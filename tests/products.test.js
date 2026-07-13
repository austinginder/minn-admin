/**
 * Products surface — WooCommerce catalog list/detail via wc/v3.
 * Fences product out of Content; daily fields edit in a modal; deep link to WC.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'products' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce products available', false, 'wc/products cap missing — skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce products available', true, '' );

	// Cap + route wiring from boot.
	const boot = await page.evaluate( () => ( {
		products: !!( window.MINN.caps && window.MINN.caps.products ),
		wc: !! window.MINN.wc,
	} ) );
	t.check( 'boot caps.products is true', boot.products && boot.wc, JSON.stringify( boot ) );

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

	// Ensure a simple product we can edit.
	const suffix = Date.now().toString( 36 );
	const created = await api( 'wc/v3/products', {
		method: 'POST',
		body: JSON.stringify( {
			name: 'Minn Products Suite ' + suffix,
			type: 'simple',
			regular_price: '24.00',
			status: 'publish',
			sku: 'minn-suite-' + suffix,
			manage_stock: true,
			stock_quantity: 7,
			stock_status: 'instock',
			short_description: 'Suite short desc',
		} ),
	} );
	t.check( 'created fixture product',
		created.status === 201 || created.status === 200,
		JSON.stringify( created.status ) );
	const productId = created.body && created.body.id;
	t.check( 'have product id', !! productId, String( productId ) );

	// Content must NOT list product as a type tab (fenced in HIDDEN_TYPES).
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-view', { timeout: 20000 } );
	// Types load async; wait until the SPA has a types cache or finished first paint.
	await page.waitForFunction( () => {
		// After loadTypes the combobox or tabs include at least Posts/All.
		const html = document.body.innerHTML;
		return html.includes( 'data-filter' ) || html.includes( 'data-typecombo' ) || html.includes( 'minn-type-select' );
	}, null, { timeout: 12000 } ).catch( () => null );
	await page.waitForTimeout( 600 );
	const contentFence = await page.evaluate( async () => {
		// Authoritative check: the types REST response still includes product,
		// but Minn's content filter must drop it. Mirror the client filter.
		const r = await fetch( window.MINN.restUrl + 'wp/v2/types?context=edit', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		} );
		const types = await r.json();
		const productInRest = !!( types && types.product );
		const domHasProduct = !! document.querySelector( '[data-filter="products"], [data-filter="product"]' )
			|| Array.from( document.querySelectorAll( '.minn-tab, .minn-ac-item' ) )
				.some( ( el ) => /^(products|product)$/i.test( ( el.textContent || '' ).trim() ) );
		return { productInRest, domHasProduct };
	} );
	t.check( 'product CPT still in REST types (show_in_rest)', contentFence.productInRest, JSON.stringify( contentFence ) );
	t.check( 'Content has no Products type tab', ! contentFence.domHasProduct, JSON.stringify( contentFence ) );

	// Products surface loads.
	await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-product-search, .minn-table-row, .minn-empty, .minn-loading', { timeout: 20000 } );
	await page.waitForFunction( () => {
		return !! document.querySelector( '#minn-product-search' )
			|| !! document.querySelector( '.minn-table-row[data-product]' )
			|| ( document.querySelector( '.minn-empty' ) && ! document.querySelector( '.minn-loading' ) );
	}, null, { timeout: 15000 } ).catch( () => null );

	const hasSearch = await page.$( '#minn-product-search' );
	t.check( 'products toolbar has search field', !! hasSearch, '' );
	t.check( 'Products nav route renders',
		!! hasSearch || !!( await page.$( '.minn-table-row[data-product]' ) ),
		'' );

	// Search by SKU.
	if ( hasSearch && productId ) {
		await page.fill( '#minn-product-search', 'minn-suite-' + suffix );
		await page.waitForTimeout( 700 );
		await page.waitForFunction( ( id ) => {
			const rows = document.querySelectorAll( '.minn-table-row[data-product]' );
			return rows.length >= 1 && Array.from( rows ).some( ( r ) => r.dataset.product === String( id ) );
		}, productId, { timeout: 12000 } ).catch( () => null );
		const found = await page.evaluate( ( id ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row[data-product]' ) );
			return { n: rows.length, hit: rows.some( ( r ) => r.dataset.product === String( id ) ) };
		}, productId );
		t.check( 'search by SKU finds the product', found.hit && found.n >= 1, JSON.stringify( found ) );

		// Also search by exact id.
		await page.fill( '#minn-product-search', String( productId ) );
		await page.waitForTimeout( 700 );
		await page.waitForFunction( ( id ) => {
			const rows = document.querySelectorAll( '.minn-table-row[data-product]' );
			return rows.length === 1 && rows[ 0 ].dataset.product === String( id );
		}, productId, { timeout: 10000 } ).catch( () => null );
		const byId = await page.evaluate( ( id ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row[data-product]' ) );
			return { n: rows.length, hit: rows.some( ( r ) => r.dataset.product === String( id ) ) };
		}, productId );
		t.check( 'search by product id finds the product', byId.hit, JSON.stringify( byId ) );
	}

	const clicked = await page.evaluate( ( id ) => {
		const row = document.querySelector( `.minn-table-row[data-product="${ id }"]` )
			|| document.querySelector( '.minn-table-row[data-product]' );
		if ( ! row ) return false;
		row.click();
		return true;
	}, productId );
	t.check( 'clicked product row', clicked, '' );

	if ( clicked ) {
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal.wide, .minn-modal' );
			return m && ! m.textContent.includes( 'Loading product' );
		}, null, { timeout: 15000 } ).catch( () => null );
		await page.waitForTimeout( 300 );

		const ui = await page.evaluate( () => {
			const modal = document.querySelector( '.minn-modal' );
			const text = modal ? modal.textContent : '';
			return {
				open: !! modal,
				hasName: !! document.querySelector( '#minn-p-name' ),
				hasSku: !! document.querySelector( '#minn-p-sku' ),
				hasRegular: !! document.querySelector( '#minn-p-regular' ),
				hasStock: !! document.querySelector( '#minn-p-stock' ),
				hasSave: !! document.querySelector( '#minn-product-save' ),
				hasWcEdit: /Edit in WooCommerce/.test( text ),
				hasView: /View product/.test( text ),
			};
		} );
		t.check( 'product modal is wide management UI',
			ui.open && ui.hasName && ui.hasSku && ui.hasSave && ui.hasWcEdit,
			JSON.stringify( ui ) );
		t.check( 'product modal has price and stock fields',
			ui.hasRegular && ui.hasStock, JSON.stringify( ui ) );

		// Save a field change.
		if ( ui.hasSave && ui.hasName ) {
			const newName = 'Minn Products Suite Renamed ' + suffix;
			await page.fill( '#minn-p-name', newName );
			if ( ui.hasRegular ) await page.fill( '#minn-p-regular', '29.50' );
			await page.click( '#minn-product-save' );
			await page.waitForFunction( () => {
				const btn = document.querySelector( '#minn-product-save' );
				// After save the modal re-renders with a fresh Save button (not disabled).
				return btn && ! btn.disabled && btn.textContent.includes( 'Save' );
			}, null, { timeout: 15000 } ).catch( () => null );
			await page.waitForTimeout( 400 );

			const verify = await api( `wc/v3/products/${ productId }?_fields=id,name,regular_price,sku` );
			t.check( 'product name saved via modal',
				verify.status === 200 && verify.body && verify.body.name === newName,
				JSON.stringify( verify.body && { name: verify.body.name } ) );
			t.check( 'product price saved via modal',
				verify.status === 200 && verify.body && String( verify.body.regular_price ) === '29.50',
				JSON.stringify( verify.body && { price: verify.body.regular_price } ) );
		}
	}

	// Cleanup fixture product.
	if ( productId ) {
		await api( `wc/v3/products/${ productId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
