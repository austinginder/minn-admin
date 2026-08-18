/**
 * The products list filter bar: the same machine orders and subscriptions
 * already run, now over WooCommerce's product vocabulary. A status dropdown,
 * the search box and an add-filter button on one row, active filters as chips
 * beneath, and the two tab strips (status, stock) gone.
 *
 * The standing rule this suite exists to protect is the orders one: filtering
 * is SERVER side. Every check asserts both the rows on screen AND the query
 * string Minn sent, because a filter that narrows the DOM while the request
 * stays unfiltered lies about its counts and breaks under pagination.
 *
 * Low stock is the exception, and deliberately so: it is not a stock_status
 * value, it is the wc-analytics lookup with a managed-stock scan behind it.
 * The check for it asserts the rows and asserts that no bogus stock_status=low
 * ever reached wc/v3.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-filters' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	// Only the LIST request, never the popover's own lookups: the category and
	// tag pickers ride different paths, but a bare wc/v3/products?search= from
	// some other picker would otherwise be mistaken for the list.
	const sent = [];
	page.on( 'request', ( r ) => {
		const u = decodeURIComponent( r.url() );
		if ( /\/wc\/v3\/products\?/.test( u ) && /_fields=id,name,type,status,sku/.test( u ) ) sent.push( u );
	} );
	const lastQuery = () => ( sent.length ? sent[ sent.length - 1 ] : '' );
	const waitForQuery = async ( re, label ) => {
		const start = Date.now();
		while ( Date.now() - start < 15000 ) {
			if ( re.test( lastQuery() ) ) return true;
			await page.waitForTimeout( 200 );
		}
		t.check( 'query carried ' + label, false, lastQuery().slice( 0, 200 ) );
		return false;
	};

	await login( page );

	const hasWc = await page.evaluate( () => !! ( window.MINN && window.MINN.wc && window.MINN.caps && window.MINN.caps.products ) );
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
	const made = {};
	let catId = null, tagId = null;

	const visibleIds = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-table-row[data-product]' ) ).map( ( r ) => parseInt( r.dataset.product, 10 ) ) );
	const chipLabels = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '[data-ofchip]' ) ).map( ( c ) => c.textContent.replace( /\s+/g, ' ' ).trim() ) );
	const waitRows = async ( has, hasNot ) => {
		try {
			await page.waitForFunction( ( a ) => {
				const ids = Array.from( document.querySelectorAll( '.minn-table-row[data-product]' ) ).map( ( r ) => parseInt( r.dataset.product, 10 ) );
				if ( document.querySelector( '#minn-view .minn-loading' ) ) return false;
				return a.has.every( ( id ) => ids.includes( id ) ) && a.hasNot.every( ( id ) => ! ids.includes( id ) );
			}, { has, hasNot }, { timeout: 15000 } );
			return true;
		} catch ( e ) {
			return false;
		}
	};
	// A soft reload keeps the toolbar and dims the list (.minn-busy) instead of
	// swapping in the cold "Loading…" shell, so rows alone do not say the list
	// has settled. Clicking a chip while the render is still in flight loses
	// the click against the node it is about to replace.
	const settle = async () => {
		await page.waitForFunction( () =>
			! document.querySelector( '#minn-view .minn-loading, #minn-view .minn-busy' ),
		null, { timeout: 20000 } ).catch( () => {} );
		await page.waitForTimeout( 150 );
	};
	const pickPreset = async ( slug ) => {
		await settle();
		await page.click( '#minn-order-preset' );
		await page.waitForSelector( `.minn-of-pop [data-opreset="${ slug }"]`, { timeout: 8000 } );
		await page.click( `.minn-of-pop [data-opreset="${ slug }"]` );
		await settle();
	};
	const presetLabel = () => page.evaluate( () => {
		const b = document.querySelector( '#minn-order-preset' );
		return b ? b.textContent.replace( /\s+/g, ' ' ).trim() : '';
	} );
	const openFilterMenu = async ( which ) => {
		await settle();
		await page.click( '#minn-order-addfilter' );
		await page.waitForSelector( `[data-offilter="${ which }"]`, { timeout: 8000 } );
		await page.click( `[data-offilter="${ which }"]` );
		await page.waitForSelector( '.minn-of-pop', { timeout: 8000 } );
	};
	/** Open a choice dimension and pick a value. */
	const pickChoice = async ( kind, value ) => {
		await openFilterMenu( kind );
		await page.click( `.minn-of-pop [data-ofval="${ value }"]` );
		await settle();
	};
	/** Open a lookup dimension, search for a term and take the first hit. */
	const pickLookup = async ( kind, query ) => {
		await openFilterMenu( kind );
		await page.fill( '.minn-of-pop .minn-ac-input', query );
		await page.waitForSelector( '.minn-of-pop .minn-ac-item', { timeout: 10000 } );
		await page.click( '.minn-of-pop .minn-ac-item' );
		await settle();
	};
	const clearAll = async () => {
		await settle();
		await page.click( '#minn-order-clearfilters' ).catch( () => {} );
		await page.waitForFunction( () => ! document.querySelector( '[data-ofchip]' ), null, { timeout: 8000 } ).catch( () => {} );
		await settle();
	};

	try {
		// ---- Fixtures: one product per dimension this bar can filter on ----
		const cat = await api( 'wc/v3/products/categories', {
			method: 'POST',
			body: JSON.stringify( { name: 'Filter Cat ' + suffix } ),
		} );
		catId = cat.body && cat.body.id;
		const tag = await api( 'wc/v3/products/tags', {
			method: 'POST',
			body: JSON.stringify( { name: 'Filter Tag ' + suffix } ),
		} );
		tagId = tag.body && tag.body.id;
		t.check( 'fixtures: category + tag created', !! ( catId && tagId ), JSON.stringify( { catId, tagId } ) );

		const mk = async ( key, body ) => {
			const r = await api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( body ) } );
			made[ key ] = r.body && r.body.id;
			return made[ key ];
		};
		// Featured, in the category, in stock.
		await mk( 'featured', {
			name: 'PF Featured ' + suffix, type: 'simple', status: 'publish',
			regular_price: '10.00', featured: true, stock_status: 'instock',
			categories: [ { id: catId } ],
		} );
		// On sale, out of stock, carrying the tag.
		await mk( 'sale', {
			name: 'PF Sale ' + suffix, type: 'simple', status: 'publish',
			regular_price: '20.00', sale_price: '15.00', stock_status: 'outofstock',
			tags: [ { id: tagId } ],
		} );
		// A variable product, for the type filter to have a target.
		await mk( 'variable', {
			name: 'PF Variable ' + suffix, type: 'variable', status: 'publish',
		} );
		// A draft, for the status dropdown.
		await mk( 'draft', {
			name: 'PF Draft ' + suffix, type: 'simple', status: 'draft', regular_price: '5.00',
		} );
		// Managed stock at or under the store threshold, for Low stock.
		const thr = await page.evaluate( () => Number( window.MINN.wcLowStock ) || 2 );
		await mk( 'low', {
			name: 'PF Low ' + suffix, type: 'simple', status: 'publish', regular_price: '7.00',
			manage_stock: true, stock_quantity: Math.max( 0, thr - 1 ), stock_status: 'instock',
		} );
		t.check( 'fixtures: five products created', Object.values( made ).every( Boolean ), JSON.stringify( made ) );

		await page.setViewportSize( { width: 1440, height: 1000 } );
		await page.goto( `${ BASE }/minn-admin/products`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-product]', { timeout: 25000 } );

		// ---- The bar replaces both tab strips ----
		const barOrder = await page.evaluate( () => {
			const x = ( sel ) => {
				const el = document.querySelector( sel );
				return el ? el.getBoundingClientRect().x : -1;
			};
			const row = ( sel ) => {
				const el = document.querySelector( sel );
				return el ? Math.round( el.getBoundingClientRect().y ) : -1;
			};
			return {
				preset: x( '#minn-order-preset' ), search: x( '#minn-order-search' ), add: x( '#minn-order-addfilter' ),
				sameRow: Math.abs( row( '#minn-order-preset' ) - row( '#minn-order-search' ) ) < 12
					&& Math.abs( row( '#minn-order-search' ) - row( '#minn-order-addfilter' ) ) < 12,
				statusTabsGone: ! document.querySelector( '[data-ptab]' ),
				stockTabsGone: ! document.querySelector( '[data-pstock]' ),
			};
		} );
		t.check( 'one row: status, search, add filter, in that order',
			barOrder.preset < barOrder.search && barOrder.search < barOrder.add && barOrder.sameRow,
			JSON.stringify( barOrder ) );
		t.check( 'both tab strips are gone', barOrder.statusTabsGone && barOrder.stockTabsGone, JSON.stringify( barOrder ) );

		// ---- Status dropdown ----
		await pickPreset( 'draft' );
		t.check( 'status dropdown filters to drafts',
			await waitRows( [ made.draft ], [ made.featured, made.sale ] ), JSON.stringify( await visibleIds() ) );
		await waitForQuery( /[?&]status=draft/, 'status=draft' );
		t.check( 'status dropdown sent status to the server', /[?&]status=draft/.test( lastQuery() ), lastQuery().slice( -120 ) );
		t.check( 'the dropdown names the active status', /Draft/i.test( await presetLabel() ), await presetLabel() );
		await pickPreset( 'any' );
		await waitRows( [ made.featured ], [] );

		// ---- Stock ----
		await pickChoice( 'stock', 'outofstock' );
		t.check( 'stock filter keeps only the out-of-stock product',
			await waitRows( [ made.sale ], [ made.featured ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'stock_status travelled to the server', /[?&]stock_status=outofstock/.test( lastQuery() ), lastQuery().slice( -120 ) );
		const stockChip = await chipLabels();
		t.check( 'chip names the stock filter and its value',
			stockChip.some( ( c ) => /Stock/i.test( c ) && /out of stock/i.test( c ) ), JSON.stringify( stockChip ) );
		await clearAll();
		await waitRows( [ made.featured, made.sale ], [] );

		// ---- Category, through the async picker ----
		await pickLookup( 'category', 'Filter Cat ' + suffix );
		t.check( 'category filter keeps only products in it',
			await waitRows( [ made.featured ], [ made.sale, made.variable ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'category id travelled to the server',
			new RegExp( '[?&]category=' + catId + '(&|$)' ).test( lastQuery() ), lastQuery().slice( -120 ) );

		// ---- Filters live in the URL, so a reload restores them ----
		let url = new URL( await page.evaluate( () => location.href ) );
		t.check( 'the category filter is in the URL', url.searchParams.get( 'category' ) === String( catId ), url.search );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-product]', { timeout: 25000 } );
		t.check( 'a reload restores the filtered rows',
			await waitRows( [ made.featured ], [ made.sale ] ), JSON.stringify( await visibleIds() ) );
		// The URL can only carry the id; the name arrives on its own request.
		const nameResolved = await page.waitForFunction( ( s ) =>
			Array.from( document.querySelectorAll( '[data-ofchip]' ) ).some( ( c ) => c.textContent.indexOf( 'Filter Cat ' + s ) !== -1 ),
		suffix, { timeout: 10000 } ).then( () => true ).catch( () => false );
		t.check( 'the restored category chip resolves its name, not its id',
			nameResolved, JSON.stringify( await chipLabels() ) );
		await clearAll();
		await waitRows( [ made.featured, made.sale ], [] );

		// ---- Tag ----
		await pickLookup( 'tag', 'Filter Tag ' + suffix );
		t.check( 'tag filter keeps only products carrying it',
			await waitRows( [ made.sale ], [ made.featured ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'tag id travelled to the server',
			new RegExp( '[?&]tag=' + tagId + '(&|$)' ).test( lastQuery() ), lastQuery().slice( -120 ) );
		await clearAll();
		await waitRows( [ made.featured, made.sale ], [] );

		// ---- Type ----
		await pickChoice( 'type', 'variable' );
		t.check( 'type filter keeps only variable products',
			await waitRows( [ made.variable ], [ made.featured, made.sale ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'type travelled to the server', /[?&]type=variable/.test( lastQuery() ), lastQuery().slice( -120 ) );
		await clearAll();
		await waitRows( [ made.featured ], [] );

		// ---- Featured ----
		await pickChoice( 'featured', 'true' );
		t.check( 'featured filter keeps only featured products',
			await waitRows( [ made.featured ], [ made.sale, made.variable ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'featured travelled to the server', /[?&]featured=true/.test( lastQuery() ), lastQuery().slice( -120 ) );
		await clearAll();
		await waitRows( [ made.sale ], [] );

		// ---- On sale ----
		await pickChoice( 'onsale', 'true' );
		t.check( 'on-sale filter keeps only discounted products',
			await waitRows( [ made.sale ], [ made.featured ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'on_sale travelled to the server', /[?&]on_sale=true/.test( lastQuery() ), lastQuery().slice( -120 ) );

		// ---- Removing one chip leaves the others alone ----
		await pickChoice( 'stock', 'outofstock' );
		await waitRows( [ made.sale ], [ made.featured ] );
		t.check( 'two filters make two chips', ( await chipLabels() ).length === 2, JSON.stringify( await chipLabels() ) );
		await page.click( '[data-ofchip="stock"] [data-ofremove]' );
		await page.waitForFunction( () => document.querySelectorAll( '[data-ofchip]' ).length === 1, null, { timeout: 8000 } ).catch( () => {} );
		await settle();
		const leftChip = await chipLabels();
		t.check( 'removing one chip keeps the other',
			leftChip.length === 1 && /sale/i.test( leftChip[ 0 ] ), JSON.stringify( leftChip ) );
		t.check( 'the removed filter left the query',
			! /[?&]stock_status=/.test( lastQuery() ) && /[?&]on_sale=true/.test( lastQuery() ), lastQuery().slice( -120 ) );

		await clearAll();
		t.check( 'clear all removes every chip', ( await chipLabels() ).length === 0, JSON.stringify( await chipLabels() ) );
		await waitRows( [ made.featured, made.sale ], [] );
		t.check( 'cleared query carries no filter params',
			! /[?&](stock_status|category|tag|type|featured|on_sale)=/.test( lastQuery() ), lastQuery().slice( -120 ) );

		// ---- Low stock: the analytics path, not a stock_status ----
		const beforeLow = sent.length;
		await pickChoice( 'stock', 'low' );
		t.check( 'low stock surfaces the managed low-quantity product',
			await waitRows( [ made.low ], [] ), JSON.stringify( await visibleIds() ) );
		t.check( 'low stock never asks wc/v3 for a stock_status that does not exist',
			! sent.slice( beforeLow ).some( ( u ) => /stock_status=low/.test( u ) ),
			sent.slice( beforeLow ).join( ' | ' ).slice( 0, 200 ) );
		await clearAll();
		await waitRows( [ made.featured, made.sale ], [] );

		// ---- A hand-edited URL is untrusted input like any other ----
		await page.goto( `${ BASE }/minn-admin/products?status=not-a-status&category=abc&type=nonsense`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-product]', { timeout: 25000 } );
		t.check( 'junk in the URL is ignored, not sent on',
			! /status=not-a-status/.test( lastQuery() ) && ! /category=abc/.test( lastQuery() ) && ! /type=nonsense/.test( lastQuery() ),
			lastQuery().slice( -120 ) );
		t.check( 'junk in the URL leaves no chips', ( await chipLabels() ).length === 0, JSON.stringify( await chipLabels() ) );

		// ---- A filter change always returns to page one ----
		await pickChoice( 'type', 'simple' );
		await waitForQuery( /[?&]type=simple/, 'type=simple' );
		t.check( 'a filter change asks for page 1', /[?&]page=1(&|$)/.test( lastQuery() ), lastQuery().slice( -120 ) );

		// ---- The count label follows the filtered total ----
		await clearAll();
		await pickLookup( 'category', 'Filter Cat ' + suffix );
		await waitRows( [ made.featured ], [ made.sale ] );
		const shown = ( await visibleIds() ).length;
		const meta = await page.evaluate( () => {
			const el = document.querySelector( '.minn-toolbar-meta' );
			return el ? el.textContent.trim() : '';
		} );
		t.check( 'count label reflects the filtered total', new RegExp( '\\b' + shown + '\\b' ).test( meta ), `${ meta } vs ${ shown } rows` );

		// ---- Search still narrows, and rides alongside the chips ----
		await page.fill( '#minn-order-search', 'PF Featured ' + suffix );
		await waitForQuery( /[?&]search=/, 'search=' );
		await settle();
		t.check( 'search narrows within the active filters',
			await waitRows( [ made.featured ], [ made.sale ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'search and the category filter travelled together',
			/[?&]search=/.test( lastQuery() ) && new RegExp( '[?&]category=' + catId + '(&|$)' ).test( lastQuery() ),
			lastQuery().slice( -160 ) );
	} finally {
		for ( const id of Object.values( made ) ) {
			if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( catId ) await api( `wc/v3/products/categories/${ catId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( tagId ) await api( `wc/v3/products/tags/${ tagId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
