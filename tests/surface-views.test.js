/**
 * Surface `views` — extra list views beyond collection/manage (the Rung-3
 * "third list view"). Driven against the bundled Gravity SMTP adapter's
 * Debug log view: the switcher grows a tab, the view is a full collection
 * (priority tabs, search, raw detail modal), the status card stays a
 * main-view-only feature, and the old "Debug log ↗" status link-out is gone.
 *
 * Fixture rows are seeded through Gravity SMTP's own Debug_Log_Model via
 * the one-shot minn_test_seed_gsmtp_debug option (mu-fixtures).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'surface-views' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Write-then-verify (rule: REST settings writes can race the boot burst).
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
			// One-shot seeders consume the flag on the verifying GET's own
			// init, so ''-after-write also reads as success.
			if ( stored === v || ( v === '1' && stored === '' ) ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	try {
		// Arm the seeder; it runs on the NEXT request's init, and the
		// navigation below is that request.
		t.check( 'debug-line seeder armed', await setOpt( 'minn_test_seed_gsmtp_debug', '1' ) );

		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-view-switch', { timeout: 20000 } );

		/* ===== The switcher grows the extra view ===== */
		const tabs = await page.$$eval( '.minn-view-switch [data-sview]', ( els ) =>
			els.map( ( e ) => e.dataset.sview + ':' + e.textContent.trim() ) );
		t.check( 'switcher shows main, manage, x0 and settings',
			JSON.stringify( tabs ) === JSON.stringify( [ 'main:Log', 'manage:Suppressions', 'x0:Debug log', 'settings:Settings' ] ), tabs.join( ' · ' ) );

		/* ===== Status card is a main-view feature; the link-out is gone ===== */
		await page.waitForSelector( '.minn-surface-status', { timeout: 20000 } );
		t.check( 'status card renders on the main view', true );
		t.check( 'status card no longer links out to the debug log',
			! ( await page.$eval( '.minn-surface-status', ( el ) => el.textContent ) ).includes( 'Debug log' ) );

		/* ===== The extra view is a full collection ===== */
		await page.click( '[data-sview="x0"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'x0 tab activates', await page.$eval( '[data-sview="x0"]', ( el ) => el.classList.contains( 'active' ) ) );
		t.check( 'status card absent on the extra view', ! ( await page.$( '.minn-surface-status' ) ) );
		const rowText = await page.$eval( '#minn-view', ( el ) => el.textContent );
		t.check( 'seeded debug lines render', rowText.includes( 'Minn fixture: connector responded 250 OK' ) );

		// Priority tabs (static tabs param) — Errors shows only error rows.
		const priTabs = await page.$$eval( '[data-stab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'priority tabs render', JSON.stringify( priTabs ) === JSON.stringify( [ 'All', 'Errors', 'Warnings', 'Info', 'Debug' ] ), priTabs.join( ' · ' ) );
		await page.click( '[data-stab="error"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		const pills = await page.$$eval( '.minn-table-row .minn-status', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'Errors tab filters to error rows', pills.length >= 1 && pills.every( ( p ) => p === 'error' ), pills.join( ',' ) );

		// Search rides the view's own collection config.
		await page.click( '[data-stab="_all"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		await page.fill( '#minn-surface-search', 'deferred' );
		await page.waitForFunction( () => {
			const rows = document.querySelectorAll( '.minn-table-row' );
			return rows.length === 1 && rows[ 0 ].textContent.includes( 'deferred' );
		}, { timeout: 20000 } );
		t.check( 'search filters the extra view', true );

		// Raw detail modal opens from an extra-view row.
		await page.click( '.minn-table-row' );
		await page.waitForSelector( '.minn-modal', { timeout: 15000 } );
		const modalText = await page.$eval( '.minn-modal', ( el ) => el.textContent );
		t.check( 'row opens the detail modal with the full line',
			modalText.includes( 'recipient deferred, retrying' ) && modalText.includes( 'warning' ) );
		await page.keyboard.press( 'Escape' );

		/* ===== Back to main; list state was reset ===== */
		await page.click( '[data-sview="main"]' );
		await page.waitForSelector( '.minn-surface-status', { timeout: 20000 } );
		t.check( 'main view returns with its status card', true );

		/* ===== The descriptor passes the Integrations validator ===== */
		const problems = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/system?_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const d = await r.json();
			const row = ( ( d.integrations || {} ).surfaces || [] ).find( ( s ) => s.id === 'gravity-smtp' );
			return row ? row.problems : [ 'surface missing from integrations' ];
		} );
		t.check( 'gravity-smtp validates clean with its views entry', Array.isArray( problems ) && problems.length === 0, JSON.stringify( problems ) );
	} finally {
		await setOpt( 'minn_test_seed_gsmtp_debug', '' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
