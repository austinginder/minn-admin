<?php
/**
 * Bundled adapter: MonsterInsights + ExactMetrics (traffic providers).
 *
 * Both are Awesome Motive's Google Analytics plugins and one codebase: every
 * class, function, option and capability exists twice with a different
 * prefix, so a two-row table below drives one adapter. Neither stores traffic
 * locally: reports come from Google Analytics through Awesome Motive's relay,
 * read here through the plugin's OWN overview report class (the one its
 * dashboard widget and stats block use), so the relay auth, the connected
 * property, request signing and their report cache (an hour for windows
 * ending today, a day otherwise) all stay the plugin's job. Gated on the
 * plugin's own `{prefix}_view_dashboard` meta capability (manage_options or
 * the roles chosen in its "view reports" setting), its dashboard-disabled
 * switch, and a connected profile.
 *
 * The report classes load only in wp-admin or cron, so the adapter requires
 * them itself for the REST request; the `auth` object is lazy-loaded by the
 * plugin's own magic getter. Registered at priority 14: local-store plugins
 * (10) answer without a network round trip, this one costs a relay call, and
 * the platform fallbacks (Site Kit, Jetpack Stats) sit at 20.
 *
 * Overview data shape (from the plugin's own consumers): overviewgraph
 * {labels[], sessions.datapoints[], pageviews.datapoints[]} one entry per
 * day of the requested window in order, toppages[] {title, hostname, url,
 * sessions, pageviews?}, referrals[] {url|source, sessions}, countries[]
 * {iso, name, sessions}, devices {desktop, tablet, mobile} as percentages,
 * infobox.sessions.value the window total. "Visitors" here are GA sessions,
 * the metric the overview report carries per day.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * The two plugins, keyed by prefix. Values name the plugin's accessor
 * function, directory constant, report classes and reports page.
 */
function minn_admin_mi_family() {
	return array(
		'monsterinsights' => array(
			'name'   => 'MonsterInsights',
			'fn'     => 'MonsterInsights',
			'dir'    => 'MONSTERINSIGHTS_PLUGIN_DIR',
			'report' => 'MonsterInsights_Report_Overview',
			'base'   => 'MonsterInsights_Report',
			'page'   => 'admin.php?page=monsterinsights_reports',
		),
		'exactmetrics'    => array(
			'name'   => 'ExactMetrics',
			'fn'     => 'ExactMetrics',
			'dir'    => 'EXACTMETRICS_PLUGIN_DIR',
			'report' => 'ExactMetrics_Report_Overview',
			'base'   => 'ExactMetrics_Report',
			'page'   => 'admin.php?page=exactmetrics_reports',
		),
	);
}

/**
 * The active member of the family the current user may read reports from,
 * or null. Checks the plugin's own view capability, its dashboard-disabled
 * setting and that a Google profile is connected (their own report route
 * refuses on the same three).
 *
 * @return array|null Family row with 'prefix' added.
 */
