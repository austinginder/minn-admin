<?php
/**
 * Regressions for the v0.45.0 shipped-zip registry scan (findings that open
 * v0.46.0), core REST half: #1 island previews and Otter, #4 the network
 * upgrade gate, #5 and #11 language removal, #10 the site palette reset,
 * #12 the wp-config switches.
 *
 * Each section calls what the route calls, as the role the finding named,
 * with a control where the fix could over-block. Sections SKIP when their
 * plugin is inactive or they need the other kind of site. Fixtures (users,
 * roles, a private post, fake language catalogs, options) are removed at
 * the end however the run ends.
 *
 * Run: wp eval-file tests/security-v046-registry-rest.test.php --user=admin --path=<site>
 *      and on a network, from a site other than the main one:
 *      wp eval-file tests/security-v046-registry-rest.test.php --user=admin --url=<subsite> --path=<network>
 *
 * @package minn-admin
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? " — {$detail}" : '' );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = static function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

// Every fixture registers how to take itself back; this runs once, from the
// end of the file or from shutdown if the run dies first.
$undo    = array();
$cleanup = static function () use ( &$undo ) {
	while ( $undo ) {
		$fn = array_pop( $undo );
		try {
			$fn();
		} catch ( \Throwable $e ) {
			echo 'WARN  cleanup: ' . $e->getMessage() . "\n";
		}
	}
};
register_shutdown_function( $cleanup );

require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}
$admin = get_current_user_id();
if ( ! $admin || ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
$make_user = static function ( $role, $blog = 0 ) use ( &$undo ) {
	$login = 'minn-v046-' . $role . '-' . wp_rand( 10000, 99999 );
	$id    = (int) wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( $id && is_multisite() && $blog ) {
		add_user_to_blog( $blog, $id, $role );
	}
	if ( $id ) {
		$undo[] = static function () use ( $id ) {
			if ( is_multisite() ) {
				wpmu_delete_user( $id );
			} else {
				wp_delete_user( $id );
			}
		};
	}
	return $id;
};
// No update check, translation fetch or loopback leaves this process.
add_filter( 'pre_http_request', static function () {
	return new WP_Error( 'minn_test_offline', 'blocked by the test' );
}, 10, 0 );

/*
 * #1 (00-1): render-blocks took two of Otter's five resolvers off for a
 * caller who may not author dynamic data, and Otter's own excerpt reader put
 * one back mid-render. Now every Otter resolver is off for that caller, a
 * block carrying any Otter dynamic markup previews empty, and a resolver
 * something puts back while a block renders blanks that block and comes off
 * before the next. The re-arm is simulated with a filter here, not reached
 * through Otter.
 */
