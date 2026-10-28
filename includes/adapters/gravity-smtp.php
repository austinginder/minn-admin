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
 * Email Routing (2.3.0+, feature flag smart_routing): recipes live in
 * plugin option routing_settings. Minn lists them (name, connector,
 * enabled, condition summary), toggles enable, and deletes through the
 * same store their Save_Routing_Settings_Endpoint uses. Building /
 * testing condition trees stays on their React screen (admin.php?page=
 * gravitysmtp-settings&tab=routing) — the condition editor is not a
 * surface-field vocabulary fit. The log also knows their 2.3.0
 * partially-sent status ("Filtered": suppressed recipients stripped).
 *
 * The log list searches the same fields Gravity SMTP's own
 * Event_Model::get_search_clause default does (subject, message, extra)
 * plus the service column, so an address, a subject, a sending plugin
 * (inside extra), a body phrase or a connector slug all hit. extra stays
 * serialized; LIKE matches the text inside the blob. A Source filter
 * loads origins on demand from their get_all_sending_sources (not at
 * boot) and narrows with the same extra RLIKE their SQL_Filter_Parser
 * uses for non-column keys.
 *
 * Log delete (single + bulk) goes through Event_Model::delete() — the
 * same model their Delete_Email_Endpoint / Delete_Events_Endpoint use —
 * gated on DELETE_EMAIL_LOG. Permanent; no trash. A routing rule's id is
 * drawn from the rule itself (minn_admin_gsmtp_routing_ids()); its place in
 * their list is the # column.
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
 * Who may send a test email: Gravity SMTP's own Send a Test capability (its
 * endpoint's) and a settings editor's, since the mail goes to any address.
 *
 * @return bool
 */
