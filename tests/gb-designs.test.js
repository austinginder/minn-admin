/**
 * GenerateBlocks pattern library inserts (adapters/generateblocks.php).
 *
 * Free patterns from patterns.generatepress.com, fetched through the
 * plugin's OWN generateblocks/v1 proxy (transient-cached, public key ships
 * in the plugin) — same adapter contract as Stackable/Kadence. Each
 * pattern's CSS rides its blocks' `css` attributes, which render-blocks
 * inlines via generateblocks_do_inline_styles, so previews arrive styled.
 *
 * NETWORK DEPENDENCY: SKIPs (exit 0) when the library is unreachable.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'gb-designs' );
	const { browser, page, errors } = await launch();
	await login( page );

	const probe = await page.evaluate( async () => {
		try {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/generateblocks/designs', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			if ( ! r.ok ) return { ok: false };
			const j = await r.json();
			return { ok: true, count: ( j.designs || [] ).length, first: ( j.designs || [] )[ 0 ] };
		} catch ( e ) {
			return { ok: false };
		}
	} );
	if ( ! probe.ok || ! probe.count ) {
		console.log( 'SKIP  Kadence design library unreachable (offline?) — suite not run' );
		await browser.close();
		process.exit( 0 );
	}

	const id = await createPost( page, {
		title: 'GB design insert test',
		content: '<!-- wp:paragraph -->\n<p>GB test.</p>\n<!-- /wp:paragraph -->',
	} );

	const save = async () => { await page.keyboard.press( 'Meta+s' ); await page.waitForTimeout( 2000 ); };
	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	try {
		await openEditor( page, id );
		t.check( 'boot payload lists the GenerateBlocks design source', await page.evaluate( () =>
			( window.MINN.designs || [] ).some( ( s ) => s.id === 'generateblocks' && !! s.route ) ) );
		t.check( 'designs endpoint lists free tier', probe.count > 20, probe.count + ' designs' );

		// Search surfaces the first design by its label.
		const label = probe.first.label;
		const q = label.toLowerCase().slice( 0, 12 );
		await freshParagraph( page );
		await page.keyboard.type( '/' + q, { delay: 30 } );
		let found = false;
		for ( let i = 0; i < 16 && ! found; i++ ) {
			await page.waitForTimeout( 400 );
			found = await page.$$eval( '.minn-slash-item', ( els, l ) =>
				els.some( ( e ) => e.textContent.includes( l ) && e.textContent.includes( 'generateblocks' ) ), label
			).catch( () => false );
			if ( ! found ) {
				// A zero-match query closes the menu until the next keystroke;
				// jiggle the last character so it reopens once the async
				// design list has landed.
				await page.keyboard.press( 'Backspace' );
				await page.keyboard.type( q.slice( -1 ), { delay: 30 } );
			}
		}
		t.check( 'design entry surfaces with generateblocks badge', found, label );

		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '.minn-block-island[data-block^="generateblocks/"]', { timeout: 45000 } );
		t.check( 'design inserted as island', true );
		const preview = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island .minn-island-preview' );
			return p && ( p.innerHTML.includes( 'gb-element' ) || p.innerHTML.includes( 'gb-text' ) ) ? p.innerHTML.length : false;
		}, null, { timeout: 20000 } ).then( ( h ) => h.jsonValue() ).catch( () => 0 );
		t.check( 'island preview renders real GB markup', preview > 200, preview + ' bytes' );

		// The inspector's generic machinery applies (text runs from saved HTML).
		await page.waitForSelector( '[data-insprun]', { timeout: 10000 } ).catch( () => null );
		t.check( 'inspector exposes text-run fields', ( await page.$$( '[data-insprun]' ) ).length > 0 );
		await page.click( '#minn-editor-title' );
		await page.waitForTimeout( 300 );

		await save();
		const raw1 = await rawContent();
		t.check( 'saved markup contains the GB template',
			/<!-- wp:generateblocks\/[a-z-]+ {"uniqueId"/.test( raw1 ), raw1.slice( 0, 160 ) );
		t.check( 'pattern CSS attribute preserved', raw1.includes( '"css":' ) );
		t.check( 'no vendor-cloud image URLs', ! /patterns\.generatepress\.com\/wp-content\/uploads/.test( raw1 ) );

		await openEditor( page, id );
		t.check( 'island survives reload',
			( await page.$( '.minn-block-island[data-block^="generateblocks/"]' ) ) !== null );
		const before = raw1.match( /<!-- wp:generateblocks\/[\s\S]*\/wp:generateblocks\/[a-z-]+ -->/ );
		await freshParagraph( page );
		await page.keyboard.type( 'After the section.', { delay: 20 } );
		await save();
		const raw2 = await rawContent();
		const after = raw2.match( /<!-- wp:generateblocks\/[\s\S]*\/wp:generateblocks\/[a-z-]+ -->/ );
		t.check( 'design round-trips byte-identical through a second save',
			!! before && !! after && before[ 0 ] === after[ 0 ] && raw2.includes( 'After the section.' ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
