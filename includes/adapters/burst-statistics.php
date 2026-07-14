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

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! defined( 'BURST_VERSION' ) ) {
		return $traffic;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'burst_statistics';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return $traffic;
	}

	$days       = max( 1, (int) $days );
	$cur_start  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	$prev_since = time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS;

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(FROM_UNIXTIME(time)) AS d, COUNT(DISTINCT uid) AS v, COUNT(*) AS p FROM {$table} WHERE time >= %d GROUP BY d", // phpcs:ignore
		$prev_since
	) );
	if ( ! $rows ) {
		return $traffic;
	}

	$map  = array();
	$prev = 0;
	foreach ( $rows as $row ) {
		if ( $row->d >= $cur_start ) {
			$map[ $row->d ] = array(
				'visitors'  => (int) $row->v,
				'pageviews' => (int) $row->p,
			);
		} else {
			$prev += (int) $row->v;
		}
	}

	return array(
		'source'        => 'Burst Statistics',
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}, 10, 2 );

/**
 * Overview traffic-day drill-down: top pages from burst_statistics.page_url
 * (+ page_id when set) and top referrers from burst_sessions.referrer.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data ) {
		return $data;
	}
	if ( ! defined( 'BURST_VERSION' ) ) {
		return $data;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'burst_statistics';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return $data;
	}

	// Inclusive Y-m-d window → unix range (UTC, matching the traffic adapter's FROM_UNIXTIME).
	$from_ts = strtotime( $from . ' 00:00:00 UTC' );
	$to_ts   = strtotime( $to . ' 23:59:59 UTC' );
	if ( ! $from_ts || ! $to_ts ) {
		return $data;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix-scoped.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT page_url, page_id,
				COUNT(DISTINCT uid) AS visitors,
				COUNT(*) AS pageviews
			FROM {$table}
			WHERE time >= %d AND time <= %d
			GROUP BY page_url, page_id
			ORDER BY pageviews DESC, visitors DESC
			LIMIT 25",
			$from_ts,
			$to_ts
		)
	);

	$pages = array();
	foreach ( (array) $rows as $row ) {
		$path    = (string) $row->page_url;
		if ( '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}
		$post_id = (int) $row->page_id;
		$title   = '';
		$url     = '';
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES );
				$url   = get_permalink( $post ) ?: '';
			}
		}
		if ( '' === $title ) {
			$title = ( '/' === $path ) ? 'Homepage' : $path;
			$url   = home_url( $path );
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
	$sessions  = $wpdb->prefix . 'burst_sessions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) === $sessions ) {
		// Sessions lack a day column; join via first hit in the window when possible.
		// Fall back to any non-empty referrer counted against sessions that have
		// a statistics row in range.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ref_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.referrer AS ref, COUNT(DISTINCT s.ID) AS v
				FROM {$sessions} s
				INNER JOIN {$table} st ON st.session_id = s.ID
				WHERE st.time >= %d AND st.time <= %d
					AND s.referrer IS NOT NULL AND s.referrer != ''
				GROUP BY s.referrer
				ORDER BY v DESC
				LIMIT 15",
				$from_ts,
				$to_ts
			)
		);
		foreach ( (array) $ref_rows as $row ) {
			$ref = (string) $row->ref;
			if ( '' === $ref ) {
				continue;
			}
			$host = wp_parse_url( $ref, PHP_URL_HOST );
			if ( ! $host && preg_match( '/^[\w.-]+\.[a-z]{2,}$/i', $ref ) ) {
				$host = $ref;
			}
			$referrers[] = array(
				'label'     => $host ? $host : $ref,
				'visitors'  => (int) $row->v,
				'pageviews' => (int) $row->v,
			);
		}
	}

	return array(
		'source'    => 'Burst Statistics',
		'pages'     => $pages,
		'referrers' => $referrers,
		'adminUrl'  => admin_url( 'admin.php?page=burst' ),
	);
}, 10, 3 );
