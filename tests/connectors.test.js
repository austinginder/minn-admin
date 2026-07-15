/**
 * Settings → Connectors (WP 7.0's connector registry in Minn).
 *
 * The section renders core's registry (minn-admin/v1/connectors display
 * model) and saves keys through core's OWN wp/v2/settings route, where core
 * masks every response and validates AI-provider keys against the provider
 * (a rejected key comes back reset to ''). The suite arms the mu-fixture
 * minn_test_connectors mock, so provider requests never leave the site:
 * the magic key 'minn-valid-key-2026' validates, everything else 401s.
 *
 * Fixtures: ai-provider-for-anthropic ACTIVE (its setting is registered, so
 * the Anthropic card carries the key field), akismet installed-inactive.
 * OpenAI / Google may be installed mid-session (Austin dogfoods Install &
 * activate) — the suite asserts a sane card state, not a fixed install pill.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'connectors' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Write-then-verify with retries (the REST-settings visibility gotcha).
	const setOpt = async ( name, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( a ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ a.name ]: a.v } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ a.name ];
			}, { name, v } );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	// The stored anthropic key as wp/v2/settings reports it (always masked).
	const settingValue = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return String( ( await r.json() ).connectors_ai_anthropic_api_key || '' );
	} );

	const openConnectors = async () => {
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );
		await page.$$eval( '.minn-settings-nav-item', ( els ) => {
			const tab = els.find( ( el ) => el.textContent.trim() === 'Connectors' );
			if ( tab ) tab.click();
		} );
		await page.waitForSelector( '[data-conn-card]', { timeout: 10000 } );
	};

	const cardText = ( id ) => page.$eval( `[data-conn-card="${ id }"]`, ( el ) => el.textContent );

	try {
		t.check( 'mock armed', await setOpt( 'minn_test_connectors', '1' ) );
		// Baseline: no key stored.
		await page.evaluate( async () => {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { connectors_ai_anthropic_api_key: '' } ),
			} );
		} );

		await openConnectors();

		/* ===== The registry renders ===== */
		const anthropic = await cardText( 'anthropic' );
		t.check( 'Anthropic card renders with the key field',
			/Anthropic/.test( anthropic ) && !! ( await page.$( '[data-conn-card="anthropic"] [data-conn-key]' ) ), anthropic.slice( 0, 120 ) );
		t.check( 'Anthropic starts Not connected', /Not connected/.test( anthropic ) );
		const openai = await cardText( 'openai' );
		// Live-robust: not installed → Install; installed inactive → Activate;
		// active → key field. Never an empty / "couldn't be loaded" card.
		const openaiOk = /Install & activate/.test( openai )
			|| /Activate/.test( openai )
			|| !! ( await page.$( '[data-conn-card="openai"] [data-conn-key]' ) )
			|| /Key in wp-config|Key in environment|Connected|Not connected/.test( openai );
		t.check( 'OpenAI card is actionable (install, activate, or key)', openaiOk, openai.slice( 0, 200 ) );
		const akismet = await cardText( 'akismet' );
		t.check( 'installed-inactive companion offers activate', /installed but not active/.test( akismet ) && /Activate/.test( akismet ), akismet.slice( 0, 160 ) );
		// The href comes from the live AI-client registry metadata (not core's
		// hardcoded fallback), so pin only the vendor domain.
		t.check( 'credentials link renders', !! ( await page.$( '[data-conn-card="anthropic"] a[href*="anthropic.com"]' ) ) );
		t.check( 'key input ignores password managers', await page.$eval(
			'[data-conn-card="anthropic"] [data-conn-key]',
			( el ) => el.hasAttribute( 'data-1p-ignore' ) && el.type === 'text' ) );

		/* ===== Rejected key: core validates, Minn keeps the typed key ===== */
		await page.fill( '[data-conn-card="anthropic"] [data-conn-key]', 'bogus-key-123456789' );
		await page.click( '[data-conn-save="anthropic"]' );
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /rejected/.test( tEl.textContent );
		}, { timeout: 20000 } );
		t.check( 'rejected key toasts the refusal', true );
		t.check( 'typed key stays in the field for a retype', await page.$eval(
			'[data-conn-card="anthropic"] [data-conn-key]', ( el ) => el.value === 'bogus-key-123456789' ) );
		t.check( 'nothing stored after the rejection', ( await settingValue() ) === '' );

		/* ===== Accepted key: masked, never echoed raw ===== */
		await page.fill( '[data-conn-card="anthropic"] [data-conn-key]', 'minn-valid-key-2026' );
		await page.click( '[data-conn-save="anthropic"]' );
		await page.waitForFunction( () => {
			const card = document.querySelector( '[data-conn-card="anthropic"]' );
			return card && /Connected/.test( card.textContent );
		}, { timeout: 20000 } );
		const after = await cardText( 'anthropic' );
		t.check( 'card flips to Connected', /Connected/.test( after ) );
		t.check( 'replace placeholder names the key tail', await page.$eval(
			'[data-conn-card="anthropic"] [data-conn-key]',
			( el ) => /ends in 2026/.test( el.placeholder ) ) );
		const masked = await settingValue();
		t.check( 'REST reports the key masked, never raw',
			masked.endsWith( '2026' ) && masked !== 'minn-valid-key-2026' && masked.includes( '•' ), JSON.stringify( masked ) );

		/* ===== Remove ===== */
		page.once( 'dialog', ( d ) => d.accept() );
		await page.click( '[data-conn-clear="anthropic"]' );
		await page.waitForFunction( () => {
			const card = document.querySelector( '[data-conn-card="anthropic"]' );
			return card && /Not connected/.test( card.textContent );
		}, { timeout: 20000 } );
		t.check( 'remove clears back to Not connected', true );
		t.check( 'stored key cleared', ( await settingValue() ) === '' );

		/* ===== Failed load → Retry recovers (the OpenAI install empty state) =====
		 * Block minn-admin/v1/connectors until the failed note paints, then
		 * unblock and click Retry — cards must repaint without a full page
		 * reload. The resilient loader retries several times, so keep the
		 * route blocked for the whole first load. */
		let blockConnectors = true;
		await page.route( '**/minn-admin/v1/connectors**', async ( route ) => {
			if ( blockConnectors ) {
				await route.abort( 'failed' );
				return;
			}
			await route.continue();
		} );
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );
		await page.$$eval( '.minn-settings-nav-item', ( els ) => {
			const tab = els.find( ( el ) => el.textContent.trim() === 'Connectors' );
			if ( tab ) tab.click();
		} );
		// loadConnectorsResilient retries (~a few seconds of backoff).
		await page.waitForSelector( '[data-conn-retry]', { timeout: 45000 } );
		t.check( 'failed connectors load offers Retry', true );
		blockConnectors = false;
		await page.click( '[data-conn-retry]' );
		await page.waitForSelector( '[data-conn-card]', { timeout: 20000 } );
		t.check( 'Retry repaints connector cards without full reload',
			!! ( await page.$( '[data-conn-card="anthropic"]' ) ) );
		await page.unroute( '**/minn-admin/v1/connectors**' ).catch( () => {} );
	} finally {
		await page.evaluate( async () => {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { connectors_ai_anthropic_api_key: '', minn_test_connectors: '' } ),
			} );
		} ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
