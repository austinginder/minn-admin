/**
 * The synced-pattern doorway answers only Minn's own cover. Post content can
 * wear data-patternedit (kses keeps data-* for every role), and a planted
 * one took every click on its text away to a pattern of its choosing,
 * saving the post on the way out. A planted attribute is now ordinary
 * content; the real cover still opens the pattern it references.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'pattern-doorway-guard' );
	await login( page );

	const rest = ( method, path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, { method: a.method, headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, body: a.body ? JSON.stringify( a.body ) : undefined } );
		return r.json();
	}, { method, path, body } );

	const pattern = await rest( 'POST', 'wp/v2/blocks', { title: 'Doorway guard pattern', status: 'publish', content: '<!-- wp:paragraph --><p>Pattern body.</p><!-- /wp:paragraph -->' } );
	const decoy = await rest( 'POST', 'wp/v2/blocks', { title: 'Doorway decoy pattern', status: 'publish', content: '<!-- wp:paragraph --><p>Decoy.</p><!-- /wp:paragraph -->' } );
	const id = await createPost( page, { title: 'Pattern doorway probe', status: 'draft', content:
		`<!-- wp:paragraph --><p data-patternedit="${ decoy.id }" style="padding:2em 0">Planted text to type into.</p><!-- /wp:paragraph -->`
		+ `<!-- wp:block {"ref":${ pattern.id }} /-->` } );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '#minn-editor-body .minn-pattern-cover', { timeout: 15000 } );
		const start = page.url();

		/* ===== A planted doorway is ordinary text ===== */
		await page.click( '#minn-editor-body p[data-patternedit]' );
		await page.waitForTimeout( 1500 );
		t.check( 'a click on planted data-patternedit stays in the post', page.url() === start && ! page.url().includes( 'editor/blocks/' + decoy.id ), page.url() );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' typed' );
		t.check( 'and its text takes typing', await page.$eval( '#minn-editor-body p[data-patternedit]', ( p ) => p.textContent.includes( 'typed' ) ) );

		/* ===== The real cover still opens its own pattern ===== */
		await page.click( '#minn-editor-body .minn-pattern-cover' );
		await page.waitForFunction( ( ref ) => location.href.includes( 'editor/blocks/' + ref ), pattern.id, { timeout: 30000 } ).catch( () => {} );
		t.check( "control: Minn's cover opens the referenced pattern", page.url().includes( 'editor/blocks/' + pattern.id ), page.url() );
	} finally {
		await deletePost( page, id );
		await rest( 'DELETE', `wp/v2/blocks/${ pattern.id }?force=true` );
		await rest( 'DELETE', `wp/v2/blocks/${ decoy.id }?force=true` );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
