<?php
/**
 * CleanTalk Anti-Spam — connector, access key, and existing-account cleanup.
 *
 * Registers CleanTalk on WordPress 7.0's connector registry (Settings →
 * Connectors, next to core's Akismet) as type `spam_filtering`. The access
 * key is not a flat option, so Minn aliases core's generated connector
 * setting onto `cleantalk_settings['apikey']` and writes through CleanTalk's
 * own save-and-check. Licenses and the spam card reuse the same store.
 *
 * CleanTalk already finds spam users (Users → Find spam users): a chunked
 * cloud scan that marks matches with user meta `ct_marked_as_spam`. That
 * scan owns a date range, a progress bar and a request cooldown, so Minn
 * deep-links it rather than rebuilding it.
 *
 * What Minn takes on is the result list. A Users session tab lists the
 * marked accounts, "Not spam" clears the flag the way CleanTalk's Approve
 * does, and delete follows CleanTalk's own path (the account AND the
 * posts they wrote, no reassignment). A generic user delete would move
 * their content to someone else, which is the wrong consequence here.
 *
 * Caps: list_users to see the tab, edit_users to approve, delete_users
 * to delete. Their own screen is gated on activate_plugins; Minn uses
 * the WordPress user capabilities instead. Administrators and the
 * signed-in account are refused even if a mark is present.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_cleantalk_active() {
	return defined( 'APBCT_VERSION' );
}

/** True when CLEANTALK_ACCESS_KEY supplies the key (read-only). */
function minn_admin_cleantalk_key_predefined() {
	return defined( 'CLEANTALK_ACCESS_KEY' ) && CLEANTALK_ACCESS_KEY;
}

/**
 * Access key currently in force. The constant wins over the stored option.
 *
 * @return string
 */
function minn_admin_cleantalk_stored_key() {
	if ( minn_admin_cleantalk_key_predefined() ) {
		return (string) CLEANTALK_ACCESS_KEY;
	}
	$settings = get_option( 'cleantalk_settings' );
	return is_array( $settings ) && ! empty( $settings['apikey'] ) ? (string) $settings['apikey'] : '';
}

/**
 * Load CleanTalk's settings API under REST (settings.php is admin-gated).
 *
 * @return bool
 */
function minn_admin_cleantalk_load_settings_api() {
	if ( ! defined( 'APBCT_VERSION' ) || ! defined( 'APBCT_DIR_PATH' ) ) {
		return false;
	}
	if ( ! function_exists( 'apbct_settings__save_key' ) && file_exists( APBCT_DIR_PATH . 'inc/cleantalk-settings.php' ) ) {
		require_once APBCT_DIR_PATH . 'inc/cleantalk-settings.php';
	}
	return function_exists( 'apbct_settings__save_key' ) && function_exists( 'ct_account_status_check' );
}

/**
 * Restore CleanTalk options + the in-memory $apbct object after a rejected check.
 *
 * Their account-status check writes key_is_ok even for a candidate, so a
 * failed paste must put the previous settings and data back.
 *
 * @param mixed $snap_settings Prior cleantalk_settings row, or false.
 * @param mixed $snap_data     Prior cleantalk_data row, or false.
 */
function minn_admin_cleantalk_restore_snapshot( $snap_settings, $snap_data ) {
	global $apbct;
	if ( false !== $snap_settings ) {
		update_option( 'cleantalk_settings', $snap_settings );
	}
	if ( false !== $snap_data ) {
		update_option( 'cleantalk_data', $snap_data );
	}
	if ( isset( $apbct ) && is_array( $snap_data ) ) {
		foreach ( $snap_data as $k => $v ) {
			$apbct->data[ $k ] = $v;
		}
	}
	if ( isset( $apbct ) && is_array( $snap_settings ) ) {
		foreach ( $snap_settings as $k => $v ) {
			$apbct->settings[ $k ] = $v;
		}
	}
}

