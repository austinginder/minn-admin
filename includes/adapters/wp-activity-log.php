<?php
/**
 * Bundled adapter: WP Activity Log (wp-security-audit-log).
 *
 * The free plugin has no REST API — events live in {prefix}wsal_occurrences
 * with per-event context in {prefix}wsal_metadata — so this shim does
 * read-only, prefix-scoped SELECTs. Event titles resolve through WSAL's own
 * alert registry (Alert::get_alert); metadata VALUES render as raw strings
 * only — some are serialized blobs and we never unserialize() third-party
 * data (json_decode/regex only, per the adapter ground rules).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * WSAL restricts log viewing via its own settings (only_me / only_admins /
 * extra users+roles) — defer to its resolver when present, manage_options
 * otherwise. Used by both the descriptor gate and the shim permissions.
 */
function minn_admin_wsal_can_view() {
	if ( class_exists( '\WSAL\Helpers\Settings_Helper' )
		&& method_exists( '\WSAL\Helpers\Settings_Helper', 'current_user_can' ) ) {
		return (bool) \WSAL\Helpers\Settings_Helper::current_user_can( 'view' );
	}
	return current_user_can( 'manage_options' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! defined( 'WSAL_VERSION' ) ) {
		return $surfaces;
	}
	if ( ! minn_admin_wsal_can_view() ) {
		return $surfaces;
	}

	$surfaces['wp-activity-log'] = array(
		'label'      => 'Activity Log',
		'family'     => 'activity-log',
		'sub'        => 'WP Activity Log',
		'icon'       => 'clock',
		'cap'        => 'read', // real gating happens above + in the shim.
		'collection' => array(
			'route'     => 'minn-admin/v1/wsal/events',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'severity',
				'static'   => array(
					array( 'critical', 'Critical' ),
					array( 'high', 'High' ),
					array( 'medium', 'Medium' ),
					array( 'low', 'Low' ),
					array( 'info', 'Info' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'username', 'label' => 'Who' ),
				array( 'key' => 'severity', 'label' => 'Severity', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/wsal/events/{id}',
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! defined( 'WSAL_VERSION' ) ) {
		return;
	}

	// WSAL severities are numeric constants; label map with raw fallback.
	$severity_label = function ( $s ) {
		$map = array( 500 => 'critical', 400 => 'high', 300 => 'medium', 250 => 'low', 200 => 'info' );
		return $map[ (int) $s ] ?? (string) $s;
	};

	// Alert title from WSAL's registry; falls back to the raw event code.
	$alert_title = function ( $alert_id ) {
		if ( class_exists( '\WSAL\Controllers\Alert' ) && method_exists( '\WSAL\Controllers\Alert', 'get_alert' ) ) {
			$a = \WSAL\Controllers\Alert::get_alert( (int) $alert_id );
			if ( is_array( $a ) && ! empty( $a['desc'] ) ) {
				return $a['desc'];
			}
		}
		return 'Event ' . (int) $alert_id;
	};

	register_rest_route( 'minn-admin/v1', '/wsal/events', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wsal_can_view',
		'callback'            => function ( WP_REST_Request $request ) use ( $severity_label, $alert_title ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'wsal_occurrences';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$where    = array( 'site_id IN (0, %d)' );
			$args     = array( get_current_blog_id() );

			if ( $request['severity'] ) {
				$sev_map = array( 'critical' => 500, 'high' => 400, 'medium' => 300, 'low' => 250, 'info' => 200 );
				$where[] = 'severity = %s';
				$args[]  = (string) ( $sev_map[ $request['severity'] ] ?? $request['severity'] );
			}
			if ( $request['search'] ) {
				$like    = '%' . $wpdb->esc_like( $request['search'] ) . '%';
				$where[] = '(username LIKE %s OR object LIKE %s OR event_type LIKE %s)';
				array_push( $args, $like, $like, $like );
			}

			$where_sql = implode( ' AND ', $where );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-derived, WHERE is placeholder-built above.
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) );
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, alert_id, created_on, username, user_id, severity, object, event_type, client_ip
				 FROM {$table} WHERE {$where_sql} ORDER BY created_on DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$items = array();
			foreach ( (array) $rows as $r ) {
				$who = $r->username;
				if ( ( ! $who || 'Unknown User' === $who ) && $r->user_id ) {
					$u   = get_userdata( (int) $r->user_id );
					$who = $u ? $u->user_login : '#' . $r->user_id;
				}
				if ( 'Unknown User' === $who ) {
					$who = ''; // WSAL's label for system/CLI actors — fall through to 'System'.
				}
				$items[] = array(
					'id'       => (int) $r->id,
					'message'  => $alert_title( $r->alert_id ),
					'username' => $who ?: 'System',
					'severity' => $severity_label( $r->severity ),
					// Trailing Z so the client does not treat UTC as site-local.
					'date'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) $r->created_on ),
					'object'   => $r->object,
					'ip'       => $r->client_ip,
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wsal/events/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wsal_can_view',
		'callback'            => function ( WP_REST_Request $request ) use ( $severity_label, $alert_title ) {
			global $wpdb;
			$occ  = $wpdb->prefix . 'wsal_occurrences';
			$meta = $wpdb->prefix . 'wsal_metadata';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$occ} WHERE id = %d", (int) $request['id'] ) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Event not found.', array( 'status' => 404 ) );
			}
			$pairs = $wpdb->get_results( $wpdb->prepare(
				"SELECT name, value FROM {$meta} WHERE occurrence_id = %d ORDER BY name", (int) $request['id']
			) );
			// phpcs:enable

			$event = array(
				array( 'label' => 'Event', 'value' => $alert_title( $row->alert_id ) . ' (' . (int) $row->alert_id . ')' ),
				array( 'label' => 'When', 'value' => date_i18n( 'M j, Y g:i a', (int) $row->created_on + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ),
				array( 'label' => 'Severity', 'value' => $severity_label( $row->severity ) ),
				array( 'label' => 'Object', 'value' => $row->object ),
				array( 'label' => 'Type', 'value' => $row->event_type ),
				array( 'label' => 'User', 'value' => $row->username ?: 'System' ),
				array( 'label' => 'IP', 'value' => $row->client_ip ),
			);

			$context = array();
			foreach ( (array) $pairs as $p ) {
				// Serialized/JSON blobs render as raw (truncated) strings — never
				// unserialize third-party data. Most values are plain scalars.
				$val = (string) $p->value;
				if ( strlen( $val ) > 300 ) {
					$val = substr( $val, 0, 300 ) . '…';
				}
				$context[] = array( 'label' => $p->name, 'value' => $val );
			}

			return rest_ensure_response( array(
				'title'    => $alert_title( $row->alert_id ),
				'sections' => array_filter( array(
					array( 'title' => 'Event', 'rows' => array_values( array_filter( $event, function ( $r ) { return '' !== (string) $r['value']; } ) ) ),
					$context ? array( 'title' => 'Context', 'rows' => $context ) : null,
				) ),
				'adminUrl' => admin_url( 'admin.php?page=wsal-auditlog' ),
			) );
		},
	) );
} );
