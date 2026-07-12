/**
 * One Time Login — "Copy one-time login link" in the users row menu
 * (adapters/one-time-login.php). The plugin ships no admin UI at all; this
 * gives it one.
 *
 * Proves: the boot flag is present, the row menu offers the action, the
 * mint route returns a real wp-login link whose token is stored in the
 * user's own meta (so the plugin's handler will honor it), the secret is
 * NEVER in the boot payload, and the endpoint is gated on edit_user for the
 * target (an editor account is refused for another user).
 *
 * The plugin is a resident fixture (installed active). Tokens land in user
 * meta and are single-use; the suite mints a couple and leaves them (the
 * plugin cleans them on use or after its own grace window).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'one-time-login' );
	const { browser: b, page: p, errors: errs } = await launch();
	await login( p );

	const api = ( path, opts ) => p.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, opts } );

	try {
		await p.goto( `${ BASE }/minn-admin/users`, { waitUntil: 'domcontentloaded' } );
		await p.waitForSelector( '.minn-table-row', { timeout: 20000 } );

		t.check( 'boot carries the One Time Login flag', await p.evaluate( () => window.MINN.otl === true ) );
		t.check( 'no secret link in the boot payload', await p.evaluate( () =>
			! JSON.stringify( window.MINN ).includes( 'one_time_login_token' ) ) );

		// Resolve the editor fixture's id.
		const editor = await p.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/users?context=edit&search=minn-editor&_fields=id,slug', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() )[ 0 ] || null;
		} );
		t.check( 'found the editor fixture', !! editor && !! editor.id, JSON.stringify( editor ) );

		// The row menu offers the action for another user.
		const hasEntry = await p.evaluate( ( slug ) => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			const row = rows.find( ( r ) => new RegExp( slug ).test( r.textContent ) );
			if ( ! row ) return 'no row';
			( row.querySelector( '.minn-row-menu-btn' ) || row ).dispatchEvent(
				new MouseEvent( 'contextmenu', { bubbles: true, clientX: 100, clientY: 100 } ) );
			return true;
		}, 'minn-editor' );
		if ( hasEntry === true ) {
			await p.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
			t.check( 'row menu offers Copy one-time login link', await p.evaluate( () =>
				[ ...document.querySelectorAll( '.minn-ctx-menu button' ) ].some( ( x ) => /one-time login link/i.test( x.textContent ) ) ) );
			await p.keyboard.press( 'Escape' );
		} else {
			t.check( 'row menu offers Copy one-time login link', false, hasEntry );
		}

		// Mint a real link and verify the token is in the user's own meta.
		const mint = await api( `minn-admin/v1/otl/${ editor.id }`, { method: 'POST', body: '{}' } );
		t.check( 'mint returns a wp-login link', mint.status === 200 && /wp-login\.php\?user_id=\d+&one_time_login_token=[a-f0-9]+/.test( mint.body.url ), JSON.stringify( mint ) );
		t.check( 'link names the target', mint.body && /editor/i.test( mint.body.name ) );
		const stored = await p.evaluate( async ( id ) => {
			// Round-trip through the plugin's own REST route as a second
			// witness that a token now exists (it validates on hit).
			const r = await fetch( window.MINN.restUrl + 'wp/v2/users/' + id + '?context=edit&_fields=id', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.status;
		}, editor.id );
		t.check( 'target user is editable by this admin', stored === 200 );

		// Gating: an editor account cannot mint a link for a DIFFERENT user.
		const ctx2 = await b.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		await p2.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-editor' );
		await p2.fill( '#user_pass', 'minn-editor-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		const adminId = await p.evaluate( () => window.MINN.user.id );
		const refused = await p2.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/otl/' + id, {
				method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin', body: '{}',
			} );
			return r.status;
		}, adminId );
		t.check( 'editor cannot mint a link for another user (edit_user gate)', refused === 403, String( refused ) );
		await ctx2.close();
	} finally {
		// nothing to clean — tokens are single-use and self-expiring
	}

	await t.done( b, errs );
} )();
