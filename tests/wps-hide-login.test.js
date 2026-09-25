/**
 * WPS Hide Login — the System "Login address" health row.
 *
 * Proves: with a custom slug the row passes and names both the login address
 * and where wp-login.php / wp-admin send visitors; on the plugin's default
 * `login` slug it warns; a non-administrator gets no row; with the plugin
 * inactive there is no row at all.
 *
 * Driven entirely over wp-cli (rest_do_request as admin): an ACTIVE login
 * hider moves wp-login.php, which would break the browser login of any suite
 * running alongside, so the plugin is on only for the few seconds this takes
 * and is restored (with its options) in finally.
 */
const { execSync } = require( 'child_process' );
const { WP, reporter } = require( './helpers' );

const wp = ( args ) => execSync( `wp --path=${ JSON.stringify( WP ) } ${ args } 2>/dev/null` ).toString().trim();
// PHP rides stdin (eval-file -) so the shell never expands its $vars.
const wpPhp = ( php, user = 'admin' ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=${ user } 2>/dev/null`, { input: '<?php ' + php } )
	.toString().trim().split( '\n' ).pop();

const loginRow = ( user ) => JSON.parse( wpPhp( `
	$d = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/system' ) )->get_data();
	$row = null;
	foreach ( (array) ( is_array( $d ) && isset( $d['checks'] ) ? $d['checks'] : array() ) as $c ) {
		if ( isset( $c['label'] ) && 'Login address' === $c['label'] ) { $row = $c; }
	}
	echo wp_json_encode( array( 'row' => $row, 'fn' => function_exists( 'minn_admin_wps_hide_login_checks' ) ? count( minn_admin_wps_hide_login_checks() ) : -1 ) );
`, user ) );

( async () => {
	const t = reporter( 'wps-hide-login' );
	let wasActive = false;
	let before = { page: null, redirect: null };
	try {
		wp( 'plugin is-installed wps-hide-login' );
	} catch ( e ) {
		console.log( 'SKIP  wps-hide-login is not installed' );
		process.exit( 0 );
	}
	try {
		try { wp( 'plugin is-active wps-hide-login' ); wasActive = true; } catch ( e ) {}
		before = JSON.parse( wpPhp( "echo wp_json_encode( array( 'page' => get_option( 'whl_page', null ), 'redirect' => get_option( 'whl_redirect_admin', null ) ) );" ) );

		if ( wasActive ) wp( 'plugin deactivate wps-hide-login' );
		t.check( 'inactive plugin: no Login address row', null === loginRow( 'admin' ).row );

		wp( 'plugin activate wps-hide-login' );
		wp( 'option update whl_page minn-front-door' );
		wp( 'option update whl_redirect_admin minn-nowhere' );
		const custom = loginRow( 'admin' ).row;
		t.check( 'custom slug passes and names the address and the redirect',
			!! custom && 'pass' === custom.status && /\/minn-front-door\//.test( custom.detail ) && /\/minn-nowhere\//.test( custom.detail ),
			JSON.stringify( custom ) );
		t.check( 'the row links to their settings section', !! custom && /options-general\.php#whl_settings$/.test( custom.href || '' ) );

		wp( 'option update whl_page login' );
		const def = loginRow( 'admin' ).row;
		t.check( 'default login slug warns', !! def && 'warn' === def.status && /\/login\//.test( def.detail ), JSON.stringify( def ) );

		t.check( 'an Editor gets no row', 0 === loginRow( 'minn-editor' ).fn );
	} finally {
		try {
			for ( const [ key, val ] of [ [ 'whl_page', before.page ], [ 'whl_redirect_admin', before.redirect ] ] ) {
				if ( null === val || false === val ) wp( `option delete ${ key }` ); else wp( `option update ${ key } ${ JSON.stringify( String( val ) ) }` );
			}
		} catch ( e ) { /* option already absent */ }
		try { wp( wasActive ? 'plugin activate wps-hide-login' : 'plugin deactivate wps-hide-login' ); } catch ( e ) {}
	}

	// No browser in this suite; done() still reports and exits.
	await t.done( { contexts: () => [], close: async () => {} }, [] );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
