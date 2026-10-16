<?php
/**
 * Pre-RC census of Minn's own write routes for a save that rewrites what
 * nobody changed, and the island preview's dynamic-content guard.
 *
 * Each write section seeds an awkward value straight into the store (a
 * switch another plugin filters on the registered copy, a support a theme
 * adds at runtime, a taxonomy attached from its own side, a permalink
 * structure stored without its leading slash, CSS a filter adds to the
 * front end's copy, a palette color, a policy for a role that is gone, a
 * card this account cannot see, a block style for a block that is not
 * registered), replays what Minn's client sends for an edit of something
 * else, and checks the stored bytes. A control in each shows a real change
 * is still written.
 *
 * The preview section checks that a block carrying JetEngine's or Kadence
 * Blocks Pro's dynamic markup previews empty for a caller who may not
 * author dynamic data, with harmless markup only, and that the request
 * keys JetEngine reads an object from are set aside while the preview
 * renders and put back after.
 *
 * The redaction section seeds credential rows the database browser and the
 * egress fence must hide, and checks each is hidden.
 *
 * Every section runs inside a database transaction that is rolled back.
 * Sections SKIP when their plugin is inactive.
 *
 * Run: wp eval-file tests/security-v046-prerc-core.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};

if ( ! current_user_can( 'manage_options' ) || is_multisite() ) {
	echo "SKIP  run on a single site with --user=<an administrator>\n";
	return;
}
global $wpdb;
$admin = get_current_user_id();
rest_get_server();

/*
 * Post types and taxonomies (#6 under this lens). The modal shows the
 * REGISTERED definition, and a save that sent one switch wrote every other
 * switch, the supports and the taxonomies from it: a switch another plugin
 * filters, a support a theme adds at runtime and a taxonomy that attaches
 * itself from its own side all went into the vendor's store.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	$row = function ( $kind, $slug ) use ( $call ) {
		list( , $d ) = $call( 'GET', '/minn-admin/v1/' . ( 'type' === $kind ? 'post-types' : 'taxonomies' ) );
		foreach ( (array) ( $d[ 'type' === $kind ? 'types' : 'taxonomies' ] ?? array() ) as $r ) {
			if ( $slug === $r['slug'] ) {
				return $r;
			}
		}
		return null;
	};
	$minn = function ( $option, $slug ) {
		return (array) ( ( (array) get_option( $option, array() ) )[ $slug ] ?? array() );
	};
	$wpdb->query( 'START TRANSACTION' );
	$filter = null;
	try {
		$slug = 'minnpc' . wp_rand( 1000, 9999 );
		$tax  = 'minnpct' . wp_rand( 1000, 9999 );
		$call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $slug, 'singular' => 'Dish', 'plural' => 'Dishes', 'description' => 'x', 'public' => 1, 'supports' => array( 'title', 'editor' ), 'taxonomies' => array(), 'backend' => 'minn' ) );
		$call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $tax, 'singular' => 'Pot', 'plural' => 'Pots', 'object_types' => array( $slug ), 'backend' => 'minn' ) );
		// The registered copy differs from the store the way real sites make
		// it differ: a filter turns REST on, a theme adds excerpts, and the
		// taxonomy attaches itself from its own definition.
		$filter = function ( $args, $name ) use ( $slug ) {
			if ( $name === $slug ) {
				$args['show_in_rest'] = true;
			}
			return $args;
		};
		add_filter( 'register_post_type_args', $filter, 10, 2 );
		$reload = function () use ( $slug, $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				unregister_taxonomy( $tax );
			}
			if ( post_type_exists( $slug ) ) {
				unregister_post_type( $slug );
			}
			Minn_Admin_CPT::register_native_types();
			Minn_Admin_CPT::register_native_taxonomies();
			add_post_type_support( $slug, 'excerpt' );
		};
		$reload();
		$stored = $minn( Minn_Admin_CPT::OPTION, $slug );
		$shown  = $row( 'type', $slug );
		$check( 'types: the fixture shows the filtered switch, the runtime support and the attached taxonomy', ! empty( $shown['show_in_rest'] ) && in_array( 'excerpt', (array) $shown['supports'], true ) && in_array( $tax, (array) $shown['taxonomies'], true ) && empty( $stored['show_in_rest'] ), wp_json_encode( array( $shown, $stored ) ) );

		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'has_archive' => 1, 'base' => $shown['stamp'] ?? '' ) );
		$now = $minn( Minn_Admin_CPT::OPTION, $slug );
		$check( 'types: a save of one switch answers 200 and writes it (control)', 200 === $st && ! empty( $now['has_archive'] ), $st . ' ' . wp_json_encode( $now ) );
		$check( 'types: and keeps the stored REST switch a filter turns on in the registered copy', array_key_exists( 'show_in_rest', $stored ) && $stored['show_in_rest'] === ( $now['show_in_rest'] ?? null ), wp_json_encode( array( $stored['show_in_rest'] ?? null, $now['show_in_rest'] ?? null ) ) );
		$check( 'types: and keeps the stored supports without the runtime one', $stored['supports'] === ( $now['supports'] ?? null ), wp_json_encode( $now['supports'] ?? null ) );
		$check( 'types: and keeps the stored taxonomies without the one attached from its own side', $stored['taxonomies'] === ( $now['taxonomies'] ?? null ), wp_json_encode( $now['taxonomies'] ?? null ) );

		$reload();
		$shown = $row( 'type', $slug );
		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'supports' => array_merge( (array) $shown['supports'], array( 'author' ) ), 'base' => $shown['stamp'] ?? '' ) );
		$now = $minn( Minn_Admin_CPT::OPTION, $slug );
		$check( 'types: a ticked support is written, and only it', 200 === $st && array( 'title', 'editor', 'author' ) === ( $now['supports'] ?? null ), $st . ' ' . wp_json_encode( $now['supports'] ?? null ) );

		$reload();
		$shown = $row( 'type', $slug );
		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'supports' => array_values( array_diff( (array) $shown['supports'], array( 'editor' ) ) ), 'base' => $shown['stamp'] ?? '' ) );
		$now = $minn( Minn_Admin_CPT::OPTION, $slug );
		$check( 'types: an unticked support is written', 200 === $st && array( 'title', 'author' ) === ( $now['supports'] ?? null ), $st . ' ' . wp_json_encode( $now['supports'] ?? null ) );

		$reload();
		$shown = $row( 'type', $slug );
		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'show_in_rest' => 1, 'base' => $shown['stamp'] ?? '' ) );
		$now = $minn( Minn_Admin_CPT::OPTION, $slug );
		$check( 'types: a switch sent as the modal showed it is not written', 200 === $st && $stored['show_in_rest'] === ( $now['show_in_rest'] ?? null ), $st . ' ' . wp_json_encode( $now['show_in_rest'] ?? null ) );
		$reload();
		$shown = $row( 'type', $slug );
		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'show_in_rest' => 0, 'base' => $shown['stamp'] ?? '' ) );
		$now = $minn( Minn_Admin_CPT::OPTION, $slug );
		$check( 'types: a switch turned off is written (control)', 200 === $st && array_key_exists( 'show_in_rest', $now ) && empty( $now['show_in_rest'] ), $st . ' ' . wp_json_encode( $now ) );

		// The taxonomy side: a post type attached at runtime shows in the
		// modal's list and stays out of the taxonomy's store.
		$reload();
		$tax2 = 'minnpcu' . wp_rand( 1000, 9999 );
		$call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $tax2, 'singular' => 'Pan', 'plural' => 'Pans', 'object_types' => array( 'post' ), 'backend' => 'minn' ) );
		if ( taxonomy_exists( $tax2 ) ) {
			unregister_taxonomy( $tax2 );
		}
		Minn_Admin_CPT::register_native_taxonomies();
		register_taxonomy_for_object_type( $tax2, $slug );
		$tstored = $minn( Minn_Admin_CPT::OPTION_TAX, $tax2 );
		$tshown  = $row( 'tax', $tax2 );
		list( $st ) = $call( 'POST', "/minn-admin/v1/taxonomies/{$tax2}", array( 'hierarchical' => 1, 'base' => $tshown['stamp'] ?? '' ) );
		$tnow = $minn( Minn_Admin_CPT::OPTION_TAX, $tax2 );
		$check( 'taxonomies: a save of one switch writes it (control)', 200 === $st && ! empty( $tnow['hierarchical'] ), $st . ' ' . wp_json_encode( $tnow ) );
		$check( 'taxonomies: and keeps the stored post types without one attached at runtime', in_array( $slug, (array) $tshown['object_types'], true ) && $tstored['object_types'] === ( $tnow['object_types'] ?? null ), wp_json_encode( array( $tshown['object_types'] ?? null, $tnow['object_types'] ?? null ) ) );
		if ( taxonomy_exists( $tax2 ) ) {
			unregister_taxonomy( $tax2 );
		}
		Minn_Admin_CPT::register_native_taxonomies();
		register_taxonomy_for_object_type( $tax2, $slug );
		$tshown = $row( 'tax', $tax2 );
		list( $st ) = $call( 'POST', "/minn-admin/v1/taxonomies/{$tax2}", array( 'object_types' => array_values( array_diff( (array) $tshown['object_types'], array( 'post' ) ) ), 'base' => $tshown['stamp'] ?? '' ) );
		$tnow = $minn( Minn_Admin_CPT::OPTION_TAX, $tax2 );
		$check( 'taxonomies: an unticked post type is written, and the runtime one stays out', 200 === $st && array() === ( $tnow['object_types'] ?? null ), $st . ' ' . wp_json_encode( $tnow['object_types'] ?? null ) );

		// CPT UI's store: an untouched switch keeps CPT UI's own bytes (its
		// select stores '' until someone picks), and a taxonomy CPT UI
		// attaches from the taxonomy's side stays out of the type's list.
		if ( defined( 'CPTUI_VERSION' ) && function_exists( 'cptui_register_single_post_type' ) && function_exists( 'cptui_register_single_taxonomy' ) ) {
			$cpt  = 'minnpcc' . wp_rand( 1000, 9999 );
			$ctax = 'minnpcx' . wp_rand( 1000, 9999 );
			list( $st1 ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $cpt, 'singular' => 'Book', 'plural' => 'Books', 'description' => 'x', 'public' => 1, 'supports' => array( 'title' ), 'backend' => 'cptui' ) );
			$types = (array) get_option( 'cptui_post_types', array() );
			if ( isset( $types[ $cpt ] ) && ! post_type_exists( $cpt ) ) {
				cptui_register_single_post_type( $types[ $cpt ] );
			}
			list( $st2 ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $ctax, 'singular' => 'Genre', 'plural' => 'Genres', 'object_types' => array( $cpt ), 'backend' => 'cptui' ) );
			if ( 200 !== $st1 || 200 !== $st2 || ! isset( $types[ $cpt ] ) ) {
				$skip( 'CPT UI would not take a test post type (' . $st1 . '/' . $st2 . ')' );
			} else {
				$types[ $cpt ]['show_in_rest'] = '';
				update_option( 'cptui_post_types', $types );
				foreach ( array( $ctax ) as $t ) {
					if ( taxonomy_exists( $t ) ) {
						unregister_taxonomy( $t );
					}
				}
				if ( post_type_exists( $cpt ) ) {
					unregister_post_type( $cpt );
				}
				cptui_register_single_post_type( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] );
				cptui_register_single_taxonomy( ( (array) get_option( 'cptui_taxonomies', array() ) )[ $ctax ] );
				$cstored = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$cshown  = $row( 'type', $cpt );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cpt}", array( 'has_archive' => 1, 'base' => $cshown['stamp'] ?? '' ) );
				$cnow = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$check( 'CPT UI: a save of one switch writes it (control)', 200 === $st && 'true' === ( $cnow['has_archive'] ?? null ), $st . ' ' . wp_json_encode( $cnow['has_archive'] ?? null ) );
				$check( 'CPT UI: and keeps the REST switch as CPT UI stored it', '' === ( $cnow['show_in_rest'] ?? null ), wp_json_encode( $cnow['show_in_rest'] ?? null ) );
				$check( 'CPT UI: and keeps the taxonomies without the one attached from the taxonomy side', in_array( $ctax, (array) $cshown['taxonomies'], true ) && ( $cstored['taxonomies'] ?? array() ) === ( $cnow['taxonomies'] ?? array() ), wp_json_encode( array( $cstored['taxonomies'] ?? null, $cnow['taxonomies'] ?? null ) ) );
			}
		} else {
			$skip( 'CPT UI inactive' );
		}

		// ACF: the title is ACF's own copy of the plural label, set from it
		// on ACF's screen; a switch save leaves it alone.
		if ( function_exists( 'acf_get_setting' ) && acf_get_setting( 'enable_post_types' ) && function_exists( 'acf_get_internal_post_type_posts' ) ) {
			$acf = 'minnpca' . wp_rand( 1000, 9999 );
			list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $acf, 'singular' => 'Car', 'plural' => 'Cars', 'description' => 'x', 'public' => 1, 'supports' => array( 'title' ), 'backend' => 'acf' ) );
			$find = function () use ( $acf ) {
				foreach ( (array) acf_get_internal_post_type_posts( 'acf-post-type' ) as $settings ) {
					if ( ( $settings['post_type'] ?? '' ) === $acf ) {
						return $settings;
					}
				}
				return null;
			};
			$settings = $find();
			if ( 200 !== $st || ! $settings || empty( $settings['ID'] ) ) {
				$skip( 'ACF would not take a test post type (' . $st . ')' );
			} else {
				$settings['title'] = 'Cars (fleet)';
				acf_update_internal_post_type( wp_slash( $settings ), 'acf-post-type' );
				if ( ! post_type_exists( $acf ) ) {
					register_post_type( $acf, array( 'labels' => array( 'name' => 'Cars', 'singular_name' => 'Car' ), 'public' => true, 'show_ui' => true ) );
				}
				$ashown = $row( 'type', $acf );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$acf}", array( 'has_archive' => 1, 'base' => $ashown['stamp'] ?? '' ) );
				$title = get_post_field( 'post_title', (int) $settings['ID'] );
				$check( 'ACF: a save of one switch answers 200', 200 === $st, (string) $st );
				$check( 'ACF: and keeps the title ACF stores apart from the plural label', 'Cars (fleet)' === $title, wp_json_encode( $title ) );
			}
		} else {
			$skip( 'ACF post types off' );
		}

		// JetEngine: its edit shape keeps a post type's plural label in
		// general_settings, so the save rebuilt it from the registered copy,
		// entity-decoded.
		if ( function_exists( 'jet_engine' ) && is_object( jet_engine()->cpt ) && is_object( jet_engine()->cpt->data ) ) {
			$jet = 'minnpcj' . wp_rand( 1000, 9999 );
			list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $jet, 'singular' => 'Fish', 'plural' => 'Fishes', 'description' => '', 'public' => 1, 'supports' => array( 'title' ), 'backend' => 'jet' ) );
			$table = $wpdb->prefix . 'jet_post_types';
			$jrow  = $wpdb->get_row( $wpdb->prepare( "SELECT id, labels FROM {$table} WHERE slug = %s", $jet ), ARRAY_A ); // phpcs:ignore
			$jlab  = $jrow ? json_decode( wp_json_encode( maybe_unserialize( $jrow['labels'] ) ), true ) : null;
			if ( 200 !== $st || ! is_array( $jlab ) ) {
				$skip( 'JetEngine would not take a test post type (' . $st . ')' );
			} else {
				$jlab['name'] = 'Fish &amp; Chips';
				$wpdb->update( $table, array( 'labels' => maybe_serialize( $jlab ) ), array( 'id' => (int) $jrow['id'] ) );
				if ( is_object( jet_engine()->db ) && property_exists( jet_engine()->db, 'query_cache' ) ) {
					jet_engine()->db->query_cache = array();
				}
				jet_engine()->cpt->data->reset_raw_cache();
				if ( post_type_exists( $jet ) ) {
					unregister_post_type( $jet );
				}
				register_post_type( $jet, array( 'labels' => array( 'name' => 'Fish &amp; Chips', 'singular_name' => 'Fish' ), 'public' => true, 'show_ui' => true ) );
				$jshown = $row( 'type', $jet );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$jet}", array( 'has_archive' => 1, 'base' => $jshown['stamp'] ?? '' ) );
				if ( is_object( jet_engine()->db ) && property_exists( jet_engine()->db, 'query_cache' ) ) {
					jet_engine()->db->query_cache = array();
				}
				$after = $wpdb->get_var( $wpdb->prepare( "SELECT labels FROM {$table} WHERE id = %d", (int) $jrow['id'] ) ); // phpcs:ignore
				$after = json_decode( wp_json_encode( maybe_unserialize( $after ) ), true );
				$check( 'JetEngine: a save of one switch answers 200', 200 === $st, (string) $st );
				$check( 'JetEngine: and keeps the plural label as JetEngine stored it', 'Fish &amp; Chips' === ( $after['name'] ?? null ), wp_json_encode( $after['name'] ?? null ) );
			}
		} else {
			$skip( 'JetEngine inactive' );
		}
	} finally {
		if ( $filter ) {
			remove_filter( 'register_post_type_args', $filter, 10 );
		}
		$wpdb->query( 'ROLLBACK' );
	}
} )();

/*
 * Permalinks: the page sends the structure and both bases when any one
 * changed, and the route cleaned and wrote all three, so a new category
 * base gave a structure stored without its leading slash one.
 */
