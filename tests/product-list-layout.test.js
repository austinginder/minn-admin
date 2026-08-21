/**
 * Products / customers list layout (GH #49, #50).
 *
 * #49: the text half of a thumb + title cell is a flex item, so a long title
 * kept its full min-content width and painted over the next column instead of
 * ellipsing. #50: at phone width the base column-hiding rule and the
 * checkbox variant hide DIFFERENT children, and their effect unions — a
 * selectable row was left with only the checkbox and the row-end control.
 * Both are CSS, so this pins computed geometry rather than markup.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

const LONG = 'Extraordinarily Long Product Title That Should Ellipse Rather Than Paint Across The Neighbouring Columns';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-list-layout' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce products available', false, 'products cap missing — skip' );
		await t.done( browser, errors );
		return;
	}

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		try { return { status: r.status, body: JSON.parse( text ) }; } catch ( e ) { return { status: r.status, body: text }; }
	}, { path, opts } );

	// A product whose name is long enough to overflow its column.
	const made = await api( 'wc/v3/products', {
		method: 'POST',
		body: JSON.stringify( { name: LONG, type: 'simple', regular_price: '9.99', sku: 'MINN-LAYOUT-' + Date.now() } ),
	} );
	const pid = made.body && made.body.id;
	let cid = null;
	t.check( 'created the long-titled product', !! pid, JSON.stringify( made.status ) );

	try {
		/* ===== Desktop: the title clips inside its own column ===== */
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( `${ BASE }/minn-admin/products`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-product-cols .minn-row-title', { timeout: 20000 } );
		await page.waitForTimeout( 600 );

		const desk = await page.evaluate( ( name ) => {
			const row = [ ...document.querySelectorAll( '.minn-table-row.minn-product-cols' ) ]
				.find( ( r ) => ( r.textContent || '' ).includes( name.slice( 0, 40 ) ) );
			if ( ! row ) return null;
			const cell = row.querySelector( '.minn-prod-name' );
			const title = row.querySelector( '.minn-row-title' );
			// The cell that follows the name cell in DOM order.
			const cells = [ ...row.children ];
			const next = cells[ cells.indexOf( cell ) + 1 ];
			return {
				titleRight: Math.round( title.getBoundingClientRect().right ),
				cellRight: Math.round( cell.getBoundingClientRect().right ),
				nextLeft: next ? Math.round( next.getBoundingClientRect().left ) : null,
				clipped: title.scrollWidth > title.clientWidth,
			};
		}, LONG );
		t.check( 'found the product row', !! desk, JSON.stringify( desk ) );
		t.check( 'the title stays inside its own cell', desk && desk.titleRight <= desk.cellRight + 1, JSON.stringify( desk ) );
		t.check( 'the title does not reach the next column', desk && desk.nextLeft !== null && desk.titleRight <= desk.nextLeft + 1, JSON.stringify( desk ) );
		t.check( 'the long title is actually being truncated', desk && desk.clipped, JSON.stringify( desk ) );

		/* ===== Phone: a selectable row still shows name and price ===== */
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.waitForTimeout( 500 );
		const phone = await page.evaluate( ( name ) => {
			const row = [ ...document.querySelectorAll( '.minn-table-row.minn-product-cols' ) ]
				.find( ( r ) => ( r.textContent || '' ).includes( name.slice( 0, 40 ) ) );
			if ( ! row ) return null;
			const vis = [ ...row.children ].filter( ( c ) => getComputedStyle( c ).display !== 'none' );
			const title = row.querySelector( '.minn-row-title' );
			return {
				withCb: row.classList.contains( 'with-cb' ),
				visible: vis.length,
				nameVisible: !! ( title && title.checkVisibility && title.checkVisibility() ),
				titleRight: title ? Math.round( title.getBoundingClientRect().right ) : null,
				rowRight: Math.round( row.getBoundingClientRect().right ),
			};
		}, LONG );
		t.check( 'the phone row is the selectable variant', phone && phone.withCb, JSON.stringify( phone ) );
		t.check( 'more than the checkbox and row-end survive', phone && phone.visible > 2, JSON.stringify( phone ) );
		t.check( 'the product name is visible on a phone', phone && phone.nameVisible, JSON.stringify( phone ) );
		t.check( 'the name does not overflow the row', phone && phone.titleRight <= phone.rowRight + 1, JSON.stringify( phone ) );

		/* ===== Customers share the name cell, so they share the fix ===== */
		// Seed one with an equally long name when the site has none, so this
		// half is actually exercised rather than quietly skipped.
		const existing = await api( 'wc/v3/customers?per_page=1' );
		if ( ! ( Array.isArray( existing.body ) && existing.body.length ) ) {
			const c = await api( 'wc/v3/customers', {
				method: 'POST',
				body: JSON.stringify( {
					email: `minn-layout-${ Date.now() }@example.com`,
					first_name: LONG.split( ' ' ).slice( 0, 6 ).join( ' ' ),
					last_name: LONG.split( ' ' ).slice( 6 ).join( ' ' ),
					username: 'minn-layout-' + Date.now(),
				} ),
			} );
			cid = c.body && c.body.id;
		}
		await page.setViewportSize( { width: 1280, height: 900 } );
		await page.goto( `${ BASE }/minn-admin/customers`, { waitUntil: 'domcontentloaded' } );
		// The list loads async — wait for a row, never a flat timeout.
		await page.waitForSelector( '.minn-customer-cols .minn-prod-name, .minn-empty', { timeout: 20000 } );
		await page.waitForTimeout( 400 );
		const cust = await page.evaluate( () => {
			const cell = document.querySelector( '.minn-customer-cols .minn-prod-name' );
			if ( ! cell ) return { none: true };
			const title = cell.querySelector( '.minn-row-title' );
			const wrap = cell.querySelector( '.minn-prod-name-text' );
			return {
				none: false,
				wrapped: !! wrap,
				minWidth: wrap ? getComputedStyle( wrap ).minWidth : null,
				inside: title ? Math.round( title.getBoundingClientRect().right ) <= Math.round( cell.getBoundingClientRect().right ) + 1 : null,
			};
		} );
		if ( cust.none ) {
			t.check( 'customers list carries the shared name cell', true, 'no customers on this site — skipped' );
		} else {
			t.check( 'customers list carries the shared name cell', cust.wrapped, JSON.stringify( cust ) );
			t.check( 'customer name cell can shrink (min-width 0)', cust.minWidth === '0px', JSON.stringify( cust ) );
			t.check( 'customer name stays inside its cell', cust.inside !== false, JSON.stringify( cust ) );
		}
	} finally {
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } );
		if ( cid ) await api( `wc/v3/customers/${ cid }?force=true`, { method: 'DELETE' } );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
