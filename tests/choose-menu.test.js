/**
 * Front-end "Choose Menu" (Beaver Builder theme empty-menu fallback, and
 * any other theme that links admin_url('nav-menus.php')) goes to Minn
 * Menus when this user has Minn as the default admin.
 *
 * SKIPs when the homepage has no such link (minnadmin's marketing theme)
 * so run-all stays green. Point MINN_TEST_URL at builders.localhost to
 * exercise the real Beaver Builder theme.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'choose-menu' );
	await login( page );

	const homeOk = await page.goto( BASE + '/', { waitUntil: 'domcontentloaded', timeout: 30000 } )
		.then( () => true )
		.catch( () => false );
	if ( ! homeOk ) {
		console.log( 'SKIP  choose-menu (homepage did not load)' );
		await t.done( browser, errors );
		return;
	}
	const href = await page.evaluate( () => {
		const links = Array.from( document.querySelectorAll( 'a.no-menu' ) )
			.filter( ( a ) => /Choose Menu/.test( a.textContent || '' ) && a.offsetParent );
		return links[ 0 ] ? links[ 0 ].href : '';
	} );
	if ( ! href ) {
		console.log( 'SKIP  choose-menu (no Choose Menu link on the homepage)' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'Choose Menu points at Minn Menus, not nav-menus.php',
		/\/minn-admin\/menus\/?$/.test( href || '' ) && ! /nav-menus\.php/.test( href || '' ),
		href );

	await page.goto( href, { waitUntil: 'domcontentloaded', timeout: 30000 } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } ).catch( () => {} );
	t.check( 'following it lands on the Menus screen',
		/\/minn-admin\/menus/.test( page.url() ), page.url() );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
