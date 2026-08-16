<?php
/**
 * v0.31 read/write scope regressions.
 *
 *  1. The minn_builder REST field declared view context and gated on nothing,
 *     so an anonymous wp/v2/posts request named the page builder running the
 *     site and handed back each post's builder editing URL. Every other field
 *     this plugin adds is editor-context and bound to edit_post.
 *
 *  2. minn_admin_tm_is_site() decided a transient's scope with a substring
 *     test while its companion minn_admin_tm_name() strips by prefix, so an
 *     ordinary blog transient whose own name contains the words was treated
 *     as network-shared state. The site-transient delete branch also carried
 *     no network gate.
 *
 * Run: wp eval-file tests/security-v031-scope.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admin ? (int) $admin[0]->ID : 0;

// --- 1. minn_builder is not public --------------------------------------
if ( ! function_exists( 'minn_admin_page_builders' ) || ! minn_admin_page_builders() ) {
	echo "SKIP  no page builder registered, minn_builder does not apply\n";
} else {
	wp_set_current_user( $admin );
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'minn scope probe',
			'post_content' => 'probe',
		),
		true
	);
	$check( 'Fixture post published', ! is_wp_error( $post_id ), is_wp_error( $post_id ) ? $post_id->get_error_message() : (string) $post_id );

	if ( ! is_wp_error( $post_id ) ) {
		// Anonymous, view context: what any visitor can fetch.
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$response = rest_do_request( $request );
		$data     = (array) $response->get_data();
		$check(
			'An anonymous request gets no minn_builder field',
			200 === $response->get_status() && ! array_key_exists( 'minn_builder', $data ),
			'status ' . $response->get_status() . ', keys ' . ( array_key_exists( 'minn_builder', $data ) ? 'include' : 'omit' ) . ' minn_builder'
		);

		// CONTROL: the editor asks in edit context and still gets it, which is
		// what the content list and the editor both read.
		wp_set_current_user( $admin );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		$data     = (array) $response->get_data();
		$check(
			'CONTROL an administrator still gets minn_builder in edit context',
			200 === $response->get_status() && array_key_exists( 'minn_builder', $data ),
			'status ' . $response->get_status()
		);

		wp_delete_post( $post_id, true );
	}
}

// --- 2. transient scope is decided by prefix ----------------------------
if ( ! function_exists( 'minn_admin_tm_is_site' ) ) {
	echo "SKIP  Transients Manager adapter is not loaded\n";
} else {
	$check(
		'A real site transient reads as network-shared',
		true === minn_admin_tm_is_site( '_site_transient_update_core' )
	);
	$check(
		'A blog transient whose NAME contains the words does not',
		false === minn_admin_tm_is_site( '_transient_my_site_transient_cache' ),
		'got ' . var_export( minn_admin_tm_is_site( '_transient_my_site_transient_cache' ), true )
	);
	$check(
		'Its name still strips to the same key either way',
		'my_site_transient_cache' === minn_admin_tm_name( '_transient_my_site_transient_cache' ),
		minn_admin_tm_name( '_transient_my_site_transient_cache' )
	);
	$check(
		'A site transient timeout row reads as network-shared',
		true === minn_admin_tm_is_site( '_site_transient_timeout_update_core' )
	);
}

printf( "\nsecurity-v031-scope: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
