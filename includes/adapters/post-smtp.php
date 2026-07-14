<?php
/**
 * Bundled adapter: Post SMTP.
 *
 * Post SMTP 2.x logs to {prefix}post_smtp_logs (all-longtext columns plus a
 * BIGINT `time` that is current_time('timestamp') — a WP-LOCAL epoch, the
 * same trap as Aryo Activity Log, so it's shifted by gmt_offset before the
 * ISO string is emitted). `success` holds '' / '1' for delivered mail and
 * the error text otherwise. Recipient columns can hold serialized arrays —
 * addresses are pulled with a regex, never unserialized. Resend uses wp_mail
 * with the stored subject/body (same pattern as FluentSMTP); original headers
 * stay out of the path so nothing is unserialized. Search + single/bulk
 * delete (prefix-scoped DELETE) match WP Mail Logging / FluentSMTP parity.
 *
 * // last-sweep: 2026-07-14
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_post_smtp_active() {
	return defined( 'POST_SMTP_VER' ) || class_exists( 'PostmanOptions' );
}

/** Email addresses out of a maybe-serialized recipient blob. */
function minn_admin_post_smtp_recipients( $raw ) {
	if ( ! $raw ) {
		return '';
	}
	if ( ! preg_match_all( '/[\w.+\-]+@[\w.\-]+\.[A-Za-z]{2,}/', (string) $raw, $m ) ) {
		return '';
	}
	$emails = array_values( array_unique( $m[0] ) );
	$out    = implode( ', ', array_slice( $emails, 0, 2 ) );
	if ( count( $emails ) > 2 ) {
		$out .= ' +' . ( count( $emails ) - 2 );
	}
	return $out;
}

/** WP-local epoch → ISO-8601 UTC (rule: hist-time columns store local). */
function minn_admin_post_smtp_iso( $local_epoch ) {
	$offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
	return gmdate( 'Y-m-d\TH:i:s\Z', (int) $local_epoch - (int) $offset );
}

function minn_admin_post_smtp_status( $success ) {
	return ( '' === (string) $success || '1' === (string) $success ) ? 'sent' : 'failed';
}

