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
	// Wordfence keeps ONE table set on base_prefix for the whole network
	// (wfDB) and its own UI lives in Network Admin there — the login log
	// holds every site's events, so on multisite it is super-admin data.
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	// Case-insensitive existence check: on case-folding MySQL setups
	// (macOS, lower_case_table_names) SHOW TABLES returns wp_wflogins while
	// Wordfence names it wfLogins — a strict === would wrongly report it
	// absent. The SELECTs themselves resolve fine either way.
	$table = $wpdb->base_prefix . 'wfLogins';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

/** Human label for a wfLogins action code. */
function minn_admin_wordfence_action_label( $action, $fail ) {
	$map = array(
		'loginOK'                 => __( 'Signed in', 'minn-admin' ),
		'loginFailValidUsername'  => __( 'Failed login (valid user)', 'minn-admin' ),
		'loginFailInvalidUsername'=> __( 'Failed login (unknown user)', 'minn-admin' ),
		'lockedOut'               => __( 'Locked out', 'minn-admin' ),
		'blocked'                 => 'Blocked',
	);
	if ( isset( $map[ $action ] ) ) {
		return $map[ $action ];
	}
	return $fail ? __( 'Failed login', 'minn-admin' ) : __( 'Signed in', 'minn-admin' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wordfence_active() || ! current_user_can( 'manage_options' ) ) {
		return $surfaces;
	}

	$surfaces['wordfence'] = array(
		'label'      => __( 'Activity Log', 'minn-admin' ),
		'family'     => 'activity-log',
		'sub'        => 'Wordfence',
		'icon'       => 'shield',
		'cap'        => 'manage_options',
		// Status card reuses the System posture rows (firewall + last scan).
		'status'     => array( 'route' => 'minn-admin/v1/wordfence/status' ),
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
				'allLabel' => __( 'All logins', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'who', 'label' => __( 'User', 'minn-admin' ) ),
				array( 'key' => 'ip', 'label' => 'IP' ),
				array( 'key' => 'result', 'label' => __( 'Result', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'message' ),
			),
		),
	);
	return $surfaces;
} );

