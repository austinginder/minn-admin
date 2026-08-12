/**
 * Local crash net: every edit lands in localStorage within ~1.2s; a session
 * that dies before autosave (crash, killed tab, dismissed unload warning)
 * offers its work back on the next open. Snapshots clear after a real save.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'localnet' );
	// Hard navigations with a dirty editor raise the unload warning — leave anyway.
	page.on( 'dialog', ( d ) => d.accept() );
	await login( page );

	const postId = await createPost( page, {
		title: 'Net test post',
		content: '<!-- wp:paragraph -->\n<p>Original text.</p>\n<!-- /wp:paragraph -->',
	} );
	const netKey = `minn-net-posts-${ postId }`;

	/* ===== Edit → snapshot lands ===== */
	await openEditor( page, postId );
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' Unsaved recovery text.' );
	await page.waitForTimeout( 2000 ); // > LOCAL_NET_DELAY
	const snap = await page.evaluate( ( k ) => localStorage.getItem( k ), netKey );
	t.check( 'snapshot written within the throttle window', !! snap && /Unsaved recovery text/.test( snap ), String( snap ).slice( 0, 120 ) );

	/* ===== "Crash": hard-leave before any autosave (15s idle not reached) ===== */
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 500 );
	const rawAfterLeave = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, postId );
	t.check( 'server never saw the edit (autosave did not fire)', ! /Unsaved recovery text/.test( rawAfterLeave ), rawAfterLeave );

	/* ===== Reopen → recovery banner → restore → save ===== */
	await openEditor( page, postId );
	await page.waitForSelector( '#minn-localnet-note', { timeout: 10000 } );
	t.check( 'recovery banner offers the local work', true );
	t.check( 'revision banner yields the slot to the local note', ! ( await page.$( '#minn-backup-note' ) ) );
	await page.click( '#minn-localnet-restore' );
	await page.waitForTimeout( 500 );
	const bodyText = await page.textContent( '#minn-editor-body' );
	t.check( 'restore brings the lost text back', /Unsaved recovery text/.test( bodyText ), bodyText );
	await page.waitForTimeout( 1500 ); // let the post-restore re-snapshot land first
	await page.keyboard.press( 'Meta+s' );
	// POLL, never a flat wait. The first save of a fresh session queues
	// behind the boot requests (notice capture, editor styles) on one h1
	// connection pool and can take several seconds, which a 1.5s window
	// reads as "the restored work never saved".
	const readRaw = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content&_cb=` + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, postId );
	let rawAfterSave = '';
	for ( let i = 0; i < 40; i++ ) {
		rawAfterSave = await readRaw();
		if ( /Unsaved recovery text/.test( rawAfterSave ) ) break;
		await page.waitForTimeout( 500 );
	}
	t.check( 'restored work saves to the server', /Unsaved recovery text/.test( rawAfterSave ), rawAfterSave );

	// The snapshot is cleared by the save's own completion handler, and only
	// when it is not newer than what that save captured, so give it its own
	// window rather than reading straight after the server confirms.
	let snapAfterSave = null;
	for ( let i = 0; i < 20; i++ ) {
		snapAfterSave = await page.evaluate( ( k ) => localStorage.getItem( k ), netKey );
		if ( ! snapAfterSave ) break;
		await page.waitForTimeout( 500 );
	}
	t.check( 'successful save clears the snapshot', ! snapAfterSave, String( snapAfterSave ).slice( 0, 120 ) );

	/* ===== Clean posts don't nag ===== */
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await openEditor( page, postId );
	await page.waitForTimeout( 1500 );
	t.check( 'no banner on a clean reopen', ! ( await page.$( '#minn-localnet-note' ) ) );

	/* ===== New, never-saved post recovers too ===== */
	await page.goto( BASE + '/minn-admin/editor/posts', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
	await page.waitForTimeout( 800 );
	await page.fill( '#minn-editor-title', 'Crash draft' );
	await page.click( '#minn-editor-body' );
	await page.keyboard.type( 'Never reached the server.' );
	await page.waitForTimeout( 2000 );
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } ); // hard-leave, no SPA flush
	await page.waitForTimeout( 500 );
	await page.goto( BASE + '/minn-admin/editor/posts', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-localnet-note', { timeout: 10000 } );
	await page.click( '#minn-localnet-restore' );
	await page.waitForTimeout( 500 );
	const newTitle = await page.inputValue( '#minn-editor-title' );
	const newBody = await page.textContent( '#minn-editor-body' );
	t.check( 'new-post recovery restores title and body', newTitle === 'Crash draft' && /Never reached the server/.test( newBody ), `${ newTitle } | ${ newBody }` );

	// Leave without letting the restored draft autosave into a real post.
	await page.evaluate( () => {
		Object.keys( localStorage ).filter( ( k ) => k.indexOf( 'minn-net-' ) === 0 ).forEach( ( k ) => localStorage.removeItem( k ) );
	} );
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );

	await deletePost( page, postId );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
