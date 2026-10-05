<?php
/**
 * Regressions for the v0.43.0 release-candidate audit.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what came back or what got
 * stored, with a control where a fix could over-block. Sections SKIP when their
 * plugin is inactive.
 *
 * Run: wp eval-file tests/security-v043-rc.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v043-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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
$author = get_user_by( 'login', 'minn-author' );
$editor = get_user_by( 'login', 'minn-editor' );

// --- 01-01 / 06-01 /render-blocks drops a post id the caller cannot edit ---
if ( $author ) {
	$foreign = wp_insert_post( array( 'post_title' => 'Minn v043 foreign private', 'post_status' => 'private', 'post_author' => $admin ) );
	wp_set_current_user( $author->ID );
	$own    = wp_insert_post( array( 'post_title' => 'Minn v043 own draft', 'post_status' => 'draft', 'post_author' => $author->ID ) );
	$seen   = null;
	$spy    = function ( $blocks, $pid ) use ( &$seen ) {
		$seen = $pid;
	};
	$markup = array( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
	add_action( 'minn_admin_before_render_blocks', $spy, 1, 2 );
	list( $status ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $markup, 'post' => $foreign ) );
	$check( 'render-blocks: an Author naming an admin\'s private post renders without its id', 0 === $seen, "status {$status}, hooks saw " . var_export( $seen, true ) );
	$seen = null;
	$call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $markup, 'post' => $own ) );
	$check( 'render-blocks: the Author\'s own draft still reaches the hooks (control)', $own === $seen, 'hooks saw ' . var_export( $seen, true ) );
	remove_action( 'minn_admin_before_render_blocks', $spy, 1 );
	wp_set_current_user( $admin );
	wp_delete_post( $foreign, true );
	wp_delete_post( $own, true );
} else {
	$skip( 'render-blocks: no minn-author account' );
}

$summary();
