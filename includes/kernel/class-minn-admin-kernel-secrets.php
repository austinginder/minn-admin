<?php
/**
 * Credential declarations: where each plugin keeps its keys.
 *
 * An adapter declares the stores that hold its plugin's credentials, beside
 * the code that detects the plugin, and the database browser and the egress
 * fence read the declarations together with Minn_Admin_DB's central lists
 * (which stay for plugins no adapter covers):
 *
 *   minn_admin_secrets( 'contact-form-7', array(
 *     'options' => array( 'wpcf7' => array( 'recaptcha/*', 'turnstile/*' ) ),  // a row, and leaves inside it
 *   ) );
 *   minn_admin_secrets( 'updraftplus', array(
 *     'options'  => array( 'updraft_s3', 'updraft_jobdata_*' ),  // rows; one * per name matches any run
 *     'usermeta' => array( 'updraftcentral_login_key' ),
 *   ) );
 *
 * The keys:
 *
 * - options: option and network option rows (the two share one list, as a
 *   network-activated plugin keeps in sitemeta what it keeps in options on a
 *   single site). Each row is redacted whole in the browser, left out of a
 *   value search, and its credential-named leaves become the fence's needles.
 *   A row may name leaves inside it ('a/b', '*' for any key or run of
 *   keys) that hold a credential under a name that does not say so (a
 *   secret keyed by its public site key); every value at or below a named
 *   leaf (names compared without case) that is 16 or more token characters
 *   shaped like a key (Minn_Admin_DB::key_shaped()) becomes a needle too.
 *   Leaves are for a named row, not a '*' shape.
 * - usermeta: user meta keys holding a credential that a copy could carry
 *   (a licence key, a connection key): redacted and searched for.
 * - user_tokens: per-user tokens, one or two for each user of a feature:
 *   redacted by name only, not searched for, so the fence's list stays
 *   bounded on a site with many users.
 * - columns: a table (no prefix) => its credential columns, redacted in the
 *   table and in copies of it, and searched for.
 * - column_tokens: the same, redacted by name only: a column with a row per
 *   user or per account (an employee's calendar token), which on a large
 *   site would fill the fence's list.
 *
 * A declaration is made when the adapter file loads, whether or not its
 * plugin is active: a deactivated plugin leaves its keys in the database.
 * One the kernel cannot read in full still registers the readable form of
 * each store (a missed store would print a key: a lone name for a list, the
 * widest shape for a name with several '*') and reports every item it
 * dropped or widened in problems(), which the conformance suite fails on.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Kernel_Secrets {

	const KEYS = array( 'options', 'usermeta', 'user_tokens', 'columns', 'column_tokens' );

	/** Plugin => [ 'files' => [], 'options' => name => leaves, 'usermeta' => [], 'user_tokens' => [], 'columns' => table => cols, 'column_tokens' => table => cols ]. */
	private static $declared = array();

	/** What could not be read, in order. */
	private static $problems = array();

	/** The flattened lists, built on first use after a declaration. */
	private static $flat = null;

	/** hash(), built on first use after a declaration. */
	private static $hash = null;

	/**
	 * Record a plugin's credential stores.
	 *
	 * @param string $plugin Plugin slug, used to group and report.
	 * @param array  $stores See the header.
	 */
	public static function add( $plugin, $stores ) {
		$plugin = (string) $plugin;
		$trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names the declaring file for the conformance suite.
		$file   = '';
		foreach ( $trace as $frame ) {
			if ( isset( $frame['file'] ) && __FILE__ !== $frame['file'] ) {
				$file = basename( $frame['file'] );
				break;
			}
		}
		if ( '' === $plugin ) {
			self::problem( 'a declaration without a plugin slug in ' . $file );
			$plugin = 'unnamed';
		}
		if ( ! isset( self::$declared[ $plugin ] ) ) {
			self::$declared[ $plugin ] = array( 'files' => array(), 'options' => array(), 'usermeta' => array(), 'user_tokens' => array(), 'columns' => array(), 'column_tokens' => array() );
		}
		$d = &self::$declared[ $plugin ];
		if ( '' !== $file && ! in_array( $file, $d['files'], true ) ) {
			$d['files'][] = $file;
		}
		if ( ! is_array( $stores ) ) {
			self::problem( "{$plugin}: the stores are not an array" );
			$stores = array();
		}
		foreach ( $stores as $key => $list ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				self::problem( "{$plugin}: unknown key '{$key}'" );
				continue;
			}
			// A lone name is read as a list of one.
			if ( is_string( $list ) ) {
				self::problem( "{$plugin}: {$key} is a string, read as a list of one" );
				$list = array( $list );
			}
			if ( ! is_array( $list ) ) {
				self::problem( "{$plugin}: {$key} is not a list" );
				continue;
			}
			foreach ( $list as $k => $v ) {
				if ( 'columns' === $key || 'column_tokens' === $key ) {
					self::add_columns( $plugin, $key, $k, $v, $d );
					continue;
				}
				// A plain entry, or (options only) a name => its leaves.
				$name   = is_int( $k ) ? $v : $k;
				$leaves = is_int( $k ) ? array() : $v;
				$name   = self::name( $plugin, $key, $name );
				if ( null === $name ) {
					continue;
				}
				if ( 'options' !== $key ) {
					if ( ! is_int( $k ) ) {
						self::problem( "{$plugin}: {$key} takes names only; the leaves of {$name} are ignored" );
					}
					if ( 'usermeta' === $key && 0 === strpos( $name, '*' ) ) {
						self::problem( "{$plugin}: usermeta {$name} has no prefix to search by: it is redacted but not searched for" );
					}
					$d[ $key ][] = $name;
					continue;
				}
				if ( is_string( $leaves ) ) {
					$leaves = array( $leaves );
				}
				if ( ! is_array( $leaves ) ) {
					self::problem( "{$plugin}: the leaves of {$name} are not a list" );
					$leaves = array();
				}
				$kept = array();
				foreach ( $leaves as $leaf ) {
					if ( is_string( $leaf ) && '' !== trim( $leaf, '/' ) ) {
						$kept[] = $leaf;
					} else {
						self::problem( "{$plugin}: a leaf of {$name} is not a path: " . wp_json_encode( $leaf ) );
					}
				}
				if ( $kept && false !== strpos( $name, '*' ) ) {
					self::problem( "{$plugin}: leaves name a row, not the shape {$name}; the shape is redacted whole" );
					$kept = array();
				}
				$d['options'][ $name ] = array_values( array_unique( array_merge( $d['options'][ $name ] ?? array(), $kept ) ) );
			}
		}
		unset( $d );
		self::$flat = null;
		self::$hash = null;
		// A list read before this declaration (the browser's, the fence's,
		// the fence's minute in the object cache) is stale now.
		if ( class_exists( 'Minn_Admin_DB', false ) ) {
			Minn_Admin_DB::forget_credentials();
		}
		if ( class_exists( 'Minn_Admin_Egress', false ) ) {
			Minn_Admin_Egress::declarations_changed();
		}
	}

	/** One name, or null when nothing readable is left. More than one '*' widens to the first prefix and last suffix; a name that is only '*' is refused. */
	private static function name( $plugin, $key, $name ) {
		if ( ! is_string( $name ) || '' === $name ) {
			self::problem( "{$plugin}: {$key} entry " . wp_json_encode( $name ) . ' is not a name' );
			return null;
		}
		if ( substr_count( $name, '*' ) > 1 ) {
			$wide = substr( $name, 0, strpos( $name, '*' ) ) . '*' . substr( $name, strrpos( $name, '*' ) + 1 );
			self::problem( "{$plugin}: {$key} {$name} has more than one *, read as {$wide}" );
			$name = $wide;
		}
		if ( '*' === $name ) {
			self::problem( "{$plugin}: {$key} '*' would name every row; refused" );
			return null;
		}
		return $name;
	}

	/** One columns entry: table => list of columns, or table => one column. */
	private static function add_columns( $plugin, $key, $table, $cols, array &$d ) {
		if ( ! is_string( $table ) || '' === $table ) {
			self::problem( "{$plugin}: {$key} takes table => columns; " . wp_json_encode( $cols ) . ' has no table' );
			return;
		}
		if ( is_string( $cols ) ) {
			self::problem( "{$plugin}: {$key} of {$table} is a string, read as a list of one" );
			$cols = array( $cols );
		}
		if ( ! is_array( $cols ) ) {
			self::problem( "{$plugin}: {$key} of {$table} is not a list" );
			return;
		}
		foreach ( $cols as $col ) {
			if ( is_string( $col ) && '' !== $col ) {
				$d[ $key ][ strtolower( $table ) ][] = $col;
			} else {
				self::problem( "{$plugin}: a column of {$table} is not a name: " . wp_json_encode( $col ) );
			}
		}
	}

	/** Every declaration, by plugin, as recorded. */
	public static function declared() {
		return self::$declared;
	}

	/** What the declarations could not say, in order. */
	public static function problems() {
		return self::$problems;
	}

	/** A short hash of every declaration, for a cache that outlives the request. */
	public static function hash() {
		if ( null === self::$hash ) {
			self::$hash = substr( md5( (string) wp_json_encode( self::$declared ) ), 0, 12 );
		}
		return self::$hash;
	}

	/** Option names declared whole, every plugin's. */
	public static function option_names() {
		return self::flat()['option_names'];
	}

	/** Option name shapes, as [ prefix, suffix ] pairs (Minn_Admin_DB's shape). */
	public static function option_shapes() {
		return self::flat()['option_shapes'];
	}

	/**
	 * The leaves named inside option rows: option name => path patterns,
	 * each matched (without case) against a leaf path as
	 * Minn_Admin_DB::credential_leaves() spells it ("/recaptcha/<site key>"),
	 * the named leaf or anything below it.
	 *
	 * @return array Name => regex[].
	 */
	public static function option_leaves() {
		return self::flat()['option_leaves'];
	}

	/**
	 * Declared user meta keys and shapes.
	 *
	 * @param bool $searched Only the keys the fence searches for (not user_tokens).
	 * @return array [ keys, [ prefix, suffix ] shapes ].
	 */
	public static function usermeta( $searched = false ) {
		$f = self::flat();
		return $searched ? $f['usermeta_searched'] : $f['usermeta_all'];
	}

	/**
	 * Declared credential columns: table without prefix (lower case) => columns.
	 *
	 * @param bool $searched Only the columns the fence searches for (not column_tokens).
	 */
	public static function columns( $searched = false ) {
		$f = self::flat();
		return $searched ? $f['columns_searched'] : $f['columns_all'];
	}

	private static function problem( $message ) {
		self::$problems[] = (string) $message;
		_doing_it_wrong( 'minn_admin_secrets', esc_html( $message ), 'Minn Admin 0.47.0' );
	}

	/** A name with one * as [ prefix, suffix ], or null for a plain name. */
	private static function shape( $name ) {
		$at = strpos( $name, '*' );
		return false === $at ? null : array( substr( $name, 0, $at ), substr( $name, $at + 1 ) );
	}

	private static function split( array $names, array &$exact, array &$shapes ) {
		foreach ( $names as $n ) {
			$s = self::shape( $n );
			if ( null === $s ) {
				$exact[] = $n;
			} else {
				$shapes[] = $s;
			}
		}
	}

	private static function merge_columns( array &$into, array $cols ) {
		foreach ( $cols as $table => $list ) {
			$into[ $table ] = array_values( array_unique( array_merge( $into[ $table ] ?? array(), $list ) ) );
		}
	}

	private static function flat() {
		if ( null !== self::$flat ) {
			return self::$flat;
		}
		$f = array(
			'option_names'      => array(),
			'option_shapes'     => array(),
			'option_leaves'     => array(),
			'usermeta_all'      => array( array(), array() ),
			'usermeta_searched' => array( array(), array() ),
			'columns_all'       => array(),
			'columns_searched'  => array(),
		);
		foreach ( self::$declared as $d ) {
			self::split( array_keys( $d['options'] ), $f['option_names'], $f['option_shapes'] );
			foreach ( $d['options'] as $name => $leaves ) {
				foreach ( $leaves as $leaf ) {
					// '*' stands for any key or run of keys (a site key may
					// hold a "/"); the leaf and everything below it count.
					$parts = array_map(
						static function ( $seg ) {
							return '*' === $seg ? '.+' : preg_quote( $seg, '#' );
						},
						explode( '/', trim( $leaf, '/' ) )
					);
					$f['option_leaves'][ $name ][] = '#^/' . implode( '/', $parts ) . '(/.*)?$#is';
				}
			}
			self::split( $d['usermeta'], $f['usermeta_searched'][0], $f['usermeta_searched'][1] );
			self::split( array_merge( $d['usermeta'], $d['user_tokens'] ), $f['usermeta_all'][0], $f['usermeta_all'][1] );
			self::merge_columns( $f['columns_searched'], $d['columns'] );
			self::merge_columns( $f['columns_all'], $d['columns'] );
			self::merge_columns( $f['columns_all'], $d['column_tokens'] );
		}
		$f['option_names'] = array_values( array_unique( $f['option_names'] ) );
		self::$flat        = $f;
		return $f;
	}
}

/**
 * Declare where a plugin keeps its credentials (see the class header).
 *
 * @param string $plugin Plugin slug.
 * @param array  $stores options, usermeta, user_tokens, columns, column_tokens.
 */
function minn_admin_secrets( $plugin, $stores ) {
	Minn_Admin_Kernel_Secrets::add( $plugin, $stores );
}
