/**
 * Kadence design library inserts (adapters/kadence.php).
 *
 * Free-tier sections from Kadence's cloud, fetched through the plugin's OWN
 * kb-design-library/v1 proxy (its file cache applies) and inserted as
 * islands — same adapter contract as Stackable's.
 *
 * NETWORK DEPENDENCY: the library lives on patterns.startertemplatecloud.com
 * (plugin-cached in uploads). If the designs endpoint is unreachable the
 * suite SKIPS (exit 0), like stackable-designs.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'kadence-designs' );
	const { browser, page, errors } = await launch();
	await login( page );

	const probe = await page.evaluate( async () => {
		try {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/kadence/designs', {
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
		title: 'Kadence design insert test',
		content: '<!-- wp:paragraph -->\n<p>Kadence test.</p>\n<!-- /wp:paragraph -->',
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
		t.check( 'boot payload lists the Kadence design source', await page.evaluate( () =>
			( window.MINN.designs || [] ).some( ( s ) => s.id === 'kadence' && !! s.route ) ) );
		t.check( 'designs endpoint lists free tier', probe.count > 100, probe.count + ' designs' );

		// Search surfaces the first design by its label.
		const label = probe.first.label;
		const q = label.toLowerCase().slice( 0, 12 );
		await freshParagraph( page );
		await page.keyboard.type( '/' + q, { delay: 30 } );
		let found = false;
		for ( let i = 0; i < 16 && ! found; i++ ) {
			await page.waitForTimeout( 400 );
			found = await page.$$eval( '.minn-slash-item', ( els, l ) =>
				els.some( ( e ) => e.textContent.includes( l ) && e.textContent.includes( 'kadence' ) ), label
			).catch( () => false );
			if ( ! found ) {
				// A zero-match query closes the menu until the next keystroke;
				// jiggle the last character so it reopens once the async
				// design list has landed.
				await page.keyboard.press( 'Backspace' );
				await page.keyboard.type( q.slice( -1 ), { delay: 30 } );
			}
		}
		t.check( 'design entry surfaces with kadence badge', found, label );

		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '.minn-block-island[data-block^="kadence/"]', { timeout: 45000 } );
		t.check( 'design inserted as island', true );
		const preview = await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-block-island .minn-island-preview' );
			return p && ( p.innerHTML.includes( 'kb-' ) || p.innerHTML.includes( 'wp-block-kadence' ) ) ? p.innerHTML.length : false;
		}, null, { timeout: 20000 } ).then( ( h ) => h.jsonValue() ).catch( () => 0 );
		t.check( 'island preview renders real Kadence markup', preview > 200, preview + ' bytes' );

		// The inspector's generic machinery applies (text runs from saved HTML).
		await page.waitForSelector( '[data-insprun]', { timeout: 10000 } ).catch( () => null );
		t.check( 'inspector exposes text-run fields', ( await page.$$( '[data-insprun]' ) ).length > 0 );
		await page.click( '#minn-editor-title' );
		await page.waitForTimeout( 300 );

		await save();
		const raw1 = await rawContent();
		t.check( 'saved markup contains the Kadence template',
			/<!-- wp:kadence\/[a-z-]+ {"uniqueID"/.test( raw1 ), raw1.slice( 0, 160 ) );
		t.check( 'no vendor-cloud image URLs', ! raw1.includes( 'startertemplatecloud.com' ) );

		await openEditor( page, id );
		t.check( 'island survives reload',
			( await page.$( '.minn-block-island[data-block^="kadence/"]' ) ) !== null );
		const before = raw1.match( /<!-- wp:kadence\/[\s\S]*\/wp:kadence\/[a-z-]+ -->/ );
		await freshParagraph( page );
		await page.keyboard.type( 'After the section.', { delay: 20 } );
		await save();
		const raw2 = await rawContent();
		const after = raw2.match( /<!-- wp:kadence\/[\s\S]*\/wp:kadence\/[a-z-]+ -->/ );
		t.check( 'design round-trips byte-identical through a second save',
			!! before && !! after && before[ 0 ] === after[ 0 ] && raw2.includes( 'After the section.' ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
