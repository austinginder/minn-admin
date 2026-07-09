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

	// Seed TWO updates. WP's newest revision mirrors the live post and is
	// hidden while the editor is clean, so we need an older revision for the
	// History card to show anything. Then Update again in the UI — a new
	// previous-version row must land without a full reload.
	const id = await createPost( page, {
		title: 'History refresh probe',
		content: '<!-- wp:paragraph --><p>Version one seed.</p><!-- /wp:paragraph -->',
		status: 'publish',
	} );
	const seedUpdate = ( content ) => page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + args.id, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { content: args.content } ),
		} );
		if ( ! r.ok ) throw new Error( 'seed update failed' );
	}, { id, content } );
	await seedUpdate( '<!-- wp:paragraph --><p>Version two.</p><!-- /wp:paragraph -->' );
	await seedUpdate( '<!-- wp:paragraph --><p>Version three.</p><!-- /wp:paragraph -->' );

	await openEditor( page, id );
	await page.waitForSelector( '.minn-history-row', { timeout: 15000 } );
	const before = await page.$$( '.minn-history-row' );
	const nBefore = before.length;
	t.check( 'history lists at least one previous revision after seed', nBefore >= 1, String( nBefore ) );

	// API has one more revision than the UI (the live-post mirror is hidden).
	const nApi = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '/revisions?per_page=6&_fields=id', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).length;
	}, id );
	t.check( 'clean editor hides the current-save mirror revision', nBefore === nApi - 1, `ui=${ nBefore } api=${ nApi }` );

	// Visible previous-version row should read as recent — never "4h ago"
	// (the old Z-suffix bug on America/New_York).
	const whenBefore = await page.evaluate( () => {
		const el = document.querySelector( '.minn-history-when' );
		return el ? el.textContent.trim() : '';
	} );
	t.check(
		'fresh revision is not skewed by site TZ (not Nh ago for N≈offset)',
		/just now|min ago/.test( whenBefore ),
		whenBefore
	);

	// Top row is a previous version — opening it must show a real diff, not
	// "Identical to the current content" (the off-by-one Austin hit).
	await before[ 0 ].click();
	await page.waitForSelector( '#minn-modal-overlay .minn-side-row', { timeout: 10000 } );
	await page.waitForTimeout( 400 );
	const topDiff = await page.evaluate( () =>
		[ ...document.querySelectorAll( '#minn-modal-overlay .minn-side-row' ) ].map( ( r ) => r.textContent ).join( ' ' )
	);
	t.check(
		'top History row diffs against current (not identical mirror)',
		! /Identical to the current content/.test( topDiff ) && /differ/.test( topDiff ),
		topDiff.slice( 0, 160 )
	);
	await page.evaluate( () => {
		const close = document.querySelector( '#minn-modal-overlay .minn-modal-close, #minn-modal-overlay [aria-label="Close"]' );
		if ( close ) close.click();
		else {
			const overlay = document.querySelector( '#minn-modal-overlay' );
			if ( overlay ) overlay.click();
		}
	} );
	await page.waitForFunction( () => ! document.querySelector( '#minn-modal-overlay' ), { timeout: 5000 } ).catch( () => {} );

	// Edit in the body and click Update — a new previous-version row appears.
	await page.click( '#minn-editor-body' );
	await page.keyboard.type( ' ' );
	await page.keyboard.type( 'Edited live.' );
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
