<?php
/**
 * Bundled adapter: Amelia (Wave F, Bookings family).
 *
 * Amelia stores appointments, customers and services in its own
 * {prefix}amelia_* tables. There is no public CPT, so nothing appears in
 * Content and the plugin's Vue admin is otherwise unreachable from Minn.
 * This surface is the inbox: upcoming / pending / today / canceled
 * appointments, a contact-style detail, approve / cancel / no-show through
 * Amelia's own UpdateAppointmentStatus controller (notifications and
 * booking rows stay theirs), and a status card with a deep link into
 * Amelia. Calendar authoring, employee hours and the booking form stay in
 * Amelia.
 *
 * bookingStart / bookingEnd are stored in UTC (their DateTimeService
 * writes getCustomDateTimeInUtc). Statuses are approved, pending,
 * canceled, rejected, no-show. Caps are Amelia's own
 * amelia_read_appointments / amelia_read_others_appointments /
 * amelia_write_status_appointments. customFields is JSON, decoded with
 * json_decode only.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_amelia_active() {
	return defined( 'AMELIA_VERSION' ) && defined( 'AMELIA_PATH' );
}

function minn_admin_amelia_can_read() {
	return current_user_can( 'amelia_read_appointments' );
}

function minn_admin_amelia_can_write_status() {
	return current_user_can( 'amelia_write_status_appointments' );
}

function minn_admin_amelia_table( $suffix ) {
	global $wpdb;
	return $wpdb->prefix . 'amelia_' . $suffix;
}

function minn_admin_amelia_has_tables() {
	global $wpdb;
	$t = minn_admin_amelia_table( 'appointments' );
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
}

/**
 * Restrict list queries to this provider id, or 0 for everyone.
 * Amelia employees without "read others" only see their own bookings.
 */
function minn_admin_amelia_provider_scope() {
	if ( current_user_can( 'amelia_read_others_appointments' ) ) {
		return 0;
	}
	global $wpdb;
	$users = minn_admin_amelia_table( 'users' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$users} WHERE externalId = %d AND type = 'provider' LIMIT 1",
		get_current_user_id()
	) );
	return $id > 0 ? $id : -1;
}

/** UTC MySQL datetime → ISO-8601 with a trailing Z. */
function minn_admin_amelia_utc_iso( $mysql_datetime ) {
	$dt = trim( (string) $mysql_datetime );
	if ( '' === $dt || 0 === strpos( $dt, '0000-00-00' ) ) {
		return '';
	}
	$ts = strtotime( $dt . ' UTC' );
	return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
}

function minn_admin_amelia_display_name( $first, $last ) {
	$name = trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );
	return '' !== $name ? $name : __( '(no name)', 'minn-admin' );
}

/**
 * Route a request through Amelia's Slim controller so status writes fire
 * their AppointmentStatusUpdated event (notifications, booking-row
 * updates). $validApiCall = true skips their nonce, the same path their
 * own Abilities registrar uses.
 *
 * @return array|\WP_Error Decoded `data` payload, or WP_Error.
 */
