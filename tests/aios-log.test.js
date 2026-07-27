/**
 * All-In-One Security audit-log adapter (v0.18.0, Wave B). AIOS rests
 * installed-inactive (WSAL + LLA-R are the active activity-log residents),
 * so the suite activates it, seeds a couple of audit rows through AIOS's own
 * event API, drives the list / level tab / search / detail (kv-table Details
 * from the JSON blob) and the status card, then restores inactive in the
 * finally. Prefix-scoped reads only; never unserialize the stacktrace column.
 */
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wp = ( args ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } ${ args } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();

( async () => {
	const t = reporter( 'aios-log' );
	const { browser, page, errors } = await launch();
	await login( page );

	let wasActive = true;
	try {
		try {
			execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active all-in-one-wp-security-and-firewall`, { stdio: 'ignore', timeout: 30000 } );
		} catch ( e ) {
			wasActive = false;
		}
		if ( ! wasActive ) wp( 'plugin activate all-in-one-wp-security-and-firewall' );

		// Seed one warning + one info event through AIOS's own recorder so the
		// row shape is exactly what the plugin writes (details JSON included).
		wp( `eval "do_action( 'aiowps_record_event', 'user_login', array( 'user_login' => array( 'user' => 'minn-suite' ) ), 'warning' );"` );
		wp( `eval "do_action( 'aiowps_record_event', 'setting_changed', array( 'setting_changed' => array( 'name' => 'minn_probe' ) ), 'info' );"` );

		const api = ( p ) => page.evaluate( async ( pathArg ) => {
			const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
			return { status: r.status, body: await r.json().catch( () => null ) };
		}, p );

		const list = await api( 'minn-admin/v1/aios/events' );
		t.check( 'events endpoint answers', list.status === 200 && ( list.body.total || 0 ) >= 2, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
		const first = ( list.body.items || [] )[ 0 ];
		t.check( 'list item has message, who, level, UTC date', !! first && first.message && first.username && first.level && /Z$/.test( first.date || '' ), JSON.stringify( first ) );

		const warn = await api( 'minn-admin/v1/aios/events?level=warning' );
		t.check( 'level tab filters to warnings', warn.status === 200 && ( warn.body.items || [] ).every( ( i ) => i.level === 'warning' ) && warn.body.total >= 1, JSON.stringify( { total: warn.body && warn.body.total } ) );

		const search = await api( 'minn-admin/v1/aios/events?search=login' );
		t.check( 'search narrows by event type', search.status === 200 && ( search.body.total || 0 ) >= 1 && ( search.body.total || 0 ) <= list.body.total, JSON.stringify( { total: search.body && search.body.total } ) );

		// Detail: pick a warning row (it carries a details JSON we can read).
		const warnItem = ( warn.body.items || [] )[ 0 ] || first;
		const detail = await api( `minn-admin/v1/aios/events/${ warnItem.id }` );
		const titles = ( detail.body && detail.body.sections || [] ).map( ( s ) => s.title );
		t.check( 'detail returns Event + Context sections', detail.status === 200 && titles.includes( 'Event' ) && titles.includes( 'Context' ), JSON.stringify( titles ) );
		const ctx = ( detail.body.sections || [] ).find( ( s ) => s.title === 'Context' );
		t.check( 'JSON details flatten into scalar Context rows', !! ctx && ctx.rows.length >= 1 && ctx.rows.every( ( r ) => typeof r.value !== 'object' ), JSON.stringify( ctx && ctx.rows ).slice( 0, 120 ) );

		const st = await api( 'minn-admin/v1/aios/status' );
		const rows = ( st.body && st.body.rows ) || [];
		t.check( 'status card carries 24h/all-time/warnings/last rows', st.status === 200
			&& rows.some( ( r ) => /24h/.test( r.label ) ) && rows.some( ( r ) => /all-time/.test( r.label ) ) && rows.some( ( r ) => /Warnings/.test( r.label ) ),
			JSON.stringify( rows.map( ( r ) => r.label ) ) );
		t.check( 'status card carries login-posture rows',
			rows.some( ( r ) => r.label === 'Failed logins (24h)' )
			&& rows.some( ( r ) => r.label === 'Locked out now' )
			&& rows.some( ( r ) => r.label === 'Permanent blocks' ),
			JSON.stringify( rows.map( ( r ) => r.label ) ) );

		// Seed a temporary lockdown + permanent block (doc-range IPs only)
		// through AIOS tables so posture counts are non-zero and deep-links appear.
		// eval-file avoids shell-quoting hell on multi-statement PHP.
		const seedPhp = path.join( __dirname, '.aios-posture-seed.php' );
		const fs = require( 'fs' );
		fs.writeFileSync( seedPhp, `<?php
global $wpdb;
$lock = defined( 'AIOWPSEC_TBL_LOGIN_LOCKOUT' ) ? AIOWPSEC_TBL_LOGIN_LOCKOUT : $wpdb->prefix . 'aiowps_login_lockdown';
$perm = defined( 'AIOWPSEC_TBL_PERM_BLOCK' ) ? AIOWPSEC_TBL_PERM_BLOCK : $wpdb->prefix . 'aiowps_permanent_block';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$lock} WHERE failed_login_ip = %s", '203.0.113.50' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$perm} WHERE blocked_ip = %s", '198.51.100.50' ) );
$wpdb->insert( $lock, array(
	'user_id' => 0, 'user_login' => 'minn-suite',
	'lockdown_date' => current_time( 'mysql' ), 'created' => time(),
	'release_date' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
	'released' => time() + HOUR_IN_SECONDS,
	'failed_login_ip' => '203.0.113.50', 'lock_reason' => 'minn-suite',
	'is_lockout_email_sent' => 0, 'backtrace_log' => '', 'ip_lookup_result' => '',
) );
$wpdb->insert( $perm, array(
	'blocked_ip' => '198.51.100.50', 'block_reason' => 'minn-suite',
	'country_origin' => '', 'blocked_date' => current_time( 'mysql' ),
	'created' => time(), 'unblock' => 0,
) );
do_action( 'aiowps_record_event', 'failed_login', array( 'failed_login' => array( 'username' => 'minn-suite-fail', 'known' => false ) ), 'warning', 'minn-suite-fail' );
` );
		try {
			wp( `eval-file ${ JSON.stringify( seedPhp ) }` );
		} finally {
			try { fs.unlinkSync( seedPhp ); } catch ( e ) { /* ignore */ }
		}

		const st2 = await api( 'minn-admin/v1/aios/status' );
		const rows2 = ( st2.body && st2.body.rows ) || [];
		const locked = rows2.find( ( r ) => r.label === 'Locked out now' );
		const blocks = rows2.find( ( r ) => r.label === 'Permanent blocks' );
		const fails = rows2.find( ( r ) => r.label === 'Failed logins (24h)' );
		t.check( 'locked out now counts the seeded lockdown',
			!! locked && parseInt( String( locked.value ).replace( /\D/g, '' ), 10 ) >= 1,
			JSON.stringify( locked ) );
		t.check( 'permanent blocks counts the seeded block',
			!! blocks && parseInt( String( blocks.value ).replace( /\D/g, '' ), 10 ) >= 1,
			JSON.stringify( blocks ) );
		t.check( 'failed logins 24h is non-zero after seed',
			!! fails && parseInt( String( fails.value ).replace( /\D/g, '' ), 10 ) >= 1,
			JSON.stringify( fails ) );
		const acts = ( st2.body && st2.body.actions ) || [];
		t.check( 'status offers locked-IP and permanent-block deep-links',
			acts.some( ( a ) => /locked IP/i.test( a.label ) && /tab=locked-ip/.test( a.href || '' ) )
			&& acts.some( ( a ) => /permanent block/i.test( a.label ) && /tab=permanent-block/.test( a.href || '' ) ),
			JSON.stringify( acts ) );

		// System health strip gains the AIOS posture row while the plugin is active.
		const sys = await api( 'minn-admin/v1/system' );
		const checks = ( sys.body && sys.body.checks ) || [];
		const aiosCheck = checks.find( ( c ) => c.label === 'All-In-One Security' );
		t.check( 'System health includes AIOS posture row',
			!! aiosCheck && ( aiosCheck.status === 'pass' || aiosCheck.status === 'warn' )
			&& /failed login/i.test( aiosCheck.detail || '' )
			&& /permanent block/i.test( aiosCheck.detail || '' ),
			JSON.stringify( aiosCheck ) );

		// Browser: the surface renders under the activity-log family.
		await page.goto( `${ BASE }/minn-admin/all-in-one-security`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
		t.check( 'surface renders card + a row', await page.evaluate( () =>
			/Events \(24h\)/.test( document.querySelector( '.minn-surface-status' ).textContent )
			&& !! document.querySelector( '.minn-table-row' ) ) );

		// Open a detail modal — the activity-log family renders a contact card.
		await page.click( '.minn-table-row' );
		await page.waitForSelector( '.minn-modal.entry .minn-entry-message', { timeout: 15000 } );
		t.check( 'detail modal renders the activity card with the event', await page.evaluate( () =>
			!! document.querySelector( '.minn-modal.entry .minn-entry-message' ) ) );
		await page.keyboard.press( 'Escape' );

		// Card should name the new posture labels in the UI too.
		t.check( 'surface card shows locked-out / permanent-blocks rows', await page.evaluate( () => {
			const t = document.querySelector( '.minn-surface-status' );
			return t && /Locked out now/.test( t.textContent ) && /Permanent blocks/.test( t.textContent );
		} ) );
	} finally {
		// Drop suite-only lockdown / block rows (doc-range IPs).
		try {
			const cleanPhp = path.join( __dirname, '.aios-posture-clean.php' );
			const fs2 = require( 'fs' );
			fs2.writeFileSync( cleanPhp, `<?php
global $wpdb;
$lock = defined( 'AIOWPSEC_TBL_LOGIN_LOCKOUT' ) ? AIOWPSEC_TBL_LOGIN_LOCKOUT : $wpdb->prefix . 'aiowps_login_lockdown';
$perm = defined( 'AIOWPSEC_TBL_PERM_BLOCK' ) ? AIOWPSEC_TBL_PERM_BLOCK : $wpdb->prefix . 'aiowps_permanent_block';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$lock} WHERE failed_login_ip = %s", '203.0.113.50' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$perm} WHERE blocked_ip = %s", '198.51.100.50' ) );
` );
			try {
				wp( `eval-file ${ JSON.stringify( cleanPhp ) }` );
			} finally {
				try { fs2.unlinkSync( cleanPhp ); } catch ( e2 ) { /* ignore */ }
			}
		} catch ( e ) { /* best-effort */ }
		if ( ! wasActive ) wp( 'plugin deactivate all-in-one-wp-security-and-firewall' );
	}

	await t.done( browser, errors );
} )();
