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
	register_post_type( $slug, array( 'public' => false, 'show_ui' => false, 'show_in_rest' => true, 'label' => 'V045s' ) );
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

$summary();
