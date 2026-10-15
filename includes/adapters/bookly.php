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
	// Bookly's staff lookup matches wp_user_id against the current user id
	// with no logged-in check of its own; a staff row that ever stored 0
	// instead of NULL would match an anonymous caller.
	if ( ! is_user_logged_in() ) {
		return false;
	}
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
	if ( ! is_user_logged_in() ) {
		return false;
	}
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
	return Minn_Admin_Kernel_SQL::exists( 'bookly_customer_appointments' );
}

/**
 * Restrict list queries to these staff ids, or empty for everyone.
 *
 * @return int[] Empty = all; [-1] = none.
 */
function minn_admin_bookly_staff_scope() {
	if ( ! is_user_logged_in() ) {
		return array( -1 );
	}
	if ( class_exists( '\Bookly\Lib\Utils\Common' ) && \Bookly\Lib\Utils\Common::isCurrentUserSupervisor() ) {
		return array();
	}
	// A WP user can be linked to more than one staff row; Bookly scopes to all
	// of them. Their own resolver (28.3+) is the source of truth when present.
	if ( class_exists( '\Bookly\Lib\Utils\Common' ) && method_exists( '\Bookly\Lib\Utils\Common', 'getCurrentUserStaffIds' ) ) {
		$ids = \Bookly\Lib\Utils\Common::getCurrentUserStaffIds();
		if ( null === $ids ) {
			return array();
		}
	} else {
		if ( ! Minn_Admin_Kernel_SQL::exists( 'bookly_staff' ) ) {
			return array( -1 );
		}
		$ids = array_column( Minn_Admin_Kernel_SQL::select( 'bookly_staff', array( 'id' ), array( 'wp_user_id' => get_current_user_id() ) ), 'id' );
	}
	$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	return $ids ? $ids : array( -1 );
}

