<?php
/**
 * Post type and taxonomy definitions save only what changed, and a copy
 * someone else changed meanwhile is refused (409) instead of written over.
 *
 * The edit dialogs sent the whole definition, and the route read every
 * switch it left out as off: a save that touched one label could undo a
 * change another person made to the archive or REST switch since the dialog
 * opened. The list now serves a stamp per definition, a save carries it as
 * `base`, and a field a save leaves out keeps its value.
 *
 * Runs on a throwaway Minn-native post type and taxonomy, inside a
 * transaction that is rolled back.
 *
 * Run: wp eval-file tests/definition-saves.test.php --user=admin --path=<absolute site path>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();
$call = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
// The store is the truth: re-register from it, as the next request would.
$reload_type = function ( $slug ) {
	if ( post_type_exists( $slug ) ) {
		unregister_post_type( $slug );
	}
	Minn_Admin_CPT::register_native_types();
};
$reload_tax = function ( $slug ) {
	if ( taxonomy_exists( $slug ) ) {
		unregister_taxonomy( $slug );
	}
	Minn_Admin_CPT::register_native_taxonomies();
};
$row = function ( $kind, $slug ) use ( $call ) {
	list( , $d ) = $call( 'GET', '/minn-admin/v1/' . ( 'type' === $kind ? 'post-types' : 'taxonomies' ) );
	foreach ( (array) ( $d[ 'type' === $kind ? 'types' : 'taxonomies' ] ?? array() ) as $r ) {
		if ( $slug === $r['slug'] ) {
			return $r;
		}
	}
	return null;
};
$slug = 'minnrtdef' . wp_rand( 100, 999 );
$tax  = 'minnrttax' . wp_rand( 100, 999 );

$wpdb->query( 'START TRANSACTION' );
try {
	list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $slug, 'singular' => 'Recipe', 'plural' => 'Recipes', 'description' => 'Kitchen notes', 'public' => 1, 'has_archive' => 1, 'show_in_rest' => 1, 'supports' => array( 'title', 'editor', 'thumbnail' ), 'taxonomies' => array( 'category' ), 'backend' => 'minn' ) );
	$reload_type( $slug );
	$before = $row( 'type', $slug );
	$check( 'a test post type is created and listed with a stamp', 200 === $st && $before && preg_match( '/^[0-9a-f]{12}$/', (string) ( $before['stamp'] ?? '' ) ), $st . ' ' . wp_json_encode( $before ) );

	// One field changed: only it goes.
	list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'description' => 'Kitchen notes, tested', 'base' => $before['stamp'] ) );
	$reload_type( $slug );
	$after = $row( 'type', $slug );
	$check( 'a save that sends one field answers ok', 200 === $st, (string) $st );
	$check( 'and changes that field', 'Kitchen notes, tested' === ( $after['description'] ?? '' ) );
	$same = true;
	foreach ( array( 'singular', 'plural', 'public', 'has_archive', 'show_in_rest', 'hierarchical' ) as $k ) {
		$same = $same && ( $before[ $k ] === $after[ $k ] );
	}
	$check( 'and leaves every switch and label it did not send', $same && $before['supports'] === $after['supports'] && $before['taxonomies'] === $after['taxonomies'], wp_json_encode( array( $before, $after ) ) );
	$check( 'and the stamp moves with the change', $before['stamp'] !== $after['stamp'] );

	// A copy opened before that save is stale now.
	list( $st, $d ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'has_archive' => 0, 'base' => $before['stamp'] ) );
	$reload_type( $slug );
	$check( 'a save from a copy opened before someone else\'s change is refused with 409', 409 === $st && 'minn_stale' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
	$check( 'and stores nothing', true === ( $row( 'type', $slug )['has_archive'] ?? null ) );

	// The current copy saves.
	$cur        = $row( 'type', $slug );
	list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'has_archive' => 0, 'base' => $cur['stamp'] ) );
	$reload_type( $slug );
	$check( 'the current copy saves a switch', 200 === $st && false === ( $row( 'type', $slug )['has_archive'] ?? null ) );

	// Taxonomies the same way.
	list( $st ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $tax, 'singular' => 'Cuisine', 'plural' => 'Cuisines', 'public' => 1, 'hierarchical' => 1, 'show_in_rest' => 1, 'object_types' => array( 'post', $slug ), 'backend' => 'minn' ) );
	$reload_tax( $tax );
	$tb = $row( 'tax', $tax );
	$check( 'a test taxonomy is created and listed with a stamp', 200 === $st && $tb && '' !== (string) ( $tb['stamp'] ?? '' ), $st . ' ' . wp_json_encode( $tb ) );
	list( $st ) = $call( 'POST', "/minn-admin/v1/taxonomies/{$tax}", array( 'plural' => 'Cuisines of the world', 'base' => $tb['stamp'] ) );
	$reload_tax( $tax );
	$ta = $row( 'tax', $tax );
	$check( 'a taxonomy save that sends one label leaves its switches and post types', 200 === $st && 'Cuisines of the world' === $ta['plural'] && $tb['hierarchical'] === $ta['hierarchical'] && $tb['public'] === $ta['public'] && $tb['show_in_rest'] === $ta['show_in_rest'] && $tb['object_types'] === $ta['object_types'], $st . ' ' . wp_json_encode( array( $tb, $ta ) ) );
	list( $st, $d ) = $call( 'POST', "/minn-admin/v1/taxonomies/{$tax}", array( 'hierarchical' => 0, 'base' => $tb['stamp'] ) );
	$check( 'a stale taxonomy copy is refused with 409', 409 === $st && 'minn_stale' === ( $d['code'] ?? '' ), (string) $st );
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
}

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\ndefinition-saves: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
