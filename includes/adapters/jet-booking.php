<?php
/**
 * Bundled adapter: JetBooking (Crocoblock, Bookings family).
 *
 * JetBooking keeps every booking in its own {prefix}jet_apartment_bookings
 * table (booking_id, booking_vendor, status, apartment_id = the booked
 * post, apartment_unit, check_in_date / check_out_date as day epochs,
 * check_in_time / check_out_time, user_id, user_email, order_id) plus a
 * meta table and whatever extra columns the site added in its settings.
 * The booked things are ordinary posts of the configured "booking
 * instance" post types, so they list in Content already; the bookings
 * themselves have no post and live only behind JetBooking's Vue screen.
 * This surface is the inbox for them: upcoming / pending / today /
 * canceled, a contact-style detail, status changes and delete through
 * JetBooking's own DB manager (its update fires the per-column hooks the
 * plugin's workflows, WooCommerce order sync and status emails listen
 * to), and a status card with a deep link. Calendars, units, pricing and
 * the booking form stay in JetBooking.
 *
 * Epochs are stored the way the plugin formats them, with date_i18n() on
 * the raw value: WP-local naive timestamps, never UTC. They leave this
 * adapter as that site-local midnight with its own offset, or a midnight
 * check-in would render as the evening before west of Greenwich.
 * Statuses are theirs (created, pending, processing, on-hold, completed,
 * cancelled, refunded, failed), read live from the Statuses class so a
 * filtered vocabulary is honored. Caps are theirs too:
 * jet_booking_manage_post gates their REST and screens, and a booking
 * vendor (their jet_booking_vendor role) only ever sees bookings of the
 * instances they own, which is what their list endpoint enforces.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_booking_active() {
	return function_exists( 'jet_abaf' ) && class_exists( '\JET_ABAF\Capabilities' ) && is_object( jet_abaf()->db );
}

function minn_admin_jet_booking_can_read() {
	return current_user_can( \JET_ABAF\Capabilities::CAP_MANAGE_POST );
}

function minn_admin_jet_booking_has_tables() {
	try {
		return (bool) jet_abaf()->db->tables_exists();
	} catch ( \Throwable $e ) {
		return false;
	}
}

function minn_admin_jet_booking_table() {
	global $wpdb;
	return $wpdb->prefix . 'jet_apartment_bookings';
}

/**
 * The vendor scope their own list applies: a booking vendor sees the
 * bookings whose booking_vendor is their user id, everyone else sees all.
 *
 * Not knowing the scope is not the same as there being none. Both failure
 * paths used to answer 0, which is the "show everything" sentinel, so a
 * vendors module that was missing or threw turned a scoped list into an
 * unscoped one — the same shape LatePoint was corrected for, and the reason
 * that comment says an empty list is a bug someone reports while an unscoped
 * one is a leak nobody sees. -1 can never equal a booking_vendor, so it denies.
 *
 * @return int 0 = proven unrestricted, -1 = unknown (deny), else the vendor's user id.
 */
function minn_admin_jet_booking_vendor_scope() {
	$uid = get_current_user_id();
	try {
		if ( ! is_object( jet_abaf()->vendors ) ) {
			return -1;
		}
		if ( jet_abaf()->vendors->is_booking_vendor( $uid ) ) {
			return $uid;
		}
	} catch ( \Throwable $e ) {
		return -1;
	}
	return 0;
}

/** The vendor scope as conditions: none for everyone, null for nobody. */
function minn_admin_jet_booking_scope() {
	$scope = minn_admin_jet_booking_vendor_scope();
	return $scope < 0 ? null : ( $scope > 0 ? array( 'booking_vendor' => $scope ) : array() );
}

/** The plugin's live status vocabulary, key => label. */
function minn_admin_jet_booking_statuses() {
	try {
		$s = jet_abaf()->statuses;
		return is_object( $s ) ? (array) $s->get_statuses() : array();
	} catch ( \Throwable $e ) {
		return array();
	}
}

