<?php
/**
 * Load plugins the dev site keeps switched off for ONE WP-CLI process.
 *
 * The site keeps one provider per family active, so a section that needs
 * another one swaps it in here: no activation hook runs and active_plugins
 * is never written.
 *
 *   MINN_SWAP_ADD=folders/folders.php,suretriggers/suretriggers.php \
 *   MINN_SWAP_DROP=filebird/filebird.php,happyfiles/happyfiles.php \
 *   MINN_SWAP_FORGET=premio_folders_settings,suretriggers_webhook_requests_db_version \
 *   wp eval-file tests/sweep-v044.test.php --user=admin --require=tests/lib/plugin-swap.php
 *
 * MINN_SWAP_FORGET names options a swapped-in plugin writes just by loading;
 * each one that did not exist before the plugins loaded is deleted when the
 * process ends, so a run leaves no trace of the visit.
 *
 * @package minn-admin
 */

$minn_swap_list = function ( $name ) {
	return array_values( array_filter( array_map( 'trim', explode( ',', (string) getenv( $name ) ) ) ) );
};

WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) use ( $minn_swap_list ) {
	$plugins = array_values( array_diff( (array) $plugins, $minn_swap_list( 'MINN_SWAP_DROP' ) ) );
	foreach ( $minn_swap_list( 'MINN_SWAP_ADD' ) as $plugin ) {
		if ( ! in_array( $plugin, $plugins, true ) ) {
			$plugins[] = $plugin;
		}
	}
	return $plugins;
}, 1 );

$minn_swap_absent = array();
WP_CLI::add_wp_hook( 'muplugins_loaded', function () use ( $minn_swap_list, &$minn_swap_absent ) {
	foreach ( $minn_swap_list( 'MINN_SWAP_FORGET' ) as $option ) {
		$sentinel = new stdClass();
		if ( get_option( $option, $sentinel ) === $sentinel ) {
			$minn_swap_absent[] = $option;
		}
	}
} );
WP_CLI::add_wp_hook( 'shutdown', function () use ( &$minn_swap_absent ) {
	foreach ( $minn_swap_absent as $option ) {
		delete_option( $option );
	}
} );
