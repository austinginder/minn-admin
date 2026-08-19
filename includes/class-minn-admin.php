<?php
/**
 * Core plugin class: routing, app shell, admin integration, maintenance mode.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin {

	const QUERY_VAR = 'minn_admin';

	public static function init() {
		add_filter( 'determine_locale', array( __CLASS__, 'route_locale' ) );
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_heal_rewrites' ), 20 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_app' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_maintenance_mode' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_link' ), 100 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_filter( 'admin_url', array( __CLASS__, 'default_admin_url' ), 10, 2 );
		// Before Minn_Admin_Bar::suppress_core_bar (100), so a 'minn' policy
		// still lets the Minn bar make the call for its own render.
		add_filter( 'show_admin_bar', array( __CLASS__, 'enforce_toolbar_policy' ), 99 );
		add_action( 'init', array( __CLASS__, 'register_x_oembed' ) );
		add_action( 'init', array( __CLASS__, 'register_oembed_refresh' ), 20 );
		add_action( 'init', array( __CLASS__, 'register_toolbar_meta' ) );
		add_action( 'wp_ajax_minn_plugin_status', array( __CLASS__, 'ajax_plugin_status' ) );
	}

	/**
	 * Plugin activate/deactivate over admin-ajax instead of wp/v2/plugins.
	 * An admin-ajax request bootstraps wp-admin, so is_admin() is genuinely
	 * true and the admin includes are loaded — the context activation hooks
	 * were written for. REST is neither admin nor CLI, and plugins that gate
	 * their loading on is_admin() fatal there (Breeze references its
	 * ecommerce class during activation on WooCommerce sites; it will not
	 * be the last with that shape). Accepts the REST-style plugin id
	 * (basename sans .php) so the client speaks one dialect.
	 */
	public static function ajax_plugin_status() {
		check_ajax_referer( 'minn-plugin-status' );
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to manage plugins for this site.' ) ), 403 );
		}
		$id     = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( '' === $id || validate_file( $id ) || ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid plugin or status.', 'minn-admin' ) ), 400 );
		}
		$file = $id . '.php';
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Plugin not found.', 'minn-admin' ) ), 404 );
		}
		$denied = self::plugin_toggle_denied( $file );
		if ( $denied ) {
			wp_send_json_error( array( 'message' => $denied->get_error_message() ), 403 );
		}
		if ( 'active' === $status ) {
			$result = activate_plugin( $file, '', self::plugin_toggle_is_network( $file, true ) );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => wp_strip_all_tags( $result->get_error_message() ) ), 500 );
			}
		} else {
			deactivate_plugins( $file, false, self::plugin_toggle_is_network( $file, false ) );
		}
		wp_send_json_success( array( 'plugin' => $id, 'status' => $status ) );
	}

	/**
	 * May the current user flip THIS plugin's activation from a per-site surface?
	 *
	 * The per-object `activate_plugin` meta cap is not enough on its own. Core
	 * only folds manage_network_plugins into it when the network HIDES the
	 * plugins menu (capabilities.php reads menu_items['plugins']), so on a
	 * network that grants subsite administrators that menu the meta cap passes
	 * for a network-active plugin too. wp-admin still refuses — plugins.php
	 * redirects away when ! is_network_admin() && is_plugin_active_for_network()
	 * and passes is_network_admin() as $network_wide — and core's REST
	 * controller answers rest_cannot_manage_network_plugins / rest_network_only_plugin.
	 * These are those guards, so one tenant cannot disable a plugin for every
	 * site on the network, or force a network-only plugin on to all of them.
	 *
	 * @param string $file Plugin file relative to the plugins directory.
	 * @return WP_Error|null Error to answer with, or null when the change is allowed.
	 */
	public static function plugin_toggle_denied( $file ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! current_user_can( 'activate_plugin', $file ) ) {
			return new WP_Error( 'forbidden', __( 'Sorry, you are not allowed to manage this plugin.', 'minn-admin' ) );
		}
		if ( ! is_multisite() || current_user_can( 'manage_network_plugins' ) ) {
			return null;
		}
		if ( is_plugin_active_for_network( $file ) ) {
			return new WP_Error( 'network_active', __( 'That plugin is turned on for the whole network, so only a network administrator can change it.', 'minn-admin' ) );
		}
		if ( is_network_only_plugin( $file ) ) {
			return new WP_Error( 'network_only', __( 'That plugin can only run network-wide, so only a network administrator can turn it on.', 'minn-admin' ) );
		}
		return null;
	}

	/**
	 * The explicit $network_wide to hand activate_plugin()/deactivate_plugins().
	 *
	 * Never leave it at the default: deactivate_plugins() reads null as "yes,
	 * network-wide" for a network-active plugin, and activate_plugin() lets
	 * is_network_only_plugin() force it true. Passing the value outright means
	 * a per-site request can only ever become a network write for someone who
	 * holds manage_network_plugins, which plugin_toggle_denied() has confirmed.
	 *
	 * @param string $file       Plugin file relative to the plugins directory.
	 * @param bool   $activating True when turning the plugin on.
	 * @return bool
	 */
	public static function plugin_toggle_is_network( $file, $activating ) {
		if ( ! is_multisite() || ! current_user_can( 'manage_network_plugins' ) ) {
			return false;
		}
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return $activating
			? is_network_only_plugin( $file )
			: is_plugin_active_for_network( $file );
	}

	/**
	 * Expose core's per-user "Show Toolbar when viewing site" preference
	 * (user meta show_admin_bar_front, 'true'/'false' STRINGS — core's own
	 * storage) over REST so Your profile can flip it. Writes are limited to
	 * whoever can edit that user, exactly like the wp-admin profile screen.
	 */
	public static function register_toolbar_meta() {
		register_meta(
			'user',
			'show_admin_bar_front',
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => 'true',
				'show_in_rest'      => true,
				'sanitize_callback' => function ( $value ) {
					return 'false' === $value ? 'false' : 'true';
				},
				'auth_callback'     => function ( $allowed, $meta_key, $object_id ) {
					return current_user_can( 'edit_user', $object_id );
				},
			)
		);
	}

	/**
	 * Installed locales for the per-user Language picker on Your profile:
	 * [value, label] pairs. '' is the site default and en_US is always
	 * offered. Labels resolve from core's cached translations list when
	 * present (the available_translations site transient) — never a
	 * network call at boot; unknown codes fall back to the code itself.
	 */
	public static function available_languages() {
		$translations = get_site_transient( 'available_translations' );
		$out          = array( array( '', __( 'Site default', 'minn-admin' ) ) );
		foreach ( array_unique( array_merge( array( 'en_US' ), get_available_languages() ) ) as $code ) {
			if ( 'en_US' === $code ) {
				$label = 'English (United States)';
			} elseif ( is_array( $translations ) && isset( $translations[ $code ]['native_name'] ) ) {
				$label = $translations[ $code ]['native_name'];
			} else {
				$label = $code;
			}
			$out[] = array( $code, $label );
		}
		return $out;
	}

	/**
	 * Core refreshes a post's oEmbed caches only from the CLASSIC editor's
	 * ajax hook — REST saves never do, so a cached '{{unknown}}' failure
	 * (e.g. x.com before the provider fix) sticks forever. Refresh the
	 * caches whenever a post is saved through REST.
	 */
	public static function register_oembed_refresh() {
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type ) {
			add_action( 'rest_after_insert_' . $type->name, array( __CLASS__, 'refresh_oembed_cache' ) );
		}
	}

	public static function refresh_oembed_cache( $post ) {
		if ( empty( $post->ID ) || empty( $GLOBALS['wp_embed'] ) ) {
			return;
		}
		// Drop stale failure caches so cache_oembed refetches them.
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( 0 === strpos( $key, '_oembed_' ) && in_array( '{{unknown}}', $values, true ) ) {
				delete_post_meta( $post->ID, $key );
				delete_post_meta( $post->ID, str_replace( '_oembed_', '_oembed_time_', $key ) );
			}
		}
		$GLOBALS['wp_embed']->cache_oembed( $post->ID );
	}

	/**
	 * WordPress 7.0 ships oEmbed providers for twitter.com but not x.com, so
	 * x.com links resolve only through discovery — which the security filter
	 * (wp_filter_oembed_result) treats as untrusted and strips, because tweet
	 * embeds carry no iframe. Register x.com against its own publish endpoint
	 * so tweets embed like they used to, until core catches up.
	 */
	public static function register_x_oembed() {
		if ( ! function_exists( 'wp_oembed_get' ) ) {
			return;
		}
		$oembed = _wp_oembed_get_object();
		// Probe with a representative URL — core (or another plugin) may
		// already cover x.com. (Never substring-match the provider list:
		// mixcloud\.com contains "x\.com".)
		if ( false !== $oembed->get_provider( 'https://x.com/wordpress/status/1', array( 'discover' => false ) ) ) {
			return;
		}
		wp_oembed_add_provider( '#https?://(www\\.)?x\\.com/\\w{1,15}/status(es)?/.*#i', 'https://publish.x.com/oembed', true );
		wp_oembed_add_provider( '#https?://(www\\.)?x\\.com/\\w{1,15}$#i', 'https://publish.x.com/oembed', true );
	}

	/**
	 * Site-wide role policies for the admin experience (option
	 * minn_admin_role_defaults). Shape: role => array(
	 *   'signin'  => 'minn',                // always land in Minn after sign-in
	 *   'toolbar' => 'minn' | 'wp' | 'off', // which toolbar the role gets on the site
	 * ). An absent key means the person chooses; only enforced values are
	 * stored, so an empty option leaves every profile switch behaving as before.
	 * Enforcement is an overlay resolved at read time: nobody's saved
	 * preference is written over, and a role returned to "person chooses"
	 * hands each account its previous choice back.
	 */
	public static function role_defaults() {
		$raw = get_option( 'minn_admin_role_defaults', array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $role => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$entry = array();
			if ( isset( $p['signin'] ) && 'minn' === $p['signin'] ) {
				$entry['signin'] = 'minn';
			}
			if ( isset( $p['toolbar'] ) && in_array( $p['toolbar'], array( 'minn', 'wp', 'off' ), true ) ) {
				$entry['toolbar'] = $p['toolbar'];
			}
			if ( $entry ) {
				$out[ (string) $role ] = $entry;
			}
		}
		return $out;
	}

	/**
	 * The enforced policy for one user, resolved across their roles. Values
	 * are '' when the person chooses. A user holding several roles takes any
	 * enforced sign-in policy, and the strongest toolbar policy
	 * (minn > wp > off): when one role grants the Minn bar and another hides
	 * the toolbar, the grant wins. Minn enforcement needs Minn access, so a
	 * policy saved against a role that later loses edit_posts falls back to
	 * personal choice instead of stranding the account.
	 */
	public static function policy_for_user( $user_id = 0 ) {
		$uid = $user_id ? (int) $user_id : get_current_user_id();
		$out = array(
			'signin'  => '',
			'toolbar' => '',
		);
		if ( $uid <= 0 ) {
			return $out;
		}
		$defaults = self::role_defaults();
		if ( ! $defaults ) {
			return $out;
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return $out;
		}
		$rank = array(
			'minn' => 3,
			'wp'   => 2,
			'off'  => 1,
		);
		foreach ( (array) $user->roles as $role ) {
			if ( empty( $defaults[ $role ] ) ) {
				continue;
			}
			$p = $defaults[ $role ];
			if ( isset( $p['signin'] ) ) {
				$out['signin'] = 'minn';
			}
			if ( isset( $p['toolbar'] ) && ( '' === $out['toolbar'] || $rank[ $p['toolbar'] ] > $rank[ $out['toolbar'] ] ) ) {
				$out['toolbar'] = $p['toolbar'];
			}
		}
		if ( 'minn' === $out['signin'] && ! user_can( $user, 'edit_posts' ) ) {
			$out['signin'] = '';
		}
		if ( 'minn' === $out['toolbar'] && ! user_can( $user, 'edit_posts' ) ) {
			$out['toolbar'] = '';
		}
		return $out;
	}

	/**
	 * A 'wp' or 'off' toolbar policy overrides the user's own toolbar
	 * preference on the front end. 'minn' passes through: the Minn bar's own
	 * gate (priority 100) suppresses the core bar when it renders.
	 */
	public static function enforce_toolbar_policy( $show ) {
		if ( ! is_user_logged_in() ) {
			return $show;
		}
		$policy = self::policy_for_user();
		if ( 'wp' === $policy['toolbar'] ) {
			return true;
		}
		if ( 'off' === $policy['toolbar'] ) {
			return false;
		}
		return $show;
	}

	/**
	 * Per-user "Minn is the default admin" (stored on minn_admin_appearance).
	 * Falls back to the legacy site option until the user saves a profile
	 * preference. A role policy of 'minn' wins over the personal preference
	 * without overwriting it.
	 */
	public static function user_wants_default_admin( $user_id = 0 ) {
		$uid = $user_id ? (int) $user_id : get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		$policy = self::policy_for_user( $uid );
		if ( 'minn' === $policy['signin'] ) {
			return true;
		}
		$ap = self::get_user_appearance( $uid );
		return ! empty( $ap['defaultAdmin'] );
	}

	/**
	 * Per-user front-end Minn admin bar opt-in (minn_admin_appearance.frontBar).
	 * A toolbar role policy overlays the preference: 'minn' forces the bar on,
	 * 'wp' and 'off' force it off.
	 */
	public static function user_wants_front_bar( $user_id = 0 ) {
		$uid = $user_id ? (int) $user_id : get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		$policy = self::policy_for_user( $uid );
		if ( 'minn' === $policy['toolbar'] ) {
			return true;
		}
		if ( 'wp' === $policy['toolbar'] || 'off' === $policy['toolbar'] ) {
			return false;
		}
		$ap = self::get_user_appearance( $uid );
		return ! empty( $ap['frontBar'] );
	}

	/**
	 * REST bases of the post types where this user may edit only their own
	 * items. Keyed by rest_base so the client can look one up by the route it
	 * is about to call. Values are always true; absence means "no restriction".
	 */
	public static function own_only_types() {
		$out = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $pt ) {
			$base = $pt->rest_base ? $pt->rest_base : $pt->name;
			// A type with no edit_others_* cap declared (some CPTs map every
			// cap to the same string) can't be reasoned about — leave it alone
			// rather than silently hiding other authors' rows.
			if ( empty( $pt->cap->edit_others_posts ) || empty( $pt->cap->edit_posts ) ) {
				continue;
			}
			if ( $pt->cap->edit_others_posts === $pt->cap->edit_posts ) {
				continue;
			}
			if ( ! current_user_can( $pt->cap->edit_others_posts ) ) {
				$out[ $base ] = true;
			}
		}
		return (object) $out;
	}

	/**
	 * Minn editor URL for a post, or '' when the type isn't REST-editable in Minn.
	 */
	public static function editor_url_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$pto = get_post_type_object( $post->post_type );
		if ( ! $pto || empty( $pto->show_in_rest ) ) {
			return '';
		}
		$rest_base = ! empty( $pto->rest_base ) ? $pto->rest_base : $post->post_type;
		$path      = 'editor/' . rawurlencode( $rest_base ) . '/' . (int) $post->ID;
		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( self::app_url() ) . $path;
		}
		// Plain permalinks: app boots via ?minn_admin=1, route rides the hash.
		return self::app_url() . '#/' . $path;
	}

	/**
	 * "Minn is the default admin" (per user): after signing in, land in Minn
	 * instead of the wp-admin dashboard. Only takes over the DEFAULT landing —
	 * an explicit redirect_to deep link still wins, and users who can't use
	 * Minn (no edit_posts) keep core behavior.
	 */
	/**
	 * App URL for an in-app route (`menus`, `editor/pages/12`). Honors the
	 * pretty-permalink vs hash-routing split that editor_url_for_post uses.
	 */
	public static function app_route_url( $route ) {
		$route = ltrim( (string) $route, '/' );
		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( self::app_url() ) . $route;
		}
		return self::app_url() . '#/' . $route;
	}

	/**
	 * Front-end links to screens Minn actually has go to Minn when this user
	 * treats Minn as the default admin. Beaver Builder's empty-menu
	 * "Choose Menu" is the reported case (`admin_url( 'nav-menus.php' )`);
	 * the same rewrite covers Divi and Bricks fallbacks. wp-admin and REST
	 * keep the classic URL so those screens stay usable.
	 */
	public static function default_admin_url( $url, $path ) {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || wp_doing_cron() ) {
			return $url;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $url;
		}
		// Pluggable helpers are not loaded while plugins bootstrap. A plugin
		// that builds admin URLs from its constructor (WPMU DEV Dashboard)
		// would otherwise fatal here on every WP-CLI command.
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return $url;
		}
		if ( ! self::user_wants_default_admin() ) {
			return $url;
		}
		$path = ltrim( (string) $path, '/' );
		if ( 0 !== strpos( $path, 'nav-menus.php' ) ) {
			return $url;
		}
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'edit_theme_options' ) || wp_is_block_theme() ) {
			return $url;
		}
		return self::app_route_url( 'menus' );
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! ( $user instanceof WP_User ) || ! $user->has_cap( 'edit_posts' ) ) {
			return $redirect_to;
		}
		if ( ! self::user_wants_default_admin( $user->ID ) ) {
			return $redirect_to;
		}
		$default_targets = array( '', admin_url(), admin_url( 'index.php' ) );
		if ( in_array( (string) $requested, $default_targets, true ) ) {
			return self::app_url();
		}
		return $redirect_to;
	}

	public static function register_route() {
		// Catch-all so app routes like /minn-admin/content resolve to the SPA.
		add_rewrite_rule( '^minn-admin(/.*)?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	/**
	 * Self-healing rewrites: any site where Minn runs but the compiled rules
	 * lack its route gets one soft flush. Covers the multisite cases the
	 * activation hook cannot reach — network activation only flushes the site
	 * it runs in, and subsites that existed before activation keep serving
	 * 404 at /minn-admin/ until their rules are rebuilt. An empty rules
	 * option is left alone: core regenerates it lazily on this same request,
	 * with the route included.
	 */
	public static function maybe_heal_rewrites() {
		if ( ! get_option( 'permalink_structure' ) ) {
			return; // Plain permalinks: the ?minn_admin=1 fallback is the route.
		}
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) || ! $rules || isset( $rules['^minn-admin(/.*)?$'] ) ) {
			return;
		}
		// Latch it. This runs on wp_loaded for EVERY request, including
		// unauthenticated front-end hits, so on a site where the rule cannot
		// persist — another plugin filtering it out of rewrite_rules_array, a
		// read-only or racing options store — the condition never clears and
		// every uncached request rebuilds the whole ruleset and rewrites an
		// autoloaded option. One attempt per version is enough to heal the
		// case this exists for; anything else degrades to a 404 at
		// /minn-admin/, which is recoverable from the permalinks screen.
		if ( get_option( 'minn_admin_rewrites_healed' ) === MINN_ADMIN_VERSION ) {
			return;
		}
		update_option( 'minn_admin_rewrites_healed', MINN_ADMIN_VERSION, false );
		flush_rewrite_rules( false );
	}

	/**
	 * Drop every site's compiled rewrite rules so each rebuilds them lazily
	 * on its next request — with Minn's route when the plugin is active
	 * there, without it when not. Deleting the option (rather than flushing
	 * under switch_to_blog) sidesteps WP_Rewrite's unreliability in switched
	 * contexts and keeps network activation/deactivation O(sites) cheap.
	 */
	public static function invalidate_network_rewrites() {
		if ( ! is_multisite() || wp_is_large_network() ) {
			return;
		}
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 10000,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			delete_option( 'rewrite_rules' );
			restore_current_blog();
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Expose extra options over the core /wp/v2/settings endpoint so the
	 * Settings view can read/write them.
	 */
	public static function register_settings() {
		register_setting(
			'reading',
			'blog_public',
			array(
				'show_in_rest' => true,
				'type'         => 'integer',
				'default'      => 1,
			)
		);
		register_setting(
			'minn_admin',
			'minn_admin_maintenance',
			array(
				'show_in_rest' => true,
				'type'         => 'boolean',
				'default'      => false,
			)
		);
		// Legacy site option — no longer used (default admin is per-user opt-in
		// on minn_admin_appearance.defaultAdmin). Keep registered so old rows
		// don't fatals if something still reads the option.
		register_setting(
			'minn_admin',
			'minn_admin_default',
			array(
				'show_in_rest' => false,
				'type'         => 'boolean',
				'default'      => false,
			)
		);

		// wp-admin options core never put behind wp/v2/settings — same pattern
		// as blog_public above. Writes are gated by manage_options at the endpoint.
		register_setting(
			'general',
			'users_can_register',
			array(
				'show_in_rest' => true,
				'type'         => 'integer',
				'default'      => 0,
			)
		);
		register_setting(
			'general',
			'default_role',
			array(
				'show_in_rest'      => true,
				'type'              => 'string',
				'default'           => 'subscriber',
				'sanitize_callback' => function ( $value ) {
					// Only real roles; a bogus write keeps the current value.
					return wp_roles()->is_role( $value ) ? $value : get_option( 'default_role', 'subscriber' );
				},
			)
		);
		foreach ( array( 'comment_moderation', 'comment_registration' ) as $discussion_opt ) {
			register_setting(
				'discussion',
				$discussion_opt,
				array(
					'show_in_rest' => true,
					'type'         => 'integer',
					'default'      => 0,
				)
			);
		}
		register_setting(
			'discussion',
			'show_avatars',
			array(
				'show_in_rest' => true,
				'type'         => 'integer',
				'default'      => 1,
			)
		);
	}

	/**
	 * URL of the Minn Admin app.
	 */
	/**
	 * Per-user Minn UI appearance. User meta key `minn_admin_appearance`.
	 *
	 * Shape:
	 *   { scheme: 'minn'|…|'custom', custom: { dark: {slot: #hex…}, light: {…} } }
	 *
	 * Scheme slots map to CSS variables (status colors stay fixed). Soft/ring
	 * accents are derived client-side from accent.
	 */
	const APPEARANCE_META = 'minn_admin_appearance';

	/** Configurable scheme slots → CSS custom properties. */
	public static function scheme_slots() {
		return array(
			'bg'       => '--bg',
			'bg2'      => '--bg2',
			'panel'    => '--panel',
			'panel2'   => '--panel2',
			'hover'    => '--hover',
			'border'   => '--border',
			'border2'  => '--border2',
			'text'     => '--text',
			'text2'    => '--text2',
			'text3'    => '--text3',
			'accent'   => '--accent',
			'accent2'  => '--accent2',
			'accentFg' => '--accent-fg',
		);
	}

	/** Named schemes (not wp-admin color schemes). CSS owns the token maps. */
	public static function scheme_ids() {
		return array( 'minn', 'ocean', 'forest', 'amber', 'rose', 'coral', 'teal', 'slate', 'dusk' );
	}

	/**
	 * Default Minn tokens for dark/light — also the fill base for incomplete custom maps.
	 *
	 * @return array{dark:array<string,string>,light:array<string,string>}
	 */
	public static function scheme_base_tokens() {
		return array(
			'dark'  => array(
				'bg'       => '#0b0b0d',
				'bg2'      => '#101013',
				'panel'    => '#151518',
				'panel2'   => '#1b1b1f',
				'hover'    => '#202027',
				'border'   => '#242429',
				'border2'  => '#31313a',
				'text'     => '#ececed',
				'text2'    => '#9d9da7',
				'text3'    => '#63636d',
				'accent'   => '#6e62f5',
				'accent2'  => '#8a80f8',
				'accentFg' => '#ffffff',
			),
			'light' => array(
				'bg'       => '#f6f6f7',
				'bg2'      => '#ffffff',
				'panel'    => '#ffffff',
				'panel2'   => '#f4f4f6',
				'hover'    => '#eeeef1',
				'border'   => '#e7e7ea',
				'border2'  => '#dadade',
				'text'     => '#1a1a1f',
				'text2'    => '#5e5e69',
				'text3'    => '#9696a0',
				'accent'   => '#6a5ef2',
				'accent2'  => '#5a4ef0',
				'accentFg' => '#ffffff',
			),
		);
	}

	public static function appearance_defaults() {
		return array(
			'scheme'       => 'minn',
			'custom'       => self::scheme_base_tokens(),
			// Opt-in only — never seed from the old site option.
			'defaultAdmin' => false,
			// Front-end Minn admin bar (replaces the classic bar on the
			// public site for this user). Opt-in only.
			'frontBar'     => false,
		);
	}

	/**
	 * Sanitize a single #rgb / #rrggbb value → lowercase #rrggbb or ''.
	 */
	public static function sanitize_hex_color( $hex ) {
		$hex = strtolower( trim( (string) $hex ) );
		if ( ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $hex ) ) {
			return '';
		}
		if ( 4 === strlen( $hex ) ) {
			return '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
		}
		return $hex;
	}

	/**
	 * Merge a partial slot map onto the Minn base for one mode.
	 *
	 * @param array  $partial Raw slot => hex map.
	 * @param string $mode    dark|light
	 * @return array<string,string>
	 */
	public static function normalize_scheme_tokens( $partial, $mode ) {
		$base  = self::scheme_base_tokens();
		$mode  = ( 'light' === $mode ) ? 'light' : 'dark';
		$out   = $base[ $mode ];
		$slots = array_keys( self::scheme_slots() );
		if ( ! is_array( $partial ) ) {
			return $out;
		}
		foreach ( $slots as $slot ) {
			if ( empty( $partial[ $slot ] ) ) {
				continue;
			}
			$hex = self::sanitize_hex_color( $partial[ $slot ] );
			if ( $hex ) {
				$out[ $slot ] = $hex;
			}
		}
		return $out;
	}

	/**
	 * Normalize appearance meta / REST body. Migrates legacy {accent,custom:#hex}.
	 *
	 * @param mixed $raw User meta value or request params.
	 * @return array{scheme:string,custom:array{dark:array,light:array}}
	 */
	public static function normalize_appearance( $raw ) {
		$defaults = self::appearance_defaults();
		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		// Legacy v1: { accent: preset|custom, custom: '#hex' }.
		if ( ! isset( $raw['scheme'] ) && isset( $raw['accent'] ) ) {
			$accent = sanitize_key( (string) $raw['accent'] );
			$ids    = self::scheme_ids();
			if ( 'custom' === $accent ) {
				$hex = self::sanitize_hex_color( isset( $raw['custom'] ) ? $raw['custom'] : '' );
				$custom = $defaults['custom'];
				if ( $hex ) {
					// Seed both modes from Minn, swap brand accents only.
					foreach ( array( 'dark', 'light' ) as $mode ) {
						$custom[ $mode ]['accent'] = $hex;
						// Mild second tone: leave accent2 as base unless light needs darker.
						if ( 'light' === $mode ) {
							$custom[ $mode ]['accent2'] = $hex;
						} else {
							$custom[ $mode ]['accent2'] = $hex;
						}
					}
				}
				return array(
					'scheme'       => $hex ? 'custom' : 'minn',
					'custom'       => $custom,
					'defaultAdmin' => false,
					'frontBar'     => false,
				);
			}
			if ( in_array( $accent, $ids, true ) ) {
				return array(
					'scheme'       => $accent,
					'custom'       => $defaults['custom'],
					'defaultAdmin' => false,
					'frontBar'     => false,
				);
			}
			return $defaults;
		}

		$ids    = self::scheme_ids();
		$scheme = isset( $raw['scheme'] ) ? sanitize_key( (string) $raw['scheme'] ) : 'minn';
		if ( 'custom' !== $scheme && ! in_array( $scheme, $ids, true ) ) {
			$scheme = 'minn';
		}

		$custom_in = isset( $raw['custom'] ) && is_array( $raw['custom'] ) ? $raw['custom'] : array();
		// Legacy custom was a string hex — ignore here (handled above).
		if ( ! is_array( $custom_in ) ) {
			$custom_in = array();
		}
		$custom = array(
			'dark'  => self::normalize_scheme_tokens( isset( $custom_in['dark'] ) ? $custom_in['dark'] : array(), 'dark' ),
			'light' => self::normalize_scheme_tokens( isset( $custom_in['light'] ) ? $custom_in['light'] : array(), 'light' ),
		);

		// Opt-in only: true only when the user explicitly saved defaultAdmin.
		$default_admin = array_key_exists( 'defaultAdmin', $raw )
			&& ! empty( $raw['defaultAdmin'] )
			&& '0' !== (string) $raw['defaultAdmin']
			&& 'false' !== (string) $raw['defaultAdmin'];

		$front_bar = array_key_exists( 'frontBar', $raw )
			&& ! empty( $raw['frontBar'] )
			&& '0' !== (string) $raw['frontBar']
			&& 'false' !== (string) $raw['frontBar'];

		return array(
			'scheme'       => $scheme,
			'custom'       => $custom,
			'defaultAdmin' => $default_admin,
			'frontBar'     => $front_bar,
		);
	}

	public static function get_user_appearance( $user_id = 0 ) {
		$uid = $user_id ? (int) $user_id : get_current_user_id();
		if ( $uid <= 0 ) {
			return self::appearance_defaults();
		}
		return self::normalize_appearance( get_user_meta( $uid, self::APPEARANCE_META, true ) );
	}

	public static function save_user_appearance( $user_id, $raw ) {
		$uid  = (int) $user_id;
		$norm = self::normalize_appearance( $raw );
		update_user_meta( $uid, self::APPEARANCE_META, $norm );
		return $norm;
	}

	/**
	 * Whether the site permits dashboard-driven code editing.
	 *
	 * DISALLOW_FILE_EDIT is the directive a site owner sets to stop anyone,
	 * administrators included, reaching PHP on disk from the dashboard; core
	 * folds it into edit_files via map_meta_cap. DISALLOW_FILE_MODS is the
	 * broader "no filesystem changes" switch. Minn is presented as the whole
	 * admin surface, so a hardened wp-config must describe what Minn will do
	 * too, not just what wp-admin will.
	 *
	 * Deliberately consulted only where code is written or executed: the
	 * wp-config writer and PHP snippet authoring. Ordinary content and
	 * stylesheet editing are not file editing.
	 */
	public static function code_edits_allowed() {
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return false;
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether the caller owns network-shared state.
	 *
	 * A plugin that keeps ONE store for the whole network — a backup archive
	 * directory, a base_prefix table, a site option — holds every tenant's
	 * data in it, so on multisite that is the network owner's, whatever
	 * per-site capability the plugin's own screens happen to ask for. The
	 * capability alone can never draw this line: core's multisite pass over
	 * map_meta_cap strips install_plugins, update_plugins and edit_files from
	 * a subsite administrator but leaves export, manage_options and every
	 * plugin-defined capability untouched. Most of these plugins say the same
	 * thing themselves by registering their admin under network_admin_menu,
	 * which shows a subsite administrator nothing at all.
	 *
	 * Call this in the adapter's *_active() check AND in every route's
	 * permission callback: an adapter whose surface is hidden but whose routes
	 * still answer is not gated, it is only quiet.
	 */
	public static function network_owner() {
		return ! is_multisite() || is_super_admin();
	}

	/**
	 * The capability meaning "may change settings", widened to the network.
	 *
	 * manage_options is PER SITE on multisite, so it is the wrong gate for
	 * anything the whole network shares. Use this where the state is
	 * site-wide-or-broader; use network_owner() where a per-site capability
	 * still has to be checked alongside it.
	 */
	public static function manage_cap() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	public static function app_url() {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/minn-admin/' );
		}
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Multisite: the sites this user can open Minn on — the switcher's model
	 * (core's own admin-bar "My Sites" membership, narrowed to sites where
	 * the app would actually let them in).
	 *
	 * A site qualifies only when Minn is active there (network-wide or on
	 * that site) AND the user clears the same `edit_posts` gate the app's
	 * route enforces, so every entry leads somewhere usable rather than to a
	 * "not allowed" page or a 404. Each site's URL is read in ITS OWN
	 * context, because permalink structure (and therefore whether the app
	 * lives at /minn-admin/ or ?minn_admin=1) is per site.
	 *
	 * Returns [] off multisite, on large networks (get_blogs_of_user is not
	 * a query to run there), and when only the current site qualifies — the
	 * client hides the switcher on an empty list, so a one-site membership
	 * never renders a menu with nothing to switch to.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	const SITES_MENU_LIMIT = 8;

	/**
	 * The blog ids this user belongs to, newest membership last, or an empty
	 * array off multisite. One query; no per-site work.
	 *
	 * @return int[]
	 */
	/**
	 * Site visibility as the CURRENT user is allowed to see it.
	 *
	 * manage_options gets the full picture, the same as the REST route.
	 * Everyone else gets the state and the search-engine flag, which is what
	 * the banner renders, with the provider identities stripped: which
	 * maintenance, coming-soon or password plugin a site runs is
	 * reconnaissance, and it is not something a lower tier can act on anyway.
	 *
	 * @return array|null
	 */
	public static function visibility_for_current_user() {
		$v = minn_admin_site_visibility();
		if ( ! is_array( $v ) || current_user_can( 'manage_options' ) ) {
			return $v;
		}
		$v['providers'] = array();
		unset( $v['toggles'] );
		return $v;
	}

	public static function user_site_ids() {
		if ( ! is_multisite() ) {
			return array();
		}
		// The switcher's docblock promises this, and it was never implemented:
		// get_blogs_of_user() is an unbounded usermeta scan, which core's own
		// large-network threshold exists to keep off the hot path.
		if ( wp_is_large_network() ) {
			return array();
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return array();
		}
		$blogs = get_blogs_of_user( $uid );
		if ( ! is_array( $blogs ) ) {
			return array();
		}
		return array_map( 'intval', wp_list_pluck( $blogs, 'userblog_id' ) );
	}

	/**
	 * Describe specific sites for the switcher: name, address, and the app
	 * URL to jump to.
	 *
	 * Reading a site's name and permalink structure means entering its
	 * context, so this is O(sites passed in) and callers MUST pass a bounded
	 * page — never a whole network's worth of ids. That is why the boot
	 * payload caps at SITES_MENU_LIMIT and search is paginated: a network can
	 * have thousands of sites, and neither a page load nor a menu can carry
	 * them.
	 *
	 * A site is included only when Minn is active there and the user clears
	 * the app's own `edit_posts` gate, so every entry leads somewhere usable.
	 *
	 * @param int[] $ids Bounded list of blog ids.
	 * @return array<int, array<string, mixed>>
	 */
	public static function describe_sites( $ids ) {
		$uid = get_current_user_id();
		if ( ! $uid || ! $ids ) {
			return array();
		}
		$current = get_current_blog_id();
		$plugin  = plugin_basename( MINN_ADMIN_FILE );
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		$net_on  = isset( $network[ $plugin ] );
		$out     = array();
		foreach ( $ids as $blog_id ) {
			$blog_id = (int) $blog_id;
			switch_to_blog( $blog_id );
			$active = $net_on || in_array( $plugin, (array) get_option( 'active_plugins', array() ), true );
			if ( $active && user_can( $uid, 'edit_posts' ) ) {
				$out[] = array(
					'id'      => $blog_id,
					'name'    => self::plain_text( get_bloginfo( 'name' ) ),
					'url'     => home_url( '/' ),
					'app'     => self::app_url(),
					'current' => $blog_id === $current,
				);
			}
			restore_current_blog();
		}
		usort(
			$out,
			function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);
		return $out;
	}

	/**
	 * Multisite: the switcher's boot model — a CAPPED page of the sites this
	 * user can open Minn on, always including the one they are standing in,
	 * plus the true total so the client knows when to offer search instead of
	 * a longer menu.
	 *
	 * Returns an empty list when only one site qualifies (a menu with nothing
	 * to switch to is worse than no control) and off multisite.
	 *
	 * @return array{sites: array, total: int}
	 */
	public static function user_sites_payload() {
		$ids = self::user_site_ids();
		if ( count( $ids ) < 2 ) {
			return array( 'sites' => array(), 'total' => 0 );
		}
		$current = get_current_blog_id();
		// The current site always earns a slot; the rest fill the cap in id
		// order, and the describe pass sorts what survives by name.
		$page = array_slice( array_values( array_diff( $ids, array( $current ) ) ), 0, self::SITES_MENU_LIMIT - 1 );
		if ( in_array( $current, $ids, true ) ) {
			array_unshift( $page, $current );
		}
		$sites = self::describe_sites( $page );
		if ( count( $sites ) < 2 ) {
			return array( 'sites' => array(), 'total' => 0 );
		}
		// `total` counts memberships, not qualifying sites: resolving that
		// exactly would cost the per-site work the cap exists to avoid. It
		// drives one decision only — whether to offer search — and erring
		// toward offering it is the safe direction.
		return array( 'sites' => $sites, 'total' => count( $ids ) );
	}

	/**
	 * A display string as plain text. Labels and names are HTML-context by
	 * WordPress convention — translators and plugins legitimately put
	 * &#039;, &amp; or &nbsp; in them because wp-admin prints them as HTML.
	 * Minn renders text, so decode before sending; the client re-escapes.
	 */
	public static function plain_text( $s ) {
		return html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
	}

	public static function admin_bar_link( $bar ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		// Always a hard link into the Minn app (never "Edit in Minn Admin").
		$bar->add_node(
			array(
				'id'    => 'minn-admin',
				'title' => __( 'Minn Admin', 'minn-admin' ),
				'href'  => self::app_url(),
			)
		);

		// Only the admin-bar Edit Post/Page item is retargeted when this user
		// prefers Minn as default admin. wp-admin list tables and other
		// get_edit_post_link() consumers stay classic so wp-admin remains usable.
		if ( ! self::user_wants_default_admin() ) {
			return;
		}
		$edit = $bar->get_node( 'edit' );
		if ( ! $edit || empty( $edit->href ) ) {
			return;
		}
		// Front-end singular: current post. In wp-admin post.php, the edit node
		// is the current screen's post.
		$post_id = 0;
		if ( ! is_admin() && is_singular() ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Post ) {
				$post_id = (int) $obj->ID;
			}
		} elseif ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base && ! empty( $GLOBALS['post'] ) ) {
				$post_id = (int) $GLOBALS['post']->ID;
			} elseif ( ! empty( $_GET['post'] ) ) {
				$post_id = (int) $_GET['post'];
			}
		}
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$minn = self::editor_url_for_post( $post_id );
		if ( ! $minn ) {
			return;
		}
		$bar->add_node(
			array(
				'id'   => 'edit',
				'href' => $minn,
			)
		);
	}

	public static function admin_menu() {
		add_menu_page(
			'Minn Admin',
			'Minn Admin',
			'edit_posts',
			'minn-admin',
			function () {
				printf(
					'<script>window.location.href = %s;</script><p><a href="%s">%s</a></p>',
					// JSON_HEX_TAG or a home_url containing </script> closes the
					// element and everything after it parses as HTML. Same flag
					// set the app shell uses in template.php.
					wp_json_encode( self::app_url(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
					esc_url( self::app_url() ),
					esc_html__( 'Open Minn Admin', 'minn-admin' )
				);
			},
			'dashicons-superhero-alt',
			2
		);
	}

	/**
	 * Simple maintenance mode: show a 503 holding page to visitors when enabled.
	 */
	public static function maybe_maintenance_mode() {
		if ( ! get_option( 'minn_admin_maintenance' ) ) {
			return;
		}
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return;
		}
		status_header( 503 );
		header( 'Retry-After: 3600' );
		$title = esc_html( get_bloginfo( 'name' ) );
		/* translators: %s: the site title. */
		$page_title = esc_html( sprintf( __( '%s — Coming soon', 'minn-admin' ), get_bloginfo( 'name' ) ) );
		$message    = esc_html__( 'We’re making some improvements. Back soon.', 'minn-admin' );
		echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'><title>{$page_title}</title><style>body{font-family:system-ui,sans-serif;background:#0b0b0d;color:#ececed;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}div{text-align:center}h1{font-size:22px;letter-spacing:-0.3px}p{color:#9d9da7}</style></head><body><div><h1>{$title}</h1><p>{$message}</p></div></body></html>";
		exit;
	}

	/**
	 * Serve the Minn Admin app at /minn-admin/.
	 */
	public static function maybe_render_app() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			auth_redirect();
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access Minn Admin.', 'minn-admin' ), 403 );
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex' );

		$boot = self::boot_payload();

		include MINN_ADMIN_DIR . 'includes/template.php';
		exit;
	}

	/**
	 * The `window.MINN` boot payload.
	 *
	 * Extracted from maybe_render_app() so the locale slice can be rebuilt on
	 * demand: switching language without a reload means re-deriving exactly
	 * what the server rendered the first time, and a second hand-maintained
	 * copy of these keys would drift the moment either side gained one.
	 *
	 * @return array
	 */
	public static function boot_payload() {
		$user  = wp_get_current_user();
		$roles = array_values( $user->roles );
		$role  = $roles ? wp_roles()->role_names[ $roles[0] ] ?? $roles[0] : '';

		// Raw forms drive insertable_blocks' candidacy (so its shared render
		// probe caches per SITE, not per user); the per-user-filtered copy is
		// only the descriptor payload sent to the client. See insertable_blocks.
		$raw_block_forms = apply_filters( 'minn_admin_block_forms', array() );
		$block_forms     = self::filter_block_forms( $raw_block_forms );
		$sites_payload   = self::user_sites_payload();

		$boot = array(
			'restUrl'  => esc_url_raw( rest_url() ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'appUrl'   => self::app_url(),
			'version'  => MINN_ADMIN_VERSION,
			'user'     => array(
				'id'         => $user->ID,
				'login'      => $user->user_login,
				'name'       => $user->display_name,
				'role'       => translate_user_role( $role ),
				'avatar'     => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
				// Per-user color scheme (user meta minn_admin_appearance).
				'appearance' => self::get_user_appearance( $user->ID ),
				// Role-enforced admin-experience policy ('' = person chooses).
				// The profile page swaps enforced switches for a locked note.
				'policy'     => self::policy_for_user( $user->ID ),
			),
			// Scheme slot metadata for the profile custom editor (key + CSS var + label).
			'appearanceSlots' => array_map(
				function ( $css, $key ) {
					// Shown in Your profile's colour-scheme editor, one label
					// per swatch, so they are interface text like any other.
					$labels = array(
						'bg'       => __( 'Background', 'minn-admin' ),
						'bg2'      => __( 'Background elevated', 'minn-admin' ),
						'panel'    => __( 'Panel', 'minn-admin' ),
						'panel2'   => __( 'Panel elevated', 'minn-admin' ),
						'hover'    => __( 'Hover', 'minn-admin' ),
						'border'   => __( 'Border', 'minn-admin' ),
						'border2'  => __( 'Border strong', 'minn-admin' ),
						'text'     => __( 'Text', 'minn-admin' ),
						'text2'    => __( 'Text secondary', 'minn-admin' ),
						'text3'    => __( 'Text muted', 'minn-admin' ),
						'accent'   => __( 'Accent', 'minn-admin' ),
						'accent2'  => __( 'Accent hover / links', 'minn-admin' ),
						'accentFg' => __( 'Text on accent', 'minn-admin' ),
					);
					return array(
						'key'   => $key,
						'css'   => $css,
						'label' => isset( $labels[ $key ] ) ? $labels[ $key ] : $key,
					);
				},
				array_values( self::scheme_slots() ),
				array_keys( self::scheme_slots() )
			),
			'site'     => array(
				'name'       => get_bloginfo( 'name' ),
				// The WordPress Site Icon (Settings → Site icon), used as the
				// sidebar mark when set; the client falls back to the Minn
				// "m" tile. '' when no icon is configured.
				'icon'       => get_site_icon_url( 64 ),
				'url'        => home_url( '/' ),
				'adminUrl'   => admin_url(),
				// Multisite + super admin only: honest link-outs to the
				// network screens Minn does not cover (site/user deletion,
				// network plugins). Absent otherwise — entries gate on it.
				'networkAdminUrl' => is_multisite() && current_user_can( 'manage_network' ) ? network_admin_url() : '',
				'logout'     => str_replace( '&amp;', '&', wp_logout_url( home_url( '/' ) ) ),
				// Block themes manage navigation/widgets in the site editor, so
				// Minn (like wp-admin) only offers Menus/Widgets on classic themes.
				'blockTheme'  => wp_is_block_theme(),
				'hasSidebars' => ! empty( $GLOBALS['wp_registered_sidebars'] ),
			),
			// Hours east of UTC (may be fractional, e.g. +5.5). Used by the
			// client to parse WP REST site-local dates (no zone suffix) so
			// timeAgo / tooltips aren't skewed by gmt_offset.
			'gmtOffset' => (float) get_option( 'gmt_offset' ),
			// SPA translation map for the client's __()/_n() (empty for
			// English; object cast so an empty map serializes as {}).
			// The reader's effective locale and writing direction. The client
			// keeps these to tell whether a language save actually changed
			// anything FOR THEM — a site-language change leaves a user with a
			// personal override exactly where they were, and repainting the
			// app in that case would be a visible no-op with a real cost.
			'locale'   => get_user_locale(),
			'rtl'      => (bool) is_rtl(),
			'i18n'     => (object) self::js_translations(),
			// The locale's Plural-Forms rule, verbatim from the catalog. The
			// client evaluates it: "n != 1" is right for English and wrong
			// for most of the world (Japanese has one form, Russian and
			// Polish three, Arabic six), so a hardcoded rule silently prints
			// the wrong plural in the majority of shipped locales.
			'i18nPlural' => self::js_plural_forms(),
			// Installed admin languages for Your profile's Language picker.
			'languages' => self::available_languages(),
			'caps'     => array(
				'plugins'      => current_user_can( 'activate_plugins' ),
				'update'       => current_user_can( 'update_plugins' ),
				'delete'       => current_user_can( 'delete_plugins' ),
				'install'      => current_user_can( 'install_plugins' ),
				'themes'       => current_user_can( 'switch_themes' ),
				'deleteThemes' => current_user_can( 'delete_themes' ),
				'updateThemes' => current_user_can( 'update_themes' ),
				// Core's own gate for the Update Translations button. On a
				// network this maps to the super admin, the same as core.
				'updateLanguages' => current_user_can( 'update_languages' ),
				'installThemes' => current_user_can( 'install_themes' ),
				'settings'     => current_user_can( 'manage_options' ),
				// Licences are network-scoped on multisite (see
				// minn_admin_licenses_can_manage): the Licenses tab would 403
				// for a subsite administrator, so don't offer it to them.
				'licenses'     => function_exists( 'minn_admin_licenses_can_manage' )
					? minn_admin_licenses_can_manage()
					: current_user_can( 'manage_options' ),
				'moderate'     => current_user_can( 'moderate_comments' ),
				'terms'        => current_user_can( 'manage_categories' ),
				'upload'       => current_user_can( 'upload_files' ),
				'users'        => current_user_can( 'list_users' ),
				'readPrivate'  => current_user_can( 'read_private_posts' ),
				'editPages'    => current_user_can( 'edit_pages' ),
				'createUsers'  => current_user_can( 'create_users' ),
				'editUsers'    => current_user_can( 'edit_users' ),
				'promoteUsers' => current_user_can( 'promote_users' ),
				'deleteUsers'  => current_user_can( 'delete_users' ),
				'orders'       => class_exists( 'WooCommerce' ) && current_user_can( 'edit_shop_orders' ),
				'products'     => class_exists( 'WooCommerce' ) && current_user_can( 'edit_products' ),
				// Coupons only when WC has them enabled (Settings → General →
				// Enable coupons). When off, shop_coupon is not registered and
				// wc/v3/coupons always 403s "cannot list resources" even for admins.
				'coupons'      => class_exists( 'WooCommerce' )
					&& ( ! function_exists( 'wc_coupons_enabled' ) || wc_coupons_enabled() )
					&& post_type_exists( 'shop_coupon' )
					&& current_user_can( 'edit_shop_coupons' ),
				// Customers REST is manage_woocommerce-gated in WC; shop managers
				// who can edit orders also get the list (read) when that cap holds.
				'customers'    => class_exists( 'WooCommerce' ) && (
					current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' )
				),
				// WooCommerce Subscriptions — same order cap; routes only exist
				// while the extension is active (B.wcs).
				'subscriptions' => class_exists( 'WooCommerce' )
					&& class_exists( 'WC_Subscriptions' )
					&& current_user_can( 'edit_shop_orders' ),
				'themeOptions' => current_user_can( 'edit_theme_options' ),
				'core'         => current_user_can( 'update_core' ),
				// Multisite-only: "Remove from this site" (per-site
				// membership). Deletion stays a Network Admin job there.
				'removeUsers'  => is_multisite() && current_user_can( 'remove_users' ),
				// Network-wide activation of plugins and themes (super admin).
				'networkPlugins' => is_multisite() && current_user_can( 'manage_network_plugins' ),
				'networkThemes'  => is_multisite() && current_user_can( 'manage_network_themes' ),
				// Drives Settings → Design (Additional CSS). Core maps this
				// from unfiltered_html; multisite keeps it super-admin-only.
				'editCss'      => current_user_can( 'edit_css' ),
			),
			// Post types (by REST base) whose OTHER authors' items this user
			// cannot edit. wp/v2/<type>?context=edit drops those rows from the
			// body but still counts them in X-WP-Total, so an author would see
			// "16 items" above their own two. The client scopes those requests
			// to author=<me> instead, which makes header and body agree.
			// Empty for anyone holding edit_others_* everywhere (admins).
			'ownOnly'  => self::own_only_types(),
			// Multisite context: users are network-shared (delete becomes
			// remove-from-site, profile edits need network caps), plugins can
			// be network-activated. Client views branch on this.
			'multisite' => is_multisite(),
			// The sites this user can open Minn on: a CAPPED page for the
			// switcher plus the membership total, so a network with thousands
			// of sites costs a page load nothing and offers search instead of
			// an unusable menu. Empty off multisite and for single-site users.
			'sites'     => $sites_payload['sites'],
			'sitesTotal' => $sites_payload['total'],
			'wc'       => class_exists( 'WooCommerce' ),
			// WooCommerce Subscriptions extension (wc/v3/subscriptions REST).
			'wcs'      => class_exists( 'WooCommerce' ) && class_exists( 'WC_Subscriptions' ),
			// Order status labels keyed by REST slug (WC stores them 'wc-'
			// prefixed). WC owns this vocabulary, translations and all, and
			// plugins register their own statuses into it — badges and the
			// status picker read it instead of humanizing the slug.
			'wcOrderStatuses' => function_exists( 'wc_get_order_statuses' )
				? (object) array_combine(
					array_map(
						static function ( $slug ) {
							return preg_replace( '/^wc-/', '', $slug );
						},
						array_keys( wc_get_order_statuses() )
					),
					array_values( wc_get_order_statuses() )
				)
				: (object) array(),
			// WooCommerce low-stock threshold (Settings → Products → Inventory).
			// Used by the Products "Low stock" filter fallback when Analytics
			// lookup tables lag a fresh write.
			'wcLowStock' => class_exists( 'WooCommerce' )
				? max( 0, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) )
				: 0,
			// False when Disable Comments (etc.) has removed the feature —
			// Comments nav/palette/badge hide even if the user can moderate.
			'comments'  => self::comments_enabled(),
			'pretty'   => (bool) get_option( 'permalink_structure' ),
			// Site discussion defaults — new-post editor state starts from
			// these so the sidebar switches match what WP will actually store
			// (GH #6: they were hardcoded open).
			// Normalized to open/closed: wp-admin's unchecked Discussion
			// checkbox stores '' (null write bypasses sanitize_option), and
			// core treats anything other than 'open' as closed.
			'discussion' => array(
				'comments' => ( 'open' === get_option( 'default_comment_status', 'open' ) ) ? 'open' : 'closed',
				'pings'    => ( 'open' === get_option( 'default_ping_status', 'open' ) ) ? 'open' : 'closed',
			),
			'roles'    => current_user_can( 'list_users' ) ? wp_roles()->get_names() : new \stdClass(),
			'surfaces' => Minn_Admin_Surfaces::for_current_user(),
			'editorPanels' => Minn_Admin_Surfaces::editor_panels_for_current_user(),
			// Integrations this user hid (Your profile lists them for restore).
			'hidden'   => Minn_Admin_Surfaces::hidden_for_current_user(),
			// Admin-notice digest: the client triggers this nonced wp-admin
			// pageload in the background when stale; Minn extracts other
			// plugins' notices into structured data for the notification
			// panel (class-minn-admin-notices.php).
			'notices'  => array(
				'url'   => Minn_Admin_Notices::capture_url(),
				'nonce' => Minn_Admin_Notices::nonce(),
				'stale' => Minn_Admin_Notices::is_stale(),
			),
			// Admin-menu items a developer hid with remove_menu_page(),
			// mirrored into Minn's nav (last capture's view; a fresh capture
			// updates it in-session). Cosmetic, like the wp-admin original.
			'menuRemoved' => Minn_Admin_Notices::menu_removed(),
			// Ungated admin-ajax URL: core's `rest-nonce` action lives there,
			// and the client uses it to mint a fresh REST nonce in place when
			// the boot nonce expires (a tab left open past nonce lifetime).
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			// Plugin toggles ride admin-ajax (a REAL admin context) so
			// activation hooks gated on is_admin() work — see
			// ajax_plugin_status(). REST remains the client's fallback.
			'pluginAjax' => current_user_can( 'activate_plugins' ) ? array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'minn-plugin-status' ),
			) : null,
			// Active cache layers — drives the "Clear site cache" palette
			// command (adapters/cache-purge.php).
			'cache'    => current_user_can( 'manage_options' ) ? minn_admin_cache_purgers_boot() : array(),
			// Backup provider — drives the "Back up site now" palette
			// command. UpdraftPlus wins when both are active (its suite
			// and health check already own the slot); WPvivid is next.
			'backup'   => ( function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					return null;
				}
				if ( function_exists( 'minn_admin_updraftplus_active' ) && minn_admin_updraftplus_active() ) {
					return array( 'name' => 'UpdraftPlus', 'route' => 'minn-admin/v1/updraft/backup-now' );
				}
				if ( function_exists( 'minn_admin_wpvivid_active' ) && minn_admin_wpvivid_active() ) {
					return array( 'name' => 'WPvivid', 'route' => 'minn-admin/v1/wpvivid/backup-now' );
				}
				return null;
			} )(),
			// Regenerate Thumbnails present + allowed — a per-image button
			// on the media detail modal (adapters/regenerate-thumbnails.php).
			// WP Migrate present + this user may migrate — drives the
			// Migrate view (adapters/wp-migrate.php). Null otherwise, so
			// the nonce it carries never reaches a user without their cap.
			'wpMigrate' => function_exists( 'minn_admin_wp_migrate_boot' ) ? minn_admin_wp_migrate_boot() : null,
			'regenThumbs' => function_exists( 'minn_admin_regen_thumbs_available' ) && minn_admin_regen_thumbs_available(),
			// Force Regenerate Thumbnails fallback — { ajax, nonce } for its
			// own admin-ajax handler; null when RT covers it or FRT is absent.
			'frt'      => function_exists( 'minn_admin_frt_boot' ) ? minn_admin_frt_boot() : null,
			// Enable Media Replace present + allowed — a "Replace file" button
			// on the media detail modal (adapters/enable-media-replace.php).
			'mediaReplace' => function_exists( 'minn_admin_emr_available' ) && minn_admin_emr_available(),
			// Media folders provider (adapters/media-folders.php) — { name }
			// gates the folder combobox on the Media view; null without one.
			'mediaFolders' => function_exists( 'minn_admin_media_folders_boot' ) ? minn_admin_media_folders_boot() : null,
			// An SVG-enabling plugin present (Safe SVG or SVG Support) —
			// media toolbar SVG filter tab + detail note
			// (adapters/safe-svg.php). Sanitization stays the plugin's.
			'safeSvg'    => function_exists( 'minn_admin_svg_provider' ) && null !== minn_admin_svg_provider(),
			'svgProvider' => function_exists( 'minn_admin_svg_provider' ) ? minn_admin_svg_provider() : null,
			// PDF Invoices & Packing Slips — download buttons on the order
			// detail modal (adapters/wcpdf.php). Null without the plugin or
			// order access.
			'wcpdf'    => function_exists( 'minn_admin_wcpdf_boot' ) ? minn_admin_wcpdf_boot() : null,
			// One Time Login present (adapters/one-time-login.php) — a boolean
			// only; the users row menu mints the single-use link on demand so
			// the secret never rides a pageload.
			'otl'      => function_exists( 'minn_admin_otl_active' ) && minn_admin_otl_active(),
			// Public Post Preview (adapters/public-post-preview.php) — boolean
			// only; editor + content menu load/toggle the share URL on demand.
			'ppp'      => function_exists( 'minn_admin_ppp_active' ) && minn_admin_ppp_active(),
			// A User Switching session's way home (adapters/user-switching.php):
			// { name, url } of the account to switch back to, else null. The
			// plugin's own back-link lives in the admin bar Minn never renders.
			'switchBack' => function_exists( 'minn_admin_user_switching_back' ) ? minn_admin_user_switching_back() : null,
			// Disembark connector present — a boolean only: the palette's
			// "Copy backup command" fetches the command (with its token) on
			// demand rather than inlining a site secret into every pageload.
			'disembark' => current_user_can( 'manage_options' ) && minn_admin_disembark_active(),
			// WP 7.0 Connectors registry present — gates the Settings →
			// Connectors section; the section fetches minn-admin/v1/connectors
			// for the display model and saves through core's wp/v2/settings.
			'connectors' => current_user_can( 'manage_options' ) && function_exists( 'wp_get_connectors' ) && count( wp_get_connectors() ) > 0,
			// Active page builders — drives "+ New → Page in ⟨builder⟩"
			// (docs/page-builders.md; adapters/page-builders.php).
			'builders' => minn_admin_page_builders_boot(),
			// Design libraries registered via minn_admin_design_sources
			// (adapters/stackable.php, kadence.php, generateblocks.php or any
			// third-party plugin) — drive the lazy designs fetches in the
			// editor's slash menu and block picker.
			'designs'  => self::design_sources(),
			// Blocks whose images a plugin rebuilds for us (adapters answer
			// minn_admin_image_blocks) — see image_blocks() below.
			'imageBlocks' => self::image_blocks_payload(),
			/**
			 * Plugin-declared slash-menu commands (boilerplate, async inserts).
			 * See minn_admin_editor_commands / docs/for-plugin-authors.md.
			 */
			'editorCommands' => self::editor_commands(),
			/**
			 * Block-inspector form refinements, keyed by block name. A descriptor
			 * can set per-attribute label/control/options/hide, an attribute
			 * `order`, and `wrapperText` patterns for editable text in an
			 * InnerBlocks wrapper. See docs/for-plugin-authors.md.
			 */
			'blockForms' => $block_forms,
			/**
			 * Dynamic third-party blocks the editor can insert with no adapter
			 * (search-only slash-menu entries). See insertable_blocks().
			 */
			'insertBlocks' => self::insertable_blocks( $raw_block_forms ),
			/**
			 * Post formats the active theme supports (drives the editor's
			 * Format picker). Empty when the theme declares no post-format
			 * support, so Minn hides the control exactly as wp-admin does.
			 * 'standard' always leads as the default.
			 */
			'postFormats' => self::supported_post_formats(),
			/**
			 * Site visibility posture (adapters/site-status.php) — drives the
			 * Overview banner warning when a maintenance/coming-soon/password
			 * plugin or "discourage search engines" is hiding the site.
			 */
			// The /visibility ROUTE is manage_options, but this payload rides
			// the SPA's own edit_posts gate, so the two disagreed and an author
			// was handed the provider identities (which maintenance or
			// password plugin is in use) that REST withholds from them. Ship
			// the state to everyone — the banner needs it — and the provider
			// list only to the tier that can act on it.
			'visibility' => function_exists( 'minn_admin_site_visibility' )
				? self::visibility_for_current_user()
				: null,
		);

		return $boot;
	}

	/**
	 * The subset of the boot payload that changes with the reader's locale.
	 *
	 * Everything here is text the SERVER translated: the JED catalog the app
	 * itself renders from, plus labels other code already resolved through
	 * __() before the payload was built (role names, surface descriptors,
	 * post formats). None of it re-translates on the client, so a language
	 * switch has to fetch it again or the nav keeps the old language while
	 * the views change.
	 *
	 * Served by GET minn-admin/v1/boot-locale, which route_locale() has
	 * already scoped to the user's language — so simply building the payload
	 * in that request yields the new locale.
	 *
	 * @return array
	 */
	public static function locale_payload() {
		$boot = self::boot_payload();
		$keys = array(
			'i18n', 'i18nPlural', 'roles', 'surfaces', 'editorPanels', 'hidden',
			'menuRemoved', 'builders', 'designs', 'editorCommands', 'blockForms',
			'insertBlocks', 'imageBlocks', 'postFormats', 'visibility', 'languages',
			'appearanceSlots',
		);
		$out = array_intersect_key( $boot, array_flip( $keys ) );

		// Only the ROLE is taken from the user block. Sending the whole thing
		// would also send appearance, which the client may have changed since
		// boot, and patching it back would silently revert their colour scheme.
		$out['userRole'] = isset( $boot['user']['role'] ) ? $boot['user']['role'] : '';
		$out['locale']   = get_user_locale();
		$out['rtl']      = (bool) is_rtl();

		return $out;
	}

	/**
	 * Post formats the active theme supports, as { slug => label } with
	 * 'standard' first. Empty when the theme declares no post-format support
	 * (Minn then hides the editor's Format picker, matching wp-admin). The
	 * label strings come from core's get_post_format_strings().
	 *
	 * @return array<string,string>
	 */
	public static function supported_post_formats() {
		$support = get_theme_support( 'post-formats' );
		if ( ! is_array( $support ) || empty( $support[0] ) || ! is_array( $support[0] ) ) {
			return array();
		}
		$strings = get_post_format_strings(); // includes 'standard'
		$out     = array( 'standard' => isset( $strings['standard'] ) ? $strings['standard'] : 'Standard' );
		foreach ( $support[0] as $slug ) {
			$slug = sanitize_key( $slug );
			if ( '' !== $slug && isset( $strings[ $slug ] ) ) {
				$out[ $slug ] = $strings[ $slug ];
			}
		}
		return $out;
	}

	/**
	 * Design libraries offered in the editor's slash menu / block picker.
	 *
	 * Adapters (bundled or third-party) answer the `minn_admin_design_sources`
	 * filter with `id => array( 'label' => …, 'route' => … )`, registering the
	 * entry only while their plugin is active. Each route implements the pair
	 * contract: GET {route} returns `{ designs: [ { id, label, category? } ] }`
	 * (a slim list) and POST {route}/{id} returns `{ template, block? }`
	 * (insert-ready serialized block markup, images already localized).
	 * See docs/for-plugin-authors.md.
	 *
	 * @return array[] [ { id, label, route } ]
	 */
	/**
	 * A descriptor's REST route, or null when it is not one.
	 *
	 * Routes ride the boot payload into apiRes(), which attaches the REST
	 * nonce, so a descriptor naming an absolute URL would send that nonce to
	 * another host. Registries take a relative path under the site's own REST
	 * root and nothing else: the character class excludes ':' and so excludes
	 * every scheme.
	 *
	 * @param mixed $route Raw descriptor value.
	 * @return string|null Normalised route, or null to drop the entry.
	 */
	public static function rest_route_or_null( $route ) {
		if ( ! is_string( $route ) || '' === $route ) {
			return null;
		}
		$route = ltrim( $route, '/' );
		return preg_match( '/^[a-z0-9_\-\/{}]+$/i', $route ) ? $route : null;
	}

	public static function design_sources() {
		$sources = apply_filters( 'minn_admin_design_sources', array() );
		$hidden  = Minn_Admin_Surfaces::hidden_map();
		$out     = array();
		foreach ( (array) $sources as $id => $src ) {
			$id    = sanitize_key( $id );
			$route = is_array( $src ) && isset( $src['route'] ) ? self::rest_route_or_null( $src['route'] ) : null;
			if ( '' === $id || null === $route ) {
				continue;
			}
			// Per-user hide (v1.0 gate G2) — hidden sources leave the payload.
			if ( isset( $hidden[ 'design:' . $id ] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'label' => ( isset( $src['label'] ) && is_string( $src['label'] ) && '' !== $src['label'] )
					? $src['label']
					: ucfirst( $id ),
				'route' => $route,
			);
		}
		return $out;
	}

	/**
	 * Blocks whose image list only their own plugin can lay out.
	 *
	 * Minn edits images generically wherever the markup lets it: a run of
	 * repeating units can be reordered, dropped and cloned byte-for-byte, and
	 * a fixed layout's openings can at least trade their photos. Some blocks
	 * are neither. Jetpack's tiled gallery packs photos into columns whose
	 * widths it derived from their aspect ratios, so ADDING or REMOVING one
	 * means re-running that layout — knowledge that belongs to the plugin.
	 *
	 * An adapter answers this filter with a `rebuild` callable and Minn hands
	 * it the images the writer chose, in order, getting whole block markup
	 * back. The callable runs in PHP where the plugin's own helpers live; the
	 * editor stays generic and never learns a plugin's layout rules.
	 *
	 * @return array<string,array> Keyed by block name. See
	 *                             docs/for-plugin-authors.md.
	 */
	public static function image_blocks() {
		$blocks = apply_filters( 'minn_admin_image_blocks', array() );
		$out    = array();
		foreach ( (array) $blocks as $name => $desc ) {
			if ( ! is_string( $name ) || ! preg_match( '#^[a-z][a-z0-9-]*/[a-z][a-z0-9-]*$#', $name ) ) {
				continue;
			}
			if ( ! is_array( $desc ) || empty( $desc['rebuild'] ) || ! is_callable( $desc['rebuild'] ) ) {
				continue;
			}
			$out[ $name ] = array(
				'label'   => ( isset( $desc['label'] ) && is_string( $desc['label'] ) && '' !== $desc['label'] )
					? $desc['label']
					: ucwords( str_replace( array( '-', '/' ), ' ', $name ) ),
				'insert'  => ! empty( $desc['insert'] ),
				'rebuild' => $desc['rebuild'],
			);
		}
		return $out;
	}

	/** The same list without the callables — safe for the boot payload. */
	public static function image_blocks_payload() {
		$out = array();
		foreach ( self::image_blocks() as $name => $desc ) {
			$out[ $name ] = array(
				'label'  => $desc['label'],
				'insert' => $desc['insert'],
			);
		}
		return $out;
	}

	/**
	 * Plugin-declared slash-menu / block-picker commands.
	 *
	 * Unlike auto-insert blocks (dynamic blocks with a render probe), these
	 * are free-form entries plugins register for writing actions: paste a
	 * boilerplate paragraph, drop a pre-built island template, or fetch
	 * markup from a REST route. Pure descriptors — no third-party JS in the
	 * Minn document. See docs/for-plugin-authors.md.
	 *
	 * Each command needs an id, a label, and exactly one insert shape:
	 * `html` (prose HTML), `template` (+ optional `block` for an island),
	 * or `route` (async: POST/GET returns { html } or { template, block? }).
	 *
	 * @return array[]
	 */
	public static function editor_commands() {
		$raw       = apply_filters( 'minn_admin_editor_commands', array() );
		$hidden_ns = Minn_Admin_Surfaces::hidden_slash_map();
		$out       = array();
		foreach ( (array) $raw as $cmd ) {
			if ( ! is_array( $cmd ) || empty( $cmd['id'] ) || empty( $cmd['label'] ) ) {
				continue;
			}
			// Per-user hide (v1.0 gate G2): a hidden slash namespace takes its
			// commands with it. Namespace-less commands are not hideable.
			if ( ! empty( $cmd['ns'] ) && is_string( $cmd['ns'] ) && isset( $hidden_ns[ sanitize_key( $cmd['ns'] ) ] ) ) {
				continue;
			}
			$id = preg_replace( '/[^a-z0-9_\-\/]/', '', strtolower( (string) $cmd['id'] ) );
			if ( '' === $id ) {
				continue;
			}
			$has_html     = ! empty( $cmd['html'] ) && is_string( $cmd['html'] );
			$has_template = ! empty( $cmd['template'] ) && is_string( $cmd['template'] );
			$has_route    = ! empty( $cmd['route'] ) && is_string( $cmd['route'] );
			// Exactly one insert shape — refuse ambiguous descriptors.
			if ( (int) $has_html + (int) $has_template + (int) $has_route !== 1 ) {
				continue;
			}
			$item = array(
				'id'    => $id,
				'label' => sanitize_text_field( (string) $cmd['label'] ),
			);
			if ( ! empty( $cmd['icon'] ) && is_string( $cmd['icon'] ) ) {
				// Lucide key (file, send…) or a single glyph — client picks.
				$item['icon'] = sanitize_text_field( $cmd['icon'] );
			}
			if ( ! empty( $cmd['ns'] ) && is_string( $cmd['ns'] ) ) {
				$item['ns'] = sanitize_text_field( $cmd['ns'] );
			}
			if ( ! empty( $cmd['keywords'] ) && is_array( $cmd['keywords'] ) ) {
				$item['keywords'] = array_values(
					array_filter(
						array_map(
							static function ( $k ) {
								return is_string( $k ) ? sanitize_text_field( $k ) : '';
							},
							$cmd['keywords']
						)
					)
				);
			}
			if ( ! empty( $cmd['searchOnly'] ) ) {
				$item['searchOnly'] = true;
			}
			if ( $has_html ) {
				// Trusted PHP source (the registering plugin) — the client
				// inserts as prose HTML the same way pullquote/table do.
				$item['html'] = $cmd['html'];
			} elseif ( $has_template ) {
				$item['template'] = $cmd['template'];
				if ( ! empty( $cmd['block'] ) && is_string( $cmd['block'] ) ) {
					$item['block'] = sanitize_text_field( $cmd['block'] );
				}
			} else {
				// Relative REST path under the site's rest root, no leading slash.
				$route = self::rest_route_or_null( $cmd['route'] );
				if ( null === $route ) {
					continue;
				}
				$item['route']  = $route;
				$method         = ! empty( $cmd['method'] ) ? strtoupper( (string) $cmd['method'] ) : 'POST';
				$item['method'] = in_array( $method, array( 'GET', 'POST' ), true ) ? $method : 'POST';
				if ( ! empty( $cmd['body'] ) && is_array( $cmd['body'] ) ) {
					// Shallow sanitize string values only — nested free-form
					// is the plugin's responsibility on its own route.
					$body = array();
					foreach ( $cmd['body'] as $k => $v ) {
						if ( ! is_string( $k ) ) {
							continue;
						}
						$key = sanitize_key( $k );
						if ( '' === $key ) {
							continue;
						}
						if ( is_scalar( $v ) || null === $v ) {
							$body[ $key ] = $v;
						}
					}
					if ( $body ) {
						$item['body'] = $body;
					}
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Third-party blocks insertable with zero adapter code.
	 *
	 * A self-closing block comment is valid saved markup only for blocks whose
	 * JS `save()` is null — `is_dynamic()` alone is NOT that guarantee (hybrid
	 * blocks like stackable/posts have a render_callback AND a JS save that
	 * emits wrapper HTML; a bare comment renders empty and Gutenberg flags it
	 * invalid). The server can't see JS save(), so the discriminator is a
	 * RENDER PROBE: a block that outputs nothing from a bare self-closing
	 * comment depends on saved HTML, inner blocks or editor-supplied
	 * attributes, and is excluded. Static-save blocks are excluded outright:
	 * only the block's own JS `save()` can produce their HTML
	 * (docs/block-inspector.md, "The honest limit"). Core blocks are excluded
	 * because Minn has native flows for them.
	 *
	 * An adapter descriptor with an `insert` key supersedes the auto entry
	 * (its hand-written template wins); `insert => false` suppresses a block
	 * from the menu entirely.
	 *
	 * @param array $block_forms The applied `minn_admin_block_forms` value.
	 * @return array[] Sorted list of { name, title, ns }.
	 */
	/**
	 * @param array $block_forms RAW (unfiltered) block-form descriptors. Pass
	 *   the unfiltered set: candidacy keys the shared render-probe transient,
	 *   so a per-user slash hide must not change it (the per-user filtering is
	 *   applied to the OUTPUT below, after the cache).
	 */
	public static function insertable_blocks( $block_forms ) {
		$candidates = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			if ( 0 === strpos( $name, 'core/' ) ) {
				continue;
			}
			if ( ! $type->is_dynamic() ) {
				continue;
			}
			// Child blocks are only valid inside their parent/ancestor.
			if ( ! empty( $type->parent ) || ! empty( $type->ancestor ) ) {
				continue;
			}
			$supports = (array) $type->supports;
			if ( isset( $supports['inserter'] ) && false === $supports['inserter'] ) {
				continue;
			}
			if ( isset( $block_forms[ $name ]['insert'] ) ) {
				continue;
			}
			// Many plugins register titles only in their editor JS — fall back
			// to a humanized slug so those blocks stay reachable.
			$slug  = substr( $name, strpos( $name, '/' ) + 1 );
			$title = $type->title ? $type->title : ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
			$candidates[ $name ] = array(
				'name'  => $name,
				'title' => $title,
				'ns'    => substr( $name, 0, strpos( $name, '/' ) ),
			);
		}

		// Render probe, cached: ~60 candidate renders can run real queries, so
		// the surviving list is kept in a transient. The key hashes the
		// candidate set, so activating/deactivating a plugin busts it.
		$key = 'minn_admin_insert_blocks_' . md5( MINN_ADMIN_VERSION . wp_json_encode( array_keys( $candidates ) ) );
		$out = get_transient( $key );
		if ( ! is_array( $out ) ) {
			$out = array();
			foreach ( $candidates as $name => $entry ) {
				try {
					$rendered = trim( do_blocks( '<!-- wp:' . $name . ' /-->' ) );
				} catch ( \Throwable $e ) {
					$rendered = '';
				}
				if ( '' !== $rendered ) {
					$out[] = $entry;
				}
			}
			set_transient( $key, $out, 12 * HOUR_IN_SECONDS );
		}

		usort( $out, function ( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		} );
		$out = apply_filters( 'minn_admin_insert_blocks', $out );

		// Per-user hide (v1.0 gate G2), applied AFTER the shared transient and
		// the filter so one user's hides never poison the cached probe list.
		$hidden_ns = Minn_Admin_Surfaces::hidden_slash_map();
		if ( $hidden_ns ) {
			$out = array_values( array_filter( (array) $out, function ( $b ) use ( $hidden_ns ) {
				return ! ( is_array( $b ) && isset( $b['ns'] ) && isset( $hidden_ns[ $b['ns'] ] ) );
			} ) );
		}
		return $out;
	}

	/**
	 * Strip the `insert` template from block-form descriptors whose slash
	 * namespace the current user hid — the hide removes the ADD affordance
	 * only; the inspector form stays so existing blocks remain editable.
	 */
	public static function filter_block_forms( $block_forms ) {
		$hidden_ns = Minn_Admin_Surfaces::hidden_slash_map();
		if ( ! $hidden_ns || ! is_array( $block_forms ) ) {
			return $block_forms;
		}
		foreach ( $block_forms as $name => $form ) {
			if ( is_array( $form ) && isset( $form['insert'] ) && isset( $hidden_ns[ strtok( (string) $name, '/' ) ] ) ) {
				unset( $block_forms[ $name ]['insert'] );
			}
		}
		return $block_forms;
	}

	/**
	 * Every slash namespace alive right now — the registry `slash:<ns>` hide
	 * ids validate against. Union of registered non-core block namespaces,
	 * block-form descriptor names, block pattern prefixes, and editor-command
	 * namespaces (the four sources the slash menu / block picker draw from).
	 */
	private static $slash_ns_cache = null;

	public static function slash_namespaces() {
		if ( null !== self::$slash_ns_cache ) {
			return self::$slash_ns_cache;
		}
		$ns = array();
		foreach ( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ) as $name ) {
			$p = strtok( (string) $name, '/' );
			if ( 'core' !== $p && '' !== $p ) {
				$ns[ $p ] = true;
			}
		}
		foreach ( array_keys( (array) apply_filters( 'minn_admin_block_forms', array() ) ) as $name ) {
			$p = strtok( (string) $name, '/' );
			if ( 'core' !== $p && '' !== $p ) {
				$ns[ $p ] = true;
			}
		}
		if ( class_exists( 'WP_Block_Patterns_Registry' ) ) {
			foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
				$prefix = empty( $p['name'] ) ? '' : strtok( (string) $p['name'], '/' );
				if ( 'core' !== $prefix && '' !== $prefix ) {
					$ns[ $prefix ] = true;
				}
			}
		}
		foreach ( (array) apply_filters( 'minn_admin_editor_commands', array() ) as $cmd ) {
			if ( is_array( $cmd ) && ! empty( $cmd['ns'] ) && is_string( $cmd['ns'] ) ) {
				$p = sanitize_key( $cmd['ns'] );
				if ( '' !== $p ) {
					$ns[ $p ] = true;
				}
			}
		}
		// Per-request memo: this walks the whole block-type and pattern
		// registries plus two filters, and a slash hide alone calls it twice
		// (validation + restore list). The registries are stable per request.
		self::$slash_ns_cache = array_keys( $ns );
		return self::$slash_ns_cache;
	}

	/**
	 * Whether comments are a usable feature on this site.
	 *
	 * Detects the *mechanisms* plugins and snippets use to kill comments,
	 * rather than naming a single plugin. Common kill methods converge on:
	 *
	 *  1. remove_post_type_support( …, 'comments' ) on every type
	 *     (Disable Comments "everywhere", Completely Disable Comments,
	 *     functions.php snippets, host hardening).
	 *  2. add_filter( 'comments_open', '__return_false' ) (and equivalent
	 *     always-false closers) so even open posts never accept replies.
	 *  3. Stripping the REST comments routes so the admin list can't load.
	 *  4. A DISABLE_COMMENTS constant (wp-config / mu-plugin kill-switch).
	 *
	 * Settings → Discussion "Allow comments on new posts" alone does NOT
	 * count as disabled — existing posts still need moderation.
	 *
	 * @return bool
	 */
	/**
	 * Serve Minn's own route in the USER's language.
	 *
	 * core's determine_locale() uses the user's language only when
	 * is_admin(), and Minn deliberately is not wp-admin: it renders at
	 * /minn-admin/ on the front end. Without this the two halves of the same
	 * screen disagree. Someone who picks German in Your profile on an English
	 * site gets a German app (js_translations() reads get_user_locale()) with
	 * every PHP string still in English, and is_rtl() never becomes true for
	 * them, so a Persian or Arabic user never gets a right-to-left layout no
	 * matter what they choose.
	 *
	 * Scoped to Minn's own requests. Widening it would change the language of
	 * the front end for everybody.
	 *
	 * @param string $locale Locale core determined.
	 * @return string
	 */
	public static function route_locale( $locale ) {
		// This filter runs during locale setup, long before the query is
		// parsed and possibly before pluggable functions exist, so the route
		// is matched on the request URI and every dependency is guarded.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $locale;
		}
		if ( ! function_exists( 'wp_get_current_user' ) || ! function_exists( 'get_user_locale' ) ) {
			return $locale;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) {
			return $locale;
		}
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
		// Minn's app route and Minn's OWN REST namespace only. Widening this
		// to every /wp-json/ request would re-language other plugins'
		// endpoints on this site, which is not Minn's call to make.
		$is_app = (bool) preg_match( '#(^|/)minn-admin(/|$)#', $path );
		$is_rest = false !== strpos( $path, '/wp-json/minn-admin/' )
			|| 0 === strpos( $route, '/minn-admin/' );
		if ( ! $is_app && ! $is_rest ) {
			return $locale;
		}
		if ( ! is_user_logged_in() ) {
			return $locale;
		}
		$user_locale = get_user_locale();
		return $user_locale ? $user_locale : $locale;
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'minn-admin', false, dirname( plugin_basename( MINN_ADMIN_FILE ) ) . '/languages' );
	}

	/**
	 * Translation map for the SPA's __()/_n() helpers, keyed by SOURCE
	 * string (English is the source vocabulary — a missing catalog or entry
	 * falls through to the literal, so the app runs with zero tooling).
	 *
	 * Files are the standard JED JSON that `wp i18n make-json` emits from a
	 * translated .po (one file per locale; the suffix is the md5 of the
	 * script path). Values are a string, or an array of plural forms for
	 * _n() entries. The filter lets sites and fixtures inject or override
	 * entries without shipping files.
	 *
	 * TWO directories, in core's own precedence order. Language packs
	 * installed through the update system land in WP_LANG_DIR/plugins/,
	 * NOT in the plugin's own languages/ — and WP_Textdomain_Registry
	 * checks WP_LANG_DIR/plugins FIRST, so a pack wins over anything
	 * bundled. Reading only the plugin directory (as this did) left PHP
	 * translated while the whole app stayed English, which is the exact
	 * half-translated state a locale is supposed to avoid.
	 */
	public static function js_translations() {
		$locale = get_user_locale();
		$map    = array();
		// Only en_US is the source vocabulary and needs no catalog. The other
		// English variants DO: en_GB (plus en_AU/en_CA/en_NZ/en_ZA) carry a
		// real spelling catalog, so this must not skip every locale starting
		// with "en".
		if ( 'en_US' !== $locale ) {
			// Later directories override earlier ones, so the bundled
			// fallback is read first and the language pack lands on top.
			$dirs = array(
				MINN_ADMIN_DIR . 'languages',
				WP_LANG_DIR . '/plugins',
			);
			foreach ( $dirs as $dir ) {
				foreach ( glob( $dir . '/minn-admin-' . $locale . '-*.json' ) ?: array() as $file ) {
					$jed     = json_decode( (string) file_get_contents( $file ), true );
					$entries = $jed['locale_data']['messages'] ?? array();
					foreach ( (array) $entries as $key => $forms ) {
						if ( '' === $key || ! is_array( $forms ) || '' === (string) ( $forms[0] ?? '' ) ) {
							continue;
						}
						$map[ $key ] = count( $forms ) > 1 ? array_values( $forms ) : (string) $forms[0];
					}
				}
			}
		}
		/**
		 * Filter the SPA translation map.
		 *
		 * @param array  $map    source string => translation (or plural-forms array).
		 * @param string $locale The user locale being served.
		 */
		return apply_filters( 'minn_admin_js_translations', $map, $locale );
	}

	/**
	 * The locale's Plural-Forms expression, read from the same JED files
	 * js_translations() uses. JED keeps it on the reserved '' entry, which
	 * that method skips because it carries no message.
	 *
	 * Returned verbatim (for example
	 * "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : …);") and evaluated on
	 * the client by a small parser, never by eval: a catalog is a
	 * supply-chain input like any other download.
	 *
	 * @return string Empty when the locale has no catalog (English falls
	 *                through to the source strings anyway).
	 */
	public static function js_plural_forms() {
		$locale = get_user_locale();
		$rule   = '';
		if ( 'en_US' === $locale ) {
			return apply_filters( 'minn_admin_js_plural_forms', $rule, $locale );
		}
		foreach ( array( MINN_ADMIN_DIR . 'languages', WP_LANG_DIR . '/plugins' ) as $dir ) {
			foreach ( glob( $dir . '/minn-admin-' . $locale . '-*.json' ) ?: array() as $file ) {
				$jed  = json_decode( (string) file_get_contents( $file ), true );
				$head = $jed['locale_data']['messages'][''] ?? array();
				if ( ! empty( $head['plural-forms'] ) ) {
					$rule = (string) $head['plural-forms'];
				} elseif ( ! empty( $head['plural_forms'] ) ) {
					$rule = (string) $head['plural_forms'];
				}
			}
		}
		/**
		 * Filter the SPA plural-forms rule.
		 *
		 * @param string $rule   The gettext Plural-Forms expression.
		 * @param string $locale The user locale being served.
		 */
		return apply_filters( 'minn_admin_js_plural_forms', $rule, $locale );
	}

	public static function comments_enabled() {
		// 0. Explicit constant kill-switch (many mu-plugins / host configs).
		if ( defined( 'DISABLE_COMMENTS' ) && DISABLE_COMMENTS ) {
			return self::filter_comments_enabled( false, array() );
		}

		// 1. Post-type support stripped for every UI type.
		//    This is what "remove everywhere" plugins actually do under the hood.
		$types = array();
		foreach ( get_post_types_by_support( 'comments' ) as $type ) {
			$obj = get_post_type_object( $type );
			// Public or show_ui — skip purely internal types that happen to
			// inherit support (and attachment, which almost never means
			// "site comments" for moderation UI purposes).
			if ( ! $obj || ( ! $obj->public && ! $obj->show_ui ) ) {
				continue;
			}
			if ( 'attachment' === $type ) {
				continue;
			}
			$types[] = $type;
		}
		if ( ! $types ) {
			return self::filter_comments_enabled( false, array() );
		}

		// 2. REST route gone — Minn's list is wp/v2/comments; no route, no UI.
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			if ( empty( $routes['/wp/v2/comments'] ) ) {
				return self::filter_comments_enabled( false, $types );
			}
		}

		// 3. Hard-close via comments_open filter (support left in place).
		if ( self::comments_hard_closed( $types ) ) {
			return self::filter_comments_enabled( false, $types );
		}

		return self::filter_comments_enabled( true, $types );
	}

	/**
	 * True when comments_open is forced closed site-wide despite support.
	 *
	 * @param string[] $types Post types that still support comments.
	 * @return bool
	 */
	private static function comments_hard_closed( array $types ) {
		// Sitewide always-false filters (the classic snippet pattern) answer
		// false even with a dummy post id. Per-type closers usually leave
		// post_id 0 alone, so this is a low-false-positive first check.
		if ( ! apply_filters( 'comments_open', true, 0 ) ) {
			return true;
		}

		// Post-level: among recent published posts that *should* accept
		// comments (type supports + comment_status open), does comments_open()
		// still return true for any of them? If every one is filtered closed,
		// the feature is effectively off.
		$q = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 10,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$saw_candidate = false;
		foreach ( $q->posts as $post ) {
			if ( 'open' !== $post->comment_status ) {
				continue;
			}
			if ( ! post_type_supports( $post->post_type, 'comments' ) ) {
				continue;
			}
			$saw_candidate = true;
			if ( comments_open( $post ) ) {
				return false; // at least one post is truly open
			}
		}

		// Candidates existed but all failed comments_open → hard-closed.
		if ( $saw_candidate ) {
			return true;
		}

		// No open-status posts in the sample (everything closed in Discussion
		// bulk, or a brand-new site). Support remains and the filter didn't
		// force-close post_id 0, so treat as enabled — moderation of any
		// existing comments still makes sense, and new posts can re-open.
		return false;
	}

	/**
	 * @param bool     $enabled Detection result.
	 * @param string[] $types   Types that still support comments.
	 * @return bool
	 */
	private static function filter_comments_enabled( $enabled, array $types ) {
		/**
		 * Filter whether Minn treats comments as enabled (nav, palette, badge).
		 *
		 * @param bool     $enabled Default detection result.
		 * @param string[] $types   Post types that still support comments.
		 */
		return (bool) apply_filters( 'minn_admin_comments_enabled', $enabled, $types );
	}
}
