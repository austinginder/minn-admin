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

	/* ===== Open in new tab switch ===== */
	await page.click( '#minn-editor-body a' );
	await page.waitForSelector( '.minn-link-pop [data-link-newtab]', { timeout: 5000 } );
	t.check( 'new-tab switch is offered', await page.$eval( '.minn-link-pop [data-link-newtab]', ( el ) => el.getAttribute( 'role' ) === 'switch' ), '' );
	await page.click( '.minn-link-pop [data-link-newtab]' );
	t.check( 'switch turns on', await page.$eval( '.minn-link-pop [data-link-newtab]', ( el ) => el.classList.contains( 'on' ) ), '' );
	await page.click( '.minn-link-pop [data-link-apply]' );
	await page.waitForFunction( () => ! document.querySelector( '.minn-link-pop' ), { timeout: 5000 } );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const savedTab = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'new tab rides target + rel on saved link',
		/<a href="https:\/\/example\.com\/page"[^>]*target="_blank"[^>]*rel="noreferrer noopener"[^>]*>announcement<\/a>/.test( savedTab )
		|| /<a href="https:\/\/example\.com\/page"[^>]*rel="noreferrer noopener"[^>]*target="_blank"[^>]*>announcement<\/a>/.test( savedTab ),
		savedTab.slice( 0, 220 ) );
	// Toggle off and confirm target/rel drop.
	await page.click( '#minn-editor-body a' );
	await page.waitForSelector( '.minn-link-pop [data-link-newtab].on', { timeout: 5000 } );
	t.check( 'existing _blank seeds the switch on', await page.$eval( '.minn-link-pop [data-link-newtab]', ( el ) => el.classList.contains( 'on' ) && el.getAttribute( 'aria-checked' ) === 'true' ), '' );
	await page.click( '.minn-link-pop [data-link-newtab]' );
	await page.click( '.minn-link-pop [data-link-apply]' );
	await page.waitForFunction( () => ! document.querySelector( '.minn-link-pop' ), { timeout: 5000 } );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const savedOff = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'clearing new-tab drops target/rel',
		savedOff.includes( '<a href="https://example.com/page">announcement</a>' )
		&& ! /target="_blank"/.test( savedOff ),
		savedOff.slice( 0, 220 ) );

	/* ===== Apply deep in a long post must not scroll to the top =====
	   (body.focus() without preventScroll scrolls the contenteditable's TOP
	   into view before the saved range restores.) ===== */
	const longId = await createPost( page, { title: 'Deep link probe', content:
		'<!-- wp:paragraph --><p>' + 'Filler to push the target far down the document. '.repeat( 250 ) + '</p><!-- /wp:paragraph -->'
		+ '<!-- wp:paragraph --><p>Read the announcement for details.</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, longId );
	await page.evaluate( () => {
		const p = document.querySelectorAll( '#minn-editor-body > p' )[ 1 ];
		p.scrollIntoView( { block: 'center' } );
		const tn = p.firstChild;
		const i = tn.textContent.indexOf( 'announcement' );
		const r = document.createRange();
		r.setStart( tn, i );
		r.setEnd( tn, i + 'announcement'.length );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus( { preventScroll: true } );
	} );
	const scrollBefore = await page.$eval( '.minn-scroll', ( s ) => s.scrollTop );
	await page.keyboard.press( 'Meta+k' );
	await page.waitForSelector( '.minn-link-pop', { timeout: 5000 } );
	await page.type( '.minn-link-pop [data-link-url]', 'https://example.com/deep', { delay: 10 } );
	await page.keyboard.press( 'Enter' );
	await page.waitForTimeout( 400 );
	const scrollAfter = await page.$eval( '.minn-scroll', ( s ) => s.scrollTop );
	t.check( 'Apply keeps the scroll position', Math.abs( scrollAfter - scrollBefore ) < 60 && scrollBefore > 500, `before=${ scrollBefore } after=${ scrollAfter }` );

	await deletePost( page, id );
	await deletePost( page, target );
	await deletePost( page, longId );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
