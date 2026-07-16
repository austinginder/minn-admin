/**
 * Revision diffs, v0.16.0 shape: History is a door on the editor rail that
 * opens the All revisions dialog directly (activity heatmap + list rows);
 * the old .minn-history-row sidebar card is gone. Picking a row opens the
 * side-by-side diff against the CURRENT content — block-level alignment,
 * word-level <del>/<ins> marks inside changed pairs, dimmed unchanged rows.
 * Restore is verified against SAVED content. Also covers the heatmap day
 * filter (click a day → filtered list → Show all clears).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const V1 = '<!-- wp:paragraph --><p>The stable opening paragraph.</p><!-- /wp:paragraph -->'
	+ '<!-- wp:paragraph --><p>The quick brown fox jumps over the fence.</p><!-- /wp:paragraph -->'
	+ '<!-- wp:paragraph --><p>This third paragraph will be deleted entirely.</p><!-- /wp:paragraph -->';
const V2 = '<!-- wp:paragraph --><p>The stable opening paragraph.</p><!-- /wp:paragraph -->'
	+ '<!-- wp:paragraph --><p>The quick red fox jumps over the fence.</p><!-- /wp:paragraph -->'
	+ '<!-- wp:paragraph --><p>A brand new closing paragraph appears.</p><!-- /wp:paragraph -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'revision-diff' );
	await login( page );

	/* ===== Fixture. REST CREATE stores no revision — each UPDATE snapshots
	   the post state AFTER it. The newest revision always mirrors the live
	   post; historyRowsFor hides that mirror while the editor is clean, so:
	   create → update(V1) → update(V2) yields one visible row (V1) to diff. ===== */
	const id = await createPost( page, { title: 'Diff probe', content: '<p>seed</p>', status: 'draft' } );
	const update = ( content ) => page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + args.id, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { content: args.content } ),
		} );
		if ( ! r.ok ) throw new Error( 'update failed' );
	}, { id, content } );
	await update( V1 );
	await update( V2 );

	// History door → All revisions dialog (rows are [data-revlist] buttons).
	const openHistory = async () => {
		await page.waitForSelector( '[data-side-door="history"]', { timeout: 15000 } );
		await page.click( '[data-side-door="history"]' );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && /All revisions/.test( m.textContent ) && ! m.textContent.includes( 'Loading revisions' );
		}, null, { timeout: 20000 } );
		await page.waitForTimeout( 300 );
	};

	await openEditor( page, id );
	// The rail renders async after the editor body — wait, don't sample.
	await page.waitForSelector( '[data-side-door="history"]', { timeout: 15000 } );
	t.check( 'History is a door on the rail', !! ( await page.$( '[data-side-door="history"]' ) ) );
	await openHistory();

	const revCount = await page.evaluate( () => document.querySelectorAll( '[data-revlist]' ).length );
	// Clean editor: API has V2 (mirror) + V1; UI shows only V1.
	t.check( 'revisions dialog lists previous revision(s), not the live-post mirror', revCount >= 1, String( revCount ) );

	const nApi = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '/revisions?per_page=6&_fields=id', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).length;
	}, id );
	t.check( 'UI hides one more revision than the API returns (the mirror)', revCount === nApi - 1, `ui=${ revCount } api=${ nApi }` );
	t.check( 'activity heatmap renders in the dialog', !! ( await page.$( '#minn-rev-heat' ) ) );

	/* ===== Oldest visible revision (v1) vs current (v2) ===== */
	await page.evaluate( () => {
		const rows = document.querySelectorAll( '[data-revlist]' );
		rows[ rows.length - 1 ].click();
	} );
	await page.waitForSelector( '#minn-diff', { timeout: 10000 } );
	const d = await page.evaluate( () => ( {
		summary: document.querySelector( '#minn-modal-overlay' ).textContent,
		same: document.querySelectorAll( '.minn-diff-row.same' ).length,
		change: document.querySelectorAll( '.minn-diff-row.change' ).length,
		del: document.querySelectorAll( '.minn-diff-row.del' ).length,
		add: document.querySelectorAll( '.minn-diff-row.add' ).length,
		delText: [ ...document.querySelectorAll( '.minn-diff del' ) ].map( ( e ) => e.textContent.trim() ).join( '|' ),
		insText: [ ...document.querySelectorAll( '.minn-diff ins' ) ].map( ( e ) => e.textContent.trim() ).join( '|' ),
	} ) );
	t.check( 'summary counts differing blocks', /3 blocks differ/.test( d.summary ), d.summary.slice( 0, 140 ) );
	t.check( 'unchanged block renders as a dimmed same-row', d.same === 1, String( d.same ) );
	t.check( 'word change becomes a change-row', d.change === 1, String( d.change ) );
	t.check( 'removed + added paragraphs become del/add rows', d.del === 1 && d.add === 1, `del=${ d.del } add=${ d.add }` );
	t.check( 'word-level del mark isolates the old word', d.delText.includes( 'brown' ) && ! d.delText.includes( 'quick' ), d.delText.slice( 0, 120 ) );
	t.check( 'word-level ins mark isolates the new word', d.insText.includes( 'red' ) && ! d.insText.includes( 'fence' ), d.insText.slice( 0, 120 ) );

	/* ===== Restore writes v1 back (verify SAVED content) ===== */
	page.once( 'dialog', ( dlg ) => dlg.accept() );
	await page.click( '#minn-restore-rev' );
	await page.waitForFunction( () => ! document.querySelector( '#minn-modal-overlay' ), { timeout: 10000 } );
	await page.waitForTimeout( 800 );
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', {
			headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	t.check( 'restore persists the revision content', saved.includes( 'brown fox' ) && saved.includes( 'deleted entirely' ) && ! saved.includes( 'brand new closing' ), saved.slice( 0, 80 ) );

	/* ===== After restore, the newest row is a previous version with a real diff ===== */
	await openEditor( page, id );
	await openHistory();
	await page.evaluate( () => document.querySelector( '[data-revlist]' ).click() );
	await page.waitForSelector( '#minn-diff, #minn-restore-rev', { timeout: 10000 } );
	await page.waitForTimeout( 400 );
	const topText = await page.evaluate( () => document.querySelector( '#minn-modal-overlay' ).textContent );
	t.check(
		'newest row after restore is not an identical mirror',
		! /Identical to the current content/.test( topText ),
		topText.slice( 0, 140 )
	);

	/* ===== ←/→ steps through revisions while the modal is open ===== */
	const countOf = () => page.evaluate( () => {
		const c = document.querySelector( '#minn-modal-overlay .minn-modal-count' );
		return c ? c.textContent.trim() : '';
	} );
	const start = await countOf();
	t.check( 'revision modal shows its position in the list', /^1 \/ [2-9]/.test( start ), start );
	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction( ( prev ) => {
		const c = document.querySelector( '#minn-modal-overlay .minn-modal-count' );
		return c && c.textContent.trim() !== prev;
	}, start, { timeout: 10000 } );
	t.check( 'ArrowRight steps to the older revision', /^2 \//.test( await countOf() ), await countOf() );
	await page.keyboard.press( 'ArrowLeft' );
	await page.waitForFunction( () => {
		const c = document.querySelector( '#minn-modal-overlay .minn-modal-count' );
		return c && /^1 \//.test( c.textContent.trim() );
	}, null, { timeout: 10000 } );
	t.check( 'ArrowLeft steps back to the newer revision', true );
	t.check( 'head step buttons render with the newer side disabled at the top', await page.evaluate( () => {
		const prev = document.querySelector( '#minn-rev-prev' );
		const next = document.querySelector( '#minn-rev-next' );
		return !! prev && !! next && prev.disabled && ! next.disabled;
	} ) );
	await page.evaluate( () => document.querySelector( '#minn-modal-close' ).click() );

	/* ===== Long history: many rows + heatmap day filter ===== */
	for ( let i = 0; i < 8; i++ ) {
		await update( V2 + `<!-- wp:paragraph --><p>Extra rev ${ i }.</p><!-- /wp:paragraph -->` );
	}
	// Re-open so the dialog loads the longer list.
	await openEditor( page, id );
	await openHistory();
	const longUi = await page.evaluate( () => ( {
		rows: document.querySelectorAll( '[data-revlist]' ).length,
		// Every cell carries data-revday; activity level classes l1–l4 mark
		// days that actually have revisions (l0 = empty).
		days: document.querySelectorAll( '.minn-rev-heat-cell:not(.l0)' ).length,
	} ) );
	t.check( 'dialog lists the long history', longUi.rows > 5, JSON.stringify( longUi ) );
	t.check( 'heatmap marks the active day(s)', longUi.days >= 1, String( longUi.days ) );

	// Day filter: all fixture revisions are today — clicking the marked day
	// keeps the rows and offers Show all; clearing restores the full list.
	await page.evaluate( () => document.querySelector( '.minn-rev-heat-cell:not(.l0):not([disabled])' ).click() );
	await page.waitForSelector( '#minn-rev-day-clear', { timeout: 10000 } );
	const filtered = await page.evaluate( () => document.querySelectorAll( '[data-revlist]' ).length );
	t.check( 'clicking a heatmap day filters the list to that day', filtered >= 1 && filtered <= longUi.rows, String( filtered ) );
	await page.click( '#minn-rev-day-clear' );
	await page.waitForFunction( () => ! document.querySelector( '#minn-rev-day-clear' ), { timeout: 10000 } );
	const unfiltered = await page.evaluate( () => document.querySelectorAll( '[data-revlist]' ).length );
	t.check( 'Show all clears the day filter', unfiltered === longUi.rows, `${ unfiltered } vs ${ longUi.rows }` );

	/* ===== Picking a list row opens the diff ===== */
	await page.evaluate( () => {
		const rows = document.querySelectorAll( '[data-revlist]' );
		if ( rows.length ) rows[ rows.length - 1 ].click();
	} );
	await page.waitForSelector( '#minn-diff, #minn-restore-rev', { timeout: 15000 } );
	t.check( 'picking a list row opens the revision diff', !! ( await page.$( '#minn-diff, #minn-restore-rev' ) ), '' );
	await page.evaluate( () => {
		const x = document.querySelector( '#minn-modal-close' );
		if ( x ) x.click();
	} );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
