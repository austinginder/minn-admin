<?php
/**
 * Bundled adapter: LatePoint (Bookings family).
 *
 * LatePoint stores appointments in {prefix}latepoint_bookings with a UTC
 * start_datetime_utc plus a site-local start_date. Customers, services and
 * agents are sibling tables. There is no public CPT, so nothing appears in
 * Content. This surface is the same inbox as Amelia: upcoming / pending /
 * today / canceled, a contact card, approve / cancel / no-show through
 * OsBookingModel::update_status (fires latepoint_booking_updated so
 * notifications stay LatePoint's), and a status card with a deep link.
 * Calendar, agents and the booking form stay in LatePoint.
 *
 * Statuses are theirs: approved, pending, cancelled, no_show, completed
 * (plus payment_pending). Caps go through OsRolesHelper::can_user
 * (booking__view / booking__edit). Agents without "all records" only see
 * their own bookings.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_latepoint_active() {
	return defined( 'LATEPOINT_VERSION' ) && class_exists( 'OsBookingModel' );
}

/*
 * LatePoint caches its own copy of the current user the first time anything
 * asks (OsAuthHelper::$current_user). A REST request carrying the login cookie
 * but no X-WP-Nonce is dropped to user 0 by core's cookie check AFTER that
 * cache can already be filled, and LatePoint never refreshes it (only its
 * Abilities module, off by default, hooks the reset). Without this, can_user()
 * kept answering for the admin on a nonce-less cross-site request. Reset the
 * cache whenever WordPress changes the current user, exactly as their own
 * Abilities module does.
 */
add_action( 'set_current_user', function () {
	if ( class_exists( 'OsAuthHelper' ) && method_exists( 'OsAuthHelper', 'reset_current_user' ) ) {
		OsAuthHelper::reset_current_user();
	}
}, 1 );

function minn_admin_latepoint_can_read() {
	// Logged out (including a nonce-less cookie request core demoted to 0)
	// never reaches LatePoint's check at all.
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( class_exists( 'OsRolesHelper' ) && method_exists( 'OsRolesHelper', 'can_user' ) ) {
		return (bool) OsRolesHelper::can_user( 'booking__view' );
	}
	return current_user_can( 'manage_options' );
}

function minn_admin_latepoint_can_write() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( class_exists( 'OsRolesHelper' ) && method_exists( 'OsRolesHelper', 'can_user' ) ) {
		return (bool) OsRolesHelper::can_user( 'booking__edit' );
	}
	return current_user_can( 'manage_options' );
}

function minn_admin_latepoint_has_tables() {
	return Minn_Admin_Kernel_SQL::exists( 'latepoint_bookings' );
}

/** Whether LatePoint 5's orders exist (bookings hang off their order items). */
function minn_admin_latepoint_has_orders() {
	return Minn_Admin_Kernel_SQL::exists( 'latepoint_orders' ) && Minn_Admin_Kernel_SQL::exists( 'latepoint_order_items' );
}

/**
 * The dimensions LatePoint scopes bookings on, for the current user.
 *
 * OsBookingModel::filter_allowed_records() constrains agent_id AND location_id
 * AND service_id, each independently, so honouring only the agent dimension
 * would show a location- or service-restricted role bookings its own screens
 * hide. A role limited to one location gets every agent who works there in its
 * allowed-agent list, and those agents' bookings elsewhere would slip through.
 *
 * @return array<string,int[]|null> Per dimension: null = unrestricted, int[] =
 *                                  allowed ids, array( -1 ) = nothing allowed.
 */
