<?php
/**
 * Bundled adapter: Connect Matomo (traffic provider).
 *
 * The wp.org `wp-piwik` plugin ("Connect Matomo") bridges WordPress to a
 * Matomo that lives SOMEWHERE ELSE: a self-hosted Matomo server or Matomo
 * Cloud. The plugin holds the Matomo URL, an auth token and the site id, and
 * proxies reporting calls over HTTP. Nothing Matomo-shaped lives in the
 * WordPress database, so the bundled `matomo` adapter (which reads the
 * in-WordPress Matomo app) cannot see these sites at all.
 *
 * Reads go through the plugin's OWN request layer: WP_Piwik\Request::register()
 * queues API calls and $wp_piwik->request() performs them as ONE bulk
 * API.getBulkRequest through their transport (curl/fopen, their SSL and proxy
 * settings). The auth token never leaves their code. Their week-long transient
 * cache treats a date range as closed even when it ends today, so requests
 * for a window that reaches into today drop that cache entry first. Minn checks the plugin's own
 * `wp-piwik_read_stats` capability first: a role capability the plugin grants
 * from its "Display stats to" setting, the same gate as its own stats screen.
 *
 * Results arrive as decoded JSON arrays (the bundled Matomo adapter gets
 * DataTable objects), so the mapping here is array-shaped. Registered at
 * priority 12: a local-store analytics plugin (Koko and co at 10) answers
 * without a network round trip, this one costs one remote request, and the
 * platform fallbacks (Site Kit, Jetpack Stats) sit at 20.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * True when Connect Matomo is loaded and configured and the current user may
 * view its reports (their own `wp-piwik_read_stats` role capability).
 */
