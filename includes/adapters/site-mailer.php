<?php
/**
 * Bundled adapter: Site Mailer (Wave B).
 *
 * Site Mailer (by Elementor) sends over its own cloud API and logs every send
 * into {prefix}site_mail_logs (free feature). No log REST for third parties,
 * so this is the shim pattern: prefix-scoped SELECTs, delivered/failed tabs,
 * search, single + bulk delete, a status card, and a sections detail (status
 * pill, sandboxed HTML body). Delivery is the cloud's job, so there is no
 * local resend; the card links out to Site Mailer's own log for that. Its
 * `created_at` is written on the site's clock (current_time), converted to
 * UTC by the shared minn_admin_db_local_to_utc_iso() helper.
 *
 * last-sweep: 2026-07-17
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_site_mailer_active() {
	return defined( 'SITE_MAILER_VERSION' );
}

/**
 * Whether Site Mailer is still showing message content in its own log.
 *
 * Their switch is called "Show email content in log" and it is off by
 * default. It withdraws the message from their list, their search and their
 * detail view, for rows already recorded, and nothing deletes the bodies that
 * were stored while it was on. No capability check can see a setting, so ask
 * the setting: a site that turned email content off has said it does not want
 * these bodies read, and their own help text says why. Password reset links
 * live in exactly these messages.
 *
 * @return bool
 */
function minn_admin_site_mailer_shows_content() {
	if ( class_exists( '\SiteMailer\Modules\Settings\Classes\Settings' ) ) {
		try {
			return (bool) \SiteMailer\Modules\Settings\Classes\Settings::get(
				\SiteMailer\Modules\Settings\Classes\Settings::KEEP_LOG
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the option when their settings layer moves.
		}
	}
	return (bool) get_option( 'site_mailer_keep_log' );
}

function minn_admin_site_mailer_table() {
	global $wpdb;
	return $wpdb->prefix . 'site_mail_logs';
}

/**
 * Site Mailer's status vocabulary → the shared pill classes. Its own tallies
 * treat delivered as good and failed/bounce/dropped as bad (log-entry.php);
 * the remaining states (not sent / rate limit / not valid / unsubscribed) are
 * neutral.
 */
function minn_admin_site_mailer_status( $status ) {
	$s = strtolower( trim( (string) $status ) );
	if ( in_array( $s, array( 'delivered', 'sent' ), true ) ) {
		return 'sent';
	}
	if ( in_array( $s, array( 'failed', 'bounce', 'dropped' ), true ) ) {
		return 'failed';
	}
	return $s ?: 'pending';
}

/**
 * The `to` column as a readable list. Site Mailer stores what wp_mail() got
 * through wp_json_encode(): a quoted string, or an array of addresses. Any
 * other value, or an array with no address strings in it (a filter's shape,
 * never wp_mail's), passes through as stored. Plain text: the client escapes it.
 */
function minn_admin_site_mailer_recipients( $raw ) {
	$raw     = (string) $raw;
	$decoded = json_decode( $raw, true );
	if ( is_string( $decoded ) ) {
		return $decoded;
	}
	if ( is_array( $decoded ) ) {
		$list = implode( ', ', array_map( 'strval', array_filter( $decoded, 'is_scalar' ) ) );
		return '' !== $list ? $list : $raw;
	}
	return $raw;
}

/** One logged email's detail. Only the body answers to their keep-content switch, as on their own screen. */
function minn_admin_site_mailer_view( array $r ) {
	$row      = (object) $r;
	$delivery = array(
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => minn_admin_site_mailer_status( $row->status ), 'type' => 'pill' ),
		array( 'label' => __( 'To', 'minn-admin' ), 'value' => minn_admin_site_mailer_recipients( $row->to ) ),
	);
	if ( '' !== (string) $row->source ) {
		$delivery[] = array( 'label' => __( 'Source', 'minn-admin' ), 'value' => (string) $row->source );
	}
	$iso = minn_admin_db_local_to_utc_iso( $row->created_at );
	if ( '' !== $iso ) {
		$delivery[] = array( 'label' => __( 'Date', 'minn-admin' ), 'value' => $iso );
	}
	$body         = (string) $row->message;
	$message_rows = array( array( 'label' => __( 'Subject', 'minn-admin' ), 'value' => (string) $row->subject ) );
	if ( minn_admin_site_mailer_shows_content() && '' !== $body ) {
		$message_rows[] = preg_match( '/<\/?[a-z][^>]*>/i', $body )
			? array( 'label' => __( 'Body', 'minn-admin' ), 'value' => $body, 'type' => 'html-preview' )
			: array( 'label' => __( 'Body', 'minn-admin' ), 'value' => $body, 'type' => 'code' );
	}
	return array(
		'sections' => array(
			array( 'title' => __( 'Delivery', 'minn-admin' ), 'rows' => $delivery ),
			array( 'title' => __( 'Message', 'minn-admin' ), 'rows' => $message_rows ),
		),
	);
}

function minn_admin_site_mailer_can() {
	return current_user_can( 'manage_options' );
}

// The status ENUM is finer than the two tabs: each tab is its members.
const MINN_ADMIN_SITE_MAILER_DELIVERED = array( 'delivered', 'sent' );
const MINN_ADMIN_SITE_MAILER_FAILED    = array( 'failed', 'bounce', 'dropped' );

