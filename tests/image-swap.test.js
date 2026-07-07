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

	await t.done( browser, errors );
} )();
