/**
 * Stackable design library inserts (adapters/stackable.php).
 *
 * Free-tier designs from Stackable's CDN library (full serialized save()
 * markup — valid by construction) surface as search-only slash entries and
 * insert as islands with CDN images sideloaded to the media library.
 *
 * NETWORK DEPENDENCY: the design library lives on stackable-files.pages.dev
 * (server-cached 7 days in Stackable's own transient). If the designs
 * endpoint is unreachable the suite SKIPS (exit 0) rather than failing.
 *
 * Fixture note: the sideloaded design image (stk-design-library-image-*) is
 * deduped by filename, so it persists on the dev site as a fixture — same
 * convention as the gal-red/green/blue gallery images.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'stackable-designs' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Offline / library-unreachable guard.
	const probe = await page.evaluate( async () => {
		try {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/stackable/designs', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			if ( ! r.ok ) return { ok: false };
			const j = await r.json();
			return { ok: true, count: ( j.designs || [] ).length };
		} catch ( e ) {
			return { ok: false };
		}
	} );
	if ( ! probe.ok || ! probe.count ) {
		console.log( 'SKIP  design library unreachable (offline?) — suite not run' );
		await browser.close();
		process.exit( 0 );
	}

	const id = await createPost( page, {
		title: 'Stackable design insert test',
		content: '<!-- wp:paragraph -->\n<p>Design test.</p>\n<!-- /wp:paragraph -->',
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

		t.check( 'boot payload flags Stackable', await page.evaluate( () => window.MINN.stackable === true ) );
		t.check( 'designs endpoint lists free tier', probe.count > 50, probe.count + ' designs' );

		// Search surfaces a design (list loads lazily — poll for it).
		await freshParagraph( page );
		await page.keyboard.type( '/call to action', { delay: 30 } );
		let found = false;
		for ( let i = 0; i < 20 && ! found; i++ ) {
			await page.waitForTimeout( 250 );
			found = await page.$$eval( '.minn-slash-item', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'Call to Action 1' ) && e.textContent.includes( 'stackable' ) )
			).catch( () => false );
			// Re-trigger the query so late-arriving designs get filtered in.
			if ( ! found && i === 8 ) { await page.keyboard.press( 'Backspace' ); await page.keyboard.type( 'n', { delay: 30 } ); }
		}
		t.check( 'design entry surfaces with namespace badge', found );

		// Insert — async fetch + image sideload, allow a generous window.
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '.minn-block-island[data-block^="stackable/"]', { timeout: 45000 } );
		t.check( 'design inserted as island', true );
		const preview = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island .minn-island-preview' );
			return p && p.innerHTML.includes( 'stk-block' ) ? p.innerHTML.length : false;
		}, null, { timeout: 20000 } ).then( ( h ) => h.jsonValue() ).catch( () => 0 );
		t.check( 'island preview renders real Stackable markup', preview > 200, preview + ' bytes' );

		// Saved markup: real template, images localized.
		await save();
		const raw1 = await rawContent();
		t.check( 'saved markup contains the design template', /<!-- wp:stackable\/[a-z-]+ {"uniqueId"/.test( raw1 ) );
		t.check( 'CDN image URLs localized', ! raw1.includes( 'stackable-files.pages.dev' ), raw1.slice( 0, 300 ) );

		// Round-trip: reload, unrelated edit, save again — island byte-stable.
		await openEditor( page, id );
		t.check( 'island survives reload',
			( await page.$( '.minn-block-island[data-block^="stackable/"]' ) ) !== null );
		const islandBefore = raw1.match( /<!-- wp:stackable\/[\s\S]*\/wp:stackable\/[a-z-]+ -->/ );
		await freshParagraph( page );
		await page.keyboard.type( 'After the section.', { delay: 20 } );
		await save();
		const raw2 = await rawContent();
		const islandAfter = raw2.match( /<!-- wp:stackable\/[\s\S]*\/wp:stackable\/[a-z-]+ -->/ );
		t.check( 'design round-trips byte-identical through a second save',
			!! islandBefore && !! islandAfter && islandBefore[ 0 ] === islandAfter[ 0 ] && raw2.includes( 'After the section.' ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
