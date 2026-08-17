<?php
/**
 * Bundled adapter: Plausible Analytics (traffic provider).
 *
 * The official WordPress plugin keeps analytics on Plausible and renders its
 * wp-admin report through an unpassworded shared dashboard. Minn uses that
 * same shared-link grant against the dashboard's query endpoint. The grant
 * stays server-side; only aggregate counts and labels reach Minn's REST
 * responses. Reads are cached for 15 minutes to match the other remote
 * traffic providers.
 *
 * Registered after local analytics providers but before broad fallbacks such
 * as Site Kit and Jetpack Stats. If a site deliberately runs both Koko and
 * Plausible, the local provider keeps precedence.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the active plugin's dashboard grant into a safe query target.
 *
 * Plausible's plugin stores one domain and shared link per language-domain
 * key. The query host must match the plugin's configured Plausible host, so a
 * tampered option cannot turn this server-side reader into an arbitrary POST.
 *
 * @return array|null { endpoint, auth, dashboard } or null when unavailable.
 */
function minn_admin_plausible_config() {
	if ( ! class_exists( '\Plausible\Analytics\WP\Helpers' ) || ! current_user_can( 'manage_options' ) ) {
		return null;
	}

	try {
		$helpers  = '\Plausible\Analytics\WP\Helpers';
		$settings = $helpers::get_settings();
		$key      = $helpers::get_current_language_domain_key();
		$hosted   = untrailingslashit( $helpers::get_hosted_domain_url() );
	} catch ( \Throwable $e ) {
		return null;
	}

	$self_hosted = ! empty( $settings['self_hosted_domain'] );
	if ( $self_hosted ) {
		$shared = (string) ( $settings['self_hosted_shared_link'] ?? '' );
	} else {
		if ( empty( $settings['enable_analytics_dashboard'] ) ) {
			return null;
		}
		$links  = isset( $settings['shared_link'] ) && is_array( $settings['shared_link'] ) ? $settings['shared_link'] : array();
		$shared = (string) ( $links[ $key ] ?? $links['default'] ?? '' );
	}

	$shared_parts = wp_parse_url( $shared );
	$hosted_parts = wp_parse_url( $hosted );
	if ( ! is_array( $shared_parts ) || ! is_array( $hosted_parts )
		|| 'https' !== strtolower( (string) ( $shared_parts['scheme'] ?? '' ) )
		|| '' === (string) ( $shared_parts['host'] ?? '' )
		|| ! preg_match( '#^/share(?:/|$)#', (string) ( $shared_parts['path'] ?? '' ) )
		|| 0 !== strcasecmp( (string) $shared_parts['host'], (string) ( $hosted_parts['host'] ?? '' ) )
		|| (int) ( $shared_parts['port'] ?? 443 ) !== (int) ( $hosted_parts['port'] ?? 443 ) ) {
		return null;
	}

	parse_str( (string) ( $shared_parts['query'] ?? '' ), $query );
	$auth   = isset( $query['auth'] ) && is_string( $query['auth'] ) ? $query['auth'] : '';
	$domain = (string) $helpers::get_domain();
	if ( '' === $auth || '' === $domain || ! preg_match( '/^[A-Za-z0-9_-]+$/', $auth ) ) {
		return null;
	}

	$origin = 'https://' . $shared_parts['host'];
	if ( isset( $shared_parts['port'] ) ) {
		$origin .= ':' . (int) $shared_parts['port'];
	}

	return array(
		'endpoint'  => $origin . '/api/stats/' . rawurlencode( $domain ) . '/query?auth=' . rawurlencode( $auth ),
		'auth'      => $auth,
		'dashboard' => admin_url( 'index.php?page=plausible_analytics_statistics' ),
		'cache'     => substr( md5( $origin . '|' . $domain . '|' . $auth ), 0, 12 ),
	);
}

/**
 * Query Plausible using the shared-dashboard access used by its own embed.
 *
 * @param array $config Output from minn_admin_plausible_config().
 * @param array $query  Plausible dashboard query body.
 * @return array|null Decoded response or null on any remote/API failure.
 */
function minn_admin_plausible_query( $config, $query ) {
	$response = wp_safe_remote_post( $config['endpoint'], array(
		'timeout' => 10,
		'headers' => array(
			'Accept'             => 'application/json',
			'Content-Type'       => 'application/json',
			'X-Shared-Link-Auth' => $config['auth'],
		),
		'body'    => wp_json_encode( $query ),
	) );
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : null;
}

/**
 * Shared fields every dashboard query expects.
 *
 * @param string $from Inclusive Y-m-d start.
 * @param string $to   Inclusive Y-m-d end.
 * @return array
 */
function minn_admin_plausible_query_base( $from, $to ) {
	return array(
		'date_range'   => array( $from, $to ),
		'relative_date' => null,
		'filters'      => array(),
		'include'      => array( 'imports' => true ),
	);
}

/**
 * Read a named metric without relying on Plausible's response column order.
 *
 * @param array  $data     Full Plausible response.
 * @param array  $row      Result row.
 * @param string $metric   Metric name.
 * @param int    $fallback Expected index when response metadata is absent.
 * @return int
 */
