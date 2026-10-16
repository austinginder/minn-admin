/**
 * WP Freighter (multi-tenant) coverage: the Tenants sidebar group, the
 * Tenant sites surface, the topbar site switcher fed by tenants, the
 * `follow` action contract (a route that answers a one-time sign-in link),
 * and the create / rename / clone / Minn-toggle / delete loop through
 * WP Freighter's own developer API.
 *
 * Runs against a WP Freighter host, not the minnadmin dev site; on a site
 * without WP Freighter it SKIPs (exit 0, the kadence-designs convention).
 *
 *   MINN_TEST_URL=https://wpfreighter.localhost MINN_TEST_USER=minn-freighter \
 *   MINN_TEST_PASS=… node wp-freighter.test.js
 *
 * The write loop creates exactly one throwaway tenant (a real wp_install
 * under a new table prefix, ~1s) and removes it again through the DELETE
 * route, so the host's own tenant list ends as it began. Domain mapping
 * decides whether a hostname is required; the suite derives one from the test
 * host so a wildcard dev mapping (*.wpfreighter.localhost) resolves it.
 *
 * A tenant left behind is not harmless here. Create and clone start by
 * dropping every table under the new id's prefix, and the main site can sit
 * on a stacked_<n>_ prefix itself (a tenant pulled out to its own install),
 * so a WP Freighter that hands out ids as max + 1 replaces the main site the
 * moment the tenant list reaches n - 1. So the write loop runs only when:
 *   - WP-CLI reaches the host (MINN_TEST_WP, or the Cove site folder named
 *     after the test host beside this one), so the tenant can be removed
 *     however the suite ends: a kill or a crash runs onExit's synchronous
 *     delete, as finally does;
 *   - the id WP Freighter will hand out next has a prefix nothing uses.
 * The removal deletes only a tenant this run's create added, named
 * "Minn suite", on the suite's hostname, never one whose prefix is the main
 * site's. A tenant with those marks that an earlier run left (a SIGKILL
 * skips every hook) is removed first, the same way.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { launch, login, reporter, onExit, BASE, WP } = require( './helpers' );

const HOST = new URL( BASE ).host;
const TENANT_DOMAIN = 'minn-suite.' + HOST;
const SUITE_NAME = /^Minn suite/;

// The host's WordPress root for WP-CLI: MINN_TEST_WP, or the Cove site
// folder named after the test host beside the default site's.
const WP_ROOT = process.env.MINN_TEST_WP || path.resolve( WP, '..', '..', HOST, 'public' );
// PHP rides stdin (eval-file -), so the shell never expands its $vars. It
// answers one JSON line after a marker, so a plugin's notice cannot be read
// as the answer.
const cli = ( php ) => {
	const out = execFileSync( 'wp', [ `--path=${ WP_ROOT }`, 'eval-file', '-' ], { input: '<?php ' + php, encoding: 'utf8', stdio: [ 'pipe', 'pipe', 'ignore' ] } );
	const line = out.split( '\n' ).reverse().find( ( l ) => l.startsWith( 'MINNJSON ' ) );
	return line ? JSON.parse( line.slice( 9 ) ) : null;
};
// WP Freighter's own reading of the main site's prefix (get_primary_prefix).
const PRIMARY = `
	global $wpdb;
	$primary = defined( 'TABLE_PREFIX' ) && 'TABLE_PREFIX' !== TABLE_PREFIX ? TABLE_PREFIX : $wpdb->prefix;
`;

// The host's tenants and whether a create is safe now (see the header).
const PREFLIGHT = PRIMARY + `
	$out = array( 'host' => (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'ok' => false );
	if ( ! class_exists( '\\WPFreighter\\Site' ) || ! method_exists( '\\WPFreighter\\Site', 'next_id' ) ) {
		$out['why'] = 'this WP Freighter has no next_id() prefix check';
		echo 'MINNJSON ' . wp_json_encode( $out ) . "\n";
		return;
	}
	$sites = ( new \\WPFreighter\\Sites() )->get();
	$next  = new ReflectionMethod( '\\WPFreighter\\Site', 'next_id' );
	$next->setAccessible( true );
	$id    = (int) $next->invoke( null, $sites );
	$prefix = "stacked_{$id}_";
	$used   = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
	$out['next']    = $id;
	$out['prefix']  = $primary;
	$out['tenants'] = array_map( function ( $s ) {
		return array( 'id' => (int) $s['stacked_site_id'], 'name' => (string) ( $s['name'] ?? '' ), 'domain' => (string) ( $s['domain'] ?? '' ) );
	}, array_values( (array) $sites ) );
	$out['ok']  = $prefix !== $primary && $prefix !== $wpdb->prefix && ! $used;
	$out['why'] = $out['ok'] ? '' : "the next tenant id {$id} would take {$prefix}, which " . ( $used ? count( $used ) . ' table(s) already use' : 'the main site uses' );
	echo 'MINNJSON ' . wp_json_encode( $out ) . "\n";
`;

// Delete the tenants this run (or an earlier, killed one) made: not in
// keep, named "Minn suite…", on domain, and never the main site's prefix.
const removeSuiteTenants = ( keep, domain ) => cli( PRIMARY + `
	$keep = array_map( 'intval', json_decode( '${ JSON.stringify( keep ) }', true ) );
	$gone = array();
	foreach ( (array) ( new \\WPFreighter\\Sites() )->get() as $s ) {
		$id = (int) $s['stacked_site_id'];
		if ( in_array( $id, $keep, true ) || ! preg_match( '/^Minn suite/', (string) ( $s['name'] ?? '' ) ) || ${ JSON.stringify( domain ) } !== (string) ( $s['domain'] ?? '' ) ) {
			continue;
		}
		if ( "stacked_{$id}_" === $primary || "stacked_{$id}_" === $wpdb->prefix ) {
			$gone[] = "refused {$id}: the main site's prefix";
			continue;
		}
		$gone[] = ( \\WPFreighter\\Site::delete( $id ) ? 'deleted ' : 'could not delete ' ) . $id;
	}
	echo 'MINNJSON ' . wp_json_encode( $gone ) . "\n";
` ) || [];

async function rest( page, method, path, body ) {
	return page.evaluate( async ( [ method, path, body ] ) => {
		const r = await fetch( window.MINN.restUrl + path, {
			method,
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
			credentials: 'same-origin',
		} );
		let data = null;
		try { data = await r.json(); } catch ( e ) { /* no body */ }
		return { status: r.status, data };
	}, [ method, path, body || null ] );
}