function minn_admin_latepoint_scope() {
	$open = array( 'agent' => null, 'location' => null, 'service' => null );
	// Not knowing the scope is not the same as there being none. If their auth
	// helper is missing or throws, the answer is unknown, and a booking list is
	// customer data: an empty list is a bug someone reports, an unscoped one is
	// a leak nobody sees. Bookly and Amelia fail closed in this same position.
	$deny = array( 'agent' => array( -1 ), 'location' => array( -1 ), 'service' => array( -1 ) );
	if ( ! class_exists( 'OsAuthHelper' ) ) {
		return $deny;
	}
	try {
		$user = OsAuthHelper::get_current_user();
	} catch ( \Throwable $e ) {
		return $deny;
	}
	if ( defined( 'LATEPOINT_USER_TYPE_ADMIN' ) && $user->backend_user_type === LATEPOINT_USER_TYPE_ADMIN ) {
		return $open;
	}
	$scope = array();
	foreach ( array_keys( $open ) as $type ) {
		if ( method_exists( $user, 'are_all_records_allowed' ) && $user->are_all_records_allowed( $type ) ) {
			$scope[ $type ] = null;
			continue;
		}
		$ids = method_exists( $user, 'get_allowed_records' ) ? $user->get_allowed_records( $type ) : array();
		$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		$scope[ $type ] = $ids ? $ids : array( -1 );
	}
	return $scope;
}

/**
 * The scope as conditions, one per restricted dimension, on the bookings
 * table (or a joined one's columns with a prefix): null when a dimension
 * allows nothing.
 *
 * Every read and every by-id ownership re-check goes through this, so a
 * dimension can never be enforced on one path and dropped on another.
 *
 * @param array  $scope  From minn_admin_latepoint_scope().
 * @param string $prefix Column prefix ('ev.' for an event's columns).
 * @return array|null
 */
function minn_admin_latepoint_scope_where( $scope, $prefix = '' ) {
	$where = array();
	foreach ( array( 'agent', 'location', 'service' ) as $type ) {
		$ids = isset( $scope[ $type ] ) ? $scope[ $type ] : null;
		if ( null === $ids ) {
			continue;
		}
		if ( array( -1 ) === $ids ) {
			return null;
		}
		$where[ $prefix . $type . '_id' ] = array_values( array_map( 'intval', $ids ) );
	}
	return $where;
}

/** UTC MySQL datetime → ISO-8601 with a trailing Z. */
function minn_admin_latepoint_utc_iso( $mysql_datetime ) {
	$dt = trim( (string) $mysql_datetime );
	if ( '' === $dt || 0 === strpos( $dt, '0000-00-00' ) ) {
		return '';
	}
	$ts = strtotime( $dt . ' UTC' );
	return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
}

/**
 * A UTC MySQL datetime as the site writes dates and times, in the site's
 * zone. Detail rows print their value as text, so an ISO string reached the
 * dialog as "2026-10-09T19:44:00Z".
 */
function minn_admin_latepoint_when_label( $mysql_datetime ) {
	$iso = minn_admin_latepoint_utc_iso( $mysql_datetime );
	return '' === $iso ? '' : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $iso ) );
}

/**
 * Event registrations (LatePoint 5.7+ events). Registrations are created on a
 * paid order already `confirmed`; `checked_in` is parked upstream (their
 * controller and button are commented out), so the only mutation is Cancel,
 * which mirrors their controller exactly. Every route needs their events
 * setting on, like their own controller.
 */
function minn_admin_latepoint_events_ready() {
	if ( ! class_exists( 'OsEventRegistrationModel' ) || ! class_exists( 'OsSettingsHelper' ) || ! OsSettingsHelper::is_on( 'enable_events_functionality' ) ) {
		return false;
	}
	return Minn_Admin_Kernel_SQL::exists( 'latepoint_event_registrations' );
}

function minn_admin_latepoint_can_view_regs() {
	return is_user_logged_in() && minn_admin_latepoint_events_ready() && class_exists( 'OsRolesHelper' ) && (bool) OsRolesHelper::can_user( 'event_registration__view' );
}

function minn_admin_latepoint_can_edit_regs() {
	return is_user_logged_in() && minn_admin_latepoint_events_ready() && class_exists( 'OsRolesHelper' ) && (bool) OsRolesHelper::can_user( 'event_registration__edit' );
}

