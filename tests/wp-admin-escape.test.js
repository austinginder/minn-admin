/**
 * Escape hatches to wp-admin's own editor, added without new chrome:
 * a ⌘K palette command while editing, and ⌥-clicking the sidebar's
 * WordPress button (which stays a plain dashboard link otherwise).
 * Both route through openInBlockEditor, which saves first, so the tab
 * that opens shows the current content.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'wp-admin-escape' );
	const { browser, page, errors } = await launch();
	await login( page );

	let id = 0;
	try {
		id = await createPost( page, { title: 'Escape hatch probe', content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->' } );
		t.check( 'fixture post created', id > 0, String( id ) );
		await openEditor( page, id );
		await page.waitForTimeout( 1200 );

		// The palette offers the command only while editing.
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '.minn-palette', { timeout: 8000 } );
		await page.keyboard.type( 'block editor', { delay: 40 } );
		await page.waitForTimeout( 600 );
		const hit = await page.evaluate( () => {
			const rows = [ ...document.querySelectorAll( '.minn-palette-item, .minn-pal-item' ) ];
			return rows.map( ( r ) => r.textContent.trim() ).find( ( s ) => /block editor/i.test( s ) ) || '';
		} );
		t.check( 'palette offers Edit in the block editor', /block editor/i.test( hit ), hit );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 400 );

		// ⌥-click the WordPress button opens the post's wp-admin editor in a
		// new tab (a plain click stays the dashboard link).
		const popup = page.context().waitForEvent( 'page', { timeout: 45000 } ).catch( () => null );
		await page.click( '#minn-wp-admin-link', { modifiers: [ 'Alt' ] } );
		const tab = await popup;
		t.check( 'a new tab opened', !! tab );
		if ( tab ) {
			// The tab opens after the save resolves; poll until it navigates
			// off about:blank rather than reading the URL too early.
			let url = tab.url();
			for ( let i = 0; i < 20 && ! /post\.php/.test( url ); i++ ) {
				await page.waitForTimeout( 500 );
				url = tab.url();
			}
			t.check( 'it is wp-admin\'s editor for THIS post', url.indexOf( 'post.php' ) !== -1 && url.indexOf( 'post=' + id ) !== -1, url );
			await tab.close();
		}

		// Ctrl-click does the same (GitHub issue 60: Windows readers reach
		// for Ctrl, the key the help dialog names in place of ⌘). On macOS a
		// real Control-click is the secondary click and never fires click,
		// so the event is dispatched with the flag set: it exercises the
		// handler's branch, and the popup proves the default was prevented
		// in favor of the post's editor.
		const popup2 = page.context().waitForEvent( 'page', { timeout: 45000 } ).catch( () => null );
		await page.evaluate( () => document.querySelector( '#minn-wp-admin-link' ).dispatchEvent(
			new MouseEvent( 'click', { bubbles: true, cancelable: true, ctrlKey: true } ) ) );
		const tab2 = await popup2;
		let url2 = tab2 ? tab2.url() : '';
		for ( let i = 0; tab2 && i < 20 && ! /post\.php/.test( url2 ); i++ ) {
			await page.waitForTimeout( 500 );
			url2 = tab2.url();
		}
		t.check( 'Ctrl-click opens the same editor tab', !! tab2 && url2.indexOf( 'post.php' ) !== -1 && url2.indexOf( 'post=' + id ) !== -1, url2 );
		if ( tab2 ) await tab2.close();
		const title = await page.getAttribute( '#minn-wp-admin-link', 'title' );
		t.check( 'button title names the modifier and closes its parenthesis', /click while editing/.test( title ) && ! /editor\)$/.test( title ), title );

		// Plain click still goes to the profile route / dashboard link (the
		// button keeps its href, so the modifier is the only new behavior).
		const href = await page.getAttribute( '#minn-wp-admin-link', 'href' );
		t.check( 'unmodified button still points at wp-admin', !! href && /wp-admin\/?$/.test( href.replace( /\/$/, '/' ) ) || /wp-admin/.test( href ), href );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
