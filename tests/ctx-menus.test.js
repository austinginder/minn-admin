/**
 * Context menus beyond content rows: Media items (Preview / Copy URL / Open /
 * Edit image / Delete) and Comment rows (the row's own moderation verbs,
 * built FROM its buttons so the menu can never drift from the tab).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'ctx-menus' );
	await login( page );

	/* ===== Media ===== */
	await page.goto( `${ BASE }/minn-admin/media`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-media]', { timeout: 15000 } );
	await page.click( '[data-media]', { button: 'right' } );
	await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
	const media = await page.evaluate( () => ( {
		labels: [ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-ctx-menu a' ) ].map( ( e ) => e.textContent.trim() ),
		openHref: ( document.querySelector( '.minn-ctx-menu a[href*="uploads"]' ) || {} ).href || '',
	} ) );
	t.check( 'media menu offers the item verbs', media.labels.includes( 'Preview' ) && media.labels.includes( 'Copy URL' ) && media.labels.includes( 'Delete' ), JSON.stringify( media.labels ) );
	t.check( 'image items offer the in-app editor', media.labels.includes( 'Edit image' ), JSON.stringify( media.labels ) );
	await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Preview' ).click() );
	await page.waitForSelector( '#minn-modal-overlay .minn-modal.media', { timeout: 5000 } );
	t.check( 'Preview opens the media modal', true, '' );
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );

	/* ===== Comments (seed a pending comment, moderate via the menu) ===== */
	const cid = await page.evaluate( async () => {
		const mk = await fetch( window.MINN.restUrl + 'wp/v2/comments', {
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { post: 1, content: 'Context menu probe comment' } ),
		} );
		const c = await mk.json();
		await fetch( window.MINN.restUrl + 'wp/v2/comments/' + c.id, {
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: 'hold' } ),
		} );
		return c.id;
	} );
	await page.goto( `${ BASE }/minn-admin/comments`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( `.minn-comment-row[data-crow="${ cid }"]`, { timeout: 15000 } );
	await page.click( `.minn-comment-row[data-crow="${ cid }"]`, { button: 'right' } );
	await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
	const cm = await page.$$eval( '.minn-ctx-menu button', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	t.check( 'comment menu mirrors the pending-tab verbs', cm.includes( 'Approve' ) && cm.some( ( l ) => /Spam/i.test( l ) ) && cm.some( ( l ) => /Trash/i.test( l ) ), JSON.stringify( cm ) );
	await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].find( ( b ) => b.textContent.trim() === 'Approve' ).click() );
	await page.waitForFunction( ( id ) => ! document.querySelector( `.minn-comment-row[data-crow="${ id }"]` ), cid, { timeout: 10000 } );
	const status = await page.evaluate( async ( id ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/comments/' + id + '?_fields=status', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).status;
	}, cid );
	t.check( 'Approve via the menu persists', status === 'approved', status );

	// Cleanup the fixture comment.
	await page.evaluate( async ( id ) => {
		await fetch( window.MINN.restUrl + 'wp/v2/comments/' + id + '?force=true', { method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce } } );
	}, cid );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
