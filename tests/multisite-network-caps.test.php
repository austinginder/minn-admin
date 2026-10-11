<?php
/**
 * Network routes read network capabilities on the main site, as core's
 * network admin does.
 *
 * Core redirects every network-admin request to the main site, so a network
 * capability that only some subsite's role table grants never counts there.
 * Minn's network routes answer on every site and asked current_user_can() in
 * the site the request addressed, so a "network manager" role on one subsite
 * could list and edit the network, and make an account super admin.
 *
 * Creates a role on this subsite only, with the network capabilities, and an
 * account holding it; asks the network routes as that account from here,
 * using requests that change nothing whatever the answer (a read, and a
 * super-admin grant to oneself, which the route refuses after its gate);
 * then removes both.
 *
 * Run from a site that is not the main one, as a super admin:
 * wp eval-file tests/multisite-network-caps.test.php --user=admin --url=https://minnmsd.localhost/store/ --path=<network>
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

if ( ! is_multisite() || is_main_site() || ! is_super_admin() ) {
	echo "SKIP  needs a network, a site other than the main one, and a super admin\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

$call = static function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

$admin = get_current_user_id();
$role  = 'minn_netmgr_probe';
add_role( $role, 'Minn network manager probe', array( 'read' => true, 'manage_network' => true, 'manage_sites' => true, 'manage_network_users' => true, 'manage_network_options' => true, 'edit_users' => true, 'list_users' => true ) );
$login = 'minn-netmgr-' . wp_rand( 1000, 9999 );
$uid   = (int) wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
try {
	wp_set_current_user( $uid );
	$check( 'the probe account holds the network caps here, through this site\'s role', current_user_can( 'manage_sites' ) && current_user_can( 'manage_network_options' ) && ! is_super_admin() );
	list( $st ) = $call( 'GET', '/minn-admin/v1/network/sites' );
	$check( 'listing the network\'s sites is refused', 403 === $st || 401 === $st, (string) $st );
	list( $st ) = $call( 'GET', '/minn-admin/v1/network/users' );
	$check( 'listing the network\'s accounts is refused', 403 === $st || 401 === $st, (string) $st );
	list( $st ) = $call( 'GET', '/minn-admin/v1/network/settings/general' );
	$check( 'reading the network settings is refused', 403 === $st || 401 === $st, (string) $st );
	list( $st, $d ) = $call( 'POST', '/minn-admin/v1/network/users/' . $uid . '/super', array( 'on' => true ) );
	$check( 'the super-admin route refuses at its gate', 403 === $st || 401 === $st, $st . ' ' . wp_json_encode( $d['code'] ?? null ) );

	wp_set_current_user( $admin );
	list( $st ) = $call( 'GET', '/minn-admin/v1/network/sites' );
	$check( 'control: a super admin still lists the sites from here', 200 === $st, (string) $st );
} finally {
	wp_set_current_user( $admin );
	if ( is_super_admin( $uid ) ) {
		revoke_super_admin( $uid );
	}
	wpmu_delete_user( $uid );
	remove_role( $role );
}

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
