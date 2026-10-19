<?php
/**
 * Bundled adapter: All-In-One Security (AIOS).
 *
 * AIOS keeps a general audit feed in {base_prefix}aiowps_audit_log (its own
 * table, base_prefix like Solid Security's itsec_lockouts). No REST surface,
 * so the feed is a kernel resource over that table, held to this site, and
 * joins the activity-log family (Wordfence / WSAL / Stream shape). Event context lives
 * in a JSON `details` column: json_decode only, never unserialize; the detail
 * view renders the decoded top level as a kv-table (v0.18.0 row type).
 *
 * Status card: audit event counts (24h / 7d / all-time / warnings) plus the
 * login-protection posture rows (active lockdowns, permanent blocks, failed
 * logins in 24h). System page gets one AIOS health row via
 * minn_admin_aios_checks() (Solid / Wordfence precedent).
 *
 * last-sweep: 2026-07-27
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
	// Their whole admin sits behind one resolver, and the constant is only the
	// default that feeds it. A site that answers their filter to deny still had
	// the audit log -- usernames, addresses, attempted logins -- served here.
	if ( class_exists( 'AIOWPSecurity_Utility_Permissions' )
		&& method_exists( 'AIOWPSecurity_Utility_Permissions', 'has_manage_cap' ) ) {
		return (bool) AIOWPSecurity_Utility_Permissions::has_manage_cap();
	}
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

/**
 * Site scope for the audit log, which lives on base_prefix and is therefore
 * SHARED BY EVERY SITE on a multisite network.
 *
 * AIOS's own audit viewer appends `site_id = get_current_blog_id()` for
 * anyone who is not a super admin (admin/wp-security-list-audit.php), and
 * applies the same guard to its deletes. This shim gates on manage_options,
 * which a SUBSITE administrator holds, so without the same condition it would
 * hand a tenant every other tenant's acting usernames, client IPs and, on
 * failed logins, the ATTEMPTED usernames from neighbouring sites.
 *
 * @return array Conditions.
 */
function minn_admin_aios_site_scope() {
	return Minn_Admin::network_owner() ? array() : array( 'site_id' => get_current_blog_id() );
}

function minn_admin_aios_admin_url() {
	// Dashboard audit tab is the honest home for the log surface.
	return admin_url( 'admin.php?page=aiowpsec&tab=audit-logs' );
}

function minn_admin_aios_lockout_url() {
	return admin_url( 'admin.php?page=aiowpsec&tab=locked-ip' );
}

function minn_admin_aios_perm_block_url() {
	return admin_url( 'admin.php?page=aiowpsec&tab=permanent-block' );
}

/**
 * Login-protection counts from AIOS's own tables (their active-lockout
 * predicate is `released > UNIX_TIMESTAMP()`; permanent blocks are every
 * remaining row; failed logins are audit_log event_type=failed_login).
 * Tables missing = zeros (never fatals).
 *
 * @return array{locked:int,blocks:int,failed_24h:int}
 */
function minn_admin_aios_login_posture() {
	$out = array(
		'locked'     => 0,
		'blocks'     => 0,
		'failed_24h' => 0,
	);
	// Match AIOWPSecurity_Utility::get_locked_ips(): still locked while released is in the future.
	if ( Minn_Admin_Kernel_SQL::exists( 'aiowps_login_lockdown' ) ) {
		$out['locked'] = Minn_Admin_Kernel_SQL::count( 'aiowps_login_lockdown', array( 'released' => array( 'op' => '>', 'value' => time() ) ) );
	}
	// Unblock deletes the row; every remaining row is blocked.
	if ( Minn_Admin_Kernel_SQL::exists( 'aiowps_permanent_block' ) ) {
		$out['blocks'] = Minn_Admin_Kernel_SQL::count( 'aiowps_permanent_block' );
	}
	$audit = array( 'table' => 'aiowps_audit_log', 'network' => true );
	if ( Minn_Admin_Kernel_SQL::exists( $audit ) ) {
		$out['failed_24h'] = Minn_Admin_Kernel_SQL::count( $audit, array_merge( minn_admin_aios_site_scope(), array(
			'event_type' => 'failed_login',
			'created'    => Minn_Admin_Kernel::since( 'epoch', DAY_IN_SECONDS ),
		) ) );
	}
	return $out;
}

