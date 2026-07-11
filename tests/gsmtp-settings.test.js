/**
 * Gravity SMTP deep adapter: the settings mapper (their settings_fields()
 * component schema → Minn's settings view), granular gravitysmtp_* caps,
 * and the enriched event detail.
 *
 * Fixtures on minnadmin: the generic (Custom SMTP) connector configured
 * against Mailpit (127.0.0.1:1025) as enabled + primary, with
 * fixture-user / fixture-smtp-pass-123 stored for the mask test, and two
 * seeded events (real Recipient_Collection blobs via their own parser).
 * The suite restores every setting it flips. The raw fixture route
 * (fixture-gsmtp-raw) reads the stored options directly — the masked
 * sentinel is unverifiable through the masked API by design.
 *
 * Note: FluentSMTP is the resident mail-family provider on this site, so
 * the suite navigates to /minn-admin/gravity-smtp by id (family pick only
 * affects the sidebar).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'gsmtp-settings' );
	const { browser, page, errors } = await launch();
	await login( page );

	const raw = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fixture-gsmtp-raw?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return r.json();
	} );

	const clickSave = async () => {
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /gravity-smtp\/settings\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-sset-save' );
		const res = await wait;
		await page.waitForTimeout( 500 );
		return res.status();
	};

	const openSettings = async () => {
		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="settings"]', { timeout: 20000 } );
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '[data-sset="primary_connector"]', { timeout: 15000 } );
	};

	try {
		/* ===== List + seeded events ===== */
		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		const listText = await page.$eval( '.minn-table', ( el ) => el.textContent );
		t.check( 'seeded events list', /Welcome to Minn/.test( listText ) && /dana@example.com/.test( listText ) );

		/* ===== Sending tab: mapped connector schema ===== */
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '[data-sset="primary_connector"]', { timeout: 15000 } );
		t.check( 'primary select reads generic', await page.$eval( '[data-sset="primary_connector"]', ( el ) => el.value === 'generic' ) );
		t.check( 'primary select lists the connector catalog', await page.$eval( '[data-sset="primary_connector"]', ( el ) => el.options.length >= 15 ),
			String( await page.$eval( '[data-sset="primary_connector"]', ( el ) => el.options.length ) ) );
		t.check( 'mapped host field carries the stored value', await page.$eval( '[data-sset="host"]', ( el ) => el.value === '127.0.0.1' ) );
		t.check( 'password renders as the sentinel, never raw', await page.$eval( '[data-sset="password"]', ( el ) => /^\*+$/.test( el.value ) ) );
		t.check( 'group titled from their schema', ( await page.$eval( '.minn-surface-settings', ( el ) => el.textContent ) ).includes( 'Custom SMTP' ) );

		/* ===== Sentinel + edit round-trip through their store ===== */
		await page.fill( '[data-sset="from_name"]', 'Minn Dev Two' );
		// Dirty the password but leave the mask — their save_all must skip it.
		const mask = await page.$eval( '[data-sset="password"]', ( el ) => el.value );
		await page.fill( '[data-sset="password"]', mask );
		t.check( 'sending save answers 200', ( await clickSave() ) === 200 );
		let stored = await raw();
		t.check( 'edited field persisted through their store', stored.generic.from_name === 'Minn Dev Two', stored.generic.from_name );
		t.check( 'masked sentinel left the stored password intact', stored.generic.password === 'fixture-smtp-pass-123' );
		t.check( 'untouched host never traveled', stored.generic.host === '127.0.0.1' );
		// Restore.
		await page.waitForSelector( '[data-sset="from_name"]', { timeout: 15000 } );
		await page.fill( '[data-sset="from_name"]', 'Minn Dev' );
		await clickSave();

		/* ===== Primary switch reshapes the tab from the new schema ===== */
		await page.waitForSelector( '[data-sset="primary_connector"]', { timeout: 15000 } );
		await page.selectOption( '[data-sset="primary_connector"]', 'postmark' );
		t.check( 'switch to Postmark saves', ( await clickSave() ) === 200 );
		await page.waitForSelector( '[data-sset="primary_connector"]', { timeout: 15000 } );
		const reshaped = await page.$$eval( '[data-sset]', ( els ) => els.map( ( e ) => e.dataset.sset ) );
		t.check( 'tab reshapes with the new connector schema', ! reshaped.includes( 'host' ), reshaped.join( ',' ) );
		stored = await raw();
		t.check( 'config maps flipped to postmark', JSON.stringify( stored.config.primary_connector ) === '{"postmark":true}', JSON.stringify( stored.config.primary_connector ) );
		// Restore generic as primary.
		await page.selectOption( '[data-sset="primary_connector"]', 'generic' );
		await clickSave();
		await page.waitForSelector( '[data-sset="host"]', { timeout: 15000 } );
		stored = await raw();
		t.check( 'generic restored as primary', JSON.stringify( stored.config.primary_connector ) === '{"generic":true}' );

		/* ===== General tab ===== */
		await page.click( '[data-ssettab="general"]' );
		await page.waitForSelector( '[data-sset="test_mode"]', { timeout: 15000 } );
		t.check( 'general values read through the router', await page.$eval( '[data-sset="event_log_enabled"]', ( el ) => el.classList.contains( 'on' ) ) );
		t.check( 'retention shows only while the log is on', await page.$eval( '[data-srow="event_log_retention"]', ( el ) => ! el.hidden ) );
		await page.click( '[data-sset="test_mode"]' );
		await clickSave();
		stored = await raw();
		t.check( 'test mode stored in their string convention', stored.config.test_mode === 'true', JSON.stringify( stored.config.test_mode ) );
		await page.waitForSelector( '[data-sset="test_mode"]', { timeout: 15000 } );
		await page.click( '[data-sset="test_mode"]' );
		await clickSave();
		stored = await raw();
		t.check( 'test mode restored off', stored.config.test_mode === 'false' );

		/* ===== Detail enrichment ===== */
		await page.click( '[data-sview="main"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
		await page.$$eval( '.minn-table-row', ( rows ) => {
			const row = rows.find( ( r ) => r.textContent.includes( 'Welcome to Minn' ) );
			if ( row ) row.click();
		} );
		// The modal opens instantly on "Loading…"; the detail fetch takes a
		// few seconds (their container lazy-loads on first use) — wait for
		// the enriched content, not the modal shell.
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && /minn-fixture/.test( m.textContent );
		}, { timeout: 25000 } );
		const modalText = await page.$eval( '.minn-modal', ( el ) => el.textContent );
		t.check( 'detail enriched with from + source via their models',
			/minn@minnadmin\.localhost/.test( modalText ) && /minn-fixture/.test( modalText ), modalText.slice( 0, 200 ) );
		t.check( 'resend action offered on a resendable event', await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Resend/.test( b.textContent ) ) ) );
	} finally {
		// Belt-and-braces restore of everything the suite flips.
		await page.evaluate( async () => {
			// no-op: every flip above restores in-line; nothing global to reset
		} ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
