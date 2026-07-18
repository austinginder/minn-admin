/**
 * WP Statistics traffic-day drill-down: top pages from statistics_pages +
 * referrers from visitor.referred. Deactivates Koko for the run so WPS is
 * the answering provider, then restores both plugins' resting state.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'traffic-day-wps' );
	const { browser, page, errors } = await launch();
	await login( page );

	const pluginPut = async ( slug, status ) => {
		// wp/v2/plugins/{dir}/{file} — leave the slash unencoded so the route
		// matches; %2F encoding 404s on some stacks.
		return page.evaluate( async ( args ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + args.slug, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { status: args.status } ),
			} );
			return { ok: r.ok, status: r.status, body: await r.json().catch( () => ( {} ) ) };
		}, { slug, status } );
	};

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
			if ( stored === v || ( v === '1' && ( stored === '' || stored == null ) ) ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	let restored = false;
	const restore = async () => {
		if ( restored ) return;
		restored = true;
		await pluginPut( 'wp-statistics/wp-statistics', 'inactive' ).catch( () => {} );
		await pluginPut( 'koko-analytics/koko-analytics', 'active' ).catch( () => {} );
	};

	try {
		// Baseline: WPS on, Koko off so WPS answers both traffic filters.
		const on = await pluginPut( 'wp-statistics/wp-statistics', 'active' );
		t.check( 'WP Statistics activated', on.ok || on.status === 200, JSON.stringify( on.status ) );
		const off = await pluginPut( 'koko-analytics/koko-analytics', 'inactive' );
		t.check( 'Koko deactivated for the run', off.ok || off.status === 200, JSON.stringify( off.status ) );

		const seeded = await setOpt( 'minn_test_seed_wps_pages', '1' );
		t.check( 'WPS pages fixture armed', seeded );

		// Hard reload so boot picks up the new active provider set.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );

		const today = await page.evaluate( () => {
			const d = new Date();
			return d.toISOString().slice( 0, 10 ); // UTC Y-m-d — matches seeder gmdate
		} );

		const day = await page.evaluate( async ( d ) => {
			const r = await fetch(
				window.MINN.restUrl + `minn-admin/v1/overview/traffic-day?from=${ d }&to=${ d }`,
				{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' }
			);
			return { ok: r.ok, status: r.status, body: await r.json() };
		}, today );

		t.check( 'traffic-day 200 under WPS', day.ok, String( day.status ) );
		t.check( 'source is WP Statistics', day.body && /WP Statistics/i.test( day.body.source || '' ), day.body && day.body.source );
		t.check( 'top pages include Homepage', day.body && day.body.pages.some( ( p ) => /Homepage/i.test( p.title ) ), JSON.stringify( ( day.body.pages || [] ).map( ( p ) => p.title ) ) );
		t.check( 'Sample Page resolved from post id', day.body && day.body.pages.some( ( p ) => p.postId === 2 ), JSON.stringify( day.body.pages ) );
		t.check(
			'Homepage leads with fixture hit count',
			day.body && day.body.pages[ 0 ] && day.body.pages[ 0 ].pageviews >= 12,
			day.body && day.body.pages[ 0 ] && String( day.body.pages[ 0 ].pageviews )
		);
		t.check( 'adminUrl points at WPS overview', day.body && /wps_overview_page/.test( day.body.adminUrl || '' ), day.body && day.body.adminUrl );
		t.check(
			'referrers include google host when seeded',
			day.body && Array.isArray( day.body.referrers )
				&& ( day.body.referrers.length === 0 || day.body.referrers.some( ( r ) => /google/i.test( r.label ) ) ),
			JSON.stringify( day.body && day.body.referrers )
		);

		// Overview totals also come from WPS when Koko is off.
		const overview = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/overview?days=7', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).traffic;
		} );
		t.check( 'overview traffic source is WP Statistics', overview && /WP Statistics/i.test( overview.source || '' ), overview && overview.source );

		// UI: open a day with traffic.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-chart', { timeout: 15000 } );
		const isTraffic = await page.evaluate( () => {
			const title = document.querySelector( '.minn-panel-title' );
			return title && /Traffic/i.test( title.textContent );
		} );
		if ( ! isTraffic ) {
			const swap = await page.$( '#minn-chart-swap' );
			if ( swap ) await swap.click();
			await page.waitForFunction( () => {
				const title = document.querySelector( '.minn-panel-title' );
				return title && /Traffic/i.test( title.textContent );
			}, null, { timeout: 5000 } ).catch( () => null );
		}
		const sourceBadge = await page.evaluate( () => ( document.querySelector( '.minn-panel-sub' ) || {} ).textContent || '' );
		t.check( 'chart badge says WP Statistics', /WP Statistics/i.test( sourceBadge ), sourceBadge );

		// Click the most recent column that has a bar — the seeded day. The
		// seeder now writes both the UTC and site-local calendar day, so this
		// bar column's drill has aligned pages even in the UTC/local skew
		// window (a run past local midnight used to click a bar day whose
		// drill was empty). Wait for the seed to reach the chart first.
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '#minn-chart .minn-chart-col div' ) ]
				.some( ( b ) => ( parseFloat( b.style.height ) || 0 ) > 0 ),
		null, { timeout: 10000 } );
		await page.evaluate( () => {
			const cols = [ ...document.querySelectorAll( '#minn-chart .minn-chart-col' ) ];
			for ( let i = cols.length - 1; i >= 0; i-- ) {
				const bars = cols[ i ].querySelectorAll( '.minn-chart-visitors, .minn-chart-views, .minn-chart-bar' );
				if ( [ ...bars ].some( ( b ) => ( parseFloat( b.style.height ) || 0 ) > 0 ) ) {
					cols[ i ].click();
					return;
				}
			}
		} );
		await page.waitForSelector( '.minn-modal', { timeout: 10000 } );
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && ! m.querySelector( '.minn-loading' );
		}, null, { timeout: 10000 } );
		const modal = await page.evaluate( () => {
			const m = document.querySelector( '.minn-modal' );
			return {
				title: ( m.querySelector( '.minn-modal-title' ) || {} ).textContent || '',
				rows: m.querySelectorAll( '.minn-traf-row' ).length,
				foot: ( m.querySelector( '.minn-traf-foot a' ) || {} ).textContent || '',
			};
		} );
		t.check( 'modal lists page rows', modal.rows > 0, JSON.stringify( modal ) );
		t.check( 'modal names WP Statistics', /WP Statistics/i.test( modal.title ), modal.title );
		t.check( 'footer links to WP Statistics', /WP Statistics|Statistics/i.test( modal.foot ), modal.foot );

		await restore();
		await t.done( browser, errors );
	} catch ( e ) {
		console.error( e );
		await restore();
		await t.done( browser, errors.concat( [ String( e ) ] ) );
		process.exit( 1 );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