( function () use ( $check, $call, $wpdb ) {
	$wpdb->query( 'START TRANSACTION' );
	try {
		update_option( 'permalink_structure', 'archives/%post_id%' );
		update_option( 'category_base', '' );
		update_option( 'tag_base', '' );
		$GLOBALS['wp_rewrite']->init();
		list( , $shown ) = $call( 'GET', '/minn-admin/v1/permalinks' );
		$stored = get_option( 'permalink_structure' );
		list( $st ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => $shown['structure'], 'category_base' => 'minnprerc-topics', 'tag_base' => $shown['tag_base'] ) );
		$check( 'permalinks: a category base edit answers 200 and is written (control)', 200 === $st && 'minnprerc-topics' === get_option( 'category_base' ), $st . ' ' . wp_json_encode( get_option( 'category_base' ) ) );
		$check( 'permalinks: and keeps the stored structure byte for byte', 'archives/%post_id%' === $stored && $stored === get_option( 'permalink_structure' ), wp_json_encode( get_option( 'permalink_structure' ) ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => '/minnprerc/%postname%/', 'category_base' => 'minnprerc-topics', 'tag_base' => '' ) );
		$check( 'permalinks: a structure edit is written (control)', 200 === $st && '/minnprerc/%postname%/' === get_option( 'permalink_structure' ), $st . ' ' . wp_json_encode( get_option( 'permalink_structure' ) ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => '/minnprerc/', 'category_base' => 'minnprerc-topics', 'tag_base' => '' ) );
		$check( 'permalinks: an edited structure without a tag is still refused (control)', 400 === $st, (string) $st );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( 'alloptions', 'options' );
		$GLOBALS['wp_rewrite']->init();
	}
} )();

