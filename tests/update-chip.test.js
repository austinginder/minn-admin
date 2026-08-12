/**
 * Bulk-update progress chip (once the
 * notification panel closes, a running "Update everything" is invisible).
 * Arms a same-version plugin offer via the minn_test_plugin_update fixture
 * (harmless wp.org reinstall of the active koko-analytics), drives the real
 * Update-everything flow, and asserts the topbar chip is the ambient signal —
 * ESPECIALLY with the panel closed. Cleanup restores a truthful transient
 * (a lingering fake offer would feed the nightly auto-updater).
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'update-chip' );

	const wpPath = path.resolve( __dirname, '../../../../' );
	const wpCli = ( args ) => execSync( `wp --path=${ JSON.stringify( wpPath ) } ${ args } 2>/dev/null`, { timeout: 120000 } ).toString().trim();

	const TARGET = 'koko-analytics/koko-analytics.php';
	const beforeVersion = wpCli( 'plugin get koko-analytics --field=version' );

	try {
		wpCli( `option update minn_test_plugin_update ${ JSON.stringify( TARGET ) }` );
		// delete_site_transient, never `wp transient delete`: the fixture
		// filter makes the transient always READ as existing, so the CLI
		// command reports "not deleted even though it appears to exist".
		wpCli( `eval 'delete_site_transient( "update_plugins" );'` );

		await login( page );

		// Open the notification panel and switch to Updates — the
		// Update-everything button renders on THAT tab only.
		await page.click( '#minn-notif-btn' );
		await page.waitForSelector( '[data-tab="updates"]', { timeout: 20000 } );
		await page.click( '[data-tab="updates"]' );
		await page.waitForSelector( '#minn-update-all', { timeout: 20000 } );
		await page.click( '#minn-update-all' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 10000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );

		// Chip appears with the plugin-phase label.
		await page.waitForFunction( () => {
			const c = document.querySelector( '#minn-upd-chip' );
			return c && ! c.hidden;
		}, null, { timeout: 15000 } );
		const chipText = await page.evaluate( () => document.querySelector( '#minn-upd-chip-text' ).textContent );
		// Count-agnostic: real pending updates (license-gated paid fixtures)
		// can ride along with the fixture offer — the phase label just has
		// to be the plugin phase.
		t.check( 'Chip appears with the plugin phase', /Updating \d+ plugins?/.test( chipText ), chipText );

		// THE ask: close the panel mid-run — the chip stays as ambient feedback.
		// evaluate-click: the open panel's overlay intercepts a real click on
		// the topbar bell.
		await page.evaluate( () => document.querySelector( '#minn-notif-btn' ).click() );
		await page.waitForFunction( () => ! document.querySelector( '#minn-update-all' ), null, { timeout: 10000 } ).catch( () => null );
		const closedState = await page.evaluate( () => ( {
			panel: !! document.querySelector( '#minn-update-all' ),
			chip: ! document.querySelector( '#minn-upd-chip' ).hidden,
		} ) );
		t.check( 'Panel closed, chip still visible', ! closedState.panel && closedState.chip, JSON.stringify( closedState ) );

		// Run completes: chip hides again.
		await page.waitForFunction( () => document.querySelector( '#minn-upd-chip' ).hidden, null, { timeout: 180000 } );
		t.check( 'Chip hides when the run finishes', true, '' );

		// Harmless reinstall: same version, still active.
		const afterVersion = wpCli( 'plugin get koko-analytics --field=version' );
		const active = wpCli( 'plugin get koko-analytics --field=status' );
		t.check( 'Plugin reinstalled at the same version, still active',
			afterVersion === beforeVersion && active === 'active',
			JSON.stringify( { beforeVersion, afterVersion, active } ) );
	} finally {
		try {
			wpCli( 'option delete minn_test_plugin_update' );
			wpCli( `eval 'delete_site_transient( "update_plugins" ); wp_update_plugins();'` );
		} catch ( e ) { /* cleanup is best-effort */ }
	}

	/* ===== Staleness filter: an offer AT the installed version (the classic
	 * update-already-installed transient bug) must never surface — not in
	 * the /plugin-updates map, not as a notification row. ===== */
	try {
		wpCli( 'option update minn_test_plugin_update_stale koko-analytics/koko-analytics.php' );
		wpCli( `eval 'delete_site_transient( "update_plugins" );'` );
		const filtered = await page.evaluate( async () => {
			const h = { 'X-WP-Nonce': window.MINN.nonce };
			const up = await ( await fetch( window.MINN.restUrl + 'minn-admin/v1/plugin-updates?_cb=' + Math.random(), { headers: h, credentials: 'same-origin' } ) ).json();
			const notif = await ( await fetch( window.MINN.restUrl + 'minn-admin/v1/notifications?_cb=' + Math.random(), { headers: h, credentials: 'same-origin' } ) ).json();
			return {
				inMap: !! ( up.updates && up.updates[ 'koko-analytics/koko-analytics.php' ] ),
				inNotif: ( notif.items || [] ).some( ( i ) => i.update && i.update.plugin === 'koko-analytics/koko-analytics.php' ),
				rawOffer: await ( async () => true )(),
			};
		} );
		const rawHasOffer = wpCli( `eval '$tr = get_site_transient( "update_plugins" ); echo isset( $tr->response["koko-analytics/koko-analytics.php"] ) ? "yes" : "no";'` );
		t.check( 'stale offer present in the raw transient', rawHasOffer === 'yes', rawHasOffer );
		t.check( 'stale offer filtered from /plugin-updates and notifications', ! filtered.inMap && ! filtered.inNotif, JSON.stringify( filtered ) );
	} finally {
		try {
			wpCli( 'option delete minn_test_plugin_update_stale' );
			wpCli( `eval 'delete_site_transient( "update_plugins" ); wp_update_plugins();'` );
		} catch ( e ) { /* cleanup is best-effort */ }
	}

	await t.done( browser, errors );
} )();
