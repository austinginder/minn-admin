/**
 * WP Crontrol adapter — cron event list, status card, pause/resume, run-now,
 * delete through Crontrol\Event APIs.
 *
 * Fixture: minn_test_seed_crontrol schedules minn_crontrol_fixture_hook
 * (one-off, ~1h out). WP Crontrol must be active.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'wp-crontrol' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	// Ensure WP Crontrol is active.
	const plug = await page.evaluate( async () => {
		const id = 'wp-crontrol/wp-crontrol';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id + '?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return { ok: false, status: r.status };
		return { ok: true, status: ( await r.json() ).status };
	} );
	t.check( 'wp-crontrol plugin is installed', !! plug.ok, JSON.stringify( plug ) );
	if ( plug.ok && plug.status !== 'active' ) {
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/wp-crontrol/wp-crontrol', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* ignore */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	// Seed disposable one-off.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_crontrol: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );

	let fixture = null;
	for ( let i = 0; i < 10; i++ ) {
		const list = await api( 'minn-admin/v1/crontrol/events?search=minn_crontrol_fixture_hook&per_page=25' );
		if ( list.status === 200 && list.body && ( list.body.items || [] ).length ) {
			fixture = list.body.items.find( ( r ) => r.hook === 'minn_crontrol_fixture_hook' ) || list.body.items[ 0 ];
			if ( fixture ) break;
		}
		await page.waitForTimeout( 700 );
	}
	t.check( 'fixture event listed', !! fixture && !! fixture.id, JSON.stringify( fixture ) );

	// Status card.
	const st = await api( 'minn-admin/v1/crontrol/status' );
	t.check( 'status card has events + open action',
		st.status === 200
		&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Events' )
		&& ( st.body.actions || [] ).some( ( a ) => /WP Crontrol/.test( a.label ) && a.href ),
		JSON.stringify( st.body && { rows: st.body.rows, actions: st.body.actions } ) );

	// Full list returns real cron population.
	const all = await api( 'minn-admin/v1/crontrol/events?per_page=50' );
	t.check( 'events list populated',
		all.status === 200 && all.body.total >= 1 && ( all.body.items || [] ).length >= 1,
		JSON.stringify( all.body && { total: all.body.total } ) );
	t.check( 'rows have hook/schedule/status/date',
		( all.body.items || [] ).every( ( r ) => r.hook && r.schedule && r.status && r.date ),
		JSON.stringify( ( all.body.items || [] )[ 0 ] ) );

	// Detail.
	const detail = await api( 'minn-admin/v1/crontrol/events/' + encodeURIComponent( fixture.id ) );
	t.check( 'detail 200 with sections',
		detail.status === 200 && Array.isArray( detail.body.sections ) && detail.body.sections.length >= 1,
		JSON.stringify( detail.status ) );
	t.check( 'detail title is fixture hook',
		detail.body.title === 'minn_crontrol_fixture_hook',
		detail.body.title || '' );
	t.check( 'detail adminUrl points at WP Crontrol',
		/tools\.php\?page=wp-crontrol/.test( detail.body.adminUrl || '' ),
		detail.body.adminUrl || '' );

	// Pause / resume fixture hook (hook-level, not single event).
	const paused = await api( 'minn-admin/v1/crontrol/events/' + encodeURIComponent( fixture.id ) + '/pause', { method: 'POST' } );
	t.check( 'pause ok', paused.status === 200 && paused.body && paused.body.ok, JSON.stringify( paused ) );
	let afterPause = null;
	for ( let i = 0; i < 6; i++ ) {
		const list = await api( 'minn-admin/v1/crontrol/events?search=minn_crontrol_fixture_hook' );
		afterPause = ( list.body && list.body.items || [] ).find( ( r ) => r.hook === 'minn_crontrol_fixture_hook' );
		if ( afterPause && afterPause.paused ) break;
		await page.waitForTimeout( 400 );
	}
	t.check( 'fixture shows paused after pause',
		!! afterPause && afterPause.paused && afterPause.status === 'paused',
		JSON.stringify( afterPause ) );

	const resumed = await api( 'minn-admin/v1/crontrol/events/' + encodeURIComponent( fixture.id ) + '/resume', { method: 'POST' } );
	t.check( 'resume ok', resumed.status === 200 && resumed.body && resumed.body.ok, JSON.stringify( resumed ) );
	let afterResume = null;
	for ( let i = 0; i < 6; i++ ) {
		const list = await api( 'minn-admin/v1/crontrol/events?search=minn_crontrol_fixture_hook' );
		afterResume = ( list.body && list.body.items || [] ).find( ( r ) => r.hook === 'minn_crontrol_fixture_hook' );
		if ( afterResume && ! afterResume.paused ) break;
		await page.waitForTimeout( 400 );
	}
	t.check( 'fixture unpaused after resume',
		!! afterResume && ! afterResume.paused,
		JSON.stringify( afterResume ) );

	// Run now (spawns cron; fixture has no callbacks — safe).
	const run = await api( 'minn-admin/v1/crontrol/events/' + encodeURIComponent( fixture.id ) + '/run', { method: 'POST' } );
	t.check( 'run now accepted',
		run.status === 200 && run.body && run.body.ok,
		JSON.stringify( run ) );

	// Re-seed if run consumed the one-off, then delete cleanly.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_crontrol: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );
	await page.waitForTimeout( 900 );
	let toDelete = null;
	for ( let i = 0; i < 8; i++ ) {
		const list = await api( 'minn-admin/v1/crontrol/events?search=minn_crontrol_fixture_hook' );
		toDelete = ( list.body && list.body.items || [] ).find( ( r ) => r.hook === 'minn_crontrol_fixture_hook' );
		if ( toDelete ) break;
		await page.waitForTimeout( 500 );
	}
	t.check( 'fixture present for delete', !! toDelete, JSON.stringify( toDelete ) );
	if ( toDelete ) {
		const del = await api( 'minn-admin/v1/crontrol/events/' + encodeURIComponent( toDelete.id ), { method: 'DELETE' } );
		t.check( 'delete ok', del.status === 200 && del.body && del.body.ok, JSON.stringify( del ) );
		const gone = await api( 'minn-admin/v1/crontrol/events?search=minn_crontrol_fixture_hook' );
		t.check( 'fixture gone after delete',
			gone.status === 200 && ( gone.body.items || [] ).every( ( r ) => r.id !== toDelete.id ),
			JSON.stringify( gone.body && gone.body.items ) );
	}

	// UI smoke.
	await page.goto( BASE + '/minn-admin/wp-crontrol', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-surface-status, .minn-table-row', { timeout: 20000 } );
	await page.waitForTimeout( 400 );
	const ui = await page.evaluate( () => {
		const status = !! document.querySelector( '.minn-surface-status' );
		const rows = document.querySelectorAll( '.minn-table-row' ).length;
		const open = Array.from( document.querySelectorAll( 'a, button' ) )
			.some( ( el ) => /WP Crontrol/.test( el.textContent || '' ) );
		return { status, rows, open };
	} );
	t.check( 'UI status card renders', ui.status, JSON.stringify( ui ) );
	t.check( 'UI lists cron rows', ui.rows > 0, JSON.stringify( ui ) );

	// Tabs include Overdue.
	const tabs = await page.$$eval( '[data-stab]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	t.check( 'filter tabs include Overdue', tabs.some( ( x ) => /Overdue/i.test( x ) ), tabs.join( ' · ' ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