/**
 * System-page security posture (Wordfence / Solid shape). One row summarizing
 * failed logins, active lockdowns and permanent blocks. Empty when AIOS is
 * not loaded.
 *
 * @return array[] of { label, status, detail, href? }
 */
function minn_admin_aios_checks() {
	// Mirror the plugin's own management permission, which a site owner can
	// narrow below manage_options; the adapter resolves it for every other
	// path already.
	if ( ! minn_admin_aios_can() ) {
		return array();
	}
	if ( ! minn_admin_aios_active() ) {
		return array();
	}
	$p = minn_admin_aios_login_posture();
	// Active lockdowns deserve attention; permanent blocks are expected protection.
	$status = $p['locked'] > 0 ? 'warn' : 'pass';
	$bits   = array(
		sprintf(
			/* translators: %s: number of failed logins. */
			_n( '%s failed login in 24h', '%s failed logins in 24h', $p['failed_24h'], 'minn-admin' ),
			number_format_i18n( $p['failed_24h'] )
		),
		$p['locked']
			? sprintf(
				/* translators: %s: number of locked IPs. */
				_n( '%s locked out now', '%s locked out now', $p['locked'], 'minn-admin' ),
				number_format_i18n( $p['locked'] )
			)
			: __( 'nobody locked out', 'minn-admin' ),
		sprintf(
			/* translators: %s: number of permanent blocks. */
			_n( '%s permanent block', '%s permanent blocks', $p['blocks'], 'minn-admin' ),
			number_format_i18n( $p['blocks'] )
		),
	);
	return array(
		array(
			'label'  => __( 'All-In-One Security', 'minn-admin' ),
			'status' => $status,
			'detail' => implode( ' · ', $bits ),
			'href'   => $p['locked'] > 0 ? minn_admin_aios_lockout_url() : minn_admin_aios_admin_url(),
		),
	);
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

/**
 * The detail of one audit event: the row, then the top level of its JSON
 * `details` as Context rows. json_decode only, never unserialize; AIOS's
 * serialized `stacktrace` (a debug artifact, not a readable trace) is never
 * surfaced.
 */
function minn_admin_aios_event_view( array $row ) {
	$who   = (string) $row['username'];
	$event = array(
		array( 'label' => __( 'Event', 'minn-admin' ), 'value' => minn_admin_aios_event_label( $row['event_type'] ) ),
		// created is a UTC epoch; wp_date() prints it at the offset that date had.
		array( 'label' => __( 'When', 'minn-admin' ), 'value' => wp_date( 'M j, Y g:i a', (int) $row['created'] ) ),
		array( 'label' => __( 'Level', 'minn-admin' ), 'value' => minn_admin_aios_level( $row['level'] ), 'type' => 'pill' ),
		array( 'label' => __( 'User', 'minn-admin' ), 'value' => '' !== $who ? $who : 'System' ),
		array( 'label' => 'IP', 'value' => (string) $row['ip'] ),
	);
	if ( ! empty( $row['country_code'] ) ) {
		$event[] = array( 'label' => __( 'Country', 'minn-admin' ), 'value' => (string) $row['country_code'] );
	}
	$filled   = function ( $r ) {
		return '' !== (string) $r['value'];
	};
	$sections = array( array( 'title' => __( 'Event', 'minn-admin' ), 'rows' => array_values( array_filter( $event, $filled ) ) ) );
	// The activity-log family renders detail as a contact card, which
	// surfaces labeled scalar rows as fields; nested values are skipped.
	$decoded = json_decode( (string) ( $row['details'] ?? '' ), true );
	if ( is_array( $decoded ) ) {
		// AIOS commonly wraps under the event key: unwrap one level.
		if ( 1 === count( $decoded ) && is_array( reset( $decoded ) ) ) {
			$decoded = reset( $decoded );
		}
		$context = array();
		foreach ( $decoded as $k => $v ) {
			$label = ucfirst( trim( str_replace( array( '-', '_' ), ' ', (string) $k ) ) );
			if ( is_bool( $v ) ) {
				$context[] = array( 'label' => $label, 'value' => $v ? __( 'Yes', 'minn-admin' ) : __( 'No', 'minn-admin' ) );
			} elseif ( is_scalar( $v ) ) {
				$val       = (string) $v;
				$context[] = array( 'label' => $label, 'value' => Minn_Admin_Egress::clip( $val, 200 ) );
			}
		}
		$context = array_values( array_filter( $context, $filled ) );
		if ( $context ) {
			$sections[] = array( 'title' => __( 'Context', 'minn-admin' ), 'rows' => $context );
		}
	}
	return array(
		'title'    => minn_admin_aios_event_label( $row['event_type'] ),
		'sections' => $sections,
		'adminUrl' => minn_admin_aios_admin_url(),
	);
}

minn_admin_resource( 'aios/events', function () {
	$columns = array( 'id', 'username', 'ip', 'level', 'event_type', 'created' );
	return array(
		'active' => 'minn_admin_aios_active',
		'gates'  => array( 'view' => 'minn_admin_aios_can' ),
		'labels' => array( 'not_found' => __( 'Event not found.', 'minn-admin' ) ),
		// One table for the whole network; every read is this site's rows
		// unless the caller owns the network (404, never 403, out of scope).
		'source' => array(
			'table'   => 'aiowps_audit_log',
			'network' => true,
			'key'     => 'id',
			'columns' => $columns,
			'detail'  => function () use ( $columns ) {
				$audit = array( 'table' => 'aiowps_audit_log', 'network' => true );
				return array_merge( $columns, array( 'details' ), Minn_Admin_Kernel_SQL::has_column( $audit, 'country_code' ) ? array( 'country_code' ) : array() );
			},
			'where'   => 'minn_admin_aios_site_scope',
			// Its other logs of who signed in, from where, and who was locked
			// out or blocked.
			'tables'  => array( 'aiowps_events', 'aiowps_debug_log', 'aiowps_logged_in_users', 'aiowps_login_lockdown', 'aiowps_permanent_block' ),
		),
		'list'   => array(
			// Their own vocabulary: a level they do not log reads as info.
			'facets' => array(
				array( 'param' => 'level', 'values' => function ( $v ) {
					return array( 'level' => minn_admin_aios_level( $v ) );
				} ),
			),
			'search' => array( 'columns' => array( 'username', 'event_type', 'ip' ) ),
			'range'  => array( 'column' => 'created', 'clock' => 'epoch' ),
			'order'  => array( 'column' => 'created' ),
			'row'    => function ( array $r ) {
				return array(
					'id'       => (int) $r['id'],
					'message'  => minn_admin_aios_event_label( $r['event_type'] ),
					'username' => '' !== (string) $r['username'] ? (string) $r['username'] : 'System',
					'ip'       => (string) $r['ip'],
					'level'    => minn_admin_aios_level( $r['level'] ),
					'date'     => $r['created'],
				);
			},
			'fields' => array(
				'id'       => 'int',
				'message'  => 'text',
				'username' => 'text',
				'ip'       => 'text',
				'level'    => 'text',
				// A UTC epoch, sent with a Z.
				'date'     => array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'z' ),
			),
		),
		'item'   => array( 'view' => 'minn_admin_aios_event_view' ),
	);
} );

