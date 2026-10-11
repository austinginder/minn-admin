<?php
/**
 * Write fences: the check under every Minn route.
 *
 * Every adapter gates its own routes, and each gate is one more place to get
 * a vendor's rule wrong. Whatever a gate lets through, a few writes would
 * hand the site to whoever made them: opening registration, granting a
 * powerful role, switching plugins on, moving the site's address, taking
 * over an administrator's sign-in, naming a network administrator. While a
 * minn-admin/v1 request runs, those writes go ahead only when the caller
 * holds the capability WordPress asks for the same change on its own
 * screens. Otherwise the stored value stays and the request answers 403.
 *
 * The line is drawn at power, not at every account change: vendors change
 * roles as a side effect (a subscription that activates makes its customer
 * a subscriber), and those grants carry nothing that runs the site.
 *
 * The fence sees writes through the options, user and metadata APIs, which
 * is the path every adapter and every vendor save handler takes. Raw SQL (a
 * migration plugin importing a database) is outside it, as it is outside
 * every other hook.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Fences {

	/**
	 * Options that hand over the site, and what changing each asks.
	 * 'network' is manage_options and, on a network, its owner: WordPress
	 * gives a site's administrators no screen for these there.
	 */
	const OPTIONS = array(
		'users_can_register' => 'manage_options',
		'default_role'       => 'manage_options',
		'admin_email'        => 'manage_options',
		'new_admin_email'    => 'manage_options',
		'siteurl'            => 'network',
		'home'               => 'network',
		'upload_path'        => 'network',
		'upload_url_path'    => 'network',
		'active_plugins'     => 'activate_plugins',
		'uninstall_plugins'  => 'activate_plugins',
		'template'           => 'switch_themes',
		'stylesheet'         => 'switch_themes',
	);

	/** Network settings only the network's owner changes. */
	const SITE_OPTIONS = array(
		'site_admins',
		'active_sitewide_plugins',
		'registration',
		'add_new_users',
		'allowedthemes',
		'upload_filetypes',
		'fileupload_maxk',
		'illegal_names',
		'menu_items',
	);

	/**
	 * Capabilities that run the site rather than work inside it: settings,
	 * code, accounts, the network, unfiltered markup, the store's payments.
	 * A role carrying any of them is a powerful role.
	 */
	const POWERS = array(
		'manage_options',
		'activate_plugins',
		'install_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'install_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'switch_themes',
		'update_core',
		'create_users',
		'edit_users',
		'delete_users',
		'promote_users',
		'unfiltered_html',
		'unfiltered_upload',
		'import',
		'manage_network',
		'manage_sites',
		'manage_network_users',
		'manage_network_plugins',
		'manage_network_themes',
		'manage_network_options',
		'upgrade_network',
		'setup_network',
		'manage_woocommerce',
	);

	/** How many minn-admin/v1 requests are running (a route may dispatch another). */
	private static $depth = 0;

	/** What the running request was refused. */
	private static $refused = array();

	/** Options a refused delete will put back, with their autoload. */
	private static $deleting = array();

	/** Set while the fence itself puts a value back. */
	private static $restoring = false;

	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'arm' ), 0, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'disarm' ), PHP_INT_MAX, 3 );

		add_filter( 'pre_update_option', array( __CLASS__, 'option_update' ), PHP_INT_MAX, 3 );
		add_action( 'added_option', array( __CLASS__, 'option_added' ), 0, 1 );
		add_action( 'delete_option', array( __CLASS__, 'option_delete' ), 0, 1 );
		add_action( 'deleted_option', array( __CLASS__, 'option_deleted' ), PHP_INT_MAX, 1 );
		foreach ( self::SITE_OPTIONS as $name ) {
			add_filter( "pre_update_site_option_{$name}", array( __CLASS__, 'site_update' ), PHP_INT_MAX, 3 );
			add_action( "add_site_option_{$name}", array( __CLASS__, 'site_added' ), 0, 3 );
			add_action( "pre_delete_site_option_{$name}", array( __CLASS__, 'site_delete' ), 0, 2 );
			add_action( "delete_site_option_{$name}", array( __CLASS__, 'site_deleted' ), PHP_INT_MAX, 2 );
		}

		add_filter( 'add_user_metadata', array( __CLASS__, 'meta_add' ), PHP_INT_MAX, 4 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'meta_update' ), PHP_INT_MAX, 4 );
		add_filter( 'delete_user_metadata', array( __CLASS__, 'meta_delete' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'user_row' ), PHP_INT_MAX, 3 );
	}

	/** Whether a fence is up: a minn-admin/v1 request is running. */
	public static function armed() {
		return self::$depth > 0 && ! self::$restoring;
	}

	public static function arm( $response, $handler, $request ) {
		if ( self::ours( $request ) ) {
			if ( 0 === self::$depth ) {
				self::$refused = array();
			}
			++self::$depth;
		}
		return $response;
	}

	public static function disarm( $response, $handler, $request ) {
		if ( ! self::ours( $request ) || self::$depth < 1 ) {
			return $response;
		}
		--self::$depth;
		if ( self::$depth > 0 || ! self::$refused ) {
			return $response;
		}
		$names         = array_values( array_unique( self::$refused ) );
		self::$refused = array();
		error_log( sprintf( 'Minn Admin refused a write to %s during %s %s by user %d', implode( ', ', $names ), $request->get_method(), $request->get_route(), get_current_user_id() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return new WP_Error(
			'minn_write_fenced',
			__( 'Part of this change was not saved: it would have changed who can run this site, which your account can’t do.', 'minn-admin' ),
			array(
				'status' => 403,
				'fenced' => $names,
			)
		);
	}

	public static function option_update( $value, $option, $old_value ) {
		if ( ! self::armed() || self::option_allowed( $option ) ) {
			return $value;
		}
		if ( $value !== $old_value && maybe_serialize( $value ) !== maybe_serialize( $old_value ) ) {
			self::$refused[] = $option;
		}
		return $old_value;
	}

	public static function option_added( $option ) {
		if ( ! self::armed() || self::option_allowed( $option ) ) {
			return;
		}
		self::$refused[] = $option;
		self::restore(
			function () use ( $option ) {
				delete_option( $option );
			}
		);
	}

	public static function option_delete( $option ) {
		if ( ! self::armed() || self::option_allowed( $option ) ) {
			return;
		}
		global $wpdb;
		$autoload                   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		$autoloaded                 = function_exists( 'wp_autoload_values_to_autoload' ) ? wp_autoload_values_to_autoload() : array( 'yes' );
		self::$deleting[ $option ] = array( get_option( $option ), in_array( $autoload, $autoloaded, true ) );
	}

	public static function option_deleted( $option ) {
		if ( ! isset( self::$deleting[ $option ] ) ) {
			return;
		}
		list( $value, $autoload ) = self::$deleting[ $option ];
		unset( self::$deleting[ $option ] );
		self::$refused[] = $option;
		self::restore(
			function () use ( $option, $value, $autoload ) {
				add_option( $option, $value, '', $autoload );
			}
		);
	}

	public static function site_update( $value, $old_value, $option ) {
		if ( ! self::armed() || self::site_allowed() ) {
			return $value;
		}
		if ( maybe_serialize( $value ) !== maybe_serialize( $old_value ) ) {
			self::$refused[] = $option;
		}
		return $old_value;
	}

	public static function site_added( $option, $value, $network_id ) {
		if ( ! self::armed() || self::site_allowed() ) {
			return;
		}
		self::$refused[] = $option;
		self::restore(
			function () use ( $option, $network_id ) {
				delete_network_option( $network_id, $option );
			}
		);
	}

	public static function site_delete( $option, $network_id ) {
		if ( ! self::armed() || self::site_allowed() ) {
			return;
		}
		self::$deleting[ 'site:' . $network_id . ':' . $option ] = array( get_network_option( $network_id, $option ), false );
	}

	public static function site_deleted( $option, $network_id ) {
		$key = 'site:' . $network_id . ':' . $option;
		if ( ! isset( self::$deleting[ $key ] ) ) {
			return;
		}
		$value = self::$deleting[ $key ][0];
		unset( self::$deleting[ $key ] );
		self::$refused[] = $option;
		self::restore(
			function () use ( $option, $value, $network_id ) {
				add_network_option( $network_id, $option, $value );
			}
		);
	}

	public static function meta_add( $check, $user_id, $key, $value ) {
		return self::meta_write( $check, (int) $user_id, (string) $key, $value );
	}

	public static function meta_update( $check, $user_id, $key, $value ) {
		return self::meta_write( $check, (int) $user_id, (string) $key, $value );
	}

	public static function meta_delete( $check, $user_id, $key ) {
		return self::meta_write( $check, (int) $user_id, (string) $key, array() );
	}

	/**
	 * Another account's sign-in (login, password, email) stays as stored
	 * when that account is powerful and the caller may not edit it: a new
	 * email address is a password reset away from the account.
	 */
	public static function user_row( $data, $update, $user_id ) {
		if ( ! $update || ! $user_id || ! self::armed() || current_user_can( 'edit_user', $user_id ) ) {
			return $data;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ( ! is_super_admin( $user_id ) && ! self::empowers( (array) $user->caps ) ) ) {
			return $data;
		}
		foreach ( array( 'user_login', 'user_pass', 'user_email' ) as $field ) {
			if ( isset( $data[ $field ] ) && (string) $data[ $field ] !== (string) $user->$field ) {
				$data[ $field ]  = $user->$field;
				self::$refused[] = $field;
			}
		}
		return $data;
	}

	/** Whether a request is one of Minn's own. */
	private static function ours( $request ) {
		return $request instanceof WP_REST_Request && 0 === strpos( $request->get_route(), '/minn-admin/v1' );
	}

	private static function option_allowed( $option ) {
		global $wpdb;
		if ( isset( self::OPTIONS[ $option ] ) ) {
			$need = self::OPTIONS[ $option ];
			if ( 'network' === $need ) {
				return current_user_can( 'manage_options' ) && Minn_Admin::network_owner();
			}
			return current_user_can( $need );
		}
		// The role definitions themselves: adding a capability to a role
		// grants it to everyone holding the role.
		if ( preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?user_roles$/', (string) $option ) ) {
			return current_user_can( 'manage_options' ) && current_user_can( 'promote_users' );
		}
		return true;
	}

	private static function site_allowed() {
		return is_multisite() ? Minn_Admin::network_owner() : current_user_can( 'manage_options' );
	}

	/** Which access record a user meta key is: 'caps', 'level' or ''. */
	private static function access_key( $key ) {
		global $wpdb;
		if ( ! preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?(capabilities|user_level)$/', $key, $m ) ) {
			return '';
		}
		return 'capabilities' === $m[2] ? 'caps' : 'level';
	}

	private static function meta_write( $check, $user_id, $key, $value ) {
		if ( null !== $check || ! self::armed() ) {
			return $check;
		}
		$kind = self::access_key( $key );
		if ( '' === $kind ) {
			return $check;
		}
		$ok = 'caps' === $kind
			? self::may_change_caps( $user_id, $key, $value )
			: ( 0 === (int) ( is_scalar( $value ) ? $value : 0 ) || self::may_promote( $user_id ) );
		if ( $ok ) {
			return $check;
		}
		self::$refused[] = $key;
		return false;
	}

	/**
	 * A change to an account's roles and capabilities: anyone may make one
	 * that grants or takes away nothing powerful; a powerful one needs the
	 * right to promote that account, a role the caller may hand out, and
	 * only capabilities the caller holds.
	 */
	private static function may_change_caps( $user_id, $key, $value ) {
		if ( $user_id && is_super_admin( $user_id ) && ! is_super_admin() ) {
			return false;
		}
		$was     = $user_id ? array_filter( (array) Minn_Admin::decode_serialized( get_user_meta( $user_id, $key, true ), array() ) ) : array();
		$now     = array_filter( (array) Minn_Admin::decode_serialized( $value, array() ) );
		$granted = array_diff_key( $now, $was );
		$removed = array_diff_key( $was, $now );
		if ( ! self::empowers( $granted ) && ! self::empowers( $removed ) ) {
			return true;
		}
		if ( ! self::may_promote( $user_id ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$editable = get_editable_roles();
		$roles    = wp_roles();
		foreach ( array_keys( $granted ) as $name ) {
			if ( $roles->is_role( $name ) ? ! isset( $editable[ $name ] ) : ! current_user_can( $name ) ) {
				return false;
			}
		}
		return true;
	}

	private static function may_promote( $user_id ) {
		return $user_id ? current_user_can( 'promote_user', $user_id ) : ( current_user_can( 'promote_users' ) && current_user_can( 'manage_options' ) && Minn_Admin::network_owner() );
	}

	/** Whether a set of granted roles and capabilities carries any power. */
	private static function empowers( array $caps ) {
		$roles = wp_roles();
		foreach ( $caps as $name => $on ) {
			if ( ! $on ) {
				continue;
			}
			$role = $roles->get_role( (string) $name );
			$held = $role ? array_keys( array_filter( (array) $role->capabilities ) ) : array( (string) $name );
			if ( array_intersect( $held, self::POWERS ) ) {
				return true;
			}
		}
		return false;
	}

	private static function restore( $put_back ) {
		self::$restoring = true;
		try {
			$put_back();
		} finally {
			self::$restoring = false;
		}
	}
}
