<?php
/**
 * WP-CLI --require file: runs ONE process with All in One SEO loaded in place
 * of Yoast, and puts back what AIOSEO's load writes when that process ends.
 *
 * active_plugins in the database is never written; the filter changes the
 * read. AIOSEO's load still writes: it bumps lastSchemaVersion in
 * aioseo_options_internal and fills {prefix}aioseo_cache. Both are snapshot
 * before any plugin loads and restored byte for byte at shutdown, so a run
 * leaves the inactive plugin exactly as it was.
 *
 * Run: wp eval-file <file> --require=<this file> --user=admin   (from the site root)
 */
WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) {
	$plugins = array_values( array_diff( (array) $plugins, array(
		'wordpress-seo/wp-seo.php',
		'wordpress-seo-premium/wp-seo-premium.php',
	) ) );
	$plugins[] = 'all-in-one-seo-pack/all_in_one_seo_pack.php';
	return $plugins;
} );

// Leave AIOSEO's pending update routine (lastActiveVersion bump, LLMS
// regeneration schedule, cache clear) for its next real activation.
WP_CLI::add_wp_hook( 'init', function () {
	if ( function_exists( 'aioseo' ) && isset( aioseo()->updates ) ) {
		remove_action( 'init', array( aioseo()->updates, 'init' ), 1001 );
		remove_action( 'init', array( aioseo()->updates, 'runUpdates' ), 1002 );
		remove_action( 'init', array( aioseo()->updates, 'updateLatestVersion' ), 3000 );
	}
}, 1000 );

// Snapshot before AIOSEO loads; restore after everything else has run
// (registered after WordPress's own shutdown hook, so it runs last).
WP_CLI::add_wp_hook( 'muplugins_loaded', function () {
	global $wpdb;
	$cache_table = $wpdb->prefix . 'aioseo_cache';
	$options     = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%aioseo%'", ARRAY_A );
	$has_cache   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) );
	$cache       = $has_cache ? $wpdb->get_results( "SELECT * FROM `{$cache_table}`", ARRAY_A ) : array();
	register_shutdown_function( function () use ( $options, $has_cache, $cache, $cache_table ) {
		global $wpdb;
		$held = array();
		foreach ( $options as $row ) {
			$held[ $row['option_name'] ] = $row;
		}
		$fixed = 0;
		foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE '%aioseo%'", ARRAY_A ) as $now ) {
			$name = $now['option_name'];
			if ( ! isset( $held[ $name ] ) ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
				$fixed++;
			} elseif ( $held[ $name ]['option_value'] !== $now['option_value'] || $held[ $name ]['autoload'] !== $now['autoload'] ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => $held[ $name ]['option_value'], 'autoload' => $held[ $name ]['autoload'] ), array( 'option_name' => $name ) );
				$fixed++;
			}
			unset( $held[ $name ] );
		}
		foreach ( $held as $row ) { // deleted during the run
			$wpdb->insert( $wpdb->options, $row );
			$fixed++;
		}
		$cache_rows = 0;
		if ( $has_cache ) {
			$now_rows = $wpdb->get_results( "SELECT * FROM `{$cache_table}`", ARRAY_A );
			if ( $now_rows !== $cache ) {
				$cache_rows = count( $now_rows );
				$wpdb->query( "DELETE FROM `{$cache_table}`" );
				foreach ( $cache as $row ) {
					$wpdb->insert( $cache_table, $row );
				}
			}
		}
		fwrite( STDERR, sprintf( "aioseo swap: restored %d option row(s); cache table %s\n", $fixed, $cache_rows ? "reset from {$cache_rows} row(s) to " . count( $cache ) : 'unchanged' ) );
	} );
}, 0 );
