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

	// --- Pack wave (v0.13.0): SpeedyCache, Redis Object Cache, Breeze,
	// Nginx Helper, Cloudflare. Same detect-by-own-API + public purge call. ---

	// SpeedyCache — Delete::run() always clears page cache + varnish + CDN;
	// minified rides the same path the Manage Cache form uses.
	if ( defined( 'SPEEDYCACHE_VERSION' ) && class_exists( '\SpeedyCache\Delete' ) ) {
		$purgers[] = array(
			'id'    => 'speedycache',
			'name'  => 'SpeedyCache',
			'purge' => function () {
				\SpeedyCache\Delete::run( array( 'minified' => true ) );
			},
		);
	}

	// Redis Object Cache (Till Krüss) — flush through core's object-cache
	// drop-in when the plugin's own drop-in is the one in place. Also feeds
	// a System health row (minn_admin_redis_object_cache_checks).
	if ( defined( 'WP_REDIS_VERSION' ) ) {
		$purgers[] = array(
			'id'    => 'redis-object-cache',
			'name'  => 'Redis Object Cache',
			'purge' => function () {
				// Prefer the plugin's own flush path when its drop-in is valid
				// (same as its admin "Flush cache" button); otherwise no-op
				// rather than flushing a different backend's group by accident.
				if ( class_exists( '\Rhubarb\RedisCache\Plugin' ) ) {
					$plugin = \Rhubarb\RedisCache\Plugin::instance();
					if ( $plugin->validate_object_cache_dropin() ) {
						wp_cache_flush();
						return;
					}
				}
				// Drop-in not active yet: still clear the runtime cache so a
				// "Clear site cache" click isn't a silent miss after enable.
				wp_cache_flush();
			},
		);
	}

	// Breeze (Cloudways) — public action runs their full clear (local +
	// Varnish + Cloudflare helper) via breeze-admin.php.
	if ( defined( 'BREEZE_VERSION' ) ) {
		$purgers[] = array(
			'id'    => 'breeze',
			'name'  => 'Breeze',
			'purge' => function () {
				do_action( 'breeze_clear_all_cache' );
			},
		);
	}

	// Nginx Helper (rtCamp) — action is wired to whichever purger they built
	// (FastCGI / PhpRedis / Predis).
	if ( defined( 'NGINX_HELPER_BASEPATH' ) || defined( 'NGINX_HELPER_BASENAME' ) ) {
		$purgers[] = array(
			'id'    => 'nginx-helper',
			'name'  => 'Nginx Helper',
			'purge' => function () {
				do_action( 'rt_nginx_helper_purge_all' );
			},
		);
	}

	// Cloudflare official plugin — Hooks::purgeCacheEverything() is the same
	// path their post/theme hooks call. Instantiating a fresh Hooks is fine:
	// the constructor rebuilds their DI from config.json + DataStore (no
	// singleton). No-ops when neither "Plugin specific cache" nor APO is on.
	if ( defined( 'CLOUDFLARE_PLUGIN_DIR' ) && class_exists( '\Cloudflare\APO\WordPress\Hooks' ) ) {
		$purgers[] = array(
			'id'    => 'cloudflare',
			'name'  => 'Cloudflare',
			'purge' => function () {
				$hooks = new \Cloudflare\APO\WordPress\Hooks();
				$hooks->purgeCacheEverything();
			},
		);
	}

	return apply_filters( 'minn_admin_cache_purgers', $purgers );
}

/**
 * System health rows for Redis Object Cache (drop-in + connection posture).
 * Empty when the plugin is not loaded.
 *
 * @return array[] List of {id,label,status,detail} checks.
 */
function minn_admin_redis_object_cache_checks() {
	if ( ! defined( 'WP_REDIS_VERSION' ) || ! class_exists( '\Rhubarb\RedisCache\Plugin' ) ) {
		return array();
	}

	$plugin = \Rhubarb\RedisCache\Plugin::instance();
	$human  = $plugin->get_status(); // Connected / Not enabled / Drop-in is outdated / …
	$redis  = $plugin->get_redis_status(); // true | false | null

	if ( true === $redis ) {
		$status = 'pass';
		$detail = 'Drop-in active · ' . $human;
	} elseif ( ! $plugin->object_cache_dropin_exists() ) {
		$status = 'warn';
		$detail = 'Plugin installed; drop-in not enabled';
	} elseif ( $plugin->object_cache_dropin_outdated() ) {
		$status = 'warn';
		$detail = 'Drop-in is outdated — update it from Redis Object Cache';
	} elseif ( false === $redis ) {
		$status = 'fail';
		$detail = 'Drop-in present but Redis is not connected';
	} else {
		$status = 'warn';
		$detail = $human;
	}

	return array(
		array(
			'id'     => 'redis-object-cache',
			'label'  => 'Redis Object Cache',
			'status' => $status,
			'detail' => $detail . ' · v' . WP_REDIS_VERSION,
		),
	);
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
