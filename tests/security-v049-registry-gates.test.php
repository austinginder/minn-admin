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
 * 08-1: render-blocks ran WP_Embed::autoembed() over a block's whole HTML
 * whenever it held "wp-block-embed__wrapper", so one Contributor request
 * embedded every bare URL it carried, and with no post each lookup left a
 * published oembed_cache post behind. Only an embed block's own URL is
 * resolved now, lookups per request are capped, and answers are cached in
 * the editable post's meta or in an expiring transient.
 */
( function () use ( $check, $drop_user, $admin, $call, $tag ) {
	global $wpdb, $wp_embed;
	if ( empty( $wp_embed ) ) {
		echo "SKIP  08-1 no WP_Embed\n";
		return;
	}
	$lookups = 0;
	$http    = 0;
	$cap     = defined( 'Minn_Admin_REST::PREVIEW_EMBED_LOOKUPS' ) ? Minn_Admin_REST::PREVIEW_EMBED_LOOKUPS : 10;
	// After core's own local-URL answer (priority 10), so it sees null.
	$answer = function ( $result, $url ) use ( &$lookups ) {
		if ( false === strpos( (string) $url, 'minn-v049.example' ) ) {
			return $result;
		}
		++$lookups;
		return false !== strpos( $url, '/ok/' ) ? '<iframe title="minn-v049-embed" src="' . esc_url( $url ) . '"></iframe>' : false;
	};
	$no_http = function () use ( &$http ) {
		++$http;
		return new WP_Error( 'minn_v049_no_http', 'This suite makes no HTTP requests.' );
	};
	add_filter( 'pre_oembed_result', $answer, 99, 2 );
	add_filter( 'pre_http_request', $no_http, 1 );

	$cache_ids = function () use ( $wpdb ) {
		return array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'oembed_cache'" ) );
	};
	$transients = function () use ( $wpdb ) {
		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_minn\\_embed\\_%'" );
	};
	$ids_before = $cache_ids();
	$tr_before  = $transients();

	$embed = function ( $url, $name = 'core/embed' ) {
		$comment = 'core/embed' === $name ? 'embed' : $name;
		return '<!-- wp:' . $comment . ' {"url":"' . $url . '","type":"rich","providerNameSlug":"minn"} -->' . "\n"
			. '<figure class="wp-block-embed is-type-rich is-provider-minn wp-block-embed-minn"><div class="wp-block-embed__wrapper">' . "\n"
			. $url . "\n" . '</div></figure>' . "\n" . '<!-- /wp:' . $comment . ' -->';
	};
	$render = function ( array $blocks, $post ) use ( $call ) {
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $blocks, 'post' => $post ) );
		return array( $st, (array) ( $d['rendered'] ?? array() ) );
	};
	$iframes = function ( array $rendered ) {
		return count( array_filter( $rendered, function ( $h ) {
			return false !== strpos( (string) $h, 'title="minn-v049-embed"' );
		} ) );
	};

	$cid   = wp_insert_user( array( 'user_login' => 'minn-v049-co-' . $tag, 'user_pass' => wp_generate_password(), 'role' => 'contributor', 'user_email' => 'minn-v049-co-' . $tag . '@example.com' ) );
	$own   = wp_insert_post( array( 'post_title' => 'minn v049 embed own ' . $tag, 'post_status' => 'draft', 'post_author' => $cid, 'post_content' => $embed( "https://minn-v049.example/ok/{$tag}-own" ) ) );
	$other = wp_insert_post( array( 'post_title' => 'minn v049 embed other ' . $tag, 'post_status' => 'draft', 'post_author' => $admin[0]->ID ) );
	try {
		wp_set_current_user( $cid );

		// A core/html block wearing the wrapper class over forty bare URLs.
		$flood = '<!-- wp:html --><div class="wp-block-embed__wrapper"></div>';
		for ( $i = 0; $i < 40; $i++ ) {
			$flood .= "\n<p>https://minn-v049.example/no/{$tag}-flood-{$i}</p>";
		}
		$flood  .= "\n<!-- /wp:html -->";
		$lookups = 0;
		list( $st ) = $render( array( $flood ), 0 );
		$new = array_diff( $cache_ids(), $ids_before );
		$check( '08-1 a Contributor\'s bare URLs in an html block start no embed lookup', 200 === $st && 0 === $lookups, "status {$st}, lookups {$lookups}" );
		$check( '08-1 and leave no oembed_cache post', ! $new, count( $new ) . ' new rows' );

		// Twenty-five embed blocks, every lookup failing.
		$blocks = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$blocks[] = $embed( "https://minn-v049.example/no/{$tag}-many-{$i}" );
		}
		$lookups = 0;
		list( $st ) = $render( $blocks, 0 );
		$new = array_diff( $cache_ids(), $ids_before );
		$check( '08-1 embed lookups per request are capped', 200 === $st && $lookups <= $cap, "status {$st}, lookups {$lookups}" );
		$check( '08-1 failed lookups with no post leave no oembed_cache post', ! $new, count( $new ) . ' new rows' );
		$check( '08-1 no HTTP request was made', 0 === $http, "{$http} attempts" );

		// Controls: a real embed block still previews as the provider's player.
		$lookups = 0;
		list( $st, $out ) = $render( array( $embed( "https://minn-v049.example/ok/{$tag}-own" ) ), $own );
		$meta = array_filter( array_keys( (array) get_post_meta( $own ) ), function ( $k ) {
			return 0 === strpos( $k, '_oembed_' ) && 0 !== strpos( $k, '_oembed_time_' );
		} );
		$check( '08-1 control: an embed block on the Contributor\'s own draft previews', 200 === $st && 1 === $iframes( $out ) && false !== strpos( (string) $out[0], 'wp-block-embed__wrapper' ), substr( (string) ( $out[0] ?? '' ), 0, 200 ) );
		$check( '08-1 its answer is cached in that draft\'s meta, as the front end caches it', 1 === count( $meta ) && ! array_diff( $cache_ids(), $ids_before ), wp_json_encode( array_values( $meta ) ) );
		$lookups = 0;
		$render( array( $embed( "https://minn-v049.example/ok/{$tag}-own" ) ), $own );
		$check( '08-1 a second preview reads that cache', 0 === $lookups, "lookups {$lookups}" );

		// A URL only typed into the editor, not in the draft's saved content.
		$own_meta = function () use ( $own ) {
			return array_filter( array_keys( (array) get_post_meta( $own ) ), function ( $k ) {
				return 0 === strpos( $k, '_oembed_' );
			} );
		};
		$meta_before = $own_meta();
		$tr_unsaved  = $transients();
		list( $st, $out ) = $render( array( $embed( "https://minn-v049.example/ok/{$tag}-unsaved" ), $embed( "https://minn-v049.example/no/{$tag}-unsaved" ) ), $own );
		$check( 'N-7 an embed not in the draft\'s saved content still previews', 200 === $st && 1 === $iframes( $out ), substr( (string) ( $out[0] ?? '' ), 0, 200 ) );
		$check( 'N-7 and adds no row to the draft\'s meta, only expiring transients', ! array_diff( $own_meta(), $meta_before ) && 2 === count( array_diff( $transients(), $tr_unsaved ) ), wp_json_encode( array_values( array_diff( $own_meta(), $meta_before ) ) ) );

		// Review 2 (N2-7): the saved URL is matched whole, not as a substring.
		$meta_before = $own_meta();
		$render( array( $embed( "https://minn-v049.example/ok/{$tag}-ow" ), $embed( "https://minn-v049.example/ok/{$tag}" ) ), $own );
		$check( 'N2-7 a prefix of a saved embed URL adds no row to the draft\'s meta', ! array_diff( $own_meta(), $meta_before ), wp_json_encode( array_values( array_diff( $own_meta(), $meta_before ) ) ) );

		// Review 2 (N2-6): a player frame is vouched only when the live oEmbed
		// object answers the URL with core's own provider.
		$yt     = 'https://www.youtube.com/watch?v=minnv049' . $tag;
		$player = 'https://www.youtube.com/embed/minnv049' . $tag;
		$yt_answer = function ( $result, $url ) use ( $yt, $player ) {
			return $url === $yt ? '<iframe title="minn-v049-yt" src="' . esc_url( $player ) . '"></iframe>' : $result;
		};
		add_filter( 'pre_oembed_result', $yt_answer, 98, 2 );
		$frames = function () use ( $call, $embed, $yt ) {
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => array( $embed( $yt ) ), 'post' => 0 ) );
			return array( $st, array_merge( array(), ...array_values( array_map( 'array_values', (array) ( $d['embed_frames'] ?? array() ) ) ) ) );
		};
		$live      = _wp_oembed_get_object();
		$providers = $live->providers;
		try {
			list( $st, $vouched ) = $frames();
			$check( 'N2-6 control: a core provider\'s player frame is vouched', 200 === $st && array( $player ) === $vouched, wp_json_encode( $vouched ) );
			$live->providers = array_filter( $providers, function ( $p ) {
				return false === strpos( (string) $p[0], 'youtube.com/oembed' );
			} );
			list( $st, $vouched ) = $frames();
			$check( 'N2-6 with core\'s provider removed on the live object, no frame is vouched', 200 === $st && ! $vouched, wp_json_encode( $vouched ) );
			$live->providers = array( '#https?://((m|www)\.)?youtube\.com/watch.*#i' => array( 'https://minn-v049.example/oembed', true ) ) + $providers;
			list( $st, $vouched ) = $frames();
			$check( 'N2-6 with a plugin provider ahead of core\'s, no frame is vouched', 200 === $st && ! $vouched, wp_json_encode( $vouched ) );
		} finally {
			$live->providers = $providers;
			remove_filter( 'pre_oembed_result', $yt_answer, 98 );
		}

		$held = $wp_embed->post_ID;
		list( $st, $out ) = $render( array( $embed( "https://minn-v049.example/ok/{$tag}-nopost" ) ), 0 );
		$check( '08-1 control: with no post an embed block previews too', 200 === $st && 1 === $iframes( $out ), substr( (string) ( $out[0] ?? '' ), 0, 200 ) );
		$check( '08-1 with no post the answer is an expiring transient, not a post', count( array_diff( $transients(), $tr_before ) ) >= 1 && ! array_diff( $cache_ids(), $ids_before ) );
		$check( '08-1 WP_Embed\'s post id is put back after the render', $held === $wp_embed->post_ID, wp_json_encode( $wp_embed->post_ID ) );

		$render( array( $embed( "https://minn-v049.example/ok/{$tag}-other" ) ), $other );
		$check( '08-1 a post the Contributor cannot edit gets no cache rows', '' === implode( '', array_filter( array_keys( (array) get_post_meta( $other ) ), function ( $k ) {
			return 0 === strpos( $k, '_oembed_' );
		} ) ) );

		// Legacy block name and an embed nested in a group.
		$legacy = $embed( "https://minn-v049.example/ok/{$tag}-legacy", 'core-embed/youtube' );
		$nested = '<!-- wp:group --><div class="wp-block-group">' . $embed( "https://minn-v049.example/ok/{$tag}-nested" ) . '</div><!-- /wp:group -->';
		list( $st, $out ) = $render( array( $legacy, $nested ), $own );
		$check( '08-1 control: a pre-5.6 embed block name and a nested embed both preview', 200 === $st && 2 === $iframes( $out ), wp_json_encode( array_map( function ( $h ) {
			return substr( (string) $h, 0, 120 );
		}, $out ) ) );

		// A post with more embeds than one request may look up: cached
		// answers are free, so the next load fills in the rest.
		$blocks = array();
		for ( $i = 0; $i < 14; $i++ ) {
			$blocks[] = $embed( "https://minn-v049.example/ok/{$tag}-fill-{$i}" );
		}
		$lookups = 0;
		list( , $first ) = $render( $blocks, $own );
		$first_lookups   = $lookups;
		$lookups         = 0;
		list( , $second ) = $render( $blocks, $own );
		$check( '08-1 control: a post with 14 embeds previews them all by its second load', $cap === $first_lookups && $cap === $iframes( $first ) && 14 === $iframes( $second ) && 4 === $lookups, "first {$first_lookups}/" . $iframes( $first ) . ", second {$lookups}/" . $iframes( $second ) );

		// The class is closed for every caller, not only untrusted ones.
		wp_set_current_user( $admin[0]->ID );
		$lookups = 0;
		$render( array( str_replace( '-flood-', '-admin-flood-', $flood ) ), 0 );
		$check( '08-1 an administrator\'s bare URLs in an html block start no lookup either', 0 === $lookups, "lookups {$lookups}" );
		$check( '08-1 still no HTTP request was made', 0 === $http, "{$http} attempts" );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		remove_filter( 'pre_oembed_result', $answer, 99 );
		remove_filter( 'pre_http_request', $no_http, 1 );
		foreach ( array_diff( $cache_ids(), $ids_before ) as $id ) {
			wp_delete_post( $id, true );
		}
		foreach ( array_diff( $transients(), $tr_before ) as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
		wp_delete_post( $own, true );
		wp_delete_post( $other, true );
		$drop_user( $cid );
	}
} )();

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
 * Class guards (static). An embed lookup over markup a caller sent runs
 * only where it is capped and cached in an editable post or a transient,
 * never through WP_Embed's no-post cache (autoembed or the_content with no
 * post leave an oembed_cache post per URL). Each FluentSnippets write that
 * can put code on the site or start it asks the authoring switch.
 */
