/**
 * Surface detail prev/next (←/→): Gravity Forms entry detail opens from the
 * list, then ArrowRight / ArrowLeft (and the head ‹ › buttons) step through
 * the loaded page of entries without leaving the modal.
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

	// Open the first entry and wait for the sections fetch to settle
	// (title swaps from the surface label to the form name). Stepping is
	// ignored while loading, so arrows only after the loading row is gone.
	await page.click( '.minn-table-row[data-sitem="0"]' );
	await page.waitForSelector( '.minn-modal-title', { timeout: 10000 } );
	await page.waitForFunction( () => {
		const title = document.querySelector( '.minn-modal-title' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const next = document.querySelector( '#minn-surface-next' );
		return title && ! loading && next && ! next.disabled;
	}, null, { timeout: 15000 } );
	const title1 = await page.$eval( '.minn-modal-title', ( el ) => el.textContent.trim() );
	const id1 = ( title1.match( /#(\d+)\s*$/ ) || [] )[ 1 ];
	const countLabel = await page.$eval( '.minn-modal-count', ( el ) => el.textContent.trim() );
	t.check( 'detail shows position 1 / N', /^1\s*\/\s*\d+$/.test( countLabel ), countLabel );
	t.check( 'first entry title has an id', !! id1, title1 );

	// → next entry (match on the #id, not the form name, so a late title
	// swap can't look like navigation).
	await page.keyboard.press( 'ArrowRight' );
	await page.waitForFunction( ( prevId ) => {
		const el = document.querySelector( '.minn-modal-title' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const m = el && el.textContent.match( /#(\d+)\s*$/ );
		return ! loading && m && m[ 1 ] !== prevId;
	}, id1, { timeout: 10000 } );
	const title2 = await page.$eval( '.minn-modal-title', ( el ) => el.textContent.trim() );
	const id2 = ( title2.match( /#(\d+)\s*$/ ) || [] )[ 1 ];
	t.check( '→ opens the next entry', id2 && id2 !== id1, `${ title1 } → ${ title2 }` );
	const count2 = await page.$eval( '.minn-modal-count', ( el ) => el.textContent.trim() );
	t.check( 'position advances to 2 / N', /^2\s*\/\s*\d+$/.test( count2 ), count2 );

	// ← previous entry
	await page.keyboard.press( 'ArrowLeft' );
	await page.waitForFunction( ( wantId ) => {
		const el = document.querySelector( '.minn-modal-title' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const m = el && el.textContent.match( /#(\d+)\s*$/ );
		return ! loading && m && m[ 1 ] === wantId;
	}, id1, { timeout: 10000 } );
	const titleBack = await page.$eval( '.minn-modal-title', ( el ) => el.textContent.trim() );
	t.check( '← returns to the first entry', titleBack === title1, `${ title2 } → ${ titleBack }` );

	// Head button also works
	await page.click( '#minn-surface-next' );
	await page.waitForFunction( ( wantId ) => {
		const el = document.querySelector( '.minn-modal-title' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const m = el && el.textContent.match( /#(\d+)\s*$/ );
		return ! loading && m && m[ 1 ] === wantId;
	}, id2, { timeout: 10000 } );
	const titleBtn = await page.$eval( '.minn-modal-title', ( el ) => el.textContent.trim() );
	t.check( '› button steps forward', titleBtn === title2, titleBtn );

	// At the last item of a 2-entry page, → is a no-op (button disabled).
	if ( count === 2 ) {
		const nextDisabled = await page.$eval( '#minn-surface-next', ( el ) => el.disabled );
		t.check( 'next disabled on last entry', nextDisabled, '' );
		await page.keyboard.press( 'ArrowRight' );
		await page.waitForTimeout( 400 );
		const still = await page.$eval( '.minn-modal-title', ( el ) => el.textContent.trim() );
		t.check( '→ on last entry is a no-op', still === titleBtn, still );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
