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
	// Health checks: a table estimated bigger than this is not scanned at all
	// (the check reports "too large to check cheaply" instead of running).
	const SCAN_MAX_ROWS = 2000000;
	// Fragmentation warns only when free space is both a large SHARE and a
	// large ABSOLUTE amount — either alone is noise.
	const FRAG_SHARE = 0.25;
	const FRAG_MIN   = 52428800;
	// Health results are cached this long so the System page row is free.
	const HEALTH_TTL = 600;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		// On multisite, manage_options is a PER-SITE capability while the
		// database is shared network-wide: wp_users and wp_usermeta are common
		// tables, and every other tenant's wp_N_options sit in the same schema.
		// A subsite administrator reading them would cross a real boundary —
		// they cannot install plugins or edit files here either, which is why
		// the wp-config routes already gate on is_super_admin(). Same floor as
		// the System page it opens from: core's Site Health floor, which on a
		// network takes a super admin.
		$manage = array( 'Minn_Admin_REST', 'can_read_system' );
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
			'/db/health',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => $manage,
			)
		);
		register_rest_route(
			self::NS,
			'/db/structure',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'structure' ),
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
	/**
	 * Keep only the tables that belong to THIS site.
	 *
	 * table_index() already encodes the rule (this site's prefix plus the
	 * shared base-prefixed tables); the health checks that ran their own
	 * information_schema queries were the two places not using it.
	 *
	 * @param string[] $names Table names from information_schema.
	 * @return string[]
	 */
	private static function scope_to_site( $names ) {
		$mine = self::site_table_names();
		$out  = array();
		foreach ( (array) $names as $n ) {
			if ( isset( $mine[ strtolower( (string) $n ) ] ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	/**
	 * This site's table names as a lookup map (lowercased name => true).
	 *
	 * table_index() returns a LIST of row objects, so it cannot be probed with
	 * isset() directly.
	 *
	 * @return array<string, true>
	 */
	private static function site_table_names() {
		$map = array();
		foreach ( (array) self::table_index() as $t ) {
			$name = is_object( $t ) ? ( $t->name ?? '' ) : (string) $t;
			if ( '' !== $name ) {
				$map[ strtolower( (string) $name ) ] = true;
			}
		}
		return $map;
	}

	/**
	 * This site's tables, largest first, for callers outside the browser.
	 *
	 * Anything reporting on "the database" wants the same scoped set the
	 * browser lists. A caller running its own information_schema query with a
	 * plain prefix LIKE names a neighbouring install's tables, which is the
	 * rule table_index() exists to apply.
	 *
	 * @return object[] Rows carrying name, size and rows_est.
	 */
	public static function site_tables() {
		$rows = (array) self::table_index();
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) ( $b->size ?? 0 ) <=> (int) ( $a->size ?? 0 );
			}
		);
		return $rows;
	}

	private static function table_index() {
		// Memoized per request: resolve_table() runs once per route call, but
		// a health run resolves many tables back to back.
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS name, engine AS engine, table_rows AS rows_est,
					( data_length + index_length ) AS size, data_free AS data_free,
					auto_increment AS auto_increment, table_collation AS collation
				 FROM information_schema.TABLES
				 WHERE table_schema = %s AND table_type = %s
				 ORDER BY table_name ASC',
				DB_NAME,
				'BASE TABLE'
			)
		);
		$rows = is_array( $rows ) ? $rows : array();
		// MySQL 8 returns information_schema columns UPPERCASE unless every
		// one is explicitly aliased; MariaDB returns them as typed. The
		// aliases above force lowercase on both servers, and this fold keeps
		// any future bare column from reading as an undefined property on
		// MySQL (GH #28: engine / data_free / auto_increment warned there
		// while the MariaDB dev stack stayed silent).
		$rows = array_map(
			static function ( $t ) {
				return (object) array_change_key_case( (array) $t, CASE_LOWER );
			},
			$rows
		);

		// Scope to THIS install's own tables — always, not only on networks.
		// On single-site this matters just as much: shared-database hosting
		// (one DB, one prefix per install, the standard cPanel pattern) puts
		// another site's wp2_users — password hashes, reset tokens — in the
		// same schema, and an unscoped list would let a route name it. A
		// subsite only ever sees its own tables plus the truly shared ones.
		// ($wpdb->prefix is the CURRENT site's; base_prefix covers shared;
		// on single-site the two are equal.)
		$own  = strtolower( $wpdb->prefix );
		$base = strtolower( $wpdb->base_prefix );

		// A neighbour whose prefix EXTENDS ours passes a plain prefix test:
		// wp_ matches wp_staging_ and wp_blog2_ just as happily as wp_posts.
		// The wp_N_ rule below catches the multisite shape and nothing else,
		// so on shared-database hosting another install's users table was
		// listable. Let the schema name its own neighbours instead.
		//
		// Requiring options AND posts AND postmeta missed a co-tenant that had
		// lost one of them — a half-removed install, or one whose postmeta was
		// renamed — and its users table was admitted. The signal set is wider
		// now and the bar is TWO distinct core-shaped tables under one prefix:
		// a real install always has many, while a plugin's own table is
		// normally alone under its prefix (wp_myplugin_options stays visible).
		// Getting this wrong in the shy direction hides one row from a
		// diagnostics viewer; getting it wrong in the other direction shows a
		// neighbour's password hashes.
		$suffixes = array( 'options', 'posts', 'postmeta', 'users', 'usermeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy' );
		$needed   = 2;
		$seen     = array();
		foreach ( $rows as $t ) {
			$name = strtolower( (string) $t->name );
			foreach ( $suffixes as $suffix ) {
				$len = strlen( $name ) - strlen( $suffix );
				// Measure against the BASE prefix, which is what the admit
				// branch below tests. Measuring against this site's own prefix
				// missed every neighbour whose prefix is shorter than it: on a
				// network at wp_12_, a wp_x_ install never became a candidate
				// and its users table was admitted as a base-prefixed table.
				if ( $len < strlen( $base ) || substr( $name, $len ) !== $suffix ) {
					continue;
				}
				$candidate = substr( $name, 0, $len );
				// Our own prefixes are not neighbours: $own is this site's, and
				// the base prefix carries the shared and main-site tables.
				if ( $candidate === $own || $candidate === $base ) {
					continue;
				}
				$seen[ $candidate ][ $suffix ] = true;
			}
		}
		$foreign = array();
		foreach ( $seen as $candidate => $found ) {
			if ( count( $found ) >= $needed ) {
				$foreign[] = $candidate;
			}
		}

		$rows = array_values(
			array_filter(
				$rows,
				function ( $t ) use ( $own, $base, $foreign ) {
					$name = strtolower( (string) $t->name );
					foreach ( $foreign as $prefix ) {
						if ( 0 === strpos( $name, $prefix ) ) {
							// A neighbour's prefix can be the START of ours: a
							// site at wp_12_ beside one at wp_1 matches here on
							// every one of its own tables, and this runs before
							// the branch that admits them, so all of them would
							// vanish. Exempt our own tables in exactly that
							// case and no other.
							//
							// The exemption has to test all three parts. Asking
							// only whether the table starts with our prefix is
							// the same question as the admit branch below, and
							// on single-site (where our prefix IS the base
							// prefix) it is true of every neighbour that
							// extends it — which is every neighbour this rule
							// exists to catch, so the whole check collapsed and
							// a co-tenant's users table came back in with our
							// own. Our prefix must be strictly longer than the
							// neighbour's AND begin with it before the table's
							// own prefix means anything.
							if ( strlen( $own ) > strlen( $prefix )
								&& 0 === strpos( $own, $prefix )
								&& 0 === strpos( $name, $own ) ) {
								continue;
							}
							return false;
						}
					}
					if ( 0 === strpos( $name, $own ) ) {
						return true;
					}
					// Base-prefixed tables are shared only when they are
					// not some OTHER site's wp_N_ table.
					return 0 === strpos( $name, $base )
						&& ! preg_match( '/^' . preg_quote( $base, '/' ) . '\d+_/', $name );
				}
			)
		);

		$memo = $rows;
		return $memo;
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
	 * { name, type, nullable, key, extra, default, collation, comment } plus
	 * the primary-key column list.
	 */
	private static function columns_of( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from the information_schema whitelist, backtick-quoted.
		$raw     = $wpdb->get_results( 'SHOW FULL COLUMNS FROM ' . self::quote_ident( $table ) );
		$columns = array();
		$primary = array();
		foreach ( (array) $raw as $c ) {
			$columns[] = array(
				'name'      => $c->Field,
				'type'      => $c->Type,
				'nullable'  => 'YES' === $c->Null,
				'key'       => $c->Key,
				'extra'     => $c->Extra,
				'default'   => $c->Default,
				'collation' => $c->Collation,
				'comment'   => (string) $c->Comment,
			);
			if ( 'PRI' === $c->Key ) {
				$primary[] = $c->Field;
			}
		}
		return array( $columns, $primary );
	}

	/**
	 * Index metadata for a resolved table, grouped by index name and ordered
	 * PRIMARY first. Cardinality is the optimizer's ESTIMATE (same caveat as
	 * table_rows). Metadata only: never reads the table itself.
	 */
	private static function indexes_of( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier from the information_schema whitelist, backtick-quoted.
		$raw     = $wpdb->get_results( 'SHOW INDEX FROM ' . self::quote_ident( $table ) );
		$indexes = array();
		foreach ( (array) $raw as $i ) {
			$name = (string) $i->Key_name;
			if ( ! isset( $indexes[ $name ] ) ) {
				$indexes[ $name ] = array(
					'name'        => $name,
					'unique'      => '0' === (string) $i->Non_unique,
					'type'        => (string) $i->Index_type,
					'cardinality' => (int) $i->Cardinality,
					'columns'     => array(),
				);
			}
			// Sub_part is the prefix length on a partial index (meta_key(191)).
			$indexes[ $name ]['columns'][ (int) $i->Seq_in_index ] = $i->Column_name
				. ( null === $i->Sub_part ? '' : '(' . (int) $i->Sub_part . ')' );
		}
		foreach ( $indexes as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );
		$primary = isset( $indexes['PRIMARY'] ) ? array( $indexes['PRIMARY'] ) : array();
		unset( $indexes['PRIMARY'] );
		return array_merge( $primary, array_values( $indexes ) );
	}

	/**
	 * Columns whose value is a credential rather than data: password hashes,
	 * pending reset keys, login session tokens, application-password hashes
	 * and REST consumer secrets. The browser is read-only and admin-only, but
	 * a page of these is still an offline-cracking export nobody needs on
	 * screen. Emits { redacted, bytes } so the cell says what it is holding.
	 *
	 * @param string   $table Table name as resolved (prefix included).
	 * @param string   $col   Column name.
	 * @param string[] $row   The row keyed by column name (for meta_key).
	 * @return bool
	 */
	private static function is_secret_cell( $table, $col, $row ) {
		$base = self::secret_base( $table );
		if ( 'users' === $base ) {
			return in_array( $col, array( 'user_pass', 'user_activation_key' ), true );
		}
		$keyed = self::keyed_secret( $table, $col );
		if ( $keyed ) {
			$key = isset( $row[ $keyed[0] ] ) ? (string) $row[ $keyed[0] ] : '';
			return self::keyed_match( $key, $keyed );
		}
		if ( 'woocommerce_api_keys' === $base ) {
			return in_array( $col, array( 'consumer_key', 'consumer_secret' ), true );
		}
		// Gravity Forms hashes the key but keeps the REST API secret in
		// plain text, the same pair WooCommerce's table holds.
		if ( 'gf_rest_api_keys' === $base ) {
			return 'consumer_secret' === $col;
		}
		// Webhook signing secrets, Wordfence 2FA seeds/recovery codes and multisite signup
		// activation keys are credentials the same way.
		if ( 'wc_webhooks' === $base ) {
			return 'secret' === $col;
		}
		// The TOTP seed and the raw recovery codes (each bypasses 2FA once).
		if ( 'wfls_2fa_secrets' === $base ) {
			return in_array( $col, array( 'secret', 'recovery' ), true );
		}
		if ( 'signups' === $base ) {
			return 'activation_key' === $col;
		}
		return false;
	}

	/**
	 * A table's name without its site prefix, lowercased (case-folding MySQL
	 * setups report wp_wfconfig for Wordfence's wfConfig). The user tables
	 * answer 'users' / 'usermeta' whatever CUSTOM_USER_TABLE named them.
	 */
	private static function secret_base( $table ) {
		global $wpdb;
		if ( 0 === strcasecmp( $table, $wpdb->users ) ) {
			return 'users';
		}
		if ( 0 === strcasecmp( $table, $wpdb->usermeta ) ) {
			return 'usermeta';
		}
		$base = $table;
		foreach ( array_unique( array( $wpdb->prefix, $wpdb->base_prefix ) ) as $prefix ) {
			// Case-blind, as table_index() matches: a case-folding server
			// reports an upper-case prefix's tables in lower case.
			if ( '' !== $prefix && 0 === stripos( $base, $prefix ) ) {
				$base = substr( $base, strlen( $prefix ) );
				break;
			}
		}
		// A multisite blog prefix (wp_3_) leaves the numeric part behind.
		return strtolower( preg_replace( '/^\d+_/', '', $base ) );
	}

	/**
	 * Key/value tables where only SOME rows hold a credential: base =>
	 * { value column, key column, secret keys }.
	 */
	const KEYED_SECRETS = array(
		'usermeta'      => array( 'meta_value', 'meta_key', self::SECRET_USERMETA_KEYS ),
		// Core's keys and salts when they live in the database, not wp-config,
		// and the option rows that hold another vendor's credentials.
		'options'       => array( 'option_value', 'option_name', self::SECRET_OPTION_KEYS ),
		// The network's options. wp_salt() reads its salts from here on
		// multisite, and a network-activated vendor keeps here (through
		// get_site_option) the same credential rows it keeps in options on a
		// single site, so the two tables share one list: keyed_secret() hands
		// both secret_option_names().
		'sitemeta'      => array( 'meta_value', 'meta_key', self::SECRET_OPTION_KEYS ),
		// Licence rows the Licences screen reads outside the options table
		// (minn_admin_license_secret_options() covers the ones inside it):
		// Search & Filter Pro keeps its key in its own table, Brizy Pro in
		// a post's meta.
		'search_filter_options' => array( 'value', 'name', array( 'license-data' ) ),
		'postmeta'      => array( 'meta_value', 'meta_key', array( 'brizy-license-key' ) ),
		// Wordfence Login Security: remembered-device cookie keys (whoever
		// holds them can mark any user as past 2FA) and the reCAPTCHA secret.
		'wfls_settings' => array( 'value', 'name', array( 'shared-hash-secret', 'shared-symmetric-secret', 'recaptcha-secret' ) ),
		// Wordfence: the Central connection's keys and tokens, and the key
		// its encrypted settings are sealed with.
		'wfconfig'      => array( 'val', 'name', array( 'apiKey', 'longEncKey', 'wordfenceCentralSecretKey', 'wordfenceCentralAccessToken', 'wordfenceCentralUserSiteAccessToken', 'wordfenceCentralJWT', 'wordfenceCentralPK', 'wordfenceCentralUserSiteAuthGrant', 'encKey', 'currentCronKey' ) ),
	);

	/**
	 * For a keyed-secret table's value column: [ key column, secret keys,
	 * [ prefix, suffix ] name shapes ].
	 *
	 * @return array|null
	 */
	private static function keyed_secret( $table, $col ) {
		$base = self::secret_base( $table );
		if ( ! isset( self::KEYED_SECRETS[ $base ] ) || 0 !== strcasecmp( $col, self::KEYED_SECRETS[ $base ][0] ) ) {
			return null;
		}
		$keys   = self::KEYED_SECRETS[ $base ][2];
		$shapes = array();
		if ( 'options' === $base || 'sitemeta' === $base ) {
			$keys   = self::secret_option_names();
			$shapes = self::secret_option_shapes();
		}
		return array( self::KEYED_SECRETS[ $base ][1], $keys, $shapes );
	}

	/**
	 * Every option name whose row is a credential, for the options table and
	 * the network's sitemeta alike: the fixed list, the payment gateways'
	 * settings rows, the core Connectors' key rows and the rows the licences
	 * screen reads a key or token from.
	 *
	 * @return string[]
	 */
	private static function secret_option_names() {
		$licences = function_exists( 'minn_admin_license_secret_options' ) ? minn_admin_license_secret_options()[0] : array();
		return array_values( array_unique( array_merge( self::SECRET_OPTION_KEYS, self::gateway_option_names(), self::connector_option_names(), $licences ) ) );
	}

	/** The option name shapes redacted with them: the fixed shapes and the licences screen's. */
	private static function secret_option_shapes() {
		$licences = function_exists( 'minn_admin_license_secret_options' ) ? minn_admin_license_secret_options()[1] : array();
		return array_merge( self::SECRET_OPTION_SHAPES, $licences );
	}

	/**
	 * Option rows redacted by name shape, as [ prefix, suffix ] pairs. The
	 * value search leaves rows out with LIKE 'prefix%suffix' and keyed_match()
	 * tests the same pattern (case-sensitively, so a case-folding collation
	 * only widens what the search leaves out): no row is redacted on screen
	 * yet still searchable by value.
	 *
	 * Gravity SMTP keeps each mail connector's API key in its own
	 * gravitysmtp_<connector> row (and its licence in gravitysmtp_config);
	 * Minn's own adapter masks them. Core's Connectors keep a credential in
	 * connectors_{type}_{id}_api_key or
	 * connectors_{type}_{id}_application_password unless the connector names
	 * its own row, and the row outlives the registration: a deactivated
	 * provider plugin, or a site that turns AI support off, drops the
	 * connector from wp_get_connectors() and leaves its key behind.
	 */
	const SECRET_OPTION_SHAPES = array(
		array( 'gravitysmtp_', '' ),
		array( 'connectors_', '_api_key' ),
		array( 'connectors_', '_application_password' ),
	);

	/**
	 * The option each registered core Connector (WP 7.0+) keeps its API key or
	 * application password in. Core masks these on every wp/v2/settings
	 * response and Minn's /connectors model sends out only the last four
	 * characters; a connector may name its own row, which only the live
	 * registry knows.
	 *
	 * @return string[]
	 */
	private static function connector_option_names() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return array();
		}
		$names = array();
		try {
			foreach ( (array) wp_get_connectors() as $connector ) {
				$auth = is_array( $connector ) && isset( $connector['authentication'] ) && is_array( $connector['authentication'] ) ? $connector['authentication'] : array();
				if ( isset( $auth['setting_name'] ) && is_string( $auth['setting_name'] ) && '' !== $auth['setting_name'] ) {
					$names[] = $auth['setting_name'];
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $names;
	}

	/**
	 * Every registered WooCommerce payment gateway's settings row
	 * (woocommerce_stripe_settings and the like): live and test secret keys
	 * sit in them, which Store settings masks. Asked from WooCommerce so the
	 * shipping and email settings rows stay readable.
	 *
	 * @return string[]
	 */
	private static function gateway_option_names() {
		// The common gateways' rows whether or not WooCommerce loads them right
		// now (PayPal Standard only loads when used; a deactivated gateway
		// plugin leaves its row and keys behind).
		$names = array(
			'woocommerce_paypal_settings',
			'woocommerce_ppcp-gateway_settings',
			'woocommerce_stripe_settings',
			'woocommerce_woocommerce_payments_settings',
			'woocommerce_square_credit_card_settings',
			'woocommerce_braintree_credit_card_settings',
			'woocommerce_braintree_paypal_settings',
			'woocommerce_authorize_net_cim_credit_card_settings',
		);
		if ( ! function_exists( 'WC' ) ) {
			return $names;
		}
		try {
			$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
			foreach ( (array) $gateways as $gw ) {
				if ( is_object( $gw ) && method_exists( $gw, 'get_option_key' ) ) {
					$names[] = (string) $gw->get_option_key();
				}
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
		return $names;
	}

	/** Whether a keyed-secret row's key is one of the listed names or name shapes. */
	private static function keyed_match( $key, $keyed ) {
		if ( in_array( $key, $keyed[1], true ) ) {
			return true;
		}
		foreach ( isset( $keyed[2] ) ? (array) $keyed[2] : array() as $shape ) {
			list( $prefix, $suffix ) = $shape;
			// LIKE 'prefix%suffix' never lets the two overlap. An empty
			// prefix is tested apart: PHP 7.4's strpos() refuses an empty
			// needle (false and a warning) where 8.0 answers 0.
			if ( strlen( $key ) >= strlen( $prefix ) + strlen( $suffix )
				&& ( '' === $prefix || 0 === strpos( $key, $prefix ) )
				&& ( '' === $suffix || substr( $key, -strlen( $suffix ) ) === $suffix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * usermeta keys whose value is a credential: sessions, app passwords,
	 * TOTP seeds and emergency codes (core Two Factor, Sucuri, All In One
	 * Security), and the plaintext one-time sign-in tokens the One Time
	 * Login and WP Freighter routes mint (each signs in as that user).
	 */
	const SECRET_USERMETA_KEYS = array( 'session_tokens', '_application_passwords', '_two_factor_totp_key', '_two_factor_backup_codes', 'one_time_login_token', 'captaincore_login_token', 'sucuriscan_topt_secret_key', 'tfa_priv_key_64', 'simba_tfa_emergency_codes_64', 'tfa_trusted_devices', 'updraftcentral_login_key', 'wpmdb_licence_key' );

	/**
	 * Option and network option rows redacted whole: core's keys and salts,
	 * plus rows that hold another vendor's live credentials, which their own
	 * screens mask and Minn's adapters read for presence only. Jetpack's blog
	 * and per-user tokens (a user token signs REST requests as that user and
	 * outlives a password change), the WooCommerce.com account link, WP Mail
	 * SMTP's provider keys, the key that seals its SMTP password and the
	 * one-hour token its logged-out Connect endpoint is checked against, All In
	 * One Security's captcha secrets (the peer of Wordfence's redacted one),
	 * Post SMTP's settings and its plaintext OAuth access and refresh tokens,
	 * Freemius accounts (user, install and licence secret keys), the WPMU DEV
	 * key (the HMAC secret its Hub's remote commands are verified with) and
	 * Smush's validation cache, which is keyed by that same key.
	 */
	const SECRET_OPTION_KEYS = array( 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt', 'secret_key', 'jetpack_private_options', 'woocommerce_helper_data', 'wp_mail_smtp', 'wp_mail_smtp_mail_key', '_transient_wp_mail_smtp_connect_token', 'aio_wp_security_configs', 'postman_options', 'postman_auth_token', 'fs_accounts', 'wpmudev_apikey', 'wp_smush_api_auth', 'jetpack_secrets' );

	/**
	 * Whether a whole COLUMN can hold a credential on some row, so it must
	 * never be filtered or sorted on: a LIKE filter or an ORDER BY over the raw
	 * value answers "does this substring occur" / "which is larger" about a
	 * cell the viewer redacts, which recovers a hash or token a character at
	 * a time. usermeta.meta_value counts whole, since which rows are secret
	 * depends on meta_key.
	 *
	 * @param string $table Table name as resolved (prefix included).
	 * @param string $col   Column name.
	 * @return bool
	 */
	private static function is_secret_column( $table, $col ) {
		return null !== self::keyed_secret( $table, $col ) || self::is_secret_cell( $table, $col, array() );
	}

	private static function redacted_cell( $value ) {
		return array( 'redacted' => true, 'bytes' => strlen( (string) $value ) );
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

	/**
	 * GET /db/structure — columns and indexes for one table. Metadata only:
	 * information_schema plus SHOW, so it stays free on tables of any size.
	 */
	public static function structure( $request ) {
		$meta = self::resolve_table( $request->get_param( 'table' ) );
		if ( ! $meta ) {
			return new WP_Error( 'minn_db_no_table', __( 'Unknown table.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		list( $columns, $primary ) = self::columns_of( $meta->name );
		if ( ! $columns ) {
			return new WP_Error( 'minn_db_no_columns', __( 'Could not read the table structure.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'table'          => $meta->name,
				'engine'         => (string) $meta->engine,
				'collation'      => (string) $meta->collation,
				'rows'           => (int) $meta->rows_est,
				'size'           => (int) $meta->size,
				'size_human'     => size_format( (int) $meta->size, 1 ),
				'data_free'      => (int) $meta->data_free,
				'auto_increment' => null === $meta->auto_increment ? null : (int) $meta->auto_increment,
				'columns'        => $columns,
				'primary'        => $primary,
				'indexes'        => self::indexes_of( $meta->name ),
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
		// A credential column is never a sort key (ordering leaks its value).
		if ( ! in_array( $orderby, $names, true ) || self::is_secret_column( $meta->name, $orderby ) ) {
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
		// usermeta.meta_value (like the other key/value tables in
		// KEYED_SECRETS) is ordinary data on almost every row; it stays
		// searchable, with the credential rows left out of the match (below).
		$keyed = in_array( $fcol, $names, true ) ? self::keyed_secret( $meta->name, $fcol ) : null;
		if ( '' !== $fq && in_array( $fcol, $names, true ) && self::is_secret_column( $meta->name, $fcol ) && ! $keyed ) {
			return new WP_Error( 'minn_db_secret_filter', __( 'That column holds passwords or tokens, so it cannot be searched.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( '' !== $fq && in_array( $fcol, $names, true ) ) {
			$where = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier whitelisted + backtick-quoted above.
				' WHERE ' . self::quote_ident( $fcol ) . ' LIKE %s',
				'%' . $wpdb->esc_like( $fq ) . '%'
			);
			if ( $keyed ) {
				$keys   = $keyed[1];
				$where .= $wpdb->prepare( ' AND ' . self::quote_ident( $keyed[0] ) . ' NOT IN (' . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')', $keys ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( isset( $keyed[2] ) ? (array) $keyed[2] : array() as $shape ) {
					$where .= $wpdb->prepare( ' AND ' . self::quote_ident( $keyed[0] ) . ' NOT LIKE %s', $wpdb->esc_like( $shape[0] ) . '%' . $wpdb->esc_like( $shape[1] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
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
			$assoc = array_combine( $names, array_slice( array_values( $r ), 0, count( $names ) ) ) ?: array();
			foreach ( $r as $i => $v ) {
				$col     = isset( $names[ $i ] ) ? $names[ $i ] : '';
				$cells[] = null !== $v && '' !== (string) $v && self::is_secret_cell( $meta->name, $col, $assoc ) ? self::redacted_cell( $v ) : self::cell( $v, self::CELL_CAP_LIST );
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
		if ( ! $primary || ! is_array( $pk ) || array_diff( array_keys( $pk ), $primary ) || count( $pk ) !== count( $primary )
			|| count( array_filter( $pk, 'is_scalar' ) ) !== count( $pk ) ) {
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
			$v       = array_key_exists( $c['name'], $raw ) ? $raw[ $c['name'] ] : null;
			$cells[] = null !== $v && '' !== (string) $v && self::is_secret_cell( $meta->name, $c['name'], $raw ) ? self::redacted_cell( $v ) : self::cell( $v, self::CELL_CAP_DETAIL );
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

	/*
	 * ===== Health checks =====
	 *
	 * Every statement below is AUTHORED: no request parameter reaches any of
	 * this SQL, so the checks add no identifier or injection surface over the
	 * viewer's existing envelope. Scans are bounded by the same COUNT_CAP
	 * subquery idiom as rows(), and skipped entirely on tables estimated over
	 * SCAN_MAX_ROWS, so this stays polite on the sites that need it most.
	 *
	 * The only remedy a check ever offers is a WP-CLI command to COPY. Minn
	 * runs no cleanup itself: read-only is the product (docs/native-editors.md).
	 */

	/** Bounded COUNT(*) over an authored FROM clause. Returns [ count, capped ]. */
	private static function bounded_count( $from_sql ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- authored SQL; no request input reaches this.
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$from_sql} LIMIT " . ( self::COUNT_CAP + 1 ) . ') x' );
		return array( min( $n, self::COUNT_CAP ), $n > self::COUNT_CAP );
	}

	/** Estimated rows for a table, or -1 when it does not exist. */
	private static function rows_est( $name ) {
		$t = self::resolve_table( $name );
		return $t ? (int) $t->rows_est : -1;
	}

	/**
	 * Shape one scan-backed check. Skips the scan (severity info) when the
	 * table is missing or estimated too large to count cheaply.
	 */
	private static function scan_check( $args ) {
		$est = self::rows_est( $args['table'] );
		if ( $est < 0 ) {
			return null; // table absent: the check simply does not apply here.
		}
		$check = array(
			'label'   => $args['label'],
			'table'   => $args['table'],
			'command' => isset( $args['command'] ) ? $args['command'] : null,
		);
		if ( $est > self::SCAN_MAX_ROWS ) {
			$check['severity'] = 'info';
			$check['value']    = __( 'Not checked', 'minn-admin' );
			$check['detail']   = __( 'This table is too large to count cheaply, so Minn does not scan it. Run the command below to check it yourself.', 'minn-admin' );
			return $check;
		}
		list( $count, $capped ) = self::bounded_count( $args['from'] );
		$check['severity']      = $count > 0 ? $args['when_found'] : 'ok';
		$check['value']         = $capped
			? number_format_i18n( $count ) . '+'
			: number_format_i18n( $count );
		$check['detail']        = $count > 0 ? $args['detail'] : $args['clear'];
		if ( 0 === $count ) {
			$check['command'] = null;
		}
		return $check;
	}

	/**
	 * GET /db/health — fixed read-only checks over this install's storage.
	 * Cached briefly so the System page's summary row costs nothing; ?refresh=1
	 * recomputes (a recompute is only ever more reads).
	 */
	public static function health( $request = null ) {
		$fresh = $request ? rest_sanitize_boolean( $request->get_param( 'refresh' ) ) : false;
		return rest_ensure_response( self::health_data( $fresh ) );
	}

	/** Cached check run. Shared by the route and the System page row. */
	public static function health_data( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( 'minn_admin_db_health' );
			if ( is_array( $cached ) && isset( $cached['checks'] ) ) {
				return $cached;
			}
		}
		$out = self::run_checks();
		set_transient( 'minn_admin_db_health', $out, self::HEALTH_TTL );
		return $out;
	}

	/**
	 * The System page's one-line summary of this surface, or null when the
	 * checks cannot be read. Shape matches the other system checks.
	 */
	public static function system_check() {
		try {
			$data = self::health_data();
		} catch ( \Throwable $e ) {
			return null;
		}
		$warn = (int) $data['warnings'];
		return array(
			'label'  => __( 'Database hygiene', 'minn-admin' ),
			'status' => $warn > 0 ? 'warn' : 'pass',
			'goto'   => 'dbhealth',
			'detail' => $warn > 0
				? sprintf(
					/* translators: %s: number of database checks needing attention. */
					_n(
						'%s storage check needs attention',
						'%s storage checks need attention',
						$warn,
						'minn-admin'
					),
					number_format_i18n( $warn )
				)
				: __( 'Orphaned rows, indexes and engines all look healthy', 'minn-admin' ),
		);
	}

	private static function run_checks() {
		$checks = array();
		foreach ( self::check_list() as $id => $fn ) {
			try {
				$c = call_user_func( $fn );
			} catch ( \Throwable $e ) {
				continue; // one odd schema can never break the page
			}
			if ( is_array( $c ) ) {
				$c['id']  = $id;
				$checks[] = $c;
			}
		}
		$rank = array(
			'warn' => 0,
			'info' => 1,
			'ok'   => 2,
		);
		usort(
			$checks,
			function ( $a, $b ) use ( $rank ) {
				$ra = isset( $rank[ $a['severity'] ] ) ? $rank[ $a['severity'] ] : 3;
				$rb = isset( $rank[ $b['severity'] ] ) ? $rank[ $b['severity'] ] : 3;
				return $ra === $rb ? strcmp( $a['label'], $b['label'] ) : $ra - $rb;
			}
		);
		$warn = 0;
		foreach ( $checks as $c ) {
			if ( 'warn' === $c['severity'] ) {
				$warn++;
			}
		}
		return array(
			'checks'   => $checks,
			'warnings' => $warn,
			'scan_max' => self::SCAN_MAX_ROWS,
		);
	}

	/** The registry. Each entry returns a check array, or null to skip. */
	private static function check_list() {
		global $wpdb;
		return array(

			'no_primary_key' => function () use ( $wpdb ) {
				$rows = $wpdb->get_col(
					$wpdb->prepare(
						'SELECT t.table_name FROM information_schema.TABLES t
						 LEFT JOIN information_schema.STATISTICS s
						   ON s.table_schema = t.table_schema AND s.table_name = t.table_name
						   AND s.index_name = %s
						 WHERE t.table_schema = %s AND t.table_type = %s AND s.index_name IS NULL
						 ORDER BY t.table_name ASC',
						'PRIMARY',
						DB_NAME,
						'BASE TABLE'
					)
				);
				// Scope to THIS site's tables. table_schema = DB_NAME alone
				// enumerates every tenant on a network, and the names are
				// imploded into a user-visible detail string.
				$rows = self::scope_to_site( is_array( $rows ) ? $rows : array() );
				return array(
					'label'    => __( 'Tables without a primary key', 'minn-admin' ),
					'severity' => $rows ? 'warn' : 'ok',
					'value'    => number_format_i18n( count( $rows ) ),
					'detail'   => $rows
						? sprintf(
							/* translators: %s: comma-separated list of database table names. */
							__( 'These tables have no primary key, which breaks row-level replication and confuses backup and migration tools: %s', 'minn-admin' ),
							implode( ', ', array_slice( $rows, 0, 8 ) )
						)
						: __( 'Every table has a primary key.', 'minn-admin' ),
				);
			},

			'storage_engine' => function () {
				$old = array();
				foreach ( self::table_index() as $t ) {
					if ( $t->engine && 0 !== strcasecmp( (string) $t->engine, 'InnoDB' ) ) {
						$old[] = $t->name . ' (' . $t->engine . ')';
					}
				}
				return array(
					'label'    => __( 'Storage engine', 'minn-admin' ),
					'severity' => $old ? 'warn' : 'ok',
					'value'    => $old ? number_format_i18n( count( $old ) ) : 'InnoDB',
					'detail'   => $old
						? sprintf(
							/* translators: %s: comma-separated list of tables and their storage engines. */
							__( 'These tables do not use InnoDB, so they miss row-level locking and crash recovery: %s', 'minn-admin' ),
							implode( ', ', array_slice( $old, 0, 8 ) )
						)
						: __( 'Every table uses InnoDB.', 'minn-admin' ),
					'command'  => $old ? array(
						'label' => __( 'Convert a table', 'minn-admin' ),
						'text'  => 'wp db query "ALTER TABLE ' . preg_replace( '/ .*/', '', $old[0] ) . ' ENGINE=InnoDB"',
						'hint'  => __( 'Back up first. Converting locks the table while it runs.', 'minn-admin' ),
					) : null,
				);
			},

			'fragmentation' => function () {
				$worst = null;
				$total = 0;
				foreach ( self::table_index() as $t ) {
					$free   = (int) $t->data_free;
					$size   = (int) $t->size;
					$total += $free;
					if ( $free > self::FRAG_MIN && $size > 0 && ( $free / $size ) > self::FRAG_SHARE ) {
						if ( ! $worst || $free > (int) $worst->data_free ) {
							$worst = $t;
						}
					}
				}
				return array(
					'label'    => __( 'Reclaimable space', 'minn-admin' ),
					'severity' => $worst ? 'warn' : 'ok',
					'value'    => size_format( $total, 1 ),
					'table'    => $worst ? $worst->name : null,
					'detail'   => $worst
						? sprintf(
							/* translators: 1: table name, 2: reclaimable size (e.g. "82.0 MB"). */
							__( '%1$s alone is holding %2$s of free space inside its own file. Optimising rewrites the table and returns it to the disk.', 'minn-admin' ),
							$worst->name,
							size_format( (int) $worst->data_free, 1 )
						)
						: __( 'No table is holding a significant amount of reclaimable space.', 'minn-admin' ),
					'command'  => $worst ? array(
						'label' => __( 'Optimise tables', 'minn-admin' ),
						'text'  => 'wp db optimize',
						'hint'  => __( 'Back up first. Optimising locks each table while it runs.', 'minn-admin' ),
					) : null,
				);
			},

			'postmeta_index' => function () use ( $wpdb ) {
				$meta = self::resolve_table( $wpdb->postmeta );
				if ( ! $meta ) {
					return null;
				}
				$composite = false;
				foreach ( self::indexes_of( $meta->name ) as $i ) {
					$cols = $i['columns'];
					if ( count( $cols ) >= 2 && 0 === stripos( $cols[0], 'post_id' ) && 0 === stripos( $cols[1], 'meta_key' ) ) {
						$composite = true;
					}
				}
				return array(
					'label'    => __( 'Post meta lookup index', 'minn-admin' ),
					'table'    => $meta->name,
					'severity' => $composite ? 'ok' : 'warn',
					'value'    => $composite ? __( 'Present', 'minn-admin' ) : __( 'Missing', 'minn-admin' ),
					'detail'   => $composite
						? __( 'A combined post_id and meta_key index is present, so meta lookups stay fast.', 'minn-admin' )
						: __( 'Lookups by post and meta key fall back to two separate indexes. On sites with heavy meta use (WooCommerce, event and booking plugins) a combined index is a large, low-risk speed win.', 'minn-admin' ),
					'command'  => $composite ? null : array(
						'label' => __( 'Add the index', 'minn-admin' ),
						'text'  => 'wp db query "ALTER TABLE ' . $meta->name . ' ADD INDEX post_id_meta_key (post_id, meta_key(191))"',
						'hint'  => __( 'Back up first. Adding an index locks the table briefly.', 'minn-admin' ),
					),
				);
			},

			'autoincrement_headroom' => function () use ( $wpdb ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT t.table_name AS name, t.auto_increment AS next_id, c.column_type AS col_type
						 FROM information_schema.TABLES t
						 JOIN information_schema.COLUMNS c
						   ON c.table_schema = t.table_schema AND c.table_name = t.table_name
						   AND c.extra LIKE %s
						 WHERE t.table_schema = %s AND t.auto_increment IS NOT NULL',
						'%auto_increment%',
						DB_NAME
					)
				);
				$limits = array(
					'tinyint'   => array( 127, 255 ),
					'smallint'  => array( 32767, 65535 ),
					'mediumint' => array( 8388607, 16777215 ),
					'int'       => array( 2147483647, 4294967295 ),
					'bigint'    => array( 9223372036854775807, 18446744073709551615 ),
				);
				$tight = array();
				$mine  = self::site_table_names();
				foreach ( (array) $rows as $r ) {
					// Same prefix scoping as the check above.
					if ( ! isset( $mine[ strtolower( (string) $r->name ) ] ) ) {
						continue;
					}
					$type = strtolower( (string) $r->col_type );
					$base = preg_replace( '/\(.*/', '', $type );
					$base = trim( preg_replace( '/\s.*/', '', $base ) );
					if ( ! isset( $limits[ $base ] ) ) {
						continue;
					}
					$max = false !== strpos( $type, 'unsigned' ) ? $limits[ $base ][1] : $limits[ $base ][0];
					if ( $max > 0 && ( (float) $r->next_id / (float) $max ) > 0.8 ) {
						$tight[] = $r->name;
					}
				}
				return array(
					'label'    => __( 'Auto-increment headroom', 'minn-admin' ),
					'severity' => $tight ? 'warn' : 'ok',
					'value'    => $tight ? number_format_i18n( count( $tight ) ) : __( 'Healthy', 'minn-admin' ),
					'table'    => $tight ? $tight[0] : null,
					'detail'   => $tight
						? sprintf(
							/* translators: %s: comma-separated list of database table names. */
							__( 'These tables are within 20 percent of the largest id their column type can store. Once the ceiling is reached, new rows stop being written: %s', 'minn-admin' ),
							implode( ', ', array_slice( $tight, 0, 8 ) )
						)
						: __( 'No table is close to exhausting its id column.', 'minn-admin' ),
				);
			},

			'orphan_postmeta' => function () use ( $wpdb ) {
				return self::scan_check(
					array(
						'table'      => $wpdb->postmeta,
						'label'      => __( 'Orphaned post meta', 'minn-admin' ),
						'from'       => "{$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL",
						'when_found' => 'warn',
						'detail'     => __( 'These meta rows belong to posts that no longer exist. They are dead weight in every query that touches post meta.', 'minn-admin' ),
						'clear'      => __( 'Every meta row belongs to a post that still exists.', 'minn-admin' ),
						'command'    => array(
							'label' => __( 'Delete them', 'minn-admin' ),
							'text'  => 'wp db query "DELETE pm FROM ' . $wpdb->postmeta . ' pm LEFT JOIN ' . $wpdb->posts . ' p ON p.ID = pm.post_id WHERE p.ID IS NULL"',
							'hint'  => __( 'Back up first. This permanently deletes rows.', 'minn-admin' ),
						),
					)
				);
			},

			'orphan_usermeta' => function () use ( $wpdb ) {
				return self::scan_check(
					array(
						'table'      => $wpdb->usermeta,
						'label'      => __( 'Orphaned user meta', 'minn-admin' ),
						'from'       => "{$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE u.ID IS NULL",
						'when_found' => 'warn',
						'detail'     => __( 'These meta rows belong to users who no longer exist, usually left behind by a plugin that removed accounts directly.', 'minn-admin' ),
						'clear'      => __( 'Every meta row belongs to a user who still exists.', 'minn-admin' ),
						'command'    => array(
							'label' => __( 'Delete them', 'minn-admin' ),
							'text'  => 'wp db query "DELETE um FROM ' . $wpdb->usermeta . ' um LEFT JOIN ' . $wpdb->users . ' u ON u.ID = um.user_id WHERE u.ID IS NULL"',
							'hint'  => __( 'Back up first. This permanently deletes rows.', 'minn-admin' ),
						),
					)
				);
			},

			'orphan_term_relationships' => function () use ( $wpdb ) {
				return self::scan_check(
					array(
						'table'      => $wpdb->term_relationships,
						'label'      => __( 'Orphaned term relationships', 'minn-admin' ),
						'from'       => "{$wpdb->term_relationships} tr LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_taxonomy_id IS NULL",
						'when_found' => 'warn',
						'detail'     => __( 'These rows link content to categories or tags that no longer exist.', 'minn-admin' ),
						'clear'      => __( 'Every relationship points at a term that still exists.', 'minn-admin' ),
						'command'    => array(
							'label' => __( 'Delete them', 'minn-admin' ),
							'text'  => 'wp db query "DELETE tr FROM ' . $wpdb->term_relationships . ' tr LEFT JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_taxonomy_id IS NULL"',
							'hint'  => __( 'Back up first. This permanently deletes rows.', 'minn-admin' ),
						),
					)
				);
			},

			'revisions' => function () use ( $wpdb ) {
				return self::scan_check(
					array(
						'table'      => $wpdb->posts,
						'label'      => __( 'Stored revisions', 'minn-admin' ),
						'from'       => $wpdb->prepare( "{$wpdb->posts} WHERE post_type = %s", 'revision' ),
						'when_found' => 'info',
						'detail'     => __( 'Revisions are the editor safety net, so a large number is normal and not a fault. Trimming them is only worthwhile if the posts table has grown unwieldy.', 'minn-admin' ),
						'clear'      => __( 'No revisions are stored.', 'minn-admin' ),
						'command'    => array(
							'label' => __( 'Delete all revisions', 'minn-admin' ),
							'text'  => 'wp post delete $(wp post list --post_type=revision --format=ids) --force',
							'hint'  => __( 'Back up first. This removes every saved revision permanently.', 'minn-admin' ),
						),
					)
				);
			},

			'spam_comments' => function () use ( $wpdb ) {
				return self::scan_check(
					array(
						'table'      => $wpdb->comments,
						'label'      => __( 'Spam and trashed comments', 'minn-admin' ),
						'from'       => $wpdb->prepare( "{$wpdb->comments} WHERE comment_approved IN ( %s, %s )", 'spam', 'trash' ),
						'when_found' => 'info',
						'detail'     => __( 'Spam and trashed comments stay in the database until they are emptied. WordPress clears them on its own after a while.', 'minn-admin' ),
						'clear'      => __( 'No spam or trashed comments are waiting.', 'minn-admin' ),
						'command'    => array(
							'label' => __( 'Delete spam', 'minn-admin' ),
							'text'  => 'wp comment delete $(wp comment list --status=spam --format=ids) --force',
							'hint'  => __( 'Back up first. This permanently deletes the comments.', 'minn-admin' ),
						),
					)
				);
			},

			'wc_sessions' => function () use ( $wpdb ) {
				$table = $wpdb->prefix . 'woocommerce_sessions';
				return self::scan_check(
					array(
						'table'      => $table,
						'label'      => __( 'Expired WooCommerce sessions', 'minn-admin' ),
						'from'       => "{$table} WHERE session_expiry < UNIX_TIMESTAMP()",
						'when_found' => 'warn',
						'detail'     => __( 'Expired cart sessions should be cleared by a scheduled WooCommerce job. A large backlog usually means that job has stopped running.', 'minn-admin' ),
						'clear'      => __( 'No expired sessions are left behind.', 'minn-admin' ),
						'command'    => array(
							'label' => __( 'Delete expired sessions', 'minn-admin' ),
							'text'  => 'wp db query "DELETE FROM ' . $table . ' WHERE session_expiry < UNIX_TIMESTAMP()"',
							'hint'  => __( 'Back up first. Shoppers with an expired cart lose it, which has already happened by definition.', 'minn-admin' ),
						),
					)
				);
			},

			'action_scheduler' => function () use ( $wpdb ) {
				$table = $wpdb->prefix . 'actionscheduler_actions';
				return self::scan_check(
					array(
						'table'      => $table,
						'label'      => __( 'Action Scheduler backlog', 'minn-admin' ),
						'from'       => $wpdb->prepare( "{$table} WHERE status IN ( %s, %s )", 'pending', 'failed' ),
						'when_found' => 'info',
						'detail'     => __( 'Pending and failed background jobs. A steady backlog is normal on a busy store; a very large one suggests the queue is not draining.', 'minn-admin' ),
						'clear'      => __( 'No pending or failed background jobs.', 'minn-admin' ),
						'command'    => array(
							'label' => __( 'Clean the queue', 'minn-admin' ),
							'text'  => 'wp action-scheduler clean',
							'hint'  => __( 'Removes finished actions. Pending work is left alone.', 'minn-admin' ),
						),
					)
				);
			},
		);
	}
}
