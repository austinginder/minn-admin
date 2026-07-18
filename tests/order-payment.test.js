/**
 * Order payment: manual receive (Record payment), payment method + transaction
 * ID editing, and the More-from-this-customer panel.
 *
 * Fixtures are created through wc/v3 as guest orders sharing one billing email
 * (exercises the email-search path of the related-orders fetch) and deleted at
 * the end. Needs the cheque gateway enabled (standing dev-site fixture).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-payment' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.orders ) );
	if ( ! hasWc ) {
		t.check( 'WooCommerce available', false, 'skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce available', true, '' );

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
	const email = `minn-paytest-${ suffix }@example.com`;
	let pid = null, aId = null, bId = null;

	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Minn Pay Test ' + suffix, type: 'simple', regular_price: '15.00', status: 'publish' } ),
		} );
		pid = prod.body && prod.body.id;
		t.check( 'created product', !! pid, String( prod.status ) );

		const billing = { first_name: 'Pat', last_name: 'Paytest', email, country: 'US' };
		const a = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( { status: 'pending', billing, line_items: [ { product_id: pid, quantity: 1 } ] } ),
		} );
		aId = a.body && a.body.id;
		t.check( 'created pending order A', !! aId && a.body.status === 'pending', JSON.stringify( { s: a.status, st: a.body && a.body.status } ) );

		const b = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( { status: 'pending', set_paid: true, billing, line_items: [ { product_id: pid, quantity: 2 } ] } ),
		} );
		bId = b.body && b.body.id;
		const bStatus = b.body && b.body.status;
		t.check( 'created paid order B', !! bId && !! ( b.body && b.body.date_paid ), JSON.stringify( { s: b.status, st: bStatus } ) );

		const openOrder = async ( id ) => {
			await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-order-search', { timeout: 20000 } );
			await page.fill( '#minn-order-search', String( id ) );
			await page.keyboard.press( 'Enter' );
			await page.waitForSelector( `.minn-table-row[data-order="${ id }"]`, { timeout: 20000 } );
			await page.click( `.minn-table-row[data-order="${ id }"]` );
			await page.waitForSelector( '.minn-order-payment', { timeout: 20000 } );
			await page.waitForFunction( () => ! document.querySelector( '.minn-order-payment .minn-loading' ), null, { timeout: 20000 } );
		};

		// Comboboxes (rule-70): click the input to open, click the option.
		const pickCombo = async ( key, value ) => {
			await page.click( `[data-oc="${ key }"] .minn-ac-input` );
			await page.waitForSelector( `[data-oc="${ key }"] .minn-ac-item[data-acv="${ value }"]`, { timeout: 8000 } );
			await page.click( `[data-oc="${ key }"] .minn-ac-item[data-acv="${ value }"]` );
		};

		// ---- Order A: unpaid → payment card offers Record payment ----
		await openOrder( aId );
		await page.click( '[data-oc="paymethod"] .minn-ac-input' );
		await page.waitForSelector( '[data-oc="paymethod"] .minn-ac-item', { timeout: 8000 } );
		const facts = await page.evaluate( () => ( {
			options: Array.from( document.querySelectorAll( '[data-oc="paymethod"] .minn-ac-item' ) ).map( ( b ) => b.dataset.acv ),
			record: !! document.getElementById( 'minn-o-recordpay' ),
			txn: !! document.getElementById( 'minn-o-txn' ),
		} ) );
		// Close the panel with a same-value pick (Escape would close the modal).
		await page.click( '[data-oc="paymethod"] .minn-ac-item[data-acv=""]' );
		t.check( 'payment picker offers N/A + cheque + Other', facts.options.includes( '' ) && facts.options.includes( 'cheque' ) && facts.options.includes( 'other' ), JSON.stringify( facts.options ) );
		t.check( 'unpaid order offers Record payment', facts.record && facts.txn, '' );

		// ---- Related orders panel finds B by billing email ----
		await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-order-others' );
			return p && ! p.querySelector( '.minn-loading' );
		}, null, { timeout: 20000 } );
		const rel = await page.evaluate( () => Array.from( document.querySelectorAll( '[data-relorder]' ) ).map( ( r ) => parseInt( r.dataset.relorder, 10 ) ) );
		t.check( 'other-orders panel lists sibling order', rel.includes( bId ), JSON.stringify( rel ) );
		const viewAllLabel = await page.evaluate( () => ( document.getElementById( 'minn-o-viewall' ) || {} ).textContent || '' );
		t.check( 'View all button carries the email', viewAllLabel.includes( email ), viewAllLabel );

		// ---- Record a check payment on A (pending → set_paid path) ----
		await pickCombo( 'paymethod', 'cheque' );
		await page.fill( '#minn-o-txn', 'CHK-' + suffix );
		await page.click( '#minn-o-recordpay' );
		await page.waitForFunction( () => {
			const card = document.querySelector( '.minn-order-payment' );
			return card && /Paid /.test( card.textContent ) && ! document.getElementById( 'minn-o-recordpay' );
		}, null, { timeout: 25000 } );
		t.check( 'card flips to Paid, Record button gone', true, '' );

		const aAfter = await api( `wc/v3/orders/${ aId }?_fields=status,payment_method,payment_method_title,transaction_id,date_paid` );
		t.check( 'A: WC recorded payment_complete', aAfter.body.status === 'processing' && !! aAfter.body.date_paid, JSON.stringify( aAfter.body ) );
		t.check( 'A: method + transaction persisted', aAfter.body.payment_method === 'cheque' && aAfter.body.transaction_id === 'CHK-' + suffix, JSON.stringify( aAfter.body ) );

		// ---- Row click swaps the modal to the sibling order ----
		await page.click( `[data-relorder="${ bId }"]` );
		await page.waitForFunction( ( num ) => {
			const el = document.querySelector( '.minn-modal-title' );
			return el && el.textContent.includes( String( num ) );
		}, bId, { timeout: 20000 } );
		t.check( 'clicking a related order opens it', true, '' );

		// ---- B is already paid: no Record button, Paid line instead ----
		// (the card must EXIST and be done loading — a not-yet-rendered card
		// makes a bare no-loading check vacuously true while B is still loading)
		await page.waitForFunction( () => {
			const card = document.querySelector( '.minn-order-payment' );
			return card && ! card.querySelector( '.minn-loading' );
		}, null, { timeout: 20000 } );
		const bCard = await page.evaluate( () => ( {
			record: !! document.getElementById( 'minn-o-recordpay' ),
			paid: /Paid /.test( ( document.querySelector( '.minn-order-payment' ) || {} ).textContent || '' ),
		} ) );
		t.check( 'paid order shows Paid line, no Record button', ! bCard.record && bCard.paid, JSON.stringify( bCard ) );

		// ---- Save-changes path: method edits persist without touching paid state ----
		const bBefore = await api( `wc/v3/orders/${ bId }?_fields=status,date_paid` );
		await pickCombo( 'paymethod', 'other' );
		await page.waitForFunction( () => {
			const w = document.getElementById( 'minn-o-paytitle-wrap' );
			return w && w.style.display !== 'none';
		}, null, { timeout: 8000 } );
		t.check( 'Other reveals the method-name field', true, '' );
		await page.fill( '#minn-o-paytitle', 'Wire transfer' );
		await page.click( '#minn-order-save' );
		let bAfter = null;
		for ( let i = 0; i < 10; i++ ) {
			bAfter = await api( `wc/v3/orders/${ bId }?_fields=status,payment_method,payment_method_title,date_paid` );
			if ( bAfter.body && bAfter.body.payment_method === 'other' ) break;
			await page.waitForTimeout( 800 );
		}
		t.check( 'B: Other method + custom title saved', bAfter.body.payment_method === 'other' && bAfter.body.payment_method_title === 'Wire transfer', JSON.stringify( bAfter.body ) );
		t.check( 'B: paid state untouched by normal save', bAfter.body.status === bBefore.body.status && !! bAfter.body.date_paid, JSON.stringify( { before: bBefore.body.status, after: bAfter.body.status } ) );

		// ---- View all: orders list pre-filtered by the customer email ----
		await page.click( '#minn-o-viewall' );
		await page.waitForFunction( ( em ) => {
			const s = document.getElementById( 'minn-order-search' );
			return s && s.value === em;
		}, email, { timeout: 20000 } );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-table-row[data-order]' ).length >= 2, null, { timeout: 20000 } );
		const listed = await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-table-row[data-order]' ) ).map( ( r ) => parseInt( r.dataset.order, 10 ) ) );
		t.check( 'View all filters the orders list to the customer', listed.includes( aId ) && listed.includes( bId ), JSON.stringify( listed ) );
	} finally {
		if ( aId ) await api( `wc/v3/orders/${ aId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( bId ) await api( `wc/v3/orders/${ bId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
