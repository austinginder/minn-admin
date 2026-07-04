<?php
/**
 * Bundled adapter: AnalyticsWP.
 *
 * AnalyticsWP keeps raw events in {prefix}analytics_wp_events (one row per
 * pageview/event, UTC timestamps, session ids). Daily traffic is aggregated
 * here the same way the plugin's own dashboard does it: pageviews are
 * event_type='pageview' rows, visitors are distinct sessions, and timestamps
 * shift into the site timezone before bucketing by date.
 *
 * Any analytics plugin can provide the same data through the
 * `minn_admin_traffic` filter. See docs/for-plugin-authors.md.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic ) {
		return $traffic; // another provider answered first
	}
	if ( ! defined( 'ANALYTICSWP_VERSION' ) ) {
		return $traffic;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'analytics_wp_events';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return $traffic;
	}

	// Site-timezone offset as ±H:MM for DATE_ADD — matches AnalyticsWP's own
	// dashboard bucketing of its UTC timestamps.
	$offset  = (int) wp_timezone()->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) );
	$hours   = intdiv( abs( $offset ), 3600 );
	$minutes = intdiv( abs( $offset ) % 3600, 60 );
	$tz      = sprintf( '%s%d:%02d', $offset < 0 ? '-' : '+', $hours, $minutes );

	$days       = max( 1, (int) $days );
	$now_local  = current_datetime();
	$cur_start  = $now_local->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
	$prev_start = $now_local->modify( '-' . ( 2 * $days - 1 ) . ' days' )->format( 'Y-m-d' );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(DATE_ADD(timestamp, INTERVAL %s HOUR_MINUTE)) AS day,
			COUNT(*) AS pageviews,
			COUNT(DISTINCT unique_session_id) AS visitors
		FROM {$table}
		WHERE event_type = 'pageview'
			AND DATE(DATE_ADD(timestamp, INTERVAL %s HOUR_MINUTE)) >= %s
		GROUP BY day
		ORDER BY day ASC", // phpcs:ignore
		$tz,
		$tz,
		$prev_start
	) );

	$map  = array();
	$prev = 0;
	foreach ( (array) $rows as $row ) {
		if ( $row->day >= $cur_start ) {
			$map[ $row->day ] = array(
				'visitors'  => (int) $row->visitors,
				'pageviews' => (int) $row->pageviews,
			);
		} else {
			$prev += (int) $row->visitors;
		}
	}

	return array(
		'source'        => 'AnalyticsWP',
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}, 10, 2 );
