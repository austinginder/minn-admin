<?php
/**
 * Bundled adapter: All-In-One Security (AIOS).
 *
 * AIOS keeps a general audit feed in {base_prefix}aiowps_audit_log (its own
 * table, base_prefix like Solid Security's itsec_lockouts). No REST surface,
 * so this shim does read-only, prefix-scoped SELECTs and joins the
 * activity-log family (Wordfence / WSAL / Stream shape). Event context lives
 * in a JSON `details` column: json_decode only, never unserialize; the detail
 * view renders the decoded top level as a kv-table (v0.18.0 row type).
 *
 * Status card: 24h / 7d / all-time counts + a warnings-in-7d row.
 *
 * last-sweep: 2026-07-17
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_aios_active() {
	return defined( 'AIO_WP_SECURITY_VERSION' ) || class_exists( 'AIO_WP_Security' );
}

/**
 * AIOS gates its admin behind AIOWPSEC_MANAGEMENT_PERMISSION (manage_options
 * by default; a site can redefine it in wp-config). Mirror it so Minn and the
 * plugin's own screens stay in lockstep.
 */
function minn_admin_aios_can() {
	$cap = defined( 'AIOWPSEC_MANAGEMENT_PERMISSION' ) ? AIOWPSEC_MANAGEMENT_PERMISSION : 'manage_options';
	return current_user_can( $cap );
}

function minn_admin_aios_table() {
	if ( defined( 'AIOWPSEC_TBL_AUDIT_LOG' ) ) {
		return AIOWPSEC_TBL_AUDIT_LOG;
	}
	global $wpdb;
	return $wpdb->base_prefix . 'aiowps_audit_log';
}

function minn_admin_aios_admin_url() {
	return admin_url( 'admin.php?page=aiowpsec_audit' );
}

/** event_type is a snake_case slug; render it as a sentence. */
function minn_admin_aios_event_label( $type ) {
	$type = trim( str_replace( array( '-', '_' ), ' ', (string) $type ) );
	return $type ? ucfirst( $type ) : 'Event';
}

