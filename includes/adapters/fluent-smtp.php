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
 * when that class loads (their own whereIn path); otherwise a prefix-scoped
 * DELETE by id. Bulk reuses the single-delete route (WPML pattern).
 *
 * last-sweep: 2026-07-14
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_fluent_smtp_active() {
	return defined( 'FLUENT_MAIL_DB_PREFIX' ) || defined( 'FLUENTMAIL' );
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

/** Server-built model for the surface status card. */
function minn_admin_fluent_smtp_status_model() {
	global $wpdb;
	$table = $wpdb->prefix . 'fsmpt_email_logs';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $found ) {
		return array(
			'rows'    => array( array( 'label' => 'Email log', 'value' => 'Not ready', 'hint' => 'FluentSMTP has not created its log table yet' ) ),
			'actions' => array(
				array( 'label' => 'Open FluentSMTP ↗', 'href' => admin_url( 'options-general.php?page=fluent-mail#/' ) ),
			),
		);
	}
	$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	$failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
	// created_at is site-local current_time — compare against site-local 14d ago.
	$since_local = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 14 * DAY_IN_SECONDS );
	$days        = $wpdb->get_results( $wpdb->prepare(
		"SELECT DATE(created_at) AS d, status, COUNT(*) AS c FROM {$table}
		 WHERE created_at >= %s GROUP BY DATE(created_at), status ORDER BY d ASC",
		$since_local
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
		if ( 'failed' === $row->status ) {
			$by_day[ $d ]['secondary'] = (int) $row->c;
		} else {
			$by_day[ $d ]['value'] = (int) $row->c;
		}
	}

	// Connection count from FluentSMTP settings (never touch secrets).
	$connections = 0;
	$settings    = get_option( 'fluentmail-settings', array() );
	if ( is_array( $settings ) && ! empty( $settings['connections'] ) && is_array( $settings['connections'] ) ) {
		$connections = count( $settings['connections'] );
	}

	return array(
		'rows'    => array(
			array(
				'label' => 'Logged emails',
				'value' => number_format_i18n( $total ),
				'hint'  => $failed ? number_format_i18n( $failed ) . ' failed' : 'All logged sends',
			),
			array(
				'label' => 'Connections',
				'value' => (string) $connections,
				'hint'  => $connections ? 'Configured in FluentSMTP' : 'No mailer connected yet',
			),
		),
		'chart'   => array(
			'title'     => 'Last 14 days',
			'primary'   => 'Sent',
			'secondary' => 'Failed',
			'points'    => array_values( $by_day ),
		),
		'actions' => array(
			array(
				'label'  => 'Send a test email',
				'route'  => 'minn-admin/v1/fluent-smtp/test',
				'method' => 'POST',
				'fields' => array(
					array(
						'key'         => 'email',
						'label'       => 'Send to',
						'placeholder' => wp_get_current_user()->user_email,
						'required'    => true,
					),
				),
			),
			array(
				'label' => 'Open FluentSMTP ↗',
				'href'  => admin_url( 'options-general.php?page=fluent-mail#/' ),
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_fluent_smtp_active() ) {
		return $surfaces;
	}

	$surfaces['fluent-smtp'] = array(
		'label'      => 'Email',
		'sub'        => 'FluentSMTP',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'family'     => 'mail',
		'status'     => array( 'route' => 'minn-admin/v1/fluent-smtp/status' ),
		// v0.18.0: the daily-ops misc settings (connections routing, logging,
		// simulation) through their Settings model. The connection WIZARD
		// (per-provider credential forms) stays FluentSMTP's own app.
		'settings'   => array(
			'cap'   => 'manage_options',
			'tabs'  => array( array( 'id' => 'general', 'label' => 'General' ) ),
			'route' => 'minn-admin/v1/fluent-smtp/settings/{tab}',
		),
		'collection' => array(
			'route'     => 'minn-admin/v1/fluent-smtp/emails',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'  => 'status',
				'static' => array(
					array( 'sent', 'Sent' ),
					array( 'failed', 'Failed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => 'Subject', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'To', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'created_at', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				// v0.18.0: server-built sections (status pill, sandboxed HTML
				// body). The flat /emails/{id} route stays for API consumers.
				'sectionsRoute' => 'minn-admin/v1/fluent-smtp/emails/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => 'Resend',
					'route'   => 'minn-admin/v1/fluent-smtp/emails/{id}/resend',
					'method'  => 'POST',
					'confirm' => 'Resend this email to the original recipients?',
				),
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/fluent-smtp/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete this log entry permanently? There is no trash.',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/fluent-smtp/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete the selected log entries permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

/**
 * Delete one or more FluentSMTP log rows through their Logger when available.
 *
 * @param int[] $ids Log ids.
 * @return bool True when at least one delete path ran without throwing.
 */
function minn_admin_fluent_smtp_delete_ids( array $ids ) {
	$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
	if ( ! $ids ) {
		return false;
	}
	if ( class_exists( 'FluentMail\\App\\Models\\Logger' ) ) {
		try {
			$logger = new \FluentMail\App\Models\Logger();
			$logger->delete( $ids );
			return true;
		} catch ( \Throwable $e ) {
			// Fall through to prefix-scoped SQL.
		}
	}
	global $wpdb;
	$table = $wpdb->prefix . 'fsmpt_email_logs';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is prefix-fixed.
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ($placeholders)", ...$ids ) );
	return false !== $deleted && $deleted > 0;
}

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

/** GET shape for the FluentSMTP settings tab: { groups, values, adminUrl }. */
function minn_admin_fluent_smtp_settings_shape() {
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

	// Simulation can be forced from wp-config — then it's read-only truth.
	$sim_locked = defined( 'FLUENTMAIL_SIMULATE_EMAILS' ) && FLUENTMAIL_SIMULATE_EMAILS;

	$days     = isset( $misc['log_saved_interval_days'] ) ? (string) intval( $misc['log_saved_interval_days'] ) : '14';
	$day_opts = array( '7', '14', '30', '60', '90', '180', '365' );
	if ( ! in_array( $days, $day_opts, true ) ) {
		array_unshift( $day_opts, $days ); // keep a nonstandard stored value pickable
	}

	$groups = array(
		array(
			'title'  => 'Connections',
			'fields' => array(
				array(
					'key'     => 'default_connection',
					'label'   => 'Default connection',
					'type'    => 'combobox',
					'options' => minn_admin_fluent_smtp_connection_options(),
					'help'    => 'Used when no sender mapping claims the From address. Connection credentials stay in FluentSMTP.',
				),
				array(
					'key'     => 'fallback_connection',
					'label'   => 'Fallback connection',
					'type'    => 'combobox',
					'options' => minn_admin_fluent_smtp_connection_options( true ),
					'help'    => 'Tried when the chosen connection fails to send.',
				),
			),
		),
		array(
			'title'  => 'Logging',
			'fields' => array(
				array(
					'key'   => 'log_emails',
					'label' => 'Log all emails',
					'type'  => 'toggle',
					'help'  => 'Keep a copy of every outgoing email in the log.',
				),
				array(
					'key'     => 'log_saved_interval_days',
					'label'   => 'Delete logs older than',
					'type'    => 'select',
					'options' => array_map( function ( $d ) {
						return array( $d, $d . ' days' );
					}, $day_opts ),
					'help'    => 'FluentSMTP prunes older log entries on its daily schedule.',
				),
			),
		),
		array(
			'title'  => 'Email simulation',
			'fields' => $sim_locked ? array() : array(
				array(
					'key'   => 'simulate_emails',
					'label' => 'Simulate outgoing emails',
					'type'  => 'toggle',
					'help'  => 'Emails are logged but never actually sent. For staging and test sites.',
				),
			),
			'locked' => $sim_locked ? 1 : 0,
		),
	);

	return array(
		'groups'   => $groups,
		'values'   => array(
			'default_connection'      => isset( $misc['default_connection'] ) ? (string) $misc['default_connection'] : '',
			'fallback_connection'     => isset( $misc['fallback_connection'] ) ? (string) $misc['fallback_connection'] : '',
			'log_emails'              => ! isset( $misc['log_emails'] ) || 'yes' === $misc['log_emails'],
			'log_saved_interval_days' => $days,
			'simulate_emails'         => isset( $misc['simulate_emails'] ) && 'yes' === $misc['simulate_emails'],
		),
		'adminUrl' => admin_url( 'options-general.php?page=fluent-mail#/settings' ),
	);
}

/** POST: whitelist keys, then their Settings::updateMiscSettings. */
function minn_admin_fluent_smtp_settings_save( WP_REST_Request $request ) {
	if ( ! class_exists( 'FluentMail\\App\\Models\\Settings' ) ) {
		return new WP_Error( 'minn_fsmtp_unavailable', 'FluentSMTP is not fully loaded.', array( 'status' => 500 ) );
	}
	$body = $request->get_json_params();
	$vals = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();

	$model = new \FluentMail\App\Models\Settings();
	$misc  = (array) $model->getMisc();

	$stored = get_option( 'fluentmail-settings', array() );
	$conns  = ( is_array( $stored ) && ! empty( $stored['connections'] ) && is_array( $stored['connections'] ) )
		? $stored['connections'] : array();

	foreach ( array( 'default_connection', 'fallback_connection' ) as $key ) {
		if ( ! array_key_exists( $key, $vals ) ) {
			continue;
		}
		$pick = (string) $vals[ $key ];
		if ( '' === $pick && 'fallback_connection' === $key ) {
			$misc[ $key ] = '';
			continue;
		}
		if ( ! isset( $conns[ $pick ] ) ) {
			return new WP_Error( 'minn_fsmtp_bad_connection', 'That connection no longer exists.', array( 'status' => 400 ) );
		}
		$misc[ $key ] = $pick;
	}
	if ( array_key_exists( 'log_emails', $vals ) ) {
		$misc['log_emails'] = $vals['log_emails'] ? 'yes' : 'no';
	}
	if ( array_key_exists( 'simulate_emails', $vals ) && ! ( defined( 'FLUENTMAIL_SIMULATE_EMAILS' ) && FLUENTMAIL_SIMULATE_EMAILS ) ) {
		$misc['simulate_emails'] = $vals['simulate_emails'] ? 'yes' : 'no';
	}
	if ( array_key_exists( 'log_saved_interval_days', $vals ) ) {
		$misc['log_saved_interval_days'] = (string) max( 1, min( 3650, intval( $vals['log_saved_interval_days'] ) ) );
	}

	try {
		$model->updateMiscSettings( $misc ); // their own controller's exact write
	} catch ( \Throwable $e ) {
		return new WP_Error( 'minn_fsmtp_save_failed', $e->getMessage(), array( 'status' => 500 ) );
	}
	return rest_ensure_response( minn_admin_fluent_smtp_settings_shape() );
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_fluent_smtp_active() ) {
		return;
	}

	$perm  = function () {
		return current_user_can( 'manage_options' );
	};
	$table = $GLOBALS['wpdb']->prefix . 'fsmpt_email_logs';

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/settings/(?P<tab>[a-z]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () {
				return rest_ensure_response( minn_admin_fluent_smtp_settings_shape() );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'minn_admin_fluent_smtp_settings_save',
		),
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/emails', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where  = array( '1=1' );
			$params = array();
			if ( $status ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			}
			// Mirror Logger::$searchables: to / from / subject (to is serialized —
			// LIKE still matches the address text inside the blob).
			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '( subject LIKE %s OR `from` LIKE %s OR `to` LIKE %s )';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
			if ( $params ) {
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", ...$params ) );
				$args  = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, `to`, subject, status, source, created_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
					...$args
				) );
			} else {
				$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
				$rows  = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, `to`, subject, status, source, created_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
					$per_page,
					( $page - 1 ) * $per_page
				) );
			}
			// phpcs:enable

			$items = array_map( function ( $row ) {
				return array(
					'id'         => (int) $row->id,
					'subject'    => $row->subject,
					'to'         => minn_admin_fluent_smtp_recipients( $row->to ),
					'status'     => $row->status,
					'source'     => $row->source,
					'created_at' => $row->created_at,
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	// Sections view (v0.18.0 row types): status pill + sandboxed HTML body.
	// The flat /emails/{id} route below stays for API consumers.
	register_rest_route( 'minn-admin/v1', '/fluent-smtp/emails/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, `to`, `from`, subject, body, status, response, source, retries, created_at FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			$delivery = array(
				array( 'label' => 'Status', 'value' => $row->status, 'type' => 'pill' ),
				array( 'label' => 'To', 'value' => minn_admin_fluent_smtp_recipients( $row->to ) ),
				array( 'label' => 'From', 'value' => (string) $row->from ),
			);
			if ( '' !== (string) $row->source ) {
				$delivery[] = array( 'label' => 'Source', 'value' => (string) $row->source );
			}
			if ( (int) $row->retries > 0 ) {
				$delivery[] = array( 'label' => 'Retries', 'value' => (string) (int) $row->retries );
			}
			$delivery[] = array( 'label' => 'Date', 'value' => (string) $row->created_at );
			$body     = (string) $row->body;
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
			// Provider reply: the same regex peek as the flat route — never
			// the raw serialized blob.
			$response = (string) $row->response;
			if ( preg_match( '/"(?:message|code)";s:\d+:"([^"]*)"/', $response, $m ) ) {
				$response = $m[1];
			} elseif ( strlen( $response ) > 200 || preg_match( '/^[aOs]:\d+/', $response ) ) {
				$response = '';
			}
			if ( '' !== $response ) {
				$sections[] = array(
					'title' => 'Provider reply',
					'rows'  => array( array( 'label' => 'Response', 'value' => $response ) ),
				);
			}
			return rest_ensure_response( array( 'sections' => $sections ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/emails/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, `to`, `from`, subject, body, status, response, source, retries, created_at FROM {$table} WHERE id = %d", // phpcs:ignore
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
				}
				// `response` may be a serialized provider reply — surface only a
				// short plain-text peek, never the raw blob.
				$response = (string) $row->response;
				if ( preg_match( '/"(?:message|code)";s:\d+:"([^"]*)"/', $response, $m ) ) {
					$response = $m[1];
				} elseif ( strlen( $response ) > 200 || preg_match( '/^[aOs]:\d+/', $response ) ) {
					$response = '';
				}
				return rest_ensure_response( array(
					'id'         => (int) $row->id,
					'subject'    => $row->subject,
					'to'         => minn_admin_fluent_smtp_recipients( $row->to ),
					'from'       => $row->from,
					'status'     => $row->status,
					'response'   => $response,
					'source'     => $row->source,
					'retries'    => (int) $row->retries,
					'created_at' => $row->created_at,
					'message'    => $row->body,
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$id = (int) $request['id'];
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
				if ( ! $exists ) {
					return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
				}
				if ( ! minn_admin_fluent_smtp_delete_ids( array( $id ) ) ) {
					return new WP_Error( 'delete_failed', 'Could not delete this log entry.', array( 'status' => 500 ) );
				}
				// Confirm gone (Logger can return falsey without throwing).
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$still = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
				if ( $still ) {
					return new WP_Error( 'delete_failed', 'Log entry still exists after delete.', array( 'status' => 500 ) );
				}
				return rest_ensure_response( array(
					'deleted' => true,
					'message' => 'Log entry deleted.',
				) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/emails/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, `to`, subject, body FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			$to = array_filter( minn_admin_fluent_smtp_recipients( $row->to, true ), 'is_email' );
			if ( ! $to ) {
				return new WP_Error( 'no_recipients', 'No recipient address on record for this email.', array( 'status' => 422 ) );
			}
			$is_html = (bool) preg_match( '/<\/?[a-z][\s\S]*>/i', (string) $row->body );
			$headers = $is_html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();
			$sent    = wp_mail( $to, (string) $row->subject, (string) $row->body, $headers );
			if ( ! $sent ) {
				return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'resent'  => true,
				'to'      => implode( ', ', $to ),
				'message' => 'Resent to ' . implode( ', ', $to ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_fluent_smtp_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-smtp/test', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$email = sanitize_email( (string) ( $request['email'] ?? '' ) );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'bad_email', 'Enter a valid email address.', array( 'status' => 400 ) );
			}
			// Prefer FluentSMTP's own test helper when their Settings model loads.
			if ( class_exists( 'FluentMail\\App\\Models\\Settings' ) ) {
				try {
					$settings = new \FluentMail\App\Models\Settings();
					$result   = $settings->sendTestEmail(
						array( 'email' => $email, 'isHtml' => 'true' ),
						$settings->get()
					);
					if ( false === $result ) {
						return new WP_Error( 'send_failed', 'FluentSMTP could not send the test email.', array( 'status' => 500 ) );
					}
					return rest_ensure_response( array(
						'ok'      => true,
						'message' => 'Test email sent to ' . $email,
					) );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'send_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
			}
			// Fallback: plain wp_mail (still rides FluentSMTP when it owns the pipeline).
			$sent = wp_mail( $email, 'Fluent SMTP: Test Email - ' . get_bloginfo( 'name' ), "This is a test email from Minn Admin.\n" );
			if ( ! $sent ) {
				return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'Test email sent to ' . $email,
			) );
		},
	) );
} );