function minn_admin_connect_matomo_ready() {
	if ( ! class_exists( 'WP_Piwik' ) || ! class_exists( '\WP_Piwik\Request' ) ) {
		return false;
	}
	if ( empty( $GLOBALS['wp-piwik'] ) || ! ( $GLOBALS['wp-piwik'] instanceof \WP_Piwik ) ) {
		return false;
	}
	if ( ! current_user_can( 'wp-piwik_read_stats' ) ) {
		return false;
	}
	// A Matomo that failed to answer moments ago is not asked again for a
	// minute: without this, every gated overview and stats call pays the
	// plugin's full connection timeout until the server recovers.
	if ( get_transient( 'minn_cmatomo_down' ) ) {
		return false;
	}
	try {
		if ( ! \WP_Piwik::is_configured() ) {
			return false;
		}
		// Only the HTTP-shaped modes. The deprecated "PHP API" mode boots the
		// whole Matomo app inside the calling request and rewrites the
		// response Content-Type, which a REST reply cannot survive.
		$settings = \WP_Piwik::get_settings();
		$mode     = $settings ? (string) $settings->get_global_option( 'piwik_mode' ) : '';
		return in_array( $mode, array( 'http', 'cloud', 'cloud-matomo' ), true );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * The Matomo site id the plugin has stored for this blog. Read from their
 * setting only: their get_piwik_site_id() falls back to a lookup that can ADD
 * a site on the Matomo server and rewrite the tracking code, which a read
 * must never trigger. An unconfigured site id means "not ready".
 */
function minn_admin_connect_matomo_site_id() {
	$settings = \WP_Piwik::get_settings();
	if ( ! $settings ) {
		return 0;
	}
	$id = $settings->get_option( 'site_id' );
	return is_numeric( $id ) ? (int) $id : 0;
}

/**
 * Queue one reporting call through their request layer. Returns the id to
 * hand to minn_admin_connect_matomo_result(); queue every call first so the
 * first result fetch performs them as one bulk request.
 */
function minn_admin_connect_matomo_queue( $method, $params, $fresh = false ) {
	$site = minn_admin_connect_matomo_site_id();
	if ( $fresh ) {
		// Their register() marks only last*/today/current-period requests as
		// uncacheable; a `range` that ends today is cached for a week under
		// this exact key, so a window still accumulating visits must clear
		// it before performing.
		delete_transient( 'wp-piwik_c_' . md5( $method . '-' . $site . '-' . serialize( $params ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}
	return \WP_Piwik\Request::register( $method, $params, $site );
}

/**
 * True when a Y-m-d window end is still accumulating visits: today, or
 * yesterday when the Matomo site's timezone may still be on it.
 */
function minn_admin_connect_matomo_is_open( $to ) {
	return (string) $to >= wp_date( 'Y-m-d', time() - DAY_IN_SECONDS );
}

/**
 * Fetch a queued call's decoded result. Null on transport failure or a
 * Matomo-side error ({result: "error", message}); an empty array is a
 * legitimate "no data".
 */
function minn_admin_connect_matomo_result( $id ) {
	$res = $GLOBALS['wp-piwik']->request( $id );
	if ( ! is_array( $res ) ) {
		// false = transport failure (their perform() returns it when the
		// bulk request produced nothing); 'n/a' = mode disabled.
		if ( false === $res ) {
			set_transient( 'minn_cmatomo_down', 1, MINUTE_IN_SECONDS );
		}
		return null;
	}
	if ( isset( $res['result'] ) && 'error' === $res['result'] ) {
		return null;
	}
	return $res;
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! minn_admin_connect_matomo_ready() ) {
		return $traffic;
	}

	$days      = max( 1, (int) $days );
	$cache_key = 'minn_cmatomo_traffic_' . $days;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		if ( ! minn_admin_connect_matomo_site_id() ) {
			return $traffic;
		}

		$base = array(
			'period' => 'day',
			'date'   => 'last' . ( 2 * $days ),
		);

		// Queue both, then read: one bulk request over their transport.
		$visits_id  = minn_admin_connect_matomo_queue( 'VisitsSummary.get', $base );
		$actions_id = minn_admin_connect_matomo_queue( 'Actions.get', $base );

		// Day labels arrive as Y-m-d keys in the Matomo site's timezone; a
		// day with no visits is an EMPTY array in JSON. The last $days labels
		// are the current window, the rest feed prev_visitors: split by
		// position, not by a UTC clock.
		$visits  = array();
		$summary = minn_admin_connect_matomo_result( $visits_id );
		if ( ! is_array( $summary ) ) {
			return $traffic;
		}
		foreach ( $summary as $label => $row ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $label ) ) {
				continue;
			}
			$row = is_array( $row ) ? $row : array();
			$visits[ (string) $label ] = array(
				'visitors'  => (int) ( $row['nb_uniq_visitors'] ?? $row['nb_visits'] ?? 0 ),
				'pageviews' => 0,
			);
		}
		if ( ! $visits ) {
			return $traffic;
		}

		$actions = minn_admin_connect_matomo_result( $actions_id );
		foreach ( is_array( $actions ) ? $actions : array() as $label => $row ) {
			if ( is_array( $row ) && isset( $visits[ (string) $label ] ) ) {
				$visits[ (string) $label ]['pageviews'] = (int) ( $row['nb_pageviews'] ?? 0 );
			}
		}

		ksort( $visits );
		$labels = array_keys( $visits );
		$cur    = array_slice( $labels, -$days );
		$out    = array();
		$prev   = 0;
		$any    = false;
		foreach ( $visits as $label => $entry ) {
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
		// A connected Matomo with no visits yet must not silence another
		// provider.
		if ( ! $any ) {
			return $traffic;
		}

		$result = array(
			'source'        => 'Matomo',
			'days'          => $out,
			'prev_visitors' => $prev,
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $traffic;
	}
}, 12, 2 );

/**
 * Top pages (Actions.getPageUrls, flat) + referrers (Referrers.getAll minus
 * the direct-entry row) for a date range, queued together as one bulk call.
 * Shared by the traffic-day drill-down and the range-wide report. Caller
 * wraps in try/catch.
 *
 * @return array { pages: [...], referrers: [...] } (pages empty = no answer)
 */
function minn_admin_connect_matomo_pages_refs( $base, $fresh = false ) {
	$pages_id = minn_admin_connect_matomo_queue( 'Actions.getPageUrls', $base + array(
		'flat'               => 1,
		'filter_limit'       => 25,
		'filter_sort_column' => 'nb_hits',
	), $fresh );
	$refs_id  = minn_admin_connect_matomo_queue( 'Referrers.getAll', $base + array(
		'filter_limit'       => 16,
		'filter_sort_column' => 'nb_visits',
	), $fresh );

	$pages = array();
	$rows  = minn_admin_connect_matomo_result( $pages_id );
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$path = (string) ( $row['label'] ?? '' );
		// The JSON renderer flattens row metadata (the full page URL) into
		// the row itself.
		$url = isset( $row['url'] ) && is_string( $row['url'] ) ? esc_url_raw( $row['url'] ) : '';
		if ( '' !== $path && '/' !== $path[0] ) {
			$path = '/' . $path;
		}
		$post_id = $url ? url_to_postid( $url ) : 0;
		$title   = $post_id ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES ) : '';
		$pages[] = array(
			'title'     => '' !== $title ? $title : ( $path ? $path : '/' ),
			'path'      => $path ? $path : '/',
			'url'       => $url,
			'postId'    => $post_id,
			'visitors'  => (int) ( $row['nb_visits'] ?? 0 ),
			'pageviews' => (int) ( $row['nb_hits'] ?? 0 ),
		);
	}
	if ( ! $pages ) {
		return array( 'pages' => array(), 'referrers' => array() );
	}

	$referrers = array();
	$refs      = minn_admin_connect_matomo_result( $refs_id );
	foreach ( is_array( $refs ) ? $refs : array() as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$label = (string) ( $row['label'] ?? '' );
		// Direct traffic is referer_type 1 in Matomo's own vocabulary; the
		// label is translated per the server's UI language, so the numeric
		// type is the reliable test (the English label is a fallback for
		// servers that omit the column).
		$type = isset( $row['referer_type'] ) ? (int) $row['referer_type'] : 0;
		if ( '' === $label || 1 === $type || 'Direct Entry' === $label ) {
			continue;
		}
		$referrers[] = array(
			'label'    => $label,
			'visitors' => (int) ( $row['nb_visits'] ?? 0 ),
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

/**
 * One label/nb_visits report as generic rows (countries, devices,
 * site-search keywords). Empty on any miss; sections are optional.
 */
function minn_admin_connect_matomo_label_rows( $id ) {
	$rows = array();
	$res  = minn_admin_connect_matomo_result( $id );
	foreach ( is_array( $res ) ? $res : array() as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$label  = (string) ( $row['label'] ?? '' );
		$visits = (int) ( $row['nb_visits'] ?? 0 );
		// DevicesDetection returns EVERY known type, zero-visit rows included.
		if ( '' === $label || $visits <= 0 ) {
			continue;
		}
		$rows[] = array(
			'label'    => $label,
			'visitors' => $visits,
		);
	}
	return $rows;
}

/**
 * Overview traffic-day drill-down: the shared builder in the pages/referrers
 * shape that route expects.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data || ! minn_admin_connect_matomo_ready() ) {
		return $data;
	}

	$cache_key = 'minn_cmatomo_traffic_day_' . md5( $from . '|' . $to );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		if ( ! minn_admin_connect_matomo_site_id() ) {
			return $data;
		}

		$r = minn_admin_connect_matomo_pages_refs( array(
			'period' => 'range',
			'date'   => $from . ',' . $to,
		), minn_admin_connect_matomo_is_open( $to ) );
		if ( ! $r['pages'] ) {
			return $data;
		}

		$result = array(
			'source'    => 'Matomo',
			'pages'     => $r['pages'],
			'referrers' => $r['referrers'],
			'adminUrl'  => admin_url( 'admin.php?page=wp-piwik_stats' ),
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $data;
	}
}, 12, 3 );

/**
 * Range-wide report for the Stats page: pages + referrers from the shared
 * builder, plus the dimensions Matomo tracks (countries, device types, and
 * site-search keywords when the site records searches). All queued before
 * the first read so the whole report is one round trip to the server.
 */
add_filter( 'minn_admin_traffic_report', function ( $report, $from, $to ) {
	if ( null !== $report || ! minn_admin_connect_matomo_ready() ) {
		return $report;
	}

	$cache_key = 'minn_cmatomo_traffic_report_' . md5( $from . '|' . $to );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		if ( ! minn_admin_connect_matomo_site_id() ) {
			return $report;
		}

		$base = array(
			'period' => 'range',
			'date'   => $from . ',' . $to,
		);
		$dim  = array(
			'filter_limit'       => 10,
			'filter_sort_column' => 'nb_visits',
		);
		$fresh        = minn_admin_connect_matomo_is_open( $to );
		$countries_id = minn_admin_connect_matomo_queue( 'UserCountry.getCountry', $base + $dim, $fresh );
		$devices_id   = minn_admin_connect_matomo_queue( 'DevicesDetection.getType', $base + $dim, $fresh );
		$search_id    = minn_admin_connect_matomo_queue( 'Actions.getSiteSearchKeywords', $base + $dim, $fresh );

		$r = minn_admin_connect_matomo_pages_refs( $base, $fresh );
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

		$countries = minn_admin_connect_matomo_label_rows( $countries_id );
		if ( $countries ) {
			$sections[] = array( 'id' => 'countries', 'label' => __( 'Countries', 'minn-admin' ), 'rows' => $countries );
		}
		$devices = minn_admin_connect_matomo_label_rows( $devices_id );
		if ( $devices ) {
			$sections[] = array( 'id' => 'devices', 'label' => __( 'Devices', 'minn-admin' ), 'rows' => $devices );
		}
		$search = minn_admin_connect_matomo_label_rows( $search_id );
		if ( $search ) {
			$sections[] = array( 'id' => 'search', 'label' => __( 'Site searches', 'minn-admin' ), 'rows' => $search );
		}

		$result = array(
			'source'   => 'Matomo',
			'sections' => $sections,
			'adminUrl' => admin_url( 'admin.php?page=wp-piwik_stats' ),
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $report;
	}
}, 12, 3 );
