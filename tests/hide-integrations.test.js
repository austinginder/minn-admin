/**
 * Per-user integration hiding (goal #7, v0.17.0): right-click a plugin
 * surface's nav row or a plugin editor panel's door to hide it FOR YOU.
 * Hidden integrations leave the boot payload server-side (nav, palette and
 * routes never see them), survive reloads, and restore from Your profile's
 * "Hidden for you" list. Core views and core doors offer no hide menu.
 *
 * Driven against the standalone mu-fixture surface
 * (minn_test_settings_surface → minn-settings-fixture, no family semantics)
 * and the resident ACF editor panel.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'hide-integrations' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, body ) => page.evaluate( async ( [ p, b ] ) => {
		const r = await fetch( window.MINN.restUrl + p + ( p.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: b === undefined ? 'GET' : 'POST',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: b === undefined ? undefined : JSON.stringify( b ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, body ] );

	const setOpt = async ( name, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( a ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ a.name ]: a.v } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ a.name ];
			}, { name, v } );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	// Menus opened by right-click must be clicked via evaluate (rule 31:
	// the mousedown+contextmenu pair can re-open the menu and detach nodes).
	const clickMenuEntry = ( text ) => page.evaluate( ( needle ) => {
		const btn = [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ]
			.find( ( b ) => b.textContent.includes( needle ) );
		if ( btn ) btn.click();
		return !! btn;
	}, text );

	const SID = 'minn-settings-fixture';
	let postId = null;
	try {
		t.check( 'fixture surface armed', await setOpt( 'minn_test_settings_surface', true ) );

		/* ===== Boot + nav baseline ===== */
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-nav-btn[data-nav="${ SID }"]`, { timeout: 20000 } );
		t.check( 'fixture surface rides the boot payload', await page.evaluate(
			( id ) => ( window.MINN.surfaces || [] ).some( ( s ) => s.id === id ), SID
		) );

		/* ===== Core nav rows offer no hide menu ===== */
		await page.click( '.minn-nav-btn[data-nav="content"]', { button: 'right' } );
		await page.waitForTimeout( 250 );
		t.check( 'core views offer no hide menu', ! ( await page.$( '.minn-ctx-menu' ) ) );

		/* ===== Hide via nav right-click ===== */
		await page.click( `.minn-nav-btn[data-nav="${ SID }"]`, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		t.check( 'surface nav row offers Hide for you', await page.evaluate(
			() => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].some( ( b ) => /Hide .* for you/.test( b.textContent ) )
		) );
		t.check( 'menu entry clicked', await clickMenuEntry( 'for you' ) );
		await page.waitForFunction( ( id ) => ! document.querySelector( `.minn-nav-btn[data-nav="${ id }"]` ),
			SID, { timeout: 10000 } );
		t.check( 'nav row disappears without a reload', true );
		t.check( 'Undo toast offered', await page.evaluate(
			() => !! document.querySelector( '.minn-toast' ) && /hidden for you/i.test( document.querySelector( '.minn-toast' ).textContent )
		) );
		t.check( 'boot slice updated in place', await page.evaluate(
			( id ) => ! ( window.MINN.surfaces || [] ).some( ( s ) => s.id === id )
				&& ( window.MINN.hidden || [] ).some( ( h ) => h.id === 'surface:' + id ), SID
		) );

		/* ===== Survives a reload; direct route falls back home ===== */
		await page.goto( `${ BASE }/minn-admin/${ SID }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn.active', { timeout: 20000 } );
		const fallback = await page.evaluate( ( id ) => ( {
			nav: !! document.querySelector( `.minn-nav-btn[data-nav="${ id }"]` ),
			active: ( document.querySelector( '.minn-nav-btn.active' ) || {} ).dataset?.nav,
		} ), SID );
		t.check( 'hide survives a full reload (server-filtered boot)', ! fallback.nav );
		t.check( 'direct route to a hidden surface falls back to Overview', fallback.active === 'overview', JSON.stringify( fallback ) );

		/* ===== Junk ids are refused ===== */
		const junk = await rest( 'minn-admin/v1/integrations/hide', { id: 'surface:not-a-thing' } );
		t.check( 'hide refuses unregistered ids', junk.status === 400, String( junk.status ) );

		/* ===== Restore from Your profile ===== */
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.keyboard.type( 'Your profile' );
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '[data-unhide]', { timeout: 15000 } );
		t.check( 'Hidden for you lists the surface', await page.evaluate(
			( id ) => !! document.querySelector( `[data-unhide="surface:${ id }"]` ), SID
		) );
		await page.click( `[data-unhide="surface:${ SID }"]` );
		await page.waitForSelector( `.minn-nav-btn[data-nav="${ SID }"]`, { timeout: 10000 } );
		t.check( 'Restore brings the nav row back without a reload', true );
		t.check( 'restore list row cleared', await page.evaluate(
			( id ) => ! document.querySelector( `[data-unhide="surface:${ id }"]` ), SID
		) );
		await page.keyboard.press( 'Escape' );

		/* ===== Editor panel hide (ACF resident fixture) ===== */
		postId = await createPost( page, { title: 'Hide panel probe', content: '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' } );
		await openEditor( page, postId );
		await page.waitForSelector( '[data-side-door^="panel:"]', { timeout: 20000 } );
		const panelId = await page.evaluate( () => document.querySelector( '[data-side-door^="panel:"]' ).dataset.sideDoor );
		await page.click( `[data-side-door="${ panelId }"]`, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		t.check( 'panel door offers Hide for you', true );
		await clickMenuEntry( 'for you' );
		await page.waitForFunction( ( id ) => ! document.querySelector( `[data-side-door="${ id }"]` ),
			panelId, { timeout: 10000 } );
		t.check( 'panel door leaves the live sidebar', true );

		// Core doors never offer the menu.
		await page.click( '[data-side-door="settings"]', { button: 'right' } );
		await page.waitForTimeout( 250 );
		t.check( 'core doors offer no hide menu', ! ( await page.$( '.minn-ctx-menu' ) ) );

		// Persists into the next editor open.
		await openEditor( page, postId );
		await page.waitForSelector( '[data-side-door="settings"]', { timeout: 20000 } );
		await page.waitForTimeout( 800 ); // panels load async after the editor
		t.check( 'panel stays hidden on the next editor open', await page.evaluate(
			( id ) => ! document.querySelector( `[data-side-door="${ id }"]` ), panelId
		) );

		// Restore over REST and confirm it returns on a fresh open.
		const un = await rest( 'minn-admin/v1/integrations/unhide', { id: panelId } );
		t.check( 'panel unhide answers with fresh boot slices', un.status === 200 && Array.isArray( un.body.editorPanels ), String( un.status ) );
		await openEditor( page, postId );
		await page.waitForFunction( ( id ) => !! document.querySelector( `[data-side-door="${ id }"]` ),
			panelId, { timeout: 20000 } );
		t.check( 'restored panel returns on the next editor open', true );
	} finally {
		await rest( 'minn-admin/v1/integrations/unhide', { id: 'surface:' + SID } ).catch( () => {} );
		const hid = await rest( 'minn-admin/v1/integrations/unhide', { id: 'surface:' + SID } ).catch( () => null );
		if ( hid && hid.body && Array.isArray( hid.body.hidden ) ) {
			for ( const h of hid.body.hidden ) {
				await rest( 'minn-admin/v1/integrations/unhide', { id: h.id } ).catch( () => {} );
			}
		}
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
		await setOpt( 'minn_test_settings_surface', false );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