/** Status-card model: login totals + System-page firewall/scan posture. */
function minn_admin_wordfence_status_model() {
	global $wpdb;
	$table = $wpdb->base_prefix . 'wfLogins';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$failed_24h = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE fail = 1 AND ctime >= %d",
		time() - DAY_IN_SECONDS
	) );
	$ok_24h = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE fail = 0 AND ctime >= %d",
		time() - DAY_IN_SECONDS
	) );
	// phpcs:enable

	$rows = array(
		array(
			'label' => __( 'Failed logins (24h)', 'minn-admin' ),
			'value' => number_format_i18n( $failed_24h ),
			/* translators: %s: number of successful logins. */
			'hint'  => $ok_24h ? sprintf( __( '%s successful', 'minn-admin' ), number_format_i18n( $ok_24h ) ) : __( 'No successful logins in the window', 'minn-admin' ),
		),
	);
	// Reuse the System posture helpers so the card and health strip never drift.
	foreach ( minn_admin_wordfence_checks() as $check ) {
		$rows[] = array(
			'label' => $check['label'],
			'value' => 'pass' === $check['status'] ? __( 'OK', 'minn-admin' ) : ( 'fail' === $check['status'] ? __( 'Attention', 'minn-admin' ) : __( 'Watch', 'minn-admin' ) ),
			'hint'  => $check['detail'],
		);
	}

	$actions = array(
		array( 'label' => __( 'Open Wordfence ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=Wordfence' ) ),
	);
	foreach ( minn_admin_wordfence_checks() as $check ) {
		// Route on the destination, never the label: the label is translated.
		if ( ! empty( $check['href'] ) && false !== strpos( $check['href'], 'WordfenceScan' ) ) {
			$actions[] = array( 'label' => __( 'Scan ↗', 'minn-admin' ), 'href' => $check['href'] );
			break;
		}
	}

	return array(
		'rows'    => $rows,
		'actions' => $actions,
	);
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wordfence_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/wordfence/logins', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			// Network-shared table (see minn_admin_wordfence_active).
			return current_user_can( 'manage_options' ) && Minn_Admin::network_owner();
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->base_prefix . 'wfLogins';
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

	register_rest_route( 'minn-admin/v1', '/wordfence/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			// Network-shared table (see minn_admin_wordfence_active).
			return current_user_can( 'manage_options' ) && Minn_Admin::network_owner();
		},
		'callback'            => function () {
			return rest_ensure_response( minn_admin_wordfence_status_model() );
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
	// The System page reads this directly at manage_options, which is per
	// site on multisite. Firewall mode, scan age and unresolved-issue counts
	// describe the network, so gate here rather than at the call site.
	if ( ! Minn_Admin::network_owner() ) {
		return array();
	}
	if ( ! defined( 'WORDFENCE_VERSION' ) ) {
		return array();
	}
	$rows = array();

	// Firewall (WAF) mode: enabled | learning-mode | disabled.
	try {
		if ( class_exists( 'wfFirewall' ) ) {
			$mode = ( new wfFirewall() )->firewallMode();
			$map  = array(
				'enabled'       => array( 'pass', __( 'Enabled and blocking', 'minn-admin' ) ),
				'learning-mode' => array( 'warn', __( 'In learning mode — it is watching traffic but not blocking yet', 'minn-admin' ) ),
				'disabled'      => array( 'warn', __( 'The firewall is turned off', 'minn-admin' ) ),
			);
			/* translators: %s: the firewall mode Wordfence reports. */
			$m = isset( $map[ $mode ] ) ? $map[ $mode ] : array( 'warn', sprintf( __( 'Mode: %s', 'minn-admin' ), (string) $mode ) );
			$rows[] = array( 'label' => __( 'Wordfence firewall', 'minn-admin' ), 'status' => $m[0], 'detail' => $m[1], 'href' => admin_url( 'admin.php?page=WordfenceWAF' ) );
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
				$rows[] = array( 'label' => __( 'Wordfence scan', 'minn-admin' ), 'status' => 'warn', 'detail' => __( 'No malware scan has run yet', 'minn-admin' ) , 'href' => admin_url( 'admin.php?page=WordfenceScan' ) );
			} else {
				/* translators: %s: human-readable time difference (e.g. "5 mins"). */
				$when = sprintf( __( '%s ago', 'minn-admin' ), human_time_diff( (int) $last ) );
				if ( $issues > 0 ) {
					/* translators: 1: number of unresolved issues, 2: how long ago the scan ran. */
					$detail = sprintf( _n( '%1$s unresolved issue from the last scan (%2$s)', '%1$s unresolved issues from the last scan (%2$s)', $issues, 'minn-admin' ), number_format_i18n( $issues ), $when );
					$rows[] = array( 'label' => __( 'Wordfence scan', 'minn-admin' ), 'status' => 'fail', 'detail' => $detail, 'href' => admin_url( 'admin.php?page=WordfenceScan' ) );
				} elseif ( time() - (int) $last > 14 * DAY_IN_SECONDS ) {
					/* translators: %s: how long ago the last scan ran. */
					$rows[] = array( 'label' => __( 'Wordfence scan', 'minn-admin' ), 'status' => 'warn', 'detail' => sprintf( __( 'Last scan was %s. Run a fresh one.', 'minn-admin' ), $when ), 'href' => admin_url( 'admin.php?page=WordfenceScan' ) );
				} else {
					/* translators: %s: how long ago the last scan ran. */
					$rows[] = array( 'label' => __( 'Wordfence scan', 'minn-admin' ), 'status' => 'pass', 'detail' => sprintf( __( 'Last scan %s, no issues found', 'minn-admin' ), $when ), 'href' => admin_url( 'admin.php?page=WordfenceScan' ) );
				}
			}
		}
	} catch ( \Throwable $e ) {
		// Ditto.
	}

	return $rows;
}