/** Server-built model for the surface status card. */
function minn_admin_post_smtp_status_model() {
	global $wpdb;
	$table = $wpdb->prefix . 'post_smtp_logs';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return array(
			'rows'    => array( array( 'label' => 'Email log', 'value' => 'Not ready', 'hint' => 'Post SMTP has not created its log table yet' ) ),
			'actions' => array(
				array( 'label' => 'Open Post SMTP ↗', 'href' => admin_url( 'admin.php?page=postman_email_log' ) ),
			),
		);
	}
	$sent_sql = "(success IS NULL OR success = '' OR success = '1')";
	$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$failed   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE NOT {$sent_sql}" );
	// `time` is a WP-local epoch (Aryo trap) — compare and bucket the same way.
	$since_local = current_time( 'timestamp' ) - 14 * DAY_IN_SECONDS;
	$rows_raw    = $wpdb->get_results( $wpdb->prepare(
		"SELECT time, success FROM {$table} WHERE time >= %d ORDER BY time ASC",
		$since_local
	) );
	// phpcs:enable
	$by_day = array();
	for ( $i = 13; $i >= 0; $i-- ) {
		$d            = date_i18n( 'Y-m-d', current_time( 'timestamp' ) - $i * DAY_IN_SECONDS );
		$by_day[ $d ] = array( 'label' => $d, 'value' => 0, 'secondary' => 0 );
	}
	foreach ( (array) $rows_raw as $row ) {
		// date_i18n over a WP-local epoch matches how they stamped the row.
		$d = date_i18n( 'Y-m-d', (int) $row->time );
		if ( ! isset( $by_day[ $d ] ) ) {
			continue;
		}
		if ( 'sent' === minn_admin_post_smtp_status( $row->success ) ) {
			$by_day[ $d ]['value']++;
		} else {
			$by_day[ $d ]['secondary']++;
		}
	}
	$transport = '';
	if ( class_exists( 'PostmanOptions' ) ) {
		try {
			$opts = PostmanOptions::getInstance();
			if ( $opts && method_exists( $opts, 'getTransportType' ) ) {
				$transport = (string) $opts->getTransportType();
			}
		} catch ( \Throwable $e ) {
			$transport = '';
		}
	}

	return array(
		'rows'    => array(
			array(
				'label' => 'Logged emails',
				'value' => number_format_i18n( $total ),
				'hint'  => $failed ? number_format_i18n( $failed ) . ' failed' : 'All logged sends',
			),
			array(
				'label' => 'Transport',
				'value' => $transport ? $transport : '—',
				'hint'  => 'Configured in Post SMTP',
			),
		),
		'chart'   => array(
			'title'     => 'Last 14 days',
			'primary'   => 'Sent',
			'secondary' => 'Failed',
			'points'    => array_values( $by_day ),
		),
		'actions' => array(
			array(
				'label' => 'Open Post SMTP ↗',
				'href'  => admin_url( 'admin.php?page=postman' ),
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_post_smtp_active() ) {
		return $surfaces;
	}

	$surfaces['post-smtp'] = array(
		'label'      => 'Email',
		'sub'        => 'Post SMTP',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'family'     => 'mail',
		'status'     => array( 'route' => 'minn-admin/v1/post-smtp/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/post-smtp/emails',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'  => 'status',
				'static' => array(
					array( 'sent', 'Sent' ),
					array( 'failed', 'Failed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => 'Subject', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'To', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'Date', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/post-smtp/emails/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message' ),
			),
			'actions'   => array(
				array(
					'label'   => 'Resend',
					'route'   => 'minn-admin/v1/post-smtp/emails/{id}/resend',
					'method'  => 'POST',
					'confirm' => 'Resend this email to the original recipients?',
				),
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/post-smtp/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete this log entry permanently? There is no trash.',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/post-smtp/emails/{id}',
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
	if ( ! minn_admin_post_smtp_active() ) {
		return;
	}

	$perm  = function () {
		return current_user_can( 'manage_options' );
	};
	$table = $GLOBALS['wpdb']->prefix . 'post_smtp_logs';

	register_rest_route( 'minn-admin/v1', '/post-smtp/emails', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );

			$sent_sql = "(success IS NULL OR success = '' OR success = '1')";
			$where    = '1=1';
			$args     = array();
			if ( 'sent' === $status ) {
				$where = $sent_sql;
			} elseif ( 'failed' === $status ) {
				$where = "NOT {$sent_sql}";
			}
			if ( $request['search'] ) {
				// Subject + recipient columns only (session_transcript can hold SMTP AUTH).
				$like   = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
				$where .= ' AND (original_subject LIKE %s OR original_to LIKE %s OR to_header LIKE %s OR from_header LIKE %s)';
				$args[] = $like;
				$args[] = $like;
				$args[] = $like;
				$args[] = $like;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table prefix-derived; WHERE placeholder-built.
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, original_subject, original_to, to_header, success, time FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$items = array_map( function ( $row ) {
				return array(
					'id'      => (int) $row->id,
					'subject' => $row->original_subject ? $row->original_subject : '(no subject)',
					'to'      => minn_admin_post_smtp_recipients( $row->original_to ?: $row->to_header ),
					'status'  => minn_admin_post_smtp_status( $row->success ),
					'date'    => minn_admin_post_smtp_iso( $row->time ),
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/post-smtp/emails/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, original_subject, original_to, to_header, from_header, original_message, success, solution, transport_uri, time FROM {$table} WHERE id = %d", // phpcs:ignore
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
				}
				$status = minn_admin_post_smtp_status( $row->success );
				return rest_ensure_response( array(
					'id'        => (int) $row->id,
					'subject'   => $row->original_subject,
					'to'        => minn_admin_post_smtp_recipients( $row->original_to ?: $row->to_header ),
					'from'      => minn_admin_post_smtp_recipients( $row->from_header ),
					'status'    => $status,
					'error'     => 'failed' === $status ? (string) $row->success : '',
					'solution'  => (string) $row->solution,
					'transport' => (string) $row->transport_uri,
					'date'      => minn_admin_post_smtp_iso( $row->time ),
					'message'   => $row->original_message,
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				// Permanent delete by id (same shape as WP Mail Logging / FluentSMTP).
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$table} WHERE id = %d",
					(int) $request['id']
				) );
				if ( ! $deleted ) {
					return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
				}
				return rest_ensure_response( array( 'deleted' => true, 'message' => 'Log entry deleted.' ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/post-smtp/emails/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, original_subject, original_to, to_header, original_message FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			// Addresses only — never unserialize their recipient blobs.
			if ( ! preg_match_all( '/[\w.+\-]+@[\w.\-]+\.[A-Za-z]{2,}/', (string) ( $row->original_to ?: $row->to_header ), $m ) ) {
				return new WP_Error( 'no_recipients', 'No recipient address on record for this email.', array( 'status' => 422 ) );
			}
			$to      = array_values( array_unique( array_filter( $m[0], 'is_email' ) ) );
			$is_html = (bool) preg_match( '/<\/?[a-z][\s\S]*>/i', (string) $row->original_message );
			$headers = $is_html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();
			$sent    = wp_mail( $to, (string) $row->original_subject, (string) $row->original_message, $headers );
			if ( ! $sent ) {
				return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'resent'  => true,
				'to'      => implode( ', ', $to ),
				'message' => 'Resent to ' . implode( ', ', $to ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/post-smtp/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_post_smtp_status_model() );
		},
	) );
} );
