/**
 * Markdown typing rules: inline wraps, block prefixes, guards, undo.
 * Covers bindMarkdown() and the serializer branches it feeds.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'markdown' );
	await login( page );
	const postId = await createPost( page, {
		title: 'Markdown rules test',
		content: '<!-- wp:paragraph -->\n<p>Start here.</p>\n<!-- /wp:paragraph -->',
	} );
	await openEditor( page, postId );

	const lastHtml = () => page.evaluate( () => window.__minnTestPara.innerHTML );
	const lastBlock = () => page.evaluate( () => {
		// A terminal pre/quote grows a trailing affordance paragraph
		// (ensureTrailingParagraph) — skip it; the block under test is the
		// last CONTENT block.
		let el = document.querySelector( '#minn-editor-body' ).lastElementChild;
		if ( el.tagName === 'P' && ! el.textContent.trim() && el.previousElementSibling ) el = el.previousElementSibling;
		return { tag: el.tagName, html: el.innerHTML, prevTag: el.previousElementSibling ? el.previousElementSibling.tagName : null };
	} );
	const SP = '(?:&nbsp;| )'; // Chrome renders boundary spaces as nbsp entities in innerHTML

	await freshParagraph( page );
	await page.keyboard.type( 'This is **bold**more' );
	let h = await lastHtml();
	t.check( '**bold** wraps in <strong>', /This is <strong>bold<\/strong>/.test( h ), h );
	t.check( 'typing after a wrap escapes once', /<\/strong>more/.test( h ), h );

	await freshParagraph( page );
	await page.keyboard.type( 'an *ital* word' );
	h = await lastHtml();
	t.check( '*italic* wraps in <em>', new RegExp( `an <em>ital</em>${ SP }word` ).test( h ), h );

	await freshParagraph( page );
	await page.keyboard.type( 'use _word_ here' );
	h = await lastHtml();
	t.check( '_italic_ wraps in <em>', new RegExp( `use <em>word</em>${ SP }here` ).test( h ), h );

	await freshParagraph( page );
	await page.keyboard.type( 'my snake_case_name stays' );
	h = await lastHtml();
	t.check( 'snake_case stays literal', h.includes( 'snake_case_name' ) && ! h.includes( '<em>' ), h );

	await freshParagraph( page );
	await page.keyboard.type( 'go __big__ now' );
	h = await lastHtml();
	t.check( '__bold__ wraps in <strong>', new RegExp( `go <strong>big</strong>${ SP }now` ).test( h ), h );

	await freshParagraph( page );
	await page.keyboard.type( 'old ~~gone~~ new' );
	h = await lastHtml();
	t.check( '~~strike~~ wraps in <s>', new RegExp( `old <s>gone</s>${ SP }new` ).test( h ), h );

	await freshParagraph( page );
	await page.keyboard.type( 'see [docs](https://example.com) after' );
	h = await lastHtml();
	t.check( '[text](url) becomes a link', new RegExp( `see <a href="https://example\\.com">docs</a>${ SP }after` ).test( h ), h );

	await freshParagraph( page );
	await page.keyboard.type( 'call arr[0](x) fine' );
	h = await lastHtml();
	t.check( 'non-URL bracket-paren stays literal', h.includes( 'arr[0](x)' ) && ! h.includes( '<a ' ), h );

	await freshParagraph( page );
	await page.keyboard.type( '5 * 3 * 2 = 30' );
	h = await lastHtml();
	t.check( 'spaced stars stay literal', h.includes( '5 * 3 * 2 = 30' ) && ! h.includes( '<em>' ), h );

	await freshParagraph( page );
	await page.keyboard.type( '## Section title' );
	let b = await lastBlock();
	t.check( '## + space becomes H2', b.tag === 'H2' && /Section title/.test( b.html ), JSON.stringify( b ) );

	await freshParagraph( page );
	await page.keyboard.type( '- first item' );
	b = await lastBlock();
	t.check( '- + space becomes bullet list', b.tag === 'UL' && /first item/.test( b.html ), JSON.stringify( b ) );

	await freshParagraph( page );
	await page.keyboard.type( '1. first step' );
	b = await lastBlock();
	t.check( '1. + space becomes numbered list', b.tag === 'OL' && /first step/.test( b.html ), JSON.stringify( b ) );

	await freshParagraph( page );
	await page.keyboard.type( '> wise words' );
	b = await lastBlock();
	t.check( '> + space becomes quote', b.tag === 'BLOCKQUOTE' && /wise words/.test( b.html ), JSON.stringify( b ) );

	await freshParagraph( page );
	await page.keyboard.type( '```' );
	await page.keyboard.type( 'echo hi' );
	b = await lastBlock();
	t.check( '``` becomes a code block', b.tag === 'PRE' && /echo hi/.test( b.html ), JSON.stringify( b ) );

	await freshParagraph( page );
	await page.keyboard.type( '---' );
	await page.keyboard.type( 'after the line' );
	b = await lastBlock();
	t.check( '--- becomes a divider', b.prevTag === 'HR' && b.tag === 'P' && /after the line/.test( b.html ), JSON.stringify( b ) );

	await freshParagraph( page );
	await page.keyboard.type( 'undo **this**' );
	await page.keyboard.press( 'Meta+z' );
	h = await lastHtml();
	t.check( 'Cmd+Z unwinds a wrap to literal text', h.includes( '**this*' ) && ! h.includes( '<strong>' ), h );

	// Inline code used to be a direct-DOM wrap (outside Blink's undo stack)
	// because insertHTML rewrote <code> into a styled span. The wrap now
	// rides insertHTML + a trailing ZWSP so ⌘Z restores the backticks.
	// Type only through the closing tick (no trailing prose) so one ⌘Z
	// targets the wrap, same shape as the bold undo check above.
	await freshParagraph( page );
	await page.keyboard.type( 'see `code`' );
	h = await lastHtml();
	t.check( '`code` wraps in <code>',
		( /see(\s|&nbsp;)*<code>code<\/code>/.test( h ) ) && ! h.includes( '\u200B' ), h );
	await page.keyboard.press( 'Meta+z' );
	h = await lastHtml();
	t.check( 'Cmd+Z unwinds an inline code wrap to backticks',
		h.includes( '`code' ) && ! h.includes( '<code>' ), h );

	// Mid-sentence: wrap then type a space+letter; undo the letter/space first,
	// then the wrap.
	await freshParagraph( page );
	await page.keyboard.type( 'a `b`' );
	h = await lastHtml();
	t.check( 'mid-sentence `b` is a code chip', /<code>b<\/code>/.test( h ) && ! h.includes( '\u200B' ), h );
	await page.keyboard.type( ' c' );
	await page.keyboard.press( 'Meta+z' ); // undo "c" or " c"
	await page.keyboard.press( 'Meta+z' ); // may need a second for the space
	// Keep undoing until the wrap reverts or we hit a cap.
	for ( let i = 0; i < 4 && ( await lastHtml() ).includes( '<code>' ); i++ ) {
		await page.keyboard.press( 'Meta+z' );
	}
	h = await lastHtml();
	t.check( 'Cmd+Z unwinds mid-sentence code wrap',
		h.includes( '`b' ) && ! h.includes( '<code>' ), h );

	await freshParagraph( page );
	await page.dispatchEvent( '.minn-tool[data-cmd="bold"]', 'mousedown' );
	await page.keyboard.type( 'xy' );
	h = await lastHtml();
	t.check( 'toolbar bold typing stays bold', /<(b|strong)>xy<\/(b|strong)>/.test( h ), h );

	await freshParagraph( page );
	await page.keyboard.type( '```' );
	await page.keyboard.type( 'a **not bold** b' );
	b = await lastBlock();
	t.check( 'markdown stays literal inside code blocks', b.tag === 'PRE' && b.html.includes( 'a **not bold** b' ), JSON.stringify( b ) );

	await deletePost( page, postId );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( 'SCRIPT ERROR', e );
	process.exit( 2 );
} );
