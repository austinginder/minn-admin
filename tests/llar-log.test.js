/**
 * Limit Login Attempts Reloaded — lockout log in the Activity Log family.
 *
 * Proves: the shim flattens the plugin's option-stored log (ip → user →
 * {counter,date,gateway,unlocked}) into rows with locked/expired/unlocked
 * pills, the status card reports active lockouts + policy from the plugin's
 * own Config values, the "Locked out now" tab and search filter, and the
 * Unlock action mirrors the plugin's own ajax_unlock semantics (lockout
 * dropped, log row marked unlocked) with a `when`-gate keeping the button
 * off rows that aren't actively locked.
 *
 * Fixture: minn_test_seed_llar (one-shot, self-clearing) overwrites the
 * limit_login_* options with one active lockout (198.51.100.7 / admin),
 * one expired, one already unlocked. LLA-R is a resident ACTIVE fixture;
 * the suite activates it if a crashed run left it off, and leaves it on.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'llar-log' );

	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		return await r.json();
	}, { path, opts } );

	// Baseline: LLA-R must be active for the shim's routes to register.
	const status = await page.evaluate( async () => {
		const id = 'limit-login-attempts-reloaded/limit-login-attempts-reloaded';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id + '?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status;
	} );
	if ( status !== 'active' ) {
		await page.evaluate( async () => {
			const id = 'limit-login-attempts-reloaded/limit-login-attempts-reloaded';
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* dropped socket — activation still lands */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	// Seed the deterministic lockout mix (one-shot flag; a dropped socket
	// still seeds server-side — poll the shim until the active row lands).
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_llar: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );
	let seeded = null;
	for ( let i = 0; i < 8; i++ ) {
		seeded = await api( 'minn-admin/v1/llar/log' ).catch( () => null );
		if ( seeded && seeded.total === 3 && seeded.items.some( ( r ) => r.status === 'locked' ) ) break;
		await page.waitForTimeout( 800 );
	}

	/* ===== Shim shape ===== */
	t.check( 'log returns the seeded rows', !! seeded && seeded.total === 3, JSON.stringify( seeded && seeded.total ) );
	const lockedRow = seeded.items.find( ( r ) => r.status === 'locked' );
	t.check( 'active lockout row carries who/ip/attempts/gateway/UTC date',
		!! lockedRow && lockedRow.who === 'admin' && lockedRow.ip === '198.51.100.7'
		&& lockedRow.attempts === 5 && lockedRow.gateway === 'Login form' && /Z$/.test( lockedRow.date ),
		JSON.stringify( lockedRow ) );
	t.check( 'all three lifecycle states present', [ 'locked', 'expired', 'unlocked' ].every( ( s ) =>
		seeded.items.some( ( r ) => r.status === s ) ) );
	const lockedOnly = await api( 'minn-admin/v1/llar/log?kind=locked' );
	t.check( 'Locked-out-now tab filters to the active lockout', lockedOnly.total === 1 && lockedOnly.items[ 0 ].ip === '198.51.100.7' );
	const searched = await api( 'minn-admin/v1/llar/log?search=shop' );
	t.check( 'search matches usernames', searched.total === 1 && searched.items[ 0 ].who === 'shop_manager' );

	/* ===== Surface in the app ===== */
	await page.goto( `${ BASE }/minn-admin/limit-login-attempts`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
	t.check( 'surface joins the activity-log family', await page.evaluate( () => {
		const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'limit-login-attempts' );
		return !! s && s.family === 'activity-log' && s.sub === 'Limit Login Attempts';
	} ) );
	const statusText = await page.$eval( '.minn-surface-status', ( el ) => el.textContent );
	t.check( 'status card reports the active lockout', /Locked out now/.test( statusText ) && statusText.includes( '1 IP' ) && statusText.includes( '198.51.100.7' ) );
	t.check( 'policy row renders from plugin config', /retries, then/.test( statusText ) );
	t.check( 'rows wear lifecycle pills', await page.$$eval( '.minn-table-row .minn-status', ( els ) => {
		const texts = els.map( ( e ) => e.textContent.trim() );
		return texts.includes( 'locked' ) && texts.includes( 'expired' ) && texts.includes( 'unlocked' );
	} ) );

	/* ===== Unlock: when-gated + mirrors the plugin's own handler ===== */
	// The expired row must NOT offer Unlock.
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => r.textContent.includes( '203.0.113.44' ) ).click();
	} );
	await page.waitForSelector( '.minn-modal', { timeout: 10000 } );
	t.check( 'expired row offers no Unlock (when-gate)', await page.evaluate( () =>
		! [ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Unlock/.test( b.textContent ) ) ) );
	await page.click( '#minn-modal-close' );

	// The locked row does; running it frees the IP.
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => r.textContent.includes( '198.51.100.7' ) ).click();
	} );
	await page.waitForSelector( '.minn-modal', { timeout: 10000 } );
	const unlockBtn = await page.evaluateHandle( () =>
		[ ...document.querySelectorAll( '.minn-modal button' ) ].find( ( b ) => /Unlock IP/.test( b.textContent ) ) );
	t.check( 'locked row offers Unlock', !! ( await unlockBtn.jsonValue !== undefined && await page.evaluate( ( b ) => !! b, unlockBtn ) ) );
	await page.evaluate( ( b ) => b.click(), unlockBtn );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => /Unlocked 198\.51\.100\.7/.test( x.textContent ) ),
	null, { timeout: 15000 } );
	t.check( 'unlock toast carries the honest message', true );

	const after = await api( 'minn-admin/v1/llar/log' );
	const freed = after.items.find( ( r ) => r.ip === '198.51.100.7' );
	t.check( 'row is unlocked in the plugin\'s own log', !! freed && freed.status === 'unlocked', JSON.stringify( freed ) );
	const stat = await api( 'minn-admin/v1/llar/status' );
	t.check( 'status card now reports nobody locked out', stat.rows[ 0 ].value === 'Nobody', JSON.stringify( stat.rows[ 0 ] ) );

	await t.done( browser, errors );
} )();
