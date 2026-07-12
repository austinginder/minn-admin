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
		'message' => 'Locked out: ' . ( $who ? $who : ( $r->lockout_host ? $r->lockout_host : 'unknown' ) ),
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
		'label'      => 'Activity Log',
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
					array( 'locked', 'Locked out now' ),
				),
				'allLabel' => 'All lockouts',
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'ip', 'label' => 'IP' ),
				array( 'key' => 'type', 'label' => 'Type' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'message', 'locked' ),
			),
			'actions'   => array(
				array(
					'label'  => 'Release lockout',
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
				return new WP_Error( 'no_api', 'Solid Security\'s lockout API is not available.', array( 'status' => 500 ) );
			}
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->base_prefix}itsec_lockouts WHERE lockout_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$id
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Lockout not found', array( 'status' => 404 ) );
			}
			$itsec_lockout->release_lockout( $id );
			$freed = minn_admin_solid_security_row( $row );
			return rest_ensure_response( array( 'ok' => true, 'message' => 'Released ' . ( '—' !== $freed['who'] ? $freed['who'] : $freed['ip'] ) ) );
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
				'brute-force'         => 'Brute force',
				'network-brute-force' => 'Network brute force',
				'firewall'            => 'Firewall',
				'two-factor'          => 'Two-factor',
				'ban-users'           => 'Ban hosts',
				'file-change'         => 'File change detection',
				'malware-scheduling'  => 'Scheduled scans',
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
						'label' => 'Locked out now',
						'value' => $active ? (string) $active : 'Nobody',
					),
					array(
						'label' => 'Lockouts all-time',
						'value' => (string) $total,
					),
					array(
						'label' => 'Banned hosts',
						'value' => (string) $bans,
					),
					array(
						'label' => 'Protection on',
						'value' => $on ? implode( ' · ', $on ) : 'No protection modules active',
					),
				),
				'actions' => array(
					array( 'label' => 'Open Solid Security ↗', 'href' => minn_admin_solid_security_admin_url() ),
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
				'label'  => 'Solid Security',
				'status' => 'pass',
				'detail' => 'Brute force protection is on' . ( $extra ? ' (with ' . implode( ' and ', $extra ) . ')' : '' ),
				'href'   => minn_admin_solid_security_admin_url(),
			);
		} else {
			$rows[] = array(
				'label'  => 'Solid Security',
				'status' => 'warn',
				'detail' => 'Brute force protection is turned off',
				'href'   => minn_admin_solid_security_admin_url(),
			);
		}
	} catch ( \Throwable $e ) {
		// A module-registry change just drops the row.
	}
	return $rows;
}
