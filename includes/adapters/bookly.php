<?php
/**
 * Bundled adapter: Bookly (Bookings family).
 *
 * Bookly stores the slot on {prefix}bookly_appointments (WP-local start_date)
 * and the customer's status on {prefix}bookly_customer_appointments. There is
 * no public CPT. This surface is the same inbox as Amelia and LatePoint:
 * upcoming / pending / today / canceled, a contact card, approve / cancel
 * through CustomerAppointment::setStatus + save (status_changed_at stays
 * Bookly's) then Sender::sendForCA so notifications stay Bookly's. Calendar,
 * staff hours and the booking form stay in Bookly.
 *
 * Statuses are theirs: pending, approved, cancelled, rejected, done
 * (plus waitlisted when that add-on is on). Caps go through Bookly's
 * supervisor check (manage_options / manage_bookly / manage_bookly_appointments).
 * Staff who are not supervisors only see their own bookings.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_bookly_active() {
	return class_exists( '\Bookly\Lib\Entities\CustomerAppointment' )
		&& class_exists( '\Bookly\Lib\Entities\Appointment' );
}

function minn_admin_bookly_can_read() {
	if ( class_exists( '\Bookly\Lib\Utils\Common' ) ) {
		if ( \Bookly\Lib\Utils\Common::isCurrentUserSupervisor() ) {
			return true;
		}
		if ( method_exists( '\Bookly\Lib\Utils\Common', 'isCurrentUserStaff' ) ) {
			return (bool) \Bookly\Lib\Utils\Common::isCurrentUserStaff();
		}
	}
	return current_user_can( 'manage_options' );
}

function minn_admin_bookly_can_write() {
	if ( class_exists( '\Bookly\Lib\Utils\Common' ) ) {
		if ( \Bookly\Lib\Utils\Common::isCurrentUserSupervisor() ) {
			return true;
		}
		if ( method_exists( '\Bookly\Lib\Utils\Common', 'isCurrentUserStaff' ) ) {
			return (bool) \Bookly\Lib\Utils\Common::isCurrentUserStaff();
		}
	}
	return current_user_can( 'manage_options' );
}

function minn_admin_bookly_table( $suffix ) {
	global $wpdb;
	return $wpdb->prefix . 'bookly_' . $suffix;
}

function minn_admin_bookly_has_tables() {
	global $wpdb;
	$t = minn_admin_bookly_table( 'customer_appointments' );
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
}

/**
 * Restrict list queries to these staff ids, or empty for everyone.
 *
 * @return int[] Empty = all; [-1] = none.
 */
function minn_admin_bookly_staff_scope() {
	if ( class_exists( '\Bookly\Lib\Utils\Common' ) && \Bookly\Lib\Utils\Common::isCurrentUserSupervisor() ) {
		return array();
	}
	global $wpdb;
	$staff = minn_admin_bookly_table( 'staff' );
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staff ) ) !== $staff ) {
		return array( -1 );
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$staff} WHERE wp_user_id = %d LIMIT 1",
		get_current_user_id()
	) );
	return $id > 0 ? array( $id ) : array( -1 );
}

/** WP-local MySQL datetime → ISO-8601 with a trailing Z. */
function minn_admin_bookly_local_iso( $mysql_datetime ) {
	$dt = trim( (string) $mysql_datetime );
	if ( '' === $dt || 0 === strpos( $dt, '0000-00-00' ) ) {
		return '';
	}
	try {
		$obj = new DateTime( $dt, wp_timezone() );
		$obj->setTimezone( new DateTimeZone( 'UTC' ) );
		return $obj->format( 'Y-m-d\TH:i:s\Z' );
	} catch ( \Exception $e ) {
		return '';
	}
}

function minn_admin_bookly_display_name( $first, $last, $full = '' ) {
	$name = trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );
	if ( '' === $name ) {
		$name = trim( (string) $full );
	}
	return '' !== $name ? $name : __( '(no name)', 'minn-admin' );
}

function minn_admin_bookly_admin_url() {
	if ( class_exists( '\Bookly\Backend\Modules\Appointments\Page' )
		&& method_exists( '\Bookly\Backend\Modules\Appointments\Page', 'pageSlug' ) ) {
		return admin_url( 'admin.php?page=' . \Bookly\Backend\Modules\Appointments\Page::pageSlug() );
	}
	return admin_url( 'admin.php?page=bookly-appointments' );
}

