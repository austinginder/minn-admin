/**
 * Wave 8 of the product page: the Attributes card. Custom attributes are
 * typed; store-wide (pa_*) ones are picked from what already exists, because
 * a global attribute cannot be created and used in the same request.
 *
 * A store-wide attribute's values are picked too: its field lists the terms
 * the attribute already has, and a typed value that is not one yet becomes a
 * term on save. The save refuses one with no values, which wc/v3 would drop
 * with a 200.
 *
 * Fixtures: one product and one typed term (both removed after), and the
 * standing "Size" store attribute on the dev site with at least two terms.
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
	const typedValue = 'Typed ' + suffix;
	let id = null;
	let globalAttr = null;
	try {
		const defs = await api( 'wc/v3/products/attributes?_fields=id,name' );
		globalAttr = ( defs.body || [] )[ 0 ] || null;
		t.check( 'the store has at least one global attribute', !! globalAttr, JSON.stringify( defs.body ) );
		const termsRes = globalAttr
			? await api( `wc/v3/products/attributes/${ globalAttr.id }/terms?per_page=100&_fields=id,name` )
			: { body: [] };
		const storeTerms = ( Array.isArray( termsRes.body ) ? termsRes.body : [] ).map( ( x ) => x.name );
		t.check( 'the global attribute has at least two terms', storeTerms.length >= 2, JSON.stringify( storeTerms ) );

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
			titles: Array.from( document.querySelectorAll( '.minn-order-sec .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
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

		// Add the store-wide attribute by picking it. Picking moves straight
		// on to its values, whose list holds the terms the store already has.
		let picked = [];
		if ( globalAttr ) {
			await page.click( '[data-pattrglobal] .minn-ac-input' );
			const item = `[data-pattrglobal] .minn-ac-item[data-acv="${ globalAttr.id }"]`;
			await page.waitForSelector( item, { timeout: 10000 } );
			await page.click( item );
			const globalRow = await page.evaluate( () => ( {
				readonlyName: !! document.querySelector( '.minn-pattr-row:nth-child(2) .minn-pattr-global' ),
				label: ( ( document.querySelector( '.minn-pattr-row:nth-child(2) .minn-pattr-global' ) || {} ).textContent || '' ).trim(),
				textValues: !! document.querySelector( '.minn-pattr-row:nth-child(2) [data-pattrvals]' ),
			} ) );
			t.check( 'a store-wide attribute shows its name as a label, not a field',
				globalRow.readonlyName, JSON.stringify( globalRow ) );
			t.check( 'a store-wide attribute has no free-text values box',
				! globalRow.textValues, JSON.stringify( globalRow ) );

			const rowsSel = '[data-pattrac="1"] .minn-ac-panel:not([hidden]) [data-pattrpick]';
			await page.waitForSelector( rowsSel, { timeout: 10000 } );
			const listed = await page.evaluate( () => ( {
				focused: !! ( document.activeElement && document.activeElement.closest( '[data-pattrac="1"]' ) ),
				names: Array.from( document.querySelectorAll( '[data-pattrac="1"] [data-pattrpick]' ) ).map( ( b ) => b.dataset.pattrpick ),
			} ) );
			t.check( 'picking a store-wide attribute focuses its values', listed.focused, JSON.stringify( listed ) );
			t.check( 'its values list the terms the store already has',
				storeTerms.every( ( n ) => listed.names.includes( n ) ), JSON.stringify( { listed: listed.names, storeTerms } ) );

			// Saving it with no values is refused before any request, and
			// the values field comes back with its list open.
			await page.keyboard.press( 'Escape' );
			let puts = 0;
			const onReq = ( r ) => { if ( r.method() === 'PUT' && r.url().includes( `/products/${ id }` ) ) puts++; };
			page.on( 'request', onReq );
			await page.click( '#minn-product-save' );
			await page.waitForTimeout( 700 );
			page.off( 'request', onReq );
			const refused = await page.evaluate( () => ( {
				toast: Array.from( document.querySelectorAll( '.minn-toast' ) ).map( ( e ) => e.textContent.trim() ).join( ' | ' ),
				open: !! document.querySelector( '[data-pattrac="1"] .minn-ac-panel:not([hidden]) [data-pattrpick]' ),
			} ) );
			t.check( 'a store-wide attribute with no values is not saved', puts === 0, String( puts ) );
			t.check( 'and the save says which attribute needs a value',
				refused.toast.includes( globalAttr.name ), JSON.stringify( refused ) );
			t.check( 'and its list stays open on the focused field', refused.open, JSON.stringify( refused ) );

			// Two existing terms by real clicks; the list stays open between.
			picked = storeTerms.slice( 0, 2 );
			for ( const n of picked ) {
				await page.click( `[data-pattrac="1"] [data-pattrpick="${ n }"]` );
				await page.waitForTimeout( 150 );
			}
			const ticked = await page.$$eval( '[data-pattrac="1"] [data-pattrpick][aria-selected="true"]', ( els ) => els.map( ( b ) => b.dataset.pattrpick ) );
			t.check( 'picked terms are ticked in the list', picked.every( ( n ) => ticked.includes( n ) ), JSON.stringify( ticked ) );

			// A typed value that matches a term in another case takes the
			// term's spelling and adds nothing new.
			await page.fill( '[data-pattrac="1"] .minn-ac-input', picked[ 0 ].toUpperCase() );
			await page.waitForTimeout( 600 );
			const dupOffer = !! await page.$( '[data-pattrac="1"] [data-pattradd]' );
			await page.keyboard.press( 'Enter' );
			// A new value is offered as one and added with Enter.
			await page.fill( '[data-pattrac="1"] .minn-ac-input', typedValue );
			await page.waitForSelector( '[data-pattrac="1"] [data-pattradd]', { timeout: 10000 } );
			await page.keyboard.press( 'Enter' );
			await page.waitForTimeout( 400 );
			const chips = await page.$$eval( '[data-pattrchips="1"] [data-pattrchip]', ( els ) => els.map( ( e ) => e.textContent.replace( '×', '' ).trim() ) );
			const spinning = await page.$eval( '[data-pattrac="1"]', ( w ) => w.classList.contains( 'is-loading' ) );
			t.check( 'an existing term typed in another case is not offered as new', ! dupOffer, '' );
			t.check( 'chips hold the picked terms and the typed value, once each',
				chips.length === 3 && picked.every( ( n ) => chips.includes( n ) ) && chips.includes( typedValue ),
				JSON.stringify( chips ) );
			t.check( 'the list does not keep spinning after a search is cleared', ! spinning, '' );
			await page.keyboard.press( 'Escape' );
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
				!! glob && glob.options.length === 3 && glob.options.includes( typedValue ), JSON.stringify( glob ) );
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
			chips: Array.from( document.querySelectorAll( '[data-pattrchip]' ) ).map( ( e ) => e.textContent.replace( '×', '' ).trim() ),
		} ) );
		t.check( 'attributes repopulate after reload',
			back.rows === 2 && back.names.includes( 'Material' )
			&& back.values.some( ( v ) => /Cotton/.test( v ) ), JSON.stringify( back ) );
		if ( globalAttr ) {
			t.check( 'the store-wide values come back as chips',
				back.chips.length === 3 && back.chips.includes( typedValue ), JSON.stringify( back.chips ) );
		}

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
		if ( globalAttr ) {
			const found = await api( `wc/v3/products/attributes/${ globalAttr.id }/terms?search=${ encodeURIComponent( typedValue ) }&_fields=id,name` ).catch( () => ( { body: [] } ) );
			for ( const x of ( Array.isArray( found.body ) ? found.body : [] ) ) {
				if ( x.name === typedValue ) await api( `wc/v3/products/attributes/${ globalAttr.id }/terms/${ x.id }?force=true`, { method: 'DELETE' } ).catch( () => null );
			}
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
