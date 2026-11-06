/**
 * Audio and video in the editor. /audio and /video open a picker that lists and
 * uploads that kind of file, and the block lands as the same editable figure a
 * saved one opens as, with a live player. Its hover chip gives a caption,
 * Replace and Remove. What's saved is what core/audio and core/video write,
 * including the attachment id, and it survives a reload. Dropping a file on
 * the editor does the same. The uploads are made-up bytes: WordPress checks a
 * media file's extension, and nothing here plays them.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const fake = ( name, mimeType ) => ( { name, mimeType, buffer: Buffer.from( 'minn test media ' + name ) } );

( async () => {
	const t = reporter( 'editor-audio-video' );
	const { browser, page, errors } = await launch();
	await login( page );
	const id = await createPost( page, { title: 'Audio and video', content: '<!-- wp:paragraph -->\n<p>Opening line.</p>\n<!-- /wp:paragraph -->' } );
	const uploaded = [];

	const rest = ( path, method = 'GET' ) => page.evaluate( async ( [ p, m ] ) => {
		const r = await fetch( window.MINN.restUrl + p, { method: m, headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return r.json().catch( () => null );
	}, [ path, method ] );
	const saved = async () => ( await rest( `wp/v2/posts/${ id }?context=edit&_fields=content` ) ).content.raw;
	const save = async () => {
		await page.waitForTimeout( 400 );
		await page.click( '#minn-save-draft-btn' );
		await page.waitForTimeout( 2500 );
	};
	const atEnd = async () => {
		const last = page.locator( '#minn-editor-body > p' ).last();
		await last.click();
		// The slash menu opens on an empty line.
		if ( ( await last.innerText() ).trim() ) {
			await page.keyboard.press( 'End' );
			await page.keyboard.press( 'Enter' );
		}
		await page.waitForTimeout( 200 );
	};
	const slashInsert = async ( cmd, file ) => {
		await atEnd();
		await page.keyboard.type( '/' + cmd, { delay: 40 } );
		await page.waitForSelector( '.minn-slash-menu', { timeout: 5000 } );
		await page.waitForTimeout( 300 );
		const first = await page.$eval( '.minn-slash-menu', ( m ) => m.innerText.split( '\n' )[ 0 ] );
		await page.keyboard.press( 'Enter' );
		await page.waitForSelector( '#minn-picker-file', { state: 'attached', timeout: 8000 } );
		const accept = await page.getAttribute( '#minn-picker-file', 'accept' );
		const up = page.waitForResponse( ( r ) => /wp\/v2\/media$/.test( new URL( r.url() ).pathname ) && 'POST' === r.request().method() );
		await page.setInputFiles( '#minn-picker-file', file );
		const res = await ( await up ).json();
		uploaded.push( res.id );
		await page.waitForSelector( `#minn-editor-body figure.wp-block-${ cmd }`, { timeout: 8000 } );
		await page.waitForTimeout( 400 );
		return { first, accept, attachment: res.id };
	};

	try {
		await openEditor( page, id );
		await page.waitForTimeout( 800 );

		const a = await slashInsert( 'audio', fake( 'minn-road-notes.mp3', 'audio/mpeg' ) );
		t.check( '/audio is the Audio command, not Embed', 'Audio' === a.first, a.first );
		t.check( 'its picker takes audio files', 'audio/*' === a.accept, a.accept );
		t.check( 'the block is an editable figure, not an island', await page.locator( '#minn-editor-body .minn-block-island' ).count() === 0, '' );
		t.check( 'with a player', await page.locator( '#minn-editor-body figure.wp-block-audio > audio[controls]' ).count() === 1, '' );
		await save();
		let raw = await saved();
		t.check( 'saved as core/audio with its attachment', raw.includes( `<!-- wp:audio {"id":${ a.attachment }} -->` ), raw.slice( 0, 300 ) );
		t.check( 'in the markup core writes', /<figure class="wp-block-audio"><audio controls(="")? src="[^"]+minn-road-notes[^"]*\.mp3"><\/audio><\/figure>/.test( raw ), raw.slice( 0, 400 ) );
		t.check( 'no editor attribute reaches the database', ! raw.includes( 'data-minn' ), '' );

		// Hover chip: caption.
		const fig = page.locator( '#minn-editor-body figure.wp-block-audio' );
		await fig.scrollIntoViewIfNeeded();
		await fig.hover();
		await page.waitForTimeout( 500 );
		const chip = page.locator( '.minn-code-chip.shown' );
		t.check( 'the chip names it', ( await chip.innerText() ).includes( 'audio' ), await chip.innerText() );
		await chip.click();
		await page.waitForSelector( '[data-media-caption]', { timeout: 5000 } );
		await page.fill( '[data-media-caption]', 'Road notes from Dyer' );
		await page.click( '[data-media-apply]' );
		await save();
		raw = await saved();
		t.check( 'caption saved', /<figcaption class="wp-element-caption">Road notes from Dyer<\/figcaption><\/figure>/.test( raw ), raw.slice( 0, 400 ) );

		// Reload: the same figure comes back, unchanged.
		const before = raw;
		await openEditor( page, id );
		await page.waitForTimeout( 800 );
		t.check( 'a reload opens the same figure', await page.locator( '#minn-editor-body figure.wp-block-audio > audio' ).count() === 1, '' );
		await page.locator( '#minn-editor-title' ).click();
		await page.keyboard.press( 'End' );
		await page.keyboard.type( '!' );
		await save();
		t.check( 'and a save leaves it byte for byte', ( await saved() ) === before, '' );

		// Video through the same door.
		const v = await slashInsert( 'video', fake( 'minn-route-30.mp4', 'video/mp4' ) );
		t.check( '/video is the Video command', 'Video' === v.first, v.first );
		t.check( 'its picker takes video files', 'video/*' === v.accept, v.accept );
		await save();
		raw = await saved();
		t.check( 'saved as core/video', raw.includes( `<!-- wp:video {"id":${ v.attachment }} -->` ) && /<figure class="wp-block-video"><video controls(="")? src="[^"]+minn-route-30[^"]*\.mp4"><\/video><\/figure>/.test( raw ), raw.slice( -400 ) );

		// Replace keeps the block and moves it to the new file.
		const vf = page.locator( '#minn-editor-body figure.wp-block-video' );
		await vf.scrollIntoViewIfNeeded();
		await vf.hover();
		await page.waitForTimeout( 500 );
		await page.locator( '.minn-code-chip.shown' ).click();
		await page.waitForSelector( '[data-media-replace]', { timeout: 5000 } );
		await page.click( '[data-media-replace]' );
		await page.waitForSelector( '#minn-picker-file', { state: 'attached', timeout: 8000 } );
		const up2 = page.waitForResponse( ( r ) => /wp\/v2\/media$/.test( new URL( r.url() ).pathname ) && 'POST' === r.request().method() );
		await page.setInputFiles( '#minn-picker-file', fake( 'minn-second-cut.mp4', 'video/mp4' ) );
		const second = await ( await up2 ).json();
		uploaded.push( second.id );
		await page.waitForTimeout( 800 );
		await save();
		raw = await saved();
		t.check( 'replace moves the block to the new file', raw.includes( `<!-- wp:video {"id":${ second.id }} -->` ) && raw.includes( 'minn-second-cut' ) && ! raw.includes( 'minn-route-30' ), raw.slice( -300 ) );

		// Remove.
		await vf.scrollIntoViewIfNeeded();
		await vf.hover();
		await page.waitForTimeout( 500 );
		await page.locator( '.minn-code-chip.shown' ).click();
		await page.waitForSelector( '[data-media-remove]', { timeout: 5000 } );
		await page.click( '[data-media-remove]' );
		await page.waitForTimeout( 500 );
		await save();
		raw = await saved();
		t.check( 'remove takes the whole block', ! raw.includes( 'wp:video' ) && ! raw.includes( 'wp-block-video' ), raw.slice( -300 ) );
		t.check( 'and leaves the others', raw.includes( 'wp:audio' ) && raw.includes( 'Opening line.' ), '' );

		// A file dropped on the editor.
		await page.locator( '#minn-editor-body > p' ).first().scrollIntoViewIfNeeded();
		const dropped = page.waitForResponse( ( r ) => /wp\/v2\/media$/.test( new URL( r.url() ).pathname ) && 'POST' === r.request().method() );
		await page.evaluate( () => {
			const dt = new DataTransfer();
			dt.items.add( new File( [ 'minn test media dropped' ], 'minn-dropped.mp3', { type: 'audio/mpeg' } ) );
			const el = document.querySelector( '#minn-editor-body > p' );
			const r = el.getBoundingClientRect();
			el.dispatchEvent( new DragEvent( 'drop', { bubbles: true, cancelable: true, dataTransfer: dt, clientX: r.left + 5, clientY: r.top + 5 } ) );
		} );
		const dropRes = await ( await dropped ).json();
		uploaded.push( dropRes.id );
		await page.waitForTimeout( 1000 );
		t.check( 'a dropped audio file becomes an audio block', await page.locator( '#minn-editor-body figure.wp-block-audio' ).count() === 2, '' );
		await save();
		t.check( 'and saves', ( await saved() ).includes( `<!-- wp:audio {"id":${ dropRes.id }} -->` ), '' );
	} finally {
		await deletePost( page, id ).catch( () => {} );
		for ( const m of uploaded ) await rest( `wp/v2/media/${ m }?force=true`, 'DELETE' ).catch( () => {} );
		await t.done( browser, errors );
	}
} )();
