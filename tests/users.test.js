/**
 * Users view: the role filter combobox — replaces the old per-role tab
 * spread that overflowed on real sites. Covers type-to-filter (which relies
 * on strict combos selecting their display label on focus so the first
 * keystroke replaces "All roles" instead of appending to it), picking a
 * role, and resetting back to All.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'users' );
	await login( page );

	await page.goto( `${ BASE }/minn-admin/users`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-user-cols.minn-table-row', { timeout: 15000 } );

	const combo = '[data-rolecombo] .minn-ac-input';
	t.check( 'role combobox renders (no tab spread)', !! ( await page.$( combo ) ) && ! ( await page.$( '.minn-tab[data-role]' ) ), '' );

	/* ===== Browse on focus ===== */
	await page.click( combo );
	await page.waitForSelector( '[data-rolecombo] .minn-ac-panel:not([hidden])' );
	const all = await page.$$eval( '[data-rolecombo] .minn-ac-item', ( o ) => o.map( ( e ) => e.textContent.trim() ) );
	t.check( 'focus browses the full role list with All first', all.length > 2 && all[ 0 ] === 'All roles', JSON.stringify( all ) );

	/* ===== Type-to-filter replaces the display label ===== */
	await page.keyboard.type( 'edi' );
	await page.waitForTimeout( 200 );
	const filtered = await page.$$eval( '[data-rolecombo] .minn-ac-item', ( o ) => o.map( ( e ) => e.textContent.trim() ) );
	t.check( 'typing filters (first keystroke replaces the label)', filtered.length > 0 && filtered.length < all.length && filtered.includes( 'Editor' ), JSON.stringify( filtered ) );

	/* ===== Enter picks the first match and filters the table ===== */
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( () => ! document.querySelector( '.minn-table.minn-busy' ), { timeout: 10000 } );
	await page.waitForTimeout( 500 );
	const roles = await page.$$eval( '.minn-user-cols.minn-table-row', ( els ) =>
		els.map( ( el ) => el.querySelectorAll( '.minn-row-meta' )[ 1 ].textContent.trim() ) );
	t.check( 'picked role filters the table', roles.length > 0 && roles.every( ( r ) => r === 'Editor' ), JSON.stringify( roles ) );
	t.check( 'display shows the picked label', ( await page.$eval( combo, ( i ) => i.value ) ) === 'Editor', '' );

	/* ===== Reset to All ===== */
	await page.click( combo );
	await page.waitForSelector( '[data-rolecombo] .minn-ac-panel:not([hidden])' );
	await page.locator( '[data-rolecombo] .minn-ac-item', { hasText: 'All roles' } ).first().dispatchEvent( 'mousedown' );
	await page.waitForFunction( () => ! document.querySelector( '.minn-table.minn-busy' ), { timeout: 10000 } );
	await page.waitForTimeout( 500 );
	const rowsAfter = await page.$$( '.minn-user-cols.minn-table-row' );
	t.check( 'All roles resets the table', rowsAfter.length > roles.length, String( rowsAfter.length ) );
	t.check( 'display snaps back to All roles', ( await page.$eval( combo, ( i ) => i.value ) ) === 'All roles', '' );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
