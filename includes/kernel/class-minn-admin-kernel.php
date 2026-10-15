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
 *     'item_gate' => callable( int $id, string $gate, array $row ): bool   // the row-level check
 *     'labels'    => array( 'not_found' => …, 'forbidden' => … ),
 *     'source'    => array( 'table' => 'evf_entries', 'key' => 'entry_id', 'columns' => [ … ], 'detail' => [ … ],
 *                 //           'network' => true,   // a table the network shares, on the base prefix
 *                 //           'columns' may be a callable, asked when a read runs, never at declaration
 *                 //           'joins' => alias => array( 'table', 'on' => array( col => 'alias.col' ) ),
 *                 //           LEFT JOINs (Minn_Admin_Kernel_SQL::from()) to a unique key (a join to a
 *                 //           column many rows share multiplies rows: use 'first'); the main table is "e" and a
 *                 //           column anywhere may be "alias.col"; 'columns' => array( 'c.email' =>
 *                 //           'customer_email' ) names a joined column in the row
 *                 //           'where'   => conditions | callable(): conditions )
 *                 // 'where' holds for every read of the source: the list, an item, a status
 *                 // card's counts (a site column, a vendor's own row scope); an array source
 *                 // is held to it in PHP. A callable that answers anything but an array
 *                 // matches nothing. A missing table is an empty list and a 404.
 *                 // or array( 'rows' => callable(): array[] )   // a vendor's list in memory (an option, an
 *                 //    API's queue), in the order to show it: the kernel applies the facets, search
 *                 //    (any declared column contains the term), range and page to it in PHP
 *                 // or array( 'list' => callable( array $q ): array( rows, total ), 'get' => callable( int $id ): ?array,
 *                 //           'exists' => callable( int $id ): bool )  // asked straight from the store, for 'gone'
 *     'list'      => array(
 *       'per_page' => 25, 'max' => 100,
 *       'filter'   => array( 'param' => 'status', 'column' => 'status', 'values' => [ … ], 'default' => … ),
 *                  // values may map each value to conditions instead: array( 'unread' => array( 'viewed' => 0 ) ),
 *                  // or to a callable answering them when the request runs (an upcoming view's "now");
 *                  // 'order' => array( 'upcoming' => 'ASC' ) sorts a value its own way
 *       'tab'      => array( 'param' => 'form_id', 'column' => 'form_id', 'gate' => callable( int $tab ): bool, 'refuse' => … ),
 *                  // the gate, when declared, is asked before the scope (a per-form capability)
 *       'scope'    => array( 'column' => 'form_id', 'allowed' => callable(): ?int[], 'refuse' => … ),
 *       'facets'   => array( array( 'param' => 'connector', 'column' => 'connector', 'type' => 'key' ),
 *                             array( 'param' => 'severity', 'values' => array( 'high' => array( 'severity' => 400 ) ) ) ),
 *                  // optional narrowings: a 'key' facet matches its column to the value as
 *                  // sanitize_key() leaves it; 'values' maps each value to conditions (or to
 *                  // a callable answering them), or is a callable( string ): ?conditions.
 *                  // An absent or unknown value narrows nothing. A callable source reads
 *                  // them in $q['facets'].
 *       'search'   => array( 'param' => 'search', 'columns' => [ … ] )
 *                  // and/or 'meta' => array( 'table', 'key', 'name', 'value', 'skip' => prefix,
 *                  //                         'only' => name, 'network' => bool ),
 *                  // and/or 'also' => callable( string $term ): ?conditions; a row matches any of them
 *       'range'    => array( 'column' => 'date_created', 'clock' => 'utc' ),
 *       'order'    => array( 'column' => 'entry_id', 'dir' => 'DESC' ),
 *       'keys'     => array( 'items' => 'items', 'total' => 'total' ),
 *       'batch'    => callable( array $rows ): mixed,   // once per page, before 'row' (a batched
 *                                                     // lookup); its answer rides in $q['batch']
 *       'row'      => callable( array $row, array $q ): array,   // optional: the row as it is
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
			/* translators: %s: the resource id an adapter declared. */
			_doing_it_wrong( __METHOD__, esc_html( sprintf( __( 'A resource id is lowercase path segments and its declaration an array or a function: %s', 'minn-admin' ), $id ) ), 'Minn Admin 0.46.0' );
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
			// A declaration array is never callable; array( 'Class', 'method' ) is.
			$def = is_callable( $slot['lazy'] ) ? call_user_func( $slot['lazy'] ) : $slot['lazy'];
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
			/* translators: %s: the endpoint's route. */
			_doing_it_wrong( 'minn_admin_endpoint', esc_html( sprintf( __( '%s has no gate, so it has no route.', 'minn-admin' ), $ep['path'] ) ), 'Minn Admin 0.46.0' );
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
				// A numeric id is an int above zero; an id of another shape
				// (an md5) is the string its own route pattern already held.
				if ( false !== strpos( $ep['path'], '(?P<id>\d+)' ) ) {
					$id = (int) Minn_Admin::path_param( $r );
					if ( $id <= 0 ) {
						return Minn_Admin_Kernel::fail( 'not_found', $ep['labels']['not_found'] ?? __( 'Not found.', 'minn-admin' ), 404 );
					}
					$ctx['id'] = $id;
				} elseif ( false !== strpos( $ep['path'], '(?P<id>' ) ) {
					$ctx['id'] = (string) Minn_Admin::path_param( $r );
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
		if ( ! empty( $res['item_gate'] ) && ! self::yes( $res['item_gate'], $id, $gate, $row ) ) {
			return $forbidden;
		}
		// The list's scope holds for the row too, so a resource whose row
		// gate is missing or narrower than its scope cannot be read by id.
		$allowed = self::scope( $res );
		$col     = array_key_exists( '_minn_scope', $row ) ? '_minn_scope' : ( isset( $res['list']['scope']['column'] ) ? self::bare( $res['list']['scope']['column'] ) : '' );
		if ( is_array( $allowed ) ) {
			if ( '' === $col || ! array_key_exists( $col, $row ) || ! in_array( (int) $row[ $col ], $allowed, true ) ) {
				return $forbidden;
			}
		}
		unset( $row['_minn_scope'] );
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
		if ( empty( $src['table'] ) || ! Minn_Admin_Kernel_SQL::exists( self::table_of( $src ) ) ) {
			return null;
		}
		$fixed = self::fixed( $src );
		if ( null === $fixed ) {
			return null;
		}
		$cols = self::cols( $src[ $which ] ?? $src['columns'] ?? array() );
		// The scope column under a name no other column can take, so a joined
		// column of the same name can never stand in for it in load().
		if ( ! empty( $res['list']['scope']['column'] ) ) {
			$cols                                             = $cols ? $cols : array( '*' );
			$cols[ (string) $res['list']['scope']['column'] ] = '_minn_scope';
		}
		return Minn_Admin_Kernel_SQL::row( self::table_of( $src ), $src['key'], (int) $id, $cols, $fixed );
	}

	/**
	 * Columns as declared, or as a function answers them when the read runs
	 * (a column a vendor's migration adds). An array is always the list
	 * itself, never read as array( 'Class', 'method' ).
	 */
	private static function cols( $cols ) {
		if ( ! is_array( $cols ) ) {
			$cols = is_callable( $cols ) ? call_user_func( $cols ) : array();
		}
		return (array) $cols;
	}

	/** A column's name in a row: "c.email" reads as "email". */
	private static function bare( $col ) {
		$col = explode( "\0", (string) $col, 2 )[0];
		return false === strpos( $col, '.' ) ? $col : substr( $col, strpos( $col, '.' ) + 1 );
	}

	/** A request value as text: '' for an array or an object, never a notice. */
	private static function param( WP_REST_Request $r, $name ) {
		$v = $r->get_param( (string) $name );
		return is_scalar( $v ) ? trim( (string) $v ) : '';
	}

	/** A table source's table, as the SQL layer names it. */
	private static function table_of( array $src ) {
		return array( 'table' => (string) $src['table'], 'network' => ! empty( $src['network'] ), 'joins' => (array) ( $src['joins'] ?? array() ) );
	}

	/**
	 * The conditions every read of a table source meets: array() for none,
	 * null when its function answers anything but an array (match nothing).
	 *
	 * @return array|null
	 */
	private static function fixed( array $src ) {
		if ( ! isset( $src['where'] ) ) {
			return array();
		}
		if ( is_array( $src['where'] ) ) {
			return $src['where'];
		}
		try {
			$w = is_callable( $src['where'] ) ? call_user_func( $src['where'] ) : null;
		} catch ( \Throwable $e ) {
			$w = null;
		}
		return is_array( $w ) ? $w : null;
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
			$v      = self::param( $r, $f['param'] ?? 'status' );
			if ( '' === $v || ! in_array( $v, $names, true ) ) {
				$v = (string) ( $f['default'] ?? $names[0] );
			}
			$q['filter'] = $v;
			if ( isset( $f['order'][ $v ] ) ) {
				$q['dir'] = 'ASC' === strtoupper( (string) $f['order'][ $v ] ) ? 'ASC' : 'DESC';
			}
			if ( $mapped ) {
				$conds = is_callable( $values[ $v ] ) && ! is_array( $values[ $v ] ) ? call_user_func( $values[ $v ] ) : $values[ $v ];
				if ( ! is_array( $conds ) ) {
					$q['none'] = true; // A view that cannot say what it holds shows nothing.
				}
				// Under their own names, as a callable source reads them
				// ($q['where']['post_status']); every other rule is tagged.
				$q['where'] = array_merge( $q['where'], (array) $conds );
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
			$tab = (int) self::param( $r, $l['tab']['param'] );
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
		$q['facets'] = array();
		foreach ( (array) ( $l['facets'] ?? array() ) as $i => $f ) {
			$v = self::param( $r, (string) $f['param'] );
			if ( '' === $v ) {
				continue;
			}
			$conds = null;
			if ( 'key' === ( $f['type'] ?? '' ) ) {
				$v     = sanitize_key( $v );
				$conds = '' === $v ? null : array( $f['column'] => $v );
			} elseif ( isset( $f['values'] ) && is_array( $f['values'] ) ) {
				$conds = $f['values'][ $v ] ?? null;
				$conds = is_callable( $conds ) ? call_user_func( $conds ) : $conds;
			} elseif ( isset( $f['values'] ) && is_callable( $f['values'] ) ) {
				$conds = call_user_func( $f['values'], $v );
			}
			if ( ! is_array( $conds ) ) {
				continue;
			}
			$q['facets'][ (string) $f['param'] ] = $v;
			foreach ( $conds as $col => $rule ) {
				$q['where'][ $col . "\0facet{$i}" ] = $rule;
			}
		}
		if ( ! empty( $l['search'] ) ) {
			$q['search'] = self::param( $r, $l['search']['param'] ?? 'search' );
		}
		// What every read of the source meets (a site column), first; an
		// array source is held to it in PHP, a callable one reads it in
		// list_where().
		$fixed = self::fixed( $res['source'] ?? array() );
		if ( null === $fixed ) {
			$q['none'] = true;
			return $q;
		}
		$own = array();
		foreach ( $fixed as $col => $rule ) {
			$own[ $col . "\0fixed" ] = $rule;
		}
		$q['where'] = array_merge( $own, $q['where'] );
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
		return Minn_Admin_Kernel_SQL::where( $where, $alias );
	}

	public static function handle_list( array $res, WP_REST_Request $r ) {
		$l    = $res['list'];
		$keys = array_merge( array( 'items' => 'items', 'total' => 'total' ), (array) ( $l['keys'] ?? array() ) );
		$q    = self::list_query( $res, $r );
		if ( is_wp_error( $q ) ) {
			return $q;
		}
		$src = $res['source'];
		if ( ( isset( $q['allowed'] ) && ! $q['allowed'] ) || ! empty( $q['none'] )
			|| ( ! empty( $src['table'] ) && ! Minn_Admin_Kernel_SQL::exists( self::table_of( $src ) ) ) ) {
			return array( $keys['items'] => array(), $keys['total'] => 0 );
		}
		if ( ! empty( $src['rows'] ) ) {
			list( $rows, $total ) = self::array_page( $l, $q, (array) call_user_func( $src['rows'] ) );
		} elseif ( ! empty( $src['list'] ) ) {
			list( $rows, $total ) = call_user_func( $src['list'], $q );
			// A callable source is handed the scope in $q['allowed']; its rows
			// are held to it here as well, and a row without the scope column
			// cannot show that it belongs.
			if ( isset( $q['allowed'] ) ) {
				$col  = self::bare( $l['scope']['column'] );
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
				list( $rs, $ra ) = minn_admin_chart_range_clause( $r, Minn_Admin_Kernel_SQL::col( $l['range']['column'] ), (string) ( $l['range']['clock'] ?? 'local' ) );
				$sql             = array_merge( $sql, $rs );
				$args            = array_merge( $args, $ra );
			}
			list( $rows, $total ) = Minn_Admin_Kernel_SQL::page( array(
				'table'   => self::table_of( $src ),
				'key'     => $src['key'],
				'columns' => self::cols( $src['columns'] ),
				'sql'     => $sql,
				'args'    => $args,
				'order'   => $l['order']['column'] ?? $src['key'],
				'dir'     => $q['dir'] ?? ( $l['order']['dir'] ?? 'DESC' ),
				'limit'   => $q['per_page'],
				'offset'  => ( $q['page'] - 1 ) * $q['per_page'],
			) );
		}
		$items = array();
		if ( ! empty( $l['batch'] ) ) {
			$q['batch'] = call_user_func( $l['batch'], array_map( function ( $row ) {
				return (array) $row;
			}, (array) $rows ) );
		}
		foreach ( (array) $rows as $row ) {
			$built   = empty( $l['row'] ) ? (array) $row : call_user_func( $l['row'], (array) $row, $q );
			$items[] = empty( $l['fields'] ) ? $built : Minn_Admin_Kernel_Fields::out( $l['fields'], (array) $built );
		}
		return array( $keys['items'] => $items, $keys['total'] => (int) $total );
	}

	/**
	 * A page of an array source: the list's conditions, search and range
	 * applied in PHP, the source's order kept.
	 *
	 * @return array [ rows, total ]
	 */
	private static function array_page( array $l, array $q, array $rows ) {
		$term  = '' === $q['search'] ? '' : $q['search'];
		$clock = (string) ( $l['range']['clock'] ?? 'epoch' );
		$from  = empty( $l['range']['column'] ) ? null : minn_admin_chart_bound_epoch( self::param( $q['request'], 'after' ), $clock );
		$to    = empty( $l['range']['column'] ) ? null : minn_admin_chart_bound_epoch( self::param( $q['request'], 'before' ), $clock );
		$kept  = array();
		foreach ( $rows as $row ) {
			$row = (array) $row;
			if ( ! Minn_Admin_Kernel_SQL::matches( $row, $q['where'] ) ) {
				continue;
			}
			if ( '' !== $term ) {
				$hit = false;
				foreach ( (array) ( $l['search']['columns'] ?? array() ) as $c ) {
					$v   = isset( $row[ $c ] ) && is_scalar( $row[ $c ] ) ? (string) $row[ $c ] : '';
					$hit = $hit || false !== ( function_exists( 'mb_stripos' ) ? mb_stripos( $v, $term ) : stripos( $v, $term ) );
				}
				if ( ! $hit ) {
					continue;
				}
			}
			if ( null !== $from || null !== $to ) {
				$v  = $row[ $l['range']['column'] ] ?? '';
				$ts = is_numeric( $v ) ? (int) $v : ( '' === (string) $v ? false : strtotime( (string) $v ) );
				if ( false === $ts || ( null !== $from && $ts < $from ) || ( null !== $to && $ts > $to ) ) {
					continue;
				}
			}
			$kept[] = $row;
		}
		return array( array_slice( $kept, ( $q['page'] - 1 ) * $q['per_page'], $q['per_page'] ), count( $kept ) );
	}

	/** A search clause: LIKE over declared columns, EXISTS over a meta table, or the declaration's own conditions; any of them. */
	private static function search_sql( array $s, array $src, $term ) {
		global $wpdb;
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$parts = array();
		$args  = array();
		foreach ( (array) ( $s['columns'] ?? array() ) as $c ) {
			$parts[] = Minn_Admin_Kernel_SQL::col( $c ) . ' LIKE %s';
			$args[]  = $like;
		}
		$mt = empty( $s['meta'] ) ? null : array( 'table' => $s['meta']['table'], 'network' => ! empty( $s['meta']['network'] ) );
		if ( $mt && Minn_Admin_Kernel_SQL::exists( $mt ) ) {
			$m   = $s['meta'];
			$sql = 'EXISTS ( SELECT 1 FROM ' . Minn_Admin_Kernel_SQL::table( $mt ) . ' m WHERE m.' . Minn_Admin_Kernel_SQL::ident( $m['key'] ) . ' = e.' . Minn_Admin_Kernel_SQL::ident( $src['key'] );
			if ( isset( $m['only'] ) ) {
				$sql   .= ' AND m.' . Minn_Admin_Kernel_SQL::ident( $m['name'] ) . ' = %s';
				$args[] = (string) $m['only'];
			}
			if ( ! empty( $m['skip'] ) ) {
				$sql   .= ' AND m.' . Minn_Admin_Kernel_SQL::ident( $m['name'] ) . ' NOT LIKE %s';
				$args[] = $wpdb->esc_like( (string) $m['skip'] ) . '%';
			}
			$parts[] = $sql . ' AND m.' . Minn_Admin_Kernel_SQL::ident( $m['value'] ) . ' LIKE %s )';
			$args[]  = $like;
		}
		$also = isset( $s['also'] ) && is_callable( $s['also'] ) ? call_user_func( $s['also'], $term ) : null;
		if ( is_array( $also ) && $also ) {
			list( $as, $aa ) = Minn_Admin_Kernel_SQL::where( $also, 'e' );
			$parts[]         = '( ' . implode( ' AND ', $as ) . ' )';
			$args            = array_merge( $args, $aa );
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

	/**
	 * What every count over a table resource's rows meets: its fixed
	 * conditions and the list's scope, keyed so neither replaces the other.
	 * null when the caller may see none of its rows or the table is missing.
	 *
	 * @param array $res Resource.
	 * @return array|null
	 */
	private static function base_where( array $res ) {
		$src = $res['source'] ?? array();
		if ( empty( $src['table'] ) || ! Minn_Admin_Kernel_SQL::exists( self::table_of( $src ) ) ) {
			return null;
		}
		$fixed   = self::fixed( $src );
		$allowed = self::scope( $res );
		if ( null === $fixed || ( is_array( $allowed ) && ! $allowed ) ) {
			return null;
		}
		$where = array();
		foreach ( $fixed as $col => $rule ) {
			$where[ $col . "\0fixed" ] = $rule;
		}
		if ( is_array( $allowed ) ) {
			$where[ $res['list']['scope']['column'] . "\0scope" ] = $allowed;
		}
		return $where;
	}

	/**
	 * The rule a date column on its clock needs for "within the last N seconds".
	 *
	 * @param string $clock   utc | local | epoch | local-epoch.
	 * @param int    $seconds The window.
	 * @return array
	 */
	public static function since( $clock, $seconds ) {
		$seconds = (int) $seconds;
		switch ( (string) $clock ) {
			case 'epoch':
				return array( 'op' => '>=', 'value' => time() - $seconds );
			case 'local-epoch':
				return array( 'op' => '>=', 'value' => (int) current_time( 'timestamp' ) - $seconds ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- the column holds this clock.
			case 'local':
				return array( 'op' => '>=', 'value' => gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - $seconds ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			default:
				return array( 'op' => '>=', 'value' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) );
		}
	}

	/**
	 * "5 mins ago" for a date as a column on its clock stores it, or a dash.
	 *
	 * @param mixed  $stored Stored value.
	 * @param string $clock  utc | local | epoch | local-epoch.
	 * @return string
	 */
	public static function ago( $stored, $clock ) {
		if ( null === $stored || ! is_scalar( $stored ) || '' === (string) $stored ) {
			return '—';
		}
		$now = time();
		if ( 'epoch' === $clock ) {
			$ts = (int) floatval( $stored );
		} elseif ( 'local-epoch' === $clock || 'local' === $clock ) {
			$ts  = 'local' === $clock ? (int) strtotime( (string) $stored ) : (int) $stored;
			$now = (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- the same clock as the column.
		} else {
			$ts = (int) strtotime( (string) $stored . ' UTC' );
		}
		if ( $ts <= 0 ) {
			return '—';
		}
		/* translators: %s: human-readable time since the last event. */
		return sprintf( __( '%s ago', 'minn-admin' ), human_time_diff( $ts, $now ) );
	}

	/**
	 * Counts for an activity log's status card, over exactly the rows its
	 * list may show: the resource's table, fixed conditions and scope, on
	 * the date column and clock its list's range declares.
	 *
	 * @param string|array $id Resource id, or a bare source (table, network, key,
	 *                         where, and date: column + clock) for a card whose
	 *                         list Minn does not serve.
	 * @param array        $c  top (column, where, window: the most frequent value,
	 *                   seven days by default), counts (name => where + window,
	 *                   0 for all time), chart (split, secondary, where; false
	 *                   for none).
	 * @return array|null null when the table is missing; otherwise day, week,
	 *                    total, last (the newest row's stored date or null),
	 *                    ago, top (value + count, or null), counts and days.
	 */
	public static function log_counts( $id, array $c = array() ) {
		$res = is_array( $id ) ? array( 'source' => $id, 'list' => array( 'range' => (array) ( $id['date'] ?? array() ) ) ) : self::def( $id );
		if ( ! is_array( $res ) || empty( $res['source']['table'] ) || empty( $res['list']['range']['column'] ) ) {
			return null;
		}
		$src   = $res['source'];
		$table = self::table_of( $src );
		if ( ! Minn_Admin_Kernel_SQL::exists( $table ) ) {
			return null;
		}
		$col   = (string) $res['list']['range']['column'];
		$clock = (string) ( $res['list']['range']['clock'] ?? 'local' );
		$base  = self::base_where( $res );
		$out   = array( 'day' => 0, 'week' => 0, 'total' => 0, 'last' => null, 'ago' => '—', 'top' => null, 'counts' => array(), 'days' => minn_admin_chart_days() );
		foreach ( array_keys( (array) ( $c['counts'] ?? array() ) ) as $k ) {
			$out['counts'][ $k ] = 0;
		}
		if ( null === $base ) {
			return $out;
		}
		$w = function ( array $extra = array(), $window = 0 ) use ( $base, $col, $clock ) {
			foreach ( $extra as $k => $rule ) {
				$base[ $k . "\0extra" ] = $rule;
			}
			if ( $window > 0 ) {
				$base[ $col . "\0since" ] = Minn_Admin_Kernel::since( $clock, $window );
			}
			return $base;
		};
		$out['total'] = Minn_Admin_Kernel_SQL::count( $table, $w() );
		$out['day']   = Minn_Admin_Kernel_SQL::count( $table, $w( array(), DAY_IN_SECONDS ) );
		$out['week']  = Minn_Admin_Kernel_SQL::count( $table, $w( array(), 7 * DAY_IN_SECONDS ) );
		$out['last']  = Minn_Admin_Kernel_SQL::latest( $table, $col, $w(), $src['key'] );
		$out['ago']   = self::ago( $out['last'], $clock );
		if ( ! empty( $c['top']['column'] ) ) {
			$out['top'] = Minn_Admin_Kernel_SQL::top( $table, $c['top']['column'], $w( (array) ( $c['top']['where'] ?? array() ), (int) ( $c['top']['window'] ?? 7 * DAY_IN_SECONDS ) ) );
		}
		foreach ( (array) ( $c['counts'] ?? array() ) as $k => $spec ) {
			$out['counts'][ $k ] = Minn_Admin_Kernel_SQL::count( $table, $w( (array) ( $spec['where'] ?? array() ), (int) ( $spec['window'] ?? 0 ) ) );
		}
		if ( false !== ( $c['chart'] ?? array() ) ) {
			$ch          = (array) ( $c['chart'] ?? array() );
			$out['days'] = Minn_Admin_Kernel_SQL::chart_days( array(
				'table'     => $table,
				'column'    => $col,
				'clock'     => $clock,
				'where'     => $w( (array) ( $ch['where'] ?? array() ) ),
				'split'     => $ch['split'] ?? '',
				'secondary' => $ch['secondary'] ?? array(),
			) );
		}
		return $out;
	}

	/**
	 * The rows every activity card opens with, by name: the last day (the
	 * week as its hint), all time, and the last event.
	 *
	 * @param array $n log_counts().
	 * @return array
	 */
	public static function event_rows( array $n ) {
		return array(
			'day'   => array(
				'label' => __( 'Events (24h)', 'minn-admin' ),
				'value' => number_format_i18n( (int) $n['day'] ),
				/* translators: %s: number of events recorded in the last 7 days. */
				'hint'  => sprintf( __( '%s in the last 7 days', 'minn-admin' ), number_format_i18n( (int) $n['week'] ) ),
			),
			'total' => array(
				'label' => __( 'Events all-time', 'minn-admin' ),
				'value' => number_format_i18n( (int) $n['total'] ),
			),
			'last'  => array(
				'label' => __( 'Last event', 'minn-admin' ),
				'value' => (string) $n['ago'],
			),
		);
	}

	/**
	 * The distinct values of a column over the rows a resource's list may
	 * show (a tab list), sorted.
	 *
	 * @param string $id     Resource id.
	 * @param string $column Column.
	 * @param array  $where  Further conditions.
	 * @return string[]
	 */
	public static function distinct( $id, $column, array $where = array() ) {
		$res  = self::def( $id );
		$base = is_array( $res ) ? self::base_where( $res ) : null;
		if ( null === $base ) {
			return array();
		}
		foreach ( $where as $k => $rule ) {
			$base[ $k . "\0extra" ] = $rule;
		}
		return Minn_Admin_Kernel_SQL::distinct( self::table_of( $res['source'] ), $column, $base );
	}

	/**
	 * The value a date column on a clock holds at a moment: 'now', or a
	 * whole number of site days from today's local midnight (0 is today's,
	 * 1 tomorrow's, -13 the chart window's first). Clocks: utc and local
	 * datetimes, date (Y-m-d), epoch, and local-epoch (the site's wall clock
	 * read as an epoch).
	 *
	 * @param string     $clock utc | local | date | epoch | local-epoch.
	 * @param string|int $when  'now' or a day offset.
	 * @return string|int
	 */
	public static function at( $clock, $when = 'now' ) {
		$zone = wp_timezone();
		if ( 'now' === $when ) {
			$t = new DateTimeImmutable( 'now', $zone );
		} else {
			// From the day's date, as the chart's own days are named: a zone
			// that skips midnight starts that day at its first instant, and
			// the next day is still at midnight.
			$date = minn_admin_chart_day( (int) $when );
			if ( 'date' === $clock ) {
				return $date;
			}
			if ( 'local' === $clock ) {
				return $date . ' 00:00:00';
			}
			if ( 'local-epoch' === $clock ) {
				return (int) strtotime( $date . ' 00:00:00 UTC' );
			}
			$t = new DateTimeImmutable( $date . ' 00:00:00', $zone );
		}
		switch ( (string) $clock ) {
			case 'epoch':
				return $t->getTimestamp();
			case 'local-epoch':
				return $t->getTimestamp() + $t->getOffset();
			case 'local':
				return $t->format( 'Y-m-d H:i:s' );
			case 'date':
				return $t->format( 'Y-m-d' );
			default:
				return $t->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		}
	}

	/**
	 * A resource's rows a status card reads, under its fixed conditions and
	 * scope plus these: null when the caller may see none or the table is
	 * missing.
	 *
	 * @param string $id    Resource id.
	 * @param array  $where Further conditions.
	 * @return array|null [ table spec, conditions ]
	 */
	private static function card_rows( $id, array $where ) {
		$res  = self::def( $id );
		$base = is_array( $res ) ? self::base_where( $res ) : null;
		if ( null === $base ) {
			return null;
		}
		foreach ( $where as $k => $rule ) {
			$base[ $k . "\0card" ] = $rule;
		}
		return array( self::table_of( $res['source'] ), $base );
	}

	/**
	 * How many of a resource's rows the caller may see meet conditions.
	 *
	 * @param string $id    Resource id.
	 * @param array  $where Conditions.
	 * @return int
	 */
	public static function count( $id, array $where = array() ) {
		$c = self::card_rows( $id, $where );
		return $c ? Minn_Admin_Kernel_SQL::count( $c[0], $c[1] ) : 0;
	}

	/**
	 * The lowest value of a column among the rows the caller may see that
	 * meet conditions (the next booking's start), or null.
	 *
	 * @param string $id     Resource id.
	 * @param string $column Column.
	 * @param array  $where  Conditions.
	 * @return string|null
	 */
	public static function first( $id, $column, array $where = array() ) {
		$c = self::card_rows( $id, $where );
		return $c ? Minn_Admin_Kernel_SQL::latest( $c[0], $column, $c[1], $column, 'ASC' ) : null;
	}

	/**
	 * A status chart's days over the rows the caller may see: column, clock,
	 * where, split, secondary and ahead, as Minn_Admin_Kernel_SQL::chart_days().
	 *
	 * @param string $id Resource id.
	 * @param array  $c  Chart.
	 * @return array Days (empty days when the caller may see nothing).
	 */
	public static function days( $id, array $c ) {
		$rows = self::card_rows( $id, (array) ( $c['where'] ?? array() ) );
		if ( ! $rows ) {
			return Minn_Admin_Kernel_SQL::empty_days( ! empty( $c['ahead'] ) );
		}
		return Minn_Admin_Kernel_SQL::chart_days( array_merge( $c, array( 'table' => $rows[0], 'where' => $rows[1] ) ) );
	}
}
