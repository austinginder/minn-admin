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

/*
 * OttoKit's settings row holds the secret its SaaS signs every call to the
 * site with. Declared whether or not OttoKit is active (its row outlives it).
 */
minn_admin_secrets( 'suretriggers', array( 'options' => array( 'suretrigger_options' ) ) );

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

/** Their table as the kernel names it (their name, without this site's prefix). */
function minn_admin_ottokit_spec() {
	return Minn_Admin_Kernel_SQL::named( minn_admin_ottokit_table() );
}

/**
 * Whether the log table exists. Their logging is a feature that can be off,
 * and the table is only created when their controller installs it, so the
 * surface must not assume it.
 */
function minn_admin_ottokit_has_table() {
	return Minn_Admin_Kernel_SQL::exists( minn_admin_ottokit_spec() );
}

/**
 * The cap OttoKit itself requires. Their menu registration gates on
 * manage_options, so this mirrors it rather than inventing something finer.
 */
function minn_admin_ottokit_cap() {
	return 'manage_options';
}

/**
 * Connection state as OttoKit itself decides it. Their token (encrypted) and
 * the connected email live inside the `suretrigger_options` array. Minn never
 * decrypts or returns the token: `suretriggers_is_user_connected` is the
 * filter their own sibling plugins ask, and it answers only true or false (a
 * token is stored and is not the `connection-denied` marker a refused connect
 * saves). Builds without that filter fall back to whether a token is stored.
 *
 * @return array { connected, email, note }
 */
