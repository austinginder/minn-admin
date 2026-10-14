<?php
/**
 * Bundled adapter: Sucuri Security (sucuri-scanner) — the local audit log.
 *
 * Sucuri writes every audit event into a local queue (the `auditqueue`
 * datastore file under uploads/sucuri/). With their API service registered,
 * a cron job ships the queue to Sucuri's servers and empties it, and the full
 * history is then only readable remotely with the site's API key; without it
 * (the common free install) the history simply stays on the site. Minn reads
 * the local queue ONLY, through their own SucuriScanAPI::getAuditLogsFromQueue()
 * (their parser, their timezone handling), and never calls their API: no
 * network on a list view, and no key handling. The status card says which of
 * the two situations the site is in so the list is never mistaken for the
 * full history.
 *
 * Family: activity-log (sub "Sucuri"). Read-only; their settings, scanner and
 * hardening stay on their screens. Gate: manage_options, the capability every
 * Sucuri screen uses.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_sucuri_active() {
	return class_exists( 'SucuriScanAPI' ) && method_exists( 'SucuriScanAPI', 'getAuditLogsFromQueue' );
}

function minn_admin_sucuri_can() {
	// Sucuri keeps ONE audit queue for the whole network (uploads/sucuri)
	// and moves its whole admin into Network Admin on multisite, so there
	// the log is the network owner's data, not a subsite admin's.
	return minn_admin_sucuri_active() && current_user_can( 'manage_options' ) && Minn_Admin::network_owner();
}

/** Whether their API service ships the queue off-site (so it only holds unsent events). */
function minn_admin_sucuri_ships_offsite() {
	try {
		$enabled = class_exists( 'SucuriScanOption' ) && ! SucuriScanOption::isDisabled( ':api_service' );
		$key     = method_exists( 'SucuriScanAPI', 'getPluginKey' ) ? SucuriScanAPI::getPluginKey() : false;
		return $enabled && ! empty( $key );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Every queued event, newest first, parsed by their own reader.
 *
 * @return array[] { id, event, timestamp, username, remote_addr, message }
 */
function minn_admin_sucuri_events() {
	static $events = null;
	if ( null !== $events ) {
		return $events;
	}
	$events = array();
	try {
		$res = SucuriScanAPI::getAuditLogsFromQueue();
	} catch ( \Throwable $e ) {
		return $events;
	}
	foreach ( (array) ( is_array( $res ) && isset( $res['output_data'] ) ? $res['output_data'] : array() ) as $log ) {
		if ( ! is_array( $log ) || empty( $log['timestamp'] ) ) {
			continue;
		}
		$events[] = array(
			// Their queue keys are not carried through the parser; a content
			// hash is stable across reads for the list's row identity.
			'id'          => substr( md5( $log['timestamp'] . '|' . ( $log['message'] ?? '' ) . '|' . ( $log['username'] ?? '' ) . '|' . ( $log['remote_addr'] ?? '' ) ), 0, 12 ),
			'event'       => (string) ( $log['event'] ?? 'notice' ),
			'timestamp'   => (int) $log['timestamp'],
			'username'    => (string) ( $log['username'] ?? '' ),
			'remote_addr' => (string) ( $log['remote_addr'] ?? '' ),
			'message'     => wp_strip_all_tags( (string) ( $log['message'] ?? '' ) ),
		);
	}
	usort( $events, function ( $a, $b ) {
		return $b['timestamp'] <=> $a['timestamp'];
	} );
	return $events;
}

minn_admin_resource( 'sucuri/events', array(
	'active' => 'minn_admin_sucuri_active',
	'gates'  => array( 'view' => 'minn_admin_sucuri_can' ),
	'source' => array( 'rows' => 'minn_admin_sucuri_events' ),
	'list'   => array(
		'facets' => array( array( 'param' => 'level', 'column' => 'event', 'type' => 'key' ) ),
		'search' => array( 'columns' => array( 'message', 'username', 'remote_addr' ) ),
		// A clicked chart bar sends site-local day bounds; their parsed timestamps are epochs.
		'range'  => array( 'column' => 'timestamp', 'clock' => 'epoch' ),
		// The event page reads the activity-log names for the time, address
		// and level the list shows under its own keys.
		'row'    => function ( array $e ) {
			$e = array_merge( $e, array( 'when' => $e['timestamp'], 'date' => $e['timestamp'], 'ip' => $e['remote_addr'], 'severity' => $e['event'] ) );
			unset( $e['timestamp'] );
			return $e;
		},
		'fields' => array(
			'id'          => 'text',
			'event'       => 'text',
			'username'    => 'text',
			'remote_addr' => 'text',
			'message'     => 'text',
			'when'        => array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'z' ),
			'date'        => array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'z' ),
			'ip'          => 'text',
			'severity'    => 'text',
		),
	),
) );

