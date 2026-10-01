/**
 * The Payment card's link to the payment at its provider: the URL WooCommerce's
 * order screen puts on the transaction ID, resolved through the gateway's own
 * get_transaction_url(). The cheque gateway has no link of its own, so the
 * dev-fixtures mu-plugin supplies one for MINNTXN- IDs (and a javascript: URL
 * for MINNJS- IDs, which must never reach an href).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-transaction-link' );

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
	const txn = 'MINNTXN-' + suffix;
	const ids = [];
	let pid = null;

	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Minn Txn Test ' + suffix, type: 'simple', regular_price: '9.00', status: 'publish' } ),
		} );
		pid = prod.body && prod.body.id;
		const mk = async ( extra ) => {
			const r = await api( 'wc/v3/orders', {
				method: 'POST',
				body: JSON.stringify( Object.assign( {
					status: 'pending',
					set_paid: true,
					billing: { first_name: 'Txn', last_name: 'Tester', email: `minn-txn-${ suffix }@example.com`, country: 'US' },
					line_items: [ { product_id: pid, quantity: 1 } ],
				}, extra ) ),
			} );
			if ( r.body && r.body.id ) ids.push( r.body.id );
			return r.body && r.body.id;
		};
		const linked = await mk( { payment_method: 'cheque', payment_method_title: 'Check payments', transaction_id: txn } );
		const hostile = await mk( { payment_method: 'cheque', payment_method_title: 'Check payments', transaction_id: 'MINNJS-' + suffix } );
		const other = await mk( { payment_method: 'other', payment_method_title: 'Wire', transaction_id: 'MINNTXN-other-' + suffix } );
		t.check( 'fixtures created', !! ( pid && linked && hostile && other ), JSON.stringify( ids ) );

		// ---- REST field ----
		const one = await api( `wc/v3/orders/${ linked }?_fields=id,minn_transaction` );
		const mt = one.body && one.body.minn_transaction;
		t.check( 'field carries the gateway link', !! mt && mt.url === 'https://payments.example/txn/' + txn, JSON.stringify( one.body ) );
		t.check( 'field names the provider by its admin title', !! mt && mt.provider === 'Check payments', JSON.stringify( mt ) );
		const js = await api( `wc/v3/orders/${ hostile }?_fields=id,minn_transaction` );
		t.check( 'a javascript: link from the filter is refused', js.body && js.body.minn_transaction === null, JSON.stringify( js.body ) );
		const oth = await api( `wc/v3/orders/${ other }?_fields=id,minn_transaction` );
		t.check( 'an Other payment gets no link (as wp-admin)', oth.body && oth.body.minn_transaction === null, JSON.stringify( oth.body ) );
		const lean = await api( `wc/v3/orders/${ linked }?_fields=id,transaction_id` );
		t.check( 'field is only computed when asked for', lean.body && ! ( 'minn_transaction' in lean.body ), JSON.stringify( lean.body ) );

		// ---- Payment card ----
		const pageReady = async () => {
			await page.waitForSelector( '.minn-order-page .minn-order-payment', { timeout: 25000 } );
			await page.waitForFunction( () => {
				const card = document.querySelector( '.minn-order-payment' );
				return card && ! card.querySelector( '.minn-loading' );
			}, null, { timeout: 20000 } );
		};
		await page.goto( `${ BASE }/minn-admin/orders/${ linked }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		const link = await page.evaluate( () => {
			const a = document.getElementById( 'minn-o-txn-link' );
			return a ? { text: a.textContent.trim(), href: a.href, target: a.target, rel: a.rel, visible: a.checkVisibility() } : null;
		} );
		t.check( 'card shows the provider link beside the ID', !! link && link.visible && /View in Check payments/.test( link.text ), JSON.stringify( link ) );
		t.check( 'link opens the provider page in a new tab', !! link && link.href === 'https://payments.example/txn/' + txn && link.target === '_blank' && /noopener/.test( link.rel ), JSON.stringify( link ) );

		const hiddenNow = () => page.evaluate( () => document.getElementById( 'minn-o-txn-link' ).hidden );
		await page.click( '#minn-o-txn' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( 'X' );
		t.check( 'editing the ID hides the stale link', await hiddenNow() === true, '' );
		await page.keyboard.press( 'Backspace' );
		t.check( 'putting the ID back shows it again', await hiddenNow() === false, '' );

		// Picking another method hides it too: the link belongs to the saved one.
		await page.click( '[data-oc="paymethod"] .minn-ac-input' );
		await page.waitForSelector( '[data-oc="paymethod"] .minn-ac-item[data-acv="other"]', { timeout: 8000 } );
		await page.click( '[data-oc="paymethod"] .minn-ac-item[data-acv="other"]' );
		t.check( 'changing the method hides the link', await hiddenNow() === true, '' );
		await page.click( '[data-oc="paymethod"] .minn-ac-input' );
		await page.waitForSelector( '[data-oc="paymethod"] .minn-ac-item[data-acv="cheque"]', { timeout: 8000 } );
		await page.click( '[data-oc="paymethod"] .minn-ac-item[data-acv="cheque"]' );
		t.check( 'picking the saved method back shows it', await hiddenNow() === false, '' );

		// Saving a new ID refetches the order, and the link follows it.
		const txn2 = txn + '-B';
		await page.fill( '#minn-o-txn', txn2 );
		const saved = page.waitForResponse( ( r ) => r.url().indexOf( `wc/v3/orders/${ linked }` ) !== -1 && r.request().method() === 'PUT', { timeout: 25000 } );
		await page.click( '#minn-order-save' );
		await saved;
		await page.waitForFunction( ( want ) => {
			const a = document.getElementById( 'minn-o-txn-link' );
			return a && ! a.hidden && a.href === want;
		}, 'https://payments.example/txn/' + txn2, { timeout: 20000 } ).then( () => true ).catch( () => false )
			.then( ( ok ) => t.check( 'link follows a saved transaction ID', ok, '' ) );

		// ---- Phone width: the fields stack and the link stays whole ----
		await page.setViewportSize( { width: 390, height: 844 } );
		await page.waitForTimeout( 300 );
		const narrow = await page.evaluate( () => {
			const card = document.querySelector( '.minn-order-payment' );
			const row = card.querySelector( '.minn-order-field-row' );
			const [ a, b ] = row.children;
			const ra = a.getBoundingClientRect(), rb = b.getBoundingClientRect();
			const link = document.getElementById( 'minn-o-txn-link' );
			return { stacked: rb.top >= ra.bottom - 1, whole: link.scrollWidth <= link.clientWidth + 1, pageX: document.documentElement.scrollWidth <= window.innerWidth };
		} );
		t.check( 'at 390px method and ID stack', narrow.stacked, JSON.stringify( narrow ) );
		t.check( 'at 390px the link is not truncated', narrow.whole, JSON.stringify( narrow ) );
		await page.setViewportSize( { width: 1440, height: 900 } );

		// ---- No link where there is none ----
		await page.goto( `${ BASE }/minn-admin/orders/${ hostile }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		const none = await page.evaluate( () => ! document.getElementById( 'minn-o-txn-link' ) );
		t.check( 'refused link renders nothing', none, '' );
	} finally {
		for ( const id of ids ) await api( `wc/v3/orders/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
