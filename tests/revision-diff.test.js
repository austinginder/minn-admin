/**
 * Revision diffs: the History card opens a side-by-side diff of a revision
 * against the CURRENT content — block-level alignment, word-level <del>/<ins>
 * marks inside changed pairs, dimmed unchanged rows — instead of the old raw
 * preview. Restore is unchanged and verified against SAVED content.
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
	   post; History hides that mirror while the editor is clean, so:
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

	await openEditor( page, id );
	await page.waitForSelector( '.minn-history-row', { timeout: 15000 } );
	// The async author-name lookup re-renders the sidebar shortly after the
	// rows first appear, detaching any stored handles — settle, then always
	// query fresh (never keep .minn-history-row handles across awaits).
	await page.waitForTimeout( 1000 );
	const revCount = await page.evaluate( () => document.querySelectorAll( '.minn-history-row' ).length );
	// Clean editor: API has V2 (mirror) + V1; UI shows only V1.
	t.check( 'history card lists previous revision(s), not the live-post mirror', revCount >= 1, String( revCount ) );

	const nApi = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '/revisions?per_page=6&_fields=id', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).length;
	}, id );
	t.check( 'UI hides one more revision than the API returns (the mirror)', revCount === nApi - 1, `ui=${ revCount } api=${ nApi }` );

	/* ===== Oldest visible revision (v1) vs current (v2) ===== */
	await page.evaluate( () => {
		const rows = document.querySelectorAll( '.minn-history-row' );
		rows[ rows.length - 1 ].click();
	} );
	await page.waitForSelector( '#minn-diff', { timeout: 10000 } );
	const d = await page.evaluate( () => ( {
		summary: [ ...document.querySelectorAll( '#minn-modal-overlay .minn-side-row' ) ].map( ( r ) => r.textContent ).join( ' ' ),
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

	/* ===== After restore, top History row is a previous version with a real diff ===== */
	await openEditor( page, id );
	await page.waitForSelector( '.minn-history-row', { timeout: 15000 } );
	await page.waitForTimeout( 1000 ); // the same re-render settle as above
	await page.evaluate( () => document.querySelector( '.minn-history-row' ).click() );
	await page.waitForSelector( '#minn-modal-overlay .minn-side-row', { timeout: 10000 } );
	await page.waitForTimeout( 400 );
	const topText = await page.evaluate( () => [ ...document.querySelectorAll( '#minn-modal-overlay .minn-side-row' ) ].map( ( r ) => r.textContent ).join( ' ' ) );
	t.check(
		'top History row after restore is not an identical mirror',
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

	/* ===== View all revisions dialog when the post has a long history ===== */
	for ( let i = 0; i < 8; i++ ) {
		await update( V2 + `<!-- wp:paragraph --><p>Extra rev ${ i }.</p><!-- /wp:paragraph -->` );
	}
	// Re-open so loadEditorRevisions runs against the longer list.
	await openEditor( page, id );
	await page.waitForSelector( '.minn-history-row', { timeout: 15000 } );
	await page.waitForTimeout( 800 );
	const moreUi = await page.evaluate( () => {
		const more = document.querySelector( '#minn-history-all' );
		const n = document.querySelectorAll( '.minn-history-row' ).length;
		return { hasMore: !! more, moreText: more ? more.textContent.trim() : '', sideRows: n };
	} );
	t.check( 'History card caps the short list', moreUi.sideRows <= 5, String( moreUi.sideRows ) );
	t.check( 'View all revisions control appears when there are more', moreUi.hasMore, JSON.stringify( moreUi ) );

	if ( moreUi.hasMore ) {
		await page.click( '#minn-history-all' );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && ( m.textContent.includes( 'All revisions' ) )
				&& ! m.textContent.includes( 'Loading revisions' );
		}, null, { timeout: 20000 } ).catch( () => null );
		const listUi = await page.evaluate( () => {
			const rows = document.querySelectorAll( '[data-revlist]' );
			return {
				title: !! document.querySelector( '.minn-modal-title' )
					&& /All revisions/.test( document.querySelector( '.minn-modal-title' ).textContent ),
				n: rows.length,
			};
		} );
		t.check( 'revisions list dialog shows many rows', listUi.title && listUi.n > 5, JSON.stringify( listUi ) );
		await page.evaluate( () => {
			const rows = document.querySelectorAll( '[data-revlist]' );
			if ( rows.length ) rows[ rows.length - 1 ].click();
		} );
		await page.waitForSelector( '#minn-diff, #minn-restore-rev', { timeout: 15000 } );
		t.check( 'picking a list row opens the revision diff',
			!!( await page.$( '#minn-diff, #minn-restore-rev' ) ), '' );
		await page.evaluate( () => {
			const x = document.querySelector( '#minn-modal-close' );
			if ( x ) x.click();
		} );
	}

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
