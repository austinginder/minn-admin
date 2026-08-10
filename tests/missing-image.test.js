/**
 * Missing-image placeholder.
 *
 * A dead image URL inside a protected block's preview draws an illustration
 * that says so, instead of the browser's broken glyph (which, on a themed
 * slide, reads as a broken block). The rules that keep it safe: it is painted
 * with CSS on the failed <img> itself, so the src attribute stays exactly as
 * authored (the image-tooling doorway matches on it), and it is applied ONLY
 * to preview and modal chrome — a class on an image in your content would
 * serialize into the saved markup.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const GONE = BASE + '/wp-content/uploads/minn-no-such-image-9k1.png';
const CONTENT = [
	`<!-- wp:acme/hero -->`,
	`<div class="wp-block-acme-hero"><img src="${ GONE }" alt=""></div>`,
	`<!-- /wp:acme/hero -->`,
	'',
	`<!-- wp:image {"id":4242} -->`,
	`<figure class="wp-block-image"><img src="${ GONE }" alt="" class="wp-image-4242"/></figure>`,
	`<!-- /wp:image -->`,
].join( '\n' );

( async () => {
	const t = reporter( 'missing-image' );
	const { browser, page, errors } = await launch();
	await login( page );
	let id = 0;
	try {
		id = await createPost( page, { title: 'Missing image probe', content: CONTENT } );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="acme/hero"]', { timeout: 20000 } );
		const flagged = await page.waitForFunction( () =>
			!! document.querySelector( '.minn-island-preview img.minn-img-missing' ), null, { timeout: 20000 }
		).then( () => true ).catch( () => false );
		t.check( 'a dead image in a preview is flagged', flagged );

		const shape = await page.evaluate( () => {
			const prev = document.querySelector( '.minn-island-preview img' );
			const body = document.querySelector( '#minn-editor-body' );
			const content = [ ...body.querySelectorAll( 'figure img' ) ].filter( ( i ) => ! i.closest( '.minn-island-preview' ) );
			const cs = prev ? getComputedStyle( prev ) : null;
			return {
				previewSrc: prev ? prev.getAttribute( 'src' ) : '',
				illustrated: !! ( cs && /data:image\/svg/.test( cs.content || '' ) ),
				fixedSize: !! ( cs && cs.objectFit === 'none' ),
				contentImgs: content.length,
				contentFlagged: content.filter( ( i ) => i.classList.contains( 'minn-img-missing' ) ).length,
			};
		} );
		t.check( 'the illustration is what paints', shape.illustrated, JSON.stringify( shape ) );
		t.check( 'it draws at its own size, not stretched to the block', shape.fixedSize, JSON.stringify( shape ) );
		// The doorway matches the clicked image by src — losing it would break
		// replace-from-the-preview on exactly the images that need replacing.
		t.check( 'the src attribute is untouched', shape.previewSrc.endsWith( 'minn-no-such-image-9k1.png' ), shape.previewSrc );
		t.check( 'images in the content are never flagged', shape.contentImgs >= 1 && shape.contentFlagged === 0, JSON.stringify( shape ) );

		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, id );
		t.check( 'nothing about the placeholder reaches the saved markup', ! /minn-img-missing/.test( raw ), raw.slice( 0, 200 ) );
		t.check( 'both dead images keep their original address', ( raw.match( /minn-no-such-image-9k1\.png/g ) || [] ).length === 2, raw.slice( 0, 200 ) );
	} finally {
		if ( id ) await deletePost( page, id ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
