<?php
/**
 * Bundled adapter: Activity Log (aryo-activity-log).
 *
 * No REST API upstream — everything lives in one flat, plain-column table
 * ({prefix}aryo_activity_log), so the shim is a read-only, prefix-scoped
 * SELECT. Visibility mirrors the plugin's own menu gate: the dedicated
 * view_all_aryo_activity_log cap when granted, else its filterable
 * edit_pages default.
 *
 * Status card (v0.16 Axis A): 24h / 7d / all-time + top action. hist_time
 * is WP local epoch (current_time('timestamp')), same trap as the list.
 *
 * Request source (Activity Log 2.14): the table gained a request_source
 * column ("{channel}|app:{name}": rest / cli / cron / xmlrpc / abilities,
 * empty for an ordinary browser request, plus the Application Password name
 * when one authenticated the request). Their migration adds the column
 * lazily (first wp-admin load, and only by hand above 50k rows), so every
 * read here gates on the column actually existing rather than on the plugin
 * version; the labels and the filter matching are the plugin's own.
 *
 * last-sweep: 2026-09-19
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Who may read the Aryo activity log through Minn.
 *
 * edit_pages is Activity Log's MENU capability, but the menu is not where the
 * plugin draws its boundary. AAL_Activity_Log_List_Table::_get_where_by_role()
 * appends an object_type + user_caps clause to EVERY query it runs, so in the
 * plugin's own screen an Editor sees only Posts/Taxonomies/Attachments/Comments
 * performed by editor/author/guest — never administrator actions, and never
 * the Core/Export/Users/Plugins/Options/Theme/Menu/Widget modules.
 *
 * Minn's shim reproduces those queries without the role clause, so copying
 * only the menu cap would hand an Editor plugin installs, user-role changes,
 * option changes, every acting username and every retained IP, plus the
 * attempted usernames on failed logins. This view shows everything, so it
 * asks for the capability that means everything, the way the Stream and WSAL
 * adapters defer to their vendors' own resolvers. Sites that granted the
 * lesser cap deliberately can restore it with the filter.
 *
 * @return bool
 */
function minn_admin_aryo_can_view() {
	return (bool) apply_filters(
		'minn_admin_aryo_can_view',
		current_user_can( 'view_all_aryo_activity_log' ) || current_user_can( 'manage_options' )
	);
}

/**
 * Aryo's own row scoping for the viewer: the clause its screens and REST
 * route append to every query (AAL_Log_Query::get_where_by_role()), which
 * keeps an account to the object types its capabilities cover and to the
 * actors whose role its own role outranks (an account holding
 * manage_options through a custom role reads no administrator's actions).
 * false when Aryo shows this viewer nothing. Before 2.14 Aryo ships no
 * AAL_Log_Query; its scoping then lives in its list table, which keys the
 * actor set on the viewer's FIRST role (key( $user->caps )) through the
 * same table its current release carries, built the same way and passed
 * through the same aal_init_caps filter (Aryo 2.0.5+): by default an
 * administrator sees the default actors plus every registered role, an
 * editor editor/author/guest, an author author/guest; a super admin or a
 * view_all_aryo_activity_log holder gets the administrator row; anyone
 * else sees nothing. The object types come from Aryo's module table the
 * same way (aal_init_roles): each capability's list the viewer holds, all
 * of them for view_all_aryo_activity_log, so a type a third party logs
 * outside that table stays hidden, as Aryo hides it. Their clause is two
 * lists of exact matches, read back as values; one that does not parse
 * the way they build it shows nothing.
 *
 * @return array|null Conditions, or null when Aryo shows this viewer nothing.
 */
