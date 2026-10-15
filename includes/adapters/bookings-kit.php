<?php
/**
 * The bookings family's kit: what every booking adapter would otherwise
 * build again. A list's When views and its filter, the row and its fields,
 * an appointment's detail, the status move, the status card (today's, the
 * pending, the next and the next fourteen days, counted through the same
 * views the list filters by) and the surface (the When filter, the
 * Customer, Service, Status and When columns, the status moves, the
 * vendor's own screen).
 *
 * Adapter tier: presentation and vendor-neutral formatting only. Routes,
 * gates and queries stay the kernel's; each adapter passes its vendor's
 * values (where a booking lives, who may move it, how its vendor writes a
 * status, the vendor's own words).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** A person's name from its parts, or the whole name the vendor stores, or "(no name)". */
function minn_admin_booking_name( $first, $last, $full = '' ) {
	$name = trim( trim( (string) $first ) . ' ' . trim( (string) $last ) );
	if ( '' === $name ) {
		$name = trim( (string) $full );
	}
	return '' !== $name ? $name : __( '(no name)', 'minn-admin' );
}

/**
 * The Today view on a start column's clock: today's local midnight up to
 * tomorrow's, read when the request runs.
 *
 * @param string $column Start column ("bookingStart", "a.start_date").
 * @param string $clock  Its clock (Minn_Admin_Kernel::at()).
 * @return Closure
 */
function minn_admin_booking_today( $column, $clock ) {
	return function () use ( $column, $clock ) {
		return array(
			"{$column}\0from" => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( $clock, 0 ) ),
			"{$column}\0to"   => array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( $clock, 1 ) ),
		);
	};
}

/**
 * The list's When filter over its views, the ones that look ahead soonest
 * first.
 *
 * @param array $views upcoming, pending, today and canceled: conditions, or
 *                     a function answering them when the request runs.
 * @return array
 */
function minn_admin_booking_filter( array $views ) {
	return array(
		'param'   => 'range',
		'default' => 'upcoming',
		'values'  => $views + array( 'all' => array() ),
		'order'   => array( 'upcoming' => 'ASC', 'pending' => 'ASC', 'today' => 'ASC' ),
	);
}

/**
 * A list row: the customer with their email, the service, the vendor's own
 * status (the actions' `when` reads it), the start and who serves it.
 *
 * @param int|string $id       Row id.
 * @param string     $customer Customer's name, or the whole label when $email is empty.
 * @param string     $email    Customer's email.
 * @param string     $service  Service name, empty for none.
 * @param string     $status   Vendor's status.
 * @param mixed      $date     Start, as the fields encode it.
 * @param string     $provider Who serves it.
 * @return array
 */
function minn_admin_booking_row( $id, $customer, $email, $service, $status, $date, $provider ) {
	$email = trim( (string) $email );
	return array(
		'id'       => (int) $id,
		'customer' => $email ? $customer . ' · ' . $email : $customer,
		'service'  => $service ? (string) $service : __( '(no service)', 'minn-admin' ),
		'status'   => (string) $status,
		'date'     => $date,
		'provider' => $provider,
	);
}

/**
 * The row's fields.
 *
 * @param string $stored The clock the start column keeps ('utc', 'site'),
 *                       or '' for a start the row already gives as text.
 * @return array
 */
function minn_admin_booking_fields( $stored ) {
	return array(
		'id'       => 'int',
		'customer' => 'text',
		'service'  => 'text',
		'status'   => 'text',
		'date'     => '' === $stored ? 'text' : array( 'type' => 'datetime', 'stored' => $stored, 'wire' => 'z' ),
		'provider' => 'text',
	);
}

/**
 * An appointment's detail as the booking page reads it: the customer, the
 * slot, and the Customer and appointment sections, the vendor's own rows
 * after Status and its own sections after those.
 *
 * @param array $b noun (the appointment section's title and the title
 *                 without a service), name, email, phone, people, service,
 *                 employee ('' for none), starts and ends (ISO), status,
 *                 rows, sections, booking (keys before notes), notes and
 *                 admin (the vendor's screen).
 * @return array
 */