/** The staff scope as conditions on the appointment: none for everyone, null for nobody. */
function minn_admin_bookly_scope() {
	$scope = minn_admin_bookly_staff_scope();
	if ( array( -1 ) === $scope ) {
		return null;
	}
	return $scope ? array( 'a.staff_id' => $scope ) : array();
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

/** A booking's detail: the customer, the appointment and both notes. */
function minn_admin_bookly_detail( array $row ) {
	$row  = (object) $row;
	$name = minn_admin_bookly_display_name( $row->customer_first, $row->customer_last, $row->customer_full );
	$who  = array(
		array( 'label' => __( 'Name', 'minn-admin' ), 'value' => $name ),
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
	// The staff's note is on the appointment, the customer's on their booking
	// of it. Both are text the client escapes.
	$internal = trim( (string) $row->internal_note );
	$comment  = trim( (string) $row->customer_notes );
	if ( '' !== $internal ) {
		$when[] = array( 'label' => __( 'Notes', 'minn-admin' ), 'value' => $internal );
	}
	if ( '' !== $comment ) {
		$when[] = array( 'label' => __( 'Booking note', 'minn-admin' ), 'value' => $comment );
	}
	$sections = array(
		array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => $who ),
		array( 'title' => __( 'Appointment', 'minn-admin' ), 'rows' => $when ),
	);
	// The page's Notes card holds one note: the internal one, else the
	// customer's. With both, the customer's gets a card of its own.
	if ( '' !== $internal && '' !== $comment ) {
		$sections[] = array( 'title' => __( 'From the customer', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Booking note', 'minn-admin' ), 'value' => $comment ) ) );
	}
	$service = $row->service_name ? (string) $row->service_name : '';
	return array(
		'kind'     => 'booking',
		'status'   => (string) $row->status,
		'title'    => $service ? $service : __( 'Appointment', 'minn-admin' ),
		'customer' => array(
			'name'  => $name,
			'email' => $row->customer_email ? (string) $row->customer_email : '',
			'phone' => $row->customer_phone ? (string) $row->customer_phone : '',
		),
		'booking'  => array(
			'service'  => $service,
			'employee' => $row->staff_name ? (string) $row->staff_name : '',
			'starts'   => minn_admin_bookly_local_iso( $row->start_date ),
			'ends'     => minn_admin_bookly_local_iso( $row->end_date ),
			'people'   => max( 1, (int) $row->number_of_persons ),
			'notes'    => '' !== $internal ? $internal : $comment,
		),
		'sections' => $sections,
		'adminUrl' => minn_admin_bookly_admin_url(),
	);
}

$minn_bookly_list = array( 'id', 'status', 'number_of_persons', 'a.start_date', 'a.end_date', 's.title' => 'service_name', 'c.first_name' => 'customer_first', 'c.last_name' => 'customer_last', 'c.full_name' => 'customer_full', 'c.email' => 'customer_email', 'st.full_name' => 'staff_name' );

// One row per customer's booking of an appointment (customer_appointments),
// joined to the appointment, customer, service and staff member.
minn_admin_resource( 'bookly/appointments', function () use ( $minn_bookly_list ) {
	return array(
		'active'  => 'minn_admin_bookly_active',
		'gates'   => array( 'view' => 'minn_admin_bookly_can_read', 'status' => 'minn_admin_bookly_can_write' ),
		'labels'  => array( 'not_found' => __( 'Appointment not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'bookly_customer_appointments',
			'key'     => 'id',
			'joins'   => array(
				'a'  => array( 'table' => 'bookly_appointments', 'on' => array( 'id' => 'e.appointment_id' ) ),
				'c'  => array( 'table' => 'bookly_customers', 'on' => array( 'id' => 'e.customer_id' ) ),
				's'  => array( 'table' => 'bookly_services', 'on' => array( 'id' => 'a.service_id' ) ),
				'st' => array( 'table' => 'bookly_staff', 'on' => array( 'id' => 'a.staff_id' ) ),
			),
			'columns' => $minn_bookly_list,
			'detail'  => array_merge( $minn_bookly_list, array( 'notes' => 'customer_notes', 'a.internal_note', 'c.phone' => 'customer_phone' ) ),
			// Staff see the bookings of the staff rows linked to their account.
			'where'   => 'minn_admin_bookly_scope',
		),
		'list'    => array(
			// start_date is WP-local.
			'filter' => array(
				'param'   => 'range',
				'default' => 'upcoming',
				'values'  => array(
					'upcoming' => function () {
						return array( 'status' => array( 'approved', 'pending', 'waitlisted' ), 'a.start_date' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local' ) ) );
					},
					'pending'  => array( 'status' => array( 'pending', 'waitlisted' ) ),
					'today'    => function () {
						return array(
							"a.start_date\0from" => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local', 0 ) ),
							"a.start_date\0to"   => array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( 'local', 1 ) ),
						);
					},
					'canceled' => array( 'status' => array( 'cancelled', 'rejected' ) ),
					'all'      => array(),
				),
				'order'   => array( 'upcoming' => 'ASC', 'pending' => 'ASC', 'today' => 'ASC' ),
			),
			'search' => array( 'columns' => array( 'c.first_name', 'c.last_name', 'c.full_name', 'c.email', 's.title', 'st.full_name' ) ),
			'order'  => array( 'column' => 'a.start_date', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				$customer = minn_admin_bookly_display_name( $r['customer_first'], $r['customer_last'], $r['customer_full'] );
				$email    = trim( (string) $r['customer_email'] );
				return array(
					'id'       => (int) $r['id'],
					'customer' => $email ? $customer . ' · ' . $email : $customer,
					'service'  => $r['service_name'] ? (string) $r['service_name'] : __( '(no service)', 'minn-admin' ),
					'status'   => (string) $r['status'],
					'date'     => $r['start_date'],
					'provider' => $r['staff_name'] ? (string) $r['staff_name'] : __( '(no name)', 'minn-admin' ),
				);
			},
			'fields' => array(
				'id'       => 'int',
				'customer' => 'text',
				'service'  => 'text',
				'status'   => 'text',
				'date'     => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'z' ),
				'provider' => 'text',
			),
		),
		'item'    => array( 'view' => 'minn_admin_bookly_detail' ),
		'actions' => array(
			// Through their CustomerAppointment entity, then their sender.
			'status' => array(
				'gate'   => 'status',
				'run'    => function ( $id, $row, $r ) {
					$status = sanitize_key( (string) $r->get_param( 'status' ) );
					if ( ! in_array( $status, minn_admin_bookly_statuses(), true ) ) {
						return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
					}
					try {
						$ca = new \Bookly\Lib\Entities\CustomerAppointment();
						if ( ! $ca->load( $id ) ) {
							return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
						}
						$ca->setStatus( $status );
						if ( false === $ca->save() ) {
							return new WP_Error( 'update_failed', __( 'Bookly could not update that appointment.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						if ( class_exists( '\Bookly\Lib\Notifications\Booking\Sender' ) ) {
							try {
								\Bookly\Lib\Notifications\Booking\Sender::sendForCA( $ca );
							} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
								// Status is already written; a notification failure must not undo it.
							}
						}
					} catch ( \Throwable $e ) {
						return new WP_Error( 'update_failed', __( 'Bookly could not update that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return $status;
				},
				'expect' => function ( $id, $fresh, $status ) {
					return is_array( $fresh ) && $status === (string) $fresh['status'];
				},
				'fail'   => __( 'Bookly could not update that appointment.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh, $status ) {
					/* translators: %s: appointment status */
					return array( 'status' => $status, 'message' => sprintf( __( 'Appointment marked %s.', 'minn-admin' ), str_replace( '_', ' ', $status ) ) );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'bookly/status', array(
	'active' => 'minn_admin_bookly_active',
	'gate'   => 'minn_admin_bookly_can_read',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open Bookly ↗', 'minn-admin' ), 'href' => minn_admin_bookly_admin_url() ) );
		if ( ! minn_admin_bookly_has_tables() ) {
			return array(
				'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No bookings yet', 'minn-admin' ) ) ),
				'actions' => $open,
			);
		}
		if ( null === minn_admin_bookly_scope() ) {
			return array( 'rows' => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—' ) ), 'actions' => $open );
		}
		$id      = 'bookly/appointments';
		$active  = array( 'status' => array( 'approved', 'pending', 'waitlisted' ) );
		$next    = Minn_Admin_Kernel::first( $id, 'a.start_date', $active + array( 'a.start_date' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local' ) ) ) );
		$next_ts = $next ? strtotime( minn_admin_bookly_local_iso( $next ) ) : false;
		return array(
			'rows'    => array(
				array( 'label' => __( 'Today', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel::count( $id, array(
					"a.start_date\0from" => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local', 0 ) ),
					"a.start_date\0to"   => array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( 'local', 1 ) ),
				) ) ) ),
				array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel::count( $id, array( 'status' => array( 'pending', 'waitlisted' ) ) ) ) ),
				array(
					'label' => __( 'Next', 'minn-admin' ),
					'value' => $next_ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_ts ) : '—',
					'hint'  => $next_ts ? '' : __( 'Nothing upcoming', 'minn-admin' ),
				),
			),
			// The next 14 site-local days, starting today: approved and the rest apart.
			'chart'   => array(
				'title'     => __( 'Next 14 days', 'minn-admin' ),
				'primary'   => __( 'Booked', 'minn-admin' ),
				'secondary' => __( 'Pending', 'minn-admin' ),
				'points'    => array_values( Minn_Admin_Kernel::days( $id, array( 'column' => 'a.start_date', 'clock' => 'local', 'ahead' => true, 'where' => $active, 'split' => 'status', 'secondary' => array( 'pending', 'waitlisted' ) ) ) ),
			),
			'actions' => $open,
		);
	},
) );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_bookly_active() || ! minn_admin_bookly_can_read() ) {
		return $surfaces;
	}
	$status  = function ( $label, $to, $when = null, $confirm = false ) {
		$ui = array( 'label' => $label, 'body' => array( 'status' => $to ) );
		if ( null !== $when ) {
			$ui['key']  = 'status';
			$ui['when'] = $when;
		}
		if ( $confirm ) {
			$ui['confirm'] = __( 'Cancel this appointment? Bookly will notify the customer.', 'minn-admin' );
		}
		if ( 'cancelled' === $to ) {
			$ui['danger'] = true;
		}
		return Minn_Admin_Kernel::ui( 'bookly/appointments', 'status', $ui );
	};
	$actions = array( array( 'label' => __( 'Open in Bookly ↗', 'minn-admin' ), 'href' => minn_admin_bookly_admin_url() ) );
	$bulk    = array();
	if ( minn_admin_bookly_can_write() ) {
		$actions = array_merge( array(
			$status( __( 'Approve', 'minn-admin' ), 'approved', 'pending' ),
			$status( __( 'Cancel', 'minn-admin' ), 'cancelled', 'approved', true ),
			$status( __( 'Cancel', 'minn-admin' ), 'cancelled', 'pending', true ),
		), $actions );
		$bulk    = array( $status( __( 'Approve', 'minn-admin' ), 'approved' ), $status( __( 'Cancel', 'minn-admin' ), 'cancelled' ) );
	}
	$surfaces['bookly'] = array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'commerce',
		'sub'        => 'Bookly',
		'plugin'     => 'bookly-responsive-appointment-booking-tool',
		'icon'       => 'calendar',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/bookly/status' ),
		'collection' => Minn_Admin_Kernel::collection( 'bookly/appointments', array(
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
				'sectionsRoute' => 'minn-admin/v1/bookly/appointments/{id}',
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		) ),
	);
	return $surfaces;
} );
