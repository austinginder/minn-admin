/**
 * Interactive + live-updating site-visibility controls (Austin's report):
 *  - toggling maintenance mode in Settings updated the banner/chip only after
 *    a page reload — now it refreshes live,
 *  - the chip/banner just dumped the user to Settings — now the chip opens a
 *    popover with a real toggle, and the banner carries inline controls.
 *
 * Drives the real UI: the Settings maintenance toggle + Save, the chip
 * popover toggle, and the Overview banner toggle; asserts the chip and server
 * state track each change without a reload. Resets maintenance + blog_public
 * in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'visibility-interactive' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = ( k, v ) => page.evaluate( async ( [ key, val ] ) => {
		const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
		await fetch( window.MINN.restUrl + 'wp/v2/settings', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify( { [ key ]: val } ) } );
	}, [ k, v ] );
	const serverOpt = ( k ) => page.evaluate( async ( key ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return ( await r.json() )[ key ];
	}, k );
	const chipHidden = () => page.$eval( '#minn-vis-chip', ( c ) => c.hidden );

	try {
		// Start public.
		await setOpt( 'minn_admin_maintenance', false );
		await setOpt( 'blog_public', 1 );

		/* ===== Settings toggle updates the chip live (no reload) ===== */
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-setting="minn_admin_maintenance"]', { timeout: 20000 } );
		t.check( 'chip hidden while public', await chipHidden() === true );
		await page.click( '[data-setting="minn_admin_maintenance"]' );
		await page.click( '#minn-save-settings' );
		// The chip must appear WITHOUT navigating away.
		await page.waitForFunction( () => ! document.querySelector( '#minn-vis-chip' ).hidden, null, { timeout: 8000 } );
		t.check( 'chip appears live after the Settings save', await chipHidden() === false );

		/* ===== Chip popover has a real toggle, flips it off live ===== */
		await page.click( '#minn-vis-chip' );
		await page.waitForSelector( '#minn-vis-pop [data-vistoggle]', { timeout: 5000 } );
		t.check( 'popover shows a Maintenance mode switch (on)', await page.$eval( '#minn-vis-pop [data-vistoggle]', ( b ) => b.classList.contains( 'on' ) ) );
		t.check( 'popover names the state', /hidden from the public/i.test( await page.$eval( '.minn-vis-pop-title', ( e ) => e.textContent ) ) );
		await page.click( '#minn-vis-pop [data-vistoggle]' );
		await page.waitForFunction( () => document.querySelector( '#minn-vis-chip' ).hidden, null, { timeout: 8000 } );
		t.check( 'toggling off in the popover hides the chip live', await chipHidden() === true );
		t.check( 'popover closes when the site is public', ( await page.$( '#minn-vis-pop' ) ) === null );
		t.check( 'server maintenance is actually off', ! ( await serverOpt( 'minn_admin_maintenance' ) ) );

		/* ===== Overview banner carries an inline toggle ===== */
		await setOpt( 'blog_public', 0 ); // search-discouraged
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stats', { timeout: 20000 } );
		t.check( 'banner shows for search-discouraged', ( await page.$( '.minn-vis-banner' ) ) !== null );
		const bannerToggle = await page.$( '.minn-vis-banner [data-vistoggle]' );
		t.check( 'banner carries an inline toggle (not just a link)', bannerToggle !== null );
		// Flip search-engine visibility back on from the banner.
		await bannerToggle.click();
		await page.waitForFunction( () => document.querySelector( '#minn-vis-chip' ).hidden && ! document.querySelector( '.minn-vis-banner' ), null, { timeout: 8000 } );
		t.check( 'banner + chip clear after enabling indexing from the banner', ( await page.$( '.minn-vis-banner' ) ) === null && await chipHidden() === true );
		t.check( 'blog_public is back on', !! ( await serverOpt( 'blog_public' ) ) );

	} finally {
		await setOpt( 'minn_admin_maintenance', false ).catch( () => {} );
		await setOpt( 'blog_public', 1 ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