minn_admin_endpoint( 'aios/status', array(
	'active' => 'minn_admin_aios_active',
	'gate'   => 'minn_admin_aios_can',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open All-In-One Security ↗', 'minn-admin' ), 'href' => minn_admin_aios_admin_url() ) );
		$warn = array( 'warning', 'error', 'fatal' );
		$n    = Minn_Admin_Kernel::log_counts( 'aios/events', array(
			'counts' => array( 'warn' => array( 'where' => array( 'level' => $warn ), 'window' => 7 * DAY_IN_SECONDS ) ),
			'chart'  => array( 'split' => 'level', 'secondary' => $warn ),
		) );
		if ( null === $n ) {
			return minn_admin_activity_none( $open, __( 'Audit log table not found', 'minn-admin' ) );
		}
		$posture = minn_admin_aios_login_posture();
		$events  = Minn_Admin_Kernel::event_rows( $n );
		$rows    = array(
			$events['day'],
			$events['total'],
			array(
				'label' => __( 'Warnings (7d)', 'minn-admin' ),
				'value' => number_format_i18n( $n['counts']['warn'] ),
				'hint'  => $n['counts']['warn'] ? __( 'warning, error or fatal', 'minn-admin' ) : __( 'all clear', 'minn-admin' ),
			),
			array(
				'label' => __( 'Failed logins (24h)', 'minn-admin' ),
				'value' => number_format_i18n( $posture['failed_24h'] ),
				'hint'  => __( 'from the audit log', 'minn-admin' ),
			),
			array(
				'label' => __( 'Locked out now', 'minn-admin' ),
				'value' => $posture['locked'] ? number_format_i18n( $posture['locked'] ) : __( 'Nobody', 'minn-admin' ),
				'hint'  => $posture['locked'] ? __( 'temporary login lockdowns', 'minn-admin' ) : __( 'no active lockdowns', 'minn-admin' ),
			),
			array(
				'label' => __( 'Permanent blocks', 'minn-admin' ),
				'value' => number_format_i18n( $posture['blocks'] ),
				'hint'  => $posture['blocks'] ? __( 'blocked IPs', 'minn-admin' ) : __( 'none', 'minn-admin' ),
			),
			$events['last'],
		);
		if ( $posture['locked'] > 0 ) {
			$open[] = array( 'label' => __( 'View locked IPs ↗', 'minn-admin' ), 'href' => minn_admin_aios_lockout_url() );
		}
		if ( $posture['blocks'] > 0 ) {
			$open[] = array( 'label' => __( 'View permanent blocks ↗', 'minn-admin' ), 'href' => minn_admin_aios_perm_block_url() );
		}
		return array(
			'rows'    => $rows,
			'chart'   => minn_admin_chart_build( $n['days'], __( 'Events', 'minn-admin' ), __( 'Warnings', 'minn-admin' ) ),
			'actions' => $open,
		);
	},
) );

minn_admin_add_surface( 'all-in-one-security', function () {
	return minn_admin_aios_active() && minn_admin_aios_can();
}, function () {
	return minn_admin_activity_surface( 'aios/events', array(
		'sub'     => 'All-In-One Security',
		'cap'     => 'read', // the active check above, in the vendor's own model, is the gate
		'plugin'  => 'all-in-one-wp-security-and-firewall',
		'status'  => 'aios/status',
		'tabs'    => array( 'level', array( 'warning' => 'Warnings', 'error' => 'Errors', 'info' => 'Info' ), 'All' ),
		'columns' => array(
			array( 'key' => 'username', 'label' => __( 'Who', 'minn-admin' ) ),
			array( 'key' => 'ip', 'label' => 'IP', 'format' => 'mono', 'width' => '130px' ),
			array( 'key' => 'level', 'label' => __( 'Level', 'minn-admin' ), 'format' => 'pill', 'width' => '96px' ),
		),
		'detail'  => array( 'sectionsRoute' => 'minn-admin/v1/aios/events/{id}' ),
	) );
} );
