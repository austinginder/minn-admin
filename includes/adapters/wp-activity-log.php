<?php
/**
 * Bundled adapter: WP Activity Log (wp-security-audit-log).
 *
 * The free plugin has no REST API — events live in {base_prefix}wsal_occurrences
 * with per-event context in wsal_metadata — so the events are a kernel
 * resource over that table, held to this site on every read. Event titles resolve through WSAL's own
 * alert registry (Alert::get_alert); metadata VALUES render as raw strings
 * only — some are serialized blobs and we never unserialize() third-party
 * data (json_decode/regex only, per the adapter ground rules).
 *
 * Status card: 24h / 7d / all-time counts + high+ severity mix, over the
 * same rows.
 *
 * last-sweep: 2026-09-19
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * WSAL restricts log viewing via its own settings (only_me / only_admins /
 * extra users+roles): defer to its resolver, the 4.6+ helper or the legacy
 * settings object. Without either the answer is no, never manage_options:
 * an owner who set only_me would otherwise be overruled by every other
 * administrator on a build Minn does not recognize. Used by both the
 * descriptor gate and the shim permissions.
 */
function minn_admin_wsal_can_view() {
	// WSAL's resolver works from a user object it caches; a request core
	// demotes to signed-out after that cache filled must not inherit the
	// cookie user's answer (the Bricks and LatePoint class).
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( class_exists( '\WSAL\Helpers\Settings_Helper' )
		&& method_exists( '\WSAL\Helpers\Settings_Helper', 'current_user_can' ) ) {
		return (bool) \WSAL\Helpers\Settings_Helper::current_user_can( 'view' );
	}
	if ( class_exists( 'WpSecurityAuditLog' ) && method_exists( 'WpSecurityAuditLog', 'settings' ) ) {
		try {
			$settings = WpSecurityAuditLog::settings();
			if ( is_object( $settings ) && method_exists( $settings, 'current_user_can' ) ) {
				return (bool) $settings->current_user_can( 'view' );
			}
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return false;
}

function minn_admin_wsal_admin_url() {
	return admin_url( 'admin.php?page=wsal-auditlog' );
}

/**
 * The WSAL tenant visible in the current site context, as conditions.
 *
 * WP Activity Log uses site_id=0 for a single site's log and for the distinct
 * network/global stream on multisite. Including zero on a subsite therefore
 * crosses the tenant boundary; single-site installs still need it for their
 * ordinary events.
 *
 * @return array
 */
function minn_admin_wsal_site_scope() {
	return array( 'site_id' => is_multisite() ? get_current_blog_id() : array( 0, get_current_blog_id() ) );
}

/** WSAL severities are numeric constants; their names, or the raw value. */
function minn_admin_wsal_severity_label( $s ) {
	$map = array( 500 => 'critical', 400 => 'high', 300 => 'medium', 250 => 'low', 200 => 'info' );
	return $map[ (int) $s ] ?? (string) $s;
}

/** Alert title from WSAL's registry; falls back to the raw event code. */
function minn_admin_wsal_alert_title( $alert_id ) {
	if ( class_exists( '\WSAL\Controllers\Alert' ) && method_exists( '\WSAL\Controllers\Alert', 'get_alert' ) ) {
		$a = \WSAL\Controllers\Alert::get_alert( (int) $alert_id );
		if ( is_array( $a ) && ! empty( $a['desc'] ) ) {
			return $a['desc'];
		}
	}
	return 'Event ' . (int) $alert_id;
}

/**
 * The name a failed login tried. A failed login for a username that does not
 * exist (1003) is stored with no user: the name is in the event's Users meta,
 * a string or, from older builds, a serialized list.
 */
function minn_admin_wsal_tried_name( $value ) {
	$value = Minn_Admin::decode_serialized( $value, $value );
	if ( is_array( $value ) ) {
		$value = implode( ', ', array_filter( $value, 'is_scalar' ) );
	}
	return is_scalar( $value ) && ! is_serialized( (string) $value ) ? trim( (string) $value ) : '';
}

/** Who acted, in the words the list and the detail both use. */
function minn_admin_wsal_who( array $r, $tried ) {
	// WSAL writes "Unknown User" for system and CLI actors, and "Unknown
	// user" for a login event with no user id or name.
	$who = (string) $r['username'];
	if ( ( '' === $who || 0 === strcasecmp( $who, 'Unknown User' ) ) && $r['user_id'] ) {
		$u   = get_userdata( (int) $r['user_id'] );
		$who = $u ? $u->user_login : '#' . $r['user_id'];
	}
	if ( 0 === strcasecmp( $who, 'Unknown User' ) ) {
		$who = '';
	}
	return '' !== (string) $tried ? (string) $tried : ( '' !== $who ? $who : 'System' );
}

$minn_wsal_meta = array( 'table' => 'wsal_metadata', 'network' => true );

minn_admin_resource( 'wsal/events', function () use ( $minn_wsal_meta ) {
	return array(
		'active' => function () {
			return defined( 'WSAL_VERSION' );
		},
		'gates'  => array( 'view' => 'minn_admin_wsal_can_view' ),
		'labels' => array( 'not_found' => __( 'Event not found.', 'minn-admin' ) ),
		// WSAL keeps ONE occurrences table for the whole network with site_id
		// as the tenant column: every read here, the list, an event by id and
		// the status card's counts, is this site's rows only (a subsite
		// administrator walking the dense ids reads nothing of another's).
		'source' => array(
			'table'   => 'wsal_occurrences',
			'network' => true,
			'key'     => 'id',
			'columns' => array( 'id', 'alert_id', 'created_on', 'username', 'user_id', 'severity', 'object', 'event_type', 'client_ip' ),
			'where'   => 'minn_admin_wsal_site_scope',
		),
		'list'   => array(
			'facets' => array(
				// A severity by name, or by WSAL's own number.
				array( 'param' => 'severity', 'values' => function ( $v ) {
					$map = array( 'critical' => 500, 'high' => 400, 'medium' => 300, 'low' => 250, 'info' => 200 );
					if ( isset( $map[ $v ] ) ) {
						return array( 'severity' => $map[ $v ] );
					}
					return ctype_digit( $v ) ? array( 'severity' => (int) $v ) : null;
				} ),
			),
			// Also the username a failed login tried, which the Who column
			// shows for event 1003 (its Users meta).
			'search' => array(
				'columns' => array( 'username', 'object', 'event_type' ),
				'meta'    => array( 'table' => 'wsal_metadata', 'network' => true, 'key' => 'occurrence_id', 'name' => 'name', 'only' => 'Users', 'value' => 'value' ),
			),
			'range'  => array( 'column' => 'created_on', 'clock' => 'epoch' ),
			'order'  => array( 'column' => 'created_on' ),
			// The names tried on this page's 1003 rows, in one query over ids
			// the scoped list returned.
			'batch'  => function ( array $rows ) use ( $minn_wsal_meta ) {
				$ids = array();
				foreach ( $rows as $r ) {
					if ( 1003 === (int) $r['alert_id'] ) {
						$ids[] = (int) $r['id'];
					}
				}
				$tried = array();
				if ( $ids ) {
					foreach ( Minn_Admin_Kernel_SQL::select( $minn_wsal_meta, array( 'occurrence_id', 'value' ), array( 'name' => 'Users', 'occurrence_id' => $ids ) ) as $m ) {
						$tried[ (int) $m['occurrence_id'] ] = minn_admin_wsal_tried_name( $m['value'] );
					}
				}
				return $tried;
			},
			'row'    => function ( array $r, array $q ) {
				return array(
					'id'       => (int) $r['id'],
					'message'  => minn_admin_wsal_alert_title( $r['alert_id'] ),
					'username' => minn_admin_wsal_who( $r, $q['batch'][ (int) $r['id'] ] ?? '' ),
					'severity' => minn_admin_wsal_severity_label( $r['severity'] ),
					'date'     => $r['created_on'],
					'object'   => $r['object'],
					'ip'       => $r['client_ip'],
				);
			},
			'fields' => array(
				'id'       => 'int',
				'message'  => 'text',
				'username' => 'text',
				'severity' => 'text',
				// A UTC epoch, sent with a Z so the client never reads it as site-local.
				'date'     => array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'z' ),
				'object'   => 'text',
				'ip'       => 'text',
			),
		),
		'item'   => array(
			'view' => function ( array $row ) use ( $minn_wsal_meta ) {
				$pairs = Minn_Admin_Kernel_SQL::select( $minn_wsal_meta, array( 'name', 'value' ), array( 'occurrence_id' => (int) $row['id'] ), array( 'name' => 'ASC' ) );
				$tried = '';
				if ( 1003 === (int) $row['alert_id'] ) {
					foreach ( $pairs as $p ) {
						if ( 'Users' === $p['name'] && '' !== minn_admin_wsal_tried_name( $p['value'] ) ) {
							$tried = minn_admin_wsal_tried_name( $p['value'] );
						}
					}
				}
				$title = minn_admin_wsal_alert_title( $row['alert_id'] );
				$event = array(
					array( 'label' => __( 'Event', 'minn-admin' ), 'value' => $title . ' (' . (int) $row['alert_id'] . ')' ),
					// created_on is a UTC epoch; wp_date() prints it at the offset that date had.
					array( 'label' => __( 'When', 'minn-admin' ), 'value' => wp_date( 'M j, Y g:i a', (int) $row['created_on'] ) ),
					array( 'label' => __( 'Severity', 'minn-admin' ), 'value' => minn_admin_wsal_severity_label( $row['severity'] ) ),
					array( 'label' => __( 'Object', 'minn-admin' ), 'value' => $row['object'] ),
					array( 'label' => __( 'Type', 'minn-admin' ), 'value' => $row['event_type'] ),
					array( 'label' => __( 'User', 'minn-admin' ), 'value' => '' !== $tried ? $tried : ( 0 === strcasecmp( (string) $row['username'], 'Unknown User' ) || '' === (string) $row['username'] ? 'System' : $row['username'] ) ),
					array( 'label' => 'IP', 'value' => $row['client_ip'] ),
				);
				$context = array();
				foreach ( $pairs as $p ) {
					// Serialized/JSON blobs render as raw (truncated) strings — never
					// unserialize third-party data. Most values are plain scalars.
					// Cut after the fence has seen them whole (Minn_Admin_Egress::clip()).
					$context[] = array( 'label' => $p['name'], 'value' => Minn_Admin_Egress::clip( (string) $p['value'], 300 ) );
				}
				return array(
					'title'    => $title,
					'sections' => array_filter( array(
						array( 'title' => __( 'Event', 'minn-admin' ), 'rows' => array_values( array_filter( $event, function ( $r ) {
							return '' !== (string) $r['value'];
						} ) ) ),
						$context ? array( 'title' => __( 'Context', 'minn-admin' ), 'rows' => $context ) : null,
					) ),
					'adminUrl' => minn_admin_wsal_admin_url(),
				);
			},
		),
	);
} );

/**
 * Status-card model for WP Activity Log: 24h / 7d / all-time counts, the
 * last event and the high + critical mix, over this site's rows.
 *
 * @return array{rows:array,actions:array}
 */
function minn_admin_wsal_status_model() {
	$open = array( array( 'label' => __( 'Open WP Activity Log ↗', 'minn-admin' ), 'href' => minn_admin_wsal_admin_url() ) );
	// 500 critical + 400 high, in the last week and split out of the chart.
	$n = Minn_Admin_Kernel::log_counts( 'wsal/events', array(
		'counts' => array( 'hot' => array( 'where' => array( 'severity' => array( 500, 400 ) ), 'window' => 7 * DAY_IN_SECONDS ) ),
		'chart'  => array( 'split' => 'severity', 'secondary' => array( 500, 400 ) ),
	) );
	if ( null === $n ) {
		return minn_admin_activity_none( $open, __( 'Log table not found', 'minn-admin' ) );
	}
	return minn_admin_activity_card( $n, $open, __( 'High + critical (7d)', 'minn-admin' ), number_format_i18n( $n['counts']['hot'] ), __( 'High + critical', 'minn-admin' ) );
}

minn_admin_endpoint( 'wsal/status', array(
	'active' => function () {
		return defined( 'WSAL_VERSION' );
	},
	'gate'   => 'minn_admin_wsal_can_view',
	'run'    => 'minn_admin_wsal_status_model',
) );

minn_admin_add_surface( 'wp-activity-log', function () {
	return defined( 'WSAL_VERSION' ) && minn_admin_wsal_can_view();
}, function () {
	return minn_admin_activity_surface( 'wsal/events', array(
		'cap'     => 'read', // the active check above, in the vendor's own model, is the gate
		'sub'     => 'WP Activity Log',
		'plugin'  => array( 'wp-security-audit-log', 'wp-security-audit-log-premium' ),
		'icon'    => 'clock',
		'status'  => 'wsal/status',
		'tabs'    => array( 'severity', array( 'critical' => 'Critical', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low', 'info' => 'Info' ), 'All' ),
		'columns' => array(
			array( 'key' => 'username', 'label' => __( 'Who', 'minn-admin' ) ),
			array( 'key' => 'severity', 'label' => __( 'Severity', 'minn-admin' ), 'format' => 'pill' ),
		),
		'detail'  => array( 'sectionsRoute' => 'minn-admin/v1/wsal/events/{id}' ),
	) );
} );
