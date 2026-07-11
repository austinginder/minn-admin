<?php
/**
 * Bundled adapter: FluentSMTP.
 *
 * FluentSMTP keeps a full email log in {prefix}fsmpt_email_logs with no
 * public REST surface, so this is the read-only shim pattern (like Gravity
 * SMTP). The `to` and `headers` columns hold serialized arrays and are
 * NEVER unserialized — addresses are pulled out with a regex. `created_at`
 * is current_time('mysql'), a site-LOCAL datetime, so rows are emitted raw
 * (the client parses naked datetimes as site-local).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_fluent_smtp_active() {
	return defined( 'FLUENT_MAIL_DB_PREFIX' ) || defined( 'FLUENTMAIL' );
}

/** Recipient addresses from the serialized `to` column, never unserialized. */
function minn_admin_fluent_smtp_recipients( $to, $all = false ) {
	if ( ! $to ) {
		return $all ? array() : '';
	}
	if ( ! preg_match_all( '/"email";s:\d+:"([^";]+)"/', (string) $to, $m ) ) {
		// A plain address (older rows can store a bare string).
		$m = array( 1 => is_email( $to ) ? array( $to ) : array() );
	}
	$emails = array_values( array_unique( $m[1] ) );
	if ( $all ) {
		return $emails;
	}
	if ( ! $emails ) {
		return '';
	}
	$out = implode( ', ', array_slice( $emails, 0, 2 ) );
	if ( count( $emails ) > 2 ) {
		$out .= ' +' . ( count( $emails ) - 2 );
	}
	return $out;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_fluent_smtp_active() ) {
		return $surfaces;
	}

	$surfaces['fluent-smtp'] = array(
		'label'      => 'Email',
		'sub'        => 'FluentSMTP',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'family'     => 'mail',
		'collection' => array(
			'route'     => 'minn-admin/v1/fluent-smtp/emails',
			'pageQuery' => 'per_page=25&page={page}',
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
				array( 'key' => 'created_at', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/fluent-smtp/emails/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message' ),
			),
			'actions'   => array(
				array(
					'label'   => 'Resend',
					'route'   => 'minn-admin/v1/fluent-smtp/emails/{id}/resend',
					'method'  => 'POST',
					'confirm' => 'Resend this email to the original recipients?',
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_fluent_smtp_active() ) {
		return;
	}

	$perm  = function () {
		return current_user_can( 'manage_options' );
	};
	$table = $GLOBALS['wpdb']->prefix . 'fsmpt_email_logs';

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/emails', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );

			$where = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, `to`, subject, status, source, created_at FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				$per_page,
				( $page - 1 ) * $per_page
			) );

			$items = array_map( function ( $row ) {
				return array(
					'id'         => (int) $row->id,
					'subject'    => $row->subject,
					'to'         => minn_admin_fluent_smtp_recipients( $row->to ),
					'status'     => $row->status,
					'source'     => $row->source,
					'created_at' => $row->created_at,
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/emails/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, `to`, `from`, subject, body, status, response, source, retries, created_at FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			// `response` may be a serialized provider reply — surface only a
			// short plain-text peek, never the raw blob.
			$response = (string) $row->response;
			if ( preg_match( '/"(?:message|code)";s:\d+:"([^"]*)"/', $response, $m ) ) {
				$response = $m[1];
			} elseif ( strlen( $response ) > 200 || preg_match( '/^[aOs]:\d+/', $response ) ) {
				$response = '';
			}
			return rest_ensure_response( array(
				'id'         => (int) $row->id,
				'subject'    => $row->subject,
				'to'         => minn_admin_fluent_smtp_recipients( $row->to ),
				'from'       => $row->from,
				'status'     => $row->status,
				'response'   => $response,
				'source'     => $row->source,
				'retries'    => (int) $row->retries,
				'created_at' => $row->created_at,
				'message'    => $row->body,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/emails/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, `to`, subject, body FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			$to = array_filter( minn_admin_fluent_smtp_recipients( $row->to, true ), 'is_email' );
			if ( ! $to ) {
				return new WP_Error( 'no_recipients', 'No recipient address on record for this email.', array( 'status' => 422 ) );
			}
			$is_html = (bool) preg_match( '/<\/?[a-z][\s\S]*>/i', (string) $row->body );
			$headers = $is_html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();
			$sent    = wp_mail( $to, (string) $row->subject, (string) $row->body, $headers );
			if ( ! $sent ) {
				return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'resent' => true, 'to' => implode( ', ', $to ) ) );
		},
	) );
} );
