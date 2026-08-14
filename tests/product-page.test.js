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
		// The type reads as its label ("Simple product"), not as the slug.
		t.check( 'page sub carries type, SKU and id', /simple/i.test( head.sub ) && head.sub.includes( '#' + id ), head.sub );
		t.check( 'page has the shared detail fields', head.save && head.sku && head.price, JSON.stringify( head ) );

		// 2. Every choice on the page is a Minn combobox. A native select drops
		// an OS-drawn menu that ignores the theme, so one creeping back in is
		// a visual regression the eye catches long after the code lands.
		const natives = await page.evaluate( () =>
			document.querySelectorAll( '.minn-order-page select' ).length );
		t.check( 'the page uses no native selects', natives === 0, String( natives ) );

		// 3. The Products nav item stays lit on the detail page.
		const navLit = await page.evaluate( () => {
			const btn = document.querySelector( '.minn-nav-btn[data-nav="products"]' );
			return !! btn && btn.classList.contains( 'active' );
		} );
		t.check( 'Products nav stays active on the detail page', navLit, '' );

		// 4. The Shopify shape, measured rather than queried: a main column with
		// a sidebar beside it, each section its own card. Asserting on drawn
		// boxes is the point — the markup can carry both class names and still
		// paint as one slab if the CSS never lands.
		const boxes = () => page.evaluate( () => {
			const box = ( el ) => {
				if ( ! el ) return null;
				const r = el.getBoundingClientRect();
				return { x: Math.round( r.x ), y: Math.round( r.y ), w: Math.round( r.width ), right: Math.round( r.right ), bottom: Math.round( r.bottom ) };
			};
			const q = ( s ) => box( document.querySelector( s ) );
			return {
				main: q( '.minn-order-page .minn-order-main' ),
				side: q( '.minn-order-page .minn-order-side' ),
				pricing: q( '.minn-order-page [data-pcard="pricing"]' ),
				status: q( '.minn-order-page [data-pcard="status"]' ),
				org: q( '.minn-order-page [data-pcard="organization"]' ),
				cards: document.querySelectorAll( '.minn-order-page .minn-order-sec' ).length,
				slab: !! document.querySelector( '.minn-order-page .minn-order-grid' ),
			};
		} );
		const lay = await boxes();
		t.check( 'the page lays out a main column and a sidebar',
			!! lay.main && !! lay.side && lay.side.w >= 300 && lay.side.w <= 380 && lay.main.w > lay.side.w,
			JSON.stringify( { main: lay.main, side: lay.side } ) );
		t.check( 'the sidebar sits beside the main column, not under it',
			!! lay.main && !! lay.side && lay.side.x >= lay.main.right - 2 && Math.abs( lay.side.y - lay.main.y ) <= 8,
			JSON.stringify( { main: lay.main, side: lay.side } ) );
		t.check( 'Status and Organization ride in the sidebar',
			!! lay.side && !! lay.status && !! lay.org
				&& lay.status.x >= lay.side.x - 2 && lay.status.right <= lay.side.right + 2
				&& lay.org.x >= lay.side.x - 2 && lay.org.right <= lay.side.right + 2,
			JSON.stringify( { side: lay.side, status: lay.status, org: lay.org } ) );
		t.check( 'Pricing rides in the main column',
			!! lay.main && !! lay.pricing && lay.pricing.x >= lay.main.x - 2 && lay.pricing.right <= lay.main.right + 2,
			JSON.stringify( { main: lay.main, pricing: lay.pricing } ) );
		t.check( 'each section is its own card, not one slab',
			lay.cards >= 6 && ! lay.slab, JSON.stringify( { cards: lay.cards, slab: lay.slab } ) );

		// 5. The save bar: absent until something actually changes, reachable
		// without scrolling once it is up, and able to put the page back.
		const barState = () => page.evaluate( () => {
			const bar = document.querySelector( '#minn-p-savebar' );
			if ( ! bar ) return { present: false };
			const r = bar.getBoundingClientRect();
			return {
				present: true,
				shown: ! bar.hidden && r.height > 0 && bar.offsetParent !== null,
				top: Math.round( r.top ), bottom: Math.round( r.bottom ), vh: window.innerHeight,
				save: !! bar.querySelector( '#minn-product-save' ),
				discard: !! bar.querySelector( '#minn-p-discard' ),
			};
		} );
		const loadedName = await page.evaluate( () => ( document.querySelector( '#minn-p-name' ) || {} ).value || '' );
		const barClean = await barState();
		t.check( 'a page with no edits shows no save bar',
			barClean.present && ! barClean.shown, JSON.stringify( barClean ) );
		await page.fill( '#minn-p-name', loadedName + ' edited' );
		await page.waitForTimeout( 200 );
		const barDirty = await barState();
		t.check( 'editing a field raises the save bar',
			barDirty.shown && barDirty.save && barDirty.discard, JSON.stringify( barDirty ) );
		t.check( 'the save bar is reachable without scrolling',
			barDirty.shown && barDirty.bottom <= barDirty.vh + 2 && barDirty.top >= 0,
			JSON.stringify( barDirty ) );
		await page.click( '#minn-p-discard' );
		await page.waitForTimeout( 300 );
		const afterDiscard = await page.evaluate( () => ( document.querySelector( '#minn-p-name' ) || {} ).value || '' );
		const barAfterDiscard = await barState();
		t.check( 'discarding restores the loaded values and lowers the bar',
			afterDiscard === loadedName && ! barAfterDiscard.shown,
			JSON.stringify( { afterDiscard, bar: barAfterDiscard } ) );
		// A switch is not typing: the change never fires an input event, so a
		// listener that only watches inputs would miss it entirely.
		await page.click( '#minn-p-featured' );
		await page.waitForTimeout( 250 );
		const barSwitch = await barState();
		t.check( 'flipping a switch counts as a change too',
			barSwitch.shown, JSON.stringify( barSwitch ) );
		await page.click( '#minn-p-discard' );
		await page.waitForTimeout( 300 );

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
		const barSaved = await barState();
		t.check( 'saving lowers the save bar', barSaved.present && ! barSaved.shown, JSON.stringify( barSaved ) );

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
				// One body, two hosts: the two-column grid is the page's, so the
				// modal gets the same markup stacked in one column, and the save
				// bar belongs to the page alone. The modal paints its chrome
				// before the detail lands, so wait for a field, not the overlay.
				await page.waitForSelector( '.minn-modal-overlay #minn-p-name', { timeout: 20000 } ).catch( () => null );
				const qvShape = await page.evaluate( () => {
					const m = document.querySelector( '.minn-modal-overlay .minn-order-main' );
					const s = document.querySelector( '.minn-modal-overlay .minn-order-side' );
					if ( ! m || ! s ) return null;
					const a = m.getBoundingClientRect(), b = s.getBoundingClientRect();
					return {
						mx: Math.round( a.x ), sx: Math.round( b.x ),
						sy: Math.round( b.y ), mb: Math.round( a.bottom ),
						bar: !! document.querySelector( '.minn-modal-overlay #minn-p-savebar' ),
						save: !! document.querySelector( '.minn-modal-overlay #minn-product-save' ),
					};
				} );
				t.check( 'the quick view stacks the same body in one column',
					!! qvShape && Math.abs( qvShape.mx - qvShape.sx ) <= 2 && qvShape.sy >= qvShape.mb - 2,
					JSON.stringify( qvShape ) );
				t.check( 'the quick view keeps its own Save, not the page bar',
					!! qvShape && qvShape.save && ! qvShape.bar, JSON.stringify( qvShape ) );
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

			// Arriving from a product leaves a trail, so the editor offers the
			// way back. Without it the description is a one-way door: the
			// editor's only exits are the nav and the browser's own Back.
			const backLabel = await page.evaluate( () => {
				const b = document.querySelector( '#minn-editor-back' );
				return b ? b.textContent.trim() : null;
			} );
			t.check( 'the editor offers a way back to the product',
				!! backLabel && /renamed/.test( backLabel ), String( backLabel ) );
			if ( backLabel ) {
				await page.click( '#minn-editor-back' );
				await page.waitForFunction( ( pid ) => location.pathname.endsWith( '/products/' + pid ), id, { timeout: 15000 } ).catch( () => null );
				t.check( 'back from the editor returns to the product',
					await page.evaluate( ( pid ) => location.pathname.endsWith( '/products/' + pid ), id ),
					await page.evaluate( () => location.pathname ) );
			}
			// A deep link into the editor left no trail, so it offers no back:
			// the button follows where the reader came from, not the post type.
			await page.goto( BASE + '/minn-admin/editor/product/' + id, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-editor-body, .minn-editor-body', { timeout: 20000 } ).catch( () => null );
			t.check( 'a deep link into the editor offers no stale back',
				! ( await page.$( '#minn-editor-back' ) ), '' );
		}

		// 7. A stale page state does not leak into the next product.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		const revisit = await page.evaluate( () => ( ( document.querySelector( '#minn-p-name' ) || {} ).value || '' ) );
		t.check( 'revisiting refetches the product', /renamed/.test( revisit ), revisit );

		// 8. Narrow: the sidebar drops under the main column rather than being
		// squeezed beside it.
		await page.setViewportSize( { width: 900, height: 800 } );
		await page.waitForTimeout( 300 );
		const narrow = await boxes();
		t.check( 'a narrow viewport stacks the sidebar under the main column',
			!! narrow.main && !! narrow.side
				&& Math.abs( narrow.side.x - narrow.main.x ) <= 2
				&& narrow.side.y >= narrow.main.bottom - 2,
			JSON.stringify( { main: narrow.main, side: narrow.side } ) );
		await page.setViewportSize( { width: 1280, height: 720 } );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
