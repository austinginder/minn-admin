/**
 * Activity Log family status cards (Axis A): Simple History, WP Activity Log,
 * Stream, Aryo. WSAL is the resident provider; others activate for the run
 * and restore. Asserts REST shape (rows + Open ↗ action) and that WSAL paints
 * a status strip on the surface.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );
const path = require( 'path' );

const WP = path.resolve( __dirname, '../../../../' );
const wp = ( args ) => {
	try {
		return execSync( `wp --path=${ JSON.stringify( WP ) } ${ args }`, {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
			timeout: 90000,
		} );
	} catch ( e ) {
		return ( e.stdout || '' ) + ( e.stderr || '' );
	}
};
const isActive = ( slug ) => {
	try {
		execSync( `wp --path=${ JSON.stringify( WP ) } plugin is-active ${ slug }`, {
			stdio: 'ignore', timeout: 30000,
		} );
		return true;
	} catch ( e ) {
		return false;
	}
};
const pluginInstalled = ( slug ) => {
	const list = wp( 'plugin list --field=name' );
	return list.split( /\r?\n/ ).map( ( s ) => s.trim() ).includes( slug );
};

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'activity-log-status' );
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );

	const api = ( pathSeg ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		} );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = { _raw: text.slice( 0, 200 ) }; }
		return { status: r.status, body };
	}, pathSeg );

	const assertStatusShape = ( label, res ) => {
		const rows = res.body && res.body.rows;
		const actions = res.body && res.body.actions;
		t.check( `${ label } status HTTP 200`, res.status === 200, JSON.stringify( { status: res.status, body: res.body } ) );
		t.check( `${ label } status has ≥3 rows`, Array.isArray( rows ) && rows.length >= 3,
			JSON.stringify( rows && rows.map( ( r ) => r.label ) ) );
		t.check( `${ label } rows are display-ready`, Array.isArray( rows ) && rows.every( ( r ) => r.label && String( r.value ).length ),
			JSON.stringify( rows && rows.slice( 0, 2 ) ) );
		t.check( `${ label } offers Open ↗`, Array.isArray( actions ) && actions.some( ( a ) => a.href && /Open/i.test( a.label || '' ) ),
			JSON.stringify( actions ) );
	};

	// --- WSAL (resident active) -----------------------------------------------
	t.check( 'WSAL installed', pluginInstalled( 'wp-security-audit-log' ) );
	if ( pluginInstalled( 'wp-security-audit-log' ) ) {
		if ( ! isActive( 'wp-security-audit-log' ) ) wp( 'plugin activate wp-security-audit-log' );
		const wsal = await api( 'minn-admin/v1/wsal/status' );
		assertStatusShape( 'WSAL', wsal );
		t.check( 'WSAL reports Events (24h)',
			( wsal.body.rows || [] ).some( ( r ) => /24h/i.test( r.label ) ),
			JSON.stringify( wsal.body.rows ) );

		await page.evaluate( () => localStorage.setItem( 'minn-sf-activity-log', 'wp-activity-log' ) );
		await page.goto( BASE + '/minn-admin/wp-activity-log', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status, .minn-table-row', { timeout: 20000 } ).catch( () => null );
		const painted = await page.evaluate( () => {
			const card = document.querySelector( '.minn-surface-status' );
			const text = card ? ( card.textContent || '' ) : '';
			return {
				card: !! card,
				has24h: /Events \(24h\)/i.test( text ),
				hasOpen: /Open WP Activity Log/i.test( text ),
			};
		} );
		t.check( 'WSAL surface paints status strip', painted.card && painted.has24h, JSON.stringify( painted ) );
	}

	// --- Simple History / Stream / Aryo: activate → assert → restore --------
	const optional = [
		{ slug: 'simple-history', route: 'minn-admin/v1/simple-history/status', label: 'Simple History' },
		{ slug: 'stream', route: 'minn-admin/v1/stream/status', label: 'Stream' },
		{ slug: 'aryo-activity-log', route: 'minn-admin/v1/aryo/status', label: 'Aryo' },
	];
	for ( const opt of optional ) {
		if ( ! pluginInstalled( opt.slug ) ) {
			t.check( `${ opt.label } plugin available`, false, 'not installed — skip' );
			continue;
		}
		const was = isActive( opt.slug );
		try {
			if ( ! was ) wp( `plugin activate ${ opt.slug }` );
			// Routes register on rest_api_init for the next request after activate.
			await page.reload( { waitUntil: 'domcontentloaded' } ).catch( () => null );
			const res = await api( opt.route );
			assertStatusShape( opt.label, res );
		} finally {
			if ( ! was ) wp( `plugin deactivate ${ opt.slug }` );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