function minn_admin_jet_booking_status_set( $which ) {
	try {
		$s = jet_abaf()->statuses;
		if ( ! is_object( $s ) ) {
			return array();
		}
		switch ( $which ) {
			case 'valid':
				return (array) $s->valid_statuses();
			case 'in_progress':
				return (array) $s->in_progress_statuses();
			case 'invalid':
				return (array) $s->invalid_statuses();
		}
	} catch ( \Throwable $e ) {
		return array();
	}
	return array();
}

/**
 * Whether a booking in this status holds its unit and dates: their
 * availability counts every booking but the invalid and the temporary ones
 * (get_booked_items()). Without the statuses, every status holds.
 */
function minn_admin_jet_booking_holds_dates( $status ) {
	$skip = minn_admin_jet_booking_status_set( 'invalid' );
	try {
		$skip[] = (string) jet_abaf()->statuses->temporary_status();
	} catch ( \Throwable $e ) {
		return true;
	}
	return ! in_array( (string) $status, $skip, true );
}

/**
 * Whether a stored booking's unit and dates are still free of every other
 * booking: their update endpoint's three checks, made on the stored row
 * with its own id left out (rest-api/endpoints/update-booking.php),
 * a unit that no longer exists standing in for the one they would pick,
 * and their settings read for the booking's item as they set it.
 *
 * @param array $booking The stored row.
 * @return bool
 */
function minn_admin_jet_booking_dates_free( array $booking ) {
	$db   = jet_abaf()->db;
	$id   = (int) $booking['booking_id'];
	$item = $booking;
	if ( $db->get_apartment_units( $item['apartment_id'] ) && ! $db->get_apartment_unit( $item['apartment_id'], $item['apartment_unit'] ?? 0 ) ) {
		$item['apartment_unit'] = $db->get_available_unit( $item );
	}
	$key  = jet_abaf()->settings::CONTEXT_KEY;
	$held = wp_cache_get( $key, 'jet-booking' );
	wp_cache_set( $key, $item['apartment_id'], 'jet-booking', 60 );
	try {
		$available = $db->booking_availability( $item, $id );
		$dates     = $db->is_booking_dates_available( $item, $id );
		$days      = jet_abaf()->tools->is_booking_period_available( $item );
	} finally {
		if ( false === $held ) {
			wp_cache_delete( $key, 'jet-booking' );
		} else {
			wp_cache_set( $key, $held, 'jet-booking', 60 );
		}
	}
	return ! ( ( ! $available && ! $dates ) || ! $days );
}

/**
 * A stored day epoch floored to its midnight. insert_booking() adds one
 * second to every check-in so it never collides with the previous
 * check-out at the same midnight; that second is bookkeeping, not time.
 */
function minn_admin_jet_booking_day( $naive ) {
	$naive = (int) $naive;
	return $naive > 0 ? $naive - ( $naive % DAY_IN_SECONDS ) : 0;
}

function minn_admin_jet_booking_nights( $in, $out ) {
	return max( 0, (int) round( ( minn_admin_jet_booking_day( $out ) - minn_admin_jet_booking_day( $in ) ) / DAY_IN_SECONDS ) );
}

/**
 * WP-local naive epoch (their date_i18n convention) → ISO-8601 with the
 * site's offset on that day. Today's gmt_offset put a check-in in the other
 * half of the year an hour off, on the evening before.
 */
function minn_admin_jet_booking_iso( $naive ) {
	$naive = minn_admin_jet_booking_day( $naive );
	if ( $naive <= 0 ) {
		return '';
	}
	try {
		return ( new DateTimeImmutable( gmdate( 'Y-m-d', $naive ), wp_timezone() ) )->format( 'c' );
	} catch ( \Exception $e ) {
		return '';
	}
}

