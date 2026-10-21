<?php
/**
 * The hand-written write surface may only shrink.
 *
 * bin/write-surface.php lists every way a request can change something
 * through Minn and who owns the rules for it. The writes whose rules an
 * adapter or a Minn screen writes by hand (a kernel handler, a route an
 * adapter registers, a panel save or an admin-ajax action) are compared with
 * tests/fixtures/write-surface-<single|network>.json:
 *
 * - a hand-written write the snapshot does not list fails: declare it through
 *   a tier (a resource action, minn_admin_settings(), minn_admin_fields()),
 *   or review it and rewrite the snapshot;
 * - a listed write that no longer registers (ported to a tier, removed, or
 *   its plugin is off) is reported, not failed.
 *
 * Minn's own screens (the core tier) are listed beside them, so a new core
 * write is reviewed the same way.
 *
 * Run: wp eval-file tests/write-surface.test.php --user=admin --path=<site>
 *      (on a network: --url=<a site other than the main one>)
 * Rewrite the snapshot after reviewing a change: MINN_WS_SNAPSHOT=write
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}

$args = array( 'json' );
ob_start();
include dirname( __DIR__ ) . '/bin/write-surface.php';
$census = json_decode( (string) ob_get_clean(), true );
$check( 'the census runs', is_array( $census ) && isset( $census['writes'] ) );
if ( ! is_array( $census ) || ! isset( $census['writes'] ) ) {
	exit( 1 );
}

$reviewed = array( 'handler', 'route', 'panel', 'ajax', 'core' );
$now      = array();
foreach ( $census['writes'] as $w ) {
	if ( in_array( $w['tier'], $reviewed, true ) ) {
		$now[] = "{$w['tier']} {$w['method']} {$w['path']}" . ( '' !== $w['adapter'] ? " [{$w['adapter']}]" : '' );
	}
}
$now = array_values( array_unique( $now ) );
sort( $now );

$file = __DIR__ . '/fixtures/write-surface-' . ( is_multisite() ? 'network' : 'single' ) . '.json';
if ( 'write' === getenv( 'MINN_WS_SNAPSHOT' ) ) {
	file_put_contents( $file, wp_json_encode( array( 'writes' => $now ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	echo 'WROTE ' . basename( $file ) . ' (' . count( $now ) . " writes)\n";
}
$snap = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
$check( 'a snapshot exists to compare with', is_array( $snap ) && isset( $snap['writes'] ), basename( $file ) . ' is missing: review the writes and run with MINN_WS_SNAPSHOT=write' );
if ( ! is_array( $snap ) || ! isset( $snap['writes'] ) ) {
	exit( 1 );
}

$new  = array_values( array_diff( $now, $snap['writes'] ) );
$gone = array_values( array_diff( $snap['writes'], $now ) );
$check(
	'no hand-written write the snapshot does not list (' . count( $now ) . ' now, ' . count( $snap['writes'] ) . ' listed)',
	! $new,
	"declare it through a tier, or review it and rewrite the snapshot:\n      " . implode( "\n      ", $new )
);
foreach ( $gone as $g ) {
	echo "NOTE  listed but not registered here (ported, removed, or its plugin is off): {$g}\n";
}
$hand = count( array_filter( $now, static function ( $k ) {
	return 0 !== strpos( $k, 'core ' );
} ) );
echo "INFO  {$hand} hand-written adapter writes, " . ( count( $now ) - $hand ) . " core writes, {$census['summary']['total']} writes in all\n";

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
echo "\nwrite-surface: " . ( count( $results ) - $failed ) . '/' . count( $results ) . " passed\n";
if ( $failed ) {
	exit( 1 );
}
