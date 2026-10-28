<?php
/**
 * Bundled adapter: FluentSMTP.
 *
 * FluentSMTP keeps a full email log in {prefix}fsmpt_email_logs with no
 * public REST surface, so this is a shim (like Gravity SMTP). The `to` and
 * `headers` columns hold serialized arrays and are NEVER unserialized —
 * addresses are pulled out with a regex. `created_at` is current_time('mysql'),
 * a site-LOCAL datetime, so rows are emitted raw (the client parses naked
 * datetimes as site-local).
 *
 * Search matches their Logger::$searchables (to / from / subject) with
 * LIKE — including the serialized `to` blob as text, so an address still
 * hits. Delete goes through FluentMail\App\Models\Logger::delete( $ids )
 * when that class loads (their own whereIn path); otherwise the kernel's
 * DELETE by id. Bulk reuses the single-delete route (WPML pattern).
 *
 * FluentSMTP 2.3.0 catch-up: resend rides their
 * Logger::resendEmailFromLog( $id, 'resend', $recipients ) when the
 * `resent_count` column exists (it bumps the count, records the trail in
 * extra['resends'], and defines FLUENTMAIL_LOG_OFF so no duplicate row);
 * older builds keep the wp_mail fallback. The detail view surfaces that
 * trail via Logger::find() (their unserialize: allowed_classes false,
 * object blobs returned raw — never our own unserialize). Status card
 * reads their ConnectionHealth daily report; caps resolve through their
 * fluent_mail/manage_capability filter.
 *
 * last-sweep: 2026-08-06
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_fluent_smtp_active() {
	return defined( 'FLUENT_MAIL_DB_PREFIX' ) || defined( 'FLUENTMAIL' );
}

/**
 * The capability FluentSMTP itself requires, via their 2.3.0 filter.
 *
 * Their helper carries the same default, so pre-2.3.0 builds resolve to
 * manage_options unless a site already registers the filter.
 */
function minn_admin_fluent_smtp_cap() {
	if ( function_exists( 'fluentMailManageCapability' ) ) {
		return (string) fluentMailManageCapability();
	}
	return (string) apply_filters( 'fluent_mail/manage_capability', 'manage_options' );
}

/**
 * Whether this FluentSMTP build tracks resends (2.3.0+: `resent_count`
 * column + extra['resends'] trail). Gates the model-resend path so an old
 * build can never half-send through an update that fails on the missing
 * column.
 */
