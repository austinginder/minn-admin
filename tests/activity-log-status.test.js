/**
 * Activity Log family status cards (Axis A): Simple History, WP Activity Log,
 * Stream, Aryo, plus the security-log providers (All-In-One Security,
 * Wordfence, Solid Security, Limit Login Attempts Reloaded) for the chart
 * half. WSAL and LLA-R are the resident providers; others activate for the
 * run and restore. Asserts REST shape (rows + Open ↗ action), that WSAL
 * paints a status strip on the surface, and, for every provider, the
 * fourteen-day chart and the day narrowing it drives: a window nothing can
 * fall in returns zero, and today's bar equals the list narrowed to today
 * (Simple History excepted: its list folds repeats of one event into one row
 * with an occasions count, so its bars count events and may exceed the rows).
 * The Aryo leg also proves the request-source column + Source filter
 * (Activity Log 2.14) end to end.
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
		return { status: r.status, body, total: r.headers.get( 'X-WP-Total' ) };
	}, pathSeg );

	/* ===== Chart + day narrowing, the same three pieces on every provider =====
	 * The status card charts the last 14 site-local days; each point carries
	 * the from/to a clicked bar sends back through the collection's dateQuery
	 * template; the list route converts those bounds onto whatever clock its
	 * own column uses (UTC datetime, UTC epoch, or Aryo's local epoch). */
	const assertChart = async ( label, statusRoute, listRoute, opts = {} ) => {
		const from = opts.fromParam || 'after';
		const to = opts.toParam || 'before';
		const st = await api( statusRoute );
		const ch = st.body && st.body.chart;
		const pts = ( ch && ch.points ) || [];
		t.check( `${ label } card charts the last 14 days`,
			st.status === 200 && !! ch && pts.length === 14
			&& pts.every( ( p ) => /^\d{4}-\d{2}-\d{2}$/.test( p.label ) && Number.isInteger( p.value ) && p.from && p.to ),
			JSON.stringify( ch && { title: ch.title, primary: ch.primary, secondary: ch.secondary, n: pts.length, last: pts[ pts.length - 1 ] } ) );
		if ( pts.length !== 14 ) return;
		const totalOf = async ( q ) => {
			const r = await api( listRoute + ( listRoute.includes( '?' ) ? '&' : '?' ) + 'per_page=1&page=1' + q );
			if ( r.status !== 200 ) return 'HTTP ' + r.status;
			if ( opts.headerTotal ) return r.total == null ? null : Number( r.total );
			return r.body && r.body.total != null ? Number( r.body.total ) : null;
		};
		const win = ( a, b ) => `&${ from }=${ encodeURIComponent( a ) }&${ to }=${ encodeURIComponent( b ) }`;
		// A window nothing can fall in must come back empty rather than
		// unfiltered: that is the failure an ignored parameter produces.
		const none = await totalOf( win( '1990-01-01 00:00:00', '1990-01-01 23:59:59' ) );
		t.check( `${ label }: a window with nothing in it comes back empty, not unfiltered`,
			none === 0, JSON.stringify( { none } ) );
		// Today's bar is exactly what clicking it narrows the list to (the
		// soft bar is value + secondary), unless the list groups rows. These
		// logs record the suite's own activity (a plugin activation writes
		// rows for a request or two after it lands), so the chart is read
		// before AND after the list call and the pair only counts once the
		// two readings agree: a comparison across a moving log proves nothing.
		const today = pts[ 13 ];
		const barOf = async () => {
			const r = await api( statusRoute );
			const p = ( r.body && r.body.chart && r.body.chart.points || [] )[ 13 ] || {};
			return ( Number( p.value ) || 0 ) + ( Number( p.secondary ) || 0 );
		};
		let barTotal = null;
		let listToday = null;
		for ( let i = 0; i < 5; i++ ) {
			const before = await barOf();
			listToday = await totalOf( win( today.from, today.to ) );
			const after = await barOf();
			if ( before === after ) {
				barTotal = after;
				break;
			}
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
		}
		t.check( opts.grouped
			? `${ label }: today's bar covers at least the rows the list groups today`
			: `${ label }: today's bar equals the list narrowed to today`,
			barTotal !== null && typeof listToday === 'number' && ( opts.grouped ? listToday <= barTotal : listToday === barTotal ),
			JSON.stringify( { barTotal, listToday, today } ) );
		// And the whole window narrows rather than widening: the span is read
		// first, so a row landing between the two calls can only widen "all".
		const span = await totalOf( win( pts[ 0 ].from, pts[ 13 ].to ) );
		const all = await totalOf( '' );
		t.check( `${ label }: the 14-day window narrows rather than widening`,
			typeof span === 'number' && typeof all === 'number' && span <= all, JSON.stringify( { all, span } ) );
		// The collection declares the narrowing so the bars are clickable.
		const desc = await page.evaluate( ( id ) => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === id );
			return s && s.collection ? s.collection.dateQuery : null;
		}, opts.surface || label );
		t.check( `${ label } collection declares dateQuery`, typeof desc === 'string' && desc.includes( '{from}' ) && desc.includes( '{to}' ), JSON.stringify( desc ) );
	};

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
		await assertChart( 'WSAL', 'minn-admin/v1/wsal/status', 'minn-admin/v1/wsal/events', { surface: 'wp-activity-log' } );

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
		{ slug: 'simple-history', route: 'minn-admin/v1/simple-history/status', label: 'Simple History',
			list: 'simple-history/v1/events', surface: 'simple-history',
			// Their events route takes date_from/date_to (strings parsed in
			// the site's zone) and paginates with core headers; the list
			// folds repeated events into one row with an occasions count.
			chart: { fromParam: 'date_from', toParam: 'date_to', headerTotal: true, grouped: true } },
		{ slug: 'stream', route: 'minn-admin/v1/stream/status', label: 'Stream', list: 'minn-admin/v1/stream/records', surface: 'stream' },
		{ slug: 'aryo-activity-log', route: 'minn-admin/v1/aryo/status', label: 'Aryo', list: 'minn-admin/v1/aryo/events', surface: 'aryo-activity-log' },
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
			await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 15000 } ).catch( () => null );
			await assertChart( opt.label, opt.route, opt.list, Object.assign( { surface: opt.surface }, opt.chart || {} ) );

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

	// --- Security-log providers: the same chart on lockout and login logs ----
	// LLA-R is an active resident; the other three activate for the leg and
	// restore (the family convention keeps one security plugin active).
	if ( pluginInstalled( 'limit-login-attempts-reloaded' ) ) {
		const wasLlar = isActive( 'limit-login-attempts-reloaded' );
		try {
			if ( ! wasLlar ) wp( 'plugin activate limit-login-attempts-reloaded' );
			await page.reload( { waitUntil: 'domcontentloaded' } ).catch( () => null );
			await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 15000 } ).catch( () => null );
			await assertChart( 'LLA-R', 'minn-admin/v1/llar/status', 'minn-admin/v1/llar/log', { surface: 'limit-login-attempts' } );
		} finally {
			if ( ! wasLlar ) wp( 'plugin deactivate limit-login-attempts-reloaded' );
		}
	}
	const security = [
		{ slug: 'all-in-one-wp-security-and-firewall', label: 'AIOS', status: 'minn-admin/v1/aios/status', list: 'minn-admin/v1/aios/events', surface: 'all-in-one-security' },
		{ slug: 'wordfence', label: 'Wordfence', status: 'minn-admin/v1/wordfence/status', list: 'minn-admin/v1/wordfence/logins', surface: 'wordfence' },
		{ slug: 'better-wp-security', label: 'Solid Security', status: 'minn-admin/v1/solid-security/status', list: 'minn-admin/v1/solid-security/lockouts', surface: 'solid-security' },
	];
	for ( const sec of security ) {
		if ( ! pluginInstalled( sec.slug ) ) {
			t.check( `${ sec.label } plugin available`, false, 'not installed — skip' );
			continue;
		}
		const was = isActive( sec.slug );
		try {
			if ( ! was ) wp( `plugin activate ${ sec.slug }` );
			await page.reload( { waitUntil: 'domcontentloaded' } ).catch( () => null );
			await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 15000 } ).catch( () => null );
			await assertChart( sec.label, sec.status, sec.list, { surface: sec.surface } );
		} finally {
			if ( ! was ) wp( `plugin deactivate ${ sec.slug }` );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
