<?php
/**
 * The kernel's table queries: every identifier from a declaration and
 * checked against a fixed pattern, every value prepared.
 *
 * Conditions are arrays of column => rule, joined with AND:
 *   'status' => 'publish'                       column = value
 *   'status' => array( 'publish', 'spam' )      column IN (…)
 *   'status' => array( 'not' => array( … ) )    column NOT IN (…)
 *   'viewed' => array( 'op' => '>', 'value' => 0 )
 *   'source' => array( 'starts' => 'rest|' )    LIKE, the value escaped: 'starts',
 *                                               'contains' or 'ends'
 *   'source' => array( 'any' => array( '', null, array( 'starts' => 'app:' ) ) )
 *                                               any of these rules (OR)
 *   'blog_id' => null                           column IS NULL
 * A list (integer keys) is always an IN list and a keyed array always a rule,
 * one this layer does not know being refused.
 * An empty IN list matches nothing, and an empty NOT IN list everything, so a
 * scope that resolved to no ids can never widen into all of them; an empty
 * 'any' matches nothing too.
 *
 * A table is named without its prefix: 'evf_entries' for the site's table,
 * or array( 'table' => 'stream', 'network' => true ) for one the whole
 * network shares on the base prefix.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Kernel_SQL {

	/**
	 * A table or column name from a declaration, or a doing-it-wrong stop.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public static function ident( $name ) {
		$name = (string) $name;
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name ) ) {
			throw new InvalidArgumentException( 'minn_kernel_identifier=' . $name );
		}
		return $name;
	}

	/**
	 * The prefixed table a declaration names.
	 *
	 * @param string|array $table Table without the prefix, or table + network.
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;
		$network = is_array( $table ) && ! empty( $table['network'] );
		$name    = is_array( $table ) ? (string) ( $table['table'] ?? '' ) : (string) $table;
		return self::ident( ( $network ? $wpdb->base_prefix : $wpdb->prefix ) . self::ident( $name ) );
	}

	/**
	 * Whether a table exists on this site.
	 *
	 * @param string|array $table Table without the prefix, or table + network.
	 * @return bool
	 */
	public static function exists( $table ) {
		global $wpdb;
		static $seen = array();
		$name = self::table( $table );
		if ( ! isset( $seen[ $name ] ) ) {
			$found         = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
			$seen[ $name ] = $found && 0 === strcasecmp( (string) $found, $name );
		}
		return $seen[ $name ];
	}

	/**
	 * Whether a table that exists has a column (a vendor's later migration).
	 *
	 * @param string|array $table  Table without the prefix, or table + network.
	 * @param string       $column Column.
	 * @return bool
	 */
	public static function has_column( $table, $column ) {
		global $wpdb;
		static $seen = array();
		$name = self::table( $table );
		$col  = self::ident( $column );
		if ( ! isset( $seen[ $name . '.' . $col ] ) ) {
			$seen[ $name . '.' . $col ] = self::exists( $table ) && (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$name}` LIKE %s", $wpdb->esc_like( $col ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier from ident().
		}
		return $seen[ $name . '.' . $col ];
	}

	/**
	 * Placeholder for a value: ints and floats keep their type, the rest is a string.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function ph( $v ) {
		if ( is_int( $v ) || is_bool( $v ) ) {
			return '%d';
		}
		return is_float( $v ) ? '%f' : '%s';
	}

	/**
	 * Conditions to [ sql fragments, args ].
	 *
	 * @param array  $where Column => rule. A key may carry a tag after a NUL
	 *                      ("site_id\0fixed"), so two rules on one column both apply.
	 * @param string $alias Table alias to qualify columns with ('' for none).
	 * @return array
	 */
	public static function where( array $where, $alias = '' ) {
		$sql  = array();
		$args = array();
		$pre  = '' === $alias ? '' : self::ident( $alias ) . '.';
		foreach ( $where as $col => $rule ) {
			list( $s, $a ) = self::rule( $pre . self::ident( explode( "\0", (string) $col, 2 )[0] ), $rule );
			$sql[]         = $s;
			$args          = array_merge( $args, $a );
		}
		return array( $sql, $args );
	}

	/**
	 * One column's rule to [ sql, args ].
	 *
	 * @param string $c    Qualified column.
	 * @param mixed  $rule Rule (see the class header).
	 * @return array
	 */
	private static function rule( $c, $rule ) {
		global $wpdb;
		if ( null === $rule ) {
			return array( "{$c} IS NULL", array() );
		}
		if ( ! is_array( $rule ) ) {
			return array( "{$c} = " . self::ph( $rule ), array( $rule ) );
		}
		// Integer keys only: a list of values, whatever its keys' order.
		if ( self::is_list( $rule ) ) {
			$vals = array_values( $rule );
			return $vals ? array( "{$c} IN (" . implode( ',', array_map( array( __CLASS__, 'ph' ), $vals ) ) . ')', $vals ) : array( '1 = 0', array() );
		}
		foreach ( array( 'starts' => array( '', '%' ), 'contains' => array( '%', '%' ), 'ends' => array( '%', '' ) ) as $how => $wild ) {
			if ( array_key_exists( $how, $rule ) ) {
				return array( "{$c} LIKE %s", array( $wild[0] . $wpdb->esc_like( (string) $rule[ $how ] ) . $wild[1] ) );
			}
		}
		if ( array_key_exists( 'any', $rule ) ) {
			$parts = array();
			$args  = array();
			foreach ( (array) $rule['any'] as $one ) {
				list( $s, $a ) = self::rule( $c, $one );
				$parts[]       = $s;
				$args          = array_merge( $args, $a );
			}
			return array( $parts ? '( ' . implode( ' OR ', $parts ) . ' )' : '1 = 0', $args );
		}
		if ( array_key_exists( 'op', $rule ) ) {
			$op = (string) $rule['op'];
			if ( ! in_array( $op, array( '=', '!=', '<', '<=', '>', '>=' ), true ) ) {
				throw new InvalidArgumentException( 'minn_kernel_operator=' . $op );
			}
			return array( "{$c} {$op} " . self::ph( $rule['value'] ), array( $rule['value'] ) );
		}
		if ( array_key_exists( 'not', $rule ) ) {
			$vals = array_values( (array) $rule['not'] );
			return $vals ? array( "{$c} NOT IN (" . implode( ',', array_map( array( __CLASS__, 'ph' ), $vals ) ) . ')', $vals ) : array( '1 = 1', array() );
		}
		// A keyed array that is no rule this layer knows is a mistake, not a list.
		throw new InvalidArgumentException( 'minn_kernel_rule=' . implode( ',', array_keys( $rule ) ) );
	}

	/** Whether an array is a list of values (integer keys only), not a keyed rule. */
	private static function is_list( array $a ) {
		foreach ( array_keys( $a ) as $k ) {
			if ( ! is_int( $k ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an in-memory row meets conditions, by the same rules as
	 * where(): equality as strings (a boolean as a boolean), lists, NOT,
	 * operators, LIKE (case-insensitive), any, IS NULL.
	 *
	 * @param array $row   Row.
	 * @param array $where Conditions (keys may carry a tag after a NUL).
	 * @return bool
	 */
	public static function matches( array $row, array $where ) {
		foreach ( $where as $col => $rule ) {
			$col = explode( "\0", (string) $col, 2 )[0];
			if ( ! self::holds( array_key_exists( $col, $row ) ? $row[ $col ] : null, $rule ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * One value against one rule (see matches()). Where PHP and MySQL could
	 * differ it is the narrower: equality is exact (no collation folding
	 * case or trailing spaces), a boolean equals only a boolean or 1 and 0,
	 * and <, <=, > and >= hold only between two numbers (MySQL would cast
	 * text to a number; this matches nothing instead).
	 */
	private static function holds( $v, $rule ) {
		if ( is_array( $rule ) && ! self::is_list( $rule ) && array_key_exists( 'any', $rule ) ) {
			foreach ( (array) $rule['any'] as $one ) {
				if ( self::holds( $v, $one ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_array( $rule ) && ! self::is_list( $rule ) && array_key_exists( 'not', $rule ) && ! $rule['not'] ) {
			return true; // where() writes 1 = 1.
		}
		if ( null === $rule ) {
			return null === $v;
		}
		if ( null === $v ) {
			return false; // NULL meets no comparison in SQL either.
		}
		$same = function ( $v, $want ) {
			if ( is_bool( $want ) ) {
				return is_bool( $v ) ? $v === $want : ( is_numeric( $v ) && (int) $v === (int) $want );
			}
			return is_scalar( $v ) && ! is_bool( $v ) && (string) $v === (string) $want;
		};
		if ( ! is_array( $rule ) ) {
			return $same( $v, $rule );
		}
		if ( self::is_list( $rule ) ) {
			foreach ( $rule as $one ) {
				if ( $same( $v, $one ) ) {
					return true;
				}
			}
			return false;
		}
		$text = is_scalar( $v ) ? strtolower( (string) $v ) : '';
		if ( array_key_exists( 'starts', $rule ) ) {
			return 0 === strpos( $text, strtolower( (string) $rule['starts'] ) );
		}
		if ( array_key_exists( 'contains', $rule ) ) {
			return false !== strpos( $text, strtolower( (string) $rule['contains'] ) );
		}
		if ( array_key_exists( 'ends', $rule ) ) {
			$end = strtolower( (string) $rule['ends'] );
			return '' === $end || substr( $text, -strlen( $end ) ) === $end;
		}
		if ( array_key_exists( 'op', $rule ) ) {
			$want = $rule['value'];
			$op   = (string) $rule['op'];
			if ( '=' === $op || '!=' === $op ) {
				return ( '=' === $op ) === $same( $v, $want );
			}
			if ( ! is_numeric( $v ) || ! is_numeric( $want ) ) {
				if ( in_array( $op, array( '<', '<=', '>', '>=' ), true ) ) {
					return false;
				}
				throw new InvalidArgumentException( 'minn_kernel_operator=' . $op );
			}
			$cmp = (float) $v <=> (float) $want;
			switch ( $op ) {
				case '<':
					return $cmp < 0;
				case '<=':
					return $cmp <= 0;
				case '>':
					return $cmp > 0;
				case '>=':
					return $cmp >= 0;
			}
			throw new InvalidArgumentException( 'minn_kernel_operator=' . $op );
		}
		if ( array_key_exists( 'not', $rule ) ) {
			foreach ( (array) $rule['not'] as $one ) {
				if ( $same( $v, $one ) ) {
					return false;
				}
			}
			return true;
		}
		throw new InvalidArgumentException( 'minn_kernel_rule=' . implode( ',', array_keys( $rule ) ) );
	}

	/**
	 * Prepare a query whose only dynamic parts are placeholders.
	 *
	 * @param string $sql  Query with placeholders.
	 * @param array  $args Values.
	 * @return string
	 */
	public static function prepare( $sql, array $args ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers come from ident(), values are placeholders.
		return $args ? $wpdb->prepare( $sql, $args ) : $sql;
	}

	/**
	 * COUNT(*) of a table under conditions.
	 *
	 * @param string $table Table without the prefix.
	 * @param array  $where Conditions.
	 * @return int
	 */
	public static function count( $table, array $where = array() ) {
		global $wpdb;
		if ( ! self::exists( $table ) ) {
			return 0;
		}
		list( $sql, $args ) = self::where( $where );
		$q                  = 'SELECT COUNT(*) FROM ' . self::table( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' );
		return (int) $wpdb->get_var( self::prepare( $q, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * One row by key, as an associative array, or null.
	 *
	 * @param string|array $table   Table without the prefix.
	 * @param string       $key     Key column.
	 * @param int          $id      Key value.
	 * @param string[]     $columns Columns to read ([] for all).
	 * @param array        $where   Conditions the row must also meet (a site column).
	 * @return array|null
	 */
	public static function row( $table, $key, $id, array $columns = array(), array $where = array() ) {
		global $wpdb;
		list( $sql, $args ) = self::where( $where );
		$cols               = $columns ? implode( ', ', array_map( array( __CLASS__, 'ident' ), $columns ) ) : '*';
		$q                  = 'SELECT ' . $cols . ' FROM ' . self::table( $table ) . ' WHERE ' . implode( ' AND ', array_merge( array( self::ident( $key ) . ' = %d' ), $sql ) ) . ' LIMIT 1';
		$row                = $wpdb->get_row( $wpdb->prepare( $q, array_merge( array( (int) $id ), $args ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows of a table under conditions, as associative arrays.
	 *
	 * @param string|array $table   Table without the prefix.
	 * @param string[]     $columns Columns to read.
	 * @param array        $where   Conditions.
	 * @param array        $order   Column => ASC|DESC.
	 * @param int          $limit   At most this many (0 for no limit).
	 * @return array[]
	 */
	public static function select( $table, array $columns, array $where = array(), array $order = array(), $limit = 0 ) {
		global $wpdb;
		if ( ! self::exists( $table ) ) {
			return array();
		}
		list( $sql, $args ) = self::where( $where );
		$by                 = array();
		foreach ( $order as $col => $dir ) {
			$by[] = self::ident( $col ) . ( 'DESC' === strtoupper( (string) $dir ) ? ' DESC' : ' ASC' );
		}
		$q = 'SELECT ' . implode( ', ', array_map( array( __CLASS__, 'ident' ), $columns ) ) . ' FROM ' . self::table( $table )
			. ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' )
			. ( $by ? ' ORDER BY ' . implode( ', ', $by ) : '' )
			. ( $limit > 0 ? ' LIMIT ' . (int) $limit : '' );
		$rows = $wpdb->get_results( self::prepare( $q, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * One column of the newest row under conditions (by the key), or null.
	 *
	 * @param string|array $table  Table without the prefix.
	 * @param string       $column Column to read.
	 * @param array        $where  Conditions.
	 * @param string       $key    The key that orders rows by age.
	 * @return string|null
	 */
	public static function latest( $table, $column, array $where, $key ) {
		$rows = self::select( $table, array( $column ), $where, array( $key => 'DESC' ), 1 );
		return $rows ? (string) reset( $rows[0] ) : null;
	}

	/**
	 * The most frequent value of a column under conditions, with its count,
	 * ties broken by the value; null when no row matches.
	 *
	 * @param string|array $table  Table without the prefix.
	 * @param string       $column Column to group by.
	 * @param array        $where  Conditions.
	 * @return array|null array( 'value' => string, 'count' => int )
	 */
	public static function top( $table, $column, array $where ) {
		global $wpdb;
		if ( ! self::exists( $table ) ) {
			return null;
		}
		list( $sql, $args ) = self::where( $where );
		$col                = self::ident( $column );
		$q                  = "SELECT {$col} AS v, COUNT(*) AS n FROM " . self::table( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' ) . " GROUP BY {$col} ORDER BY n DESC, {$col} ASC LIMIT 1";
		$row                = $wpdb->get_row( self::prepare( $q, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? array( 'value' => (string) $row['v'], 'count' => (int) $row['n'] ) : null;
	}

	/**
	 * The distinct values of a column under conditions, sorted, at most 1,000.
	 *
	 * @param string|array $table  Table without the prefix.
	 * @param string       $column Column.
	 * @param array        $where  Conditions.
	 * @return string[]
	 */
	public static function distinct( $table, $column, array $where ) {
		global $wpdb;
		if ( ! self::exists( $table ) ) {
			return array();
		}
		list( $sql, $args ) = self::where( $where );
		$col                = self::ident( $column );
		$q                  = "SELECT DISTINCT {$col} FROM " . self::table( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' ) . " ORDER BY {$col} ASC LIMIT 1000";
		return array_map( 'strval', (array) $wpdb->get_col( self::prepare( $q, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A page of rows and the total under the same conditions.
	 *
	 * @param array $q table, key, columns, where (fragments + args from where()
	 *                 and the kernel's own search and range clauses), order,
	 *                 dir, limit, offset.
	 * @return array [ rows, total ]
	 */
	public static function page( array $q ) {
		global $wpdb;
		$t     = self::table( $q['table'] ) . ' e';
		$cols  = implode( ', ', array_map( function ( $c ) {
			return 'e.' . Minn_Admin_Kernel_SQL::ident( $c );
		}, $q['columns'] ) );
		$where = $q['sql'] ? ' WHERE ' . implode( ' AND ', $q['sql'] ) : '';
		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$t}{$where}", $q['args'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$dir   = 'ASC' === strtoupper( (string) ( $q['dir'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$order = 'e.' . self::ident( $q['order'] ) . ' ' . $dir;
		// Every sort breaks ties by the key, so pages never overlap.
		if ( $q['order'] !== $q['key'] ) {
			$order .= ', e.' . self::ident( $q['key'] ) . ' ' . $dir;
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::prepare( "SELECT {$cols} FROM {$t}{$where} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $q['args'], array( (int) $q['limit'], (int) $q['offset'] ) ) ),
			ARRAY_A
		);
		return array( is_array( $rows ) ? $rows : array(), $total );
	}

	/**
	 * Rows per site day for the status chart, split into primary and
	 * secondary (spam) series by a condition on one column, or one series
	 * when no split is declared.
	 *
	 * @param array $c table, column (the date), clock (utc|local|epoch|local-epoch),
	 *                 where (conditions for every counted row), split (column,
	 *                 optional), secondary (values of split that count as secondary).
	 * @return array Days from minn_admin_chart_days(), bumped.
	 */
	public static function chart_days( array $c ) {
		global $wpdb;
		$days  = minn_admin_chart_days();
		$col   = self::ident( $c['column'] );
		$clock = (string) ( $c['clock'] ?? 'utc' );
		if ( 'utc' === $clock ) {
			$day   = minn_admin_chart_utc_day_sql( $col );
			$since = array( "{$col} >= %s", minn_admin_chart_utc_since() );
		} elseif ( 'epoch' === $clock || 'local-epoch' === $clock ) {
			$day   = minn_admin_chart_epoch_day_sql( $col, $clock );
			$since = array( "{$col} >= %d", minn_admin_chart_epoch_since( 14, $clock ) );
		} else {
			$day   = "DATE({$col})";
			$since = array( "{$col} >= %s", minn_admin_chart_local_since() );
		}
		list( $sql, $args ) = self::where( (array) ( $c['where'] ?? array() ) );
		$sql[]              = $since[0];
		$args[]             = $since[1];
		$split              = empty( $c['split'] ) ? "''" : self::ident( $c['split'] );
		$q                  = "SELECT {$day} AS d, {$split} AS s, COUNT(*) AS n FROM " . self::table( $c['table'] ) . ' WHERE ' . implode( ' AND ', $sql ) . ' GROUP BY d, s';
		foreach ( (array) $wpdb->get_results( self::prepare( $q, $args ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$d = (string) $r->d;
			if ( 'epoch' === $clock || 'local-epoch' === $clock ) {
				$d = minn_admin_chart_epoch_bucket_day( $d );
			}
			minn_admin_chart_bump( $days, $d, ! empty( $c['split'] ) && in_array( (string) $r->s, array_map( 'strval', (array) ( $c['secondary'] ?? array() ) ), true ), (int) $r->n );
		}
		return $days;
	}
}
