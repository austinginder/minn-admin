<?php
/**
 * Bundled adapter: Gravity SMTP.
 *
 * Gravity SMTP keeps its email log in custom tables with no public REST
 * surface, so this adapter is the shim pattern from docs/extension-api.md:
 * a small read-only REST collection over {prefix}gravitysmtp_events, plus a
 * descriptor that renders it. The `extra` column holds serialized PHP objects
 * and is NEVER unserialized — recipients are pulled out with a regex.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_gravity_smtp_active() {
	return defined( 'GF_GRAVITY_SMTP_VERSION' ) || class_exists( 'Gravity_Forms\Gravity_SMTP\Gravity_SMTP' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_gravity_smtp_active() ) {
		return $surfaces;
	}

	$surfaces['gravity-smtp'] = array(
		'label'      => 'Email Log',
		'sub'        => 'Gravity SMTP',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'collection' => array(
			'route'     => 'minn-admin/v1/gravity-smtp/events',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'  => 'status',
				'static' => array(
					array( 'sent', 'Sent' ),
					array( 'failed', 'Failed' ),
					array( 'sandboxed', 'Sandboxed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => 'Subject', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'To', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date_created', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/gravity-smtp/events/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message' ),
			),
			'actions'   => array(
				array(
					'label'   => 'Resend',
					'route'   => 'minn-admin/v1/gravity-smtp/events/{id}/resend',
					'method'  => 'POST',
					'confirm' => 'Resend this email to the original recipients?',
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_gravity_smtp_active() ) {
		return;
	}

	$perm = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/events', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'gravitysmtp_events';
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );

			$where  = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
			$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore
			$rows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, date_created, status, service, subject, extra FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				$per_page,
				( $page - 1 ) * $per_page
			) );

			$items = array_map( function ( $row ) {
				return array(
					'id'           => (int) $row->id,
					'date_created' => $row->date_created,
					'status'       => $row->status,
					'service'      => $row->service,
					'subject'      => $row->subject,
					'to'           => minn_admin_gravity_smtp_recipients( $row->extra ),
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/events/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = $wpdb->prefix . 'gravitysmtp_events';
			$row   = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, date_created, status, service, subject, message, extra FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
			}
			return rest_ensure_response( array(
				'id'           => (int) $row->id,
				'subject'      => $row->subject,
				'to'           => minn_admin_gravity_smtp_recipients( $row->extra ),
				'status'       => $row->status,
				'service'      => $row->service,
				'date_created' => $row->date_created,
				'message'      => $row->message,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/events/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = $wpdb->prefix . 'gravitysmtp_events';
			$row   = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, subject, message, extra FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
			}
			// Recipients are the addresses extracted from `extra` (never unserialized).
			$to = array_filter( array_map( 'trim', explode( ',', preg_replace( '/\s\+\d+$/', '', minn_admin_gravity_smtp_recipients( $row->extra ) ) ) ) );
			if ( ! $to ) {
				return new WP_Error( 'no_recipients', 'No recipient address on record for this email.', array( 'status' => 422 ) );
			}
			$is_html = (bool) preg_match( '/<\/?[a-z][\s\S]*>/i', (string) $row->message );
			$headers = $is_html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();
			$sent    = wp_mail( $to, (string) $row->subject, (string) $row->message, $headers );
			if ( ! $sent ) {
				return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'resent' => true, 'to' => implode( ', ', $to ) ) );
		},
	) );
} );

/**
 * Pull recipient email addresses out of the serialized `extra` blob without
 * unserializing it (PHP object injection would be a vulnerability here).
 */
function minn_admin_gravity_smtp_recipients( $extra ) {
	if ( ! $extra || ! preg_match_all( '/s:5:"email";s:\d+:"([^"]+)"/', $extra, $m ) ) {
		return '';
	}
	$emails = array_values( array_unique( $m[1] ) );
	$out    = implode( ', ', array_slice( $emails, 0, 2 ) );
	if ( count( $emails ) > 2 ) {
		$out .= ' +' . ( count( $emails ) - 2 );
	}
	return $out;
}
