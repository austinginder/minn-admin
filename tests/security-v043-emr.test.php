<?php
/**
 * Enable Media Replace's wp-config capability narrows Minn's replace route;
 * it never stands in for upload_files (RC audit 11-01).
 *
 * Run: wp eval-file tests/security-v043-emr.test.php --require=tests/security-v043-emr-const.php --path=<site>
 *
 * @package minn-admin
 */

if ( ! function_exists( 'emr' ) || ! function_exists( 'minn_admin_emr_available' ) ) {
	echo "SKIP  Enable Media Replace inactive\n";
	return;
}
if ( 'minn_v043_emr' !== EMR_CAPABILITY ) {
	echo "SKIP  run with --require=tests/security-v043-emr-const.php\n";
	return;
}
require_once ABSPATH . 'wp-admin/includes/user.php';
$results = array();
$check   = function ( $label, $ok ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s\n", $ok ? 'PASS' : 'FAIL', $label );
};
$make    = function ( $login, $caps ) {
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.com', 'role' => 'subscriber' ) );
	$u  = new WP_User( $id );
	foreach ( $caps as $c ) {
		$u->add_cap( $c );
	}
	return $id;
};
$only_cap  = $make( 'minn-v043-emr-cap', array( 'minn_v043_emr' ) );
$both      = $make( 'minn-v043-emr-both', array( 'minn_v043_emr', 'upload_files' ) );
$only_file = $make( 'minn-v043-emr-files', array( 'upload_files' ) );
wp_set_current_user( $only_cap );
$check( 'EMR: its wp-config capability without upload_files cannot replace files', ! minn_admin_emr_available() );
wp_set_current_user( $only_file );
$check( 'EMR: upload_files without the site\'s replace capability cannot either', ! minn_admin_emr_available() );
wp_set_current_user( $both );
$check( 'EMR: both together can (control)', minn_admin_emr_available() );
foreach ( array( $only_cap, $both, $only_file ) as $id ) {
	wp_delete_user( $id );
}
printf( "\nsecurity-v043-emr: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
