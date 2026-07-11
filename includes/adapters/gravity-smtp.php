<?php
/**
 * Bundled adapter: Gravity SMTP.
 *
 * Gravity SMTP keeps its email log in custom tables with no REST surface
 * (100% nonce'd admin-ajax), so this adapter is the shim pattern from
 * docs/extension-api.md — but a deep one: alongside the read-only event
 * list it maps Gravity SMTP's own schema-driven settings (their
 * settings_fields() component descriptors) into Minn's `settings` surface
 * view, reads and writes through their data-store router (so GRAVITYSMTP_*
 * constant locks and masked-secret sentinels keep their semantics), and
 * resends through their own recipient model.
 *
 * Capability model: Gravity SMTP ships granular gravitysmtp_* caps
 * (granted to administrators by their Roles::register()); every route here
 * gates on the matching cap when the Roles class is loaded, falling back
 * to manage_options.
 *
 * The `extra` column holds serialized PHP objects. List/detail parsing
 * NEVER unserializes it (regex only); the resend path is the documented
 * exception: it mirrors Gravity SMTP's own Resend_Email_Endpoint verbatim,
 * including its allowlist (Recipient_Collection + Recipient only), so
 * their vendor code runs on their data exactly as it does in wp-admin.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_gravity_smtp_active() {
	return defined( 'GF_GRAVITY_SMTP_VERSION' ) || class_exists( 'Gravity_Forms\Gravity_SMTP\Gravity_SMTP' );
}

/**
 * A Gravity SMTP capability by Roles constant name, falling back to
 * manage_options when their Roles class isn't loadable.
 */
function minn_admin_gsmtp_cap( $const ) {
	$roles = 'Gravity_Forms\Gravity_SMTP\Users\Roles';
	if ( class_exists( $roles ) && defined( "$roles::$const" ) ) {
		return constant( "$roles::$const" );
	}
	return 'manage_options';
}

