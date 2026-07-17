/**
 * Your profile ( /minn-admin/profile ) — the self-profile promoted from the
 * user modal to a route page (2026-07-17). Covers: every self entry point
 * lands on the route (avatar, ⌘K, your own row in Users), the cards render
 * (Account / Appearance / AI Access / Sessions), a display-name save
 * round-trips over REST and syncs the sidebar, an application password is
 * created (reveal + curl copy buttons) and revoked, and the Edit-user modal
 * for ANOTHER user no longer carries the self-only sections.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'profile' );
	const { browser, page, errors } = await launch();
	await login( page );

	const restSelf = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/users/me?context=edit&_fields=id,name,url,description,first_name,last_name', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return r.json();
	} );
	const original = await restSelf();

	try {
		/* ===== Avatar entry point ===== */
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-user-area', { timeout: 20000 } );
		await page.click( '#minn-user-area .minn-user-name' );
		await page.waitForSelector( '#minn-pf-save', { timeout: 15000 } );
		t.check( 'avatar opens /minn-admin/profile', await page.evaluate( () => location.pathname.endsWith( '/minn-admin/profile' ) ) );

		/* ===== Cards render ===== */
		const titles = await page.$$eval( '.minn-profile-grid .minn-panel-title', ( els ) => els.map( ( el ) => el.firstChild.textContent.trim() ) );
		t.check( 'Account, AI Access, Appearance and Sessions cards render',
			[ 'Account', 'AI Access', 'Appearance', 'Sessions' ].every( ( x ) => titles.some( ( y ) => y.startsWith( x ) ) ), titles.join( ' | ' ) );
		t.check( 'account fields seeded from context=edit', await page.$eval( '#minn-pf-name', ( i ) => i.value.length > 0 ) );
		t.check( 'color scheme swatches render', await page.$$eval( '.minn-scheme-swatch', ( els ) => els.length > 3 ) );
		t.check( 'theme mode + default-admin switches render', await page.evaluate( () =>
			document.querySelectorAll( '[data-theme-pref]' ).length === 3 && !! document.getElementById( 'minn-default-admin' ) ) );
		t.check( 'a session row renders with the current marker', await page.evaluate( () =>
			!! document.querySelector( '[data-kill]' ) && /this session/.test( document.body.textContent ) ) );

		/* ===== Display-name save round-trip ===== */
		const probeName = 'Profile Probe ' + ( Date.now() % 100000 );
		await page.evaluate( ( n ) => { const i = document.getElementById( 'minn-pf-name' ); i.value = n; }, probeName );
		await page.click( '#minn-pf-save' );
		await page.waitForFunction( ( n ) => {
			const el = document.querySelector( '.minn-user-name' );
			return el && el.textContent.trim() === n;
		}, probeName, { timeout: 10000 } );
		t.check( 'save syncs the sidebar name', true );
		const savedName = ( await restSelf() ).name;
		t.check( 'display name persists over REST', savedName === probeName, savedName );

		/* ===== Public profile card (bio + website) round-trip ===== */
		await page.fill( '#minn-pf-url', 'https://example.com/probe' );
		await page.fill( '#minn-pf-bio', 'Suite bio probe.' );
		await page.evaluate( () => { [ ...document.querySelectorAll( '[data-pf-save]' ) ].pop().click(); } );
		await page.waitForTimeout( 1200 );
		const pub = await restSelf();
		t.check( 'website + bio persist over REST', pub.url === 'https://example.com/probe' && pub.description === 'Suite bio probe.',
			JSON.stringify( { url: pub.url, description: pub.description } ) );

		/* ===== App password create + revoke ===== */
		await page.fill( '#minn-app-name', 'Profile Suite Probe' );
		await page.click( '#minn-app-create' );
		await page.waitForSelector( '#minn-app-secret', { timeout: 10000 } );
		t.check( 'new password reveal renders with copy buttons', await page.evaluate( () =>
			document.getElementById( 'minn-app-secret' ).textContent.trim().length >= 20
			&& !! document.getElementById( 'minn-app-copy-curl' ) ) );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '.minn-session-ua' ) ].some( ( el ) => el.textContent.trim() === 'Profile Suite Probe' ), null, { timeout: 10000 } );
		t.check( 'created password joins the list', true );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-session-row' ) ].find( ( r ) =>
				r.textContent.includes( 'Profile Suite Probe' ) );
			row.querySelector( '[data-appdel]' ).click();
		} );
		await page.waitForFunction( () =>
			! [ ...document.querySelectorAll( '.minn-session-ua' ) ].some( ( el ) => el.textContent.trim() === 'Profile Suite Probe' ), null, { timeout: 10000 } );
		t.check( 'revoke removes it from the list', true );

		/* ===== ⌘K entry point ===== */
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-view .minn-toolbar, #minn-view .minn-loading', { timeout: 20000 } );
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.keyboard.type( 'Your profile' );
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '#minn-pf-save', { timeout: 15000 } );
		t.check( 'palette "Your profile" lands on the route', await page.evaluate( () => location.pathname.endsWith( '/minn-admin/profile' ) ) );

		/* ===== Own row in Users redirects; other users keep the modal ===== */
		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-user-cols.minn-table-row', { timeout: 20000 } );
		await page.evaluate( ( uid ) => { document.querySelector( `[data-user="${ uid }"] .minn-row-title` ).click(); }, original.id );
		await page.waitForSelector( '#minn-pf-save', { timeout: 15000 } );
		t.check( 'clicking your own Users row opens the profile route', await page.evaluate( () =>
			location.pathname.endsWith( '/minn-admin/profile' ) && ! document.querySelector( '.minn-modal' ) ) );

		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-user-cols.minn-table-row', { timeout: 20000 } );
		await page.locator( '.minn-user-cols.minn-table-row', { hasText: 'minn-editor' } ).first().click();
		await page.waitForSelector( '#minn-uf-save', { timeout: 10000 } );
		t.check( 'another user still opens the Edit-user modal', await page.evaluate( () => !! document.querySelector( '.minn-modal' ) ) );
		t.check( 'the modal carries no self-only sections', await page.evaluate( () =>
			! document.querySelector( '.minn-modal .minn-scheme-swatch' )
			&& ! document.querySelector( '.minn-modal #minn-app-create' )
			&& ! document.querySelector( '.minn-modal [data-unhide]' ) ) );
		await page.keyboard.press( 'Escape' );
	} finally {
		// Restore the admin's identity fields whatever happened above.
		await page.evaluate( async ( orig ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/users/' + orig.id, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( {
					name: orig.name,
					url: orig.url || '',
					description: orig.description || '',
					first_name: orig.first_name || '',
					last_name: orig.last_name || '',
				} ),
			} );
		}, original ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
