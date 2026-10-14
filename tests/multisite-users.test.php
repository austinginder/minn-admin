<?php
/**
 * A network site's Users list, as wp-admin's Users screen shows it.
 *
 * On a network, edit_user maps to manage_network_users, so a site's
 * administrator may edit no account but their own. Minn's list dropped
 * every row its caller could not edit, and a site administrator saw only
 * themselves. The members they may promote or remove are listed again; a
 * Shop Manager, who holds list_users and nothing else, still sees only what
 * they may edit.
 *
 * Accounts are made inside a transaction that is rolled back.
 *
 * Run: wp eval-file tests/multisite-users.test.php --user=admin --path=<network root> --url=<a site other than the main one>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
if ( ! is_multisite() || is_main_site() ) {
	echo "SKIP  run on a network site other than the main one (--url)\n";
	return;
}
global $wpdb;
$admin = get_current_user_id();
$list  = function () {
	$res = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/users' ) );
	return array( $res->get_status(), array_map( 'intval', array_column( (array) $res->get_data(), 'id' ) ) );
};
$wpdb->query( 'START TRANSACTION' );
try {
	$make = function ( $role ) {
		$login = 'minnmsu' . $role . wp_rand( 1000, 9999 );
		$id    = wp_insert_user( array( 'user_login' => str_replace( '_', '', $login ), 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
		return is_int( $id ) && $id > 1 ? $id : 0;
	};
	$site_admin = $make( 'administrator' );
	$member     = $make( 'author' );
	$manager    = get_role( 'shop_manager' ) ? $make( 'shop_manager' ) : 0;
	rest_get_server();

	wp_set_current_user( $site_admin );
	list( $st, $ids ) = $list();
	$check( 'a site administrator gets the Users list', 200 === $st, (string) $st );
	$check( 'and sees the site\'s other members, not only themselves', in_array( $member, $ids, true ) && in_array( $site_admin, $ids, true ), wp_json_encode( $ids ) );
	$check( 'control: they still cannot edit that member', ! current_user_can( 'edit_user', $member ) );

	if ( $manager ) {
		wp_set_current_user( $manager );
		list( $st, $ids ) = $list();
		$check( 'a Shop Manager sees no member they cannot edit', 200 === $st && ! in_array( $member, $ids, true ) && ! in_array( $site_admin, $ids, true ), $st . ' ' . wp_json_encode( $ids ) );
	} else {
		echo "SKIP  no shop_manager role on this site\n";
	}
} finally {
	wp_set_current_user( $admin );
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
}

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nmultisite-users: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
