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
		'sub'        => 'Redirection',
		'icon'       => 'shuffle',
		'cap'        => apply_filters( 'redirection_role', 'manage_options' ),
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
				array( 'key' => 'last_access', 'label' => 'Last hit', 'format' => 'ago' ),
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
