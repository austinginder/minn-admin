<?php
/**
 * Bundled adapter: Independent Analytics (traffic provider).
 *
 * One adapter for the free plugin and Independent Analytics Pro: the Pro
 * build is the same codebase in its own folder with the paid features
 * unlocked (`iawp_is_pro()`), and both write the same tables.
 *
 * Storage (all datetimes UTC — the plugin writes `new DateTime('now', utc)`
 * and compares against UTC_TIMESTAMP(), so Minn's UTC-anchored day buckets
 * line up with theirs):
 *   {prefix}independent_analytics_views     one row per pageview (viewed_at,
 *                                           resource_id, session_id)
 *   {prefix}independent_analytics_sessions  visitor_id, created_at,
 *                                           referrer_id, campaign_id,
 *                                           country_id, city_id,
 *                                           device_type_id, device_os_id,
 *                                           device_browser_id, total_views
 *   lookup tables: resources, referrers (+ referrer_types), countries,
 *   cities, device_types, device_browsers, device_oss, campaigns (+ utm_*),
 *   clicks → clicked_links → links → click_targets.
 *
 * Metric definitions mirror the plugin's own Statistics class: Visitors =
 * COUNT(DISTINCT sessions.visitor_id), Pageviews = view rows (or the
 * session's total_views when aggregating by a session dimension). Bots
 * never reach these tables (the tracking endpoint drops them before the
 * insert), so raw counts match the plugin's dashboard.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * The name to show for this provider.
 *
 * Independent Analytics can be white labelled, and when it is, it renders
 * simply as "Analytics" for everyone who is not an administrator. Naming it
 * anyway showed an agency's clients the plugin the agency paid to hide.
 *
 * @return string
 */
