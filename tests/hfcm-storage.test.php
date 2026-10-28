<?php
/**
 * Header Footer Code Manager's storage contract: its form stores a snippet
 * as htmlentities( $snippet ) and its renderer and edit screen read it with
 * html_entity_decode(). A snippet Minn writes must render exactly as typed,
 * and one HFCM wrote must open in Minn as its author sees it.
 *
 * HFCM stays inactive on the lab; the swap loads it for this process only.
 *   MINN_SWAP_ADD=header-footer-code-manager/99robots-header-footer-code-manager.php \
 *     wp eval-file tests/hfcm-storage.test.php --user=admin --require=tests/lib/plugin-swap.php
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
if ( ! function_exists( 'minn_admin_hfcm_active' ) || ! minn_admin_hfcm_active() ) {
	echo "SKIP  Header Footer Code Manager inactive\n";
	return;
}
$call = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};
global $wpdb;
$table = $wpdb->prefix . 'hfcm_scripts';
// What HFCM's renderer echoes for a stored snippet.
$renders = function ( $id ) use ( $wpdb, $table ) {
	return html_entity_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT snippet FROM {$table} WHERE script_id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
};
$typed = '<script>if (a &amp;&amp; b) { x = "&lt;b&gt;"; }</script><div>Wrap text in &lt;b&gt;</div>';
$ids   = array();
try {
	list( $st, $d ) = $call( 'POST', '/minn-admin/v1/hfcm/snippets', array( 'name' => 'Minn hfcm storage', 'code' => $typed, 'snippet_type' => 'html', 'location' => 'footer', 'active' => false ) );
	$id    = (int) ( $d['id'] ?? 0 );
	$ids[] = $id;
	$check( 'a snippet created in Minn (premise)', in_array( $st, array( 200, 201 ), true ) && $id > 0, $st . ' ' . wp_json_encode( $d ) );
	$check( 'it renders exactly as typed, entities kept', $typed === $renders( $id ), $renders( $id ) );
	list( , $item ) = $call( 'GET', "/minn-admin/v1/hfcm/snippets/{$id}" );
	$check( 'it opens in Minn as typed', $typed === ( $item['code'] ?? null ), (string) ( $item['code'] ?? '' ) );

	$edited = $typed . '<!-- &copy; edited -->';
	list( $st ) = $call( 'PUT', "/minn-admin/v1/hfcm/snippets/{$id}", array( 'code' => $edited ) );
	$check( 'an edit in Minn renders exactly as typed', 200 === $st && $edited === $renders( $id ), $st . ' ' . $renders( $id ) );

	// A snippet HFCM's own form saved: stored htmlentities-encoded.
	$wpdb->insert( $table, array( 'name' => 'Minn hfcm native', 'snippet' => htmlentities( '<script>var t = 1 < 2;</script>' ), 'snippet_type' => 'html', 'location' => 'footer', 'device_type' => 'both', 'display_on' => 'All', 'status' => 'inactive', 'created' => current_time( 'mysql' ) ) );
	$native = (int) $wpdb->insert_id;
	$ids[]  = $native;
	list( , $item ) = $call( 'GET', "/minn-admin/v1/hfcm/snippets/{$native}" );
	$check( "a snippet HFCM's form saved opens in Minn as its author typed it", '<script>var t = 1 < 2;</script>' === ( $item['code'] ?? null ), (string) ( $item['code'] ?? '' ) );
	list( $st ) = $call( 'PUT', "/minn-admin/v1/hfcm/snippets/{$native}", array( 'name' => 'Minn hfcm native renamed', 'code' => $item['code'] ) );
	$check( 'control: a rename that sends the code back unchanged keeps it rendering the same', 200 === $st && '<script>var t = 1 < 2;</script>' === $renders( $native ), $renders( $native ) );
} finally {
	foreach ( array_filter( $ids ) as $id ) {
		$wpdb->delete( $table, array( 'script_id' => $id ), array( '%d' ) );
	}
}

printf( "\nhfcm-storage: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
