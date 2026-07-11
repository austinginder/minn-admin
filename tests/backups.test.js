/**
 * Backups surface (UpdraftPlus) — history list, status endpoint, System
 * health check, palette command, and a REAL database backup triggered
 * through UpdraftPlus's own cron machinery (retention prunes old sets, so
 * repeated runs don't accumulate).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'backups' );

	await login( page );

	const api = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: a.body,
		} );
		return await r.json();
	}, { path: opts.method ? path : path, method: opts.method, body: opts.body } );

	// --- Status + history ---------------------------------------------------
	const status = await api( 'minn-admin/v1/updraft/status' );
	t.check( 'Status endpoint reports last/running/history', status && 'last' in status && 'running' in status && status.history >= 1, JSON.stringify( status ) );
	const baseline = status.history;
	// Retention (updraft_retain=2 per set type) saturates on the dev site,
	// so a NEW backup prunes an OLD set and the set COUNT stays flat — the
	// completion check tracks the newest backup's timestamp instead
	// (rule-46 class: totals drift).
	const baselineTime = ( status.last && status.last.time ) || 0;

	const list = await api( 'minn-admin/v1/updraft/backups' );
	t.check( 'History list shape', Array.isArray( list.items ) && list.total === baseline );
	const first = list.items[ 0 ];
	t.check( 'Rows carry components/size/where/date', !! first && !! first.components && !! first.size && !! first.where && /Z$/.test( first.date ), JSON.stringify( first ) );

	// --- System health check ------------------------------------------------
	const checks = ( await api( 'minn-admin/v1/system' ) ).checks;
	const bk = checks.find( ( c ) => c.label === 'Backups' );
	t.check( 'System check reports fresh backup', !! bk && bk.status === 'pass', JSON.stringify( bk ) );

	// --- Surface UI -----------------------------------------------------------
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && document.querySelector( '.minn-sidebar' ), null, { timeout: 15000 } );
	const nav = await page.evaluate( () => {
		const btn = Array.from( document.querySelectorAll( '.minn-nav-btn' ) ).find( ( b ) => b.textContent.includes( 'Backups' ) );
		if ( btn ) btn.click();
		return !! btn;
	} );
	t.check( 'Backups appears in the nav', nav );
	await page.waitForFunction( () => /Database|Plugins/.test( document.body.textContent ) && /local/.test( document.body.textContent ), null, { timeout: 15000 } );
	t.check( 'Backup sets render in the list', true );

	// --- Palette command ------------------------------------------------------
	await page.keyboard.press( 'Meta+k' );
	await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
	await page.type( '#minn-palette-input', 'back up' );
	await page.waitForTimeout( 300 );
	const entry = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-palette-item .minn-palette-label' ) )
			.map( ( e ) => e.textContent ).find( ( x ) => /Back up site now/.test( x ) ) || '' );
	t.check( 'Palette offers Back up site now', entry.includes( 'UpdraftPlus' ), entry );
	await page.keyboard.press( 'Escape' );

	// --- Real database backup through the trigger -----------------------------
	const started = await api( 'minn-admin/v1/updraft/backup-now', { method: 'POST', body: JSON.stringify( { what: 'db' } ) } );
	t.check( 'Backup-now accepts a db-only run', started.started === true && started.what === 'db' );
	let after = baselineTime;
	const t0 = Date.now();
	while ( Date.now() - t0 < 120000 ) {
		await page.waitForTimeout( 8000 );
		// Nudge cron along; UpdraftPlus resumes itself but a kick is cheap.
		await page.evaluate( () => fetch( '/wp-cron.php?doing_wp_cron', { credentials: 'omit' } ).catch( () => {} ) );
		const s = await api( 'minn-admin/v1/updraft/status' );
		if ( s.last && s.last.time > baselineTime && ! s.running ) { after = s.last.time; break; }
	}
	t.check( 'A new backup set completed', after > baselineTime, `baseline=${ baselineTime } after=${ after }` );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
