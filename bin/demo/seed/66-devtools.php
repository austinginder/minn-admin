<?php
/**
 * Developer tools with the shop's own fingerprints: cron jobs for the stock
 * sync, workshop reminders and wholesale prices (one paused, one on a custom
 * six-hour schedule, one-off jobs queued), the shop's transients (live,
 * persistent and expired), and Scrutoscope profiles captured from real page
 * loads of the shop. Rewrite Rules Inspector needs nothing: its list is the
 * site's own rules.
 *
 * The cron hooks have no callbacks, so when WordPress runs them nothing
 * happens; they only give the lists the shop's own entries.
 *
 * Scrutoscope stores what its profiler records, so the profiles are real:
 * for the length of this part it samples every anonymous request (with query
 * logging on), a profiling session covers a few more, and two cron hooks are
 * profiled on demand. The pages are fetched from this site over loopback.
 * Afterwards it samples 5% of visitors and query logging is off again, and
 * the captures are spread back over the past week. captured_at is
 * site-local; its cleanup drops unpinned profiles older than
 * scrutoscope_retention_days (14 here).
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	$mins = function ( $minutes ) use ( $demo ) {
		return $demo->now - (int) round( $minutes * MINUTE_IN_SECONDS );
	};

	/*
	 * Cron events (WP Crontrol).
	 */
	$hooks = array( 'larkspur_nightly_stock_sync', 'larkspur_workshop_reminders', 'larkspur_wholesale_price_refresh', 'larkspur_plant_club_digest', 'larkspur_frost_alert_check', 'larkspur_review_request' );
	foreach ( $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
		// A one-off job's args are part of its key; clear those too.
		foreach ( (array) _get_cron_array() as $ts => $events ) {
			if ( isset( $events[ $hook ] ) ) {
				foreach ( $events[ $hook ] as $event ) {
					wp_unschedule_event( $ts, $hook, $event['args'] );
				}
			}
		}
	}
	if ( function_exists( 'Crontrol\Event\resume' ) ) {
		\Crontrol\Event\resume( 'larkspur_plant_club_digest' );
	}
	// Crontrol throws when the schedule exists already.
	if ( function_exists( 'Crontrol\Schedule\add' ) && ! isset( wp_get_schedules()['every_six_hours'] ) ) {
		\Crontrol\Schedule\add( 'every_six_hours', 6 * HOUR_IN_SECONDS, 'Every 6 hours' );
	}
	// The next time a local clock time comes round, at or after now.
	$next = function ( $hour, $minute, $weekday = null ) use ( $demo ) {
		for ( $d = 0; $d <= 7; $d++ ) {
			$ts = $demo->ago( -$d, $hour, $minute );
			if ( $ts > $demo->now && ( null === $weekday || (int) wp_date( 'w', $ts ) === $weekday ) ) {
				return $ts;
			}
		}
		return $demo->now + DAY_IN_SECONDS;
	};
	$six = isset( wp_get_schedules()['every_six_hours'] ) ? 'every_six_hours' : 'twicedaily';
	wp_schedule_event( $next( 2, 30 ), 'daily', 'larkspur_nightly_stock_sync' );
	wp_schedule_event( $next( 8, 0 ), 'daily', 'larkspur_workshop_reminders' );
	wp_schedule_event( $demo->now + 97 * MINUTE_IN_SECONDS, $six, 'larkspur_wholesale_price_refresh' );
	wp_schedule_event( $next( 7, 30, 1 ), 'weekly', 'larkspur_plant_club_digest' );
	wp_schedule_single_event( $next( 6, 0 ) + DAY_IN_SECONDS, 'larkspur_frost_alert_check', array( 'Portland', 'OR' ) );
	$orders = array_values( array_map( 'intval', $demo->ids( 'orders' ) ) );
	$order  = $orders ? max( $orders ) : 0;
	wp_schedule_single_event( $demo->now + 3 * HOUR_IN_SECONDS + 20 * MINUTE_IN_SECONDS, 'larkspur_review_request', array( $order ) );
	if ( function_exists( 'Crontrol\Event\pause' ) ) {
		// The plant club newsletter moved to the email service; kept, but paused.
		\Crontrol\Event\pause( 'larkspur_plant_club_digest' );
	}
	$demo->log( count( $hooks ) . ' cron events, one paused' );

	/*
	 * Transients (Transients Manager): the shop's caches.
	 */
	$transients = array(
		// name, value, expires in (minutes; 0 = never, negative = already expired)
		array( 'larkspur_weather_forecast', array( 'city' => 'Portland', 'days' => array( array( 'day' => 'Sat', 'high' => 61, 'low' => 47, 'rain' => 40 ), array( 'day' => 'Sun', 'high' => 58, 'low' => 45, 'rain' => 70 ) ) ), 170 ),
		array( 'larkspur_workshop_seats', array( 'terrariums' => 3, 'propagation' => 7, 'kokedama' => 0, 'kids-sunflowers' => 5 ), 55 ),
		array( 'larkspur_wholesale_price_sheet', array( 'updated' => wp_date( 'Y-m-d', $demo->now ), 'rows' => 42, 'currency' => 'USD' ), 330 ),
		array( 'larkspur_shipping_zone_97214', array( 'zone' => 'Portland local delivery', 'free_over' => 75 ), 1380 ),
		array( 'larkspur_featured_products', array_values( array_filter( array( $demo->id( 'products', 'anthurium' ), $demo->id( 'products', 'peace-lily' ), $demo->id( 'products', 'meadow-mix' ), $demo->id( 'products', 'workshop' ) ) ) ), 0 ),
		array( 'larkspur_journal_popular', array( 'how-often-should-you-really-water-a-cactus', 'five-houseplants-that-forgive-a-missed-watering', 'meet-the-anthurium-a-tropical-that-blooms-all-year' ), 0 ),
		array( 'larkspur_frost_alert', 'No frost expected this week.', -2900 ),
		array( 'larkspur_plant_club_count', 214, -310 ),
	);
	foreach ( $transients as $t ) {
		delete_transient( $t[0] );
		set_transient( $t[0], $t[1], $t[2] > 0 ? $t[2] * MINUTE_IN_SECONDS : ( $t[2] < 0 ? HOUR_IN_SECONDS : 0 ) );
		if ( $t[2] < 0 ) {
			// Expired: the value stays until WordPress's daily cleanup runs.
			update_option( '_transient_timeout_' . $t[0], $demo->now + $t[2] * MINUTE_IN_SECONDS, false );
		}
	}
	$demo->log( count( $transients ) . ' transients' );

	/*
	 * Scrutoscope profiles.
	 */
	if ( ! class_exists( '\Scrutoscope\Profiler\Storage' ) || ! class_exists( '\Scrutoscope\Profiler\Profiler' ) ) {
		return;
	}
	$table = \Scrutoscope\Profiler\Storage::table_name();
	$old   = $demo->id( 'devtools', 'scrutoscope' );
	if ( is_array( $old ) && $old ) {
		\Scrutoscope\Profiler\Storage::delete_profiles_bulk( $old );
	}
	$demo->forget( 'devtools' );

	$fetch = function ( $path, $cookies = array() ) {
		return wp_remote_get(
			home_url( $path ),
			array(
				'timeout'     => 90,
				'sslverify'   => false,
				'redirection' => 0,
				'cookies'     => $cookies,
				'headers'     => array( 'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15' ),
			)
		);
	};
	// Sampled visitors: path => when (minutes ago).
	$sampled = array(
		'/'                                         => 25,
		'/shop/'                                    => 410,
		'/product/anthurium/'                       => 1350,
		'/product/peace-lily/'                      => 2210,
		'/product-category/houseplants/'            => 3020,
		'/the-spring-workshop-calendar-is-open/'    => 4150,
		'/workshops/'                               => 5600,
		'/wp-json/wc/store/v1/products?per_page=12' => 6900,
		'/?s=fern'                                  => 7700,
	);
	// June's profiling session on the workshop pages, after the seats-left snippet went in.
	$sessions = array(
		'/product/workshop/'      => 2885,
		'/product/workshop-kids/' => 2883,
		'/cart/'                  => 2880,
	);
	// One unprofiled pass first, so the captures are warm page loads
	// (Autoptimize builds its files on a page's first visit).
	update_option( 'scrutoscope_background_profiling', 0 );
	foreach ( array_merge( array_keys( $sampled ), array_keys( $sessions ) ) as $path ) {
		$fetch( $path );
	}

	$before = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
	update_option( 'scrutoscope_retention_days', 14 );
	update_option( 'scrutoscope_user_scope', 'anonymous' );
	update_option( 'scrutoscope_sample_rate', 100 );
	update_option( 'scrutoscope_background_profiling', 1 );
	update_option( 'scrutoscope_query_profiling', 1 );

	foreach ( array_keys( $sampled ) as $path ) {
		$fetch( $path );
	}
	update_option( 'scrutoscope_background_profiling', 0 );

	$session = 'demo' . substr( md5( (string) $demo->now ), 0, 20 );
	set_transient( 'scrutoscope_active_session', $session, 30 * MINUTE_IN_SECONDS );
	$cookie   = array( new WP_Http_Cookie( array( 'name' => 'scrutoscope_session', 'value' => $session ) ) );
	foreach ( array_keys( $sessions ) as $path ) {
		$fetch( $path, $cookie );
	}
	delete_transient( 'scrutoscope_active_session' );

	// Two cron hooks profiled on demand from the Cron view.
	$profiler = \Scrutoscope\Profiler\Profiler::instance();
	$cron     = array();
	foreach ( array( 'woocommerce_cleanup_sessions' => 1440 + 200, 'larkspur_nightly_stock_sync' => 95 ) as $hook => $ago ) {
		$id = $profiler->profile_cron_hook( $hook );
		if ( ! is_wp_error( $id ) && $id ) {
			$cron[ (int) $id ] = $ago;
		}
	}

	update_option( 'scrutoscope_query_profiling', 0 );
	update_option( 'scrutoscope_sample_rate', 5 );
	update_option( 'scrutoscope_background_profiling', 1 );

	// Spread the captures back over the week; anything else that came in
	// during the window (another visitor) is left as it is. Captures keep
	// the path without its query, so the two fetches of / queue up under one
	// key, in the order they were made.
	$when = array();
	foreach ( array( 'background' => $sampled, 'session' => $sessions ) as $type => $paths ) {
		foreach ( $paths as $path => $ago ) {
			$when[ $type . ' ' . (string) wp_parse_url( $path, PHP_URL_PATH ) ][] = $ago;
		}
	}
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, profile_type, request_url, duration_ns FROM {$table} WHERE id > %d ORDER BY id", $before ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	$mine = array();
	foreach ( (array) $rows as $row ) {
		$id  = (int) $row->id;
		$key = $row->profile_type . ' ' . (string) wp_parse_url( $row->request_url, PHP_URL_PATH );
		if ( isset( $cron[ $id ] ) ) {
			$ago = $cron[ $id ];
		} elseif ( ! empty( $when[ $key ] ) ) {
			$ago = array_shift( $when[ $key ] );
		} else {
			continue;
		}
		$wpdb->update( $table, array( 'captured_at' => $demo->local( $mins( $ago ) ) ), array( 'id' => $id ) );
		$mine[ $id ] = $row;
	}
	// The shop page capture is pinned as the baseline to compare against.
	foreach ( $mine as $id => $row ) {
		if ( 'background' === $row->profile_type && '/shop/' === wp_parse_url( $row->request_url, PHP_URL_PATH ) ) {
			\Scrutoscope\Profiler\Storage::pin_profile( $id, 'Shop page before the plant finder filters go in. Compare after the next WooCommerce update.', 'shop, baseline' );
		}
	}
	$demo->remember( 'devtools', 'scrutoscope', array_keys( $mine ) );
	$demo->log( count( $mine ) . ' Scrutoscope profiles' );
};
