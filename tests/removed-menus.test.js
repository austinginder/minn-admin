/**
 * remove_menu_page() awareness: a developer hiding a wp-admin menu (the
 * classic agency snippet on admin_menu) is mirrored in Minn's nav. The
 * notices-capture pageload sees the final admin menu and reports watched
 * removals; the nav filters them. Cosmetic like the original — the route
 * stays reachable by URL. Fixture: minn_test_remove_menus (mu-plugin)
 * remove_menu_page()s the listed slugs at admin_menu 999.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

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
	} finally {
		await setOpt( '' ).catch( () => {} );
		await capture().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
