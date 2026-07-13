/**
 * Scrutoscope profiler adapter — profiles list, detail sections, status card,
 * Cron view, and delete-through-Storage.
 *
 * Fixture: minn_test_seed_scrutoscope inserts two profiles via their
 * Storage::save_profile (session home + background slow). Scrutoscope must
 * be active (GitHub install on minnadmin).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'scrutoscope' );

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

	// Ensure Scrutoscope is active (adapter routes only register while it is).
	const plug = await page.evaluate( async () => {
		const id = 'scrutoscope/scrutoscope';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id + '?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return { ok: false, status: r.status };
		return { ok: true, status: ( await r.json() ).status };
	} );
	t.check( 'scrutoscope plugin is installed', !! plug.ok, JSON.stringify( plug ) );
	if ( plug.ok && plug.status !== 'active' ) {
		await page.evaluate( async () => {
			const id = 'scrutoscope/scrutoscope';
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + id, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* dropped socket */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	// Seed two fixture profiles (write-then-poll — flag is self-clearing).
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_scrutoscope: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );

	let list = null;
	for ( let i = 0; i < 10; i++ ) {
		list = await api( 'minn-admin/v1/scrutoscope/profiles?per_page=25' );
		if ( list.status === 200 && list.body && list.body.total >= 2
			&& ( list.body.items || [] ).some( ( r ) => /minn-fixture-home/.test( r.route ) )
			&& ( list.body.items || [] ).some( ( r ) => /minn-fixture-slow/.test( r.route ) ) ) {
			break;
		}
		await page.waitForTimeout( 700 );
	}
	t.check( 'list returns fixture profiles', !! list && list.status === 200 && list.body.total >= 2,
		JSON.stringify( list && { status: list.status, total: list.body && list.body.total } ) );

	const home = ( list.body.items || [] ).find( ( r ) => /minn-fixture-home/.test( r.route ) );
	const slow = ( list.body.items || [] ).find( ( r ) => /minn-fixture-slow/.test( r.route ) );
	t.check( 'home row has duration and session type',
		!! home && home.type === 'session' && /ms/.test( home.duration ) && home.id > 0,
		JSON.stringify( home ) );
	t.check( 'slow row is background type',
		!! slow && slow.type === 'background' && slow.role === 'anonymous',
		JSON.stringify( slow ) );

	// Detail via sectionsRoute (Scrutoscope's own /profile/{id} under the hood).
	const detail = await api( 'minn-admin/v1/scrutoscope/profiles/' + home.id );
	t.check( 'detail 200 with sections', detail.status === 200 && Array.isArray( detail.body.sections ) && detail.body.sections.length >= 2,
		JSON.stringify( detail.status ) );
	const titles = ( detail.body.sections || [] ).map( ( s ) => s.title );
	t.check( 'detail has Summary + Top sources', titles.includes( 'Summary' ) && titles.includes( 'Top sources' ), titles.join( ',' ) );
	t.check( 'detail adminUrl points at Scrutoscope',
		/tools\.php\?page=scrutoscope/.test( detail.body.adminUrl || '' ),
		detail.body.adminUrl || '' );
	const sourceSec = ( detail.body.sections || [] ).find( ( s ) => s.title === 'Top sources' );
	t.check( 'sources name minn-admin or wordpress',
		!! sourceSec && sourceSec.rows.some( ( r ) => /minn-admin|wordpress/.test( r.label ) ),
		JSON.stringify( sourceSec && sourceSec.rows ) );

	// Status card.
	const st = await api( 'minn-admin/v1/scrutoscope/status' );
	t.check( 'status card returns rows + open action',
		st.status === 200 && ( st.body.rows || [] ).length >= 3
		&& ( st.body.actions || [] ).some( ( a ) => /Scrutoscope/.test( a.label ) && a.href ),
		JSON.stringify( st.body && { rows: ( st.body.rows || [] ).length, actions: st.body.actions } ) );
	t.check( 'status reports profiles stored',
		( st.body.rows || [] ).some( ( r ) => r.label === 'Profiles stored' && parseInt( String( r.value ).replace( /\D/g, '' ), 10 ) >= 2 ),
		JSON.stringify( st.body.rows ) );

	// Cron view.
	const cron = await api( 'minn-admin/v1/scrutoscope/cron?per_page=50' );
	t.check( 'cron inventory returns events',
		cron.status === 200 && cron.body.total > 0 && ( cron.body.items || [] ).length > 0,
		JSON.stringify( cron.body && { total: cron.body.total } ) );
	t.check( 'cron rows have hook + schedule + status',
		( cron.body.items || [] ).every( ( r ) => r.hook && r.schedule && r.status ),
		JSON.stringify( ( cron.body.items || [] )[ 0 ] ) );

	// UI: open surface, status card, row open, Cron tab.
	await page.goto( BASE + '/minn-admin/scrutoscope', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-surface-status, .minn-table-row, .minn-empty', { timeout: 20000 } );
	await page.waitForTimeout( 400 );

	const ui = await page.evaluate( () => {
		const status = !! document.querySelector( '.minn-surface-status' );
		const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) ).map( ( r ) => r.textContent );
		const hasHome = rows.some( ( t ) => /minn-fixture-home/.test( t ) );
		const openScruto = Array.from( document.querySelectorAll( '.minn-sstat-actions a, .minn-sstat-actions button, a' ) )
			.some( ( el ) => /Scrutoscope/.test( el.textContent ) );
		const views = Array.from( document.querySelectorAll( '.minn-view-tab, [data-sview], .minn-surface-views button, .minn-tabs button' ) )
			.map( ( b ) => b.textContent.trim() );
		return { status, hasHome, openScruto, views, rowCount: rows.length };
	} );
	t.check( 'UI status card renders', ui.status, JSON.stringify( ui ) );
	t.check( 'UI lists fixture home route', ui.hasHome, JSON.stringify( ui ) );

	// Open detail modal from the home row.
	const opened = await page.evaluate( () => {
		const row = Array.from( document.querySelectorAll( '.minn-table-row' ) )
			.find( ( r ) => /minn-fixture-home/.test( r.textContent ) );
		if ( ! row ) return false;
		row.click();
		return true;
	} );
	t.check( 'clicked fixture row', opened, '' );
	if ( opened ) {
		await page.waitForSelector( '.minn-modal [data-saction], .minn-modal .minn-ssec, .minn-modal .minn-modal-body', { timeout: 10000 } ).catch( () => null );
		await page.waitForTimeout( 300 );
		const modal = await page.evaluate( () => {
			const m = document.querySelector( '.minn-modal' );
			if ( ! m ) return { open: false };
			const text = m.textContent || '';
			return {
				open: true,
				hasSources: /Top sources|minn-admin|wordpress/.test( text ),
				hasDuration: /ms/.test( text ),
			};
		} );
		t.check( 'detail modal shows sources/duration', modal.open && modal.hasSources && modal.hasDuration, JSON.stringify( modal ) );
		// Close modal.
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );
	}

	// Cron view switcher (views[] → data-sview="x0").
	const switcher = await page.$$eval( '.minn-view-switch [data-sview]', ( els ) =>
		els.map( ( e ) => e.dataset.sview + ':' + e.textContent.trim() ) );
	t.check( 'switcher has Profiles + Cron',
		switcher.includes( 'main:Profiles' ) && switcher.includes( 'x0:Cron' ),
		switcher.join( ' · ' ) );
	await page.click( '[data-sview="x0"]' );
	await page.waitForTimeout( 800 );
	const cronUi = await page.evaluate( () => {
		const rows = document.querySelectorAll( '.minn-table-row' );
		const statusGone = ! document.querySelector( '.minn-surface-status' );
		return { n: rows.length, statusGone, sample: rows[ 0 ] ? rows[ 0 ].textContent.slice( 0, 120 ) : '' };
	} );
	t.check( 'Cron view lists events (status card hidden)',
		cronUi.n > 0 && cronUi.statusGone, JSON.stringify( cronUi ) );

	// Delete the slow fixture through the shim (Storage::delete_profile).
	const del = await api( 'minn-admin/v1/scrutoscope/profiles/' + slow.id, { method: 'DELETE' } );
	t.check( 'delete profile ok', del.status === 200 && del.body && del.body.ok, JSON.stringify( del ) );
	const after = await api( 'minn-admin/v1/scrutoscope/profiles?search=minn-fixture-slow' );
	t.check( 'deleted profile gone from list',
		after.status === 200 && ( after.body.items || [] ).every( ( r ) => r.id !== slow.id ),
		JSON.stringify( after.body && after.body.items ) );

	// Background tab filters to background type.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_scrutoscope: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );
	await page.waitForTimeout( 900 );
	const bg = await api( 'minn-admin/v1/scrutoscope/profiles?kind=background' );
	t.check( 'background tab only background rows',
		bg.status === 200 && ( bg.body.items || [] ).length > 0
		&& ( bg.body.items || [] ).every( ( r ) => r.type === 'background' ),
		JSON.stringify( bg.body && { total: bg.body.total, types: ( bg.body.items || [] ).map( ( r ) => r.type ) } ) );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
