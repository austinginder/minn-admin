/**
 * Global keyboard shortcuts: ⌘\ toggles the navigation, ⌘⇧D toggles focus
 * mode in the editor, ⌘⏎ publishes/updates, and the help dialog documents
 * the whole set.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'shortcuts' );
	await login( page );

	/* ===== Help dialog documents the set ===== */
	await page.click( '#minn-help-btn' );
	await page.waitForSelector( '.minn-help-keys', { timeout: 10000 } );
	const keys = await page.$$eval( '.minn-help-keys .minn-kbd', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	t.check( 'help dialog lists the shortcut set', [ '⌘K', '⌘S', '⌘⏎', '⌘⇧D', '⌘\\', 'Esc' ].every( ( k ) => keys.includes( k ) ), JSON.stringify( keys ) );
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );

	/* ===== ⌘\ toggles the navigation ===== */
	await page.keyboard.press( 'Meta+\\' );
	await page.waitForTimeout( 400 );
	t.check( '⌘\\ hides the nav', await page.evaluate( () => document.body.classList.contains( 'minn-nav-hidden' ) && document.querySelector( '.minn-sidebar' ).offsetWidth < 10 ), '' );
	await page.keyboard.press( 'Meta+\\' );
	await page.waitForTimeout( 400 );
	t.check( '⌘\\ shows it again', await page.evaluate( () => ! document.body.classList.contains( 'minn-nav-hidden' ) && document.querySelector( '.minn-sidebar' ).offsetWidth > 100 ), '' );

	/* ===== Editor: ⌘⇧D focus mode, ⌘⏎ publish ===== */
	const id = await createPost( page, { title: 'Shortcut probe', content: '<!-- wp:paragraph --><p>Body text for the probe.</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, id );
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'Meta+Shift+D' );
	await page.waitForSelector( '.minn-focus-dim', { timeout: 5000 } );
	t.check( '⌘⇧D enters focus mode', await page.$eval( '#minn-focus-btn', ( b ) => b.classList.contains( 'active' ) ), '' );
	await page.keyboard.press( 'Meta+Shift+D' );
	await page.waitForTimeout( 400 );
	t.check( '⌘⇧D leaves focus mode', await page.evaluate( () => ! document.querySelector( '.minn-focus-dim' ) && ! document.body.classList.contains( 'minn-focus-zen' ) ), '' );

	await page.keyboard.press( 'Meta+Enter' );
	await page.waitForTimeout( 2000 );
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=status', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).status;
	}, id );
	t.check( '⌘⏎ publishes the draft', saved === 'publish', saved );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
