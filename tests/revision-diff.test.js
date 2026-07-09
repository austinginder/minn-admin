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
	const revRows = await page.$$( '.minn-history-row' );
	// Clean editor: API has V2 (mirror) + V1; UI shows only V1.
	t.check( 'history card lists previous revision(s), not the live-post mirror', revRows.length >= 1, String( revRows.length ) );

	const nApi = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '/revisions?per_page=6&_fields=id', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).length;
	}, id );
	t.check( 'UI hides one more revision than the API returns (the mirror)', revRows.length === nApi - 1, `ui=${ revRows.length } api=${ nApi }` );

	/* ===== Oldest visible revision (v1) vs current (v2) ===== */
	await revRows[ revRows.length - 1 ].click();
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
	await ( await page.$( '.minn-history-row' ) ).click();
	await page.waitForSelector( '#minn-modal-overlay .minn-side-row', { timeout: 10000 } );
	await page.waitForTimeout( 400 );
	const topText = await page.evaluate( () => [ ...document.querySelectorAll( '#minn-modal-overlay .minn-side-row' ) ].map( ( r ) => r.textContent ).join( ' ' ) );
	t.check(
		'top History row after restore is not an identical mirror',
		! /Identical to the current content/.test( topText ),
		topText.slice( 0, 140 )
	);

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
