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
	t.check( 'help dialog lists the shortcut set', [ '⌘K', '⌘S', '⌘⏎', '⌘⇧D', '⌘.', 'Esc' ].every( ( k ) => keys.includes( k ) ), JSON.stringify( keys ) );
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );

	/* ===== ⌘. toggles the navigation (⌘\ is the silent alternate) ===== */
	await page.keyboard.press( 'Meta+.' );
	await page.waitForTimeout( 400 );
	t.check( '⌘. hides the nav', await page.evaluate( () => document.body.classList.contains( 'minn-nav-hidden' ) && document.querySelector( '.minn-sidebar' ).offsetWidth < 10 ), '' );
	await page.keyboard.press( 'Meta+\\' );
	await page.waitForTimeout( 400 );
	t.check( '⌘\\ (alternate) shows it again', await page.evaluate( () => ! document.body.classList.contains( 'minn-nav-hidden' ) && document.querySelector( '.minn-sidebar' ).offsetWidth > 100 ), '' );

	/* ===== Editor: ⌘⇧D focus mode, ⌘⏎ publish ===== */
	const id = await createPost( page, { title: 'Shortcut probe', content: '<!-- wp:paragraph --><p>Body text for the probe.</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, id );
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'Meta+Shift+D' );
	await page.waitForSelector( '.minn-focus-dim', { timeout: 5000 } );
	t.check( '⌘⇧D enters focus mode', await page.evaluate( () => document.body.classList.contains( 'minn-focus-zen' ) ), '' );
	await page.keyboard.press( 'Meta+Shift+D' );
	await page.waitForTimeout( 400 );
	t.check( '⌘⇧D leaves focus mode', await page.evaluate( () => ! document.querySelector( '.minn-focus-dim' ) && ! document.body.classList.contains( 'minn-focus-zen' ) ), '' );

	await page.keyboard.press( 'Meta+Enter' );
	// Poll — publish can take several seconds under server churn; a flat
	// wait here read 'draft' mid-save and failed a working shortcut.
	let saved = 'draft';
	for ( let i = 0; i < 20 && saved !== 'publish'; i++ ) {
		await page.waitForTimeout( 500 );
		saved = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=status', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).status;
		}, id );
	}
	t.check( '⌘⏎ publishes the draft', saved === 'publish', saved );

	/* ===== ⌘⇧⌥T / ⌘⇧⌥Y move the caret's block (GH #53) ===== */
	// Matched on e.code (Option+T types "†" on a Mac). Order is read as a
	// text sequence with empty blocks filtered — moving prose past a
	// terminal island grows a trailing affordance paragraph by design.
	const mvId = await createPost( page, { title: 'Move block probe', content:
		'<!-- wp:paragraph --><p>Alpha para.</p><!-- /wp:paragraph -->\n\n'
		+ '<!-- wp:acme/mv --><div>MOVER</div><!-- /wp:acme/mv -->\n\n'
		+ '<!-- wp:paragraph --><p>Beta para.</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, mvId );
	await page.waitForSelector( '#minn-editor-body .minn-block-island', { timeout: 20000 } );
	const order = () => page.evaluate( () => Array.from( document.querySelectorAll( '#minn-editor-body > *' ) )
		.map( ( el ) => el.classList.contains( 'minn-block-island' ) ? 'ISLAND' : el.textContent.trim() )
		.filter( ( s ) => s !== '' ) );
	const caretInBeta = () => page.evaluate( () => {
		const p = Array.from( document.querySelectorAll( '#minn-editor-body > p' ) )
			.find( ( el ) => /Beta/.test( el.textContent ) );
		const r = document.createRange();
		r.selectNodeContents( p );
		r.collapse( false );
		const s = getSelection();
		s.removeAllRanges();
		s.addRange( r );
		document.querySelector( '#minn-editor-body' ).focus();
	} );
	await caretInBeta();
	await page.keyboard.press( 'Meta+Shift+Alt+Y' );
	await page.waitForTimeout( 250 );
	t.check( 'move down on the last block is a no-op',
		JSON.stringify( await order() ) === JSON.stringify( [ 'Alpha para.', 'ISLAND', 'Beta para.' ] ),
		JSON.stringify( await order() ) );
	await page.keyboard.press( 'Meta+Shift+Alt+T' );
	await page.waitForTimeout( 250 );
	t.check( '⌘⇧⌥T moves the block above the island',
		JSON.stringify( await order() ) === JSON.stringify( [ 'Alpha para.', 'Beta para.', 'ISLAND' ] ),
		JSON.stringify( await order() ) );
	await page.keyboard.press( 'Meta+Shift+Alt+T' );
	await page.waitForTimeout( 250 );
	t.check( '⌘⇧⌥T again moves it to the top',
		JSON.stringify( await order() ) === JSON.stringify( [ 'Beta para.', 'Alpha para.', 'ISLAND' ] ),
		JSON.stringify( await order() ) );
	await page.keyboard.press( 'Meta+Shift+Alt+T' );
	await page.waitForTimeout( 250 );
	t.check( 'move up on the first block is a no-op',
		JSON.stringify( await order() ) === JSON.stringify( [ 'Beta para.', 'Alpha para.', 'ISLAND' ] ),
		JSON.stringify( await order() ) );
	// The caret rode along: typing lands in the moved block.
	await page.keyboard.type( ' typed' );
	t.check( 'the caret follows the moved block',
		await page.evaluate( () => /Beta para\. typed/.test( document.querySelector( '#minn-editor-body > p' ).textContent ) ),
		await page.evaluate( () => document.querySelector( '#minn-editor-body > p' ).textContent ) );
	// The new order is what saves.
	await page.keyboard.press( 'Meta+s' );
	let mvRaw = '';
	for ( let i = 0; i < 20; i++ ) {
		await page.waitForTimeout( 500 );
		mvRaw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, mvId );
		if ( mvRaw.indexOf( 'Beta para. typed' ) !== -1 ) break;
	}
	const mvOrderSaved = mvRaw.indexOf( 'Beta para' ) < mvRaw.indexOf( 'Alpha para' )
		&& mvRaw.indexOf( 'Alpha para' ) < mvRaw.indexOf( 'wp:acme/mv' );
	t.check( 'the moved order is what saves', mvOrderSaved, mvRaw.slice( 0, 220 ) );
	await deletePost( page, mvId );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
