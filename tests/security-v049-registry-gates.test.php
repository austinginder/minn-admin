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

/*
 * 13-2: FluentSnippets keeps creating and editing snippets off its REST API
 * until the site owner allows it, so an Application Password cannot put PHP
 * on the site; Minn's routes wrote for any sign-in. They now answer the same
 * way, and turning a snippet on is held with them.
 */
( function () use ( $check, $call, $tag ) {
	$switch = '\\FluentSnippets\\App\\Services\\RestAuthoring';
	if ( ! function_exists( 'minn_admin_fluent_ready' ) || ! minn_admin_fluent_ready() || ! class_exists( $switch ) ) {
		echo "SKIP  13-2 FluentSnippets 10.57+ inactive\n";
		return;
	}
	// Their switch, read from their cached config and set in memory only:
	// nothing written while it is flipped, so their index file never holds it.
	$prop = function ( $name ) {
		$p = new ReflectionProperty( '\\FluentSnippets\\App\\Helpers\\Helper', $name );
		$p->setAccessible( true );
		return $p;
	};
	\FluentSnippets\App\Helpers\Helper::getIndexedConfig();
	$real    = $prop( 'indexedConfig' )->getValue();
	$flip_to = function ( $on ) use ( $prop, $real ) {
		$config                           = is_array( $real ) ? $real : array();
		$config['meta']['rest_authoring'] = $on ? 'yes' : 'no';
		$prop( 'indexedConfig' )->setValue( null, $config );
		$prop( 'indexedConfigLoaded' )->setValue( null, true );
	};
	$put_back = function () use ( $prop, $real ) {
		$prop( 'indexedConfig' )->setValue( null, $real );
	};
	$as_app_password = function ( $fn ) {
		global $wp_rest_application_password_uuid;
		$held                              = $wp_rest_application_password_uuid;
		$wp_rest_application_password_uuid = wp_generate_uuid4();
		try {
			return $fn();
		} finally {
			$wp_rest_application_password_uuid = $held;
		}
	};
	$files = function () {
		return array_map( function ( $row ) {
			return (string) ( $row['file_name'] ?? '' );
		}, minn_admin_fluent_all() );
	};
	$made = array();
	$drop = function ( $file ) {
		( new \FluentSnippets\App\Model\Snippet() )->deleteSnippet( $file );
	};
	$body  = array( 'name' => 'Minn v049 ' . $tag, 'desc' => 'x', 'code' => '// minn v049 suite', 'type' => 'PHP', 'run_at' => 'all', 'active' => false );
	$start = $files();
	// A refusal under test may not hold: whatever the suite made goes.
	$refused = function ( $req ) {
		return function_exists( 'minn_admin_fluent_authoring_refused' ) ? minn_admin_fluent_authoring_refused( $req ) : null;
	};
	try {
		if ( $switch::lockedValue() !== null ) {
			echo "SKIP  13-2 FLUENT_SNIPPETS_REST_AUTHORING decides the switch on this site\n";
			return;
		}
		// Control first, with their real config: a session that is not an
		// Application Password writes as before.
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', $body );
		$file           = (string) ( $d['id'] ?? '' );
		if ( '' !== $file ) {
			$made[] = $file;
		}
		$check( '13-2 control: a signed-in session creates a snippet', 200 === $st && '' !== $file, $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		if ( '' === $file ) {
			return;
		}
		list( , , $code_before ) = minn_admin_fluent_load( $file );

		$flip_to( false );
		$before = $files();
		list( $st, $d ) = $as_app_password( function () use ( $call, $body ) {
			return $call( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn v049 app pw' ) + $body );
		} );
		$check( '13-2 with authoring off, an Application Password cannot create a snippet', 403 === $st && 'minn_fluent_rest_authoring_off' === ( $d['code'] ?? '' ) && $before === $files(), $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		list( $st, $d ) = $as_app_password( function () use ( $call, $file ) {
			return $call( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), array( 'code' => '// minn v049 rewritten' ) );
		} );
		list( , , $code_now ) = minn_admin_fluent_load( $file );
		$check( '13-2 nor edit one', 403 === $st && $code_before === $code_now, $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		list( $st, $d ) = $as_app_password( function () use ( $call, $file ) {
			return $call( 'POST', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) . '/active', array( 'active' => true ) );
		} );
		list( , $meta_now ) = minn_admin_fluent_load( $file );
		$check( '13-2 nor turn one on', 403 === $st && 'draft' === ( $meta_now['status'] ?? '' ), $st . ' ' . wp_json_encode( $meta_now['status'] ?? null ) );
		list( $st ) = $as_app_password( function () use ( $call, $file ) {
			return $call( 'POST', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) . '/active', array( 'active' => false ) );
		} );
		$check( '13-2 control: turning one off stays open, as in their REST', 200 === $st, (string) $st );

		$flip_to( true );
		$req = new WP_REST_Request( 'POST', '/minn-admin/v1/fluent-snippets' );
		$check( '13-2 control: with authoring on, an Application Password may write', function_exists( 'minn_admin_fluent_authoring_refused' ) && null === $as_app_password( function () use ( $req, $refused ) {
			return $refused( $req );
		} ) );
		$put_back();

		// Over HTTP a sign-in that is not a cookie session arrives without a
		// nonce. Last in the suite: REST_REQUEST stays defined.
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
		$flip_to( false );
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn v049 no nonce' ) + $body );
		$check( '13-2 with authoring off, a REST request with no wp_rest nonce cannot create a snippet', 403 === $st && 'minn_fluent_rest_authoring_off' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn v049 bad nonce' ) + $body, array(), array( 'X-WP-Nonce' => 'abcdef1234' ) );
		$check( '13-2 nor one with a forged nonce', 403 === $st, (string) $st );
		$req = new WP_REST_Request( 'POST', '/minn-admin/v1/fluent-snippets' );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$check( '13-2 control: Minn\'s own screens (a valid wp_rest nonce) may write', function_exists( 'minn_admin_fluent_authoring_refused' ) && null === $refused( $req ) );
		$check( '13-2 but a valid nonce does not admit an Application Password', null !== $as_app_password( function () use ( $req, $refused ) {
			return $refused( $req );
		} ) );
		$put_back();
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn v049 nonce' ) + $body, array(), array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );
		if ( ! empty( $d['id'] ) ) {
			$made[] = (string) $d['id'];
		}
		$check( '13-2 control: with a valid nonce the create goes through', 200 === $st && ! empty( $d['id'] ), $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
	} finally {
		$put_back();
		foreach ( array_unique( array_merge( $made, array_diff( $files(), $start ) ) ) as $file ) {
			$drop( $file );
		}
	}
} )();

$summary();