function minn_admin_iawp_source_name() {
	if ( class_exists( '\IAWP\Capability_Manager' )
		&& method_exists( '\IAWP\Capability_Manager', 'show_white_labeled_ui' ) ) {
		try {
			if ( \IAWP\Capability_Manager::show_white_labeled_ui() ) {
				return __( 'Analytics', 'minn-admin' );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the product name.
		}
	}
	return 'Independent Analytics';
}

/**
 * Independent Analytics is loaded AND this user may read its reports.
 *
 * IAWP requires the administrator role or one of its own grants
 * (IAWP/Capability_Manager.php). It also ships an authored-posts-only tier;
 * this adapter reports site-wide totals, so anything short of full view
 * access is refused rather than silently collapsing that tier.
 */
function minn_admin_iawp_ready() {
	if ( ! defined( 'IAWP_VERSION' ) && ! class_exists( '\\IAWP\\Capability_Manager' ) ) {
		return false;
	}
	if ( class_exists( '\\IAWP\\Capability_Manager' ) ) {
		if ( method_exists( '\\IAWP\\Capability_Manager', 'can_only_view_authored_analytics' )
			&& \IAWP\Capability_Manager::can_only_view_authored_analytics() ) {
			return false;
		}
		if ( method_exists( '\\IAWP\\Capability_Manager', 'can_view' ) ) {
			return (bool) \IAWP\Capability_Manager::can_view();
		}
	}
	return current_user_can( 'manage_options' );
}

/**
 * Paid features unlocked? The free build hardcodes this false; the Pro
 * build asks its Freemius license. Campaigns and click tracking only
 * record under Pro, and the plugin hides those tabs otherwise, so Minn
 * shows those sections under the same condition.
 */
function minn_admin_iawp_is_pro() {
	if ( ! function_exists( 'iawp_is_pro' ) ) {
		return false;
	}
	try {
		return (bool) iawp_is_pro();
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Prefixed table name.
 */
function minn_admin_iawp_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'independent_analytics_' . $name;
}

/**
 * Table exists (one SHOW TABLES per name per request).
 */
function minn_admin_iawp_has( $name ) {
	static $seen = array();
	if ( ! array_key_exists( $name, $seen ) ) {
		global $wpdb;
		$t             = minn_admin_iawp_table( $name );
		$seen[ $name ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t );
	}
	return $seen[ $name ];
}

/**
 * The plugin's own dashboard URL (respects the white-label menu slug the
 * capability manager exposes when present).
 */
function minn_admin_iawp_admin_url() {
	return admin_url( 'admin.php?page=independent-analytics' );
}

/**
 * Top pages for an inclusive UTC datetime window.
 *
 * Visitors per page = distinct session visitors (the plugin's definition),
 * not distinct sessions: a returning visitor with two sessions in the range
 * counts once here and once in the chart above.
 *
 * @return array[] { title, path, url, postId, visitors, pageviews }
 */
function minn_admin_iawp_pages( $from_dt, $to_dt, $limit = 25 ) {
	global $wpdb;
	if ( ! minn_admin_iawp_has( 'views' ) ) {
		return array();
	}
	$views    = minn_admin_iawp_table( 'views' );
	$sessions = minn_admin_iawp_table( 'sessions' );
	$res      = minn_admin_iawp_table( 'resources' );
	$has_res  = minn_admin_iawp_has( 'resources' );
	$has_sess = minn_admin_iawp_has( 'sessions' );
	$limit    = max( 1, min( 100, (int) $limit ) );

	$visitor_expr = $has_sess ? 'COUNT(DISTINCT s.visitor_id)' : 'COUNT(DISTINCT v.session_id)';
	$sess_join    = $has_sess ? "LEFT JOIN {$sessions} s ON s.session_id = v.session_id" : '';
	$res_cols     = $has_res
		? 'MAX(r.cached_title) AS title, MAX(r.cached_url) AS url, MAX(r.singular_id) AS singular_id, MAX(r.resource) AS resource'
		: 'NULL AS title, NULL AS url, NULL AS singular_id, NULL AS resource';
	$res_join     = $has_res ? "LEFT JOIN {$res} r ON r.id = v.resource_id" : '';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are prefix-scoped.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT v.resource_id,
				COUNT(*) AS pageviews,
				{$visitor_expr} AS visitors,
				{$res_cols}
			FROM {$views} v
			{$sess_join}
			{$res_join}
			WHERE v.viewed_at >= %s AND v.viewed_at <= %s
			GROUP BY v.resource_id
			ORDER BY pageviews DESC, visitors DESC
			LIMIT {$limit}",
			$from_dt,
			$to_dt
		)
	);

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
	return $pages;
}

/**
 * Referrers for a window, labelled the way the plugin labels them: the
 * grouped name ("Google", "ChatGPT") with the domain as the sub line when
 * it differs. Direct traffic is not a referrer and is left out, as the
 * Matomo adapter does; the plugin models it as a referrer row of type
 * Direct with an empty domain.
 *
 * @return array[] { label, sub, visitors, pageviews }
 */
