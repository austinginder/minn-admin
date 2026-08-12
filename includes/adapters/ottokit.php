<?php
/**
 * Bundled adapter: OttoKit (formerly SureTriggers, Brainstorm Force).
 *
 * OttoKit is a CONNECTOR, and the scope here follows from that. The
 * automations themselves — workflows, recipes, run history, apps — live in
 * OttoKit's cloud and are reached through an embedded app under their own
 * menu. Their REST routes are machine-to-machine: authenticated by a shared
 * secret their cloud holds (`st_authorization: Bearer …`), not by the
 * logged-in user, so Minn never calls them and never touches that secret.
 *
 * What IS local is the outgoing-request log, {prefix}suretriggers_webhook_requests:
 * every webhook this site fired at OttoKit, with the response code, an error
 * string and a retry count. That is the operational half — the part you go
 * looking for when an automation did not run — so that is what this surface
 * lists, with retry through their OWN WebhookRequestsController so the retry
 * accounting, response capture and status flip stay theirs.
 *
 * `created_at` is current_time('mysql'), a site-LOCAL datetime, so rows are
 * emitted raw and the client reads them as site-local. `request_data` is
 * JSON, decoded with json_decode only. Statuses are theirs: success, failed.
 *
 * Deliberately NOT built: workflow listing or editing, and run history.
 * Both are cloud state with no local copy, so the status card link-out to
 * their own screens is the honest answer rather than a half-mirror.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Whether OttoKit is loaded. */
function minn_admin_ottokit_active() {
	return defined( 'SURE_TRIGGERS_FILE' )
		&& class_exists( '\SureTriggers\Controllers\WebhookRequestsController' );
}

/** Their table name, straight from their controller. */
function minn_admin_ottokit_table() {
	global $wpdb;
	if ( method_exists( '\SureTriggers\Controllers\WebhookRequestsController', 'get_table_name' ) ) {
		try {
			return (string) \SureTriggers\Controllers\WebhookRequestsController::get_table_name();
		} catch ( \Throwable $e ) {
			// fall through to the documented name
		}
	}
	return $wpdb->prefix . 'suretriggers_webhook_requests';
}

/**
 * Whether the log table exists. Their logging is a feature that can be off,
 * and the table is only created when their controller installs it, so the
 * surface must not assume it.
 */
function minn_admin_ottokit_has_table() {
	static $has = null;
	if ( null !== $has ) {
		return $has;
	}
	global $wpdb;
	$table = minn_admin_ottokit_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
	$has = ! empty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	return $has;
}

/**
 * The cap OttoKit itself requires. Their menu registration gates on
 * manage_options, so this mirrors it rather than inventing something finer.
 */
function minn_admin_ottokit_cap() {
	return 'manage_options';
}

/**
 * Connection state from their own options. The secret key is never read for
 * its value — only for whether one exists.
 *
 * @return array { connected, email, note }
 */
function minn_admin_ottokit_connection() {
	$verify = (string) get_option( 'suretriggers_verify_connection', '' );
	$email  = (string) get_option( 'suretriggers_connected_email', '' );
	$has_key = '' !== (string) get_option( 'suretriggers_secret_key', '' );
	$connected = $has_key && 'suretriggers_connection_error' !== $verify;
	$note = '';
	if ( ! $has_key ) {
		$note = 'no account is connected yet';
	} elseif ( 'suretriggers_connection_error' === $verify ) {
		$note = 'OttoKit reported a connection error';
	}
	return array(
		'connected' => $connected,
		'email'     => $email,
		'note'      => $note,
	);
}

