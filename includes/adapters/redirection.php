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
		),
	);
	return $surfaces;
} );
