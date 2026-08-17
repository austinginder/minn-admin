/**
 * Add plugin catalog — curated category cards replace flat search chips.
 * Proves: catalog grid renders on open, chips reflect installed state,
 * "Browse more" runs a directory search, ← Catalog returns, install-url
 * allowlists hosts, resolves Disembark's GitHub release, and gives paid ZIP
 * extensions an explicit upload path without calling the wp.org installer.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'plugin-catalog' );
	const { browser, page, errors } = await launch();
	// Hide the locally installed WooCommerce Subscriptions fixture from the
	// plugin-list response so the suite can exercise its fresh-site paid ZIP
	// message without deactivating a real extension. Arm this before login,
	// since login reuses the Minn page and warms its plugin cache.
	await page.route( '**/wp-json/minn-admin/v1/boot-status**', async ( route ) => {
		const response = await route.fetch();
		const json = await response.json();
		if ( Array.isArray( json.plugins ) ) {
			json.plugins = json.plugins.filter( ( p ) =>
				! String( p.plugin || '' ).startsWith( 'woocommerce-subscriptions/' ) );
		}
		await route.fulfill( { response, json } );
	} );
	let wpPluginPosts = 0;
	await page.route( '**/wp-json/wp/v2/plugins**', async ( route ) => {
		if ( route.request().method() !== 'GET' ) {
			wpPluginPosts++;
		}
		await route.continue();
	} );
	await login( page );

	try {
		await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-add-plugin', { timeout: 20000 } );
		await page.click( '#minn-add-plugin' );
		await page.waitForSelector( '.minn-pi-catalog', { timeout: 15000 } );

		const cards = await page.$$eval( '.minn-pi-card-title', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'catalog has multiple category cards', cards.length >= 8, cards.join( '|' ) );
		t.check( 'SEO card present', cards.includes( 'SEO' ) );
		t.check( 'Backup card present', cards.includes( 'Backup' ) );
		t.check( 'Performance card present', cards.includes( 'Performance' ) );
		t.check( 'Bookings card present', cards.includes( 'Bookings' ) );
		t.check( 'Site visibility card present', cards.includes( 'Site visibility' ) );

		const catalogSlugs = await page.$$eval( '.minn-pi-chip', ( els ) =>
			els.map( ( e ) => e.getAttribute( 'data-slug' ) ).filter( Boolean ) );
		t.check( 'Bookings lists all three wired providers', [
			'ameliabooking', 'latepoint', 'bookly-responsive-appointment-booking-tool',
		].every( ( slug ) => catalogSlugs.includes( slug ) ) );
		t.check( 'Site visibility lists its wired providers', [
			'maintenance', 'coming-soon', 'under-construction-page', 'wp-maintenance-mode',
			'cmp-coming-soon-maintenance', 'minimal-coming-soon-maintenance-mode', 'password-protected',
		].every( ( slug ) => catalogSlugs.includes( slug ) ) );

		const wcs = await page.$eval( '.minn-pi-chip[data-slug="woocommerce-subscriptions"]', ( btn ) => ( {
			badge: btn.querySelector( '.minn-pi-chip-badge' )?.textContent.trim() || '',
			fallback: btn.getAttribute( 'data-fallback-title' ) || '',
			disabled: btn.disabled,
		} ) );
		t.check( 'WooCommerce Subscriptions is marked as a ZIP upload',
			wcs.badge === 'ZIP' && /paid extension/i.test( wcs.fallback ) && ! wcs.disabled,
			JSON.stringify( wcs ) );
		await page.click( '.minn-pi-chip[data-slug="woocommerce-subscriptions"]' );
		await page.waitForSelector( '.minn-toast-action', { timeout: 5000 } );
		const wcsMessage = await page.$eval( '.minn-toast-action', ( el ) => el.textContent.trim() );
		t.check( 'WooCommerce Subscriptions explains the paid install path',
			/paid extension/i.test( wcsMessage ) && /WooCommerce\.com/i.test( wcsMessage ) && /upload/i.test( wcsMessage ),
			wcsMessage );
		t.check( 'paid ZIP chip does not call the plugin installer', wpPluginPosts === 0, String( wpPluginPosts ) );

		const disembark = await page.$$eval( '.minn-pi-chip', ( els ) => {
			const btn = els.find( ( e ) => /Disembark/i.test( e.textContent ) );
			if ( ! btn ) return null;
			return {
				text: btn.textContent.trim(),
				badge: !! btn.querySelector( '.minn-pi-chip-badge' ),
				slug: btn.getAttribute( 'data-slug' ) || '',
				fallback: btn.getAttribute( 'data-fallback-title' ) || '',
			};
		} );
		t.check( 'Disembark chip present', !! disembark );
		t.check( 'Disembark carries GitHub badge', !!( disembark && disembark.badge ) );
		t.check( 'Disembark is catalogued by slug', !!( disembark && disembark.slug === 'disembark' ) );

		// An active plugin chip (minn-admin is always active) is not required
		// in the catalog; Yoast may or may not be. Just assert chip states exist.
		const chipClasses = await page.$$eval( '.minn-pi-chip', ( els ) =>
			els.slice( 0, 20 ).map( ( e ) => e.className ) );
		t.check( 'chips render', chipClasses.length >= 10, String( chipClasses.length ) );

		// Browse more → directory search.
		const more = await page.$( '[data-pi-more="seo"]' );
		t.check( 'SEO has browse-more control', !! more );
		await more.click();
		await page.waitForFunction( () => {
			const el = document.querySelector( '#minn-pi-search' );
			return el && /SEO/i.test( el.value );
		}, { timeout: 10000 } );
		await page.waitForSelector( '.minn-pi-row, .minn-loading, .minn-empty', { timeout: 20000 } );
		// Wait for search to settle.
		for ( let i = 0; i < 20; i++ ) {
			const searching = await page.$( '.minn-loading' );
			if ( ! searching ) break;
			await page.waitForTimeout( 300 );
		}
		const hasRows = ( await page.$$( '.minn-pi-row' ) ).length > 0
			|| !!( await page.$( '.minn-empty' ) );
		t.check( 'browse-more runs a directory search', hasRows );

		// Back to catalog.
		await page.click( '#minn-pi-back' );
		await page.waitForSelector( '.minn-pi-catalog', { timeout: 10000 } );
		t.check( '← Catalog restores the grid', !!( await page.$( '.minn-pi-catalog' ) ) );

		// Server allowlist (no full GitHub install here — that can recycle the
		// worker and drop the socket; the suite only needs the host gate).
		const api = await page.evaluate( async () => {
			try {
				const bad = await fetch( window.MINN.restUrl + 'minn-admin/v1/plugins/install-url', {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					body: JSON.stringify( { url: 'https://evil.example/plugin.zip' } ),
				} );
				const badBody = await bad.json().catch( () => ( {} ) );
				return { badStatus: bad.status, badCode: badBody.code || null, err: null };
			} catch ( e ) {
				return { badStatus: 0, badCode: null, err: String( e.message || e ) };
			}
		} );
		t.check( 'evil host rejected', api.badStatus === 400 && api.badCode === 'host_not_allowed',
			( api.err || ( api.badStatus + ' ' + api.badCode ) ) );

		// Hover tip: info endpoint + tip appears after hover.
		const info = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/plugins/info?slug=wordpress-seo', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'plugin info 200', info.status === 200 );
		t.check( 'plugin info has author + description',
			!!( info.body.author && info.body.description ),
			( info.body.author || '' ) + ' / ' + ( info.body.description || '' ).slice( 0, 40 ) );
		t.check( 'plugin info has icon or installs',
			!!( info.body.icon || info.body.installs > 0 ) );

		const wcsInfo = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/plugins/info?slug=woocommerce-subscriptions', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'paid ZIP plugin info is local and explicit',
			wcsInfo.status === 200 && wcsInfo.body.source === 'upload' && /paid extension/i.test( wcsInfo.body.description || '' ) );

		const seoSlugs = await page.$$eval( '.minn-pi-chip', ( els ) =>
			els.map( ( e ) => e.getAttribute( 'data-slug' ) ).filter( Boolean ) );
		t.check( 'SEO catalog includes Squirrly', seoSlugs.includes( 'squirrly-seo' ) );
		t.check( 'SEO catalog includes SureRank', seoSlugs.includes( 'surerank' ) );

		const yoastChip = await page.$( '.minn-pi-chip[data-slug="wordpress-seo"]' );
		if ( yoastChip ) {
			await yoastChip.hover();
			// Wait past the 280ms open delay and the plugins_api round-trip.
			await page.waitForSelector( '#minn-pi-tip .minn-pi-tip-name', { timeout: 15000 } );
			const tip = await page.$eval( '#minn-pi-tip', ( el ) => ( {
				hasName: !! el.querySelector( '.minn-pi-tip-name' ),
				hasDesc: !! el.querySelector( '.minn-pi-tip-desc' ),
				hasIcon: !! el.querySelector( '.minn-pi-tip-icon' ),
				text: el.textContent.trim().slice( 0, 100 ),
			} ) );
			t.check( 'hover tip shows name', tip.hasName, tip.text );
			t.check( 'hover tip shows description or meta', tip.hasDesc || /install|Yoast/i.test( tip.text ) );
			t.check( 'hover tip shows icon tile', tip.hasIcon );
		} else {
			t.check( 'hover tip shows name', false, 'wordpress-seo chip missing' );
		}

		const disInfo = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/plugins/info?slug=disembark', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.json();
		} );
		t.check( 'Disembark tip is local/github', disInfo.source === 'github' && /backup/i.test( disInfo.description || '' ) );
	} finally {
		// close modal if open
		await page.keyboard.press( 'Escape' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