/** AIOS levels → the shared pill vocabulary (fatal/error red, warning amber). */
function minn_admin_aios_level( $level ) {
	$level = strtolower( (string) $level );
	return in_array( $level, array( 'info', 'warning', 'fatal', 'error', 'debug', 'trace' ), true ) ? $level : 'info';
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_aios_active() ) {
		return $surfaces;
	}

	$surfaces['all-in-one-security'] = array(
		'label'      => 'Activity Log',
		'family'     => 'activity-log',
		'sub'        => 'All-In-One Security',
		'icon'       => 'shield',
		'cap'        => 'read', // real gate is adapter-side minn_admin_aios_can().
		'status'     => array( 'route' => 'minn-admin/v1/aios/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/aios/events',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'tabs'      => array(
				'param'    => 'level',
				'static'   => array(
					array( 'warning', 'Warnings' ),
					array( 'error', 'Errors' ),
					array( 'info', 'Info' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'username', 'label' => 'Who' ),
				array( 'key' => 'ip', 'label' => 'IP', 'format' => 'mono', 'width' => '130px' ),
				array( 'key' => 'level', 'label' => 'Level', 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/aios/events/{id}',
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_aios_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/aios/events', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_aios_can',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = minn_admin_aios_table();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived table; WHERE built from placeholders.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$where    = array( '1=1' );
			$args     = array();
			if ( $request['level'] ) {
				$where[] = 'level = %s';
				$args[]  = minn_admin_aios_level( $request['level'] );
			}
			if ( $request['search'] ) {
				$like    = '%' . $wpdb->esc_like( $request['search'] ) . '%';
				$where[] = '(username LIKE %s OR event_type LIKE %s OR ip LIKE %s)';
				array_push( $args, $like, $like, $like );
			}
			$where_sql = implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
			$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, username, ip, level, event_type, created FROM {$table}
				 WHERE {$where_sql} ORDER BY created DESC, id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$items = array();
			foreach ( (array) $rows as $r ) {
				$who = (string) $r->username;
				$items[] = array(
					'id'       => (int) $r->id,
					'message'  => minn_admin_aios_event_label( $r->event_type ),
					'username' => '' !== $who ? $who : 'System',
					'ip'       => (string) $r->ip,
					'level'    => minn_admin_aios_level( $r->level ),
					// Trailing Z: created is a UTC epoch, not site-local.
					'date'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) $r->created ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/aios/events/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_aios_can',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = minn_admin_aios_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Event not found.', array( 'status' => 404 ) );
			}
			$who   = (string) $row->username;
			$event = array(
				array( 'label' => 'Event', 'value' => minn_admin_aios_event_label( $row->event_type ) ),
				array( 'label' => 'When', 'value' => date_i18n( 'M j, Y g:i a', (int) $row->created + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ),
				array( 'label' => 'Level', 'value' => minn_admin_aios_level( $row->level ), 'type' => 'pill' ),
				array( 'label' => 'User', 'value' => '' !== $who ? $who : 'System' ),
				array( 'label' => 'IP', 'value' => (string) $row->ip ),
			);
			if ( ! empty( $row->country_code ) ) {
				$event[] = array( 'label' => 'Country', 'value' => (string) $row->country_code );
			}
			$sections = array(
				array( 'title' => 'Event', 'rows' => array_values( array_filter( $event, function ( $r ) {
					return '' !== (string) $r['value'];
				} ) ) ),
			);

			// `details` is JSON — decode (never unserialize) and flatten the
			// top level into scalar Context rows. The activity-log family
			// renders detail as a contact card (renderActivityDetail), which
			// surfaces labeled scalar rows as fields; a kv-table object row is
			// for the plain-sections surfaces (mail family), not this card.
			$decoded = json_decode( (string) $row->details, true );
			if ( is_array( $decoded ) ) {
				// AIOS commonly wraps under the event key: unwrap one level.
				if ( 1 === count( $decoded ) && is_array( reset( $decoded ) ) ) {
					$decoded = reset( $decoded );
				}
				$context = array();
				foreach ( $decoded as $k => $v ) {
					$label = ucfirst( trim( str_replace( array( '-', '_' ), ' ', (string) $k ) ) );
					if ( is_bool( $v ) ) {
						$context[] = array( 'label' => $label, 'value' => $v ? 'Yes' : 'No' );
					} elseif ( is_scalar( $v ) ) {
						$val = (string) $v;
						$context[] = array( 'label' => $label, 'value' => strlen( $val ) > 200 ? substr( $val, 0, 200 ) . '…' : $val );
					}
					// Nested values are skipped: the card is for short facts.
				}
				$context = array_values( array_filter( $context, function ( $r ) {
					return '' !== (string) $r['value'];
				} ) );
				if ( $context ) {
					$sections[] = array( 'title' => 'Context', 'rows' => $context );
				}
			}

			// AIOS stores `stacktrace` as a PHP-serialized array (a debug
			// artifact, not a readable trace) — deliberately not surfaced: it
			// would be a giant blob, and we never unserialize third-party data.

			return rest_ensure_response( array(
				'title'    => minn_admin_aios_event_label( $row->event_type ),
				'sections' => $sections,
				'adminUrl' => minn_admin_aios_admin_url(),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/aios/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_aios_can',
		'callback'            => function () {
			global $wpdb;
			$table = minn_admin_aios_table();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => 'Events', 'value' => '—', 'hint' => 'Audit log table not found' ) ),
					'actions' => array( array( 'label' => 'Open All-In-One Security ↗', 'href' => minn_admin_aios_admin_url() ) ),
				) );
			}
			$now   = time();
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$day   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created >= %d", $now - DAY_IN_SECONDS ) );
			$week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created >= %d", $now - 7 * DAY_IN_SECONDS ) );
			$warn  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created >= %d AND level IN ('warning','error','fatal')", $now - 7 * DAY_IN_SECONDS ) );
			$last  = $wpdb->get_var( "SELECT created FROM {$table} ORDER BY id DESC LIMIT 1" );
			// phpcs:enable
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => 'Events (24h)',
						'value' => number_format_i18n( $day ),
						'hint'  => number_format_i18n( $week ) . ' in the last 7 days',
					),
					array( 'label' => 'Events all-time', 'value' => number_format_i18n( $total ) ),
					array(
						'label' => 'Warnings (7d)',
						'value' => number_format_i18n( $warn ),
						'hint'  => $warn ? 'warning, error or fatal' : 'all clear',
					),
					array(
						'label' => 'Last event',
						'value' => $last ? human_time_diff( (int) $last, time() ) . ' ago' : '—',
					),
				),
				'actions' => array( array( 'label' => 'Open All-In-One Security ↗', 'href' => minn_admin_aios_admin_url() ) ),
			) );
		},
	) );
} );
