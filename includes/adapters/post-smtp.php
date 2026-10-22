<?php
/**
 * Bundled adapter: Post SMTP.
 *
 * Post SMTP 2.x logs to {prefix}post_smtp_logs (all-longtext columns plus a
 * BIGINT `time` that is current_time('timestamp') — a WP-LOCAL epoch, the
 * same trap as Aryo Activity Log, so it's shifted by gmt_offset before the
 * ISO string is emitted). `success` holds '' / '1' for delivered mail,
 * 'Sent ( ** Fallback ** )' when their backup mailer delivered it, and the
 * error text otherwise. Recipient columns can hold serialized arrays —
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
	if ( ! defined( 'POST_SMTP_VER' ) && ! class_exists( 'PostmanOptions' ) ) {
		return false;
	}
	// Post SMTP withdraws its Email Log by not registering the screen at all
	// when mail logging is switched off, and the rows already stored are not
	// deleted. No capability check can see a setting, so ask the setting: a
	// site that turned logging off has said it does not want these message
	// bodies read, and they hold live password reset links.
	if ( class_exists( 'PostmanOptions' ) && method_exists( 'PostmanOptions', 'getInstance' ) ) {
		try {
			$options = PostmanOptions::getInstance();
			if ( $options && method_exists( $options, 'isMailLoggingEnabled' ) ) {
				return (bool) $options->isMailLoggingEnabled();
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to presence alone when their option layer moves.
		}
	}
	return true;
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

/**
 * WP-local epoch → ISO-8601 UTC. Post SMTP stamps current_time( 'timestamp' ),
 * the site's wall clock read as an epoch, so it is read back in the site's
 * zone at the offset that date had (today's is an hour off for a send from
 * the other side of a DST change).
 */
function minn_admin_post_smtp_iso( $local_epoch ) {
	return ( new DateTimeImmutable( gmdate( 'Y-m-d H:i:s', (int) $local_epoch ), wp_timezone() ) )
		->setTimezone( new DateTimeZone( 'UTC' ) )
		->format( 'Y-m-d\TH:i:s\Z' );
}

/**
 * Post SMTP's marker for a message its backup mailer delivered. Their own log
 * screen and dashboard widget count it as sent; since 4.0.2 each fallback
 * attempt is its own row, so reading it as failed double-counts one rescued
 * email as two failures.
 */
const MINN_ADMIN_POST_SMTP_FALLBACK_SENT = 'Sent ( ** Fallback ** )';

function minn_admin_post_smtp_is_fallback_sent( $success ) {
	return 0 === strpos( (string) $success, MINN_ADMIN_POST_SMTP_FALLBACK_SENT );
}

function minn_admin_post_smtp_status( $success ) {
	$success = (string) $success;
	return ( '' === $success || '1' === $success || minn_admin_post_smtp_is_fallback_sent( $success ) ) ? 'sent' : 'failed';
}

/** The `success` values that read as sent, as a rule: minn_admin_post_smtp_status()'s test. */
function minn_admin_post_smtp_sent_rule() {
	return array( null, '', '1', array( 'starts' => MINN_ADMIN_POST_SMTP_FALLBACK_SENT ) );
}

/** Server-built model for the surface status card. */
function minn_admin_post_smtp_status_model() {
	$failed    = array( 'success' => array( 'none' => minn_admin_post_smtp_sent_rule() ) );
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
	return minn_admin_mail_card( 'post-smtp/emails', array(
		'table'           => 'post_smtp_logs',
		'missing'         => __( 'Post SMTP has not created its log table yet', 'minn-admin' ),
		'missing_actions' => array( array( 'label' => __( 'Open Post SMTP ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=postman_email_log' ) ) ),
		'failed'          => $failed,
		// `time` is a WP-local epoch (Aryo trap): the chart buckets it on that clock.
		'chart'           => array( 'column' => 'time', 'clock' => 'local-epoch', 'split' => 'success', 'secondary' => $failed['success'] ),
		'rows'            => array( array( 'label' => __( 'Transport', 'minn-admin' ), 'value' => $transport ? $transport : '—', 'hint' => __( 'Configured in Post SMTP', 'minn-admin' ) ) ),
		'actions'         => array( array( 'label' => __( 'Open Post SMTP ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=postman' ) ) ),
	) );
}

