/**
 * Settings → Spam (adapters/spam.php, minn_admin_spam_providers filter).
 *
 * Provider cards for the site's spam filtering: Antispam Bee is the resident
 * active provider (Akismet + CleanTalk installed-inactive, family
 * convention). Covers: the section renders the provider card with status
 * pill and toggles, provider toggles + the core disallowed_keys blocklist
 * save through minn-admin/v1/spam and round-trip, the queue row jumps to
 * the Comments spam tab, and activating Akismet mid-session adds its
 * needs-setup card on the next load. Everything created/toggled is
 * restored.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'spam-settings' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Spam is a subsection of the Comments settings tab now (it's comment spam).
	const openSpam = async () => {
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );
		await page.$$eval( '.minn-settings-nav-item', ( els ) => {
			const tab = els.find( ( el ) => el.textContent.trim() === 'Comments' );
			if ( tab ) tab.click();
		} );
		await page.waitForSelector( '.minn-spam-queue', { timeout: 10000 } );
	};

	const spamState = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/spam', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return r.json();
	} );

	const setPlugin = ( plugin, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.plugin, {
			method: 'PUT', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: a.status } ),
		} );
		return r.ok;
	}, { plugin, status } );

	try {
		// Seed the baseline — live sessions (and this very bug report) leave
		// spam plugins toggled; never assume the resident-only state.
		await setPlugin( 'akismet/akismet', 'inactive' ).catch( () => {} );
		await openSpam();

		/* ===== Resident provider card ===== */
		const cardText = await page.$$eval( '.minn-spam-provider', ( els ) =>
			( els.find( ( el ) => el.textContent.includes( 'Antispam Bee' ) ) || { textContent: '' } ).textContent );
		t.check( 'Antispam Bee card renders with Active pill',
			/Antispam Bee/.test( cardText ) && /Active/.test( cardText ) );
		t.check( 'provider toggles render from the descriptor',
			( await page.$( '[data-spamtog="antispam-bee:email_notify"]' ) ) !== null
			&& ( await page.$( '[data-spamtog="antispam-bee:flag_spam"]' ) ) !== null );
		// minnadmin runs Disable Comments (fixture): the queue row must
		// explain itself instead of offering a Review button into a route
		// the nav hides (B.comments gating).
		t.check( 'comments-disabled state explains the missing queue',
			/Commenting is disabled/.test( await page.$eval( '.minn-spam-queue', ( el ) => el.textContent ) )
			&& ( await page.$( '#minn-spam-queue' ) ) === null );

		/* ===== Toggle + blocklist save round-trip ===== */
		await page.click( '[data-spamtog="antispam-bee:email_notify"]' );
		await page.fill( '#minn-spam-keys', 'minn-spam-test-token' );
		await page.click( '#minn-save-settings' );
		await page.waitForTimeout( 1200 );
		let st = await spamState();
		const asb = st.providers.find( ( p ) => p.id === 'antispam-bee' );
		t.check( 'toggle persisted through the provider option',
			asb && asb.toggles.find( ( x ) => x.id === 'email_notify' ).on === true );
		t.check( 'disallowed_keys blocklist saved', st.disallowed_keys === 'minn-spam-test-token' );

		// Restore both.
		await page.click( '[data-spamtog="antispam-bee:email_notify"]' );
		await page.fill( '#minn-spam-keys', '' );
		await page.click( '#minn-save-settings' );
		await page.waitForTimeout( 1200 );
		st = await spamState();
		t.check( 'restore round-trips clean',
			st.disallowed_keys === ''
			&& st.providers.find( ( p ) => p.id === 'antispam-bee' ).toggles.find( ( x ) => x.id === 'email_notify' ).on === false );

		/* ===== With comments enabled, the queue row counts + navigates ===== */
		t.check( 'disable-comments deactivates over REST', await setPlugin( 'disable-comments/disable-comments', 'inactive' ) );
		await openSpam();
		t.check( 'queue row shows the spam count', /\d+ comments? in the spam queue/.test(
			await page.$eval( '.minn-spam-queue', ( el ) => el.textContent ) ) );
		await page.click( '#minn-spam-queue' );
		await page.waitForSelector( '.minn-tab.active', { timeout: 10000 } );
		const activeTab = await page.$eval( '.minn-tab.active', ( el ) => el.textContent.trim() );
		t.check( 'Review spam lands on the Comments spam tab', activeTab === 'Spam', activeTab );

		/* ===== Extensions toggle refreshes the Spam page without reload =====
		   (Austin's report: state.cache.settings survived plugin toggles, so
		   an activated spam plugin never appeared until a hard refresh). */
		const spaNav = ( id ) => page.$$eval( '.minn-nav-btn', ( els, target ) => {
			const btn = els.find( ( e ) => e.dataset.nav === target );
			if ( btn ) btn.click();
			return !! btn;
		}, id );
		const openSpamSection = async () => {
			await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );
			await page.$$eval( '.minn-settings-nav-item', ( els ) => {
				const spam = els.find( ( el ) => el.textContent.trim() === 'Spam' );
				if ( spam ) spam.click();
			} );
			await page.waitForSelector( '.minn-spam-queue', { timeout: 10000 } );
		};

		// Prime the settings cache with Akismet absent.
		await spaNav( 'settings' );
		await openSpamSection();
		let names = await page.$$eval( '.minn-spam-provider .minn-spam-name', ( els ) => els.map( ( e ) => e.textContent ) );
		t.check( 'Akismet absent before activation', ! names.includes( 'Akismet' ), names.join( ', ' ) );

		// Activate Akismet through the Extensions UI — the SPA path that
		// must bust the settings cache.
		await spaNav( 'extensions' );
		await page.waitForSelector( '.minn-plugin[data-plugin^="akismet"]', { timeout: 20000 } );
		await page.$eval( '.minn-plugin[data-plugin^="akismet"]', ( el ) => el.scrollIntoView( { block: 'center' } ) );
		await page.click( '.minn-plugin[data-plugin^="akismet"] .minn-switch' );
		await page.waitForFunction( () => {
			const c = document.querySelector( '.minn-plugin[data-plugin^="akismet"]' );
			return c && c.querySelector( '.minn-switch.on' );
		}, null, { timeout: 30000 } );
		await page.waitForTimeout( 1500 ); // refreshAfterPluginChange settles

		// Back to Settings → Spam in the SAME session, no reload.
		await spaNav( 'settings' );
		await openSpamSection();
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-spam-provider .minn-spam-name' ) )
				.some( ( e ) => e.textContent === 'Akismet' ), null, { timeout: 15000 } );
		names = await page.$$eval( '.minn-spam-provider .minn-spam-name', ( els ) => els.map( ( e ) => e.textContent ) );
		t.check( 'Extensions toggle refreshes the Spam page without a reload',
			names.includes( 'Akismet' ) && names.includes( 'Antispam Bee' ), names.join( ', ' ) );
		const akCard = await page.$$eval( '.minn-spam-provider', ( els ) =>
			( els.find( ( el ) => el.textContent.includes( 'Akismet' ) ) || { textContent: '' } ).textContent );
		t.check( 'keyless Akismet shows Needs setup', /Needs setup/.test( akCard ) && /API key/.test( akCard ) );
	} finally {
		await setPlugin( 'akismet/akismet', 'inactive' ).catch( () => {} );
		await setPlugin( 'disable-comments/disable-comments', 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
