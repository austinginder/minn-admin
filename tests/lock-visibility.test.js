/**
 * Locked-post visibility in the content list: a post another user has open
 * in an editor wears a "{name} is editing" chip (the minn_lock REST field
 * over core's _edit_lock; core's 150s window ages stale locks out
 * server-side, so a crashed session clears itself).
 *
 * Two real sessions: admin (list viewer) and minn-editor (lock holder via
 * the real lock-acquire route). The chip appears while B holds the lock,
 * the holder's own session sees no chip (own locks are not news, matching
 * wp-admin), and a fresh load after release shows it gone.
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

const EDITOR_USER = process.env.MINN_TEST_USER2 || 'minn-editor';
const EDITOR_PASS = process.env.MINN_TEST_PASS2 || 'minn-editor-pass-1';

( async () => {
	const { browser, page: pageA, errors } = await launch();
	const t = reporter( 'lock-visibility' );
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
	await Promise.all( [
		pageB.waitForNavigation( { waitUntil: 'domcontentloaded' } ).catch( () => {} ),
		pageB.click( '#wp-submit' ),
	] );
	await pageB.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await pageB.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );
	const editorName = await pageB.evaluate( () => window.MINN.user.name );

	const rowInfo = ( page, id ) => page.evaluate( ( pid ) => {
		const row = document.querySelector( `.minn-table-row[data-id="${ pid }"]` );
		const el = row && row.querySelector( '.minn-status.editing' );
		return { found: !! row, chip: el ? el.textContent.trim() : null };
	}, id );

	let postId = null;
	try {
		postId = await createPost( pageA, {
			title: 'Lock visibility suite post',
			content: '<!-- wp:paragraph -->\n<p>Held open elsewhere.</p>\n<!-- /wp:paragraph -->',
			status: 'publish',
		} );

		/* ===== B acquires a real lock through the lock route ===== */
		const acq = await pageB.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + `minn-admin/v1/posts/${ id }/lock`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: '{}',
			} );
			return ( await r.json() ).acquired;
		}, postId );
		t.check( 'editor session acquires the lock', acq === true, String( acq ) );

		/* ===== Admin list wears the chip ===== */
		await pageA.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await pageA.waitForSelector( '.minn-table-row[data-id]', { timeout: 20000 } );
		const locked = await rowInfo( pageA, postId );
		t.check( 'row wears the is-editing chip naming the holder', locked.found && !! locked.chip && locked.chip.includes( editorName ), JSON.stringify( locked ) );

		const field = await pageA.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ id }?_fields=minn_lock`, {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			return ( await r.json() ).minn_lock;
		}, postId );
		t.check( 'minn_lock field carries the holder', !! field && field.name === editorName, JSON.stringify( field ) );

		/* ===== The holder's own session sees no chip ===== */
		await pageB.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await pageB.waitForSelector( '.minn-table-row[data-id]', { timeout: 20000 } );
		const own = await rowInfo( pageB, postId );
		t.check( 'holder sees no chip on their own lock', own.found && ! own.chip, JSON.stringify( own ) );

		/* ===== Release clears the chip on a fresh load ===== */
		await pageB.evaluate( async ( id ) => {
			await fetch( window.MINN.restUrl + `minn-admin/v1/posts/${ id }/unlock`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: '{}',
			} );
		}, postId );
		await pageA.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await pageA.waitForSelector( '.minn-table-row[data-id]', { timeout: 20000 } );
		const after = await rowInfo( pageA, postId );
		t.check( 'released lock clears the chip', after.found && ! after.chip, JSON.stringify( after ) );
	} finally {
		if ( postId ) await deletePost( pageA, postId ).catch( () => {} );
		await ctxB.close().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
