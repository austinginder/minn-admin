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
	const childCatName = 'Minn Parkas ' + suffix;
	const dialogTagName = 'minndialog' + suffix;
	let id = null;
	let catId = null;
	let childCatId = null;
	let dialogTagId = null;
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
			titles: Array.from( document.querySelectorAll( '.minn-order-sec .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			cats: !! document.querySelector( '[data-ptac="categories"]' ),
			tags: !! document.querySelector( '[data-ptac="tags"]' ),
			brands: !! document.querySelector( '[data-ptac="brands"]' ),
			slug: !! document.querySelector( '#minn-p-slug' ),
			featured: !! document.querySelector( '#minn-p-featured' ),
		} ) );
		t.check( 'Organization card is on the page', card.titles.includes( 'Organization' ), JSON.stringify( card.titles ) );
		t.check( 'card carries categories, tags, brands, slug and featured',
			card.cats && card.tags && card.brands && card.slug && card.featured, JSON.stringify( card ) );

		// The field opens into a list, the way Shopify's collections field
		// does: what exists is visible without guessing at a search term, and
		// each row says whether the product is in it.
		await page.click( '[data-ptac="categories"] .minn-ac-input' );
		await page.waitForSelector( '[data-ptac="categories"] [data-ptpick]', { timeout: 20000 } );
		const listed = await page.evaluate( ( name ) => {
			const rows = Array.from( document.querySelectorAll( '[data-ptac="categories"] [data-ptpick]' ) );
			const mine = rows.find( ( r ) => ( r.dataset.ptname || '' ) === name );
			return {
				count: rows.length,
				boxes: rows.filter( ( r ) => r.querySelector( '.minn-check' ) ).length,
				mine: !! mine,
				mineOn: !! mine && mine.getAttribute( 'aria-selected' ) === 'true',
				add: !! document.querySelector( '[data-ptac="categories"] [data-ptadd]' ),
			};
		}, catName );
		t.check( 'clicking the field lists the categories without typing',
			listed.count >= 1 && listed.mine, JSON.stringify( listed ) );
		t.check( 'every row carries a tick box, unticked when not assigned',
			listed.boxes === listed.count && ! listed.mineOn, JSON.stringify( listed ) );
		t.check( 'the list offers a way to add a term', listed.add, JSON.stringify( listed ) );

		const tickRow = ( name ) => page.evaluate( ( n ) => {
			const row = Array.from( document.querySelectorAll( '[data-ptac="categories"] [data-ptpick]' ) )
				.find( ( r ) => ( r.dataset.ptname || '' ) === n );
			if ( ! row ) return false;
			row.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
			return true;
		}, name );
		await tickRow( catName );
		await page.waitForTimeout( 250 );
		const afterTick = await page.evaluate( ( name ) => {
			const panel = document.querySelector( '[data-ptac="categories"] .minn-ac-panel' );
			const row = Array.from( document.querySelectorAll( '[data-ptac="categories"] [data-ptpick]' ) )
				.find( ( r ) => ( r.dataset.ptname || '' ) === name );
			return {
				open: !! panel && ! panel.hidden,
				on: !! row && row.getAttribute( 'aria-selected' ) === 'true',
				chips: Array.from( document.querySelectorAll( '[data-ptchips="categories"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ),
			};
		}, catName );
		t.check( 'ticking a row adds the chip and leaves the list open',
			afterTick.open && afterTick.on && afterTick.chips.some( ( c ) => c.includes( catName ) ),
			JSON.stringify( afterTick ) );
		await tickRow( catName );
		await page.waitForTimeout( 250 );
		const afterUntick = await page.evaluate( ( name ) => {
			const row = Array.from( document.querySelectorAll( '[data-ptac="categories"] [data-ptpick]' ) )
				.find( ( r ) => ( r.dataset.ptname || '' ) === name );
			return {
				on: !! row && row.getAttribute( 'aria-selected' ) === 'true',
				chips: Array.from( document.querySelectorAll( '[data-ptchips="categories"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ),
			};
		}, catName );
		t.check( 'ticking it again takes the product back out',
			! afterUntick.on && ! afterUntick.chips.some( ( c ) => c.includes( catName ) ),
			JSON.stringify( afterUntick ) );

		// Add new opens a dialog rather than doing something invisible. The
		// name is typed there, deliberately, which is what makes creating a
		// category safe enough to offer at all.
		const openAdd = async ( key ) => {
			await page.click( '#minn-p-name' );
			await page.waitForTimeout( 250 );
			await page.click( `[data-ptac="${ key }"] .minn-ac-input` );
			await page.waitForSelector( `[data-ptac="${ key }"] [data-ptadd]`, { timeout: 20000 } );
			// Wait for the list to settle before measuring: rows landing under
			// the footer move it, and a click aimed at where it used to be
			// hits a row instead.
			await page.waitForFunction(
				( k ) => ! document.querySelector( `[data-ptac="${ k }"]` ).classList.contains( 'is-loading' ),
				key, { timeout: 20000 } );
			await page.waitForTimeout( 150 );
			// A real click, scrolled into view first: the panel's foot can sit
			// below the fold, and raw mouse coordinates do not scroll. The row
			// is bound on mousedown (to survive the blur), so a dispatched
			// event would not prove a reader can reach it.
			await ( await page.$( `[data-ptac="${ key }"] [data-ptadd]` ) ).click();
			await page.waitForSelector( '#minn-term-dialog', { timeout: 15000 } );
		};

		await openAdd( 'categories' );
		const dialog = await page.evaluate( () => {
			const d = document.querySelector( '#minn-term-dialog' );
			return {
				name: !! document.querySelector( '#minn-term-name' ),
				parent: !! document.querySelector( '#minn-term-dialog [data-termparent]' ),
				create: !! document.querySelector( '#minn-term-dialog [data-term-create]' ),
				title: ( ( d.querySelector( '.minn-confirm-title' ) || {} ).textContent || '' ).trim(),
			};
		} );
		t.check( 'Add new opens a dialog with a name field',
			dialog.name && dialog.create, JSON.stringify( dialog ) );
		t.check( 'a hierarchy also offers a parent to file it under',
			dialog.parent, JSON.stringify( dialog ) );
		t.check( 'the dialog says which kind of term it makes',
			/categor/i.test( dialog.title ), dialog.title );

		// Escape leaves nothing behind.
		const catsBeforeCancel = ( ( await api( 'wc/v3/products/categories?per_page=100&_fields=id' ) ).body || [] ).length;
		await page.fill( '#minn-term-name', 'Abandoned ' + suffix );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 400 );
		const catsAfterCancel = ( ( await api( 'wc/v3/products/categories?per_page=100&_fields=id' ) ).body || [] ).length;
		t.check( 'cancelling the dialog creates nothing',
			! ( await page.$( '#minn-term-dialog' ) ) && catsAfterCancel === catsBeforeCancel,
			`${ catsBeforeCancel } -> ${ catsAfterCancel }` );

		// Create for real, under the fixture category as parent.
		await openAdd( 'categories' );
		await page.fill( '#minn-term-name', childCatName );
		await page.click( '#minn-term-dialog [data-termparent] .minn-ac-input' );
		await page.waitForSelector( '#minn-term-dialog [data-termparent] .minn-ac-item', { timeout: 20000 } );
		await page.evaluate( ( name ) => {
			const row = Array.from( document.querySelectorAll( '#minn-term-dialog [data-termparent] .minn-ac-item' ) )
				.find( ( r ) => ( r.textContent || '' ).trim() === name );
			if ( row ) row.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
		}, catName );
		await page.click( '#minn-term-dialog [data-term-create]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-term-dialog' ), null, { timeout: 20000 } );
		await page.waitForTimeout( 300 );
		const madeCat = ( ( await api( `wc/v3/products/categories?search=${ encodeURIComponent( childCatName ) }&per_page=10&_fields=id,name,parent` ) ).body || [] )[ 0 ];
		childCatId = madeCat && madeCat.id;
		t.check( 'the dialog creates the term in WooCommerce', !! childCatId, JSON.stringify( madeCat ) );
		t.check( 'and files it under the parent that was chosen',
			!! madeCat && madeCat.parent === catId, JSON.stringify( madeCat ) );
		const afterCreate = await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-ptchips="categories"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ) );
		t.check( 'the new term lands on the product, already ticked',
			afterCreate.some( ( c ) => c.includes( childCatName ) ), JSON.stringify( afterCreate ) );

		// The same door for a flat taxonomy, minus the parent.
		await openAdd( 'tags' );
		const flatDialog = await page.evaluate( () => ( {
			name: !! document.querySelector( '#minn-term-name' ),
			parent: !! document.querySelector( '#minn-term-dialog [data-termparent]' ),
		} ) );
		t.check( 'a flat taxonomy gets the dialog without a parent',
			flatDialog.name && ! flatDialog.parent, JSON.stringify( flatDialog ) );
		await page.fill( '#minn-term-name', dialogTagName );
		await page.click( '#minn-term-dialog [data-term-create]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-term-dialog' ), null, { timeout: 20000 } );
		await page.waitForTimeout( 300 );
		const tagChipsAfter = await page.evaluate( () => Array.from(
			document.querySelectorAll( '[data-ptchips="tags"] [data-ptchip]' ) ).map( ( c ) => c.textContent.trim() ) );
		t.check( 'a tag made in the dialog lands on the product',
			tagChipsAfter.some( ( c ) => c.includes( dialogTagName ) ), JSON.stringify( tagChipsAfter ) );
		await page.click( '#minn-p-name' );
		await page.waitForTimeout( 200 );

		// Anything that goes to the server says so while it is going. Delayed
		// on purpose: a local request answers too fast to observe otherwise.
		await page.route( '**/wc/v3/products/categories**', async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, 1200 ) );
			await route.continue();
		} );
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-slug', { timeout: 20000 } );
		await page.click( '[data-ptac="categories"] .minn-ac-input' );
		await page.waitForTimeout( 300 );
		const busy = await page.evaluate( () => {
			const w = document.querySelector( '[data-ptac="categories"]' );
			return { loading: !! w && w.classList.contains( 'is-loading' ) };
		} );
		t.check( 'a field waiting on the server shows it is working', busy.loading, JSON.stringify( busy ) );
		await page.waitForSelector( '[data-ptac="categories"] [data-ptpick]', { timeout: 20000 } );
		const settled = await page.evaluate( () => {
			const w = document.querySelector( '[data-ptac="categories"]' );
			return !! w && w.classList.contains( 'is-loading' );
		} );
		t.check( 'and stops showing it once the answer lands', ! settled, String( settled ) );
		await page.unroute( '**/wc/v3/products/categories**' );
		await page.click( '#minn-p-name' );
		await page.waitForTimeout( 200 );

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
		// The child goes before its parent: deleting a parent only reparents.
		if ( childCatId ) await api( `wc/v3/products/categories/${ childCatId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( catId ) await api( `wc/v3/products/categories/${ catId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( madeTagId ) await api( `wc/v3/products/tags/${ madeTagId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( madeBrandId ) await api( `wc/v3/products/brands/${ madeBrandId }?force=true`, { method: 'DELETE' } ).catch( () => null );
		if ( ! dialogTagId ) {
			const found = ( await api( `wc/v3/products/tags?search=${ encodeURIComponent( dialogTagName ) }&per_page=5&_fields=id` ) ).body;
			dialogTagId = ( Array.isArray( found ) ? found : [] ).map( ( x ) => x.id )[ 0 ];
		}
		if ( dialogTagId ) await api( `wc/v3/products/tags/${ dialogTagId }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
