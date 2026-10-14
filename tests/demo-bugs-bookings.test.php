<?php
/**
 * Regressions for the demo-site bugs in the bookings group: LatePoint,
 * Bookly, JetBooking, Amelia, WooCommerce Gift Cards, JetReviews and
 * JetSearch.
 *
 * Each section seeds its own rows straight into the vendor's tables (so no
 * vendor hook sends mail), reads them back through the route Minn's client
 * calls, as an administrator, and removes them at the end. A section SKIPs
 * when its plugin is not active. LatePoint's events setting and JetBooking's
 * guest columns are switched on for their sections only and put back.
 *
 * Run: wp eval-file tests/demo-bugs-bookings.test.php --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};
// Every row of every section, flattened.
$rows_of = function ( $data ) {
	$out = array();
	foreach ( (array) ( $data['sections'] ?? array() ) as $g ) {
		foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
			$r['section'] = (string) ( $g['title'] ?? '' );
			$out[]        = $r;
		}
	}
	return $out;
};
$row_value = function ( $rows, $label ) {
	foreach ( $rows as $r ) {
		if ( ( $r['label'] ?? '' ) === $label ) {
			return (string) $r['value'];
		}
	}
	return null;
};
$is_iso   = function ( $v ) {
	return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string) $v );
};
$valid_id = function ( $id ) {
	return is_int( $id ) && $id > 1;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( (int) $admin[0]->ID );

global $wpdb;
$now_gmt   = gmdate( 'Y-m-d H:i:s' );
$fmt_when  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$surfaces  = apply_filters( 'minn_admin_surfaces', array() );

/* ===== LatePoint: D-04 (registration dialog), D-15, D-16 ===== */
if ( ! function_exists( 'minn_admin_latepoint_active' ) || ! minn_admin_latepoint_active() || ! minn_admin_latepoint_has_tables() ) {
	$skip( 'LatePoint: not active' );
} else {
	$lp      = function ( $t ) use ( $wpdb ) {
		return $wpdb->prefix . 'latepoint_' . $t;
	};
	$made    = array();
	$setting = null;
	try {
		// Leftovers from an interrupted run, by email.
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$lp( 'customers' )} WHERE email = %s", 'minn-d45-lp@example.com' ) ) as $old ) {
			$wpdb->delete( $lp( 'bookings' ), array( 'customer_id' => (int) $old ) );
			$wpdb->delete( $lp( 'event_registrations' ), array( 'customer_id' => (int) $old ) );
			$wpdb->delete( $lp( 'orders' ), array( 'customer_id' => (int) $old ) );
			$wpdb->delete( $lp( 'customers' ), array( 'id' => (int) $old ) );
		}
		$wpdb->delete( $lp( 'events' ), array( 'name' => 'Minn D45 Workshop' ) );

		$wpdb->insert( $lp( 'customers' ), array( 'first_name' => 'Minn', 'last_name' => 'D45', 'email' => 'minn-d45-lp@example.com', 'phone' => '+15035550199', 'status' => 'active', 'admin_notes' => 'Prefers morning visits.', 'created_at' => $now_gmt, 'updated_at' => $now_gmt ) );
		$cid = (int) $wpdb->insert_id;
		if ( ! $valid_id( $cid ) ) {
			throw new RuntimeException( 'customer insert failed' );
		}
		$made['customer'] = $cid;
		$comment = 'Front desk opens at 8:30 & <b>ask</b> for Dana.';
		$wpdb->insert( $lp( 'orders' ), array( 'customer_id' => $cid, 'customer_comment' => $comment, 'status' => 'open', 'created_at' => $now_gmt, 'updated_at' => $now_gmt ) );
		$made['order'] = (int) $wpdb->insert_id;
		$wpdb->insert( $lp( 'order_items' ), array( 'order_id' => $made['order'], 'variant' => 'booking', 'created_at' => $now_gmt, 'updated_at' => $now_gmt ) );
		$made['item'] = (int) $wpdb->insert_id;
		$start = time() + 3 * DAY_IN_SECONDS;
		$book  = function ( $status, $item_id ) use ( $wpdb, $lp, $cid, $start, $now_gmt ) {
			$wpdb->insert( $lp( 'bookings' ), array(
				'booking_code'       => 'MD45' . wp_rand( 100, 999 ),
				'start_date'         => wp_date( 'Y-m-d', $start ),
				'end_date'           => wp_date( 'Y-m-d', $start ),
				'start_time'         => 600,
				'end_time'           => 660,
				'start_datetime_utc' => gmdate( 'Y-m-d H:i:s', $start ),
				'end_datetime_utc'   => gmdate( 'Y-m-d H:i:s', $start + HOUR_IN_SECONDS ),
				'buffer_before'      => 0,
				'buffer_after'       => 0,
				'duration'           => 60,
				'status'             => $status,
				'customer_id'        => $cid,
				'service_id'         => 0,
				'agent_id'           => 0,
				'order_item_id'      => $item_id,
				'total_attendees'    => 1,
				'created_at'         => $now_gmt,
				'updated_at'         => $now_gmt,
			) );
			return (int) $wpdb->insert_id;
		};
		$made['booking_a'] = $book( 'payment_pending', $made['item'] );
		$made['booking_b'] = $book( 'no_show', null );
		foreach ( array( 'order', 'item', 'booking_a', 'booking_b' ) as $k ) {
			if ( ! $valid_id( $made[ $k ] ) ) {
				throw new RuntimeException( "{$k} insert failed" );
			}
		}

		// I12: `status` is what the `when` gates read, on the list and (copied
		// from the detail) on the booking page, so it carries LatePoint's own
		// value. The client reads underscored slugs as words.
		list( , $list ) = $call( 'GET', '/minn-admin/v1/latepoint/bookings', array( 'range' => 'all', 'search' => 'minn-d45-lp' ) );
		$by_id = array();
		foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
			$by_id[ (int) $it['id'] ] = $it;
		}
		$a_status = (string) ( $by_id[ $made['booking_a'] ]['status'] ?? '' );
		$b_status = (string) ( $by_id[ $made['booking_b'] ]['status'] ?? '' );
		$check( "I12 LatePoint list: status keeps LatePoint's own payment_pending", 'payment_pending' === $a_status, $a_status );
		$check( "I12 LatePoint list: status keeps LatePoint's own no_show", 'no_show' === $b_status, $b_status );
		$cols = $surfaces['latepoint']['collection']['columns'] ?? array();
		$wide = 0;
		foreach ( $cols as $c ) {
			if ( 'status' === ( $c['key'] ?? '' ) ) {
				$wide = (int) ( $c['width'] ?? 0 );
			}
		}
		$check( 'D-15 LatePoint list: the Status column fits "Payment Pending"', $wide >= 130, $wide . 'px' );

		// D-16: the booking page reads the internal note and the customer's comment.
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/latepoint/bookings/' . $made['booking_a'] );
		$rows  = $rows_of( $d );
		$shown = array();
		foreach ( $rows as $r ) {
			$t = strtolower( $r['section'] );
			if ( 'customer' !== $t && 'appointment' !== $t ) {
				$shown[] = (string) $r['value'];
			}
		}
		$check( 'D-16 LatePoint page: Notes carries the internal note', 200 === $st && 'Prefers morning visits.' === ( $d['booking']['notes'] ?? '' ), (string) ( $d['booking']['notes'] ?? '' ) );
		$check( "D-16 LatePoint page: the customer's comment shows, as typed", in_array( $comment, $shown, true ), implode( ' | ', $shown ) );
		$check( "I12 LatePoint page: the status the gates copy is LatePoint's own", 'payment_pending' === ( $d['status'] ?? '' ) && 'payment_pending' === $row_value( $rows, 'Status' ), (string) ( $d['status'] ?? '' ) . ' / ' . (string) $row_value( $rows, 'Status' ) );
		list( , $d ) = $call( 'GET', '/minn-admin/v1/latepoint/bookings/' . $made['booking_b'] );
		$check( 'D-16 LatePoint page: a booking with no order still shows the internal note', 'Prefers morning visits.' === ( $d['booking']['notes'] ?? '' ), (string) ( $d['booking']['notes'] ?? '' ) );

		// S18: LatePoint 4 kept the customer's comment on the booking row,
		// a column dbDelta never drops once LatePoint 5 moves it to orders.
		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$lp( 'bookings' )} LIKE %s", 'customer_comment' ) ) ) {
			$wpdb->query( "ALTER TABLE {$lp( 'bookings' )} ADD COLUMN customer_comment text" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$made['lp4_column'] = true;
		}
		$wpdb->update( $lp( 'bookings' ), array( 'customer_comment' => 'Key is under the mat.' ), array( 'id' => $made['booking_b'] ) );
		list( , $d ) = $call( 'GET', '/minn-admin/v1/latepoint/bookings/' . $made['booking_b'] );
		$check( "S18 LatePoint page: a comment LatePoint 4 kept on the booking shows", 'Key is under the mat.' === $row_value( $rows_of( $d ), 'Booking note' ), (string) $row_value( $rows_of( $d ), 'Booking note' ) );

		// D-04: the registration dialog's times read as dates, in the site's zone.
		if ( ! class_exists( 'OsSettingsHelper' ) || ! class_exists( 'OsEventRegistrationModel' ) ) {
			$skip( 'D-04 LatePoint registrations: this LatePoint has no events' );
		} else {
			$setting = array( 'row' => $wpdb->get_row( $wpdb->prepare( "SELECT id, value FROM {$lp( 'settings' )} WHERE name = %s", 'enable_events_functionality' ) ) );
			if ( $setting['row'] ) {
				$wpdb->update( $lp( 'settings' ), array( 'value' => 'on' ), array( 'id' => (int) $setting['row']->id ) );
			} else {
				$wpdb->insert( $lp( 'settings' ), array( 'name' => 'enable_events_functionality', 'value' => 'on', 'created_at' => $now_gmt, 'updated_at' => $now_gmt ) );
				$setting['added'] = (int) $wpdb->insert_id;
			}
			OsSettingsHelper::reset_loaded_value( 'enable_events_functionality' );
			$ev_start = time() + 4 * DAY_IN_SECONDS;
			$wpdb->insert( $lp( 'events' ), array( 'name' => 'Minn D45 Workshop', 'status' => 'active', 'start_datetime_utc' => gmdate( 'Y-m-d H:i:s', $ev_start ), 'end_datetime_utc' => gmdate( 'Y-m-d H:i:s', $ev_start + 90 * MINUTE_IN_SECONDS ), 'agent_id' => 0, 'location_id' => 0, 'capacity' => 10, 'created_at' => $now_gmt, 'updated_at' => $now_gmt ) );
			$made['event'] = (int) $wpdb->insert_id;
			$made['reg']   = 0;
			if ( $valid_id( $made['event'] ) ) {
				$wpdb->insert( $lp( 'event_registrations' ), array( 'registration_code' => 'MD45REG', 'event_id' => $made['event'], 'customer_id' => $cid, 'quantity' => 2, 'status' => 'checked_in', 'payment_status' => 'fully_paid', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ), 'updated_at' => $now_gmt ) );
				$made['reg'] = (int) $wpdb->insert_id;
			}
			if ( ! $valid_id( $made['reg'] ) ) {
				$check( 'D-04 LatePoint registrations: fixture', false, 'event or registration insert failed' );
			} else {
				list( $st, $d ) = $call( 'GET', '/minn-admin/v1/latepoint/event-registrations/' . $made['reg'] );
				$rows  = $rows_of( $d );
				$reg   = $row_value( $rows, 'Registered' );
				$starts = $row_value( $rows, 'Starts' );
				$ends  = $row_value( $rows, 'Ends' );
				$check( 'D-04 LatePoint registration: Registered reads as a date, not ISO', 200 === $st && null !== $reg && ! $is_iso( $reg ) && wp_date( $fmt_when, time() - DAY_IN_SECONDS ) === $reg, (string) $reg );
				$check( 'D-04 LatePoint registration: Starts reads as a date in the site zone', null !== $starts && wp_date( $fmt_when, $ev_start ) === $starts, (string) $starts );
				$check( 'D-04 LatePoint registration: Ends reads as a date in the site zone', null !== $ends && wp_date( $fmt_when, $ev_start + 90 * MINUTE_IN_SECONDS ) === $ends, (string) $ends );
				$check( "I12 LatePoint registration: Status and Payment keep LatePoint's own values", 'checked_in' === $row_value( $rows, 'Status' ) && 'fully_paid' === $row_value( $rows, 'Payment' ), $row_value( $rows, 'Status' ) . ' / ' . $row_value( $rows, 'Payment' ) );
				list( , $rl ) = $call( 'GET', '/minn-admin/v1/latepoint/event-registrations', array( 'range' => 'all', 'search' => 'MD45REG' ) );
				$check( "I12 LatePoint registrations list: status keeps LatePoint's own value for the Cancel gate", 'checked_in' === ( $rl['items'][0]['status'] ?? '' ), (string) ( $rl['items'][0]['status'] ?? '' ) );
				$check( 'LatePoint registrations list: Starts stays an ISO time with an offset', (bool) preg_match( '/(Z|[+-]\d\d:\d\d)$/', (string) ( $rl['items'][0]['starts'] ?? '' ) ) );
			}
		}
	} catch ( \Throwable $e ) {
		$check( 'LatePoint section ran', false, $e->getMessage() );
	} finally {
		if ( ! empty( $made['reg'] ) && $valid_id( $made['reg'] ) ) {
			$wpdb->delete( $lp( 'event_registrations' ), array( 'id' => $made['reg'] ) );
		}
		if ( ! empty( $made['event'] ) && $valid_id( $made['event'] ) ) {
			$wpdb->delete( $lp( 'events' ), array( 'id' => $made['event'] ) );
		}
		foreach ( array( 'booking_a', 'booking_b' ) as $k ) {
			if ( ! empty( $made[ $k ] ) && $valid_id( $made[ $k ] ) ) {
				$wpdb->delete( $lp( 'bookings' ), array( 'id' => $made[ $k ] ) );
			}
		}
		if ( ! empty( $made['item'] ) && $valid_id( $made['item'] ) ) {
			$wpdb->delete( $lp( 'order_items' ), array( 'id' => $made['item'] ) );
		}
		if ( ! empty( $made['order'] ) && $valid_id( $made['order'] ) ) {
			$wpdb->delete( $lp( 'orders' ), array( 'id' => $made['order'] ) );
		}
		if ( ! empty( $made['customer'] ) && $valid_id( $made['customer'] ) ) {
			$wpdb->delete( $lp( 'customers' ), array( 'id' => $made['customer'] ) );
		}
		if ( ! empty( $made['lp4_column'] ) ) {
			$wpdb->query( "ALTER TABLE {$lp( 'bookings' )} DROP COLUMN customer_comment" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $setting ) {
			if ( ! empty( $setting['added'] ) && $valid_id( $setting['added'] ) ) {
				$wpdb->delete( $lp( 'settings' ), array( 'id' => $setting['added'] ) );
			} elseif ( $setting['row'] ) {
				$wpdb->update( $lp( 'settings' ), array( 'value' => $setting['row']->value ), array( 'id' => (int) $setting['row']->id ) );
			}
			OsSettingsHelper::reset_loaded_value( 'enable_events_functionality' );
		}
	}
}

