<?php
/**
 * Bundled adapter: Solid Security (better-wp-security) — lockout log +
 * posture rows.
 *
 * Solid Security records every lockout in {base_prefix}itsec_lockouts with
 * both local and GMT datetimes (the kernel resource reads the *_gmt columns
 * and sends them with a Z). A lockout is host-, user- or username-typed; `lockout_active` is
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
	return class_exists( 'ITSEC_Core' ) && Minn_Admin_Kernel_SQL::exists( array( 'table' => 'itsec_lockouts', 'network' => true ) );
}

/*
 * Solid Security keeps its Network Brute Force API key and secret in
 * itsec-storage. Declared whether or not it is active (its row outlives it).
 */
minn_admin_secrets( 'better-wp-security', array( 'options' => array( 'itsec-storage' ) ) );

function minn_admin_solid_security_can() {
	// {base_prefix}itsec_lockouts is ONE network-shared table with no site
	// column (schema.php) — rows can't be attributed to a site, so on
	// multisite this surface would show a subsite admin every other site's
	// lockout IPs and attempted usernames. Same class (and same fix) as the
	// All-In-One Security scoping in v0.28.0, except here scoping is
	// impossible: network data needs a network administrator.
	if ( ! Minn_Admin::install_owner() ) {
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

/**
 * Their label for a lockout's module ("firewall" is "Firewall Rule"): the
 * `label` each module registers with their lockout registry, which their own
 * screens print. A module this site does not register is tidied.
 *
 * @param string $type Stored lockout_type.
 * @return string
 */
function minn_admin_solid_security_type_label( $type ) {
	global $itsec_lockout;
	$type = (string) $type;
	try {
		$modules = is_object( $itsec_lockout ) && method_exists( $itsec_lockout, 'get_lockout_modules' ) ? (array) $itsec_lockout->get_lockout_modules() : array();
	} catch ( \Throwable $e ) {
		$modules = array();
	}
	if ( isset( $modules[ $type ]['label'] ) && is_scalar( $modules[ $type ]['label'] ) && '' !== (string) $modules[ $type ]['label'] ) {
		return Minn_Admin::plain_text( $modules[ $type ]['label'] );
	}
	return ucwords( str_replace( array( '-', '_' ), ' ', $type ) );
}

/** Display shape for one itsec_lockouts row (dates as stored, GMT). */
function minn_admin_solid_security_row( $r ) {
	$r   = (object) $r;
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
		'type'    => minn_admin_solid_security_type_label( $r->lockout_type ),
		'who'     => $who ? $who : '—',
		'ip'      => $r->lockout_host ? (string) $r->lockout_host : '—',
		'status'  => $status,
		'locked'  => 'locked' === $status,
		'date'    => (string) $r->lockout_start_gmt,
		'expires' => (string) $r->lockout_expire_gmt,
	);
}

// One network table with no site column: network data, so the gate asks for
// the network's owner (minn_admin_solid_security_can).
minn_admin_resource( 'solid-security/lockouts', function () {
	return array(
		'active'  => 'minn_admin_solid_security_active',
		'gates'   => array( 'view' => 'minn_admin_solid_security_can', 'manage' => 'minn_admin_solid_security_can' ),
		'labels'  => array( 'not_found' => __( 'Lockout not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'itsec_lockouts',
			'network' => true,
			'key'     => 'lockout_id',
			'columns' => array( 'lockout_id', 'lockout_type', 'lockout_start_gmt', 'lockout_expire_gmt', 'lockout_host', 'lockout_user', 'lockout_username', 'lockout_active' ),
		),
		'list'    => array(
			'facets' => array(
				array( 'param' => 'kind', 'values' => array(
					'locked' => function () {
						return array( 'lockout_active' => 1, 'lockout_expire_gmt' => array( 'op' => '>', 'value' => gmdate( 'Y-m-d H:i:s' ) ) );
					},
				) ),
			),
			'search' => array( 'columns' => array( 'lockout_host', 'lockout_username' ) ),
			'range'  => array( 'column' => 'lockout_start_gmt', 'clock' => 'utc' ),
			'order'  => array( 'column' => 'lockout_id' ),
			'row'    => 'minn_admin_solid_security_row',
			'fields' => array(
				'id'      => 'int',
				'message' => 'text',
				'type'    => 'text',
				'who'     => 'text',
				'ip'      => 'text',
				'status'  => array( 'type' => 'enum', 'values' => array( 'locked', 'expired', 'released' ) ),
				'locked'  => 'bool',
				'date'    => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'z' ),
				'expires' => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'z' ),
			),
		),
		'actions' => array(
			// Through their own release_lockout(), which clears the active flag.
			'release' => array(
				'gate'   => 'manage',
				'run'    => function ( $id ) {
					global $itsec_lockout;
					if ( ! $itsec_lockout || ! method_exists( $itsec_lockout, 'release_lockout' ) ) {
						return new WP_Error( 'no_api', __( 'Solid Security\'s lockout API is not available.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return $itsec_lockout->release_lockout( $id );
				},
				'expect' => array( 'lockout_active' => array( 0 ) ),
				'reply'  => function ( $id, $fresh ) {
					$freed = minn_admin_solid_security_row( $fresh );
					return array( 'message' => __( 'Released ', 'minn-admin' ) . ( '—' !== $freed['who'] ? $freed['who'] : $freed['ip'] ) );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'solid-security/status', array(
	'active' => 'minn_admin_solid_security_active',
	'gate'   => 'minn_admin_solid_security_can',
	'run'    => function () {
		$lockouts = array( 'table' => 'itsec_lockouts', 'network' => true );
		$active   = Minn_Admin_Kernel_SQL::count( $lockouts, array( 'lockout_active' => 1, 'lockout_expire_gmt' => array( 'op' => '>', 'value' => gmdate( 'Y-m-d H:i:s' ) ) ) );
		$total    = Minn_Admin_Kernel_SQL::count( $lockouts );
		$bans     = Minn_Admin_Kernel_SQL::exists( array( 'table' => 'itsec_bans', 'network' => true ) ) ? Minn_Admin_Kernel_SQL::count( array( 'table' => 'itsec_bans', 'network' => true ) ) : 0;
		// Fourteen site-local days of lockouts; lockout_start_gmt is UTC.
		$days     = Minn_Admin_Kernel_SQL::chart_days( array( 'table' => $lockouts, 'column' => 'lockout_start_gmt', 'clock' => 'utc' ) );
		try {
			$modules = (array) ITSEC_Modules::get_active_modules();
		} catch ( \Throwable $e ) {
			$modules = array();
		}
		$names = array(
			'brute-force'         => __( 'Brute force', 'minn-admin' ),
			'network-brute-force' => __( 'Network brute force', 'minn-admin' ),
			'firewall'            => __( 'Firewall', 'minn-admin' ),
			'two-factor'          => __( 'Two-factor', 'minn-admin' ),
			'ban-users'           => __( 'Ban hosts', 'minn-admin' ),
			'file-change'         => __( 'File change detection', 'minn-admin' ),
			'malware-scheduling'  => __( 'Scheduled scans', 'minn-admin' ),
		);
		$on    = array();
		foreach ( $names as $slug => $label ) {
			if ( in_array( $slug, $modules, true ) ) {
				$on[] = $label;
			}
		}
		return array(
			'rows'    => array(
				array( 'label' => __( 'Locked out now', 'minn-admin' ), 'value' => $active ? (string) $active : 'Nobody' ),
				array( 'label' => __( 'Lockouts all-time', 'minn-admin' ), 'value' => (string) $total ),
				array( 'label' => __( 'Banned hosts', 'minn-admin' ), 'value' => (string) $bans ),
				array( 'label' => __( 'Protection on', 'minn-admin' ), 'value' => $on ? implode( ' · ', $on ) : __( 'No protection modules active', 'minn-admin' ) ),
			),
			'chart'   => minn_admin_chart_build( $days, __( 'Lockouts', 'minn-admin' ) ),
			'actions' => array(
				array( 'label' => __( 'Open Solid Security ↗', 'minn-admin' ), 'href' => minn_admin_solid_security_admin_url() ),
			),
		);
	},
) );

minn_admin_add_surface( 'solid-security', function () {
	return minn_admin_solid_security_active() && minn_admin_solid_security_can();
}, function () {
	// itsec_manage is dynamically granted, so the active check above is the
	// real gate (the LLA-R / Gravity Forms cap-model precedent).
	return minn_admin_activity_surface( 'solid-security/lockouts', array(
		'cap'     => 'read', // the active check above, in the vendor's own model, is the gate
		'sub'     => 'Solid Security',
		'plugin'  => array( 'better-wp-security', 'ithemes-security-pro' ),
		'status'  => 'solid-security/status',
		'tabs'    => array( 'kind', array( 'locked' => __( 'Locked out now', 'minn-admin' ) ), __( 'All lockouts', 'minn-admin' ) ),
		'columns' => array(
			array( 'key' => 'ip', 'label' => 'IP' ),
			array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ) ),
			array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
		),
		'when'    => array( 'utc' => true ),
		'detail'  => array( 'skip' => array( 'message', 'locked' ) ),
		'actions' => array(
			Minn_Admin_Kernel::ui( 'solid-security/lockouts', 'release', array(
				'label' => __( 'Release lockout', 'minn-admin' ),
				'key'   => 'locked',
				'when'  => true,
			) ),
		),
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
	if ( ! Minn_Admin::install_owner() ) {
		return array();
	}
	// Ask the same question the surface and the routes ask: their required
	// capability is granted through a user-groups matcher, not by holding
	// manage_options.
	if ( ! minn_admin_solid_security_can() ) {
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
