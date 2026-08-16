/**
 * OttoKit (SureTriggers) outgoing-request log.
 *
 * OttoKit is a connector: workflows and run history live in its cloud, so
 * the only thing Minn surfaces is the local outgoing-request table plus a
 * connection status card. This suite drives that surface end to end.
 *
 * Retry is the interesting one. It calls OttoKit's OWN retry, which really
 * fires wp_remote_post at the stored URL — so the fixture rows point at the
 * local discard port (127.0.0.1:9), which refuses instantly. The retry
 * therefore completes offline and deterministically, and the assertion is
 * the retry ACCOUNTING (their attempt counter and status flip), never
 * delivery.
 *
 * OttoKit rests installed-inactive on the dev site (nothing else in the
 * automation family yet, but the same one-provider-at-a-time convention);
 * the suite activates it and restores that in finally.
 */
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const { launch, login, reporter, BASE } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wp = ( args ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } ${ args } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();

( async () => {
	const t = reporter( 'ottokit' );
	let wasActive = false;
	try {
		execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active suretriggers`, { stdio: 'ignore', timeout: 30000 } );
		wasActive = true;
	} catch ( e ) {
		wasActive = false;
	}
	if ( ! wasActive ) wp( 'plugin activate suretriggers' );

	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( route, init ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route + ( a.route.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( a.init && a.init.method ) || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: a.init && a.init.body ? a.init.body : undefined,
		} );
		let body = null;
		try { body = await r.json(); } catch ( e ) { body = null; }
		return { status: r.status, body };
	}, { route, init } );

	// Fixture rows: one-shot init seeder, idempotent by URL marker.
	const seed = async () => {
		wp( 'option update minn_test_seed_ottokit 1' );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 500 );
	};
	const rowByMarker = ( marker ) => {
		const out = wp( `eval "global \\$wpdb; \\$t = \\$wpdb->prefix . 'suretriggers_webhook_requests'; echo wp_json_encode( \\$wpdb->get_row( \\$wpdb->prepare( 'SELECT id, status, response_code, retry_attempts FROM ' . \\$t . ' WHERE request_url = %s', 'http://127.0.0.1:9/${ marker }' ), ARRAY_A ) );"` );
		try { return JSON.parse( out ); } catch ( e ) { return null; }
	};

	try {
		await seed();

		const list = await api( 'minn-admin/v1/ottokit/requests' );
		t.check( 'request log answers', list.status === 200, String( list.status ) );
		t.check( 'fixture rows are listed',
			!! list.body && list.body.total >= 3 && Array.isArray( list.body.items ),
			JSON.stringify( list.body && { total: list.body.total, n: ( list.body.items || [] ).length } ) );

		const items = ( list.body && list.body.items ) || [];
		const shape = items[ 0 ] || {};
		t.check( 'rows carry endpoint, status, code and a date',
			'request_url' in shape && 'status' in shape && 'response_code' in shape && 'created_at' in shape,
			JSON.stringify( shape ) );
		// created_at is current_time('mysql') — site-local, emitted raw, so
		// it must NOT carry a zone marker the client would read as UTC.
		t.check( 'dates are emitted raw as site-local',
			! /[Zz]$|[+-]\d\d:\d\d$/.test( String( shape.created_at || '' ) ), String( shape.created_at ) );

		const failed = await api( 'minn-admin/v1/ottokit/requests?status=failed' );
		const okOnly = await api( 'minn-admin/v1/ottokit/requests?status=success' );
		t.check( 'status tabs filter',
			failed.body && okOnly.body
				&& ( failed.body.items || [] ).every( ( i ) => i.status === 'failed' )
				&& ( okOnly.body.items || [] ).every( ( i ) => i.status === 'success' )
				&& failed.body.total >= 2 && okOnly.body.total >= 1,
			`failed=${ failed.body && failed.body.total } success=${ okOnly.body && okOnly.body.total }` );

		const search = await api( 'minn-admin/v1/ottokit/requests?search=form-submitted' );
		t.check( 'search matches the endpoint',
			search.body && search.body.total >= 1 && ( search.body.items || [] ).some( ( i ) => i.request_url.includes( 'form-submitted' ) ),
			JSON.stringify( search.body && search.body.total ) );

		// Detail: sections, with the JSON payload decoded and shown as code.
		const target = rowByMarker( 'minn-fixture-form-submitted' );
		t.check( 'fixture row resolves through WP-CLI', !! target && target.id, JSON.stringify( target ) );
		const view = await api( `minn-admin/v1/ottokit/requests/${ target.id }/view` );
		const sections = ( view.body && view.body.sections ) || [];
		t.check( 'detail renders sections', view.status === 200 && sections.length >= 2, `${ view.status } / ${ sections.length }` );
		const labels = sections.flatMap( ( s ) => ( s.rows || [] ).map( ( r ) => r.label ) );
		t.check( 'detail names the endpoint and status',
			labels.includes( 'Endpoint' ) && labels.includes( 'Status' ), labels.join( ',' ) );
		// The Error row is CONDITIONAL on the request having recorded
		// error_info, and this fixture's failed rows record none (status
		// failed, response_code 0, error_info empty), so there is nothing
		// here to assert it against — the old check demanded the label from a
		// SUCCESSFUL request, which asked the detail to invent a row. Seeding
		// a request with real error text would make the conditional row
		// testable; until then it stays honestly uncovered.
		const payload = sections.find( ( s ) => s.title === 'Payload' );
		t.check( 'payload is decoded and shown as code',
			!! payload && payload.rows[ 0 ].type === 'code' && /wordpress_webhook_uuid/.test( payload.rows[ 0 ].value ),
			payload ? String( payload.rows[ 0 ].value ).slice( 0, 60 ) : 'missing' );

		// Retry runs THEIR flow: the attempt counter moves and the status is
		// re-derived from a real (refused) response.
		const before = rowByMarker( 'minn-fixture-form-submitted' );
		const retry = await api( `minn-admin/v1/ottokit/requests/${ before.id }/retry`, { method: 'POST' } );
		t.check( 'retry answers', retry.status === 200 && retry.body && retry.body.ok === true, `${ retry.status } ${ JSON.stringify( retry.body ) }` );
		const after = rowByMarker( 'minn-fixture-form-submitted' );
		t.check( 'retry bumps OttoKit\'s own attempt counter',
			!! after && Number( after.retry_attempts ) === Number( before.retry_attempts ) + 1,
			`${ before.retry_attempts } → ${ after && after.retry_attempts }` );
		t.check( 'retry re-derives the status from the real response',
			!! after && after.status === 'failed' && Number( after.response_code ) === 0,
			JSON.stringify( after ) );

		const missing = await api( 'minn-admin/v1/ottokit/requests/99999999/retry', { method: 'POST' } );
		t.check( 'retrying a vanished request 404s', missing.status === 404, String( missing.status ) );

		// Status card: connection state, counts, honest link-outs.
		const status = await api( 'minn-admin/v1/ottokit/status' );
		const rows = ( status.body && status.body.rows ) || [];
		t.check( 'status card lists account and counts',
			status.status === 200 && rows.some( ( r ) => r.label === 'Account' ) && rows.some( ( r ) => r.label === 'Outgoing requests' ),
			JSON.stringify( rows.map( ( r ) => r.label ) ) );
		t.check( 'unconnected site says so plainly',
			rows.some( ( r ) => r.label === 'Account' && /Not connected|Connected/.test( String( r.value ) ) ),
			JSON.stringify( rows[ 0 ] ) );
		t.check( 'workflows and history stay link-outs',
			( ( status.body && status.body.actions ) || [] ).every( ( a ) => !! a.href ),
			JSON.stringify( ( status.body && status.body.actions ) || [] ) );

		// The surface renders in the app, under Tools.
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-nav-tools', { timeout: 20000 } );
		const inNav = await page.evaluate( () => {
			const grp = document.querySelector( '#minn-navgrp-tools' ) || document;
			return [ ...grp.querySelectorAll( '.minn-nav-btn' ) ].some( ( b ) => /Automation/i.test( b.textContent ) );
		} );
		t.check( 'Automation appears in the Tools group', inNav, String( inNav ) );

		// Editors never reach the log.
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true } );
		const p2 = await ctx2.newPage();
		await p2.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-editor' );
		await p2.fill( '#user_pass', 'minn-editor-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		const editorStatus = await p2.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/ottokit/requests', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.status;
		} );
		t.check( 'editors get 403 from the request log', editorStatus === 403, String( editorStatus ) );
		await ctx2.close();
	} finally {
		if ( ! wasActive ) {
			try { wp( 'plugin deactivate suretriggers' ); } catch ( e ) { /* leave it */ }
		}
	}
	await t.done( browser, errors );
} )();