minn_admin_endpoint( 'sucuri/status', array(
	'active' => 'minn_admin_sucuri_active',
	'gate'   => 'minn_admin_sucuri_can',
	'run'    => function () {
		$events  = minn_admin_sucuri_events();
		$offsite = minn_admin_sucuri_ships_offsite();
		$days    = minn_admin_chart_days();
		$serious = 0;
		foreach ( $events as $e ) {
			$is_serious = in_array( $e['event'], array( 'critical', 'error', 'warning' ), true );
			minn_admin_chart_bump( $days, wp_date( 'Y-m-d', $e['timestamp'] ), $is_serious );
			$serious   += $is_serious ? 1 : 0;
		}
		// A day's bar counts every event: primary = routine, secondary =
		// warnings and worse, like the other activity logs' split.
		$rows = array(
			array(
				'label' => __( 'Events on this site', 'minn-admin' ),
				'value' => number_format_i18n( count( $events ) ),
				'hint'  => $offsite
					? __( 'Sucuri sends events to its servers; only those not yet sent are here', 'minn-admin' )
					: __( 'Kept on this site (no Sucuri API service)', 'minn-admin' ),
			),
			array(
				'label' => __( 'Warnings and worse', 'minn-admin' ),
				'value' => number_format_i18n( $serious ),
			),
		);
		if ( $events ) {
			$rows[] = array(
				'label' => __( 'Latest', 'minn-admin' ),
				'value' => $events[0]['message'],
				/* translators: %s: how long ago, like "3 hours". */
				'hint'  => sprintf( __( '%s ago', 'minn-admin' ), human_time_diff( $events[0]['timestamp'] ) ),
			);
		}
		return array(
			'rows'    => $rows,
			'chart'   => minn_admin_chart_build( $days, __( 'Events', 'minn-admin' ), __( 'Warnings and worse', 'minn-admin' ) ),
			'actions' => array(
				array( 'label' => __( 'Open Sucuri ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=sucuriscan' ) ),
			),
		);
	},
) );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_sucuri_can() ) {
		return $surfaces;
	}
	$surfaces['sucuri'] = array(
		'label'      => __( 'Activity Log', 'minn-admin' ),
		'family'     => 'activity-log',
		'sub'        => 'Sucuri',
		'plugin'     => 'sucuri-scanner',
		'icon'       => 'shield',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/sucuri/status' ),
		'collection' => Minn_Admin_Kernel::collection( 'sucuri/events', array(
			'tabs'      => array(
				'param'    => 'level',
				'static'   => array(
					array( 'critical', __( 'Critical', 'minn-admin' ) ),
					array( 'error', __( 'Errors', 'minn-admin' ) ),
					array( 'warning', __( 'Warnings', 'minn-admin' ) ),
					array( 'notice', __( 'Notices', 'minn-admin' ) ),
					array( 'info', __( 'Info', 'minn-admin' ) ),
				),
				'allLabel' => __( 'All', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,2fr)' ),
				array( 'key' => 'username', 'label' => __( 'Who', 'minn-admin' ) ),
				array( 'key' => 'remote_addr', 'label' => __( 'IP', 'minn-admin' ), 'format' => 'mono' ),
				array( 'key' => 'event', 'label' => __( 'Level', 'minn-admin' ), 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'when', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(),
		) ),
	);
	return $surfaces;
} );
