/**
 * Etch island copy + image editing.
 *
 * Etch stores every line of copy in `wp:etch/text` `content` attributes (not
 * as HTML text nodes), so the generic textRunsOf scan cannot see it. This
 * suite pins the sibling path: in-place preview runs splice the quoted JSON
 * value, untouched strings stay byte-identical, and a tag:img src in element
 * attributes replaces through the inspector Images list.
 *
 * SKIPs (exit 0) when etch/text is not a registered block, so run-all on
 * minnadmin stays green. Point it at a site with Etch active:
 *   MINN_TEST_URL=https://etch.localhost MINN_TEST_USER=minn-etch-dev \
 *   MINN_TEST_PASS=minn-etch-dev-1 node etch-text.test.js
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const PNG_RED = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const PNG_BLU = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPj/HwADBwIAMCbHYQAAAABJRU5ErkJggg==';

( async () => {
	const t = reporter( 'etch-text' );
	const { browser, page, errors } = await launch();
	await login( page );

	const hasEtch = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/block-types/etch/text', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return r.ok;
	} );
	if ( ! hasEtch ) {
		console.log( 'SKIP  Etch is not active — suite not run' );
		await browser.close();
		process.exit( 0 );
	}

	const uploadPng = async ( b64, name ) => page.evaluate( async ( { b64, name } ) => {
		const bin = atob( b64 );
		const bytes = new Uint8Array( bin.length );
		for ( let i = 0; i < bin.length; i++ ) bytes[ i ] = bin.charCodeAt( i );
		const fd = new FormData();
		fd.append( 'file', new Blob( [ bytes ], { type: 'image/png' } ), name );
		const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
			method: 'POST',
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			body: fd,
		} );
		const j = await r.json();
		if ( ! r.ok ) throw new Error( j.message || 'upload failed' );
		return { id: j.id, url: j.source_url };
	}, { b64, name } );

	const red = await uploadPng( PNG_RED, 'minn-etch-red.png' );
	const blu = await uploadPng( PNG_BLU, 'minn-etch-blu.png' );

	const content = [
		'<!-- wp:etch/element {"metadata":{"name":"Hero"},"tag":"section","attributes":{"class":"minn-etch-fix"}} -->',
		'<!-- wp:etch/element {"metadata":{"name":"Heading"},"tag":"h2","attributes":{}} -->',
		'<!-- wp:etch/text {"metadata":{"name":"Text"},"content":"Alpha heading"} /-->',
		'<!-- /wp:etch/element -->',
		'<!-- wp:etch/element {"metadata":{"name":"Lead"},"tag":"p","attributes":{}} -->',
		'<!-- wp:etch/text {"metadata":{"name":"Text"},"content":"Bravo lead copy."} /-->',
		'<!-- /wp:etch/element -->',
		`<!-- wp:etch/element {"metadata":{"name":"Photo"},"tag":"img","attributes":{"src":"${ red.url }","alt":"fixture","width":"1","height":"1"}} -->`,
		'<!-- /wp:etch/element -->',
		'<!-- /wp:etch/element -->',
	].join( '\n' );

	const id = await createPost( page, { title: 'Etch copy edit test', content } );

	const save = async ( expectFn ) => {
		await page.keyboard.press( 'Meta+s' );
		for ( let i = 0; i < 15; i++ ) {
			await page.waitForTimeout( 900 );
			const raw = await rawContent();
			if ( ! expectFn || expectFn( raw ) ) return raw;
		}
		return rawContent();
	};
	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	const deleteMedia = async ( mid ) => page.evaluate( async ( pid ) => {
		await fetch( window.MINN.restUrl + 'wp/v2/media/' + pid + '?force=true', {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} ).catch( () => {} );
	}, mid ).catch( () => {} );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="etch/element"]', { timeout: 15000 } );
		t.check( 'etch section islands as one card',
			( await page.$$( '.minn-block-island[data-block="etch/element"]' ) ).length >= 1 );

		await page.waitForSelector( '.minn-island-run', { timeout: 20000 } );
		const runCount = await page.$$eval( '.minn-island-run', ( els ) => els.length );
		t.check( 'in-place runs armed on etch/text copy', runCount >= 2, runCount + ' runs' );

		const first = page.locator( '.minn-island-run' ).first();
		const box = await first.boundingBox();
		t.check( 'first run is hittable', !! box && box.width > 0 && box.height > 0 );
		await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
		await page.keyboard.press( 'Meta+a' );
		await page.keyboard.type( 'Changed heading' );

		const previewHas = await page.waitForFunction( () =>
			( document.querySelector( '.minn-island-preview' ) || {} ).textContent
				&& document.querySelector( '.minn-island-preview' ).textContent.includes( 'Changed heading' ),
		null, { timeout: 8000 } ).then( () => true ).catch( () => false );
		t.check( 'preview shows the typed heading', previewHas );

		const raw1 = await save( ( r ) => r.includes( '"content":"Changed heading"' ) );
		t.check( 'saved JSON content attr updated', raw1.includes( '"content":"Changed heading"' ) );
		t.check( 'untouched etch/text stays byte-identical',
			raw1.includes( '"content":"Bravo lead copy."' ) && ! raw1.includes( '"content":"Alpha heading"' ) );
		t.check( 'image src untouched by the text edit', raw1.includes( red.url ) );

		// Inspector image replace: click the ⚙ with a real mouse (preview
		// chrome is pointer-events:none; synthetic clicks lie).
		const chip = page.locator( '.minn-block-island[data-block="etch/element"] .minn-island-chip' ).first();
		const chipBox = await chip.boundingBox();
		await page.mouse.click( chipBox.x + chipBox.width / 2, chipBox.y + chipBox.height / 2 );
		await page.waitForSelector( '[data-inspimg]', { timeout: 10000 } );
		t.check( 'inspector lists the etch img src', ( await page.$$( '[data-inspimg]' ) ).length >= 1 );

		await page.click( '[data-inspimg]' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 15000 } );
		const picked = await page.evaluate( ( name ) => {
			const el = [ ...document.querySelectorAll( '.minn-picker-item' ) ].find( ( e ) =>
				( e.title || e.textContent || '' ).includes( name ) );
			if ( ! el ) return false;
			el.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			return true;
		}, 'minn-etch-blu' );
		t.check( 'replacement image picked', picked );

		const raw2 = await save( ( r ) => r.includes( blu.url ) );
		t.check( 'img src swapped in element attributes', raw2.includes( blu.url ) && ! raw2.includes( red.url ) );
		t.check( 'heading edit survived the image swap', raw2.includes( '"content":"Changed heading"' ) );
		t.check( 'lead copy still untouched', raw2.includes( '"content":"Bravo lead copy."' ) );
	} finally {
		await deletePost( page, id );
		await deleteMedia( red.id );
		await deleteMedia( blu.id );
	}

	await t.done( browser, errors );
} )();
