/**
 * Gravity SMTP email log search + source filter.
 *
 * The log collection had status tabs and a chart-day window but no search
 * box, so finding mail to one address meant paging. Search matches their
 * own get_search_clause default (subject / extra / message) plus service,
 * and a Source filter loads origins on demand via filter.route.
 *
 * Seeds one disposable event with a unique address + source in extra
 * (serialized by PHP, never unserialized on the read path), then deletes it.
 */
const { BASE, WP, launch, login, reporter, pickCombo } = require( './helpers' );
const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );

const TOKEN = 'minn-gsmtp-search-' + Date.now();
const EMAIL = TOKEN + '@example.com';
const SOURCE = 'MinnSearchFixture';
const SUBJECT = 'Minn GSMTP search ' + TOKEN;

( async () => {
	const t = reporter( 'gsmtp-search' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( a ) => page.evaluate( async ( q ) => {
		const r = await fetch( window.MINN.restUrl + q.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, q.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = { raw: text }; }
		return { ok: r.ok, status: r.status, body };
	}, typeof a === 'string' ? { path: a } : a );

	const plug = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/gravitysmtp/gravitysmtp?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).status;
	} );
	if ( plug !== 'active' ) {
		await page.evaluate( async () => {
			try {
				await fetch( window.MINN.restUrl + 'wp/v2/plugins/gravitysmtp/gravitysmtp', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
					body: JSON.stringify( { status: 'active' } ),
				} );
			} catch ( e ) { /* drop */ }
		} );
		await page.waitForTimeout( 1200 );
	}

	const seedFile = path.join( require( 'os' ).tmpdir(), 'minn-gsmtp-search-seed.php' );
	fs.writeFileSync( seedFile, [
		'<?php',
		'global $wpdb;',
		"$t = $wpdb->prefix . 'gravitysmtp_events';",
		"if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) { echo wp_json_encode( array( 'ok' => false ) ); exit; }",
		'$extra = serialize( array(',
		"  'to'     => " + JSON.stringify( EMAIL ) + ',',
		"  'from'   => 'noreply@example.com',",
		"  'source' => " + JSON.stringify( SOURCE ) + ',',
		') );',
		'$wpdb->insert( $t, array(',
		"  'date_created' => gmdate( 'Y-m-d H:i:s' ),",
		"  'date_updated' => gmdate( 'Y-m-d H:i:s' ),",
		"  'status'       => 'sent',",
		"  'service'      => 'phpmail',",
		"  'subject'      => " + JSON.stringify( SUBJECT ) + ',',
		"  'message'      => '<p>Searchable body " + TOKEN + "</p>',",
		"  'extra'        => $extra,",
		') );',
		"delete_transient( 'minn_admin_gsmtp_log_sources' );",
		'echo wp_json_encode( array( "ok" => true, "id" => (int) $wpdb->insert_id ) );',
	].join( '\n' ) );
	let seedOut = '';
	try {
		seedOut = execSync(
			`wp --path=${ JSON.stringify( WP ) } --skip-plugins --skip-themes eval-file ${ JSON.stringify( seedFile ) }`,
			{ encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 30000 }
		);
	} catch ( e ) {
		seedOut = ( e.stdout || '' ) + ( e.stderr || '' );
	}
	try { fs.unlinkSync( seedFile ); } catch ( e ) { /* ignore */ }
	let seed = {};
	try {
		const line = String( seedOut ).trim().split( /\r?\n/ ).filter( ( l ) => l.startsWith( '{' ) ).pop();
		seed = JSON.parse( line || '{}' );
	} catch ( e ) {
		seed = {};
	}
	const id = seed.id;
	t.check( 'Seeded a searchable Gravity SMTP event', !! id, JSON.stringify( seed ) );

	const bySubject = await api( 'minn-admin/v1/gravity-smtp/events?search=' + encodeURIComponent( SUBJECT ) );
	t.check( 'Search by subject returns the seeded row',
		bySubject.status === 200 && ( bySubject.body.items || [] ).some( ( it ) => it.id === id && it.subject === SUBJECT ),
		JSON.stringify( { status: bySubject.status, n: ( bySubject.body.items || [] ).length, total: bySubject.body.total } ) );

	const byEmail = await api( 'minn-admin/v1/gravity-smtp/events?search=' + encodeURIComponent( EMAIL ) );
	t.check( 'Search by recipient hits extra without unserializing',
		byEmail.status === 200 && ( byEmail.body.items || [] ).some( ( it ) => it.id === id && String( it.to ).includes( EMAIL ) ),
		JSON.stringify( { status: byEmail.status, items: ( byEmail.body.items || [] ).slice( 0, 2 ) } ) );

	const bySourceQ = await api( 'minn-admin/v1/gravity-smtp/events?search=' + encodeURIComponent( SOURCE ) );
	t.check( 'Search by source name hits extra',
		bySourceQ.status === 200 && ( bySourceQ.body.items || [] ).some( ( it ) => it.id === id && it.source === SOURCE ),
		JSON.stringify( { status: bySourceQ.status, first: ( bySourceQ.body.items || [] )[ 0 ] } ) );

	const byBody = await api( 'minn-admin/v1/gravity-smtp/events?search=' + encodeURIComponent( TOKEN ) );
	t.check( 'Search by body phrase hits message',
		byBody.status === 200 && ( byBody.body.items || [] ).some( ( it ) => it.id === id ),
		JSON.stringify( { status: byBody.status, total: byBody.body.total } ) );

	const miss = await api( 'minn-admin/v1/gravity-smtp/events?search=' + encodeURIComponent( 'zzznomatch-gsmtp-minn' ) );
	t.check( 'Search miss returns an empty list',
		miss.status === 200 && Array.isArray( miss.body.items ) && miss.body.total === 0,
		JSON.stringify( miss.body ) );

	const failed = await api( 'minn-admin/v1/gravity-smtp/events?status=failed&search=' + encodeURIComponent( SUBJECT ) );
	t.check( 'Search ANDs with the Failed tab',
		failed.status === 200 && failed.body.total === 0,
		JSON.stringify( failed.body ) );

	const sourced = await api( 'minn-admin/v1/gravity-smtp/events?source=' + encodeURIComponent( SOURCE ) );
	t.check( 'Source query param narrows to the fixture origin',
		sourced.status === 200 && ( sourced.body.items || [] ).some( ( it ) => it.id === id )
			&& ( sourced.body.items || [] ).every( ( it ) => it.source === SOURCE ),
		JSON.stringify( { status: sourced.status, total: sourced.body.total, sources: ( sourced.body.items || [] ).map( ( it ) => it.source ).slice( 0, 5 ) } ) );

	const origins = await api( 'minn-admin/v1/gravity-smtp/sources' );
	t.check( 'Sources endpoint lists the fixture origin',
		origins.status === 200 && Array.isArray( origins.body )
			&& origins.body.some( ( s ) => s.id === SOURCE ),
		JSON.stringify( origins.body && origins.body.slice( 0, 8 ) ) );

	await page.evaluate( () => localStorage.setItem( 'minn-sf-mail', 'gravity-smtp' ) );
	await page.goto( BASE + '/minn-admin/gravity-smtp', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-surface-search', { timeout: 20000 } );
	t.check( 'Log view exposes a search field', true );

	const headers = await page.$$eval( '.minn-table-head, .minn-th, [class*="head"]', () => {
		const row = document.querySelector( '.minn-table-head' ) || document.querySelector( '.minn-table thead' );
		return row ? row.textContent : document.body.innerText.slice( 0, 200 );
	} ).catch( () => '' );
	const chrome = await page.evaluate( () => document.body.innerText );
	t.check( 'Source and Service columns render',
		/Source/i.test( chrome ) && /Service/i.test( chrome ), headers || chrome.slice( 0, 180 ) );

	await page.fill( '#minn-surface-search', EMAIL );
	await page.waitForFunction( ( want ) => {
		const rows = Array.from( document.querySelectorAll( '.minn-table-row .minn-row-title' ) );
		return rows.length > 0 && rows.every( ( r ) => ( r.textContent || '' ).includes( want ) );
	}, SUBJECT, { timeout: 15000 } ).catch( () => null );
	const found = await page.evaluate( ( want ) => {
		const rows = Array.from( document.querySelectorAll( '.minn-table-row .minn-row-title' ) );
		return {
			n: rows.length,
			ok: rows.length > 0 && rows.every( ( r ) => ( r.textContent || '' ).includes( want ) ),
			first: rows[ 0 ] && rows[ 0 ].textContent,
		};
	}, SUBJECT );
	t.check( 'Toolbar search filters the list to the seeded subject', found.ok, JSON.stringify( found ) );

	const filterUi = await page.evaluate( () => ( {
		combo: !! document.querySelector( '[data-sfiltercombo]' ),
		pills: Array.from( document.querySelectorAll( '[data-sfilter]' ) ).map( ( b ) => b.dataset.sfilter ),
	} ) );
	t.check( 'Source filter renders as pills or a combobox',
		filterUi.combo || filterUi.pills.length > 1,
		JSON.stringify( filterUi ) );

	if ( filterUi.combo ) {
		await pickCombo( page, '[data-sfiltercombo] .minn-ac-input', SOURCE );
		await page.waitForFunction( ( src ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) );
			return rows.length > 0 && rows.every( ( r ) => ( r.textContent || '' ).includes( src ) );
		}, SOURCE, { timeout: 15000 } ).catch( () => null );
		const narrowed = await page.evaluate( ( src ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) );
			return { n: rows.length, ok: rows.length > 0 && rows.every( ( r ) => ( r.textContent || '' ).includes( src ) ) };
		}, SOURCE );
		t.check( 'Source combobox narrows the list', narrowed.ok, JSON.stringify( narrowed ) );
	} else if ( filterUi.pills.includes( SOURCE ) ) {
		await page.click( `[data-sfilter="${ SOURCE }"]` );
		await page.waitForFunction( ( src ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) );
			return rows.length > 0 && rows.every( ( r ) => ( r.textContent || '' ).includes( src ) );
		}, SOURCE, { timeout: 15000 } ).catch( () => null );
		const narrowed = await page.evaluate( ( src ) => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row' ) );
			return { n: rows.length, ok: rows.length > 0 && rows.every( ( r ) => ( r.textContent || '' ).includes( src ) ) };
		}, SOURCE );
		t.check( 'Source pills narrow the list', narrowed.ok, JSON.stringify( narrowed ) );
	} else {
		t.check( 'Source filter includes the fixture origin', false, JSON.stringify( filterUi ) );
	}

	const clears = await page.evaluate( () => ( {
		x: !! ( document.querySelector( '#minn-surface-search-clear' ) && ! document.querySelector( '#minn-surface-search-clear' ).hidden ),
		all: !! ( document.querySelector( '#minn-surface-clear' ) && ! document.querySelector( '#minn-surface-clear' ).hidden ),
	} ) );
	t.check( 'Search × and Clear appear once the list is narrowed', clears.x && clears.all, JSON.stringify( clears ) );

	await page.click( '#minn-surface-search-clear' );
	await page.waitForFunction( () => {
		const box = document.querySelector( '#minn-surface-search' );
		return box && box.value === '';
	}, null, { timeout: 15000 } ).catch( () => null );
	const afterX = await page.evaluate( () => ( {
		q: ( document.querySelector( '#minn-surface-search' ) || {} ).value,
		xHidden: ! document.querySelector( '#minn-surface-search-clear' ) || document.querySelector( '#minn-surface-search-clear' ).hidden,
		clear: !! ( document.querySelector( '#minn-surface-clear' ) && ! document.querySelector( '#minn-surface-clear' ).hidden ),
	} ) );
	t.check( '× empties the search and leaves filters in place',
		afterX.q === '' && afterX.xHidden && afterX.clear, JSON.stringify( afterX ) );

	await page.click( '#minn-surface-clear' );
	await page.waitForFunction( () => {
		const b = document.querySelector( '#minn-surface-clear' );
		const hidden = ! b || b.hidden;
		const allTab = !! document.querySelector( '[data-stab="_all"].active' );
		const combo = document.querySelector( '[data-sfiltercombo] .minn-ac-input' );
		const active = document.querySelector( '[data-sfilter].active' );
		const filterReset = combo
			? ! combo.value || /all/i.test( combo.value )
			: ! active || active.dataset.sfilter === '';
		return hidden && allTab && filterReset;
	}, null, { timeout: 15000 } ).catch( () => null );
	const afterClear = await page.evaluate( () => ( {
		q: ( document.querySelector( '#minn-surface-search' ) || {} ).value,
		clear: !! ( document.querySelector( '#minn-surface-clear' ) && ! document.querySelector( '#minn-surface-clear' ).hidden ),
		allTab: !! document.querySelector( '[data-stab="_all"].active' ),
		filter: ( document.querySelector( '[data-sfiltercombo] .minn-ac-input' ) || {} ).value
			|| ( ( document.querySelector( '[data-sfilter].active' ) || {} ).dataset || {} ).sfilter,
		n: document.querySelectorAll( '.minn-table-row' ).length,
	} ) );
	t.check( 'Clear wipes search, source and the status tab',
		afterClear.q === '' && ! afterClear.clear && afterClear.allTab
			&& ( afterClear.filter === '' || /all/i.test( String( afterClear.filter || '' ) ) )
			&& afterClear.n >= 1,
		JSON.stringify( afterClear ) );

	// Letters typed while a slow search is in flight must survive the
	// toolbar rebuild (the box used to snap back to the committed query).
	await page.route( '**/minn-admin/v1/gravity-smtp/events**', async ( route ) => {
		await new Promise( ( r ) => setTimeout( r, 900 ) );
		await route.continue().catch( () => {} );
	} );
	await page.click( '#minn-surface-search', { clickCount: 3 } );
	await page.keyboard.type( 'hel', { delay: 40 } );
	await page.waitForTimeout( 420 );
	await page.keyboard.type( 'lo', { delay: 40 } );
	await page.waitForFunction( () => {
		const box = document.querySelector( '#minn-surface-search' );
		return box && box.value === 'hello' && document.activeElement === box;
	}, null, { timeout: 15000 } ).catch( () => null );
	const kept = await page.evaluate( () => {
		const box = document.querySelector( '#minn-surface-search' );
		return { v: box && box.value, focused: document.activeElement === box };
	} );
	t.check( 'Typing during a search reload stays in the box',
		kept.v === 'hello' && kept.focused, JSON.stringify( kept ) );
	await page.unroute( '**/minn-admin/v1/gravity-smtp/events**' );
	await page.click( '#minn-surface-search-clear' ).catch( () => {} );

	if ( id ) {
		const del = await api( { path: 'minn-admin/v1/gravity-smtp/events/' + id, opts: { method: 'DELETE' } } );
		const gone = await api( 'minn-admin/v1/gravity-smtp/events/' + id );
		t.check( 'Seeded event is deleted',
			del.status === 200 && del.body && del.body.deleted && gone.status === 404,
			JSON.stringify( { del: del.status, body: del.body, gone: gone.status } ) );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
