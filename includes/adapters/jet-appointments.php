<?php
/**
 * Bundled adapter: JetAppointments Booking (Crocoblock, Bookings family).
 *
 * JetAppointments keeps every appointment in {prefix}jet_appointments
 * (ID, group_ID, status, service and provider = post ids of the
 * configured CPTs, order_id, user_id, user_name, user_email, date = day
 * epoch, slot / slot_end = the slot's epochs, type) plus a meta table
 * (phone, comments and form fields land there, keyed as the form named
 * them). Services and providers are ordinary posts and list in Content;
 * the appointments have no post and only ever show on the plugin's Vue
 * screen. This surface is their inbox: upcoming / pending / today /
 * canceled, a contact-style detail, status changes through the plugin's
 * own Appointment_Model (save() fires jet-apb/db/update/appointments,
 * which its workflows and notifications listen to, and moves the slot in
 * and out of the excluded-dates table exactly as their endpoint does),
 * delete through its DB manager, and a status card with a deep link.
 * Services, providers, schedules and the booking form stay in the plugin.
 *
 * Epochs are stored the way the plugin formats them, date_i18n() on the
 * raw value: WP-local naive timestamps, never UTC, so they leave this
 * adapter as that site-local time with its own offset. Statuses are theirs
 * (pending, processing, on-hold, completed, cancelled, refunded, failed),
 * read live from their Statuses class. Caps are theirs: every screen and
 * endpoint resolves through Plugin::current_user_can( $context ) over the
 * capability_type setting (default manage_options) and the
 * jet-apb/capability filter, so the same resolver is called here with the
 * same context names their endpoints use.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_apb_active() {
	return class_exists( '\JET_APB\Plugin' ) && class_exists( '\JET_APB\Resources\Appointment_Model' );
}

function minn_admin_jet_apb_can( $context ) {
	try {
		return (bool) \JET_APB\Plugin::instance()->current_user_can( $context );
	} catch ( \Throwable $e ) {
		return false;
	}
}

function minn_admin_jet_apb_can_read() {
	return minn_admin_jet_apb_can( 'appointments-list' );
}

/**
 * The detail view stands in for two of their endpoints, not one: reading an
 * appointment and reading its meta. A site that separates those contexts
 * through their capability filter asked for the separation, and gating the
 * whole detail on the list context handed the customer's name, email, phone
 * and comments to a role given list-only access.
 */
function minn_admin_jet_apb_can_detail() {
	return minn_admin_jet_apb_can( 'get-appointment' );
}

function minn_admin_jet_apb_can_meta() {
	return minn_admin_jet_apb_can( 'appointment-meta' );
}

function minn_admin_jet_apb_can_update() {
	return minn_admin_jet_apb_can( 'update-appointment' );
}

function minn_admin_jet_apb_can_delete() {
	return minn_admin_jet_apb_can( 'delete-appointment' );
}

function minn_admin_jet_apb_table() {
	global $wpdb;
	return $wpdb->prefix . 'jet_appointments';
}

function minn_admin_jet_apb_has_tables() {
	return Minn_Admin_Kernel_SQL::exists( 'jet_appointments' );
}

function minn_admin_jet_apb_statuses() {
	try {
		return (array) \JET_APB\Plugin::instance()->statuses->get_statuses();
	} catch ( \Throwable $e ) {
		return array();
	}
}

