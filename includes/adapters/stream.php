<?php
/**
 * Bundled adapter: Stream.
 *
 * Current Stream (4.x) ships no REST routes for records — they live in
 * {base_prefix}stream (+ stream_meta), with a human-readable summary
 * column, so the records are a kernel resource over that table, held to
 * the current blog_id on every read. Visibility uses Stream's own
 * view_stream capability.
 *
 * Status card: 24h / 7d / all-time + top connector, over the same rows.
 *
 * last-sweep: 2026-09-19
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Has the network owner switched off per-site access to Stream?
 *
 * Stream has TWO access dimensions and only one of them is a capability. Role
 * Access decides which roles may read the log; the network-level Site Access
 * switch decides whether subsites may read it AT ALL, and its whole purpose is
 * to keep the log in the Network Admin. Their cap grant knows nothing about it
 * (filter_user_caps reads role access alone) because they enforce it by simply
 * not registering the screen. Minn IS that screen here, so the switch has to be
 * asked before either path below, including the one where Stream's own filter
 * has already answered yes.
 *
 * @return bool
 */
function minn_admin_stream_site_access_denied() {
	if ( ! is_multisite() ) {
		return false;
	}
	try {
		$plugin = function_exists( 'wp_stream_get_instance' ) ? wp_stream_get_instance() : null;
		if ( ! $plugin || ! method_exists( $plugin, 'is_multisite_network_activated' )
			|| ! $plugin->is_multisite_network_activated() ) {
			return false;
		}
	} catch ( \Throwable $e ) {
		return false;
	}
	$options = (array) get_site_option( 'wp_stream_network', array() );
	$allowed = isset( $options['general_site_access'] ) ? absint( $options['general_site_access'] ) : 1;
	return ! $allowed;
}

