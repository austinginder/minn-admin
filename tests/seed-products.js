/* Lab seeder (not a suite): a catalogue that exercises every dimension the
 * products filter bar offers, so the bar can be walked by hand rather than
 * only asserted. There is at least one product per status (published, draft,
 * private, pending), per stock state (in stock, out of stock, on backorder,
 * low), per type (simple, variable, grouped, external), plus featured and
 * on-sale products, spread across three categories and three tags.
 *
 * It leaves what it creates behind on purpose, which is the opposite of a
 * suite's contract: point it at a throwaway lab, never a real site. */
const { launch, login } = require( './helpers' );

( async () => {
	const { browser, page } = await launch();
	await login( page );
	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const b = await r.json();
		if ( ! r.ok ) throw new Error( a.path + ' -> ' + r.status + ' ' + JSON.stringify( b ).slice( 0, 200 ) );
		return b;
	}, { path, opts } );

	// The store's own low-stock threshold decides what "Low stock" means, so
	// the low fixture is seeded relative to it rather than to a guessed 2.
	const threshold = await page.evaluate( () => Number( window.MINN.wcLowStock ) || 2 );

	const term = async ( kind, name ) => {
		const existing = await api( `wc/v3/products/${ kind }?search=${ encodeURIComponent( name ) }&per_page=1&_fields=id,name` );
		if ( existing[ 0 ] && existing[ 0 ].name === name ) return existing[ 0 ].id;
		return ( await api( `wc/v3/products/${ kind }`, { method: 'POST', body: JSON.stringify( { name } ) } ) ).id;
	};
	const cat = {
		apparel: await term( 'categories', 'Apparel' ),
		home: await term( 'categories', 'Home' ),
		outdoors: await term( 'categories', 'Outdoors' ),
	};
	const tag = {
		bestseller: await term( 'tags', 'bestseller' ),
		fresh: await term( 'tags', 'new' ),
		clearance: await term( 'tags', 'clearance' ),
	};

	const mk = ( body ) => api( 'wc/v3/products', { method: 'POST', body: JSON.stringify( body ) } );
	const made = [];
	const add = ( label, p ) => { made.push( [ label, p.id, p.name ] ); return p; };

	// --- Published, in stock, featured, categorised and tagged ---
	add( 'featured · in stock', await mk( {
		name: 'Trailhead Backpack', type: 'simple', status: 'publish',
		sku: 'TRL-BACKPACK', regular_price: '89.00', featured: true,
		stock_status: 'instock', categories: [ { id: cat.outdoors } ], tags: [ { id: tag.bestseller } ],
		short_description: 'Thirty litres, one pocket that matters.',
	} ) );

	// --- On sale ---
	add( 'on sale', await mk( {
		name: 'Merino Base Layer', type: 'simple', status: 'publish',
		sku: 'MER-BASE-M', regular_price: '70.00', sale_price: '49.00',
		stock_status: 'instock', categories: [ { id: cat.apparel } ], tags: [ { id: tag.fresh } ],
	} ) );

	// --- Out of stock ---
	add( 'out of stock', await mk( {
		name: 'Cast Iron Skillet', type: 'simple', status: 'publish',
		sku: 'CST-SKILLET-10', regular_price: '45.00',
		stock_status: 'outofstock', categories: [ { id: cat.home } ], tags: [ { id: tag.bestseller } ],
	} ) );

	// --- On backorder ---
	add( 'on backorder', await mk( {
		name: 'Enamel Camp Mug', type: 'simple', status: 'publish',
		sku: 'ENM-MUG', regular_price: '18.00',
		stock_status: 'onbackorder', backorders: 'notify',
		categories: [ { id: cat.outdoors }, { id: cat.home } ],
	} ) );

	// --- Low stock: managed quantity at the store's own threshold ---
	add( 'low stock', await mk( {
		name: 'Field Notebook (3-pack)', type: 'simple', status: 'publish',
		sku: 'FLD-NOTE-3', regular_price: '12.00',
		manage_stock: true, stock_quantity: Math.max( 1, threshold ), stock_status: 'instock',
		categories: [ { id: cat.outdoors } ], tags: [ { id: tag.clearance } ],
	} ) );

	// --- Variable, with real variations behind it ---
	const jacket = add( 'variable', await mk( {
		name: 'Alpine Shell Jacket', type: 'variable', status: 'publish',
		sku: 'ALP-SHELL', categories: [ { id: cat.apparel } ], tags: [ { id: tag.fresh } ],
		attributes: [ { name: 'Size', visible: true, variation: true, options: [ 'S', 'M', 'L' ] } ],
	} ) );
	for ( const [ size, price ] of [ [ 'S', '210.00' ], [ 'M', '210.00' ], [ 'L', '225.00' ] ] ) {
		await api( `wc/v3/products/${ jacket.id }/variations`, {
			method: 'POST',
			body: JSON.stringify( {
				regular_price: price, sku: `ALP-SHELL-${ size }`,
				attributes: [ { name: 'Size', option: size } ],
			} ),
		} );
	}

	// --- Grouped, pointing at two of the simple products above ---
	add( 'grouped', await mk( {
		name: 'Starter Kitchen Bundle', type: 'grouped', status: 'publish',
		categories: [ { id: cat.home } ],
		grouped_products: [ made[ 2 ][ 1 ], made[ 3 ][ 1 ] ],
	} ) );

	// --- External / affiliate ---
	add( 'external', await mk( {
		name: 'Field Guide to Knots (paperback)', type: 'external', status: 'publish',
		regular_price: '24.00', external_url: 'https://example.com/field-guide-to-knots',
		button_text: 'Buy on example.com', categories: [ { id: cat.outdoors } ],
	} ) );

	// --- The non-published statuses ---
	add( 'draft', await mk( {
		name: 'Winter Clearance Parka', type: 'simple', status: 'draft',
		sku: 'WNT-PARKA', regular_price: '180.00', sale_price: '119.00',
		categories: [ { id: cat.apparel } ], tags: [ { id: tag.clearance } ],
	} ) );
	add( 'private · featured', await mk( {
		name: 'Staff Pick: Ceramic Pour-Over', type: 'simple', status: 'private',
		sku: 'STF-POUROVER', regular_price: '38.00', featured: true,
		categories: [ { id: cat.home } ],
	} ) );
	add( 'pending', await mk( {
		name: 'Supplier Sample: Titanium Spork', type: 'simple', status: 'pending',
		sku: 'SUP-SPORK', regular_price: '15.00',
		categories: [ { id: cat.outdoors } ],
	} ) );

	// --- A couple of coupons, so that bar has something to narrow too ---
	const coupons = [];
	for ( const body of [
		{ code: 'welcome10', discount_type: 'percent', amount: '10', status: 'publish', description: 'Ten percent off a first order' },
		{ code: 'freeship', discount_type: 'fixed_cart', amount: '0', status: 'publish', free_shipping: true, description: 'Shipping on us' },
		{ code: 'winter25', discount_type: 'percent', amount: '25', status: 'draft', description: 'Not live yet' },
	] ) {
		try {
			const c = await api( 'wc/v3/coupons', { method: 'POST', body: JSON.stringify( body ) } );
			coupons.push( [ c.code, c.id ] );
		} catch ( e ) {
			// A code that already exists is fine on a re-run.
		}
	}

	console.log( made.map( ( [ label, id, name ] ) =>
		`${ label.padEnd( 20 ) } ${ name }  →  /minn-admin/products/${ id }` ).join( '\n' ) );
	if ( coupons.length ) {
		console.log( '\ncoupons: ' + coupons.map( ( [ code, id ] ) => `${ code } (${ id })` ).join( ', ' ) );
	}
	console.log( `\nlow-stock threshold on this store: ${ threshold }` );
	await browser.close();
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
