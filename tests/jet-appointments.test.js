/**
 * JetAppointments bookings adapter. Appointments live in
 * {prefix}jet_appointments (WP-local naive epochs for date / slot / slot_end,
 * emitted as UTC ISO after the site-offset shift) with phone and comments
 * in the meta table; status writes go through the plugin's own
 * Appointment_Model so its update hook and excluded-dates bookkeeping run.
 *
 * JetAppointments stays ACTIVE as a bookings-family resident, configured
 * against the mu-fixture minn_service / minn_provider post types. The suite
 * seeds two disposable appointments (pending + cancelled) on the
 * "Consultation" service with "Dana Provider", tagged by a suite-only
 * email, and deletes them after.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );
const WP_PATH = process.env.MINN_TEST_WP || path.resolve( __dirname, '../../../..' );
const evalPhp = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-jet-apb-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		for ( let attempt = 1; attempt <= 4; attempt++ ) {
			try {
				return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } --user=admin 2>/dev/null`, { encoding: 'utf8', timeout: 60000 } ).trim();
			} catch ( e ) {
				if ( attempt === 4 ) return ( e.stdout || '' ).trim();
				execSync( 'sleep 3' );
			}
		}
	} finally {
		try { fs.unlinkSync( file ); } catch ( e ) { /* ignore */ }
	}
	return '';
};
const CLEANUP = `if ( ! class_exists( '\\\\JET_APB\\\\Plugin' ) ) return;
	global $wpdb; $t = $wpdb->prefix . 'jet_appointments';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$t} WHERE user_email LIKE %s", 'suite-%@jet-apb.minn.test' ) ) as $id ) {
		\\JET_APB\\Plugin::instance()->db->delete_appointment( (int) $id );
	}`;

