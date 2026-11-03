<?php
/**
 * Regressions for the v0.49.0 release-candidate audit.
 *
 *  - C1 Custom CSS & JS: manage_options alone passed the list, edit and
 *    publish gates, so an administrator their installer never granted the
 *    snippet caps could publish site-wide code their own screens refuse.
 *  - C2 Gravity Forms feeds: an add-on whose form-settings capability list is
 *    the framework default (empty) let a gravityforms_edit_forms role toggle
 *    and delete its feeds; Gravity Forms reads that empty list as
 *    gform_full_access only.
 *  - C3 JetEngine meta boxes: an include condition switched on with an empty
 *    list (roles or posts) was read as no rule, so the box JetEngine shows to
 *    nobody was read and written for every editor.
 *  - C4 Store settings: an untouched select whose stored value is not among
 *    the options offered to this user was rewritten to the field default by
 *    any save of its section.
 *  - C5 WooCommerce Gift Cards: Adjust balance wrote an Activity row typed as
 *    a sale or a redemption, which their analytics sum.
 *  - C6 Block previews: core's own cover and pattern renderers looked embeds
 *    up past the request's lookup budget and left oembed_cache posts behind.
 *  - C7 Public Post Preview: a toggle body without "enabled" switched a
 *    shared preview link off.
 *
 * Each section calls what the route calls, as the role the finding named,
 * with a control where the fix could over-block. Fixtures (users, posts, a
 * feed row, options) are put back at the end however the run ends.
 *
 * Run: MINN_SWAP_ADD=custom-css-js/custom-css-js.php wp eval-file <ABSOLUTE path>/tests/security-v049-rc.test.php --user=admin --path=<site> --require=<ABSOLUTE path>/tests/lib/plugin-swap.php
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
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
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
$as      = static function ( $uid, callable $fn ) {
	$was = get_current_user_id();
	wp_set_current_user( $uid );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $was );
	}
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = static function ( callable $fn ) {
	$done = false;
	$run  = static function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};
$tag   = strtolower( wp_generate_password( 6, false ) );
$admin = get_current_user_id();
// A throwaway user; its id goes on $users only once it is a real one.
$users     = array();
$make_user = static function ( $role, array $caps = array() ) use ( &$users, $tag ) {
	$login = 'minn-v049rc-' . $role . '-' . count( $users ) . '-' . $tag;
	$id    = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '@example.invalid',
		'role'       => $role,
	) );
	if ( is_wp_error( $id ) || ! is_int( $id ) || $id < 2 ) {
		return 0;
	}
	$users[] = $id;
	$u       = new WP_User( $id );
	foreach ( $caps as $cap ) {
		$u->add_cap( $cap );
	}
	return $id;
};
$drop_users = $once( static function () use ( &$users ) {
	foreach ( $users as $id ) {
		if ( is_int( $id ) && $id > 1 ) {
			wp_delete_user( $id );
		}
	}
} );

/*
 * C1 Custom CSS & JS. The administrator with every snippet cap stripped is
 * the user their installer skipped.
 */
( static function () use ( $check, $skip, $admin, $as ) {
	if ( ! function_exists( 'minn_admin_ccj_can' ) || ! post_type_exists( 'custom-css-js' ) ) {
		$skip( 'C1 Custom CSS & JS: not loaded (MINN_SWAP_ADD=custom-css-js/custom-css-js.php)' );
		return;
	}
	$strip = static function ( $all ) {
		foreach ( array_keys( $all ) as $cap ) {
			if ( false !== strpos( $cap, 'custom_css' ) ) {
				$all[ $cap ] = false;
			}
		}
		return $all;
	};
	$as( $admin, static function () use ( $check, $strip ) {
		$check( 'C1 CCJ control: an administrator holding their caps lists and publishes', minn_admin_ccj_can() && minn_admin_ccj_obj_can( 'publish' ) );
		$ctl = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/ccj/snippets' ) )->get_status();
		$check( 'C1 CCJ control: and reads the snippet list', 200 === $ctl, (string) $ctl );
		add_filter( 'user_has_cap', $strip, PHP_INT_MAX );
		try {
			$check( 'C1 CCJ: manage_options without their caps cannot list', ! minn_admin_ccj_can() );
			$check( 'C1 CCJ: nor publish', ! minn_admin_ccj_obj_can( 'publish' ) );
			$st = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/ccj/snippets' ) )->get_status();
			$check( 'C1 CCJ: and the snippet list refuses', in_array( $st, array( 401, 403, 404 ), true ), (string) $st );
		} finally {
			remove_filter( 'user_has_cap', $strip, PHP_INT_MAX );
		}
	} );
} )();

