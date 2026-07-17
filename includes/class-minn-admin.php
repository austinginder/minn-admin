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
		add_action( 'init', array( __CLASS__, 'register_toolbar_meta' ) );
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
	 * Per-user "Minn is the default admin" (stored on minn_admin_appearance).
	 * Falls back to the legacy site option until the user saves a profile preference.
	 */
	public static function user_wants_default_admin( $user_id = 0 ) {
		$uid = $user_id ? (int) $user_id : get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		$ap = self::get_user_appearance( $uid );
		return ! empty( $ap['defaultAdmin'] );
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
				);
			}
			if ( in_array( $accent, $ids, true ) ) {
				return array(
					'scheme'       => $accent,
					'custom'       => $defaults['custom'],
					'defaultAdmin' => false,
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

		return array(
			'scheme'       => $scheme,
			'custom'       => $custom,
			'defaultAdmin' => $default_admin,
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

		// Always a hard link into the Minn app (never "Edit in Minn Admin").
		$bar->add_node(
			array(
				'id'    => 'minn-admin',
				'title' => 'Minn Admin',
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

		// Raw forms drive insertable_blocks' candidacy (so its shared render
		// probe caches per SITE, not per user); the per-user-filtered copy is
		// only the descriptor payload sent to the client. See insertable_blocks.
		$raw_block_forms = apply_filters( 'minn_admin_block_forms', array() );
		$block_forms     = self::filter_block_forms( $raw_block_forms );

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
			),
			// Scheme slot metadata for the profile custom editor (key + CSS var + label).
			'appearanceSlots' => array_map(
				function ( $css, $key ) {
					$labels = array(
						'bg'       => 'Background',
						'bg2'      => 'Background elevated',
						'panel'    => 'Panel',
						'panel2'   => 'Panel elevated',
						'hover'    => 'Hover',
						'border'   => 'Border',
						'border2'  => 'Border strong',
						'text'     => 'Text',
						'text2'    => 'Text secondary',
						'text3'    => 'Text muted',
						'accent'   => 'Accent',
						'accent2'  => 'Accent hover / links',
						'accentFg' => 'Text on accent',
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
				// Drives Settings → Design (Additional CSS). Core maps this
				// from unfiltered_html; multisite keeps it super-admin-only.
				'editCss'      => current_user_can( 'edit_css' ),
			),
			'wc'       => class_exists( 'WooCommerce' ),
			// WooCommerce Subscriptions extension (wc/v3/subscriptions REST).
			'wcs'      => class_exists( 'WooCommerce' ) && class_exists( 'WC_Subscriptions' ),
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
			'regenThumbs' => function_exists( 'minn_admin_regen_thumbs_available' ) && minn_admin_regen_thumbs_available(),
			// Enable Media Replace present + allowed — a "Replace file" button
			// on the media detail modal (adapters/enable-media-replace.php).
			'mediaReplace' => function_exists( 'minn_admin_emr_available' ) && minn_admin_emr_available(),
			// Media folders provider (adapters/media-folders.php) — { name }
			// gates the folder combobox on the Media view; null without one.
			'mediaFolders' => function_exists( 'minn_admin_media_folders_boot' ) ? minn_admin_media_folders_boot() : null,
			// Safe SVG present — media toolbar SVG filter tab + detail note
			// (adapters/safe-svg.php). Sanitization stays Safe SVG's.
			'safeSvg'    => function_exists( 'minn_admin_safe_svg_active' ) && minn_admin_safe_svg_active(),
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
		$hidden  = Minn_Admin_Surfaces::hidden_map();
		$out     = array();
		foreach ( (array) $sources as $id => $src ) {
			$id = sanitize_key( $id );
			if ( '' === $id || ! is_array( $src ) || empty( $src['route'] ) || ! is_string( $src['route'] ) ) {
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
				'route' => $src['route'],
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
				$route = ltrim( $cmd['route'], '/' );
				if ( ! preg_match( '/^[a-z0-9_\-\/{}]+$/i', $route ) ) {
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
