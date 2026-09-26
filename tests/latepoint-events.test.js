/**
 * LatePoint event registrations (5.7+ events) — the Event registrations view
 * on the LatePoint Bookings surface.
 *
 * Proves: the view appears only while LatePoint's events setting is on; an
 * upcoming confirmed registration lists with its event and seats; the detail
 * shows Registration / Customer / Event; Cancel from the detail cancels it
 * the way LatePoint's own button does (status cancelled, moves to the
 * Cancelled filter); an Editor is refused; zero console errors.
 *
 * Fixture: seeded through LatePoint's own models (customer, event,
 * registration) with their events setting switched on for the run; all rows
 * removed and the setting restored in finally.
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wpPhp = ( php, user = 'admin' ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=${ user } 2>/dev/null`, { input: '<?php ' + php } )
	.toString().trim().split( '\n' ).pop();

( async () => {
	const t = reporter( 'latepoint-events' );
	if ( '1' !== wpPhp( "echo class_exists( 'OsEventRegistrationModel' ) ? 1 : 0;" ) ) {
		console.log( 'SKIP  LatePoint 5.7+ events are not available' );
		process.exit( 0 );
	}
	const seed = JSON.parse( wpPhp( `
		$was = OsSettingsHelper::get_settings_value( 'enable_events_functionality', null );
		OsSettingsHelper::save_setting_by_name( 'enable_events_functionality', 'on' );
		$c = new OsCustomerModel(); $c->first_name = 'Rhea'; $c->last_name = 'Suite'; $c->email = 'rhea-suite@example.com'; $c->save();
		$e = new OsEventModel(); $e->name = 'Minn suite workshop'; $e->status = 'published'; $e->capacity = 10;
		$e->start_datetime_utc = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ); $e->end_datetime_utc = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS + 7200 ); $e->save();
		$r = new OsEventRegistrationModel(); $r->event_id = $e->id; $r->customer_id = $c->id; $r->quantity = 2; $r->status = 'confirmed'; $r->payment_status = 'paid'; $r->order_id = 0; $r->save();
		echo wp_json_encode( array( 'was' => $was, 'c' => (int) $c->id, 'e' => (int) $e->id, 'r' => (int) $r->id ) );
	` ) );

	const { browser, page, errors } = await launch();
	page.on( 'dialog', ( d ) => d.accept() );
	const status = () => wpPhp( `global $wpdb; echo $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}latepoint_event_registrations WHERE id = %d", ${ seed.r } ) );` );

	try {
		await login( page );
		await page.goto( BASE + '/minn-admin/latepoint', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="x0"]', { timeout: 20000 } );
		t.check( 'Event registrations view appears while events are on', await page.$eval( '[data-sview="x0"]', ( el ) => /Event registrations/.test( el.textContent ) ) );
		await page.click( '[data-sview="x0"]' );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => /Rhea Suite/.test( r.textContent ) ), null, { timeout: 15000 } );
		const cells = await page.evaluate( () => Array.from( Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => /Rhea Suite/.test( r.textContent ) ).children ).map( ( c ) => c.textContent.trim() ) );
		t.check( 'the upcoming registration lists with its event and seats', cells.includes( 'Minn suite workshop' ) && cells.includes( '2' ), JSON.stringify( cells ) );

		await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => /Rhea Suite/.test( r.textContent ) ).click() );
		await page.waitForFunction( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).some( ( b ) => /Cancel registration/.test( b.textContent ) ), null, { timeout: 15000 } );
		const sections = await page.evaluate( () => document.querySelector( '.minn-modal' ).textContent );
		t.check( 'detail shows registration, customer and event', /Registration/.test( sections ) && /rhea-suite@example\.com/.test( sections ) && /Minn suite workshop/.test( sections ) );
		await page.evaluate( () => Array.from( document.querySelectorAll( '.minn-modal button' ) ).find( ( b ) => /Cancel registration/.test( b.textContent ) ).click() );
		for ( let i = 0; i < 30 && 'cancelled' !== status(); i++ ) await page.waitForTimeout( 300 );
		t.check( 'Cancel registration cancels it through LatePoint', 'cancelled' === status() );

		const lists = await page.evaluate( async () => {
			const get = async ( q ) => ( await ( await fetch( window.MINN.restUrl + 'minn-admin/v1/latepoint/event-registrations?search=Rhea&' + q, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ) ).json() ).total;
			return { upcoming: await get( 'range=upcoming' ), cancelled: await get( 'range=cancelled' ) };
		} );
		t.check( 'a cancelled registration leaves Upcoming and shows under Cancelled', 0 === lists.upcoming && 1 === lists.cancelled, JSON.stringify( lists ) );

		// A cookie-only request (what a cross-site form can send) carries no
		// REST nonce: core demotes it to logged out, and LatePoint's cached
		// user must not keep answering for the admin.
		const csrf = await page.evaluate( async ( id ) => {
			const post = await fetch( window.MINN.restUrl + 'minn-admin/v1/latepoint/event-registrations/' + id + '/cancel', { method: 'POST', credentials: 'same-origin' } );
			const list = await fetch( window.MINN.restUrl + 'minn-admin/v1/latepoint/bookings', { credentials: 'same-origin' } );
			return { cancel: post.status, bookings: list.status };
		}, seed.r );
		t.check( 'requests without a REST nonce are refused (no cross-site cancel or read)', 401 === csrf.cancel && 401 === csrf.bookings, JSON.stringify( csrf ) );

		const ed = wpPhp( `echo rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/latepoint/event-registrations' ) )->get_status();`, 'minn-editor' );
		t.check( 'an Editor is refused', '403' === ed, ed );
	} finally {
		try {
			wpPhp( `
				global $wpdb; $p = $wpdb->prefix;
				$wpdb->delete( $p . 'latepoint_event_registrations', array( 'id' => ${ seed.r } ) );
				$wpdb->delete( $p . 'latepoint_events', array( 'id' => ${ seed.e } ) );
				$wpdb->delete( $p . 'latepoint_customers', array( 'id' => ${ seed.c } ) );
				$was = json_decode( '${ JSON.stringify( seed.was ) }', true );
				// Their getter answers '' for an absent setting.
				if ( null === $was || '' === $was ) { $wpdb->delete( $p . 'latepoint_settings', array( 'name' => 'enable_events_functionality' ) ); } else { OsSettingsHelper::save_setting_by_name( 'enable_events_functionality', $was ); }
				echo 1;
			` );
		} catch ( e ) {}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
