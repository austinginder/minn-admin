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
 * typed ids and error shape. The API is internal: only Minn's own adapters
 * declare through it.
 *
 * A declaration the kernel cannot read whole registers nothing: a key it does
 * not know (a misspelt 'when' would let an action run in any state), a field
 * type it does not know, a scope without its column and function. So does
 * one whose function throws, and an endpoint whose gate takes anything but
 * the request; the rest of the REST API is untouched either way.
 *
 * A resource:
 *
 *   minn_admin_resource( 'everest/entries', array(
 *     'active'    => callable(): bool        // routes register only when true
 *     'gates'     => array( 'view' => callable(): bool, 'delete' => … ),
 *                                            // called with no arguments; 'view' is required;
 *                                            // 'table', when declared, is what a reader of the
 *                                            // whole table (table_refused()) must pass beyond the
 *                                            // gates its item and reads answer to: a column the
 *                                            // rows mask past any gate (a code, a message)
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
 *                 //           'where'   => conditions | callable(): conditions,
 *                 //           'viewer'  => true,    // the where is the viewer's own row scope
 *                 //                                    (a provider's own bookings), not only a slice;
 *                 //                                    or callable(): bool, whether this viewer's scope
 *                 //                                    is all the plugin shows anyone, for a scope
 *                 //                                    that is never empty (a log's list of types)
 *                 //           'tables'  => array( 'wpforms_entry_fields', … ) )   // the vendor's other
 *                 //                                    tables holding this data (entry fields, payments),
 *                 //                                    each a name or a table spec, on the source's prefix
 *                 // 'where' holds for every read of the source: the list, an item, a status
 *                 // card's counts (a site column, a vendor's own row scope); an array source
 *                 // is held to it in PHP. A callable that answers anything but an array
 *                 // matches nothing. A missing table is an empty list and a 404. A reader of
 *                 // whole tables (table_refused()) admits a 'viewer' source only to someone
 *                 // its where answers array() for (or its 'viewer' callable says yes to),
 *                 // and gives each of its 'tables' the source's own answer. No read routes
 *                 // ever touch 'tables'.
 *                 // or array( 'rows' => callable(): array[] )   // a vendor's list in memory (an option, an
 *                 //    API's queue), in the order to show it: the kernel applies the facets, search
 *                 //    (any declared column contains the term), range and page to it in PHP
 *                 // or array( 'list' => callable( array $q ): array( rows, total ), 'get' => callable( int $id ): ?array,
 *                 //           'exists' => callable( int $id ): bool )  // asked straight from the store, for 'gone'
 *     'list'      => array(
 *       'per_page' => 25,   // a page is never longer than 100 rows
 *       'filter'   => array( 'param' => 'status', 'column' => 'status', 'values' => [ … ], 'default' => … ),
 *                  // values may map each value to conditions instead: array( 'unread' => array( 'viewed' => 0 ) ),
 *                  // or to a callable answering them when the request runs (an upcoming view's "now");
 *                  // 'order' => array( 'upcoming' => 'ASC' ) sorts a value its own way;
 *                  // 'many' => true takes several at once (status[]=a&status[]=b, as the
 *                  // filter bar sends them): rows in any of them, rows in any declared
 *                  // value for none or 'any', no row when every value asked for is
 *                  // unknown; no 'default' or 'order'; a callable source reads the values
 *                  // asked for in $q['filter'] (empty for none)
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
 *       'sort'     => array( 'columns' => array( 'name', 'weight' ) ),
 *                  // the columns a person may sort by (a column header): 'columns' lists
 *                  // them, or maps a public name to a column ('hits' => 'e.count');
 *                  // ?orderby=<name>&order=asc|desc picks one (descending unless asc),
 *                  // anything else keeps 'order'. A table sorts in SQL, the key breaking
 *                  // ties in the same direction, and its columns must be the source's own
 *                  // (or a joined alias's); an array source sorts in PHP, numbers before
 *                  // text, ties in the order the rows came; a callable source that
 *                  // declares a sort applies the pick it reads in $q['sort'] ( by, column,
 *                  // dir ). collection() hands the client the matching sortQuery
 *       'batch'    => callable( array $rows ): mixed,   // once per page, before 'row' (a batched
 *                                                     // lookup); its answer rides in $q['batch']
 *       'row'      => callable( array $row, array $q ): array,   // optional: the row as it is
 *       'fields'   => array( name => type ),      // Minn_Admin_Kernel_Fields
 *     ),
 *     'item'      => array( 'view' => callable( array $row, WP_REST_Request $r ): array|WP_Error, 'fields' => … ),
 *     'reads'     => array( 'view' => array( 'gate' => 'detail', 'view' => callable( array $row, WP_REST_Request $r ), 'fields' => … ) ),
 *                 // more GET routes on one item (GET /<id>/view: a mail log's rendered message),
 *                 // each answering to its gate (the item's when it names none) and loaded like
 *                 // the item, only its declared fields leaving
 *     'create'    => array( 'gate' => 'manage', 'run' => callable( WP_REST_Request $r ): int|array|WP_Error,
 *                   'expect' => 'exists' | callable( int $id, ?array $fresh, mixed $ran ): bool,
 *                   'reply' => callable( int $id, ?array $fresh, mixed $ran ): array, 'fail' => … ),
 *                 // POST /<id> beside the list: the vendor's own create answers the new id (a
 *                 // positive int or digits, or array( 'id' => … )), and it is done only once that
 *                 // row reads back for the caller (a table source's above every key before it ran);
 *                 // a source that is not a table declares its own expect; no create on a resource
 *                 // with a scope or a row gate
 *     'actions'   => array( 'trash' => array(
 *       'method' => 'POST',           // POST /<id>/(?P<id>\d+)/trash; DELETE, PUT and PATCH use the
 *       'item'   => true,             // item route itself, and so does a POST that says 'item' (an edit)
 *       'gate'   => 'delete',         // a declared gate; no gate, no route
 *       'when'   => array( 'status' => array( 'publish' ) ),   // or callable( array $row ): bool
 *       'code'   => 'bad_status', 'refuse' => …,               // the 400 when the row is not in that state
 *       'run'    => callable( int $id, array $row ): mixed|WP_Error,
 *       'expect' => array( 'status' => 'trash' ) | 'gone' | callable( int $id, ?array $fresh, mixed $ran ): bool,
 *                   // or 'ran' for a send, which leaves nothing to read back: the run's answer
 *                   // must be true, the text of whom it sent to or array( 'sent' => true, … ),
 *                   // and no wp_mail_failed may fire while it runs
 *       'args'   => array( name => schema ),   // JSON-schema keys only, checked after the gate and the
 *                                      // row: 400 rest_missing_callback_param / rest_invalid_param;
 *                                      // no 'default', 'required' a boolean, no name the route captures
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

	/** What the kernel refused (see wrong()). */
	private static $refused = array();

	/** "METHOD /route" (its shape(): one {} per captured part) => who registered it, so a second declaration of one is refused, never shadowed. */
	private static $claimed = array();

	/** The clocks a date column may be read on. */
	const CLOCKS = array( 'utc', 'local', 'date', 'epoch', 'local-epoch', 'db' );

	/**
	 * Every key a declaration may hold, by where it sits. Anything else is a
	 * mistake the kernel would otherwise read as absent.
	 */
	const KEYS = array(
		'resource' => array( 'active', 'gates', 'item_gate', 'labels', 'source', 'list', 'item', 'reads', 'create', 'actions', 'id', 'file' ),
		'source'   => array( 'table', 'key', 'columns', 'detail', 'network', 'joins', 'where', 'viewer', 'tables', 'rows', 'list', 'get', 'exists' ),
		'join'     => array( 'table', 'core', 'on', 'first' ),
		'list'     => array( 'per_page', 'filter', 'tab', 'scope', 'facets', 'search', 'range', 'order', 'sort', 'batch', 'row', 'fields' ),
		'filter'   => array( 'param', 'column', 'values', 'default', 'order', 'many' ),
		'tab'      => array( 'param', 'column', 'gate', 'refuse' ),
		'scope'    => array( 'column', 'allowed', 'refuse' ),
		'facet'    => array( 'param', 'column', 'type', 'values' ),
		'search'   => array( 'param', 'columns', 'meta', 'also' ),
		'meta'     => array( 'table', 'key', 'name', 'value', 'skip', 'only', 'network' ),
		'range'    => array( 'column', 'clock' ),
		'order'    => array( 'column', 'dir' ),
		'sort'     => array( 'columns' ),
		'item'     => array( 'view', 'fields', 'gate' ),
		'read'     => array( 'gate', 'view', 'fields' ),
		'create'   => array( 'gate', 'run', 'expect', 'reply', 'fail', 'fail_code' ),
		'action'   => array( 'method', 'item', 'gate', 'when', 'code', 'refuse', 'run', 'expect', 'args', 'fail', 'fail_code', 'reply', 'done' ),
		'endpoint' => array( 'methods', 'gate', 'run', 'active', 'args', 'labels', 'path', 'file' ),
		'field'    => array( 'type', 'decode', 'values', 'stored', 'wire', 'nullable' ),
	);

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
			self::wrong( __METHOD__, "A resource id is lowercase path segments and its declaration an array or a function: {$id}" );
			return;
		}
		if ( isset( self::$resources[ $id ] ) ) {
			// The first declaration keeps the id: a second would silently
			// replace a resource other code already reads.
			self::wrong( __METHOD__, "{$id} is declared twice, so the second declaration is ignored." );
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
				self::wrong( __METHOD__, "{$id} was needed before init, so its labels are not yet in the reader's language." );
			}
			// A declaration array is never callable; array( 'Class', 'method' ) is.
			// One that throws (a vendor class an update renamed) is a resource
			// with no routes: it runs at rest_api_init, where a throw would fail
			// every REST request on the site.
			try {
				$def = is_callable( $slot['lazy'] ) ? call_user_func( $slot['lazy'] ) : $slot['lazy'];
			} catch ( \Throwable $e ) {
				self::log( $id, $e );
				self::wrong( __METHOD__, "{$id}'s declaration threw, so it has no routes." );
				$def = array( 'active' => false );
			}
			$def = is_array( $def ) ? $def : array();
			// Refused here, not only at registration: load(), the card readers,
			// distinct() and collection() read the declaration too, and a scope
			// or a fixed condition the kernel cannot read is none to them.
			// It runs where nothing else catches, at rest_api_init: a shape it
			// trips over is a declaration it cannot read, never a fatal.
			try {
				$why = self::unreadable( $def, 'resource' );
			} catch ( \Throwable $e ) {
				$why = 'its declaration cannot be read';
			}
			if ( '' !== $why ) {
				self::wrong( __METHOD__, "{$id}: {$why}, so it has no routes." );
				$def = array( 'active' => false );
			}
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
	 * @param array  $def  methods, gate (callable, required, asked with the
	 *                     request, as WordPress asks a permission callback,
	 *                     so a per-item capability is checked before anything
	 *                     runs), run (callable( WP_REST_Request, array ctx )),
	 *                     active, args (JSON schemas the kernel checks after
	 *                     the gate, as an action's are).
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

	/**
	 * A declaration the kernel refuses, or part of one: a notice for its
	 * author, and a line in refused(), which a runner reads whenever the REST
	 * server started.
	 *
	 * @param string $fn      The function the mistake is in.
	 * @param string $message What was refused, and why.
	 */
	private static function wrong( $fn, $message ) {
		self::$refused[] = (string) $message;
		// Registration runs at rest_api_init, and during a REST request a
		// notice rides the response as an X-WP-DoingItWrong header, to anyone.
		$rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
		if ( $rest ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Minn Admin ' . $fn . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}
		_doing_it_wrong( $fn, esc_html( $message ), 'Minn Admin 0.46.0' );
	}

	/** What the kernel refused, or declared and could not apply, this request, in order. */
	public static function refused() {
		return self::$refused;
	}

	/** A throw, for the adapter's author: in the debug log, never in a response. */
	private static function log( $where, \Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'Minn Admin ' . $where . ': ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
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

	/**
	 * Whether a declared resource keeps the current user out of a whole table
	 * it reads, for a screen that shows tables raw (the database browser), so
	 * the vendor rule an adapter applies to its own list holds there too.
	 *
	 * A table a resource reads as its source, or names among the vendor's
	 * other tables holding the same data ('tables'), is refused when any
	 * resource reading it so does not show the viewer all of it: its view
	 * gate, the gate its item or any further read answers to, or its 'table'
	 * gate says no (table_gates()), its scope admits less than every row, it
	 * has a tab gate and no scope to say which tabs pass, its fixed condition
	 * cannot be read, or that condition is the viewer's own scope ('viewer')
	 * and narrows anything the adapter does not say it shows them anyway. A
	 * table a resource only joins or searches (its meta table) is refused
	 * unless some resource reading it shows the viewer all of its own table,
	 * since that one shows them these rows. A fixed condition that is not the
	 * viewer's is not asked: it is the resource's slice of the table (this
	 * site's rows, its own kind of row), not who may see the rest; nor is a
	 * row gate, which answers for one row. Core's tables are never refused
	 * here: a resource reads only its slice of them. A copy of a table (its
	 * name under another prefix or with a word before or after, as
	 * wp_amelia_appointments_old or wp_3_frm_items) answers as the table
	 * does, for names of two words or more; a one-word name (stream,
	 * redirects) is too common to find inside another. Asked whether or not
	 * the plugin is active, since its tables outlive it; a gate that cannot
	 * answer without it says no.
	 *
	 * @param string $table Table name as the database names it.
	 * @return bool
	 */
	public static function table_refused( $table ) {
		$claims = self::table_claims();
		$name   = strtolower( (string) $table );
		$found  = isset( $claims['tables'][ $name ] ) ? array( $claims['tables'][ $name ] ) : self::copy_claims( $name, $claims );
		foreach ( $found as $claim ) {
			if ( $claim['source'] ) {
				foreach ( $claim['source'] as $id ) {
					if ( ! self::admits_table( self::$resources[ $id ] ) ) {
						return true;
					}
				}
				continue;
			}
			$shown = false;
			foreach ( $claim['read'] as $id ) {
				if ( self::admits_table( self::$resources[ $id ] ) ) {
					$shown = true;
					break;
				}
			}
			if ( ! $shown ) {
				return true;
			}
		}
		return false;
	}

	/** The claims of every declared table a name holds whole, for a table no resource reads by that name. */
	private static function copy_claims( $name, array $claims ) {
		global $wpdb;
		$base = $name;
		foreach ( array_unique( array( strtolower( $wpdb->prefix ), strtolower( $wpdb->base_prefix ) ) ) as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $base, $prefix ) ) {
				$base = substr( $base, strlen( $prefix ) );
				break;
			}
		}
		$base  = preg_replace( '/^\d+_/', '', $base );
		$found = array();
		foreach ( $claims['bases'] as $declared => $full ) {
			if ( false !== strpos( $declared, '_' ) && false !== strpos( $base, $declared ) ) {
				foreach ( $full as $t ) {
					$found[] = $claims['tables'][ $t ];
				}
			}
		}
		return $found;
	}

	/** Whether a resource shows the current user every row of its source table, every column of it included. */
	private static function admits_table( array $res ) {
		$gates = (array) ( $res['gates'] ?? array() );
		foreach ( self::table_gates( $res ) as $name ) {
			if ( ! self::yes( $gates[ $name ] ?? null ) ) {
				return false;
			}
		}
		$l     = (array) ( $res['list'] ?? array() );
		$rules = self::rules( $res );
		if ( null !== $rules['allowed'] || null === $rules['fixed'] || ( ! empty( $l['tab']['gate'] ) && empty( $l['scope'] ) ) ) {
			return false;
		}
		$viewer = $res['source']['viewer'] ?? null;
		// A viewer's own scope admits someone it narrows nothing for, or
		// someone the adapter's own answer says it shows all the plugin shows
		// anyone (a scope that is never empty, as a log's own list of types).
		return empty( $viewer ) || array() === $rules['fixed'] || ( true !== $viewer && self::yes( $viewer ) );
	}

	/**
	 * The gates a reader of a resource's whole table must pass: its view
	 * gate, the gate its item and each further read answer to (a whole table
	 * holds every column those show, a message body behind a preview
	 * capability, a booking's meta behind its own context), and its own
	 * 'table' gate when it declares one, for what its rows mask past any
	 * gate (a gift card code only some roles see whole).
	 *
	 * @param array $res Resource declaration.
	 * @return string[]
	 */
	private static function table_gates( array $res ) {
		$item  = is_array( $res['item'] ?? null ) ? $res['item'] : array();
		$names = array( 'view', (string) ( $item['gate'] ?? 'view' ) );
		foreach ( (array) ( $res['reads'] ?? array() ) as $read ) {
			$names[] = (string) ( ( is_array( $read ) ? ( $read['gate'] ?? null ) : null ) ?? ( $item['gate'] ?? 'view' ) );
		}
		if ( array_key_exists( 'table', (array) ( $res['gates'] ?? array() ) ) ) {
			$names[] = 'table';
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Every table a declared resource reads, lowercased, with the resources
	 * reading it as their source and those only joining or searching it, and
	 * each declared name without its prefix, for its copies.
	 *
	 * @return array{tables: array<string, array{source: string[], read: string[]}>, bases: array<string, string[]>}
	 */
	private static function table_claims() {
		global $wpdb;
		static $memo = array();
		self::resources();
		$key = $wpdb->prefix . '|' . $wpdb->base_prefix . '|' . count( self::$resources );
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		// Core's own tables from wpdb's class defaults: plugins append their
		// tables to the live lists ($wpdb->tables), which would count them as
		// core and leave them to nobody's rule.
		$core     = array( strtolower( $wpdb->users ), strtolower( $wpdb->usermeta ) );
		$defaults = get_class_vars( 'wpdb' );
		foreach ( array_merge( (array) ( $defaults['tables'] ?? array() ), (array) ( $defaults['old_tables'] ?? array() ) ) as $t ) {
			$core[] = strtolower( $wpdb->prefix . $t );
			$core[] = strtolower( $wpdb->base_prefix . $t );
		}
		foreach ( array_merge( (array) ( $defaults['global_tables'] ?? array() ), (array) ( $defaults['ms_global_tables'] ?? array() ) ) as $t ) {
			$core[] = strtolower( $wpdb->base_prefix . $t );
		}
		$out  = array( 'tables' => array(), 'bases' => array() );
		$add  = function ( $spec, $id, $as ) use ( &$out, $core, $wpdb ) {
			try {
				$name = strtolower( Minn_Admin_Kernel_SQL::table( $spec ) );
			} catch ( \Throwable $e ) {
				return;
			}
			if ( in_array( $name, $core, true ) ) {
				return;
			}
			if ( ! isset( $out['tables'][ $name ] ) ) {
				$out['tables'][ $name ] = array( 'source' => array(), 'read' => array() );
				$base                   = $name;
				foreach ( array_unique( array( strtolower( $wpdb->prefix ), strtolower( $wpdb->base_prefix ) ) ) as $prefix ) {
					if ( '' !== $prefix && 0 === strpos( $base, $prefix ) ) {
						$base = substr( $base, strlen( $prefix ) );
						break;
					}
				}
				$out['bases'][ $base ][] = $name;
			}
			$out['tables'][ $name ][ $as ][] = $id;
		};
		foreach ( self::$resources as $id => $res ) {
			$src = (array) ( $res['source'] ?? array() );
			if ( ! isset( $src['table'] ) ) {
				continue;
			}
			$add( array( 'table' => $src['table'], 'network' => ! empty( $src['network'] ) ), $id, 'source' );
			// The vendor's other tables holding this data take the source's answer.
			foreach ( (array) ( $src['tables'] ?? array() ) as $t ) {
				$add( is_array( $t ) ? $t : array( 'table' => $t, 'network' => ! empty( $src['network'] ) ), $id, 'source' );
			}
			foreach ( (array) ( $src['joins'] ?? array() ) as $j ) {
				if ( is_array( $j ) && empty( $j['core'] ) && isset( $j['table'] ) ) {
					$add( $j, $id, 'read' );
				}
			}
			$meta = $res['list']['search']['meta'] ?? null;
			if ( is_array( $meta ) && isset( $meta['table'] ) ) {
				$add( array( 'table' => $meta['table'], 'network' => ! empty( $meta['network'] ) ), $id, 'read' );
			}
		}
		$memo[ $key ] = $out;
		return $out;
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
		try {
			if ( 'resource' === $kind ) {
				self::register_resource( $def );
			} else {
				self::register_endpoint( $def );
			}
		} catch ( \Throwable $e ) {
			self::log( (string) ( $def['id'] ?? $def['path'] ?? $key ), $e );
			self::wrong( 'minn_admin_' . $kind, ( $def['id'] ?? $def['path'] ?? $key ) . ' threw while its routes registered.' );
		}
	}

	/**
	 * Why a declaration cannot be read whole, or '' when it can: the first
	 * key it holds that its place does not take, a field of a type the
	 * kernel cannot encode, a scope without its column and function.
	 *
	 * @param array  $def  Declaration.
	 * @param string $kind resource | endpoint.
	 * @return string
	 */
	private static function unreadable( array $def, $kind ) {
		$at = function ( $where, $arr, $place ) {
			if ( null !== $arr && ! is_array( $arr ) ) {
				return "{$where} is not a list of keys";
			}
			foreach ( is_array( $arr ) ? array_keys( $arr ) : array() as $k ) {
				if ( ! in_array( $k, self::KEYS[ $place ], true ) ) {
					return "{$where} holds \"{$k}\", which the kernel does not read";
				}
			}
			return '';
		};
		$fields = function ( $where, $f ) use ( $at ) {
			if ( null === $f ) {
				return '';
			}
			if ( ! is_array( $f ) ) {
				return "{$where} is not a list of fields";
			}
			foreach ( $f as $name => $spec ) {
				$type = is_array( $spec ) ? ( $spec['type'] ?? '' ) : $spec;
				if ( ! is_string( $type ) || ! in_array( $type, Minn_Admin_Kernel_Fields::TYPES, true ) ) {
					return "{$where}.{$name} has no type the kernel encodes";
				}
				$why = is_array( $spec ) ? $at( "{$where}.{$name}", $spec, 'field' ) : '';
				if ( '' !== $why ) {
					return $why;
				}
			}
			return '';
		};
		if ( 'endpoint' === $kind ) {
			return $at( 'the endpoint', $def, 'endpoint' );
		}
		$l      = is_array( $def['list'] ?? null ) ? $def['list'] : array();
		$checks = array(
			array( 'the resource', $def, 'resource' ),
			array( 'source', $def['source'] ?? null, 'source' ),
			array( 'list', $def['list'] ?? null, 'list' ),
			array( 'item', $def['item'] ?? null, 'item' ),
			array( 'create', $def['create'] ?? null, 'create' ),
		);
		foreach ( array( 'filter', 'tab', 'scope', 'search', 'range', 'order', 'sort' ) as $k ) {
			$checks[] = array( "list.{$k}", $l[ $k ] ?? null, $k );
		}
		$src      = is_array( $def['source'] ?? null ) ? $def['source'] : array();
		$checks[] = array( 'list.search.meta', is_array( $l['search'] ?? null ) ? ( $l['search']['meta'] ?? null ) : null, 'meta' );
		foreach ( array( 'gates' => $def['gates'] ?? null, 'labels' => $def['labels'] ?? null, 'reads' => $def['reads'] ?? null, 'actions' => $def['actions'] ?? null, 'list.facets' => $l['facets'] ?? null, 'source.joins' => $src['joins'] ?? null ) as $where => $v ) {
			if ( null !== $v && ! is_array( $v ) ) {
				return "{$where} is not a list";
			}
		}
		foreach ( (array) ( $l['facets'] ?? array() ) as $i => $f ) {
			$checks[] = array( "list.facets[{$i}]", $f, 'facet' );
		}
		foreach ( (array) ( $src['joins'] ?? array() ) as $alias => $j ) {
			$checks[] = array( "source.joins.{$alias}", $j, 'join' );
		}
		foreach ( (array) ( $def['reads'] ?? array() ) as $slug => $read ) {
			$checks[] = array( "reads.{$slug}", $read, 'read' );
		}
		foreach ( (array) ( $def['actions'] ?? array() ) as $slug => $a ) {
			$checks[] = array( "actions.{$slug}", $a, 'action' );
		}
		foreach ( $checks as $c ) {
			$why = $at( $c[0], $c[1], $c[2] );
			if ( '' !== $why ) {
				return $why;
			}
		}
		$why = $fields( 'list.fields', $l['fields'] ?? null );
		$why = '' !== $why ? $why : $fields( 'item.fields', $def['item']['fields'] ?? null );
		foreach ( (array) ( $def['reads'] ?? array() ) as $slug => $read ) {
			$why = '' !== $why ? $why : $fields( "reads.{$slug}.fields", $read['fields'] ?? null );
		}
		if ( '' === $why && isset( $l['scope'] ) && ( empty( $l['scope']['column'] ) || ! is_string( $l['scope']['column'] ) || ! is_callable( $l['scope']['allowed'] ?? null ) ) ) {
			// A scope that cannot be asked would read as no scope at all.
			$why = 'list.scope needs a column and an allowed function';
		}
		if ( '' === $why && isset( $l['range'] ) && ! in_array( $l['range']['clock'] ?? 'local', self::CLOCKS, true ) ) {
			$why = 'list.range names a clock the kernel does not read';
		}
		if ( '' === $why && array_key_exists( 'exists', (array) ( $def['source'] ?? array() ) ) && ! is_callable( $def['source']['exists'] ) ) {
			// One that is not callable would leave gone to the source's own read.
			$why = 'source.exists is not callable';
		}
		if ( '' === $why && array_key_exists( 'tables', (array) ( $def['source'] ?? array() ) ) ) {
			// A list the kernel cannot read would leave those tables to nobody's rule.
			$more = $def['source']['tables'];
			$bad  = ! is_array( $more ) || ! isset( $def['source']['table'] );
			foreach ( is_array( $more ) ? $more : array() as $t ) {
				$bad = $bad || ! ( ( is_string( $t ) && '' !== $t ) || ( is_array( $t ) && is_string( $t['table'] ?? null ) ) );
			}
			if ( $bad ) {
				$why = 'source.tables lists table names beside a source table';
			}
		}
		return $why;
	}

	/**
	 * A route as the URLs it serves: each captured part one placeholder,
	 * whatever its pattern, unless it captures one word ("(?P<tab>general)"),
	 * so "/x/(?P<id>\d+)" and "/x/(?P<id>[0-9]+)" are one route, and two
	 * patterns for one place (which WordPress would both match, serving the
	 * first) are taken for one. Lowercased: WordPress matches routes without
	 * regard to case.
	 *
	 * @param string $route Route pattern.
	 * @return string|null null when the pattern cannot be read, so it claims nothing.
	 */
	private static function shape( $route ) {
		$shape = preg_replace_callback( '/\(\?P<\w+>((?:[^()\\\\]|\\\\.|\((?1)\))*)\)/', function ( $m ) {
			return preg_match( '/^[\w-]+$/', $m[1] ) ? $m[1] : '{}';
		}, (string) $route );
		return is_string( $shape ) ? strtolower( $shape ) : null;
	}

	/**
	 * Register a route's endpoints, each method on each route once across
	 * every declaration: one claimed already, under any pattern for the same
	 * URLs (shape()), is refused, not left to WordPress, which would serve
	 * whichever came first.
	 *
	 * @param string $route Route under the namespace, with its leading slash.
	 * @param array  $eps   Endpoint args (one, or a list of them).
	 * @param string $who   The declaration, for the notice.
	 */
	private static function route( $route, array $eps, $who ) {
		$eps  = isset( $eps['methods'] ) ? array( $eps ) : $eps;
		$keep = array();
		foreach ( $eps as $ep ) {
			$methods = array();
			foreach ( (array) $ep['methods'] as $m ) {
				foreach ( explode( ',', (string) $m ) as $one ) {
					$methods[] = strtoupper( trim( $one ) );
				}
			}
			$shape = self::shape( $route );
			if ( null === $shape ) {
				self::wrong( 'minn_admin_resource', "{$who}: {$route} is a pattern the kernel cannot read, so it has no route." );
				continue;
			}
			$taken = array();
			foreach ( $methods as $m ) {
				if ( isset( self::$claimed[ "{$m} {$shape}" ] ) ) {
					$taken[] = $m;
				}
			}
			if ( $taken ) {
				self::wrong( 'minn_admin_resource', "{$who}: " . implode( ', ', $taken ) . " {$route} is already declared by " . self::$claimed[ "{$taken[0]} {$shape}" ] . ', so it has no route.' );
				continue;
			}
			foreach ( $methods as $m ) {
				self::$claimed[ "{$m} {$shape}" ] = $who;
			}
			$keep[] = $ep;
		}
		if ( $keep ) {
			register_rest_route( self::NS, $route, $keep );
		}
	}

	/**
	 * Whether an endpoint's gate takes only what it is asked with: nothing,
	 * or the request (typed as one). A gate with any other parameter (a
	 * capability check whose user id defaults) would be handed the request
	 * in its place, and a user id read from it is user 1.
	 *
	 * @param callable $gate Gate.
	 * @return bool
	 */
	private static function takes_request( $gate ) {
		try {
			if ( is_string( $gate ) && false !== strpos( $gate, '::' ) ) {
				$gate = explode( '::', $gate, 2 );
			}
			if ( is_array( $gate ) ) {
				$rf = new ReflectionMethod( $gate[0], $gate[1] );
			} elseif ( is_object( $gate ) && ! $gate instanceof Closure ) {
				$rf = new ReflectionMethod( $gate, '__invoke' );
			} else {
				$rf = new ReflectionFunction( $gate );
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( $rf->getParameters() as $prm ) {
			$t = $prm->getType();
			if ( false === strpos( $t instanceof ReflectionNamedType ? $t->getName() : (string) $t, 'WP_REST_Request' ) ) {
				return false;
			}
		}
		return true;
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
		self::wrong( 'minn_admin_resource', "{$where} names no declared gate \"{$name}\", so it has no route." );
	}

	/**
	 * A route endpoint the kernel owns: the declaring file, the method, the
	 * gate, and the handler answering in one shape (guarded()).
	 *
	 * @param array    $res     Resource.
	 * @param string   $method  Method.
	 * @param callable $gate    Permission callback.
	 * @param callable $handler Handler, called with the request.
	 * @return array
	 */
	private static function ep( array $res, $method, callable $gate, callable $handler ) {
		return array(
			'minn_file'           => $res['file'],
			'methods'             => $method,
			'permission_callback' => $gate,
			'callback'            => self::guarded( $handler ),
		);
	}

	private static function register_resource( array $res ) {
		$base = '/' . $res['id'];
		$item = $base . '/(?P<id>\d+)';
		$view = self::gate( $res, 'view' );
		if ( ! $view ) {
			self::missing_gate( $res['id'], 'view' );
			return;
		}
		$listed = self::listed( $res );
		$create = self::create_ep( $res );
		$eps    = array();
		if ( $listed ) {
			$eps[] = self::ep( $res, 'GET', $view, function ( WP_REST_Request $r ) use ( $res ) {
				return Minn_Admin_Kernel::handle_list( $res, $r );
			} );
		}
		if ( $create ) {
			$eps[] = $create;
		}
		if ( $eps ) {
			self::route( $base, $eps, $res['id'] );
		}
		self::register_reads( $res, $item );
		// Each method on each route once: a second would be shadowed by the first.
		$taken = array( 'GET ' . $base => $listed, 'POST ' . $base => ! empty( $res['create'] ), 'GET ' . $item => ! empty( $res['item'] ) );
		foreach ( array_keys( (array) ( $res['reads'] ?? array() ) ) as $slug ) {
			$taken[ 'GET ' . $item . '/' . sanitize_key( (string) $slug ) ] = true;
		}
		$on_item   = array();
		$item_gate = (string) ( $res['item']['gate'] ?? 'view' );
		$item_perm = ! empty( $res['item'] ) ? self::gate( $res, $item_gate ) : null;
		if ( ! empty( $res['item'] ) && ! $item_perm ) {
			self::missing_gate( $res['id'] . ' item', $item_gate );
		}
		if ( $item_perm ) {
			$on_item[] = self::ep( $res, 'GET', $item_perm, function ( WP_REST_Request $r ) use ( $res ) {
				return Minn_Admin_Kernel::handle_item( $res, $r );
			} );
		}
		foreach ( (array) ( $res['actions'] ?? array() ) as $slug => $action ) {
			$ep = self::action_ep( $res, (string) $slug, (array) $action, $item, $taken );
			if ( $ep && self::on_item( $action ) ) {
				$on_item[] = $ep;
			} elseif ( $ep ) {
				self::route( $item . '/' . sanitize_key( (string) $slug ), $ep, $res['id'] );
			}
		}
		if ( $on_item ) {
			self::route( $item, $on_item, $res['id'] );
		}
	}

	/**
	 * Whether a resource has its list route: it declares a list, and its
	 * filter has values (built from the vendor's data, they can come out
	 * empty: then only the list route goes, and the item, reads and actions
	 * still hold the list's scope). A sort it cannot apply is dropped here,
	 * so the list keeps its declared order.
	 *
	 * @param array $res Resource, its sort removed when it cannot be applied.
	 * @return bool
	 */
	private static function listed( array &$res ) {
		$f = $res['list']['filter'] ?? array();
		if ( ! empty( $f['many'] ) && ( isset( $f['default'] ) || isset( $f['order'] ) ) ) {
			self::wrong( 'minn_admin_resource', "{$res['id']} declares a default or an order on a filter taking several values, which it never applies." );
		}
		if ( isset( $res['list']['sort'] ) ) {
			$why = self::sort_problem( $res['list']['sort'], $res );
			if ( '' !== $why ) {
				self::wrong( 'minn_admin_resource', "{$res['id']} declares a sort it cannot apply ({$why}), so its list keeps its declared order." );
				unset( $res['list']['sort'] );
			}
		}
		if ( empty( $res['list'] ) ) {
			return false;
		}
		if ( ! empty( $f ) && empty( $f['values'] ) ) {
			self::wrong( 'minn_admin_resource', "{$res['id']} declares a filter with no values, so it has no list route." );
			return false;
		}
		return true;
	}

	/**
	 * A resource's create on its collection route, or null (with a notice
	 * for each reason it cannot have one).
	 *
	 * @param array $res Resource.
	 * @return array|null
	 */
	private static function create_ep( array $res ) {
		if ( empty( $res['create'] ) ) {
			return null;
		}
		$c    = $res['create'];
		$gate = (string) ( $c['gate'] ?? '' );
		$perm = '' === $gate ? null : self::gate( $res, $gate );
		if ( ! $perm || empty( $c['run'] ) ) {
			self::missing_gate( $res['id'] . ' create', $gate );
			return null;
		}
		$expect = $c['expect'] ?? 'exists';
		if ( ! empty( $res['list']['scope'] ) || ! empty( $res['item_gate'] ) ) {
			// A create cannot ask a scope or a row gate before its row exists.
			$why = 'declares a create beside a scope or a row gate';
		} elseif ( 'exists' !== $expect && ( is_string( $expect ) || ! is_callable( $expect ) ) ) {
			// A misspelt expect would answer 500 after the vendor made the row.
			$why = "create declares an expect that is neither 'exists' nor a function";
		} elseif ( empty( $res['source']['table'] ) && 'exists' === $expect ) {
			// Only a table proves a row is new (its key); any other source says how.
			$why = 'creates through a source that is not a table and declares no expect';
		} else {
			return self::ep( $res, 'POST', $perm, function ( WP_REST_Request $r ) use ( $res ) {
				return Minn_Admin_Kernel::handle_create( $res, $r );
			} );
		}
		self::wrong( 'minn_admin_resource', "{$res['id']} {$why}, so it has no route." );
		return null;
	}

	/** Each further read of an item on its own route, behind its gate (the item's when it names none). */
	private static function register_reads( array $res, $item ) {
		foreach ( (array) ( $res['reads'] ?? array() ) as $slug => $read ) {
			$gate_name = (string) ( $read['gate'] ?? ( $res['item']['gate'] ?? 'view' ) );
			$gate      = self::gate( $res, $gate_name );
			if ( ! $gate || empty( $read['view'] ) ) {
				self::missing_gate( $res['id'] . ' read ' . $slug, $gate_name );
				continue;
			}
			self::route( $item . '/' . sanitize_key( (string) $slug ), self::ep( $res, 'GET', $gate, function ( WP_REST_Request $r ) use ( $res, $read, $gate_name ) {
				$row = Minn_Admin_Kernel::load( $res, (int) Minn_Admin::path_param( $r ), $gate_name );
				$out = is_wp_error( $row ) ? $row : call_user_func( $read['view'], $row, $r );
				return is_wp_error( $out ) || empty( $read['fields'] ) ? $out : Minn_Admin_Kernel_Fields::out( $read['fields'], (array) $out );
			} ), $res['id'] );
		}
	}

	/**
	 * An action's endpoint, or null (with a notice saying why it has none):
	 * a declared gate, a post-condition, a write method, a slug that is a
	 * key, a method its route does not already answer, a source that can
	 * prove gone, and args that are schemas.
	 *
	 * @param array  $res    Resource.
	 * @param string $slug   Action slug.
	 * @param array  $action The action.
	 * @param string $item   The item route.
	 * @param array  $taken  "METHOD route" already answered, this one added when it registers.
	 * @return array|null
	 */
	private static function action_ep( array $res, $slug, array $action, $item, array &$taken ) {
		$gate_name = (string) ( $action['gate'] ?? '' );
		$gate      = '' === $gate_name ? null : self::gate( $res, $gate_name );
		if ( ! $gate ) {
			self::missing_gate( $res['id'] . ' action ' . $slug, $gate_name );
			return null;
		}
		// A write says what must hold after it runs, or it has no route:
		// without one the kernel could not tell a refusal from success.
		$expect = $action['expect'] ?? null;
		if ( ! ( 'gone' === $expect || 'ran' === $expect || ( is_array( $expect ) && $expect ) || ( ! is_string( $expect ) && is_callable( $expect ) ) ) ) {
			self::wrong( 'minn_admin_resource', "{$res['id']} action {$slug} declares no post-condition ('expect'), so it has no route." );
			return null;
		}
		$method = strtoupper( (string) ( $action['method'] ?? 'POST' ) );
		$path   = self::on_item( $action ) ? $item : $item . '/' . $slug;
		$why    = '';
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			$why = "method {$method} is not one of POST, PUT, PATCH, DELETE";
		} elseif ( '' === $slug || sanitize_key( $slug ) !== $slug ) {
			$why = 'its slug is not a key';
		} elseif ( ! empty( $taken[ $method . ' ' . $path ] ) ) {
			$why = "{$method} on its route is taken";
		} elseif ( 'gone' === $expect && empty( $res['source']['table'] ) && ! is_callable( $res['source']['exists'] ?? null ) ) {
			// A callable source's own read can fail, or hide the row from
			// this caller, and either reads as gone.
			$why = 'it proves gone, and its source is no table and declares no exists';
		} elseif ( isset( $action['args'] ) && ! self::args_ok( $action['args'], $item ) ) {
			$why = 'its args are not schemas (a type each, no callbacks, no default, a boolean required, no name the route captures)';
		}
		if ( '' !== $why ) {
			self::wrong( 'minn_admin_resource', "{$res['id']} action {$slug}: {$why}, so it has no route." );
			return null;
		}
		$taken[ $method . ' ' . $path ] = true;
		return self::ep( $res, $method, $gate, function ( WP_REST_Request $r ) use ( $res, $slug ) {
			return Minn_Admin_Kernel::handle_action( $res, $slug, $r );
		} );
	}

	private static function register_endpoint( array $ep ) {
		$gate = $ep['gate'] ?? null;
		if ( ! is_callable( $gate ) ) {
			self::wrong( 'minn_admin_endpoint', "{$ep['path']} has no gate, so it has no route." );
			return;
		}
		$why = self::unreadable( $ep, 'endpoint' );
		if ( '' === $why && ! self::takes_request( $gate ) ) {
			$why = 'its gate takes something other than the request it is asked with';
		}
		if ( '' !== $why ) {
			self::wrong( 'minn_admin_endpoint', "{$ep['path']}: {$why}, so it has no route." );
			return;
		}
		if ( isset( $ep['args'] ) && ! self::args_ok( $ep['args'], $ep['path'] ) ) {
			self::wrong( 'minn_admin_endpoint', "{$ep['path']}: its args are not schemas (a type each, no callbacks, no default, a boolean required, no name the route captures), so it has no route." );
			return;
		}
		$run = $ep['run'] ?? null;
		$args = array(
			'minn_file'           => $ep['file'],
			'methods'             => $ep['methods'] ?? 'GET',
			// Asked with the request, as WordPress asks it: a gate on one item
			// (edit this form) refuses before the handler runs, 401 signed out.
			'permission_callback' => function ( WP_REST_Request $r ) use ( $gate ) {
				return Minn_Admin_Kernel::yes( $gate, $r );
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
				$bad = Minn_Admin_Kernel::check_args( (array) ( $ep['args'] ?? array() ), $r );
				return $bad ? $bad : call_user_func( $run, $r, $ctx );
			} ),
		);
		self::route( '/' . $ep['path'], $args, $ep['path'] );
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
			if ( is_wp_error( $out ) ) {
				return Minn_Admin_Kernel::clean_error( $out );
			}
			$out = rest_ensure_response( $out );
			// A vendor's own error response (rest_do_request()'s answer, passed
			// on) keeps its shape and status, with the same scrub as an error.
			if ( $out->get_status() >= 400 ) {
				$out->set_data( self::scrub( $out->get_data() ) );
			}
			return $out;
		};
	}

	/** A handler's WP_Error as it may leave: see guarded(). */
	public static function clean_error( WP_Error $e ) {
		$data = $e->get_error_data();
		$data = is_array( $data ) ? self::scrub( $data ) : array();
		$status         = (int) ( $data['status'] ?? 0 );
		$data['status'] = ( $status >= 400 && $status <= 599 ) ? $status : 500;
		$message        = (string) $e->get_error_message();
		if ( self::leaks( $message ) ) {
			$message = __( 'That did not work. Nothing was reported as done.', 'minn-admin' );
		}
		return new WP_Error( $e->get_error_code(), $message, $data );
	}

	/**
	 * Error data as it may leave, at any depth: no key shaped like debug
	 * output, and no text that quotes a query, a database error or a server
	 * path (an empty string in its place, so the shape the client reads holds;
	 * a message the generic one).
	 *
	 * @param mixed $data Data.
	 * @param int   $depth How deep this is.
	 * @return mixed
	 */
	private static function scrub( $data, $depth = 0 ) {
		if ( is_string( $data ) ) {
			return self::leaks( $data ) ? '' : $data;
		}
		if ( is_object( $data ) ) {
			$data = (array) json_decode( (string) wp_json_encode( $data ), true );
		}
		if ( ! is_array( $data ) ) {
			return $data;
		}
		if ( $depth > 8 ) {
			return array();
		}
		foreach ( $data as $k => $v ) {
			if ( is_string( $k ) && preg_match( '/^(sql|query|queries|trace|backtrace|stack|file|line|exception|last_error|last_query|debug)$/i', $k ) ) {
				unset( $data[ $k ] );
				continue;
			}
			// A message the client shows reads as one, not as a blank.
			$data[ $k ] = 'message' === $k && is_string( $v ) && self::leaks( $v ) ? __( 'That did not work. Nothing was reported as done.', 'minn-admin' ) : self::scrub( $v, $depth + 1 );
		}
		return $data;
	}

	/**
	 * Whether text quotes SQL as a database prints it, a database's own
	 * error, or a path on this server. Keywords in capitals are SQL; in any
	 * case only shapes no sentence takes count ("SELECT *", "FROM wp_posts",
	 * "WHERE id = 5", a quoted identifier), so "could not delete the set" and
	 * "Select a state from the list" are left as they are.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function leaks( $text ) {
		global $wpdb;
		$text   = (string) $text;
		$prefix = preg_quote( (string) $wpdb->base_prefix, '/' );
		$sql    = array(
			'/\b(?:SELECT\b[\s\S]{0,400}?\bFROM\s+[`\w]|INSERT\s+INTO\s+[`\w]|UPDATE\s+[`\w.]+\s+SET\s|DELETE\s+FROM\s+[`\w]|REPLACE\s+INTO\s+[`\w])/',
			'/\bselect\s+(?:\*|count\s*\(|distinct\s)/i',
			// A table on this database's prefix, never a function call (wp_mail()).
			'' === $prefix ? '' : '/\b(?:from|into|update|join|table)\s+`?' . $prefix . '\w++(?!\(\s*\))/i',
			'/\bwhere\s+`?[\w.]+`?\s*(?:=|<>|!=|<=|>=|\bin\s*\(|\blike\s|\bis\s+(?:not\s+)?null\b)/i',
			'/`\w+`\s*\.\s*`\w+`/',
			// The database's own words, in the shapes it prints them.
			'/You have an error in your SQL syntax|WordPress database error|Unknown column|Table \S+ doesn\'t exist|Duplicate entry \'|Data too long for column|Data truncated for column|Incorrect \w+ value: \'|Out of range value|doesn\'t have a default value|Column \'[^\']*\' cannot be null|foreign key constraint|Deadlock found|Lock wait timeout|server has gone away|Lost connection to (?:MySQL |MariaDB )?server|Can\'t connect to (?:local )?(?:MySQL |MariaDB )?server|Unknown database \'|command denied to user \'|Access denied for user \'|SQLSTATE\[/i',
			// A file on a server: a script by its extension, anything under a
			// server's own roots (a URL's path, "/blog/2020/old-post", is no file).
			'#(?:^|[\s(\'"=:])(?:[A-Za-z]:)?(?:[\\\\/][\w.@~-]+){2,}\.(?:php|inc|phtml)\b#',
			'#(?:^|[\s(\'"=:\[])(?:/(?:home\d*|var|srv|usr|opt|tmp|etc|root|run|dev|Users|Library|private|Volumes|mnt|data|www|app|nas|storage|hermes|chroot|kunden|homepages|customers|clients)/[\w.@~-]|[A-Za-z]:\\\\[\w.@~-]+\\\\)#',
		);
		foreach ( $sql as $re ) {
			if ( '' !== $re && preg_match( $re, $text ) ) {
				return true;
			}
		}
		$dirs = array( ABSPATH, WP_CONTENT_DIR );
		foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $d ) {
			$dirs[] = wp_normalize_path( $d );
			$real   = realpath( $d ); // A symlinked install prints its real path.
			if ( is_string( $real ) ) {
				$dirs[] = $real;
			}
		}
		foreach ( array_unique( $dirs ) as $dir ) {
			$dir = rtrim( (string) $dir, '/\\' );
			if ( strlen( $dir ) > 1 && false !== strpos( $text, $dir ) ) {
				return true;
			}
		}
		return false;
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
		$id    = (int) $id;
		$rules = self::rules( $res );
		$row   = $id > 0 && null !== $rules['fixed'] ? self::fetch( $res, $id, $rules['fixed'] ) : null;
		if ( ! $row ) {
			return self::fail( 'not_found', self::label( $res, 'not_found', __( 'Not found.', 'minn-admin' ) ), 404 );
		}
		$forbidden = self::fail( 'forbidden', self::label( $res, 'forbidden', __( 'You cannot access that item.', 'minn-admin' ) ), 403 );
		if ( ! empty( $res['item_gate'] ) && ! self::yes( $res['item_gate'], $id, $gate, $row ) ) {
			return $forbidden;
		}
		// The list's scope holds for the row too, so a resource whose row
		// gate is missing or narrower than its scope cannot be read by id.
		// The fixed conditions held when the row was read.
		if ( ! self::kept( array( 'fixed' => array() ) + $rules, $row, array_key_exists( '_minn_scope', $row ) ? '_minn_scope' : null ) ) {
			return $forbidden;
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

	/**
	 * The one rule every reader holds a resource's rows to: the source's
	 * fixed conditions and the list's scope. The list, an item and its
	 * actions, a callable source's rows, the status card readers, a tab list
	 * and a reader of the whole table all ask it, so none can hold less.
	 *
	 * @param array $res Resource.
	 * @return array{where: array, fixed: ?array, allowed: ?int[], none: bool}
	 *               where: both as conditions, each keyed apart so neither
	 *               replaces a rule on the same column; fixed: null when its
	 *               function cannot answer; allowed: null for no scope; none:
	 *               no row can meet them.
	 */
	private static function rules( array $res ) {
		$fixed   = self::fixed( $res['source'] ?? array() );
		$allowed = self::scope( $res );
		$where   = Minn_Admin_Kernel_SQL::tag( (array) $fixed, 'fixed' );
		if ( is_array( $allowed ) ) {
			$where[ $res['list']['scope']['column'] . "\0scope" ] = $allowed;
		}
		return array(
			'where'   => $where,
			'fixed'   => $fixed,
			'allowed' => $allowed,
			'column'  => (string) ( $res['list']['scope']['column'] ?? '' ),
			'none'    => null === $fixed || array() === $allowed,
		);
	}

	/**
	 * Whether a row read outside SQL (a callable source's) meets the rules.
	 * A row without the scope's column cannot show that it belongs.
	 *
	 * @param array       $rules rules().
	 * @param array       $row   Row.
	 * @param string|null $col   The scope's column in the row, when not its declared name.
	 * @return bool
	 */
	private static function kept( array $rules, array $row, $col = null ) {
		if ( null === $rules['fixed'] ) {
			return false;
		}
		try {
			if ( $rules['fixed'] && ! Minn_Admin_Kernel_SQL::matches( $row, $rules['fixed'] ) ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( ! is_array( $rules['allowed'] ) ) {
			return true;
		}
		$col = $col ?? self::bare( $rules['column'] );
		return '' !== $col && array_key_exists( $col, $row ) && in_array( (int) $row[ $col ], $rules['allowed'], true );
	}

	/**
	 * One row from the source under its fixed conditions, or null.
	 *
	 * @param array $res   Resource.
	 * @param int   $id    Row id.
	 * @param array $fixed The source's fixed conditions (rules()).
	 * @return array|null
	 */
	private static function fetch( array $res, $id, array $fixed ) {
		$src = $res['source'] ?? array();
		if ( ! empty( $src['get'] ) ) {
			$row = call_user_func( $src['get'], (int) $id );
			// The source's fixed conditions hold for a row read by id as they
			// do for the list, which reads them in list_where().
			return is_array( $row ) && self::kept( array( 'fixed' => $fixed, 'allowed' => null ), $row ) ? $row : null;
		}
		if ( empty( $src['table'] ) || ! Minn_Admin_Kernel_SQL::exists( self::table_of( $src ) ) ) {
			return null;
		}
		$cols = self::cols( $src['detail'] ?? $src['columns'] ?? array() );
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
		return array( 'table' => is_array( $src['table'] ) ? $src['table'] : (string) $src['table'], 'network' => ! empty( $src['network'] ), 'joins' => (array) ( $src['joins'] ?? array() ) );
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
	 * The list's request, read once: page, size, filter, tab, search, range,
	 * all under the resource's rules.
	 *
	 * @param array           $res   Resource.
	 * @param WP_REST_Request $r     Request.
	 * @param array           $rules rules().
	 * @return array|WP_Error
	 */
	private static function list_query( array $res, WP_REST_Request $r, array $rules ) {
		$l = $res['list'];
		$q = array(
			'per_page' => min( 100, max( 1, (int) ( $r['per_page'] ?: (int) ( $l['per_page'] ?? 25 ) ) ) ),
			// Capped so the offset can never overflow into a negative LIMIT.
			'page'     => min( 100000, max( 1, (int) ( $r['page'] ?: 1 ) ) ),
			'where'    => array(),
			'filter'   => null,
			'tab'      => 0,
			'search'   => '',
			'request'  => $r,
		);
		if ( ! empty( $l['filter'] ) ) {
			$q = empty( $l['filter']['many'] ) ? self::one( $l['filter'], $r, $q ) : self::many( $l['filter'], $r, $q );
		}
		if ( is_array( $rules['allowed'] ) ) {
			$q['allowed'] = $rules['allowed'];
		}
		if ( ! empty( $l['tab'] ) ) {
			$q = self::tab( $res, $r, $q );
			if ( is_wp_error( $q ) ) {
				return $q;
			}
		}
		$sort = empty( $l['sort'] ) ? null : self::picked( $res, $r );
		if ( $sort ) {
			$q['sort'] = $sort;
		}
		$q = self::facets( (array) ( $l['facets'] ?? array() ), $r, $q );
		if ( ! empty( $l['search'] ) ) {
			$q['search'] = self::param( $r, $l['search']['param'] ?? 'search' );
		}
		// What every read of the source meets (a site column, the scope),
		// first; an array source is held to it in PHP, a callable one reads
		// it in list_where().
		$q['none']  = ! empty( $q['none'] ) || $rules['none'];
		// The rules' keys win: a list-built key of the same name is kept
		// under its own tag, never in their place.
		$where = $rules['where'];
		foreach ( $q['where'] as $k => $rule ) {
			$where[ array_key_exists( $k, $where ) ? $k . "\0list" : $k ] = $rule;
		}
		$q['where'] = $where;
		return $q;
	}

	/**
	 * A filter that takes one value: the one asked for when it is declared,
	 * its default otherwise; a mapped value's conditions (or a function's
	 * answer, read now) or the column's equality, and its own order.
	 *
	 * @param array           $f The filter declaration.
	 * @param WP_REST_Request $r Request.
	 * @param array           $q The list query so far.
	 * @return array
	 */
	private static function one( array $f, WP_REST_Request $r, array $q ) {
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
		if ( ! $mapped ) {
			$q['where'][ $f['column'] ] = $v;
			return $q;
		}
		$conds = is_callable( $values[ $v ] ) && ! is_array( $values[ $v ] ) ? call_user_func( $values[ $v ] ) : $values[ $v ];
		if ( ! is_array( $conds ) ) {
			$q['none'] = true; // A view that cannot say what it holds shows nothing.
		}
		// Under their own names, as a callable source reads them
		// ($q['where']['post_status']); every other rule is tagged.
		$q['where'] = array_merge( $q['where'], (array) $conds );
		return $q;
	}

	/**
	 * The tab asked for, as an integer: refused when its gate says no, or
	 * when it is outside a scope that is not empty (an empty scope answers
	 * an empty list whatever is asked).
	 *
	 * @param array           $res Resource.
	 * @param WP_REST_Request $r   Request.
	 * @param array           $q   The list query so far.
	 * @return array|WP_Error
	 */
	private static function tab( array $res, WP_REST_Request $r, array $q ) {
		$t   = $res['list']['tab'];
		$tab = (int) self::param( $r, $t['param'] );
		if ( ! $tab ) {
			return $q;
		}
		if ( ! empty( $t['gate'] ) && ! self::yes( $t['gate'], $tab ) ) {
			return self::fail( 'forbidden', $t['refuse'] ?? self::label( $res, 'forbidden', __( 'You cannot access that item.', 'minn-admin' ) ), 403 );
		}
		if ( ! empty( $q['allowed'] ) && ! in_array( $tab, $q['allowed'], true ) ) {
			return self::fail( 'forbidden', $res['list']['scope']['refuse'] ?? self::label( $res, 'forbidden', __( 'You cannot access that item.', 'minn-admin' ) ), 403 );
		}
		$q['tab']                               = $tab;
		$q['where'][ $t['column'] . "\0tab" ] = $tab;
		return $q;
	}

	/**
	 * The sort a person picked, among the declared columns only: by, column
	 * and dir, or null for the declared order.
	 *
	 * @param array           $res Resource.
	 * @param WP_REST_Request $r   Request.
	 * @return array|null
	 */
	private static function picked( array $res, WP_REST_Request $r ) {
		$map = '' === self::sort_problem( $res['list']['sort'], $res ) ? self::sort_map( $res['list']['sort'] ) : null;
		$by  = self::param( $r, 'orderby' );
		if ( ! $map || '' === $by || ! isset( $map[ $by ] ) ) {
			return null;
		}
		return array(
			'by'     => $by,
			'column' => $map[ $by ],
			'dir'    => 'asc' === strtolower( self::param( $r, 'order' ) ) ? 'ASC' : 'DESC',
		);
	}

	/**
	 * The facets asked for, each narrowing by its conditions; an absent or
	 * unknown value narrows nothing.
	 *
	 * @param array           $facets The list's facets.
	 * @param WP_REST_Request $r      Request.
	 * @param array           $q      The list query so far.
	 * @return array
	 */
	private static function facets( array $facets, WP_REST_Request $r, array $q ) {
		$q['facets'] = array();
		foreach ( $facets as $i => $f ) {
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
			if ( is_array( $conds ) ) {
				$q['facets'][ (string) $f['param'] ] = $v;
				$q['where']                         += Minn_Admin_Kernel_SQL::tag( $conds, "facet{$i}" );
			}
		}
		return $q;
	}

	/**
	 * A filter that takes several values: each one named is a group of
	 * conditions, and a row in any group is listed ('#any'); none named, or
	 * 'any', is every declared value, so a row in none of them never lists.
	 * An unknown name narrows to nothing rather than widening, so asking only
	 * for unknown ones lists nothing, whatever the source; a value whose
	 * conditions cannot be said drops out.
	 *
	 * @param array           $f The filter declaration.
	 * @param WP_REST_Request $r Request.
	 * @param array           $q The list query so far.
	 * @return array
	 */
	private static function many( array $f, WP_REST_Request $r, array $q ) {
		$values = (array) $f['values'];
		$mapped = array_keys( $values ) !== range( 0, count( $values ) - 1 );
		$names  = $mapped ? array_map( 'strval', array_keys( $values ) ) : array_map( 'strval', $values );
		$raw    = $r->get_param( (string) ( $f['param'] ?? 'status' ) );
		$asked  = array();
		foreach ( is_array( $raw ) ? $raw : array( $raw ) as $one ) {
			// A value that is not text names no view: it asks for nothing known.
			$one = is_scalar( $one ) || null === $one ? trim( (string) $one ) : "\0";
			if ( '' !== $one && 'any' !== $one ) {
				$asked[] = $one;
			}
		}
		$known       = array_values( array_unique( array_intersect( $asked, $names ) ) );
		$q['filter'] = $known;
		if ( $asked && ! $known ) {
			$q['none'] = true;
			return $q;
		}
		$use = $asked ? $known : $names;
		if ( ! $mapped ) {
			$q['where'][ $f['column'] ] = $use;
			return $q;
		}
		$groups = array();
		foreach ( $use as $v ) {
			$conds = is_callable( $values[ $v ] ) && ! is_array( $values[ $v ] ) ? call_user_func( $values[ $v ] ) : $values[ $v ];
			if ( is_array( $conds ) ) {
				$groups[] = $conds;
			}
		}
		if ( ! $groups ) {
			$q['none'] = true;
		}
		$q['where'][ "#any\0filter" ] = $groups;
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
		return Minn_Admin_Kernel_SQL::where( (array) ( $q['where'] ?? array() ), $alias );
	}

	public static function handle_list( array $res, WP_REST_Request $r ) {
		$l     = $res['list'];
		$rules = self::rules( $res );
		$q     = self::list_query( $res, $r, $rules );
		if ( is_wp_error( $q ) ) {
			return $q;
		}
		$src = $res['source'];
		if ( $q['none'] || ( ! empty( $src['table'] ) && ! Minn_Admin_Kernel_SQL::exists( self::table_of( $src ) ) ) ) {
			return array( 'items' => array(), 'total' => 0 );
		}
		if ( ! empty( $src['rows'] ) ) {
			list( $rows, $total ) = self::array_page( $l, $q, (array) call_user_func( $src['rows'] ) );
		} elseif ( ! empty( $src['list'] ) ) {
			list( $rows, $total ) = self::callable_page( $src, $q, $rules );
		} else {
			list( $rows, $total ) = self::table_page( $res, $q, $r );
		}
		return array( 'items' => self::items( $l, (array) $rows, $q ), 'total' => (int) $total );
	}

	/**
	 * A page from a callable source. It reads the rules in list_where() and
	 * is handed the scope in $q['allowed']; its rows are held to them here
	 * as well, and a source that let one through cannot be trusted for the
	 * total either: count only what is shown.
	 *
	 * @return array [ rows, total ]
	 */
	private static function callable_page( array $src, array $q, array $rules ) {
		list( $rows, $total ) = call_user_func( $src['list'], $q );
		$kept                 = array();
		foreach ( (array) $rows as $row ) {
			if ( self::kept( $rules, (array) $row ) ) {
				$kept[] = $row;
			}
		}
		return array( $kept, count( $kept ) !== count( (array) $rows ) ? count( $kept ) : $total );
	}

	/**
	 * A page from a table source: the list's conditions, search and day
	 * range in SQL, its order (or the person's sort) with the key breaking ties.
	 *
	 * @return array [ rows, total ]
	 */
	private static function table_page( array $res, array $q, WP_REST_Request $r ) {
		$l                  = $res['list'];
		$src                = $res['source'];
		list( $sql, $args ) = Minn_Admin_Kernel_SQL::where( $q['where'], 'e' );
		if ( '' !== $q['search'] ) {
			list( $s, $a ) = self::search_sql( $l['search'], $src, $q['search'] );
			$sql[]         = $s;
			$args          = array_merge( $args, $a );
		}
		if ( ! empty( $l['range'] ) ) {
			list( $rs, $ra ) = self::range_sql( $r, $l['range']['column'], (string) ( $l['range']['clock'] ?? 'local' ) );
			$sql             = array_merge( $sql, $rs );
			$args            = array_merge( $args, $ra );
		}
		return Minn_Admin_Kernel_SQL::page( array(
			'table'   => self::table_of( $src ),
			'key'     => $src['key'],
			'columns' => self::cols( $src['columns'] ),
			'sql'     => $sql,
			'args'    => $args,
			'order'   => $q['sort']['column'] ?? ( $l['order']['column'] ?? $src['key'] ),
			'dir'     => $q['sort']['dir'] ?? ( $q['dir'] ?? ( $l['order']['dir'] ?? 'DESC' ) ),
			'limit'   => $q['per_page'],
			'offset'  => ( $q['page'] - 1 ) * $q['per_page'],
		) );
	}

	/**
	 * A page's rows as they leave: the batch hook once, each row built, then
	 * only the declared fields, encoded.
	 *
	 * @return array[]
	 */
	private static function items( array $l, array $rows, array $q ) {
		$items = array();
		if ( ! empty( $l['batch'] ) ) {
			$q['batch'] = call_user_func( $l['batch'], array_map( function ( $row ) {
				return (array) $row;
			}, $rows ) );
		}
		$built = array();
		foreach ( $rows as $row ) {
			$built[] = (array) ( empty( $l['row'] ) ? (array) $row : call_user_func( $l['row'], (array) $row, $q ) );
		}
		// The database's clock is read once for the page, not once per value.
		$db = array();
		foreach ( (array) ( $l['fields'] ?? array() ) as $name => $spec ) {
			$spec = Minn_Admin_Kernel_Fields::spec( $spec );
			if ( 'datetime' === $spec['type'] && 'db' === ( $spec['stored'] ?? '' ) ) {
				foreach ( $built as $b ) {
					if ( isset( $b[ $name ] ) && is_scalar( $b[ $name ] ) ) {
						$db[] = (string) $b[ $name ];
					}
				}
			}
		}
		if ( $db ) {
			Minn_Admin_Kernel_SQL::db_epochs( $db );
		}
		foreach ( $built as $b ) {
			$items[] = empty( $l['fields'] ) ? $b : Minn_Admin_Kernel_Fields::out( $l['fields'], $b );
		}
		return $items;
	}

	/**
	 * A clicked day's bounds (site-local) as SQL on a column's clock. The
	 * database's own clock compares with what it read at each bound's
	 * instant; a bound it cannot read narrows to nothing, never to all.
	 *
	 * @param WP_REST_Request $r      Request (after, before).
	 * @param string          $column Column.
	 * @param string          $clock  utc | local | epoch | local-epoch | db.
	 * @return array [ sql fragments, args ]
	 */
	private static function range_sql( WP_REST_Request $r, $column, $clock ) {
		$col = Minn_Admin_Kernel_SQL::col( $column );
		if ( 'db' !== $clock ) {
			return minn_admin_chart_range_clause( $r, $col, $clock );
		}
		$sql  = array();
		$args = array();
		foreach ( array( 'after' => '>=', 'before' => '<=' ) as $param => $op ) {
			if ( '' === minn_admin_chart_bound( $r->get_param( $param ) ) ) {
				continue;
			}
			$stamp = minn_admin_chart_bound_epoch( $r->get_param( $param ), 'epoch' );
			$at    = null === $stamp ? null : Minn_Admin_Kernel_SQL::db_at( $stamp );
			if ( null === $at ) {
				$sql[] = '1 = 0';
				continue;
			}
			$sql[]  = "{$col} {$op} %s";
			$args[] = $at;
		}
		return array( $sql, $args );
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
		$from  = empty( $l['range']['column'] ) ? null : minn_admin_chart_bound_epoch( self::param( $q['request'], 'after' ), 'db' === $clock ? 'epoch' : $clock );
		$to    = empty( $l['range']['column'] ) ? null : minn_admin_chart_bound_epoch( self::param( $q['request'], 'before' ), 'db' === $clock ? 'epoch' : $clock );
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
				$ts = is_numeric( $v ) ? (int) $v : ( '' === (string) $v ? false : ( 'db' === $clock ? Minn_Admin_Kernel_SQL::db_epoch( $v ) : strtotime( (string) $v ) ) );
				$ts = null === $ts ? false : $ts;
				if ( false === $ts || ( null !== $from && $ts < $from ) || ( null !== $to && $ts > $to ) ) {
					continue;
				}
			}
			$kept[] = $row;
		}
		if ( ! empty( $q['sort'] ) ) {
			$kept = self::sort_rows( $kept, self::bare( $q['sort']['column'] ), $q['sort']['dir'] );
		}
		return array( array_slice( $kept, ( $q['page'] - 1 ) * $q['per_page'], $q['per_page'] ), count( $kept ) );
	}

	/**
	 * A declared sort's columns as public name => column, or null when it
	 * names none that can be ordered by (a name that is not a key, a column
	 * that is not an identifier).
	 *
	 * @param mixed $sort The list's 'sort'.
	 * @return array|null
	 */
	private static function sort_map( $sort ) {
		if ( ! is_array( $sort ) || empty( $sort['columns'] ) || ! is_array( $sort['columns'] ) ) {
			return null;
		}
		$cols = $sort['columns'];
		$map  = array();
		foreach ( $cols as $name => $col ) {
			$name = is_int( $name ) ? $col : $name;
			if ( ! is_string( $name ) || ! is_string( $col ) || '' === $name || sanitize_key( $name ) !== $name ) {
				return null;
			}
			try {
				Minn_Admin_Kernel_SQL::col( $col );
			} catch ( InvalidArgumentException $e ) {
				return null;
			}
			$map[ $name ] = $col;
		}
		return $map;
	}

	/**
	 * Why a declared sort cannot be applied, or '' when it can: its request
	 * keys (orderby, order) must be keys no other list parameter uses, every
	 * column an identifier, and on a table each column the source's own (a
	 * declared column, the key, or a joined alias's).
	 *
	 * @param mixed $sort The list's 'sort'.
	 * @param array $res  The resource.
	 * @return string
	 */
	private static function sort_problem( $sort, array $res ) {
		$map = self::sort_map( $sort );
		if ( ! $map ) {
			return 'no columns, or one that is not an identifier';
		}
		$l     = $res['list'];
		$taken = array( $l['search']['param'] ?? 'search', $l['filter']['param'] ?? 'status', (string) ( $l['tab']['param'] ?? '' ) );
		foreach ( (array) ( $l['facets'] ?? array() ) as $f ) {
			$taken[] = (string) ( $f['param'] ?? '' );
		}
		if ( array_intersect( array( 'orderby', 'order' ), $taken ) ) {
			return 'another list parameter takes its request key';
		}
		$src = $res['source'];
		if ( ! empty( $src['table'] ) && is_array( $src['columns'] ?? null ) ) {
			$own = array_merge( array( (string) $src['key'] ), array_map( array( __CLASS__, 'bare' ), array_keys( array_filter( $src['columns'], 'is_string' ) ) ), array_map( 'strval', array_filter( $src['columns'], 'is_string' ) ) );
			foreach ( $map as $col ) {
				$alias = false === strpos( $col, '.' ) ? 'e' : strstr( $col, '.', true );
				$bare  = self::bare( $col );
				if ( 'e' === $alias ? ! in_array( $bare, $own, true ) : ! isset( $src['joins'][ $alias ] ) ) {
					return "column {$col} is not the source's";
				}
			}
		}
		return '';
	}

	/**
	 * Rows ordered by one column: numbers before text, numbers by value, text
	 * without regard to case; rows that tie keep the order they came in.
	 */
	private static function sort_rows( array $rows, $col, $dir ) {
		$keyed = array();
		foreach ( array_values( $rows ) as $i => $row ) {
			$v       = $row[ $col ] ?? '';
			$v       = is_scalar( $v ) ? trim( (string) $v ) : '';
			$num     = '' !== $v && is_numeric( $v );
			$keyed[] = array( $i, $row, $num ? 0 : 1, $num ? 0 + $v : strtolower( $v ) );
		}
		usort( $keyed, function ( $a, $b ) use ( $dir ) {
			$c = $a[2] <=> $b[2];
			if ( 0 === $c ) {
				$c = 0 === $a[2] ? $a[3] <=> $b[3] : strnatcmp( $a[3], $b[3] );
			}
			$c = 'ASC' === $dir ? $c : -$c;
			return 0 !== $c ? $c : $a[0] <=> $b[0];
		} );
		return array_column( $keyed, 1 );
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

	public static function handle_create( array $res, WP_REST_Request $r ) {
		$c   = $res['create'];
		$src = $res['source'];
		// A table proves the row is new by its key: above every key before.
		$before = empty( $src['table'] ) ? null : (int) Minn_Admin_Kernel_SQL::latest( array( 'table' => is_array( $src['table'] ) ? $src['table'] : (string) $src['table'], 'network' => ! empty( $src['network'] ) ), $src['key'], array(), $src['key'] );
		$ran    = call_user_func( $c['run'], $r );
		if ( $ran instanceof WP_REST_Response ) {
			$ran = $ran->is_error() ? $ran->as_error() : $ran->get_data();
		}
		if ( is_wp_error( $ran ) ) {
			return $ran;
		}
		$id     = self::created_id( is_array( $ran ) ? ( $ran['id'] ?? null ) : $ran );
		$row    = $id > 0 && ( null === $before || $id > $before ) ? self::load( $res, $id, (string) $c['gate'] ) : null;
		$fresh  = is_array( $row ) ? $row : null;
		$expect = $c['expect'] ?? 'exists';
		// Done only once the new row reads back for this caller, or what the
		// declaration says must hold does.
		$held = null !== $fresh && ( 'exists' === $expect || ( ! is_string( $expect ) && is_callable( $expect ) && self::yes( $expect, $id, $fresh, $ran ) ) );
		if ( ! $held ) {
			return self::fail( (string) ( $c['fail_code'] ?? 'not_applied' ), $c['fail'] ?? __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), 500 );
		}
		$reply = empty( $c['reply'] ) ? array() : (array) call_user_func( $c['reply'], $id, $fresh, $ran );
		return array_merge( $reply, array( 'ok' => true, 'id' => $id ) );
	}

	public static function handle_action( array $res, $slug, WP_REST_Request $r ) {
		$a   = $res['actions'][ $slug ];
		$id  = (int) Minn_Admin::path_param( $r );
		$row = self::load( $res, $id, (string) $a['gate'] );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$bad = self::check_args( (array) ( $a['args'] ?? array() ), $r );
		if ( $bad ) {
			return $bad;
		}
		if ( ! self::holds( $a['when'] ?? null, $row ) ) {
			return self::fail( (string) ( $a['code'] ?? 'bad_status' ), $a['refuse'] ?? __( 'That cannot be done to this item now.', 'minn-admin' ), 400 );
		}
		$expect = $a['expect'] ?? null;
		if ( 'ran' === $expect ) {
			return self::send( $a, $id, $row, $r );
		}
		$ran = call_user_func( $a['run'], $id, $row, $r );
		if ( is_wp_error( $ran ) ) {
			return $ran;
		}
		$fixed = self::fixed( $res['source'] ?? array() );
		$fresh = null === $fixed ? null : self::fetch( $res, $id, $fixed );
		if ( 'gone' === $expect ) {
			$held = self::gone( $res, $id, $fixed, $fresh );
		} elseif ( is_array( $expect ) ) {
			$held = is_array( $fresh ) && self::holds( $expect, $fresh );
		} else {
			$held = self::yes( $expect, $id, $fresh, $ran );
		}
		if ( ! $held ) {
			return self::fail( (string) ( $a['fail_code'] ?? 'not_applied' ), $a['fail'] ?? __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), 500 );
		}
		return self::reply( $a, $id, $fresh, $ran );
	}

	/**
	 * Whether a row is gone. A callable source's own read may hide a row
	 * from this caller, so gone is asked of the store itself when the source
	 * says how; a table's read that failed returns no row too, so gone there
	 * is the store answering that it holds none. A check that throws proves
	 * nothing, so the row counts as still there.
	 *
	 * @param array      $res   Resource.
	 * @param int        $id    Row id.
	 * @param array|null $fixed The source's fixed conditions.
	 * @param array|null $fresh The row read back.
	 * @return bool
	 */
	private static function gone( array $res, $id, $fixed, $fresh ) {
		$exists = $res['source']['exists'] ?? null;
		if ( is_callable( $exists ) ) {
			try {
				return false === call_user_func( $exists, $id );
			} catch ( \Throwable $e ) {
				return false;
			}
		}
		if ( ! empty( $res['source']['table'] ) ) {
			return null !== $fixed && Minn_Admin_Kernel_SQL::absent( self::table_of( $res['source'] ), $res['source']['key'], $id, $fixed );
		}
		return null === $fresh;
	}

	/**
	 * A send ('expect' => 'ran'): it leaves nothing to read back (a read
	 * that failed then would answer 500 for mail that went, and invite
	 * sending it twice), so the run's own answer is the proof: true, the
	 * addresses it sent to, or a list saying sent. A mailer may swallow its
	 * failure (WP Mail Logging's dispatcher drops wp_mail()'s answer), so
	 * WordPress's own failure action during the run fails it too.
	 *
	 * @param array           $a   The action.
	 * @param int             $id  Row id.
	 * @param array           $row The row.
	 * @param WP_REST_Request $r   Request.
	 * @return array|WP_Error
	 */
	private static function send( array $a, $id, array $row, WP_REST_Request $r ) {
		$failed = false;
		$listen = function () use ( &$failed ) {
			$failed = true;
		};
		add_action( 'wp_mail_failed', $listen );
		try {
			$ran = call_user_func( $a['run'], $id, $row, $r );
		} finally {
			remove_action( 'wp_mail_failed', $listen );
		}
		if ( is_wp_error( $ran ) ) {
			return $ran;
		}
		if ( $failed || ! ( true === $ran || ( is_string( $ran ) && self::addresses( $ran ) ) || ( is_array( $ran ) && true === ( $ran['sent'] ?? null ) ) ) ) {
			return self::fail( (string) ( $a['fail_code'] ?? 'not_sent' ), $a['fail'] ?? __( 'The mailer reported the message could not be sent.', 'minn-admin' ), 500 );
		}
		return self::reply( $a, $id, null, $ran );
	}

	/** An action's success: { ok: true }, its reply merged over it, its message. */
	private static function reply( array $a, $id, $fresh, $ran ) {
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
		$l   = $res['list'];
		$out = array( 'route' => self::NS . '/' . $id );
		if ( ! empty( $l['range'] ) ) {
			$out['dateQuery'] = 'after={from}&before={to}';
		}
		$out['pageQuery'] = 'per_page=' . (int) ( $l['per_page'] ?? 25 ) . '&page={page}';
		if ( ! empty( $l['search'] ) ) {
			$out['search'] = ( $l['search']['param'] ?? 'search' ) . '={q}';
		}
		if ( ! empty( $l['sort'] ) && '' === self::sort_problem( $l['sort'], $res ) ) {
			$out['sortQuery'] = 'orderby={by}&order={dir}';
		}
		$out['itemsKey'] = 'items';
		$out['totalKey'] = 'total';
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
			'route'  => self::action_path( $id, $slug ) . ( isset( $ui['query'] ) ? '?' . $ui['query'] : '' ),
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
	 * Whether args are what check_args() checks: name => schema arrays, each
	 * with its JSON type, no callback of any kind (which WordPress would run before the gate), no
	 * 'default' (the kernel applies none, so one would only read as applied),
	 * 'required' a boolean when given, and no name the route itself captures
	 * (id, or a (?P<file>) of its path): the sanitized value is written back
	 * through set_param(), which writes every source holding the name, the
	 * URL's included, so a body could otherwise replace the path's value.
	 *
	 * @param mixed  $args Declared args.
	 * @param string $path The route's pattern.
	 * @return bool
	 */
	private static function args_ok( $args, $path ) {
		if ( ! is_array( $args ) ) {
			return false;
		}
		preg_match_all( '/\(\?P<([A-Za-z0-9_]+)>/', (string) $path, $m );
		$captured = array_merge( array( 'id' ), $m[1] );
		foreach ( $args as $name => $schema ) {
			if ( ! is_string( $name ) || in_array( $name, $captured, true ) || ! is_array( $schema ) ) {
				return false;
			}
			foreach ( array( 'validate_callback', 'sanitize_callback', 'arg_options', 'default' ) as $k ) {
				if ( array_key_exists( $k, $schema ) ) {
					return false;
				}
			}
			if ( array_key_exists( 'required', $schema ) && ! is_bool( $schema['required'] ) ) {
				return false;
			}
			// Without a type, rest_validate_value_from_schema() checks nothing.
			$types = array( 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' );
			$type  = $schema['type'] ?? null;
			if ( ! ( is_string( $type ) ? in_array( $type, $types, true ) : ( is_array( $type ) && $type && ! array_diff( $type, $types ) ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Declared args checked as JSON schemas, after the gate (and an item's
	 * row) and before the run, the value the run reads set to its sanitized
	 * form: the first refusal as a WP_Error, or null when every one holds.
	 *
	 * @param array           $args Name => schema ('required' for a must).
	 * @param WP_REST_Request $r    Request.
	 * @return WP_Error|null
	 */
	public static function check_args( array $args, WP_REST_Request $r ) {
		foreach ( $args as $name => $schema ) {
			$required = true === ( $schema['required'] ?? null );
			unset( $schema['required'] );
			// A null is no value, as WordPress reads one: missing when it
			// must be sent, unchecked when it need not be.
			if ( ! $r->has_param( $name ) || null === $r->get_param( $name ) ) {
				if ( $required ) {
					/* translators: %s: the parameter's name. */
					return new WP_Error( 'rest_missing_callback_param', sprintf( __( 'Missing parameter: %s', 'minn-admin' ), $name ), array( 'status' => 400, 'params' => array( $name ) ) );
				}
				continue;
			}
			$valid = rest_validate_value_from_schema( $r->get_param( $name ), $schema, $name );
			if ( is_wp_error( $valid ) ) {
				/* translators: %s: the parameter's name. */
				return new WP_Error( 'rest_invalid_param', sprintf( __( 'Invalid parameter: %s', 'minn-admin' ), $name ), array( 'status' => 400, 'params' => array( $name => $valid->get_error_message() ) ) );
			}
			$r->set_param( $name, rest_sanitize_value_from_schema( $r->get_param( $name ), $schema, $name ) );
		}
		return null;
	}

	/**
	 * Whether text is only addresses, comma-separated, each bare or as
	 * "Name <address>": whom a send went to, not a mailer's error that
	 * happens to quote one.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function addresses( $text ) {
		// A quoted name and an <address> come out whole first: a comma in
		// either ("Smith, Dana" <dana@example.com>) separates nothing.
		$found = array();
		$rest  = preg_replace( '/"[^"]*"/', ' ', (string) $text );
		$rest  = preg_replace_callback( '/<([^<>]*)>/', function ( $m ) use ( &$found ) {
			$found[] = trim( $m[1] );
			return ' ';
		}, $rest );
		$named = (bool) $found;
		foreach ( explode( ',', $rest ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( false !== strpos( $part, '@' ) && ! preg_match( '/\s/', $part ) ) {
				$found[] = $part; // A bare address.
			} elseif ( ! $named || false !== strpos( $part, ':' ) ) {
				// Words with no address beside them, or a mailer's reply around
				// one ("550 5.1.1 <a@b.c>: Recipient address rejected"): not whom it went to.
				return false;
			}
		}
		foreach ( $found as $a ) {
			// is_email(), as WordPress and WooCommerce hold a recipient to: a
			// send to an address they drop never went.
			if ( ! is_email( $a ) ) {
				return false;
			}
		}
		return (bool) $found;
	}

	/** A create's answer as an id: a positive int, or a string of digits; anything else is 0. */
	private static function created_id( $v ) {
		if ( is_int( $v ) ) {
			return max( 0, $v );
		}
		return is_string( $v ) && '' !== $v && ctype_digit( $v ) ? (int) $v : 0;
	}

	/** Whether an action answers on the item route itself: DELETE, PUT, PATCH, or a POST that says 'item'. */
	private static function on_item( array $a ) {
		return in_array( strtoupper( (string) ( $a['method'] ?? 'POST' ) ), array( 'DELETE', 'PUT', 'PATCH' ), true ) || ! empty( $a['item'] );
	}

	/**
	 * The route a declared action answers on, as a descriptor names it
	 * ("minn-admin/v1/x/y/{id}/trash", or the item route for one on it).
	 *
	 * @param string $id   Resource id.
	 * @param string $slug Action slug.
	 * @return string
	 */
	public static function action_path( $id, $slug ) {
		$a = self::def( $id )['actions'][ $slug ] ?? array();
		return self::NS . '/' . $id . '/{id}' . ( self::on_item( (array) $a ) ? '' : '/' . sanitize_key( (string) $slug ) );
	}

	/**
	 * Counts and a 14-day chart for an inbox status card: over the rows a
	 * resource's list may show (its fixed conditions and scope, as every
	 * reader of it), or over a table no resource lists.
	 *
	 * @param array $c resource (its id) or table, received, unread and spam
	 *                 (conditions), chart (column, clock, where, split,
	 *                 secondary). Anything else (a scope handed over by
	 *                 hand) counts nothing: it would otherwise be ignored.
	 * @return array received, unread, spam (ints), chart (built, or null)
	 */
	public static function inbox_counts( array $c ) {
		$unknown = array_diff( array_keys( $c ), array( 'resource', 'table', 'received', 'unread', 'spam', 'chart' ) );
		if ( $unknown || isset( $c['resource'] ) === isset( $c['table'] ) ) {
			$over = is_scalar( $c['resource'] ?? null ) ? (string) $c['resource'] : ( is_scalar( $c['table'] ?? null ) ? (string) $c['table'] : 'nothing' );
			self::wrong( __METHOD__, "inbox counts over {$over} " . ( $unknown ? 'take no "' . implode( '", "', $unknown ) . '"' : 'name a resource and a table; they take one' ) . ', so they count nothing.' );
			$rows = null;
		} else {
			$rows = isset( $c['resource'] ) ? self::card_rows( (string) $c['resource'], array() ) : array( $c['table'], array() );
		}
		$out  = array();
		foreach ( array( 'received', 'unread', 'spam' ) as $k ) {
			$out[ $k ] = isset( $c[ $k ] ) && $rows ? Minn_Admin_Kernel_SQL::count( $rows[0], $rows[1] + Minn_Admin_Kernel_SQL::tag( (array) $c[ $k ], 'card' ) ) : 0;
		}
		$out['days'] = null;
		if ( ! empty( $c['chart'] ) ) {
			$out['days'] = ! $rows ? Minn_Admin_Kernel_SQL::empty_days( ! empty( $c['chart']['ahead'] ), (string) ( $c['chart']['label'] ?? '' ) ) : Minn_Admin_Kernel_SQL::chart_days( array_merge( $c['chart'], array(
				'table' => $rows[0],
				'where' => $rows[1] + Minn_Admin_Kernel_SQL::tag( (array) ( $c['chart']['where'] ?? array() ), 'card' ),
			) ) );
		}
		return $out;
	}

	/**
	 * What every count over a table resource's rows meets: its rules().
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
		$rules = self::rules( $res );
		return $rules['none'] ? null : $rules['where'];
	}

	/**
	 * The rule a date column on its clock needs for "within the last N seconds".
	 *
	 * @param string $clock   utc | local | epoch | local-epoch | db.
	 * @param int    $seconds The window.
	 * @return array
	 */
	public static function since( $clock, $seconds ) {
		$seconds = (int) $seconds;
		if ( ! in_array( $clock, self::CLOCKS, true ) ) {
			return array(); // A clock it cannot read matches no row (an empty IN list), never every row.
		}
		switch ( (string) $clock ) {
			case 'db':
				// A moment the database cannot read matches no row (an empty IN list).
				$at = Minn_Admin_Kernel_SQL::db_at( time() - $seconds );
				return null === $at ? array() : array( 'op' => '>=', 'value' => $at );
			case 'epoch':
				return array( 'op' => '>=', 'value' => time() - $seconds );
			case 'local-epoch':
				return array( 'op' => '>=', 'value' => (int) current_time( 'timestamp' ) - $seconds ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- the column holds this clock.
			case 'local':
				return array( 'op' => '>=', 'value' => gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - $seconds ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
			default: // utc, and a date compared as one
				return array( 'op' => '>=', 'value' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) );
		}
	}

	/**
	 * "5 mins ago" for a date as a column on its clock stores it, or a dash.
	 *
	 * @param mixed  $stored Stored value.
	 * @param string $clock  utc | local | epoch | local-epoch | db.
	 * @return string
	 */
	public static function ago( $stored, $clock ) {
		if ( null === $stored || ! is_scalar( $stored ) || '' === (string) $stored || ! in_array( $clock, self::CLOCKS, true ) ) {
			return '—';
		}
		$now = time();
		if ( 'db' === $clock ) {
			$ts = (int) Minn_Admin_Kernel_SQL::db_epoch( $stored );
		} elseif ( 'epoch' === $clock ) {
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
			$base += Minn_Admin_Kernel_SQL::tag( $extra, 'extra' );
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
		return Minn_Admin_Kernel_SQL::distinct( self::table_of( $res['source'] ), $column, $base + Minn_Admin_Kernel_SQL::tag( $where, 'extra' ) );
	}

	/**
	 * The value a date column on a clock holds at a moment: 'now', or a
	 * whole number of site days from today's local midnight (0 is today's,
	 * 1 tomorrow's, -13 the chart window's first). Clocks: utc and local
	 * datetimes, date (Y-m-d), epoch, and local-epoch (the site's wall clock
	 * read as an epoch), and db (the database's own clock, as it read then;
	 * null when it cannot say).
	 *
	 * @param string     $clock utc | local | date | epoch | local-epoch | db.
	 * @param string|int $when  'now' or a day offset.
	 * @return string|int|null null for a clock it does not know.
	 */
	public static function at( $clock, $when = 'now' ) {
		if ( ! in_array( $clock, self::CLOCKS, true ) ) {
			return null; // Never a moment on a guessed clock.
		}
		if ( 'db' === $clock ) {
			return Minn_Admin_Kernel_SQL::db_at( (int) self::at( 'epoch', $when ) );
		}
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
			default: // utc
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
		return array( self::table_of( $res['source'] ), $base + Minn_Admin_Kernel_SQL::tag( $where, 'card' ) );
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
	 * The sum of a column over the rows the caller may see that meet
	 * conditions (a redirect list's hits, all time).
	 *
	 * @param string   $id       Resource id.
	 * @param string   $column   Column.
	 * @param array    $where    Conditions.
	 * @param int|null $decimals Decimal places for money (Minn_Admin_Kernel_SQL::sum()).
	 * @return int|float
	 */
	public static function sum( $id, $column, array $where = array(), $decimals = null ) {
		$c = self::card_rows( $id, $where );
		if ( ! $c ) {
			return null === $decimals ? 0 : 0.0;
		}
		return Minn_Admin_Kernel_SQL::sum( $c[0], $column, $c[1], $decimals );
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
	 * where, split, secondary, ahead and label, as Minn_Admin_Kernel_SQL::chart_days().
	 *
	 * @param string $id Resource id.
	 * @param array  $c  Chart.
	 * @return array Days (empty days when the caller may see nothing).
	 */
	public static function days( $id, array $c ) {
		$rows = self::card_rows( $id, (array) ( $c['where'] ?? array() ) );
		if ( ! $rows ) {
			return Minn_Admin_Kernel_SQL::empty_days( ! empty( $c['ahead'] ), (string) ( $c['label'] ?? '' ) );
		}
		return Minn_Admin_Kernel_SQL::chart_days( array_merge( $c, array( 'table' => $rows[0], 'where' => $rows[1] ) ) );
	}
}
