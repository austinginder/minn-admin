<?php
/**
 * Bundled adapter: Stream.
 *
 * Current Stream (4.x) ships no REST routes for records — they live in
 * {prefix}stream (+ {prefix}stream_meta), with a human-readable summary
 * column, so the shim is a read-only, prefix-scoped SELECT. Visibility uses
 * Stream's own view_stream capability.
 *
 * Status card (v0.16 Axis A): 24h / 7d / all-time + top connector, scoped
 * to the current blog_id.
 *
 * last-sweep: 2026-07-15
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_stream_can() {
	// Defer entirely to Stream. view_stream is granted dynamically from its
	// general_role_access setting, so an operator who removes administrator
	// from Role Access means it — a manage_options backstop would override
	// that choice, and this must never become one.
	$cap = apply_filters( 'wp_stream_view_cap', 'view_stream' );
	if ( current_user_can( $cap ) ) {
		return true;
	}
	// Stream grants view_stream through a user_has_cap filter that its Admin
	// class registers, and Admin never loads in a REST request. Stream ships
	// a REST-side equivalent in its Abilities class, but registers it inside
	// a method that returns early unless the Abilities API integration is
	// switched on — an unrelated, default-OFF feature flag. Verified from
	// inside a real REST request on a default install: role_access is
	// ["administrator"], the caller IS an administrator, no Stream callback
	// is on user_has_cap, and view_stream comes back false. So on any site
	// that has not opted into that flag, nobody can read Stream over REST.
	//
	// Falling back to Stream's OWN rule keeps the deferral honest: read
	// their Role Access setting and match the caller's roles exactly as
	// their filter does. Remove administrator from Role Access and this
	// returns false for administrators, which is the whole point.
	if ( 'view_stream' !== $cap ) {
		return false; // A site that renamed the cap is telling us to use it.
	}
	try {
		$plugin = function_exists( 'wp_stream_get_instance' ) ? wp_stream_get_instance() : null;
		if ( ! $plugin || ! isset( $plugin->settings ) || ! method_exists( $plugin->settings, 'get_setting_value' ) ) {
			return false;
		}
		// Their own reader, so the network-level option is consulted on a
		// network-activated multisite.
		$role_access = (array) $plugin->settings->get_setting_value( 'general_role_access', array() );
		if ( ! $role_access ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		global $wp_roles;
		$roles_obj = isset( $wp_roles ) ? $wp_roles : new WP_Roles();
		$roles     = array_unique( array_merge(
			(array) $user->roles,
			array_filter( array_keys( (array) $user->caps ), array( $roles_obj, 'is_role' ) )
		) );
		foreach ( $roles as $role ) {
			if ( in_array( $role, $role_access, true ) ) {
				return true;
			}
		}
	} catch ( \Throwable $e ) {
		return false;
	}
	return false;
}

function minn_admin_stream_admin_url() {
	return admin_url( 'admin.php?page=wp_stream' );
}

/**
 * Status-card model for Stream (created is GMT datetime).
 *
 * @return array{rows:array,actions:array}
 */
