<?php
/**
 * WP-CLI --require file: runs ONE process with another SEO provider loaded in
 * place of Yoast, and puts back the options that provider's load writes.
 *
 *   MINN_SEO_SWAP=rank-math   seo-by-rank-math/rank-math.php
 *   MINN_SEO_SWAP=surerank    surerank/surerank.php
 *
 * active_plugins in the database is never written; the filter changes the
 * read. Every option row whose name carries the provider's prefix is
 * snapshot before any plugin loads and restored byte for byte at shutdown
 * (rows added during the run are deleted), so a run leaves the inactive
 * plugin as it was. tests/lib/aioseo-swap.php is the AIOSEO version.
 *
 * Run: MINN_SEO_SWAP=rank-math wp eval-file <file> --require=<this file> --user=admin   (from the site root)
 *
 * @package minn-admin
 */

$minn_seo_swap = array(
	'rank-math' => array( 'seo-by-rank-math/rank-math.php', array( 'rank_math%', 'rank-math%' ) ),
	'surerank'  => array( 'surerank/surerank.php', array( 'surerank%' ) ),
)[ (string) getenv( 'MINN_SEO_SWAP' ) ] ?? null;

if ( $minn_seo_swap ) {
	WP_CLI::add_wp_hook( 'option_active_plugins', function ( $plugins ) use ( $minn_seo_swap ) {
		$plugins = array_values( array_diff( (array) $plugins, array(
			'wordpress-seo/wp-seo.php',
			'wordpress-seo-premium/wp-seo-premium.php',
		) ) );
		$plugins[] = $minn_seo_swap[0];
		return $plugins;
	} );

	WP_CLI::add_wp_hook( 'muplugins_loaded', function () use ( $minn_seo_swap ) {
		global $wpdb;
		$where = implode( ' OR ', array_map( function ( $like ) use ( $wpdb ) {
			return $wpdb->prepare( 'option_name LIKE %s', $like );
		}, $minn_seo_swap[1] ) );
		$read  = function () use ( $wpdb, $where ) {
			$rows = array();
			foreach ( $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE {$where}", ARRAY_A ) as $row ) {
				$rows[ $row['option_name'] ] = $row;
			}
			return $rows;
		};
		$held = $read();
		register_shutdown_function( function () use ( $wpdb, $read, $held ) {
			$fixed = 0;
			foreach ( $read() as $name => $now ) {
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
			fwrite( STDERR, sprintf( "seo swap: restored %d option row(s)\n", $fixed ) );
		} );
	}, 0 );
}
