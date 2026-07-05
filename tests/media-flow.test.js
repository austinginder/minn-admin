/**
 * Inline media flow: clipboard/dropped image files upload to the library and
 * insert at the caret; captions edit inline. Verifies SAVED markup (true
 * Gutenberg image blocks), the blob-URL serialize guard, caption edge guards,
 * the constrained-caret hop, and ⌘Z. Uploads are canvas-generated PNGs,
 * deleted on the way out.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'media-flow' );
	// Tests navigate away from dirty editors on purpose (post-⌘Z state) —
	// accept the unload warning instead of letting it cancel the goto.
	page.on( 'dialog', ( d ) => d.accept() );
	await login( page );

	// Paste (or drop) a canvas-generated PNG at the current caret.
	const sendImage = ( name, kind = 'paste', at = null ) => page.evaluate( async ( a ) => {
		const body = document.querySelector( '#minn-editor-body' );
		const canvas = document.createElement( 'canvas' );
		canvas.width = 24; canvas.height = 24;
		const cx = canvas.getContext( '2d' );
		cx.fillStyle = '#89b4fa';
		cx.fillRect( 0, 0, 24, 24 );
		const blob = await new Promise( ( res ) => canvas.toBlob( res, 'image/png' ) );
		const dt = new DataTransfer();
		dt.items.add( new File( [ blob ], a.name, { type: 'image/png' } ) );
		let ev;
		if ( a.kind === 'drop' ) {
			// Chrome's DragEvent constructor ignores the dataTransfer member —
			// pin it on the instance or the handler sees no files (and the
			// unhandled default would navigate to the dropped file).
			ev = new DragEvent( 'drop', { bubbles: true, cancelable: true, clientX: a.at.x, clientY: a.at.y } );
			Object.defineProperty( ev, 'dataTransfer', { value: dt } );
		} else {
			ev = new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } );
		}
		body.dispatchEvent( ev );
		return { prevented: ev.defaultPrevented, html: body.innerHTML };
	}, { name, kind, at } );

	const waitUploaded = () => page.waitForFunction( () => {
		const b = document.querySelector( '#minn-editor-body' );
		return ! b.querySelector( '[data-minn-upload]' ) && b.querySelector( 'figure img[src*="wp-content/uploads"]' );
	}, null, { timeout: 20000 } );

	const savedRaw = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );

	const caretEndOf = ( sel ) => page.evaluate( ( q ) => {
		const el = document.querySelector( '#minn-editor-body' ).querySelector( q );
		const r = document.createRange();
		r.selectNodeContents( el );
		r.collapse( false );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus();
	}, sel );

	/* ===== Paste a screenshot-style file ===== */
	const pasteId = await createPost( page, { title: 'MF paste', content: '<!-- wp:paragraph -->\n<p>Before image.</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, pasteId );
	await caretEndOf( 'p' );
	const mid = await sendImage( 'mf-paste.png' );
	t.check( 'blob preview lands instantly, marked uploading', mid.prevented && /data-minn-upload/.test( mid.html ) && /blob:/.test( mid.html ), mid.html.slice( 0, 200 ) );
	await waitUploaded();

	// Inline caption: click in, type, Enter exits to the next block.
	await page.click( '#minn-editor-body figure figcaption' );
	await page.keyboard.type( 'Typed in place' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( 'After the figure.' );
	const figures = await page.evaluate( () => document.querySelectorAll( '#minn-editor-body figure' ).length );
	t.check( 'Enter exits the caption without splitting the figure', figures === 1, String( figures ) );

	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	let raw = await savedRaw( pasteId );
	t.check( 'saved as a true image block with attachment id', /<!-- wp:image \{"id":\d+\} -->/.test( raw ) && /wp-image-\d+/.test( raw ), raw );
	t.check( 'saved src is the uploaded library file', /wp-content\/uploads\/[^"]*mf-paste[^"]*\.png/.test( raw ), raw );
	t.check( 'no blob URL ever reaches the database', ! /blob:/.test( raw ), raw );
	t.check( 'inline caption saved', /<figcaption class="wp-element-caption">Typed in place<\/figcaption>/.test( raw ), raw );

	/* ===== Caption edge guards ===== */
	await page.evaluate( () => {
		const fc = document.querySelector( '#minn-editor-body figcaption' );
		const r = document.createRange();
		r.setStart( fc.firstChild, 0 );
		r.collapse( true );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
	} );
	await page.keyboard.press( 'Backspace' );
	t.check( 'Backspace at caption start is a no-op', await page.evaluate( () => {
		const fc = document.querySelector( '#minn-editor-body figcaption' );
		return !! fc && fc.textContent === 'Typed in place';
	} ) );

	/* ===== Empty captions are chrome, not content ===== */
	const emptyCapId = await createPost( page, { title: 'MF empty cap', content: '<!-- wp:image {"id":1} -->\n<figure class="wp-block-image"><img src="https://minnadmin.localhost/wp-content/uploads/none.png" alt="" class="wp-image-1"></figure>\n<!-- /wp:image -->' } );
	await openEditor( page, emptyCapId );
	const seeded = await page.evaluate( () => !! document.querySelector( '#minn-editor-body > figure > figcaption' ) );
	t.check( 'existing images get a typable caption seeded', seeded );
	await page.click( '#minn-editor-body' );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	raw = await savedRaw( emptyCapId );
	t.check( 'empty caption never serializes', ! /figcaption/.test( raw ), raw );

	/* ===== Constrained caret hops out; ⌘Z reverts ===== */
	const hopId = await createPost( page, { title: 'MF hop', content: '<!-- wp:list -->\n<ul class="wp-block-list"><!-- wp:list-item -->\n<li>item one</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->' } );
	await openEditor( page, hopId );
	await caretEndOf( 'li' );
	await sendImage( 'mf-hop.png' );
	await waitUploaded();
	const hopState = await page.evaluate( () => {
		const b = document.querySelector( '#minn-editor-body' );
		return { top: b.querySelectorAll( ':scope > figure' ).length, inLi: b.querySelectorAll( 'li figure' ).length, li: b.querySelector( 'li' ).textContent };
	} );
	t.check( 'image pasted in a list hops to top level', hopState.top === 1 && hopState.inLi === 0 && hopState.li === 'item one', JSON.stringify( hopState ) );
	await page.keyboard.press( 'Meta+z' );
	await page.waitForTimeout( 300 );
	const afterUndo = await page.evaluate( () => document.querySelectorAll( '#minn-editor-body figure' ).length );
	t.check( '⌘Z removes the pasted image', afterUndo === 0, String( afterUndo ) );

	/* ===== Drop at a point ===== */
	const dropId = await createPost( page, { title: 'MF drop', content: '<!-- wp:paragraph -->\n<p>Drop target paragraph.</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, dropId );
	const box = await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body p' );
		const r = p.getBoundingClientRect();
		return { x: r.right - 4, y: r.top + r.height / 2 };
	} );
	const dropRes = await sendImage( 'mf-drop.png', 'drop', box );
	t.check( 'drop handler claims the event', dropRes.prevented === true, JSON.stringify( dropRes ).slice( 0, 200 ) );
	await waitUploaded();
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	raw = await savedRaw( dropId );
	t.check( 'dropped file uploads and saves as an image block', /mf-drop[^"]*\.png/.test( raw ) && /<!-- wp:image \{"id":\d+\} -->/.test( raw ), raw );

	/* ===== Cleanup: uploaded attachments + posts ===== */
	await page.evaluate( async () => {
		for ( const term of [ 'mf-paste', 'mf-hop', 'mf-drop' ] ) {
			const r = await fetch( window.MINN.restUrl + `wp/v2/media?search=${ term }&_fields=id`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			for ( const m of await r.json() ) {
				await fetch( window.MINN.restUrl + `wp/v2/media/${ m.id }?force=true`, { method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			}
		}
	} );
	for ( const id of [ pasteId, emptyCapId, hopId, dropId ] ) await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
