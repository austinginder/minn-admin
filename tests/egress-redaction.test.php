<?php
/**
 * Egress redaction (includes/class-minn-admin-egress.php): a credential the
 * database browser would redact never leaves in a minn-admin/v1 response, and
 * a save that sends a redacted value back cannot overwrite the real one.
 *
 * Licence rows are seeded with made-up keys inside a transaction that is
 * rolled back; the routes that leak them are registered by this test only.
 *
 * Run: wp eval-file tests/egress-redaction.test.php --user=admin --path=<absolute site path>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
if ( ! class_exists( 'Minn_Admin_Egress' ) ) {
	echo "FAIL  the egress fence is not loaded\n";
	exit( 1 );
}
global $wpdb;
$key    = 'MinnEgress' . strtoupper( wp_generate_password( 10, false ) ) . '42x7/Q9+Ab';
$key2   = 'MinnSecond' . strtoupper( wp_generate_password( 10, false ) ) . '7731zQ';
$option = 'wpforms_license';
$leak   = function () use ( $option ) {
	$v = get_option( $option );
	$k = is_array( $v ) ? (string) ( $v['key'] ?? '' ) : '';
	return $k;
};
$json_ser = new class() implements JsonSerializable {
	public $k = '';
	public function jsonSerialize(): mixed {
		return array( 'inside' => $this->k );
	}
};
// WP-CLI may have started the REST server already (WooCommerce does).
rest_get_server();
register_rest_route( 'minn-admin/v1', '/minn-egress-test/leak', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () use ( $leak, $json_ser ) {
	$k            = $leak();
	$json_ser->k  = $k;
	$plain        = new ArrayObject( array( 'k' => $k ) );
	$res          = rest_ensure_response( array(
		'raw'     => $k,
		'inside'  => 'The key is ' . $k . ', keep it safe.',
		'json'    => wp_json_encode( array( 'key' => $k ) ),
		'url'     => 'https://example.com/?license=' . rawurlencode( $k ),
		'base64'  => base64_encode( 'key=' . $k ),
		'nested'  => array( 'deep' => (object) array( 'k' => $k ) ),
		'keyed'   => array( $k => 'active' ),
		'ser'     => $json_ser,
		'object'  => $plain,
		'short'   => 'active',
		'status'  => 'valid',
	) );
	$res->add_link( 'self', 'https://example.com/check?key=' . rawurlencode( $k ) );
	$res->header( 'X-Minn-Test', 'key ' . $k );
	return $res;
} ) );
register_rest_route( 'minn-egress-test/v1', '/leak', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () use ( $leak ) {
	return array( 'raw' => $leak() );
} ) );
register_rest_route( 'minn-admin/v1', '/minn-egress-test/intended', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () use ( $option, $key2 ) {
	$v   = get_option( $option );
	$url = home_url( '/?minn-token=' . $v['key'] );
	Minn_Admin_Egress::intended( $url );
	return array( 'url' => $url, 'other' => 'second ' . $key2 );
} ) );
register_rest_route( 'minn-admin/v1', '/minn-egress-test/save', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( $req ) {
	$sent = $req->get_json_params();
	update_option( $sent['option'], $sent['value'] );
	return array( 'saved' => true );
} ) );
// What a browser receives: dispatch, the server's response filters, the body as sent.
$send = function ( $method, $route, $body = null ) {
	$server = rest_get_server();
	$req    = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	return array( (string) wp_json_encode( $data ), $res->get_headers() );
};

$wpdb->query( 'START TRANSACTION' );
try {
	update_option( $option, array( 'key' => $key, 'type' => 'pro' ) );
	update_option( 'rg_gforms_key', $key2 );
	Minn_Admin_Egress::reset();

	list( $out, $headers ) = $send( 'GET', '/minn-admin/v1/minn-egress-test/leak' );
	$plain = str_replace( '\\/', '/', $out );
	$check( 'a licence key never leaves raw', false === strpos( $plain, $key ), $out );
	$check( 'nor JSON-escaped inside a string', false === strpos( $out, str_replace( '/', '\\\\/', $key ) ) );
	$check( 'nor URL-encoded, in a value or a link', false === strpos( $out, rawurlencode( $key ) ) );
	$check( 'nor base64-encoded', false === strpos( $out, substr( base64_encode( 'key=' . $key ), 8, 16 ) ) );
	$check( 'nor as an array key, in a JsonSerializable or another object', false === strpos( $plain, $key ) && false !== strpos( $out, '"inside":"[redacted' ) );
	$check( 'nor in a response header', false === strpos( (string) ( $headers['X-Minn-Test'] ?? '' ), $key ), (string) ( $headers['X-Minn-Test'] ?? '' ) );
	$check( 'what is left reads as redacted, with a fingerprint', (bool) preg_match( '/\[redacted:[0-9a-f]{6}\]/', $out ), $out );
	$check( 'short values and statuses are left alone', false !== strpos( $out, '"short":"active"' ) && false !== strpos( $out, '"status":"valid"' ) );

	list( $other ) = $send( 'GET', '/minn-egress-test/v1/leak' );
	$check( 'another namespace is that plugin\'s own business', false !== strpos( str_replace( '\\/', '/', $other ), $key ) );

	list( $out ) = $send( 'GET', '/minn-admin/v1/minn-egress-test/intended' );
	$plain       = str_replace( '\\/', '/', $out );
	$check( 'a value a route hands out on purpose leaves as it is', false !== strpos( $plain, $key ), $out );
	$check( 'while another credential in the same response is still redacted', false === strpos( $plain, $key2 ), $out );
	list( $out ) = $send( 'GET', '/minn-admin/v1/minn-egress-test/leak' );
	$check( 'and the next response redacts the intended value again', false === strpos( str_replace( '\\/', '/', $out ), $key ) );

	// The fingerprinted token a screen would show for each key.
	$fp  = function ( $k ) {
		return '[redacted:' . substr( hash( 'sha256', $k ), 0, 6 ) . ']';
	};
	$t1  = $fp( $key );
	$t2  = $fp( $key2 );

	// A screen that showed the redacted key saves it back unchanged.
	$send( 'POST', '/minn-admin/v1/minn-egress-test/save', array( 'option' => $option, 'value' => array( 'key' => $t1, 'type' => 'basic' ) ) );
	$now = get_option( $option );
	$check( 'a redacted value sent back never overwrites the stored key', is_array( $now ) && $key === ( $now['key'] ?? '' ), wp_json_encode( $now ) );
	$check( 'while the rest of the save lands', is_array( $now ) && 'basic' === ( $now['type'] ?? '' ) );

	// An edit beside the token in the same string is kept.
	update_option( $option, array( 'url' => 'https://example.com/?key=' . $key . '&mode=a' ) );
	$send( 'POST', '/minn-admin/v1/minn-egress-test/save', array( 'option' => $option, 'value' => array( 'url' => 'https://example.com/?key=' . $t1 . '&mode=b' ) ) );
	$check( 'an edit beside a redacted value in one string is kept', 'https://example.com/?key=' . $key . '&mode=b' === ( get_option( $option )['url'] ?? '' ), wp_json_encode( get_option( $option ) ) );

	// Two keys in a list, saved back after the first was removed: the one
	// left keeps its own key, not the other's.
	update_option( $option, array( 'list' => array( array( 'key' => $key ), array( 'key' => $key2 ) ) ) );
	$send( 'POST', '/minn-admin/v1/minn-egress-test/save', array( 'option' => $option, 'value' => array( 'list' => array( array( 'key' => $t2 ) ) ) ) );
	$check( 'a list that lost an item gives each key back to its own item', array( array( 'key' => $key2 ) ) === ( get_option( $option )['list'] ?? null ), wp_json_encode( get_option( $option ) ) );

	// An object-valued licence row (one no active plugin hooks: WPForms'
	// own update hook expects an array).
	update_option( 'searchwp_license', (object) array( 'key' => $key ) );
	Minn_Admin_Egress::reset();
	$send( 'POST', '/minn-admin/v1/minn-egress-test/save', array( 'option' => 'searchwp_license', 'value' => array( 'key' => $t1 ) ) );
	$v = get_option( 'searchwp_license' );
	$check( 'an option that held an object gets its key back', $key === ( is_array( $v ) ? ( $v['key'] ?? '' ) : '' ), wp_json_encode( $v ) );

	// The token pasted into a row that never held that key stays text.
	$send( 'POST', '/minn-admin/v1/minn-egress-test/save', array( 'option' => 'blogdescription', 'value' => 'tagline ' . $t1 ) );
	$check( 'a redacted token pasted elsewhere never turns into the key', false === strpos( (string) get_option( 'blogdescription' ), $key ) && false !== strpos( (string) get_option( 'blogdescription' ), $t1 ) );

	// Outside a Minn request a plugin may store whatever it likes.
	update_option( $option, array( 'key' => $t1 ) );
	$check( 'outside a minn-admin/v1 request the save is not touched', $t1 === ( get_option( $option )['key'] ?? '' ) );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	Minn_Admin_Egress::reset();
}

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\negress-redaction: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
