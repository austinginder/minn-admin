<?php
/**
 * Regressions for what the v0.44.0 pre-flight fixture sweep proved: older
 * adapter bugs the plugin updates did not cause but the sweep's smoke runs
 * turned up (an AIOSEO image Minn wrote under the wrong type, a CFDB7 delete
 * that reached past its own uploads, a Folders provider that never loaded,
 * a credential the database browser printed). Each section replays the route
 * Minn's client calls and checks the stored result, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive; a few
 * load an inactive plugin for the run only, through a --require file.
 *
 * Run: wp eval-file tests/sweep-v044.test.php --user=admin --path=<site>   (from the site root)
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
	printf( "\nsweep-v044: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

// --- DB browser redacts WP Mail SMTP's one-hour Connect token ----------------
// WP Mail SMTP keeps the token its logged-out connect endpoint is checked
// against in a transient for an hour; it is the endpoint's only credential.
( function () use ( $check, $skip, $call ) {
	if ( ! class_exists( 'Minn_Admin_DB' ) ) {
		$skip( 'database browser not loaded' );
		return;
	}
	global $wpdb;
	$name   = '_transient_wp_mail_smtp_connect_token';
	$secret = 'mnwpmsct' . wp_generate_password( 24, false );
	$was    = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
	$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $secret, 'autoload' => 'off' ) );
	try {
		list( $st, $res ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'fcol' => 'option_name', 'fq' => 'wp_mail_smtp_connect_token' ) );
		$cols = wp_list_pluck( (array) ( $res['columns'] ?? array() ), 'name' );
		$ki   = array_search( 'option_name', $cols, true );
		$vi   = array_search( 'option_value', $cols, true );
		$cell = null;
		$pk   = null;
		foreach ( (array) ( $res['rows'] ?? array() ) as $row ) {
			if ( false !== $ki && $name === ( $row[ $ki ] ?? null ) ) {
				$cell = $row[ $vi ] ?? null;
				$pk   = $row;
			}
		}
		$check( 'DB browser: the WP Mail SMTP connect token renders redacted', 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ), $st . ' ' . ( null === $cell ? 'row missing' : ( is_array( $cell ) ? 'array' : 'RAW ' . strlen( (string) $cell ) . ' bytes' ) ) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		list( $st2, $one ) = $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $id ) ) ) );
		$check( 'DB browser: its row detail holds no part of the token', 200 === $st2 && false === strpos( wp_json_encode( $one ), substr( $secret, 0, 12 ) ), $st2 . ( false === strpos( wp_json_encode( $one ), substr( $secret, 0, 12 ) ) ? '' : ' secret present' ) );
		list( $st3, $hit ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'fcol' => 'option_value', 'fq' => substr( $secret, 0, 12 ) ) );
		$check( 'DB browser: a value search on a prefix of the token finds nothing', 200 === $st3 && 0 === (int) ( $hit['total'] ?? -1 ), 'total ' . ( $hit['total'] ?? '?' ) );
	} finally {
		if ( $was ) {
			$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $was['option_value'], 'autoload' => $was['autoload'] ) );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $name, 'options' );
	}
} )();

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
