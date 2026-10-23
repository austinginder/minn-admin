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
 *
 * With ACF PRO installed there too, it also hides one options page at a
 * time: a page White Label CMS hides leaves Site Options for the client
 * (its tab, then the whole item), whatever else ACF still shows. Those two
 * pages come from a must-use file the suite writes and removes, and ACF
 * PRO is switched back off when the lab kept it off.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { launch, login, loginAs, reporter, BASE, WP } = require( './helpers' );

const wp = ( ...args ) => execFileSync( 'wp', [ '--path=' + WP, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ], timeout: 60000 } ).trim();
const wpEval = ( code ) => wp( 'eval', code );

const CLIENT = 'minn-wlcms-client';
const CLIENT_PASS = 'minn-wlcms-client-1';
const BRICKS = [ 'bricks-templates', 'bricks-forms' ];
const ACF_FIXTURE = path.join( WP, 'wp-content/mu-plugins/minn-test-wlcms-acf.php' );
// Two ACF options pages, each with one field: one under Appearance, the way
// a theme's settings page usually sits, and one top-level page of its own.
const ACF_PAGES = `<?php
add_action( 'acf/init', function () {
	foreach ( array(
		array( 'minn-wlcms-theme', 'Theme Settings', 'themes.php' ),
		array( 'minn-wlcms-footer', 'Footer Settings', '' ),
	) as $p ) {
		acf_add_options_page( array( 'page_title' => $p[1], 'menu_title' => $p[1], 'menu_slug' => $p[0], 'parent_slug' => $p[2], 'capability' => 'manage_options' ) );
		acf_add_local_field_group( array(
			'key'      => 'group_' . str_replace( '-', '_', $p[0] ),
			'title'    => $p[1],
			'fields'   => array( array( 'key' => 'field_' . str_replace( '-', '_', $p[0] ), 'label' => 'Note', 'name' => str_replace( '-', '_', $p[0] ) . '_note', 'type' => 'text' ) ),
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $p[0] ) ) ),
		) );
	}
} );
`;

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
	const hasAcf = 'yes' === wpEval( 'echo file_exists( WP_PLUGIN_DIR . "/advanced-custom-fields-pro/acf.php" ) ? "yes" : "no";' );
	const acfWasActive = hasAcf && 'yes' === wpEval( 'echo is_plugin_active( "advanced-custom-fields-pro/acf.php" ) ? "yes" : "no";' );

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
		wpEval( '$o = get_option( "wlcms_options" ); $o["enable_wlcms_admin"] = 1; update_option( "wlcms_options", $o );' );

		// Options pages: hidden one at a time, they leave Site Options.
		if ( ! hasAcf ) {
			console.log( 'SKIP  options pages: needs ACF PRO installed' );
		} else {
			fs.writeFileSync( ACF_FIXTURE, ACF_PAGES );
			if ( ! acfWasActive ) {
				wp( 'plugin', 'activate', 'advanced-custom-fields-pro' );
			}
			// The tab labels Site Options shows this user, or null without it.
			const optionTabs = ( p ) => p.evaluate( async () => {
				const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/surfaces?_cb=' + Date.now(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
				} );
				const s = ( await r.json() ).find( ( x ) => 'site-options' === x.id );
				return s ? { label: s.label, tabs: ( s.settings && s.settings.tabs || [] ).map( ( x ) => x.label ) } : null;
			} );
			const both = ( o ) => !! o && o.tabs.includes( 'Theme Settings' ) && o.tabs.includes( 'Footer Settings' );

			configure( {} );
			await capture( client );
			const start = await optionTabs( client );
			t.check( 'with nothing hidden the client sees both options pages', both( start ), JSON.stringify( start ) );

			// Appearance hidden, the way the report had it: the theme's
			// settings page under it goes with it, ACF's other page stays.
			// No capture by hand here: saving White Label CMS's settings
			// marks the capture out of date, so the next load reads the menu.
			configure( { main: [ 'themes.php' ] } );
			await client.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
			await client.waitForSelector( '.minn-sidebar', { timeout: 20000 } );
			t.check( 'a White Label CMS save makes the next load recapture', true === await client.evaluate( () => !! ( window.MINN.notices && window.MINN.notices.stale ) ), '' );
			let noTheme = null;
			for ( let i = 0; i < 30; i++ ) {
				noTheme = await optionTabs( client );
				if ( noTheme && ! noTheme.tabs.includes( 'Theme Settings' ) ) {
					break;
				}
				await client.waitForTimeout( 1000 );
			}
			t.check( 'hiding Appearance takes Theme Settings out of Site Options', !! noTheme && ! noTheme.tabs.includes( 'Theme Settings' ) && noTheme.tabs.includes( 'Footer Settings' ) && 'Footer Settings' === noTheme.label, JSON.stringify( noTheme ) );
			const capA2 = await capture( client );
			const still = await optionTabs( client );
			t.check( 'and the next capture keeps it out (the page is still asked about)', false === capA2.surfaces_changed && !! still && ! still.tabs.includes( 'Theme Settings' ), JSON.stringify( { changed: capA2.surfaces_changed, still } ) );

			// A submenu hidden on its own.
			configure( { sub: [ 'themes.php_wlcms_minn-wlcms-theme' ] } );
			await capture( client );
			const subOnly = await optionTabs( client );
			t.check( 'hiding just the Theme Settings submenu does the same', !! subOnly && ! subOnly.tabs.includes( 'Theme Settings' ) && subOnly.tabs.includes( 'Footer Settings' ), JSON.stringify( subOnly ) );

			// Every options page hidden: the item leaves the sidebar.
			configure( { main: [ 'themes.php', 'minn-wlcms-footer' ] } );
			await capture( client );
			t.check( 'with every options page hidden Site Options leaves the client', null === await optionTabs( client ), '' );
			await client.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
			await client.waitForSelector( '.minn-sidebar', { timeout: 20000 } );
			t.check( 'nor in the client\'s nav', ! await client.$( '.minn-nav-btn[data-nav="site-options"]' ), '' );

			await capture( page );
			t.check( 'the White Label CMS admin keeps both', both( await optionTabs( page ) ), '' );

			configure( {} );
			const capB = await capture( client );
			t.check( 'unhiding them brings both back', true === capB.surfaces_changed && both( await optionTabs( client ) ), JSON.stringify( capB.surfaces_changed ) );
		}
	} finally {
		if ( fs.existsSync( ACF_FIXTURE ) ) {
			fs.unlinkSync( ACF_FIXTURE );
		}
		if ( hasAcf && ! acfWasActive ) {
			wp( 'plugin', 'deactivate', 'advanced-custom-fields-pro' );
		}
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
