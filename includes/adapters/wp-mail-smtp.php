<?php
/**
 * Bundled adapter: WP Mail SMTP.
 *
 * The free plugin stores no full email log (that's Pro) — what it does keep
 * is {prefix}wpmailsmtp_debug_events: delivery errors and, when verbose
 * debugging is on, send attempts. That is exactly the "did my mail fail and
 * why" daily-work question, so the surface lists those events read-only.
 * event_type 0 = error, 1 = debug. created_at is UTC whatever the database's
 * own zone: the column defaults to CURRENT_TIMESTAMP, but Event::save() always
 * writes it, from a DateTime in UTC. Read on the 'db' clock instead, a
 * database on its system zone (Eastern here) moved every event by the offset
 * and put the evening's events on tomorrow. It leaves as an ISO-8601 Z
 * string. The `initiator`
 * column is a {"file","line"} JSON blob, reduced to basename:line. The
 * table only exists after the plugin's migration runs; routes answer empty
 * until then.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wp_mail_smtp_active() {
	return defined( 'WPMS_PLUGIN_VER' ) || function_exists( 'wp_mail_smtp' );
}

/** {"file":"…","line":N} → "file.php:N"; anything else passes through. */
function minn_admin_wp_mail_smtp_initiator( $raw ) {
	$data = json_decode( (string) $raw, true );
	if ( is_array( $data ) && ! empty( $data['file'] ) ) {
		return basename( str_replace( '\\', '/', $data['file'] ) ) . ( isset( $data['line'] ) ? ':' . (int) $data['line'] : '' );
	}
	return (string) $raw;
}

/**
 * WP Mail SMTP resolves this through a filter a site can narrow or widen, and
 * gates every one of its own screens on the result. manage_options is only the
 * default that feeds it.
 *
 * @return bool
 */
