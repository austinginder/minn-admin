<?php
/**
 * The accounts a parity capture runs as: one per role route-gates asks,
 * created for the run and removed after it.
 *
 *   wp eval-file tests/parity/principals.php create   prints "PRINCIPALS {json}"
 *   wp eval-file tests/parity/principals.php delete   removes every minn-parity- account
 *
 * Both first remove accounts an interrupted run left behind. Only accounts
 * whose login starts with "minn-parity-" are ever removed, and only by an id
 * that is a real one (a WP_Error cast to int names user 1).
 *
 * @package minn-admin
 */

require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$minn_parity_role = 'minn_parity_netrole';

$minn_parity_sweep = function () use ( $minn_parity_role ) {
	global $wpdb;
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'minn-parity-' ) . '%' ) );
	foreach ( $ids as $id ) {
		$id   = (int) $id;
		$user = get_userdata( $id );
		if ( $id > 1 && $user && 0 === strpos( $user->user_login, 'minn-parity-' ) ) {
			is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
		}
	}
	if ( get_role( $minn_parity_role ) ) {
		remove_role( $minn_parity_role );
	}
	// Posts a plugin made for a new account (Flamingo keeps a contact per
	// user), found by the account's address in their title.
	$posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", '%' . $wpdb->esc_like( 'minn-parity-' ) . '%' . $wpdb->esc_like( '@example.com' ) . '%' ) );
	foreach ( $posts as $pid ) {
		if ( (int) $pid > 0 ) {
			wp_delete_post( (int) $pid, true );
		}
	}
	return count( $ids );
};

$mode = $args[0] ?? '';
if ( 'delete' === $mode ) {
	echo 'removed ' . $minn_parity_sweep() . " parity accounts\n";
	return;
}
if ( 'create' !== $mode ) {
	fwrite( STDERR, "usage: wp eval-file tests/parity/principals.php create|delete\n" );
	exit( 2 );
}

$minn_parity_sweep();
$roles = array( 'subscriber', 'contributor', 'author', 'editor', 'shop_manager', 'administrator' );
if ( is_multisite() ) {
	// A site role carrying network capabilities, as route-gates asks: what a
	// role plugin's "network manager" hands out on one site.
	add_role( $minn_parity_role, 'Minn parity network role', array( 'read' => true, 'manage_network' => true, 'manage_sites' => true, 'manage_network_users' => true, 'manage_network_options' => true, 'manage_network_plugins' => true, 'manage_network_themes' => true, 'edit_users' => true, 'list_users' => true ) );
	$roles[] = $minn_parity_role;
}
$made = array();
foreach ( $roles as $role ) {
	if ( ! get_role( $role ) ) {
		continue;
	}
	$label = $minn_parity_role === $role ? 'site_network_role' : $role;
	$login = 'minn-parity-' . str_replace( '_', '-', $label ) . '-' . wp_rand( 1000, 9999 );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( is_int( $id ) && $id > 1 ) {
		$made[ $label ] = $id;
	}
}
echo 'PRINCIPALS ' . wp_json_encode( $made ) . "\n";