/**
 * Store or clear a CleanTalk access key through their own save-and-check.
 *
 * Empty $key clears. A non-empty key is checked live, then stored only when
 * CleanTalk accepts it. A rejection never clobbers a working key.
 *
 * @param string $key Access key, or empty to remove.
 * @return array{ok:bool,code:string,message:string}
 */
function minn_admin_cleantalk_save_access_key( $key ) {
	// CleanTalk's own key handler refuses anyone without activate_plugins,
	// but only when NOT called with its $direct_call flag — which Minn must
	// pass, so the vendor's own gate never runs. Enforce that cap here, or
	// on multisite a main-site administrator (who keeps manage_options but
	// loses activate_plugins) could disable spam filtering through the
	// wp/v2/settings connector.
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'You are not allowed to change the CleanTalk access key.', 'minn-admin' ) );
	}
	$key = trim( (string) $key );
	if ( minn_admin_cleantalk_key_predefined() ) {
		return array(
			'ok'      => false,
			'code'    => 'error',
			'message' => '' === $key
				? __( 'The key is supplied in code (CLEANTALK_ACCESS_KEY); remove it there.', 'minn-admin' )
				: __( 'The key is supplied in code (CLEANTALK_ACCESS_KEY); it cannot be changed here.', 'minn-admin' ),
		);
	}
	if ( ! minn_admin_cleantalk_load_settings_api() ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'CleanTalk settings machinery unavailable.', 'minn-admin' ) );
	}
	if ( '' === $key ) {
		$had = minn_admin_cleantalk_stored_key();
		if ( '' === $had ) {
			return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No key stored', 'minn-admin' ) );
		}
		apbct_settings__save_key( '', true );
		return array( 'ok' => true, 'code' => '', 'message' => __( 'Key removed from this site.', 'minn-admin' ) );
	}
	if ( function_exists( 'apbct_api_key__is_correct' ) && ! apbct_api_key__is_correct( $key ) ) {
		return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'That does not look like a CleanTalk access key (3 to 30 letters and digits).', 'minn-admin' ) );
	}
	global $apbct;
	$snap_settings = get_option( 'cleantalk_settings' );
	$snap_data     = get_option( 'cleantalk_data' );
	$ok            = false;
	try {
		$ok = (bool) ct_account_status_check( $key, false );
	} catch ( \Throwable $e ) {
		$ok = false;
	}
	if ( ! $ok ) {
		minn_admin_cleantalk_restore_snapshot( $snap_settings, $snap_data );
		return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'CleanTalk did not accept that access key.', 'minn-admin' ) );
	}
	apbct_settings__save_key( $key, true );
	$stored = isset( $apbct ) ? (string) $apbct->settings['apikey'] : '';
	if ( $stored === $key ) {
		return array( 'ok' => true, 'code' => '', 'message' => '' );
	}
	return array( 'ok' => false, 'code' => 'error', 'message' => __( 'CleanTalk did not store that access key.', 'minn-admin' ) );
}

/**
 * Re-check the stored CleanTalk access key against their API.
 *
 * @return array{ok:bool,code:string,message:string}
 */
function minn_admin_cleantalk_verify_access_key() {
	if ( ! minn_admin_cleantalk_load_settings_api() ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'CleanTalk settings machinery unavailable.', 'minn-admin' ) );
	}
	global $apbct;
	$key = isset( $apbct ) ? (string) $apbct->settings['apikey'] : minn_admin_cleantalk_stored_key();
	if ( '' === $key ) {
		return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key stored', 'minn-admin' ) );
	}
	try {
		$ok = (bool) ct_account_status_check( $key, false );
	} catch ( \Throwable $e ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach CleanTalk.', 'minn-admin' ) );
	}
	return array(
		'ok'      => $ok,
		'code'    => $ok ? '' : 'invalid',
		'message' => $ok ? '' : __( 'CleanTalk reports this access key as invalid.', 'minn-admin' ),
	);
}

