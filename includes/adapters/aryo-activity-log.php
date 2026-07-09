<?php
/**
 * Bundled adapter: Activity Log (aryo-activity-log).
 *
 * No REST API upstream — everything lives in one flat, plain-column table
 * ({prefix}aryo_activity_log), so the shim is a read-only, prefix-scoped
 * SELECT. Visibility mirrors the plugin's own menu gate: the dedicated
 * view_all_aryo_activity_log cap when granted, else its filterable
 * edit_pages default.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_aryo_can_view() {
	return current_user_can( 'view_all_aryo_activity_log' )
		|| current_user_can( apply_filters( 'aal_menu_page_capability', 'edit_pages' ) );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! class_exists( 'AAL_Main' ) ) {
		return $surfaces;
	}
	if ( ! minn_admin_aryo_can_view() ) {
		return $surfaces;
	}

	$surfaces['aryo-activity-log'] = array(
		'label'      => 'Activity Log',
		'family'     => 'activity-log',
		// Plugin product name is just "Activity Log"; use Aryo so the
		// family switcher can tell it apart from Simple History / Stream.
		'sub'        => 'Aryo',
		'icon'       => 'clock',
		'cap'        => 'read', // real gating above + in the shim.
		'collection' => array(
			'route'     => 'minn-admin/v1/aryo/events',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'message', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'who', 'label' => 'Who' ),
				array( 'key' => 'action', 'label' => 'Action', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'skip' => array( 'message' ),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! class_exists( 'AAL_Main' ) ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/aryo/events', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_aryo_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'aryo_activity_log';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$where    = '1=1';
			$args     = array();

			if ( $request['search'] ) {
				$like  = '%' . $wpdb->esc_like( $request['search'] ) . '%';
				$where = '(object_name LIKE %s OR action LIKE %s OR object_type LIKE %s)';
				$args  = array( $like, $like, $like );
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-derived; WHERE is placeholder-built.
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT histid, action, object_type, object_subtype, object_name, user_id, hist_ip, hist_time
				 FROM {$table} WHERE {$where} ORDER BY hist_time DESC LIMIT %d OFFSET %d",
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
					'id'      => (int) $r->histid,
					'message' => trim( $r->object_type . ( $r->object_name ? ': ' . $r->object_name : '' ) ),
					'who'     => $uid ? $users[ $uid ] : 'Guest',
					'action'  => $r->action,
					'type'    => $r->object_type . ( $r->object_subtype ? ' / ' . $r->object_subtype : '' ),
					'ip'      => $r->hist_ip,
					// Aryo stores current_time('timestamp') — WP's LOCAL epoch,
					// not UTC — so shift back before emitting an ISO-UTC shape.
					// UTC with Z (hist_time is WP local epoch — shift back first).
					'date'    => gmdate( 'Y-m-d\TH:i:s\Z', (int) $r->hist_time - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );
} );
