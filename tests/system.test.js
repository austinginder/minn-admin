/**
 * System diagnostics page: the minn-admin/v1/system endpoint shape, the
 * rendered health strip + group cards + largest-tables, and copy-report.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const fs = require( 'fs' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'system' );
	await login( page );
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );

	/* ===== Endpoint ===== */
	const api = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/system', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return { status: r.status, body: await r.json() };
	} );
	t.check( 'endpoint returns 200', api.status === 200, String( api.status ) );
	const groups = ( api.body.groups || [] ).map( ( g ) => g.title );
	t.check( 'has WordPress/PHP/Database/Server groups', [ 'WordPress', 'PHP', 'Database', 'Server' ].every( ( g ) => groups.includes( g ) ), JSON.stringify( groups ) );
	t.check( 'checks carry a status of pass/warn/fail', ( api.body.checks || [] ).length > 0 && api.body.checks.every( ( c ) => [ 'pass', 'warn', 'fail' ].includes( c.status ) ), JSON.stringify( ( api.body.checks || [] ).map( ( c ) => c.status ) ) );
	const db = ( api.body.groups || [] ).find( ( g ) => g.title === 'Database' );
	t.check( 'database group carries largest-tables', !! db && Array.isArray( db.tables ) && db.tables.length > 0, JSON.stringify( db && db.tables && db.tables.length ) );
	const phpRow = ( api.body.groups || [] ).find( ( g ) => g.title === 'PHP' ).rows.find( ( r ) => r.key === 'Version' );
	t.check( 'PHP version is present', !! phpRow && /^\d+\.\d+/.test( phpRow.value ), phpRow && phpRow.value );

	/* ===== Rendered page ===== */
	await page.goto( `${ BASE }/minn-admin/system`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-sys-grid', { timeout: 15000 } );
	await page.waitForTimeout( 500 );
	const ui = await page.evaluate( () => ( {
		checks: document.querySelectorAll( '.minn-sys-check' ).length,
		cards: document.querySelectorAll( '.minn-sys-card' ).length,
		pills: document.querySelectorAll( '.minn-sys-pill' ).length,
		tableRows: document.querySelectorAll( '.minn-sys-trow' ).length,
		hasHealthy: !! Array.from( document.querySelectorAll( '.minn-sys-pill' ) ).find( ( p ) => /healthy/.test( p.textContent ) ),
	} ) );
	t.check( 'health strip renders every check', ui.checks === ( api.body.checks || [] ).length && ui.checks > 0, JSON.stringify( ui ) );
	t.check( 'four group cards render', ui.cards === 4, String( ui.cards ) );
	t.check( 'summary pills + largest-tables render', ui.pills > 0 && ui.hasHealthy && ui.tableRows > 0, JSON.stringify( ui ) );

	/* ===== Nav item + copy report ===== */
	t.check( 'System nav item present', !! ( await page.$( '.minn-nav-btn[data-nav="system"]' ) ) );
	await page.click( '#minn-sys-copy' );
	await page.waitForTimeout( 400 );
	const clip = await page.evaluate( () => navigator.clipboard.readText() );
	t.check( 'copy report writes a markdown system report', /^# System report/.test( clip ) && /## PHP/.test( clip ) && /## Database/.test( clip ), clip.slice( 0, 50 ) );

	/* ===== Debug tools (wp-config toggles) ===== */
	const cfg = api.body.config;
	t.check( 'config block carries editable + constants', !! cfg && Array.isArray( cfg.constants ) && cfg.constants.length >= 4, JSON.stringify( cfg && { editable: cfg.editable, n: cfg.constants && cfg.constants.length } ) );
	t.check( 'constants carry name/value/locked shape', ( cfg.constants || [] ).every( ( c ) => typeof c.name === 'string' && typeof c.value === 'boolean' && typeof c.locked === 'boolean' ), '' );

	// The endpoint must reject anything off the whitelist.
	const reject = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/system/config', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, body: JSON.stringify( { constant: 'ABSPATH', value: true } ) } );
		return r.status;
	} );
	t.check( 'non-whitelisted constant is rejected', reject === 400, String( reject ) );

	if ( cfg.editable ) {
		t.check( 'debug-tools card renders when editable', !! ( await page.$( '.minn-sys-debug' ) ) );
		t.check( 'a toggle switch is present for a live constant', ( await page.$$( '.minn-sys-toggle [data-const]' ) ).length > 0 );

		// Guarded write round-trip against the real wp-config, with a
		// filesystem backup restored in finally no matter what. The path is
		// resolved relative to this file (…/public/wp-content/plugins/
		// minn-admin/tests) so nothing site-specific is hardcoded; the test
		// self-skips if wp-config isn't where a standard install keeps it
		// (e.g. running against a remote site, or a config kept one level up).
		const path = require( 'path' );
		const wpConfig = path.resolve( __dirname, '..', '..', '..', '..', 'wp-config.php' );
		if ( fs.existsSync( wpConfig ) ) {
			const backup = wpConfig + '.test-bak';
			fs.copyFileSync( wpConfig, backup );
			try {
				// SAVEQUERIES is absent by default → toggling adds it.
				await page.evaluate( async () => {
					await fetch( window.MINN.restUrl + 'minn-admin/v1/system/config', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, body: JSON.stringify( { constant: 'SAVEQUERIES', value: true } ) } );
				} );
				const written = fs.readFileSync( wpConfig, 'utf8' );
				t.check( 'toggle writes a valid define into wp-config', /define\(\s*'SAVEQUERIES',\s*true\s*\)\s*;/.test( written ) && /<\?php/.test( written ), '' );
			} finally {
				fs.copyFileSync( backup, wpConfig );
				fs.unlinkSync( backup );
				[ wpConfig + '.minn-bak' ].forEach( ( f ) => { if ( fs.existsSync( f ) ) fs.unlinkSync( f ); } );
			}
		} else {
			console.log( '  (skipped wp-config write round-trip — path not found)' );
		}
	}

	/* ===== Debug log viewer ===== */
	t.check( 'config.log carries a path', !! ( cfg.log && typeof cfg.log.path === 'string' ), JSON.stringify( cfg.log ) );
	const logApi = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/system/debug-log', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return { status: r.status, body: await r.json() };
	} );
	t.check( 'debug-log endpoint returns exists + path', logApi.status === 200 && typeof logApi.body.exists === 'boolean' && typeof logApi.body.path === 'string', JSON.stringify( { s: logApi.status, exists: logApi.body.exists } ) );

	if ( cfg.log && cfg.log.exists ) {
		await page.click( '#minn-view-log' );
		await page.waitForSelector( '.minn-log-modal', { timeout: 8000 } );
		await page.waitForFunction( () => { const b = document.querySelector( '#minn-log-body' ); return b && ( b.textContent.length > 60 || /empty/.test( b.textContent ) ); }, null, { timeout: 10000 } );
		const overlay = await page.evaluate( () => ( {
			meta: document.querySelector( '#minn-log-meta' ).textContent,
			hasActions: document.querySelectorAll( '.minn-log-actions button' ).length >= 4,
		} ) );
		t.check( 'log overlay opens with meta + actions', /debug\.log/.test( overlay.meta ) && overlay.hasActions, JSON.stringify( overlay ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 250 );
		t.check( 'Escape closes the log overlay', ! ( await page.$( '.minn-log-modal' ) ) );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
