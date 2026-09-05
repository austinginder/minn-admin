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
 * The chart's soft bar is everything logged that day (sent + failed +
 * the point's extra rows: sandboxed, filtered), so a bar's total must equal
 * the All tab's windowed count exactly, and the Failed tab the bar's
 * secondary series. The suite seeds two sandboxed and one filtered row
 * for today (UTC) under subject "minn-chart-fixture-held" (delete +
 * reinsert each run, so they never accumulate) to prove the extra rows.
 */
const { launch, login, reporter, BASE, WP } = require( './helpers' );

( async () => {
	const t = reporter( 'chart-day-filter' );
	const { browser, page, errors } = await launch();

	// Seed today's held rows through wp-cli (eval-file: an inline snippet
	// would lose $wpdb to the shell). WP resolves the site under test.
	let seed = '';
	try {
		const fs = require( 'fs' );
		const file = require( 'path' ).join( require( 'os' ).tmpdir(), 'minn-chart-held-seed.php' );
		fs.writeFileSync( file, [
			'<?php',
			'global $wpdb;',
			'$table = $wpdb->prefix . "gravitysmtp_events";',
			'$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE subject = %s", "minn-chart-fixture-held" ) );',
			'$ts = gmdate( "Y-m-d 12:00:00" );',
			'foreach ( array( "sandboxed", "sandboxed", "partially-sent" ) as $st ) {',
			'  $wpdb->insert( $table, array( "date_created" => $ts, "date_updated" => $ts, "status" => $st, "service" => "smtp", "subject" => "minn-chart-fixture-held", "message" => "held fixture body", "extra" => "" ) );',
			'}',
			'echo "seeded";',
		].join( '\n' ) );
		seed = require( 'child_process' ).execSync(
			`wp --path=${ JSON.stringify( WP ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`,
			{ encoding: 'utf8', timeout: 60000 }
		).trim();
		fs.unlinkSync( file );
	} catch ( e ) {
		seed = 'failed: ' + e.message;
	}
	await login( page );
	t.check( 'Held rows seeded for today', seed === 'seeded', seed );
	const extraOf = ( p ) => ( Array.isArray( p.extra ) ? p.extra : [] ).reduce( ( n, x ) => n + ( Number( x.value ) || 0 ), 0 );
	const totalOf = ( p ) => ( Number( p.value ) || 0 ) + ( Number( p.secondary ) || 0 ) + extraOf( p );

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
	const withData = points.filter( ( p ) => totalOf( p ) > 0 );
	t.check( 'Chart points carry from/to bounds', points.length > 0 && points.every( ( p ) => p.from && p.to ), JSON.stringify( points[ 0 ] ) );
	t.check( 'A bar with sends exists to click', withData.length > 0, `nonzero=${ withData.length }` );
	// Today (UTC) is the last point and carries the seeded held rows.
	const bar = points[ points.length - 1 ];
	const held = ( bar.extra || [] ).reduce( ( m, x ) => Object.assign( m, { [ x.label ]: x.value } ), {} );
	t.check( 'Today\'s point lists sandboxed and filtered as extra rows', held.Sandboxed >= 2 && held.Filtered >= 1, JSON.stringify( bar ) );
	const windowed = await api( `minn-admin/v1/gravity-smtp/events?after=${ encodeURIComponent( bar.from ) }&before=${ encodeURIComponent( bar.to ) }&per_page=100` );
	const inWindow = windowed.body.items.every( ( it ) => it.date_created >= bar.from && it.date_created <= bar.to );
	t.check( 'after/before narrow the events route to the bar', windowed.status === 200 && inWindow, JSON.stringify( { total: windowed.body.total } ) );
	t.check( 'Bar total equals the day\'s All count exactly', windowed.body.total === totalOf( bar ), `route ${ windowed.body.total } vs bar ${ totalOf( bar ) }` );
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
		const last = cols[ cols.length - 1 ];
		const r = last && last.getBoundingClientRect();
		return { picks: picks.length, empties: empties.length, ci: last && last.dataset.ci, lastPick: !! last && last.classList.contains( 'pick' ), x: r && r.left + r.width / 2, y: r && r.bottom - 4 };
	} );
	t.check( 'Today\'s bar is pickable', pick.lastPick, JSON.stringify( pick ) );
	// Hover first: the tip lists the held rows after Sent / Failed.
	await page.mouse.move( pick.x, pick.y - 2 );
	const tip = await page.waitForFunction( () => {
		const el = document.querySelector( '#minn-chart-tip' );
		return el && ! el.hidden && /Sandboxed/.test( el.textContent ) ? el.textContent.replace( /\s+/g, ' ' ).trim() : false;
	}, null, { timeout: 5000 } ).then( ( h ) => h.jsonValue() ).catch( () => '' );
	t.check( 'Tip lists Sandboxed and Filtered rows', /Sandboxed/.test( tip ) && /Filtered/.test( tip ) && /Sent/.test( tip ), tip );
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
		await page.click( '[data-stab="sandboxed"]' );
		t.check( 'Sandboxed tab matches the bar\'s Sandboxed row', await waitCount( held.Sandboxed ), `want ${ held.Sandboxed }, got ${ await metaCount() }` );
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