/**
 * The capability Post SMTP gates its own log screens on.
 *
 * They grant it at activation to the roles that then held activate_plugins,
 * and it can be taken away again from their settings. A generic settings
 * capability is not the same question, and these rows carry message bodies,
 * which routinely contain a live password reset link.
 *
 * @return string
 */
function minn_admin_post_smtp_cap() {
	return class_exists( 'Postman' ) && defined( 'Postman::MANAGE_POSTMAN_CAPABILITY_LOGS' )
		? (string) Postman::MANAGE_POSTMAN_CAPABILITY_LOGS
		: 'manage_postman_logs';
}

/** Whether this user may read Post SMTP's log through Minn. */
function minn_admin_post_smtp_can() {
	return current_user_can( minn_admin_post_smtp_cap() );
}

minn_admin_add_surface( 'post-smtp', 'minn_admin_post_smtp_active', function () {
	return minn_admin_mail_surface( 'post-smtp/emails', array(
		'sub'    => 'Post SMTP',
		'plugin' => 'post-smtp',
		'cap'    => minn_admin_post_smtp_cap(),
		'status' => 'post-smtp/status',
		'date'   => 'date',
		'utc'    => true,
		'resend' => true,
	) );
} );

/** Addresses on record for a resend: never unserialized, each one checked. */
function minn_admin_post_smtp_resend_to( array $r ) {
	if ( ! preg_match_all( '/[\w.+\-]+@[\w.\-]+\.[A-Za-z]{2,}/', (string) ( $r['original_to'] ?: $r['to_header'] ), $m ) ) {
		return array();
	}
	return array_values( array_unique( array_filter( $m[0], 'is_email' ) ) );
}

/** One logged email's sections: status pill, sandboxed HTML body, failure text as a code row. */
function minn_admin_post_smtp_view( array $r ) {
	$status   = minn_admin_post_smtp_status( $r['success'] );
	$delivery = array(
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $status, 'type' => 'pill' ),
		array( 'label' => __( 'To', 'minn-admin' ), 'value' => minn_admin_post_smtp_recipients( $r['original_to'] ?: $r['to_header'] ) ),
		array( 'label' => __( 'From', 'minn-admin' ), 'value' => minn_admin_post_smtp_recipients( $r['from_header'] ) ),
	);
	if ( '' !== (string) $r['transport_uri'] ) {
		$delivery[] = array( 'label' => __( 'Transport', 'minn-admin' ), 'value' => (string) $r['transport_uri'] );
	}
	if ( minn_admin_post_smtp_is_fallback_sent( $r['success'] ) ) {
		$delivery[] = array( 'label' => __( 'Delivered by', 'minn-admin' ), 'value' => __( 'Backup mailer (the primary connection failed)', 'minn-admin' ) );
	}
	$delivery[] = array( 'label' => __( 'Date', 'minn-admin' ), 'value' => minn_admin_post_smtp_iso( $r['time'] ) );
	$fix        = '' !== (string) $r['solution'] ? array( array( 'label' => __( 'Suggested fix', 'minn-admin' ), 'value' => (string) $r['solution'] ) ) : array();
	return minn_admin_mail_sections( $delivery, $r['original_subject'], (string) $r['original_message'], 'failed' === $status ? array( minn_admin_mail_failure( $r['success'], $fix ) ) : array() );
}

