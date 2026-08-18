<?php
/**
 * Bundled adapter: Matomo Analytics (traffic provider).
 *
 * The wp.org `matomo` plugin bundles the full Matomo app and stores all data
 * locally. Reads go through Matomo's OWN reporting API (Bootstrap +
 * Piwik\API\Request::processRequest) inside Access::doAsSuperUser() — the same
 * pattern Matomo's own WpMatomo\Annotations and PluginSuggestions code uses for
 * programmatic reads — after Minn checks the plugin's `view_matomo` capability
 * (their dynamically-granted role cap) on the WP side. Never raw archive-table
 * SQL: archive blobs and metric semantics are Matomo's own.
 *
 * Numbers match Matomo's own UI exactly, including its archiving cadence: the
 * WP plugin archives via WP-cron (hourly), so "today" can lag up to an hour,
 * exactly as it does on their reporting screens. Responses are cached for 15
 * minutes — the Matomo environment boot plus report reads cost ~300ms.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * True when the Matomo plugin is loaded, has a synced site, and the current
 * user may view reports (their own `view_matomo` role capability).
 */
function minn_admin_matomo_ready() {
	return class_exists( '\WpMatomo\Bootstrap' )
		&& class_exists( '\WpMatomo\Site' )
		&& current_user_can( 'view_matomo' );
}

/**
 * Run one Matomo reporting-API request as superuser and return the raw
 * DataTable / DataTable\Map result. Caller wraps in try/catch.
 *
 * @param string $method Matomo API method, e.g. 'VisitsSummary.get'.
 * @param array  $params Request parameters (idSite/period/date/...).
 * @return mixed DataTable, DataTable\Map, or scalar per method.
 */
function minn_admin_matomo_request( $method, $params ) {
	\WpMatomo\Bootstrap::do_bootstrap();
	return \Piwik\Access::doAsSuperUser(
		function () use ( $method, $params ) {
			$params['format'] = 'original';
			return \Piwik\API\Request::processRequest( $method, $params );
		}
	);
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! minn_admin_matomo_ready() ) {
		return $traffic;
	}

	$days      = max( 1, (int) $days );
	$cache_key = 'minn_matomo_traffic_' . $days;
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		$site   = new \WpMatomo\Site();
		$idsite = $site->get_current_matomo_site_id();
		if ( empty( $idsite ) ) {
			return $traffic;
		}

		$base = array(
			'idSite' => $idsite,
			'period' => 'day',
			'date'   => 'last' . ( 2 * $days ),
		);

		// Day labels arrive as Y-m-d in the Matomo site's timezone (synced
		// from WP). The last $days labels are the current window; the rest
		// feed prev_visitors — split by position, not by a UTC clock.
		$visits = array();
		$map    = minn_admin_matomo_request( 'VisitsSummary.get', $base );
		foreach ( $map->getDataTables() as $label => $table ) {
			$row = $table->getFirstRow();
			if ( $row ) {
				$cols = $row->getColumns();
				$visits[ (string) $label ] = array(
					'visitors'  => (int) ( $cols['nb_uniq_visitors'] ?? $cols['nb_visits'] ?? 0 ),
					'pageviews' => 0,
				);
			} else {
				$visits[ (string) $label ] = array( 'visitors' => 0, 'pageviews' => 0 );
			}
		}
		if ( ! $visits ) {
			return $traffic;
		}

		$actions = minn_admin_matomo_request( 'Actions.get', $base );
		foreach ( $actions->getDataTables() as $label => $table ) {
			$row = $table->getFirstRow();
			if ( $row && isset( $visits[ (string) $label ] ) ) {
				$visits[ (string) $label ]['pageviews'] = (int) ( $row->getColumn( 'nb_pageviews' ) ?: 0 );
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
		// A Matomo that has never tracked (tracking is off until configured)
		// must not silence another provider.
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
}, 10, 2 );

/**
 * Top pages (Actions.getPageUrls, flat) + referrers (Referrers.getAll minus
 * the direct-entry row) for a date range. Shared by the traffic-day
 * drill-down and the range-wide report. Caller wraps in try/catch.
 *
 * @return array { pages: [...], referrers: [...] } (pages empty = no answer)
 */
