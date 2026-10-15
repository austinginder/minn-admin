/**
 * WP-CLI still boots with Minn loaded.
 *
 * A filter on admin_url used to call is_user_logged_in() while plugins
 * were still loading, before pluggable.php existed. Any plugin that
 * builds an admin URL from its constructor (WPMU DEV Dashboard is the
 * one that came up) then crashed every command. `wp option get home`
 * is the canary: it loads WordPress and Minn and does almost no work.
 *
 * No browser. MINN_TEST_WP selects the WordPress root (defaults to
 * this plugin's site). Point it at a heavier fixture to exercise more
 * constructors.
 */
const { execSync } = require( 'child_process' );
const { WP } = require( './helpers' );

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
	return /Fatal error:|Call to undefined function|There has been a critical error/i.test( text || '' );
}

const home = runWp( 'option get home' );
check( 'wp option get home boots without a fatal',
	home.ok && ! looksFatal( home.out + home.err ),
	home.ok ? '' : ( home.combined || '' ).split( '\n' ).slice( -8 ).join( ' | ' ) );
check( 'wp option get home prints the site URL',
	home.ok && /^https?:\/\//.test( home.out.trim() ),
	home.out.trim().split( '\n' ).pop() );

const menus = runWp( 'eval \'echo admin_url( "nav-menus.php" );\'' );
check( 'admin_url() during CLI does not fatal',
	menus.ok && ! looksFatal( menus.out + menus.err ),
	menus.ok ? '' : ( menus.combined || '' ).split( '\n' ).slice( -8 ).join( ' | ' ) );
check( 'admin_url( nav-menus.php ) still returns a menus URL',
	menus.ok && /nav-menus\.php/.test( menus.out ),
	menus.out.trim().split( '\n' ).pop() );

// Nothing of Minn's is translated while plugins load. Before init the
// reader's language is not loaded yet, and WordPress 6.7+ says so in a notice
// on every request and every command ("Translation loading for the
// minn-admin domain was triggered too early"). An adapter that declares as
// its file loads keeps its strings in a function: a resource's declaration,
// an endpoint's run (a WooCommerce Memberships endpoint's label slipped
// through once). A required file names each string's place.
const os = require( 'os' );
const fs = require( 'fs' );
const path = require( 'path' );
const early = path.join( fs.mkdtempSync( path.join( os.tmpdir(), 'minn-early-i18n-' ) ), 'early-i18n.php' );
fs.writeFileSync( early, `<?php
$GLOBALS['minn_early_i18n'] = array();
WP_CLI::add_wp_hook( 'gettext_minn-admin', static function ( $translation ) {
	if ( ! did_action( 'init' ) ) {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $f ) {
			if ( isset( $f['file'] ) && false !== strpos( $f['file'], '/plugins/minn-admin/' ) ) {
				$GLOBALS['minn_early_i18n'][] = preg_replace( '#^.*/plugins/minn-admin/#', '', $f['file'] ) . ':' . $f['line'];
				break;
			}
		}
	}
	return $translation;
} );
WP_CLI::add_hook( 'after_wp_load', static function () {
	echo 'MINN-EARLY-I18N ', json_encode( array_values( array_unique( $GLOBALS['minn_early_i18n'] ) ) ), "\n";
} );
` );
const translated = runWp( `--require=${ JSON.stringify( early ) } option get home` );
const earlyLine = ( translated.out.match( /^MINN-EARLY-I18N (.*)$/m ) || [] )[ 1 ];
const earlySites = earlyLine ? JSON.parse( earlyLine ) : null;
check( 'no Minn string is translated before init',
	Array.isArray( earlySites ) && earlySites.length === 0,
	earlySites ? earlySites.join( ', ' ) : ( translated.combined || translated.out ).split( '\n' ).slice( -4 ).join( ' | ' ) );
fs.rmSync( path.dirname( early ), { recursive: true, force: true } );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\ncli-bootstrap: ${ results.length - failed }/${ results.length } passed` );
process.exit( failed ? 1 : 0 );
