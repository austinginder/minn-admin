<?php
/**
 * On an install with several networks, the database browser, the logs and
 * the wp-config switches belong to the main network's super admins.
 *
 * WP Multi Network installs share one database and one set of logs, and
 * is_super_admin() answers only for the current network, so a secondary
 * network's super admin was taken for the owner of install-wide state: the
 * browser listed the primary network's main-site tables and every network's
 * settings, and the debug log could be read and cleared.
 *
 * Asks the system routes as the current network's super admin from a
 * secondary network (read-only requests), then as the main network's.
 *
 * Run on the minnms lab from its second network, as that network's super admin:
 * wp eval-file tests/multi-network-system.test.php --user=minn-net2-admin --url=https://lab2.minnms.localhost/ --path=<install>
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

if ( ! is_multisite() || ! function_exists( 'switch_to_network' ) || is_main_network() || ! is_super_admin() ) {
	echo "SKIP  needs WP Multi Network, a secondary network, and that network's super admin\n";
	return;
}
$status = static function ( $route ) {
	return rest_do_request( new WP_REST_Request( 'GET', $route ) )->get_status();
};

$check( 'this account is a super admin of this network only', is_super_admin() && ! in_array( wp_get_current_user()->user_login, (array) get_network_option( get_main_network_id(), 'site_admins', array() ), true ) );
$check( 'the database browser is refused here', 403 === $status( '/minn-admin/v1/db/tables' ), (string) $status( '/minn-admin/v1/db/tables' ) );
$check( 'the logs are refused here', 403 === $status( '/minn-admin/v1/system/logs' ), (string) $status( '/minn-admin/v1/system/logs' ) );

// The main network's super admin, in the main network.
$was     = get_current_user_id();
$admins  = (array) get_network_option( get_main_network_id(), 'site_admins', array() );
$main_su = $admins ? get_user_by( 'login', (string) reset( $admins ) ) : null;
if ( $main_su ) {
	switch_to_network( get_main_network_id() );
	wp_set_current_user( $main_su->ID );
	$check( 'control: the main network\'s super admin keeps the browser', 200 === $status( '/minn-admin/v1/db/tables' ), (string) $status( '/minn-admin/v1/db/tables' ) );
	wp_set_current_user( $was );
	restore_current_network();
}

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
