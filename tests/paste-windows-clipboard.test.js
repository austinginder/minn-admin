/**
 * Pastes as Windows and desktop apps put them on the clipboard (issue #69).
 *
 * - A copied screenshot on Windows arrives as the image file plus html that
 *   wraps one <img> in <html><body><!--StartFragment-->. That is still a lone
 *   image: the file uploads, the html's address is not hotlinked.
 * - An image held inline as data: in the html (which the sanitizer drops)
 *   uploads its file instead of pasting nothing.
 * - Text with an image keeps its text (the html path), as before.
 * - Markdown whose html flavor is a styled <div> around the same characters
 *   (a raw view's or copy button's copy) gets the Markdown prompt instead of
 *   landing as one paragraph of literal # and **.
 * - A classic post that saves an uploaded image stores no data-minn-attrs
 *   marker (its page token would be written into the post).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const WIN = ( frag ) => `<html>\r\n<body>\r\n<!--StartFragment-->${ frag }<!--EndFragment-->\r\n</body>\r\n</html>`;
const MD = '# Edge, Fixed\n\nFighting Edge through its **settings** is useless.\n\n## Killing the Noise\n\n- `HubsSidebarEnabled: 0` gone\n- `CopilotPageContext: 0` blocked\n\nOne registry file.\n';
const escHtml = ( s ) => s.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'paste-windows-clipboard' );
	await login( page );
	const made = [];

	// Caret at the start of the body's first paragraph, then a synthetic paste
	// (the handler reads e.clipboardData, so the whole pipeline runs).
	const paste = ( flavors, withFile ) => page.evaluate( ( a ) => {
		const body = document.querySelector( '#minn-editor-body' );
		const p = body.querySelector( 'p' );
		const r = document.createRange();
		r.setStart( p.firstChild || p, 0 );
		r.collapse( true );
		getSelection().removeAllRanges();
		getSelection().addRange( r );
		body.focus();
		const dt = new DataTransfer();
		if ( a.withFile ) {
			const bytes = Uint8Array.from( atob( a.png ), ( ch ) => ch.charCodeAt( 0 ) );
			dt.items.add( new File( [ bytes ], 'screenshot.png', { type: 'image/png' } ) );
		}
		for ( const [ k, v ] of Object.entries( a.flavors ) ) dt.setData( k, v );
		const ev = new ClipboardEvent( 'paste', { bubbles: true, cancelable: true, clipboardData: dt } );
		body.dispatchEvent( ev );
		return ev.defaultPrevented;
	}, { flavors, withFile, png: PNG } );
	const figure = () => page.evaluate( () => {
		const f = document.querySelector( '#minn-editor-body > figure' );
		return f ? { upload: f.hasAttribute( 'data-minn-upload' ), src: ( f.querySelector( 'img' ) || {} ).getAttribute ? f.querySelector( 'img' ).getAttribute( 'src' ) : '' } : null;
	} );
	const waitUploaded = () => page.waitForFunction( () => ! document.querySelector( '#minn-editor-body [data-minn-upload]' ), null, { timeout: 90000 } ).catch( () => null );
	const attachmentOf = () => page.evaluate( () => {
		const img = document.querySelector( '#minn-editor-body img[class*="wp-image-"]' );
		const m = img && img.className.match( /wp-image-(\d+)/ );
		return m ? Number( m[ 1 ] ) : 0;
	} );
	const savedRaw = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );

	const prose = '<!-- wp:paragraph -->\n<p>Here is the proof.</p>\n<!-- /wp:paragraph -->';
	// Each case on its own post, so an autosave from one never shows in the next.
	const fresh = async ( title, content ) => {
		const id = await createPost( page, { title, content } );
		made.push( { post: id } );
		await openEditor( page, id );
		return id;
	};
	try {
		/* A Windows screenshot copy: file + a lone <img> in Windows' wrapper. */
		await fresh( 'Paste: Windows image', prose );
		await paste( { 'text/html': WIN( '<img src="https://example.com/shot.png"/>' ) }, true );
		await page.waitForTimeout( 300 );
		let f = await figure();
		t.check( 'a Windows-wrapped lone image uploads its file, not a hotlink', !! f && ( f.upload || /^blob:|\/uploads\//.test( f.src ) ) && ! /example\.com/.test( f.src ), JSON.stringify( f ) );
		await waitUploaded();
		made.push( { media: await attachmentOf() } );

		/* An image held inline as data: in the html. */
		await fresh( 'Paste: data image', prose );
		await paste( { 'text/html': WIN( `<img src="data:image/png;base64,${ PNG }"/>` ) }, true );
		await page.waitForTimeout( 300 );
		f = await figure();
		t.check( 'an image held as data: in the html uploads its file instead of pasting nothing', !! f && ( f.upload || /^blob:|\/uploads\//.test( f.src ) ), JSON.stringify( f ) );
		await waitUploaded();
		made.push( { media: await attachmentOf() } );

		/* Control: text with an image keeps its text. */
		await fresh( 'Paste: text and image', prose );
		await paste( { 'text/html': WIN( '<p>Caption words</p><img src="https://example.com/shot.png"/>' ) }, true );
		await page.waitForTimeout( 300 );
		const kept = await page.evaluate( () => document.querySelector( '#minn-editor-body' ).textContent.includes( 'Caption words' ) );
		t.check( 'control: text with an image keeps its text', kept );

		/* Markdown in a styled <div>: the prompt, then real blocks. */
		await fresh( 'Paste: Markdown div', '' );
		await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			if ( ! body.querySelector( 'p' ) ) body.insertAdjacentHTML( 'afterbegin', '<p><br></p>' );
		} );
		await paste( { 'text/plain': MD.replace( /\n/g, '\r\n' ), 'text/html': `<meta charset="utf-8"><div style="white-space: pre-wrap;">${ escHtml( MD ) }</div>` }, false );
		const btn = await page.waitForSelector( 'text=Format Markdown', { timeout: 5000 } ).catch( () => null );
		t.check( 'Markdown in a styled <div> gets the Markdown prompt', !! btn );
		if ( btn ) await btn.click();
		await page.waitForTimeout( 600 );
		const top = await page.evaluate( () => [ ...document.querySelector( '#minn-editor-body' ).children ].map( ( n ) => n.tagName ) );
		t.check( 'and Format Markdown makes real headings and a list', top.includes( 'H1' ) && top.includes( 'H2' ) && top.includes( 'UL' ), top.join( ',' ) );

		/* Control: a styled div that is NOT the same text stays rich. */
		await fresh( 'Paste: rich div', prose );
		await paste( { 'text/plain': MD, 'text/html': '<div><b>Rich</b> words</div>' }, false );
		const asked = await page.waitForSelector( 'text=Format Markdown', { timeout: 1500 } ).catch( () => null );
		t.check( 'control: a div holding other content is not offered as Markdown', ! asked );
		if ( asked ) await page.keyboard.press( 'Escape' );

		/* A classic post saving an uploaded image stores no marker. */
		const classicId = await fresh( 'Paste: classic image', '<p>Classic intro.</p>\n<p>More classic text.</p>' );
		await paste( {}, true );
		await waitUploaded();
		const att = await attachmentOf();
		made.push( { media: att } );
		await page.keyboard.press( 'Meta+s' );
		let raw = '';
		for ( let i = 0; i < 60 && ! /wp-image-/.test( raw ); i++ ) {
			await page.waitForTimeout( 1000 );
			raw = await savedRaw( classicId );
		}
		t.check( 'a classic post keeps the pasted image', /wp-image-\d+/.test( raw ), raw.slice( 0, 300 ) );
		t.check( 'and stores no data-minn-attrs marker', ! /data-minn-attrs/.test( raw ), raw.slice( 0, 300 ) );
	} finally {
		for ( const m of made ) {
			if ( m.post ) await deletePost( page, m.post ).catch( () => {} );
			if ( m.media ) {
				await page.evaluate( async ( a ) => fetch( window.MINN.restUrl + `wp/v2/media/${ a }?force=true`, { method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce } } ), m.media ).catch( () => {} );
			}
		}
	}
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