function minn_admin_aryo_scope() {
	if ( ! class_exists( 'AAL_Log_Query' ) || ! method_exists( 'AAL_Log_Query', 'get_where_by_role' ) ) {
		$modules = apply_filters(
			'aal_init_roles',
			array(
				'manage_options' => array( 'Core', 'Export', 'Posts', 'Taxonomies', 'Users', 'Emails', 'Options', 'Attachments', 'Plugins', 'Widgets', 'Themes', 'Menus', 'Comments', 'Post', 'Taxonomy', 'User', 'Plugin', 'Widget', 'Theme', 'Menu' ),
				'edit_pages'     => array( 'Posts', 'Taxonomies', 'Attachments', 'Comments', 'Post', 'Taxonomy', 'Attachment' ),
			)
		);
		$types   = array();
		foreach ( is_array( $modules ) ? $modules : array() as $cap => $list ) {
			if ( current_user_can( (string) $cap ) || current_user_can( 'view_all_aryo_activity_log' ) ) {
				$types = array_merge( $types, (array) $list );
			}
		}
		$roles = array_keys( (array) wp_roles()->roles );
		$table = apply_filters(
			'aal_init_caps',
			array(
				'administrator' => array_unique( array_merge( array( 'administrator', 'editor', 'author', 'guest' ), $roles ) ),
				'editor'        => array( 'editor', 'author', 'guest' ),
				'author'        => array( 'author', 'guest' ),
			)
		);
		$table = is_array( $table ) ? $table : array();
		$first = strtolower( (string) array_key_first( (array) wp_get_current_user()->caps ) );
		$allow = is_super_admin() || current_user_can( 'view_all_aryo_activity_log' ) ? ( $table['administrator'] ?? array() ) : ( $table[ $first ] ?? array() );
		$clean = function ( $values ) {
			return array_values( array_unique( array_filter( array_map( 'strval', array_filter( (array) $values, 'is_scalar' ) ), 'strlen' ) ) );
		};
		$types = $clean( $types );
		$allow = $clean( $allow );
		return $types && $allow ? array( 'object_type' => $types, 'user_caps' => $allow ) : null;
	}
	return minn_admin_aryo_parse_scope( ( new AAL_Log_Query() )->get_where_by_role() );
}

/**
 * Whether Aryo's scoping shows this viewer all it shows anyone: their scope
 * covers the one Aryo gives the accounts it lets see everything (super
 * admins, view_all_aryo_activity_log), asked the same way, with that
 * capability held for the one call and nothing else changed. Aryo's scope
 * is never empty, even for those accounts (a list of types and of actor
 * roles), so a reader of the whole table (the database browser) asks this
 * of the viewer instead.
 *
 * @return bool
 */
