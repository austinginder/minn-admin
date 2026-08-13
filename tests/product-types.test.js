/**
 * Wave 6 of the product page: the type combobox, Virtual and Downloadable,
 * the Downloads card and the external-product fields.
 *
 * The load-bearing check here is that flipping one of those controls repaints
 * the page WITHOUT throwing away what has been typed into the other cards.
 *
 * Fixture: one product, created and removed over REST.
 */
const { BASE, launch, login, reporter, pickCombo, setSwitch, switchOn } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-types' );

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

	const cards = () => page.evaluate( () => Array.from(
		document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ) );

	const suffix = Date.now();
	let id = null;
	try {
		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( {
				name: 'Product types fixture ' + suffix,
				type: 'simple', regular_price: '14.00', status: 'publish',
			} ),
		} );
		id = made.body && made.body.id;
		t.check( 'fixture product created', !! id, String( made.status ) );
		if ( ! id ) {
			await t.done( browser, errors );
			return;
		}

		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-type', { timeout: 20000 } );

		const start = await page.evaluate( () => ( {
			type: document.querySelector( '#minn-p-type' ).dataset.acValue,
			virtual: !! document.querySelector( '#minn-p-virtual' ),
			downloadable: !! document.querySelector( '#minn-p-downloadable' ),
		} ) );
		t.check( 'type, virtual and downloadable are on the page',
			start.type === 'simple' && start.virtual && start.downloadable, JSON.stringify( start ) );
		const before = await cards();
		t.check( 'a simple product has Shipping and no Downloads',
			before.includes( 'Shipping' ) && ! before.includes( 'Downloads' ), JSON.stringify( before ) );

		// THE INVARIANT: type a value, flip a switch, and the typed value lives.
		await page.fill( '#minn-p-sku', 'TYPE-KEEP-' + suffix );
		await page.fill( '#minn-p-regular', '44.44' );
		await setSwitch( page, '#minn-p-virtual', true );
		await page.waitForTimeout( 400 );
		const afterVirtual = await page.evaluate( () => ( {
			sku: ( document.querySelector( '#minn-p-sku' ) || {} ).value,
			price: ( document.querySelector( '#minn-p-regular' ) || {} ).value,
			shipping: !! document.querySelector( '#minn-p-weight' ),
			virtualOn: document.querySelector( '#minn-p-virtual' ).classList.contains( 'on' ),
		} ) );
		t.check( 'Virtual hides the Shipping card', ! afterVirtual.shipping && afterVirtual.virtualOn,
			JSON.stringify( afterVirtual ) );
		t.check( 'flipping Virtual keeps what was typed',
			afterVirtual.sku === 'TYPE-KEEP-' + suffix && afterVirtual.price === '44.44',
			JSON.stringify( afterVirtual ) );

		// Downloadable reveals the Downloads card, again without losing edits.
		await setSwitch( page, '#minn-p-downloadable', true );
		await page.waitForFunction( () => !! document.querySelector( '#minn-p-downloads' ), null, { timeout: 15000 } ).catch( () => null );
		const afterDl = await page.evaluate( () => ( {
			cards: Array.from( document.querySelectorAll( '.minn-order-panel .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			sku: ( document.querySelector( '#minn-p-sku' ) || {} ).value,
			add: !! document.querySelector( '#minn-p-dl-add' ),
			limit: !! document.querySelector( '#minn-p-dllimit' ),
		} ) );
		t.check( 'Downloadable reveals the Downloads card',
			afterDl.cards.includes( 'Downloads' ) && afterDl.add && afterDl.limit, JSON.stringify( afterDl ) );
		t.check( 'flipping Downloadable keeps what was typed',
			afterDl.sku === 'TYPE-KEEP-' + suffix, String( afterDl.sku ) );

		// Add a file and save the whole thing once.
		await page.click( '#minn-p-dl-add' );
		await page.waitForSelector( '[data-pdlname="0"]', { timeout: 10000 } );
		await page.fill( '[data-pdlname="0"]', 'Care guide' );
		await page.fill( '[data-pdlfile="0"]', 'https://example.com/care-guide.pdf' );
		await page.fill( '#minn-p-dllimit', '3' );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 700 );

		const saved = await api( `wc/v3/products/${ id }?_fields=id,type,virtual,downloadable,downloads,download_limit,download_expiry,sku,regular_price` );
		const b = saved.body || {};
		t.check( 'virtual saved', b.virtual === true, String( b.virtual ) );
		t.check( 'downloadable saved', b.downloadable === true, String( b.downloadable ) );
		t.check( 'the typed SKU and price survived to the server',
			b.sku === 'TYPE-KEEP-' + suffix && String( b.regular_price ) === '44.44',
			JSON.stringify( { sku: b.sku, price: b.regular_price } ) );
		t.check( 'the download file saved with a generated id',
			( b.downloads || [] ).length === 1 && b.downloads[ 0 ].name === 'Care guide'
			&& /care-guide\.pdf$/.test( b.downloads[ 0 ].file ) && !! b.downloads[ 0 ].id,
			JSON.stringify( b.downloads ) );
		t.check( 'download limit saved', Number( b.download_limit ) === 3, String( b.download_limit ) );
		t.check( 'empty expiry stores -1, meaning never',
			Number( b.download_expiry ) === -1, String( b.download_expiry ) );

		// Switch to an external product: its own fields appear.
		await pickCombo( page, '#minn-p-type', 'external' );
		await page.waitForFunction( () => !! document.querySelector( '#minn-p-exturl' ), null, { timeout: 15000 } ).catch( () => null );
		t.check( 'external type reveals the URL and button fields',
			!! ( await page.$( '#minn-p-exturl' ) ) && !! ( await page.$( '#minn-p-btntext' ) ), '' );
		await page.fill( '#minn-p-exturl', 'https://example.com/buy-it' );
		await page.fill( '#minn-p-btntext', 'Buy on partner site' );
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const btn = document.querySelector( '#minn-product-save' );
			return btn && ! btn.disabled && /Save/.test( btn.textContent );
		}, null, { timeout: 20000 } ).catch( () => null );
		await page.waitForTimeout( 700 );
		const ext = await api( `wc/v3/products/${ id }?_fields=id,type,external_url,button_text` );
		t.check( 'the type change saved',
			( ext.body || {} ).type === 'external', String( ( ext.body || {} ).type ) );
		t.check( 'external URL and button text saved',
			/buy-it$/.test( ( ext.body || {} ).external_url || '' )
			&& ( ext.body || {} ).button_text === 'Buy on partner site',
			JSON.stringify( ext.body ) );

		// A variable product hands price and stock back to WooCommerce.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-type', { timeout: 20000 } );
		await pickCombo( page, '#minn-p-type', 'variable' );
		await page.waitForTimeout( 500 );
		const variable = await page.evaluate( () => ( {
			price: !! document.querySelector( '#minn-p-regular' ),
			gtin: !! document.querySelector( '#minn-p-gtin' ),
			note: ( document.querySelector( '.minn-order-body' ) || {} ).textContent || '',
		} ) );
		t.check( 'a variable product hides the price fields and says why',
			! variable.price && /managed in WooCommerce/i.test( variable.note ), JSON.stringify( {
				price: variable.price, gtin: variable.gtin } ) );
		t.check( 'a variable product keeps its identifiers', variable.gtin, String( variable.gtin ) );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
