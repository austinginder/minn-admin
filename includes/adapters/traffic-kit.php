<?php
/**
 * The traffic providers' kit: what every analytics adapter that reads its
 * vendor's own tables would otherwise build again. The windows a series is
 * fetched over, the series split into the current window's days and the
 * visitors before it, a top page's row, a referrer's label, and the day
 * drill-down's reply.
 *
 * Adapter tier: vendor-neutral formatting only. Queries go through the
 * kernel's SQL layer; which tables, columns and gates are each vendor's.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * The first day of the current window and of the one before it (UTC days,
 * as the Overview buckets them), for a series of $days.
 *
 * @param int $days Days in the window.
 * @return string[] array( current start, previous start ), each Y-m-d.
 */
function minn_admin_traffic_starts( $days ) {
	$days = max( 1, (int) $days );
	return array(
		gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS ),
		gmdate( 'Y-m-d', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS ),
	);
}

/**
 * A provider's answer to minn_admin_traffic: each day of the current window
 * with its visitors and pageviews, and the visitors of the window before it.
 *
 * @param string  $source    The provider's name.
 * @param array[] $rows      Rows with 'd' (Y-m-d), 'v' (visitors) and 'p' (pageviews).
 * @param string  $cur_start The current window's first day.
 * @return array
 */
function minn_admin_traffic_series( $source, array $rows, $cur_start ) {
	$map  = array();
	$prev = 0;
	foreach ( $rows as $row ) {
		$d = (string) $row['d'];
		if ( $d >= $cur_start ) {
			$map[ $d ] = array( 'visitors' => (int) $row['v'], 'pageviews' => (int) $row['p'] );
		} else {
			$prev += (int) $row['v'];
		}
	}
	return array(
		'source'        => $source,
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}

/**
 * A top page's row: the post's title and permalink when the vendor tied the
 * hit to one, its path otherwise.
 *
 * @param int    $post_id   The post the vendor tied the hit to, or 0.
 * @param string $path      The path it recorded.
 * @param int    $visitors  Visitors.
 * @param int    $pageviews Pageviews.
 * @return array title, path, url, postId, visitors, pageviews
 */
function minn_admin_traffic_page( $post_id, $path, $visitors, $pageviews ) {
	$post_id = (int) $post_id;
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
		$title = ( '/' === $path || '' === $path ) ? 'Homepage' : $path;
		$url   = home_url( $path ? $path : '/' );
	}
	return array(
		'title'     => $title,
		'path'      => $path,
		'url'       => $url,
		'postId'    => $post_id,
		'visitors'  => (int) $visitors,
		'pageviews' => (int) $pageviews,
	);
}

/**
 * A referrer's row, labelled by its host so the list stays scannable (a
 * value with no host, a bare domain, is its own label).
 *
 * @param string $ref       The referrer as the vendor stored it.
 * @param int    $visitors  Visitors.
 * @param int    $pageviews Pageviews.
 * @return array|null label, visitors, pageviews; null for an empty referrer.
 */
function minn_admin_traffic_referrer( $ref, $visitors, $pageviews ) {
	$ref = (string) $ref;
	if ( '' === $ref ) {
		return null;
	}
	$host = wp_parse_url( $ref, PHP_URL_HOST );
	return array(
		'label'     => $host ? $host : $ref,
		'visitors'  => (int) $visitors,
		'pageviews' => (int) $pageviews,
	);
}

/**
 * Referrer rows from grouped totals, empty ones left out.
 *
 * @param array[] $rows Rows.
 * @param string  $ref  The referrer's key in a row.
 * @param string  $v    Visitors' key.
 * @param string  $p    Pageviews' key.
 * @return array[]
 */
function minn_admin_traffic_referrers( array $rows, $ref, $v, $p ) {
	$out = array();
	foreach ( $rows as $row ) {
		$one = minn_admin_traffic_referrer( $row[ $ref ] ?? '', $row[ $v ] ?? 0, $row[ $p ] ?? 0 );
		if ( $one ) {
			$out[] = $one;
		}
	}
	return $out;
}

/** An inclusive window of Y-m-d days as conditions on a column ($from to $to). */
function minn_admin_traffic_window( $column, $from, $to ) {
	return array(
		$column           => array( 'op' => '>=', 'value' => $from ),
		$column . "\0to" => array( 'op' => '<=', 'value' => $to ),
	);
}

/** The day drill-down's reply. */
function minn_admin_traffic_day_reply( $source, array $pages, array $referrers, $admin_url ) {
	return array(
		'source'    => $source,
		'pages'     => $pages,
		'referrers' => $referrers,
		'adminUrl'  => $admin_url,
	);
}