/** The date label their screen shows for a check-in/out day. */
function minn_admin_jet_booking_day_label( $naive ) {
	$naive = (int) $naive;
	return $naive > 0 ? date_i18n( get_option( 'date_format', 'F j, Y' ), $naive ) : '';
}

function minn_admin_jet_booking_admin_url() {
	return admin_url( 'admin.php?page=jet-abaf-bookings' );
}

function minn_admin_jet_booking_customer( $row ) {
	$name  = '';
	$email = trim( (string) ( $row->user_email ?? '' ) );
	if ( ! empty( $row->user_id ) ) {
		$u = get_userdata( (int) $row->user_id );
		if ( $u ) {
			$name = $u->display_name;
			if ( '' === $email ) {
				$email = (string) $u->user_email;
			}
		}
	}
	if ( '' === $name && ! empty( $row->guest_name ) ) {
		$name = (string) $row->guest_name;
	}
	return array( 'name' => $name, 'email' => $email );
}

function minn_admin_jet_booking_customer_label( $row ) {
	$c = minn_admin_jet_booking_customer( $row );
	if ( '' !== $c['name'] && '' !== $c['email'] ) {
		return $c['name'] . ' · ' . $c['email'];
	}
	if ( '' !== $c['name'] ) {
		return $c['name'];
	}
	return '' !== $c['email'] ? $c['email'] : __( '(no name)', 'minn-admin' );
}

/**
 * The booking's note. JetBooking has none of its own: a site adds one as an
 * extra booking column, so it is the first of those named or labelled as a
 * note or comment. One named or labelled as staff-only (internal, staff,
 * admin, private) is the note only when no other note column has a value.
 * It is text, which the client escapes.
 */
function minn_admin_jet_booking_note( $row ) {
	$staff = '';
	foreach ( minn_admin_jet_booking_extra_columns() as $col => $label ) {
		$v = isset( $row->$col ) ? trim( (string) $row->$col ) : '';
		if ( '' === $v || ! preg_match( '/note|comment/i', $col . ' ' . $label ) ) {
			continue;
		}
		if ( ! preg_match( '/internal|staff|admin|private/i', $col . ' ' . $label ) ) {
			return $v;
		}
		$staff = '' !== $staff ? $staff : $v;
	}
	return $staff;
}

/**
 * The guest's phone, from the first extra column named or labelled as one.
 * The booking page only found a row labelled exactly "Phone" on its own.
 */
function minn_admin_jet_booking_phone( $row ) {
	foreach ( minn_admin_jet_booking_extra_columns() as $col => $label ) {
		$v = isset( $row->$col ) ? trim( (string) $row->$col ) : '';
		if ( '' !== $v && preg_match( '/phone|mobile|\btel\b/i', $col . ' ' . $label ) ) {
			return $v;
		}
	}
	return '';
}

/** The site's extra booking columns, name => label. */
function minn_admin_jet_booking_extra_columns() {
	$out = array();
	try {
		$cols = jet_abaf()->settings->get( 'additional_columns' );
		foreach ( is_array( $cols ) ? $cols : array() as $c ) {
			$name = isset( $c['column'] ) ? sanitize_key( (string) $c['column'] ) : '';
			if ( '' === $name || ! jet_abaf()->db->column_exists( $name ) ) {
				continue;
			}
			$out[ $name ] = ! empty( $c['column_label'] ) ? (string) $c['column_label'] : ucwords( str_replace( array( '_', '-' ), ' ', $name ) );
		}
	} catch ( \Throwable $e ) {
		return array();
	}
	return $out;
}