( async () => {
	const t = reporter( 'jet-appointments' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = ( p, opts ) => page.evaluate( async ( [ pathArg, o ] ) => {
		const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( o && o.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: o && o.body ? JSON.stringify( o.body ) : undefined,
		} );
		let body = null;
		try { body = await r.json(); } catch ( e ) { body = null; }
		return { status: r.status, body };
	}, [ p, opts || null ] );
	let seed = {};
	try {
		const seedOut = evalPhp(
			`if ( ! class_exists( '\\\\JET_APB\\\\Resources\\\\Appointment_Model' ) ) { echo '{"error":"inactive"}'; return; }
			 ${ CLEANUP }
			 $find = function ( $type, $title ) { $p = get_posts( array( 'post_type' => $type, 'title' => $title, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) ); return $p ? (int) $p[0] : (int) wp_insert_post( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => 'publish' ) ); };
			 $svc = $find( 'minn_service', 'Consultation' ); $prv = $find( 'minn_provider', 'Dana Provider' );
			 $day = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
			 $mk = function ( $status, $dayoff, $hour, $email, $name ) use ( $svc, $prv, $day ) {
			 	$d = $day + $dayoff * DAY_IN_SECONDS; $slot = $d + $hour * HOUR_IN_SECONDS;
			 	$m = new \\JET_APB\\Resources\\Appointment_Model( array( 'status' => $status, 'service' => $svc, 'provider' => $prv, 'user_name' => $name, 'user_email' => $email, 'date' => $d, 'slot' => $slot, 'slot_end' => $slot + 3600, 'type' => 'single' ) );
			 	$m->set_meta( 'phone', '+15555550123' ); $m->set_meta( 'comments', 'Suite note' );
			 	return (int) $m->save();
			 };
			 $pending = $mk( 'pending', 2, 10, 'suite-priya@jet-apb.minn.test', 'Priya Suite' );
			 $canceled = $mk( 'cancelled', -2, 14, 'suite-sam@jet-apb.minn.test', 'Sam Suite' );
			 echo wp_json_encode( array( 'pending' => $pending, 'canceled' => $canceled ) );`
		);
		try { seed = JSON.parse( ( seedOut.match( /\{.*\}/ ) || [ '{}' ] )[ 0 ] ); } catch ( e ) { seed = {}; }
		t.check( 'pending + cancelled suite appointments seeded through Appointment_Model', seed.pending > 0 && seed.canceled > 0, seedOut.slice( 0, 160 ) );

		const list = await api( 'minn-admin/v1/jet-appointments/appointments' );
		t.check( 'upcoming list answers', list.status === 200 && ( list.body.total || 0 ) >= 1, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
		const priya = ( list.body.items || [] ).find( ( i ) => i.id === seed.pending );
		t.check( 'upcoming includes the pending suite row with a UTC slot, service, provider and pill status',
			!! priya && priya.status === 'pending' && /Z$/.test( priya.date || '' ) && priya.service === 'Consultation' && priya.provider === 'Dana Provider' && /Priya Suite/.test( priya.customer || '' ),
			JSON.stringify( priya ) );

		const pending = await api( 'minn-admin/v1/jet-appointments/appointments?range=pending' );
		t.check( 'pending filter narrows to in-progress rows',
			pending.status === 200 && ( pending.body.items || [] ).every( ( i ) => [ 'pending', 'processing', 'on-hold' ].includes( i.status ) )
			&& ( pending.body.items || [] ).some( ( i ) => i.id === seed.pending ),
			JSON.stringify( { total: pending.body && pending.body.total } ) );

		const canceled = await api( 'minn-admin/v1/jet-appointments/appointments?range=canceled' );
		t.check( 'canceled filter shows the cancelled suite row',
			canceled.status === 200 && ( canceled.body.items || [] ).some( ( i ) => i.id === seed.canceled ),
			JSON.stringify( { total: canceled.body && canceled.body.total } ) );

		const search = await api( 'minn-admin/v1/jet-appointments/appointments?range=all&search=' + encodeURIComponent( 'suite-priya@jet-apb' ) );
		t.check( 'search matches the customer email',
			search.status === 200 && search.body.total >= 1 && ( search.body.items || [] ).every( ( i ) => /Priya Suite/.test( i.customer ) ),
			JSON.stringify( { total: search.body && search.body.total } ) );

		const view = await api( `minn-admin/v1/jet-appointments/appointments/${ seed.pending }` );
		t.check( 'detail is a booking card with phone + comments from the meta table and the JetAppointments link',
			view.status === 200 && view.body.kind === 'booking'
			&& view.body.customer && view.body.customer.phone === '+15555550123'
			&& view.body.booking && view.body.booking.notes === 'Suite note' && view.body.booking.employee === 'Dana Provider'
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Customer' && s.rows.some( ( r ) => r.label === 'Email' && /suite-priya/.test( r.value ) ) )
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Appointment' && s.rows.some( ( r ) => r.label === 'Provider' ) )
			&& /page=jet-apb-appointments/.test( view.body.adminUrl || '' ),
			JSON.stringify( { phone: view.body.customer && view.body.customer.phone, notes: view.body.booking && view.body.booking.notes, sections: ( view.body.sections || [] ).map( ( s ) => s.title ) } ) );

		const bad = await api( `minn-admin/v1/jet-appointments/appointments/${ seed.pending }/status`, { method: 'POST', body: { status: 'bogus' } } );
		t.check( 'an unknown status is refused with 400', bad.status === 400, String( bad.status ) );

		const mark = await api( `minn-admin/v1/jet-appointments/appointments/${ seed.pending }/status`, { method: 'POST', body: { status: 'completed' } } );
		const stored = evalPhp( `global $wpdb; echo $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}jet_appointments WHERE ID = %d", ${ seed.pending } ) );` );
		t.check( 'mark completed goes through Appointment_Model and flips the stored status',
			mark.status === 200 && mark.body && mark.body.ok && stored === 'completed',
			JSON.stringify( { mark: mark.status, body: mark.body, stored } ) );

		const st = await api( 'minn-admin/v1/jet-appointments/status' );
		t.check( 'status card carries today, pending, next and Open JetAppointments',
			st.status === 200
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Today' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Pending' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Next' )
			&& ( st.body.actions || [] ).some( ( a ) => /Open JetAppointments/.test( a.label ) && /page=jet-apb-appointments/.test( a.href || '' ) ),
			JSON.stringify( st.body && { rows: ( st.body.rows || [] ).map( ( r ) => r.label ), actions: st.body.actions } ) );
		const ch = st.body && st.body.chart;
		t.check( 'status card carries a Next 14 days chart with the seeded row on its day',
			!! ch && ch.title === 'Next 14 days' && Array.isArray( ch.points ) && ch.points.length === 14
			&& ch.points.every( ( pt ) => /^\d{4}-\d{2}-\d{2}$/.test( pt.label ) )
			&& ch.points.some( ( pt ) => pt.value > 0 ),
			JSON.stringify( ch ) );

		const del = await api( `minn-admin/v1/jet-appointments/appointments/${ seed.canceled }`, { method: 'DELETE' } );
		const gone = evalPhp( `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jet_appointments WHERE ID = %d", ${ seed.canceled } ) );` );
		t.check( 'delete removes the row through JetAppointments', del.status === 200 && gone === '0', JSON.stringify( { del: del.status, gone } ) );

		let painted = false;
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				await page.goto( `${ BASE }/minn-admin/jet-appointments`, { waitUntil: 'domcontentloaded', timeout: 45000 } );
				await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
				painted = true;
				break;
			} catch ( e ) {
				if ( attempt === 3 ) throw e;
				await page.waitForTimeout( 4000 );
			}
		}
		t.check( 'surface renders its status card', painted && await page.evaluate( () => {
			const el = document.querySelector( '.minn-surface-status' );
			return !!( el && /Today|Pending|Next/.test( el.textContent ) );
		} ) );
		t.check( 'family switcher offers JetAppointments beside the other bookings plugins', await page.evaluate( () => {
			const sw = document.querySelector( '#minn-surface-switch' );
			if ( ! sw ) return ( window.MINN.surfaces || [] ).filter( ( s ) => s.family === 'bookings' ).length < 2;
			const input = sw.querySelector( '.minn-ac-input' );
			return !!( input && /JetAppointments/i.test( input.value || '' ) );
		} ) );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => /Priya Suite/.test( r.textContent || '' ) );
			if ( row ) row.setAttribute( 'data-bkopen', '1' );
		} );
		t.check( 'the suite appointment is on the Upcoming list', !! await page.$( '[data-bkopen]' ) );
		if ( await page.$( '[data-bkopen]' ) ) {
			await page.click( '[data-bkopen]' );
			await page.waitForSelector( '.minn-booking-page .minn-order-customer', { timeout: 20000 } );
			t.check( 'appointment opens as an order-style page with a readable date', await page.evaluate( () => {
				const p = document.querySelector( '.minn-booking-page' );
				const head = ( p && p.querySelector( '.minn-order-page-head' ) || {} ).textContent || '';
				return !!( p && /Customer/.test( p.textContent ) && /Appointment/.test( p.textContent )
					&& /January|February|March|April|May|June|July|August|September|October|November|December/.test( head )
					&& ! /T\d{2}:\d{2}:\d{2}Z/.test( head ) );
			} ) );
			await page.click( '#minn-bk-back' );
			await page.waitForSelector( '.minn-surface-status', { timeout: 15000 } );
		}
	} finally {
		evalPhp( CLEANUP );
		await t.done( browser, errors );
	}
} )();