function minn_admin_bookly_statuses() {
	if ( class_exists( '\Bookly\Lib\Entities\CustomerAppointment' )
		&& method_exists( '\Bookly\Lib\Entities\CustomerAppointment', 'getStatuses' ) ) {
		return (array) \Bookly\Lib\Entities\CustomerAppointment::getStatuses();
	}
	return array( 'pending', 'approved', 'cancelled', 'rejected', 'done' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_bookly_active() || ! minn_admin_bookly_can_read() ) {
		return $surfaces;
	}
	$can_write = minn_admin_bookly_can_write();
	$actions   = array(
		array(
			'label' => __( 'Open in Bookly ↗', 'minn-admin' ),
			'href'  => minn_admin_bookly_admin_url(),
		),
	);
	if ( $can_write ) {
		$actions = array_merge( array(
			array(
				'label'  => __( 'Approve', 'minn-admin' ),
				'method' => 'POST',
				'route'  => 'minn-admin/v1/bookly/appointments/{id}/status',
				'body'   => array( 'status' => 'approved' ),
				'when'   => array( 'key' => 'status', 'equals' => 'pending' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/bookly/appointments/{id}/status',
				'body'   => array( 'status' => 'cancelled' ),
				'confirm' => __( 'Cancel this appointment? Bookly will notify the customer.', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'status', 'equals' => 'approved' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/bookly/appointments/{id}/status',
				'body'   => array( 'status' => 'cancelled' ),
				'confirm' => __( 'Cancel this appointment? Bookly will notify the customer.', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'status', 'equals' => 'pending' ),
			),
		), $actions );
	}
	$bulk = array();
	if ( $can_write ) {
		$bulk = array(
			array(
				'label'  => __( 'Approve', 'minn-admin' ),
				'method' => 'POST',
				'route'  => 'minn-admin/v1/bookly/appointments/{id}/status',
				'body'   => array( 'status' => 'approved' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/bookly/appointments/{id}/status',
				'body'   => array( 'status' => 'cancelled' ),
				'danger'  => true,
			),
		);
	}
	$surfaces['bookly'] = array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'workspace',
		'sub'        => 'Bookly',
		'icon'       => 'calendar',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/bookly/status' ),
		'collection' => array(
			'viewLabel' => __( 'Appointments', 'minn-admin' ),
			'route'     => 'minn-admin/v1/bookly/appointments',
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
				'sectionsRoute' => 'minn-admin/v1/bookly/appointments/{id}',
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_bookly_active() ) {
		return;
	}
	$perm = 'minn_admin_bookly_can_read';

	register_rest_route( 'minn-admin/v1', '/bookly/appointments', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_bookly_has_tables() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$cas       = minn_admin_bookly_table( 'customer_appointments' );
			$appts     = minn_admin_bookly_table( 'appointments' );
			$customers = minn_admin_bookly_table( 'customers' );
			$services  = minn_admin_bookly_table( 'services' );
			$staff     = minn_admin_bookly_table( 'staff' );
			$per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$view      = sanitize_key( (string) $request->get_param( 'range' ) );
			if ( '' === $view ) {
				$view = 'upcoming';
			}
			$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
			$scope  = minn_admin_bookly_staff_scope();
			$now    = current_time( 'mysql' );

			$where  = array( '1=1' );
			$params = array();
			if ( $scope ) {
				if ( array( -1 ) === $scope ) {
					return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
				}
				$in      = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
				$where[] = "a.staff_id IN ({$in})";
				$params  = array_merge( $params, $scope );
			}

			if ( 'pending' === $view ) {
				$where[] = "ca.status IN ('pending','waitlisted')";
			} elseif ( 'canceled' === $view ) {
				$where[] = "ca.status IN ('cancelled','rejected')";
			} elseif ( 'today' === $view ) {
				$where[]  = 'DATE(a.start_date) = %s';
				$params[] = wp_date( 'Y-m-d' );
			} elseif ( 'all' === $view ) {
				// no extra gate
			} else {
				$where[]  = "ca.status IN ('approved','pending','waitlisted') AND a.start_date >= %s";
				$params[] = $now;
			}

			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(c.first_name LIKE %s OR c.last_name LIKE %s OR c.full_name LIKE %s OR c.email LIKE %s OR s.title LIKE %s OR st.full_name LIKE %s)';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}

			$where_sql = 'WHERE ' . implode( ' AND ', $where );
			$order     = ( 'upcoming' === $view || 'pending' === $view || 'today' === $view )
				? 'a.start_date ASC'
				: 'a.start_date DESC';
			$from = "{$cas} ca
				LEFT JOIN {$appts} a ON a.id = ca.appointment_id
				LEFT JOIN {$customers} c ON c.id = ca.customer_id
				LEFT JOIN {$services} s ON s.id = a.service_id
				LEFT JOIN {$staff} st ON st.id = a.staff_id";
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count_sql = "SELECT COUNT(*) FROM {$from} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT ca.id, ca.status, ca.number_of_persons, a.start_date, a.end_date,
					s.title AS service_name,
					c.first_name AS customer_first, c.last_name AS customer_last, c.full_name AS customer_full, c.email AS customer_email,
					st.full_name AS staff_name
				FROM {$from} {$where_sql}
				ORDER BY {$order}
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array_map( function ( $r ) {
				$customer = minn_admin_bookly_display_name( $r->customer_first, $r->customer_last, $r->customer_full );
				$email    = trim( (string) $r->customer_email );
				return array(
					'id'       => (int) $r->id,
					'customer' => $email ? $customer . ' · ' . $email : $customer,
					'service'  => $r->service_name ? (string) $r->service_name : __( '(no service)', 'minn-admin' ),
					'status'   => (string) $r->status,
					'date'     => minn_admin_bookly_local_iso( $r->start_date ),
					'provider' => $r->staff_name ? (string) $r->staff_name : __( '(no name)', 'minn-admin' ),
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/bookly/appointments/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_bookly_has_tables() ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			global $wpdb;
			$cas       = minn_admin_bookly_table( 'customer_appointments' );
			$appts     = minn_admin_bookly_table( 'appointments' );
			$customers = minn_admin_bookly_table( 'customers' );
			$services  = minn_admin_bookly_table( 'services' );
			$staff     = minn_admin_bookly_table( 'staff' );
			$id        = (int) $request['id'];
			$scope     = minn_admin_bookly_staff_scope();
			$scope_sql = '';
			$params    = array( $id );
			if ( $scope ) {
				if ( array( -1 ) === $scope ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$in        = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
				$scope_sql = " AND a.staff_id IN ({$in})";
				$params    = array_merge( $params, $scope );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT ca.id, ca.status, ca.number_of_persons, a.start_date, a.end_date,
					s.title AS service_name,
					c.first_name AS customer_first, c.last_name AS customer_last, c.full_name AS customer_full,
					c.email AS customer_email, c.phone AS customer_phone,
					st.full_name AS staff_name
				FROM {$cas} ca
				LEFT JOIN {$appts} a ON a.id = ca.appointment_id
				LEFT JOIN {$customers} c ON c.id = ca.customer_id
				LEFT JOIN {$services} s ON s.id = a.service_id
				LEFT JOIN {$staff} st ON st.id = a.staff_id
				WHERE ca.id = %d{$scope_sql}
				LIMIT 1",
				$params
			) );
			// phpcs:enable
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$who = array(
				array( 'label' => __( 'Name', 'minn-admin' ), 'value' => minn_admin_bookly_display_name( $row->customer_first, $row->customer_last, $row->customer_full ) ),
				array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $row->customer_email ? (string) $row->customer_email : '—' ),
				array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => $row->customer_phone ? (string) $row->customer_phone : '—' ),
				array( 'label' => __( 'People', 'minn-admin' ), 'value' => (string) max( 1, (int) $row->number_of_persons ) ),
			);
			$when = array(
				array( 'label' => __( 'Service', 'minn-admin' ), 'value' => $row->service_name ? (string) $row->service_name : '—' ),
				array( 'label' => __( 'Employee', 'minn-admin' ), 'value' => $row->staff_name ? (string) $row->staff_name : '—' ),
				array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => minn_admin_bookly_local_iso( $row->start_date ) ),
				array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => minn_admin_bookly_local_iso( $row->end_date ) ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status ),
			);
			$service = $row->service_name ? (string) $row->service_name : '';
			return rest_ensure_response( array(
				'kind'     => 'booking',
				'status'   => (string) $row->status,
				'title'    => $service ? $service : __( 'Appointment', 'minn-admin' ),
				'customer' => array(
					'name'  => minn_admin_bookly_display_name( $row->customer_first, $row->customer_last, $row->customer_full ),
					'email' => $row->customer_email ? (string) $row->customer_email : '',
					'phone' => $row->customer_phone ? (string) $row->customer_phone : '',
				),
				'booking'  => array(
					'service'  => $service,
					'employee' => $row->staff_name ? (string) $row->staff_name : '',
					'starts'   => minn_admin_bookly_local_iso( $row->start_date ),
					'ends'     => minn_admin_bookly_local_iso( $row->end_date ),
					'people'   => max( 1, (int) $row->number_of_persons ),
					'notes'    => '',
				),
				'sections' => array(
					array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => $who ),
					array( 'title' => __( 'Appointment', 'minn-admin' ), 'rows' => $when ),
				),
				'adminUrl' => minn_admin_bookly_admin_url(),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/bookly/appointments/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_bookly_can_write',
		'callback'            => function ( WP_REST_Request $request ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			$ok     = minn_admin_bookly_statuses();
			if ( ! in_array( $status, $ok, true ) ) {
				return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$id    = (int) $request['id'];
			$scope = minn_admin_bookly_staff_scope();
			if ( $scope ) {
				if ( array( -1 ) === $scope ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				global $wpdb;
				$cas   = minn_admin_bookly_table( 'customer_appointments' );
				$appts = minn_admin_bookly_table( 'appointments' );
				$in    = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owned = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT ca.id FROM {$cas} ca JOIN {$appts} a ON a.id = ca.appointment_id WHERE ca.id = %d AND a.staff_id IN ({$in})",
					array_merge( array( $id ), $scope )
				) );
				if ( ! $owned ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
			}
			try {
				$ca = new \Bookly\Lib\Entities\CustomerAppointment();
				if ( ! $ca->load( $id ) ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$ca->setStatus( $status );
				if ( false === $ca->save() ) {
					return new WP_Error(
						'update_failed',
						__( 'Bookly could not update that appointment.', 'minn-admin' ),
						array( 'status' => 400 )
					);
				}
				if ( class_exists( '\Bookly\Lib\Notifications\Booking\Sender' ) ) {
					try {
						\Bookly\Lib\Notifications\Booking\Sender::sendForCA( $ca );
					} catch ( \Throwable $e ) {
						// Status is already written; a notification failure must not undo it.
					}
				}
			} catch ( \Throwable $e ) {
				return new WP_Error( 'update_failed', __( 'Bookly could not update that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
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

	register_rest_route( 'minn-admin/v1', '/bookly/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$admin_url = minn_admin_bookly_admin_url();
			if ( ! minn_admin_bookly_has_tables() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No bookings yet', 'minn-admin' ) ) ),
					'actions' => array( array( 'label' => __( 'Open Bookly ↗', 'minn-admin' ), 'href' => $admin_url ) ),
				) );
			}
			global $wpdb;
			$cas   = minn_admin_bookly_table( 'customer_appointments' );
			$appts = minn_admin_bookly_table( 'appointments' );
			$scope = minn_admin_bookly_staff_scope();
			$join  = " FROM {$cas} ca JOIN {$appts} a ON a.id = ca.appointment_id";
			$where = ' WHERE 1=1';
			$params = array();
			if ( $scope ) {
				if ( array( -1 ) === $scope ) {
					return rest_ensure_response( array(
						'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—' ) ),
						'actions' => array( array( 'label' => __( 'Open Bookly ↗', 'minn-admin' ), 'href' => $admin_url ) ),
					) );
				}
				$in     = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
				$where .= " AND a.staff_id IN ({$in})";
				$params = $scope;
			}
			$now   = current_time( 'mysql' );
			$today = wp_date( 'Y-m-d' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending_sql = "SELECT COUNT(*){$join}{$where} AND ca.status IN ('pending','waitlisted')";
			$pending     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $pending_sql, $params ) ) : $wpdb->get_var( $pending_sql ) );
			$today_sql   = "SELECT COUNT(*){$join}{$where} AND DATE(a.start_date) = %s";
			$today_n     = (int) $wpdb->get_var( $wpdb->prepare( $today_sql, array_merge( $params, array( $today ) ) ) );
			$next_sql    = "SELECT a.start_date{$join}{$where} AND ca.status IN ('approved','pending','waitlisted') AND a.start_date >= %s ORDER BY a.start_date ASC LIMIT 1";
			$next        = $wpdb->get_var( $wpdb->prepare( $next_sql, array_merge( $params, array( $now ) ) ) );
			// phpcs:enable
			$next_iso = minn_admin_bookly_local_iso( $next );
			$next_ts  = $next_iso ? strtotime( $next_iso ) : false;
			$next_lbl = $next_ts
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts )
				: '—';
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
				'actions' => array( array( 'label' => __( 'Open Bookly ↗', 'minn-admin' ), 'href' => $admin_url ) ),
			) );
		},
	) );
} );
