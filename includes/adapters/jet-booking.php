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
 * the raw value: WP-local naive timestamps, never UTC. They are shifted
 * by the site offset before an ISO Z leaves this adapter, or a midnight
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

/** WP-local naive epoch (their date_i18n convention) → ISO-8601 Z. */
function minn_admin_jet_booking_iso( $naive ) {
	$naive = minn_admin_jet_booking_day( $naive );
	if ( $naive <= 0 ) {
		return '';
	}
	$offset = (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	return gmdate( 'Y-m-d\TH:i:s\Z', $naive - $offset );
}

/** The date label their screen shows for a check-in/out day. */
function minn_admin_jet_booking_day_label( $naive ) {
	$naive = (int) $naive;
	return $naive > 0 ? date_i18n( get_option( 'date_format', 'F j, Y' ), $naive ) : '';
}

/** Local day bounds as naive epochs, matching the stored convention. */
function minn_admin_jet_booking_day_bounds( $when = 'today' ) {
	$start = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
	if ( 'tomorrow' === $when ) {
		$start += DAY_IN_SECONDS;
	}
	return array( $start, $start + DAY_IN_SECONDS - 1 );
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

/**
 * The WHERE for one list view, as [ sql fragments, params ].
 * Scope, range and search compose the same way their list does.
 */
function minn_admin_jet_booking_where( $view, $search, $scope ) {
	global $wpdb;
	$where  = array( '1=1' );
	$params = array();
	if ( $scope ) {
		$where[]  = 'b.booking_vendor = %d';
		$params[] = $scope;
	}
	$in = function ( $list ) use ( &$params ) {
		$list = array_values( array_filter( array_map( 'strval', (array) $list ) ) );
		if ( ! $list ) {
			return "b.status IN ('')";
		}
		$params = array_merge( $params, $list );
		return 'b.status IN (' . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
	};
	list( $today_start, $today_end ) = minn_admin_jet_booking_day_bounds();
	if ( 'pending' === $view ) {
		$where[] = $in( minn_admin_jet_booking_status_set( 'in_progress' ) );
	} elseif ( 'canceled' === $view ) {
		$where[] = $in( minn_admin_jet_booking_status_set( 'invalid' ) );
	} elseif ( 'today' === $view ) {
		$where[]  = 'b.check_in_date BETWEEN %d AND %d';
		$params[] = $today_start;
		$params[] = $today_end;
	} elseif ( 'all' === $view ) {
		// no extra gate
	} else {
		$where[]  = $in( minn_admin_jet_booking_status_set( 'valid' ) );
		$where[]  = 'b.check_in_date >= %d';
		$params[] = $today_start;
	}
	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '(b.user_email LIKE %s OR p.post_title LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR b.booking_id = %d)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = (int) $search;
	}
	return array( 'WHERE ' . implode( ' AND ', $where ), $params );
}

function minn_admin_jet_booking_from() {
	global $wpdb;
	return minn_admin_jet_booking_table() . " b
		LEFT JOIN {$wpdb->posts} p ON p.ID = b.apartment_id
		LEFT JOIN {$wpdb->users} u ON u.ID = b.user_id";
}

function minn_admin_jet_booking_row( $id, $scope ) {
	global $wpdb;
	$params = array( (int) $id );
	$sql    = 'SELECT b.*, p.post_title AS item_title FROM ' . minn_admin_jet_booking_from() . ' WHERE b.booking_id = %d';
	if ( $scope ) {
		$sql     .= ' AND b.booking_vendor = %d';
		$params[] = $scope;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_row( $wpdb->prepare( $sql, $params ) );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_jet_booking_active() || ! minn_admin_jet_booking_can_read() ) {
		return $surfaces;
	}
	$labels   = minn_admin_jet_booking_statuses();
	$progress = minn_admin_jet_booking_status_set( 'in_progress' );
	$valid    = minn_admin_jet_booking_status_set( 'valid' );
	$route    = 'minn-admin/v1/jet-booking/bookings/{id}/status';
	$actions  = array();
	$bulk     = array();
	// Mark completed is offered while the booking is still in progress;
	// Cancel while it is any valid status. Their vocabulary is filterable,
	// so the `when` gates are built from the live sets, one action per status.
	foreach ( $progress as $st ) {
		$actions[] = array(
			'label'  => __( 'Mark completed', 'minn-admin' ),
			'method' => 'POST',
			'route'  => $route,
			'body'   => array( 'status' => 'completed' ),
			'when'   => array( 'key' => 'status', 'equals' => $st ),
		);
	}
	foreach ( $valid as $st ) {
		$actions[] = array(
			'label'   => __( 'Cancel', 'minn-admin' ),
			'method'  => 'POST',
			'route'   => $route,
			'body'    => array( 'status' => 'cancelled' ),
			'confirm' => __( 'Cancel this booking? JetBooking runs its own workflows and order sync for the change.', 'minn-admin' ),
			'danger'  => true,
			'when'    => array( 'key' => 'status', 'equals' => $st ),
		);
	}
	$actions[] = array(
		'label'   => __( 'Delete', 'minn-admin' ),
		'method'  => 'DELETE',
		'route'   => 'minn-admin/v1/jet-booking/bookings/{id}',
		'confirm' => __( 'Delete this booking permanently? JetBooking has no trash for bookings.', 'minn-admin' ),
		'danger'  => true,
	);
	$actions[] = array(
		'label' => __( 'Open in JetBooking ↗', 'minn-admin' ),
		'href'  => minn_admin_jet_booking_admin_url(),
	);
	$bulk = array(
		array(
			'label'  => __( 'Mark completed', 'minn-admin' ),
			'method' => 'POST',
			'route'  => $route,
			'body'   => array( 'status' => 'completed' ),
		),
		array(
			'label'  => __( 'Cancel', 'minn-admin' ),
			'method' => 'POST',
			'route'  => $route,
			'body'   => array( 'status' => 'cancelled' ),
			'danger' => true,
		),
	);
	$surfaces['jet-booking'] = array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'commerce',
		'sub'        => 'JetBooking',
		'icon'       => 'calendar',
		// The routes gate on JetBooking's own capability; this is nav gating
		// only, and it sits on a surface holding customer contact details, so
		// it says the floor for reaching Minn at all rather than 'read'.
		'cap'        => 'edit_posts',
		'status'     => array( 'route' => 'minn-admin/v1/jet-booking/status' ),
		'collection' => array(
			'viewLabel' => __( 'Bookings', 'minn-admin' ),
			'route'     => 'minn-admin/v1/jet-booking/bookings',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'filter'    => array(
				'label'   => __( 'When', 'minn-admin' ),
				'options' => array(
					array( 'upcoming', __( 'Upcoming', 'minn-admin' ) ),
					array( 'pending', __( 'Pending', 'minn-admin' ) ),
					array( 'today', __( 'Today', 'minn-admin' ) ),
					array( 'canceled', __( 'Canceled', 'minn-admin' ) ),
					array( 'all', __( 'All', 'minn-admin' ) ),
				),
				'query'   => 'range={v}',
			),
			'columns'   => array(
				array( 'key' => 'customer', 'label' => __( 'Customer', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.6fr)' ),
				array( 'key' => 'service', 'label' => __( 'Booked', 'minn-admin' ) ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
				array( 'key' => 'date', 'label' => __( 'Check-in', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/jet-booking/bookings/{id}',
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_booking_active() ) {
		return;
	}
	$perm = 'minn_admin_jet_booking_can_read';

	register_rest_route( 'minn-admin/v1', '/jet-booking/bookings', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_jet_booking_has_tables() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$view     = sanitize_key( (string) $request->get_param( 'range' ) );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
			$scope    = minn_admin_jet_booking_vendor_scope();
			list( $where_sql, $params ) = minn_admin_jet_booking_where( $view ? $view : 'upcoming', $search, $scope );
			$order = in_array( $view, array( 'canceled', 'all' ), true ) ? 'b.check_in_date DESC, b.booking_id DESC' : 'b.check_in_date ASC, b.booking_id ASC';
			$from  = minn_admin_jet_booking_from();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = "SELECT COUNT(*) FROM {$from} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT b.booking_id, b.status, b.apartment_id, b.apartment_unit, b.check_in_date, b.check_out_date, b.user_id, b.user_email, b.order_id,
					p.post_title AS item_title, u.display_name AS user_name
				FROM {$from} {$where_sql}
				ORDER BY {$order}
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array_map( function ( $r ) {
				$nights = minn_admin_jet_booking_nights( $r->check_in_date, $r->check_out_date );
				$title  = $r->item_title ? (string) $r->item_title : __( '(deleted item)', 'minn-admin' );
				return array(
					'id'       => (int) $r->booking_id,
					'customer' => minn_admin_jet_booking_customer_label( $r ),
					'service'  => $nights ? sprintf(
						/* translators: 1: booked item, 2: number of nights. */
						_n( '%1$s · %2$d night', '%1$s · %2$d nights', $nights, 'minn-admin' ), $title, $nights
					) : $title,
					'status'   => (string) $r->status,
					'date'     => minn_admin_jet_booking_iso( $r->check_in_date ),
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-booking/bookings/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				if ( ! minn_admin_jet_booking_has_tables() ) {
					return new WP_Error( 'not_found', __( 'Booking not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$row = minn_admin_jet_booking_row( (int) Minn_Admin::path_param( $request ), minn_admin_jet_booking_vendor_scope() );
				if ( ! $row ) {
					return new WP_Error( 'not_found', __( 'Booking not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$labels   = minn_admin_jet_booking_statuses();
				$customer = minn_admin_jet_booking_customer( $row );
				$title    = $row->item_title ? (string) $row->item_title : __( '(deleted item)', 'minn-admin' );
				$nights   = minn_admin_jet_booking_nights( $row->check_in_date, $row->check_out_date );
				$who      = array(
					array( 'label' => __( 'Name', 'minn-admin' ), 'value' => '' !== $customer['name'] ? $customer['name'] : '—' ),
					array( 'label' => __( 'Email', 'minn-admin' ), 'value' => '' !== $customer['email'] ? $customer['email'] : '—' ),
				);
				$extra = array();
				foreach ( minn_admin_jet_booking_extra_columns() as $col => $label ) {
					$v = isset( $row->$col ) ? trim( (string) $row->$col ) : '';
					if ( '' !== $v ) {
						$extra[] = array( 'label' => $label, 'value' => $v );
					}
				}
				$in_lbl  = minn_admin_jet_booking_day_label( $row->check_in_date );
				$out_lbl = minn_admin_jet_booking_day_label( $row->check_out_date );
				try {
					$t_in  = jet_abaf()->tools->format_timepicker_time( $row->check_in_time ?? null );
					$t_out = jet_abaf()->tools->format_timepicker_time( $row->check_out_time ?? null );
				} catch ( \Throwable $e ) {
					$t_in  = '';
					$t_out = '';
				}
				$when = array(
					array( 'label' => __( 'Booked', 'minn-admin' ), 'value' => $title ),
					array( 'label' => __( 'Check-in', 'minn-admin' ), 'value' => trim( $in_lbl . ' ' . $t_in ) ),
					array( 'label' => __( 'Check-out', 'minn-admin' ), 'value' => trim( $out_lbl . ' ' . $t_out ) ),
					array( 'label' => __( 'Nights', 'minn-admin' ), 'value' => (string) $nights ),
					array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status ),
				);
				if ( ! empty( $row->apartment_unit ) ) {
					$when[] = array( 'label' => __( 'Unit', 'minn-admin' ), 'value' => (string) $row->apartment_unit );
				}
				if ( ! empty( $row->order_id ) ) {
					$when[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => '#' . (int) $row->order_id );
				}
				$sections = array(
					array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => array_merge( $who, $extra ) ),
					array( 'title' => __( 'Booking', 'minn-admin' ), 'rows' => $when ),
				);
				return rest_ensure_response( array(
					'kind'     => 'booking',
					'status'   => (string) $row->status,
					'title'    => $title,
					'customer' => array( 'name' => $customer['name'], 'email' => $customer['email'], 'phone' => '' ),
					// allDay + nights tell the shared booking page this is a
					// stay: check-in / check-out dates and a nights count, not
					// a slot with an employee and a duration.
					'booking'  => array(
						'service' => $title,
						'starts'  => minn_admin_jet_booking_iso( $row->check_in_date ),
						'ends'    => minn_admin_jet_booking_iso( $row->check_out_date ),
						'allDay'  => true,
						'nights'  => $nights,
						'notes'   => '',
					),
					'sections' => $sections,
					'adminUrl' => minn_admin_jet_booking_admin_url(),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id  = (int) Minn_Admin::path_param( $request );
				$row = minn_admin_jet_booking_row( $id, minn_admin_jet_booking_vendor_scope() );
				if ( ! $row ) {
					return new WP_Error( 'not_found', __( 'Booking not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				try {
					jet_abaf()->db->delete_booking( array( 'booking_id' => $id ) );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'delete_failed', __( 'JetBooking could not delete that booking.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				if ( minn_admin_jet_booking_row( $id, 0 ) ) {
					return new WP_Error( 'delete_failed', __( 'JetBooking could not delete that booking.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Booking deleted.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-booking/bookings/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			$ok     = minn_admin_jet_booking_statuses();
			if ( ! isset( $ok[ $status ] ) ) {
				return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$id  = (int) Minn_Admin::path_param( $request );
			$row = minn_admin_jet_booking_row( $id, minn_admin_jet_booking_vendor_scope() );
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Booking not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			try {
				// Their DB manager: the table update merges the stored row,
				// sanitizes, writes, and fires jet-booking/db/update/bookings
				// (/status) so workflows, order sync and emails run as if the
				// change came from their own screen.
				jet_abaf()->db->update_booking( $id, array( 'status' => $status ) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'update_failed', __( 'JetBooking could not update that booking.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$after = minn_admin_jet_booking_row( $id, 0 );
			if ( ! $after || (string) $after->status !== $status ) {
				return new WP_Error( 'update_failed', __( 'JetBooking could not update that booking.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'status'  => $status,
				/* translators: %s: booking status label */
				'message' => sprintf( __( 'Booking marked %s.', 'minn-admin' ), strtolower( (string) $ok[ $status ] ) ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-booking/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$admin_url = minn_admin_jet_booking_admin_url();
			$open      = array( array( 'label' => __( 'Open JetBooking ↗', 'minn-admin' ), 'href' => $admin_url ) );
			if ( ! minn_admin_jet_booking_has_tables() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => __( 'Bookings', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No bookings yet', 'minn-admin' ) ) ),
					'actions' => $open,
				) );
			}
			global $wpdb;
			$scope = minn_admin_jet_booking_vendor_scope();
			$from  = minn_admin_jet_booking_from();
			list( $today_sql, $today_p )     = minn_admin_jet_booking_where( 'today', '', $scope );
			list( $pending_sql, $pending_p ) = minn_admin_jet_booking_where( 'pending', '', $scope );
			list( $next_sql, $next_p )       = minn_admin_jet_booking_where( 'upcoming', '', $scope );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$today_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} {$today_sql}", $today_p ) );
			$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} {$pending_sql}", $pending_p ) );
			$next    = $wpdb->get_var( $wpdb->prepare( "SELECT b.check_in_date FROM {$from} {$next_sql} ORDER BY b.check_in_date ASC LIMIT 1", $next_p ) );
			// phpcs:enable
			// Next 14 site-local days, starting today: the shape of the week ahead.
			$chart_days = array();
			for ( $i = 0; $i < 14; $i++ ) {
				$d                = wp_date( 'Y-m-d', time() + $i * DAY_IN_SECONDS );
				$chart_days[ $d ] = array( 'label' => $d, 'value' => 0, 'secondary' => 0 );
			}
			// check_in_date is a naive day epoch (site day at 00:00 read as UTC),
			// the same clock their own screens and the views above use.
			list( $chart_from ) = minn_admin_jet_booking_day_bounds();
			$chart_pending = array_map( 'strval', minn_admin_jet_booking_status_set( 'in_progress' ) );
			$chart_invalid = array_map( 'strval', minn_admin_jet_booking_status_set( 'invalid' ) );
			list( $all_sql, $all_p ) = minn_admin_jet_booking_where( 'all', '', $scope );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$chart_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT b.check_in_date, b.status FROM {$from} {$all_sql} AND b.check_in_date >= %d AND b.check_in_date < %d",
				array_merge( $all_p, array( $chart_from, $chart_from + 14 * DAY_IN_SECONDS ) )
			) );
			// phpcs:enable
			foreach ( (array) $chart_rows as $cr ) {
				if ( in_array( (string) $cr->status, $chart_invalid, true ) ) {
					continue;
				}
				$d = gmdate( 'Y-m-d', (int) $cr->check_in_date );
				if ( ! isset( $chart_days[ $d ] ) ) {
					continue;
				}
				$chart_days[ $d ]['value']++;
				if ( in_array( (string) $cr->status, $chart_pending, true ) ) {
					$chart_days[ $d ]['secondary']++;
				}
			}
			return rest_ensure_response( array(
				'rows'    => array(
					array( 'label' => __( 'Today', 'minn-admin' ), 'value' => number_format_i18n( $today_n ), 'hint' => __( 'check-ins', 'minn-admin' ) ),
					array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( $pending ) ),
					array(
						'label' => __( 'Next', 'minn-admin' ),
						'value' => $next ? minn_admin_jet_booking_day_label( $next ) : '—',
						'hint'  => $next ? __( 'check-in', 'minn-admin' ) : __( 'Nothing upcoming', 'minn-admin' ),
					),
				),
				'chart'   => array(
					'title'     => __( 'Next 14 days', 'minn-admin' ),
					'primary'   => __( 'Booked', 'minn-admin' ),
					'secondary' => __( 'Pending', 'minn-admin' ),
					'points'    => array_values( $chart_days ),
				),
				'actions' => $open,
			) );
		},
	) );
} );
