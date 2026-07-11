<?php
/**
 * Bundled adapter: Limit Login Attempts Reloaded (lockout log).
 *
 * LLA-R keeps everything in options through its own Config store (prefix
 * limit_login_): the lockout log is `logged` (ip → username → { counter,
 * date, gateway, unlocked }), active lockouts are `lockouts` (ip → expiry),
 * and failed-attempt stats live in `retries_stats`. No tables, no REST.
 * This shim flattens the log into an Activity Log family member with a
 * status card (who's locked out right now, failed attempts today, policy)
 * and an Unlock action that mirrors the plugin's own ajax_unlock handler
 * byte-for-byte through its Config class: drop the IP from `lockouts`,
 * mark the log row unlocked. Plugin settings stay on its own screen.
 *
 * Caps mirror the plugin exactly: admins hold manage_options, and LLA-R
 * grants its lesser-admin role the `llar_admin` capability.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_llar_active() {
	return class_exists( 'LLAR\\Core\\Config' );
}

function minn_admin_llar_can() {
	return current_user_can( 'manage_options' ) || current_user_can( 'llar_admin' );
}

/** Human label for a detect_gateway() value; unknown values pass through. */
function minn_admin_llar_gateway_label( $gateway ) {
	$map = array(
		'wp_login'        => 'Login form',
		'wp_lostpassword' => 'Password reset',
		'wp_register'     => 'Registration',
		'xmlrpc'          => 'XML-RPC',
		'wp_woo_login'    => 'WooCommerce login',
	);
	$g = (string) $gateway;
	return isset( $map[ $g ] ) ? $map[ $g ] : ( $g ? $g : '—' );
}

/**
 * The `logged` option flattened to display rows, newest first. Row ids are
 * md5(ip|user) — stable, and safe to ride a REST path segment (raw IPs and
 * usernames are not).
 */
