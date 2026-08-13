/**
 * Wave 8 of the product page: the Attributes card. Custom attributes are
 * typed; store-wide (pa_*) ones are picked from what already exists, because
 * a global attribute cannot be created and used in the same request.
 *
 * Fixtures: one product (removed after) and the standing "Size" store
 * attribute on the dev site.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-attributes' );

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
		const defs = await api( 'wc/v3/products/attributes?_fields=id,name' );
		const globalAttr = ( defs.body || [] )[ 0 ] || null;
		t.check( 'the store has at least one global attribute', !! globalAttr, JSON.stringify( defs.body ) );

		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Attr fixture ' + suffix,
				type: 'simple', regular_price: '6.00', status: 'publish',
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created', !! id, String( made.status ) );
		if ( ! id ) {
			await t.done( browser, errors );
			return;
		}

		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-attrs', { timeout: 20000 } );

		const card = await page.evaluate( () => ( {
			titles: Array.from( document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			empty: /No attributes yet/.test( ( document.querySelector( '#minn-p-attrs' ) || {} ).textContent || '' ),
			add: !! document.querySelector( '#minn-p-attr-add' ),
			pick: !! document.querySelector( '[data-pattrglobal]' ),
		} ) );
		t.check( 'Attributes card is on the page', card.titles.includes( 'Attributes' ), JSON.stringify( card.titles ) );
		t.check( 'an attribute-less product says so', card.empty, '' );
		t.check( 'card offers Add attribute and the store-wide picker',
			card.add && card.pick, JSON.stringify( card ) );

		// A custom attribute: type a name and comma-separated values.
		await page.click( '#minn-p-attr-add' );
		await page.waitForSelector( '[data-pattrname="0"]', { timeout: 10000 } );
		await page.fill( '[data-pattrname="0"]', 'Material' );
		await page.fill( '[data-pattrvals="0"]', 'Cotton, Wool, Linen' );

		// Add the store-wide attribute by picking it.
		if ( globalAttr ) {
			await page.click( '[data-pattrglobal] .minn-ac-input' );
			const item = `[data-pattrglobal] .minn-ac-item[data-acv="${ globalAttr.id }"]`;
			await page.waitForSelector( item, { timeout: 10000 } );
			await page.click( item );
			await page.waitForSelector( '[data-pattrvals="1"]', { timeout: 10000 } );
			await page.fill( '[data-pattrvals="1"]', 'Small, Large' );
			const globalRow = await page.evaluate( () => ( {
				readonlyName: !! document.querySelector( '.minn-pattr-row:nth-child(2) .minn-pattr-global' ),
				label: ( ( document.querySelector( '.minn-pattr-row:nth-child(2) .minn-pattr-global' ) || {} ).textContent || '' ).trim(),
			} ) );
			t.check( 'a store-wide attribute shows its name as a label, not a field',
				globalRow.readonlyName, JSON.stringify( globalRow ) );
		}

		// Turn on "used for variations" for the global one.
		await page.click( '[data-pattrvar="1"]' );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 800 );

		const saved = await api( `wc/v3/products/${ id }?_fields=id,attributes` );
		const attrs = ( saved.body || {} ).attributes || [];
		const custom = attrs.find( ( a ) => a.name === 'Material' );
		t.check( 'the custom attribute saved with its values',
			!! custom && custom.options.join( ',' ) === 'Cotton,Wool,Linen', JSON.stringify( custom ) );
		t.check( 'the custom attribute has no global id', !! custom && ! custom.id, JSON.stringify( custom && custom.id ) );
		if ( globalAttr ) {
			const glob = attrs.find( ( a ) => a.id === globalAttr.id );
			t.check( 'the store-wide attribute saved against its taxonomy',
				!! glob && glob.options.length === 2, JSON.stringify( glob ) );
			t.check( 'used-for-variations saved', !! glob && glob.variation === true,
				JSON.stringify( glob && glob.variation ) );
		}

		// Reload: both come back, and the global one is still a label.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-attrs [data-pattrvals]', { timeout: 20000 } );
		const back = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '.minn-pattr-row' ).length,
			names: Array.from( document.querySelectorAll( '[data-pattrname]' ) ).map( ( i ) => i.value ),
			globals: Array.from( document.querySelectorAll( '.minn-pattr-global' ) ).map( ( e ) => e.textContent.trim() ),
			values: Array.from( document.querySelectorAll( '[data-pattrvals]' ) ).map( ( i ) => i.value ),
		} ) );
		t.check( 'attributes repopulate after reload',
			back.rows === 2 && back.names.includes( 'Material' )
			&& back.values.some( ( v ) => /Cotton/.test( v ) ), JSON.stringify( back ) );

		// Removing a row and saving really drops the attribute.
		await page.click( '[data-pattrx="0"]' );
		await page.waitForTimeout( 250 );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 800 );
		const after = await api( `wc/v3/products/${ id }?_fields=id,attributes` );
		const left = ( after.body || {} ).attributes || [];
		t.check( 'removing an attribute row drops it on save',
			left.length === 1 && ! left.some( ( a ) => a.name === 'Material' ),
			JSON.stringify( left.map( ( a ) => a.name ) ) );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
