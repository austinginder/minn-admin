/**
 * Shopify-style layout of the /orders/{id} page: two-column grid (main +
 * sidebar) on desktop, header that carries the badges and the actions, a
 * payment card with a Subtotal/Total breakdown, the notes timeline with its
 * composer on top, and a read-first sidebar (customer as text, pencil toggles
 * the edit form). The SAME shared body must keep stacking one-column inside
 * the quick-view modal and under a narrow viewport.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-layout' );

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
	const email = `minn-layout-${ suffix }@example.com`;
	let pid = null, oid = null, eid = null, mediaId = null;

	try {
		const prod = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Minn Layout Test ' + suffix, type: 'simple', regular_price: '20.00', status: 'publish' } ),
		} );
		pid = prod.body && prod.body.id;
		// A real attachment on the product: the add-product picker's thumbnail
		// is only proven by a product that actually has one.
		mediaId = await page.evaluate( async ( s ) => {
			const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
			const bin = atob( png );
			const bytes = new Uint8Array( bin.length );
			for ( let i = 0; i < bin.length; i++ ) bytes[ i ] = bin.charCodeAt( i );
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'image/png', 'Content-Disposition': `attachment; filename="layout-${ s }.png"` },
				credentials: 'same-origin',
				body: bytes,
			} );
			const j = await r.json();
			return j && j.id;
		}, suffix );
		if ( mediaId ) await api( `wc/v3/products/${ pid }`, { method: 'PUT', body: JSON.stringify( { images: [ { id: mediaId } ] } ) } );
		const o = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( {
				status: 'processing',
				set_paid: true,
				billing: { first_name: 'Layla', last_name: 'Ordonez', email, phone: '+1 555 0100', address_1: '123 Test St', city: 'Paris', postcode: '75011', country: 'FR' },
				shipping: { first_name: 'Layla', last_name: 'Ordonez', address_1: '9 Warehouse Rd', city: 'Lyon', postcode: '69001', country: 'FR' },
				line_items: [ { product_id: pid, quantity: 2 } ],
				meta_data: [
					{ key: '_wc_order_attribution_source_type', value: 'utm' },
					{ key: '_wc_order_attribution_utm_source', value: 'newsletter' },
					{ key: '_wc_order_attribution_utm_medium', value: 'email' },
					{ key: '_wc_order_attribution_device_type', value: 'Desktop' },
					{ key: '_wc_order_attribution_session_pages', value: '4' },
					{ key: '_wc_order_attribution_session_count', value: '2' },
					{ key: '_wc_order_attribution_referrer', value: 'https://news.example.com/' },
				],
			} ),
		} );
		oid = o.body && o.body.id;
		t.check( 'fixtures created', !! ( pid && oid ), JSON.stringify( { pid, oid } ) );

		const pageReady = async () => {
			await page.waitForSelector( '.minn-order-page .minn-order-payment', { timeout: 25000 } );
			await page.waitForFunction( () => {
				const card = document.querySelector( '.minn-order-payment' );
				return card && ! card.querySelector( '.minn-loading' );
			}, null, { timeout: 20000 } );
		};

		await page.setViewportSize( { width: 1440, height: 950 } );
		await page.goto( `${ BASE }/minn-admin/orders/${ oid }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();

		// ---- Two-column grid on desktop: sidebar sits to the RIGHT of main ----
		const cols = await page.evaluate( () => {
			const main = document.querySelector( '.minn-order-page .minn-order-main' );
			const side = document.querySelector( '.minn-order-page .minn-order-side' );
			if ( ! main || ! side ) return { main: !! main, side: !! side };
			const mb = main.getBoundingClientRect(), sb = side.getBoundingClientRect();
			return { main: true, side: true, mainRight: mb.x + mb.width, sideX: sb.x, sameTop: Math.abs( mb.y - sb.y ) < 40 };
		} );
		t.check( 'desktop lays main and sidebar side by side',
			cols.main && cols.side && cols.sideX >= cols.mainRight - 1 && cols.sameTop, JSON.stringify( cols ) );

		// ---- Header carries badges and the actions ----
		const head = await page.evaluate( () => {
			const h = document.querySelector( '.minn-order-page-head' );
			return {
				statusBadge: !! ( h && h.querySelector( '.minn-status' ) ),
				// WC's own label for the slug, not the humanized slug itself.
				statusLabel: h && h.querySelector( '.minn-status' ) ? h.querySelector( '.minn-status' ).textContent.trim() : '',
				paidChip: !! ( h && /\bPaid\b/.test( h.textContent ) ),
				emailBtn: !! ( h && h.querySelector( '#minn-o-email' ) ),
				wcEdit: !! ( h && /Edit in WooCommerce/.test( h.textContent ) ),
			};
		} );
		t.check( 'header shows status badge and Paid chip', head.statusBadge && head.paidChip, JSON.stringify( head ) );
		t.check( 'status badge reads WooCommerce\'s label', head.statusLabel === 'Processing', head.statusLabel );
		t.check( 'header hosts the order actions', head.emailBtn && head.wcEdit, JSON.stringify( head ) );

		// ---- Main column: items card, payment breakdown, timeline ----
		// (notes land on their own fetch — wait for the list so the composer
		// position check never samples the loading placeholder)
		await page.waitForFunction( () => !! document.querySelector( '.minn-order-notes .minn-order-notes-list' ), null, { timeout: 15000 } );
		const main = await page.evaluate( () => {
			const m = document.querySelector( '.minn-order-main' );
			const totals = m && m.querySelector( '.minn-order-items' );
			const notes = m && m.querySelector( '.minn-order-notes' );
			const composer = notes && notes.querySelector( '#minn-o-new-note' );
			const list = notes && notes.querySelector( '.minn-order-notes-list' );
			return {
				items: !! totals,
				subtotalRow: !! totals && /Subtotal/.test( totals.textContent ) && /\b2 items\b/.test( totals.textContent ),
				totalRow: !! totals && /Total/.test( totals.textContent ),
				timeline: !! notes,
				composerAboveList: !! ( composer && list ) && !! ( composer.compareDocumentPosition( list ) & Node.DOCUMENT_POSITION_FOLLOWING ),
			};
		} );
		t.check( 'main column carries the items card', main.items, JSON.stringify( main ) );
		t.check( 'payment breakdown shows Subtotal with item count and Total', main.subtotalRow && main.totalRow, JSON.stringify( main ) );
		t.check( 'timeline lives in main with the composer on top', main.timeline && main.composerAboveList, JSON.stringify( main ) );

		// ---- Sidebar: customer reads as text, pencil reveals the form ----
		const side = await page.evaluate( () => {
			const s = document.querySelector( '.minn-order-side' );
			return {
				emailAsText: !! s && /minn-layout-/.test( s.textContent ),
				formAbsent: ! document.getElementById( 'minn-ob-first' ),
				pencil: !! ( s && s.querySelector( '[data-oedit="customer"]' ) ),
				shippingShown: !! s && /Lyon/.test( s.textContent ),
			};
		} );
		t.check( 'sidebar shows customer as text with no inline form',
			side.emailAsText && side.formAbsent && side.pencil && side.shippingShown, JSON.stringify( side ) );

		// The pencil opens an edit dialog; Save rides the one save flow and
		// closes the dialog on success.
		await page.click( '[data-oedit="customer"]' );
		await page.waitForSelector( '.minn-order-submodal #minn-ob-first', { timeout: 8000 } );
		t.check( 'pencil opens the customer edit dialog', true, '' );
		await page.fill( '#minn-ob-first', 'Layla-Edited' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 15000 } );
		t.check( 'dialog Save closes it', true, '' );
		await page.waitForFunction( () => {
			const s = document.querySelector( '.minn-order-side' );
			return s && s.textContent.includes( 'Layla-Edited' );
		}, null, { timeout: 10000 } );
		t.check( 'sidebar read card reflects the save', true, '' );
		const saved = await api( `wc/v3/orders/${ oid }?_fields=billing` );
		t.check( 'edited customer persists through WC', saved.body && saved.body.billing && saved.body.billing.first_name === 'Layla-Edited', JSON.stringify( saved.body ) );

		// ---- Shipping card: copy-from-billing beside the pencil ----
		// (this order ships to Lyon, so a copy must actually change the card)
		t.check( 'shipping card offers copy from billing',
			await page.evaluate( () => !! document.querySelector( '.minn-order-shipto [data-ocopyaddr]' ) ), '' );
		await page.click( '.minn-order-shipto [data-ocopyaddr]' );
		await page.waitForFunction( () => {
			const c = document.querySelector( '.minn-order-shipto' );
			return c && /Same as billing address/.test( c.textContent );
		}, null, { timeout: 15000 } );
		const shipCopied = await api( `wc/v3/orders/${ oid }?_fields=shipping` );
		t.check( 'copy writes the billing address into shipping',
			shipCopied.body.shipping.city === 'Paris' && shipCopied.body.shipping.postcode === '75011' && shipCopied.body.shipping.address_1 === '123 Test St',
			JSON.stringify( shipCopied.body.shipping ) );

		// ---- Attribution card renders WC's order-attribution meta ----
		const attrib = await page.evaluate( () => {
			const c = document.querySelector( '.minn-order-side .minn-order-attrib' );
			return {
				card: !! c,
				origin: !! c && /newsletter/.test( c.textContent ),
				device: !! c && /Desktop/.test( c.textContent ),
			};
		} );
		t.check( 'sidebar shows the attribution card from WC meta', attrib.card && attrib.origin && attrib.device, JSON.stringify( attrib ) );

		// ---- Refund: a header action opening a dialog, not an inline card ----
		const rbtn = await page.evaluate( () => ( {
			inHead: !! document.querySelector( '.minn-order-page-head #minn-o-refund-open' ),
			cardInline: !! document.querySelector( '.minn-order-main .minn-order-refund' ),
		} ) );
		t.check( 'Refund is a header action, not an inline card', rbtn.inHead && ! rbtn.cardInline, JSON.stringify( rbtn ) );
		await page.click( '#minn-o-refund-open' );
		await page.waitForSelector( '.minn-order-submodal #minn-o-refund', { timeout: 8000 } );
		t.check( 'Refund dialog opens with the refund controls', true, '' );
		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 5000 } );
		t.check( 'Escape peels the refund dialog only', await page.evaluate( () => !! document.querySelector( '.minn-order-page' ) ), '' );

		// ---- Items editing: hidden on paid orders, a dialog on editable ones ----
		t.check( 'paid order hides the items pencil',
			await page.evaluate( () => ! document.querySelector( '[data-oedit="items"]' ) ), '' );

		const e = await api( 'wc/v3/orders', {
			method: 'POST',
			body: JSON.stringify( { status: 'pending', billing: { first_name: 'Edit', last_name: 'Able', email, country: 'FR' }, line_items: [ { product_id: pid, quantity: 1 } ] } ),
		} );
		eid = e.body && e.body.id;
		await page.goto( `${ BASE }/minn-admin/orders/${ eid }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();
		// Woo parity: wp-admin offers Refund on any order with refundable
		// items, paid or not (manual refund records) — Minn matches that.
		t.check( 'unpaid order still offers the Refund action',
			await page.evaluate( () => !! document.querySelector( '#minn-o-refund-open' ) ), '' );
		await page.click( '[data-oedit="items"]' );
		await page.waitForSelector( '.minn-order-submodal [data-eiqty]', { timeout: 8000 } );
		await page.fill( '[data-eiqty]', '3' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 15000 } );
		let ei = await api( `wc/v3/orders/${ eid }?_fields=line_items,total` );
		t.check( 'quantity edit persists and totals rescale',
			ei.body.line_items.length === 1 && ei.body.line_items[ 0 ].quantity === 3 && parseFloat( ei.body.total ) === 60,
			JSON.stringify( { qty: ei.body.line_items[ 0 ] && ei.body.line_items[ 0 ].quantity, total: ei.body.total } ) );

		// Add a product through the dialog's search.
		await page.click( '[data-oedit="items"]' );
		await page.waitForSelector( '.minn-order-submodal #minn-ei-search', { timeout: 8000 } );
		await page.fill( '#minn-ei-search', 'Minn Layout Test' );
		// The search is a round trip: say so while it runs.
		await page.waitForSelector( '.minn-order-submodal .minn-ei-searching', { timeout: 8000 } );
		t.check( 'product search shows a loading state', true, '' );
		await page.waitForSelector( '.minn-order-submodal [data-ei-pick]', { timeout: 10000 } );
		t.check( 'loading state clears once hits land',
			await page.evaluate( () => ! document.querySelector( '.minn-ei-searching' ) ), '' );
		const pickShape = await page.evaluate( () => {
			const item = document.querySelector( '.minn-order-submodal [data-ei-pick]' );
			const thumb = item && item.querySelector( '.minn-of-thumb' );
			const img = thumb && thumb.querySelector( 'img' );
			const box = thumb ? thumb.getBoundingClientRect() : { width: 0, height: 0 };
			return { thumbSlot: !! thumb, src: img ? img.getAttribute( 'src' ) : '', w: Math.round( box.width ), h: Math.round( box.height ) };
		} );
		t.check( 'the add-product picker carries the product image',
			pickShape.thumbSlot && /\.(png|jpe?g|gif|webp)/i.test( pickShape.src ), JSON.stringify( pickShape ) );
		// Same trap the filter picker hit: the shared button.minn-ac-item rule
		// sets display:block, and an inline style beats the flex rule outright,
		// which leaves the thumb inline where width and height are ignored.
		t.check( 'the thumbnail is actually laid out, not inline',
			pickShape.w >= 20 && pickShape.h >= 20, JSON.stringify( pickShape ) );
		await page.click( '.minn-order-submodal [data-ei-pick]' );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 15000 } );
		ei = await api( `wc/v3/orders/${ eid }?_fields=line_items,total` );
		t.check( 'added product lands as a second line',
			ei.body.line_items.length === 2 && parseFloat( ei.body.total ) === 80,
			JSON.stringify( { lines: ei.body.line_items.length, total: ei.body.total } ) );

		// Remove the added line.
		const addedId = ei.body.line_items[ 1 ].id;
		await page.click( '[data-oedit="items"]' );
		await page.waitForSelector( `.minn-order-submodal [data-eidel="${ addedId }"]`, { timeout: 8000 } );
		await page.click( `.minn-order-submodal [data-eidel="${ addedId }"]` );
		await page.click( '.minn-order-submodal [data-esave]' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-order-submodal' ), null, { timeout: 15000 } );
		ei = await api( `wc/v3/orders/${ eid }?_fields=line_items,total` );
		t.check( 'removed line leaves the order and totals restore',
			ei.body.line_items.length === 1 && parseFloat( ei.body.total ) === 60,
			JSON.stringify( { lines: ei.body.line_items.length, total: ei.body.total } ) );
		await page.goto( `${ BASE }/minn-admin/orders/${ oid }`, { waitUntil: 'domcontentloaded' } );
		await pageReady();

		// ---- Narrow viewport stacks the sidebar below main ----
		await page.setViewportSize( { width: 700, height: 950 } );
		await page.waitForTimeout( 250 );
		const narrow = await page.evaluate( () => {
			const mb = document.querySelector( '.minn-order-main' ).getBoundingClientRect();
			const sb = document.querySelector( '.minn-order-side' ).getBoundingClientRect();
			return { stacked: sb.y >= mb.y + mb.height - 1, sameX: Math.abs( sb.x - mb.x ) < 40 };
		} );
		t.check( 'narrow viewport stacks sidebar below main', narrow.stacked && narrow.sameX, JSON.stringify( narrow ) );
		await page.setViewportSize( { width: 1440, height: 950 } );

		// ---- Quick-view modal keeps the one-column stack ----
		await page.goto( `${ BASE }/minn-admin/orders`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-order="${ oid }"]`, { timeout: 20000 } );
		await page.click( `.minn-table-row[data-order="${ oid }"] .minn-row-quick` );
		await page.waitForSelector( '.minn-modal .minn-order-main', { timeout: 15000 } );
		const modal = await page.evaluate( () => {
			const mb = document.querySelector( '.minn-modal .minn-order-main' ).getBoundingClientRect();
			const sb = document.querySelector( '.minn-modal .minn-order-side' ).getBoundingClientRect();
			return { stacked: sb.y >= mb.y + mb.height - 1, sameX: Math.abs( sb.x - mb.x ) < 40 };
		} );
		t.check( 'quick-view modal stays one column', modal.stacked && modal.sameX, JSON.stringify( modal ) );
	} finally {
		if ( eid ) await api( `wc/v3/orders/${ eid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( oid ) await api( `wc/v3/orders/${ oid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( mediaId ) await api( `wp/v2/media/${ mediaId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
