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
	$row    = (object) $row;
	$priced = null !== $row->price && '' !== (string) $row->price;
	$when   = array();
	if ( $priced ) {
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
	$sections = array();
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
	return minn_admin_booking_detail( array(
		'noun'     => __( 'Appointment', 'minn-admin' ),
		'name'     => minn_admin_booking_name( $row->customer_first, $row->customer_last ),
		'email'    => $row->customer_email,
		'phone'    => $row->customer_phone,
		'people'   => $row->persons,
		'service'  => $row->service_name,
		'employee' => minn_admin_booking_name( $row->provider_first, $row->provider_last ),
		'starts'   => minn_admin_amelia_utc_iso( $row->bookingStart ),
		'ends'     => minn_admin_amelia_utc_iso( $row->bookingEnd ),
		'status'   => $row->status,
		'rows'     => $when,
		'sections' => $sections,
		'booking'  => array( 'price' => $priced ? (string) $row->price : '' ),
		'notes'    => '' !== $notes ? $notes : $profile,
		'admin'    => minn_admin_amelia_admin_url(),
	) );
}

$minn_amelia_list = array( 'id', 'status', 'bookingStart', 'bookingEnd', 'internalNotes', 'providerId', 's.name' => 'service_name', 'c.firstName' => 'customer_first', 'c.lastName' => 'customer_last', 'c.email' => 'customer_email', 'p.firstName' => 'provider_first', 'p.lastName' => 'provider_last' );

// bookingStart is UTC (their DateTimeService writes getCustomDateTimeInUtc).
$minn_amelia_views = array(
	'upcoming' => function () {
		return array( 'status' => array( 'approved', 'pending' ), 'bookingStart' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc' ) ) );
	},
	'pending'  => array( 'status' => 'pending' ),
	'today'    => minn_admin_booking_today( 'bookingStart', 'utc' ),
	'canceled' => array( 'status' => array( 'canceled', 'rejected', 'no-show' ) ),
);

minn_admin_resource( 'amelia/appointments', function () use ( $minn_amelia_list, $minn_amelia_views ) {
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
			'viewer'  => true,
			// The payments, extras, event bookings and packages behind the
			// bookings, and the notifications sent to their customers.
			'tables'  => array(
				'amelia_payments',
				'amelia_customer_bookings_to_extras',
				'amelia_customer_bookings_to_events_periods',
				'amelia_customer_bookings_to_events_tickets',
				'amelia_packages_to_customers',
				'amelia_packages_customers_to_services',
				'amelia_notifications_log',
				'amelia_notifications_sms_history',
			),
		),
		'list'      => array(
			'filter' => minn_admin_booking_filter( $minn_amelia_views ),
			'search' => array( 'columns' => array( 'c.firstName', 'c.lastName', 'c.email', 's.name', 'p.firstName', 'p.lastName', 'internalNotes' ) ),
			'order'  => array( 'column' => 'bookingStart', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				return minn_admin_booking_row( $r['id'], minn_admin_booking_name( $r['customer_first'], $r['customer_last'] ), $r['customer_email'], $r['service_name'], $r['status'], $r['bookingStart'], minn_admin_booking_name( $r['provider_first'], $r['provider_last'] ) );
			},
			'fields' => minn_admin_booking_fields( 'utc' ),
		),
		'item'      => array( 'view' => 'minn_admin_amelia_detail' ),
		'actions'   => array(
			// Through Amelia's UpdateAppointmentStatus controller, so their
			// notifications and booking rows follow the change.
			'status' => minn_admin_booking_move( array(
				'known'  => array( 'approved', 'pending', 'canceled', 'rejected', 'no-show' ),
				'write'  => function ( $id, $status ) {
					return minn_admin_amelia_invoke( '\AmeliaBooking\Application\Controller\Booking\Appointment\UpdateAppointmentStatusController', array( 'status' => $status ), array( 'id' => $id ), 'POST' );
				},
				'fail'   => __( 'Amelia could not update that appointment.', 'minn-admin' ),
				/* translators: %s: appointment status */
				'marked' => __( 'Appointment marked %s.', 'minn-admin' ),
			) ),
		),
	);
} );

minn_admin_endpoint( 'amelia/status', array(
	'active' => 'minn_admin_amelia_active',
	'gate'   => 'minn_admin_amelia_can_read',
	'run'    => function () use ( $minn_amelia_views ) {
		return minn_admin_booking_card( 'amelia/appointments', $minn_amelia_views, array(
			'ready' => minn_admin_amelia_has_tables(),
			'noun'  => __( 'Appointments', 'minn-admin' ),
			'empty' => __( 'No bookings yet', 'minn-admin' ),
			'scope' => 'minn_admin_amelia_scope',
			'next'  => 'bookingStart',
			'iso'   => 'minn_admin_amelia_utc_iso',
			// The next 14 site-local days, starting today: confirmed and pending apart.
			'chart' => array( 'column' => 'bookingStart', 'clock' => 'utc', 'where' => array( 'status' => array( 'approved', 'pending' ) ), 'secondary' => array( 'pending' ) ),
			'open'  => array( __( 'Open Amelia ↗', 'minn-admin' ), minn_admin_amelia_admin_url() ),
		) );
	},
) );

minn_admin_add_surface( 'amelia', function () {
	return minn_admin_amelia_active() && minn_admin_amelia_can_read();
}, function () {
	return minn_admin_booking_surface( 'amelia/appointments', array(
		'sub'    => 'Amelia',
		'plugin' => 'ameliabooking',
		'status' => 'amelia/status',
		'noun'   => __( 'Appointments', 'minn-admin' ),
		'open'   => array( __( 'Open in Amelia ↗', 'minn-admin' ), minn_admin_amelia_admin_url() ),
		'moves'  => minn_admin_amelia_can_write_status() ? array(
			array( __( 'Approve', 'minn-admin' ), 'approved', 'pending' ),
			array( __( 'Cancel', 'minn-admin' ), 'canceled', array( 'approved', 'pending' ), __( 'Cancel this appointment? Amelia will notify the customer.', 'minn-admin' ) ),
			array( __( 'Mark no-show', 'minn-admin' ), 'no-show', 'approved' ),
		) : array(),
	) );
} );
