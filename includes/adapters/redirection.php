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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! defined( 'REDIRECTION_VERSION' ) ) {
		return $surfaces;
	}

	$surfaces['redirection'] = array(
		'label'      => 'Redirects',
		'family'     => 'redirects',
		'sub'        => 'Redirection',
		'icon'       => 'shuffle',
		'cap'        => apply_filters( 'redirection_role', 'manage_options' ),
		// Fresh installs have no tables or default group until Redirection's
		// setup wizard runs, so every write fails ("Invalid group"). The gate
		// runs THEIR installer (Red_Latest_Database::install() is the same
		// create-tables + create-groups sequence their wizard drives) and
		// mirrors the wizard's three questions as toggles. Monitoring on and
		// IP logging off by default: the wizard's spirit, and IP storage is
		// a privacy choice Minn must not make silently.
		'setup'      => array(
			'needed'  => function () {
				if ( ! defined( 'REDIRECTION_FILE' ) ) {
					return false;
				}
				include_once dirname( REDIRECTION_FILE ) . '/database/database.php';
				if ( ! class_exists( 'Red_Database_Status' ) ) {
					return false;
				}
				return ( new Red_Database_Status() )->needs_installing();
			},
			'title'   => 'Redirection needs its one-time setup',
			'note'    => 'Redirection stores redirects in its own database tables, which it creates on first setup. This runs the same install its own setup wizard performs; the choices below are the wizard\'s questions.',
			'options' => array(
				array(
					'id'      => 'monitor',
					'label'   => 'Monitor permalink changes in posts and pages, and add a redirect when they change',
					'default' => true,
				),
				array(
					'id'      => 'log',
					'label'   => 'Keep a log of redirects and 404 errors (kept for 7 days)',
					'default' => true,
				),
				array(
					'id'      => 'ip',
					'label'   => 'Store IP addresses with logged redirects and errors',
					'default' => false,
				),
			),
			'run'     => function ( $choices ) {
				include_once dirname( REDIRECTION_FILE ) . '/database/database.php';
				$result = Red_Database::get_latest_database()->install();
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				( new Red_Database_Status() )->finish();
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
			// Their filter route accepts orderby source|last_count|last_access
			// (plus position/id) with direction asc|desc.
			'sortQuery' => 'orderby={by}&direction={dir}',
			'pageQuery' => 'per_page=25&page={page0}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'filterBy[url]={q}',
			'create'    => array(
				'label'    => 'Add redirect',
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
				'fields'   => array(
					array( 'key' => 'url', 'label' => 'Source URL', 'mono' => true, 'placeholder' => '/old-page' ),
					array( 'key' => 'action_data.url', 'label' => 'Target URL', 'mono' => true, 'placeholder' => '/new-page or https://…' ),
					array( 'key' => 'action_code', 'label' => 'HTTP status', 'type' => 'number', 'value' => 301 ),
				),
			),
			'columns'   => array(
				array( 'key' => 'url', 'label' => 'Source', 'format' => 'title', 'width' => 'minmax(0,1.4fr)', 'sort' => 'source' ),
				array( 'key' => 'action_data.url', 'label' => 'Target', 'format' => 'mono', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'action_code', 'label' => 'Code', 'format' => 'mono', 'width' => '64px' ),
				array( 'key' => 'hits', 'label' => 'Hits', 'format' => 'num', 'width' => '72px', 'sort' => 'last_count' ),
				// last_access is stored via gmdate (UTC, no zone).
				array( 'key' => 'last_access', 'label' => 'Last hit', 'format' => 'ago', 'utc' => true, 'sort' => 'last_access' ),
			),
			'detail'    => array(
				'skip' => array( 'match_data', 'match_type', 'match_url', 'position', 'group_id' ),
				// Basic in-place edit — Redirection's own update endpoint (POST /redirect/{id}).
				// `preserve` keeps the untouched fields so the sanitizer doesn't reset them.
				'edit' => array(
					'route'    => 'redirection/v1/redirect/{id}',
					'method'   => 'POST',
					'preserve' => array( 'match_type', 'action_type', 'group_id', 'title', 'regex' ),
					'fields'   => array(
						array( 'key' => 'url', 'label' => 'Source URL', 'mono' => true ),
						array( 'key' => 'action_data.url', 'label' => 'Target URL', 'mono' => true ),
						array( 'key' => 'action_code', 'label' => 'HTTP status', 'type' => 'number' ),
					),
				),
			),
			'actions'   => array(
				array(
					'label'  => 'Disable',
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/disable?items={id}',
				),
				array(
					'label'  => 'Enable',
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/enable?items={id}',
				),
				array(
					'label'   => 'Delete redirect',
					'method'  => 'POST',
					'route'   => 'redirection/v1/bulk/redirect/delete?items={id}',
					'confirm' => 'Delete this redirect permanently?',
					'danger'  => true,
				),
			),
			// Same routes as single actions — Redirection's bulk endpoint is
			// already per-item under the hood.
			'bulk'      => array(
				array(
					'label'  => 'Disable',
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/disable?items={id}',
				),
				array(
					'label'  => 'Enable',
					'method' => 'POST',
					'route'  => 'redirection/v1/bulk/redirect/enable?items={id}',
				),
				array(
					'label'   => 'Delete',
					'method'  => 'POST',
					'route'   => 'redirection/v1/bulk/redirect/delete?items={id}',
					'confirm' => 'Delete the selected redirects permanently?',
					'danger'  => true,
				),
			),
		),
		// Status card (v0.18.0): rule counts, served/404 traffic and a
		// dual-series 14-day chart from Redirection's own log tables.
		'status'     => array( 'route' => 'minn-admin/v1/redirection/status' ),
		// Daily options only (monitor + logging + IP). Schema served at
		// request time; writes go through red_set_options (their sanitizer).
		'settings'   => array(
			'label' => 'Settings',
			'cap'   => apply_filters( 'redirection_role', 'manage_options' ),
			'tabs'  => array(
				array( 'id' => 'general', 'label' => 'General' ),
			),
			'route' => 'minn-admin/v1/redirection/settings/{tab}',
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! defined( 'REDIRECTION_VERSION' ) || ! function_exists( 'red_get_options' ) ) {
		return;
	}
	$perm = function () {
		$cap = apply_filters( 'redirection_role', 'manage_options' );
		return current_user_can( $cap );
	};

	// Status card. Counts from Redirection's own tables (SHOW TABLES-gated:
	// a pre-setup install has none). Log timestamps are current_time('mysql')
	// site-local; DATE(created) buckets match Redirection's own log view.
	// Retention is their log_expiry setting (7 days by default), so the
	// "served" numbers honestly say what the log still holds.
	register_rest_route( 'minn-admin/v1', '/redirection/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			global $wpdb;
			$has = function ( $suffix ) use ( $wpdb ) {
				$table = $wpdb->prefix . $suffix;
				return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			};
			$rows = array();

			if ( $has( 'redirection_items' ) ) {
				$items = $wpdb->get_results( "SELECT status, COUNT(*) AS c, SUM(last_count) AS hits FROM {$wpdb->prefix}redirection_items GROUP BY status" ); // phpcs:ignore
				$enabled  = 0;
				$disabled = 0;
				$hits     = 0;
				foreach ( (array) $items as $r ) {
					if ( 'enabled' === $r->status ) {
						$enabled = (int) $r->c;
					} else {
						$disabled += (int) $r->c;
					}
					$hits += (int) $r->hits;
				}
				$rows[] = array(
					'label' => 'Redirect rules',
					'value' => (string) $enabled,
					'hint'  => $disabled ? $disabled . ' disabled' : 'all enabled',
				);
				$rows[] = array( 'label' => 'Hits, all time', 'value' => number_format_i18n( $hits ) );
				$top = $wpdb->get_row( "SELECT url, last_count FROM {$wpdb->prefix}redirection_items WHERE last_count > 0 ORDER BY last_count DESC LIMIT 1" ); // phpcs:ignore
				if ( $top ) {
					$rows[] = array(
						'label' => 'Top redirect',
						'value' => $top->url,
						'hint'  => number_format_i18n( (int) $top->last_count ) . ' hits',
					);
				}
			}

			$since  = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 7 * DAY_IN_SECONDS );
			$served = $has( 'redirection_logs' )
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_logs WHERE created >= %s", $since ) ) // phpcs:ignore
				: null;
			$missed = $has( 'redirection_404' )
				? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}redirection_404 WHERE created >= %s", $since ) ) // phpcs:ignore
				: null;
			if ( null !== $served ) {
				$rows[] = array( 'label' => 'Served, 7 days', 'value' => number_format_i18n( $served ) );
			}
			if ( null !== $missed ) {
				$rows[] = array( 'label' => '404s, 7 days', 'value' => number_format_i18n( $missed ) );
			}

			$out = array( 'rows' => $rows );

			// Dual-series daily chart: redirects served (primary) over 404s
			// (secondary), the sent/failed idiom. Only when a log table holds
			// anything in the window — an all-zero chart collapses client-side
			// anyway, but skipping keeps the card short on log-less sites.
			if ( ( $served || $missed ) && ( $has( 'redirection_logs' ) || $has( 'redirection_404' ) ) ) {
				$days   = 14;
				$start  = strtotime( gmdate( 'Y-m-d', strtotime( current_time( 'mysql' ) ) ) ) - ( $days - 1 ) * DAY_IN_SECONDS;
				$startd = gmdate( 'Y-m-d 00:00:00', $start );
				$bucket = function ( $suffix ) use ( $wpdb, $has, $startd ) {
					if ( ! $has( $suffix ) ) {
						return array();
					}
					$out  = array();
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created) AS d, COUNT(*) AS c FROM {$wpdb->prefix}{$suffix} WHERE created >= %s GROUP BY DATE(created)", $startd ) ); // phpcs:ignore
					foreach ( (array) $rows as $r ) {
						$out[ (string) $r->d ] = (int) $r->c;
					}
					return $out;
				};
				$hitmap = $bucket( 'redirection_logs' );
				$missmap = $bucket( 'redirection_404' );
				$points = array();
				for ( $i = 0; $i < $days; $i++ ) {
					$day = gmdate( 'Y-m-d', $start + $i * DAY_IN_SECONDS );
					$points[] = array(
						'label'     => gmdate( 'M j', strtotime( $day ) ),
						'value'     => isset( $hitmap[ $day ] ) ? $hitmap[ $day ] : 0,
						'secondary' => isset( $missmap[ $day ] ) ? $missmap[ $day ] : 0,
					);
				}
				$out['chart'] = array(
					'title'     => 'Last 14 days',
					'primary'   => 'Redirects',
					'secondary' => '404s',
					'points'    => $points,
				);
			}

			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/redirection/settings/(?P<tab>[a-z0-9_-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function () {
				$opts = red_get_options();
				// monitor_post is a group id when on; 0 is off.
				$monitor_on = ! empty( $opts['monitor_post'] );
				$log_days   = isset( $opts['expire_redirect'] ) ? (int) $opts['expire_redirect'] : 7;
				$log_on     = $log_days >= 0;
				$ip_on      = ! empty( $opts['ip_logging'] );
				return rest_ensure_response( array(
					'groups' => array(
						array(
							'title'  => 'Permalink monitor',
							'fields' => array(
								array(
									'key'   => 'monitor',
									'label' => 'Monitor permalink changes',
									'type'  => 'toggle',
									'help'  => 'Add a redirect when a post or page slug changes.',
								),
							),
						),
						array(
							'title'  => 'Logging',
							'fields' => array(
								array(
									'key'   => 'log',
									'label' => 'Keep a log of redirects and 404s',
									'type'  => 'toggle',
									'help'  => 'When on, logs are kept for the number of days below.',
								),
								array(
									'key'      => 'expire_days',
									'label'    => 'Keep logs for (days)',
									'type'     => 'number',
									'min'      => 1,
									'max'      => 60,
									'showWhen' => array( 'key' => 'log', 'equals' => true ),
								),
								array(
									'key'      => 'ip_logging',
									'label'    => 'Store IP addresses with logs',
									'type'     => 'toggle',
									'help'     => 'A privacy choice — off by default on fresh installs.',
									'showWhen' => array( 'key' => 'log', 'equals' => true ),
								),
							),
						),
					),
					'values'   => array(
						'monitor'     => $monitor_on,
						'log'         => $log_on,
						'expire_days' => $log_on ? max( 1, $log_days ) : 7,
						'ip_logging'  => $ip_on,
					),
					'adminUrl' => admin_url( 'tools.php?page=redirection.php' ),
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$vals = $request->get_param( 'values' );
				if ( ! is_array( $vals ) ) {
					$vals = array();
				}
				$opts    = red_get_options();
				$payload = array();

				if ( array_key_exists( 'monitor', $vals ) ) {
					$on = ! empty( $vals['monitor'] );
					// Same shape the setup wizard writes: default group 1 + post/page types.
					$payload['monitor_post']  = $on ? ( ! empty( $opts['monitor_post'] ) ? (int) $opts['monitor_post'] : 1 ) : 0;
					$payload['monitor_types'] = $on ? array( 'post', 'page' ) : array();
				}
				if ( array_key_exists( 'log', $vals ) || array_key_exists( 'expire_days', $vals ) ) {
					$log_on = array_key_exists( 'log', $vals )
						? ! empty( $vals['log'] )
						: ( isset( $opts['expire_redirect'] ) && (int) $opts['expire_redirect'] >= 0 );
					$days   = array_key_exists( 'expire_days', $vals )
						? max( 1, min( 60, (int) $vals['expire_days'] ) )
						: ( isset( $opts['expire_redirect'] ) && (int) $opts['expire_redirect'] > 0 ? (int) $opts['expire_redirect'] : 7 );
					// -1 disables logging (their convention).
					$payload['expire_redirect'] = $log_on ? $days : -1;
					$payload['expire_404']      = $log_on ? $days : -1;
				}
				if ( array_key_exists( 'ip_logging', $vals ) ) {
					$payload['ip_logging'] = ! empty( $vals['ip_logging'] ) ? 1 : 0;
				}
				if ( $payload ) {
					red_set_options( $payload );
				}

				// Return a fresh GET so the client repaints.
				$req = new WP_REST_Request( 'GET', '/minn-admin/v1/redirection/settings/general' );
				$res = rest_do_request( $req );
				return $res;
			},
		),
	) );
} );
