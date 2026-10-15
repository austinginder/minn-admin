<?php
/**
 * Bundled adapter: Amelia (Wave F, Bookings family).
 *
 * Amelia stores appointments, customers and services in its own
 * {prefix}amelia_* tables. There is no public CPT, so nothing appears in
 * Content and the plugin's Vue admin is otherwise unreachable from Minn.
 * This surface is the inbox, a kernel resource over their tables:
 * upcoming / pending / today / canceled appointments, a contact-style
 * detail, approve / cancel / no-show through Amelia's own
 * UpdateAppointmentStatus controller (notifications and booking rows stay
 * theirs), and a status card with a deep link into Amelia. Calendar authoring, employee hours and the booking form stay in
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
	return Minn_Admin_Kernel_SQL::exists( 'amelia_appointments' );
}

/**
 * Restrict queries to this provider id, or 0 for everyone.
 *
 * Amelia employees without "read others" only see their own bookings, and it
 * grants read and write separately: wpamelia-provider has write_others set to
 * false while wpamelia-manager has both. Reads answer to read_others, writes to
 * write_others, so broad visibility never implies broad authority.
 *
 * @param bool $for_write Which of the two capabilities decides the scope.
 */
function minn_admin_amelia_provider_scope( $for_write = false ) {
	$cap = $for_write ? 'amelia_write_others_appointments' : 'amelia_read_others_appointments';
	if ( current_user_can( $cap ) ) {
		return 0;
	}
	$rows = Minn_Admin_Kernel_SQL::select( 'amelia_users', array( 'id' ), array( 'externalId' => get_current_user_id(), 'type' => 'provider' ), array(), 1 );
	$id   = $rows ? (int) $rows[0]['id'] : 0;
	return $id > 0 ? $id : -1;
}

