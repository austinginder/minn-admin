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
 * its neighbours: it is never glued into one string with them. A code
 * block inside one is not syntax-highlighted, so no highlight markup can
 * reach the save.
 *
 * The same holds for every other block: a paragraph or heading left open,
 * a slot container whose wrapper bytes leave an element open or start a
 * <textarea>, saves its stored bytes and leaves its neighbours alone. A
 * Custom HTML block stays itself when copied, and a block-markup paste of
 * one (and of an island) fills in, saves as pasted, and undoes and redoes.
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

		/* ===== Open markup in other blocks and in slot wrapper bytes ===== */
		const LP = '<!-- wp:latest-posts {"postsToShow":1} /-->';
		const SIBS = [
			'<!-- wp:paragraph -->\n<p>see <a href="/x">link</p>\n<!-- /wp:paragraph -->',
			LP,
			'<!-- wp:paragraph -->\n<p>after one</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:heading -->\n<h2 class="wp-block-heading">Title<span>\n<!-- /wp:heading -->',
			'<!-- wp:query-title {"type":"archive"} /-->',
			'<!-- wp:paragraph -->\n<p>a</p></div><p>x2</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:group -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p><b>bold</p>\n<!-- /wp:paragraph --><!-- wp:latest-posts /--></div>\n<!-- /wp:group -->',
			'<!-- wp:paragraph -->\n<p>after the group</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:cover -->\n<div class="wp-block-cover"><a href="/x"><span class="bg"></span><div class="wp-block-cover__inner-container"><!-- wp:paragraph -->\n<p>in cover a</p>\n<!-- /wp:paragraph --></div></div>\n<!-- /wp:cover -->',
			LP,
			'<!-- wp:media-text -->\n<div class="wp-block-media-text"><figure class="wp-block-media-text__media"><b></figure><div class="wp-block-media-text__content"><!-- wp:paragraph -->\n<p>mt</p>\n<!-- /wp:paragraph --></div></div>\n<!-- /wp:media-text -->',
			'<!-- wp:paragraph -->\n<p>after the media</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:stackable/columns -->\n<div class="stk-block-columns stk-block"><span title="</div>"></span><div class="stk-row stk-inner-blocks stk-block-content"><!-- wp:stackable/column -->\n<div class="stk-block-column stk-column"><div class="stk-column-wrapper"><div class="stk-block-content stk-inner-blocks"><!-- wp:paragraph -->\n<p>stk</p>\n<!-- /wp:paragraph --></div></div></div>\n<!-- /wp:stackable/column --></div></div>\n<!-- /wp:stackable/columns -->',
			'<!-- wp:paragraph -->\n<p>after the columns</p>\n<!-- /wp:paragraph -->',
			'<!-- wp:cover -->\n<div class="wp-block-cover"><textarea><div class="wp-block-cover__inner-container"><!-- wp:paragraph -->\n<p>in cover t</p>\n<!-- /wp:paragraph --></div></div>\n<!-- /wp:cover -->',
			'<!-- wp:paragraph -->\n<p>after the textarea</p>\n<!-- /wp:paragraph -->',
			PARA,
		];
		const SPOST = SIBS.join( '\n\n' );
		const sid = await createPost( page, { title: 'Open markup round trip', content: SPOST, status: 'draft' } );
		ids.push( sid );
		await openEditor( page, sid );
		await page.waitForSelector( '#minn-editor-body .minn-block-island', { timeout: 90000 } );
		await page.waitForTimeout( 2500 );
		await freshParagraph( page );
		await page.keyboard.type( 'tail' );
		const sibSaved = await save( sid );
		const sibMissing = [ ...new Set( SIBS ) ].filter( ( b ) => ! sibSaved.includes( b ) );
		t.check( 'blocks with open markup, and their neighbours, come back byte for byte', 0 === sibMissing.length, sibMissing.join( ' | ' ).slice( 0, 400 ) + ' :: ' + sibSaved.slice( 0, 300 ) );
		t.check( 'no island chrome or server preview is saved from open markup', ! /minn-block-island|minn-island-preview|minn-island-chip|minn-raw-block|data-minn-/.test( sibSaved ), sibSaved.slice( 0, 400 ) );
		t.check( 'nothing after the <textarea> cover was lost', sibSaved.includes( '<!-- wp:paragraph -->\n<p>after the textarea</p>\n<!-- /wp:paragraph -->' ) && /<p>tail<\/p>/.test( sibSaved ), sibSaved.slice( -300 ) );

		/* ===== A Custom HTML block keeps itself through a copy ===== */
		const cid = await createPost( page, { title: 'HTML block copy', content: [ PARA, WRAPPED, LP, PARA ].join( '\n\n' ), status: 'draft' } );
		ids.push( cid );
		await openEditor( page, cid );
		await page.waitForSelector( '#minn-editor-body .acme-wrap', { timeout: 90000 } );
		await page.waitForTimeout( 1500 );
		const copied = await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			body.focus();
			const r = document.createRange();
			r.selectNodeContents( body );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( r );
			const dt = new DataTransfer();
			body.dispatchEvent( new ClipboardEvent( 'copy', { bubbles: true, cancelable: true, clipboardData: dt } ) );
			return dt.getData( 'text/x-minn-blocks' );
		} );
		t.check( 'a copy that spans an island carries the Custom HTML block as itself', copied.includes( WRAPPED ), copied.slice( 0, 400 ) );

		/* ===== A block-markup paste of a Custom HTML block and an island ===== */
		const DUO = '<!-- wp:acme/duo -->\n<div class="acme-duo"><p>Pasted island.</p></div>\n<!-- /wp:acme/duo -->';
		const PASTED = '<!-- wp:html -->\n<div class="paste-wrap" data-y="2"><span>pasted</span></div>\n<!-- /wp:html -->';
		const pid = await createPost( page, { title: 'HTML block paste', content: PARA, status: 'draft' } );
		ids.push( pid );
		await openEditor( page, pid );
		await page.waitForSelector( '#minn-editor-body p', { timeout: 90000 } );
		await freshParagraph( page );
		await page.evaluate( ( markup ) => {
			const body = document.querySelector( '#minn-editor-body' );
			const dt = new DataTransfer();
			dt.setData( 'text/x-minn-blocks', markup );
			dt.setData( 'text/plain', 'x' );
			body.dispatchEvent( new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } ) );
		}, PASTED + '\n\n' + DUO );
		await page.waitForTimeout( 1500 );
		const landed = await page.evaluate( () => ( {
			wrap: document.querySelectorAll( '#minn-editor-body .minn-raw-block .paste-wrap' ).length,
			island: document.querySelectorAll( '#minn-editor-body .minn-block-island[data-block="acme/duo"]' ).length,
			pending: document.querySelectorAll( '#minn-editor-body [data-minn-raw], #minn-editor-body [data-minn-fill]' ).length,
			preview: ( document.querySelector( '#minn-editor-body .minn-block-island[data-block="acme/duo"] .minn-island-preview' ) || {} ).textContent || '',
		} ) );
		t.check( 'the pasted Custom HTML block and island land filled, no placeholder left', 1 === landed.wrap && 1 === landed.island && 0 === landed.pending && /Pasted island/.test( landed.preview ), JSON.stringify( landed ) );
		const pastedSave = await save( pid );
		t.check( 'the paste saves as pasted', pastedSave.includes( PASTED ) && pastedSave.includes( DUO ), pastedSave.slice( 0, 500 ) );
		const counts = () => page.evaluate( () => [ document.querySelectorAll( '#minn-editor-body .paste-wrap' ).length, document.querySelectorAll( '#minn-editor-body .minn-block-island[data-block="acme/duo"]' ).length ] );
		await page.keyboard.press( 'Meta+z' );
		await page.waitForTimeout( 600 );
		const undone = await counts();
		await page.keyboard.press( 'Meta+Shift+z' );
		await page.waitForTimeout( 600 );
		const redone = await counts();
		t.check( 'undo takes the paste out and redo puts it back', 0 === undone[ 0 ] && 0 === undone[ 1 ] && 1 === redone[ 0 ] && 1 === redone[ 1 ], JSON.stringify( { undone, redone } ) );
		const redoSave = await save( pid );
		t.check( 'after undo and redo the paste saves once, as pasted', 1 === redoSave.split( PASTED ).length - 1 && 1 === redoSave.split( DUO ).length - 1, redoSave.slice( 0, 500 ) );
	} finally {
		for ( const id of ids ) await deletePost( page, id );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