function minn_admin_mi_ready() {
	foreach ( minn_admin_mi_family() as $prefix => $row ) {
		if ( ! function_exists( $row['fn'] ) || ! defined( $row['dir'] ) ) {
			continue;
		}
		if ( ! current_user_can( $prefix . '_view_dashboard' ) ) {
			return null;
		}
		// A relay that failed moments ago is not asked again for a minute:
		// their report class caches nothing on failure, so every gated
		// overview and stats call would otherwise pay the full timeout.
		if ( get_transient( 'minn_mi_down' ) ) {
			return null;
		}
		try {
			$get_option = $prefix . '_get_option';
			if ( function_exists( $get_option ) && $get_option( 'dashboard_disabled', false ) ) {
				return null;
			}
			$plugin = call_user_func( $row['fn'] );
			$auth   = $plugin ? $plugin->auth : null;
			if ( ! $auth || ! is_callable( array( $auth, 'get_viewname' ) ) || '' === (string) $auth->get_viewname() ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		$row['prefix'] = $prefix;
		return $row;
	}
	return null;
}

/**
 * Fetch the overview report for a Y-m-d window through the plugin's own
 * report class. Returns the report's data array, or null when the plugin
 * answered with an error or nothing. Caller wraps in try/catch.
 */
function minn_admin_mi_overview( $row, $start, $end ) {
	$dir = constant( $row['dir'] );
	if ( ! class_exists( $row['base'] ) ) {
		require_once $dir . 'includes/admin/reports/abstract-report.php';
	}
	if ( ! class_exists( $row['report'] ) ) {
		require_once $dir . 'includes/admin/reports/overview.php';
	}
	if ( ! class_exists( $row['report'] ) ) {
		return null;
	}
	$report = new $row['report']();
	// Their relay request ships a 3000-second timeout. Minn's overview must
	// not pin a PHP worker behind a hung relay socket, so the call is capped
	// while this request runs (their own transport, only the timeout changes).
	$cap = function ( $args, $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( in_array( $host, array( 'api.monsterinsights.com', 'api.exactmetrics.com' ), true ) ) {
			$args['timeout'] = min( (float) ( $args['timeout'] ?? 15 ), 15 );
		}
		return $args;
	};
	add_filter( 'http_request_args', $cap, 10, 2 );
	try {
		$ret = $report->get_data( array( 'start' => $start, 'end' => $end ) );
	} finally {
		remove_filter( 'http_request_args', $cap, 10 );
	}
	if ( ! is_array( $ret ) || empty( $ret['success'] ) ) {
		set_transient( 'minn_mi_down', 1, MINUTE_IN_SECONDS );
		return null;
	}
	if ( empty( $ret['data'] ) || ! is_array( $ret['data'] ) ) {
		return null;
	}
	// Their "chart overlay" swaps in random datapoints for a site connected
	// less than a day ago with no data yet; that is demo art, not traffic.
	if ( ! empty( $ret['data']['show_chart_overlay'] ) ) {
		return null;
	}
	return $ret['data'];
}

/**
 * Day series from the overview graph: [ 'Y-m-d' => [visitors, pageviews] ].
 * Days are assigned by POSITION from $start when the series has one point
 * per day of the window (their labels are display strings), else parsed
 * from the labels as a fallback.
 */
function minn_admin_mi_day_series( $data, $start, $end ) {
	$graph    = isset( $data['overviewgraph'] ) && is_array( $data['overviewgraph'] ) ? $data['overviewgraph'] : array();
	$sessions = isset( $graph['sessions']['datapoints'] ) && is_array( $graph['sessions']['datapoints'] ) ? array_values( $graph['sessions']['datapoints'] ) : array();
	$views    = isset( $graph['pageviews']['datapoints'] ) && is_array( $graph['pageviews']['datapoints'] ) ? array_values( $graph['pageviews']['datapoints'] ) : array();
	$labels   = isset( $graph['labels'] ) && is_array( $graph['labels'] ) ? array_values( $graph['labels'] ) : array();
	$count    = count( $sessions );
	if ( ! $count ) {
		return array();
	}
	$first = strtotime( $start . ' 00:00:00 UTC' );
	$last  = strtotime( $end . ' 00:00:00 UTC' );
	$span  = ( $first && $last && $last >= $first ) ? (int) round( ( $last - $first ) / DAY_IN_SECONDS ) + 1 : 0;

	$out = array();
	for ( $i = 0; $i < $count; $i++ ) {
		if ( $span === $count ) {
			$date = gmdate( 'Y-m-d', $first + $i * DAY_IN_SECONDS );
		} else {
			$ts   = isset( $labels[ $i ] ) ? strtotime( (string) $labels[ $i ] ) : false;
			$date = $ts ? gmdate( 'Y-m-d', $ts ) : '';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			continue;
		}
		$out[ $date ] = array(
			'visitors'  => (int) $sessions[ $i ],
			'pageviews' => (int) ( $views[ $i ] ?? 0 ),
		);
	}
	return $out;
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic ) {
		return $traffic;
	}
	$row = minn_admin_mi_ready();
	if ( ! $row ) {
		return $traffic;
	}

	$days      = max( 1, (int) $days );
	$cache_key = 'minn_mi_traffic_' . $days;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		$end   = wp_date( 'Y-m-d' );
		$start = wp_date( 'Y-m-d', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS );
		$data  = minn_admin_mi_overview( $row, $start, $end );
		if ( ! $data ) {
			return $traffic;
		}
		$series = minn_admin_mi_day_series( $data, $start, $end );
		if ( ! $series ) {
			return $traffic;
		}

		ksort( $series );
		$labels = array_keys( $series );
		$cur    = array_slice( $labels, -$days );
		$out    = array();
		$prev   = 0;
		$any    = false;
		foreach ( $series as $label => $entry ) {
			if ( $entry['visitors'] > 0 || $entry['pageviews'] > 0 ) {
				$any = true;
			}
			if ( in_array( $label, $cur, true ) ) {
				if ( $entry['visitors'] > 0 || $entry['pageviews'] > 0 ) {
					$out[ $label ] = $entry;
				}
			} else {
				$prev += $entry['visitors'];
			}
		}
		// A connected property with no traffic yet must not silence another
		// provider.
		if ( ! $any ) {
			return $traffic;
		}

		$result = array(
			'source'        => $row['name'],
			'days'          => $out,
			'prev_visitors' => $prev,
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $traffic;
	}
}, 14, 2 );

