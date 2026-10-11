/**
 * Comment body/author editing, and no one-click Block commenter.
 *
 * Edit: inline box on Pending/Approved rows — body for everyone, author
 * name/email only on guest comments (registered comments keep their account
 * identity). Block commenter was removed in v0.45.0: core matches each
 * disallowed_keys line as a substring of every later comment, so an address
 * the commenter chose can't stand for them alone. The right-click menu no
 * longer offers it and its route is gone.
 *
 * minnadmin runs Disable Comments as a resident fixture — deactivated for
 * the run, restored in finally (rule-53 pattern).
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'comment-edit-block' );

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
	const spamState = async () => ( await api( 'minn-admin/v1/spam' ) ).body || {};

	let postId = null;
	let guestId = null;
	let adminId = null;
	const guestEmail = `blocked-${ Date.now().toString( 36 ) }@example.com`;
	try {
		t.check( 'disable-comments deactivates over REST', await setPlugin( 'disable-comments/disable-comments', 'inactive' ) );

		postId = await createPost( page, { title: 'Edit block fixture', content: '<p>body</p>', status: 'publish', comment_status: 'open' } );
		const g = await api( 'wp/v2/comments', { method: 'POST', body: JSON.stringify( {
			post: postId, author_name: 'Guest Gwen', author_email: guestEmail, content: 'Original guest text', status: 'approved',
		} ) } );
		guestId = g.body && g.body.id;
		const m = await api( 'wp/v2/comments', { method: 'POST', body: JSON.stringify( { post: postId, content: 'Admin comment text' } ) } );
		adminId = m.body && m.body.id;
		t.check( 'guest + admin comments created', !! guestId && !! adminId, `${ guestId } / ${ adminId }` );

		await page.goto( BASE + '/minn-admin/comments', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-ctab="approve"]', { timeout: 20000 } );
		await page.click( '[data-ctab="approve"]' );
		await page.waitForSelector( `[data-cmedit="${ guestId }"]`, { timeout: 20000 } );

		// Guest edit: body + author fields.
		await page.click( `[data-cmedit="${ guestId }"]` );
		await page.waitForSelector( '#minn-cedit-text', { timeout: 10000 } );
		t.check( 'guest edit box offers author name/email', !! ( await page.$( '#minn-cedit-name' ) ) && !! ( await page.$( '#minn-cedit-email' ) ), '' );
		const seeded = await page.evaluate( () => document.querySelector( '#minn-cedit-text' ).value );
		t.check( 'edit box seeds the raw body', /Original guest text/.test( seeded ), seeded.slice( 0, 40 ) );
		await page.fill( '#minn-cedit-text', 'Corrected guest text' );
		await page.fill( '#minn-cedit-name', 'Gwen Fixed' );
		await page.click( '#minn-cedit-save' );
		await page.waitForFunction( ( id ) => ! document.querySelector( '#minn-cedit-text' ), null, { timeout: 15000 } );
		const after = await api( `wp/v2/comments/${ guestId }?context=edit&_fields=content,author_name` );
		t.check( 'body and author name saved',
			/Corrected guest text/.test( after.body.content.raw ) && after.body.author_name === 'Gwen Fixed',
			JSON.stringify( { raw: after.body.content.raw, name: after.body.author_name } ) );

		// Registered comment: no author fields.
		await page.waitForSelector( `[data-cmedit="${ adminId }"]`, { timeout: 15000 } );
		await page.click( `[data-cmedit="${ adminId }"]` );
		await page.waitForSelector( '#minn-cedit-text', { timeout: 10000 } );
		t.check( 'registered comment hides author fields', ! ( await page.$( '#minn-cedit-name' ) ), '' );
		await page.click( '#minn-cedit-cancel' );

		// The right-click menu offers the row's verbs and nothing that writes
		// the disallowed list.
		const before = String( ( await spamState() ).disallowed_keys || '' );
		const row = await page.$( `.minn-comment-row[data-crow="${ guestId }"]` );
		await row.click( { button: 'right' } );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( 'button, a' ) ).some( ( b ) => /Open post in editor|View post/.test( b.textContent ) ), null, { timeout: 8000 } );
		const offered = await page.evaluate( () => Array.from( document.querySelectorAll( 'button, a' ) ).some( ( b ) => /Block commenter/.test( b.textContent ) ) );
		t.check( 'the menu offers no Block commenter', ! offered, '' );
		await page.keyboard.press( 'Escape' );
		const gone = await api( `minn-admin/v1/comments/${ guestId }/block`, { method: 'POST' } );
		t.check( 'the block route is gone', 404 === gone.status, String( gone.status ) );
		const keysNow = String( ( await spamState() ).disallowed_keys || '' );
		t.check( 'the disallowed list is untouched', keysNow === before, '' );
	} finally {
		await deletePost( page, postId );
		await setPlugin( 'disable-comments/disable-comments', 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
