/**
 * Independent Analytics traffic-day drill-down: top pages from views ×
 * resources. Deactivates Koko for the run so IA answers, then restores.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'traffic-day-ia' );
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
		await pluginPut( 'independent-analytics/iawp', 'inactive' ).catch( () => {} );
		await pluginPut( 'koko-analytics/koko-analytics', 'active' ).catch( () => {} );
	};

	try {
		// Plugin file may be independent-analytics/iawp.php or similar.
		const slug = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?search=Independent&per_page=20&_fields=plugin,status,name', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const list = await r.json();
			const hit = ( list || [] ).find( ( p ) => /independent/i.test( p.plugin || '' ) || /Independent Analytics/i.test( p.name || '' ) );
			return hit ? hit.plugin : 'independent-analytics/iawp';
		} );

		const on = await pluginPut( slug, 'active' );
		t.check( 'IA activated', on.ok || on.status === 200, String( on.status ) + ' ' + slug );
		const off = await pluginPut( 'koko-analytics/koko-analytics', 'inactive' );
		t.check( 'Koko deactivated for the run', off.ok || off.status === 200, String( off.status ) );

		// One-shot seeder clears itself to '' on init — empty after write is success.
		const seeded = await page.evaluate( async () => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			for ( let i = 0; i < 5; i++ ) {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_ia_day: '1' } ),
				} ).catch( () => {} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				const b = await r.json();
				const v = b.minn_test_seed_ia_day;
				if ( v === '1' || v === 1 || v === '' || v == null ) return true;
				await new Promise( ( res ) => setTimeout( res, 400 ) );
			}
			return false;
		} );
		t.check( 'IA day fixture armed', seeded );

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
		t.check( 'traffic-day names Independent Analytics', day.body && /Independent/i.test( day.body.source || '' ), day.body && day.body.source );
		t.check( 'pages array present', day.body && Array.isArray( day.body.pages ) );
		t.check(
			'fixture page present',
			day.body && day.body.pages.some( ( p ) => /Minn IA Fixture|Homepage|Resource/i.test( p.title || '' ) ),
			day.body && JSON.stringify( ( day.body.pages || [] ).slice( 0, 3 ) )
		);
		t.check( 'adminUrl points at IA', day.body && /independent/i.test( day.body.adminUrl || '' ), day.body && day.body.adminUrl );
	} finally {
		await restore();
	}

	await t.done( browser, errors );
} )();
