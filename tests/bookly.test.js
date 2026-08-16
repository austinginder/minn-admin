/**
 * Bookly bookings adapter. The slot lives in {prefix}bookly_appointments
 * (WP-local start_date); the customer's status lives on
 * {prefix}bookly_customer_appointments. Status writes go through
 * CustomerAppointment::setStatus + save, then Sender::sendForCA so
 * notifications stay Bookly's.
 *
 * Bookly stays ACTIVE as a bookings-family resident alongside Amelia
 * and LatePoint. The suite seeds two disposable customer-appointments
 * (pending + cancelled) against a dedicated customer and deletes them
 * after.
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
	const file = path.join( os.tmpdir(), `minn-bookly-${ process.pid }.php` );
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
	const t = reporter( 'bookly' );
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
			execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active bookly-responsive-appointment-booking-tool`, { stdio: 'ignore', timeout: 30000 } );
		} catch ( e ) {
			wasActive = false;
		}
		if ( ! wasActive ) wp( 'plugin activate bookly-responsive-appointment-booking-tool' );

		const seedOut = evalPhp(
			`if ( ! class_exists( 'Bookly\\\\Lib\\\\Entities\\\\CustomerAppointment' ) ) { echo '{"error":"inactive"}'; return; }
			 $staff = \\Bookly\\Lib\\Entities\\Staff::query()->findOne();
			 if ( ! $staff ) {
			 	$staff = new \\Bookly\\Lib\\Entities\\Staff();
			 	$staff->setFullName( 'Minn Stylist' )->setEmail( 'stylist-bookly@minn.test' )->setVisibility( 'public' );
			 	$staff->save();
			 }
			 $service = \\Bookly\\Lib\\Entities\\Service::query()->findOne();
			 if ( ! $service ) {
			 	$service = new \\Bookly\\Lib\\Entities\\Service();
			 	$service->setTitle( 'Haircut' )->setDuration( 3600 )->setPrice( 45 )->setColor( '#1788FB' )->setVisibility( 'public' );
			 	$service->save();
			 }
			 $email = 'priya-bookly-suite@example.com';
			 $customer = \\Bookly\\Lib\\Entities\\Customer::query()->where( 'email', $email )->findOne();
			 if ( ! $customer ) {
			 	$customer = new \\Bookly\\Lib\\Entities\\Customer();
			 	$customer->setFirstName( 'Priya' )->setLastName( 'Suite' )->setFullName( 'Priya Suite' )
			 		->setEmail( $email )->setPhone( '+15555550997' )->setNotes( '' )->setCreatedAt( current_time( 'mysql' ) );
			 	$customer->save();
			 }
			 $old = \\Bookly\\Lib\\Entities\\Appointment::query()->where( 'internal_note', 'minn-bookly-suite' )->find();
			 foreach ( $old as $a ) {
			 	\\Bookly\\Lib\\Entities\\CustomerAppointment::query()->delete()->where( 'appointment_id', $a->getId() )->execute();
			 	$a->delete();
			 }
			 $pending_local = new DateTime( 'now', wp_timezone() );
			 $pending_local->modify( '+2 days' )->setTime( 10, 0, 0 );
			 $pending_end = clone $pending_local; $pending_end->modify( '+60 minutes' );
			 $canceled_local = new DateTime( 'now', wp_timezone() );
			 $canceled_local->modify( '-1 day' )->setTime( 14, 0, 0 );
			 $canceled_end = clone $canceled_local; $canceled_end->modify( '+60 minutes' );
			 $make = function( $status, DateTime $start, DateTime $end ) use ( $staff, $service, $customer ) {
			 	$a = new \\Bookly\\Lib\\Entities\\Appointment();
			 	$a->setStaffId( $staff->getId() )->setServiceId( $service->getId() )
			 		->setStartDate( $start->format( 'Y-m-d H:i:s' ) )->setEndDate( $end->format( 'Y-m-d H:i:s' ) )
			 		->setInternalNote( 'minn-bookly-suite' )->setCreatedAt( current_time( 'mysql' ) )->setUpdatedAt( current_time( 'mysql' ) );
			 	$a->save();
			 	$ca = new \\Bookly\\Lib\\Entities\\CustomerAppointment();
			 	$ca->setCustomerId( $customer->getId() )->setAppointmentId( $a->getId() )->setStatus( $status )
			 		->setNumberOfPersons( 1 )->setNotes( '' )->setCreatedFrom( 'backend' )
			 		->setCreatedAt( current_time( 'mysql' ) )->setUpdatedAt( current_time( 'mysql' ) );
			 	$ca->save();
			 	return (int) $ca->getId();
			 };
			 $pending = $make( \\Bookly\\Lib\\Entities\\CustomerAppointment::STATUS_PENDING, $pending_local, $pending_end );
			 $canceled = $make( \\Bookly\\Lib\\Entities\\CustomerAppointment::STATUS_CANCELLED, $canceled_local, $canceled_end );
			 echo wp_json_encode( array( 'pending' => $pending, 'canceled' => $canceled, 'customer' => (int) $customer->getId() ) );`
		);
		try { seed = JSON.parse( ( seedOut.match( /\{.*\}/ ) || [ '{}' ] )[ 0 ] ); } catch ( e ) { seed = {}; }
		t.check( 'pending + cancelled suite bookings seeded', seed.pending > 0 && seed.canceled > 0, seedOut.slice( 0, 160 ) );

		const list = await api( 'minn-admin/v1/bookly/appointments' );
		t.check( 'upcoming list answers', list.status === 200 && ( list.body.total || 0 ) >= 1, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
		const priya = ( list.body.items || [] ).find( ( i ) => /Priya/.test( i.customer ) );
		t.check( 'upcoming includes the pending suite row with UTC date and pill status',
			!! priya && priya.status === 'pending' && /Z$/.test( priya.date || '' ) && priya.service,
			JSON.stringify( priya ) );

		const pending = await api( 'minn-admin/v1/bookly/appointments?range=pending' );
		t.check( 'pending filter narrows to pending rows',
			pending.status === 200 && ( pending.body.items || [] ).every( ( i ) => i.status === 'pending' || i.status === 'waitlisted' )
			&& ( pending.body.items || [] ).some( ( i ) => i.id === seed.pending ),
			JSON.stringify( { total: pending.body && pending.body.total } ) );

		const canceled = await api( 'minn-admin/v1/bookly/appointments?range=canceled' );
		t.check( 'canceled filter shows the cancelled suite row',
			canceled.status === 200 && ( canceled.body.items || [] ).some( ( i ) => i.id === seed.canceled ),
			JSON.stringify( { total: canceled.body && canceled.body.total } ) );

		const search = await api( 'minn-admin/v1/bookly/appointments?range=all&search=' + encodeURIComponent( 'priya-bookly-suite@example.com' ) );
		t.check( 'search matches customer email',
			search.status === 200 && ( search.body.items || [] ).every( ( i ) => /Priya/.test( i.customer ) ) && search.body.total >= 1,
			JSON.stringify( { total: search.body && search.body.total } ) );

		const view = await api( `minn-admin/v1/bookly/appointments/${ seed.pending }` );
		t.check( 'detail is a contact card with customer + appointment sections',
			view.status === 200 && view.body.kind === 'entry'
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Customer' && s.rows.some( ( r ) => r.label === 'Email' && /priya-bookly-suite/.test( r.value ) ) )
			&& ( view.body.sections || [] ).some( ( s ) => s.title === 'Appointment' && s.rows.some( ( r ) => r.label === 'Service' ) )
			&& /page=bookly-appointments/.test( view.body.adminUrl || '' ),
			JSON.stringify( ( view.body.sections || [] ).map( ( s ) => s.title ) ) );

		const mark = await api( `minn-admin/v1/bookly/appointments/${ seed.pending }/status`, { method: 'POST', body: { status: 'approved' } } );
		const after = await api( `minn-admin/v1/bookly/appointments/${ seed.pending }` );
		const afterStatus = ( ( after.body.sections || [] ).find( ( s ) => s.title === 'Appointment' ) || { rows: [] } )
			.rows.find( ( r ) => r.label === 'Status' );
		t.check( 'approve goes through Bookly and flips the stored status',
			mark.status === 200 && mark.body && mark.body.ok && afterStatus && afterStatus.value === 'approved',
			JSON.stringify( { mark: mark.status, body: mark.body, stored: afterStatus && afterStatus.value } ) );

		const st = await api( 'minn-admin/v1/bookly/status' );
		t.check( 'status card carries today, pending, next and Open Bookly',
			st.status === 200
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Today' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Pending' )
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Next' )
			&& ( st.body.actions || [] ).some( ( a ) => /Open Bookly/.test( a.label ) && /page=bookly-appointments/.test( a.href || '' ) ),
			JSON.stringify( st.body && { rows: ( st.body.rows || [] ).map( ( r ) => r.label ), actions: st.body.actions } ) );

		let painted = false;
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				await page.goto( `${ BASE }/minn-admin/bookly`, { waitUntil: 'domcontentloaded', timeout: 45000 } );
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
			!! document.querySelector( '[data-nav="bookly"], [data-family="bookings"]' ) || /Bookings/.test( document.body.innerText ) ) );
		t.check( 'family switcher offers Bookly when other bookings plugins are active', await page.evaluate( () => {
			const sw = document.querySelector( '#minn-surface-switch' );
			if ( ! sw ) return ( window.MINN.surfaces || [] ).filter( ( s ) => s.family === 'bookings' ).length < 2;
			const input = sw.querySelector( '.minn-ac-input' );
			return !!( input && /Bookly/i.test( input.value || '' ) );
		} ) );
	} finally {
		evalPhp(
			`if ( ! class_exists( 'Bookly\\\\Lib\\\\Entities\\\\Appointment' ) ) return;
			 $old = \\Bookly\\Lib\\Entities\\Appointment::query()->where( 'internal_note', 'minn-bookly-suite' )->find();
			 foreach ( $old as $a ) {
			 	\\Bookly\\Lib\\Entities\\CustomerAppointment::query()->delete()->where( 'appointment_id', $a->getId() )->execute();
			 	$a->delete();
			 }
			 $cid = ${ parseInt( seed.customer, 10 ) || 0 };
			 if ( $cid ) {
			 	$left = \\Bookly\\Lib\\Entities\\CustomerAppointment::query()->where( 'customer_id', $cid )->count();
			 	if ( ! $left ) { $c = new \\Bookly\\Lib\\Entities\\Customer(); if ( $c->load( $cid ) ) $c->delete(); }
			 }`
		);
		if ( ! wasActive ) wp( 'plugin deactivate bookly-responsive-appointment-booking-tool' );
	}

	await t.done( browser, errors );
} )();