function minn_admin_jet_apb_status_set( $which ) {
	try {
		$s = \JET_APB\Plugin::instance()->statuses;
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
 * WP-local naive epoch (their date_i18n convention) → ISO-8601 with the
 * site's offset at that time. Today's gmt_offset put a slot in the other
 * half of the year an hour off.
 */
function minn_admin_jet_apb_iso( $naive ) {
	$naive = (int) $naive;
	if ( $naive <= 0 ) {
		return '';
	}
	try {
		return ( new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', $naive ), wp_timezone() ) )->format( 'c' );
	} catch ( \Exception $e ) {
		return '';
	}
}

/** The label their screen shows for a slot: site date + time formats over the naive epoch. */
function minn_admin_jet_apb_slot_label( $naive, $with_date = true ) {
	$naive = (int) $naive;
	if ( $naive <= 0 ) {
		return '';
	}
	$fmt = $with_date ? get_option( 'date_format', 'F j, Y' ) . ' ' . get_option( 'time_format', 'H:i' ) : get_option( 'time_format', 'H:i' );
	return date_i18n( $fmt, $naive );
}

function minn_admin_jet_apb_admin_url() {
	return admin_url( 'admin.php?page=jet-apb-appointments' );
}

function minn_admin_jet_apb_customer_label( $row ) {
	$name  = trim( (string) ( $row->user_name ?? '' ) );
	$email = trim( (string) ( $row->user_email ?? '' ) );
	if ( '' === $name && ! empty( $row->user_id ) ) {
		$u = get_userdata( (int) $row->user_id );
		if ( $u ) {
			$name = $u->display_name;
			if ( '' === $email ) {
				$email = (string) $u->user_email;
			}
		}
	}
	if ( '' !== $name && '' !== $email ) {
		return $name . ' · ' . $email;
	}
	if ( '' !== $name ) {
		return $name;
	}
	return '' !== $email ? $email : __( '(no name)', 'minn-admin' );
}

/** Meta rows for one appointment, key => value, through their meta table. */
function minn_admin_jet_apb_meta( $id ) {
	$out = array();
	foreach ( Minn_Admin_Kernel_SQL::select( 'jet_appointments_meta', array( 'meta_key', 'meta_value' ), array( 'appointment_id' => (int) $id ) ) as $r ) {
		$v = (string) $r['meta_value'];
		// Serialized values stay opaque: shown as stored, never unserialized.
		if ( is_serialized( $v ) ) {
			continue;
		}
		$out[ (string) $r['meta_key'] ] = $v;
	}
	return $out;
}

/**
 * Labels their own settings give particular meta keys, key => label.
 *
 * NOT an allowlist for the detail, though it looks like one: on a real install
 * get_appointment_fields() returns only the extra columns their settings screen
 * offers (User Local Date / Time / Timezone), never the fields the booking form
 * collected. Gating the detail on it hid the guest's phone and comments and
 * showed the three timezone rows Minn deliberately withholds, which is the
 * exact inverse of what it should do. It is worth asking for the LABELS it
 * does define, and nothing more.
 *
 * @return array<string,string>|null
 */
function minn_admin_jet_apb_display_fields() {
	try {
		$settings = \JET_APB\Plugin::instance()->settings;
		if ( ! is_object( $settings ) || ! method_exists( $settings, 'get_appointment_fields' ) ) {
			return null;
		}
		$out = array();
		foreach ( (array) $settings->get_appointment_fields() as $key => $field ) {
			$field = (array) $field;
			$k     = (string) ( $field['name'] ?? $key );
			if ( '' === $k ) {
				continue;
			}
			$out[ $k ] = (string) ( $field['label'] ?? ucwords( str_replace( array( '_', '-' ), ' ', $k ) ) );
		}
		return $out;
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * One appointment's detail. The meta table is their second endpoint, with its
 * own context: a site that granted the detail but not the meta gets the
 * appointment without the form answers or the phone.
 */
function minn_admin_jet_apb_detail( array $r ) {
	$row   = (object) $r;
	$meta  = minn_admin_jet_apb_can_meta() ? minn_admin_jet_apb_meta( (int) $row->ID ) : array();
	$name  = trim( (string) $row->user_name );
	$email = trim( (string) $row->user_email );
	if ( ( '' === $name || '' === $email ) && ! empty( $row->user_id ) ) {
		$u = get_userdata( (int) $row->user_id );
		if ( $u ) {
			$name  = '' !== $name ? $name : $u->display_name;
			$email = '' !== $email ? $email : (string) $u->user_email;
		}
	}
	$phone = '';
	foreach ( array( 'phone', 'user_phone', 'tel', 'telephone' ) as $k ) {
		if ( ! empty( $meta[ $k ] ) ) {
			$phone = (string) $meta[ $k ];
			break;
		}
	}
	$who   = array(
		array( 'label' => __( 'Name', 'minn-admin' ), 'value' => '' !== $name ? $name : '—' ),
		array( 'label' => __( 'Email', 'minn-admin' ), 'value' => '' !== $email ? $email : '—' ),
		array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => '' !== $phone ? $phone : '—' ),
	);
	$notes = '';
	// The keys their own screen displays, when it can tell us. Falling back
	// to the blocklist keeps older versions working, with the operational
	// plumbing named explicitly so it is not printed either way.
	$labels = minn_admin_jet_apb_display_fields();
	$hidden = array( 'phone', 'user_phone', 'tel', 'telephone', 'user_timezone', 'user_local_time', 'user_local_date' );
	foreach ( $meta as $k => $v ) {
		if ( in_array( $k, $hidden, true ) ) {
			continue;
		}
		// The integrations write their bookkeeping onto the same row as the
		// guest's answers: a Google Calendar id, the raw text of a failed API
		// call, a Zoom meeting id, workflow schedule ids. Their own screen
		// shows none of it, and it is not what somebody opens a booking to read.
		if ( preg_match( '/^(gcal|zoom|_schedule_id)/', $k ) ) {
			continue;
		}
		$v = trim( (string) $v );
		if ( '' === $v ) {
			continue;
		}
		$label = ( is_array( $labels ) && ! empty( $labels[ $k ] ) ) ? (string) $labels[ $k ] : ucwords( str_replace( array( '_', '-' ), ' ', $k ) );
		$who[] = array( 'label' => $label, 'value' => $v );
		if ( '' === $notes && in_array( $k, array( 'comments', 'comment', 'message', 'notes' ), true ) ) {
			$notes = $v;
		}
	}
	$service  = $row->service_title ? (string) $row->service_title : '';
	$provider = $row->provider_title ? (string) $row->provider_title : '';
	$when     = array(
		array( 'label' => __( 'Service', 'minn-admin' ), 'value' => '' !== $service ? $service : '—' ),
		array( 'label' => __( 'Provider', 'minn-admin' ), 'value' => '' !== $provider ? $provider : '—' ),
		array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => minn_admin_jet_apb_slot_label( $row->slot ) ),
		array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => minn_admin_jet_apb_slot_label( $row->slot_end ) ),
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status ),
	);
	if ( ! empty( $row->order_id ) ) {
		$when[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => '#' . (int) $row->order_id );
	}
	if ( ! empty( $row->type ) ) {
		$when[] = array( 'label' => __( 'Type', 'minn-admin' ), 'value' => (string) $row->type );
	}
	return array(
		'kind'     => 'booking',
		'status'   => (string) $row->status,
		'title'    => '' !== $service ? $service : __( 'Appointment', 'minn-admin' ),
		'customer' => array( 'name' => $name, 'email' => $email, 'phone' => $phone ),
		'booking'  => array(
			'service'  => $service,
			'employee' => $provider,
			'starts'   => minn_admin_jet_apb_iso( $row->slot ),
			'ends'     => minn_admin_jet_apb_iso( $row->slot_end ),
			'people'   => 1,
			'notes'    => $notes,
		),
		'sections' => array(
			array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => $who ),
			array( 'title' => __( 'Appointment', 'minn-admin' ), 'rows' => $when ),
		),
		'adminUrl' => minn_admin_jet_apb_admin_url(),
	);
}

