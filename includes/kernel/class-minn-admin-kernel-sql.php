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
 * An empty IN list matches nothing, and an empty NOT IN list everything, so a
 * scope that resolved to no ids can never widen into all of them.
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
	 * @param string $table Table without the prefix.
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;
		return self::ident( $wpdb->prefix . self::ident( $table ) );
	}

	/**
	 * Whether a table exists on this site.
	 *
	 * @param string $table Table without the prefix.
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
	 * @param array  $where Column => rule.
	 * @param string $alias Table alias to qualify columns with ('' for none).
	 * @return array
	 */
	public static function where( array $where, $alias = '' ) {
		$sql  = array();
		$args = array();
		$pre  = '' === $alias ? '' : self::ident( $alias ) . '.';
		foreach ( $where as $col => $rule ) {
			$c = $pre . self::ident( $col );
			if ( is_array( $rule ) && array_key_exists( 'op', $rule ) ) {
				$op = (string) $rule['op'];
				if ( ! in_array( $op, array( '=', '!=', '<', '<=', '>', '>=' ), true ) ) {
					throw new InvalidArgumentException( 'minn_kernel_operator=' . $op );
				}
				$sql[]  = "{$c} {$op} " . self::ph( $rule['value'] );
				$args[] = $rule['value'];
			} elseif ( is_array( $rule ) && array_key_exists( 'not', $rule ) ) {
				$vals = array_values( (array) $rule['not'] );
				if ( $vals ) {
					$sql[] = "{$c} NOT IN (" . implode( ',', array_map( array( __CLASS__, 'ph' ), $vals ) ) . ')';
					$args  = array_merge( $args, $vals );
				}
			} elseif ( is_array( $rule ) ) {
				$vals = array_values( $rule );
				if ( ! $vals ) {
					$sql[] = '1 = 0';
					continue;
				}
				$sql[] = "{$c} IN (" . implode( ',', array_map( array( __CLASS__, 'ph' ), $vals ) ) . ')';
				$args  = array_merge( $args, $vals );
			} elseif ( null === $rule ) {
				$sql[] = "{$c} IS NULL";
			} else {
				$sql[]  = "{$c} = " . self::ph( $rule );
				$args[] = $rule;
			}
		}
		return array( $sql, $args );
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
		list( $sql, $args ) = self::where( $where );
		$q                  = 'SELECT COUNT(*) FROM ' . self::table( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' );
		return (int) $wpdb->get_var( self::prepare( $q, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * One row by key, as an associative array, or null.
	 *
	 * @param string   $table   Table without the prefix.
	 * @param string   $key     Key column.
	 * @param int      $id      Key value.
	 * @param string[] $columns Columns to read ([] for all).
	 * @return array|null
	 */
	public static function row( $table, $key, $id, array $columns = array() ) {
		global $wpdb;
		$cols = $columns ? implode( ', ', array_map( array( __CLASS__, 'ident' ), $columns ) ) : '*';
		$q    = 'SELECT ' . $cols . ' FROM ' . self::table( $table ) . ' WHERE ' . self::ident( $key ) . ' = %d LIMIT 1';
		$row  = $wpdb->get_row( $wpdb->prepare( $q, (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
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
	 * secondary (spam) series by a condition on one column.
	 *
	 * @param array $c table, column (the date), clock (utc|local|epoch|local-epoch),
	 *                 where (conditions for every counted row), split (column),
	 *                 secondary (values of split that count as secondary).
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
		$split              = self::ident( $c['split'] );
		$q                  = "SELECT {$day} AS d, {$split} AS s, COUNT(*) AS n FROM " . self::table( $c['table'] ) . ' WHERE ' . implode( ' AND ', $sql ) . ' GROUP BY d, s';
		foreach ( (array) $wpdb->get_results( self::prepare( $q, $args ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$d = (string) $r->d;
			if ( 'epoch' === $clock || 'local-epoch' === $clock ) {
				$d = minn_admin_chart_epoch_bucket_day( $d );
			}
			minn_admin_chart_bump( $days, $d, in_array( (string) $r->s, array_map( 'strval', (array) ( $c['secondary'] ?? array() ) ), true ), (int) $r->n );
		}
		return $days;
	}
}
