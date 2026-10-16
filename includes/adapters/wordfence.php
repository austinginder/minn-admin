<?php
/**
 * Bundled adapter: Wordfence (login-security activity log).
 *
 * Wordfence has no REST log surface, but its {prefix}wfLogins table is a
 * clean record of every login and failed attempt (who, from where, when) —
 * the security half of "what happened on my site". It is a kernel
 * resource in the Activity Log family, read-only, the binary IP decoded
 * through Wordfence's OWN inet_ntop so we never reinvent its packing. Firewall config and scans stay in wp-admin; that's the
 * plugin's product, not a log.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wordfence registers its whole admin at activate_plugins, not manage_options.
 * Its login log names usernames, addresses and which attempts used a REAL
 * account, so a role narrowed to site settings should not read it here.
 *
 * @return bool
 */
function minn_admin_wordfence_can() {
	// Wordfence answers this in two places and they are not the same
	// question. Their menu opens to anyone who may activate plugins, but
	// every request that fills those screens with data asks whether you may
	// manage site options (the network, on multisite). What Minn serves IS
	// that data: firewall mode, when the last scan ran, how many issues are
	// unresolved, sign-in totals. Ask the data question.
	if ( class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'isAdmin' ) ) {
		try {
			return (bool) wfUtils::isAdmin();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the mirrored test below.
		}
	}
	return is_multisite() ? current_user_can( 'manage_network' ) : current_user_can( 'manage_options' );
}

function minn_admin_wordfence_active() {
	if ( ! defined( 'WORDFENCE_VERSION' ) && ! class_exists( 'wordfence' ) ) {
		return false;
	}
	// Wordfence keeps ONE table set on base_prefix for the whole network
	// (wfDB) and its own UI lives in Network Admin there — the login log
	// holds every site's events, so on multisite it is super-admin data.
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	// The kernel's check ignores case: on case-folding MySQL setups (macOS,
	// lower_case_table_names) SHOW TABLES returns wp_wflogins while
	// Wordfence names it wfLogins.
	return Minn_Admin_Kernel_SQL::exists( array( 'table' => 'wfLogins', 'network' => true ) );
}

/** Network-shared table (see minn_admin_wordfence_active), so network data. */
function minn_admin_wordfence_can_read_log() {
	return minn_admin_wordfence_can() && Minn_Admin::network_owner();
}

/**
 * Human label for a wfLogins action code: every code Wordfence 9's
 * logLogin() calls write, plus the two older ones.
 */
function minn_admin_wordfence_action_label( $action, $fail ) {
	$map = array(
		'loginOK'                  => __( 'Signed in', 'minn-admin' ),
		'loginPasskeyOK'           => __( 'Signed in with a passkey', 'minn-admin' ),
		'logout'                   => __( 'Signed out', 'minn-admin' ),
		'loginFailValidUsername'   => __( 'Failed login (valid user)', 'minn-admin' ),
		'loginFailInvalidUsername' => __( 'Failed login (unknown user)', 'minn-admin' ),
		'loginFailPasskey'         => __( 'Failed passkey login', 'minn-admin' ),
		'loginFailPasskeyRequired' => __( 'Login blocked (passkey required)', 'minn-admin' ),
		'lockedOut'                => __( 'Locked out', 'minn-admin' ),
		'blocked'                  => 'Blocked',
	);
	if ( isset( $map[ $action ] ) ) {
		return $map[ $action ];
	}
	return $fail ? __( 'Failed login', 'minn-admin' ) : __( 'Signed in', 'minn-admin' );
}

minn_admin_add_surface( 'wordfence', function () {
	return minn_admin_wordfence_active() && minn_admin_wordfence_can();
}, function () {
	// Their answer is a resolver, not a capability name; the active check
	// above is the real gate (the UpdraftPlus precedent).
	return minn_admin_activity_surface( 'wordfence/logins', array(
		'cap'     => 'read', // the active check above, in the vendor's own model, is the gate
		'sub'     => 'Wordfence',
		'plugin'  => 'wordfence',
		// Status card reuses the System posture rows (firewall + last scan).
		'status'  => 'wordfence/status',
		'tabs'    => array( 'kind', array( 'failed' => 'Failed', 'success' => 'Successful' ), __( 'All logins', 'minn-admin' ) ),
		'columns' => array(
			array( 'key' => 'who', 'label' => __( 'User', 'minn-admin' ) ),
			array( 'key' => 'ip', 'label' => 'IP' ),
			array( 'key' => 'result', 'label' => __( 'Result', 'minn-admin' ), 'format' => 'pill' ),
		),
		'when'    => array( 'utc' => true ),
		'detail'  => array( 'skip' => array( 'message' ) ),
	) );
} );