/*
 * Custom CSS: the edit key was wp_get_custom_css(), the front end's copy,
 * which a filter may add rules to; an edit saved those rules into the post.
 * A save of the stylesheet as stored writes nothing, and a copy opened
 * before someone else saved is refused.
 */
( function () use ( $check, $call, $wpdb ) {
	$wpdb->query( 'START TRANSACTION' );
	$add = function ( $css ) {
		return $css . "\n/* minnprerc-added-by-a-plugin */";
	};
	try {
		$stored = ".minnprerc-a { color: red; }\n";
		wp_update_custom_css_post( $stored );
		add_filter( 'wp_get_custom_css', $add );
		list( , $shown ) = $call( 'GET', '/minn-admin/v1/custom-css' );
		$check( 'custom CSS: the edit key is the stylesheet as stored', $stored === ( $shown['css'] ?? null ), wp_json_encode( $shown['css'] ?? null ) );
		$post = wp_get_custom_css_post();
		$was  = $post ? $post->post_modified_gmt . '|' . count( wp_get_post_revisions( $post->ID ) ) : '';
		list( $st ) = $call( 'POST', '/minn-admin/v1/custom-css', array( 'css' => $shown['css'] ?? '', 'base' => $shown['stamp'] ?? '' ) );
		$post = wp_get_custom_css_post();
		$check( 'custom CSS: a save of the stylesheet as stored writes nothing', 200 === $st && $post && $was === $post->post_modified_gmt . '|' . count( wp_get_post_revisions( $post->ID ) ), $st . ' ' . $was );
		list( $st ) = $call( 'POST', '/minn-admin/v1/custom-css', array( 'css' => ( $shown['css'] ?? '' ) . ".minnprerc-b { color: blue; }\n", 'base' => $shown['stamp'] ?? '' ) );
		$post = wp_get_custom_css_post();
		$check( 'custom CSS: an edit is written without the rules a filter adds to the front end', 200 === $st && $post && $stored . ".minnprerc-b { color: blue; }\n" === $post->post_content, $st . ' ' . wp_json_encode( $post ? $post->post_content : null ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/custom-css', array( 'css' => '.minnprerc-c {}', 'base' => $shown['stamp'] ?? '' ) );
		$post = wp_get_custom_css_post();
		$check( 'custom CSS: a copy opened before another save is refused with 409', 409 === $st && false === strpos( (string) ( $post ? $post->post_content : '' ), 'minnprerc-c' ), (string) $st );
	} finally {
		remove_filter( 'wp_get_custom_css', $add );
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( 'alloptions', 'options' );
	}
} )();

/*
 * Appearance (#9 under this lens): a palette map that names one color reset
 * every other color to Minn's base, so a client could not send only the
 * color it changed without wiping the rest.
 */
( function () use ( $check, $call, $wpdb, $admin ) {
	$wpdb->query( 'START TRANSACTION' );
	try {
		$palette = Minn_Admin::scheme_base_tokens();
		$palette['dark']['accent']  = '#112233';
		$palette['dark']['text']    = '#445566';
		$palette['light']['accent'] = '#778899';
		Minn_Admin::save_user_appearance( $admin, array( 'scheme' => 'custom', 'custom' => $palette, 'font' => 'minn' ) );
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/me/appearance', array( 'custom' => array( 'dark' => array( 'accent' => '#abcdef' ) ) ) );
		$now = Minn_Admin::get_user_appearance( $admin );
		$check( 'appearance: one color sent is written (control)', 200 === $st && '#abcdef' === $now['custom']['dark']['accent'], $st . ' ' . wp_json_encode( $now['custom']['dark'] ) );
		$check( 'appearance: and the rest of the stored palette stays', '#445566' === $now['custom']['dark']['text'] && '#778899' === $now['custom']['light']['accent'], wp_json_encode( $now['custom'] ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/me/appearance', array( 'custom' => array( 'dark' => array( 'text' => 'not a color' ) ) ) );
		$now = Minn_Admin::get_user_appearance( $admin );
		$check( 'appearance: a color that is not a hex value keeps the stored one', 200 === $st && '#445566' === $now['custom']['dark']['text'], wp_json_encode( $now['custom']['dark']['text'] ) );
		list( $st ) = $call( 'POST', "/minn-admin/v1/users/{$admin}/appearance", array( 'custom' => array( 'light' => array( 'text' => '#010203' ) ) ) );
		$now = Minn_Admin::get_user_appearance( $admin );
		$check( 'appearance: the user page writes one color and keeps the rest', 200 === $st && '#010203' === $now['custom']['light']['text'] && '#778899' === $now['custom']['light']['accent'] && '#abcdef' === $now['custom']['dark']['accent'], $st . ' ' . wp_json_encode( $now['custom'] ) );

		Minn_Admin::save_site_appearance( array( 'scheme' => 'custom', 'custom' => $palette, 'mode' => 'dark' ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/site-appearance', array( 'custom' => array( 'light' => array( 'accent' => '#0a0b0c' ) ) ) );
		$site = Minn_Admin::site_appearance();
		$check( 'site appearance: one color sent is written and the rest of the palette stays', 200 === $st && '#0a0b0c' === $site['custom']['light']['accent'] && '#112233' === $site['custom']['dark']['accent'] && '#445566' === $site['custom']['dark']['text'], $st . ' ' . wp_json_encode( $site['custom'] ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/site-appearance', array( 'mode' => 'light' ) );
		$site = Minn_Admin::site_appearance();
		$check( 'site appearance: a mode-only save keeps the scheme and palette (control)', 200 === $st && 'light' === $site['mode'] && 'custom' === $site['scheme'] && '#0a0b0c' === $site['custom']['light']['accent'], $st . ' ' . wp_json_encode( $site ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $admin, 'user_meta' );
	}
} )();

/*
 * Role defaults: the page sends every policy it shows and the route stored
 * that set whole. A policy for a role that is not registered right now, and
 * the sign-in policy of a role without Minn access (the page shows no
 * choice for it), were dropped by any save; a Minn toolbar a role kept
 * after losing edit_posts made every save answer 400.
 */
( function () use ( $check, $call, $wpdb ) {
	$wpdb->query( 'START TRANSACTION' );
	try {
		update_option( 'minn_admin_role_defaults', array(
			'*'               => array( 'signin' => 'minn' ),
			'minnprerc_gone'  => array( 'toolbar' => 'off' ),
			'subscriber'      => array( 'signin' => 'choice', 'toolbar' => 'minn' ),
		) );
		list( , $shown ) = $call( 'GET', '/minn-admin/v1/role-defaults' );
		// What the page sends: every role it lists, the sign-in choice only
		// for roles with Minn access, one change (editor's toolbar).
		$body = array( '*' => array( 'signin' => 'minn' ), 'subscriber' => array( 'toolbar' => 'minn' ), 'editor' => array( 'toolbar' => 'wp' ) );
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/role-defaults', array( 'policies' => $body, 'base' => $shown['stamp'] ?? '' ) );
		$now = (array) get_option( 'minn_admin_role_defaults', array() );
		$check( 'role defaults: a save with an untouched Minn toolbar on a role without Minn access answers 200', 200 === $st, $st . ' ' . wp_json_encode( $d ) );
		$check( 'role defaults: and writes the change (control)', 'wp' === ( $now['editor']['toolbar'] ?? null ), wp_json_encode( $now ) );
		$check( 'role defaults: and keeps the policy for a role that is not registered now', array( 'toolbar' => 'off' ) === ( $now['minnprerc_gone'] ?? null ), wp_json_encode( $now['minnprerc_gone'] ?? null ) );
		$check( 'role defaults: and keeps the sign-in choice of a role without Minn access', 'choice' === ( $now['subscriber']['signin'] ?? null ) && 'minn' === ( $now['subscriber']['toolbar'] ?? null ), wp_json_encode( $now['subscriber'] ?? null ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/role-defaults', array( 'policies' => array( 'author' => array( 'toolbar' => 'off' ) ), 'base' => $shown['stamp'] ?? '' ) );
		$check( 'role defaults: a copy opened before another save is refused with 409', 409 === $st, (string) $st );
		list( , $fresh ) = $call( 'GET', '/minn-admin/v1/role-defaults' );
		list( $st ) = $call( 'POST', '/minn-admin/v1/role-defaults', array( 'policies' => array( '*' => array( 'signin' => 'minn' ), 'contributor' => array( 'toolbar' => 'minn' ) ), 'base' => $fresh['stamp'] ?? '' ) );
		$check( 'role defaults: a role cleared on the page is cleared (control)', 200 === $st && ! isset( ( (array) get_option( 'minn_admin_role_defaults', array() ) )['editor'] ), $st . ' ' . wp_json_encode( get_option( 'minn_admin_role_defaults' ) ) );
		$sub = get_role( 'subscriber' );
		update_option( 'minn_admin_role_defaults', array( 'subscriber' => array( 'signin' => 'choice' ) ) );
		list( , $fresh ) = $call( 'GET', '/minn-admin/v1/role-defaults' );
		list( $st ) = $call( 'POST', '/minn-admin/v1/role-defaults', array( 'policies' => array( 'subscriber' => array( 'toolbar' => 'minn' ) ), 'base' => $fresh['stamp'] ?? '' ) );
		$check( 'role defaults: a new Minn toolbar for a role without Minn access is still refused (control)', $sub && ! $sub->has_cap( 'edit_posts' ) && 400 === $st, (string) $st );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'minn_admin_role_defaults', 'options' );
	}
} )();

/*
 * Overview cards: a slot whose stored card this account cannot see right
 * now shows a fallback, and the page sends what it shows, so a pick in
 * another slot replaced the hidden card for good. With `base` (the layout
 * the page showed) an untouched slot keeps the stored card.
 */
( function () use ( $check, $call, $wpdb, $admin ) {
	$wpdb->query( 'START TRANSACTION' );
	try {
		delete_user_meta( $admin, 'minn_admin_overview_metrics' );
		list( , $o ) = $call( 'GET', '/minn-admin/v1/overview' );
		$builtin = array_values( array_filter( array_map( function ( $r ) {
			return is_array( $r ) ? (string) ( $r['key'] ?? '' ) : '';
		}, (array) ( $o['stats'] ?? array() ) ) ) );
		$stored = array_merge( array( 'minnprercgone' ), array_slice( $builtin, 1, 3 ) );
		update_user_meta( $admin, 'minn_admin_overview_metrics', $stored );
		list( , $o ) = $call( 'GET', '/minn-admin/v1/overview' );
		$shown = array_values( (array) ( $o['metricKeys'] ?? array() ) );
		if ( count( $stored ) < 4 || 'minnprercgone' === ( $shown[0] ?? 'minnprercgone' ) || $stored[1] !== ( $shown[1] ?? null ) || $stored[2] !== ( $shown[2] ?? null ) ) {
			$check( 'overview: the fixture shows a fallback in the hidden card\'s slot and the stored cards in theirs', false, wp_json_encode( array( $stored, $shown ) ) );
		} else {
			$picked    = $shown;
			$picked[1] = $shown[2];
			$picked[2] = $shown[1];
			list( $st ) = $call( 'POST', '/minn-admin/v1/overview/metrics', array( 'keys' => $picked, 'base' => $shown ) );
			$now = get_user_meta( $admin, 'minn_admin_overview_metrics', true );
			$check( 'overview: a swap of two cards is written (control)', 200 === $st && $shown[2] === ( $now[1] ?? null ) && $shown[1] === ( $now[2] ?? null ), $st . ' ' . wp_json_encode( $now ) );
			$check( 'overview: and the untouched slot keeps the stored card this account cannot see', 'minnprercgone' === ( $now[0] ?? null ), wp_json_encode( $now ) );
			list( $st ) = $call( 'POST', '/minn-admin/v1/overview/metrics', array( 'keys' => array() ) );
			$check( 'overview: an empty list still clears the personal pick (control)', 200 === $st && '' === get_user_meta( $admin, 'minn_admin_overview_metrics', true ), (string) $st );
		}
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( $admin, 'user_meta' );
	}
} )();

/*
 * Styles: an edit read the user styles through core's REST copy, which
 * WP_Theme_JSON sanitizes against the blocks registered on the server, and
 * wrote it back whole, deleting the styles of every block it does not know.
 */
( function () use ( $check, $call, $wpdb ) {
	if ( ! class_exists( 'WP_Theme_JSON_Resolver' ) || ! current_user_can( 'edit_theme_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
		echo "SKIP  styles: needs global styles and an administrator with unfiltered_html\n";
		return;
	}
	$wpdb->query( 'START TRANSACTION' );
	try {
		$id = (int) WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$cfg = array(
			'version'                     => WP_Theme_JSON::LATEST_SCHEMA,
			'isGlobalStylesUserThemeJSON' => true,
			'styles'                      => array(
				'color'  => array( 'text' => '#111111' ),
				'blocks' => array( 'minnprerc/not-registered' => array( 'color' => array( 'text' => '#222222' ) ) ),
			),
			'settings'                    => array(),
		);
		wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( wp_json_encode( $cfg ) ) ) );
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/styles/update', array( 'changes' => array( 'styles.color.text' => '#333333' ) ) );
		$now = json_decode( (string) get_post_field( 'post_content', $id ), true );
		$check( 'styles: an edit of one path answers 200 and writes it (control)', 200 === $st && '#333333' === ( $now['styles']['color']['text'] ?? null ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'styles: and keeps the stored style of a block that is not registered', '#222222' === ( $now['styles']['blocks']['minnprerc/not-registered']['color']['text'] ?? null ), wp_json_encode( $now['styles'] ?? null ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * Island previews (registry #1, other vendors): JetEngine and Kadence
 * Blocks Pro resolve dynamic content when a block renders and check no read
 * access there, so for a caller who may not author dynamic data a block
 * carrying their dynamic markup previews empty, as Otter's does. The
 * markup here is harmless: each block would render its own words.
 */
( function () use ( $check, $skip, $call, $wpdb, $admin ) {
	$jet     = function_exists( 'jet_engine' );
	$kadence = defined( 'KBP_VERSION' );
	if ( ! $jet && ! $kadence ) {
		$skip( 'previews: JetEngine and Kadence Blocks Pro inactive' );
		return;
	}
	$render = function ( array $blocks ) use ( $call ) {
		list( $status, $data ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $blocks, 'post' => 0 ) );
		return array( $status, is_array( $data ) && isset( $data['rendered'] ) ? $data['rendered'] : null );
	};
	$wpdb->query( 'START TRANSACTION' );
	try {
		$contrib = wp_insert_user( array( 'user_login' => 'minnprerc-c' . wp_rand( 10000, 99999 ), 'user_email' => 'minnprerc' . wp_rand( 10000, 99999 ) . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'contributor' ) );
		if ( is_wp_error( $contrib ) || ! $contrib ) {
			$check( 'previews: a Contributor is made for the check', false, is_wp_error( $contrib ) ? $contrib->get_error_message() : '' );
			return;
		}
		$shapes = array();
		if ( $jet ) {
			$shapes['a JetEngine dynamic data attribute']      = '<!-- wp:paragraph {"jetEngineDynamicData":{}} --><p>minnprerc words one</p><!-- /wp:paragraph -->';
			$shapes['a JetEngine dynamic visibility attribute'] = '<!-- wp:paragraph {"jetDynamicVisibility":{}} --><p>minnprerc words two</p><!-- /wp:paragraph -->';
			$shapes['a JetEngine attribute on an inner block']  = '<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph {"jetEngineDynamicData":{}} --><p>minnprerc words three</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
			$shapes['a JetEngine block']                        = '<!-- wp:group --><div class="wp-block-group"><p>minnprerc words four</p><!-- wp:jet-engine/dynamic-field /--></div><!-- /wp:group -->';
		}
		if ( $kadence ) {
			$shapes['a Kadence dynamic attribute']     = '<!-- wp:paragraph {"kadenceDynamic":{}} --><p>minnprerc words five</p><!-- /wp:paragraph -->';
			$shapes['a Kadence conditional attribute'] = '<!-- wp:paragraph {"kadenceConditional":{}} --><p>minnprerc words six</p><!-- /wp:paragraph -->';
			$shapes['a Kadence inline dynamic span']   = '<!-- wp:paragraph --><p>minnprerc words seven <span class="kb-inline-dynamic">x</span></p><!-- /wp:paragraph -->';
			$shapes['an entity-spelled inline span']   = '<!-- wp:paragraph --><p>minnprerc words eight <span class="kb&#45;inline&#45;dynamic">x</span></p><!-- /wp:paragraph -->';
			$shapes['a Kadence dynamic HTML block']    = '<!-- wp:group --><div class="wp-block-group"><p>minnprerc words nine</p><!-- wp:kadence/dynamichtml {"uniqueID":"_minnprerc"} /--></div><!-- /wp:group -->';
		}
		$plain = '<!-- wp:paragraph --><p>minnprerc plain words</p><!-- /wp:paragraph -->';

		// The request keys JetEngine reads an object from are set aside
		// while the preview renders and put back after.
		$during = null;
		$spy    = function ( $content ) use ( &$during ) {
			if ( false !== strpos( (string) $content, 'minnprerc plain words' ) ) {
				$during = array( isset( $_GET['object'] ), isset( $_REQUEST['object'] ), isset( $_REQUEST['post_id'] ), isset( $_REQUEST['listing'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
			return $content;
		};
		add_filter( 'render_block', $spy );
		$held_get     = $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$held_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification
		wp_set_current_user( $contrib );
		try {
			foreach ( $shapes as $name => $markup ) {
				list( $status, $out ) = $render( array( $markup ) );
				$check( "previews: a Contributor's preview of {$name} is empty", 200 === $status && is_array( $out ) && '' === trim( (string) ( $out[0] ?? 'x' ) ), $status . ' ' . substr( wp_json_encode( $out ), 0, 160 ) );
			}
			$_GET['object']        = 7;
			$_REQUEST['object']    = 7;
			$_REQUEST['post_id']   = 7;
			$_REQUEST['listing']   = array( 'listing_source' => 'posts' );
			list( $status, $out ) = $render( array( $plain ) );
			$check( "previews: control: a Contributor's plain block still previews", 200 === $status && false !== strpos( (string) ( $out[0] ?? '' ), 'minnprerc plain words' ), $status . ' ' . wp_json_encode( $out ) );
			if ( $jet ) {
				$check( 'previews: the object JetEngine reads from the request is set aside while it renders', array( false, false, false, false ) === $during, wp_json_encode( $during ) );
				$check( 'previews: and put back after', 7 === ( $_GET['object'] ?? null ) && 7 === ( $_REQUEST['post_id'] ?? null ) && isset( $_REQUEST['listing'] ), wp_json_encode( array( $_GET, $_REQUEST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			}
		} finally {
			wp_set_current_user( $admin );
			$_GET     = $held_get;
			$_REQUEST = $held_request;
			remove_filter( 'render_block', $spy );
		}
		// An administrator may author dynamic data: nothing is blanked.
		$trusted = $jet ? $shapes['a JetEngine dynamic data attribute'] : $shapes['a Kadence dynamic attribute'];
		list( $status, $out ) = $render( array( $trusted ) );
		$check( "previews: control: an administrator's preview of the same block is not blanked", 200 === $status && false !== strpos( (string) ( $out[0] ?? '' ), 'minnprerc words' ), $status . ' ' . substr( wp_json_encode( $out ), 0, 160 ) );
	} finally {
		wp_set_current_user( $admin );
		$wpdb->query( 'ROLLBACK' );
	}
} )();

/*
 * Redaction: rows the fixture sweep found the database browser printing in
 * the clear (Formidable's PayPal Connect passwords and Connect verify token,
 * Sucuri's backup codes and their five-minute plain copy) and the sweep of
 * the freshly updated vendors that followed (Formidable Lite's Connect uuid,
 * Solid Security's Network Brute Force secret, OttoKit's SaaS secret, WP
 * Statistics' licence and encryption keys, Independent Analytics' salts,
 * SureCart's reCAPTCHA and webhook secrets and a shopper's password hash,
 * CleanTalk's self-call tokens, the email second factor's code hash). Each
 * row is seeded in a transaction where the lab has none, and a failing check
 * prints only whether a cell showed, never the cell.
 */
( function () use ( $check, $wpdb, $admin ) {
	$rows   = function ( $table, $col, $value ) {
		$r = new WP_REST_Request( 'GET', '/minn-admin/v1/db/rows' );
		foreach ( array( 'table' => $table, 'fcol' => $col, 'fq' => $value ) as $k => $v ) {
			$r->set_param( $k, $v );
		}
		return json_decode( wp_json_encode( rest_do_request( $r )->get_data() ), true );
	};
	$cell   = function ( $d, $col, $by, $val ) {
		$cols = wp_list_pluck( (array) ( $d['columns'] ?? array() ), 'name' );
		$ci   = array_search( $col, $cols, true );
		$bi   = array_search( $by, $cols, true );
		foreach ( (array) ( $d['rows'] ?? array() ) as $r ) {
			if ( false !== $bi && (string) $r[ $bi ] === (string) $val ) {
				return $r[ $ci ];
			}
		}
		return 'missing';
	};
	$hidden = function ( $c ) {
		return is_array( $c ) && ! empty( $c['redacted'] );
	};
	$shape  = function ( $c ) use ( $hidden ) {
		return $hidden( $c ) ? 'redacted' : ( 'missing' === $c ? 'missing' : 'shown, ' . strlen( is_array( $c ) ? wp_json_encode( $c ) : (string) $c ) . ' bytes' );
	};
	$opt    = function ( $name ) use ( $rows, $cell, $wpdb ) {
		return $cell( $rows( $wpdb->options, 'option_name', $name ), 'option_value', 'option_name', $name );
	};
	$token  = 'MinnPrerc' . wp_rand( 100000, 999999 ) . 'tok7Q2xZ' . wp_rand( 1000, 9999 );
	$wpdb->query( 'START TRANSACTION' );
	try {
		$seed = array(
			'frm_paypal_connect_server_password_live'           => 'srv_' . $token,
			'frm_paypal_connect_client_password_test'           => 'p@ss-Wd!12',
			'frm_stripe_lite_connect_verify'                    => array( 'token' => $token, 'time' => time() ),
			'frm_paypal_lite_connect_verify'                    => array( 'token' => 'pp' . $token, 'time' => time() ),
			'_transient_sucuriscan_backup_codes_reveal_' . $admin => array( '23456789', 'ABCDEFGH', 'K2M3N4P5' ),
			'_transient_sc_checkout_password_hash_minnprerc'    => '$wp$2y$10$abcdefghijklmnopqrstuuJ8xQ0Yq1Zb2Cc3Dd4Ee5Ff6Gg7Hh8',
			'frm-usage-uuid'                                    => md5( $token ),
			'itsec-storage'                                     => array( 'network-brute-force' => array( 'api_key' => 'k_' . $token, 'api_secret' => 's_' . $token ) ),
			'suretrigger_options'                               => array( 'secret_key' => 'st_' . $token ),
			'wp_statistics'                                     => array( 'geoip_license_key' => 'mm_' . $token ),
			'wp_statistics_licenses'                            => array( 'data-plus' => array( 'license' => 'lic_' . $token ) ),
			'wp_statistics_cipher_key'                          => base64_encode( 'minn prerc cipher key 32 bytes!!' ),
			'iawp_salt'                                         => 'Ab3.De4/Gh5Ij6Kl7Mn8Op9Qr0St1Uv2Wx3Yz4Ab5Cd6E',
			'iawp_request_payload_salt'                         => 'Zy9.Xw8/Vu7Ts6Rq5Po4Nm3Lk2Ji1Hg0Fe9Dc8Ba7Zy6X',
			'surecart_recaptcha_secret_key'                     => 'rc_' . $token,
			'surecart_registered_webhook'                       => base64_encode( 'sealed ' . $token ),
			'surecart_previous_webhook'                         => base64_encode( 'sealed old ' . $token ),
			'cleantalk_sfw_worker_self_tokens'                  => array( md5( 'cleantalk' . $token ) => time() + 120 ),
		);
		foreach ( $seed as $name => $value ) {
			if ( false === get_option( $name ) ) {
				add_option( $name, $value, '', false );
			}
		}
		foreach ( array_keys( $seed ) as $name ) {
			$c = $opt( $name );
			$check( "redaction: the database browser hides {$name}", $hidden( $c ), $shape( $c ) );
		}
		add_option( 'frm_stripe_lite_connect_verify_time', '1700000000', '', false );
		add_option( 'iawp_visitor_salt_refresh_interval', 'daily', '', false );
		$check( 'redaction: control: a row that only starts like one still shows', ! $hidden( $opt( 'frm_stripe_lite_connect_verify_time' ) ) && ! $hidden( $opt( 'iawp_visitor_salt_refresh_interval' ) ), $shape( $opt( 'iawp_visitor_salt_refresh_interval' ) ) );

		foreach ( array( 'sucuriscan_topt_backup_codes' => array( wp_hash_password( '23456789' ) ), '_two_factor_email_token' => array( 'hash' => wp_hash( '12345678' ), 'created' => time() ) ) as $key => $value ) {
			$umid = add_user_meta( $admin, $key, $value );
			$c    = $cell( $rows( $wpdb->usermeta, 'meta_key', $key ), 'meta_value', 'umeta_id', $umid );
			$check( "redaction: the database browser hides usermeta {$key}", $umid && $hidden( $c ), $shape( $c ) );
		}

		$check( 'redaction: other screens can ask whether a row is one, by name or by shape', method_exists( 'Minn_Admin_DB', 'is_credential_option' )
			&& Minn_Admin_DB::is_credential_option( '_transient_sc_checkout_password_hash_x1' ) && Minn_Admin_DB::is_credential_option( 'itsec-storage' )
			&& ! Minn_Admin_DB::is_credential_option( 'iawp_visitor_salt_refresh_interval' ) );

		// The egress fence takes its credentials from the same rows: a
		// token-shaped value from one never leaves a minn-admin/v1 response.
		if ( class_exists( 'Minn_Admin_Egress' ) ) {
			Minn_Admin_Egress::reset();
			$server = rest_get_server();
			register_rest_route( 'minn-admin/v1', '/minn-prerc-egress/leak', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
				$verify = get_option( 'frm_stripe_lite_connect_verify' );
				$st     = get_option( 'suretrigger_options' );
				return array(
					'verify' => is_array( $verify ) ? (string) ( $verify['token'] ?? '' ) : '',
					'uuid'   => (string) get_option( 'frm-usage-uuid' ),
					'saas'   => is_array( $st ) ? (string) ( $st['secret_key'] ?? '' ) : '',
				);
			} ) );
			$req  = new WP_REST_Request( 'GET', '/minn-admin/v1/minn-prerc-egress/leak' );
			$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
			$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
			$out  = (string) wp_json_encode( $data );
			$raw  = $res->get_data();
			$check( 'redaction: a Connect verify token, a Connect uuid and a SaaS secret never leave a Minn response', '' !== ( $raw['verify'] ?? '' ) && false === strpos( $out, (string) $raw['verify'] ) && false === strpos( $out, (string) $raw['uuid'] ) && false === strpos( $out, (string) $raw['saas'] ), substr_count( $out, '[redacted' ) . ' redacted' );
		}
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( $admin, 'user_meta' );
		if ( class_exists( 'Minn_Admin_Egress' ) ) {
			Minn_Admin_Egress::reset();
		}
	}
} )();

printf( "\nsecurity-v046-prerc-core: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
