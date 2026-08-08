/**
 * GH #6: a new post's discussion switches start from the SITE defaults
 * (default_comment_status / default_ping_status), and an untouched new post
 * stores those defaults, matching wp-admin. Previously the editor hardcoded
 * both switches to open.
 */
const { BASE, launch, login, deletePost, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'discussion-defaults' );
	const { browser, page, errors } = await launch();
	await login( page );

	const readSettings = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		const j = await r.json();
		return { comments: j.default_comment_status, pings: j.default_ping_status };
	} );

	// Write-then-verify with retries (REST settings writes can race boot).
	const setDefaults = async ( comments, pings ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			await page.evaluate( async ( v ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					body: JSON.stringify( { default_comment_status: v.comments, default_ping_status: v.pings } ),
				} );
			}, { comments, pings } );
			const now = await readSettings();
			if ( now.comments === comments && now.pings === pings ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const orig = await readSettings();
	let id = 0;
	try {
		t.check( 'defaults set to closed', await setDefaults( 'closed', 'closed' ) );

		// Fresh page load = fresh boot payload carrying the new defaults.
		await page.goto( BASE + '/minn-admin/editor/posts', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
		await page.waitForTimeout( 600 );

		const initState = await page.evaluate( () => {
			const s = window.MINN && window.MINN.discussion;
			return { boot: s, comment: state.editor.commentStatus, ping: state.editor.pingStatus };
		} ).catch( () => null );
		// `state` is closure-scoped; read through the UI instead when needed.
		if ( initState ) {
			t.check( 'boot payload carries defaults', initState.boot && initState.boot.comments === 'closed', JSON.stringify( initState && initState.boot ) );
		} else {
			const boot = await page.evaluate( () => window.MINN.discussion );
			t.check( 'boot payload carries defaults', boot && boot.comments === 'closed' && boot.pings === 'closed', JSON.stringify( boot ) );
		}

		// The Settings door's switches reflect the defaults.
		await page.click( '[data-side-door="settings"]' );
		await page.waitForSelector( '#minn-comment-status', { timeout: 8000 } );
		const switches = await page.evaluate( () => ( {
			comments: document.querySelector( '#minn-comment-status' ).classList.contains( 'on' ),
			pings: document.querySelector( '#minn-ping-status' ).classList.contains( 'on' ),
		} ) );
		t.check( 'comment switch starts off', switches.comments === false );
		t.check( 'ping switch starts off', switches.pings === false );
		await page.keyboard.press( 'Escape' );

		// Save an untouched draft; the stored post carries the site defaults.
		await page.fill( '#minn-editor-title', 'Discussion defaults probe' );
		await page.click( '#minn-save-draft-btn' );
		await page.waitForFunction( () => /editor\/posts\/\d+/.test( location.pathname ) || /post=\d+/.test( location.search ), null, { timeout: 15000 } ).catch( () => {} );
		await page.waitForTimeout( 2500 );
		id = await page.evaluate( async () => {
			const m = location.pathname.match( /editor\/posts\/(\d+)/ );
			if ( m ) return parseInt( m[ 1 ], 10 );
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts?status=draft&per_page=1&search=Discussion+defaults+probe', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return j.length ? j[ 0 ].id : 0;
		} );
		t.check( 'draft created', id > 0, String( id ) );

		const post = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.json();
		}, id );
		t.check( 'stored post has comments closed', post.comment_status === 'closed', post.comment_status );
		t.check( 'stored post has pings closed', post.ping_status === 'closed', post.ping_status );
	} finally {
		await deletePost( page, id );
		await setDefaults( orig.comments, orig.pings );
	}

	await t.done( browser, errors );
} )();
