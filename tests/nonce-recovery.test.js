/**
 * Expired-REST-nonce recovery — a tab left open past nonce lifetime used to
 * dead-end: every request 403'd with a raw error toast until a manual reload.
 * apiRes now catches rest_cookie_invalid_nonce, mints a fresh nonce through
 * core's admin-ajax `rest-nonce` action, and retries the request once; a
 * burst of parallel failures shares a single refresh round trip.
 *
 * The suite corrupts window.MINN.nonce (the same object apiRes reads at call
 * time) and drives a real SPA navigation to a view that is NOT boot-warmed
 * (Media), so its loads all land on the bad nonce. Read-only path — the
 * retry machinery is shared with writes.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'nonce-recovery' );

	// Observe (never stub) admin-ajax rest-nonce traffic to count refreshes.
	let refreshCalls = 0;
	await page.route( /admin-ajax\.php\?action=rest-nonce/, ( route ) => {
		refreshCalls++;
		route.continue();
	} );

	await login( page );
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-nav-btn', { timeout: 15000 } );
	// Let the boot warm-up burst drain on the good nonce before corrupting.
	await page.waitForTimeout( 2500 );

	const bootNonce = await page.evaluate( () => window.MINN.nonce );
	t.check( 'Boot nonce present', !! bootNonce && bootNonce.length >= 8 );
	t.check( 'Boot payload carries ajaxUrl', await page.evaluate( () => /admin-ajax\.php$/.test( window.MINN.ajaxUrl || '' ) ) );

	// Corrupt the live nonce; a reload would wipe the probe marker.
	await page.evaluate( () => {
		window.__minnProbe = 1;
		window.MINN.nonce = 'deadbeef99';
	} );
	await page.click( '.minn-nav-btn[data-nav="media"]' );
	await page.waitForSelector( '.minn-media-card, .minn-empty', { timeout: 20000 } );

	t.check( 'Media view rendered despite the stale nonce', await page.evaluate(
		() => !! document.querySelector( '.minn-media-card, .minn-empty' )
	) );

	const healed = await page.evaluate( () => window.MINN.nonce );
	t.check( 'Nonce was replaced in place', healed !== 'deadbeef99' && /^[a-f0-9]{8,12}$/i.test( healed ) );
	t.check( 'At least one refresh ran, bursts deduped', refreshCalls === 1 );
	t.check( 'Recovery never reloaded the page', await page.evaluate( () => window.__minnProbe === 1 ) );

	// The healed nonce is real: another non-warmed view loads with no
	// further refresh traffic.
	await page.click( '.minn-nav-btn[data-nav="users"]' );
	await page.waitForSelector( '.minn-table, .minn-empty', { timeout: 20000 } );
	t.check( 'Next view loads on the healed nonce with no extra refresh', refreshCalls === 1 );

	// No session-expired toast appeared anywhere in the recovery path.
	t.check( 'No session-expired toast during recovery', await page.evaluate(
		() => ! Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( el ) => /session expired/i.test( el.textContent ) )
	) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
