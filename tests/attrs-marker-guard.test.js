/**
 * A block's comment attributes ride on its element as data-minn-attrs while
 * it is edited. Post content can wear that attribute too (kses keeps data-*
 * for every role), and a planted one was read back as Minn's own marker, so
 * an Editor's next save wrote it into the block comment, where
 * filter_block_kses never saw it. Minn's markers now carry the page's token;
 * one without it is content's and is no block's attributes. Verified against
 * SAVED markup, with a real marker beside it as the control.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const PLANTED = '{&quot;someAttr&quot;:&quot;planted-attr-value&quot;}';
const CONTENT = `<!-- wp:paragraph --><p data-minn-attrs="${ PLANTED }">Planted paragraph.</p><!-- /wp:paragraph -->`
	+ '<!-- wp:paragraph {"fontSize":"large"} --><p class="has-large-font-size">Styled paragraph.</p><!-- /wp:paragraph -->'
	+ `<!-- wp:image --><figure class="wp-block-image" data-minn-attrs="${ PLANTED }"><img src="https://example.com/a.png" alt=""/></figure><!-- /wp:image -->`;

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'attrs-marker-guard' );
	await login( page );

	const id = await createPost( page, { title: 'Attrs marker probe', content: CONTENT, status: 'draft' } );
	try {
		await openEditor( page, id );
		await page.waitForSelector( '#minn-editor-body p', { timeout: 15000 } );
		// A save that touches nothing in the body: the title only.
		await page.click( '#minn-editor-title' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' edited' );
		const wait = page.waitForResponse( ( r ) => r.request().method() === 'POST' && r.url().includes( 'wp/v2/posts/' + id ), { timeout: 60000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, id );
		const comments = ( raw.match( /<!-- wp:[^>]*-->/g ) || [] ).join( ' ' );
		t.check( 'a planted marker never becomes block-comment attributes', ! comments.includes( 'someAttr' ) && ! comments.includes( 'planted-attr-value' ), comments );
		t.check( "control: a real block's own attributes still ride through", /<!-- wp:paragraph \{"fontSize":"large"\} -->/.test( raw ), comments );
		t.check( 'no page token reaches the database', ! /data-minn-attrs="m[a-z0-9]+:/.test( raw ), raw.slice( 0, 300 ) );
	} finally {
		await deletePost( page, id );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
