<?php
/**
 * The accounts a parity capture runs as: one per role route-gates asks,
 * created for the run and removed after it.
 *
 *   MINN_PARITY_RUN=<id> wp eval-file tests/parity/principals.php create   prints "PRINCIPALS {json}"
 *   MINN_PARITY_RUN=<id> wp eval-file tests/parity/principals.php delete
 *
 * Accounts are named for their run ("minn-parity-<run>-editor-1234"), so two
 * captures at once never remove each other's. Delete removes this run's
 * accounts and any parity account older than two hours (an interrupted
 * run's). Only accounts whose login starts with "minn-parity-" are ever
 * removed, and only by an id that is a real one (a WP_Error cast to int
 * names user 1).
 *
 * @package minn-admin
 */

require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$minn_parity_run  = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) getenv( 'MINN_PARITY_RUN' ) ) );
$minn_parity_run  = '' === $minn_parity_run ? 'x' . wp_rand( 1000, 9999 ) : substr( $minn_parity_run, 0, 12 );
$minn_parity_role = 'minn_parity_netrole_' . $minn_parity_run;

$minn_parity_sweep = function () use ( $minn_parity_run, $minn_parity_role ) {
	global $wpdb;
	$mine  = $wpdb->esc_like( 'minn-parity-' . $minn_parity_run . '-' ) . '%';
	$stale = gmdate( 'Y-m-d H:i:s', time() - 2 * HOUR_IN_SECONDS );
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT ID, user_login FROM {$wpdb->users} WHERE user_login LIKE %s OR ( user_login LIKE %s AND user_registered < %s )",
		$mine,
		$wpdb->esc_like( 'minn-parity-' ) . '%',
		$stale
	) );
	$gone = 0;
	foreach ( (array) $rows as $row ) {
		$id    = (int) $row->ID;
		$login = (string) $row->user_login;
		if ( $id <= 1 || 0 !== strpos( $login, 'minn-parity-' ) ) {
			continue;
		}
		// Posts a plugin made for the new account (Flamingo keeps a contact
		// per user), found by the account's address in their title.
		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", '%' . $wpdb->esc_like( $login . '@example.com' ) . '%' ) );
		foreach ( $posts as $pid ) {
			if ( (int) $pid > 0 ) {
				wp_delete_post( (int) $pid, true );
			}
		}
		is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
		++$gone;
	}
	if ( get_role( $minn_parity_role ) ) {
		remove_role( $minn_parity_role );
	}
	return $gone;
};

$mode = $args[0] ?? '';
if ( 'delete' === $mode ) {
	echo 'removed ' . $minn_parity_sweep() . " parity accounts\n";
	return;
}
if ( 'create' !== $mode ) {
	fwrite( STDERR, "usage: MINN_PARITY_RUN=<id> wp eval-file tests/parity/principals.php create|delete\n" );
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
	$login = 'minn-parity-' . $minn_parity_run . '-' . str_replace( '_', '-', $label ) . '-' . wp_rand( 1000, 9999 );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( is_int( $id ) && $id > 1 ) {
		$made[ $label ] = $id;
	}
}
echo 'PRINCIPALS ' . wp_json_encode( $made ) . "\n";
