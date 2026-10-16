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
// Code outside WordPress and Minn, for the checks that need some: a class
// named like an Otter resolver, and a callback for the locale filter.
$probe_file = trailingslashit( sys_get_temp_dir() ) . 'minn-v046-probe-' . wp_rand( 10000, 99999 ) . '.php';
file_put_contents( $probe_file, "<?php\nnamespace ThemeIsle\\MinnV046Probe;\nclass Dynamic_Probe {\n\tpublic function cb( \$content, \$block = null, \$instance = null ) {\n\t\treturn \$content;\n\t}\n}\nfunction keep_locale( \$locale ) {\n\treturn \$locale;\n}\n" );
require $probe_file;
$undo[] = static function () use ( $probe_file ) {
	if ( is_file( $probe_file ) ) {
		unlink( $probe_file );
	}
};
// Round four: Complianz's locale callback by name, a class that says when
// it is unserialized, and a plugin folder that is a symlink.
$probe_global = trailingslashit( sys_get_temp_dir() ) . 'minn-v046-probe-g-' . wp_rand( 10000, 99999 ) . '.php';
file_put_contents( $probe_global, "<?php\nif ( ! function_exists( 'cmplz_set_plugin_language' ) ) {\n\tfunction cmplz_set_plugin_language( \$locale ) {\n\t\treturn \$locale;\n\t}\n}\nclass Minn_V046_Wakeup_Probe {\n\tpublic static \$woke = 0;\n\tpublic function __wakeup() {\n\t\t++self::\$woke;\n\t}\n}\n" );
require $probe_global;
$link_name   = 'minn-v046-link-' . wp_rand( 10000, 99999 );
$link_target = trailingslashit( sys_get_temp_dir() ) . $link_name . '-target';
wp_mkdir_p( $link_target );
file_put_contents( $link_target . '/link-probe.php', "<?php\n/**\n * Plugin Name: Minn v046 Link Probe\n */\nfunction minn_v046_link_locale( \$locale ) {\n\treturn \$locale;\n}\n" );
$linked = @symlink( $link_target, WP_PLUGIN_DIR . '/' . $link_name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
if ( $linked ) {
	require WP_PLUGIN_DIR . '/' . $link_name . '/link-probe.php';
}
$undo[] = static function () use ( $probe_global, $link_name, $link_target ) {
	if ( is_link( WP_PLUGIN_DIR . '/' . $link_name ) ) {
		unlink( WP_PLUGIN_DIR . '/' . $link_name );
	}
	foreach ( array( $probe_global, $link_target . '/link-probe.php' ) as $f ) {
		if ( is_file( $f ) ) {
			unlink( $f );
		}
	}
	if ( is_dir( $link_target ) ) {
		rmdir( $link_target );
	}
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

	// Round-two I5: a block that throws mid-render still gets the resolvers
	// and the preview clamps back, each resolver with its own accepted args
	// (the probe resolver takes three).
	$probe = new \ThemeIsle\MinnV046Probe\Dynamic_Probe();
	add_filter( 'render_block', array( $probe, 'cb' ), 10, 3 );
	$undo[]  = static function () use ( $probe ) {
		remove_filter( 'render_block', array( $probe, 'cb' ), 10 );
	};
	$args_of = static function () use ( $probe ) {
		global $wp_filter;
		foreach ( $wp_filter['render_block']->callbacks as $prio => $cbs ) {
			foreach ( $cbs as $cb ) {
				if ( is_array( $cb['function'] ) && $cb['function'][0] === $probe ) {
					return $prio . '/' . $cb['accepted_args'];
				}
			}
		}
		return 'gone';
	};
	$count   = static function ( $hook ) {
		global $wp_filter;
		return isset( $wp_filter[ $hook ] ) ? array_sum( array_map( 'count', $wp_filter[ $hook ]->callbacks ) ) : 0;
	};
	$thrower = static function ( $content ) {
		if ( false !== strpos( (string) $content, 'minn-v046-throw' ) ) {
			throw new RuntimeException( 'minn-v046 probe throw' );
		}
		return $content;
	};
	add_filter( 'render_block', $thrower, 5 );
	$before_on = $on();
	$hooks_n   = array( $count( 'render_block_data' ), $count( 'pre_get_posts' ) );
	$thrown    = false;
	wp_set_current_user( $contrib );
	try {
		$render( array( '<!-- wp:paragraph --><p>minn-v046-throw</p><!-- /wp:paragraph -->' ) );
	} catch ( \Throwable $e ) {
		$thrown = true;
	} finally {
		wp_set_current_user( $admin );
		remove_filter( 'render_block', $thrower, 5 );
	}
	$check( '#1 (I5) fixture: the block threw', $thrown );
	$check( "#1 (I5) after a block throws, Otter's resolvers are back", $on() === $before_on, wp_json_encode( array_values( array_diff( $before_on, $on() ) ) ) );
	$check( '#1 (I5) and the preview clamps are off again', array( $count( 'render_block_data' ), $count( 'pre_get_posts' ) ) === $hooks_n, wp_json_encode( array( $hooks_n, array( $count( 'render_block_data' ), $count( 'pre_get_posts' ) ) ) ) );
	wp_set_current_user( $contrib );
	try {
		$render( array( '<!-- wp:paragraph --><p>minn-v046-plain</p><!-- /wp:paragraph -->' ) );
	} finally {
		wp_set_current_user( $admin );
	}
	$check( '#1 (I5) a resolver comes back with its own accepted args', '10/3' === $args_of(), $args_of() );
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
( static function () use ( $check, $skip, $probe_file, $probe_global, $linked, &$undo ) {
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

	// Round-two L4: a multilingual plugin Minn has no list for.
	$glob = $active( array( 'wpglobus/wpglobus.php' ) );
	$r    = $row( 'zz_ZZ' );
	remove_filter( 'option_active_plugins', $glob );
	$check( '#11 (L4) WPGlobus active keeps every language', $kept( $r, 'multilingual', 'WPGlobus' ), wp_json_encode( $r ) );

	// Anything outside WordPress and Minn on the locale filter is how such a
	// plugin switches languages; it keeps every language, named by its file.
	// Round four R1: code that is not a known multilingual plugin keeps
	// nothing (Remove stays a write); the row names it for the page.
	add_filter( 'locale', 'ThemeIsle\\MinnV046Probe\\keep_locale' );
	$r = $row( 'zz_ZZ' );
	remove_filter( 'locale', 'ThemeIsle\\MinnV046Probe\\keep_locale' );
	$check( '#11 (R1) other code on the locale filter keeps nothing, and the row names it', $r && $r['removable'] && in_array( basename( $probe_file ), (array) ( $r['switchers'] ?? array() ), true ), wp_json_encode( $r ) );
	add_filter( 'locale', 'cmplz_set_plugin_language', 19 );
	$r = $row( 'zz_ZZ' );
	remove_filter( 'locale', 'cmplz_set_plugin_language', 19 );
	$check( "#11 (R1) Complianz's locale callback, which switches no visitor, keeps nothing and is not named", $r && $r['removable'] && ! in_array( basename( $probe_global ), (array) ( $r['switchers'] ?? array() ), true ), wp_json_encode( $r ) );
	if ( $linked ) {
		add_filter( 'locale', 'minn_v046_link_locale' );
		$r = $row( 'zz_ZZ' );
		remove_filter( 'locale', 'minn_v046_link_locale' );
		$check( '#11 (R2) a plugin in a symlinked folder keeps nothing and is named by its header', $r && $r['removable'] && in_array( 'Minn v046 Link Probe', (array) ( $r['switchers'] ?? array() ), true ), wp_json_encode( $r ) );
	} else {
		$skip( '#11 (R2) cannot make a symlinked plugin folder here' );
	}
	// Control: Minn hooks determine_locale itself (its own UI language),
	// and that keeps nothing.
	global $wp_filter;
	$minn_hooked = false;
	foreach ( isset( $wp_filter['determine_locale'] ) ? $wp_filter['determine_locale']->callbacks : array() as $cbs ) {
		foreach ( $cbs as $cb ) {
			$fn = $cb['function'];
			try {
				$ref = is_array( $fn ) ? new ReflectionMethod( $fn[0], $fn[1] ) : ( is_string( $fn ) && false !== strpos( $fn, '::' ) ? new ReflectionMethod( $fn ) : new ReflectionFunction( $fn ) );
				$minn_hooked = $minn_hooked || false !== strpos( (string) $ref->getFileName(), 'minn-admin/includes/' );
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	}
	$r = $row( 'zz_ZZ' );
	$check( "#11 (L4) control: Minn's own callback on determine_locale keeps nothing", $minn_hooked && $r && $r['removable'], ( $minn_hooked ? '' : 'Minn is not hooked; ' ) . wp_json_encode( $r ) );

	// WPML's hidden languages are active rows of its own table, which its
	// API leaves out; the table is read.
	global $wpdb;
	$icl = $wpdb->prefix . 'icl_languages';
	if ( $icl === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $icl ) ) ) {
		$skip( '#11 (L4) WPML is really installed here' );
	} else {
		// Round four R3: WPML active with no table of its own serves nothing.
		$wpml = $active( array( 'sitepress-multilingual-cms/sitepress.php' ) );
		$r    = $row( 'zz_ZZ' );
		remove_filter( 'option_active_plugins', $wpml );
		$check( '#11 (R3) WPML with no languages table keeps nothing', $r && $r['removable'], wp_json_encode( $r ) );
		$wpdb->query( "CREATE TABLE `{$icl}` ( id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(7), english_name VARCHAR(128), major TINYINT DEFAULT 0, active TINYINT DEFAULT 0, default_locale VARCHAR(35), tag VARCHAR(35), encode_url TINYINT DEFAULT 0 )" );
		$undo[] = static function () use ( $icl ) {
			$GLOBALS['wpdb']->query( "DROP TABLE IF EXISTS `{$icl}`" );
		};
		$wpdb->insert( $icl, array( 'code' => 'en', 'english_name' => 'English', 'active' => 1, 'default_locale' => 'en_US' ) );
		$wpdb->insert( $icl, array( 'code' => 'zz', 'english_name' => 'Hidden probe', 'active' => 1, 'default_locale' => 'zz_ZZ' ) );
		$wpdb->insert( $icl, array( 'code' => 'zy', 'english_name' => 'Inactive probe', 'active' => 0, 'default_locale' => 'zy_ZY' ) );
		$wpml = $active( array( 'sitepress-multilingual-cms/sitepress.php' ) );
		$r    = $row( 'zz_ZZ' );
		$r2   = $row( 'zy_ZY' );
		remove_filter( 'option_active_plugins', $wpml );
		$check( "#11 (L4) a language WPML keeps (hidden ones included) is kept as WPML's", $kept( $r, 'plugin', 'WPML' ), wp_json_encode( $r ) );
		$check( '#11 (L4) control: a language WPML has switched off is still offered', $r2 && $r2['removable'], wp_json_encode( $r2 ) );
	}

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
	update_blog_option( $other, 'active_plugins', array_merge( (array) $had, array( 'polylang/polylang.php', 'sitepress-multilingual-cms/sitepress.php' ) ) );
	$r = $row( 'zz_ZZ' );
	update_blog_option( $other, 'active_plugins', array_merge( (array) $had, array( 'polylang/polylang.php' ) ) );
	$check( '#11 (R3) Polylang and WPML on another site with no languages there keep nothing', $r && $r['removable'], wp_json_encode( $r ) );

	// A language term that cannot be read keeps every language; one holding
	// an object never builds it (R6).
	switch_to_blog( $other );
	$terms = $wpdb->terms;
	$tts   = $wpdb->term_taxonomy;
	$made_terms = array();
	foreach ( array( serialize( new Minn_V046_Wakeup_Probe() ), 'not a stored array' ) as $k => $desc ) {
		$wpdb->insert( $terms, array( 'name' => 'v046 bad ' . $k, 'slug' => 'minn-v046-bad-' . $k, 'term_group' => 0 ) );
		$tid = (int) $wpdb->insert_id;
		$wpdb->insert( $tts, array( 'term_id' => $tid, 'taxonomy' => 'language', 'description' => $desc, 'parent' => 0, 'count' => 0 ) );
		$made_terms[] = array( $tid, (int) $wpdb->insert_id );
	}
	restore_current_blog();
	$drop_terms = static function () use ( $terms, $tts, &$made_terms ) {
		foreach ( $made_terms as $pair ) {
			$GLOBALS['wpdb']->delete( $tts, array( 'term_taxonomy_id' => $pair[1] ) );
			$GLOBALS['wpdb']->delete( $terms, array( 'term_id' => $pair[0] ) );
		}
		$made_terms = array();
	};
	$undo[] = $drop_terms;
	Minn_V046_Wakeup_Probe::$woke = 0;
	$r = $row( 'zz_ZZ' );
	$check( '#11 Polylang on another site, a language term there unreadable, keeps every language here', $kept( $r, 'multilingual', 'Polylang' ), wp_json_encode( $r ) );
	$check( "#11 (R6) another site's term description is read without building its object", 0 === Minn_V046_Wakeup_Probe::$woke, (string) Minn_V046_Wakeup_Probe::$woke );
	$drop_terms();

	// Round-two I6: where that site's own list can be read, it is, and every
	// other language stays removable. Polylang keeps each language as a
	// 'language' term whose description holds its locale.
	switch_to_blog( $other );
	$wpdb->insert( $wpdb->terms, array( 'name' => 'Zz probe', 'slug' => 'minn-v046-zz', 'term_group' => 0 ) );
	$term_id = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $term_id, 'taxonomy' => 'language', 'description' => serialize( array( 'locale' => 'zz_ZZ', 'rtl' => 0, 'flag_code' => 'zz' ) ), 'parent' => 0, 'count' => 0 ) );
	$tt_id  = (int) $wpdb->insert_id;
	$terms  = $wpdb->terms;
	$tts    = $wpdb->term_taxonomy;
	restore_current_blog();
	$undo[] = static function () use ( $terms, $tts, $term_id, $tt_id ) {
		$GLOBALS['wpdb']->delete( $tts, array( 'term_taxonomy_id' => $tt_id ) );
		$GLOBALS['wpdb']->delete( $terms, array( 'term_id' => $term_id ) );
	};
	$r  = $row( 'zz_ZZ' );
	$r2 = $row( 'zy_ZY' );
	update_blog_option( $other, 'active_plugins', $had );
	$check( "#11 (I6) a language Polylang serves on another site is kept, as Polylang's", $kept( $r, 'plugin', 'Polylang' ), wp_json_encode( $r ) );
	$check( '#11 (I6) and a language it does not serve there is still offered', $r2 && $r2['removable'], wp_json_encode( $r2 ) );
	$wpdb->delete( $tts, array( 'term_taxonomy_id' => $tt_id ) );
	$wpdb->delete( $terms, array( 'term_id' => $term_id ) );

	// TranslatePress on another site, its settings read from that site.
	$trp_had = get_blog_option( $other, 'trp_settings', null );
	$undo[]  = static function () use ( $other, $trp_had ) {
		null === $trp_had ? delete_blog_option( $other, 'trp_settings' ) : update_blog_option( $other, 'trp_settings', $trp_had );
	};
	update_blog_option( $other, 'trp_settings', array( 'default-language' => 'en_US', 'translation-languages' => array( 'en_US', 'zz_ZZ' ) ) );
	update_blog_option( $other, 'active_plugins', array_merge( (array) $had, array( 'translatepress-multilingual/index.php' ) ) );
	$r  = $row( 'zz_ZZ' );
	$r2 = $row( 'zy_ZY' );
	update_blog_option( $other, 'active_plugins', $had );
	null === $trp_had ? delete_blog_option( $other, 'trp_settings' ) : update_blog_option( $other, 'trp_settings', $trp_had );
	$check( "#11 (I6) a language TranslatePress serves on another site is kept, as TranslatePress's", $kept( $r, 'plugin', 'TranslatePress' ), wp_json_encode( $r ) );
	$check( '#11 (I6) and a language it does not serve there is still offered', $r2 && $r2['removable'], wp_json_encode( $r2 ) );

	// Round four R3: a must-use file counts only by its plugin header, not
	// for naming a plugin in its text. A language term on the other site
	// shows whether Polylang was counted as running there.
	if ( defined( 'WPMU_PLUGIN_DIR' ) && wp_is_writable( WPMU_PLUGIN_DIR ) ) {
		switch_to_blog( $other );
		$wpdb->insert( $wpdb->terms, array( 'name' => 'Zz mu probe', 'slug' => 'minn-v046-zz-mu', 'term_group' => 0 ) );
		$mu_term = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->term_taxonomy, array( 'term_id' => $mu_term, 'taxonomy' => 'language', 'description' => serialize( array( 'locale' => 'zz_ZZ' ) ), 'parent' => 0, 'count' => 0 ) );
		$mu_tt = (int) $wpdb->insert_id;
		$t_terms = $wpdb->terms;
		$t_tts   = $wpdb->term_taxonomy;
		restore_current_blog();
		$mu_file = WPMU_PLUGIN_DIR . '/minn-v046-mu-' . wp_rand( 10000, 99999 ) . '.php';
		$undo[]  = static function () use ( $mu_file, $t_terms, $t_tts, $mu_term, $mu_tt ) {
			if ( is_file( $mu_file ) ) {
				unlink( $mu_file );
			}
			$GLOBALS['wpdb']->delete( $t_tts, array( 'term_taxonomy_id' => $mu_tt ) );
			$GLOBALS['wpdb']->delete( $t_terms, array( 'term_id' => $mu_term ) );
		};
		file_put_contents( $mu_file, "<?php\n// Speeds up polylang and sitepress-multilingual-cms admin requests.\n" );
		$r = $row( 'zz_ZZ' );
		$check( '#11 (R3) a must-use file that only names Polylang counts for nothing', $r && $r['removable'], wp_json_encode( $r ) );
		file_put_contents( $mu_file, "<?php\n/**\n * Plugin Name: Polylang Loader\n */\n" );
		$r = $row( 'zz_ZZ' );
		$check( "#11 (R3) a must-use Polylang (by its header) runs on every site: the other site's language is kept as Polylang's", $kept( $r, 'plugin', 'Polylang' ), wp_json_encode( $r ) );
		unlink( $mu_file );
		$wpdb->delete( $t_tts, array( 'term_taxonomy_id' => $mu_tt ) );
		$wpdb->delete( $t_terms, array( 'term_id' => $mu_term ) );
	} else {
		$skip( '#11 (R3) mu-plugins is not writable here' );
	}

	// Round four R7: one removal walks the network once.
	$walks = 0;
	$count = static function ( $sites ) use ( &$walks ) {
		++$walks;
		return $sites;
	};
	add_filter( 'the_sites', $count );
	$code = $remove( 'zy_ZY' );
	remove_filter( 'the_sites', $count );
	$check( '#11 (R7) a removal walks the network once', 'removed' === $code && 1 === $walks, $code . ' / ' . $walks . ' walks' );

	// Network-active: every other site of the network runs it with its own
	// list, even where this site's own list is readable.
	$sitewide = static function () {
		return array( 'translatepress-multilingual/index.php' => time() );
	};
	add_filter( 'pre_site_option_active_sitewide_plugins', $sitewide );
	add_filter( 'pre_option_trp_settings', $settings );
	$r = $row( 'zy_ZY' );
	// Round four R3: no settings on the other sites is no languages there;
	// settings that cannot be read keep every language.
	$check( '#11 (R3) a network-active multilingual plugin with no settings on the other sites keeps nothing', $r && $r['removable'], wp_json_encode( $r ) );
	// (Written with this site's settings filter off: update_option reads
	// the old value first, and would see the filtered one.)
	remove_filter( 'pre_option_trp_settings', $settings );
	update_blog_option( $other, 'trp_settings', 'not settings' );
	add_filter( 'pre_option_trp_settings', $settings );
	$r = $row( 'zy_ZY' );
	null === $trp_had ? delete_blog_option( $other, 'trp_settings' ) : update_blog_option( $other, 'trp_settings', $trp_had );
	remove_filter( 'pre_option_trp_settings', $settings );
	remove_filter( 'pre_site_option_active_sitewide_plugins', $sitewide );
	$check( '#11 a network-active multilingual plugin whose list on another site cannot be read keeps every language', $kept( $r, 'multilingual', 'TranslatePress' ), wp_json_encode( $r ) );
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

	// Round-two L7: a lone literal is a switch only when it is the value
	// WordPress runs with. WP_DEBUG is defined in this process.
	$has_switch = method_exists( 'Minn_Admin_REST', 'config_switch' );
	$check( '#12 (L7) each switch is judged against the running value (config_switch)', $has_switch );
	if ( $has_switch ) {
		$now  = defined( 'WP_DEBUG' ) && WP_DEBUG ? 'true' : 'false';
		$flip = 'true' === $now ? 'false' : 'true';
		$live = Minn_Admin_REST::config_switch( 'WP_DEBUG', $php . "define( 'WP_DEBUG', {$now} );\n" );
		$dead = Minn_Admin_REST::config_switch( 'WP_DEBUG', $php . "if ( getenv( 'MINN_V046_STAGING' ) ) {\n\tdefine( 'WP_DEBUG', {$flip} );\n}\n" );
		$none = Minn_Admin_REST::config_switch( 'MINN_V046_NEVER_DEFINED', $php . "if ( getenv( 'MINN_V046_STAGING' ) ) {\n\tdefine( 'MINN_V046_NEVER_DEFINED', true );\n}\n" );
		$check( '#12 (L7) control: the literal WordPress runs with is a live switch', false === $live['locked'], wp_json_encode( $live ) );
		$over = Minn_Admin_REST::config_switch( 'WP_DEBUG', $php . "define( 'WP_DEBUG', {$flip} );\n" );
		$same = Minn_Admin_REST::config_switch( 'WP_DEBUG', $php . "if ( getenv( 'MINN_V046_STAGING' ) ) {\n\tdefine( 'WP_DEBUG', {$now} );\n}\n" );
		$check( '#12 (L7) a literal another value overrides is locked as elsewhere', true === $over['locked'] && 'elsewhere' === $over['lock'], wp_json_encode( $over ) );
		$check( '#12 (L7) a literal under a branch is locked as in a branch', true === $dead['locked'] && 'branch' === $dead['lock'], wp_json_encode( $dead ) );
		$check( '#12 (L7) so is one under a branch for a constant that is not defined', true === $none['locked'] && 'branch' === $none['lock'], wp_json_encode( $none ) );
		$check( '#12 (L7, round four) one under a branch is locked even when it matches the running value', true === $same['locked'] && 'branch' === $same['lock'], wp_json_encode( $same ) );
		$e = Minn_Admin_REST::config_rewrite( 'WP_DEBUG', true, $php . "if ( getenv( 'MINN_V046_STAGING' ) ) {\n\tdefine( 'WP_DEBUG', {$now} );\n}\n" );
		$check( '#12 (L7, round four) and its write is refused', is_wp_error( $e ) && 'in_branch' === $e->get_error_code(), is_wp_error( $e ) ? $e->get_error_code() : 'written' );
	}

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
