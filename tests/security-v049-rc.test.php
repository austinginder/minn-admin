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
 * Round 2 (RC2):
 *
 *  - C8 Block previews: once the budget was spent, a Query loop's post
 *    content kept the refused lookup as a failure in that post's own
 *    _oembed_ meta, which the front end never retries.
 *  - C9 WooCommerce Gift Cards: a reduction saved the whole card it had
 *    read, writing back over a purchase that landed in between.
 *  - C10 Visibility: a toggle body without "on" switched the mode off.
 *  - C11 Snippet switches (Custom CSS & JS, HFCM): a body without "active"
 *    switched a snippet off, and one that was not JSON switched it on.
 *  - C12 Redirection: switching the monitor off and on again lost every
 *    monitored type but posts and pages, and the associated path.
 *
 * Round 3 (RC3):
 *
 *  - C13 Gravity Forms notifications: a blank stored event was read as
 *    form_submission, so a subject-only save started sending a notification
 *    Gravity Forms' own Update refuses to save until an event is picked.
 *  - C14 JetReviews: an approve body without "approved" unapproved the review.
 *  - C15 WPForms: a star body without "on" unstarred the entry.
 *  - C16 Redirection: turning the monitor on dropped its kept snapshot before
 *    a refused retention, and kept types no longer registered left it off.
 *
 * Each section calls what the route calls, as the role the finding named,
 * with a control where the fix could over-block. Fixtures (users, posts, a
 * feed row, options) are put back at the end however the run ends.
 *
 * Run: MINN_SWAP_ADD=custom-css-js/custom-css-js.php,header-footer-code-manager/99robots-header-footer-code-manager.php wp eval-file <ABSOLUTE path>/tests/security-v049-rc.test.php --user=admin --path=<site> --require=<ABSOLUTE path>/tests/lib/plugin-swap.php
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

/*
 * C8 Block previews, round 2. The covers spend the lookup budget, then a
 * Query loop renders another post's content, whose bare URL autoembed looks
 * up on that post.
 */
( static function () use ( $check, $call, $as, $admin, $tag ) {
	global $wpdb;
	$fake = static function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) $url, 'minn-v049rc' ) ) {
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	$title = 'minn-v049rc2-loop-' . $tag;
	$other = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => $admin, 'post_content' => "<!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph -->\n\nhttps://example.org/minn-v049rc-loop-$tag\n" ) );
	$other = is_int( $other ) ? $other : 0;
	if ( $other < 2 ) {
		$check( 'C8 Preview: loop post fixture created', false );
		return;
	}
	// The insert itself can cache the URL (Yoast's link indexer runs
	// autoembed on save): only what the preview writes counts.
	$embed_keys = static function () use ( $other ) {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $other, $wpdb->esc_like( '_oembed_' ) . '%' ) );
	};
	foreach ( $embed_keys() as $k ) {
		delete_post_meta( $other, $k );
	}
	$covers = '';
	for ( $i = 0; $i < 12; $i++ ) {
		$covers .= '<!-- wp:cover {"url":"https://example.org/minn-v049rc-l' . $i . '-' . $tag . '","backgroundType":"embed-video","dimRatio":50} --><div class="wp-block-cover"><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover -->';
	}
	$query = '<!-- wp:query {"queryId":7,"query":{"perPage":1,"pages":0,"offset":0,"postType":"post","order":"desc","orderBy":"date","search":"' . $title . '","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-content /--><!-- /wp:post-template --></div><!-- /wp:query -->';
	$max   = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
	add_filter( 'pre_http_request', $fake, 10, 3 );
	try {
		list( $st, $d ) = $as( $admin, static function () use ( $call, $covers, $query ) {
			return $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => array( $covers, $query ), 'post' => 0 ) );
		} );
		$meta = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $other, $wpdb->esc_like( '_oembed_' ) . '%' ) );
	} finally {
		remove_filter( 'pre_http_request', $fake, 10 );
		wp_delete_post( $other, true );
		foreach ( array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'oembed_cache' AND ID > %d", $max ) ) ) as $pid ) {
			if ( $pid > $max ) {
				wp_delete_post( $pid, true );
			}
		}
	}
	$check( 'C8 Preview control: the loop renders the other post', 200 === $st && false !== strpos( (string) ( $d['rendered'][1] ?? '' ), 'minn-v049rc-loop-' ), $st . ' ' . substr( wp_json_encode( $d['rendered'][1] ?? null ), 0, 160 ) );
	$check( 'C8 Preview: a loop post keeps no embed cache the preview wrote', ! $meta, wp_json_encode( $meta ) );
} )();