( function () use ( $check ) {
	$root = dirname( __DIR__ );
	$src  = array();
	$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( '.php' === substr( (string) $f, -4 ) ) {
			$src[ substr( (string) $f, strlen( $root ) + 1 ) ] = (string) file_get_contents( (string) $f );
		}
	}
	// Pattern => file => how many times it may appear, each one reviewed.
	$reviewed = array(
		'/->\s*autoembed\s*\(/'                                             => array(),
		'/apply_filters\(\s*[\'"]the_content[\'"]/'                         => array(),
		'/_wp_oembed_get_object\(\)\s*->\s*(get_html|get_data|fetch)\s*\(/' => array(),
		// preview_embed(): capped, transient-cached.
		'/\bwp_oembed_get\s*\(/'                                            => array( 'includes/class-minn-admin-rest.php' => 1 ),
		// preview_embed(): capped, with post_ID set to an editable post.
		'/\$wp_embed->shortcode\s*\(/'                                      => array( 'includes/class-minn-admin-rest.php' => 1 ),
		// refresh_oembed_cache(): a post's own embeds after a save of it.
		'/->\s*cache_oembed\s*\(/'                                          => array( 'includes/class-minn-admin.php' => 1 ),
	);
	foreach ( $reviewed as $re => $want ) {
		$found = array();
		foreach ( $src as $file => $text ) {
			$n = preg_match_all( $re, $text );
			if ( $n ) {
				$found[ $file ] = $n;
			}
		}
		ksort( $found );
		ksort( $want );
		$check( "guard: embed lookups {$re} only where reviewed", $found === $want, wp_json_encode( $found ) );
	}
	$fluent = (string) ( $src['includes/adapters/fluent-snippets.php'] ?? '' );
	if ( '' === $fluent ) {
		return;
	}
	$writes = 0;
	$asked  = 0;
	foreach ( array_slice( explode( 'minn_admin_endpoint(', $fluent ), 1 ) as $route ) {
		if ( preg_match( "/'methods'\s*=>\s*'(POST|PUT|PATCH)'/", $route ) ) {
			++$writes;
			$asked += false !== strpos( $route, 'minn_admin_fluent_authoring_refused(' ) ? 1 : 0;
		}
	}
	$check( 'guard: every FluentSnippets write route that can add, change or start code asks the authoring switch', $writes > 0 && $writes === $asked, "{$asked} of {$writes}" );
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
