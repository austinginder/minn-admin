/**
 * Overview activity pending-comment gate — pending comments are
 * moderation-queue data (author names included), so the Recent activity
 * feed only includes them for users with moderate_comments, matching the
 * notifications feed's gate. Approved comments stay visible to everyone.
 *
 * minnadmin runs Disable Comments as a resident fixture, so the suite
 * deactivates it for the run and restores it in finally (rule-53 pattern).
 */
const { BASE, launch, login, createPost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'activity-pending-gate' );

	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const api = ( p, path, opts ) => p.evaluate( async ( a ) => {
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
		const r = await api( page, `wp/v2/plugins/${ plugin }`, { method: 'PUT', body: JSON.stringify( { status } ) } );
		return r.status === 200;
	};
	const activityTexts = async ( p ) => {
		const r = await api( p, 'minn-admin/v1/overview' );
		return ( ( r.body && r.body.activity ) || [] ).map( ( a ) => a.text ).join( ' | ' );
	};

	let postId = null;
	const commentIds = [];
	try {
		t.check( 'disable-comments deactivates over REST', await setPlugin( 'disable-comments/disable-comments', 'inactive' ) );

		postId = await createPost( page, { title: 'Pending gate post', content: '<p>body</p>', status: 'publish', comment_status: 'open' } );
		t.check( 'fixture post created', !! postId, String( postId ) );

		// Two guest comments with distinctive names. Guest comments land in
		// moderation even when an admin creates them, so both statuses are
		// set explicitly after creation.
		const mkComment = async ( name ) => api( page, 'wp/v2/comments', {
			method: 'POST',
			body: JSON.stringify( { post: postId, content: `Gate suite comment by ${ name }`, author_name: name, author_email: `${ name.toLowerCase().replace( / /g, '.' ) }@example.com` } ),
		} );
		const approved = await mkComment( 'Approved Gater' );
		const pending  = await mkComment( 'Pending Gater' );
		if ( approved.body && approved.body.id ) commentIds.push( approved.body.id );
		if ( pending.body && pending.body.id ) commentIds.push( pending.body.id );
		t.check( 'fixture comments created', approved.status === 201 && pending.status === 201, `${ approved.status } / ${ pending.status }` );
		const appr = await api( page, `wp/v2/comments/${ approved.body.id }`, { method: 'POST', body: JSON.stringify( { status: 'approved' } ) } );
		const held = await api( page, `wp/v2/comments/${ pending.body.id }`, { method: 'POST', body: JSON.stringify( { status: 'hold' } ) } );
		t.check( 'statuses set: one approved, one hold', appr.status === 200 && appr.body.status === 'approved' && held.status === 200 && held.body.status === 'hold', `${ appr.status } ${ appr.body && appr.body.status } / ${ held.status } ${ held.body && held.body.status }` );

		/* ===== Moderator sees both rows ===== */
		const adminFeed = await activityTexts( page );
		t.check( 'moderator sees the pending row', adminFeed.includes( 'Pending Gater' ) && adminFeed.includes( 'awaiting moderation' ), adminFeed );
		t.check( 'moderator sees the approved row', adminFeed.includes( 'Approved Gater commented' ), adminFeed );

		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-activity-row', { timeout: 15000 } );
		const adminRendered = await page.$$eval( '.minn-activity-text', ( els ) => els.map( ( e ) => e.textContent ).join( ' | ' ) );
		t.check( 'rendered feed shows the pending row to a moderator', adminRendered.includes( 'Pending Gater' ), adminRendered );

		/* ===== Author (no moderate_comments) sees only the approved row ===== */
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		await p2.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-author' );
		await p2.fill( '#user_pass', 'minn-author-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );

		const authorFeed = await activityTexts( p2 );
		t.check( 'author response carries no pending comment', ! authorFeed.includes( 'Pending Gater' ) && ! authorFeed.includes( 'awaiting moderation' ), authorFeed );
		t.check( 'author still sees approved comments', authorFeed.includes( 'Approved Gater commented' ), authorFeed );

		await p2.waitForSelector( '.minn-activity-row', { timeout: 15000 } );
		const authorRendered = await p2.$$eval( '.minn-activity-text', ( els ) => els.map( ( e ) => e.textContent ).join( ' | ' ) );
		t.check( 'rendered feed hides the pending row from an author', ! authorRendered.includes( 'Pending Gater' ) && authorRendered.includes( 'Approved Gater' ), authorRendered );
		await ctx2.close();
	} finally {
		for ( const id of commentIds ) await api( page, `wp/v2/comments/${ id }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		if ( postId ) await api( page, `wp/v2/posts/${ postId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
		await setPlugin( 'disable-comments/disable-comments', 'active' ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