( static function () use ( $check, $skip, $call, $make_user, $admin, &$undo ) {
	$class = '\ThemeIsle\GutenbergBlocks\Plugins\Dynamic_Content';
	if ( ! class_exists( $class ) ) {
		$skip( '#1 Otter inactive' );
		return;
	}
	if ( is_multisite() ) {
		$skip( '#1 runs on a single site' );
		return;
	}
	$otter = $class::instance();
	$on    = static function () {
		global $wp_filter;
		$names = array();
		foreach ( array( 'render_block', 'otter_apply_dynamic_image' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $cb ) {
					// Otter's dynamic content (its animations, sticky and
					// the like stay; other vendors' dynamic content, such as
					// Kadence Pro's, checks read access itself).
					$owner = is_array( $cb['function'] ) && is_object( $cb['function'][0] ) ? get_class( $cb['function'][0] ) : '';
					if ( 0 === strpos( $owner, 'ThemeIsle\\' ) && false !== stripos( $owner, 'Dynamic' ) ) {
						$names[] = $hook . ':' . $cb['function'][1] . '@' . $prio;
					}
				}
			}
		}
		sort( $names );
		return $names;
	};
	$before = $on();
	$check( '#1 Otter registers its resolvers (fixture)', count( $before ) >= 5, implode( ', ', $before ) );

	$stamp   = wp_rand( 100000, 999999 );
	$post_id = wp_insert_post( array( 'post_title' => 'v046 private ' . $stamp, 'post_content' => 'secret ' . $stamp, 'post_status' => 'private', 'post_author' => $admin ) );
	$undo[]  = static function () use ( $post_id ) {
		wp_delete_post( $post_id, true );
	};
	$contrib = $make_user( 'contributor' );
	$render  = static function ( array $blocks ) use ( $call ) {
		list( $status, $data ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $blocks, 'post' => 0 ) );
		return array( $status, is_array( $data ) && isset( $data['rendered'] ) ? $data['rendered'] : null );
	};

	$id     = (int) $post_id;
	$shapes = array(
		'an <o-dynamic> element'                  => '<!-- wp:paragraph --><p><o-dynamic data-type="postTitle" data-context="' . $id . '">Post Title</o-dynamic></p><!-- /wp:paragraph -->',
		'an <o-dynamic-link> element'             => '<!-- wp:paragraph --><p><o-dynamic-link data-type="postURL" data-context="' . $id . '">read</o-dynamic-link></p><!-- /wp:paragraph -->',
		'a {otterDynamic?} magic tag'             => '<!-- wp:paragraph --><p>{otterDynamic?type=postTitle&context=' . $id . '}</p><!-- /wp:paragraph -->',
		'a #otterDynamicLink button'              => '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#otterDynamicLink?type=postURL&context=' . $id . '">Go</a></div><!-- /wp:button --></div><!-- /wp:buttons -->',
		'an otter/v1/dynamic image'               => '<!-- wp:image --><figure class="wp-block-image"><img src="/wp-json/otter/v1/dynamic?type=featuredImage&context=' . $id . '" alt=""/></figure><!-- /wp:image -->',
		'a \u-escaped marker in a block attribute' => '<!-- wp:paragraph {"className":"x","metadata":{"bg":"https:\/\/x\/wp-json\/otter/v1/dynamic?type=featuredImage"}} --><p>plain words</p><!-- /wp:paragraph -->',
		'an upper-case <O-DYNAMIC> element'       => '<!-- wp:paragraph --><p><O-DYNAMIC data-type="postTitle" data-context="' . $id . '">Post Title</O-DYNAMIC></p><!-- /wp:paragraph -->',
	);

	// Who is on the hook while a block renders, keyed by a word in it.
	$seen     = array();
	$recorder = static function ( $content ) use ( &$seen, $on ) {
		foreach ( array( 'minn-v046-plain', 'minn-v046-rearm', 'minn-v046-after' ) as $word ) {
			if ( false !== strpos( (string) $content, $word ) ) {
				$seen[ $word ] = $on();
			}
		}
		return $content;
	};
	add_filter( 'render_block', $recorder, PHP_INT_MAX );
	$undo[] = static function () use ( $recorder ) {
		remove_filter( 'render_block', $recorder, PHP_INT_MAX );
	};
	// Stands in for any code that puts a resolver back while a block
	// renders, as Otter's get_excerpt() does.
	$rearm = static function ( $content ) use ( $otter ) {
		if ( false !== strpos( (string) $content, 'minn-v046-rearm' ) ) {
			add_filter( 'render_block', array( $otter, 'apply_dynamic_content' ), 10 );
		}
		return $content;
	};
	add_filter( 'render_block', $rearm, 5 );
	$undo[] = static function () use ( $rearm ) {
		remove_filter( 'render_block', $rearm, 5 );
	};

	wp_set_current_user( $contrib );
	try {
		foreach ( $shapes as $name => $markup ) {
			list( $status, $out ) = $render( array( $markup ) );
			$check( "#1 a Contributor's preview of {$name} is empty", 200 === $status && is_array( $out ) && '' === trim( (string) ( $out[0] ?? 'x' ) ), $status . ' ' . substr( wp_json_encode( $out ), 0, 160 ) );
		}
		list( $status, $out ) = $render( array( '<!-- wp:paragraph --><p>minn-v046-plain words</p><!-- /wp:paragraph -->' ) );
		$check( "#1 control: a Contributor's plain block still previews", 200 === $status && false !== strpos( (string) ( $out[0] ?? '' ), 'minn-v046-plain words' ), $status . ' ' . wp_json_encode( $out ) );
		$check( '#1 no Otter resolver is on while a Contributor\'s preview renders', isset( $seen['minn-v046-plain'] ) && array() === $seen['minn-v046-plain'], wp_json_encode( $seen['minn-v046-plain'] ?? null ) );

		list( $status, $out ) = $render( array(
			'<!-- wp:paragraph --><p>minn-v046-rearm</p><!-- /wp:paragraph -->',
			'<!-- wp:paragraph --><p>minn-v046-after</p><!-- /wp:paragraph -->',
		) );
		$check( '#1 a block during which a resolver came back previews empty', 200 === $status && '' === trim( (string) ( $out[0] ?? 'x' ) ), $status . ' ' . wp_json_encode( $out ) );
		$check( '#1 the next block renders, with every resolver off again', false !== strpos( (string) ( $out[1] ?? '' ), 'minn-v046-after' ) && isset( $seen['minn-v046-after'] ) && array() === $seen['minn-v046-after'], wp_json_encode( $seen['minn-v046-after'] ?? null ) );
	} finally {
		wp_set_current_user( $admin );
	}
	$check( '#1 after the preview, Otter\'s resolvers are back as they were', $on() === $before, wp_json_encode( array_values( array_diff( $before, $on() ) ) ) . ' / ' . wp_json_encode( array_values( array_diff( $on(), $before ) ) ) );

	// Control: Otter trusts an administrator with dynamic data, so their
	// preview renders the element (it resolves through Otter, not here).
	$seen = array();
	list( $status, $out ) = $render( array( '<!-- wp:paragraph --><p class="minn-v046-plain"><o-dynamic data-type="postTitle" data-context="' . $id . '">Post Title</o-dynamic></p><!-- /wp:paragraph -->' ) );
	$check( "#1 control: an administrator's preview of an <o-dynamic> element renders", 200 === $status && '' !== trim( (string) ( $out[0] ?? '' ) ), $status . ' ' . wp_json_encode( $out ) );
	$check( "#1 control: Otter's resolvers stay on for an administrator's preview", isset( $seen['minn-v046-plain'] ) && $seen['minn-v046-plain'] === $before, wp_json_encode( $seen['minn-v046-plain'] ?? null ) );
} )();

