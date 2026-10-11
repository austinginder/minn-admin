/**
 * The editor's crash-net drafts (localStorage minn-net-*) are cleared by a
 * real sign-out, not by any visit to wp-login.php?loggedout=true: any site
 * can send a person there, and the drafts are the edits they haven't saved.
 *
 * Runs on its own login (loginAs), so signing out ends that session only and
 * the suites' stored login stays valid.
 */
const { BASE, launch, loginAs, reporter } = require( './helpers' );

( async () => {
	const { browser, errors } = await launch();
	const t = reporter( 'localnet-logout' );
	const pass = process.env.MINN_TEST_PASS || '';
	const { ctx, page } = await loginAs( browser, 'admin', pass );
	try {
		const key = await page.evaluate( () => {
			const k = 'minn-net-probe-' + Date.now();
			localStorage.setItem( k, '{"draft":"unsaved words"}' );
			return k;
		} );
		const has = () => page.evaluate( ( k ) => null !== localStorage.getItem( k ), key );

		// A link from anywhere to the logged-out screen, without signing out.
		await page.goto( BASE + '/wp-login.php?loggedout=true', { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 500 );
		t.check( 'a visit to ?loggedout=true without signing out keeps the drafts', await has() );

		// A real sign-out the way wp-admin's does it: core's logout screen,
		// whose confirm link carries the nonce and lands on loggedout=true.
		await page.goto( BASE + '/wp-login.php?action=logout', { waitUntil: 'domcontentloaded' } );
		const confirm = await page.$( 'a[href*="action=logout"][href*="_wpnonce"]' );
		t.check( 'core offers its logout confirmation', !! confirm );
		if ( confirm ) {
			await Promise.all( [ page.waitForNavigation( { waitUntil: 'domcontentloaded' } ).catch( () => {} ), confirm.click() ] );
			await page.waitForTimeout( 800 );
			t.check( 'a real sign-out lands on the logged-out screen', /loggedout=true/.test( page.url() ), page.url() );
			t.check( 'a real sign-out clears the drafts', ! await has() );
		}
	} finally {
		await ctx.close().catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