function minn_admin_ottokit_connection() {
	$opts = get_option( 'suretrigger_options', array() );
	$opts = is_array( $opts ) ? $opts : array();
	if ( has_filter( 'suretriggers_is_user_connected' ) ) {
		$has_key = (bool) apply_filters( 'suretriggers_is_user_connected', false );
	} else {
		$has_key = ! empty( $opts['secret_key'] );
	}
	$email  = isset( $opts['connected_email_key'] ) && is_string( $opts['connected_email_key'] ) ? $opts['connected_email_key'] : '';
	$verify = (string) get_option( 'suretriggers_verify_connection', '' );
	// Their six-hourly ping records one of these on failure: _error when
	// OttoKit answered with anything but 200, _wp_error when the request never
	// got an answer. Their dashboard notice treats both as a broken connection.
	$broken    = in_array( $verify, array( 'suretriggers_connection_error', 'suretriggers_connection_wp_error' ), true );
	$connected = $has_key && ! $broken;
	$note      = '';
	if ( ! $has_key ) {
		$note = __( 'no account is connected yet', 'minn-admin' );
	} elseif ( 'suretriggers_connection_error' === $verify ) {
		$note = __( 'OttoKit reported a connection error', 'minn-admin' );
	} elseif ( 'suretriggers_connection_wp_error' === $verify ) {
		$note = __( 'this site could not reach OttoKit', 'minn-admin' );
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
		'label'      => __( 'Automation', 'minn-admin' ),
		'sub'        => 'OttoKit',
		'plugin'     => 'suretriggers',
		'icon'       => 'activity',
		'cap'        => minn_admin_ottokit_cap(),
		'family'     => 'automation',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/ottokit/status' ),
		'collection' => Minn_Admin_Kernel::collection( 'ottokit/requests', array(
			'viewLabel' => __( 'Outgoing requests', 'minn-admin' ),
			'tabs'      => array(
				'param'    => 'status',
				'static'   => array(
					array( 'failed', 'Failed' ),
					array( 'success', 'Delivered' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'request_url', 'label' => __( 'Endpoint', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'response_code', 'label' => __( 'Code', 'minn-admin' ), 'format' => 'num', 'width' => 80 ),
				array( 'key' => 'created_at', 'label' => __( 'Sent', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/ottokit/requests/{id}/view',
			),
			// Both re-fire the webhook through their retry, and are offered
			// as their own screen offers them: Retry on a request that
			// failed, Rerun on one that was delivered.
			'actions'   => array(
				array(
					'label'   => __( 'Retry', 'minn-admin' ),
					'route'   => 'minn-admin/v1/ottokit/requests/{id}/retry',
					'method'  => 'POST',
					'confirm' => __( 'Send this request to OttoKit again?', 'minn-admin' ),
					'when'    => array( 'key' => 'status', 'equals' => 'failed' ),
				),
				array(
					'label'   => __( 'Rerun', 'minn-admin' ),
					'route'   => 'minn-admin/v1/ottokit/requests/{id}/retry',
					'method'  => 'POST',
					'confirm' => __( 'This request was delivered. Send it to OttoKit again, so its automation runs a second time?', 'minn-admin' ),
					'when'    => array( 'key' => 'status', 'equals' => 'success' ),
				),
			),
			'bulk'      => array(
				array(
					'label'  => __( 'Retry', 'minn-admin' ),
					'route'  => 'minn-admin/v1/ottokit/requests/{id}/retry',
					'method' => 'POST',
					'when'   => array( 'key' => 'status', 'equals' => 'failed' ),
				),
			),
		) ),
	);
	return $surfaces;
} );

/** The detail view's sections: the request, then its payload without headers. */
function minn_admin_ottokit_view( array $row ) {
	$rows = array(
		array( 'label' => __( 'Endpoint', 'minn-admin' ), 'value' => (string) ( $row['request_url'] ?? '' ), 'type' => 'url' ),
		array( 'label' => __( 'Method', 'minn-admin' ), 'value' => strtoupper( (string) ( $row['request_method'] ?? '' ) ) ),
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => (string) ( $row['status'] ?? '' ), 'type' => 'pill' ),
		array( 'label' => __( 'Response code', 'minn-admin' ), 'value' => (string) ( $row['response_code'] ?? '' ) ),
		array( 'label' => __( 'Sent', 'minn-admin' ), 'value' => (string) ( $row['created_at'] ?? '' ) ),
	);
	if ( ! empty( $row['processed_at'] ) ) {
		$rows[] = array( 'label' => __( 'Processed', 'minn-admin' ), 'value' => (string) $row['processed_at'] );
	}
	if ( (int) ( $row['retry_attempts'] ?? 0 ) > 0 ) {
		$rows[] = array( 'label' => __( 'Retries', 'minn-admin' ), 'value' => (string) (int) $row['retry_attempts'] );
	}
	if ( ! empty( $row['error_info'] ) ) {
		$rows[] = array( 'label' => __( 'Error', 'minn-admin' ), 'value' => (string) $row['error_info'] );
	}
	$sections = array( array( 'title' => __( 'Request', 'minn-admin' ), 'rows' => $rows ) );

	// Their payload is JSON; decode only, and show it as escaped code.
	// A retried request is re-persisted WITH its outbound headers,
	// and those carry the SaaS bearer token; their own screen unsets
	// `headers` before rendering, so this one does too.
	$raw = (string) ( $row['request_data'] ?? '' );
	if ( '' !== trim( $raw ) ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			unset( $decoded['headers'] );
		}
		$pretty     = ( null !== $decoded )
			? (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: $raw;
		$sections[] = array(
			'title' => __( 'Payload', 'minn-admin' ),
			'rows'  => array( array( 'label' => __( 'Sent to OttoKit', 'minn-admin' ), 'value' => $pretty, 'type' => 'code' ) ),
		);
	}
	return array( 'sections' => $sections );
}

minn_admin_resource( 'ottokit/requests', function () {
	$fields = array(
		'id'            => 'int',
		'request_url'   => 'text',
		'status'        => 'text',
		'response_code' => 'int',
		'created_at'    => 'text',
	);
	return array(
		'active'  => 'minn_admin_ottokit_active',
		'gates'   => array(
			'view' => function () {
				return current_user_can( minn_admin_ottokit_cap() );
			},
		),
		'labels'  => array( 'not_found' => __( 'That request is gone.', 'minn-admin' ) ),
		'source'  => array(
			'table'   => minn_admin_ottokit_spec(),
			'key'     => 'id',
			'columns' => array( 'id', 'request_url', 'status', 'response_code', 'created_at' ),
			// Every column, as their screen reads the row, so an older schema
			// still opens.
			'detail'  => array(),
		),
		'list'    => array(
			// Their statuses are success and failed; no status is every row.
			'facets' => array( array( 'param' => 'status', 'column' => 'status', 'type' => 'key' ) ),
			'search' => array( 'columns' => array( 'request_url', 'error_info' ) ),
			'order'  => array( 'column' => 'id', 'dir' => 'DESC' ),
			'row'    => 'minn_admin_ottokit_item',
			'fields' => $fields,
		),
		'item'    => array( 'view' => 'minn_admin_ottokit_item', 'fields' => $fields ),
		'reads'   => array( 'view' => array( 'view' => 'minn_admin_ottokit_view', 'fields' => array( 'sections' => 'raw' ) ) ),
		'actions' => array(
			'retry' => array(
				'method' => 'POST',
				'gate'   => 'view',
				// Their own retry: re-fires the webhook, records the response,
				// bumps the attempt count and flips the status, and answers
				// true once it sent. It sends nothing for a payload that does
				// not decode. It can send and then fail to record the answer
				// (a response body longer than their error column), so a send
				// is done whether or not the row moved, and the reply says
				// which: retrying an unrecorded send would run it twice.
				'run'    => function ( $id, array $row ) {
					$sent = \SureTriggers\Controllers\WebhookRequestsController::suretriggers_retry_trigger_request( $id );
					return array( 'sent' => true === $sent, 'before' => (int) ( $row['retry_attempts'] ?? 0 ) );
				},
				'expect' => function ( $id, $fresh, $ran ) {
					return is_array( $fresh ) && is_array( $ran ) && ( $ran['sent'] || (int) ( $fresh['retry_attempts'] ?? 0 ) > $ran['before'] );
				},
				'fail'   => __( 'OttoKit did not resend that request.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh, $ran ) {
					$recorded = (int) ( $fresh['retry_attempts'] ?? 0 ) > (int) ( $ran['before'] ?? 0 );
					$out      = array(
						'status'   => (string) ( $fresh['status'] ?? '' ),
						'code'     => (int) ( $fresh['response_code'] ?? 0 ),
						'recorded' => $recorded,
					);
					if ( ! $recorded ) {
						$out['message'] = __( 'OttoKit sent the request again but could not record the answer, so the log still shows the earlier attempt. Check the automation before sending it again.', 'minn-admin' );
					}
					return $out;
				},
			),
		),
	);
} );

minn_admin_endpoint( 'ottokit/status', array(
	'active' => 'minn_admin_ottokit_active',
	'gate'   => function () {
		return current_user_can( minn_admin_ottokit_cap() );
	},
	'run'    => function () {
		$conn   = minn_admin_ottokit_connection();
		$counts = array( 'failed' => 0, 'total' => 0 );
		if ( minn_admin_ottokit_has_table() ) {
			$counts['total']  = Minn_Admin_Kernel::count( 'ottokit/requests' );
			$counts['failed'] = Minn_Admin_Kernel::count( 'ottokit/requests', array( 'status' => 'failed' ) );
		}
		$rows = array(
			array(
				'label' => __( 'Account', 'minn-admin' ),
				'value' => $conn['connected'] ? ( $conn['email'] ? $conn['email'] : __( 'Connected', 'minn-admin' ) ) : __( 'Not connected', 'minn-admin' ),
				'hint'  => $conn['note'],
			),
			array( 'label' => __( 'Outgoing requests', 'minn-admin' ), 'value' => (string) $counts['total'] ),
			array(
				'label' => __( 'Failed', 'minn-admin' ),
				'value' => (string) $counts['failed'],
				'hint'  => $counts['failed'] ? __( 'retry from the Failed tab', 'minn-admin' ) : '',
			),
		);
		$actions = array();
		if ( current_user_can( 'manage_options' ) ) {
			// Workflows and run history are cloud state with no local
			// copy, so this links to OttoKit's own screens rather than
			// pretending Minn can list them.
			$actions[] = array( 'label' => __( 'Workflows ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=suretriggers' ) );
			$actions[] = array( 'label' => __( 'OttoKit status ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=suretriggers-status' ) );
		}
		return array( 'rows' => $rows, 'actions' => $actions );
	},
) );
