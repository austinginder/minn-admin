<?php
/**
 * Bundled adapter: Koko Analytics.
 *
 * The first traffic provider for the Overview chart. Koko keeps daily site
 * totals in {prefix}koko_analytics_site_stats (date, visitors, pageviews) —
 * a stable schema we read directly. When Koko is active, the Overview
 * "Activity" chart becomes a real Traffic chart with a Visitors stat card.
 *
 * Any analytics plugin can provide the same data through the
 * `minn_admin_traffic` filter. See docs/for-plugin-authors.md.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Koko Analytics is loaded AND this user may read its reports.
 *
 * Koko grants `view_koko_analytics` to administrator only (src/Plugin.php).
 * A traffic provider owns its own capability check: the consuming routes
 * (/overview, /overview/traffic-day) only require edit_posts, so without this
 * a Contributor reads the site's top URLs and referrers.
 */
function minn_admin_koko_ready() {
	return ( defined( 'KOKO_ANALYTICS_VERSION' ) || class_exists( 'KokoAnalytics\\Plugin' ) )
		&& current_user_can( 'view_koko_analytics' );
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic ) {
		return $traffic; // another provider answered first
	}
	if ( ! minn_admin_koko_ready() || ! Minn_Admin_Kernel_SQL::exists( 'koko_analytics_site_stats' ) ) {
		return $traffic;
	}
	list( $cur_start, $prev_start ) = minn_admin_traffic_starts( $days );
	$rows = Minn_Admin_Kernel_SQL::select(
		'koko_analytics_site_stats',
		array( 'date' => 'd', 'visitors' => 'v', 'pageviews' => 'p' ),
		array( 'date' => array( 'op' => '>=', 'value' => $prev_start ) ),
		array( 'date' => 'ASC' )
	);
	return minn_admin_traffic_series( 'Koko Analytics', $rows, $cur_start );
}, 10, 2 );

/**
 * Overview traffic-day drill-down: top pages + top referrers for a date
 * window, from Koko's local post_stats / paths / referrer tables.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data ) {
		return $data;
	}
	if ( ! minn_admin_koko_ready() || ! Minn_Admin_Kernel_SQL::exists( 'koko_analytics_post_stats' ) ) {
		return $data;
	}

	// Aggregate across the window (daily bars are 1 day; 90d chart buckets weeks).
	$rows  = Minn_Admin_Kernel_SQL::totals(
		array( 'table' => 'koko_analytics_post_stats', 'joins' => array( 'paths' => array( 'table' => 'koko_analytics_paths', 'on' => array( 'id' => 'e.path_id' ) ) ) ),
		array( 'path_id', 'post_id' ),
		array( 'visitors' => array( 'sum' => 'visitors' ), 'pageviews' => array( 'sum' => 'pageviews' ), 'path' => array( 'max' => 'paths.path' ) ),
		minn_admin_traffic_window( 'date', $from, $to ),
		array( 'pageviews' => 'DESC', 'visitors' => 'DESC' ),
		25
	);
	$pages = array();
	foreach ( $rows as $row ) {
		// The post's title when Koko tied the hit to a post; the path for pure URLs.
		$pages[] = minn_admin_traffic_page( $row['post_id'], $row['path'] ? (string) $row['path'] : '/', $row['visitors'], $row['pageviews'] );
	}

	// Koko 2.x renamed referrer_urls (url) to referrer_labels (value); a site
	// whose migration has not run yet still has the old table.
	list( $labels, $col ) = Minn_Admin_Kernel_SQL::exists( 'koko_analytics_referrer_labels' )
		? array( 'koko_analytics_referrer_labels', 'value' )
		: array( 'koko_analytics_referrer_urls', 'url' );
	$referrers = array();
	if ( Minn_Admin_Kernel_SQL::exists( 'koko_analytics_referrer_stats' ) && Minn_Admin_Kernel_SQL::exists( $labels ) ) {
		$referrers = minn_admin_traffic_referrers(
			Minn_Admin_Kernel_SQL::totals(
				array( 'table' => 'koko_analytics_referrer_stats', 'joins' => array( 'ru' => array( 'table' => $labels, 'on' => array( 'id' => 'e.id' ) ) ) ),
				array( 'id' ),
				array( 'url' => array( 'max' => 'ru.' . $col ), 'v' => array( 'sum' => 'unique_hits' ), 'p' => array( 'sum' => 'hits' ) ),
				// Only stats with a label: Koko's own inner join, an empty one left out.
				minn_admin_traffic_window( 'date', $from, $to ) + array( 'ru.' . $col => array( 'not' => array( '' ) ) ),
				array( 'v' => 'DESC', 'p' => 'DESC' ),
				15
			),
			'url',
			'v',
			'p'
		);
	}

	return minn_admin_traffic_day_reply( 'Koko Analytics', $pages, $referrers, admin_url( 'index.php?page=koko-analytics' ) );
}, 10, 3 );
