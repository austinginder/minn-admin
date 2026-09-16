<?php
/**
 * Bundled adapter: WP Mail SMTP.
 *
 * The free plugin stores no full email log (that's Pro) — what it does keep
 * is {prefix}wpmailsmtp_debug_events: delivery errors and, when verbose
 * debugging is on, send attempts. That is exactly the "did my mail fail and
 * why" daily-work question, so the surface lists those events read-only.
 * event_type 0 = error, 1 = debug. created_at is a MySQL CURRENT_TIMESTAMP —
 * the DB server clock, UTC on the stacks this targets (verified against a
 * seeded row) — so it's emitted as an ISO-8601 Z string. The `initiator`
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

/** "2026-07-10 02:01:50" (DB clock, UTC) → ISO-8601 Z. */
function minn_admin_wp_mail_smtp_iso( $mysql ) {
	$ts = strtotime( (string) $mysql . ' UTC' );
	return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : (string) $mysql;
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
		'collection' => array(
			'route'     => 'minn-admin/v1/wp-mail-smtp/events',
			'pageQuery' => 'per_page=25&page={page}',
			// Their own EventsCollection does the searching, so this matches
			// their Debug Events screen rather than approximating it.
			'search'    => 'search={q}',
			// A status-chart bar narrows the list to that day (the chart's
			// points carry from/to; the route reads after/before).
			'dateQuery' => 'after={from}&before={to}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
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
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wp_mail_smtp_active() ) {
		return;
	}

	$perm  = function () {
		return minn_admin_wp_mail_smtp_can();
	};
	$table = $GLOBALS['wpdb']->prefix . 'wpmailsmtp_debug_events';

	register_rest_route( 'minn-admin/v1', '/wp-mail-smtp/events', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$type     = sanitize_key( (string) $request->get_param( 'type' ) );

			$search = sanitize_text_field( (string) $request->get_param( 'search' ) );

			$clauses = array();
			$args    = array();
			if ( 'error' === $type ) {
				$clauses[] = 'event_type = 0';
			} elseif ( 'debug' === $type ) {
				$clauses[] = 'event_type != 0';
			}
			if ( '' !== $search ) {
				// Their own EventsCollection searches content OR initiator;
				// mirror that pair rather than narrowing to one column.
				$like      = '%' . $wpdb->esc_like( $search ) . '%';
				$clauses[] = '( content LIKE %s OR initiator LIKE %s )';
				$args[]    = $like;
				$args[]    = $like;
			}
			// A status-chart bar narrows the list to that day. created_at is
			// the DB clock, UTC on the stacks this targets (the file header's
			// verified note), so the site-local bounds convert first.
			list( $range_sql, $range_args ) = minn_admin_chart_range_clause( $request, 'created_at', 'utc' );
			$clauses = array_merge( $clauses, $range_sql );
			$args    = array_merge( $args, $range_args );

			$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", $args ) ) // phpcs:ignore
				: $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ) ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, content, initiator, event_type, created_at FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );

			$items = array_map( function ( $row ) {
				$content = trim( preg_replace( '/\s+/', ' ', (string) $row->content ) );
				if ( function_exists( 'mb_substr' ) && mb_strlen( $content ) > 120 ) {
					$content = mb_substr( $content, 0, 119 ) . '…';
				}
				return array(
					'id'         => (int) $row->id,
					'content'    => $content,
					'initiator'  => minn_admin_wp_mail_smtp_initiator( $row->initiator ),
					'type'       => 0 === (int) $row->event_type ? 'error' : 'debug',
					'created_at' => minn_admin_wp_mail_smtp_iso( $row->created_at ),
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wp-mail-smtp/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () use ( $table ) {
			global $wpdb;
			$rows  = array();
			$debug = null;
			$cls   = '\\WPMailSMTP\\Admin\\DebugEvents\\DebugEvents';

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
			$have = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
			$total = $have ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0; // phpcs:ignore

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
			if ( class_exists( $cls ) && method_exists( $cls, 'is_debug_enabled' ) ) {
				try {
					$debug = (bool) $cls::is_debug_enabled();
				} catch ( \Throwable $e ) {
					$debug = null;
				}
			}
			$rows[] = array(
				'label' => __( 'Events logged', 'minn-admin' ),
				'value' => number_format_i18n( $total ),
				'hint'  => true === $debug
					? __( 'Debug logging is on, so sends are recorded too.', 'minn-admin' )
					: __( 'Errors only. Turn on debug logging to record sends.', 'minn-admin' ),
			);

			// Errors per day for the same fourteen days the mail siblings show.
			$chart = null;
			if ( $have ) {
				$days  = minn_admin_chart_days();
				$since = get_gmt_from_date( minn_admin_chart_local_since() );
				$day_sql = minn_admin_chart_utc_day_sql( 'created_at' );
				$counts  = $wpdb->get_results( $wpdb->prepare(
					"SELECT {$day_sql} AS d, event_type, COUNT(*) AS c FROM {$table} WHERE created_at >= %s GROUP BY d, event_type", // phpcs:ignore
					$since
				) );
				foreach ( (array) $counts as $r ) {
					minn_admin_chart_bump( $days, (string) $r->d, 0 !== (int) $r->event_type, (int) $r->c );
				}
				$chart = minn_admin_chart_build( $days, __( 'Errors', 'minn-admin' ), __( 'Debug', 'minn-admin' ) );
			}

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
			if ( $chart ) {
				$out['chart'] = $chart;
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wp-mail-smtp/events/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, content, initiator, event_type, created_at FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) Minn_Admin::path_param( $request )
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Event not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			return rest_ensure_response( array(
				'id'         => (int) $row->id,
				'initiator'  => minn_admin_wp_mail_smtp_initiator( $row->initiator ),
				'type'       => 0 === (int) $row->event_type ? 'error' : 'debug',
				'created_at' => minn_admin_wp_mail_smtp_iso( $row->created_at ),
				'message'    => (string) $row->content,
			) );
		},
	) );
} );
