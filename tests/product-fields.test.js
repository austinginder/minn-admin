/**
 * Wave 2 of the product page: inventory (GTIN, backorders, low stock, sold
 * individually) and shipping (weight, dimensions, shipping class).
 *
 * Fixtures: one product, one virtual product (proving the shipping card hides
 * itself) and one shipping class, all created and removed over REST.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-fields' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const hasWc = await page.evaluate( () => !!( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
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

	const suffix = Date.now();
	let id = null;
	let virtualId = null;
	let classId = null;
	try {
		const cls = await api( 'wc/v3/products/shipping_classes', {
			method: 'POST',
			body: JSON.stringify( { name: 'Minn Bulky ' + suffix } ),
		} );
		classId = cls.body && cls.body.id;
		const classSlug = ( cls.body && cls.body.slug ) || '';
		t.check( 'fixture shipping class created', !! classId, String( cls.status ) );

		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Product fields fixture ' + suffix,
				type: 'simple',
				regular_price: '10.00',
				manage_stock: true,
				stock_quantity: 5,
				status: 'publish',
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created', !! id, String( made.status ) );
		if ( ! id ) {
			await t.done( browser, errors );
			return;
		}

		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-gtin', { timeout: 20000 } );

		// The groups WooCommerce splits across metabox tabs, on one page.
		const cards = await page.evaluate( () => Array.from(
			document.querySelectorAll( '.minn-order-panel .minn-side-title' )
		).map( ( el ) => el.textContent.trim() ) );
		t.check( 'page shows Basics, Pricing, Inventory and Shipping',
			[ 'Basics', 'Pricing', 'Inventory', 'Shipping' ].every( ( c ) => cards.includes( c ) ),
			JSON.stringify( cards ) );

		const present = await page.evaluate( () => ( {
			gtin: !! document.querySelector( '#minn-p-gtin' ),
			backorders: !! document.querySelector( '#minn-p-backorders' ),
			low: !! document.querySelector( '#minn-p-lowstock' ),
			solo: !! document.querySelector( '#minn-p-solo' ),
			weight: !! document.querySelector( '#minn-p-weight' ),
			length: !! document.querySelector( '#minn-p-length' ),
			shipclass: !! document.querySelector( '#minn-p-shipclass' ),
		} ) );
		t.check( 'every wave-2 field is on the page',
			Object.values( present ).every( Boolean ), JSON.stringify( present ) );

		// The shipping-class select is fed by the store's real vocabulary.
		const opts = await page.evaluate( () => Array.from(
			document.querySelectorAll( '#minn-p-shipclass option' )
		).map( ( o ) => o.value ) );
		t.check( 'shipping class select offers no-class plus the store\'s classes',
			opts.includes( '' ) && opts.includes( classSlug ), JSON.stringify( opts ) );

		// Fill everything and save once.
		await page.fill( '#minn-p-gtin', '01234567890128' );
		await page.selectOption( '#minn-p-backorders', 'notify' );
		await page.fill( '#minn-p-lowstock', '3' );
		await page.check( '#minn-p-solo' );
		await page.fill( '#minn-p-weight', '1.25' );
		await page.fill( '#minn-p-length', '10' );
		await page.fill( '#minn-p-width', '8' );
		await page.fill( '#minn-p-height', '2' );
		await page.selectOption( '#minn-p-shipclass', classSlug );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 500 );

		const saved = await api( `wc/v3/products/${ id }?_fields=id,global_unique_id,backorders,low_stock_amount,sold_individually,weight,dimensions,shipping_class` );
		const b = saved.body || {};
		t.check( 'GTIN saved', b.global_unique_id === '01234567890128', JSON.stringify( b.global_unique_id ) );
		t.check( 'backorders saved', b.backorders === 'notify', String( b.backorders ) );
		t.check( 'low stock threshold saved', Number( b.low_stock_amount ) === 3, JSON.stringify( b.low_stock_amount ) );
		t.check( 'sold individually saved', b.sold_individually === true, String( b.sold_individually ) );
		t.check( 'weight saved', String( b.weight ) === '1.25', String( b.weight ) );
		t.check( 'dimensions saved', b.dimensions && b.dimensions.length === '10'
			&& b.dimensions.width === '8' && b.dimensions.height === '2', JSON.stringify( b.dimensions ) );
		t.check( 'shipping class saved', b.shipping_class === classSlug, String( b.shipping_class ) );

		// The values come back on a reload rather than only living in the DOM.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-gtin', { timeout: 20000 } );
		await page.waitForFunction( () => {
			const s = document.querySelector( '#minn-p-shipclass' );
			return s && s.options.length > 1;
		}, null, { timeout: 15000 } ).catch( () => null );
		const back = await page.evaluate( () => ( {
			gtin: document.querySelector( '#minn-p-gtin' ).value,
			backorders: document.querySelector( '#minn-p-backorders' ).value,
			low: document.querySelector( '#minn-p-lowstock' ).value,
			solo: document.querySelector( '#minn-p-solo' ).checked,
			weight: document.querySelector( '#minn-p-weight' ).value,
			length: document.querySelector( '#minn-p-length' ).value,
			ship: document.querySelector( '#minn-p-shipclass' ).value,
		} ) );
		t.check( 'saved values repopulate the form',
			back.gtin === '01234567890128' && back.backorders === 'notify' && back.low === '3'
			&& back.solo === true && back.weight === '1.25' && back.length === '10'
			&& back.ship === classSlug, JSON.stringify( back ) );

		// Empty low stock means "store default" (null), never 0.
		await page.fill( '#minn-p-lowstock', '' );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const btn = document.querySelector( '#minn-product-save' );
			return btn && ! btn.disabled && /Save/.test( btn.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 500 );
		const cleared = await api( `wc/v3/products/${ id }?_fields=id,low_stock_amount` );
		t.check( 'clearing low stock stores null, not zero',
			cleared.body && ( cleared.body.low_stock_amount === null || cleared.body.low_stock_amount === '' ),
			JSON.stringify( cleared.body && cleared.body.low_stock_amount ) );

		// Untracked stock hides quantity, low stock and backorders together.
		await page.uncheck( '#minn-p-manage' );
		const hidden = await page.evaluate( () => {
			const row = document.querySelector( '#minn-p-stock-row' );
			return row ? getComputedStyle( row ).display : 'missing';
		} );
		t.check( 'untracking stock hides the quantity group', hidden === 'none', hidden );

		// A virtual product never ships, so it gets no shipping card.
		const v = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Virtual fields fixture ' + suffix,
				type: 'simple',
				virtual: true,
				regular_price: '5.00',
				status: 'publish',
			} ),
		} );
		virtualId = v.body && v.body.id;
		t.check( 'fixture virtual product created', !! virtualId, String( v.status ) );
		if ( virtualId ) {
			await page.goto( BASE + '/minn-admin/products/' + virtualId, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-p-gtin', { timeout: 20000 } );
			const vCards = await page.evaluate( () => ( {
				titles: Array.from( document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( el ) => el.textContent.trim() ),
				weight: !! document.querySelector( '#minn-p-weight' ),
				gtin: !! document.querySelector( '#minn-p-gtin' ),
			} ) );
			t.check( 'virtual product hides the Shipping card',
				! vCards.titles.includes( 'Shipping' ) && ! vCards.weight, JSON.stringify( vCards ) );
			t.check( 'virtual product keeps Inventory', vCards.gtin, JSON.stringify( vCards ) );
		}

		// The shipping-class vocabulary must be in hand BEFORE the form paints.
		// When it was merged in on arrival, the repaint rebuilt the form and
		// threw away whatever had been typed. Delaying the request makes the
		// old race deterministic: if the form ever repaints, the probe dies
		// with the node it was stamped on.
		await page.route( '**/products/shipping_classes*', async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, 2500 ) );
			await route.continue();
		} );
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-name', { timeout: 30000 } );
		await page.evaluate( () => { document.querySelector( '#minn-p-name' )._minnProbe = 'wave2'; } );
		await page.fill( '#minn-p-name', 'Typed during the slow load' );
		await page.waitForTimeout( 3500 );
		const survived = await page.evaluate( () => {
			const el = document.querySelector( '#minn-p-name' );
			return { probe: el && el._minnProbe, value: el && el.value,
				opts: document.querySelectorAll( '#minn-p-shipclass option' ).length };
		} );
		t.check( 'a slow shipping-class load never rebuilds the form',
			survived.probe === 'wave2' && survived.value === 'Typed during the slow load',
			JSON.stringify( survived ) );
		t.check( 'the shipping select is populated on first paint',
			survived.opts > 1, String( survived.opts ) );
		await page.unroute( '**/products/shipping_classes*' );

		// The quick view shares the body, so it carries the same fields.
		await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-product="${ id }"] [data-pqv]`, { timeout: 20000 } ).catch( () => null );
		const eye = await page.$( `.minn-table-row[data-product="${ id }"] [data-pqv]` );
		if ( eye ) {
			const box = await eye.boundingBox();
			await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
			await page.waitForSelector( '.minn-modal-overlay #minn-p-gtin', { timeout: 15000 } ).catch( () => null );
			t.check( 'quick view carries the same wave-2 fields',
				!! ( await page.$( '.minn-modal-overlay #minn-p-weight' ) ), '' );
		}
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( virtualId ) await api( `wc/v3/products/${ virtualId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( classId ) await api( `wc/v3/products/shipping_classes/${ classId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
