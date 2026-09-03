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
 * raw value: WP-local naive timestamps, never UTC, so they are shifted by
 * the site offset before an ISO Z leaves this adapter. Statuses are theirs
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
	global $wpdb;
	$t = minn_admin_jet_apb_table();
	return 0 === strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ), $t );
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

/** WP-local naive epoch (their date_i18n convention) → ISO-8601 Z. */
function minn_admin_jet_apb_iso( $naive ) {
	$naive = (int) $naive;
	if ( $naive <= 0 ) {
		return '';
	}
	$offset = (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	return gmdate( 'Y-m-d\TH:i:s\Z', $naive - $offset );
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

function minn_admin_jet_apb_now_naive() {
	return time() + (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
}

function minn_admin_jet_apb_day_bounds() {
	$start = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 UTC' );
	return array( $start, $start + DAY_IN_SECONDS - 1 );
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

function minn_admin_jet_apb_from() {
	global $wpdb;
	return minn_admin_jet_apb_table() . " a
		LEFT JOIN {$wpdb->posts} s ON s.ID = a.service
		LEFT JOIN {$wpdb->posts} pr ON pr.ID = a.provider";
}

function minn_admin_jet_apb_where( $view, $search ) {
	global $wpdb;
	$where  = array( '1=1' );
	$params = array();
	$in     = function ( $list ) use ( &$params ) {
		$list = array_values( array_filter( array_map( 'strval', (array) $list ) ) );
		if ( ! $list ) {
			return "a.status IN ('')";
		}
		$params = array_merge( $params, $list );
		return 'a.status IN (' . implode( ',', array_fill( 0, count( $list ), '%s' ) ) . ')';
	};
	list( $day_start, $day_end ) = minn_admin_jet_apb_day_bounds();
	if ( 'pending' === $view ) {
		$where[] = $in( minn_admin_jet_apb_status_set( 'in_progress' ) );
	} elseif ( 'canceled' === $view ) {
		$where[] = $in( minn_admin_jet_apb_status_set( 'invalid' ) );
	} elseif ( 'today' === $view ) {
		$where[]  = 'a.slot BETWEEN %d AND %d';
		$params[] = $day_start;
		$params[] = $day_end;
	} elseif ( 'all' === $view ) {
		// no extra gate
	} else {
		$where[]  = $in( minn_admin_jet_apb_status_set( 'valid' ) );
		$where[]  = 'a.slot >= %d';
		$params[] = minn_admin_jet_apb_now_naive();
	}
	if ( '' !== $search ) {
		$like     = '%' . $wpdb->esc_like( $search ) . '%';
		$where[]  = '(a.user_name LIKE %s OR a.user_email LIKE %s OR s.post_title LIKE %s OR pr.post_title LIKE %s OR a.ID = %d)';
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = $like;
		$params[] = (int) $search;
	}
	return array( 'WHERE ' . implode( ' AND ', $where ), $params );
}

function minn_admin_jet_apb_row( $id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return $wpdb->get_row( $wpdb->prepare( 'SELECT a.*, s.post_title AS service_title, pr.post_title AS provider_title FROM ' . minn_admin_jet_apb_from() . ' WHERE a.ID = %d', (int) $id ) );
}

/** Meta rows for one appointment, key => value, through their meta table. */
function minn_admin_jet_apb_meta( $id ) {
	global $wpdb;
	$t = $wpdb->prefix . 'jet_appointments_meta';
	if ( 0 !== strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ), $t ) ) {
		return array();
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$t} WHERE appointment_id = %d", (int) $id ) );
	$out  = array();
	foreach ( $rows ? $rows : array() as $r ) {
		$v = (string) $r->meta_value;
		// Serialized values stay opaque: shown as stored, never unserialized.
		if ( is_serialized( $v ) ) {
			continue;
		}
		$out[ (string) $r->meta_key ] = $v;
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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_jet_apb_active() || ! minn_admin_jet_apb_can_read() ) {
		return $surfaces;
	}
	$route   = 'minn-admin/v1/jet-appointments/appointments/{id}/status';
	$actions = array();
	$bulk    = array();
	if ( minn_admin_jet_apb_can_update() ) {
		foreach ( minn_admin_jet_apb_status_set( 'in_progress' ) as $st ) {
			$actions[] = array(
				'label'  => __( 'Mark completed', 'minn-admin' ),
				'method' => 'POST',
				'route'  => $route,
				'body'   => array( 'status' => 'completed' ),
				'when'   => array( 'key' => 'status', 'equals' => $st ),
			);
		}
		foreach ( minn_admin_jet_apb_status_set( 'valid' ) as $st ) {
			$actions[] = array(
				'label'   => __( 'Cancel', 'minn-admin' ),
				'method'  => 'POST',
				'route'   => $route,
				'body'    => array( 'status' => 'cancelled' ),
				'confirm' => __( 'Cancel this appointment? JetAppointments runs its own workflows and notifications for the change.', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'status', 'equals' => $st ),
			);
		}
		$bulk = array(
			array( 'label' => __( 'Mark completed', 'minn-admin' ), 'method' => 'POST', 'route' => $route, 'body' => array( 'status' => 'completed' ) ),
			array( 'label' => __( 'Cancel', 'minn-admin' ), 'method' => 'POST', 'route' => $route, 'body' => array( 'status' => 'cancelled' ), 'danger' => true ),
		);
	}
	if ( minn_admin_jet_apb_can_delete() ) {
		$actions[] = array(
			'label'   => __( 'Delete', 'minn-admin' ),
			'method'  => 'DELETE',
			'route'   => 'minn-admin/v1/jet-appointments/appointments/{id}',
			'confirm' => __( 'Delete this appointment permanently? JetAppointments has no trash.', 'minn-admin' ),
			'danger'  => true,
		);
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
		'icon'       => 'calendar',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/jet-appointments/status' ),
		'collection' => array(
			'viewLabel' => __( 'Appointments', 'minn-admin' ),
			'route'     => 'minn-admin/v1/jet-appointments/appointments',
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
				'sectionsRoute' => 'minn-admin/v1/jet-appointments/appointments/{id}',
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_apb_active() ) {
		return;
	}
	$perm = 'minn_admin_jet_apb_can_read';

	register_rest_route( 'minn-admin/v1', '/jet-appointments/appointments', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_jet_apb_has_tables() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$view     = sanitize_key( (string) $request->get_param( 'range' ) );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
			list( $where_sql, $params ) = minn_admin_jet_apb_where( $view ? $view : 'upcoming', $search );
			$order = in_array( $view, array( 'canceled', 'all' ), true ) ? 'a.slot DESC, a.ID DESC' : 'a.slot ASC, a.ID ASC';
			$from  = minn_admin_jet_apb_from();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = "SELECT COUNT(*) FROM {$from} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT a.ID, a.status, a.slot, a.slot_end, a.user_id, a.user_name, a.user_email,
					s.post_title AS service_title, pr.post_title AS provider_title
				FROM {$from} {$where_sql}
				ORDER BY {$order}
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array_map( function ( $r ) {
				return array(
					'id'       => (int) $r->ID,
					'customer' => minn_admin_jet_apb_customer_label( $r ),
					'service'  => $r->service_title ? (string) $r->service_title : __( '(no service)', 'minn-admin' ),
					'status'   => (string) $r->status,
					'date'     => minn_admin_jet_apb_iso( $r->slot ),
					'provider' => $r->provider_title ? (string) $r->provider_title : '',
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-appointments/appointments/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_jet_apb_can_detail',
			'callback'            => function ( WP_REST_Request $request ) {
				$row = minn_admin_jet_apb_has_tables() ? minn_admin_jet_apb_row( (int) $request['id'] ) : null;
				if ( ! $row ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// The meta table is their second endpoint, with its own
				// context. A site that granted the detail but not the meta
				// gets the appointment without the form answers or the phone.
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
				$who = array(
					array( 'label' => __( 'Name', 'minn-admin' ), 'value' => '' !== $name ? $name : '—' ),
					array( 'label' => __( 'Email', 'minn-admin' ), 'value' => '' !== $email ? $email : '—' ),
					array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => '' !== $phone ? $phone : '—' ),
				);
				$notes = '';
				// The keys their own screen displays, when it can tell us.
				// Falling back to the blocklist keeps older versions working,
				// with the operational plumbing named explicitly so it is not
				// printed either way.
				$labels = minn_admin_jet_apb_display_fields();
				$hidden = array( 'phone', 'user_phone', 'tel', 'telephone', 'user_timezone', 'user_local_time', 'user_local_date' );
				foreach ( $meta as $k => $v ) {
					if ( in_array( $k, $hidden, true ) ) {
						continue;
					}
					// The integrations write their bookkeeping onto the same
					// row as the guest's answers: a Google Calendar id, the raw
					// text of a failed API call, a Zoom meeting id, workflow
					// schedule ids. Their own screen shows none of it, and it
					// is not what somebody opens a booking to read.
					if ( preg_match( '/^(gcal|zoom|_schedule_id)/', $k ) ) {
						continue;
					}
					$v = trim( (string) $v );
					if ( '' === $v ) {
						continue;
					}
					$label = ( is_array( $labels ) && ! empty( $labels[ $k ] ) )
						? (string) $labels[ $k ]
						: ucwords( str_replace( array( '_', '-' ), ' ', $k ) );
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
				return rest_ensure_response( array(
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
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => 'minn_admin_jet_apb_can_delete',
			'callback'            => function ( WP_REST_Request $request ) {
				$id = (int) Minn_Admin::path_param( $request );
				if ( ! minn_admin_jet_apb_row( $id ) ) {
					return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				try {
					// Their manager: removes the row, its meta, and the slot's
					// excluded-dates entry, the way their delete endpoint does.
					\JET_APB\Plugin::instance()->db->delete_appointment( $id );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'delete_failed', __( 'JetAppointments could not delete that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				if ( minn_admin_jet_apb_row( $id ) ) {
					return new WP_Error( 'delete_failed', __( 'JetAppointments could not delete that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Appointment deleted.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-appointments/appointments/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_jet_apb_can_update',
		'callback'            => function ( WP_REST_Request $request ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			$ok     = minn_admin_jet_apb_statuses();
			if ( ! isset( $ok[ $status ] ) ) {
				return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$id = (int) $request['id'];
			if ( ! minn_admin_jet_apb_row( $id ) ) {
				return new WP_Error( 'not_found', __( 'Appointment not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			try {
				// Their model, the way their update endpoint uses it: the
				// constructor loads the stored row, save() moves the slot
				// through the excluded-dates table on invalid/valid flips,
				// writes, and fires jet-apb/db/update/appointments.
				$model = new \JET_APB\Resources\Appointment_Model( array( 'status' => $status ), $id );
				$model->save();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'update_failed', __( 'JetAppointments could not update that appointment.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$after = minn_admin_jet_apb_row( $id );
			if ( ! $after || (string) $after->status !== $status ) {
				return new WP_Error( 'update_failed', __( 'JetAppointments could not update that appointment.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'status'  => $status,
				/* translators: %s: appointment status label */
				'message' => sprintf( __( 'Appointment marked %s.', 'minn-admin' ), strtolower( (string) $ok[ $status ] ) ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-appointments/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$open = array( array( 'label' => __( 'Open JetAppointments ↗', 'minn-admin' ), 'href' => minn_admin_jet_apb_admin_url() ) );
			if ( ! minn_admin_jet_apb_has_tables() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => __( 'Appointments', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No appointments yet', 'minn-admin' ) ) ),
					'actions' => $open,
				) );
			}
			global $wpdb;
			$from = minn_admin_jet_apb_from();
			list( $today_sql, $today_p )     = minn_admin_jet_apb_where( 'today', '' );
			list( $pending_sql, $pending_p ) = minn_admin_jet_apb_where( 'pending', '' );
			list( $next_sql, $next_p )       = minn_admin_jet_apb_where( 'upcoming', '' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$today_n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} {$today_sql}", $today_p ) );
			$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$from} {$pending_sql}", $pending_p ) );
			$next    = $wpdb->get_var( $wpdb->prepare( "SELECT a.slot FROM {$from} {$next_sql} ORDER BY a.slot ASC LIMIT 1", $next_p ) );
			// phpcs:enable
			// Next 14 site-local days, starting today: the shape of the week ahead.
			$chart_days = array();
			for ( $i = 0; $i < 14; $i++ ) {
				$d                = wp_date( 'Y-m-d', time() + $i * DAY_IN_SECONDS );
				$chart_days[ $d ] = array( 'label' => $d, 'value' => 0, 'secondary' => 0 );
			}
			// slot is a naive epoch on the site's own clock read as UTC (their
			// convention), so gmdate() gives the site day back.
			list( $chart_from ) = minn_admin_jet_apb_day_bounds();
			$chart_pending = array_map( 'strval', minn_admin_jet_apb_status_set( 'in_progress' ) );
			$chart_invalid = array_map( 'strval', minn_admin_jet_apb_status_set( 'invalid' ) );
			list( $all_sql, $all_p ) = minn_admin_jet_apb_where( 'all', '' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$chart_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT a.slot, a.status FROM {$from} {$all_sql} AND a.slot >= %d AND a.slot < %d",
				array_merge( $all_p, array( $chart_from, $chart_from + 14 * DAY_IN_SECONDS ) )
			) );
			// phpcs:enable
			foreach ( (array) $chart_rows as $cr ) {
				if ( in_array( (string) $cr->status, $chart_invalid, true ) ) {
					continue;
				}
				$d = gmdate( 'Y-m-d', (int) $cr->slot );
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
					array( 'label' => __( 'Today', 'minn-admin' ), 'value' => number_format_i18n( $today_n ) ),
					array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( $pending ) ),
					array(
						'label' => __( 'Next', 'minn-admin' ),
						'value' => $next ? minn_admin_jet_apb_slot_label( $next ) : '—',
						'hint'  => $next ? '' : __( 'Nothing upcoming', 'minn-admin' ),
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
