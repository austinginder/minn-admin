/**
 * Surface detail prev/next (←/→): a surface item's detail opens in the modal
 * from the list, then ArrowRight / ArrowLeft (and the head › button) step
 * through the loaded page of items without leaving it.
 *
 * Form entries open on their own page now (gf-entry-page.test.js covers its
 * stepping), so this drives the surfaces that still use the modal: the
 * resident activity log first, else a mail log. Navigation is matched on the
 * modal's "i / N" position, which every surface item carries.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

const CANDIDATES = [ 'wp-activity-log', 'fluent-smtp', 'gravity-smtp', 'wp-mail-logging' ];

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'surface-nav' );
	await login( page );

	const sid = await page.evaluate( ( ids ) => {
		const have = ( window.MINN.surfaces || [] ).map( ( x ) => x.id );
		return ids.find( ( id ) => have.includes( id ) ) || null;
	}, CANDIDATES );
	if ( ! sid ) {
		t.check( 'a modal-detail surface is available (skipped when absent)', true, 'skipped' );
		await t.done( browser, errors );
		return;
	}

	await page.goto( `${ BASE }/minn-admin/${ sid }`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row[data-sitem]', { timeout: 30000 } );
	const count = await page.$$eval( '.minn-table-row[data-sitem]', ( els ) => els.length );
	t.check( `${ sid } list has at least two rows`, count >= 2, String( count ) );
	if ( count < 2 ) {
		await t.done( browser, errors );
		return;
	}

	// "i / N" from the modal head, once the item has loaded.
	const position = () => page.evaluate( () => {
		const el = document.querySelector( '.minn-modal-count' );
		const m = el && el.textContent.trim().match( /^(\d+)\s*\/\s*(\d+)$/ );
		return m ? Number( m[ 1 ] ) : null;
	} );
	const settledAt = ( want ) => page.waitForFunction( ( w ) => {
		const el = document.querySelector( '.minn-modal-count' );
		const loading = document.querySelector( '.minn-modal .minn-loading' );
		const m = el && el.textContent.trim().match( /^(\d+)\s*\/\s*(\d+)$/ );
		return ! loading && m && Number( m[ 1 ] ) === w;
	}, want, { timeout: 15000, polling: 250 } );

	// Open the first item; stepping is ignored while it loads.
	await page.click( '.minn-table-row[data-sitem="0"]' );
	await settledAt( 1 );
	t.check( 'detail opens at position 1 / N', 1 === await position() );

	await page.keyboard.press( 'ArrowRight' );
	await settledAt( 2 );
	t.check( '→ opens the next item', 2 === await position() );

	await page.keyboard.press( 'ArrowLeft' );
	await settledAt( 1 );
	t.check( '← returns to the first item', 1 === await position() );

	await page.click( '#minn-surface-next' );
	await settledAt( 2 );
	t.check( '› button steps forward', 2 === await position() );

	// At the last item of a two-row page, → is a no-op (button disabled).
	if ( count === 2 ) {
		t.check( 'next disabled on the last item', await page.$eval( '#minn-surface-next', ( el ) => el.disabled ) );
		await page.keyboard.press( 'ArrowRight' );
		await page.waitForTimeout( 400 );
		t.check( '→ on the last item is a no-op', 2 === await position() );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
