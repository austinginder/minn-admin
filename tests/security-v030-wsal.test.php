<?php
/**
 * v0.30 WP Activity Log multisite tenant-scope regression.
 *
 * This uses disposable WSAL-shaped tables in the dedicated multisite lab and
 * drops them in the same process. The lab must not have real WSAL tables.
 *
 * Run: wp eval-file tests/security-v030-wsal.test.php --path=<multisite> --url=<subsite>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};

if ( ! is_multisite() ) {
	echo "SKIP  requires the dedicated multisite lab\n";
	return;
}

global $wpdb;
$occ  = $wpdb->base_prefix . 'wsal_occurrences';
$meta = $wpdb->base_prefix . 'wsal_metadata';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $occ ) ) === $occ
	|| $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta ) ) === $meta ) {
	echo "SKIP  real WP Activity Log tables already exist; refusing to touch them\n";
	return;
}

$blog_id   = get_current_blog_id();
$global_id = 930001;
$local_id  = 930002;
$user      = get_user_by( 'login', 'minnsiteadmin' );

try {
	$wpdb->query( "CREATE TABLE {$occ} (
		id bigint unsigned NOT NULL,
		site_id bigint unsigned NOT NULL,
		alert_id bigint unsigned NOT NULL,
		created_on decimal(20,6) NOT NULL,
		username varchar(191) NOT NULL,
		user_id bigint unsigned NOT NULL,
		severity int NOT NULL,
		object varchar(191) NOT NULL,
		event_type varchar(191) NOT NULL,
		client_ip varchar(64) NOT NULL,
		PRIMARY KEY (id)
	)" );
	$wpdb->query( "CREATE TABLE {$meta} (
		occurrence_id bigint unsigned NOT NULL,
		name varchar(191) NOT NULL,
		value longtext NOT NULL
	)" );

	$now = (string) microtime( true );
	$wpdb->insert( $occ, array( 'id' => $global_id, 'site_id' => 0, 'alert_id' => 1001, 'created_on' => $now, 'username' => 'network-actor', 'user_id' => 0, 'severity' => 400, 'object' => 'network', 'event_type' => 'changed', 'client_ip' => '192.0.2.10' ) );
	$wpdb->insert( $occ, array( 'id' => $local_id, 'site_id' => $blog_id, 'alert_id' => 1002, 'created_on' => $now, 'username' => 'site-actor', 'user_id' => 0, 'severity' => 200, 'object' => 'site', 'event_type' => 'changed', 'client_ip' => '192.0.2.20' ) );
	$wpdb->insert( $meta, array( 'occurrence_id' => $global_id, 'name' => 'AttemptedUsername', 'value' => 'network-secret' ) );
	$wpdb->insert( $meta, array( 'occurrence_id' => $local_id, 'name' => 'LocalContext', 'value' => 'site-visible' ) );

	$check( 'Fixture runs on a subsite', 1 < $blog_id, (string) $blog_id );
	$check( 'Fixture has the subsite administrator', $user instanceof WP_User );
	if ( ! $user ) {
		throw new RuntimeException( 'minnsiteadmin fixture missing' );
	}
	wp_set_current_user( $user->ID );
	$check( 'Subsite administrator passes the adapter fallback permission', minn_admin_wsal_can_view() );

	if ( ! defined( 'WSAL_VERSION' ) ) {
		define( 'WSAL_VERSION', 'test-fixture' );
	}
	$server = rest_get_server();
	// WP-CLI may have initialized REST before this fixture defines the vendor
	// constant. Re-fire registration so the adapter's real routes are present.
	do_action( 'rest_api_init', $server );

	$list_request = new WP_REST_Request( 'GET', '/minn-admin/v1/wsal/events' );
	$list_request->set_param( 'per_page', 25 );
	$list_response = rest_do_request( $list_request );
	$list_data = $list_response->get_data();
	$list_ids  = array_map( function ( $item ) { return (int) $item['id']; }, (array) ( $list_data['items'] ?? array() ) );
	$check( 'Subsite list includes its own event', in_array( $local_id, $list_ids, true ), wp_json_encode( $list_ids ) );
	$check( 'Subsite list excludes the network-global event', ! in_array( $global_id, $list_ids, true ), wp_json_encode( $list_ids ) );

	$global_detail = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/wsal/events/' . $global_id ) );
	$local_detail  = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/wsal/events/' . $local_id ) );
	$check( 'Subsite detail hides the network-global event', 404 === $global_detail->get_status(), (string) $global_detail->get_status() );
	$check( 'Subsite detail keeps its own event available', 200 === $local_detail->get_status(), (string) $local_detail->get_status() );

	$status = minn_admin_wsal_status_model();
	$check( 'Subsite status counts only its own event', '1' === (string) $status['rows'][1]['value'], wp_json_encode( $status['rows'] ) );
} finally {
	$wpdb->query( "DROP TABLE IF EXISTS {$meta}" );
	$wpdb->query( "DROP TABLE IF EXISTS {$occ}" );
}

$failed = count( array_filter( $results, function ( $result ) { return ! $result; } ) );
printf( "\nsecurity-v030-wsal: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
