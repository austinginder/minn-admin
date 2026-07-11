<?php
/**
 * Bundled adapter: Wordfence (login-security activity log).
 *
 * Wordfence has no REST log surface, but its {prefix}wfLogins table is a
 * clean record of every login and failed attempt (who, from where, when) —
 * the security half of "what happened on my site". This shim exposes it as
 * an Activity Log family member: read-only, prefix-scoped SELECTs, the
 * binary IP decoded through Wordfence's OWN inet_ntop so we never reinvent
 * its packing. Firewall config and scans stay in wp-admin; that's the
 * plugin's product, not a log.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wordfence_active() {
	global $wpdb;
	if ( ! defined( 'WORDFENCE_VERSION' ) && ! class_exists( 'wordfence' ) ) {
		return false;
	}
	// Case-insensitive existence check: on case-folding MySQL setups
	// (macOS, lower_case_table_names) SHOW TABLES returns wp_wflogins while
	// Wordfence names it wfLogins — a strict === would wrongly report it
	// absent. The SELECTs themselves resolve fine either way.
	$table = $wpdb->prefix . 'wfLogins';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

/** Human label for a wfLogins action code. */
function minn_admin_wordfence_action_label( $action, $fail ) {
	$map = array(
		'loginOK'                 => 'Signed in',
		'loginFailValidUsername'  => 'Failed login (valid user)',
		'loginFailInvalidUsername'=> 'Failed login (unknown user)',
		'lockedOut'               => 'Locked out',
		'blocked'                 => 'Blocked',
	);
	if ( isset( $map[ $action ] ) ) {
		return $map[ $action ];
	}
	return $fail ? 'Failed login' : 'Signed in';
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wordfence_active() || ! current_user_can( 'manage_options' ) ) {
		return $surfaces;
	}

	$surfaces['wordfence'] = array(
		'label'      => 'Activity Log',
		'family'     => 'activity-log',
		'sub'        => 'Wordfence',
		'icon'       => 'shield',
		'cap'        => 'manage_options',
		'collection' => array(
			'route'     => 'minn-admin/v1/wordfence/logins',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'  => 'kind',
				'static' => array(
					array( 'failed', 'Failed' ),
					array( 'success', 'Successful' ),
				),
				'allLabel' => 'All logins',
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'who', 'label' => 'User' ),
				array( 'key' => 'ip', 'label' => 'IP' ),
				array( 'key' => 'result', 'label' => 'Result', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'message' ),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wordfence_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/wordfence/logins', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'wfLogins';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$kind     = sanitize_key( (string) $request['kind'] );

			$where = '1=1';
			$args  = array();
			if ( 'failed' === $kind ) {
				$where = 'fail = 1';
			} elseif ( 'success' === $kind ) {
				$where = 'fail = 0';
			}
			if ( $request['search'] ) {
				$like   = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
				$where .= ' AND username LIKE %s';
				$args[] = $like;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table prefix-derived; WHERE placeholder-built.
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, ctime, fail, action, username, userID, IP FROM {$table} WHERE {$where} ORDER BY ctime DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$decode = class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_ntop' );
			$items  = array();
			foreach ( (array) $rows as $r ) {
				$ip = $decode ? @wfUtils::inet_ntop( $r->IP ) : '';
				$items[] = array(
					'id'      => (int) $r->id,
					'message' => minn_admin_wordfence_action_label( $r->action, (int) $r->fail )
						. ( $r->username ? ': ' . $r->username : '' ),
					'who'     => $r->username ? $r->username : '—',
					'ip'      => $ip ? $ip : '—',
					'result'  => ( (int) $r->fail ) ? 'failed' : 'success',
					'action'  => $r->action,
					// ctime is a UTC float epoch.
					'date'    => gmdate( 'Y-m-d\TH:i:s\Z', (int) $r->ctime ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );
} );

/**
 * Security posture health rows for the System page: firewall mode, last
 * scan and open-issue count. Read through Wordfence's OWN public APIs
 * (guarded, Throwable-caught), never its private storage, so a Wordfence
 * version change degrades to fewer rows rather than a fatal. Returns [] when
 * Wordfence is not loaded. Firewall/scan config stays in wp-admin; these are
 * a glanceable status, not a control.
 *
 * @return array[] of { label, status, detail }
 */
function minn_admin_wordfence_checks() {
	if ( ! defined( 'WORDFENCE_VERSION' ) ) {
		return array();
	}
	$rows = array();

	// Firewall (WAF) mode: enabled | learning-mode | disabled.
	try {
		if ( class_exists( 'wfFirewall' ) ) {
			$mode = ( new wfFirewall() )->firewallMode();
			$map  = array(
				'enabled'       => array( 'pass', 'Enabled and blocking' ),
				'learning-mode' => array( 'warn', 'In learning mode — it is watching traffic but not blocking yet' ),
				'disabled'      => array( 'warn', 'The firewall is turned off' ),
			);
			$m = isset( $map[ $mode ] ) ? $map[ $mode ] : array( 'warn', 'Mode: ' . (string) $mode );
			$rows[] = array( 'label' => 'Wordfence firewall', 'status' => $m[0], 'detail' => $m[1] );
		}
	} catch ( \Throwable $e ) {
		// A version mismatch just drops the row.
	}

	// Last scan + unresolved issues.
	try {
		if ( class_exists( 'wfScanner' ) && class_exists( 'wfIssues' ) ) {
			$last   = wfScanner::shared()->lastScanTime();
			$issues = (int) ( new wfIssues() )->getIssueCount();
			if ( ! $last ) {
				$rows[] = array( 'label' => 'Wordfence scan', 'status' => 'warn', 'detail' => 'No malware scan has run yet' );
			} else {
				$when = human_time_diff( (int) $last ) . ' ago';
				if ( $issues > 0 ) {
					$rows[] = array( 'label' => 'Wordfence scan', 'status' => 'fail', 'detail' => $issues . ' unresolved issue' . ( 1 === $issues ? '' : 's' ) . ' from the last scan (' . $when . ')' );
				} elseif ( time() - (int) $last > 14 * DAY_IN_SECONDS ) {
					$rows[] = array( 'label' => 'Wordfence scan', 'status' => 'warn', 'detail' => 'Last scan was ' . $when . ' — run a fresh one' );
				} else {
					$rows[] = array( 'label' => 'Wordfence scan', 'status' => 'pass', 'detail' => 'Last scan ' . $when . ', no issues found' );
				}
			}
		}
	} catch ( \Throwable $e ) {
		// Ditto.
	}

	return $rows;
}
