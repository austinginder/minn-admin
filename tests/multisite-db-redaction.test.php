<?php
/**
 * On a network, the database browser on a subsite hides copies of the main
 * site's credentials too.
 *
 * A subsite's browser lists the base-prefixed tables (the main site's
 * options among them), and it used to collect the credentials it looks for
 * from the current site's options only, so a copy of a main-site licence key
 * under a name no rule knows printed raw there.
 *
 * Plants a fake credential in a main-site row the browser hides by name and a
 * copy of it under another name, asks the browser from a subsite, and removes
 * both.
 *
 * Run from a site that is not the main one, as a super admin:
 * wp eval-file tests/multisite-db-redaction.test.php --user=admin --url=https://minnmsd.localhost/store/ --path=<network>
 */

global $wpdb;

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

if ( ! is_multisite() || is_main_site() || ! is_super_admin() ) {
	echo "SKIP  needs a network, a site other than the main one, and a super admin\n";
	return;
}

$main  = (int) get_main_site_id();
$table = $wpdb->base_prefix . 'options';
$home  = '';
foreach ( array( 'postman_auth_token', 'jetpack_secrets' ) as $name ) {
	if ( false === get_blog_option( $main, $name ) ) {
		$home = $name;
		break;
	}
}
if ( '' === $home ) {
	echo "SKIP  no free credential row on the main site\n";
	return;
}
$fake = 'minnMAINkey' . wp_rand( 100000, 999999 ) . 'xyzQ7';
update_blog_option( $main, $home, $fake );
update_blog_option( $main, 'minn_test_copy_main', 'cached link https://vendor.example/dl?key=' . $fake );
try {
	$r = new WP_REST_Request( 'GET', '/minn-admin/v1/db/rows' );
	$r->set_param( 'table', $table );
	$r->set_param( 'fcol', 'option_name' );
	$r->set_param( 'fq', 'minn_test_copy_main' );
	$res  = rest_do_request( $r );
	$data = $res->get_data();
	$cols = wp_list_pluck( (array) ( $data['columns'] ?? array() ), 'name' );
	$vi   = array_search( 'option_value', $cols, true );
	$cell = 'missing';
	foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
		if ( 'minn_test_copy_main' === $row[ array_search( 'option_name', $cols, true ) ] ) {
			$cell = $row[ $vi ];
		}
	}
	$check( 'the subsite browser lists the main site\'s options', 200 === $res->get_status(), (string) $res->get_status() );
	$check( 'a copy of a main-site credential is hidden there', is_array( $cell ) && ! empty( $cell['redacted'] ), is_array( $cell ) ? 'not redacted' : ( 'missing' === $cell ? 'missing' : 'shown' ) );
} finally {
	delete_blog_option( $main, $home );
	delete_blog_option( $main, 'minn_test_copy_main' );
}

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