function minn_admin_fluent_smtp_has_resend_tracking() {
	static $has = null;
	if ( null !== $has ) {
		return $has;
	}
	$has = Minn_Admin_Kernel_SQL::has_column( 'fsmpt_email_logs', 'resent_count' )
		&& class_exists( 'FluentMail\\App\\Models\\Logger' )
		&& method_exists( 'FluentMail\\App\\Models\\Logger', 'resendEmailFromLog' );
	return $has;
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

/**
 * Connection-health status row from FluentSMTP's own daily check (2.3.0+).
 *
 * @return array|null Status-card row, or null when the service is absent
 *                    or no connection is configured (nothing to check).
 */
function minn_admin_fluent_smtp_health() {
	if ( ! class_exists( 'FluentMail\\App\\Services\\ConnectionHealth' ) || ! function_exists( 'fluentMailGetSettings' ) ) {
		return null;
	}
	try {
		$svc    = new \FluentMail\App\Services\ConnectionHealth();
		$report = (array) $svc->getReport();
		if ( ! $report ) {
			// Class present but the first daily run hasn't happened (or no
			// connections). Only say so when there is something to check.
			$conns = get_option( 'fluentmail-settings', array() );
			$n     = ( is_array( $conns ) && ! empty( $conns['connections'] ) && is_array( $conns['connections'] ) ) ? count( $conns['connections'] ) : 0;
			if ( ! $n ) {
				return null;
			}
			return array(
				'label' => __( 'Connection health', 'minn-admin' ),
				'value' => __( 'Not checked yet', 'minn-admin' ),
				'hint'  => __( 'FluentSMTP runs its first daily check within 24 hours', 'minn-admin' ),
			);
		}
		$failing = (array) $svc->getFailing();
		if ( $failing ) {
			$first = reset( $failing );
			$who   = '';
			if ( is_array( $first ) ) {
				$who = ! empty( $first['sender_email'] ) ? (string) $first['sender_email'] : strtoupper( (string) ( $first['provider'] ?? '' ) );
			}
			$msg = ( is_array( $first ) && ! empty( $first['message'] ) ) ? (string) $first['message'] : '';
			return array(
				'label' => __( 'Connection health', 'minn-admin' ),
				'value' => number_format_i18n( count( $failing ) ) . ' failing',
				'hint'  => trim( $who . ( $msg ? ': ' . ( strlen( $msg ) > 120 ? Minn_Admin_Egress::clip( $msg, 117 ) : $msg ) : '' ) ),
			);
		}
		// checked_at is gmdate — UTC.
		$first = reset( $report );
		$ago   = '';
		if ( is_array( $first ) && ! empty( $first['checked_at'] ) ) {
			$ts = strtotime( $first['checked_at'] . ' UTC' );
			if ( $ts ) {
				$ago = ', checked ' . human_time_diff( $ts, time() ) . ' ago';
			}
		}
		return array(
			'label' => __( 'Connection health', 'minn-admin' ),
			'value' => __( 'All passing', 'minn-admin' ),
			'hint'  => __( 'Daily check by FluentSMTP', 'minn-admin' ) . $ago,
		);
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * System-page health row: silent mail breakage is exactly what their daily
 * check exists for. Renders only when a report exists — a site that has
 * never been checked gets no row (no noise), and "not checked yet" already
 * shows on the surface status card.
 *
 * @return array[] of { label, status, detail, href? }
 */
function minn_admin_fluent_smtp_checks() {
	// The rows name sender addresses of failing connections, so they answer to
	// the plugin's own capability, which this adapter already resolves for its
	// routes and simply never applied here.
	if ( ! current_user_can( minn_admin_fluent_smtp_cap() ) ) {
		return array();
	}
	if ( ! minn_admin_fluent_smtp_active() || ! class_exists( 'FluentMail\\App\\Services\\ConnectionHealth' ) || ! function_exists( 'fluentMailGetSettings' ) ) {
		return array();
	}
	try {
		$svc    = new \FluentMail\App\Services\ConnectionHealth();
		$report = (array) $svc->getReport();
		if ( ! $report ) {
			return array();
		}
		$failing = (array) $svc->getFailing();
		$total   = count( $report );
		if ( ! $failing ) {
			return array( array(
				'label'  => __( 'FluentSMTP connections', 'minn-admin' ),
				'status' => 'pass',
				'detail' => sprintf(
					/* translators: %s: number of email connections. */
					_n( '%s connection passing its daily health check', 'All %s connections passing their daily health check', $total, 'minn-admin' ),
					number_format_i18n( $total )
				),
			) );
		}
		$names = array();
		foreach ( $failing as $f ) {
			if ( is_array( $f ) ) {
				$names[] = ! empty( $f['sender_email'] ) ? (string) $f['sender_email'] : strtoupper( (string) ( $f['provider'] ?? '' ) );
			}
		}
		return array( array(
			'label'  => __( 'FluentSMTP connections', 'minn-admin' ),
			// Every connection failing means outgoing email is down, not degraded.
			'status' => count( $failing ) >= $total ? 'fail' : 'warn',
			'detail' => sprintf(
				/* translators: 1: failing count, 2: total count, 3: sender list. */
				__( '%1$s of %2$s connections failing the daily health check (%3$s)', 'minn-admin' ),
				number_format_i18n( count( $failing ) ),
				number_format_i18n( $total ),
				implode( ', ', array_filter( $names ) )
			),
			'href'   => admin_url( 'options-general.php?page=fluent-mail#/connections' ),
		) );
	} catch ( \Throwable $e ) {
		return array();
	}
}

/** Server-built model for the surface status card. */
function minn_admin_fluent_smtp_status_model() {
	// Connection count from FluentSMTP settings (never touch secrets).
	$connections = 0;
	$settings    = get_option( 'fluentmail-settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['connections'] ) && is_array( $settings['connections'] ) ) {
		$connections = count( $settings['connections'] );
	}
	$rows = array(
		array(
			'label' => __( 'Connections', 'minn-admin' ),
			'value' => (string) $connections,
			'hint'  => $connections ? __( 'Configured in FluentSMTP', 'minn-admin' ) : __( 'No mailer connected yet', 'minn-admin' ),
		),
	);
	// Connection health (FluentSMTP 2.3.0+): their daily cron stores the
	// report in an option; getReport() already reconciles deleted
	// connections, so this is a zero-network read of their own verdict.
	$health = minn_admin_fluent_smtp_health();
	if ( null !== $health ) {
		$rows[] = $health;
	}
	return minn_admin_mail_card( 'fluent-smtp/emails', array(
		'table'           => 'fsmpt_email_logs',
		'missing'         => __( 'FluentSMTP has not created its log table yet', 'minn-admin' ),
		'missing_actions' => array( array( 'label' => __( 'Open FluentSMTP ↗', 'minn-admin' ), 'href' => admin_url( 'options-general.php?page=fluent-mail#/' ) ) ),
		'failed'          => array( 'status' => 'failed' ),
		// created_at is site-local current_time.
		'chart'           => array( 'column' => 'created_at', 'clock' => 'local', 'split' => 'status', 'secondary' => array( 'failed' ) ),
		'rows'            => $rows,
		'actions'         => array(
			array(
				'label'  => __( 'Send a test email', 'minn-admin' ),
				'route'  => 'minn-admin/v1/fluent-smtp/test',
				'method' => 'POST',
				'fields' => array(
					array(
						'key'         => 'email',
						'label'       => __( 'Send to', 'minn-admin' ),
						'placeholder' => wp_get_current_user()->user_email,
						'required'    => true,
					),
				),
			),
			array(
				'label' => __( 'Open FluentSMTP ↗', 'minn-admin' ),
				'href'  => admin_url( 'options-general.php?page=fluent-mail#/' ),
			),
		),
	) );
}

minn_admin_add_surface( 'fluent-smtp', 'minn_admin_fluent_smtp_active', function () {
	return minn_admin_mail_surface( 'fluent-smtp/emails', array(
		'sub'     => 'FluentSMTP',
		'plugin'  => 'fluent-smtp',
		'cap'     => minn_admin_fluent_smtp_cap(),
		'status'  => 'fluent-smtp/status',
		'resend'  => true,
		// Their 2.3.0 Recipient Picker equivalent: a parameterized action
		// lives in the detail modal (field-less actions are the only ones
		// list rows render, by design).
		'actions' => array(
			array_merge( Minn_Admin_Kernel::ui( 'fluent-smtp/emails', 'resend', array( 'label' => __( 'Resend to…', 'minn-admin' ) ) ), array(
				'fields' => array(
					array(
						'key'         => 'to',
						'label'       => __( 'Send to', 'minn-admin' ),
						'placeholder' => __( 'address@example.com, second@example.com', 'minn-admin' ),
						'required'    => true,
					),
				),
			) ),
		),
		// The daily-ops misc settings (connections routing, logging,
		// simulation) through their Settings model. The connection WIZARD
		// (per-provider credential forms) stays FluentSMTP's own app.
		'surface' => array(
			'settings' => array(
				'cap'   => minn_admin_fluent_smtp_cap(),
				'tabs'  => array( array( 'id' => 'general', 'label' => __( 'General', 'minn-admin' ) ) ),
				'route' => 'minn-admin/v1/fluent-smtp/settings/{tab}',
			),
		),
	) );
} );

/**
 * Connection choices for the settings selects: key => "Title · sender".
 * Reads titles and senders only, never credentials.
 *
 * @param bool $with_none Prepend a "None" choice (fallback connection).
 * @return array[] [ value, label ] pairs.
 */
function minn_admin_fluent_smtp_connection_options( $with_none = false ) {
	$out      = $with_none ? array( array( '', 'None' ) ) : array();
	$settings = get_option( 'fluentmail-settings', array() );
	$conns    = ( is_array( $settings ) && ! empty( $settings['connections'] ) && is_array( $settings['connections'] ) )
		? $settings['connections'] : array();
	foreach ( $conns as $key => $c ) {
		$ps       = isset( $c['provider_settings'] ) && is_array( $c['provider_settings'] ) ? $c['provider_settings'] : array();
		$provider = isset( $ps['provider'] ) ? strtoupper( (string) $ps['provider'] ) : '';
		$sender   = isset( $ps['sender_email'] ) ? (string) $ps['sender_email'] : '';
		$title    = ! empty( $c['title'] ) ? (string) $c['title'] : ( $provider ? $provider : (string) $key );
		$out[]    = array( (string) $key, $sender ? $title . ' · ' . $sender : $title );
	}
	return $out;
}

/** The misc settings as FluentSMTP reads them: its defaults under the stored row. */
function minn_admin_fluent_smtp_misc() {
	$misc = array();
	if ( class_exists( 'FluentMail\\App\\Models\\Settings' ) ) {
		try {
			$misc = (array) ( new \FluentMail\App\Models\Settings() )->getMisc();
		} catch ( \Throwable $e ) {
			$misc = array();
		}
	}
	if ( ! $misc ) {
		$stored = get_option( 'fluentmail-settings', array() );
		$misc   = isset( $stored['misc'] ) && is_array( $stored['misc'] ) ? $stored['misc'] : array();
	}
	return $misc;
}

/** The settings tab as offered: { groups, adminUrl }. */
function minn_admin_fluent_smtp_settings_layout( array $misc ) {
	// Simulation can be forced from wp-config — then it's read-only truth.
	$sim_locked = defined( 'FLUENTMAIL_SIMULATE_EMAILS' ) && FLUENTMAIL_SIMULATE_EMAILS;

	$days     = isset( $misc['log_saved_interval_days'] ) ? (string) intval( $misc['log_saved_interval_days'] ) : '14';
	$day_opts = array( '7', '14', '30', '60', '90', '180', '365' );
	if ( ! in_array( $days, $day_opts, true ) ) {
		array_unshift( $day_opts, $days ); // keep a nonstandard stored value pickable
	}

	$groups = array(
		array(
			'title'  => __( 'Connections', 'minn-admin' ),
			'fields' => array(
				array(
					'key'     => 'default_connection',
					'label'   => __( 'Default connection', 'minn-admin' ),
					'type'    => 'combobox',
					'options' => minn_admin_fluent_smtp_connection_options(),
					'help'    => __( 'Used when no sender mapping claims the From address. Connection credentials stay in FluentSMTP.', 'minn-admin' ),
				),
				array(
					'key'     => 'fallback_connection',
					'label'   => __( 'Fallback connection', 'minn-admin' ),
					'type'    => 'combobox',
					'options' => minn_admin_fluent_smtp_connection_options( true ),
					'help'    => __( 'Tried when the chosen connection fails to send.', 'minn-admin' ),
				),
			),
		),
		array(
			'title'  => __( 'Logging', 'minn-admin' ),
			'fields' => array(
				array(
					'key'   => 'log_emails',
					'label' => __( 'Log all emails', 'minn-admin' ),
					'type'  => 'toggle',
					'help'  => __( 'Keep a copy of every outgoing email in the log.', 'minn-admin' ),
				),
				array(
					'key'     => 'log_saved_interval_days',
					'label'   => __( 'Delete logs older than', 'minn-admin' ),
					'type'    => 'select',
					'options' => array_map( function ( $d ) {
						return array( $d, $d . ' days' );
					}, $day_opts ),
					'help'    => __( 'FluentSMTP prunes older log entries on its daily schedule.', 'minn-admin' ),
				),
			),
		),
		array(
			'title'  => __( 'Email simulation', 'minn-admin' ),
			'fields' => $sim_locked ? array() : array(
				array(
					'key'   => 'simulate_emails',
					'label' => __( 'Simulate outgoing emails', 'minn-admin' ),
					'type'  => 'toggle',
					'help'  => __( 'Emails are logged but never actually sent. For staging and test sites.', 'minn-admin' ),
				),
			),
			'locked' => $sim_locked ? 1 : 0,
		),
	);

	return array(
		'groups'   => $groups,
		'adminUrl' => admin_url( 'options-general.php?page=fluent-mail#/settings' ),
	);
}

/** The capability check every FluentSMTP route answers to. */
function minn_admin_fluent_smtp_can() {
	return current_user_can( minn_admin_fluent_smtp_cap() );
}

/** A provider reply as a short plain-text peek, never the raw serialized blob. */
function minn_admin_fluent_smtp_response( $raw ) {
	$response = (string) $raw;
	if ( preg_match( '/"(?:message|code)";s:\d+:"([^"]*)"/', $response, $m ) ) {
		return $m[1];
	}
	return ( strlen( $response ) > 200 || preg_match( '/^[aOs]:\d+/', $response ) ) ? '' : $response;
}

/** One email's sections: status pill, sandboxed HTML body, the provider's reply and the resend trail. */
function minn_admin_fluent_smtp_view( array $r ) {
	$delivery = array(
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $r['status'], 'type' => 'pill' ),
		array( 'label' => __( 'To', 'minn-admin' ), 'value' => minn_admin_fluent_smtp_recipients( $r['to'] ) ),
		array( 'label' => __( 'From', 'minn-admin' ), 'value' => (string) $r['from'] ),
	);
	if ( '' !== (string) $r['source'] ) {
		$delivery[] = array( 'label' => __( 'Source', 'minn-admin' ), 'value' => (string) $r['source'] );
	}
	if ( (int) $r['retries'] > 0 ) {
		$delivery[] = array( 'label' => __( 'Retries', 'minn-admin' ), 'value' => (string) (int) $r['retries'] );
	}
	$delivery[] = array( 'label' => __( 'Date', 'minn-admin' ), 'value' => (string) $r['created_at'] );
	$sections   = array();
	$response = minn_admin_fluent_smtp_response( $r['response'] );
	if ( '' !== $response ) {
		$sections[] = array(
			'title' => __( 'Provider reply', 'minn-admin' ),
			'rows'  => array( array( 'label' => __( 'Response', 'minn-admin' ), 'value' => $response ) ),
		);
	}
	// Resend trail (FluentSMTP 2.3.0+): read through their Logger::find,
	// whose unserialize refuses objects, so the blob never passes through
	// our own hands raw.
	if ( minn_admin_fluent_smtp_has_resend_tracking() ) {
		try {
			$full    = ( new \FluentMail\App\Models\Logger() )->find( (int) $r['id'] );
			$count   = isset( $full['resent_count'] ) ? (int) $full['resent_count'] : 0;
			$resends = ( ! empty( $full['extra']['resends'] ) && is_array( $full['extra']['resends'] ) )
				? $full['extra']['resends'] : array();
			if ( $count > 0 || $resends ) {
				$rrows   = array();
				$rrows[] = array(
					'label' => __( 'Resent', 'minn-admin' ),
					'value' => sprintf( /* translators: %s: how many times the message was sent. */ _n( '%s time', '%s times', max( $count, count( $resends ) ), 'minn-admin' ), number_format_i18n( max( $count, count( $resends ) ) ) ),
				);
				// Newest first, last five; `at` is site-local current_time,
				// emitted as stored, like created_at.
				foreach ( array_slice( array_reverse( $resends ), 0, 5 ) as $one ) {
					if ( ! is_array( $one ) ) {
						continue;
					}
					$bits   = array( 'To ' . implode( ', ', array_map( 'strval', (array) ( $one['to'] ?? array() ) ) ) );
					$bits[] = empty( $one['sent'] ) ? 'failed' : 'delivered';
					if ( ! empty( $one['by'] ) ) {
						$bits[] = 'by ' . (string) $one['by'];
					}
					$rrows[] = array(
						'label' => (string) ( $one['at'] ?? '' ),
						'value' => implode( ' — ', $bits ),
					);
				}
				$sections[] = array( 'title' => __( 'Resends', 'minn-admin' ), 'rows' => $rrows );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// The trail is a bonus; the detail renders without it.
		}
	}
	return minn_admin_mail_sections( $delivery, $r['subject'], (string) $r['body'], $sections );
}

/**
 * Resend one logged email, to its own recipients or to the addresses a
 * "Resend to…" names. Answers whom it sent to, or why it did not.
 *
 * @return string|WP_Error
 */
function minn_admin_fluent_smtp_resend( $id, array $row, WP_REST_Request $request ) {
	// Optional recipient override: comma-separated addresses; every one must
	// be valid or nothing sends.
	$override = array();
	$body_in  = (array) $request->get_json_params();
	$raw_to   = isset( $body_in['to'] ) ? (string) $body_in['to'] : (string) $request->get_param( 'to' );
	if ( '' !== trim( $raw_to ) ) {
		foreach ( array_map( 'trim', explode( ',', $raw_to ) ) as $addr ) {
			if ( '' === $addr ) {
				continue;
			}
			if ( ! is_email( $addr ) ) {
				/* translators: %s: the rejected recipient address as typed. */
				return new WP_Error( 'bad_email', sprintf( __( '"%s" is not a valid email address.', 'minn-admin' ), $addr ), array( 'status' => 400 ) );
			}
			$override[] = $addr;
		}
		// Their own resend screen's limit (LoggerController): a list this
		// long is a pasted address book, caught before sending.
		$max = (int) apply_filters( 'fluentsmtp_max_resend_recipients', 25 );
		if ( count( $override ) > $max ) {
			/* translators: %d: maximum number of recipients. */
			return new WP_Error( 'too_many_recipients', sprintf( __( 'Please enter no more than %d email addresses.', 'minn-admin' ), $max ), array( 'status' => 422 ) );
		}
	}
	// A reset link goes back only to whom it was for: sent anywhere else, its
	// key would reach someone the log hides it from.
	if ( $override && minn_admin_mail_mask_reset_keys( (string) $row['body'] ) !== (string) $row['body'] ) {
		return new WP_Error( 'minn_mail_reset_link', __( 'This email holds a link whose key is hidden from you (a password reset link), so it can only be resent to whom it was sent.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	$original = array_filter( minn_admin_fluent_smtp_recipients( $row['to'], true ), 'is_email' );
	if ( ! $override && ! $original ) {
		return new WP_Error( 'no_recipients', __( 'No recipient address on record for this email.', 'minn-admin' ), array( 'status' => 422 ) );
	}
	// FluentSMTP 2.3.0+: their own resend flow, which bumps resent_count,
	// records the trail in extra['resends'] (who, where, when, sent),
	// restores headers and attachments, drops cc/bcc on redirects and
	// defines FLUENTMAIL_LOG_OFF so no duplicate log row appears.
	if ( minn_admin_fluent_smtp_has_resend_tracking() ) {
		try {
			$email = ( new \FluentMail\App\Models\Logger() )->resendEmailFromLog( $id, 'resend', $override );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'send_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		// Their flow answers nothing when it could not record the attempt,
		// so whether the mail went cannot be told: never say it did.
		if ( ! is_array( $email ) ) {
			return new WP_Error( 'send_unknown', __( 'FluentSMTP did not record this resend, so Minn cannot tell whether it went out. Check its email log before trying again.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		$rec = array();
		if ( ! empty( $email['extra']['resends'] ) && is_array( $email['extra']['resends'] ) ) {
			$rec = (array) end( $email['extra']['resends'] );
		}
		$to_str = ! empty( $rec['to'] ) ? implode( ', ', (array) $rec['to'] ) : implode( ', ', $override ? $override : $original );
		if ( $rec && empty( $rec['sent'] ) ) {
			return new WP_Error( 'send_failed', __( 'The mailer reported the resend to ', 'minn-admin' ) . $to_str . ' could not be delivered (the attempt is recorded on the log entry).', array( 'status' => 500 ) );
		}
		return $to_str;
	}
	// Pre-2.3.0 fallback: plain wp_mail (no trail is recorded).
	return minn_admin_mail_wp_mail( $override ? $override : $original, $row['subject'], $row['body'] );
}

// created_at is the site's wall clock (current_time), and so are a chart bar's bounds.
minn_admin_resource( 'fluent-smtp/emails', function () {
	return array(
		'active'  => 'minn_admin_fluent_smtp_active',
		'gates'   => array(
			'view'  => 'minn_admin_fluent_smtp_can',
			// The raw table holds bodies whole, which the browser cannot
			// mask a reset link's key in: only for who reads mail whole.
			'table' => 'minn_admin_mail_bodies_open',
		),
		'labels'  => array( 'not_found' => __( 'Email not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'fsmpt_email_logs',
			'key'     => 'id',
			'columns' => array( 'id', 'to', 'subject', 'status', 'source', 'created_at' ),
			'detail'  => array( 'id', 'to', 'from', 'subject', 'body', 'status', 'response', 'source', 'retries', 'created_at' ),
		),
		'list'    => array(
			'facets' => array( array( 'param' => 'status', 'column' => 'status', 'type' => 'key' ) ),
			// Their Logger::$searchables; `to` is serialized, and LIKE still
			// matches the address text inside the blob.
			'search' => array( 'columns' => array( 'subject', 'from', 'to' ) ),
			'range'  => array( 'column' => 'created_at', 'clock' => 'local' ),
			'order'  => array( 'column' => 'id' ),
			'row'    => function ( array $r ) {
				return array(
					'id'         => (int) $r['id'],
					'subject'    => $r['subject'],
					'to'         => minn_admin_fluent_smtp_recipients( $r['to'] ),
					'status'     => $r['status'],
					'source'     => $r['source'],
					'created_at' => $r['created_at'],
				);
			},
			'fields' => array(
				'id'         => 'int',
				'subject'    => 'text',
				'to'         => 'text',
				'status'     => 'text',
				'source'     => 'text',
				'created_at' => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'sql' ),
			),
		),
		// The flat item stays for API consumers; the app reads the sections.
		'item'    => array(
			'view'   => function ( array $r ) {
				return array(
					'id'         => (int) $r['id'],
					'subject'    => $r['subject'],
					'to'         => minn_admin_fluent_smtp_recipients( $r['to'] ),
					'from'       => $r['from'],
					'status'     => $r['status'],
					'response'   => minn_admin_fluent_smtp_response( $r['response'] ),
					'source'     => $r['source'],
					'retries'    => (int) $r['retries'],
					'created_at' => $r['created_at'],
					'message'    => minn_admin_mail_mask_reset_keys( $r['body'] ),
				);
			},
			'fields' => array(
				'id'         => 'int',
				'subject'    => 'text',
				'to'         => 'text',
				'from'       => 'text',
				'status'     => 'text',
				'response'   => 'text',
				'source'     => 'text',
				'retries'    => 'int',
				'created_at' => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'sql' ),
				'message'    => 'text',
			),
		),
		'reads'   => array( 'view' => array( 'view' => 'minn_admin_fluent_smtp_view', 'fields' => array( 'sections' => 'raw' ) ) ),
		'actions' => array(
			// Their Logger::delete() (its own whereIn path) when it loads.
			'delete' => minn_admin_mail_delete( function ( $id ) {
				if ( class_exists( 'FluentMail\\App\\Models\\Logger' ) ) {
					try {
						return ( new \FluentMail\App\Models\Logger() )->delete( array( $id ) );
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Fall through to the kernel's delete.
					}
				}
				return Minn_Admin_Kernel_SQL::delete( 'fsmpt_email_logs', 'id', $id );
			}, array( 'fail' => __( 'Log entry still exists after delete.', 'minn-admin' ) ) ),
			'resend' => minn_admin_mail_resend( 'minn_admin_fluent_smtp_resend' ),
		),
	);
} );

minn_admin_endpoint( 'fluent-smtp/status', array(
	'active' => 'minn_admin_fluent_smtp_active',
	'gate'   => 'minn_admin_fluent_smtp_can',
	'run'    => 'minn_admin_fluent_smtp_status_model',
) );

// The misc settings live inside FluentSMTP's own row and are written through
// its Settings model, as its controller writes them, so the screen reads and
// writes them itself; the tier compares, checks and refuses first.
minn_admin_settings( 'fluent-smtp', array(
	'route'    => 'fluent-smtp/settings/(?P<tab>[a-z]+)',
	'active'   => 'minn_admin_fluent_smtp_active',
	'gate'     => 'minn_admin_fluent_smtp_can',
	'layout'   => function ( $ctx ) {
		$ctx['memo']['misc'] = $ctx['memo']['misc'] ?? minn_admin_fluent_smtp_misc();
		return minn_admin_fluent_smtp_settings_layout( $ctx['memo']['misc'] );
	},
	'read'     => function ( $f, $ctx ) {
		$ctx['memo']['misc'] = $ctx['memo']['misc'] ?? minn_admin_fluent_smtp_misc();
		$v                   = $ctx['memo']['misc'][ $f['key'] ] ?? null;
		if ( 'log_saved_interval_days' === $f['key'] ) {
			return null === $v ? '14' : (string) intval( $v );
		}
		if ( 'toggle' === $f['type'] ) {
			// Logging is on until it is turned off; simulation the other way.
			return 'yes' === $v || ( null === $v && 'log_emails' === $f['key'] );
		}
		return is_scalar( $v ) ? (string) $v : '';
	},
	'validate' => function ( $changes ) {
		$stored = get_option( 'fluentmail-settings', array() );
		$conns  = is_array( $stored ) && ! empty( $stored['connections'] ) && is_array( $stored['connections'] ) ? $stored['connections'] : array();
		foreach ( array_intersect_key( $changes, array_flip( array( 'default_connection', 'fallback_connection' ) ) ) as $key => $pick ) {
			// The fallback may be none; any other pick is a connection that exists.
			if ( ! is_scalar( $pick ) || ( ! isset( $conns[ (string) $pick ] ) && ( '' !== (string) $pick || 'fallback_connection' !== $key ) ) ) {
				return new WP_Error( 'minn_fsmtp_bad_connection', __( 'That connection no longer exists.', 'minn-admin' ), array( 'status' => 400, 'field' => $key ) );
			}
		}
		return null;
	},
	'write'    => function ( $changes ) {
		if ( ! class_exists( 'FluentMail\\App\\Models\\Settings' ) ) {
			return new WP_Error( 'minn_fsmtp_unavailable', __( 'FluentSMTP is not fully loaded.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		$model = new \FluentMail\App\Models\Settings();
		$misc  = (array) $model->getMisc();
		foreach ( $changes as $key => $v ) {
			if ( 'log_saved_interval_days' === $key ) {
				$misc[ $key ] = (string) max( 1, min( 3650, intval( $v ) ) );
			} else {
				$misc[ $key ] = in_array( $key, array( 'log_emails', 'simulate_emails' ), true ) ? ( Minn_Admin_Kernel_Settings::on( $v ) ? 'yes' : 'no' ) : (string) $v;
			}
		}
		try {
			$model->updateMiscSettings( $misc ); // their own controller's exact write
		} catch ( \Throwable $e ) {
			return new WP_Error( 'minn_fsmtp_save_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		return true;
	},
) );

minn_admin_endpoint( 'fluent-smtp/test', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_fluent_smtp_active',
	'gate'    => 'minn_admin_fluent_smtp_can',
	'run'     => function ( WP_REST_Request $request ) {
		$email = sanitize_email( (string) ( $request['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'bad_email', __( 'Enter a valid email address.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$done = array( 'ok' => true, 'message' => __( 'Test email sent to ', 'minn-admin' ) . $email );
		// Prefer FluentSMTP's own test helper when their Settings model loads.
		if ( class_exists( 'FluentMail\\App\\Models\\Settings' ) ) {
			try {
				$settings = new \FluentMail\App\Models\Settings();
				if ( false === $settings->sendTestEmail( array( 'email' => $email, 'isHtml' => 'true' ), $settings->get() ) ) {
					return new WP_Error( 'send_failed', __( 'FluentSMTP could not send the test email.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return $done;
			} catch ( \Throwable $e ) {
				return new WP_Error( 'send_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
		}
		// Fallback: plain wp_mail (still rides FluentSMTP when it owns the pipeline).
		/* translators: %s: the site title. */
		if ( ! wp_mail( $email, sprintf( __( 'Fluent SMTP: Test Email - %s', 'minn-admin' ), get_bloginfo( 'name' ) ), "This is a test email from Minn Admin.\n" ) ) {
			return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
		}
		return $done;
	},
) );
