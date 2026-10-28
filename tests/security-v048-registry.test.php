<?php
/**
 * Regressions for the v0.47.0 shipped-zip registry scan (findings that open
 * v0.48.0).
 *
 * Each section checks as the role the finding named, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive.
 *
 * Run: wp eval-file tests/security-v048-registry.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v048-registry: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( $admin[0]->ID );

/*
 * Mail-1: logged mail read whole on a single site asked edit_users, which
 * WooCommerce grants a Shop Manager while narrowing edit_user to customers,
 * so an administrator's reset key in a mail log was theirs to read.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( is_multisite() || ! function_exists( 'minn_admin_mail_bodies_open' ) ) {
		echo "SKIP  Mail-1 needs a single site with the mail kit loaded\n";
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! get_role( 'shop_manager' ) ) {
		echo "SKIP  Mail-1 WooCommerce inactive\n";
		return;
	}
	$login = $admin[0]->user_login;
	$body  = 'Reset: ' . network_site_url( 'wp-login.php?action=rp&key=SECRETKEY123&login=' . rawurlencode( $login ) );
	$sm    = wp_insert_user( array( 'user_login' => 'minn-v048-sm-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'minn-v048-sm-' . wp_rand() . '@example.com' ) );
	try {
		wp_set_current_user( $sm );
		$check( 'Mail-1 a Shop Manager has edit_users (the premise)', current_user_can( 'edit_users' ) && ! current_user_can( 'edit_user', $admin[0]->ID ) );
		$check( 'Mail-1 a Shop Manager does not read mail bodies whole', ! minn_admin_mail_bodies_open() );
		$masked = minn_admin_mail_mask_reset_keys( $body );
		$check( 'Mail-1 an administrator\'s reset key is hidden from a Shop Manager', false === strpos( $masked, 'SECRETKEY123' ), $masked );
		wp_set_current_user( $admin[0]->ID );
		$check( 'Mail-1 control: an administrator reads bodies whole', minn_admin_mail_bodies_open() && false !== strpos( minn_admin_mail_mask_reset_keys( $body ), 'SECRETKEY123' ) );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $sm );
	}
} )();

$summary();
