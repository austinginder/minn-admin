/**
 * Plausible Analytics traffic provider. The dev fixture mocks only the
 * official plugin settings and Plausible's remote dashboard query. Minn's
 * shared-link validation, request construction, mapping and caching run for
 * real. Plausible rests installed-inactive and Koko remains the resident.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'plausible-traffic' );
	await login( page );

	const setOpt = async ( value ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_plausible: val } ),
				} );
				const response = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await response.json() ).minn_test_plausible;
			}, value );
			if ( stored === value ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const setStatus = ( id, status ) => page.evaluate( async ( a ) => {
		const response = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.id, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { status: a.status } ),
		} );
		if ( ! response.ok ) throw new Error( `plugin toggle failed: ${ response.status }` );
		return ( await response.json() ).status;
	}, { id, status } );

	let originalStatus = null;
	try {
		const plugins = await page.evaluate( async () => {
			const response = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,name,status,version', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return response.json();
		} );
		const plausible = plugins.find( ( plugin ) => plugin.plugin === 'plausible-analytics/plausible-analytics' );
		t.check( 'Plausible 2.6.1 installed', !! plausible && plausible.version === '2.6.1', plausible && `${ plausible.version } ${ plausible.status }` );
		if ( ! plausible ) throw new Error( 'Plausible Analytics is not installed' );
		originalStatus = plausible.status;
		if ( originalStatus !== 'active' ) await setStatus( plausible.plugin, 'active' );

		t.check( 'Fixture on', await setOpt( '1' ) );
		const overview = await page.evaluate( async () => {
			const response = await fetch( window.MINN.restUrl + 'minn-admin/v1/overview?days=30', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { ok: response.ok, body: await response.json() };
		} );
		t.check( 'Overview reads Plausible', overview.ok && overview.body.traffic && overview.body.traffic.source === 'Plausible Analytics', overview.body.traffic && overview.body.traffic.source );
		t.check( 'Traffic chart has data', overview.body.traffic && overview.body.traffic.chart.some( ( bar ) => bar.value > 0 && bar.views > 0 ) );
		t.check( 'Shared-link secret stays server-side', ! JSON.stringify( overview.body ).includes( 'minn-fixture-auth' ) && ! JSON.stringify( overview.body ).includes( 'plausible-plugin-fixture' ) );

		const bar = overview.body.traffic.chart.filter( ( item ) => item.value > 0 && item.from && item.to ).pop();
		const detail = await page.evaluate( async ( item ) => {
			const response = await fetch(
				window.MINN.restUrl + `minn-admin/v1/overview/traffic-day?from=${ encodeURIComponent( item.from ) }&to=${ encodeURIComponent( item.to ) }`,
				{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' }
			);
			return { ok: response.ok, body: await response.json() };
		}, bar );
		t.check( 'Day detail reads Plausible', detail.ok && detail.body.source === 'Plausible Analytics' );
		t.check( 'Top pages mapped', detail.body.pages.some( ( row ) => row.path === '/hello-world/' && row.visitors === 44 && row.pageviews === 96 ) );
		t.check( 'Sources mapped without direct traffic', detail.body.referrers.some( ( row ) => row.label === 'Google' ) && ! detail.body.referrers.some( ( row ) => row.label === 'Direct / None' ) );
		t.check( 'Dashboard escape hatch is local', /wp-admin\/index\.php\?page=plausible_analytics_statistics/.test( detail.body.adminUrl || '' ), detail.body.adminUrl );
		t.check( 'Detail also keeps secrets private', ! JSON.stringify( detail.body ).includes( 'minn-fixture-auth' ) );

		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => {
			const sub = document.querySelector( '.minn-panel-sub' );
			return sub && sub.textContent.includes( 'Plausible Analytics' );
		}, null, { timeout: 20000 } );
		t.check( 'Overview UI names Plausible', true );

		t.check( 'Fixture off', await setOpt( '' ) );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-chart', { timeout: 20000 } );
		const fallback = await page.evaluate( () => ( document.querySelector( '.minn-panel-sub' ) || {} ).textContent || '' );
		t.check( 'Falls back to the resident provider', fallback.length > 0 && ! fallback.includes( 'Plausible' ), fallback );
	} finally {
		await setOpt( '' ).catch( () => {} );
		if ( originalStatus && originalStatus !== 'active' ) {
			await setStatus( 'plausible-analytics/plausible-analytics', 'inactive' ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
