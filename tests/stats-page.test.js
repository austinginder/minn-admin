/**
 * Stats page (/minn-admin/stats): the dedicated dig-in behind the Overview
 * traffic card. Longer ranges ride the same minn_admin_traffic provider
 * filter with server-side bucketing (daily ≤45d, weekly ≤190d, ~monthly
 * beyond); bar clicks reuse the traffic-day drill modal. Entered through the
 * Overview door, the ⌘K command, or a deep link. Reporting stays behind the
 * traffic capability: an Author sees the permission empty state, never
 * numbers. Uses the resident Koko Analytics fixture's live data — asserts
 * structure, never absolute counts.
 */
const { launch, login, loginAs, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'stats-page' );
	const { browser, page, errors } = await launch();
	await login( page );

	let authorCtx = null;
	try {
		/* ===== Door from the Overview traffic card ===== */
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-chart', { timeout: 20000 } );
		const door = await page.$( '#minn-open-stats' );
		t.check( 'Overview traffic card offers the stats door', !! door );
		if ( door ) {
			await door.click();
			await page.waitForSelector( '#minn-stats-chart', { timeout: 20000 } );
			t.check( 'door lands on /minn-admin/stats', page.url().includes( '/minn-admin/stats' ), page.url() );
		}

		/* ===== Default range: totals + daily buckets ===== */
		const shape = await page.evaluate( () => ( {
			cards: [ ...document.querySelectorAll( '.minn-stat .minn-stat-label' ) ].map( ( el ) => el.textContent.trim() ),
			cols: document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length,
			source: ( document.querySelector( '.minn-panel-sub' ) || {} ).textContent,
		} ) );
		t.check( 'totals row leads with Visitors and Pageviews',
			shape.cards[ 0 ] === 'Visitors' && shape.cards[ 1 ] === 'Pageviews', JSON.stringify( shape.cards ) );
		t.check( '30d renders 30 daily buckets from the provider',
			shape.cols === 30 && !! shape.source, JSON.stringify( shape ) );

		/* ===== Range switch refetches with the new days ===== */
		const res365 = page.waitForResponse( ( r ) => r.url().includes( 'minn-admin/v1/stats' ) && r.url().includes( 'days=365' ) );
		await page.click( '.minn-range-tab[data-range="365"]' );
		t.check( '12m asks the server for days=365', ( await res365 ).status() === 200 );
		await page.waitForFunction( () =>
			document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length > 0
			&& document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length <= 14, null, { timeout: 20000 } );
		const cols365 = await page.evaluate( () => document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length );
		t.check( '12m buckets ~monthly', cols365 >= 12 && cols365 <= 14, String( cols365 ) );

		/* ===== Bar click opens the drill modal (data bar only — rule 80) ===== */
		const clicked = await page.evaluate( () => {
			const cols = [ ...document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ) ];
			for ( let i = cols.length - 1; i >= 0; i-- ) {
				const vis = cols[ i ].querySelector( '.minn-chart-visitors' );
				if ( vis && parseInt( vis.style.height, 10 ) > 2 ) { cols[ i ].click(); return true; }
			}
			return false;
		} );
		if ( ! clicked ) {
			console.log( 'note: no non-zero bar in 12m — drill check skipped' );
		} else {
			await page.waitForSelector( '.minn-traf-day', { timeout: 15000 } );
			const drill = await page.evaluate( () => ( {
				label: ( document.querySelector( '.minn-traf-sec-label' ) || {} ).textContent,
			} ) );
			t.check( 'drill modal shows top pages for the bucket', /top pages/i.test( drill.label || '' ), JSON.stringify( drill ) );
			await page.keyboard.press( 'Escape' );
			await page.waitForTimeout( 200 );
		}

		/* ===== Range choice persists across a reload ===== */
		await page.goto( BASE + '/minn-admin/stats', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-range-tab.active', { timeout: 20000 } );
		const active = await page.evaluate( () => ( document.querySelector( '.minn-range-tab.active' ) || {} ).dataset.range );
		t.check( 'deep link works and the range sticks', active === '365', String( active ) );
		await page.evaluate( () => localStorage.removeItem( 'minn-stats-range' ) );

		/* ===== ⌘K command ===== */
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 10000 } );
		await page.type( '#minn-palette-input', 'traffic stats' );
		await page.waitForTimeout( 400 );
		const hasCmd = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-palette-item .minn-palette-label' ) )
				.some( ( el ) => /View traffic stats/.test( el.textContent ) ) );
		t.check( 'palette offers View traffic stats', hasCmd );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );

		/* ===== Author: page loads, numbers stay behind the cap ===== */
		authorCtx = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		const ap = authorCtx.page;
		await ap.goto( BASE + '/minn-admin/stats', { waitUntil: 'domcontentloaded' } );
		await ap.waitForSelector( '.minn-empty', { timeout: 20000 } );
		const gated = await ap.evaluate( () => ( {
			empty: ( document.querySelector( '.minn-empty' ) || {} ).textContent,
			chart: !! document.querySelector( '#minn-stats-chart' ),
		} ) );
		t.check( 'author sees the permission empty state, no chart',
			! gated.chart && /permission/i.test( gated.empty || '' ), JSON.stringify( gated ) );
		const apiGate = await ap.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/stats', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			const d = await r.json();
			return { status: r.status, allowed: d.allowed, totals: d.totals, cols: ( d.chart || [] ).length };
		} );
		t.check( 'the endpoint answers the author empty (no series, no totals)',
			apiGate.status === 200 && apiGate.allowed === false && ! apiGate.totals && apiGate.cols === 0,
			JSON.stringify( apiGate ) );
	} finally {
		await page.evaluate( () => localStorage.removeItem( 'minn-stats-range' ) ).catch( () => {} );
		if ( authorCtx ) await authorCtx.ctx.close().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
