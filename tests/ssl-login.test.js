/**
 * SSL enforcement row + real login URL on the System page (v0.11.0
 * security-posture wave).
 *
 *  - "SSL enforcement" health check appears when Really Simple SSL is active
 *    (adapters/site-status.php: minn_admin_rsssl_check).
 *  - The WordPress group's "Login URL" row shows wp_login_url(), which honors
 *    login-hiders (WPS Hide Login and friends filter it), so it reflects a
 *    custom slug rather than a wp-login.php that would 404.
 *
 * Both plugins are installed-inactive fixtures; the suite activates each,
 * asserts, and restores inactive in the finally. It runs already
 * authenticated, so activating WPS Hide Login mid-session doesn't lock it out
 * (only fresh logins would need the custom slug).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'ssl-login' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setPlugin = ( plugin, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.plugin, {
			method: 'PUT', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: a.status } ),
		} );
		return r.ok;
	}, { plugin, status } );

	const openSystem = async () => {
		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sys-check', { timeout: 20000 } );
	};
	const kvValue = ( key ) => page.evaluate( ( k ) => {
		const row = [ ...document.querySelectorAll( '.minn-sys-row' ) ]
			.find( ( r ) => ( r.querySelector( '.minn-sys-key' )?.textContent || '' ).trim() === k );
		return row ? row.querySelector( '.minn-sys-val' ).textContent.trim() : null;
	}, key );
	const check = ( label ) => page.evaluate( ( l ) => {
		const el = [ ...document.querySelectorAll( '.minn-sys-check' ) ].find( ( e ) => new RegExp( l ).test( e.textContent ) );
		return el ? { text: el.textContent.replace( /\s+/g, ' ' ).trim(), status: el.className.replace( /.*minn-sys-check\s*/, '' ).trim() } : null;
	}, label );

	try {
		/* ===== Login URL — default ===== */
		await openSystem();
		const def = await kvValue( 'Login URL' );
		t.check( 'Login URL row shows wp-login.php by default', def && /wp-login\.php/.test( def ), def );

		/* ===== Login URL — WPS Hide Login active ===== */
		t.check( 'WPS Hide Login activates', await setPlugin( 'wps-hide-login/wps-hide-login', 'active' ) );
		await openSystem();
		const hidden = await kvValue( 'Login URL' );
		t.check( 'Login URL reflects the hider (not wp-login.php)', hidden && ! /wp-login\.php/.test( hidden ) && /\/login\/?$/.test( hidden ), hidden );
		await setPlugin( 'wps-hide-login/wps-hide-login', 'inactive' );

		/* ===== SSL enforcement — Really Simple SSL ===== */
		await openSystem();
		t.check( 'no SSL-enforcement row without RSSSL', ( await check( 'SSL enforcement' ) ) === null );
		t.check( 'Really Simple SSL activates', await setPlugin( 'really-simple-ssl/rlrsssl-really-simple-ssl', 'active' ) );
		await page.waitForTimeout( 800 );
		await openSystem();
		const ssl = await check( 'SSL enforcement' );
		t.check( 'SSL enforcement row appears with RSSSL', !! ssl, JSON.stringify( ssl ) );
		t.check( 'SSL row has a valid status', ssl && [ 'pass', 'warn', 'fail' ].includes( ssl.status ), ssl && ssl.status );
		await setPlugin( 'really-simple-ssl/rlrsssl-really-simple-ssl', 'inactive' );
		await openSystem();
		t.check( 'SSL-enforcement row gone when RSSSL inactive', ( await check( 'SSL enforcement' ) ) === null );

	} finally {
		await setPlugin( 'wps-hide-login/wps-hide-login', 'inactive' ).catch( () => {} );
		await setPlugin( 'really-simple-ssl/rlrsssl-really-simple-ssl', 'inactive' ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
