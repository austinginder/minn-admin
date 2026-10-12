/**
 * remove_menu_page() awareness: a developer hiding a wp-admin menu (the
 * classic agency snippet on admin_menu) is mirrored in Minn's nav. The
 * notices-capture pageload sees the final admin menu and reports watched
 * removals; the nav filters them. Cosmetic like the original — the route
 * stays reachable by URL. Fixture: minn_test_remove_menus (mu-plugin)
 * remove_menu_page()s the listed slugs at admin_menu 999.
 *
 * A plugin's whole menu hidden the same way (the White Label CMS case: an
 * agency hides a plugin from clients) takes that plugin's surfaces out of
 * Minn for the same user, and brings them back when the menu returns. The
 * fixture vouches for its removals through minn_admin_admin_page_hidden,
 * as a menu tool's adapter does; white-label-cms.test.js drives the real
 * plugin on the builders lab.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

// The app re-captures only when its last capture is stale; dropping the
// stored capture makes the next boot run one, which is the live path.
const forgetCapture = () => execSync(
	`wp --path=${ JSON.stringify( WP ) } transient delete minn_admin_notices_v5_1`,
	{ stdio: 'ignore', timeout: 60000 }
);

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'removed-menus' );
	await login( page );

	// Write-verify-retry for REST-exposed fixture options (rule-47c class).
	const setOpt = async ( value ) => {
		for ( let i = 0; i < 5; i++ ) {
			await page.evaluate( async ( v ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'include',
					body: JSON.stringify( { minn_test_remove_menus: v } ),
				} );
			}, value );
			const read = await page.evaluate( async () => {
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Date.now(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
				} );
				return ( await r.json() ).minn_test_remove_menus;
			} );
			if ( read === value ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};
	const capture = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.notices.url, { credentials: 'same-origin' } );
		return r.json();
	} );

	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-sidebar', { timeout: 20000 } );

	try {
		t.check( 'armed fixture (upload.php removed)', await setOpt( 'edit-comments.php,upload.php' ), '' );

		// The capture pageload sees the removal and reports it.
		const cap = await capture();
		t.check( 'capture reports media removed', cap && cap.ok && Array.isArray( cap.menu_removed ) && cap.menu_removed.includes( 'media' ), JSON.stringify( cap && cap.menu_removed ) );

		// A fresh boot reads the stored capture: Media leaves the nav.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sidebar', { timeout: 20000 } );
		const shape = await page.evaluate( () => ( {
			menuRemoved: window.MINN.menuRemoved || [],
			mediaBtn: !! document.querySelector( '.minn-nav-btn[data-nav="media"]' ),
			contentBtn: !! document.querySelector( '.minn-nav-btn[data-nav="content"]' ),
		} ) );
		t.check( 'boot payload carries the removal', shape.menuRemoved.includes( 'media' ), JSON.stringify( shape.menuRemoved ) );
		t.check( 'Media gone from nav, Content untouched', ! shape.mediaBtn && shape.contentBtn, JSON.stringify( shape ) );

		// Cosmetic, like remove_menu_page itself: the route stays reachable.
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-media-grid, .minn-media-item, [data-view="media"], #minn-view', { timeout: 20000 } );
		const mediaWorks = await page.evaluate( () => {
			const v = document.querySelector( '#minn-view' );
			return !! v && ! /don.t have permission/i.test( v.textContent || '' ) && /media|upload|drop/i.test( v.textContent || '' );
		} );
		t.check( 'media route still reachable by URL', mediaWorks, '' );

		// Disarm → recapture → nav returns on next boot.
		t.check( 'disarmed fixture', await setOpt( '' ), '' );
		const cap2 = await capture();
		// Assert on media only: the resident Disable Comments plugin REALLY
		// removes the Comments menu on this site, so 'comments' legitimately
		// stays in the list — live proof the detection works on real plugins.
		t.check( 'capture drops media after restore', cap2 && cap2.ok && Array.isArray( cap2.menu_removed ) && ! cap2.menu_removed.includes( 'media' ), JSON.stringify( cap2 && cap2.menu_removed ) );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn[data-nav="media"]', { timeout: 20000 } );
		t.check( 'Media back in nav after restore', true, '' );

		// A plugin's whole menu removed: Gravity Forms' only top-level page.
		const surfaceIds = () => page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/surfaces?_cb=' + Date.now(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
			} );
			return ( await r.json() ).map( ( s ) => s.id );
		} );
		t.check( 'Gravity Forms is a surface before', ( await surfaceIds() ).includes( 'gravity-forms' ), '' );
		t.check( 'armed fixture (Gravity Forms menu removed)', await setOpt( 'gf_edit_forms' ), '' );
		const cap3 = await capture();
		t.check( 'capture says the surfaces changed', cap3 && cap3.ok && true === cap3.surfaces_changed, JSON.stringify( cap3 && cap3.surfaces_changed ) );
		const after = await surfaceIds();
		t.check( 'Gravity Forms surface gone, other forms kept', ! after.includes( 'gravity-forms' ) && after.includes( 'wpforms' ), JSON.stringify( after ) );
		const cap4 = await capture();
		t.check( 'an unchanged capture says nothing changed', cap4 && cap4.ok && false === cap4.surfaces_changed, JSON.stringify( cap4 && cap4.surfaces_changed ) );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sidebar', { timeout: 20000 } );
		const bootIds = await page.evaluate( () => ( window.MINN.surfaces || [] ).map( ( s ) => s.id ) );
		t.check( 'boot payload leaves it out', ! bootIds.includes( 'gravity-forms' ) && bootIds.length > 0, JSON.stringify( bootIds ) );

		// Live: the menu comes back, the app's own stale capture notices and
		// re-reads its surfaces without a reload.
		t.check( 'disarmed fixture (Gravity Forms back)', await setOpt( '' ), '' );
		forgetCapture();
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sidebar', { timeout: 20000 } );
		const back = await page.waitForFunction( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/surfaces?_cb=' + Date.now(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'include',
			} );
			return ( await r.json() ).some( ( s ) => s.id === 'gravity-forms' );
		}, null, { timeout: 30000, polling: 1000 } ).then( () => true, () => false );
		t.check( 'the app\'s own capture brings Gravity Forms back', back, '' );
	} finally {
		await setOpt( '' ).catch( () => {} );
		await capture().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
