/**
 * Table context menu: right-click a cell for targeted ops — add row
 * above/below, add column left/right, delete row/column — acting on the
 * CLICKED cell (not the caret). Deletes offer the Undo toast. All verified
 * against SAVED markup.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const TABLE = '<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>H1</th><th>H2</th><th>H3</th></tr></thead><tbody>'
	+ '<tr><td>a1</td><td>a2</td><td>a3</td></tr>'
	+ '<tr><td>b1</td><td>b2</td><td>b3</td></tr>'
	+ '</tbody></table></figure><!-- /wp:table -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'table-menu' );
	await login( page );

	const id = await createPost( page, { title: 'Table menu probe', content: TABLE, status: 'draft' } );
	await openEditor( page, id );
	await page.waitForSelector( '#minn-editor-body table td', { timeout: 15000 } );

	const saved = async () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async () => { await page.keyboard.press( 'Meta+s' ); await page.waitForTimeout( 1400 ); };

	/* ===== Right-click opens the menu ===== */
	await page.click( 'td >> text=b2', { button: 'right' } );
	await page.waitForSelector( '.minn-table-menu', { timeout: 5000 } );
	const items = await page.$$eval( '.minn-table-menu button', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	t.check( 'menu offers the six targeted ops', items.join( '|' ) === 'Add row above|Add row below|Delete row|Add column left|Add column right|Delete column', JSON.stringify( items ) );

	/* ===== Add row below the CLICKED row (b), not the caret ===== */
	await page.click( '.minn-table-menu button[data-op="row-below"]' );
	await page.waitForTimeout( 300 );
	const rows = await page.$$eval( '#minn-editor-body tbody tr', ( els ) => els.map( ( r ) => r.cells[ 0 ].textContent.trim() ) );
	t.check( 'row lands below the clicked row', rows.length === 3 && rows[ 0 ] === 'a1' && rows[ 1 ] === 'b1' && rows[ 2 ] === '', JSON.stringify( rows ) );

	/* ===== Add column right of the clicked column (2 of 3) ===== */
	await page.click( 'td >> text=a2', { button: 'right' } );
	await page.waitForSelector( '.minn-table-menu' );
	await page.click( '.minn-table-menu button[data-op="col-right"]' );
	await page.waitForTimeout( 300 );
	const headCells = await page.$$eval( '#minn-editor-body thead th', ( els ) => els.map( ( c ) => c.textContent.trim() ) );
	t.check( 'column lands right of the clicked one, thead included', headCells.length === 4 && headCells[ 0 ] === 'H1' && headCells[ 1 ] === 'H2' && headCells[ 2 ] === '' && headCells[ 3 ] === 'H3', JSON.stringify( headCells ) );

	/* ===== Delete the clicked column — Undo toast offered ===== */
	await page.click( 'td >> text=a3', { button: 'right' } );
	await page.waitForSelector( '.minn-table-menu' );
	await page.click( '.minn-table-menu button[data-op="col-del"]' );
	await page.waitForTimeout( 300 );
	const afterDel = await page.$$eval( '#minn-editor-body thead th', ( els ) => els.length );
	const toast = await page.evaluate( () => {
		const el = [ ...document.querySelectorAll( '[class*=toast]' ) ].find( ( x ) => /Column deleted/.test( x.textContent ) );
		return el ? el.textContent : '';
	} );
	t.check( 'clicked column deleted', afterDel === 3, String( afterDel ) );
	t.check( 'delete offers the Undo toast', /Undo/.test( toast ), toast.slice( 0, 60 ) );

	/* ===== Delete the clicked row ===== */
	await page.click( 'td >> text=a1', { button: 'right' } );
	await page.waitForSelector( '.minn-table-menu' );
	await page.click( '.minn-table-menu button[data-op="row-del"]' );
	await page.waitForTimeout( 300 );
	const rows2 = await page.$$eval( '#minn-editor-body tbody tr', ( els ) => els.map( ( r ) => r.cells[ 0 ].textContent.trim() ) );
	t.check( 'clicked row deleted', rows2.length === 2 && rows2[ 0 ] === 'b1', JSON.stringify( rows2 ) );

	/* ===== The lot persists ===== */
	await save();
	const raw = await saved();
	// New cells carry &nbsp; (tableNewCell keeps empties clickable).
	t.check( 'saved markup reflects the targeted ops', /<th>H1<\/th><th>H2<\/th><th>(&nbsp;)?<\/th>/.test( raw ) && ! raw.includes( 'a1' ) && raw.includes( 'b1' ), raw.slice( raw.indexOf( '<thead' ), raw.indexOf( '<thead' ) + 80 ) );

	/* ===== Menu closes on away-click ===== */
	await page.click( 'td >> text=b1', { button: 'right' } );
	await page.waitForSelector( '.minn-table-menu' );
	await page.mouse.down( { button: 'left' } );
	await page.mouse.up( { button: 'left' } );
	await page.waitForTimeout( 200 );
	t.check( 'menu closes on away click', ! ( await page.$( '.minn-table-menu' ) ), '' );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