/* ===== Bookly: D-16 ===== */
if ( ! function_exists( 'minn_admin_bookly_active' ) || ! minn_admin_bookly_active() || ! minn_admin_bookly_has_tables() ) {
	$skip( 'Bookly: not active' );
} else {
	$bk   = function ( $t ) use ( $wpdb ) {
		return $wpdb->prefix . 'bookly_' . $t;
	};
	$made = array();
	try {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$bk( 'customers' )} WHERE email = %s", 'minn-d45-bookly@example.com' ) ) as $old ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT appointment_id FROM {$bk( 'customer_appointments' )} WHERE customer_id = %d", (int) $old ) ) as $appt ) {
				$wpdb->delete( $bk( 'appointments' ), array( 'id' => (int) $appt ) );
			}
			$wpdb->delete( $bk( 'customer_appointments' ), array( 'customer_id' => (int) $old ) );
			$wpdb->delete( $bk( 'customers' ), array( 'id' => (int) $old ) );
		}
		$wpdb->insert( $bk( 'customers' ), array( 'full_name' => 'Minn D45', 'first_name' => 'Minn', 'last_name' => 'D45', 'email' => 'minn-d45-bookly@example.com', 'phone' => '+15035550198', 'notes' => '', 'created_at' => $now_gmt ) );
		$made['customer'] = (int) $wpdb->insert_id;
		// Appointments carry a foreign key to a staff row; the lab's first one
		// is borrowed, never changed.
		$staff = (int) $wpdb->get_var( "SELECT id FROM {$bk( 'staff' )} ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $staff < 1 ) {
			throw new RuntimeException( 'no Bookly staff member to book with' );
		}
		$local = wp_date( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );
		$appt  = function ( $internal ) use ( $wpdb, $bk, $staff, $local, $now_gmt ) {
			$wpdb->insert( $bk( 'appointments' ), array( 'staff_id' => $staff, 'start_date' => $local, 'end_date' => $local, 'internal_note' => $internal, 'created_from' => 'bookly', 'created_at' => $now_gmt, 'updated_at' => $now_gmt ) );
			return (int) $wpdb->insert_id;
		};
		$theirs = 'Bringing my sister, it is her birthday & <i>she</i> loves ferns.';
		$made['appt_a'] = $appt( 'Paid for two at the door.' );
		$made['appt_b'] = $appt( '' );
		foreach ( array( 'customer', 'appt_a', 'appt_b' ) as $k ) {
			if ( ! $valid_id( $made[ $k ] ) ) {
				throw new RuntimeException( "{$k} insert failed" );
			}
		}
		$ca = function ( $appt_id ) use ( $wpdb, $bk, $made, $theirs, $now_gmt ) {
			$wpdb->insert( $bk( 'customer_appointments' ), array( 'customer_id' => $made['customer'], 'appointment_id' => $appt_id, 'number_of_persons' => 2, 'notes' => $theirs, 'status' => 'approved', 'created_from' => 'frontend', 'created_at' => $now_gmt, 'updated_at' => $now_gmt ) );
			return (int) $wpdb->insert_id;
		};
		$made['ca_a'] = $ca( $made['appt_a'] );
		$made['ca_b'] = $ca( $made['appt_b'] );
		if ( ! $valid_id( $made['ca_a'] ) || ! $valid_id( $made['ca_b'] ) ) {
			throw new RuntimeException( 'customer appointment insert failed' );
		}

		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/bookly/appointments/' . $made['ca_a'] );
		$shown = array();
		foreach ( $rows_of( $d ) as $r ) {
			$t = strtolower( $r['section'] );
			if ( 'customer' !== $t && 'appointment' !== $t ) {
				$shown[] = (string) $r['value'];
			}
		}
		$check( 'D-16 Bookly page: Notes carries the internal note', 200 === $st && 'Paid for two at the door.' === ( $d['booking']['notes'] ?? '' ), (string) ( $d['booking']['notes'] ?? '' ) );
		$check( "D-16 Bookly page: the customer's note shows, as typed", in_array( $theirs, $shown, true ), implode( ' | ', $shown ) );
		list( , $d ) = $call( 'GET', '/minn-admin/v1/bookly/appointments/' . $made['ca_b'] );
		$check( "D-16 Bookly page: with no internal note, Notes carries the customer's note", $theirs === ( $d['booking']['notes'] ?? '' ), (string) ( $d['booking']['notes'] ?? '' ) );
	} catch ( \Throwable $e ) {
		$check( 'Bookly section ran', false, $e->getMessage() );
	} finally {
		foreach ( array( 'ca_a', 'ca_b' ) as $k ) {
			if ( ! empty( $made[ $k ] ) && $valid_id( $made[ $k ] ) ) {
				$wpdb->delete( $bk( 'customer_appointments' ), array( 'id' => $made[ $k ] ) );
			}
		}
		foreach ( array( 'appt_a', 'appt_b' ) as $k ) {
			if ( ! empty( $made[ $k ] ) && $valid_id( $made[ $k ] ) ) {
				$wpdb->delete( $bk( 'appointments' ), array( 'id' => $made[ $k ] ) );
			}
		}
		if ( ! empty( $made['customer'] ) && $valid_id( $made['customer'] ) ) {
			$wpdb->delete( $bk( 'customers' ), array( 'id' => $made['customer'] ) );
		}
	}
}

