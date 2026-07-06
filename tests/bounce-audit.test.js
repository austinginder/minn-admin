/**
 * Bounce-audit picks: content-row quick actions (right-click or hover ⋯ —
 * publish/draft/trash without opening the editor, plus view + block-editor
 * escapes), the media "Edit image" deep link, and the self-deactivate modal
 * (Keep Minn cancels safely — the deactivation itself is never exercised).
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'bounce-audit' );
	await login( page );

	const id = await createPost( page, { title: 'Row action probe', content: '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', status: 'draft' } );
	await page.goto( `${ BASE }/minn-admin/content`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( `.minn-table-row[data-id="${ id }"]`, { timeout: 15000 } );

	/* ===== Hover ⋯ opens the menu with draft-appropriate items ===== */
	const row = `.minn-table-row[data-id="${ id }"]`;
	await page.hover( row );
	await page.click( `${ row } .minn-row-more` );
	await page.waitForSelector( '.minn-row-menu', { timeout: 5000 } );
	const items = await page.evaluate( () => ( {
		labels: [ ...document.querySelectorAll( '.minn-row-menu button, .minn-row-menu a' ) ].map( ( e ) => e.textContent.trim() ),
		blockHref: ( document.querySelector( '.minn-row-menu a[href*="action=edit"]' ) || {} ).href || '',
	} ) );
	t.check( 'draft menu offers preview, block editor, publish, trash', items.labels.join( '|' ).includes( 'Preview draft ↗' ) && items.labels.includes( 'Publish now' ) && items.labels.includes( 'Move to trash' ) && ! items.labels.includes( 'Move to draft' ), JSON.stringify( items.labels ) );
	t.check( 'block-editor escape targets the post', items.blockHref.includes( `post.php?post=${ id }&action=edit` ), items.blockHref );

	/* ===== Publish now — verified over REST ===== */
	await page.click( '.minn-row-menu [data-ract="publish"]' );
	await page.waitForFunction( ( pid ) => {
		const r = document.querySelector( `.minn-table-row[data-id="${ pid }"]` );
		return r && r.dataset.status === 'publish';
	}, id, { timeout: 10000 } );
	const st1 = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?_fields=status', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).status;
	}, id );
	t.check( 'Publish now persists', st1 === 'publish', st1 );

	/* ===== Right-click opens the same menu; Move to draft ===== */
	await page.click( row, { button: 'right' } );
	await page.waitForSelector( '.minn-row-menu', { timeout: 5000 } );
	const pubLabels = await page.$$eval( '.minn-row-menu button, .minn-row-menu a', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	t.check( 'published menu flips to View + Move to draft', pubLabels.join( '|' ).includes( 'View on site ↗' ) && pubLabels.includes( 'Move to draft' ) && ! pubLabels.includes( 'Publish now' ), JSON.stringify( pubLabels ) );
	// evaluate-click: Playwright's right-click sequence can re-open the menu
	// mid-actionability-wait, detaching the first node.
	await page.evaluate( () => document.querySelector( '.minn-row-menu [data-ract="draft"]' ).click() );
	await page.waitForFunction( ( pid ) => {
		const r = document.querySelector( `.minn-table-row[data-id="${ pid }"]` );
		return r && r.dataset.status === 'draft';
	}, id, { timeout: 10000 } );
	t.check( 'Move to draft persists', true, '' );

	/* ===== Trash from the menu ===== */
	await page.click( row, { button: 'right' } );
	await page.waitForSelector( '.minn-row-menu' );
	await page.evaluate( () => document.querySelector( '.minn-row-menu [data-ract="trash"]' ).click() );
	await page.waitForFunction( ( pid ) => ! document.querySelector( `.minn-table-row[data-id="${ pid }"]` ), id, { timeout: 10000 } );
	const st2 = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?_fields=status', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).status;
	}, id );
	t.check( 'Move to trash persists and drops the row', st2 === 'trash', st2 );

	/* ===== Media: Edit image deep link ===== */
	await page.goto( `${ BASE }/minn-admin/media`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-media-item, .minn-media-grid [data-media]', { timeout: 15000 } );
	await page.click( '.minn-media-item, .minn-media-grid [data-media]' );
	await page.waitForSelector( '#minn-modal-overlay', { timeout: 10000 } );
	await page.click( '#minn-media-edit-image' );
	await page.waitForSelector( '#minn-imged-stage', { timeout: 5000 } );
	t.check( 'media preview opens Minn\'s own image editor', true, '' );
	await page.click( '#minn-imged-cancel' );
	await page.waitForSelector( '.minn-modal.media', { timeout: 5000 } );
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );

	/* ===== Self-deactivate: modal with a safe way out ===== */
	await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-toggle="minn-admin/minn-admin"]', { timeout: 15000 } );
	await page.click( '[data-toggle="minn-admin/minn-admin"]' );
	await page.waitForSelector( '#minn-off-cancel', { timeout: 5000 } );
	const modalText = await page.$eval( '.minn-modal', ( el ) => el.textContent );
	t.check( 'self-deactivate opens a Minn modal, not a native confirm', /Deactivate Minn Admin\?/.test( modalText ) && /Nothing is lost/.test( modalText ), '' );
	await page.click( '#minn-off-cancel' );
	await page.waitForTimeout( 400 );
	const stillOn = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/minn-admin/minn-admin', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).status;
	} );
	t.check( 'Keep Minn cancels without deactivating or navigating', stillOn === 'active' && page.url().includes( '/minn-admin/' ), stillOn );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
