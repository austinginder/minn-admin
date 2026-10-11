/**
 * A look-alike island in post content cannot name another block.
 *
 * Post kses keeps class and data-* (it strips contenteditable), so a writer
 * can put an element wearing .minn-block-island and data-island="K" into a
 * paragraph (a <div> there is hoisted out of the <p> into the editor body)
 * or a Custom HTML block. The editor used to find islands by that
 * class: the serializer emitted block K's stored markup for a top-level
 * look-alike (a duplicate) and flushed a dirty look-alike slot island over
 * K's markup (a rewrite), and cutting or deleting one nulled K's markup so K
 * dropped on the next save. Islands are now only elements Minn rendered
 * (contenteditable="false", not inside another island's preview).
 *
 * The post is written over REST as an administrator, whose content is
 * stored as written, with markup a Contributor's kses would leave: no
 * contenteditable on the look-alikes.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

// Index 0 is the first island: an unregistered block keeps its markup
// verbatim as one.
const REAL = '<!-- wp:acme/lookalike-probe -->\n<div class="acme-real">REAL-BLOCK-ZERO</div>\n<!-- /wp:acme/lookalike-probe -->';
const CONTENT = [
	REAL,
	'<!-- wp:paragraph -->\n<p>Before the group.</p>\n<!-- /wp:paragraph -->',
	'<!-- wp:group -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Group text <span class="minn-block-island" data-island="0">look-alike</span> end.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->',
	// A <div> inside a paragraph: the HTML parser hoists it out of the <p>,
	// so it reaches the editor body as a top-level node.
	'<!-- wp:paragraph -->\n<p>Hoist <div class="minn-block-island minn-slot-island" data-island="0" data-minn-slot-dirty="1" data-block="core/group"><div class="minn-slot"><p>PLANTED-SLOT</p></div></div> tail</p>\n<!-- /wp:paragraph -->',
	'<!-- wp:paragraph -->\n<p>After.</p>\n<!-- /wp:paragraph -->',
].join( '\n\n' );

( async () => {
	const t = reporter( 'island-lookalike' );
	const { browser, page, errors } = await launch();
	await login( page );
	const id = await createPost( page, { title: 'Island look-alike probe', content: CONTENT } );
	let id2 = 0;
	const stored = ( which ) => page.evaluate( async ( pid ) => {
		const r = await fetch( `${ window.MINN.restUrl }wp/v2/posts/${ pid }?context=edit&_fields=content`,
			{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return ( await r.json() ).content.raw;
	}, which || id );
	const count = ( s, needle ) => s.split( needle ).length - 1;
	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[contenteditable="false"]', { timeout: 20000 } );

		// An ordinary edit and save: type in the first paragraph.
		await page.evaluate( () => {
			const p = Array.from( document.querySelectorAll( '#minn-editor-body p' ) ).find( ( x ) => /Before the group/.test( x.textContent ) );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( r );
			p.closest( '[contenteditable="true"]' ).focus( { preventScroll: true } );
		} );
		await page.keyboard.type( ' Edited.' );
		const saved = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( `/wp/v2/posts/${ id }(\\?|$)` ).test( r.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await saved;
		await page.waitForTimeout( 600 );
		let s = await stored();
		if ( process.env.MINN_DEBUG ) console.log( s );
		t.check( 'the edit saved', s.includes( 'Before the group. Edited.' ), s.slice( 0, 160 ) );
		t.check( 'block 0 is saved once, as it was', 1 === count( s, '<!-- wp:acme/lookalike-probe -->' ) && s.includes( REAL ), 'copies: ' + count( s, '<!-- wp:acme/lookalike-probe -->' ) );
		t.check( 'no look-alike slot was flushed into block 0', ! /<div class="acme-real">(?:(?!<\/div>)[\s\S])*PLANTED-SLOT/.test( s ) );
		t.check( 'the look-alikes stay as the writer\'s text', s.includes( 'data-island="0">look-alike</span>' ) && s.includes( 'PLANTED-SLOT' ) && ! /acme-real[^<]*<!-- wp:paragraph/.test( s ) );

		// Cutting the look-alike in the Custom HTML block cuts text, not block 0.
		// A fresh post, so the first editor's state plays no part.
		id2 = await createPost( page, { title: 'Island look-alike cut probe', content: CONTENT } );
		await openEditor( page, id2 );
		await page.waitForSelector( '.minn-block-island[contenteditable="false"]', { timeout: 20000 } );
		const cut = await page.evaluate( () => {
			const fake = Array.from( document.querySelectorAll( '#minn-editor-body .minn-block-island' ) ).find( ( el ) => 'false' !== el.getAttribute( 'contenteditable' ) && /PLANTED-SLOT/.test( el.textContent ) );
			if ( ! fake ) return false;
			const r = document.createRange();
			r.selectNode( fake );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( r );
			document.getElementById( 'minn-editor-body' ).focus( { preventScroll: true } );
			return true;
		} );
		if ( cut ) {
			await page.keyboard.press( 'Meta+x' );
			await page.waitForTimeout( 300 );
			const saved2 = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( `/wp/v2/posts/${ id2 }(\\?|$)` ).test( r.url() ), { timeout: 20000 } );
			await page.keyboard.press( 'Meta+s' );
			await saved2;
			await page.waitForTimeout( 600 );
			s = await stored( id2 );
			if ( process.env.MINN_DEBUG ) console.log( s );
			t.check( 'cutting a look-alike keeps block 0', 1 === count( s, '<!-- wp:acme/lookalike-probe -->' ) && s.includes( REAL ), 'copies: ' + count( s, '<!-- wp:acme/lookalike-probe -->' ) );
		} else {
			t.check( 'the look-alike reached the editor to cut', false );
		}
	} finally {
		await deletePost( page, id );
		await deletePost( page, id2 );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
