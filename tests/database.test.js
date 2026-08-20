/**
 * Database viewer ( /minn-admin/database ) — read-only by design.
 *
 * Covers: nav gating (admin sees it, Author does not + REST 403), table list
 * with search, drill into wp_options, per-column contains-filter, column
 * sort, pagination, the row-detail modal (including the full-value refetch
 * past the list cap), and the read-only claim in the chrome.
 *
 * Run: MINN_TEST_PASS=… node database.test.js
 */
const { execFileSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], {
	encoding: 'utf8',
	stdio: [ 'ignore', 'pipe', 'pipe' ],
} ).trim();

( async () => {
	const t = reporter( 'database' );
	const { browser, page, errors } = await launch();
	await login( page );

	// --- Entry points -----------------------------------------------------
	// Deliberately NOT a nav item: the System page's Database card and the
	// ⌘K command are the doors (plus the URL itself).
	await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-sysdb]', { timeout: 30000 } );
	t.check( 'No top-level nav item', await page.$( '.minn-nav-btn[data-nav="database"]' ) === null );
	const topTable = await page.$eval( '[data-sysdb]:not([data-sysdb=""]) .minn-sys-tname', ( el ) => el.textContent );
	await page.click( '[data-sysdb]:not([data-sysdb=""])' );
	await page.waitForSelector( '.minn-db-tname', { timeout: 20000 } );
	t.check( 'System card table row drills into that table', ( await page.textContent( '.minn-db-tname' ) ) === topTable );
	await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-sysdb=""]', { timeout: 30000 } );
	await page.click( '[data-sysdb=""]' );
	await page.waitForSelector( '[data-dbtable]', { timeout: 20000 } );
	t.check( 'System card "browse all" opens the table list', await page.$( '[data-dbtable="wp_options"]' ) !== null );

	// --- Table list -------------------------------------------------------
	await page.goto( BASE + '/minn-admin/database', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-dbtable]', { timeout: 20000 } );
	t.check( 'Read-only claim in the toolbar', ( await page.textContent( '.minn-toolbar' ) ).includes( 'Read-only by design' ) );
	t.check( 'wp_options is listed', await page.$( '[data-dbtable="wp_options"]' ) !== null );

	await page.fill( '#minn-db-search', 'wp_options' );
	await page.waitForTimeout( 200 );
	const shown = await page.$$eval( '[data-dbtable]', ( rows ) => rows.map( ( r ) => r.dataset.dbtable ) );
	t.check( 'Search filters the table list', shown.includes( 'wp_options' ) && ! shown.includes( 'wp_posts' ) );

	// --- Rows view --------------------------------------------------------
	await page.click( '[data-dbtable="wp_options"]' );
	await page.waitForSelector( '.minn-db-grid tbody tr[data-dbrow]', { timeout: 20000 } );
	const headText = await page.textContent( '.minn-db-grid thead' );
	t.check( 'Columns render from the live schema', headText.includes( 'option_name' ) && headText.includes( 'option_value' ) );
	t.check( 'Primary key is badged', headText.includes( 'PK' ) );
	t.check( 'Row meta names the table', ( await page.textContent( '.minn-db-tname' ) ) === 'wp_options' );
	t.check( 'Pager renders for a >50-row table', await page.$( '.minn-pager' ) !== null );

	// Pagination: page 2 shows different rows.
	const firstCell = () => page.$eval( 'tr[data-dbrow] td', ( td ) => td.textContent );
	const p1First = await firstCell();
	await page.click( '.minn-pager-btn[data-pg="2"]' );
	await page.waitForFunction( ( prev ) => {
		const td = document.querySelector( 'tr[data-dbrow] td' );
		return td && td.textContent !== prev;
	}, p1First, { timeout: 15000 } );
	t.check( 'Page 2 loads different rows', ( await firstCell() ) !== p1First );

	// Column sort: option_name ascending should put an a-ish name first.
	await page.click( 'th[data-dbcol="option_name"]' ); // desc
	await page.waitForTimeout( 600 );
	await page.click( 'th[data-dbcol="option_name"]' ); // asc
	await page.waitForFunction( () => {
		const th = document.querySelector( 'th[data-dbcol="option_name"]' );
		return th && th.textContent.includes( '▴' );
	}, null, { timeout: 15000 } );
	t.check( 'Header click sorts (asc marker shown)', true );

	// Per-column contains-filter → exactly the siteurl row.
	await page.selectOption( '#minn-db-fcol', 'option_name' );
	await page.fill( '#minn-db-fq', 'siteurl' );
	await page.press( '#minn-db-fq', 'Enter' );
	await page.waitForFunction( () => {
		const rows = document.querySelectorAll( 'tr[data-dbrow]' );
		return rows.length > 0 && rows.length < 10
			&& document.querySelector( '.minn-db-grid tbody' ).textContent.includes( 'siteurl' );
	}, null, { timeout: 15000 } );
	t.check( 'Contains-filter narrows to matching rows', true );
	t.check( 'Clear-filter control appears', await page.$( '#minn-db-fclear' ) !== null );

	// --- Row detail modal -------------------------------------------------
	// A value bigger than the 2 KB list cap proves the full-value refetch: the
	// modal's value block must outgrow what the grid carried. This used to
	// read the site's `cron` option and assume it was fat, which is true on a
	// site with dozens of plugins and false on a fresh one (2039 chars, just
	// under the cap). Seed the oversized serialized value instead, so the
	// assertion means the same thing on every site.
	// Seeded with wp-cli, not through the app: the Database viewer is
	// read-only by design and must stay that way, so its own suite is not the
	// reason a write endpoint exists.
	const BLOB = 'minn_suite_db_blob';
	wp( [ 'eval', `$v = array(); for ( $i = 0; $i < 90; $i++ ) { $v[ 'k' . $i ] = str_repeat( 'x', 40 ); } update_option( '${ BLOB }', $v, false );` ] );
	await page.fill( '#minn-db-fq', BLOB );
	await page.press( '#minn-db-fq', 'Enter' );
	await page.waitForFunction( ( name ) => {
		const rows = [ ...document.querySelectorAll( 'tr[data-dbrow]' ) ];
		return rows.some( ( r ) => [ ...r.children ].some( ( td ) => td.textContent === name ) );
	}, BLOB, { timeout: 15000 } );
	await page.evaluate( ( name ) => {
		const rows = [ ...document.querySelectorAll( 'tr[data-dbrow]' ) ];
		rows.find( ( r ) => [ ...r.children ].some( ( td ) => td.textContent === name ) ).click();
	}, BLOB );
	await page.waitForSelector( '.minn-dbd', { timeout: 15000 } );
	t.check( 'Detail modal titles the table', ( await page.textContent( '.minn-modal-title' ) ) === 'wp_options' );
	t.check( 'Detail lists every column', ( await page.$$eval( '.minn-dbd-row', ( r ) => r.length ) ) >= 4 );
	await page.waitForFunction( () => {
		const vals = [ ...document.querySelectorAll( '.minn-dbd-val' ) ];
		return vals.some( ( v ) => v.textContent.length > 2048 );
	}, null, { timeout: 15000 } ).catch( () => {} );
	const maxLen = await page.$$eval( '.minn-dbd-val', ( vals ) => Math.max( ...vals.map( ( v ) => v.textContent.length ) ) );
	t.check( 'Full value refetched past the 2 KB list cap', maxLen > 2048, 'longest value ' + maxLen + ' chars' );
	// Serialized blob stays raw text (never unserialized into structure).
	const bigVal = await page.$$eval( '.minn-dbd-val', ( vals ) =>
		vals.map( ( v ) => v.textContent ).find( ( x ) => x.length > 2048 ) || '' );
	t.check( 'Serialized value renders raw', /^a:\d+:{/.test( bigVal ) );
	t.check( 'Copy control offered on values', await page.$( '[data-dbcopy]' ) !== null );
	await page.click( '#minn-modal-close' );
	wp( [ 'option', 'delete', BLOB ] );

	// --- Back to the table list ------------------------------------------
	await page.click( '#minn-db-back' );
	await page.waitForSelector( '[data-dbtable]', { timeout: 15000 } );
	t.check( 'Back returns to the table list', await page.$( '[data-dbtable="wp_options"]' ) !== null );

	// --- Structure tab ----------------------------------------------------
	// wp_postmeta is the interesting case: a partial index (meta_key(191))
	// and no composite, which is exactly what the tab exists to make visible.
	// The list still carries the earlier search, so clear it first.
	await page.fill( '#minn-db-search', '' );
	await page.waitForSelector( '[data-dbtable="wp_postmeta"]', { timeout: 15000 } );
	await page.click( '[data-dbtable="wp_postmeta"]' );
	await page.waitForSelector( '.minn-db-grid tbody tr[data-dbrow]', { timeout: 20000 } );
	t.check( 'Table view offers a Structure tab', await page.$( '[data-dbtab="structure"]' ) !== null );
	await page.click( '[data-dbtab="structure"]' );
	await page.waitForSelector( '.minn-db-idx-cols', { timeout: 20000 } );

	const structText = await page.$$eval( '.minn-db-struct-cols', ( r ) => r.map( ( x ) => x.textContent ).join( '\n' ) );
	t.check( 'Structure lists columns with types', structText.includes( 'meta_key' ) && structText.includes( 'bigint' ) );
	t.check( 'Structure badges the primary key', structText.includes( 'PK' ) );
	t.check( 'Structure shows the auto_increment extra', structText.includes( 'auto_increment' ) );

	const idxText = await page.$$eval( '.minn-db-idx-cols', ( r ) => r.map( ( x ) => x.textContent ).join( '\n' ) );
	t.check( 'Indexes card lists PRIMARY', idxText.includes( 'PRIMARY' ) );
	t.check( 'Partial index shows its prefix length', idxText.includes( 'meta_key(191)' ), idxText.replace( /\s+/g, ' ' ).slice( 0, 160 ) );
	t.check( 'Structure reads no rows (metadata only)', await page.$( '.minn-db-grid' ) === null );
	t.check( 'Structure keeps the read-only claim', ( await page.textContent( '.minn-toolbar' ) ).includes( 'Read-only by design' ) );

	await page.click( '[data-dbtab="rows"]' );
	await page.waitForSelector( '.minn-db-grid tbody tr[data-dbrow]', { timeout: 20000 } );
	t.check( 'Rows tab returns to the grid', await page.$( '.minn-db-grid' ) !== null );
	await page.click( '#minn-db-back' );
	await page.waitForSelector( '[data-dbtable]', { timeout: 15000 } );

	// --- ⌘K palette entry -------------------------------------------------
	await page.keyboard.press( 'Meta+k' );
	await page.waitForSelector( '.minn-palette input', { timeout: 10000 } );
	await page.type( '.minn-palette input', 'browse database' );
	await page.waitForTimeout( 400 );
	t.check( 'Palette offers Browse database', ( await page.textContent( '.minn-palette' ) ).includes( 'Browse database' ) );
	await page.keyboard.press( 'Escape' );

	// --- Author gating ----------------------------------------------------
	const actx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const apage = await actx.newPage();
	await apage.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
	await apage.fill( '#user_login', 'minn-author' );
	await apage.fill( '#user_pass', 'minn-author-pass-1' );
	await Promise.all( [
		apage.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		apage.click( '#wp-submit' ),
	] );
	await apage.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await apage.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
	t.check( 'Author sees no Database nav item', await apage.$( '.minn-nav-btn[data-nav="database"]' ) === null );
	const status = await apage.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/db/tables', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return r.status;
	} );
	t.check( 'REST refuses an Author (403)', status === 403, 'got ' + status );
	const sstatus = await apage.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/db/structure?table=wp_options', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return r.status;
	} );
	t.check( 'REST refuses an Author on structure (403)', sstatus === 403, 'got ' + sstatus );
	await apage.goto( BASE + '/minn-admin/database', { waitUntil: 'domcontentloaded' } );
	await apage.waitForSelector( '#minn-view .minn-empty, #minn-view .minn-card', { timeout: 15000 } );
	const authorView = await apage.textContent( '#minn-view' );
	t.check( 'Author route shows the permission message', authorView.includes( 'administrator permissions' ) );
	await actx.close();

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
