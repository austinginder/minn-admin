<?php
/**
 * Regressions for the v0.44.0 release-candidate audit.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what got stored or what
 * came back, with a control where a fix could over-block. Sections SKIP when
 * their plugin is inactive, and the multisite ones SKIP on a single site.
 *
 * Run: wp eval-file tests/security-v044-rc.test.php --user=admin --path=<site> [--url=<subsite>]
 *
 * @package minn-admin
 */

// ACPT's WPAttachment.php calls set_time_limit( 5 ) at file scope, so once a
// section autoloads it every later section would have five seconds left.
// Each check and skip lifts the limit again.
$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v044-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// --- 03-04 Bricks: a key Bricks itself treats as valid stays -----------------
// Bricks counts processed, canceled (not refunded) and past_due licences as
// valid. Minn's restore-on-refusal used to treat everything but "active" as a
// refusal, so such a key was put back to the previous one, or removed.
if ( ! class_exists( '\Bricks\License' ) || ! function_exists( 'minn_admin_license_default_providers' ) ) {
	$skip( '03-04 Bricks inactive' );
} else {
	// The real key and status are snapshotted and put back exactly; the
	// Bricks licence server never hears from this section.
	$brx4_prev   = get_option( 'bricks_license_key', null );
	$brx4_status = get_option( '_transient_bricks_license_status', null );
	$brx4_tout   = get_option( '_transient_timeout_bricks_license_status', null );
	$brx4_answer = 'active';
	$brx4_http   = function ( $pre, $args, $url ) use ( &$brx4_answer ) {
		if ( 'my.bricksbuilder.io' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'status' => $brx4_answer ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return new WP_Error( 'minn_test_offline', 'offline' );
	};
	add_filter( 'pre_http_request', $brx4_http, PHP_INT_MAX, 3 );
	$brx4_row = function () use ( $call ) {
		list( , $d ) = $call( 'GET', '/minn-admin/v1/licenses' );
		foreach ( (array) ( $d['items'] ?? $d ) as $it ) {
			if ( is_array( $it ) && 'Bricks' === ( $it['name'] ?? '' ) ) {
				return $it;
			}
		}
		return null;
	};
	foreach ( array( 'processed', 'canceled', 'past_due' ) as $brx4_word ) {
		delete_option( 'bricks_license_key' );
		delete_transient( 'bricks_license_status' );
		$brx4_answer = $brx4_word;
		list( $brx4_st, $brx4_body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'bricks', 'action' => 'activate', 'secret' => 'minntestbricks' . $brx4_word ) );
		$brx4_key = get_option( 'bricks_license_key', null );
		$check( "03-04 Bricks: a {$brx4_word} licence activates and keeps its key", 200 === $brx4_st && ! empty( $brx4_body['ok'] ) && 'minntestbricks' . $brx4_word === $brx4_key, $brx4_st . ' ' . wp_json_encode( array_intersect_key( (array) $brx4_body, array_flip( array( 'ok', 'code', 'message' ) ) ) ) . ' key=' . ( null === $brx4_key ? 'absent' : 'kept' ) );
		$brx4_it = $brx4_row();
		$check( "03-04 Bricks: a {$brx4_word} licence reads valid", is_array( $brx4_it ) && 'valid' === ( $brx4_it['state'] ?? '' ), wp_json_encode( $brx4_it ) );
		list( , $brx4_v ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'bricks', 'action' => 'verify' ) );
		$check( "03-04 Bricks: re-verifying a {$brx4_word} licence succeeds", ! empty( $brx4_v['ok'] ), wp_json_encode( array_intersect_key( (array) $brx4_v, array_flip( array( 'ok', 'code', 'message' ) ) ) ) );
	}
	// Control: a refused key is still put back.
	update_option( 'bricks_license_key', 'minntestbricksworking' );
	set_transient( 'bricks_license_status', 'active', HOUR_IN_SECONDS );
	$brx4_answer = 'expired';
	list( , $brx4_body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'bricks', 'action' => 'activate', 'secret' => 'minntestbricksexpired' ) );
	$check( '03-04 Bricks control: an expired key is refused and the working one kept', empty( $brx4_body['ok'] ) && 'minntestbricksworking' === get_option( 'bricks_license_key', null ) && 'active' === get_transient( 'bricks_license_status' ), wp_json_encode( array_intersect_key( (array) $brx4_body, array_flip( array( 'ok', 'code', 'message' ) ) ) ) );

	remove_filter( 'pre_http_request', $brx4_http, PHP_INT_MAX );
	if ( null === $brx4_prev ) {
		delete_option( 'bricks_license_key' );
	} else {
		update_option( 'bricks_license_key', $brx4_prev );
	}
	foreach ( array( '_transient_bricks_license_status' => $brx4_status, '_transient_timeout_bricks_license_status' => $brx4_tout ) as $brx4_k => $brx4_v ) {
		if ( null === $brx4_v ) {
			delete_option( $brx4_k );
		} else {
			update_option( $brx4_k, $brx4_v, false );
		}
	}
	\Bricks\License::$license_key = $brx4_prev;
}

// @sections

// Flamingo files a contact for every user a section creates and keeps it
// after the user is deleted; the fixtures' leftovers go here.
if ( post_type_exists( 'flamingo_contact' ) ) {
	global $wpdb;
	foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'flamingo_contact' AND post_title LIKE 'minn-%@example.com'" ) as $minn_contact ) {
		if ( ! get_user_by( 'email', $minn_contact->post_title ) ) {
			wp_delete_post( (int) $minn_contact->ID, true );
		}
	}
}

$summary();