/**
 * Boot payload `spamUsers`. Null when CleanTalk is off or the viewer
 * cannot list users. `count` is whatever is marked right now, so a
 * just-finished scan on their screen shows up after a refresh.
 *
 * @return array{count:int,checkUrl:string}|null
 */
function minn_admin_cleantalk_spam_users_boot() {
	if ( ! minn_admin_cleantalk_active() || ! current_user_can( 'list_users' ) ) {
		return null;
	}
	return array(
		'count'    => minn_admin_cleantalk_spam_count(),
		'checkUrl' => admin_url( 'users.php?page=ct_check_users' ),
	);
}

function minn_admin_cleantalk_spam_count() {
	if ( class_exists( '\Cleantalk\ApbctWP\FindSpam\UsersChecker' ) ) {
		try {
			return (int) \Cleantalk\ApbctWP\FindSpam\UsersChecker::getCountSpammers();
		} catch ( \Throwable $e ) { /* fall through to the meta count */ }
	}
	global $wpdb;
	return (int) $wpdb->get_var(
		"SELECT COUNT(user_id) FROM {$wpdb->usermeta} WHERE meta_key = 'ct_marked_as_spam'"
	);
}

function minn_admin_cleantalk_user_is_marked( $user_id ) {
	$val = get_user_meta( (int) $user_id, 'ct_marked_as_spam', true );
	return '' !== $val && false !== $val;
}

/**
 * Accounts CleanTalk never scans (skip_roles = administrator) and the
 * signed-in operator. Deleting either through this route would be a
 * different, worse action than spam cleanup.
 */
function minn_admin_cleantalk_protected_user( $user ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return true;
	}
	if ( (int) $user->ID === get_current_user_id() ) {
		return true;
	}
	if ( in_array( 'administrator', (array) $user->roles, true ) ) {
		return true;
	}
	if ( user_can( $user, 'manage_options' ) ) {
		return true;
	}
	return false;
}

function minn_admin_cleantalk_approve_user( $user_id ) {
	$user_id = (int) $user_id;
	// CleanTalk's Approve: drop the spam mark and set ct_bad so the next
	// scan will not pick them up again (they treat ct_bad as uncheckable).
	delete_user_meta( $user_id, 'ct_marked_as_spam' );
	update_user_meta( $user_id, 'ct_bad', true );
}

function minn_admin_cleantalk_delete_user( $user_id ) {
	$user_id = (int) $user_id;
	$was_spam = delete_user_meta( $user_id, 'ct_marked_as_spam' );
	$was_bad  = delete_user_meta( $user_id, 'ct_bad' );
	if ( ! $was_spam && ! $was_bad ) {
		return false;
	}
	if ( $was_spam && function_exists( 'ct_feedback' ) ) {
		$hash = get_user_meta( $user_id, 'ct_hash', true );
		if ( $hash ) {
			try {
				ct_feedback( $hash, 0 );
			} catch ( \Throwable $e ) { /* feedback is best-effort */ }
		}
	}
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	// No reassignment: CleanTalk deletes the posts with the account.
	return (bool) wp_delete_user( $user_id );
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_cleantalk_active() ) {
		return;
	}

	register_rest_route(
		'minn-admin/v1',
		'/cleantalk/spam-users',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'list_users' );
			},
			'callback'            => 'minn_admin_cleantalk_list_spam_users',
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/cleantalk/spam-users/(?P<id>\d+)',
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( WP_REST_Request $request ) {
				$id     = (int) $request['id'];
				$action = (string) $request->get_param( 'action' );
				if ( 'approve' === $action ) {
					return current_user_can( 'edit_users' ) && current_user_can( 'edit_user', $id );
				}
				if ( 'delete' === $action ) {
					return current_user_can( 'delete_users' );
				}
				return false;
			},
			'callback'            => 'minn_admin_cleantalk_spam_user_action',
		)
	);
} );

/**
 * Same row shape as minn-admin/v1/users so the Users table can render
 * either list without a second template.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response
 */
