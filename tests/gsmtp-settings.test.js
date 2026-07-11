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

	// Seed the primary-connector baseline: live sessions switch it (Austin
	// was on Google when reporting the combobox bug) — never assume generic.
	const seedPrimary = () => page.evaluate( async () => {
		await fetch( window.MINN.restUrl + 'minn-admin/v1/gravity-smtp/settings/sending', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { values: { primary_connector: 'generic' } } ),
		} );
	} );

	const openSettings = async () => {
		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="settings"]', { timeout: 20000 } );
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '[data-sset="primary_connector"]', { timeout: 15000 } );
	};

	try {
		/* ===== List + seeded events ===== */
		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
		await seedPrimary();
		// Reload so the status card reflects the seeded primary.
		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		const listText = await page.$eval( '.minn-table', ( el ) => el.textContent );
		t.check( 'seeded events list', /Welcome to Minn/.test( listText ) && /dana@example.com/.test( listText ) );

		/* ===== Status card + parameterized send-a-test ===== */
		await page.waitForSelector( '.minn-surface-status', { timeout: 15000 } );
		const statText = await page.$eval( '.minn-surface-status', ( el ) => el.textContent );
		t.check( 'status card names the sending service', /Sending through/.test( statText ) && /Custom SMTP/.test( statText ), statText.slice( 0, 120 ) );
		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '[data-sstatact]' ) ].find( ( b ) => /Send a test/.test( b.textContent ) );
			btn.click();
		} );
		await page.waitForSelector( '[data-actfield="email"]', { timeout: 10000 } );
		await page.fill( '[data-actfield="email"]', 'minn-test@example.com' );
		await page.evaluate( () => document.querySelectorAll( '.minn-toast' ).forEach( ( e ) => e.remove() ) );
		await page.click( '[data-actgo]' );
		// FluentSMTP owns wp_mail on this site, so the honest outcome message
		// says another mailer carried the test (Austin's repro: delivered to
		// Mailpit, absent from Gravity SMTP's log).
		await page.waitForFunction( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && /Test email sent/.test( tEl.textContent );
		}, { timeout: 30000 } );
		t.check( 'test email sent through the inline address field', true );
		t.check( 'toast says who carried the send when GS did not log it', await page.evaluate( () => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return !! tEl && /another active mail plugin/.test( tEl.textContent );
		} ) );
		await page.waitForSelector( '[data-sview="settings"]', { timeout: 20000 } );

		/* ===== Sending tab: mapped connector schema ===== */
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '[data-sset="primary_connector"]', { timeout: 15000 } );
		// Primary service is a strict combobox (Austin's ask) — the wrapper
		// carries data-sset, the picked value rides the inner input's acValue.
		const combo = '[data-sset="primary_connector"] .minn-ac-input';
		t.check( 'primary combobox reads Custom SMTP', await page.$eval( combo,
			( el ) => el.dataset.acValue === 'generic' && /Custom SMTP/.test( el.value ) ) );
		await page.click( combo );
		await page.waitForSelector( '[data-sset="primary_connector"] .minn-ac-panel:not([hidden])', { timeout: 10000 } );
		const catalog = await page.$$eval( '[data-sset="primary_connector"] .minn-ac-item', ( o ) => o.length );
		t.check( 'combobox browses the connector catalog', catalog >= 15, String( catalog ) );
		await page.keyboard.press( 'Escape' );
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
		await page.waitForSelector( combo, { timeout: 15000 } );
		await page.click( combo );
		await page.keyboard.type( 'postmark' );
		await page.waitForTimeout( 250 );
		await page.keyboard.press( 'Enter' );
		t.check( 'switch to Postmark saves', ( await clickSave() ) === 200 );
		await page.waitForSelector( '[data-sset="primary_connector"]', { timeout: 15000 } );
		const reshaped = await page.$$eval( '[data-sset]', ( els ) => els.map( ( e ) => e.dataset.sset ) );
		t.check( 'tab reshapes with the new connector schema', ! reshaped.includes( 'host' ), reshaped.join( ',' ) );
		stored = await raw();
		t.check( 'config maps flipped to postmark', JSON.stringify( stored.config.primary_connector ) === '{"postmark":true}', JSON.stringify( stored.config.primary_connector ) );
		// Restore generic as primary.
		await page.waitForSelector( combo, { timeout: 15000 } );
		await page.click( combo );
		await page.keyboard.type( 'custom smtp' );
		await page.waitForTimeout( 250 );
		await page.keyboard.press( 'Enter' );
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
