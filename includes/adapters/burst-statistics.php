<?php
/**
 * Bundled adapter: Burst Statistics (traffic provider).
 *
 * Burst stores one row per pageview in {prefix}burst_statistics with a unix
 * `time` and a per-visitor `uid`, so daily totals are COUNT(*) for pageviews
 * and COUNT(DISTINCT uid) for visitors.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Burst Statistics is loaded AND this user may read its reports.
 *
 * Burst grants `view_burst_statistics` to administrator and editor at
 * activation (includes/class-bootstrap.php).
 */
function minn_admin_burst_ready() {
	// In network-wide mode Burst keeps statistics on the main site and simply
	// does not register its page anywhere else -- and it grants the capability
	// on every subsite regardless, so the capability cannot express the rule.
	// This subsite's own table is empty in that mode, so the surface would
	// report nothing and look broken besides.
	if ( is_multisite() && ! is_main_site() && get_site_option( 'burst_track_network_wide' ) ) {
		return false;
	}
	return ( defined( 'BURST_VERSION' ) || class_exists( 'Burst\\Burst' ) )
		&& current_user_can( 'view_burst_statistics' );
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! defined( 'BURST_VERSION' ) ) {
		return $traffic;
	}
	if ( ! minn_admin_burst_ready() || ! Minn_Admin_Kernel_SQL::exists( 'burst_statistics' ) ) {
		return $traffic;
	}
	$days         = max( 1, (int) $days );
	list( $cur_start ) = minn_admin_traffic_starts( $days );
	// A hit's day is the UTC day of its epoch, as the drill-down's windows are.
	$rows = Minn_Admin_Kernel_SQL::totals(
		'burst_statistics',
		array( 'd' => array( 'day' => 'time', 'clock' => 'local-epoch' ) ),
		array( 'v' => array( 'distinct' => 'uid' ), 'p' => array( 'count' => '*' ) ),
		array( 'time' => array( 'op' => '>=', 'value' => time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS ) ),
		array(),
		5000
	);
	if ( ! $rows ) {
		return $traffic;
	}
	return minn_admin_traffic_series( 'Burst Statistics', $rows, $cur_start );
}, 10, 2 );

/**
 * Overview traffic-day drill-down: top pages from burst_statistics.page_url
 * (+ page_id when set) and top referrers from burst_sessions.referrer.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data || ! defined( 'BURST_VERSION' ) ) {
		return $data;
	}
	if ( ! minn_admin_burst_ready() || ! Minn_Admin_Kernel_SQL::exists( 'burst_statistics' ) ) {
		return $data;
	}

	// Inclusive Y-m-d window → unix range (UTC, matching the series' days).
	$from_ts = strtotime( $from . ' 00:00:00 UTC' );
	$to_ts   = strtotime( $to . ' 23:59:59 UTC' );
	if ( ! $from_ts || ! $to_ts ) {
		return $data;
	}
	$window = array( 'time' => array( 'op' => '>=', 'value' => $from_ts ), "time\0to" => array( 'op' => '<=', 'value' => $to_ts ) );

	$rows  = Minn_Admin_Kernel_SQL::totals(
		'burst_statistics',
		array( 'page_url', 'page_id' ),
		array( 'visitors' => array( 'distinct' => 'uid' ), 'pageviews' => array( 'count' => '*' ) ),
		$window,
		array( 'pageviews' => 'DESC', 'visitors' => 'DESC' ),
		25
	);
	$pages = array();
	foreach ( $rows as $row ) {
		$path = (string) $row['page_url'];
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}
		$pages[] = minn_admin_traffic_page( $row['page_id'], $path, $row['visitors'], $row['pageviews'] );
	}

	$referrers = array();
	if ( Minn_Admin_Kernel_SQL::exists( 'burst_sessions' ) ) {
		// Sessions lack a day column: counted against the sessions that have
		// a statistics row in the window.
		$hits      = array();
		foreach ( $window as $k => $rule ) {
			$hits[ 'st.' . $k ] = $rule;
		}
		$referrers = minn_admin_traffic_referrers(
			Minn_Admin_Kernel_SQL::totals(
				array( 'table' => 'burst_sessions', 'joins' => array( 'st' => array( 'table' => 'burst_statistics', 'on' => array( 'session_id' => 'e.ID' ) ) ) ),
				array( 'referrer' => 'ref' ),
				array( 'v' => array( 'distinct' => 'ID' ) ),
				$hits + array( 'referrer' => array( 'not' => array( '' ) ) ),
				array( 'v' => 'DESC' ),
				15
			),
			'ref',
			'v',
			'v'
		);
	}

	return minn_admin_traffic_day_reply( 'Burst Statistics', $pages, $referrers, admin_url( 'admin.php?page=burst' ) );
}, 10, 3 );