/*
 * C9 WooCommerce Gift Cards, round 2. A checkout's debit commits after the
 * adjustment read the card and before it wrote: the query filter runs it
 * just ahead of the adjustment's first write to the card row.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	global $wpdb;
	if ( ! function_exists( 'WC_GC' ) || ! function_exists( 'minn_admin_wcgc_load' ) ) {
		$skip( 'C9 WooCommerce Gift Cards: not active' );
		return;
	}
	$cards = $wpdb->prefix . 'woocommerce_gc_cards';
	$act   = $wpdb->prefix . 'woocommerce_gc_activity';
	$card  = $wpdb->get_row( "SELECT id, remaining FROM $cards WHERE is_active = 'on' AND remaining > 5 ORDER BY id LIMIT 1" );
	if ( ! $card ) {
		$skip( 'C9 WooCommerce Gift Cards: no active card with a balance' );
		return;
	}
	$id    = (int) $card->id;
	$was   = (string) $card->remaining;
	$max   = (int) $wpdb->get_var( "SELECT MAX(id) FROM $act" );
	$clean = $once( static function () use ( $cards, $act, $id, $was, $max ) {
		global $wpdb;
		$wpdb->update( $cards, array( 'remaining' => $was ), array( 'id' => $id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM $act WHERE id > %d AND gc_id = %d", $max, $id ) );
	} );
	$fired = false;
	$race  = static function ( $q ) use ( &$fired, $cards, $id ) {
		global $wpdb;
		if ( ! $fired && false !== stripos( $q, $cards ) && ( false !== stripos( $q, 'FOR UPDATE' ) || 0 === stripos( ltrim( $q ), 'UPDATE' ) ) ) {
			$fired = true;
			$wpdb->query( $wpdb->prepare( "UPDATE $cards SET remaining = remaining - 2 WHERE id = %d", $id ) );
		}
		return $q;
	};
	try {
		add_filter( 'query', $race );
		try {
			list( $st ) = $as( $admin, static function () use ( $call, $id ) {
				return $call( 'POST', "/minn-admin/v1/wcgc/gift-cards/$id/balance", array( 'adjust' => -1 ) );
			} );
		} finally {
			remove_filter( 'query', $race );
		}
		$now = (float) $wpdb->get_var( $wpdb->prepare( "SELECT remaining FROM $cards WHERE id = %d", $id ) );
		$check( 'C9 WCGC: a reduction keeps a purchase that landed after its read', $fired && 200 === $st && abs( $now - ( (float) $was - 3 ) ) < 0.001, ( $fired ? 'raced' : 'no race' ) . " $st $was -> $now" );
		$wpdb->update( $cards, array( 'remaining' => $was ), array( 'id' => $id ) );
		list( $st ) = $as( $admin, static function () use ( $call, $id ) {
			return $call( 'POST', "/minn-admin/v1/wcgc/gift-cards/$id/balance", array( 'adjust' => -1 ) );
		} );
		$now = (float) $wpdb->get_var( $wpdb->prepare( "SELECT remaining FROM $cards WHERE id = %d", $id ) );
		$check( 'C9 WCGC control: an unraced reduction takes exactly its amount', 200 === $st && abs( $now - ( (float) $was - 1 ) ) < 0.001, "$st $was -> $now" );
		list( $st ) = $as( $admin, static function () use ( $call, $id, $was ) {
			return $call( 'POST', "/minn-admin/v1/wcgc/gift-cards/$id/balance", array( 'adjust' => -1 * ( (float) $was + 1 ) ) );
		} );
		$check( 'C9 WCGC control: more than the card holds is still refused', 400 === $st, (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * C10 Visibility toggle. A fixture writer records what it is asked to do.
 */
