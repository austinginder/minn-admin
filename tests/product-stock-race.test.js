/**
 * The product page and stock sold while it was open.
 *
 * Orders lower a product's stock while its page sits open. A save from that
 * page must not write the quantity it loaded back over them (units sold
 * twice, a sold-out product purchasable again). WooCommerce's own product
 * screen refuses a stock change when the stored stock moved since the form
 * loaded and saves the rest; Minn does the same.
 *
 * The "sale" is an inventory_delta through wc/v3, which lowers _stock the
 * way an order does, from outside the open page.
 *
 * A variation's stock gets the same check on the same Save.
 *
 * Fixture: one simple and one variable product, created and removed over
 * REST.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-stock-race' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
	if ( ! hasWc ) {
		console.log( 'SKIP  WooCommerce unavailable' );
		await t.done( browser, errors );
		return;
	}

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
	const stock = async () => {
		const r = await api( `wc/v3/products/${ id }?_fields=stock_quantity,regular_price` );
		return r.body || {};
	};
	const sell = ( n ) => api( `wc/v3/products/${ id }`, { method: 'PUT', body: JSON.stringify( { inventory_delta: -n } ) } );
	const openPage = async () => {
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-qty', { timeout: 20000 } );
	};
	const save = async () => {
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 600 );
	};

	let id = null;
	let varParent = null;
	try {
		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Stock race fixture ' + Date.now(),
				type: 'simple', regular_price: '20.00', status: 'publish',
				manage_stock: true, stock_quantity: 5,
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created with 5 in stock', !! id && made.body.stock_quantity === 5, String( made.status ) );
		if ( ! id ) return;

		/* 1. An unrelated edit keeps a sale made while the page was open. */
		await openPage();
		await sell( 2 );
		await page.fill( '#minn-p-regular', '21.00' );
		await save();
		let s = await stock();
		t.check( 'a price edit saves', s.regular_price === '21.00', String( s.regular_price ) );
		t.check( 'the sale made meanwhile is kept (3, not the loaded 5)', s.stock_quantity === 3, String( s.stock_quantity ) );

		/* 2. A stock edit over a moved stock is refused; the rest saves. */
		await openPage();
		await sell( 1 );
		await page.fill( '#minn-p-qty', '10' );
		await page.fill( '#minn-p-regular', '22.00' );
		await save();
		s = await stock();
		t.check( 'a stock edit over a stock that moved is refused (2 stays)', s.stock_quantity === 2, String( s.stock_quantity ) );
		t.check( 'the other edits in that save still land', s.regular_price === '22.00', String( s.regular_price ) );
		const shown = await page.$eval( '#minn-p-qty', ( e ) => e.value ).catch( () => '' );
		t.check( 'the page then shows the stored stock', shown === '2', shown );

		/* 3. Control: a stock edit with nothing sold meanwhile saves. */
		await openPage();
		await page.fill( '#minn-p-qty', '9' );
		await save();
		s = await stock();
		t.check( 'a stock edit saves when the stock did not move', s.stock_quantity === 9, String( s.stock_quantity ) );

		/* 4. A variation's stock gets the same check. */
		const defs = await api( 'wc/v3/products/attributes?_fields=id,name' );
		const attr = ( defs.body || [] )[ 0 ];
		if ( ! attr ) {
			console.log( 'SKIP  no global attribute to vary by' );
		} else {
			const vmade = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: 'Variation stock race ' + Date.now(), type: 'variable', status: 'publish' } ) } );
			varParent = vmade.body && vmade.body.id;
			await api( `wc/v3/products/${ varParent }`, { method: 'PUT', body: JSON.stringify( { attributes: [ { id: attr.id, visible: true, variation: true, options: [ 'Small', 'Large' ] } ] } ) } );
			const vv = await api( `wc/v3/products/${ varParent }/variations`, { method: 'POST', body: JSON.stringify( { attributes: [ { id: attr.id, option: 'Small' } ], regular_price: '10.00', manage_stock: true, stock_quantity: 5 } ) } );
			const vid = vv.body && vv.body.id;
			t.check( 'a variation with 5 in stock is made', !! vid && vv.body.stock_quantity === 5, String( vv.status ) );
			const vstock = async () => ( ( await api( `wc/v3/products/${ varParent }/variations/${ vid }?_fields=stock_quantity` ) ).body || {} ).stock_quantity;
			const openVar = async () => {
				await page.goto( BASE + '/minn-admin/products/' + varParent, { waitUntil: 'domcontentloaded' } );
				await page.waitForSelector( '[data-pvaropen="0"]', { timeout: 20000 } );
			};
			const setVarQty = async ( q ) => {
				await page.click( '[data-pvaropen="0"]' );
				await page.waitForSelector( '#minn-var-qty', { timeout: 15000 } );
				await page.fill( '#minn-var-qty', q );
				await page.click( '#minn-var-dialog [data-var-done]' );
				await page.waitForFunction( () => ! document.querySelector( '#minn-var-dialog' ), null, { timeout: 15000 } );
			};
			if ( vid ) {
				await openVar();
				await api( `wc/v3/products/${ varParent }/variations/${ vid }`, { method: 'PUT', body: JSON.stringify( { inventory_delta: -2 } ) } );
				await setVarQty( '10' );
				const toastSeen = page.waitForSelector( '.minn-toast', { timeout: 20000 } ).then( ( el ) => el.textContent() ).then( ( x ) => ( x || '' ).trim() ).catch( () => '' );
				await save();
				const toastText = await toastSeen;
				t.check( 'a variation stock edit over a stock that moved is refused (3 stays)', 3 === await vstock(), String( await vstock() ) );
				t.check( 'and the save says why', /stock of 1 variation/.test( toastText ), toastText );

				await openVar();
				await setVarQty( '8' );
				await save();
				t.check( 'control: a variation stock edit saves when it did not move', 8 === await vstock(), String( await vstock() ) );
			}
		}
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( varParent ) await api( `wc/v3/products/${ varParent }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