/*
 * #4 (03-2): /core/network-upgrade and the dbUpgrade flag asked
 * upgrade_network of whichever site the request addressed. Core asks it on
 * the main site (network/admin.php moves the request there), and a role
 * plugin can put it in a subsite role.
 */
( static function () use ( $check, $skip, $make_user, $admin, &$undo ) {
	if ( ! is_multisite() || is_main_site() ) {
		$skip( '#4 needs a network, run from a site other than the main one' );
		return;
	}
	$here = get_current_blog_id();
	$main = get_main_site_id();
	$role = 'minn_v046_upgrader';
	foreach ( array( $here, $main ) as $blog ) {
		switch_to_blog( $blog );
		add_role( $role, 'v046 upgrader', array( 'read' => true, 'edit_posts' => true, 'upgrade_network' => true ) );
		restore_current_blog();
	}
	$undo[] = static function () use ( $here, $main, $role ) {
		foreach ( array( $here, $main ) as $blog ) {
			switch_to_blog( $blog );
			remove_role( $role );
			restore_current_blog();
		}
	};
	$sub_holder  = $make_user( 'subscriber', $here );
	$main_holder = $make_user( 'subscriber', $here );
	( new WP_User( $sub_holder ) )->set_role( $role );
	switch_to_blog( $main );
	add_user_to_blog( $main, $main_holder, $role );
	restore_current_blog();

	$routes = rest_get_server()->get_routes();
	$gate   = $routes['/minn-admin/v1/core/network-upgrade'][0]['permission_callback'] ?? null;
	$ask    = static function ( $uid ) use ( $gate, $admin ) {
		wp_set_current_user( $uid );
		try {
			return (bool) call_user_func( $gate, new WP_REST_Request( 'POST', '/minn-admin/v1/core/network-upgrade' ) );
		} finally {
			wp_set_current_user( $admin );
		}
	};
	$check( '#4 the route registers with a gate', is_callable( $gate ) );
	if ( ! is_callable( $gate ) ) {
		return;
	}
	$check( '#4 upgrade_network held only through this site\'s role does not open the network upgrade', ! $ask( $sub_holder ) );
	$check( '#4 control: upgrade_network held on the main site does, as core\'s screen admits it', $ask( $main_holder ) );
	$check( '#4 control: a super admin does', $ask( $admin ) );

	// The dbUpgrade flag: core's stamp says a walk is owed.
	$had = get_site_option( 'wpmu_upgrade_site', null );
	update_site_option( 'wpmu_upgrade_site', 1 );
	$undo[] = static function () use ( $had ) {
		null === $had ? delete_site_option( 'wpmu_upgrade_site' ) : update_site_option( 'wpmu_upgrade_site', $had );
	};
	// The version check answers from a fresh transient, offline.
	$fresh = static function () {
		return (object) array( 'updates' => array(), 'version_checked' => wp_get_wp_version(), 'last_checked' => time(), 'translations' => array() );
	};
	add_filter( 'pre_site_transient_update_core', $fresh );
	$flag = static function ( $uid ) use ( $admin ) {
		wp_set_current_user( $uid );
		try {
			$res = Minn_Admin_REST::core_status();
			$res = rest_ensure_response( $res )->get_data();
			return ! empty( $res['dbUpgrade'] );
		} finally {
			wp_set_current_user( $admin );
		}
	};
	$check( '#4 the dbUpgrade flag is not raised for a subsite-only upgrade_network holder', ! $flag( $sub_holder ) );
	$check( '#4 control: it is raised for a super admin', $flag( $admin ) );
	remove_filter( 'pre_site_transient_update_core', $fresh );
} )();

