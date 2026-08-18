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
 * Top posts and referrers for a date window, summarized through WPCOM's own
 * summarize flag. Shared by the traffic-day drill-down and the range-wide
 * report. WPCOM reports views per page (no per-page visitor count) —
 * visitors stays 0 and the client hides the empty number.
 *
 * @return array { pages: [...], referrers: [...] } (pages empty = no answer)
 */
function minn_admin_jetpack_stats_pages_refs( $wpcom, $from, $to ) {
	$span = (int) ( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
	$base = array(
		'date'      => $to,
		'period'    => 'day',
		'num'       => max( 1, $span ),
		'summarize' => 1,
	);

	$top = $wpcom->get_top_posts( $base + array( 'max' => 25 ) );
	if ( is_wp_error( $top ) ) {
		return array( 'pages' => array(), 'referrers' => array() );
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
		return array( 'pages' => array(), 'referrers' => array() );
	}

	$referrers = array();
	$refs      = $wpcom->get_referrers( $base + array( 'max' => 15 ) );
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
		'pages'     => $pages,
		'referrers' => $referrers,
	);
}

/**
 * Overview traffic-day drill-down: the shared builder in the pages/referrers
 * shape that route expects.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data || ! minn_admin_jetpack_stats_ready() ) {
		return $data;
	}

	try {
		$r = minn_admin_jetpack_stats_pages_refs( new \Automattic\Jetpack\Stats\WPCOM_Stats(), $from, $to );
		if ( ! $r['pages'] ) {
			return $data;
		}
		return array(
			'source'    => 'Jetpack Stats',
			'pages'     => $r['pages'],
			'referrers' => $r['referrers'],
			'adminUrl'  => admin_url( 'admin.php?page=stats' ),
		);
	} catch ( \Throwable $e ) {
		return $data;
	}
}, 20, 3 );

/**
 * Range-wide report for the Stats page: pages + referrers from the shared
 * builder, plus the dimensions only WPCOM has — countries, search terms and
 * outbound clicks. Search terms are mostly encrypted these days, so that
 * section only appears when real terms came back.
 */
add_filter( 'minn_admin_traffic_report', function ( $report, $from, $to ) {
	if ( null !== $report || ! minn_admin_jetpack_stats_ready() ) {
		return $report;
	}

	try {
		$wpcom = new \Automattic\Jetpack\Stats\WPCOM_Stats();
		$r     = minn_admin_jetpack_stats_pages_refs( $wpcom, $from, $to );
		if ( ! $r['pages'] ) {
			return $report;
		}
		$sections = array();
		$pages    = array();
		foreach ( $r['pages'] as $p ) {
			$pages[] = array(
				'label'     => $p['title'],
				'sub'       => $p['path'] !== $p['title'] ? $p['path'] : '',
				'url'       => $p['url'],
				'postId'    => $p['postId'],
				'pageviews' => $p['pageviews'],
			);
		}
		$sections[] = array( 'id' => 'pages', 'label' => __( 'Top pages', 'minn-admin' ), 'rows' => $pages );
		if ( $r['referrers'] ) {
			$sections[] = array( 'id' => 'referrers', 'label' => __( 'Referrers', 'minn-admin' ), 'rows' => $r['referrers'] );
		}

		$span = (int) ( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
		$base = array(
			'date'      => $to,
			'period'    => 'day',
			'num'       => max( 1, $span ),
			'summarize' => 1,
		);

		// Countries: summary.views is [{country_code, views}] with display
		// names in the response's top-level country-info map.
		$geo = $wpcom->get_views_by_country( $base + array( 'max' => 10 ) );
		if ( ! is_wp_error( $geo ) && ! empty( $geo['summary']['views'] ) ) {
			$info = (array) ( $geo['country-info'] ?? array() );
			$rows = array();
			foreach ( array_slice( (array) $geo['summary']['views'], 0, 10 ) as $row ) {
				$code = isset( $row['country_code'] ) ? (string) $row['country_code'] : '';
				if ( '' === $code ) {
					continue;
				}
				$rows[] = array(
					'label'     => isset( $info[ $code ]['country_full'] ) ? (string) $info[ $code ]['country_full'] : $code,
					'pageviews' => (int) ( $row['views'] ?? 0 ),
				);
			}
			if ( $rows ) {
				$sections[] = array( 'id' => 'countries', 'label' => __( 'Countries', 'minn-admin' ), 'rows' => $rows );
			}
		}

		$terms = $wpcom->get_search_terms( $base + array( 'max' => 10 ) );
		if ( ! is_wp_error( $terms ) && ! empty( $terms['summary']['search_terms'] ) ) {
			$rows = array();
			foreach ( (array) $terms['summary']['search_terms'] as $row ) {
				if ( is_array( $row ) && ! empty( $row['term'] ) ) {
					$rows[] = array(
						'label'     => (string) $row['term'],
						'pageviews' => (int) ( $row['views'] ?? 0 ),
					);
				}
			}
			if ( $rows ) {
				$sections[] = array( 'id' => 'search', 'label' => __( 'Search terms', 'minn-admin' ), 'rows' => $rows );
			}
		}

		$clicks = $wpcom->get_clicks( $base + array( 'max' => 10 ) );
		if ( ! is_wp_error( $clicks ) && ! empty( $clicks['summary']['clicks'] ) ) {
			$rows = array();
			foreach ( (array) $clicks['summary']['clicks'] as $row ) {
				if ( is_array( $row ) && ! empty( $row['name'] ) ) {
					$rows[] = array(
						'label'     => (string) $row['name'],
						'url'       => isset( $row['url'] ) ? (string) $row['url'] : '',
						'pageviews' => (int) ( $row['views'] ?? 0 ),
					);
				}
			}
			if ( $rows ) {
				$sections[] = array( 'id' => 'clicks', 'label' => __( 'Outbound clicks', 'minn-admin' ), 'rows' => $rows );
			}
		}

		return array(
			'source'   => 'Jetpack Stats',
			'sections' => $sections,
			'adminUrl' => admin_url( 'admin.php?page=stats' ),
		);
	} catch ( \Throwable $e ) {
		return $report;
	}
}, 20, 3 );
