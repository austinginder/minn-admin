/**
 * Core-update visibility — Overview banner, notification item, System check.
 *
 * Core updates are important enough to surface on the front page, not just
 * Extensions. The minn-dev-fixtures mu-plugin fakes a pending offer when the
 * REST-exposed minn_test_core_update option holds a version string (the
 * current version, so nothing real could act on it); this suite flips it on,
 * asserts every surface, flips it off, and asserts the banner is gone.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'core-update-visibility' );

	await login( page );

	const setOffer = ( v ) => page.evaluate( async ( ver ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/settings', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { minn_test_core_update: ver } ),
		} );
		return r.status;
	}, v );

	try {
		// Current core version → a same-version "upgrade" offer.
		const current = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/core', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			return ( await r.json() ).version;
		} );
		t.check( 'Core status endpoint reports a version', /^\d+\./.test( current ), current );

		t.check( 'Fixture offer set over REST', ( await setOffer( current ) ) === 200 );

		// --- Overview banner ------------------------------------------------
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-core-banner', { timeout: 15000 } );
		const banner = await page.evaluate( () => {
			const b = document.querySelector( '.minn-core-banner' );
			return { text: b.textContent, hasButton: !! b.querySelector( '#minn-core-update' ) };
		} );
		t.check( 'Overview shows the core banner', banner.text.includes( `WordPress ${ current } is available` ) );
		t.check( 'Banner carries the Update button', banner.hasButton );

		// Persistent topbar chip — visible on every route while an update pends.
		const chip = await page.evaluate( () => {
			const c = document.querySelector( '#minn-core-chip' );
			return { hidden: c.hidden, text: c.textContent.trim() };
		} );
		t.check( 'Topbar chip shows while an update pends', ! chip.hidden && chip.text.includes( `WordPress ${ current }` ), JSON.stringify( chip ) );

		// --- Notification item ------------------------------------------------
		const notif = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/notifications', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			return ( await r.json() ).items;
		} );
		t.check(
			'Notifications carry the pending update',
			notif.some( ( n ) => n.id === `core-${ current }` && n.title.includes( 'is available' ) )
		);

		// --- Update everything (Updates tab) ----------------------------------
		await page.click( '#minn-notif-btn' );
		await page.waitForSelector( '.minn-notif-panel', { timeout: 5000 } );
		await page.click( '.minn-notif-tab[data-tab="updates"]' );
		await page.waitForSelector( '#minn-update-all', { timeout: 5000 } );
		const updAll = await page.evaluate( () => ( {
			label: document.querySelector( '#minn-update-all' ).textContent.trim(),
			sub: ( document.querySelector( '.minn-update-all-sub' ) || {} ).textContent || '',
		} ) );
		t.check( 'Update everything button pinned on the Updates tab', updAll.label.includes( 'Update everything' ) );
		t.check( 'Button subtitle names WordPress', updAll.sub.includes( `WordPress ${ current }` ), updAll.sub );

		// Cancelling the confirm leaves everything untouched.
		let dialogSeen = '';
		page.once( 'dialog', ( d ) => { dialogSeen = d.message(); d.dismiss(); } );
		await page.click( '#minn-update-all' );
		await page.waitForTimeout( 400 );
		t.check( 'Confirm lists what will update', dialogSeen.includes( `WordPress ${ current }` ), dialogSeen );
		const stillIdle = await page.evaluate( () => ! document.querySelector( '#minn-update-all' ).disabled );
		t.check( 'Cancel leaves the button idle', stillIdle );
		await page.keyboard.press( 'Escape' ); // close the panel

		// --- System health check ---------------------------------------------
		const checks = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/system', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			return ( await r.json() ).checks;
		} );
		const wpCheck = checks.find( ( c ) => c.label === 'WordPress version' );
		t.check( 'System check warns while an update pends', !! wpCheck && wpCheck.status === 'warn', JSON.stringify( wpCheck ) );

		// --- Offer cleared → surfaces reflect reality --------------------------
		// The dev site may genuinely have an update pending (minors wait for
		// the auto-updater), so assert against the real offer, not "calm".
		t.check( 'Fixture offer cleared', ( await setOffer( '' ) ) === 200 );
		const real = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/core', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			return ( await r.json() ).update;
		} );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-stats', { timeout: 15000 } );
		await page.waitForTimeout( 1500 ); // lazy core-status fetch settles
		const bannerText = await page.evaluate( () => {
			const b = document.querySelector( '.minn-core-banner' );
			return b ? b.textContent : '';
		} );
		t.check(
			real ? `Banner shows the real pending ${ real.version }` : 'Banner absent with no pending update',
			real ? bannerText.includes( `WordPress ${ real.version } is available` ) : bannerText === ''
		);
		const chipAfter = await page.evaluate( () => document.querySelector( '#minn-core-chip' ).hidden );
		t.check( 'Topbar chip matches the real offer state', chipAfter === ! real );

		const checksAfter = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/system', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			return ( await r.json() ).checks;
		} );
		const wpAfter = checksAfter.find( ( c ) => c.label === 'WordPress version' );
		t.check(
			'System check matches the real offer state',
			!! wpAfter && wpAfter.status === ( real ? 'warn' : 'pass' ),
			JSON.stringify( wpAfter )
		);

		// --- Theme updates surface alongside plugin updates -------------------
		const themeState = await page.evaluate( async () => {
			const h = { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' };
			const upd = await ( await fetch( window.MINN.restUrl + 'minn-admin/v1/plugin-updates', h ) ).json();
			const notif = ( await ( await fetch( window.MINN.restUrl + 'minn-admin/v1/notifications', h ) ).json() ).items;
			return { themes: Object.keys( upd.themes || {} ).length, notifThemes: notif.filter( ( n ) => n.id.startsWith( 'theme-' ) ).length };
		} );
		t.check(
			'Theme-update count matches notification items',
			themeState.themes === themeState.notifThemes,
			JSON.stringify( themeState )
		);
		if ( themeState.themes ) {
			const dotShown = await page.evaluate( () => {
				const d = document.querySelector( '#minn-plugin-dot' );
				return !! d && ! d.hidden;
			} );
			t.check( 'Extensions dot lights for theme updates', dotShown );
		}
	} finally {
		// Never leave a fake offer behind — the nightly auto-updater reads
		// the same transient.
		await setOffer( '' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