function minn_admin_matomo_pages_refs( $base ) {
	$pages = array();
	$table = minn_admin_matomo_request( 'Actions.getPageUrls', $base + array(
		'flat'               => 1,
		'filter_limit'       => 25,
		'filter_sort_column' => 'nb_hits',
	) );
	foreach ( $table->getRows() as $row ) {
		$path = (string) $row->getColumn( 'label' );
		$url  = (string) $row->getMetadata( 'url' );
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
			'visitors'  => (int) ( $row->getColumn( 'nb_visits' ) ?: 0 ),
			'pageviews' => (int) ( $row->getColumn( 'nb_hits' ) ?: 0 ),
		);
	}
	if ( ! $pages ) {
		return array( 'pages' => array(), 'referrers' => array() );
	}

	$referrers = array();
	$refs      = minn_admin_matomo_request( 'Referrers.getAll', $base + array(
		'filter_limit'       => 16,
		'filter_sort_column' => 'nb_visits',
	) );
	// Matomo labels its direct-traffic row in the UI language; compare
	// against their own translation rather than a hardcoded string.
	$direct = \Piwik\Piwik::translate( 'Referrers_DirectEntry' );
	foreach ( $refs->getRows() as $row ) {
		$label = (string) $row->getColumn( 'label' );
		if ( '' === $label || $label === $direct ) {
			continue;
		}
		$referrers[] = array(
			'label'    => $label,
			'visitors' => (int) ( $row->getColumn( 'nb_visits' ) ?: 0 ),
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
 * One Matomo label/nb_visits report as generic rows (countries, devices,
 * site-search keywords). Empty on any miss — sections are optional.
 */
function minn_admin_matomo_label_rows( $method, $base, $limit = 10 ) {
	$rows  = array();
	$table = minn_admin_matomo_request( $method, $base + array(
		'filter_limit'       => $limit,
		'filter_sort_column' => 'nb_visits',
	) );
	foreach ( $table->getRows() as $row ) {
		$label  = (string) $row->getColumn( 'label' );
		$visits = (int) ( $row->getColumn( 'nb_visits' ) ?: 0 );
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
	if ( null !== $data || ! minn_admin_matomo_ready() ) {
		return $data;
	}

	$cache_key = 'minn_matomo_traffic_day_' . md5( $from . '|' . $to );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		$site   = new \WpMatomo\Site();
		$idsite = $site->get_current_matomo_site_id();
		if ( empty( $idsite ) ) {
			return $data;
		}

		$r = minn_admin_matomo_pages_refs( array(
			'idSite' => $idsite,
			'period' => 'range',
			'date'   => $from . ',' . $to,
		) );
		if ( ! $r['pages'] ) {
			return $data;
		}

		$result = array(
			'source'    => 'Matomo',
			'pages'     => $r['pages'],
			'referrers' => $r['referrers'],
			'adminUrl'  => admin_url( 'admin.php?page=matomo-reporting' ),
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $data;
	}
}, 10, 3 );

/**
 * Range-wide report for the Stats page: pages + referrers from the shared
 * builder, plus the dimensions Matomo tracks locally — countries, device
 * types, and site-search keywords (only when the site records searches).
 */
add_filter( 'minn_admin_traffic_report', function ( $report, $from, $to ) {
	if ( null !== $report || ! minn_admin_matomo_ready() ) {
		return $report;
	}

	$cache_key = 'minn_matomo_traffic_report_' . md5( $from . '|' . $to );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	try {
		$site   = new \WpMatomo\Site();
		$idsite = $site->get_current_matomo_site_id();
		if ( empty( $idsite ) ) {
			return $report;
		}

		$base = array(
			'idSite' => $idsite,
			'period' => 'range',
			'date'   => $from . ',' . $to,
		);

		$r = minn_admin_matomo_pages_refs( $base );
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

		$countries = minn_admin_matomo_label_rows( 'UserCountry.getCountry', $base );
		if ( $countries ) {
			$sections[] = array( 'id' => 'countries', 'label' => __( 'Countries', 'minn-admin' ), 'rows' => $countries );
		}
		$devices = minn_admin_matomo_label_rows( 'DevicesDetection.getType', $base );
		if ( $devices ) {
			$sections[] = array( 'id' => 'devices', 'label' => __( 'Devices', 'minn-admin' ), 'rows' => $devices );
		}
		$search = minn_admin_matomo_label_rows( 'Actions.getSiteSearchKeywords', $base );
		if ( $search ) {
			$sections[] = array( 'id' => 'search', 'label' => __( 'Site searches', 'minn-admin' ), 'rows' => $search );
		}

		$result = array(
			'source'   => 'Matomo',
			'sections' => $sections,
			'adminUrl' => admin_url( 'admin.php?page=matomo-reporting' ),
		);
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	} catch ( \Throwable $e ) {
		return $report;
	}
}, 10, 3 );
