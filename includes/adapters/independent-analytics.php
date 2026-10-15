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
 * One of the plugin's tables, as the kernel's SQL layer names it (no prefix).
 */
function minn_admin_iawp_table( $name ) {
	return 'independent_analytics_' . $name;
}

/**
 * Table exists (asked once per name per request).
 */
function minn_admin_iawp_has( $name ) {
	return Minn_Admin_Kernel_SQL::exists( minn_admin_iawp_table( $name ) );
}

/**
 * The plugin's own dashboard URL, or '' when the site has white-labeled it.
 *
 * The docblock here used to claim it respected the white-label menu slug and
 * then hardcoded the product one, which put the very name the site had chosen
 * to hide into an href on the same card whose title had just been genericised.
 * There is no public accessor for the renamed slug, so the honest answer when
 * the UI is white-labeled is no link at all: callers omit adminUrl and the
 * client renders none.
 */
function minn_admin_iawp_admin_url() {
	if ( class_exists( '\IAWP\Capability_Manager' )
		&& method_exists( '\IAWP\Capability_Manager', 'show_white_labeled_ui' ) ) {
		try {
			if ( \IAWP\Capability_Manager::show_white_labeled_ui() ) {
				return '';
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the product URL.
		}
	}
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
	if ( ! minn_admin_iawp_has( 'views' ) ) {
		return array();
	}
	$has_res  = minn_admin_iawp_has( 'resources' );
	$has_sess = minn_admin_iawp_has( 'sessions' );
	$joins    = array();
	$totals   = array( 'pageviews' => array( 'count' => '*' ), 'visitors' => array( 'distinct' => $has_sess ? 's.visitor_id' : 'session_id' ) );
	if ( $has_sess ) {
		$joins['s'] = array( 'table' => minn_admin_iawp_table( 'sessions' ), 'on' => array( 'session_id' => 'e.session_id' ) );
	}
	if ( $has_res ) {
		$joins['r'] = array( 'table' => minn_admin_iawp_table( 'resources' ), 'on' => array( 'id' => 'e.resource_id' ) );
		$totals    += array(
			'title'       => array( 'max' => 'r.cached_title' ),
			'url'         => array( 'max' => 'r.cached_url' ),
			'singular_id' => array( 'max' => 'r.singular_id' ),
			'resource'    => array( 'max' => 'r.resource' ),
		);
	}
	$rows = Minn_Admin_Kernel_SQL::totals(
		array( 'table' => minn_admin_iawp_table( 'views' ), 'joins' => $joins ),
		array( 'resource_id' ),
		$totals,
		minn_admin_traffic_window( 'viewed_at', $from_dt, $to_dt ),
		array( 'pageviews' => 'DESC', 'visitors' => 'DESC' ),
		max( 1, min( 100, (int) $limit ) )
	);

	$pages = array();
	foreach ( $rows as $row ) {
		$row    += array( 'title' => null, 'url' => null, 'singular_id' => null, 'resource' => null );
		$post_id = (int) $row['singular_id'];
		$title   = $row['title'] ? (string) $row['title'] : '';
		$url     = $row['url'] ? (string) $row['url'] : '';
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
			$resource = $row['resource'] ? (string) $row['resource'] : '';
			if ( $resource ) {
				$title = $resource;
				$path  = $path ? $path : ( '/' === $resource[0] ? $resource : '/' . $resource );
			} else {
				$title = 'Resource #' . (int) $row['resource_id'];
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
			'visitors'  => (int) $row['visitors'],
			'pageviews' => (int) $row['pageviews'],
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
	if ( ! minn_admin_iawp_has( 'sessions' ) || ! minn_admin_iawp_has( 'referrers' ) ) {
		return array();
	}
	$joins  = array( 'r' => array( 'table' => minn_admin_iawp_table( 'referrers' ), 'on' => array( 'id' => 'e.referrer_id' ) ) );
	$totals = array( 'domain' => array( 'max' => 'r.domain' ), 'name' => array( 'max' => 'r.referrer' ) );
	if ( minn_admin_iawp_has( 'referrer_types' ) ) {
		$joins['t']      = array( 'table' => minn_admin_iawp_table( 'referrer_types' ), 'on' => array( 'id' => 'r.referrer_type_id' ) );
		$totals['rtype'] = array( 'max' => 't.referrer_type' );
	}
	$rows = Minn_Admin_Kernel_SQL::totals(
		array( 'table' => minn_admin_iawp_table( 'sessions' ), 'joins' => $joins ),
		array( 'referrer_id' ),
		$totals + array( 'visitors' => array( 'distinct' => 'visitor_id' ), 'pageviews' => array( 'sum' => 'total_views' ) ),
		// A session with a referrer the plugin still has: its own inner join.
		minn_admin_traffic_window( 'created_at', $from_dt, $to_dt ) + array( 'referrer_id' => array( 'op' => '>', 'value' => 0 ), 'r.id' => array( 'none' => array( null ) ) ),
		array( 'visitors' => 'DESC', 'pageviews' => 'DESC' ),
		max( 1, min( 100, (int) $limit ) )
	);

	$out = array();
	foreach ( $rows as $row ) {
		$domain = trim( (string) $row['domain'] );
		$name   = trim( (string) $row['name'] );
		if ( 'Direct' === (string) ( $row['rtype'] ?? '' ) || ( '' === $domain && ( '' === $name || 'Direct' === $name ) ) ) {
			continue;
		}
		$label = '' !== $name ? $name : $domain;
		if ( '' === $label ) {
			continue;
		}
		$out[] = array(
			'label'     => $label,
			'sub'       => ( '' !== $domain && $domain !== $label ) ? $domain : '',
			'visitors'  => (int) $row['visitors'],
			'pageviews' => (int) $row['pageviews'],
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
 * @param string $sub       Optional column for a sub line, on the lookup
 *                          table (alias `d`) or on one of $joins.
 * @param array  $joins     Further joins the sub line reads (a city's country).
 * @return array[] { label, sub, visitors, pageviews }
 */
function minn_admin_iawp_dimension( $from_dt, $to_dt, $table, $id_col, $label_col, $sub = '', array $joins = array(), $limit = 25 ) {
	if ( ! minn_admin_iawp_has( 'sessions' ) || ! minn_admin_iawp_has( $table ) ) {
		return array();
	}
	$totals = array( 'label' => array( 'max' => 'd.' . $label_col ) );
	if ( '' !== $sub ) {
		$totals['sub'] = array( 'max' => $sub );
	}
	$rows = Minn_Admin_Kernel_SQL::totals(
		array( 'table' => minn_admin_iawp_table( 'sessions' ), 'joins' => array( 'd' => array( 'table' => minn_admin_iawp_table( $table ), 'on' => array( $id_col => 'e.' . $id_col ) ) ) + $joins ),
		array( $id_col ),
		$totals + array( 'visitors' => array( 'distinct' => 'visitor_id' ), 'pageviews' => array( 'sum' => 'total_views' ) ),
		minn_admin_traffic_window( 'created_at', $from_dt, $to_dt ) + array( 'd.' . $id_col => array( 'none' => array( null ) ) ),
		array( 'visitors' => 'DESC', 'pageviews' => 'DESC' ),
		max( 1, min( 100, (int) $limit ) )
	);

	$out = array();
	foreach ( $rows as $row ) {
		$label = trim( (string) $row['label'] );
		if ( '' === $label ) {
			continue;
		}
		$out[] = array(
			'label'     => $label,
			'sub'       => trim( (string) ( $row['sub'] ?? '' ) ),
			'visitors'  => (int) $row['visitors'],
			'pageviews' => (int) $row['pageviews'],
		);
	}
	return $out;
}

/**
 * Pro: UTM campaigns. Label = utm_campaign, sub = "source / medium".
 */
function minn_admin_iawp_campaigns( $from_dt, $to_dt, $limit = 25 ) {
	foreach ( array( 'sessions', 'campaigns', 'utm_campaigns', 'utm_sources', 'utm_mediums' ) as $t ) {
		if ( ! minn_admin_iawp_has( $t ) ) {
			return array();
		}
	}
	$rows = Minn_Admin_Kernel_SQL::totals(
		array(
			'table' => minn_admin_iawp_table( 'sessions' ),
			'joins' => array(
				'c'  => array( 'table' => minn_admin_iawp_table( 'campaigns' ), 'on' => array( 'campaign_id' => 'e.campaign_id' ) ),
				'uc' => array( 'table' => minn_admin_iawp_table( 'utm_campaigns' ), 'on' => array( 'id' => 'c.utm_campaign_id' ) ),
				'us' => array( 'table' => minn_admin_iawp_table( 'utm_sources' ), 'on' => array( 'id' => 'c.utm_source_id' ) ),
				'um' => array( 'table' => minn_admin_iawp_table( 'utm_mediums' ), 'on' => array( 'id' => 'c.utm_medium_id' ) ),
			),
		),
		array( 'c.utm_campaign_id', 'c.utm_source_id', 'c.utm_medium_id' ),
		array(
			'campaign'  => array( 'max' => 'uc.utm_campaign' ),
			'source'    => array( 'max' => 'us.utm_source' ),
			'medium'    => array( 'max' => 'um.utm_medium' ),
			'visitors'  => array( 'distinct' => 'visitor_id' ),
			'pageviews' => array( 'sum' => 'total_views' ),
		),
		minn_admin_traffic_window( 'created_at', $from_dt, $to_dt ) + array( 'c.campaign_id' => array( 'none' => array( null ) ) ),
		array( 'visitors' => 'DESC', 'pageviews' => 'DESC' ),
		max( 1, min( 100, (int) $limit ) )
	);

	$out = array();
	foreach ( $rows as $row ) {
		$label = trim( (string) $row['campaign'] );
		if ( '' === $label ) {
			continue;
		}
		$parts = array_filter( array( trim( (string) $row['source'] ), trim( (string) $row['medium'] ) ) );
		$out[] = array(
			'label'     => $label,
			'sub'       => implode( ' / ', $parts ),
			'visitors'  => (int) $row['visitors'],
			'pageviews' => (int) $row['pageviews'],
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
	foreach ( array( 'clicks', 'clicked_links', 'links', 'click_targets' ) as $t ) {
		if ( ! minn_admin_iawp_has( $t ) ) {
			return array();
		}
	}
	$joins  = array(
		'cl' => array( 'table' => minn_admin_iawp_table( 'clicked_links' ), 'on' => array( 'click_id' => 'e.click_id' ) ),
		'l'  => array( 'table' => minn_admin_iawp_table( 'links' ), 'on' => array( 'id' => 'cl.link_id' ) ),
		't'  => array( 'table' => minn_admin_iawp_table( 'click_targets' ), 'on' => array( 'click_target_id' => 'l.click_target_id' ) ),
	);
	$totals = array( 'target' => array( 'max' => 't.target' ), 'protocol' => array( 'max' => 't.protocol' ), 'clicks' => array( 'distinct' => 'click_id' ) );
	if ( minn_admin_iawp_has( 'views' ) && minn_admin_iawp_has( 'sessions' ) ) {
		$joins['v']         = array( 'table' => minn_admin_iawp_table( 'views' ), 'on' => array( 'id' => 'e.view_id' ) );
		$joins['s']         = array( 'table' => minn_admin_iawp_table( 'sessions' ), 'on' => array( 'session_id' => 'v.session_id' ) );
		$totals['visitors'] = array( 'distinct' => 's.visitor_id' );
	}
	$rows = Minn_Admin_Kernel_SQL::totals(
		array( 'table' => minn_admin_iawp_table( 'clicks' ), 'joins' => $joins ),
		array( 'l.click_target_id' ),
		$totals,
		// A click whose link and target the plugin still has: its own inner joins.
		minn_admin_traffic_window( 'created_at', $from_dt, $to_dt ) + array( 't.click_target_id' => array( 'none' => array( null ) ) ),
		array( 'clicks' => 'DESC' ),
		max( 1, min( 100, (int) $limit ) )
	);

	$out = array();
	foreach ( $rows as $row ) {
		$target = trim( (string) $row['target'] );
		if ( '' === $target ) {
			continue;
		}
		$proto = trim( (string) $row['protocol'] );
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
		$count = sprintf( _n( '%s click', '%s clicks', (int) $row['clicks'], 'minn-admin' ), number_format_i18n( (int) $row['clicks'] ) );
		$out[] = array(
			'label'     => $label,
			'sub'       => '' !== $sub ? $count . ' · ' . $sub : $count,
			'url'       => $url,
			'visitors'  => (int) ( $row['visitors'] ?? 0 ),
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
	$days              = max( 1, (int) $days );
	list( $cur_start ) = minn_admin_traffic_starts( $days );
	$since             = gmdate( 'Y-m-d 00:00:00', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS );

	// Pageviews from views, visitors from sessions, each by its UTC day.
	$per_day = array();
	foreach ( array( array( 'views', 'viewed_at', 'p', array( 'count' => '*' ) ), array( 'sessions', 'created_at', 'v', array( 'distinct' => 'visitor_id' ) ) ) as $q ) {
		if ( ! minn_admin_iawp_has( $q[0] ) ) {
			continue;
		}
		foreach ( Minn_Admin_Kernel_SQL::totals( minn_admin_iawp_table( $q[0] ), array( 'd' => array( 'day' => $q[1], 'clock' => 'local' ) ), array( 'n' => $q[3] ), array( $q[1] => array( 'op' => '>=', 'value' => $since ) ), array(), 5000 ) as $row ) {
			$per_day[ $row['d'] ]          = $per_day[ $row['d'] ] ?? array( 'd' => $row['d'], 'v' => 0, 'p' => 0 );
			$per_day[ $row['d'] ][ $q[2] ] = (int) $row['n'];
		}
	}
	if ( ! $per_day ) {
		return $traffic;
	}
	return minn_admin_traffic_series( minn_admin_iawp_source_name(), array_values( $per_day ), $cur_start );
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
			'c2.country',
			array( 'c2' => array( 'table' => minn_admin_iawp_table( 'countries' ), 'on' => array( 'country_id' => 'd.country_id' ) ) )
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
