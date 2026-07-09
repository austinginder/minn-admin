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
		add_action( 'init', array( __CLASS__, 'register_route' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_app' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_maintenance_mode' ), 1 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_link' ), 100 );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_action( 'init', array( __CLASS__, 'register_x_oembed' ) );
		add_action( 'init', array( __CLASS__, 'register_oembed_refresh' ), 20 );
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
	 * "Make Minn the default admin": after signing in, land in Minn instead of
	 * the wp-admin dashboard. Only takes over the DEFAULT landing — an explicit
	 * redirect_to deep link (a plugin page, a specific post) still wins, and
	 * users who can't use Minn (no edit_posts) keep core behavior.
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! get_option( 'minn_admin_default' ) || ! ( $user instanceof WP_User ) || ! $user->has_cap( 'edit_posts' ) ) {
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
		register_setting(
			'minn_admin',
			'minn_admin_default',
			array(
				'show_in_rest' => true,
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
	public static function app_url() {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/minn-admin/' );
		}
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	public static function admin_bar_link( $bar ) {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'minn-admin',
				'title' => 'Minn Admin',
				'href'  => self::app_url(),
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
					'<script>window.location.href = %s;</script><p><a href="%s">Open Minn Admin</a></p>',
					wp_json_encode( self::app_url() ),
					esc_url( self::app_url() )
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
		echo "<!doctype html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'><title>{$title} — Coming soon</title><style>body{font-family:system-ui,sans-serif;background:#0b0b0d;color:#ececed;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}div{text-align:center}h1{font-size:22px;letter-spacing:-0.3px}p{color:#9d9da7}</style></head><body><div><h1>{$title}</h1><p>We&rsquo;re making some improvements. Back soon.</p></div></body></html>";
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

		$user  = wp_get_current_user();
		$roles = array_values( $user->roles );
		$role  = $roles ? wp_roles()->role_names[ $roles[0] ] ?? $roles[0] : '';

		$block_forms = apply_filters( 'minn_admin_block_forms', array() );

		$boot = array(
			'restUrl'  => esc_url_raw( rest_url() ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'appUrl'   => self::app_url(),
			'version'  => MINN_ADMIN_VERSION,
			'user'     => array(
				'id'     => $user->ID,
				'login'  => $user->user_login,
				'name'   => $user->display_name,
				'role'   => translate_user_role( $role ),
				'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
			),
			'site'     => array(
				'name'       => get_bloginfo( 'name' ),
				'url'        => home_url( '/' ),
				'adminUrl'   => admin_url(),
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
			'caps'     => array(
				'plugins'      => current_user_can( 'activate_plugins' ),
				'update'       => current_user_can( 'update_plugins' ),
				'delete'       => current_user_can( 'delete_plugins' ),
				'install'      => current_user_can( 'install_plugins' ),
				'themes'       => current_user_can( 'switch_themes' ),
				'deleteThemes' => current_user_can( 'delete_themes' ),
				'updateThemes' => current_user_can( 'update_themes' ),
				'installThemes' => current_user_can( 'install_themes' ),
				'settings'     => current_user_can( 'manage_options' ),
				'moderate'     => current_user_can( 'moderate_comments' ),
				'upload'       => current_user_can( 'upload_files' ),
				'users'        => current_user_can( 'list_users' ),
				'readPrivate'  => current_user_can( 'read_private_posts' ),
				'editPages'    => current_user_can( 'edit_pages' ),
				'createUsers'  => current_user_can( 'create_users' ),
				'editUsers'    => current_user_can( 'edit_users' ),
				'promoteUsers' => current_user_can( 'promote_users' ),
				'deleteUsers'  => current_user_can( 'delete_users' ),
				'orders'       => class_exists( 'WooCommerce' ) && current_user_can( 'edit_shop_orders' ),
				'themeOptions' => current_user_can( 'edit_theme_options' ),
				'core'         => current_user_can( 'update_core' ),
			),
			'wc'       => class_exists( 'WooCommerce' ),
			// False when Disable Comments (etc.) has removed the feature —
			// Comments nav/palette/badge hide even if the user can moderate.
			'comments'  => self::comments_enabled(),
			'pretty'   => (bool) get_option( 'permalink_structure' ),
			'roles'    => current_user_can( 'list_users' ) ? wp_roles()->get_names() : new \stdClass(),
			'surfaces' => Minn_Admin_Surfaces::for_current_user(),
			'editorPanels' => Minn_Admin_Surfaces::editor_panels_for_current_user(),
			// Active page builders — drives "+ New → Page in ⟨builder⟩"
			// (docs/page-builders.md; adapters/page-builders.php).
			'builders' => minn_admin_page_builders_boot(),
			// Design libraries available (adapters/stackable.php, kadence.php)
			// — gate the lazy designs fetches in the editor's slash menu.
			'stackable' => minn_admin_stackable_active(),
			'kadence'   => minn_admin_kadence_active(),
			'generateblocks' => minn_admin_generateblocks_active(),
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
			'insertBlocks' => self::insertable_blocks( $block_forms ),
		);

		include MINN_ADMIN_DIR . 'includes/template.php';
		exit;
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
		return apply_filters( 'minn_admin_insert_blocks', $out );
	}

	/**
	 * Whether comments are a usable feature on this site.
	 *
	 * Plugins like Disable Comments strip post_type_support( 'comments' )
	 * from every type (or set "remove everywhere"), leaving the
	 * moderate_comments capability intact but the Comments screen empty.
	 * Return false when no type still accepts comments, or when Disable
	 * Comments is in everywhere mode.
	 *
	 * @return bool
	 */
	public static function comments_enabled() {
		// Disable Comments "everywhere" removes support + menus; check first.
		if ( class_exists( 'Disable_Comments', false ) ) {
			$opts = get_option( 'disable_comments_options', array() );
			if ( is_array( $opts ) && ! empty( $opts['remove_everywhere'] ) ) {
				return false;
			}
		}
		// Explicit kill-switch some setups define in wp-config.
		if ( defined( 'DISABLE_COMMENTS' ) && DISABLE_COMMENTS ) {
			return false;
		}

		// Any post type that still declares comment support?
		$types = get_post_types_by_support( 'comments' );
		if ( empty( $types ) ) {
			return false;
		}

		/**
		 * Filter whether Minn treats comments as enabled (nav, palette, badge).
		 *
		 * @param bool     $enabled Default detection result.
		 * @param string[] $types   Post types that still support comments.
		 */
		return (bool) apply_filters( 'minn_admin_comments_enabled', true, $types );
	}
}