/** One list row. */
function minn_admin_ottokit_item( $row ) {
	$url = (string) ( $row['request_url'] ?? '' );
	return array(
		'id'            => (int) ( $row['id'] ?? 0 ),
		'request_url'   => $url,
		'status'        => (string) ( $row['status'] ?? '' ),
		'response_code' => (int) ( $row['response_code'] ?? 0 ),
		// Site-local datetime, emitted raw on purpose.
		'created_at'    => (string) ( $row['created_at'] ?? '' ),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_ottokit_active() || ! minn_admin_ottokit_has_table() ) {
		return $surfaces;
	}

	$surfaces['ottokit'] = array(
		'label'      => 'Automation',
		'sub'        => 'OttoKit',
		'icon'       => 'activity',
		'cap'        => minn_admin_ottokit_cap(),
		'family'     => 'automation',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/ottokit/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/ottokit/requests',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'viewLabel' => 'Outgoing requests',
			'tabs'      => array(
				'param'    => 'status',
				'static'   => array(
					array( 'failed', 'Failed' ),
					array( 'success', 'Delivered' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'request_url', 'label' => 'Endpoint', 'format' => 'title' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'response_code', 'label' => 'Code', 'format' => 'text', 'num' => true, 'width' => 80 ),
				array( 'key' => 'created_at', 'label' => 'Sent', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/ottokit/requests/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => 'Retry',
					'route'   => 'minn-admin/v1/ottokit/requests/{id}/retry',
					'method'  => 'POST',
					'confirm' => 'Send this request to OttoKit again?',
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Retry',
					'route'  => 'minn-admin/v1/ottokit/requests/{id}/retry',
					'method' => 'POST',
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ottokit_active() ) {
		return;
	}
	$can = function () {
		return current_user_can( minn_admin_ottokit_cap() );
	};

	register_rest_route( 'minn-admin/v1', '/ottokit/requests', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) {
			global $wpdb;
			if ( ! minn_admin_ottokit_has_table() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$table    = minn_admin_ottokit_table();
			$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
			$offset   = ( $page - 1 ) * $per_page;

			$where  = array( '1=1' );
			$params = array();
			$status = sanitize_text_field( (string) $req->get_param( 'status' ) );
			if ( '' !== $status ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			}
			$search = trim( (string) $req->get_param( 'search' ) );
			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '( request_url LIKE %s OR error_info LIKE %s )';
				$params[] = $like;
				$params[] = $like;
			}
			$clause = implode( ' AND ', $where );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-fixed, clause is built from placeholders only.
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
			$total     = (int) ( $params
				? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
				: $wpdb->get_var( $count_sql ) );

			$rows_sql = "SELECT id, request_url, status, response_code, created_at FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
			$rows     = (array) $wpdb->get_results(
				$wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ),
				ARRAY_A
			);
			// phpcs:enable

			return rest_ensure_response( array(
				'items' => array_map( 'minn_admin_ottokit_item', $rows ),
				'total' => $total,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ottokit/requests/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) {
			global $wpdb;
			$table = minn_admin_ottokit_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-fixed.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $req['id'] ), ARRAY_A );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'That request is gone.', array( 'status' => 404 ) );
			}
			$rows = array(
				array( 'label' => 'Endpoint', 'value' => (string) ( $row['request_url'] ?? '' ), 'type' => 'url' ),
				array( 'label' => 'Method', 'value' => strtoupper( (string) ( $row['request_method'] ?? '' ) ) ),
				array( 'label' => 'Status', 'value' => (string) ( $row['status'] ?? '' ), 'type' => 'pill' ),
				array( 'label' => 'Response code', 'value' => (string) ( $row['response_code'] ?? '' ) ),
				array( 'label' => 'Sent', 'value' => (string) ( $row['created_at'] ?? '' ) ),
			);
			if ( ! empty( $row['processed_at'] ) ) {
				$rows[] = array( 'label' => 'Processed', 'value' => (string) $row['processed_at'] );
			}
			if ( (int) ( $row['retry_attempts'] ?? 0 ) > 0 ) {
				$rows[] = array( 'label' => 'Retries', 'value' => (string) (int) $row['retry_attempts'] );
			}
			if ( ! empty( $row['error_info'] ) ) {
				$rows[] = array( 'label' => 'Error', 'value' => (string) $row['error_info'] );
			}
			$sections = array( array( 'title' => 'Request', 'rows' => $rows ) );

			// Their payload is JSON; decode only, and show it as escaped code.
			$raw = (string) ( $row['request_data'] ?? '' );
			if ( '' !== trim( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$pretty  = ( null !== $decoded )
					? (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
					: $raw;
				$sections[] = array(
					'title' => 'Payload',
					'rows'  => array( array( 'label' => 'Sent to OttoKit', 'value' => $pretty, 'type' => 'code' ) ),
				);
			}
			return rest_ensure_response( array( 'sections' => $sections ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ottokit/requests/(?P<id>\d+)/retry', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( WP_REST_Request $req ) {
			global $wpdb;
			$id    = (int) $req['id'];
			$table = minn_admin_ottokit_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-fixed.
			$before = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, retry_attempts FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			if ( ! $before ) {
				return new WP_Error( 'not_found', 'That request is gone.', array( 'status' => 404 ) );
			}
			try {
				// Their own retry: re-fires the webhook, records the response,
				// bumps the attempt count and flips the status.
				\SureTriggers\Controllers\WebhookRequestsController::suretriggers_retry_trigger_request( $id );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'retry_failed', 'OttoKit could not resend that request.', array( 'status' => 500 ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-fixed.
			$after = $wpdb->get_row( $wpdb->prepare( "SELECT status, response_code FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			return rest_ensure_response( array(
				'ok'     => true,
				'status' => (string) ( $after['status'] ?? '' ),
				'code'   => (int) ( $after['response_code'] ?? 0 ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ottokit/status', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function () {
			global $wpdb;
			$conn  = minn_admin_ottokit_connection();
			$table = minn_admin_ottokit_table();
			$counts = array( 'failed' => 0, 'success' => 0, 'total' => 0 );
			if ( minn_admin_ottokit_has_table() ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is prefix-fixed.
				$counts['total']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
				$counts['failed']  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'failed' ) );
				$counts['success'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'success' ) );
				// phpcs:enable
			}

			$rows = array(
				array(
					'label' => 'Account',
					'value' => $conn['connected'] ? ( $conn['email'] ? $conn['email'] : 'Connected' ) : 'Not connected',
					'hint'  => $conn['note'],
				),
				array( 'label' => 'Outgoing requests', 'value' => (string) $counts['total'] ),
				array(
					'label' => 'Failed',
					'value' => (string) $counts['failed'],
					'hint'  => $counts['failed'] ? 'retry from the Failed tab' : '',
				),
			);

			$actions = array();
			if ( current_user_can( 'manage_options' ) ) {
				// Workflows and run history are cloud state with no local
				// copy, so this links to OttoKit's own screens rather than
				// pretending Minn can list them.
				$actions[] = array( 'label' => 'Workflows ↗', 'href' => admin_url( 'admin.php?page=suretriggers' ) );
				$actions[] = array( 'label' => 'OttoKit status ↗', 'href' => admin_url( 'admin.php?page=suretriggers-status' ) );
			}
			return rest_ensure_response( array( 'rows' => $rows, 'actions' => $actions ) );
		},
	) );
} );
