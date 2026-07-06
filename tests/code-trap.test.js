/**
 * Terminal-block caret trap: a pre/table/figure/island as the LAST block left
 * nothing below to click. ensureTrailingParagraph keeps one empty affordance
 * paragraph after any terminal non-paragraph block — present in the DOM,
 * absent from SAVED markup. Also: the code-language select lives only in the
 * code chip popover now (the toolbar copy is gone).
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'code-trap' );
	await login( page );

	/* ===== Post ENDING in a code block gets the affordance paragraph ===== */
	const id = await createPost( page, { title: 'Code trap probe', content:
		'<!-- wp:paragraph --><p>Intro.</p><!-- /wp:paragraph -->'
		+ '<!-- wp:code --><pre class="wp-block-code"><code>echo "last block";</code></pre><!-- /wp:code -->', status: 'draft' } );
	await openEditor( page, id );
	const dom = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const last = body.lastElementChild;
		return { lastTag: last.tagName, empty: ! last.textContent.trim(), toolbarSelect: !! document.querySelector( '#minn-code-lang' ) };
	} );
	t.check( 'trailing paragraph appended after terminal code block', dom.lastTag === 'P' && dom.empty, JSON.stringify( dom ) );
	t.check( 'toolbar language select is gone', ! dom.toolbarSelect, '' );

	/* ===== Untouched, it never reaches the database ===== */
	await page.click( '#minn-editor-body p' ); // caret in Intro
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const saved1 = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'affordance paragraph never serializes', ! /<p><\/p>|<p><br\s*\/?><\/p>/.test( saved1 ) && saved1.includes( 'last block' ) && saved1.trim().endsWith( '<!-- /wp:code -->' ), saved1.slice( -80 ) );

	/* ===== Typed into, it becomes a real paragraph after the code ===== */
	await page.click( '#minn-editor-body > p:last-child' );
	await page.keyboard.type( 'After the code.', { delay: 15 } );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const saved2 = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'typed-into affordance saves as a paragraph after the code', /<!-- \/wp:code -->\s*<!-- wp:paragraph -->\s*<p>After the code\.<\/p>/.test( saved2 ), saved2.slice( -140 ) );

	/* ===== Live trap: ``` conversion as the last act springs a new one ===== */
	const id2 = await createPost( page, { title: 'Live trap probe', content: '<!-- wp:paragraph --><p>Start.</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, id2 );
	await freshParagraph( page );
	await page.keyboard.type( '```', { delay: 30 } );
	await page.waitForTimeout( 600 ); // conversion + stats cadence
	const live = await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		return { hasPre: !! body.querySelector( 'pre' ), lastTag: body.lastElementChild.tagName };
	} );
	t.check( '``` conversion immediately regrows the affordance paragraph', live.hasPre && live.lastTag === 'P', JSON.stringify( live ) );

	/* ===== Persistent chip: present with no hover/caret, survives scroll,
	   opens the popover, label tracks the language ===== */
	const chipState = () => page.evaluate( () => {
		const chip = [ ...document.querySelectorAll( '#minn-table-chips .minn-code-chip' ) ]
			.find( ( c ) => c._target && c._target.tagName === 'PRE' );
		return chip ? { text: chip.textContent.trim(), visible: chip.style.visibility !== 'hidden' } : null;
	} );
	const idle = await chipState();
	t.check( 'code chip is persistent (no hover or caret needed)', !! idle && idle.visible && /code/.test( idle.text ), JSON.stringify( idle ) );
	await page.evaluate( () => { document.querySelector( '.minn-scroll' ).scrollTop += 120; } );
	await page.waitForTimeout( 300 );
	const scrolled = await chipState();
	t.check( 'chip survives scrolling (no flicker-hide)', !! scrolled && scrolled.visible, JSON.stringify( scrolled ) );
	await page.evaluate( () => {
		[ ...document.querySelectorAll( '#minn-table-chips .minn-code-chip' ) ]
			.find( ( c ) => c._target && c._target.tagName === 'PRE' ).click();
	} );
	await page.waitForSelector( '.minn-code-pop [data-lang]', { timeout: 5000 } );
	t.check( 'chip click opens the code popover', true, '' );
	const pos = await page.evaluate( () => {
		const pop = document.querySelector( '.minn-code-pop' ).getBoundingClientRect();
		const pre = document.querySelector( '#minn-editor-body pre' ).getBoundingClientRect();
		return { beside: pop.left >= pre.right + 4, below: pop.top >= pre.bottom + 4 };
	} );
	t.check( 'popover sits beside or below the block, never on it', pos.beside || pos.below, JSON.stringify( pos ) );
	await page.selectOption( '.minn-code-pop [data-lang]', 'php' );
	await page.waitForTimeout( 400 );
	const relabeled = await chipState();
	t.check( 'chip label tracks the picked language', !! relabeled && /php/.test( relabeled.text ), JSON.stringify( relabeled ) );
	await page.keyboard.press( 'Escape' );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	const saved3 = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id2 );
	t.check( 'picked language persists, no hover styles leak', /language-php/.test( saved3 ) && ! /border-color/.test( saved3 ), saved3.slice( -120 ) );

	await deletePost( page, id );
	await deletePost( page, id2 );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
