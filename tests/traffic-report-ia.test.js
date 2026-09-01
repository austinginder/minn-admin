/**
 * Independent Analytics range-wide report (minn_admin_traffic_report):
 * Top pages, Referrers (grouped names, no Direct row), Countries, Cities,
 * Devices and Browsers from the free plugin's tables, plus Campaigns and
 * Link clicks when the Pro build is active and licensed. Deactivates Koko
 * for the run so IA answers, seeds the one-shot day fixture (which now
 * carries every dimension), reads today's report, then restores.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'traffic-report-ia' );
	const { browser, page, errors } = await launch();
	await login( page );

	const pluginPut = ( slug, status ) => page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + args.slug, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { status: args.status } ),
		} );
		return { ok: r.ok, status: r.status };
	}, { slug, status } );
	const iaPlugins = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?search=Independent&per_page=20&_fields=plugin,status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( ( await r.json() ) || [] ).filter( ( p ) => /independent-analytics/i.test( p.plugin || '' ) );
	} );

	let iaSlug = '';
	let iaWas = '';
	let kokoWas = '';
	let restored = false;
	const restore = async () => {
		if ( restored ) return;
		restored = true;
		if ( iaSlug && 'active' !== iaWas ) await pluginPut( iaSlug, 'inactive' ).catch( () => {} );
		if ( 'active' === kokoWas ) await pluginPut( 'koko-analytics/koko-analytics', 'active' ).catch( () => {} );
	};

	try {
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );

		const ia = await iaPlugins();
		const hit = ia.find( ( p ) => p.status === 'active' ) || ia.find( ( p ) => /-pro\//.test( p.plugin ) ) || ia[ 0 ];
		iaSlug = hit ? hit.plugin : 'independent-analytics/iawp';
		iaWas = hit ? hit.status : 'inactive';
		const isPro = /-pro\//.test( iaSlug );
		if ( 'active' !== iaWas ) {
			const on = await pluginPut( iaSlug, 'active' );
			t.check( 'IA activated', on.ok, String( on.status ) + ' ' + iaSlug );
		}
		kokoWas = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/koko-analytics/koko-analytics?_fields=status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.ok ? ( await r.json() ).status : 'missing';
		} );
		if ( 'active' === kokoWas ) {
			const off = await pluginPut( 'koko-analytics/koko-analytics', 'inactive' );
			t.check( 'Koko deactivated for the run', off.ok, String( off.status ) );
		}

		// One-shot seeder clears itself to '' on init: empty after write is success.
		const seeded = await page.evaluate( async () => {
			for ( let i = 0; i < 5; i++ ) {
				const w = await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { minn_test_seed_ia_day: '1' } ),
				} );
				const b = await w.json();
				if ( w.ok && ( b.minn_test_seed_ia_day === '' || b.minn_test_seed_ia_day === '1' ) ) return true;
				await new Promise( ( r ) => setTimeout( r, 800 ) );
			}
			return false;
		} );
		t.check( 'IA day fixture armed', seeded );
		// Give init one request to consume the flag.
		await page.evaluate( () => fetch( window.MINN.restUrl + 'wp/v2/types', { credentials: 'same-origin' } ).then( () => null ) );

		const today = await page.evaluate( () => new Date().toISOString().slice( 0, 10 ) );
		const rep = await page.evaluate( async ( d ) => {
			const r = await fetch( window.MINN.restUrl + `minn-admin/v1/stats/report?from=${ d }&to=${ d }`, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { ok: r.ok, status: r.status, body: await r.json() };
		}, today );
		t.check( 'stats/report 200', rep.ok, String( rep.status ) );
		const body = rep.body || {};
		t.check( 'report names Independent Analytics', /Independent/i.test( body.source || '' ), body.source );
		const ids = ( body.sections || [] ).map( ( s ) => s.id );
		const sec = ( id ) => ( body.sections || [] ).find( ( s ) => s.id === id );
		for ( const id of [ 'pages', 'referrers', 'countries', 'cities', 'devices', 'browsers' ] ) {
			t.check( `section ${ id } present`, ids.includes( id ), ids.join( ',' ) );
		}
		const pages = sec( 'pages' ) || { rows: [] };
		const fixture = pages.rows.find( ( r ) => /Minn IA Fixture/.test( r.label ) );
		t.check( 'fixture page row carries visitors + pageviews', fixture && fixture.visitors >= 1 && fixture.pageviews >= 4, JSON.stringify( fixture ) );
		const refs = sec( 'referrers' ) || { rows: [] };
		t.check( 'referrers use the grouped name', refs.rows.some( ( r ) => r.label === 'Google' && r.sub === 'google.com' ), JSON.stringify( refs.rows.slice( 0, 3 ) ) );
		t.check( 'Direct is not listed as a referrer', ! refs.rows.some( ( r ) => /^direct$/i.test( r.label ) ) );
		const countries = sec( 'countries' ) || { rows: [] };
		t.check( 'countries carry the continent as sub', countries.rows.some( ( r ) => r.label === 'United States' && r.sub === 'North America' ), JSON.stringify( countries.rows.slice( 0, 2 ) ) );
		const cities = sec( 'cities' ) || { rows: [] };
		t.check( 'cities carry the country as sub', cities.rows.some( ( r ) => r.label === 'Lancaster' && r.sub === 'United States' ), JSON.stringify( cities.rows.slice( 0, 2 ) ) );
		t.check( 'devices + browsers name the fixture session', ( sec( 'devices' ) || { rows: [] } ).rows.some( ( r ) => r.label === 'Desktop' ) && ( sec( 'browsers' ) || { rows: [] } ).rows.some( ( r ) => r.label === 'Chrome' ) );
		t.check( 'adminUrl points at IA', /independent/i.test( body.adminUrl || '' ), body.adminUrl );
		t.check( 'at most 8 sections', ids.length <= 8, String( ids.length ) );

		if ( isPro ) {
			// Pro build ACTIVE: the dev fixture is licensed, so campaigns and
			// clicks must show. A lapsed license reads here as a failure on
			// purpose (it means the fixture needs re-activating).
			t.check( 'Pro: campaigns section present', ids.includes( 'campaigns' ), ids.join( ',' ) );
			const camps = sec( 'campaigns' ) || { rows: [] };
			t.check( 'Pro: campaign row = utm_campaign with source / medium', camps.rows.some( ( r ) => r.label === 'minn-fixture' && r.sub === 'newsletter / email' ), JSON.stringify( camps.rows.slice( 0, 2 ) ) );
			const clicks = sec( 'clicks' ) || { rows: [] };
			t.check( 'Pro: link clicks in words on the sub line, target linked', clicks.rows.some( ( r ) => /github\.com/.test( r.label ) && /\d+ clicks?/.test( r.sub ) && r.url && r.pageviews === 0 && r.visitors >= 1 ), JSON.stringify( clicks.rows.slice( 0, 2 ) ) );
		} else {
			t.check( 'free build: no Pro-only sections', ! ids.includes( 'campaigns' ) && ! ids.includes( 'clicks' ), ids.join( ',' ) );
		}

		// The Overview day drill-down shares the helpers.
		const day = await page.evaluate( async ( d ) => {
			const r = await fetch( window.MINN.restUrl + `minn-admin/v1/overview/traffic-day?from=${ d }&to=${ d }`, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		}, today );
		t.check( 'day drill-down referrers use the grouped name too', ( day.referrers || [] ).some( ( r ) => r.label === 'Google' ), JSON.stringify( ( day.referrers || [] ).slice( 0, 3 ) ) );
	} finally {
		await restore();
	}
	await t.done( browser, errors );
} )();