function minn_admin_booking_detail( array $b ) {
	$b      += array( 'rows' => array(), 'sections' => array(), 'booking' => array() );
	$email   = $b['email'] ? (string) $b['email'] : '';
	$phone   = $b['phone'] ? (string) $b['phone'] : '';
	$service = $b['service'] ? (string) $b['service'] : '';
	$people  = max( 1, (int) $b['people'] );
	$who     = array(
		array( 'label' => __( 'Name', 'minn-admin' ), 'value' => $b['name'] ),
		array( 'label' => __( 'Email', 'minn-admin' ), 'value' => '' !== $email ? $email : '—' ),
		array( 'label' => __( 'Phone', 'minn-admin' ), 'value' => '' !== $phone ? $phone : '—' ),
		array( 'label' => __( 'People', 'minn-admin' ), 'value' => (string) $people ),
	);
	$when    = array(
		array( 'label' => __( 'Service', 'minn-admin' ), 'value' => '' !== $service ? $service : '—' ),
		array( 'label' => __( 'Employee', 'minn-admin' ), 'value' => '' !== (string) $b['employee'] ? $b['employee'] : '—' ),
		array( 'label' => __( 'Starts', 'minn-admin' ), 'value' => $b['starts'] ),
		array( 'label' => __( 'Ends', 'minn-admin' ), 'value' => $b['ends'] ),
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $b['status'] ),
	);
	return array(
		'kind'     => 'booking',
		'status'   => (string) $b['status'],
		'title'    => $service ? $service : $b['noun'],
		'customer' => array( 'name' => $b['name'], 'email' => $email, 'phone' => $phone ),
		'booking'  => array_merge(
			array( 'service' => $service, 'employee' => $b['employee'], 'starts' => $b['starts'], 'ends' => $b['ends'], 'people' => $people ),
			$b['booking'],
			array( 'notes' => $b['notes'] )
		),
		'sections' => array_merge(
			array(
				array( 'title' => __( 'Customer', 'minn-admin' ), 'rows' => $who ),
				array( 'title' => $b['noun'], 'rows' => array_merge( $when, $b['rows'] ) ),
			),
			$b['sections']
		),
		'adminUrl' => $b['admin'],
	);
}

/**
 * The status move every booking list declares: the status the request
 * names, refused unless the vendor knows it, written through the vendor's
 * own code, done only once the row reads back in it, and named in the reply
 * as the vendor names it.
 *
 * @param array $o known (the vendor's statuses, or a function answering
 *                 them), or labels (a function answering status => label,
 *                 the reply naming a status by its label), write
 *                 (callable( int $id, string $status ): the vendor's update,
 *                 a WP_Error when it refuses), fail (the 500 when the row
 *                 does not read back in the status), marked (the reply, %s
 *                 the status) and gate.
 * @return array
 */
function minn_admin_booking_move( array $o ) {
	$o += array( 'gate' => 'status' );
	return array(
		'gate'   => $o['gate'],
		'run'    => function ( $id, $row, $r ) use ( $o ) {
			$status = sanitize_key( (string) $r->get_param( 'status' ) );
			$known  = isset( $o['labels'] )
				? isset( call_user_func( $o['labels'] )[ $status ] )
				: in_array( $status, is_array( $o['known'] ) ? $o['known'] : (array) call_user_func( $o['known'] ), true );
			if ( ! $known ) {
				return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$out = call_user_func( $o['write'], $id, $status );
			return is_wp_error( $out ) ? $out : $status;
		},
		'expect' => function ( $id, $fresh, $status ) {
			return is_array( $fresh ) && $status === (string) $fresh['status'];
		},
		'fail'   => $o['fail'],
		'reply'  => function ( $id, $fresh, $status ) use ( $o ) {
			$word = isset( $o['labels'] ) ? strtolower( (string) ( call_user_func( $o['labels'] )[ $status ] ?? $status ) ) : str_replace( '_', ' ', $status );
			return array( 'status' => $status, 'message' => sprintf( $o['marked'], $word ) );
		},
	);
}

/**
 * A booking list's status card over its resource: today's, the pending and
 * the next, each counted through the list's own view, and the next fourteen
 * site days, booked and pending apart.
 *
 * @param string $res   Resource id.
 * @param array  $views The list's views (minn_admin_booking_filter()).
 * @param array  $o     ready (the vendor's tables are there), noun and empty
 *                      (the row and its hint while they are not), scope (a
 *                      function answering null while the reader may see
 *                      none, asked only once they are), next (the start
 *                      column), iso or label (a function giving its stored
 *                      value as ISO, shown as the site writes dates and
 *                      times, or as the text to show), today and soon (the
 *                      hints under Today and Next), chart (column, clock,
 *                      where, secondary) and open (array( label, href ): the
 *                      vendor's screen).
 * @return array
 */
function minn_admin_booking_card( $res, array $views, array $o ) {
	$o   += array( 'soon' => '' );
	$open = array( array( 'label' => $o['open'][0], 'href' => $o['open'][1] ) );
	if ( ! $o['ready'] ) {
		return array( 'rows' => array( array( 'label' => $o['noun'], 'value' => '—', 'hint' => $o['empty'] ) ), 'actions' => $open );
	}
	if ( isset( $o['scope'] ) && null === call_user_func( $o['scope'] ) ) {
		return array( 'rows' => array( array( 'label' => $o['noun'], 'value' => '—' ) ), 'actions' => $open );
	}
	// As the kernel reads a view: an array is conditions, never a callable.
	$view = function ( $v ) {
		return ! is_array( $v ) && is_callable( $v ) ? call_user_func( $v ) : $v;
	};
	$next = Minn_Admin_Kernel::first( $res, $o['next'], $view( $views['upcoming'] ) );
	if ( isset( $o['label'] ) ) {
		$text = $next ? call_user_func( $o['label'], $next ) : null;
	} else {
		$ts   = $next ? strtotime( call_user_func( $o['iso'], $next ) ) : false;
		$text = $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : null;
	}
	$today = array( 'label' => __( 'Today', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel::count( $res, $view( $views['today'] ) ) ) );
	return array(
		'rows'    => array(
			isset( $o['today'] ) ? $today + array( 'hint' => $o['today'] ) : $today,
			array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel::count( $res, $view( $views['pending'] ) ) ) ),
			array(
				'label' => __( 'Next', 'minn-admin' ),
				'value' => null !== $text ? $text : '—',
				'hint'  => null !== $text ? $o['soon'] : __( 'Nothing upcoming', 'minn-admin' ),
			),
		),
		'chart'   => array(
			'title'     => __( 'Next 14 days', 'minn-admin' ),
			'primary'   => __( 'Booked', 'minn-admin' ),
			'secondary' => __( 'Pending', 'minn-admin' ),
			'points'    => array_values( Minn_Admin_Kernel::days( $res, array_merge( array( 'ahead' => true, 'split' => 'status' ), $o['chart'] ) ) ),
		),
		'actions' => $open,
	);
}

