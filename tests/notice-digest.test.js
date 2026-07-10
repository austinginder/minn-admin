/**
 * Admin-notice digest — extraction into the notification panel.
 *
 * The capture endpoint boots a real wp-admin dashboard pageload as the
 * current user, renders every registered admin-notice callback in an
 * isolated buffer, and stores structured data (severity, text, links,
 * owner) — never third-party HTML. This suite drives the fixtures the
 * minn-dev-fixtures mu-plugin registers: a dismissible warning with an
 * external link, a non-dismissible error, one callback emitting TWO
 * notices (split test), and a plugins.php-gated notice the dashboard
 * capture must skip.
 */
const { launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'notice-digest' );

	await login( page );

	// --- Capture endpoint -------------------------------------------------
	const boot = await page.evaluate( () => window.MINN.notices || null );
	t.check( 'Boot payload carries notices.url', !! ( boot && boot.url && boot.url.includes( 'minn_notices=1' ) ) );

	const cap = await page.evaluate( async () => {
		const r = await fetch( window.MINN.notices.url, { credentials: 'same-origin' } );
		return { status: r.status, body: await r.json() };
	} );
	t.check( 'Capture responds ok JSON (page chrome swallowed)', cap.status === 200 && cap.body && cap.body.ok === true );
	t.check( 'Capture found the fixture notices', cap.body.count >= 4, `count=${ cap.body.count }` );

	// --- Notifications endpoint -------------------------------------------
	const items = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/notifications', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		} );
		return ( await r.json() ).items.filter( ( n ) => n.kind === 'notices' );
	} );

	const byText = ( s ) => items.find( ( n ) => n.title.includes( s ) );
	const warning = byText( 'license expires soon' );
	t.check( 'Warning fixture extracted', !! warning );
	t.check( 'Attribution names the owning plugin', !! warning && warning.title.startsWith( 'Minn Dev Fixtures:' ), warning && warning.title );
	t.check( 'Severity icon rides the item', !! warning && warning.icon === '⚠️' && warning.severity === 'warning' );
	t.check( 'External action link extracted', !! warning && warning.link === 'https://example.com/renew' );

	const error = byText( 'nightly backup failed' );
	t.check( 'Error fixture extracted', !! error && error.severity === 'error' );
	t.check( 'Link-less notice has no link', !! error && error.link === '' );

	const split1 = byText( 'settings were imported' );
	const split2 = byText( 'integrations catalog is available' );
	t.check( 'One callback, two notices → two entries', !! split1 && !! split2 && split1.id !== split2.id );
	t.check( 'Relative link absolutized to wp-admin', !! split2 && /\/wp-admin\/plugins\.php$/.test( split2.link ) );
	t.check( 'Screen-gated notice NOT captured on dashboard', ! byText( 'gated notice' ) );

	// --- Panel UI -----------------------------------------------------------
	await page.click( '#minn-notif-btn' );
	await page.waitForSelector( '.minn-notif-panel', { timeout: 5000 } );
	const noticesTab = await page.$( '.minn-notif-tab[data-tab="notices"]' );
	t.check( 'Notices tab exists', !! noticesTab );
	await noticesTab.click();
	await page.waitForTimeout( 300 );
	const rowCount = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-notif-row' ) )
			.filter( ( r ) => r.textContent.includes( 'Minn Fixture' ) ).length
	);
	t.check( 'Fixture rows render under the Notices tab', rowCount >= 4, `rows=${ rowCount }` );

	// Row click opens the notice's own action link (stub window.open).
	await page.evaluate( () => {
		window.__minnOpened = null;
		window.open = ( u ) => { window.__minnOpened = u; return null; };
	} );
	await page.evaluate( () => {
		const row = Array.from( document.querySelectorAll( '.minn-notif-row' ) )
			.find( ( r ) => r.textContent.includes( 'license expires soon' ) );
		row.click();
	} );
	await page.waitForTimeout( 200 );
	const opened = await page.evaluate( () => window.__minnOpened );
	t.check( 'Clicking a notice row opens its action link', opened === 'https://example.com/renew' );
	const panelGone = await page.evaluate( () => ! document.querySelector( '.minn-notif-panel' ) );
	t.check( 'Panel closes after row click', panelGone );

	// Read state persists: the clicked row is no longer unread on re-open.
	await page.click( '#minn-notif-btn' );
	await page.waitForSelector( '.minn-notif-panel', { timeout: 5000 } );
	await page.waitForTimeout( 400 );
	const stillUnread = await page.evaluate( () => {
		const row = Array.from( document.querySelectorAll( '.minn-notif-row' ) )
			.find( ( r ) => r.textContent.includes( 'license expires soon' ) );
		return row ? row.classList.contains( 'unread' ) : null;
	} );
	t.check( 'Clicked notice is marked read', stillUnread === false, `unread=${ stillUnread }` );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