/** Today on the slot's clock (a naive epoch: the site's wall clock read as UTC). */
function minn_admin_jet_apb_today() {
	return array(
		"slot\0from" => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local-epoch', 0 ) ),
		"slot\0to"   => array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( 'local-epoch', 1 ) ),
	);
}

/** Valid and still ahead: what upcoming and "next" mean. */
function minn_admin_jet_apb_upcoming() {
	return array( 'status' => minn_admin_jet_apb_status_set( 'valid' ), 'slot' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'local-epoch' ) ) );
}

minn_admin_resource( 'jet-appointments/appointments', function () {
	return array(
		'active'  => 'minn_admin_jet_apb_active',
		// Their contexts, one per endpoint they ship: the list, one
		// appointment, an update, a delete.
		'gates'   => array(
			'view'   => 'minn_admin_jet_apb_can_read',
			'detail' => 'minn_admin_jet_apb_can_detail',
			'status' => 'minn_admin_jet_apb_can_update',
			'delete' => 'minn_admin_jet_apb_can_delete',
		),
		'labels'  => array( 'not_found' => __( 'Appointment not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'jet_appointments',
			'key'     => 'ID',
			'joins'   => array(
				's'  => array( 'core' => 'posts', 'on' => array( 'ID' => 'e.service' ) ),
				'pr' => array( 'core' => 'posts', 'on' => array( 'ID' => 'e.provider' ) ),
			),
			'columns' => array( 'ID', 'status', 'slot', 'slot_end', 'user_id', 'user_name', 'user_email', 's.post_title' => 'service_title', 'pr.post_title' => 'provider_title' ),
			'detail'  => array( 'ID', 'status', 'slot', 'slot_end', 'user_id', 'user_name', 'user_email', 'order_id', 'type', 's.post_title' => 'service_title', 'pr.post_title' => 'provider_title' ),
		),
		'list'    => array(
			// Their status sets are filterable, so each view reads them live.
			'filter' => array(
				'param'   => 'range',
				'default' => 'upcoming',
				'values'  => array(
					'upcoming' => 'minn_admin_jet_apb_upcoming',
					'pending'  => function () {
						return array( 'status' => minn_admin_jet_apb_status_set( 'in_progress' ) );
					},
					'today'    => 'minn_admin_jet_apb_today',
					'canceled' => function () {
						return array( 'status' => minn_admin_jet_apb_status_set( 'invalid' ) );
					},
					'all'      => array(),
				),
				'order'   => array( 'upcoming' => 'ASC', 'pending' => 'ASC', 'today' => 'ASC' ),
			),
			'search' => array(
				'columns' => array( 'user_name', 'user_email', 's.post_title', 'pr.post_title' ),
				'also'    => function ( $term ) {
					return (int) $term > 0 ? array( 'ID' => (int) $term ) : null;
				},
			),
			'order'  => array( 'column' => 'slot', 'dir' => 'DESC' ),
			'row'    => function ( array $r ) {
				return array(
					'id'       => (int) $r['ID'],
					'customer' => minn_admin_jet_apb_customer_label( (object) $r ),
					'service'  => $r['service_title'] ? (string) $r['service_title'] : __( '(no service)', 'minn-admin' ),
					'status'   => (string) $r['status'],
					// The site's wall clock at that slot, with the offset that date had.
					'date'     => minn_admin_jet_apb_iso( $r['slot'] ),
					'provider' => $r['provider_title'] ? (string) $r['provider_title'] : '',
				);
			},
			'fields' => array( 'id' => 'int', 'customer' => 'text', 'service' => 'text', 'status' => 'text', 'date' => 'text', 'provider' => 'text' ),
		),
		'item'    => array( 'gate' => 'detail', 'view' => 'minn_admin_jet_apb_detail' ),
		'actions' => array(
			// Their model, the way their update endpoint uses it: the
			// constructor loads the stored row, save() moves the slot through
			// the excluded-dates table on invalid/valid flips, writes, and
			// fires jet-apb/db/update/appointments.
			'status' => array(
				'gate'   => 'status',
				'run'    => function ( $id, $row, $r ) {
					$status = sanitize_key( (string) $r->get_param( 'status' ) );
					if ( ! isset( minn_admin_jet_apb_statuses()[ $status ] ) ) {
						return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
					}
					try {
						( new \JET_APB\Resources\Appointment_Model( array( 'status' => $status ), $id ) )->save();
					} catch ( \Throwable $e ) {
						return new WP_Error( 'update_failed', __( 'JetAppointments could not update that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return $status;
				},
				'expect' => function ( $id, $fresh, $status ) {
					return is_array( $fresh ) && $status === (string) $fresh['status'];
				},
				'fail'   => __( 'JetAppointments could not update that appointment.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh, $status ) {
					$labels = minn_admin_jet_apb_statuses();
					/* translators: %s: appointment status label */
					return array( 'status' => $status, 'message' => sprintf( __( 'Appointment marked %s.', 'minn-admin' ), strtolower( (string) ( $labels[ $status ] ?? $status ) ) ) );
				},
			),
			// Their manager: removes the row, its meta, and the slot's
			// excluded-dates entry, the way their delete endpoint does.
			'delete' => array(
				'method' => 'DELETE',
				'gate'   => 'delete',
				'run'    => function ( $id ) {
					try {
						\JET_APB\Plugin::instance()->db->delete_appointment( $id );
					} catch ( \Throwable $e ) {
						return new WP_Error( 'delete_failed', __( 'JetAppointments could not delete that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return true;
				},
				'expect' => 'gone',
				'fail'   => __( 'JetAppointments could not delete that appointment.', 'minn-admin' ),
				'done'   => __( 'Appointment deleted.', 'minn-admin' ),
			),
		),
	);
} );

minn_admin_endpoint( 'jet-appointments/status', array(
	'active' => 'minn_admin_jet_apb_active',
	'gate'   => 'minn_admin_jet_apb_can_read',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open JetAppointments ↗', 'minn-admin' ), 'href' => minn_admin_jet_apb_admin_url() ) );
		if ( ! minn_admin_jet_apb_has_tables() ) {
			return array(
				'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No appointments yet', 'minn-admin' ) ) ),
				'actions' => $open,
			);
		}
		$id   = 'jet-appointments/appointments';
		$next = Minn_Admin_Kernel::first( $id, 'slot', minn_admin_jet_apb_upcoming() );
		return array(
			'rows'    => array(
				array( 'label' => __( 'Today', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel::count( $id, minn_admin_jet_apb_today() ) ) ),
				array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel::count( $id, array( 'status' => minn_admin_jet_apb_status_set( 'in_progress' ) ) ) ) ),
				array(
					'label' => __( 'Next', 'minn-admin' ),
					'value' => $next ? minn_admin_jet_apb_slot_label( $next ) : '—',
					'hint'  => $next ? '' : __( 'Nothing upcoming', 'minn-admin' ),
				),
			),
			// The next 14 site-local days, starting today: in progress apart
			// from the rest, cancelled left out.
			'chart'   => array(
				'title'     => __( 'Next 14 days', 'minn-admin' ),
				'primary'   => __( 'Booked', 'minn-admin' ),
				'secondary' => __( 'Pending', 'minn-admin' ),
				'points'    => array_values( Minn_Admin_Kernel::days( $id, array(
					'column'    => 'slot',
					'clock'     => 'local-epoch',
					'ahead'     => true,
					'where'     => array( 'status' => array( 'not' => minn_admin_jet_apb_status_set( 'invalid' ) ) ),
					'split'     => 'status',
					'secondary' => minn_admin_jet_apb_status_set( 'in_progress' ),
				) ) ),
			),
			'actions' => $open,
		);
	},
) );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_jet_apb_active() || ! minn_admin_jet_apb_can_read() ) {
		return $surfaces;
	}
	$res     = 'jet-appointments/appointments';
	$actions = array();
	$bulk    = array();
	if ( minn_admin_jet_apb_can_update() ) {
		$cancel = __( 'Cancel this appointment? JetAppointments runs its own workflows and notifications for the change.', 'minn-admin' );
		foreach ( minn_admin_jet_apb_status_set( 'in_progress' ) as $st ) {
			$actions[] = Minn_Admin_Kernel::ui( $res, 'status', array( 'label' => __( 'Mark completed', 'minn-admin' ), 'body' => array( 'status' => 'completed' ), 'key' => 'status', 'when' => $st ) );
		}
		foreach ( minn_admin_jet_apb_status_set( 'valid' ) as $st ) {
			$actions[] = Minn_Admin_Kernel::ui( $res, 'status', array( 'label' => __( 'Cancel', 'minn-admin' ), 'body' => array( 'status' => 'cancelled' ), 'confirm' => $cancel, 'danger' => true, 'key' => 'status', 'when' => $st ) );
		}
		$bulk = array(
			Minn_Admin_Kernel::ui( $res, 'status', array( 'label' => __( 'Mark completed', 'minn-admin' ), 'body' => array( 'status' => 'completed' ) ) ),
			Minn_Admin_Kernel::ui( $res, 'status', array( 'label' => __( 'Cancel', 'minn-admin' ), 'body' => array( 'status' => 'cancelled' ), 'danger' => true ) ),
		);
	}
	if ( minn_admin_jet_apb_can_delete() ) {
		$actions[] = Minn_Admin_Kernel::ui( $res, 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => __( 'Delete this appointment permanently? JetAppointments has no trash.', 'minn-admin' ), 'danger' => true ) );
	}
	$actions[] = array(
		'label' => __( 'Open in JetAppointments ↗', 'minn-admin' ),
		'href'  => minn_admin_jet_apb_admin_url(),
	);
	$surfaces['jet-appointments'] = array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'commerce',
		'sub'        => 'JetAppointments',
		'plugin'     => 'jet-appointments-booking',
		'icon'       => 'calendar',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/jet-appointments/status' ),
		'collection' => Minn_Admin_Kernel::collection( $res, array(
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
				'sectionsRoute' => 'minn-admin/v1/jet-appointments/appointments/{id}',
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		) ),
	);
	return $surfaces;
} );
