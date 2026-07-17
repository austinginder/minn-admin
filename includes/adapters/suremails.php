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
 * REGEX only, never unserialize (adapter ground rule). `created_at` defaults to
 * the DB CURRENT_TIMESTAMP (UTC), so timestamps emit as UTC.
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
 * Convert a `DEFAULT current_timestamp()` datetime to a UTC ISO-8601 string.
 *
 * Plugins that store timestamps via the DB's own CURRENT_TIMESTAMP inherit
 * the database session timezone, which is UTC on many managed hosts but
 * SITE-LOCAL on others (Cove's dev MariaDB runs the OS zone). Rather than
 * guess, ask the DB its offset from UTC once and shift. Correct on every
 * host: a UTC database yields offset 0 and the value passes through.
 *
 * @param string $mysql_datetime "Y-m-d H:i:s" in the DB session zone.
 * @return string ISO-8601 with a trailing Z, or '' when unparseable.
 */
if ( ! function_exists( 'minn_admin_db_local_to_utc_iso' ) ) {
	function minn_admin_db_local_to_utc_iso( $mysql_datetime ) {
		$dt = trim( (string) $mysql_datetime );
		if ( '' === $dt || 0 === strpos( $dt, '0000-00-00' ) ) {
			return '';
		}
		$naive = strtotime( $dt . ' UTC' );
		if ( false === $naive ) {
			return '';
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', $naive - minn_admin_db_utc_offset() );
	}

	/** Seconds the DB session clock is ahead of UTC (0 on a UTC database). */
	function minn_admin_db_utc_offset() {
		static $offset = null;
		if ( null === $offset ) {
			global $wpdb;
			$offset = (int) $wpdb->get_var( 'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())' );
		}
		return $offset;
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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_suremails_active() ) {
		return $surfaces;
	}
	$surfaces['suremails'] = array(
		'label'      => 'Email',
		'family'     => 'mail',
		'sub'        => 'SureMails',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/suremails/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/suremails/emails',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'status',
				'static'   => array(
					array( 'sent', 'Sent' ),
					array( 'failed', 'Failed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => 'Subject', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'To', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'created_at', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/suremails/emails/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/suremails/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete this log entry permanently? There is no trash.',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/suremails/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete the selected log entries permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_suremails_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( 'manage_options' );
	};
	$has_table = function () {
		global $wpdb;
		$t = minn_admin_suremails_table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	};

	register_rest_route( 'minn-admin/v1', '/suremails/emails', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $has_table ) {
			if ( ! $has_table() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$table    = minn_admin_suremails_table();
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where  = array( '1=1' );
			$params = array();
			if ( $status ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			}
			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '( subject LIKE %s OR email_from LIKE %s OR email_to LIKE %s )';
				array_push( $params, $like, $like, $like );
			}
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, email_to, subject, status, created_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array_map( function ( $r ) {
				return array(
					'id'         => (int) $r->id,
					'subject'    => (string) $r->subject,
					'to'         => minn_admin_suremails_recipients( $r->email_to ),
					'status'     => (string) $r->status,
					// created_at rides the DB session zone (may be local, not
					// UTC): convert to an absolute ISO-Z so the client never
					// shifts it by gmt_offset.
					'created_at' => minn_admin_db_local_to_utc_iso( $r->created_at ),
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/suremails/emails/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = minn_admin_suremails_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			$delivery = array(
				array( 'label' => 'Status', 'value' => (string) $row->status, 'type' => 'pill' ),
				array( 'label' => 'To', 'value' => minn_admin_suremails_recipients( $row->email_to ) ),
				array( 'label' => 'From', 'value' => (string) $row->email_from ),
			);
			if ( '' !== (string) $row->connection ) {
				$delivery[] = array( 'label' => 'Connection', 'value' => (string) $row->connection );
			}
			$iso = minn_admin_db_local_to_utc_iso( $row->created_at );
			if ( '' !== $iso ) {
				$delivery[] = array( 'label' => 'Date', 'value' => $iso );
			}
			$body     = (string) $row->body;
			$sections = array(
				array( 'title' => 'Delivery', 'rows' => $delivery ),
				array(
					'title' => 'Message',
					'rows'  => array(
						array( 'label' => 'Subject', 'value' => (string) $row->subject ),
						preg_match( '/<\/?[a-z][^>]*>/i', $body )
							? array( 'label' => 'Body', 'value' => $body, 'type' => 'html-preview' )
							: array( 'label' => 'Body', 'value' => $body, 'type' => 'code' ),
					),
				),
			);
			$peek = minn_admin_suremails_response_peek( $row->response );
			if ( '' !== $peek ) {
				$sections[] = array(
					'title' => 'Provider reply',
					'rows'  => array( array( 'label' => 'Response', 'value' => $peek ) ),
				);
			}
			return rest_ensure_response( array( 'sections' => $sections ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/suremails/emails/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = minn_admin_suremails_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", (int) $request['id'] ) );
			if ( ! $deleted ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			return rest_ensure_response( array( 'deleted' => true, 'message' => 'Log entry deleted.' ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/suremails/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () use ( $has_table ) {
			$admin_url = admin_url( 'admin.php?page=suremails' );
			if ( ! $has_table() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => 'Email log', 'value' => '—', 'hint' => 'SureMails has not logged any sends yet' ) ),
					'actions' => array( array( 'label' => 'Open SureMails ↗', 'href' => $admin_url ) ),
				) );
			}
			global $wpdb;
			$table  = minn_admin_suremails_table();
			$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
			$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" ); // phpcs:ignore

			// 14-day sent/failed chart. DATE(created_at) buckets in the DB
			// session zone, so build the day keys in that same zone (DB-now =
			// UTC + offset) — correct whether the DB runs UTC or local.
			$db_now = time() + minn_admin_db_utc_offset();
			$since  = gmdate( 'Y-m-d 00:00:00', $db_now - 13 * DAY_IN_SECONDS );
			$byday  = array();
			for ( $i = 13; $i >= 0; $i-- ) {
				$d           = gmdate( 'Y-m-d', $db_now - $i * DAY_IN_SECONDS );
				$byday[ $d ] = array( 'label' => gmdate( 'M j', strtotime( $d ) ), 'value' => 0, 'secondary' => 0 );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(created_at) AS d, status, COUNT(*) AS c FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at), status",
				$since
			) );
			foreach ( (array) $rows as $r ) {
				$d = (string) $r->d;
				if ( ! isset( $byday[ $d ] ) ) {
					continue;
				}
				if ( 'failed' === $r->status ) {
					$byday[ $d ]['secondary'] = (int) $r->c;
				} else {
					$byday[ $d ]['value'] += (int) $r->c;
				}
			}
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => 'Logged emails',
						'value' => number_format_i18n( $total ),
						'hint'  => $failed ? number_format_i18n( $failed ) . ' failed' : 'All logged sends',
					),
				),
				'chart'   => array(
					'title'     => 'Last 14 days',
					'primary'   => 'Sent',
					'secondary' => 'Failed',
					'points'    => array_values( $byday ),
				),
				'actions' => array( array( 'label' => 'Open SureMails ↗', 'href' => $admin_url ) ),
			) );
		},
	) );
} );