minn_admin_resource( 'wordfence/logins', array(
	'active' => 'minn_admin_wordfence_active',
	'gates'  => array( 'view' => 'minn_admin_wordfence_can_read_log' ),
	'source' => array(
		'table'   => 'wfLogins',
		'network' => true,
		'key'     => 'id',
		'columns' => array( 'id', 'ctime', 'fail', 'action', 'username', 'userID', 'IP' ),
		// Its other records of visitors and accounts: live traffic, blocks
		// and the addresses they stopped, security and audit events, and
		// each account's second-factor secrets and passkeys.
		'tables'  => array( 'wfHits', 'wfLiveTrafficHuman', 'wfBlocks7', 'wfBlockedIPLog', 'wfSecurityEvents', 'wfAuditEvents', 'wfls_2fa_secrets', 'wfls_passkeys' ),
	),
	'list'   => array(
		// A sign-out is logged with fail = 0 but is no sign-in: their own
		// dashboard leaves `logout` out of its successful logins, and so does this.
		'facets' => array(
			array( 'param' => 'kind', 'values' => function ( $v ) {
				$kinds = array(
					'failed'  => array( 'fail' => 1 ),
					'success' => array( 'fail' => 0, 'action' => array( 'op' => '!=', 'value' => 'logout' ) ),
				);
				return $kinds[ sanitize_key( $v ) ] ?? null;
			} ),
		),
		'search' => array( 'columns' => array( 'username' ) ),
		'range'  => array( 'column' => 'ctime', 'clock' => 'epoch' ),
		'order'  => array( 'column' => 'ctime' ),
		'row'    => function ( array $r ) {
			// The binary address through Wordfence's own unpacking.
			$ip    = class_exists( 'wfUtils' ) && method_exists( 'wfUtils', 'inet_ntop' ) ? @wfUtils::inet_ntop( $r['IP'] ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$label = minn_admin_wordfence_action_label( $r['action'], (int) $r['fail'] );
			return array(
				'id'      => (int) $r['id'],
				'message' => $label . ( $r['username'] ? ': ' . $r['username'] : '' ),
				'who'     => $r['username'] ? $r['username'] : '—',
				'ip'      => $ip ? $ip : '—',
				// A sign-out is neither (their dashboard counts it as no sign-in).
				'result'  => ( (int) $r['fail'] ) ? 'failed' : ( 'logout' === $r['action'] ? 'signed-out' : 'success' ),
				// The event page reads this as the level: their code ("loginOK") in words.
				'action'  => $label,
				'date'    => $r['ctime'],
			);
		},
		'fields' => array(
			'id'      => 'int',
			'message' => 'text',
			'who'     => 'text',
			'ip'      => 'text',
			'result'  => array( 'type' => 'enum', 'values' => array( 'failed', 'signed-out', 'success' ) ),
			'action'  => 'text',
			// ctime is a UTC float epoch.
			'date'    => array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'z' ),
		),
	),
) );

/** Status-card model: login totals + System-page firewall/scan posture. */
function minn_admin_wordfence_status_model() {
	$logins = array( 'table' => 'wfLogins', 'network' => true );
	$since  = Minn_Admin_Kernel::since( 'epoch', DAY_IN_SECONDS );
	$failed = Minn_Admin_Kernel_SQL::count( $logins, array( 'fail' => 1, 'ctime' => $since ) );
	$ok     = Minn_Admin_Kernel_SQL::count( $logins, array( 'fail' => 0, 'action' => array( 'op' => '!=', 'value' => 'logout' ), 'ctime' => $since ) );
	// Fourteen site-local days of logins, failures split out. Sign-outs are
	// in neither series (Wordfence logs them with fail = 0, never 1).
	$days = Minn_Admin_Kernel_SQL::chart_days( array(
		'table'     => $logins,
		'column'    => 'ctime',
		'clock'     => 'epoch',
		'where'     => array( 'action' => array( 'op' => '!=', 'value' => 'logout' ) ),
		'split'     => 'fail',
		'secondary' => array( 1 ),
	) );

	$rows = array(
		array(
			'label' => __( 'Failed logins (24h)', 'minn-admin' ),
			'value' => number_format_i18n( $failed ),
			/* translators: %s: number of successful logins. */
			'hint'  => $ok ? sprintf( __( '%s successful', 'minn-admin' ), number_format_i18n( $ok ) ) : __( 'No successful logins in the window', 'minn-admin' ),
		),
	);
	// Reuse the System posture helpers so the card and health strip never drift.
	$checks = minn_admin_wordfence_checks();
	foreach ( $checks as $check ) {
		$rows[] = array(
			'label' => $check['label'],
			'value' => 'pass' === $check['status'] ? __( 'OK', 'minn-admin' ) : ( 'fail' === $check['status'] ? __( 'Attention', 'minn-admin' ) : __( 'Watch', 'minn-admin' ) ),
			'hint'  => $check['detail'],
		);
	}
	$actions = array(
		array( 'label' => __( 'Open Wordfence ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=Wordfence' ) ),
	);
	foreach ( $checks as $check ) {
		// Route on the destination, never the label: the label is translated.
		if ( ! empty( $check['href'] ) && false !== strpos( $check['href'], 'WordfenceScan' ) ) {
			$actions[] = array( 'label' => __( 'Scan ↗', 'minn-admin' ), 'href' => $check['href'] );
			break;
		}
	}
	return array(
		'rows'    => $rows,
		'chart'   => minn_admin_chart_build( $days, __( 'Successful', 'minn-admin' ), __( 'Failed', 'minn-admin' ) ),
		'actions' => $actions,
	);
}

minn_admin_endpoint( 'wordfence/status', array(
	'active' => 'minn_admin_wordfence_active',
	'gate'   => 'minn_admin_wordfence_can_read_log',
	'run'    => 'minn_admin_wordfence_status_model',
) );

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
	// Ask the same question the surface and the routes ask. These rows carry
	// firewall mode, scan age and unresolved-issue counts, so a role narrowed
	// to site settings should not read them here either.
	if ( ! minn_admin_wordfence_can() ) {
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
