/**
 * Paste cleanup: Word / Google Docs / web HTML → safe-subset markup.
 * Covers sanitizePastedHtml(), the caret-context routing in pasteInsert(),
 * the bracket strategy in pasteBlocksInsert(), and cleanLeadingNbsp() —
 * always against SAVED content, not just the editor DOM.
 *
 * Most cases dispatch a synthetic ClipboardEvent (the handler reads
 * e.clipboardData, so the full pipeline runs); one case goes through the real
 * OS clipboard + ⌘V to prove the wiring end to end.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

const DOCS_FIXTURE = `<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-1234abcd"><p dir="ltr" style="line-height:1.38;margin-top:0pt;margin-bottom:0pt;"><span style="font-size:11pt;font-family:Arial,sans-serif;color:#000000;background-color:transparent;font-weight:400;font-style:normal;white-space:pre-wrap;">First paragraph with </span><span style="font-size:11pt;font-family:Arial,sans-serif;font-weight:700;white-space:pre-wrap;">bold text</span><span style="font-size:11pt;font-weight:400;white-space:pre-wrap;"> and </span><span style="font-size:11pt;font-style:italic;white-space:pre-wrap;">italics</span><span style="font-size:11pt;white-space:pre-wrap;">.</span></p><h2 dir="ltr" style="line-height:1.38;"><span style="font-size:16pt;font-family:Arial,sans-serif;font-weight:400;white-space:pre-wrap;">A Section Heading</span></h2><ul style="margin-top:0;margin-bottom:0;"><li dir="ltr" style="list-style-type:disc;" aria-level="1"><p dir="ltr" style="line-height:1.38;" role="presentation"><span style="white-space:pre-wrap;">First bullet</span></p></li><li dir="ltr" aria-level="1"><p dir="ltr" role="presentation"><span style="white-space:pre-wrap;">Second bullet with </span><span style="font-family:'Courier New',monospace;white-space:pre-wrap;">mono_code</span></p></li></ul></b>`;

const WORD_FIXTURE = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word"><head><meta name=ProgId content=Word.Document><style><!-- p.MsoNormal {mso-style-parent:"";} --></style></head><body lang=EN-US><p class=MsoNormal>Intro paragraph with <b>bold</b> and <i>italic</i>.<o:p></o:p></p><p class=MsoListParagraphCxSpFirst style='text-indent:-.25in;mso-list:l0 level1 lfo1'><span style='font-family:Symbol;mso-list:Ignore'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp;&nbsp; </span></span>Bullet one<o:p></o:p></p><p class=MsoListParagraphCxSpMiddle style='text-indent:-.25in;mso-list:l0 level2 lfo1'><span style='font-family:"Courier New";mso-list:Ignore'>o<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp; </span></span>Nested bullet<o:p></o:p></p><p class=MsoListParagraphCxSpLast style='text-indent:-.25in;mso-list:l1 level1 lfo2'><span style='mso-list:Ignore'>1.<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp; </span></span>Numbered item<o:p></o:p></p><p class=MsoNormal>&nbsp;</p><table class=MsoTableGrid border=1 cellspacing=0 cellpadding=0><tr><td width=312 valign=top><p class=MsoNormal>Cell A<o:p></o:p></p></td><td width=312 valign=top><p class=MsoNormal>Cell B<o:p></o:p></p></td></tr></table><p class=MsoNormal>After table.<o:p></o:p></p></body></html>`;

const WEB_FIXTURE = `<h1 class="entry-title" style="font-size:42px">Article Title</h1><p>Intro with <a href="https://example.com/x" target="_blank" onclick="evil()">a link</a>, <code>inline code</code> and <a href="javascript:alert(1)">bad link</a>.</p><script>alert(1)</script><style>.x{color:red}</style><pre class="language-js"><code>const x = 1;
console.log(x);</code></pre><blockquote><p>Quoted wisdom.</p></blockquote><figure><img src="https://example.com/pic.jpg" alt="Pic"><figcaption>The caption</figcaption></figure><img src="data:image/png;base64,AAAA"><table><thead><tr><th>H1</th><th>H2</th></tr></thead><tbody><tr><td>a</td><td>b</td></tr></tbody></table><hr><p style="text-align: center;">centered text</p><div><div>div soup paragraph</div></div>`;

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'paste' );
	await login( page );

	// Synthetic paste at the current caret. The handler consumes
	// e.clipboardData, so this exercises the full sanitize/insert pipeline.
	const paste = ( flavors ) => page.evaluate( ( f ) => {
		const body = document.querySelector( '#minn-editor-body' );
		const dt = new DataTransfer();
		for ( const [ k, v ] of Object.entries( f ) ) dt.setData( k, v );
		const ev = new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } );
		body.dispatchEvent( ev );
		return ev.defaultPrevented;
	}, flavors );

	const caretIn = ( sel, offset ) => page.evaluate( ( a ) => {
		const body = document.querySelector( '#minn-editor-body' );
		const target = body.querySelector( a.sel );
		if ( ! target ) return { ok: false, why: 'no target for ' + a.sel, body: body.innerHTML.slice( 0, 200 ) };
		const node = target.firstChild && target.firstChild.nodeType === 3 ? target.firstChild : target;
		const r = document.createRange();
		r.setStart( node, a.offset );
		r.collapse( true );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		body.focus();
		return { ok: true, text: target.textContent.slice( 0, 40 ) };
	}, { sel, offset } );

	// ⌘S then poll the SAVED content until it changes — a flat wait reads
	// mid-save state (saves ride a serialized chain and REST writes carry
	// every active plugin's hooks; the editor-sidebar/shortcuts lesson).
	// Every call site pastes fresh content first, so "changed" is the signal.
	const save = async ( id ) => {
		const before = await savedRaw( id );
		await page.keyboard.press( 'Meta+s' );
		const start = Date.now();
		let raw = await savedRaw( id );
		while ( raw === before && Date.now() - start < 12000 ) {
			await page.waitForTimeout( 400 );
			raw = await savedRaw( id );
		}
		return raw;
	};
	const savedRaw = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	/* ===== Google Docs into a blocks post ===== */
	const docsId = await createPost( page, { title: 'Paste: Docs', content: '<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, docsId );
	await freshParagraph( page );
	await paste( { 'text/html': DOCS_FIXTURE, 'text/plain': 'x' } );
	let raw = await save( docsId );
	t.check( 'Docs: bold/italic map to strong/em', /<strong>bold text<\/strong> and <em>italics<\/em>/.test( raw ), raw );
	t.check( 'Docs: heading becomes a heading block', /<!-- wp:heading \{"level":2\} -->\n<h2 class="wp-block-heading">A Section Heading<\/h2>/.test( raw ), raw );
	t.check( 'Docs: list with list-item blocks', /<!-- wp:list -->[\s\S]*<!-- wp:list-item -->[\s\S]*First bullet[\s\S]*<!-- \/wp:list -->/.test( raw ), raw );
	t.check( 'Docs: mono font becomes inline code', /<code>mono_code<\/code>/.test( raw ), raw );
	t.check( 'Docs: no vendor markup survives', ! /docs-internal-guid|<span|style="|dir="ltr"|font-weight/.test( raw ), raw );

	/* ===== Word into a blocks post ===== */
	const wordId = await createPost( page, { title: 'Paste: Word', content: '<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, wordId );
	await freshParagraph( page );
	await paste( { 'text/html': WORD_FIXTURE, 'text/plain': 'x' } );
	raw = await save( wordId );
	t.check( 'Word: mso list run becomes a nested ul', /<ul[^>]*><!-- wp:list-item -->\n<li>Bullet one<ul><li>Nested bullet<\/li><\/ul><\/li>\n<!-- \/wp:list-item --><\/ul>/.test( raw.replace( / class="wp-block-list"/g, '' ) ), raw );
	t.check( 'Word: separate mso list id becomes its own ol', /<!-- wp:list \{"ordered":true\} -->[\s\S]*Numbered item/.test( raw ), raw );
	t.check( 'Word: table becomes a table block', /<!-- wp:table -->[\s\S]*<td>Cell A<\/td><td>Cell B<\/td>/.test( raw ), raw );
	t.check( 'Word: nbsp spacer paragraph dropped, o:p gone', ! /o:p|<p> <\/p>|&nbsp;<\/p>/.test( raw ), raw );

	/* ===== Web page into a blocks post, mid-paragraph ===== */
	const webId = await createPost( page, { title: 'Paste: Web', content: '<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, webId );
	const caretDiag = await caretIn( 'p', 5 ); // between "Hello" and " world"
	const pasteDiag = await paste( { 'text/html': WEB_FIXTURE, 'text/plain': 'x' } );
	raw = await save( webId );
	t.check( 'Web: paste handler took the event', pasteDiag === true, `caret=${ JSON.stringify( caretDiag ) } prevented=${ pasteDiag }` );
	t.check( 'Web: caret paragraph splits around the paste', /<p>Hello<\/p>/.test( raw ) && /<p> ?world<\/p>/.test( raw ), raw );
	t.check( 'Web: split-off tail carries no nbsp indent', ! /<p> /.test( raw ), raw );
	t.check( 'Web: h1 survives as a level-1 heading block', /<!-- wp:heading \{"level":1\} -->/.test( raw ), raw );
	t.check( 'Web: link keeps href only; js: link demoted to text', /<a href="https:\/\/example.com\/x">a link<\/a>/.test( raw ) && ! /javascript:|onclick|target=/.test( raw ), raw );
	t.check( 'Web: code block keeps its language', /<pre class="wp-block-code"><code class="language-js">const x = 1;\nconsole\.log\(x\);<\/code><\/pre>/.test( raw ), raw );
	t.check( 'Web: quote block', /<!-- wp:quote -->[\s\S]*Quoted wisdom/.test( raw ), raw );
	t.check( 'Web: image with caption, data: image dropped', /<!-- wp:image -->[\s\S]*pic\.jpg[\s\S]*<figcaption class="wp-element-caption">The caption<\/figcaption>/.test( raw ) && ! /data:image/.test( raw ), raw );
	t.check( 'Web: table keeps its header section', /<thead><tr><th>H1<\/th><th>H2<\/th><\/tr><\/thead>/.test( raw ), raw );
	t.check( 'Web: hr becomes separator, centered p keeps alignment', /<!-- wp:separator -->/.test( raw ) && /<!-- wp:paragraph \{"align":"center"\} -->/.test( raw ), raw );
	t.check( 'Web: script/style stripped', ! /script|alert|color:red/.test( raw ), raw );
	t.check( 'Web: no paste-bracket markers reach the database', ! /data-minn-bkt/.test( raw ), raw );

	/* ===== Undo: one ⌘Z reverts the whole rich paste ===== */
	const undoId = await createPost( page, { title: 'Paste: Undo', content: '<!-- wp:paragraph -->\n<p>Alpha beta</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, undoId );
	const before = await page.evaluate( () => document.querySelector( '#minn-editor-body' ).innerHTML );
	await caretIn( 'p', 5 );
	await paste( { 'text/html': '<h2>Pasted</h2><pre><code>x=1</code></pre>', 'text/plain': 'x' } );
	const mid = await page.evaluate( () => document.querySelector( '#minn-editor-body' ).innerHTML );
	await page.keyboard.press( 'Meta+z' );
	const after = await page.evaluate( () => document.querySelector( '#minn-editor-body' ).innerHTML );
	t.check( 'paste inserted blocks', /<h2>Pasted<\/h2>/.test( mid ), mid );
	t.check( 'one undo restores the exact original body', after === before, `before=${ before } after=${ after }` );

	/* ===== Caret-context routing ===== */
	const ctxId = await createPost( page, {
		title: 'Paste: Contexts',
		content: '<!-- wp:list -->\n<ul class="wp-block-list"><!-- wp:list-item -->\n<li>first</li>\n<!-- /wp:list-item --><!-- wp:list-item -->\n<li>second</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->\n\n<!-- wp:heading -->\n<h2 class="wp-block-heading">Head line</h2>\n<!-- /wp:heading -->\n\n<!-- wp:code -->\n<pre class="wp-block-code"><code>seed</code></pre>\n<!-- /wp:code -->',
	} );
	await openEditor( page, ctxId );
	await caretIn( 'li', 5 ); // end of "first"
	await paste( { 'text/html': '<ul><li>merged A</li><li>merged B</li></ul>', 'text/plain': 'x' } );
	await caretIn( 'h2', 4 );
	await paste( { 'text/html': '<p>one</p><h3>two</h3>', 'text/plain': 'x' } );
	await caretIn( 'pre code', 2 );
	await paste( { 'text/html': '<p><b>rich</b> stuff</p>', 'text/plain': 'plain\ntext' } );
	raw = await save( ctxId );
	t.check( 'list pasted into list merges items', ( raw.match( /<!-- wp:list-item -->/g ) || [] ).length === 4 && /merged A/.test( raw ), raw );
	t.check( 'blocks pasted into heading flatten to text', /<h2 class="wp-block-heading">Headone two line<\/h2>/.test( raw ), raw );
	t.check( 'rich paste into code block takes plain text, newline intact', /<code>seplain\ntexted<\/code>/.test( raw ), raw );

	/* ===== Plain text ===== */
	const textId = await createPost( page, { title: 'Paste: Text', content: '<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, textId );
	await freshParagraph( page );
	const preventedMulti = await paste( { 'text/plain': 'para one line one\nline two\n\npara two' } );
	const preventedSingle = await paste( { 'text/plain': 'just one line' } );
	raw = await save( textId );
	t.check( 'multi-line text becomes paragraphs with <br>', /<p>para one line one<br>line two<\/p>/.test( raw ) && /<p>para two<\/p>/.test( raw ), raw );
	t.check( 'single-line text keeps native handling', preventedMulti === true && preventedSingle === false, `multi=${ preventedMulti } single=${ preventedSingle }` );

	/* ===== Classic mode ===== */
	const classicId = await createPost( page, { title: 'Paste: Classic', content: '<p>Classic paragraph.</p>' } );
	await openEditor( page, classicId );
	await freshParagraph( page );
	await paste( { 'text/html': DOCS_FIXTURE, 'text/plain': 'x' } );
	raw = await save( classicId );
	t.check( 'classic: sanitized markup, no block comments introduced', ! /<!-- wp:/.test( raw ) && /<strong>bold text<\/strong>/.test( raw ) && /<h2>A Section Heading<\/h2>/.test( raw ), raw );
	t.check( 'classic: no vendor spans/styles', ! /<span|style="/.test( raw ), raw );

	/* ===== Real clipboard end-to-end (permissions + ⌘V) ===== */
	const e2eId = await createPost( page, { title: 'Paste: E2E', content: '<!-- wp:paragraph -->\n<p>Start.</p>\n<!-- /wp:paragraph -->' } );
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await openEditor( page, e2eId );
	await freshParagraph( page );
	await page.evaluate( async ( html ) => {
		await navigator.clipboard.write( [ new ClipboardItem( {
			'text/html': new Blob( [ html ], { type: 'text/html' } ),
			'text/plain': new Blob( [ 'fallback' ], { type: 'text/plain' } ),
		} ) ] );
	}, '<h2>Real clipboard heading</h2><p>With <b>bold</b>.</p>' );
	await page.keyboard.press( 'Meta+v' );
	raw = await save( e2eId );
	t.check( 'real ⌘V paste lands sanitized', /<h2 class="wp-block-heading">Real clipboard heading<\/h2>/.test( raw ) && /<strong>bold<\/strong>/.test( raw ), raw );

	/* ===== Paste URL over selection → hyperlink (keep selected words) ===== */
	const linkId = await createPost( page, {
		title: 'Paste: Link over selection',
		content: '<!-- wp:paragraph -->\n<p>Until today, with Grok Build and a skill.</p>\n<!-- /wp:paragraph -->',
	} );
	await openEditor( page, linkId );
	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body p' );
		const tn = p.firstChild;
		const i = tn.textContent.indexOf( 'Grok Build' );
		const r = document.createRange();
		r.setStart( tn, i );
		r.setEnd( tn, i + 'Grok Build'.length );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus();
	} );
	const linkPrevented = await paste( {
		'text/plain': 'https://x.ai/grok',
		// Browser-copied URLs often carry a matching <a> HTML flavor — still
		// must wrap the selection, not replace it with the URL text.
		'text/html': '<a href="https://x.ai/grok">https://x.ai/grok</a>',
	} );
	raw = await save( linkId );
	t.check( 'URL paste over selection is intercepted', linkPrevented === true, `prevented=${ linkPrevented }` );
	t.check(
		'URL paste hyperlinks the selected words',
		/<a href="https:\/\/x\.ai\/grok">Grok Build<\/a>/.test( raw )
		&& /Until today, with /.test( raw )
		&& / and a skill/.test( raw ),
		raw
	);
	t.check( 'URL paste does not replace selection with the URL string', ! /with https:\/\/x\.ai\/grok and/.test( raw ), raw );

	// Non-URL paste over selection must not be force-linked. Synthetic
	// single-line pastes do not run Chrome's native insert (same as the
	// "single-line keeps native handling" check above), so we only pin that
	// createLink was not applied.
	const plainId = await createPost( page, {
		title: 'Paste: Plain over selection',
		content: '<!-- wp:paragraph -->\n<p>Replace WORD here.</p>\n<!-- /wp:paragraph -->',
	} );
	await openEditor( page, plainId );
	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body p' );
		const tn = p.firstChild;
		const i = tn.textContent.indexOf( 'WORD' );
		const r = document.createRange();
		r.setStart( tn, i );
		r.setEnd( tn, i + 4 );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus();
	} );
	const plainPrevented = await paste( { 'text/plain': 'not a url at all' } );
	const plainDom = await page.evaluate( () => document.querySelector( '#minn-editor-body p' ).innerHTML );
	t.check(
		'non-URL paste over selection is not forced into a link',
		plainPrevented === false && ! /<a[\s>]/i.test( plainDom ) && /WORD/.test( plainDom ),
		`prevented=${ plainPrevented } dom=${ plainDom }`
	);
	raw = await save( plainId );
	t.check( 'non-URL paste leaves the words unlinked in saved markup', /Replace WORD here\./.test( raw ) && ! /<a /.test( raw ), raw );

	for ( const id of [ docsId, wordId, webId, undoId, ctxId, textId, classicId, e2eId, linkId, plainId ] ) await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
