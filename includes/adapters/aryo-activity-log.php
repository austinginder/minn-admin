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
 * Request source (Activity Log 2.14): the table gained a request_source
 * column ("{channel}|app:{name}": rest / cli / cron / xmlrpc / abilities,
 * empty for an ordinary browser request, plus the Application Password name
 * when one authenticated the request). Their migration adds the column
 * lazily (first wp-admin load, and only by hand above 50k rows), so every
 * read here gates on the column actually existing rather than on the plugin
 * version; the labels and the filter matching are the plugin's own.
 *
 * last-sweep: 2026-09-02
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Who may read the Aryo activity log through Minn.
 *
 * edit_pages is Activity Log's MENU capability, but the menu is not where the
 * plugin draws its boundary. AAL_Activity_Log_List_Table::_get_where_by_role()
 * appends an object_type + user_caps clause to EVERY query it runs, so in the
 * plugin's own screen an Editor sees only Posts/Taxonomies/Attachments/Comments
 * performed by editor/author/guest — never administrator actions, and never
 * the Core/Export/Users/Plugins/Options/Theme/Menu/Widget modules.
 *
 * Minn's shim reproduces those queries without the role clause, so copying
 * only the menu cap would hand an Editor plugin installs, user-role changes,
 * option changes, every acting username and every retained IP, plus the
 * attempted usernames on failed logins. This view shows everything, so it
 * asks for the capability that means everything, the way the Stream and WSAL
 * adapters defer to their vendors' own resolvers. Sites that granted the
 * lesser cap deliberately can restore it with the filter.
 *
 * @return bool
 */
function minn_admin_aryo_can_view() {
	return (bool) apply_filters(
		'minn_admin_aryo_can_view',
		current_user_can( 'view_all_aryo_activity_log' ) || current_user_can( 'manage_options' )
	);
}

function minn_admin_aryo_admin_url() {
	return admin_url( 'admin.php?page=activity-log-page' );
}

/**
 * Whether the log table carries the request_source column (Activity Log 2.14+
 * after its migration has run). The column is what the SQL needs, so this asks
 * the table, not the plugin's version option.
 *
 * @return bool
 */
function minn_admin_aryo_source_ready() {
	static $ready = null;
	if ( null !== $ready ) {
		return $ready;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'aryo_activity_log';
	// The same existence check the status model makes first. Asking SHOW
	// COLUMNS of a table that is not there writes a database error into the
	// surfaces payload under WP_DEBUG, which is how a subsite created after
	// network activation reports a missing column.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		$ready = false;
		return $ready;
	}
	$col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'request_source' ) );
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ready = ! empty( $col );
	return $ready;
}

/**
 * Channel vocabulary: the plugin's own labels when its API class is loaded,
 * the same five words otherwise. '' is the browser (their code stores no
 * channel for an ordinary wp-admin or front-end request).
 *
 * @return array<string,string>
 */
function minn_admin_aryo_channel_labels() {
	$labels = array(
		'rest'      => __( 'REST API', 'minn-admin' ),
		'cli'       => __( 'WP-CLI', 'minn-admin' ),
		'cron'      => __( 'WP-Cron', 'minn-admin' ),
		'xmlrpc'    => __( 'XML-RPC', 'minn-admin' ),
		'abilities' => __( 'WP Abilities', 'minn-admin' ),
	);
	if ( class_exists( 'AAL_API' ) && method_exists( 'AAL_API', 'get_channel_labels' ) ) {
		$theirs = (array) AAL_API::get_channel_labels();
		foreach ( $labels as $k => $v ) {
			if ( ! empty( $theirs[ $k ] ) ) {
				$labels[ $k ] = (string) $theirs[ $k ];
			}
		}
	}
	return $labels;
}

/**
 * Split a stored request_source into [channel, app_name] through the plugin's
 * own parser when it exists (same "{channel}|app:{name}" encoding either way).
 *
 * @param string $raw Stored value.
 * @return array{0:string,1:string}
 */
function minn_admin_aryo_parse_source( $raw ) {
	$raw = (string) $raw;
	if ( class_exists( 'AAL_API' ) && method_exists( 'AAL_API', 'parse_request_source' ) ) {
		$p = (array) AAL_API::parse_request_source( $raw );
		return array( (string) ( $p['channel'] ?? '' ), (string) ( $p['app_name'] ?? '' ) );
	}
	if ( '' === $raw ) {
		return array( '', '' );
	}
	$pos = strpos( $raw, '|app:' );
	if ( false !== $pos ) {
		return array( substr( $raw, 0, $pos ), substr( $raw, $pos + 5 ) );
	}
	if ( 0 === strpos( $raw, 'app:' ) ) {
		return array( '', substr( $raw, 4 ) );
	}
	return array( $raw, '' );
}

