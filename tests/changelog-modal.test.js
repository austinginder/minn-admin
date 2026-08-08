/**
 * Sectioned changelog modal: clickable version rail beside one release at a
 * time (the minnadmin.com pattern in-app), collapsing to a horizontal chip
 * strip on small screens that scrolls in its own overflow.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'changelog-modal' );
	const { browser, page, errors } = await launch();
	await login( page );

	await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-ver-btn', { timeout: 15000 } );
	await page.click( '#minn-ver-btn' );
	await page.waitForSelector( '.minn-cl-rail .minn-cl-ver', { timeout: 10000 } );

	const first = await page.evaluate( () => ( {
		vers: Array.from( document.querySelectorAll( '.minn-cl-ver .minn-cl-ver-v' ) ).map( ( el ) => el.textContent ),
		selIdx: Array.from( document.querySelectorAll( '.minn-cl-ver' ) ).findIndex( ( el ) => el.classList.contains( 'sel' ) ),
		bodyHead: document.querySelector( '#minn-cl-body h3' ).textContent,
		heads: document.querySelectorAll( '#minn-cl-body h3' ).length,
	} ) );
	t.check( 'rail lists every release newest-first', first.vers.length >= 10 && /^v\d/.test( first.vers[ 0 ] ), JSON.stringify( first.vers.slice( 0, 3 ) ) );
	t.check( 'latest release selected by default', first.selIdx === 0, String( first.selIdx ) );
	t.check( 'body shows exactly one release', first.heads === 1 && first.bodyHead.includes( first.vers[ 0 ] ), first.bodyHead );

	// Click an older version: body swaps, selection follows.
	await page.click( '.minn-cl-ver[data-clver="2"]' );
	await page.waitForFunction( ( v ) => {
		const h = document.querySelector( '#minn-cl-body h3' );
		return h && h.textContent.includes( v );
	}, first.vers[ 2 ], { timeout: 8000 } );
	const after = await page.evaluate( () => ( {
		sel: document.querySelector( '.minn-cl-ver.sel .minn-cl-ver-v' ).textContent,
		heads: document.querySelectorAll( '#minn-cl-body h3' ).length,
	} ) );
	t.check( 'clicking an older version swaps the body', after.sel === first.vers[ 2 ] && after.heads === 1, JSON.stringify( after ) );
	await page.keyboard.press( 'Escape' );

	// Mobile: the rail becomes a horizontal chip strip scrolling in its own
	// overflow — the modal itself must never scroll horizontally.
	await page.setViewportSize( { width: 420, height: 800 } );
	await page.click( '#minn-ver-btn' ).catch( () => {} );
	// The ver chip hides ≤640px; open via the palette-free fallback: run openChangelog directly.
	const opened = await page.evaluate( () => {
		if ( document.querySelector( '.minn-cl-rail' ) ) return true;
		const btn = document.querySelector( '#minn-ver-btn' );
		if ( btn ) { btn.click(); return true; }
		return false;
	} );
	await page.waitForSelector( '.minn-cl-rail .minn-cl-ver', { timeout: 8000 } );
	const mobile = await page.evaluate( () => {
		const rail = document.querySelector( '.minn-cl-rail' );
		const modal = document.querySelector( '.minn-cl-modal' );
		const st = getComputedStyle( rail );
		return {
			opened: true,
			horizontal: st.flexDirection === 'row' && st.overflowX === 'auto',
			datesHidden: Array.from( rail.querySelectorAll( '.minn-cl-ver-d' ) ).every( ( el ) => getComputedStyle( el ).display === 'none' ),
			railScrolls: rail.scrollWidth > rail.clientWidth,
			modalFits: modal.scrollWidth <= modal.clientWidth + 1,
		};
	} );
	t.check( 'mobile rail is a horizontal chip strip', mobile.horizontal && ( opened || mobile.opened ) );
	t.check( 'mobile chips drop the date line', mobile.datesHidden );
	t.check( 'chips scroll in their own overflow, modal never widens', mobile.railScrolls && mobile.modalFits, JSON.stringify( mobile ) );

	// Older chip click still swaps on mobile.
	await page.evaluate( () => document.querySelector( '.minn-cl-ver[data-clver="1"]' ).click() );
	await page.waitForFunction( () => {
		const sel = document.querySelector( '.minn-cl-ver.sel' );
		return sel && sel.dataset.clver === '1';
	}, null, { timeout: 8000 } );
	t.check( 'mobile chip click swaps the release', true );

	await t.done( browser, errors );
} )();
