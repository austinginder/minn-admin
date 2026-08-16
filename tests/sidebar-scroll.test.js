/**
 * Sidebar scroll on short viewports — the nav region (.minn-nav-scroll)
 * scrolls while logo, search and the user area stay pinned. Before the fix,
 * short windows simply clipped the lower nav items with no way to reach them.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'sidebar-scroll' );

	await login( page );
	await page.setViewportSize( { width: 1280, height: 480 } );
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-nav-scroll', { timeout: 15000 } );

	const m = await page.evaluate( () => {
		const nav = document.querySelector( '.minn-nav-scroll' );
		const user = document.querySelector( '.minn-user' );
		const navRect = nav.getBoundingClientRect();
		const userRect = user.getBoundingClientRect();
		nav.scrollTop = 9999;
		return {
			overflows: nav.scrollHeight > nav.clientHeight,
			scrolled: nav.scrollTop > 0,
			userVisible: userRect.top >= navRect.bottom - 1 && userRect.bottom <= window.innerHeight + 1,
		};
	} );
	t.check( 'Nav region overflows on a short viewport', m.overflows );
	t.check( 'Nav region actually scrolls', m.scrolled );
	t.check( 'User area stays pinned below the scroller', m.userVisible );

	// Scrolled to the bottom, the last nav item is reachable and clickable.
	const lastReachable = await page.evaluate( () => {
		const nav = document.querySelector( '.minn-nav-scroll' );
		const btns = nav.querySelectorAll( '.minn-nav-btn' );
		const last = btns[ btns.length - 1 ];
		const r = last.getBoundingClientRect();
		const navR = nav.getBoundingClientRect();
		return r.bottom <= navR.bottom + 1 && r.top >= navR.top - 1 && ! last.disabled;
	} );
	t.check( 'Last nav item reachable after scrolling', lastReachable );

	// Tall enough for the nav it actually has: no scrollbar needed. The
	// height is DERIVED, never a constant — this site grows a nav item per
	// installed surface (26 today), so a fixed 1100px stopped being "tall"
	// and the check went red while the scrolling it guards worked fine.
	const need = await page.evaluate( () => {
		const nav = document.querySelector( '.minn-nav-scroll' );
		return Math.max( 0, nav.scrollHeight - nav.clientHeight );
	} );
	await page.setViewportSize( { width: 1280, height: 1100 + need + 40 } );
	await page.waitForTimeout( 300 );
	const tall = await page.evaluate( () => {
		const nav = document.querySelector( '.minn-nav-scroll' );
		return nav.scrollHeight <= nav.clientHeight + 1;
	} );
	t.check( 'A viewport tall enough for the whole nav needs no scrolling', tall );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
