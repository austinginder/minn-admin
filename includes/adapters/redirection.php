<?php
/**
 * Bundled adapter: Redirection.
 *
 * Pure descriptor over Redirection's own REST API (redirection/v1). Lists
 * redirects with source, target, status code, hit counts and last access,
 * with enable/disable/delete actions through its bulk endpoints. Redirection
 * paginates 0-based, hence the {page0} token.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirection's database status and installer classes, whichever generation
 * of the plugin is installed. 5.10 moved them into the autoloaded
 * Redirection\Database namespace (Status, Database, Schema\Latest); earlier
 * builds keep the Red_Database_Status / Red_Database globals in
 * database/database.php, which nothing loads outside their admin screens.
 * A path include of a file that no longer exists is what silently turned
 * the setup gate off on 5.10.
 *
 * @return array{status:string,database:string}|null Class names, or null when neither shape is present.
 */
function minn_admin_redirection_db_classes() {
	if ( class_exists( '\\Redirection\\Database\\Status' ) && class_exists( '\\Redirection\\Database\\Database' ) ) {
		return array( 'status' => '\\Redirection\\Database\\Status', 'database' => '\\Redirection\\Database\\Database' );
	}
	if ( defined( 'REDIRECTION_FILE' ) ) {
		$legacy = dirname( REDIRECTION_FILE ) . '/database/database.php';
		if ( file_exists( $legacy ) ) {
			include_once $legacy;
		}
		if ( class_exists( 'Red_Database_Status' ) && class_exists( 'Red_Database' ) ) {
			return array( 'status' => 'Red_Database_Status', 'database' => 'Red_Database' );
		}
	}
	return null;
}