/* ===== JetBooking: D-17 ===== */
if ( ! function_exists( 'minn_admin_jet_booking_active' ) || ! minn_admin_jet_booking_active() || ! minn_admin_jet_booking_has_tables() ) {
	$skip( 'JetBooking: not active' );
} else {
	$jt    = minn_admin_jet_booking_table();
	$added = array();
	$made  = array();
	// The site's extra booking columns, as their settings name them. A filter,
	// so nothing is written to their settings option.
	// A staff-only note comes first, so the note guess has to pass it over
	// (I17); the phone's label is not the plain "Phone" the client's own
	// fallback would match (S18).
	$extra = function () {
		return array(
			array( 'column' => 'guest_name', 'column_label' => 'Name' ),
			array( 'column' => 'staff_note', 'column_label' => 'Internal note' ),
			array( 'column' => 'guest_note', 'column_label' => 'Note' ),
			array( 'column' => 'guest_phone', 'column_label' => 'Guest phone' ),
		);
	};
	try {
		foreach ( array( 'guest_name', 'staff_note', 'guest_note', 'guest_phone' ) as $col ) {
			if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$jt} LIKE %s", $col ) ) ) {
				$wpdb->query( "ALTER TABLE {$jt} ADD COLUMN {$col} text" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$added[] = $col;
			}
		}
		add_filter( 'jet-booking/settings/get/additional_columns', $extra );
		$wpdb->delete( $jt, array( 'user_email' => 'minn-d45-guest@example.com' ) );
		// A stay in another DST period than today, so an offset taken from
		// today would land the check-in on the evening before.
		$in_day  = ( (int) gmdate( 'Y' ) + 1 ) . '-01-15';
		$in_n    = strtotime( $in_day . ' 00:00:00 UTC' ) + 1;
		$out_n   = $in_n - 1 + 2 * DAY_IN_SECONDS;
		$note    = 'Six pots & <b>two</b> big ones. Water every other day.';
		$wpdb->insert( $jt, array( 'status' => 'pending', 'apartment_id' => 0, 'check_in_date' => $in_n, 'check_out_date' => $out_n, 'user_id' => 0, 'user_email' => 'minn-d45-guest@example.com', 'guest_name' => 'Minn D45 Guest', 'staff_note' => 'Deposit taken by card.', 'guest_note' => $note, 'guest_phone' => '+15035550196' ) );
		$made['booking'] = (int) $wpdb->insert_id;
		if ( ! $valid_id( $made['booking'] ) ) {
			throw new RuntimeException( 'booking insert failed' );
		}

		list( , $list ) = $call( 'GET', '/minn-admin/v1/jet-booking/bookings', array( 'range' => 'all', 'search' => 'minn-d45-guest' ) );
		$item = null;
		foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
			if ( (int) $it['id'] === $made['booking'] ) {
				$item = $it;
			}
		}
		$check( "D-17 JetBooking list: a guest booking shows the guest's name", is_array( $item ) && 'Minn D45 Guest · minn-d45-guest@example.com' === $item['customer'], (string) ( $item['customer'] ?? '' ) );
		$surf = apply_filters( 'minn_admin_surfaces', array() );
		$col  = null;
		foreach ( $surf['jet-booking']['collection']['columns'] ?? array() as $c ) {
			if ( 'Check-in' === ( $c['label'] ?? '' ) ) {
				$col = $c;
			}
		}
		$check( 'D-17 JetBooking list: Check-in is not a relative time', is_array( $col ) && 'ago' !== ( $col['format'] ?? '' ), (string) ( $col['format'] ?? '' ) );
		$check( 'D-17 JetBooking list: Check-in shows the day as their screen does', is_array( $col ) && is_array( $item ) && date_i18n( get_option( 'date_format' ), $in_n ) === (string) ( $item[ $col['key'] ] ?? '' ), (string) ( $item[ $col['key'] ?? '' ] ?? '' ) );

		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/jet-booking/bookings/' . $made['booking'] );
		$check( "D-17 JetBooking page: a note column reaches Notes, as typed", 200 === $st && in_array( $d['booking']['notes'] ?? '', array( $note, 'Deposit taken by card.' ), true ), (string) ( $d['booking']['notes'] ?? '' ) );
		$check( "I17 JetBooking page: the guest's note is preferred over a staff-only one listed first", $note === ( $d['booking']['notes'] ?? '' ), (string) ( $d['booking']['notes'] ?? '' ) );
		$check( "S18 JetBooking page: the site-added phone column reaches the customer card", '+15035550196' === ( $d['customer']['phone'] ?? '' ), (string) ( $d['customer']['phone'] ?? '' ) );
		$starts = (string) ( $d['booking']['starts'] ?? '' );
		$local  = '';
		try {
			$local = ( new DateTime( $starts ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' );
		} catch ( \Exception $e ) {
			$local = 'unparsable';
		}
		$check( 'D-17 JetBooking page: check-in is site-local midnight in its own DST period', $in_day . ' 00:00' === $local && (bool) preg_match( '/(Z|[+-]\d\d:\d\d)$/', $starts ), $starts );
	} catch ( \Throwable $e ) {
		$check( 'JetBooking section ran', false, $e->getMessage() );
	} finally {
		remove_filter( 'jet-booking/settings/get/additional_columns', $extra );
		if ( ! empty( $made['booking'] ) && $valid_id( $made['booking'] ) ) {
			$wpdb->delete( $jt, array( 'booking_id' => $made['booking'] ) );
		}
		foreach ( $added as $col ) {
			$wpdb->query( "ALTER TABLE {$jt} DROP COLUMN {$col}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}

/* ===== JetAppointments: S6 ===== */
if ( ! function_exists( 'minn_admin_jet_apb_active' ) || ! minn_admin_jet_apb_active() || ! minn_admin_jet_apb_has_tables() ) {
	$skip( 'JetAppointments: not active' );
} else {
	$at   = minn_admin_jet_apb_table();
	$made = array();
	try {
		$wpdb->delete( $at, array( 'user_email' => 'minn-d45-apb@example.com' ) );
		// A 10:00 slot in the other DST half from today. Epochs are naive:
		// the site-local wall time read as UTC.
		$day  = ( (int) gmdate( 'Y' ) + 1 ) . '-01-15';
		$date = strtotime( $day . ' 00:00:00 UTC' );
		$slot = strtotime( $day . ' 10:00:00 UTC' );
		$wpdb->insert( $at, array( 'status' => 'pending', 'service' => '0', 'provider' => '0', 'user_id' => 0, 'user_name' => 'Minn D45 Apb', 'user_email' => 'minn-d45-apb@example.com', 'date' => $date, 'date_end' => $date, 'slot' => $slot, 'slot_end' => $slot + HOUR_IN_SECONDS, 'type' => '', 'appointment_date' => $day . ' 10:00:00' ) );
		$made['appt'] = (int) $wpdb->insert_id;
		if ( ! $valid_id( $made['appt'] ) ) {
			throw new RuntimeException( 'appointment insert failed' );
		}
		$local = function ( $iso ) {
			try {
				return ( new DateTime( (string) $iso ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' );
			} catch ( \Exception $e ) {
				return 'unparsable';
			}
		};
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/jet-appointments/appointments/' . $made['appt'] );
		$starts = (string) ( $d['booking']['starts'] ?? '' );
		$check( 'S6 JetAppointments page: a January 10:00 slot is 10:00 site time, with its own offset', 200 === $st && $day . ' 10:00' === $local( $starts ) && (bool) preg_match( '/(Z|[+-]\d\d:\d\d)$/', $starts ), $starts );
		$check( 'S6 JetAppointments page: it ends at 11:00 site time', $day . ' 11:00' === $local( $d['booking']['ends'] ?? '' ), (string) ( $d['booking']['ends'] ?? '' ) );
		list( , $list ) = $call( 'GET', '/minn-admin/v1/jet-appointments/appointments', array( 'range' => 'all', 'search' => 'minn-d45-apb' ) );
		$item = null;
		foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
			if ( (int) $it['id'] === $made['appt'] ) {
				$item = $it;
			}
		}
		$check( 'S6 JetAppointments list: When is the same 10:00 site time', is_array( $item ) && $day . ' 10:00' === $local( $item['date'] ?? '' ), (string) ( $item['date'] ?? '' ) );
	} catch ( \Throwable $e ) {
		$check( 'JetAppointments section ran', false, $e->getMessage() );
	} finally {
		if ( ! empty( $made['appt'] ) && $valid_id( $made['appt'] ) ) {
			$wpdb->delete( $at, array( 'ID' => $made['appt'] ) );
		}
	}
}

/* ===== Amelia: D-18 ===== */
if ( ! function_exists( 'minn_admin_amelia_active' ) || ! minn_admin_amelia_active() || ! minn_admin_amelia_has_tables() ) {
	$skip( 'Amelia: not active' );
} else {
	$am   = function ( $t ) use ( $wpdb ) {
		return $wpdb->prefix . 'amelia_' . $t;
	};
	$made = array();
	try {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$am( 'users' )} WHERE email = %s", 'minn-d45-amelia@example.com' ) ) as $old ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT appointmentId FROM {$am( 'customer_bookings' )} WHERE customerId = %d", (int) $old ) ) as $appt ) {
				$wpdb->delete( $am( 'appointments' ), array( 'id' => (int) $appt ) );
			}
			$wpdb->delete( $am( 'customer_bookings' ), array( 'customerId' => (int) $old ) );
			$wpdb->delete( $am( 'users' ), array( 'id' => (int) $old ) );
		}
		$wpdb->insert( $am( 'users' ), array( 'status' => 'visible', 'type' => 'customer', 'firstName' => 'Minn', 'lastName' => 'D45', 'email' => 'minn-d45-amelia@example.com', 'phone' => '', 'note' => 'Allergic to lilies.' ) );
		$made['customer'] = (int) $wpdb->insert_id;
		if ( ! $valid_id( $made['customer'] ) ) {
			throw new RuntimeException( 'customer insert failed' );
		}
		$start = time() + 5 * DAY_IN_SECONDS;
		$book  = function ( $info, $fields, $internal = '' ) use ( $wpdb, $am, $made, $start ) {
			$wpdb->insert( $am( 'appointments' ), array( 'status' => 'approved', 'bookingStart' => gmdate( 'Y-m-d H:i:s', $start ), 'bookingEnd' => gmdate( 'Y-m-d H:i:s', $start + HOUR_IN_SECONDS ), 'notifyParticipants' => 0, 'serviceId' => 0, 'providerId' => 0, 'internalNotes' => $internal ) );
			$appt = (int) $wpdb->insert_id;
			if ( $appt > 1 ) {
				$wpdb->insert( $am( 'customer_bookings' ), array( 'appointmentId' => $appt, 'customerId' => $made['customer'], 'status' => 'approved', 'price' => 0, 'persons' => 1, 'info' => $info, 'customFields' => $fields ) );
			}
			return $appt;
		};
		// What Amelia's own booking form writes: the customer's details as JSON
		// in info, and the answers keyed by custom field id.
		$info   = wp_json_encode( array( 'firstName' => 'Minn', 'lastName' => 'D45', 'phone' => '+15035550197', 'locale' => 'en_US', 'timeZone' => 'America/Los_Angeles', 'urlParams' => null ) );
		$fields = wp_json_encode( array(
			'1' => array( 'label' => 'Anything we should know?', 'value' => 'Ring twice & <b>wait</b>.', 'type' => 'text-area' ),
			'2' => array( 'label' => 'Photos of the plant', 'value' => array( array( 'name' => 'fig.jpg', 'fileName' => 'a1b2c3.jpg' ) ), 'type' => 'file' ),
		) );
		$made['appt_form']  = $book( $info, $fields, 'Paid at the door.' );
		$made['appt_plain'] = $book( 'Please call before you come.', '' );
		foreach ( array( 'appt_form', 'appt_plain' ) as $k ) {
			if ( ! $valid_id( $made[ $k ] ) ) {
				throw new RuntimeException( "{$k} insert failed" );
			}
		}

		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/amelia/appointments/' . $made['appt_form'] );
		$rows = $rows_of( $d );
		$raw  = array();
		foreach ( $rows as $r ) {
			$v = trim( (string) $r['value'] );
			if ( '' !== $v && ( '{' === $v[0] || '[' === $v[0] || 'Array' === $v ) ) {
				$raw[] = $r['label'] . ': ' . $v;
			}
		}
		$check( 'D-18 Amelia page: no row prints the form JSON raw', 200 === $st && ! $raw, implode( ' | ', $raw ) );
		$check( 'D-18 Amelia page: the form JSON is not a "Booking note"', null === $row_value( $rows, 'Booking note' ) && false === strpos( (string) ( $d['booking']['notes'] ?? '' ), '{' ), (string) $row_value( $rows, 'Booking note' ) );
		$shown = array();
		foreach ( $rows as $r ) {
			if ( ! in_array( strtolower( $r['section'] ), array( 'customer', 'appointment' ), true ) ) {
				$shown[] = (string) $r['value'];
			}
		}
		$check( "S18 Amelia page: with an internal note in Notes, the customer profile's note gets a card", 'Paid at the door.' === ( $d['booking']['notes'] ?? '' ) && in_array( 'Allergic to lilies.', $shown, true ), implode( ' | ', $shown ) );
		$check( "D-18 Amelia page: the form's details read as label and value", 'America/Los_Angeles' === $row_value( $rows, 'Time zone' ) && '+15035550197' === $row_value( $rows, 'Phone at booking' ), (string) $row_value( $rows, 'Time zone' ) . ' / ' . (string) $row_value( $rows, 'Phone at booking' ) );
		$check( 'D-18 Amelia page: the answers read as label and value, as typed', 'Ring twice & <b>wait</b>.' === $row_value( $rows, 'Anything we should know?' ) && 'fig.jpg' === $row_value( $rows, 'Photos of the plant' ), (string) $row_value( $rows, 'Photos of the plant' ) );
		list( , $d ) = $call( 'GET', '/minn-admin/v1/amelia/appointments/' . $made['appt_plain'] );
		$check( 'D-18 Amelia page: a plain-text booking note still shows as one', 'Please call before you come.' === $row_value( $rows_of( $d ), 'Booking note' ) );
		$check( "S18 Amelia page: with no internal note, Notes carries the customer profile's note", 'Allergic to lilies.' === ( $d['booking']['notes'] ?? '' ), (string) ( $d['booking']['notes'] ?? '' ) );
	} catch ( \Throwable $e ) {
		$check( 'Amelia section ran', false, $e->getMessage() );
	} finally {
		foreach ( array( 'appt_form', 'appt_plain' ) as $k ) {
			if ( ! empty( $made[ $k ] ) && $valid_id( $made[ $k ] ) ) {
				$wpdb->delete( $am( 'customer_bookings' ), array( 'appointmentId' => $made[ $k ] ) );
				$wpdb->delete( $am( 'appointments' ), array( 'id' => $made[ $k ] ) );
			}
		}
		if ( ! empty( $made['customer'] ) && $valid_id( $made['customer'] ) ) {
			$wpdb->delete( $am( 'users' ), array( 'id' => $made['customer'] ) );
		}
	}
}

