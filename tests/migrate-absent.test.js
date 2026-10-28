/**
 * The Migrate screen without WP Migrate says so. It called a helper that
 * does not exist, so reaching /minn-admin/migrate with the plugin gone
 * (deactivated after the page loaded, or a stale link) threw instead of
 * explaining. The boot payload's wpMigrate is cleared in the page to stand
 * in for the plugin being absent.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'migrate-absent' );
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && document.querySelector( '#minn-view' ), null, { timeout: 30000 } );
	await page.evaluate( () => {
		window.MINN.wpMigrate = null;
		history.pushState( null, '', window.location.pathname.replace( /\/minn-admin\/.*$/, '/minn-admin/migrate' ) );
		window.dispatchEvent( new PopStateEvent( 'popstate' ) );
	} );
	await page.waitForTimeout( 1500 );
	const text = await page.$eval( '#minn-view', ( v ) => v.textContent );
	t.check( 'the screen explains WP Migrate is not available', /WP Migrate is not available/.test( text ), text.slice( 0, 160 ) );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
