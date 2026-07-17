/**
 * Redirection status card (v0.18.0): rule counts, served/404 traffic and
 * the dual-series 14-day chart from Redirection's own log tables. Seeds a
 * few rows into wp_redirection_logs (site-local datetimes, matching the
 * plugin's own writes) so the primary series has data, asserts the card
 * and chart in the browser, and REST-verifies the counts. Seeded rows are
 * cleaned up by URL marker in the finally.
 */
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const MARK = '/minn-suite-redirect-probe';

const db = ( sql ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } db query ${ JSON.stringify( sql ) } --skip-column-names 2>/dev/null`,
	{ encoding: 'utf8' }
).trim();

( async () => {
	const t = reporter( 'redirection-status' );
	const { browser, page, errors } = await launch();

	try {
		t.check( 'Redirection is the active resident', db( "SELECT COUNT(*) FROM wp_options WHERE option_name='active_plugins' AND option_value LIKE '%redirection/redirection.php%'" ) === '1' );

		// Seed 3 served hits today + 1 yesterday (their own site-local stamp).
		db( `INSERT INTO wp_redirection_logs (created, url, sent_to, http_code) VALUES
			(NOW(), '${ MARK }', '/target', 301),
			(NOW(), '${ MARK }', '/target', 301),
			(NOW(), '${ MARK }', '/target', 301),
			(DATE_SUB(NOW(), INTERVAL 1 DAY), '${ MARK }', '/target', 301)` );

		await login( page );
		const status = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/redirection/status?_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'status endpoint answers', status.status === 200 );
		const labels = ( status.body.rows || [] ).map( ( r ) => r.label );
		t.check( 'rows carry rules + traffic facts',
			labels.includes( 'Redirect rules' ) && labels.includes( 'Served, 7 days' ) && labels.includes( '404s, 7 days' ),
			labels.join( ' | ' ) );
		const served = ( status.body.rows.find( ( r ) => r.label === 'Served, 7 days' ) || {} ).value;
		t.check( 'served count includes the seeded hits', parseInt( served, 10 ) >= 4, String( served ) );
		const chart = status.body.chart || {};
		t.check( 'chart is the dual redirects/404s series', chart.primary === 'Redirects' && chart.secondary === '404s' && ( chart.points || [] ).length === 14,
			JSON.stringify( { p: chart.primary, s: chart.secondary, n: ( chart.points || [] ).length } ) );
		const today = chart.points[ chart.points.length - 1 ] || {};
		t.check( 'today\'s point carries the seeded primary hits', ( today.value || 0 ) >= 3, JSON.stringify( today ) );

		await page.goto( `${ BASE }/minn-admin/redirection`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
		const card = await page.evaluate( () => ( {
			text: document.querySelector( '.minn-surface-status' ).textContent,
			bars: document.querySelectorAll( '.minn-surface-status .minn-chart-col' ).length,
			dual: !! document.querySelector( '.minn-surface-status .minn-chart-visitors' ),
		} ) );
		t.check( 'card renders above the list', /Redirect rules/.test( card.text ) && /404s, 7 days/.test( card.text ) );
		t.check( 'chart renders 14 columns', card.bars === 14, String( card.bars ) );
		t.check( 'both series render (stacked bars)', card.dual );
	} finally {
		db( `DELETE FROM wp_redirection_logs WHERE url = '${ MARK }'` );
	}

	await t.done( browser, errors );
} )();
