<?php
/**
 * Bookings: the shop's Saturday workshops, plant consultations and space
 * rentals in each booking plugin, from three weeks back to three weeks ahead,
 * in every status the plugin knows, with the customers' details.
 *
 * - Amelia: workshops (Priya) and consultations (June, Mateo) in its own
 *   amelia_* tables. bookingStart/End are UTC. customer_bookings.info is
 *   left empty: Amelia's front end stores the customer's name as JSON there
 *   and Minn prints that field as "Booking note".
 * - LatePoint: consultations, repotting and video calls as bookings (each
 *   hangs off an order and an order item, as LatePoint 5 makes them), and
 *   the workshops as events with registrations (the events setting is
 *   switched on here; Minn's "Event registrations" view needs it).
 *   start_date/start_time are site-local, start_datetime_utc is UTC. The
 *   customers, agents and services go through LatePoint's models, which
 *   save without firing the "created" hooks that send mail.
 * - Bookly: the same workshops and consultations through its entities.
 *   start_date is site-local; the status lives on customer_appointments.
 * - JetBooking: rentals of three spaces (a JetEngine post type, lf_rental)
 *   through jet_abaf()->db->insert_booking(), with guest_name, guest_phone
 *   and guest_note added as booking columns (settings additional_columns
 *   plus the ALTER TABLE their DB manager runs) so guests without an
 *   account have a name. Overlapping stays need units, or insert_booking
 *   refuses them. Day epochs are site-local "naive" timestamps, and
 *   insert_booking adds one second to every check-in.
 * - JetAppointments: consultations and care visits through its
 *   Appointment_Model; services (lf_service) and specialists
 *   (lf_specialist) are JetEngine post types related through the legacy
 *   relation JetAppointments registers (meta relation_<md5> on both
 *   posts). Saving with a provider fatals without that relation, and
 *   JetAppointments registers it on admin screens only. Epochs are naive
 *   like JetBooking's. On-hold counts as canceled there by default, so no
 *   appointment uses it.
 *
 * The JetEngine post type definitions stay between runs; their posts and
 * every booking row are cleared and made again.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	$demo->as_user( 'june' );

	// Seeding sends nothing out. OttoKit posts some plugin events (every
	// JetEngine content type item among them) to its own servers whether or
	// not an automation uses them, then logs and retries what fails. While
	// this part runs, requests leave for this site only and OttoKit logs none.
	$home    = wp_parse_url( home_url(), PHP_URL_HOST );
	$offline = function ( $pre, $args, $url ) use ( $home ) {
		return wp_parse_url( $url, PHP_URL_HOST ) === $home ? $pre : new WP_Error( 'minn_demo_offline', 'The demo seed makes no outside requests.' );
	};
	add_filter( 'pre_http_request', $offline, 10, 3 );
	add_filter( 'suretriggers_enable_request_logging', '__return_false' );

	// When things happen. Workshops are Saturday mornings: $sat( 0 ) is this
	// week's Saturday, -1 the one before, 1 the next. Other days skip Sunday.
	$dow = (int) wp_date( 'w', $demo->now );
	$at  = function ( $spec ) use ( $demo, $dow ) {
		list( $kind, $n, $hour, $minute ) = $spec;
		if ( 'sat' === $kind ) {
			return $demo->ago( -( 6 - $dow + 7 * $n ), $hour, $minute );
		}
		$ts = $demo->ago( $n, $hour, $minute );
		if ( '0' === wp_date( 'w', $ts ) && 0 !== $n ) {
			$ts = $demo->ago( $n - 1, $hour, $minute );
		}
		return $ts;
	};
	// When it was booked: a few days ahead of the slot, never in the future.
	$booked = function ( $ts, $lead ) use ( $demo ) {
		return min( $demo->now - 5 * HOUR_IN_SECONDS - $lead * 600, $ts - $lead * DAY_IN_SECONDS );
	};
	$e164 = function ( $phone ) {
		return '+1' . preg_replace( '/\D/', '', $phone );
	};
	// Site-local "naive" epochs, the way the Jet plugins store dates.
	$naive = function ( $ts ) {
		return strtotime( wp_date( 'Y-m-d H:i:s', $ts ) . ' UTC' );
	};
	$naive_day = function ( $ts ) {
		return strtotime( wp_date( 'Y-m-d', $ts ) . ' 00:00:00 UTC' );
	};
	$in_ids = function ( $ids ) {
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		return $ids ? implode( ',', $ids ) : '0';
	};

	$workshops = array(
		'terrarium'   => array( 'Terrarium Workshop', 120, 55, 'Build a closed glass terrarium with moss, ferns and a little stone path. Jars, soil and plants included.' ),
		'propagation' => array( 'Propagation Bar', 90, 40, 'Take cuttings from our mother plants, learn water and soil propagation, and go home with five starts.' ),
		'kokedama'    => array( 'Kokedama Class', 120, 48, 'Wrap a fern or a pothos in a moss ball and string it up. Bring an apron, it gets muddy.' ),
		'sunflower'   => array( 'Kids Sunflower Morning', 60, 20, 'Ages five and up, with a grown-up. Sow, label and take home a pot of Teddy Bear sunflowers.' ),
	);
	$visits = array(
		'consult' => array( 'In-home Plant Consultation', 60, 75, 'We come to you, look at your light, and help you choose and place the right plants.' ),
		'office'  => array( 'Office Plant Care Visit', 90, 95, 'Watering, pruning, pest checks and a written note for the office manager.' ),
	);

	/*
	 * Amelia.
	 */
	if ( defined( 'AMELIA_VERSION' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'amelia_appointments' ) ) ) {
		$t   = function ( $name ) use ( $wpdb ) {
			return $wpdb->prefix . 'amelia_' . $name;
		};
		$old = $demo->ids( 'bookings_amelia' );
		if ( ! empty( $old['appointments'] ) ) {
			$appts = $in_ids( $old['appointments'] );
			$wpdb->query( "DELETE FROM {$t( 'payments' )} WHERE customerBookingId IN (SELECT id FROM {$t( 'customer_bookings' )} WHERE appointmentId IN ({$appts}))" );
			$wpdb->query( "DELETE FROM {$t( 'customer_bookings' )} WHERE appointmentId IN ({$appts})" );
			$wpdb->query( "DELETE FROM {$t( 'appointments' )} WHERE id IN ({$appts})" );
		}
		if ( ! empty( $old['fields'] ) ) {
			$wpdb->query( "DELETE FROM {$t( 'custom_fields_services' )} WHERE customFieldId IN ({$in_ids( $old['fields'] )})" );
			$wpdb->query( "DELETE FROM {$t( 'custom_fields' )} WHERE id IN ({$in_ids( $old['fields'] )})" );
		}
		if ( ! empty( $old['users'] ) ) {
			$wpdb->query( "DELETE FROM {$t( 'providers_to_services' )} WHERE userId IN ({$in_ids( $old['users'] )})" );
			$wpdb->query( "DELETE FROM {$t( 'providers_to_locations' )} WHERE userId IN ({$in_ids( $old['users'] )})" );
			$wpdb->query( "DELETE FROM {$t( 'providers_to_weekdays' )} WHERE userId IN ({$in_ids( $old['users'] )})" );
			$wpdb->query( "DELETE FROM {$t( 'users' )} WHERE id IN ({$in_ids( $old['users'] )})" );
		}
		foreach ( array( 'services', 'categories', 'locations' ) as $table ) {
			if ( ! empty( $old[ $table ] ) ) {
				$wpdb->query( "DELETE FROM {$t( $table )} WHERE id IN ({$in_ids( $old[ $table ] )})" );
			}
		}
		$demo->forget( 'bookings_amelia' );

		$wpdb->insert( $t( 'locations' ), array( 'status' => 'visible', 'name' => 'Larkspur & Fern', 'description' => 'The shop floor and the workshop table at the back.', 'address' => '112 Station Road, Portland, OR 97214', 'phone' => '+15035550100', 'latitude' => 45.5231, 'longitude' => -122.6765, 'countryPhoneIso' => 'us' ) );
		$location = (int) $wpdb->insert_id;
		$demo->remember( 'bookings_amelia', 'locations', array( $location ) );

		$cats = array();
		foreach ( array( 'Workshops' => '#3f7d4e', 'Consultations' => '#b5835a' ) as $i => $color ) {
			$wpdb->insert( $t( 'categories' ), array( 'status' => 'visible', 'name' => $i, 'position' => count( $cats ) + 2, 'color' => $color ) );
			$cats[ $i ] = (int) $wpdb->insert_id;
		}
		$demo->remember( 'bookings_amelia', 'categories', array_values( $cats ) );

		$users = array();
		$staff = array();
		foreach ( array( 'priya' => 'Runs the Saturday workshops.', 'june' => 'Owner. Home consultations.', 'mateo' => 'Office care visits and repotting.' ) as $slug => $note ) {
			$p = $demo->person( $slug );
			$wpdb->insert( $t( 'users' ), array( 'status' => 'visible', 'type' => 'provider', 'externalId' => $p['id'], 'firstName' => $p['first'], 'lastName' => $p['last'], 'email' => $p['email'], 'phone' => $e164( $p['phone'] ), 'countryPhoneIso' => 'us', 'note' => $note, 'description' => $note, 'timeZone' => 'America/Los_Angeles' ) );
			$staff[ $slug ] = (int) $wpdb->insert_id;
			$users[]        = $staff[ $slug ];
			$wpdb->insert( $t( 'providers_to_locations' ), array( 'userId' => $staff[ $slug ], 'locationId' => $location ) );
			// Tuesday to Saturday, ten to five.
			foreach ( array( 2, 3, 4, 5, 6 ) as $day ) {
				$wpdb->insert( $t( 'providers_to_weekdays' ), array( 'userId' => $staff[ $slug ], 'dayIndex' => $day, 'startTime' => '10:00:00', 'endTime' => '17:00:00' ) );
			}
		}
		$demo->remember( 'bookings_amelia', 'users', $users );

		$services = array();
		$position = 1;
		foreach ( $workshops + $visits as $key => $s ) {
			$workshop = isset( $workshops[ $key ] );
			$wpdb->insert(
				$t( 'services' ),
				array(
					'name'           => $s[0],
					'description'    => '<p>' . $s[3] . '</p>',
					'color'          => $workshop ? '#3f7d4e' : '#b5835a',
					'price'          => $s[2],
					'status'         => 'visible',
					'categoryId'     => $cats[ $workshop ? 'Workshops' : 'Consultations' ],
					'minCapacity'    => 1,
					'maxCapacity'    => $workshop ? 10 : 1,
					'duration'       => $s[1] * MINUTE_IN_SECONDS,
					'timeBefore'     => 0,
					'timeAfter'      => $workshop ? 0 : 1800,
					'bringingAnyone' => $workshop ? 1 : 0,
					'priority'       => 'least_expensive',
					'position'       => $position++,
					'show'           => 1,
					'aggregatedPrice' => 1,
				)
			);
			$services[ $key ] = (int) $wpdb->insert_id;
			foreach ( $workshop ? array( 'priya' ) : ( 'office' === $key ? array( 'mateo' ) : array( 'june', 'mateo' ) ) as $slug ) {
				$wpdb->insert( $t( 'providers_to_services' ), array( 'userId' => $staff[ $slug ], 'serviceId' => $services[ $key ], 'price' => $s[2], 'minCapacity' => 1, 'maxCapacity' => $workshop ? 10 : 1 ) );
			}
		}
		$demo->remember( 'bookings_amelia', 'services', array_values( $services ) );

		$fields = array();
		foreach ( array( 'Anything we should know?' => 'text-area', 'How did you hear about us?' => 'text' ) as $label => $type ) {
			$wpdb->insert( $t( 'custom_fields' ), array( 'label' => $label, 'type' => $type, 'required' => 0, 'position' => count( $fields ) + 1, 'allServices' => 1, 'width' => 100, 'saveType' => 'bookings' ) );
			$fields[ $label ] = (int) $wpdb->insert_id;
		}
		$demo->remember( 'bookings_amelia', 'fields', array_values( $fields ) );

		// who, service, staff, when, status, people, internal note, their note, heard about us
		$rows = array(
			array( 'olivia', 'terrarium', 'priya', array( 'sat', -3, 10, 0 ), 'approved', 2, 'Paid for two at the door.', 'Bringing my sister, it is her birthday.', 'Instagram' ),
			array( 'marcus', 'terrarium', 'priya', array( 'sat', -3, 10, 0 ), 'no-show', 1, 'No reply to the reminder text. Offered him a seat next month.', '', 'A friend' ),
			array( 'nadia', 'consult', 'june', array( 'day', 16, 13, 0 ), 'approved', 1, 'Fiddle leaf fig dropping leaves by a north window. Moved it to the bedroom, sold her a grow light.', 'My fig is sulking and I do not know why.', 'Google' ),
			array( 'owen', 'office', 'mateo', array( 'day', 12, 9, 0 ), 'approved', 1, 'Harrison Street Dental: fourteen plants in the waiting room. Agreed a monthly visit.', 'Front desk opens at 8:30, ask for Dana.', 'Walked past the shop' ),
			array( 'aisha', 'propagation', 'priya', array( 'sat', -2, 13, 0 ), 'canceled', 1, 'Canceled two days ahead, refunded in full.', '', 'The newsletter' ),
			array( 'kenji', 'kokedama', 'priya', array( 'sat', -1, 10, 0 ), 'approved', 1, '', 'Can I make one with a small fern?', 'The newsletter' ),
			array( 'grace', 'kokedama', 'priya', array( 'sat', -1, 10, 0 ), 'rejected', 1, 'Class was full when this came in. Put her on the list for the next one.', '', 'Instagram' ),
			array( 'ruth', 'office', 'mateo', array( 'day', 0, 15, 0 ), 'approved', 1, 'Lovejoy Hall lobby planters before the Friday wedding. Bring the long-spout can.', 'Use the loading door on Lovejoy Street.', 'A friend' ),
			array( 'samuel', 'consult', 'june', array( 'day', -2, 11, 0 ), 'pending', 1, '', 'Looking at a shade wall for the back of the Alberta Street shop.', 'We buy wholesale from you' ),
			array( 'sofia', 'terrarium', 'priya', array( 'sat', 0, 10, 0 ), 'approved', 1, '', '', 'Instagram' ),
			array( 'liam', 'terrarium', 'priya', array( 'sat', 0, 10, 0 ), 'pending', 2, 'Waiting on payment for the second seat.', 'Two of us, first time making one.', 'Google' ),
			array( 'emma', 'sunflower', 'priya', array( 'sat', 1, 9, 30 ), 'approved', 3, '', 'Two kids, six and eight. The younger one is very excited.', 'School newsletter' ),
			array( 'mia', 'propagation', 'priya', array( 'sat', 2, 13, 0 ), 'pending', 1, '', 'Do I need to bring my own jars?', 'The newsletter' ),
			array( 'daniel', 'consult', 'june', array( 'day', -9, 10, 0 ), 'canceled', 1, 'Moving apartments, will rebook after.', '', 'Google' ),
		);
		$customers = array();
		$appts     = array();
		foreach ( $rows as $i => $row ) {
			list( $who, $service, $by, $when, $status, $people, $internal, $theirs, $heard ) = $row;
			if ( ! isset( $customers[ $who ] ) ) {
				$p = $demo->person( $who );
				$wpdb->insert( $t( 'users' ), array( 'status' => 'visible', 'type' => 'customer', 'externalId' => $p['id'] ? $p['id'] : null, 'firstName' => $p['first'], 'lastName' => $p['last'], 'email' => $p['email'], 'phone' => $e164( $p['phone'] ), 'countryPhoneIso' => 'us', 'note' => $p['company'] ) );
				$customers[ $who ] = (int) $wpdb->insert_id;
				$users[]           = $customers[ $who ];
				$demo->remember( 'bookings_amelia', 'users', $users );
			}
			$start = $at( $when );
			$len   = isset( $workshops[ $service ] ) ? $workshops[ $service ][1] : $visits[ $service ][1];
			$price = isset( $workshops[ $service ] ) ? $workshops[ $service ][2] : $visits[ $service ][2];
			$wpdb->insert(
				$t( 'appointments' ),
				array(
					'status'             => $status,
					'bookingStart'       => $demo->gmt( $start ),
					'bookingEnd'         => $demo->gmt( $start + $len * MINUTE_IN_SECONDS ),
					'notifyParticipants' => 1,
					'serviceId'          => $services[ $service ],
					'providerId'         => $staff[ $by ],
					'locationId'         => $location,
					'internalNotes'      => $internal,
				)
			);
			$appt    = (int) $wpdb->insert_id;
			$appts[] = $appt;
			$demo->remember( 'bookings_amelia', 'appointments', $appts );
			$answers = array();
			if ( '' !== $theirs ) {
				$answers[ $fields['Anything we should know?'] ] = array( 'label' => 'Anything we should know?', 'value' => $theirs, 'type' => 'text-area' );
			}
			$answers[ $fields['How did you hear about us?'] ] = array( 'label' => 'How did you hear about us?', 'value' => $heard, 'type' => 'text' );
			$made = $booked( $start, 3 + $i % 9 );
			$wpdb->insert(
				$t( 'customer_bookings' ),
				array(
					'appointmentId'   => $appt,
					'customerId'      => $customers[ $who ],
					'status'          => $status,
					'price'           => $price,
					'persons'         => $people,
					'token'           => substr( md5( 'amelia' . $i ), 0, 10 ),
					'customFields'    => wp_json_encode( $answers ),
					'aggregatedPrice' => 1,
					'duration'        => $len * MINUTE_IN_SECONDS,
					'created'         => $demo->gmt( $made ),
				)
			);
			$paid = in_array( $status, array( 'approved', 'no-show' ), true ) && 'liam' !== $who;
			$wpdb->insert(
				$t( 'payments' ),
				array(
					'customerBookingId' => (int) $wpdb->insert_id,
					'amount'            => $paid ? $price * $people : 0,
					'dateTime'          => $demo->gmt( $made ),
					'status'            => $paid ? 'paid' : ( 'canceled' === $status && 'aisha' === $who ? 'refunded' : 'pending' ),
					'gateway'           => 'onSite',
					'entity'            => 'appointment',
					'created'           => $demo->gmt( $made ),
				)
			);
		}
		$demo->log( count( $appts ) . ' Amelia appointments' );
	} else {
		$demo->log( 'Amelia is not active: no Amelia appointments' );
	}

	/*
	 * LatePoint.
	 */
	if ( defined( 'LATEPOINT_VERSION' ) && class_exists( 'OsCustomerModel' ) ) {
		$t   = function ( $name ) use ( $wpdb ) {
			return $wpdb->prefix . 'latepoint_' . $name;
		};
		$old = $demo->ids( 'bookings_latepoint' );
		$map = array(
			'registrations' => 'event_registrations',
			'events'        => 'events',
			'bookings'      => 'bookings',
			'items'         => 'order_items',
			'orders'        => 'orders',
			'links'         => 'agents_services',
			'services'      => 'services',
			'categories'    => 'service_categories',
			'agents'        => 'agents',
			'customers'     => 'customers',
		);
		foreach ( $map as $key => $table ) {
			if ( ! empty( $old[ $key ] ) ) {
				$wpdb->query( "DELETE FROM {$t( $table )} WHERE id IN ({$in_ids( $old[ $key ] )})" );
			}
		}
		if ( ! empty( $old['bookings'] ) ) {
			$wpdb->query( "DELETE FROM {$t( 'booking_meta' )} WHERE object_id IN ({$in_ids( $old['bookings'] )})" );
		}
		$demo->forget( 'bookings_latepoint' );
		$keep = function ( $key, $id ) use ( $demo ) {
			$ids   = $demo->id( 'bookings_latepoint', $key );
			$ids   = is_array( $ids ) ? $ids : array();
			$ids[] = (int) $id;
			$demo->remember( 'bookings_latepoint', $key, $ids );
			return (int) $id;
		};
		$stamp = function ( $table, $id, $ts ) use ( $wpdb, $t, $demo ) {
			$wpdb->update( $t( $table ), array( 'created_at' => $demo->local( $ts ), 'updated_at' => $demo->local( $ts ) ), array( 'id' => $id ) );
		};

		OsSettingsHelper::save_setting_by_name( 'enable_events_functionality', 'on' );
		// The location LatePoint made on activation becomes the shop.
		$location = (int) $wpdb->get_var( "SELECT id FROM {$t( 'locations' )} ORDER BY id ASC LIMIT 1" );
		if ( $location ) {
			$wpdb->update( $t( 'locations' ), array( 'name' => 'Larkspur & Fern', 'full_address' => '112 Station Road, Portland, OR 97214' ), array( 'id' => $location ) );
		}

		$agents = array();
		foreach ( array( 'june' => 'Owner', 'mateo' => 'Shop manager', 'priya' => 'Workshops' ) as $slug => $title ) {
			$p                     = $demo->person( $slug );
			$agent                 = new OsAgentModel();
			$agent->first_name     = $p['first'];
			$agent->last_name      = $p['last'];
			$agent->display_name   = $p['first'];
			$agent->title          = $title;
			$agent->email          = $p['email'];
			$agent->phone          = $e164( $p['phone'] );
			$agent->status         = LATEPOINT_AGENT_STATUS_ACTIVE;
			$agent->bio            = wp_specialchars_decode( get_user_meta( $p['id'], 'description', true ), ENT_QUOTES );
			if ( ! $agent->save() ) {
				WP_CLI::warning( 'LatePoint agent ' . $slug . ': ' . wp_json_encode( $agent->get_error_messages() ) );
				continue;
			}
			$agents[ $slug ] = $keep( 'agents', $agent->id );
		}

		$cats = array();
		foreach ( array( 'Consultations', 'Plant care' ) as $i => $name ) {
			$cat               = new OsServiceCategoryModel();
			$cat->name         = $name;
			$cat->order_number = $i + 1;
			$cat->save();
			$cats[ $name ] = $keep( 'categories', $cat->id );
		}
		// key => name, minutes, price, category, agents, colour, description
		$lp_services = array(
			'consult' => array( 'In-home Plant Consultation', 60, 75, 'Consultations', array( 'june', 'mateo' ), '#b5835a', $visits['consult'][3] ),
			'video'   => array( 'Plant Clinic Video Call', 30, 30, 'Consultations', array( 'june' ), '#6f8fb5', 'Show us the plant on camera and we will work out what it needs. For customers outside Portland.' ),
			'office'  => array( 'Office Plant Care Visit', 90, 95, 'Plant care', array( 'mateo' ), '#3f7d4e', $visits['office'][3] ),
			'repot'   => array( 'Repotting Appointment', 30, 25, 'Plant care', array( 'mateo' ), '#8a6f4e', 'Bring your plant in and we repot it while you wait. Soil and a pot from the shelf are extra.' ),
		);
		$services = array();
		$order    = 1;
		foreach ( $lp_services as $key => $s ) {
			$svc                   = new OsServiceModel();
			$svc->name             = $s[0];
			$svc->short_description = $s[6];
			$svc->duration         = $s[1];
			$svc->charge_amount    = $s[2];
			$svc->price_min        = $s[2];
			$svc->price_max        = $s[2];
			$svc->category_id      = $cats[ $s[3] ];
			$svc->bg_color         = $s[5];
			$svc->capacity_min     = 1;
			$svc->capacity_max     = 1;
			$svc->order_number     = $order++;
			$svc->status           = LATEPOINT_SERVICE_STATUS_ACTIVE;
			$svc->visibility       = LATEPOINT_SERVICE_VISIBILITY_VISIBLE;
			if ( ! $svc->save() ) {
				WP_CLI::warning( 'LatePoint service ' . $key . ': ' . wp_json_encode( $svc->get_error_messages() ) );
				continue;
			}
			$services[ $key ] = $keep( 'services', $svc->id );
			foreach ( $s[4] as $slug ) {
				$wpdb->insert( $t( 'agents_services' ), array( 'agent_id' => $agents[ $slug ], 'service_id' => $services[ $key ], 'location_id' => $location, 'created_at' => $demo->local( $demo->now ), 'updated_at' => $demo->local( $demo->now ) ) );
				$keep( 'links', $wpdb->insert_id );
			}
		}

		$customers = array();
		$customer  = function ( $who ) use ( &$customers, $demo, $e164, $keep, $stamp ) {
			if ( ! isset( $customers[ $who ] ) ) {
				$p              = $demo->person( $who );
				$c              = new OsCustomerModel();
				$c->first_name  = $p['first'];
				$c->last_name   = $p['last'];
				$c->email       = $p['email'];
				$c->phone       = $e164( $p['phone'] );
				$c->is_guest    = false;
				$c->admin_notes = $p['company'] ? $p['company'] : '';
				if ( ! $c->save() ) {
					WP_CLI::warning( 'LatePoint customer ' . $who . ': ' . wp_json_encode( $c->get_error_messages() ) );
					return 0;
				}
				$customers[ $who ] = $keep( 'customers', $c->id );
				$stamp( 'customers', $c->id, $demo->ago( 40 - count( $customers ), 11, 0 ) );
			}
			return $customers[ $who ];
		};
		// An order and its line, as LatePoint 5 makes one for every booking and registration.
		$order_for = function ( $cid, $total, $paid, $made, $variant, $data, $comment = '' ) use ( $demo, $keep, $stamp ) {
			$o                     = new OsOrderModel();
			$o->customer_id        = $cid;
			$o->subtotal           = $total;
			$o->total              = $total;
			$o->status             = LATEPOINT_ORDER_STATUS_OPEN;
			$o->fulfillment_status = LATEPOINT_ORDER_FULFILLMENT_STATUS_NOT_FULFILLED;
			$o->payment_status     = $paid;
			$o->confirmation_code  = strtoupper( substr( md5( 'lp-order' . $cid . $made ), 0, 7 ) );
			$o->customer_comment   = $comment;
			$o->save();
			$keep( 'orders', $o->id );
			$stamp( 'orders', $o->id, $made );
			$item           = new OsOrderItemModel();
			$item->order_id = $o->id;
			$item->variant  = $variant;
			$item->item_data = wp_json_encode( $data );
			$item->subtotal = $total;
			$item->total    = $total;
			$item->save();
			$keep( 'items', $item->id );
			$stamp( 'order_items', $item->id, $made );
			return array( (int) $o->id, (int) $item->id );
		};

		// who, service, agent, when, status, comment
		$rows = array(
			array( 'ethan', 'video', 'june', array( 'day', 18, 17, 0 ), 'completed', 'My calathea leaves are curling at the edges.' ),
			array( 'olivia', 'repot', 'mateo', array( 'day', 15, 11, 0 ), 'completed', 'Monstera has roots coming out the bottom.' ),
			array( 'kenji', 'consult', 'mateo', array( 'day', 11, 14, 0 ), 'no_show', '' ),
			array( 'chloe', 'video', 'june', array( 'day', 8, 16, 0 ), 'completed', 'Is it too late to bring the citrus tree inside?' ),
			array( 'owen', 'office', 'mateo', array( 'day', 5, 9, 0 ), 'completed', 'Monthly visit for the waiting room.' ),
			array( 'lucas', 'video', 'june', array( 'day', 3, 18, 0 ), 'cancelled', '' ),
			array( 'nadia', 'repot', 'mateo', array( 'day', 0, 11, 30 ), 'approved', 'Two snake plants, one has split its pot.' ),
			array( 'samuel', 'consult', 'june', array( 'day', -1, 10, 0 ), 'approved', 'Planning the back room of Ortiz Florals as a plant corner.' ),
			array( 'isla', 'video', 'june', array( 'day', -3, 12, 0 ), 'pending', 'Succulents going soft at the base.' ),
			array( 'ruth', 'office', 'mateo', array( 'day', -6, 9, 0 ), 'approved', 'Lobby planters and the two big ficus on the stage.' ),
			array( 'noah', 'video', 'june', array( 'day', -10, 17, 30 ), 'payment_pending', '' ),
			array( 'ben', 'video', 'june', array( 'day', -15, 15, 0 ), 'pending', 'Help choosing plants for a dark basement apartment.' ),
		);
		$count = 0;
		foreach ( $rows as $i => $row ) {
			list( $who, $service, $by, $when, $status, $comment ) = $row;
			$cid = $customer( $who );
			if ( ! $cid || empty( $services[ $service ] ) ) {
				continue;
			}
			$start = $at( $when );
			$len   = $lp_services[ $service ][1];
			$price = $lp_services[ $service ][2];
			$made  = $booked( $start, 2 + $i % 8 );
			$paid  = in_array( $status, array( 'completed', 'approved', 'no_show' ), true ) ? LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID : LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID;
			list( $order_id, $item_id ) = $order_for( $cid, $price, $paid, $made, LATEPOINT_ITEM_VARIANT_BOOKING, array( 'service_id' => $services[ $service ], 'agent_id' => $agents[ $by ] ), $comment );
			$wpdb->insert(
				$t( 'bookings' ),
				array(
					'booking_code'       => strtoupper( substr( md5( 'lp' . $i ), 0, 7 ) ),
					'start_date'         => wp_date( 'Y-m-d', $start ),
					'end_date'           => wp_date( 'Y-m-d', $start + $len * MINUTE_IN_SECONDS ),
					'start_time'         => (int) wp_date( 'G', $start ) * 60 + (int) wp_date( 'i', $start ),
					'end_time'           => (int) wp_date( 'G', $start + $len * MINUTE_IN_SECONDS ) * 60 + (int) wp_date( 'i', $start + $len * MINUTE_IN_SECONDS ),
					'start_datetime_utc' => $demo->gmt( $start ),
					'end_datetime_utc'   => $demo->gmt( $start + $len * MINUTE_IN_SECONDS ),
					'buffer_before'      => 0,
					'buffer_after'       => 0,
					'duration'           => $len,
					'status'             => $status,
					'customer_id'        => $cid,
					'service_id'         => $services[ $service ],
					'agent_id'           => $agents[ $by ],
					'location_id'        => $location,
					'order_item_id'      => $item_id,
					'total_attendees'    => 1,
					'customer_timezone'  => 'America/Los_Angeles',
					'server_timezone'    => 'America/Los_Angeles',
					'created_at'         => $demo->local( $made ),
					'updated_at'         => $demo->local( $made ),
				)
			);
			$keep( 'bookings', $wpdb->insert_id );
			++$count;
		}

		// The workshops as events: name, Saturday, start, minutes, capacity, price, registrations (who, seats, status, payment, note).
		$events = array(
			array( 'terrarium', -1, 10, 0, 10, array( array( 'olivia', 2, 'confirmed', 'paid', 'Her sister is coming too.' ), array( 'marcus', 1, 'confirmed', 'paid', '' ), array( 'grace', 1, 'cancelled', 'refunded', 'Could not get a flight that weekend.' ) ) ),
			array( 'propagation', 0, 13, 0, 8, array( array( 'kenji', 1, 'confirmed', 'paid', '' ), array( 'sofia', 2, 'confirmed', 'paid', 'Bringing a friend who has never kept a plant alive.' ), array( 'aisha', 1, 'pending', 'not_paid', '' ) ) ),
			array( 'sunflower', 1, 9, 30, 12, array( array( 'emma', 3, 'confirmed', 'paid', 'Two kids, six and eight.' ), array( 'zoe', 2, 'confirmed', 'paid', '' ), array( 'liam', 1, 'cancelled', 'refunded', '' ) ) ),
			array( 'kokedama', 2, 10, 0, 8, array( array( 'mia', 1, 'confirmed', 'paid', '' ), array( 'daniel', 1, 'confirmed', 'paid', 'Can I use a fern?' ) ) ),
		);
		$regs = 0;
		if ( class_exists( 'OsEventModel' ) && class_exists( 'OsEventRegistrationModel' ) ) {
			foreach ( $events as $n => $ev ) {
				list( $key, $week, $hour, $minute, $capacity, $people ) = $ev;
				$w     = $workshops[ $key ];
				$start = $at( array( 'sat', $week, $hour, $minute ) );
				$e                         = new OsEventModel();
				$e->name                   = $w[0];
				$e->summary                = $w[3];
				$e->description            = '<p>' . $w[3] . '</p><p>At the workshop table at the back of the shop. Coffee and tea on us.</p>';
				$e->agent_id               = $agents['priya'];
				$e->location_id            = $location;
				$e->event_type             = LATEPOINT_EVENT_TYPE_IN_PERSON;
				$e->start_datetime_utc     = $demo->gmt( $start );
				$e->end_datetime_utc       = $demo->gmt( $start + $w[1] * MINUTE_IN_SECONDS );
				$e->timezone               = 'America/Los_Angeles';
				$e->capacity               = $capacity;
				$e->price                  = $w[2];
				$e->registration_start_utc = $demo->gmt( $start - 30 * DAY_IN_SECONDS );
				$e->registration_end_utc   = $demo->gmt( $start - HOUR_IN_SECONDS );
				$e->status                 = LATEPOINT_EVENT_STATUS_PUBLISHED;
				$e->visibility             = 'public';
				$e->order_number           = $n + 1;
				if ( ! $e->save() ) {
					WP_CLI::warning( 'LatePoint event ' . $key . ': ' . wp_json_encode( $e->get_error_messages() ) );
					continue;
				}
				$keep( 'events', $e->id );
				$stamp( 'events', $e->id, $demo->ago( 35 - $n * 3, 9, 15 ) );
				foreach ( $people as $j => $reg ) {
					list( $who, $seats, $status, $payment, $note ) = $reg;
					$cid  = $customer( $who );
					$made = $booked( $start, 4 + ( $n + $j ) % 9 );
					list( $order_id, $item_id ) = $order_for( $cid, $w[2] * $seats, 'paid' === $payment ? LATEPOINT_ORDER_PAYMENT_STATUS_FULLY_PAID : ( 'refunded' === $payment ? LATEPOINT_ORDER_PAYMENT_STATUS_REFUNDED : LATEPOINT_ORDER_PAYMENT_STATUS_NOT_PAID ), $made, LATEPOINT_ITEM_VARIANT_EVENT_REGISTRATION, array( 'event_id' => $e->id, 'quantity' => $seats ), $note );
					$r                    = new OsEventRegistrationModel();
					$r->registration_code = strtoupper( substr( md5( 'lp-reg' . $n . $j ), 0, 8 ) );
					$r->event_id          = $e->id;
					$r->customer_id       = $cid;
					$r->order_id          = $order_id;
					$r->order_item_id     = $item_id;
					$r->quantity          = $seats;
					$r->status            = $status;
					$r->payment_status    = $payment;
					$r->notes             = $note;
					if ( ! $r->save() ) {
						WP_CLI::warning( 'LatePoint registration ' . $who . ': ' . wp_json_encode( $r->get_error_messages() ) );
						continue;
					}
					$keep( 'registrations', $r->id );
					$stamp( 'event_registrations', $r->id, $made );
					++$regs;
				}
			}
		}
		$demo->log( $count . ' LatePoint bookings, ' . $regs . ' event registrations' );
	} else {
		$demo->log( 'LatePoint is not active: no LatePoint bookings' );
	}

	/*
	 * Bookly.
	 */
	if ( class_exists( '\Bookly\Lib\Entities\CustomerAppointment' ) ) {
		$t   = function ( $name ) use ( $wpdb ) {
			return $wpdb->prefix . 'bookly_' . $name;
		};
		$old = $demo->ids( 'bookings_bookly' );
		if ( ! empty( $old['appointments'] ) ) {
			$wpdb->query( "DELETE FROM {$t( 'customer_appointments' )} WHERE appointment_id IN ({$in_ids( $old['appointments'] )})" );
			$wpdb->query( "DELETE FROM {$t( 'appointments' )} WHERE id IN ({$in_ids( $old['appointments'] )})" );
		}
		if ( ! empty( $old['staff'] ) ) {
			foreach ( array( 'staff_services', 'staff_schedule_items', 'holidays' ) as $table ) {
				$wpdb->query( "DELETE FROM {$t( $table )} WHERE staff_id IN ({$in_ids( $old['staff'] )})" );
			}
		}
		foreach ( array( 'staff' => 'staff', 'services' => 'services', 'categories' => 'categories', 'customers' => 'customers' ) as $key => $table ) {
			if ( ! empty( $old[ $key ] ) ) {
				$wpdb->query( "DELETE FROM {$t( $table )} WHERE id IN ({$in_ids( $old[ $key ] )})" );
			}
		}
		$demo->forget( 'bookings_bookly' );
		$keep = function ( $key, $id ) use ( $demo ) {
			$ids   = $demo->id( 'bookings_bookly', $key );
			$ids   = is_array( $ids ) ? $ids : array();
			$ids[] = (int) $id;
			$demo->remember( 'bookings_bookly', $key, $ids );
			return (int) $id;
		};

		$cats = array();
		foreach ( array( 'Workshops', 'Consultations' ) as $i => $name ) {
			$cat = new \Bookly\Lib\Entities\Category();
			$cat->setName( $name )->setPosition( $i + 1 )->save();
			$cats[ $name ] = $keep( 'categories', $cat->getId() );
		}
		$staff  = array();
		$colors = array( 'priya' => '#3f7d4e', 'june' => '#b5835a', 'mateo' => '#6f8fb5' );
		foreach ( $colors as $slug => $color ) {
			$p = $demo->person( $slug );
			$s = new \Bookly\Lib\Entities\Staff();
			$s->setFullName( $p['name'] )->setWpUserId( $p['id'] )->setEmail( $p['email'] )->setPhone( $e164( $p['phone'] ) )
				->setTimeZone( 'America/Los_Angeles' )->setInfo( wp_specialchars_decode( get_user_meta( $p['id'], 'description', true ), ENT_QUOTES ) )
				->setVisibility( 'public' )->setPosition( count( $staff ) + 1 )->setColor( $color );
			$s->save();
			$staff[ $slug ] = $keep( 'staff', $s->getId() );
		}
		$services = array();
		foreach ( $workshops + $visits as $key => $w ) {
			$workshop = isset( $workshops[ $key ] );
			$s        = new \Bookly\Lib\Entities\Service();
			$s->setTitle( $w[0] )->setInfo( $w[3] )->setDuration( $w[1] * MINUTE_IN_SECONDS )->setPrice( $w[2] )
				->setColor( $workshop ? '#3f7d4e' : '#b5835a' )->setCategoryId( $cats[ $workshop ? 'Workshops' : 'Consultations' ] )
				->setCapacityMin( 1 )->setCapacityMax( $workshop ? 10 : 1 )->setVisibility( 'public' )->setPosition( count( $services ) + 1 );
			$s->save();
			$services[ $key ] = $keep( 'services', $s->getId() );
			foreach ( $workshop ? array( 'priya' ) : ( 'office' === $key ? array( 'mateo' ) : array( 'june', 'mateo' ) ) as $slug ) {
				$link = new \Bookly\Lib\Entities\StaffService();
				$link->setStaffId( $staff[ $slug ] )->setServiceId( $services[ $key ] )->setPrice( $w[2] )->setDeposit( '100%' )
					->setCapacityMin( 1 )->setCapacityMax( $workshop ? 10 : 1 )->save();
			}
		}

		// who, service, staff, when, status, people, their note, internal note
		$rows = array(
			array( 'chloe', 'propagation', 'priya', array( 'sat', -3, 13, 0 ), 'done', 1, '', '' ),
			array( 'ethan', 'propagation', 'priya', array( 'sat', -3, 13, 0 ), 'done', 1, 'In town for a conference that week.', '' ),
			array( 'noah', 'terrarium', 'priya', array( 'sat', -2, 10, 0 ), 'done', 2, 'Anniversary present for my partner.', 'Asked about a bigger jar next time.' ),
			array( 'isla', 'terrarium', 'priya', array( 'sat', -2, 10, 0 ), 'rejected', 1, '', 'Booked after the class sold out.' ),
			array( 'ruth', 'consult', 'june', array( 'day', 9, 14, 0 ), 'done', 1, 'Planning the plants for the hall foyer.', 'Wants a quote for twelve large planters.' ),
			array( 'samuel', 'office', 'mateo', array( 'day', 6, 8, 30 ), 'done', 1, '', 'Ortiz Florals back room. Fungus gnats in the ferns, treated.' ),
			array( 'zoe', 'sunflower', 'priya', array( 'sat', -1, 9, 30 ), 'done', 2, 'My son Kofi, he is six.', '' ),
			array( 'ben', 'sunflower', 'priya', array( 'sat', -1, 9, 30 ), 'cancelled', 1, '', 'Kid had a cold.' ),
			array( 'olivia', 'consult', 'june', array( 'day', 0, 13, 0 ), 'approved', 1, 'New apartment, lots of east light.', '' ),
			array( 'lucas', 'kokedama', 'priya', array( 'sat', 0, 10, 0 ), 'approved', 1, '', '' ),
			array( 'mia', 'kokedama', 'priya', array( 'sat', 0, 10, 0 ), 'pending', 1, 'Is there parking nearby?', '' ),
			array( 'owen', 'office', 'mateo', array( 'day', -4, 9, 0 ), 'approved', 1, 'Monthly visit.', 'Bring neem oil for the scale on the ficus.' ),
			array( 'daniel', 'terrarium', 'priya', array( 'sat', 1, 10, 0 ), 'pending', 2, 'Two seats please.', '' ),
			array( 'emma', 'terrarium', 'priya', array( 'sat', 1, 10, 0 ), 'approved', 1, '', '' ),
			array( 'aisha', 'propagation', 'priya', array( 'sat', 2, 13, 0 ), 'cancelled', 1, '', 'Rebooked on Amelia by mistake, canceled here.' ),
		);
		$customers = array();
		$count     = 0;
		foreach ( $rows as $i => $row ) {
			list( $who, $service, $by, $when, $status, $people, $theirs, $internal ) = $row;
			if ( ! isset( $customers[ $who ] ) ) {
				$p = $demo->person( $who );
				$c = new \Bookly\Lib\Entities\Customer();
				$c->setWpUserId( $p['id'] ? $p['id'] : null )->setFirstName( $p['first'] )->setLastName( $p['last'] )->setFullName( $p['name'] )
					->setEmail( $p['email'] )->setPhone( $e164( $p['phone'] ) )->setNotes( $p['company'] )
					->setCountry( 'US' )->setState( $p['state'] )->setCity( $p['city'] )->setPostcode( $p['postcode'] )->setStreet( $p['street'] )
					->setCreatedAt( $demo->local( $demo->ago( 60 - $i, 12, 0 ) ) );
				$c->save();
				$customers[ $who ] = $keep( 'customers', $c->getId() );
			}
			$start = $at( $when );
			$w     = isset( $workshops[ $service ] ) ? $workshops[ $service ] : $visits[ $service ];
			$made  = $demo->local( $booked( $start, 3 + $i % 7 ) );
			$a     = new \Bookly\Lib\Entities\Appointment();
			$a->setStaffId( $staff[ $by ] )->setServiceId( $services[ $service ] )
				->setStartDate( $demo->local( $start ) )->setEndDate( $demo->local( $start + $w[1] * MINUTE_IN_SECONDS ) )
				->setInternalNote( $internal )->setCreatedAt( $made )->setUpdatedAt( $made );
			$a->save();
			$keep( 'appointments', $a->getId() );
			$ca = new \Bookly\Lib\Entities\CustomerAppointment();
			$ca->setCustomerId( $customers[ $who ] )->setAppointmentId( $a->getId() )->setStatus( $status )->setStatusChangedAt( $made )
				->setNumberOfPersons( $people )->setNotes( $theirs )->setCreatedFrom( 'frontend' )->setTimeZone( 'America/Los_Angeles' )
				->setCreatedAt( $made )->setUpdatedAt( $made );
			$ca->save();
			++$count;
		}
		// Every entity save writes a row to Bookly's own log, signed by this
		// script. Those rows record the seeding, not the shop.
		$wpdb->query( "DELETE FROM {$t( 'log' )} WHERE ref LIKE '%EvalFile_Command%'" );
		$demo->log( $count . ' Bookly appointments' );
	} else {
		$demo->log( 'Bookly is not active: no Bookly appointments' );
	}

	/*
	 * The Jet plugins book posts. JetEngine keeps the post types; the posts
	 * are made again each run.
	 */
	$jet_types = array(
		'lf_rental'     => array( 'Rentals', 'Rental', 'dashicons-store' ),
		'lf_service'    => array( 'Care Services', 'Care Service', 'dashicons-carrot' ),
		'lf_specialist' => array( 'Specialists', 'Specialist', 'dashicons-groups' ),
	);
	$jet_posts = function ( $group ) use ( $demo ) {
		foreach ( $demo->ids( $group ) as $id ) {
			if ( is_numeric( $id ) && get_post( $id ) ) {
				wp_delete_post( $id, true );
			}
		}
	};
	$jet_post = function ( $group, $key, $type, $title, $content, $days ) use ( $demo ) {
		$ts = $demo->ago( $days, 10, 0 );
		$id = wp_insert_post(
			array(
				'post_type'     => $type,
				'post_title'    => $title,
				'post_name'     => sanitize_title( $title ),
				'post_content'  => $content,
				'post_status'   => 'publish',
				'post_author'   => $demo->id( 'users', 'june' ),
				'post_date'     => $demo->local( $ts ),
				'post_date_gmt' => $demo->gmt( $ts ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::warning( $title . ': ' . $id->get_error_message() );
			return 0;
		}
		return $demo->remember( $group, $key, $id );
	};
	$jet_ready = function ( $slugs ) use ( $jet_types, $wpdb ) {
		if ( ! function_exists( 'jet_engine' ) || ! is_object( jet_engine()->cpt ) || ! is_object( jet_engine()->cpt->data ) ) {
			return false;
		}
		foreach ( $slugs as $slug ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jet_post_types WHERE slug = %s AND status = 'publish'", $slug ) );
			if ( ! $found ) {
				list( $plural, $singular, $icon ) = $jet_types[ $slug ];
				$data = jet_engine()->cpt->data;
				$data->set_request(
					array(
						'slug'          => $slug,
						'name'          => $plural,
						'singular_name' => $singular,
						'menu_name'     => $plural,
						'all_items'     => 'All ' . $plural,
						'add_new_item'  => 'Add New ' . $singular,
						'edit_item'     => 'Edit ' . $singular,
						'public'        => 'true',
						'publicly_queryable' => 'true',
						'show_ui'       => 'true',
						'show_in_menu'  => 'true',
						'show_in_rest'  => 'true',
						'query_var'     => 'true',
						'rewrite'       => 'true',
						'map_meta_cap'  => 'true',
						'menu_icon'     => $icon,
						'supports'      => array( 'title', 'editor', 'thumbnail' ),
					)
				);
				$data->create_item( false );
				$data->reset_raw_cache();
			}
			// JetEngine registers its types on init: this run registers them itself.
			if ( ! post_type_exists( $slug ) ) {
				register_post_type( $slug, array( 'label' => $jet_types[ $slug ][0], 'public' => true, 'show_in_rest' => true ) );
			}
		}
		return true;
	};

	/*
	 * JetBooking.
	 */
	if ( function_exists( 'jet_abaf' ) && is_object( jet_abaf()->db ) && $jet_ready( array( 'lf_rental' ) ) ) {
		$old = $demo->ids( 'bookings_jet_booking' );
		if ( ! empty( $old['bookings'] ) ) {
			foreach ( (array) $old['bookings'] as $id ) {
				jet_abaf()->db->delete_booking( array( 'booking_id' => (int) $id ) );
			}
		}
		foreach ( array( 'greenhouse', 'bench', 'hotel' ) as $key ) {
			$id = $demo->id( 'bookings_jet_booking', $key );
			if ( $id ) {
				$wpdb->delete( $wpdb->prefix . 'jet_apartment_units', array( 'apartment_id' => $id ) );
			}
		}
		$jet_posts( 'bookings_jet_booking' );
		$demo->forget( 'bookings_jet_booking' );

		$abaf = jet_abaf()->settings;
		$abaf->update( 'apartment_post_type', array( 'lf_rental' ), false );
		$abaf->update( 'booking_period', 'per_nights', false );
		$abaf->update( 'additional_columns', array( array( 'column' => 'guest_name', 'column_label' => 'Name' ), array( 'column' => 'guest_phone', 'column_label' => 'Phone' ), array( 'column' => 'guest_note', 'column_label' => 'Note' ) ), false );
		$abaf->update( 'is_set', true, false );
		$abaf->update( 'hide_setup', true, false );
		$abaf->write();
		$abaf->hook_db_columns();
		$add = array();
		foreach ( array( 'guest_name', 'guest_phone', 'guest_note' ) as $col ) {
			if ( ! jet_abaf()->db->column_exists( $col ) ) {
				$add[ $col ] = 'text';
			}
		}
		jet_abaf()->db->insert_table_columns( $add );

		// key => title, description, nightly price, units
		$spaces = array(
			'greenhouse' => array( 'Greenhouse Room', 'The glass room off the back of the shop, with seating for twenty among the palms. For parties, small weddings and photo shoots. Evening hire, out by ten the next morning.', 180, array() ),
			'bench'      => array( 'Potting Bench', 'A spot at the long potting bench with tools, a hose and bins of mix. For neighbours without a yard who have a balcony to plant up.', 15, array( 'Bench 1', 'Bench 2', 'Bench 3', 'Bench 4' ) ),
			'hotel'      => array( 'Plant Hotel Shelf', 'Going away? Your plants stay with us in the greenhouse, watered and checked every day. One shelf holds about six medium pots.', 6, array( 'Shelf A', 'Shelf B', 'Shelf C', 'Shelf D', 'Shelf E', 'Shelf F' ) ),
		);
		$space_ids = array();
		foreach ( $spaces as $key => $s ) {
			$id = $jet_post( 'bookings_jet_booking', $key, 'lf_rental', $s[0], '<!-- wp:paragraph --><p>' . $s[1] . '</p><!-- /wp:paragraph -->', 120 - count( $space_ids ) * 9 );
			if ( ! $id ) {
				continue;
			}
			update_post_meta( $id, '_apartment_price', $s[2] );
			foreach ( $s[3] as $unit ) {
				jet_abaf()->db->units->insert( array( 'apartment_id' => $id, 'unit_title' => $unit ) );
			}
			$space_ids[ $key ] = $id;
		}

		// who, space, check-in (days ago), nights, status, note
		$rows = array(
			array( 'ruth', 'greenhouse', 20, 1, 'completed', 'Lovejoy Hall staff party, about eighteen people.' ),
			array( 'olivia', 'hotel', 18, 10, 'completed', 'Four pots: monstera, two pothos and a peace lily.' ),
			array( 'liam', 'hotel', 12, 10, 'completed', 'Bonsai juniper, water every other day only.' ),
			array( 'samuel', 'greenhouse', 9, 1, 'cancelled', 'Shop launch party, moved to their own space.' ),
			array( 'isla', 'bench', 6, 2, 'completed', 'Planting up three balcony boxes.' ),
			array( 'owen', 'hotel', 3, 7, 'processing', 'Waiting room plants while the office is repainted.' ),
			array( 'grace', 'greenhouse', 0, 1, 'on-hold', 'Photo shoot for a ceramics catalog. Deposit not in yet.' ),
			array( 'ben', 'bench', -1, 1, 'failed', 'Card declined at checkout.' ),
			array( 'mia', 'bench', -2, 3, 'pending', '' ),
			array( 'zoe', 'hotel', -6, 4, 'refunded', 'Trip canceled, refunded.' ),
			array( 'noah', 'hotel', -5, 14, 'pending', 'Six pots, two of them big. Away two weeks.' ),
			array( 'ruth', 'greenhouse', -12, 1, 'processing', 'Small wedding reception, thirty guests.' ),
		);
		$made = array();
		foreach ( $rows as $i => $row ) {
			list( $who, $space, $in, $nights, $status, $note ) = $row;
			if ( empty( $space_ids[ $space ] ) ) {
				continue;
			}
			$p       = $demo->person( $who );
			$in_ts   = $demo->ago( $in, 12, 0 );
			$booking = array(
				'apartment_id'   => $space_ids[ $space ],
				'status'         => $status,
				'check_in_date'  => $naive_day( $in_ts ),
				'check_out_date' => $naive_day( $in_ts ) + $nights * DAY_IN_SECONDS,
				'user_id'        => $p['id'] ? $p['id'] : 0,
				'user_email'     => $p['email'],
				'guest_name'     => $p['name'],
				'guest_phone'    => $p['phone'],
				'guest_note'     => $note,
			);
			$id = jet_abaf()->db->insert_booking( $booking );
			if ( ! $id ) {
				WP_CLI::warning( "JetBooking: no room for {$who} in {$space}" );
				continue;
			}
			$made[] = (int) $id;
			$demo->remember( 'bookings_jet_booking', 'bookings', $made );
		}
		$demo->log( count( $made ) . ' JetBooking bookings' );
	} else {
		$demo->log( 'JetBooking or JetEngine is not active: no rentals' );
	}

	/*
	 * JetAppointments.
	 */
	if ( class_exists( '\JET_APB\Plugin' ) && class_exists( '\JET_APB\Resources\Appointment_Model' ) && $jet_ready( array( 'lf_service', 'lf_specialist' ) ) ) {
		$apb = \JET_APB\Plugin::instance();
		$old = $demo->ids( 'bookings_jet_apb' );
		if ( ! empty( $old['appointments'] ) ) {
			foreach ( (array) $old['appointments'] as $id ) {
				$apb->db->delete_appointment( (int) $id );
			}
		}
		// Saving an appointment can mark whole days off for the other
		// services of its provider; deleting it leaves those rows behind.
		unset( $old['appointments'] );
		if ( $old ) {
			$posts = $in_ids( $old );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}jet_appointments_excluded WHERE service IN ({$posts}) OR provider IN ({$posts})" );
		}
		$jet_posts( 'bookings_jet_apb' );
		$demo->forget( 'bookings_jet_apb' );

		$apb->settings->update( 'services_cpt', 'lf_service', false );
		$apb->settings->update( 'providers_cpt', 'lf_specialist', false );
		$apb->settings->update( 'is_set', true, false );
		$apb->settings->update( 'hide_setup', true, false );
		$apb->settings->write();

		// key => title, minutes, price, description
		$care = array(
			'consult' => array( 'In-home Plant Consultation', 60, 75, $visits['consult'][3] ),
			'office'  => array( 'Office Plant Care Visit', 90, 95, $visits['office'][3] ),
			'repot'   => array( 'Repotting Appointment', 30, 25, 'Bring your plant in and we repot it while you wait.' ),
			'video'   => array( 'Plant Clinic Video Call', 30, 30, 'Show us the plant on camera and we will work out what it needs.' ),
			'tuneup'  => array( 'Terrarium Tune-up', 30, 20, 'Bring back a terrarium from one of our workshops for a trim, a clean and fresh moss.' ),
		);
		$svc = array();
		foreach ( $care as $key => $c ) {
			$id = $jet_post( 'bookings_jet_apb', 'service-' . $key, 'lf_service', $c[0], '<!-- wp:paragraph --><p>' . $c[3] . '</p><!-- /wp:paragraph -->', 110 - count( $svc ) * 6 );
			if ( $id ) {
				update_post_meta( $id, '_app_price', $c[2] );
				update_post_meta( $id, '_app_duration', $c[1] * MINUTE_IN_SECONDS );
				$svc[ $key ] = $id;
			}
		}
		$pros = array();
		foreach ( array( 'june', 'mateo', 'priya' ) as $slug ) {
			$p  = $demo->person( $slug );
			$id = $jet_post( 'bookings_jet_apb', 'provider-' . $slug, 'lf_specialist', $p['name'], '<!-- wp:paragraph --><p>' . get_user_meta( $p['id'], 'description', true ) . '</p><!-- /wp:paragraph -->', 108 - count( $pros ) * 6 );
			if ( $id ) {
				$pros[ $slug ] = $id;
			}
		}

		// Who offers what: JetAppointments relates services to providers
		// through a JetEngine legacy many-to-many relation, stored as one
		// post meta row per related post on both sides. It registers that
		// relation only on admin screens, and saving an appointment with a
		// provider reads it, so this run registers it too.
		$hash = 'relation_' . md5( 'lf_service' . 'lf_specialist' );
		$offers = array(
			'june'  => array( 'consult', 'video' ),
			'mateo' => array( 'consult', 'office', 'repot' ),
			'priya' => array( 'tuneup' ),
		);
		foreach ( $offers as $slug => $keys ) {
			foreach ( $keys as $key ) {
				if ( ! empty( $pros[ $slug ] ) && ! empty( $svc[ $key ] ) ) {
					add_post_meta( $pros[ $slug ], $hash, $svc[ $key ] );
					add_post_meta( $svc[ $key ], $hash, $pros[ $slug ] );
				}
			}
		}
		$legacy = is_object( jet_engine()->relations ) && isset( jet_engine()->relations->legacy ) ? jet_engine()->relations->legacy : null;
		if ( $legacy && empty( $legacy->_active_relations[ $hash ] ) ) {
			$legacy->set_legacy_relations(
				array(
					array(
						'id'                  => 'item-0',
						'name'                => 'services to providers',
						'post_type_1'         => 'lf_service',
						'post_type_2'         => 'lf_specialist',
						'type'                => 'many_to_many',
						'post_type_1_control' => 1,
						'post_type_2_control' => 1,
						'parent_relation'     => '',
					),
				)
			);
			$legacy->register_instances();
		}
		if ( ! $legacy || empty( $legacy->_active_relations[ $hash ] ) ) {
			WP_CLI::warning( 'JetAppointments: the services to providers relation is not active, appointments may fail to save.' );
		}

		// who, service, specialist, when, status, comment
		$rows = array(
			array( 'olivia', 'consult', 'june', array( 'day', 19, 10, 0 ), 'completed', 'Moving the plants around for winter.' ),
			array( 'owen', 'office', 'mateo', array( 'day', 14, 9, 0 ), 'completed', 'Monthly visit, waiting room and the two operatories.' ),
			array( 'marcus', 'video', 'june', array( 'day', 11, 18, 0 ), 'completed', 'String of pearls is shriveling.' ),
			array( 'kenji', 'repot', 'mateo', array( 'day', 8, 15, 0 ), 'cancelled', '' ),
			array( 'aisha', 'video', 'june', array( 'day', 6, 17, 30 ), 'refunded', 'Call dropped twice, refunded and rebooked.' ),
			array( 'sofia', 'tuneup', 'priya', array( 'day', 4, 11, 0 ), 'completed', 'Moss is browning on one side.' ),
			array( 'lucas', 'video', 'june', array( 'day', 2, 16, 0 ), 'failed', '' ),
			array( 'ruth', 'office', 'mateo', array( 'day', 0, 15, 30 ), 'processing', 'Hall foyer and the green room.' ),
			array( 'samuel', 'consult', 'june', array( 'day', -1, 14, 0 ), 'pending', 'Ideas for hanging plants in the shop window.' ),
			array( 'grace', 'video', 'june', array( 'day', -3, 12, 0 ), 'pending', 'Bird of paradise with split leaves.' ),
			array( 'chloe', 'tuneup', 'priya', array( 'day', -7, 11, 30 ), 'pending', 'Made it at the spring workshop.' ),
			array( 'owen', 'office', 'mateo', array( 'day', -12, 9, 0 ), 'processing', 'Monthly visit.' ),
			array( 'nadia', 'repot', 'mateo', array( 'day', -16, 13, 30 ), 'pending', 'Rubber plant, about four feet tall.' ),
		);
		$made = array();
		foreach ( $rows as $i => $row ) {
			list( $who, $service, $by, $when, $status, $comment ) = $row;
			if ( empty( $svc[ $service ] ) || empty( $pros[ $by ] ) ) {
				continue;
			}
			$p     = $demo->person( $who );
			$start = $at( $when );
			$slot  = $naive( $start );
			$day   = $naive_day( $start );
			$m     = new \JET_APB\Resources\Appointment_Model(
				array(
					'status'           => $status,
					'service'          => $svc[ $service ],
					'provider'         => $pros[ $by ],
					'user_id'          => $p['id'] ? $p['id'] : null,
					'user_name'        => $p['name'],
					'user_email'       => $p['email'],
					'date'             => $day,
					'date_end'         => $day,
					'slot'             => $slot,
					'slot_end'         => $slot + $care[ $service ][1] * MINUTE_IN_SECONDS,
					'type'             => 'single',
					'appointment_date' => $demo->local( $booked( $start, 2 + $i % 6 ) ),
				)
			);
			$m->set_meta( 'phone', $p['phone'] );
			if ( '' !== $comment ) {
				$m->set_meta( 'comments', $comment );
			}
			$id = (int) $m->save();
			if ( $id ) {
				$made[] = $id;
				$demo->remember( 'bookings_jet_apb', 'appointments', $made );
			}
		}
		$demo->log( count( $made ) . ' JetAppointments appointments' );
	} else {
		$demo->log( 'JetAppointments or JetEngine is not active: no appointments' );
	}

	remove_filter( 'pre_http_request', $offline, 10 );
	remove_filter( 'suretriggers_enable_request_logging', '__return_false' );
};
