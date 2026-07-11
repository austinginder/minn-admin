/**
 * Security activity log (Wordfence) — the login-security member of the
 * Activity Log family. Reads {prefix}wfLogins: failed/successful logins,
 * usernames, decoded IPs. WSAL is the dev site's resident activity-log
 * provider, so this suite verifies Wordfence via its REST shim + surface
 * without disturbing the family default.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'security-log' );

	await login( page );

	const api = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return await r.json();
	}, path );

	// SEED THE PLUGIN BASELINE (rule: Austin toggles plugins live; the dev
	// convention keeps Wordfence DEACTIVATED so WSAL stays the resident
	// activity-log provider). The shim's routes only register while
	// Wordfence is active, so activate it for the run and restore after.
	const setWordfence = ( status ) => page.evaluate( async ( s ) => {
		try {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/wordfence/wordfence', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { status: s } ),
			} );
			return ( await r.json() ).status;
		} catch ( e ) {
			return 'dropped'; // plugin toggles can recycle the worker
		}
	}, status );
	const wasActive = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/wordfence/wordfence?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status === 'active';
	} );
	if ( ! wasActive ) {
		await setWordfence( 'active' );
		// Wordfence activation is heavy (tables, config, cron) — settle, then
		// reload so the app boots with the surface present.
		await page.waitForTimeout( 1500 );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 800 );
	}

	try {
	// Seed a deterministic login mix (fixture; idempotent). The insert can
	// recycle the PHP worker mid-response, dropping the socket even on
	// success — tolerate the TypeError, then poll the log until data lands.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_wordfence: String( Date.now() ) } ),
			} );
		} catch ( e ) { /* dropped socket — the seed still ran server-side */ }
	} );
	for ( let i = 0; i < 6; i++ ) {
		const s = await api( 'minn-admin/v1/wordfence/logins' ).catch( () => ( { total: 0 } ) );
		if ( s.total > 0 ) break;
		await page.waitForTimeout( 800 );
	}

	const all = await api( 'minn-admin/v1/wordfence/logins' );
	t.check( 'Login log returns {items,total}', Array.isArray( all.items ) && typeof all.total === 'number' && all.total > 0, `total=${ all.total }` );
	const row = all.items[ 0 ];
	t.check( 'Rows carry message/who/ip/result/date', !! row && !! row.message && !! row.who && !! row.ip && ( row.result === 'failed' || row.result === 'success' ) && /Z$/.test( row.date ), JSON.stringify( row ) );
	t.check( 'IPs are decoded (not binary)', all.items.every( ( r ) => r.ip === '—' || /[.:]/.test( r.ip ) ) );

	const failed = await api( 'minn-admin/v1/wordfence/logins?kind=failed' );
	const success = await api( 'minn-admin/v1/wordfence/logins?kind=success' );
	t.check( 'Failed filter returns only failures', failed.items.every( ( r ) => r.result === 'failed' ) && failed.total > 0, `failed=${ failed.total }` );
	t.check( 'Success filter returns only successes', success.items.every( ( r ) => r.result === 'success' ) );
	t.check( 'Filters partition the full set', failed.total + success.total === all.total, `${ failed.total }+${ success.total } vs ${ all.total }` );

	// Search against a username actually present in the log.
	const term = ( all.items.find( ( r ) => r.who && r.who !== '—' ) || {} ).who || 'admin';
	const search = await api( 'minn-admin/v1/wordfence/logins?search=' + encodeURIComponent( term ) );
	t.check( 'Search matches by username', search.total >= 1 && search.items.every( ( r ) => new RegExp( term, 'i' ).test( r.who ) ), `term=${ term } n=${ search.total }` );

	// --- Surface UI ---------------------------------------------------------
	await page.goto( BASE + '/minn-admin/wordfence', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction(
		() => /Failed login|Signed in/.test( document.body.textContent ),
		null, { timeout: 15000 }
	);
	// Real successful logins accumulate in wfLogins across runs and push the
	// seeded failures off page 1 — assert through the Failed tab instead of
	// hoping a failure row lands on the first page (totals grow; rule 46).
	await page.click( '[data-stab="failed"]' );
	await page.waitForFunction(
		() => /Failed login/.test( document.body.textContent ),
		null, { timeout: 15000 }
	);
	t.check( 'Wordfence security log renders (failed view)', true );
	} finally {
		// Restore the dev-site baseline: Wordfence stays deactivated so WSAL
		// remains the resident activity-log provider.
		if ( ! wasActive ) {
			await setWordfence( 'inactive' ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