/**
 * Top pages + referrers from one overview report. Shared by the day
 * drill-down and the range report.
 *
 * @return array { pages: [...], referrers: [...] } (pages empty = no answer)
 */
function minn_admin_mi_pages_refs( $data ) {
	$pages = array();
	foreach ( isset( $data['toppages'] ) && is_array( $data['toppages'] ) ? $data['toppages'] : array() as $p ) {
		if ( ! is_array( $p ) ) {
			continue;
		}
		$title = (string) ( $p['title'] ?? '' );
		$path  = (string) ( $p['url'] ?? '' );
		$host  = (string) ( $p['hostname'] ?? '' );
		if ( '' !== $path && '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		// The hostname is whatever hits reached the GA property from; only
		// this site's own host becomes a link (a foreign host would make a
		// plausible row open an attacker-chosen URL).
		$url = '';
		if ( $host && $path ) {
			$own   = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
			$their = strtolower( preg_replace( '/^www\./', '', (string) wp_parse_url( $host, PHP_URL_HOST ) ) );
			if ( '' !== $own && $own === $their ) {
				$url = esc_url_raw( untrailingslashit( $host ) . $path );
			}
		}
		$post_id = $url ? url_to_postid( $url ) : 0;
		if ( '' === $title || '(not set)' === $title ) {
			$title = $post_id ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES ) : ( $path ? $path : '/' );
		}
		$pages[] = array(
			'title'     => $title,
			'path'      => $path ? $path : '/',
			'url'       => $url,
			'postId'    => $post_id,
			'visitors'  => (int) ( $p['sessions'] ?? 0 ),
			'pageviews' => (int) ( $p['pageviews'] ?? 0 ),
		);
		if ( count( $pages ) >= 25 ) {
			break;
		}
	}
	if ( ! $pages ) {
		return array( 'pages' => array(), 'referrers' => array() );
	}

	$referrers = array();
	foreach ( isset( $data['referrals'] ) && is_array( $data['referrals'] ) ? $data['referrals'] : array() as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$label = (string) ( $r['url'] ?? $r['source'] ?? $r['name'] ?? $r['label'] ?? '' );
		$label = preg_replace( '#^https?://#', '', $label );
		if ( '' === $label || '(direct)' === $label ) {
			continue;
		}
		$referrers[] = array(
			'label'    => $label,
			'visitors' => (int) ( $r['sessions'] ?? 0 ),
		);
		if ( count( $referrers ) >= 15 ) {
			break;
		}
	}

	return array(
		'pages'     => $pages,
		'referrers' => $referrers,
	);
}