/** A booking's detail, every column their screen shows plus the site's extra ones. */
function minn_admin_jet_booking_detail( array $r ) {
	$row      = (object) $r;
	$customer = minn_admin_jet_booking_customer( $row );
	$title    = $row->item_title ? (string) $row->item_title : __( '(deleted item)', 'minn-admin' );
	$nights   = minn_admin_jet_booking_nights( $row->check_in_date, $row->check_out_date );
	$who      = array(
		array( 'label' => __( 'Name', 'minn-admin' ), 'value' => '' !== $customer['name'] ? $customer['name'] : '—' ),
		array( 'label' => __( 'Email', 'minn-admin' ), 'value' => '' !== $customer['email'] ? $customer['email'] : '—' ),
	);
	$extra    = array();
	foreach ( minn_admin_jet_booking_extra_columns() as $col => $label ) {
		$v = isset( $row->$col ) ? trim( (string) $row->$col ) : '';
		if ( '' !== $v ) {
			$extra[] = array( 'label' => $label, 'value' => $v );
		}
	}
	try {
		$t_in  = jet_abaf()->tools->format_timepicker_time( $row->check_in_time ?? null );
		$t_out = jet_abaf()->tools->format_timepicker_time( $row->check_out_time ?? null );
	} catch ( \Throwable $e ) {
		$t_in  = '';
		$t_out = '';
	}
	$when = array(
		array( 'label' => __( 'Booked', 'minn-admin' ), 'value' => $title ),
		array( 'label' => __( 'Check-in', 'minn-admin' ), 'value' => trim( minn_admin_jet_booking_day_label( $row->check_in_date ) . ' ' . $t_in ) ),
		array( 'label' => __( 'Check-out', 'minn-admin' ), 'value' => trim( minn_admin_jet_booking_day_label( $row->check_out_date ) . ' ' . $t_out ) ),
		array( 'label' => __( 'Nights', 'minn-admin' ), 'value' => (string) $nights ),
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status ),
	);
	if ( ! empty( $row->apartment_unit ) ) {
		$when[] = array( 'label' => __( 'Unit', 'minn-admin' ), 'value' => (string) $row->apartment_unit );
	}
	if ( ! empty( $row->order_id ) ) {
		$when[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => '#' . (int) $row->order_id );
	}
	return array(
		'kind'     => 'booking',
		'status'   => (string) $row->status,
		'title'    => $title,
		'customer' => array( 'name' => $customer['name'], 'email' => $customer['email'], 'phone' => minn_admin_jet_booking_phone( $row ) ),
		'booking'  => array(
			'service' => $title,
			'starts'  => minn_admin_jet_booking_iso( $row->check_in_date ),
			'ends'    => minn_admin_jet_booking_iso( $row->check_out_date ),
			'allDay'  => true,
			'nights'  => $nights,
			'notes'   => minn_admin_jet_booking_note( $row ),
		),
		'sections' => array(
			array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => array_merge( $who, $extra ) ),
			array( 'title' => __( 'Booking', 'minn-admin' ), 'rows' => $when ),
		),
		'adminUrl' => minn_admin_jet_booking_admin_url(),
	);
}

