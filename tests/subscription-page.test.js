/**
 * The /subscriptions/{id} page: a subscription is a place, in the same
 * Shopify shape the order detail wears. Deep link, two columns, the status
 * save on the page, related-order navigation with real URLs, and the modal
 * kept as Quick view.
 *
 * SKIPs cleanly when WooCommerce Subscriptions is not installed.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'subscription-page' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWcs = await page.evaluate( () => !!( window.MINN && window.MINN.wcs ) );
	if ( ! hasWcs ) {
		t.check( 'WooCommerce Subscriptions available', false, 'skip' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce Subscriptions available', true, '' );

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
	const email = `minn-subpage-${ suffix }@example.com`;
	let pid = null, subId = null, orderId = null;

	const pageReady = async () => {
		await page.waitForSelector( '.minn-sub-page .minn-order-main', { timeout: 25000 } );
		await page.waitForFunction( () => {
			const p = document.querySelector( '.minn-sub-page' );
			return p && ! p.querySelector( '.minn-loading' );
		}, null, { timeout: 20000 } );
	};

	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Sub Page Widget ' + suffix, type: 'simple', regular_price: '11.00', status: 'publish' } ),
		} );
		pid = prod.body && prod.body.id;
		const billing = { first_name: 'Paige', last_name: 'Subscriber', email, country: 'US' };
		// A parent order, so the page's related-order navigation has a target.
		const parent = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( { status: 'completed', set_paid: true, billing, line_items: [ { product_id: pid, quantity: 1 } ] } ),
		} );
		orderId = parent.body && parent.body.id;
		const sub = await api( 'wc/v3/subscriptions', {
			method: 'POST',
			body: JSON.stringify( {
				status: 'active',
				billing,
				parent_id: orderId,
				billing_period: 'month',
				billing_interval: 1,
				line_items: [ { product_id: pid, quantity: 2 } ],
				meta_data: [
					{ key: '_wc_order_attribution_source_type', value: 'typein' },
					{ key: '_wc_order_attribution_device_type', value: 'Mobile' },
					{ key: '_wc_order_attribution_session_pages', value: '4' },
				],
			} ),
		} );
		subId = sub.body && sub.body.id;
		t.check( 'fixtures created', !! ( pid && orderId && subId ), JSON.stringify( { pid, orderId, subId } ) );

		// ---- Deep link renders the page ----
		await page.setViewportSize( { width: 1440, height: 1000 } );
		await page.goto( `${ BASE }/minn-admin/subscriptions/${ subId }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		const head = await page.evaluate( () => ( {
			title: ( document.querySelector( '.minn-sub-page .minn-modal-title' ) || {} ).textContent || '',
			topbar: ( document.getElementById( 'minn-title' ) || {} ).textContent || '',
			nav: ( document.querySelector( '.minn-nav-btn.active' ) || { textContent: '' } ).textContent.trim(),
			back: !! document.getElementById( 'minn-sp-back' ),
			status: ( document.querySelector( '.minn-sub-page .minn-order-page-head .minn-status' ) || {} ).textContent || '',
		} ) );
		t.check( 'deep link renders the subscription page', head.title.includes( String( subId ) ) && head.back, JSON.stringify( head ) );
		t.check( 'topbar says Subscription, Subscriptions nav stays lit',
			/Subscription/i.test( head.topbar ) && head.nav.indexOf( 'Subscriptions' ) === 0, JSON.stringify( head ) );
		t.check( 'the header carries the status', /Active/i.test( head.status ), head.status );

		// ---- Two columns, like the order page ----
		const cols = await page.evaluate( () => {
			const main = document.querySelector( '.minn-sub-page .minn-order-main' );
			const side = document.querySelector( '.minn-sub-page .minn-order-side' );
			if ( ! main || ! side ) return { main: !! main, side: !! side };
			const mb = main.getBoundingClientRect(), sb = side.getBoundingClientRect();
			return { main: true, side: true, mainRight: mb.x + mb.width, sideX: sb.x, sameTop: Math.abs( mb.y - sb.y ) < 40 };
		} );
		t.check( 'desktop lays main and sidebar side by side',
			cols.main && cols.side && cols.sideX >= cols.mainRight - 1 && cols.sameTop, JSON.stringify( cols ) );

		const shape = await page.evaluate( () => {
			const main = document.querySelector( '.minn-order-main' );
			const side = document.querySelector( '.minn-order-side' );
			return {
				items: !! ( main && main.querySelector( '.minn-order-items' ) ),
				itemsMoney: !! main && /22\.00/.test( main.textContent ),
				schedule: !! ( side && side.querySelector( '.minn-sub-schedule' ) ),
				customer: !! ( side && side.querySelector( '.minn-order-customer' ) ),
				statusControl: !! ( main && main.querySelector( '[data-oc="substatus"]' ) ),
			};
		} );
		t.check( 'main carries the items with their money', shape.items && shape.itemsMoney, JSON.stringify( shape ) );
		t.check( 'sidebar carries customer and schedule', shape.customer && shape.schedule, JSON.stringify( shape ) );
		// WooCommerce Subscriptions records attribution on the subscription
		// with the same meta keys an order uses, so the card is the same card.
		const attrib = await page.evaluate( () => {
			const c = document.querySelector( '.minn-order-side .minn-order-attrib' );
			return { card: !! c, text: c ? c.textContent.replace( /\s+/g, ' ' ) : '' };
		} );
		t.check( 'sidebar shows the subscription attribution card',
			attrib.card && /Direct/.test( attrib.text ) && /Mobile/.test( attrib.text ), JSON.stringify( attrib ) );
		t.check( 'the status control is a Minn combobox, not an OS select', shape.statusControl, JSON.stringify( shape ) );

		// ---- Saving the status on the page persists through WC ----
		await page.click( '[data-oc="substatus"] .minn-ac-input' );
		await page.waitForSelector( '[data-oc="substatus"] .minn-ac-item[data-acv="on-hold"]', { timeout: 8000 } );
		await page.click( '[data-oc="substatus"] .minn-ac-item[data-acv="on-hold"]' );
		await page.click( '#minn-sub-save' );
		await page.waitForFunction( () => {
			const el = document.querySelector( '.minn-sub-page .minn-order-page-head .minn-status' );
			return el && /On hold/i.test( el.textContent );
		}, null, { timeout: 20000 } );
		const saved = await api( `wc/v3/subscriptions/${ subId }?_fields=status` );
		t.check( 'page status save persists through WC', saved.body && saved.body.status === 'on-hold', JSON.stringify( saved.body ) );

		// ---- Related order navigation is a real URL ----
		await page.click( `[data-relorder="${ orderId }"]` );
		await page.waitForFunction( ( id ) => location.pathname.indexOf( '/orders/' + id ) !== -1, orderId, { timeout: 15000 } );
		t.check( 'the parent order opens on its own page', true, await page.evaluate( () => location.pathname ) );

		// ---- Back to the list, and the row navigates ----
		await page.goto( `${ BASE }/minn-admin/subscriptions`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-sub="${ subId }"]`, { timeout: 25000 } );
		// Click the title cell, not the row's centre: the Items cell lives there
		// and swallows its own clicks so the list can pop instead of navigating.
		await page.click( `.minn-table-row[data-sub="${ subId }"] .minn-row-title` );
		await page.waitForFunction( ( id ) => location.pathname.indexOf( '/subscriptions/' + id ) !== -1, subId, { timeout: 15000 } );
		t.check( 'a row click navigates to the subscription page', true, '' );
		await pageReady();

		// ---- The modal survives as Quick view ----
		await page.goto( `${ BASE }/minn-admin/subscriptions`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-sub="${ subId }"]`, { timeout: 25000 } );
		await page.click( `.minn-table-row[data-sub="${ subId }"] .minn-row-quick` );
		await page.waitForSelector( '.minn-modal .minn-order-main', { timeout: 15000 } );
		const modal = await page.evaluate( () => {
			const mb = document.querySelector( '.minn-modal .minn-order-main' ).getBoundingClientRect();
			const sb = document.querySelector( '.minn-modal .minn-order-side' ).getBoundingClientRect();
			return { stacked: sb.y >= mb.y + mb.height - 1, fullPage: !! document.getElementById( 'minn-sub-fullpage' ) };
		} );
		t.check( 'quick view keeps one column and offers the full page', modal.stacked && modal.fullPage, JSON.stringify( modal ) );
		await page.click( '#minn-sub-fullpage' );
		await page.waitForFunction( ( id ) => location.pathname.indexOf( '/subscriptions/' + id ) !== -1, subId, { timeout: 15000 } );
		t.check( 'Open full page navigates and closes the modal',
			await page.evaluate( () => ! document.querySelector( '.minn-modal-overlay' ) ), '' );

		// ---- Narrow viewport stacks ----
		await pageReady(); // the full-page jump is still loading its detail
		await page.setViewportSize( { width: 700, height: 950 } );
		await page.waitForTimeout( 300 );
		const narrow = await page.evaluate( () => {
			const mb = document.querySelector( '.minn-order-main' ).getBoundingClientRect();
			const sb = document.querySelector( '.minn-order-side' ).getBoundingClientRect();
			return sb.y >= mb.y + mb.height - 1;
		} );
		t.check( 'narrow viewport stacks the sidebar below main', narrow, '' );
	} finally {
		if ( subId ) await api( `wc/v3/subscriptions/${ subId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( orderId ) await api( `wc/v3/orders/${ orderId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