function minn_admin_iawp_referrers( $from_dt, $to_dt, $limit = 15 ) {
	global $wpdb;
	if ( ! minn_admin_iawp_has( 'sessions' ) || ! minn_admin_iawp_has( 'referrers' ) ) {
		return array();
	}
	$sessions  = minn_admin_iawp_table( 'sessions' );
	$refs      = minn_admin_iawp_table( 'referrers' );
	$types     = minn_admin_iawp_table( 'referrer_types' );
	$has_types = minn_admin_iawp_has( 'referrer_types' );
	$limit     = max( 1, min( 100, (int) $limit ) );
	$type_col  = $has_types ? 'MAX(t.referrer_type) AS rtype' : 'NULL AS rtype';
	$type_join = $has_types ? "LEFT JOIN {$types} t ON t.id = r.referrer_type_id" : '';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT MAX(r.domain) AS domain, MAX(r.referrer) AS name, {$type_col},
				COUNT(DISTINCT s.visitor_id) AS visitors,
				COALESCE(SUM(s.total_views), 0) AS pageviews
			FROM {$sessions} s
			INNER JOIN {$refs} r ON r.id = s.referrer_id
			{$type_join}
			WHERE s.created_at >= %s AND s.created_at <= %s
				AND s.referrer_id IS NOT NULL AND s.referrer_id > 0
			GROUP BY s.referrer_id
			ORDER BY visitors DESC, pageviews DESC
			LIMIT {$limit}",
			$from_dt,
			$to_dt
		)
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$domain = trim( (string) $row->domain );
		$name   = trim( (string) $row->name );
		if ( 'Direct' === (string) $row->rtype || ( '' === $domain && ( '' === $name || 'Direct' === $name ) ) ) {
			continue;
		}
		$label = '' !== $name ? $name : $domain;
		if ( '' === $label ) {
			continue;
		}
		$out[] = array(
			'label'     => $label,
			'sub'       => ( '' !== $domain && $domain !== $label ) ? $domain : '',
			'visitors'  => (int) $row->visitors,
			'pageviews' => (int) $row->pageviews,
		);
	}
	return $out;
}

/**
 * Sessions broken down by one lookup dimension (country, city, device
 * type, browser, operating system). Pageviews = the sessions' total_views,
 * which is what the plugin's own dimension reports sum.
 *
 * @param string $table     Lookup table suffix (countries, device_types, …).
 * @param string $id_col    Shared id column (country_id, device_type_id, …).
 * @param string $label_col Label column on the lookup table.
 * @param string $sub_sql   Optional SQL expression for a sub line (may
 *                          reference alias `d`).
 * @return array[] { label, sub, visitors, pageviews }
 */
function minn_admin_iawp_dimension( $from_dt, $to_dt, $table, $id_col, $label_col, $sub_sql = '', $limit = 25 ) {
	global $wpdb;
	if ( ! minn_admin_iawp_has( 'sessions' ) || ! minn_admin_iawp_has( $table ) ) {
		return array();
	}
	$sessions = minn_admin_iawp_table( 'sessions' );
	$dim      = minn_admin_iawp_table( $table );
	$limit    = max( 1, min( 100, (int) $limit ) );
	$sub_expr = '' !== $sub_sql ? "MAX({$sub_sql}) AS sub" : "'' AS sub";
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers are adapter constants.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT MAX(d.{$label_col}) AS label, {$sub_expr},
				COUNT(DISTINCT s.visitor_id) AS visitors,
				COALESCE(SUM(s.total_views), 0) AS pageviews
			FROM {$sessions} s
			INNER JOIN {$dim} d ON d.{$id_col} = s.{$id_col}
			WHERE s.created_at >= %s AND s.created_at <= %s
			GROUP BY s.{$id_col}
			ORDER BY visitors DESC, pageviews DESC
			LIMIT {$limit}",
			$from_dt,
			$to_dt
		)
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$label = trim( (string) $row->label );
		if ( '' === $label ) {
			continue;
		}
		$out[] = array(
			'label'     => $label,
			'sub'       => trim( (string) $row->sub ),
			'visitors'  => (int) $row->visitors,
			'pageviews' => (int) $row->pageviews,
		);
	}
	return $out;
}

/**
 * Pro: UTM campaigns. Label = utm_campaign, sub = "source / medium".
 */
