/**
 * Wave 7 of the product page: the Linked products card (upsells, cross-sells).
 *
 * Fixtures: three products, all removed over REST.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-linked' );

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
	const ids = [];
	try {
		for ( const label of [ 'main', 'upsell', 'cross' ] ) {
			const r = await api( 'wc/v3/products', {
				method: 'POST',
				body: JSON.stringify( {
					name: `Linkfix ${ label } ${ suffix }`,
					type: 'simple', regular_price: '5.00', status: 'publish',
				} ),
			} );
			if ( r.body && r.body.id ) ids.push( r.body.id );
		}
		t.check( 'three fixture products created', ids.length === 3, JSON.stringify( ids ) );
		if ( ids.length < 3 ) {
			await t.done( browser, errors );
			return;
		}
		const [ mainId, upsellId, crossId ] = ids;

		await page.goto( BASE + '/minn-admin/products/' + mainId, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-plac="upsell_ids"]', { timeout: 20000 } );

		const card = await page.evaluate( () => ( {
			titles: Array.from( document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			up: !! document.querySelector( '[data-plac="upsell_ids"]' ),
			cross: !! document.querySelector( '[data-plac="cross_sell_ids"]' ),
		} ) );
		t.check( 'Linked products card is on the page', card.titles.includes( 'Linked products' ), JSON.stringify( card.titles ) );
		t.check( 'card carries upsells and cross-sells', card.up && card.cross, JSON.stringify( card ) );

		// Search finds other products and excludes this one.
		await page.fill( '[data-plac="upsell_ids"] .minn-ac-input', 'Linkfix ' );
		await page.waitForSelector( '[data-plac="upsell_ids"] [data-plpick]', { timeout: 15000 } );
		const offered = await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-plac="upsell_ids"] [data-plpick]' ) )
			.map( ( b ) => parseInt( b.dataset.plpick, 10 ) ) );
		t.check( 'the suggest offers other products', offered.length >= 2, JSON.stringify( offered ) );
		t.check( 'the product never offers itself', ! offered.includes( mainId ), JSON.stringify( offered ) );

		// Pick the upsell.
		await page.evaluate( ( pid ) => {
			const b = document.querySelector( `[data-plac="upsell_ids"] [data-plpick="${ pid }"]` );
			b.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
		}, upsellId );
		await page.waitForTimeout( 250 );
		const upChips = await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-plchips="upsell_ids"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ) );
		t.check( 'picking an upsell adds a chip', upChips.some( ( c ) => /Linkfix upsell/.test( c ) ), JSON.stringify( upChips ) );

		// And a cross-sell.
		await page.fill( '[data-plac="cross_sell_ids"] .minn-ac-input', 'Linkfix cross' );
		await page.waitForSelector( '[data-plac="cross_sell_ids"] [data-plpick]', { timeout: 15000 } );
		await page.evaluate( ( pid ) => {
			const b = document.querySelector( `[data-plac="cross_sell_ids"] [data-plpick="${ pid }"]` );
			b.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
		}, crossId );
		await page.waitForTimeout( 250 );

		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 700 );

		const saved = await api( `wc/v3/products/${ mainId }?_fields=id,upsell_ids,cross_sell_ids` );
		const b = saved.body || {};
		t.check( 'upsell saved', ( b.upsell_ids || [] ).includes( upsellId ), JSON.stringify( b.upsell_ids ) );
		t.check( 'cross-sell saved', ( b.cross_sell_ids || [] ).includes( crossId ), JSON.stringify( b.cross_sell_ids ) );

		// Reload: stored ids come back as named chips, not bare numbers.
		await page.goto( BASE + '/minn-admin/products/' + mainId, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-plchips="upsell_ids"] [data-ptchip]', { timeout: 20000 } );
		const back = await page.evaluate( () => ( {
			up: Array.from( document.querySelectorAll( '[data-plchips="upsell_ids"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ),
			cross: Array.from( document.querySelectorAll( '[data-plchips="cross_sell_ids"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ),
		} ) );
		t.check( 'linked products come back named, not as bare ids',
			back.up.some( ( c ) => /Linkfix upsell/.test( c ) ) && ! back.up.some( ( c ) => /^#\d+/.test( c ) ),
			JSON.stringify( back ) );
		t.check( 'the cross-sell comes back too',
			back.cross.some( ( c ) => /Linkfix cross/.test( c ) ), JSON.stringify( back.cross ) );

		// Removing a chip and saving really unlinks.
		await page.evaluate( () => document.querySelector( '[data-plchips="upsell_ids"] [data-ptchip]' ).click() );
		await page.waitForTimeout( 250 );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const btn = document.querySelector( '#minn-product-save' );
			return btn && ! btn.disabled && /Save/.test( btn.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 700 );
		const after = await api( `wc/v3/products/${ mainId }?_fields=id,upsell_ids,cross_sell_ids` );
		t.check( 'removing an upsell chip unlinks it',
			( ( after.body || {} ).upsell_ids || [] ).length === 0, JSON.stringify( ( after.body || {} ).upsell_ids ) );
		t.check( 'removing the upsell leaves the cross-sell alone',
			( ( after.body || {} ).cross_sell_ids || [] ).includes( crossId ),
			JSON.stringify( ( after.body || {} ).cross_sell_ids ) );
	} finally {
		for ( const pid of ids ) {
			await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => null );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
