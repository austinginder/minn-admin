/**
 * Surface `settings` view (the Rung-2 shape, docs/full-ui-adapters.md):
 * an adapter serves schema-driven settings tabs from one route
 * (GET → { groups, values, adminUrl }, POST { values: dirty-only } → fresh
 * shape) and the client renders them through the shared form engine.
 *
 * Driven against the mu-fixture surface (minn_test_settings_surface):
 * field vocabulary (text/number/select/toggle + help), live showWhen
 * dependency, only-dirty-keys saves (untouched fields never travel), the
 * masked-secret sentinel (a mask riding back never clobbers the stored
 * secret), a server refusal keeping the form as typed, and the locked
 * count with its wp-admin escape.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'settings-surface' );
	const { browser, page, errors } = await launch();
	await login( page );

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

	const raw = ( method = 'GET' ) => page.evaluate( async ( m ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fixture-settings-raw?_cb=' + Math.random(), {
			method: m, headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return m === 'GET' ? r.json() : r.ok;
	}, method );

	// Click Save and wait on the real POST response — a toast from the
	// PREVIOUS save can still be on screen (2.6s lifetime), so toast-based
	// waits match stale toasts and verification reads mid-flight state.
	const clickSave = async () => {
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /fixture-settings\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		const res = await wait;
		await page.waitForTimeout( 400 );
		return res.status();
	};

	const openSettingsView = async () => {
		await page.goto( BASE + '/minn-admin/minn-settings-fixture', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="settings"]', { timeout: 20000 } );
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '[data-sset]', { timeout: 15000 } );
	};

	try {
		t.check( 'fixture armed', await setOpt( 'minn_test_settings_surface', true ) );
		await raw( 'DELETE' ); // deterministic seed

		await openSettingsView();

		/* ===== Schema renders through the form engine ===== */
		t.check( 'view switcher shows the Settings view', await page.$eval( '[data-sview="settings"]', ( el ) => el.classList.contains( 'active' ) ) );
		const kinds = await page.$$eval( '[data-sset]', ( els ) => els.map( ( e ) => e.dataset.sset + ':' + e.dataset.ftype ).sort() );
		// Declared selects render as the themed strict combobox in adapter
		// forms (comboUpgrade) — the native select popup is never shown.
		t.check( 'field vocabulary renders (text/number/select-as-combobox/toggle)',
			JSON.stringify( kinds ) === JSON.stringify( [ 'advanced_url:text', 'enabled:toggle', 'mode:combobox', 'retention:number', 'site_label:text' ] ), kinds.join( ', ' ) );
		t.check( 'values seed the controls', await page.$eval( '[data-sset="site_label"]', ( el ) => el.value === 'Fixture' )
			&& await page.$eval( '[data-sset="retention"]', ( el ) => el.value === '30' )
			&& await page.$eval( '[data-sset="enabled"]', ( el ) => el.classList.contains( 'on' ) ) );
		t.check( 'help text renders', ( await page.$eval( '.minn-surface-settings', ( el ) => el.textContent ) ).includes( 'Shown on outgoing mail.' ) );
		t.check( 'locked count renders with the wp-admin escape', await page.$eval( '.minn-panel-locked', ( el ) =>
			/2 advanced settings/.test( el.textContent ) && !! el.querySelector( 'a[href*="options-general"]' ) ) );

		/* ===== showWhen follows the toggle live ===== */
		t.check( 'dependent row visible while toggle is on', await page.$eval( '[data-srow="advanced_url"]', ( el ) => ! el.hidden ) );
		await page.click( '[data-sset="enabled"]' );
		t.check( 'dependent row hides when the toggle flips off', await page.$eval( '[data-srow="advanced_url"]', ( el ) => el.hidden ) );
		await page.click( '[data-sset="enabled"]' ); // back on — enabled is now dirty but unchanged in value

		/* ===== Server refusal keeps the form as typed ===== */
		await page.fill( '[data-sset="retention"]', '0' );
		t.check( 'refused save answers 400', ( await clickSave() ) === 400 );
		t.check( 'refusal toasts the server message', await page.evaluate( () => {
			const el = document.querySelector( '.minn-toast-msg' );
			return !! el && /positive number/.test( el.textContent );
		} ) );
		t.check( 'form keeps the typed value after refusal', await page.$eval( '[data-sset="retention"]', ( el ) => el.value === '0' ) );

		/* ===== Only dirty keys ride the save ===== */
		await page.fill( '[data-sset="retention"]', '45' );
		await page.fill( '[data-sset="site_label"]', 'Fixture Two' );
		// mode is deliberately untouched.
		t.check( 'save answers 200', ( await clickSave() ) === 200 );
		let stored = await raw();
		t.check( 'edited values persisted', stored.site_label === 'Fixture Two' && Number( stored.retention ) === 45, JSON.stringify( stored ) );
		t.check( 'untouched select never traveled', stored.mode === 'digest' );

		/* ===== Secrets tab: masked sentinel ===== */
		await page.click( '[data-ssettab="secrets"]' );
		await page.waitForSelector( '[data-sset="api_secret"]', { timeout: 15000 } );
		const maskedShown = await page.$eval( '[data-sset="api_secret"]', ( el ) => el.value );
		t.check( 'secret renders masked with its tail', /^\*+123$/.test( maskedShown ), maskedShown );
		// Dirty the secret field but leave the mask in place, change the label:
		// the sentinel must not clobber the stored secret.
		await page.fill( '[data-sset="api_secret"]', maskedShown );
		await page.fill( '[data-sset="site_label"]', 'Fixture Three' );
		await clickSave();
		stored = await raw();
		t.check( 'masked sentinel left the stored secret intact', stored.api_secret === 'seed-secret-abc123', stored.api_secret );
		t.check( 'sibling field on the tab still saved', stored.site_label === 'Fixture Three', stored.site_label );
		// A really new secret writes through; the save re-renders the tab
		// from the fresh response, so the field re-serves masked.
		await page.waitForSelector( '[data-sset="api_secret"]', { timeout: 15000 } );
		await page.fill( '[data-sset="api_secret"]', 'rotated-secret-xyz789' );
		await clickSave();
		stored = await raw();
		t.check( 'new secret stored', stored.api_secret === 'rotated-secret-xyz789', stored.api_secret );
		t.check( 'field re-serves the rotated secret masked', await page.$eval( '[data-sset="api_secret"]', ( el ) => /^\*+789$/.test( el.value ) ) );

		/* ===== Back to the list view ===== */
		await page.click( '[data-sview="main"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
		t.check( 'list view returns after settings', true );
	} finally {
		await raw( 'DELETE' ).catch( () => {} );
		await setOpt( 'minn_test_settings_surface', false );
	}

	await t.done( browser, errors );
} )();
