/**
 * The Drafts filter — a quiet toolbar chip beside Modified and Trash that
 * narrows the content list to status=draft, so drafts are not scattered
 * through the publishing timeline by their dates. Drafts and Modified are
 * exclusive (Modified means live content), and trash mode hides the chip.
 *
 * Fixtures: one draft and one published post, deleted on the way out. The
 * Author pass checks the chip as an own-only writer.
 */
const { BASE, launch, login, loginAs, createPost, deletePost, reporter } = require( './helpers' );

const settled = ( page, fn, arg ) => page.waitForFunction( ( a ) => {
	if ( document.querySelector( '#minn-view .minn-busy, #minn-view .minn-loading' ) ) return false;
	return new Function( 'a', a.body )( a.arg );
}, { body: fn, arg }, { timeout: 20000 } );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'drafts-filter' );

	await login( page );

	let draftId = null;
	let liveId = null;
	let authorDraft = null;
	try {
		draftId = await createPost( page, { title: 'Drafts filter fixture draft', content: '<p>unfinished</p>', status: 'draft' } );
		liveId = await createPost( page, { title: 'Drafts filter fixture live', content: '<p>shipped</p>', status: 'publish' } );
		t.check( 'fixture posts created', !! draftId && !! liveId, `${ draftId } / ${ liveId }` );

		await page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `.minn-table-row[data-id="${ liveId }"]`, { timeout: 20000 } );
		const order = await page.evaluate( () => Array.from(
			document.querySelectorAll( '.minn-tabs-aux .minn-tab' ) ).map( ( b ) => b.id ) );
		t.check( 'Drafts sits first in the strip, before Modified and Trash',
			order[ 0 ] === 'minn-content-drafts' && order[ order.length - 1 ] === 'minn-content-trash', JSON.stringify( order ) );

		// On: only drafts remain.
		await page.click( '#minn-content-drafts' );
		await settled( page, `return !! document.querySelector( '#minn-content-drafts.active' )
			&& ! document.querySelector( '.minn-table-row[data-id="' + a + '"]' );`, liveId );
		t.check( 'Drafts keeps the draft', !! ( await page.$( `.minn-table-row[data-id="${ draftId }"]` ) ), '' );
		const statuses = await page.evaluate( () => Array.from(
			document.querySelectorAll( '.minn-table-row[data-status]' ) ).map( ( r ) => r.dataset.status ) );
		t.check( 'every row is a draft', statuses.length > 0 && statuses.every( ( s ) => s === 'draft' ), JSON.stringify( [ ...new Set( statuses ) ] ) );
		const sub = await page.evaluate( () => ( document.querySelector( '#minn-sub' ) || {} ).textContent || '' );
		t.check( 'topbar names the Drafts view', /Drafts/.test( sub ), sub );

		// Modified takes over and clears Drafts.
		if ( await page.$( '#minn-content-modified' ) ) {
			await page.click( '#minn-content-modified' );
			await settled( page, `return !! document.querySelector( '#minn-content-modified.active' )
				&& ! document.querySelector( '#minn-content-drafts.active' );` );
			t.check( 'Modified clears Drafts', true, '' );
			await page.click( '#minn-content-drafts' );
			await settled( page, `return !! document.querySelector( '#minn-content-drafts.active' )
				&& ! document.querySelector( '#minn-content-modified.active' );` );
			t.check( 'Drafts clears Modified', true, '' );
		}

		// Trash hides the chip; leaving trash brings it back still on.
		await page.click( '#minn-content-trash' );
		await settled( page, `return !! document.querySelector( '#minn-content-trash.active' );` );
		t.check( 'trash mode hides the Drafts chip', ! ( await page.$( '#minn-content-drafts' ) ), '' );
		await page.click( '#minn-content-trash' );
		await settled( page, `return ! document.querySelector( '#minn-content-trash.active' )
			&& !! document.querySelector( '#minn-content-drafts.active' );` );
		t.check( 'leaving trash keeps Drafts on', ! ( await page.$( `.minn-table-row[data-id="${ liveId }"]` ) ), '' );

		// Off: the full list returns.
		await page.click( '#minn-content-drafts' );
		await page.waitForSelector( `.minn-table-row[data-id="${ liveId }"]`, { timeout: 20000 } );
		t.check( 'filter off restores published content', true, '' );

		// As an Author: own drafts only, no errors.
		const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		const authorErrors = [];
		author.page.on( 'pageerror', ( e ) => authorErrors.push( e.message ) );
		authorDraft = await createPost( author.page, { title: 'Drafts filter fixture author draft', content: '<p>mine</p>', status: 'draft' } );
		await author.page.goto( BASE + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await author.page.waitForSelector( '#minn-content-drafts', { timeout: 20000 } );
		await author.page.click( '#minn-content-drafts' );
		await settled( author.page, `return !! document.querySelector( '#minn-content-drafts.active' );` );
		const authorView = await author.page.evaluate( ( id ) => ( {
			statuses: Array.from( document.querySelectorAll( '.minn-table-row[data-status]' ) ).map( ( r ) => r.dataset.status ),
			own: !! document.querySelector( `.minn-table-row[data-id="${ id }"]` ),
			err: !! document.querySelector( '#minn-view .minn-error' ),
		} ), authorDraft );
		t.check( 'Author sees their own draft, only drafts, without an error',
			authorView.own && ! authorView.err && authorView.statuses.every( ( s ) => s === 'draft' ) && ! authorErrors.length,
			JSON.stringify( { ...authorView, authorErrors } ) );
		await author.ctx.close();
	} finally {
		await deletePost( page, draftId );
		await deletePost( page, liveId );
		if ( authorDraft ) await deletePost( page, authorDraft );
	}

	await t.done( browser, errors );
} )();