( static function () use ( $check, $call, $as, $admin ) {
	if ( ! function_exists( 'minn_admin_visibility_toggles' ) ) {
		$check( 'C10 Visibility: site-status adapter loaded', false );
		return;
	}
	$asked = array();
	$add   = static function ( $t ) use ( &$asked ) {
		$t['minn_v049rc2'] = array( 'set' => static function ( $on ) use ( &$asked ) {
			$asked[] = (bool) $on;
		} );
		return $t;
	};
	add_filter( 'minn_admin_visibility_toggles', $add );
	try {
		$st = $as( $admin, static function () use ( $call ) {
			$out = array();
			list( $out[] ) = $call( 'POST', '/minn-admin/v1/visibility/toggle', array( 'id' => 'minn_v049rc2' ) );
			list( $out[] ) = $call( 'POST', '/minn-admin/v1/visibility/toggle', array( 'id' => 'minn_v049rc2', 'on' => false ) );
			list( $out[] ) = $call( 'POST', '/minn-admin/v1/visibility/toggle', array( 'id' => 'minn_v049rc2', 'on' => true ) );
			return $out;
		} );
	} finally {
		remove_filter( 'minn_admin_visibility_toggles', $add );
	}
	$check( 'C10 Visibility: a body without on is refused and no writer runs', 400 === $st[0] && array( false, true ) === $asked, wp_json_encode( $st ) . ' ' . wp_json_encode( $asked ) );
	$check( 'C10 Visibility control: explicit off and on reach the writer', 200 === $st[1] && 200 === $st[2], wp_json_encode( $st ) );
} )();

/*
 * C11 Snippet switches: Custom CSS & JS (an HTML snippet, so no upload file
 * is touched) and HFCM. A body without active, and one that is not JSON.
 */
( static function () use ( $check, $skip, $as, $admin, $once ) {
	global $wpdb;
	$raw = static function ( $route, $body, $type ) {
		$r = new WP_REST_Request( 'POST', $route );
		$r->set_header( 'content-type', $type );
		$r->set_body( $body );
		return rest_do_request( $r )->get_status();
	};
	if ( function_exists( 'minn_admin_ccj_is_active' ) && post_type_exists( 'custom-css-js' ) ) {
		$on    = (int) wp_insert_post( array( 'post_title' => 'minn v049rc2 on', 'post_status' => 'publish', 'post_type' => 'custom-css-js', 'post_content' => '<!-- minn -->', 'post_author' => $admin ) );
		$off   = (int) wp_insert_post( array( 'post_title' => 'minn v049rc2 off', 'post_status' => 'draft', 'post_type' => 'custom-css-js', 'post_content' => '<!-- minn -->', 'post_author' => $admin ) );
		$clean = $once( static function () use ( $on, $off ) {
			foreach ( array( $on, $off ) as $pid ) {
				if ( $pid > 1 && 'custom-css-js' === get_post_type( $pid ) ) {
					wp_delete_post( $pid, true );
				}
			}
			if ( function_exists( 'minn_admin_ccj_rebuild_tree' ) ) {
				minn_admin_ccj_rebuild_tree();
			}
		} );
		try {
			$opts = array_merge( minn_admin_ccj_default_options( 'html' ), array( 'language' => 'html', 'type' => 'footer', 'side' => 'frontend' ) );
			update_post_meta( $on, 'options', $opts );
			update_post_meta( $off, 'options', $opts );
			update_post_meta( $on, '_active', 'yes' );
			update_post_meta( $off, '_active', 'no' );
			$st = $as( $admin, static function () use ( $raw, $on, $off ) {
				return array( $raw( "/minn-admin/v1/ccj/snippets/$on/active", '{}', 'application/json' ), $raw( "/minn-admin/v1/ccj/snippets/$off/active", 'on', 'text/plain' ), $raw( "/minn-admin/v1/ccj/snippets/$off/active", '{"active":false}', 'application/json' ) );
			} );
			$check( 'C11 CCJ: a body without active leaves a live snippet on', 400 === $st[0] && minn_admin_ccj_is_active( $on ), wp_json_encode( $st ) );
			$check( 'C11 CCJ: a body that is not JSON leaves an off snippet off', 400 === $st[1] && ! minn_admin_ccj_is_active( $off ), wp_json_encode( $st ) );
			$check( 'C11 CCJ control: an explicit off answers', 200 === $st[2], wp_json_encode( $st ) );
		} finally {
			$clean();
		}
	} else {
		$skip( 'C11 CCJ: not loaded (MINN_SWAP_ADD=custom-css-js/custom-css-js.php)' );
	}
	$table = $wpdb->prefix . 'hfcm_scripts';
	if ( function_exists( 'minn_admin_hfcm_active' ) && minn_admin_hfcm_active() ) {
		$row = $wpdb->get_row( "SELECT script_id, status FROM $table ORDER BY script_id ASC LIMIT 1" );
		if ( ! $row ) {
			$skip( 'C11 HFCM: no snippet' );
			return;
		}
		$sid   = (int) $row->script_id;
		$clean = $once( static function () use ( $row, $sid, $table ) {
			global $wpdb;
			$wpdb->update( $table, array( 'status' => $row->status ), array( 'script_id' => $sid ) );
		} );
		$status = static function () use ( $sid, $table ) {
			global $wpdb;
			return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $table WHERE script_id = %d", $sid ) );
		};
		try {
			$wpdb->update( $table, array( 'status' => 'active' ), array( 'script_id' => $sid ) );
			$st = $as( $admin, static function () use ( $raw, $sid ) {
				return $raw( "/minn-admin/v1/hfcm/snippets/$sid/active", '{}', 'application/json' );
			} );
			$check( 'C11 HFCM: a body without active leaves a live snippet on', 400 === $st && 'active' === $status(), $st . ' ' . $status() );
			$wpdb->update( $table, array( 'status' => 'inactive' ), array( 'script_id' => $sid ) );
			$st = $as( $admin, static function () use ( $raw, $sid ) {
				return $raw( "/minn-admin/v1/hfcm/snippets/$sid/active", 'on', 'text/plain' );
			} );
			$check( 'C11 HFCM: a body that is not JSON leaves an off snippet off', 400 === $st && 'inactive' === $status(), $st . ' ' . $status() );
			$st = $as( $admin, static function () use ( $raw, $sid ) {
				return $raw( "/minn-admin/v1/hfcm/snippets/$sid/active", '{"active":true}', 'application/json' );
			} );
			$check( 'C11 HFCM control: an explicit on switches it on', 200 === $st && 'active' === $status(), $st . ' ' . $status() );
		} finally {
			$clean();
		}
	} else {
		$skip( 'C11 HFCM: not loaded (MINN_SWAP_ADD=header-footer-code-manager/99robots-header-footer-code-manager.php)' );
	}
} )();

