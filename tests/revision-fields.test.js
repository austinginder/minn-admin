/**
 * Custom fields in the revision comparison.
 *
 * Core revisions carry no postmeta, but field plugins write their own values
 * onto each revision, so a revision that changed nothing but fields can be
 * compared after all. Before this, such a revision reported itself "Identical
 * to the current content", which was not merely empty — it was wrong.
 *
 * Two properties matter and are tested separately, because they pull against
 * each other:
 *   - a revision that DID record field values reports what moved
 *   - a revision that recorded NONE reports nothing, rather than reading its
 *     silence as "every field was empty" and inventing changes
 *
 * The route is provider-driven (`minn_admin_revision_fields`), so a site with
 * no field plugin answers an empty list and the modal is unchanged.
 *
 * A revision that DOES carry field values cannot be built from a browser:
 * writing field meta onto a revision post has no REST route, and revisions
 * made through REST content saves record none. That half is verified on the
 * mula lab, whose wp-admin-made revisions carry ACF's values (a section field
 * reads "11 sections -> 11 sections · 1 edited").
 */
const { launch, login, openEditor, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'revision-fields' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const post = ( url, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.url, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( a.body ),
		} );
		return { status: r.status, body: await r.json() };
	}, { url, body } );

	// Content moves away and back, so the revision the list offers carries
	// content IDENTICAL to the live post — the case that used to read as no
	// change at all. (Core snapshots a revision only when title, content or
	// excerpt move, and the newest one mirrors the live post, so the list
	// hides it.)
	const id = ( await post( 'wp/v2/posts', {
		title: 'Revision fields suite', status: 'draft', content: '<p>first body</p>',
	} ) ).body.id;
	await post( 'wp/v2/posts/' + id, { content: '<p>second body</p>' } );
	await post( 'wp/v2/posts/' + id, { content: '<p>third body</p>' } );
	await post( 'wp/v2/posts/' + id, { content: '<p>second body</p>' } );

	const revIds = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '/revisions?per_page=20&_fields=id', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).map( ( x ) => x.id );
	}, id );
	t.check( 'the fixture produced revisions to compare', revIds.length >= 2, revIds.join( ',' ) );

	const fieldsFor = ( rev ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/revision-fields/' + a.id + '/' + a.rev, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return { status: r.status, body: await r.json() };
	}, { id, rev: rev } );

	// These revisions were taken by REST content saves, so no field plugin
	// wrote anything onto them. Silence must read as silence.
	const quiet = await fieldsFor( revIds[ revIds.length - 1 ] );
	t.check( 'the route answers for a revision with no recorded fields', quiet.status === 200, String( quiet.status ) );
	t.check( 'a revision that recorded no fields reports no changes',
		quiet.body.changed === 0 && Array.isArray( quiet.body.groups ) && ! quiet.body.groups.length,
		JSON.stringify( quiet.body ) );

	// A revision belonging to another post is refused, not silently compared.
	const other = ( await post( 'wp/v2/posts', { title: 'Revision fields decoy', status: 'draft', content: '<p>x</p>' } ) ).body.id;
	const wrong = await page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/revision-fields/' + a.other + '/' + a.rev, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return r.status;
	}, { other, rev: revIds[ 0 ] } );
	t.check( "a revision of another post is refused", wrong === 404, String( wrong ) );

	// The modal: a revision whose content matches the live post says so, and
	// the field rows render when there are any.
	await openEditor( page, id );
	const openHistory = async () => {
		await page.waitForSelector( '[data-side-door="history"]', { timeout: 15000 } );
		await page.click( '[data-side-door="history"]' );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && /All revisions/.test( m.textContent ) && ! m.textContent.includes( 'Loading revisions' );
		}, null, { timeout: 20000 } );
		await page.waitForTimeout( 300 );
	};
	await openHistory();
	const shown = await page.$$eval( '[data-revlist]', ( els ) => els.map( ( e ) => e.dataset.revlist ) );
	t.check( 'the history dialog lists revisions', shown.length >= 1, shown.join( ',' ) );

	await page.evaluate( ( r ) => document.querySelector( `[data-revlist="${ r }"]` ).click(), shown[ shown.length - 1 ] );
	await page.waitForSelector( '.minn-modal-meta', { timeout: 20000 } );
	// The field request lands after the revision itself; wait for both.
	await page.waitForTimeout( 1500 );
	const modal = await page.evaluate( () => ( {
		changes: ( [ ...document.querySelectorAll( '.minn-modal-meta .minn-side-row' ) ]
			.find( ( r ) => /Changes/.test( r.textContent ) ) || {} ).textContent || '',
		fieldRows: document.querySelectorAll( '#minn-diff .minn-diff-row.field' ).length,
	} ) );
	t.check( 'a revision with identical content and no recorded fields still says identical',
		/Identical to the current content/.test( modal.changes ) && modal.fieldRows === 0, JSON.stringify( modal ) );

	await page.evaluate( async ( ids ) => {
		for ( const pid of ids ) {
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?force=true', {
				method: 'DELETE', credentials: 'same-origin', headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		}
	}, [ id, other ] );

	t.done( browser, errors );
} )();