/**
 * A booking list's surface over its kernel resource: the When filter, the
 * columns, the detail, the status moves and any further actions, then the
 * vendor's own screen.
 *
 * @param string $res Resource id ("amelia/appointments").
 * @param array  $o   sub, plugin, status (route under minn-admin/v1), noun
 *                    (the list's label), open (array( label, href ): the
 *                    vendor's screen), moves (array( label, to status, the
 *                    state or states it is offered in, confirm ) each, one
 *                    with a confirm being dangerous; the first two are bulk
 *                    actions too, never asking), actions (after the moves),
 *                    cap, service (the Service column's label), width (the
 *                    Status column's), when (the last column) and surface
 *                    (keys merged over it).
 * @return array
 */
function minn_admin_booking_surface( $res, array $o ) {
	$o   += array(
		'moves'   => array(),
		'actions' => array(),
		'cap'     => 'read',
		'service' => __( 'Service', 'minn-admin' ),
		'width'   => '110px',
		'when'    => array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
		'surface' => array(),
	);
	// ui() leaves out what is null: a move with no state is a bulk one.
	$move = function ( array $m, $from = null ) use ( $res ) {
		return Minn_Admin_Kernel::ui( $res, 'status', array(
			'label'   => $m[0],
			'body'    => array( 'status' => $m[1] ),
			'key'     => 'status',
			'when'    => $from,
			'confirm' => null === $from ? null : ( $m[3] ?? null ),
			'danger'  => isset( $m[3] ) ? true : null,
		) );
	};
	$actions = array();
	foreach ( $o['moves'] as $m ) {
		foreach ( (array) $m[2] as $from ) {
			$actions[] = $move( $m, $from );
		}
	}
	return array_merge( array(
		'label'      => __( 'Bookings', 'minn-admin' ),
		'family'     => 'bookings',
		'group'      => 'commerce',
		'sub'        => $o['sub'],
		'plugin'     => $o['plugin'],
		'icon'       => 'calendar',
		'cap'        => $o['cap'],
		'status'     => array( 'route' => 'minn-admin/v1/' . $o['status'] ),
		'collection' => Minn_Admin_Kernel::collection( $res, array(
			'viewLabel' => $o['noun'],
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
				array( 'key' => 'service', 'label' => $o['service'] ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => $o['width'] ),
				$o['when'],
			),
			'detail'    => array( 'sectionsRoute' => 'minn-admin/v1/' . $res . '/{id}' ),
			'actions'   => array_merge( $actions, $o['actions'], array( array( 'label' => $o['open'][0], 'href' => $o['open'][1] ) ) ),
			'bulk'      => array_map( $move, array_slice( $o['moves'], 0, 2 ) ),
		) ),
	), $o['surface'] );
}