/* ===== WooCommerce Gift Cards: D-19 ===== */
if ( ! function_exists( 'minn_admin_wcgc_active' ) || ! minn_admin_wcgc_active() ) {
	$skip( 'WooCommerce Gift Cards: not active' );
} else {
	$gc   = $wpdb->prefix . 'woocommerce_gc_cards';
	$made = array();
	try {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$gc} WHERE code LIKE %s", 'MD45-%' ) );
		$card = function ( $code, $active, $deliver, $delivered ) use ( $wpdb, $gc ) {
			$wpdb->insert( $gc, array(
				'code'          => $code,
				'order_id'      => 0,
				'order_item_id' => 0,
				'recipient'     => 'minn-d45-gc@example.com',
				'sender'        => 'Minn D45',
				'sender_email'  => 'minn-d45-gc@example.com',
				'message'       => '',
				'balance'       => 25,
				'remaining'     => 25,
				'template_id'   => 'default',
				'create_date'   => time(),
				'deliver_date'  => $deliver,
				'delivered'     => $delivered,
				'expire_date'   => 0,
				'redeem_date'   => 0,
				'is_virtual'    => 'on',
				'is_active'     => $active,
			) );
			return (int) $wpdb->insert_id;
		};
		// As their order flow makes a card with a future delivery date: off until sent.
		$made['scheduled'] = $card( 'MD45-TEST-SCHD-0001', 'off', time() + 3 * DAY_IN_SECONDS, 'no' );
		// Sent by their scheduler (delivered = 0, "by the system"), still on.
		$made['sent']      = $card( 'MD45-TEST-SENT-0002', 'on', time() - DAY_IN_SECONDS, '0' );
		// Sent, then disabled by the store.
		$made['disabled']  = $card( 'MD45-TEST-DSBL-0003', 'off', time() - DAY_IN_SECONDS, '0' );
		// Waiting for its date, but switched on by the store already.
		$made['early']     = $card( 'MD45-TEST-EARL-0004', 'on', time() + 3 * DAY_IN_SECONDS, 'no' );
		foreach ( $made as $k => $id ) {
			if ( ! $valid_id( $id ) ) {
				throw new RuntimeException( "{$k} insert failed" );
			}
		}
		$status_of = function ( $id ) use ( $call, $rows_of, $row_value ) {
			list( , $d ) = $call( 'GET', '/minn-admin/v1/wcgc/gift-cards/' . $id . '/view' );
			return (string) $row_value( $rows_of( $d ), 'Status' );
		};
		$check( 'D-19 Gift card: a card waiting for its delivery date reads Scheduled', 'scheduled' === $status_of( $made['scheduled'] ), $status_of( $made['scheduled'] ) );
		$check( 'D-19 Gift card: a card their scheduler sent reads Active', 'active' === $status_of( $made['sent'] ), $status_of( $made['sent'] ) );
		$check( 'D-19 Gift card: a sent card the store disabled reads Disabled', 'disabled' === $status_of( $made['disabled'] ), $status_of( $made['disabled'] ) );
		list( , $dl ) = $call( 'GET', '/minn-admin/v1/wcgc/gift-cards', array( 'status' => 'disabled', 'search' => 'MD45-TEST' ) );
		$ids = array_map( 'intval', wp_list_pluck( (array) ( $dl['items'] ?? array() ), 'id' ) );
		$check( 'D-19 Gift cards list: the Disabled filter leaves out a scheduled card', ! in_array( $made['scheduled'], $ids, true ) && in_array( $made['disabled'], $ids, true ), wp_json_encode( $ids ) );
		list( , $sl ) = $call( 'GET', '/minn-admin/v1/wcgc/gift-cards', array( 'status' => 'scheduled', 'search' => 'MD45-TEST' ) );
		$ids = array_map( 'intval', wp_list_pluck( (array) ( $sl['items'] ?? array() ), 'id' ) );
		sort( $ids );
		$want = array( $made['scheduled'], $made['early'] );
		sort( $want );
		$check( 'D-19 Gift cards list: the Scheduled filter finds it', $want === $ids, wp_json_encode( $ids ) );
		$enabled_of = function ( $id ) use ( $call, $rows_of, $row_value ) {
			list( , $d ) = $call( 'GET', '/minn-admin/v1/wcgc/gift-cards/' . $id . '/view' );
			return $row_value( $rows_of( $d ), 'Enabled' );
		};
		$check( 'S18 Gift card: a scheduled card says whether it is enabled, as their list does', 'scheduled' === $status_of( $made['early'] ) && 'Yes' === $enabled_of( $made['early'] ) && 'No' === $enabled_of( $made['scheduled'] ), $enabled_of( $made['early'] ) . ' / ' . $enabled_of( $made['scheduled'] ) );
		$check( 'S18 Gift card: a card that is not scheduled has no Enabled row beside its pill', null === $enabled_of( $made['sent'] ), (string) $enabled_of( $made['sent'] ) );
	} catch ( \Throwable $e ) {
		$check( 'Gift cards section ran', false, $e->getMessage() );
	} finally {
		foreach ( $made as $id ) {
			if ( $valid_id( $id ) ) {
				$wpdb->delete( $gc, array( 'id' => $id ) );
			}
		}
	}
}