function minn_admin_iawp_campaigns( $from_dt, $to_dt, $limit = 25 ) {
	global $wpdb;
	foreach ( array( 'sessions', 'campaigns', 'utm_campaigns', 'utm_sources', 'utm_mediums' ) as $t ) {
		if ( ! minn_admin_iawp_has( $t ) ) {
			return array();
		}
	}
	$sessions = minn_admin_iawp_table( 'sessions' );
	$camps    = minn_admin_iawp_table( 'campaigns' );
	$uc       = minn_admin_iawp_table( 'utm_campaigns' );
	$us       = minn_admin_iawp_table( 'utm_sources' );
	$um       = minn_admin_iawp_table( 'utm_mediums' );
	$limit    = max( 1, min( 100, (int) $limit ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT MAX(uc.utm_campaign) AS campaign, MAX(us.utm_source) AS source, MAX(um.utm_medium) AS medium,
				COUNT(DISTINCT s.visitor_id) AS visitors,
				COALESCE(SUM(s.total_views), 0) AS pageviews
			FROM {$sessions} s
			INNER JOIN {$camps} c ON c.campaign_id = s.campaign_id
			LEFT JOIN {$uc} uc ON uc.id = c.utm_campaign_id
			LEFT JOIN {$us} us ON us.id = c.utm_source_id
			LEFT JOIN {$um} um ON um.id = c.utm_medium_id
			WHERE s.created_at >= %s AND s.created_at <= %s
			GROUP BY c.utm_campaign_id, c.utm_source_id, c.utm_medium_id
			ORDER BY visitors DESC, pageviews DESC
			LIMIT {$limit}",
			$from_dt,
			$to_dt
		)
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$label = trim( (string) $row->campaign );
		if ( '' === $label ) {
			continue;
		}
		$parts = array_filter( array( trim( (string) $row->source ), trim( (string) $row->medium ) ) );
		$out[] = array(
			'label'     => $label,
			'sub'       => implode( ' / ', $parts ),
			'visitors'  => (int) $row->visitors,
			'pageviews' => (int) $row->pageviews,
		);
	}
	return $out;
}

/**
 * Pro: tracked link clicks by target, counted the way the plugin's
 * Click_Statistics does (clicks → clicked_links → links → click_targets;
 * visitors via the click's view → session).
 */
function minn_admin_iawp_clicks( $from_dt, $to_dt, $limit = 25 ) {
	global $wpdb;
	foreach ( array( 'clicks', 'clicked_links', 'links', 'click_targets' ) as $t ) {
		if ( ! minn_admin_iawp_has( $t ) ) {
			return array();
		}
	}
	$clicks   = minn_admin_iawp_table( 'clicks' );
	$cl       = minn_admin_iawp_table( 'clicked_links' );
	$links    = minn_admin_iawp_table( 'links' );
	$targets  = minn_admin_iawp_table( 'click_targets' );
	$views    = minn_admin_iawp_table( 'views' );
	$sessions = minn_admin_iawp_table( 'sessions' );
	$has_vs   = minn_admin_iawp_has( 'views' ) && minn_admin_iawp_has( 'sessions' );
	$limit    = max( 1, min( 100, (int) $limit ) );
	$vis_expr = $has_vs ? 'COUNT(DISTINCT s.visitor_id)' : '0';
	$vs_join  = $has_vs ? "LEFT JOIN {$views} v ON v.id = k.view_id LEFT JOIN {$sessions} s ON s.session_id = v.session_id" : '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT MAX(t.target) AS target, MAX(t.protocol) AS protocol,
				COUNT(DISTINCT k.click_id) AS clicks,
				{$vis_expr} AS visitors
			FROM {$clicks} k
			INNER JOIN {$cl} cl ON cl.click_id = k.click_id
			INNER JOIN {$links} l ON l.id = cl.link_id
			INNER JOIN {$targets} t ON t.click_target_id = l.click_target_id
			{$vs_join}
			WHERE k.created_at >= %s AND k.created_at <= %s
			GROUP BY l.click_target_id
			ORDER BY clicks DESC
			LIMIT {$limit}",
			$from_dt,
			$to_dt
		)
	);
	$out = array();
	foreach ( (array) $rows as $row ) {
		$target = trim( (string) $row->target );
		if ( '' === $target ) {
			continue;
		}
		$proto = trim( (string) $row->protocol );
		$label = $target;
		$sub   = '';
		$url   = '';
		if ( '' !== $proto ) {
			$sub = $proto;
		} elseif ( preg_match( '#^https?://#i', $target ) ) {
			$url  = $target;
			$host = wp_parse_url( $target, PHP_URL_HOST );
			$path = wp_parse_url( $target, PHP_URL_PATH );
			if ( $host ) {
				$label = $host . ( $path && '/' !== $path ? $path : '' );
				// The label already reads as the address; repeat the full
				// target only when it carries a query or fragment the
				// label dropped.
				$sub = ( false !== strpos( $target, '?' ) || false !== strpos( $target, '#' ) ) ? $target : '';
			}
		}
		// The Stats page prints its two numbers as "vis" and "views"; a click
		// count is neither, so it rides the sub line in words and the
		// pageviews slot stays empty (the client hides a zero).
		/* translators: %s: number of clicks. */
		$count = sprintf( _n( '%s click', '%s clicks', (int) $row->clicks, 'minn-admin' ), number_format_i18n( (int) $row->clicks ) );
		$out[] = array(
			'label'     => $label,
			'sub'       => '' !== $sub ? $count . ' · ' . $sub : $count,
			'url'       => $url,
			'visitors'  => (int) $row->visitors,
			'pageviews' => 0,
		);
	}
	return $out;
}

