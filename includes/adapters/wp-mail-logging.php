<?php
/**
 * Bundled adapter: WP Mail Logging.
 *
 * WP Mail Logging records every wp_mail() into {prefix}wpml_mails with no
 * REST surface — the classic read-only shim (the FluentSMTP shape). Facts
 * the code hangs on: `timestamp` is current_time('mysql'), a site-LOCAL
 * datetime, so rows are emitted raw (the client parses naked datetimes as
 * site-local); sent-vs-failed is the `error` column (empty = delivered to
 * the mailer); `receiver` can hold several comma/newline-separated
 * addresses. Resend goes through the plugin's OWN resender service (its
 * DI container), so attachment paths and header cleaning stay its logic.
 * Deletes mirror its own log screen (a prefix-scoped DELETE by id).
 *
 * Caps mirror the plugin: manage_options, or the capability its
 * "can see submission data" setting names.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wpml_active() {
	global $wpdb;
	if ( ! class_exists( 'No3x\\WPML\\WPML_Init' ) ) {
		return false;
	}
	$table = $wpdb->prefix . 'wpml_mails';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

function minn_admin_wpml_can() {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	// Their own gate: a role-derived capability from the
	// "can-see-submission-data" setting (default manage_options).
	try {
		if ( class_exists( 'No3x\\WPML\\Admin\\SettingsTab' ) ) {
			$settings = \No3x\WPML\Admin\SettingsTab::get_settings( array() );
			if ( ! empty( $settings['can-see-submission-data'] ) ) {
				return current_user_can( (string) $settings['can-see-submission-data'] );
			}
		}
	} catch ( \Throwable $e ) {
		// A settings-layer change just falls back to admins-only.
	}
	return false;
}

/** Compact display form of the receiver column (may hold several addresses). */
function minn_admin_wpml_receivers( $receiver ) {
	$parts = preg_split( '/[,\n\r]+/', (string) $receiver );
	$parts = array_values( array_filter( array_map( 'trim', (array) $parts ) ) );
	if ( ! $parts ) {
		return '—';
	}
	$out = implode( ', ', array_slice( $parts, 0, 2 ) );
	if ( count( $parts ) > 2 ) {
		$out .= ' +' . ( count( $parts ) - 2 );
	}
	return $out;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wpml_active() || ! minn_admin_wpml_can() ) {
		return $surfaces;
	}

	$surfaces['wp-mail-logging'] = array(
		'label'      => 'Email',
		'sub'        => 'WP Mail Logging',
		'icon'       => 'send',
		'family'     => 'mail',
		// Their lesser-viewer cap is a setting; the filter above is the
		// real gate (the LLA-R / Gravity Forms cap-model precedent).
		'cap'        => 'read',
		'collection' => array(
			'route'     => 'minn-admin/v1/wpml/emails',
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
				array( 'key' => 'timestamp', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/wpml/emails/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message' ),
			),
			'actions'   => array(
				array(
					'label'   => 'Resend',
					'route'   => 'minn-admin/v1/wpml/emails/{id}/resend',
					'method'  => 'POST',
					'confirm' => 'Resend this email to the original recipients?',
				),
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/wpml/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete this log entry permanently? There is no trash.',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpml_active() ) {
		return;
	}

	$table = $GLOBALS['wpdb']->prefix . 'wpml_mails';

	register_rest_route( 'minn-admin/v1', '/wpml/emails', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wpml_can',
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );

			$where = '1=1';
			$args  = array();
			if ( 'sent' === $status ) {
				$where = "(error IS NULL OR error = '')";
			} elseif ( 'failed' === $status ) {
				$where = "error IS NOT NULL AND error != ''";
			}
			if ( $request['search'] ) {
				$like   = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
				$where .= ' AND (receiver LIKE %s OR subject LIKE %s)';
				$args[] = $like;
				$args[] = $like;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table prefix-derived; WHERE placeholder-built.
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $args ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT mail_id, timestamp, receiver, subject, error FROM {$table} WHERE {$where} ORDER BY mail_id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$items = array_map( function ( $row ) {
				return array(
					'id'        => (int) $row->mail_id,
					'subject'   => $row->subject ? $row->subject : '(no subject)',
					'to'        => minn_admin_wpml_receivers( $row->receiver ),
					'status'    => ( null === $row->error || '' === $row->error ) ? 'sent' : 'failed',
					'timestamp' => $row->timestamp,
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wpml/emails/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_wpml_can',
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$table} WHERE mail_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
				}
				return rest_ensure_response( array(
					'id'          => (int) $row->mail_id,
					'subject'     => $row->subject,
					'to'          => minn_admin_wpml_receivers( $row->receiver ),
					'status'      => ( null === $row->error || '' === $row->error ) ? 'sent' : 'failed',
					'error'       => (string) $row->error,
					'headers'     => trim( (string) $row->headers ),
					'attachments' => trim( (string) $row->attachments, "0 \n" ),
					'host'        => '0' === (string) $row->host ? '' : (string) $row->host,
					'timestamp'   => $row->timestamp,
					'message'     => (string) $row->message,
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => 'minn_admin_wpml_can',
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				// Mirrors the plugin's own log-screen delete: permanent, by id.
				$deleted = $wpdb->query( $wpdb->prepare(
					"DELETE FROM {$table} WHERE mail_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					(int) $request['id']
				) );
				if ( ! $deleted ) {
					return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
				}
				return rest_ensure_response( array( 'deleted' => true ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/wpml/emails/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_wpml_can',
		'callback'            => function ( WP_REST_Request $request ) {
			// The plugin's OWN resend pipeline: model + resender out of its
			// DI container, so recipient splitting, header cleaning and
			// attachment path resolution stay its code, not a re-guess.
			try {
				$mail = \No3x\WPML\Model\WPML_Mail::find_one( (int) $request['id'] );
				if ( ! $mail ) {
					return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
				}
				\No3x\WPML\WPML_Init::getInstance()->getService( 'emailResender' )->resendMail( $mail );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'resend_failed', 'WP Mail Logging could not resend: ' . $e->getMessage(), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'resent'  => true,
				'message' => 'Handed back to the mailer — the new attempt appears as its own log entry.',
			) );
		},
	) );
} );
