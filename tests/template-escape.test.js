/**
 * The block-editor escape hatches while editing a template part. A
 * template's id is "theme//slug", which post.php cannot open, so every door
 * that hands a post to wp-admin's block editor must hand a template to the
 * Site Editor instead: the ⌘K command, ⌥-clicking the sidebar's WordPress
 * button, and the block inspector's button. Each one saves first, so the
 * tab that opens shows what was typed.
 *
 * Seeds its own custom part on twentytwentyfive (activated for the run, the
 * previous theme restored in finally) so nothing of the theme's is touched.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'template-escape' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );

	const SLUG = 'minn-escape-suite-part';
	const PART_ID = 'twentytwentyfive//' + SLUG;
	const FIXTURE = [
		'<!-- wp:paragraph --><p>Escape starting text</p><!-- /wp:paragraph -->',
		'<!-- wp:acme/unknown-thing {"weird":true} /-->',
	].join( '\n\n' );

	// The tab opens once the save resolves, and a noopener tab reports an
	// empty URL, then about:blank, before it navigates: poll until it holds
	// a real one rather than reading it too early.
	const tabUrl = async ( popup ) => {
		const tab = await popup;
		if ( ! tab ) return { tab: null, url: '' };
		let url = tab.url();
		for ( let i = 0; i < 40 && ! /^https?:/.test( url ); i++ ) {
			await page.waitForTimeout( 500 );
			url = tab.url();
		}
		return { tab, url };
	};
	const opensThePart = ( url ) => {
		let target = '';
		try { target = new URL( url ).searchParams.get( 'p' ) || ''; } catch ( e ) { /* not a URL */ }
		return /\/wp-admin\/site-editor\.php/.test( url ) && target === '/wp_template_part/' + PART_ID;
	};
	const waitPopup = () => page.context().waitForEvent( 'page', { timeout: 45000 } ).catch( () => null );

	let prevTheme = '';
	let seeded = false;
	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;
		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );
		await rest( `wp/v2/template-parts/${ PART_ID }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		const made = await rest( 'wp/v2/template-parts', {
			method: 'POST',
			body: { slug: SLUG, theme: 'twentytwentyfive', title: 'Escape Suite Part', area: 'uncategorized', content: FIXTURE },
		} );
		seeded = made.status === 201 || made.status === 200;
		t.check( 'seeded a custom template part', seeded && made.body.id === PART_ID, `status ${ made.status } ${ made.body && made.body.id }` );

		await page.goto( BASE + '/minn-admin/editor/template-parts/twentytwentyfive/' + SLUG, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForTimeout( 1500 );

		/* ===== ⌥-click the WordPress button ===== */
		const p1 = waitPopup();
		await page.click( '#minn-wp-admin-link', { modifiers: [ 'Alt' ] } );
		const a = await tabUrl( p1 );
		t.check( '⌥-click opens the Site Editor on this part', opensThePart( a.url ), a.url );
		if ( a.tab ) {
			// The Site Editor really finds the part: its app mounts, and
			// wp-admin's "doesn't exist" page (what post.php answers for a
			// template id) never shows.
			const loaded = await a.tab.waitForSelector( '.edit-site', { timeout: 30000 } ).then( () => true ).catch( () => false );
			const missing = await a.tab.evaluate( () => /doesn.t exist/i.test( document.body.textContent ) ).catch( () => false );
			t.check( 'the Site Editor loads it', loaded && ! missing, JSON.stringify( { loaded, missing } ) );
			await a.tab.close();
		}

		/* ===== ⌘K command ===== */
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '.minn-palette', { timeout: 8000 } );
		await page.keyboard.type( 'site editor', { delay: 40 } );
		await page.waitForTimeout( 600 );
		const rows = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-palette-item, .minn-pal-item' ) ]
			.map( ( r ) => r.textContent.trim() ) );
		const cmd = rows.find( ( s ) => /Open in Site Editor/.test( s ) ) || '';
		t.check( 'the palette names the Site Editor for a template', !! cmd, JSON.stringify( rows ) );
		t.check( 'and no block-editor command is offered', ! rows.some( ( s ) => /block editor/i.test( s ) ), JSON.stringify( rows ) );
		if ( cmd ) {
			const p2 = waitPopup();
			await page.evaluate( () => [ ...document.querySelectorAll( '.minn-palette-item, .minn-pal-item' ) ]
				.find( ( r ) => /Open in Site Editor/.test( r.textContent ) ).click() );
			const b = await tabUrl( p2 );
			t.check( 'the command opens the Site Editor on this part', opensThePart( b.url ), b.url );
			if ( b.tab ) await b.tab.close();
		} else {
			await page.keyboard.press( 'Escape' );
		}
		await page.waitForTimeout( 400 );

		/* ===== The block inspector's button ===== */
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '#minn-insp-gutenberg', { timeout: 10000 } );
		const label = ( await page.textContent( '#minn-insp-gutenberg' ) ).trim();
		t.check( 'the inspector button names the Site Editor', /Site Editor/.test( label ) && ! /Block editor/i.test( label ), label );
		const p3 = waitPopup();
		await page.click( '#minn-insp-gutenberg' );
		const c = await tabUrl( p3 );
		t.check( 'the inspector button opens the Site Editor on this part', opensThePart( c.url ), c.url );
		if ( c.tab ) await c.tab.close();

		/* ===== Unsaved text saves before the tab opens ===== */
		await page.click( '#minn-editor-body p' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' and more' );
		await page.waitForTimeout( 300 );
		const p4 = waitPopup();
		await page.click( '#minn-wp-admin-link', { modifiers: [ 'Alt' ] } );
		const d = await tabUrl( p4 );
		t.check( 'a dirty part still opens the Site Editor', opensThePart( d.url ), d.url );
		if ( d.tab ) await d.tab.close();
		const after = ( await rest( `wp/v2/template-parts/${ PART_ID }?context=edit&_fields=content` ) ).body;
		t.check( 'what was typed saved first', !! after && after.content.raw.includes( 'Escape starting text and more' ),
			after && after.content.raw.slice( 0, 120 ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( seeded ) await rest( `wp/v2/template-parts/${ PART_ID }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( prevTheme ) await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
