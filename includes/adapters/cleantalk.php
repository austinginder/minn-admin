<?php
/**
 * CleanTalk Anti-Spam — existing-account cleanup.
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