function minn_admin_wp_mail_smtp_can() {
	if ( function_exists( 'wp_mail_smtp' ) ) {
		try {
			$core = wp_mail_smtp();
			if ( $core && method_exists( $core, 'get_capability_manage_options' ) ) {
				return current_user_can( $core->get_capability_manage_options() );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the mirrored test below.
		}
	}
	return current_user_can( 'manage_options' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wp_mail_smtp_active() || ! minn_admin_wp_mail_smtp_can() ) {
		return $surfaces;
	}

	$surfaces['wp-mail-smtp'] = array(
		'label'      => __( 'Email', 'minn-admin' ),
		'sub'        => 'WP Mail SMTP',
		'plugin'     => array( 'wp-mail-smtp', 'wp-mail-smtp-pro' ),
		'icon'       => 'send',
		// Their answer is a resolver, not a capability name; the filter above
		// is the real gate (the Solid Security / WP Mail Logging precedent).
		'cap'        => 'read',
		'family'     => 'mail',
		'status'     => array( 'route' => 'minn-admin/v1/wp-mail-smtp/status' ),
		// A status-chart bar narrows the list to that day (the kernel's dateQuery).
		'collection' => Minn_Admin_Kernel::collection( 'wp-mail-smtp/events', array(
			'tabs'      => array(
				'param'  => 'type',
				'static' => array(
					array( 'error', 'Errors' ),
					array( 'debug', 'Debug' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'content', 'label' => __( 'Event', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'initiator', 'label' => __( 'Initiator', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'created_at', 'label' => __( 'Date', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/wp-mail-smtp/events/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message' ),
			),
		) ),
	);
	return $surfaces;
} );

/** The event's type as the list and its tabs name it. */
function minn_admin_wp_mail_smtp_type( $event_type ) {
	return 0 === (int) $event_type ? 'error' : 'debug';
}

// created_at is UTC (see the header), so a chart bar's site-local bounds
// convert first.
minn_admin_resource( 'wp-mail-smtp/events', function () {
	return array(
		'active' => 'minn_admin_wp_mail_smtp_active',
		'gates'  => array( 'view' => 'minn_admin_wp_mail_smtp_can' ),
		'labels' => array( 'not_found' => __( 'Event not found', 'minn-admin' ) ),
		'source' => array(
			'table'   => 'wpmailsmtp_debug_events',
			'key'     => 'id',
			'columns' => array( 'id', 'content', 'initiator', 'event_type', 'created_at' ),
			// The queue holds each queued email whole, body included, while
			// its queue is on: its own capability, as every screen of theirs.
			'tables'  => array( 'wpmailsmtp_emails_queue' ),
		),
		'list'   => array(
			'facets' => array(
				array( 'param' => 'type', 'values' => array(
					'error' => array( 'event_type' => 0 ),
					'debug' => array( 'event_type' => array( 'not' => array( 0 ) ) ),
				) ),
			),
			// Their own EventsCollection searches content OR initiator.
			'search' => array( 'columns' => array( 'content', 'initiator' ) ),
			'range'  => array( 'column' => 'created_at', 'clock' => 'utc' ),
			'order'  => array( 'column' => 'id' ),
			'row'    => function ( array $r ) {
				// Cut after the fence has seen it whole (Minn_Admin_Egress::clip()).
				$content = Minn_Admin_Egress::clip( trim( preg_replace( '/\s+/', ' ', (string) $r['content'] ) ), 119 );
				return array(
					'id'         => (int) $r['id'],
					'content'    => $content,
					'initiator'  => minn_admin_wp_mail_smtp_initiator( $r['initiator'] ),
					'type'       => minn_admin_wp_mail_smtp_type( $r['event_type'] ),
					'created_at' => $r['created_at'],
				);
			},
			'fields' => array(
				'id'         => 'int',
				'content'    => 'text',
				'initiator'  => 'text',
				'type'       => 'text',
				'created_at' => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'z' ),
			),
		),
		'item'   => array(
			'view'   => function ( array $r ) {
				return array(
					'id'         => (int) $r['id'],
					'initiator'  => minn_admin_wp_mail_smtp_initiator( $r['initiator'] ),
					'type'       => minn_admin_wp_mail_smtp_type( $r['event_type'] ),
					'created_at' => $r['created_at'],
					'message'    => (string) $r['content'],
				);
			},
			'fields' => array(
				'id'         => 'int',
				'initiator'  => 'text',
				'type'       => 'text',
				'created_at' => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'z' ),
				'message'    => 'text',
			),
		),
	);
} );

minn_admin_endpoint( 'wp-mail-smtp/status', array(
	'active' => 'minn_admin_wp_mail_smtp_active',
	'gate'   => 'minn_admin_wp_mail_smtp_can',
	'run'    => function () {
		$cls  = '\\WPMailSMTP\\Admin\\DebugEvents\\DebugEvents';
		$rows = array();

		// Their own counter for the headline number, so the card agrees
		// with their screen instead of counting differently.
		$errors_30d = null;
		if ( class_exists( $cls ) && method_exists( $cls, 'get_error_debug_events_count' ) ) {
			try {
				$errors_30d = (int) $cls::get_error_debug_events_count( '-30 days' );
			} catch ( \Throwable $e ) {
				$errors_30d = null;
			}
		}
		$rows[] = array(
			'label' => __( 'Errors (30 days)', 'minn-admin' ),
			'value' => number_format_i18n( null === $errors_30d ? 0 : $errors_30d ),
			'hint'  => $errors_30d
				? __( 'Delivery problems this plugin recorded.', 'minn-admin' )
				: __( 'No delivery errors recorded.', 'minn-admin' ),
		);

		// Which mailer is configured is the other half of "is my mail
		// working", and it is the first thing their own setup screen shows.
		$mailer = '';
		if ( class_exists( '\\WPMailSMTP\\Options' ) ) {
			try {
				$opts   = new \WPMailSMTP\Options();
				$mailer = (string) $opts->get( 'mail', 'mailer' );
			} catch ( \Throwable $e ) {
				$mailer = '';
			}
		}
		$rows[] = array(
			'label' => __( 'Mailer', 'minn-admin' ),
			'value' => '' !== $mailer ? $mailer : __( 'Not set', 'minn-admin' ),
			'hint'  => 'mail' === $mailer || '' === $mailer
				? __( 'Sending through the web server, not an SMTP service.', 'minn-admin' )
				: __( 'Configured in WP Mail SMTP.', 'minn-admin' ),
		);

		// Verbose debug logging is off by default, which is why the list
		// can look empty on a site that sends fine. Say so rather than
		// leaving the reader to wonder.
		$debug = null;
		if ( class_exists( $cls ) && method_exists( $cls, 'is_debug_enabled' ) ) {
			try {
				$debug = (bool) $cls::is_debug_enabled();
			} catch ( \Throwable $e ) {
				$debug = null;
			}
		}
		$rows[] = array(
			'label' => __( 'Events logged', 'minn-admin' ),
			'value' => number_format_i18n( Minn_Admin_Kernel::count( 'wp-mail-smtp/events' ) ),
			'hint'  => true === $debug
				? __( 'Debug logging is on, so sends are recorded too.', 'minn-admin' )
				: __( 'Errors only. Turn on debug logging to record sends.', 'minn-admin' ),
		);

		$out = array(
			'rows'    => $rows,
			'actions' => array(
				array(
					'label' => __( 'Open WP Mail SMTP ↗', 'minn-admin' ),
					'href'  => class_exists( $cls ) && method_exists( $cls, 'get_page_url' )
						? $cls::get_page_url()
						: admin_url( 'admin.php?page=wp-mail-smtp-tools&tab=debug-events' ),
				),
			),
		);
		// Errors per day for the same fourteen days the mail siblings show:
		// TYPE_ERROR is 0 and TYPE_DEBUG 1, their only two.
		if ( Minn_Admin_Kernel_SQL::exists( 'wpmailsmtp_debug_events' ) ) {
			$days         = Minn_Admin_Kernel::days( 'wp-mail-smtp/events', array( 'column' => 'created_at', 'clock' => 'utc', 'split' => 'event_type', 'secondary' => array( 1 ) ) );
			$out['chart'] = minn_admin_chart_build( $days, __( 'Errors', 'minn-admin' ), __( 'Debug', 'minn-admin' ) );
		}
		return $out;
	},
) );
