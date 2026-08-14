<?php
/**
 * Bundled adapter: Solid Security (better-wp-security) — lockout log +
 * posture rows.
 *
 * Solid Security records every lockout in {base_prefix}itsec_lockouts with
 * both local and GMT datetimes (the shim reads the *_gmt columns and emits
 * ISO Z). A lockout is host-, user- or username-typed; `lockout_active` is
 * their release flag, so status derives as locked (active + unexpired),
 * expired, or released. Release goes through their own
 * `$itsec_lockout->release_lockout()` (multisite-aware via base_prefix,
 * like all their lockout SQL — this shim matches). Firewall rules, scans
 * and settings stay on their screens; the System page just gets posture
 * rows read from their own module registry.
 *
 * Caps mirror the plugin exactly: everything gates through
 * `ITSEC_Core::get_required_cap()` (the dynamically granted `itsec_manage`,
 * which their user-groups feature can extend beyond admins).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_solid_security_active() {
	global $wpdb;
	if ( ! class_exists( 'ITSEC_Core' ) ) {
		return false;
	}
	$table = $wpdb->base_prefix . 'itsec_lockouts';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

function minn_admin_solid_security_can() {
	// {base_prefix}itsec_lockouts is ONE network-shared table with no site
	// column (schema.php) — rows can't be attributed to a site, so on
	// multisite this surface would show a subsite admin every other site's
	// lockout IPs and attempted usernames. Same class (and same fix) as the
	// All-In-One Security scoping in v0.28.0, except here scoping is
	// impossible: network data needs a network administrator.
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	try {
		return current_user_can( ITSEC_Core::get_required_cap() );
	} catch ( \Throwable $e ) {
		return current_user_can( 'manage_options' );
	}
}

/** Their Security screen (dashboard once onboarded, setup before). */
function minn_admin_solid_security_admin_url() {
	$onboarded = method_exists( 'ITSEC_Core', 'is_onboarded' ) && ITSEC_Core::is_onboarded();
	return admin_url( 'admin.php?page=' . ( $onboarded ? 'itsec-dashboard' : 'itsec' ) );
}

