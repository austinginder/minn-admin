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
 * One write cannot be undone: core fires no hook before a network setting is
 * deleted under another spelling of its name, so only the super admins and
 * the network-active plugins are put back (site_variant_deleted()).
 *
 * The fence guards the stored value, not code that runs on the way to it.
 * activate_plugin() loads a plugin and switch_theme() fires its hooks before
 * the option write the fence refuses, so a route that activates a plugin or
 * switches a theme asks the activation capability itself first.
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

	/**
	 * Whether the running request's caller owns the whole install, read as
	 * the request arms. A super admin of the main network passes every
	 * check here, but a route can switch into a context where WordPress no
	 * longer knows them: WP Multi Network sets up a new network's main site
	 * from inside that network, whose site_admins is not written yet, so
	 * each check made in that moment said no and left the new site without
	 * roles or an administrator.
	 */
	private static $owner = false;

	/**
	 * The network's super admins and network-active plugins as the request
	 * armed. Core preloads both, so the copy costs nothing; it is what a
	 * refused delete under another spelling of the name is put back from.
	 */
	private static $network_was = array();

	public static function init() {
		// Last: a filter before it may hand back null whatever it was given
		// (WooCommerce Gift Cards does), which would drop the refusal.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'canonical' ), PHP_INT_MAX, 3 );
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
		// The same names in another case: MySQL matches meta_key without
		// regard to case, so SITE_ADMINS writes site_admins, and core has
		// per-name hooks only for the exact spelling.
		add_action( 'update_site_option', array( __CLASS__, 'site_variant_updated' ), PHP_INT_MAX, 4 );
		add_action( 'add_site_option', array( __CLASS__, 'site_variant_added' ), 0, 3 );
		add_action( 'delete_site_option', array( __CLASS__, 'site_variant_deleted' ), PHP_INT_MAX, 2 );

		add_filter( 'add_user_metadata', array( __CLASS__, 'meta_add' ), PHP_INT_MAX, 4 );
		add_filter( 'update_user_metadata', array( __CLASS__, 'meta_update' ), PHP_INT_MAX, 4 );
		add_filter( 'delete_user_metadata', array( __CLASS__, 'meta_delete' ), PHP_INT_MAX, 3 );
		add_filter( 'wp_pre_insert_user_data', array( __CLASS__, 'user_row' ), PHP_INT_MAX, 3 );
	}

	/** Whether a fence is up: a minn-admin/v1 request by anyone but the install's owner is running. */
	public static function armed() {
		return self::$depth > 0 && ! self::$restoring && ! self::$owner;
	}

	public static function arm( $response, $handler, $request ) {
		if ( self::ours( $request ) ) {
			if ( 0 === self::$depth ) {
				self::$refused = array();
				self::$owner   = is_multisite() && Minn_Admin::install_owner();
				self::$network_was = is_multisite() && ! self::$owner ? array(
					'network'                 => get_current_network_id(),
					'site_admins'             => get_site_option( 'site_admins' ),
					'active_sitewide_plugins' => get_site_option( 'active_sitewide_plugins' ),
				) : array();
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
		if ( 0 === self::$depth ) {
			self::$owner = false;
		}
		if ( self::$depth > 0 || ! self::$refused ) {
			return $response;
		}
		$names         = array_values( array_unique( self::$refused ) );
		self::$refused = array();
		// One greppable line for the server log, never shown in the UI.
		error_log( sprintf( 'minn_write_fenced user=%d route="%s %s" refused=%s', get_current_user_id(), $request->get_method(), $request->get_route(), implode( ',', $names ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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

	public static function site_variant_updated( $option, $value, $old_value, $network_id ) {
		if ( ! self::site_variant( $option ) || ! self::armed() || self::site_allowed() ) {
			return;
		}
		if ( maybe_serialize( $value ) !== maybe_serialize( $old_value ) ) {
			self::$refused[] = $option;
			self::restore(
				function () use ( $option, $old_value, $network_id ) {
					update_network_option( $network_id, $option, $old_value );
				}
			);
		}
	}

	/** An add under another spelling lands only when the real name is unset: remove that row alone. */
	public static function site_variant_added( $option, $value, $network_id ) {
		if ( ! self::site_variant( $option ) || ! self::armed() || self::site_allowed() ) {
			return;
		}
		global $wpdb;
		self::$refused[] = $option;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s AND BINARY meta_key = %s", $network_id, $option, $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $network_id . ':' . $option, 'site-options' );
		wp_cache_delete( $network_id . ':' . strtolower( $option ), 'site-options' );
		wp_cache_delete( $network_id . ':' . self::site_name( $option ), 'site-options' );
	}

	/**
	 * Core gives a delete under another spelling no hook before the row
	 * goes, so only what the request armed with can be put back: the super
	 * admins and network-active plugins. The rest of the list is refused
	 * and logged but stays deleted (registration closes, a list falls back
	 * to core's default).
	 */
	public static function site_variant_deleted( $option, $network_id ) {
		if ( ! self::site_variant( $option ) || ! self::armed() || self::site_allowed() ) {
			return;
		}
		self::$refused[] = $option;
		$name = self::site_name( $option );
		if ( isset( self::$network_was[ $name ] ) && (int) self::$network_was['network'] === (int) $network_id && false !== self::$network_was[ $name ] ) {
			$value = self::$network_was[ $name ];
			// Core cleared the cache under the spelling it was handed; the
			// real name's copy would make the add a no-op.
			wp_cache_delete( $network_id . ':' . $name, 'site-options' );
			self::restore(
				function () use ( $name, $value, $network_id ) {
					add_network_option( $network_id, $name, $value );
				}
			);
		}
	}

	/** A network setting's name in a case or spelling other than its own. */
	private static function site_variant( $option ) {
		$option = (string) $option;
		return ! in_array( $option, self::SITE_OPTIONS, true ) && in_array( self::site_name( $option ), self::SITE_OPTIONS, true );
	}

	/**
	 * The row a network setting name lands on. MySQL compares meta_key
	 * without regard to case, and its PAD SPACE collations ignore trailing
	 * spaces, so 'SITE_ADMINS ' writes site_admins; core trims option
	 * names, but not network option names or meta keys.
	 */
	private static function site_name( $option ) {
		return strtolower( rtrim( (string) $option, ' ' ) );
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
	 * Another account's sign-in (login, password, email, password-reset key)
	 * stays as stored when that account is powerful and the caller may not
	 * edit it: a new email address, or a reset key the caller knows, is a
	 * password reset away from the account.
	 */
	public static function user_row( $data, $update, $user_id ) {
		if ( ! $update || ! $user_id || ! self::armed() || current_user_can( 'edit_user', $user_id ) ) {
			return $data;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || ( ! is_super_admin( $user_id ) && ! self::empowers( (array) $user->caps ) ) ) {
			return $data;
		}
		foreach ( array( 'user_login', 'user_pass', 'user_email', 'user_activation_key' ) as $field ) {
			if ( isset( $data[ $field ] ) && (string) $data[ $field ] !== (string) $user->$field ) {
				$data[ $field ]  = $user->$field;
				self::$refused[] = $field;
			}
		}
		return $data;
	}

	/**
	 * Core matches routes without regard to case and keeps the route as the
	 * request spelled it, so /Minn-Admin/v1/... ran Minn's handlers while
	 * every check that tells Minn's requests apart by their route (these
	 * fences, the egress mask, the settings tier) read it as someone else's.
	 * A Minn namespace in any spelling but its own is refused before it
	 * reaches a handler.
	 *
	 * @param mixed           $result  A response a filter before this one gave.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function canonical( $result, $server, $request ) {
		// Core takes a filter's answer only when it is not empty, and
		// dispatches past false, '' or an empty array: so does this.
		if ( ! empty( $result ) || ! $request instanceof WP_REST_Request ) {
			return $result;
		}
		if ( preg_match( '#^/minn-admin/v\d+(?=/|$)#i', $request->get_route(), $m ) && strtolower( $m[0] ) !== $m[0] ) {
			return new WP_Error( 'minn_admin_route_case', __( 'Minn Admin routes are lowercase.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		return $result;
	}

	/** Whether a request is one of Minn's own (any spelling, as core matches it). */
	private static function ours( $request ) {
		return $request instanceof WP_REST_Request && 0 === stripos( $request->get_route(), '/minn-admin/v1' );
	}

	private static function option_allowed( $option ) {
		global $wpdb;
		// MySQL compares option names without regard to case, so a write
		// to USERS_CAN_REGISTER lands on users_can_register.
		$key = strtolower( (string) $option );
		if ( isset( self::OPTIONS[ $key ] ) ) {
			$need = self::OPTIONS[ $key ];
			if ( 'network' === $need ) {
				return current_user_can( 'manage_options' ) && Minn_Admin::network_owner();
			}
			return current_user_can( $need );
		}
		// The role definitions themselves: adding a capability to a role
		// grants it to everyone holding the role.
		if ( preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?user_roles$/i', (string) $option ) ) {
			return current_user_can( 'manage_options' ) && current_user_can( 'promote_users' );
		}
		return true;
	}

	private static function site_allowed() {
		return is_multisite() ? Minn_Admin::network_owner() : current_user_can( 'manage_options' );
	}

	/**
	 * Which access record a user meta key is ('caps', 'level' or '') and
	 * the key it is stored under. Matched as MySQL matches meta_key:
	 * without regard to case, and with trailing spaces ignored (PAD SPACE
	 * collations), so WP_CAPABILITIES and 'wp_capabilities ' both write
	 * wp_capabilities.
	 */
	private static function access_key( $key ) {
		global $wpdb;
		if ( ! preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '(\d+_)?(capabilities|user_level)$/i', rtrim( (string) $key, ' ' ), $m ) ) {
			return array( '', '' );
		}
		$kind = 'capabilities' === strtolower( $m[2] ) ? 'caps' : 'level';
		return array( $kind, $wpdb->base_prefix . $m[1] . ( 'caps' === $kind ? 'capabilities' : 'user_level' ) );
	}

	private static function meta_write( $check, $user_id, $key, $value ) {
		if ( null !== $check || ! self::armed() ) {
			return $check;
		}
		list( $kind, $stored ) = self::access_key( $key );
		if ( '' === $kind ) {
			return $check;
		}
		// Account 0 is every account at once (delete_metadata()'s delete_all):
		// no Minn screen takes everyone's role away.
		if ( 0 === (int) $user_id ) {
			self::$refused[] = $key;
			return false;
		}
		$ok = 'caps' === $kind
			? self::may_change_caps( $user_id, $stored, $value )
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
