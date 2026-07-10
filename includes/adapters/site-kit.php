<?php
/**
 * Bundled adapter: Site Kit by Google (traffic provider).
 *
 * Google Analytics data for the Overview chart, through Site Kit's OWN
 * REST module (`analytics-4` report datapoint) via rest_do_request — so
 * Google auth, dashboard-sharing permissions and quota handling all stay
 * Site Kit's job and run against the current user. Registered at priority
 * 20: a purpose-installed analytics plugin (Koko &co at 10) answers first;
 * Site Kit is the fallback most sites already have.
 *
 * Responses are cached per user for 15 minutes — GA data lags reality by
 * hours anyway, and the report round-trips to Google's API.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * GA4 runReport response → the minn_admin_traffic shape, or null when the
 * response has no usable rows. Dates arrive as YYYYMMDD, possibly unsorted.
 */
function minn_admin_site_kit_map_report( $data, $days ) {
	if ( empty( $data['rows'] ) || ! is_array( $data['rows'] ) ) {
		return null;
	}
	$days      = max( 1, (int) $days );
	$cur_start = gmdate( 'Ymd', time() - ( $days - 1 ) * DAY_IN_SECONDS );
	$map       = array();
	$prev      = 0;
	foreach ( $data['rows'] as $row ) {
		$date = (string) ( $row['dimensionValues'][0]['value'] ?? '' );
		if ( ! preg_match( '/^\d{8}$/', $date ) ) {
			continue;
		}
		$visitors = (int) ( $row['metricValues'][0]['value'] ?? 0 );
		$views    = (int) ( $row['metricValues'][1]['value'] ?? 0 );
		if ( $date >= $cur_start ) {
			$iso         = substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
			$map[ $iso ] = array(
				'visitors'  => $visitors,
				'pageviews' => $views,
			);
		} else {
			$prev += $visitors;
		}
	}
	if ( ! $map ) {
		return null;
	}
	ksort( $map );
	return array(
		'source'        => 'Site Kit',
		'days'          => $map,
		'prev_visitors' => $prev,
	);
}

add_filter( 'minn_admin_traffic', function ( $traffic, $days ) {
	if ( null !== $traffic ) {
		return $traffic; // a dedicated analytics plugin answered first
	}
	if ( ! defined( 'GOOGLESITEKIT_VERSION' ) ) {
		return $traffic;
	}
	// Connected = the Analytics module carries a GA4 property.
	$settings = get_option( 'googlesitekit_analytics-4_settings' );
	if ( empty( $settings['propertyID'] ) ) {
		return $traffic;
	}

	$days      = max( 1, (int) $days );
	$cache_key = 'minn_sitekit_traffic_' . $days . '_' . get_current_user_id();
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$request = new WP_REST_Request( 'GET', '/google-site-kit/v1/modules/analytics-4/data/report' );
	$request->set_param( 'startDate', gmdate( 'Y-m-d', time() - ( 2 * $days - 1 ) * DAY_IN_SECONDS ) );
	$request->set_param( 'endDate', gmdate( 'Y-m-d' ) );
	$request->set_param( 'metrics', array(
		array( 'name' => 'totalUsers' ),
		array( 'name' => 'screenPageViews' ),
	) );
	$request->set_param( 'dimensions', array( array( 'name' => 'date' ) ) );

	$response = rest_do_request( $request );
	if ( $response->is_error() ) {
		return $traffic; // not authed / shared-view denied / quota — fall through
	}
	// Route responses carry objects whose protected props only flatten via
	// JsonSerializable (the GenerateBlocks gotcha) — round-trip through JSON.
	$data = json_decode( wp_json_encode( $response->get_data() ), true );
	$out  = minn_admin_site_kit_map_report( $data, $days );
	if ( ! $out ) {
		return $traffic;
	}
	set_transient( $cache_key, $out, 15 * MINUTE_IN_SECONDS );
	return $out;
}, 20, 2 );
