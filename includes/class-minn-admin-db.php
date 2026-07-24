<?php
/**
 * Read-only database viewer (docs/native-editors.md, case study 2).
 *
 * Read-only is the product, not a v1 compromise: a database editor bypasses
 * every plugin's invariants (serialized blobs, caches, code-enforced foreign
 * keys) and turns a diagnostic into a foot-gun. No write routes exist here,
 * and none should be added. "I need real SQL" is answered by WP-CLI or
 * Adminer, honestly.
 *
 * LIMIT discipline: totals come from information_schema estimates (or exact
 * counts only when cheap), filtered counts stop at COUNT_CAP, and paging is
 * bounded to the first WINDOW rows of an ordering — narrowing with a filter
 * is the way to reach the tail. This keeps the viewer harmless on the huge
 * tables it is most needed on.
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_DB {

	const NS = 'minn-admin/v1';

	// Exact-count threshold: estimates at or under this are cheap to verify.
	const EXACT_MAX = 10000;
	// Filtered counts stop counting here ("10,000+").
	const COUNT_CAP = 10000;
	// Paging is bounded to the first WINDOW rows of the current ordering.
	const WINDOW = 10000;
	// Per-cell byte caps: grid payloads stay light, the row detail is roomy.
	const CELL_CAP_LIST   = 2048;
	const CELL_CAP_DETAIL = 131072;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$manage = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			self::NS,
			'/db/tables',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'tables' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/db/rows',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rows' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/db/row',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'row' ),
				'permission_callback' => $manage,
			)
		);
	}

	/**
	 * All tables in this database, from information_schema metadata only
	 * (never touches the tables themselves). rows is the storage engine's
	 * ESTIMATE — InnoDB is routinely off by 2×, which is fine for a browser.
	 */
	private static function table_index() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS name, engine, table_rows AS rows_est,
					( data_length + index_length ) AS size, table_collation AS collation
				 FROM information_schema.TABLES
				 WHERE table_schema = %s AND table_type = %s
				 ORDER BY table_name ASC',
				DB_NAME,
				'BASE TABLE'
			)
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Resolve an untrusted table name against the live table list. The
	 * CANONICAL name from information_schema is what gets interpolated
	 * (backtick-quoted) into SQL — user input never reaches a query as an
	 * identifier. Case-insensitive match (rule: macOS/case-folding MySQL
	 * report names in drifting case).
	 */
	private static function resolve_table( $name ) {
		foreach ( self::table_index() as $t ) {
			if ( 0 === strcasecmp( (string) $t->name, (string) $name ) ) {
				return $t;
			}
		}
		return null;
	}

	private static function quote_ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Column metadata for a resolved table. Returns array of
	 * { name, type, nullable, key, extra } plus the primary-key column list.
	 */
	private static function columns_of( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from the information_schema whitelist, backtick-quoted.
		$raw     = $wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . self::quote_ident( $table ) );
		$columns = array();
		$primary = array();
		foreach ( (array) $raw as $c ) {
			$columns[] = array(
				'name'     => $c->Field,
				'type'     => $c->Type,
				'nullable' => 'YES' === $c->Null,
				'key'      => $c->Key,
				'extra'    => $c->Extra,
			);
			if ( 'PRI' === $c->Key ) {
				$primary[] = $c->Field;
			}
		}
		return array( $columns, $primary );
	}

	/**
	 * Reduce a raw cell value to a JSON-safe payload. Serialized blobs stay
	 * RAW text by design (the standing shim rule: never unserialize protects
	 * even a viewer). Binary/invalid-UTF-8 values become a hex preview so
	 * wp_json_encode can never choke mid-response.
	 *
	 * Shapes: null · plain string · { v, cut } (truncated, cut = total bytes)
	 * · { hex, bin } (binary preview, bin = total bytes).
	 */
	private static function cell( $value, $cap ) {
		if ( null === $value ) {
			return null;
		}
		$value = (string) $value;
		$bytes = strlen( $value );
		// Valid UTF-8 and free of C0 controls (tab/newline/CR allowed)?
		$printable = (bool) preg_match( '//u', $value )
			&& ! preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value );
		if ( ! $printable ) {
			return array(
				'hex' => bin2hex( substr( $value, 0, 120 ) ),
				'bin' => $bytes,
			);
		}
		if ( $bytes > $cap ) {
			$clip = substr( $value, 0, $cap );
			// A UTF-8 sequence may be split at the cap — trim to validity.
			while ( '' !== $clip && ! preg_match( '//u', $clip ) ) {
				$clip = substr( $clip, 0, -1 );
			}
			return array(
				'v'   => $clip,
				'cut' => $bytes,
			);
		}
		return $value;
	}

	/** GET /db/tables — table list with estimates; prefix-scoped by default. */
	public static function tables( $request ) {
		global $wpdb;
		$all     = rest_sanitize_boolean( $request->get_param( 'all' ) );
		$prefix  = $wpdb->prefix;
		$index   = self::table_index();
		$tables  = array();
		$total   = 0;
		$foreign = 0;
		foreach ( $index as $t ) {
			$own    = 0 === stripos( $t->name, $prefix );
			$total += (int) $t->size;
			if ( ! $own ) {
				$foreign++;
				if ( ! $all ) {
					continue;
				}
			}
			$tables[] = array(
				'name'       => $t->name,
				'own'        => $own,
				'engine'     => (string) $t->engine,
				'rows'       => (int) $t->rows_est,
				'size'       => (int) $t->size,
				'size_human' => size_format( (int) $t->size, 1 ),
			);
		}
		return rest_ensure_response(
			array(
				'prefix'           => $prefix,
				'database'         => DB_NAME,
				'tables'           => $tables,
				'foreign'          => $foreign,
				'total_size'       => $total,
				'total_size_human' => size_format( $total, 1 ),
			)
		);
	}

	/** GET /db/rows — one page of a table, sorted/filtered, LIMIT-bounded. */
	public static function rows( $request ) {
		global $wpdb;
		$meta = self::resolve_table( $request->get_param( 'table' ) );
		if ( ! $meta ) {
			return new WP_Error( 'minn_db_no_table', __( 'Unknown table.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		list( $columns, $primary ) = self::columns_of( $meta->name );
		if ( ! $columns ) {
			return new WP_Error( 'minn_db_no_columns', __( 'Could not read the table structure.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		$names    = wp_list_pluck( $columns, 'name' );
		$ident    = self::quote_ident( $meta->name );
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 50 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );

		// Sort: whitelisted column only. Default = primary key DESC (recent
		// rows first on log-shaped tables); no PK means natural order.
		$orderby = (string) $request->get_param( 'orderby' );
		$order   = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';
		if ( ! in_array( $orderby, $names, true ) ) {
			$orderby = '';
		}
		$sorted_default = false;
		if ( '' === $orderby && count( $primary ) === 1 ) {
			$orderby        = $primary[0];
			$sorted_default = true;
		}
		$order_sql = $orderby ? ' ORDER BY ' . self::quote_ident( $orderby ) . ' ' . $order : '';

		// Per-column contains-filter: whitelisted column, LIKE-escaped value.
		$fcol  = (string) $request->get_param( 'fcol' );
		$fq    = (string) $request->get_param( 'fq' );
		$where = '';
		if ( '' !== $fq && in_array( $fcol, $names, true ) ) {
			$where = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier whitelisted + backtick-quoted above.
				' WHERE ' . self::quote_ident( $fcol ) . ' LIKE %s',
				'%' . $wpdb->esc_like( $fq ) . '%'
			);
		} else {
			$fcol = '';
			$fq   = '';
		}

		// Count. Unfiltered: information_schema estimate, verified exactly
		// only when small. Filtered: exact but capped (the subquery LIMIT
		// stops the scan at COUNT_CAP + 1 rows).
		$approx = false;
		if ( $where ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from whitelisted parts; value prepared above.
			$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$ident}{$where} LIMIT " . ( self::COUNT_CAP + 1 ) . ') x' );
			$capped = $total > self::COUNT_CAP;
			if ( $capped ) {
				$total  = self::COUNT_CAP;
				$approx = true;
			}
		} else {
			$total = (int) $meta->rows_est;
			if ( $total <= self::EXACT_MAX ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- whitelisted identifier.
				$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ident}" );
			} else {
				$approx = true;
			}
		}

		// Paging window: offsets never pass WINDOW (deep OFFSET on an
		// unindexed ordering is a table-killer; filters reach the tail).
		$window    = min( max( $total, 0 ), self::WINDOW );
		$max_pages = max( 1, (int) ceil( $window / $per_page ) );
		$page      = min( $page, $max_pages );
		$offset    = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers whitelisted, limits are ints.
		$raw = $wpdb->get_results( "SELECT * FROM {$ident}{$where}{$order_sql} LIMIT {$per_page} OFFSET {$offset}", ARRAY_N );

		$rows = array();
		foreach ( (array) $raw as $r ) {
			$cells = array();
			foreach ( $r as $v ) {
				$cells[] = self::cell( $v, self::CELL_CAP_LIST );
			}
			$rows[] = $cells;
		}

		return rest_ensure_response(
			array(
				'table'      => $meta->name,
				'engine'     => (string) $meta->engine,
				'collation'  => (string) $meta->collation,
				'size_human' => size_format( (int) $meta->size, 1 ),
				'columns'    => $columns,
				'primary'    => $primary,
				'rows'       => $rows,
				'total'      => $total,
				'approx'     => $approx,
				'window'     => self::WINDOW,
				'page'       => $page,
				'per_page'   => $per_page,
				'orderby'    => $orderby,
				'order'      => strtolower( $order ),
				'sorted_default' => $sorted_default,
				'fcol'       => $fcol,
				'fq'         => $fq,
			)
		);
	}

	/** GET /db/row — one row by primary key, with the roomy per-cell cap. */
	public static function row( $request ) {
		global $wpdb;
		$meta = self::resolve_table( $request->get_param( 'table' ) );
		if ( ! $meta ) {
			return new WP_Error( 'minn_db_no_table', __( 'Unknown table.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		list( $columns, $primary ) = self::columns_of( $meta->name );
		$pk = json_decode( (string) $request->get_param( 'pk' ), true );
		if ( ! $primary || ! is_array( $pk ) || array_diff( array_keys( $pk ), $primary ) || count( $pk ) !== count( $primary ) ) {
			return new WP_Error( 'minn_db_bad_pk', __( 'This table has no usable primary key.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$where = array();
		foreach ( $primary as $col ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier from SHOW COLUMNS, backtick-quoted.
			$where[] = $wpdb->prepare( self::quote_ident( $col ) . ' = %s', (string) $pk[ $col ] );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers whitelisted; values prepared above.
		$raw = $wpdb->get_row( 'SELECT * FROM ' . self::quote_ident( $meta->name ) . ' WHERE ' . implode( ' AND ', $where ) . ' LIMIT 1', ARRAY_A );
		if ( null === $raw ) {
			return new WP_Error( 'minn_db_no_row', __( 'Row not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		$cells = array();
		foreach ( $columns as $c ) {
			$cells[] = self::cell( array_key_exists( $c['name'], $raw ) ? $raw[ $c['name'] ] : null, self::CELL_CAP_DETAIL );
		}
		return rest_ensure_response(
			array(
				'table'   => $meta->name,
				'columns' => $columns,
				'primary' => $primary,
				'cells'   => $cells,
			)
		);
	}
}
