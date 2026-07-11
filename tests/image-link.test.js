/**
 * Image link + lightbox through the image popover (Austin's editor asks).
 *
 * The link is the DOM itself: core sources `href` from figure > a, so
 * wrapping the img is Gutenberg-valid by construction; linkDestination and
 * the comment-only lightbox attr ride the data-minn-attrs passthrough. The
 * two are mutually exclusive (core's own rule) — the popover enforces it.
 * Everything is verified against SAVED post_content, not the DOM.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'image-link' );
	const { browser, page, errors } = await launch();
	await login( page );

	// A real attachment (gal-red is a standing media fixture) keeps the block
	// exactly what Gutenberg would produce.
	const media = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/media?search=gal-red&_fields=id,source_url', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() )[ 0 ];
	} );

	const id = await createPost( page, {
		title: 'Image link test',
		content: `<!-- wp:image {"id":${ media.id },"sizeSlug":"large","linkDestination":"none"} -->\n<figure class="wp-block-image size-large"><img src="${ media.source_url }" alt="" class="wp-image-${ media.id }"/></figure>\n<!-- /wp:image -->`,
	} );

	const raw = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content&_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).content.raw;
	}, id );

	const save = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 400 );
	};

	const openPop = async () => {
		await page.click( '.minn-editor-body figure img' );
		await page.waitForSelector( '[data-img-link]', { timeout: 10000 } );
	};

	try {
		t.check( 'fixture attachment found', !! ( media && media.id ), JSON.stringify( media ) );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-editor-body figure img', { timeout: 15000 } );

		/* ===== Link the image ===== */
		await openPop();
		await page.fill( '[data-img-link]', 'https://example.com/case-study' );
		t.check( 'lightbox disables while a link is set', await page.$eval( '[data-img-lightbox]', ( el ) => el.disabled ) );
		await page.click( '[data-img-newtab]' );
		await page.click( '[data-img-apply]' );
		await save();
		let content = await raw();
		t.check( 'saved figure wraps the img in the link', /<figure[^>]*><a href="https:\/\/example\.com\/case-study"[^>]*><img/.test( content ), content.slice( 0, 300 ) );
		t.check( 'new tab rides target + rel', /target="_blank"/.test( content ) && /rel="noreferrer noopener"/.test( content ) );
		t.check( 'linkDestination custom in the comment attrs', /"linkDestination":"custom"/.test( content ) );
		t.check( 'attachment id survives', content.includes( `"id":${ media.id }` ) && content.includes( `wp-image-${ media.id }` ) );

		/* ===== Reopen: link prefilled; swap to lightbox ===== */
		await openPop();
		t.check( 'link prefills on reopen', await page.$eval( '[data-img-link]', ( el ) => el.value === 'https://example.com/case-study' ) );
		await page.fill( '[data-img-link]', '' );
		t.check( 'clearing the link re-enables lightbox', await page.$eval( '[data-img-lightbox]', ( el ) => ! el.disabled ) );
		await page.click( '[data-img-lightbox]' );
		await page.click( '[data-img-apply]' );
		await save();
		content = await raw();
		t.check( 'link unwrapped from the saved figure', ! /<figure[^>]*><a /.test( content ), content.slice( 0, 300 ) );
		t.check( 'lightbox enabled in the comment attrs', /"lightbox":\{"enabled":true\}/.test( content ) );
		t.check( 'linkDestination removed with the link', ! /"linkDestination"/.test( content ) );

		/* ===== Lightbox off again ===== */
		await openPop();
		await page.click( '[data-img-lightbox]' );
		await page.click( '[data-img-apply]' );
		await save();
		content = await raw();
		t.check( 'lightbox attr fully removed when off', ! /"lightbox"/.test( content ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
