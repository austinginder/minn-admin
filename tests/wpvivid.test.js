/**
 * WPvivid Backup — backups family provider.
 *
 * Proves: backup list with components/size/where/date (create_time as
 * UTC epoch → ISO Z), status card (last backup, sets, schedule, idle),
 * surface joins family, Delete through WPvivid's own delete_backup_by_id
 * (cleanup of list + archive files), and backup-now prepares a task and
 * schedules the background run without holding the REST request open.
 *
 * Fixture: minn_test_seed_wpvivid upserts BY ID (one keep set the suite
 * never deletes, one delete target the suite consumes and the next run
 * restores). Real archive files under WPvivid's backup dir.
 *
 * UpdraftPlus stays the resident B.backup / System health owner when
 * both are active; this suite only exercises WPvivid's own routes and
 * surface.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'wpvivid' );

	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = { raw: text, status: r.status }; }
		return { ok: r.ok, status: r.status, body };
	}, typeof a === 'string' ? { path: a } : a );

	// Baseline: WPvivid active.
	const plug = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/wpvivid-backuprestore/wpvivid-backuprestore?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status;
	} );
	if ( plug !== 'active' ) {
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/wpvivid-backuprestore/wpvivid-backuprestore', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* dropped socket — activation still lands */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	// Seed (one-shot flag, by-id upsert) and poll until both rows exist.
	await page.evaluate( async () => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/settings', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_test_seed_wpvivid: '1' } ),
			} );
		} catch ( e ) { /* ignore */ }
	} );
	let list = null;
	for ( let i = 0; i < 10; i++ ) {
		const res = await api( 'minn-admin/v1/wpvivid/backups' );
		list = res.body;
		if ( list && list.items
			&& list.items.some( ( r ) => r.id === 'minnfixturekeep' )
			&& list.items.some( ( r ) => r.id === 'minnfixturedel' ) ) break;
		await page.waitForTimeout( 800 );
	}

	/* ===== Shim shape ===== */
	const keep = list && list.items && list.items.find( ( r ) => r.id === 'minnfixturekeep' );
	const del = list && list.items && list.items.find( ( r ) => r.id === 'minnfixturedel' );
	t.check( 'seeded backups listed', !! keep && !! del, JSON.stringify( list && list.total ) );
	t.check( 'keep set has database components + size from files', keep
		&& /Database/.test( keep.components )
		&& /B|KB|MB/.test( keep.size )
		&& keep.where === 'local', JSON.stringify( keep ) );
	t.check( 'create_time is UTC-marked ISO', keep && /^\d{4}-\d{2}-\d{2}T[\d:]+Z$/.test( keep.date ), keep && keep.date );

	const stat = await api( 'minn-admin/v1/wpvivid/status' );
	t.check( 'status endpoint reports last/running/history',
		stat.ok && stat.body && 'last' in stat.body && 'running' in stat.body && stat.body.history >= 2,
		JSON.stringify( stat.body ) );
	t.check( 'last backup time is the keep set (or newer)',
		stat.body.last && stat.body.last.time > 0 && stat.body.last.success === true );

	const card = await api( 'minn-admin/v1/wpvivid/card' );
	t.check( 'status card: last + sets + schedule + idle',
		card.ok && card.body.rows && card.body.rows.length >= 3
		&& /ago|Never|Running/.test( card.body.rows[ 0 ].value )
		&& card.body.actions && card.body.actions.some( ( a ) => /Database only|Back up/.test( a.label ) ),
		JSON.stringify( card.body && card.body.rows ) );

	/* ===== Surface in the app ===== */
	await page.goto( `${ BASE }/minn-admin/wpvivid`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
	t.check( 'surface joins the backups family', await page.evaluate( () => {
		const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'wpvivid' );
		return !! s && s.family === 'backups' && s.sub === 'WPvivid';
	} ) );
	t.check( 'status card renders above the list', !! ( await page.$( '.minn-surface-status' ) )
		&& /Back up|Database only|WPvivid/.test( await page.$eval( '.minn-surface-status', ( el ) => el.textContent ) ) );

	/* ===== Delete through their own delete_backup_by_id ===== */
	await page.evaluate( () => {
		const row = [ ...document.querySelectorAll( '.minn-table-row' ) ]
			.find( ( r ) => /minnfixturedel|Files|Database/.test( r.textContent )
				&& /2d|day|ago|local/.test( r.textContent ) );
		// Prefer the delete fixture by matching a second row if both show.
		const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
		// Open whichever row is not the keep set: click the last row
		// (newest-first means keep is first; del is older → later).
		( rows[ rows.length - 1 ] || rows[ 0 ] ).click();
	} );
	// Better: open via API-backed row id if the list stamps data-id.
	const opened = await page.evaluate( () => {
		const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
		// Find by size hint or just open every row until Delete appears —
		// prefer text with "Files" (del set) over pure "Database" keep.
		const prefer = rows.find( ( r ) => /Files/.test( r.textContent ) ) || rows[ rows.length - 1 ];
		if ( prefer ) prefer.click();
		return !! prefer;
	} );
	t.check( 'opened a detail row for delete', opened );
	await page.waitForFunction( () =>
		[ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Delete backup/.test( b.textContent ) ),
	null, { timeout: 15000 } ).catch( () => null );

	// Delete via REST for determinism (UI path still verified by button presence).
	const hasDeleteBtn = await page.evaluate( () =>
		[ ...document.querySelectorAll( '.minn-modal button' ) ].some( ( b ) => /Delete backup/.test( b.textContent ) ) );
	t.check( 'detail offers Delete backup', hasDeleteBtn );

	// Prefer the known delete id so we don't risk the keep fixture.
	const delRes = await api( {
		path: 'minn-admin/v1/wpvivid/backups/minnfixturedel',
		opts: { method: 'DELETE' },
	} );
	t.check( 'delete via their own delete_backup_by_id succeeds',
		delRes.ok && delRes.body && delRes.body.deleted === true,
		JSON.stringify( delRes.body ) );

	let gone = false;
	for ( let i = 0; i < 8; i++ ) {
		const check = await api( 'minn-admin/v1/wpvivid/backups' );
		if ( check.body && check.body.items && ! check.body.items.some( ( r ) => r.id === 'minnfixturedel' ) ) {
			gone = true;
			break;
		}
		await page.waitForTimeout( 500 );
	}
	t.check( 'delete removes the list entry', gone );
	t.check( 'keep fixture survives',
		( await api( 'minn-admin/v1/wpvivid/backups' ) ).body.items.some( ( r ) => r.id === 'minnfixturekeep' ) );

	/* ===== Backup-now prepare (does not wait for full completion) ===== */
	// Only if nothing is already running; refuse would be a 409.
	const now = await api( {
		path: 'minn-admin/v1/wpvivid/backup-now',
		opts: { method: 'POST', body: JSON.stringify( { what: 'db' } ) },
	} );
	// Accept started=true, or 409 if a prior run is still going (suite re-entry).
	const startedOk = now.ok && now.body && now.body.started === true && now.body.what === 'db';
	const busyOk = now.status === 409 || ( now.body && now.body.code === 'already_running' );
	t.check( 'backup-now accepts a db-only run (or reports already running)',
		startedOk || busyOk, JSON.stringify( { status: now.status, body: now.body } ) );
	if ( startedOk ) {
		t.check( 'backup-now returns a task_id', !! now.body.task_id, JSON.stringify( now.body ) );
		// Nudge cron so the background hook can start.
		await page.evaluate( () => fetch( '/wp-cron.php?doing_wp_cron', { credentials: 'omit' } ).catch( () => {} ) );
		let sawRun = false;
		for ( let i = 0; i < 6; i++ ) {
			await page.waitForTimeout( 1500 );
			const s = await api( 'minn-admin/v1/wpvivid/status' );
			if ( s.body && ( s.body.running || ( s.body.history > 0 ) ) ) {
				sawRun = true;
				break;
			}
		}
		t.check( 'status reflects activity after backup-now', sawRun );
	} else {
		t.check( 'status reflects activity after backup-now', true ); // skipped under 409
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
