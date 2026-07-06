/**
 * Internal link picker: the link popover's URL field searches your own
 * content when the text doesn't read as a URL — results from wp/v2/search,
 * keyboard navigation, pick-applies-immediately — while URL-shaped input
 * keeps the classic behavior. Verified against SAVED markup.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'link-picker' );
	await login( page );

	const target = await createPost( page, { title: 'Unique Linkable Target ZQX', content: '<!-- wp:paragraph --><p>Target.</p><!-- /wp:paragraph -->', status: 'publish' } );
	const id = await createPost( page, { title: 'Link picker probe', content: '<!-- wp:paragraph --><p>Read the announcement for details.</p><!-- /wp:paragraph -->', status: 'draft' } );

	await openEditor( page, id );

	/* ===== Select a word, ⌘K opens the popover ===== */
	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body p' );
		const tn = p.firstChild;
		const i = tn.textContent.indexOf( 'announcement' );
		const r = document.createRange();
		r.setStart( tn, i );
		r.setEnd( tn, i + 'announcement'.length );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus();
	} );
	await page.keyboard.press( 'Meta+k' );
	await page.waitForSelector( '.minn-link-pop', { timeout: 5000 } );
	t.check( 'popover opens on ⌘K with selection', true, '' );

	/* ===== Non-URL text searches own content ===== */
	await page.type( '.minn-link-pop [data-link-url]', 'Linkable Target ZQX', { delay: 15 } );
	await page.waitForSelector( '.minn-link-result', { timeout: 8000 } );
	const rows = await page.$$eval( '.minn-link-result', ( els ) => els.map( ( e ) => ( {
		title: e.querySelector( '.minn-link-result-title' ).textContent,
		type: e.querySelector( '.minn-link-result-type' ).textContent,
		url: e.dataset.url,
	} ) ) );
	t.check( 'search finds the target post', rows.some( ( r ) => r.title === 'Unique Linkable Target ZQX' && r.type === 'post' && /unique-linkable-target-zqx/.test( r.url ) ), JSON.stringify( rows ) );

	/* ===== Arrow + Enter picks and applies ===== */
	await page.keyboard.press( 'ArrowDown' );
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( () => ! document.querySelector( '.minn-link-pop' ), { timeout: 5000 } );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'picked link persists in saved markup', /<a href="[^"]*unique-linkable-target-zqx[^"]*">announcement<\/a>/.test( saved ), saved.slice( 0, 160 ) );

	/* ===== URL-shaped input keeps classic behavior (no results) ===== */
	await page.click( '#minn-editor-body a' );
	await page.waitForSelector( '.minn-link-pop', { timeout: 5000 } );
	await page.fill( '.minn-link-pop [data-link-url]', '' );
	await page.type( '.minn-link-pop [data-link-url]', 'https://example.com/page', { delay: 10 } );
	await page.waitForTimeout( 500 );
	t.check( 'URL-shaped input shows no search results', await page.$eval( '[data-link-results]', ( el ) => el.hidden ), '' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const saved2 = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'typed URL still applies classically', saved2.includes( '<a href="https://example.com/page">announcement</a>' ), saved2.slice( 0, 160 ) );

	await deletePost( page, id );
	await deletePost( page, target );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
