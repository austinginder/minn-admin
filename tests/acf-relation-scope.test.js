/**
 * The relational picker route answers only for fields the caller was served.
 *
 * `minn-admin/v1/acf/relation` is gated on `edit_posts`, which says the caller
 * uses the editor at all — not which fields are theirs. Every route Minn emits
 * therefore carries a per-field, per-user signature minted at the moment the
 * field was mapped into a payload that caller was authorized to receive. This
 * is the binding ACF's own ajax gets from its per-field nonce.
 *
 * Without the signature a contributor can name any field key on the site and
 * read back that field's choices. For a `user` field that is the whole user
 * list, including logins of users who have published nothing — wider than core
 * shows the same account through wp/v2/users.
 *
 * The checks that matter, in order: the unsigned request is refused, the
 * signed one the app actually hands out still works (an over-blocking fix is a
 * regression), and a signature does not travel between accounts.
 *
 * Needs a relational ACF field on `post`. The minnadmin fixture is the
 * "Slideshow settings" group; the suite SKIPs (exit 0) where none exists.
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );

// Fetch through the page so cookie auth + the REST nonce come from the app.
const apiGet = ( page, route ) => page.evaluate( async ( r ) => {
	const res = await fetch( window.MINN.restUrl + r, {
		headers: { 'X-WP-Nonce': window.MINN.nonce },
	} );
	return { status: res.status, body: await res.json() };
}, route );

// The signed routes the app hands this user for a post-type's fields.
const servedRoutes = async ( page ) => {
	const { body } = await apiGet( page, 'minn-admin/v1/acf/fields?post_type=post' );
	const out = [];
	for ( const g of ( body && body.groups ) || [] ) {
		for ( const f of g.fields || [] ) {
			if ( f.route ) out.push( f );
		}
	}
	return out;
};

( async () => {
	const t = reporter( 'acf-relation-scope' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );

	const adminRoutes = await servedRoutes( page );
	if ( ! adminRoutes.length ) {
		console.log( 'SKIP  no relational ACF field on `post` — nothing to scope' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'every served picker route carries a signature',
		adminRoutes.every( ( f ) => /[?&]sig=[a-z0-9]+/.test( f.route ) ),
		adminRoutes.map( ( f ) => f.name ).join( ', ' ) );

	// An author is the low-privilege side of this: enough to reach the route's
	// `edit_posts` gate, not enough to list users through core.
	const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
	const authorRoutes = await servedRoutes( author.page );
	if ( ! authorRoutes.length ) {
		console.log( 'SKIP  author is served no relational field on `post`' );
		await author.ctx.close();
		await t.done( browser, errors );
		return;
	}
	const served = authorRoutes[ 0 ];
	const key = ( served.route.match( /field=([^&]+)/ ) || [] )[ 1 ];

	// 1. The unsigned request — the shape of the finding this fix closed.
	const bare = await apiGet( author.page, 'minn-admin/v1/acf/relation?field=' + key );
	t.check( 'unsigned request for a field key is refused', bare.status === 403, 'status ' + bare.status );
	t.check( 'refusal names the stale-picker code', bare.body && bare.body.code === 'minn_stale_picker', String( bare.body && bare.body.code ) );

	// A guessed signature is no better than none.
	const forged = await apiGet( author.page, 'minn-admin/v1/acf/relation?field=' + key + '&sig=deadbeef' );
	t.check( 'a made-up signature is refused', forged.status === 403, 'status ' + forged.status );

	// 2. The control: the route the app really hands this author still works.
	// A fix that breaks the picker for the account it exists for is a bug.
	const signed = await apiGet( author.page, served.route + '&q=' );
	t.check( 'the served signed route still answers for the author', signed.status === 200, 'status ' + signed.status );
	t.check( 'signed route returns picker rows', Array.isArray( signed.body ), 'field: ' + served.name );

	// 3. Signatures are user-bound: an author's cannot be replayed by anyone
	// else, so one leaked URL does not reopen the route for the whole site.
	const editor = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
	const replay = await apiGet( editor.page, served.route );
	t.check( 'another account cannot replay the author\'s signature', replay.status === 403, 'status ' + replay.status );

	const editorRoutes = await servedRoutes( editor.page );
	const mine = editorRoutes.find( ( f ) => f.name === served.name );
	if ( mine ) {
		const own = await apiGet( editor.page, mine.route + '&q=' );
		t.check( 'the editor’s own signature works', own.status === 200, 'status ' + own.status );
		t.check( 'each account gets its own signature', mine.route !== served.route );
	}

	await editor.ctx.close();
	await author.ctx.close();
	await t.done( browser, errors );
} )();
