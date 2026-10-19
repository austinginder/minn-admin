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

/**
 * WP Statistics is loaded AND this user may read its reports.
 *
 * WP Statistics gates reporting on its `read_capability` OPTION, default
 * manage_options (includes/class-wp-statistics-user.php). Read the option so a
 * site that loosened or tightened it is honoured either way.
 */
function minn_admin_wps_ready() {
	if ( ! defined( 'WP_STATISTICS_VERSION' ) ) {
		return false;
	}
	$cap = 'manage_options';
	if ( class_exists( '\\WP_STATISTICS\\Option' ) && method_exists( '\\WP_STATISTICS\\Option', 'get' ) ) {
		$stored = \WP_STATISTICS\Option::get( 'read_capability', 'manage_options' );
		if ( is_string( $stored ) && '' !== $stored ) {
			$cap = $stored;
		}
	}
	return current_user_can( $cap );
}

/*
 * WP Statistics keeps its MaxMind and DB-IP licence keys in its settings
 * row, its add-on licences apart and, on a site without salts, its
 * encryption key. Declared whether or not it is active (its rows outlive it).
 */
minn_admin_secrets( 'wp-statistics', array( 'options' => array( 'wp_statistics', 'wp_statistics_licenses', 'wp_statistics_cipher_key' ) ) );

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! defined( 'WP_STATISTICS_VERSION' ) ) {
		return $traffic;
	}
	if ( ! minn_admin_wps_ready() ) {
		return $traffic;
	}
	list( $cur_start, $prev_start ) = minn_admin_traffic_starts( $days );
	$rows = array();
	if ( Minn_Admin_Kernel_SQL::exists( 'statistics_summary_totals' ) ) {
		$rows = Minn_Admin_Kernel_SQL::select( 'statistics_summary_totals', array( 'date' => 'd', 'visitors' => 'v', 'views' => 'p' ), array( 'date' => array( 'op' => '>=', 'value' => $prev_start ) ) );
	}
	if ( ! $rows ) {
		if ( ! Minn_Admin_Kernel_SQL::exists( 'statistics_visitor' ) ) {
			return $traffic;
		}
		$rows = Minn_Admin_Kernel_SQL::totals(
			'statistics_visitor',
			array( 'last_counter' => 'd' ),
			array( 'v' => array( 'count' => '*' ), 'p' => array( 'sum' => 'hits' ) ),
			array( 'last_counter' => array( 'op' => '>=', 'value' => $prev_start ) ),
			array(),
			5000
		);
	}
	if ( ! $rows ) {
		return $traffic;
	}
	return minn_admin_traffic_series( 'WP Statistics', $rows, $cur_start );
}, 10, 2 );

/**
 * Overview traffic-day drill-down: top pages from statistics_pages + top
 * referrers from the visitor.referred column. Pages only store hits
 * (`count`), not unique visitors per URI — both columns report that hit
 * total so the modal stays scannable without inventing uniques.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data || ! defined( 'WP_STATISTICS_VERSION' ) ) {
		return $data;
	}
	if ( ! minn_admin_wps_ready() ) {
		return $data;
	}

	$pages = array();
	if ( Minn_Admin_Kernel_SQL::exists( 'statistics_pages' ) ) {
		$rows = Minn_Admin_Kernel_SQL::totals( 'statistics_pages', array( 'uri', 'type', 'id' ), array( 'hits' => array( 'sum' => 'count' ) ), minn_admin_traffic_window( 'date', $from, $to ), array( 'hits' => 'DESC' ), 25 );
		foreach ( $rows as $row ) {
			$uri     = ltrim( (string) $row['uri'], '/' );
			$path    = '/' . $uri;
			$type    = (string) $row['type'];
			$obj_id  = (int) $row['id'];
			$hits    = (int) $row['hits'];
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

	$referrers = array();
	if ( Minn_Admin_Kernel_SQL::exists( 'statistics_visitor' ) ) {
		$referrers = minn_admin_traffic_referrers(
			Minn_Admin_Kernel_SQL::totals(
				'statistics_visitor',
				array( 'referred' ),
				array( 'v' => array( 'count' => '*' ) ),
				minn_admin_traffic_window( 'last_counter', $from, $to ) + array( 'referred' => array( 'not' => array( '' ) ) ),
				array( 'v' => 'DESC' ),
				15
			),
			'referred',
			'v',
			'v'
		);
	}

	if ( ! $pages && ! $referrers ) {
		return $data; // no breakdown — leave room for another provider
	}
	return minn_admin_traffic_day_reply( 'WP Statistics', $pages, $referrers, admin_url( 'admin.php?page=wps_overview_page' ) );
}, 10, 3 );
