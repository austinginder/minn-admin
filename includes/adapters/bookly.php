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

// Bookly Cloud's account token.
minn_admin_secrets( 'bookly-responsive-appointment-booking-tool', array( 'options' => array( 'bookly_cloud_token' ) ) );

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

// The supervisors and staff who may read a booking may change its status.
function minn_admin_bookly_can_write() {
	return minn_admin_bookly_can_read();
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
	$row = (object) $row;
	// The staff's note is on the appointment, the customer's on their booking
	// of it. Both are text the client escapes.
	$internal = trim( (string) $row->internal_note );
	$comment  = trim( (string) $row->customer_notes );
	$when     = array();
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
		'name'     => minn_admin_booking_name( $row->customer_first, $row->customer_last, $row->customer_full ),
		'email'    => $row->customer_email,
		'phone'    => $row->customer_phone,
		'people'   => $row->number_of_persons,
		'service'  => $row->service_name,
		'employee' => $row->staff_name ? (string) $row->staff_name : '',
		'starts'   => minn_admin_bookly_local_iso( $row->start_date ),
		'ends'     => minn_admin_bookly_local_iso( $row->end_date ),
		'status'   => $row->status,
		'rows'     => $when,
		'sections' => $sections,
		'notes'    => '' !== $internal ? $internal : $comment,
		'admin'    => minn_admin_bookly_admin_url(),
	) );
}

$minn_bookly_list = array( 'id', 'status', 'number_of_persons', 'a.start_date', 'a.end_date', 's.title' => 'service_name', 'c.first_name' => 'customer_first', 'c.last_name' => 'customer_last', 'c.full_name' => 'customer_full', 'c.email' => 'customer_email', 'st.full_name' => 'staff_name' );

