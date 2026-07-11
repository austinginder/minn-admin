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

		// Default: open the app. On a singular front-end view the current
		// user can edit, retarget to that item's Minn editor (mirrors WP's
		// own "Edit Page" / "Edit Post" affordance).
		$title = 'Minn Admin';
		$href  = self::app_url();

		if ( ! is_admin() && is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
				$pto = get_post_type_object( $post->post_type );
				// Minn's editor is REST-backed — skip types that aren't in REST.
				if ( $pto && ! empty( $pto->show_in_rest ) ) {
					$rest_base = ! empty( $pto->rest_base ) ? $pto->rest_base : $post->post_type;
					$path      = 'editor/' . rawurlencode( $rest_base ) . '/' . (int) $post->ID;
					$title     = 'Edit in Minn Admin';
					if ( get_option( 'permalink_structure' ) ) {
						$href = trailingslashit( self::app_url() ) . $path;
					} else {
						// Plain permalinks: app boots via ?minn_admin=1, route rides the hash.
						$href = self::app_url() . '#/' . $path;
					}
				}
			}
		}

		$bar->add_node(
			array(
				'id'    => 'minn-admin',
				'title' => $title,
				'href'  => $href,
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
				'themeOptions' => current_user_can( 'edit_theme_options' ),
				'core'         => current_user_can( 'update_core' ),
				// Drives Settings → Design (Additional CSS). Core maps this
				// from unfiltered_html; multisite keeps it super-admin-only.
				'editCss'      => current_user_can( 'edit_css' ),
			),
			'wc'       => class_exists( 'WooCommerce' ),
			// False when Disable Comments (etc.) has removed the feature —
			// Comments nav/palette/badge hide even if the user can moderate.
			'comments'  => self::comments_enabled(),
			'pretty'   => (bool) get_option( 'permalink_structure' ),
			'roles'    => current_user_can( 'list_users' ) ? wp_roles()->get_names() : new \stdClass(),
			'surfaces' => Minn_Admin_Surfaces::for_current_user(),
			'editorPanels' => Minn_Admin_Surfaces::editor_panels_for_current_user(),
			// Admin-notice digest: the client triggers this nonced wp-admin
			// pageload in the background when stale; Minn extracts other
			// plugins' notices into structured data for the notification
			// panel (class-minn-admin-notices.php).
			'notices'  => array(
				'url'   => Minn_Admin_Notices::capture_url(),
				'nonce' => Minn_Admin_Notices::nonce(),
				'stale' => Minn_Admin_Notices::is_stale(),
			),
			// Active cache layers — drives the "Clear site cache" palette
			// command (adapters/cache-purge.php).
			'cache'    => current_user_can( 'manage_options' ) ? minn_admin_cache_purgers_boot() : array(),
			// Backup provider — drives the "Back up site now" palette
			// command (adapters/updraftplus.php).
			'backup'   => ( current_user_can( 'manage_options' ) && minn_admin_updraftplus_active() )
				? array( 'name' => 'UpdraftPlus', 'route' => 'minn-admin/v1/updraft/backup-now' )
				: null,
			// Regenerate Thumbnails present + allowed — a per-image button
			// on the media detail modal (adapters/regenerate-thumbnails.php).
			'regenThumbs' => function_exists( 'minn_admin_regen_thumbs_available' ) && minn_admin_regen_thumbs_available(),
			// PDF Invoices & Packing Slips — download buttons on the order
			// detail modal (adapters/wcpdf.php). Null without the plugin or
			// order access.
			'wcpdf'    => function_exists( 'minn_admin_wcpdf_boot' ) ? minn_admin_wcpdf_boot() : null,
			// Disembark connector present — a boolean only: the palette's
			// "Copy backup command" fetches the command (with its token) on
			// demand rather than inlining a site secret into every pageload.
			'disembark' => current_user_can( 'manage_options' ) && minn_admin_disembark_active(),
			// Active page builders — drives "+ New → Page in ⟨builder⟩"
			// (docs/page-builders.md; adapters/page-builders.php).
			'builders' => minn_admin_page_builders_boot(),
			// Design libraries registered via minn_admin_design_sources
			// (adapters/stackable.php, kadence.php, generateblocks.php or any
			// third-party plugin) — drive the lazy designs fetches in the
			// editor's slash menu and block picker.
			'designs'  => self::design_sources(),
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
			'visibility' => function_exists( 'minn_admin_site_visibility' ) ? minn_admin_site_visibility() : null,
		);

		include MINN_ADMIN_DIR . 'includes/template.php';
		exit;
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
	public static function design_sources() {
		$sources = apply_filters( 'minn_admin_design_sources', array() );
		$out     = array();
		foreach ( (array) $sources as $id => $src ) {
			$id = sanitize_key( $id );
			if ( '' === $id || ! is_array( $src ) || empty( $src['route'] ) || ! is_string( $src['route'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $id,
				'label' => ( isset( $src['label'] ) && is_string( $src['label'] ) && '' !== $src['label'] )
					? $src['label']
					: ucfirst( $id ),
				'route' => $src['route'],
			);
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
