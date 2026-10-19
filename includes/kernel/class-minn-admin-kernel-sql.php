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
 *                                               any of these rules (OR); 'none' => array( … ) is
 *                                               NOT any of them, in SQL only (matches() refuses it:
 *                                               negating PHP's narrower tests would widen them)
 *   'blog_id' => null                           column IS NULL
 *   '#any' => array( array( 'active' => 0 ), array( 'active' => 1, 'expires' => null ) )
 *                                               any of these groups of conditions (OR of ANDs),
 *                                               for a state that spans columns; an empty list
 *                                               matches nothing, and a group may hold its own '#any'
 *                                               (tag each one, "#any\0why", before joining two
 *                                               condition arrays: an untagged pair would collide)
 * A list (integer keys) is always an IN list and a keyed array always a rule
 * of exactly one kind ('op' with its 'value'), anything else being refused.
 * An empty IN list matches nothing, and an empty NOT IN list everything, so a
 * scope that resolved to no ids can never widen into all of them; an empty
 * 'any' matches nothing too.
 *
 * A table is named without its prefix: 'evf_entries' for the site's table,
 * or array( 'table' => 'stream', 'network' => true ) for one the whole
 * network shares on the base prefix, or array( 'core' => 'users' ) for a core
 * table by its $wpdb name. A table may carry 'joins' (see from()):
 * the main table is then "e", and any column may be named "alias.column".
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
		// \z, not $: a $ also matches before a final newline.
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $name ) ) {
			throw new InvalidArgumentException( 'minn_kernel_identifier=' . $name );
		}
		return $name;
	}

	/**
	 * A prefixed table name as MySQL takes one unquoted: what a site's
	 * prefix may be (WordPress allows "1wp_") added to a declared name that
	 * ident() already held.
	 *
	 * @param string $name Full name.
	 * @return string
	 */
	private static function full( $name ) {
		$name = (string) $name;
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}\z/', $name ) || ctype_digit( $name ) ) {
			throw new InvalidArgumentException( 'minn_kernel_table=' . $name );
		}
		return $name;
	}

	/**
	 * The prefixed table a declaration names.
	 *
	 * @param string|array $table Table without the prefix, or table + network,
	 *                            whose table may itself be a spec named() gave.
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;
		// A core table by its $wpdb name, so CUSTOM_USER_TABLE and the
		// network's shared users table are honoured.
		if ( is_array( $table ) && ! empty( $table['core'] ) ) {
			$core = (string) $table['core'];
			if ( ! in_array( $core, array( 'posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'terms', 'term_taxonomy', 'term_relationships', 'options' ), true ) ) {
				throw new InvalidArgumentException( 'minn_kernel_core_table=' . $core );
			}
			return self::full( $wpdb->$core );
		}
		$network = is_array( $table ) && ! empty( $table['network'] );
		$name    = is_array( $table ) ? ( $table['table'] ?? '' ) : $table;
		// A spec in the table's place (named() answered the network's).
		if ( is_array( $name ) ) {
			$network = $network || ! empty( $name['network'] );
			$name    = $name['table'] ?? '';
		}
		return self::full( ( $network ? $wpdb->base_prefix : $wpdb->prefix ) . self::ident( (string) $name ) );
	}

	/**
	 * A table a vendor names in full ("wp_2_bricks_form_submissions", from
	 * its own get_table_name()) as this layer names it: without the site's
	 * prefix, or as the network's when it lives on the base prefix. A name on
	 * neither is kept whole and so read under this site's prefix: a table
	 * that is not there (an empty list), never another one.
	 *
	 * @param string $full The prefixed table name.
	 * @return string|array Table spec.
	 */
	public static function named( $full ) {
		global $wpdb;
		$full = (string) $full;
		if ( '' !== $wpdb->prefix && 0 === strpos( $full, $wpdb->prefix ) ) {
			return substr( $full, strlen( $wpdb->prefix ) );
		}
		if ( '' !== $wpdb->base_prefix && 0 === strpos( $full, $wpdb->base_prefix ) ) {
			return array( 'table' => substr( $full, strlen( $wpdb->base_prefix ) ), 'network' => true );
		}
		return $full;
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
	 * Only a yes is remembered for the request, so a column a migration adds
	 * meanwhile is seen at the next read.
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
		if ( empty( $seen[ $name . '.' . $col ] ) ) {
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
		foreach ( $where as $col => $rule ) {
			list( $s, $a ) = self::is_groups( $col ) ? self::groups( $rule, $alias ) : self::rule( self::col( $col, $alias ), $rule );
			$sql[]         = $s;
			$args          = array_merge( $args, $a );
		}
		return array( $sql, $args );
	}

	/**
	 * Conditions with a tag after each key ("site_id\0fixed"), so they join
	 * other conditions on the same columns without replacing any: the tag is
	 * dropped where a key is read as a column.
	 *
	 * @param array  $where Conditions.
	 * @param string $why   Tag.
	 * @return array
	 */
	public static function tag( array $where, $why ) {
		$out = array();
		foreach ( $where as $col => $rule ) {
			$out[ $col . "\0" . $why ] = $rule;
		}
		return $out;
	}

	/** Whether a condition's key is '#any' (its tag after a NUL aside): groups, not a column. */
	private static function is_groups( $key ) {
		return '#any' === explode( "\0", (string) $key, 2 )[0];
	}

	/**
	 * The groups of an '#any', checked: a list of condition arrays, or a refusal.
	 *
	 * @param mixed $groups Groups.
	 * @return array[]
	 */
	private static function group_list( $groups ) {
		if ( ! is_array( $groups ) || ! self::is_list( $groups ) ) {
			throw new InvalidArgumentException( 'minn_kernel_rule=#any' );
		}
		foreach ( $groups as $g ) {
			if ( ! is_array( $g ) || ( $g && self::is_list( $g ) ) ) {
				throw new InvalidArgumentException( 'minn_kernel_rule=#any' );
			}
		}
		return $groups;
	}

	/** An '#any' as SQL: ( ( a AND b ) OR ( c ) ), or 1 = 0 for no groups. */
	private static function groups( $groups, $alias ) {
		$parts = array();
		$args  = array();
		foreach ( self::group_list( $groups ) as $g ) {
			list( $s, $a ) = self::where( $g, $alias );
			$parts[]       = $s ? '( ' . implode( ' AND ', $s ) . ' )' : '1 = 1';
			$args          = array_merge( $args, $a );
		}
		return array( $parts ? '( ' . implode( ' OR ', $parts ) . ' )' : '1 = 0', $args );
	}

	/**
	 * A column as SQL: "e.status" for the main table, "c.email" for one a
	 * join names, a bare name with the alias ''. A tag after a NUL is dropped.
	 *
	 * @param string $name  Column, or "alias.column".
	 * @param string $alias The main table's alias.
	 * @return string
	 */
	public static function col( $name, $alias = 'e' ) {
		$name = explode( "\0", (string) $name, 2 )[0];
		if ( false !== strpos( $name, '.' ) ) {
			list( $a, $c ) = explode( '.', $name, 2 );
			return self::ident( $a ) . '.' . self::ident( $c );
		}
		return ( '' === $alias ? '' : self::ident( $alias ) . '.' ) . self::ident( $name );
	}

	/**
	 * A table and its joins as SQL, the main table as "e".
	 *
	 * 'joins' => alias => array( 'table', 'network', 'on' => array( own column
	 * => 'alias.column' ) ) is a LEFT JOIN on equal columns; with 'first' =>
	 * array( 'key', 'by', 'of' ) instead of 'on' it joins the lowest-keyed row
	 * of a one-to-many table (a group booking's first customer):
	 * alias.key = ( SELECT MIN( key ) FROM table WHERE by = e.of ).
	 *
	 * @param string|array $table Table spec.
	 * @return string
	 */
	public static function from( $table ) {
		$sql = self::table( $table ) . ' e';
		foreach ( (array) ( is_array( $table ) ? ( $table['joins'] ?? array() ) : array() ) as $alias => $j ) {
			$alias = self::ident( $alias );
			if ( 'e' === strtolower( $alias ) || ! is_array( $j ) ) {
				throw new InvalidArgumentException( 'minn_kernel_join=' . $alias );
			}
			$t = self::table( $j );
			if ( ! empty( $j['first'] ) ) {
				$key = self::ident( $j['first']['key'] );
				$f   = 'f_' . $alias;
				$on  = "{$alias}.{$key} = ( SELECT MIN( {$f}.{$key} ) FROM {$t} {$f} WHERE {$f}." . self::ident( $j['first']['by'] ) . ' = ' . self::col( $j['first']['of'] ) . ' )';
			} else {
				$parts = array();
				foreach ( (array) ( $j['on'] ?? array() ) as $own => $other ) {
					$parts[] = "{$alias}." . self::ident( $own ) . ' = ' . self::col( $other );
				}
				if ( ! $parts ) {
					throw new InvalidArgumentException( 'minn_kernel_join=' . $alias );
				}
				$on = implode( ' AND ', $parts );
			}
			$sql .= " LEFT JOIN {$t} {$alias} ON {$on}";
		}
		return $sql;
	}

	/**
	 * A select list: 'col' is e.col, 'c.email' is c.email AS email, and
	 * 'c.email' => 'customer_email' names the result. '*' (or an empty list)
	 * is every column of the main table, for a vendor whose site may add its
	 * own.
	 *
	 * @param array $columns Columns.
	 * @return string
	 */
	public static function columns( array $columns ) {
		if ( ! $columns ) {
			return 'e.*';
		}
		$out = array();
		foreach ( $columns as $k => $v ) {
			$src   = is_int( $k ) ? (string) $v : (string) $k;
			if ( '*' === $src ) {
				$out[] = 'e.*';
				continue;
			}
			$as    = is_int( $k ) ? ( false !== strpos( $src, '.' ) ? substr( $src, strpos( $src, '.' ) + 1 ) : $src ) : (string) $v;
			$out[] = self::col( $src ) . ( false !== strpos( $src, '.' ) || ! is_int( $k ) ? ' AS ' . self::ident( $as ) : '' );
		}
		return implode( ', ', $out );
	}

	/**
	 * The one kind a keyed rule is, or a refusal: a rule naming two kinds
	 * (array( 'none' => …, 'starts' => … )) would read one way in SQL and
	 * another in PHP, and an 'any' or 'none' must hold a list of rules.
	 *
	 * @param array $rule Keyed rule.
	 * @return string
	 */
	private static function kind( array $rule ) {
		$kinds = array_values( array_intersect( array_keys( $rule ), array( 'starts', 'contains', 'ends', 'any', 'none', 'op', 'not' ) ) );
		$rest  = array_diff( array_keys( $rule ), $kinds, array( 'op' ) === $kinds ? array( 'value' ) : array() );
		if ( 1 !== count( $kinds ) || $rest || ( in_array( $kinds[0], array( 'any', 'none' ), true ) && ! is_array( $rule[ $kinds[0] ] ) ) ) {
			throw new InvalidArgumentException( 'minn_kernel_rule=' . implode( ',', array_keys( $rule ) ) );
		}
		return $kinds[0];
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
		$kind = self::kind( $rule );
		foreach ( array( 'starts' => array( '', '%' ), 'contains' => array( '%', '%' ), 'ends' => array( '%', '' ) ) as $how => $wild ) {
			if ( $how === $kind ) {
				return array( "{$c} LIKE %s", array( $wild[0] . $wpdb->esc_like( (string) $rule[ $how ] ) . $wild[1] ) );
			}
		}
		if ( 'any' === $kind || 'none' === $kind ) {
			$none  = 'none' === $kind;
			$parts = array();
			$args  = array();
			foreach ( $rule[ $kind ] as $one ) {
				list( $s, $a ) = self::rule( $c, $one );
				$parts[]       = $s;
				$args          = array_merge( $args, $a );
			}
			if ( ! $parts ) {
				return array( $none ? '1 = 1' : '1 = 0', $args );
			}
			// NOT ( … ) over a group that holds an IS NULL arm is NULL-safe: a
			// NULL value meets that arm, so the group is true and NOT false.
			return array( ( $none ? 'NOT ' : '' ) . '( ' . implode( ' OR ', $parts ) . ' )', $args );
		}
		if ( 'op' === $kind ) {
			$op = (string) $rule['op'];
			if ( ! in_array( $op, array( '=', '!=', '<', '<=', '>', '>=' ), true ) ) {
				throw new InvalidArgumentException( 'minn_kernel_operator=' . $op );
			}
			// NULL meets no comparison (a moment its clock could not read),
			// where a placeholder would read it as '' and meet every date.
			if ( null === ( $rule['value'] ?? null ) ) {
				return array( '1 = 0', array() );
			}
			return array( "{$c} {$op} " . self::ph( $rule['value'] ), array( $rule['value'] ) );
		}
		$vals = array_values( (array) $rule['not'] );
		return $vals ? array( "{$c} NOT IN (" . implode( ',', array_map( array( __CLASS__, 'ph' ), $vals ) ) . ')', $vals ) : array( '1 = 1', array() );
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
	 * operators, LIKE (case-insensitive), any, IS NULL, '#any' groups.
	 *
	 * @param array $row   Row.
	 * @param array $where Conditions (keys may carry a tag after a NUL).
	 * @return bool
	 */
	public static function matches( array $row, array $where ) {
		foreach ( $where as $col => $rule ) {
			if ( self::is_groups( $col ) ) {
				$any = false;
				foreach ( self::group_list( $rule ) as $g ) {
					$any = $any || self::matches( $row, $g );
				}
				if ( ! $any ) {
					return false;
				}
				continue;
			}
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
		$kind = is_array( $rule ) && ! self::is_list( $rule ) ? self::kind( $rule ) : '';
		// Each test here is the narrower of PHP's and MySQL's (case, trailing
		// spaces, NULL), so NOT of one would be the wider: 'none' is SQL only.
		if ( 'none' === $kind ) {
			throw new InvalidArgumentException( 'minn_kernel_rule=none (SQL only)' );
		}
		if ( 'any' === $kind ) {
			foreach ( $rule['any'] as $one ) {
				if ( self::holds( $v, $one ) ) {
					return true;
				}
			}
			return false;
		}
		if ( 'not' === $kind && ! $rule['not'] ) {
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
		// A negation held to MySQL's wider equality (no case, no trailing
		// spaces, text cast to a number), so it is never wider than SQL's.
		$differs = function ( $v, $want ) {
			if ( ! is_string( $want ) ) {
				return is_numeric( $v ) && (float) $v !== (float) $want;
			}
			return is_scalar( $v ) && ! is_bool( $v ) && strtolower( rtrim( (string) $v ) ) !== strtolower( rtrim( $want ) );
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
		if ( 'starts' === $kind ) {
			return 0 === strpos( $text, strtolower( (string) $rule['starts'] ) );
		}
		if ( 'contains' === $kind ) {
			return false !== strpos( $text, strtolower( (string) $rule['contains'] ) );
		}
		if ( 'ends' === $kind ) {
			$end = strtolower( (string) $rule['ends'] );
			return '' === $end || substr( $text, -strlen( $end ) ) === $end;
		}
		if ( 'op' === $kind ) {
			$want = $rule['value'] ?? null;
			$op   = (string) $rule['op'];
			if ( null === $want ) {
				return false; // As where() reads it: NULL meets no comparison.
			}
			if ( '=' === $op ) {
				return $same( $v, $want );
			}
			if ( '!=' === $op ) {
				return $differs( $v, $want );
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
		if ( 'not' === $kind ) {
			foreach ( (array) $rule['not'] as $one ) {
				if ( ! $differs( $v, $one ) ) {
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
	 * COUNT(*) of a table (and its joins) under conditions.
	 *
	 * @param string|array $table Table spec.
	 * @param array        $where Conditions.
	 * @return int
	 */
	public static function count( $table, array $where = array() ) {
		global $wpdb;
		if ( ! self::exists( $table ) ) {
			return 0;
		}
		list( $sql, $args ) = self::where( $where, 'e' );
		$q                  = 'SELECT COUNT(*) FROM ' . self::from( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' );
		return (int) $wpdb->get_var( self::prepare( $q, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * One row by key, as an associative array, or null.
	 *
	 * @param string|array $table   Table spec.
	 * @param string       $key     Key column.
	 * @param int          $id      Key value.
	 * @param array        $columns Columns (see columns(); [] for the main table's all).
	 * @param array        $where   Conditions the row must also meet (a site column).
	 * @return array|null
	 */
	public static function row( $table, $key, $id, array $columns = array(), array $where = array() ) {
		global $wpdb;
		list( $sql, $args ) = self::where( $where, 'e' );
		$q                  = 'SELECT ' . self::columns( $columns ) . ' FROM ' . self::from( $table ) . ' WHERE ' . implode( ' AND ', array_merge( array( self::col( $key ) . ' = %d' ), $sql ) ) . ' LIMIT 1';
		$row                = $wpdb->get_row( $wpdb->prepare( $q, array_merge( array( (int) $id ), $args ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Rows of a table under conditions, as associative arrays.
	 *
	 * @param string|array $table   Table spec.
	 * @param array        $columns Columns (see columns()).
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
		list( $sql, $args ) = self::where( $where, 'e' );
		$by                 = array();
		foreach ( $order as $col => $dir ) {
			$by[] = self::col( $col ) . ( 'DESC' === strtoupper( (string) $dir ) ? ' DESC' : ' ASC' );
		}
		$q = 'SELECT ' . self::columns( $columns ) . ' FROM ' . self::from( $table )
			. ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' )
			. ( $by ? ' ORDER BY ' . implode( ', ', $by ) : '' )
			. ( $limit > 0 ? ' LIMIT ' . (int) $limit : '' );
		$rows = $wpdb->get_results( self::prepare( $q, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * DELETE rows of a table by their key: only the ids named, each above
	 * zero, and further conditions only narrow them, so no declaration can
	 * empty a table, whatever a rule compiles to. A vendor's own delete is
	 * used where it has one; this is for a log table it gives no API for.
	 *
	 * @param string|array $table Table spec (joins are not read).
	 * @param string       $key   Key column.
	 * @param int|int[]    $ids   Ids.
	 * @param array        $where Further conditions (a source's fixed ones).
	 * @return int Rows deleted.
	 */
	public static function delete( $table, $key, $ids, array $where = array() ) {
		global $wpdb;
		self::writable( $table );
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids || min( $ids ) < 1 ) {
			throw new InvalidArgumentException( 'minn_kernel_delete_ids' );
		}
		if ( ! self::exists( $table ) ) {
			return 0;
		}
		list( $sql, $args ) = self::where( array_merge( $where, array( $key . "\0delete" => $ids ) ), '' );
		return (int) $wpdb->query( self::prepare( 'DELETE FROM ' . self::table( $table ) . ' WHERE ' . implode( ' AND ', $sql ), $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Whether the store holds no row with this key under conditions: true
	 * only when the count answers zero, so a read that failed (which returns
	 * no row too) never proves a delete.
	 *
	 * @param string|array $table Table spec.
	 * @param string       $key   Key column.
	 * @param int          $id    Key value.
	 * @param array        $where Conditions (a source's fixed ones).
	 * @return bool
	 */
	public static function absent( $table, $key, $id, array $where = array() ) {
		global $wpdb;
		list( $sql, $args ) = self::where( $where, 'e' );
		$q                  = 'SELECT COUNT(*) FROM ' . self::from( $table ) . ' WHERE ' . implode( ' AND ', array_merge( array( self::col( $key ) . ' = %d' ), $sql ) );
		$n                  = $wpdb->get_var( $wpdb->prepare( $q, array_merge( array( (int) $id ), $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return null !== $n && '' === (string) $wpdb->last_error && 0 === (int) $n;
	}

	/**
	 * One column of the first row under conditions in an order: the newest
	 * by the key (DESC), or the soonest by a date (the column itself, ASC).
	 *
	 * @param string|array $table  Table spec.
	 * @param string       $column Column to read.
	 * @param array        $where  Conditions.
	 * @param string       $key    The column that orders rows.
	 * @param string       $dir    DESC or ASC.
	 * @return string|null
	 */
	public static function latest( $table, $column, array $where, $key, $dir = 'DESC' ) {
		$rows = self::select( $table, array( $column ), $where, array( $key => $dir ), 1 );
		return $rows ? (string) reset( $rows[0] ) : null;
	}

	/**
	 * The most frequent value of a column under conditions, with its count,
	 * ties broken by the value; null when no row matches.
	 *
	 * @param string|array $table  Table spec.
	 * @param string       $column Column to group by.
	 * @param array        $where  Conditions.
	 * @return array|null array( 'value' => string, 'count' => int )
	 */
	public static function top( $table, $column, array $where ) {
		$counts = self::counts( $table, $column, $where, 1 );
		return $counts ? $counts[0] : null;
	}

	/**
	 * The most frequent values of a column under conditions with their
	 * counts, most first, ties broken by the value (a status-code mix).
	 *
	 * @param string|array $table  Table spec.
	 * @param string       $column Column to group by.
	 * @param array        $where  Conditions.
	 * @param int          $limit  At most this many (1 to 1,000).
	 * @return array[] array( 'value' => string, 'count' => int ) each
	 */
	public static function counts( $table, $column, array $where, $limit = 10 ) {
		global $wpdb;
		if ( ! self::exists( $table ) ) {
			return array();
		}
		list( $sql, $args ) = self::where( $where, 'e' );
		$col                = self::col( $column );
		$q                  = "SELECT {$col} AS v, COUNT(*) AS n FROM " . self::from( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' ) . " GROUP BY {$col} ORDER BY n DESC, {$col} ASC LIMIT " . max( 1, min( 1000, (int) $limit ) );
		$out                = array();
		foreach ( (array) $wpdb->get_results( self::prepare( $q, $args ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[] = array( 'value' => (string) $row['v'], 'count' => (int) $row['n'] );
		}
		return $out;
	}

	/**
	 * Grouped totals: rows of group values and the totals over each group,
	 * under conditions, in an order, at most $limit (a traffic report's top
	 * pages, a day's views). Every column a declared identifier, every value a
	 * placeholder, the order only by what the query selects.
	 *
	 * @param string|array $table  Table spec (joins as from(), each named "alias.column").
	 * @param array        $group  What rows group by: 'col' or 'c.col' (named by the column),
	 *                             'c.col' => 'name', or 'name' => array( 'day' => col, 'clock'
	 *                             => date | local | utc | epoch | local-epoch ) for the day
	 *                             it falls on, as chart_days() reads it (Y-m-d, '' for none;
	 *                             'date' is a DATE column); the clock is required.
	 * @param array        $totals Name => array( 'count' => '*' | col ), array( 'distinct' =>
	 *                             col ) (COUNT DISTINCT), array( 'sum' => col ) (0 for none),
	 *                             array( 'max' => col ) or array( 'min' => col ).
	 * @param array        $where  Conditions.
	 * @param array        $order  Name => ASC | DESC, a group's or a total's; the groups break
	 *                             ties, so a limit cuts the same rows every time.
	 * @param int          $limit  At most this many rows (1 to 5,000: two years of days, twice;
	 *                             below 1 is 1).
	 * @return array[] Rows keyed by the group and total names.
	 */
	public static function totals( $table, array $group, array $totals, array $where = array(), array $order = array(), $limit = 100 ) {
		global $wpdb;
		$sel   = array();
		$by    = array();
		$exprs = array();
		$days  = array();
		foreach ( $group as $k => $g ) {
			if ( is_array( $g ) ) {
				// A day without its clock would be read on a guessed one.
				if ( ! isset( $g['clock'] ) ) {
					throw new InvalidArgumentException( 'minn_kernel_clock=' );
				}
				$name          = self::ident( (string) $k );
				$clock         = (string) $g['clock'];
				$expr          = self::day_sql( self::col( (string) ( $g['day'] ?? '' ) ), $clock );
				$days[ $name ] = in_array( $clock, array( 'epoch', 'local-epoch', 'db' ), true );
			} else {
				$src  = is_int( $k ) ? (string) $g : (string) $k;
				$bare = explode( "\0", $src, 2 )[0];
				$name = self::ident( is_int( $k ) ? ( false === strpos( $bare, '.' ) ? $bare : substr( $bare, strpos( $bare, '.' ) + 1 ) ) : (string) $g );
				$expr = self::col( $src );
			}
			if ( isset( $exprs[ $name ] ) ) {
				throw new InvalidArgumentException( 'minn_kernel_total=' . $name );
			}
			$exprs[ $name ] = $expr;
			$by[]           = $expr;
			$sel[]          = "{$expr} AS `{$name}`";
		}
		foreach ( $totals as $k => $t ) {
			$name = self::ident( (string) $k );
			$kind = is_array( $t ) && 1 === count( $t ) ? (string) array_key_first( $t ) : '';
			$of   = is_array( $t ) ? (string) reset( $t ) : '';
			if ( isset( $exprs[ $name ] ) || ! in_array( $kind, array( 'count', 'distinct', 'sum', 'max', 'min' ), true ) ) {
				throw new InvalidArgumentException( 'minn_kernel_total=' . $name );
			}
			if ( 'count' === $kind ) {
				$expr = '*' === $of ? 'COUNT(*)' : 'COUNT(' . self::col( $of ) . ')';
			} elseif ( 'distinct' === $kind ) {
				$expr = 'COUNT(DISTINCT ' . self::col( $of ) . ')';
			} elseif ( 'sum' === $kind ) {
				$expr = 'COALESCE(SUM(' . self::col( $of ) . '),0)';
			} else {
				$expr = strtoupper( $kind ) . '(' . self::col( $of ) . ')';
			}
			$exprs[ $name ] = $expr;
			$sel[]          = "{$expr} AS `{$name}`";
		}
		if ( ! $sel ) {
			throw new InvalidArgumentException( 'minn_kernel_total=' );
		}
		// Refused whether or not the vendor's table is there.
		$ord = array();
		foreach ( $order as $name => $dir ) {
			$name = (string) $name;
			if ( ! isset( $exprs[ $name ] ) ) {
				throw new InvalidArgumentException( 'minn_kernel_total_order=' . $name );
			}
			$ord[] = $exprs[ $name ] . ( 'DESC' === strtoupper( (string) $dir ) ? ' DESC' : ' ASC' );
		}
		if ( ! self::exists( $table ) ) {
			return array();
		}
		foreach ( $by as $expr ) {
			$ord[] = $expr . ' ASC';
		}
		list( $sql, $args ) = self::where( $where, 'e' );
		$q = 'SELECT ' . implode( ', ', $sel ) . ' FROM ' . self::from( $table )
			. ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' )
			. ( $by ? ' GROUP BY ' . implode( ', ', $by ) : '' )
			. ( $ord ? ' ORDER BY ' . implode( ', ', array_unique( $ord ) ) : '' )
			. ' LIMIT ' . max( 1, min( 5000, (int) $limit ) );
		$rows = $wpdb->get_results( self::prepare( $q, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			foreach ( $days as $name => $epoch ) {
				if ( null === $row[ $name ] ) {
					$row[ $name ] = '';
				} elseif ( $epoch ) {
					$row[ $name ] = minn_admin_chart_epoch_bucket_day( $row[ $name ] );
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * The SQL for the day a column falls on, by its clock, as chart_days()
	 * buckets it: a DATE column as it is, the date part of a datetime, the
	 * site day of a UTC datetime, or of an epoch (a day number; 'local-epoch'
	 * reads it unshifted, which for a real epoch is its UTC day).
	 *
	 * @param string $col   Qualified column.
	 * @param string $clock date | local | utc | epoch | local-epoch.
	 * @return string
	 */
	private static function day_sql( $col, $clock ) {
		switch ( $clock ) {
			case 'db':
				// The database reads its own clock's datetime as the instant it was.
				return minn_admin_chart_epoch_day_sql( "UNIX_TIMESTAMP({$col})", 'epoch' );
			case 'date':
				return $col;
			case 'local':
				return "DATE({$col})";
			case 'utc':
				return minn_admin_chart_utc_day_sql( $col );
			case 'epoch':
			case 'local-epoch':
				return minn_admin_chart_epoch_day_sql( $col, $clock );
		}
		throw new InvalidArgumentException( 'minn_kernel_clock=' . $clock );
	}

	/**
	 * The sum of a column under conditions; 0 for none. A count is summed
	 * whole; money is summed with $decimals, each row rounded to them first,
	 * as a store shows each one: read as an exact decimal, so a text column
	 * (postmeta's) rounds 5.005 to 5.01 as PHP does, not as a double would.
	 *
	 * @param string|array $table    Table spec.
	 * @param string       $column   Column.
	 * @param array        $where    Conditions.
	 * @param int|null     $decimals Decimal places (0 to 8), or null for a whole number.
	 * @return int|float
	 */
	public static function sum( $table, $column, array $where = array(), $decimals = null ) {
		global $wpdb;
		$money = null !== $decimals;
		$d     = $money ? max( 0, min( 8, (int) $decimals ) ) : 0;
		if ( ! self::exists( $table ) ) {
			return $money ? 0.0 : 0;
		}
		list( $sql, $args ) = self::where( $where, 'e' );
		$col                = self::col( $column );
		$q                  = 'SELECT COALESCE(SUM(' . ( $money ? "ROUND(CAST({$col} AS DECIMAL(65,30)), {$d})" : $col ) . '),0) FROM ' . self::from( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' );
		$v                  = (float) $wpdb->get_var( self::prepare( $q, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $money ? round( $v, $d ) : (int) round( $v );
	}

	/**
	 * INSERT one row: its columns as declared identifiers, every value a
	 * placeholder. For a table its vendor gives no writer for.
	 *
	 * @param string|array $table  Table spec (joins are not read).
	 * @param array        $values Column => value (at least one).
	 * @return int The new row's id, 0 when nothing was written.
	 */
	public static function insert( $table, array $values ) {
		global $wpdb;
		self::writable( $table );
		if ( ! $values ) {
			throw new InvalidArgumentException( 'minn_kernel_insert_without_values' );
		}
		list( $cols, $phs, $args ) = self::assign( $values );
		if ( ! self::exists( $table ) || ! self::fits( $table, $values ) ) {
			return 0;
		}
		$ok = $wpdb->query( self::prepare( 'INSERT INTO ' . self::table( $table ) . ' (' . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', $phs ) . ')', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * UPDATE rows by their key, as delete() names them: only the ids given,
	 * each above zero, further conditions only narrowing them.
	 *
	 * @param string|array $table  Table spec (joins are not read).
	 * @param string       $key    Key column.
	 * @param int|int[]    $ids    Ids.
	 * @param array        $values Column => value (at least one).
	 * @param array        $where  Further conditions.
	 * @return int Rows changed.
	 */
	public static function update( $table, $key, $ids, array $values, array $where = array() ) {
		global $wpdb;
		$ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		if ( ! $ids || min( $ids ) < 1 ) {
			throw new InvalidArgumentException( 'minn_kernel_update_ids' );
		}
		self::writable( $table );
		if ( ! $values ) {
			throw new InvalidArgumentException( 'minn_kernel_update_without_values' );
		}
		// The key names the rows; it is never one of the values written,
		// whatever its case (MySQL's column names are not case-sensitive).
		if ( in_array( strtolower( (string) $key ), array_map( 'strtolower', array_map( 'strval', array_keys( $values ) ) ), true ) ) {
			throw new InvalidArgumentException( 'minn_kernel_update_key' );
		}
		list( $cols, $phs, $vargs ) = self::assign( $values );
		if ( ! self::exists( $table ) || ! self::fits( $table, $values ) ) {
			return 0;
		}
		$set                        = array();
		foreach ( $cols as $i => $c ) {
			$set[] = "{$c} = {$phs[ $i ]}";
		}
		list( $sql, $args ) = self::where( array_merge( $where, array( $key . "\0update" => $ids ) ), '' );
		return (int) $wpdb->query( self::prepare( 'UPDATE ' . self::table( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $sql ), array_merge( $vargs, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A table the write primitives may write: a vendor's own, never one of
	 * WordPress's (options, users, posts …), which have their own APIs, write
	 * fence and object cache.
	 */
	private static function writable( $table ) {
		global $wpdb;
		$name = is_array( $table ) && isset( $table['core'] ) ? '' : self::table( $table );
		// By its name too, as table() reads it (a spec in the table's place
		// included): on a network site "options" on the base prefix is the
		// main site's, and "usermeta" with the site's own prefix is no table
		// of anyone's, and still a name that means theirs. (Another site's,
		// "3_options", is no identifier.)
		$core = array_map( 'strtolower', $wpdb->tables( 'all', false ) );
		$bare = strtolower( (string) $name );
		foreach ( array_unique( array( (string) $wpdb->prefix, (string) $wpdb->base_prefix ) ) as $p ) {
			if ( '' !== $p && 0 === strpos( $bare, strtolower( $p ) ) ) {
				$bare = substr( $bare, strlen( $p ) );
				break;
			}
		}
		if ( '' === $name || in_array( $name, array_values( $wpdb->tables( 'all', true ) ), true ) || in_array( $bare, $core, true ) ) {
			throw new InvalidArgumentException( 'minn_kernel_core_table' );
		}
		// A network site writes its own tables: one on the base prefix is the
		// main site's, or the whole network's, and no site's admin may write it.
		if ( is_multisite() && $wpdb->prefix !== $wpdb->base_prefix && 0 !== strpos( $name, $wpdb->prefix ) ) {
			throw new InvalidArgumentException( 'minn_kernel_network_write' );
		}
	}

	/**
	 * Whether every value fits its column as it is, so MySQL (which WordPress
	 * runs without strict modes) never cuts, casts or nulls it into something
	 * else: a known column, NULL only where it may be, a number for a number,
	 * text within the column's length and charset.
	 */
	private static function fits( $table, array $values ) {
		global $wpdb;
		static $columns = array();
		$name = self::table( $table );
		if ( ! isset( $columns[ $name ] ) ) {
			$found = array();
			foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . $name, ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from table().
				$found[ strtolower( (string) $c['Field'] ) ] = $c;
			}
			if ( ! $found ) {
				return false; // Not remembered: a table read that failed proves nothing.
			}
			$columns[ $name ] = $found;
		}
		foreach ( $values as $col => $v ) {
			$c = $columns[ $name ][ strtolower( (string) $col ) ] ?? null;
			if ( ! $c ) {
				return false;
			}
			if ( null === $v ) {
				if ( 'NO' === strtoupper( (string) $c['Null'] ) ) {
					return false;
				}
				continue;
			}
			if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/i', (string) $c['Type'], $m ) ) {
				// A whole number within the column's range: MySQL without strict
				// modes would round 5.5 and clamp 300 into a tinyint.
				if ( ! self::whole_fits( $v, strtolower( $m[1] ), false !== stripos( (string) $c['Type'], 'unsigned' ) ) ) {
					return false;
				}
				continue;
			}
			if ( preg_match( '/^(decimal|numeric|float|double|real|bit)\b/i', (string) $c['Type'] ) ) {
				if ( ! is_bool( $v ) && ! is_numeric( $v ) ) {
					return false;
				}
				continue;
			}
			$v = (string) $v;
			if ( $wpdb->strip_invalid_text_for_column( $name, (string) $col, $v ) !== $v ) {
				return false;
			}
			$len = $wpdb->get_col_length( $name, (string) $col );
			if ( is_array( $len ) && isset( $len['length'] ) && ( 'byte' === ( $len['type'] ?? '' ) ? strlen( $v ) : mb_strlen( $v ) ) > (int) $len['length'] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is a whole number an integer column of this kind holds:
	 * an int, a bool, or digits (a sign allowed where the column is signed).
	 *
	 * @param mixed  $v        Value.
	 * @param string $kind     tinyint | smallint | mediumint | int | integer | bigint.
	 * @param bool   $unsigned Whether the column is unsigned.
	 * @return bool
	 */
	private static function whole_fits( $v, $kind, $unsigned ) {
		$s = is_bool( $v ) ? (string) (int) $v : ( is_int( $v ) ? (string) $v : ( is_string( $v ) ? trim( $v ) : '' ) );
		if ( ! preg_match( '/^(-?)0*(\d{1,20})\z/', $s, $m ) ) {
			return false;
		}
		$neg  = '-' === $m[1] && '0' !== $m[2];
		$bits = array( 'tinyint' => 8, 'smallint' => 16, 'mediumint' => 24, 'int' => 32, 'integer' => 32, 'bigint' => 64 );
		$n    = $bits[ $kind ] ?? 64;
		if ( $unsigned && $neg ) {
			return false;
		}
		// Each bound as digits, compared as digits: a bigint's are past PHP's int.
		$bound = array(
			8  => array( '127', '128', '255' ),
			16 => array( '32767', '32768', '65535' ),
			24 => array( '8388607', '8388608', '16777215' ),
			32 => array( '2147483647', '2147483648', '4294967295' ),
			64 => array( '9223372036854775807', '9223372036854775808', '18446744073709551615' ),
		)[ $n ];
		$max = $unsigned ? $bound[2] : ( $neg ? $bound[1] : $bound[0] );
		return strlen( $m[2] ) < strlen( $max ) || ( strlen( $m[2] ) === strlen( $max ) && strcmp( $m[2], $max ) <= 0 );
	}

	/** Columns as identifiers, a placeholder for each value (NULL as itself), and the values. */
	private static function assign( array $values ) {
		$cols = array();
		$phs  = array();
		$args = array();
		foreach ( $values as $col => $v ) {
			if ( ! is_null( $v ) && ! is_scalar( $v ) ) {
				throw new InvalidArgumentException( 'minn_kernel_value=' . (string) $col );
			}
			$cols[] = self::ident( $col );
			$phs[]  = null === $v ? 'NULL' : self::ph( $v );
			if ( null !== $v ) {
				$args[] = $v;
			}
		}
		return array( $cols, $phs, $args );
	}

	/** Epochs of datetimes on the database's own clock, by value, as the database read them. */
	private static $epochs = array();

	/**
	 * Datetimes a vendor wrote with NOW() (the database's own clock, which is
	 * the server's, not the site's) as the instants they are, read by the
	 * database itself so its zone's daylight saving holds for each date: one
	 * query for many values; a value that is no datetime, or a read that
	 * failed, is null (and a failure is not remembered).
	 *
	 * @param string[] $values Stored values.
	 * @return array Value => epoch or null.
	 */
	public static function db_epochs( array $values ) {
		global $wpdb;
		$want = array();
		foreach ( array_unique( array_map( 'strval', $values ) ) as $v ) {
			if ( ! array_key_exists( $v, self::$epochs ) && self::is_datetime( $v ) ) {
				$want[] = $v;
			}
		}
		foreach ( array_chunk( $want, 100 ) as $chunk ) {
			$cols = array();
			foreach ( array_keys( $chunk ) as $i ) {
				$cols[] = "UNIX_TIMESTAMP(%s) AS t{$i}";
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . implode( ', ', $cols ), $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- only placeholders.
			if ( ! is_array( $row ) ) {
				continue;
			}
			foreach ( $chunk as $i => $v ) {
				$t                  = $row[ "t{$i}" ] ?? null;
				self::$epochs[ $v ] = is_numeric( $t ) && (int) $t > 0 ? (int) $t : null;
			}
		}
		$out = array();
		foreach ( $values as $v ) {
			$out[ (string) $v ] = self::$epochs[ (string) $v ] ?? null;
		}
		return $out;
	}

	/**
	 * The datetime the database's own clock reads at an instant (what NOW()
	 * wrote then), asked of the database so its zone's daylight saving holds;
	 * null when the read fails.
	 *
	 * @param int $epoch Instant.
	 * @return string|null
	 */
	public static function db_at( $epoch ) {
		global $wpdb;
		static $seen = array();
		$epoch = (int) $epoch;
		if ( ! isset( $seen[ $epoch ] ) ) {
			$v = $epoch > 0 ? $wpdb->get_var( $wpdb->prepare( 'SELECT FROM_UNIXTIME(%d)', $epoch ) ) : null;
			if ( ! self::is_datetime( $v ) ) {
				return null; // Not remembered: a read that failed proves nothing.
			}
			$seen[ $epoch ] = substr( (string) $v, 0, 19 );
		}
		return $seen[ $epoch ];
	}

	/** One datetime on the database's clock as its epoch, or null (see db_epochs()). */
	public static function db_epoch( $v ) {
		$v = (string) $v;
		return self::db_epochs( array( $v ) )[ $v ];
	}

	/** Whether a value is exactly a real "Y-m-d H:i:s" from 1970-01-02 on (a never-set column sits before). */
	private static function is_datetime( $v ) {
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', (string) $v, new DateTimeZone( 'UTC' ) );
		return $d && $d->format( 'Y-m-d H:i:s' ) === (string) $v && $d->getTimestamp() >= DAY_IN_SECONDS;
	}

	/**
	 * The distinct values of a column under conditions, sorted, at most 1,000.
	 *
	 * @param string|array $table  Table spec.
	 * @param string       $column Column.
	 * @param array        $where  Conditions.
	 * @return string[]
	 */
	public static function distinct( $table, $column, array $where ) {
		global $wpdb;
		if ( ! self::exists( $table ) ) {
			return array();
		}
		list( $sql, $args ) = self::where( $where, 'e' );
		$col                = self::col( $column );
		$q                  = "SELECT DISTINCT {$col} FROM " . self::from( $table ) . ( $sql ? ' WHERE ' . implode( ' AND ', $sql ) : '' ) . " ORDER BY {$col} ASC LIMIT 1000";
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
		$t     = self::from( $q['table'] );
		$where = $q['sql'] ? ' WHERE ' . implode( ' AND ', $q['sql'] ) : '';
		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM {$t}{$where}", $q['args'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$dir   = 'ASC' === strtoupper( (string) ( $q['dir'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$order = self::col( $q['order'] ) . ' ' . $dir;
		// Every sort breaks ties by the key, so pages never overlap.
		if ( $q['order'] !== $q['key'] ) {
			$order .= ', ' . self::col( $q['key'] ) . ' ' . $dir;
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			self::prepare( 'SELECT ' . self::columns( $q['columns'] ) . " FROM {$t}{$where} ORDER BY {$order} LIMIT %d OFFSET %d", array_merge( $q['args'], array( (int) $q['limit'], (int) $q['offset'] ) ) ),
			ARRAY_A
		);
		return array( is_array( $rows ) ? $rows : array(), $total );
	}

	/**
	 * A chart's fourteen days with nothing counted: the last fourteen, or
	 * from today on.
	 *
	 * @param bool $ahead From today on.
	 * @return array
	 */
	public static function empty_days( $ahead = false, $label = '' ) {
		if ( ! $ahead ) {
			$days = minn_admin_chart_days();
		} else {
			$days = array();
			for ( $i = 0; $i < 14; $i++ ) {
				$d          = minn_admin_chart_day( $i );
				$days[ $d ] = array( 'label' => $d, 'value' => 0, 'secondary' => 0 );
			}
		}
		// A label in a date format of the adapter's ('M j'), the day's key unchanged.
		if ( '' !== (string) $label ) {
			foreach ( $days as $d => $day ) {
				$days[ $d ]['label'] = gmdate( (string) $label, (int) strtotime( $d . ' 00:00:00 UTC' ) );
			}
		}
		return $days;
	}

	/**
	 * Rows per site day for a status chart: the last fourteen days, or with
	 * 'ahead' the fourteen from today on. A row whose split value is one of
	 * 'secondary' counts there, every other row in the primary series, so a
	 * day's two counts never overlap; with no split there is one series.
	 * 'secondary' may be a rule instead of values (array( 'none' => array(
	 * null, '' ) ): a failure is any text), decided in SQL as a condition
	 * is, so a day is still two rows however many texts it holds.
	 *
	 * @param array $c table, column (the date), clock (utc|local|date|epoch|
	 *                 local-epoch|db; utc when none), where (conditions for every counted row),
	 *                 split (column, optional), secondary (its values), ahead,
	 *                 label (a date format for each day's label).
	 * @return array Days keyed Y-m-d: label, value, secondary (and from / to
	 *               looking back, the bounds a clicked bar sends).
	 */
	public static function chart_days( array $c ) {
		global $wpdb;
		$ahead = ! empty( $c['ahead'] );
		$col   = self::col( $c['column'] );
		$clock = (string) ( $c['clock'] ?? 'utc' );
		$days  = self::empty_days( $ahead, (string) ( $c['label'] ?? '' ) );
		if ( ! in_array( $clock, Minn_Admin_Kernel::CLOCKS, true ) ) {
			return $days; // A clock it cannot read: no days, never days guessed on another.
		}
		$day   = self::day_sql( $col, $clock );
		$since = $ahead ? Minn_Admin_Kernel::at( $clock, 0 ) : Minn_Admin_Kernel::at( $clock, -13 );
		if ( null === $since || ( $ahead && null === Minn_Admin_Kernel::at( $clock, 14 ) ) ) {
			return $days; // A bound the database could not read: no days, never every row.
		}
		$bound = array( $c['column'] . "\0since" => array( 'op' => '>=', 'value' => $since ) );
		if ( $ahead ) {
			$bound[ $c['column'] . "\0until" ] = array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( $clock, 14 ) );
		}
		list( $sql, $args ) = self::where( array_merge( (array) ( $c['where'] ?? array() ), $bound ), 'e' );
		$second             = (array) ( $c['secondary'] ?? array() );
		$by_rule            = ! empty( $c['split'] ) && $second && ! self::is_list( $second );
		$split              = empty( $c['split'] ) ? "''" : self::col( $c['split'] );
		if ( $by_rule ) {
			list( $rs, $ra ) = self::rule( $split, $second );
			$split           = "CASE WHEN {$rs} THEN 1 ELSE 0 END";
			$args            = array_merge( $ra, $args );
		}
		$q                  = "SELECT {$day} AS _minn_d, {$split} AS _minn_s, COUNT(*) AS _minn_n FROM " . self::from( $c['table'] ) . ' WHERE ' . implode( ' AND ', $sql ) . ' GROUP BY _minn_d, _minn_s';
		foreach ( (array) $wpdb->get_results( self::prepare( $q, $args ) ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$r = (object) array( 'd' => $r->_minn_d, 's' => $r->_minn_s, 'n' => $r->_minn_n );
			$d = (string) $r->d;
			if ( in_array( $clock, array( 'epoch', 'local-epoch', 'db' ), true ) ) {
				$d = minn_admin_chart_epoch_bucket_day( $d );
			}
			$is_second = $by_rule ? '1' === (string) $r->s : in_array( (string) $r->s, array_map( 'strval', $second ), true );
			minn_admin_chart_bump( $days, substr( $d, 0, 10 ), ! empty( $c['split'] ) && $is_second, (int) $r->n );
		}
		return $days;
	}

}
