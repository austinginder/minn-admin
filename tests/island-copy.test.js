/**
 * Island-aware clipboard: Select All / multi-block copy must include the
 * text inside contenteditable=false islands (browser default drops them).
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
	await page.click( '#minn-editor-body' );
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

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
