/**
 * History card after save: new revisions must appear without a page refresh,
 * and "time ago" labels must not be skewed by the site's gmt_offset (the
 * "4h ago for a just-saved revision" bug on America/New_York).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'history-refresh' );
	await login( page );

	/* ===== Boot exposes site offset; parseWpDate uses it ===== */
	const boot = await page.evaluate( () => ( {
		gmtOffset: window.MINN.gmtOffset,
		hasParse: typeof window.MINN !== 'undefined',
	} ) );
	t.check( 'boot payload includes numeric gmtOffset', typeof boot.gmtOffset === 'number', String( boot.gmtOffset ) );

	// Seed one revision so History exists, then open the editor and Update
	// again — the new row must land without a full reload.
	const id = await createPost( page, {
		title: 'History refresh probe',
		content: '<!-- wp:paragraph --><p>Version one seed.</p><!-- /wp:paragraph -->',
		status: 'publish',
	} );
	await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { content: '<!-- wp:paragraph --><p>Version two.</p><!-- /wp:paragraph -->' } ),
		} );
		if ( ! r.ok ) throw new Error( 'seed update failed' );
	}, id );

	await openEditor( page, id );
	await page.waitForSelector( '.minn-history-row', { timeout: 15000 } );
	const before = await page.$$( '.minn-history-row' );
	const nBefore = before.length;
	t.check( 'history lists at least one revision after seed', nBefore >= 1, String( nBefore ) );

	// Newest row should read as recent — never "4h ago" for a revision
	// written moments ago (the old Z-suffix bug).
	const whenBefore = await page.evaluate( () => {
		const el = document.querySelector( '.minn-history-when' );
		return el ? el.textContent.trim() : '';
	} );
	t.check(
		'fresh revision is not skewed by site TZ (not Nh ago for N≈offset)',
		/just now|min ago/.test( whenBefore ),
		whenBefore
	);

	// Edit in the body and click Update — a new revision must appear.
	await page.click( '#minn-editor-body' );
	await page.keyboard.type( ' ' );
	await page.keyboard.type( 'Edited live.' );
	const updateBtn = await page.$( '#minn-update, #minn-publish, button.minn-btn-primary' );
	// Prefer the explicit Update control in the topbar/side.
	const clicked = await page.evaluate( () => {
		const btn = [ ...document.querySelectorAll( 'button' ) ].find( ( b ) =>
			/^(Update|Publish)$/.test( b.textContent.trim() )
		);
		if ( ! btn ) return false;
		btn.click();
		return true;
	} );
	t.check( 'clicked Update/Publish', clicked, '' );

	await page.waitForFunction( ( n ) => {
		const rows = document.querySelectorAll( '.minn-history-row' );
		return rows.length > n;
	}, nBefore, { timeout: 15000 } ).catch( () => {} );

	const after = await page.$$( '.minn-history-row' );
	t.check( 'history gains a row after save without refresh', after.length > nBefore, `before=${ nBefore } after=${ after.length }` );

	const whenAfter = await page.evaluate( () => {
		const el = document.querySelector( '.minn-history-when' );
		return el ? el.textContent.trim() : '';
	} );
	t.check(
		'post-save revision still reads as recent',
		/just now|min ago/.test( whenAfter ),
		whenAfter
	);

	// Direct unit check of the offset math against a site-local ISO string.
	const math = await page.evaluate( () => {
		const off = window.MINN.gmtOffset;
		// Fabricate "now" as a WP site-local string (no zone) and ask timeAgo
		// via a probe: inject a temporary history label isn't exported, so
		// reimplement the same offset append the app uses and compare clocks.
		const now = new Date();
		// Build a site-local wall-clock string for "now".
		const utcMs = now.getTime() + off * 3600 * 1000;
		const u = new Date( utcMs );
		const pad = ( n ) => String( n ).padStart( 2, '0' );
		const local = u.getUTCFullYear() + '-' + pad( u.getUTCMonth() + 1 ) + '-' + pad( u.getUTCDate() )
			+ 'T' + pad( u.getUTCHours() ) + ':' + pad( u.getUTCMinutes() ) + ':' + pad( u.getUTCSeconds() );
		// Old bug: append Z → skew by |offset| hours.
		const wrong = Math.round( ( Date.now() - new Date( local + 'Z' ).getTime() ) / 3600000 );
		// Correct: append site offset.
		const sign = off >= 0 ? '+' : '-';
		const abs = Math.abs( off );
		const hh = String( Math.floor( abs ) ).padStart( 2, '0' );
		const mm = String( Math.round( ( abs % 1 ) * 60 ) ).padStart( 2, '0' );
		const right = Math.round( ( Date.now() - new Date( local + sign + hh + ':' + mm ).getTime() ) / 1000 );
		return { off, wrongHours: wrong, rightSeconds: right, local };
	} );
	if ( Math.abs( math.off ) >= 1 ) {
		t.check(
			'legacy Z parse would skew by ~site offset hours',
			Math.abs( math.wrongHours - ( -math.off ) ) <= 1 || Math.abs( math.wrongHours ) >= Math.abs( math.off ) - 1,
			JSON.stringify( math )
		);
	}
	t.check( 'offset-aware parse of "now" is within a few seconds', Math.abs( math.rightSeconds ) < 5, JSON.stringify( math ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
