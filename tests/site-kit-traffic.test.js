/**
 * Site Kit traffic provider — GA data on the Overview chart through Site
 * Kit's own REST module. The minn-dev-fixtures mu-plugin mocks ONLY the
 * Google API response (rest_pre_dispatch at 1000); the adapter's gate,
 * rest_do_request, flattening, mapping and caching all run for real.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'site-kit-traffic' );

	await login( page );

	// Write-then-verify with retries: on this stack a REST settings write
	// can be lost when it races the app's parallel boot requests (observed
	// as a 200 whose row later reads stale). Never trust the status alone.
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_sitekit: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_sitekit;
			}, v );
			if ( stored === v ) return { ok: true, attempt };
			await page.waitForTimeout( 800 );
		}
		return { ok: false, attempt: 5 };
	};

	const chartState = async () => {
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-chart', { timeout: 20000 } );
		await page.waitForTimeout( 500 );
		return page.evaluate( () => ( {
			sub: ( document.querySelector( '.minn-panel-sub' ) || {} ).textContent || '',
			cols: document.querySelectorAll( '.minn-chart-col' ).length,
			visitorsCard: Array.from( document.querySelectorAll( '.minn-stat-label' ) ).some( ( l ) => l.textContent === 'Visitors' ),
		} ) );
	};

	try {
		const onSet = await setOpt( '1' );
		t.check( 'Fixture on (write verified)', onSet.ok, `attempts=${ onSet.attempt }` );
		const on = await chartState();
		t.check( 'Chart source reads Site Kit', on.sub.includes( 'Site Kit' ), on.sub );
		t.check( 'Traffic bars render', on.cols > 0, `cols=${ on.cols }` );
		t.check( 'Visitors stat card present', on.visitorsCard );

		const offSet = await setOpt( '' );
		t.check( 'Fixture off (write verified)', offSet.ok, `attempts=${ offSet.attempt }` );
		const off = await chartState();
		t.check( 'Falls back to the dedicated provider', ! off.sub.includes( 'Site Kit' ) && off.sub.length > 0, off.sub );
		t.check( 'Chart still renders', off.cols > 0 );
	} finally {
		await setOpt( '' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