// start_date is WP-local.
$minn_bookly_views = array(
	'upcoming' => function () {
		return array( 'status' => array( 'approved', 'pending', 'waitlisted' ), 'a.start_date' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local' ) ) );
	},
	'pending'  => array( 'status' => array( 'pending', 'waitlisted' ) ),
	'today'    => minn_admin_booking_today( 'a.start_date', 'local' ),
	'canceled' => array( 'status' => array( 'cancelled', 'rejected' ) ),
);

// One row per customer's booking of an appointment (customer_appointments),
// joined to the appointment, customer, service and staff member.
minn_admin_resource( 'bookly/appointments', function () use ( $minn_bookly_list, $minn_bookly_views ) {
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
			'viewer'  => true,
			// The orders and payments behind the bookings, and what was sent
			// to the customers and what their booking forms kept.
			'tables'  => array( 'bookly_orders', 'bookly_payments', 'bookly_sent_notifications', 'bookly_sms_log', 'bookly_form_sessions', 'bookly_log' ),
		),
		'list'    => array(
			'filter' => minn_admin_booking_filter( $minn_bookly_views ),
			'search' => array( 'columns' => array( 'c.first_name', 'c.last_name', 'c.full_name', 'c.email', 's.title', 'st.full_name' ) ),
			'order'  => array( 'column' => 'a.start_date', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				return minn_admin_booking_row( $r['id'], minn_admin_booking_name( $r['customer_first'], $r['customer_last'], $r['customer_full'] ), $r['customer_email'], $r['service_name'], $r['status'], $r['start_date'], $r['staff_name'] ? (string) $r['staff_name'] : __( '(no name)', 'minn-admin' ) );
			},
			'fields' => minn_admin_booking_fields( 'site' ),
		),
		'item'    => array( 'view' => 'minn_admin_bookly_detail' ),
		'actions' => array(
			// Through their CustomerAppointment entity, then their sender.
			'status' => minn_admin_booking_move( array(
				'known'  => 'minn_admin_bookly_statuses',
				'write'  => function ( $id, $status ) {
					try {
						$ca = new \Bookly\Lib\Entities\CustomerAppointment();
						if ( ! $ca->load( $id ) ) {
							return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
						}
						// Their own status change refuses a move that would put
						// more people in the appointment than the staff member's
						// service holds (card/Ajax.php changeBookingStatus →
						// failIfOverCapacity): a booking taken off the waiting list
						// overfills it as surely as added seats do.
						$busy = (array) \Bookly\Lib\Proxy\CustomStatuses::prepareBusyStatuses( array( \Bookly\Lib\Entities\CustomerAppointment::STATUS_PENDING, \Bookly\Lib\Entities\CustomerAppointment::STATUS_APPROVED ) );
						$appt = $status !== $ca->getStatus() && in_array( $status, $busy, true ) ? \Bookly\Lib\Entities\Appointment::find( $ca->getAppointmentId() ) : null;
						if ( $appt && $appt->getServiceId() ) {
							$taken = (int) $ca->getNumberOfPersons();
							foreach ( $appt->getCustomerAppointments() as $other ) {
								if ( (int) $other->getId() !== (int) $ca->getId() && in_array( $other->getStatus(), $busy, true ) ) {
									$taken += (int) $other->getNumberOfPersons();
								}
							}
							$ss = new \Bookly\Lib\Entities\StaffService();
							if ( $ss->loadBy( array( 'staff_id' => $appt->getStaffId(), 'service_id' => $appt->getServiceId(), 'location_id' => null ) ) && $taken > (int) $ss->getCapacityMax() ) {
								/* translators: %d: the most people the service takes at once. */
								return new WP_Error( 'minn_bookly_full', sprintf( __( 'That would put more people in this appointment than the service takes (%d).', 'minn-admin' ), (int) $ss->getCapacityMax() ), array( 'status' => 409 ) );
							}
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
				},
				'fail'   => __( 'Bookly could not update that appointment.', 'minn-admin' ),
				/* translators: %s: appointment status */
				'marked' => __( 'Appointment marked %s.', 'minn-admin' ),
			) ),
		),
	);
} );

minn_admin_endpoint( 'bookly/status', array(
	'active' => 'minn_admin_bookly_active',
	'gate'   => 'minn_admin_bookly_can_read',
	'run'    => function () use ( $minn_bookly_views ) {
		return minn_admin_booking_card( 'bookly/appointments', $minn_bookly_views, array(
			'ready' => minn_admin_bookly_has_tables(),
			'noun'  => __( 'Appointments', 'minn-admin' ),
			'empty' => __( 'No bookings yet', 'minn-admin' ),
			'scope' => 'minn_admin_bookly_scope',
			'next'  => 'a.start_date',
			'iso'   => 'minn_admin_bookly_local_iso',
			// The next 14 site-local days, starting today: approved and the rest apart.
			'chart' => array( 'column' => 'a.start_date', 'clock' => 'local', 'where' => array( 'status' => array( 'approved', 'pending', 'waitlisted' ) ), 'secondary' => array( 'pending', 'waitlisted' ) ),
			'open'  => array( __( 'Open Bookly ↗', 'minn-admin' ), minn_admin_bookly_admin_url() ),
		) );
	},
) );

minn_admin_add_surface( 'bookly', function () {
	return minn_admin_bookly_active() && minn_admin_bookly_can_read();
}, function () {
	return minn_admin_booking_surface( 'bookly/appointments', array(
		'sub'    => 'Bookly',
		'plugin' => 'bookly-responsive-appointment-booking-tool',
		'status' => 'bookly/status',
		'noun'   => __( 'Appointments', 'minn-admin' ),
		'open'   => array( __( 'Open in Bookly ↗', 'minn-admin' ), minn_admin_bookly_admin_url() ),
		'moves'  => minn_admin_bookly_can_write() ? array(
			array( __( 'Approve', 'minn-admin' ), 'approved', 'pending' ),
			array( __( 'Cancel', 'minn-admin' ), 'cancelled', array( 'approved', 'pending' ), __( 'Cancel this appointment? Bookly will notify the customer.', 'minn-admin' ) ),
		) : array(),
	) );
} );
