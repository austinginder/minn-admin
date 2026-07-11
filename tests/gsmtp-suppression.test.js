/**
 * Gravity SMTP Suppressions view (the manage slot): list, suppress, search
 * and reactivate through Gravity SMTP's own Suppressed_Emails_Model, gated
 * on its granular suppression caps.
 *
 * Standing fixtures: bounce@example.com and unsubscribed@example.com are
 * seeded suppressions that must survive; the suite creates and reactivates
 * its own disposable address.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'gsmtp-suppression' );
	const { browser, page, errors } = await launch();
	await login( page );

	const DISPOSABLE = 'suite-suppress@example.com';

	const openSuppressions = async () => {
		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="manage"]', { timeout: 20000 } );
		await page.click( '[data-sview="manage"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
	};

	try {
		await openSuppressions();

		/* ===== Switcher + standing fixtures ===== */
		t.check( 'switcher reads Log / Suppressions / Settings', await page.evaluate( () => {
			const labels = [ ...document.querySelectorAll( '[data-sview]' ) ].map( ( b ) => b.textContent.trim() );
			return JSON.stringify( labels ) === JSON.stringify( [ 'Log', 'Suppressions', 'Settings' ] );
		} ) );
		const listText = await page.$eval( '.minn-table', ( el ) => el.textContent );
		t.check( 'standing suppressions list', /bounce@example\.com/.test( listText ) && /unsubscribed@example\.com/.test( listText ) );
		t.check( 'reason renders as a pill', /manually added/.test( listText ) );

		/* ===== Suppress a new address through the create form ===== */
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="email"]', { timeout: 10000 } );
		await page.fill( '[data-createfield="email"]', DISPOSABLE );
		await page.fill( '[data-createfield="notes"]', 'Suite disposable' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( ( addr ) => {
			const tbl = document.querySelector( '.minn-table' );
			return tbl && tbl.textContent.includes( addr );
		}, DISPOSABLE, { timeout: 20000 } );
		t.check( 'suppressed address appears in the list', true );

		/* ===== Search narrows ===== */
		await page.fill( '#minn-surface-search', 'bounce' );
		await page.waitForFunction( () => {
			const tbl = document.querySelector( '.minn-table' );
			return tbl && tbl.textContent.includes( 'bounce@example.com' ) && ! tbl.textContent.includes( 'unsubscribed@example.com' );
		}, { timeout: 20000 } );
		t.check( 'search filters through their model', true );
		await page.fill( '#minn-surface-search', '' );
		await page.waitForFunction( ( addr ) => {
			const tbl = document.querySelector( '.minn-table' );
			return tbl && tbl.textContent.includes( addr );
		}, DISPOSABLE, { timeout: 20000 } );

		/* ===== Reactivate through their model ===== */
		await page.$$eval( '.minn-table-row', ( rows, addr ) => {
			const row = rows.find( ( r ) => r.textContent.includes( addr ) );
			if ( row ) row.click();
		}, DISPOSABLE );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && [ ...m.querySelectorAll( '[data-saction]' ) ].some( ( b ) => /Reactivate/.test( b.textContent ) );
		}, { timeout: 20000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			[ ...document.querySelectorAll( '[data-saction]' ) ].find( ( b ) => /Reactivate/.test( b.textContent ) ).click();
		} );
		// The route returns an honest outcome message naming the address.
		await page.waitForFunction( ( addr ) => {
			const tEl = document.querySelector( '.minn-toast-msg' );
			return tEl && tEl.textContent.includes( 'can send to ' + addr );
		}, DISPOSABLE, { timeout: 20000 } );
		t.check( 'reactivate toasts the outcome message', true );
		await page.waitForFunction( ( addr ) => {
			const tbl = document.querySelector( '.minn-table' );
			return tbl && ! tbl.classList.contains( 'minn-busy' ) && ! tbl.textContent.includes( addr );
		}, DISPOSABLE, { timeout: 20000 } );
		t.check( 'reactivated address leaves the list', true );

		/* ===== Standing fixtures untouched ===== */
		const finalText = await page.$eval( '.minn-table', ( el ) => el.textContent );
		t.check( 'standing suppressions survive', /bounce@example\.com/.test( finalText ) && /unsubscribed@example\.com/.test( finalText ) );
	} finally {
		// Belt and braces: the disposable must not linger suppressed.
		await page.evaluate( async ( addr ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/gravity-smtp/suppressed?search=' + encodeURIComponent( addr ), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const d = await r.json();
			for ( const it of ( d.items || [] ) ) {
				await fetch( window.MINN.restUrl + 'minn-admin/v1/gravity-smtp/suppressed/' + it.id + '/reactivate', {
					method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
			}
		}, DISPOSABLE ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