/**
 * Filter options for the Source dimension: the plugin's channels plus the two
 * their filter dropdown leaves implicit (the browser, and "any Application
 * Password" which their query matches by token).
 *
 * @return array<int,array{0:string,1:string}>
 */
function minn_admin_aryo_source_options() {
	$out = array(
		array( '', __( 'All sources', 'minn-admin' ) ),
		array( 'browser', __( 'Browser', 'minn-admin' ) ),
	);
	foreach ( minn_admin_aryo_channel_labels() as $k => $label ) {
		$out[] = array( $k, $label );
	}
	$out[] = array( 'app_password', __( 'App password', 'minn-admin' ) );
	return $out;
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
			'rows'    => array( array( 'label' => __( 'Events', 'minn-admin' ), 'value' => '—', 'hint' => __( 'Log table not found', 'minn-admin' ) ) ),
			'actions' => array( array( 'label' => __( 'Open Activity Log ↗', 'minn-admin' ), 'href' => minn_admin_aryo_admin_url() ) ),
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
		$last_label = sprintf(
			/* translators: %s: human-readable time since the last event. */
			__( '%s ago', 'minn-admin' ),
			human_time_diff( (int) $last, $now )
		);
	}
	$top_label = '—';
	if ( $top && ! empty( $top->action ) ) {
		$top_label = ucwords( str_replace( array( '-', '_' ), ' ', (string) $top->action ) )
			. ' (' . number_format_i18n( (int) $top->c ) . ')';
	}

	return array(
		'rows'    => array(
			array(
				'label' => __( 'Events (24h)', 'minn-admin' ),
				'value' => number_format_i18n( $day ),
				'hint'  => sprintf(
					/* translators: %s: number of events recorded in the last 7 days. */
					__( '%s in the last 7 days', 'minn-admin' ),
					number_format_i18n( $week )
				),
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
				'label' => __( 'Top action (7d)', 'minn-admin' ),
				'value' => $top_label,
			),
		),
		'actions' => array(
			array( 'label' => __( 'Open Activity Log ↗', 'minn-admin' ), 'href' => minn_admin_aryo_admin_url() ),
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
		'label'      => __( 'Activity Log', 'minn-admin' ),
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
				'allLabel' => __( 'All actions', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'who', 'label' => __( 'Who', 'minn-admin' ) ),
				array( 'key' => 'action', 'label' => __( 'Action', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'skip' => array( 'message' ),
			),
		),
	);
	if ( minn_admin_aryo_source_ready() ) {
		// Where the change came from: the daily audit question, and the
		// column their 2.14 release added. Rendered only once the column
		// exists, so a site whose migration has not run sees the old list.
		$surfaces['aryo-activity-log']['collection']['filter']    = array(
			'label'   => __( 'Source', 'minn-admin' ),
			'options' => minn_admin_aryo_source_options(),
			'query'   => 'source={v}',
		);
		$surfaces['aryo-activity-log']['collection']['columns'][] = array(
			'key'   => 'source',
			'label' => __( 'Source', 'minn-admin' ),
			'width' => '128px',
		);
	}
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
			$source_ready = minn_admin_aryo_source_ready();
			$source       = $source_ready ? sanitize_key( (string) $request['source'] ) : '';
			if ( 'browser' === $source ) {
				// No channel recorded: an ordinary wp-admin or front-end request.
				$where[] = "( request_source = '' OR request_source IS NULL )";
			} elseif ( 'app_password' === $source ) {
				// Their own matching for "any Application Password".
				$where[] = "( request_source LIKE '%|app:%' OR request_source LIKE 'app:%' )";
			} elseif ( '' !== $source ) {
				// Their own matching: the bare channel, or the channel with an app suffix.
				$where[] = '( request_source = %s OR request_source LIKE %s )';
				$args[]  = $source;
				$args[]  = $wpdb->esc_like( $source ) . '|%';
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
			$source_col = $source_ready ? ', request_source' : '';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT histid, action, object_type, object_subtype, object_name, user_id, hist_ip, hist_time{$source_col}
				 FROM {$table} WHERE {$where_sql} ORDER BY hist_time DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$labels = $source_ready ? minn_admin_aryo_channel_labels() : array();
			$users  = array();
			$items  = array();
			foreach ( (array) $rows as $r ) {
				$uid = (int) $r->user_id;
				if ( $uid && ! isset( $users[ $uid ] ) ) {
					$u             = get_userdata( $uid );
					$users[ $uid ] = $u ? $u->user_login : '#' . $uid;
				}
				$item = array(
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
				if ( $source_ready ) {
					list( $channel, $app ) = minn_admin_aryo_parse_source( isset( $r->request_source ) ? $r->request_source : '' );
					$item['source'] = '' === $channel
						? __( 'Browser', 'minn-admin' )
						: ( isset( $labels[ $channel ] ) ? $labels[ $channel ] : $channel );
					if ( '' !== $app ) {
						$item['app_password'] = $app;
					}
				}
				$items[] = $item;
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );
} );