function minn_admin_llar_rows() {
	$log      = \LLAR\Core\Config::get( 'logged' );
	$lockouts = (array) \LLAR\Core\Config::get( 'lockouts' );
	$rows     = array();
	if ( ! is_array( $log ) ) {
		return $rows;
	}
	foreach ( $log as $ip => $users ) {
		if ( ! is_array( $users ) ) {
			continue;
		}
		foreach ( $users as $user => $data ) {
			// Ancient rows may be a bare counter (their own normalization).
			if ( ! is_array( $data ) ) {
				$data = array( 'counter' => (int) $data );
			}
			$unlocked = ! empty( $data['unlocked'] );
			$locked   = ! $unlocked && isset( $lockouts[ $ip ] ) && (int) $lockouts[ $ip ] > time();
			$rows[]   = array(
				'id'       => md5( $ip . '|' . $user ),
				'message'  => 'Locked out: ' . $user,
				'who'      => (string) $user,
				'ip'       => (string) $ip,
				'attempts' => isset( $data['counter'] ) ? (int) $data['counter'] : 1,
				'gateway'  => minn_admin_llar_gateway_label( isset( $data['gateway'] ) ? $data['gateway'] : '' ),
				'status'   => $unlocked ? 'unlocked' : ( $locked ? 'locked' : 'expired' ),
				'locked'   => $locked,
				// `date` is written with time(): a UTC epoch.
				'date'     => ! empty( $data['date'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $data['date'] ) : '',
			);
		}
	}
	usort( $rows, function ( $a, $b ) {
		return strcmp( $b['date'], $a['date'] );
	} );
	return $rows;
}

/** Failed attempts in the last 24h — mirrors get_local_retries_count_for_last_day(). */
function minn_admin_llar_retries_last_day() {
	$stats = \LLAR\Core\Config::get( 'retries_stats' );
	$count = 0;
	if ( is_array( $stats ) ) {
		$cutoff = time() - DAY_IN_SECONDS;
		foreach ( $stats as $key => $n ) {
			if ( is_numeric( $key ) && (int) $key > $cutoff ) {
				$count += (int) $n;
			} elseif ( ! is_numeric( $key ) && date_i18n( 'Y-m-d' ) === $key ) {
				$count += (int) $n;
			}
		}
	}
	return $count;
}

/** The plugin's own settings screen (top-level or Settings submenu, per its option). */
function minn_admin_llar_admin_url() {
	$top = \LLAR\Core\Config::get( 'show_top_level_menu_item' );
	return admin_url( ( $top ? 'admin.php' : 'options-general.php' ) . '?page=limit-login-attempts' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_llar_active() || ! minn_admin_llar_can() ) {
		return $surfaces;
	}

	$surfaces['limit-login-attempts'] = array(
		'label'      => 'Activity Log',
		'family'     => 'activity-log',
		'sub'        => 'Limit Login Attempts',
		'icon'       => 'shield',
		// llar_admin holders lack manage_options; the filter above is the
		// real gate (the Gravity Forms cap-model precedent).
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/llar/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/llar/log',
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
				array( 'key' => 'attempts', 'label' => 'Attempts', 'format' => 'num' ),
				array( 'key' => 'gateway', 'label' => 'Via' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'message', 'locked' ),
			),
			'actions'   => array(
				array(
					'label'  => 'Unlock IP',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/llar/log/{id}/unlock',
					'when'   => array( 'key' => 'locked', 'equals' => true ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_llar_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/llar/log', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_llar_can',
		'callback'            => function ( WP_REST_Request $request ) {
			$rows = minn_admin_llar_rows();
			if ( 'locked' === $request['kind'] ) {
				$rows = array_values( array_filter( $rows, function ( $r ) {
					return $r['locked'];
				} ) );
			}
			if ( $request['search'] ) {
				$q    = strtolower( (string) $request['search'] );
				$rows = array_values( array_filter( $rows, function ( $r ) use ( $q ) {
					return false !== strpos( strtolower( $r['who'] ), $q ) || false !== strpos( strtolower( $r['ip'] ), $q );
				} ) );
			}
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			return rest_ensure_response( array(
				'items' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $rows ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/llar/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_llar_can',
		'callback'            => function () {
			$lockouts = (array) \LLAR\Core\Config::get( 'lockouts' );
			$active   = array();
			foreach ( $lockouts as $ip => $until ) {
				if ( (int) $until > time() ) {
					$active[] = (string) $ip;
				}
			}
			$retries  = (int) \LLAR\Core\Config::get( 'allowed_retries' );
			$duration = (int) \LLAR\Core\Config::get( 'lockout_duration' );
			$total    = (int) \LLAR\Core\Config::get( 'lockouts_total' );
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => 'Locked out now',
						'value' => $active ? count( $active ) . ' IP' . ( 1 === count( $active ) ? '' : 's' ) : 'Nobody',
						'hint'  => $active ? implode( ' · ', array_slice( $active, 0, 3 ) ) . ( count( $active ) > 3 ? ' …' : '' ) : '',
					),
					array(
						'label' => 'Failed attempts (24h)',
						'value' => (string) minn_admin_llar_retries_last_day(),
					),
					array(
						'label' => 'Lockouts all-time',
						'value' => (string) $total,
					),
					array(
						'label' => 'Policy',
						'value' => $retries ? sprintf( '%d retries, then a %s lockout', $retries, human_time_diff( 0, max( 60, $duration ) ) ) : '—',
					),
				),
				'actions' => array(
					array( 'label' => 'Open Limit Login Attempts ↗', 'href' => minn_admin_llar_admin_url() ),
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/llar/log/(?P<id>[a-f0-9]{32})/unlock', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_llar_can',
		'callback'            => function ( WP_REST_Request $request ) {
			$id  = (string) $request['id'];
			$log = \LLAR\Core\Config::get( 'logged' );
			if ( ! is_array( $log ) ) {
				return new WP_Error( 'minn_llar_missing', 'No lockout log.', array( 'status' => 404 ) );
			}
			foreach ( $log as $ip => $users ) {
				foreach ( (array) $users as $user => $data ) {
					if ( md5( $ip . '|' . $user ) !== $id ) {
						continue;
					}
					// Mirror the plugin's own ajax_unlock: drop the active
					// lockout, mark the log row unlocked, both through Config.
					$lockouts = (array) \LLAR\Core\Config::get( 'lockouts' );
					if ( isset( $lockouts[ $ip ] ) ) {
						unset( $lockouts[ $ip ] );
						\LLAR\Core\Config::update( 'lockouts', $lockouts );
					}
					if ( ! is_array( $log[ $ip ][ $user ] ) ) {
						$log[ $ip ][ $user ] = array( 'counter' => (int) $log[ $ip ][ $user ] );
					}
					$log[ $ip ][ $user ]['unlocked'] = true;
					\LLAR\Core\Config::update( 'logged', $log );
					return rest_ensure_response( array( 'ok' => true, 'message' => 'Unlocked ' . $ip ) );
				}
			}
			return new WP_Error( 'minn_llar_missing', 'That lockout is no longer in the log.', array( 'status' => 404 ) );
		},
	) );
} );
