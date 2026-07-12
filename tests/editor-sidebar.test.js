/**
 * Editor sidebar upgrades (from the v0.6 friction audit): editable slug,
 * per-post discussion (comments/pings), visibility (public / password /
 * private) and sticky. Everything rides wp/v2's native fields; verified
 * against SAVED post state, plus the WordPress constraints that bit during
 * development (sticky+password mutual exclusion; autosave-never-publishes).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-sidebar' );
	page.on( 'dialog', ( d ) => d.accept() );
	await login( page );

	const saved = ( id, fields = 'slug,status,comment_status,ping_status,password,sticky' ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ a.id }?context=edit&_fields=${ a.fields }`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return r.json();
	}, { id, fields } );
	const save = async () => { await page.keyboard.press( 'Meta+s' ); await page.waitForTimeout( 2000 ); };
	// Saves ride a serialized promise chain and REST writes carry every
	// active plugin's hooks, so a flat post-⌘S wait reads mid-save state
	// (the shortcuts-suite lesson). Poll the SAVED post until it converges.
	const savedWhen = async ( id2, pred, timeout = 12000 ) => {
		const start = Date.now();
		let st = await saved( id2 );
		while ( ! pred( st ) && Date.now() - start < timeout ) {
			await page.waitForTimeout( 500 );
			st = await saved( id2 );
		}
		return st;
	};

	/* ===== Slug + Discussion on a draft (saves in place) ===== */
	const id = await createPost( page, { title: 'Sidebar test', content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, id );
	await page.fill( '#minn-slug-input', 'A Custom Slug!' );
	await page.evaluate( () => document.querySelector( '#minn-slug-input' ).blur() ); // normalizes the field
	const shownSlug = await page.inputValue( '#minn-slug-input' );
	t.check( 'slug input normalizes on blur', shownSlug === 'a-custom-slug-', shownSlug );
	await page.uncheck( '#minn-comment-status' );
	await page.uncheck( '#minn-ping-status' );
	await save();
	let s = await savedWhen( id, ( x ) => x.slug === 'a-custom-slug' && x.comment_status === 'closed' && x.ping_status === 'closed' );
	t.check( 'slug persists', s.slug === 'a-custom-slug', s.slug );
	t.check( 'comments closed persists', s.comment_status === 'closed', s.comment_status );
	t.check( 'pingbacks closed persists', s.ping_status === 'closed', s.ping_status );

	/* ===== Values round-trip back into the UI on reopen ===== */
	await openEditor( page, id );
	const ui = await page.evaluate( () => ( {
		slug: document.querySelector( '#minn-slug-input' ).value,
		comments: document.querySelector( '#minn-comment-status' ).checked,
		pings: document.querySelector( '#minn-ping-status' ).checked,
		// Visibility is a themed combobox (value rides data-ac-value).
		visibility: document.querySelector( '#minn-visibility-input' )?.dataset.acValue
			|| document.querySelector( '#minn-visibility' )?.dataset?.acValue,
	} ) );
	t.check( 'reopened UI reflects saved slug + discussion', ui.slug === 'a-custom-slug' && ! ui.comments && ! ui.pings && ui.visibility === 'public', JSON.stringify( ui ) );

	/* ===== Sticky (public post) ===== */
	await page.check( '#minn-sticky' );
	await save();
	s = await savedWhen( id, ( x ) => x.sticky === true );
	t.check( 'sticky persists on a public post', s.sticky === true, String( s.sticky ) );

	// Strict combobox pick: open the panel, click the option (mousedown is
	// what bindAutocomplete listens for — page.selectOption no longer works).
	const pickVisibility = async ( value ) => {
		await page.click( '#minn-visibility-input' );
		await page.waitForSelector( `#minn-visibility .minn-ac-item[data-acv="${ value }"]`, { timeout: 5000 } );
		await page.locator( `#minn-visibility .minn-ac-item[data-acv="${ value }"]` ).dispatchEvent( 'mousedown' );
		await page.waitForTimeout( 200 );
	};

	/* ===== Switch to Password — sticky must auto-clear (WP forbids the pair) ===== */
	await pickVisibility( 'password' );
	await page.waitForFunction( () => !! document.querySelector( '#minn-password-input' ), null, { timeout: 8000 } );
	t.check( 'sticky control hidden under password visibility', ! ( await page.$( '#minn-sticky' ) ) );
	// Combobox is themed (not a native select).
	t.check( 'visibility control is a combobox', await page.evaluate( () =>
		!! document.querySelector( '#minn-visibility.minn-ac' )
		&& document.querySelector( '#minn-visibility-input' )?.getAttribute( 'role' ) === 'combobox' ) );
	await page.fill( '#minn-password-input', 'sekret' );
	await save();
	// The unstick rides a separate chained request BEFORE the password save
	// (WP validates the pair against CURRENT sticky), so two writes land here.
	s = await savedWhen( id, ( x ) => x.password === 'sekret' && x.sticky === false );
	t.check( 'password set and sticky auto-cleared', s.password === 'sekret' && s.sticky === false, JSON.stringify( { password: s.password, sticky: s.sticky } ) );

	/* ===== Back to Public clears the password ===== */
	await openEditor( page, id );
	await pickVisibility( 'public' );
	await page.waitForTimeout( 200 );
	await save();
	s = await savedWhen( id, ( x ) => ! x.password );
	t.check( 'returning to Public clears the password', ! s.password, JSON.stringify( s.password ) );

	/* ===== Private is a status; publishing keeps it private ===== */
	const pid = await createPost( page, { title: 'Private test', content: '<!-- wp:paragraph -->\n<p>secret</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, pid );
	await pickVisibility( 'private' );
	await page.waitForTimeout( 200 );
	await save();
	s = await saved( pid );
	t.check( 'Private applies status=private', s.status === 'private', s.status );

	/* ===== Autosave must NOT auto-publish a private-selected draft ===== */
	const gid = await createPost( page, { title: 'Autosave guard', content: '<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->' } );
	await openEditor( page, gid );
	await pickVisibility( 'private' );
	await page.click( '#minn-editor-body p' );
	await page.keyboard.press( 'End' );
	await page.keyboard.type( ' typing' );
	await page.waitForTimeout( 17000 ); // exceed the 15s autosave idle
	s = await saved( gid, 'status' );
	t.check( 'autosave leaves a private-selected draft as a draft', s.status === 'draft', s.status );

	for ( const d of [ id, pid, gid ] ) await deletePost( page, d );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
