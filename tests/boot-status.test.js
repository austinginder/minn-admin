/**
 * Consolidated /boot-status — the startup burst (notifications, plugin
 * caches, core status, comment badge, types, order summary) rides ONE
 * request instead of ~9, each section produced internally by the same route
 * the standalone load used. The standalone routes remain for refreshes and
 * as the per-section fallback when boot-status is absent or fails.
 *
 * Pass 1 boots normally: exactly one boot-status request, none of the
 * consolidated standalone endpoints fetched, badges consistent with
 * independently-fetched standalone data. Pass 2 force-fails boot-status and
 * proves the fallback restores the old per-endpoint boot.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'boot-status' );

	const reqs = [];
	page.on( 'request', ( r ) => {
		const u = r.url();
		if ( u.includes( '/wp-json/' ) ) reqs.push( u );
	} );
	const hits = ( frag ) => reqs.filter( ( u ) => u.includes( frag ) ).length;

	await login( page );
	// Login lands inside the app on this site (Minn is the login redirect).
	// Let THAT instance's boot fully settle before navigating: a goto that
	// unloads a page mid-boot aborts its in-flight boot-status fetch, and
	// the dying instance's fallback burst would pollute the request count.
	await page.waitForTimeout( 4500 );
	reqs.length = 0;
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
	// Let the boot settle (boot-status + content warm + overview loads).
	await page.waitForTimeout( 3500 );

	t.check( 'Exactly one boot-status request', hits( 'minn-admin/v1/boot-status' ) === 1 );
	t.check( 'No standalone types fetch', hits( 'wp/v2/types' ) === 0 );
	t.check( 'No standalone plugins fetch', hits( 'wp/v2/plugins' ) === 0 );
	t.check( 'No standalone plugin-updates fetch', hits( 'plugin-updates' ) === 0 );
	t.check( 'No standalone plugin-meta fetch', hits( 'plugin-meta' ) === 0 );
	t.check( 'No standalone core-status fetch', hits( 'minn-admin/v1/core' ) === 0 );
	t.check( 'No standalone order-summary fetches', hits( 'wc/v3/reports/sales' ) + hits( 'wc/v3/orders' ) === 0 );
	t.check( 'No standalone hold-comments fetch', hits( 'comments?status=hold' ) === 0 );
	// The stale-capture chain may legitimately refresh notifications once.
	t.check( 'At most one notifications refresh (capture chain)', hits( 'minn-admin/v1/notifications' ) <= 1, `saw ${ hits( 'minn-admin/v1/notifications' ) }` );

	// Badges seeded from boot-status agree with the standalone routes,
	// fetched independently here (live fixtures drift; never assert
	// absolute counts).
	const agree = await page.evaluate( async () => {
		const j = ( p ) => fetch( window.MINN.restUrl + p, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ).then( ( r ) => r.json() );
		const upd = await j( 'minn-admin/v1/plugin-updates' );
		const pending = Object.keys( ( upd && upd.updates ) || {} ).length + Object.keys( ( upd && upd.themes ) || {} ).length;
		const dot = document.querySelector( '#minn-plugin-dot' );
		const ordersBadge = document.querySelector( '#minn-orders-count' );
		return {
			dotConsistent: ! dot || dot.hidden === ( pending === 0 ),
			ordersNumeric: ! ordersBadge || ordersBadge.hidden || /^\d+$/.test( ordersBadge.textContent.trim() ),
		};
	} );
	t.check( 'Plugin dot agrees with standalone plugin-updates', agree.dotConsistent );
	t.check( 'Orders badge is numeric when shown', agree.ordersNumeric );

	// Types seeded: the content view renders its type control without a
	// wp/v2/types fetch (typesPromise resolves from the seed).
	reqs.length = 0;
	await page.click( '.minn-nav-btn[data-nav="content"]' );
	await page.waitForSelector( '[data-typecombo], .minn-tab, .minn-empty', { timeout: 20000 } );
	t.check( 'Content type control renders with zero types fetches', hits( 'wp/v2/types' ) === 0 );

	// Pass 2: boot-status down → per-section fallback is the old boot.
	await page.route( /minn-admin\/v1\/boot-status/, ( route ) => route.fulfill( { status: 500, contentType: 'application/json', body: '{"error":true}' } ) );
	reqs.length = 0;
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
	await page.waitForTimeout( 3500 );

	t.check( 'Fallback: standalone notifications fetch fires', hits( 'minn-admin/v1/notifications' ) >= 1 );
	t.check( 'Fallback: standalone types fetch fires', hits( 'wp/v2/types' ) >= 1 );
	t.check( 'Fallback: standalone plugins fetch fires', hits( 'wp/v2/plugins' ) >= 1 );
	t.check( 'Fallback: order summary fetches fire', hits( 'wc/v3/reports/sales' ) >= 1 && hits( 'wc/v3/orders' ) >= 1 );
	t.check( 'Fallback: core status fetch fires', hits( 'minn-admin/v1/core' ) >= 1 );

	const fb = await page.evaluate( () => {
		const ordersBadge = document.querySelector( '#minn-orders-count' );
		return {
			shell: !! document.querySelector( '.minn-nav-btn' ),
			ordersNumeric: ! ordersBadge || ordersBadge.hidden || /^\d+$/.test( ordersBadge.textContent.trim() ),
		};
	} );
	t.check( 'Fallback boot still renders the app shell + badges', fb.shell && fb.ordersNumeric );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
