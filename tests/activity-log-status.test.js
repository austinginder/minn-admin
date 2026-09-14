/**
 * Activity Log family status cards (Axis A): Simple History, WP Activity Log,
 * Stream, Aryo. WSAL is the resident provider; others activate for the run
 * and restore. Asserts REST shape (rows + Open ↗ action) and that WSAL paints
 * a status strip on the surface. The Aryo leg also proves the request-source
 * column + Source filter (Activity Log 2.14) end to end.
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

			// SH stores date as GMT. Parsing it as site-local made a
			// just-now event read as "5 hours ago" on America/Chicago
			// (the Playground blueprint timezone). Force that offset
			// and require the card to stay in the minute range.
			if ( 'simple-history' === opt.slug ) {
				const snapRaw = wp( `eval "echo 'TZSNAP' . wp_json_encode( array( 'tz' => (string) get_option( 'timezone_string' ), 'off' => get_option( 'gmt_offset' ) ) );"` );
				const snapM = snapRaw.match( /TZSNAP(\{.*\})/ );
				const snap = snapM ? JSON.parse( snapM[ 1 ] ) : { tz: '', off: 0 };
				try {
					wp( 'option update timezone_string America/Chicago' );
					wp( `eval "if ( function_exists( 'SimpleLogger' ) ) { SimpleLogger()->info( 'minn status tz probe' ); }"` );
					const tzRes = await api( opt.route );
					const last = ( tzRes.body.rows || [] ).find( ( r ) => /Last event/i.test( r.label || '' ) );
					const val = last ? String( last.value ) : '';
					t.check( 'Simple History last event is recent under America/Chicago',
						tzRes.status === 200 && /\b(seconds?|minutes?)\b/i.test( val ) && ! /\bhours?\b/i.test( val ),
						val || JSON.stringify( tzRes.body ) );
				} finally {
					if ( snap.tz ) {
						wp( `option update timezone_string ${ JSON.stringify( snap.tz ) }` );
					} else {
						wp( 'option delete timezone_string' );
						wp( `option update gmt_offset ${ JSON.stringify( String( snap.off == null ? 0 : snap.off ) ) }` );
					}
				}
			}

			// Stream's visibility is its own Role Access setting, and Minn
			// reads that setting directly because Stream only registers its
			// view_stream cap filter in wp-admin (its REST-side equivalent
			// sits behind a default-OFF Abilities API flag). Prove that is a
			// DEFERRAL and not a manage_options backstop: take administrator
			// out of Role Access and the same administrator must be refused.
			if ( 'stream' === opt.slug ) {
				try {
					wp( `eval "update_option( 'wp_stream', array( 'general_role_access' => array( 'editor' ) ) );"` );
					const denied = await api( opt.route );
					t.check( 'Stream honors Role Access over the caller being an administrator',
						denied.status === 403, `${ denied.status }` );
				} finally {
					// The option did not exist before (defaults in use).
					wp( `eval "delete_option( 'wp_stream' );"` );
				}
				const restored = await api( opt.route );
				t.check( 'Stream reads again once Role Access is back to its default',
					restored.status === 200, `${ restored.status }` );
			}

			// Activity Log 2.14 records where each change came from
			// (request_source) behind a LAZY migration: the column appears on
			// the first wp-admin load, never on activation. Minn gates the
			// Source column and filter on the column itself, so prove both
			// halves: the surface offers nothing until the column exists, and
			// everything once it does. Their migration is run through their
			// own upgrade steps (with a stale version option cleared first: a
			// version option ahead of the schema makes THEIR inserts fail too).
			if ( 'aryo-activity-log' === opt.slug ) {
				const probeCol = () => /SRCCOL:1/.test( wp( `eval "global \\$wpdb; echo 'SRCCOL:' . ( \\$wpdb->get_var( \\$wpdb->prepare( 'SHOW COLUMNS FROM ' . \\$wpdb->prefix . 'aryo_activity_log LIKE %s', 'request_source' ) ) ? 1 : 0 );"` ) );
				if ( ! probeCol() ) {
					wp( `eval "delete_option( 'activity_log_db_version' ); delete_transient( 'aal_upgrade_failed' ); delete_option( 'aal_manual_db_upgrade' ); AAL_Maintenance::run_upgrade_steps();"` );
				}
				t.check( 'Aryo request_source column present after their migration', probeCol(), '' );
				const SEED = 'minn suite source probe';
				try {
					wp( `eval "aal_insert_log( array( 'action' => 'updated', 'object_type' => 'Options', 'object_name' => '${ SEED }', 'request_source' => 'rest|app:Minn Suite' ) );"` );
					await page.reload( { waitUntil: 'domcontentloaded' } ).catch( () => null );
					await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 15000 } );
					const desc = await page.evaluate( () => {
						const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'aryo-activity-log' );
						const c = s && s.collection ? s.collection : {};
						return {
							filter: c.filter ? c.filter.options.map( ( o ) => o[ 0 ] ) : null,
							cols: ( c.columns || [] ).map( ( x ) => x.key ),
						};
					} );
					t.check( 'Aryo surface declares the Source filter with the plugin\'s own channels',
						!! desc.filter && [ '', 'browser', 'rest', 'cli', 'cron', 'xmlrpc', 'abilities', 'app_password' ].every( ( v ) => desc.filter.includes( v ) ),
						JSON.stringify( desc.filter ) );
					t.check( 'Aryo surface lists a Source column', desc.cols.includes( 'source' ), JSON.stringify( desc.cols ) );

					const q = ( src ) => api( 'minn-admin/v1/aryo/events?search=' + encodeURIComponent( SEED ) + '&source=' + src );
					const rest = await q( 'rest' );
					const row = ( ( rest.body && rest.body.items ) || [] )[ 0 ];
					t.check( 'source=rest finds the seeded REST row labeled with the channel and its Application Password',
						rest.status === 200 && !! row && row.source === 'REST API' && row.app_password === 'Minn Suite',
						JSON.stringify( row || rest.body ) );
					const app = await q( 'app_password' );
					t.check( 'source=app_password matches any Application Password request (their token match)',
						app.status === 200 && ( app.body.items || [] ).some( ( i ) => i.app_password === 'Minn Suite' ), JSON.stringify( app.body ) );
					const cron = await q( 'cron' );
					t.check( 'source=cron excludes it', cron.status === 200 && cron.body.total === 0, JSON.stringify( cron.body ) );
					const browser = await q( 'browser' );
					t.check( 'source=browser excludes it', browser.status === 200 && browser.body.total === 0, JSON.stringify( browser.body ) );

					// The real control on the surface: segmented Source filter
					// narrows the list to the seeded row.
					await page.evaluate( () => localStorage.setItem( 'minn-sf-activity-log', 'aryo-activity-log' ) );
					await page.goto( BASE + '/minn-admin/aryo-activity-log', { waitUntil: 'domcontentloaded' } );
					// Eight sources overflow the chip strip, so the Source filter is
					// the same strict combobox the tab strip becomes: open it and
					// pick the REST entry rather than clicking a chip.
					await page.waitForSelector( '[data-sfiltercombo] .minn-ac-input', { timeout: 20000 } );
					await page.click( '[data-sfiltercombo] .minn-ac-input' );
					await page.waitForSelector( '[data-sfiltercombo] .minn-ac-item[data-acv="rest"]', { timeout: 10000 } );
					await page.click( '[data-sfiltercombo] .minn-ac-item[data-acv="rest"]' );
					await page.waitForFunction( () => {
						const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) );
						return rows.length > 0 && rows.every( ( r ) => /REST API/.test( r.textContent || '' ) );
					}, null, { timeout: 20000 } ).catch( () => null );
					const ui = await page.evaluate( () => {
						const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) );
						return { n: rows.length, allRest: rows.length > 0 && rows.every( ( r ) => /REST API/.test( r.textContent || '' ) ) };
					} );
					t.check( 'Source filter on the surface narrows the list to REST API rows', ui.allRest, JSON.stringify( ui ) );
				} finally {
					wp( `eval "global \\$wpdb; \\$wpdb->query( \\$wpdb->prepare( 'DELETE FROM ' . \\$wpdb->prefix . 'aryo_activity_log WHERE object_name = %s', '${ SEED }' ) );"` );
					await page.evaluate( () => localStorage.setItem( 'minn-sf-activity-log', 'wp-activity-log' ) ).catch( () => null );
				}
			}
		} finally {
			if ( ! was ) wp( `plugin deactivate ${ opt.slug }` );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
