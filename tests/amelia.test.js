/**
 * Amelia bookings adapter (Wave F). Appointments live in
 * {prefix}amelia_appointments with UTC bookingStart; customers and
 * services join from Amelia's own user/service tables. Status writes go
 * through Amelia's UpdateAppointmentStatus controller so notifications
 * and booking rows stay theirs.
 *
 * Amelia stays ACTIVE as the bookings-family resident. The suite seeds
 * two disposable appointments (pending + canceled) against a dedicated
 * customer and deletes them after.
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
	const file = path.join( os.tmpdir(), `minn-amelia-${ process.pid }.php` );
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
	const t = reporter( 'amelia' );
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
			execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active ameliabooking`, { stdio: 'ignore', timeout: 30000 } );
		} catch ( e ) {
			wasActive = false;
		}
		if ( ! wasActive ) wp( 'plugin activate ameliabooking' );

		const seedOut = evalPhp(
			`global $wpdb;
			 $users = $wpdb->prefix . 'amelia_users';
			 $services = $wpdb->prefix . 'amelia_services';
			 $pts = $wpdb->prefix . 'amelia_providers_to_services';
			 $appts = $wpdb->prefix . 'amelia_appointments';
			 $bookings = $wpdb->prefix . 'amelia_customer_bookings';
			 $provider = (int) $wpdb->get_var( "SELECT id FROM {$users} WHERE type = 'provider' ORDER BY id ASC LIMIT 1" );
			 $service = (int) $wpdb->get_var( "SELECT id FROM {$services} ORDER BY id ASC LIMIT 1" );
			 if ( ! $provider ) {
			 	$wpdb->insert( $users, array( 'status' => 'visible', 'type' => 'provider', 'firstName' => 'Minn', 'lastName' => 'Stylist', 'email' => 'stylist@minn.test' ) );
			 	$provider = (int) $wpdb->insert_id;
			 }
			 if ( ! $service ) {
			 	$wpdb->insert( $services, array( 'name' => 'Haircut', 'color' => '#1788FB', 'price' => 45, 'status' => 'visible', 'categoryId' => 1, 'minCapacity' => 1, 'maxCapacity' => 1, 'duration' => 1800, 'priority' => 'least_expensive' ) );
			 	$service = (int) $wpdb->insert_id;
			 }
			 if ( $provider && $service && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pts} WHERE userId = %d AND serviceId = %d", $provider, $service ) ) ) {
			 	$wpdb->insert( $pts, array( 'userId' => $provider, 'serviceId' => $service, 'price' => 45, 'minCapacity' => 1, 'maxCapacity' => 1 ) );
			 }
			 $email = 'priya-amelia-suite@example.com';
			 $cid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$users} WHERE email = %s", $email ) );
			 if ( ! $cid ) {
			 	$wpdb->insert( $users, array( 'status' => 'visible', 'type' => 'customer', 'firstName' => 'Priya', 'lastName' => 'Suite', 'email' => $email, 'phone' => '+15555550999' ) );
			 	$cid = (int) $wpdb->insert_id;
			 }
			 $wpdb->query( $wpdb->prepare( "DELETE b FROM {$bookings} b JOIN {$appts} a ON a.id = b.appointmentId WHERE b.customerId = %d", $cid ) );
			 $wpdb->query( $wpdb->prepare( "DELETE a FROM {$appts} a LEFT JOIN {$bookings} b ON b.appointmentId = a.id WHERE a.internalNotes = %s OR b.customerId = %d", 'minn-amelia-suite', $cid ) );
			 $pending_start = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );
			 $pending_end = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS + 1800 );
			 $wpdb->insert( $appts, array( 'status' => 'pending', 'bookingStart' => $pending_start, 'bookingEnd' => $pending_end, 'notifyParticipants' => 0, 'serviceId' => $service, 'providerId' => $provider, 'internalNotes' => 'minn-amelia-suite' ) );
			 $pending = (int) $wpdb->insert_id;
			 $wpdb->insert( $bookings, array( 'appointmentId' => $pending, 'customerId' => $cid, 'status' => 'pending', 'price' => 45, 'persons' => 1, 'created' => gmdate( 'Y-m-d H:i:s' ) ) );
			 $canceled_start = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			 $canceled_end = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS + 1800 );
			 $wpdb->insert( $appts, array( 'status' => 'canceled', 'bookingStart' => $canceled_start, 'bookingEnd' => $canceled_end, 'notifyParticipants' => 0, 'serviceId' => $service, 'providerId' => $provider, 'internalNotes' => 'minn-amelia-suite' ) );
			 $canceled = (int) $wpdb->insert_id;
			 $wpdb->insert( $bookings, array( 'appointmentId' => $canceled, 'customerId' => $cid, 'status' => 'canceled', 'price' => 45, 'persons' => 1, 'created' => gmdate( 'Y-m-d H:i:s' ) ) );
			 echo wp_json_encode( array( 'pending' => $pending, 'canceled' => $canceled, 'customer' => $cid ) );`
		);
		try { seed = JSON.parse( ( seedOut.match( /\{.*\}/ ) || [ '{}' ] )[ 0 ] ); } catch ( e ) { seed = {}; }
		t.check( 'pending + canceled suite appointments seeded', seed.pending > 0 && seed.canceled > 0, seedOut.slice( 0, 160 ) );

		const list = await api( 'minn-admin/v1/amelia/appointments' );
		t.check( 'upcoming list answers', list.status === 200 && ( list.body.total || 0 ) >= 1, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
		const priya = ( list.body.items || [] ).find( ( i ) => /Priya/.test( i.customer ) );
		t.check( 'upcoming includes the pending suite row with UTC date and pill status',
			!! priya && priya.status === 'pending' && /Z$/.test( priya.date || '' ) && priya.service,
			JSON.stringify( priya ) );

		const pending = await api( 'minn-admin/v1/amelia/appointments?range=pending' );
		t.check( 'pending filter narrows to pending rows',
			pending.status === 200 && ( pending.body.items || [] ).every( ( i ) => i.status === 'pending' )
			&& ( pending.body.items || [] ).some( ( i ) => i.id === seed.pending ),
			JSON.stringify( { total: pending.body && pending.body.total } ) );

		const canceled = await api( 'minn-admin/v1/amelia/appointments?range=canceled' );
		t.check( 'canceled filter shows the canceled suite row',
			canceled.status === 200 && ( canceled.body.items || [] ).some( ( i ) => i.id === seed.canceled ),
			JSON.stringify( { total: canceled.body && canceled.body.total } ) );

		const search = await api( 'minn-admin/v1/amelia/appointments?range=all&search=' + encodeURIComponent( 'priya-amelia-suite@example.com' ) );
		t.check( 'search matches customer email',
			search.status === 200 && ( search.body.items || [] ).every( ( i ) => /Priya/.test( i.customer ) ) && search.body.total >= 1,
			JSON.stringify( { total: search.body && search.body.total } ) );

		const view = await api( `minn-admin/v1/amelia/appointments/${ seed.pending }` );
		t.check( 'detail is a contact card with customer + appointment sections',
			view.status === 200 && view.body.kind === 'entry'
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Customer' && s.rows.some( ( r ) => r.label === 'Email' && /priya-amelia-suite/.test( r.value ) ) )
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Appointment' && s.rows.some( ( r ) => r.label === 'Service' ) )
			&& /wpamelia-bookings/.test( view.body.adminUrl || '' ),
			JSON.stringify( ( view.body.sections || [] ).map( ( s ) => s.title ) ) );

		const mark = await api( `minn-admin/v1/amelia/appointments/${ seed.pending }/status`, { method: 'POST', body: { status: 'approved' } } );
		const after = await api( `minn-admin/v1/amelia/appointments/${ seed.pending }` );
		const afterStatus = ( ( after.body.sections || [] ).find( ( s ) => s.title === 'Appointment' ) || { rows: [] } )
			.rows.find( ( r ) => r.label === 'Status' );
		t.check( 'approve goes through Amelia and flips the stored status',
			mark.status === 200 && mark.body && mark.body.ok && afterStatus && afterStatus.value === 'approved',
			JSON.stringify( { mark: mark.status, body: mark.body, stored: afterStatus && afterStatus.value } ) );

		const st = await api( 'minn-admin/v1/amelia/status' );
		t.check( 'status card carries today, pending, next and Open Amelia',
			st.status === 200
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Today' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Pending' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Next' )
			&& ( st.body.actions || [] ).some( ( a ) => /Open Amelia/.test( a.label ) && /wpamelia-bookings/.test( a.href || '' ) ),
			JSON.stringify( st.body && { rows: ( st.body.rows || [] ).map( ( r ) => r.label ), actions: st.body.actions } ) );

		let painted = false;
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				await page.goto( `${ BASE }/minn-admin/amelia`, { waitUntil: 'domcontentloaded', timeout: 45000 } );
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
			!! document.querySelector( '[data-nav="amelia"]' ) || /Bookings/.test( document.body.innerText ) ) );
	} finally {
		if ( seed.customer || seed.pending ) {
			evalPhp(
				`global $wpdb;
				 $appts = $wpdb->prefix . 'amelia_appointments';
				 $bookings = $wpdb->prefix . 'amelia_customer_bookings';
				 $users = $wpdb->prefix . 'amelia_users';
				 $cid = ${ parseInt( seed.customer, 10 ) || 0 };
				 $pending = ${ parseInt( seed.pending, 10 ) || 0 };
				 $canceled = ${ parseInt( seed.canceled, 10 ) || 0 };
				 foreach ( array( $pending, $canceled ) as $id ) {
				 	if ( $id ) {
				 		$wpdb->delete( $bookings, array( 'appointmentId' => $id ) );
				 		$wpdb->delete( $appts, array( 'id' => $id ) );
				 	}
				 }
				 if ( $cid ) {
				 	$wpdb->query( $wpdb->prepare( "DELETE b FROM {$bookings} b JOIN {$appts} a ON a.id = b.appointmentId WHERE b.customerId = %d AND a.internalNotes = %s", $cid, 'minn-amelia-suite' ) );
				 	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} WHERE customerId = %d", $cid ) );
				 	if ( ! $left ) $wpdb->delete( $users, array( 'id' => $cid ) );
				 }`
			);
		}
		if ( ! wasActive ) wp( 'plugin deactivate ameliabooking' );
	}

	await t.done( browser, errors );
} )();
