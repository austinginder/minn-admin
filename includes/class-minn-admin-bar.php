<?php
/**
 * Minn Bar: an opt-in replacement for the classic admin bar on the PUBLIC
 * site. Per-user (minn_admin_appearance.frontBar), front end only — wp-admin
 * keeps the classic bar untouched, and users who have not opted in see core's
 * bar exactly as before.
 *
 * The design is deliberately quiet: identity + one contextual Edit action on
 * the left, a small action cluster on the right, and a status slot that stays
 * EMPTY on a healthy public production site — a chip renders only for
 * exceptions (coming soon, maintenance, password, hidden from search, or a
 * non-production environment), so a chip always means something. No global
 * keyboard shortcuts are claimed: front-end pages own the keyboard, and the
 * palette opens from the search icon (a one-shot sessionStorage intent the
 * app consumes on boot).
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Bar {

	public static function init() {
		add_filter( 'show_admin_bar', array( __CLASS__, 'suppress_core_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_head', array( __CLASS__, 'bump' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Whether the Minn Bar owns this request's admin bar. Front end only;
	 * the user must pass Minn's own gate AND have opted in on Your profile.
	 * Builder canvases are excluded: they hide the classic bar, and this
	 * bar is a replacement for that bar, not a second chrome layer.
	 */
	public static function active() {
		static $active = null;
		if ( null !== $active ) {
			return $active;
		}
		$active = ! is_admin()
			&& is_user_logged_in()
			&& current_user_can( 'edit_posts' )
			&& Minn_Admin::user_wants_front_bar()
			&& ! is_customize_preview()
			&& ! self::is_builder_canvas();
		return $active;
	}

	/**
	 * Front-end builder editors (and their preview iframes) are still
	 * `! is_admin()`, so they would otherwise get the Minn bar. They already
	 * hide the classic bar via their own `show_admin_bar` veto; we cannot
	 * consult `is_admin_bar_showing()` here because this method is itself
	 * called FROM that filter. The query flags are the same signals the
	 * builders use to enter canvas mode. Brizy's iframe is
	 * `?is-editor-iframe=`, not a brizy-* name.
	 */
	private static function is_builder_canvas() {
		if ( isset( $_GET['elementor-preview'] ) ) {
			return true;
		}
		if ( isset( $_GET['fl_builder'] ) ) {
			return true;
		}
		if ( isset( $_GET['et_fb'] ) ) {
			return true;
		}
		if ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] ) {
			return true;
		}
		if ( isset( $_GET['breakdance'] ) && 'builder' === $_GET['breakdance'] ) {
			return true;
		}
		if ( isset( $_GET['etch'] ) && 'magic' === $_GET['etch'] ) {
			return true;
		}
		if ( isset( $_GET['is-editor-iframe'] ) ) {
			return true;
		}
		if ( isset( $_GET['vc_editable'] ) ) {
			return true;
		}
		return false;
	}

	public static function suppress_core_bar( $show ) {
		return self::active() ? false : $show;
	}

	public static function body_class( $classes ) {
		if ( self::active() && ! is_embed() ) {
			$classes[] = 'minn-front-bar';
			// Preserve core's public contract for themes whose sticky chrome is
			// positioned with body.admin-bar selectors.
			$classes[] = 'admin-bar';
		}
		return array_unique( $classes );
	}

	public static function enqueue() {
		if ( ! self::active() || is_embed() ) {
			return;
		}
		$ver = function ( $rel ) {
			$mtime = @filemtime( MINN_ADMIN_DIR . $rel );
			return MINN_ADMIN_VERSION . ( $mtime ? '.' . $mtime : '' );
		};
		wp_enqueue_style( 'minn-admin-bar', MINN_ADMIN_URL . 'assets/css/bar.css', array(), $ver( 'assets/css/bar.css' ) );
		wp_enqueue_script( 'minn-admin-bar', MINN_ADMIN_URL . 'assets/js/bar.js', array(), $ver( 'assets/js/bar.js' ), true );
		wp_add_inline_script(
			'minn-admin-bar',
			'window.MINN_BAR = ' . wp_json_encode( self::config() ) . ';',
			'before'
		);
	}

	/**
	 * Recreate core's front-end admin-bar height contract. Themes commonly
	 * hardcode these exact 32px/46px offsets for their sticky chrome, while
	 * newer code reads --wp-admin--admin-bar--height.
	 */
	public static function bump() {
		if ( ! self::active() || is_embed() ) {
			return;
		}
		echo '<style id="minn-bar-bump">html{--wp-admin--admin-bar--height:32px;margin-top:var(--wp-admin--admin-bar--height) !important;scroll-padding-top:var(--wp-admin--admin-bar--height);}@media screen and (max-width:782px){html{--wp-admin--admin-bar--height:46px;}}@media print{#wpadminbar.minn-wpadminbar{display:none !important;}html{margin-top:0 !important;}}</style>' . "\n";
	}

	private static function app_path( $path ) {
		$app = Minn_Admin::app_url();
		if ( get_option( 'permalink_structure' ) ) {
			return trailingslashit( $app ) . $path;
		}
		return $app . '#/' . $path;
	}

	/**
	 * The status slot's exception state, or null when there is nothing worth
	 * saying (public site, production environment).
	 *
	 * @return array|null { tone, label, title, sub, fix|null { label, kind, body|id } }
	 */
	private static function status() {
		$fixable = current_user_can( 'manage_options' );
		// Read through the redaction helper, never the raw detector: this bar
		// renders into a public page for anyone with edit_posts, and provider
		// identities are reconnaissance the lower tiers do not get elsewhere.
		$v = class_exists( 'Minn_Admin' ) && method_exists( 'Minn_Admin', 'visibility_for_current_user' )
			? Minn_Admin::visibility_for_current_user()
			: null;
		if ( $v && 'public' !== $v['state'] ) {
			$providers = isset( $v['providers'] ) ? (array) $v['providers'] : array();
			$names     = implode( ', ', wp_list_pluck( $providers, 'name' ) );
			$first     = $providers ? $providers[0] : null;
			// One obvious fix only: offered when exactly one provider is
			// responsible and Minn knows how to turn that provider off.
			$fix = null;
			if ( $fixable && $first && 1 === count( $providers ) ) {
				if ( ! empty( $first['minn'] ) ) {
					$fix = array(
						'label' => __( 'Turn off maintenance mode', 'minn-admin' ),
						'kind'  => 'settings',
						'body'  => array( 'minn_admin_maintenance' => false ),
					);
				} elseif ( ! empty( $first['can'] ) && ! empty( $first['id'] ) ) {
					$fix = array(
						/* translators: %s: the plugin providing the coming-soon/maintenance mode. */
						'label' => sprintf( __( 'Turn off in %s', 'minn-admin' ), $first['name'] ),
						'kind'  => 'provider',
						'id'    => $first['id'],
					);
				}
			}
			if ( 'hidden' === $v['state'] || 'partial' === $v['state'] ) {
				$coming = $first && 'coming-soon' === $first['kind'];
				return array(
					'tone'  => 'amber',
					'label' => 'partial' === $v['state'] ? __( 'Partly hidden', 'minn-admin' )
						: ( $coming ? __( 'Coming soon', 'minn-admin' ) : __( 'Maintenance', 'minn-admin' ) ),
					'title' => 'partial' === $v['state']
						? __( 'Part of the site is hidden', 'minn-admin' )
						: ( $coming ? __( 'Coming soon mode is on', 'minn-admin' ) : __( 'Maintenance mode is on', 'minn-admin' ) ),
					'sub'   => $names
						/* translators: %s: the plugin(s) hiding the site. */
						? sprintf( __( 'Visitors see a holding page from %s. You see the real site because you are signed in.', 'minn-admin' ), $names )
						: __( 'Visitors see a holding page. You see the real site because you are signed in.', 'minn-admin' ),
					'fix'   => $fix,
				);
			}
			if ( 'password' === $v['state'] ) {
				return array(
					'tone'  => 'amber',
					'label' => __( 'Password protected', 'minn-admin' ),
					'title' => __( 'The whole site is password-protected', 'minn-admin' ),
					'sub'   => $names
						/* translators: %s: the plugin providing the password gate. */
						? sprintf( __( 'Visitors need a password from %s before they can browse.', 'minn-admin' ), $names )
						: __( 'Visitors need a password before they can browse.', 'minn-admin' ),
					'fix'   => $fix,
				);
			}
			// search-discouraged.
			return array(
				'tone'  => 'blue',
				'label' => __( 'Hidden from search', 'minn-admin' ),
				'title' => __( 'Search engines are discouraged', 'minn-admin' ),
				'sub'   => __( 'The site asks search engines not to index it. Visitors can still browse normally.', 'minn-admin' ),
				'fix'   => $fixable ? array(
					'label' => __( 'Allow search engines', 'minn-admin' ),
					'kind'  => 'settings',
					'body'  => array( 'blog_public' => 1 ),
				) : null,
			);
		}
		// Environment type is a system_info() row everywhere else in the app,
		// and that route is manage_options. Keep the same bar here.
		$env = $fixable && function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'production' !== $env ) {
			$labels = array(
				'staging'     => __( 'Staging', 'minn-admin' ),
				'development' => __( 'Development', 'minn-admin' ),
				'local'       => __( 'Local', 'minn-admin' ),
			);
			return array(
				'tone'  => 'accent',
				'label' => isset( $labels[ $env ] ) ? $labels[ $env ] : $env,
				'title' => __( 'This is not the production site', 'minn-admin' ),
				'sub'   => __( 'Changes here do not affect the live site.', 'minn-admin' ),
				'fix'   => null,
			);
		}
		return null;
	}

	/**
	 * The front-end palette's command list, server-built so capability checks
	 * happen in PHP. kind: url (navigate) | intent (one-shot app handoff) |
	 * theme (bar.js toggles the shared minn-theme preference).
	 */
	private static function commands( $edit_url, $edit_label, $edit_hint ) {
		$go      = __( 'Go to', 'minn-admin' );
		$actions = __( 'Actions', 'minn-admin' );
		$cmds    = array();
		$cmds[]  = array( 'group' => $go, 'icon' => 'grid', 'title' => __( 'Overview', 'minn-admin' ), 'hint' => __( 'Minn Admin home', 'minn-admin' ), 'kind' => 'url', 'value' => Minn_Admin::app_url() );
		$cmds[]  = array( 'group' => $go, 'icon' => 'doc', 'title' => __( 'Content', 'minn-admin' ), 'hint' => __( 'Posts, pages, and custom types', 'minn-admin' ), 'kind' => 'url', 'value' => self::app_path( 'content' ) );
		if ( current_user_can( 'upload_files' ) ) {
			$cmds[] = array( 'group' => $go, 'icon' => 'image', 'title' => __( 'Media', 'minn-admin' ), 'hint' => __( 'Library and uploads', 'minn-admin' ), 'kind' => 'url', 'value' => self::app_path( 'media' ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			$cmds[] = array( 'group' => $go, 'icon' => 'gear', 'title' => __( 'Settings', 'minn-admin' ), 'hint' => __( 'Site settings in Minn', 'minn-admin' ), 'kind' => 'url', 'value' => self::app_path( 'settings' ) );
		}
		if ( $edit_url ) {
			$cmds[] = array( 'group' => $actions, 'icon' => 'pencil', 'title' => $edit_label, 'hint' => $edit_hint, 'kind' => 'url', 'value' => $edit_url );
		}
		$cmds[] = array( 'group' => $actions, 'icon' => 'plus', 'title' => __( 'Create a post', 'minn-admin' ), 'hint' => __( 'Start a new draft', 'minn-admin' ), 'kind' => 'intent', 'value' => 'new:posts' );
		if ( current_user_can( 'edit_pages' ) ) {
			$cmds[] = array( 'group' => $actions, 'icon' => 'plus', 'title' => __( 'Create a page', 'minn-admin' ), 'hint' => __( 'Start a new page', 'minn-admin' ), 'kind' => 'intent', 'value' => 'new:pages' );
		}
		$purgers = self::cache_purgers();
		if ( $purgers ) {
			$cmds[] = array(
				'group' => $actions,
				'icon'  => 'refresh',
				/* translators: %s: comma-separated cache provider names. */
				'title' => sprintf( __( 'Clear site cache (%s)', 'minn-admin' ), implode( ', ', wp_list_pluck( $purgers, 'name' ) ) ),
				'hint'  => __( 'Purge every detected cache layer', 'minn-admin' ),
				'kind'  => 'purge',
				'value' => '',
			);
		}
		$cmds[] = array( 'group' => $actions, 'icon' => 'moon', 'title' => __( 'Toggle appearance', 'minn-admin' ), 'hint' => __( 'Switch light or dark', 'minn-admin' ), 'kind' => 'theme', 'value' => '' );
		$cmds[] = array( 'group' => $actions, 'icon' => 'wp', 'title' => __( 'Classic admin', 'minn-admin' ), 'hint' => __( 'Open wp-admin', 'minn-admin' ), 'kind' => 'url', 'value' => admin_url() );
		return $cmds;
	}

	/**
	 * search subtype (post type slug) → Minn editor route base, for the
	 * palette's content results. Only REST-editable types are offered.
	 */
	private static function search_types() {
		$out = array();
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $pt ) {
			$out[ $pt->name ] = $pt->rest_base ? $pt->rest_base : $pt->name;
		}
		return $out;
	}

	/**
	 * Cache providers this user may purge from the bar — same detection the
	 * app's ⌘K command rides ({ id, name } each, empty without the cap).
	 */
	private static function cache_purgers() {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'minn_admin_cache_purgers_boot' ) ) {
			return array();
		}
		return minn_admin_cache_purgers_boot();
	}

	private static function config() {
		$status = self::status();
		list( $edit_url, $edit_label, $edit_hint ) = self::edit_target();
		return array(
			'rest'        => esc_url_raw( rest_url() ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'app'         => Minn_Admin::app_url(),
			'editorBase'  => self::app_path( 'editor' ),
			'fix'         => $status && $status['fix'] ? $status['fix'] : null,
			'commands'    => self::commands( $edit_url, $edit_label, $edit_hint ),
			'purge'       => self::cache_purgers(),
			'types'       => self::search_types(),
			'emptyNotifs' => __( 'All caught up.', 'minn-admin' ),
			'i18n'        => array(
				'placeholder' => __( 'Go anywhere or run a command…', 'minn-admin' ),
				'content'     => __( 'Your content', 'minn-admin' ),
				'empty'       => __( 'No matches. Try “content” or “settings”.', 'minn-admin' ),
				'navigate'    => __( 'navigate', 'minn-admin' ),
				'open'        => __( 'open', 'minn-admin' ),
				'purging'     => __( 'Clearing cache…', 'minn-admin' ),
				/* translators: %s: the providers that cleared. */
				'purged'      => __( 'Cache cleared (%s)', 'minn-admin' ),
				/* translators: 1: the providers that cleared. 2: the providers that failed. */
				'purgeFail'   => __( 'Cache cleared (%1$s); failed: %2$s', 'minn-admin' ),
			),
		);
	}

	private static function icon( $paths ) {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
	}

	private static function menu_item( $href, $icon, $title, $sub = '', $meta = '' ) {
		return '<a class="minn-bar-menu-item" role="menuitem" href="' . esc_url( $href ) . '">'
			. ( $icon ? self::icon( $icon ) : '' )
			. '<span class="minn-bar-menu-copy"><span class="minn-bar-menu-title">' . esc_html( $title ) . '</span>'
			. ( $sub ? '<span class="minn-bar-menu-sub">' . esc_html( $sub ) . '</span>' : '' ) . '</span>'
			. ( $meta ? '<span class="minn-bar-menu-meta">' . esc_html( $meta ) . '</span>' : '' )
			. '</a>';
	}

	/**
	 * Contextual Edit action: the queried singular object, when this user
	 * can edit it and its type is REST-editable in Minn. A page whose canvas
	 * a builder OWNS (Elementor, Beaver Builder, Brizy…) edits in that
	 * builder instead — Minn's editor would only open a read-only fence, and
	 * on the front end "edit this page" means the tool that renders it.
	 * Block-native builders (Etch, Divi 5) stay on the Minn editor, which
	 * handles their markup as islands.
	 *
	 * @return array [ url|'' , label, hint ]
	 */
	private static function edit_target() {
		$edit_url   = '';
		$edit_label = __( 'Edit', 'minn-admin' );
		$edit_hint  = __( 'Open this page in the Minn editor', 'minn-admin' );
		if ( is_singular() ) {
			$obj = get_queried_object();
			if ( $obj instanceof WP_Post && current_user_can( 'edit_post', $obj->ID ) ) {
				$edit_url = Minn_Admin::editor_url_for_post( $obj->ID );
				if ( $edit_url ) {
					$pto = get_post_type_object( $obj->post_type );
					$edit_label = $pto && ! empty( $pto->labels->singular_name )
						/* translators: %s: the post type's singular name (Page, Post, Product…). */
						? sprintf( __( 'Edit %s', 'minn-admin' ), $pto->labels->singular_name )
						: __( 'Edit', 'minn-admin' );
					$builder = function_exists( 'minn_admin_builder_for_post' )
						? minn_admin_builder_for_post( $obj )
						: null;
					// Inactive builder: keep the Minn editor, whose fence
					// explains the state and points at Extensions.
					if ( $builder && $builder['owns_content'] && $builder['active'] && $builder['edit_url'] ) {
						$edit_url = $builder['edit_url'];
						/* translators: %s: the page builder's name. */
						$edit_label = sprintf( __( 'Edit in %s', 'minn-admin' ), $builder['name'] );
						/* translators: %s: the page builder's name. */
						$edit_hint = sprintf( __( 'This page is built with %s', 'minn-admin' ), $builder['name'] );
					}
				}
			}
		}
		return array( $edit_url, $edit_label, $edit_hint );
	}

	public static function render() {
		if ( ! self::active() || is_embed() ) {
			return;
		}

		$status = self::status();
		list( $edit_url, $edit_label ) = self::edit_target();

		$site_name = Minn_Admin::plain_text( get_bloginfo( 'name' ) );
		$user      = wp_get_current_user();
		// force_display: the Discussion "Show avatars" setting governs the
		// site's comments, not Minn's chrome — without it get_avatar() returns
		// false on avatar-less sites and the account button rendered an empty
		// circle while the SPA (which reads avatar URLs, uncovered by that
		// setting) kept showing the picture.
		$avatar    = get_avatar( $user->ID, 52, '', '', array( 'class' => 'minn-bar-avatar-img', 'extra_attr' => 'loading="lazy"', 'force_display' => true ) );

		// Use core's public shell ID as well as its body class and height token.
		// A large number of themes key their sticky-header fixes to one or both.
		echo '<div id="wpadminbar" class="minn-wpadminbar nojq">';
		echo '<div id="minn-bar-root" data-minn-theme="dark">';

		// Pre-paint the saved Minn theme before first paint of the bar (the
		// SPA's localStorage key; system preference when unset).
		echo '<script>(function(){try{var t=localStorage.getItem("minn-theme");if(t!=="dark"&&t!=="light"){t=window.matchMedia&&matchMedia("(prefers-color-scheme: light)").matches?"light":"dark";}document.getElementById("minn-bar-root").setAttribute("data-minn-theme",t);}catch(e){}})();</script>';

		echo '<header id="minn-bar" aria-label="' . esc_attr__( 'Minn Admin Bar', 'minn-admin' ) . '">';

		// Left: identity + status slot + Edit.
		echo '<div class="minn-bar-zone minn-bar-left">';
		// The flip rule, taught from both sides: the m mark is Minn (one click
		// into the app), the site name is the site (here, its menu).
		echo '<a class="minn-bar-btn minn-bar-markbtn" href="' . esc_url( Minn_Admin::app_url() ) . '" title="' . esc_attr__( 'Open Minn Admin', 'minn-admin' ) . '"><span class="minn-bar-mark">m</span></a>';
		echo '<button type="button" class="minn-bar-btn minn-bar-site" data-barmenu="minn-bar-menu-site" aria-haspopup="menu" aria-expanded="false">'
			. '<span class="minn-bar-sitename">' . esc_html( $site_name ) . '</span>'
			. '<svg class="minn-bar-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m8 10 4 4 4-4"/></svg>'
			. '</button>';
		if ( $status ) {
			echo '<span class="minn-bar-divider"></span>';
			echo '<button type="button" class="minn-bar-btn minn-bar-status" data-tone="' . esc_attr( $status['tone'] ) . '" data-barmenu="minn-bar-menu-status" aria-haspopup="menu" aria-expanded="false" aria-label="' . esc_attr( $status['title'] ) . '">'
				. '<span class="minn-bar-status-dot"></span><span class="minn-bar-status-text">' . esc_html( $status['label'] ) . '</span>'
				. '</button>';
		}
		echo '</div>';

		// Right: search (palette intent), New, Edit, notifications, account.
		echo '<div class="minn-bar-zone minn-bar-right">';
		echo '<button type="button" class="minn-bar-btn minn-bar-iconbtn" id="minn-bar-search" title="' . esc_attr__( 'Search Minn Admin', 'minn-admin' ) . '" aria-label="' . esc_attr__( 'Search Minn Admin', 'minn-admin' ) . '">'
			. self::icon( '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>' ) . '</button>';
		echo '<button type="button" class="minn-bar-btn minn-bar-iconbtn" data-barmenu="minn-bar-menu-new" aria-haspopup="menu" aria-expanded="false" aria-label="' . esc_attr__( 'Create new', 'minn-admin' ) . '">'
			. self::icon( '<path d="M12 5v14M5 12h14"/>' ) . '</button>';
		if ( $edit_url ) {
			echo '<a class="minn-bar-btn minn-bar-edit" href="' . esc_url( $edit_url ) . '">'
				. self::icon( '<path d="M4 20h4L19 9l-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/>' )
				. '<span>' . esc_html( $edit_label ) . '</span></a>';
		}
		echo '<span class="minn-bar-divider"></span>';
		echo '<span class="minn-bar-bellwrap"><button type="button" class="minn-bar-btn minn-bar-iconbtn" data-barmenu="minn-bar-menu-notif" aria-haspopup="menu" aria-expanded="false" aria-label="' . esc_attr__( 'Notifications', 'minn-admin' ) . '">'
			. self::icon( '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>' )
			. '</button><span class="minn-bar-notif-dot" id="minn-bar-notif-dot" hidden></span></span>';
		echo '<button type="button" class="minn-bar-btn minn-bar-iconbtn minn-bar-avatarbtn" data-barmenu="minn-bar-menu-user" aria-haspopup="menu" aria-expanded="false" aria-label="' . esc_attr__( 'Account menu', 'minn-admin' ) . '"><span class="minn-bar-avatar">' . $avatar . '</span></button>';
		echo '</div>';
		echo '</header>';

		// Site menu.
		echo '<div class="minn-bar-menu" id="minn-bar-menu-site" role="menu" hidden>';
		echo '<div class="minn-bar-menu-label">' . esc_html__( 'Minn Admin', 'minn-admin' ) . '</div>';
		echo self::menu_item( Minn_Admin::app_url(), '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>', __( 'Overview', 'minn-admin' ) );
		echo self::menu_item( self::app_path( 'content' ), '<path d="M6 3h9l3 3v15H6z"/><path d="M9 11h6M9 15h6"/>', __( 'Content', 'minn-admin' ) );
		if ( current_user_can( 'upload_files' ) ) {
			echo self::menu_item( self::app_path( 'media' ), '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m5 17 5-5 3 3 2-2 4 4"/>', __( 'Media', 'minn-admin' ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			echo self::menu_item( self::app_path( 'settings' ), '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h.01a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h.01a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v.01a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>', __( 'Settings', 'minn-admin' ) );
		}
		echo '<div class="minn-bar-menu-rule"></div>';
		echo self::menu_item( admin_url(), '<path fill="currentColor" stroke-width="0" d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.54.82-2.771.82-3.864 0-.405-.026-.78-.07-1.11m-7.981.105c.647-.03 1.232-.105 1.232-.105.582-.075.514-.93-.067-.899 0 0-1.755.135-2.88.135-1.064 0-2.85-.15-2.85-.15-.585-.03-.661.855-.075.885 0 0 .54.061 1.125.09l1.68 4.605-2.37 7.08L5.354 6.9c.649-.03 1.234-.1 1.234-.1.585-.075.516-.93-.065-.896 0 0-1.746.138-2.874.138-.2 0-.438-.008-.69-.015C4.911 3.15 8.235 1.215 12 1.215c2.809 0 5.365 1.072 7.286 2.833-.046-.003-.091-.009-.141-.009-1.06 0-1.812.923-1.812 1.914 0 .89.513 1.643 1.06 2.531.411.72.89 1.643.89 2.977 0 .915-.354 1.994-.821 3.479l-1.075 3.585-3.9-11.61.001.014zM12 22.784c-1.059 0-2.081-.153-3.048-.437l3.237-9.406 3.315 9.087c.024.053.05.101.078.149-1.12.393-2.325.607-3.582.607M1.211 12c0-1.564.336-3.05.935-4.39L7.29 21.709C3.694 19.96 1.212 16.271 1.211 12M12 0C5.385 0 0 5.385 0 12s5.385 12 12 12 12-5.385 12-12S18.615 0 12 0"/>', __( 'Classic admin', 'minn-admin' ), __( 'The original WordPress tools', 'minn-admin' ), '↗' );
		echo '</div>';

		// Status menu (content is server-known; the fix button is wired in bar.js).
		if ( $status ) {
			echo '<div class="minn-bar-menu" id="minn-bar-menu-status" role="menu" hidden>';
			echo '<div class="minn-bar-menu-label">' . esc_html__( 'Site status', 'minn-admin' ) . '</div>';
			echo '<div class="minn-bar-menu-item minn-bar-menu-static"><span class="minn-bar-menu-copy"><span class="minn-bar-menu-title">' . esc_html( $status['title'] ) . '</span><span class="minn-bar-menu-sub minn-bar-menu-wrap">' . esc_html( $status['sub'] ) . '</span></span></div>';
			if ( $status['fix'] || current_user_can( 'manage_options' ) ) {
				echo '<div class="minn-bar-menu-rule"></div>';
			}
			if ( $status['fix'] ) {
				echo '<button type="button" class="minn-bar-menu-item" role="menuitem" id="minn-bar-status-fix">'
					. self::icon( '<path d="M18.4 5.6a9 9 0 1 0 .8 8.4"/><path d="M19 3v5h-5"/>' )
					. '<span class="minn-bar-menu-copy"><span class="minn-bar-menu-title">' . esc_html( $status['fix']['label'] ) . '</span></span></button>';
			}
			if ( current_user_can( 'manage_options' ) ) {
				echo self::menu_item( self::app_path( 'settings' ), '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>', __( 'Visibility settings', 'minn-admin' ) );
			}
			echo '</div>';
		}

		// New menu: creation happens inside the app (a one-shot intent flag).
		echo '<div class="minn-bar-menu" id="minn-bar-menu-new" role="menu" hidden>';
		echo '<div class="minn-bar-menu-label">' . esc_html__( 'Create', 'minn-admin' ) . '</div>';
		echo '<button type="button" class="minn-bar-menu-item" role="menuitem" data-barintent="new:posts">' . self::icon( '<path d="M6 3h9l3 3v15H6z"/><path d="M9 11h6M9 15h6"/>' ) . '<span class="minn-bar-menu-copy"><span class="minn-bar-menu-title">' . esc_html__( 'Post', 'minn-admin' ) . '</span></span></button>';
		if ( current_user_can( 'edit_pages' ) ) {
			echo '<button type="button" class="minn-bar-menu-item" role="menuitem" data-barintent="new:pages">' . self::icon( '<path d="M6 3h9l3 3v15H6z"/><path d="M15 3v4h4"/>' ) . '<span class="minn-bar-menu-copy"><span class="minn-bar-menu-title">' . esc_html__( 'Page', 'minn-admin' ) . '</span></span></button>';
		}
		echo '</div>';

		// Notifications menu: filled lazily by bar.js from minn-admin/v1/notifications.
		echo '<div class="minn-bar-menu" id="minn-bar-menu-notif" role="menu" hidden>';
		echo '<div class="minn-bar-menu-label">' . esc_html__( 'Notifications', 'minn-admin' ) . '</div>';
		echo '<div id="minn-bar-notif-items"><div class="minn-bar-menu-item minn-bar-menu-static"><span class="minn-bar-menu-copy"><span class="minn-bar-menu-sub">' . esc_html__( 'Loading…', 'minn-admin' ) . '</span></span></div></div>';
		echo '<div class="minn-bar-menu-rule"></div>';
		echo '<button type="button" class="minn-bar-menu-item" role="menuitem" data-barintent="notifications">' . self::icon( '<path d="M5 12h14M13 6l6 6-6 6"/>' ) . '<span class="minn-bar-menu-copy"><span class="minn-bar-menu-title">' . esc_html__( 'Open notifications', 'minn-admin' ) . '</span></span></button>';
		echo '</div>';

		// Account menu.
		echo '<div class="minn-bar-menu" id="minn-bar-menu-user" role="menu" hidden>';
		echo '<div class="minn-bar-menu-label">' . esc_html( Minn_Admin::plain_text( $user->display_name ) ) . '</div>';
		echo self::menu_item( self::app_path( 'profile' ), '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>', __( 'Your profile', 'minn-admin' ) );
		echo '<div class="minn-bar-menu-rule"></div>';
		echo self::menu_item( wp_logout_url(), '<path d="M10 4H5v16h5M14 8l4 4-4 4M8 12h10"/>', __( 'Sign out', 'minn-admin' ) );
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}
}
