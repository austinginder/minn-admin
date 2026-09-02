/**
 * JetBooking bookings adapter. Bookings live in {prefix}jet_apartment_bookings
 * (WP-local naive day epochs for check-in / check-out, emitted as UTC ISO
 * after the site-offset shift); status writes go through
 * jet_abaf()->db->update_booking so the plugin's own status hook fires.
 *
 * JetBooking stays ACTIVE as a bookings-family resident (fourth after
 * Amelia, LatePoint and Bookly), configured against the mu-fixture
 * minn_rental post type. The suite seeds two disposable bookings through
 * insert_booking (pending + cancelled) on a rental of its own ("Minn Suite
 * Cabin": JetBooking refuses overlapping dates on an instance without
 * units, so the suite must not share the demo rentals), tagged by a
 * suite-only email, and deletes them after.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );
const WP_PATH = process.env.MINN_TEST_WP || path.resolve( __dirname, '../../../..' );
const evalPhp = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-jet-booking-${ process.pid }.php` );
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
const CLEANUP = `if ( ! function_exists( 'jet_abaf' ) ) return;
	global $wpdb; $t = $wpdb->prefix . 'jet_apartment_bookings';
	foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT booking_id FROM {$t} WHERE user_email LIKE %s", 'suite-%@jet-booking.minn.test' ) ) as $id ) {
		jet_abaf()->db->delete_booking( array( 'booking_id' => (int) $id ) );
	}`;

( async () => {
	const t = reporter( 'jet-booking' );
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
			`if ( ! function_exists( 'jet_abaf' ) ) { echo '{"error":"inactive"}'; return; }
			 ${ CLEANUP }
			 $cabin = get_posts( array( 'post_type' => 'minn_rental', 'title' => 'Minn Suite Cabin', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
			 $cabin = $cabin ? (int) $cabin[0] : (int) wp_insert_post( array( 'post_type' => 'minn_rental', 'post_title' => 'Minn Suite Cabin', 'post_status' => 'publish' ) );
			 $day = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
			 $pending = jet_abaf()->db->insert_booking( array( 'apartment_id' => $cabin, 'status' => 'pending', 'check_in_date' => $day + 2 * DAY_IN_SECONDS, 'check_out_date' => $day + 5 * DAY_IN_SECONDS, 'user_email' => 'suite-priya@jet-booking.minn.test' ) );
			 $canceled = jet_abaf()->db->insert_booking( array( 'apartment_id' => $cabin, 'status' => 'cancelled', 'check_in_date' => $day - 3 * DAY_IN_SECONDS, 'check_out_date' => $day - DAY_IN_SECONDS, 'user_email' => 'suite-sam@jet-booking.minn.test' ) );
			 echo wp_json_encode( array( 'pending' => (int) $pending, 'canceled' => (int) $canceled, 'cabin' => $cabin ) );`
		);
		try { seed = JSON.parse( ( seedOut.match( /\{.*\}/ ) || [ '{}' ] )[ 0 ] ); } catch ( e ) { seed = {}; }
		t.check( 'pending + cancelled suite bookings seeded through insert_booking', seed.pending > 0 && seed.canceled > 0, seedOut.slice( 0, 160 ) );

		const list = await api( 'minn-admin/v1/jet-booking/bookings' );
		t.check( 'upcoming list answers', list.status === 200 && ( list.body.total || 0 ) >= 1, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
		const priya = ( list.body.items || [] ).find( ( i ) => i.id === seed.pending );
		t.check( 'upcoming includes the pending suite row with a UTC check-in, nights and pill status',
			!! priya && priya.status === 'pending' && /Z$/.test( priya.date || '' ) && /Minn Suite Cabin · 3 nights/.test( priya.service || '' ) && /suite-priya/.test( priya.customer || '' ),
			JSON.stringify( priya ) );
		// The stored check-in is local midnight + the plugin's one-second
		// bookkeeping offset; the ISO must be the plain midnight in UTC terms.
		t.check( 'check-in ISO is the local midnight (offset shifted, bookkeeping second dropped)',
			!! priya && /T\d{2}:00:00Z$/.test( priya.date || '' ), priya && priya.date );

		const pending = await api( 'minn-admin/v1/jet-booking/bookings?range=pending' );
		t.check( 'pending filter narrows to in-progress rows',
			pending.status === 200 && ( pending.body.items || [] ).every( ( i ) => [ 'pending', 'processing', 'on-hold' ].includes( i.status ) )
			&& ( pending.body.items || [] ).some( ( i ) => i.id === seed.pending ),
			JSON.stringify( { total: pending.body && pending.body.total } ) );

		const canceled = await api( 'minn-admin/v1/jet-booking/bookings?range=canceled' );
		t.check( 'canceled filter shows the cancelled suite row',
			canceled.status === 200 && ( canceled.body.items || [] ).some( ( i ) => i.id === seed.canceled ),
			JSON.stringify( { total: canceled.body && canceled.body.total } ) );

		const search = await api( 'minn-admin/v1/jet-booking/bookings?range=all&search=' + encodeURIComponent( 'suite-priya@jet-booking' ) );
		t.check( 'search matches the guest email',
			search.status === 200 && search.body.total >= 1 && ( search.body.items || [] ).every( ( i ) => /suite-priya/.test( i.customer ) ),
			JSON.stringify( { total: search.body && search.body.total } ) );

		const view = await api( `minn-admin/v1/jet-booking/bookings/${ seed.pending }` );
		t.check( 'detail is a booking card with Customer + Booking sections and the JetBooking link',
			view.status === 200 && view.body.kind === 'booking'
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Customer' && s.rows.some( ( r ) => r.label === 'Email' && /suite-priya/.test( r.value ) ) )
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Booking' && s.rows.some( ( r ) => r.label === 'Nights' && r.value === '3' ) )
			&& /page=jet-abaf-bookings/.test( view.body.adminUrl || '' ),
			JSON.stringify( ( view.body.sections || [] ).map( ( s ) => s.title ) ) );

		const bad = await api( `minn-admin/v1/jet-booking/bookings/${ seed.pending }/status`, { method: 'POST', body: { status: 'bogus' } } );
		t.check( 'an unknown status is refused with 400', bad.status === 400, String( bad.status ) );

		const mark = await api( `minn-admin/v1/jet-booking/bookings/${ seed.pending }/status`, { method: 'POST', body: { status: 'completed' } } );
		const stored = evalPhp( `global $wpdb; echo $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}jet_apartment_bookings WHERE booking_id = %d", ${ seed.pending } ) );` );
		t.check( 'mark completed goes through JetBooking and flips the stored status',
			mark.status === 200 && mark.body && mark.body.ok && stored === 'completed',
			JSON.stringify( { mark: mark.status, body: mark.body, stored } ) );

		const st = await api( 'minn-admin/v1/jet-booking/status' );
		t.check( 'status card carries today, pending, next and Open JetBooking',
			st.status === 200
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Today' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Pending' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Next' )
			&& ( st.body.actions || [] ).some( ( a ) => /Open JetBooking/.test( a.label ) && /page=jet-abaf-bookings/.test( a.href || '' ) ),
			JSON.stringify( st.body && { rows: ( st.body.rows || [] ).map( ( r ) => r.label ), actions: st.body.actions } ) );
		const ch = st.body && st.body.chart;
		t.check( 'status card carries a Next 14 days chart with the seeded row on its day',
			!! ch && ch.title === 'Next 14 days' && Array.isArray( ch.points ) && ch.points.length === 14
			&& ch.points.every( ( pt ) => /^\d{4}-\d{2}-\d{2}$/.test( pt.label ) )
			&& ch.points.some( ( pt ) => pt.value > 0 ),
			JSON.stringify( ch ) );

		const del = await api( `minn-admin/v1/jet-booking/bookings/${ seed.canceled }`, { method: 'DELETE' } );
		const gone = evalPhp( `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jet_apartment_bookings WHERE booking_id = %d", ${ seed.canceled } ) );` );
		t.check( 'delete removes the row through JetBooking', del.status === 200 && gone === '0', JSON.stringify( { del: del.status, gone } ) );

		let painted = false;
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				await page.goto( `${ BASE }/minn-admin/jet-booking`, { waitUntil: 'domcontentloaded', timeout: 45000 } );
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
		t.check( 'family switcher offers JetBooking beside the other bookings plugins', await page.evaluate( () => {
			const sw = document.querySelector( '#minn-surface-switch' );
			if ( ! sw ) return ( window.MINN.surfaces || [] ).filter( ( s ) => s.family === 'bookings' ).length < 2;
			const input = sw.querySelector( '.minn-ac-input' );
			return !!( input && /JetBooking/i.test( input.value || '' ) );
		} ) );
		// The Upcoming view lists the completed suite row (completed is a valid status).
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) => /suite-priya/.test( r.textContent || '' ) );
			if ( row ) row.setAttribute( 'data-bkopen', '1' );
		} );
		t.check( 'the suite booking is on the Upcoming list', !! await page.$( '[data-bkopen]' ) );
		if ( await page.$( '[data-bkopen]' ) ) {
			await page.click( '[data-bkopen]' );
			await page.waitForSelector( '.minn-booking-page .minn-order-customer', { timeout: 20000 } );
			t.check( 'booking opens as an order-style page with a readable date', await page.evaluate( () => {
				const p = document.querySelector( '.minn-booking-page' );
				const head = ( p && p.querySelector( '.minn-order-page-head' ) || {} ).textContent || '';
				return !!( p && /Customer/.test( p.textContent ) && /Booking/.test( p.textContent )
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
