/**
 * Prime the shared Playwright storageState used by login().
 *
 * run-all.sh calls this once so 269 suites skip wp-login + the wp-admin
 * landing. A lone `node foo.test.js` still works: the first login writes
 * the cookie, later runs in the same session reuse it.
 */
const { BASE, launch, login } = require( './helpers' );

( async () => {
	const { browser, page } = await launch();
	await login( page );
	console.log( 'Shared login cookie primed for ' + BASE );
	await Promise.race( [ browser.close(), new Promise( ( r ) => setTimeout( r, 5000 ) ) ] );
	process.exit( 0 );
} )().catch( ( e ) => {
	console.error( e && e.stack ? e.stack : e );
	process.exit( 1 );
} );
