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
 * Deletes are its own log screen's (WPML_Mail::find_one( $id )->delete()).
 *
 * Caps mirror the plugin: manage_options, or the capability its
 * "can see submission data" setting names.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wpml_active() {
	return class_exists( 'No3x\\WPML\\WPML_Init' ) && Minn_Admin_Kernel_SQL::exists( 'wpml_mails' );
}

/**
 * Whether WP Mail Logging is still showing a given field in its own log.
 *
 * The plugin records every message and has no switch for that, but it does
 * withdraw individual fields from its list, its search and its detail view,
 * for rows already stored. No capability check can see a setting, so ask the
 * setting: a site that left the sending server's address hidden has said it
 * does not want it surfaced. Their own default is hidden.
 *
 * @param string $key Their setting key.
 * @return bool
 */
function minn_admin_wpml_displays( $key ) {
	try {
		if ( class_exists( '\No3x\WPML\Admin\SettingsTab' )
			&& method_exists( '\No3x\WPML\Admin\SettingsTab', 'get_settings' ) ) {
			$settings = \No3x\WPML\Admin\SettingsTab::get_settings( array() );
			return ! empty( $settings[ $key ] );
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// A settings-layer change falls back to their own default: hidden.
	}
	return false;
}

function minn_admin_wpml_can() {
	// Their own answer first: administrator ROLE, or the capability named in
	// their "can see submission data" setting. manage_options is only their
	// default for that setting, not the rule itself.
	// Their class is namespaced; testing the bare name never matched, so this
	// branch never ran and the mirrored test below was doing all the work.
	if ( class_exists( '\\No3x\\WPML\\WPML_Utils' )
		&& method_exists( '\\No3x\\WPML\\WPML_Utils', 'can_current_user_access_wp_mail_logging_submissions' ) ) {
		try {
			return (bool) \No3x\WPML\WPML_Utils::can_current_user_access_wp_mail_logging_submissions();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the mirrored test below.
		}
	}
	if ( current_user_can( 'administrator' ) ) {
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

/**
 * Display form of a stored subject. Some senders store RFC 2047 encoded
 * words (=?utf-8?B?…?=); their own SubjectColumn decodes the UTF-8 forms for
 * display, and so does this (any charset, several encoded words). Search
 * still matches the stored value.
 */
function minn_admin_wpml_subject( $subject ) {
	$subject = (string) $subject;
	if ( false === strpos( $subject, '=?' ) ) {
		return $subject;
	}
	$decoded = function_exists( 'mb_decode_mimeheader' ) ? mb_decode_mimeheader( $subject )
		: ( function_exists( 'iconv_mime_decode' ) ? iconv_mime_decode( $subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' ) : $subject );
	return ( is_string( $decoded ) && '' !== $decoded ) ? $decoded : $subject;
}

/** Compact display form of the receiver column (may hold several addresses). */
function minn_admin_wpml_receivers( $receiver ) {
	// Real separators, plus the literal two-character "\n" / "\r\n" some
	// senders store (their ReceiverColumn::normalize collapses those too).
	$parts = preg_split( '/(?:[,\n\r]|\\\\r\\\\n|\\\\n)+/', (string) $receiver );
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

minn_admin_add_surface( 'wp-mail-logging', function () {
	return minn_admin_wpml_active() && minn_admin_wpml_can();
}, function () {
	return minn_admin_mail_surface( 'wpml/emails', array(
		'sub'    => 'WP Mail Logging',
		'plugin' => 'wp-mail-logging',
		// Their lesser-viewer cap is a setting; the active check above is
		// the real gate (the LLA-R / Gravity Forms cap-model precedent).
		'cap'    => 'read',
		'status' => 'wpml/status',
		'date'   => 'timestamp',
		'resend' => true,
	) );
} );

/** The `error` values of a delivered email: none (empty = handed to the mailer). */
const MINN_ADMIN_WPML_SENT = array( null, '' );

/** Status-card model: log totals + 14-day sent/failed chart. */
function minn_admin_wpml_status_model() {
	$failed = array( 'error' => array( 'none' => MINN_ADMIN_WPML_SENT ) );
	return minn_admin_mail_card( 'wpml/emails', array(
		'table'           => 'wpml_mails',
		'missing'         => __( 'WP Mail Logging has not created its table yet', 'minn-admin' ),
		'missing_actions' => array(),
		'failed'          => $failed,
		// timestamp is site-local current_time('mysql').
		'chart'           => array( 'column' => 'timestamp', 'clock' => 'local', 'split' => 'error', 'secondary' => $failed['error'] ),
		'actions'         => array( array( 'label' => __( 'Open WP Mail Logging ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=wpml_plugin_log' ) ) ),
	) );
}

/** Whether a stored email failed: their `error` column holds the reason. */
function minn_admin_wpml_failed( array $r ) {
	return ! ( null === $r['error'] || '' === $r['error'] );
}

/**
 * One email's sections: status pill, sandboxed HTML body, their raw header
 * blob as a code row (a newline string, not pairs: a kv-table would imply
 * structure the store doesn't have).
 */
function minn_admin_wpml_view( array $r ) {
	$failed   = minn_admin_wpml_failed( $r );
	$delivery = array(
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $failed ? 'failed' : 'sent', 'type' => 'pill' ),
		array( 'label' => __( 'To', 'minn-admin' ), 'value' => minn_admin_wpml_receivers( $r['receiver'] ) ),
	);
	$host = '0' === (string) $r['host'] ? '' : (string) $r['host'];
	if ( '' !== $host && minn_admin_wpml_displays( 'display-host' ) ) {
		$delivery[] = array( 'label' => __( 'Host', 'minn-admin' ), 'value' => $host );
	}
	$attachments = trim( (string) $r['attachments'], "0 \n" );
	if ( '' !== $attachments ) {
		$delivery[] = array( 'label' => __( 'Attachments', 'minn-admin' ), 'value' => $attachments );
	}
	$delivery[] = array( 'label' => __( 'Date', 'minn-admin' ), 'value' => (string) $r['timestamp'] );
	$more       = array();
	$headers    = trim( (string) $r['headers'] );
	if ( '' !== $headers ) {
		$more[] = array(
			'title' => __( 'Headers', 'minn-admin' ),
			'rows'  => array( array( 'label' => __( 'Raw headers', 'minn-admin' ), 'value' => $headers, 'type' => 'code' ) ),
		);
	}
	if ( $failed ) {
		$more[] = minn_admin_mail_failure( $r['error'] );
	}
	// Remote images stay blocked unless their own "Always Load Remote
	// Images" setting (1.17+) says otherwise.
	return minn_admin_mail_sections( $delivery, minn_admin_wpml_subject( $r['subject'] ), (string) $r['message'], $more, array( 'remote' => minn_admin_wpml_displays( 'load-remote-images' ) ) );
}

minn_admin_resource( 'wpml/emails', function () {
	return array(
		'active'  => 'minn_admin_wpml_active',
		'gates'   => array(
			'view'  => 'minn_admin_wpml_can',
			// The raw table holds bodies whole, which the browser cannot
			// mask a reset link's key in: only for who reads mail whole.
			'table' => 'minn_admin_mail_bodies_open',
		),
		'labels'  => array( 'not_found' => __( 'Email not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'wpml_mails',
			'key'     => 'mail_id',
			'columns' => array( 'mail_id', 'timestamp', 'receiver', 'subject', 'error' ),
			'detail'  => array( '*' ),
		),
		'list'    => array(
			'facets' => array(
				array( 'param' => 'status', 'values' => array(
					'sent'   => array( 'error' => array( 'any' => MINN_ADMIN_WPML_SENT ) ),
					'failed' => array( 'error' => array( 'none' => MINN_ADMIN_WPML_SENT ) ),
				) ),
			),
			'search' => array( 'columns' => array( 'receiver', 'subject' ) ),
			// Both the bounds and the column are site-local.
			'range'  => array( 'column' => 'timestamp', 'clock' => 'local' ),
			'order'  => array( 'column' => 'mail_id' ),
			'row'    => function ( array $r ) {
				return array(
					'id'        => (int) $r['mail_id'],
					'subject'   => $r['subject'] ? minn_admin_wpml_subject( $r['subject'] ) : __( '(no subject)', 'minn-admin' ),
					'to'        => minn_admin_wpml_receivers( $r['receiver'] ),
					'status'    => minn_admin_wpml_failed( $r ) ? 'failed' : 'sent',
					'timestamp' => $r['timestamp'],
				);
			},
			'fields' => array(
				'id'        => 'int',
				'subject'   => 'text',
				'to'        => 'text',
				'status'    => 'text',
				'timestamp' => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'sql' ),
			),
		),
		// The flat item stays for API consumers; the app reads the sections.
		'item'    => array(
			'view'   => function ( array $r ) {
				return array(
					'id'          => (int) $r['mail_id'],
					'subject'     => minn_admin_wpml_subject( $r['subject'] ),
					'to'          => minn_admin_wpml_receivers( $r['receiver'] ),
					'status'      => minn_admin_wpml_failed( $r ) ? 'failed' : 'sent',
					'error'       => (string) $r['error'],
					'headers'     => trim( (string) $r['headers'] ),
					'attachments' => trim( (string) $r['attachments'], "0 \n" ),
					'host'        => minn_admin_wpml_displays( 'display-host' ) && '0' !== (string) $r['host'] ? (string) $r['host'] : '',
					'timestamp'   => $r['timestamp'],
					'message'     => minn_admin_mail_mask_reset_keys( (string) $r['message'] ),
				);
			},
			'fields' => array(
				'id'          => 'int',
				'subject'     => 'text',
				'to'          => 'text',
				'status'      => 'text',
				'error'       => 'text',
				'headers'     => 'text',
				'attachments' => 'text',
				'host'        => 'text',
				'timestamp'   => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'sql' ),
				'message'     => 'text',
			),
		),
		'reads'   => array( 'view' => array( 'view' => 'minn_admin_wpml_view', 'fields' => array( 'sections' => 'raw' ) ) ),
		'actions' => array(
			// Their own log screen's delete.
			'delete' => minn_admin_mail_delete( function ( $id ) {
				if ( class_exists( '\\No3x\\WPML\\Model\\WPML_Mail' ) ) {
					$mail = \No3x\WPML\Model\WPML_Mail::find_one( $id );
					return $mail ? $mail->delete() : false;
				}
				return Minn_Admin_Kernel_SQL::delete( 'wpml_mails', 'mail_id', $id );
			} ),
			// The plugin's OWN resend pipeline: model + resender out of its
			// DI container, so recipient splitting, header cleaning and
			// attachment path resolution stay its code, not a re-guess.
			'resend' => minn_admin_mail_resend( function ( $id ) {
				try {
					$mail = \No3x\WPML\Model\WPML_Mail::find_one( $id );
					if ( ! $mail ) {
						return new WP_Error( 'not_found', __( 'Email not found', 'minn-admin' ), array( 'status' => 404 ) );
					}
					\No3x\WPML\WPML_Init::getInstance()->getService( 'emailResender' )->resendMail( $mail );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'resend_failed', __( 'WP Mail Logging could not resend: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
				}
				return true;
			}, array( 'done' => __( 'Handed back to the mailer — the new attempt appears as its own log entry.', 'minn-admin' ) ) ),
		),
	);
} );

minn_admin_endpoint( 'wpml/status', array(
	'active' => 'minn_admin_wpml_active',
	'gate'   => 'minn_admin_wpml_can',
	'run'    => 'minn_admin_wpml_status_model',
) );
