/**
 * Autosave semantics — the editor's safety contract:
 *  - drafts autosave in place after idle, never eagerly
 *  - published posts are NEVER written by autosave (backup revision only)
 *  - Save draft / ⌘S save immediately; pending autosaves flush on navigation
 * Slow by design (~90s): the idle timings ARE the subject under test.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'autosave' );
	await login( page );
	const draftId = await createPost( page, {
		title: 'Autosave draft test',
		content: '<!-- wp:paragraph -->\n<p>Draft body text.</p>\n<!-- /wp:paragraph -->',
	} );
	const pubId = await createPost( page, {
		title: 'Autosave published test',
		content: '<!-- wp:paragraph -->\n<p>Original live sentence stays put.</p>\n<!-- /wp:paragraph -->',
		status: 'publish',
	} );

	const writes = [];
	page.on( 'request', ( r ) => {
		if ( r.method() === 'POST' && r.url().includes( '/wp-json/wp/v2/' ) ) writes.push( r.url() );
	} );

	/* ===== Draft: explicit saves + calm autosave ===== */
	await openEditor( page, draftId );
	t.check( 'Save draft button on drafts', ( await page.locator( '#minn-save-draft-btn' ).count() ) === 1 );

	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' first edit' );
	writes.length = 0;
	await page.click( '#minn-save-draft-btn' );
	await page.waitForTimeout( 1500 );
	t.check( 'Save draft posts immediately', writes.some( ( u ) => u.includes( `/posts/${ draftId }` ) ), writes.join() );

	writes.length = 0;
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' more' );
	// Wait for the POST (1.5s flat race was too tight under load; Meta+s is
	// also flaky in headless — Control+s is accepted by the same handler).
	const waitPost = () => page.waitForRequest(
		( r ) => r.method() === 'POST' && r.url().includes( `/posts/${ draftId }` ) && ! r.url().includes( 'autosaves' ),
		{ timeout: 4000 }
	).catch( () => null );
	let sawSave = waitPost();
	await page.keyboard.press( 'Meta+s' );
	if ( ! ( await sawSave ) ) {
		sawSave = waitPost();
		await page.keyboard.press( 'Control+s' );
		await sawSave;
	}
	t.check( 'Cmd+S saves immediately', writes.some( ( u ) => u.includes( `/posts/${ draftId }` ) ), writes.join() );

	// Let the save chain FULLY drain before opening the calm window. The
	// saved-indicator check was not enough: it already reads "just now" from
	// the Save-draft save, while a congested first load (notices capture,
	// editor styles) can delay the ⌘S save — and the Control+s fallback's
	// second queued save — by several seconds. Watch the request traffic
	// itself: proceed only after 2.5s with no posts/{id} POST starting or
	// finishing.
	let lastPostActivity = Date.now();
	const bumpActivity = ( r ) => {
		if ( r.method() === 'POST' && r.url().includes( `/posts/${ draftId }` ) ) lastPostActivity = Date.now();
	};
	page.on( 'request', bumpActivity );
	page.on( 'requestfinished', bumpActivity );
	while ( Date.now() - lastPostActivity < 2500 ) await page.waitForTimeout( 250 );
	page.off( 'request', bumpActivity );
	page.off( 'requestfinished', bumpActivity );
	writes.length = 0;
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' typing along' );
	await page.waitForTimeout( 5000 );
	t.check( 'No autosave within 5s of typing', writes.length === 0, writes.join() );
	await page.waitForTimeout( 12000 );
	t.check( 'Autosave fires after the idle window', writes.some( ( u ) => u.includes( `/posts/${ draftId }` ) ), writes.join() );

	const savedVal = await page.textContent( '#minn-saved-state' );
	t.check( 'Saved indicator settles', /now|ago/.test( savedVal ), savedVal );

	/* ===== Published: autosave must never touch the live post ===== */
	await openEditor( page, pubId );
	t.check( 'No Save draft button on published', ( await page.locator( '#minn-save-draft-btn' ).count() ) === 0 );
	writes.length = 0;
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' Edited but not published.' );
	await page.waitForTimeout( 17000 );
	t.check( 'Published autosave goes to /autosaves', writes.some( ( u ) => u.includes( `/posts/${ pubId }/autosaves` ) ), writes.join() );
	t.check( 'The live post itself is not written', ! writes.some( ( u ) => u.includes( `/posts/${ pubId }` ) && ! u.includes( 'autosaves' ) ), writes.join() );
	t.check( 'Indicator says backed up', /backed up/.test( await page.textContent( '#minn-saved-state' ) ) );

	writes.length = 0;
	await page.click( '#minn-publish-btn' );
	await page.waitForTimeout( 1500 );
	t.check( 'Update writes the live post', writes.some( ( u ) => u.includes( `/posts/${ pubId }` ) && ! u.includes( 'autosaves' ) ), writes.join() );

	/* ===== Flush on SPA navigation ===== */
	await openEditor( page, draftId );
	writes.length = 0;
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' navflush' );
	await page.waitForTimeout( 300 );
	await page.click( '.minn-nav-btn[data-nav="content"]' );
	await page.waitForTimeout( 1500 );
	t.check( 'Pending autosave flushes on navigate', writes.some( ( u ) => u.includes( `/posts/${ draftId }` ) ), writes.join() );

	await page.goto( ( process.env.MINN_TEST_URL || 'https://minnadmin.localhost' ) + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce );
	await deletePost( page, draftId );
	await deletePost( page, pubId );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( 'SCRIPT ERROR', e );
	process.exit( 2 );
} );
