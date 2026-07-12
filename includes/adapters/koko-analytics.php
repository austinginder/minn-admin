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

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic ) {
		return $traffic; // another provider answered first
	}
	if ( ! defined( 'KOKO_ANALYTICS_VERSION' ) && ! class_exists( 'KokoAnalytics\Plugin' ) ) {
		return $traffic;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'koko_analytics_site_stats';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return $traffic;
	}

	$days       = max( 1, (int) $days );
	$cur_start  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	$prev_start = gmdate( 'Y-m-d', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS );

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT date, visitors, pageviews FROM {$table} WHERE date >= %s ORDER BY date ASC", // phpcs:ignore
		$prev_start
	) );

	$map  = array();
	$prev = 0;
	foreach ( (array) $rows as $row ) {
		if ( $row->date >= $cur_start ) {
			$map[ $row->date ] = array(
				'visitors'  => (int) $row->visitors,
				'pageviews' => (int) $row->pageviews,
			);
		} else {
			$prev += (int) $row->visitors;
		}
	}

	return array(
		'source'        => 'Koko Analytics',
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}, 10, 2 );

/**
 * Overview traffic-day drill-down: top pages + top referrers for a date
 * window, from Koko's local post_stats / paths / referrer tables.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data ) {
		return $data;
	}
	if ( ! defined( 'KOKO_ANALYTICS_VERSION' ) && ! class_exists( 'KokoAnalytics\Plugin' ) ) {
		return $data;
	}

	global $wpdb;
	$stats = $wpdb->prefix . 'koko_analytics_post_stats';
	$paths = $wpdb->prefix . 'koko_analytics_paths';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats ) ) !== $stats ) {
		return $data;
	}

	// Aggregate across the window (daily bars are 1 day; 90d chart buckets weeks).
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are prefix-scoped.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ps.post_id, ps.path_id,
				SUM(ps.visitors) AS visitors,
				SUM(ps.pageviews) AS pageviews,
				MAX(paths.path) AS path
			FROM {$stats} ps
			LEFT JOIN {$paths} paths ON paths.id = ps.path_id
			WHERE ps.date >= %s AND ps.date <= %s
			GROUP BY ps.path_id, ps.post_id
			ORDER BY pageviews DESC, visitors DESC
			LIMIT 25",
			$from,
			$to
		)
	);

	$pages = array();
	foreach ( (array) $rows as $row ) {
		$post_id = (int) $row->post_id;
		$path    = $row->path ? (string) $row->path : '/';
		// Prefer the post title when Koko tied the hit to a post; path for pure URLs.
		$title = '';
		$url   = '';
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES );
				$url   = get_permalink( $post ) ?: '';
			}
		}
		if ( '' === $title ) {
			$title = ( '/' === $path || '' === $path ) ? 'Homepage' : $path;
			$url   = home_url( $path ? $path : '/' );
		}
		$pages[] = array(
			'title'     => $title,
			'path'      => $path,
			'url'       => $url,
			'postId'    => $post_id,
			'visitors'  => (int) $row->visitors,
			'pageviews' => (int) $row->pageviews,
		);
	}

	$referrers = array();
	$ref_stats = $wpdb->prefix . 'koko_analytics_referrer_stats';
	$ref_urls  = $wpdb->prefix . 'koko_analytics_referrer_urls';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_stats ) ) === $ref_stats
		&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ref_urls ) ) === $ref_urls ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ref_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ru.url, SUM(rs.unique_hits) AS v, SUM(rs.hits) AS p
				FROM {$ref_stats} rs
				INNER JOIN {$ref_urls} ru ON ru.id = rs.id
				WHERE rs.date >= %s AND rs.date <= %s
				GROUP BY rs.id
				ORDER BY v DESC, p DESC
				LIMIT 15",
				$from,
				$to
			)
		);
		foreach ( (array) $ref_rows as $row ) {
			$url = (string) $row->url;
			if ( '' === $url ) {
				continue;
			}
			// Host-only label when possible so the list stays scannable.
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$referrers[] = array(
				'label'     => $host ? $host : $url,
				'visitors'  => (int) $row->v,
				'pageviews' => (int) $row->p,
			);
		}
	}

	return array(
		'source'    => 'Koko Analytics',
		'pages'     => $pages,
		'referrers' => $referrers,
		'adminUrl'  => admin_url( 'index.php?page=koko-analytics' ),
	);
}, 10, 3 );
