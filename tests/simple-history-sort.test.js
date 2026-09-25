/**
 * Simple History — sortable columns (5.34's orderby/order on its events route).
 *
 * Proves: Event, Level and When headers are sortable; a click sends
 * orderby/order to Simple History's own route; the rendered first row matches
 * what Simple History itself returns for that order (derived from the API, not
 * assumed); a second click flips direction.
 *
 * Simple History is installed-inactive on minnadmin (WSAL is the resident
 * activity log); the suite activates it and restores what it found.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( args ) => execSync( `wp --path=${ JSON.stringify( WP ) } ${ args } 2>/dev/null` ).toString().trim();

( async () => {
	const t = reporter( 'simple-history-sort' );
	let wasActive = false;
	try { wp( 'plugin is-active simple-history' ); wasActive = true; } catch ( e ) {}
	if ( ! wasActive ) wp( 'plugin activate simple-history' );
	// Seed a few events with distinct messages so both directions have a
	// deterministic, distinct first row (SH logs through its own API).
	const stamp = Date.now();
	wp( `eval 'if ( function_exists( "SimpleLogger" ) ) { SimpleLogger()->info( "Aardvark sort probe ${ stamp }" ); SimpleLogger()->warning( "Zebra sort probe ${ stamp }" ); } echo 1;'` );

	const { browser, page, errors } = await launch();
	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/simple-history', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-ssort="message"]', { timeout: 20000 } );
		const heads = await page.$$eval( '[data-ssort]', ( els ) => els.map( ( e ) => e.dataset.ssort ) );
		t.check( 'Event, Level and When are sortable', [ 'message', 'level', 'date' ].every( ( k ) => heads.includes( k ) ), JSON.stringify( heads ) );

		const expectFirst = ( dir ) => page.evaluate( async ( d ) => {
			const r = await fetch( window.MINN.restUrl + 'simple-history/v1/events?per_page=1&page=1&orderby=message&order=' + d, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const rows = await r.json();
			return ( rows[ 0 ] && rows[ 0 ].message ) || '';
		}, dir );
		const asc = await expectFirst( 'asc' );
		const desc = await expectFirst( 'desc' );
		t.check( 'Simple History itself orders messages both ways', !! asc && !! desc && asc !== desc, `${ asc } | ${ desc }` );

		const firstRowIs = ( want ) => page.waitForFunction( ( w ) => {
			const el = document.querySelector( '.minn-table-row .minn-row-title' );
			return !! el && el.textContent.trim().startsWith( w.trim().slice( 0, 40 ) );
		}, want, { timeout: 15000, polling: 300 } );
		const sortReq = ( by, dir ) => page.waitForRequest( ( r ) => r.url().includes( 'simple-history/v1/events' )
			&& r.url().includes( 'orderby=' + by ) && r.url().includes( 'order=' + dir ), { timeout: 10000 } );

		let wait = sortReq( 'message', 'asc' );
		await page.click( '[data-ssort="message"]' );
		await wait;
		await firstRowIs( asc );
		t.check( 'first click sorts Event ascending (request + rendered order)', true );
		wait = sortReq( 'message', 'desc' );
		await page.click( '[data-ssort="message"]' );
		await wait;
		await firstRowIs( desc );
		t.check( 'repeat click flips to descending', true );

		wait = sortReq( 'level', 'asc' );
		await page.click( '[data-ssort="level"]' );
		const lvl = await wait;
		const resp = await lvl.response();
		t.check( 'Level sort reaches their route and answers 200', !! resp && 200 === resp.status(), resp ? String( resp.status() ) : 'no response' );
	} finally {
		if ( ! wasActive ) {
			try { wp( 'plugin deactivate simple-history' ); } catch ( e ) {}
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
