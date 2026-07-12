/**
 * Overview Traffic chart day drill-down: click a bar → top pages (+ referrers)
 * modal, powered by minn_admin_traffic_day. Koko Analytics is the resident
 * provider on the test site.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'traffic-day' );
	const { browser, page, errors } = await launch();
	await login( page );

	try {
		// --- REST: overview carries from/to on traffic bars --------------------
		const overview = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/overview?days=30', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { ok: r.ok, body: await r.json() };
		} );
		t.check( 'overview 200', overview.ok );
		const traffic = overview.body && overview.body.traffic;
		t.check( 'traffic provider present', !!( traffic && traffic.source && Array.isArray( traffic.chart ) ), JSON.stringify( traffic && traffic.source ) );
		t.check( 'source is Koko Analytics', traffic && /Koko/i.test( traffic.source || '' ), traffic && traffic.source );

		const withData = ( traffic.chart || [] ).filter( ( c ) => ( c.value || 0 ) + ( c.views || 0 ) > 0 && c.from && c.to );
		t.check( 'at least one bar has traffic + from/to', withData.length > 0, String( withData.length ) );
		const sample = withData[ withData.length - 1 ] || withData[ 0 ];
		t.check( 'bar from/to are Y-m-d', sample && /^\d{4}-\d{2}-\d{2}$/.test( sample.from ) && /^\d{4}-\d{2}-\d{2}$/.test( sample.to ), sample && `${ sample.from }…${ sample.to }` );

		// Visitors card always names pageviews now.
		const visitorsCard = ( overview.body.stats || [] ).find( ( s ) => s.label === 'Visitors' );
		t.check(
			'Visitors card delta mentions pageviews',
			visitorsCard && /pageview/i.test( visitorsCard.delta || '' ),
			visitorsCard && visitorsCard.delta
		);

		// --- REST: traffic-day payload ----------------------------------------
		const day = await page.evaluate( async ( b ) => {
			const r = await fetch(
				window.MINN.restUrl + `minn-admin/v1/overview/traffic-day?from=${ encodeURIComponent( b.from ) }&to=${ encodeURIComponent( b.to ) }`,
				{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' }
			);
			return { ok: r.ok, status: r.status, body: await r.json() };
		}, sample );
		t.check( 'traffic-day 200', day.ok, String( day.status ) );
		t.check( 'traffic-day names Koko', day.body && /Koko/i.test( day.body.source || '' ), day.body && day.body.source );
		t.check( 'traffic-day returns pages array', day.body && Array.isArray( day.body.pages ) );
		t.check(
			'pages carry title + counts',
			day.body.pages.length === 0 || (
				day.body.pages[ 0 ].title
				&& typeof day.body.pages[ 0 ].visitors === 'number'
				&& typeof day.body.pages[ 0 ].pageviews === 'number'
			),
			day.body.pages[ 0 ] ? JSON.stringify( day.body.pages[ 0 ] ) : '(empty pages — ok if no path breakdown)'
		);
		t.check( 'adminUrl points at Koko', day.body && /koko-analytics/.test( day.body.adminUrl || '' ), day.body && day.body.adminUrl );

		// Bad range rejected.
		const bad = await page.evaluate( async () => {
			const r = await fetch(
				window.MINN.restUrl + 'minn-admin/v1/overview/traffic-day?from=2026-01-01&to=2026-03-01',
				{ headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' }
			);
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'range >31 days is refused', bad.status === 400, String( bad.status ) );

		// --- UI: click a traffic bar → modal ----------------------------------
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		// Ensure Traffic (not Activity) is showing.
		await page.waitForSelector( '#minn-chart', { timeout: 15000 } );
		const isTraffic = await page.evaluate( () => {
			const title = document.querySelector( '.minn-panel-title' );
			return title && /Traffic/i.test( title.textContent );
		} );
		if ( ! isTraffic ) {
			const swap = await page.$( '#minn-chart-swap' );
			if ( swap ) {
				await swap.click();
				await page.waitForFunction( () => {
					const title = document.querySelector( '.minn-panel-title' );
					return title && /Traffic/i.test( title.textContent );
				}, null, { timeout: 5000 } );
			}
		}
		t.check( 'Traffic chart is visible', await page.evaluate( () => {
			const title = document.querySelector( '.minn-panel-title' );
			return !!( title && /Traffic/i.test( title.textContent ) );
		} ) );

		// Click the last non-empty column (rightmost with height).
		const clicked = await page.evaluate( () => {
			const cols = [ ...document.querySelectorAll( '#minn-chart .minn-chart-col' ) ];
			// Prefer the last bar with a visible visitor/view bar.
			for ( let i = cols.length - 1; i >= 0; i-- ) {
				const col = cols[ i ];
				const bars = col.querySelectorAll( '.minn-chart-visitors, .minn-chart-views, .minn-chart-bar' );
				const has = [ ...bars ].some( ( b ) => {
					const h = parseFloat( b.style.height ) || 0;
					return h > 0;
				} );
				if ( has ) {
					col.click();
					return i;
				}
			}
			return -1;
		} );
		t.check( 'clicked a traffic bar', clicked >= 0, String( clicked ) );

		await page.waitForSelector( '.minn-modal', { timeout: 10000 } );
		// Wait past the loading state.
		await page.waitForFunction( () => {
			const m = document.querySelector( '.minn-modal' );
			return m && ! m.querySelector( '.minn-loading' );
		}, null, { timeout: 10000 } );

		const modal = await page.evaluate( () => {
			const m = document.querySelector( '.minn-modal' );
			if ( ! m ) return null;
			return {
				title: ( m.querySelector( '.minn-modal-title' ) || {} ).textContent || '',
				count: ( m.querySelector( '.minn-modal-count' ) || {} ).textContent || '',
				hasPages: !! m.querySelector( '.minn-traf-sec-label' ),
				rows: m.querySelectorAll( '.minn-traf-row' ).length,
				foot: ( m.querySelector( '.minn-traf-foot a' ) || {} ).textContent || '',
				empty: ( m.querySelector( '.minn-empty' ) || {} ).textContent || '',
			};
		} );
		t.check( 'modal opened', !! modal );
		t.check(
			'modal shows page breakdown or honest empty',
			modal && ( modal.rows > 0 || /No page breakdown/i.test( modal.empty ) ),
			JSON.stringify( modal )
		);
		if ( modal && modal.rows > 0 ) {
			t.check( 'Top pages section present', modal.hasPages );
		}
		t.check(
			'footer links to Koko when present',
			! modal.foot || /Koko|analytics/i.test( modal.foot ),
			modal.foot || '(no footer — ok if empty state without adminUrl)'
		);

		// Close.
		await page.click( '#minn-modal-close' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), null, { timeout: 5000 } );
		t.check( 'modal closes', true );

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
