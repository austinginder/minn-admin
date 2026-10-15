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

/**
 * AnalyticsWP is loaded AND this user may read its reports.
 *
 * No public reporting-capability API, so fall back to the floor its own
 * dashboard uses.
 */
function minn_admin_awp_ready() {
	// AnalyticsWP registers its page at 'read' and decides access in the page
	// body instead, from a role allowlist an operator edits in its settings.
	// A capability check cannot see that, so ask their own resolver when it is
	// loaded and keep manage_options only as the floor for when it is not.
	if ( class_exists( '\\AnalyticsWP\\Lib\\SuperSimpleWP' )
		&& method_exists( '\\AnalyticsWP\\Lib\\SuperSimpleWP', 'does_current_user_have_admin_access' ) ) {
		try {
			if ( ! \AnalyticsWP\Lib\SuperSimpleWP::does_current_user_have_admin_access() ) {
				return false;
			}
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return ( defined( 'ANALYTICSWP_VERSION' ) || class_exists( 'AnalyticsWP\\Plugin' ) )
		&& current_user_can( 'manage_options' );
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic ) {
		return $traffic; // another provider answered first
	}
	if ( ! minn_admin_awp_ready() || ! defined( 'ANALYTICSWP_VERSION' ) || ! Minn_Admin_Kernel_SQL::exists( 'analytics_wp_events' ) ) {
		return $traffic;
	}

	// Site days, as AnalyticsWP's own dashboard buckets its UTC timestamps:
	// each day the site day it falls on, the window from the site's midnight.
	$days       = max( 1, (int) $days );
	$now_local  = current_datetime();
	$cur_start  = $now_local->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
	$prev_start = $now_local->modify( '-' . ( 2 * $days - 1 ) . ' days' )->format( 'Y-m-d' );
	$offset     = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );

	$rows = Minn_Admin_Kernel_SQL::totals(
		'analytics_wp_events',
		array( 'd' => array( 'day' => 'timestamp', 'clock' => 'utc' ) ),
		array( 'p' => array( 'count' => '*' ), 'v' => array( 'distinct' => 'unique_session_id' ) ),
		array(
			'event_type' => 'pageview',
			'timestamp'  => array( 'op' => '>=', 'value' => gmdate( 'Y-m-d H:i:s', strtotime( $prev_start . ' 00:00:00 UTC' ) - $offset ) ),
		),
		array( 'd' => 'ASC' ),
		5000
	);
	return minn_admin_traffic_series( 'AnalyticsWP', $rows, $cur_start );
}, 10, 2 );
