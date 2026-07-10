/**
 * Third-party design sources (minn_admin_design_sources filter).
 *
 * Proves the design-library seam is open: the minn-dev-fixtures mu-plugin
 * registers a "Minn Test Library" source through the same public filter a
 * third-party block plugin would use (no Minn patch), gated on the
 * REST-exposed minn_test_design_source option. Covers: boot payload lists
 * the source, slash search surfaces its design with the namespace badge,
 * the block picker groups it under its label, and the insert lands as an
 * island whose markup survives save.
 *
 * No network dependency — the fixture source is served by the site itself.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'design-sources' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Write-then-verify with retries (the REST settings write can be lost
	// when it races the app's parallel boot requests — site-kit suite rule).
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_design_source: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_design_source;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	if ( ! await setOpt( true ) ) {
		console.log( 'FAIL  could not enable the fixture design source option' );
		await browser.close();
		process.exit( 1 );
	}

	const id = await createPost( page, {
		title: 'Design source seam test',
		content: '<!-- wp:paragraph -->\n<p>Seam test.</p>\n<!-- /wp:paragraph -->',
	} );

	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	try {
		await openEditor( page, id );

		t.check( 'boot payload lists the fixture source with its label', await page.evaluate( () =>
			( window.MINN.designs || [] ).some( ( s ) =>
				s.id === 'minn-test' && s.label === 'Minn Test Library' && !! s.route ) ) );

		// Slash search surfaces the fixture design (async list — poll with
		// the jiggle: a zero-match query closes the menu until the next keyup).
		await freshParagraph( page );
		const q = '/fixture hero';
		await page.keyboard.type( q, { delay: 30 } );
		let found = false;
		for ( let i = 0; i < 16 && ! found; i++ ) {
			await page.waitForTimeout( 400 );
			found = await page.$$eval( '.minn-slash-item', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'Fixture Hero Section' ) && e.textContent.includes( 'minn-test' ) )
			).catch( () => false );
			if ( ! found ) {
				await page.keyboard.press( 'Backspace' );
				await page.keyboard.type( q.slice( -1 ), { delay: 30 } );
			}
		}
		t.check( 'design entry surfaces with the source id badge', found );

		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '.minn-block-island[data-block="core/group"]', { timeout: 20000 } );
		t.check( 'design inserted as an island', true );

		// The generic inspector machinery applies and auto-opens after a
		// design insert. The heading child is a single-element tail, so its
		// text surfaces via childTextOf ([data-insptext]); multi-node markup
		// would surface as text runs ([data-insprun]) — accept either.
		await page.waitForSelector( '[data-insptext], [data-insprun]', { timeout: 10000 } ).catch( () => null );
		t.check( 'inspector exposes editable text fields',
			( await page.$$( '[data-insptext], [data-insprun]' ) ).length > 0 );
		await page.keyboard.press( 'Escape' );

		// Block picker groups the source under its human label.
		await page.click( '#minn-editor-title' );
		await page.keyboard.press( 'Meta+/' );
		let grouped = false;
		for ( let i = 0; i < 16 && ! grouped; i++ ) {
			await page.waitForTimeout( 400 );
			grouped = await page.$$eval( '.minn-bp-group h3', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'Minn Test Library · designs' ) )
			).catch( () => false );
		}
		t.check( 'block picker groups the source by label', grouped );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );

		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2000 );
		const raw = await rawContent();
		t.check( 'saved markup contains the fixture template',
			raw.includes( 'minn-fixture-hero' ) && raw.includes( 'Fixture hero heading' ), raw.slice( 0, 160 ) );

		// Disabling the option drops the source from the re-poll payload.
		await setOpt( false );
		const gone = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-blocks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			return ! ( j.designs || [] ).some( ( s ) => s.id === 'minn-test' );
		} );
		t.check( 'disabled source absent from the editor-blocks re-poll', gone );
	} finally {
		await setOpt( false );
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