/** Display shape for one itsec_lockouts row. */
function minn_admin_solid_security_row( $r ) {
	$who = '';
	if ( ! empty( $r->lockout_username ) ) {
		$who = (string) $r->lockout_username;
	} elseif ( ! empty( $r->lockout_user ) ) {
		$user = get_userdata( (int) $r->lockout_user );
		$who  = $user ? $user->user_login : 'user #' . (int) $r->lockout_user;
	}
	$active = (int) $r->lockout_active === 1;
	$now    = gmdate( 'Y-m-d H:i:s' );
	if ( ! $active ) {
		$status = 'released';
	} elseif ( (string) $r->lockout_expire_gmt > $now ) {
		$status = 'locked';
	} else {
		$status = 'expired';
	}
	return array(
		'id'      => (int) $r->lockout_id,
		'message' => __( 'Locked out: ', 'minn-admin' ) . ( $who ? $who : ( $r->lockout_host ? $r->lockout_host : 'unknown' ) ),
		'type'    => (string) $r->lockout_type,
		'who'     => $who ? $who : '—',
		'ip'      => $r->lockout_host ? (string) $r->lockout_host : '—',
		'status'  => $status,
		'locked'  => 'locked' === $status,
		'date'    => str_replace( ' ', 'T', (string) $r->lockout_start_gmt ) . 'Z',
		'expires' => str_replace( ' ', 'T', (string) $r->lockout_expire_gmt ) . 'Z',
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_solid_security_active() || ! minn_admin_solid_security_can() ) {
		return $surfaces;
	}

	$surfaces['solid-security'] = array(
		'label'      => __( 'Activity Log', 'minn-admin' ),
		'family'     => 'activity-log',
		'sub'        => 'Solid Security',
		'icon'       => 'shield',
		// itsec_manage is dynamically granted; the filter above is the
		// real gate (the LLA-R / Gravity Forms cap-model precedent).
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/solid-security/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/solid-security/lockouts',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'kind',
				'static'   => array(
					array( 'locked', __( 'Locked out now', 'minn-admin' ) ),
				),
				'allLabel' => __( 'All lockouts', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'ip', 'label' => 'IP' ),
				array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ) ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'message', 'locked' ),
			),
			'actions'   => array(
				array(
					'label'  => __( 'Release lockout', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/solid-security/lockouts/{id}/release',
					'when'   => array( 'key' => 'locked', 'equals' => true ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_solid_security_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/solid-security/lockouts', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_solid_security_can',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->base_prefix . 'itsec_lockouts';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );

			$where = '1=1';
			$args  = array();
			if ( 'locked' === $request['kind'] ) {
				$where .= " AND lockout_active = 1 AND lockout_expire_gmt > %s";
				$args[] = gmdate( 'Y-m-d H:i:s' );
			}
			if ( $request['search'] ) {
				$like   = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
				$where .= ' AND (lockout_host LIKE %s OR lockout_username LIKE %s)';
				$args[] = $like;
				$args[] = $like;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table prefix-derived; WHERE placeholder-built.
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where} ORDER BY lockout_id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			return rest_ensure_response( array(
				'items' => array_map( 'minn_admin_solid_security_row', (array) $rows ),
				'total' => $total,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/solid-security/lockouts/(?P<id>\d+)/release', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_solid_security_can',
		'callback'            => function ( WP_REST_Request $request ) {
			global $itsec_lockout, $wpdb;
			$id = (int) $request['id'];
			if ( ! $itsec_lockout || ! method_exists( $itsec_lockout, 'release_lockout' ) ) {
				return new WP_Error( 'no_api', __( 'Solid Security\'s lockout API is not available.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}itsec_lockouts WHERE lockout_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Lockout not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$itsec_lockout->release_lockout( $id );
			$freed = minn_admin_solid_security_row( $row );
			return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Released ', 'minn-admin' ) . ( '—' !== $freed['who'] ? $freed['who'] : $freed['ip'] ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/solid-security/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_solid_security_can',
		'callback'            => function () {
			global $wpdb;
			$table  = $wpdb->base_prefix . 'itsec_lockouts';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$active = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE lockout_active = 1 AND lockout_expire_gmt > %s",
				gmdate( 'Y-m-d H:i:s' )
			) );
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$bans  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->base_prefix}itsec_bans" );
			// phpcs:enable
			$modules = array();
			try {
				$modules = (array) ITSEC_Modules::get_active_modules();
			} catch ( \Throwable $e ) {
				$modules = array();
			}
			$names   = array(
				'brute-force'         => __( 'Brute force', 'minn-admin' ),
				'network-brute-force' => __( 'Network brute force', 'minn-admin' ),
				'firewall'            => __( 'Firewall', 'minn-admin' ),
				'two-factor'          => __( 'Two-factor', 'minn-admin' ),
				'ban-users'           => __( 'Ban hosts', 'minn-admin' ),
				'file-change'         => __( 'File change detection', 'minn-admin' ),
				'malware-scheduling'  => __( 'Scheduled scans', 'minn-admin' ),
			);
			$on = array();
			foreach ( $names as $slug => $label ) {
				if ( in_array( $slug, $modules, true ) ) {
					$on[] = $label;
				}
			}
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => __( 'Locked out now', 'minn-admin' ),
						'value' => $active ? (string) $active : 'Nobody',
					),
					array(
						'label' => __( 'Lockouts all-time', 'minn-admin' ),
						'value' => (string) $total,
					),
					array(
						'label' => __( 'Banned hosts', 'minn-admin' ),
						'value' => (string) $bans,
					),
					array(
						'label' => __( 'Protection on', 'minn-admin' ),
						'value' => $on ? implode( ' · ', $on ) : __( 'No protection modules active', 'minn-admin' ),
					),
				),
				'actions' => array(
					array( 'label' => __( 'Open Solid Security ↗', 'minn-admin' ), 'href' => minn_admin_solid_security_admin_url() ),
				),
			) );
		},
	) );
} );

/**
 * Security posture rows for the System page (the Wordfence precedent):
 * read from Solid Security's own module registry, Throwable-guarded so a
 * plugin change drops rows rather than fatals. Returns [] when inactive.
 *
 * @return array[] of { label, status, detail }
 */
function minn_admin_solid_security_checks() {
	// The System page reads this directly at manage_options, which is per
	// site on multisite. Firewall mode, scan age and unresolved-issue counts
	// describe the network, so gate here rather than at the call site.
	if ( ! Minn_Admin::network_owner() ) {
		return array();
	}
	if ( ! class_exists( 'ITSEC_Core' ) || ! class_exists( 'ITSEC_Modules' ) ) {
		return array();
	}
	$rows = array();
	try {
		$modules = (array) ITSEC_Modules::get_active_modules();
		if ( in_array( 'brute-force', $modules, true ) ) {
			$extra  = array();
			if ( in_array( 'firewall', $modules, true ) ) {
				$extra[] = 'firewall';
			}
			if ( in_array( 'two-factor', $modules, true ) ) {
				$extra[] = 'two-factor';
			}
			$rows[] = array(
				'label'  => __( 'Solid Security', 'minn-admin' ),
				'status' => 'pass',
				'detail' => __( 'Brute force protection is on', 'minn-admin' ) . ( $extra ? ' (with ' . implode( ' and ', $extra ) . ')' : '' ),
				'href'   => minn_admin_solid_security_admin_url(),
			);
		} else {
			$rows[] = array(
				'label'  => __( 'Solid Security', 'minn-admin' ),
				'status' => 'warn',
				'detail' => __( 'Brute force protection is turned off', 'minn-admin' ),
				'href'   => minn_admin_solid_security_admin_url(),
			);
		}
	} catch ( \Throwable $e ) {
		// A module-registry change just drops the row.
	}
	return $rows;
}
