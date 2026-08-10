/**
 * Duplicating an editable image from its ⚙ popover — the path that applies
 * to images inside container slots, where the block is real editable DOM
 * rather than a protected card. The copy carries caption, classes and the
 * attachment id, lands right after the original, and saves as a second
 * real image block.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const IMG = ( n ) => `<!-- wp:image {"id":${ 950 + n },"sizeSlug":"large"} -->\n<figure class="wp-block-image size-large"><img src="${ BASE }/wp-content/uploads/gal-red.png" alt="" class="wp-image-${ 950 + n }"/><figcaption class="wp-element-caption">Caption ${ n }</figcaption></figure>\n<!-- /wp:image -->`;
const CONTENT = '<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">' + IMG( 1 ) + '\n\n<!-- wp:paragraph -->\n<p>After.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->';

( async () => {
	const t = reporter( 'image-duplicate' );
	const { browser, page, errors } = await launch();
	await login( page );

	let id = 0;
	try {
		id = await createPost( page, { title: 'Image duplicate probe', content: CONTENT } );
		t.check( 'fixture post created', id > 0, String( id ) );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-slot img', { timeout: 20000, state: 'attached' } );
		await page.waitForTimeout( 2000 );

		t.check( 'image inside the container is editable DOM, not a card',
			await page.evaluate( () => {
				const i = document.querySelector( '.minn-slot img' );
				return !! i && ! i.closest( '.minn-island-preview' );
			} ) );

		// The persistent ⚙ chip opens the image popover.
		const chip = await page.evaluate( () => {
			const img = document.querySelector( '.minn-slot img' );
			img.scrollIntoView( { block: 'center' } );
			return true;
		} );
		t.check( 'image located', chip );
		await page.waitForTimeout( 800 );
		const opened = await page.evaluate( () => {
			const c = [ ...document.querySelectorAll( '#minn-table-chips button, .minn-table-chip' ) ].find( ( b ) => b._kind === 'image' || /image/i.test( b.textContent ) );
			if ( ! c ) return false;
			c.click();
			return true;
		} );
		t.check( 'image chip opens its popover', opened && await page.waitForSelector( '[data-img-dup]', { timeout: 6000 } ).then( () => true ).catch( () => false ) );

		await page.click( '[data-img-dup]' );
		await page.waitForTimeout( 800 );
		const domCount = await page.evaluate( () => document.querySelectorAll( '.minn-slot figure.wp-block-image' ).length );
		t.check( 'a second image appears in the container', domCount === 2, String( domCount ) );

		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3500 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return ( j.content && j.content.raw ) || '';
		}, id );
		const imgBlocks = ( raw.match( /<!-- wp:image/g ) || [] ).length;
		t.check( 'saved as two real image blocks', imgBlocks === 2, String( imgBlocks ) );
		t.check( 'the copy kept the caption and attachment id',
			( raw.match( /Caption 1/g ) || [] ).length === 2 && ( raw.match( /wp-image-951/g ) || [] ).length === 2 );
		t.check( 'the paragraph after it survived', raw.indexOf( '<p>After.</p>' ) !== -1 );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