/*
 * C2 Gravity Forms feeds, on a registered add-on that leaves its
 * form-settings capabilities at the framework default.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $make_user, $once ) {
	global $wpdb;
	if ( ! class_exists( 'GFAddOn' ) || ! class_exists( 'GFAPI' ) ) {
		$skip( 'C2 Gravity Forms: not active' );
		return;
	}
	$slug = '';
	foreach ( GFAddOn::get_registered_addons( true, true ) as $s => $addon ) {
		if ( is_object( $addon ) && method_exists( $addon, 'get_form_settings_capabilities' ) && empty( $addon->get_form_settings_capabilities() ) ) {
			$slug = (string) $s;
			break;
		}
	}
	$forms = GFAPI::get_forms();
	if ( '' === $slug || ! $forms ) {
		$skip( 'C2 Gravity Forms: no add-on with empty form-settings caps, or no form' );
		return;
	}
	$table = $wpdb->prefix . 'gf_addon_feed';
	$feed  = 0;
	if ( $wpdb->insert( $table, array( 'form_id' => (int) $forms[0]['id'], 'is_active' => 0, 'feed_order' => 0, 'meta' => '{"feedName":"minn-v049rc"}', 'addon_slug' => $slug ) ) ) {
		$feed = (int) $wpdb->insert_id;
	}
	$clean = $once( static function () use ( &$feed, $table, $slug ) {
		global $wpdb;
		if ( is_int( $feed ) && $feed > 0 ) {
			$wpdb->delete( $table, array( 'id' => $feed, 'addon_slug' => $slug ) );
		}
	} );
	try {
		if ( ! $feed ) {
			$check( 'C2 GF: feed fixture inserted', false );
			return;
		}
		$builder = $make_user( 'editor', array( 'gravityforms_edit_forms' ) );
		list( $st ) = $as( $builder, static function () use ( $call, $feed ) {
			return $call( 'POST', "/minn-admin/v1/gf/feeds/$feed/active", array( 'active' => true ) );
		} );
		$check( 'C2 GF: edit_forms without full access cannot switch the feed on', 403 === $st, (string) $st );
		list( $st ) = $as( $builder, static function () use ( $call, $feed ) {
			return $call( 'DELETE', "/minn-admin/v1/gf/feeds/$feed" );
		} );
		$check( 'C2 GF: nor delete it', 403 === $st && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE id = %d", $feed ) ), (string) $st );
		list( $st ) = $as( $admin, static function () use ( $call, $feed ) {
			return $call( 'POST', "/minn-admin/v1/gf/feeds/$feed/active", array( 'active' => true ) );
		} );
		$check( 'C2 GF control: an administrator switches it on', 200 === $st, (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * C3 JetEngine. Their meta-box data object is swapped for a fixture for the
 * length of the call, so no box is stored.
 */
( static function () use ( $check, $skip, $as, $make_user, $once ) {
	if ( ! function_exists( 'jet_engine' ) || ! function_exists( 'minn_admin_jet_groups_for' ) || ! is_object( jet_engine()->meta_boxes ?? null ) ) {
		$skip( 'C3 JetEngine: not active' );
		return;
	}
	$editor = $make_user( 'editor' );
	if ( ! $editor ) {
		$check( 'C3 Jet: editor fixture created', false );
		return;
	}
	$post   = (int) wp_insert_post( array( 'post_title' => 'minn v049rc jet', 'post_status' => 'draft', 'post_type' => 'post' ) );
	$clean  = $once( static function () use ( $post ) {
		if ( $post > 0 ) {
			wp_delete_post( $post, true );
		}
	} );
	try {
		$field = array( array( 'type' => 'text', 'name' => 'minn_v049rc', 'title' => 'F', 'object_type' => 'field' ) );
		$box   = static function ( $name, $args ) use ( $field ) {
			return array( 'args' => array_merge( array( 'object_type' => 'post', 'allowed_post_type' => array( 'post' ), 'name' => $name ), $args ), 'meta_fields' => $field );
		};
		$items = array(
			$box( 'roles-empty', array( 'active_conditions' => array( 'include_roles' ), 'include_roles' => array() ) ),
			$box( 'posts-empty', array( 'active_conditions' => array( 'allowed_posts' ), 'allowed_posts' => array() ) ),
			$box( 'roles-editor', array( 'active_conditions' => array( 'include_roles' ), 'include_roles' => array( 'editor' ) ) ),
			$box( 'posts-this', array( 'active_conditions' => array( 'allowed_posts' ), 'allowed_posts' => array( (string) $post ) ) ),
			$box( 'roles-other', array( 'active_conditions' => array( 'include_roles' ), 'include_roles' => array( 'author' ) ) ),
			$box( 'none', array() ),
		);
		$orig  = jet_engine()->meta_boxes->data;
		jet_engine()->meta_boxes->data = new class( $items ) {
			private $items;
			public function __construct( $items ) {
				$this->items = $items;
			}
			public function get_items() {
				return $this->items;
			}
		};
		try {
			$names = $as( $editor, static function () use ( $post ) {
				return wp_list_pluck( minn_admin_jet_groups_for( 'post', $post ), 'group' );
			} );
		} finally {
			jet_engine()->meta_boxes->data = $orig;
		}
		$check( 'C3 Jet: include_roles on and empty offers the box to nobody', ! in_array( 'roles-empty', $names, true ), wp_json_encode( $names ) );
		$check( 'C3 Jet: allowed_posts on and empty offers it on no post', ! in_array( 'posts-empty', $names, true ), wp_json_encode( $names ) );
		$check( 'C3 Jet: a role list without the caller still hides it', ! in_array( 'roles-other', $names, true ), wp_json_encode( $names ) );
		$check( 'C3 Jet control: matching role, matching post and no condition stay', in_array( 'roles-editor', $names, true ) && in_array( 'posts-this', $names, true ) && in_array( 'none', $names, true ), wp_json_encode( $names ) );
	} finally {
		$clean();
	}
} )();

