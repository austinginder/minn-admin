/**
 * FluentSMTP settings tab — the daily-ops misc settings (default/fallback
 * connection, logging, retention, email simulation) mapped through
 * FluentSMTP's own Settings model (adapters/fluent-smtp.php). The connection
 * wizard deliberately stays FluentSMTP's app. Flips simulation + retention
 * through the real UI, verifies the stored option, and restores.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'fsmtp-settings' );
	const { browser, page, errors } = await launch();
	await login( page );

	const restSettings = ( vals ) => page.evaluate( async ( v ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-smtp/settings/general' + ( v ? '' : '?_cb=' + Math.random() ), {
			method: v ? 'POST' : 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( v ? { body: JSON.stringify( { values: v } ) } : {} ),
		} );
		return r.json();
	}, vals || null );

	const clickSave = async () => {
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /fluent-smtp\/settings\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		const res = await wait;
		await page.waitForTimeout( 400 );
		return res.status();
	};

	const before = await ( async () => {
		await page.goto( BASE + '/minn-admin/fluent-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
		return ( await restSettings() ).values;
	} )();

	try {
		await page.waitForSelector( '.minn-view-switch', { timeout: 20000 } );
		t.check( 'switcher offers Settings', await page.$$eval( '.minn-view-switch [data-sview]', ( els ) =>
			els.some( ( e ) => e.dataset.sview === 'settings' ) ) );
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '[data-sset="log_emails"]', { timeout: 20000 } );

		const groups = await page.$$eval( '.minn-fields-sub', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'groups render (Connections / Logging / Email simulation)',
			groups.includes( 'Connections' ) && groups.includes( 'Logging' ) && groups.includes( 'Email simulation' ),
			groups.join( ' · ' ) );
		t.check( 'connection pickers are themed comboboxes', await page.$eval( '[data-sset="default_connection"]', ( el ) => el.dataset.ftype === 'combobox' ) );
		t.check( 'default connection seeds from FluentSMTP', await page.$eval( '[data-sset="default_connection"]', ( el, cur ) =>
			el.dataset.acseed === cur, before.default_connection ) );

		// Flip simulation on + retention to 30 days through the real form.
		await page.click( '[data-sset="simulate_emails"]' );
		await page.click( '[data-srow="log_saved_interval_days"] .minn-ac-input' );
		await page.waitForSelector( '[data-srow="log_saved_interval_days"] .minn-ac-item[data-acv="30"]', { timeout: 5000 } );
		await page.click( '[data-srow="log_saved_interval_days"] .minn-ac-item[data-acv="30"]' );
		t.check( 'save answers 200', ( await clickSave() ) === 200 );

		const stored = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-smtp/settings/general?_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).values;
		} );
		t.check( 'simulation + retention persisted through their model',
			stored.simulate_emails === true && stored.log_saved_interval_days === '30',
			JSON.stringify( { s: stored.simulate_emails, d: stored.log_saved_interval_days } ) );

		// A connection key that no longer exists is refused.
		const bad = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-smtp/settings/general', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { values: { default_connection: 'bogus-key' } } ),
			} );
			return r.status;
		} );
		t.check( 'unknown connection refused with 400', bad === 400, String( bad ) );
	} finally {
		await restSettings( {
			simulate_emails: !! before.simulate_emails,
			log_saved_interval_days: before.log_saved_interval_days,
			log_emails: !! before.log_emails,
			fallback_connection: before.fallback_connection,
		} ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
