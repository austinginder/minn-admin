<?php
/**
 * Regressions for the v0.45.0 release-candidate audit.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what got stored or what
 * came back, with a control where a fix could over-block. Sections SKIP when
 * their plugin is inactive, and the multisite ones SKIP on a single site.
 *
 * Run: wp eval-file tests/security-v045-rc.test.php --user=admin --path=<site> [--url=<subsite>]
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v045-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

// A throwaway account of one role; the id is checked before any later
// delete, since a WP_Error cast to int names user 1.
$make_user = function ( $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'minn-rc45-' . $role . '-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'minn-rc45-' . $role . '-' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => $role,
		)
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// --- 01-01 GenerateBlocks: island previews resolve no legacy dynamic data -----
// GenerateBlocks' first blocks read a post's title, excerpt, author email or
// any meta from their attributes, with no {{ }} tag for the stripper to find,
// and GenerateBlocks blanks them only on core's block renderer. A Contributor
// could read another author's draft and protected meta through the preview.
if ( ! class_exists( 'GenerateBlocks_Dynamic_Content' ) ) {
	$skip( '01-01 GenerateBlocks inactive' );
} else {
	$gb_pid = wp_insert_post( array( 'post_title' => 'MinnRc45SecretTitle', 'post_content' => 'MinnRc45SecretBody', 'post_status' => 'draft', 'post_author' => $admin ) );
	$gb_uid = $make_user( 'contributor' );
	if ( ! $gb_pid || is_wp_error( $gb_pid ) || ! $gb_uid ) {
		$check( '01-01 fixtures', false, 'could not create the draft or the contributor' );
	} else {
		update_post_meta( $gb_pid, '_minn_rc45_secret', 'MinnRc45SecretMeta' );
		$gb_headline = function ( $attrs ) {
			$u = 'rc45' . substr( md5( wp_json_encode( $attrs ) ), 0, 6 );
			return '<!-- wp:generateblocks/headline ' . wp_json_encode( array_merge( array( 'uniqueId' => $u, 'element' => 'p' ), $attrs ) ) . ' --><p class="gb-headline gb-headline-' . $u . ' gb-headline-text">Static words</p><!-- /wp:generateblocks/headline -->';
		};
		$gb_render = function ( $markup ) use ( $call ) {
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => array( $markup ) ) );
			return array( $st, (string) ( $d['rendered'][0] ?? '' ) );
		};
		$gb_cases = array(
			'post title' => array( 'useDynamicData' => true, 'dynamicContentType' => 'post-title', 'dynamicSource' => 'post-id', 'postId' => $gb_pid ),
			'protected meta' => array( 'useDynamicData' => true, 'dynamicContentType' => 'post-meta', 'metaFieldName' => '_minn_rc45_secret', 'dynamicSource' => 'post-id', 'postId' => $gb_pid ),
			'excerpt' => array( 'useDynamicData' => true, 'dynamicContentType' => 'post-excerpt', 'dynamicSource' => 'post-id', 'postId' => $gb_pid ),
		);
		wp_set_current_user( $gb_uid );
		foreach ( $gb_cases as $gb_label => $gb_attrs ) {
			list( $gb_st, $gb_html ) = $gb_render( $gb_headline( $gb_attrs ) );
			$check( "01-01 a Contributor's preview resolves no legacy {$gb_label} of another author's draft", 200 === $gb_st && false === strpos( $gb_html, 'MinnRc45Secret' ), $gb_st . ' ' . substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
		}
		list( $gb_st, $gb_html ) = $gb_render( '<!-- wp:generateblocks/text {"uniqueId":"rc45t","tagName":"p"} --><p class="gb-text">{{post_title id:' . $gb_pid . '}}</p><!-- /wp:generateblocks/text -->' );
		$check( '01-01 a Contributor\'s {{ }} tag still resolves nothing', 200 === $gb_st && false === strpos( $gb_html, 'MinnRc45Secret' ), substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
		list( $gb_st, $gb_html ) = $gb_render( $gb_headline( array() ) );
		$check( '01-01 control: a Contributor\'s plain headline still previews', 200 === $gb_st && false !== strpos( $gb_html, 'Static words' ), substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
		wp_set_current_user( $admin );
		list( $gb_st, $gb_html ) = $gb_render( $gb_headline( $gb_cases['post title'] ) );
		$check( '01-01 control: an administrator\'s legacy title still resolves', 200 === $gb_st && false !== strpos( $gb_html, 'MinnRc45SecretTitle' ), substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
	}
	wp_set_current_user( $admin );
	if ( $gb_pid && ! is_wp_error( $gb_pid ) ) {
		wp_delete_post( $gb_pid, true );
	}
	if ( $gb_uid ) {
		$drop_user( $gb_uid );
	}
}

// --- 01-02 Permalinks: a typed structure is stored as typed -------------------
// Where WordPress can't tell the server rewrites URLs, core puts /index.php in
// its presets and stores a typed structure verbatim on a single site. Minn
// prefixed every structure it saved there.
if ( is_multisite() ) {
	$skip( '01-02 single-site rule (a network adds its own /blog handling)' );
} else {
	global $wp_rewrite;
	$pl_prev  = (string) get_option( 'permalink_structure' );
	$pl_norew = function () {
		return false;
	};
	add_filter( 'got_url_rewrite', $pl_norew );
	list( , $pl_state ) = $call( 'GET', '/minn-admin/v1/permalinks' );
	$check( '01-02 the state names the /index.php preset prefix where rewriting is unknown', '/index.php' === ( $pl_state['index_prefix'] ?? null ), wp_json_encode( $pl_state['index_prefix'] ?? null ) );
	$pl_try = '/%postname%/' === $pl_prev ? '/%year%/%postname%/' : '/%postname%/';
	list( $pl_st, $pl_body ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => $pl_try ) );
	$check( '01-02 a typed structure is stored as typed', 200 === $pl_st && $pl_try === get_option( 'permalink_structure' ), $pl_st . ' ' . get_option( 'permalink_structure' ) );
	list( $pl_st, $pl_body ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => '/index.php' . $pl_try ) );
	$check( '01-02 control: the /index.php preset is stored with its prefix', 200 === $pl_st && '/index.php' . $pl_try === get_option( 'permalink_structure' ), get_option( 'permalink_structure' ) );
	remove_filter( 'got_url_rewrite', $pl_norew );
	add_filter( 'got_url_rewrite', '__return_true' );
	list( , $pl_state ) = $call( 'GET', '/minn-admin/v1/permalinks' );
	remove_filter( 'got_url_rewrite', '__return_true' );
	$check( '01-02 control: no preset prefix where the server rewrites', '' === ( $pl_state['index_prefix'] ?? null ), wp_json_encode( $pl_state['index_prefix'] ?? null ) );
	$wp_rewrite->set_permalink_structure( $pl_prev );
	flush_rewrite_rules();
	$check( '01-02 cleanup: the structure is back', $pl_prev === get_option( 'permalink_structure' ), get_option( 'permalink_structure' ) );
}

// @sections

$summary();
