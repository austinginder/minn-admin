<?php
/**
 * Regressions for the v0.44.0 shipped-zip registry scan (findings that open
 * v0.45.0).
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what got stored, with a
 * control where a fix could over-block. Sections SKIP when their plugin is
 * inactive.
 *
 * Run: wp eval-file tests/security-v045-registry.test.php --user=admin --path=<site>
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
	printf( "\nsecurity-v045-registry: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( $admin[0]->ID );

/*
 * 14-1: the Pods panel resends every field it shows. Pods parses a number or
 * currency value in the field's own format (thousands separator removed, then
 * the decimal mark made a dot), so the raw '19.99' the panel read came back
 * as 1999.00 wherever '.' is the thousands separator: a de_DE site's
 * localized default, or an explicit 9.999,99 format as here.
 */
( function () use ( $check, $skip, $call ) {
	if ( ! function_exists( 'pods_api' ) || ! function_exists( 'minn_admin_pods_read_values' ) ) {
		$skip( '14-1 Pods inactive' );
		return;
	}
	$api = pods_api();
	$pod = $api->load_pod( array( 'name' => 'post' ) );
	if ( ! $pod ) {
		$skip( '14-1 no Pods pod extends post' );
		return;
	}
	$made = array();
	foreach ( array(
		'minn_v045_price' => array( 'type' => 'currency', 'currency_format' => '9.999,99', 'currency_decimals' => 2 ),
		'minn_v045_ratio' => array( 'type' => 'number', 'number_format' => '9.999,99', 'number_decimals' => 2 ),
		'minn_v045_plain' => array( 'type' => 'number', 'number_format' => '9,999.99', 'number_decimals' => 2 ),
	) as $name => $opts ) {
		$id = $api->save_field( array_merge( array( 'pod' => 'post', 'name' => $name, 'label' => $name ), $opts ) );
		if ( $id ) {
			$made[] = $name;
		}
	}
	$post_id = wp_insert_post( array( 'post_title' => 'v045 Pods probe', 'post_status' => 'draft' ) );
	try {
		$check( '14-1 fixture fields and post created', 3 === count( $made ) && $post_id, implode( ',', $made ) );
		// Pods' own form sends the value as the field formats it.
		pods( 'post', $post_id )->save( array( 'minn_v045_price' => '19,99', 'minn_v045_ratio' => '1.234,50', 'minn_pods_subtitle' => 'alt' ) );
		$check( '14-1 Pods stores the canonical dot-decimal', '19.99' === get_post_meta( $post_id, 'minn_v045_price', true ) && '1234.50' === get_post_meta( $post_id, 'minn_v045_ratio', true ),
			get_post_meta( $post_id, 'minn_v045_price', true ) . ' / ' . get_post_meta( $post_id, 'minn_v045_ratio', true ) );

		// The panel save: every value as the read served it, one text edited.
		$served = minn_admin_pods_read_values( $post_id );
		$served['minn_pods_subtitle'] = 'neu';
		list( $status ) = $call( 'POST', '/wp/v2/posts/' . $post_id, array( 'minn_pods' => $served ) );
		wp_cache_flush();
		$check( '14-1 a text edit saves', 200 === $status && 'neu' === get_post_meta( $post_id, 'minn_pods_subtitle', true ), $status . ' ' . get_post_meta( $post_id, 'minn_pods_subtitle', true ) );
		$check( '14-1 the untouched currency field keeps 19.99', '19.99' === get_post_meta( $post_id, 'minn_v045_price', true ), get_post_meta( $post_id, 'minn_v045_price', true ) );
		$check( '14-1 the untouched number field keeps 1234.50', '1234.50' === get_post_meta( $post_id, 'minn_v045_ratio', true ), get_post_meta( $post_id, 'minn_v045_ratio', true ) );

		// An edited value arrives as the number input holds it (dot decimal).
		$served = minn_admin_pods_read_values( $post_id );
		$served['minn_v045_price'] = '25.50';
		$served['minn_v045_ratio'] = '7.25';
		$served['minn_v045_plain'] = '3.75';
		list( $status ) = $call( 'POST', '/wp/v2/posts/' . $post_id, array( 'minn_pods' => $served ) );
		wp_cache_flush();
		$check( '14-1 an edited currency value is stored as typed', '25.50' === get_post_meta( $post_id, 'minn_v045_price', true ), get_post_meta( $post_id, 'minn_v045_price', true ) );
		$check( '14-1 an edited number value is stored as typed', '7.25' === get_post_meta( $post_id, 'minn_v045_ratio', true ), get_post_meta( $post_id, 'minn_v045_ratio', true ) );
		$check( '14-1 control: a dot-decimal format field is stored as typed', '3.75' === get_post_meta( $post_id, 'minn_v045_plain', true ), get_post_meta( $post_id, 'minn_v045_plain', true ) );
	} finally {
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $made as $name ) {
			$api->delete_field( array( 'pod' => 'post', 'name' => $name ) );
		}
	}
} )();

/*
 * 04-4, the class: a surface's edit modal sends its `preserve` keys as they
 * were when the modal opened. A key one of the same list's actions sets (an
 * on/off switch) would be put back by any later save from a modal opened
 * before the action ran, so no surface may preserve one.
 */
( function () use ( $check, $call ) {
	list( $status, $d ) = $call( 'GET', '/minn-admin/v1/surfaces' );
	$list  = isset( $d['surfaces'] ) ? $d['surfaces'] : (array) $d;
	$colls = array();
	foreach ( $list as $s ) {
		$s = json_decode( wp_json_encode( $s ), true );
		foreach ( array( $s['collection'] ?? null, $s['manage'] ?? null ) as $c ) {
			if ( $c ) {
				$colls[ $s['id'] ][] = $c;
			}
		}
		foreach ( (array) ( $s['views'] ?? array() ) as $v ) {
			if ( ! empty( $v['collection'] ) ) {
				$colls[ $s['id'] ][] = $v['collection'];
			}
		}
	}
	$bad = array();
	foreach ( $colls as $sid => $list_of ) {
		foreach ( $list_of as $c ) {
			$preserve = (array) ( $c['detail']['edit']['preserve'] ?? array() );
			if ( ! $preserve ) {
				continue;
			}
			$set = array();
			foreach ( array_merge( (array) ( $c['actions'] ?? array() ), (array) ( $c['bulk'] ?? array() ), (array) ( $c['status']['actions'] ?? array() ) ) as $a ) {
				foreach ( array_keys( (array) ( $a['body'] ?? array() ) ) as $k ) {
					$set[ $k ] = true;
				}
			}
			foreach ( $preserve as $k ) {
				if ( isset( $set[ $k ] ) ) {
					$bad[] = "{$sid} preserves {$k}";
				}
			}
		}
	}
	$check( '04-4 class: no surface preserves a key one of its own actions sets', 200 === $status && ! $bad, implode( '; ', $bad ) );
} )();

/*
 * 11-1: the Settings Content section resent the permalinks with every save,
 * and the route rebuilt the rewrite rules (and, where WordPress can't tell
 * the server rewrites URLs, prefixed /index.php to a custom structure). The
 * same values now come back untouched; a real change still saves.
 */
( function () use ( $check, $call ) {
	$was = array(
		'structure'     => (string) get_option( 'permalink_structure' ),
		'category_base' => (string) get_option( 'category_base' ),
		'tag_base'      => (string) get_option( 'tag_base' ),
	);
	if ( '' === $was['structure'] ) {
		echo "SKIP  11-1 plain permalinks on this site\n";
		return;
	}
	// WP-CLI cannot tell what the server rewrites, so got_url_rewrite() is
	// false here and any real change would prefix /index.php. Answer as the
	// lab's web server does, and put the options back directly whatever
	// happens.
	add_filter( 'got_url_rewrite', '__return_true' );
	try {
		update_option( 'rewrite_rules', array( 'minn-v045-probe' => 'index.php' ) );
		list( $status ) = $call( 'POST', '/minn-admin/v1/permalinks', $was );
		$rules = get_option( 'rewrite_rules' );
		$check( '11-1 saving the permalinks unchanged leaves the rewrite rules alone', 200 === $status && is_array( $rules ) && isset( $rules['minn-v045-probe'] ), (string) $status );
		list( $status ) = $call( 'POST', '/minn-admin/v1/permalinks', array_merge( $was, array( 'tag_base' => 'minn-v045-tags' ) ) );
		$rules = get_option( 'rewrite_rules' );
		$check( '11-1 control: a changed tag base saves and rebuilds the rules', 200 === $status && 'minn-v045-tags' === get_option( 'tag_base' ) && ! isset( $rules['minn-v045-probe'] ), (string) $status . ' ' . get_option( 'tag_base' ) );
		$check( '11-1 control: the structure is left as it was', get_option( 'permalink_structure' ) === $was['structure'], (string) get_option( 'permalink_structure' ) );
	} finally {
		update_option( 'permalink_structure', $was['structure'] );
		update_option( 'category_base', $was['category_base'] );
		update_option( 'tag_base', $was['tag_base'] );
		remove_filter( 'got_url_rewrite', '__return_true' );
		flush_rewrite_rules();
	}
} )();

/*
 * 06-1: theme delete and activate took any directory under the themes root
 * (wp_get_theme()->exists() is true for one), so '..' named wp-content and a
 * theme's own subfolder passed too. Probed only on a throwaway directory, so
 * a regression can delete nothing that matters.
 */
( function () use ( $check, $call ) {
	$root  = get_theme_root();
	$probe = $root . '/minn-v045-probe';
	wp_mkdir_p( $probe . '/sub' );
	try {
		$check( '06-1 a theme subfolder is not a theme', null === Minn_Admin_REST::get_valid_theme( 'minn-v045-probe/sub' ) );
		$check( "06-1 '..' is not a theme", null === Minn_Admin_REST::get_valid_theme( 'minn-v045-probe/sub/..' ) && null === Minn_Admin_REST::get_valid_theme( '..' ) );
		$check( '06-1 control: the active theme is a theme', null !== Minn_Admin_REST::get_valid_theme( get_stylesheet() ) );
		foreach ( array( 'minn-v045-probe/sub', 'minn-v045-probe/sub/..' ) as $name ) {
			list( $status ) = $call( 'POST', '/minn-admin/v1/themes/delete', array( 'stylesheet' => $name ) );
			$check( "06-1 delete refuses {$name}", 404 === $status && is_dir( $probe . '/sub' ), (string) $status );
		}
	} finally {
		@rmdir( $probe . '/sub' ); // phpcs:ignore
		@rmdir( $probe ); // phpcs:ignore
	}
} )();

