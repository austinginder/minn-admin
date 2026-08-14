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
 * The `logger IN (...)` fragment Simple History itself appends to every query.
 *
 * Their Log_Query never reads the table without it: each logger declares a
 * capability, so an Editor is shown posts and media but never the user,
 * options, plugin or core-update loggers. The collection route rides their own
 * REST endpoint and inherits that for free. Anything here that queries the
 * table directly has to reproduce it, or it reports on rows the same caller is
 * refused when they ask for them one by one.
 *
 * Returns null when their API is missing, so the caller can fall back to
 * refusing rather than to counting everything.
 *
 * @return string|null SQL fragment, already escaped by Simple History.
 */
function minn_admin_simple_history_logger_scope() {
	if ( ! class_exists( 'Simple_History\\Simple_History' ) ) {
		return null;
	}
	try {
		$sh = Simple_History\Simple_History::get_instance();
		if ( ! method_exists( $sh, 'get_loggers_that_user_can_read' ) ) {
			return null;
		}
		$sql = (string) $sh->get_loggers_that_user_can_read( get_current_user_id(), 'sql' );
		// They return "(NULL)" when the user may read nothing, which matches no
		// rows — the correct answer, not an empty filter.
		return '' !== $sql ? $sql : null;
	} catch ( \Throwable $e ) {
		return null;
	}
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
			'rows'    => array( array( 'label' => __( 'Events', 'minn-admin' ), 'value' => '—', 'hint' => __( 'History table not found', 'minn-admin' ) ) ),
			'actions' => array( array( 'label' => __( 'Open Simple History ↗', 'minn-admin' ), 'href' => minn_admin_simple_history_admin_url() ) ),
		);
	}
	// Count only what this caller is allowed to read, exactly as their own
	// Log_Query does. Without it an Editor gets a live failed-login counter
	// and an administrator-activity timeline from a screen the plugin blanks
	// for them: failed logins are recorded by SimpleUserLogger, which asks
	// for edit_users.
	$scope = minn_admin_simple_history_logger_scope();
	if ( null === $scope ) {
		return array(
			'rows'    => array( array( 'label' => __( 'Events', 'minn-admin' ), 'value' => '—', 'hint' => __( 'Cannot determine which loggers you may read', 'minn-admin' ) ) ),
			'actions' => array( array( 'label' => __( 'Open Simple History ↗', 'minn-admin' ), 'href' => minn_admin_simple_history_admin_url() ) ),
		);
	}
	$readable = " AND logger IN {$scope}";

	// SH stores `date` as site-local MySQL datetime (matches list date_local).
	$now_ts    = current_time( 'timestamp' );
	$since_24h = wp_date( 'Y-m-d H:i:s', $now_ts - DAY_IN_SECONDS );
	$since_7d  = wp_date( 'Y-m-d H:i:s', $now_ts - ( 7 * DAY_IN_SECONDS ) );

	$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE 1=1{$readable}" );
	$day     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE date >= %s{$readable}", $since_24h ) );
	$week    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE date >= %s{$readable}", $since_7d ) );
	$errors  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE date >= %s AND level IN ('emergency','alert','critical','error'){$readable}",
		$since_7d
	) );
	$warns   = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE date >= %s AND level = 'warning'{$readable}",
		$since_7d
	) );
	$last    = $wpdb->get_var( "SELECT date FROM {$table} WHERE 1=1{$readable} ORDER BY id DESC LIMIT 1" );
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
				'label' => __( 'Events (24h)', 'minn-admin' ),
				'value' => number_format_i18n( $day ),
				'hint'  => number_format_i18n( $week ) . ' in the last 7 days',
			),
			array(
				'label' => __( 'Events all-time', 'minn-admin' ),
				'value' => number_format_i18n( $total ),
			),
			array(
				'label' => __( 'Last event', 'minn-admin' ),
				'value' => $last_label,
			),
			array(
				'label' => __( 'Severity (7d)', 'minn-admin' ),
				'value' => $mix ? implode( ' · ', $mix ) : 'No errors or warnings',
			),
		),
		'actions' => array(
			array( 'label' => __( 'Open Simple History ↗', 'minn-admin' ), 'href' => minn_admin_simple_history_admin_url() ),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! defined( 'SIMPLE_HISTORY_VERSION' ) ) {
		return $surfaces;
	}

	$surfaces['simple-history'] = array(
		'label'      => __( 'Activity Log', 'minn-admin' ),
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
				array( 'key' => 'message', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'initiator_data.user_login', 'altKey' => 'initiator', 'label' => __( 'Who', 'minn-admin' ) ),
				array( 'key' => 'loglevel', 'label' => __( 'Level', 'minn-admin' ), 'format' => 'pill' ),
				// date_local is site-local (matches parseWpDate). date_gmt
				// without a zone suffix used to render "in 4h" on EDT.
				array( 'key' => 'date_local', 'altKey' => 'date_gmt', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
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
