/**
 * Sessions listing (profile modal). Austin's report: the list looked full of
 * duplicates. They are genuinely distinct WordPress session tokens (each
 * login creates one), but two problems made them look buggy:
 *   1. Minn read session_tokens raw and showed EXPIRED tokens that core
 *      (and wp-admin) filter out — dead sessions padding the list.
 *   2. The meta line showed only the day, so two same-day logins from one
 *      device were indistinguishable. It now shows a precise time.
 *
 * Seeds a known session_tokens meta on a throwaway user (two same-day active
 * logins from one IP + one expired token) and asserts the endpoint filters
 * the expired one and the two active rows render distinct times.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'sessions' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	let uid = null;
	try {
		const email = `minn_sess_${ Date.now() % 100000 }@example.com`;
		const created = await rest( 'wp/v2/users', { method: 'POST', body: JSON.stringify( {
			username: email, email, name: 'Session Test', password: 'Sess-Test-9x!', roles: [ 'subscriber' ],
		} ) } );
		uid = created.body && created.body.id;
		t.check( 'throwaway user created', !! uid, String( uid ) );

		const seeded = await rest( 'minn-admin/v1/minn-test/seed-sessions', { method: 'POST', body: JSON.stringify( { uid, mode: 'seed' } ) } );
		t.check( 'sessions seeded (2 active + 1 expired)', seeded.body && seeded.body.ok === true );

		// The endpoint must filter the expired token.
		const listed = await rest( `minn-admin/v1/users/${ uid }/sessions` );
		const sessions = ( listed.body && listed.body.sessions ) || [];
		t.check( 'endpoint returns only the 2 non-expired sessions', sessions.length === 2, String( sessions.length ) );
		t.check( 'the expired Windows token is filtered out', ! sessions.some( ( s ) => /Windows/.test( s.ua ) ), JSON.stringify( sessions.map( ( s ) => s.ua ) ) );

		// Open the profile modal for that user and read the rendered rows.
		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-user="${ uid }"]`, { timeout: 20000 } );
		await page.click( `[data-user="${ uid }"] .minn-row-title` );
		await page.waitForSelector( '#minn-uf-sessions .minn-session-row', { timeout: 10000 } );
		const rows = await page.$$eval( '#minn-uf-sessions .minn-session-row', ( els ) => els.map( ( el ) => ( {
			ua: el.querySelector( '.minn-session-ua' ).textContent.trim(),
			meta: el.querySelector( '.minn-session-meta' ).textContent.trim(),
		} ) ) );
		t.check( 'exactly two session rows render', rows.length === 2, String( rows.length ) );
		t.check( 'both rows are the macOS device', rows.every( ( r ) => /macOS/.test( r.ua ) ), JSON.stringify( rows.map( ( r ) => r.ua ) ) );
		// The two same-day sessions must be DISTINGUISHABLE (different times),
		// not identical-looking duplicates.
		t.check( 'same-device rows show distinct sign-in times', rows[ 0 ].meta !== rows[ 1 ].meta, JSON.stringify( rows.map( ( r ) => r.meta ) ) );
		t.check( 'meta line carries a precise time (has a colon in the clock)', rows.every( ( r ) => /\d:\d\d/.test( r.meta ) ), JSON.stringify( rows.map( ( r ) => r.meta ) ) );

	} finally {
		if ( uid ) {
			await rest( 'minn-admin/v1/minn-test/seed-sessions', { method: 'POST', body: JSON.stringify( { uid, mode: 'clear' } ) } ).catch( () => {} );
			await rest( `wp/v2/users/${ uid }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => {} );
		}
	}
	await t.done( browser, errors );
} )();
