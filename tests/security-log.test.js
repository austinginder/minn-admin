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
	const providerTitle = await page.evaluate( () => {
		const el = Array.from( document.querySelectorAll( '.minn-page *' ) ).find( ( n ) => /Wordfence/.test( n.textContent || '' ) && n.children.length === 0 );
		return el ? el.textContent : ( /Wordfence/.test( document.body.textContent ) ? 'Wordfence' : '' );
	} );
	t.check( 'Wordfence security log renders', /Wordfence/.test( providerTitle ) || /Failed login/.test( await page.evaluate( () => document.body.textContent ) ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
