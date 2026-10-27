<?php
/**
 * System health: the OPcache row reads a host-fenced status API as "on" when
 * the extension and its enable setting say so, and SiteGround Speed
 * Optimizer's caching layers get a row of their own.
 *
 * Run: wp eval-file tests/system-cache-health.test.php --path=<site> --user=admin
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $detail ? "\n      {$detail}" : '' ) . "\n";
};

/* ===== OPcache state ===== */
$state = new ReflectionMethod( 'Minn_Admin_REST', 'opcache_state_of' );
$state->setAccessible( true );
$cases = array(
	'status API answers enabled'                  => array( array( 'opcache_enabled' => true ), true, true, 'on' ),
	'status API answers disabled'                 => array( array( 'opcache_enabled' => false ), true, true, 'off' ),
	'restricted API, extension on'                => array( false, true, true, 'hidden' ),
	'restricted API, extension loaded but off'    => array( false, true, false, 'off' ),
	'no status API and no extension'              => array( false, false, false, 'missing' ),
);
foreach ( $cases as $label => $case ) {
	$got = $state->invoke( null, $case[0], $case[1], $case[2] );
	$check( "OPcache: {$label} reads {$case[3]}", $case[3] === $got, $got );
}

/* ===== SiteGround caching row ===== */
$options = array( 'siteground_optimizer_enable_cache', 'siteground_optimizer_file_caching', 'siteground_optimizer_enable_memcached' );
$saved   = array();
foreach ( $options as $option ) {
	$saved[ $option ] = get_option( $option, null );
}
$row = static function () {
	$rows = minn_admin_siteground_cache_checks();
	return $rows[0] ?? array();
};

if ( ! defined( 'SiteGround_Optimizer\VERSION' ) ) {
	$check( 'No row without SiteGround Optimizer', array() === minn_admin_siteground_cache_checks() );
	define( 'SiteGround_Optimizer\VERSION', '7.8.0' );
}

try {
	update_option( 'siteground_optimizer_enable_cache', 1 );
	update_option( 'siteground_optimizer_file_caching', 0 );
	update_option( 'siteground_optimizer_enable_memcached', 1 );
	$r = $row();
	$check( 'Dynamic cache + Memcached passes and names both', 'pass' === ( $r['status'] ?? '' ) && false !== strpos( $r['detail'] ?? '', 'Dynamic cache, Memcached' ), wp_json_encode( $r ) );

	update_option( 'siteground_optimizer_enable_cache', 0 );
	update_option( 'siteground_optimizer_file_caching', 1 );
	update_option( 'siteground_optimizer_enable_memcached', 0 );
	$r = $row();
	$check( 'File-based cache alone passes', 'pass' === ( $r['status'] ?? '' ) && false !== strpos( $r['detail'] ?? '', 'File-based cache' ), wp_json_encode( $r ) );

	update_option( 'siteground_optimizer_file_caching', 0 );
	update_option( 'siteground_optimizer_enable_memcached', 1 );
	$r = $row();
	$check( 'Memcached alone warns that page caching is off', 'warn' === ( $r['status'] ?? '' ) && 0 === strpos( $r['detail'] ?? '', 'Page caching is off' ), wp_json_encode( $r ) );

	update_option( 'siteground_optimizer_enable_memcached', 0 );
	$r = $row();
	$check( 'Every layer off warns', 'warn' === ( $r['status'] ?? '' ) && 0 === strpos( $r['detail'] ?? '', 'Installed, but every caching layer is off' ), wp_json_encode( $r ) );

	$info = Minn_Admin_REST::system_info();
	$info = $info instanceof WP_REST_Response ? $info->get_data() : $info;
	$ids  = array_map(
		static function ( $c ) {
			return $c['id'] ?? ( $c['key'] ?? '' );
		},
		$info['checks'] ?? array()
	);
	$check( 'System health carries the SiteGround row', in_array( 'siteground-cache', $ids, true ), wp_json_encode( $ids ) );
	$check( 'System health still carries the OPcache row', in_array( 'opcache', $ids, true ) );
} finally {
	foreach ( $saved as $option => $value ) {
		if ( null === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
}

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
