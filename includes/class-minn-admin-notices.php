<?php
/**
 * Admin-notice digest — extraction, never hosting.
 *
 * Minn never lets third-party PHP or markup run inside its own UI. Instead,
 * the client triggers a REAL wp-admin dashboard pageload (as the current
 * user, cookie-authenticated) with ?minn_notices=1. That request boots the
 * admin exactly like a human visit, so every plugin registers its notice
 * callbacks the normal way. Right before core would print the notices, Minn
 * renders each registered callback in an isolated output buffer, reduces the
 * output to structured data (severity, plain text, action links, owning
 * plugin via Reflection) and returns JSON instead of the page. Raw
 * third-party HTML is never stored and never reaches the SPA.
 *
 * The per-callback render + Reflection attribution technique is borrowed
 * from the Dismissed. project's notice extractor.
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Notices {

	const NONCE_ACTION = 'minn-notices';
	const STALE_AFTER  = 15 * MINUTE_IN_SECONDS;

	/** User option (per site) holding the last capture's menu removals. */
	const REMOVED_OPTION = 'minn_admin_menu_removed';

	/**
	 * How long a stored removal stands without a fresh capture. A capture
	 * runs whenever the app opens and the last one is 15 minutes old, so
	 * this only ends removals for someone whose captures stopped coming
	 * back (a dashboard that redirects them, or fatals).
	 */
	const REMOVED_FOR = WEEK_IN_SECONDS;

	/** On a capture request: which plugin or theme registered each post type and taxonomy. */
	private static $content_owners = array();

	/** On a capture request: the owners that have admin pages of their own. */
	private static $owners_with_pages = array();

	/**
	 * On a capture request: removed owners a list of a content type they
	 * registered still stands for in the menu, owner => [ 'pt:x', 'tax:y' ].
	 */
	private static $content_kept = array();

	/** On a capture request: admin pages surfaces name as theirs (adminPages) that a menu tool hid. */
	private static $pages_hidden = array();

	/**
	 * Folders of frameworks several plugins bundle and load once, from
	 * whichever copy is newest (or first). A page such a framework draws
	 * belongs to none of the plugins that bundle it: Crocoblock's dashboard
	 * and its JetPlugins Settings page, Freemius' Account and Contact pages,
	 * YITH's plugin panels, Tools › Scheduled Actions, CMB2, Redux, Codestar
	 * and Carbon Fields options pages, ThemeIsle's About pages. Matched in
	 * the path below the plugins folder, ignoring case.
	 */
	const SHARED_FRAMEWORKS = array( '/jet-dashboard/', '/freemius/', '/plugin-fw/', '/action-scheduler/', '/cmb2/', '/redux-core/', '/reduxcore/', '/redux-framework/', '/codestar-framework/', '/carbon-fields/', '/themeisle-sdk/' );

	/** Core callbacks Minn already covers with its own notifications. */
	const SKIP_CALLBACKS = array( 'update_nag', 'maintenance_nag', 'site_admin_notice' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_arm' ), PHP_INT_MAX );
		// Only the capture load needs to know who registered each content
		// type, and it has to listen before init, when they are registered.
		if ( ! empty( $_GET['minn_notices'] ) && is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_action( 'registered_post_type', array( __CLASS__, 'note_content_owner' ), 10, 1 );
			add_action( 'registered_taxonomy', array( __CLASS__, 'note_content_owner' ), 10, 1 );
		}
	}

	/**
	 * Remember which plugin or theme registered a post type or taxonomy,
	 * from the first frame of the call outside WordPress itself.
	 *
	 * @param string $name The post type or taxonomy.
	 */
	public static function note_content_owner( $name ) {
		$kind = 'registered_taxonomy' === current_action() ? 'tax:' : 'pt:';
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( empty( $frame['file'] ) ) {
				continue;
			}
			$owner = self::owner_of_file( wp_normalize_path( $frame['file'] ) );
			if ( 'plugin' === $owner['type'] && '' !== $owner['slug'] && dirname( plugin_basename( MINN_ADMIN_FILE ) ) !== $owner['slug'] ) {
				self::$content_owners[ $kind . $name ] = $owner['slug'];
				return;
			}
			if ( 'theme' === $owner['type'] && '' !== $owner['slug'] ) {
				self::$content_owners[ $kind . $name ] = 'theme:' . $owner['slug'];
				return;
			}
		}
	}

	/**
	 * On a flagged request, swallow the admin page output and schedule the
	 * capture for in_admin_header — the last moment before core fires the
	 * notice hooks, so every registration path (plugins_loaded, init,
	 * current_screen, admin_init, load-*) has run like a real visit.
	 */
	public static function maybe_arm() {
		if ( empty( $_GET['minn_notices'] ) ) {
			return;
		}
		// Our nonce rides a dedicated param — notice action links carry the
		// PLUGIN'S own _wpnonce, which must reach its handler untouched.
		$nonce = sanitize_text_field( wp_unslash( $_GET['minn_nonce'] ?? $_GET['_wpnonce'] ?? '' ) );
		if ( ! current_user_can( 'edit_posts' ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		// admin-header.php prints the document head before in_admin_header
		// fires; buffer it so the JSON response starts clean.
		ob_start();
		add_action( 'in_admin_header', array( __CLASS__, 'capture_and_respond' ), PHP_INT_MAX );
	}

	public static function capture_and_respond() {
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$items        = self::capture();
		$menu_removed = self::capture_removed_menus();
		$owners       = self::capture_removed_owners();
		$hiding_was   = self::surface_hiding();
		self::store( $items, $menu_removed, $owners );
		wp_send_json(
			array(
				'ok'               => true,
				'count'            => count( $items ),
				'captured'         => time(),
				'menu_removed'     => $menu_removed,
				// The app re-reads its surface list when this flips, so a
				// plugin hidden from wp-admin leaves Minn's nav right away.
				'surfaces_changed' => self::surface_hiding() !== $hiding_was,
			)
		);
	}

	/**
	 * Everything the stored removals say that decides which surfaces the
	 * current user keeps: removed owners, owners with pages, lists that
	 * keep a surface, hidden hosting pages.
	 *
	 * @return array
	 */
	public static function surface_hiding() {
		return array(
			self::removed_owners(),
			self::owners_with_pages(),
			self::owners_kept_by_content(),
			self::hidden_admin_pages(),
		);
	}

	/**
	 * Admin menu items a developer hid with remove_menu_page(), mapped to the
	 * Minn nav ids they correspond to. This runs inside the SAME hidden
	 * dashboard pageload as the notice capture — in_admin_header, after every
	 * admin_menu/admin_head callback has run like a real visit — so whatever
	 * the site's code removed from wp-admin's menu is genuinely absent from
	 * $GLOBALS['menu'] here. Cap-guarded per slug: a menu absent because THIS
	 * USER lacks the capability is normal gating, not a removal (Minn's own
	 * caps already hide those), so it is never reported.
	 */
	const WATCHED_MENUS = array(
		// admin slug => [ minn nav id, capability that would normally show it ]
		'edit-comments.php'   => array( 'comments', 'edit_posts' ),
		'upload.php'          => array( 'media', 'upload_files' ),
		'users.php'           => array( 'users', 'list_users' ),
		'plugins.php'         => array( 'extensions', 'activate_plugins' ),
		'options-general.php' => array( 'settings', 'manage_options' ),
	);

	public static function capture_removed_menus() {
		/**
		 * Filter whether Minn's nav mirrors admin-menu removals at all.
		 * Default true: a site that hides Comments from wp-admin gets the
		 * same courtesy in Minn (cosmetic, like remove_menu_page itself —
		 * routes stay reachable by URL and the command palette).
		 */
		if ( ! apply_filters( 'minn_admin_respect_removed_menus', true ) ) {
			return array();
		}
		$menu    = isset( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ? $GLOBALS['menu'] : array();
		$present = array();
		foreach ( $menu as $item ) {
			if ( isset( $item[2] ) ) {
				$present[ (string) $item[2] ] = true;
			}
		}
		$removed = array();
		/** Filter the watched slug => [minn id, cap] map. */
		$watched = apply_filters( 'minn_admin_watched_admin_menus', self::WATCHED_MENUS );
		foreach ( $watched as $slug => $def ) {
			if ( empty( $def[0] ) || empty( $def[1] ) ) {
				continue;
			}
			if ( ! isset( $present[ $slug ] ) && current_user_can( $def[1] ) ) {
				$removed[] = (string) $def[0];
			}
		}
		return $removed;
	}

	/**
	 * Plugins and themes whose every wp-admin menu page is gone for this
	 * user. This is how agencies hide a plugin from clients: a menu tool
	 * takes the plugin's menu away, and Minn drops that plugin's surfaces
	 * for the same user (Minn_Admin_Surfaces::for_current_user()). Only a
	 * tool that vouches through the filter below counts (the bundled White
	 * Label CMS adapter does). Runs in the same capture pageload as
	 * capture_removed_menus(), after every admin_menu callback.
	 *
	 * A page counts only when this user could open it. WordPress hooks a
	 * top-level page's callback, and adds a submenu page at all, only for
	 * users with its capability, so a plugin absent because of normal
	 * capability gating is never reported. A plugin with even one page
	 * still in the menu (or moved elsewhere in it) is not reported either.
	 * A list of a post type or taxonomy it registered that is still in the
	 * menu (Elementor's Templates) keeps only the surface that is that list
	 * (its contentTypes); the plugin's other surfaces leave. Pages a shared
	 * framework draws are no one's, and a surface whose plugin has no page
	 * of its own names the pages that host it (adminPages).
	 *
	 * Absence alone proves nothing: plugins hide their own setup and
	 * wizard pages exactly the way an agency hides a menu. So a plugin is
	 * reported only when a menu tool vouches for at least one of its pages
	 * through `minn_admin_admin_page_hidden` (adapters/white-label-cms.php
	 * answers from White Label CMS's own settings).
	 *
	 * @return string[] Owner keys spelled like a surface's `plugin` key:
	 *                  the plugin's folder, or 'theme:<folder>'.
	 */
	public static function capture_removed_owners() {
		if ( ! apply_filters( 'minn_admin_respect_removed_menus', true ) ) {
			return array();
		}
		global $menu, $submenu, $admin_page_hooks, $_parent_pages;
		self::$content_kept = array();
		self::$pages_hidden = array();
		$hooks = is_array( $admin_page_hooks ) ? $admin_page_hooks : array();
		// What the user sees: every top-level item, and the submenus of the
		// top-level items still there.
		$shown = array();
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) ) {
				$shown[ (string) $item[2] ] = true;
			}
		}
		foreach ( array_keys( $shown ) as $top ) {
			foreach ( (array) ( $submenu[ $top ] ?? array() ) as $item ) {
				if ( isset( $item[2] ) ) {
					$shown[ (string) $item[2] ] = true;
				}
			}
		}
		// Every page the user could open, by owner: slug => parent ('' for
		// a top-level page). Pages registered without a menu parent are
		// never in a menu, so they are left out.
		$pages = array();
		$tops  = array();
		foreach ( $hooks as $slug => $unused ) {
			$owner                  = self::page_owner( get_plugin_page_hookname( (string) $slug, '' ) );
			$tops[ (string) $slug ] = $owner;
			if ( '' !== $owner ) {
				$pages[ $owner ][ (string) $slug ] = '';
			}
		}
		foreach ( (array) $_parent_pages as $slug => $parent ) {
			if ( ! isset( $hooks[ (string) $parent ] ) ) {
				continue;
			}
			$owner = self::page_owner( get_plugin_page_hookname( (string) $slug, (string) $parent ), $tops[ (string) $parent ] ?? '' );
			if ( '' !== $owner && ! isset( $pages[ $owner ][ (string) $slug ] ) ) {
				$pages[ $owner ][ (string) $slug ] = (string) $parent;
			}
		}
		// Owners with a content screen still in the menu.
		$content_shown = array();
		foreach ( array_keys( $shown ) as $slug ) {
			$screen = strtok( (string) $slug, '?' );
			$q      = array();
			parse_str( (string) wp_parse_url( (string) $slug, PHP_URL_QUERY ), $q );
			if ( in_array( $screen, array( 'edit.php', 'post-new.php' ), true ) && ! empty( $q['post_type'] ) ) {
				$key = 'pt:' . $q['post_type'];
			} elseif ( 'edit-tags.php' === $screen && ! empty( $q['taxonomy'] ) ) {
				$key = 'tax:' . $q['taxonomy'];
			} else {
				continue;
			}
			if ( isset( self::$content_owners[ $key ] ) ) {
				$content_shown[ self::$content_owners[ $key ] ][] = $key;
			}
		}
		self::$owners_with_pages = array_keys( $pages );
		sort( self::$owners_with_pages );
		$removed = array();
		foreach ( $pages as $owner => $slugs ) {
			if ( array_intersect_key( $slugs, $shown ) ) {
				continue;
			}
			foreach ( $slugs as $slug => $parent ) {
				/**
				 * Filter whether a menu tool hid this admin page from the
				 * current user on purpose.
				 *
				 * @param bool   $hidden Default false.
				 * @param string $slug   The page's menu slug.
				 * @param string $parent Its parent menu slug, '' for a
				 *                       top-level page.
				 */
				if ( apply_filters( 'minn_admin_admin_page_hidden', false, (string) $slug, $parent ) ) {
					$removed[] = (string) $owner;
					if ( isset( $content_shown[ $owner ] ) ) {
						self::$content_kept[ (string) $owner ] = array_values( array_unique( $content_shown[ $owner ] ) );
					}
					break;
				}
			}
		}
		sort( $removed );
		ksort( self::$content_kept );
		// The hosting pages surfaces name, hidden from this user on purpose.
		foreach ( Minn_Admin_Surfaces::declared_admin_pages() as $slug ) {
			if ( isset( $shown[ $slug ] ) ) {
				continue;
			}
			$parent = is_array( $_parent_pages ) && isset( $_parent_pages[ $slug ] ) ? (string) $_parent_pages[ $slug ] : '';
			/** This filter is documented above. */
			if ( apply_filters( 'minn_admin_admin_page_hidden', false, $slug, $parent ) ) {
				self::$pages_hidden[] = $slug;
			}
		}
		sort( self::$pages_hidden );
		return $removed;
	}

	/**
	 * The plugin or theme that owns an admin page hook, or ''.
	 *
	 * A page a shared framework draws is no one's, except under a top-level
	 * menu a plugin draws itself when the framework copy that drew it is
	 * that same plugin's (Rank Math's CMB2 settings pages under its own
	 * menu). Both have to agree: another plugin's Freemius Account page
	 * drawn from Post SMTP's copy, or a YITH panel under the menu YITH's
	 * framework draws, stays no one's.
	 *
	 * @param string $hook The page's hook name.
	 * @param string $host The owner of the top-level menu it sits under, or ''.
	 * @return string
	 */
	private static function page_owner( $hook, $host = '' ) {
		if ( '' === $hook || empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
			return '';
		}
		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $cbs ) {
			foreach ( $cbs as $registered ) {
				$ref  = self::reflect( $registered['function'] );
				$file = $ref && $ref->getFileName() ? wp_normalize_path( $ref->getFileName() ) : '';
				if ( self::in_shared_framework( $file ) ) {
					$carrier = self::owner_of_file( $file );
					return '' !== $host && 'plugin' === $carrier['type'] && $carrier['slug'] === $host ? $host : '';
				}
				$owner = self::owner_of_file( $file );
				if ( 'plugin' === $owner['type'] && '' !== $owner['slug'] ) {
					return $owner['slug'];
				}
				if ( 'theme' === $owner['type'] && '' !== $owner['slug'] ) {
					return 'theme:' . $owner['slug'];
				}
				return '';
			}
		}
		return '';
	}

	/**
	 * Whether a file is part of a framework several plugins bundle
	 * (SHARED_FRAMEWORKS). Only the path below the plugins folder counts, so
	 * a site whose own folders carry one of those names keeps its hiding.
	 *
	 * @param string $file Normalized absolute path.
	 * @return bool
	 */
	private static function in_shared_framework( $file ) {
		$root = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		if ( '' === $file || 0 !== strpos( $file, $root ) ) {
			return false;
		}
		$rel = '/' . strtolower( substr( $file, strlen( $root ) ) );
		foreach ( self::SHARED_FRAMEWORKS as $dir ) {
			if ( false !== strpos( $rel, $dir ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Stored admin-menu removals for the boot payload (last capture's view).
	 * Kept per user and per site, so a client never sees a hidden item come
	 * back for a moment while a fresh capture runs, for a week since the
	 * last capture that came back (REMOVED_FOR).
	 */
	public static function menu_removed() {
		$kept = self::kept_removals();
		if ( is_array( $kept ) && isset( $kept['nav'] ) && is_array( $kept['nav'] ) ) {
			return $kept['nav'];
		}
		$stored = self::stored();
		return isset( $stored['menu_removed'] ) && is_array( $stored['menu_removed'] ) ? $stored['menu_removed'] : array();
	}

	/** Owners whose whole wp-admin menu the last capture found removed. */
	public static function removed_owners() {
		if ( ! get_current_user_id() || ! apply_filters( 'minn_admin_respect_removed_menus', true ) ) {
			return array();
		}
		$kept = self::kept_removals();
		return is_array( $kept ) && isset( $kept['owners'] ) && is_array( $kept['owners'] ) ? $kept['owners'] : array();
	}

	/**
	 * Owners the last capture found with admin pages of their own, hidden
	 * or not. A surface several plugins share leaves only when every one of
	 * them with pages is gone (Minn_Admin_Surfaces::owners_gone()); empty
	 * when no capture has recorded it yet.
	 */
	public static function owners_with_pages() {
		$kept = self::kept_removals();
		return is_array( $kept ) && isset( $kept['paged'] ) && is_array( $kept['paged'] ) ? $kept['paged'] : array();
	}

	/**
	 * Removed owners a list of a content type they registered still stands
	 * for in the menu: owner => [ 'pt:x', 'tax:y' ]. A surface that is one
	 * of those lists (its contentTypes) stays; the owner's others leave.
	 */
	public static function owners_kept_by_content() {
		if ( ! get_current_user_id() || ! apply_filters( 'minn_admin_respect_removed_menus', true ) ) {
			return array();
		}
		$kept = self::kept_removals();
		return is_array( $kept ) && isset( $kept['content'] ) && is_array( $kept['content'] ) ? $kept['content'] : array();
	}

	/** Hosting pages surfaces name (adminPages) that the last capture found hidden. */
	public static function hidden_admin_pages() {
		if ( ! get_current_user_id() || ! apply_filters( 'minn_admin_respect_removed_menus', true ) ) {
			return array();
		}
		$kept = self::kept_removals();
		return is_array( $kept ) && isset( $kept['pages'] ) && is_array( $kept['pages'] ) ? $kept['pages'] : array();
	}

	/** The stored removals, unless no capture has refreshed them for REMOVED_FOR. */
	private static function kept_removals() {
		$kept = get_user_option( self::REMOVED_OPTION );
		if ( ! is_array( $kept ) ) {
			return null;
		}
		// Kept without a time by older versions: valid until the next capture stamps it.
		if ( isset( $kept['at'] ) && time() - (int) $kept['at'] > self::REMOVED_FOR ) {
			return null;
		}
		return $kept;
	}

	/**
	 * Render every admin-notice callback in isolation and reduce the output
	 * to structured entries. Only admin_notices + all_admin_notices — the
	 * hooks a normal site dashboard fires (network/user admin are out of
	 * scope, like multisite generally).
	 */
	public static function capture() {
		$entries = array();
		foreach ( array( 'admin_notices', 'all_admin_notices' ) as $hook ) {
			if ( empty( $GLOBALS['wp_filter'][ $hook ] ) ) {
				continue;
			}
			foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $priority => $cbs ) {
				foreach ( $cbs as $registered ) {
					$cb    = $registered['function'];
					$owner = self::owner_of( $cb );
					if ( 'minn-admin' === $owner['slug'] ) {
						continue;
					}
					if ( 'core' === $owner['type'] && in_array( self::callback_name( $cb ), self::SKIP_CALLBACKS, true ) ) {
						continue;
					}
					ob_start();
					try {
						call_user_func( $cb );
					} catch ( \Throwable $e ) {
						// A broken notice callback must never break the digest.
					}
					$html = trim( (string) ob_get_clean() );
					if ( '' === $html ) {
						continue;
					}
					foreach ( self::parse( $html ) as $entry ) {
						$entry['owner'] = $owner;
						$entry['id']    = substr( md5( $owner['slug'] . '|' . strtolower( $entry['text'] ) ), 0, 12 );
						// One capture can re-emit the same notice (a callback
						// registered on both hooks) — keep the first.
						$entries[ $entry['id'] ] = $entry;
					}
				}
			}
		}
		return array_values( $entries );
	}

	/**
	 * Reduce one callback's output to entries. A callback may print several
	 * .notice boxes (registry/dispatcher pattern), so each un-nested element
	 * with a notice-ish class becomes its own entry; output with no such
	 * element is treated as a single blob.
	 */
	public static function parse( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $doc->loadHTML(
			'<?xml encoding="UTF-8"><html><body><div id="minn-cap">' . $html . '</div></body></html>',
			LIBXML_NONET
		);
		libxml_clear_errors();
		if ( ! $loaded ) {
			return array();
		}
		$wrap = $doc->getElementById( 'minn-cap' );
		if ( ! $wrap ) {
			return array();
		}

		// Notice output is copy, not behaviour — drop non-content subtrees.
		foreach ( array( 'script', 'style', 'template', 'iframe', 'form', 'svg' ) as $tag ) {
			$nodes = array();
			foreach ( $wrap->getElementsByTagName( $tag ) as $n ) {
				$nodes[] = $n;
			}
			foreach ( $nodes as $n ) {
				$n->parentNode->removeChild( $n );
			}
		}

		$boxes = array();
		foreach ( $wrap->getElementsByTagName( '*' ) as $el ) {
			if ( ! self::is_notice_box( $el ) ) {
				continue;
			}
			$nested = false;
			for ( $p = $el->parentNode; $p && $p !== $wrap; $p = $p->parentNode ) {
				if ( $p instanceof DOMElement && self::is_notice_box( $p ) ) {
					$nested = true;
					break;
				}
			}
			if ( ! $nested ) {
				$boxes[] = $el;
			}
		}
		if ( ! $boxes ) {
			$boxes = array( $wrap );
		}

		$entries = array();
		foreach ( $boxes as $box ) {
			$links = self::links_of( $box );
			$text  = self::text_of( $box );
			// Button CTAs and in-panel action links also appear as textContent
			// of the notice — strip their labels so the body is not
			// "Allow No, Thanks" / "No, thanks." with no way to click them
			// (Everest Forms + ThemeIsle review-nag pattern).
			foreach ( $links as $l ) {
				if ( ! empty( $l['text'] ) && ( ! empty( $l['button'] ) || ! empty( $l['action'] ) ) ) {
					$text = self::strip_label( $text, $l['text'] );
				}
			}
			$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
			if ( strlen( $text ) < 8 ) {
				continue;
			}
			$class     = $box instanceof DOMElement ? (string) $box->getAttribute( 'class' ) : '';
			$entries[] = array(
				'severity'    => self::severity_of( $class ),
				'dismissible' => (bool) preg_match( '/\bis-dismissible\b/', $class ),
				'text'        => $text,
				'links'       => $links,
			);
		}
		return $entries;
	}

	/**
	 * Remove a surfaced CTA's label from the notice body — LAST occurrence,
	 * whole word only. Short labels ("Yes", "No") would otherwise eat the
	 * first matching substring anywhere in the sentence.
	 */
	private static function strip_label( $text, $label ) {
		$q = preg_quote( $label, '/' );
		$stripped = preg_replace( '/\b' . $q . '\b(?!.*\b' . $q . '\b)/us', '', $text, 1 );
		return null !== $stripped ? $stripped : $text;
	}

	private static function is_notice_box( $el ) {
		if ( ! $el instanceof DOMElement ) {
			return false;
		}
		$class = ' ' . $el->getAttribute( 'class' ) . ' ';
		return (bool) preg_match( '/\s(notice|updated|error)(\s|-)/', $class );
	}

	private static function severity_of( $class ) {
		$class = ' ' . $class . ' ';
		if ( preg_match( '/\s(notice-error|error)\s/', $class ) ) {
			return 'error';
		}
		if ( false !== strpos( $class, 'notice-warning' ) ) {
			return 'warning';
		}
		if ( preg_match( '/\s(notice-success|updated)\s/', $class ) ) {
			return 'success';
		}
		return 'info';
	}

	private static function text_of( $node ) {
		// textContent fuses element boundaries into one run ("…enjoying the
		// plugin?YesNo" — the Smash Balloon step-1 shape), so join text
		// nodes with spaces instead, then tidy the space a split leaves
		// before punctuation.
		$parts = array();
		$xpath = new DOMXPath( $node->ownerDocument );
		foreach ( $xpath->query( './/text()', $node ) as $t ) {
			$chunk = preg_replace( '/\s+/u', ' ', trim( (string) $t->nodeValue ) );
			if ( '' !== $chunk ) {
				$parts[] = $chunk;
			}
		}
		$text = implode( ' ', $parts );
		$text = preg_replace( '/\s+([.,:;!?…)\]])/u', '$1', $text );
		// The capture is stored and served later, so it is cut after the
		// fence has seen it whole (Minn_Admin_Egress::clip()).
		return Minn_Admin_Egress::clip( $text, 399 );
	}

	/**
	 * Visible control text without screen-reader-only accessibility suffixes.
	 *
	 * WordPress and plugins commonly append text such as "(opens in a new
	 * tab)" inside .screen-reader-text. Minn supplies its own accessible
	 * control and external-link marker, so copying that hidden suffix into
	 * the visible button duplicates the affordance and can leave English in
	 * an otherwise translated panel.
	 */
	private static function control_text_of( $node ) {
		$parts = array();
		$xpath = new DOMXPath( $node->ownerDocument );
		$query = './/text()['
			. 'not(ancestor::*[contains(concat(" ", normalize-space(@class), " "), " screen-reader-text ")])'
			. ' and not(ancestor::*[@aria-hidden="true"])'
			. ']';
		foreach ( $xpath->query( $query, $node ) as $text ) {
			$chunk = preg_replace( '/\s+/u', ' ', trim( (string) $text->nodeValue ) );
			if ( '' !== $chunk ) {
				$parts[] = $chunk;
			}
		}
		return Minn_Admin_Egress::clip( implode( ' ', $parts ), 80, '' );
	}

	/**
	 * Up to 3 action links, absolutized; text and URL only.
	 *
	 * Links carrying our capture params are notices that built their href
	 * from the CURRENT request URI (add_query_arg with no URL) — the
	 * allow/dismiss/opt-in class of action link that expects to reload the
	 * page it rendered on. They get flagged `action` so the client can run
	 * them in the background, and our params are stripped (only OUR nonce —
	 * a plugin's own _wpnonce value is preserved).
	 *
	 * Button CTAs with href="#" (or empty) are also extracted when they look
	 * like real choices (WP .button class, or labels like "No, Thanks" /
	 * "Allow"). Those fire only via plugin admin-ajax JS in wp-admin; Minn
	 * surfaces them as action buttons and, when the class maps to a known
	 * handler, runs it through minn-admin/v1/notices/ajax (whitelist).
	 */
	private static function links_of( $node ) {
		$links     = array();
		$seen      = array();
		$own_nonce = sanitize_text_field( wp_unslash( $_GET['minn_nonce'] ?? $_GET['_wpnonce'] ?? '' ) );
		// Anchors AND <button> elements, in document order — plugins render
		// JS-only choices both ways (Smash Balloon's "Yes" is a literal
		// <button>; its "No" is an anchor). Order matters for choice pairs.
		$xpath = new DOMXPath( $node->ownerDocument );
		foreach ( $xpath->query( './/a|.//button', $node ) as $a ) {
			if ( count( $links ) >= 3 ) {
				break;
			}
			$class = (string) $a->getAttribute( 'class' );
			$id    = (string) $a->getAttribute( 'id' );
			$label = self::control_text_of( $a );

			// A button element IS a control (anchors can be decoration), but
			// only ones Minn can honestly answer are surfaced: a whitelisted
			// ajax mapping, or a dismiss-shaped label where digest-hide
			// matches intent.
			if ( 'button' === strtolower( $a->tagName ) ) {
				if ( '' === $label ) {
					continue;
				}
				$ajax     = self::ajax_for_button( $class, $label, $id );
				$dismissy = (bool) preg_match( '/^(No,?\s*thanks|Allow|Dismiss|Not now|Maybe later|Skip|Deny|Opt\s*out)\b/i', $label );
				if ( ! $ajax && ! $dismissy ) {
					continue;
				}
				$key = 'btn:' . strtolower( $label ) . '|' . $class . '|' . $id;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$entry        = array(
					'text'   => $label,
					'url'    => '',
					'action' => true,
					'button' => true,
				);
				if ( $ajax ) {
					$entry['ajax'] = $ajax;
				}
				$links[] = $entry;
				continue;
			}

			$href = trim( (string) $a->getAttribute( 'href' ) );
			$label = $label ?: __( 'Open', 'minn-admin' );

			// JS-only button CTAs (href="#" / empty / javascript:).
			$is_hash = ( '' === $href || '#' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'javascript:' ) );
			if ( $is_hash ) {
				$looks_button = (bool) preg_match( '/\bbutton\b/', $class )
					|| (bool) preg_match( '/^(No,?\s*thanks|Allow|Dismiss|Not now|Maybe later|Skip|Deny|Opt\s*out)\b/i', $label );
				if ( ! $looks_button ) {
					continue;
				}
				$key = 'btn:' . strtolower( $label ) . '|' . $class;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$entry        = array(
					'text'   => $label,
					'url'    => '',
					'action' => true,
					'button' => true,
				);
				$ajax = self::ajax_for_button( $class, $label, $id );
				if ( $ajax ) {
					$entry['ajax'] = $ajax;
				}
				$links[] = $entry;
				if ( count( $links ) >= 3 ) {
					break;
				}
				continue;
			}

			if ( preg_match( '#^https?://#i', $href ) ) {
				$url = $href;
			} elseif ( '/' === $href[0] ) {
				$url = home_url( $href );
			} else {
				$url = admin_url( $href );
			}
			// Capture-piggyback links (minn_notices=) OR admin dismiss/opt-out
			// URLs (ThemeIsle tsdk_dismiss_nonce, nid=, generic dismiss).
			$is_action = ( false !== strpos( $url, 'minn_notices=' ) && self::is_same_origin( $url ) )
				|| self::is_admin_dismiss_url( $url, $label );
			if ( false !== strpos( $url, 'minn_notices=' ) ) {
				$url = remove_query_arg( array( 'minn_notices', 'minn_nonce' ), $url );
				// A _wpnonce here is only stripped when it's OURS (legacy
				// capture URLs) — a plugin's own action nonce must survive.
				if ( $own_nonce && false !== strpos( $url, '_wpnonce=' . $own_nonce ) ) {
					$url = remove_query_arg( '_wpnonce', $url );
				}
			}
			$url = esc_url_raw( $url );
			if ( ! $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$entry        = array(
				'text'   => $label,
				'url'    => $url,
				'action' => $is_action,
			);
			// A choice can carry BOTH a real href and a JS handler (Smash
			// Balloon's "No": feedback URL + consent ajax) — the mapped
			// ajax is the meaningful action, so it wins over the plain ↗.
			$ajax = self::ajax_for_button( $class, $label, $id );
			if ( $ajax ) {
				$entry['action'] = true;
				$entry['ajax']   = $ajax;
			}
			$links[] = $entry;
			if ( count( $links ) >= 3 ) {
				break;
			}
		}

		return $links;
	}

	/**
	 * Admin-page dismiss / opt-out links that must run in-panel, not as
	 * window.open ↗. ThemeIsle SDK (Otter "No, thanks." → index.php?nid=…
	 * &tsdk_dismiss_nonce=…), and similar dismiss nonces on wp-admin URLs.
	 * External review CTAs (wordpress.org) stay non-action.
	 */
	/**
	 * Whether a URL is on this site.
	 *
	 * An "action" link is fetched by the app with Minn's capture nonce
	 * appended, so the origin has to be established before anything is
	 * classified as one. Notice HTML comes from third-party plugins and can
	 * embed unauthenticated input, so the anchor is not necessarily trusted.
	 */
	private static function is_same_origin( $url ) {
		$host = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' );
		$site = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
		// hrefs are absolutized against home_url()/admin_url() before this
		// runs, so a genuine relative admin path already carries the site host.
		// Scheme and port count: this classification is what decides whether
		// the client appends the capture nonce to a URL, and an http:// or
		// off-port twin of this host is a different browser origin.
		if ( '' === $host || '' === $site || 0 !== strcasecmp( $host, $site ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( wp_parse_url( $url, PHP_URL_SCHEME ) ?: '' ) );
		$mine   = strtolower( (string) ( wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: '' ) );
		if ( '' !== $scheme && '' !== $mine && $scheme !== $mine ) {
			return false;
		}
		$port     = wp_parse_url( $url, PHP_URL_PORT );
		$own_port = wp_parse_url( home_url(), PHP_URL_PORT );
		return (int) $port === (int) $own_port;
	}

	private static function is_admin_dismiss_url( $url, $label = '' ) {
		// Never treat public marketing / directory URLs as dismiss actions.
		if ( preg_match( '#https?://(?:www\.)?wordpress\.org/#i', $url ) ) {
			return false;
		}
		$admin = wp_parse_url( admin_url(), PHP_URL_PATH );
		$path  = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '' );
		$host  = (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?: '' );
		$site  = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' );
		// Must be same-site admin (or relative admin path we already absolutized).
		// Both clauses require the SAME ORIGIN. The second used to compare only
		// the path against /wp-admin/ and ignore the host entirely, so
		// https://evil.example/wp-admin/x?dismiss=1 satisfied it and was then
		// fetched with Minn's nonce attached, presented as a local button.
		$is_admin = self::is_same_origin( $url )
			&& ( false !== strpos( $path, '/wp-admin' ) || ( $admin && 0 === strpos( $path, $admin ) ) );
		if ( ! $is_admin ) {
			return false;
		}
		$query = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?: '' );
		// ThemeIsle SDK + common dismiss flags.
		if ( preg_match( '/(?:^|&)(?:tsdk_dismiss_nonce|[\w-]*dismiss[\w_]*|nid)=/i', $query ) ) {
			return true;
		}
		// Explicit dismiss labels on any admin link.
		if ( preg_match( '/^(No,?\s*thanks\.?|Dismiss|Not now|Maybe later|Skip|Remind me later)$/i', trim( $label ) ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Map known JS-dismiss button classes to a whitelist ajax action.
	 * Handlers run via Minn_Admin_Notices::run_ajax() (never arbitrary ajax).
	 *
	 * @return array{action:string,args:array}|null
	 */
	private static function ajax_for_button( $class, $label, $id = '' ) {
		/**
		 * Map a JS-dismiss notice button to a whitelisted ajax action before
		 * the built-in matches run. Return array{action:string,args:array} to
		 * claim the button; the action must also exist in the
		 * `minn_admin_notice_ajax_actions` whitelist or run_ajax() refuses it.
		 * (The dev-fixtures mu-plugin registers its suite buttons here.)
		 *
		 * @param array|null $mapped null to fall through to built-ins.
		 * @param string     $class  Button class attribute.
		 * @param string     $label  Button text.
		 * @param string     $id     Button id attribute.
		 */
		$mapped = apply_filters( 'minn_admin_notice_button_ajax', null, $class, $label, $id );
		if ( is_array( $mapped ) && ! empty( $mapped['action'] ) ) {
			return $mapped;
		}
		// Smash Balloon Instagram Feed review step 1 ("Are you enjoying…"):
		// Yes is a literal <button>, No is an anchor that ALSO carries a
		// feedback URL; both fire sbi_review_notice_consent_update.
		if ( 'sbi_review_consent_yes' === $id ) {
			return array(
				'action' => 'sbi_review_notice_consent_update',
				'args'   => array( 'consent' => 'yes' ),
			);
		}
		if ( 'sbi_review_consent_no' === $id ) {
			return array(
				'action' => 'sbi_review_notice_consent_update',
				'args'   => array( 'consent' => 'no' ),
			);
		}
		$class = ' ' . $class . ' ';
		// Everest Forms allow-usage notice (html-notice-allow-usage.php).
		if ( false !== strpos( $class, 'evf-deny-data-sharing' ) ) {
			return array(
				'action' => 'everest_forms_allow_usage_dismiss',
				'args'   => array( 'allow_usage_tracking' => 'false' ),
			);
		}
		if ( false !== strpos( $class, 'evf-allow-data-sharing' ) ) {
			return array(
				'action' => 'everest_forms_allow_usage_dismiss',
				'args'   => array( 'allow_usage_tracking' => 'true' ),
			);
		}
		return null;
	}

	/**
	 * Whitelisted notice ajax handlers Minn can run on the user's behalf.
	 * Keys are admin-ajax action names; values describe allowed args.
	 */
	public static function ajax_whitelist() {
		$map = array(
			'everest_forms_allow_usage_dismiss' => array(
				'args' => array(
					'allow_usage_tracking' => array( 'true', 'false' ),
				),
				// The handler re-checks this itself; declaring it here is what
				// the dispatcher enforces before the handler is reached.
				'cap'  => 'manage_options',
				// Mirror the plugin handler: no arbitrary POST keys.
				'run'  => array( __CLASS__, 'run_everest_allow_usage' ),
			),
			'sbi_review_notice_consent_update'  => array(
				'args' => array(
					'consent' => array( 'yes', 'no' ),
				),
				'cap'  => 'manage_options',
				'run'  => array( __CLASS__, 'run_sbi_review_consent' ),
			),
		);
		/**
		 * Filter the whitelist of notice admin-ajax actions Minn may run.
		 *
		 * `cap` is REQUIRED: the route this reaches is only edit_posts, so the
		 * dispatcher refuses any entry that does not declare the capability
		 * its handler needs, and checks it before calling `run`.
		 *
		 * @param array $map action => { args, cap, run }.
		 */
		return apply_filters( 'minn_admin_notice_ajax_actions', $map );
	}

	/**
	 * Run a whitelisted notice ajax action (Everest "No, Thanks" / "Allow").
	 *
	 * @param string $action Admin-ajax action name.
	 * @param array  $args   Sanitized args from the client.
	 * @return true|WP_Error
	 */
	public static function run_ajax( $action, $args = array() ) {
		$map = self::ajax_whitelist();
		if ( ! isset( $map[ $action ] ) || ! is_callable( $map[ $action ]['run'] ) ) {
			return new WP_Error( 'unknown_action', __( 'That notice action is not supported.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$def = $map[ $action ];
		// Every entry must declare the capability its handler needs, and the
		// dispatcher enforces it here. The route itself is only edit_posts,
		// and the whitelist is filterable with the docs pointing third parties
		// at it, so without this a registrant that mirrors a plugin's
		// admin-ajax handler and forgets its own check would hand a
		// contributor a privileged option write.
		if ( empty( $def['cap'] ) || ! is_string( $def['cap'] ) ) {
			return new WP_Error( 'no_cap', __( 'That notice action does not declare a capability.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		if ( ! current_user_can( $def['cap'] ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot run that notice action.', 'minn-admin' ), array( 'status' => 403 ) );
		}
		$safe = array();
		foreach ( (array) ( $def['args'] ?? array() ) as $key => $allowed ) {
			if ( ! isset( $args[ $key ] ) ) {
				continue;
			}
			$val = is_bool( $args[ $key ] ) ? ( $args[ $key ] ? 'true' : 'false' ) : (string) $args[ $key ];
			if ( is_array( $allowed ) && ! in_array( $val, $allowed, true ) ) {
				continue;
			}
			$safe[ $key ] = $val;
		}
		$result = call_user_func( $def['run'], $safe );
		return is_wp_error( $result ) ? $result : true;
	}

	/** Everest Forms allow-usage notice — mirrors allow_usage_dismiss without wp_die(). */
	public static function run_everest_allow_usage( $args ) {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_everest_forms' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot dismiss this notice.', 'minn-admin' ), array( 'status' => 403 ) );
		}
		update_option( 'everest_forms_allow_usage_notice_shown', true );
		if ( isset( $args['allow_usage_tracking'] ) && 'true' === $args['allow_usage_tracking'] ) {
			update_option( 'everest_forms_allow_usage_tracking', 'yes' );
		}
		return true;
	}

	/**
	 * Smash Balloon Instagram Feed review consent — mirrors the plugin's own
	 * wp_ajax_sbi_review_notice_consent_update handler (class-sbi-new-user.php:
	 * consent option, dismissal stamps on "no", and the notice-registry
	 * removals so their stored notices stop rendering).
	 */
	public static function run_sbi_review_consent( $args ) {
		$cap = current_user_can( 'manage_instagram_feed_options' ) ? 'manage_instagram_feed_options' : 'manage_options';
		$cap = apply_filters( 'sbi_settings_pages_capability', $cap );
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot answer this notice.', 'minn-admin' ), array( 'status' => 403 ) );
		}
		$consent = ( isset( $args['consent'] ) && 'yes' === $args['consent'] ) ? 'yes' : 'no';
		update_option( 'sbi_review_consent', $consent );
		if ( 'no' === $consent ) {
			update_option( 'sbi_rating_notice', 'dismissed', false );
			$statuses = get_option( 'sbi_statuses', array() );
			if ( is_array( $statuses ) ) {
				$statuses['rating_notice_dismissed'] = function_exists( 'sbi_get_current_time' ) ? sbi_get_current_time() : time();
				update_option( 'sbi_statuses', $statuses, false );
			}
		}
		global $sbi_notices;
		if ( $sbi_notices && method_exists( $sbi_notices, 'remove_notice' ) ) {
			$sbi_notices->remove_notice( 'review_step_1' );
			$sbi_notices->remove_notice( 'review_step_1_all_pages' );
			if ( 'no' === $consent ) {
				$sbi_notices->remove_notice( 'review_step_2' );
				$sbi_notices->remove_notice( 'review_step_2_all_pages' );
			}
		}
		return true;
	}

	/** Resolve a callback to the component that owns its file. */
	public static function owner_of( $cb ) {
		$ref  = self::reflect( $cb );
		$file = $ref && $ref->getFileName() ? wp_normalize_path( $ref->getFileName() ) : '';
		return self::owner_of_file( $file );
	}

	/** The plugin, theme or core a file belongs to. */
	private static function owner_of_file( $file ) {
		if ( ! $file ) {
			return array( 'type' => 'unknown', 'slug' => '', 'name' => 'Unknown' );
		}
		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		if ( 0 === strpos( $file, $plugin_dir ) ) {
			$slug = explode( '/', ltrim( substr( $file, strlen( $plugin_dir ) ), '/' ) )[0];
			$name = self::plugin_name( $slug );
			return array( 'type' => 'plugin', 'slug' => $slug, 'name' => $name );
		}
		$mu_dir = wp_normalize_path( WPMU_PLUGIN_DIR );
		if ( 0 === strpos( $file, $mu_dir ) ) {
			$slug = explode( '/', ltrim( substr( $file, strlen( $mu_dir ) ), '/' ) )[0];
			$name = ucwords( str_replace( array( '-', '_' ), ' ', preg_replace( '/\.php$/', '', $slug ) ) );
			return array( 'type' => 'mu-plugin', 'slug' => $slug, 'name' => $name );
		}
		$theme_root = wp_normalize_path( get_theme_root() );
		if ( 0 === strpos( $file, $theme_root ) ) {
			$slug  = explode( '/', ltrim( substr( $file, strlen( $theme_root ) ), '/' ) )[0];
			$theme = wp_get_theme( $slug );
			return array( 'type' => 'theme', 'slug' => $slug, 'name' => $theme->exists() ? $theme->get( 'Name' ) : $slug );
		}
		if ( 0 === strpos( $file, wp_normalize_path( ABSPATH . 'wp-admin' ) )
			|| 0 === strpos( $file, wp_normalize_path( ABSPATH . WPINC ) ) ) {
			return array( 'type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress' );
		}
		return array( 'type' => 'other', 'slug' => '', 'name' => basename( dirname( $file ) ) );
	}

	private static function plugin_name( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( get_plugins() as $pf => $data ) {
			if ( 0 === strpos( $pf, $slug . '/' ) || $pf === $slug ) {
				return $data['Name'] ?: $slug;
			}
		}
		return $slug;
	}

	private static function reflect( $cb ) {
		try {
			if ( is_string( $cb ) ) {
				return false !== strpos( $cb, '::' )
					? new ReflectionMethod( $cb )
					: new ReflectionFunction( $cb );
			}
			if ( is_array( $cb ) && 2 === count( $cb ) ) {
				return new ReflectionMethod( is_object( $cb[0] ) ? get_class( $cb[0] ) : $cb[0], $cb[1] );
			}
			if ( $cb instanceof Closure ) {
				return new ReflectionFunction( $cb );
			}
			if ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
				return new ReflectionMethod( $cb, '__invoke' );
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	private static function callback_name( $cb ) {
		if ( is_string( $cb ) ) {
			return false !== strpos( $cb, '::' ) ? substr( strrchr( $cb, ':' ), 1 ) : $cb;
		}
		if ( is_array( $cb ) && isset( $cb[1] ) && is_string( $cb[1] ) ) {
			return $cb[1];
		}
		return '';
	}

	/* ===== Storage ===== */

	private static function store_key() {
		// v5: menu_removed joins the stored shape (admin-menu removals mirror
		// into Minn's nav). v4: ThemeIsle / admin dismiss URLs (nid=,
		// tsdk_dismiss_nonce, "No, thanks." labels) flagged as in-panel
		// actions, not ↗ tabs. v3 was href="#" button CTAs. Versioning
		// invalidates stale captures.
		return 'minn_admin_notices_v5_' . get_current_user_id();
	}

	private static function store( $items, $menu_removed = array(), $owners = array() ) {
		set_transient(
			self::store_key(),
			array(
				'captured'     => time(),
				'items'        => $items,
				'menu_removed' => $menu_removed,
			),
			DAY_IN_SECONDS
		);
		$removed = array(
			'nav'     => array_values( $menu_removed ),
			'owners'  => array_values( $owners ),
			'paged'   => array_values( self::$owners_with_pages ),
			'content' => self::$content_kept,
			'pages'   => array_values( self::$pages_hidden ),
		);
		// Rewritten when what it says changes, and at least daily so its
		// stamp shows captures are still coming back.
		$was     = get_user_option( self::REMOVED_OPTION );
		$was_at  = is_array( $was ) ? (int) ( $was['at'] ?? 0 ) : 0;
		$was_say = is_array( $was ) ? array_diff_key( $was, array( 'at' => true ) ) : null;
		if ( $was_say !== $removed || time() - $was_at > DAY_IN_SECONDS ) {
			update_user_option( get_current_user_id(), self::REMOVED_OPTION, $removed + array( 'at' => time() ) );
		}

		// First-seen stamps drive notification time + unread state. Absent
		// hashes are kept 30 days so a flickering notice doesn't re-surface
		// as new on every capture.
		$uid  = get_current_user_id();
		$seen = get_user_meta( $uid, 'minn_admin_notice_seen', true );
		$seen = is_array( $seen ) ? $seen : array();
		$now  = time();
		$live = array();
		foreach ( $items as $item ) {
			$live[ $item['id'] ] = isset( $seen[ $item['id'] ] ) ? (int) $seen[ $item['id'] ] : $now;
		}
		foreach ( $seen as $hash => $ts ) {
			if ( ! isset( $live[ $hash ] ) && ( $now - (int) $ts ) < 30 * DAY_IN_SECONDS ) {
				$live[ $hash ] = (int) $ts;
			}
		}
		if ( count( $live ) > 200 ) {
			arsort( $live );
			$live = array_slice( $live, 0, 200, true );
		}
		update_user_meta( $uid, 'minn_admin_notice_seen', $live );
	}

	public static function stored() {
		$data = get_transient( self::store_key() );
		return is_array( $data ) ? $data : array(
			'captured' => 0,
			'items'    => array(),
		);
	}

	/* ===== Hidden notices =====
	 *
	 * Some notices dismiss only through their plugin's own admin-ajax handler
	 * wired in enqueued JS (Brizy's rating nag, WPBakery's notice list) — no
	 * followable link exists, so Minn cannot replay the dismissal without a
	 * per-plugin registry of action names, nonces and params. Instead, Minn
	 * hides the notice from ITS OWN digest: ids are content-stable
	 * (md5 of slug|text), so a hidden id stays suppressed across re-captures
	 * until the notice's text changes — at which point it is arguably new.
	 */

	private static function hidden() {
		$h = get_user_meta( get_current_user_id(), 'minn_admin_notice_hidden', true );
		return is_array( $h ) ? $h : array();
	}

	public static function hide( $id ) {
		$h        = self::hidden();
		$h[ $id ] = time();
		if ( count( $h ) > 200 ) {
			arsort( $h );
			$h = array_slice( $h, 0, 200, true );
		}
		update_user_meta( get_current_user_id(), 'minn_admin_notice_hidden', $h );
	}

	public static function unhide( $id ) {
		// Empty / "all" clears every hide so a previous Hide cannot suppress
		// fixtures (or a real notice that text-stable-id'd across captures).
		if ( '' === $id || 'all' === $id || '*' === $id ) {
			delete_user_meta( get_current_user_id(), 'minn_admin_notice_hidden' );
			return;
		}
		$h = self::hidden();
		unset( $h[ $id ] );
		update_user_meta( get_current_user_id(), 'minn_admin_notice_hidden', $h );
	}

	public static function is_stale() {
		return ( time() - (int) self::stored()['captured'] ) > self::STALE_AFTER;
	}

	public static function nonce() {
		return wp_create_nonce( self::NONCE_ACTION );
	}

	public static function capture_url() {
		return add_query_arg(
			array(
				'minn_notices' => 1,
				'minn_nonce'   => self::nonce(),
			),
			admin_url( 'index.php' )
		);
	}

	/** Captured notices shaped as items for the notifications endpoint. */
	public static function items_for_user() {
		$icons = array(
			'error'   => '⛔',
			'warning' => '⚠️',
			'success' => '✅',
			'info'    => 'ℹ️',
		);
		$seen   = get_user_meta( get_current_user_id(), 'minn_admin_notice_seen', true );
		$seen   = is_array( $seen ) ? $seen : array();
		$hidden = self::hidden();
		$items  = array();
		foreach ( self::stored()['items'] as $n ) {
			if ( isset( $hidden[ $n['id'] ] ) ) {
				continue;
			}
			// A credential saved since the capture is masked before the cut too.
			$text    = Minn_Admin_Egress::clip( $n['text'], 159 );
			$items[] = array(
				'id'       => 'notice-' . $n['id'],
				'kind'     => 'notices',
				'icon'     => $icons[ $n['severity'] ] ?? 'ℹ️',
				'title'    => sprintf( '%s: %s', $n['owner']['name'], $text ),
				'time'     => isset( $seen[ $n['id'] ] ) ? (int) $seen[ $n['id'] ] : (int) self::stored()['captured'],
				'severity' => $n['severity'],
				// {text, url, action} — action links run in the background
				// from the panel; plain links open in a new tab.
				'links'    => $n['links'],
			);
		}
		return $items;
	}
}
