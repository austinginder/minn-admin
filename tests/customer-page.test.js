/**
 * The customer page (/customers/{id}): WooCommerce's own lifetime figures,
 * the order history a page at a time, the Back trail to and from orders,
 * sidebar edits that send only what changed, a new order started from the
 * customer, and no page for someone without the customers capability.
 *
 * Fixtures (made here, swept by email at the start, deleted at the end): a
 * customer, a product and eleven orders for them, one of them paid.
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'customer-page' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const caps = await page.evaluate( () => ( { wc: !! window.MINN.wc, customers: !! ( window.MINN.caps || {} ).customers } ) );
	if ( ! caps.wc || ! caps.customers ) {
		console.log( 'SKIP  needs WooCommerce and the customers capability' );
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

	// A crashed earlier run leaves its customer behind; their orders go with
	// the sweep of orders by email below.
	const stale = await api( 'wc/v3/customers?search=minn-cp-&role=all&per_page=50&_fields=id,email' );
	for ( const c of ( Array.isArray( stale.body ) ? stale.body : [] ).filter( ( x ) => /^minn-cp-.*@example\.com$/.test( x.email || '' ) ) ) {
		const old = await api( `wc/v3/orders?customer=${ c.id }&per_page=50&_fields=id` );
		for ( const o of ( Array.isArray( old.body ) ? old.body : [] ) ) await api( `wc/v3/orders/${ o.id }?force=true`, { method: 'DELETE' } );
		await api( `wc/v3/customers/${ c.id }?force=true&reassign=0`, { method: 'DELETE' } );
	}

	const suffix = Date.now().toString( 36 );
	const email = `minn-cp-${ suffix }@example.com`;
	let customerId = null;
	let productId = null;
	const orderIds = [];
	try {
		const made = await api( 'wc/v3/customers', { method: 'POST', body: JSON.stringify( {
			email, first_name: 'Page', last_name: 'Customer', username: 'minn-cp-' + suffix,
			billing: { first_name: 'Page', last_name: 'Customer', email, address_1: '9 Old Road', city: 'Oldtown', postcode: '15001', state: 'PA', country: 'US', phone: '' },
		} ) } );
		customerId = made.body && made.body.id;
		const prod = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( { name: 'Minn CP Prod ' + suffix, type: 'simple', regular_price: '12.00', status: 'publish' } ) } );
		productId = prod.body && prod.body.id;
		for ( let i = 0; i < 11; i++ ) {
			const o = await api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( {
				customer_id: customerId,
				status: 0 === i ? 'processing' : 'pending',
				set_paid: 0 === i,
				billing: { first_name: 'Page', last_name: 'Customer', email },
				line_items: [ { product_id: productId, quantity: 1 } ],
			} ) } );
			if ( o.body && o.body.id ) orderIds.push( o.body.id );
		}
		t.check( 'fixtures: a customer, a product and eleven orders', !! customerId && !! productId && 11 === orderIds.length,
			JSON.stringify( { customer: made.status, product: prod.status, orders: orderIds.length } ) );
		if ( ! customerId || 11 !== orderIds.length ) throw new Error( 'fixtures missing' );

		await page.goto( BASE + '/minn-admin/customers/' + customerId, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-ccard="orders"] [data-cporder]', { timeout: 30000 } );
		const v2 = ( await api( `wc/v2/customers/${ customerId }?_fields=orders_count,total_spent` ) ).body || {};
		await page.waitForFunction( () => ! [ ...document.querySelectorAll( '.minn-cp-stats .minn-stat-value' ) ].some( ( v ) => '…' === v.textContent ), null, { timeout: 15000 } ).catch( () => null );
		const head = await page.evaluate( () => ( {
			title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
			paying: /Paying customer/.test( ( document.querySelector( '.minn-order-head-row' ) || {} ).textContent || '' ),
			tiles: Object.fromEntries( [ ...document.querySelectorAll( '.minn-cp-stats .minn-stat' ) ].map( ( s ) => [ s.querySelector( '.minn-stat-label' ).textContent, s.querySelector( '.minn-stat-value' ).textContent ] ) ),
			rows: document.querySelectorAll( '[data-cporder]' ).length,
			more: !! document.querySelector( '#minn-cp-more-orders' ),
		} ) );
		const sym = await page.evaluate( () => ( window.MINN.wcCurrency || {} ).symbol || '$' );
		t.check( 'the page names the customer and shows WooCommerce\'s own lifetime figures',
			'Page Customer' === head.title && String( v2.orders_count ) === head.tiles.Orders
				&& sym + parseFloat( v2.total_spent ).toFixed( 2 ) === head.tiles[ 'Total spent' ] && '—' !== head.tiles[ 'Last order' ],
			JSON.stringify( { head, v2 } ) );
		t.check( 'orders come ten at a time with a way to the rest', 10 === head.rows && head.more, JSON.stringify( head ) );
		await page.click( '#minn-cp-more-orders' );
		await page.waitForFunction( () => 11 === document.querySelectorAll( '[data-cporder]' ).length, null, { timeout: 15000 } ).catch( () => null );
		const all = await page.evaluate( () => ( { rows: document.querySelectorAll( '[data-cporder]' ).length, more: !! document.querySelector( '#minn-cp-more-orders' ) } ) );
		t.check( 'Show more orders brings the eleventh and then stops offering', 11 === all.rows && ! all.more, JSON.stringify( all ) );

		// An order opened from here leads back here, and the order's own
		// customer button leads back to the order.
		await page.click( '[data-cporder]' );
		await page.waitForSelector( '#minn-op-back', { timeout: 20000 } );
		const toOrder = await page.$eval( '#minn-op-back', ( b ) => b.textContent );
		t.check( 'an order opened from the customer leads back to them', /Page Customer/.test( toOrder ), toOrder );
		await page.waitForSelector( '[data-ocustomer]', { timeout: 20000 } );
		await page.click( '[data-ocustomer]' );
		await page.waitForSelector( '#minn-cp-back', { timeout: 20000 } );
		const toCustomer = await page.$eval( '#minn-cp-back', ( b ) => b.textContent );
		t.check( 'the order\'s customer button opens the customer, leading back to the order',
			page.url().endsWith( '/customers/' + customerId ) && /Order #/.test( toCustomer ), JSON.stringify( { url: page.url(), toCustomer } ) );
		await page.waitForSelector( '[data-cedit="billing"]', { timeout: 20000 } );

		// Billing: change the city while the phone changes elsewhere; the
		// save sends the city alone, so the new phone stays.
		await page.click( '[data-cedit="billing"]' );
		await page.waitForSelector( '.minn-order-submodal [data-caf="city"]', { timeout: 10000 } );
		await api( `wc/v3/customers/${ customerId }`, { method: 'PUT', body: JSON.stringify( { billing: { phone: '555-0101' } } ) } );
		await page.fill( '.minn-order-submodal [data-caf="city"]', 'Newtown' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 15000 } ).catch( () => null );
		const afterBill = ( await api( `wc/v3/customers/${ customerId }?_fields=billing,shipping,first_name,email` ) ).body || {};
		const billCard = await page.$eval( '[data-ccard="billing"] .minn-order-read', ( e ) => e.textContent ).catch( () => '' );
		t.check( 'a billing edit saves only the field changed', 'Newtown' === afterBill.billing.city && '555-0101' === afterBill.billing.phone && /Newtown/.test( billCard ),
			JSON.stringify( { billing: afterBill.billing, billCard } ) );

		// Shipping copied from billing.
		await page.click( '[data-cedit="shipping"]' );
		await page.waitForSelector( '.minn-order-submodal [data-ccopy]', { timeout: 10000 } );
		await page.click( '.minn-order-submodal [data-ccopy]' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 15000 } ).catch( () => null );
		const afterShip = ( await api( `wc/v3/customers/${ customerId }?_fields=shipping` ) ).body || {};
		t.check( 'Same as billing address fills the shipping address', 'Newtown' === afterShip.shipping.city && '9 Old Road' === afterShip.shipping.address_1 && 'Page' === afterShip.shipping.first_name,
			JSON.stringify( afterShip.shipping ) );

		// Profile: a new first name; the email is left as stored.
		await page.click( '[data-cedit="profile"]' );
		await page.waitForSelector( '.minn-order-submodal [data-caf="first_name"]', { timeout: 10000 } );
		await page.fill( '.minn-order-submodal [data-caf="first_name"]', 'Paige' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => /Paige/.test( ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent || '' ), null, { timeout: 15000 } ).catch( () => null );
		const afterProfile = ( await api( `wc/v3/customers/${ customerId }?_fields=first_name,email` ) ).body || {};
		t.check( 'a profile edit renames the customer and leaves the email', 'Paige' === afterProfile.first_name && email === afterProfile.email,
			JSON.stringify( afterProfile ) );

		// A new order started here starts from this customer, and Cancel
		// on the untouched form comes straight back.
		await page.click( '#minn-cp-neworder' );
		await page.waitForSelector( '#minn-no-custchip', { timeout: 20000 } );
		const prefilled = await page.evaluate( () => ( {
			chip: ( document.querySelector( '#minn-no-custchip' ) || {} ).textContent || '',
			city: ( document.querySelector( '#minn-ob-city' ) || {} ).value,
			back: ( document.querySelector( '#minn-op-back' ) || {} ).textContent || '',
			pristine: !! document.querySelector( '#minn-no-savebar[data-pristine]' ),
		} ) );
		t.check( 'New order starts from the customer, their details filled in', prefilled.chip.includes( email ) && 'Newtown' === prefilled.city && /Paige Customer/.test( prefilled.back ) && prefilled.pristine,
			JSON.stringify( prefilled ) );
		await page.click( '#minn-no-cancel' );
		await page.waitForSelector( '#minn-cp-back', { timeout: 20000 } );
		t.check( 'Cancel returns to the customer without asking', page.url().endsWith( '/customers/' + customerId ) && ! await page.$( '[role="alertdialog"]' ), page.url() );

		await page.click( '#minn-cp-more' );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 8000 } );
		const menu = await page.$$eval( '.minn-ctx-menu button, .minn-ctx-menu a', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'the ⋯ menu: email, orders, the user account and delete',
			menu.some( ( l ) => l.includes( email ) ) && menu.includes( 'View orders' ) && menu.includes( 'Open user account' ) && menu.some( ( l ) => /Delete customer/.test( l ) ),
			JSON.stringify( menu ) );
		await page.keyboard.press( 'Escape' );

		// No customers capability, no customer page.
		const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		await author.page.goto( BASE + '/minn-admin/customers/' + customerId, { waitUntil: 'domcontentloaded' } );
		await author.page.waitForSelector( '#minn-view', { timeout: 20000 } );
		await author.page.waitForTimeout( 1500 );
		const authorView = await author.page.$eval( '#minn-view', ( v ) => v.textContent ).catch( () => '' );
		t.check( 'an Author gets no customer page', ! /Paige|Newtown/.test( authorView ) && ! await author.page.$( '[data-ccard]' ), authorView.slice( 0, 120 ) );
		await author.ctx.close();
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		for ( const id of orderIds ) await api( `wc/v3/orders/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( productId ) await api( `wc/v3/products/${ productId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( customerId ) await api( `wc/v3/customers/${ customerId }?force=true&reassign=0`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