function minn_admin_aryo_sees_all() {
	$mine = minn_admin_aryo_scope();
	if ( ! is_array( $mine ) ) {
		return false;
	}
	$uid = get_current_user_id();
	$all = function ( $allcaps, $caps, $args ) use ( $uid ) {
		if ( 'view_all_aryo_activity_log' === ( $args[0] ?? '' ) && $uid === (int) ( $args[1] ?? 0 ) ) {
			$allcaps['view_all_aryo_activity_log'] = true;
		}
		return $allcaps;
	};
	add_filter( 'user_has_cap', $all, PHP_INT_MAX, 3 );
	try {
		$max = minn_admin_aryo_scope();
	} finally {
		remove_filter( 'user_has_cap', $all, PHP_INT_MAX );
	}
	if ( ! is_array( $max ) ) {
		return false;
	}
	foreach ( array( 'object_type', 'user_caps' ) as $k ) {
		if ( isset( $mine[ $k ] ) && ( ! isset( $max[ $k ] ) || array_diff( (array) $max[ $k ], (array) $mine[ $k ] ) ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Aryo's role clause read back as values: "AND (`object_type` = '…' OR …)
 * AND (`user_caps` = '…' OR …)", each value as esc_sql() wrote it. Anything
 * else, false included, is null: Aryo shows this viewer nothing.
 *
 * @param mixed $sql AAL_Log_Query::get_where_by_role().
 * @return array|null
 */
function minn_admin_aryo_parse_scope( $sql ) {
	$val = "'((?:[^'\\\\]|\\\\.)*)'";
	$in  = function ( $col ) use ( $val ) {
		return '\\((`' . $col . '` = ' . $val . '(?: OR `' . $col . '` = ' . $val . ')*)\\)';
	};
	if ( ! is_string( $sql ) || ! preg_match( '/^\\s*AND ' . $in( 'object_type' ) . ' AND ' . $in( 'user_caps' ) . '\\s*$/s', $sql, $m ) ) {
		return null;
	}
	global $wpdb;
	$read = function ( $group ) use ( $val, $wpdb ) {
		preg_match_all( '/= ' . $val . '/', $group, $hits );
		// Undo esc_sql(): mysqli's escapes, then WordPress's % placeholder.
		$codes = array( '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => "\x1a" );
		return array_map( function ( $v ) use ( $wpdb, $codes ) {
			return $wpdb->remove_placeholder_escape( (string) preg_replace_callback( '/\\\\(.)/s', function ( $m ) use ( $codes ) {
				return $codes[ $m[1] ] ?? $m[1];
			}, $v ) );
		}, $hits[1] );
	};
	return array( 'object_type' => $read( $m[1] ), 'user_caps' => $read( $m[4] ) );
}

function minn_admin_aryo_admin_url() {
	return admin_url( 'admin.php?page=activity-log-page' );
}

/**
 * An action in words ("failed_login" is "Failed Login"): the plugin's own
 * label where its presenter is loaded, the same casing otherwise. The tabs,
 * the pills and the status card all read it, so they say the same thing.
 *
 * @param string $action Stored action.
 * @return string
 */
function minn_admin_aryo_action_label( $action ) {
	if ( class_exists( 'AAL_Log_Presenter' ) && method_exists( 'AAL_Log_Presenter', 'get_action_label' ) ) {
		return (string) AAL_Log_Presenter::get_action_label( (string) $action );
	}
	return ucwords( str_replace( array( '-', '_' ), ' ', (string) $action ) );
}

/**
 * Whether the log table carries the request_source column (Activity Log 2.14+
 * after its migration has run). The column is what the SQL needs, so this asks
 * the table, not the plugin's version option.
 *
 * @return bool
 */
function minn_admin_aryo_source_ready() {
	return Minn_Admin_Kernel_SQL::has_column( 'aryo_activity_log', 'request_source' );
}

/**
 * Channel vocabulary: the plugin's own labels when its API class is loaded,
 * the same five words otherwise. '' is the browser (their code stores no
 * channel for an ordinary wp-admin or front-end request).
 *
 * @return array<string,string>
 */
function minn_admin_aryo_channel_labels() {
	$labels = array(
		'rest'      => __( 'REST API', 'minn-admin' ),
		'cli'       => __( 'WP-CLI', 'minn-admin' ),
		'cron'      => __( 'WP-Cron', 'minn-admin' ),
		'xmlrpc'    => __( 'XML-RPC', 'minn-admin' ),
		'abilities' => __( 'WP Abilities', 'minn-admin' ),
	);
	if ( class_exists( 'AAL_API' ) && method_exists( 'AAL_API', 'get_channel_labels' ) ) {
		$theirs = (array) AAL_API::get_channel_labels();
		foreach ( $labels as $k => $v ) {
			if ( ! empty( $theirs[ $k ] ) ) {
				$labels[ $k ] = (string) $theirs[ $k ];
			}
		}
	}
	return $labels;
}

/**
 * Split a stored request_source into [channel, app_name] through the plugin's
 * own parser when it exists (same "{channel}|app:{name}" encoding either way).
 *
 * @param string $raw Stored value.
 * @return array{0:string,1:string}
 */
function minn_admin_aryo_parse_source( $raw ) {
	$raw = (string) $raw;
	if ( class_exists( 'AAL_API' ) && method_exists( 'AAL_API', 'parse_request_source' ) ) {
		$p = (array) AAL_API::parse_request_source( $raw );
		return array( (string) ( $p['channel'] ?? '' ), (string) ( $p['app_name'] ?? '' ) );
	}
	if ( '' === $raw ) {
		return array( '', '' );
	}
	$pos = strpos( $raw, '|app:' );
	if ( false !== $pos ) {
		return array( substr( $raw, 0, $pos ), substr( $raw, $pos + 5 ) );
	}
	if ( 0 === strpos( $raw, 'app:' ) ) {
		return array( '', substr( $raw, 4 ) );
	}
	return array( $raw, '' );
}

/**
 * Filter options for the Source dimension: the plugin's channels plus the two
 * their filter dropdown leaves implicit (the browser, and "any Application
 * Password" which their query matches by token).
 *
 * @return array<int,array{0:string,1:string}>
 */
function minn_admin_aryo_source_options() {
	$out = array(
		array( '', __( 'All sources', 'minn-admin' ) ),
		array( 'browser', __( 'Browser', 'minn-admin' ) ),
	);
	foreach ( minn_admin_aryo_channel_labels() as $k => $label ) {
		$out[] = array( $k, $label );
	}
	$out[] = array( 'app_password', __( 'App password', 'minn-admin' ) );
	return $out;
}

/** One Aryo row as its row in the list. */
function minn_admin_aryo_row( array $r ) {
	$uid  = (int) $r['user_id'];
	$user = $uid ? get_userdata( $uid ) : false;
	$item = array(
		'id'      => (int) $r['histid'],
		'message' => trim( $r['object_type'] . ( $r['object_name'] ? ': ' . $r['object_name'] : '' ) ),
		'who'     => $uid ? ( $user ? $user->user_login : '#' . $uid ) : 'Guest',
		// In words, as the tabs above the list say it.
		'action'  => minn_admin_aryo_action_label( $r['action'] ),
		'type'    => $r['object_type'] . ( $r['object_subtype'] ? ' / ' . $r['object_subtype'] : '' ),
		'ip'      => $r['hist_ip'],
		'date'    => $r['hist_time'],
	);
	if ( array_key_exists( 'request_source', $r ) ) {
		list( $channel, $app ) = minn_admin_aryo_parse_source( (string) $r['request_source'] );
		$labels                = minn_admin_aryo_channel_labels();
		$item['source']        = '' === $channel ? __( 'Browser', 'minn-admin' ) : ( $labels[ $channel ] ?? $channel );
		if ( '' !== $app ) {
			$item['app_password'] = $app;
		}
	}
	return $item;
}

minn_admin_resource( 'aryo/events', function () {
	return array(
		'active' => function () {
			return class_exists( 'AAL_Main' );
		},
		'gates'  => array( 'view' => 'minn_admin_aryo_can_view' ),
		'source' => array(
			'table'   => 'aryo_activity_log',
			'key'     => 'histid',
			// request_source once their 2.14 migration has added it.
			'columns' => function () {
				return array_merge( array( 'histid', 'action', 'object_type', 'object_subtype', 'object_name', 'user_id', 'hist_ip', 'hist_time' ), minn_admin_aryo_source_ready() ? array( 'request_source' ) : array() );
			},
			// Only the rows Aryo would show this viewer, on every read.
			'where'   => 'minn_admin_aryo_scope',
			// That scope is the viewer's own, and never empty: a reader of
			// the whole table admits only someone it shows all Aryo shows.
			'viewer'  => 'minn_admin_aryo_sees_all',
		),
		'list'   => array(
			'facets' => array(
				array( 'param' => 'action', 'column' => 'action', 'type' => 'key' ),
				// Where the change came from, matched as their own query matches it:
				// no channel is the browser, an app password rides any channel.
				array( 'param' => 'source', 'values' => function ( $v ) {
					$v = sanitize_key( $v );
					if ( '' === $v || ! minn_admin_aryo_source_ready() ) {
						return null;
					}
					if ( 'browser' === $v ) {
						return array( 'request_source' => array( 'any' => array( '', null ) ) );
					}
					if ( 'app_password' === $v ) {
						return array( 'request_source' => array( 'any' => array( array( 'contains' => '|app:' ), array( 'starts' => 'app:' ) ) ) );
					}
					return array( 'request_source' => array( 'any' => array( $v, array( 'starts' => $v . '|' ) ) ) );
				} ),
			),
			'search' => array(
				'columns' => array( 'object_name', 'action', 'object_type' ),
				// The pill shows an action in words ("Failed Login" for
				// failed_login), so the words find it too: every action this
				// viewer may read whose label carries the term.
				'also'    => function ( $term ) {
					$named = array();
					foreach ( Minn_Admin_Kernel::distinct( 'aryo/events', 'action', array( 'action' => array( 'op' => '!=', 'value' => '' ) ) ) as $a ) {
						$label = minn_admin_aryo_action_label( $a );
						if ( false !== ( function_exists( 'mb_stripos' ) ? mb_stripos( $label, $term ) : stripos( $label, $term ) ) ) {
							$named[] = $a;
						}
					}
					return $named ? array( 'action' => $named ) : null;
				},
			),
			// Aryo stores current_time('timestamp'): the site's wall clock read as an epoch.
			'range'  => array( 'column' => 'hist_time', 'clock' => 'local-epoch' ),
			'order'  => array( 'column' => 'hist_time' ),
			'row'    => 'minn_admin_aryo_row',
			'fields' => array(
				'id'           => 'int',
				'message'      => 'text',
				'who'          => 'text',
				'action'       => 'text',
				'type'         => 'text',
				'ip'           => 'text',
				// Read back in the site's zone at the offset that date had, sent as UTC with a Z.
				'date'         => array( 'type' => 'datetime', 'stored' => 'local-epoch', 'wire' => 'z' ),
				'source'       => 'text',
				'app_password' => 'text',
			),
		),
	);
} );

/**
 * Status-card model for Aryo Activity Log, over the rows Aryo shows this viewer.
 *
 * @return array{rows:array,actions:array}
 */
function minn_admin_aryo_status_model() {
	$open = array( array( 'label' => __( 'Open Activity Log ↗', 'minn-admin' ), 'href' => minn_admin_aryo_admin_url() ) );
	$n    = Minn_Admin_Kernel::log_counts( 'aryo/events', array(
		'top' => array( 'column' => 'action', 'where' => array( 'action' => array( 'op' => '!=', 'value' => '' ) ) ),
	) );
	if ( null === $n ) {
		return minn_admin_activity_none( $open, __( 'Log table not found', 'minn-admin' ) );
	}
	$top = $n['top'] ? minn_admin_aryo_action_label( $n['top']['value'] ) . ' (' . number_format_i18n( $n['top']['count'] ) . ')' : '—';
	return minn_admin_activity_card( $n, $open, __( 'Top action (7d)', 'minn-admin' ), $top );
}

minn_admin_endpoint( 'aryo/status', array(
	'active' => function () {
		return class_exists( 'AAL_Main' );
	},
	'gate'   => 'minn_admin_aryo_can_view',
	'run'    => 'minn_admin_aryo_status_model',
) );

minn_admin_endpoint( 'aryo/actions', array(
	'active' => function () {
		return class_exists( 'AAL_Main' );
	},
	'gate'   => 'minn_admin_aryo_can_view',
	'run'    => function () {
		$out = array();
		foreach ( Minn_Admin_Kernel::distinct( 'aryo/events', 'action', array( 'action' => array( 'op' => '!=', 'value' => '' ) ) ) as $a ) {
			$out[] = array( 'id' => $a, 'title' => minn_admin_aryo_action_label( $a ) );
		}
		return $out;
	},
) );

minn_admin_add_surface( 'aryo-activity-log', function () {
	return class_exists( 'AAL_Main' ) && minn_admin_aryo_can_view();
}, function () {
	$surface = minn_admin_activity_surface( 'aryo/events', array(
		'cap'     => 'read', // the active check above, in the vendor's own model, is the gate
		// Plugin product name is just "Activity Log"; use Aryo so the
		// family switcher can tell it apart from Simple History / Stream.
		'sub'     => 'Aryo',
		'plugin'  => 'aryo-activity-log',
		'icon'    => 'clock',
		'status'  => 'aryo/status',
		// Action is Aryo's first-class verb (logged_in / updated / installed…).
		'tabs'    => array( 'action', 'aryo/actions', __( 'All actions', 'minn-admin' ) ),
		'columns' => array(
			array( 'key' => 'who', 'label' => __( 'Who', 'minn-admin' ) ),
			array( 'key' => 'action', 'label' => __( 'Action', 'minn-admin' ), 'format' => 'pill' ),
		),
		'detail'  => array( 'skip' => array( 'message' ) ),
	) );
	if ( minn_admin_aryo_source_ready() ) {
		// Where the change came from: the daily audit question, and the
		// column their 2.14 release added. Rendered only once the column
		// exists, so a site whose migration has not run sees the old list.
		$surface['collection']['filter']    = array(
			'label'   => __( 'Source', 'minn-admin' ),
			'options' => minn_admin_aryo_source_options(),
			'query'   => 'source={v}',
		);
		$surface['collection']['columns'][] = array(
			'key'   => 'source',
			'label' => __( 'Source', 'minn-admin' ),
			'width' => '128px',
		);
	}
	return $surface;
} );
