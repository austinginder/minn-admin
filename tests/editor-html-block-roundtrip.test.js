/**
 * A Custom HTML block, and a run of freeform HTML between blocks, saves as
 * itself. The serializer used to re-derive blocks from their elements, so
 * any save of the post (an edit anywhere else) turned a Custom HTML block
 * into paragraphs, dropped a wrapping <div> with its class and attributes,
 * and did the same to freeform markup, though nobody touched it.
 *
 * Untouched, each comes back byte for byte. Edited, it stays the same block
 * with the edit in it. Unbalanced markup (an open/close pair of blocks
 * around others, a stray close) can neither escape its block nor swallow
 * its neighbours: it is never glued into one string with them. A code block inside one is not syntax-highlighted,
 * so no highlight markup can reach the save.
 */
const { launch, login, createPost, deletePost, openEditor: openEditorQuick, freshParagraph, reporter, BASE } = require( './helpers' );
// The shared opener waits 15 s a try; this lab runs at load 40-100, where an
// editor with several islands takes longer, so these suites wait longer.
const openEditor = async ( page, id ) => {
	for ( let i = 0; i < 3; i++ ) {
		try {
			await page.goto( `${ BASE }/minn-admin/editor/posts/${ id }`, { waitUntil: 'domcontentloaded', timeout: 120000 } );
			await page.waitForSelector( '#minn-editor-body', { timeout: 120000 } );
			await page.waitForTimeout( 800 );
			return;
		} catch ( e ) {
			if ( 2 === i ) return openEditorQuick( page, id );
			await page.waitForTimeout( 3000 );
		}
	}
};

const WRAPPED = '<!-- wp:html -->\n<div class="acme-wrap" data-x="1"><iframe src="about:blank" width="40" height="40"></iframe> <span id="acme-pop" popover>pop</span> wrapped</div>\n<!-- /wp:html -->';
const MULTI = '<!-- wp:html -->\n<p class="a">one</p>\n<p>two</p>\n<!-- /wp:html -->';
const CODE = '<!-- wp:html -->\n<pre><code class="language-php">&lt;?php echo 1; ?&gt;</code></pre>\n<!-- /wp:html -->';
const FREEFORM = '<div class="legacy">old <b>bold</b> text</div>';
const PARA = '<!-- wp:paragraph -->\n<p>Plain paragraph.</p>\n<!-- /wp:paragraph -->';
const POST = [ PARA, WRAPPED, MULTI, CODE ].join( '\n\n' ) + '\n\n' + FREEFORM + '\n\n' + PARA;

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-html-block-roundtrip' );
	await login( page );
	const ids = [];
	const rawOf = ( pid ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + p + '?context=edit&_fields=content.raw&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, pid );
	const save = async ( pid ) => {
		const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && res.url().includes( 'wp/v2/posts/' + pid ), { timeout: 150000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		return rawOf( pid );
	};

	try {
		const id = await createPost( page, { title: 'HTML block round trip', content: POST, status: 'draft' } );
		ids.push( id );
		t.check( 'the post is stored as written (premise)', ( await rawOf( id ) ) === POST, '' );
		await openEditor( page, id );
		await page.waitForSelector( '#minn-editor-body .acme-wrap', { timeout: 90000 } );

		/* ===== An edit elsewhere ===== */
		await freshParagraph( page );
		await page.keyboard.type( 'elsewhere' );
		const saved = await save( id );
		t.check( 'an edit elsewhere is saved (premise)', /elsewhere/.test( saved ), saved.slice( -200 ) );
		t.check( 'an untouched Custom HTML block with a wrapper div comes back byte for byte', saved.includes( WRAPPED ), saved.slice( 0, 500 ) );
		t.check( 'an untouched Custom HTML block of several elements comes back byte for byte', saved.includes( MULTI ), '' );
		t.check( 'an untouched Custom HTML block holding code comes back byte for byte', saved.includes( CODE ), '' );
		t.check( 'untouched freeform HTML comes back byte for byte', saved.includes( FREEFORM ), '' );
		t.check( 'no editor marker reaches the database', ! /data-minn-|minn-raw-block/.test( saved ), '' );

		/* ===== An edit inside the Custom HTML block ===== */
		await page.click( '#minn-editor-body >> text=two' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' more' );
		const edited = await save( id );
		const block = ( edited.match( /<!-- wp:html -->[\s\S]*?class="a"[\s\S]*?<!-- \/wp:html -->/ ) || [ '' ] )[ 0 ];
		t.check( 'an edited Custom HTML block stays a Custom HTML block with the edit', /<p class="a">one<\/p>/.test( block ) && /two more/.test( block ), block || edited.slice( 0, 600 ) );
		t.check( 'the other blocks still come back byte for byte', edited.includes( WRAPPED ) && edited.includes( CODE ) && edited.includes( FREEFORM ), '' );

		/* ===== Unbalanced Custom HTML and freeform ===== */
		// An open/close pair of Custom HTML blocks around other blocks (a
		// dynamic one among them), a column splitter, a stray close followed
		// by markup, and freeform with a stray close. Each keeps its own
		// bytes and its neighbours, through two saves and a reload between.
		const UNBAL = [
			'<!-- wp:html -->\n<div class="box">\n<!-- /wp:html -->',
			'<!-- wp:paragraph -->\n<p>Inside the box.</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:latest-posts {"postsToShow":1} /-->',
			'<!-- wp:html -->\n</div>\n<!-- /wp:html -->',
			'<!-- wp:paragraph -->\n<p>Between.</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:html -->\n</div><div class="col">\n<!-- /wp:html -->',
			'<!-- wp:paragraph -->\n<p>Column two.</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:html -->\n</div><p>x</p>\n<!-- /wp:html -->',
			'<!-- wp:paragraph -->\n<p>After the stray.</p>\n<!-- /wp:paragraph -->',
			'</section><p class="loose">freeform after a stray close</p>',
			PARA,
		];
		const UPOST = UNBAL.join( '\n\n' );
		const uid = await createPost( page, { title: 'Unbalanced HTML round trip', content: UPOST, status: 'draft' } );
		ids.push( uid );
		const editAndSave = async () => {
			await openEditor( page, uid );
			await page.waitForSelector( '#minn-editor-body .minn-block-island', { timeout: 90000 } );
			await page.waitForTimeout( 1500 );
			await freshParagraph( page );
			await page.keyboard.type( 'tail' );
			return save( uid );
		};
		const first = await editAndSave();
		const firstMissing = UNBAL.filter( ( b ) => ! first.includes( b ) );
		t.check( 'every unbalanced block and its neighbours come back byte for byte', 0 === firstMissing.length, firstMissing.join( ' | ' ).slice( 0, 400 ) + ' :: ' + first.slice( 0, 400 ) );
		t.check( 'no island chrome, preview or wrapper is saved into the post', ! /minn-block-island|minn-island-preview|minn-island-chip|minn-raw-block|data-minn-/.test( first ), first.slice( 0, 400 ) );
		const second = await editAndSave();
		const count = ( s, needle ) => s.split( needle ).length - 1;
		t.check( 'a second load and save duplicates nothing', UNBAL.every( ( b ) => 1 === count( second, b ) ) && 1 === count( second, '<p>x</p>' ), second.slice( 0, 600 ) );
	} finally {
		for ( const id of ids ) await deletePost( page, id );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
