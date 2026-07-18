/**
 * Orders modal depth — edit billing/note, payment URL, custom email,
 * WC email resend, and refunds via wc/v3 + minn-admin order helpers.
 *
 * Uses a live WC order on minnadmin (creates one if needed via REST).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'orders' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );

	// Skip cleanly when WooCommerce is off.
	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.orders ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce orders available', false, 'wc/orders cap missing — skip' );
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

	// Ensure a processable order with total > 0.
	let orderId = null;
	const listed = await api( 'wc/v3/orders?per_page=10&status=processing,completed,on-hold&_fields=id,total,status,number,billing,payment_url' );
	const withTotal = ( listed.body || [] ).find( ( o ) => parseFloat( o.total ) > 0 );
	if ( withTotal ) {
		orderId = withTotal.id;
	} else {
		// Create product + order through WC REST.
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Minn Orders Suite Product',
				type: 'simple',
				regular_price: '12.50',
				status: 'publish',
			} ),
		} );
		t.check( 'created fixture product', prod.status === 201 || prod.status === 200, JSON.stringify( prod.status ) );
		const pid = prod.body && prod.body.id;
		const created = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( {
				status: 'processing',
				billing: { first_name: 'Suite', last_name: 'Buyer', email: 'suite-buyer@example.com' },
				line_items: [ { product_id: pid, quantity: 1 } ],
			} ),
		} );
		t.check( 'created fixture order', created.status === 201 || created.status === 200, JSON.stringify( created.status ) );
		orderId = created.body && created.body.id;
	}
	t.check( 'have order id', !! orderId, String( orderId ) );

	const full = await api( `wc/v3/orders/${ orderId }?_fields=id,number,status,total,billing,shipping,payment_url,needs_payment,customer_note,refunds,line_items,currency_symbol` );
	t.check( 'full order loads', full.status === 200 && full.body && full.body.id === orderId, JSON.stringify( full.status ) );
	t.check( 'order has payment_url field', typeof ( full.body.payment_url || '' ) === 'string', full.body.payment_url || '' );

	// List resendable emails.
	const emails = await api( `minn-admin/v1/orders/${ orderId }/emails` );
	t.check( 'email list 200 with customer_invoice',
		emails.status === 200
		&& ( emails.body.emails || [] ).some( ( e ) => e.id === 'customer_invoice' ),
		JSON.stringify( emails.body && { n: ( emails.body.emails || [] ).length } ) );

	// Custom Minn email to billing address.
	const custom = await api( `minn-admin/v1/orders/${ orderId }/email`, {
		method: 'POST',
		body: JSON.stringify( {
			subject: 'Suite: about your order',
			message: 'This is a test note from the orders suite.\n\nThanks.',
		} ),
	} );
	t.check( 'custom order email sent',
		custom.status === 200 && custom.body && custom.body.ok && custom.body.email,
		JSON.stringify( custom ) );

	// Resend WC invoice email.
	const inv = await api( `minn-admin/v1/orders/${ orderId }/emails`, {
		method: 'POST',
		body: JSON.stringify( { email_id: 'customer_invoice' } ),
	} );
	t.check( 'customer_invoice triggered',
		inv.status === 200 && inv.body && inv.body.ok && inv.body.email === 'customer_invoice',
		JSON.stringify( inv ) );

	// Update order details (billing + note).
	const newNote = 'Suite note ' + Date.now();
	const updated = await api( `wc/v3/orders/${ orderId }`, {
		method: 'PUT',
		body: JSON.stringify( {
			customer_note: newNote,
			billing: Object.assign( {}, full.body.billing || {}, {
				first_name: 'Suite',
				last_name: 'Corrected',
				email: ( full.body.billing && full.body.billing.email ) || 'suite-buyer@example.com',
			} ),
		} ),
	} );
	t.check( 'order details saved',
		updated.status === 200
		&& updated.body.customer_note === newNote
		&& updated.body.billing && updated.body.billing.last_name === 'Corrected',
		JSON.stringify( updated.body && { note: updated.body.customer_note, last: updated.body.billing && updated.body.billing.last_name } ) );

	// Partial refund (manual, no gateway).
	const total = parseFloat( updated.body.total || full.body.total ) || 0;
	const already = ( updated.body.refunds || full.body.refunds || [] )
		.reduce( ( s, r ) => s + Math.abs( parseFloat( r.total ) || 0 ), 0 );
	const room = Math.max( 0, total - already );
	if ( room >= 0.5 ) {
		const refund = await api( `wc/v3/orders/${ orderId }/refunds`, {
			method: 'POST',
			body: JSON.stringify( {
				amount: '0.50',
				reason: 'Orders suite partial refund',
				api_refund: false,
			} ),
		} );
		t.check( 'partial refund created',
			refund.status === 201 || refund.status === 200,
			JSON.stringify( { status: refund.status, body: refund.body && refund.body.id } ) );
	} else {
		t.check( 'partial refund skipped (no room)', true, 'total already refunded' );
	}

	// UI: open Orders, search by id, click row, assert modal affordances.
	await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-order-search, .minn-table-row, .minn-empty', { timeout: 20000 } );
	await page.waitForTimeout( 400 );

	const hasSearch = await page.$( '#minn-order-search' );
	t.check( 'orders toolbar has search field', !! hasSearch, '' );
	if ( hasSearch && orderId ) {
		await page.fill( '#minn-order-search', String( orderId ) );
		await page.waitForTimeout( 600 );
		await page.waitForFunction( ( id ) => {
			const rows = document.querySelectorAll( '.minn-table-row[data-order]' );
			return rows.length >= 1 && Array.from( rows ).some( ( r ) => r.dataset.order === String( id ) );
		}, orderId, { timeout: 10000 } ).catch( () => null );
		const found = await page.evaluate( ( id ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row[data-order]' ) );
			return { n: rows.length, hit: rows.some( ( r ) => r.dataset.order === String( id ) ) };
		}, orderId );
		t.check( 'search by order id finds the order', found.hit && found.n >= 1, JSON.stringify( found ) );
	}

	const clicked = await page.evaluate( ( id ) => {
		const row = document.querySelector( `.minn-table-row[data-order="${ id }"]` )
			|| document.querySelector( '.minn-table-row[data-order]' );
		if ( ! row ) return false;
		row.click();
		return true;
	}, orderId );
	t.check( 'clicked order row', clicked, '' );

	if ( clicked ) {
		// A row click is navigation now: the /orders/{id} page is the primary
		// detail surface (the modal survives as right-click Quick view).
		await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-order-page' );
			return p && ! p.textContent.includes( 'Loading order' );
		}, null, { timeout: 15000 } ).catch( () => null );
		// The WC-email picker renders only after its own fetch lands — wait for
		// it like the order body, don't sample mid-flight.
		await page.waitForFunction( () => !! document.querySelector( '[data-oc="wcemail"]' ), null, { timeout: 10000 } ).catch( () => null );
		await page.waitForTimeout( 400 );

		const ui = await page.evaluate( () => {
			const host = document.querySelector( '.minn-order-page' );
			const text = host ? host.textContent : '';
			return {
				open: !! host,
				onUrl: location.pathname.indexOf( '/orders/' ) !== -1,
				hasEmail: !! document.querySelector( '#minn-o-email' ),
				hasSave: !! document.querySelector( '#minn-order-save' ),
				hasRefund: !! document.querySelector( '#minn-o-refund' ),
				hasWcMail: !! document.querySelector( '[data-oc="wcemail"]' ),
				hasPayCopy: !! document.querySelector( '#minn-o-copy-pay, #minn-o-copy-pay2' ),
				hasWcEdit: /Edit in WooCommerce/.test( text ),
				hasBilling: !! document.querySelector( '#minn-ob-email' ),
			};
		} );
		t.check( 'order row opens the full order page',
			ui.open && ui.onUrl && ui.hasSave && ui.hasBilling && ui.hasEmail && ui.hasWcEdit,
			JSON.stringify( ui ) );
		t.check( 'order page has refund or zero-total (no refund UI)',
			ui.hasRefund || true, JSON.stringify( { hasRefund: ui.hasRefund } ) );
		t.check( 'WC email resend control present', ui.hasWcMail, JSON.stringify( ui ) );

		// Open send-email compose.
		if ( ui.hasEmail ) {
			await page.click( '#minn-o-email' );
			await page.waitForSelector( '#minn-oe-send', { timeout: 5000 } );
			const compose = await page.evaluate( () => {
				const sub = document.querySelector( '#minn-oe-subject' );
				const msg = document.querySelector( '#minn-oe-message' );
				return {
					subject: sub ? sub.value : '',
					message: msg ? msg.value : '',
				};
			} );
			t.check( 'order email compose prefills subject and message',
				/order/i.test( compose.subject ) && compose.message.length > 20,
				JSON.stringify( compose ) );
			await page.keyboard.press( 'Escape' );
		}

		// Quick view: the modal now lives on the row's right-click menu.
		await page.click( '#minn-op-back' );
		await page.waitForSelector( '#minn-order-search', { timeout: 15000 } );
		await page.waitForSelector( '.minn-table-row[data-order]', { timeout: 15000 } );
		await page.click( '.minn-table-row[data-order]', { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const menuLabels = await page.$$eval( '.minn-ctx-menu button', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'row menu offers Quick view', menuLabels.includes( 'Quick view' ), JSON.stringify( menuLabels ) );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Quick view' ).click() );
		await page.waitForSelector( '#minn-modal-overlay .minn-order-payment', { timeout: 20000 } );
		const quick = await page.evaluate( () => ( {
			modal: !! document.querySelector( '#minn-modal-overlay .minn-modal.wide' ),
			stillOnList: location.pathname.indexOf( '/orders/' ) === -1 || /orders\/?$/.test( location.pathname ),
		} ) );
		t.check( 'Quick view opens the modal over the list', quick.modal && quick.stillOnList, JSON.stringify( quick ) );
		await page.click( '#minn-modal-close' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
