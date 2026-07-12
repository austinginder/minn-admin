/**
 * Status-card chart row (Rung-3): server-built daily series rendered above a
 * surface list with Overview-style bars and a hover tip.
 *
 * First consumer is Gravity SMTP's Email status card (sent/failed dual series
 * for the last 14 UTC days). Fixture: minn_test_seed_gsmtp_chart inserts a
 * known 3-day pattern under subject "minn-chart-fixture".
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'status-chart' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = async ( key, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( args ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ args.key ]: args.v } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ args.key ];
			}, { key, v } );
			// One-shot seeders clear themselves on the next init, so '' after
			// write also means success (consumed = seeded).
			if ( stored === v || ( v === '1' && ( stored === '' || stored == null ) ) ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	try {
		const seeded = await setOpt( 'minn_test_seed_gsmtp_chart', '1' );
		t.check( 'chart fixture armed', seeded );

		// Status is built server-side — assert the payload first so a UI miss
		// is distinguishable from a bad chart builder.
		const status = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/gravity-smtp/status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { ok: r.ok, body: await r.json() };
		} );
		t.check( 'status route 200', status.ok, JSON.stringify( status.body && status.body.code ) );
		const chart = status.body && status.body.chart;
		t.check( 'status carries a chart', !!( chart && Array.isArray( chart.points ) ), JSON.stringify( chart && Object.keys( chart || {} ) ) );
		t.check( 'chart is 14 points', chart && chart.points.length === 14, chart && String( chart.points.length ) );
		t.check( 'chart labels dual series', chart && chart.primary === 'Sent' && chart.secondary === 'Failed' );
		t.check( 'chart title names the window', chart && /Last 14 days/.test( chart.title || '' ), chart && chart.title );

		// Last three points include the fixture pattern (UTC today-2 / -1 / today).
		// Other real log rows on those days can raise the totals, so assert
		// minimums rather than exact equality on a shared site.
		const last3 = chart.points.slice( -3 );
		t.check(
			'fixture day-2 ≥ 3 sent',
			last3[ 0 ] && last3[ 0 ].value >= 3,
			JSON.stringify( last3[ 0 ] )
		);
		t.check(
			'fixture day-1 ≥ 2 sent / ≥ 1 failed',
			last3[ 1 ] && last3[ 1 ].value >= 2 && last3[ 1 ].secondary >= 1,
			JSON.stringify( last3[ 1 ] )
		);
		t.check(
			'fixture today ≥ 1 sent / ≥ 2 failed',
			last3[ 2 ] && last3[ 2 ].value >= 1 && last3[ 2 ].secondary >= 2,
			JSON.stringify( last3[ 2 ] )
		);

		// Pin the mail family to Gravity SMTP so the status card is the one
		// with the chart (FluentSMTP is also active on this site).
		await page.evaluate( () => localStorage.setItem( 'minn-sf-mail', 'gravity-smtp' ) );
		await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status [data-sstat-chart]', { timeout: 20000 } );

		const ui = await page.evaluate( () => {
			const card = document.querySelector( '.minn-surface-status' );
			const bars = card && card.querySelector( '[data-sstat-chart]' );
			const cols = bars ? bars.querySelectorAll( '.minn-chart-col' ).length : 0;
			const title = card && card.querySelector( '.minn-sstat-chart .minn-sstat-label' );
			const dual = bars && bars.querySelector( '.minn-chart-views' ) && bars.querySelector( '.minn-chart-visitors' );
			return {
				cols,
				title: title ? title.textContent.trim() : '',
				dual: !! dual,
				rows: [ ...card.querySelectorAll( '.minn-sstat .minn-sstat-label' ) ].map( ( el ) => el.textContent.trim() ),
			};
		} );
		t.check( 'status card renders 14 bar columns', ui.cols === 14, String( ui.cols ) );
		t.check( 'chart title visible', ui.title === 'Last 14 days', ui.title );
		t.check( 'dual bars (sent solid / total soft)', ui.dual );
		t.check( 'stat rows still render beside the chart', ui.rows.includes( 'Sending through' ) && ui.rows.includes( 'Test mode' ), ui.rows.join( ',' ) );

		// Real mouse over the last column (today) — tip mirrors the live payload.
		const today = last3[ 2 ];
		const lastCol = page.locator( '[data-sstat-chart] .minn-chart-col' ).last();
		const box = await lastCol.boundingBox();
		t.check( 'last bar is on screen', !!( box && box.width > 0 ), JSON.stringify( box ) );
		if ( box ) {
			await page.mouse.move( box.x + box.width / 2, box.y + Math.max( 4, box.height - 8 ) );
			await page.waitForFunction( () => {
				const tip = document.querySelector( '#minn-chart-tip' );
				return tip && ! tip.hidden && /Sent/.test( tip.textContent ) && /Failed/.test( tip.textContent );
			}, null, { timeout: 5000 } ).catch( () => null );
		}
		const tipText = await page.evaluate( () => {
			const tip = document.querySelector( '#minn-chart-tip' );
			if ( ! tip || tip.hidden ) return '';
			return tip.textContent.replace( /\s+/g, ' ' ).trim();
		} );
		const sentStr = Number( today.value ).toLocaleString();
		const failStr = Number( today.secondary ).toLocaleString();
		t.check(
			'hover tip mirrors today payload',
			tipText.includes( 'Sent' ) && tipText.includes( 'Failed' )
				&& tipText.includes( sentStr ) && tipText.includes( failStr ),
			`tip="${ tipText }" expected Sent ${ sentStr } / Failed ${ failStr }`
		);

		await t.done( browser, errors );
	} catch ( e ) {
		console.error( e );
		await t.done( browser, errors.concat( [ String( e ) ] ) );
		process.exit( 1 );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