/** Their data-store router (constant locks first, then options). */
function minn_admin_gsmtp_router() {
	return new Gravity_Forms\Gravity_SMTP\Data_Store\Data_Store_Router(
		new Gravity_Forms\Gravity_SMTP\Data_Store\Const_Data_Store(),
		new Gravity_Forms\Gravity_SMTP\Data_Store\Opts_Data_Store(),
		new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store()
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_gravity_smtp_active() ) {
		return $surfaces;
	}

	$surfaces['gravity-smtp'] = array(
		'label'      => 'Email',
		'sub'        => 'Gravity SMTP',
		'icon'       => 'send',
		'cap'        => minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG' ),
		'family'     => 'mail',
		'collection' => array(
			'viewLabel' => 'Log',
			'route'     => 'minn-admin/v1/gravity-smtp/events',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'  => 'status',
				'static' => array(
					array( 'sent', 'Sent' ),
					array( 'failed', 'Failed' ),
					array( 'sandboxed', 'Sandboxed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => 'Subject', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'To', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				// Event timestamps are UTC MySQL datetimes (no zone suffix).
				array( 'key' => 'date_created', 'label' => 'Date', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/gravity-smtp/events/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message', 'can_resend' ),
			),
			'actions'   => array(
				array(
					'label'   => 'Resend',
					'route'   => 'minn-admin/v1/gravity-smtp/events/{id}/resend',
					'method'  => 'POST',
					'confirm' => 'Resend this email to the original recipients?',
					'when'    => array( 'key' => 'can_resend', 'equals' => true ),
				),
			),
		),
		// Suppressed addresses Gravity SMTP refuses to send to — list, add,
		// reactivate through their own model. Reads/writes gate on their
		// granular suppression caps server-side.
		'manage'     => array(
			'viewLabel' => 'Suppressions',
			'route'     => 'minn-admin/v1/gravity-smtp/suppressed',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'columns'   => array(
				array( 'key' => 'email', 'label' => 'Email', 'format' => 'title' ),
				array( 'key' => 'reason', 'label' => 'Reason', 'format' => 'pill' ),
				array( 'key' => 'notes', 'label' => 'Notes' ),
				// Their model stamps current_time( 'mysql', true ) — UTC.
				array( 'key' => 'date_created', 'label' => 'Since', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(),
			'create'    => array(
				'label'  => 'Suppress email',
				'route'  => 'minn-admin/v1/gravity-smtp/suppressed',
				'fields' => array(
					array( 'key' => 'email', 'label' => 'Email address', 'type' => 'email' ),
					array( 'key' => 'notes', 'label' => 'Notes', 'required' => false, 'placeholder' => 'Why sending to this address is off' ),
				),
			),
			'actions'   => array(
				array(
					'label'   => 'Reactivate (allow sending again)',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/gravity-smtp/suppressed/{id}/reactivate',
					'confirm' => 'Allow Gravity SMTP to send to this address again?',
				),
			),
		),
		'settings'   => array(
			'cap'   => minn_admin_gsmtp_cap( 'VIEW_GENERAL_SETTINGS' ),
			'tabs'  => array(
				array( 'id' => 'sending', 'label' => 'Sending' ),
				array( 'id' => 'general', 'label' => 'General' ),
			),
			'route' => 'minn-admin/v1/gravity-smtp/settings/{tab}',
		),
		'status'     => array(
			'route' => 'minn-admin/v1/gravity-smtp/status',
		),
	);
	return $surfaces;
} );

/**
 * Map one connector's settings_fields() descriptors (their React component
 * tree, declared as data) into Minn form-engine fields. Written once, it
 * covers all 21 connectors and whatever they add later — the schema is
 * read from the live plugin at request time.
 *
 * @param object $connector Connector_Base instance.
 * @return array { fields: array, values: array, locked: int }
 */
function minn_admin_gsmtp_map_connector( $connector ) {
	$sensitive = array();
	if ( method_exists( $connector, 'get_sensitive_fields' ) ) {
		$sensitive = (array) $connector->get_sensitive_fields();
	}
	$fields = array();
	$values = array();
	$locked = 0;
	$sentinel = '****************'; // Connector_Base::OBFUSCATED_STRING

	$walk = function ( $items ) use ( &$walk, &$fields, &$values, &$locked, $sensitive, $sentinel ) {
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$component = isset( $item['component'] ) ? $item['component'] : '';
			$props     = isset( $item['props'] ) && is_array( $item['props'] ) ? $item['props'] : array();
			// Containers nest their children under `fields`.
			if ( ! empty( $item['fields'] ) && in_array( $component, array( 'Box', 'InputGroup' ), true ) && ( 'InputGroup' !== $component || empty( $props['inputType'] ) ) ) {
				$walk( $item['fields'] );
				continue;
			}
			$name = isset( $props['name'] ) ? (string) $props['name'] : '';
			$label = isset( $props['labelAttributes']['label'] ) ? (string) $props['labelAttributes']['label'] : $name;
			$help  = isset( $props['helpTextAttributes']['content'] ) ? wp_strip_all_tags( (string) $props['helpTextAttributes']['content'] ) : '';
			switch ( $component ) {
				case 'Input':
				case 'LinkedHelpTextInput':
					if ( '' === $name ) {
						break;
					}
					$is_secret = in_array( $name, $sensitive, true );
					$fields[]  = array_filter( array(
						'key'   => $name,
						'label' => $label,
						'help'  => $help,
						'mono'  => $is_secret || ( isset( $props['type'] ) && 'password' === $props['type'] ),
					) );
					$raw = isset( $props['value'] ) ? $props['value'] : '';
					$values[ $name ] = ( $is_secret && '' !== (string) $raw ) ? $sentinel : $raw;
					break;
				case 'Toggle':
					if ( '' === $name ) {
						break;
					}
					$fields[] = array_filter( array(
						'key'   => $name,
						'label' => $label,
						'type'  => 'toggle',
						'help'  => $help,
					) );
					$values[ $name ] = ! empty( $props['initialChecked'] );
					break;
				case 'InputGroup': // radio group → select
				case 'Select':
					if ( '' === $name ) {
						break;
					}
					$options = array();
					foreach ( array( 'data', 'options', 'choices' ) as $ok ) {
						if ( ! empty( $props[ $ok ] ) && is_array( $props[ $ok ] ) ) {
							foreach ( $props[ $ok ] as $opt ) {
								if ( is_array( $opt ) && isset( $opt['value'] ) ) {
									$options[] = array( (string) $opt['value'], (string) ( isset( $opt['label'] ) ? $opt['label'] : $opt['value'] ) );
								}
							}
							break;
						}
					}
					if ( ! $options ) {
						$locked++;
						break;
					}
					$fields[] = array_filter( array(
						'key'     => $name,
						'label'   => $label,
						'type'    => 'select',
						'options' => $options,
						'help'    => $help,
					) );
					$values[ $name ] = isset( $props['initialValue'] ) ? $props['initialValue']
						: ( isset( $props['value'] ) ? $props['value'] : '' );
					break;
				case 'BrandedButton': // OAuth handshakes etc. — bespoke, wp-admin's
					$locked++;
					break;
				// Heading / Label / Text / Alert / Link / LinkedText / CopyInput /
				// Icon are chrome, not settings — nothing to map, nothing locked.
			}
		}
	};

	$sf = $connector->settings_fields();
	$walk( isset( $sf['fields'] ) ? $sf['fields'] : array() );
	return array( 'fields' => $fields, 'values' => $values, 'locked' => $locked );
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_gravity_smtp_active() ) {
		return;
	}

	$can = function ( $const ) {
		return function () use ( $const ) {
			return current_user_can( minn_admin_gsmtp_cap( $const ) );
		};
	};

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/events', array(
		'methods'             => 'GET',
		'permission_callback' => $can( 'VIEW_EMAIL_LOG' ),
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'gravitysmtp_events';
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );

			$where  = $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
			$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore
			$rows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, date_created, status, service, subject, extra FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				$per_page,
				( $page - 1 ) * $per_page
			) );

			$items = array_map( function ( $row ) {
				return array(
					'id'           => (int) $row->id,
					'date_created' => $row->date_created,
					'status'       => $row->status,
					'service'      => $row->service,
					'subject'      => $row->subject,
					'to'           => minn_admin_gravity_smtp_recipients( $row->extra ),
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/events/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $can( 'VIEW_EMAIL_LOG_DETAILS' ),
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = $wpdb->prefix . 'gravitysmtp_events';
			$row   = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, date_created, status, service, subject, message, extra FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
			}
			$out = array(
				'id'           => (int) $row->id,
				'subject'      => $row->subject,
				'to'           => minn_admin_gravity_smtp_recipients( $row->extra ),
				'status'       => $row->status,
				'service'      => $row->service,
				'date_created' => $row->date_created,
				'message'      => $row->message,
				'can_resend'   => true,
			);
			// Enrich through their own models (from/cc/bcc parsed by their
			// code, attachment count, resend eligibility). Their
			// full_details() needs container services that only register on
			// their admin pages, so every call is Throwable-guarded and the
			// plain row above is the floor.
			try {
				$container = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
				$events    = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::EVENT_MODEL );
				$event     = $events ? $events->get( (int) $row->id ) : null;
				if ( is_array( $event ) && array_key_exists( 'can_resend', $event ) ) {
					$out['can_resend'] = (bool) $event['can_resend'];
				}
				$details = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::LOG_DETAILS_MODEL );
				$full    = $details ? $details->full_details( (int) $row->id ) : array();
				if ( is_array( $full ) && $full ) {
					foreach ( array( 'from', 'cc', 'bcc', 'source' ) as $k ) {
						if ( ! empty( $full[ $k ] ) && is_string( $full[ $k ] ) ) {
							// "Name <addr>" → "Name (addr)": the client
							// strip-tags every raw detail value, which would
							// eat an angle-bracketed address whole.
							$out[ $k ] = trim( str_replace( array( '<', '>' ), array( '(', ')' ), $full[ $k ] ) );
						}
					}
					if ( ! empty( $full['has_attachment'] ) ) {
						$out['attachments'] = (int) $full['has_attachment'];
					}
				}
			} catch ( \Throwable $e ) {
				// The plain columns already shipped.
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/events/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'EDIT_EMAIL_LOG_DETAILS' ),
		'callback'            => 'minn_admin_gravity_smtp_resend',
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/suppressed', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can( 'VIEW_EMAIL_SUPPRESSION_SETTINGS' ),
			'callback'            => function ( WP_REST_Request $request ) {
				// Reads are a prefix-scoped LIKE shim (the DB-shim
				// convention): their model's count() and paginate() DISAGREE
				// on partial search terms (FULLTEXT quirks — count says 1,
				// paginate returns nothing for "bounce"), which breaks the
				// pager and reads as a broken filter. Writes still go
				// through their model.
				global $wpdb;
				$table    = $wpdb->prefix . 'gravitysmtp_suppressed_emails';
				$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
				$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
				$where    = '';
				$args     = array();
				if ( '' !== $search ) {
					$like  = '%' . $wpdb->esc_like( $search ) . '%';
					$where = 'WHERE email LIKE %s OR notes LIKE %s';
					$args  = array( $like, $like );
				}
				$total = (int) $wpdb->get_var( $args
					? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$args ) // phpcs:ignore
					: "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
				$rows  = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, email, reason, notes, date_created FROM {$table} {$where} ORDER BY date_created DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore
					...array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
				), ARRAY_A );
				$items = array_map( function ( $row ) {
					return array(
						'id'           => (int) $row['id'],
						'email'        => (string) $row['email'],
						'reason'       => str_replace( '_', ' ', (string) $row['reason'] ),
						'notes'        => (string) $row['notes'],
						'date_created' => (string) $row['date_created'],
					);
				}, is_array( $rows ) ? $rows : array() );
				return rest_ensure_response( array(
					'items' => $items,
					'total' => $total,
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can( 'EDIT_EMAIL_SUPPRESSION_SETTINGS' ),
			'callback'            => function ( WP_REST_Request $request ) {
				$body  = $request->get_json_params();
				$email = sanitize_email( (string) ( isset( $body['email'] ) ? $body['email'] : '' ) );
				if ( ! is_email( $email ) ) {
					return new WP_Error( 'bad_email', 'Enter a valid email address.', array( 'status' => 400 ) );
				}
				$notes = sanitize_text_field( (string) ( isset( $body['notes'] ) ? $body['notes'] : '' ) );
				$model = new Gravity_Forms\Gravity_SMTP\Models\Suppressed_Emails_Model();
				// 'manually_added' is their manual-suppression reason enum value.
				$model->suppress_email( $email, 'manually_added', $notes );
				return rest_ensure_response( array( 'suppressed' => $email ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/suppressed/(?P<id>\d+)/reactivate', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'EDIT_EMAIL_SUPPRESSION_SETTINGS' ),
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			// Their model keys reactivation by ADDRESS; resolve the row id
			// (prefix-scoped read — the shim convention).
			$email = $wpdb->get_var( $wpdb->prepare(
				"SELECT email FROM {$wpdb->prefix}gravitysmtp_suppressed_emails WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $email ) {
				return new WP_Error( 'not_found', 'Suppressed address not found.', array( 'status' => 404 ) );
			}
			$model = new Gravity_Forms\Gravity_SMTP\Models\Suppressed_Emails_Model();
			$model->reactivate_email( $email );
			return rest_ensure_response( array(
				'reactivated' => $email,
				'message'     => 'Reactivated — Gravity SMTP can send to ' . $email . ' again.',
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/status', array(
		'methods'             => 'GET',
		'permission_callback' => $can( 'VIEW_EMAIL_LOG' ),
		'callback'            => function () {
			$router  = minn_admin_gsmtp_router();
			$primary = minn_admin_gsmtp_primary();
			$title   = ucfirst( $primary );
			foreach ( minn_admin_gsmtp_connector_options() as $opt ) {
				if ( $opt[0] === $primary ) {
					$title = $opt[1];
					break;
				}
			}
			$test_mode = filter_var( $router->get_plugin_setting( 'test_mode', 'false' ), FILTER_VALIDATE_BOOLEAN )
				|| ( class_exists( 'Gravity_Forms\Gravity_SMTP\Utils\Booliesh' )
					&& Gravity_Forms\Gravity_SMTP\Utils\Booliesh::get( $router->get_plugin_setting( 'test_mode', 'false' ) ) );
			$out = array(
				'rows' => array(
					array( 'label' => 'Sending through', 'value' => $title ),
					array(
						'label' => 'Test mode',
						'value' => $test_mode ? 'On' : 'Off',
						'hint'  => $test_mode ? 'Emails are logged, not sent.' : '',
					),
				),
			);
			$out['actions'] = array();
			if ( current_user_can( minn_admin_gsmtp_cap( 'VIEW_TOOLS_SENDATEST' ) ) ) {
				$out['actions'][] = array(
					'label'  => 'Send a test email',
					'route'  => 'minn-admin/v1/gravity-smtp/send-test',
					'method' => 'POST',
					'fields' => array(
						array( 'key' => 'email', 'label' => 'Send to', 'type' => 'email', 'placeholder' => 'you@example.com' ),
					),
				);
			}
			if ( current_user_can( minn_admin_gsmtp_cap( 'VIEW_DEBUG_LOG' ) ) ) {
				// The debug log stays a link-out until surfaces grow a third
				// list view; Suppressions holds the manage slot.
				$out['actions'][] = array(
					'label' => 'Debug log ↗',
					'href'  => admin_url( 'admin.php?page=gravitysmtp-tools' ),
				);
			}
			if ( ! $out['actions'] ) {
				unset( $out['actions'] );
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/send-test', array(
		'methods'             => 'POST',
		'permission_callback' => $can( 'VIEW_TOOLS_SENDATEST' ),
		'callback'            => function ( WP_REST_Request $request ) {
			$body  = $request->get_json_params();
			$email = sanitize_email( (string) ( isset( $body['email'] ) ? $body['email'] : '' ) );
			if ( ! is_email( $email ) ) {
				return new WP_Error( 'bad_email', 'Enter a valid email address.', array( 'status' => 400 ) );
			}
			// Their own Send_Test_Endpoint pattern: force the chosen
			// connector for this send, then let the wp_mail interception
			// route it. (Another active mail plugin owning wp_mail sends it
			// instead — same behavior as their own test screen.)
			$connector = minn_admin_gsmtp_primary();
			add_filter( 'gravitysmtp_connector_for_sending', function () use ( $connector ) {
				return array(
					'force'     => true,
					'connector' => $connector,
				);
			}, 8, 2 );
			// Only ONE mail plugin can own the wp_mail pipeline. If another
			// active mailer (FluentSMTP on a typical multi-mailer site)
			// carries the send, Gravity SMTP never sees it and nothing lands
			// in ITS log — deliverable but invisible here (Austin's repro).
			// Their own Event_Model fires this action when GS records the
			// send; its absence is the honest tell.
			$logged   = 0;
			$listener = function ( $created_id ) use ( &$logged ) {
				$logged = (int) $created_id;
			};
			add_action( 'gravitysmtp_after_mail_created', $listener );
			$sent = wp_mail(
				$email,
				'Test email from Minn Admin (Gravity SMTP)',
				"This is a test email sent through the {$connector} connector, triggered from Minn Admin's Email Log.",
				array()
			);
			remove_action( 'gravitysmtp_after_mail_created', $listener );
			if ( ! $sent ) {
				return new WP_Error( 'send_failed', 'The mailer reported the test could not be sent.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'sent'    => true,
				'logged'  => (bool) $logged,
				'message' => $logged
					? 'Test email sent and logged.'
					: 'Test email sent, but another active mail plugin carried it, so it appears in that plugin’s log, not Gravity SMTP’s.',
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gravity-smtp/settings/(?P<tab>[a-z]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( $request ) {
				return current_user_can( minn_admin_gsmtp_cap(
					'sending' === $request['tab'] ? 'VIEW_INTEGRATIONS' : 'VIEW_GENERAL_SETTINGS'
				) );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				return rest_ensure_response( minn_admin_gsmtp_settings_shape( (string) $request['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $request ) {
				return current_user_can( minn_admin_gsmtp_cap(
					'sending' === $request['tab'] ? 'EDIT_INTEGRATIONS' : 'EDIT_GENERAL_SETTINGS'
				) );
			},
			'callback'            => 'minn_admin_gsmtp_settings_save',
		),
	) );
} );

/** The primary connector's lowercase name ('generic' when unset). */
function minn_admin_gsmtp_primary() {
	$router  = minn_admin_gsmtp_router();
	$primary = $router->get_connector_status_of_type( 'primary' );
	if ( ! $primary ) {
		$primary = $router->get_active_connector();
	}
	return is_string( $primary ) && '' !== $primary ? strtolower( $primary ) : 'generic';
}

/** Registered connector ids => titles, for the sending-service select. */
function minn_admin_gsmtp_connector_options() {
	$out = array();
	try {
		$container  = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
		$registered = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::REGISTERED_CONNECTORS );
		$factory    = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::CONNECTOR_FACTORY );
		foreach ( (array) $registered as $key => $class ) {
			try {
				$c     = $factory->create( $key );
				$name  = strtolower( (string) $key );
				$sf    = $c->settings_fields();
				$title = isset( $sf['title'] ) && $sf['title'] ? (string) $sf['title'] : ucfirst( $name );
				// Their titles are settings-page headings ("SendGrid
				// Settings") — the picker wants the service name.
				$out[] = array( $name, preg_replace( '/\s+Settings$/', '', $title ) );
			} catch ( \Throwable $e ) {
				continue;
			}
		}
	} catch ( \Throwable $e ) {
		return array( array( 'generic', 'Custom SMTP' ) );
	}
	usort( $out, function ( $a, $b ) {
		return strcasecmp( $a[1], $b[1] );
	} );
	return $out;
}

/** GET shape for one settings tab: { groups, values, adminUrl }. */
function minn_admin_gsmtp_settings_shape( $tab ) {
	$router = minn_admin_gsmtp_router();
	$truthy = function ( $v ) {
		if ( class_exists( 'Gravity_Forms\Gravity_SMTP\Utils\Booliesh' ) ) {
			return Gravity_Forms\Gravity_SMTP\Utils\Booliesh::get( $v );
		}
		return filter_var( $v, FILTER_VALIDATE_BOOLEAN );
	};
	if ( 'sending' === $tab ) {
		$primary = minn_admin_gsmtp_primary();
		$groups  = array(
			array(
				'title'  => 'Sending service',
				'fields' => array(
					array(
						'key'     => 'primary_connector',
						'label'   => 'Primary service',
						'type'    => 'combobox',
						'options' => minn_admin_gsmtp_connector_options(),
						'help'    => 'Saving a different service reloads this tab with its settings.',
					),
				),
			),
		);
		$values = array( 'primary_connector' => $primary );
		try {
			$container = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
			$factory   = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::CONNECTOR_FACTORY );
			$connector = $factory->create( $primary );
			$mapped    = minn_admin_gsmtp_map_connector( $connector );
			$sf        = $connector->settings_fields();
			$groups[]  = array(
				'title'  => isset( $sf['title'] ) ? (string) $sf['title'] : ucfirst( $primary ),
				'fields' => $mapped['fields'],
				'locked' => $mapped['locked'],
			);
			$values = array_merge( $values, $mapped['values'] );
		} catch ( \Throwable $e ) {
			$groups[] = array(
				'title'  => ucfirst( $primary ),
				'fields' => array(),
				'locked' => 1,
			);
		}
		return array(
			'groups'   => $groups,
			'values'   => $values,
			'adminUrl' => admin_url( 'admin.php?page=gravitysmtp-settings' ),
		);
	}
	// General: their plugin-level keys (option gravitysmtp_config), read
	// through the router so GRAVITYSMTP_* constants win.
	return array(
		'groups'   => array(
			array(
				'title'  => 'Sending behavior',
				'fields' => array(
					array( 'key' => 'test_mode', 'label' => 'Test mode', 'type' => 'toggle', 'help' => 'Log emails without actually sending them.' ),
				),
			),
			array(
				'title'  => 'Email log',
				'fields' => array(
					array( 'key' => 'event_log_enabled', 'label' => 'Keep an email log', 'type' => 'toggle' ),
					array( 'key' => 'save_email_body_enabled', 'label' => 'Store message bodies', 'type' => 'toggle', 'help' => 'Needed for the detail preview and resend.', 'showWhen' => array( 'key' => 'event_log_enabled', 'equals' => true ) ),
					array( 'key' => 'save_attachments_enabled', 'label' => 'Store attachments', 'type' => 'toggle', 'showWhen' => array( 'key' => 'event_log_enabled', 'equals' => true ) ),
					array( 'key' => 'event_log_retention', 'label' => 'Retention (days)', 'type' => 'number', 'min' => 0, 'help' => '0 keeps the log forever.', 'showWhen' => array( 'key' => 'event_log_enabled', 'equals' => true ) ),
				),
			),
			array(
				'title'  => 'Debug log',
				'fields' => array(
					array( 'key' => 'debug_log_enabled', 'label' => 'Keep a debug log', 'type' => 'toggle' ),
					array( 'key' => 'debug_log_retention', 'label' => 'Retention (days)', 'type' => 'number', 'min' => 0, 'showWhen' => array( 'key' => 'debug_log_enabled', 'equals' => true ) ),
				),
			),
		),
		'values'   => array(
			'test_mode'                => $truthy( $router->get_plugin_setting( 'test_mode', 'false' ) ),
			'event_log_enabled'        => $truthy( $router->get_plugin_setting( 'event_log_enabled', 'true' ) ),
			'save_email_body_enabled'  => $truthy( $router->get_plugin_setting( 'save_email_body_enabled', 'true' ) ),
			'save_attachments_enabled' => $truthy( $router->get_plugin_setting( 'save_attachments_enabled', 'false' ) ),
			'event_log_retention'      => (int) $router->get_plugin_setting( 'event_log_retention', 7 ),
			'debug_log_enabled'        => $truthy( $router->get_plugin_setting( 'debug_log_enabled', 'false' ) ),
			'debug_log_retention'      => (int) $router->get_plugin_setting( 'debug_log_retention', 7 ),
		),
		'adminUrl' => admin_url( 'admin.php?page=gravitysmtp-settings' ),
	);
}

/** POST: write one tab's changed values through their own stores. */
function minn_admin_gsmtp_settings_save( WP_REST_Request $request ) {
	$tab  = (string) $request['tab'];
	$body = $request->get_json_params();
	$vals = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
	if ( 'sending' === $tab ) {
		$opts    = new Gravity_Forms\Gravity_SMTP\Data_Store\Opts_Data_Store();
		$plugin  = new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store();
		$current = minn_admin_gsmtp_primary();
		$target  = $current;
		if ( isset( $vals['primary_connector'] ) ) {
			$next = sanitize_key( (string) $vals['primary_connector'] );
			unset( $vals['primary_connector'] );
			if ( $next && $next !== $current ) {
				// Mirror their Save_Connector_Settings_Endpoint semantics:
				// the config maps hold ONE truthy primary/enabled entry and
				// the connector's own option mirrors the flags.
				$plugin->save( 'primary_connector', array( $next => true ) );
				$plugin->save( 'enabled_connector', array( $next => true ) );
				$opts->save( 'is_primary', true, $next );
				$opts->save( 'enabled', true, $next );
				$opts->save( 'is_primary', false, $current );
				$target = $next;
			}
		}
		if ( $vals ) {
			// Their store: skips the '****************' sentinel, coerces
			// 'true'/'false' strings — the masked-secret contract for free.
			$opts->save_all( $vals, $target );
		}
		delete_transient( 'gsmtp_connector_configured_' . $target );
		return rest_ensure_response( minn_admin_gsmtp_settings_shape( 'sending' ) );
	}
	$allowed = array( 'test_mode', 'event_log_enabled', 'save_email_body_enabled', 'save_attachments_enabled', 'event_log_retention', 'debug_log_enabled', 'debug_log_retention' );
	$plugin  = new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store();
	foreach ( $vals as $k => $v ) {
		if ( ! in_array( $k, $allowed, true ) ) {
			continue;
		}
		if ( in_array( $k, array( 'event_log_retention', 'debug_log_retention' ), true ) ) {
			$v = max( 0, (int) $v );
		} elseif ( is_bool( $v ) ) {
			$v = $v ? 'true' : 'false'; // their stored convention
		}
		$plugin->save( $k, $v );
	}
	return rest_ensure_response( minn_admin_gsmtp_settings_shape( 'general' ) );
}

/**
 * Resend through Gravity SMTP's own flow — a faithful mirror of their
 * Resend_Email_Endpoint::handle(): hydrated event, allowlisted unserialize
 * (their two Recipient classes only, exactly their allowlist), original
 * headers and attachments, wp_mail() so their Mail_Handler routes it
 * through the configured connector. Falls back to the regex recipient
 * extraction if the blob doesn't parse.
 */
function minn_admin_gravity_smtp_resend( WP_REST_Request $request ) {
	global $wpdb;
	$table = $wpdb->prefix . 'gravitysmtp_events';
	$row   = $wpdb->get_row( $wpdb->prepare(
		"SELECT id, subject, message, extra FROM {$table} WHERE id = %d", // phpcs:ignore
		(int) $request['id']
	) );
	if ( ! $row ) {
		return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
	}

	$to          = null;
	$headers     = array();
	$attachments = array();
	try {
		$container = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
		$events    = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::EVENT_MODEL );
		$event     = $events ? $events->get( (int) $row->id ) : null;
		if ( is_array( $event ) && array_key_exists( 'can_resend', $event ) && ! $event['can_resend'] ) {
			return new WP_Error( 'cannot_resend', 'Gravity SMTP reports this email cannot be resent (body or attachments were not stored).', array( 'status' => 422 ) );
		}
		$extra = unserialize( // phpcs:ignore -- their own endpoint's exact allowlist on their own data.
			(string) $row->extra,
			array(
				'allowed_classes' => array(
					Gravity_Forms\Gravity_SMTP\Utils\Recipient_Collection::class,
					Gravity_Forms\Gravity_SMTP\Utils\Recipient::class,
				),
			)
		);
		if ( is_array( $extra ) ) {
			if ( isset( $extra['to'] ) && is_object( $extra['to'] ) ) {
				$to = $extra['to'];
			}
			if ( isset( $extra['headers'] ) && is_array( $extra['headers'] ) ) {
				$headers = $extra['headers'];
				// Their endpoint flattens Recipient_Collections in headers
				// to comma-joined address strings before sending.
				foreach ( $headers as $hk => $hv ) {
					if ( is_object( $hv ) && method_exists( $hv, 'as_string' ) ) {
						$headers[ $hk ] = $hv->as_string();
					} elseif ( is_object( $hv ) ) {
						unset( $headers[ $hk ] );
					}
				}
			}
			if ( isset( $extra['source'] ) && is_string( $extra['source'] ) ) {
				$headers['source'] = $extra['source'];
			}
			foreach ( (array) ( isset( $extra['attachments'] ) ? $extra['attachments'] : array() ) as $path ) {
				if ( is_string( $path ) && file_exists( $path ) ) {
					$attachments[] = $path;
				}
			}
		}
	} catch ( \Throwable $e ) {
		$to = null; // fall through to the regex path
	}

	if ( ! $to ) {
		$addresses = array_filter( minn_admin_gravity_smtp_to_addresses( $row->extra ), 'is_email' );
		if ( ! $addresses ) {
			return new WP_Error( 'no_recipients', 'No recipient address on record for this email.', array( 'status' => 422 ) );
		}
		$to      = $addresses;
		$is_html = (bool) preg_match( '/<\/?[a-z][\s\S]*>/i', (string) $row->message );
		$headers = $is_html ? array( 'Content-Type: text/html; charset=UTF-8' ) : array();
	}

	// Same tell as send-test: if another active mailer carries the resend,
	// Gravity SMTP records nothing and the log won't show a new row.
	$logged   = 0;
	$listener = function ( $created_id ) use ( &$logged ) {
		$logged = (int) $created_id;
	};
	add_action( 'gravitysmtp_after_mail_created', $listener );
	$sent = wp_mail( $to, (string) $row->subject, (string) $row->message, $headers, $attachments );
	remove_action( 'gravitysmtp_after_mail_created', $listener );
	if ( ! $sent ) {
		return new WP_Error( 'send_failed', 'The mailer reported the message could not be sent.', array( 'status' => 500 ) );
	}
	return rest_ensure_response( array(
		'resent'  => true,
		'logged'  => (bool) $logged,
		'message' => $logged
			? 'Resent to the original recipients.'
			: 'Resent, but another active mail plugin carried it, so the new send appears in that plugin’s log, not Gravity SMTP’s.',
	) );
}

/**
 * Pull recipient email addresses out of the serialized `extra` blob without
 * unserializing it (PHP object injection would be a vulnerability here).
 */
function minn_admin_gravity_smtp_recipients( $extra ) {
	$emails = minn_admin_gravity_smtp_to_addresses( $extra );
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
 * The full To list from `extra`, scoped to the `to` Recipient_Collection so
 * cc/bcc/reply-to addresses are never treated as To recipients (Resend would
 * otherwise expose them in the To header).
 */
function minn_admin_gravity_smtp_to_addresses( $extra ) {
	if ( ! $extra ) {
		return array();
	}
	// The `to` collection ends at the first `}}}` (recipient → array → collection).
	if ( preg_match( '/s:2:"to";O:\d+:"[^"]*Recipient_Collection":\d+:\{.*?\}\}\}/s', $extra, $m ) ) {
		if ( preg_match_all( '/s:5:"email";s:\d+:"([^"]+)"/', $m[0], $mm ) ) {
			return array_values( array_unique( $mm[1] ) );
		}
		return array();
	}
	// Some write paths store `to` as a plain address string instead of a
	// Recipient_Collection (Event_Model::create keeps whatever the caller
	// passed) — accept that shape too.
	if ( preg_match( '/s:2:"to";s:\d+:"([^"]+)"/', $extra, $m ) && is_email( $m[1] ) ) {
		return array( $m[1] );
	}
	if ( preg_match_all( '/s:5:"email";s:\d+:"([^"]+)"/', $extra, $m ) ) {
		return array_values( array_unique( $m[1] ) );
	}
	return array();
}
