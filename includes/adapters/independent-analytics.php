<?php
/**
 * Bundled adapter: Independent Analytics (traffic provider).
 *
 * Pageviews come from {prefix}independent_analytics_views (one row per view,
 * `viewed_at` datetime); visitors from {prefix}independent_analytics_sessions
 * (COUNT(DISTINCT visitor_id) per day of `created_at`).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! defined( 'IAWP_VERSION' ) ) {
		return $traffic;
	}

	global $wpdb;
	$views    = $wpdb->prefix . 'independent_analytics_views';
	$sessions = $wpdb->prefix . 'independent_analytics_sessions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $views ) ) !== $views ) {
		return $traffic;
	}

	$days       = max( 1, (int) $days );
	$cur_start  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	$prev_start = gmdate( 'Y-m-d 00:00:00', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS );

	$view_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(viewed_at) AS d, COUNT(*) AS p FROM {$views} WHERE viewed_at >= %s GROUP BY d", // phpcs:ignore
		$prev_start
	) );
	$visitor_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(created_at) AS d, COUNT(DISTINCT visitor_id) AS v FROM {$sessions} WHERE created_at >= %s GROUP BY d", // phpcs:ignore
		$prev_start
	) );
	if ( ! $view_rows && ! $visitor_rows ) {
		return $traffic;
	}

	$map  = array();
	$prev = 0;
	foreach ( (array) $visitor_rows as $row ) {
		if ( $row->d >= $cur_start ) {
			$map[ $row->d ] = array( 'visitors' => (int) $row->v, 'pageviews' => 0 );
		} else {
			$prev += (int) $row->v;
		}
	}
	foreach ( (array) $view_rows as $row ) {
		if ( $row->d < $cur_start ) {
			continue;
		}
		if ( ! isset( $map[ $row->d ] ) ) {
			$map[ $row->d ] = array( 'visitors' => 0, 'pageviews' => 0 );
		}
		$map[ $row->d ]['pageviews'] = (int) $row->p;
	}

	return array(
		'source'        => 'Independent Analytics',
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}, 10, 2 );

/**
 * Overview traffic-day drill-down: top pages from views × resources, plus
 * referrers from sessions.referrer_id → referrers.domain.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data ) {
		return $data;
	}
	if ( ! defined( 'IAWP_VERSION' ) ) {
		return $data;
	}

	global $wpdb;
	$views     = $wpdb->prefix . 'independent_analytics_views';
	$resources = $wpdb->prefix . 'independent_analytics_resources';
	$sessions  = $wpdb->prefix . 'independent_analytics_sessions';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $views ) ) !== $views ) {
		return $data;
	}

	$from_dt = $from . ' 00:00:00';
	$to_dt   = $to . ' 23:59:59';
	$has_res = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $resources ) ) === $resources;

	if ( $has_res ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are prefix-scoped.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.resource_id,
					COUNT(*) AS pageviews,
					COUNT(DISTINCT v.session_id) AS visitors,
					MAX(r.cached_title) AS title,
					MAX(r.cached_url) AS url,
					MAX(r.singular_id) AS singular_id,
					MAX(r.resource) AS resource
				FROM {$views} v
				LEFT JOIN {$resources} r ON r.id = v.resource_id
				WHERE v.viewed_at >= %s AND v.viewed_at <= %s
				GROUP BY v.resource_id
				ORDER BY pageviews DESC, visitors DESC
				LIMIT 25",
				$from_dt,
				$to_dt
			)
		);
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT resource_id,
					COUNT(*) AS pageviews,
					COUNT(DISTINCT session_id) AS visitors,
					NULL AS title, NULL AS url, NULL AS singular_id, NULL AS resource
				FROM {$views}
				WHERE viewed_at >= %s AND viewed_at <= %s
				GROUP BY resource_id
				ORDER BY pageviews DESC, visitors DESC
				LIMIT 25",
				$from_dt,
				$to_dt
			)
		);
	}

	$pages = array();
	foreach ( (array) $rows as $row ) {
		$post_id = (int) $row->singular_id;
		$title   = $row->title ? (string) $row->title : '';
		$url     = $row->url ? (string) $row->url : '';
		$path    = '';
		if ( $url ) {
			$path = wp_parse_url( $url, PHP_URL_PATH );
			$path = $path ? $path : '/';
		}
		if ( $post_id > 0 && '' === $title ) {
			$post = get_post( $post_id );
			if ( $post ) {
				$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES );
				$url   = get_permalink( $post ) ?: $url;
				$path  = wp_parse_url( $url, PHP_URL_PATH ) ?: $path;
			}
		}
		if ( '' === $title ) {
			$resource = $row->resource ? (string) $row->resource : '';
			if ( $resource ) {
				$title = $resource;
				$path  = $path ? $path : ( '/' === $resource[0] ? $resource : '/' . $resource );
			} else {
				$title = 'Resource #' . (int) $row->resource_id;
				$path  = $path ? $path : '/';
			}
			$url = $url ? $url : home_url( $path ? $path : '/' );
		}
		if ( '' === $path ) {
			$path = '/';
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
	$refs      = $wpdb->prefix . 'independent_analytics_referrers';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) === $sessions
		&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $refs ) ) === $refs ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ref_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COALESCE(NULLIF(r.domain, ''), r.referrer) AS label,
					COUNT(DISTINCT s.session_id) AS v
				FROM {$sessions} s
				INNER JOIN {$refs} r ON r.id = s.referrer_id
				WHERE s.created_at >= %s AND s.created_at <= %s
					AND s.referrer_id IS NOT NULL AND s.referrer_id > 0
				GROUP BY s.referrer_id
				ORDER BY v DESC
				LIMIT 15",
				$from_dt,
				$to_dt
			)
		);
		foreach ( (array) $ref_rows as $row ) {
			$label = (string) $row->label;
			if ( '' === $label ) {
				continue;
			}
			$referrers[] = array(
				'label'     => $label,
				'visitors'  => (int) $row->v,
				'pageviews' => (int) $row->v,
			);
		}
	}

	return array(
		'source'    => 'Independent Analytics',
		'pages'     => $pages,
		'referrers' => $referrers,
		'adminUrl'  => admin_url( 'admin.php?page=independent-analytics' ),
	);
}, 10, 3 );