function minn_admin_cleantalk_list_spam_users( WP_REST_Request $request ) {
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
	$search   = trim( (string) $request->get_param( 'search' ) );
	$roles    = trim( (string) $request->get_param( 'roles' ) );
	$orderby  = (string) $request->get_param( 'orderby' );
	$order    = strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';

	$orderby_map = array(
		'id'              => 'ID',
		'name'            => 'display_name',
		'email'           => 'user_email',
		'registered_date' => 'user_registered',
		'slug'            => 'user_nicename',
	);
	$wp_orderby = isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'user_registered';

	$args = array(
		'number'     => $per_page,
		'paged'      => $page,
		'orderby'    => $wp_orderby,
		'order'      => $order,
		'fields'     => 'ID',
		'meta_query' => array(
			array(
				'key'     => 'ct_marked_as_spam',
				'compare' => 'EXISTS',
			),
		),
	);
	if ( '' !== $search ) {
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
	}
	if ( '' !== $roles ) {
		$role_list = array_values( array_filter( array_map( 'sanitize_key', explode( ',', $roles ) ) ) );
		if ( 1 === count( $role_list ) ) {
			$args['role'] = $role_list[0];
		} elseif ( $role_list ) {
			$args['role__in'] = $role_list;
		}
	}

	$query = new WP_User_Query( $args );
	$total = (int) $query->get_total();
	$ids   = array_map( 'intval', (array) $query->get_results() );
	$items = array();
	foreach ( $ids as $uid ) {
		if ( ! current_user_can( 'edit_user', $uid ) && ! current_user_can( 'delete_users' ) ) {
			continue;
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			continue;
		}
		$item = array(
			'id'              => $uid,
			'name'            => $user->display_name,
			'email'           => $user->user_email,
			'roles'           => array_values( $user->roles ),
			'registered_date' => mysql_to_rfc3339( $user->user_registered ),
			'avatar_urls'     => rest_get_avatar_urls( $user->user_email ),
			'ctSpam'          => true,
		);
		if ( is_multisite() && is_super_admin( $uid ) ) {
			$item['super'] = true;
		}
		$items[] = $item;
	}

	$pages    = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;
	$response = rest_ensure_response( $items );
	$response->header( 'X-WP-Total', $total );
	$response->header( 'X-WP-TotalPages', $total ? max( 1, $pages ) : 0 );
	return $response;
}

/**
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function minn_admin_cleantalk_spam_user_action( WP_REST_Request $request ) {
	$id     = (int) $request['id'];
	$action = (string) $request->get_param( 'action' );
	$user   = get_userdata( $id );
	if ( ! $user ) {
		return new WP_Error( 'not_found', __( 'User not found', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( ! minn_admin_cleantalk_user_is_marked( $id ) ) {
		return new WP_Error(
			'not_spam',
			__( 'This account is not marked as spam.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}

	if ( 'approve' === $action ) {
		minn_admin_cleantalk_approve_user( $id );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'action' => 'approve' ) );
	}

	if ( 'delete' === $action ) {
		if ( is_multisite() ) {
			return new WP_Error(
				'multisite',
				__( 'On a network, deleting an account is a Network Admin job. Approve it as not spam here, or open CleanTalk to remove it from this site.', 'minn-admin' ),
				array( 'status' => 400 )
			);
		}
		if ( minn_admin_cleantalk_protected_user( $user ) ) {
			return new WP_Error(
				'protected',
				__( 'This account cannot be deleted as spam.', 'minn-admin' ),
				array( 'status' => 400 )
			);
		}
		if ( ! minn_admin_cleantalk_delete_user( $id ) ) {
			return new WP_Error(
				'delete_failed',
				__( 'Could not delete this account.', 'minn-admin' ),
				array( 'status' => 500 )
			);
		}
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'action' => 'delete' ) );
	}

	return new WP_Error( 'bad_action', __( 'Unknown action.', 'minn-admin' ), array( 'status' => 400 ) );
}

/**
 * Register CleanTalk on core's connector registry (Settings → Connectors).
 *
 * Core auto-generates setting_name `connectors_spam_filtering_cleantalk_api_key`
 * and REST-registers it while the plugin is active. That option is a facade:
 * reads alias onto cleantalk_settings['apikey'], writes go through
 * minn_admin_cleantalk_save_access_key so a rejected paste never lands in a
 * second store. CLEANTALK_ACCESS_KEY is declared as the constant so Minn's
 * connector cards lock the field the same way they do for Akismet.
 *
 * @param object $registry WP_Connector_Registry.
 */
