/**
 * Connect Matomo (wp-piwik) traffic provider — the suite runs a FAKE MATOMO
 * SERVER (a tiny Node HTTP server on 127.0.0.1) and the mu-fixture
 * minn_test_connect_matomo, holding that server's URL, points the plugin's
 * settings at it. The adapter's gates, Connect Matomo's request queue, its
 * bulk transport (real curl from PHP) and JSON decode, and Minn's mapping all
 * run for real; only the far end is canned. The fake server deliberately
 * lives OUTSIDE FrankenPHP: served by the site itself it deadlocked the
 * worker pool (every request held a worker while waiting for another to
 * answer its loopback). Connect Matomo rests installed-inactive; the suite
 * activates it and restores. Koko stays active (the fixture's priority-11
 * reset lets the 12-priority adapter answer, the Jetpack fixture convention).
 */
const http = require( 'http' );
const querystring = require( 'querystring' );
const { BASE, launch, login, reporter } = require( './helpers' );

const TOKEN = 'minnfixturetoken';

// One canned answer per Matomo API method, in the shapes Matomo's JSON
// renderer emits: date-keyed maps for period=day (an empty day is []), flat
// row lists otherwise, row metadata (the page URL) flattened into the row.
const answer = ( q ) => {
	const method = q.method || '';
	const m = /^last(\d+)$/.exec( q.date || '' );
	const n = m ? parseInt( m[ 1 ], 10 ) : 60;
	switch ( method ) {
		case 'VisitsSummary.get':
		case 'Actions.get': {
			const out = {};
			for ( let i = n - 1; i >= 0; i-- ) {
				const d = new Date( Date.now() - i * 86400000 ).toISOString().slice( 0, 10 );
				if ( i === 3 ) { out[ d ] = []; continue; } // a day with no visits
				out[ d ] = method === 'Actions.get'
					? { nb_pageviews: 90 + i, nb_uniq_pageviews: 70 + i }
					: { nb_uniq_visitors: 30 + ( i % 7 ), nb_visits: 40 + ( i % 5 ) };
			}
			return out;
		}
		case 'Actions.getPageUrls':
			return [
				{ label: '/hello-world/', nb_visits: 34, nb_hits: 51, url: BASE + '/hello-world/' },
				{ label: '/sample-page/', nb_visits: 21, nb_hits: 27, url: BASE + '/sample-page/' },
			];
		case 'Referrers.getAll':
			return [
				{ label: 'Direct Entry', referer_type: 1, nb_visits: 40 },
				{ label: 'Google', referer_type: 2, nb_visits: 18 },
				{ label: 'twitter.com', referer_type: 3, nb_visits: 7 },
			];
		case 'UserCountry.getCountry':
			return [ { label: 'United States', nb_visits: 20 }, { label: 'Germany', nb_visits: 9 } ];
		case 'DevicesDetection.getType':
			return [ { label: 'Desktop', nb_visits: 22 }, { label: 'Smartphone', nb_visits: 8 }, { label: 'Tablet', nb_visits: 0 } ];
		case 'Actions.getSiteSearchKeywords':
			return [ { label: 'minn', nb_visits: 3 } ];
	}
	return { result: 'error', message: 'fixture: unknown method ' + method };
};

