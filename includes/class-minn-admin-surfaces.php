<?php
/**
 * Surface registry — the extension point for third-party plugin views.
 *
 * A "surface" is a declarative descriptor (label, capability, REST collection,
 * columns, actions) that the Minn Admin app renders with its generic list /
 * detail / action primitives. Plugins register surfaces via the
 * `minn_admin_surfaces` filter; Minn also bundles adapters for popular plugins
 * under includes/adapters/. See docs/for-plugin-authors.md.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Surfaces {

	/**
	 * All registered surfaces, keyed by id.
	 *
	 * @return array
	 */
	// Per-request memo of the surfaces registry. Registries don't change
	// within a single request, and a hide POST resolves them up to three
	// times (validation, boot slice, restore list); the boot pageload twice.
	private static $all_cache = null;

	public static function all() {
		if ( null === self::$all_cache ) {
			$surfaces        = apply_filters( 'minn_admin_surfaces', array() );
			self::$all_cache = is_array( $surfaces ) ? $surfaces : array();
		}
		return self::$all_cache;
	}

	/**
	 * Surfaces the current user may see, as a list ready for the boot payload.
	 *
	 * @return array
	 */
	public static function for_current_user() {
		$hidden = self::hidden_map();
		// The authoritative surface list comes from all() (apply_filters), the
		// SAME resolution hide validation and the restore list use, so what
		// surfaces exist never diverges per endpoint. contributions() runs a
		// second, best-effort attributing pass ONLY for owner names, which
		// feed the informational nav budget below (a divergence there can at
		// most misword a budget note, never hide or 400 a surface).
		$all    = self::all();
		$reg    = self::contributions( 'minn_admin_surfaces' );
		$owners = array();
		$own    = array();
		foreach ( $reg['owners'] as $raw_id => $name ) {
			$owners[ sanitize_key( $raw_id ) ] = $name;
			$own[ sanitize_key( $raw_id ) ]    = ! empty( $reg['own'][ $raw_id ] );
		}
		$out = array();
		foreach ( $all as $id => $surface ) {
			if ( ! is_array( $surface ) ) {
				continue;
			}
			$cap = isset( $surface['cap'] ) ? $surface['cap'] : 'manage_options';
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			// Per-user hide (goal #7): a hidden surface leaves the boot
			// payload entirely — nav, palette and routes never see it. The
			// registry itself is untouched; Your profile lists it for restore.
			if ( isset( $hidden[ 'surface:' . sanitize_key( $id ) ] ) ) {
				continue;
			}
			// Attention budget (v1.0 gate G3): workspace is for inbox-shaped
			// surfaces. Anything else claiming it degrades to Tools.
			if ( isset( $surface['group'] ) && 'workspace' === $surface['group'] && ! self::inbox_shaped( $surface ) ) {
				$surface['group'] = 'tools';
			}
			unset( $surface['cap'] );
			$surface['id'] = sanitize_key( $id );
			$surface       = self::with_setup_state( $surface );
			$surface       = self::with_settings_state( $surface );
			$surface       = self::with_views_state( $surface );
			$out[]         = $surface;
		}
		return self::collapse_owner_surfaces( $out, $owners, $own );
	}

	/**
	 * The workspace nav group is for inbox-shaped surfaces: a time-ordered
	 * stream of incoming items (form entries, messages). The mechanical test
	 * is a main collection with at least one `ago` column — settings pages,
	 * catalogs and manage lists don't qualify and land under Tools instead.
	 */
	private static function inbox_shaped( $surface ) {
		$cols = isset( $surface['collection']['columns'] ) && is_array( $surface['collection']['columns'] )
			? $surface['collection']['columns']
			: array();
		foreach ( $cols as $col ) {
			if ( is_array( $col ) && isset( $col['format'] ) && 'ago' === $col['format'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Attention budget (v1.0 gate G3): one plugin holds at most this many
	 * nav slots. An owner with more visible surfaces gets its family-less
	 * ones collapsed into a single synthetic family — the existing switcher
	 * mechanics render them as one nav item and one palette row, so nothing
	 * is dropped. Surfaces already in a real family keep it (families are
	 * already collapsed slots). Minn's own bundled adapters are exempt — by
	 * registration-file location, never by owner display name (symlinked
	 * installs break name attribution): each registers only while its
	 * subject plugin is active, so the one-plugin-many-surfaces vector this
	 * guards against does not apply to them. 'Unknown' owners are exempt
	 * too — attribution failures must never collapse strangers together.
	 */
	const OWNER_NAV_BUDGET = 3;

	/**
	 * Nav-slot count per third-party owner. A real `family` renders as ONE
	 * nav slot no matter how many surfaces share it (a family is already a
	 * collapsed slot), and each family-less surface is its own slot. Minn's
	 * own registrations (by file, the `own` flag) and unattributed
	 * ('' / 'Unknown') owners are exempt from the budget. Shared by the
	 * collapse pass and the Integrations-card note so the two never disagree
	 * about which owners exceed the budget.
	 *
	 * @param array[] $items Each { owner, own, family }.
	 * @return array owner => nav-slot count.
	 */
	private static function owner_nav_slots( $items ) {
		$families = array(); // owner => [ family => true ]
		$loose    = array(); // owner => family-less surface count
		foreach ( $items as $it ) {
			$owner = isset( $it['owner'] ) ? $it['owner'] : '';
			if ( '' === $owner || 'Unknown' === $owner || ! empty( $it['own'] ) ) {
				continue;
			}
			if ( ! empty( $it['family'] ) ) {
				$families[ $owner ][ (string) $it['family'] ] = true;
			} else {
				$loose[ $owner ] = isset( $loose[ $owner ] ) ? $loose[ $owner ] + 1 : 1;
			}
		}
		$slots = array();
		foreach ( array_keys( $families + $loose ) as $owner ) {
			$slots[ $owner ] = ( isset( $families[ $owner ] ) ? count( $families[ $owner ] ) : 0 )
				+ ( isset( $loose[ $owner ] ) ? $loose[ $owner ] : 0 );
		}
		return $slots;
	}

	private static function collapse_owner_surfaces( $surfaces, $owners, $own ) {
		$items = array();
		foreach ( $surfaces as $s ) {
			$items[] = array(
				'owner'  => isset( $owners[ $s['id'] ] ) ? $owners[ $s['id'] ] : '',
				'own'    => ! empty( $own[ $s['id'] ] ),
				'family' => isset( $s['family'] ) ? $s['family'] : '',
			);
		}
		$slots = self::owner_nav_slots( $items );
		foreach ( $surfaces as $i => $s ) {
			$owner = isset( $owners[ $s['id'] ] ) ? $owners[ $s['id'] ] : '';
			if ( empty( $slots[ $owner ] ) || $slots[ $owner ] <= self::OWNER_NAV_BUDGET || ! empty( $own[ $s['id'] ] ) ) {
				continue;
			}
			// Merge only the family-less surfaces into one synthetic slot;
			// surfaces already in a real family keep it.
			if ( empty( $s['family'] ) ) {
				$surfaces[ $i ]['family'] = 'plugin-' . sanitize_title( $owner );
			}
		}
		return $surfaces;
	}

	/* ===== Per-user integration hiding =====
	 * One user-meta map ( "kind:id" => hidden-at timestamp ) backs hide for
	 * every integration point. Same interaction contract as notice Hide:
	 * per user, Undo-able, survives re-registration because the key is the
	 * registry id, and capped so the meta can never grow unbounded. */

	const HIDDEN_META = 'minn_admin_hidden_integrations';

	// Per-request memo (uid => map). One hide request rebuilds the map across
	// design_sources, editor_commands, insertable_blocks and the validation
	// path; invalidated on every write below so a same-request read after a
	// hide sees the new value.
	private static $hidden_cache = array();

	public static function hidden_map( $user_id = 0 ) {
		$uid = $user_id ? (int) $user_id : get_current_user_id();
		if ( $uid <= 0 ) {
			return array();
		}
		if ( array_key_exists( $uid, self::$hidden_cache ) ) {
			return self::$hidden_cache[ $uid ];
		}
		$h                          = get_user_meta( $uid, self::HIDDEN_META, true );
		self::$hidden_cache[ $uid ] = is_array( $h ) ? $h : array();
		return self::$hidden_cache[ $uid ];
	}

	/**
	 * True when the id names something actually registered right now (and
	 * visible to this user's caps) — hide never stores junk ids.
	 */
	/**
	 * The raw registry key whose sanitize_key() equals the client-facing id,
	 * or null. Every id the client holds is sanitize_key'd at emit
	 * (for_current_user / design_sources / editor_panels_for_current_user) and
	 * the REST route sanitizes the param again, so a registry key that is not
	 * already sanitize_key-clean (uppercase, dots) would never match a raw
	 * isset — the Hide affordance would render but the POST would 400.
	 */
	private static function raw_key_matching( $registry, $client_id ) {
		if ( ! is_array( $registry ) ) {
			return null;
		}
		if ( isset( $registry[ $client_id ] ) ) {
			return $client_id; // already clean (the common case)
		}
		foreach ( array_keys( $registry ) as $raw ) {
			if ( sanitize_key( (string) $raw ) === $client_id ) {
				return (string) $raw;
			}
		}
		return null;
	}

	public static function is_registered_integration( $id ) {
		if ( ! preg_match( '/^(surface|panel|design|slash):([a-z0-9_-]+)$/', (string) $id, $m ) ) {
			return false;
		}
		if ( 'surface' === $m[1] ) {
			$all = self::all();
			$raw = self::raw_key_matching( $all, $m[2] );
			if ( null === $raw ) {
				return false;
			}
			$cap = isset( $all[ $raw ]['cap'] ) ? $all[ $raw ]['cap'] : 'manage_options';
			return current_user_can( $cap );
		}
		if ( 'design' === $m[1] ) {
			// Validate against the RAW registry (the resolved list already
			// filters this user's hides, which would wrongly refuse re-hides).
			$sources = apply_filters( 'minn_admin_design_sources', array() );
			return null !== self::raw_key_matching( $sources, $m[2] ) && current_user_can( 'edit_posts' );
		}
		if ( 'slash' === $m[1] ) {
			foreach ( Minn_Admin::slash_namespaces() as $ns ) {
				if ( sanitize_key( (string) $ns ) === $m[2] ) {
					return current_user_can( 'edit_posts' );
				}
			}
			return false;
		}
		$panels = apply_filters( 'minn_admin_editor_panels', array() );
		$raw    = self::raw_key_matching( $panels, $m[2] );
		if ( null === $raw ) {
			return false;
		}
		$cap = isset( $panels[ $raw ]['cap'] ) ? $panels[ $raw ]['cap'] : 'edit_posts';
		return current_user_can( $cap );
	}

	/**
	 * Slash namespaces the current user hid ( ns => true ) — consumed by the
	 * editor payload builders (insertable blocks, patterns, commands, block
	 * form insert templates) so hidden namespaces leave every menu source.
	 */
	public static function hidden_slash_map() {
		$out = array();
		foreach ( self::hidden_map() as $key => $ts ) {
			if ( 0 === strpos( (string) $key, 'slash:' ) ) {
				$out[ substr( $key, 6 ) ] = true;
			}
		}
		return $out;
	}

	public static function hide_integration( $id ) {
		if ( ! self::is_registered_integration( $id ) ) {
			return false;
		}
		$h        = self::hidden_map();
		$h[ $id ] = time();
		// Cap: keep the newest 100 (the notice-hide precedent).
		if ( count( $h ) > 100 ) {
			arsort( $h );
			$h = array_slice( $h, 0, 100, true );
		}
		$uid = get_current_user_id();
		update_user_meta( $uid, self::HIDDEN_META, $h );
		self::$hidden_cache[ $uid ] = $h; // keep the memo in step with the write
		return true;
	}

	public static function unhide_integration( $id ) {
		$h = self::hidden_map();
		unset( $h[ (string) $id ] );
		$uid = get_current_user_id();
		if ( $h ) {
			update_user_meta( $uid, self::HIDDEN_META, $h );
		} else {
			delete_user_meta( $uid, self::HIDDEN_META );
		}
		self::$hidden_cache[ $uid ] = $h;
		return true;
	}

	/**
	 * The restore list for Your profile: label + kind for every hidden id
	 * that still exists in the registry (a hidden id whose plugin was
	 * deactivated simply doesn't render; the meta entry stays until the
	 * cap prunes it, so reactivation keeps the user's choice).
	 */
	public static function hidden_for_current_user() {
		$hidden = self::hidden_map();
		if ( ! $hidden ) {
			return array();
		}
		// Which kinds the user actually hid, so we resolve only the registries
		// we need: a surface-only hider must not pay a panels + designs filter
		// run plus a block/pattern-registry walk to render a one-row list.
		$has = array( 'surface' => false, 'panel' => false, 'design' => false, 'slash' => false );
		foreach ( array_keys( $hidden ) as $k ) {
			$p = strtok( (string) $k, ':' );
			if ( isset( $has[ $p ] ) ) {
				$has[ $p ] = true;
			}
		}
		$out = array();
		foreach ( ( $has['surface'] ? self::all() : array() ) as $id => $surface ) {
			$key = 'surface:' . sanitize_key( $id );
			if ( ! isset( $hidden[ $key ] ) ) {
				continue;
			}
			$cap = isset( $surface['cap'] ) ? $surface['cap'] : 'manage_options';
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $key,
				'kind'  => 'surface',
				'label' => isset( $surface['label'] ) ? (string) $surface['label'] : $id,
				'sub'   => isset( $surface['sub'] ) ? (string) $surface['sub'] : '',
			);
		}
		$panels = $has['panel'] ? apply_filters( 'minn_admin_editor_panels', array() ) : array();
		foreach ( ( is_array( $panels ) ? $panels : array() ) as $id => $panel ) {
			$key = 'panel:' . sanitize_key( $id );
			if ( ! isset( $hidden[ $key ] ) ) {
				continue;
			}
			$cap = isset( $panel['cap'] ) ? $panel['cap'] : 'edit_posts';
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $key,
				'kind'  => 'panel',
				'label' => isset( $panel['label'] ) ? (string) $panel['label'] : $id,
				'sub'   => isset( $panel['sub'] ) ? (string) $panel['sub'] : '',
			);
		}
		if ( ( $has['design'] || $has['slash'] ) && current_user_can( 'edit_posts' ) ) {
			// Design libraries and slash namespaces: same still-registered
			// rule as above (raw registry; the resolved lists already omit
			// this user's hides). Each registry resolves only when hidden.
			$sources = $has['design'] ? apply_filters( 'minn_admin_design_sources', array() ) : array();
			foreach ( ( is_array( $sources ) ? $sources : array() ) as $id => $src ) {
				$key = 'design:' . sanitize_key( $id );
				if ( ! isset( $hidden[ $key ] ) ) {
					continue;
				}
				$label = is_array( $src ) && ! empty( $src['label'] ) && is_string( $src['label'] )
					? $src['label']
					: ucfirst( sanitize_key( $id ) );
				$out[] = array(
					'id'    => $key,
					'kind'  => 'design',
					'label' => $label,
					'sub'   => '',
				);
			}
			$live_ns = $has['slash'] ? Minn_Admin::slash_namespaces() : array();
			foreach ( $hidden as $key => $ts ) {
				if ( 0 !== strpos( (string) $key, 'slash:' ) ) {
					continue;
				}
				$ns = substr( $key, 6 );
				if ( ! in_array( $ns, $live_ns, true ) ) {
					continue;
				}
				$out[] = array(
					'id'    => (string) $key,
					'kind'  => 'slash',
					'label' => $ns,
					'sub'   => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Resolve a surface's setup gate for the client. The descriptor's
	 * `setup` key carries callables (`needed`, `run`) that must never reach
	 * JSON: `needed()` is evaluated here and the client gets only the card
	 * copy plus a `setupNeeded` flag. A throwing check reads as not-needed
	 * so a broken gate can never brick a working surface.
	 *
	 * @param array $surface Client-bound surface row (id already set).
	 * @return array The row with `setup` resolved or removed.
	 */
	private static function with_setup_state( $surface ) {
		if ( empty( $surface['setup'] ) || ! is_array( $surface['setup'] ) ) {
			unset( $surface['setup'] );
			return $surface;
		}
		$setup  = $surface['setup'];
		$needed = false;
		if ( isset( $setup['needed'] ) && is_callable( $setup['needed'] ) ) {
			try {
				$needed = (bool) call_user_func( $setup['needed'] );
			} catch ( \Throwable $e ) {
				$needed = false;
			}
		}
		if ( ! $needed ) {
			unset( $surface['setup'] );
			return $surface;
		}
		$options = array();
		foreach ( (array) ( $setup['options'] ?? array() ) as $opt ) {
			if ( ! is_array( $opt ) || empty( $opt['id'] ) || empty( $opt['label'] ) ) {
				continue;
			}
			$options[] = array(
				'id'      => sanitize_key( $opt['id'] ),
				'label'   => (string) $opt['label'],
				'default' => ! empty( $opt['default'] ),
			);
		}
		$client = array(
			'title' => (string) ( $setup['title'] ?? 'This plugin needs a one-time setup' ),
			'note'  => (string) ( $setup['note'] ?? '' ),
		);
		if ( $options ) {
			$client['options'] = $options;
		}
		if ( ! empty( $setup['href'] ) && empty( $setup['run'] ) ) {
			$client['href'] = (string) $setup['href'];
		}
		$surface['setup']       = $client;
		$surface['setupNeeded'] = true;
		return $surface;
	}

	/**
	 * Resolve a surface's `settings` view for the client. The view can be
	 * gated tighter than the surface itself (an email log any admin reads
	 * vs. transport settings only settings-capable users touch): a `cap`
	 * the user lacks removes the view from the boot payload. The real
	 * write gate stays the adapter route's own permission_callback.
	 *
	 * @param array $surface Client-bound surface row (id already set).
	 * @return array The row with `settings` normalized or removed.
	 */
	private static function with_settings_state( $surface ) {
		if ( empty( $surface['settings'] ) || ! is_array( $surface['settings'] ) ) {
			unset( $surface['settings'] );
			return $surface;
		}
		$cfg = $surface['settings'];
		if ( ! empty( $cfg['cap'] ) && ! current_user_can( $cfg['cap'] ) ) {
			unset( $surface['settings'] );
			return $surface;
		}
		$tabs = array();
		foreach ( (array) ( $cfg['tabs'] ?? array() ) as $tab ) {
			if ( ! is_array( $tab ) || empty( $tab['id'] ) || empty( $tab['label'] ) ) {
				continue;
			}
			$tabs[] = array(
				'id'    => sanitize_key( $tab['id'] ),
				'label' => (string) $tab['label'],
			);
		}
		if ( empty( $cfg['route'] ) || ! is_string( $cfg['route'] ) || ! $tabs ) {
			unset( $surface['settings'] );
			return $surface;
		}
		$surface['settings'] = array(
			'label' => (string) ( $cfg['label'] ?? 'Settings' ),
			'route' => $cfg['route'],
			'tabs'  => $tabs,
		);
		return $surface;
	}

	/**
	 * Resolve a surface's extra list views (`views`) for the client. Each
	 * entry is a collection like `manage`, and like `settings` it may carry
	 * its own `cap` gating the VIEW tighter than the surface (an email log
	 * any admin reads vs. a debug log only settings-capable users see); the
	 * real gate stays the adapter route's own permission_callback. Entries
	 * without a route or a viewLabel are dropped — the switcher can't name
	 * a nameless tab, and index-based view ids mean a malformed entry must
	 * vanish here, consistently, not sometimes-render.
	 *
	 * @param array $surface Client-bound surface row (id already set).
	 * @return array The row with `views` normalized or removed.
	 */
	private static function with_views_state( $surface ) {
		if ( empty( $surface['views'] ) || ! is_array( $surface['views'] ) ) {
			unset( $surface['views'] );
			return $surface;
		}
		$views = array();
		foreach ( $surface['views'] as $v ) {
			if ( ! is_array( $v ) || empty( $v['route'] ) || ! is_string( $v['route'] ) || empty( $v['viewLabel'] ) ) {
				continue;
			}
			if ( ! empty( $v['cap'] ) && ! current_user_can( $v['cap'] ) ) {
				continue;
			}
			unset( $v['cap'] );
			$views[] = $v;
		}
		if ( $views ) {
			$surface['views'] = array_values( $views );
		} else {
			unset( $surface['views'] );
		}
		return $surface;
	}

	/* ===== Integration diagnostics (System page) ==========================
	 *
	 * A live registry view of everything hooked into Minn, with each entry
	 * attributed to the plugin that registered it and validated against the
	 * documented descriptor contract (docs/for-plugin-authors.md). This is
	 * the feedback loop for integration authors: a malformed descriptor
	 * fails silently in the client, but shows its problems here.
	 */

	// The documented descriptor vocabulary. Undocumented keys are internal
	// (see the Compatibility section of for-plugin-authors.md), so anything
	// outside these lists is flagged as unknown rather than silently ignored.
	const SURFACE_KEYS    = array( 'label', 'sub', 'icon', 'cap', 'family', 'group', 'collection', 'manage', 'views', 'status', 'setup', 'settings' );
	const SETUP_KEYS      = array( 'needed', 'title', 'note', 'options', 'run', 'href' );
	const SETTINGS_KEYS   = array( 'label', 'cap', 'tabs', 'route' );
	const COLLECTION_KEYS = array( 'route', 'allRoute', 'query', 'pageQuery', 'itemsKey', 'totalKey', 'tabs', 'columns', 'detail', 'actions', 'search', 'create', 'viewLabel', 'bulk', 'filter', 'sortQuery' );
	const FILTER_KEYS     = array( 'label', 'options', 'query', 'param', 'json' );
	const DETAIL_KEYS     = array( 'detailRoute', 'sectionsRoute', 'labels', 'messageKey', 'skip', 'edit' );
	const COLUMN_KEYS     = array( 'key', 'label', 'format', 'altKey', 'width', 'utc', 'sort' );
	const COLUMN_FORMATS  = array( 'title', 'text', 'pill', 'ago', 'mono', 'num', 'entry-summary' );
	const ACTION_KEYS     = array( 'label', 'method', 'route', 'body', 'confirm', 'danger', 'when', 'href', 'fields', 'settingsItem', 'list' );
	const CREATE_KEYS     = array( 'label', 'route', 'method', 'fields', 'defaults' );
	const EDIT_KEYS       = array( 'route', 'method', 'preserve', 'fields' );
	const FIELD_KEYS      = array( 'key', 'label', 'type', 'options', 'value', 'placeholder', 'rows', 'mono', 'required' );
	const FIELD_TYPES     = array( 'text', 'number', 'textarea', 'select', 'tags', 'email', 'url' );
	const PANEL_KEYS      = array( 'label', 'sub', 'cap', 'fieldsRoute', 'valuesKey', 'writeKey' );

	/**
	 * Replay a registry filter callback-by-callback, attributing each added
	 * entry to the plugin that owns the callback's file (the notices class's
	 * Reflection technique). Assoc registries diff by key; list registries
	 * (cache purgers) diff by each entry's `id`.
	 *
	 * @param string $hook    Filter name.
	 * @param array  $initial Seed value (what the caller passes to apply_filters).
	 * @return array { value: final array, owners: entry-key => owner name }
	 */
	/**
	 * The file a hook callback is defined in ('' when unresolvable). Used to
	 * recognize Minn's OWN registrations by location rather than by owner
	 * display name — symlinked installs resolve bundled adapter files outside
	 * WP_PLUGIN_DIR, which breaks name attribution but never this prefix.
	 */
	private static function callback_file( $fn ) {
		try {
			if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) ) {
				$ref = new ReflectionMethod( $fn[0], $fn[1] );
			} elseif ( is_object( $fn ) && ! ( $fn instanceof Closure ) && method_exists( $fn, '__invoke' ) ) {
				$ref = new ReflectionMethod( $fn, '__invoke' );
			} else {
				$ref = new ReflectionFunction( $fn );
			}
			return $ref->getFileName() ? wp_normalize_path( $ref->getFileName() ) : '';
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	private static function contributions( $hook, $initial = array() ) {
		global $wp_filter;
		$value    = $initial;
		$owners   = array();
		$own      = array();
		$minn_dir = wp_normalize_path( dirname( __DIR__ ) ) . '/';
		if ( empty( $wp_filter[ $hook ] ) ) {
			return array( 'value' => $value, 'owners' => $owners, 'own' => $own );
		}
		$entry_keys = function ( $arr ) {
			if ( ! is_array( $arr ) ) {
				return array();
			}
			if ( wp_is_numeric_array( $arr ) ) {
				return array_values( array_filter( array_map( function ( $e ) {
					return is_array( $e ) && isset( $e['id'] ) ? (string) $e['id'] : null;
				}, $arr ) ) );
			}
			return array_map( 'strval', array_keys( $arr ) );
		};
		foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$before = $entry_keys( $value );
				try {
					$next = call_user_func( $cb['function'], $value );
				} catch ( \Throwable $e ) {
					continue; // a broken callback keeps the running value
				}
				if ( ! is_array( $next ) ) {
					continue;
				}
				$owner   = class_exists( 'Minn_Admin_Notices' ) ? Minn_Admin_Notices::owner_of( $cb['function'] ) : null;
				$name    = $owner ? $owner['name'] : 'Unknown';
				$file    = self::callback_file( $cb['function'] );
				$is_minn = '' !== $file && 0 === strpos( $file, $minn_dir );
				foreach ( array_diff( $entry_keys( $next ), $before ) as $added ) {
					$owners[ $added ] = $name;
					$own[ $added ]    = $is_minn;
				}
				$value = $next;
			}
		}
		return array( 'value' => $value, 'owners' => $owners, 'own' => $own );
	}

	private static function unknown_keys( $arr, $known ) {
		return array_values( array_diff( array_map( 'strval', array_keys( (array) $arr ) ), $known ) );
	}

	/**
	 * Contract problems for a create/detail.edit `fields` array (the form
	 * engine's field vocabulary). $where labels the problem source
	 * ("collection create" / "manage detail.edit").
	 */
	private static function field_problems( $fields, $where ) {
		$problems = array();
		if ( ! is_array( $fields ) || ! wp_is_numeric_array( $fields ) ) {
			return array( "$where: fields is not a list" );
		}
		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) || empty( $f['key'] ) ) {
				$problems[] = "$where: field without a key";
				continue;
			}
			if ( isset( $f['type'] ) && ! in_array( $f['type'], self::FIELD_TYPES, true ) ) {
				$problems[] = "$where: field \"{$f['key']}\" has unknown type \"{$f['type']}\"";
			}
			if ( isset( $f['type'] ) && 'select' === $f['type'] && empty( $f['options'] ) ) {
				$problems[] = "$where: select field \"{$f['key']}\" has no options";
			}
			if ( isset( $f['options'] ) && ! wp_is_numeric_array( $f['options'] ) ) {
				$problems[] = "$where: field \"{$f['key']}\" options must be a list of [value, label] pairs";
			}
			foreach ( self::unknown_keys( $f, self::FIELD_KEYS ) as $k ) {
				$problems[] = "$where: unknown field key \"$k\" on \"{$f['key']}\"";
			}
		}
		return $problems;
	}

	/**
	 * External-link honesty: descriptor hrefs that leave this site. These
	 * are legal (the export / vendor-screen pattern), not contract problems,
	 * but they are surfaced on the Integrations card so an off-site link can
	 * never hide inside a surface unnoticed. The client independently
	 * guarantees the ↗ affordance on every rendered href; this is the
	 * per-plugin audit trail. Status-card links arrive in route responses at
	 * runtime, so only descriptor-carried hrefs can be flagged here.
	 */
	private static function offsite_href( $href ) {
		if ( ! is_string( $href ) || '' === $href ) {
			return false;
		}
		if ( 0 === strpos( $href, '//' ) ) {
			// Protocol-relative: the browser resolves it against the page
			// scheme and it navigates off-site just like an absolute URL, so
			// the JS offsiteHref (new URL(href, origin)) flags it. Match that.
			$href = ( is_ssl() ? 'https:' : 'http:' ) . $href;
		} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
			return false; // A relative URL resolves to this site.
		}
		// Compare host AND port (the client compares u.host, port included), so
		// a same-host-different-port origin reads off-site on both sides.
		$hostport = static function ( $url ) {
			$p = wp_parse_url( $url );
			if ( empty( $p['host'] ) ) {
				return '';
			}
			return strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		};
		$here = $hostport( home_url() );
		$there = $hostport( $href );
		return '' !== $there && '' !== $here && $there !== $here;
	}

	private static function surface_offsite_links( $surface ) {
		if ( ! is_array( $surface ) ) {
			return array();
		}
		$out = array();
		if ( isset( $surface['setup']['href'] ) && self::offsite_href( $surface['setup']['href'] ) ) {
			$out[] = 'setup links off-site (' . wp_parse_url( $surface['setup']['href'], PHP_URL_HOST ) . ')';
		}
		$colls = array();
		foreach ( array( 'collection', 'manage' ) as $ck ) {
			if ( ! empty( $surface[ $ck ] ) && is_array( $surface[ $ck ] ) ) {
				$colls[ $ck ] = $surface[ $ck ];
			}
		}
		foreach ( (array) ( $surface['views'] ?? array() ) as $i => $v ) {
			if ( is_array( $v ) ) {
				$colls[ "views[$i]" ] = $v;
			}
		}
		foreach ( $colls as $ck => $coll ) {
			foreach ( (array) ( $coll['actions'] ?? array() ) as $a ) {
				if ( is_array( $a ) && ! empty( $a['href'] ) && self::offsite_href( $a['href'] ) ) {
					$label = isset( $a['label'] ) ? (string) $a['label'] : '(unlabeled)';
					$out[] = "$ck: action \"$label\" links off-site (" . wp_parse_url( $a['href'], PHP_URL_HOST ) . ')';
				}
			}
		}
		return $out;
	}

	/** Contract problems for one surface descriptor (documented keys only). */
	private static function surface_problems( $surface ) {
		$problems = array();
		if ( ! is_array( $surface ) ) {
			return array( 'descriptor is not an array' );
		}
		if ( empty( $surface['label'] ) ) {
			$problems[] = 'missing label';
		}
		foreach ( self::unknown_keys( $surface, self::SURFACE_KEYS ) as $k ) {
			$problems[] = "unknown key \"$k\" (ignored)";
		}
		if ( isset( $surface['group'] ) && 'workspace' === $surface['group'] && ! self::inbox_shaped( $surface ) ) {
			$problems[] = 'group: workspace is for inbox-shaped surfaces (a collection with an "ago" column); shown under Tools instead';
		}
		if ( empty( $surface['collection'] ) || ! is_array( $surface['collection'] ) ) {
			// Settings-only surfaces are legal: a settings-shaped plugin
			// (Perfmatters is the bundled example) has no list to show.
			if ( empty( $surface['settings'] ) || ! is_array( $surface['settings'] ) ) {
				$problems[] = 'missing collection';
			}
		}
		if ( isset( $surface['setup'] ) ) {
			if ( ! is_array( $surface['setup'] ) ) {
				$problems[] = 'setup is not an array';
			} else {
				$setup = $surface['setup'];
				if ( empty( $setup['needed'] ) || ! is_callable( $setup['needed'] ) ) {
					$problems[] = 'setup: missing needed callable';
				}
				if ( ( empty( $setup['run'] ) || ! is_callable( $setup['run'] ) ) && empty( $setup['href'] ) ) {
					$problems[] = 'setup: needs a run callable or an href';
				}
				foreach ( (array) ( $setup['options'] ?? array() ) as $opt ) {
					if ( ! is_array( $opt ) || empty( $opt['id'] ) || empty( $opt['label'] ) ) {
						$problems[] = 'setup: option without id and label';
					}
				}
				foreach ( self::unknown_keys( $setup, self::SETUP_KEYS ) as $k ) {
					$problems[] = "setup: unknown key \"$k\" (ignored)";
				}
			}
		}
		if ( isset( $surface['settings'] ) ) {
			$cfg = $surface['settings'];
			if ( ! is_array( $cfg ) || empty( $cfg['route'] ) || ! is_string( $cfg['route'] ) ) {
				$problems[] = 'settings: missing route';
			} else {
				$tabs = isset( $cfg['tabs'] ) && is_array( $cfg['tabs'] ) ? $cfg['tabs'] : array();
				if ( ! $tabs ) {
					$problems[] = 'settings: needs at least one tab';
				}
				foreach ( $tabs as $tab ) {
					if ( ! is_array( $tab ) || empty( $tab['id'] ) || empty( $tab['label'] ) ) {
						$problems[] = 'settings: tab without id and label';
					}
				}
				foreach ( self::unknown_keys( $cfg, self::SETTINGS_KEYS ) as $k ) {
					$problems[] = "settings: unknown key \"$k\" (ignored)";
				}
			}
		}
		// Every list view validates with the same collection vocabulary:
		// `collection`, `manage`, and each `views` entry (which additionally
		// needs a viewLabel to name its switcher tab and may carry `cap`).
		$colls = array();
		foreach ( array( 'collection', 'manage' ) as $ck ) {
			if ( ! empty( $surface[ $ck ] ) && is_array( $surface[ $ck ] ) ) {
				$colls[ $ck ] = $surface[ $ck ];
			}
		}
		if ( isset( $surface['views'] ) ) {
			if ( ! is_array( $surface['views'] ) || ! wp_is_numeric_array( $surface['views'] ) ) {
				$problems[] = 'views: must be a list of collections';
			} else {
				foreach ( $surface['views'] as $i => $v ) {
					$vk = "views[$i]";
					if ( ! is_array( $v ) ) {
						$problems[] = "$vk: entry is not an array";
						continue;
					}
					if ( empty( $v['viewLabel'] ) ) {
						$problems[] = "$vk: missing viewLabel (entry dropped)";
					}
					unset( $v['cap'] ); // view-level gate, legal here only
					$colls[ $vk ] = $v;
				}
			}
		}
		foreach ( $colls as $ck => $coll ) {
			if ( empty( $coll['route'] ) || ! is_string( $coll['route'] ) ) {
				$problems[] = "$ck: missing route";
			}
			foreach ( self::unknown_keys( $coll, self::COLLECTION_KEYS ) as $k ) {
				$problems[] = "$ck: unknown key \"$k\" (ignored)";
			}
			foreach ( (array) ( $coll['columns'] ?? array() ) as $col ) {
				if ( ! is_array( $col ) || empty( $col['key'] ) ) {
					$problems[] = "$ck: column without a key";
					continue;
				}
				if ( isset( $col['format'] ) && ! in_array( $col['format'], self::COLUMN_FORMATS, true ) ) {
					$problems[] = "$ck: unknown column format \"{$col['format']}\"";
				}
				foreach ( self::unknown_keys( $col, self::COLUMN_KEYS ) as $k ) {
					$problems[] = "$ck: unknown column key \"$k\"";
				}
			}
			// An EMPTY detail array is legitimate (modal shows the raw list
			// item) — only the key vocabulary is checked.
			if ( isset( $coll['detail'] ) && is_array( $coll['detail'] ) ) {
				foreach ( self::unknown_keys( $coll['detail'], self::DETAIL_KEYS ) as $k ) {
					$problems[] = "$ck: unknown detail key \"$k\" (ignored)";
				}
				if ( isset( $coll['detail']['edit'] ) ) {
					$edit = $coll['detail']['edit'];
					if ( ! is_array( $edit ) || empty( $edit['route'] ) ) {
						$problems[] = "$ck: detail.edit without a route";
					} else {
						foreach ( self::unknown_keys( $edit, self::EDIT_KEYS ) as $k ) {
							$problems[] = "$ck: unknown detail.edit key \"$k\" (ignored)";
						}
						$problems = array_merge( $problems, self::field_problems( $edit['fields'] ?? array(), "$ck detail.edit" ) );
					}
				}
			}
			if ( isset( $coll['create'] ) ) {
				$create = $coll['create'];
				if ( ! is_array( $create ) || empty( $create['route'] ) ) {
					$problems[] = "$ck: create without a route";
				} else {
					foreach ( self::unknown_keys( $create, self::CREATE_KEYS ) as $k ) {
						$problems[] = "$ck: unknown create key \"$k\" (ignored)";
					}
					$problems = array_merge( $problems, self::field_problems( $create['fields'] ?? array(), "$ck create" ) );
				}
			}
			foreach ( (array) ( $coll['actions'] ?? array() ) as $a ) {
				if ( ! is_array( $a ) || empty( $a['label'] ) ) {
					$problems[] = "$ck: action without a label";
					continue;
				}
				if ( empty( $a['route'] ) && empty( $a['href'] ) && empty( $a['settingsItem'] ) ) {
					$problems[] = "$ck: action \"{$a['label']}\" has neither route nor href";
				}
				if ( ! empty( $a['settingsItem'] ) && ( empty( $surface['settings'] ) || false === strpos( (string) ( $surface['settings']['route'] ?? '' ), '{id}' ) ) ) {
					$problems[] = "$ck: action \"{$a['label']}\" declares settingsItem but the surface has no item-scoped settings route";
				}
				foreach ( self::unknown_keys( $a, self::ACTION_KEYS ) as $k ) {
					$problems[] = "$ck: unknown action key \"$k\" (ignored)";
				}
				if ( isset( $a['fields'] ) ) {
					$problems = array_merge( $problems, self::field_problems( $a['fields'], "$ck action \"{$a['label']}\"" ) );
				}
			}
			if ( isset( $coll['filter'] ) ) {
				$f = $coll['filter'];
				if ( ! is_array( $f ) || empty( $f['options'] ) || ! is_array( $f['options'] ) ) {
					$problems[] = "$ck: filter needs an options list";
				} else {
					foreach ( $f['options'] as $opt ) {
						if ( ! is_array( $opt ) || 2 > count( $opt ) ) {
							$problems[] = "$ck: filter option must be a [value, label] pair";
							break;
						}
					}
					if ( empty( $f['query'] ) && ( empty( $f['param'] ) || empty( $f['json'] ) ) ) {
						$problems[] = "$ck: filter needs a query template or param + json";
					}
					foreach ( self::unknown_keys( $f, self::FILTER_KEYS ) as $k ) {
						$problems[] = "$ck: unknown filter key \"$k\" (ignored)";
					}
				}
			}
			// Bulk actions share the action vocabulary but always need a route
			// (there is no href form of a batch) and cannot carry fields — a
			// batch has no place to ask per-item questions.
			foreach ( (array) ( $coll['bulk'] ?? array() ) as $b ) {
				if ( ! is_array( $b ) || empty( $b['label'] ) ) {
					$problems[] = "$ck: bulk action without a label";
					continue;
				}
				if ( empty( $b['route'] ) ) {
					$problems[] = "$ck: bulk action \"{$b['label']}\" has no route";
				}
				if ( isset( $b['fields'] ) ) {
					$problems[] = "$ck: bulk action \"{$b['label']}\" declares fields (not supported on bulk)";
				}
				foreach ( self::unknown_keys( $b, self::ACTION_KEYS ) as $k ) {
					$problems[] = "$ck: unknown bulk action key \"$k\" (ignored)";
				}
			}
		}
		return $problems;
	}

	/** Contract problems for one editor-panel descriptor. */
	private static function panel_problems( $panel ) {
		$problems = array();
		if ( ! is_array( $panel ) ) {
			return array( 'descriptor is not an array' );
		}
		foreach ( array( 'label', 'fieldsRoute', 'valuesKey', 'writeKey' ) as $req ) {
			if ( empty( $panel[ $req ] ) ) {
				$problems[] = "missing $req";
			}
		}
		foreach ( self::unknown_keys( $panel, self::PANEL_KEYS ) as $k ) {
			$problems[] = "unknown key \"$k\" (ignored)";
		}
		return $problems;
	}

	/**
	 * The full integrations model for the System page: every registry hook's
	 * live entries with owner + contract problems, plus listener owners for
	 * the data hooks that can't be enumerated as entries.
	 *
	 * @return array
	 */
	public static function integrations() {
		global $wp_filter;

		$surfaces = self::contributions( 'minn_admin_surfaces' );
		$s_rows   = array();
		foreach ( (array) $surfaces['value'] as $id => $s ) {
			$s_rows[] = array(
				'id'       => sanitize_key( (string) $id ),
				'label'    => is_array( $s ) && isset( $s['label'] ) ? (string) $s['label'] : '',
				'family'   => is_array( $s ) && isset( $s['family'] ) ? (string) $s['family'] : '',
				'cap'      => is_array( $s ) && isset( $s['cap'] ) ? (string) $s['cap'] : 'manage_options',
				'owner'    => isset( $surfaces['owners'][ (string) $id ] ) ? $surfaces['owners'][ (string) $id ] : 'Unknown',
				'own'      => ! empty( $surfaces['own'][ (string) $id ] ),
				'problems' => self::surface_problems( $s ),
				'offsite'  => self::surface_offsite_links( $s ),
			);
		}
		// Attention-budget note (informational, like offsite): an owner past
		// the nav budget has its family-less surfaces sharing one nav slot.
		// Uses the SAME nav-slot counting the collapse pass uses (a real
		// family counts once), so the card never claims a collapse the nav
		// does not perform. Minn's own registrations are exempt by FILE
		// location (the `own` flag), matching for_current_user(), never by
		// owner display name, which symlinked installs mangle.
		$slots = self::owner_nav_slots( $s_rows );
		foreach ( $s_rows as $i => $r ) {
			$n = isset( $slots[ $r['owner'] ] ) ? $slots[ $r['owner'] ] : 0;
			unset( $s_rows[ $i ]['own'] );
			if ( $n > self::OWNER_NAV_BUDGET ) {
				$s_rows[ $i ]['notes'] = array(
					sprintf( '%s uses %d nav slots (budget %d); its family-less surfaces share one.', $r['owner'], $n, self::OWNER_NAV_BUDGET ),
				);
			}
		}

		$panels = self::contributions( 'minn_admin_editor_panels' );
		$p_rows = array();
		foreach ( (array) $panels['value'] as $id => $p ) {
			$p_rows[] = array(
				'id'       => sanitize_key( (string) $id ),
				'label'    => is_array( $p ) && isset( $p['label'] ) ? (string) $p['label'] : '',
				'cap'      => is_array( $p ) && isset( $p['cap'] ) ? (string) $p['cap'] : 'edit_posts',
				'owner'    => isset( $panels['owners'][ (string) $id ] ) ? $panels['owners'][ (string) $id ] : 'Unknown',
				'problems' => self::panel_problems( $p ),
			);
		}

		$designs = self::contributions( 'minn_admin_design_sources' );
		$d_rows  = array();
		foreach ( (array) $designs['value'] as $id => $d ) {
			$problems = array();
			if ( ! is_array( $d ) || empty( $d['route'] ) || ! is_string( $d['route'] ) ) {
				$problems[] = 'missing route (source dropped)';
			}
			$d_rows[] = array(
				'id'       => sanitize_key( (string) $id ),
				'label'    => is_array( $d ) && ! empty( $d['label'] ) ? (string) $d['label'] : ucfirst( sanitize_key( (string) $id ) ),
				'owner'    => isset( $designs['owners'][ (string) $id ] ) ? $designs['owners'][ (string) $id ] : 'Unknown',
				'problems' => $problems,
			);
		}

		// Cache purgers: the bundled detections seed the final list inside
		// minn_admin_cache_purgers(); the filter replay (empty seed) only
		// attributes third-party additions — bundled ones default to Minn.
		$c_rows = array();
		if ( function_exists( 'minn_admin_cache_purgers' ) ) {
			$purgers = self::contributions( 'minn_admin_cache_purgers', array() );
			foreach ( minn_admin_cache_purgers() as $p ) {
				if ( ! is_array( $p ) || empty( $p['id'] ) ) {
					continue;
				}
				$c_rows[] = array(
					'id'    => (string) $p['id'],
					'label' => isset( $p['name'] ) ? (string) $p['name'] : (string) $p['id'],
					'owner' => isset( $purgers['owners'][ $p['id'] ] ) ? $purgers['owners'][ $p['id'] ] : 'Minn Admin',
				);
			}
		}

		// Spam providers: same seed-plus-filter shape as cache purgers.
		$sp_rows = array();
		if ( function_exists( 'minn_admin_spam_providers' ) ) {
			$spam = self::contributions( 'minn_admin_spam_providers', array() );
			foreach ( minn_admin_spam_providers() as $p ) {
				if ( ! is_array( $p ) || empty( $p['id'] ) ) {
					continue;
				}
				$sp_rows[] = array(
					'id'    => (string) $p['id'],
					'label' => isset( $p['name'] ) ? (string) $p['name'] : (string) $p['id'],
					'owner' => isset( $spam['owners'][ $p['id'] ] ) ? $spam['owners'][ $p['id'] ] : 'Minn Admin',
				);
			}
		}

		// License readers: assoc registry keyed by provider id, detect-gated
		// (only providers whose component is installed appear). Bundled
		// entries seed before the filter; the replay attributes third parties.
		$l_rows = array();
		if ( function_exists( 'minn_admin_license_default_providers' ) ) {
			$lic = self::contributions( 'minn_admin_license_providers', array() );
			$all = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
			foreach ( (array) $all as $id => $p ) {
				if ( ! is_array( $p ) || empty( $p['detect'] ) || ! is_callable( $p['detect'] ) ) {
					continue;
				}
				try {
					if ( ! call_user_func( $p['detect'] ) ) {
						continue;
					}
				} catch ( \Throwable $e ) {
					continue;
				}
				$l_rows[] = array(
					'id'    => sanitize_key( (string) $id ),
					'label' => isset( $p['name'] ) ? (string) $p['name'] : (string) $id,
					'owner' => isset( $lic['owners'][ (string) $id ] ) ? $lic['owners'][ (string) $id ] : 'Minn Admin',
				);
			}
		}

		// Page builders: the registry is already active-only (each bundled
		// entry gates on its plugin's constant; `detect` is per-post, not
		// plugin-active). Bundled entries seed before the filter, so the
		// replay only attributes third-party additions.
		$b_rows = array();
		if ( function_exists( 'minn_admin_page_builders' ) ) {
			$builders = self::contributions( 'minn_admin_page_builders' );
			foreach ( minn_admin_page_builders() as $id => $b ) {
				$b_rows[] = array(
					'id'    => sanitize_key( (string) $id ),
					'label' => is_array( $b ) && isset( $b['name'] ) ? (string) $b['name'] : (string) $id,
					'owner' => isset( $builders['owners'][ (string) $id ] ) ? $builders['owners'][ (string) $id ] : 'Minn Admin',
				);
			}
		}

		// Block-form descriptors: aggregate per owner (per-block rows would
		// drown the card — Anchor Blocks alone registers a dozen).
		$forms = self::contributions( 'minn_admin_block_forms' );
		$f_by  = array();
		foreach ( array_keys( (array) $forms['value'] ) as $block ) {
			$owner          = isset( $forms['owners'][ (string) $block ] ) ? $forms['owners'][ (string) $block ] : 'Unknown';
			$f_by[ $owner ] = isset( $f_by[ $owner ] ) ? $f_by[ $owner ] + 1 : 1;
		}
		$f_rows = array();
		foreach ( $f_by as $owner => $count ) {
			$f_rows[] = array( 'owner' => $owner, 'count' => $count );
		}

		// Data hooks that can't be enumerated as entries — list who's listening.
		$listeners  = array();
		$data_hooks = array(
			'minn_admin_traffic', 'minn_admin_traffic_day', 'minn_admin_before_render_blocks', 'minn_admin_render_styles',
			'minn_admin_rendered_html', 'minn_admin_insert_blocks', 'minn_admin_editor_commands', 'minn_admin_template_footer',
			'minn_admin_comments_enabled',
		);
		foreach ( $data_hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			$names = array();
			foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$owner   = class_exists( 'Minn_Admin_Notices' ) ? Minn_Admin_Notices::owner_of( $cb['function'] ) : null;
					$names[] = $owner ? $owner['name'] : 'Unknown';
				}
			}
			$listeners[] = array( 'hook' => $hook, 'owners' => array_values( array_unique( $names ) ) );
		}

		return array(
			'surfaces'   => $s_rows,
			'panels'     => $p_rows,
			'designs'    => $d_rows,
			'cache'      => $c_rows,
			'spam'       => $sp_rows,
			'licenses'   => $l_rows,
			'builders'   => $b_rows,
			'blockForms' => $f_rows,
			'listeners'  => $listeners,
		);
	}

	/**
	 * Editor panels — per-post field panels shown in the editor sidebar.
	 * Registered via the `minn_admin_editor_panels` filter; same shape of
	 * capability gating as surfaces. See docs/for-plugin-authors.md.
	 *
	 * @return array
	 */
	public static function editor_panels_for_current_user() {
		$hidden = self::hidden_map();
		$panels = apply_filters( 'minn_admin_editor_panels', array() );
		$out    = array();
		foreach ( ( is_array( $panels ) ? $panels : array() ) as $id => $panel ) {
			$cap = isset( $panel['cap'] ) ? $panel['cap'] : 'edit_posts';
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			if ( isset( $hidden[ 'panel:' . sanitize_key( $id ) ] ) ) {
				continue;
			}
			unset( $panel['cap'] );
			$panel['id'] = sanitize_key( $id );
			$out[]       = $panel;
		}
		return $out;
	}
}