/*
 * C4 Store settings. Currency position stands in for any select: its stored
 * value is set outside the options, a shop manager saves another field.
 */
( static function () use ( $check, $skip, $call, $as, $make_user, $once ) {
	if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'minn_admin_wc_settings_post_data' ) ) {
		$skip( 'C4 Store settings: WooCommerce not active' );
		return;
	}
	$opt   = 'woocommerce_currency_pos';
	$had   = get_option( $opt, null );
	$clean = $once( static function () use ( $opt, $had ) {
		null === $had ? delete_option( $opt ) : update_option( $opt, $had );
	} );
	try {
		update_option( $opt, 'minn-stray' );
		$sm = $make_user( 'shop_manager' );
		list( $st ) = $as( $sm, static function () use ( $call ) {
			return $call( 'POST', '/minn-admin/v1/wc/settings/general/default', array( 'values' => array( 'woocommerce_price_num_decimals' => (string) get_option( 'woocommerce_price_num_decimals', '2' ) ) ) );
		} );
		wp_cache_delete( $opt, 'options' );
		$check( 'C4 Store: a save leaves an untouched select it does not offer as stored', 200 === $st && 'minn-stray' === get_option( $opt ), $st . ' ' . wp_json_encode( get_option( $opt ) ) );
		list( $st ) = $as( $sm, static function () use ( $call, $opt ) {
			return $call( 'POST', '/minn-admin/v1/wc/settings/general/default', array( 'values' => array( $opt => 'right_space' ) ) );
		} );
		wp_cache_delete( $opt, 'options' );
		$check( 'C4 Store control: an edited select still saves', 200 === $st && 'right_space' === get_option( $opt ), $st . ' ' . wp_json_encode( get_option( $opt ) ) );
		list( $st ) = $as( $sm, static function () use ( $call ) {
			return $call( 'POST', '/minn-admin/v1/wc/settings/general/default', array( 'values' => array( 'woocommerce_price_num_decimals' => (string) get_option( 'woocommerce_price_num_decimals', '2' ) ) ) );
		} );
		wp_cache_delete( $opt, 'options' );
		$check( 'C4 Store control: and an untouched valid select stays', 200 === $st && 'right_space' === get_option( $opt ), $st . ' ' . wp_json_encode( get_option( $opt ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * C5 WooCommerce Gift Cards. A top-up and a matching reduction net to zero.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	global $wpdb;
	if ( ! function_exists( 'WC_GC' ) || ! function_exists( 'minn_admin_wcgc_load' ) ) {
		$skip( 'C5 WooCommerce Gift Cards: not active' );
		return;
	}
	$act  = $wpdb->prefix . 'woocommerce_gc_activity';
	$card = $wpdb->get_row( "SELECT id, remaining FROM {$wpdb->prefix}woocommerce_gc_cards WHERE is_active = 'on' AND remaining > 5 ORDER BY id LIMIT 1" );
	if ( ! $card ) {
		$skip( 'C5 WooCommerce Gift Cards: no active card with a balance' );
		return;
	}
	$id    = (int) $card->id;
	$max   = (int) $wpdb->get_var( "SELECT MAX(id) FROM $act" );
	$clean = $once( static function () use ( $act, $id, $max ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM $act WHERE id > %d AND gc_id = %d", $max, $id ) );
	} );
	try {
		$st = array();
		foreach ( array( 1, -1 ) as $delta ) {
			list( $s ) = $as( $admin, static function () use ( $call, $id, $delta ) {
				return $call( 'POST', "/minn-admin/v1/wcgc/gift-cards/$id/balance", array( 'adjust' => $delta ) );
			} );
			$st[] = $s;
		}
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $act WHERE id > %d AND gc_id = %d", $max, $id ) );
		$now  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT remaining FROM {$wpdb->prefix}woocommerce_gc_cards WHERE id = %d", $id ) );
		$check( 'C5 WCGC control: both adjustments land and net to zero', array( 200, 200 ) === $st && (float) $now === (float) $card->remaining, wp_json_encode( $st ) . ' ' . $now );
		$check( 'C5 WCGC: no Activity row their analytics would count', 0 === $rows, (string) $rows );
	} finally {
		$clean();
	}
} )();

/*
 * C6 Block previews. Outbound requests to the probe host are answered here,
 * never sent; fourteen covers and a pattern of two bare URLs.
 */
( static function () use ( $check, $call, $as, $admin, $tag ) {
	global $wpdb;
	$fetches = 0;
	$fake    = static function ( $pre, $args, $url ) use ( &$fetches ) {
		if ( false !== strpos( (string) $url, 'minn-v049rc' ) ) {
			++$fetches;
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	$pattern = 'minn-v049rc/urls-' . $tag;
	add_filter( 'pre_http_request', $fake, 10, 3 );
	register_block_pattern( $pattern, array( 'title' => 'v049rc', 'content' => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->\n\nhttps://example.org/minn-v049rc-p1-$tag\n\nhttps://example.org/minn-v049rc-p2-$tag\n" ) );
	$covers = '';
	for ( $i = 0; $i < 14; $i++ ) {
		$covers .= '<!-- wp:cover {"url":"https://example.org/minn-v049rc-c' . $i . '-' . $tag . '","backgroundType":"embed-video","dimRatio":50} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';
	}
	$max = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	try {
		list( $st, $d ) = $as( $admin, static function () use ( $call, $covers, $pattern ) {
			return $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => array( $covers, '<!-- wp:pattern {"slug":"' . $pattern . '"} /-->' ), 'post' => 0 ) );
		} );
	} finally {
		remove_filter( 'pre_http_request', $fake, 10 );
		unregister_block_pattern( $pattern );
		$made = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'oembed_cache' AND ID > %d", $max ) ) );
		foreach ( $made as $pid ) {
			if ( $pid > $max ) {
				wp_delete_post( $pid, true );
			}
		}
	}
	$check( 'C6 Preview control: the render answers', 200 === $st && 2 === count( (array) ( $d['rendered'] ?? array() ) ), (string) $st );
	$check( 'C6 Preview: core renderers stay inside the lookup budget', $fetches <= 10, (string) $fetches );
	$check( 'C6 Preview: and leave no oembed_cache post', ! $made, wp_json_encode( $made ) );
} )();

/*
 * C7 Public Post Preview.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	if ( ! function_exists( 'minn_admin_ppp_set' ) || ! class_exists( 'DS_Public_Post_Preview' ) ) {
		$skip( 'C7 Public Post Preview: not active' );
		return;
	}
	$draft = (int) wp_insert_post( array( 'post_title' => 'minn v049rc ppp', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
	$clean = $once( static function () use ( $draft ) {
		if ( $draft > 0 ) {
			minn_admin_ppp_set( $draft, false );
			wp_delete_post( $draft, true );
		}
	} );
	try {
		minn_admin_ppp_set( $draft, true );
		$as( $admin, static function () use ( $check, $call, $draft ) {
			list( $st ) = $call( 'POST', "/minn-admin/v1/ppp/$draft", (object) array() );
			list( , $d ) = $call( 'GET', "/minn-admin/v1/ppp/$draft" );
			$check( 'C7 PPP: a body without enabled is refused and the link stays', 400 === $st && ! empty( $d['enabled'] ), $st . ' ' . wp_json_encode( $d['enabled'] ?? null ) );
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/ppp/$draft", array( 'enabled' => false ) );
			$check( 'C7 PPP control: an explicit off turns it off', 200 === $st && empty( $d['enabled'] ), (string) $st );
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/ppp/$draft", array( 'enabled' => true ) );
			$check( 'C7 PPP control: and on again', 200 === $st && ! empty( $d['enabled'] ), (string) $st );
		} );
	} finally {
		$clean();
	}
} )();

$drop_users();

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
