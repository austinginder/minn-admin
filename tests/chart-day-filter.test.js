/**
 * Status-card chart → list day filter (collection `dateQuery`).
 *
 * A bar on a surface's status chart narrows the list beneath it to that
 * bar's window: the point's from/to ride the collection's dateQuery
 * template verbatim, a chip beside the tabs names the day and clears it,
 * the same bar again widens the list back, and the narrowing combines
 * with the status tabs. Gravity SMTP is the reference (UTC bounds);
 * FluentSMTP is checked for the same affordance on site-local bounds.
 *
 * The chart counts only sent + failed, so the "All" list total for a day
 * is compared against the route's own windowed count, and the Failed tab
 * against the bar's secondary series.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'chart-day-filter' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return { status: r.status, body: await r.json() };
	}, path );

	const metaCount = () => page.evaluate( () => {
		const m = document.querySelector( '.minn-toolbar-meta' );
		const n = m && m.textContent.replace( /[^\d]/g, '' );
		return n ? parseInt( n, 10 ) : null;
	} );
	const waitCount = async ( n ) => page.waitForFunction( ( want ) => {
		const m = document.querySelector( '.minn-toolbar-meta' );
		const t = m && m.textContent.replace( /[^\d]/g, '' );
		return !! t && parseInt( t, 10 ) === want;
	}, n, { timeout: 15000 } ).then( () => true ).catch( () => false );

	// --- REST: the window narrows, junk bounds are ignored --------------
	const status = await api( 'minn-admin/v1/gravity-smtp/status' );
	const points = ( status.body && status.body.chart && status.body.chart.points ) || [];
	const withData = points.filter( ( p ) => ( Number( p.value ) || 0 ) + ( Number( p.secondary ) || 0 ) > 0 );
	t.check( 'Chart points carry from/to bounds', points.length > 0 && points.every( ( p ) => p.from && p.to ), JSON.stringify( points[ 0 ] ) );
	t.check( 'A bar with sends exists to click', withData.length > 0, `nonzero=${ withData.length }` );
	const bar = withData[ withData.length - 1 ];
	const windowed = bar ? await api( `minn-admin/v1/gravity-smtp/events?after=${ encodeURIComponent( bar.from ) }&before=${ encodeURIComponent( bar.to ) }&per_page=100` ) : null;
	const inWindow = windowed && windowed.body.items.every( ( it ) => it.date_created >= bar.from && it.date_created <= bar.to );
	t.check( 'after/before narrow the events route to the bar', !! windowed && windowed.status === 200 && windowed.body.total >= bar.value + bar.secondary && inWindow,
		JSON.stringify( { total: windowed && windowed.body.total, bar: bar && ( bar.value + bar.secondary ) } ) );
	const all = await api( 'minn-admin/v1/gravity-smtp/events?per_page=1' );
	const junk = await api( 'minn-admin/v1/gravity-smtp/events?per_page=1&after=' + encodeURIComponent( "1' OR 1=1" ) + '&before=yesterday' );
	t.check( 'Junk bounds are ignored, not guessed', junk.status === 200 && junk.body.total === all.body.total, `${ junk.body.total } vs ${ all.body.total }` );

	// --- Surface: click the bar -------------------------------------------
	await page.evaluate( () => localStorage.setItem( 'minn-sf-mail', 'gravity-smtp' ) );
	await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-sstat-chart] .minn-chart-col', { timeout: 20000 } );
	await page.waitForSelector( '.minn-toolbar-meta', { timeout: 20000 } );
	const total = all.body.total;
	t.check( 'Unfiltered count matches the route total', await waitCount( total ), `want ${ total }` );

	const pick = await page.evaluate( () => {
		const cols = Array.from( document.querySelectorAll( '[data-sstat-chart] .minn-chart-col' ) );
		const picks = cols.filter( ( c ) => c.classList.contains( 'pick' ) );
		const empties = cols.filter( ( c ) => ! c.classList.contains( 'pick' ) );
		const last = picks[ picks.length - 1 ];
		const r = last && last.getBoundingClientRect();
		return { picks: picks.length, empties: empties.length, ci: last && last.dataset.ci, x: r && r.left + r.width / 2, y: r && r.bottom - 4 };
	} );
	t.check( 'Bars with sends take the pointer; empty days do not', pick.picks > 0 && pick.picks === withData.length, JSON.stringify( pick ) );
	t.check( 'No chip before any click', ! ( await page.$( '[data-srange-clear]' ) ) );

	await page.mouse.click( pick.x, pick.y );
	const chipOk = await page.waitForSelector( '[data-srange-clear]', { timeout: 15000 } ).then( () => true ).catch( () => false );
	t.check( 'Clicking a bar shows the day chip', chipOk );
	const chipText = await page.evaluate( () => ( document.querySelector( '[data-srange-clear]' ) || {} ).textContent || '' );
	t.check( 'Chip names the bar', chipText.includes( bar.label ), chipText.trim() );
	t.check( 'List narrows to the bar\'s window', await waitCount( windowed.body.total ), `want ${ windowed.body.total }, got ${ await metaCount() }` );
	t.check( 'Selected bar is highlighted', await page.evaluate( ( ci ) => {
		const col = document.querySelector( `[data-sstat-chart] [data-ci="${ ci }"]` );
		return !! col && col.classList.contains( 'selected' ) && document.querySelectorAll( '[data-sstat-chart] .selected' ).length === 1;
	}, pick.ci ) );
	const dates = await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).length );
	t.check( 'Rows on the page belong to that day', dates <= windowed.body.total, `rows=${ dates }` );

	// Combines with the status tabs.
	const failedTab = await page.$( '[data-stab="failed"]' );
	if ( failedTab ) {
		await failedTab.click();
		t.check( 'Failed tab keeps the day window', await waitCount( bar.secondary ), `want ${ bar.secondary }, got ${ await metaCount() }` );
		const allTab = await page.$( '[data-stab="_all"]' );
		if ( allTab ) await allTab.click();
		await waitCount( windowed.body.total );
	}

	// The chip clears it.
	await page.click( '[data-srange-clear]' );
	t.check( 'Chip clears the day filter', await waitCount( total ), `want ${ total }, got ${ await metaCount() }` );
	t.check( 'Chip and highlight are gone', await page.evaluate( () =>
		! document.querySelector( '[data-srange-clear]' ) && ! document.querySelector( '[data-sstat-chart] .selected' ) ) );

	// The same bar again toggles it off.
	const again = await page.evaluate( ( ci ) => {
		const col = document.querySelector( `[data-sstat-chart] [data-ci="${ ci }"]` );
		const r = col.getBoundingClientRect();
		return { x: r.left + r.width / 2, y: r.bottom - 4 };
	}, pick.ci );
	await page.mouse.click( again.x, again.y );
	await page.waitForSelector( '[data-srange-clear]', { timeout: 15000 } ).catch( () => null );
	await waitCount( windowed.body.total );
	const again2 = await page.evaluate( ( ci ) => {
		const col = document.querySelector( `[data-sstat-chart] [data-ci="${ ci }"]` );
		const r = col.getBoundingClientRect();
		return { x: r.left + r.width / 2, y: r.bottom - 4 };
	}, pick.ci );
	await page.mouse.click( again2.x, again2.y );
	t.check( 'Clicking the selected bar again widens the list back', await waitCount( total ) && ! ( await page.$( '[data-srange-clear]' ) ), `got ${ await metaCount() }` );

	// --- FluentSMTP: same affordance on site-local bounds -----------------
	await page.evaluate( () => localStorage.setItem( 'minn-sf-mail', 'fluent-smtp' ) );
	await page.goto( BASE + '/minn-admin/fluent-smtp', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-sstat-chart] .minn-chart-col', { timeout: 20000 } );
	const fs = await api( 'minn-admin/v1/fluent-smtp/status' );
	const fpts = ( fs.body && fs.body.chart && fs.body.chart.points ) || [];
	const fbar = fpts.filter( ( p ) => p.value + p.secondary > 0 ).pop();
	if ( fbar ) {
		const fw = await api( `minn-admin/v1/fluent-smtp/emails?after=${ encodeURIComponent( fbar.from ) }&before=${ encodeURIComponent( fbar.to ) }&per_page=1` );
		const fpick = await page.evaluate( () => {
			const picks = Array.from( document.querySelectorAll( '[data-sstat-chart] .minn-chart-col.pick' ) );
			const last = picks[ picks.length - 1 ];
			const r = last && last.getBoundingClientRect();
			return r && { x: r.left + r.width / 2, y: r.bottom - 4 };
		} );
		t.check( 'FluentSMTP bars are pickable', !! fpick );
		if ( fpick ) {
			await page.mouse.click( fpick.x, fpick.y );
			await page.waitForSelector( '[data-srange-clear]', { timeout: 15000 } ).catch( () => null );
			t.check( 'FluentSMTP narrows to the day on site-local bounds', await waitCount( fw.body.total ), `want ${ fw.body.total }, got ${ await metaCount() }` );
			await page.click( '[data-srange-clear]' );
		}
	} else {
		t.check( 'FluentSMTP chart has data to pick (fixture)', false, 'no nonzero bar' );
	}
	await page.evaluate( () => localStorage.removeItem( 'minn-sf-mail' ) );

	await t.done( browser, errors );
} )();
