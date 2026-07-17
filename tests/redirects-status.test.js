/**
 * Redirects sibling status cards (v0.18.0): Safe Redirect Manager (CPT +
 * meta), Simple 301 Redirects (option array + wildcard toggle) and EPS 301
 * Redirects (own table with hit counts + 404 log). Each rests
 * installed-inactive (Redirection is the family resident), so the suite
 * activates one at a time, checks its status endpoint and the rendered
 * card, and restores inactive in the finally.
 */
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wp = ( args ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } ${ args } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();

const PLUGINS = [
	{
		slug: 'safe-redirect-manager',
		surface: 'safe-redirect-manager',
		status: 'minn-admin/v1/srm/status',
		firstRow: 'Redirect rules',
	},
	{
		slug: 'simple-301-redirects',
		surface: 'simple-301-redirects',
		status: 'minn-admin/v1/s301/status',
		firstRow: 'Redirect rules',
	},
	{
		slug: 'eps-301-redirects',
		surface: 'eps-301-redirects',
		status: 'minn-admin/v1/eps301/status',
		firstRow: 'Redirect rules',
	},
];

( async () => {
	const t = reporter( 'redirects-status' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( p ) => page.evaluate( async ( pathArg ) => {
		const r = await fetch( window.MINN.restUrl + pathArg + '?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, p );

	const activated = [];
	try {
		for ( const pl of PLUGINS ) {
			let wasActive = true;
			try {
				execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active ${ pl.slug }`, { stdio: 'ignore', timeout: 30000 } );
			} catch ( e ) {
				wasActive = false;
			}
			if ( ! wasActive ) {
				wp( `plugin activate ${ pl.slug }` );
				activated.push( pl.slug );
			}

			const st = await api( pl.status );
			const rows = ( st.body && st.body.rows ) || [];
			t.check( `${ pl.slug }: status endpoint answers with counts`,
				st.status === 200 && rows.some( ( r ) => r.label === pl.firstRow ),
				JSON.stringify( rows ).slice( 0, 120 ) );

			// The surface id is directly routable even while several family
			// members are transiently active.
			await page.goto( `${ BASE }/minn-admin/${ pl.surface }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
			t.check( `${ pl.slug }: card renders above the list`, await page.evaluate( ( label ) =>
				document.querySelector( '.minn-surface-status' ).textContent.includes( label ), pl.firstRow ) );

			if ( ! wasActive ) {
				wp( `plugin deactivate ${ pl.slug }` );
				activated.splice( activated.indexOf( pl.slug ), 1 );
			}
		}
	} finally {
		for ( const slug of activated ) {
			try { wp( `plugin deactivate ${ slug }` ); } catch ( e ) { /* best effort */ }
		}
	}

	await t.done( browser, errors );
} )();