add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data ) {
		return $data;
	}
	$row = minn_admin_mi_ready();
	if ( ! $row ) {
		return $data;
	}

	$cache_key = 'minn_mi_traffic_day_' . md5( $from . '|' . $to );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		$report = minn_admin_mi_overview( $row, $from, $to );
		if ( ! $report ) {
			return $data;
		}
		$r = minn_admin_mi_pages_refs( $report );
		if ( ! $r['pages'] ) {
			return $data;
		}
		$result = array(
			'source'    => $row['name'],
			'pages'     => $r['pages'],
			'referrers' => $r['referrers'],
			'adminUrl'  => admin_url( $row['page'] ),
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $data;
	}
}, 14, 3 );

add_filter( 'minn_admin_traffic_report', function ( $report, $from, $to ) {
	if ( null !== $report ) {
		return $report;
	}
	$row = minn_admin_mi_ready();
	if ( ! $row ) {
		return $report;
	}

	$cache_key = 'minn_mi_traffic_report_' . md5( $from . '|' . $to );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		$data = minn_admin_mi_overview( $row, $from, $to );
		if ( ! $data ) {
			return $report;
		}
		$r = minn_admin_mi_pages_refs( $data );
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
				'visitors'  => $p['visitors'],
				'pageviews' => $p['pageviews'],
			);
		}
		$sections[] = array( 'id' => 'pages', 'label' => __( 'Top pages', 'minn-admin' ), 'rows' => $pages );
		if ( $r['referrers'] ) {
			$sections[] = array( 'id' => 'referrers', 'label' => __( 'Referrers', 'minn-admin' ), 'rows' => $r['referrers'] );
		}

		$countries = array();
		foreach ( isset( $data['countries'] ) && is_array( $data['countries'] ) ? $data['countries'] : array() as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			// prepare_report_data() resolves the ISO code to a name.
			$label  = (string) ( $c['name'] ?? $c['iso'] ?? '' );
			$visits = (int) ( $c['sessions'] ?? 0 );
			if ( '' === $label || $visits <= 0 ) {
				continue;
			}
			$countries[] = array( 'label' => $label, 'visitors' => $visits );
			if ( count( $countries ) >= 10 ) {
				break;
			}
		}
		if ( $countries ) {
			$sections[] = array( 'id' => 'countries', 'label' => __( 'Countries', 'minn-admin' ), 'rows' => $countries );
		}

		// Devices arrive as percentages of sessions; turn them back into
		// session counts against the window total so the rows read like
		// every other provider's.
		$total   = (int) ( $data['infobox']['sessions']['value'] ?? 0 );
		$devices = array();
		if ( $total > 0 && isset( $data['devices'] ) && is_array( $data['devices'] ) ) {
			foreach ( array( 'desktop' => __( 'Desktop', 'minn-admin' ), 'mobile' => __( 'Mobile', 'minn-admin' ), 'tablet' => __( 'Tablet', 'minn-admin' ) ) as $key => $label ) {
				$pct = isset( $data['devices'][ $key ] ) ? (float) $data['devices'][ $key ] : 0;
				$n   = (int) round( $total * $pct / 100 );
				if ( $n > 0 ) {
					$devices[] = array( 'label' => $label, 'visitors' => $n );
				}
			}
		}
		if ( $devices ) {
			$sections[] = array( 'id' => 'devices', 'label' => __( 'Devices', 'minn-admin' ), 'rows' => $devices );
		}

		$result = array(
			'source'   => $row['name'],
			'sections' => $sections,
			'adminUrl' => admin_url( $row['page'] ),
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $report;
	}
}, 14, 3 );
