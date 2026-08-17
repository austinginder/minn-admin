/**
 * Add plugin catalog — curated category cards replace flat search chips.
 * Proves: catalog grid renders on open, chips reflect installed state,
 * "Browse more" runs a directory search, ← Catalog returns, install-url
 * allowlists hosts, resolves Disembark's GitHub release, and gives paid ZIP
 * extensions an explicit upload path without calling the wp.org installer.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

const PAID_UPLOADS = [
	{ slug: 'gravityforms', vendor: 'Gravity Forms' },
	{ slug: 'wpforms', vendor: 'WPForms' },
	{ slug: 'elementor-pro', vendor: 'Elementor' },
	{ slug: 'woocommerce-subscriptions', vendor: 'WooCommerce.com' },
	{ slug: 'analyticswp', vendor: 'AnalyticsWP' },
	{ slug: 'wp-rocket', vendor: 'WP Rocket' },
	{ slug: 'perfmatters', vendor: 'Perfmatters' },
	{ slug: 'gravitysmtp', vendor: 'Gravity SMTP' },
	{ slug: 'advanced-custom-fields-pro', vendor: 'ACF' },
];

( async () => {
	const t = reporter( 'plugin-catalog' );
	const { browser, page, errors } = await launch();
	// Hide the locally installed paid fixtures from the plugin-list response
	// so the suite can exercise their fresh-site ZIP messages without
	// deactivating real extensions. Arm this before login, since login reuses
	// the Minn page and warms its plugin cache.
	await page.route( '**/wp-json/minn-admin/v1/boot-status**', async ( route ) => {
		const response = await route.fetch();
		const json = await response.json();
		const hidden = new Set( PAID_UPLOADS.map( ( p ) => p.slug ) );
		if ( Array.isArray( json.plugins ) ) {
			json.plugins = json.plugins.filter( ( p ) =>
				! hidden.has( String( p.plugin || '' ).split( '/' )[ 0 ] ) );
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
		t.check( 'Analytics lists Plausible', catalogSlugs.includes( 'plausible-analytics' ) );
		const plausibleChip = await page.$eval( '.minn-pi-chip[data-slug="plausible-analytics"]', ( button ) => ( {
			text: button.textContent.trim(),
			installed: button.classList.contains( 'is-installed' ),
			disabled: button.disabled,
		} ) );
		t.check( 'Installed Plausible is ready to activate',
			plausibleChip.text.includes( 'Plausible Analytics' ) && plausibleChip.installed && ! plausibleChip.disabled,
			JSON.stringify( plausibleChip ) );

		const paidChips = await page.$$eval( '.minn-pi-chip[data-slug]', ( buttons, slugs ) =>
			slugs.map( ( slug ) => {
				const btn = buttons.find( ( item ) => item.dataset.slug === slug );
				return btn ? {
					slug,
					badge: btn.querySelector( '.minn-pi-chip-badge' )?.textContent.trim() || '',
					fallback: btn.getAttribute( 'data-fallback-title' ) || '',
					disabled: btn.disabled,
				} : null;
			} ), PAID_UPLOADS.map( ( p ) => p.slug ) );
		t.check( 'well-covered paid plugins are present', paidChips.every( Boolean ), JSON.stringify( paidChips ) );
		t.check( 'paid plugins are marked as fresh-site ZIP uploads', paidChips.every( ( item ) =>
			item && item.badge === 'ZIP' && /\.zip/i.test( item.fallback ) && ! item.disabled ), JSON.stringify( paidChips ) );
		for ( const paid of PAID_UPLOADS ) {
			await page.click( `.minn-pi-chip[data-slug="${ paid.slug }"]` );
			await page.waitForSelector( '.minn-toast-action', { timeout: 5000 } );
			const notice = await page.$eval( '.minn-toast-action', ( el ) => ( {
				message: el.querySelector( '.minn-toast-msg' )?.textContent.trim() || '',
				action: el.querySelector( '.minn-toast-btn' )?.textContent.trim() || '',
			} ) );
			t.check( `${ paid.slug } explains its vendor ZIP path`,
				/WordPress\.org/i.test( notice.message ) && /upload/i.test( notice.message )
					&& notice.action.includes( paid.vendor ), JSON.stringify( notice ) );
		}
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

		const paidInfo = await page.evaluate( async ( slugs ) => Promise.all( slugs.map( async ( slug ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/plugins/info?slug=' + encodeURIComponent( slug ), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { slug, status: r.status, body: await r.json() };
		} ) ), PAID_UPLOADS.map( ( p ) => p.slug ) );
		t.check( 'paid ZIP plugin info is local and explicit', paidInfo.every( ( item ) =>
			item.status === 200 && item.body.source === 'upload' && !! item.body.description ), JSON.stringify( paidInfo ) );

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
