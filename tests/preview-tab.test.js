/**
 * Preview link tab reuse.
 *
 * The editor sidebar's "Preview draft ↗" (and the content row menu's
 * View/Preview link) must open in a NAMED window so repeated clicks refresh
 * one tab instead of piling up new ones — wp-admin behavior.
 *
 * The name must be core's own `wp-preview-{id}`: on the front end,
 * wp_post_preview_js() RENAMES the preview window to that exact name (and
 * clears it on pagehide), so any other target name is overwritten on first
 * load and every later click misses the lookup and spawns a fresh tab.
 * rel="noopener" must also stay off these links — severing the opener breaks
 * the named-window lookup the same way.
 *
 * Run: MINN_TEST_PASS=... node preview-tab.test.js
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'preview-tab' );
	const { browser, page, errors } = await launch();
	const ctx = page.context();
	let postId = null;

	try {
		await login( page );
		postId = await createPost( page, { title: 'preview tab suite', content: '<p>probe</p>' } );
		await openEditor( page, postId );

		const link = await page.$( '.minn-side-viewlink' );
		t.check( 'sidebar preview link renders', !! link );
		t.check( 'link targets wp-preview-{id}',
			( await link.getAttribute( 'target' ) ) === 'wp-preview-' + postId );
		t.check( 'link has no rel=noopener (would break named-window reuse)',
			! /noopener/.test( ( await link.getAttribute( 'rel' ) ) || '' ) );

		// First click opens exactly one preview tab.
		const before = ctx.pages().length;
		const [ previewPage ] = await Promise.all( [
			ctx.waitForEvent( 'page' ),
			page.click( '.minn-side-viewlink' ),
		] );
		await previewPage.waitForLoadState( 'domcontentloaded' );
		t.check( 'first click opens one tab', ctx.pages().length === before + 1 );
		t.check( 'core preview script kept the window name', await previewPage.evaluate(
			() => window.name ).catch( () => '' ) === 'wp-preview-' + postId );

		// Second click reuses that tab: page count is flat and the tab got a
		// fresh document (an expando stamped on the old document is gone).
		await previewPage.evaluate( () => { window.__minnStale = 1; } );
		await page.click( '.minn-side-viewlink' );
		let reloaded = false;
		for ( let i = 0; i < 40 && ! reloaded; i++ ) {
			await page.waitForTimeout( 250 );
			reloaded = await previewPage.evaluate( () => ! window.__minnStale ).catch( () => false );
		}
		t.check( 'second click reloads the SAME tab', reloaded );
		t.check( 'second click opened no new tab', ctx.pages().length === before + 1 );

		// Content list row menu uses the same named target.
		await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		const row = `.minn-table-row[data-id="${ postId }"]`;
		await page.waitForSelector( row, { timeout: 15000 } );
		await page.hover( row );
		await page.click( `${ row } .minn-row-more` );
		await page.waitForSelector( '.minn-row-menu', { timeout: 5000 } );
		const rowTarget = await page.evaluate( () => {
			const a = [ ...document.querySelectorAll( '.minn-row-menu a' ) ]
				.find( ( el ) => /Preview draft/.test( el.textContent ) );
			return a ? a.getAttribute( 'target' ) : null;
		} );
		t.check( 'row menu preview link targets wp-preview-{id}', rowTarget === 'wp-preview-' + postId );
	} finally {
		await deletePost( page, postId );
	}

	await t.done( browser, errors );
} )();