/**
 * Overview chart: daily visitors + pageviews for the last N days plus the
 * previous window's visitor total.
 */
add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic || ! defined( 'IAWP_VERSION' ) ) {
		return $traffic;
	}
	if ( ! minn_admin_iawp_ready() || ! minn_admin_iawp_has( 'views' ) ) {
		return $traffic;
	}

	global $wpdb;
	$views    = minn_admin_iawp_table( 'views' );
	$sessions = minn_admin_iawp_table( 'sessions' );

	$days       = max( 1, (int) $days );
	$cur_start  = gmdate( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	$prev_start = gmdate( 'Y-m-d 00:00:00', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS );

	$view_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(viewed_at) AS d, COUNT(*) AS p FROM {$views} WHERE viewed_at >= %s GROUP BY d", // phpcs:ignore
		$prev_start
	) );
	$visitor_rows = minn_admin_iawp_has( 'sessions' ) ? $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(created_at) AS d, COUNT(DISTINCT visitor_id) AS v FROM {$sessions} WHERE created_at >= %s GROUP BY d", // phpcs:ignore
		$prev_start
	) ) : array();
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
		'source'        => minn_admin_iawp_source_name(),
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}, 10, 2 );

/**
 * Overview day-click drill-down (also the Stats page's fallback): top pages
 * + referrers for an inclusive date window.
 */
add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data || ! defined( 'IAWP_VERSION' ) ) {
		return $data;
	}
	if ( ! minn_admin_iawp_ready() || ! minn_admin_iawp_has( 'views' ) ) {
		return $data;
	}
	$from_dt = $from . ' 00:00:00';
	$to_dt   = $to . ' 23:59:59';
	$refs    = array();
	foreach ( minn_admin_iawp_referrers( $from_dt, $to_dt, 15 ) as $r ) {
		// The day shape carries a bare label; fold the domain in when the
		// grouped name alone would be ambiguous ("Google" is fine, a bare
		// hostname group is already its own domain).
		$refs[] = array(
			'label'     => $r['label'],
			'visitors'  => $r['visitors'],
			'pageviews' => $r['pageviews'],
		);
	}
	return array(
		'source'    => minn_admin_iawp_source_name(),
		'pages'     => minn_admin_iawp_pages( $from_dt, $to_dt, 25 ),
		'referrers' => $refs,
		'adminUrl'  => minn_admin_iawp_admin_url(),
	);
}, 10, 3 );

/**
 * Stats page range-wide breakdowns: the dimensions the day shape cannot
 * carry. Countries, cities, device types and browsers record in the free
 * plugin (its Geo and Devices tabs are free); campaigns and link clicks
 * record under Pro only, and appear here under the same condition the
 * plugin uses to show those tabs.
 */