/*
 * #5 (03-3) and #11 (10-1): language removal protected the site language,
 * users' choices and other sites' WPLANG, read with the current network's
 * WPLANG standing in for every network's, and nothing a multilingual plugin
 * serves. Fake core catalogs (a .po and .mo pair, all core reads) stand in
 * for installed languages the lab does not use.
 */
( static function () use ( $check, $skip, &$undo ) {
	if ( ! current_user_can( 'update_languages' ) ) {
		$skip( '#5/#11 this account may not manage languages here' );
		return;
	}
	$locales = array( 'zz_ZZ', 'zy_ZY' );
	$planted = array();
	$plant   = static function () use ( $locales, &$planted ) {
		wp_mkdir_p( WP_LANG_DIR );
		foreach ( $locales as $loc ) {
			foreach ( array( '.po' => "msgid \"\"\nmsgstr \"\"\n\"Language: {$loc}\\n\"\n\"PO-Revision-Date: 2026-01-01 00:00+0000\\n\"\n", '.mo' => '' ) as $ext => $body ) {
				$file = WP_LANG_DIR . '/' . $loc . $ext;
				if ( ! is_file( $file ) ) {
					file_put_contents( $file, $body );
				}
				$planted[ $file ] = true;
			}
		}
		wp_cache_delete( md5( trailingslashit( WP_LANG_DIR ) ), 'translation_files' );
	};
	$undo[] = static function () use ( &$planted ) {
		foreach ( array_keys( $planted ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		wp_cache_delete( md5( trailingslashit( WP_LANG_DIR ) ), 'translation_files' );
	};
	foreach ( $locales as $loc ) {
		if ( in_array( $loc, get_available_languages(), true ) ) {
			$skip( "#5/#11 {$loc} is really installed here" );
			return;
		}
	}
	$plant();
	$row    = static function ( $loc ) {
		wp_cache_delete( md5( trailingslashit( WP_LANG_DIR ) ), 'translation_files' );
		foreach ( Minn_Admin_REST::installed_languages() as $lang ) {
			if ( $lang['locale'] === $loc ) {
				return $lang;
			}
		}
		return null;
	};
	$remove = static function ( $loc ) use ( $plant ) {
		$req = new WP_REST_Request( 'POST', '/minn-admin/v1/translations/remove' );
		$req->set_param( 'locale', $loc );
		$out = Minn_Admin_REST::remove_language( $req );
		$code = is_wp_error( $out ) ? $out->get_error_code() : 'removed';
		$plant(); // A wrongful removal deleted only these fakes; put them back.
		return $code;
	};
	$kept   = static function ( $r, $reason, $plugin = null ) {
		return $r && ! $r['removable'] && $reason === $r['reason'] && ( null === $plugin || ( $r['plugin'] ?? null ) === $plugin );
	};

	$r = $row( 'zz_ZZ' );
	$check( '#11 control: a language nothing uses is offered for removal', $r && $r['removable'], wp_json_encode( $r ) );

	// A multilingual plugin this site runs: TranslatePress is known by its
	// folder in active_plugins and read from its own settings.
	$active = static function ( $plugins ) use ( &$undo ) {
		$f = static function ( $list ) use ( $plugins ) {
			return array_values( array_unique( array_merge( (array) $list, $plugins ) ) );
		};
		add_filter( 'option_active_plugins', $f );
		$undo[] = static function () use ( $f ) {
			remove_filter( 'option_active_plugins', $f );
		};
		return $f;
	};
	$trp      = $active( array( 'translatepress-multilingual/index.php' ) );
	$settings = static function () {
		return array( 'default-language' => 'en_US', 'translation-languages' => array( 'en_US', 'zz_ZZ' ) );
	};
	add_filter( 'pre_option_trp_settings', $settings );
	$r = $row( 'zz_ZZ' );
	$check( '#11 a language TranslatePress serves visitors is kept, and says so', $kept( $r, 'plugin', 'TranslatePress' ), wp_json_encode( $r ) );
	$check( '#11 and its removal is refused', 'minn_admin_locale_plugin' === $remove( 'zz_ZZ' ) );
	$r = $row( 'zy_ZY' );
	$check( '#11 control: a language TranslatePress does not serve is still offered', $r && $r['removable'], wp_json_encode( $r ) );
	remove_filter( 'pre_option_trp_settings', $settings );

	// Its settings cannot be read: every language is kept.
	$r = $row( 'zy_ZY' );
	$check( '#11 a multilingual plugin whose languages cannot be read keeps every language', $kept( $r, 'multilingual', 'TranslatePress' ), wp_json_encode( $r ) );
	$check( '#11 and removal is refused', 'minn_admin_locale_multilingual' === $remove( 'zy_ZY' ) );
	remove_filter( 'option_active_plugins', $trp );

	// Polylang listed as active with its API missing: the same.
	$pll = $active( array( 'polylang/polylang.php' ) );
	$r   = $row( 'zz_ZZ' );
	$check( '#11 Polylang active without its API keeps every language', $kept( $r, 'multilingual', 'Polylang' ), wp_json_encode( $r ) );
	remove_filter( 'option_active_plugins', $pll );
	$r = $row( 'zz_ZZ' );
	$check( '#11 control: with no multilingual plugin the language is offered again', $r && $r['removable'], wp_json_encode( $r ) );

	if ( ! is_multisite() ) {
		$skip( '#5 and the network halves of #11 run on a network' );
		return;
	}

	// #5: a site on another network with no WPLANG row of its own runs in
	// ITS network's language. The site is a stand-in (no tables, so its
	// options read as missing), on a network that exists only here.
	$other_net = 990000 + wp_rand( 1, 9999 );
	$ghost     = new WP_Site( (object) array( 'blog_id' => 990000 + wp_rand( 1, 9999 ), 'site_id' => $other_net, 'domain' => 'minn-v046.invalid', 'path' => '/' ) );
	$add_ghost = static function ( $sites ) use ( $ghost ) {
		$sites[] = $ghost;
		return $sites;
	};
	$net_lang = static function ( $pre, $option, $network_id ) use ( $other_net ) {
		return (int) $network_id === $other_net ? 'zz_ZZ' : $pre;
	};
	add_filter( 'the_sites', $add_ghost );
	add_filter( 'pre_site_option_WPLANG', $net_lang, 10, 3 );
	global $wpdb;
	$quiet = $wpdb->suppress_errors( true );
	$r     = $row( 'zz_ZZ' );
	$wpdb->suppress_errors( $quiet );
	remove_filter( 'the_sites', $add_ghost );
	remove_filter( 'pre_site_option_WPLANG', $net_lang, 10 );
	$check( "#5 a row-less site on another network keeps that network's language", $kept( $r, 'sites' ), wp_json_encode( $r ) );

	// #11 on a network: a multilingual plugin another site runs, whose list
	// this site cannot read, keeps every language.
	$other = 0;
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
		if ( (int) $id !== get_current_blog_id() && ! is_main_site( $id ) ) {
			$other = (int) $id;
			break;
		}
	}
	if ( ! $other ) {
		$skip( '#11 needs a third site' );
		return;
	}
	$had    = get_blog_option( $other, 'active_plugins', array() );
	$undo[] = static function () use ( $other, $had ) {
		update_blog_option( $other, 'active_plugins', $had );
	};
	update_blog_option( $other, 'active_plugins', array_merge( (array) $had, array( 'polylang/polylang.php' ) ) );
	$r = $row( 'zz_ZZ' );
	update_blog_option( $other, 'active_plugins', $had );
	$check( '#11 Polylang on another site of the network keeps every language here', $kept( $r, 'multilingual', 'Polylang' ), wp_json_encode( $r ) );

	// Network-active: every other site of the network runs it with its own
	// list, even where this site's own list is readable.
	$sitewide = static function () {
		return array( 'translatepress-multilingual/index.php' => time() );
	};
	add_filter( 'pre_site_option_active_sitewide_plugins', $sitewide );
	add_filter( 'pre_option_trp_settings', $settings );
	$r = $row( 'zy_ZY' );
	remove_filter( 'pre_option_trp_settings', $settings );
	remove_filter( 'pre_site_option_active_sitewide_plugins', $sitewide );
	$check( '#11 a network-active multilingual plugin keeps every language', $kept( $r, 'multilingual', 'TranslatePress' ), wp_json_encode( $r ) );
	$r = $row( 'zy_ZY' );
	$check( '#11 control: with it gone the language is offered again', $r && $r['removable'], wp_json_encode( $r ) );
} )();

/*
 * #10 (09-2): "Move everyone to the site default" reset the network-wide
 * palette choice of every member of the site, super admins included. It now
 * resets only accounts the caller may edit_user, and counts only those.
 */
( static function () use ( $check, $skip, $call, $make_user, $admin, &$undo ) {
	$meta  = Minn_Admin::APPEARANCE_META;
	$keep  = static function ( $uid ) use ( $meta, &$undo ) {
		$had    = get_user_meta( $uid, $meta, true );
		$undo[] = static function () use ( $uid, $meta, $had ) {
			'' === $had ? delete_user_meta( $uid, $meta ) : update_user_meta( $uid, $meta, $had );
		};
	};
	$pick  = static function ( $uid ) {
		$ap           = Minn_Admin::get_user_appearance( $uid );
		$ap['scheme'] = 'minn';
		Minn_Admin::save_user_appearance( $uid, $ap );
	};
	$scheme = static function ( $uid ) {
		wp_cache_delete( $uid, 'user_meta' );
		return Minn_Admin::get_user_appearance( $uid )['scheme'];
	};
	$site_had = get_option( Minn_Admin::SITE_APPEARANCE_OPTION, null );
	$undo[]   = static function () use ( $site_had ) {
		null === $site_had ? delete_option( Minn_Admin::SITE_APPEARANCE_OPTION ) : update_option( Minn_Admin::SITE_APPEARANCE_OPTION, $site_had );
	};

	if ( ! is_multisite() ) {
		// Control: on a single site an administrator may edit everyone.
		$editor = $make_user( 'editor' );
		$keep( $editor );
		$pick( $editor );
		list( $status ) = $call( 'POST', '/minn-admin/v1/site-appearance', array( 'resetUsers' => true ) );
		$check( '#10 control: on a single site the reset still moves an editor to the site default', 200 === $status && 'site' === $scheme( $editor ), $status . ' ' . $scheme( $editor ) );
		return;
	}
	if ( is_main_site() || ! is_super_admin() ) {
		$skip( '#10 run on a site other than the main one, as a super admin' );
		return;
	}
	$here     = get_current_blog_id();
	$sub      = $make_user( 'administrator', $here );
	$member   = $make_user( 'editor', $here );
	$was_mem  = is_user_member_of_blog( $admin, $here );
	if ( ! $was_mem ) {
		add_user_to_blog( $here, $admin, 'administrator' );
		$undo[] = static function () use ( $here, $admin ) {
			remove_user_from_blog( $admin, $here );
		};
	}
	foreach ( array( $admin, $sub, $member ) as $uid ) {
		$keep( $uid );
		$pick( $uid );
	}
	wp_set_current_user( $sub );
	try {
		list( , $data ) = $call( 'GET', '/minn-admin/v1/site-appearance' );
		$check( '#10 the count names only the accounts this administrator may reset (their own)', is_array( $data ) && 1 === (int) ( $data['ownPalette'] ?? -1 ), wp_json_encode( $data['ownPalette'] ?? null ) );
		list( $status ) = $call( 'POST', '/minn-admin/v1/site-appearance', array( 'resetUsers' => true ) );
	} finally {
		wp_set_current_user( $admin );
	}
	$check( '#10 the reset answers', 200 === $status, (string) $status );
	$check( "#10 a super admin's own palette survives a site administrator's reset", 'minn' === $scheme( $admin ), $scheme( $admin ) );
	$check( "#10 another member's palette, which this administrator may not edit, survives", 'minn' === $scheme( $member ), $scheme( $member ) );
	$check( '#10 control: the administrator\'s own palette is reset', 'site' === $scheme( $sub ), $scheme( $sub ) );
} )();

/*
 * #12 (11-1, 00-2): the wp-config switch judged "custom" over the whole file
 * and rewrote the first define, so a path-valued WP_DEBUG_LOG next to a
 * literal one (a commented-out line, an environment branch) was flattened,
 * or the comment was edited and success reported. Only defines PHP runs
 * count, the kind is that of the define the write replaces, and a constant
 * defined twice is locked.
 */
( static function () use ( $check ) {
	$php   = "<?php\n";
	$cases = array(
		array( 'one literal true', "define( 'WP_DEBUG_LOG', true );", 'bool' ),
		array( 'a path', "define( 'WP_DEBUG_LOG', '/srv/private/wp-debug.log' );", 'custom' ),
		array( 'nothing', '// nothing here', 'absent' ),
		array( 'a path, then a commented-out literal', "define( 'WP_DEBUG_LOG', '/home/site/logs/debug.log' );\n// define( 'WP_DEBUG_LOG', true );", 'custom' ),
		array( 'a commented-out literal, then a path', "/* define( 'WP_DEBUG_LOG', true ); */\ndefine( 'WP_DEBUG_LOG', '/srv/x.log' );", 'custom' ),
		array( 'an environment branch', "if ( 'production' === getenv( 'WP_ENV' ) ) { define( 'WP_DEBUG_LOG', '/var/log/site/debug.log' ); } else { define( 'WP_DEBUG_LOG', true ); }", 'multiple' ),
		array( 'two literals', "define( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_LOG', false );", 'multiple' ),
		array( 'only a commented-out literal', "# define( 'WP_DEBUG_LOG', true );", 'absent' ),
		array( 'a value with a parenthesis', "define( 'WP_DEBUG_LOG', dirname( __FILE__ ) . '/x.log' );", 'custom' ),
		array( 'a define inside a string', "\$s = \"define( 'WP_DEBUG_LOG', true );\";", 'custom' ),
		array( 'a literal next to another constant', "define( 'WP_DEBUG', '1' === getenv( 'D' ) );\ndefine( 'WP_DEBUG_LOG', true );", 'bool' ),
		array( "a define above WP-CLI's ! defined() fallback", "define( 'WP_DEBUG_LOG', true );\nif ( ! defined( 'WP_DEBUG_LOG' ) ) {\n\tdefine( 'WP_DEBUG_LOG', false );\n}", 'bool' ),
		array( 'that fallback alone', "if ( ! defined( 'WP_DEBUG_LOG' ) ) {\n\tdefine( 'WP_DEBUG_LOG', false );\n}", 'bool' ),
		array( 'a fallback ahead of a define (the fallback wins)', "if ( ! defined( 'WP_DEBUG_LOG' ) ) { define( 'WP_DEBUG_LOG', '/srv/p.log' ); }\ndefine( 'WP_DEBUG_LOG', true );", 'multiple' ),
		array( 'a path above a fallback literal', "define( 'WP_DEBUG_LOG', '/srv/p.log' );\nif ( ! defined( 'WP_DEBUG_LOG' ) ) { define( 'WP_DEBUG_LOG', true ); }", 'custom' ),
	);
	$bad = array();
	foreach ( $cases as $c ) {
		$kind = Minn_Admin_REST::config_define_kind( 'WP_DEBUG_LOG', $php . $c[1] . "\n" );
		if ( $kind !== $c[2] ) {
			$bad[] = "{$c[0]}: {$kind}, want {$c[2]}";
		}
	}
	$check( '#12 only one literal define PHP runs is a switch', ! $bad, implode( '; ', $bad ) );

	$has = method_exists( 'Minn_Admin_REST', 'config_rewrite' );
	$check( '#12 the write is judged by the same reading (config_rewrite)', $has );
	if ( ! $has ) {
		return;
	}
	$marker = "/* That's all, stop editing! Happy publishing. */\n";
	$src    = $php . "// define( 'WP_DEBUG_LOG', '/srv/old.log' );\ndefine( 'WP_DEBUG_LOG', true );\n" . $marker;
	$new    = Minn_Admin_REST::config_rewrite( 'WP_DEBUG_LOG', false, $src );
	$check( '#12 the switch rewrites the define PHP runs and leaves the comment', is_string( $new )
		&& false !== strpos( $new, "// define( 'WP_DEBUG_LOG', '/srv/old.log' );" )
		&& false !== strpos( $new, "\ndefine( 'WP_DEBUG_LOG', false );" ), is_string( $new ) ? $new : wp_json_encode( $new ) );
	$src = $php . "define( 'WP_DEBUG_LOG', true );\nif ( ! defined( 'WP_DEBUG_LOG' ) ) {\n\tdefine( 'WP_DEBUG_LOG', false );\n}\n" . $marker;
	$new = Minn_Admin_REST::config_rewrite( 'WP_DEBUG_LOG', false, $src );
	$check( '#12 control: next to a fallback, the switch still rewrites the define that holds', is_string( $new )
		&& 0 === strpos( $new, $php . "define( 'WP_DEBUG_LOG', false );\nif ( ! defined( 'WP_DEBUG_LOG' ) ) {\n\tdefine( 'WP_DEBUG_LOG', false );" ), is_string( $new ) ? $new : wp_json_encode( $new ) );
	$e = Minn_Admin_REST::config_rewrite( 'WP_DEBUG_LOG', true, $php . $cases[5][1] . "\n" );
	$check( '#12 a constant defined twice is refused, both defines untouched', is_wp_error( $e ) && 'defined_twice' === $e->get_error_code(), is_wp_error( $e ) ? $e->get_error_code() : 'written' );
	$e = Minn_Admin_REST::config_rewrite( 'WP_DEBUG_LOG', true, $php . $cases[3][1] . "\n" );
	$check( '#12 a path next to a commented-out literal is refused', is_wp_error( $e ) && 'custom_value' === $e->get_error_code(), is_wp_error( $e ) ? $e->get_error_code() : 'written' );
	$new = Minn_Admin_REST::config_rewrite( 'SAVEQUERIES', true, $php . "// define( 'SAVEQUERIES', false );\n" . $marker );
	$check( '#12 control: a constant only a comment names gets a real define, the comment kept', defined( 'SAVEQUERIES' ) || ( is_string( $new )
		&& 1 === substr_count( $new, "define( 'SAVEQUERIES', true );" ) && false !== strpos( $new, "// define( 'SAVEQUERIES', false );" ) ), is_string( $new ) ? $new : wp_json_encode( $new ) );
} )();

$cleanup();
printf( "\nsecurity-v046-registry-rest: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( in_array( false, $results, true ) ? 1 : 0 );
