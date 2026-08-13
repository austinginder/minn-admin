/**
 * Wave 4 of the product page: the Images card. First entry is the product
 * image and the rest are the gallery, so ordering is the point: arrows and
 * drag reorder, × removes, a tile click replaces, Add images… appends.
 *
 * Fixtures: one product and three uploaded attachments, all removed after.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-images' );

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

	// Upload a solid-colour PNG straight from a canvas.
	const upload = ( name, colour ) => page.evaluate( async ( a ) => {
		const c = document.createElement( 'canvas' );
		c.width = 60; c.height = 40;
		const ctx = c.getContext( '2d' );
		ctx.fillStyle = a.colour; ctx.fillRect( 0, 0, 60, 40 );
		const blob = await new Promise( ( r ) => c.toBlob( r, 'image/png' ) );
		const fd = new FormData();
		fd.append( 'file', blob, a.name + '.png' );
		const res = await fetch( window.MINN.restUrl + 'wp/v2/media', {
			method: 'POST',
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: fd,
		} );
		const j = await res.json();
		return j.id || null;
	}, { name, colour } );

	const suffix = Date.now();
	let id = null;
	const media = [];
	try {
		for ( const [ n, c ] of [ [ 'minnimg-a', '#c0392b' ], [ 'minnimg-b', '#27ae60' ], [ 'minnimg-c', '#2980b9' ] ] ) {
			const mid = await upload( n + '-' + suffix, c );
			if ( mid ) media.push( mid );
		}
		t.check( 'three fixture images uploaded', media.length === 3, JSON.stringify( media ) );

		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Product images fixture ' + suffix,
				type: 'simple', regular_price: '9.00', status: 'publish',
				images: media.slice( 0, 2 ).map( ( mid ) => ( { id: mid } ) ),
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created with two images', !! id, String( made.status ) );
		if ( ! id || media.length < 3 ) {
			await t.done( browser, errors );
			return;
		}

		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-images', { timeout: 20000 } );

		const card = await page.evaluate( () => ( {
			titles: Array.from( document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			tiles: document.querySelectorAll( '#minn-p-images [data-pimg]' ).length,
			badge: ( ( document.querySelector( '#minn-p-images .minn-imgedit-new' ) || {} ).textContent || '' ).trim(),
			firstBadged: !! document.querySelector( '#minn-p-images [data-pimg="0"] .minn-imgedit-new' ),
			secondBadged: !! document.querySelector( '#minn-p-images [data-pimg="1"] .minn-imgedit-new' ),
			add: !! document.querySelector( '#minn-p-img-add' ),
		} ) );
		t.check( 'Images card is on the page', card.titles.includes( 'Images' ), JSON.stringify( card.titles ) );
		t.check( 'both images render as tiles', card.tiles === 2, String( card.tiles ) );
		t.check( 'only the first tile is marked the product image',
			card.firstBadged && ! card.secondBadged && /Product image/.test( card.badge ), JSON.stringify( card ) );
		t.check( 'card offers Add images', card.add, '' );

		// Order carries meaning: promoting the second tile changes which
		// picture WooCommerce treats as the product image.
		const orderBefore = await page.evaluate( () => Array.from(
			document.querySelectorAll( '#minn-p-images [data-pimg] img' ) ).map( ( i ) => i.src ) );
		await page.click( '#minn-p-images [data-pimgmv="1:-1"]' );
		await page.waitForTimeout( 200 );
		const orderAfter = await page.evaluate( () => Array.from(
			document.querySelectorAll( '#minn-p-images [data-pimg] img' ) ).map( ( i ) => i.src ) );
		t.check( 'the move-earlier arrow swaps the tiles',
			orderAfter[ 0 ] === orderBefore[ 1 ] && orderAfter[ 1 ] === orderBefore[ 0 ],
			JSON.stringify( { orderBefore, orderAfter } ) );

		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 600 );
		const reordered = await api( `wc/v3/products/${ id }?_fields=id,images` );
		const savedIds = ( ( reordered.body || {} ).images || [] ).map( ( x ) => x.id );
		t.check( 'reordering saves, and image two is now the product image',
			savedIds[ 0 ] === media[ 1 ] && savedIds[ 1 ] === media[ 0 ], JSON.stringify( savedIds ) );

		// Add a third through the media picker.
		await page.click( '#minn-p-img-add' );
		await page.waitForSelector( '.minn-modal-overlay', { timeout: 15000 } );
		// Picker tiles are keyed by array index, so find the one by its
		// attachment title (WordPress names it after the uploaded file).
		await page.waitForSelector( '.minn-picker-item', { timeout: 15000 } ).catch( () => null );
		const addedInPicker = await page.evaluate( ( name ) => {
			const el = Array.from( document.querySelectorAll( '.minn-picker-item' ) )
				.find( ( x ) => ( x.getAttribute( 'title' ) || '' ).startsWith( name ) );
			if ( ! el ) return false;
			el.click();
			return true;
		}, 'minnimg-c-' + suffix );
		if ( addedInPicker ) {
			await page.click( '#minn-picker-done' );
			await page.waitForTimeout( 400 );
			const tiles = await page.evaluate( () => document.querySelectorAll( '#minn-p-images [data-pimg]' ).length );
			t.check( 'the picker appends a third tile', tiles === 3, String( tiles ) );
			await page.click( '#minn-product-save' );
			await page.waitForFunction( () => {
				const b = document.querySelector( '#minn-product-save' );
				return b && ! b.disabled && /Save/.test( b.textContent );
			}, null, { timeout: 20000 } ).catch( () => null );
			await page.waitForTimeout( 600 );
			const three = await api( `wc/v3/products/${ id }?_fields=id,images` );
			t.check( 'the added image saves into the gallery',
				( ( three.body || {} ).images || [] ).map( ( x ) => x.id ).includes( media[ 2 ] ),
				JSON.stringify( ( ( three.body || {} ).images || [] ).map( ( x ) => x.id ) ) );
		} else {
			t.check( 'the picker appends a third tile', false, 'picker row not found' );
			t.check( 'the added image saves into the gallery', false, 'picker row not found' );
		}

		// × removes a tile, and saving really drops it.
		await page.click( '#minn-p-images [data-pimgx="0"]' );
		await page.waitForTimeout( 200 );
		const afterX = await page.evaluate( () => document.querySelectorAll( '#minn-p-images [data-pimg]' ).length );
		t.check( 'the × removes a tile', afterX === 2, String( afterX ) );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 600 );
		const removed = await api( `wc/v3/products/${ id }?_fields=id,images` );
		t.check( 'removing a tile drops that image on save',
			( ( removed.body || {} ).images || [] ).length === 2
			&& ! ( ( removed.body || {} ).images || [] ).map( ( x ) => x.id ).includes( media[ 1 ] ),
			JSON.stringify( ( ( removed.body || {} ).images || [] ).map( ( x ) => x.id ) ) );

		// The list row's thumbnail follows the product image.
		await page.goto( BASE + '/minn-admin/products', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-product="${ id }"]`, { timeout: 20000 } ).catch( () => null );
		const rowImg = await page.evaluate( ( pid ) => {
			const row = document.querySelector( `.minn-table-row[data-product="${ pid }"] img` );
			return row ? row.src : '';
		}, id );
		t.check( 'the list row shows a thumbnail', /\.(png|jpe?g|webp)/i.test( rowImg ), rowImg );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		for ( const mid of media ) {
			await api( `wp/v2/media/${ mid }?force=true`, { method: 'DELETE' } ).catch( () => null );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
