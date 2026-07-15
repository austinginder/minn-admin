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
	return current_user_can( 'view_stream' ) || current_user_can( 'manage_options' );
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
	$table = $wpdb->prefix . 'stream';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array(
			'rows'    => array( array( 'label' => 'Events', 'value' => '—', 'hint' => 'Stream table not found' ) ),
			'actions' => array( array( 'label' => 'Open Stream ↗', 'href' => minn_admin_stream_admin_url() ) ),
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
			$last_label = human_time_diff( $ts, time() ) . ' ago';
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
				'label' => 'Events (24h)',
				'value' => number_format_i18n( $day ),
				'hint'  => number_format_i18n( $week ) . ' in the last 7 days',
			),
			array(
				'label' => 'Events all-time',
				'value' => number_format_i18n( $total ),
			),
			array(
				'label' => 'Last event',
				'value' => $last_label,
			),
			array(
				'label' => 'Top source (7d)',
				'value' => $top_label,
			),
		),
		'actions' => array(
			array( 'label' => 'Open Stream ↗', 'href' => minn_admin_stream_admin_url() ),
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
		'label'      => 'Activity Log',
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
				'allLabel' => 'All sources',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'who', 'label' => 'Who' ),
				array( 'key' => 'connector', 'label' => 'Source', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
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
			$table = $wpdb->prefix . 'stream';
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
			$table    = $wpdb->prefix . 'stream';
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