/**
 * Scope for registrations, via their event's agent and location. Their own
 * registrations screen applies no scoping; Minn applies the viewer's agent and
 * location restrictions anyway so it can never show more than LatePoint. The
 * service dimension does not apply to events.
 */
function minn_admin_latepoint_reg_scope() {
	$scope = minn_admin_latepoint_scope();
	unset( $scope['service'] );
	return minn_admin_latepoint_scope_where( $scope, 'ev.' );
}

function minn_admin_latepoint_regs_admin_url() {
	if ( class_exists( 'OsRouterHelper' ) && method_exists( 'OsRouterHelper', 'build_link' ) ) {
		try {
			return OsRouterHelper::build_link( array( 'event_registrations', 'index' ) );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		}
	}
	return admin_url( 'admin.php?page=latepoint&route_name=event_registrations__index' );
}

/**
 * The registrations list view (views[] on the LatePoint surface), or null.
 */
function minn_admin_latepoint_regs_view() {
	if ( ! minn_admin_latepoint_can_view_regs() ) {
		return null;
	}
	$actions = array(
		array(
			'label' => __( 'Open in LatePoint ↗', 'minn-admin' ),
			'href'  => minn_admin_latepoint_regs_admin_url(),
		),
	);
	if ( minn_admin_latepoint_can_edit_regs() ) {
		$actions[] = Minn_Admin_Kernel::ui( 'latepoint/event-registrations', 'cancel', array(
			'label'   => __( 'Cancel registration', 'minn-admin' ),
			'confirm' => __( 'Cancel this registration? The seats are released and LatePoint sends its cancellation notice. The order is not refunded.', 'minn-admin' ),
			'danger'  => true,
			'key'     => 'status',
			'when'    => 'confirmed',
		) );
	}
	return Minn_Admin_Kernel::collection( 'latepoint/event-registrations', array(
		'viewLabel' => __( 'Event registrations', 'minn-admin' ),
		'filter'    => array(
			'label'   => __( 'When', 'minn-admin' ),
			'options' => array(
				array( 'upcoming', __( 'Upcoming', 'minn-admin' ) ),
				array( 'cancelled', __( 'Cancelled', 'minn-admin' ) ),
				array( 'all', __( 'All', 'minn-admin' ) ),
			),
		),
		'columns'   => array(
			array( 'key' => 'customer', 'label' => __( 'Customer', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.5fr)' ),
			array( 'key' => 'event', 'label' => __( 'Event', 'minn-admin' ), 'width' => 'minmax(0,1.3fr)' ),
			array( 'key' => 'quantity', 'label' => __( 'Seats', 'minn-admin' ), 'format' => 'num', 'width' => '64px' ),
			array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '130px' ),
			array( 'key' => 'starts', 'label' => __( 'Starts', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
		),
		'detail'    => array(
			'sectionsRoute' => 'minn-admin/v1/latepoint/event-registrations/{id}',
		),
		'actions'   => $actions,
	) );
}

function minn_admin_latepoint_admin_url() {
	if ( class_exists( 'OsRouterHelper' ) && method_exists( 'OsRouterHelper', 'build_link' ) ) {
		try {
			return OsRouterHelper::build_link( array( 'bookings', 'index' ) );
		} catch ( \Throwable $e ) {
			// fall through
		}
	}
	return admin_url( 'admin.php?page=latepoint&route_name=bookings__index' );
}

/**
 * A booking's detail. LatePoint 5 keeps the customer's comment on the
 * booking's order (booking -> order item -> order); LatePoint 4 kept it on
 * the booking, a column dbDelta leaves in place after their migration copies
 * it to the order. The admin-only note is on the customer. Both are text the
 * client escapes: the comment is the visitor's own.
 */
function minn_admin_latepoint_detail( array $r ) {
	$row     = (object) $r;
	$comment = '';
	if ( ! empty( $row->order_item_id ) ) {
		$item  = Minn_Admin_Kernel_SQL::select( 'latepoint_order_items', array( 'order_id' ), array( 'id' => (int) $row->order_item_id ), array(), 1 );
		$order = $item ? Minn_Admin_Kernel_SQL::select( 'latepoint_orders', array( 'customer_comment' ), array( 'id' => (int) $item[0]['order_id'] ), array(), 1 ) : array();
		$comment = $order ? trim( (string) $order[0]['customer_comment'] ) : '';
	}
	if ( '' === $comment && isset( $row->customer_comment ) ) {
		$comment = trim( (string) $row->customer_comment );
	}
	$when = array();
	if ( $row->booking_code ) {
		$when[] = array( 'label' => __( 'Code', 'minn-admin' ), 'value' => (string) $row->booking_code );
	}
	$internal = trim( (string) $row->customer_admin_notes );
	if ( '' !== $internal ) {
		$when[] = array( 'label' => __( 'Notes', 'minn-admin' ), 'value' => $internal );
	}
	if ( '' !== $comment ) {
		$when[] = array( 'label' => __( 'Booking note', 'minn-admin' ), 'value' => $comment );
	}
	// The page's Notes card holds one note: the internal one, else the
	// customer's. With both, the customer's gets a card of its own.
	$sections = array();
	if ( '' !== $internal && '' !== $comment ) {
		$sections[] = array( 'title' => __( 'From the customer', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Booking note', 'minn-admin' ), 'value' => $comment ) ) );
	}
	return minn_admin_booking_detail( array(
		'noun'     => __( 'Appointment', 'minn-admin' ),
		'name'     => minn_admin_booking_name( $row->customer_first, $row->customer_last ),
		'email'    => $row->customer_email,
		'phone'    => $row->customer_phone,
		'people'   => $row->total_attendees,
		'service'  => $row->service_name,
		'employee' => minn_admin_booking_name( $row->agent_first, $row->agent_last ),
		'starts'   => minn_admin_latepoint_utc_iso( $row->start_datetime_utc ),
		'ends'     => minn_admin_latepoint_utc_iso( $row->end_datetime_utc ),
		'status'   => $row->status,
		'rows'     => $when,
		'sections' => $sections,
		'booking'  => array( 'code' => $row->booking_code ? (string) $row->booking_code : '' ),
		'notes'    => '' !== $internal ? $internal : $comment,
		'admin'    => minn_admin_latepoint_admin_url(),
	) );
}

$minn_latepoint_list = array( 'id', 'status', 'start_datetime_utc', 'end_datetime_utc', 'booking_code', 'total_attendees', 's.name' => 'service_name', 'c.first_name' => 'customer_first', 'c.last_name' => 'customer_last', 'c.email' => 'customer_email', 'a.first_name' => 'agent_first', 'a.last_name' => 'agent_last' );

$minn_latepoint_views = array(
	// Approved, pending or awaiting payment, and still ahead.
	'upcoming' => function () {
		return array( 'status' => array( 'approved', 'pending', 'payment_pending' ), 'start_datetime_utc' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc' ) ) );
	},
	'pending'  => array( 'status' => array( 'pending', 'payment_pending' ) ),
	// start_date is LatePoint's site-local day.
	'today'    => function () {
		return array( 'start_date' => Minn_Admin_Kernel::at( 'date', 0 ) );
	},
	'canceled' => array( 'status' => array( 'cancelled', 'no_show' ) ),
);

// OsBookingModel::filter_allowed_records() constrains agent, location and
// service each on its own: every read here holds all three.
minn_admin_resource( 'latepoint/bookings', function () use ( $minn_latepoint_list, $minn_latepoint_views ) {
	return array(
		'active'  => 'minn_admin_latepoint_active',
		'gates'   => array( 'view' => 'minn_admin_latepoint_can_read', 'status' => 'minn_admin_latepoint_can_write' ),
		'labels'  => array( 'not_found' => __( 'Appointment not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'latepoint_bookings',
			'key'     => 'id',
			'joins'   => array(
				'c' => array( 'table' => 'latepoint_customers', 'on' => array( 'id' => 'e.customer_id' ) ),
				's' => array( 'table' => 'latepoint_services', 'on' => array( 'id' => 'e.service_id' ) ),
				'a' => array( 'table' => 'latepoint_agents', 'on' => array( 'id' => 'e.agent_id' ) ),
			),
			'columns' => $minn_latepoint_list,
			// The order item (LatePoint 5) and the comment column (4) only where they are.
			'detail'  => function () use ( $minn_latepoint_list ) {
				return array_merge(
					$minn_latepoint_list,
					array( 'c.phone' => 'customer_phone', 'c.admin_notes' => 'customer_admin_notes' ),
					minn_admin_latepoint_has_orders() ? array( 'order_item_id' ) : array(),
					Minn_Admin_Kernel_SQL::has_column( 'latepoint_bookings', 'customer_comment' ) ? array( 'customer_comment' ) : array()
				);
			},
			'where'   => function () {
				return minn_admin_latepoint_scope_where( minn_admin_latepoint_scope() );
			},
		),
		'list'    => array(
			'filter' => minn_admin_booking_filter( $minn_latepoint_views ),
			'search' => array( 'columns' => array( 'c.first_name', 'c.last_name', 'c.email', 's.name', 'a.first_name', 'a.last_name', 'booking_code' ) ),
			'order'  => array( 'column' => 'start_datetime_utc', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				return minn_admin_booking_row( $r['id'], minn_admin_booking_name( $r['customer_first'], $r['customer_last'] ), $r['customer_email'], $r['service_name'], $r['status'], $r['start_datetime_utc'], minn_admin_booking_name( $r['agent_first'], $r['agent_last'] ) );
			},
			'fields' => minn_admin_booking_fields( 'utc' ),
		),
		'item'    => array( 'view' => 'minn_admin_latepoint_detail' ),
		'actions' => array(
			// Through their model's update_status(), which runs their status
			// hooks and notifications.
			'status' => minn_admin_booking_move( array(
				'known'  => array( 'approved', 'pending', 'cancelled', 'no_show', 'completed', 'payment_pending' ),
				'write'  => function ( $id, $status ) {
					try {
						$booking = new OsBookingModel( $id );
						if ( $booking->is_new_record() ) {
							return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
						}
						if ( ! $booking->update_status( $status ) ) {
							$msg = method_exists( $booking, 'get_error_messages' ) ? implode( ' ', (array) $booking->get_error_messages() ) : '';
							return new WP_Error( 'update_failed', $msg ? $msg : __( 'LatePoint could not update that appointment.', 'minn-admin' ), array( 'status' => 400 ) );
						}
					} catch ( \Throwable $e ) {
						return new WP_Error( 'update_failed', __( 'LatePoint could not update that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
					}
				},
				'fail'   => __( 'LatePoint could not update that appointment.', 'minn-admin' ),
				/* translators: %s: appointment status */
				'marked' => __( 'Appointment marked %s.', 'minn-admin' ),
			) ),
		),
	);
} );

// Event registrations (5.7+): created_at is UTC (their model save forces
// it); the event's start and end are *_utc columns. Their own screen applies
// no scope; Minn holds the event's agent and location to the viewer's.
minn_admin_resource( 'latepoint/event-registrations', function () {
	return array(
		'active'  => 'minn_admin_latepoint_active',
		'gates'   => array( 'view' => 'minn_admin_latepoint_can_view_regs', 'cancel' => 'minn_admin_latepoint_can_edit_regs' ),
		'labels'  => array( 'not_found' => __( 'Registration not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'latepoint_event_registrations',
			'key'     => 'id',
			'joins'   => array(
				'ev' => array( 'table' => 'latepoint_events', 'on' => array( 'id' => 'e.event_id' ) ),
				'c'  => array( 'table' => 'latepoint_customers', 'on' => array( 'id' => 'e.customer_id' ) ),
			),
			'columns' => array( 'id', 'quantity', 'status', 'ev.name' => 'event_name', 'ev.start_datetime_utc', 'c.first_name', 'c.last_name', 'c.email' ),
			'detail'  => array( '*', 'ev.name' => 'event_name', 'ev.start_datetime_utc', 'ev.end_datetime_utc', 'c.first_name', 'c.last_name', 'c.email', 'c.phone' ),
			'where'   => 'minn_admin_latepoint_reg_scope',
		),
		'list'    => array(
			'filter' => array(
				'param'   => 'range',
				'default' => 'upcoming',
				'values'  => array(
					'upcoming'  => function () {
						return array( 'ev.end_datetime_utc' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc' ) ), 'status' => array( 'not' => array( 'cancelled' ) ) );
					},
					'cancelled' => array( 'status' => 'cancelled' ),
					'all'       => array(),
				),
				'order'   => array( 'upcoming' => 'ASC' ),
			),
			'search' => array( 'columns' => array( 'c.first_name', 'c.last_name', 'c.email', 'ev.name', 'registration_code' ) ),
			'order'  => array( 'column' => 'ev.start_datetime_utc', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				return array(
					'id'       => (int) $r['id'],
					'customer' => minn_admin_booking_name( $r['first_name'], $r['last_name'] ) . ( $r['email'] ? ' · ' . $r['email'] : '' ),
					'event'    => (string) $r['event_name'],
					'quantity' => (int) $r['quantity'],
					'status'   => (string) $r['status'],
					'starts'   => $r['start_datetime_utc'],
				);
			},
			'fields' => array(
				'id'       => 'int',
				'customer' => 'text',
				'event'    => 'text',
				'quantity' => 'int',
				'status'   => 'text',
				'starts'   => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'z' ),
			),
		),
		'item'    => array( 'view' => function ( array $r ) {
			$row        = (object) $r;
			$registered = minn_admin_latepoint_when_label( $row->created_at );
			$starts     = minn_admin_latepoint_when_label( $row->start_datetime_utc );
			$ends       = minn_admin_latepoint_when_label( $row->end_datetime_utc );
			return array(
				'sections' => array(
					array(
						'title' => __( 'Registration', 'minn-admin' ),
						'rows'  => array_values( array_filter( array(
							array( 'label' => __( 'Code', 'minn-admin' ), 'value' => (string) $row->registration_code, 'type' => 'code' ),
							array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status, 'type' => 'pill' ),
							array( 'label' => __( 'Seats', 'minn-admin' ), 'value' => (string) (int) $row->quantity ),
							'' !== (string) $row->payment_status ? array( 'label' => __( 'Payment', 'minn-admin' ), 'value' => (string) $row->payment_status, 'type' => 'pill' ) : null,
							'' !== $registered ? array( 'label' => __( 'Registered', 'minn-admin' ), 'value' => $registered ) : null,
							'' !== trim( (string) $row->notes ) ? array( 'label' => __( 'Notes', 'minn-admin' ), 'value' => (string) $row->notes ) : null,
						) ) ),
					),
					array(
						'title' => __( 'Customer', 'minn-admin' ),
						'rows'  => array_values( array_filter( array(
							array( 'label' => __( 'Name', 'minn-admin' ), 'value' => minn_admin_booking_name( $row->first_name, $row->last_name ) ),
							$row->email ? array( 'label' => __( 'Email', 'minn-admin' ), 'value' => (string) $row->email, 'type' => 'email' ) : null,
							$row->phone ? array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => (string) $row->phone ) : null,
						) ) ),
					),
					array(
						'title' => __( 'Event', 'minn-admin' ),
						'rows'  => array_values( array_filter( array(
							array( 'label' => __( 'Event', 'minn-admin' ), 'value' => (string) $row->event_name ),
							'' !== $starts ? array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => $starts ) : null,
							'' !== $ends ? array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => $ends ) : null,
						) ) ),
					),
				),
				'adminUrl' => minn_admin_latepoint_regs_admin_url(),
			);
		} ),
		'actions' => array(
			// Mirrors their OsEventRegistrationsController::cancel(): already
			// cancelled is a no-op so the cancellation notice is not queued
			// twice, then status + save() + the same
			// latepoint_event_registration_cancelled action with the old status
			// (their notification and e-ticket listeners ride it). The order
			// is untouched.
			'cancel' => array(
				'gate'   => 'cancel',
				'run'    => function ( $id ) {
					$reg = new OsEventRegistrationModel( $id );
					if ( $reg->is_cancelled() ) {
						return 'already';
					}
					$old         = $reg->status;
					$reg->status = defined( 'LATEPOINT_EVENT_REGISTRATION_STATUS_CANCELLED' ) ? LATEPOINT_EVENT_REGISTRATION_STATUS_CANCELLED : 'cancelled';
					if ( ! $reg->save() ) {
						return new WP_Error( 'minn_lp_cancel', __( 'LatePoint could not cancel that registration.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					do_action( 'latepoint_event_registration_cancelled', $reg, $old );
					return 'cancelled';
				},
				'expect' => array( 'status' => array( 'cancelled' ) ),
				'fail'   => __( 'LatePoint could not cancel that registration.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh, $ran ) {
					return array( 'status' => 'cancelled', 'message' => 'already' === $ran ? __( 'Registration was already cancelled.', 'minn-admin' ) : __( 'Registration cancelled.', 'minn-admin' ) );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'latepoint/status', array(
	'active' => 'minn_admin_latepoint_active',
	'gate'   => 'minn_admin_latepoint_can_read',
	'run'    => function () use ( $minn_latepoint_views ) {
		return minn_admin_booking_card( 'latepoint/bookings', $minn_latepoint_views, array(
			'ready' => minn_admin_latepoint_has_tables(),
			'noun'  => __( 'Appointments', 'minn-admin' ),
			'empty' => __( 'No bookings yet', 'minn-admin' ),
			'scope' => function () {
				return minn_admin_latepoint_scope_where( minn_admin_latepoint_scope() );
			},
			'next'  => 'start_datetime_utc',
			'iso'   => 'minn_admin_latepoint_utc_iso',
			// The next 14 site-local days (start_date is their site-local day
			// column): approved apart from the rest.
			'chart' => array( 'column' => 'start_date', 'clock' => 'date', 'where' => array( 'status' => array( 'approved', 'pending', 'payment_pending' ) ), 'secondary' => array( 'pending', 'payment_pending' ) ),
			'open'  => array( __( 'Open LatePoint ↗', 'minn-admin' ), minn_admin_latepoint_admin_url() ),
		) );
	},
) );

minn_admin_add_surface( 'latepoint', function () {
	return minn_admin_latepoint_active() && minn_admin_latepoint_can_read();
}, function () {
	$regs = minn_admin_latepoint_regs_view();
	return minn_admin_booking_surface( 'latepoint/bookings', array(
		'sub'     => 'LatePoint',
		'plugin'  => 'latepoint',
		'status'  => 'latepoint/status',
		'noun'    => __( 'Appointments', 'minn-admin' ),
		'open'    => array( __( 'Open in LatePoint ↗', 'minn-admin' ), minn_admin_latepoint_admin_url() ),
		'moves'   => minn_admin_latepoint_can_write() ? array(
			array( __( 'Approve', 'minn-admin' ), 'approved', 'pending' ),
			array( __( 'Cancel', 'minn-admin' ), 'cancelled', array( 'approved', 'pending' ), __( 'Cancel this appointment? LatePoint will notify the customer.', 'minn-admin' ) ),
			array( __( 'Mark no-show', 'minn-admin' ), 'no_show', 'approved' ),
		) : array(),
		'width'   => '130px',
		'surface' => $regs ? array( 'views' => array( $regs ) ) : array(),
	) );
} );
