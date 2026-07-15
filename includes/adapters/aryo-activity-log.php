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
 * Status card (v0.16 Axis A): 24h / 7d / all-time + top action. hist_time
 * is WP local epoch (current_time('timestamp')), same trap as the list.
 *
 * last-sweep: 2026-07-15
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_aryo_can_view() {
	return current_user_can( 'view_all_aryo_activity_log' )
		|| current_user_can( apply_filters( 'aal_menu_page_capability', 'edit_pages' ) );
}

function minn_admin_aryo_admin_url() {
	return admin_url( 'admin.php?page=activity-log-page' );
}

/**
 * Status-card model for Aryo Activity Log.
 *
 * @return array{rows:array,actions:array}
 */
function minn_admin_aryo_status_model() {
	global $wpdb;
	$table = $wpdb->prefix . 'aryo_activity_log';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array(
			'rows'    => array( array( 'label' => 'Events', 'value' => '—', 'hint' => 'Log table not found' ) ),
			'actions' => array( array( 'label' => 'Open Activity Log ↗', 'href' => minn_admin_aryo_admin_url() ) ),
		);
	}
	// hist_time is site-local epoch — compare against current_time('timestamp').
	$now     = (int) current_time( 'timestamp' );
	$since_d = $now - DAY_IN_SECONDS;
	$since_w = $now - ( 7 * DAY_IN_SECONDS );
	$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$day     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE hist_time >= %d", $since_d ) );
	$week    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE hist_time >= %d", $since_w ) );
	$last    = $wpdb->get_var( "SELECT hist_time FROM {$table} ORDER BY histid DESC LIMIT 1" );
	$top     = $wpdb->get_row( $wpdb->prepare(
		"SELECT action, COUNT(*) AS c FROM {$table}
		 WHERE hist_time >= %d AND action != ''
		 GROUP BY action ORDER BY c DESC LIMIT 1",
		$since_w
	) );
	// phpcs:enable

	$last_label = '—';
	if ( $last ) {
		// Display relative to "now" in the same clock the row used (site local).
		$last_label = human_time_diff( (int) $last, $now ) . ' ago';
	}
	$top_label = '—';
	if ( $top && ! empty( $top->action ) ) {
		$top_label = ucwords( str_replace( array( '-', '_' ), ' ', (string) $top->action ) )
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
				'label' => 'Top action (7d)',
				'value' => $top_label,
			),
		),
		'actions' => array(
			array( 'label' => 'Open Activity Log ↗', 'href' => minn_admin_aryo_admin_url() ),
		),
	);
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
		'status'     => array( 'route' => 'minn-admin/v1/aryo/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/aryo/events',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			// Action is Aryo's first-class verb (logged_in / updated / installed…).
			'tabs'      => array(
				'route'    => 'minn-admin/v1/aryo/actions',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'action',
				'allLabel' => 'All actions',
			),
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

	register_rest_route( 'minn-admin/v1', '/aryo/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_aryo_can_view',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_aryo_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/aryo/actions', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_aryo_can_view',
		'callback'            => function () {
			global $wpdb;
			$table = $wpdb->prefix . 'aryo_activity_log';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cols = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} WHERE action != '' ORDER BY action ASC" );
			$out  = array();
			foreach ( (array) $cols as $a ) {
				$out[] = array(
					'id'    => (string) $a,
					'title' => ucwords( str_replace( array( '-', '_' ), ' ', (string) $a ) ),
				);
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/aryo/events', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_aryo_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'aryo_activity_log';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$where    = array( '1=1' );
			$args     = array();

			if ( $request['action'] ) {
				$where[] = 'action = %s';
				$args[]  = sanitize_key( (string) $request['action'] );
			}
			if ( $request['search'] ) {
				$like    = '%' . $wpdb->esc_like( $request['search'] ) . '%';
				$where[] = '(object_name LIKE %s OR action LIKE %s OR object_type LIKE %s)';
				array_push( $args, $like, $like, $like );
			}
			$where_sql = implode( ' AND ', $where );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-derived; WHERE is placeholder-built.
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$args ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT histid, action, object_type, object_subtype, object_name, user_id, hist_ip, hist_time
				 FROM {$table} WHERE {$where_sql} ORDER BY hist_time DESC LIMIT %d OFFSET %d",
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
