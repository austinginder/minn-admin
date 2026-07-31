/**
 * Visibility toggle writers (Phase 2): turning a third-party coming-soon /
 * maintenance mode off from Minn, with Undo restoring the exact mode.
 * The generic UI plumbing (banner switch → Undo toast → Settings button) is
 * driven through the mu-fixture provider so no real plugin mode is armed
 * during the UI passes; the real writers are proven endpoint-level against
 * CMP (mode persistence) and SeedProd (kind memory: maintenance restored,
 * not coming-soon).
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

const WP = path.resolve( __dirname, '../../../../' );
const wp = ( args ) => {
	try {
		return execSync( `wp --path=${ JSON.stringify( WP ) } ${ args }`, {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			timeout: 90000,
		} );
	} catch ( e ) {
		return ( e.stdout || '' ) + ( e.stderr || '' );
	}
};
const isActive = ( slug ) => {
	try {
		execSync( `wp --path=${ JSON.stringify( WP ) } plugin is-active ${ slug }`, {
			stdio: 'ignore', timeout: 30000,
		} );
		return true;
	} catch ( e ) {
		return false;
	}
};
const phpEval = ( php ) => wp( `eval ${ JSON.stringify( php ) }` );

( async () => {
	const t = reporter( 'visibility-toggle' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = ( k, v ) => page.evaluate( async ( [ key, val ] ) => {
		const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
		await fetch( window.MINN.restUrl + 'wp/v2/settings', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify( { [ key ]: val } ) } );
	}, [ k, v ] );
	const visibility = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/visibility?_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return r.json();
	} );
	const toggle = ( id, on ) => page.evaluate( async ( [ pid, pon ] ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/visibility/toggle', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { id: pid, on: pon } ),
		} );
		return { status: r.status, body: await r.json() };
	}, [ id, on ] );

	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const cmpWasActive = isActive( 'cmp-coming-soon-maintenance' );
	const spWasActive = isActive( 'coming-soon' );
	try {
		// Baseline (rule: seed, don't assume).
		await setOpt( 'minn_admin_maintenance', false );
		await setOpt( 'blog_public', 1 );
		await setOpt( 'minn_test_visibility', '' );
		phpEval( 'delete_option("minn_test_visibility_was_on"); delete_option("minn_admin_vis_restore");' );

		// ---- Generic UI plumbing via the fixture provider ----
		await setOpt( 'minn_test_visibility', 'maintenance' );
		const armed = await visibility();
		const row = ( armed.providers || [] ).find( ( p ) => p.id === 'minn-fixture-vis' );
		t.check( 'fixture provider carries can:true', !! row && row.can === true );

		// Fresh boot: the banner offers a switch, not a link-out.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-vis-banner', { timeout: 20000 } );
		const sw = await page.$( '.minn-vis-banner .minn-switch[aria-label="Minn Visibility Fixture"]' );
		t.check( 'banner renders a switch for the toggleable provider', !! sw );

		// Turn off from the banner → Undo toast, chip clears, state public.
		await sw.click();
		await page.waitForSelector( '.minn-toast-action .minn-toast-btn', { timeout: 10000 } );
		t.check( 'turn-off shows an Undo toast naming the provider', await page.evaluate( () =>
			/Minn Visibility Fixture turned off/.test( document.querySelector( '.minn-toast-action' ).textContent ) ) );
		const offState = await visibility();
		t.check( 'mode is off server-side', offState.state === 'public', offState.state );
		t.check( 'chip cleared without a reload', await page.evaluate( () => {
			const c = document.querySelector( '#minn-vis-chip' );
			return ! c || c.hidden;
		} ) );

		// Undo restores the exact kind that was on.
		await page.click( '.minn-toast-action .minn-toast-btn' );
		await page.waitForFunction( () => {
			const c = document.querySelector( '#minn-vis-chip' );
			return c && ! c.hidden;
		}, null, { timeout: 10000 } );
		const back = await visibility();
		const backRow = ( back.providers || [] ).find( ( p ) => p.id === 'minn-fixture-vis' );
		t.check( 'Undo re-arms the provider', back.state === 'hidden', back.state );
		t.check( 'Undo restores the maintenance kind', !! backRow && backRow.kind === 'maintenance', backRow && backRow.kind );

		// ---- Settings → Visibility Turn off button ----
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );
		await page.evaluate( () => { [ ...document.querySelectorAll( '.minn-settings-nav-item' ) ].find( ( b ) => b.textContent.trim() === 'Visibility' ).click(); } );
		await page.waitForSelector( '[data-visoff="minn-fixture-vis"]', { timeout: 8000 } );
		t.check( 'Settings row offers Turn off next to Open', true );
		await page.click( '[data-visoff="minn-fixture-vis"]' );
		await page.waitForFunction( () => ! document.querySelector( '[data-visoff="minn-fixture-vis"]' ), null, { timeout: 10000 } );
		t.check( 'Settings row disappears after turn-off (route re-rendered)', true );
		t.check( 'Settings turn-off lands server-side', ( await visibility() ).state === 'public' );

		// ---- Real writers, endpoint-level ----
		// CMP: the on switch round-trips while niteoCS_activation persists.
		if ( ! cmpWasActive ) wp( 'plugin activate cmp-coming-soon-maintenance' );
		phpEval( 'update_option("niteoCS_status", "1"); update_option("niteoCS_activation", "1");' );
		const cmpOff = await toggle( 'cmp', false );
		t.check( 'CMP turn-off answers with fresh public state', cmpOff.status === 200 && cmpOff.body.state === 'public' );
		t.check( 'CMP option written through their storage', wp( 'option get niteoCS_status' ).trim() === '0' );
		const cmpOn = await toggle( 'cmp', true );
		const cmpRow = ( cmpOn.body.providers || [] ).find( ( p ) => p.id === 'cmp' );
		t.check( 'CMP undo restores maintenance mode', !! cmpRow && cmpRow.kind === 'maintenance', cmpRow && cmpRow.kind );
		phpEval( 'update_option("niteoCS_status", "0");' );
		if ( ! cmpWasActive ) wp( 'plugin deactivate cmp-coming-soon-maintenance' );

		// SeedProd: kind memory — a maintenance page must not undo into a
		// coming-soon page.
		if ( ! spWasActive ) wp( 'plugin activate coming-soon' );
		phpEval( 'update_option("seedprod_settings", json_encode(array("api_key" => "", "enable_coming_soon_mode" => false, "enable_maintenance_mode" => true)));' );
		const spOff = await toggle( 'seedprod', false );
		t.check( 'SeedProd turn-off lands', spOff.status === 200 && spOff.body.state === 'public' );
		const spOn = await toggle( 'seedprod', true );
		const spRow = ( spOn.body.providers || [] ).find( ( p ) => p.id === 'seedprod' );
		t.check( 'SeedProd undo restores the maintenance mode exactly', !! spRow && spRow.kind === 'maintenance', spRow && spRow.kind );
		phpEval( 'update_option("seedprod_settings", json_encode(array("api_key" => "", "enable_coming_soon_mode" => false, "enable_maintenance_mode" => false)));' );
		if ( ! spWasActive ) wp( 'plugin deactivate coming-soon' );

		// Unknown provider id refuses cleanly.
		const nope = await toggle( 'nope', false );
		t.check( 'unknown provider id 404s', nope.status === 404 );
	} finally {
		await setOpt( 'minn_test_visibility', '' ).catch( () => {} );
		await setOpt( 'blog_public', 1 ).catch( () => {} );
		await setOpt( 'minn_admin_maintenance', false ).catch( () => {} );
		phpEval( 'delete_option("minn_test_visibility_was_on"); delete_option("minn_admin_vis_restore"); update_option("niteoCS_status", "0"); update_option("seedprod_settings", json_encode(array("api_key" => "", "enable_coming_soon_mode" => false, "enable_maintenance_mode" => false)));' );
		if ( ! cmpWasActive && isActive( 'cmp-coming-soon-maintenance' ) ) wp( 'plugin deactivate cmp-coming-soon-maintenance' );
		if ( ! spWasActive && isActive( 'coming-soon' ) ) wp( 'plugin deactivate coming-soon' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
