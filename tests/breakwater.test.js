/**
 * Breakwater adapter — its log as a Forms surface. The list reads
 * {prefix}breakwater_log through the kernel (Spam / Held / Clean / All, a
 * tab per integration), a row's detail shows what was sent and why it
 * scored, "Not spam" is Breakwater's release (the held comment leaves the
 * spam folder), "Was spam" marks a clean row, the mode saves through the
 * settings tier, and Settings → Spam shows the card. Editors get none of it
 * (Breakwater's own rule is manage_options). The comment's own status is not
 * read: Disable Comments turns the comments REST API off on the lab.
 *
 * Fixtures: Breakwater active in monitor mode + minn_test_seed_breakwater
 * (a held comment, a logged spam comment and a clean row, re-made each arm).
 */
const { launch, login, loginAs, reporter, BASE, openEntry, entryAction, entryText, leaveEntry, listSettled } = require( './helpers' );

( async () => {
	const t = reporter( 'breakwater' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path + ( a.path.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method || 'GET', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, json: await r.json().catch( () => null ) };
	}, { path, ...opts } );

	const seed = async () => {
		await api( 'wp/v2/settings', { method: 'POST', body: { minn_test_seed_breakwater: '1' } } );
		await api( 'wp/v2/settings' ); // the next request seeds
		const held = await api( 'minn-admin/v1/breakwater/log?status=held' );
		return held.json && held.json.items.some( ( r ) => /dana\.breakwater/.test( r.summary ) );
	};


	try {
		t.check( 'fixture seeded a held comment', await seed() );

		await page.goto( BASE + '/minn-admin/breakwater', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 30000 } );

		/* ===== List ===== */
		const body = await page.$eval( '#minn-view', ( el ) => el.textContent );
		t.check( 'spam rows render with sender and message', body.includes( 'dana.breakwater@example.com' ) && body.includes( 'Buy backlinks' ) );
		t.check( 'clean rows stay out of the Spam view', ! body.includes( 'robin.breakwater@example.com' ) );
		t.check( 'rows say where they stand', body.includes( 'Held' ) && body.includes( 'Spam, logged' ) );
		const filters = await page.$$eval( '[data-sfilter]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'filter offers Spam, Held, Clean and All', [ 'Spam', 'Held', 'Clean', 'All' ].every( ( f ) => filters.includes( f ) ), filters.join( ' · ' ) );
		const tabs = await page.$$eval( '[data-stab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'a tab per integration that logged', tabs.includes( 'Comments' ), tabs.join( ' · ' ) );

		await page.click( '[data-sfilter="held"]' );
		await page.waitForFunction( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			return rows.length && rows.every( ( r ) => ! r.textContent.includes( 'Buy backlinks' ) );
		}, { timeout: 20000 } );
		t.check( 'Held lists only what waits on a decision', true );

		/* ===== Detail and Not spam ===== */
		await openEntry( page, 'dana.breakwater' );
		const entry = await entryText( page );
		t.check( 'detail shows what was sent', entry.includes( 'Dana Tester' ) && entry.includes( 'wanted to say thanks' ) );
		t.check( 'detail says why it scored', /no browser proof/i.test( entry ) && /6 points/.test( entry ), entry.slice( 0, 300 ) );
		await entryAction( page, 'Not spam' );
		const toast = await page.$eval( '.minn-toast', ( el ) => el.textContent ).catch( () => '' );
		t.check( 'Not spam answers with what Breakwater did', /pending comments|approved/i.test( toast ), toast );
		const again = await api( 'minn-admin/v1/breakwater/log?status=held' );
		t.check( 'nothing is held any more', again.json && again.json.total === 0 );
		const row = ( await api( 'minn-admin/v1/breakwater/log?status=spam' ) ).json.items.find( ( r ) => /dana\.breakwater/.test( r.summary ) );
		const kept = ( await api( 'minn-admin/v1/breakwater/log/' + row.id ) ).json;
		t.check( 'the row keeps what Breakwater did', JSON.stringify( kept ).includes( 'pending comments' ), JSON.stringify( kept ).slice( 0, 300 ) );
		const twice = await api( 'minn-admin/v1/breakwater/log/' + row.id + '/release', { method: 'POST' } );
		t.check( 'a second release is refused', twice.status === 400 && twice.json.code === 'not_waiting', JSON.stringify( twice.json ) );
		await leaveEntry( page );

		/* ===== Was spam ===== */
		await page.click( '[data-sfilter="clean"]' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-table-row' ) ].some( ( r ) => r.textContent.includes( 'robin.breakwater' ) ), { timeout: 20000 } );
		await openEntry( page, 'robin.breakwater' );
		await entryAction( page, 'Was spam' );
		const clean = ( await api( 'minn-admin/v1/breakwater/log?status=clean' ) ).json.items.find( ( r ) => /robin\.breakwater/.test( r.summary ) );
		t.check( 'Was spam marks the clean row', clean && clean.state === 'was_spam', clean ? clean.state : '(gone)' );
		await leaveEntry( page );

		/* ===== Settings tab: the mode ===== */
		const loaded = ( await api( 'minn-admin/v1/breakwater/settings/general' ) ).json;
		t.check( 'settings serve the mode', loaded && loaded.values && loaded.values.mode === 'monitor' );
		const saved = await api( 'minn-admin/v1/breakwater/settings/general', { method: 'POST', body: { values: { mode: 'protect' }, base: { mode: 'monitor' } } } );
		t.check( 'a mode save goes through Breakwater', saved.status === 200 && saved.json.values.mode === 'protect', JSON.stringify( saved.json ) );
		const stale = await api( 'minn-admin/v1/breakwater/settings/general', { method: 'POST', body: { values: { mode: 'off' }, base: { mode: 'monitor' } } } );
		t.check( 'a stale mode save asks for a reload', stale.status === 409 );
		await api( 'minn-admin/v1/breakwater/settings/general', { method: 'POST', body: { values: { mode: 'monitor' }, base: { mode: 'protect' } } } );

		/* ===== Settings → Spam card ===== */
		const spam = ( await api( 'minn-admin/v1/spam' ) ).json;
		const card = spam && spam.providers.find( ( p ) => p.id === 'breakwater' );
		t.check( 'Settings → Spam shows Breakwater', !! card && card.configured && /Monitor only/.test( card.note ), JSON.stringify( card ) );

		/* ===== An Editor gets none of it ===== */
		const { ctx: edCtx, page: ed } = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const edRead = await ed.evaluate( async () => ( await fetch( window.MINN.restUrl + 'minn-admin/v1/breakwater/log', { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ) ).status );
		const edWrite = await ed.evaluate( async ( id ) => ( await fetch( window.MINN.restUrl + 'minn-admin/v1/breakwater/log/' + id + '/release', { method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ) ).status, row.id );
		t.check( 'an Editor cannot read or release', 403 === edRead && 403 === edWrite, edRead + ' / ' + edWrite );
		await edCtx.close();
	} finally {
		await seed().catch( () => {} ); // leave a held row for the next run
	}

	await t.done( browser, errors );
} )();
