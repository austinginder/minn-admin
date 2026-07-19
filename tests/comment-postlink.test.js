/**
 * Comment rows open their post — the "on Post title" is an editor door
 * (posts AND pages resolve), a ↗ views the post on the site landing at the
 * comment, and the right-click menu leads with the post doors.
 *
 * minnadmin runs Disable Comments as a resident fixture, so the suite
 * deactivates it for the run and restores it in finally (rule-53 pattern).
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'comment-postlink' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );
	const setPlugin = async ( plugin, status ) => {
		const r = await api( `wp/v2/plugins/${ plugin }`, { method: 'PUT', body: JSON.stringify( { status } ) } );
		return r.status === 200;
	};

	let postId = null;
	let pageId = null;
	try {
		t.check( 'disable-comments deactivates over REST', await setPlugin( 'disable-comments/disable-comments', 'inactive' ) );

		postId = await createPost( page, { title: 'Comment door post', content: '<p>body</p>', status: 'publish', comment_status: 'open' } );
		const pg = await api( 'wp/v2/pages', { method: 'POST', body: JSON.stringify( { title: 'Comment door page', content: '<p>body</p>', status: 'publish', comment_status: 'open' } ) } );
		pageId = pg.body && pg.body.id;
		t.check( 'fixture post + page created', !! postId && !! pageId, `${ postId } / ${ pageId }` );

		const c1 = await api( 'wp/v2/comments', { method: 'POST', body: JSON.stringify( { post: postId, content: 'Door suite comment on the post' } ) } );
		const c2 = await api( 'wp/v2/comments', { method: 'POST', body: JSON.stringify( { post: pageId, content: 'Door suite comment on the page' } ) } );
		t.check( 'comments created (auto-approved as admin)', c1.status === 201 && c2.status === 201, `${ c1.status } / ${ c2.status }` );

		await page.goto( BASE + '/minn-admin/comments', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-ctab="approve"]', { timeout: 20000 } );
		await page.click( '[data-ctab="approve"]' );
		await page.waitForSelector( `.minn-comment-row [data-cedit="posts:${ postId }"]`, { timeout: 20000 } );
		t.check( 'post comment title is an editor door', true, '' );
		t.check( 'page comment resolves with the pages REST base',
			!! ( await page.$( `.minn-comment-row [data-cedit="pages:${ pageId }"]` ) ), '' );
		const titleText = await page.evaluate( ( id ) => document.querySelector( `[data-cedit="posts:${ id }"]` ).textContent.trim(), postId );
		t.check( 'door wears the post title', titleText === 'Comment door post', titleText );
		const viewHref = await page.evaluate( ( id ) => {
			const row = document.querySelector( `[data-cedit="posts:${ id }"]` ).closest( '.minn-comment-row' );
			const a = row.querySelector( '.minn-comment-postview' );
			return a ? a.getAttribute( 'href' ) : null;
		}, postId );
		t.check( 'view link is the comment permalink', !! viewHref && /#comment-\d+/.test( viewHref ) && viewHref.includes( 'comment-door-post' ), String( viewHref ) );

		// Right-click menu leads with the post doors.
		const row = await page.$( `.minn-comment-row:has([data-cedit="posts:${ postId }"])` );
		await row.click( { button: 'right' } );
		await page.waitForSelector( '.minn-menu, .minn-new-menu, [class*=minn-menu]', { timeout: 8000 } );
		const menuLabels = await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-menu button, .minn-menu a, .minn-new-menu button, .minn-new-menu a' ) ).map( ( e ) => e.textContent.trim() ) );
		t.check( 'menu leads with Open post in editor + View post',
			menuLabels[ 0 ] === 'Open post in editor' && /^View post/.test( menuLabels[ 1 ] || '' ), JSON.stringify( menuLabels.slice( 0, 4 ) ) );
		await page.keyboard.press( 'Escape' );

		// The door lands in the editor.
		await page.evaluate( ( id ) => document.querySelector( `[data-cedit="posts:${ id }"]` ).click(), postId );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		t.check( 'clicking the title opens the Minn editor', page.url().includes( `/minn-admin/editor/posts/${ postId }` ), page.url() );
	} finally {
		await deletePost( page, postId );
		if ( pageId ) await api( `wp/v2/pages/${ pageId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await setPlugin( 'disable-comments/disable-comments', 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
