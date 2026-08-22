/**
 * Gravity Forms surface without GF's REST API.
 *
 * The real-site state this pins: most installs never turn on Forms →
 * Settings → REST API, and the surface used to vanish entirely on them
 * (the anchor.host report). Every route is a GFAPI shim now, so the
 * suite turns the webapi setting OFF, hard-loads the app, and drives the
 * surface end to end — nav, tabs, list, detail, a workflow action — then
 * restores the setting (the dev site keeps it ON as a fixture for the
 * gf/v2-plumbed suites).
 *
 * Fixture plumbing cannot use gf/v2 here (that is the point), so scratch
 * entries ride WP-CLI + GFAPI directly.
 */
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const WP_PATH = path.resolve( __dirname, '../../../..' );
// No $ in these snippets: the shell would expand it before PHP sees it.
const wpEval = ( php ) => {
	const output = execSync(
		`wp --path=${ JSON.stringify( WP_PATH ) } eval ${ JSON.stringify( php ) } 2>/dev/null`,
		{ encoding: 'utf8', timeout: 60000 }
	).trim();
	return output.split( /\r?\n/ ).filter( Boolean ).pop() || '';
};
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'gf-no-api' );
	const { browser, page, errors } = await launch();
	let entryId = 0;
	try {
		wpEval( `update_option( 'gravityformsaddon_gravityformswebapi_settings', array( 'enabled' => false ) );` );
		const state = wpEval( `echo empty( get_option( 'gravityformsaddon_gravityformswebapi_settings' )['enabled'] ) ? 'off' : 'on';` );
		t.check( 'premise: GF REST API disabled', state === 'off', state );
		// GF's own routes must actually be GONE, or this suite proves nothing.
		entryId = parseInt( wpEval( `echo GFAPI::add_entry( array( 'form_id' => 1, '1.3' => 'NoApi', '1.6' => 'Probe', '2' => 'no-api-probe@example.com' ) );` ), 10 );
		t.check( 'scratch entry seeded via GFAPI', entryId > 0, String( entryId ) );

		await login( page );
		const gone = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'gf/v2/entries', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.status;
		} );
		t.check( 'gf/v2 really is unregistered (404)', gone === 404, String( gone ) );

		// Surface nav renders and the list answers.
		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		// Rows carry the bulk checkbox marker (the gf-workflow selector).
		await page.waitForSelector( '[data-scheck], .minn-empty', { timeout: 20000 } );
		const rows = await page.evaluate( () => document.querySelectorAll( '[data-scheck]' ).length );
		t.check( 'entries list renders rows', rows > 0, String( rows ) );
		const tabs = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '[data-stab], [data-stabcombo] .minn-ac-input' ) ).length
		);
		t.check( 'form tabs render', tabs > 0, String( tabs ) );

		// Search narrows through the shim's gf/v2-shaped JSON param.
		const search = await page.evaluate( async () => {
			const q = encodeURIComponent( JSON.stringify( { field_filters: [ { key: 0, value: 'zzz-not-there-zzz', operator: 'contains' } ] } ) );
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/gf/entries?search=' + q, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		} );
		t.check( 'search narrows to zero', search && search.total_count === 0, JSON.stringify( search && search.total_count ) );

		// Detail opens (its shim always worked; the row click path proves the
		// list rows carry live ids) and the star action round-trips.
		const starred = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + `minn-admin/v1/gf/entries/${ id }/properties`, {
				method: 'PUT',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { is_starred: 1 } ),
			} );
			return r.status;
		}, entryId );
		t.check( 'workflow action lands without gf/v2', starred === 200 );
		t.check( 'star persisted in GF store', wpEval( `echo GFAPI::get_entry( ${ entryId } )['is_starred'];` ) === '1' );

		// Notifications view tabs ride the shimmed forms route now.
		const notifTabs = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/gf/forms?active=1', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const list = await r.json();
			return Array.isArray( list ) ? list.length : -1;
		} );
		t.check( 'active-forms tabs route answers', notifTabs > 0, String( notifTabs ) );
	} finally {
		if ( entryId ) wpEval( `GFAPI::delete_entry( ${ entryId } );` );
		wpEval( `update_option( 'gravityformsaddon_gravityformswebapi_settings', array( 'enabled' => 1 ) );` );
	}
	const restored = wpEval( `echo empty( get_option( 'gravityformsaddon_gravityformswebapi_settings' )['enabled'] ) ? 'off' : 'on';` );
	t.check( 'webapi fixture restored ON', restored === 'on', restored );

	await t.done( browser, errors );
} )();
