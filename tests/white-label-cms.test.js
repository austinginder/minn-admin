/**
 * White Label CMS (adapters/white-label-cms.php): a plugin menu an agency
 * hides from clients there leaves Minn for the same clients.
 *
 * White Label CMS hides its chosen menus from everyone except its own White
 * Label CMS Admins. The notices capture asks the adapter which pages were
 * hidden on purpose, and a plugin whose every page is gone loses its
 * surfaces for that user (removed-menus.test.js covers the mechanism with
 * a fixture). Needs Bricks as the active theme and White Label CMS
 * installed (the suite switches it on and back off), so on minnadmin this
 * SKIPs (exit 0). Run it against the builders lab:
 *
 *   MINN_TEST_URL=https://builders.localhost MINN_TEST_USER=admin \
 *   MINN_TEST_PASS=<the lab password> \
 *   MINN_TEST_WP=<the lab WordPress path> \
 *   node white-label-cms.test.js
 *
 * It creates the client account minn-wlcms-client and deletes it at the
 * end, and puts White Label CMS's settings back as it found them.
 */
const { execFileSync } = require( 'child_process' );
const { launch, login, loginAs, reporter, BASE, WP } = require( './helpers' );

const wp = ( ...args ) => execFileSync( 'wp', [ '--path=' + WP, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ], timeout: 60000 } ).trim();
const wpEval = ( code ) => wp( 'eval', code );

const CLIENT = 'minn-wlcms-client';
const CLIENT_PASS = 'minn-wlcms-client-1';
const BRICKS = [ 'bricks-templates', 'bricks-forms' ];

