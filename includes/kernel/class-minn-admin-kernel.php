<?php
/**
 * The resource kernel: adapters declare a resource, Minn owns its routes.
 *
 * docs/adapter-kernel.md is the design. An adapter declares what is
 * vendor-specific (where rows live, who may do what, how a row reads) and
 * the kernel owns the plumbing every hand-written route used to repeat:
 * route registration under minn-admin/v1 (so the write fence always arms),
 * a gate per verb with no route for a verb without one, ids from the URL
 * only, capped pages with id tie-breaks, prepared SQL from declared
 * identifiers, row scope, typed output with nothing undeclared leaving, the
 * not-found / forbidden / wrong-state answers in that order, a post-condition
 * check so a refused write never reports success, and one error shape.
 *
 * Two tiers. minn_admin_resource() declares a list, an item and its actions.
 * minn_admin_endpoint() is the handler tier: a route whose logic stays in the
 * adapter (a status card, a send, a job) still gets the kernel's route, gate,
 * typed ids and error shape. The API is internal until a second family ports.
 *
 * A resource:
 *
 *   minn_admin_resource( 'everest/entries', array(
 *     'active'    => callable(): bool        // routes register only when true
 *     'gates'     => array( 'view' => callable(): bool, 'delete' => … ),
 *                                            // called with no arguments; 'view' is required
 *     'item_gate' => callable( int $id, string $gate ): bool   // the row-level check
 *     'labels'    => array( 'not_found' => …, 'forbidden' => … ),
 *     'source'    => array( 'table' => 'evf_entries', 'key' => 'entry_id', 'columns' => [ … ], 'detail' => [ … ] )
 *                 // or array( 'list' => callable( array $q ): array( rows, total ), 'get' => callable( int $id ): ?array,
 *                 //           'exists' => callable( int $id ): bool )  // asked straight from the store, for 'gone'
 *     'list'      => array(
 *       'per_page' => 25, 'max' => 100,
 *       'filter'   => array( 'param' => 'status', 'column' => 'status', 'values' => [ … ], 'default' => … ),
 *                  // values may map each value to conditions instead: array( 'unread' => array( 'viewed' => 0 ) )
 *       'tab'      => array( 'param' => 'form_id', 'column' => 'form_id', 'gate' => callable( int $tab ): bool, 'refuse' => … ),
 *                  // the gate, when declared, is asked before the scope (a per-form capability)
 *       'scope'    => array( 'column' => 'form_id', 'allowed' => callable(): ?int[], 'refuse' => … ),
 *       'search'   => array( 'param' => 'search', 'columns' => [ … ] )
 *                  // or 'meta' => array( 'table', 'key', 'name', 'value', 'skip' => prefix )
 *       'range'    => array( 'column' => 'date_created', 'clock' => 'utc' ),
 *       'order'    => array( 'column' => 'entry_id', 'dir' => 'DESC' ),
 *       'keys'     => array( 'items' => 'items', 'total' => 'total' ),
 *       'row'      => callable( array $row ): array,
 *       'fields'   => array( name => type ),      // Minn_Admin_Kernel_Fields
 *     ),
 *     'item'      => array( 'view' => callable( array $row, WP_REST_Request $r ): array|WP_Error, 'fields' => … ),
 *     'actions'   => array( 'trash' => array(
 *       'method' => 'POST',           // POST /<id>/(?P<id>\d+)/trash; DELETE uses the item route
 *       'gate'   => 'delete',         // a declared gate; no gate, no route
 *       'when'   => array( 'status' => array( 'publish' ) ),   // or callable( array $row ): bool
 *       'code'   => 'bad_status', 'refuse' => …,               // the 400 when the row is not in that state
 *       'run'    => callable( int $id, array $row ): mixed|WP_Error,
 *       'expect' => array( 'status' => 'trash' ) | 'gone' | callable( int $id, ?array $fresh, mixed $ran ): bool,
 *       'fail'   => …,                // the 500 when the expectation does not hold
 *       'reply'  => callable( int $id, ?array $fresh, mixed $ran ): array,   // merged over { ok: true }
 *       'done'   => …,                // the reply's message
 *     ) ),
 *   ) );
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Kernel {

	const NS = 'minn-admin/v1';

	/** Declared resources by id. */
	private static $resources = array();

	/** Declared endpoints, in declaration order. */
	private static $endpoints = array();

	private static $hooked = false;

	/** What has registered routes this request, so nothing registers twice. */
	private static $done = array();

	/**
	 * Declare a resource. Routes register at rest_api_init.
	 *
	 * The declaration may be a function returning the array, and an adapter
	 * that declares when its file loads passes one: its labels and messages
	 * are translated when the kernel first needs them, after Minn's
	 * translations load at init in the reader's language, not before.
	 *
	 * @param string         $id  Route base under minn-admin/v1 ("everest/entries").
	 * @param array|callable $def Declaration (see the class header), or a function returning it.
	 */
	public static function resource( $id, $def ) {
		$id = trim( (string) $id, '/' );
		if ( ! preg_match( '#^[a-z0-9-]+(/[a-z0-9-]+)*$#', $id ) || ! ( is_array( $def ) || is_callable( $def ) ) ) {
			_doing_it_wrong( __METHOD__, esc_html( 'Resource ids are lowercase path segments, declarations arrays or functions: ' . $id ), 'Minn Admin 0.46.0' );
			return;
		}
		self::$resources[ $id ] = array( 'lazy' => $def, 'id' => $id, 'file' => self::caller() );
		self::hook();
		// Declared after the REST server started (a late plugin, a test):
		// register now rather than never.
		if ( did_action( 'rest_api_init' ) ) {
			self::register_one( 'resource', $id, self::def( $id ) );
		}
	}

	/**
	 * A resource's declaration, built from its function the first time it
	 * is asked for.
	 *
	 * @param string $id Resource id.
	 * @return array|null
	 */
	private static function def( $id ) {
		$slot = self::$resources[ $id ] ?? null;
		if ( ! is_array( $slot ) ) {
			return null;
		}
		if ( array_key_exists( 'lazy', $slot ) ) {
			if ( ! did_action( 'init' ) ) {
				_doing_it_wrong( __METHOD__, esc_html( "{$id} was needed before init, so its labels are not yet in the reader's language." ), 'Minn Admin 0.46.0' );
			}
			$def = is_callable( $slot['lazy'] ) && ! is_array( $slot['lazy'] ) ? call_user_func( $slot['lazy'] ) : $slot['lazy'];
			$def = is_array( $def ) ? $def : array();
			$def['id']              = $slot['id'];
			$def['file']            = $slot['file'];
			self::$resources[ $id ] = $def;
		}
		return self::$resources[ $id ];
	}

	/**
	 * Declare a handler-tier endpoint.
	 *
	 * @param string $path Route under minn-admin/v1, may hold (?P<id>\d+).
	 * @param array  $def  methods, gate (callable, required), run (callable(
	 *                     WP_REST_Request, array ctx )), active, args.
	 *                     Any message in it is translated when declared, so
	 *                     an endpoint declared as its file loads keeps its
	 *                     messages inside run.
	 */
	public static function endpoint( $path, array $def ) {
		$def['path']       = trim( (string) $path, '/' );
		$def['file']       = self::caller();
		self::$endpoints[] = $def;
		self::hook();
		if ( did_action( 'rest_api_init' ) ) {
			self::register_one( 'endpoint', count( self::$endpoints ) - 1, $def );
		}
	}

	/**
	 * The file that declared a resource or endpoint. Each route carries it
	 * as minn_file, so tools that attribute routes to adapters (the parity
	 * capture, the inventory) see the adapter, not the kernel.
	 */
	private static function caller() {
		$root = wp_normalize_path( MINN_ADMIN_DIR );
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 6 ) as $f ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			$file = isset( $f['file'] ) ? wp_normalize_path( $f['file'] ) : '';
			if ( '' === $file || false !== strpos( $file, '/includes/kernel/' ) ) {
				continue;
			}
			// Relative, so a route definition never carries a server path.
			return 0 === strpos( $file, $root ) ? substr( $file, strlen( $root ) ) : basename( $file );
		}
		return '';
	}

	private static function hook() {
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
		}
	}

	/** What is declared, for the conformance runner and the inventory. */
	public static function resources() {
		foreach ( array_keys( self::$resources ) as $id ) {
			self::def( $id );
		}
		return self::$resources;
	}

	public static function endpoints() {
		return self::$endpoints;
	}

	/** Whether a declaration's `active` holds: true when it has none, a boolean as it is, a callable only when it returns true. */
	private static function active( array $def ) {
		if ( ! array_key_exists( 'active', $def ) ) {
			return true;
		}
		return is_bool( $def['active'] ) ? $def['active'] : self::yes( $def['active'] );
	}

	/**
	 * Whether a check says yes: only a strict true. A permission callback
	 * that says no with a WP_Error, a truthy number or a string never opens
	 * anything, and neither does one that throws.
	 *
	 * @param callable $fn   The check.
	 * @param mixed    ...$args Its arguments.
	 * @return bool
	 */
	public static function yes( $fn, ...$args ) {
		if ( ! is_callable( $fn ) ) {
			return false;
		}
		try {
			return true === call_user_func_array( $fn, $args );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/** rest_api_init: every active declaration's routes. */
	public static function register() {
		foreach ( array_keys( self::$resources ) as $id ) {
			self::register_one( 'resource', $id, self::def( $id ) );
		}
		foreach ( self::$endpoints as $i => $ep ) {
			self::register_one( 'endpoint', $i, $ep );
		}
	}

	private static function register_one( $kind, $key, array $def ) {
		if ( isset( self::$done[ $kind . ':' . $key ] ) || ! self::active( $def ) ) {
			return;
		}
		self::$done[ $kind . ':' . $key ] = true;
		if ( 'resource' === $kind ) {
			self::register_resource( $def );
		} else {
			self::register_endpoint( $def );
		}
	}

	/**
	 * The permission callback for a named gate, or null when the resource
	 * declares no such gate (and so gets no route for it).
	 *
	 * @param array  $res  Resource.
	 * @param string $name Gate name.
	 * @return callable|null
	 */
	private static function gate( array $res, $name ) {
		$fn = $res['gates'][ $name ] ?? null;
		if ( ! is_callable( $fn ) ) {
			return null;
		}
		return function () use ( $fn ) {
			return Minn_Admin_Kernel::yes( $fn );
		};
	}

	private static function missing_gate( $where, $name ) {
		_doing_it_wrong( 'minn_admin_resource', esc_html( "{$where} names no declared gate \"{$name}\", so it has no route." ), 'Minn Admin 0.46.0' );
	}

	private static function register_resource( array $res ) {
		$base = '/' . $res['id'];
		$item = $base . '/(?P<id>\d+)';
		$view = self::gate( $res, 'view' );
		if ( ! $view ) {
			self::missing_gate( $res['id'], 'view' );
			return;
		}
		if ( ! empty( $res['list']['filter'] ) && empty( $res['list']['filter']['values'] ) ) {
			_doing_it_wrong( 'minn_admin_resource', esc_html( "{$res['id']} declares a filter with no values, so it has no list route." ), 'Minn Admin 0.46.0' );
			$res['list'] = array();
		}
		if ( ! empty( $res['list'] ) ) {
			register_rest_route( self::NS, $base, array(
				'minn_file'           => $res['file'],
				'methods'             => 'GET',
				'permission_callback' => $view,
				'callback'            => self::guarded( function ( WP_REST_Request $r ) use ( $res ) {
					return Minn_Admin_Kernel::handle_list( $res, $r );
				} ),
			) );
		}
		$item_eps = array();
		$item_gate = (string) ( $res['item']['gate'] ?? 'view' );
		$item_perm = ! empty( $res['item'] ) ? self::gate( $res, $item_gate ) : null;
		if ( ! empty( $res['item'] ) && ! $item_perm ) {
			self::missing_gate( $res['id'] . ' item', $item_gate );
		}
		if ( $item_perm ) {
			$item_eps[] = array(
				'minn_file'           => $res['file'],
				'methods'             => 'GET',
				'permission_callback' => $item_perm,
				'callback'            => self::guarded( function ( WP_REST_Request $r ) use ( $res ) {
					return Minn_Admin_Kernel::handle_item( $res, $r );
				} ),
			);
		}
		foreach ( (array) ( $res['actions'] ?? array() ) as $slug => $action ) {
			$gate_name = (string) ( $action['gate'] ?? '' );
			$gate      = '' === $gate_name ? null : self::gate( $res, $gate_name );
			if ( ! $gate ) {
				self::missing_gate( $res['id'] . ' action ' . $slug, $gate_name );
				continue;
			}
			// A write says what must hold after it runs, or it has no route:
			// without one the kernel could not tell a refusal from success.
			$expect = $action['expect'] ?? null;
			if ( ! ( 'gone' === $expect || ( is_array( $expect ) && $expect ) || ( ! is_string( $expect ) && is_callable( $expect ) ) ) ) {
				_doing_it_wrong( 'minn_admin_resource', esc_html( "{$res['id']} action {$slug} declares no post-condition ('expect'), so it has no route." ), 'Minn Admin 0.46.0' );
				continue;
			}
			$method = strtoupper( (string) ( $action['method'] ?? 'POST' ) );
			$ep     = array(
				'minn_file'           => $res['file'],
				'methods'             => $method,
				'permission_callback' => $gate,
				'callback'            => self::guarded( function ( WP_REST_Request $r ) use ( $res, $slug ) {
					return Minn_Admin_Kernel::handle_action( $res, $slug, $r );
				} ),
			);
			if ( 'DELETE' === $method ) {
				$item_eps[] = $ep;
			} else {
				register_rest_route( self::NS, $item . '/' . sanitize_key( (string) $slug ), $ep );
			}
		}
		if ( $item_eps ) {
			register_rest_route( self::NS, $item, $item_eps );
		}
	}

	private static function register_endpoint( array $ep ) {
		$gate = $ep['gate'] ?? null;
		if ( ! is_callable( $gate ) ) {
			_doing_it_wrong( 'minn_admin_endpoint', esc_html( $ep['path'] . ' has no gate, so it has no route.' ), 'Minn Admin 0.46.0' );
			return;
		}
		$run = $ep['run'] ?? null;
		$args = array(
			'minn_file'           => $ep['file'],
			'methods'             => $ep['methods'] ?? 'GET',
			'permission_callback' => function () use ( $gate ) {
				return Minn_Admin_Kernel::yes( $gate );
			},
			'callback'            => self::guarded( function ( WP_REST_Request $r ) use ( $run, $ep ) {
				$ctx = array();
				if ( false !== strpos( $ep['path'], '(?P<id>' ) ) {
					$id = (int) Minn_Admin::path_param( $r );
					if ( $id <= 0 ) {
						return Minn_Admin_Kernel::fail( 'not_found', $ep['labels']['not_found'] ?? __( 'Not found.', 'minn-admin' ), 404 );
					}
					$ctx['id'] = $id;
				}
				return call_user_func( $run, $r, $ctx );
			} ),
		);
		if ( ! empty( $ep['args'] ) ) {
			$args['args'] = $ep['args'];
		}
		register_rest_route( self::NS, '/' . $ep['path'], $args );
	}

	/**
	 * A route callback that answers in one shape whatever the handler does:
	 * a value becomes a response, a WP_Error keeps its code, message and the
	 * data the client reads (a field, params) with its status held to an
	 * error status and anything shaped like debug output dropped, and
	 * anything thrown becomes a 500 that names no file, query or vendor
	 * message.
	 *
	 * @param callable $fn Handler.
	 * @return callable
	 */
	public static function guarded( callable $fn ) {
		return function ( WP_REST_Request $r ) use ( $fn ) {
			try {
				$out = call_user_func( $fn, $r );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					error_log( 'Minn Admin ' . $r->get_route() . ': ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return Minn_Admin_Kernel::fail( 'minn_failed', __( 'That did not work. Nothing was reported as done.', 'minn-admin' ), 500 );
			}
			return is_wp_error( $out ) ? Minn_Admin_Kernel::clean_error( $out ) : rest_ensure_response( $out );
		};
	}

	/** A handler's WP_Error as it may leave: see guarded(). */
	public static function clean_error( WP_Error $e ) {
		$data = $e->get_error_data();
		$data = is_array( $data ) ? $data : array();
		foreach ( array_keys( $data ) as $k ) {
			if ( preg_match( '/^(sql|query|queries|trace|backtrace|stack|file|line|exception|last_error|debug)$/i', (string) $k ) ) {
				unset( $data[ $k ] );
			}
		}
		$status         = (int) ( $data['status'] ?? 0 );
		$data['status'] = ( $status >= 400 && $status <= 599 ) ? $status : 500;
		// A vendor message that quotes a query or a server path is replaced.
		$message = (string) $e->get_error_message();
		if ( preg_match( '/\b(SELECT|INSERT|UPDATE|DELETE|REPLACE)\b[\s\S]*\b(FROM|INTO|SET|WHERE)\b/i', $message )
			|| false !== strpos( $message, wp_normalize_path( ABSPATH ) ) || false !== strpos( $message, ABSPATH ) ) {
			$message = __( 'That did not work. Nothing was reported as done.', 'minn-admin' );
		}
		return new WP_Error( $e->get_error_code(), $message, $data );
	}

	public static function fail( $code, $message, $status ) {
		return new WP_Error( $code, $message, array( 'status' => (int) $status ) );
	}

	private static function label( array $res, $key, $fallback ) {
		return isset( $res['labels'][ $key ] ) ? $res['labels'][ $key ] : $fallback;
	}

	/**
	 * Load a row the caller may act on: 404 when it does not exist, 403 when
	 * the row-level gate refuses it. Handlers in the endpoint tier use it too.
	 *
	 * @param string|array $res  Resource or its id.
	 * @param int          $id   Row id from the URL.
	 * @param string       $gate Gate the row is checked against.
	 * @return array|WP_Error
	 */
	public static function load( $res, $id, $gate = 'view' ) {
		if ( is_string( $res ) ) {
			$res = self::def( $res );
		}
		if ( ! is_array( $res ) ) {
			return self::fail( 'not_found', __( 'Not found.', 'minn-admin' ), 404 );
		}
		$id  = (int) $id;
		$row = $id > 0 ? self::fetch( $res, $id, 'detail' ) : null;
		if ( ! $row ) {
			return self::fail( 'not_found', self::label( $res, 'not_found', __( 'Not found.', 'minn-admin' ) ), 404 );
		}
		$forbidden = self::fail( 'forbidden', self::label( $res, 'forbidden', __( 'You cannot access that item.', 'minn-admin' ) ), 403 );
		if ( ! empty( $res['item_gate'] ) && ! self::yes( $res['item_gate'], $id, $gate ) ) {
			return $forbidden;
		}
		// The list's scope holds for the row too, so a resource whose row
		// gate is missing or narrower than its scope cannot be read by id.
		$allowed = self::scope( $res );
		if ( is_array( $allowed ) ) {
			$col = (string) $res['list']['scope']['column'];
			if ( ! array_key_exists( $col, $row ) || ! in_array( (int) $row[ $col ], $allowed, true ) ) {
				return $forbidden;
			}
		}
		return $row;
	}

	/**
	 * The ids a declared scope allows: null for no restriction, an array
	 * otherwise. Anything else a scope function answers (false, a WP_Error,
	 * an error string) is an empty scope, never an open one.
	 *
	 * @return int[]|null
	 */
	private static function scope( array $res ) {
		if ( empty( $res['list']['scope']['allowed'] ) ) {
			return null;
		}
		try {
			$allowed = call_user_func( $res['list']['scope']['allowed'] );
		} catch ( \Throwable $e ) {
			$allowed = array();
		}
		if ( null === $allowed ) {
			return null;
		}
		return is_array( $allowed ) ? array_values( array_map( 'intval', $allowed ) ) : array();
	}

	/** One row from the source, or null. */
	private static function fetch( array $res, $id, $which = 'detail' ) {
		$src = $res['source'] ?? array();
		if ( ! empty( $src['get'] ) ) {
			$row = call_user_func( $src['get'], (int) $id );
			return is_array( $row ) ? $row : null;
		}
		if ( empty( $src['table'] ) ) {
			return null;
		}
		$cols = (array) ( $src[ $which ] ?? $src['columns'] ?? array() );
		return Minn_Admin_Kernel_SQL::row( $src['table'], $src['key'], (int) $id, $cols );
	}

	/**
	 * The list's request, read once: page, size, filter, tab, search, range.
	 *
	 * @return array|WP_Error
	 */
	private static function list_query( array $res, WP_REST_Request $r ) {
		$l        = $res['list'];
		$size     = (int) ( $l['per_page'] ?? 25 );
		$max      = (int) ( $l['max'] ?? 100 );
		$q        = array(
			'per_page' => min( $max, max( 1, (int) ( $r['per_page'] ?: $size ) ) ),
			// Capped so the offset can never overflow into a negative LIMIT.
			'page'     => min( 100000, max( 1, (int) ( $r['page'] ?: 1 ) ) ),
			'where'    => array(),
			'sql'      => array(),
			'args'     => array(),
			'filter'   => null,
			'tab'      => 0,
			'search'   => '',
			'request'  => $r,
		);
		if ( ! empty( $l['filter'] ) ) {
			$f      = $l['filter'];
			$values = (array) $f['values'];
			$mapped = array_keys( $values ) !== range( 0, count( $values ) - 1 );
			$names  = $mapped ? array_map( 'strval', array_keys( $values ) ) : array_map( 'strval', $values );
			$v      = (string) $r->get_param( $f['param'] ?? 'status' );
			if ( '' === $v || ! in_array( $v, $names, true ) ) {
				$v = (string) ( $f['default'] ?? $names[0] );
			}
			$q['filter'] = $v;
			if ( $mapped ) {
				$q['where'] = array_merge( $q['where'], (array) $values[ $v ] );
			} else {
				$q['where'][ $f['column'] ] = $v;
			}
		}
		if ( ! empty( $l['scope'] ) ) {
			$allowed = self::scope( $res );
			if ( is_array( $allowed ) ) {
				$q['allowed'] = $allowed;
				$q['where'][ $l['scope']['column'] . "\0scope" ] = $allowed;
			}
		}
		if ( ! empty( $l['tab'] ) ) {
			$tab = (int) $r->get_param( $l['tab']['param'] );
			if ( $tab && ! empty( $l['tab']['gate'] ) && ! self::yes( $l['tab']['gate'], $tab ) ) {
				return self::fail( 'forbidden', $l['tab']['refuse'] ?? self::label( $res, 'forbidden', __( 'You cannot access that item.', 'minn-admin' ) ), 403 );
			}
			if ( $tab ) {
				// An empty scope answers an empty list whatever is asked; a
				// form outside a non-empty one is refused outright.
				if ( ! empty( $q['allowed'] ) && ! in_array( $tab, $q['allowed'], true ) ) {
					return self::fail( 'forbidden', $l['scope']['refuse'] ?? self::label( $res, 'forbidden', __( 'You cannot access that item.', 'minn-admin' ) ), 403 );
				}
				$q['tab']                                       = $tab;
				$q['where'][ $l['tab']['column'] . "\0tab" ] = $tab;
			}
		}
		if ( ! empty( $l['search'] ) ) {
			$q['search'] = trim( (string) $r->get_param( $l['search']['param'] ?? 'search' ) );
		}
		return $q;
	}

	/**
	 * A list query's conditions (filter, scope, tab) as SQL fragments and
	 * their values, for a callable source that runs its own query over a
	 * table: [ sql fragments, args ], every value a placeholder.
	 *
	 * @param array  $q     The kernel's list query, as the source is handed it.
	 * @param string $alias The table alias the fragments qualify columns with.
	 * @return array
	 */
	public static function list_where( array $q, $alias = 'e' ) {
		return self::where_sql( (array) ( $q['where'] ?? array() ), $alias );
	}

	/** Conditions keyed "column\0why" (so a scope and a tab on one column both apply). */
	private static function where_sql( array $where, $alias ) {
		$sql  = array();
		$args = array();
		foreach ( $where as $key => $rule ) {
			list( $s, $a ) = Minn_Admin_Kernel_SQL::where( array( explode( "\0", (string) $key )[0] => $rule ), $alias );
			$sql           = array_merge( $sql, $s );
			$args          = array_merge( $args, $a );
		}
		return array( $sql, $args );
	}

	public static function handle_list( array $res, WP_REST_Request $r ) {
		$l    = $res['list'];
		$keys = array_merge( array( 'items' => 'items', 'total' => 'total' ), (array) ( $l['keys'] ?? array() ) );
		$q    = self::list_query( $res, $r );
		if ( is_wp_error( $q ) ) {
			return $q;
		}
		if ( isset( $q['allowed'] ) && ! $q['allowed'] ) {
			return array( $keys['items'] => array(), $keys['total'] => 0 );
		}
		$src = $res['source'];
		if ( ! empty( $src['list'] ) ) {
			list( $rows, $total ) = call_user_func( $src['list'], $q );
			// A callable source is handed the scope in $q['allowed']; its rows
			// are held to it here as well, and a row without the scope column
			// cannot show that it belongs.
			if ( isset( $q['allowed'] ) ) {
				$col  = (string) $l['scope']['column'];
				$kept = array_values( array_filter( (array) $rows, function ( $row ) use ( $col, $q ) {
					$row = (array) $row;
					return array_key_exists( $col, $row ) && in_array( (int) $row[ $col ], $q['allowed'], true );
				} ) );
				// A source that ignored the scope cannot be trusted for the
				// total either: count only what is shown.
				if ( count( $kept ) !== count( (array) $rows ) ) {
					$total = count( $kept );
				}
				$rows = $kept;
			}
		} else {
			list( $sql, $args ) = self::where_sql( $q['where'], 'e' );
			if ( '' !== $q['search'] ) {
				list( $s, $a ) = self::search_sql( $l['search'], $src, $q['search'] );
				$sql[]         = $s;
				$args          = array_merge( $args, $a );
			}
			if ( ! empty( $l['range'] ) ) {
				list( $rs, $ra ) = minn_admin_chart_range_clause( $r, 'e.' . Minn_Admin_Kernel_SQL::ident( $l['range']['column'] ), (string) ( $l['range']['clock'] ?? 'local' ) );
				$sql             = array_merge( $sql, $rs );
				$args            = array_merge( $args, $ra );
			}
			list( $rows, $total ) = Minn_Admin_Kernel_SQL::page( array(
				'table'   => $src['table'],
				'key'     => $src['key'],
				'columns' => (array) $src['columns'],
				'sql'     => $sql,
				'args'    => $args,
				'order'   => $l['order']['column'] ?? $src['key'],
				'dir'     => $l['order']['dir'] ?? 'DESC',
				'limit'   => $q['per_page'],
				'offset'  => ( $q['page'] - 1 ) * $q['per_page'],
			) );
		}
		$items = array();
		foreach ( (array) $rows as $row ) {
			$built   = call_user_func( $l['row'], (array) $row, $q );
			$items[] = empty( $l['fields'] ) ? $built : Minn_Admin_Kernel_Fields::out( $l['fields'], (array) $built );
		}
		return array( $keys['items'] => $items, $keys['total'] => (int) $total );
	}

	/** A search clause: LIKE over declared columns, or EXISTS over a meta table. */
	private static function search_sql( array $s, array $src, $term ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $term ) . '%';
		if ( ! empty( $s['meta'] ) ) {
			$m    = $s['meta'];
			$sql  = 'EXISTS ( SELECT 1 FROM ' . Minn_Admin_Kernel_SQL::table( $m['table'] ) . ' m WHERE m.' . Minn_Admin_Kernel_SQL::ident( $m['key'] ) . ' = e.' . Minn_Admin_Kernel_SQL::ident( $src['key'] );
			$args = array();
			if ( ! empty( $m['skip'] ) ) {
				$sql   .= ' AND m.' . Minn_Admin_Kernel_SQL::ident( $m['name'] ) . ' NOT LIKE %s';
				$args[] = $wpdb->esc_like( (string) $m['skip'] ) . '%';
			}
			$sql   .= ' AND m.' . Minn_Admin_Kernel_SQL::ident( $m['value'] ) . ' LIKE %s )';
			$args[] = $like;
			return array( $sql, $args );
		}
		$parts = array();
		$args  = array();
		foreach ( (array) $s['columns'] as $c ) {
			$parts[] = 'e.' . Minn_Admin_Kernel_SQL::ident( $c ) . ' LIKE %s';
			$args[]  = $like;
		}
		return array( $parts ? '( ' . implode( ' OR ', $parts ) . ' )' : '1 = 1', $args );
	}

	public static function handle_item( array $res, WP_REST_Request $r ) {
		$id  = (int) Minn_Admin::path_param( $r );
		$row = self::load( $res, $id, $res['item']['gate'] ?? 'view' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$out = call_user_func( $res['item']['view'], $row, $r );
		if ( is_wp_error( $out ) || empty( $res['item']['fields'] ) ) {
			return $out;
		}
		return Minn_Admin_Kernel_Fields::out( $res['item']['fields'], (array) $out );
	}

	/** Whether a row matches an action's `when`. */
	private static function holds( $when, array $row ) {
		if ( empty( $when ) ) {
			return true;
		}
		if ( ! is_array( $when ) ) {
			return self::yes( $when, $row );
		}
		foreach ( (array) $when as $col => $values ) {
			if ( ! in_array( (string) ( $row[ $col ] ?? '' ), array_map( 'strval', (array) $values ), true ) ) {
				return false;
			}
		}
		return true;
	}

	public static function handle_action( array $res, $slug, WP_REST_Request $r ) {
		$a   = $res['actions'][ $slug ];
		$id  = (int) Minn_Admin::path_param( $r );
		$row = self::load( $res, $id, (string) $a['gate'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! self::holds( $a['when'] ?? null, $row ) ) {
			return self::fail( (string) ( $a['code'] ?? 'bad_status' ), $a['refuse'] ?? __( 'That cannot be done to this item now.', 'minn-admin' ), 400 );
		}
		$ran = call_user_func( $a['run'], $id, $row, $r );
		if ( is_wp_error( $ran ) ) {
			return $ran;
		}
		$fresh  = self::fetch( $res, $id, 'detail' );
		$expect = $a['expect'] ?? null;
		$held   = false;
		if ( 'gone' === $expect ) {
			// A callable source's own read may hide a row from this caller,
			// so gone is asked of the store itself when the source says how.
			// A check that throws proves nothing, so the row counts as still there.
			$exists = $res['source']['exists'] ?? null;
			if ( is_callable( $exists ) ) {
				try {
					$held = false === call_user_func( $exists, $id );
				} catch ( \Throwable $e ) {
					$held = false;
				}
			} else {
				$held = null === $fresh;
			}
		} elseif ( is_array( $expect ) ) {
			$held = is_array( $fresh ) && self::holds( $expect, $fresh );
		} elseif ( is_callable( $expect ) ) {
			$held = self::yes( $expect, $id, $fresh, $ran );
		}
		if ( ! $held ) {
			return self::fail( (string) ( $a['fail_code'] ?? 'not_applied' ), $a['fail'] ?? __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), 500 );
		}
		$reply = array( 'ok' => true );
		if ( ! empty( $a['reply'] ) ) {
			$reply = array_merge( $reply, (array) call_user_func( $a['reply'], $id, $fresh, $ran ) );
		}
		if ( isset( $a['done'] ) ) {
			$reply['message'] = $a['done'];
		}
		return $reply;
	}

	/**
	 * The parts of a collection descriptor that must agree with the routes:
	 * route, pageQuery, search, itemsKey, totalKey, dateQuery and the filter
	 * query. The adapter adds what is presentation (columns, tabs, labels)
	 * through $extra, which wins.
	 *
	 * @param string $id    Resource id.
	 * @param array  $extra Descriptor keys to merge.
	 * @return array
	 */
	public static function collection( $id, array $extra = array() ) {
		$res = self::def( $id );
		if ( ! $res || empty( $res['list'] ) ) {
			return $extra;
		}
		$l    = $res['list'];
		$keys = array_merge( array( 'items' => 'items', 'total' => 'total' ), (array) ( $l['keys'] ?? array() ) );
		$out  = array( 'route' => self::NS . '/' . $id );
		if ( ! empty( $l['range'] ) ) {
			$out['dateQuery'] = 'after={from}&before={to}';
		}
		$out['pageQuery'] = 'per_page=' . (int) ( $l['per_page'] ?? 25 ) . '&page={page}';
		if ( ! empty( $l['search'] ) ) {
			$out['search'] = ( $l['search']['param'] ?? 'search' ) . '={q}';
		}
		$out['itemsKey'] = $keys['items'];
		$out['totalKey'] = $keys['total'];
		if ( ! empty( $l['filter'] ) && isset( $extra['filter'] ) && is_array( $extra['filter'] ) ) {
			$extra['filter']['query'] = ( $l['filter']['param'] ?? 'status' ) . '={v}';
		}
		return array_merge( $out, $extra );
	}

	/**
	 * A row or bulk action for a descriptor: method and route from the
	 * declared action, the rest (label, confirm, danger, which state it shows
	 * in) from $ui. `when` is one value of the action's own `when` column, or
	 * of `key` when the action declares no `when`.
	 *
	 * @param string $id   Resource id.
	 * @param string $slug Action slug.
	 * @param array  $ui   label, confirm, danger, body, query, when, key.
	 * @return array
	 */
	public static function ui( $id, $slug, array $ui ) {
		$a      = self::def( $id )['actions'][ $slug ] ?? array();
		$method = strtoupper( (string) ( $a['method'] ?? 'POST' ) );
		$out    = array(
			'label'  => $ui['label'] ?? $slug,
			'method' => $method,
			// A query rides the route itself ("force=1"): some servers drop
			// the body of a DELETE.
			'route'  => self::NS . '/' . $id . '/{id}' . ( 'DELETE' === $method ? '' : '/' . $slug ) . ( isset( $ui['query'] ) ? '?' . $ui['query'] : '' ),
		);
		foreach ( array( 'confirm', 'danger', 'body' ) as $k ) {
			if ( isset( $ui[ $k ] ) ) {
				$out[ $k ] = $ui[ $k ];
			}
		}
		if ( isset( $ui['when'] ) ) {
			$col         = is_array( $a['when'] ?? null ) ? (string) array_key_first( $a['when'] ) : 'status';
			$out['when'] = array( 'key' => $ui['key'] ?? $col, 'equals' => $ui['when'] );
		}
		return $out;
	}

	/**
	 * Counts and a 14-day chart for an inbox status card, under the same
	 * scope as the list.
	 *
	 * @param array $c table, scope (column + allowed ids or null), received,
	 *                 unread and spam (conditions), chart (column, clock,
	 *                 where, split, secondary).
	 * @return array received, unread, spam (ints), chart (built, or null)
	 */
	public static function inbox_counts( array $c ) {
		$scope = array();
		if ( isset( $c['scope'] ) && is_array( $c['scope']['allowed'] ) ) {
			$scope = array( $c['scope']['column'] => array_values( array_map( 'intval', $c['scope']['allowed'] ) ) );
		}
		$out = array();
		foreach ( array( 'received', 'unread', 'spam' ) as $k ) {
			$out[ $k ] = isset( $c[ $k ] ) ? Minn_Admin_Kernel_SQL::count( $c['table'], array_merge( (array) $c[ $k ], $scope ) ) : 0;
		}
		$out['days'] = null;
		if ( ! empty( $c['chart'] ) ) {
			$out['days'] = Minn_Admin_Kernel_SQL::chart_days( array_merge( $c['chart'], array(
				'table' => $c['table'],
				'where' => array_merge( (array) ( $c['chart']['where'] ?? array() ), $scope ),
			) ) );
		}
		return $out;
	}
}