( async () => {
	const t = reporter( 'wp-freighter' );
	const { browser, page, errors } = await launch();
	await login( page );

	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 15000 } );
	const surface = await page.evaluate( () => ( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'freighter-sites' ) || null );
	if ( ! surface ) {
		console.log( 'SKIP: no WP Freighter surface on this site (WP Freighter active + manage_options required)' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}

	// Descriptor + boot payload.
	t.check( 'surface lives in the network group', surface.group === 'network', surface.group );
	t.check( 'group is relabelled Tenants', await page.evaluate( () => ( window.MINN.navGroupLabels || {} ).network ) === 'Tenants' );
	t.check( 'sidebar heading reads Tenants', await page.evaluate( () => ( document.querySelector( '[data-navgroup="network"]' ) || {} ).textContent || '' ).then( ( s ) => /Tenants/.test( s ) ) );
	const boot = await page.evaluate( () => ( { sites: window.MINN.sites || [], total: window.MINN.sitesTotal || 0 } ) );
	t.check( 'switcher carries the main site plus tenants', boot.sites.length >= 2 && boot.sites.some( ( s ) => s.id === 'main' ), JSON.stringify( boot.sites.map( ( s ) => s.id ) ) );
	const current = boot.sites.find( ( s ) => s.current );
	const other = boot.sites.find( ( s ) => ! s.current );
	t.check( 'current entry has an app URL and no login route', !! current && !! current.app && ! current.login );
	t.check( 'other entries carry a login route instead of an app URL', !! other && /freighter\/sites\/(main|\d+)\/login$/.test( other.login || '' ) && ! other.app, other && other.login );
	t.check( 'switcher chevron rendered', !! ( await page.$( '#minn-site-switch' ) ) );
	await page.click( '#minn-site-switch' );
	await page.waitForSelector( '#minn-site-switch-results button', { timeout: 5000 } );
	const menu = await page.$$eval( '#minn-site-switch-results button', ( b ) => b.map( ( x ) => x.textContent.trim() ) );
	t.check( 'switcher menu lists every site', menu.length === boot.sites.length, menu.join( ' | ' ) );
	await page.keyboard.press( 'Escape' );

	// The surface page.
	await page.goto( BASE + '/minn-admin/freighter-sites', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-surface-status', { timeout: 15000 } );
	await page.waitForTimeout( 800 );
	const cardText = await page.evaluate( () => document.querySelector( '.minn-surface-status' ).innerText );
	t.check( 'status card names tenants, main site, files mode and domain mapping',
		/TENANTS/i.test( cardText ) && /MAIN SITE/i.test( cardText ) && /FILES/i.test( cardText ) && /DOMAIN MAPPING/i.test( cardText ), cardText.slice( 0, 160 ).replace( /\s+/g, ' ' ) );
	t.check( 'status card links out to WP Freighter', await page.$$eval( '.minn-surface-status a', ( a ) => a.some( ( x ) => /WP Freighter/.test( x.textContent ) && /page=wp-freighter/.test( x.href ) ) ) );
	t.check( 'view switcher offers Tenants and Freighter settings', await page.evaluate( () => {
		const txt = document.querySelector( '.minn-toolbar-views' ) ? document.querySelector( '.minn-toolbar-views' ).innerText : '';
		return /Tenants/.test( txt ) && /Freighter settings/.test( txt );
	} ) );

	// Settings round-trip: read, write the same values back, read again.
	const settings = await rest( page, 'GET', 'minn-admin/v1/freighter/settings/freighter' );
	t.check( 'settings answer files + domain mapping', settings.status === 200 && settings.data && settings.data.values
		&& [ 'shared', 'hybrid', 'dedicated' ].includes( settings.data.values.files ) && typeof settings.data.values.domain_mapping === 'boolean', JSON.stringify( settings.data && settings.data.values ) );
	const mapping = !! ( settings.data && settings.data.values && settings.data.values.domain_mapping );
	const same = await rest( page, 'POST', 'minn-admin/v1/freighter/settings/freighter', { values: settings.data.values } );
	t.check( 'saving unchanged settings is a no-op that re-reads', same.status === 200 && JSON.stringify( same.data.values ) === JSON.stringify( settings.data.values ) );
	const bad = await rest( page, 'POST', 'minn-admin/v1/freighter/settings/freighter', { values: { files: 'nope' } } );
	t.check( 'an unknown files mode is refused', bad.status === 400, String( bad.status ) );

	// Write loop through WP Freighter's own API: one throwaway tenant, gone
	// again however the suite ends.
	let pre = null;
	try {
		pre = fs.existsSync( WP_ROOT ) ? cli( PREFLIGHT ) : null;
	} catch ( e ) { /* reported below */ }
	if ( ! pre || pre.host !== HOST.replace( /:\d+$/, '' ) ) {
		t.check( 'WP-CLI reaches this host, so a killed run can still remove its tenant (set MINN_TEST_WP to its root)', false, WP_ROOT + ( pre ? ' is ' + pre.host : '' ) );
		await t.done( browser, errors );
		return;
	}
	if ( ! pre.ok ) {
		t.check( 'WP Freighter hands out a tenant id whose table prefix nothing uses', false, pre.why + '; update WP Freighter before running this suite' );
		await t.done( browser, errors );
		return;
	}
	const tenantDomain = mapping ? TENANT_DOMAIN : '';
	const leftover = pre.tenants.filter( ( s ) => SUITE_NAME.test( s.name ) && s.domain === tenantDomain );
	if ( leftover.length ) {
		console.log( 'NOTE  an earlier run left ' + leftover.map( ( s ) => '#' + s.id ).join( ', ' ) + ': ' + removeSuiteTenants( pre.tenants.filter( ( s ) => ! leftover.includes( s ) ).map( ( s ) => s.id ), tenantDomain ).join( ', ' ) );
	}
	const before = pre.tenants.filter( ( s ) => ! leftover.includes( s ) ).map( ( s ) => s.id ).sort( ( a, b ) => a - b );
	const cleanup = onExit( () => {
		const gone = removeSuiteTenants( before, tenantDomain );
		if ( gone.length ) console.log( 'NOTE  removed after the suite: ' + gone.join( ', ' ) );
	} );
	const me = await page.evaluate( () => window.MINN.user || {} );
	const created = await rest( page, 'POST', 'minn-admin/v1/freighter/sites', {
		title: 'Minn suite tenant',
		name: 'Minn suite',
		domain: tenantDomain,
		username: me.login || me.username || 'minn-suite-admin',
		email: me.email || 'minn-suite@example.com',
	} );
	const id = created.data && created.data.id;
	t.check( 'creating a tenant returns its row', created.status === 200 && !! id && created.data.name === 'Minn suite' && created.data.minn === 'inactive', JSON.stringify( created.data ).slice( 0, 200 ) );
	t.check( 'it took the id WP Freighter offered, not one in use', Number( id ) === Number( pre.next ) && ! before.includes( Number( id ) ), `got ${ id }, next ${ pre.next }, in use ${ before.join( ',' ) }` );
	if ( ! id ) {
		cleanup();
		await t.done( browser, errors );
		return;
	}
	try {
		if ( mapping ) {
			const dup = await rest( page, 'POST', 'minn-admin/v1/freighter/sites', { title: 'Dup', username: 'x', email: 'x@example.com', domain: TENANT_DOMAIN } );
			t.check( 'a hostname another tenant uses is refused', dup.status === 400, String( dup.status ) );
		}
		const renamed = await rest( page, 'POST', 'minn-admin/v1/freighter/sites/' + id, { name: 'Minn suite renamed' } );
		t.check( 'rename writes through Site::update', renamed.status === 200 && renamed.data.name === 'Minn suite renamed' );

		// The list and its Minn tab see it.
		await page.goto( BASE + '/minn-admin/freighter-sites', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( 'text=Minn suite renamed', { timeout: 15000 } );
		t.check( 'new tenant appears in the list', true );

		// `follow`: the login route answers a one-time link; Minn is off there
		// so it aims at wp-admin and says so.
		const off = await rest( page, 'POST', 'minn-admin/v1/freighter/sites/' + id + '/login', { to: 'minn' } );
		t.check( 'login route mints a one-time link', off.status === 200 && /captaincore_login_token=/.test( off.data.url || '' ), off.data && off.data.url );
		t.check( 'without Minn the link aims at wp-admin and explains', off.data && off.data.minn === false && /wp-admin/.test( off.data.url ) && /not turned on/.test( off.data.message || '' ) );
		const on = await rest( page, 'POST', 'minn-admin/v1/freighter/sites/' + id + '/minn', { on: true } );
		t.check( 'Turn on Minn Admin flips the tenant row', on.status === 200 && on.data.minn === 'active' && on.data.canOpenMinn === '1' );
		const link = await rest( page, 'POST', 'minn-admin/v1/freighter/sites/' + id + '/login', { to: 'minn' } );
		// redirect_to rides URL-encoded inside the sign-in link.
		t.check( 'with Minn on the link aims at the tenant Minn', link.status === 200 && link.data.minn === true && /minn_admin=1|\/minn-admin\//.test( decodeURIComponent( link.data.url || '' ) ), link.data && link.data.url );

		// Follow it for real: a fresh context (no cookies) lands signed in.
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		const errs2 = [];
		p2.on( 'pageerror', ( e ) => errs2.push( 'pageerror: ' + e.message ) );
		await p2.goto( link.data.url, { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => !! window.MINN, null, { timeout: 30000 } ).catch( () => {} );
		const landed = await p2.evaluate( () => ( { minn: !! window.MINN, site: window.MINN && window.MINN.site && window.MINN.site.name, url: location.href } ) );
		t.check( 'following the link boots Minn on the tenant, signed in', landed.minn && landed.site === 'Minn suite tenant', JSON.stringify( landed ) );
		t.check( 'tenant Minn loads without page errors', errs2.length === 0, errs2.join( ' | ' ) );
		await ctx2.close();

		// The row's menu offers the verbs the gates allow. The toggle above
		// went over REST, so reload the list to read the fresh gates.
		await page.goto( BASE + '/minn-admin/freighter-sites', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( 'text=Minn suite renamed', { timeout: 15000 } );
		await page.waitForTimeout( 500 );
		const row = await page.$( 'text=Minn suite renamed' );
		await row.click( { button: 'right' } );
		await page.waitForTimeout( 500 );
		const verbs = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-new-menu button, .minn-new-menu a' ) ].map( ( x ) => x.textContent.trim() ) );
		t.check( 'row menu offers Open in Minn, Open in WordPress, Clone and Delete',
			[ 'Open in Minn', 'Open in WordPress', 'Clone tenant', 'Delete tenant' ].every( ( v ) => verbs.includes( v ) ) && verbs.includes( 'Turn off Minn Admin' ), verbs.join( ' | ' ) );
		await page.keyboard.press( 'Escape' );
	} finally {
		const del = await rest( page, 'DELETE', 'minn-admin/v1/freighter/sites/' + id );
		t.check( 'delete removes the tenant through Site::delete', del.status === 200 && del.data && del.data.deleted === true, JSON.stringify( del.data ) );
		const gone = await rest( page, 'GET', 'minn-admin/v1/freighter/sites?search=Minn%20suite' );
		t.check( 'deleted tenant is gone from the list', gone.status === 200 && ( gone.data.items || [] ).every( ( r ) => r.id !== id ) );
		cleanup();
		const after = ( ( cli( PREFLIGHT ) || {} ).tenants || [] ).map( ( s ) => s.id ).sort( ( a, b ) => a - b );
		t.check( 'the host\'s tenant list ends as it began', JSON.stringify( after ) === JSON.stringify( before ), `before ${ before.join( ',' ) }, after ${ after.join( ',' ) }` );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( 'FAIL', e ); process.exit( 1 ); } );
