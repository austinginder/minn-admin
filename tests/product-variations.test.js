/**
 * Wave 9 of the product page: the Variations card on a variable product.
 * Generate from attributes, edit per variation, remove, all saved through the
 * variations batch route by the page's own Save button.
 *
 * Fixture: one variable product using the standing "Size" store attribute.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'product-variations' );

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

	const save = async () => {
		await page.click( '#minn-product-save' );
		await page.waitForFunction( () => {
			const b = document.querySelector( '#minn-product-save' );
			return b && ! b.disabled && /Save/.test( b.textContent );
		}, null, { timeout: 25000 } ).catch( () => null );
		await page.waitForTimeout( 900 );
	};

	const suffix = Date.now();
	let id = null;
	try {
		const defs = await api( 'wc/v3/products/attributes?_fields=id,name' );
		const attr = ( defs.body || [] )[ 0 ];
		t.check( 'the store has a global attribute to vary by', !! attr, JSON.stringify( defs.body ) );
		if ( ! attr ) {
			await t.done( browser, errors );
			return;
		}

		const made = await api( 'wc/v3/products', {
			method: 'POST',
			body: JSON.stringify( { name: 'Variation fixture ' + suffix, type: 'variable', status: 'publish' } ),
		} );
		id = made.body && made.body.id;
		// A global attribute cannot be assigned in the request that creates the
		// product; a second request is how it sticks (wave 8's finding).
		await api( `wc/v3/products/${ id }`, {
			method: 'PUT',
			body: JSON.stringify( { attributes: [
				{ id: attr.id, visible: true, variation: true, options: [ 'Small', 'Large' ] },
			] } ),
		} );
		t.check( 'fixture variable product created', !! id, String( made.status ) );
		if ( ! id ) {
			await t.done( browser, errors );
			return;
		}

		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-variations', { timeout: 20000 } );

		const card = await page.evaluate( () => ( {
			titles: Array.from( document.querySelectorAll( '.minn-order-sec .minn-side-title' ) ).map( ( e ) => e.textContent.trim() ),
			empty: /No variations yet/.test( ( document.querySelector( '#minn-p-variations' ) || {} ).textContent || '' ),
			gen: !! document.querySelector( '#minn-p-var-gen' ),
			add: !! document.querySelector( '#minn-p-var-add' ),
		} ) );
		t.check( 'Variations card is on the variable product', card.titles.includes( 'Variations' ), JSON.stringify( card.titles ) );
		t.check( 'a variation-less product says so and offers both buttons',
			card.empty && card.gen && card.add, JSON.stringify( card ) );

		// Generate every combination the attribute allows.
		await page.click( '#minn-p-var-gen' );
		await page.waitForSelector( '.minn-pvar-row', { timeout: 10000 } );
		const rows = await page.evaluate( () => document.querySelectorAll( '.minn-pvar-row' ).length );
		t.check( 'generating builds one row per attribute value', rows === 2, String( rows ) );

		// The card is a list of variants, not a wall of inputs: each row reads
		// as a variant, and the editing happens behind it.
		const listShape = await page.evaluate( () => {
			const row = document.querySelector( '.minn-pvar-row' );
			return {
				openable: document.querySelectorAll( '.minn-pvar-row[data-pvaropen]' ).length,
				inlineInputs: document.querySelectorAll( '#minn-p-variations input' ).length,
				name: ( ( row.querySelector( '.minn-pvar-name' ) || {} ).textContent || '' ).trim(),
				price: !! row.querySelector( '.minn-pvar-price' ),
				avail: !! row.querySelector( '.minn-pvar-avail' ),
				thumb: !! row.querySelector( '.minn-pvar-thumb' ),
				badge: !! row.querySelector( '.minn-pvar-new' ),
			};
		} );
		t.check( 'the card lists variants rather than rows of inputs',
			listShape.openable === 2 && listShape.inlineInputs === 0, JSON.stringify( listShape ) );
		t.check( 'a row carries the variant, a picture slot, price and availability',
			/Small|Large/.test( listShape.name ) && listShape.price && listShape.avail && listShape.thumb,
			JSON.stringify( listShape ) );
		t.check( 'an unsaved variation is badged as new', listShape.badge, JSON.stringify( listShape ) );

		// Everything modifiable lives in the variant's own editor.
		await ( await page.$( '[data-pvaropen="0"]' ) ).click();
		await page.waitForSelector( '#minn-var-dialog', { timeout: 15000 } );
		const dlg = await page.evaluate( () => ( {
			title: ( ( document.querySelector( '#minn-var-dialog .minn-confirm-title' ) || {} ).textContent || '' ).trim(),
			sku: !! document.querySelector( '#minn-var-sku' ),
			regular: !! document.querySelector( '#minn-var-regular' ),
			sale: !! document.querySelector( '#minn-var-sale' ),
			stock: !! document.querySelector( '#minn-var-dialog [data-varstock]' ),
			track: !! document.querySelector( '#minn-var-track' ),
			axes: document.querySelectorAll( '#minn-var-dialog [data-varaxis]' ).length,
			image: !! document.querySelector( '#minn-var-img-pick' ),
			natives: document.querySelectorAll( '#minn-var-dialog select' ).length,
		} ) );
		t.check( 'the editor opens on the variant it was asked for',
			/Small|Large/.test( dlg.title ), dlg.title );
		t.check( 'and holds everything modifiable about it',
			dlg.sku && dlg.regular && dlg.sale && dlg.stock && dlg.track && dlg.axes === 1 && dlg.image,
			JSON.stringify( dlg ) );
		t.check( 'the editor uses Minn comboboxes, not native selects',
			dlg.natives === 0, String( dlg.natives ) );
		// Drawn, not just marked hidden: a button's own display beats the
		// hidden attribute, which is how this shipped visible the first time.
		const removeShown = await page.evaluate( () => {
			const b = document.querySelector( '#minn-var-img-x' );
			return !! b && b.getBoundingClientRect().height > 0;
		} );
		t.check( 'a variant with no picture offers no Remove image', ! removeShown, String( removeShown ) );

		// Cancel is not a save: what was typed goes nowhere.
		await page.fill( '#minn-var-regular', '999.00' );
		await page.click( '#minn-var-dialog [data-var-cancel]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-var-dialog' ), null, { timeout: 15000 } );
		const afterCancel = await page.evaluate( () => Array.from(
			document.querySelectorAll( '.minn-pvar-price' ) ).map( ( e ) => e.textContent.trim() ).join( '|' ) );
		t.check( 'cancelling the editor changes nothing', ! /999/.test( afterCancel ), afterCancel );

		// Price them through the editor, then save through the page's button.
		const editVariant = async ( i, fields ) => {
			await ( await page.$( `[data-pvaropen="${ i }"]` ) ).click();
			await page.waitForSelector( '#minn-var-dialog', { timeout: 15000 } );
			if ( fields.regular != null ) await page.fill( '#minn-var-regular', fields.regular );
			if ( fields.sku != null ) await page.fill( '#minn-var-sku', fields.sku );
			await page.click( '#minn-var-dialog [data-var-done]' );
			await page.waitForFunction( () => ! document.querySelector( '#minn-var-dialog' ), null, { timeout: 15000 } );
			await page.waitForTimeout( 150 );
		};
		await editVariant( 0, { regular: '19.00', sku: 'VAR-S-' + suffix } );
		await editVariant( 1, { regular: '24.00' } );
		const rowPrices = await page.evaluate( () => Array.from(
			document.querySelectorAll( '.minn-pvar-price' ) ).map( ( e ) => e.textContent.trim() ) );
		t.check( 'Done writes what was typed back onto the row',
			rowPrices.some( ( p ) => /19\.00/.test( p ) ) && rowPrices.some( ( p ) => /24\.00/.test( p ) ),
			JSON.stringify( rowPrices ) );
		await save();

		const saved = await api( `wc/v3/products/${ id }/variations?per_page=100&_fields=id,sku,regular_price,attributes` );
		const list = saved.body || [];
		t.check( 'both variations saved', list.length === 2, JSON.stringify( list.map( ( v ) => v.regular_price ) ) );
		t.check( 'variation prices saved',
			list.some( ( v ) => String( v.regular_price ) === '19.00' )
			&& list.some( ( v ) => String( v.regular_price ) === '24.00' ),
			JSON.stringify( list.map( ( v ) => v.regular_price ) ) );
		t.check( 'variation SKU saved',
			list.some( ( v ) => v.sku === 'VAR-S-' + suffix ), JSON.stringify( list.map( ( v ) => v.sku ) ) );
		t.check( 'each variation carries its attribute value',
			list.every( ( v ) => ( v.attributes || [] ).some( ( a ) => /Small|Large/.test( a.option || '' ) ) ),
			JSON.stringify( list.map( ( v ) => v.attributes ) ) );

		// Saved rows lose the New badge: it means "not on the server yet".
		const badgesAfterSave = await page.evaluate( () => document.querySelectorAll( '.minn-pvar-new' ).length );
		t.check( 'a saved variation is no longer badged new', badgesAfterSave === 0, String( badgesAfterSave ) );

		// Saving twice must not duplicate: created rows come back with ids.
		// The second save needs a real edit to reach: the page's save bar is
		// down while the form matches what was stored, which is also the point
		// of the check — the rows that come back carry ids, so this updates.
		await editVariant( 0, { regular: '19.50' } );
		await save();
		const again = await api( `wc/v3/products/${ id }/variations?per_page=100&_fields=id` );
		t.check( 'saving twice does not duplicate the variations',
			( again.body || [] ).length === 2, String( ( again.body || [] ).length ) );

		// Generating again finds nothing missing.
		await page.click( '#minn-p-var-gen' );
		await page.waitForTimeout( 400 );
		const afterGen = await page.evaluate( () => document.querySelectorAll( '.minn-pvar-row' ).length );
		t.check( 'generating again adds no duplicates', afterGen === 2, String( afterGen ) );

		// Reload and edit an existing variation.
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-pvar-row', { timeout: 20000 } );
		const repop = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '.minn-pvar-row' ).length,
			prices: Array.from( document.querySelectorAll( '.minn-pvar-price' ) ).map( ( e ) => e.textContent.trim() ),
			names: Array.from( document.querySelectorAll( '.minn-pvar-name' ) ).map( ( e ) => e.textContent.trim() ),
			nativeSelects: document.querySelectorAll( '#minn-p-variations select' ).length,
		} ) );
		t.check( 'variations repopulate with their prices and values',
			repop.rows === 2 && repop.prices.some( ( p ) => /19\.50/.test( p ) )
			&& repop.names.filter( ( n ) => /Small|Large/.test( n ) ).length === 2, JSON.stringify( repop ) );
		t.check( 'variation rows use Minn comboboxes, not native selects',
			repop.nativeSelects === 0, String( repop.nativeSelects ) );

		// A picture for one variant, picked from the library through the
		// editor. The picker opens over the dialog, which is the part worth
		// proving: a modal inside a modal is where z-index goes wrong.
		await ( await page.$( '[data-pvaropen="0"]' ) ).click();
		await page.waitForSelector( '#minn-var-dialog', { timeout: 15000 } );
		await page.click( '#minn-var-img-pick' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 20000 } );
		const pickerOnTop = await page.evaluate( () => {
			const pick = document.querySelector( '#minn-modal-overlay' );
			const dlg = document.querySelector( '#minn-var-dialog' );
			if ( ! pick || ! dlg ) return null;
			const z = ( el ) => parseInt( getComputedStyle( el ).zIndex, 10 ) || 0;
			const r = pick.querySelector( '.minn-picker-item' ).getBoundingClientRect();
			const hit = document.elementFromPoint( r.x + r.width / 2, r.y + r.height / 2 );
			return { pickerZ: z( pick ), dialogZ: z( dlg ), reachable: !! hit && !! hit.closest( '.minn-picker-item' ) };
		} );
		t.check( 'the picker opens above the variant editor, clickable',
			!! pickerOnTop && pickerOnTop.pickerZ > pickerOnTop.dialogZ && pickerOnTop.reachable,
			JSON.stringify( pickerOnTop ) );
		await page.click( '.minn-picker-item' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-picker-item' ), null, { timeout: 15000 } ).catch( () => null );
		await page.waitForTimeout( 300 );
		const gotThumb = await page.evaluate( () => !! document.querySelector( '#minn-var-image img' ) );
		t.check( 'the picked image shows in the editor', gotThumb, '' );
		await page.fill( '#minn-var-regular', '21.50' );
		await page.click( '#minn-var-dialog [data-var-done]' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-var-dialog' ), null, { timeout: 15000 } );
		const rowThumb = await page.evaluate( () => !! document.querySelector( '.minn-pvar-thumb img' ) );
		t.check( 'and on the row it came from', rowThumb, '' );
		await save();
		const withImage = await api( `wc/v3/products/${ id }/variations?per_page=100&_fields=id,image,regular_price` );
		t.check( 'the variant picture saves',
			( withImage.body || [] ).some( ( v ) => v.image && v.image.id ),
			JSON.stringify( ( withImage.body || [] ).map( ( v ) => ( v.image ? v.image.id : null ) ) ) );
		const edited = await api( `wc/v3/products/${ id }/variations?per_page=100&_fields=id,regular_price` );
		t.check( 'editing an existing variation updates it',
			( edited.body || [] ).some( ( v ) => String( v.regular_price ) === '21.50' )
			&& ( edited.body || [] ).length === 2,
			JSON.stringify( ( edited.body || [] ).map( ( v ) => v.regular_price ) ) );

		// Remove one and save: it goes from the server too.
		await page.click( '[data-pvarx="0"]' );
		await page.waitForTimeout( 300 );
		await save();
		const removed = await api( `wc/v3/products/${ id }/variations?per_page=100&_fields=id` );
		t.check( 'removing a variation deletes it on save',
			( removed.body || [] ).length === 1, String( ( removed.body || [] ).length ) );

		// A simple product has no Variations card at all.
		await api( `wc/v3/products/${ id }`, { method: 'PUT', body: JSON.stringify( { type: 'simple' } ) } );
		await page.goto( BASE + '/minn-admin/products/' + id, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-p-name', { timeout: 20000 } );
		t.check( 'a simple product has no Variations card',
			! ( await page.$( '#minn-p-variations' ) ), '' );
	} finally {
		if ( id ) await api( `wc/v3/products/${ id }?force=true`, { method: 'DELETE' } ).catch( () => null );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
