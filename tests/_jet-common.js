/**
 * Shared helpers for the Jet-pack surface suites: a JSON fetch through the
 * app's nonce, a wp-cli eval-file runner (retries through worker recycles),
 * and a surface-page paint check.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const WP_PATH = process.env.MINN_TEST_WP || path.resolve( __dirname, '../../../..' );
const evalPhp = ( tag, php ) => {
	const file = path.join( os.tmpdir(), `minn-${ tag }-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		for ( let attempt = 1; attempt <= 4; attempt++ ) {
			try {
				return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } --user=admin 2>/dev/null`, { encoding: 'utf8', timeout: 60000 } ).trim();
			} catch ( e ) {
				if ( attempt === 4 ) return ( e.stdout || '' ).trim();
				execSync( 'sleep 3' );
			}
		}
	} finally {
		try { fs.unlinkSync( file ); } catch ( e ) { /* ignore */ }
	}
	return '';
};
const apiFor = ( page ) => ( p, opts ) => page.evaluate( async ( [ pathArg, o ] ) => {
	const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
		method: ( o && o.method ) || 'GET',
		headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
		credentials: 'same-origin',
		body: o && o.body ? JSON.stringify( o.body ) : undefined,
	} );
	let body = null;
	try { body = await r.json(); } catch ( e ) { body = null; }
	return { status: r.status, body };
}, [ p, opts || null ] );
const paintSurface = async ( page, BASE, id, needle ) => {
	for ( let attempt = 1; attempt <= 3; attempt++ ) {
		try {
			await page.goto( `${ BASE }/minn-admin/${ id }`, { waitUntil: 'domcontentloaded', timeout: 45000 } );
			await page.waitForFunction( ( n ) => new RegExp( n ).test( ( document.querySelector( '#minn-view' ) || {} ).textContent || '' ), needle, { timeout: 30000 } );
			return true;
		} catch ( e ) {
			if ( attempt === 3 ) return false;
			await page.waitForTimeout( 4000 );
		}
	}
	return false;
};
module.exports = { evalPhp, apiFor, paintSurface, WP_PATH };
