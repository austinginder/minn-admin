/**
 * Prices print in the store's currency, never a guessed dollar. A customer's
 * store in INR showed every product-list price as "$500" because the list
 * and the variation rows hard-coded the symbol (v0.46.0).
 *
 * The symbol sits where the store's currency position puts it (left,
 * right, or either with a space), as WooCommerce prints it (v0.47.0).
 *
 * Fixtures (made here, deleted at the end): a variable product with one
 * variation, in a store switched to INR for the run and switched back after.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter, listSettled, onExit } = require( './helpers' );

const wp = ( args ) => execSync( `wp --path=${ JSON.stringify( WP ) } ${ args } 2>/dev/null` ).toString().trim();

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'store-currency' );
	await login( page );

	if ( ! ( await page.evaluate( () => !! window.MINN.wc ) ) ) {
		console.log( 'SKIP  needs WooCommerce' );
		await t.done( browser, errors );
		return;
	}

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, opts } );

	const was = wp( 'option get woocommerce_currency' ) || 'USD';
	const wasPos = wp( 'option get woocommerce_currency_pos' ) || 'left';
	const restore = onExit( () => {
		wp( `option update woocommerce_currency ${ was }` );
		wp( `option update woocommerce_currency_pos ${ wasPos }` );
	} );
	let pid = null;
	try {
		wp( 'option update woocommerce_currency INR' );
		wp( 'option update woocommerce_currency_pos left' );
		const name = 'Minn Rupee Lamp ' + Date.now().toString( 36 );
		const made = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( {
			name, type: 'variable', status: 'draft',
			attributes: [ { name: 'Size', options: [ 'Large' ], variation: true, visible: true } ],
		} ) } );
		pid = made.body && made.body.id;
		t.check( 'created a variable product', !! pid, JSON.stringify( made.status ) );
		await api( `wc/v3/products/${ pid }/variations`, { method: 'POST', body: JSON.stringify( {
			regular_price: '500', sale_price: '450', attributes: [ { name: 'Size', option: 'Large' } ],
		} ) } );

		await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
		t.check( 'boot payload carries the rupee', ( await page.evaluate( () => ( window.MINN.wcCurrency || {} ).symbol ) ) === '₹' );
		await page.waitForSelector( '#minn-order-search', { timeout: 30000 } );
		await page.fill( '#minn-order-search', name );
		await page.waitForSelector( `.minn-table-row[data-product="${ pid }"]`, { timeout: 30000 } );
		await listSettled( page );
		// A variable product's row shows its current price only.
		const row = await page.$eval( `.minn-table-row[data-product="${ pid }"]`, ( el ) => el.textContent );
		t.check( 'product list prints the store symbol', /₹450/.test( row ) && ! row.includes( '$' ), row.replace( /\s+/g, ' ' ).trim() );

		await page.goto( BASE + '/minn-admin/products/' + pid, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => /₹450/.test( document.body.textContent ), null, { timeout: 30000 } ).catch( () => null );
		const vars = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-prod-was' ) ].map( ( el ) => el.parentElement.textContent.trim() ) );
		t.check( 'variation rows print the store symbol', vars.length > 0 && vars.every( ( v ) => /^₹450\s*₹500$/.test( v ) ), JSON.stringify( vars ) );

		// The symbol after the amount, with a space: "450 ₹".
		wp( 'option update woocommerce_currency_pos right_space' );
		await page.goto( BASE + '/minn-admin/products/' + pid, { waitUntil: 'domcontentloaded' } );
		t.check( 'boot payload carries the position', ( await page.evaluate( () => ( window.MINN.wcCurrency || {} ).pos ) ) === 'right_space' );
		await page.waitForFunction( () => /450\u00a0₹/.test( document.body.textContent ), null, { timeout: 30000 } ).catch( () => null );
		const varsRight = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-prod-was' ) ].map( ( el ) => el.parentElement.textContent.trim() ) );
		t.check( 'variation rows put the symbol after the amount', varsRight.length > 0 && varsRight.every( ( v ) => /^450\u00a0₹\s*500\u00a0₹$/.test( v ) ), JSON.stringify( varsRight ) );
		await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-order-search', { timeout: 30000 } );
		await page.fill( '#minn-order-search', name );
		await page.waitForSelector( `.minn-table-row[data-product="${ pid }"]`, { timeout: 30000 } );
		await listSettled( page );
		const rowRight = await page.$eval( `.minn-table-row[data-product="${ pid }"]`, ( el ) => el.textContent );
		t.check( 'product list puts the symbol after the amount', /450\u00a0₹/.test( rowRight ) && ! /₹450/.test( rowRight ), rowRight.replace( /\s+/g, ' ' ).trim() );
	} catch ( e ) {
		t.check( 'suite ran to the end', false, e.message );
	} finally {
		if ( pid ) await api( `wc/v3/products/${ pid }?force=true`, { method: 'DELETE' } ).catch( () => null );
		restore();
		await t.done( browser, errors );
	}
} )();