/** The provider scope as conditions: none for everyone, null for nobody. */
function minn_admin_amelia_scope() {
	$scope = minn_admin_amelia_provider_scope();
	return $scope < 0 ? null : ( $scope > 0 ? array( 'providerId' => $scope ) : array() );
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

/**
 * The appointment's detail: the customer, the appointment, the booking
 * form's answers, as Amelia stores them. The answers are visitor text, sent
 * as typed for the client to escape; customFields and info are JSON, decoded
 * with json_decode only.
 */
function minn_admin_amelia_detail( array $row ) {
	$row   = (object) $row;
	$who   = array(
		array( 'label' => __( 'Name', 'minn-admin' ), 'value' => minn_admin_amelia_display_name( $row->customer_first, $row->customer_last ) ),
		array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $row->customer_email ? (string) $row->customer_email : '—' ),
		array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => $row->customer_phone ? (string) $row->customer_phone : '—' ),
		array( 'label' => __( 'People', 'minn-admin' ), 'value' => (string) max( 1, (int) $row->persons ) ),
	);
	$when  = array(
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
	// Amelia's own booking form writes info as JSON: the customer's details
	// as typed (firstName, lastName, phone, locale, timeZone, urlParams), not
	// a note. Only something else stored there is one.
	$info   = trim( (string) $row->info );
	$booked = '' !== $info ? json_decode( $info, true ) : null;
	if ( '' !== $info && ! is_array( $booked ) ) {
		$when[] = array( 'label' => __( 'Booking note', 'minn-admin' ), 'value' => $info );
	}
	// A file field's value is a list of { name, fileName }.
	$fields = json_decode( (string) $row->customFields, true );
	$extra  = array();
	foreach ( is_array( $fields ) ? $fields : array() as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		$label = isset( $field['label'] ) && is_scalar( $field['label'] ) ? Minn_Admin::plain_text( $field['label'] ) : '';
		$value = isset( $field['value'] ) ? $field['value'] : '';
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $part ) {
				$part = is_array( $part ) ? ( $part['name'] ?? '' ) : $part;
				if ( is_scalar( $part ) && '' !== (string) $part ) {
					$parts[] = (string) $part;
				}
			}
			$value = implode( ', ', $parts );
		}
		if ( '' === $label || ! is_scalar( $value ) ) {
			continue;
		}
		$extra[] = array( 'label' => $label, 'value' => '' !== (string) $value ? (string) $value : '—' );
	}
	// What the form's details add to the customer card: the name and phone
	// as typed when they differ from the record, and the customer's time zone.
	if ( is_array( $booked ) ) {
		$part   = function ( $k ) use ( $booked ) {
			return isset( $booked[ $k ] ) && is_scalar( $booked[ $k ] ) ? trim( (string) $booked[ $k ] ) : '';
		};
		$as     = trim( $part( 'firstName' ) . ' ' . $part( 'lastName' ) );
		$record = trim( trim( (string) $row->customer_first ) . ' ' . trim( (string) $row->customer_last ) );
		if ( '' !== $as && $as !== $record ) {
			$extra[] = array( 'label' => __( 'Booked as', 'minn-admin' ), 'value' => $as );
		}
		if ( '' !== $part( 'phone' ) && $part( 'phone' ) !== trim( (string) $row->customer_phone ) ) {
			$extra[] = array( 'label' => __( 'Phone at booking', 'minn-admin' ), 'value' => $part( 'phone' ) );
		}
		if ( '' !== $part( 'timeZone' ) ) {
			$extra[] = array( 'label' => __( 'Time zone', 'minn-admin' ), 'value' => $part( 'timeZone' ) );
		}
	}
	$sections = array(
		array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => $who ),
		array( 'title' => __( 'Appointment', 'minn-admin' ), 'rows' => $when ),
	);
	if ( $extra ) {
		$sections[] = array( 'title' => __( 'Answers', 'minn-admin' ), 'rows' => $extra );
	}
	// The note on the customer's Amelia profile. The page's Notes card holds
	// one note: the appointment's internal one, else this; with both, this
	// gets a card of its own.
	$profile = trim( (string) $row->customer_note );
	if ( '' !== $profile && '' !== $notes ) {
		$sections[] = array( 'title' => __( 'Customer profile', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Notes', 'minn-admin' ), 'value' => $profile ) ) );
	}
	$service = $row->service_name ? (string) $row->service_name : '';
	return array(
		'kind'     => 'booking',
		'status'   => (string) $row->status,
		'title'    => $service ? $service : __( 'Appointment', 'minn-admin' ),
		'customer' => array(
			'name'  => minn_admin_amelia_display_name( $row->customer_first, $row->customer_last ),
			'email' => $row->customer_email ? (string) $row->customer_email : '',
			'phone' => $row->customer_phone ? (string) $row->customer_phone : '',
		),
		'booking'  => array(
			'service'  => $service,
			'employee' => minn_admin_amelia_display_name( $row->provider_first, $row->provider_last ),
			'starts'   => minn_admin_amelia_utc_iso( $row->bookingStart ),
			'ends'     => minn_admin_amelia_utc_iso( $row->bookingEnd ),
			'people'   => max( 1, (int) $row->persons ),
			'price'    => ( null !== $row->price && '' !== (string) $row->price ) ? (string) $row->price : '',
			'notes'    => '' !== $notes ? $notes : $profile,
		),
		'sections' => $sections,
		'adminUrl' => minn_admin_amelia_admin_url(),
	);
}

$minn_amelia_list = array( 'id', 'status', 'bookingStart', 'bookingEnd', 'internalNotes', 'providerId', 's.name' => 'service_name', 'c.firstName' => 'customer_first', 'c.lastName' => 'customer_last', 'c.email' => 'customer_email', 'p.firstName' => 'provider_first', 'p.lastName' => 'provider_last' );

minn_admin_resource( 'amelia/appointments', function () use ( $minn_amelia_list ) {
	return array(
		'active'    => 'minn_admin_amelia_active',
		'gates'     => array( 'view' => 'minn_admin_amelia_can_read', 'status' => 'minn_admin_amelia_can_write_status' ),
		'labels'    => array( 'not_found' => __( 'Appointment not found', 'minn-admin' ) ),
		// Amelia grants read and write on others separately (a provider role
		// may read every booking and change only its own): a status change
		// answers to write_others on the row it changes.
		'item_gate' => function ( $id, $gate, $row ) {
			if ( 'status' !== $gate ) {
				return true;
			}
			$scope = minn_admin_amelia_provider_scope( true );
			return 0 === $scope || ( $scope > 0 && $scope === (int) $row['providerId'] );
		},
		'source'    => array(
			'table'   => 'amelia_appointments',
			'key'     => 'id',
			'joins'   => array(
				// The appointment's first customer booking (a group booking has several).
				'b' => array( 'table' => 'amelia_customer_bookings', 'first' => array( 'key' => 'id', 'by' => 'appointmentId', 'of' => 'id' ) ),
				'c' => array( 'table' => 'amelia_users', 'on' => array( 'id' => 'b.customerId' ) ),
				'p' => array( 'table' => 'amelia_users', 'on' => array( 'id' => 'e.providerId' ) ),
				's' => array( 'table' => 'amelia_services', 'on' => array( 'id' => 'e.serviceId' ) ),
			),
			'columns' => $minn_amelia_list,
			'detail'  => array_merge( $minn_amelia_list, array( 'serviceId', 'c.phone' => 'customer_phone', 'c.note' => 'customer_note', 'b.persons' => 'persons', 'b.price' => 'price', 'b.customFields' => 'customFields', 'b.info' => 'info' ) ),
			// A provider without read_others sees only their own bookings.
			'where'   => 'minn_admin_amelia_scope',
		),
		'list'      => array(
			// bookingStart is UTC (their DateTimeService writes getCustomDateTimeInUtc).
			'filter' => array(
				'param'   => 'range',
				'default' => 'upcoming',
				'values'  => array(
					'upcoming' => function () {
						return array( 'status' => array( 'approved', 'pending' ), 'bookingStart' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc' ) ) );
					},
					'pending'  => array( 'status' => 'pending' ),
					'today'    => function () {
						return array(
							"bookingStart\0from" => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc', 0 ) ),
							"bookingStart\0to"   => array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( 'utc', 1 ) ),
						);
					},
					'canceled' => array( 'status' => array( 'canceled', 'rejected', 'no-show' ) ),
					'all'      => array(),
				),
				'order'   => array( 'upcoming' => 'ASC', 'pending' => 'ASC', 'today' => 'ASC' ),
			),
			'search' => array( 'columns' => array( 'c.firstName', 'c.lastName', 'c.email', 's.name', 'p.firstName', 'p.lastName', 'internalNotes' ) ),
			'order'  => array( 'column' => 'bookingStart', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				$customer = minn_admin_amelia_display_name( $r['customer_first'], $r['customer_last'] );
				$email    = trim( (string) $r['customer_email'] );
				return array(
					'id'       => (int) $r['id'],
					'customer' => $email ? $customer . ' · ' . $email : $customer,
					'service'  => $r['service_name'] ? (string) $r['service_name'] : __( '(no service)', 'minn-admin' ),
					'status'   => (string) $r['status'],
					'date'     => $r['bookingStart'],
					'provider' => minn_admin_amelia_display_name( $r['provider_first'], $r['provider_last'] ),
				);
			},
			'fields' => array(
				'id'       => 'int',
				'customer' => 'text',
				'service'  => 'text',
				'status'   => 'text',
				'date'     => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'z' ),
				'provider' => 'text',
			),
		),
		'item'      => array( 'view' => 'minn_admin_amelia_detail' ),
		'actions'   => array(
			// Through Amelia's UpdateAppointmentStatus controller, so their
			// notifications and booking rows follow the change.
			'status' => array(
				'gate'   => 'status',
				'run'    => function ( $id, $row, $r ) {
					$status = sanitize_key( (string) $r->get_param( 'status' ) );
					if ( ! in_array( $status, array( 'approved', 'pending', 'canceled', 'rejected', 'no-show' ), true ) ) {
						return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$out = minn_admin_amelia_invoke( '\AmeliaBooking\Application\Controller\Booking\Appointment\UpdateAppointmentStatusController', array( 'status' => $status ), array( 'id' => $id ), 'POST' );
					return is_wp_error( $out ) ? $out : $status;
				},
				'expect' => function ( $id, $fresh, $status ) {
					return is_array( $fresh ) && $status === (string) $fresh['status'];
				},
				'fail'   => __( 'Amelia could not update that appointment.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh, $status ) {
					/* translators: %s: appointment status */
					return array( 'status' => $status, 'message' => sprintf( __( 'Appointment marked %s.', 'minn-admin' ), $status ) );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'amelia/status', array(
	'active' => 'minn_admin_amelia_active',
	'gate'   => 'minn_admin_amelia_can_read',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open Amelia ↗', 'minn-admin' ), 'href' => minn_admin_amelia_admin_url() ) );
		if ( ! minn_admin_amelia_has_tables() ) {
			return array(
				'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No bookings yet', 'minn-admin' ) ) ),
				'actions' => $open,
			);
		}
		if ( minn_admin_amelia_provider_scope() < 0 ) {
			return array( 'rows' => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—' ) ), 'actions' => $open );
		}
		$id      = 'amelia/appointments';
		$active  = array( 'status' => array( 'approved', 'pending' ) );
		$today   = Minn_Admin_Kernel::count( $id, array(
			"bookingStart\0from" => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc', 0 ) ),
			"bookingStart\0to"   => array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( 'utc', 1 ) ),
		) );
		$next    = Minn_Admin_Kernel::first( $id, 'bookingStart', $active + array( 'bookingStart' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc' ) ) ) );
		$next_ts = $next ? strtotime( minn_admin_amelia_utc_iso( $next ) ) : false;
		return array(
			'rows'    => array(
				array( 'label' => __( 'Today', 'minn-admin' ), 'value' => number_format_i18n( $today ) ),
				array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel::count( $id, array( 'status' => 'pending' ) ) ) ),
				array(
					'label' => __( 'Next', 'minn-admin' ),
					'value' => $next_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts ) : '—',
					'hint'  => $next_ts ? '' : __( 'Nothing upcoming', 'minn-admin' ),
				),
			),
			// The next 14 site-local days, starting today: confirmed and pending apart.
			'chart'   => array(
				'title'     => __( 'Next 14 days', 'minn-admin' ),
				'primary'   => __( 'Booked', 'minn-admin' ),
				'secondary' => __( 'Pending', 'minn-admin' ),
				'points'    => array_values( Minn_Admin_Kernel::days( $id, array( 'column' => 'bookingStart', 'clock' => 'utc', 'ahead' => true, 'where' => $active, 'split' => 'status', 'secondary' => array( 'pending' ) ) ) ),
			),
			'actions' => $open,
		);
	},
) );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_amelia_active() || ! minn_admin_amelia_can_read() ) {
		return $surfaces;
	}
	$can_write = minn_admin_amelia_can_write_status();
	$status    = function ( $label, $to, $when = null, $confirm = false ) {
		$ui = array( 'label' => $label, 'body' => array( 'status' => $to ) );
		if ( null !== $when ) {
			$ui['key']  = 'status';
			$ui['when'] = $when;
		}
		if ( $confirm ) {
			$ui['confirm'] = __( 'Cancel this appointment? Amelia will notify the customer.', 'minn-admin' );
			$ui['danger']  = true;
		}
		return Minn_Admin_Kernel::ui( 'amelia/appointments', 'status', $ui );
	};
	$actions = array( array( 'label' => __( 'Open in Amelia ↗', 'minn-admin' ), 'href' => minn_admin_amelia_admin_url() ) );
	$bulk    = array();
	if ( $can_write ) {
		$actions = array_merge( array(
			$status( __( 'Approve', 'minn-admin' ), 'approved', 'pending' ),
			$status( __( 'Cancel', 'minn-admin' ), 'canceled', 'approved', true ),
			$status( __( 'Cancel', 'minn-admin' ), 'canceled', 'pending', true ),
			$status( __( 'Mark no-show', 'minn-admin' ), 'no-show', 'approved' ),
		), $actions );
		$cancel  = $status( __( 'Cancel', 'minn-admin' ), 'canceled' );
		unset( $cancel['confirm'] );
		$cancel['danger'] = true;
		$bulk    = array( $status( __( 'Approve', 'minn-admin' ), 'approved' ), $cancel );
	}
	$surfaces['amelia'] = array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'commerce',
		'sub'        => 'Amelia',
		'plugin'     => 'ameliabooking',
		'icon'       => 'calendar',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/amelia/status' ),
		'collection' => Minn_Admin_Kernel::collection( 'amelia/appointments', array(
			'viewLabel' => __( 'Appointments', 'minn-admin' ),
			'filter'    => array(
				'label'   => __( 'When', 'minn-admin' ),
				'options' => array(
					array( 'upcoming', __( 'Upcoming', 'minn-admin' ) ),
					array( 'pending', __( 'Pending', 'minn-admin' ) ),
					array( 'today', __( 'Today', 'minn-admin' ) ),
					array( 'canceled', __( 'Canceled', 'minn-admin' ) ),
					array( 'all', __( 'All', 'minn-admin' ) ),
				),
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
		) ),
	);
	return $surfaces;
} );
