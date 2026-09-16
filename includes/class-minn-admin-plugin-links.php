<?php
/**
 * Plugin settings links — harvested from what each plugin already declares.
 *
 * Every plugin with a settings screen tells wp-admin where it is: the
 * "Settings" link on plugins.php comes from the plugin_action_links filters.
 * Minn asks the same question the same way: the client triggers a REAL
 * plugins.php pageload (as the current user, cookie-authenticated) with
 * ?minn_links=1, so every plugin registers its filters exactly as it would
 * for a human visit, including the ones that only hook on the plugins
 * screen. At in_admin_header the filters are applied per plugin inside a
 * Throwable guard, the core verbs are dropped, and what remains is reduced
 * to {label, href, kind}. Raw markup is never stored.
 *
 * Alongside the harvested links, links() names where each plugin already
 * lives INSIDE Minn (its surfaces, a settings section it provides, its
 * license row), resolved from the surface registry's `plugin` key and the
 * provider registries, so a card can send the reader to the Minn view
 * first and the plugin's own screen second.
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Plugin_Links {

	const STALE_AFTER = DAY_IN_SECONDS;

	/** Link labels that read as the plugin's own settings doorway. */
	const SETTINGS_LABEL = '/\b(settings?|configure|configuration|options?|set ?up|preferences|config|manage|dashboard)\b/i';

	/** Coming-soon / maintenance plugins the Visibility section can switch. */
	const VISIBILITY_PLUGINS = array( 'wp-maintenance-mode', 'coming-soon', 'maintenance', 'cmp-coming-soon-maintenance', 'minimal-coming-soon-maintenance-mode', 'under-construction-page', 'password-protected' );

	/** Core plugins.php verbs the harvest never keeps. */
	const CORE_KEYS = array( 'activate', 'deactivate', 'delete', 'edit', 'network_active', 'network_only', 'auto-update', 'plugin-auto-update' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_arm' ), PHP_INT_MAX );
	}

	/**
	 * On a flagged plugins.php request, swallow the page and answer JSON
	 * from in_admin_header, after every admin hook has run like a visit.
	 */
	public static function maybe_arm() {
		if ( empty( $_GET['minn_links'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_GET['minn_nonce'] ?? '' ) );
		if ( ! current_user_can( 'activate_plugins' ) || ! wp_verify_nonce( $nonce, Minn_Admin_Notices::NONCE_ACTION ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		ob_start();
		add_action( 'in_admin_header', array( __CLASS__, 'capture_and_respond' ), PHP_INT_MAX );
	}

	public static function capture_and_respond() {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$links = self::capture();
		// Admin-menu pages fill in what a plugin never declared as an
		// action link; a plugin's own screen is the doorway either way.
		foreach ( self::capture_menu_pages() as $dir => $pages ) {
			$have = array();
			foreach ( $links[ $dir ] ?? array() as $l ) {
				$have[ $l['href'] ] = true;
			}
			foreach ( $pages as $page ) {
				if ( isset( $have[ $page['href'] ] ) || count( $links[ $dir ] ?? array() ) >= 8 ) {
					continue;
				}
				$links[ $dir ][]        = $page;
				$have[ $page['href'] ] = true;
			}
		}
		$census = self::capture_settings_api();
		set_transient(
			self::store_key(),
			array( 'captured' => time(), 'hash' => self::plugin_set_hash(), 'links' => $links, 'settings_api' => $census ),
			2 * DAY_IN_SECONDS
		);
		wp_send_json( array( 'ok' => true, 'count' => count( $links ), 'captured' => time() ) );
	}

	/**
	 * Admin-menu pages attributed to plugins. After admin_menu has run, every
	 * top-level and submenu page is in $GLOBALS['menu'] / ['submenu']; a page
	 * with a render callback is attributed by that callback's file
	 * (Minn_Admin_Notices::owner_of, Reflection), the same way notices are.
	 * Pages without a callback (post-type lists, core files) are skipped:
	 * they belong to no plugin screen.
	 *
	 * @return array dir-slug => [ { label, href, kind: 'menu' } ]
	 */
	public static function capture_menu_pages() {
		global $menu, $submenu, $wp_filter;
		$out   = array();
		$label = function ( $title ) {
			// Menu titles carry update-count bubbles and "new" badges.
			$t = preg_replace( '/<span\b[^>]*>.*?<\/span>/is', '', (string) $title );
			$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES ) ) );
			return mb_substr( $t, 0, 40 );
		};
		$owner_dir = function ( $hook ) use ( $wp_filter ) {
			if ( ! $hook || empty( $wp_filter[ $hook ] ) ) {
				return '';
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $cb ) {
					$o = Minn_Admin_Notices::owner_of( $cb['function'] );
					if ( 'plugin' === $o['type'] && $o['slug'] ) {
						return $o['slug'];
					}
				}
			}
			return '';
		};
		$consider = function ( $item, $parent ) use ( &$out, $label, $owner_dir ) {
			if ( ! is_array( $item ) || empty( $item[2] ) ) {
				return;
			}
			$slug = (string) $item[2];
			$cap  = isset( $item[1] ) ? (string) $item[1] : 'manage_options';
			if ( ! current_user_can( $cap ) ) {
				return; // gating, not a doorway this user has
			}
			$hook = get_plugin_page_hookname( $slug, $parent );
			$dir  = $owner_dir( $hook );
			if ( ! $dir ) {
				return;
			}
			$href = menu_page_url( $slug, false );
			if ( ! $href ) {
				return;
			}
			$text = $label( $item[0] ?? '' );
			if ( '' === $text ) {
				return;
			}
			$out[ $dir ][] = array( 'label' => $text, 'href' => esc_url_raw( $href ), 'kind' => 'menu' );
		};
		foreach ( (array) $menu as $item ) {
			$consider( $item, '' );
		}
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $item ) {
				$consider( $item, (string) $parent );
			}
		}
		return $out;
	}

	/**
	 * Which plugins build their screens on the core Settings API, and how
	 * big those screens are: every add_settings_field callback attributed
	 * to its plugin by file. admin_init has run by in_admin_header, so the
	 * registries are complete. This is the census behind a generic
	 * settings surface: a plugin here can be read and written without a
	 * bespoke adapter; a plugin absent here has its own UI.
	 *
	 * @return array dir-slug => { pages: [option_page], sections, fields, options }
	 */
	public static function capture_settings_api() {
		global $wp_settings_fields, $wp_settings_sections, $wp_registered_settings;
		$out = array();
		foreach ( (array) $wp_settings_fields as $page => $sections ) {
			foreach ( (array) $sections as $section => $fields ) {
				foreach ( (array) $fields as $field ) {
					if ( empty( $field['callback'] ) ) {
						continue;
					}
					$o = Minn_Admin_Notices::owner_of( $field['callback'] );
					if ( 'plugin' !== $o['type'] || ! $o['slug'] ) {
						continue;
					}
					$d = $o['slug'];
					if ( ! isset( $out[ $d ] ) ) {
						$out[ $d ] = array( 'pages' => array(), 'sections' => array(), 'fields' => 0, 'options' => 0 );
					}
					$out[ $d ]['pages'][ (string) $page ]                                = true;
					$out[ $d ]['sections'][ (string) $page . '/' . (string) $section ] = true;
					$out[ $d ]['fields']++;
				}
			}
		}
		foreach ( $out as $d => $row ) {
			$pages = array_keys( $row['pages'] );
			$n     = 0;
			foreach ( (array) $wp_registered_settings as $opt => $args ) {
				if ( isset( $args['group'] ) && in_array( (string) $args['group'], $pages, true ) ) {
					$n++;
				}
			}
			$out[ $d ] = array( 'pages' => $pages, 'sections' => count( $row['sections'] ), 'fields' => $row['fields'], 'options' => $n );
		}
		return $out;
	}

	/**
	 * Apply the plugin_action_links filters for every installed plugin the
	 * way WP_Plugins_List_Table does, keep the non-core anchors.
	 *
	 * @return array dir-slug => [ { label, href, kind } ]
	 */
	public static function capture() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$active   = is_plugin_active( $file );
			$defaults = $active
				? array( 'deactivate' => '<a href="' . esc_url( wp_nonce_url( 'plugins.php?action=deactivate&amp;plugin=' . rawurlencode( $file ), 'deactivate-plugin_' . $file ) ) . '">Deactivate</a>' )
				: array(
					'activate' => '<a href="' . esc_url( wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . rawurlencode( $file ), 'activate-plugin_' . $file ) ) . '">Activate</a>',
					'delete'   => '<a href="' . esc_url( wp_nonce_url( 'plugins.php?action=delete-selected&amp;checked[]=' . rawurlencode( $file ), 'bulk-plugins' ) ) . '">Delete</a>',
				);
			try {
				// Same signatures core passes; a vendor callback that throws
				// costs that plugin its links, never the whole harvest.
				$actions = apply_filters( 'plugin_action_links', $defaults, $file, $data, 'all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
				$actions = apply_filters( "plugin_action_links_{$file}", $actions, $file, $data, 'all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( ! is_array( $actions ) ) {
				continue;
			}
			$links = array();
			foreach ( $actions as $key => $html ) {
				if ( in_array( (string) $key, self::CORE_KEYS, true ) || ! is_string( $html ) ) {
					continue;
				}
				$link = self::anchor_of( $html );
				if ( $link ) {
					$links[] = $link;
				}
				if ( count( $links ) >= 6 ) {
					break;
				}
			}
			if ( $links ) {
				$out[ self::dir_of( $file ) ] = $links;
			}
		}
		return $out;
	}

	/** The first anchor in a filter entry, reduced and classified. */
	private static function anchor_of( $html ) {
		if ( ! preg_match( '/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $html, $m ) ) {
			return null;
		}
		$href  = html_entity_decode( trim( $m[2] ), ENT_QUOTES );
		$label = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $m[3] ), ENT_QUOTES ) ) );
		if ( '' === $label || '' === $href || '#' === $href[0] || 0 === stripos( $href, 'javascript:' ) ) {
			return null;
		}
		$label = mb_substr( $label, 0, 40 );
		// Relative admin paths are what most plugins write ("admin.php?page=x").
		if ( ! preg_match( '#^(https?:)?//#i', $href ) ) {
			$href = '/' === $href[0] ? home_url( $href ) : admin_url( ltrim( $href, './' ) );
		}
		$href = esc_url_raw( $href );
		if ( ! $href ) {
			return null;
		}
		// The verbs core owns, in case a plugin re-emits them under its own key.
		if ( preg_match( '#/wp-admin/(plugins\.php\?.*action=|plugin-editor\.php)#', $href ) ) {
			return null;
		}
		$admin = 0 === strpos( $href, admin_url() );
		$kind  = ! $admin ? 'external' : ( preg_match( self::SETTINGS_LABEL, $label ) ? 'settings' : 'admin' );
		return array( 'label' => $label, 'href' => $href, 'kind' => $kind );
	}

	private static function dir_of( $file ) {
		$dir = dirname( $file );
		return '.' === $dir ? preg_replace( '/\.php$/', '', $file ) : $dir;
	}

	/** Active plugins as dir-slug => { name, file }, so readers of the links need no plugin list. */
	public static function active_names() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
				$out[ self::dir_of( $file ) ] = array(
					'name' => html_entity_decode( wp_strip_all_tags( (string) $data['Name'] ), ENT_QUOTES ),
					'file' => preg_replace( '/\.php$/', '', $file ),
				);
			}
		}
		return $out;
	}

	private static function plugin_set_hash() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$parts = array();
		foreach ( get_plugins() as $file => $data ) {
			$parts[] = $file . '@' . $data['Version'] . ( is_plugin_active( $file ) ? '+' : '-' );
		}
		return md5( implode( '|', $parts ) );
	}

	private static function store_key() {
		// v2: menu pages + the Settings-API census join the stored shape.
		return 'minn_admin_plugin_links_v2_' . get_current_user_id();
	}

	public static function stored() {
		$data = get_transient( self::store_key() );
		return is_array( $data ) ? $data + array( 'settings_api' => array() ) : array( 'captured' => 0, 'hash' => '', 'links' => array(), 'settings_api' => array() );
	}

	/** Stale after a day, or as soon as the installed set (or a version) changes. */
	public static function is_stale() {
		$s = self::stored();
		return ( time() - (int) $s['captured'] ) > self::STALE_AFTER || $s['hash'] !== self::plugin_set_hash();
	}

	public static function capture_url() {
		return add_query_arg(
			array( 'minn_links' => 1, 'minn_nonce' => Minn_Admin_Notices::nonce() ),
			admin_url( 'plugins.php' )
		);
	}

	/**
	 * Where each installed plugin lives inside Minn, keyed by dir slug:
	 * [ { label, go, section?, tab? } ]. Surfaces declare their plugin via
	 * the descriptor `plugin` key (a dir slug, "theme:slug", or a list);
	 * settings providers and license rows are matched by their own ids.
	 */
	public static function minn_links() {
		$out = array();
		$add = function ( $slugs, $link ) use ( &$out ) {
			foreach ( (array) $slugs as $slug ) {
				$slug = (string) $slug;
				if ( '' === $slug || 0 === strpos( $slug, 'theme:' ) ) {
					continue;
				}
				$out[ $slug ][] = $link;
			}
		};
		foreach ( Minn_Admin_Surfaces::for_current_user() as $surface ) {
			if ( empty( $surface['plugin'] ) || empty( $surface['id'] ) ) {
				continue;
			}
			$add( $surface['plugin'], array( 'label' => (string) $surface['label'], 'go' => (string) $surface['id'] ) );
		}
		if ( current_user_can( 'manage_options' ) ) {
			if ( function_exists( 'minn_admin_spam_providers' ) ) {
				foreach ( minn_admin_spam_providers() as $p ) {
					if ( ! empty( $p['plugin'] ) ) {
						$add( $p['plugin'], array( 'label' => __( 'Spam settings', 'minn-admin' ), 'go' => 'settings', 'section' => 'Comments' ) );
					}
				}
			}
			// Visibility providers only report while their mode is on, so the
			// doorway is keyed on the plugins the section knows how to drive.
			foreach ( self::VISIBILITY_PLUGINS as $dir ) {
				$add( $dir, array( 'label' => __( 'Visibility', 'minn-admin' ), 'go' => 'settings', 'section' => 'Visibility' ) );
			}
		}
		if ( class_exists( 'WooCommerce' ) && current_user_can( 'manage_woocommerce' ) ) {
			$add( 'woocommerce', array( 'label' => __( 'Store settings', 'minn-admin' ), 'go' => 'store-settings/general' ) );
		}
		if ( function_exists( 'minn_admin_licenses_can_manage' ) && minn_admin_licenses_can_manage() && function_exists( 'minn_admin_licenses' ) ) {
			foreach ( (array) minn_admin_licenses() as $row ) {
				$c = isset( $row['component'] ) ? (string) $row['component'] : '';
				if ( '' === $c || 0 === strpos( $c, 'theme:' ) ) {
					continue;
				}
				$add( self::dir_of( $c ), array( 'label' => __( 'License', 'minn-admin' ), 'go' => 'extensions', 'tab' => 'licenses' ) );
			}
		}
		// Only installed plugins; the static lists above name plugins a site
		// may not have.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$installed[ self::dir_of( $file ) ] = true;
		}
		$out = array_intersect_key( $out, $installed );
		// One entry per (slug, go, section): a plugin with two surfaces keeps
		// both, a provider listed twice does not.
		foreach ( $out as $slug => $links ) {
			$seen = array();
			$out[ $slug ] = array_values( array_filter( $links, function ( $l ) use ( &$seen ) {
				$k = $l['go'] . '|' . ( $l['section'] ?? '' ) . '|' . ( $l['tab'] ?? '' );
				if ( isset( $seen[ $k ] ) ) {
					return false;
				}
				$seen[ $k ] = true;
				return true;
			} ) );
		}
		return $out;
	}
}
