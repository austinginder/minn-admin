<?php
/**
 * Ctrl-C, TERM and HUP end a PHP suite through its own cleanups.
 *
 * A signal's default action ends the process at once: no finally block and
 * no shutdown function runs, so a suite stopped mid-section leaves what it
 * seeded (an option, a file, a fixture post) in the lab. With this file
 * required first, the signal is thrown into the running code as an
 * exception instead: every open finally block puts back what it holds, and
 * the shutdown cleanups run as the uncaught exception ends the process. An
 * open transaction is rolled back as the connection closes. SIGKILL cannot
 * be caught. lib/roundtrip.php does the same for the round-trip harness.
 *
 * Use: require __DIR__ . '/lib/signals.php'; at the top of a suite.
 *
 * @package minn-admin
 */

if ( function_exists( 'pcntl_async_signals' ) && ! defined( 'MINN_SUITE_SIGNALS' ) && ! defined( 'MINN_RT_SIGNALS' ) ) {
	define( 'MINN_SUITE_SIGNALS', true );
	pcntl_async_signals( true );
	foreach ( array( SIGINT => 'SIGINT', SIGTERM => 'SIGTERM', SIGHUP => 'SIGHUP' ) as $minn_suite_sig => $minn_suite_name ) {
		pcntl_signal( $minn_suite_sig, function () use ( $minn_suite_name ) {
			printf( "FAIL  interrupted by %s: putting back what the suite changed\n", $minn_suite_name );
			throw new RuntimeException( 'interrupted by ' . $minn_suite_name );
		} );
	}
}
