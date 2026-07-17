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
		'status'     => array( 'route' => 'minn-admin/v1/wpml/status' ),
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
				// v0.18.0: server-built sections (status pill, sandboxed HTML
				// body, raw headers + error as code rows). Flat route stays.
				'sectionsRoute' => 'minn-admin/v1/wpml/emails/{id}/view',
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
			'bulk'      => array(
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/wpml/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete the selected log entries permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

/** Status-card model: log totals + 14-day sent/failed chart. */
function minn_admin_wpml_status_model() {
	global $wpdb;
	$table = $wpdb->prefix . 'wpml_mails';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return array(
			'rows'    => array( array( 'label' => 'Email log', 'value' => 'Not ready', 'hint' => 'WP Mail Logging has not created its table yet' ) ),
			'actions' => array(),
		);
	}
	$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE error IS NOT NULL AND error != ''" );
	// timestamp is site-local current_time('mysql').
	$since  = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 14 * DAY_IN_SECONDS );
	$days   = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(timestamp) AS d,
			SUM(CASE WHEN error IS NULL OR error = '' THEN 1 ELSE 0 END) AS sent,
			SUM(CASE WHEN error IS NOT NULL AND error != '' THEN 1 ELSE 0 END) AS failed
		 FROM {$table} WHERE timestamp >= %s GROUP BY DATE(timestamp) ORDER BY d ASC",
		$since
	) );
	// phpcs:enable
	$by_day = array();
	for ( $i = 13; $i >= 0; $i-- ) {
		$d            = date_i18n( 'Y-m-d', current_time( 'timestamp' ) - $i * DAY_IN_SECONDS );
		$by_day[ $d ] = array( 'label' => $d, 'value' => 0, 'secondary' => 0 );
	}
	foreach ( (array) $days as $row ) {
		$d = (string) $row->d;
		if ( ! isset( $by_day[ $d ] ) ) {
			continue;
		}
		$by_day[ $d ]['value']     = (int) $row->sent;
		$by_day[ $d ]['secondary'] = (int) $row->failed;
	}
	return array(
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
			'points'    => array_values( $by_day ),
		),
		'actions' => array(
			array( 'label' => 'Open WP Mail Logging ↗', 'href' => admin_url( 'admin.php?page=wpml_plugin_log' ) ),
		),
	);
}

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

	// Sections view (v0.18.0 row types): status pill, sandboxed HTML body,
	// their raw header blob as a code row (a newline string, not pairs — a
	// kv-table would imply structure the store doesn't have). Flat
	// /emails/{id} stays for API consumers.
	register_rest_route( 'minn-admin/v1', '/wpml/emails/(?P<id>\d+)/view', array(
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
			$failed   = ! ( null === $row->error || '' === $row->error );
			$delivery = array(
				array( 'label' => 'Status', 'value' => $failed ? 'failed' : 'sent', 'type' => 'pill' ),
				array( 'label' => 'To', 'value' => minn_admin_wpml_receivers( $row->receiver ) ),
			);
			$host = '0' === (string) $row->host ? '' : (string) $row->host;
			if ( '' !== $host ) {
				$delivery[] = array( 'label' => 'Host', 'value' => $host );
			}
			$attachments = trim( (string) $row->attachments, "0 \n" );
			if ( '' !== $attachments ) {
				$delivery[] = array( 'label' => 'Attachments', 'value' => $attachments );
			}
			$delivery[] = array( 'label' => 'Date', 'value' => (string) $row->timestamp );
			$body     = (string) $row->message;
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
			$headers = trim( (string) $row->headers );
			if ( '' !== $headers ) {
				$sections[] = array(
					'title' => 'Headers',
					'rows'  => array( array( 'label' => 'Raw headers', 'value' => $headers, 'type' => 'code' ) ),
				);
			}
			if ( $failed ) {
				$sections[] = array(
					'title' => 'Failure',
					'rows'  => array( array( 'label' => 'Error', 'value' => (string) $row->error, 'type' => 'code' ) ),
				);
			}
			return rest_ensure_response( array( 'sections' => $sections ) );
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
				return rest_ensure_response( array( 'deleted' => true, 'message' => 'Log entry deleted.' ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/wpml/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_wpml_can',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_wpml_status_model() );
		},
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
