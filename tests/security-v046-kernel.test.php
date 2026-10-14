<?php
/**
 * Regressions from the v0.46 kernel audit that live in ported adapters (the
 * kernel's own are in kernel-conformance.test.php).
 *
 * Every write runs inside a transaction that is rolled back.
 *
 * Run: wp eval-file tests/security-v046-kernel.test.php --user=admin --path=<absolute site path>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	if ( $params ) {
		$r->set_query_params( $params );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
$admin = get_current_user_id();
if ( ! $admin || ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();

// --- Everest: a restore with no prior status lands in Received ------------
// Everest's own Abilities API trashes an entry without writing the
// _evf_trash_entry_status row its restore reads, so the restore set an
// empty status that no filter lists and no action accepts, and Minn said
// "Entry restored."
if ( function_exists( 'minn_admin_everest_active' ) && minn_admin_everest_active() ) {
	$wpdb->query( 'START TRANSACTION' );
	try {
		$form = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'everest_form' ORDER BY ID LIMIT 1" );
		$wpdb->insert( $wpdb->prefix . 'evf_entries', array( 'form_id' => $form, 'user_id' => 0, 'status' => 'trash', 'date_created' => current_time( 'mysql', true ), 'viewed' => 0, 'starred' => 0, 'user_device' => '', 'user_ip_address' => '', 'referer' => '', 'fields' => '{}' ) );
		$id = (int) $wpdb->insert_id;
		if ( $id > 0 && $form > 0 ) {
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/everest/entries/{$id}/restore" );
			$now            = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}evf_entries WHERE entry_id = %d", $id ) );
			$check( 'Everest: restoring an entry trashed without a prior status answers ok', 200 === $st && true === ( $d['ok'] ?? null ), $st . ' ' . wp_json_encode( $d ) );
			$check( 'Everest: and the entry is received again, not left with no status', 'publish' === $now, "status '{$now}'" );
			list( , $l ) = $call( 'GET', '/minn-admin/v1/everest/entries', array( 'status' => 'publish', 'per_page' => 100 ) );
			$check( 'Everest: and it lists under Received', in_array( $id, array_column( (array) $l['items'], 'id' ), true ) );
		} else {
			$skip( 'Everest: no form to hang an entry on' );
		}
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
	// A row gate asked for a context it does not know says no.
	$check( 'Everest: the row gate refuses a context it does not know', false === minn_admin_everest_can_entry( 1, 'manage' ) && false === minn_admin_everest_can_entry( 1, 'export' ) );
} else {
	$skip( 'Everest Forms inactive' );
}

// --- WPForms: the row gate refuses a gate it does not know -----------------
if ( function_exists( 'minn_admin_wpforms_can_entry' ) && minn_admin_wpforms_active() ) {
	$entry = (int) $wpdb->get_var( 'SELECT entry_id FROM ' . minn_admin_wpforms_table() . ' ORDER BY entry_id LIMIT 1' );
	if ( $entry ) {
		$check( 'WPForms: the row gate refuses a gate it does not know', false === minn_admin_wpforms_can_entry( $entry, 'manage' ) );
		$check( 'WPForms: control: the row gate admits an administrator for view', true === minn_admin_wpforms_can_entry( $entry, 'view' ) );
	} else {
		$skip( 'WPForms: no entry on the lab' );
	}
} else {
	$skip( 'WPForms inactive' );
}

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nsecurity-v046-kernel: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