minn_admin_resource( 'post-smtp/emails', function () {
	return array(
		'active'  => 'minn_admin_post_smtp_active',
		'gates'   => array( 'view' => 'minn_admin_post_smtp_can' ),
		'labels'  => array( 'not_found' => __( 'Email not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'post_smtp_logs',
			'key'     => 'id',
			'columns' => array( 'id', 'original_subject', 'original_to', 'to_header', 'success', 'time' ),
			'detail'  => array( 'id', 'original_subject', 'original_to', 'to_header', 'from_header', 'original_message', 'success', 'solution', 'transport_uri', 'time' ),
			// Each message's headers and session transcript.
			'tables'  => array( 'post_smtp_logmeta' ),
		),
		'list'    => array(
			'facets' => array(
				array( 'param' => 'status', 'values' => array(
					'sent'   => array( 'success' => array( 'any' => minn_admin_post_smtp_sent_rule() ) ),
					'failed' => array( 'success' => array( 'none' => minn_admin_post_smtp_sent_rule() ) ),
				) ),
			),
			// Subject and recipient columns only (session_transcript can hold SMTP AUTH).
			'search' => array( 'columns' => array( 'original_subject', 'original_to', 'to_header', 'from_header' ) ),
			'range'  => array( 'column' => 'time', 'clock' => 'local-epoch' ),
			'order'  => array( 'column' => 'id' ),
			'row'    => function ( array $r ) {
				return array(
					'id'      => (int) $r['id'],
					'subject' => $r['original_subject'] ? $r['original_subject'] : __( '(no subject)', 'minn-admin' ),
					'to'      => minn_admin_post_smtp_recipients( $r['original_to'] ?: $r['to_header'] ),
					'status'  => minn_admin_post_smtp_status( $r['success'] ),
					'date'    => $r['time'],
				);
			},
			'fields' => array(
				'id'      => 'int',
				'subject' => 'text',
				'to'      => 'text',
				'status'  => 'text',
				'date'    => array( 'type' => 'datetime', 'stored' => 'local-epoch', 'wire' => 'z' ),
			),
		),
		// The flat item stays for API consumers; the app reads the sections.
		'item'    => array(
			'view'   => function ( array $r ) {
				$status = minn_admin_post_smtp_status( $r['success'] );
				return array(
					'id'        => (int) $r['id'],
					'subject'   => $r['original_subject'],
					'to'        => minn_admin_post_smtp_recipients( $r['original_to'] ?: $r['to_header'] ),
					'from'      => minn_admin_post_smtp_recipients( $r['from_header'] ),
					'status'    => $status,
					'error'     => 'failed' === $status ? (string) $r['success'] : '',
					'solution'  => (string) $r['solution'],
					'transport' => (string) $r['transport_uri'],
					'date'      => $r['time'],
					'message'   => minn_admin_mail_mask_reset_keys( (string) $r['original_message'] ),
				);
			},
			'fields' => array(
				'id'        => 'int',
				'subject'   => 'text',
				'to'        => 'text',
				'from'      => 'text',
				'status'    => 'text',
				'error'     => 'text',
				'solution'  => 'text',
				'transport' => 'text',
				'date'      => array( 'type' => 'datetime', 'stored' => 'local-epoch', 'wire' => 'z' ),
				'message'   => 'text',
			),
		),
		'reads'   => array( 'view' => array( 'view' => 'minn_admin_post_smtp_view', 'fields' => array( 'sections' => 'raw' ) ) ),
		'actions' => array(
			// Their own delete_logs(), which takes the entry's logmeta rows
			// with it; the id is an int the kernel loaded.
			'delete' => minn_admin_mail_delete( function ( $id ) {
				if ( class_exists( 'PostmanEmailQueryLog' ) && method_exists( 'PostmanEmailQueryLog', 'delete_logs' ) ) {
					return ( new PostmanEmailQueryLog() )->delete_logs( array( (int) $id ) );
				}
				return Minn_Admin_Kernel_SQL::delete( 'post_smtp_logs', 'id', $id );
			} ),
			// wp_mail with the stored subject and body (the FluentSMTP
			// pattern); their stored headers stay out so nothing unserializes.
			'resend' => minn_admin_mail_resend( function ( $id, array $r ) {
				$to = minn_admin_post_smtp_resend_to( $r );
				if ( ! $to ) {
					return new WP_Error( 'no_recipients', __( 'No recipient address on record for this email.', 'minn-admin' ), array( 'status' => 422 ) );
				}
				return minn_admin_mail_wp_mail( $to, $r['original_subject'], $r['original_message'] );
			} ),
		),
	);
} );

minn_admin_endpoint( 'post-smtp/status', array(
	'active' => 'minn_admin_post_smtp_active',
	'gate'   => 'minn_admin_post_smtp_can',
	'run'    => 'minn_admin_post_smtp_status_model',
) );