function minn_admin_amelia_invoke( $controller_class, $params, $args = array(), $method = 'POST' ) {
	if ( ! class_exists( $controller_class )
		|| ! class_exists( '\Slim\Psr7\Factory\ServerRequestFactory' )
		|| ! class_exists( '\Slim\Psr7\Factory\ResponseFactory' )
		|| ! defined( 'AMELIA_PATH' ) ) {
		return new WP_Error( 'amelia_missing', __( 'Amelia is not ready to handle this request.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	try {
		$container = require AMELIA_PATH . '/src/Infrastructure/ContainerConfig/container.php';
		$uri       = 'http://127.0.0.1/';
		if ( 'GET' === $method && $params ) {
			$uri .= '?' . http_build_query( $params );
		}
		$request = ( new \Slim\Psr7\Factory\ServerRequestFactory() )
			->createServerRequest( $method, $uri )
			->withHeader( 'Content-Type', 'application/json' );
		if ( 'GET' !== $method ) {
			$request = $request->withParsedBody( $params );
		}
		$response = ( new \Slim\Psr7\Factory\ResponseFactory() )->createResponse();
		$controller = new $controller_class( $container );
		$result     = $controller( $request, $response, $args, true );
		$code       = $result->getStatusCode();
		$decoded    = json_decode( (string) $result->getBody(), true );
		if ( $code >= 400 ) {
			$message = is_array( $decoded ) && ! empty( $decoded['message'] )
				? (string) $decoded['message']
				: __( 'Amelia could not update that appointment.', 'minn-admin' );
			return new WP_Error( 'amelia_command_error', $message, array( 'status' => $code >= 500 ? 500 : $code ) );
		}
		return is_array( $decoded ) && isset( $decoded['data'] ) ? $decoded['data'] : $decoded;
	} catch ( \Throwable $e ) {
		return new WP_Error( 'amelia_command_error', __( 'Amelia could not update that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
	}
}

function minn_admin_amelia_admin_url() {
	return admin_url( 'admin.php?page=wpamelia-bookings' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_amelia_active() || ! minn_admin_amelia_can_read() ) {
		return $surfaces;
	}
	$can_write = minn_admin_amelia_can_write_status();
	$actions   = array(
		array(
			'label' => __( 'Open in Amelia ↗', 'minn-admin' ),
			'href'  => minn_admin_amelia_admin_url(),
		),
	);
	if ( $can_write ) {
		$actions = array_merge( array(
			array(
				'label'  => __( 'Approve', 'minn-admin' ),
				'method' => 'POST',
				'route'  => 'minn-admin/v1/amelia/appointments/{id}/status',
				'body'   => array( 'status' => 'approved' ),
				'when'   => array( 'key' => 'status', 'equals' => 'pending' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/amelia/appointments/{id}/status',
				'body'    => array( 'status' => 'canceled' ),
				'confirm' => __( 'Cancel this appointment? Amelia will notify the customer.', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'status', 'equals' => 'approved' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/amelia/appointments/{id}/status',
				'body'    => array( 'status' => 'canceled' ),
				'confirm' => __( 'Cancel this appointment? Amelia will notify the customer.', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'status', 'equals' => 'pending' ),
			),
			array(
				'label'  => __( 'Mark no-show', 'minn-admin' ),
				'method' => 'POST',
				'route'  => 'minn-admin/v1/amelia/appointments/{id}/status',
				'body'   => array( 'status' => 'no-show' ),
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
				'route'  => 'minn-admin/v1/amelia/appointments/{id}/status',
				'body'   => array( 'status' => 'approved' ),
			),
			array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/amelia/appointments/{id}/status',
				'body'    => array( 'status' => 'canceled' ),
				'danger'  => true,
			),
		);
	}
	$surfaces['amelia'] = array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'workspace',
		'sub'        => 'Amelia',
		'icon'       => 'calendar',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/amelia/status' ),
		'collection' => array(
			'viewLabel' => __( 'Appointments', 'minn-admin' ),
			'route'     => 'minn-admin/v1/amelia/appointments',
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
				'sectionsRoute' => 'minn-admin/v1/amelia/appointments/{id}',
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_amelia_active() ) {
		return;
	}
	$perm = 'minn_admin_amelia_can_read';

	register_rest_route( 'minn-admin/v1', '/amelia/appointments', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_amelia_has_tables() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$appts    = minn_admin_amelia_table( 'appointments' );
			$bookings = minn_admin_amelia_table( 'customer_bookings' );
			$users    = minn_admin_amelia_table( 'users' );
			$services = minn_admin_amelia_table( 'services' );
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$view     = sanitize_key( (string) $request->get_param( 'range' ) );
			if ( '' === $view ) {
				$view = 'upcoming';
			}
			$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
			$scope  = minn_admin_amelia_provider_scope();
			$now    = gmdate( 'Y-m-d H:i:s' );

			$where  = array( '1=1' );
			$params = array();
			if ( $scope > 0 ) {
				$where[]  = 'a.providerId = %d';
				$params[] = $scope;
			} elseif ( $scope < 0 ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}

			if ( 'pending' === $view ) {
				$where[] = "a.status = 'pending'";
			} elseif ( 'canceled' === $view ) {
				$where[] = "a.status IN ('canceled','rejected','no-show')";
			} elseif ( 'today' === $view ) {
				$tz    = wp_timezone();
				$start = new DateTimeImmutable( 'today', $tz );
				$end   = $start->modify( '+1 day' );
				$where[]  = 'a.bookingStart >= %s AND a.bookingStart < %s';
				$params[] = $start->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
				$params[] = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			} elseif ( 'all' === $view ) {
				// no extra status/date gate
			} else {
				$where[]  = "a.status IN ('approved','pending') AND a.bookingStart >= %s";
				$params[] = $now;
			}

			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(c.firstName LIKE %s OR c.lastName LIKE %s OR c.email LIKE %s OR s.name LIKE %s OR p.firstName LIKE %s OR p.lastName LIKE %s OR a.internalNotes LIKE %s)';
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
				? 'a.bookingStart ASC'
				: 'a.bookingStart DESC';
			$from = "{$appts} a
				LEFT JOIN {$bookings} b ON b.id = (SELECT MIN(b2.id) FROM {$bookings} b2 WHERE b2.appointmentId = a.id)
				LEFT JOIN {$users} c ON c.id = b.customerId
				LEFT JOIN {$users} p ON p.id = a.providerId
				LEFT JOIN {$services} s ON s.id = a.serviceId";
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count_sql = "SELECT COUNT(*) FROM {$from} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT a.id, a.status, a.bookingStart, a.bookingEnd, a.internalNotes,
					s.name AS service_name,
					c.firstName AS customer_first, c.lastName AS customer_last, c.email AS customer_email,
					p.firstName AS provider_first, p.lastName AS provider_last
				FROM {$from} {$where_sql}
				ORDER BY {$order}
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array_map( function ( $r ) {
				$customer = minn_admin_amelia_display_name( $r->customer_first, $r->customer_last );
				$email    = trim( (string) $r->customer_email );
				return array(
					'id'       => (int) $r->id,
					'customer' => $email ? $customer . ' · ' . $email : $customer,
					'service'  => $r->service_name ? (string) $r->service_name : __( '(no service)', 'minn-admin' ),
					'status'   => (string) $r->status,
					'date'     => minn_admin_amelia_utc_iso( $r->bookingStart ),
					'provider' => minn_admin_amelia_display_name( $r->provider_first, $r->provider_last ),
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/amelia/appointments/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_amelia_has_tables() ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			global $wpdb;
			$appts    = minn_admin_amelia_table( 'appointments' );
			$bookings = minn_admin_amelia_table( 'customer_bookings' );
			$users    = minn_admin_amelia_table( 'users' );
			$services = minn_admin_amelia_table( 'services' );
			$id       = (int) $request['id'];
			$scope    = minn_admin_amelia_provider_scope();
			$scope_sql = '';
			$params    = array( $id );
			if ( $scope > 0 ) {
				$scope_sql = ' AND a.providerId = %d';
				$params[]  = $scope;
			} elseif ( $scope < 0 ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT a.id, a.status, a.bookingStart, a.bookingEnd, a.internalNotes, a.serviceId, a.providerId,
					s.name AS service_name,
					c.id AS customer_id, c.firstName AS customer_first, c.lastName AS customer_last,
					c.email AS customer_email, c.phone AS customer_phone,
					p.firstName AS provider_first, p.lastName AS provider_last,
					b.persons, b.price, b.customFields, b.info
				FROM {$appts} a
				LEFT JOIN {$bookings} b ON b.id = (SELECT MIN(b2.id) FROM {$bookings} b2 WHERE b2.appointmentId = a.id)
				LEFT JOIN {$users} c ON c.id = b.customerId
				LEFT JOIN {$users} p ON p.id = a.providerId
				LEFT JOIN {$services} s ON s.id = a.serviceId
				WHERE a.id = %d{$scope_sql}
				LIMIT 1",
				$params
			) );
			// phpcs:enable
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$who = array(
				array( 'label' => __( 'Name', 'minn-admin' ), 'value' => minn_admin_amelia_display_name( $row->customer_first, $row->customer_last ) ),
				array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $row->customer_email ? (string) $row->customer_email : '—' ),
				array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => $row->customer_phone ? (string) $row->customer_phone : '—' ),
				array( 'label' => __( 'People', 'minn-admin' ), 'value' => (string) max( 1, (int) $row->persons ) ),
			);
			$when = array(
				array( 'label' => __( 'Service', 'minn-admin' ), 'value' => $row->service_name ? (string) $row->service_name : '—' ),
				array( 'label' => __( 'Employee', 'minn-admin' ), 'value' => minn_admin_amelia_display_name( $row->provider_first, $row->provider_last ) ),
				array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => minn_admin_amelia_utc_iso( $row->bookingStart ) ),
				array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => minn_admin_amelia_utc_iso( $row->bookingEnd ) ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status ),
			);
			if ( null !== $row->price && '' !== (string) $row->price ) {
				$when[] = array( 'label' => __( 'Price', 'minn-admin' ), 'value' => (string) $row->price );
			}
			$notes = trim( (string) $row->internalNotes );
			if ( '' !== $notes ) {
				$when[] = array( 'label' => __( 'Notes', 'minn-admin' ), 'value' => $notes );
			}
			$info = trim( (string) $row->info );
			if ( '' !== $info ) {
				$when[] = array( 'label' => __( 'Booking note', 'minn-admin' ), 'value' => $info );
			}
			$fields = json_decode( (string) $row->customFields, true );
			$extra  = array();
			if ( is_array( $fields ) ) {
				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) ) {
						continue;
					}
					$label = isset( $field['label'] ) ? (string) $field['label'] : '';
					$value = isset( $field['value'] ) ? $field['value'] : '';
					if ( is_array( $value ) ) {
						$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
					}
					if ( '' === $label ) {
						continue;
					}
					$extra[] = array( 'label' => $label, 'value' => '' !== (string) $value ? (string) $value : '—' );
				}
			}
			$sections = array(
				array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => $who ),
				array( 'title' => __( 'Appointment', 'minn-admin' ), 'rows' => $when ),
			);
			if ( $extra ) {
				$sections[] = array( 'title' => __( 'Answers', 'minn-admin' ), 'rows' => $extra );
			}
			return rest_ensure_response( array(
				'kind'     => 'entry',
				'sections' => $sections,
				'adminUrl' => minn_admin_amelia_admin_url(),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/amelia/appointments/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_amelia_can_write_status',
		'callback'            => function ( WP_REST_Request $request ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( $status, array( 'approved', 'pending', 'canceled', 'rejected', 'no-show' ), true ) ) {
				return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$id    = (int) $request['id'];
			$scope = minn_admin_amelia_provider_scope();
			if ( $scope < 0 ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			if ( $scope > 0 ) {
				global $wpdb;
				$appts = minn_admin_amelia_table( 'appointments' );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owned = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$appts} WHERE id = %d AND providerId = %d",
					$id,
					$scope
				) );
				if ( ! $owned ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
			}
			$out = minn_admin_amelia_invoke(
				'\AmeliaBooking\Application\Controller\Booking\Appointment\UpdateAppointmentStatusController',
				array( 'status' => $status ),
				array( 'id' => $id ),
				'POST'
			);
			if ( is_wp_error( $out ) ) {
				return $out;
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'status'  => $status,
				'message' => sprintf(
					/* translators: %s: appointment status */
					__( 'Appointment marked %s.', 'minn-admin' ),
					$status
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/amelia/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$admin_url = minn_admin_amelia_admin_url();
			if ( ! minn_admin_amelia_has_tables() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No bookings yet', 'minn-admin' ) ) ),
					'actions' => array( array( 'label' => __( 'Open Amelia ↗', 'minn-admin' ), 'href' => $admin_url ) ),
				) );
			}
			global $wpdb;
			$appts  = minn_admin_amelia_table( 'appointments' );
			$scope  = minn_admin_amelia_provider_scope();
			$scoped = '';
			$params = array();
			if ( $scope > 0 ) {
				$scoped   = ' AND providerId = %d';
				$params[] = $scope;
			} elseif ( $scope < 0 ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—' ) ),
					'actions' => array( array( 'label' => __( 'Open Amelia ↗', 'minn-admin' ), 'href' => $admin_url ) ),
				) );
			}
			$now = gmdate( 'Y-m-d H:i:s' );
			$tz  = wp_timezone();
			$day = ( new DateTimeImmutable( 'today', $tz ) );
			$day_end = $day->modify( '+1 day' );
			$today_from = $day->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			$today_to   = $day_end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = (int) ( $params
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$appts} WHERE status = 'pending'{$scoped}", $params ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$appts} WHERE status = 'pending'" ) );
			$today   = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$appts} WHERE bookingStart >= %s AND bookingStart < %s{$scoped}",
				array_merge( array( $today_from, $today_to ), $params )
			) );
			$next    = $wpdb->get_var( $wpdb->prepare(
				"SELECT bookingStart FROM {$appts} WHERE status IN ('approved','pending') AND bookingStart >= %s{$scoped} ORDER BY bookingStart ASC LIMIT 1",
				array_merge( array( $now ), $params )
			) );
			// phpcs:enable
			$next_iso = minn_admin_amelia_utc_iso( $next );
			$next_ts  = $next_iso ? strtotime( $next_iso ) : false;
			$next_lbl = $next_ts
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts )
				: '—';
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => __( 'Today', 'minn-admin' ),
						'value' => number_format_i18n( $today ),
					),
					array(
						'label' => __( 'Pending', 'minn-admin' ),
						'value' => number_format_i18n( $pending ),
					),
					array(
						'label' => __( 'Next', 'minn-admin' ),
						'value' => $next_lbl,
						'hint'  => $next_ts ? '' : __( 'Nothing upcoming', 'minn-admin' ),
					),
				),
				'actions' => array( array( 'label' => __( 'Open Amelia ↗', 'minn-admin' ), 'href' => $admin_url ) ),
			) );
		},
	) );
} );
