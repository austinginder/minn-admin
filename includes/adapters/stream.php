<?php
/**
 * Bundled adapter: Stream.
 *
 * Current Stream (4.x) ships no REST routes for records — they live in
 * {prefix}stream (+ {prefix}stream_meta), with a human-readable summary
 * column, so the shim is a read-only, prefix-scoped SELECT. Visibility uses
 * Stream's own view_stream capability.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! function_exists( 'wp_stream_get_instance' ) ) {
		return $surfaces;
	}
	if ( ! current_user_can( 'view_stream' ) && ! current_user_can( 'manage_options' ) ) {
		return $surfaces;
	}

	$surfaces['stream'] = array(
		'label'      => 'Activity Log',
		'family'     => 'activity-log',
		'sub'        => 'Stream',
		'icon'       => 'clock',
		'cap'        => 'read', // real gating above + in the shim.
		'collection' => array(
			'route'     => 'minn-admin/v1/stream/records',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
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

	register_rest_route( 'minn-admin/v1', '/stream/records', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'view_stream' ) || current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'stream';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$where    = array( 'blog_id = %d' );
			$args     = array( get_current_blog_id() );

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
