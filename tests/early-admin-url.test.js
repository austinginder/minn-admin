/**
 * admin_url() during plugin include must not fatal.
 *
 * Minn rewrites some admin_url() values for people who treat Minn as the
 * default admin. That filter used to call is_user_logged_in() as soon as
 * it ran. Two shapes of third-party plugin then took the whole site down
 * the moment Minn was activated:
 *
 *   - a plugin that builds an admin URL from its constructor, before
 *     pluggable.php exists (is_user_logged_in is undefined)
 *   - a plugin that defines is_user_logged_in() itself in terms of
 *     wp_get_current_user(), then another that calls admin_url() while
 *     plugins are still loading (the helper exists, its dependency does not)
 *
 * The second is what GitHub issue 51 was. WP-CLI cannot catch it: the
 * filter bails on WP_CLI before the pluggable call. This suite drops a
 * throwaway plugin that is both shapes at once, hits the front end over
 * HTTP, and requires a 200 plus the probe call completing.
 *
 * No browser. MINN_TEST_URL / MINN_TEST_WP select the site (defaults to
 * this plugin's site).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const { BASE, WP } = require( './helpers' );

const SLUG = 'minn-early-admin-url';
const DIR = path.join( WP, 'wp-content/plugins', SLUG );
const FILE = path.join( DIR, SLUG + '.php' );
const OPTION = 'minn_early_admin_url_ran';

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( !! ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};

function runWp( args ) {
	try {
		const out = execSync( `wp --path=${ JSON.stringify( WP ) } ${ args }`, {
			encoding: 'utf8',
			timeout: 90000,
			maxBuffer: 10 * 1024 * 1024,
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} );
		return { ok: true, out: String( out || '' ), err: '' };
	} catch ( e ) {
		const out = String( e.stdout || '' );
		const err = String( e.stderr || '' );
		return { ok: false, out, err, combined: out + '\n' + err + '\n' + ( e.message || '' ) };
	}
}

function looksFatal( text ) {
	return /Fatal error:|Call to undefined function|There has been a critical error|Whoops! There was an error/i.test( text || '' );
}

function httpGet( url ) {
	try {
		const out = execSync(
			`curl -sk -o /tmp/minn-early-admin-url.body -w "%{http_code}" ${ JSON.stringify( url ) }`,
			{ encoding: 'utf8', timeout: 30000 }
		);
		const body = fs.readFileSync( '/tmp/minn-early-admin-url.body', 'utf8' );
		return { code: String( out || '' ).trim(), body };
	} catch ( e ) {
		return { code: '000', body: String( e.stderr || e.message || '' ) };
	}
}

const PROBE = `<?php
/**
 * Plugin Name: Minn early admin_url probe
 * Description: Test fixture. Do not leave active.
 * Version: 0.0.0
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		$user = wp_get_current_user();
		return ! empty( $user->ID );
	}
}

function minn_early_admin_url_fire() {
	if ( ! class_exists( 'Minn_Admin', false ) ) {
		return;
	}
	if ( did_action( 'plugins_loaded' ) ) {
		return;
	}
	admin_url( 'nav-menus.php' );
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
		update_option( 'minn_early_admin_url_ran', '1', false );
	}
}

if ( class_exists( 'Minn_Admin', false ) ) {
	minn_early_admin_url_fire();
} else {
	add_action( 'plugin_loaded', function () {
		minn_early_admin_url_fire();
	} );
}
`;

function cleanup() {
	runWp( `plugin deactivate ${ SLUG } --quiet` );
	try {
		fs.rmSync( DIR, { recursive: true, force: true } );
	} catch ( e ) { /* gone */ }
	runWp( `option delete ${ OPTION }` );
}

try {
	cleanup();
	fs.mkdirSync( DIR, { recursive: true } );
	fs.writeFileSync( FILE, PROBE );
	runWp( `option delete ${ OPTION }` );

	const act = runWp( `plugin activate ${ SLUG }` );
	check( 'probe plugin activates',
		act.ok && ! looksFatal( act.out + act.err ),
		act.ok ? '' : ( act.combined || '' ).split( '\n' ).slice( -6 ).join( ' | ' ) );
	runWp( `option delete ${ OPTION }` );

	const home = httpGet( BASE.replace( /\/$/, '' ) + '/?minn-early-admin-url=' + Date.now() );
	check( 'front end is HTTP 200 with the probe active',
		home.code === '200',
		'HTTP ' + home.code );
	check( 'front end is not a critical-error page',
		! looksFatal( home.body ),
		looksFatal( home.body ) ? home.body.slice( 0, 160 ).replace( /\s+/g, ' ' ) : '' );

	const ran = runWp( `option get ${ OPTION }` );
	check( 'probe called admin_url() during plugin include',
		ran.ok && ran.out.trim() === '1',
		ran.ok ? ran.out.trim() : ( ran.combined || '' ).split( '\n' ).slice( -4 ).join( ' | ' ) );
} finally {
	cleanup();
}

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\nearly-admin-url: ${ results.length - failed }/${ results.length } passed` );
process.exit( failed ? 1 : 0 );