/*
 * 08-2: the duplicate route took any post type the caller could edit_post,
 * internal ones included: an Editor's copy of a privacy request handed back
 * the requester's email. 08-5: /builders/new checked the type's edit cap
 * where core's create paths check create_posts.
 */
( function () use ( $check, $call, $drop_user ) {
	$admin_id = get_current_user_id();
	$editor   = wp_insert_user( array( 'user_login' => 'minn-v045-ed-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'minn-v045-ed-' . wp_rand() . '@example.com' ) );
	$req      = function_exists( 'wp_create_user_request' ) ? wp_create_user_request( 'minn-v045-requester@example.com', 'export_personal_data' ) : 0;
	$post     = wp_insert_post( array( 'post_title' => 'v045 duplicate control', 'post_status' => 'draft', 'post_author' => $admin_id ) );
	$made     = array();
	try {
		wp_set_current_user( $editor );
		if ( $req && ! is_wp_error( $req ) ) {
			$before = (int) wp_count_posts( 'user_request' )->{'request-pending'};
			list( $status, $d ) = $call( 'POST', "/minn-admin/v1/posts/{$req}/duplicate" );
			$after = (int) wp_count_posts( 'user_request' )->{'request-pending'};
			if ( is_array( $d ) && ! empty( $d['id'] ) ) {
				$made[] = (int) $d['id'];
			}
			$check( '08-2 an Editor cannot duplicate a privacy request', 404 === $status && false === strpos( wp_json_encode( $d ), 'minn-v045-requester' ), (string) $status );
		}
		list( $status, $d ) = $call( 'POST', "/minn-admin/v1/posts/{$post}/duplicate" );
		if ( is_array( $d ) && ! empty( $d['id'] ) ) {
			$made[] = (int) $d['id'];
		}
		$check( '08-2 control: an Editor still duplicates a post', $status < 300, (string) $status );

		$routes = rest_get_server()->get_routes();
		if ( isset( $routes['/minn-admin/v1/builders/new'] ) ) {
			$type = get_post_type_object( 'page' );
			$was  = $type->cap->create_posts;
			$type->cap->create_posts = 'minn_v045_create_pages';
			try {
				list( $status, $d ) = $call( 'POST', '/minn-admin/v1/builders/new', array( 'builder' => array_key_first( minn_admin_page_builders() ), 'type' => 'pages', 'title' => 'v045 probe' ) );
				if ( is_array( $d ) && ! empty( $d['id'] ) ) {
					$made[] = (int) $d['id'];
				}
				$check( "08-5 /builders/new asks the type's create cap", 403 === $status, (string) $status );
			} finally {
				$type->cap->create_posts = $was;
			}
		} else {
			echo "SKIP  08-5 no page builder active\n";
		}
	} finally {
		wp_set_current_user( $admin_id );
		foreach ( array_merge( $made, array( $post, $req ) ) as $id ) {
			if ( $id && ! is_wp_error( $id ) ) {
				wp_delete_post( $id, true );
			}
		}
		$drop_user( $editor );
	}
} )();

/*
 * 09-1: a session's expiry is its sign-in time plus two or fourteen days, so
 * it gave back what the route withholds from a role that may only edit the
 * user (a Shop Manager over a customer). 09-2: show_admin_bar_front was in
 * every context, so the public users endpoint listed each author's choice.
 */
( function () use ( $check, $call, $drop_user ) {
	$admin_id = get_current_user_id();
	if ( ! get_role( 'shop_manager' ) ) {
		echo "SKIP  09-1 no shop_manager role\n";
	} else {
		$sm   = wp_insert_user( array( 'user_login' => 'minn-v045-sm-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'minn-v045-sm-' . wp_rand() . '@example.com' ) );
		$cust = wp_insert_user( array( 'user_login' => 'minn-v045-cu-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'customer', 'user_email' => 'minn-v045-cu-' . wp_rand() . '@example.com' ) );
		WP_Session_Tokens::get_instance( $cust )->create( time() + 2 * DAY_IN_SECONDS );
		try {
			wp_set_current_user( $sm );
			list( $status, $d ) = $call( 'GET', "/minn-admin/v1/users/{$cust}/sessions" );
			$s = is_array( $d ) && ! empty( $d['sessions'] ) ? $d['sessions'][0] : null;
			$check( '09-1 a Shop Manager gets no session expiry for a customer', 200 === $status && $s && 0 === (int) $s['expiration'] && 0 === (int) $s['login'], wp_json_encode( $s ) );
			wp_set_current_user( $admin_id );
			list( , $d ) = $call( 'GET', "/minn-admin/v1/users/{$cust}/sessions" );
			$s = is_array( $d ) && ! empty( $d['sessions'] ) ? $d['sessions'][0] : null;
			$check( '09-1 control: an administrator still gets it', $s && (int) $s['expiration'] > time(), wp_json_encode( $s ) );
		} finally {
			wp_set_current_user( $admin_id );
			$drop_user( $sm );
			$drop_user( $cust );
		}
	}
	wp_set_current_user( 0 );
	list( $status, $d ) = $call( 'GET', '/wp/v2/users/' . $admin_id );
	wp_set_current_user( $admin_id );
	$check( '09-2 the public users endpoint carries no toolbar preference', ! isset( $d['meta']['show_admin_bar_front'] ), $status . ' ' . wp_json_encode( $d['meta'] ?? null ) );
	list( , $d ) = $call( 'GET', '/wp/v2/users/' . $admin_id, null, array( 'context' => 'edit' ) );
	$check( '09-2 control: the edit context still carries it', isset( $d['meta']['show_admin_bar_front'] ), wp_json_encode( $d['meta'] ?? null ) );
} )();

/*
 * 11-3: the System log viewer listed WooCommerce's log channels to anyone
 * the page admits, whether or not WooCommerce would show them its logs.
 */
( function () use ( $check ) {
	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'Minn_Admin_Logs' ) ) {
		echo "SKIP  11-3 WooCommerce inactive\n";
		return;
	}
	$wc = function () {
		return array_values( array_filter( array_keys( Minn_Admin_Logs::sources() ), function ( $k ) {
			return 0 === strpos( $k, 'wc:' );
		} ) );
	};
	$with = $wc();
	if ( ! $with ) {
		echo "SKIP  11-3 no WooCommerce log channels on this site\n";
		return;
	}
	$deny = function ( $caps ) {
		$caps['manage_woocommerce'] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $deny );
	$without = $wc();
	remove_filter( 'user_has_cap', $deny );
	$check( '11-3 WooCommerce logs need manage_woocommerce', ! $without, implode( ',', $without ) );
	$check( '11-3 control: they are listed for a store manager', (bool) $with, implode( ',', array_slice( $with, 0, 3 ) ) );
} )();

/*
 * 13-2: Elementor's template Duplicate took its permission from the source's
 * post type but wrote the copy into the type its template-type meta named,
 * so a Contributor's library template typed as floating buttons came out as
 * an administrators-only Floating Buttons post.
 */
( function () use ( $check, $call, $drop_user ) {
	if ( ! function_exists( 'minn_admin_elementor_floating_cpt' ) || ! minn_admin_elementor_floating_cpt() || ! class_exists( '\\Elementor\\TemplateLibrary\\Source_Local' ) ) {
		echo "SKIP  13-2 Elementor's floating buttons are not available\n";
		return;
	}
	$admin_id = get_current_user_id();
	$contrib  = wp_insert_user( array( 'user_login' => 'minn-v045-co-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor', 'user_email' => 'minn-v045-co-' . wp_rand() . '@example.com' ) );
	$lib      = \Elementor\TemplateLibrary\Source_Local::CPT;
	$src      = wp_insert_post( array( 'post_type' => $lib, 'post_title' => 'v045 floating probe', 'post_status' => 'draft', 'post_author' => $contrib ) );
	update_post_meta( $src, '_elementor_template_type', 'floating-buttons' );
	update_post_meta( $src, '_elementor_edit_mode', 'builder' );
	$made = 0;
	try {
		wp_set_current_user( $contrib );
		list( $status, $d ) = $call( 'POST', "/minn-admin/v1/elementor/templates/{$src}/duplicate" );
		$made = is_array( $d ) && ! empty( $d['id'] ) ? (int) $d['id'] : 0;
		$type = $made ? get_post_type( $made ) : '';
		$check( "13-2 a Contributor's copy stays out of Floating Buttons", minn_admin_elementor_floating_cpt() !== $type, "{$status} {$type}" );
	} finally {
		wp_set_current_user( $admin_id );
		foreach ( array( $made, $src ) as $id ) {
			if ( $id ) {
				wp_delete_post( $id, true );
			}
		}
		$drop_user( $contrib );
	}
} )();

/*
 * 04-6 (08-4): editing a post type or taxonomy in Minn forced Show UI on
 * (and JetEngine's Show in menu), undoing an owner's choice the dialog never
 * shows. Checked on CPT UI, whose store is one option.
 */
( function () use ( $check, $call ) {
	if ( ! function_exists( 'cptui_get_post_type_data' ) && ! defined( 'CPTUI_VERSION' ) ) {
		echo "SKIP  04-6 CPT UI inactive\n";
		return;
	}
	$slug  = 'minnv045t';
	$types = (array) get_option( 'cptui_post_types', array() );
	$was   = $types;
	$types[ $slug ] = array( 'name' => $slug, 'label' => 'V045s', 'singular_label' => 'V045', 'description' => '', 'public' => 'false', 'show_ui' => 'false', 'show_in_rest' => 'true', 'has_archive' => 'false', 'hierarchical' => 'false', 'supports' => array( 'title' ), 'taxonomies' => array() );
	update_option( 'cptui_post_types', $types );
	register_post_type( $slug, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => true, 'label' => 'V045s', 'supports' => array( 'title' ) ) );
	try {
		list( $status, $d ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'singular' => 'V045 renamed', 'plural' => 'V045s', 'public' => false, 'show_in_rest' => true, 'supports' => array( 'title' ), 'taxonomies' => array() ) );
		$now = (array) get_option( 'cptui_post_types', array() );
		$check( '04-6 a CPT UI type edited in Minn keeps Show UI off', $status < 300 && 'false' === ( $now[ $slug ]['show_ui'] ?? '' ) && 'V045 renamed' === ( $now[ $slug ]['singular_label'] ?? '' ), $status . ' ' . wp_json_encode( $now[ $slug ] ?? null ) );
	} finally {
		update_option( 'cptui_post_types', $was );
		unregister_post_type( $slug );
	}
} )();

/*
 * 04-8: the PowerPress panel compared each title after sanitize_text_field
 * with the stored one, so a stored title the cleaner alters ("Episode <3")
 * never matched and was rewritten on every save of the panel. Driven through
 * the adapter's writer, which does not need PowerPress active.
 */
( function () use ( $check ) {
	if ( ! function_exists( 'minn_admin_powerpress_write_values' ) ) {
		echo "SKIP  04-8 no PowerPress adapter\n";
		return;
	}
	$id    = wp_insert_post( array( 'post_title' => 'v045 PowerPress probe', 'post_status' => 'draft' ) );
	$extra = serialize( array( 'episode_title' => 'Episode <3 & more', 'subtitle' => '100%Accurate notes', 'episode_no' => '7' ) );
	update_post_meta( $id, 'enclosure', wp_slash( "https://example.com/ep.mp3\n1234\naudio/mpeg\n" . $extra ) );
	try {
		$vals = minn_admin_powerpress_read_values( $id );
		$vals['season'] = '2';
		minn_admin_powerpress_write_values( $id, $vals );
		wp_cache_delete( $id, 'post_meta' );
		$now = minn_admin_powerpress_read_values( $id );
		$check( '04-8 an untouched episode title is left as stored', 'Episode <3 & more' === $now['episode_title'] && '100%Accurate notes' === $now['subtitle'], wp_json_encode( $now ) );
		$check( '04-8 control: the edited field saves', '2' === $now['season'], (string) $now['season'] );
	} finally {
		wp_delete_post( $id, true );
	}
} )();

/*
 * 12-2: the ACF builder and the Fields view re-parse a field's choices from
 * the text Minn wrote for them. The parser trimmed each line before looking
 * for ' : ', so '' => 'None' came back as ': None' and 'x' => '' as 'x :'
 * on every save. Encoded with Minn's own writer, decoded with its parser.
 */
( function () use ( $check ) {
	if ( ! function_exists( 'minn_admin_acf_schema_field_item' ) || ! function_exists( 'minn_admin_acf_schema_parse_choices' ) ) {
		echo "SKIP  12-2 no ACF schema adapter\n";
		return;
	}
	$choices = array( '' => 'None', 'a' => 'Apple', 'x' => '', '16:9' => 'Wide', 'b' => 'b' );
	$item    = minn_admin_acf_schema_field_item( array( 'key' => 'field_minn_v045', 'name' => 'v045', 'label' => 'V045', 'type' => 'select', 'choices' => $choices ), array( 'key' => 'group_minn_v045', 'title' => 'V045' ) );
	$text    = (string) ( $item['choices'] ?? '' );
	$back    = minn_admin_acf_schema_parse_choices( $text );
	$check( '12-2 choices survive Minn\'s own text round trip', $back === $choices, wp_json_encode( $back ) . ' from ' . wp_json_encode( $text ) );
} )();

/*
 * 04-5 (12-1): the ACF field-group builder saved the whole group from the
 * page's copy, deleting a field added after the page loaded (another admin,
 * an import, an acf-json sync) and switching a disabled group back on. A
 * save now carries the stamp of what the page loaded and is refused (409)
 * when the group moved since.
 */
( function () use ( $check, $call ) {
	if ( ! function_exists( 'acf_update_field_group' ) || ! function_exists( 'acf_update_field' ) ) {
		echo "SKIP  04-5 ACF inactive\n";
		return;
	}
	$group = acf_update_field_group( array( 'key' => 'group_minn_v045_stale', 'title' => 'v045 stale probe', 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ) ) );
	acf_update_field( array( 'key' => 'field_minn_v045_a', 'label' => 'A', 'name' => 'minn_v045_a', 'type' => 'text', 'parent' => $group['ID'] ) );
	try {
		list( , $page ) = $call( 'GET', '/minn-admin/v1/acf/schema/groups/group_minn_v045_stale/full' );
		// Someone adds a field in ACF while the page is open.
		acf_update_field( array( 'key' => 'field_minn_v045_b', 'label' => 'B', 'name' => 'minn_v045_b', 'type' => 'text', 'parent' => $group['ID'] ) );
		$rows = array_map( function ( $f ) {
			return array( 'key' => $f['key'], 'label' => $f['label'] . ' renamed' );
		}, (array) $page['fields'] );
		list( $status ) = $call( 'POST', '/minn-admin/v1/acf/schema/groups/group_minn_v045_stale/full', array( 'stamp' => $page['stamp'], 'title' => 'v045 stale probe', 'fields' => $rows ) );
		$still = (bool) acf_get_field( 'field_minn_v045_b' );
		$check( '04-5 a save from a page opened before a field was added is refused (409)', 409 === $status && $still, $status . ' field B ' . ( $still ? 'kept' : 'DELETED' ) );
		list( , $fresh ) = $call( 'GET', '/minn-admin/v1/acf/schema/groups/group_minn_v045_stale/full' );
		$rows = array_map( function ( $f ) {
			return array( 'key' => $f['key'], 'label' => $f['label'] );
		}, (array) $fresh['fields'] );
		list( $status ) = $call( 'POST', '/minn-admin/v1/acf/schema/groups/group_minn_v045_stale/full', array( 'stamp' => $fresh['stamp'], 'title' => 'v045 stale probe', 'fields' => $rows ) );
		$check( '04-5 control: a save from the latest copy goes through', 200 === $status && (bool) acf_get_field( 'field_minn_v045_b' ), (string) $status );
		list( $status ) = $call( 'POST', '/minn-admin/v1/acf/schema/groups/group_minn_v045_stale/full', array( 'title' => 'v045 stale probe', 'fields' => $rows ) );
		$check( '04-5 a save with no stamp is refused', 409 === $status, (string) $status );
	} finally {
		$g = acf_get_field_group( 'group_minn_v045_stale' );
		if ( $g ) {
			acf_delete_field_group( $g['ID'] );
		}
	}
} )();

/*
 * 15-2: a membership plan save deleted every rule the page did not send
 * back, a rule added after the page opened included. The page now names
 * the rules it loaded, and only those can be removed. Run inside a rolled
 * back transaction on the lab's first plan.
 */
( function () use ( $check, $call ) {
	if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
		echo "SKIP  15-2 WooCommerce Memberships inactive\n";
		return;
	}
	$plans = wc_memberships_get_membership_plans();
	if ( ! $plans ) {
		echo "SKIP  15-2 no membership plan\n";
		return;
	}
	global $wpdb;
	$id = (int) reset( $plans )->get_id();
	$wpdb->query( 'START TRANSACTION' );
	try {
		list( , $d ) = $call( 'GET', "/minn-admin/v1/wcm/plans/{$id}" );
		$targets = (array) ( $d['vocab']['targets']['content_restriction'] ?? array() );
		if ( count( $targets ) < 2 ) {
			echo "SKIP  15-2 fewer than two content targets\n";
			return;
		}
		$row  = function ( $t, $rid = '' ) {
			$r = array( 'target' => $t, 'object_ids' => array(), 'access_schedule' => array( 'type' => 'immediate' ) );
			if ( $rid ) {
				$r['id'] = $rid;
			}
			return $r;
		};
		$ids  = function () use ( $call, $id ) {
			wp_cache_flush();
			list( , $d ) = $call( 'GET', "/minn-admin/v1/wcm/plans/{$id}" );
			return array_map( function ( $r ) {
				return (string) $r['id'];
			}, (array) ( $d['rules']['content_restriction'] ?? array() ) );
		};
		$call( 'POST', "/minn-admin/v1/wcm/plans/{$id}", array( 'rules' => array( 'content_restriction' => array( $row( $targets[0][0] ) ) ), 'known_rules' => array() ) );
		$first = $ids();
		// Another tab adds a second rule.
		$call( 'POST', "/minn-admin/v1/wcm/plans/{$id}", array( 'rules' => array( 'content_restriction' => array( $row( $targets[0][0], $first[0] ?? '' ), $row( $targets[1][0] ) ) ), 'known_rules' => $first ) );
		$both = $ids();
		// The page opened before that saves what it knows.
		$call( 'POST', "/minn-admin/v1/wcm/plans/{$id}", array( 'rules' => array( 'content_restriction' => array( $row( $targets[0][0], $first[0] ?? '' ) ) ), 'known_rules' => $first ) );
		$after = $ids();
		$check( '15-2 a rule added after the page opened survives its save', 1 === count( $first ) && 2 === count( $both ) && 2 === count( $after ), wp_json_encode( array( $first, $both, $after ) ) );
		$call( 'POST', "/minn-admin/v1/wcm/plans/{$id}", array( 'rules' => array( 'content_restriction' => array() ), 'known_rules' => $after ) );
		$check( '15-2 control: rules the page knew and dropped are removed', ! $ids(), wp_json_encode( $ids() ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * 11-2: the System page's wp-config switches rewrote any define of their
 * constant with true or false, so a WP_DEBUG_LOG set to a private file path
 * became true (the public wp-content/debug.log), and an expression define
 * was replaced by a fixed value. Only a literal true/false is a switch now.
 */
( function () use ( $check ) {
	$cases = array(
		array( "define( 'WP_DEBUG_LOG', true );", 'bool' ),
		array( "define('WP_DEBUG_LOG',FALSE);", 'bool' ),
		array( "define( 'WP_DEBUG_LOG', '/srv/private/wp-debug.log' );", 'custom' ),
		array( "define( 'WP_DEBUG', WP_ENVIRONMENT_TYPE !== 'production' );", 'custom' ),
		array( "// nothing here", 'absent' ),
	);
	$bad = array();
	foreach ( $cases as $c ) {
		$name = false !== strpos( $c[0], 'WP_DEBUG_LOG' ) || 'absent' === $c[1] ? 'WP_DEBUG_LOG' : 'WP_DEBUG';
		$kind = Minn_Admin_REST::config_define_kind( $name, $c[0] );
		if ( $kind !== $c[1] ) {
			$bad[] = "{$c[0]} => {$kind}";
		}
	}
	$check( '11-2 only a literal true/false define is a switch', ! $bad, implode( '; ', $bad ) );
} )();

/*
 * 15-1: email previews made from a form's latest entry resolved tags that
 * read the submitter's own user meta (Gravity Forms {created_by:<key>},
 * WPForms {user_meta key=…}), so whoever may preview could read any meta
 * of that account: a 2FA seed, a licence key. Profile fields the entry
 * screen already shows still resolve.
 */
( function () use ( $check, $call, $drop_user ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		echo "SKIP  15-1 Gravity Forms inactive\n";
		return;
	}
	$form = null;
	foreach ( GFAPI::get_forms() as $f ) {
		if ( rgar( $f, 'notifications' ) ) {
			$form = $f;
			break;
		}
	}
	if ( ! $form ) {
		echo "SKIP  15-1 no form with a notification\n";
		return;
	}
	$nid    = array_keys( $form['notifications'] )[0];
	$uid    = wp_insert_user( array( 'user_login' => 'minn-v045-sub-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'minn-v045-sub-' . wp_rand() . '@example.com', 'display_name' => 'V045 Submitter' ) );
	$secret = 'seed-' . wp_generate_password( 12, false );
	update_user_meta( $uid, 'minn_v045_secret', $secret );
	$entry  = GFAPI::add_entry( array( 'form_id' => $form['id'], 'created_by' => $uid, 'date_created' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ) );
	try {
		list( $status, $d ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$form['id']}:{$nid}/preview", array( 'subject' => 'S {created_by:minn_v045_secret}', 'message' => 'M {created_by:minn_v045_secret} by {created_by:display_name}' ) );
		$out = wp_json_encode( $d );
		$check( '15-1 a Gravity Forms preview does not resolve a submitter\'s meta', 200 === $status && false === strpos( $out, $secret ), $status . ' ' . substr( $out, 0, 160 ) );
		$check( '15-1 control: a profile field the entry screen shows still resolves', false !== strpos( $out, 'V045 Submitter' ), substr( $out, 0, 160 ) );
	} finally {
		if ( $entry && ! is_wp_error( $entry ) ) {
			GFAPI::delete_entry( $entry );
		}
		$drop_user( $uid );
	}
} )();

/*
 * 13-1: Minn's Fluent Forms entry views printed a password answer Fluent's
 * own screens mask (an entry whose password Fluent has not replaced yet).
 * Run on a throwaway form in a rolled-back transaction.
 */
( function () use ( $check, $call ) {
	if ( ! class_exists( '\\FluentForm\\App\\Helpers\\Helper' ) ) {
		echo "SKIP  13-1 Fluent Forms inactive\n";
		return;
	}
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		$fields = array( 'fields' => array(
			array( 'element' => 'input_text', 'attributes' => array( 'name' => 'nick' ), 'settings' => array( 'label' => 'Nick' ) ),
			array( 'element' => 'container', 'columns' => array( array( 'fields' => array(
				array( 'element' => 'input_password', 'attributes' => array( 'name' => 'secret_pw' ), 'settings' => array( 'label' => 'Password' ) ),
			) ) ) ),
		) );
		$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'title' => 'v045 password probe', 'status' => 'published', 'form_fields' => wp_json_encode( $fields ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$form_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'fluentform_submissions', array( 'form_id' => $form_id, 'response' => wp_json_encode( array( 'nick' => 'Dana', 'secret_pw' => 'hunter2-v045' ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$entry = (int) $wpdb->insert_id;
		list( $status, $d ) = $call( 'GET', "/minn-admin/v1/fluent-forms/entries/{$entry}" );
		$out = wp_json_encode( $d );
		$check( '13-1 a Fluent password answer is masked as Fluent masks it', 200 === $status && false === strpos( $out, 'hunter2-v045' ) && false !== strpos( $out, '******' ), $status . ' ' . substr( $out, 0, 200 ) );
		$check( '13-1 control: other answers still show', false !== strpos( $out, 'Dana' ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

// --- 08-3 A short address no commenter's contains yet still trashed later comments ---
// Core matches each disallowed_keys line as a substring of every later comment.
// The block's breadth check consulted only commenters who already exist, so a
// spammer's short address (x@<domain>) was accepted and every later comment
// from a longer address containing it went to the trash. The block is gone.
( function () use ( $check, $call ) {
	$post = (int) get_option( 'page_on_front' ) ?: (int) get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) )[0];
	if ( ! $post ) {
		echo "SKIP  08-3 no published post to comment on\n";
		return;
	}
	$was   = get_option( 'disallowed_keys', '' );
	$tag   = 'mv45cb' . wp_rand( 1000, 9999 );
	$short = (int) wp_insert_comment( array( 'comment_post_ID' => $post, 'comment_author' => 'Minn test', 'comment_author_email' => "x@{$tag}.example", 'comment_author_IP' => '203.0.113.9', 'comment_content' => 'Minn 08-3', 'comment_approved' => 0 ) );
	try {
		list( $status ) = $call( 'POST', "/minn-admin/v1/comments/{$short}/block" );
		$lines = array_map( 'trim', explode( "\n", (string) get_option( 'disallowed_keys', '' ) ) );
		$check( '08-3 a short address is not added to the disallowed list', 404 === $status && ! in_array( "x@{$tag}.example", $lines, true ), (string) $status );
		$check( '08-3 so a later comment from a longer address is not trashed', ! wp_check_comment_disallowed_list( 'Jane', "jax@{$tag}.example", '', 'Hello', '198.51.100.4', 'Mozilla/5.0' ) );
	} finally {
		update_option( 'disallowed_keys', $was );
		wp_delete_comment( $short, true );
	}
} )();

// --- 10-1 An update offer older than what is installed is neither shown nor applied ---
// The update transients keep an offer until their next refresh, so after a
// theme or plugin is replaced (or a plugin deleted) outside the upgrader they
// can offer an older release, and neither upgrader compares versions. Every
// HTTP request is refused here, so no package downloads and no update check
// can turn a forged offer into a real one; the transients are put back after.
( function () use ( $check, $call ) {
	$theme  = 'twentytwentyfour';
	$plugin = 'eps-301-redirects/eps-301-redirects.php';
	$gone   = 'minn-gone-test/minn-gone-test.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins = get_plugins();
	if ( ! wp_get_theme( $theme )->exists() || get_stylesheet() === $theme || ! isset( $plugins[ $plugin ] ) || is_plugin_active( $plugin ) || isset( $plugins[ $gone ] ) ) {
		echo "SKIP  10-1 needs {$theme} installed and inactive and {$plugin} installed and inactive\n";
		return;
	}
	$themes_was  = get_site_transient( 'update_themes' );
	$plugins_was = get_site_transient( 'update_plugins' );
	$offline     = function () {
		return new WP_Error( 'minn_test_offline', 'offline' );
	};
	add_filter( 'pre_http_request', $offline, 1 );
	$offer_theme = function ( $version ) use ( $theme, $themes_was ) {
		$t = is_object( $themes_was ) ? clone $themes_was : new stdClass();
		$t->last_checked = time();
		$t->checked      = array( $theme => wp_get_theme( $theme )->get( 'Version' ) ) + (array) ( $t->checked ?? array() );
		$t->response     = array( $theme => array( 'theme' => $theme, 'new_version' => $version, 'url' => 'https://wordpress.org/themes/' . $theme . '/', 'package' => 'https://downloads.wordpress.org/theme/' . $theme . '.' . $version . '.zip' ) );
		set_site_transient( 'update_themes', $t );
	};
	$offer_plugins = function ( array $offers ) use ( $plugins_was ) {
		$t = is_object( $plugins_was ) ? clone $plugins_was : new stdClass();
		$t->last_checked = time();
		$t->response     = array();
		foreach ( $offers as $file => $version ) {
			$t->response[ $file ] = (object) array( 'slug' => dirname( $file ), 'plugin' => $file, 'new_version' => $version, 'package' => 'https://downloads.wordpress.org/plugin/' . dirname( $file ) . '.' . $version . '.zip' );
		}
		set_site_transient( 'update_plugins', $t );
	};
	$listed = function () use ( $call, $theme ) {
		list( , $d ) = $call( 'GET', '/minn-admin/v1/themes' );
		foreach ( (array) ( $d['themes'] ?? array() ) as $row ) {
			if ( $theme === ( $row['stylesheet'] ?? '' ) ) {
				return $row['update'];
			}
		}
		return 'missing';
	};
	try {
		$offer_theme( '1.0' );
		$check( '10-1 the Themes list shows no update older than the installed theme', null === $listed(), wp_json_encode( $listed() ) );
		list( $status, $d ) = $call( 'POST', '/minn-admin/v1/themes/update', array( 'stylesheet' => $theme ) );
		$check( '10-1 a theme update older than what is installed is refused', 400 === $status && 'no_update' === ( $d['code'] ?? '' ), $status . ' ' . ( $d['code'] ?? '' ) );
		$offer_theme( '99.0' );
		$check( '10-1 control: a newer one is still listed', '99.0' === $listed(), wp_json_encode( $listed() ) );
		list( $status, $d ) = $call( 'POST', '/minn-admin/v1/themes/update', array( 'stylesheet' => $theme ) );
		$check( '10-1 control: and its update goes ahead (the download fails offline)', 500 === $status && 'update_failed' === ( $d['code'] ?? '' ), $status . ' ' . ( $d['code'] ?? '' ) );

		$offer_plugins( array( $plugin => '1.0', $gone => '1.0' ) );
		list( $status, $d ) = $call( 'POST', '/minn-admin/v1/plugins/update', array( 'plugin' => $plugin ) );
		$check( '10-1 a plugin update older than what is installed is refused', 400 === $status && 'no_update' === ( $d['code'] ?? '' ), $status . ' ' . ( $d['code'] ?? '' ) );
		list( $status, $d ) = $call( 'POST', '/minn-admin/v1/plugins/update', array( 'plugin' => $gone ) );
		$check( '10-1 an offer for a plugin that is no longer installed is refused', 400 === $status && 'no_update' === ( $d['code'] ?? '' ) && ! is_dir( WP_PLUGIN_DIR . '/minn-gone-test' ), $status . ' ' . ( $d['code'] ?? '' ) );
		$offer_plugins( array( $plugin => '99.0' ) );
		list( $status, $d ) = $call( 'POST', '/minn-admin/v1/plugins/update', array( 'plugin' => $plugin ) );
		$check( '10-1 control: a newer plugin offer goes ahead (the download fails offline)', 500 === $status && 'update_failed' === ( $d['code'] ?? '' ), $status . ' ' . ( $d['code'] ?? '' ) );
	} finally {
		remove_filter( 'pre_http_request', $offline, 1 );
		set_site_transient( 'update_themes', $themes_was );
		set_site_transient( 'update_plugins', $plugins_was );
	}
} )();

// --- 00-1 An ACF User field keeps a stored user whose role no longer matches ---
// The panel sends every ACF field back on any edit. A multi-value User field
// limited to a role served each stored user, and the write refused one whose
// role had changed since, so editing an unrelated field dropped them (and ACF's
// bidirectional update dropped the post from their side).
( function () use ( $check, $call, $drop_user ) {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		echo "SKIP  00-1 ACF inactive\n";
		return;
	}
	acf_add_local_field_group( array(
		'key'      => 'group_minn45u',
		'title'    => 'Minn 00-1',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
		'fields'   => array(
			array( 'key' => 'field_minn45u_note', 'name' => 'minn45u_note', 'label' => 'Note', 'type' => 'text' ),
			array( 'key' => 'field_minn45u_rev', 'name' => 'minn45u_rev', 'label' => 'Reviewers', 'type' => 'user', 'multiple' => 1, 'role' => array( 'editor' ), 'return_format' => 'id' ),
		),
	) );
	$tag  = wp_rand( 1000, 9999 );
	$mk   = function ( $who, $role ) use ( $tag ) {
		return (int) wp_insert_user( array( 'user_login' => "minn45u-{$who}-{$tag}", 'user_email' => "minn45u-{$who}-{$tag}@example.com", 'user_pass' => wp_generate_password( 24 ), 'role' => $role, 'display_name' => "Minn {$who}" ) );
	};
	$kept   = $mk( 'kept', 'editor' );
	$moved  = $mk( 'moved', 'editor' );
	$author = $mk( 'author', 'author' );
	$post   = (int) wp_insert_post( array( 'post_title' => 'Minn 00-1', 'post_status' => 'draft', 'post_author' => get_current_user_id() ) );
	$stored = function () use ( $post ) {
		wp_cache_delete( $post, 'post_meta' );
		return array_map( 'intval', (array) get_post_meta( $post, 'minn45u_rev', true ) );
	};
	try {
		update_field( 'field_minn45u_note', 'Before', $post );
		update_field( 'field_minn45u_rev', array( $kept, $moved ), $post );
		( new WP_User( $moved ) )->set_role( 'author' );

		list( , $got ) = $call( 'GET', '/wp/v2/posts/' . $post, null, array( 'context' => 'edit' ) );
		$vals = json_decode( wp_json_encode( $got['minn_acf'] ?? array() ), true );
		$vals['minn45u_note'] = 'After';
		list( $status ) = $call( 'POST', '/wp/v2/posts/' . $post, array( 'minn_acf' => $vals ) );
		$check( '00-1 editing another field keeps a reviewer whose role changed', 200 === $status && array( $kept, $moved ) === $stored(), wp_json_encode( $stored() ) );
		$check( '00-1 control: the edited field saved', 'After' === get_post_meta( $post, 'minn45u_note', true ) );

		// Editing the User field itself: remove the reviewer who still has the
		// role, keep the one who doesn't, and try to add a new author.
		list( , $got ) = $call( 'GET', '/wp/v2/posts/' . $post, null, array( 'context' => 'edit' ) );
		$vals = json_decode( wp_json_encode( $got['minn_acf'] ?? array() ), true );
		$vals['minn45u_rev'] = array_values( array_filter( (array) $vals['minn45u_rev'], function ( $e ) use ( $moved ) {
			return (int) ( $e['value'] ?? 0 ) === $moved;
		} ) );
		$vals['minn45u_rev'][] = array( 'value' => (string) $author, 'label' => 'Minn author' );
		$call( 'POST', '/wp/v2/posts/' . $post, array( 'minn_acf' => $vals ) );
		$check( '00-1 editing the field keeps a stored reviewer that was sent back', array( $moved ) === $stored(), wp_json_encode( $stored() ) );
		$check( '00-1 control: a NEW user outside the role is still refused', ! in_array( $author, $stored(), true ) );
	} finally {
		wp_delete_post( $post, true );
		foreach ( array( $kept, $moved, $author ) as $id ) {
			$drop_user( $id );
		}
	}
} )();

// --- 04-3 A TEC event with several venues keeps them all on a panel save ---
// Events Calendar Pro links several venues, and TEC links exactly the venues a
// save submits. The panel read the first venue only and sent it back with any
// edit, so changing the cost unlinked the rest. Seeded as two _EventVenueID
// rows, the way Pro stores them.
( function () use ( $check, $call ) {
	if ( ! class_exists( 'Tribe__Events__API' ) ) {
		echo "SKIP  04-3 The Events Calendar inactive\n";
		return;
	}
	$ids   = array();
	$venue = function ( $name ) use ( &$ids ) {
		$id    = (int) wp_insert_post( array( 'post_type' => 'tribe_venue', 'post_status' => 'publish', 'post_title' => $name ) );
		$ids[] = $id;
		return $id;
	};
	$v1    = $venue( 'Minn 04-3 Hall A' );
	$v2    = $venue( 'Minn 04-3 Hall B' );
	$event = (int) wp_insert_post( array( 'post_type' => 'tribe_events', 'post_status' => 'draft', 'post_title' => 'Minn 04-3' ) );
	$ids[] = $event;
	try {
		update_post_meta( $event, '_EventStartDate', '2026-12-01 10:00:00' );
		update_post_meta( $event, '_EventEndDate', '2026-12-01 12:00:00' );
		update_post_meta( $event, '_EventCost', '10' );
		add_post_meta( $event, '_EventVenueID', $v1 );
		add_post_meta( $event, '_EventVenueID', $v2 );

		list( , $fields ) = $call( 'GET', '/minn-admin/v1/tec/fields', null, array( 'post_id' => $event, 'post_type' => 'tribe_events' ) );
		$names = wp_list_pluck( (array) ( $fields['groups'][0]['fields'] ?? array() ), 'name' );
		$check( '04-3 the venue field is locked on an event with two venues', ! in_array( 'venue', $names, true ) && in_array( 'cost', $names, true ), wp_json_encode( $names ) );

		list( , $got ) = $call( 'GET', '/wp/v2/tribe_events/' . $event, null, array( 'context' => 'edit' ) );
		$vals         = json_decode( wp_json_encode( $got['minn_tec'] ?? array() ), true );
		$vals['cost'] = '15';
		// An older client still sending the first venue changes nothing either.
		$vals['venue'] = array( 'value' => (string) $v1, 'label' => 'Minn 04-3 Hall A' );
		list( $status ) = $call( 'POST', '/wp/v2/tribe_events/' . $event, array( 'minn_tec' => $vals ) );
		wp_cache_delete( $event, 'post_meta' );
		$venues = array_map( 'intval', (array) get_post_meta( $event, '_EventVenueID' ) );
		sort( $venues );
		$check( '04-3 changing the cost keeps both venues', 200 === $status && array( $v1, $v2 ) === $venues, $status . ' ' . wp_json_encode( $venues ) );
		$check( '04-3 control: the cost saved', '15' === (string) get_post_meta( $event, '_EventCost', true ), (string) get_post_meta( $event, '_EventCost', true ) );
	} finally {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}
} )();

// --- 05-3..05-6 The database browser hides credentials by column name, by
// the vendor rows that keep them, and wherever a copy of one sits ---
( function () use ( $check, $call ) {
	global $wpdb;
	$rows = function ( $table, $params = array() ) use ( $call ) {
		return $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $table ) + $params );
	};
	// The cell in column $col of the row whose $by column equals $val.
	$cell = function ( $d, $col, $by, $val ) {
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
	// What a failing check prints: never the cell, only what it was.
	$shape = function ( $c ) use ( $hidden ) {
		return $hidden( $c ) ? 'redacted' : ( 'missing' === $c ? 'missing' : 'shown, ' . strlen( is_array( $c ) ? wp_json_encode( $c ) : (string) $c ) . ' bytes' );
	};
	$fake  = 'minnFAKEkey' . wp_rand( 100000, 999999 ) . 'abcXYZ42';
	$t     = $wpdb->prefix . 'minn_test_vendor';
	$undo  = array();
	try {
		// 05-3: a vendor's own accounts table.
		$wpdb->query( "CREATE TABLE `{$t}` ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100), password VARCHAR(255), api_secret VARCHAR(255), account_nonse VARCHAR(100), refreshToken VARCHAR(255), token_expires VARCHAR(30), privkey TEXT, key_local_private TEXT, api_keys TEXT, prompt_tokens INT, usedTokens TEXT, nonce_action VARCHAR(60), token_endpoint_auth_method VARCHAR(60), ts_password_modified VARCHAR(30), note TEXT )" );
		$undo[] = function () use ( $wpdb, $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
		};
		// Created before the browser's first call: it lists tables once a request.
		$tk = $wpdb->prefix . 'minn_test_sessions';
		$wpdb->query( "CREATE TABLE `{$tk}` ( token VARCHAR(64) NOT NULL PRIMARY KEY, label VARCHAR(30) )" );
		$undo[] = function () use ( $wpdb, $tk ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$tk}`" );
		};
		$wpdb->insert( $t, array( 'email' => 'dana@example.com', 'password' => '$2y$10$abcdefghijklmnopqrstuuWm0Hj7mYkzW8dYbnA5rZp4Qz1v2Kd9W', 'api_secret' => 'live_secret_0123456789abc', 'account_nonse' => 'nonse0123456789abc', 'refreshToken' => 'rt_0123456789abcdef', 'token_expires' => '2026-12-01 10:00:00', 'privkey' => '-----BEGIN PRIVATE KEY-----', 'key_local_private' => '-----BEGIN RSA PRIVATE KEY-----', 'api_keys' => 'k_a,k_b', 'prompt_tokens' => 1234, 'usedTokens' => 'eyJ.probe.sig', 'nonce_action' => 'wc_admin_note_action', 'token_endpoint_auth_method' => 'client_secret_post', 'ts_password_modified' => '2026-01-01 00:00:00', 'note' => 'copy: ' . $fake ) );
		list( $st, $d ) = $rows( $t );
		$ok = 200 === $st;
		foreach ( array( 'password', 'api_secret', 'account_nonse', 'refreshToken' ) as $col ) {
			$ok = $ok && $hidden( $cell( $d, $col, 'email', 'dana@example.com' ) );
		}
		$check( '05-3 a vendor table\'s password, secret, nonse and token columns are hidden by name', $ok, $st . ' ' . $shape( $cell( $d, 'password', 'email', 'dana@example.com' ) ) );
		$check( '05-3 control: a column that only describes one, and plain data, still show', '2026-12-01 10:00:00' === $cell( $d, 'token_expires', 'email', 'dana@example.com' ) && 'dana@example.com' === $cell( $d, 'email', 'email', 'dana@example.com' ) );
		$check( '05-3 private keys under either word order, and plural names, are hidden', $hidden( $cell( $d, 'privkey', 'email', 'dana@example.com' ) ) && $hidden( $cell( $d, 'key_local_private', 'email', 'dana@example.com' ) ) && $hidden( $cell( $d, 'api_keys', 'email', 'dana@example.com' ) ) && $hidden( $cell( $d, 'usedTokens', 'email', 'dana@example.com' ) ) );
		$check( '05-3 control: an action, a method, a modified time and a token counter named after one still show', '1234' === (string) $cell( $d, 'prompt_tokens', 'email', 'dana@example.com' ) && 'wc_admin_note_action' === $cell( $d, 'nonce_action', 'email', 'dana@example.com' ) && 'client_secret_post' === $cell( $d, 'token_endpoint_auth_method', 'email', 'dana@example.com' ) && '2026-01-01 00:00:00' === $cell( $d, 'ts_password_modified', 'email', 'dana@example.com' ) );
		list( , $sorted ) = $rows( $t, array( 'orderby' => 'note' ) );
		$sortable = wp_list_pluck( (array) ( $sorted['columns'] ?? array() ), 'sortable', 'name' );
		$check( '05-3 a long text column is not a sort key (a copy can sit there)', 'note' !== ( $sorted['orderby'] ?? '' ) && false === ( $sortable['note'] ?? true ) && true === ( $sortable['email'] ?? false ), wp_json_encode( array( $sorted['orderby'] ?? null, $sortable['note'] ?? null ) ) );
		$wpdb->insert( $tk, array( 'token' => 'sess_0123456789abcdef', 'label' => 'probe' ) );
		list( , $sd ) = $rows( $tk );
		$check( '05-3 a table keyed by a credential is not ordered by it', '' === ( $sd['orderby'] ?? 'x' ), wp_json_encode( $sd['orderby'] ?? null ) );
		$sortable = wp_list_pluck( (array) ( $d['columns'] ?? array() ), 'sortable', 'name' );
		list( , $sorted ) = $rows( $t, array( 'orderby' => 'password' ) );
		list( $fst ) = $rows( $t, array( 'fcol' => 'api_secret', 'fq' => 'live' ) );
		$check( '05-3 and they are neither sortable nor searchable', false === ( $sortable['password'] ?? true ) && 'password' !== ( $sorted['orderby'] ?? '' ) && 400 === $fst, wp_json_encode( array( $sortable['password'] ?? null, $sorted['orderby'] ?? null, $fst ) ) );

		// 05-4: OttoKit's logged request, where its retry writes the bearer token.
		$ot = $wpdb->prefix . 'suretriggers_webhook_requests';
		if ( $ot === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ot ) ) ) ) {
			$wpdb->insert( $ot, array( 'request_method' => 'POST', 'request_url' => 'https://minn-test.example/hook', 'request_data' => wp_json_encode( array( 'headers' => array( 'Authorization' => 'Bearer ' . $fake ) ) ), 'response_code' => 500, 'status' => 'minn_test', 'error_info' => '' ) );
			$oid    = (int) $wpdb->insert_id;
			$undo[] = function () use ( $wpdb, $ot, $oid ) {
				$wpdb->delete( $ot, array( 'id' => $oid ) );
			};
			list( , $d ) = $rows( $ot, array( 'fcol' => 'status', 'fq' => 'minn_test' ) );
			$check( '05-4 OttoKit\'s logged request data is hidden', $hidden( $cell( $d, 'request_data', 'id', $oid ) ), $shape( $cell( $d, 'request_data', 'id', $oid ) ) );
		}

		// 05-5 / 05-6: rows that keep licence and payment credentials.
		$opt = function ( $name ) use ( $rows, $cell ) {
			list( , $d ) = $rows( $GLOBALS['wpdb']->options, array( 'fcol' => 'option_name', 'fq' => $name ) );
			return $cell( $d, 'option_value', 'option_name', $name );
		};
		foreach ( array( 'fs_api_cache', 'wpforms_settings', 'wpforms_crypto_secret_key', 'wpforms_square_connections', 'frm_strp_connect_live_password_server' ) as $name ) {
			if ( false === get_option( $name ) ) {
				add_option( $name, 'minn probe ' . $name, '', false );
				$undo[] = function () use ( $name ) {
					delete_option( $name );
				};
			}
			$check( "05-5/05-6 option {$name} is hidden", $hidden( $opt( $name ) ), $shape( $opt( $name ) ) );
		}
		$um_key = $wpdb->get_blog_prefix() . 'elementor_connect_common_data';
		$um_id  = add_user_meta( get_current_user_id(), $um_key, array( 'access_token_secret' => 'ats_' . $fake ) );
		$undo[] = function () use ( $um_key ) {
			delete_user_meta( get_current_user_id(), $um_key );
		};
		list( , $d ) = $rows( $wpdb->usermeta, array( 'fcol' => 'meta_key', 'fq' => $um_key ) );
		$check( '05-5 Elementor Connect\'s per-user secrets are hidden', $hidden( $cell( $d, 'meta_value', 'umeta_id', $um_id ) ), $shape( $cell( $d, 'meta_value', 'umeta_id', $um_id ) ) );

		// What counts as a credential in a stored value: not a settings list's
		// paths and file names, and only a JWT's signature.
		$paths = Minn_Admin_DB::credential_strings( serialize( array( 'exclude' => array( '/wp-content/uploads/2024/', 'hero-banner-2024.jpg', 'IMG_20240512_123456', '/members/2024/area/' ) ) ) );
		$check( 'value: a settings list of paths and file names yields no credential', array() === $paths, wp_json_encode( array_keys( $paths ) ) );
		$jwt  = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.Sig0123456789abcdefGHIJKLmnop';
		$got  = Minn_Admin_DB::credential_strings( serialize( array( 'access_token' => $jwt ) ) );
		$check( 'value: a JWT counts whole and by its signature, not by its shared header', isset( $got['Sig0123456789abcdefGHIJKLmnop'] ) && ! isset( $got['eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9'] ), wp_json_encode( array_map( 'strlen', array_keys( $got ) ) ) );
		$named = Minn_Admin_DB::credential_strings( serialize( array(
			'user_tokens'  => array( 1 => 'abcdEFGH1234567890.ijklMNOP0987654321.1' ),
			'licence_keys' => array( 'ABCD1234EFGH5678IJKL' ),
			'settings'     => array( array( 'value' => 'c2VjcmV0+VmFsdWUx/MjM0NTY3OA' ) ),
		) ) );
		$check( 'value: lists under compound containers and a value field still count', isset( $named['abcdEFGH1234567890'], $named['ijklMNOP0987654321'], $named['ABCD1234EFGH5678IJKL'], $named['c2VjcmV0+VmFsdWUx/MjM0NTY3OA'] ), wp_json_encode( array_map( 'strlen', array_keys( $named ) ) ) );
		// code, sig and hash are whole words anywhere in a name (and
		// signature one too), split on - as well as _; a shortcode is not one.
		$words = Minn_Admin_DB::credential_strings( serialize( array(
			'signature'     => 'Ab3+De4/Gh5Ij6Kl7Mn8Op9Q',
			'sig_value'     => 'Rs3+Tu4/Vw5Xy6Za7Bc8De9F',
			'code_verifier' => 'Qr5+St6/Uv7Wx8Yz9Ab0Cd1E',
			'access-code'   => 'Ef2+Gh3/Ij4Kl5Mn6Op7Qr8S',
			'shortcode'     => 'Tu9+Vw0/Xy1Za2Bc3De4Fg5H',
		) ) );
		$check( 'value: code, sig and hash count as whole words anywhere in a name, and a shortcode does not',
			isset( $words['Ab3+De4/Gh5Ij6Kl7Mn8Op9Q'], $words['Rs3+Tu4/Vw5Xy6Za7Bc8De9F'], $words['Qr5+St6/Uv7Wx8Yz9Ab0Cd1E'], $words['Ef2+Gh3/Ij4Kl5Mn6Op7Qr8S'] ) && ! isset( $words['Tu9+Vw0/Xy1Za2Bc3De4Fg5H'] ),
			wp_json_encode( array_keys( $words ) ) );
		list( , $pd ) = $rows( $wpdb->posts, array( 'per_page' => 1 ) );
		$ps = wp_list_pluck( (array) ( $pd['columns'] ?? array() ), 'sortable', 'name' );
		$check( 'value: control: a post title still sorts, a post body does not', true === ( $ps['post_title'] ?? null ) && false === ( $ps['post_content'] ?? null ), wp_json_encode( array( $ps['post_title'] ?? null, $ps['post_content'] ?? null ) ) );

		// Value redaction: a credential the browser hides by name, and copies
		// of it under names no rule knows.
		$home = '';
		foreach ( array( 'postman_auth_token', 'jetpack_secrets' ) as $name ) {
			if ( false === get_option( $name ) ) {
				$home = $name;
				break;
			}
		}
		if ( '' === $home ) {
			echo "SKIP  value redaction: no free credential row to plant\n";
			return;
		}
		add_option( $home, $fake . '.' . strrev( $fake ), '', false );
		add_option( 'minn_test_copy_raw', 'cached link https://vendor.example/dl?key=' . $fake, '', false );
		$post = (int) wp_insert_post( array( 'post_title' => 'Minn copy probe', 'post_status' => 'draft' ) );
		add_post_meta( $post, 'minn_test_copy_b64', base64_encode( 'license=' . $fake . '&site=x' ) );
		$undo[] = function () use ( $home, $post ) {
			delete_option( $home );
			delete_option( 'minn_test_copy_raw' );
			wp_delete_post( $post, true );
		};
		$check( 'value: a raw copy in an option no rule names is hidden', $hidden( $opt( 'minn_test_copy_raw' ) ), $shape( $opt( 'minn_test_copy_raw' ) ) );
		// The second half of a dotted token (key.secret) on its own.
		add_option( 'minn_test_copy_half', 'secret=' . strrev( $fake ), '', false );
		$undo[] = function () {
			delete_option( 'minn_test_copy_half' );
		};
		$check( 'value: a copy of one part of a dotted token is hidden', $hidden( $opt( 'minn_test_copy_half' ) ), $shape( $opt( 'minn_test_copy_half' ) ) );
		list( , $d ) = $rows( $wpdb->postmeta, array( 'fcol' => 'meta_key', 'fq' => 'minn_test_copy_b64' ) );
		$check( 'value: a base64 copy in post meta is hidden', $hidden( $cell( $d, 'meta_value', 'meta_key', 'minn_test_copy_b64' ) ), $shape( $cell( $d, 'meta_value', 'meta_key', 'minn_test_copy_b64' ) ) );
		list( , $d ) = $rows( $t );
		$check( 'value: a copy in a vendor table\'s plain column is hidden', $hidden( $cell( $d, 'note', 'email', 'dana@example.com' ) ), $shape( $cell( $d, 'note', 'email', 'dana@example.com' ) ) );
		list( $st, $d ) = $rows( $wpdb->options, array( 'fcol' => 'option_value', 'fq' => substr( $fake, 0, 14 ) ) );
		$check( 'value: a search for part of it does not find the copy', 200 === $st && 'missing' === $cell( $d, 'option_value', 'option_name', 'minn_test_copy_raw' ), $st . ' ' . $shape( $cell( $d, 'option_value', 'option_name', 'minn_test_copy_raw' ) ) );
		$wl = $wpdb->prefix . 'wsal_metadata';
		if ( $wl === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wl ) ) ) ) {
			$wpdb->insert( $wl, array( 'occurrence_id' => 0, 'name' => 'MinnTestCopy', 'value' => 'new value ' . $fake ) );
			$wid    = (int) $wpdb->insert_id;
			$undo[] = function () use ( $wpdb, $wl, $wid ) {
				$wpdb->delete( $wl, array( 'id' => $wid ) );
			};
			list( , $d ) = $rows( $wl, array( 'fcol' => 'name', 'fq' => 'MinnTestCopy' ) );
			$check( 'value: control: an activity log\'s copy stays as its own screen shows it', 'new value ' . $fake === $cell( $d, 'value', 'id', $wid ), $shape( $cell( $d, 'value', 'id', $wid ) ) );
		}
	} finally {
		foreach ( array_reverse( $undo ) as $fn ) {
			$fn();
		}
	}
} )();

// --- 05-2 A refused TEC paste leaves TEC's status transients as they were,
// object cache or not ---
// TEC records every answer to a pasted key (a refusal, an outage) as the
// product's status: an option, a 12-hour md5-named transient and the
// any-licence-valid transient. Minn's snapshot read wp_options rows only, so
// under a persistent object cache, where set_transient() never reaches
// wp_options, a typo marked a working key invalid for 12 hours. The object
// cache is simulated in this process (transients then live in memory only),
// the licence servers are offline, and every licence row is held and put back.
( function () use ( $check, $call ) {
	global $wpdb;
	if ( ! class_exists( 'Tribe__PUE__Checker' ) || ! class_exists( 'Tribe__Tickets_Plus__Main' ) ) {
		echo "SKIP  05-2 Event Tickets Plus inactive\n";
		return;
	}
	$likes = array( $wpdb->esc_like( 'stellarwp_uplink_license_key_' ) . '%', $wpdb->esc_like( 'pue_' ) . '%' );
	$rows  = function ( $like ) use ( $wpdb ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s", $like ), ARRAY_A );
	};
	$held = array();
	foreach ( $likes as $like ) {
		$held[ $like ] = $rows( $like );
	}
	$off = function ( $pre, $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		return ( '' === $host || false !== strpos( $host, 'localhost' ) ) ? $pre : new WP_Error( 'minn_test_offline', 'offline' );
	};
	$host   = isset( $_SERVER['SERVER_NAME'] ) ? (string) $_SERVER['SERVER_NAME'] : strtolower( (string) wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) );
	$status = md5( 'event-tickets-plus' . $host );
	$any    = 'TEC_IS_ANY_LICENSE_VALID_TRANSIENT';
	$set    = array();
	$seen   = function ( $name ) use ( &$set ) {
		$set[] = $name;
	};
	$was_ext = wp_using_ext_object_cache( true );
	add_filter( 'pre_http_request', $off, PHP_INT_MAX, 3 );
	add_action( 'setted_transient', $seen );
	try {
		set_transient( $status, 'valid', 12 * HOUR_IN_SECONDS );
		set_transient( $any, array( 'plugins' => array( 'event-tickets-plus' => true ) ), HOUR_IN_SECONDS );
		$set = array();
		list( , $body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'tec-event-tickets-plus', 'action' => 'activate', 'secret' => 'minntestlicensetypo' ) );
		$check( '05-2 TEC wrote its status transients during the refused paste (the names this test watches)', in_array( $status, $set, true ) && in_array( $any, $set, true ), wp_json_encode( array_values( array_unique( $set ) ) ) );
		$check( '05-2 under an object cache the product\'s status transient is as it was', empty( $body['ok'] ) && 'valid' === get_transient( $status ), wp_json_encode( array( $body['ok'] ?? null, get_transient( $status ) ) ) );
		$any_now = get_transient( $any );
		$check( '05-2 and so is the any-licence-valid transient', is_array( $any_now ) && true === ( $any_now['plugins']['event-tickets-plus'] ?? null ), wp_json_encode( $any_now ) );
	} finally {
		remove_action( 'setted_transient', $seen );
		remove_filter( 'pre_http_request', $off, PHP_INT_MAX );
		delete_transient( $status );
		delete_transient( $any );
		wp_using_ext_object_cache( $was_ext );
		foreach ( $held as $like => $was ) {
			foreach ( $rows( $like ) as $now ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $now['option_name'] ) );
			}
			foreach ( $was as $row ) {
				$wpdb->insert( $wpdb->options, $row );
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
} )();

// --- 12-3 peers: an edit form keeps a type the vendor left empty ---
// A strict select with an empty seed falls back to its first option, and the
// detail form posts every field. WPCode and FluentSnippets run nothing for a
// snippet with no type, so a rename made an inert snippet PHP; Novamira reads
// a memory with no type as a project memory, and a rename made it a user one.
( function () use ( $check, $call ) {
	$field = function ( $route_part, $key ) {
		foreach ( Minn_Admin_Surfaces::all() as $sf ) {
			$edit = $sf['collection']['detail']['edit'] ?? ( $sf['detail']['edit'] ?? null );
			if ( is_array( $edit ) && false !== strpos( (string) ( $edit['route'] ?? '' ), $route_part ) ) {
				foreach ( (array) ( $edit['fields'] ?? array() ) as $f ) {
					if ( $key === ( $f['key'] ?? '' ) ) {
						return $f;
					}
				}
			}
		}
		return null;
	};
	// WPCode.
	if ( post_type_exists( 'wpcode' ) && taxonomy_exists( 'wpcode_type' ) ) {
		$f = $field( 'wpcode/snippets/', 'code_type' );
		$check( '12-3 peer WPCode: the Type field is clearable', ! empty( $f['clearable'] ), wp_json_encode( $f['clearable'] ?? null ) );
		$id = (int) wp_insert_post( array( 'post_type' => 'wpcode', 'post_status' => 'draft', 'post_title' => 'Minn untyped probe', 'post_content' => 'echo 1;' ) );
		wp_set_object_terms( $id, array(), 'wpcode_type' );
		try {
			list( $st, $d ) = $call( 'PUT', '/minn-admin/v1/wpcode/snippets/' . $id, array( 'name' => 'Minn untyped probe 2', 'code_type' => '' ) );
			$terms = wp_get_object_terms( $id, 'wpcode_type', array( 'fields' => 'slugs' ) );
			$check( '12-3 peer WPCode: a rename sending the empty type leaves it untyped', in_array( $st, array( 200, 201 ), true ) && array() === $terms && 'Minn untyped probe 2' === get_the_title( $id ), $st . ' ' . wp_json_encode( $terms ) . ' ' . wp_json_encode( $d['code'] ?? null ) );
		} finally {
			wp_delete_post( $id, true );
		}
	}
	// Novamira (its read path, on an in-memory post: the lab registers no
	// memories).
	if ( function_exists( 'minn_admin_novamira_memory_item' ) ) {
		$p    = new WP_Post( (object) array( 'ID' => PHP_INT_MAX, 'post_title' => 'Minn untyped memory', 'post_excerpt' => '', 'post_content' => 'note', 'post_modified' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00' ) );
		$item = minn_admin_novamira_memory_item( $p );
		$check( '12-3 peer Novamira: a memory with no type reads as the project memory Novamira treats it as', 'project' === $item['type'], wp_json_encode( $item['type'] ) );
	}
	// Custom CSS & JS: the options normalizer every edit goes through.
	if ( function_exists( 'minn_admin_ccj_normalize_options' ) ) {
		$kept  = minn_admin_ccj_normalize_options( array( 'linking' => 'desktop', 'language' => 'html' ), array( 'linking' => 'desktop', 'language' => 'html' ) );
		$fresh = minn_admin_ccj_normalize_options( array( 'linking' => 'desktop' ), array( 'linking' => 'internal' ) );
		$check( '12-3 peer CCJ: a Pro linking value round-trips when it is the stored one', 'desktop' === $kept['linking'], wp_json_encode( $kept['linking'] ) );
		$check( '12-3 peer CCJ: control: a new value outside the list still falls back', 'internal' === $fresh['linking'], wp_json_encode( $fresh['linking'] ) );
	}
	// FluentSnippets.
	if ( function_exists( 'minn_admin_fluent_snippets_active' ) && minn_admin_fluent_snippets_active() ) {
		$check( '12-3 peer FluentSnippets: Type and Run at are clearable', ! empty( $field( 'fluent-snippets/', 'type' )['clearable'] ) && ! empty( $field( 'fluent-snippets/', 'run_at' )['clearable'] ) );
		list( , $made ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn untyped fluent', 'code' => '/* nothing */', 'type' => 'css', 'run_at' => 'wp_head', 'active' => false ) );
		$file = (string) ( $made['id'] ?? '' );
		$path = \FluentSnippets\App\Helpers\Helper::getStorageDir() . '/' . $file;
		try {
			if ( '' === $file || ! is_file( $path ) ) {
				$check( '12-3 peer FluentSnippets: a probe snippet was created', false, wp_json_encode( $made ) );
				return;
			}
			// Their own parser defaults a missing @type / @run_at line to ''.
			file_put_contents( $path, preg_replace( '/^\s*\*\s*@(type|run_at):?.*\R/m', '', file_get_contents( $path ) ) );
			list( $st, $pd ) = $call( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), array( 'name' => 'Minn untyped fluent 2', 'type' => '', 'run_at' => '' ) );
			$meta = ( new \FluentSnippets\App\Model\Snippet() )->findByFileName( $file );
			$meta = is_array( $meta ) ? ( $meta['meta'] ?? array() ) : array();
			$check( '12-3 peer FluentSnippets: a rename sending the empty type asks for one instead of making it PHP', 400 === $st && 'minn_fluent_no_type' === ( $pd['code'] ?? '' ) && '' === (string) ( $meta['type'] ?? '' ) && 'Minn untyped fluent' === ( $meta['name'] ?? '' ), $st . ' ' . wp_json_encode( array( $meta['type'] ?? null, $pd['code'] ?? null ) ) );
			list( $st, $pd ) = $call( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), array( 'name' => 'Minn untyped fluent 2', 'type' => 'css', 'run_at' => '' ) );
			$check( '12-3 peer FluentSnippets: an empty run-at is asked for too', 400 === $st && 'minn_fluent_no_run_at' === ( $pd['code'] ?? '' ), $st . ' ' . wp_json_encode( $pd['code'] ?? null ) );
			list( $st ) = $call( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), array( 'name' => 'Minn untyped fluent 2', 'type' => 'css', 'run_at' => 'wp_head' ) );
			$meta = ( new \FluentSnippets\App\Model\Snippet() )->findByFileName( $file );
			$meta = is_array( $meta ) ? ( $meta['meta'] ?? array() ) : array();
			$check( '12-3 peer FluentSnippets: control: with both picked it saves them', in_array( $st, array( 200, 201 ), true ) && 'css' === ( $meta['type'] ?? '' ) && 'wp_head' === ( $meta['run_at'] ?? '' ), $st . ' ' . wp_json_encode( array( $meta['type'] ?? null, $meta['run_at'] ?? null ) ) );
		} finally {
			if ( '' !== $file ) {
				$call( 'DELETE', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) );
				if ( is_file( $path ) ) {
					unlink( $path );
				}
			}
		}
	}
} )();

// --- 05-1 Licence actions follow each vendor's own licence gate ---
// ACF PRO runs licence actions only on its Updates page, which an agency hides
// from a client's administrators (show_admin / show_updates); WPForms checks its
// manage cap, Gravity Forms gravityforms_edit_settings. Minn attached Activate,
// Deactivate and Re-check for any manage_options holder. Asked of the provider
// list only: no licence action runs (the lab holds real seats).
( function () use ( $check, $drop_user ) {
	$wpf_user = 0;
	$wpf_was  = 0;
	if ( ! function_exists( 'minn_admin_license_default_providers' ) ) {
		echo "SKIP  05-1 licences unavailable\n";
		return;
	}
	$acts = function ( $id ) {
		$p = minn_admin_license_default_providers()[ $id ] ?? null;
		return null === $p ? null : array_values( array_filter( array( 'activate', 'deactivate', 'verify' ), function ( $a ) use ( $p ) {
			return ! empty( $p[ $a ] ) && is_callable( $p[ $a ] );
		} ) );
	};
	$cases = array(
		'acf-pro'      => array( 'ACF PRO with its Updates page hidden', function () {
			add_filter( 'acf/settings/show_updates', '__return_false', 99 );
		}, function () {
			remove_filter( 'acf/settings/show_updates', '__return_false', 99 );
		} ),
		// As a fresh administrator: wpforms_current_user_can() keeps its answer
		// per user for the rest of the process.
		'wpforms'      => array( 'WPForms with its manage cap narrowed', function () use ( &$wpf_user, &$wpf_was ) {
			$wpf_was  = get_current_user_id();
			$wpf_user = (int) wp_insert_user( array( 'user_login' => 'minn-wpf-' . wp_rand( 1000, 9999 ), 'user_email' => 'minn-wpf-' . wp_rand( 1000, 9999 ) . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'administrator' ) );
			wp_set_current_user( $wpf_user );
			add_filter( 'wpforms_manage_cap', 'minn_test_no_cap' );
		}, function () use ( &$wpf_user, &$wpf_was, $drop_user ) {
			remove_filter( 'wpforms_manage_cap', 'minn_test_no_cap' );
			wp_set_current_user( $wpf_was );
			$drop_user( $wpf_user );
		} ),
		'gravityforms' => array( 'Gravity Forms without its settings cap', function () {
			add_filter( 'user_has_cap', 'minn_test_no_gf_settings', 99 );
		}, function () {
			remove_filter( 'user_has_cap', 'minn_test_no_gf_settings', 99 );
		} ),
	);
	if ( ! function_exists( 'minn_test_no_cap' ) ) {
		function minn_test_no_cap() {
			return 'do_not_allow';
		}
		function minn_test_no_gf_settings( $caps ) {
			$caps['gravityforms_edit_settings'] = false;
			$caps['gform_full_access']          = false;
			return $caps;
		}
	}
	foreach ( $cases as $id => $case ) {
		list( $label, $narrow, $restore ) = $case;
		$open = $acts( $id );
		if ( empty( $open ) ) {
			echo "SKIP  05-1 {$id}: no licence actions on this site\n";
			continue;
		}
		$narrow();
		$gated = $acts( $id );
		$restore();
		$check( "05-1 {$label}: no licence actions", array() === $gated, wp_json_encode( $gated ) );
		$check( "05-1 control: {$id} keeps them for its own admin", ! empty( $acts( $id ) ), wp_json_encode( $open ) );
	}
} )();

// --- A JetSearch suggestion edit keeps searches counted while it was open ---
// JetSearch adds 1 to a suggestion's weight on every search, and the edit form
// sent back the weight it loaded, so a rename put the count back to then.
( function () use ( $check, $call ) {
	global $wpdb;
	if ( ! function_exists( 'minn_admin_jet_search_has_table' ) || ! minn_admin_jet_search_has_table() ) {
		echo "SKIP  JetSearch suggestions unavailable\n";
		return;
	}
	$t = minn_admin_jet_search_table();
	$wpdb->insert( $t, array( 'name' => 'minn weight probe', 'weight' => 5, 'parent' => '', 'term' => '' ), array( '%s', '%d', '%s', '%s' ) );
	$id = (int) $wpdb->insert_id;
	try {
		list( , $item ) = $call( 'GET', '/minn-admin/v1/jet-search/suggestions/' . $id );
		// Visitors search it while the form is open.
		$wpdb->update( $t, array( 'weight' => 9 ), array( 'id' => $id ) );
		// The form: its fields plus the preserved weight_seen.
		$call( 'POST', '/minn-admin/v1/jet-search/suggestions/' . $id, array( 'name' => 'minn weight probe 2', 'weight' => $item['weight'], 'weight_seen' => $item['weight_seen'] ?? null ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT name, weight FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$check( 'JetSearch: a rename keeps the searches counted while the form was open', 'minn weight probe 2' === $row->name && 9 === (int) $row->weight, wp_json_encode( $row ) );
		$call( 'POST', '/minn-admin/v1/jet-search/suggestions/' . $id, array( 'name' => 'minn weight probe 2', 'weight' => 20, 'weight_seen' => 9 ) );
		$check( 'JetSearch control: a weight you change is saved', 20 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT weight FROM {$t} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} finally {
		$wpdb->delete( $t, array( 'id' => $id ), array( '%d' ) );
	}
} )();

$summary();