function minn_admin_gsmtp_can_send_test() {
	return current_user_can( minn_admin_gsmtp_cap( 'VIEW_TOOLS_SENDATEST' ) ) && current_user_can( minn_admin_gsmtp_cap( 'EDIT_GENERAL_SETTINGS' ) );
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

/**
 * May this user read a logged message body?
 *
 * Gravity SMTP splits the email log in two: view_email_log_details opens the
 * delivery metadata, and view_email_log_preview opens the MESSAGE. Their own
 * details model never returns the body, and both their preview button and their
 * resend button gate on the preview cap. The split exists so a site can hand an
 * operator a delivery log without handing them the mail itself, which routinely
 * carries a live password reset link.
 *
 * @return bool
 */
function minn_admin_gsmtp_can_preview() {
	return current_user_can( minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG_PREVIEW' ) );
}

/** Their data-store router (constant locks first, then options). */
function minn_admin_gsmtp_router() {
	return new Gravity_Forms\Gravity_SMTP\Data_Store\Data_Store_Router(
		new Gravity_Forms\Gravity_SMTP\Data_Store\Const_Data_Store(),
		new Gravity_Forms\Gravity_SMTP\Data_Store\Opts_Data_Store(),
		new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store()
	);
}

/** Plugin opts store (gravitysmtp_config JSON) — where routing_settings lives. */
function minn_admin_gsmtp_plugin_opts() {
	return new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store();
}

/** Email Routing (2.3.0+) is behind their smart_routing feature flag. */
function minn_admin_gsmtp_routing_available() {
	if ( ! class_exists( 'Gravity_Forms\Gravity_SMTP\Feature_Flags\Feature_Flag_Manager' ) ) {
		return false;
	}
	return (bool) Gravity_Forms\Gravity_SMTP\Feature_Flags\Feature_Flag_Manager::is_enabled( 'smart_routing' );
}

/** Deep link into their React routing screen. */
function minn_admin_gsmtp_routing_admin_url() {
	return admin_url( 'admin.php?page=gravitysmtp-settings&tab=routing' );
}

/**
 * Routing recipes as stored by Save_Routing_Settings_Endpoint
 * (option key routing_settings inside gravitysmtp_config).
 *
 * @return array
 */
function minn_admin_gsmtp_routing_recipes() {
	$store = minn_admin_gsmtp_plugin_opts();
	// Their Config reads get( key, 'config', default ); Plugin_Opts::get
	// signature is get( $setting_name, $connector = 'config', $default ).
	$settings = $store->get( 'routing_settings', 'config', array() );
	if ( ! is_array( $settings ) ) {
		// Router path (constants first) as a fallback.
		$settings = minn_admin_gsmtp_router()->get_plugin_setting( 'routing_settings', array() );
	}
	return is_array( $settings ) ? array_values( $settings ) : array();
}

/**
 * Persist recipes through their own plugin-opts store (same as their
 * save_routing_settings ajax endpoint).
 *
 * @param array $recipes
 */
function minn_admin_gsmtp_routing_save( array $recipes ) {
	minn_admin_gsmtp_plugin_opts()->save( 'routing_settings', array_values( $recipes ) );
}

/** Human label for a connector slug from their live data map. */
function minn_admin_gsmtp_connector_title( $slug ) {
	$slug = (string) $slug;
	if ( '' === $slug ) {
		return '—';
	}
	try {
		$container = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
		$map       = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::CONNECTOR_DATA_MAP );
		if ( is_array( $map ) && ! empty( $map[ $slug ]['title'] ) ) {
			return (string) $map[ $slug ]['title'];
		}
	} catch ( \Throwable $e ) {
		// Fall through.
	}
	return $slug;
}

/**
 * One-line condition summary for a recipe (not a full reimplementation of
 * their Settings_to_Conditionals_Parser — just enough to scan a list).
 *
 * @param array $conditions
 * @return string
 */
function minn_admin_gsmtp_condition_summary( $conditions ) {
	if ( ! is_array( $conditions ) ) {
		return '—';
	}
	$labels = array(
		'subject'          => 'Subject',
		'message'          => __( 'Message', 'minn-admin' ),
		'from_email'       => 'From',
		'from_name'        => __( 'From name', 'minn-admin' ),
		'to'               => 'To',
		'cc'               => 'CC',
		'bcc'              => 'BCC',
		'reply_to'         => 'Reply-To',
		'source'           => 'Source',
		'source_subtype'   => __( 'Source type', 'minn-admin' ),
		'to_count'         => __( 'To count', 'minn-admin' ),
		'cc_count'         => __( 'CC count', 'minn-admin' ),
		'bcc_count'        => __( 'BCC count', 'minn-admin' ),
		'has_attachments'  => __( 'Has attachments', 'minn-admin' ),
		'attachments_count'=> 'Attachments',
		'content_type'     => __( 'Content type', 'minn-admin' ),
		'message_size'     => __( 'Message size', 'minn-admin' ),
		'attachment_size'  => __( 'Attachment size', 'minn-admin' ),
	);
	$comps = array(
		'='                 => 'is',
		'!='                => __( 'is not', 'minn-admin' ),
		'>'                 => '>',
		'>='                => '≥',
		'<'                 => '<',
		'<='                => '≤',
		'contains'          => 'contains',
		'does_not_contain'  => __( 'does not contain', 'minn-admin' ),
		'starts_with'       => __( 'starts with', 'minn-admin' ),
		'ends_with'         => __( 'ends with', 'minn-admin' ),
	);
	$parts = array();
	$walk  = function ( $node ) use ( &$walk, &$parts, $labels, $comps ) {
		if ( ! is_array( $node ) ) {
			return;
		}
		// Leaf condition.
		if ( isset( $node['email_field'], $node['comparator'] ) ) {
			$field = $labels[ $node['email_field'] ] ?? $node['email_field'];
			$comp  = $comps[ $node['comparator'] ] ?? $node['comparator'];
			$val   = isset( $node['value'] ) ? (string) $node['value'] : '';
			if ( ! empty( $node['regex'] ) ) {
				$val .= ' (regex)';
			}
			$parts[] = trim( $field . ' ' . $comp . ' ' . $val );
			return;
		}
		// Group with nested rules.
		if ( ! empty( $node['rules'] ) && is_array( $node['rules'] ) ) {
			foreach ( $node['rules'] as $child ) {
				$walk( $child );
			}
		}
	};
	$walk( $conditions );
	if ( ! $parts ) {
		return __( 'No conditions', 'minn-admin' );
	}
	// Cap for list display.
	$shown = array_slice( $parts, 0, 3 );
	$out   = implode( ' · ', $shown );
	if ( count( $parts ) > 3 ) {
		$out .= ' · +' . ( count( $parts ) - 3 ) . ' more';
	}
	return $out;
}

/**
 * Each routing rule's id, by position: their recipes carry none, and a
 * position names another rule once one is added or removed in their screen
 * or another tab, so the id is drawn from what the rule is (its name,
 * service and conditions; not its on/off switch, which the actions change),
 * and which copy of it, where a rule was added twice.
 *
 * @param array $recipes Their stored recipes.
 * @return int[] Position => id.
 */
function minn_admin_gsmtp_routing_ids( array $recipes ) {
	$ids  = array();
	$seen = array();
	foreach ( $recipes as $i => $recipe ) {
		if ( ! is_array( $recipe ) ) {
			continue;
		}
		unset( $recipe['enabled'] );
		$body          = (string) wp_json_encode( $recipe );
		$seen[ $body ] = ( $seen[ $body ] ?? -1 ) + 1;
		$ids[ $i ]     = 1 + ( crc32( $body . '#' . $seen[ $body ] ) % 2147483646 );
	}
	return $ids;
}

/** The position of the rule with this id, or null. */
function minn_admin_gsmtp_routing_index( array $recipes, $id ) {
	$at = array_search( (int) $id, minn_admin_gsmtp_routing_ids( $recipes ), true );
	return false === $at ? null : $at;
}

/** Display rows for the Routing list view, each under its rule's own id. */
function minn_admin_gsmtp_routing_rows() {
	$recipes = minn_admin_gsmtp_routing_recipes();
	$ids     = minn_admin_gsmtp_routing_ids( $recipes );
	$items   = array();
	foreach ( $recipes as $i => $recipe ) {
		if ( ! is_array( $recipe ) ) {
			continue;
		}
		$enabled = true;
		if ( array_key_exists( 'enabled', $recipe ) ) {
			$enabled = class_exists( 'Gravity_Forms\Gravity_SMTP\Utils\Booliesh' )
				? (bool) Gravity_Forms\Gravity_SMTP\Utils\Booliesh::get( $recipe['enabled'] )
				: (bool) $recipe['enabled'];
		}
		$connector = isset( $recipe['connector'] ) ? (string) $recipe['connector'] : '';
		$items[]   = array(
			'id'         => $ids[ $i ],
			'name'       => isset( $recipe['name'] ) && $recipe['name'] !== ''
				? (string) $recipe['name']
				: ( 'Rule ' . ( $i + 1 ) ),
			'provider'   => minn_admin_gsmtp_connector_title( $connector ),
			'connector'  => $connector,
			'enabled'    => $enabled ? 'on' : 'off',
			'conditions' => minn_admin_gsmtp_condition_summary( $recipe['conditions'] ?? array() ),
			'order'      => $i + 1,
		);
	}
	return $items;
}

/** How many connectors are currently enabled (routing needs ≥ 2). */
function minn_admin_gsmtp_active_connector_count() {
	try {
		$container = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
		$map       = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::CONNECTOR_DATA_MAP );
		if ( ! is_array( $map ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $map as $entry ) {
			if ( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Base::is_data_map_entry_active( $entry ) ) {
				$count++;
			}
		}
		return $count;
	} catch ( \Throwable $e ) {
		return 0;
	}
}

minn_admin_add_surface( 'gravity-smtp', 'minn_admin_gravity_smtp_active', function () {
	return minn_admin_mail_surface( 'gravity-smtp/events', array(
		'sub'        => 'Gravity SMTP',
		'plugin'     => 'gravitysmtp',
		'cap'        => minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG' ),
		'status'     => 'gravity-smtp/status',
		// Event timestamps are UTC MySQL datetimes (no zone suffix).
		'date'       => 'date_created',
		'utc'        => true,
		// 2.3.0: suppressed recipients stripped from to/cc/bcc.
		'tabs'       => array( 'sent' => 'Sent', 'failed' => 'Failed', 'sandboxed' => 'Sandboxed', 'partially-sent' => 'Filtered' ),
		'resend'     => array( 'key' => 'can_resend', 'when' => true ),
		'columns'    => array(
			array( 'key' => 'source', 'label' => __( 'Source', 'minn-admin' ), 'width' => '128px' ),
			array( 'key' => 'service', 'label' => __( 'Service', 'minn-admin' ), 'width' => '120px' ),
		),
		'collection' => array(
			'viewLabel' => __( 'Log', 'minn-admin' ),
			// Origins live in extra and grow as plugins send mail, so the
			// list is fetched on demand (filter.route) rather than at boot.
			'filter'    => array(
				'label'    => __( 'Source', 'minn-admin' ),
				'route'    => 'minn-admin/v1/gravity-smtp/sources',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'allLabel' => __( 'All sources', 'minn-admin' ),
				'query'    => 'source={v}',
			),
		),
		'surface'    => array(
			// Suppressed addresses Gravity SMTP refuses to send to — list, add,
			// reactivate through their own model. Reads/writes gate on their
			// granular suppression caps server-side.
			'manage'     => Minn_Admin_Kernel::collection( 'gravity-smtp/suppressed', array(
				'viewLabel' => __( 'Suppressions', 'minn-admin' ),
				'columns'   => array(
					array( 'key' => 'email', 'label' => __( 'Email', 'minn-admin' ), 'format' => 'title' ),
					array( 'key' => 'reason', 'label' => __( 'Reason', 'minn-admin' ), 'format' => 'pill' ),
					array( 'key' => 'notes', 'label' => __( 'Notes', 'minn-admin' ) ),
					// Their model stamps current_time( 'mysql', true ) — UTC.
					array( 'key' => 'date_created', 'label' => __( 'Since', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
				),
				'detail'    => array(),
				'create'    => array(
					'label'  => __( 'Suppress email', 'minn-admin' ),
					'route'  => 'minn-admin/v1/gravity-smtp/suppressed',
					'fields' => array(
						array( 'key' => 'email', 'label' => __( 'Email address', 'minn-admin' ), 'type' => 'email' ),
						array( 'key' => 'notes', 'label' => __( 'Notes', 'minn-admin' ), 'required' => false, 'placeholder' => __( 'Why sending to this address is off', 'minn-admin' ) ),
					),
				),
				'actions'   => array(
					Minn_Admin_Kernel::ui( 'gravity-smtp/suppressed', 'reactivate', array( 'label' => __( 'Reactivate (allow sending again)', 'minn-admin' ), 'confirm' => __( 'Allow Gravity SMTP to send to this address again?', 'minn-admin' ) ) ),
				),
			) ),
			// Extra list views beyond collection/manage. The debug log lived as a
			// wp-admin link-out on the status card until surfaces grew this slot.
			'views'      => array_values( array_filter( array(
				Minn_Admin_Kernel::collection( 'gravity-smtp/debug', array(
					'viewLabel' => __( 'Debug log', 'minn-admin' ),
					'cap'       => minn_admin_gsmtp_cap( 'VIEW_DEBUG_LOG' ),
					'tabs'      => array(
						'param'    => 'priority',
						'static'   => array(
							array( 'error', 'Errors' ),
							array( 'warning', 'Warnings' ),
							array( 'info', 'Info' ),
							array( 'debug', 'Debug' ),
						),
						'allLabel' => 'All',
					),
					'columns'   => array(
						array( 'key' => 'line', 'label' => __( 'Message', 'minn-admin' ), 'format' => 'title' ),
						array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'format' => 'pill' ),
						// Their Debug_Log_Model stamps current_time( 'mysql', true ) — UTC.
						array( 'key' => 'date_created', 'label' => __( 'Date', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
					),
					'detail'    => array(),
				) ),
				// Email Routing (2.3.0+). List + enable/disable/delete only —
				// the condition builder stays on their React screen.
				minn_admin_gsmtp_routing_available() ? Minn_Admin_Kernel::collection( 'gravity-smtp/routing', array(
					'viewLabel' => __( 'Routing', 'minn-admin' ),
					'cap'       => minn_admin_gsmtp_cap( 'VIEW_ROUTING' ),
					'columns'   => array(
						array( 'key' => 'order', 'label' => '#', 'format' => 'text', 'width' => '48px' ),
						array( 'key' => 'name', 'label' => __( 'Rule', 'minn-admin' ), 'format' => 'title' ),
						array( 'key' => 'provider', 'label' => __( 'Send with', 'minn-admin' ), 'format' => 'text' ),
						array( 'key' => 'conditions', 'label' => __( 'When', 'minn-admin' ), 'format' => 'text' ),
						array( 'key' => 'enabled', 'label' => __( 'Enabled', 'minn-admin' ), 'format' => 'pill' ),
					),
					'detail'    => array(
						'skip' => array( 'order', 'connector' ),
					),
					'actions'   => array(
						Minn_Admin_Kernel::ui( 'gravity-smtp/routing', 'enable', array( 'label' => __( 'Enable', 'minn-admin' ), 'key' => 'enabled', 'when' => 'off' ) ),
						Minn_Admin_Kernel::ui( 'gravity-smtp/routing', 'disable', array( 'label' => __( 'Disable', 'minn-admin' ), 'key' => 'enabled', 'when' => 'on' ) ),
						Minn_Admin_Kernel::ui( 'gravity-smtp/routing', 'delete', array( 'label' => __( 'Delete rule', 'minn-admin' ), 'confirm' => __( 'Delete this routing rule permanently?', 'minn-admin' ), 'danger' => true ) ),
						array(
							'label' => __( 'Edit in Gravity SMTP ↗', 'minn-admin' ),
							'href'  => minn_admin_gsmtp_routing_admin_url(),
						),
					),
				) ) : null,
			) ) ),
			'settings'   => array(
				'cap'   => minn_admin_gsmtp_cap( 'VIEW_GENERAL_SETTINGS' ),
				'tabs'  => array(
					array( 'id' => 'sending', 'label' => __( 'Sending', 'minn-admin' ) ),
					array( 'id' => 'general', 'label' => __( 'General', 'minn-admin' ) ),
				),
				'route' => 'minn-admin/v1/gravity-smtp/settings/{tab}',
			),
		),
	) );
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
					// Fail CLOSED. get_sensitive_fields() is optional, so a connector that
		// does not implement it yielded an empty list and every value — SMTP
		// password, SendGrid/Mailgun/SES/Postmark api_key — was echoed
		// verbatim. Treat password-typed and secret-looking names as sensitive
		// regardless. Over-masking is round-trip safe: the save path already
		// skips the sentinel.
		$is_secret = in_array( $name, $sensitive, true )
			|| ( isset( $props['type'] ) && 'password' === $props['type'] )
			|| preg_match( '/(pass|secret|api[_-]?key|token|credential)/i', (string) $name );
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

/** A gate on one of Gravity SMTP's own capabilities, by Roles constant name. */
function minn_admin_gsmtp_gate( $const ) {
	return function () use ( $const ) {
		return current_user_can( minn_admin_gsmtp_cap( $const ) );
	};
}

/** Their EVENT_MODEL, or null where their container has not registered it (it does on their admin screens). */
function minn_admin_gsmtp_events_model() {
	try {
		if ( class_exists( 'Gravity_Forms\Gravity_SMTP\Gravity_SMTP' ) ) {
			return Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container()->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::EVENT_MODEL );
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Their model only registers on their admin screens.
	}
	return null;
}

/** Whether their EVENT_MODEL says an event can be resent; true when it cannot say. */
function minn_admin_gsmtp_can_resend( $events, $id ) {
	try {
		$event = $events ? $events->get( (int) $id ) : null;
	} catch ( \Throwable $e ) {
		$event = null;
	}
	return is_array( $event ) && array_key_exists( 'can_resend', $event ) ? (bool) $event['can_resend'] : true;
}

/**
 * From, reply-to, cc, bcc, source and the attachment count, parsed by their
 * own log-details model. Its services only register on their admin pages,
 * so a failure is an empty array and the plain columns stand alone.
 */
function minn_admin_gsmtp_full_details( $id ) {
	try {
		$details = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container()->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::LOG_DETAILS_MODEL );
		$full    = $details ? $details->full_details( (int) $id ) : array();
	} catch ( \Throwable $e ) {
		$full = array();
	}
	$out = array();
	if ( is_array( $full ) ) {
		foreach ( array( 'from', 'reply_to', 'cc', 'bcc', 'source' ) as $k ) {
			if ( ! empty( $full[ $k ] ) && is_string( $full[ $k ] ) ) {
				// "Name <addr>" → "Name (addr)": the client strip-tags every
				// raw detail value, which would eat an angle-bracketed address.
				$out[ $k ] = trim( str_replace( array( '<', '>' ), array( '(', ')' ), $full[ $k ] ) );
			}
		}
		if ( ! empty( $full['has_attachment'] ) ) {
			$out['attachments'] = (int) $full['has_attachment'];
		}
	}
	return $out;
}

/**
 * One event's sections: status pill, linked addresses, the HTML body in the
 * sandboxed html-preview row and the stored headers as a kv-table.
 */
function minn_admin_gsmtp_event_view( array $r ) {
	$delivery = array(
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $r['status'], 'type' => 'pill' ),
		array( 'label' => __( 'To', 'minn-admin' ), 'value' => minn_admin_gravity_smtp_recipients( $r['extra'] ) ),
	);
	$full = minn_admin_gsmtp_full_details( $r['id'] );
	// reply_to is stored per event since 2.3.4; older rows simply lack it.
	foreach ( array( 'from' => 'From', 'reply_to' => 'Reply-To', 'cc' => 'Cc', 'bcc' => 'Bcc', 'source' => 'Source' ) as $k => $label ) {
		if ( isset( $full[ $k ] ) ) {
			$delivery[] = array( 'label' => $label, 'value' => $full[ $k ] );
		}
	}
	if ( ! empty( $full['attachments'] ) ) {
		$delivery[] = array( 'label' => __( 'Attachments', 'minn-admin' ), 'value' => (string) $full['attachments'] );
	}
	$delivery[] = array( 'label' => __( 'Service', 'minn-admin' ), 'value' => (string) $r['service'] );
	$delivery[] = array( 'label' => __( 'Date', 'minn-admin' ), 'value' => $r['date_created'] . ' UTC' );

	// Stored headers ride the serialized `extra` blob. Regex-decode only
	// (never unserialize third-party blobs): plain string headers alone.
	$headers = array();
	if ( is_string( $r['extra'] ) && preg_match( '/"headers";a:\d+:\{(.*)$/s', $r['extra'], $m ) ) {
		if ( preg_match_all( '/s:\d+:"([^"]{1,64})";s:\d+:"([^"]{0,512})";/', $m[1], $pairs, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $pairs, 0, 30 ) as $p ) {
				$headers[ $p[1] ] = $p[2];
			}
		}
	}
	$more = $headers ? array( array( 'title' => __( 'Headers', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Stored headers', 'minn-admin' ), 'value' => $headers, 'type' => 'kv-table' ) ) ) ) : array();
	return minn_admin_mail_sections( $delivery, $r['subject'], minn_admin_gsmtp_can_preview() ? (string) $r['message'] : null, $more );
}

// date_created is UTC (current_time( 'mysql', true )), and a chart bar hands
// back its site day's bounds in that same shape, so the list compares them as
// they come (their own events route always has).
minn_admin_resource( 'gravity-smtp/events', function () {
	return array(
		'active'  => 'minn_admin_gravity_smtp_active',
		'gates'   => array(
			'view'   => minn_admin_gsmtp_gate( 'VIEW_EMAIL_LOG' ),
			'detail' => minn_admin_gsmtp_gate( 'VIEW_EMAIL_LOG_DETAILS' ),
			'delete' => minn_admin_gsmtp_gate( 'DELETE_EMAIL_LOG' ),
			// Their resend button carries the preview cap, not just the edit
			// cap: a principal who may not read the message may not
			// rebroadcast it either.
			'resend' => function () {
				return current_user_can( minn_admin_gsmtp_cap( 'EDIT_EMAIL_LOG_DETAILS' ) ) && minn_admin_gsmtp_can_preview();
			},
			// The raw tables hold every message body, which the detail
			// shows only to the preview cap: the database browser shows
			// them only to someone who holds it too, and who reads mail
			// whole (the browser cannot hide a reset link's key).
			'table'  => function () {
				return minn_admin_gsmtp_can_preview() && minn_admin_mail_bodies_open();
			},
		),
		'labels'  => array( 'not_found' => __( 'Event not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'gravitysmtp_events',
			'key'     => 'id',
			'columns' => array( 'id', 'date_created', 'status', 'service', 'subject', 'extra' ),
			'detail'  => array( 'id', 'date_created', 'status', 'service', 'subject', 'message', 'extra' ),
			// Each send's log lines.
			'tables'  => array( 'gravitysmtp_event_logs' ),
		),
		'list'    => array(
			'facets' => array(
				array( 'param' => 'status', 'column' => 'status', 'type' => 'key' ),
				array( 'param' => 'service', 'column' => 'service', 'type' => 'key' ),
				// The same extra-blob match their SQL_Filter_Parser makes for
				// keys that are not columns: serialized or JSON.
				array( 'param' => 'source', 'values' => function ( $source ) {
					return array( 'extra' => array( 'any' => array(
						array( 'contains' => '"source";s:' . strlen( $source ) . ':"' . $source . '"' ),
						array( 'contains' => '"source":"' . $source . '"' ),
					) ) );
				} ),
			),
			// Their Event_Model::get_search_clause default (subject, message,
			// extra) plus the service. Matching bodies would let a caller who
			// may not open a message probe it a substring at a time (a reset
			// link, a code), so only a preview-capable caller searches them.
			'search' => array(
				'columns' => array( 'subject', 'extra', 'service' ),
				'also'    => function ( $term ) {
					// Nor on a network for anyone but its owner: a body search finds a
					// reset key the page hides.
					return minn_admin_gsmtp_can_preview() && minn_admin_mail_bodies_open() ? array( 'message' => array( 'contains' => $term ) ) : null;
				},
			),
			'range'  => array( 'column' => 'date_created', 'clock' => 'local' ),
			'order'  => array( 'column' => 'id' ),
			// Resend eligibility from their own EVENT_MODEL, once a page: the
			// Resend action's when-gate reads it off the LIST row (the detail
			// is a sectionsRoute, which never updates the modal item).
			'batch'  => function ( array $rows ) {
				$events = minn_admin_gsmtp_events_model();
				$map    = array();
				foreach ( $rows as $row ) {
					$map[ (int) $row['id'] ] = minn_admin_gsmtp_can_resend( $events, $row['id'] );
				}
				return $map;
			},
			'row'    => function ( array $r, array $q ) {
				static $titles = array();
				$slug = (string) $r['service'];
				if ( ! isset( $titles[ $slug ] ) ) {
					$titles[ $slug ] = minn_admin_gsmtp_connector_title( $slug );
				}
				return array(
					'id'           => (int) $r['id'],
					'date_created' => $r['date_created'],
					'status'       => $r['status'],
					'service'      => $titles[ $slug ],
					'source'       => minn_admin_gravity_smtp_source( $r['extra'] ),
					'subject'      => $r['subject'],
					'to'           => minn_admin_gravity_smtp_recipients( $r['extra'] ),
					'can_resend'   => $q['batch'][ (int) $r['id'] ] ?? true,
				);
			},
			'fields' => array(
				'id'           => 'int',
				'date_created' => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'sql' ),
				'status'       => 'text',
				'service'      => 'text',
				'source'       => 'text',
				'subject'      => 'text',
				'to'           => 'text',
				'can_resend'   => 'bool',
			),
		),
		// The flat item stays for API consumers; the app reads the sections.
		'item'    => array(
			'gate'   => 'detail',
			'view'   => function ( array $r ) {
				$out = array(
					'id'           => (int) $r['id'],
					'subject'      => $r['subject'],
					'to'           => minn_admin_gravity_smtp_recipients( $r['extra'] ),
					'status'       => $r['status'],
					'service'      => $r['service'],
					'date_created' => $r['date_created'],
					'can_resend'   => minn_admin_gsmtp_can_resend( minn_admin_gsmtp_events_model(), $r['id'] ),
				);
				if ( minn_admin_gsmtp_can_preview() ) {
					$out['message'] = minn_admin_mail_mask_reset_keys( (string) $r['message'] );
				}
				return array_merge( $out, minn_admin_gsmtp_full_details( $r['id'] ) );
			},
			'fields' => array(
				'id'           => 'int',
				'subject'      => 'text',
				'to'           => 'text',
				'status'       => 'text',
				'service'      => 'text',
				'date_created' => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'sql' ),
				'can_resend'   => 'bool',
				'message'      => 'text',
				'from'         => 'text',
				'reply_to'     => 'text',
				'cc'           => 'text',
				'bcc'          => 'text',
				'source'       => 'text',
				'attachments'  => 'int',
			),
		),
		'reads'   => array( 'view' => array( 'gate' => 'detail', 'view' => 'minn_admin_gsmtp_event_view', 'fields' => array( 'sections' => 'raw' ) ) ),
		'actions' => array(
			// Their Event_Model::delete(), so their hooks match wp-admin; a
			// model that is missing or no-ops leaves the kernel's delete.
			'delete' => minn_admin_mail_delete( function ( $id ) {
				$events = minn_admin_gsmtp_events_model();
				try {
					if ( $events && method_exists( $events, 'delete' ) ) {
						$events->delete( $id );
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
					// The kernel's delete below.
				}
				if ( Minn_Admin_Kernel_SQL::count( 'gravitysmtp_events', array( 'id' => $id ) ) ) {
					Minn_Admin_Kernel_SQL::delete( 'gravitysmtp_events', 'id', $id );
				}
				return true;
			}, array( 'gate' => 'delete', 'fail' => __( 'Could not delete this log entry.', 'minn-admin' ) ) ),
			'resend' => minn_admin_mail_resend( 'minn_admin_gravity_smtp_resend', array(
				'gate'  => 'resend',
				'reply' => function ( $id, $fresh, $ran ) {
					return array(
						'resent'  => true,
						'logged'  => ! empty( $ran['logged'] ),
						'message' => ! empty( $ran['logged'] )
							? __( 'Resent to the original recipients.', 'minn-admin' )
							: __( 'Resent, but another active mail plugin carried it, so the new send appears in that plugin’s log, not Gravity SMTP’s.', 'minn-admin' ),
					);
				},
			) ),
		),
	);
} );

// The debug log rows exist only while General's debug_log_enabled is on.
// Their Debug_Log_Model stamps current_time( 'mysql', true ): UTC.
minn_admin_resource( 'gravity-smtp/debug', function () {
	return array(
		'active' => 'minn_admin_gravity_smtp_active',
		'gates'  => array(
			'view'  => minn_admin_gsmtp_gate( 'VIEW_DEBUG_LOG' ),
			// A line can hold a message whole; the browser shows it raw.
			'table' => function () {
				return current_user_can( minn_admin_gsmtp_cap( 'VIEW_DEBUG_LOG' ) ) && minn_admin_mail_bodies_open();
			},
		),
		'source' => array(
			'table'   => 'gravitysmtp_debug_log',
			'key'     => 'id',
			'columns' => array( 'id', 'priority', 'line', 'date_created' ),
		),
		'list'   => array(
			'facets' => array( array( 'param' => 'priority', 'column' => 'priority', 'type' => 'key' ) ),
			// A debug line can carry a message whole (a reset link's key): served
			// through the mail mask, and searched only by who reads it whole.
			'search' => array( 'columns' => minn_admin_mail_bodies_open() ? array( 'line' ) : array( 'priority' ) ),
			'order'  => array( 'column' => 'id' ),
			'row'    => function ( array $r ) {
				$r['line'] = minn_admin_mail_mask_reset_keys( (string) $r['line'] );
				return $r;
			},
			'fields' => array(
				'id'           => 'int',
				'line'         => 'text',
				'priority'     => 'text',
				'date_created' => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'sql' ),
			),
		),
	);
} );

// Reads are the kernel's LIKE: their model's count() and paginate() disagree
// on partial terms (FULLTEXT: count says 1, paginate returns nothing for
// "bounce"), which breaks the pager. Writes go through their model.
minn_admin_resource( 'gravity-smtp/suppressed', function () {
	return array(
		'active'  => 'minn_admin_gravity_smtp_active',
		'gates'   => array(
			'view' => minn_admin_gsmtp_gate( 'VIEW_EMAIL_SUPPRESSION_SETTINGS' ),
			'edit' => minn_admin_gsmtp_gate( 'EDIT_EMAIL_SUPPRESSION_SETTINGS' ),
		),
		'labels'  => array( 'not_found' => __( 'Suppressed address not found.', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'gravitysmtp_suppressed_emails',
			'key'     => 'id',
			'columns' => array( 'id', 'email', 'reason', 'notes', 'date_created' ),
		),
		'list'    => array(
			'search' => array( 'columns' => array( 'email', 'notes' ) ),
			'order'  => array( 'column' => 'date_created' ),
			// The reason in their own words where they have them; any other
			// goes out as the slug, which the client's pill tidies.
			'row'    => function ( array $r ) {
				$labels = '\Gravity_Forms\Gravity_SMTP\Enums\Suppression_Reason_Enum';
				$r['reason'] = class_exists( $labels ) ? (string) $labels::label( (string) $r['reason'] ) : (string) $r['reason'];
				return $r;
			},
			'fields' => array(
				'id'           => 'int',
				'email'        => 'text',
				'reason'       => 'text',
				'notes'        => 'text',
				'date_created' => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'sql' ),
			),
		),
		'actions' => array(
			// Their model reactivates by address, which deletes its row.
			'reactivate' => array(
				'gate'   => 'edit',
				'run'    => function ( $id, array $r ) {
					( new Gravity_Forms\Gravity_SMTP\Models\Suppressed_Emails_Model() )->reactivate_email( $r['email'] );
					return $r['email'];
				},
				'expect' => 'gone',
				'reply'  => function ( $id, $fresh, $email ) {
					return array(
						'reactivated' => $email,
						'message'     => __( 'Reactivated — Gravity SMTP can send to ', 'minn-admin' ) . $email . ' again.',
					);
				},
			),
		),
	);
} );

minn_admin_endpoint( 'gravity-smtp/suppressed', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_gravity_smtp_active',
	'gate'    => minn_admin_gsmtp_gate( 'EDIT_EMAIL_SUPPRESSION_SETTINGS' ),
	'run'     => function ( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$email = sanitize_email( (string) ( isset( $body['email'] ) ? $body['email'] : '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'bad_email', __( 'Enter a valid email address.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$notes = sanitize_text_field( (string) ( isset( $body['notes'] ) ? $body['notes'] : '' ) );
		// 'manually_added' is their manual-suppression reason enum value.
		( new Gravity_Forms\Gravity_SMTP\Models\Suppressed_Emails_Model() )->suppress_email( $email, 'manually_added', $notes );
		return array( 'suppressed' => $email );
	},
) );

// Email Routing (2.3.0+): recipes in their plugin option, listed in their
// order (first match wins). Enable, disable and delete only; the condition
// builder stays on their React screen.
minn_admin_resource( 'gravity-smtp/routing', function () {
	// The store holds what was written, read back: the post-condition of a
	// rewrite of the recipe list.
	$saved = function ( $id, $fresh, $recipes ) {
		return is_array( $recipes ) && minn_admin_gsmtp_routing_recipes() == $recipes; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- their JSON store round-trips '1' and true alike.
	};
	$flip  = function ( $on ) {
		return function ( $id ) use ( $on ) {
			$recipes = minn_admin_gsmtp_routing_recipes();
			$at      = minn_admin_gsmtp_routing_index( $recipes, $id );
			if ( null === $at ) {
				return new WP_Error( 'rest_not_found', __( 'Routing rule not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$recipes[ $at ]['enabled'] = $on;
			minn_admin_gsmtp_routing_save( $recipes );
			return $recipes;
		};
	};
	return array(
		'active'  => function () {
			return minn_admin_gravity_smtp_active() && minn_admin_gsmtp_routing_available();
		},
		'gates'   => array(
			'view' => minn_admin_gsmtp_gate( 'VIEW_ROUTING' ),
			'edit' => minn_admin_gsmtp_gate( 'EDIT_ROUTING' ),
		),
		'labels'  => array( 'not_found' => __( 'Routing rule not found.', 'minn-admin' ) ),
		'source'  => array(
			'rows' => 'minn_admin_gsmtp_routing_rows',
			'get'  => function ( $id ) {
				foreach ( minn_admin_gsmtp_routing_rows() as $row ) {
					if ( $row['id'] === $id ) {
						return $row;
					}
				}
				return null;
			},
		),
		'list'    => array(
			'per_page' => 50,
			'fields'   => array(
				'id'         => 'int',
				'name'       => 'text',
				'provider'   => 'text',
				'connector'  => 'text',
				'enabled'    => 'text',
				'conditions' => 'text',
				'order'      => 'int',
			),
		),
		'actions' => array(
			'enable'  => array(
				'gate'   => 'edit',
				'run'    => $flip( true ),
				'expect' => $saved,
				'reply'  => function () {
					return array( 'updated' => true, 'enabled' => true );
				},
				'done'   => __( 'Rule enabled.', 'minn-admin' ),
			),
			'disable' => array(
				'gate'   => 'edit',
				'run'    => $flip( false ),
				'expect' => $saved,
				'reply'  => function () {
					return array( 'updated' => true, 'enabled' => false );
				},
				'done'   => __( 'Rule disabled.', 'minn-admin' ),
			),
			'delete'  => array(
				'method' => 'DELETE',
				'gate'   => 'edit',
				'run'    => function ( $id ) {
					$recipes = minn_admin_gsmtp_routing_recipes();
					$at      = minn_admin_gsmtp_routing_index( $recipes, $id );
					if ( null === $at ) {
						return new WP_Error( 'rest_not_found', __( 'Routing rule not found.', 'minn-admin' ), array( 'status' => 404 ) );
					}
					array_splice( $recipes, $at, 1 );
					minn_admin_gsmtp_routing_save( $recipes );
					return $recipes;
				},
				'expect' => $saved,
				'reply'  => function () {
					return array( 'deleted' => true );
				},
				'done'   => __( 'Routing rule deleted.', 'minn-admin' ),
			),
		),
	);
} );

minn_admin_endpoint( 'gravity-smtp/sources', array(
	'active' => 'minn_admin_gravity_smtp_active',
	'gate'   => minn_admin_gsmtp_gate( 'VIEW_EMAIL_LOG' ),
	'run'    => 'minn_admin_gsmtp_log_sources',
) );

minn_admin_endpoint( 'gravity-smtp/status', array(
	'active' => 'minn_admin_gravity_smtp_active',
	'gate'   => minn_admin_gsmtp_gate( 'VIEW_EMAIL_LOG' ),
	'run'    => function () {
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
		$rows = array(
			array( 'label' => __( 'Sending through', 'minn-admin' ), 'value' => $title ),
			array(
				'label' => __( 'Test mode', 'minn-admin' ),
				'value' => $test_mode ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ),
				'hint'  => $test_mode ? __( 'Emails are logged, not sent.', 'minn-admin' ) : '',
			),
		);
		$routing = minn_admin_gsmtp_routing_available() && current_user_can( minn_admin_gsmtp_cap( 'VIEW_ROUTING' ) );
		// Email Routing (2.3.0+): how many recipes, and whether evaluation can
		// run (it needs two active connectors, the same gate as their UI).
		if ( $routing ) {
			$all    = minn_admin_gsmtp_routing_rows();
			$on     = count( array_filter( $all, function ( $r ) {
				return 'on' === $r['enabled'];
			} ) );
			$hint   = minn_admin_gsmtp_active_connector_count() < 2
				? __( 'Needs at least 2 active integrations to evaluate (same as Gravity SMTP).', 'minn-admin' )
				: ( $all ? __( 'First matching rule wins, top to bottom.', 'minn-admin' ) : __( 'No rules yet — add them in Gravity SMTP.', 'minn-admin' ) );
			$rows[] = array(
				'label' => __( 'Routing', 'minn-admin' ),
				'value' => $all
					? sprintf(
						/* translators: 1: number of routing rules; 2: number of enabled rules. */
						_n( '%1$d rule · %2$d on', '%1$d rules · %2$d on', count( $all ), 'minn-admin' ),
						count( $all ),
						$on
					)
					: __( 'None', 'minn-admin' ),
				'hint'  => $hint,
			);
		}
		$out   = array( 'rows' => $rows );
		$chart = minn_admin_gsmtp_send_chart();
		if ( $chart ) {
			$out['chart'] = $chart;
		}
		$out['actions'] = array();
		if ( minn_admin_gsmtp_can_send_test() ) {
			$out['actions'][] = array(
				'label'  => __( 'Send a test email', 'minn-admin' ),
				'route'  => 'minn-admin/v1/gravity-smtp/send-test',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'email', 'label' => __( 'Send to', 'minn-admin' ), 'type' => 'email', 'placeholder' => __( 'you@example.com', 'minn-admin' ) ),
				),
			);
		}
		if ( $routing ) {
			$out['actions'][] = array(
				'label' => __( 'Edit routing rules ↗', 'minn-admin' ),
				'href'  => minn_admin_gsmtp_routing_admin_url(),
			);
		}
		if ( ! $out['actions'] ) {
			unset( $out['actions'] );
		}
		return $out;
	},
) );

minn_admin_endpoint( 'gravity-smtp/send-test', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_gravity_smtp_active',
	// Sending real mail to a caller-chosen address is a WRITE, so it takes a
	// settings editor (a VIEW_* capability alone let a read-only operator
	// drive authenticated mail out of the site's ESP), on top of Gravity
	// SMTP's own Send a Test capability, which its endpoint asks.
	'gate'    => 'minn_admin_gsmtp_can_send_test',
	'run'     => function ( WP_REST_Request $request ) {
		$body  = $request->get_json_params();
		$email = sanitize_email( (string) ( isset( $body['email'] ) ? $body['email'] : '' ) );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'bad_email', __( 'Enter a valid email address.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		// Their own Send_Test_Endpoint pattern: force the chosen connector for
		// this send, then let the wp_mail interception route it. (Another
		// active mail plugin owning wp_mail sends it instead, as on their own
		// test screen.)
		$connector = minn_admin_gsmtp_primary();
		add_filter( 'gravitysmtp_connector_for_sending', function () use ( $connector ) {
			return array(
				'force'     => true,
				'connector' => $connector,
			);
		}, 8, 2 );
		// Only ONE mail plugin can own the wp_mail pipeline. Their Event_Model
		// fires this action when Gravity SMTP records the send; its absence
		// is the honest tell that another mailer carried it.
		$logged   = 0;
		$listener = function ( $created_id ) use ( &$logged ) {
			$logged = (int) $created_id;
		};
		add_action( 'gravitysmtp_after_mail_created', $listener );
		$sent = wp_mail(
			$email,
			__( 'Test email from Minn Admin (Gravity SMTP)', 'minn-admin' ),
			"This is a test email sent through the {$connector} connector, triggered from Minn Admin's Email Log.",
			array()
		);
		remove_action( 'gravitysmtp_after_mail_created', $listener );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', __( 'The mailer reported the test could not be sent.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		return array(
			'sent'    => true,
			'logged'  => (bool) $logged,
			'message' => $logged
				? __( 'Test email sent and logged.', 'minn-admin' )
				: __( 'Test email sent, but another active mail plugin carried it, so it appears in that plugin’s log, not Gravity SMTP’s.', 'minn-admin' ),
		);
	},
) );

// Each settings tab is its own screen with its own capabilities, viewing and
// editing apart: Sending their integrations caps, General their general ones.
// The values live in Gravity SMTP's own stores, read and written here.
foreach ( array( 'sending' => 'INTEGRATIONS', 'general' => 'GENERAL_SETTINGS' ) as $minn_gsmtp_tab => $minn_gsmtp_caps ) {
	minn_admin_settings( 'gravity-smtp-' . $minn_gsmtp_tab, array(
		'route'    => "gravity-smtp/settings/(?P<tab>{$minn_gsmtp_tab})",
		'active'   => 'minn_admin_gravity_smtp_active',
		'gate'     => function ( WP_REST_Request $r ) use ( $minn_gsmtp_caps ) {
			return call_user_func( minn_admin_gsmtp_gate( ( in_array( $r->get_method(), array( 'GET', 'HEAD' ), true ) ? 'VIEW_' : 'EDIT_' ) . $minn_gsmtp_caps ) );
		},
		'layout'   => function ( $ctx ) use ( $minn_gsmtp_tab ) {
			$ctx['memo']['shape'] = minn_admin_gsmtp_settings_shape( $minn_gsmtp_tab );
			return $ctx['memo']['shape'];
		},
		'read'     => function ( $f, $ctx ) {
			return $ctx['memo']['shape']['values'][ $f['key'] ] ?? '';
		},
		// General's test mode, email log and debug log each have their own
		// edit cap on their screen, beyond the tab's.
		'may'      => function ( $f ) {
			$caps = array( 'test_mode' => 'EDIT_TEST_MODE', 'event_log_' => 'EDIT_EMAIL_LOG_SETTINGS', 'save_' => 'EDIT_EMAIL_LOG_SETTINGS', 'debug_log_' => 'EDIT_DEBUG_LOG_SETTINGS' );
			foreach ( $caps as $prefix => $cap ) {
				if ( 0 === strpos( (string) $f['key'], $prefix ) ) {
					return current_user_can( minn_admin_gsmtp_cap( $cap ) );
				}
			}
			return true;
		},
		'validate' => 'minn_admin_gsmtp_settings_prepare',
		'write'    => function ( $vals ) use ( $minn_gsmtp_tab ) {
			return minn_admin_gsmtp_settings_write( $minn_gsmtp_tab, $vals );
		},
	) );
}
unset( $minn_gsmtp_tab, $minn_gsmtp_caps );

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
		return array( array( 'generic', __( 'Custom SMTP', 'minn-admin' ) ) );
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
				'title'  => __( 'Sending service', 'minn-admin' ),
				'fields' => array(
					array(
						'key'     => 'primary_connector',
						'label'   => __( 'Primary service', 'minn-admin' ),
						'type'    => 'combobox',
						'options' => minn_admin_gsmtp_connector_options(),
						'help'    => __( 'Saving a different service reloads this tab with its settings.', 'minn-admin' ),
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
			// The fields on the tab are the shown service's: a save made after
			// another tab switched services is a stale copy.
			'baseKeys' => array( 'primary_connector' ),
		);
	}
	// General: their plugin-level keys (option gravitysmtp_config), read
	// through the router so GRAVITYSMTP_* constants win.
	return array(
		'groups'   => array(
			array(
				'title'  => __( 'Sending behavior', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'test_mode', 'label' => __( 'Test mode', 'minn-admin' ), 'type' => 'toggle', 'help' => 'Log emails without actually sending them.' ),
				),
			),
			array(
				'title'  => __( 'Email log', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'event_log_enabled', 'label' => __( 'Keep an email log', 'minn-admin' ), 'type' => 'toggle' ),
					array( 'key' => 'save_email_body_enabled', 'label' => __( 'Store message bodies', 'minn-admin' ), 'type' => 'toggle', 'help' => 'Needed for the detail preview and resend.', 'showWhen' => array( 'key' => 'event_log_enabled', 'equals' => true ) ),
					array( 'key' => 'save_attachments_enabled', 'label' => __( 'Store attachments', 'minn-admin' ), 'type' => 'toggle', 'showWhen' => array( 'key' => 'event_log_enabled', 'equals' => true ) ),
					array( 'key' => 'event_log_retention', 'label' => __( 'Retention (days)', 'minn-admin' ), 'type' => 'number', 'min' => 0, 'help' => '0 keeps the log forever.', 'showWhen' => array( 'key' => 'event_log_enabled', 'equals' => true ) ),
				),
			),
			array(
				'title'  => __( 'Debug log', 'minn-admin' ),
				'fields' => array(
					array( 'key' => 'debug_log_enabled', 'label' => __( 'Keep a debug log', 'minn-admin' ), 'type' => 'toggle' ),
					array( 'key' => 'debug_log_retention', 'label' => __( 'Retention (days)', 'minn-admin' ), 'type' => 'number', 'min' => 0, 'showWhen' => array( 'key' => 'debug_log_enabled', 'equals' => true ) ),
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

/**
 * The Sending tab's changes as its store takes them. A service switch goes
 * alone: the tab's fields belong to the service it showed, and services share
 * keys such as api_key and from_email, so they would land in the new one.
 */
function minn_admin_gsmtp_settings_prepare( $changes ) {
	if ( ! array_key_exists( 'primary_connector', $changes ) ) {
		return null;
	}
	$next = sanitize_key( is_scalar( $changes['primary_connector'] ) ? (string) $changes['primary_connector'] : '' );
	unset( $changes['primary_connector'] );
	if ( '' === $next || minn_admin_gsmtp_primary() === $next ) {
		return $changes;
	}
	// Only a registered connector: the name keys their per-connector option
	// stores, and 'config' is the plugin-level store itself.
	if ( ! in_array( $next, array_column( minn_admin_gsmtp_connector_options(), 0 ), true ) ) {
		return new WP_Error( 'minn_gsmtp_bad_connector', __( 'Unknown sending service.', 'minn-admin' ), array( 'status' => 400, 'field' => 'primary_connector' ) );
	}
	if ( $changes ) {
		return new WP_Error( 'minn_gsmtp_switch_alone', __( 'Switch the sending service on its own first, then edit its settings.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return array( 'primary_connector' => $next );
}

/** Write one tab's changed values through their own stores. */
function minn_admin_gsmtp_settings_write( $tab, array $vals ) {
	$plugin = new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store();
	if ( 'sending' === $tab ) {
		$opts   = new Gravity_Forms\Gravity_SMTP\Data_Store\Opts_Data_Store();
		$was    = minn_admin_gsmtp_primary();
		$target = $vals['primary_connector'] ?? $was;
		if ( $target !== $was ) {
			// Their Save_Connector_Settings_Endpoint::save_connector_status():
			// primary and backup are exclusive (granting one clears it from
			// every other connector, in the map and in that connector's own
			// option), enabled is per connector (only the target's entry
			// changes, so a backup or a routing integration stays enabled).
			$status = function ( $map, $flag, $exclusive ) use ( $plugin, $opts, $target ) {
				$values = $plugin->get( $map, 'config', array() );
				$values = is_array( $values ) ? $values : array();
				if ( $exclusive ) {
					foreach ( array_keys( $values ) as $slug ) {
						if ( $slug !== $target ) {
							if ( Minn_Admin_Kernel_Settings::on( $values[ $slug ] ) ) {
								$opts->save( $flag, false, $slug );
							}
							$values[ $slug ] = false;
						}
					}
				}
				$values[ $target ] = true;
				$plugin->save( $map, $values );
				$opts->save( $flag, true, $target );
			};
			$status( 'enabled_connector', 'enabled', false );
			$status( 'primary_connector', 'is_primary', true );
			$opts->save( 'is_primary', false, $was );
			// One connector is not both: the new primary stops being the backup.
			$backup = $plugin->get( 'backup_connector', 'config', array() );
			if ( is_array( $backup ) && ! empty( $backup[ $target ] ) ) {
				$backup[ $target ] = false;
				$plugin->save( 'backup_connector', $backup );
				$opts->save( 'is_backup', false, $target );
			}
		} elseif ( $vals ) {
			// Their store: skips the '****************' sentinel, coerces
			// 'true'/'false' strings — the masked-secret contract for free.
			$opts->save_all( $vals, $target );
		}
		delete_transient( 'gsmtp_connector_configured_' . $target );
		return true;
	}
	foreach ( $vals as $k => $v ) {
		// Retention is whole days; a switch is stored as their 'true' or 'false'.
		$plugin->save( $k, in_array( $k, array( 'event_log_retention', 'debug_log_retention' ), true ) ? max( 0, (int) $v ) : ( Minn_Admin_Kernel_Settings::on( $v ) ? 'true' : 'false' ) );
	}
	return true;
}

/**
 * Resend through Gravity SMTP's own flow — a faithful mirror of their
 * Resend_Email_Endpoint::handle(): hydrated event, allowlisted unserialize
 * (their two Recipient classes only, exactly their allowlist), original
 * headers and attachments, wp_mail() so their Mail_Handler routes it
 * through the configured connector. Falls back to the regex recipient
 * extraction if the blob doesn't parse.
 */
function minn_admin_gravity_smtp_resend( $id, array $r ) {
	$row = (object) $r;

	$to          = null;
	$headers     = array();
	$attachments = array();
	try {
		$container = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
		$events    = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::EVENT_MODEL );
		$event     = $events ? $events->get( (int) $row->id ) : null;
		if ( is_array( $event ) && array_key_exists( 'can_resend', $event ) && ! $event['can_resend'] ) {
			return new WP_Error( 'cannot_resend', __( 'Gravity SMTP reports this email cannot be resent (body or attachments were not stored).', 'minn-admin' ), array( 'status' => 422 ) );
		}
		// The one place an adapter names classes to unserialize: the resend
		// mirrors Gravity SMTP's own endpoint, the row is written only by its
		// mail pipeline (never by a visitor), and the allowlist is exactly
		// theirs. Everything else goes through Minn_Admin::decode_serialized().
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
				// Hand wp_mail the mailbox string, not the collection object:
				// their pipeline reads either, but another mail plugin's
				// wp_mail filter (WP Mail Logging's extractor) expects the
				// string form and fatals on the object.
				$to = method_exists( $extra['to'], 'as_string' ) ? $extra['to']->as_string( true ) : $extra['to'];
			}
			if ( isset( $extra['headers'] ) && is_array( $extra['headers'] ) ) {
				$headers = $extra['headers'];
				// Their resend drops the original cc and bcc unless the
				// operator supplies new ones; Minn's confirm offers no such
				// field, so a resend goes to the addressee alone rather than
				// rebroadcasting to every copied party.
				unset( $headers['cc'], $headers['bcc'] );
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
			// Their resend attaches the copy their pipeline archived under
			// uploads/gravitysmtp/attachments/{id}/, never the original path:
			// a temp file at that path may since have been reused by another
			// send. Same resolver, same fallback to nothing.
			$saver = null;
			try {
				// The provider constant lives in the Gravity_Tools namespace
				// (like the license connector), the saver itself under
				// Gravity_SMTP.
				if ( class_exists( 'Gravity_Forms\Gravity_Tools\Utils\Utils_Service_Provider' ) && defined( 'Gravity_Forms\Gravity_Tools\Utils\Utils_Service_Provider::ATTACHMENTS_SAVER' ) ) {
					$saver = $container->get( Gravity_Forms\Gravity_Tools\Utils\Utils_Service_Provider::ATTACHMENTS_SAVER );
				}
			} catch ( \Throwable $e ) {
				$saver = null;
			}
			foreach ( (array) ( isset( $extra['attachments'] ) ? $extra['attachments'] : array() ) as $path ) {
				if ( ! is_string( $path ) || '' === $path ) {
					continue;
				}
				$saved = $saver && method_exists( $saver, 'get_saved_attachment' ) ? (string) $saver->get_saved_attachment( (int) $row->id, $path ) : '';
				if ( '' !== $saved && file_exists( $saved ) ) {
					$attachments[] = $saved;
				}
			}
		}
	} catch ( \Throwable $e ) {
		$to = null; // fall through to the regex path
	}

	if ( ! $to ) {
		$addresses = array_filter( minn_admin_gravity_smtp_to_addresses( $row->extra ), 'is_email' );
		if ( ! $addresses ) {
			return new WP_Error( 'no_recipients', __( 'No recipient address on record for this email.', 'minn-admin' ), array( 'status' => 422 ) );
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
		return new WP_Error( 'send_failed', __( 'The mailer reported the message could not be sent.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return array( 'sent' => true, 'logged' => (bool) $logged );
}

/**
 * Daily counts for the status-card chart: sent (solid bar) and failed, plus
 * everything else the log holds that day (sandboxed, filtered) as extra tip
 * rows that raise the soft total bar, so the bar agrees with the log's All
 * tab for that day and a site in test mode still sees activity. The days
 * are the site's, as every chart's; null before their migration made the
 * events table.
 *
 * @return array|null { title, primary, secondary, points:[{label,value,secondary,extra?,from,to}] }
 */
function minn_admin_gsmtp_send_chart() {
	if ( ! Minn_Admin_Kernel_SQL::exists( 'gravitysmtp_events' ) ) {
		return null;
	}
	$id   = 'gravity-smtp/events';
	$day  = array( 'column' => 'date_created', 'clock' => 'utc', 'split' => 'status', 'label' => 'M j' );
	$sent = Minn_Admin_Kernel::days( $id, $day + array( 'where' => array( 'status' => array( 'sent', 'failed' ) ), 'secondary' => array( 'failed' ) ) );
	// Each other outcome in its own bucket, so "Failed" never means "held":
	// sandboxed is test mode's, partially-sent a send with suppressed
	// recipients stripped (their "Filtered" tab), and anything newer than
	// this list still counts toward the day.
	$held  = Minn_Admin_Kernel::days( $id, $day + array( 'where' => array( 'status' => array( 'not' => array( 'sent', 'failed' ) ) ), 'secondary' => array( 'partially-sent' ) ) );
	$boxed = Minn_Admin_Kernel::days( $id, $day + array( 'where' => array( 'status' => 'sandboxed' ) ) );
	// The bounds a clicked bar sends back, in the UTC shape date_created
	// stores, by the same fixed offset the days are bucketed with, so a bar
	// and the list it narrows to always agree.
	$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	foreach ( $sent as $d => $point ) {
		foreach ( array( 'from', 'to' ) as $k ) {
			$sent[ $d ][ $k ] = gmdate( 'Y-m-d H:i:s', strtotime( $point[ $k ] . ' UTC' ) - $offset );
		}
		$extra = array_filter( array(
			__( 'Sandboxed', 'minn-admin' ) => (int) $boxed[ $d ]['value'],
			__( 'Filtered', 'minn-admin' )  => (int) $held[ $d ]['secondary'],
			__( 'Other', 'minn-admin' )     => (int) $held[ $d ]['value'] - (int) $boxed[ $d ]['value'],
		) );
		foreach ( $extra as $label => $n ) {
			$sent[ $d ]['extra'][] = array( 'label' => $label, 'value' => $n );
		}
	}
	return array(
		'title'     => __( 'Last 14 days', 'minn-admin' ),
		'primary'   => __( 'Sent', 'minn-admin' ),
		'secondary' => __( 'Failed', 'minn-admin' ),
		'points'    => array_values( $sent ),
	);
}

/**
 * Sending-plugin origins for the Source filter, via Gravity SMTP's own
 * get_all_sending_sources (first 5000 rows, their substring parse). Cached
 * 15 minutes so a visit to Email is the only request that pays for it.
 *
 * @return array[] { id, title }
 */
function minn_admin_gsmtp_log_sources() {
	$cached = get_transient( 'minn_admin_gsmtp_log_sources' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$sources = array();
	try {
		if ( class_exists( 'Gravity_Forms\Gravity_SMTP\Gravity_SMTP' ) ) {
			$container = Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
			$events    = $container->get( Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::EVENT_MODEL );
			if ( $events && method_exists( $events, 'get_all_sending_sources' ) ) {
				$sources = array_values( array_filter( array_map( 'strval', (array) $events->get_all_sending_sources() ) ) );
			}
		}
	} catch ( \Throwable $e ) {
		$sources = array();
	}
	$seen  = array();
	$items = array();
	foreach ( $sources as $src ) {
		$src = trim( $src );
		if ( '' === $src || isset( $seen[ strtolower( $src ) ] ) ) {
			continue;
		}
		$seen[ strtolower( $src ) ] = true;
		$items[] = array( 'id' => $src, 'title' => $src );
	}
	usort( $items, function ( $a, $b ) {
		return strcasecmp( $a['title'], $b['title'] );
	} );
	set_transient( 'minn_admin_gsmtp_log_sources', $items, 15 * MINUTE_IN_SECONDS );
	return $items;
}

/**
 * The sending plugin name from `extra`, without unserializing it.
 *
 * @param string $extra Serialized `extra` column.
 * @return string
 */
function minn_admin_gravity_smtp_source( $extra ) {
	$extra = (string) $extra;
	if ( '' === $extra ) {
		return '';
	}
	if ( preg_match( '/s:6:"source";s:\d+:"([^"]*)"/', $extra, $m ) ) {
		return $m[1];
	}
	if ( isset( $extra[0] ) && '{' === $extra[0] && preg_match( '/"source"\s*:\s*"([^"]*)"/', $extra, $m ) ) {
		return $m[1];
	}
	return '';
}

/**
 * Pull recipient email addresses out of the serialized `extra` blob without
 * unserializing it (PHP object injection would be a vulnerability here).
 */
function minn_admin_gravity_smtp_recipients( $extra ) {
	$emails = minn_admin_gravity_smtp_display_addresses( $extra );
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
 * The To list from `extra`, scoped to the `to` key and nothing else.
 *
 * FAILS CLOSED: when the `to` scope cannot be located this returns an empty
 * array, never "every address in the blob". A scope-extraction helper that
 * guesses is worse than one that gives up, because the caller here is Resend,
 * which puts what it returns into a real To header. Falling back to a blanket
 * scan meant resending a message that had used bcc to keep its recipient list
 * private republished that whole list to everyone on it, along with whatever
 * the original body carried, which for a mail log is routinely a live
 * password-reset or one-time-login link.
 *
 * Never unserializes: PHP object injection would be a vulnerability here.
 *
 * @param string $extra Serialized `extra` column.
 * @return array Addresses in the `to` scope, empty when it cannot be resolved.
 */
function minn_admin_gravity_smtp_to_addresses( $extra ) {
	if ( ! $extra ) {
		return array();
	}
	$extra = (string) $extra;

	// Some write paths store `to` as a plain address string rather than a
	// Recipient_Collection (Event_Model::create keeps whatever the caller
	// passed). Accept that shape, including a multi-address string.
	if ( preg_match( '/s:2:"to";s:\d+:"([^"]*)"/', $extra, $m ) ) {
		$parts = array_filter( array_map( 'trim', explode( ',', $m[1] ) ), 'is_email' );
		return array_values( array_unique( $parts ) );
	}

	$start = strpos( $extra, 's:2:"to";' );
	if ( false === $start ) {
		return array();
	}
	// Bound the window at the next sibling key rather than at the first `}}}`.
	// That terminator has no relationship to serialized structure, so an empty
	// or differently shaped `to` collection let the span run on into the cc and
	// bcc keys that follow it.
	$end = strlen( $extra );
	foreach ( array( 's:2:"cc";', 's:3:"bcc";', 's:8:"reply_to";', 's:4:"from";', 's:7:"headers";', 's:11:"attachments";' ) as $sibling ) {
		$at = strpos( $extra, $sibling, $start + 1 );
		if ( false !== $at && $at < $end ) {
			$end = $at;
		}
	}
	$window = substr( $extra, $start, $end - $start );

	if ( preg_match_all( '/s:5:"email";s:\d+:"([^"]+)"/', $window, $mm ) ) {
		return array_values( array_unique( array_filter( $mm[1], 'is_email' ) ) );
	}
	return array();
}

/**
 * Best-effort recipient addresses for DISPLAY only.
 *
 * The list column just needs something human to show, so a blanket scan is
 * acceptable here in a way it never is on the send path. Keep the two apart:
 * this must not be wired into Resend.
 *
 * @param string $extra Serialized `extra` column.
 * @return array
 */
function minn_admin_gravity_smtp_display_addresses( $extra ) {
	$scoped = minn_admin_gravity_smtp_to_addresses( $extra );
	if ( $scoped ) {
		return $scoped;
	}
	// The blanket scan reaches cc and bcc too. The list is open to Gravity
	// SMTP's list-only role, which its own screen never shows a message's
	// recipients beyond To, so the fallback is for callers who may open the
	// full log entry (VIEW_EMAIL_LOG_DETAILS) anyway.
	if ( ! current_user_can( minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG_DETAILS' ) ) ) {
		return array();
	}
	if ( $extra && preg_match_all( '/s:5:"email";s:\d+:"([^"]+)"/', (string) $extra, $m ) ) {
		return array_values( array_unique( array_filter( $m[1], 'is_email' ) ) );
	}
	return array();
}
