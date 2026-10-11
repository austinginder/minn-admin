<?php
/**
 * Write fences: whatever an adapter's gate lets through, the writes that
 * hand over the site still need the caller's own capability.
 *
 * A probe route inside minn-admin/v1, gated for anyone signed in, stands in
 * for an adapter whose gate is wrong. Each case makes one such write the way
 * vendor code would (update_option, WP_Roles, WP_User, wp_update_user) as an
 * Editor, a Shop Manager and an Administrator. The fence must keep the
 * stored value and answer 403 for the first two, let the Administrator
 * through, let grants that carry no power through for anyone (vendor side
 * effects), and leave requests outside Minn's namespace alone.
 *
 * Everything a case could change is put back at the end, from raw values
 * read before the run.
 *
 * Run: wp eval-file tests/write-fences.test.php --user=admin --path=<site>
 *      (on a network: --url=<a site other than the main one>)
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}
global $wpdb;
$admin = get_current_user_id();
if ( ! $admin || ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
add_filter( 'send_email_change_email', '__return_false' );
add_filter( 'send_password_change_email', '__return_false' );

// --- what the cases may touch, read raw before anything runs -----------------
$watched = array( 'default_role', 'users_can_register', 'active_plugins', 'upload_url_path', 'siteurl', $wpdb->get_blog_prefix() . 'user_roles' );
$before  = array();
foreach ( $watched as $name ) {
	$before[ $name ] = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
}
$admin_row  = $wpdb->get_row( $wpdb->prepare( "SELECT user_email, user_pass FROM {$wpdb->users} WHERE ID = %d", $admin ), ARRAY_A );
$admin_caps = get_user_meta( $admin, $wpdb->get_blog_prefix() . 'capabilities', true );
$site_admins = is_multisite() ? get_site_option( 'site_admins' ) : null;

$made = array();
$mk   = static function ( $key, $role ) use ( &$made ) {
	if ( ! get_role( $role ) ) {
		return 0;
	}
	$login = 'minn-fence-' . $key . '-' . wp_rand( 1000, 9999 );
	$id    = (int) wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( $id ) {
		$made[] = $id;
	}
	return $id;
};
$editor = $mk( 'editor', 'editor' );
$shop   = $mk( 'shop', 'shop_manager' );
$target = $mk( 'target', 'subscriber' );
// On a network: an administrator of this site only, who runs the site but
// not its address or the network.
$site_admin = is_multisite() ? $mk( 'siteadmin', 'administrator' ) : 0;
$low    = get_role( 'customer' ) ? 'customer' : 'subscriber';

// The probe: one write per case, made the way vendor code makes it.
$cases = array(
	'default_role'      => static function () {
		update_option( 'default_role', 'administrator' );
	},
	'open_registration' => static function () {
		update_option( 'users_can_register', '1' );
	},
	'add_plugin'        => static function () {
		$active   = (array) get_option( 'active_plugins', array() );
		$active[] = 'minn-fence-probe/none.php';
		update_option( 'active_plugins', $active );
	},
	'add_upload_path'   => static function () {
		add_option( 'upload_url_path', 'https://example.invalid/minn-fence' );
	},
	'move_site'         => static function () {
		update_option( 'siteurl', 'https://minn-fence.example.invalid' );
	},
	'drop_plugins'      => static function () {
		delete_option( 'active_plugins' );
	},
	'role_gets_power'   => static function () {
		get_role( 'subscriber' )->add_cap( 'manage_options' );
	},
	'make_admin'        => static function () use ( $target ) {
		( new WP_User( $target ) )->set_role( 'administrator' );
	},
	'raw_power'         => static function () use ( $target ) {
		( new WP_User( $target ) )->add_cap( 'promote_users' );
	},
	'low_role'          => static function () use ( $target, $low ) {
		( new WP_User( $target ) )->set_role( $low );
	},
	'demote_owner'      => static function () use ( $admin ) {
		( new WP_User( $admin ) )->set_role( 'subscriber' );
	},
	'owner_email'       => static function () use ( $admin ) {
		wp_update_user( array( 'ID' => $admin, 'user_email' => 'minn-fence-takeover@example.com' ) );
	},
	'member_email'      => static function () use ( $target ) {
		wp_update_user( array( 'ID' => $target, 'user_email' => 'minn-fence-member@example.com' ) );
	},
);
if ( is_multisite() ) {
	$cases['name_super_admin'] = static function () use ( $target ) {
		$list   = (array) get_site_option( 'site_admins', array() );
		$list[] = get_userdata( $target )->user_login;
		update_site_option( 'site_admins', $list );
	};
}

rest_get_server();
foreach ( array( 'minn-admin/v1', 'minn-fence-probe/v1' ) as $ns ) {
	register_rest_route(
		$ns,
		'/zz-fence-probe',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'is_user_logged_in',
			'callback'            => static function ( WP_REST_Request $request ) use ( $cases ) {
				$case = (string) $request['case'];
				if ( 'nested' === $case ) {
					// A Minn route handing the work to another namespace's route.
					$inner = new WP_REST_Request( 'POST', '/minn-fence-probe/v1/zz-fence-probe' );
					$inner->set_param( 'case', 'default_role' );
					rest_do_request( $inner );
				} else {
					$cases[ $case ]();
				}
				return rest_ensure_response( array( 'ok' => true ) );
			},
		)
	);
}

$reload = static function () use ( $target, $admin ) {
	wp_roles()->for_site();
	wp_cache_delete( 'alloptions', 'options' );
	foreach ( array( 'default_role', 'users_can_register', 'active_plugins', 'upload_url_path' ) as $name ) {
		wp_cache_delete( $name, 'options' );
	}
	clean_user_cache( $target );
	clean_user_cache( $admin );
	if ( is_multisite() ) {
		wp_cache_delete( get_current_network_id() . ':site_admins', 'site-options' );
	}
};
$put_back = static function () use ( $wpdb, $before, $admin, $admin_row, $admin_caps, $target, $site_admins, $reload ) {
	foreach ( $before as $name => $row ) {
		if ( $row ) {
			$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ) );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		}
	}
	$wpdb->update( $wpdb->users, $admin_row, array( 'ID' => $admin ) );
	update_user_meta( $admin, $wpdb->get_blog_prefix() . 'capabilities', $admin_caps );
	( new WP_User( $target ) )->set_role( 'subscriber' );
	$wpdb->update( $wpdb->users, array( 'user_email' => get_userdata( $target )->user_login . '@example.com' ), array( 'ID' => $target ) );
	if ( is_multisite() ) {
		update_site_option( 'site_admins', $site_admins );
	}
	$reload();
};
$send = static function ( $who, $case, $ns = 'minn-admin/v1' ) use ( $reload ) {
	wp_set_current_user( $who );
	$request = new WP_REST_Request( 'POST', '/' . $ns . '/zz-fence-probe' );
	$request->set_param( 'case', $case );
	$response = rest_do_request( $request );
	$reload();
	return $response;
};
$state = static function () use ( $target, $admin, $wpdb ) {
	$sub = get_role( 'subscriber' );
	$tu  = new WP_User( $target );
	return array(
		'default_role'      => get_option( 'default_role' ),
		'open_registration' => (string) get_option( 'users_can_register' ),
		'add_plugin'        => in_array( 'minn-fence-probe/none.php', (array) get_option( 'active_plugins' ), true ),
		'add_upload_path'   => get_option( 'upload_url_path' ),
		'drop_plugins'      => wp_json_encode( get_option( 'active_plugins' ) ),
		// Raw: hosting helpers filter the option to the request's host.
		'move_site'         => $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" ),
		'role_gets_power'   => $sub && $sub->has_cap( 'manage_options' ),
		'make_admin'        => in_array( 'administrator', $tu->roles, true ),
		'raw_power'         => ! empty( $tu->caps['promote_users'] ),
		'low_role'          => $tu->roles,
		'demote_owner'      => ( new WP_User( $admin ) )->roles,
		'owner_email'       => $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM {$wpdb->users} WHERE ID = %d", $admin ) ),
		'member_email'      => $wpdb->get_var( $wpdb->prepare( "SELECT user_email FROM {$wpdb->users} WHERE ID = %d", $target ) ),
		'name_super_admin'  => is_multisite() && in_array( get_userdata( $target )->user_login, (array) get_site_option( 'site_admins', array() ), true ),
	);
};

try {
	$check( 'Fixture accounts exist', $editor && $target, 'editor ' . $editor . ', target ' . $target );
	$put_back();
	$rest = $state();

	// --- refused for accounts without the power, value kept -----------------
	$fenced = array( 'default_role', 'open_registration', 'add_plugin', 'add_upload_path', 'drop_plugins', 'move_site', 'role_gets_power', 'make_admin', 'raw_power', 'demote_owner', 'owner_email' );
	if ( is_multisite() ) {
		$fenced[] = 'name_super_admin';
	}
	$callers = array_filter( array( 'Editor' => $editor, 'Shop Manager' => $shop ) );
	foreach ( $callers as $label => $who ) {
		foreach ( $fenced as $case ) {
			$expect = $rest[ $case ];
			if ( 'add_upload_path' === $case ) {
				// An add only happens to an option that is not there yet.
				$wpdb->delete( $wpdb->options, array( 'option_name' => 'upload_url_path' ) );
				$reload();
				$expect = false;
			}
			$response = $send( $who, $case );
			$after    = $state();
			$kept     = $after[ $case ] === $expect;
			$check(
				"{$label}: {$case} is refused and the stored value kept",
				403 === $response->get_status() && 'minn_write_fenced' === ( $response->get_data()['code'] ?? '' ) && $kept,
				'status ' . $response->get_status() . ', kept ' . var_export( $kept, true )
			);
			$put_back();
		}
	}

	// --- a site's administrator on a network: the site, not the network -----
	if ( $site_admin ) {
		foreach ( array( 'move_site', 'name_super_admin' ) as $case ) {
			$response = $send( $site_admin, $case );
			$check( "Site administrator: {$case} is refused and the stored value kept", 403 === $response->get_status() && $state()[ $case ] === $rest[ $case ], 'status ' . $response->get_status() );
			$put_back();
		}
		$response = $send( $site_admin, 'default_role' );
		$check( 'Site administrator: default_role (a site setting) goes through', 200 === $response->get_status() && 'administrator' === $state()['default_role'], 'status ' . $response->get_status() );
		$put_back();
	}

	// --- grants that carry no power go through for anyone -------------------
	$response = $send( $editor, 'low_role' );
	$check( "Editor: a powerless role ({$low}) still goes through, as vendor side effects need", 200 === $response->get_status() && in_array( $low, $state()['low_role'], true ), 'status ' . $response->get_status() );
	$put_back();
	$response = $send( $editor, 'member_email' );
	$check( 'Editor: a powerless account\'s email still changes (member tools)', 200 === $response->get_status() && 'minn-fence-member@example.com' === $state()['member_email'], 'status ' . $response->get_status() );
	$put_back();

	// --- an administrator holds every power here -----------------------------
	foreach ( array( 'default_role', 'make_admin', 'owner_email' ) as $case ) {
		$response = $send( $admin, $case );
		$check( "Administrator: {$case} goes through", 200 === $response->get_status() && $state()[ $case ] !== $rest[ $case ], 'status ' . $response->get_status() );
		$put_back();
	}

	// --- scope: only Minn's own requests are fenced ---------------------------
	$response = $send( $editor, 'default_role', 'minn-fence-probe/v1' );
	$check( 'Outside minn-admin/v1 the fence stays down (other plugins keep their own rules)', 200 === $response->get_status() && 'administrator' === $state()['default_role'], 'status ' . $response->get_status() );
	$put_back();
	$response = $send( $editor, 'nested' );
	$check( 'A Minn route handing the write to another route is still fenced', 403 === $response->get_status() && $state()['default_role'] === $rest['default_role'], 'status ' . $response->get_status() );
	$put_back();
	$check( 'The fence is down again after the requests', ! Minn_Admin_Fences::armed() );
} finally {
	wp_set_current_user( $admin );
	$put_back();
	foreach ( $made as $uid ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $uid );
		} else {
			wp_delete_user( $uid );
		}
	}
}

$failed = count( array_filter( $results, function ( $r ) { return ! $r; } ) );
printf( "\nwrite-fences: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
