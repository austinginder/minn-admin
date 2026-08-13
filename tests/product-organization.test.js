/**
 * Wave 3 of the product page: the Organization card. Categories (pick-only),
 * tags and brands (pick or create with Enter), slug and featured.
 *
 * Fixtures: one product plus one product category, created and removed over
 * REST. The tag and brand the suite creates through the UI are deleted too.
 */
const { BASE, launch, login, reporter, setSwitch } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-organization' );

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
	const catName = 'Minn Outerwear ' + suffix;
	const tagName = 'minnwave' + suffix;
	const brandName = 'Minnbrand ' + suffix;
	let id = null;
	let catId = null;
	let madeTagId = null;
	let madeBrandId = null;
	try {
		const cat = await api( 'wc/v3/products/categories', {
			method: 'POST', body: JSON.stringify( { name: catName } ),
		} );
		catId = cat.body && cat.body.id;
		t.check( 'fixture category created', !! catId, String( cat.status ) );

		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Product org fixture ' + suffix,
				type: 'simple', regular_price: '8.00', status: 'publish',
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created', !! id, String( made.status ) );
		if ( ! id ) {
			await t.done( browser, errors );
			return;
		}

		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-slug', { timeout: 20000 } );

		const card = await page.evaluate( () => ( {
			titles: Array.from( document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			cats: !! document.querySelector( '[data-ptac="categories"]' ),
			tags: !! document.querySelector( '[data-ptac="tags"]' ),
			brands: !! document.querySelector( '[data-ptac="brands"]' ),
			slug: !! document.querySelector( '#minn-p-slug' ),
			featured: !! document.querySelector( '#minn-p-featured' ),
		} ) );
		t.check( 'Organization card is on the page', card.titles.includes( 'Organization' ), JSON.stringify( card.titles ) );
		t.check( 'card carries categories, tags, brands, slug and featured',
			card.cats && card.tags && card.brands && card.slug && card.featured, JSON.stringify( card ) );

		// Categories: type, pick from the suggest panel.
		await page.fill( '[data-ptac="categories"] .minn-ac-input', 'Minn Outerwear' );
		await page.waitForSelector( '[data-ptac="categories"] [data-ptpick]', { timeout: 15000 } );
		const picked = await page.evaluate( () => {
			const b = document.querySelector( '[data-ptac="categories"] [data-ptpick]' );
			const ev = new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } );
			b.dispatchEvent( ev );
			return b.dataset.ptname;
		} );
		t.check( 'suggest panel offers the category', /Minn Outerwear/.test( picked || '' ), String( picked ) );
		await page.waitForTimeout( 200 );
		const catChips = await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-ptchips="categories"] [data-ptchip]' )
		).map( ( c ) => c.textContent.trim() ) );
		t.check( 'picking a category adds a chip',
			catChips.some( ( c ) => c.includes( 'Minn Outerwear' ) ), JSON.stringify( catChips ) );

		// Categories are pick-only: Enter must not create a term.
		const beforeCats = ( await api( 'wc/v3/products/categories?per_page=100&_fields=id' ) ).body || [];
		await page.fill( '[data-ptac="categories"] .minn-ac-input', 'Definitely Not A Category ' + suffix );
		await page.press( '[data-ptac="categories"] .minn-ac-input', 'Enter' );
		await page.waitForTimeout( 700 );
		const afterCats = ( await api( 'wc/v3/products/categories?per_page=100&_fields=id' ) ).body || [];
		t.check( 'Enter does not create a category',
			afterCats.length === beforeCats.length, `${ beforeCats.length } -> ${ afterCats.length }` );

		// Tags and brands: Enter creates.
		await page.fill( '[data-ptac="tags"] .minn-ac-input', tagName );
		await page.press( '[data-ptac="tags"] .minn-ac-input', 'Enter' );
		await page.waitForFunction( () => document.querySelector( '[data-ptchips="tags"] [data-ptchip]' ), null, { timeout: 20000 } ).catch( () => null );
		const tagChips = await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-ptchips="tags"] [data-ptchip]' )
		).map( ( c ) => c.textContent.trim() ) );
		t.check( 'Enter creates and adds a tag',
			tagChips.some( ( c ) => c.includes( tagName ) ), JSON.stringify( tagChips ) );

		await page.fill( '[data-ptac="brands"] .minn-ac-input', brandName );
		await page.press( '[data-ptac="brands"] .minn-ac-input', 'Enter' );
		await page.waitForFunction( () => document.querySelector( '[data-ptchips="brands"] [data-ptchip]' ), null, { timeout: 20000 } ).catch( () => null );
		const brandChips = await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-ptchips="brands"] [data-ptchip]' )
		).map( ( c ) => c.textContent.trim() ) );
		t.check( 'Enter creates and adds a brand',
			brandChips.some( ( c ) => c.includes( brandName ) ), JSON.stringify( brandChips ) );

		// Slug and featured, then one save for the whole card.
		await page.fill( '#minn-p-slug', 'minn-org-fixture-' + suffix );
		await setSwitch( page, '#minn-p-featured', true );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 600 );

		const saved = await api( `wc/v3/products/${ id }?_fields=id,slug,featured,categories,tags,brands` );
		const b = saved.body || {};
		madeTagId = ( b.tags || [] )[ 0 ] && b.tags[ 0 ].id;
		madeBrandId = ( b.brands || [] )[ 0 ] && b.brands[ 0 ].id;
		t.check( 'category saved', ( b.categories || [] ).some( ( c ) => c.id === catId ), JSON.stringify( b.categories ) );
		t.check( 'tag saved', ( b.tags || [] ).some( ( x ) => x.name === tagName ), JSON.stringify( b.tags ) );
		t.check( 'brand saved', ( b.brands || [] ).some( ( x ) => x.name === brandName ), JSON.stringify( b.brands ) );
		t.check( 'slug saved', b.slug === 'minn-org-fixture-' + suffix, String( b.slug ) );
		t.check( 'featured saved', b.featured === true, String( b.featured ) );

		// Reload: the picks come back from the server, not from memory.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-slug', { timeout: 20000 } );
		const back = await page.evaluate( () => ( {
			cats: Array.from( document.querySelectorAll( '[data-ptchips="categories"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ),
			tags: Array.from( document.querySelectorAll( '[data-ptchips="tags"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ),
			slug: document.querySelector( '#minn-p-slug' ).value,
			featured: document.querySelector( '#minn-p-featured' ).classList.contains( 'on' ),
		} ) );
		t.check( 'organization repopulates after reload',
			back.cats.some( ( c ) => c.includes( 'Minn Outerwear' ) )
			&& back.tags.some( ( c ) => c.includes( tagName ) )
			&& back.slug === 'minn-org-fixture-' + suffix && back.featured === true,
			JSON.stringify( back ) );

		// Removing a chip and saving really unassigns the term.
		await page.evaluate( () => document.querySelector( '[data-ptchips="tags"] [data-ptchip]' ).click() );
		await page.waitForTimeout( 200 );
		const afterRemove = await page.evaluate( () => document.querySelectorAll( '[data-ptchips="tags"] [data-ptchip]' ).length );
		t.check( 'clicking a chip removes it', afterRemove === 0, String( afterRemove ) );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const btn = document.querySelector( '#minn-product-save' );
			return btn && ! btn.disabled && /Save/.test( btn.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 600 );
		const unassigned = await api( `wc/v3/products/${ id }?_fields=id,tags,categories` );
		t.check( 'removing a tag chip unassigns it on save',
			( ( unassigned.body || {} ).tags || [] ).length === 0, JSON.stringify( ( unassigned.body || {} ).tags ) );
		t.check( 'removing a tag leaves categories alone',
			( ( unassigned.body || {} ).categories || [] ).some( ( c ) => c.id === catId ),
			JSON.stringify( ( unassigned.body || {} ).categories ) );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( catId ) await api( `wc/v3/products/categories/${ catId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( madeTagId ) await api( `wc/v3/products/tags/${ madeTagId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( madeBrandId ) await api( `wc/v3/products/brands/${ madeBrandId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
