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
			'columns'   => array(
				array( 'key' => 'url', 'label' => 'Source', 'format' => 'title' ),
				array( 'key' => 'action_data.url', 'label' => 'Target' ),
				array( 'key' => 'action_code', 'label' => 'Code', 'format' => 'mono' ),
				array( 'key' => 'hits', 'label' => 'Hits' ),
				array( 'key' => 'last_access', 'label' => 'Last hit' ),
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
