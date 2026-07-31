/**
 * Real coming-soon / maintenance plugin detectors in site-status.php:
 * Maintenance (WebFactory), CMP (NiteoThemes), Minimal Coming Soon
 * (WebFactory) and the SeedProd JSON-string re-verify. Each plugin is
 * installed-inactive as a fixture; the suite activates one at a time, arms
 * its REAL status option through wp-cli (proving the option-shape reads,
 * which the minn_test_visibility mu-fixture deliberately can't), asserts
 * the /visibility endpoint, disarms, and restores. One UI pass (Maintenance
 * armed) proves the topbar chip picks a real plugin up on a fresh boot.
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
// PHP snippets are variable-free on purpose: execSync runs through sh, and a
// literal $ inside the double-quoted JSON.stringify wrapper would expand.
const phpEval = ( php ) => wp( `eval ${ JSON.stringify( php ) }` );

const PLUGINS = [
	{
		slug: 'maintenance',
		name: 'Maintenance',
		kind: 'maintenance',
		arm: 'update_option("mtnc-options", array("options" => array("status" => "1"), "meta" => array(), "dismissed_notices" => array()));',
		disarm: 'update_option("mtnc-options", array("options" => array("status" => "0"), "meta" => array(), "dismissed_notices" => array()));',
		uiCheck: true,
	},
	{
		slug: 'cmp-coming-soon-maintenance',
		name: 'CMP Coming Soon & Maintenance',
		kind: 'maintenance',
		arm: 'update_option("niteoCS_status", "1"); update_option("niteoCS_activation", "1");',
		disarm: 'update_option("niteoCS_status", "0");',
		// Second mode: same on switch, niteoCS_activation "2" = coming-soon.
		remode: { php: 'update_option("niteoCS_activation", "2");', kind: 'coming-soon' },
	},
	{
		slug: 'minimal-coming-soon-maintenance-mode',
		name: 'Minimal Coming Soon',
		kind: 'coming-soon',
		arm: 'update_option("signals_csmm_options", array_merge((array) get_option("signals_csmm_options", array()), array("status" => "1")));',
		disarm: 'update_option("signals_csmm_options", array_merge((array) get_option("signals_csmm_options", array()), array("status" => "2")));',
	},
	{
		slug: 'coming-soon',
		name: 'SeedProd',
		kind: 'coming-soon',
		// The JSON-STRING storage shape (SeedProd v5+) — the regression the
		// old detector missed entirely.
		arm: 'update_option("seedprod_settings", json_encode(array("api_key" => "", "enable_coming_soon_mode" => true, "enable_maintenance_mode" => false)));',
		disarm: 'update_option("seedprod_settings", json_encode(array("api_key" => "", "enable_coming_soon_mode" => false, "enable_maintenance_mode" => false)));',
		remode: {
			php: 'update_option("seedprod_settings", json_encode(array("api_key" => "", "enable_coming_soon_mode" => false, "enable_maintenance_mode" => true)));',
			kind: 'maintenance',
		},
	},
];

( async () => {
	const t = reporter( 'visibility-plugins' );
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

	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const restore = [];
	try {
		// Baseline: nothing else limiting visibility (rule: seed, don't assume).
		await setOpt( 'minn_admin_maintenance', false );
		await setOpt( 'blog_public', 1 );
		await setOpt( 'minn_test_visibility', '' );

		for ( const p of PLUGINS ) {
			const wasActive = isActive( p.slug );
			if ( ! wasActive ) {
				wp( `plugin activate ${ p.slug }` );
				restore.push( p );
			}
			phpEval( p.arm );

			const v = await visibility();
			const row = ( v.providers || [] ).find( ( x ) => x.name === p.name );
			t.check( `${ p.slug }: state is hidden while armed`, v.state === 'hidden', v.state );
			t.check( `${ p.slug }: provider row present with kind ${ p.kind }`, !! row && row.kind === p.kind, row && row.kind );
			t.check( `${ p.slug }: provider links to its settings page`, !! row && /wp-admin/.test( row.url || '' ) );

			if ( p.remode ) {
				phpEval( p.remode.php );
				const v2 = await visibility();
				const row2 = ( v2.providers || [] ).find( ( x ) => x.name === p.name );
				t.check( `${ p.slug }: alternate mode maps to kind ${ p.remode.kind }`, !! row2 && row2.kind === p.remode.kind, row2 && row2.kind );
			}

			if ( p.uiCheck ) {
				// A real plugin drives the chip on a fresh boot, same as the
				// fixture provider does in visibility-partial.
				await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '.minn-stats', { timeout: 20000 } );
				await page.waitForFunction( () => {
					const c = document.querySelector( '#minn-vis-chip' );
					return c && ! c.hidden && /Site hidden/.test( c.textContent );
				}, null, { timeout: 10000 } );
				t.check( `${ p.slug }: topbar chip reads Site hidden`, true );
				t.check( `${ p.slug }: banner names the plugin`, await page.evaluate( ( name ) => {
					const b = document.querySelector( '.minn-vis-banner' );
					return !! b && b.textContent.includes( name );
				}, p.name ) );
			}

			phpEval( p.disarm );
			const after = await visibility();
			t.check( `${ p.slug }: disarming returns to public`, after.state === 'public', after.state );

			if ( ! wasActive ) {
				wp( `plugin deactivate ${ p.slug }` );
				restore.pop();
			}
		}
	} finally {
		for ( const p of restore ) {
			phpEval( p.disarm );
			wp( `plugin deactivate ${ p.slug }` );
		}
		await setOpt( 'minn_test_visibility', '' ).catch( () => {} );
		await setOpt( 'blog_public', 1 ).catch( () => {} );
		await setOpt( 'minn_admin_maintenance', false ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