minn_admin_add_surface( 'redirection', function () {
	return defined( 'REDIRECTION_VERSION' );
}, function () {
	return minn_admin_redirects_surface( array(
		'sub'        => 'Redirection',
		'plugin'     => 'redirection',
		'cap'        => apply_filters( 'redirection_role', 'manage_options' ),
		// Status card (v0.18.0): rule counts, served/404 traffic and a
		// dual-series 14-day chart from Redirection's own log tables.
		'status'     => 'redirection/status',
		// Fresh installs have no tables or default group until Redirection's
		// setup wizard runs, so every write fails ("Invalid group"). The gate
		// runs THEIR installer (Red_Latest_Database::install() is the same
		// create-tables + create-groups sequence their wizard drives) and
		// mirrors the wizard's three questions as toggles. Monitoring on and
		// IP logging off by default: the wizard's spirit, and IP storage is
		// a privacy choice Minn must not make silently.
		'setup'      => array(
			'needed'  => function () {
				$db = minn_admin_redirection_db_classes();
				if ( ! $db ) {
					return false;
				}
				return (bool) ( new $db['status']() )->needs_installing();
			},
			'title'   => __( 'Redirection needs its one-time setup', 'minn-admin' ),
			'note'    => __( 'Redirection stores redirects in its own database tables, which it creates on first setup. This runs the same install its own setup wizard performs; the choices below are the wizard\'s questions.', 'minn-admin' ),
			'options' => array(
				array(
					'id'      => 'monitor',
					'label'   => __( 'Monitor permalink changes in posts and pages, and add a redirect when they change', 'minn-admin' ),
					'default' => true,
				),
				array(
					'id'      => 'log',
					'label'   => __( 'Keep a log of redirects and 404 errors (kept for 7 days)', 'minn-admin' ),
					'default' => true,
				),
				array(
					'id'      => 'ip',
					'label'   => __( 'Store IP addresses with logged redirects and errors', 'minn-admin' ),
					'default' => false,
				),
			),
			'run'     => function ( $choices ) {
				// Redirection's own setup route asks its option or support
				// capability through redirection_capability_check, which a
				// site can narrow; the manage_options floor stays.
				if ( class_exists( 'Redirection_Capabilities' )
					&& ! Redirection_Capabilities::has_access( Redirection_Capabilities::CAP_OPTION_MANAGE )
					&& ! Redirection_Capabilities::has_access( Redirection_Capabilities::CAP_SUPPORT_MANAGE ) ) {
					return new WP_Error( 'rest_forbidden', __( 'This site keeps Redirection’s setup to people with its own settings access.', 'minn-admin' ), array( 'status' => 403 ) );
				}
				$db = minn_admin_redirection_db_classes();
				if ( ! $db ) {
					return new WP_Error( 'no_installer', __( 'Redirection\'s installer could not be found. Run its setup from the Redirection screen.', 'minn-admin' ) );
				}
				$result = call_user_func( array( $db['database'], 'get_latest_database' ) )->install();
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				( new $db['status']() )->finish();
				// The wizard's own option values (from its setup submit):
				// monitor targets the default group, unchecked logging is -1.
				red_set_options( array(
					'monitor_post'    => ! empty( $choices['monitor'] ) ? 1 : 0,
					'monitor_types'   => ! empty( $choices['monitor'] ) ? array( 'post', 'page' ) : array(),
					'expire_redirect' => ! empty( $choices['log'] ) ? 7 : -1,
					'expire_404'      => ! empty( $choices['log'] ) ? 7 : -1,
					'ip_logging'      => ! empty( $choices['ip'] ) ? 1 : 0,
				) );
				return true;
			},
		),
		'collection' => array(
			'route'     => 'redirection/v1/redirect',
			// Asks for last_hit (the rest_request_after_callbacks filter below).
			'query'     => 'minn_view=1',
			// Their filter route accepts orderby source|last_count|last_access
			// (plus position/id) with direction asc|desc.
			'sortQuery' => 'orderby={by}&direction={dir}',
			'pageQuery' => 'per_page=25&page={page0}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'filterBy[url]={q}',
			'create'    => array(
				'label'    => __( 'Add redirect', 'minn-admin' ),
				'route'    => 'redirection/v1/redirect',
				'method'   => 'POST',
				// Plain URL-match redirect in the default group; power users
				// still have Redirection's own UI for regex/conditional rules.
				'defaults' => array(
					'action_type' => 'url',
					'match_type'  => 'url',
					'group_id'    => 1,
					'regex'       => false,
				),
				'fields'   => array( minn_admin_redirects_source( 'url' ), minn_admin_redirects_target( 'action_data.url' ), minn_admin_redirects_code( 'action_code' ) ),
			),
			'columns'   => array(
				array( 'key' => 'url', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)', 'sort' => 'source' ),
				array( 'key' => 'action_data.url', 'label' => __( 'Target', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'action_code', 'label' => __( 'Code', 'minn-admin' ), 'format' => 'mono', 'width' => '64px' ),
				// Their enabled flag: a disabled rule reads Inactive.
				array( 'key' => 'enabled', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'hits', 'label' => __( 'Hits', 'minn-admin' ), 'format' => 'num', 'width' => '72px', 'sort' => 'last_count' ),
				// last_hit is added to their list below (ISO, UTC); their own
				// last_access is the date alone.
				array( 'key' => 'last_hit', 'label' => __( 'Last hit', 'minn-admin' ), 'format' => 'ago', 'sort' => 'last_access' ),
			),
			'detail'    => array(
				'skip' => array( 'match_data', 'match_type', 'match_url', 'position', 'group_id', 'last_access' ),
				// Basic in-place edit — Redirection's own update endpoint (POST /redirect/{id}).
				// That endpoint rebuilds the WHOLE row from the payload, so `preserve`
				// carries everything the form does not edit: source flags and options
				// (match_data), precedence (position) and the matcher's own targets
				// (action_data — a login, role or IP rule keeps its two URLs; the form's
				// Target URL writes into a copy of it).
				'edit' => array(
					'route'    => 'redirection/v1/redirect/{id}',
					'method'   => 'POST',
					'preserve' => array( 'match_type', 'action_type', 'group_id', 'title', 'regex', 'match_data', 'position', 'action_data' ),
					'fields'   => array( minn_admin_redirects_source( 'url', false ), minn_admin_redirects_target( 'action_data.url', false ), minn_admin_redirects_code( 'action_code', false ) ),
				),
			),
			'actions'   => array(
				array(
					'label'  => __( 'Disable', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/disable?items={id}',
				),
				array(
					'label'  => __( 'Enable', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/enable?items={id}',
				),
				array(
					'label'   => __( 'Delete redirect', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'redirection/v1/bulk/redirect/delete?items={id}',
					'confirm' => __( 'Delete this redirect permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
			// Same routes as single actions — Redirection's bulk endpoint is
			// already per-item under the hood.
			'bulk'      => array(
				array(
					'label'  => __( 'Disable', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/disable?items={id}',
				),
				array(
					'label'  => __( 'Enable', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/enable?items={id}',
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'redirection/v1/bulk/redirect/delete?items={id}',
					'confirm' => __( 'Delete the selected redirects permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
		// Daily options only (monitor + logging + IP). Schema served at
		// request time; writes go through red_set_options (their sanitizer).
		'settings'   => array(
			'label' => __( 'Settings', 'minn-admin' ),
			'cap'   => apply_filters( 'redirection_role', 'manage_options' ),
			'tabs'  => array(
				array( 'id' => 'general', 'label' => __( 'General', 'minn-admin' ) ),
			),
			'route' => 'minn-admin/v1/redirection/settings/{tab}',
		),
	) );
} );

function minn_admin_redirection_ready() {
	return defined( 'REDIRECTION_VERSION' ) && function_exists( 'red_get_options' );
}

/** Their base plugin role: the gate of every Redirection screen. */
function minn_admin_redirection_can() {
	return current_user_can( apply_filters( 'redirection_role', 'manage_options' ) );
}

/** One of their granular capabilities, or the base role on builds without them. */
function minn_admin_redirection_has( $const ) {
	if ( class_exists( 'Redirection_Capabilities' ) && defined( 'Redirection_Capabilities::' . $const ) ) {
		return (bool) Redirection_Capabilities::has_access( constant( 'Redirection_Capabilities::' . $const ) );
	}
	return minn_admin_redirection_can();
}

/** Their log screen's capability: the database browser asks it of the logs table. */
function minn_admin_redirection_can_logs() {
	return minn_admin_redirection_has( 'CAP_LOG_MANAGE' );
}

/** Their 404 screen's capability: the database browser asks it of the 404 table. */
function minn_admin_redirection_can_404() {
	return minn_admin_redirection_has( 'CAP_404_MANAGE' );
}

/** The redirect list's capability. */
function minn_admin_redirection_can_redirects() {
	return minn_admin_redirection_has( 'CAP_REDIRECT_MANAGE' );
}

/**
 * The options capability, for reading and writing settings alike.
 * Redirection splits its permissions: the base role lets a non-admin manage
 * redirects, but options (log retention, IP logging) stay behind a SECOND
 * cap its own settings API enforces. Writing settings at the base role only
 * would let an editor stop redirect/404 logging site-wide, an anti-forensics
 * primitive; their settings API reads options behind the same cap.
 */
function minn_admin_redirection_can_settings() {
	return minn_admin_redirection_has( 'CAP_OPTION_MANAGE' );
}

/** The settings tab: { groups, values, adminUrl }. */
function minn_admin_redirection_settings() {
	$opts = red_get_options();
	// Redirection keeps three IP levels: 0 none, 1 the full address, 2
	// masked (its front end masks only at 2).
	$ip_level = isset( $opts['ip_logging'] ) ? max( 0, min( 2, (int) $opts['ip_logging'] ) ) : 0;
	return array(
		'groups'   => array(
			array(
				'title'  => __( 'Permalink monitor', 'minn-admin' ),
				'fields' => array(
					array(
						'key'   => 'monitor',
						'label' => __( 'Monitor permalink changes', 'minn-admin' ),
						'type'  => 'toggle',
						'help'  => __( 'Add a redirect when a post or page slug changes.', 'minn-admin' ),
					),
				),
			),
			array(
				'title'  => __( 'Logging', 'minn-admin' ),
				'fields' => array(
					// Two retentions, kept apart exactly as Redirection stores
					// them (expire_redirect / expire_404): a site can keep
					// redirect hits while the 404 log, which records visitor
					// addresses, stays off.
					array(
						'key'     => 'expire_redirect',
						'label'   => __( 'Redirect log', 'minn-admin' ),
						'type'    => 'select',
						'options' => minn_admin_redirection_retention_options(),
					),
					array(
						'key'     => 'expire_404',
						'label'   => __( '404 log', 'minn-admin' ),
						'type'    => 'select',
						'options' => minn_admin_redirection_retention_options(),
					),
					array(
						'key'     => 'ip_logging',
						'label'   => __( 'Store IP addresses with logs', 'minn-admin' ),
						'type'    => 'select',
						'options' => array(
							array( '0', __( 'No', 'minn-admin' ) ),
							array( '1', __( 'Full addresses', 'minn-admin' ) ),
							array( '2', __( 'Anonymized (last part masked)', 'minn-admin' ) ),
						),
						'help'    => __( 'A privacy choice — off by default on fresh installs.', 'minn-admin' ),
					),
				),
			),
		),
		'values'   => array(
			// monitor_post is a group id when on; 0 is off.
			'monitor'         => ! empty( $opts['monitor_post'] ),
			'expire_redirect' => (string) ( isset( $opts['expire_redirect'] ) ? (int) $opts['expire_redirect'] : 7 ),
			'expire_404'      => (string) ( isset( $opts['expire_404'] ) ? (int) $opts['expire_404'] : 7 ),
			'ip_logging'      => (string) $ip_level,
		),
		'adminUrl' => admin_url( 'tools.php?page=redirection.php' ),
	);
}

// Their redirect list sends last_access as a date with no time (date_i18n
// with the date format), so a hit an hour ago read as hours older, counted
// from midnight. The time is in their table, written by visit() with MySQL
// NOW(), the database's own clock. Added to each item of that list as
// last_hit; nothing else about their answer changes. The surface asks with
// minn_view=1, so their screen and every other client get the list as they
// ship it.
add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
	if ( ! minn_admin_redirection_ready() || ! $request instanceof WP_REST_Request || 'GET' !== $request->get_method()
		|| '/redirection/v1/redirect' !== $request->get_route() || ! $request->get_param( 'minn_view' ) ) {
		return $response;
	}
	$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;
	if ( ! is_array( $data ) || empty( $data['items'] ) || ! is_array( $data['items'] ) ) {
		return $response;
	}
	$ids = array();
	foreach ( $data['items'] as $item ) {
		if ( is_array( $item ) && ! empty( $item['id'] ) ) {
			$ids[] = (int) $item['id'];
		}
	}
	if ( ! $ids ) {
		return $response;
	}
	// A rule never hit (or reset) holds 1970-01-01 00:00:00, which reads as none.
	$at   = array();
	$rows = Minn_Admin_Kernel_SQL::select( 'redirection_items', array( 'id', 'last_access' ), array( 'id' => $ids ) );
	// The whole page read by the database in one query, each on its own date's offset.
	Minn_Admin_Kernel_SQL::db_epochs( array_column( $rows, 'last_access' ) );
	foreach ( $rows as $r ) {
		$at[ (int) $r['id'] ] = Minn_Admin_Kernel_Fields::datetime( $r['last_access'], 'db', 'iso-utc' );
	}
	foreach ( $data['items'] as $i => $item ) {
		if ( is_array( $item ) && isset( $item['id'] ) ) {
			$data['items'][ $i ]['last_hit'] = $at[ (int) $item['id'] ] ?? '';
		}
	}
	if ( $response instanceof WP_REST_Response ) {
		$response->set_data( $data );
		return $response;
	}
	return $data;
}, 10, 3 );

// Status card. Counts from Redirection's own tables (a pre-setup install has
// none). Log timestamps are current_time('mysql') site-local; the days match
// Redirection's own log view. Retention is their log_expiry setting (7 days by
// default), so the "served" numbers honestly say what the log still holds.
minn_admin_endpoint( 'redirection/status', array(
	'active' => 'minn_admin_redirection_ready',
	'gate'   => 'minn_admin_redirection_can_redirects',
	'run'    => function () {
		$rows = array();
		if ( Minn_Admin_Kernel_SQL::exists( 'redirection_items' ) ) {
			$enabled  = Minn_Admin_Kernel_SQL::count( 'redirection_items', array( 'status' => 'enabled' ) );
			$disabled = Minn_Admin_Kernel_SQL::count( 'redirection_items', array( 'status' => array( 'not' => array( 'enabled' ) ) ) );
			$rows[]   = array(
				'label' => __( 'Redirect rules', 'minn-admin' ),
				'value' => (string) $enabled,
				'hint'  => $disabled
					/* translators: %d: number of disabled redirect rules. */
					? sprintf( _n( '%d disabled', '%d disabled', $disabled, 'minn-admin' ), $disabled )
					: __( 'all enabled', 'minn-admin' ),
			);
			$rows[] = array( 'label' => __( 'Hits, all time', 'minn-admin' ), 'value' => number_format_i18n( Minn_Admin_Kernel_SQL::sum( 'redirection_items', 'last_count' ) ) );
			$top    = Minn_Admin_Kernel_SQL::select( 'redirection_items', array( 'url', 'last_count' ), array( 'last_count' => array( 'op' => '>', 'value' => 0 ) ), array( 'last_count' => 'DESC' ), 1 );
			if ( $top ) {
				$rows[] = array(
					'label' => __( 'Top redirect', 'minn-admin' ),
					'value' => $top[0]['url'],
					'hint'  => number_format_i18n( (int) $top[0]['last_count'] ) . ' hits',
				);
			}
		}

		// Their log and 404 screens sit behind their own capabilities, not
		// the base plugin role: on a site that gave someone redirect
		// management WITHOUT log access, the card does not report log volume
		// they cannot open. An unanswerable gate is not an open one.
		$can = function ( $const ) {
			try {
				return minn_admin_redirection_has( $const );
			} catch ( \Throwable $e ) {
				return false;
			}
		};
		$logs   = $can( 'CAP_LOG_MANAGE' ) && Minn_Admin_Kernel_SQL::exists( 'redirection_logs' );
		$misses = $can( 'CAP_404_MANAGE' ) && Minn_Admin_Kernel_SQL::exists( 'redirection_404' );
		$week   = array( 'created' => Minn_Admin_Kernel::since( 'local', 7 * DAY_IN_SECONDS ) );
		$served = $logs ? Minn_Admin_Kernel_SQL::count( 'redirection_logs', $week ) : null;
		$missed = $misses ? Minn_Admin_Kernel_SQL::count( 'redirection_404', $week ) : null;
		if ( null !== $served ) {
			$rows[] = array( 'label' => __( 'Served, 7 days', 'minn-admin' ), 'value' => number_format_i18n( $served ) );
		}
		if ( null !== $missed ) {
			$rows[] = array( 'label' => __( '404s, 7 days', 'minn-admin' ), 'value' => number_format_i18n( $missed ) );
		}
		$out = array( 'rows' => $rows );

		// Redirects served (primary) over 404s (secondary), the sent/failed
		// idiom; only when a log holds anything, which keeps the card short
		// on log-less sites. Their list takes no day, so a bar carries none.
		if ( $served || $missed ) {
			$day    = array( 'column' => 'created', 'clock' => 'local', 'label' => 'M j' );
			$hits   = $logs ? Minn_Admin_Kernel_SQL::chart_days( $day + array( 'table' => 'redirection_logs' ) ) : array();
			$miss   = $misses ? Minn_Admin_Kernel_SQL::chart_days( $day + array( 'table' => 'redirection_404' ) ) : array();
			$points = array();
			foreach ( Minn_Admin_Kernel_SQL::empty_days( false, 'M j' ) as $d => $empty ) {
				$points[] = array(
					'label'     => $empty['label'],
					'value'     => (int) ( $hits[ $d ]['value'] ?? 0 ),
					'secondary' => (int) ( $miss[ $d ]['value'] ?? 0 ),
				);
			}
			$out['chart'] = array(
				'title'     => __( 'Last 14 days', 'minn-admin' ),
				'primary'   => 'Redirects',
				'secondary' => '404s',
				'points'    => $points,
			);
		}
		return $out;
	},
) );

minn_admin_endpoint( 'redirection/settings/(?P<tab>[a-z0-9_-]+)', array(
	'active' => 'minn_admin_redirection_ready',
	'gate'   => 'minn_admin_redirection_can_settings',
	'run'    => 'minn_admin_redirection_settings',
) );

minn_admin_endpoint( 'redirection/settings/(?P<tab>[a-z0-9_-]+)', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_redirection_ready',
	'gate'    => 'minn_admin_redirection_can_settings',
	'run'     => function ( WP_REST_Request $request ) {
		$vals = $request->get_param( 'values' );
		$vals = is_array( $vals ) ? $vals : array();
		$opts = red_get_options();
		$save = array();
		if ( array_key_exists( 'monitor', $vals ) ) {
			$on = ! empty( $vals['monitor'] );
			// Same shape the setup wizard writes: default group 1 + post/page types.
			$save['monitor_post']  = $on ? ( ! empty( $opts['monitor_post'] ) ? (int) $opts['monitor_post'] : 1 ) : 0;
			$save['monitor_types'] = $on ? array( 'post', 'page' ) : array();
		}
		// Each retention is written only when it was sent: -1 is Redirection's
		// "no logs", 0 its "forever", any other value days. A value outside
		// that range is refused, not clamped.
		foreach ( array( 'expire_redirect', 'expire_404' ) as $key ) {
			if ( ! array_key_exists( $key, $vals ) ) {
				continue;
			}
			$days = filter_var( $vals[ $key ], FILTER_VALIDATE_INT );
			if ( false === $days || $days < -1 || $days > 3650 ) {
				return new WP_Error( 'minn_redirection_retention', __( 'Choose how long to keep the log.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$save[ $key ] = $days;
		}
		if ( array_key_exists( 'ip_logging', $vals ) ) {
			$level = filter_var( $vals['ip_logging'], FILTER_VALIDATE_INT );
			if ( false === $level || $level < 0 || $level > 2 ) {
				return new WP_Error( 'minn_redirection_ip', __( 'Choose how IP addresses are stored.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$save['ip_logging'] = $level;
		}
		if ( $save ) {
			red_set_options( $save );
		}
		// The fresh tab, so the client repaints.
		return minn_admin_redirection_settings();
	},
) );

/**
 * Redirection's own log-retention choices (its settings screen offers
 * exactly these for both logs): -1 no logs, 0 forever, else days.
 *
 * @return array<int, array{0:string,1:string}>
 */
function minn_admin_redirection_retention_options() {
	return array(
		array( '-1', __( 'No logs', 'minn-admin' ) ),
		array( '1', __( 'A day', 'minn-admin' ) ),
		array( '7', __( 'A week', 'minn-admin' ) ),
		array( '30', __( 'A month', 'minn-admin' ) ),
		array( '60', __( 'Two months', 'minn-admin' ) ),
		array( '0', __( 'Forever', 'minn-admin' ) ),
	);
}
