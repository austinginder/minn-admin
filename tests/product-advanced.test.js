/**
 * Wave 5 of the product page: the sale schedule and tax fields in Pricing,
 * plus the Advanced card (purchase note, menu order, reviews).
 *
 * Fixture: one product, created and removed over REST.
 */
const { BASE, launch, login, reporter, pickCombo, setSwitch } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-advanced' );

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
	try {
		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Product advanced fixture ' + suffix,
				type: 'simple', regular_price: '20.00', sale_price: '15.00', status: 'publish',
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created', !! id, String( made.status ) );
		if ( ! id ) {
			await t.done( browser, errors );
			return;
		}

		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-note', { timeout: 20000 } );

		const present = await page.evaluate( () => ( {
			titles: Array.from( document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			from: !! document.querySelector( '#minn-p-salefrom' ),
			to: !! document.querySelector( '#minn-p-saleto' ),
			taxStatus: !! document.querySelector( '#minn-p-taxstatus' ),
			taxClass: !! document.querySelector( '#minn-p-taxclass' ),
			note: !! document.querySelector( '#minn-p-note' ),
			order: !! document.querySelector( '#minn-p-menuorder' ),
			reviews: !! document.querySelector( '#minn-p-reviews' ),
			readonlyDates: ( document.querySelector( '#minn-p-salefrom' ) || {} ).readOnly,
		} ) );
		t.check( 'Advanced card is on the page', present.titles.includes( 'Advanced' ), JSON.stringify( present.titles ) );
		t.check( 'Pricing carries the sale schedule and tax fields',
			present.from && present.to && present.taxStatus && present.taxClass, JSON.stringify( present ) );
		t.check( 'Advanced carries note, menu order and reviews',
			present.note && present.order && present.reviews, JSON.stringify( present ) );
		t.check( 'sale dates use Minn\'s picker, not a native date input',
			present.readonlyDates === true, String( present.readonlyDates ) );

		// The tax-class select is the store's real vocabulary, and standard
		// rate is the empty string WooCommerce actually stores.
		await page.click( '#minn-p-taxclass' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item', { timeout: 10000 } );
		const taxOpts = await page.evaluate( () => Array.from(
			document.querySelectorAll( '.minn-ac-panel:not([hidden]) .minn-ac-item' ) ).map( ( o ) => [ o.dataset.acv, o.textContent.trim() ] ) );
		await page.keyboard.press( 'Escape' );
		t.check( 'tax class offers standard as the empty value',
			taxOpts.some( ( [ v, l ] ) => v === '' && /standard/i.test( l ) ), JSON.stringify( taxOpts ) );

		// Open the date picker and take a day from the grid.
		await page.click( '#minn-p-salefrom' );
		await page.waitForSelector( '.minn-dp-pop', { timeout: 10000 } ).catch( () => null );
		const pickedDay = await page.evaluate( () => {
			const pop = document.querySelector( '.minn-dp-pop' );
			if ( ! pop ) return null;
			const day = pop.querySelector( '.minn-dp-day:not(.out)' );
			if ( ! day ) return null;
			day.click();
			return day.textContent.trim();
		} );
		t.check( 'the date picker opens and offers days', !! pickedDay, String( pickedDay ) );
		if ( pickedDay ) {
			const done = await page.evaluate( () => {
				const btn = document.querySelector( '.minn-dp-pop [data-dp-done]' );
				if ( btn ) { btn.click(); return true; }
				return false;
			} );
			await page.waitForTimeout( 300 );
			const machine = await page.evaluate( () => ( {
				dp: document.querySelector( '#minn-p-salefrom' ).dataset.dp,
				shown: document.querySelector( '#minn-p-salefrom' ).value,
			} ) );
			t.check( 'picking a day fills the field',
				/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test( machine.dp || '' ) && !! machine.shown,
				JSON.stringify( { done, ...machine } ) );
		}

		await pickCombo( page, '#minn-p-taxstatus', 'shipping' );
		await page.fill( '#minn-p-note', 'Thanks for buying. Care instructions are inside.' );
		await page.fill( '#minn-p-menuorder', '7' );
		await setSwitch( page, '#minn-p-reviews', false );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 600 );

		const saved = await api( `wc/v3/products/${ id }?_fields=id,tax_status,tax_class,purchase_note,menu_order,reviews_allowed,date_on_sale_from` );
		const b = saved.body || {};
		t.check( 'tax status saved', b.tax_status === 'shipping', String( b.tax_status ) );
		t.check( 'purchase note saved', /Care instructions/.test( b.purchase_note || '' ), String( b.purchase_note ) );
		t.check( 'menu order saved', Number( b.menu_order ) === 7, String( b.menu_order ) );
		t.check( 'reviews turned off saved', b.reviews_allowed === false, String( b.reviews_allowed ) );
		t.check( 'sale start date saved', /^\d{4}-\d{2}-\d{2}T/.test( String( b.date_on_sale_from || '' ) ), String( b.date_on_sale_from ) );

		// Reload, then clear the sale start: empty must store null, not today.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-salefrom', { timeout: 20000 } );
		const repop = await page.evaluate( () => ( {
			from: document.querySelector( '#minn-p-salefrom' ).value,
			note: document.querySelector( '#minn-p-note' ).value,
			order: document.querySelector( '#minn-p-menuorder' ).value,
			reviews: document.querySelector( '#minn-p-reviews' ).classList.contains( 'on' ),
			tax: document.querySelector( '#minn-p-taxstatus' ).dataset.acValue,
		} ) );
		t.check( 'advanced values repopulate after reload',
			!! repop.from && /Care instructions/.test( repop.note ) && repop.order === '7'
			&& repop.reviews === false && repop.tax === 'shipping', JSON.stringify( repop ) );

		await page.evaluate( () => {
			const el = document.querySelector( '#minn-p-salefrom' );
			el.dataset.dp = '';
			el.value = '';
		} );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const btn = document.querySelector( '#minn-product-save' );
			return btn && ! btn.disabled && /Save/.test( btn.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 600 );
		const cleared = await api( `wc/v3/products/${ id }?_fields=id,date_on_sale_from` );
		t.check( 'clearing the sale start stores null',
			( cleared.body || {} ).date_on_sale_from === null || ( cleared.body || {} ).date_on_sale_from === '',
			JSON.stringify( ( cleared.body || {} ).date_on_sale_from ) );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
