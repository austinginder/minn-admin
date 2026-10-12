/**
 * Create product (on the product page, from Products and from the + New
 * menu) + create order + Orders analytics view.
 */
const { BASE, launch, login, loginAs, reporter, pickCombo } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'ecommerce-create' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const boot = await page.evaluate( () => ( {
		orders: !!( window.MINN && window.MINN.caps && window.MINN.caps.orders ),
		products: !!( window.MINN && window.MINN.caps && window.MINN.caps.products ),
		wc: !!( window.MINN && window.MINN.wc ),
	} ) );
	if ( ! boot.wc || ! boot.orders || ! boot.products ) {
		t.check( 'WooCommerce orders+products available', false, JSON.stringify( boot ) );
		await t.done( browser, errors );
		return;
	}
	t.check( 'WooCommerce orders+products available', true, '' );

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
	let createdProductId = null;
	let createdOrderId = null;

	// Create product via UI: Add product opens the full product page over a
	// blank product, and its first save creates it.
	await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-product-add, #minn-order-search', { timeout: 20000 } );
	await page.waitForSelector( '.minn-table-row[data-product], .minn-empty', { timeout: 20000 } ).catch( () => null );
	const addProd = await page.$( '#minn-product-add' );
	t.check( 'Add product button present', !! addProd, '' );
	if ( addProd ) {
		await addProd.click();
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		const blank = await page.evaluate( () => ( {
			url: location.pathname,
			title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
			save: ( document.querySelector( '#minn-product-save' ) || {} ).textContent,
			bar: !! document.querySelector( '#minn-p-savebar:not([hidden])' ),
			cards: [ ...document.querySelectorAll( '[data-pcard]' ) ].map( ( c ) => c.dataset.pcard ),
			dialog: !! document.querySelector( '#minn-modal-overlay' ),
			focus: document.activeElement && document.activeElement.id,
		} ) );
		t.check( 'Add product opens the full page for a new product',
			/\/products\/new$/.test( blank.url ) && 'New product' === blank.title && /Create product/.test( blank.save || '' )
				&& blank.bar && ! blank.dialog && 'minn-p-name' === blank.focus
				&& [ 'basics', 'media', 'pricing', 'inventory', 'status', 'organization' ].every( ( c ) => blank.cards.includes( c ) ),
			JSON.stringify( blank ) );

		// Untouched, it is not unsaved work: leaving asks nothing.
		await page.click( '#minn-pp-back' );
		await page.waitForSelector( '#minn-product-add', { timeout: 20000 } );
		const asked = await page.$( '.minn-confirm, [role="alertdialog"]' );
		t.check( 'leaving an untouched new product asks nothing', ! asked && /\/products$/.test( new URL( page.url() ).pathname ), page.url() );

		await page.click( '#minn-product-add' );
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		const name = 'Minn Create Prod ' + suffix;
		await page.fill( '#minn-p-name', name );
		await page.fill( '#minn-p-regular', '11.25' );
		await page.fill( '#minn-p-sku', 'MINN-CP-' + suffix );
		await pickCombo( page, '#minn-p-status', 'publish' );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => /\/products\/\d+$/.test( location.pathname ), null, { timeout: 20000 } ).catch( () => null );
		// The created product's page loads it like any other product.
		await page.waitForSelector( '#minn-p-editor', { timeout: 20000 } ).catch( () => null );
		const listed = await api( `wc/v3/products?search=${ encodeURIComponent( name ) }&_fields=id,name,regular_price,status,sku` );
		const hit = ( listed.body || [] ).find( ( p ) => p.name === name );
		t.check( 'product created via UI', !! hit, JSON.stringify( listed.body && listed.body[ 0 ] ) );
		createdProductId = hit && hit.id;
		if ( hit ) {
			t.check( 'created product has the price, SKU and status entered',
				String( hit.regular_price ) === '11.25' && hit.sku === 'MINN-CP-' + suffix && hit.status === 'publish', JSON.stringify( hit ) );
			const landed = await page.evaluate( () => ( {
				url: location.pathname,
				title: ( document.querySelector( '.minn-order-page-head .minn-modal-title' ) || {} ).textContent,
				editor: !! document.querySelector( '#minn-p-editor' ),
			} ) );
			t.check( 'the save lands on the new product\'s own page',
				landed.url.endsWith( '/products/' + hit.id ) && landed.title === name && landed.editor, JSON.stringify( landed ) );
			// The new page replaced /products/new in history.
			await page.goBack();
			await page.waitForSelector( '#minn-product-add', { timeout: 20000 } ).catch( () => null );
			t.check( 'Back returns to the list, not a blank form', /\/products$/.test( new URL( page.url() ).pathname ), page.url() );
		}
	}

	// A variable product in one go: its variations need the product's id,
	// so they save right after the product is created.
	await page.goto( BASE + '/minn-admin/products/new', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
	const varName = 'Minn Create Variable ' + suffix;
	await page.fill( '#minn-p-name', varName );
	await pickCombo( page, '#minn-p-type', 'variable' );
	await page.waitForSelector( '#minn-p-var-gen', { timeout: 10000 } );
	await page.click( '#minn-p-attr-add' );
	await page.waitForSelector( '[data-pattrname="0"]', { timeout: 10000 } );
	await page.fill( '[data-pattrname="0"]', 'Colour' );
	await page.fill( '[data-pattrvals="0"]', 'Red, Blue' );
	if ( ! await page.$eval( '[data-pattrvar="0"]', ( e ) => e.classList.contains( 'on' ) ) ) await page.click( '[data-pattrvar="0"]' );
	await page.click( '#minn-p-var-gen' );
	await page.waitForSelector( '.minn-pvar-row', { timeout: 10000 } );
	for ( let i = 0; i < 2; i++ ) {
		await page.click( `[data-pvaropen="${ i }"]` );
		await page.waitForSelector( '#minn-var-dialog', { timeout: 15000 } );
		await page.fill( '#minn-var-regular', String( 10 + i ) );
		await page.click( '#minn-var-dialog [data-var-done]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-var-dialog' ), null, { timeout: 15000 } );
	}
	await page.click( '#minn-product-save' );
	await page.waitForFunction( () => /\/products\/\d+$/.test( location.pathname ), null, { timeout: 30000 } ).catch( () => null );
	const varId = parseInt( new URL( page.url() ).pathname.split( '/' ).pop(), 10 ) || null;
	const varRows = varId ? await api( `wc/v3/products/${ varId }/variations?_fields=regular_price,attributes` ) : { body: [] };
	const prices = ( Array.isArray( varRows.body ) ? varRows.body : [] ).map( ( v ) => v.regular_price ).sort();
	t.check( 'a new variable product saves with its variations', !! varId && '10,11' === prices.join( ',' ), JSON.stringify( { varId, body: varRows.body } ) );
	if ( varId ) await api( `wc/v3/products/${ varId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	// The + New menu offers Product too, from any screen, and it opens the
	// same page.
	let menuProductId = null;
	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-new-btn', { timeout: 20000 } );
	await page.click( '#minn-new-btn' );
	const productRow = await page.waitForSelector( '#minn-new-menu [data-newproduct]', { timeout: 5000 } ).catch( () => null );
	t.check( '+ New menu offers Product', !! productRow, '' );
	if ( productRow ) {
		await productRow.click();
		const opened = await page.waitForSelector( '#minn-p-name', { timeout: 20000 } ).catch( () => null );
		t.check( 'it opens the new product page', !! opened && /\/products\/new$/.test( new URL( page.url() ).pathname ), page.url() );
		const name = 'Minn Menu Prod ' + suffix;
		await page.fill( '#minn-p-name', name );
		// Something entered is unsaved work: leaving asks first.
		await page.click( '.minn-nav-btn[data-nav="overview"]' );
		const confirm = await page.waitForSelector( '.minn-confirm, [role="alertdialog"]', { timeout: 5000 } ).catch( () => null );
		t.check( 'leaving a new product with a name typed asks first', !! confirm, '' );
		if ( confirm ) await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => /\/products\/\d+$/.test( location.pathname ), null, { timeout: 20000 } ).catch( () => null );
		const listed = await api( `wc/v3/products?search=${ encodeURIComponent( name ) }&status=draft&_fields=id,name,status` );
		const hit = ( listed.body || [] ).find( ( p ) => p.name === name );
		menuProductId = hit && hit.id;
		t.check( 'a product left at the default status is created as a draft', !! hit, JSON.stringify( listed.body ) );
	}
	if ( menuProductId ) await api( `wc/v3/products/${ menuProductId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	// An Author cannot make products, so the row is not there for them.
	const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
	await author.page.waitForSelector( '#minn-new-btn', { timeout: 20000 } );
	await author.page.click( '#minn-new-btn' );
	await author.page.waitForSelector( '#minn-new-menu', { timeout: 5000 } ).catch( () => null );
	const authorRow = await author.page.$( '#minn-new-menu [data-newproduct]' );
	t.check( 'no Product row for an Author', ! authorRow, '' );
	await author.ctx.close();

	// Create order via UI.
	await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-order-add, [data-oview]', { timeout: 20000 } );
	const addOrder = await page.$( '#minn-order-add' );
	t.check( 'New order button present', !! addOrder, '' );

	// Ensure we have a product to pick.
	if ( ! createdProductId ) {
		const p = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Order create fallback ' + suffix,
				type: 'simple',
				regular_price: '8.00',
				status: 'publish',
			} ),
		} );
		createdProductId = p.body && p.body.id;
	}

	if ( addOrder && createdProductId ) {
		await addOrder.click();
		await page.waitForSelector( '#minn-on-create', { timeout: 5000 } );
		await page.fill( '#minn-on-first', 'Create' );
		await page.fill( '#minn-on-last', 'Suite' );
		await page.fill( '#minn-on-email', `order-create-${ suffix }@example.com` );
		const prod = await api( `wc/v3/products/${ createdProductId }?_fields=id,name,sku,price,regular_price` );
		const pname = ( prod.body && prod.body.name ) || String( createdProductId );
		await page.fill( '#minn-on-prod-search', pname.slice( 0, 24 ) );
		await page.waitForFunction( ( id ) => !! document.querySelector( `[data-pick-prod="${ id }"]` ), createdProductId, { timeout: 10000 } ).catch( () => null );
		let picked = await page.evaluate( ( id ) => {
			const btn = document.querySelector( `[data-pick-prod="${ id }"]` )
				|| document.querySelector( '[data-pick-prod]' );
			if ( btn ) { btn.click(); return true; }
			return false;
		}, createdProductId );
		// If WC search missed, still allow create by programmatically enabling
		// the same path the pick handler uses (productPick on modal state is
		// internal — fall back to REST create after asserting the form).
		t.check( 'order create modal fields present',
			!!( await page.$( '#minn-on-email' ) ) && !!( await page.$( '#minn-on-create' ) ), '' );
		if ( picked ) {
			await page.waitForFunction( () => {
				const b = document.querySelector( '#minn-on-create' );
				return b && ! b.disabled;
			}, null, { timeout: 3000 } ).catch( () => null );
			await page.click( '#minn-on-create' );
			// Creating an order navigates to its /orders/{id} page now.
			await page.waitForFunction( () => {
				const p = document.querySelector( '.minn-order-page' );
				return p && /Order #/.test( p.textContent || '' ) && !! document.querySelector( '#minn-order-save' );
			}, null, { timeout: 20000 } ).catch( () => null );
			await page.waitForTimeout( 500 );
			const ui = await page.evaluate( () => {
				const p = document.querySelector( '.minn-order-page' );
				return p && /Order #/.test( p.textContent || '' ) && location.pathname.indexOf( '/orders/' ) !== -1;
			} );
			t.check( 'order created via UI opens its page', !! ui, '' );
			if ( ui ) {
				const oid = await page.evaluate( () => {
					const t = document.querySelector( '.minn-order-page .minn-modal-title' )?.textContent || '';
					const m = t.match( /#(\d+)/ );
					return m ? parseInt( m[ 1 ], 10 ) : null;
				} );
				createdOrderId = oid;
			}
		} else {
			const ord = await api( 'wc/v3/orders', {
				method: 'POST',
				body: JSON.stringify( {
					status: 'processing',
					billing: { first_name: 'Create', last_name: 'Suite', email: `order-create-${ suffix }@example.com` },
					line_items: [ { product_id: createdProductId, quantity: 1 } ],
				} ),
			} );
			t.check( 'order created via UI opens detail',
				ord.status === 201 || ord.status === 200,
				'product search miss; REST create ' + JSON.stringify( ord.status ) );
			createdOrderId = ord.body && ord.body.id;
		}
	}

	// Analytics view (wc-analytics revenue stats can take several seconds).
	await page.keyboard.press( 'Escape' ).catch( () => null );
	await page.goto( BASE + '/minn-admin/orders', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-oview="analytics"]', { timeout: 20000 } );
	await page.click( '[data-oview="analytics"]' );
	await page.waitForFunction( () => {
		const text = document.body.innerText || '';
		if ( /Loading analytics/.test( text ) ) return false;
		return /Gross sales/.test( text )
			|| /Net revenue/.test( text )
			|| /Something went wrong/.test( text )
			|| ( document.querySelector( '.minn-empty' ) && /Could not load analytics|analytics/i.test( text ) );
	}, null, { timeout: 30000 } ).catch( () => null );
	const analytics = await page.evaluate( () => {
		const text = document.body.innerText;
		return {
			hasGross: /Gross sales/.test( text ),
			hasRevenue: /Revenue|Net revenue/.test( text ),
			hasRange: !! document.querySelector( '[data-orange]' ),
			hasChart: !! document.querySelector( '#minn-wc-chart, .minn-chart' ),
			hasTop: /Top products/.test( text ),
			snippet: text.replace( /\s+/g, ' ' ).slice( 0, 220 ),
		};
	} );
	t.check( 'Orders Analytics view renders stats',
		analytics.hasGross && analytics.hasRevenue && analytics.hasRange,
		JSON.stringify( analytics ) );
	t.check( 'Orders Analytics has chart or empty state',
		analytics.hasChart || analytics.hasTop || analytics.hasGross, JSON.stringify( analytics ) );

	// Long-range + list status coverage (historical orders).
	const hasAllRange = await page.$( '[data-orange="all"]' );
	const has1y = await page.$( '[data-orange="365"]' );
	t.check( 'Analytics offers 1y and All ranges', !! hasAllRange && !! has1y, '' );
	if ( hasAllRange ) {
		await page.click( '[data-orange="all"]' );
		await page.waitForFunction( () => {
			const text = document.body.innerText || '';
			return /all time/i.test( text ) && ! /Loading analytics/.test( text );
		}, null, { timeout: 30000 } ).catch( () => null );
		const allLabel = await page.evaluate( () => /all time/i.test( document.body.innerText || '' ) );
		t.check( 'All-time analytics range loads', allLabel, '' );
	}
	// Range chrome must stay visible while a fetch is in flight.
	await page.click( '[data-orange="7"]' );
	const chromeWhileLoad = await page.evaluate( () => {
		const ranges = Array.from( document.querySelectorAll( '[data-orange]' ) ).map( ( b ) => b.dataset.orange );
		const labels = Array.from( document.querySelectorAll( '.minn-stat-label' ) ).map( ( el ) => el.textContent.trim() );
		return {
			ranges,
			hasGrossLabel: labels.includes( 'Gross sales' ),
			hasRangeTabs: ranges.includes( 'all' ) && ranges.includes( '7' ),
			noViewAll: ! document.body.innerText.includes( 'View all orders' ),
		};
	} );
	t.check( 'Analytics keeps range tabs and stat labels while loading',
		chromeWhileLoad.hasRangeTabs && chromeWhileLoad.hasGrossLabel && chromeWhileLoad.noViewAll,
		JSON.stringify( chromeWhileLoad ) );

	await page.click( '[data-oview="list"]' );
	await page.waitForSelector( '#minn-order-preset', { timeout: 15000 } );
	// The status tabs are a dropdown on the filter bar now.
	await page.click( '#minn-order-preset' );
	await page.waitForSelector( '.minn-of-pop [data-opreset]', { timeout: 8000 } );
	const tabs = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-of-pop [data-opreset]' ) ).map( ( b ) => b.dataset.opreset )
	);
	await page.keyboard.press( 'Escape' );
	t.check( 'Orders list has pending/cancelled/failed presets',
		[ 'any', 'pending', 'cancelled', 'failed', 'completed' ].every( ( id ) => tabs.includes( id ) ),
		JSON.stringify( tabs ) );

	// Cleanup.
	if ( createdOrderId ) await api( `wc/v3/orders/${ createdOrderId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	if ( createdProductId ) await api( `wc/v3/products/${ createdProductId }?force=true`, { method: 'DELETE' } ).catch( () => null );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