/*
 * C12 Redirection's permalink monitor: off, then on, through the Minn switch.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	if ( ! function_exists( 'red_get_options' ) || ! function_exists( 'red_set_options' ) || ! post_type_exists( 'product' ) ) {
		$skip( 'C12 Redirection: not active, or no product type' );
		return;
	}
	$had   = get_option( 'redirection_options' );
	$kept  = get_option( 'minn_admin_redirection_monitor', null );
	$clean = $once( static function () use ( $had, $kept ) {
		update_option( 'redirection_options', $had );
		null === $kept ? delete_option( 'minn_admin_redirection_monitor' ) : update_option( 'minn_admin_redirection_monitor', $kept, false );
	} );
	try {
		red_set_options( array( 'monitor_types' => array( 'post', 'page', 'product' ), 'monitor_post' => 1, 'associated_redirect' => '/minn-old/' ) );
		$save = static function ( $on ) use ( $call, $as, $admin ) {
			return $as( $admin, static function () use ( $call, $on ) {
				list( $st ) = $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'monitor' => $on ) ) );
				return $st;
			} );
		};
		$st0 = $save( true );
		$o   = red_get_options();
		$check( 'C12 Redirection: on while already on keeps the types', 200 === $st0 && array( 'post', 'page', 'product' ) === array_values( $o['monitor_types'] ), $st0 . ' ' . wp_json_encode( $o['monitor_types'] ) );
		$st1 = $save( false );
		$o   = red_get_options();
		$check( 'C12 Redirection control: off is their own off', 200 === $st1 && empty( $o['monitor_post'] ) && ! $o['monitor_types'], $st1 . ' ' . wp_json_encode( array( $o['monitor_post'], $o['monitor_types'] ) ) );
		$st2 = $save( true );
		$o   = red_get_options();
		$check( 'C12 Redirection: on again brings back every type and the associated path', 200 === $st2 && array( 'post', 'page', 'product' ) === array_values( $o['monitor_types'] ) && '/minn-old/' === $o['associated_redirect'] && 1 === (int) $o['monitor_post'], $st2 . ' ' . wp_json_encode( array( $o['monitor_post'], $o['monitor_types'], $o['associated_redirect'] ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * C13 Gravity Forms notification with a blank stored event.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_payload' ) ) {
		$skip( 'C13 Gravity Forms notifications: not active' );
		return;
	}
	$forms = GFAPI::get_forms();
	if ( ! $forms ) {
		$skip( 'C13 Gravity Forms notifications: no form' );
		return;
	}
	$fid   = (int) $forms[0]['id'];
	$nid   = 'minnv049rc3';
	$clean = $once( static function () use ( $fid, $nid ) {
		$form = GFAPI::get_form( $fid );
		if ( $form && isset( $form['notifications'][ $nid ] ) ) {
			unset( $form['notifications'][ $nid ] );
			GFAPI::update_form( $form );
		}
	} );
	try {
		$form                           = GFAPI::get_form( $fid );
		$form['notifications'][ $nid ] = array(
			'id'       => $nid,
			'name'     => 'minn v049rc3 blank event',
			'isActive' => true,
			'service'  => 'wordpress',
			'event'    => '',
			'toType'   => 'email',
			'to'       => '{admin_email}',
			'from'     => '{admin_email}',
			'subject'  => 'Before',
			'message'  => 'Body',
		);
		GFAPI::update_form( $form );
		$route = "/minn-admin/v1/gf/notifications/$fid:$nid/full";
		$read   = $as( $admin, static function () use ( $call, $route ) {
			return $call( 'GET', $route );
		} );
		$save   = $as( $admin, static function () use ( $call, $route ) {
			return $call( 'POST', $route, array( 'subject' => 'After' ) );
		} );
		$stored = rgar( GFAPI::get_form( $fid )['notifications'], $nid );
		$check( 'C13 GF: a blank stored event reads blank', 200 === $read[0] && '' === ( $read[1]['notification']['event'] ?? null ), wp_json_encode( $read[1]['notification']['event'] ?? null ) );
		$check( 'C13 GF: a subject-only save is refused and the event stays blank', 400 === $save[0] && '' === ( $stored['event'] ?? null ) && 'Before' === ( $stored['subject'] ?? null ), $save[0] . ' ' . wp_json_encode( array( $stored['event'] ?? null, $stored['subject'] ?? null ) ) );
		$ctl    = $as( $admin, static function () use ( $call, $route ) {
			return $call( 'POST', $route, array( 'subject' => 'After', 'event' => 'form_submission' ) );
		} );
		$stored = rgar( GFAPI::get_form( $fid )['notifications'], $nid );
		$check( 'C13 GF control: picking an event saves it', 200 === $ctl[0] && 'form_submission' === ( $stored['event'] ?? null ) && 'After' === ( $stored['subject'] ?? null ), $ctl[0] . ' ' . wp_json_encode( array( $stored['event'] ?? null, $stored['subject'] ?? null ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * C14 JetReviews approve, on an approved review, restored after.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	global $wpdb;
	if ( ! function_exists( 'minn_admin_jet_reviews_sync' ) ) {
		$skip( 'C14 JetReviews: not active' );
		return;
	}
	$table = $wpdb->prefix . 'jet_reviews';
	$row   = $wpdb->get_row( "SELECT * FROM $table ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB
	if ( ! $row ) {
		$skip( 'C14 JetReviews: no review row' );
		return;
	}
	$id    = (int) $row->id;
	$was   = (int) $row->approved;
	$clean = $once( static function () use ( $wpdb, $table, $id, $was ) {
		$wpdb->update( $table, array( 'approved' => $was ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		minn_admin_jet_reviews_sync( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB
	} );
	try {
		$wpdb->update( $table, array( 'approved' => 1 ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		$st  = $as( $admin, static function () use ( $call, $id ) {
			return $call( 'POST', "/minn-admin/v1/jet-reviews/reviews/$id/approve", array() )[0];
		} );
		$now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT approved FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$check( 'C14 JetReviews: a body without approved leaves the review approved', 400 === $st && 1 === $now, $st . ' approved=' . $now );
		$ctl = $as( $admin, static function () use ( $call, $id ) {
			return $call( 'POST', "/minn-admin/v1/jet-reviews/reviews/$id/approve", array( 'approved' => false ) )[0];
		} );
		$now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT approved FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$check( 'C14 JetReviews control: an explicit unapprove answers', 200 === $ctl && 0 === $now, $ctl . ' approved=' . $now );
	} finally {
		$clean();
	}
} )();

/*
 * C15 WPForms star, on a starred entry, restored after.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	global $wpdb;
	if ( ! function_exists( 'wpforms' ) || ! wpforms()->obj( 'entry' ) ) {
		$skip( 'C15 WPForms: not active, or no entries' );
		return;
	}
	$table = $wpdb->prefix . 'wpforms_entries';
	$row   = $wpdb->get_row( "SELECT entry_id, starred FROM $table ORDER BY entry_id LIMIT 1" ); // phpcs:ignore WordPress.DB
	if ( ! $row ) {
		$skip( 'C15 WPForms: no entry row' );
		return;
	}
	$id    = (int) $row->entry_id;
	$was   = (int) $row->starred;
	$clean = $once( static function () use ( $wpdb, $table, $id, $was ) {
		$wpdb->update( $table, array( 'starred' => $was ), array( 'entry_id' => $id ) ); // phpcs:ignore WordPress.DB
	} );
	try {
		$wpdb->update( $table, array( 'starred' => 1 ), array( 'entry_id' => $id ) ); // phpcs:ignore WordPress.DB
		$st  = $as( $admin, static function () use ( $call, $id ) {
			return array( $call( 'POST', "/minn-admin/v1/wpforms/entries/$id/star", array() )[0] );
		} );
		$now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT starred FROM $table WHERE entry_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$check( 'C15 WPForms: a body without on leaves the entry starred', 400 === $st[0] && 1 === $now, wp_json_encode( $st ) . ' starred=' . $now );
		$ctl = $as( $admin, static function () use ( $call, $id ) {
			return $call( 'POST', "/minn-admin/v1/wpforms/entries/$id/star", array( 'on' => 0 ) )[0];
		} );
		$now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT starred FROM $table WHERE entry_id = %d", $id ) ); // phpcs:ignore WordPress.DB
		$check( 'C15 WPForms control: an explicit unstar answers', 200 === $ctl && 0 === $now, $ctl . ' starred=' . $now );
	} finally {
		$clean();
	}
} )();

/*
 * C16 Redirection's monitor restore: a refused retention, then kept types
 * that are no longer registered.
 */
