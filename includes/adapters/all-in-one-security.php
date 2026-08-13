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
 * which a SUBSITE administrator holds, so without the same predicate it would
 * hand a tenant every other tenant's acting usernames, client IPs and, on
 * failed logins, the ATTEMPTED usernames from neighbouring sites.
 *
 * @return array{0:string,1:array} SQL fragment to append, and its args.
 */
function minn_admin_aios_site_scope() {
	if ( Minn_Admin::network_owner() ) {
		return array( '', array() );
	}
	return array( ' AND site_id = %d', array( get_current_blog_id() ) );
}

/** Temporary lockouts live on the blog prefix (not base_prefix). */
function minn_admin_aios_lockdown_table() {
	if ( defined( 'AIOWPSEC_TBL_LOGIN_LOCKOUT' ) ) {
		return AIOWPSEC_TBL_LOGIN_LOCKOUT;
	}
	global $wpdb;
	return $wpdb->prefix . 'aiowps_login_lockdown';
}

/** Permanent blocks table (blog prefix). Unblock = DELETE the row. */
function minn_admin_aios_perm_block_table() {
	if ( defined( 'AIOWPSEC_TBL_PERM_BLOCK' ) ) {
		return AIOWPSEC_TBL_PERM_BLOCK;
	}
	global $wpdb;
	return $wpdb->prefix . 'aiowps_permanent_block';
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
	global $wpdb;
	$out = array(
		'locked'     => 0,
		'blocks'     => 0,
		'failed_24h' => 0,
	);

	$lock = minn_admin_aios_lockdown_table();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from constant/prefix.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lock ) ) === $lock ) {
		// Match AIOWPSecurity_Utility::get_locked_ips(): still locked while released is in the future.
		$out['locked'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$lock} WHERE released > UNIX_TIMESTAMP()" );
	}

	$perm = minn_admin_aios_perm_block_table();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $perm ) ) === $perm ) {
		// Unblock deletes the row; every remaining row is blocked.
		$out['blocks'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$perm}" );
	}

	$audit = minn_admin_aios_table();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit ) ) === $audit ) {
		list( $scope_sql, $scope_args ) = minn_admin_aios_site_scope();
		$out['failed_24h'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$audit} WHERE event_type = %s AND created >= %d{$scope_sql}",
			array_merge( array( 'failed_login', time() - DAY_IN_SECONDS ), $scope_args )
		) );
	}
	// phpcs:enable

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
			'label'  => 'All-In-One Security',
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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	// Enforce what the 'cap' => 'read' comment claims, the way
	// solid-security.php and limit-login-attempts.php already do.
	if ( ! minn_admin_aios_active() || ! minn_admin_aios_can() ) {
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
			list( $scope_sql, $scope_args ) = minn_admin_aios_site_scope();
			$where    = array( '1=1' );
			$args     = array();
			if ( $scope_sql ) {
				$where[] = 'site_id = %d';
				$args    = array_merge( $args, $scope_args );
			}
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
			// Scope like the list route. 404 rather than 403 so the endpoint
			// never confirms that an out-of-scope id exists.
			list( $scope_sql, $scope_args ) = minn_admin_aios_site_scope();
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d{$scope_sql}",
				array_merge( array( (int) $request['id'] ), $scope_args )
			) );
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
			list( $scope_sql, $scope_args ) = minn_admin_aios_site_scope();
			$scoped = function ( $extra, $extra_args ) use ( $wpdb, $table, $scope_sql, $scope_args ) {
				$sql  = "SELECT COUNT(*) FROM {$table} WHERE 1=1{$extra}{$scope_sql}";
				$args = array_merge( $extra_args, $scope_args );
				return (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) );
			};
			$total = $scoped( '', array() );
			$day   = $scoped( ' AND created >= %d', array( $now - DAY_IN_SECONDS ) );
			$week  = $scoped( ' AND created >= %d', array( $now - 7 * DAY_IN_SECONDS ) );
			$warn  = $scoped( " AND created >= %d AND level IN ('warning','error','fatal')", array( $now - 7 * DAY_IN_SECONDS ) );
			$last_sql  = "SELECT created FROM {$table} WHERE 1=1{$scope_sql} ORDER BY id DESC LIMIT 1";
			$last  = $scope_args ? $wpdb->get_var( $wpdb->prepare( $last_sql, $scope_args ) ) : $wpdb->get_var( $last_sql );
			// phpcs:enable
			$posture = minn_admin_aios_login_posture();
			$rows    = array(
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
					'label' => 'Failed logins (24h)',
					'value' => number_format_i18n( $posture['failed_24h'] ),
					'hint'  => 'from the audit log',
				),
				array(
					'label' => 'Locked out now',
					'value' => $posture['locked'] ? number_format_i18n( $posture['locked'] ) : 'Nobody',
					'hint'  => $posture['locked'] ? 'temporary login lockdowns' : 'no active lockdowns',
				),
				array(
					'label' => 'Permanent blocks',
					'value' => number_format_i18n( $posture['blocks'] ),
					'hint'  => $posture['blocks'] ? 'blocked IPs' : 'none',
				),
				array(
					'label' => 'Last event',
					'value' => $last ? human_time_diff( (int) $last, time() ) . ' ago' : '—',
				),
			);
			$actions = array(
				array( 'label' => 'Open All-In-One Security ↗', 'href' => minn_admin_aios_admin_url() ),
			);
			if ( $posture['locked'] > 0 ) {
				$actions[] = array( 'label' => 'View locked IPs ↗', 'href' => minn_admin_aios_lockout_url() );
			}
			if ( $posture['blocks'] > 0 ) {
				$actions[] = array( 'label' => 'View permanent blocks ↗', 'href' => minn_admin_aios_perm_block_url() );
			}
			return rest_ensure_response( array(
				'rows'    => $rows,
				'actions' => $actions,
			) );
		},
	) );
} );
