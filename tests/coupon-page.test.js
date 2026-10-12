/**
 * The coupon page (/coupons/{id}, /coupons/new): every setting of
 * WooCommerce's coupon screen, created by the first save, an existing
 * coupon saving only what changed, Discard, the orders that used the code,
 * the leave guard, Delete, and no page without the coupons capability.
 *
 * Fixtures (made here, swept by name at the start, deleted at the end): two
 * products, a product category, a coupon made through the page, and an
 * order that uses it.
 */
const { BASE, launch, login, loginAs, reporter, pickCombo } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'coupon-page' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const caps = await page.evaluate( () => ( { wc: !! window.MINN.wc, coupons: !! ( window.MINN.caps || {} ).coupons } ) );
	if ( ! caps.wc || ! caps.coupons ) {
		console.log( 'SKIP  needs WooCommerce with coupons on' );
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
	const toastSays = ( re ) => page.waitForFunction( ( src ) => new RegExp( src, 'i' ).test( ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' ), re.source, { timeout: 20000 } ).catch( () => null );

	// A crashed earlier run's fixtures go first.
	for ( const pre of [ 'Minn CPG Prod' ] ) {
		const rows = await api( `wc/v3/products?search=${ encodeURIComponent( pre ) }&status=any&per_page=50&_fields=id,name` );
		for ( const r of ( Array.isArray( rows.body ) ? rows.body : [] ).filter( ( x ) => String( x.name ).startsWith( pre ) ) ) {
			await api( `wc/v3/products/${ r.id }?force=true`, { method: 'DELETE' } );
		}
	}
	const oldCats = await api( 'wc/v3/products/categories?search=Minn%20CPG&per_page=50&_fields=id,name' );
	for ( const r of ( Array.isArray( oldCats.body ) ? oldCats.body : [] ).filter( ( x ) => String( x.name ).startsWith( 'Minn CPG' ) ) ) {
		await api( `wc/v3/products/categories/${ r.id }?force=true`, { method: 'DELETE' } );
	}

	const suffix = Date.now().toString( 36 );
	const made = { products: [], category: null, coupon: null, order: null };
	try {
		for ( const n of [ 'A', 'B' ] ) {
			const p = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: `Minn CPG Prod ${ n } ${ suffix }`, type: 'simple', regular_price: '40.00', status: 'publish' } ) } );
			if ( p.body && p.body.id ) made.products.push( p.body );
		}
		const cat = await api( 'wc/v3/products/categories', { method: 'POST', body: JSON.stringify( { name: 'Minn CPG Cat ' + suffix } ) } );
		made.category = cat.body && cat.body.id ? cat.body : null;
		t.check( 'fixtures: two products and a category', 2 === made.products.length && !! made.category, JSON.stringify( { products: made.products.length, cat: cat.status } ) );

		// ---- New coupon ----
		await page.goto( BASE + '/minn-admin/coupons', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-coupon-add', { timeout: 20000 } );
		await page.click( '#minn-coupon-add' );
		await page.waitForSelector( '#minn-c-code', { timeout: 20000 } );
		const blank = await page.evaluate( () => ( {
			url: location.pathname,
			title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
			pristine: !! document.querySelector( '#minn-co-savebar[data-pristine]' ),
			cards: [ ...document.querySelectorAll( '[data-cocard]' ) ].map( ( c ) => c.dataset.cocard ),
			dialog: !! document.querySelector( '#minn-modal-overlay' ),
		} ) );
		t.check( 'Add coupon opens the new coupon page',
			/\/coupons\/new$/.test( blank.url ) && 'New coupon' === blank.title && blank.pristine && ! blank.dialog
				&& [ 'general', 'restrictions', 'limits', 'status' ].every( ( c ) => blank.cards.includes( c ) ),
			JSON.stringify( blank ) );

		await page.click( '#minn-c-generate' );
		const generated = await page.$eval( '#minn-c-code', ( i ) => i.value );
		const dirtyAfterGenerate = ! await page.$( '#minn-co-savebar[data-pristine]' );
		t.check( 'Generate fills an eight-character code and counts as an edit', /^[A-Z2-9]{8}$/.test( generated ) && dirtyAfterGenerate, generated );
		const code = 'MINNCPG' + suffix.toUpperCase();
		await page.fill( '#minn-c-code', code );
		await page.fill( '#minn-c-desc', 'Made by the coupon-page suite' );
		await pickCombo( page, '#minn-c-type', 'fixed_cart' );
		await page.fill( '#minn-c-amount', '15' );
		await page.click( '#minn-c-expires' );
		await page.waitForSelector( '.minn-dp-pop .minn-dp-day', { timeout: 10000 } );
		const pickedDay = await page.$$eval( '.minn-dp-pop .minn-dp-day:not(.out)', ( bs ) => bs[ bs.length - 1 ].dataset.day ).catch( () => '' );
		await page.click( `.minn-dp-pop .minn-dp-day[data-day="${ pickedDay }"]` );
		const done = await page.$( '.minn-dp-pop [data-dp-done]' );
		if ( done ) await done.click();
		await page.click( '#minn-c-ship' );
		await page.fill( '#minn-c-min', '20' );
		await page.fill( '#minn-c-max', '200' );
		await page.click( '#minn-c-individual' );
		await page.click( '#minn-c-nosale' );
		// The search matches both fixtures, so pick each by its id.
		const pickLink = async ( key, product ) => {
			await page.fill( `[data-plac="${ key }"] .minn-ac-input`, product.name );
			const sel = `[data-plac="${ key }"] [data-plpick="${ product.id }"]`;
			const hit = await page.waitForSelector( sel, { timeout: 15000 } ).catch( () => null );
			if ( hit ) await page.dispatchEvent( sel, 'mousedown' );
		};
		await pickLink( 'product_ids', made.products[ 0 ] );
		await pickLink( 'excluded_product_ids', made.products[ 1 ] );
		await page.fill( '[data-ptac="product_categories"] .minn-ac-input', made.category.name );
		await page.waitForSelector( `[data-ptac="product_categories"] [data-ptpick="${ made.category.id }"]`, { timeout: 15000 } ).catch( () => null );
		await page.dispatchEvent( `[data-ptac="product_categories"] [data-ptpick="${ made.category.id }"]`, 'mousedown' );
		const noAdd = ! await page.$( '[data-ptac="product_categories"] [data-ptadd]' );
		t.check( 'the category fields only pick categories that exist', noAdd, '' );
		await page.fill( '#minn-c-emails', 'buyer@example.com, *@example.org' );
		await page.fill( '#minn-c-limit', '50' );
		await page.fill( '#minn-c-limit-user', '2' );
		await page.fill( '#minn-c-xitems', '3' );
		await pickCombo( page, '#minn-c-status', 'draft' );

		await page.click( '#minn-coupon-save' );
		await page.waitForFunction( () => /\/coupons\/\d+$/.test( location.pathname ), null, { timeout: 20000 } ).catch( () => null );
		made.coupon = parseInt( new URL( page.url() ).pathname.split( '/' ).pop(), 10 ) || null;
		const c = made.coupon ? ( await api( `wc/v3/coupons/${ made.coupon }` ) ).body : {};
		const want = {
			code: code.toLowerCase(), description: 'Made by the coupon-page suite', discount_type: 'fixed_cart', amount: '15.00', status: 'draft',
			free_shipping: true, minimum_amount: '20.00', maximum_amount: '200.00', individual_use: true, exclude_sale_items: true,
			product_ids: [ made.products[ 0 ].id ], excluded_product_ids: [ made.products[ 1 ].id ], product_categories: [ made.category.id ],
			email_restrictions: [ 'buyer@example.com', '*@example.org' ], usage_limit: 50, usage_limit_per_user: 2, limit_usage_to_x_items: 3,
		};
		const off = Object.keys( want ).filter( ( k ) => JSON.stringify( c[ k ] ) !== JSON.stringify( want[ k ] ) );
		const expiresOk = !! pickedDay && String( c.date_expires || '' ).slice( 0, 10 ) === pickedDay;
		t.check( 'Create stores every setting entered and lands on the coupon\'s page',
			!! made.coupon && ! off.length && expiresOk,
			JSON.stringify( { off: off.map( ( k ) => [ k, c[ k ], want[ k ] ] ), expires: [ c.date_expires, pickedDay ] } ) );
		await page.waitForSelector( '#minn-co-copy', { timeout: 20000 } );
		const reopened = await page.evaluate( () => ( {
			chips: [ ...document.querySelectorAll( '[data-plchips="product_ids"] [data-ptchip], [data-ptchips="product_categories"] [data-ptchip]' ) ].map( ( b ) => b.textContent.trim() ),
			bar: document.querySelector( '#minn-co-savebar' ).hidden,
		} ) );
		t.check( 'the saved coupon reads back with names on its chips and no pending changes',
			reopened.bar && reopened.chips.some( ( x ) => x.includes( 'Minn CPG Prod A' ) ) && reopened.chips.some( ( x ) => x.includes( 'Minn CPG Cat' ) ),
			JSON.stringify( reopened ) );

		// ---- An existing coupon saves only what changed ----
		await api( `wc/v3/coupons/${ made.coupon }`, { method: 'PUT', body: JSON.stringify( { usage_limit: 99 } ) } );
		await page.fill( '#minn-c-desc', 'Reworded' );
		await page.waitForSelector( '#minn-co-savebar:not([hidden])', { timeout: 5000 } );
		await page.click( '#minn-coupon-save' );
		await toastSays( /Coupon updated/ );
		const afterEdit = ( await api( `wc/v3/coupons/${ made.coupon }?_fields=description,usage_limit,product_ids` ) ).body || {};
		t.check( 'an edit sends only the changed field, keeping a limit changed elsewhere',
			'Reworded' === afterEdit.description && 99 === afterEdit.usage_limit && JSON.stringify( afterEdit.product_ids ) === JSON.stringify( [ made.products[ 0 ].id ] ),
			JSON.stringify( afterEdit ) );

		// Discard puts the form back as saved.
		await page.waitForSelector( '#minn-c-amount', { timeout: 10000 } );
		await page.fill( '#minn-c-amount', '33' );
		await page.waitForSelector( '#minn-co-savebar:not([hidden])', { timeout: 5000 } );
		await page.click( '#minn-co-discard' );
		await page.waitForTimeout( 300 );
		const discarded = await page.evaluate( () => ( { amount: document.querySelector( '#minn-c-amount' ).value, hidden: document.querySelector( '#minn-co-savebar' ).hidden } ) );
		t.check( 'Discard puts the saved values back and hides the bar', '15' === discarded.amount && discarded.hidden, JSON.stringify( discarded ) );

		// Unsaved work: leaving asks.
		await page.fill( '#minn-c-desc', 'About to leave' );
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		const asked = await page.waitForSelector( '[role="alertdialog"]', { timeout: 5000 } ).catch( () => null );
		t.check( 'leaving with an unsaved edit asks first', !! asked, '' );
		if ( asked ) await page.keyboard.press( 'Escape' );
		await page.click( '#minn-co-discard' );

		// ---- The orders that used it ----
		await api( `wc/v3/coupons/${ made.coupon }`, { method: 'PUT', body: JSON.stringify( { status: 'publish', product_ids: [], excluded_product_ids: [], product_categories: [], email_restrictions: [], minimum_amount: '', exclude_sale_items: false } ) } );
		const ord = await api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( {
			status: 'processing',
			billing: { email: 'buyer@example.com', first_name: 'Coupon', last_name: 'Buyer' },
			line_items: [ { product_id: made.products[ 0 ].id, quantity: 1 } ],
			coupon_lines: [ { code } ],
		} ) } );
		made.order = ord.body && ord.body.id;
		t.check( 'an order using the code was made', !! made.order, JSON.stringify( ord.body && ( ord.body.message || ord.status ) ) );
		await page.goto( BASE + '/minn-admin/coupons/' + made.coupon, { waitUntil: 'domcontentloaded' } );
		const usedRow = await page.waitForSelector( `#minn-co-orders [data-coorder="${ made.order }"]`, { timeout: 20000 } ).catch( () => null );
		t.check( 'the Orders card lists the order that used it', !! usedRow, '' );
		if ( usedRow ) {
			await usedRow.click();
			await page.waitForSelector( '#minn-op-back', { timeout: 20000 } );
			const back = await page.$eval( '#minn-op-back', ( b ) => b.textContent );
			t.check( 'the order leads back to the coupon', back.toLowerCase().includes( code.toLowerCase() ), back );
			await page.click( '#minn-op-back' );
			await page.waitForSelector( '#minn-co-delete', { timeout: 20000 } );
		}

		// ---- Delete from the page ----
		await page.click( '#minn-co-delete' );
		await page.waitForSelector( '[role="alertdialog"]', { timeout: 8000 } );
		await page.evaluate( () => [ ...document.querySelectorAll( '[role="alertdialog"] button' ) ].find( ( b ) => /Delete coupon/.test( b.textContent ) ).click() );
		await page.waitForFunction( () => /\/coupons$/.test( location.pathname ), null, { timeout: 15000 } ).catch( () => null );
		const gone = ( await api( `wc/v3/coupons/${ made.coupon }?_fields=id` ) ).status;
		t.check( 'Delete removes the coupon and returns to the list', 404 === gone && /\/coupons$/.test( new URL( page.url() ).pathname ), JSON.stringify( { gone, url: page.url() } ) );
		if ( 404 === gone ) made.coupon = null;

		// No coupons capability, no coupon page.
		const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		await author.page.goto( BASE + '/minn-admin/coupons/new', { waitUntil: 'domcontentloaded' } );
		await author.page.waitForSelector( '#minn-view', { timeout: 20000 } );
		await author.page.waitForTimeout( 1500 );
		t.check( 'an Author gets no coupon page', ! await author.page.$( '#minn-c-code' ), '' );
		await author.ctx.close();
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( made.order ) await api( `wc/v3/orders/${ made.order }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( made.coupon ) await api( `wc/v3/coupons/${ made.coupon }?force=true`, { method: 'DELETE' } ).catch( () => null );
		for ( const p of made.products ) await api( `wc/v3/products/${ p.id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( made.category ) await api( `wc/v3/products/categories/${ made.category.id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
