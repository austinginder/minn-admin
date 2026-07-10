/**
 * Find & replace — the ⌘F bar over the editor body.
 *
 * The contract under test: matching is over the text writers see (across
 * inline formatting like a split <strong>, never across blocks), islands
 * and any contenteditable=false subtree are excluded and pass through
 * byte-identical, highlights are overlay rects that never touch the typing
 * surface, and every replace runs through execCommand so a single ⌘Z
 * reverts it. Replace-all runs last-to-first so earlier ranges stay valid.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

// An UNREGISTERED block always islands (contenteditable=false, byte-identity
// contract) — wp:html would NOT do here, Minn treats its div as editable prose.
const ISLAND = '<!-- wp:acme/find-fixture {"tone":"quick"} --><div class="acme-find">quick brown island text</div><!-- /wp:acme/find-fixture -->';
const CONTENT = [
	'<!-- wp:paragraph --><p>The quick brown fox jumps over the lazy dog.</p><!-- /wp:paragraph -->',
	'<!-- wp:paragraph --><p>A qui<strong>ck bro</strong>wn shard, then quick brown again.</p><!-- /wp:paragraph -->',
	'<!-- wp:code --><pre class="wp-block-code"><code>const speed = "Quick Brown";</code></pre><!-- /wp:code -->',
	ISLAND,
].join( '\n\n' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'find-replace' );

	await login( page );
	let pid = null;

	const barText = ( sel ) => page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		return el ? el.textContent : null;
	}, sel );
	const bodyText = () => page.evaluate( () => document.querySelector( '#minn-editor-body' ).textContent );
	const count = () => barText( '#minn-find-count' );
	const fetchRaw = () => page.evaluate( async ( id ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ id }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, pid );

	try {
		pid = await createPost( page, { title: 'Find & Replace Fixture', content: CONTENT } );
		await openEditor( page, pid );

		// --- Open + count -----------------------------------------------------
		await page.keyboard.press( 'Meta+f' );
		await page.waitForSelector( '#minn-find-bar', { timeout: 5000 } );
		t.check( '⌘F opens the find bar', true );

		await page.type( '#minn-find-input', 'quick brown' );
		await page.waitForFunction( () => document.querySelector( '#minn-find-count' ).textContent === '1/4', null, { timeout: 5000 } );
		// 4 = p1 (1) + p2 (2, one across the split <strong>) + code (1,
		// case-insensitive). The island's "quick brown" must NOT count.
		t.check( 'Counts 4 matches — across inline marks, case-insensitive, island excluded', true );
		const marks = await page.evaluate( () => ( {
			total: document.querySelectorAll( '.minn-find-mark' ).length,
			cur: document.querySelectorAll( '.minn-find-mark.cur' ).length,
			inBody: document.querySelectorAll( '#minn-editor-body .minn-find-mark' ).length,
		} ) );
		t.check( 'Highlight marks render as overlays, never inside the typing surface', marks.total >= 4 && marks.cur >= 1 && marks.inBody === 0, JSON.stringify( marks ) );

		// --- Navigation -------------------------------------------------------
		await page.keyboard.press( 'Enter' );
		t.check( 'Enter steps to the next match', ( await count() ) === '2/4', await count() );
		await page.keyboard.press( 'Shift+Enter' );
		t.check( '⇧Enter steps back', ( await count() ) === '1/4', await count() );
		await page.keyboard.press( 'Shift+Enter' );
		t.check( 'Navigation wraps around', ( await count() ) === '4/4', await count() );

		// --- Match case -------------------------------------------------------
		await page.click( '#minn-find-case' );
		await page.waitForFunction( () => /\/3$/.test( document.querySelector( '#minn-find-count' ).textContent ), null, { timeout: 5000 } );
		t.check( 'Match case excludes the capitalized code match (3 left)', true );
		await page.click( '#minn-find-case' );
		await page.waitForFunction( () => /\/4$/.test( document.querySelector( '#minn-find-count' ).textContent ), null, { timeout: 5000 } );

		// --- Replace one + undo -----------------------------------------------
		await page.type( '#minn-find-replace', 'swift crimson' );
		await page.click( '#minn-find-rep' );
		await page.waitForFunction( () => /\/3$/.test( document.querySelector( '#minn-find-count' ).textContent ), null, { timeout: 5000 } );
		const afterOne = await bodyText();
		t.check( 'Replace rewrites the current match', ( afterOne.match( /swift crimson/g ) || [] ).length === 1 );

		// One ⌘Z reverts the replace — it rode the native undo stack. Focus
		// the body first or the keystroke would undo the find INPUT's text.
		await page.evaluate( () => {
			const p = document.querySelector( '#minn-editor-body p' );
			const r = document.createRange();
			r.setStart( p.firstChild, 0 );
			r.collapse( true );
			const s = getSelection();
			s.removeAllRanges();
			s.addRange( r );
			document.querySelector( '#minn-editor-body' ).focus( { preventScroll: true } );
		} );
		await page.keyboard.press( 'Meta+z' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-editor-body' ).textContent.includes( 'swift crimson' ), null, { timeout: 5000 } );
		t.check( '⌘Z reverts the replace (native undo stack)', true );
		await page.waitForFunction( () => /\/4$/.test( document.querySelector( '#minn-find-count' ).textContent ), null, { timeout: 5000 } );

		// --- Replace all -------------------------------------------------------
		await page.click( '#minn-find-repall' );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Replaced 4 matches/.test( x.textContent ) ),
			null, { timeout: 5000 }
		);
		t.check( 'Replace all toasts the count', true );
		const afterAll = await bodyText();
		t.check( 'All editable matches rewritten', ( afterAll.match( /swift crimson/g ) || [] ).length === 4, String( ( afterAll.match( /swift crimson/g ) || [] ).length ) );
		t.check( 'Island preview text untouched', afterAll.includes( 'quick brown island text' ) );
		t.check( 'Count reads 0 after replace-all', ( await count() ) === '0', await count() );

		// --- Saved content ------------------------------------------------------
		await page.keyboard.press( 'Meta+s' );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Draft saved/.test( x.textContent ) ),
			null, { timeout: 10000 }
		);
		const raw = await fetchRaw();
		t.check( 'Saved markup carries the replacements (incl. inside the code block)', ( raw.match( /swift crimson/g ) || [] ).length === 4, String( ( raw.match( /swift crimson/g ) || [] ).length ) );
		t.check( 'Island block saved byte-identical', raw.includes( ISLAND ) );
		t.check( 'No stray find chrome reached the database', ! /minn-find/.test( raw ) );

		// --- Close --------------------------------------------------------------
		await page.focus( '#minn-find-input' );
		await page.keyboard.press( 'Escape' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-find-bar' ) && ! document.querySelector( '#minn-find-marks' ), null, { timeout: 5000 } );
		t.check( 'Esc closes the bar and clears every mark', true );
	} finally {
		await deletePost( page, pid );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
