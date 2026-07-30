<?php
/**
 * Bundled adapter: Jetpack Stats (traffic provider).
 *
 * Stats data lives on WordPress.com; the plugin reads it through Jetpack's OWN
 * Automattic\Jetpack\Stats\WPCOM_Stats client (blog-token request, their
 * 5-minute transient cache), so connection auth, retries and caching all stay
 * Jetpack's job. Gated on an active connection, the stats module, and
 * Jetpack's own `view_stats` capability (mapped from their allowed-roles
 * option). Registered at priority 20 like Site Kit: a purpose-installed
 * analytics plugin (Koko &co at 10) answers first; Jetpack is the fallback
 * many sites already run.
 *
 * Day labels arrive from WPCOM in the blog's timezone. The visits endpoint is
 * asked for `views,visitors` explicitly — the default response carries views
 * only.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * True when Jetpack is connected, the stats module is on, and the current
 * user may view stats (their own `view_stats` meta capability).
 */
function minn_admin_jetpack_stats_ready() {
	if ( ! class_exists( '\Automattic\Jetpack\Stats\WPCOM_Stats' )
		|| ! class_exists( '\Automattic\Jetpack\Connection\Manager' )
		|| ! class_exists( '\Automattic\Jetpack\Modules' ) ) {
		return false;
	}
	if ( ! current_user_can( 'view_stats' ) ) {
		return false;
	}
	try {
		return ( new \Automattic\Jetpack\Connection\Manager() )->is_connected()
			&& ( new \Automattic\Jetpack\Modules() )->is_active( 'stats' );
	} catch ( \Throwable $e ) {
		return false;
	}
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! minn_admin_jetpack_stats_ready() ) {
		return $traffic;
	}

	$days = max( 1, (int) $days );

	try {
		$stats = ( new \Automattic\Jetpack\Stats\WPCOM_Stats() )->get_visits( array(
			'unit'        => 'day',
			'quantity'    => 2 * $days,
			'stat_fields' => 'views,visitors',
		) );
		if ( is_wp_error( $stats ) || empty( $stats['data'] ) || empty( $stats['fields'] ) ) {
			return $traffic;
		}

		$fi = array_flip( (array) $stats['fields'] );
		if ( ! isset( $fi['period'], $fi['views'], $fi['visitors'] ) ) {
			return $traffic;
		}

		// Rows are [period, views, visitors] tuples, oldest first; the last
		// $days rows are the current window, the rest feed prev_visitors.
		$rows = array();
		foreach ( (array) $stats['data'] as $row ) {
			$date = isset( $row[ $fi['period'] ] ) ? (string) $row[ $fi['period'] ] : '';
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				continue;
			}
			$rows[ $date ] = array(
				'visitors'  => (int) ( $row[ $fi['visitors'] ] ?? 0 ),
				'pageviews' => (int) ( $row[ $fi['views'] ] ?? 0 ),
			);
		}
		if ( ! $rows ) {
			return $traffic;
		}

		ksort( $rows );
		$cur  = array_slice( array_keys( $rows ), -$days );
		$map  = array();
		$prev = 0;
		foreach ( $rows as $date => $entry ) {
			if ( in_array( $date, $cur, true ) ) {
				if ( $entry['visitors'] > 0 || $entry['pageviews'] > 0 ) {
					$map[ $date ] = $entry;
				}
			} else {
				$prev += $entry['visitors'];
			}
		}

		return array(
			'source'        => 'Jetpack Stats',
			'days'          => $map,
			'prev_visitors' => $prev,
		);
	} catch ( \Throwable $e ) {
		return $traffic;
	}
}, 20, 2 );

/**
 * Overview traffic-day drill-down: top posts and referrers, summarized over
 * the requested window through WPCOM's own summarize flag. WPCOM reports
 * views per page (no per-page visitor count) — visitors stays 0 and the
 * client hides the empty number.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data || ! minn_admin_jetpack_stats_ready() ) {
		return $data;
	}

	$span = (int) ( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
	$span = max( 1, $span );

	try {
		$wpcom = new \Automattic\Jetpack\Stats\WPCOM_Stats();

		$top = $wpcom->get_top_posts( array(
			'date'      => $to,
			'period'    => 'day',
			'num'       => $span,
			'summarize' => 1,
			'max'       => 25,
		) );
		if ( is_wp_error( $top ) ) {
			return $data;
		}

		$pages = array();
		foreach ( (array) ( $top['summary']['postviews'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$href = isset( $row['href'] ) ? (string) $row['href'] : '';
			$path = $href ? ( wp_parse_url( $href, PHP_URL_PATH ) ?: '/' ) : '';
			$pages[] = array(
				'title'     => isset( $row['title'] ) ? (string) $row['title'] : $path,
				'path'      => $path,
				'url'       => $href,
				'postId'    => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'pageviews' => isset( $row['views'] ) ? (int) $row['views'] : 0,
			);
		}
		if ( ! $pages ) {
			return $data;
		}

		$referrers = array();
		$refs      = $wpcom->get_referrers( array(
			'date'      => $to,
			'period'    => 'day',
			'num'       => $span,
			'summarize' => 1,
			'max'       => 15,
		) );
		if ( ! is_wp_error( $refs ) ) {
			foreach ( (array) ( $refs['summary']['groups'] ?? array() ) as $group ) {
				if ( ! is_array( $group ) ) {
					continue;
				}
				// WPCOM groups referrers ("Search Engines") with the useful
				// names nested in results ("Google Search") — surface the
				// specific rows like their own UI does, the group otherwise.
				$rows = array();
				foreach ( (array) ( $group['results'] ?? array() ) as $result ) {
					if ( is_array( $result ) && ! empty( $result['name'] ) && (int) ( $result['views'] ?? 0 ) > 0 ) {
						$rows[] = array( 'label' => (string) $result['name'], 'pageviews' => (int) $result['views'] );
					}
				}
				if ( ! $rows ) {
					$label = (string) ( $group['name'] ?? $group['group'] ?? '' );
					$total = (int) ( $group['total'] ?? 0 );
					if ( '' !== $label && $total > 0 ) {
						$rows[] = array( 'label' => $label, 'pageviews' => $total );
					}
				}
				foreach ( $rows as $row ) {
					$referrers[] = $row;
				}
			}
		}

		return array(
			'source'    => 'Jetpack Stats',
			'pages'     => $pages,
			'referrers' => $referrers,
			'adminUrl'  => admin_url( 'admin.php?page=stats' ),
		);
	} catch ( \Throwable $e ) {
		return $data;
	}
}, 20, 3 );
