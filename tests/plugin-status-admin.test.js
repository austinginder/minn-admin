/**
 * Plugin toggles ride admin-ajax (a REAL admin context), not wp/v2/plugins.
 * Activation hooks are written for is_admin() — Breeze fatals over REST on
 * WooCommerce sites because its ecommerce class only loads under
 * is_admin()/CLI, so activating Breeze through the UI IS the regression
 * proof (it 500'd on the REST path). Breeze is an active resident fixture:
 * the suite deactivates it over REST (deactivation never fataled), then
 * activates AND deactivates AND re-activates through the UI switch,
 * asserting the requests hit admin-ajax and the server state really flips.
 * Resting state: Breeze active.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'plugin-status-admin' );
	await login( page );

	const breezeStatus = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/breeze/breeze', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status;
	} );

	/* ===== Boot payload ===== */
	const aj = await page.evaluate( () => window.MINN.pluginAjax );
	t.check( 'boot payload carries the admin-ajax url + nonce for admins',
		!! ( aj && /admin-ajax\.php/.test( aj.url ) && aj.nonce ), JSON.stringify( aj ) );

	/* ===== Endpoint refuses a bad nonce ===== */
	const badNonce = await page.evaluate( async ( url ) => {
		const r = await fetch( url, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams( { action: 'minn_plugin_status', _wpnonce: 'bogus', plugin: 'breeze/breeze', status: 'active' } ).toString(),
		} );
		return r.status;
	}, aj.url );
	t.check( 'bad nonce is refused', badNonce >= 400, String( badNonce ) );

	/* ===== Baseline: Breeze inactive (REST deactivate never fataled) ===== */
	await page.evaluate( async () => {
		await fetch( window.MINN.restUrl + 'wp/v2/plugins/breeze/breeze', {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { status: 'inactive' } ),
		} ).catch( () => null );
	} );
	t.check( 'baseline: Breeze inactive', await breezeStatus() === 'inactive', '' );

	/* ===== The regression: activate Breeze through the UI switch ===== */
	await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-plugin[data-plugin="breeze/breeze"]', { timeout: 20000 } );

	const ajaxCalls = [];
	page.on( 'request', ( req ) => {
		if ( /admin-ajax\.php/.test( req.url() ) && req.method() === 'POST'
			&& /action=minn_plugin_status/.test( req.postData() || '' ) ) {
			ajaxCalls.push( req.postData() );
		}
	} );

	const clickSwitch = () => page.evaluate( () => {
		document.querySelector( '.minn-plugin[data-plugin="breeze/breeze"] [data-toggle]' ).click();
	} );
	// The toggle can recycle the PHP worker (cache plugin activation) — poll
	// server state rather than trusting one render.
	const waitStatus = async ( want ) => {
		await page.waitForFunction( async ( w ) => {
			try {
				const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/breeze/breeze', {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).status === w;
			} catch ( e ) { return false; }
		}, want, { timeout: 30000, polling: 800 } );
	};

	await clickSwitch();
	await waitStatus( 'active' );
	t.check( 'Breeze ACTIVATES through the UI (fataled over REST before)', true, '' );
	t.check( 'activation went through admin-ajax', ajaxCalls.length === 1 && /status=active/.test( ajaxCalls[ 0 ] ), JSON.stringify( ajaxCalls ) );

	// A cache-plugin toggle can recycle the PHP worker mid-refresh, so the
	// in-place re-render is not a reliable settle signal — reload and read
	// the fresh render instead (server state is already proven above).
	const freshSwitchOn = async () => {
		await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-plugin[data-plugin="breeze/breeze"] [data-toggle]', { timeout: 20000 } );
		return page.evaluate( () =>
			document.querySelector( '.minn-plugin[data-plugin="breeze/breeze"] [data-toggle]' ).classList.contains( 'on' ) );
	};
	t.check( 'fresh render shows the switch on', await freshSwitchOn() === true, '' );

	/* ===== Deactivate + re-activate through the UI (both directions) ===== */
	await clickSwitch();
	await waitStatus( 'inactive' );
	t.check( 'deactivation also rides admin-ajax', ajaxCalls.length === 2 && /status=inactive/.test( ajaxCalls[ 1 ] ), JSON.stringify( ajaxCalls ) );

	t.check( 'fresh render shows the switch off', await freshSwitchOn() === false, '' );
	await clickSwitch();
	// waitStatus IS the assertion here — a single follow-up read can catch
	// the REST write-visibility flap (rule 47c) and report stale state.
	await waitStatus( 'active' );
	t.check( 'resting state restored: Breeze active', true, '' );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
