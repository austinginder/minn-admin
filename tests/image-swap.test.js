/**
 * Island image-swap heuristics (docs/block-suites.md, image conventions).
 *
 * One synthetic (unregistered — islands preserve those too) block carries
 * every id/url convention the block-suite lab documented:
 *   - flat  "bgImg" + "bgImgID"            (Kadence rows)
 *   - flat  "imageUrl" + "imageId"          (Essential Blocks)
 *   - object { "url", "id" }                (Spectra / Otter / EB media objects)
 *   - "mediaId" with src only in HTML       (GenerateBlocks)
 *   - img markers wp-image-N, data-media-id, data-id
 *   - background-image style + all URL occurrences
 * Replacing the image via the inspector must retarget ALL of them.
 *
 * A second block covers images that live ONLY in attributes, the shape
 * Gutenslider uses: string ids ("id":"1971"), a mirror array on the parent,
 * and TWO url keys per picture (a sized copy plus the original). Those pair
 * into one inspector row, and replacing moves both keys everywhere.
 *
 * Fixtures: gal-blue / gal-red images in the minnadmin media library.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'image-swap' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Resolve the two fixture images.
	const media = await page.evaluate( async () => {
		const find = async ( q ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media?search=' + q + '&_fields=id,source_url,title', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const items = await r.json();
			return items.find( ( i ) => i.title.rendered.includes( q ) );
		};
		return { blue: await find( 'gal-blue' ), red: await find( 'gal-red' ) };
	} );
	t.check( 'fixture images resolved', !! ( media.blue && media.red ),
		JSON.stringify( { blue: !! media.blue, red: !! media.red } ) );
	if ( ! media.blue || ! media.red ) { await t.done( browser, errors ); return; }

	const B = media.blue.source_url;
	const content = [
		`<!-- wp:acme/hero {"bgImg":"${ B }","bgImgID":999,"imageUrl":"${ B }","imageId":999,"media":{"url":"${ B }","id":999,"alt":""},"mediaId":999} -->`,
		`<div class="wp-block-acme-hero" style="background-image:url(${ B })"><img class="acme-img wp-image-999" src="${ B }" data-media-id="999" data-id="999"/></div>`,
		'<!-- /wp:acme/hero -->',
	].join( '\n' );

	const id = await createPost( page, { title: 'Image swap heuristics test', content } );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="acme/hero"]', { timeout: 10000 } );

		// Inspector lists the image; replace with gal-red.
		await page.click( '.minn-block-island .minn-island-chip' );
		await page.waitForSelector( '[data-inspimg]', { timeout: 10000 } );
		t.check( 'inspector lists the synthetic block image', ( await page.$$( '[data-inspimg]' ) ).length === 1 );
		await page.click( '[data-inspimg]' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 15000 } );
		const picked = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '.minn-picker-item' ) ].find( ( e ) => /gal-red/i.test( e.title ) );
			if ( ! el ) return false;
			el.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			return true;
		} );
		t.check( 'gal-red picked', picked );
		await page.waitForTimeout( 1200 );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2000 );

		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return ( await r.json() ).content.raw;
		}, id );

		const R = media.red.source_url;
		const rid = media.red.id;
		t.check( 'every URL occurrence swapped', ! raw.includes( B ) && raw.split( R ).length - 1 >= 4, raw.slice( 0, 260 ) );
		t.check( 'Kadence-style bgImgID retargeted', raw.includes( `"bgImgID":${ rid }` ) );
		t.check( 'flat imageId retargeted', raw.includes( `"imageId":${ rid }` ) );
		t.check( 'media-object id retargeted', new RegExp( `"media":\\{"url":"${ R.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) }","id":${ rid },` ).test( raw ) );
		t.check( 'GenerateBlocks-style mediaId retargeted', raw.includes( `"mediaId":${ rid }` ) );
		t.check( 'img markers retargeted', raw.includes( `wp-image-${ rid }` ) && raw.includes( `data-media-id="${ rid }"` ) && raw.includes( `data-id="${ rid }"` ) );
		t.check( 'no stale 999 ids remain', ! raw.includes( '999' ) );
	} finally {
		await deletePost( page, id );
	}

	// --- Attribute-only images with paired url keys (Gutenslider shape) ---
	const R = media.red.source_url;
	const RFULL = R.replace( /\.png$/, '-full.png' );
	const gsContent = [
		`<!-- wp:acme/gslider {"media":[{"alt":"","id":"7001","url":"${ R }","fullUrl":"${ RFULL }"}]} -->`,
		`<!-- wp:acme/gslide {"background":{"backgroundImage":{"alt":"","id":"7001","url":"${ R }","fullUrl":"${ RFULL }"},"mediaId":"7001"}} -->`,
		'<!-- wp:paragraph -->',
		'<p>Slide one.</p>',
		'<!-- /wp:paragraph -->',
		'<!-- /wp:acme/gslide -->',
		'<!-- /wp:acme/gslider -->',
	].join( '\n' );
	const gsId = await createPost( page, { title: 'Attribute image swap test', content: gsContent } );
	try {
		await openEditor( page, gsId );
		await page.waitForSelector( '.minn-block-island[data-block="acme/gslider"]', { timeout: 15000 } );
		await page.waitForTimeout( 1500 );
		for ( let i = 0; i < 8; i++ ) {
			try {
				await page.click( '.minn-block-island[data-block="acme/gslider"] .minn-island-chip' );
				await page.waitForSelector( '[data-inspimg]', { timeout: 6000 } );
				break;
			} catch ( e ) { await page.waitForTimeout( 1200 ); }
		}
		// Four URL occurrences, one picture: the inspector offers ONE row.
		t.check( 'paired url keys collapse to one image row', ( await page.$$( '[data-inspimg]' ) ).length === 1,
			String( ( await page.$$( '[data-inspimg]' ) ).length ) );
		await page.click( '[data-inspimg]' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 15000 } );
		const pickedBlue = await page.evaluate( () => {
			const el = [ ...document.querySelectorAll( '.minn-picker-item' ) ].find( ( e ) => /gal-blue/i.test( e.title ) );
			if ( ! el ) return false;
			el.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );
			return true;
		} );
		t.check( 'gal-blue picked for the attribute block', pickedBlue );
		await page.waitForTimeout( 1200 );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2500 );
		const gsRaw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content&_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return ( await r.json() ).content.raw;
		}, gsId );
		const bid = media.blue.id;
		t.check( 'both url keys moved to the new image', ! gsRaw.includes( R ) && ! gsRaw.includes( RFULL )
			&& ( gsRaw.match( /"(?:url|fullUrl)":"[^"]*gal-blue[^"]*"/g ) || [] ).length === 4, gsRaw.slice( 0, 200 ) );
		t.check( 'string-form ids retargeted in both objects', ( gsRaw.match( new RegExp( '"id":"' + bid + '"', 'g' ) ) || [] ).length === 2, gsRaw.slice( 0, 200 ) );
		t.check( 'string-form mediaId retargeted', gsRaw.includes( '"mediaId":"' + bid + '"' ) );
		t.check( 'no stale 7001 ids remain', ! gsRaw.includes( '7001' ) );
		t.check( 'slide content untouched', gsRaw.includes( '<p>Slide one.</p>' ) );
	} finally {
		await deletePost( page, gsId );
	}

	await t.done( browser, errors );
} )();
