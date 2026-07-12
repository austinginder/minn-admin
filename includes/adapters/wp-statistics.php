<?php
/**
 * Bundled adapter: WP Statistics (traffic provider).
 *
 * Prefers the {prefix}statistics_summary_totals table (date, visitors, views —
 * populated by WP Statistics 14.10+ daily aggregation). When that has no rows
 * for the window, falls back to aggregating the raw visitor table
 * (one row per visitor per day; `hits` = that visitor's views).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! defined( 'WP_STATISTICS_VERSION' ) ) {
		return $traffic;
	}

	global $wpdb;
	$days       = max( 1, (int) $days );
	$cur_start  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	$prev_start = gmdate( 'Y-m-d', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS );

	$rows    = array();
	$summary = $wpdb->prefix . 'statistics_summary_totals';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $summary ) ) === $summary ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT date AS d, visitors AS v, views AS p FROM {$summary} WHERE date >= %s", // phpcs:ignore
			$prev_start
		) );
	}
	if ( ! $rows ) {
		$visitor = $wpdb->prefix . 'statistics_visitor';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $visitor ) ) !== $visitor ) {
			return $traffic;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT last_counter AS d, COUNT(*) AS v, COALESCE(SUM(hits), 0) AS p FROM {$visitor} WHERE last_counter >= %s GROUP BY last_counter", // phpcs:ignore
			$prev_start
		) );
	}
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
		'source'        => 'WP Statistics',
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}, 10, 2 );

/**
 * Overview traffic-day drill-down: top pages from statistics_pages + top
 * referrers from the visitor.referred column. Pages only store hits
 * (`count`), not unique visitors per URI — both columns report that hit
 * total so the modal stays scannable without inventing uniques.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data ) {
		return $data;
	}
	if ( ! defined( 'WP_STATISTICS_VERSION' ) ) {
		return $data;
	}

	global $wpdb;
	$pages_table = $wpdb->prefix . 'statistics_pages';
	$has_pages   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pages_table ) ) === $pages_table;

	$pages = array();
	if ( $has_pages ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix-scoped.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT uri, type, id, SUM(count) AS hits
				FROM {$pages_table}
				WHERE date >= %s AND date <= %s
				GROUP BY uri, type, id
				ORDER BY hits DESC
				LIMIT 25",
				$from,
				$to
			)
		);
		foreach ( (array) $rows as $row ) {
			$uri     = ltrim( (string) $row->uri, '/' );
			$path    = '/' . $uri;
			$type    = (string) $row->type;
			$obj_id  = (int) $row->id;
			$hits    = (int) $row->hits;
			$title   = '';
			$url     = '';
			$post_id = 0;

			if ( in_array( $type, array( 'home', 'homepage' ), true ) || ( '' === $uri && 0 === $obj_id ) ) {
				$title = 'Homepage';
				$path  = '/';
				$url   = home_url( '/' );
			} elseif ( $obj_id > 0 && ( 'post' === $type || 'page' === $type || 0 === strpos( $type, 'post_type_' ) ) ) {
				$post = get_post( $obj_id );
				if ( $post ) {
					$post_id = $obj_id;
					$title   = html_entity_decode( get_the_title( $post ), ENT_QUOTES );
					$url     = get_permalink( $post ) ?: home_url( $path );
					$path    = wp_parse_url( $url, PHP_URL_PATH ) ?: $path;
				}
			} elseif ( $obj_id > 0 && in_array( $type, array( 'category', 'post_tag' ), true ) ) {
				$term = get_term( $obj_id );
				if ( $term && ! is_wp_error( $term ) ) {
					$title = $term->name;
					$link  = get_term_link( $term );
					$url   = is_wp_error( $link ) ? home_url( $path ) : $link;
				}
			}

			if ( '' === $title ) {
				$title = $path ? $path : 'Homepage';
				$url   = home_url( $path ? $path : '/' );
			}

			$pages[] = array(
				'title'     => $title,
				'path'      => $path ? $path : '/',
				'url'       => $url,
				'postId'    => $post_id,
				// Hits only — WP Statistics does not store uniques per URI.
				'visitors'  => $hits,
				'pageviews' => $hits,
			);
		}
	}

	$referrers     = array();
	$visitor_table = $wpdb->prefix . 'statistics_visitor';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $visitor_table ) ) === $visitor_table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ref_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT referred, COUNT(*) AS v
				FROM {$visitor_table}
				WHERE last_counter >= %s AND last_counter <= %s
					AND referred IS NOT NULL AND referred != ''
				GROUP BY referred
				ORDER BY v DESC
				LIMIT 15",
				$from,
				$to
			)
		);
		foreach ( (array) $ref_rows as $row ) {
			$ref = (string) $row->referred;
			if ( '' === $ref ) {
				continue;
			}
			$host = wp_parse_url( $ref, PHP_URL_HOST );
			// referred is sometimes a bare host without a scheme.
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

	if ( ! $pages && ! $referrers ) {
		return $data; // no breakdown — leave room for another provider
	}

	return array(
		'source'    => 'WP Statistics',
		'pages'     => $pages,
		'referrers' => $referrers,
		'adminUrl'  => admin_url( 'admin.php?page=wps_overview_page' ),
	);
}, 10, 3 );