( async () => {
	const t = reporter( 'white-label-cms' );
	const ready = wpEval( 'echo ( file_exists( WP_PLUGIN_DIR . "/white-label-cms/wlcms-plugin.php" ) && "bricks" === get_template() ) ? "yes" : "no";' );
	if ( 'yes' !== ready ) {
		console.log( 'SKIP  needs White Label CMS installed and Bricks as the theme (run on builders.localhost)' );
		process.exit( 0 );
	}
	// The lab keeps White Label CMS installed but off, so it never brands
	// the screens other suites drive there.
	const wasActive = 'yes' === wpEval( 'echo is_plugin_active( "white-label-cms/wlcms-plugin.php" ) ? "yes" : "no";' );
	if ( ! wasActive ) {
		wp( 'plugin', 'activate', 'white-label-cms' );
	}

	const { browser, page, errors } = await launch();
	const before = wpEval( 'echo wp_json_encode( get_option( "wlcms_options", null ) );' );
	const configure = ( menus ) => wpEval( `
		$o = get_option( 'wlcms_options', array() );
		$o = is_array( $o ) ? $o : array();
		$o['enable_wlcms_admin'] = 1;
		$o['wlcms_admin'] = array( 1 );
		$o['admin_menus'] = json_decode( '${ JSON.stringify( menus ) }', true );
		update_option( 'wlcms_options', $o );
	` );
	let clientId = '';
	let client = null;

	const capture = ( p ) => p.evaluate( async () => {
		const r = await fetch( window.MINN.notices.url, { credentials: 'same-origin' } );
		return r.json();
	} );
	const surfaceIds = ( p ) => p.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/surfaces?_cb=' + Date.now(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
		} );
		return ( await r.json() ).map( ( s ) => s.id );
	} );
	// What White Label CMS itself does to the wp-admin menu.
	const wpAdminHasBricks = ( p ) => p.evaluate( async () => {
		const r = await fetch( '/wp-admin/index.php', { credentials: 'same-origin' } );
		return /toplevel_page_bricks\b/.test( await r.text() );
	} );

	try {
		clientId = wpEval( `
			$u = get_user_by( 'login', '${ CLIENT }' );
			$id = $u ? $u->ID : wp_insert_user( array( 'user_login' => '${ CLIENT }', 'user_email' => '${ CLIENT }@example.invalid', 'user_pass' => '${ CLIENT_PASS }', 'role' => 'administrator' ) );
			wp_set_password( '${ CLIENT_PASS }', $id );
			echo (int) $id;
		` );
		t.check( 'client administrator created', /^\d+$/.test( clientId ) && '0' !== clientId, clientId );

		// The agency hides the whole Bricks menu from clients.
		configure( { main: [ 'bricks' ] } );
		await login( page );
		( { page: client } = await loginAs( browser, CLIENT, CLIENT_PASS ) );
		t.check( 'White Label CMS hides Bricks in the client\'s wp-admin', ! await wpAdminHasBricks( client ), '' );
		t.check( 'and keeps it for its own admin', await wpAdminHasBricks( page ), '' );

		// The client's first visit runs the app's own capture, which takes
		// Bricks out of the open page without a reload.
		const gone = await client.waitForFunction( ( ids ) => ( window.MINN.surfaces || [] ).length > 0
			&& ! ( window.MINN.surfaces || [] ).some( ( s ) => ids.includes( s.id ) )
			&& ! ids.some( ( id ) => document.querySelector( `.minn-nav-btn[data-nav="${ id }"]` ) ), BRICKS, { timeout: 30000, polling: 500 } ).then( () => true, () => false );
		t.check( 'the client\'s first visit drops Bricks from the open app', gone, '' );
		const hidden = await surfaceIds( client );
		t.check( 'Bricks surfaces gone for the client', ! hidden.some( ( id ) => BRICKS.includes( id ) ) && hidden.length > 0, JSON.stringify( hidden ) );
		const cap = await capture( client );
		t.check( 'a second capture finds nothing new', cap && cap.ok && false === cap.surfaces_changed, JSON.stringify( cap && cap.surfaces_changed ) );

		await client.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await client.waitForSelector( '.minn-sidebar', { timeout: 20000 } );
		const boot = await client.evaluate( ( ids ) => ( {
			payload: ( window.MINN.surfaces || [] ).filter( ( s ) => ids.includes( s.id ) ).map( ( s ) => s.id ),
			nav: ids.filter( ( id ) => document.querySelector( `.minn-nav-btn[data-nav="${ id }"]` ) ),
		} ), BRICKS );
		t.check( 'nor in the client\'s boot payload or nav', ! boot.payload.length && ! boot.nav.length, JSON.stringify( boot ) );

		await capture( page );
		const kept = await surfaceIds( page );
		t.check( 'the White Label CMS admin keeps them', BRICKS.every( ( id ) => kept.includes( id ) ), JSON.stringify( kept.filter( ( id ) => id.startsWith( 'bricks' ) ) ) );

		// Hiding one submenu leaves the rest of Bricks in the client's menu,
		// so Bricks stays in Minn.
		configure( { sub: [ 'bricks_wlcms_bricks-settings' ] } );
		const cap2 = await capture( client );
		const partial = await surfaceIds( client );
		t.check( 'one hidden submenu keeps Bricks for the client', true === cap2.surfaces_changed && BRICKS.every( ( id ) => partial.includes( id ) ), JSON.stringify( { changed: cap2.surfaces_changed, ids: partial.filter( ( id ) => id.startsWith( 'bricks' ) ) } ) );

		// Menu hiding switched off altogether.
		configure( { main: [ 'bricks' ] } );
		await capture( client );
		wpEval( '$o = get_option( "wlcms_options" ); $o["enable_wlcms_admin"] = 0; update_option( "wlcms_options", $o );' );
		const cap3 = await capture( client );
		const off = await surfaceIds( client );
		t.check( 'turning White Label CMS\'s menu hiding off brings Bricks back', true === cap3.surfaces_changed && BRICKS.every( ( id ) => off.includes( id ) ), JSON.stringify( { changed: cap3.surfaces_changed, ids: off.filter( ( id ) => id.startsWith( 'bricks' ) ) } ) );
	} finally {
		if ( 'null' === before ) {
			wpEval( 'delete_option( "wlcms_options" );' );
		} else {
			wpEval( `update_option( 'wlcms_options', json_decode( '${ before.replace( /\\/g, '\\\\' ).replace( /'/g, "\\'" ) }', true ) );` );
		}
		if ( /^\d+$/.test( clientId ) && '0' !== clientId ) {
			wp( 'user', 'delete', clientId, '--yes' );
		}
		await capture( page ).catch( () => {} );
		if ( ! wasActive ) {
			wp( 'plugin', 'deactivate', 'white-label-cms' );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
