<?php
/**
 * Regressions for the v0.45.0 fix-delta review, round 1: what the network
 * and Autoptimize surfaces offer.
 *
 * A surface's actions and fields are what the client draws. Each section
 * reads the boot payload or the route the client calls, as the role the
 * finding named, and checks that nothing is offered the route behind it
 * refuses, with a control for the role it is offered to. Sections SKIP on a
 * single site, without WP Multi Network, or without Autoptimize.
 *
 * Run: wp eval-file tests/security-v045-delta-surfaces.test.php --user=admin --path=<site> [--url=<subsite>]
 * From a second network, as its super admin:
 *      wp eval-file tests/security-v045-delta-surfaces.test.php --user=<net2 admin> --url=<net2 site> --path=<install>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v045-delta-surfaces: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

// A throwaway account of one role; the id is checked before any later
// delete, since a WP_Error cast to int names user 1.
$make_user = function ( $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'minn-d45s-' . $role . '-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'minn-d45s-' . $role . '-' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => $role,
		)
	);
	return is_int( $id ) && $id > 1 ? $id : 0;
};

// Whether a route's permission callback admits the current user, without
// running the route.
$perm_for = function ( $route, $method, $url_params = array() ) {
	$routes = rest_get_server()->get_routes();
	foreach ( $routes[ $route ] ?? array() as $handler ) {
		if ( ! empty( $handler['methods'][ $method ] ) ) {
			$req = new WP_REST_Request( $method, $route );
			$req->set_url_params( $url_params );
			return true === call_user_func( $handler['permission_callback'], $req );
		}
	}
	return null;
};

// The boot payload's surfaces, read fresh for whoever is current.
$surfaces_ref = new ReflectionProperty( 'Minn_Admin_Surfaces', 'all_cache' );
$surfaces_ref->setAccessible( true );
$surface = function ( $id ) use ( $surfaces_ref ) {
	$surfaces_ref->setValue( null, null );
	foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
		if ( $id === $s['id'] ) {
			return $s;
		}
	}
	return null;
};
$action_routes = function ( $s ) {
	return $s ? array_values( array_filter( wp_list_pluck( (array) ( $s['collection']['actions'] ?? array() ), 'route' ) ) ) : array();
};

// Leftovers of an earlier run that stopped part-way.
foreach ( get_users( array( 'search' => 'minn-d45s-*', 'search_columns' => array( 'user_login' ), 'blog_id' => 0, 'fields' => 'ID' ) ) as $stale ) {
	if ( (int) $stale > 1 ) {
		$drop_user( (int) $stale );
	}
}

$cli_user = get_current_user_id();

// --- NI-10 Network sites and users: offered only where the route allows ------
// A network capability on its own (manage_sites, manage_network_users) lets
// a role open these surfaces, but adding a site needs a super admin and
// deleting one needs delete_sites, and granting network administrator needs
// manage_network_options. The surfaces offered them anyway, and each click
// was a 403.
if ( ! is_multisite() ) {
	$skip( 'NI-10 network only' );
} elseif ( ! is_super_admin( $cli_user ) ) {
	$skip( 'NI-10 run as a super admin' );
} else {
	$ns_uid = $make_user( 'subscriber' );
	if ( ! $ns_uid ) {
		$check( 'NI-10 fixtures', false, 'could not create the account' );
	} else {
		$ns_caps  = array();
		$ns_grant = function ( $allcaps, $caps, $args ) use ( $ns_uid, &$ns_caps ) {
			if ( (int) ( $args[1] ?? 0 ) === $ns_uid ) {
				foreach ( $ns_caps as $c ) {
					$allcaps[ $c ] = true;
				}
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $ns_grant, 10, 3 );
		wp_set_current_user( $ns_uid );
		$ns_caps = array( 'manage_sites' );
		$ns_s    = $surface( 'network-sites' );
		$check( 'NI-10 a manage_sites holder who is not a super admin is not offered Add site', $ns_s && empty( $ns_s['collection']['create'] ) && false === $perm_for( '/minn-admin/v1/network/sites', 'POST' ), $ns_s ? wp_json_encode( $ns_s['collection']['create'] ?? null ) : 'no Sites surface' );
		$check( 'NI-10 and, without delete_sites, is not offered Delete site', $ns_s && ! in_array( 'minn-admin/v1/network/sites/{id}', $action_routes( $ns_s ), true ) && false === $perm_for( '/minn-admin/v1/network/sites/(?P<id>\d+)', 'DELETE' ), wp_json_encode( $action_routes( $ns_s ) ) );
		$check( 'NI-10 control: that holder keeps archive and spam, which their route allows', in_array( 'minn-admin/v1/network/sites/{id}/flag', $action_routes( $ns_s ), true ) );
		$ns_caps = array( 'manage_sites', 'delete_sites' );
		$check( 'NI-10 control: with delete_sites, Delete site is offered', in_array( 'minn-admin/v1/network/sites/{id}', $action_routes( $surface( 'network-sites' ) ), true ) && true === $perm_for( '/minn-admin/v1/network/sites/(?P<id>\d+)', 'DELETE' ) );
		$ns_caps = array( 'manage_network_users' );
		$nu_s    = $surface( 'network-users' );
		$check( 'NI-10 a network user manager without manage_network_options is not offered network administrator grants', $nu_s && ! in_array( 'minn-admin/v1/network/users/{id}/super', $action_routes( $nu_s ), true ) && false === $perm_for( '/minn-admin/v1/network/users/(?P<id>\d+)/super', 'POST' ), wp_json_encode( $action_routes( $nu_s ) ) );
		$check( 'NI-10 control: that manager keeps the Network Admin link', $nu_s && in_array( network_admin_url( 'user-edit.php?user_id={id}' ), wp_list_pluck( (array) ( $nu_s['collection']['actions'] ?? array() ), 'href' ), true ) );
		remove_filter( 'user_has_cap', $ns_grant, 10 );
		wp_set_current_user( $cli_user );
		$drop_user( $ns_uid );
	}
	wp_set_current_user( $cli_user );
	$su_s = $surface( 'network-sites' );
	$check( 'NI-10 control: a super admin is offered Add site and Delete site', $su_s && ! empty( $su_s['collection']['create'] ) && in_array( 'minn-admin/v1/network/sites/{id}', $action_routes( $su_s ), true ) );
	$check( 'NI-10 control: a super admin is offered network administrator grants', in_array( 'minn-admin/v1/network/users/{id}/super', $action_routes( $surface( 'network-users' ) ), true ) );
}

// --- NI-10 WP Multi Network: delete and move for the install's owners only ----
// Both routes now require the main network's super admins, but a secondary
// network's super admin who also administers a third network was still
// offered Delete network on it and Move site to it. The probe lists the
// account in a third network's administrators for the run.
if ( ! is_multisite() || ! function_exists( 'switch_to_network' ) || ! function_exists( 'minn_admin_wpmn_ready' ) || ! minn_admin_wpmn_ready() ) {
	$skip( 'NI-10 WP Multi Network inactive' );
} elseif ( ! is_super_admin( $cli_user ) ) {
	$skip( 'NI-10 WP Multi Network: run as a super admin' );
} else {
	$mn_other = 0;
	foreach ( get_networks( array( 'number' => 0 ) ) as $mn_n ) {
		if ( ! is_main_network( $mn_n->id ) && (int) $mn_n->id !== (int) get_current_network_id() ) {
			$mn_other = (int) $mn_n->id;
			break;
		}
	}
	if ( ! $mn_other ) {
		$skip( 'NI-10 WP Multi Network needs a secondary network other than this one' );
	} else {
		$mn_admins_was = get_network_option( $mn_other, 'site_admins', null );
		$mn_main_su    = null;
		foreach ( (array) get_network_option( get_main_network_id(), 'site_admins', array() ) as $mn_login ) {
			$mn_main_su = $mn_main_su ? $mn_main_su : get_user_by( 'login', (string) $mn_login );
		}
		$mn_logins = array_values( array_unique( array_merge( (array) $mn_admins_was, array( wp_get_current_user()->user_login ), $mn_main_su ? array( $mn_main_su->user_login ) : array() ) ) );
		try {
			update_network_option( $mn_other, 'site_admins', $mn_logins );
			$mn_row = function ( $id ) use ( $call ) {
				list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wp-multi-network/networks', null, array( 'per_page' => 100 ) );
				foreach ( (array) ( $d['items'] ?? array() ) as $row ) {
					if ( (int) $row['id'] === $id ) {
						return $row;
					}
				}
				return array( 'status' => $st );
			};
			$mn_moves = function () use ( $surface, $action_routes ) {
				return in_array( 'minn-admin/v1/wp-multi-network/sites/{id}/move', $action_routes( $surface( 'network-sites' ) ), true );
			};
			if ( ! is_main_network() && ! Minn_Admin::install_owner() ) {
				$check( 'NI-10 a secondary network\'s super admin is not offered Delete network on a third network they administer', '0' === ( $mn_row( $mn_other )['canDelete'] ?? null ) && false === $perm_for( '/minn-admin/v1/wp-multi-network/networks/(?P<id>\d+)', 'DELETE', array( 'id' => $mn_other ) ), wp_json_encode( $mn_row( $mn_other ) ) );
				$check( 'NI-10 nor Move site to it', ! $mn_moves() && false === $perm_for( '/minn-admin/v1/wp-multi-network/sites/(?P<id>\d+)/move', 'POST', array( 'id' => get_current_blog_id() ) ) );
				$check( 'NI-10 control: they may still list the networks', 200 === $call( 'GET', '/minn-admin/v1/wp-multi-network/networks' )[0] );
			}
			if ( $mn_main_su ) {
				$mn_switched = ! is_main_network();
				if ( $mn_switched ) {
					switch_to_network( get_main_network_id() );
				}
				wp_set_current_user( $mn_main_su->ID );
				$check( 'NI-10 control: the main network\'s super admin is offered Delete network on a network they administer', '1' === ( $mn_row( $mn_other )['canDelete'] ?? null ) && true === $perm_for( '/minn-admin/v1/wp-multi-network/networks/(?P<id>\d+)', 'DELETE', array( 'id' => $mn_other ) ), wp_json_encode( $mn_row( $mn_other ) ) );
				$check( 'NI-10 control: and Move site', $mn_moves() );
				wp_set_current_user( $cli_user );
				if ( $mn_switched ) {
					restore_current_network();
				}
			}
		} finally {
			if ( null === $mn_admins_was ) {
				delete_network_option( $mn_other, 'site_admins' );
			} else {
				update_network_option( $mn_other, 'site_admins', $mn_admins_was );
			}
			wp_set_current_user( $cli_user );
			$surfaces_ref->setValue( null, null );
		}
	}
}

// --- NI-11 Autoptimize network-wide: the tab is locked, not editable ----------
// With Autoptimize network-active and per-site configuration off, every save
// answers 409, but the tab still drew editable fields. Its fields are locked
// with the way to Network Admin; a per-site Autoptimize still edits.
if ( ! function_exists( 'minn_admin_autoptimize_active' ) || ! minn_admin_autoptimize_active() ) {
	$skip( 'NI-11 Autoptimize inactive' );
} else {
	$ao_fields = function () use ( $call ) {
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/autoptimize/settings/js' );
		$n = 0;
		$l = 0;
		foreach ( (array) ( $d['groups'] ?? array() ) as $g ) {
			$n += count( (array) ( $g['fields'] ?? array() ) );
			$l += (int) ( $g['locked'] ?? 0 );
		}
		return array( $st, $n, $l, (string) ( $d['adminUrl'] ?? '' ) );
	};
	$ao_network = is_multisite() && class_exists( 'autoptimizeOptionWrapper', false ) && function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( 'autoptimize/autoptimize.php' );
	if ( ! $ao_network ) {
		list( $ao_st, $ao_n, $ao_l ) = $ao_fields();
		$check( 'NI-11 control: a per-site Autoptimize tab has editable fields', 200 === $ao_st && $ao_n > 0 && 0 === $ao_l, "{$ao_st} fields {$ao_n} locked {$ao_l}" );
		$skip( 'NI-11 network-wide: Autoptimize is not network-active here' );
	} else {
		// Their option reader keeps its first answer for the request unless
		// this is defined, so the per-site switch can be flipped for the run.
		if ( ! defined( 'TEST_MULTISITE_FORCE_AO_ON_NETWORK' ) ) {
			define( 'TEST_MULTISITE_FORCE_AO_ON_NETWORK', true );
		}
		$ao_cfg_was = get_network_option( get_main_network_id(), 'autoptimize_enable_site_config', null );
		wp_set_current_user( $cli_user );
		try {
			update_network_option( get_main_network_id(), 'autoptimize_enable_site_config', '' );
			list( $ao_st, $ao_n, $ao_l, $ao_url ) = $ao_fields();
			$check( 'NI-11 network-wide, the tab draws no editable field and locks them all', 200 === $ao_st && 0 === $ao_n && $ao_l > 0, "{$ao_st} fields {$ao_n} locked {$ao_l}" );
			$check( 'NI-11 and points to the network\'s settings', false !== strpos( $ao_url, network_admin_url( 'settings.php' ) ), $ao_url );
			list( $ao_st ) = $call( 'POST', '/minn-admin/v1/autoptimize/settings/js', array( 'values' => array( 'autoptimize_js' => true ) ) );
			$check( 'NI-11 control: a save there is still refused', 409 === $ao_st, (string) $ao_st );
			update_network_option( get_main_network_id(), 'autoptimize_enable_site_config', 'on' );
			list( $ao_st, $ao_n, $ao_l ) = $ao_fields();
			$check( 'NI-11 control: with per-site configuration on, the tab has editable fields', 200 === $ao_st && $ao_n > 0 && 0 === $ao_l, "{$ao_st} fields {$ao_n} locked {$ao_l}" );
		} finally {
			if ( null === $ao_cfg_was ) {
				delete_network_option( get_main_network_id(), 'autoptimize_enable_site_config' );
			} else {
				update_network_option( get_main_network_id(), 'autoptimize_enable_site_config', $ao_cfg_was );
			}
		}
	}
}

// --- NI-11 class: Asset CleanUp before its settings were ever saved -----------
// Asset CleanUp Lite does not load on REST requests, so its defaults are not
// there for a save to start from, and Minn refuses every save until its own
// Settings page has been saved once. The tab drew editable fields anyway. A
// child run makes the request look like the app's (REST, so it does not
// load) and reads the row as never saved; writes to the row are blocked
// there, and the row is compared before and after.
if ( ! function_exists( 'minn_admin_asset_cleanup_active' ) || ! minn_admin_asset_cleanup_active() ) {
	$skip( 'NI-11 Asset CleanUp inactive' );
} elseif ( ! class_exists( 'WP_CLI' ) || ! function_exists( 'proc_open' ) ) {
	$skip( 'NI-11 Asset CleanUp needs WP-CLI and a child process' );
} else {
	global $wpdb;
	$acu_key  = minn_admin_asset_cleanup_option_key();
	$acu_row  = function () use ( $wpdb, $acu_key ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $acu_key ), ARRAY_A );
	};
	$acu_was  = $acu_row();
	$acu_base = realpath( sys_get_temp_dir() ) . '/minn-probe-acu-' . wp_generate_password( 8, false, false );
	wp_mkdir_p( $acu_base );
	file_put_contents(
		$acu_base . '/child.php',
		"<?php\n"
		. "\$shape = function () { \$d = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/asset-cleanup/settings/cleanup' ) ); \$n = 0; \$l = 0;\n"
		. "\tforeach ( (array) ( \$d->get_data()['groups'] ?? array() ) as \$g ) { \$n += count( (array) ( \$g['fields'] ?? array() ) ); \$l += (int) ( \$g['locked'] ?? 0 ); }\n"
		. "\treturn array( \$d->get_status(), \$n, \$l, (string) ( \$d->get_data()['groups'][0]['desc'] ?? '' ) ); };\n"
		. "\$out = array( 'loaded' => class_exists( '\\\\WpAssetCleanUp\\\\Settings', false ), 'get' => \$shape() );\n"
		. "if ( defined( 'MINN_PROBE_ACU_UNSAVED' ) ) {\n"
		. "\t\$p = new WP_REST_Request( 'POST', '/minn-admin/v1/asset-cleanup/settings/cleanup' ); \$p->set_header( 'content-type', 'application/json' ); \$p->set_body( wp_json_encode( array( 'values' => array( 'disable_emojis' => true ) ) ) );\n"
		. "\t\$out['post'] = rest_do_request( \$p )->get_status();\n"
		. "}\n"
		. "echo 'MINNPROBE ' . wp_json_encode( \$out ) . \"\\n\";\n"
	);
	$acu_rest = "<?php\nWP_CLI::add_wp_hook( 'muplugins_loaded', function () { \$_SERVER['REQUEST_URI'] = '/wp-json/minn-admin/v1/asset-cleanup/settings/cleanup'; } );\n";
	file_put_contents( $acu_base . '/rest.php', $acu_rest );
	file_put_contents(
		$acu_base . '/unsaved.php',
		$acu_rest
		. "define( 'MINN_PROBE_ACU_UNSAVED', true );\n"
		. "WP_CLI::add_wp_hook( 'pre_option_' . " . var_export( $acu_key, true ) . ", function () { return ''; } );\n"
		. "WP_CLI::add_wp_hook( 'pre_update_option_' . " . var_export( $acu_key, true ) . ", function ( \$value, \$old ) { return \$old; }, 10, 2 );\n"
	);
	$acu_run = function ( $require ) use ( $acu_base ) {
		$o = WP_CLI::runcommand( 'eval-file ' . escapeshellarg( $acu_base . '/child.php' ) . ' --require=' . escapeshellarg( $acu_base . '/' . $require ), array( 'launch' => true, 'return' => 'all', 'exit_error' => false ) );
		return preg_match( '/^MINNPROBE (.+)$/m', (string) $o->stdout, $m ) ? (array) json_decode( $m[1], true ) : array( 'error' => substr( trim( $o->stderr . ' ' . $o->stdout ), 0, 200 ) );
	};
	try {
		$acu_unsaved = $acu_run( 'unsaved.php' );
		if ( ! isset( $acu_unsaved['get'] ) || ! empty( $acu_unsaved['loaded'] ) ) {
			$skip( 'NI-11 Asset CleanUp: the child run did not answer as a REST request would — ' . wp_json_encode( $acu_unsaved ) );
		} else {
			list( $acu_st, $acu_n, $acu_l ) = $acu_unsaved['get'];
			$check( 'NI-11 Asset CleanUp, never saved, on a REST request: the tab draws no editable field and locks them all', 200 === $acu_st && 0 === $acu_n && $acu_l > 0, "{$acu_st} fields {$acu_n} locked {$acu_l}" );
			$check( 'NI-11 control: a save there is still refused, and the stored row is untouched', 409 === ( $acu_unsaved['post'] ?? null ) && $acu_row() === $acu_was, wp_json_encode( $acu_unsaved['post'] ?? null ) );
			// Asset CleanUp deletes its row when a save leaves every value at
			// its default, so "save its Settings page once" never unlocks it.
			$check( 'Asset CleanUp: the lock no longer says one save of its Settings page unlocks it', '' !== (string) ( $acu_unsaved['get'][3] ?? '' ) && false === strpos( (string) $acu_unsaved['get'][3], 'Save its Settings page once' ), (string) ( $acu_unsaved['get'][3] ?? '' ) );
		}
		if ( ! $acu_was || '' === (string) $acu_was['option_value'] ) {
			$skip( 'NI-11 Asset CleanUp: no saved settings here for the control' );
		} else {
			$acu_saved = $acu_run( 'rest.php' );
			list( $acu_st, $acu_n, $acu_l ) = array_pad( (array) ( $acu_saved['get'] ?? array() ), 3, 0 );
			$check( 'NI-11 control: with its settings saved once, the tab has editable fields', 200 === $acu_st && $acu_n > 0 && 0 === $acu_l, "{$acu_st} fields {$acu_n} locked {$acu_l}" );
		}
	} finally {
		foreach ( array( 'child.php', 'rest.php', 'unsaved.php' ) as $f ) {
			if ( is_file( $acu_base . '/' . $f ) ) {
				unlink( $acu_base . '/' . $f );
			}
		}
		if ( is_dir( $acu_base ) ) {
			rmdir( $acu_base );
		}
		if ( $acu_row() !== $acu_was ) {
			if ( is_array( $acu_was ) ) {
				$wpdb->update( $wpdb->options, $acu_was, array( 'option_name' => $acu_key ) );
			} else {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $acu_key ) );
			}
			wp_cache_delete( $acu_key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}

$summary();