/* ===== JetReviews: D-24 ===== */
if ( ! function_exists( 'minn_admin_jet_reviews_active' ) || ! minn_admin_jet_reviews_active() || ! minn_admin_jet_reviews_has_table() ) {
	$skip( 'JetReviews: not active' );
} else {
	$rt   = minn_admin_jet_reviews_table();
	$made = array();
	try {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$rt} WHERE title LIKE %s", 'Minn D45 review%' ) );
		$post = wp_insert_post( array( 'post_title' => "Lupine 'Gallery Blue'", 'post_status' => 'publish', 'post_type' => 'post' ), true );
		$made['post'] = is_wp_error( $post ) ? 0 : (int) $post;
		if ( ! $valid_id( $made['post'] ) ) {
			throw new RuntimeException( 'post insert failed' );
		}
		// The shape their submit endpoint stores: maybe_serialize() of the
		// validated fields, each with its label, value, step and max.
		$ratings = maybe_serialize( array(
			array( 'field_label' => 'Quality', 'field_value' => 4, 'field_step' => 1, 'field_max' => 5 ),
			array( 'field_label' => 'Value &amp; price', 'field_value' => 3, 'field_step' => 1, 'field_max' => 5 ),
		) );
		// Title and body as JetReviews stores them: through wp_kses on submit,
		// and esc_html with <br> for line breaks on edit, so entities arrive.
		$stored_title = 'Minn D45 review: Tom &amp; Jerry&#039;s pick';
		$stored_body  = 'It&#039;s healthy &amp; tall.<br>It came well packed.';
		$wpdb->insert( $rt, array( 'source' => 'post', 'post_id' => $made['post'], 'post_type' => 'post', 'author' => (string) get_current_user_id(), 'date' => current_time( 'mysql' ), 'title' => $stored_title, 'content' => $stored_body, 'type_slug' => 'default', 'rating_data' => $ratings, 'rating' => 70, 'likes' => 0, 'dislikes' => 0, 'approved' => 0, 'pinned' => 0 ) );
		$made['review'] = (int) $wpdb->insert_id;
		if ( ! $valid_id( $made['review'] ) ) {
			throw new RuntimeException( 'review insert failed' );
		}
		$curly = 'Lupine ‘Gallery Blue’';
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/jet-reviews/reviews/' . $made['review'] );
		$rows = $rows_of( $d );
		$check( "D-24 JetReviews review: the product's name is plain text", 200 === $st && $curly === $row_value( $rows, 'Reviewed' ), (string) $row_value( $rows, 'Reviewed' ) );
		$check( 'D-24 JetReviews review: per-question ratings show from the stored shape', '4/5' === $row_value( $rows, 'Quality' ) && '3/5' === $row_value( $rows, 'Value & price' ), (string) $row_value( $rows, 'Quality' ) );
		list( , $list ) = $call( 'GET', '/minn-admin/v1/jet-reviews/reviews', array( 'status' => 'pending', 'search' => 'Minn D45 review' ) );
		$check( "S4 JetReviews review: the title is plain text", "Minn D45 review: Tom & Jerry's pick" === ( $d['title'] ?? '' ), (string) ( $d['title'] ?? '' ) );
		$check( "S4 JetReviews review: the message is plain text, its line break kept", "It's healthy & tall.\nIt came well packed." === $row_value( $rows, 'Message' ), (string) $row_value( $rows, 'Message' ) );
		$check( "D-24 JetReviews list: the product's name is plain text", $curly === ( $list['items'][0]['item'] ?? '' ), (string) ( $list['items'][0]['item'] ?? '' ) );
	} catch ( \Throwable $e ) {
		$check( 'JetReviews section ran', false, $e->getMessage() );
	} finally {
		if ( ! empty( $made['review'] ) && $valid_id( $made['review'] ) ) {
			$wpdb->delete( $rt, array( 'id' => $made['review'] ) );
		}
		if ( ! empty( $made['post'] ) && $valid_id( $made['post'] ) ) {
			wp_delete_post( $made['post'], true );
		}
	}
}

