<?php
/**
 * Clear site cache — one action across every cache layer the site runs.
 *
 * Each provider is detected by its own API surface and purged through the
 * plugin's public purge call, wrapped in a Throwable guard so one broken
 * cache plugin can never break the action. Third parties can add providers
 * via the `minn_admin_cache_purgers` filter:
 *
 *   add_filter( 'minn_admin_cache_purgers', function ( $purgers ) {
 *       $purgers[] = array(
 *           'id'    => 'my-cache',
 *           'name'  => 'My Cache',
 *           'purge' => function () { my_cache_flush(); },
 *       );
 *       return $purgers;
 *   } );
 */

defined( 'ABSPATH' ) || exit;

/**
 * Active cache providers as [{id, name, purge}] — detection runs at call
 * time so plugin toggles are always reflected.
 */
function minn_admin_cache_purgers() {
	$purgers = array();

	// Kinsta hosting mu-plugin (KMP 3.x): the global KMP object's
	// Cache_Purge covers page cache + CDN (host-local HTTP endpoints),
	// object cache and OPcache. `true` bypasses its once-per-request guard.
	if ( class_exists( '\Kinsta\KMP' ) && ! empty( $GLOBALS['kinsta_muplugin']->kinsta_cache_purge ) ) {
		$purgers[] = array(
			'id'    => 'kinsta',
			'name'  => 'Kinsta',
			'purge' => function () {
				$GLOBALS['kinsta_muplugin']->kinsta_cache_purge->purge_complete_caches( true );
			},
		);
	}

	if ( defined( 'LSCWP_V' ) ) {
		$purgers[] = array(
			'id'    => 'litespeed',
			'name'  => 'LiteSpeed Cache',
			'purge' => function () {
				do_action( 'litespeed_purge_all' );
			},
		);
	}

	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		$purgers[] = array(
			'id'    => 'wp-super-cache',
			'name'  => 'WP Super Cache',
			'purge' => function () {
				wp_cache_clear_cache();
			},
		);
	}

	if ( function_exists( 'w3tc_flush_all' ) ) {
		$purgers[] = array(
			'id'    => 'w3-total-cache',
			'name'  => 'W3 Total Cache',
			'purge' => function () {
				w3tc_flush_all();
			},
		);
	}

	if ( function_exists( 'rocket_clean_domain' ) ) {
		$purgers[] = array(
			'id'    => 'wp-rocket',
			'name'  => 'WP Rocket',
			'purge' => function () {
				rocket_clean_domain();
				if ( function_exists( 'rocket_clean_minify' ) ) {
					rocket_clean_minify();
				}
			},
		);
	}

	if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
		$purgers[] = array(
			'id'    => 'wp-fastest-cache',
			'name'  => 'WP Fastest Cache',
			'purge' => function () {
				$GLOBALS['wp_fastest_cache']->deleteCache( true );
			},
		);
	}

	if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
		$purgers[] = array(
			'id'    => 'sg-optimizer',
			'name'  => 'SiteGround Optimizer',
			'purge' => function () {
				sg_cachepress_purge_everything();
			},
		);
	} elseif ( function_exists( 'sg_cachepress_purge_cache' ) ) {
		$purgers[] = array(
			'id'    => 'sg-optimizer',
			'name'  => 'SiteGround Optimizer',
			'purge' => function () {
				sg_cachepress_purge_cache();
			},
		);
	}

	if ( class_exists( 'autoptimizeCache' ) ) {
		$purgers[] = array(
			'id'    => 'autoptimize',
			'name'  => 'Autoptimize',
			'purge' => function () {
				autoptimizeCache::clearall();
			},
		);
	}

	if ( function_exists( 'wpo_cache_flush' ) ) {
		$purgers[] = array(
			'id'    => 'wp-optimize',
			'name'  => 'WP-Optimize',
			'purge' => function () {
				wpo_cache_flush();
			},
		);
	}

	if ( class_exists( 'Cache_Enabler' ) ) {
		$purgers[] = array(
			'id'    => 'cache-enabler',
			'name'  => 'Cache Enabler',
			'purge' => function () {
				do_action( 'cache_enabler_clear_complete_cache' );
			},
		);
	}

	if ( defined( 'WPHB_VERSION' ) ) {
		$purgers[] = array(
			'id'    => 'hummingbird',
			'name'  => 'Hummingbird',
			'purge' => function () {
				do_action( 'wphb_clear_page_cache' );
			},
		);
	}

	// Elementor (free + Pro) generates per-post CSS files — stale ones are
	// the usual "my styles didn't update" culprit, so they count as a cache
	// layer here.
	if ( class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
		$purgers[] = array(
			'id'    => 'elementor',
			'name'  => 'Elementor CSS',
			'purge' => function () {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			},
		);
	}

	return apply_filters( 'minn_admin_cache_purgers', $purgers );
}

/** Slim [{id, name}] list for the boot payload / palette. */
function minn_admin_cache_purgers_boot() {
	return array_values(
		array_map(
			function ( $p ) {
				return array(
					'id'   => $p['id'],
					'name' => $p['name'],
				);
			},
			minn_admin_cache_purgers()
		)
	);
}