function minn_admin_plausible_metric( $data, $row, $metric, $fallback ) {
	$metrics = isset( $data['query']['metrics'] ) && is_array( $data['query']['metrics'] ) ? $data['query']['metrics'] : array();
	$index   = array_search( $metric, $metrics, true );
	if ( false === $index ) {
		$index = $fallback;
	}
	return (int) ( $row['metrics'][ $index ] ?? 0 );
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic ) {
		return $traffic;
	}
	$config = minn_admin_plausible_config();
	if ( ! $config ) {
		return $traffic;
	}

	$days      = max( 1, (int) $days );
	$cache_key = 'minn_plausible_traffic_' . $days . '_' . $config['cache'];
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$today      = current_datetime();
	$from       = $today->modify( '-' . ( 2 * $days - 1 ) . ' days' )->format( 'Y-m-d' );
	$cur_start  = $today->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
	$query      = minn_admin_plausible_query_base( $from, $today->format( 'Y-m-d' ) );
	$query     += array(
		'dimensions' => array( 'time:day' ),
		'metrics'    => array( 'visitors', 'pageviews' ),
		'order_by'   => array( array( 'time:day', 'asc' ) ),
	);
	$data       = minn_admin_plausible_query( $config, $query );
	if ( empty( $data['results'] ) || ! is_array( $data['results'] ) ) {
		return $traffic;
	}

	$map  = array();
	$prev = 0;
	$any  = false;
	foreach ( $data['results'] as $row ) {
		$date = (string) ( $row['dimensions'][0] ?? '' );
		$date = substr( $date, 0, 10 );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			continue;
		}
		$visitors  = minn_admin_plausible_metric( $data, $row, 'visitors', 0 );
		$pageviews = minn_admin_plausible_metric( $data, $row, 'pageviews', 1 );
		$any       = $any || $visitors > 0 || $pageviews > 0;
		if ( $date >= $cur_start ) {
			if ( $visitors > 0 || $pageviews > 0 ) {
				$map[ $date ] = array( 'visitors' => $visitors, 'pageviews' => $pageviews );
			}
		} else {
			$prev += $visitors;
		}
	}
	if ( ! $any ) {
		return $traffic;
	}
	ksort( $map );

	$result = array(
		'source'        => 'Plausible Analytics',
		'days'          => $map,
		'prev_visitors' => $prev,
	);
	set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
	return $result;
}, 12, 2 );

add_filter( 'minn_admin_traffic_day', function ( $data, $from, $to ) {
	if ( null !== $data ) {
		return $data;
	}
	$config = minn_admin_plausible_config();
	if ( ! $config ) {
		return $data;
	}

	$cache_key = 'minn_plausible_day_' . md5( $from . '|' . $to . '|' . $config['cache'] );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$base       = minn_admin_plausible_query_base( $from, $to );
	$page_query = $base + array(
		'dimensions' => array( 'event:page' ),
		'metrics'    => array( 'visitors', 'pageviews' ),
		'order_by'   => array( array( 'visitors', 'desc' ) ),
		'pagination' => array( 'limit' => 25, 'offset' => 0 ),
	);
	$pages_data = minn_admin_plausible_query( $config, $page_query );
	if ( ! is_array( $pages_data ) ) {
		return $data;
	}

	$pages = array();
	foreach ( (array) ( $pages_data['results'] ?? array() ) as $row ) {
		$path = (string) ( $row['dimensions'][0] ?? '' );
		if ( '' === $path ) {
			continue;
		}
		$path    = '/' . ltrim( $path, '/' );
		$url     = home_url( $path );
		$post_id = url_to_postid( $url );
		$title   = $post_id ? html_entity_decode( get_the_title( $post_id ), ENT_QUOTES ) : $path;
		$pages[] = array(
			'title'     => '' !== $title ? $title : $path,
			'path'      => $path,
			'url'       => $url,
			'postId'    => $post_id,
			'visitors'  => minn_admin_plausible_metric( $pages_data, $row, 'visitors', 0 ),
			'pageviews' => minn_admin_plausible_metric( $pages_data, $row, 'pageviews', 1 ),
		);
	}

	$ref_query = $base + array(
		'dimensions' => array( 'visit:source' ),
		'metrics'    => array( 'visitors', 'pageviews' ),
		'order_by'   => array( array( 'visitors', 'desc' ) ),
		'pagination' => array( 'limit' => 16, 'offset' => 0 ),
	);
	$refs_data = minn_admin_plausible_query( $config, $ref_query );
	$referrers = array();
	foreach ( (array) ( $refs_data['results'] ?? array() ) as $row ) {
		$label = (string) ( $row['dimensions'][0] ?? '' );
		if ( '' === $label || 'Direct / None' === $label ) {
			continue;
		}
		$referrers[] = array(
			'label'     => $label,
			'visitors'  => minn_admin_plausible_metric( $refs_data, $row, 'visitors', 0 ),
			'pageviews' => minn_admin_plausible_metric( $refs_data, $row, 'pageviews', 1 ),
		);
		if ( count( $referrers ) >= 15 ) {
			break;
		}
	}

	$result = array(
		'source'    => 'Plausible Analytics',
		'pages'     => $pages,
		'referrers' => $referrers,
		'adminUrl'  => $config['dashboard'],
	);
	set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
	return $result;
}, 12, 3 );