/** Valid and checking in today or later: what upcoming and "next" mean. */
function minn_admin_jet_booking_upcoming() {
	return array( 'status' => minn_admin_jet_booking_status_set( 'valid' ), 'check_in_date' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local-epoch', 0 ) ) );
}

// Their status sets are filterable, so each view reads them live. Today is
// today's check-ins, on the stored clock (a day epoch on the site's wall
// clock read as UTC).
$minn_jet_booking_views = array(
	'upcoming' => 'minn_admin_jet_booking_upcoming',
	'pending'  => function () {
		return array( 'status' => minn_admin_jet_booking_status_set( 'in_progress' ) );
	},
	'today'    => minn_admin_booking_today( 'check_in_date', 'local-epoch' ),
	'canceled' => function () {
		return array( 'status' => minn_admin_jet_booking_status_set( 'invalid' ) );
	},
);

// A booking vendor sees only their own bookings, as their list scopes them.
minn_admin_resource( 'jet-booking/bookings', function () use ( $minn_jet_booking_views ) {
	return array(
		'active'  => 'minn_admin_jet_booking_active',
		'gates'   => array( 'view' => 'minn_admin_jet_booking_can_read' ),
		'labels'  => array( 'not_found' => __( 'Booking not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'jet_apartment_bookings',
			'key'     => 'booking_id',
			'joins'   => array(
				'p' => array( 'core' => 'posts', 'on' => array( 'ID' => 'e.apartment_id' ) ),
				'u' => array( 'core' => 'users', 'on' => array( 'ID' => 'e.user_id' ) ),
			),
			// Every column: a site adds its own booking columns (a note, a phone).
			'columns' => array( '*', 'p.post_title' => 'item_title', 'u.display_name' => 'user_name' ),
			'detail'  => array( '*', 'p.post_title' => 'item_title' ),
			// A booking vendor sees only their own bookings.
			'where'   => 'minn_admin_jet_booking_scope',
			'viewer'  => true,
			// Each booking's meta (the guest's details, a site's own fields).
			'tables'  => array( 'jet_bookings_meta' ),
		),
		'list'    => array(
			'filter' => minn_admin_booking_filter( $minn_jet_booking_views ),
			'search' => array(
				'columns' => array( 'user_email', 'p.post_title', 'u.display_name', 'u.user_email' ),
				'also'    => function ( $term ) {
					return (int) $term > 0 ? array( 'booking_id' => (int) $term ) : null;
				},
			),
			'order'  => array( 'column' => 'check_in_date', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				$row    = (object) $r;
				$nights = minn_admin_jet_booking_nights( $row->check_in_date, $row->check_out_date );
				$title  = $row->item_title ? (string) $row->item_title : __( '(deleted item)', 'minn-admin' );
				return array(
					'id'       => (int) $row->booking_id,
					'customer' => minn_admin_jet_booking_customer_label( $row ),
					/* translators: 1: booked item, 2: number of nights. */
					'service'  => $nights ? sprintf( _n( '%1$s · %2$d night', '%1$s · %2$d nights', $nights, 'minn-admin' ), $title, $nights ) : $title,
					'status'   => (string) $row->status,
					'date'     => minn_admin_jet_booking_iso( $row->check_in_date ),
					'check_in' => minn_admin_jet_booking_day_label( $row->check_in_date ),
				);
			},
			'fields' => array( 'id' => 'int', 'customer' => 'text', 'service' => 'text', 'status' => 'text', 'date' => 'text', 'check_in' => 'text' ),
		),
		'item'    => array( 'view' => 'minn_admin_jet_booking_detail' ),
		'actions' => array(
			'status' => minn_admin_booking_move( array(
				'gate'   => 'view',
				'labels' => 'minn_admin_jet_booking_statuses',
				'write'  => function ( $id, $status ) {
					try {
						// A cancelled or refunded booking has given its unit and
						// dates back, and another guest may hold them now: moving
						// it back to a status that holds them asks what their
						// own update asks first.
						$stored = jet_abaf()->db->get_booking_by( 'booking_id', (int) $id );
						if ( is_array( $stored ) && ! minn_admin_jet_booking_holds_dates( $stored['status'] ?? '' ) && minn_admin_jet_booking_holds_dates( $status ) && ! minn_admin_jet_booking_dates_free( $stored ) ) {
							return new WP_Error( 'minn_jet_booking_taken', __( 'Those dates are no longer available: another booking holds them.', 'minn-admin' ), array( 'status' => 409 ) );
						}
						jet_abaf()->db->update_booking( $id, array( 'status' => $status ) );
					} catch ( \Throwable $e ) {
						return new WP_Error( 'update_failed', __( 'JetBooking could not update that booking.', 'minn-admin' ), array( 'status' => 500 ) );
					}
				},
				'fail'   => __( 'JetBooking could not update that booking.', 'minn-admin' ),
				/* translators: %s: booking status label */
				'marked' => __( 'Booking marked %s.', 'minn-admin' ),
			) ),
			'delete' => array(
				'method' => 'DELETE',
				'gate'   => 'view',
				'run'    => function ( $id ) {
					try {
						jet_abaf()->db->delete_booking( array( 'booking_id' => $id ) );
					} catch ( \Throwable $e ) {
						return new WP_Error( 'delete_failed', __( 'JetBooking could not delete that booking.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return true;
				},
				'expect' => 'gone',
				'fail'   => __( 'JetBooking could not delete that booking.', 'minn-admin' ),
				'done'   => __( 'Booking deleted.', 'minn-admin' ),
			),
		),
	);
} );

minn_admin_endpoint( 'jet-booking/status', array(
	'active' => 'minn_admin_jet_booking_active',
	'gate'   => 'minn_admin_jet_booking_can_read',
	'run'    => function () use ( $minn_jet_booking_views ) {
		return minn_admin_booking_card( 'jet-booking/bookings', $minn_jet_booking_views, array(
			'ready' => minn_admin_jet_booking_has_tables(),
			'noun'  => __( 'Bookings', 'minn-admin' ),
			'empty' => __( 'No bookings yet', 'minn-admin' ),
			'next'  => 'check_in_date',
			'label' => 'minn_admin_jet_booking_day_label',
			'today' => __( 'check-ins', 'minn-admin' ),
			'soon'  => __( 'check-in', 'minn-admin' ),
			// The next 14 site-local days of check-ins: in progress apart from
			// the rest, cancelled left out.
			'chart' => array(
				'column'    => 'check_in_date',
				'clock'     => 'local-epoch',
				'where'     => array( 'status' => array( 'not' => minn_admin_jet_booking_status_set( 'invalid' ) ) ),
				'secondary' => minn_admin_jet_booking_status_set( 'in_progress' ),
			),
			'open'  => array( __( 'Open JetBooking ↗', 'minn-admin' ), minn_admin_jet_booking_admin_url() ),
		) );
	},
) );

minn_admin_add_surface( 'jet-booking', function () {
	return minn_admin_jet_booking_active() && minn_admin_jet_booking_can_read();
}, function () {
	return minn_admin_booking_surface( 'jet-booking/bookings', array(
		'sub'     => 'JetBooking',
		'plugin'  => 'jet-booking',
		'status'  => 'jet-booking/status',
		'noun'    => __( 'Bookings', 'minn-admin' ),
		'open'    => array( __( 'Open in JetBooking ↗', 'minn-admin' ), minn_admin_jet_booking_admin_url() ),
		// Mark completed is offered while the booking is still in progress;
		// Cancel while it is any valid status. Their vocabulary is filterable,
		// so the `when` gates are built from the live sets, one action per status.
		'moves'   => array(
			array( __( 'Mark completed', 'minn-admin' ), 'completed', minn_admin_jet_booking_status_set( 'in_progress' ) ),
			array( __( 'Cancel', 'minn-admin' ), 'cancelled', minn_admin_jet_booking_status_set( 'valid' ), __( 'Cancel this booking? JetBooking runs its own workflows and order sync for the change.', 'minn-admin' ) ),
		),
		'actions' => array(
			Minn_Admin_Kernel::ui( 'jet-booking/bookings', 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => __( 'Delete this booking permanently? JetBooking has no trash for bookings.', 'minn-admin' ), 'danger' => true ) ),
		),
		// The routes gate on JetBooking's own capability; this is nav gating
		// only, and it sits on a surface holding customer contact details, so
		// it says the floor for reaching Minn at all rather than 'read'.
		'cap'     => 'edit_posts',
		'service' => __( 'Booked', 'minn-admin' ),
		// A stay starts on a day, not at an hour: "in 11h" for a midnight
		// check-in read as a time. The day reads as their screen shows it.
		'when'    => array( 'key' => 'check_in', 'label' => __( 'Check-in', 'minn-admin' ) ),
	) );
} );
