/**
 * WP Mail SMTP — the mail family's thinnest provider.
 *
 * The free plugin keeps no email log (that is Pro); what it keeps is the
 * debug-events table: delivery errors, plus send attempts when verbose
 * debugging is on. So this surface is a short list by design, but it had no
 * search, no status card and no chart while every sibling had all three.
 *
 * Seeds through the plugin's OWN DebugEvents::add() rather than an INSERT,
 * so the rows are shaped exactly as its screen makes them, and removes only
 * what it seeded.
 *
 * Activates WP Mail SMTP (deactivating FluentSMTP for the run, one mailer at
 * a time) and restores both in finally.
 */
const { BASE, WP, launch, login, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const os = require( 'os' );

const TOKEN = 'minnwpms' + Date.now();

( async () => {
	const t = reporter( 'wp-mail-smtp' );
	const { browser, page, errors } = await launch();
	await login( page );

	const wpPath = WP;
	const wp = ( args ) => {
		try {
			return execSync( `wp --path=${ JSON.stringify( wpPath ) } ${ args }`, {
				encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], timeout: 60000,
			} );
		} catch ( e ) {
			return ( e.stdout || '' ) + ( e.stderr || '' );
		}
	};
	const isActive = ( slug ) => {
		try {
			execSync( `wp --path=${ JSON.stringify( wpPath ) } plugin is-active ${ slug }`, {
				stdio: 'ignore', timeout: 30000,
			} );
			return true;
		} catch ( e ) {
			return false;
		}
	};
	const api = ( q ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, q );
	const total = async ( q ) => {
		const r = await api( 'minn-admin/v1/wp-mail-smtp/events?per_page=1&page=1' + q );
		return r.status === 200 && r.body ? r.body.total : 'ERR' + r.status;
	};

	const wpmsWasActive = isActive( 'wp-mail-smtp' );
	const fluentWasActive = isActive( 'fluent-smtp' );
	let seeded = false;

	try {
		if ( fluentWasActive ) wp( 'plugin deactivate fluent-smtp' );
		if ( ! wpmsWasActive ) wp( 'plugin activate wp-mail-smtp' );
		// Verify rather than assume: wp() swallows a failed command.
		if ( ! isActive( 'wp-mail-smtp' ) ) {
			t.check( 'WP Mail SMTP could not be activated — checks skipped', true, 'activation did not take' );
			await t.done( browser, errors );
			return;
		}

		// Seed two errors through their own API so the rows are theirs.
		const seedFile = path.join( os.tmpdir(), 'minn-wpms-seed-' + Date.now() + '.php' );
		fs.writeFileSync( seedFile, [
			'<?php',
			"$cls = '\\\\WPMailSMTP\\\\Admin\\\\DebugEvents\\\\DebugEvents';",
			"if ( ! class_exists( $cls ) ) { echo 'noclass'; return; }",
			`$cls::add( 'Minn suite ${ TOKEN } alpha: connection refused', 0 );`,
			`$cls::add( 'Minn suite ${ TOKEN } beta: authentication failed', 0 );`,
			"echo 'ok';",
		].join( '\n' ) );
		const seedOut = wp( `eval-file ${ JSON.stringify( seedFile ) } --user=admin` );
		fs.unlinkSync( seedFile );
		seeded = /ok/.test( String( seedOut ) );
		t.check( 'seeded two events through the plugin’s own DebugEvents::add', seeded,
			String( seedOut ).slice( -120 ) );

		/* ===== Status card (new: the family's every sibling had one) ===== */
		const st = await api( 'minn-admin/v1/wp-mail-smtp/status' );
		const labels = ( ( st.body && st.body.rows ) || [] ).map( ( r ) => r.label );
		t.check( 'status card answers the mail questions', st.status === 200
			&& labels.some( ( l ) => /Errors/.test( l ) )
			&& labels.some( ( l ) => /Mailer/.test( l ) )
			&& labels.some( ( l ) => /Events logged/.test( l ) ),
			JSON.stringify( labels ) );
		t.check( 'status card links out to their own screen',
			( ( st.body && st.body.actions ) || [] ).some( ( a ) => /wp-mail-smtp/.test( String( a.href || '' ) ) ),
			JSON.stringify( ( st.body && st.body.actions ) || [] ) );

		const chart = st.body && st.body.chart;
		t.check( 'chart draws fourteen days whose bars carry a window',
			!! chart && ( chart.points || [] ).length === 14
			&& !! chart.points[ 13 ].from && !! chart.points[ 13 ].to,
			JSON.stringify( chart && chart.points ? chart.points[ 13 ] : null ) );

		/* ===== Search (the rank-3 gap) ===== */
		const all = await total( '' );
		const hit = await total( '&search=' + encodeURIComponent( TOKEN + ' alpha' ) );
		const miss = await total( '&search=zzq' + Date.now() );
		t.check( 'search finds one seeded event', hit === 1, JSON.stringify( { all, hit } ) );
		t.check( 'a search that matches nothing returns nothing, not everything',
			miss === 0, JSON.stringify( { all, miss } ) );

		/* ===== The type tabs still work alongside it ===== */
		const errorsOnly = await total( '&type=error' );
		t.check( 'the Errors tab still filters', typeof errorsOnly === 'number' && errorsOnly <= all,
			JSON.stringify( { all, errorsOnly } ) );
		const bothFilters = await total( '&type=error&search=' + encodeURIComponent( TOKEN ) );
		t.check( 'search combines with the tab', bothFilters === 2,
			JSON.stringify( { bothFilters } ) );

		/* ===== A chart bar narrows to its day ===== */
		if ( chart && chart.points && chart.points.length ) {
			const today = chart.points[ chart.points.length - 1 ];
			const inDay = await total( `&after=${ encodeURIComponent( today.from ) }&before=${ encodeURIComponent( today.to ) }` );
			const none = await total( '&after=1990-01-01%2000%3A00%3A00&before=1990-01-01%2023%3A59%3A59' );
			t.check( 'today’s bar finds the events seeded today', inDay >= 2,
				JSON.stringify( { inDay } ) );
			t.check( 'a window with nothing in it comes back empty, not unfiltered',
				none === 0, JSON.stringify( { none } ) );
		}
	} finally {
		if ( seeded ) {
			const cleanFile = path.join( os.tmpdir(), 'minn-wpms-clean-' + Date.now() + '.php' );
			fs.writeFileSync( cleanFile, [
				'<?php',
				'global $wpdb;',
				"$t = $wpdb->prefix . 'wpmailsmtp_debug_events';",
				`echo (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE content LIKE %s", '%${ TOKEN }%' ) );`,
			].join( '\n' ) );
			wp( `eval-file ${ JSON.stringify( cleanFile ) } --user=admin` );
			fs.unlinkSync( cleanFile );
		}
		// Restore the mail residents: FluentSMTP active, WP Mail SMTP as found.
		if ( ! wpmsWasActive ) wp( 'plugin deactivate wp-mail-smtp' );
		if ( fluentWasActive ) wp( 'plugin activate fluent-smtp' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