/* ===== JetSearch: D-25 ===== */
if ( ! function_exists( 'minn_admin_jet_search_active' ) || ! minn_admin_jet_search_active() || ! minn_admin_jet_search_has_table() ) {
	$skip( 'JetSearch: not active' );
} else {
	$st_t = minn_admin_jet_search_table();
	$made = array();
	try {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$st_t} WHERE name LIKE %s", 'md45 %' ) );
		$wpdb->insert( $st_t, array( 'name' => 'md45 tomato', 'weight' => 5, 'parent' => '0', 'term' => '' ) );
		$made['parent'] = (int) $wpdb->insert_id;
		if ( ! $valid_id( $made['parent'] ) ) {
			throw new RuntimeException( 'parent insert failed' );
		}
		// Their manage screen stores a child's parent as the parent's id.
		$wpdb->insert( $st_t, array( 'name' => 'md45 tomato seeds', 'weight' => 2, 'parent' => (string) $made['parent'], 'term' => '' ) );
		$made['child'] = (int) $wpdb->insert_id;
		if ( ! $valid_id( $made['child'] ) ) {
			throw new RuntimeException( 'child insert failed' );
		}
		list( , $list ) = $call( 'GET', '/minn-admin/v1/jet-search/suggestions', array( 'search' => 'md45' ) );
		$by = array();
		foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
			$by[ (int) $it['id'] ] = (string) $it['parent'];
		}
		$check( "D-25 JetSearch list: a child's Parent is the parent's name", 'md45 tomato' === ( $by[ $made['child'] ] ?? null ), (string) ( $by[ $made['child'] ] ?? '' ) );
		$check( 'D-25 JetSearch list: a top-level suggestion has no Parent', '' === ( $by[ $made['parent'] ] ?? null ), (string) ( $by[ $made['parent'] ] ?? '' ) );
		list( , $one ) = $call( 'GET', '/minn-admin/v1/jet-search/suggestions/' . $made['child'] );
		$check( "D-25 JetSearch detail: Parent is the parent's name", 'md45 tomato' === ( $one['parent'] ?? '' ), (string) ( $one['parent'] ?? '' ) );
	} catch ( \Throwable $e ) {
		$check( 'JetSearch section ran', false, $e->getMessage() );
	} finally {
		foreach ( $made as $id ) {
			if ( $valid_id( $id ) ) {
				$wpdb->delete( $st_t, array( 'id' => $id ) );
			}
		}
	}
}

printf( "\ndemo-bugs-bookings: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
