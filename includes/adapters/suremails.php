<?php
/**
 * Bundled adapter: SureMails (Wave B).
 *
 * SureMails logs every send into {prefix}suremails_email_log (free feature).
 * No log REST, so this is the shim pattern: prefix-scoped SELECTs, sent/failed
 * tabs, search, single + bulk delete, a status card with a 14-day chart, and a
 * sections detail (status pill, sandboxed HTML body, response peek). It logs
 * only while SureMails is the active mailer; the surface gates on the table
 * existing. `email_to`/`headers`/`response` are maybe_serialize'd — decoded by
 * REGEX only, never unserialize (adapter ground rule). `created_at` is written
 * on the site's clock (current_time), and timestamps emit converted to UTC.
 *
 * last-sweep: 2026-07-17
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_suremails_active() {
	return defined( 'SUREMAILS_VERSION' ) || defined( 'SUREMAILS' );
}

/**
 * Convert a site-local datetime to a UTC ISO-8601 string.
 *
 * SureMails, Site Mailer and SureForms all write `created_at` with
 * current_time( 'mysql' ), the site's wall clock, whatever the column's
 * CURRENT_TIMESTAMP default says. So the shift is the site's zone (and its
 * DST), never the database server's: the two agree only where the database
 * happens to run in the site's zone.
 *
 * @param string $mysql_datetime "Y-m-d H:i:s" on the site's clock.
 * @return string ISO-8601 with a trailing Z, or '' when unparseable.
 */
if ( ! function_exists( 'minn_admin_db_local_to_utc_iso' ) ) {
	function minn_admin_db_local_to_utc_iso( $mysql_datetime ) {
		$dt = trim( (string) $mysql_datetime );
		if ( '' === $dt || 0 === strpos( $dt, '0000-00-00' ) || false === strtotime( $dt ) ) {
			return '';
		}
		return get_gmt_from_date( $dt, 'Y-m-d\TH:i:s\Z' );
	}
}

function minn_admin_suremails_table() {
	global $wpdb;
	return $wpdb->prefix . 'suremails_email_log';
}

/**
 * Pull email addresses out of a maybe_serialized recipient value without
 * unserializing. Handles a plain string and a serialized array of addresses
 * or {email,name} maps; returns a short comma list.
 */
function minn_admin_suremails_recipients( $raw ) {
	$raw = (string) $raw;
	if ( '' === $raw ) {
		return '';
	}
	if ( ! preg_match( '/^a:\d+:/', $raw ) ) {
		return $raw; // plain address string.
	}
	// Serialized: scan quoted strings that look like addresses.
	preg_match_all( '/s:\d+:"([^"]*@[^"]*)"/', $raw, $m );
	$addrs = array_values( array_unique( array_filter( $m[1] ) ) );
	return $addrs ? implode( ', ', array_slice( $addrs, 0, 5 ) ) : '';
}

/** A short human peek at the serialized `response` blob; never the raw value. */
function minn_admin_suremails_response_peek( $raw ) {
	$raw = (string) $raw;
	if ( '' === $raw ) {
		return '';
	}
	if ( preg_match( '/"(?:message|error|Message|error_message)";s:\d+:"([^"]*)"/', $raw, $m ) ) {
		return $m[1];
	}
	if ( preg_match( '/^a:\d+:|^O:\d+:/', $raw ) || strlen( $raw ) > 200 ) {
		return '';
	}
	return $raw;
}

/** One logged email's detail: the delivery, the message, the provider's reply. */
function minn_admin_suremails_view( array $r ) {
	$row      = (object) $r;
	$delivery = array(
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) $row->status, 'type' => 'pill' ),
		array( 'label' => __( 'To', 'minn-admin' ), 'value' => minn_admin_suremails_recipients( $row->email_to ) ),
		array( 'label' => __( 'From', 'minn-admin' ), 'value' => (string) $row->email_from ),
	);
	if ( '' !== (string) $row->connection ) {
		$delivery[] = array( 'label' => __( 'Connection', 'minn-admin' ), 'value' => (string) $row->connection );
	}
	$iso = minn_admin_db_local_to_utc_iso( $row->created_at );
	if ( '' !== $iso ) {
		$delivery[] = array( 'label' => __( 'Date', 'minn-admin' ), 'value' => $iso );
	}
	$peek = minn_admin_suremails_response_peek( $row->response );
	$more = '' !== $peek ? array( array( 'title' => __( 'Provider reply', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Response', 'minn-admin' ), 'value' => $peek ) ) ) ) : array();
	return minn_admin_mail_sections( $delivery, $row->subject, (string) $row->body, $more );
}

function minn_admin_suremails_can() {
	return current_user_can( 'manage_options' );
}

// created_at is the site's wall clock (current_time( 'mysql' )).
minn_admin_resource( 'suremails/emails', function () {
	return array(
		'active'  => 'minn_admin_suremails_active',
		'gates'   => array( 'view' => 'minn_admin_suremails_can' ),
		'labels'  => array( 'not_found' => __( 'Email not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'suremails_email_log',
			'key'     => 'id',
			'columns' => array( 'id', 'email_to', 'subject', 'status', 'created_at' ),
			'detail'  => array( '*' ),
		),
		'list'    => array(
			'facets' => array( array( 'param' => 'status', 'column' => 'status', 'type' => 'key' ) ),
			'search' => array( 'columns' => array( 'subject', 'email_from', 'email_to' ) ),
			'range'  => array( 'column' => 'created_at', 'clock' => 'local' ),
			'order'  => array( 'column' => 'created_at' ),
			'row'    => function ( array $r ) {
				return array(
					'id'         => (int) $r['id'],
					'subject'    => (string) $r['subject'],
					'to'         => minn_admin_suremails_recipients( $r['email_to'] ),
					'status'     => (string) $r['status'],
					'created_at' => $r['created_at'],
				);
			},
			'fields' => array(
				'id'         => 'int',
				'subject'    => 'text',
				'to'         => 'text',
				'status'     => 'text',
				// Site-local, sent as an absolute UTC time with a Z.
				'created_at' => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'z' ),
			),
		),
		'reads'   => array( 'view' => array( 'view' => 'minn_admin_suremails_view', 'fields' => array( 'sections' => 'raw' ) ) ),
		'actions' => array(
			// SureMails keeps no per-entry delete of its own (only its expiry).
			'delete' => minn_admin_mail_delete( function ( $id ) {
				return Minn_Admin_Kernel_SQL::delete( 'suremails_email_log', 'id', $id );
			} ),
		),
	);
} );

minn_admin_endpoint( 'suremails/status', array(
	'active' => 'minn_admin_suremails_active',
	'gate'   => 'minn_admin_suremails_can',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open SureMails ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=suremails' ) ) );
		return minn_admin_mail_card( 'suremails/emails', array(
			'table'         => 'suremails_email_log',
			'missing_value' => '—',
			'missing'       => __( 'SureMails has not logged any sends yet', 'minn-admin' ),
			'failed'        => array( 'status' => 'failed' ),
			// Fourteen site-local days, sent and failed apart; a clicked bar
			// sends its day back to the list.
			'chart'         => array( 'column' => 'created_at', 'clock' => 'local', 'split' => 'status', 'secondary' => array( 'failed' ), 'label' => 'M j' ),
			'actions'       => $open,
		) );
	},
) );

minn_admin_add_surface( 'suremails', 'minn_admin_suremails_active', function () {
	return minn_admin_mail_surface( 'suremails/emails', array(
		'sub'    => 'SureMails',
		'plugin' => 'suremails',
		'cap'    => 'manage_options',
		'status' => 'suremails/status',
	) );
} );
