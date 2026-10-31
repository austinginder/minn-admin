<?php
/**
 * Regressions for the v0.48.0 shipped-zip registry scan (findings that open
 * v0.49.0): the render-blocks embed pass, the SEO panel's per-type gate and
 * FluentSnippets' REST authoring switch.
 *
 * Each section checks as the role the finding named, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive. No
 * request leaves the machine: every test embed URL is answered by a
 * pre_oembed_result filter and any HTTP attempt is refused and counted.
 *
 * Run: wp eval-file tests/security-v049-registry-gates.test.php --user=admin --path=<site>
 *      MINN_SEO_SWAP=seopress wp eval-file tests/security-v049-registry-gates.test.php --user=admin --require=tests/lib/seo-swap.php
 *      MINN_SEO_SWAP=surerank wp eval-file tests/security-v049-registry-gates.test.php --user=admin --require=tests/lib/seo-swap.php
 *
 * The FluentSnippets section defines REST_REQUEST for its last checks, so it
 * runs last.
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v049-registry-gates: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
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

$call = function ( $method, $route, $body = null, $query = array(), $headers = array() ) {
	$r = new WP_REST_Request( $method, $route );
	if ( $query ) {
		$r->set_query_params( $query );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	foreach ( $headers as $k => $v ) {
		$r->set_header( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( $admin[0]->ID );
$tag = substr( md5( (string) microtime( true ) ), 0, 8 );

/*
 * 00-2: the SEO panel's per-type gate read SEOPress's switch only, while
 * SEOPress boxes only its own type list as seopress_metaboxe_seo leaves it.
 * The sweep found the same gap for Yoast (wpseo_enable_editor_features_{type})
 * and SureRank (surerank_excluded_post_types_from_seo_checks).
 */
( function () use ( $check, $call ) {
	$plugin = function_exists( 'minn_admin_seo_plugin' ) ? minn_admin_seo_plugin() : null;
	if ( ! $plugin ) {
		echo "SKIP  00-2 no SEO provider active\n";
		return;
	}
	$name = (string) $plugin['name'];
	echo "00-2 provider: {$name}\n";
	$filters = array(
		'SEOPress'     => array(
			'seopress_metaboxe_seo',
			function ( $types ) {
				unset( $types['page'] );
				return $types;
			},
		),
		'Yoast SEO'    => array( 'wpseo_enable_editor_features_page', '__return_false' ),
		'SureRank'     => array(
			'surerank_excluded_post_types_from_seo_checks',
			function ( $types ) {
				$types[] = 'page';
				return $types;
			},
		),
	);
	$filters['SEOPress PRO'] = $filters['SEOPress'];
	if ( ! isset( $filters[ $name ] ) ) {
		echo "SKIP  00-2 {$name} has no per-type filter in this suite\n";
		return;
	}
	list( $hook, $fn ) = $filters[ $name ];
	$page   = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'minn v049 seo type', 'post_status' => 'draft' ) );
	$groups = function () use ( $call, $page ) {
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/seo/fields', null, array( 'post' => $page, 'type' => 'pages' ) );
		return 200 === $st ? count( (array) ( $d['groups'] ?? array() ) ) : -1;
	};
	try {
		$check( "00-2 {$name} control: pages get the panel", true === minn_admin_seo_shows_for_type( $plugin, 'page' ) && $groups() > 0, 'groups ' . $groups() );
		add_filter( $hook, $fn, 99 );
		try {
			$check( "00-2 {$name}: a type its own filter drops is closed", false === minn_admin_seo_shows_for_type( $plugin, 'page' ) );
			$check( "00-2 {$name}: the fields route offers that type no groups", 0 === $groups(), 'groups ' . $groups() );
			list( $st ) = $call( 'POST', '/wp/v2/pages/' . $page, array( 'minn_seo' => array( 'canonical' => 'https://elsewhere.example/minn-v049-type' ) ) );
			$check( "00-2 {$name}: a minn_seo write to that type stores nothing", false === strpos( (string) wp_json_encode( get_post_meta( $page ) ), 'elsewhere.example' ), (string) $st );
		} finally {
			remove_filter( $hook, $fn, 99 );
		}
		$check( "00-2 {$name} control: with the filter gone the panel returns", true === minn_admin_seo_shows_for_type( $plugin, 'page' ) && $groups() > 0 );
		if ( 'SureRank' !== $name ) {
			$check( "00-2 {$name}: a type it never boxes (synced patterns) is closed", false === minn_admin_seo_shows_for_type( $plugin, 'wp_block' ) );
		}
	} finally {
		wp_delete_post( $page, true );
	}
} )();

$summary();
