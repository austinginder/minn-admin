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

function minn_admin_latepoint_table( $suffix ) {
	global $wpdb;
	return $wpdb->prefix . 'latepoint_' . $suffix;
}

function minn_admin_latepoint_has_tables() {
	global $wpdb;
	$t = minn_admin_latepoint_table( 'bookings' );
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
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
 * One WHERE fragment per restricted dimension, for a bookings table alias.
 *
 * Every read and every by-id ownership re-check goes through this, so a
 * dimension can never be enforced on one path and dropped on another.
 *
 * @param array       $scope From minn_admin_latepoint_scope().
 * @param string      $alias Table alias ('b' or '').
 * @return array{sql: string[], params: int[], deny: bool}
 */
function minn_admin_latepoint_scope_sql( $scope, $alias = 'b' ) {
	$prefix = '' === $alias ? '' : $alias . '.';
	$sql    = array();
	$params = array();
	$deny   = false;
	foreach ( array( 'agent', 'location', 'service' ) as $type ) {
		$ids = isset( $scope[ $type ] ) ? $scope[ $type ] : null;
		if ( null === $ids ) {
			continue;
		}
		if ( array( -1 ) === $ids ) {
			$deny = true;
			continue;
		}
		$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql[]  = "{$prefix}{$type}_id IN ({$in})";
		$params = array_merge( $params, $ids );
	}
	return array( 'sql' => $sql, 'params' => $params, 'deny' => $deny );
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

function minn_admin_latepoint_display_name( $first, $last ) {
	$name = trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );
	return '' !== $name ? $name : __( '(no name)', 'minn-admin' );
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
	global $wpdb;
	$t = minn_admin_latepoint_table( 'event_registrations' );
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
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
function minn_admin_latepoint_reg_scope_sql() {
	$scope = minn_admin_latepoint_scope();
	unset( $scope['service'] );
	return minn_admin_latepoint_scope_sql( $scope, 'e' );
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
		$actions[] = array(
			'label'   => __( 'Cancel registration', 'minn-admin' ),
			'method'  => 'POST',
			'route'   => 'minn-admin/v1/latepoint/event-registrations/{id}/cancel',
			'confirm' => __( 'Cancel this registration? The seats are released and LatePoint sends its cancellation notice. The order is not refunded.', 'minn-admin' ),
			'danger'  => true,
			'when'    => array( 'key' => 'status', 'equals' => 'confirmed' ),
		);
	}
	return array(
		'viewLabel' => __( 'Event registrations', 'minn-admin' ),
		'route'     => 'minn-admin/v1/latepoint/event-registrations',
		'pageQuery' => 'per_page=25&page={page}',
		'search'    => 'search={q}',
		'itemsKey'  => 'items',
		'totalKey'  => 'total',
		'filter'    => array(
			'label'   => __( 'When', 'minn-admin' ),
			'options' => array(
				array( 'upcoming', __( 'Upcoming', 'minn-admin' ) ),
				array( 'cancelled', __( 'Cancelled', 'minn-admin' ) ),
				array( 'all', __( 'All', 'minn-admin' ) ),
			),
			'query'   => 'range={v}',
		),
		'columns'   => array(
			array( 'key' => 'customer', 'label' => __( 'Customer', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.5fr)' ),
			array( 'key' => 'event', 'label' => __( 'Event', 'minn-admin' ), 'width' => 'minmax(0,1.3fr)' ),
			array( 'key' => 'quantity', 'label' => __( 'Seats', 'minn-admin' ), 'format' => 'num', 'width' => '64px' ),
			array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
			array( 'key' => 'starts', 'label' => __( 'Starts', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
		),
		'detail'    => array(
			'sectionsRoute' => 'minn-admin/v1/latepoint/event-registrations/{id}',
		),
		'actions'   => $actions,
	);
}

/**
 * One registration joined to its event and customer, within the viewer's
 * scope (the same scope the list applies), or null.
 */
function minn_admin_latepoint_reg_row( $id ) {
	global $wpdb;
	$scope = minn_admin_latepoint_reg_scope_sql();
	if ( $scope['deny'] ) {
		return null;
	}
	$r     = minn_admin_latepoint_table( 'event_registrations' );
	$e     = minn_admin_latepoint_table( 'events' );
	$c     = minn_admin_latepoint_table( 'customers' );
	$where = array_merge( array( 'r.id = %d' ), $scope['sql'] );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT r.*, e.name AS event_name, e.start_datetime_utc, e.end_datetime_utc, c.first_name, c.last_name, c.email, c.phone FROM {$r} r LEFT JOIN {$e} e ON e.id = r.event_id LEFT JOIN {$c} c ON c.id = r.customer_id WHERE " . implode( ' AND ', $where ),
		array_merge( array( (int) $id ), $scope['params'] )
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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_latepoint_active() || ! minn_admin_latepoint_can_read() ) {
		return $surfaces;
	}
	$can_write = minn_admin_latepoint_can_write();
	$actions   = array(
		array(
			'label' => __( 'Open in LatePoint ↗', 'minn-admin' ),
			'href'  => minn_admin_latepoint_admin_url(),
		),
	);
	if ( $can_write ) {
		$actions = array_merge( array(
			array(
				'label'  => __( 'Approve', 'minn-admin' ),
				'method' => 'POST',
				'route'  => 'minn-admin/v1/latepoint/bookings/{id}/status',
				'body'   => array( 'status' => 'approved' ),
				'when'   => array( 'key' => 'status', 'equals' => 'pending' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/latepoint/bookings/{id}/status',
				'body'    => array( 'status' => 'cancelled' ),
				'confirm' => __( 'Cancel this appointment? LatePoint will notify the customer.', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'status', 'equals' => 'approved' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/latepoint/bookings/{id}/status',
				'body'    => array( 'status' => 'cancelled' ),
				'confirm' => __( 'Cancel this appointment? LatePoint will notify the customer.', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'status', 'equals' => 'pending' ),
			),
			array(
				'label'  => __( 'Mark no-show', 'minn-admin' ),
				'method' => 'POST',
				'route'  => 'minn-admin/v1/latepoint/bookings/{id}/status',
				'body'   => array( 'status' => 'no_show' ),
				'when'   => array( 'key' => 'status', 'equals' => 'approved' ),
			),
		), $actions );
	}
	$bulk = array();
	if ( $can_write ) {
		$bulk = array(
			array(
				'label'  => __( 'Approve', 'minn-admin' ),
				'method' => 'POST',
				'route'  => 'minn-admin/v1/latepoint/bookings/{id}/status',
				'body'   => array( 'status' => 'approved' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/latepoint/bookings/{id}/status',
				'body'    => array( 'status' => 'cancelled' ),
				'danger'  => true,
			),
		);
	}
	$surfaces['latepoint'] = array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'commerce',
		'sub'        => 'LatePoint',
		'plugin'     => 'latepoint',
		'icon'       => 'calendar',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/latepoint/status' ),
		'collection' => array(
			'viewLabel' => __( 'Appointments', 'minn-admin' ),
			'route'     => 'minn-admin/v1/latepoint/bookings',
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
				array( 'key' => 'service', 'label' => __( 'Service', 'minn-admin' ) ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/latepoint/bookings/{id}',
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		),
	);
	$regs = minn_admin_latepoint_regs_view();
	if ( $regs ) {
		$surfaces['latepoint']['views'] = array( $regs );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_latepoint_active() ) {
		return;
	}
	$perm = 'minn_admin_latepoint_can_read';

	register_rest_route( 'minn-admin/v1', '/latepoint/bookings', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_latepoint_has_tables() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$bookings = minn_admin_latepoint_table( 'bookings' );
			$customers = minn_admin_latepoint_table( 'customers' );
			$services  = minn_admin_latepoint_table( 'services' );
			$agents    = minn_admin_latepoint_table( 'agents' );
			$per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$view      = sanitize_key( (string) $request->get_param( 'range' ) );
			if ( '' === $view ) {
				$view = 'upcoming';
			}
			$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
			$scope  = minn_admin_latepoint_scope_sql( minn_admin_latepoint_scope() );
			$now    = gmdate( 'Y-m-d H:i:s' );

			$where  = array( '1=1' );
			$params = array();
			if ( $scope['deny'] ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$where  = array_merge( $where, $scope['sql'] );
			$params = array_merge( $params, $scope['params'] );

			if ( 'pending' === $view ) {
				$where[] = "b.status IN ('pending','payment_pending')";
			} elseif ( 'canceled' === $view ) {
				$where[] = "b.status IN ('cancelled','no_show')";
			} elseif ( 'today' === $view ) {
				$where[]  = 'b.start_date = %s';
				$params[] = wp_date( 'Y-m-d' );
			} elseif ( 'all' === $view ) {
				// no extra gate
			} else {
				$where[]  = "b.status IN ('approved','pending','payment_pending') AND b.start_datetime_utc >= %s";
				$params[] = $now;
			}

			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(c.first_name LIKE %s OR c.last_name LIKE %s OR c.email LIKE %s OR s.name LIKE %s OR a.first_name LIKE %s OR a.last_name LIKE %s OR b.booking_code LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}

			$where_sql = 'WHERE ' . implode( ' AND ', $where );
			$order     = ( 'upcoming' === $view || 'pending' === $view || 'today' === $view )
				? 'b.start_datetime_utc ASC'
				: 'b.start_datetime_utc DESC';
			$from = "{$bookings} b
				LEFT JOIN {$customers} c ON c.id = b.customer_id
				LEFT JOIN {$services} s ON s.id = b.service_id
				LEFT JOIN {$agents} a ON a.id = b.agent_id";
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count_sql = "SELECT COUNT(*) FROM {$from} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT b.id, b.status, b.start_datetime_utc, b.end_datetime_utc, b.booking_code, b.total_attendees,
					s.name AS service_name,
					c.first_name AS customer_first, c.last_name AS customer_last, c.email AS customer_email,
					a.first_name AS agent_first, a.last_name AS agent_last
				FROM {$from} {$where_sql}
				ORDER BY {$order}
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array_map( function ( $r ) {
				$customer = minn_admin_latepoint_display_name( $r->customer_first, $r->customer_last );
				$email    = trim( (string) $r->customer_email );
				return array(
					'id'       => (int) $r->id,
					'customer' => $email ? $customer . ' · ' . $email : $customer,
					'service'  => $r->service_name ? (string) $r->service_name : __( '(no service)', 'minn-admin' ),
					'status'   => (string) $r->status,
					'date'     => minn_admin_latepoint_utc_iso( $r->start_datetime_utc ),
					'provider' => minn_admin_latepoint_display_name( $r->agent_first, $r->agent_last ),
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/latepoint/bookings/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_latepoint_has_tables() ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			global $wpdb;
			$bookings  = minn_admin_latepoint_table( 'bookings' );
			$customers = minn_admin_latepoint_table( 'customers' );
			$services  = minn_admin_latepoint_table( 'services' );
			$agents    = minn_admin_latepoint_table( 'agents' );
			$id        = (int) Minn_Admin::path_param( $request );
			$scope     = minn_admin_latepoint_scope_sql( minn_admin_latepoint_scope() );
			$scope_sql = '';
			$params    = array( $id );
			if ( $scope['deny'] ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			if ( $scope['sql'] ) {
				$scope_sql = ' AND ' . implode( ' AND ', $scope['sql'] );
				$params    = array_merge( $params, $scope['params'] );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT b.id, b.status, b.start_datetime_utc, b.end_datetime_utc, b.booking_code, b.total_attendees,
					s.name AS service_name,
					c.first_name AS customer_first, c.last_name AS customer_last, c.email AS customer_email, c.phone AS customer_phone,
					a.first_name AS agent_first, a.last_name AS agent_last
				FROM {$bookings} b
				LEFT JOIN {$customers} c ON c.id = b.customer_id
				LEFT JOIN {$services} s ON s.id = b.service_id
				LEFT JOIN {$agents} a ON a.id = b.agent_id
				WHERE b.id = %d{$scope_sql}
				LIMIT 1",
				$params
			) );
			// phpcs:enable
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$who = array(
				array( 'label' => __( 'Name', 'minn-admin' ), 'value' => minn_admin_latepoint_display_name( $row->customer_first, $row->customer_last ) ),
				array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $row->customer_email ? (string) $row->customer_email : '—' ),
				array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => $row->customer_phone ? (string) $row->customer_phone : '—' ),
				array( 'label' => __( 'People', 'minn-admin' ), 'value' => (string) max( 1, (int) $row->total_attendees ) ),
			);
			$when = array(
				array( 'label' => __( 'Service', 'minn-admin' ), 'value' => $row->service_name ? (string) $row->service_name : '—' ),
				array( 'label' => __( 'Employee', 'minn-admin' ), 'value' => minn_admin_latepoint_display_name( $row->agent_first, $row->agent_last ) ),
				array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => minn_admin_latepoint_utc_iso( $row->start_datetime_utc ) ),
				array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => minn_admin_latepoint_utc_iso( $row->end_datetime_utc ) ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status ),
			);
			if ( $row->booking_code ) {
				$when[] = array( 'label' => __( 'Code', 'minn-admin' ), 'value' => (string) $row->booking_code );
			}
			$service = $row->service_name ? (string) $row->service_name : '';
			return rest_ensure_response( array(
				'kind'     => 'booking',
				'status'   => (string) $row->status,
				'title'    => $service ? $service : __( 'Appointment', 'minn-admin' ),
				'customer' => array(
					'name'  => minn_admin_latepoint_display_name( $row->customer_first, $row->customer_last ),
					'email' => $row->customer_email ? (string) $row->customer_email : '',
					'phone' => $row->customer_phone ? (string) $row->customer_phone : '',
				),
				'booking'  => array(
					'service'  => $service,
					'employee' => minn_admin_latepoint_display_name( $row->agent_first, $row->agent_last ),
					'starts'   => minn_admin_latepoint_utc_iso( $row->start_datetime_utc ),
					'ends'     => minn_admin_latepoint_utc_iso( $row->end_datetime_utc ),
					'people'   => max( 1, (int) $row->total_attendees ),
					'code'     => $row->booking_code ? (string) $row->booking_code : '',
					'notes'    => '',
				),
				'sections' => array(
					array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => $who ),
					array( 'title' => __( 'Appointment', 'minn-admin' ), 'rows' => $when ),
				),
				'adminUrl' => minn_admin_latepoint_admin_url(),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/latepoint/bookings/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_latepoint_can_write',
		'callback'            => function ( WP_REST_Request $request ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			$ok     = array( 'approved', 'pending', 'cancelled', 'no_show', 'completed', 'payment_pending' );
			if ( ! in_array( $status, $ok, true ) ) {
				return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$id    = (int) Minn_Admin::path_param( $request );
			$scope = minn_admin_latepoint_scope_sql( minn_admin_latepoint_scope(), '' );
			if ( $scope['deny'] ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			if ( $scope['sql'] ) {
				global $wpdb;
				$bookings = minn_admin_latepoint_table( 'bookings' );
				$clauses  = ' AND ' . implode( ' AND ', $scope['sql'] );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owned = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$bookings} WHERE id = %d{$clauses}",
					array_merge( array( $id ), $scope['params'] )
				) );
				if ( ! $owned ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
			}
			try {
				$booking = new OsBookingModel( $id );
				if ( $booking->is_new_record() ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! $booking->update_status( $status ) ) {
					$msg = method_exists( $booking, 'get_error_messages' )
						? implode( ' ', (array) $booking->get_error_messages() )
						: '';
					return new WP_Error(
						'update_failed',
						$msg ? $msg : __( 'LatePoint could not update that appointment.', 'minn-admin' ),
						array( 'status' => 400 )
					);
				}
			} catch ( \Throwable $e ) {
				return new WP_Error( 'update_failed', __( 'LatePoint could not update that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'status'  => $status,
				'message' => sprintf(
					/* translators: %s: appointment status */
					__( 'Appointment marked %s.', 'minn-admin' ),
					str_replace( '_', ' ', $status )
				),
			) );
		},
	) );

	// Event registrations (5.7+). Registration created_at is UTC (their model
	// save forces it); event start/end are *_utc columns.
	register_rest_route( 'minn-admin/v1', '/latepoint/event-registrations', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_latepoint_can_view_regs',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$r     = minn_admin_latepoint_table( 'event_registrations' );
			$e     = minn_admin_latepoint_table( 'events' );
			$c     = minn_admin_latepoint_table( 'customers' );
			$scope = minn_admin_latepoint_reg_scope_sql();
			if ( $scope['deny'] ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$where  = $scope['sql'];
			$params = $scope['params'];
			$range  = sanitize_key( (string) ( $request->get_param( 'range' ) ?: 'upcoming' ) );
			if ( 'upcoming' === $range ) {
				$where[] = "e.end_datetime_utc >= UTC_TIMESTAMP() AND r.status <> 'cancelled'";
			} elseif ( 'cancelled' === $range ) {
				$where[] = "r.status = 'cancelled'";
			}
			$q = trim( (string) $request->get_param( 'search' ) );
			if ( '' !== $q ) {
				$like    = '%' . $wpdb->esc_like( $q ) . '%';
				$where[] = '(c.first_name LIKE %s OR c.last_name LIKE %s OR c.email LIKE %s OR e.name LIKE %s OR r.registration_code LIKE %s)';
				$params  = array_merge( $params, array( $like, $like, $like, $like, $like ) );
			}
			$sql_where = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
			$per_page  = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );
			$page      = max( 1, (int) $request->get_param( 'page' ) );
			$order     = 'upcoming' === $range ? 'ASC' : 'DESC';
			$from      = "FROM {$r} r LEFT JOIN {$e} e ON e.id = r.event_id LEFT JOIN {$c} c ON c.id = r.customer_id {$sql_where}";
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders
			$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) {$from}" ) );
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT r.id, r.quantity, r.status, e.name AS event_name, e.start_datetime_utc, c.first_name, c.last_name, c.email {$from} ORDER BY e.start_datetime_utc {$order}, r.id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array();
			foreach ( (array) $rows as $row ) {
				$items[] = array(
					'id'       => (int) $row->id,
					'customer' => minn_admin_latepoint_display_name( $row->first_name, $row->last_name ) . ( $row->email ? ' · ' . $row->email : '' ),
					'event'    => (string) $row->event_name,
					'quantity' => (int) $row->quantity,
					'status'   => (string) $row->status,
					'starts'   => minn_admin_latepoint_utc_iso( $row->start_datetime_utc ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/latepoint/event-registrations/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_latepoint_can_view_regs',
		'callback'            => function ( WP_REST_Request $request ) {
			$row = minn_admin_latepoint_reg_row( (int) $request['id'] );
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Registration not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$registered = minn_admin_latepoint_utc_iso( $row->created_at );
			return rest_ensure_response( array(
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
							array( 'label' => __( 'Name', 'minn-admin' ), 'value' => minn_admin_latepoint_display_name( $row->first_name, $row->last_name ) ),
							$row->email ? array( 'label' => __( 'Email', 'minn-admin' ), 'value' => (string) $row->email, 'type' => 'email' ) : null,
							$row->phone ? array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => (string) $row->phone ) : null,
						) ) ),
					),
					array(
						'title' => __( 'Event', 'minn-admin' ),
						'rows'  => array_values( array_filter( array(
							array( 'label' => __( 'Event', 'minn-admin' ), 'value' => (string) $row->event_name ),
							minn_admin_latepoint_utc_iso( $row->start_datetime_utc ) ? array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => minn_admin_latepoint_utc_iso( $row->start_datetime_utc ) ) : null,
							minn_admin_latepoint_utc_iso( $row->end_datetime_utc ) ? array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => minn_admin_latepoint_utc_iso( $row->end_datetime_utc ) ) : null,
						) ) ),
					),
				),
				'adminUrl' => minn_admin_latepoint_regs_admin_url(),
			) );
		},
	) );

	// Cancel mirrors their OsEventRegistrationsController::cancel(): events
	// setting on (the permission check), already-cancelled is a no-op so the
	// cancellation notice is not queued twice, then status + save() + the same
	// latepoint_event_registration_cancelled action with the old status (their
	// notification and e-ticket listeners ride it). The order is untouched.
	register_rest_route( 'minn-admin/v1', '/latepoint/event-registrations/(?P<id>\d+)/cancel', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_latepoint_can_edit_regs',
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) $request['id'];
			if ( ! minn_admin_latepoint_reg_row( $id ) ) {
				return new WP_Error( 'not_found', __( 'Registration not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$reg = new OsEventRegistrationModel( $id );
			if ( $reg->is_cancelled() ) {
				return rest_ensure_response( array( 'status' => 'cancelled', 'message' => __( 'Registration was already cancelled.', 'minn-admin' ) ) );
			}
			$old         = $reg->status;
			$reg->status = defined( 'LATEPOINT_EVENT_REGISTRATION_STATUS_CANCELLED' ) ? LATEPOINT_EVENT_REGISTRATION_STATUS_CANCELLED : 'cancelled';
			if ( ! $reg->save() ) {
				return new WP_Error( 'minn_lp_cancel', __( 'LatePoint could not cancel that registration.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			do_action( 'latepoint_event_registration_cancelled', $reg, $old );
			return rest_ensure_response( array( 'status' => 'cancelled', 'message' => __( 'Registration cancelled.', 'minn-admin' ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/latepoint/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$admin_url = minn_admin_latepoint_admin_url();
			if ( ! minn_admin_latepoint_has_tables() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No bookings yet', 'minn-admin' ) ) ),
					'actions' => array( array( 'label' => __( 'Open LatePoint ↗', 'minn-admin' ), 'href' => $admin_url ) ),
				) );
			}
			global $wpdb;
			$bookings = minn_admin_latepoint_table( 'bookings' );
			$scope    = minn_admin_latepoint_scope_sql( minn_admin_latepoint_scope(), '' );
			$scoped   = '';
			$params   = array();
			if ( $scope['deny'] ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—' ) ),
					'actions' => array( array( 'label' => __( 'Open LatePoint ↗', 'minn-admin' ), 'href' => $admin_url ) ),
				) );
			}
			if ( $scope['sql'] ) {
				$scoped = ' AND ' . implode( ' AND ', $scope['sql'] );
				$params = $scope['params'];
			}
			$now        = gmdate( 'Y-m-d H:i:s' );
			$today      = wp_date( 'Y-m-d' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = (int) ( $params
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings} WHERE status IN ('pending','payment_pending'){$scoped}", $params ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$bookings} WHERE status IN ('pending','payment_pending')" ) );
			$today_n = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings} WHERE start_date = %s{$scoped}",
				array_merge( array( $today ), $params )
			) );
			$next    = $wpdb->get_var( $wpdb->prepare(
				"SELECT start_datetime_utc FROM {$bookings} WHERE status IN ('approved','pending','payment_pending') AND start_datetime_utc >= %s{$scoped} ORDER BY start_datetime_utc ASC LIMIT 1",
				array_merge( array( $now ), $params )
			) );
			// phpcs:enable
			$next_iso = minn_admin_latepoint_utc_iso( $next );
			$next_ts  = $next_iso ? strtotime( $next_iso ) : false;
			$next_lbl = $next_ts
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts )
				: '—';
			// Next 14 site-local days, starting today: the shape of the week ahead.
			$chart_days = array();
			for ( $i = 0; $i < 14; $i++ ) {
				$d                = wp_date( 'Y-m-d', time() + $i * DAY_IN_SECONDS );
				$chart_days[ $d ] = array( 'label' => $d, 'value' => 0, 'secondary' => 0 );
			}
			// start_date is LatePoint's site-local day column, so it buckets directly.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$chart_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT start_date, status, COUNT(*) AS c FROM {$bookings} WHERE status IN ('approved','pending','payment_pending') AND start_date >= %s AND start_date <= %s{$scoped} GROUP BY start_date, status",
				array_merge( array( $today, wp_date( 'Y-m-d', time() + 13 * DAY_IN_SECONDS ) ), $params )
			) );
			// phpcs:enable
			foreach ( (array) $chart_rows as $cr ) {
				$d = (string) $cr->start_date;
				if ( ! isset( $chart_days[ $d ] ) ) {
					continue;
				}
				$chart_days[ $d ]['value'] += (int) $cr->c;
				if ( 'approved' !== $cr->status ) {
					$chart_days[ $d ]['secondary'] += (int) $cr->c;
				}
			}
			return rest_ensure_response( array(
				'rows'    => array(
					array( 'label' => __( 'Today', 'minn-admin' ), 'value' => number_format_i18n( $today_n ) ),
					array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( $pending ) ),
					array(
						'label' => __( 'Next', 'minn-admin' ),
						'value' => $next_lbl,
						'hint'  => $next_ts ? '' : __( 'Nothing upcoming', 'minn-admin' ),
					),
				),
				'chart'   => array(
					'title'     => __( 'Next 14 days', 'minn-admin' ),
					'primary'   => __( 'Booked', 'minn-admin' ),
					'secondary' => __( 'Pending', 'minn-admin' ),
					'points'    => array_values( $chart_days ),
				),
				'actions' => array( array( 'label' => __( 'Open LatePoint ↗', 'minn-admin' ), 'href' => $admin_url ) ),
			) );
		},
	) );
} );
