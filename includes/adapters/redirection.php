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
				array( 'key' => 'url', 'label' => 'Source', 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'action_data.url', 'label' => 'Target', 'format' => 'mono', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'action_code', 'label' => 'Code', 'format' => 'mono', 'width' => '64px' ),
				array( 'key' => 'hits', 'label' => 'Hits', 'format' => 'num', 'width' => '72px' ),
				// last_access is stored via gmdate (UTC, no zone).
				array( 'key' => 'last_access', 'label' => 'Last hit', 'format' => 'ago', 'utc' => true ),
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
