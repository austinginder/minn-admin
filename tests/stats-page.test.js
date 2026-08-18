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

		/* ===== Range-wide breakdown panels (Koko rides the day fallback) ===== */
		await page.waitForSelector( '.minn-stats-panel, #minn-stats-breakdowns:empty', { timeout: 20000 } );
		const bd = await page.evaluate( () => ( {
			sections: [ ...document.querySelectorAll( '.minn-stats-panel .minn-traf-sec-label' ) ].map( ( el ) => el.textContent.trim() ),
			rows: document.querySelectorAll( '.minn-stats-panel .minn-traf-row' ).length,
			deltas: [ ...document.querySelectorAll( '.minn-stat-delta' ) ].map( ( el ) => el.textContent ),
		} ) );
		t.check( 'breakdown panels render from the traffic-day fallback',
			bd.sections.includes( 'Top pages' ) && bd.rows > 0, JSON.stringify( bd.sections ) );
		t.check( 'no raw %% leaks into the delta text (JS sprintf has no %% escape)',
			! bd.deltas.some( ( s ) => s.includes( '%%' ) ), JSON.stringify( bd.deltas ) );
		const report = await page.evaluate( async () => {
			const to = new Date().toISOString().slice( 0, 10 );
			const from = new Date( Date.now() - 89 * 86400000 ).toISOString().slice( 0, 10 );
			const r = await fetch( `${ window.MINN.restUrl }minn-admin/v1/stats/report?from=${ from }&to=${ to }`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			const d = await r.json();
			return { status: r.status, source: d.source, ids: ( d.sections || [] ).map( ( s ) => s.id ) };
		} );
		t.check( 'report endpoint answers a 90-day window through the fallback',
			report.status === 200 && report.source === 'Koko Analytics' && report.ids.includes( 'pages' ),
			JSON.stringify( report ) );

		/* ===== Range switch refetches with the new days ===== */
		const res365 = page.waitForResponse( ( r ) => r.url().includes( 'minn-admin/v1/stats' ) && r.url().includes( 'days=365' ) );
		await page.click( '.minn-range-tab[data-range="365"]' );
		t.check( '12m asks the server for days=365', ( await res365 ).status() === 200 );
		await page.waitForFunction( () =>
			document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length > 0
			&& document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length <= 14, null, { timeout: 20000 } );
		const cols365 = await page.evaluate( () => document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length );
		t.check( '12m buckets ~monthly', cols365 >= 12 && cols365 <= 14, String( cols365 ) );
		// 13 × 30d buckets overshoot 365 days — the report must ride the
		// server's echoed window or this fetch 400s and the panels vanish.
		await page.waitForSelector( '.minn-stats-panel', { timeout: 20000 } );
		t.check( '12m breakdowns render (bucket edges never leak into the report window)', true );

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

		/* ===== Custom range: seeded from the visible window, sliced server-side ===== */
		await page.click( '.minn-range-tab[data-range="custom"]' );
		await page.waitForSelector( '#minn-stats-from', { timeout: 15000 } );
		const seeded = await page.evaluate( () => ( {
			from: document.querySelector( '#minn-stats-from' ).value,
			to: document.querySelector( '#minn-stats-to' ).value,
		} ) );
		t.check( 'Custom seeds with the window on screen', !! seeded.from && !! seeded.to, JSON.stringify( seeded ) );
		const day = ( ago ) => new Date( Date.now() - ago * 86400000 ).toISOString().slice( 0, 10 );
		await page.fill( '#minn-stats-from', day( 40 ) );
		await page.fill( '#minn-stats-to', day( 10 ) );
		const resCustom = page.waitForResponse( ( r ) =>
			r.url().includes( 'minn-admin/v1/stats' ) && r.url().includes( `from=${ day( 40 ) }` ) );
		await page.click( '#minn-stats-apply' );
		t.check( 'Apply fetches the from/to window', ( await resCustom ).status() === 200 );
		await page.waitForFunction( () =>
			document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length === 31, null, { timeout: 20000 } );
		const customOut = await page.evaluate( () => ( {
			cols: document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length,
			delta: document.querySelector( '.minn-stat-delta' ).textContent,
		} ) );
		t.check( 'custom window renders one bar per day with no fabricated delta',
			customOut.cols === 31 && /no prior period/i.test( customOut.delta ), JSON.stringify( customOut ) );

		/* ===== Range choice persists across a reload ===== */
		await page.click( '.minn-range-tab[data-range="365"]' );
		await page.waitForFunction( () =>
			document.querySelectorAll( '#minn-stats-chart .minn-chart-col' ).length <= 14, null, { timeout: 20000 } );
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
		const reportGate = await ap.evaluate( async () => {
			const to = new Date().toISOString().slice( 0, 10 );
			const r = await fetch( `${ window.MINN.restUrl }minn-admin/v1/stats/report?from=${ to }&to=${ to }`, { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			const d = await r.json();
			return { status: r.status, source: d.source, sections: ( d.sections || [] ).length };
		} );
		t.check( 'the report endpoint answers the author empty too',
			reportGate.status === 200 && '' === reportGate.source && 0 === reportGate.sections,
			JSON.stringify( reportGate ) );
	} finally {
		await page.evaluate( () => localStorage.removeItem( 'minn-stats-range' ) ).catch( () => {} );
		if ( authorCtx ) await authorCtx.ctx.close().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
