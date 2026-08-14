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
	let flatCoupon = null, recCoupon = null, recProd = null, recSub = null;

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

		// ---- Items are editable, and the money follows the quantity ----
		await page.click( '[data-soedit="items"]' );
		await page.waitForSelector( '.minn-order-submodal [data-eiqty]', { timeout: 10000 } );
		const qtyInput = '.minn-order-submodal [data-eiqty]';
		await page.fill( qtyInput, '3' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 20000 } );
		// What the card shows is not the point; what WooCommerce stored is.
		const savedItems = await api( `wc/v3/subscriptions/${ subId }?_fields=line_items,total` );
		const li = ( savedItems.body && savedItems.body.line_items || [] )[ 0 ] || {};
		t.check( 'the items dialog saves the quantity through WC',
			li.quantity === 3, JSON.stringify( { quantity: li.quantity, total: li.total } ) );
		// 2 x 11.00 became 3 x 11.00: the line money is rescaled from the unit
		// price, because WC's REST keeps the old totals otherwise.
		t.check( 'the line money is rescaled with the quantity',
			parseFloat( li.total ) === 33, JSON.stringify( { total: li.total, subtotal: li.subtotal } ) );
		t.check( 'the card repaints with the new total',
			await page.evaluate( () => /33\.00/.test( document.querySelector( '.minn-order-main' ).textContent ) ), '' );

		// ---- The schedule is editable, and the timezone survives the trip ----
		// WooCommerce Subscriptions READS dates as *_date_gmt and WRITES them
		// without the suffix, interpreting the value as GMT. The dialog shows
		// site time like every other date here, so a correct save is the only
		// way both conversions can be right.
		// A day late in the current month grid: future, and reachable without
		// paging the calendar. The expected GMT is computed from the site's own
		// offset, so the check means something on any timezone.
		const want = await page.evaluate( () => {
			const off = window.MINN.gmtOffset || 0;
			const d = new Date();
			d.setDate( d.getDate() + 12 );
			const p = ( n ) => String( n ).padStart( 2, '0' );
			const day = `${ d.getFullYear() }-${ p( d.getMonth() + 1 ) }-${ p( d.getDate() ) }`;
			const asUtc = new Date( day + 'T10:20:00Z' ).getTime() - off * 3600000;
			return { day, gmt: new Date( asUtc ).toISOString().slice( 0, 19 ), off };
		} );
		await page.click( '[data-soedit="schedule"]' );
		await page.waitForSelector( '#minn-ss-next', { timeout: 10000 } );
		const startReadOnly = await page.evaluate( () => ! document.querySelector( '#minn-ss-start' ) );
		t.check( 'the schedule dialog leaves the start date alone', startReadOnly, '' );
		t.check( 'the date field is the themed picker, not an OS control',
			await page.$eval( '#minn-ss-next', ( i ) => i.readOnly && i.type === 'text' && i.classList.contains( 'minn-dp-input' ) ), '' );
		await page.click( '#minn-ss-next' );
		await page.waitForSelector( '.minn-dp-pop', { timeout: 8000 } );
		// The popover repaints once its month marks land, replacing the day
		// buttons; clicking into that repaint races it.
		await page.waitForTimeout( 350 );
		await page.click( `.minn-dp-day[data-day="${ want.day }"]` );
		await page.fill( '.minn-dp-time-input', '10:20 am' );
		// Done commits and closes. Enter only commits: the popover would stay
		// open over the rest of the dialog and swallow the next field's clicks.
		await page.click( '[data-dp-done]' );
		await page.waitForFunction( ( d ) => ! document.querySelector( '.minn-dp-pop' ) &&
			( document.querySelector( '#minn-ss-next' ).dataset.dp || '' ) === d + 'T10:20',
		want.day, { timeout: 8000 } );
		await page.fill( '#minn-ss-interval', '2' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 20000 } );
		const sched = await api( `wc/v3/subscriptions/${ subId }?_fields=next_payment_date_gmt,billing_interval,billing_period` );
		t.check( 'the next payment date saves as GMT, not as site time',
			sched.body && sched.body.next_payment_date_gmt === want.gmt,
			JSON.stringify( { got: sched.body && sched.body.next_payment_date_gmt, want: want.gmt, gmtOffset: want.off } ) );
		t.check( 'the billing interval saves with it',
			sched.body && String( sched.body.billing_interval ) === '2',
			JSON.stringify( { interval: sched.body && sched.body.billing_interval, period: sched.body && sched.body.billing_period } ) );

		// ---- Coupons: only recurring ones, and WooCommerce says so itself ----
		// This subscription's line is a plain product, which is exactly the
		// case WooCommerce refuses — assert the refusal reaches the user.
		const flat = await api( 'wc/v3/coupons', {
			method: 'POST',
			body: JSON.stringify( { code: 'subflat' + suffix, discount_type: 'percent', amount: '10' } ),
		} );
		flatCoupon = flat.body && flat.body.id;
		await page.click( '[data-soedit="coupons"]' );
		await page.waitForSelector( '.minn-order-submodal #minn-ec-code', { timeout: 10000 } );
		await page.fill( '#minn-ec-code', flat.body.code );
		await page.click( '#minn-ec-add' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForSelector( '.minn-toast', { timeout: 15000 } );
		const refusal = await page.evaluate( () => ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' );
		t.check( 'a non-recurring coupon is refused in WooCommerce\'s own words',
			/recurring/i.test( refusal ), refusal.trim().slice( 0, 120 ) );
		const untouched = await api( `wc/v3/subscriptions/${ subId }?_fields=coupon_lines,total` );
		t.check( 'the refused coupon changed nothing',
			( untouched.body.coupon_lines || [] ).length === 0, JSON.stringify( untouched.body ) );
		await page.keyboard.press( 'Escape' );

		// A real subscription product with a recurring coupon: the path that
		// works, asserted on what WooCommerce stored, not on the card.
		const subProd = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Recurring Widget ' + suffix, type: 'subscription', regular_price: '50.00', status: 'publish',
				meta_data: [
					{ key: '_subscription_price', value: '50.00' },
					{ key: '_subscription_period', value: 'month' },
					{ key: '_subscription_period_interval', value: '1' },
				],
			} ),
		} );
		recProd = subProd.body && subProd.body.id;
		const rec = await api( 'wc/v3/coupons', {
			method: 'POST',
			body: JSON.stringify( { code: 'subrec' + suffix, discount_type: 'recurring_percent', amount: '20' } ),
		} );
		recCoupon = rec.body && rec.body.id;
		const rsub = await api( 'wc/v3/subscriptions', {
			method: 'POST',
			body: JSON.stringify( { status: 'active', billing, billing_period: 'month', billing_interval: 1, line_items: [ { product_id: recProd, quantity: 1 } ] } ),
		} );
		recSub = rsub.body && rsub.body.id;
		await page.goto( `${ BASE }/minn-admin/subscriptions/${ recSub }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		await page.click( '[data-soedit="coupons"]' );
		await page.waitForSelector( '.minn-order-submodal #minn-ec-code', { timeout: 10000 } );
		await page.fill( '#minn-ec-code', rec.body.code );
		await page.click( '#minn-ec-add' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 20000 } );
		let rc = await api( `wc/v3/subscriptions/${ recSub }?_fields=coupon_lines,discount_total,total` );
		t.check( 'a recurring coupon applies and WooCommerce recalculates',
			( rc.body.coupon_lines || [] ).length === 1 && parseFloat( rc.body.total ) === 40 && parseFloat( rc.body.discount_total ) === 10,
			JSON.stringify( { lines: ( rc.body.coupon_lines || [] ).map( ( c ) => c.code ), total: rc.body.total, discount: rc.body.discount_total } ) );
		t.check( 'the card names the discount instead of just shrinking the total',
			await page.evaluate( ( c ) => {
				const items = document.querySelector( '.minn-sub-page .minn-order-items' );
				return !! items && items.textContent.indexOf( c ) !== -1 && /Discount/i.test( items.textContent );
			}, rec.body.code ), '' );
		await page.click( '[data-soedit="coupons"]' );
		await page.waitForSelector( `.minn-order-submodal [data-ecdel="${ rec.body.code }"]`, { timeout: 10000 } );
		await page.click( `.minn-order-submodal [data-ecdel="${ rec.body.code }"]` );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 20000 } );
		rc = await api( `wc/v3/subscriptions/${ recSub }?_fields=coupon_lines,total` );
		t.check( 'dropping it restores the recurring total',
			( rc.body.coupon_lines || [] ).length === 0 && parseFloat( rc.body.total ) === 50,
			JSON.stringify( { lines: ( rc.body.coupon_lines || [] ).length, total: rc.body.total } ) );
		await page.goto( `${ BASE }/minn-admin/subscriptions/${ subId }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();

		// ---- The timeline takes notes, private and customer-visible ----
		await page.waitForSelector( '.minn-sub-page .minn-sub-notes', { timeout: 20000 } );
		await page.fill( '#minn-s-new-note', 'Called about the card on file.' );
		await page.click( '#minn-s-note-add' );
		await page.waitForFunction( () => /Called about the card on file/.test(
			( document.querySelector( '.minn-sub-notes' ) || {} ).textContent || '' ), null, { timeout: 20000 } );
		let subNotes = await api( `wc/v3/subscriptions/${ subId }/notes?per_page=20` );
		const priv = ( subNotes.body || [] ).find( ( n ) => /Called about the card/.test( n.note || '' ) );
		// What the card shows is not the point; what WooCommerce stored is.
		t.check( 'a private note reaches the subscription and stays private',
			!! priv && priv.customer_note === false, JSON.stringify( { found: !! priv, customer: priv && priv.customer_note } ) );

		await page.fill( '#minn-s-new-note', 'Your next delivery ships Monday.' );
		await page.check( '#minn-s-note-customer' );
		await page.click( '#minn-s-note-add' );
		await page.waitForFunction( () => /ships Monday/.test(
			( document.querySelector( '.minn-sub-notes' ) || {} ).textContent || '' ), null, { timeout: 20000 } );
		subNotes = await api( `wc/v3/subscriptions/${ subId }/notes?per_page=20` );
		const cust = ( subNotes.body || [] ).find( ( n ) => /ships Monday/.test( n.note || '' ) );
		t.check( 'a customer note is saved as visible to the customer',
			!! cust && cust.customer_note === true, JSON.stringify( { found: !! cust, customer: cust && cust.customer_note } ) );
		t.check( 'the timeline marks which is which',
			await page.evaluate( () => {
				const list = document.querySelector( '.minn-sub-notes .minn-order-notes-list' );
				if ( ! list ) return false;
				const rows = [ ...list.querySelectorAll( '.minn-order-note' ) ];
				const p = rows.find( ( r ) => /Called about the card/.test( r.textContent ) );
				const c = rows.find( ( r ) => /ships Monday/.test( r.textContent ) );
				return !! p && !! c && ! p.classList.contains( 'customer' ) && c.classList.contains( 'customer' );
			} ), '' );
		// Both hosts can be on screen at once (an order quick view over this
		// page), so the composers must not share ids with the order's.
		t.check( 'the subscription composer does not borrow the order ids',
			await page.evaluate( () => ! document.getElementById( 'minn-o-note-add' ) ), '' );

		// ---- A related order can be glanced at without leaving the page ----
		await page.hover( `[data-relorder="${ orderId }"]` );
		await page.click( `[data-relqv="${ orderId }"]` );
		await page.waitForSelector( '.minn-modal .minn-order-main', { timeout: 15000 } );
		const glance = await page.evaluate( ( id ) => ( {
			isOrder: !! document.querySelector( '.minn-modal .minn-order-payment' ),
			isSub: !! document.querySelector( '.minn-modal .minn-sub-schedule' ),
			stillHere: location.pathname.indexOf( '/subscriptions/' + id ) !== -1,
		} ), subId );
		t.check( 'the quick view opens the order without leaving the subscription',
			glance.isOrder && ! glance.isSub && glance.stillHere, JSON.stringify( glance ) );
		await page.click( '#minn-modal-close' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal-overlay' ), null, { timeout: 10000 } );
		t.check( 'closing it leaves the subscription page as it was',
			await page.evaluate( ( id ) => location.pathname.indexOf( '/subscriptions/' + id ) !== -1 && !! document.querySelector( '.minn-sub-page .minn-order-main' ), subId ), '' );

		// ---- Related order navigation is a real URL ----
		await page.click( `[data-relorder="${ orderId }"]` );
		await page.waitForFunction( ( id ) => location.pathname.indexOf( '/orders/' + id ) !== -1, orderId, { timeout: 15000 } );
		t.check( 'the parent order opens on its own page', true, await page.evaluate( () => location.pathname ) );

		// ---- …and Back returns to the subscription, not to the orders list ----
		const backLabel = await page.evaluate( () => {
			const b = document.getElementById( 'minn-op-back' );
			return b ? b.textContent.trim() : '';
		} );
		t.check( 'the order page offers the subscription as its way back',
			backLabel.indexOf( 'Subscription' ) !== -1 && backLabel.indexOf( String( subId ) ) !== -1, backLabel );
		await page.click( '#minn-op-back' );
		await page.waitForFunction( ( id ) => location.pathname.indexOf( '/subscriptions/' + id ) !== -1, subId, { timeout: 15000 } );
		t.check( 'Back lands on the subscription it came from', true, await page.evaluate( () => location.pathname ) );
		await pageReady();
		// Reached on its own, the order page keeps its own list as Back: the
		// return is the trail this visit left, never a leftover from the last.
		await page.goto( `${ BASE }/minn-admin/orders/${ orderId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-op-back', { timeout: 25000 } );
		t.check( 'an order opened directly still goes back to Orders',
			( await page.evaluate( () => document.getElementById( 'minn-op-back' ).textContent.trim() ) ).indexOf( 'Subscription' ) === -1, '' );

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
		if ( recSub ) await api( `wc/v3/subscriptions/${ recSub }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( recProd ) await api( `wc/v3/products/${ recProd }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( flatCoupon ) await api( `wc/v3/coupons/${ flatCoupon }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( recCoupon ) await api( `wc/v3/coupons/${ recCoupon }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
