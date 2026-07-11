/**
 * Site visibility posture (adapters/site-status.php). Warns when the site is
 * hidden from the public — a maintenance/coming-soon plugin, a whole-site
 * password gate, or "discourage search engines" left on. Surfaced three ways:
 * an Overview banner, a System health check, and a persistent amber topbar
 * chip on every route (it should nag until fixed).
 *
 * Maintenance/coming-soon/password states are armed through the
 * minn_admin_visibility_providers fixture (so no real plugin mode is left
 * enabled); search-discouraged uses the real blog_public option. Both are
 * reset in the finally. B.visibility is a boot payload, so each state change
 * reloads the page.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'site-visibility' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Write-then-verify with retries (REST settings writes race the app's
	// parallel boot requests — the site-kit-suite rule).
	const setOpt = async ( key, val ) => {
		for ( let i = 0; i < 5; i++ ) {
			const stored = await page.evaluate( async ( [ k, v ] ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify( { [ k ]: v } ) } );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
				return ( await r.json() )[ k ];
			}, [ key, val ] );
			if ( String( stored ) === String( val ) ) return true;
			await page.waitForTimeout( 600 );
		}
		return false;
	};

	const stateAt = async ( route ) => {
		await page.goto( BASE + '/minn-admin/' + route, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && document.querySelector( '#minn-vis-chip' ), null, { timeout: 20000 } );
		// The banner lives in the Overview view — wait for it to finish loading
		// (the chip is in the always-present topbar, so it isn't enough).
		if ( 'overview' === route ) await page.waitForSelector( '.minn-stats', { timeout: 20000 } );
		return page.evaluate( () => {
			const chip = document.querySelector( '#minn-vis-chip' );
			const banner = document.querySelector( '.minn-vis-banner' );
			return {
				chipHidden: chip.hidden,
				chipText: chip.textContent.trim(),
				bannerTitle: banner ? banner.querySelector( '.minn-panel-title' ).textContent.trim() : null,
				bannerAmber: banner ? banner.classList.contains( 'block' ) : null,
			};
		} );
	};

	try {
		/* ===== Maintenance (fixture) — hidden ===== */
		t.check( 'maintenance fixture armed', await setOpt( 'minn_test_visibility', 'maintenance' ) );
		let s = await stateAt( 'media' ); // a NON-overview route: chip must nag everywhere
		t.check( 'topbar chip shows on a non-overview route', ! s.chipHidden && /hidden/i.test( s.chipText ), JSON.stringify( s ) );
		s = await stateAt( 'overview' );
		t.check( 'Overview banner warns the site is hidden', /hidden from the public/i.test( s.bannerTitle || '' ), JSON.stringify( s ) );
		t.check( 'hidden banner uses the amber treatment', s.bannerAmber === true );

		// System health check goes to warn.
		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sys-check', { timeout: 20000 } );
		const check = await page.$$eval( '.minn-sys-check', ( els ) => {
			const el = els.find( ( e ) => /Site visibility/.test( e.textContent ) );
			return el ? { cls: el.className, text: el.textContent } : null;
		} );
		t.check( 'System health check flags visibility (warn)', check && /warn/.test( check.cls ) && /hidden/i.test( check.text ), JSON.stringify( check ) );

		/* ===== Password (fixture) ===== */
		await setOpt( 'minn_test_visibility', 'password' );
		s = await stateAt( 'overview' );
		t.check( 'password state warns + chip reads "Password gated"', /password-protected/i.test( s.bannerTitle || '' ), JSON.stringify( s ) );

		/* ===== Search-discouraged (real blog_public) ===== */
		await setOpt( 'minn_test_visibility', '' );
		t.check( 'blog_public set to discouraged', await setOpt( 'blog_public', 0 ) );
		s = await stateAt( 'overview' );
		t.check( 'search-discouraged banner shows (amber)', /search engines are discouraged/i.test( s.bannerTitle || '' ) && s.bannerAmber === true, JSON.stringify( s ) );
		t.check( 'chip reads "Not indexed"', ! s.chipHidden && /not indexed/i.test( s.chipText ), JSON.stringify( s ) );

		/* ===== Fully public — nothing shows ===== */
		await setOpt( 'blog_public', 1 );
		s = await stateAt( 'overview' );
		t.check( 'public: no banner', s.bannerTitle === null, JSON.stringify( s ) );
		t.check( 'public: no chip', s.chipHidden === true, JSON.stringify( s ) );

	} finally {
		await setOpt( 'minn_test_visibility', '' ).catch( () => {} );
		await setOpt( 'blog_public', 1 ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
