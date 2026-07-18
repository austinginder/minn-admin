/**
 * The /orders/{id} page: deep link, shared detail body (record payment, save),
 * related-order navigation with real URLs, and the modal's escape hatch.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-page' );

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
	const email = `minn-pagetest-${ suffix }@example.com`;
	let pid = null, aId = null, bId = null;

	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Minn Page Test ' + suffix, type: 'simple', regular_price: '12.00', status: 'publish' } ),
		} );
		pid = prod.body && prod.body.id;
		const billing = { first_name: 'Page', last_name: 'Tester', email, country: 'US' };
		const a = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( { status: 'pending', billing, line_items: [ { product_id: pid, quantity: 1 } ] } ),
		} );
		aId = a.body && a.body.id;
		const b = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( { status: 'pending', set_paid: true, billing, line_items: [ { product_id: pid, quantity: 2 } ] } ),
		} );
		bId = b.body && b.body.id;
		t.check( 'fixtures created', !! ( pid && aId && bId ), JSON.stringify( { pid, aId, bId } ) );

		const pageReady = async () => {
			await page.waitForSelector( '.minn-order-page .minn-order-payment', { timeout: 25000 } );
			await page.waitForFunction( () => {
				const card = document.querySelector( '.minn-order-payment' );
				return card && ! card.querySelector( '.minn-loading' );
			}, null, { timeout: 20000 } );
		};
		const pickCombo = async ( key, value ) => {
			await page.click( `[data-oc="${ key }"] .minn-ac-input` );
			await page.waitForSelector( `[data-oc="${ key }"] .minn-ac-item[data-acv="${ value }"]`, { timeout: 8000 } );
			await page.click( `[data-oc="${ key }"] .minn-ac-item[data-acv="${ value }"]` );
		};

		// ---- Deep link renders the page ----
		await page.goto( `${ BASE }/minn-admin/orders/${ aId }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		const head = await page.evaluate( () => ( {
			title: document.querySelector( '.minn-order-page .minn-modal-title' ).textContent,
			topbar: document.getElementById( 'minn-title' ).textContent,
			nav: ( document.querySelector( '.minn-nav-btn.active' ) || { textContent: '' } ).textContent.trim(),
			noFullPageBtn: ! document.getElementById( 'minn-o-fullpage' ),
			back: !! document.getElementById( 'minn-op-back' ),
		} ) );
		t.check( 'deep link renders the order page', head.title.includes( String( aId ) ) && head.back, JSON.stringify( head ) );
		t.check( 'topbar says Order, Orders nav stays lit', head.topbar === 'Order' && head.nav.indexOf( 'Orders' ) === 0, JSON.stringify( head ) );
		t.check( 'page hides its own escape hatch', head.noFullPageBtn, '' );

		// ---- Record a payment ON THE PAGE (shared machinery in page host) ----
		await pickCombo( 'paymethod', 'cheque' );
		await page.fill( '#minn-o-txn', 'PCHK-' + suffix );
		await page.click( '#minn-o-recordpay' );
		await page.waitForFunction( () => {
			const card = document.querySelector( '.minn-order-payment' );
			return card && /Paid /.test( card.textContent ) && ! document.getElementById( 'minn-o-recordpay' );
		}, null, { timeout: 25000 } );
		const aAfter = await api( `wc/v3/orders/${ aId }?_fields=status,payment_method,transaction_id,date_paid` );
		t.check( 'page Record payment persists through WC', aAfter.body.status === 'processing' && aAfter.body.payment_method === 'cheque' && aAfter.body.transaction_id === 'PCHK-' + suffix && !! aAfter.body.date_paid, JSON.stringify( aAfter.body ) );
		const headPill = await page.evaluate( () => document.querySelector( '.minn-order-page-head .minn-status' ).textContent.trim() );
		t.check( 'page header pill follows the status', headPill === 'processing', headPill );

		// ---- Related order navigates with a real URL ----
		await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-order-others' );
			return p && ! p.querySelector( '.minn-loading' );
		}, null, { timeout: 20000 } );
		const rel = await page.evaluate( () => Array.from( document.querySelectorAll( '[data-relorder]' ) ).map( ( r ) => parseInt( r.dataset.relorder, 10 ) ) );
		t.check( 'related panel lists the sibling', rel.includes( bId ), JSON.stringify( rel ) );
		await page.click( `[data-relorder="${ bId }"]` );
		await page.waitForFunction( ( oid ) => location.pathname.indexOf( '/orders/' + oid ) !== -1, bId, { timeout: 15000 } );
		await pageReady();
		const bTitle = await page.evaluate( () => document.querySelector( '.minn-order-page .minn-modal-title' ).textContent );
		t.check( 'related click navigates to the sibling page', bTitle.includes( String( bId ) ), bTitle );

		// ---- Save on the page host (status via combobox) ----
		await pickCombo( 'status', 'completed' );
		await page.click( '#minn-order-save' );
		let bAfter = null;
		for ( let i = 0; i < 10; i++ ) {
			bAfter = await api( `wc/v3/orders/${ bId }?_fields=status` );
			if ( bAfter.body && bAfter.body.status === 'completed' ) break;
			await page.waitForTimeout( 800 );
		}
		t.check( 'page Save changes persists the status', bAfter.body.status === 'completed', JSON.stringify( bAfter.body ) );

		// ---- Back button returns to the list ----
		await page.click( '#minn-op-back' );
		await page.waitForSelector( '#minn-order-search', { timeout: 15000 } );
		t.check( 'back button returns to Orders', true, '' );

		// ---- Quick view (right-click) and its escape hatch back to the page ----
		await page.fill( '#minn-order-search', String( aId ) );
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( `.minn-table-row[data-order="${ aId }"]`, { timeout: 20000 } );
		await page.click( `.minn-table-row[data-order="${ aId }"]`, { button: 'right' } );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Quick view' ).click() );
		await page.waitForSelector( '#minn-modal-overlay #minn-o-fullpage', { timeout: 20000 } );
		t.check( 'right-click Quick view opens the modal', true, '' );
		await page.click( '#minn-o-fullpage' );
		await page.waitForFunction( ( oid ) => location.pathname.indexOf( '/orders/' + oid ) !== -1 && !! document.querySelector( '.minn-order-page' ), aId, { timeout: 15000 } );
		const modalGone = await page.evaluate( () => ! document.querySelector( '.minn-modal-overlay' ) );
		t.check( 'modal Open full page navigates and closes the modal', modalGone, '' );
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