add_filter( 'minn_admin_traffic_report', function ( $report, $from, $to ) {
	if ( null !== $report || ! defined( 'IAWP_VERSION' ) ) {
		return $report;
	}
	if ( ! minn_admin_iawp_ready() || ! minn_admin_iawp_has( 'views' ) ) {
		return $report;
	}
	$from_dt  = $from . ' 00:00:00';
	$to_dt    = $to . ' 23:59:59';
	$sections = array();

	$pages = array();
	foreach ( minn_admin_iawp_pages( $from_dt, $to_dt, 25 ) as $p ) {
		$pages[] = array(
			'label'     => $p['title'],
			'sub'       => ( $p['path'] !== $p['title'] ) ? $p['path'] : '',
			'url'       => $p['url'],
			'postId'    => $p['postId'],
			'visitors'  => $p['visitors'],
			'pageviews' => $p['pageviews'],
		);
	}
	if ( $pages ) {
		$sections[] = array( 'id' => 'pages', 'label' => __( 'Top pages', 'minn-admin' ), 'rows' => $pages );
	}
	$refs = minn_admin_iawp_referrers( $from_dt, $to_dt, 25 );
	if ( $refs ) {
		$sections[] = array( 'id' => 'referrers', 'label' => __( 'Referrers', 'minn-admin' ), 'rows' => $refs );
	}
	$countries = minn_admin_iawp_dimension( $from_dt, $to_dt, 'countries', 'country_id', 'country', 'd.continent' );
	if ( $countries ) {
		$sections[] = array( 'id' => 'countries', 'label' => __( 'Countries', 'minn-admin' ), 'rows' => $countries );
	}
	// Cities carry their country as the sub line; the cities table stores a
	// country_id, so the label needs a second hop.
	if ( minn_admin_iawp_has( 'cities' ) && minn_admin_iawp_has( 'countries' ) ) {
		$cities = minn_admin_iawp_dimension(
			$from_dt,
			$to_dt,
			'cities',
			'city_id',
			'city',
			'(SELECT c2.country FROM ' . minn_admin_iawp_table( 'countries' ) . ' c2 WHERE c2.country_id = d.country_id LIMIT 1)'
		);
		if ( $cities ) {
			$sections[] = array( 'id' => 'cities', 'label' => __( 'Cities', 'minn-admin' ), 'rows' => $cities );
		}
	}
	$devices = minn_admin_iawp_dimension( $from_dt, $to_dt, 'device_types', 'device_type_id', 'device_type' );
	if ( $devices ) {
		$sections[] = array( 'id' => 'devices', 'label' => __( 'Devices', 'minn-admin' ), 'rows' => $devices );
	}
	$browsers = minn_admin_iawp_dimension( $from_dt, $to_dt, 'device_browsers', 'device_browser_id', 'device_browser' );
	if ( $browsers ) {
		$sections[] = array( 'id' => 'browsers', 'label' => __( 'Browsers', 'minn-admin' ), 'rows' => $browsers );
	}
	if ( minn_admin_iawp_is_pro() ) {
		$campaigns = minn_admin_iawp_campaigns( $from_dt, $to_dt );
		if ( $campaigns ) {
			$sections[] = array( 'id' => 'campaigns', 'label' => __( 'Campaigns', 'minn-admin' ), 'rows' => $campaigns );
		}
		$clicks = minn_admin_iawp_clicks( $from_dt, $to_dt );
		if ( $clicks ) {
			$sections[] = array( 'id' => 'clicks', 'label' => __( 'Link clicks', 'minn-admin' ), 'rows' => $clicks );
		}
	}
	if ( ! $sections ) {
		return $report;
	}
	return array(
		'source'   => minn_admin_iawp_source_name(),
		'sections' => $sections,
		'adminUrl' => minn_admin_iawp_admin_url(),
	);
}, 10, 3 );
