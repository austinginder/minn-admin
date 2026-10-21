/**
 * Minn's boot payload carries a real nonce under LiteSpeed Cache's ESI.
 *
 * With ESI on, LiteSpeed Cache swaps the nonces it knows (wp_rest among
 * them) for an <esi:include> tag that the LiteSpeed server fills from a
 * sub-request. Spliced into the boot payload's inline script, a sub-request
 * that answers with markup closes the script early and prints the rest of
 * the payload on the page. Minn turns ESI off for its own document, so the
 * payload's nonce must be the bare ten characters and REST must accept it.
 *
 * Needs a lab with LiteSpeed Cache active and ESI on (`litespeed-option set
 * esi 1`). Off a LiteSpeed server, an mu-plugin setting
 * $_SERVER['X-LSCACHE'] = 'on,crawler,esi,combine' and
 * $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed' makes the plugin take its ESI
 * path. Without MINN_LITESPEED_URL this SKIPs (exit 0).
 *
 * Run: MINN_LITESPEED_URL=https://<lab> MINN_LITESPEED_USER=admin MINN_LITESPEED_PASS=… node tests/litespeed-esi.test.js
 */
'use strict';

const { chromium } = require( 'playwright-core' );

const BASE = ( process.env.MINN_LITESPEED_URL || '' ).replace( /\/$/, '' );
if ( ! BASE ) {
	console.log( 'SKIP: MINN_LITESPEED_URL is not set (needs a LiteSpeed Cache lab with ESI on)' );
	process.exit( 0 );
}

const checks = [];
const check = ( label, ok, detail = '' ) => {
	checks.push( !! ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ ok || ! detail ? '' : ' — ' + detail }` );
};

( async () => {
	const browser = await chromium.launch( {
		executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
		args: [ '--ignore-certificate-errors', '--disable-http2', '--disable-features=MacAppCodeSignClone' ],
	} );
	try {
		const ctx = await browser.newContext( { ignoreHTTPSErrors: true } );
		const page = await ctx.newPage();
		const errors = [];
		const refused = [];
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		page.on( 'response', ( r ) => {
			if ( r.url().includes( '/wp-json/' ) && 403 === r.status() ) refused.push( r.url() );
		} );

		await page.goto( `${ BASE }/wp-login.php` );
		await page.fill( '#user_login', process.env.MINN_LITESPEED_USER || 'admin' );
		await page.fill( '#user_pass', process.env.MINN_LITESPEED_PASS || '' );
		await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );

		const res = await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'load', timeout: 90000 } );
		const html = await res.text();
		check( 'no ESI tag in the document', ! /<esi:/i.test( html ), ( html.match( /<esi:[^>]{0,120}/i ) || [ '' ] )[ 0 ] );

		await page.waitForFunction( () => document.querySelector( '#minn-app nav, #minn-app aside' ), null, { timeout: 30000 } ).catch( () => {} );
		const nonce = await page.evaluate( () => window.MINN && window.MINN.nonce );
		check( 'boot nonce is a bare nonce', /^[0-9a-f]{10}$/.test( String( nonce ) ), String( nonce ).slice( 0, 120 ) );
		check( 'the app boots', await page.evaluate( () => !! document.querySelector( '#minn-app nav, #minn-app aside' ) ) );
		check( 'no page errors', ! errors.length, errors.slice( 0, 3 ).join( ' | ' ) );
		check( 'REST accepts the nonce', ! refused.length, refused.slice( 0, 3 ).join( ' ' ) );
	} finally {
		await browser.close().catch( () => {} );
	}
	const failed = checks.filter( ( ok ) => ! ok ).length;
	console.log( failed ? `\n${ failed } FAILED` : `\nAll ${ checks.length } checks passed` );
	process.exit( failed ? 1 : 0 );
} )();