// created_at is the site's wall clock.
minn_admin_resource( 'site-mailer/emails', function () {
	return array(
		'active'  => 'minn_admin_site_mailer_active',
		'gates'   => array( 'view' => 'minn_admin_site_mailer_can' ),
		'labels'  => array( 'not_found' => __( 'Email not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'site_mail_logs',
			'key'     => 'id',
			'columns' => array( 'id', 'to', 'subject', 'status', 'created_at' ),
			'detail'  => array( '*' ),
		),
		'list'    => array(
			'facets' => array(
				array( 'param' => 'status', 'values' => array(
					'delivered' => array( 'status' => MINN_ADMIN_SITE_MAILER_DELIVERED ),
					'failed'    => array( 'status' => MINN_ADMIN_SITE_MAILER_FAILED ),
				) ),
			),
			'search' => array(
				'columns' => array( 'subject', 'to' ),
				// `to` holds wp_json_encode() output, which writes a non-ASCII
				// letter as \uXXXX and a slash as \/: the term in that form too.
				'also'    => function ( $term ) {
					return array( 'to' => array( 'contains' => substr( (string) wp_json_encode( $term ), 1, -1 ) ) );
				},
			),
			'range'  => array( 'column' => 'created_at', 'clock' => 'local' ),
			'order'  => array( 'column' => 'created_at' ),
			'row'    => function ( array $r ) {
				return array(
					'id'         => (int) $r['id'],
					'subject'    => (string) $r['subject'],
					'to'         => minn_admin_site_mailer_recipients( $r['to'] ),
					'status'     => minn_admin_site_mailer_status( $r['status'] ),
					'created_at' => $r['created_at'],
				);
			},
			'fields' => array(
				'id'         => 'int',
				'subject'    => 'text',
				'to'         => 'text',
				'status'     => 'text',
				'created_at' => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'z' ),
			),
		),
		'reads'   => array( 'view' => array( 'view' => 'minn_admin_site_mailer_view', 'fields' => array( 'sections' => 'raw' ) ) ),
		'actions' => array(
			// Not their Log_Entry::delete_logs(): it deletes by api_id, which is
			// '' on every row their cloud never accepted, so one of those would
			// take every other with it. By the row's own key instead.
			'delete' => array(
				'method' => 'DELETE',
				'gate'   => 'view',
				'run'    => function ( $id ) {
					return Minn_Admin_Kernel_SQL::delete( 'site_mail_logs', 'id', $id );
				},
				'expect' => 'gone',
				'reply'  => function () {
					return array( 'deleted' => true );
				},
				'done'   => __( 'Log entry deleted.', 'minn-admin' ),
			),
		),
	);
} );

minn_admin_endpoint( 'site-mailer/status', array(
	'active' => 'minn_admin_site_mailer_active',
	'gate'   => 'minn_admin_site_mailer_can',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open Site Mailer ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=site-mailer' ) ) );
		if ( ! Minn_Admin_Kernel_SQL::exists( 'site_mail_logs' ) ) {
			return array(
				'rows'    => array( array( 'label' => __( 'Email log', 'minn-admin' ), 'value' => '—', 'hint' => __( 'Site Mailer has not logged any sends yet', 'minn-admin' ) ) ),
				'actions' => $open,
			);
		}
		$id     = 'site-mailer/emails';
		$failed = Minn_Admin_Kernel::count( $id, array( 'status' => MINN_ADMIN_SITE_MAILER_FAILED ) );
		$opened = Minn_Admin_Kernel::count( $id, array( 'opened' => 1 ) );
		return array(
			'rows'    => array(
				array(
					'label' => __( 'Logged emails', 'minn-admin' ),
					'value' => number_format_i18n( Minn_Admin_Kernel::count( $id ) ),
					/* translators: %s: number of failed emails. */
					'hint'  => $failed ? sprintf( __( '%s failed', 'minn-admin' ), number_format_i18n( $failed ) ) : __( 'All delivered', 'minn-admin' ),
				),
				array(
					'label' => __( 'Delivered', 'minn-admin' ),
					'value' => number_format_i18n( Minn_Admin_Kernel::count( $id, array( 'status' => MINN_ADMIN_SITE_MAILER_DELIVERED ) ) ),
					'hint'  => $opened ? number_format_i18n( $opened ) . ' opened' : '',
				),
			),
			'chart'   => array(
				'title'     => __( 'Last 14 days', 'minn-admin' ),
				'primary'   => 'Delivered',
				'secondary' => 'Failed',
				'points'    => array_values( Minn_Admin_Kernel::days( $id, array( 'column' => 'created_at', 'clock' => 'local', 'split' => 'status', 'secondary' => MINN_ADMIN_SITE_MAILER_FAILED, 'label' => 'M j' ) ) ),
			),
			'actions' => $open,
		);
	},
) );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_site_mailer_active() ) {
		return $surfaces;
	}
	$surfaces['site-mailer'] = array(
		'label'      => __( 'Email', 'minn-admin' ),
		'family'     => 'mail',
		'sub'        => 'Site Mailer',
		'plugin'     => 'site-mailer',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/site-mailer/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/site-mailer/emails',
			// A status-chart bar narrows the log to that day (the chart's
			// points carry from/to; the route reads after/before).
			'dateQuery' => 'after={from}&before={to}',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'status',
				'static'   => array(
					array( 'delivered', 'Delivered' ),
					array( 'failed', 'Failed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => __( 'Subject', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'to', 'label' => __( 'To', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'created_at', 'label' => __( 'Date', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/site-mailer/emails/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'route'   => 'minn-admin/v1/site-mailer/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => __( 'Delete this log entry permanently? There is no trash.', 'minn-admin' ),
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'route'   => 'minn-admin/v1/site-mailer/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => __( 'Delete the selected log entries permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );
