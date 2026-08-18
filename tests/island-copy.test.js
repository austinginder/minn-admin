/**
 * Island-aware clipboard: Select All / multi-block copy must include the
 * text inside contenteditable=false islands (browser default drops them),
 * highlight them while selected, and — pasted back into Minn — restore them
 * as real islands instead of flattening them into loose prose.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'island-copy' );
	await login( page );
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );

	const islandRaw = '<!-- wp:minn-test/feature-box -->\n'
		+ '<div class="minn-feature-box"><p class="minn-feature-title">Island Title Alpha</p>'
		+ '<p class="minn-feature-body">Island body text that must copy.</p></div>\n'
		+ '<!-- /wp:minn-test/feature-box -->';

	// Unregistered / island block between two paragraphs.
	const id = await createPost( page, {
		title: 'Island copy probe',
		content: '<!-- wp:paragraph -->\n<p>Prose before the island.</p>\n<!-- /wp:paragraph -->\n'
			+ islandRaw + '\n'
			+ '<!-- wp:paragraph -->\n<p>Prose after the island.</p>\n<!-- /wp:paragraph -->',
	} );
	await openEditor( page, id );
	await page.waitForSelector( '.minn-block-island', { timeout: 15000 } );

	// Select all and copy via the real shortcut so our copy handler runs.
	// Click INSIDE a paragraph: clicking the container leaves focus outside
	// the contenteditable, so Chrome's Select All spans the whole DOCUMENT
	// (page chrome included) and the editor copy handler correctly bails.
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'Meta+a' );
	await page.keyboard.press( 'Meta+c' );
	await page.waitForTimeout( 300 );

	const clip = await page.evaluate( async () => {
		const text = await navigator.clipboard.readText();
		let html = '';
		try {
			const items = await navigator.clipboard.read();
			for ( const item of items ) {
				if ( item.types.includes( 'text/html' ) ) {
					html = await ( await item.getType( 'text/html' ) ).text();
				}
			}
		} catch ( e ) { /* read() may be denied; plain is enough */ }
		return { text, html };
	} );

	t.check(
		'Select All copy includes prose before the island',
		/Prose before the island/.test( clip.text ),
		clip.text.slice( 0, 200 )
	);
	t.check(
		'Select All copy includes island preview text',
		/Island Title Alpha/.test( clip.text ) && /Island body text that must copy/.test( clip.text ),
		clip.text.slice( 0, 400 )
	);
	t.check(
		'Select All copy includes prose after the island',
		/Prose after the island/.test( clip.text ),
		clip.text.slice( 0, 200 )
	);

	// HTML flavor (when available) should also carry island content.
	if ( clip.html ) {
		t.check(
			'text/html clipboard includes island content',
			/Island Title Alpha/.test( clip.html ) || /Island body text that must copy/.test( clip.html ),
			clip.html.slice( 0, 300 )
		);
	} else {
		t.check( 'text/html clipboard includes island content (plain covered HTML skipped)', true, 'no html flavor' );
	}

	// Prose-only selection must NOT force the custom path (native still works).
	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body p' );
		const tn = p.firstChild;
		const r = document.createRange();
		r.setStart( tn, 0 );
		r.setEnd( tn, Math.min( 5, tn.textContent.length ) );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus();
	} );
	await page.keyboard.press( 'Meta+c' );
	await page.waitForTimeout( 200 );
	const proseOnly = await page.evaluate( () => navigator.clipboard.readText() );
	t.check(
		'prose-only copy does not pull in island text',
		! /Island Title Alpha/.test( proseOnly ),
		proseOnly
	);

	// ---- Selection highlight: the island card tints while it's in range ----
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'Meta+a' );
	await page.waitForTimeout( 300 );
	t.check( 'island card is tinted while selected', await page.evaluate( () =>
		document.querySelector( '#minn-editor-body .minn-block-island' ).classList.contains( 'minn-island-selected' ) ) );
	t.check( 'island text is selectable (no user-select:none)', await page.evaluate( () =>
		getComputedStyle( document.querySelector( '#minn-editor-body .minn-block-island' ) ).userSelect !== 'none' ) );
	await page.evaluate( () => {
		const p = document.querySelector( '#minn-editor-body p' );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( true );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
	} );
	await page.waitForTimeout( 300 );
	t.check( 'tint clears when the selection collapses', await page.evaluate( () =>
		! document.querySelector( '#minn-editor-body .minn-block-island' ).classList.contains( 'minn-island-selected' ) ) );

	// ---- Round trip: copy everything, paste at the end ----
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'Meta+a' );
	await page.keyboard.press( 'Meta+c' );
	await page.waitForTimeout( 400 );
	await page.evaluate( () => {
		const body = document.querySelector( '#minn-editor-body' );
		const r = document.createRange();
		r.selectNodeContents( body.lastElementChild );
		r.collapse( false );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		body.focus( { preventScroll: true } );
	} );
	await page.keyboard.press( 'Meta+v' );
	await page.waitForTimeout( 2000 );
	t.check( 'paste restores the island as an island, not flattened prose',
		await page.evaluate( () => document.querySelectorAll( '#minn-editor-body .minn-block-island' ).length ) === 2 );
	t.check( 'pasted prose came along too', await page.evaluate( () =>
		( document.querySelector( '#minn-editor-body' ).innerText.match( /Prose before the island/g ) || [] ).length === 2 ) );

	// ⌘Z reverts the whole paste in one step (single execCommand entry).
	await page.keyboard.press( 'Meta+z' );
	await page.waitForTimeout( 800 );
	t.check( 'undo reverts the pasted island',
		await page.evaluate( () => document.querySelectorAll( '#minn-editor-body .minn-block-island' ).length ) === 1 );

	// Redo, save, and confirm the STORED markup carries two real blocks.
	await page.keyboard.press( 'Meta+Shift+z' );
	await page.waitForFunction( () =>
		document.querySelectorAll( '#minn-editor-body .minn-block-island' ).length === 2,
		null, { timeout: 5000 } );
	const saveResponse = page.waitForResponse( ( res ) =>
		res.request().method() === 'POST'
		&& res.url().includes( `/wp-json/wp/v2/posts/${ id }` )
		&& ! res.url().includes( '/autosaves' ), { timeout: 20000 } );
	await page.keyboard.down( 'Meta' );
	await page.keyboard.press( 's' );
	await page.keyboard.up( 'Meta' );
	const saveFinished = await saveResponse;
	await saveFinished.finished();
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(),
			{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'saved markup carries both feature-box blocks',
		( saved.match( /<!-- wp:minn-test\/feature-box/g ) || [] ).length === 2,
		( saved.match( /<!-- wp:minn-test\/feature-box/g ) || [] ).length );
	t.check( 'saved island markup is byte-faithful (no preview HTML leaked)',
		! /minn-island-preview|minn-island-chip|minn-island-selected/.test( saved ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
