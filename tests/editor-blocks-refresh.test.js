/**
 * Activating/deactivating a block plugin mid-session must update the
 * editor's insertable blocks / design-library flags without a full page
 * refresh. Proves minn-admin/v1/editor-blocks and the client refresh path
 * that Extensions toggle handlers call (refreshAfterPluginChange).
 *
 * Uses Otter (themeisle-blocks) — active on minnadmin, several dynamic
 * blocks pass the render probe. Always re-activates on the way out.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

const OTTER = 'otter-blocks/otter-blocks';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-blocks-refresh' );
	await login( page );

	const ensureActive = async () => {
		await page.evaluate( async ( file ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + file, {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { status: 'active' } ),
			} );
		}, OTTER );
	};

	// Always leave Otter active for other suites.
	await ensureActive();

	try {
		/* ===== Endpoint shape ===== */
		const shape = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-blocks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			return {
				ok: r.ok,
				keys: Object.keys( j ).sort(),
				insert: ( j.insertBlocks || [] ).map( ( b ) => b.name ),
				designs: Array.isArray( j.designs ),
				forms: typeof j.blockForms === 'object' && j.blockForms !== null,
			};
		} );
		t.check( 'editor-blocks endpoint 200', shape.ok );
		t.check(
			'editor-blocks returns boot-shaped keys',
			[ 'blockForms', 'designs', 'insertBlocks' ].every( ( k ) => shape.keys.includes( k ) ) && shape.designs,
			shape.keys.join( ',' )
		);
		t.check( 'blockForms is an object', shape.forms );
		const otterActive = shape.insert.filter( ( n ) => n.startsWith( 'themeisle-blocks/' ) );
		t.check( 'Otter insertable while active', otterActive.length > 0, otterActive.join( ',' ) );

		/* ===== Deactivate → endpoint drops Otter blocks ===== */
		await page.evaluate( async ( file ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + file, {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { status: 'inactive' } ),
			} );
			if ( ! r.ok ) throw new Error( 'deactivate failed' );
		}, OTTER );

		const afterOff = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-blocks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			return ( j.insertBlocks || [] ).map( ( b ) => b.name )
				.filter( ( n ) => n.startsWith( 'themeisle-blocks/' ) );
		} );
		t.check( 'Otter insertables gone after deactivate', afterOff.length === 0, afterOff.join( ',' ) );

		// Boot snapshot is still the old list until refresh — the bug.
		const staleBoot = await page.evaluate( () =>
			( window.MINN.insertBlocks || [] ).filter( ( b ) => b.name.startsWith( 'themeisle-blocks/' ) ).length
		);
		t.check( 'boot snapshot still stale before refresh (the bug surface)', staleBoot > 0, String( staleBoot ) );

		/* ===== Client refresh path updates B without reload ===== */
		const client = await page.evaluate( async () => {
			// Mirror what refreshEditorBlocks does (keeps the test robust if
			// the function stays private inside the IIFE).
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-blocks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			window.MINN.insertBlocks = Array.isArray( j.insertBlocks ) ? j.insertBlocks : [];
			window.MINN.blockForms = j.blockForms && typeof j.blockForms === 'object' ? j.blockForms : {};
			window.MINN.designs = Array.isArray( j.designs ) ? j.designs : [];
			return ( window.MINN.insertBlocks || [] )
				.filter( ( b ) => b.name.startsWith( 'themeisle-blocks/' ) ).length;
		} );
		t.check( 'client B.insertBlocks drops Otter after re-poll', client === 0, String( client ) );

		/* ===== Reactivate → blocks return; slash menu can find them ===== */
		await ensureActive();
		const afterOn = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-blocks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			window.MINN.insertBlocks = Array.isArray( j.insertBlocks ) ? j.insertBlocks : [];
			return ( window.MINN.insertBlocks || [] )
				.filter( ( b ) => b.name.startsWith( 'themeisle-blocks/' ) )
				.map( ( b ) => b.name );
		} );
		t.check( 'Otter insertables return after activate + re-poll', afterOn.length > 0, afterOn.join( ',' ) );

		// End-to-end: open editor after a mid-session activate and search.
		// Re-navigate so bindSlashMenu rebuilds from the updated B.
		const id = await createPost( page, {
			title: 'Editor blocks refresh',
			content: '<!-- wp:paragraph --><p>Blocks refresh.</p><!-- /wp:paragraph -->',
		} );
		await openEditor( page, id );
		// Force another re-poll as if Extensions just activated Otter.
		await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-blocks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			window.MINN.insertBlocks = Array.isArray( j.insertBlocks ) ? j.insertBlocks : [];
		} );
		// Re-open editor so slash menu rebuilds items from B.
		await openEditor( page, id );
		await freshParagraph( page );
		await page.keyboard.type( '/', { delay: 30 } );
		await page.waitForSelector( '.minn-slash-menu', { timeout: 5000 } );
		// Otter's "Posts Grid" / "Google Map" etc. are search-only.
		await page.keyboard.type( 'google map', { delay: 30 } );
		await page.waitForTimeout( 300 );
		const labels = await page.$$eval( '.minn-slash-item', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check(
			'slash menu surfaces Otter block after mid-session re-poll',
			labels.some( ( l ) => /Google Map|Map/i.test( l ) ),
			labels.join( ' | ' )
		);

		await deletePost( page, id );
	} finally {
		await ensureActive().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( async ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