const fakeMatomo = () => new Promise( ( resolve ) => {
	const seen = [];
	const server = http.createServer( ( req, res ) => {
		let body = '';
		req.on( 'data', ( c ) => { body += c; } );
		req.on( 'end', () => {
			const p = querystring.parse( body );
			seen.push( p );
			res.setHeader( 'Content-Type', 'application/json' );
			// A wrong token gets Matomo's own error shape, which proves the
			// plugin's stored token rides every call.
			if ( p.method !== 'API.getBulkRequest' || p.token_auth !== TOKEN ) {
				res.end( JSON.stringify( { result: 'error', message: 'fixture: bad request or token' } ) );
				return;
			}
			const urls = Object.keys( p ).filter( ( k ) => /^urls\[\d+\]$/.test( k ) ).sort( ( a, b ) => parseInt( a.slice( 5 ), 10 ) - parseInt( b.slice( 5 ), 10 ) );
			res.end( JSON.stringify( urls.map( ( k ) => answer( querystring.parse( String( p[ k ] ) ) ) ) ) );
		} );
	} );
	server.listen( 0, '127.0.0.1', () => resolve( { server, seen, url: `http://127.0.0.1:${ server.address().port }/` } ) );
} );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'connect-matomo-traffic' );
	const fake = await fakeMatomo();

	await login( page );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_connect_matomo: val } ),
				} ).catch( () => null );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ).catch( () => null );
				if ( ! r || ! r.ok ) return null;
				return ( await r.json() ).minn_test_connect_matomo;
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

	let piwikWas = null;
	try {
		const plugins = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,name,status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		} );
		const piwik = plugins.find( ( p ) => p.plugin === 'wp-piwik/wp-piwik' );
		t.check( 'Connect Matomo installed', !! piwik, piwik && piwik.status );
		if ( ! piwik ) throw new Error( 'wp-piwik not installed on this site' );
		piwikWas = piwik.status;
		if ( piwikWas !== 'active' ) await setStatus( piwik.plugin, 'active' );

		t.check( 'Fixture on (write verified)', await setOpt( fake.url ), fake.url );

		const on = await chartState();
		t.check( 'Chart source reads Matomo', on.sub.includes( 'Matomo' ), on.sub );
		t.check( 'Traffic bars render', on.cols > 0, `cols=${ on.cols }` );
		t.check( 'Plugin called the Matomo server with its token as one bulk request', fake.seen.some( ( p ) => p.method === 'API.getBulkRequest' && p.token_auth === TOKEN && 'urls[1]' in p ), `calls=${ fake.seen.length }` );

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
				pageHasVisitors: !! ( firstPage && firstPage.querySelector( '[title="Visitors"]' ) ),
			};
		} );
		t.check( 'Drill-down lists top pages with real post titles', day.text.includes( 'Hello world!' ) && day.text.includes( 'Sample Page' ) );
		t.check( 'Referrers listed', day.text.includes( 'Google' ) && day.text.includes( 'twitter.com' ) );
		t.check( 'Direct traffic row filtered out', ! day.text.includes( 'Direct Entry' ) );
		t.check( 'Page rows show visitors and views', day.pageHasViews && day.pageHasVisitors );
		t.check( 'Open Matomo escape hatch offered', day.text.includes( 'Open Matomo' ) );
		await page.keyboard.press( 'Escape' );

		// Stats page report: the range-wide sections ride the same bulk
		// transport (countries, devices and site searches come from the
		// fake server's canned rows; the zero-visit Tablet row must not show).
		const to = new Date().toISOString().slice( 0, 10 );
		const fromD = new Date( Date.now() - 6 * 86400000 ).toISOString().slice( 0, 10 );
		const rep = await restGet( `minn-admin/v1/stats/report?from=${ fromD }&to=${ to }` );
		const secs = ( rep.body && rep.body.sections ) || [];
		const ids = secs.map( ( s ) => s.id );
		t.check( 'Stats report answers 200 from Matomo', rep.status === 200 && rep.body && rep.body.source === 'Matomo', `status=${ rep.status } source=${ rep.body && rep.body.source }` );
		t.check( 'Report carries pages, referrers, countries, devices, search', [ 'pages', 'referrers', 'countries', 'devices', 'search' ].every( ( id ) => ids.includes( id ) ), ids.join( ',' ) );
		const devices = secs.find( ( s ) => s.id === 'devices' );
		t.check( 'Zero-visit device rows dropped', !! devices && devices.rows.length === 2 && ! devices.rows.some( ( r ) => r.label === 'Tablet' ) );

		t.check( 'Fixture off (write verified)', await setOpt( '' ) );
		const off = await chartState();
		t.check( 'Falls back to the resident provider', ! off.sub.includes( 'Matomo' ) && off.sub.length > 0, off.sub );
	} finally {
		await setOpt( '' ).catch( () => {} );
		if ( piwikWas && piwikWas !== 'active' ) await setStatus( 'wp-piwik/wp-piwik', 'inactive' ).catch( () => {} );
		fake.server.close();
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
