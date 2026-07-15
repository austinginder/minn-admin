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

	/** Core callbacks Minn already covers with its own notifications. */
	const SKIP_CALLBACKS = array( 'update_nag', 'maintenance_nag', 'site_admin_notice' );

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_arm' ), PHP_INT_MAX );
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
		$items = self::capture();
		self::store( $items );
		wp_send_json(
			array(
				'ok'       => true,
				'count'    => count( $items ),
				'captured' => time(),
			)
		);
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
					$text = str_replace( $l['text'], '', $text );
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
		$text = preg_replace( '/\s+/u', ' ', trim( (string) $node->textContent ) );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 400 ) {
			$text = mb_substr( $text, 0, 399 ) . '…';
		} elseif ( strlen( $text ) > 400 ) {
			$text = substr( $text, 0, 399 ) . '…';
		}
		return $text;
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
		foreach ( $node->getElementsByTagName( 'a' ) as $a ) {
			$href  = trim( (string) $a->getAttribute( 'href' ) );
			$class = (string) $a->getAttribute( 'class' );
			$label = preg_replace( '/\s+/u', ' ', trim( (string) $a->textContent ) );
			if ( function_exists( 'mb_substr' ) ) {
				$label = mb_substr( $label, 0, 80 );
			} else {
				$label = substr( $label, 0, 80 );
			}
			$label = $label ?: 'Open';

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
				$ajax = self::ajax_for_button( $class, $label );
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
			$is_action = false !== strpos( $url, 'minn_notices=' )
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
			$links[]      = array(
				'text'   => $label,
				'url'    => $url,
				'action' => $is_action,
			);
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
		$is_admin = ( $host && $site && strcasecmp( $host, $site ) === 0 && false !== strpos( $path, '/wp-admin' ) )
			|| ( $admin && 0 === strpos( $path, $admin ) );
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
	private static function ajax_for_button( $class, $label ) {
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
		// Dev fixture (minn-dev-fixtures) for the suite.
		if ( false !== strpos( $class, 'minn-fixture-hash-no' ) ) {
			return array(
				'action' => 'minn_fixture_hash_dismiss',
				'args'   => array( 'allow' => 'false' ),
			);
		}
		if ( false !== strpos( $class, 'minn-fixture-hash-yes' ) ) {
			return array(
				'action' => 'minn_fixture_hash_dismiss',
				'args'   => array( 'allow' => 'true' ),
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
				// Mirror the plugin handler: no arbitrary POST keys.
				'run'  => array( __CLASS__, 'run_everest_allow_usage' ),
			),
			'minn_fixture_hash_dismiss'         => array(
				'args' => array(
					'allow' => array( 'true', 'false' ),
				),
				'run'  => array( __CLASS__, 'run_fixture_hash_dismiss' ),
			),
		);
		/**
		 * Filter the whitelist of notice admin-ajax actions Minn may run.
		 *
		 * @param array $map action => { args, run }.
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
			return new WP_Error( 'unknown_action', 'That notice action is not supported.', array( 'status' => 400 ) );
		}
		$def  = $map[ $action ];
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
			return new WP_Error( 'forbidden', 'You cannot dismiss this notice.', array( 'status' => 403 ) );
		}
		update_option( 'everest_forms_allow_usage_notice_shown', true );
		if ( isset( $args['allow_usage_tracking'] ) && 'true' === $args['allow_usage_tracking'] ) {
			update_option( 'everest_forms_allow_usage_tracking', 'yes' );
		}
		return true;
	}

	/** Dev-fixture hash-button dismiss. */
	public static function run_fixture_hash_dismiss( $args ) {
		update_option( 'minn_fixture_hash_dismissed', '1' );
		if ( isset( $args['allow'] ) && 'true' === $args['allow'] ) {
			update_option( 'minn_fixture_hash_allowed', '1' );
		}
		return true;
	}

	/** Resolve a callback to the component that owns its file. */
	public static function owner_of( $cb ) {
		$ref  = self::reflect( $cb );
		$file = $ref && $ref->getFileName() ? wp_normalize_path( $ref->getFileName() ) : '';
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
		// v4: ThemeIsle / admin dismiss URLs (nid=, tsdk_dismiss_nonce,
		// "No, thanks." labels) flagged as in-panel actions, not ↗ tabs.
		// v3 was href="#" button CTAs. Versioning invalidates stale captures.
		return 'minn_admin_notices_v4_' . get_current_user_id();
	}

	private static function store( $items ) {
		set_transient(
			self::store_key(),
			array(
				'captured' => time(),
				'items'    => $items,
			),
			DAY_IN_SECONDS
		);

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
			$text = $n['text'];
			if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > 160 ) {
				$text = mb_substr( $text, 0, 159 ) . '…';
			}
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
