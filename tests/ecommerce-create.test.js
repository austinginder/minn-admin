/**
 * Create product (on the product page, from Products and from the + New
 * menu) + create order + Orders analytics view.
 */
const { BASE, launch, login, loginAs, reporter, pickCombo } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'ecommerce-create' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const boot = await page.evaluate( () => ( {
		orders: !!( window.MINN && window.MINN.caps && window.MINN.caps.orders ),
		products: !!( window.MINN && window.MINN.caps && window.MINN.caps.products ),
		wc: !!( window.MINN && window.MINN.wc ),
	} ) );
	if ( ! boot.wc || ! boot.orders || ! boot.products ) {
		t.check( 'WooCommerce orders+products available', false, JSON.stringify( boot ) );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce orders+products available', true, '' );

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

	// A crashed earlier run leaves its fixtures behind; sweep them by name
	// first so this run's lookups never pick up a stale one.
	for ( const pre of [ 'Minn Create Prod', 'Minn Menu Prod', 'Minn Create Variable', 'Minn Order Variable' ] ) {
		const rows = await api( `wc/v3/products?search=${ encodeURIComponent( pre ) }&status=any&per_page=50&_fields=id,name` );
		for ( const r of ( Array.isArray( rows.body ) ? rows.body : [] ).filter( ( x ) => String( x.name ).startsWith( pre ) ) ) {
			await api( `wc/v3/products/${ r.id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		}
	}
	const oldCoupons = await api( 'wc/v3/coupons?search=minn-oc-&per_page=50&_fields=id,code' );
	for ( const c of ( Array.isArray( oldCoupons.body ) ? oldCoupons.body : [] ).filter( ( x ) => String( x.code ).startsWith( 'minn-oc-' ) ) ) {
		await api( `wc/v3/coupons/${ c.id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}
	const oldCustomers = await api( 'wc/v3/customers?search=order-create-&role=all&per_page=50&_fields=id,email' );
	for ( const c of ( Array.isArray( oldCustomers.body ) ? oldCustomers.body : [] ).filter( ( x ) => /^order-create-.*@example\.com$/.test( x.email || '' ) ) ) {
		await api( `wc/v3/customers/${ c.id }?force=true&reassign=0`, { method: 'DELETE' } ).catch( () => null );
	}

	const suffix = Date.now().toString( 36 );
	let createdProductId = null;
	let createdOrderId = null;

	// Create product via UI: Add product opens the full product page over a
	// blank product, and its first save creates it.
	await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-product-add, #minn-order-search', { timeout: 20000 } );
	await page.waitForSelector( '.minn-table-row[data-product], .minn-empty', { timeout: 20000 } ).catch( () => null );
	const addProd = await page.$( '#minn-product-add' );
	t.check( 'Add product button present', !! addProd, '' );
	if ( addProd ) {
		await addProd.click();
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		const blank = await page.evaluate( () => ( {
			url: location.pathname,
			title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
			save: ( document.querySelector( '#minn-product-save' ) || {} ).textContent,
			bar: !! document.querySelector( '#minn-p-savebar:not([hidden])' ),
			cards: [ ...document.querySelectorAll( '[data-pcard]' ) ].map( ( c ) => c.dataset.pcard ),
			dialog: !! document.querySelector( '#minn-modal-overlay' ),
			focus: document.activeElement && document.activeElement.id,
		} ) );
		t.check( 'Add product opens the full page for a new product',
			/\/products\/new$/.test( blank.url ) && 'New product' === blank.title && /Create product/.test( blank.save || '' )
				&& blank.bar && ! blank.dialog && 'minn-p-name' === blank.focus
				&& [ 'basics', 'media', 'pricing', 'inventory', 'status', 'organization' ].every( ( c ) => blank.cards.includes( c ) ),
			JSON.stringify( blank ) );

		// Untouched, it is not unsaved work: leaving asks nothing.
		await page.click( '#minn-pp-back' );
		await page.waitForSelector( '#minn-product-add', { timeout: 20000 } );
		const asked = await page.$( '.minn-confirm, [role="alertdialog"]' );
		t.check( 'leaving an untouched new product asks nothing', ! asked && /\/products$/.test( new URL( page.url() ).pathname ), page.url() );

		await page.click( '#minn-product-add' );
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		const name = 'Minn Create Prod ' + suffix;
		await page.fill( '#minn-p-name', name );
		await page.fill( '#minn-p-regular', '11.25' );
		await page.fill( '#minn-p-sku', 'MINN-CP-' + suffix );
		await pickCombo( page, '#minn-p-status', 'publish' );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => /\/products\/\d+$/.test( location.pathname ), null, { timeout: 20000 } ).catch( () => null );
		// The created product's page loads it like any other product.
		await page.waitForSelector( '#minn-p-editor', { timeout: 20000 } ).catch( () => null );
		const listed = await api( `wc/v3/products?search=${ encodeURIComponent( name ) }&_fields=id,name,regular_price,status,sku` );
		const hit = ( listed.body || [] ).find( ( p ) => p.name === name );
		t.check( 'product created via UI', !! hit, JSON.stringify( listed.body && listed.body[ 0 ] ) );
		createdProductId = hit && hit.id;
		if ( hit ) {
			t.check( 'created product has the price, SKU and status entered',
				String( hit.regular_price ) === '11.25' && hit.sku === 'MINN-CP-' + suffix && hit.status === 'publish', JSON.stringify( hit ) );
			const landed = await page.evaluate( () => ( {
				url: location.pathname,
				title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
				editor: !! document.querySelector( '#minn-p-editor' ),
			} ) );
			t.check( 'the save lands on the new product\'s own page',
				landed.url.endsWith( '/products/' + hit.id ) && landed.title === name && landed.editor, JSON.stringify( landed ) );
			// The new page replaced /products/new in history.
			await page.goBack();
			await page.waitForSelector( '#minn-product-add', { timeout: 20000 } ).catch( () => null );
			t.check( 'Back returns to the list, not a blank form', /\/products$/.test( new URL( page.url() ).pathname ), page.url() );
		}
	}

	// A variable product in one go: its variations need the product's id,
	// so they save right after the product is created.
	await page.goto( BASE + '/minn-admin/products/new', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
	const varName = 'Minn Create Variable ' + suffix;
	await page.fill( '#minn-p-name', varName );
	await pickCombo( page, '#minn-p-type', 'variable' );
	await page.waitForSelector( '#minn-p-var-gen', { timeout: 10000 } );
	await page.click( '#minn-p-attr-add' );
	await page.waitForSelector( '[data-pattrname="0"]', { timeout: 10000 } );
	await page.fill( '[data-pattrname="0"]', 'Colour' );
	await page.fill( '[data-pattrvals="0"]', 'Red, Blue' );
	if ( ! await page.$eval( '[data-pattrvar="0"]', ( e ) => e.classList.contains( 'on' ) ) ) await page.click( '[data-pattrvar="0"]' );
	await page.click( '#minn-p-var-gen' );
	await page.waitForSelector( '.minn-pvar-row', { timeout: 10000 } );
	for ( let i = 0; i < 2; i++ ) {
		await page.click( `[data-pvaropen="${ i }"]` );
		await page.waitForSelector( '#minn-var-dialog', { timeout: 15000 } );
		await page.fill( '#minn-var-regular', String( 10 + i ) );
		await page.click( '#minn-var-dialog [data-var-done]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-var-dialog' ), null, { timeout: 15000 } );
	}
	// Hold the variations a moment, and leave meanwhile: the product exists
	// by then, so leaving asks nothing and the finished create leaves the
	// person where they went.
	const holdVariations = async ( route ) => {
		await new Promise( ( r ) => setTimeout( r, 3000 ) );
		await route.continue().catch( () => {} );
	};
	await page.route( /\/products\/\d+\/variations\/batch/, holdVariations );
	const varCreated = page.waitForResponse( ( r ) => 'POST' === r.request().method() && /\/wc\/v3\/products$/.test( r.url().split( '?' )[ 0 ] ), { timeout: 30000 } ).catch( () => null );
	await page.click( '#minn-product-save' );
	const varRes = await varCreated;
	const varId = varRes ? ( ( await varRes.json().catch( () => ( {} ) ) ).id || null ) : null;
	await page.waitForTimeout( 200 );
	await page.click( '.minn-nav-btn[data-nav="overview"]' );
	const askedVar = await page.waitForSelector( '.minn-confirm-overlay, [role="alertdialog"]', { timeout: 1500 } ).catch( () => null );
	t.check( 'leaving while a new product\'s variations save asks nothing', ! askedVar, '' );
	if ( askedVar ) await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 3800 );
	t.check( 'and the finished create leaves them where they went', ! /\/products\/\d+$/.test( new URL( page.url() ).pathname ), page.url() );
	await page.unroute( /\/products\/\d+\/variations\/batch/, holdVariations );
	const varRows = varId ? await api( `wc/v3/products/${ varId }/variations?_fields=regular_price,attributes` ) : { body: [] };
	const prices = ( Array.isArray( varRows.body ) ? varRows.body : [] ).map( ( v ) => v.regular_price ).sort();
	t.check( 'a new variable product saves with its variations', !! varId && '10,11' === prices.join( ',' ), JSON.stringify( { varId, body: varRows.body } ) );
	if ( varId ) await api( `wc/v3/products/${ varId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	// The + New menu offers Product too, from any screen, and it opens the
	// same page.
	let menuProductId = null;
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-new-btn', { timeout: 20000 } );
	await page.click( '#minn-new-btn' );
	const productRow = await page.waitForSelector( '#minn-new-menu [data-newproduct]', { timeout: 5000 } ).catch( () => null );
	t.check( '+ New menu offers Product', !! productRow, '' );
	if ( productRow ) {
		await productRow.click();
		const opened = await page.waitForSelector( '#minn-p-name', { timeout: 20000 } ).catch( () => null );
		t.check( 'it opens the new product page', !! opened && /\/products\/new$/.test( new URL( page.url() ).pathname ), page.url() );
		const name = 'Minn Menu Prod ' + suffix;
		await page.fill( '#minn-p-name', name );
		// Something entered is unsaved work: leaving asks first.
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		const confirm = await page.waitForSelector( '.minn-confirm, [role="alertdialog"]', { timeout: 5000 } ).catch( () => null );
		t.check( 'leaving a new product with a name typed asks first', !! confirm, '' );
		if ( confirm ) await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => /\/products\/\d+$/.test( location.pathname ), null, { timeout: 20000 } ).catch( () => null );
		const listed = await api( `wc/v3/products?search=${ encodeURIComponent( name ) }&status=draft&_fields=id,name,status` );
		const hit = ( listed.body || [] ).find( ( p ) => p.name === name );
		menuProductId = hit && hit.id;
		t.check( 'a product left at the default status is created as a draft', !! hit, JSON.stringify( listed.body ) );
	}
	if ( menuProductId ) await api( `wc/v3/products/${ menuProductId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	// An Author cannot make products, so the row is not there for them.
	const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
	await author.page.waitForSelector( '#minn-new-btn', { timeout: 20000 } );
	await author.page.click( '#minn-new-btn' );
	await author.page.waitForSelector( '#minn-new-menu', { timeout: 5000 } ).catch( () => null );
	const authorRow = await author.page.$( '#minn-new-menu [data-newproduct]' );
	t.check( 'no Product row for an Author', ! authorRow, '' );
	await author.ctx.close();

	// Create order via UI: New order opens /orders/new, the order page's
	// cards over a blank order, and nothing is stored until Create.
	await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-order-add, [data-oview]', { timeout: 20000 } );
	const addOrder = await page.$( '#minn-order-add' );
	t.check( 'New order button present', !! addOrder, '' );

	// Fixtures: a simple product (the one made above, or a fallback), a
	// variable product with two priced variations, a coupon and a customer.
	if ( ! createdProductId ) {
		const p = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: 'Minn Create Prod ' + suffix, type: 'simple', regular_price: '11.25', status: 'publish' } ) } );
		createdProductId = p.body && p.body.id;
	}
	const simple = await api( `wc/v3/products/${ createdProductId }?_fields=id,name,price` );
	const varMade = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( {
		name: 'Minn Order Variable ' + suffix, type: 'variable', status: 'publish',
		attributes: [ { name: 'Colour', visible: true, variation: true, options: [ 'Red', 'Blue' ] } ],
	} ) } );
	const orderVarId = varMade.body && varMade.body.id;
	if ( orderVarId ) {
		await api( `wc/v3/products/${ orderVarId }/variations/batch`, { method: 'POST', body: JSON.stringify( { create: [
			{ regular_price: '5.00', attributes: [ { name: 'Colour', option: 'Red' } ] },
			{ regular_price: '6.00', attributes: [ { name: 'Colour', option: 'Blue' } ] },
		] } ) } );
	}
	const couponCode = 'minn-oc-' + suffix;
	const couponMade = await api( 'wc/v3/coupons', { method: 'POST', body: JSON.stringify( { code: couponCode, discount_type: 'percent', amount: '10' } ) } );
	const couponId = couponMade.body && couponMade.body.id;
	const custEmail = `order-create-${ suffix }@example.com`;
	const custMade = await api( 'wc/v3/customers', { method: 'POST', body: JSON.stringify( {
		email: custEmail, first_name: 'Create', last_name: 'Suite', username: 'minn-oc-' + suffix,
		billing: { first_name: 'Create', last_name: 'Suite', email: custEmail, address_1: '1 Test Lane', city: 'Testville', postcode: '15001', country: 'US', state: 'PA' },
	} ) } );
	const customerId = custMade.body && custMade.body.id;
	t.check( 'order fixtures made', !! ( simple.body && simple.body.id && orderVarId && couponId && customerId ),
		JSON.stringify( { simple: simple.status, varMade: varMade.status, coupon: couponMade.status, cust: custMade.status } ) );
	const draftCount = async () => {
		const r = await api( 'wc/v3/orders?status=checkout-draft&per_page=100&_fields=id' );
		return Array.isArray( r.body ) ? r.body.length : -1;
	};

	if ( addOrder && simple.body && orderVarId && couponId && customerId ) {
		await page.click( '#minn-order-add' );
		await page.waitForSelector( '#minn-no-create', { timeout: 20000 } );
		const blankOrder = await page.evaluate( () => ( {
			url: location.pathname,
			title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
			pristine: !! document.querySelector( '#minn-no-savebar[data-pristine]' ),
			cards: [ ...document.querySelectorAll( '[data-nocard]' ) ].map( ( c ) => c.dataset.nocard ),
			dialog: !! document.querySelector( '#minn-modal-overlay' ),
		} ) );
		t.check( 'New order opens the new order page',
			/\/orders\/new$/.test( blankOrder.url ) && 'New order' === blankOrder.title && blankOrder.pristine && ! blankOrder.dialog
				&& [ 'items', 'coupons', 'shipcharge', 'payment', 'status', 'customer', 'shipping', 'note' ].every( ( c ) => blankOrder.cards.includes( c ) ),
			JSON.stringify( blankOrder ) );

		// Nothing to create without an item: it says so and stays put.
		await page.click( '#minn-no-create' );
		await page.waitForTimeout( 400 );
		t.check( 'Create with no items stays on the page', /\/orders\/new$/.test( new URL( page.url() ).pathname ), page.url() );

		// The simple product twice (a second pick is one more of it) and a
		// variation picked through its variable parent.
		const pickProduct = async ( q, rowText ) => {
			await page.fill( '#minn-no-search', q );
			const row = await page.waitForFunction( ( txt ) => [ ...document.querySelectorAll( '[data-nocard="items"] [data-ei-pick]' ) ].find( ( b ) => b.textContent.includes( txt ) ), rowText, { timeout: 15000 } ).catch( () => null );
			if ( row ) await row.asElement().click();
			return !! row;
		};
		await pickProduct( simple.body.name, simple.body.name );
		await pickProduct( simple.body.name, simple.body.name );
		await pickProduct( 'Minn Order Variable ' + suffix, 'choose a variation' );
		const blueRow = await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-nocard="items"] [data-ei-pick]' ) ].find( ( b ) => /Blue/.test( b.textContent ) ), null, { timeout: 15000 } ).catch( () => null );
		if ( blueRow ) await blueRow.asElement().click();
		const lines = await page.evaluate( () => [ ...document.querySelectorAll( '#minn-no-lines [data-noline]' ) ].map( ( r ) => ( {
			name: r.querySelector( '.minn-cell-clip' ).textContent,
			qty: r.querySelector( '[data-noqty]' ).value,
		} ) ) );
		t.check( 'items: the simple product twice, then the picked variation',
			2 === lines.length && '2' === lines[ 0 ].qty && /Blue/.test( lines[ 1 ].name ) && '1' === lines[ 1 ].qty, JSON.stringify( lines ) );

		// A code WooCommerce refuses stops the create, and leaves no draft.
		const draftsBefore = await draftCount();
		await page.fill( '#minn-no-coupon', 'nope-' + suffix );
		await page.click( '#minn-no-coupon-add' );
		await page.click( '#minn-no-create' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-no-create' );
			return b && ! b.disabled;
		}, null, { timeout: 20000 } ).catch( () => null );
		const draftsAfter = await draftCount();
		t.check( 'a refused coupon creates nothing and leaves no draft behind',
			/\/orders\/new$/.test( new URL( page.url() ).pathname ) && draftsAfter === draftsBefore, JSON.stringify( { url: page.url(), draftsBefore, draftsAfter } ) );
		await page.click( '#minn-no-coupons [data-nocdel="0"]' );
		await page.fill( '#minn-no-coupon', couponCode.toUpperCase() );
		await page.press( '#minn-no-coupon', 'Enter' );

		// A customer fills the details from their account.
		await page.fill( '#minn-no-cust-search', custEmail );
		const custRow = await page.waitForSelector( '[data-nocust="0"]', { timeout: 15000 } ).catch( () => null );
		if ( custRow ) await custRow.click();
		await page.waitForSelector( '#minn-no-custchip', { timeout: 10000 } ).catch( () => null );
		const filled = await page.evaluate( () => ( {
			first: ( document.querySelector( '#minn-ob-first' ) || {} ).value,
			city: ( document.querySelector( '#minn-ob-city' ) || {} ).value,
			chip: ( document.querySelector( '#minn-no-custchip' ) || {} ).textContent || '',
			kept: [ ...document.querySelectorAll( '#minn-no-lines [data-noline]' ) ].length,
		} ) );
		t.check( 'picking a customer fills their details and keeps the items',
			'Create' === filled.first && 'Testville' === filled.city && filled.chip.includes( custEmail ) && 2 === filled.kept, JSON.stringify( filled ) );

		await page.fill( '#minn-no-shiptitle', 'Courier' );
		await page.fill( '#minn-no-shiptotal', '5' );
		await page.click( '[data-nocard="shipping"] [data-ocopyaddr]' );
		await page.fill( '#minn-o-note', 'Leave it on the porch' );
		await pickCombo( page, '[data-noc="status"] .minn-ac-input', 'processing' );
		await pickCombo( page, '[data-noc="paymethod"] .minn-ac-input', 'other' );
		await page.fill( '#minn-no-paytitle', 'Phone order' );
		await page.fill( '#minn-no-txn', 'REF-' + suffix );

		// Typed work is unsaved work: leaving asks first.
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		const askLeave = await page.waitForSelector( '[role="alertdialog"]', { timeout: 5000 } ).catch( () => null );
		t.check( 'leaving a new order with details entered asks first', !! askLeave, '' );
		if ( askLeave ) await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );

		await page.click( '#minn-no-create' );
		await page.waitForFunction( () => /\/orders\/\d+$/.test( location.pathname ), null, { timeout: 30000 } ).catch( () => null );
		await page.waitForSelector( '#minn-order-save', { timeout: 20000 } ).catch( () => null );
		createdOrderId = parseInt( new URL( page.url() ).pathname.split( '/' ).pop(), 10 ) || null;
		t.check( 'Create lands on the new order\'s own page', !! createdOrderId && !! await page.$( '#minn-order-save' ), page.url() );
		if ( createdOrderId ) {
			const o = ( await api( `wc/v3/orders/${ createdOrderId }?_fields=status,customer_id,billing,shipping,line_items,coupon_lines,shipping_lines,customer_note,payment_method,payment_method_title,transaction_id,discount_total,total` ) ).body || {};
			const got = {
				status: o.status,
				customer: o.customer_id === customerId,
				items: ( o.line_items || [] ).map( ( l ) => [ l.product_id, l.variation_id, l.quantity ] ),
				coupons: ( o.coupon_lines || [] ).map( ( c ) => c.code ),
				shipping: ( o.shipping_lines || [] ).map( ( l ) => [ l.method_title, l.total ] ),
				shipTo: ( o.shipping || {} ).city + '/' + ( o.shipping || {} ).first_name,
				note: o.customer_note,
				pay: [ o.payment_method, o.payment_method_title, o.transaction_id ],
				discount: o.discount_total,
				total: o.total,
			};
			const unit = parseFloat( simple.body.price );
			const expected = ( ( unit * 2 + 6 ) * 0.9 + 5 ).toFixed( 2 );
			t.check( 'the order WooCommerce stored is the one entered, totals worked out by it',
				'processing' === got.status && got.customer
					&& 2 === got.items.length && got.items[ 0 ][ 2 ] === 2 && got.items[ 1 ][ 0 ] === orderVarId && got.items[ 1 ][ 1 ] > 0
					&& got.coupons[ 0 ] === couponCode && 'Courier' === got.shipping[ 0 ][ 0 ] && '5.00' === got.shipping[ 0 ][ 1 ]
					&& 'Testville/Create' === got.shipTo && 'Leave it on the porch' === got.note
					&& 'other' === got.pay[ 0 ] && 'Phone order' === got.pay[ 1 ] && 'REF-' + suffix === got.pay[ 2 ]
					&& parseFloat( got.discount ) > 0 && got.total === expected,
				JSON.stringify( { got, expected } ) );
			await page.goBack();
			await page.waitForSelector( '#minn-order-add, [data-oview]', { timeout: 20000 } ).catch( () => null );
			t.check( 'Back from the new order returns to the list', /\/orders$/.test( new URL( page.url() ).pathname ), page.url() );
		}
	}
	if ( orderVarId ) await api( `wc/v3/products/${ orderVarId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	if ( couponId ) await api( `wc/v3/coupons/${ couponId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	if ( customerId ) await api( `wc/v3/customers/${ customerId }?force=true&reassign=0`, { method: 'DELETE' } ).catch( () => null );

	// Analytics view (wc-analytics revenue stats can take several seconds).
	await page.keyboard.press( 'Escape' ).catch( () => null );
	await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-oview="analytics"]', { timeout: 20000 } );
	await page.click( '[data-oview="analytics"]' );
	await page.waitForFunction( () => {
		const text = document.body.innerText || '';
		if ( /Loading analytics/.test( text ) ) return false;
		return /Gross sales/.test( text )
			|| /Net revenue/.test( text )
			|| /Something went wrong/.test( text )
			|| ( document.querySelector( '.minn-empty' ) && /Could not load analytics|analytics/i.test( text ) );
	}, null, { timeout: 30000 } ).catch( () => null );
	const analytics = await page.evaluate( () => {
		const text = document.body.innerText;
		return {
			hasGross: /Gross sales/.test( text ),
			hasRevenue: /Revenue|Net revenue/.test( text ),
			hasRange: !! document.querySelector( '[data-orange]' ),
			hasChart: !! document.querySelector( '#minn-wc-chart, .minn-chart' ),
			hasTop: /Top products/.test( text ),
			snippet: text.replace( /\s+/g, ' ' ).slice( 0, 220 ),
		};
	} );
	t.check( 'Orders Analytics view renders stats',
		analytics.hasGross && analytics.hasRevenue && analytics.hasRange,
		JSON.stringify( analytics ) );
	t.check( 'Orders Analytics has chart or empty state',
		analytics.hasChart || analytics.hasTop || analytics.hasGross, JSON.stringify( analytics ) );

	// Long-range + list status coverage (historical orders).
	const hasAllRange = await page.$( '[data-orange="all"]' );
	const has1y = await page.$( '[data-orange="365"]' );
	t.check( 'Analytics offers 1y and All ranges', !! hasAllRange && !! has1y, '' );
	if ( hasAllRange ) {
		await page.click( '[data-orange="all"]' );
		await page.waitForFunction( () => {
			const text = document.body.innerText || '';
			return /all time/i.test( text ) && ! /Loading analytics/.test( text );
		}, null, { timeout: 30000 } ).catch( () => null );
		const allLabel = await page.evaluate( () => /all time/i.test( document.body.innerText || '' ) );
		t.check( 'All-time analytics range loads', allLabel, '' );
	}
	// Range chrome must stay visible while a fetch is in flight.
	await page.click( '[data-orange="7"]' );
	const chromeWhileLoad = await page.evaluate( () => {
		const ranges = Array.from( document.querySelectorAll( '[data-orange]' ) ).map( ( b ) => b.dataset.orange );
		const labels = Array.from( document.querySelectorAll( '.minn-stat-label' ) ).map( ( el ) => el.textContent.trim() );
		return {
			ranges,
			hasGrossLabel: labels.includes( 'Gross sales' ),
			hasRangeTabs: ranges.includes( 'all' ) && ranges.includes( '7' ),
			noViewAll: ! document.body.innerText.includes( 'View all orders' ),
		};
	} );
	t.check( 'Analytics keeps range tabs and stat labels while loading',
		chromeWhileLoad.hasRangeTabs && chromeWhileLoad.hasGrossLabel && chromeWhileLoad.noViewAll,
		JSON.stringify( chromeWhileLoad ) );

	await page.click( '[data-oview="list"]' );
	await page.waitForSelector( '#minn-order-preset', { timeout: 15000 } );
	// The status tabs are a dropdown on the filter bar now.
	await page.click( '#minn-order-preset' );
	await page.waitForSelector( '.minn-of-pop [data-opreset]', { timeout: 8000 } );
	const tabs = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-of-pop [data-opreset]' ) ).map( ( b ) => b.dataset.opreset )
	);
	await page.keyboard.press( 'Escape' );
	t.check( 'Orders list has pending/cancelled/failed presets',
		[ 'any', 'pending', 'cancelled', 'failed', 'completed' ].every( ( id ) => tabs.includes( id ) ),
		JSON.stringify( tabs ) );

	// Cleanup.
	if ( createdOrderId ) await api( `wc/v3/orders/${ createdOrderId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	if ( createdProductId ) await api( `wc/v3/products/${ createdProductId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
