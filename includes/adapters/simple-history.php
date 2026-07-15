<?php
/**
 * Bundled adapter: Simple History.
 *
 * Simple History 5.x ships a full REST API (simple-history/v1/events with
 * standard WP pagination), so the list is a pure descriptor. The status
 * card is a thin Minn shim (prefix-scoped COUNTs on simple_history) so the
 * surface matches Solid / LLA-R / Wordfence daily-ops depth. Visibility
 * follows Simple History's own view capability.
 *
 * last-sweep: 2026-07-15
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_simple_history_can() {
	return current_user_can( apply_filters( 'simple_history/view_history_capability', 'edit_pages' ) );
}

function minn_admin_simple_history_admin_url() {
	if ( class_exists( 'Simple_History\\Simple_History' ) ) {
		try {
			$slug = \Simple_History\Simple_History::MENU_PAGE_SLUG;
			if ( is_string( $slug ) && $slug ) {
				return admin_url( 'admin.php?page=' . $slug );
			}
		} catch ( \Throwable $e ) { /* fall through */ }
	}
	// Stable fallbacks across SH 4.x/5.x placements.
	return admin_url( 'index.php?page=simple_history_page' );
}

/**
 * Status-card model: 24h / 7d / all-time counts + level mix + last event.
 *
 * @return array{rows:array,actions:array}
 */
function minn_admin_simple_history_status_model() {
	global $wpdb;
	$table = $wpdb->prefix . 'simple_history';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived table.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array(
			'rows'    => array( array( 'label' => 'Events', 'value' => '—', 'hint' => 'History table not found' ) ),
			'actions' => array( array( 'label' => 'Open Simple History ↗', 'href' => minn_admin_simple_history_admin_url() ) ),
		);
	}
	// SH stores `date` as site-local MySQL datetime (matches list date_local).
	$now_ts    = current_time( 'timestamp' );
	$since_24h = wp_date( 'Y-m-d H:i:s', $now_ts - DAY_IN_SECONDS );
	$since_7d  = wp_date( 'Y-m-d H:i:s', $now_ts - ( 7 * DAY_IN_SECONDS ) );

	$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$day     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE date >= %s", $since_24h ) );
	$week    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE date >= %s", $since_7d ) );
	$errors  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE date >= %s AND level IN ('emergency','alert','critical','error')",
		$since_7d
	) );
	$warns   = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE date >= %s AND level = 'warning'",
		$since_7d
	) );
	$last    = $wpdb->get_var( "SELECT date FROM {$table} ORDER BY id DESC LIMIT 1" );
	// phpcs:enable

	$last_label = '—';
	if ( $last ) {
		try {
			$dt = date_create( $last, wp_timezone() );
			if ( $dt ) {
				$last_label = human_time_diff( $dt->getTimestamp(), time() ) . ' ago';
			}
		} catch ( \Throwable $e ) {
			$last_label = (string) $last;
		}
	}

	$mix = array();
	if ( $errors ) {
		$mix[] = number_format_i18n( $errors ) . ' error' . ( 1 === $errors ? '' : 's' );
	}
	if ( $warns ) {
		$mix[] = number_format_i18n( $warns ) . ' warning' . ( 1 === $warns ? '' : 's' );
	}

	return array(
		'rows'    => array(
			array(
				'label' => 'Events (24h)',
				'value' => number_format_i18n( $day ),
				'hint'  => number_format_i18n( $week ) . ' in the last 7 days',
			),
			array(
				'label' => 'Events all-time',
				'value' => number_format_i18n( $total ),
			),
			array(
				'label' => 'Last event',
				'value' => $last_label,
			),
			array(
				'label' => 'Severity (7d)',
				'value' => $mix ? implode( ' · ', $mix ) : 'No errors or warnings',
			),
		),
		'actions' => array(
			array( 'label' => 'Open Simple History ↗', 'href' => minn_admin_simple_history_admin_url() ),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! defined( 'SIMPLE_HISTORY_VERSION' ) ) {
		return $surfaces;
	}

	$surfaces['simple-history'] = array(
		'label'      => 'Activity Log',
		'family'     => 'activity-log',
		'sub'        => 'Simple History',
		'icon'       => 'clock',
		'cap'        => apply_filters( 'simple_history/view_history_capability', 'edit_pages' ),
		'status'     => array( 'route' => 'minn-admin/v1/simple-history/status' ),
		'collection' => array(
			'route'     => 'simple-history/v1/events',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'tabs'      => array(
				'param'    => 'loglevels',
				'static'   => array(
					array( 'error', 'Errors' ),
					array( 'warning', 'Warnings' ),
					array( 'notice', 'Notices' ),
					array( 'info', 'Info' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'initiator_data.user_login', 'altKey' => 'initiator', 'label' => 'Who' ),
				array( 'key' => 'loglevel', 'label' => 'Level', 'format' => 'pill' ),
				// date_local is site-local (matches parseWpDate). date_gmt
				// without a zone suffix used to render "in 4h" on EDT.
				array( 'key' => 'date_local', 'altKey' => 'date_gmt', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'skip' => array(
					'message_html', 'message_uninterpolated', 'details_html', 'details_data',
					'context', 'ip_addresses', 'action_links', 'occasions_id', 'sticky',
					'sticky_appended', 'backfilled', 'ai_origin', 'via', 'link', 'permalink',
					'message_key', 'date_local', 'subsequent_occasions_count',
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! defined( 'SIMPLE_HISTORY_VERSION' ) ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/simple-history/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_simple_history_can',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_simple_history_status_model() );
		},
	) );
} );
