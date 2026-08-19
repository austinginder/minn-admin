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

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\ncli-bootstrap: ${ results.length - failed }/${ results.length } passed` );
process.exit( failed ? 1 : 0 );
