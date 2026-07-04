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
			'pretty'   => (bool) get_option( 'permalink_structure' ),
			'roles'    => current_user_can( 'list_users' ) ? wp_roles()->get_names() : new \stdClass(),
			'surfaces' => Minn_Admin_Surfaces::for_current_user(),
			'editorPanels' => Minn_Admin_Surfaces::editor_panels_for_current_user(),
			/**
			 * Block-inspector form refinements, keyed by block name. A descriptor
			 * can set per-attribute label/control/options/hide, an attribute
			 * `order`, and `wrapperText` patterns for editable text in an
			 * InnerBlocks wrapper. See docs/for-plugin-authors.md.
			 */
			'blockForms' => apply_filters( 'minn_admin_block_forms', array() ),
		);

		include MINN_ADMIN_DIR . 'includes/template.php';
		exit;
	}
}
