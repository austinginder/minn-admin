/**
 * Public Post Preview adapter — enable/disable shareable draft links through
 * the plugin's own option + get_preview_link, editor Publish card toggle,
 * content row "Copy public preview link", and anonymous front-end access.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'public-post-preview' );
	await login( page );
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );

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

	// SKIPs exit 0 when the plugin is not active (family fixture convention).
	const boot = await page.evaluate( () => !! ( window.MINN && window.MINN.ppp ) );
	if ( ! boot ) {
		t.check( 'Public Post Preview active (boot B.ppp)', false, 'plugin not active — activate public-post-preview' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'boot flag B.ppp', boot );

	const id = await createPost( page, {
		title: 'PPP suite draft',
		content: '<!-- wp:paragraph --><p>Secret draft body for public preview.</p><!-- /wp:paragraph -->',
		status: 'draft',
	} );

	// --- REST ---------------------------------------------------------------
	const off = await api( 'minn-admin/v1/ppp/' + id );
	t.check( 'GET returns eligible+disabled for draft',
		off.status === 200 && off.body.eligible === true && off.body.enabled === false && ! off.body.url,
		JSON.stringify( off ) );

	const on = await api( 'minn-admin/v1/ppp/' + id, {
		method: 'POST',
		body: JSON.stringify( { enabled: true } ),
	} );
	t.check( 'POST enable returns url with _ppp nonce',
		on.status === 200 && on.body.enabled === true
		&& typeof on.body.url === 'string'
		&& /[?&]_ppp=/.test( on.body.url )
		&& /[?&]preview=/.test( on.body.url ),
		JSON.stringify( on.body ) );

	const previewUrl = on.body.url;

	// Anonymous browser context: no cookies → must still render the draft.
	const anon = await browser.newContext( {
		ignoreHTTPSErrors: true,
		// No storageState — fresh anonymous session.
	} );
	const anonPage = await anon.newPage();
	const resp = await anonPage.goto( previewUrl, { waitUntil: 'domcontentloaded', timeout: 30000 } ).catch( ( e ) => e );
	const anonBody = await anonPage.content().catch( () => '' );
	// Proof of public access: 200 without login, title present, not the expired
	// or login screens. (Theme the_content can be empty on this marketing
	// theme for edge draft cases; title + no-auth is the PPP contract.)
	const anonOk = resp && typeof resp.status === 'function' && resp.status() < 400
		&& /PPP suite draft/.test( anonBody )
		&& ! /wp-login\.php/.test( anonPage.url() )
		&& ! /This link has expired/i.test( anonBody )
		&& ! /No public preview available/i.test( anonBody );
	// Without the nonce, the same draft must not be public.
	const bare = await anonPage.goto( BASE + '/?p=' + id, { waitUntil: 'domcontentloaded', timeout: 15000 } ).catch( () => null );
	const bareStatus = bare && bare.status ? bare.status() : 0;
	const bareBody = await anonPage.content().catch( () => '' );
	const bareDenied = bareStatus >= 400
		|| /wp-login\.php/.test( anonPage.url() )
		|| ! /PPP suite draft/.test( bareBody );
	t.check( 'anonymous visitor can open the public preview URL', anonOk,
		JSON.stringify( {
			status: resp && resp.status && resp.status(),
			url: anonPage.url(),
			hasTitle: /PPP suite draft/.test( anonBody ),
		} ) );
	t.check( 'draft stays private without the public preview nonce', bareDenied,
		JSON.stringify( { bareStatus, url: anonPage.url() } ) );
	await anon.close();

	const off2 = await api( 'minn-admin/v1/ppp/' + id, {
		method: 'POST',
		body: JSON.stringify( { enabled: false } ),
	} );
	t.check( 'POST disable clears url',
		off2.status === 200 && off2.body.enabled === false && ! off2.body.url,
		JSON.stringify( off2.body ) );

	// Publish is ineligible.
	await api( `wp/v2/posts/${ id }`, { method: 'POST', body: JSON.stringify( { status: 'publish' } ) } );
	const pub = await api( 'minn-admin/v1/ppp/' + id );
	t.check( 'published post is not eligible',
		pub.status === 200 && pub.body.eligible === false,
		JSON.stringify( pub.body ) );
	// Back to draft for UI.
	await api( `wp/v2/posts/${ id }`, { method: 'POST', body: JSON.stringify( { status: 'draft' } ) } );

	// --- Editor UI ----------------------------------------------------------
	await openEditor( page, id );
	await page.waitForSelector( '#minn-ppp-on', { timeout: 15000 } );
	t.check( 'Publish card shows Public preview toggle', true );

	await page.click( '#minn-ppp-on' );
	await page.waitForSelector( '#minn-ppp-url', { timeout: 10000 } );
	const uiUrl = await page.$eval( '#minn-ppp-url', ( el ) => el.value );
	t.check( 'enabling in the sidebar reveals the share URL', /_ppp=/.test( uiUrl ), uiUrl );

	await page.click( '#minn-ppp-copy' );
	await page.waitForFunction(
		() => Array.from( document.querySelectorAll( '.minn-toast' ) )
			.some( ( x ) => /copied|Public preview/i.test( x.textContent || '' ) ),
		null, { timeout: 8000 }
	).catch( () => null );
	const clip = await page.evaluate( () => navigator.clipboard.readText() ).catch( () => '' );
	t.check( 'Copy puts the preview URL on the clipboard', clip === uiUrl || /_ppp=/.test( clip ), clip.slice( 0, 120 ) );

	// Content row menu
	await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( `.minn-table-row[data-id="${ id }"]`, { timeout: 20000 } );
	await page.click( `.minn-table-row[data-id="${ id }"] .minn-row-more` );
	await page.waitForSelector( '.minn-row-menu', { timeout: 5000 } );
	const hasRow = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.minn-row-menu [data-ract="ppp"]' ) ).length > 0
		|| Array.from( document.querySelectorAll( '.minn-row-menu button' ) )
			.some( ( b ) => /public preview/i.test( b.textContent || '' ) ) );
	t.check( 'content row menu offers Copy public preview link', hasRow );

	// Cleanup: disable + trash
	await api( 'minn-admin/v1/ppp/' + id, { method: 'POST', body: JSON.stringify( { enabled: false } ) } );
	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
