/**
 * Partial site visibility — the WooCommerce store-pages-only coming-soon
 * shape. A provider flagged `partial` must NOT claim the whole site is dark:
 * state becomes 'partial', the chip reads "Partly hidden", and the banner /
 * popover / Settings → Visibility all soften their copy while still naming
 * the provider. Armed via the mu-fixture provider (minn_test_visibility =
 * 'partial') so no real plugin mode is left enabled.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'visibility-partial' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = ( k, v ) => page.evaluate( async ( [ key, val ] ) => {
		const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
		await fetch( window.MINN.restUrl + 'wp/v2/settings', { method: 'POST', headers: h, credentials: 'same-origin', body: JSON.stringify( { [ key ]: val } ) } );
	}, [ k, v ] );
	const visibility = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/visibility?_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return r.json();
	} );

	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	try {
		// Baseline: nothing else hiding the site (rule: seed, don't assume).
		await setOpt( 'minn_admin_maintenance', false );
		await setOpt( 'blog_public', 1 );
		await setOpt( 'minn_test_visibility', 'partial' );

		const v = await visibility();
		t.check( 'server state is partial', v.state === 'partial', v.state );
		t.check( 'provider carries the partial flag', ( v.providers || [] ).some( ( p ) => p.partial ) );

		// Fresh boot picks the state up for chip + banner.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stats', { timeout: 20000 } );
		t.check( 'chip reads Partly hidden', await page.evaluate( () => {
			const c = document.querySelector( '#minn-vis-chip' );
			return c && ! c.hidden && /Partly hidden/.test( c.textContent );
		} ) );
		t.check( 'banner says part of the site is hidden', await page.evaluate( () => {
			const b = document.querySelector( '.minn-vis-banner' );
			return b && /Part of your site is hidden/.test( b.textContent );
		} ) );
		t.check( 'banner names the provider and its note', await page.evaluate( () => {
			const b = document.querySelector( '.minn-vis-banner' );
			return b && /Minn Visibility Fixture/.test( b.textContent ) && /only fixture pages are hidden/i.test( b.textContent );
		} ) );

		// Chip popover: softened title, provider link-out.
		await page.click( '#minn-vis-chip' );
		await page.waitForSelector( '#minn-vis-pop', { timeout: 5000 } );
		t.check( 'popover title softens to partial copy', await page.$eval( '.minn-vis-pop-title', ( e ) => /Part of the site is hidden/.test( e.textContent ) ) );
		t.check( 'popover links out to the provider', await page.evaluate( () =>
			Array.from( document.querySelectorAll( '#minn-vis-pop a' ) ).some( ( a ) => /Minn Visibility Fixture/.test( a.textContent ) ) ) );

		// Settings → Visibility lists third-party limiters with a link.
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );
		await page.evaluate( () => { [ ...document.querySelectorAll( '.minn-settings-nav-item' ) ].find( ( b ) => b.textContent.trim() === 'Visibility' ).click(); } );
		await page.waitForSelector( '[data-setting="minn_admin_maintenance"]', { timeout: 8000 } );
		t.check( 'Settings Visibility shows Also limiting visibility', await page.evaluate( () =>
			/Also limiting visibility/.test( document.querySelector( '.minn-settings-body' ).textContent ) ) );
		t.check( 'Settings row names the provider with an Open link', await page.evaluate( () => {
			const body = document.querySelector( '.minn-settings-body' );
			return /Minn Visibility Fixture/.test( body.textContent )
				&& Array.from( body.querySelectorAll( 'a' ) ).some( ( a ) => /Open/.test( a.textContent ) );
		} ) );

		// Disarm → public again.
		await setOpt( 'minn_test_visibility', '' );
		const after = await visibility();
		t.check( 'disarming returns the site to public', after.state === 'public', after.state );
	} finally {
		await setOpt( 'minn_test_visibility', '' ).catch( () => {} );
		await setOpt( 'blog_public', 1 ).catch( () => {} );
		await setOpt( 'minn_admin_maintenance', false ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
