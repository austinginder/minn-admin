<?php
/**
 * Who reaches each Minn route, against an approved snapshot.
 *
 * Every minn-admin/v1 endpoint's permission callback is asked as each kind of
 * account (signed out, subscriber, contributor, author, editor, shop manager,
 * administrator; on a network also a site administrator and an account
 * holding network capabilities only through a site role). The answer is
 * compared with tests/fixtures/route-gates-<single|network>.json:
 *
 * - a route the snapshot does not list fails until its gate is reviewed and
 *   the snapshot rewritten;
 * - a kind of account a route now admits that the snapshot does not fails
 *   (a gate got wider);
 * - a narrower gate, or a listed route that no longer registers (its plugin
 *   is off), is reported, not failed.
 *
 * Callbacks are asked with no path parameters, so a gate that checks the
 * object answers for "no object" (the user routes read that as the account
 * itself); the snapshot records the door, not the room.
 *
 * Run: wp eval-file tests/route-gates.test.php --user=admin --path=<site>
 *      (on a network: --url=<a site other than the main one>; the network
 *      snapshot comes from the lab's WooCommerce subsite, the one run-php.sh
 *      uses, since the accounts and routes differ from site to site)
 * Rewrite the snapshot after reviewing a change: MINN_ROUTE_SNAPSHOT=write
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
$admin = get_current_user_id();
if ( ! $admin ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}

// The principals, created for this run and removed at the end.
$made  = array();
$roles = array();
$add   = static function ( $key, $role, $caps = null ) use ( &$made, &$roles ) {
	if ( null !== $caps ) {
		add_role( $role, 'Minn route probe ' . $role, $caps );
		$roles[] = $role;
	} elseif ( ! get_role( $role ) ) {
		return;
	}
	$login = 'minn-gate-' . $key . '-' . wp_rand( 1000, 9999 );
	$id    = (int) wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( $id ) {
		$made[ $key ] = $id;
	}
};
$add( 'subscriber', 'subscriber' );
$add( 'contributor', 'contributor' );
$add( 'author', 'author' );
$add( 'editor', 'editor' );
$add( 'shop_manager', 'shop_manager' );
$add( 'administrator', 'administrator' );
if ( is_multisite() ) {
	// A role on this site carrying the network capabilities (a role plugin's
	// "network manager", applied to every site and handed out by a site's
	// administrator). Core's network admin never reads it.
	$add( 'site_network_role', 'minn_gate_netrole', array( 'read' => true, 'manage_network' => true, 'manage_sites' => true, 'manage_network_users' => true, 'manage_network_options' => true, 'manage_network_plugins' => true, 'manage_network_themes' => true, 'edit_users' => true, 'list_users' => true ) );
}
$principals = array( 'signed_out' => 0 ) + $made;

// Plugins that keep the current user in a static answer for whoever they
// saw first; a real request never changes user midway, this loop does.
$as = static function ( $uid ) {
	wp_set_current_user( $uid );
	if ( class_exists( '\\WSAL\\Helpers\\User_Helper' ) && property_exists( '\\WSAL\\Helpers\\User_Helper', 'current_user' ) ) {
		$prop = new ReflectionProperty( '\\WSAL\\Helpers\\User_Helper', 'current_user' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
};
$server = rest_get_server();
$now    = array();
try {
	foreach ( $server->get_routes() as $route => $endpoints ) {
		if ( 0 !== strpos( $route, '/minn-admin/v1' ) ) {
			continue;
		}
		foreach ( (array) $endpoints as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			$methods = array_keys( array_filter( (array) $ep['methods'] ) );
			sort( $methods );
			$key  = implode( '|', $methods ) . ' ' . $route;
			$cb   = $ep['permission_callback'] ?? null;
			$open = array();
			foreach ( $principals as $who => $uid ) {
				$as( $uid );
				if ( null === $cb ) {
					$open[] = $who; // core treats a missing callback as public (with a notice)
					continue;
				}
				$req = new WP_REST_Request( $methods[0], $route );
				try {
					$ok = call_user_func( $cb, $req );
				} catch ( \Throwable $e ) {
					$ok = false;
				}
				if ( true === $ok ) {
					$open[] = $who;
				}
			}
			$now[ $key ] = isset( $now[ $key ] ) ? array_values( array_unique( array_merge( $now[ $key ], $open ) ) ) : $open;
		}
	}
} finally {
	$as( $admin );
	foreach ( $made as $uid ) {
		if ( is_multisite() ) {
			wpmu_delete_user( $uid );
		} else {
			wp_delete_user( $uid );
		}
	}
	foreach ( $roles as $role ) {
		remove_role( $role );
	}
}
ksort( $now );

$file = __DIR__ . '/fixtures/route-gates-' . ( is_multisite() ? 'network' : 'single' ) . '.json';
if ( 'write' === getenv( 'MINN_ROUTE_SNAPSHOT' ) ) {
	file_put_contents( $file, wp_json_encode( array( 'principals' => array_keys( $principals ), 'routes' => $now ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo 'WROTE ' . basename( $file ) . ': ' . count( $now ) . " endpoints\n";
	return;
}
$snap = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
if ( ! is_array( $snap ) || ! isset( $snap['routes'] ) ) {
	$check( 'a snapshot exists to compare with', false, basename( $file ) . ' is missing: review the routes and run with MINN_ROUTE_SNAPSHOT=write' );
	exit( 1 );
}
$known = $snap['routes'];
$new   = array_diff_key( $now, $known );
$wider = array();
$less  = array();
foreach ( array_intersect_key( $now, $known ) as $key => $open ) {
	$extra = array_diff( $open, (array) $known[ $key ] );
	if ( $extra ) {
		$wider[] = $key . ' now admits ' . implode( ', ', $extra );
	}
	$fewer = array_diff( (array) $known[ $key ], $open );
	if ( $fewer ) {
		$less[] = $key . ' no longer admits ' . implode( ', ', $fewer );
	}
}
$gone = array_diff_key( $known, $now );
$check( count( $now ) . ' endpoints: every one is in the snapshot', ! $new, implode( "\n      ", array_map( function ( $k ) use ( $now ) {
	return $k . ' (admits: ' . ( $now[ $k ] ? implode( ', ', $now[ $k ] ) : 'nobody without an object' ) . ')';
}, array_keys( array_slice( $new, 0, 40, true ) ) ) ) );
$check( 'no gate admits a kind of account the snapshot does not', ! $wider, implode( "\n      ", array_slice( $wider, 0, 40 ) ) );
foreach ( array_slice( $less, 0, 20 ) as $line ) {
	echo "NOTE  narrower: {$line}\n";
}
if ( $gone ) {
	echo 'NOTE  ' . count( $gone ) . " snapshot endpoint(s) not registered here (their plugin is off, or the route was removed)\n";
}

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
