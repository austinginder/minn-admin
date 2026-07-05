/**
 * System diagnostics page: the minn-admin/v1/system endpoint shape, the
 * rendered health strip + group cards + largest-tables, and copy-report.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

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

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