function minn_admin_stream_can() {
	if ( minn_admin_stream_site_access_denied() ) {
		return false;
	}
	// Defer entirely to Stream. view_stream is granted dynamically from its
	// general_role_access setting, so an operator who removes administrator
	// from Role Access means it — a manage_options backstop would override
	// that choice, and this must never become one.
	$cap = apply_filters( 'wp_stream_view_cap', 'view_stream' );
	if ( current_user_can( $cap ) ) {
		return true;
	}
	// Stream grants view_stream through a user_has_cap filter that its Admin
	// class registers, and Admin never loads in a REST request. Stream ships
	// a REST-side equivalent in its Abilities class, but registers it inside
	// a method that returns early unless the Abilities API integration is
	// switched on — an unrelated, default-OFF feature flag. Verified from
	// inside a real REST request on a default install: role_access is
	// ["administrator"], the caller IS an administrator, no Stream callback
	// is on user_has_cap, and view_stream comes back false. So on any site
	// that has not opted into that flag, nobody can read Stream over REST.
	//
	// Falling back to Stream's OWN rule keeps the deferral honest: read
	// their Role Access setting and match the caller's roles exactly as
	// their filter does. Remove administrator from Role Access and this
	// returns false for administrators, which is the whole point.
	if ( 'view_stream' !== $cap ) {
		return false; // A site that renamed the cap is telling us to use it.
	}
	try {
		$plugin = function_exists( 'wp_stream_get_instance' ) ? wp_stream_get_instance() : null;
		if ( ! $plugin || ! isset( $plugin->settings ) || ! method_exists( $plugin->settings, 'get_setting_value' ) ) {
			return false;
		}
		// Their own reader, so the network-level option is consulted on a
		// network-activated multisite.
		$role_access = (array) $plugin->settings->get_setting_value( 'general_role_access', array() );
		if ( ! $role_access ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		global $wp_roles;
		$roles_obj = isset( $wp_roles ) ? $wp_roles : new WP_Roles();
		$roles     = array_unique( array_merge(
			(array) $user->roles,
			array_filter( array_keys( (array) $user->caps ), array( $roles_obj, 'is_role' ) )
		) );
		foreach ( $roles as $role ) {
			if ( in_array( $role, $role_access, true ) ) {
				return true;
			}
		}
	} catch ( \Throwable $e ) {
		return false;
	}
	return false;
}

/**
 * Stream's own words for a connector's context or action ("sessions" is
 * "Sessions", "login" is "Log In"), the labels its screen prints. Read from
 * every connector: a REST request registers only the front-end ones, so the
 * live registry would miss Settings, Editor, Menus and the rest. A term with
 * no label is tidied. Their labels are esc_html'd; this returns plain text.
 *
 * @param string $connector Stored connector slug.
 * @param string $kind      'contexts' or 'actions'.
 * @param string $term      Stored context or action slug.
 * @return string
 */
function minn_admin_stream_term_label( $connector, $kind, $term ) {
	static $labels = null;
	if ( null === $labels ) {
		$labels = array();
		try {
			$registry = function_exists( 'wp_stream_get_instance' ) ? wp_stream_get_instance()->connectors : null;
			if ( is_object( $registry ) && method_exists( $registry, 'get_all_including_admin_only' ) ) {
				$all = $registry->get_all_including_admin_only();
			} elseif ( is_object( $registry ) && method_exists( $registry, 'get_all' ) ) {
				$all = $registry->get_all();
			} else {
				$all = array();
			}
			foreach ( (array) $all as $one ) {
				if ( is_array( $one ) && isset( $one['slug'] ) ) {
					$labels[ (string) $one['slug'] ] = array(
						'contexts' => (array) ( $one['contexts'] ?? array() ),
						'actions'  => (array) ( $one['actions'] ?? array() ),
					);
				}
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// No registry: every term is tidied.
		}
	}
	$label = $labels[ (string) $connector ][ $kind ][ (string) $term ] ?? '';
	return is_scalar( $label ) && '' !== (string) $label
		? Minn_Admin::plain_text( $label )
		: ucwords( str_replace( array( '-', '_' ), ' ', (string) $term ) );
}

function minn_admin_stream_admin_url() {
	return admin_url( 'admin.php?page=wp_stream' );
}

/** One Stream record as its row in the list. */
function minn_admin_stream_row( array $r ) {
	$uid  = (int) $r['user_id'];
	$user = $uid ? get_userdata( $uid ) : false;
	return array(
		'id'        => (int) $r['ID'],
		'summary'   => $r['summary'],
		'who'       => $uid ? ( $user ? $user->user_login : '#' . $uid ) : 'Guest',
		'connector' => $r['connector'],
		// In Stream's words ("Sessions / Log In"), as its own list prints them.
		'context'   => minn_admin_stream_term_label( $r['connector'], 'contexts', $r['context'] )
			. ( $r['action'] ? ' / ' . minn_admin_stream_term_label( $r['connector'], 'actions', $r['action'] ) : '' ),
		'ip'        => $r['ip'],
		'date'      => $r['created'],
	);
}

// Records live in one network table; each read is this site's rows only.
minn_admin_resource( 'stream/records', array(
	'active' => function () {
		return function_exists( 'wp_stream_get_instance' );
	},
	'gates'  => array( 'view' => 'minn_admin_stream_can' ),
	'source' => array(
		'table'   => 'stream',
		'network' => true,
		'key'     => 'ID',
		'columns' => array( 'ID', 'summary', 'connector', 'context', 'action', 'user_id', 'ip', 'created' ),
		'where'   => function () {
			return array( 'blog_id' => get_current_blog_id() );
		},
	),
	'list'   => array(
		// Connector is Stream's first-class source dimension (posts/users/…).
		'facets' => array( array( 'param' => 'connector', 'column' => 'connector', 'type' => 'key' ) ),
		'search' => array( 'columns' => array( 'summary', 'connector', 'context' ) ),
		'range'  => array( 'column' => 'created', 'clock' => 'utc' ),
		'order'  => array( 'column' => 'created' ),
		'row'    => 'minn_admin_stream_row',
		'fields' => array(
			'id'        => 'int',
			'summary'   => 'text',
			'who'       => 'text',
			'connector' => 'text',
			'context'   => 'text',
			'ip'        => 'text',
			// GMT, sent with a Z so the client never reads it as site-local.
			'date'      => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'z' ),
		),
	),
) );

/**
 * Status-card model for Stream: 24h / 7d / all-time, the last event and the
 * top connector, over this site's records.
 *
 * @return array{rows:array,actions:array}
 */
function minn_admin_stream_status_model() {
	$open = array( array( 'label' => __( 'Open Stream ↗', 'minn-admin' ), 'href' => minn_admin_stream_admin_url() ) );
	$n    = Minn_Admin_Kernel::log_counts( 'stream/records', array(
		'top' => array( 'column' => 'connector', 'where' => array( 'connector' => array( 'op' => '!=', 'value' => '' ) ) ),
	) );
	if ( null === $n ) {
		return minn_admin_activity_none( $open, __( 'Stream table not found', 'minn-admin' ) );
	}
	$top = $n['top'] ? ucwords( str_replace( array( '-', '_' ), ' ', $n['top']['value'] ) ) . ' (' . number_format_i18n( $n['top']['count'] ) . ')' : '—';
	return minn_admin_activity_card( $n, $open, __( 'Top source (7d)', 'minn-admin' ), $top );
}

minn_admin_endpoint( 'stream/status', array(
	'active' => function () {
		return function_exists( 'wp_stream_get_instance' );
	},
	'gate'   => 'minn_admin_stream_can',
	'run'    => 'minn_admin_stream_status_model',
) );

minn_admin_endpoint( 'stream/connectors', array(
	'active' => function () {
		return function_exists( 'wp_stream_get_instance' );
	},
	'gate'   => 'minn_admin_stream_can',
	'run'    => function () {
		$out = array();
		foreach ( Minn_Admin_Kernel::distinct( 'stream/records', 'connector', array( 'connector' => array( 'op' => '!=', 'value' => '' ) ) ) as $c ) {
			$out[] = array( 'id' => $c, 'title' => ucwords( str_replace( array( '-', '_' ), ' ', $c ) ) );
		}
		return $out;
	},
) );

minn_admin_add_surface( 'stream', function () {
	return function_exists( 'wp_stream_get_instance' ) && minn_admin_stream_can();
}, function () {
	return minn_admin_activity_surface( 'stream/records', array(
		'cap'     => 'read', // the active check above, in the vendor's own model, is the gate
		'sub'     => 'Stream',
		'plugin'  => 'stream',
		'icon'    => 'clock',
		'status'  => 'stream/status',
		'tabs'    => array( 'connector', 'stream/connectors', __( 'All sources', 'minn-admin' ) ),
		'event'   => array( 'key' => 'summary' ),
		'columns' => array(
			array( 'key' => 'who', 'label' => __( 'Who', 'minn-admin' ) ),
			array( 'key' => 'connector', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'pill' ),
		),
		'detail'  => array( 'skip' => array( 'summary' ) ),
	) );
} );
