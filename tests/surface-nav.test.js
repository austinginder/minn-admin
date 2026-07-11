/**
 * Surface detail prev/next (←/→): Gravity Forms entry detail opens from the
 * list, then ArrowRight / ArrowLeft (and the head ‹ › buttons) step through
 * the loaded page of entries without leaving the modal.
 *
 * The entry id lives in `.minn-modal-sub` ("Entry #12") since the contact-card
 * redesign (57fb053) moved it out of the title — navigation matches on that
 * id, not the form name, so a late title swap can't look like navigation.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'surface-nav' );
	await login( page );

	// Need at least two GF entries on the site (dev fixtures). Skip cleanly
	// when GF isn't present so the suite stays green on bare sites.
	const ready = await page.evaluate( () => {
		const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'gravity-forms' );
		return !! s;
	} );
	if ( ! ready ) {
		t.check( 'gravity-forms surface available (skipped when absent)', true, 'skipped' );
		await t.done( browser, errors );
		return;
	}

	await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row[data-sitem]', { timeout: 15000 } );
	const count = await page.$$eval( '.minn-table-row[data-sitem]', ( els ) => els.length );
	t.check( 'entries list has at least two rows', count >= 2, String( count ) );
	if ( count < 2 ) {
		await t.done( browser, errors );
		return;
	}

	const ids = await page.$$eval( '.minn-table-row[data-sitem]', ( rows ) =>
		rows.map( ( r ) => {
			// Title cell is entry summary — grab id from the row index via data attr only.
			return parseInt( r.dataset.sitem, 10 );
		} )
	);
	t.check( 'row indexes load', ids.length >= 2, JSON.stringify( ids ) );

	// The open entry's id, read from the "Entry #N" sub line.
	const modalId = () => page.evaluate( () => {
		const sub = document.querySelector( '.minn-modal-sub' );
		const m = sub && sub.textContent.match( /#(\d+)\s*$/ );
		return m ? m[ 1 ] : null;
	} );

	// Open the first entry and wait for the sections fetch to settle.
	// Stepping is ignored while loading, so arrows only after the loading
	// row is gone.
	await page.click( '.minn-table-row[data-sitem="0"]' );
	await page.waitForSelector( '.minn-modal-title', { timeout: 10000 } );
	await page.waitForFunction( () => {
		const sub = document.querySelector( '.minn-modal-sub' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const next = document.querySelector( '#minn-surface-next' );
		return sub && ! loading && next && ! next.disabled;
	}, null, { timeout: 15000 } );
	const id1 = await modalId();
	const countLabel = await page.$eval( '.minn-modal-count', ( el ) => el.textContent.trim() );
	t.check( 'detail shows position 1 / N', /^1\s*\/\s*\d+$/.test( countLabel ), countLabel );
	t.check( 'first entry carries its id in the sub line', !! id1, String( id1 ) );

	// → next entry (match on the #id, not the form name, so a late title
	// swap can't look like navigation).
	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction( ( prevId ) => {
		const sub = document.querySelector( '.minn-modal-sub' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const m = sub && sub.textContent.match( /#(\d+)\s*$/ );
		return ! loading && m && m[ 1 ] !== prevId;
	}, id1, { timeout: 10000 } );
	const id2 = await modalId();
	t.check( '→ opens the next entry', id2 && id2 !== id1, `#${ id1 } → #${ id2 }` );
	const count2 = await page.$eval( '.minn-modal-count', ( el ) => el.textContent.trim() );
	t.check( 'position advances to 2 / N', /^2\s*\/\s*\d+$/.test( count2 ), count2 );

	// ← previous entry
	await page.keyboard.press( 'ArrowLeft' );
	await page.waitForFunction( ( wantId ) => {
		const sub = document.querySelector( '.minn-modal-sub' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const m = sub && sub.textContent.match( /#(\d+)\s*$/ );
		return ! loading && m && m[ 1 ] === wantId;
	}, id1, { timeout: 10000 } );
	t.check( '← returns to the first entry', ( await modalId() ) === id1, `#${ id2 } → #${ await modalId() }` );

	// Head button also works
	await page.click( '#minn-surface-next' );
	await page.waitForFunction( ( wantId ) => {
		const sub = document.querySelector( '.minn-modal-sub' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const m = sub && sub.textContent.match( /#(\d+)\s*$/ );
		return ! loading && m && m[ 1 ] === wantId;
	}, id2, { timeout: 10000 } );
	t.check( '› button steps forward', ( await modalId() ) === id2, `#${ await modalId() }` );

	// At the last item of a 2-entry page, → is a no-op (button disabled).
	if ( count === 2 ) {
		const nextDisabled = await page.$eval( '#minn-surface-next', ( el ) => el.disabled );
		t.check( 'next disabled on last entry', nextDisabled, '' );
		await page.keyboard.press( 'ArrowRight' );
		await page.waitForTimeout( 400 );
		t.check( '→ on last entry is a no-op', ( await modalId() ) === id2, `#${ await modalId() }` );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
