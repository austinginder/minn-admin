<?php
/**
 * The activity logs on the resource kernel: the vendor rules the port
 * reads back as values, and the writes that now say done only when done.
 *
 * Aryo's role clause is parsed from what AAL_Log_Query builds, quotes,
 * backslashes, newlines and percent signs included, and anything else
 * shows nothing. WP Activity Log's severity narrows by name or by its own
 * number; Wordfence's kind by name in any case. Limit Login Attempts calls
 * an address locked while its lockout runs, as its own screen does, even
 * when the row still carries an unlock flag from an earlier lockout, and an
 * unlock whose lockout is still there afterwards is not reported as done.
 *
 * The lockout fixtures run inside a transaction that is rolled back.
 *
 * Run: wp eval-file tests/activity-log-kernel.test.php --user=admin --path=<absolute site path>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();

// Aryo: their clause, built the way AAL_Log_Query::get_where_by_role() builds it.
$types  = array( 'Posts', "O'Brien \"x\" \\ 100%_", "two\nlines" );
$caps   = array( 'administrator', 'editor' );
$clause = function ( $types, $caps ) {
	$t = array_map( function ( $v ) {
		return '`object_type` = \'' . esc_sql( $v ) . '\'';
	}, $types );
	$c = array_map( function ( $v ) {
		return '`user_caps` = \'' . esc_sql( $v ) . '\'';
	}, $caps );
	return 'AND (' . implode( ' OR ', $t ) . ') AND (' . implode( ' OR ', $c ) . ')';
};
$parse  = function ( $sql ) {
	return function_exists( 'minn_admin_aryo_parse_scope' ) ? minn_admin_aryo_parse_scope( $sql ) : 'no parser';
};
$parsed = $parse( $clause( $types, $caps ) );
$check( "Aryo's clause reads back as the values it was built from, escapes and all", array( 'object_type' => $types, 'user_caps' => $caps ) === $parsed, wp_json_encode( $parsed ) );
$check( "Aryo's clause with one value each reads back", array( 'object_type' => array( 'Posts' ), 'user_caps' => array( 'author' ) ) === $parse( $clause( array( 'Posts' ), array( 'author' ) ) ) );
foreach ( array(
	'false'                => false,
	'an empty string'      => '',
	'an appended clause'   => $clause( $types, $caps ) . ' OR 1=1',
	'a lower-case and'     => str_replace( 'AND (', 'and (', $clause( $types, $caps ) ),
	'a missing caps group' => 'AND (`object_type` = \'Posts\')',
) as $what => $sql ) {
	$check( "Aryo's clause as {$what} shows nothing", null === $parse( $sql ) );
}

// The narrowings, read from the declarations themselves (the vendor need not be active).
$facet = function ( $id, $param ) {
	foreach ( (array) ( Minn_Admin_Kernel::resources()[ $id ]['list']['facets'] ?? array() ) as $f ) {
		if ( $param === $f['param'] ) {
			return $f;
		}
	}
	return null;
};
// What a facet narrows a value to, the way the kernel reads it.
$narrow = function ( $f, $v ) {
	if ( ! is_array( $f ) ) {
		return 'no facet';
	}
	if ( 'key' === ( $f['type'] ?? '' ) ) {
		$v = sanitize_key( $v );
		return '' === $v ? null : array( $f['column'] => $v );
	}
	if ( isset( $f['values'] ) && is_array( $f['values'] ) ) {
		$c = $f['values'][ $v ] ?? null;
		return is_callable( $c ) ? call_user_func( $c ) : $c;
	}
	return isset( $f['values'] ) && is_callable( $f['values'] ) ? call_user_func( $f['values'], $v ) : null;
};
$sev = $facet( 'wsal/events', 'severity' );
$check( "WP Activity Log's severity narrows by name", array( 'severity' => 400 ) === $narrow( $sev, 'high' ) );
$check( "and by WSAL's own number, as it did before the port", array( 'severity' => 400 ) === $narrow( $sev, '400' ) );
$check( 'and not by anything else', null === $narrow( $sev, 'foo' ) && null === $narrow( $sev, '4 00' ) );
$kind = $facet( 'wordfence/logins', 'kind' );
$check( "Wordfence's kind narrows by name in any case", array( 'fail' => 1 ) === $narrow( $kind, 'FAILED' ) );
$check( 'and a sign-in leaves out sign-outs', array( 'fail' => 0, 'action' => array( 'op' => '!=', 'value' => 'logout' ) ) === $narrow( $kind, 'success' ) );

// Limit Login Attempts.
if ( ! function_exists( 'minn_admin_llar_active' ) || ! minn_admin_llar_active() ) {
	echo "SKIP  Limit Login Attempts Reloaded is not active\n";
} else {
	$wpdb->query( 'START TRANSACTION' );
	try {
		$ip   = '203.0.113.' . wp_rand( 10, 250 );
		$user = 'minn-llar-' . wp_rand( 1000, 9999 );
		$ip2  = '198.51.100.' . wp_rand( 10, 250 );
		$log  = (array) \LLAR\Core\Config::get( 'logged' );
		$lock = (array) \LLAR\Core\Config::get( 'lockouts' );
		// Locked out again after an earlier lockout expired: the row still says unlocked.
		$log[ $ip ][ $user ]  = array( 'counter' => 3, 'date' => time() - 60, 'gateway' => 'wp_login', 'unlocked' => true );
		$log[ $ip2 ][ $user ] = array( 'counter' => 2, 'date' => time() - 30, 'gateway' => 'wp_login', 'unlocked' => true );
		$lock[ $ip ]          = time() + 600;
		$lock[ $ip2 ]         = time() + 600;
		\LLAR\Core\Config::update( 'logged', $log );
		\LLAR\Core\Config::update( 'lockouts', $lock );
		$row = null;
		foreach ( minn_admin_llar_rows() as $r ) {
			if ( md5( $ip . '|' . $user ) === $r['id'] ) {
				$row = $r;
			}
		}
		$check( 'an address whose lockout runs is locked, whatever an older unlock flag says', $row && true === $row['locked'] && 'locked' === $row['status'], wp_json_encode( $row ) );
		$r = new WP_REST_Request( 'GET', '/minn-admin/v1/llar/log' );
		$r->set_query_params( array( 'kind' => 'locked', 'search' => $user ) );
		$d = rest_do_request( $r )->get_data();
		$check( 'and the Locked out now tab lists it, as the card counts it', in_array( md5( $ip . '|' . $user ), array_column( (array) ( $d['items'] ?? array() ), 'id' ), true ), wp_json_encode( $d ) );
		$unlock = function ( $who_ip ) use ( $user ) {
			$res = rest_do_request( new WP_REST_Request( 'POST', '/minn-admin/v1/llar/log/' . md5( $who_ip . '|' . $user ) . '/unlock' ) );
			return array( $res->get_status(), $res->get_data() );
		};
		list( $st, $d ) = $unlock( $ip );
		$after          = (array) \LLAR\Core\Config::get( 'lockouts' );
		$check( 'an unlock answers ok once the lockout is gone', 200 === $st && ! empty( $d['ok'] ) && ! isset( $after[ $ip ] ), $st . ' ' . wp_json_encode( $d ) );
		// A lockouts write that does not stick (a filter, a failed write).
		$hold = function ( $new, $old ) {
			return $old;
		};
		add_filter( 'pre_update_option_limit_login_lockouts', $hold, 10, 2 );
		list( $st, $d ) = $unlock( $ip2 );
		remove_filter( 'pre_update_option_limit_login_lockouts', $hold, 10 );
		$check( 'an unlock whose lockout is still there afterwards is not reported as done', 500 === $st && 'not_applied' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
}

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nactivity-log-kernel: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
