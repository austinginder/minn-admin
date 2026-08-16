<?php
/**
 * v0.31 POST /users/add-existing tenancy regressions (multisite only).
 *
 * The route is gated on promote_users, which every subsite administrator
 * holds. wp-admin's own Add Existing User flow puts two further gates in
 * front of the same action, and neither was reproduced here:
 *
 *  1. Looking an account up by USERNAME is a network administrator's
 *     privilege (user-new.php redirects everyone else with update=enter_email),
 *     because the answer is an existence oracle over the whole network's
 *     wp_users table.
 *
 *  2. Attaching the account outright needs manage_network_users. Everyone
 *     else sends a confirmation link and waits, so joining a site stays the
 *     account holder's decision.
 *
 * A super administrator as the TARGET also had no protection, though the
 * sibling remove-from-site handler already refused one.
 *
 * Run on a multisite install, against a SUBSITE:
 *   wp eval-file tests/security-v031-add-existing.test.php \
 *     --user=admin --url=https://store.minnms.localhost
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};

if ( ! is_multisite() ) {
	echo "SKIP  not a multisite install\n";
	return;
}
$blog_id = get_current_blog_id();
if ( 1 === $blog_id ) {
	echo "SKIP  run against a SUBSITE, not the network's main site (pass --url=)\n";
	return;
}

$mk = function ( $login, $email ) {
	$id = username_exists( $login );
	if ( ! $id ) {
		$id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 20 ),
			)
		);
	}
	return (int) $id;
};

// A plain administrator of THIS site, with no standing on the network.
$sub_admin = $mk( 'minn_sub_admin', 'minn_sub_admin@example.com' );
add_user_to_blog( $blog_id, $sub_admin, 'administrator' );
revoke_super_admin( $sub_admin );

// An account that exists on the network but is not a member of this site.
$target = $mk( 'minn_probe_target', 'minn_probe_target@example.com' );
remove_user_from_blog( $target, $blog_id );

// A network administrator who is likewise not a member of this site.
$net_admin = $mk( 'minn_probe_netadmin', 'minn_probe_netadmin@example.com' );
remove_user_from_blog( $net_admin, $blog_id );
grant_super_admin( $net_admin );

$post = function ( $body ) {
	$request = new WP_REST_Request( 'POST', '/minn-admin/v1/users/add-existing' );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
};

wp_set_current_user( $sub_admin );
$check( 'Subsite administrator holds promote_users', current_user_can( 'promote_users' ) );
$check( 'Subsite administrator is not a network administrator', ! current_user_can( 'manage_network_users' ) );

// --- 1. username lookup is not theirs to make ---------------------------
$response = $post( array( 'user' => 'minn_probe_target', 'role' => 'author' ) );
$check(
	'Lookup by username is refused for a subsite administrator',
	400 === $response->get_status() && ! is_user_member_of_blog( $target, $blog_id ),
	'status ' . $response->get_status() . ', member ' . var_export( is_user_member_of_blog( $target, $blog_id ), true )
);

// --- 2. adding by email invites rather than attaches --------------------
$response = $post( array( 'user' => 'minn_probe_target@example.com', 'role' => 'author' ) );
$data     = $response->get_data();
$check(
	'Adding by email invites instead of attaching outright',
	200 === $response->get_status()
		&& ! empty( $data['pending'] )
		&& ! is_user_member_of_blog( $target, $blog_id ),
	'status ' . $response->get_status() . ', member ' . var_export( is_user_member_of_blog( $target, $blog_id ), true )
);
$check(
	'The invitation reply names nobody',
	empty( $data['name'] ) && empty( $data['email'] ) && empty( $data['id'] )
);
$check(
	'A confirmation the account holder can act on was stored',
	(bool) $GLOBALS['wpdb']->get_var(
		"SELECT option_id FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'new_user_%' LIMIT 1"
	)
);

// --- 3. a network administrator is not a target -------------------------
$response = $post( array( 'user' => 'minn_probe_netadmin@example.com', 'role' => 'administrator' ) );
$check(
	'A network administrator cannot be pulled into the site',
	403 === $response->get_status() && ! is_user_member_of_blog( $net_admin, $blog_id ),
	'status ' . $response->get_status() . ', member ' . var_export( is_user_member_of_blog( $net_admin, $blog_id ), true )
);

// --- 4. controls: the network administrator still works normally --------
$super = get_super_admins();
$super = get_user_by( 'login', reset( $super ) );
wp_set_current_user( $super->ID );
$check( 'CONTROL the control user is a network administrator', current_user_can( 'manage_network_users' ) );

$response = $post( array( 'user' => 'minn_probe_target', 'role' => 'author' ) );
$check(
	'CONTROL a network administrator still adds by username, immediately',
	200 === $response->get_status() && is_user_member_of_blog( $target, $blog_id ),
	'status ' . $response->get_status()
);

remove_user_from_blog( $target, $blog_id );
$response = $post( array( 'user' => 'minn_probe_target@example.com', 'role' => 'author' ) );
$check(
	'CONTROL a network administrator still adds by email, immediately',
	200 === $response->get_status() && is_user_member_of_blog( $target, $blog_id ),
	'status ' . $response->get_status()
);

// --- cleanup -------------------------------------------------------------
$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'new_user_%'" );
if ( ! function_exists( 'wpmu_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}
foreach ( array( $target, $net_admin, $sub_admin ) as $id ) {
	revoke_super_admin( $id );
	remove_user_from_blog( $id, $blog_id );
	wpmu_delete_user( $id );
}

printf( "\nsecurity-v031-add-existing: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