( static function () use ( $check, $skip, $call, $as, $admin, $once ) {
	if ( ! function_exists( 'red_get_options' ) || ! function_exists( 'red_set_options' ) || ! post_type_exists( 'product' ) ) {
		$skip( 'C16 Redirection: not active, or no product type' );
		return;
	}
	$had   = get_option( 'redirection_options' );
	$kept  = get_option( 'minn_admin_redirection_monitor', null );
	$clean = $once( static function () use ( $had, $kept ) {
		update_option( 'redirection_options', $had );
		null === $kept ? delete_option( 'minn_admin_redirection_monitor' ) : update_option( 'minn_admin_redirection_monitor', $kept, false );
	} );
	try {
		$save = static function ( array $vals ) use ( $call, $as, $admin ) {
			return $as( $admin, static function () use ( $call, $vals ) {
				return $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => $vals ) )[0];
			} );
		};
		red_set_options( array( 'monitor_types' => array( 'post', 'page', 'product' ), 'monitor_post' => 1, 'associated_redirect' => '/minn-old/' ) );
		$save( array( 'monitor' => false ) );
		$bad = $save( array( 'monitor' => true, 'expire_redirect' => 99999 ) );
		$o   = red_get_options();
		$check( 'C16 Redirection: a refused retention keeps the snapshot and stays off', 400 === $bad && is_array( get_option( 'minn_admin_redirection_monitor' ) ) && ! $o['monitor_types'], $bad . ' ' . wp_json_encode( get_option( 'minn_admin_redirection_monitor' ) ) );
		$ok = $save( array( 'monitor' => true ) );
		$o  = red_get_options();
		$check( 'C16 Redirection control: the next on still restores every type and the path', 200 === $ok && array( 'post', 'page', 'product' ) === array_values( $o['monitor_types'] ) && '/minn-old/' === $o['associated_redirect'] && false === get_option( 'minn_admin_redirection_monitor' ), $ok . ' ' . wp_json_encode( array( $o['monitor_types'], $o['associated_redirect'] ) ) );

		// Kept types Redirection no longer offers: on still turns it on.
		$save( array( 'monitor' => false ) );
		update_option( 'minn_admin_redirection_monitor', array( 'group' => 1, 'types' => array( 'minn_gone_type' ), 'associated' => '/minn-old/' ), false );
		$st = $save( array( 'monitor' => true ) );
		$o  = red_get_options();
		$check( 'C16 Redirection: kept types that are gone still turn the monitor on, path kept', 200 === $st && ! empty( $o['monitor_post'] ) && array( 'post', 'page' ) === array_values( $o['monitor_types'] ) && '/minn-old/' === $o['associated_redirect'], $st . ' ' . wp_json_encode( array( $o['monitor_post'], $o['monitor_types'], $o['associated_redirect'] ) ) );
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
