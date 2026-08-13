/**
 * The /products/{id} page: deep link, the shared detail body, saving from the
 * page, the row-click contract (row navigates, eye quick-views), and the
 * editor link that owns the long description.
 *
 * Fixture: one product created and deleted over REST.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-page' );

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
				name: 'Product page fixture ' + suffix,
				type: 'simple',
				regular_price: '12.00',
				sku: 'MINN-PP-' + suffix,
				status: 'publish',
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created', !! id, String( made.status ) );
		if ( ! id ) {
			await t.done( browser, errors );
			return;
		}

		// 1. Deep link straight to the page.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		const head = await page.evaluate( () => ( {
			page: !! document.querySelector( '.minn-order-page' ),
			modal: !! document.querySelector( '.minn-modal-overlay' ),
			back: !! document.querySelector( '#minn-pp-back' ),
			title: ( ( document.querySelector( '.minn-modal-title' ) || {} ).textContent || '' ).trim(),
			sub: ( ( document.querySelector( '.minn-modal-sub' ) || {} ).textContent || '' ).trim(),
			save: !! document.querySelector( '#minn-product-save' ),
			sku: !! document.querySelector( '#minn-p-sku' ),
			price: !! document.querySelector( '#minn-p-regular' ),
		} ) );
		t.check( 'deep link renders the page, not a modal', head.page && ! head.modal, JSON.stringify( head ) );
		t.check( 'page names the product', /Product page fixture/.test( head.title ), head.title );
		t.check( 'page sub carries type, SKU and id', /simple/.test( head.sub ) && head.sub.includes( '#' + id ), head.sub );
		t.check( 'page has the shared detail fields', head.save && head.sku && head.price, JSON.stringify( head ) );

		// 2. The Products nav item stays lit on the detail page.
		const navLit = await page.evaluate( () => {
			const btn = document.querySelector( '.minn-nav-btn[data-nav="products"]' );
			return !! btn && btn.classList.contains( 'active' );
		} );
		t.check( 'Products nav stays active on the detail page', navLit, '' );

		// 3. Saving from the page persists and repaints the page (not a modal).
		const newName = 'Product page renamed ' + suffix;
		await page.fill( '#minn-p-name', newName );
		await page.fill( '#minn-p-regular', '31.25' );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 400 );
		const saved = await api( `wc/v3/products/${ id }?_fields=id,name,regular_price` );
		t.check( 'name saved from the page',
			saved.body && saved.body.name === newName, JSON.stringify( saved.body ) );
		t.check( 'price saved from the page',
			saved.body && String( saved.body.regular_price ) === '31.25', JSON.stringify( saved.body ) );
		const stillPage = await page.evaluate( () => ( {
			page: !! document.querySelector( '.minn-order-page' ),
			modal: !! document.querySelector( '.minn-modal-overlay' ),
			title: ( ( document.querySelector( '.minn-modal-title' ) || {} ).textContent || '' ).trim(),
		} ) );
		t.check( 'save repaints the page in place',
			stillPage.page && ! stillPage.modal && /renamed/.test( stillPage.title ),
			JSON.stringify( stillPage ) );

		// 4. Back goes to the list.
		await page.click( '#minn-pp-back' );
		await page.waitForSelector( '.minn-table-row[data-product]', { timeout: 20000 } );
		t.check( 'back returns to the products list',
			/\/minn-admin\/products$/.test( await page.evaluate( () => location.pathname ) ),
			await page.evaluate( () => location.pathname ) );

		// 5. Row click navigates; the eye quick-views. Real mouse clicks: a
		// synthetic click can pass on an element a reader could not hit.
		const rowSel = `.minn-table-row[data-product="${ id }"]`;
		await page.waitForSelector( rowSel, { timeout: 20000 } ).catch( () => null );
		const haveRow = !! ( await page.$( rowSel ) );
		if ( haveRow ) {
			const eye = await page.$( `${ rowSel } [data-pqv]` );
			t.check( 'row carries a quick-view button', !! eye, '' );
			if ( eye ) {
				const box = await eye.boundingBox();
				await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
				await page.waitForSelector( '.minn-modal-overlay', { timeout: 15000 } ).catch( () => null );
				const qv = await page.evaluate( () => ( {
					modal: !! document.querySelector( '.minn-modal-overlay' ),
					path: location.pathname,
				} ) );
				t.check( 'eye opens the quick view without navigating',
					qv.modal && /\/products$/.test( qv.path ), JSON.stringify( qv ) );
				await page.keyboard.press( 'Escape' );
				await page.waitForTimeout( 300 );
			}
			const nameCell = await page.$( `${ rowSel } .minn-prod-name` );
			if ( nameCell ) {
				const box = await nameCell.boundingBox();
				await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
				await page.waitForFunction( ( pid ) => location.pathname.endsWith( '/products/' + pid ), id, { timeout: 15000 } ).catch( () => null );
				t.check( 'row click opens the product page',
					await page.evaluate( ( pid ) => location.pathname.endsWith( '/products/' + pid ), id ),
					await page.evaluate( () => location.pathname ) );
			}
		}

		// 6. The description link hands off to Minn's editor.
		await page.waitForSelector( '#minn-p-editor', { timeout: 20000 } ).catch( () => null );
		const hasEd = !! ( await page.$( '#minn-p-editor' ) );
		t.check( 'page offers the editor for the long description', hasEd, '' );
		if ( hasEd ) {
			await page.click( '#minn-p-editor' );
			await page.waitForFunction( ( pid ) => location.pathname.includes( '/editor/product/' + pid ), id, { timeout: 20000 } ).catch( () => null );
			const edPath = await page.evaluate( () => location.pathname );
			t.check( 'editor link routes to the product editor',
				edPath.includes( '/editor/product/' + id ), edPath );
			await page.waitForSelector( '#minn-editor-body, .minn-editor-body', { timeout: 20000 } ).catch( () => null );
			t.check( 'the product opens in Minn\'s editor',
				!! ( await page.$( '#minn-editor-body, .minn-editor-body' ) ), '' );
		}

		// 7. A stale page state does not leak into the next product.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		const revisit = await page.evaluate( () => ( ( document.querySelector( '#minn-p-name' ) || {} ).value || '' ) );
		t.check( 'revisiting refetches the product', /renamed/.test( revisit ), revisit );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
