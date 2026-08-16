/**
 * LatePoint bookings adapter. Appointments live in
 * {prefix}latepoint_bookings with UTC start_datetime_utc; customers,
 * services and agents join from LatePoint's own tables. Status writes
 * go through OsBookingModel::update_status so notifications stay theirs.
 *
 * LatePoint stays ACTIVE as a bookings-family resident alongside Amelia.
 * The suite seeds two disposable bookings (pending + cancelled) against
 * a dedicated customer and deletes them after.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wp = ( args ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } ${ args } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();
const evalPhp = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-latepoint-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		for ( let attempt = 1; attempt <= 4; attempt++ ) {
			try {
				return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 60000 } ).trim();
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

( async () => {
	const t = reporter( 'latepoint' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( p, opts ) => page.evaluate( async ( [ pathArg, o ] ) => {
		const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( o && o.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: o && o.body ? JSON.stringify( o.body ) : undefined,
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ p, opts || null ] );

	let wasActive = true;
	let seed = {};
	try {
		try {
			execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active latepoint`, { stdio: 'ignore', timeout: 30000 } );
		} catch ( e ) {
			wasActive = false;
		}
		if ( ! wasActive ) wp( 'plugin activate latepoint' );

		const seedOut = evalPhp(
			`global $wpdb;
			 if ( ! defined( 'LATEPOINT_VERSION' ) ) { echo '{"error":"inactive"}'; return; }
			 $locations = $wpdb->prefix . 'latepoint_locations';
			 $agents = $wpdb->prefix . 'latepoint_agents';
			 $services = $wpdb->prefix . 'latepoint_services';
			 $customers = $wpdb->prefix . 'latepoint_customers';
			 $bookings = $wpdb->prefix . 'latepoint_bookings';
			 $orders = $wpdb->prefix . 'latepoint_orders';
			 $items = $wpdb->prefix . 'latepoint_order_items';
			 $links = $wpdb->prefix . 'latepoint_agents_services';
			 $location = (int) $wpdb->get_var( "SELECT id FROM {$locations} ORDER BY id ASC LIMIT 1" );
			 if ( ! $location ) {
			 	$loc = new OsLocationModel();
			 	$loc->name = 'Studio';
			 	$loc->status = defined( 'LATEPOINT_LOCATION_STATUS_ACTIVE' ) ? LATEPOINT_LOCATION_STATUS_ACTIVE : 'active';
			 	$loc->save();
			 	$location = (int) $loc->id;
			 }
			 $agent = (int) $wpdb->get_var( "SELECT id FROM {$agents} ORDER BY id ASC LIMIT 1" );
			 if ( ! $agent ) {
			 	$a = new OsAgentModel();
			 	$a->first_name = 'Minn';
			 	$a->last_name = 'Stylist';
			 	$a->email = 'stylist-latepoint@minn.test';
			 	$a->status = defined( 'LATEPOINT_AGENT_STATUS_ACTIVE' ) ? LATEPOINT_AGENT_STATUS_ACTIVE : 'active';
			 	$a->save();
			 	$agent = (int) $a->id;
			 }
			 $service = (int) $wpdb->get_var( "SELECT id FROM {$services} ORDER BY id ASC LIMIT 1" );
			 if ( ! $service ) {
			 	$s = new OsServiceModel();
			 	$s->name = 'Haircut';
			 	$s->duration = 60;
			 	$s->status = defined( 'LATEPOINT_SERVICE_STATUS_ACTIVE' ) ? LATEPOINT_SERVICE_STATUS_ACTIVE : 'active';
			 	$s->save();
			 	$service = (int) $s->id;
			 }
			 if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $links ) ) === $links
			 	&& $agent && $service
			 	&& ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$links} WHERE agent_id = %d AND service_id = %d", $agent, $service ) ) ) {
			 	$wpdb->insert( $links, array( 'agent_id' => $agent, 'service_id' => $service, 'location_id' => $location ) );
			 }
			 $email = 'priya-latepoint-suite@example.com';
			 $cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$customers} WHERE email = %s", $email ) );
			 if ( ! $cid ) {
			 	$c = new OsCustomerModel();
			 	$c->first_name = 'Priya';
			 	$c->last_name = 'Suite';
			 	$c->email = $email;
			 	$c->phone = '+15555550998';
			 	$c->save();
			 	$cid = (int) $c->id;
			 }
			 $old = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_item_id FROM {$bookings} WHERE customer_id = %d AND booking_code LIKE %s", $cid, 'MINNSU%' ) );
			 if ( $old ) {
			 	$bids = array_map( 'intval', wp_list_pluck( $old, 'id' ) );
			 	$iids = array_filter( array_map( 'intval', wp_list_pluck( $old, 'order_item_id' ) ) );
			 	$wpdb->query( 'DELETE FROM ' . $bookings . ' WHERE id IN (' . implode( ',', $bids ) . ')' );
			 	if ( $iids ) {
			 		$oids = $wpdb->get_col( 'SELECT order_id FROM ' . $items . ' WHERE id IN (' . implode( ',', $iids ) . ')' );
			 		$wpdb->query( 'DELETE FROM ' . $items . ' WHERE id IN (' . implode( ',', $iids ) . ')' );
			 		$oids = array_filter( array_map( 'intval', $oids ) );
			 		if ( $oids ) $wpdb->query( 'DELETE FROM ' . $orders . ' WHERE id IN (' . implode( ',', $oids ) . ')' );
			 	}
			 }
			 $make_order = function() use ( $cid ) {
			 	$order = new OsOrderModel();
			 	$order->customer_id = $cid;
			 	$order->status = defined( 'LATEPOINT_ORDER_STATUS_OPEN' ) ? LATEPOINT_ORDER_STATUS_OPEN : 'open';
			 	$order->fulfillment_status = defined( 'LATEPOINT_ORDER_FULFILLMENT_STATUS_NOT_FULFILLED' ) ? LATEPOINT_ORDER_FULFILLMENT_STATUS_NOT_FULFILLED : 'not_fulfilled';
			 	$order->payment_status = defined( 'LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID' ) ? LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID : 'not_paid';
			 	$order->confirmation_code = 'MINN' . strtoupper( wp_generate_password( 5, false ) );
			 	$order->save();
			 	$item = new OsOrderItemModel();
			 	$item->order_id = $order->id;
			 	$item->variant = defined( 'LATEPOINT_ITEM_VARIANT_BOOKING' ) ? LATEPOINT_ITEM_VARIANT_BOOKING : 'booking';
			 	$item->item_data = wp_json_encode( array( 'seed' => 'minn-latepoint-suite' ) );
			 	$item->save();
			 	return (int) $item->id;
			 };
			 $pending_local = new DateTime( 'now', wp_timezone() );
			 $pending_local->modify( '+2 days' )->setTime( 10, 0, 0 );
			 $pending_end = clone $pending_local;
			 $pending_end->modify( '+60 minutes' );
			 $pending_utc = clone $pending_local;
			 $pending_utc->setTimezone( new DateTimeZone( 'UTC' ) );
			 $pending_end_utc = clone $pending_end;
			 $pending_end_utc->setTimezone( new DateTimeZone( 'UTC' ) );
			 $canceled_local = new DateTime( 'now', wp_timezone() );
			 $canceled_local->modify( '-1 day' )->setTime( 14, 0, 0 );
			 $canceled_end = clone $canceled_local;
			 $canceled_end->modify( '+60 minutes' );
			 $canceled_utc = clone $canceled_local;
			 $canceled_utc->setTimezone( new DateTimeZone( 'UTC' ) );
			 $canceled_end_utc = clone $canceled_end;
			 $canceled_end_utc->setTimezone( new DateTimeZone( 'UTC' ) );
			 $mins = function( DateTime $dt ) { return ( (int) $dt->format( 'H' ) ) * 60 + (int) $dt->format( 'i' ); };
			 $insert = function( $status, $start, $end, $start_utc, $end_utc, $item_id, $code ) use ( $wpdb, $bookings, $cid, $service, $agent, $location, $mins ) {
			 	$wpdb->insert( $bookings, array(
			 		'booking_code' => $code,
			 		'start_date' => $start->format( 'Y-m-d' ),
			 		'end_date' => $end->format( 'Y-m-d' ),
			 		'start_time' => $mins( $start ),
			 		'end_time' => $mins( $end ),
			 		'start_datetime_utc' => $start_utc->format( 'Y-m-d H:i:s' ),
			 		'end_datetime_utc' => $end_utc->format( 'Y-m-d H:i:s' ),
			 		'buffer_before' => 0,
			 		'buffer_after' => 0,
			 		'duration' => 60,
			 		'status' => $status,
			 		'customer_id' => $cid,
			 		'service_id' => $service,
			 		'agent_id' => $agent,
			 		'location_id' => $location,
			 		'order_item_id' => $item_id,
			 		'total_attendees' => 1,
			 		'created_at' => current_time( 'mysql' ),
			 		'updated_at' => current_time( 'mysql' ),
			 	) );
			 	return (int) $wpdb->insert_id;
			 };
			 $pending = $insert( 'pending', $pending_local, $pending_end, $pending_utc, $pending_end_utc, $make_order(), 'MINNSUP' );
			 $canceled = $insert( 'cancelled', $canceled_local, $canceled_end, $canceled_utc, $canceled_end_utc, $make_order(), 'MINNSUC' );
			 echo wp_json_encode( array( 'pending' => $pending, 'canceled' => $canceled, 'customer' => $cid ) );`
		);
		try { seed = JSON.parse( ( seedOut.match( /\{.*\}/ ) || [ '{}' ] )[ 0 ] ); } catch ( e ) { seed = {}; }
		t.check( 'pending + cancelled suite bookings seeded', seed.pending > 0 && seed.canceled > 0, seedOut.slice( 0, 160 ) );

		const list = await api( 'minn-admin/v1/latepoint/bookings' );
		t.check( 'upcoming list answers', list.status === 200 && ( list.body.total || 0 ) >= 1, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
		const priya = ( list.body.items || [] ).find( ( i ) => /Priya/.test( i.customer ) );
		t.check( 'upcoming includes the pending suite row with UTC date and pill status',
			!! priya && priya.status === 'pending' && /Z$/.test( priya.date || '' ) && priya.service,
			JSON.stringify( priya ) );

		const pending = await api( 'minn-admin/v1/latepoint/bookings?range=pending' );
		t.check( 'pending filter narrows to pending rows',
			pending.status === 200 && ( pending.body.items || [] ).every( ( i ) => i.status === 'pending' || i.status === 'payment_pending' )
			&& ( pending.body.items || [] ).some( ( i ) => i.id === seed.pending ),
			JSON.stringify( { total: pending.body && pending.body.total } ) );

		const canceled = await api( 'minn-admin/v1/latepoint/bookings?range=canceled' );
		t.check( 'canceled filter shows the cancelled suite row',
			canceled.status === 200 && ( canceled.body.items || [] ).some( ( i ) => i.id === seed.canceled ),
			JSON.stringify( { total: canceled.body && canceled.body.total } ) );

		const search = await api( 'minn-admin/v1/latepoint/bookings?range=all&search=' + encodeURIComponent( 'priya-latepoint-suite@example.com' ) );
		t.check( 'search matches customer email',
			search.status === 200 && ( search.body.items || [] ).every( ( i ) => /Priya/.test( i.customer ) ) && search.body.total >= 1,
			JSON.stringify( { total: search.body && search.body.total } ) );

		const view = await api( `minn-admin/v1/latepoint/bookings/${ seed.pending }` );
		t.check( 'detail is a contact card with customer + appointment sections',
			view.status === 200 && view.body.kind === 'entry'
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Customer' && s.rows.some( ( r ) => r.label === 'Email' && /priya-latepoint-suite/.test( r.value ) ) )
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Appointment' && s.rows.some( ( r ) => r.label === 'Service' ) )
			&& /page=latepoint/.test( view.body.adminUrl || '' ),
			JSON.stringify( ( view.body.sections || [] ).map( ( s ) => s.title ) ) );

		const mark = await api( `minn-admin/v1/latepoint/bookings/${ seed.pending }/status`, { method: 'POST', body: { status: 'approved' } } );
		const after = await api( `minn-admin/v1/latepoint/bookings/${ seed.pending }` );
		const afterStatus = ( ( after.body.sections || [] ).find( ( s ) => s.title === 'Appointment' ) || { rows: [] } )
			.rows.find( ( r ) => r.label === 'Status' );
		t.check( 'approve goes through LatePoint and flips the stored status',
			mark.status === 200 && mark.body && mark.body.ok && afterStatus && afterStatus.value === 'approved',
			JSON.stringify( { mark: mark.status, body: mark.body, stored: afterStatus && afterStatus.value } ) );

		const st = await api( 'minn-admin/v1/latepoint/status' );
		t.check( 'status card carries today, pending, next and Open LatePoint',
			st.status === 200
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Today' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Pending' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Next' )
			&& ( st.body.actions || [] ).some( ( a ) => /Open LatePoint/.test( a.label ) && /page=latepoint/.test( a.href || '' ) ),
			JSON.stringify( st.body && { rows: ( st.body.rows || [] ).map( ( r ) => r.label ), actions: st.body.actions } ) );

		let painted = false;
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				await page.goto( `${ BASE }/minn-admin/latepoint`, { waitUntil: 'domcontentloaded', timeout: 45000 } );
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
			return !!( el && /Today|Pending|Appointments/.test( el.textContent ) );
		} ) );
		t.check( 'sidebar lists Bookings', await page.evaluate( () =>
			!! document.querySelector( '[data-nav="latepoint"], [data-family="bookings"]' ) || /Bookings/.test( document.body.innerText ) ) );
		t.check( 'family switcher offers LatePoint when Amelia is also active', await page.evaluate( () => {
			const sw = document.querySelector( '#minn-surface-switch' );
			if ( ! sw ) return ( window.MINN.surfaces || [] ).filter( ( s ) => s.family === 'bookings' ).length < 2;
			const input = sw.querySelector( '.minn-ac-input' );
			return !!( input && /LatePoint/i.test( input.value || '' ) );
		} ) );
	} finally {
		if ( seed.customer || seed.pending ) {
			evalPhp(
				`global $wpdb;
				 $bookings = $wpdb->prefix . 'latepoint_bookings';
				 $orders = $wpdb->prefix . 'latepoint_orders';
				 $items = $wpdb->prefix . 'latepoint_order_items';
				 $customers = $wpdb->prefix . 'latepoint_customers';
				 $cid = ${ parseInt( seed.customer, 10 ) || 0 };
				 $pending = ${ parseInt( seed.pending, 10 ) || 0 };
				 $canceled = ${ parseInt( seed.canceled, 10 ) || 0 };
				 $old = $wpdb->get_results( $wpdb->prepare( "SELECT id, order_item_id FROM {$bookings} WHERE booking_code LIKE %s OR id IN (%d,%d)", 'MINNSU%', $pending, $canceled ) );
				 if ( $old ) {
				 	$bids = array_filter( array_map( 'intval', wp_list_pluck( $old, 'id' ) ) );
				 	$iids = array_filter( array_map( 'intval', wp_list_pluck( $old, 'order_item_id' ) ) );
				 	if ( $bids ) $wpdb->query( 'DELETE FROM ' . $bookings . ' WHERE id IN (' . implode( ',', $bids ) . ')' );
				 	if ( $iids ) {
				 		$oids = $wpdb->get_col( 'SELECT order_id FROM ' . $items . ' WHERE id IN (' . implode( ',', $iids ) . ')' );
				 		$wpdb->query( 'DELETE FROM ' . $items . ' WHERE id IN (' . implode( ',', $iids ) . ')' );
				 		$oids = array_filter( array_map( 'intval', $oids ) );
				 		if ( $oids ) $wpdb->query( 'DELETE FROM ' . $orders . ' WHERE id IN (' . implode( ',', $oids ) . ')' );
				 	}
				 }
				 if ( $cid ) {
				 	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} WHERE customer_id = %d", $cid ) );
				 	if ( ! $left ) $wpdb->delete( $customers, array( 'id' => $cid ) );
				 }`
			);
		}
		if ( ! wasActive ) wp( 'plugin deactivate latepoint' );
	}

	await t.done( browser, errors );
} )();
