/**
 * Customers surface — list/search + detail with recent orders via wc/v3.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'customers' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const has = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.customers ) );
	if ( ! has ) {
		t.check( 'WooCommerce customers available', false, 'skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce customers available', true, '' );

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

	const suffix = Date.now().toString( 36 );
	const email = `suite-cust-${ suffix }@example.com`;
	const created = await api( 'wc/v3/customers', {
		method: 'POST',
		body: JSON.stringify( {
			email,
			first_name: 'Suite',
			last_name: 'Buyer',
			username: 'suite' + suffix,
			password: 'TempPass123!x',
			billing: { first_name: 'Suite', last_name: 'Buyer', email },
		} ),
	} );
	t.check( 'created fixture customer', created.status === 201 || created.status === 200, JSON.stringify( created.status ) );
	const cid = created.body && created.body.id;
	t.check( 'have customer id', !! cid, String( cid ) );

	// Optional order tied to customer.
	let productId = null;
	const prods = await api( 'wc/v3/products?per_page=1&status=publish&_fields=id' );
	productId = prods.body && prods.body[ 0 ] && prods.body[ 0 ].id;
	if ( ! productId ) {
		const p = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Cust suite prod ' + suffix, type: 'simple', regular_price: '5', status: 'publish' } ),
		} );
		productId = p.body && p.body.id;
	}
	if ( productId && cid ) {
		await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( {
				customer_id: cid,
				status: 'processing',
				billing: { first_name: 'Suite', last_name: 'Buyer', email },
				line_items: [ { product_id: productId, quantity: 1 } ],
			} ),
		} );
	}

	await page.goto( BASE + '/minn-admin/customers', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-customer-search', { timeout: 20000 } );
	t.check( 'customers route has search', !!( await page.$( '#minn-customer-search' ) ), '' );

	await page.fill( '#minn-customer-search', email );
	await page.waitForTimeout( 700 );
	await page.waitForFunction( ( id ) =>
		!! document.querySelector( `.minn-table-row[data-customer="${ id }"]` ), cid, { timeout: 12000 } ).catch( () => null );
	const found = await page.evaluate( ( id ) =>
		!! document.querySelector( `.minn-table-row[data-customer="${ id }"]` ), cid );
	t.check( 'search finds customer by email', found, '' );

	if ( found ) {
		await page.evaluate( ( id ) => {
			document.querySelector( `.minn-table-row[data-customer="${ id }"]` ).click();
		}, cid );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && ! m.textContent.includes( 'Loading customer' );
		}, null, { timeout: 15000 } ).catch( () => null );
		const ui = await page.evaluate( () => {
			const text = document.querySelector( '.minn-modal' )?.textContent || '';
			return {
				open: !! document.querySelector( '.minn-modal' ),
				hasOrders: /Recent orders/.test( text ),
				hasBilling: /Billing/.test( text ),
				hasProfile: /Username|Registered/.test( text ),
			};
		} );
		t.check( 'customer modal shows profile and orders', ui.open && ui.hasOrders && ui.hasBilling, JSON.stringify( ui ) );
	}

	// Cleanup: delete customer (force) if possible.
	if ( cid ) {
		await api( `wc/v3/customers/${ cid }?force=true&reassign=1`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