function minn_admin_stream_status_model() {
	global $wpdb;
	$table = $wpdb->base_prefix . 'stream';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array(
			'rows'    => array( array( 'label' => __( 'Events', 'minn-admin' ), 'value' => '—', 'hint' => __( 'Stream table not found', 'minn-admin' ) ) ),
			'actions' => array( array( 'label' => __( 'Open Stream ↗', 'minn-admin' ), 'href' => minn_admin_stream_admin_url() ) ),
		);
	}
	$blog      = get_current_blog_id();
	$since_24h = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	$since_7d  = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
	$total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d", $blog ) );
	$day       = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND created >= %s",
		$blog,
		$since_24h
	) );
	$week = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND created >= %s",
		$blog,
		$since_7d
	) );
	$last = $wpdb->get_var( $wpdb->prepare(
		"SELECT created FROM {$table} WHERE blog_id = %d ORDER BY ID DESC LIMIT 1",
		$blog
	) );
	$top = $wpdb->get_row( $wpdb->prepare(
		"SELECT connector, COUNT(*) AS c FROM {$table}
		 WHERE blog_id = %d AND created >= %s AND connector != ''
		 GROUP BY connector ORDER BY c DESC LIMIT 1",
		$blog,
		$since_7d
	) );
	// phpcs:enable

	$last_label = '—';
	if ( $last ) {
		$ts = strtotime( $last . ' UTC' );
		if ( $ts ) {
			$last_label = sprintf(
				/* translators: %s: human-readable time since the last event. */
				__( '%s ago', 'minn-admin' ),
				human_time_diff( $ts, time() )
			);
		}
	}
	$top_label = '—';
	if ( $top && ! empty( $top->connector ) ) {
		$top_label = ucwords( str_replace( array( '-', '_' ), ' ', (string) $top->connector ) )
			. ' (' . number_format_i18n( (int) $top->c ) . ')';
	}

	return array(
		'rows'    => array(
			array(
				'label' => __( 'Events (24h)', 'minn-admin' ),
				'value' => number_format_i18n( $day ),
				/* translators: %s: number of events logged in the last 7 days. */
				'hint'  => sprintf( __( '%s in the last 7 days', 'minn-admin' ), number_format_i18n( $week ) ),
			),
			array(
				'label' => __( 'Events all-time', 'minn-admin' ),
				'value' => number_format_i18n( $total ),
			),
			array(
				'label' => __( 'Last event', 'minn-admin' ),
				'value' => $last_label,
			),
			array(
				'label' => __( 'Top source (7d)', 'minn-admin' ),
				'value' => $top_label,
			),
		),
		'actions' => array(
			array( 'label' => __( 'Open Stream ↗', 'minn-admin' ), 'href' => minn_admin_stream_admin_url() ),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! function_exists( 'wp_stream_get_instance' ) ) {
		return $surfaces;
	}
	if ( ! minn_admin_stream_can() ) {
		return $surfaces;
	}

	$surfaces['stream'] = array(
		'label'      => __( 'Activity Log', 'minn-admin' ),
		'family'     => 'activity-log',
		'sub'        => 'Stream',
		'icon'       => 'clock',
		'cap'        => 'read', // real gating above + in the shim.
		'status'     => array( 'route' => 'minn-admin/v1/stream/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/stream/records',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			// Connector is Stream's first-class source dimension (posts/users/…).
			'tabs'      => array(
				'route'    => 'minn-admin/v1/stream/connectors',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'connector',
				'allLabel' => __( 'All sources', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'who', 'label' => __( 'Who', 'minn-admin' ) ),
				array( 'key' => 'connector', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'skip' => array( 'summary' ),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'wp_stream_get_instance' ) ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/stream/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_stream_can',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_stream_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/stream/connectors', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_stream_can',
		'callback'            => function () {
			global $wpdb;
			$table = $wpdb->base_prefix . 'stream';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cols = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT connector FROM {$table} WHERE blog_id = %d AND connector != '' ORDER BY connector ASC",
				get_current_blog_id()
			) );
			$out = array();
			foreach ( (array) $cols as $c ) {
				$out[] = array(
					'id'    => (string) $c,
					'title' => ucwords( str_replace( array( '-', '_' ), ' ', (string) $c ) ),
				);
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/stream/records', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_stream_can',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->base_prefix . 'stream';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$where    = array( 'blog_id = %d' );
			$args     = array( get_current_blog_id() );

			if ( $request['connector'] ) {
				$where[] = 'connector = %s';
				$args[]  = sanitize_key( (string) $request['connector'] );
			}
			if ( $request['search'] ) {
				$like    = '%' . $wpdb->esc_like( $request['search'] ) . '%';
				$where[] = '(summary LIKE %s OR connector LIKE %s OR context LIKE %s)';
				array_push( $args, $like, $like, $like );
			}

			$where_sql = implode( ' AND ', $where );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-derived; WHERE is placeholder-built.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) );
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, summary, connector, context, action, user_id, ip, created
				 FROM {$table} WHERE {$where_sql} ORDER BY created DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$users = array();
			$items = array();
			foreach ( (array) $rows as $r ) {
				$uid = (int) $r->user_id;
				if ( $uid && ! isset( $users[ $uid ] ) ) {
					$u             = get_userdata( $uid );
					$users[ $uid ] = $u ? $u->user_login : '#' . $uid;
				}
				$items[] = array(
					'id'        => (int) $r->ID,
					'summary'   => $r->summary,
					'who'       => $uid ? $users[ $uid ] : 'Guest',
					'connector' => $r->connector,
					'context'   => $r->context . ( $r->action ? ' / ' . $r->action : '' ),
					'ip'        => $r->ip,
					// Stream stores GMT datetimes — append Z so parseWpDate
					// does not treat them as site-local (EDT "in 4h" bug).
					'date'      => rtrim( str_replace( ' ', 'T', (string) $r->created ), 'Z' ) . 'Z',
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );
} );
