/**
 * Burst Statistics traffic-day drill-down: top pages from burst_statistics +
 * referrers from burst_sessions. Deactivates Koko for the run so Burst answers,
 * then restores resting state.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'traffic-day-burst' );
	const { browser, page, errors } = await launch();
	await login( page );

	const pluginPut = async ( slug, status ) => page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + args.slug, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { status: args.status } ),
		} );
		return { ok: r.ok, status: r.status };
	}, { slug, status } );

	let restored = false;
	const restore = async () => {
		if ( restored ) return;
		restored = true;
		await pluginPut( 'burst-statistics/burst', 'inactive' ).catch( () => {} );
		await pluginPut( 'koko-analytics/koko-analytics', 'active' ).catch( () => {} );
	};

	try {
		const on = await pluginPut( 'burst-statistics/burst', 'active' );
		t.check( 'Burst activated', on.ok || on.status === 200, String( on.status ) );
		const off = await pluginPut( 'koko-analytics/koko-analytics', 'inactive' );
		t.check( 'Koko deactivated for the run', off.ok || off.status === 200, String( off.status ) );

		// Seed via REST-exposed fixture option (mu-plugin seeder).
		// One-shot seeder clears itself to '' on init — empty after write is success.
		const seeded = await page.evaluate( async () => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			for ( let i = 0; i < 5; i++ ) {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_burst_day: '1' } ),
				} ).catch( () => {} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				const b = await r.json();
				const v = b.minn_test_seed_burst_day;
				if ( v === '1' || v === 1 || v === '' || v == null ) return true;
				await new Promise( ( res ) => setTimeout( res, 400 ) );
			}
			return false;
		} );
		t.check( 'Burst day fixture armed', seeded );

		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );

		const today = await page.evaluate( () => new Date().toISOString().slice( 0, 10 ) );

		const day = await page.evaluate( async ( d ) => {
			const r = await fetch(
				window.MINN.restUrl + `minn-admin/v1/overview/traffic-day?from=${ d }&to=${ d }`,
				{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' }
			);
			return { ok: r.ok, status: r.status, body: await r.json() };
		}, today );

		t.check( 'traffic-day 200', day.ok, String( day.status ) );
		t.check( 'traffic-day names Burst', day.body && /Burst/i.test( day.body.source || '' ), day.body && day.body.source );
		t.check( 'pages array present', day.body && Array.isArray( day.body.pages ) );
		t.check(
			'fixture homepage in pages',
			day.body && day.body.pages.some( ( p ) => /Homepage|\/$/.test( p.title + ( p.path || '' ) ) ),
			day.body && JSON.stringify( ( day.body.pages || [] ).slice( 0, 3 ) )
		);
		t.check(
			'pages carry counts',
			! day.body.pages.length || (
				typeof day.body.pages[ 0 ].visitors === 'number'
				&& typeof day.body.pages[ 0 ].pageviews === 'number'
			)
		);
		t.check( 'adminUrl points at Burst', day.body && /burst/i.test( day.body.adminUrl || '' ), day.body && day.body.adminUrl );
		t.check( 'referrers array present', day.body && Array.isArray( day.body.referrers ) );
	} finally {
		await restore();
	}

	await t.done( browser, errors );
} )();
