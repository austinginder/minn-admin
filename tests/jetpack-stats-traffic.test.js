/**
 * Jetpack Stats traffic provider — the mu-fixture minn_test_jetpack_stats
 * mocks ONLY the WordPress.com side (connection options + the
 * public-api.wordpress.com stats responses); the adapter's gates, Jetpack's
 * WPCOM_Stats client, its caching and Minn's mapping all run for real.
 * Jetpack rests installed-inactive; the suite activates it and restores.
 * Koko stays active (the fixture's priority-16 reset lets the 20-priority
 * Jetpack adapter answer, the Site Kit fixture convention).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'jetpack-stats-traffic' );

	await login( page );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_jetpack_stats: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_jetpack_stats;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	// Jetpack is a large plugin: its toggle can recycle the PHP worker
	// mid-response (the theme-install precedent). On a dropped fetch, wait,
	// then ask the plugin itself for the truth and retry only if it's wrong.
	const setStatus = async ( id, status ) => {
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				return await page.evaluate( async ( a ) => {
					const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.id, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
						credentials: 'same-origin',
						body: JSON.stringify( { status: a.status } ),
					} );
					return ( await r.json() ).status;
				}, { id, status } );
			} catch ( e ) {
				await page.waitForTimeout( 5000 );
				const now = await page.evaluate( async ( pid ) => {
					const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + pid + '?_fields=status&_cb=' + Math.random(), {
						headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
					} );
					return r.ok ? ( await r.json() ).status : null;
				}, id ).catch( () => null );
				if ( now === status ) return now;
			}
		}
		throw new Error( `plugin toggle failed: ${ id } -> ${ status }` );
	};

	const chartState = async () => {
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-chart', { timeout: 20000 } );
		await page.waitForTimeout( 500 );
		return page.evaluate( () => ( {
			sub: ( document.querySelector( '.minn-panel-sub' ) || {} ).textContent || '',
			cols: document.querySelectorAll( '.minn-chart-col' ).length,
		} ) );
	};

	let jetpackWas = null;
	try {
		const plugins = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,name,status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		} );
		const jetpack = plugins.find( ( p ) => p.plugin === 'jetpack/jetpack' );
		t.check( 'Jetpack installed', !! jetpack, jetpack && jetpack.status );
		if ( ! jetpack ) throw new Error( 'jetpack not installed on this site' );
		jetpackWas = jetpack.status;
		if ( jetpackWas !== 'active' ) await setStatus( jetpack.plugin, 'active' );

		t.check( 'Fixture on (write verified)', await setOpt( '1' ) );

		const on = await chartState();
		t.check( 'Chart source reads Jetpack Stats', on.sub.includes( 'Jetpack Stats' ), on.sub );
		t.check( 'Traffic bars render', on.cols > 0, `cols=${ on.cols }` );

		// Click the last bar WITH data (the chart's buckets are UTC-anchored,
		// so in the site's evening the final bar is tomorrow-UTC and empty —
		// zero bars are deliberate click no-ops).
		const dataCi = await page.evaluate( () => {
			const cols = Array.from( document.querySelectorAll( '.minn-chart-col[data-ci]' ) );
			for ( let i = cols.length - 1; i >= 0; i-- ) {
				const has = Array.from( cols[ i ].querySelectorAll( '[style*="height"]' ) )
					.some( ( el ) => parseFloat( el.style.height || '0' ) > 0 );
				if ( has ) return cols[ i ].dataset.ci;
			}
			return null;
		} );
		t.check( 'Chart has a data bar', dataCi !== null, `ci=${ dataCi }` );
		await page.click( `.minn-chart-col[data-ci="${ dataCi }"]` );
		await page.waitForSelector( '.minn-traf-day, .minn-empty', { timeout: 20000 } );
		const day = await page.evaluate( () => {
			const modal = document.querySelector( '.minn-modal' );
			const rows = Array.from( document.querySelectorAll( '.minn-traf-row' ) );
			const firstPage = rows.find( ( r ) => r.textContent.includes( 'Hello world!' ) );
			return {
				text: ( modal || {} ).textContent || '',
				pageHasViews: !! ( firstPage && firstPage.querySelector( '[title="Pageviews"]' ) ),
				pageFakesVisitors: !! ( firstPage && firstPage.querySelector( '[title="Visitors"]' ) ),
			};
		} );
		t.check( 'Drill-down lists WPCOM top posts', day.text.includes( 'Hello world!' ) && day.text.includes( 'Sample Page' ) );
		t.check( 'Referrers flattened to specific names', day.text.includes( 'Google Search' ) && day.text.includes( 'twitter.com' ) );
		t.check( 'Page rows show views', day.pageHasViews );
		t.check( 'No fabricated 0-visitor number', ! day.pageFakesVisitors );
		t.check( 'Open Jetpack Stats escape hatch offered', day.text.includes( 'Open Jetpack Stats' ) );

		// ←/→ step the drill-down through adjacent days (fixture data is
		// dense, so the previous day always has stats).
		const title1 = await page.evaluate( () => document.querySelector( '.minn-modal-title' ).textContent.trim() );
		await page.keyboard.press( 'ArrowLeft' );
		const stepped = await page.waitForFunction( ( t ) => {
			const el = document.querySelector( '.minn-modal-title' );
			return !! ( el && el.textContent.trim() !== t && document.querySelector( '.minn-traf-day' ) );
		}, title1, { timeout: 20000 } ).then( () => true ).catch( () => false );
		t.check( 'ArrowLeft steps to the previous day', stepped );
		await page.keyboard.press( 'ArrowRight' );
		const steppedBack = await page.waitForFunction( ( t ) => {
			const el = document.querySelector( '.minn-modal-title' );
			return !! ( el && el.textContent.trim() === t && document.querySelector( '.minn-traf-day' ) );
		}, title1, { timeout: 20000 } ).then( () => true ).catch( () => false );
		t.check( 'ArrowRight steps back', steppedBack );
		t.check( 'Header nav chevrons render', await page.evaluate( () => !! document.querySelector( '#minn-traf-prev' ) && !! document.querySelector( '#minn-traf-next' ) ) );
		await page.keyboard.press( 'Escape' );

		t.check( 'Fixture off (write verified)', await setOpt( '' ) );
		const off = await chartState();
		t.check( 'Falls back to the dedicated provider', ! off.sub.includes( 'Jetpack' ) && off.sub.length > 0, off.sub );
	} finally {
		await setOpt( '' ).catch( () => {} );
		if ( jetpackWas && jetpackWas !== 'active' ) await setStatus( 'jetpack/jetpack', 'inactive' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
