/**
 * Refund polish — per-line quantity steppers with auto-computed amounts,
 * restock through WC's api_restock, gateway-aware refund checkbox, enriched
 * refund history rows (when/who) and the delete-refund undo path.
 *
 * Fixtures: its own product (managed stock) and a paid check order, both
 * deleted on the way out. Check payments cannot push money back, so the
 * suite also pins the no-gateway-checkbox honest-copy path.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-refunds' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.orders ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce orders available', false, 'caps.orders missing — skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce orders available', true, '' );

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

	let productId = null;
	let orderId = null;
	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Refund Suite Widget ' + Date.now().toString( 36 ),
				regular_price: '10.00',
				manage_stock: true,
				stock_quantity: 5,
				status: 'publish',
			} ),
		} );
		t.check( 'created fixture product', prod.status === 201 && prod.body.id, String( prod.status ) );
		productId = prod.body && prod.body.id;

		const order = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( {
				status: 'processing',
				set_paid: true,
				payment_method: 'cheque',
				payment_method_title: 'Check payments',
				billing: { first_name: 'Refund', last_name: 'Fixture', email: 'refund-fixture@example.com' },
				line_items: [ { product_id: productId, quantity: 3 } ],
			} ),
		} );
		t.check( 'created paid fixture order (3× $10)', order.status === 201 && order.body.id, String( order.status ) );
		orderId = order.body && order.body.id;
		const lineItemId = order.body && order.body.line_items && order.body.line_items[ 0 ] && order.body.line_items[ 0 ].id;

		// Stock after the paid order — the refund's restock asserts against this.
		const stockAfterOrder = ( await api( `wc/v3/products/${ productId }?_fields=stock_quantity` ) ).body.stock_quantity;

		// The endpoint's own accounting before any refund.
		const rs0 = await api( `minn-admin/v1/wc/orders/${ orderId }/refund-state` );
		t.check( 'refund-state serves lines + gateway', rs0.status === 200 && rs0.body.lines && rs0.body.lines[ lineItemId ] && rs0.body.lines[ lineItemId ].qty_refunded === 0, JSON.stringify( rs0.body ) );
		t.check( 'check gateway cannot refund itself', !! ( rs0.body.gateway && rs0.body.gateway.can_refund === false ), JSON.stringify( rs0.body.gateway ) );

		// The order page's refund card.
		await page.goto( `${ BASE }/minn-admin/orders/${ orderId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-refund-qty', { timeout: 20000 } );
		t.check( 'per-line stepper renders', true, '' );
		const stepperMax = await page.evaluate( () => document.querySelector( '.minn-refund-qty' ).max );
		t.check( 'stepper max is the full quantity', stepperMax === '3', stepperMax );
		const hasApiBox = await page.evaluate( () => !! document.querySelector( '#minn-o-refund-api' ) );
		t.check( 'no gateway checkbox for check payments', ! hasApiBox, '' );
		const manualNote = await page.evaluate( () => {
			const card = document.querySelector( '.minn-order-refund' );
			return card ? card.textContent.includes( 'cannot send money back' ) : false;
		} );
		t.check( 'manual-record note names the gateway limit', manualNote, '' );
		t.check( 'restock checkbox present', await page.evaluate( () => !! document.querySelector( '#minn-o-refund-restock' ) ), '' );
		const amt0 = await page.evaluate( () => document.querySelector( '#minn-o-refund-amt' ).value );
		t.check( 'amount defaults to full remaining', amt0 === '30.00', amt0 );

		// Pick one unit — amount recomputes to one unit's money.
		await page.fill( '.minn-refund-qty', '1' );
		const amt1 = await page.evaluate( () => document.querySelector( '#minn-o-refund-amt' ).value );
		t.check( 'picking 1 unit computes 10.00', amt1 === '10.00', amt1 );

		await page.fill( '#minn-o-refund-reason', 'Suite refund' );
		await page.click( '#minn-o-refund' );
		await page.waitForSelector( '[data-rdel]', { timeout: 20000 } );
		t.check( 'refund row appears with delete affordance', true, '' );

		// Server truth: one refund, itemized to the line, restocked.
		const refunds = await api( `wc/v3/orders/${ orderId }/refunds` );
		t.check( 'one refund recorded', Array.isArray( refunds.body ) && refunds.body.length === 1, String( refunds.body.length ) );
		const rf = refunds.body[ 0 ] || {};
		t.check( 'refund amount is 10.00', parseFloat( rf.amount ) === 10, String( rf.amount ) );
		const rli = ( rf.line_items || [] )[ 0 ] || {};
		t.check( 'refund is itemized (qty −1 on the line)', rli.quantity === -1, JSON.stringify( rli.quantity ) );
		const stockAfterRefund = ( await api( `wc/v3/products/${ productId }?_fields=stock_quantity` ) ).body.stock_quantity;
		t.check( 'restock returned one unit', stockAfterRefund === stockAfterOrder + 1, `${ stockAfterOrder } → ${ stockAfterRefund }` );

		// Enriched history + updated accounting in the card.
		await page.waitForFunction( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-order-item' ) );
			return rows.some( ( r ) => /Refund · /.test( r.textContent ) && / by /.test( r.textContent ) );
		}, null, { timeout: 20000 } );
		t.check( 'refund row carries when and who', true, '' );
		await page.waitForFunction( () => {
			const meta = document.querySelector( '.minn-refund-line-meta' );
			return meta && meta.textContent.includes( '1 of 3 refunded' );
		}, null, { timeout: 20000 } );
		t.check( 'stepper line shows 1 of 3 refunded', true, '' );

		// Delete the refund record — totals restore, row goes away.
		await page.click( '[data-rdel]' );
		await page.waitForFunction( () => ! document.querySelector( '[data-rdel]' ), null, { timeout: 20000 } );
		const refundsAfter = await api( `wc/v3/orders/${ orderId }/refunds` );
		t.check( 'refund record deleted', Array.isArray( refundsAfter.body ) && refundsAfter.body.length === 0, String( refundsAfter.body.length ) );
	} finally {
		if ( orderId ) await api( `wc/v3/orders/${ orderId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( productId ) await api( `wc/v3/products/${ productId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
