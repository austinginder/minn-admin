/**
 * Post locking on core's _edit_lock: blocked-open takeover dialog, mid-session
 * takeover detection (30s refresh), take-back, and release-on-leave.
 *
 * Two real sessions: admin (context A) and minn-editor (context B, Editor
 * role). Slow by design — takeover detection rides the 30s lock refresh.
 */
const { chromium } = require( 'playwright-core' );
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const EDITOR_USER = process.env.MINN_TEST_USER2 || 'minn-editor';
const EDITOR_PASS = process.env.MINN_TEST_PASS2 || 'minn-editor-pass-1';

( async () => {
	const { browser, page: pageA, errors } = await launch();
	const t = reporter( 'lock' );
	await login( pageA );

	// Second, independent session for the Editor-role user.
	const ctxB = await browser.newContext( { ignoreHTTPSErrors: true } );
	const pageB = await ctxB.newPage();
	pageB.on( 'pageerror', ( e ) => errors.push( 'B pageerror: ' + e.message ) );
	pageB.on( 'console', ( m ) => {
		if ( m.type() === 'error' && ! /Failed to load resource/.test( m.text() ) ) errors.push( 'B console: ' + m.text() );
	} );
	await pageB.goto( BASE + '/wp-login.php' );
	await pageB.fill( '#user_login', EDITOR_USER );
	await pageB.fill( '#user_pass', EDITOR_PASS );
	await pageB.click( '#wp-submit' );
	await pageB.waitForLoadState( 'networkidle' );
	await pageB.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await pageB.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );

	const adminName = await pageA.evaluate( () => window.MINN.user.name );
	const editorName = await pageB.evaluate( () => window.MINN.user.name );

	const postId = await createPost( pageA, {
		title: 'Lock test post',
		content: '<!-- wp:paragraph -->\n<p>Contested content.</p>\n<!-- /wp:paragraph -->',
	} );

	/* ===== A opens first and holds the lock ===== */
	await openEditor( pageA, postId );
	await pageA.waitForTimeout( 1000 ); // let the acquire round-trip land
	t.check( 'A sees no overlay on first open', ! ( await pageA.$( '#minn-lock-overlay' ) ) );

	/* ===== B opens the same post → blocked with A identified ===== */
	await openEditor( pageB, postId );
	await pageB.waitForSelector( '#minn-lock-overlay', { timeout: 10000 } );
	const overlayText = await pageB.textContent( '#minn-lock-overlay' );
	t.check( 'B gets the takeover dialog naming A', overlayText.includes( adminName ), overlayText );

	/* ===== B takes over and can edit ===== */
	await pageB.click( '#minn-lock-take' );
	await pageB.waitForFunction( () => ! document.querySelector( '#minn-lock-overlay' ), null, { timeout: 10000 } );
	t.check( 'takeover removes the dialog', true );
	await pageB.click( '#minn-editor-body p' );
	await pageB.keyboard.press( 'End' );
	await pageB.keyboard.type( ' B was here.' );
	await pageB.keyboard.press( 'Meta+s' );
	await pageB.waitForTimeout( 1500 );
	const rawAfterB = await pageB.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, postId );
	t.check( 'B can save after takeover', /B was here\./.test( rawAfterB ), rawAfterB );

	/* ===== A's next refresh notices the takeover ===== */
	await pageA.waitForSelector( '#minn-lock-note', { timeout: 40000 } ); // 30s refresh + slack
	const noteText = await pageA.textContent( '#minn-lock-note' );
	t.check( 'A gets the taken-over banner naming B', noteText.includes( editorName ), noteText );
	const aEditable = await pageA.evaluate( () => document.querySelector( '#minn-editor-body' ).getAttribute( 'contenteditable' ) );
	t.check( 'A\'s editor is read-only after takeover', aEditable === 'false', aEditable );

	/* ===== A takes it back ===== */
	await pageA.click( '#minn-lock-retake' );
	await pageA.waitForFunction( () => ! document.querySelector( '#minn-lock-note' ), null, { timeout: 10000 } );
	const aEditable2 = await pageA.evaluate( () => document.querySelector( '#minn-editor-body' ).getAttribute( 'contenteditable' ) );
	t.check( 'take-back re-enables A\'s editor', aEditable2 === 'true', aEditable2 );

	/* ===== Clean take-back silently adopts B's saved version ===== */
	// A never typed, so A's copy is clean — the drift check must swap in B's
	// save in place instead of leaving A one ⌘S away from reverting it.
	await pageA.waitForFunction( () => {
		const b = document.querySelector( '#minn-editor-body' );
		return b && /B was here\./.test( b.textContent );
	}, null, { timeout: 10000 } );
	t.check( 'clean take-back adopts B\'s saved content', true );
	t.check( 'no drift banner on a clean take-back', ! ( await pageA.$( '#minn-drift-note' ) ) );

	/* ===== Dirty take-back offers the choice instead of silently reverting ===== */
	// Published post: A's idle autosave only writes a revision (never clears
	// dirty, never advances post modified), so the sequence is deterministic.
	const post3 = await createPost( pageA, {
		title: 'Lock drift test',
		content: '<!-- wp:paragraph -->\n<p>Original copy.</p>\n<!-- /wp:paragraph -->',
		status: 'publish',
	} );
	await openEditor( pageA, post3 );
	await pageA.waitForTimeout( 1000 );
	await pageA.click( '#minn-editor-body p' );
	await pageA.keyboard.press( 'End' );
	await pageA.keyboard.type( ' A unsaved edit.' );
	await openEditor( pageB, post3 );
	await pageB.waitForSelector( '#minn-lock-overlay', { timeout: 10000 } );
	await pageB.click( '#minn-lock-take' );
	await pageB.waitForFunction( () => ! document.querySelector( '#minn-lock-overlay' ), null, { timeout: 10000 } );
	await pageB.click( '#minn-editor-body p' );
	await pageB.keyboard.press( 'End' );
	await pageB.keyboard.type( ' B2 version.' );
	await pageB.keyboard.press( 'Meta+s' );
	await pageB.waitForTimeout( 1500 );
	const rawPost3 = await pageB.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, post3 );
	t.check( 'B saved the contested published post', /B2 version\./.test( rawPost3 ), rawPost3 );
	await pageA.waitForSelector( '#minn-lock-note', { timeout: 40000 } ); // 30s refresh + slack
	await pageA.click( '#minn-lock-retake' );
	await pageA.waitForSelector( '#minn-drift-note', { timeout: 10000 } );
	const driftText = await pageA.textContent( '#minn-drift-note' );
	t.check( 'dirty take-back shows the drift banner naming B', driftText.includes( editorName ), driftText );
	const aKept = await pageA.evaluate( () => document.querySelector( '#minn-editor-body' ).textContent );
	t.check( 'A\'s unsaved edits survive until A chooses', /A unsaved edit\./.test( aKept ), aKept );
	await pageA.click( '#minn-drift-load' );
	await pageA.waitForFunction( () => {
		const b = document.querySelector( '#minn-editor-body' );
		return b && /B2 version\./.test( b.textContent ) && ! /A unsaved edit\./.test( b.textContent );
	}, null, { timeout: 10000 } );
	t.check( 'Load theirs swaps in B\'s saved version', true );
	t.check( 'drift banner clears after loading theirs', ! ( await pageA.$( '#minn-drift-note' ) ) );

	/* ===== Blind-window save bounces off the server-side lock guard ===== */
	// B takes over and A saves right away, long before A's 30s refresh could
	// notice. The X-Minn-Expect-Lock guard must 409 the write and flip A to
	// the taken-over UI immediately. (If a refresh happens to land first, the
	// client-side guard blocks the save instead — both paths satisfy the
	// checks, no timing flake.)
	// Published: A's idle autosave writes only a revision, so nothing can
	// legitimately land in live content before the guarded ⌘S (a draft's
	// in-place autosave raced B's takeover here and false-failed the check).
	const post4 = await createPost( pageA, {
		title: 'Lock guard test',
		content: '<!-- wp:paragraph -->\n<p>Guarded copy.</p>\n<!-- /wp:paragraph -->',
		status: 'publish',
	} );
	await openEditor( pageA, post4 );
	await pageA.waitForTimeout( 1000 );
	await pageA.click( '#minn-editor-body p' );
	await pageA.keyboard.press( 'End' );
	await pageA.keyboard.type( ' A blind edit.' );
	await openEditor( pageB, post4 );
	await pageB.waitForSelector( '#minn-lock-overlay', { timeout: 10000 } );
	await pageB.click( '#minn-lock-take' );
	await pageB.waitForFunction( () => ! document.querySelector( '#minn-lock-overlay' ), null, { timeout: 10000 } );
	await pageA.keyboard.press( 'Meta+s' ); // A still believes it holds the lock
	await pageA.waitForSelector( '#minn-lock-note', { timeout: 10000 } );
	t.check( 'blind-window save flips A to taken-over immediately', true );
	const rawPost4 = await pageA.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=content`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, post4 );
	t.check( 'the blocked save wrote nothing', ! /A blind edit\./.test( rawPost4 ), rawPost4 );

	/* ===== Leaving the editor releases the lock ===== */
	const post2 = await createPost( pageA, {
		title: 'Lock release test',
		content: '<!-- wp:paragraph -->\n<p>Second post.</p>\n<!-- /wp:paragraph -->',
	} );
	await openEditor( pageA, post2 );
	await pageA.waitForTimeout( 1000 );
	// SPA-navigate away (sidebar) — this releases, no unload involved.
	await pageA.click( '.minn-nav-btn[data-nav="content"]' );
	await pageA.waitForTimeout( 1200 ); // unlock round-trip
	await openEditor( pageB, post2 );
	await pageB.waitForTimeout( 1500 );
	t.check( 'B opens released post with no dialog', ! ( await pageB.$( '#minn-lock-overlay' ) ) );

	await deletePost( pageA, postId );
	await deletePost( pageA, post2 );
	await deletePost( pageA, post3 );
	await deletePost( pageA, post4 );
	await ctxB.close();
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
