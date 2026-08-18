/**
 * The orders list filter bar: a status dropdown, the search box and an
 * add-filter button on one row, with the active filters as chips beneath.
 * Filters cover status (multi), date range, customer and product.
 *
 * The standing rule this suite exists to protect: filtering is SERVER side.
 * Every check asserts both the rows on screen AND the query string Minn sent,
 * because a filter that narrows the DOM while the request stays unfiltered
 * lies about its counts and breaks the moment the list is paginated.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'order-filters' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	// Record every orders collection request so each filter can be proven to
	// have travelled to the server.
	const sent = [];
	page.on( 'request', ( r ) => {
		const u = r.url();
		if ( /\/wc\/v3\/orders\?/.test( u ) ) sent.push( decodeURIComponent( u ) );
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
	const email = `minn-filter-${ suffix }@example.com`;
	let p1 = null, p2 = null, custId = null, mediaId = null;
	const made = {};

	// Rows currently on screen, by order id.
	const visibleIds = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-table-row[data-order]' ) ).map( ( r ) => parseInt( r.dataset.order, 10 ) ) );
	const chipLabels = () => page.evaluate( () =>
		Array.from( document.querySelectorAll( '[data-ofchip]' ) ).map( ( c ) => c.textContent.replace( /\s+/g, ' ' ).trim() ) );
	// A filter change reloads the list; wait for the rows to settle on it.
	const waitRows = async ( has, hasNot ) => {
		try {
			await page.waitForFunction( ( a ) => {
				const ids = Array.from( document.querySelectorAll( '.minn-table-row[data-order]' ) ).map( ( r ) => parseInt( r.dataset.order, 10 ) );
				if ( document.querySelector( '#minn-view .minn-loading' ) ) return false;
				return a.has.every( ( id ) => ids.includes( id ) ) && a.hasNot.every( ( id ) => ! ids.includes( id ) );
			}, { has, hasNot }, { timeout: 15000 } );
			return true;
		} catch ( e ) {
			return false;
		}
	};
	const pickPreset = async ( slug ) => {
		await page.click( '#minn-order-preset' );
		await page.waitForSelector( `.minn-of-pop [data-opreset="${ slug }"]`, { timeout: 8000 } );
		await page.click( `.minn-of-pop [data-opreset="${ slug }"]` );
	};
	const presetLabel = () => page.evaluate( () => {
		const b = document.querySelector( '#minn-order-preset' );
		return b ? b.textContent.replace( /\s+/g, ' ' ).trim() : '';
	} );
	const openFilterMenu = async ( which ) => {
		await page.click( '#minn-order-addfilter' );
		await page.waitForSelector( `[data-offilter="${ which }"]`, { timeout: 8000 } );
		await page.click( `[data-offilter="${ which }"]` );
		await page.waitForSelector( '.minn-of-pop', { timeout: 8000 } );
	};

	try {
		const mkProduct = async ( name, price ) => {
			const r = await api( 'wc/v3/products', {
				method: 'POST',
				body: JSON.stringify( { name, type: 'simple', regular_price: price, status: 'publish' } ),
			} );
			return r.body && r.body.id;
		};
		p1 = await mkProduct( 'Filter Widget A ' + suffix, '10.00' );
		p2 = await mkProduct( 'Filter Widget B ' + suffix, '25.00' );
		// A real attachment on p2: the picker's thumbnail is only proven by a
		// product that actually has one.
		mediaId = await page.evaluate( async ( s ) => {
			const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
			const bin = atob( png );
			const bytes = new Uint8Array( bin.length );
			for ( let i = 0; i < bin.length; i++ ) bytes[ i ] = bin.charCodeAt( i );
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'image/png', 'Content-Disposition': `attachment; filename="filter-${ s }.png"` },
				credentials: 'same-origin',
				body: bytes,
			} );
			const j = await r.json();
			return j && j.id;
		}, suffix );
		if ( mediaId ) await api( `wc/v3/products/${ p2 }`, { method: 'PUT', body: JSON.stringify( { images: [ { id: mediaId } ] } ) } );

		const cust = await api( 'wc/v3/customers', {
			method: 'POST',
			body: JSON.stringify( { email, first_name: 'Filtra', last_name: 'Tester ' + suffix, username: 'filtra-' + suffix, password: 'filter-pass-' + suffix } ),
		} );
		custId = cust.body && cust.body.id;
		t.check( 'fixtures: products + customer created', !! ( p1 && p2 && custId ), JSON.stringify( { p1, p2, custId } ) );

		const mkOrder = async ( key, body ) => {
			const r = await api( 'wc/v3/orders', { method: 'POST', body: JSON.stringify( body ) } );
			made[ key ] = r.body && r.body.id;
			return made[ key ];
		};
		const billing = { first_name: 'Filtra', last_name: 'Tester', email, country: 'US' };
		// The matrix each filter is proven against.
		await mkOrder( 'processing', { status: 'processing', customer_id: custId, billing, line_items: [ { product_id: p1, quantity: 1 } ] } );
		await mkOrder( 'onhold', { status: 'on-hold', customer_id: custId, billing, line_items: [ { product_id: p1, quantity: 1 } ] } );
		await mkOrder( 'completed', { status: 'completed', customer_id: custId, billing, line_items: [ { product_id: p2, quantity: 1 } ] } );
		// A guest order (no customer id) and an old one, for the customer and
		// date filters to have something to exclude.
		await mkOrder( 'guest', { status: 'processing', billing: { first_name: 'Guest', last_name: 'Buyer', email: `guest-${ suffix }@example.com`, country: 'US' }, line_items: [ { product_id: p1, quantity: 1 } ] } );
		t.check( 'fixtures: four orders created', Object.values( made ).every( Boolean ), JSON.stringify( made ) );

		await page.setViewportSize( { width: 1440, height: 1000 } );
		await page.goto( `${ BASE }/minn-admin/orders`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-order]', { timeout: 25000 } );

		// ---- The Items column names the first item and reveals the rest ----
		const cell = await page.evaluate( ( id ) => {
			const row = document.querySelector( `.minn-table-row[data-order="${ id }"]` );
			const c = row && row.querySelector( '.minn-items-cell' );
			return { cell: !! c, text: c ? c.textContent.replace( /\s+/g, ' ' ).trim() : '' };
		}, made.completed );
		t.check( 'the items column names the product, not just a count',
			cell.cell && /Filter Widget B/.test( cell.text ), JSON.stringify( cell ) );
		await page.click( `.minn-table-row[data-order="${ made.completed }"] .minn-items-cell` );
		await page.waitForSelector( '.minn-items-pop', { timeout: 8000 } );
		const pop = await page.evaluate( () => {
			const p = document.querySelector( '.minn-items-pop' );
			return { open: !! p, text: p ? p.textContent.replace( /\s+/g, ' ' ) : '', onPage: location.pathname };
		} );
		t.check( 'clicking the items cell opens the list without navigating',
			pop.open && /Filter Widget B/.test( pop.text ) && ! /\/orders\/\d/.test( pop.onPage ), JSON.stringify( pop ) );
		await page.keyboard.press( 'Escape' );

		// ---- The quick-access strip writes the status filter ----
		await pickPreset( 'processing' );
		t.check( 'status dropdown filters to processing',
			await waitRows( [ made.processing ], [ made.completed, made.onhold ] ), JSON.stringify( await visibleIds() ) );
		await waitForQuery( /status(\[\]|)=processing/, 'status=processing' );
		t.check( 'status dropdown sent status to the server', /status(\[\]|)=processing/.test( lastQuery() ), lastQuery().slice( 0, 160 ) );
		t.check( 'the dropdown names the active status', /Processing/i.test( await presetLabel() ), await presetLabel() );

		// Shopify's bar order: status on the left, search in the middle, the
		// filter button on the right, all on one row.
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
				// The status tabs are gone from the page (they live in the
				// dropdown now). The Orders/Analytics switch keeps its own strip.
				tabsGone: ! document.querySelector( '[data-opreset]' ),
			};
		} );
		t.check( 'one row: status, search, add filter, in that order',
			barOrder.preset < barOrder.search && barOrder.search < barOrder.add && barOrder.sameRow && barOrder.tabsGone,
			JSON.stringify( barOrder ) );

		// ---- Status chip: multi-select, which the old tabs could not do ----
		await pickPreset( 'any' );
		await waitRows( [ made.completed ], [] );
		await openFilterMenu( 'status' );
		await page.click( '.minn-of-pop [data-ofval="processing"]' );
		await page.click( '.minn-of-pop [data-ofval="on-hold"]' );
		await page.click( '.minn-of-pop [data-ofapply]' );
		t.check( 'status chip filters to both statuses',
			await waitRows( [ made.processing, made.onhold ], [ made.completed ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'both statuses travelled to the server',
			/status(\[\]|)=processing/.test( lastQuery() ) && /status(\[\]|)=on-hold/.test( lastQuery() ), lastQuery().slice( 0, 160 ) );
		const chips = await chipLabels();
		t.check( 'chip names the filter and its values', chips.some( ( c ) => /Status/i.test( c ) && /Processing/i.test( c ) ), JSON.stringify( chips ) );

		// ---- Removing the chip restores the excluded order ----
		await page.click( '[data-ofchip] [data-ofremove]' );
		t.check( 'removing the chip restores the list',
			await waitRows( [ made.completed, made.processing ], [] ), JSON.stringify( await visibleIds() ) );

		// ---- Date range ----
		// WooCommerce refuses to backdate an order through REST (proven on this
		// lab: date_created is ignored on both create and update, the stored
		// date is always now), so no fixture here can be older than today and
		// an "excludes the old order" check would pass vacuously. What IS ours
		// to test is the boundary math and that the list mirrors the server for
		// that window; WooCommerce's own date filtering is its business.
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		const from = new Date( Date.now() - 29 * 86400000 );
		const expected = `after=${ from.getFullYear() }-${ pad( from.getMonth() + 1 ) }-${ pad( from.getDate() ) }T00:00:00`;
		await openFilterMenu( 'date' );
		const dateResponse = page.waitForResponse( ( res ) =>
			res.request().method() === 'GET' && /\/wc\/v3\/orders\?/.test( res.url() )
			&& decodeURIComponent( res.url() ).includes( expected ), { timeout: 20000 } );
		await page.click( '.minn-of-pop [data-ofval="30"]' );
		const dateBody = await ( await dateResponse ).json();
		await waitForQuery( /[?&]after=/, 'after=' );
		t.check( 'date preset asks for the right window', lastQuery().indexOf( expected ) !== -1, `${ expected } vs ${ lastQuery().slice( -80 ) }` );
		const serverList = ( Array.isArray( dateBody ) ? dateBody : [] ).map( ( o ) => o.id );
		await page.waitForFunction( ( ids ) => {
			if ( document.querySelector( '#minn-view .minn-loading' ) ) return false;
			const shown = Array.from( document.querySelectorAll( '.minn-table-row[data-order]' ) )
				.map( ( r ) => parseInt( r.dataset.order, 10 ) );
			return shown.length === ids.length && ids.every( ( id ) => shown.includes( id ) );
		}, serverList, { timeout: 20000 } );
		const uiIds = await visibleIds();
		t.check( 'the list mirrors the server for that window',
			serverList.length === uiIds.length && serverList.every( ( id ) => uiIds.includes( id ) ),
			JSON.stringify( { server: serverList.slice( 0, 8 ), ui: uiIds.slice( 0, 8 ) } ) );

		// ---- Customer: the guest order drops out ----
		await openFilterMenu( 'customer' );
		await page.fill( '.minn-of-pop .minn-ac-input', 'Filtra' );
		await page.waitForSelector( '.minn-of-pop .minn-ac-item', { timeout: 10000 } );
		await page.click( '.minn-of-pop .minn-ac-item' );
		t.check( 'customer filter drops the guest order',
			await waitRows( [ made.processing ], [ made.guest ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'customer id travelled to the server', new RegExp( '[?&]customer=' + custId + '(&|$)' ).test( lastQuery() ), lastQuery().slice( 0, 200 ) );

		// ---- Clear all wipes every chip and the query ----
		await page.click( '#minn-order-clearfilters' );
		t.check( 'clear all removes every chip', ( await chipLabels() ).length === 0, JSON.stringify( await chipLabels() ) );
		await waitRows( [ made.completed, made.guest ], [] );
		t.check( 'cleared query carries no filter params',
			! /[?&](customer|after|before|product)=/.test( lastQuery() ), lastQuery().slice( 0, 200 ) );

		// ---- Product filter, on its own ----
		await openFilterMenu( 'product' );
		await page.fill( '.minn-of-pop .minn-ac-input', 'Filter Widget B ' + suffix );
		// The lookup is a round trip: say so while it runs.
		await page.waitForSelector( '.minn-of-pop .minn-of-searching', { timeout: 8000 } );
		t.check( 'the picker shows it is searching', true, '' );
		await page.waitForSelector( '.minn-of-pop .minn-ac-item', { timeout: 10000 } );
		t.check( 'the searching state clears once hits land',
			await page.evaluate( () => ! document.querySelector( '.minn-of-searching' ) ), '' );
		const rowShape = await page.evaluate( () => {
			const item = document.querySelector( '.minn-of-pop .minn-ac-item' );
			const thumb = item && item.querySelector( '.minn-of-thumb' );
			const img = thumb && thumb.querySelector( 'img' );
			const box = thumb ? thumb.getBoundingClientRect() : { width: 0, height: 0 };
			return { thumbSlot: !! thumb, src: img ? img.getAttribute( 'src' ) : '', w: Math.round( box.width ), h: Math.round( box.height ) };
		} );
		t.check( 'product rows carry the product image',
			rowShape.thumbSlot && /\.(png|jpe?g|gif|webp)/i.test( rowShape.src ), JSON.stringify( rowShape ) );
		// The shared button.minn-ac-item rule sets display:block; losing to it
		// left the thumb inline, where width and height are ignored (it
		// measured 2px wide on screen while this check did not exist).
		t.check( 'the thumbnail is actually laid out, not inline',
			rowShape.w >= 20 && rowShape.h >= 20, JSON.stringify( rowShape ) );
		await page.click( '.minn-of-pop .minn-ac-item' );
		t.check( 'product filter keeps only orders containing it',
			await waitRows( [ made.completed ], [ made.processing, made.guest ] ), JSON.stringify( await visibleIds() ) );
		t.check( 'product id travelled to the server', new RegExp( '[?&]product=' + p2 + '(&|$)' ).test( lastQuery() ), lastQuery().slice( 0, 200 ) );

		// ---- Filters live in the URL, so a reload restores them ----
		let url = new URL( await page.evaluate( () => location.href ) );
		t.check( 'the product filter is in the URL', url.searchParams.get( 'product' ) === String( p2 ), url.search );

		await page.click( '#minn-order-clearfilters' );
		await waitRows( [ made.completed, made.guest ], [] );
		await pickPreset( 'processing' );
		await waitRows( [ made.processing ], [ made.completed ] );
		await openFilterMenu( 'customer' );
		await page.fill( '.minn-of-pop .minn-ac-input', 'Filtra' );
		await page.waitForSelector( '.minn-of-pop .minn-ac-item', { timeout: 10000 } );
		await page.click( '.minn-of-pop .minn-ac-item' );
		await waitRows( [ made.processing ], [ made.guest ] );
		url = new URL( await page.evaluate( () => location.href ) );
		t.check( 'status and customer both reach the URL',
			url.searchParams.get( 'status' ) === 'processing' && url.searchParams.get( 'customer' ) === String( custId ),
			url.search );

		const beforeReload = await visibleIds();
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-order]', { timeout: 25000 } );
		t.check( 'a reload restores the filtered rows',
			await waitRows( [ made.processing ], [ made.guest, made.completed ] ),
			JSON.stringify( { before: beforeReload, after: await visibleIds() } ) );
		t.check( 'a reload restores the chips and the dropdown label',
			( await chipLabels() ).some( ( c ) => /Customer/i.test( c ) ) && /Processing/i.test( await presetLabel() ),
			JSON.stringify( { chips: await chipLabels(), preset: await presetLabel() } ) );
		// The URL can only carry the id; the name arrives on its own request.
		const nameResolved = await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '[data-ofchip]' ) ).some( ( c ) => /Filtra/i.test( c.textContent ) ),
			null, { timeout: 10000 } ).then( () => true ).catch( () => false );
		t.check( 'the restored customer chip resolves its name, not its id',
			nameResolved, JSON.stringify( await chipLabels() ) );
		t.check( 'the reloaded request carried the filters',
			/status(\[\]|)=processing/.test( lastQuery() ) && new RegExp( '[?&]customer=' + custId + '(&|$)' ).test( lastQuery() ),
			lastQuery().slice( -90 ) );

		// A hand-edited URL is untrusted input like any other.
		await page.goto( `${ BASE }/minn-admin/orders?status=not-a-status&customer=abc`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[data-order]', { timeout: 25000 } );
		t.check( 'junk in the URL is ignored, not sent on',
			! /status(\[\]|)=not-a-status/.test( lastQuery() ) && ! /customer=abc/.test( lastQuery() ),
			lastQuery().slice( -90 ) );
		t.check( 'junk in the URL leaves no chips', ( await chipLabels() ).length === 0, JSON.stringify( await chipLabels() ) );

		await page.click( '#minn-order-clearfilters' ).catch( () => {} );

		// ---- A filter change always returns to page one ----
		const pageOne = /[?&]page=1(&|$)/.test( lastQuery() );
		t.check( 'a filter change asks for page 1', pageOne, lastQuery().slice( 0, 200 ) );

		// ---- The count label follows the filtered total ----
		const shown = ( await visibleIds() ).length;
		const meta = await page.evaluate( () => {
			const el = document.querySelector( '.minn-toolbar-meta' );
			return el ? el.textContent.trim() : '';
		} );
		t.check( 'count label reflects the filtered total', new RegExp( '\\b' + shown + '\\b' ).test( meta ), `${ meta } vs ${ shown } rows` );
	} finally {
		for ( const id of Object.values( made ) ) {
			if ( id ) await api( `wc/v3/orders/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( p1 ) await api( `wc/v3/products/${ p1 }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( p2 ) await api( `wc/v3/products/${ p2 }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( custId ) await api( `wc/v3/customers/${ custId }?force=true&reassign=0`, { method: 'DELETE' } ).catch( () => {} );
		if ( mediaId ) await api( `wp/v2/media/${ mediaId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