function minn_admin_cleantalk_register_connector( $registry ) {
	if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
		return;
	}
	$registered = $registry->register(
		'cleantalk',
		array(
			'name'           => __( 'CleanTalk Anti-Spam', 'minn-admin' ),
			'description'    => __( 'Cloud spam filtering for comments, registrations and forms.', 'minn-admin' ),
			'type'           => 'spam_filtering',
			'plugin'         => array(
				'file'      => 'cleantalk-spam-protect/cleantalk.php',
				'is_active' => static function () {
					return defined( 'APBCT_VERSION' );
				},
			),
			'authentication' => array(
				'method'          => 'api_key',
				'credentials_url' => 'https://cleantalk.org/my',
				'constant_name'   => 'CLEANTALK_ACCESS_KEY',
			),
		)
	);
	if ( ! is_array( $registered ) ) {
		return;
	}
	$setting = isset( $registered['authentication']['setting_name'] )
		? (string) $registered['authentication']['setting_name']
		: '';
	if ( '' === $setting ) {
		return;
	}
	add_filter( "pre_option_{$setting}", 'minn_admin_cleantalk_connector_pre_option' );
	add_filter(
		"pre_update_option_{$setting}",
		static function ( $value, $old ) {
			unset( $value );
			// Never persist a second copy of the key as a flat option.
			return $old;
		},
		10,
		2
	);
}
add_action( 'wp_connectors_init', 'minn_admin_cleantalk_register_connector' );

/** Alias core's connector setting onto the key CleanTalk actually stores. */
function minn_admin_cleantalk_connector_pre_option() {
	return minn_admin_cleantalk_stored_key();
}

/**
 * Per-request flag: a connector save was rejected, so the REST settings
 * response should come back empty (Minn's Connectors UI treats that as
 * the refusal) without clearing a working stored key.
 *
 * @param bool|null $set Pass true/false to set, null to read.
 * @return bool
 */
function minn_admin_cleantalk_connector_rejected( $set = null ) {
	static $rejected = false;
	if ( null !== $set ) {
		$rejected = (bool) $set;
	}
	return $rejected;
}

/** Setting name core generated for the CleanTalk connector, or empty. */
function minn_admin_cleantalk_connector_setting() {
	if ( ! function_exists( 'wp_get_connector' ) ) {
		return '';
	}
	$c = wp_get_connector( 'cleantalk' );
	return ( $c && ! empty( $c['authentication']['setting_name'] ) )
		? (string) $c['authentication']['setting_name']
		: '';
}

add_filter(
	'rest_pre_update_setting',
	static function ( $updated, $name, $value ) {
		$setting = minn_admin_cleantalk_connector_setting();
		if ( '' === $setting || $setting !== $name ) {
			return $updated;
		}
		$key    = is_string( $value ) ? $value : '';
		$result = minn_admin_cleantalk_save_access_key( $key );
		if ( ! $result['ok'] ) {
			minn_admin_cleantalk_connector_rejected( true );
		}
		return true;
	},
	10,
	3
);

add_filter(
	'rest_pre_get_setting',
	static function ( $result, $name ) {
		$setting = minn_admin_cleantalk_connector_setting();
		if ( '' === $setting || $setting !== $name ) {
			return $result;
		}
		if ( minn_admin_cleantalk_connector_rejected() ) {
			return '';
		}
		return $result;
	},
	10,
	2
);
