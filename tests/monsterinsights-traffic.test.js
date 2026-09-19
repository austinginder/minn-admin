/**
 * MonsterInsights + ExactMetrics traffic providers — one adapter, two
 * prefixes. The mu-fixture minn_test_monsterinsights mocks ONLY the Google
 * side (the connected-profile option + the Awesome Motive relay's overview
 * report); the adapter's gates, the plugin's own report class, its report
 * cache and Minn's mapping all run for real. Both plugins rest
 * installed-inactive; the suite activates each in turn and restores. Koko
 * stays active (the fixture's priority-13 reset lets the 14-priority adapter
 * answer, the Jetpack fixture convention).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

const FAMILY = [
	{ id: 'google-analytics-for-wordpress/googleanalytics', name: 'MonsterInsights' },
	{ id: 'google-analytics-dashboard-for-wp/gadwp', name: 'ExactMetrics' },
];

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'monsterinsights-traffic' );

	await login( page );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_monsterinsights: val } ),
				} ).catch( () => null );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ).catch( () => null );
				if ( ! r || ! r.ok ) return null;
				return ( await r.json() ).minn_test_monsterinsights;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	// A plugin toggle can recycle the PHP worker mid-response (the
	// theme-install precedent). On a dropped fetch, wait, then ask the plugin
	// itself for the truth and retry only if it's wrong.
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

	const restGet = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p + ( p.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, path );

	const was = {};
	try {
		const plugins = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,name,status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		} );
		for ( const f of FAMILY ) {
			const p = plugins.find( ( x ) => x.plugin === f.id );
			t.check( `${ f.name } installed`, !! p, p && p.status );
			if ( ! p ) throw new Error( `${ f.id } not installed on this site` );
			was[ f.id ] = p.status;
			// The two share one codebase and must not run together.
			if ( p.status === 'active' ) await setStatus( f.id, 'inactive' );
		}

		for ( const f of FAMILY ) {
			await setStatus( f.id, 'active' );
			t.check( `${ f.name }: fixture on (write verified)`, await setOpt( '1' ) );

			const on = await chartState();
			t.check( `${ f.name }: chart source reads ${ f.name }`, on.sub.includes( f.name ), on.sub );
			t.check( `${ f.name }: traffic bars render`, on.cols > 0, `cols=${ on.cols }` );

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
			t.check( `${ f.name }: chart has a data bar`, dataCi !== null, `ci=${ dataCi }` );
			await page.click( `.minn-chart-col[data-ci="${ dataCi }"]` );
			await page.waitForSelector( '.minn-traf-day, .minn-empty', { timeout: 20000 } );
			const day = await page.evaluate( () => {
				const modal = document.querySelector( '.minn-modal' );
				const rows = Array.from( document.querySelectorAll( '.minn-traf-row' ) );
				const firstPage = rows.find( ( r ) => r.textContent.includes( 'Hello world!' ) );
				return {
					text: ( modal || {} ).textContent || '',
					pageHasViews: !! ( firstPage && firstPage.querySelector( '[title="Pageviews"]' ) ),
					pageHasVisitors: !! ( firstPage && firstPage.querySelector( '[title="Visitors"]' ) ),
				};
			} );
			t.check( `${ f.name }: drill-down lists top pages`, day.text.includes( 'Hello world!' ) && day.text.includes( 'Sample Page' ) );
			t.check( `${ f.name }: referrers listed`, day.text.includes( 'google' ) && day.text.includes( 't.co' ) );
			t.check( `${ f.name }: page rows show visitors and views`, day.pageHasViews && day.pageHasVisitors );
			t.check( `${ f.name}: Open ${ f.name } escape hatch offered`, day.text.includes( `Open ${ f.name }` ) );
			await page.keyboard.press( 'Escape' );

			const to = new Date().toISOString().slice( 0, 10 );
			const fromD = new Date( Date.now() - 6 * 86400000 ).toISOString().slice( 0, 10 );
			const rep = await restGet( `minn-admin/v1/stats/report?from=${ fromD }&to=${ to }` );
			const secs = ( rep.body && rep.body.sections ) || [];
			const ids = secs.map( ( s ) => s.id );
			t.check( `${ f.name }: stats report answers 200`, rep.status === 200 && rep.body && rep.body.source === f.name, `status=${ rep.status } source=${ rep.body && rep.body.source }` );
			t.check( `${ f.name }: report carries pages, referrers, countries, devices`, [ 'pages', 'referrers', 'countries', 'devices' ].every( ( id ) => ids.includes( id ) ), ids.join( ',' ) );
			const countries = secs.find( ( s ) => s.id === 'countries' );
			t.check( `${ f.name }: country codes resolved to names`, !! countries && countries.rows.some( ( r ) => r.label === 'United States' ) && countries.rows.some( ( r ) => r.label === 'Germany' ), countries && countries.rows.map( ( r ) => r.label ).join( ',' ) );
			const devices = secs.find( ( s ) => s.id === 'devices' );
			t.check( `${ f.name }: device percentages became session counts`, !! devices && devices.rows.length === 3 && devices.rows.every( ( r ) => r.visitors > 0 ) );

			t.check( `${ f.name }: fixture off (write verified)`, await setOpt( '' ) );
			const off = await chartState();
			t.check( `${ f.name }: falls back to the resident provider`, ! off.sub.includes( f.name ) && off.sub.length > 0, off.sub );
			await setStatus( f.id, 'inactive' );
		}
	} finally {
		await setOpt( '' ).catch( () => {} );
		for ( const f of FAMILY ) {
			if ( was[ f.id ] ) await setStatus( f.id, was[ f.id ] ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
