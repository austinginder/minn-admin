<?php
/**
 * Bundled adapter: Code Snippets.
 *
 * Pure descriptor over Code Snippets' own REST API (code-snippets/v1). Lists
 * snippets with name, scope, active status, priority and last modified; detail
 * shows the code body as a mono block; activate / deactivate / delete ride the
 * plugin's own endpoints. Edit is a deep link into the Code Snippets admin.
 *
 * See docs/code-snippets.md for the source audit and why this plugin is the
 * first snippet adapter (full CRUD REST, manage_options cap, clean table).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! defined( 'CODE_SNIPPETS_VERSION' ) ) {
		return $surfaces;
	}

	// Cap is filterable in Code Snippets itself; mirror it so Minn and the
	// plugin's own admin stay in lockstep.
	$cap = 'manage_options';
	if ( function_exists( 'Code_Snippets\\code_snippets' ) ) {
		$cap = \Code_Snippets\code_snippets()->get_cap_name();
	}

	$surfaces['code-snippets'] = array(
		'label'      => 'Snippets',
		'sub'        => 'Code Snippets',
		'icon'       => 'code',
		'cap'        => $cap,
		'collection' => array(
			'route'     => 'code-snippets/v1/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			// Their list endpoint has no free-text search; name/desc still land
			// in the detail and are scannable in the list title column.
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'Snippet', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => 'Scope', 'format' => 'mono', 'width' => '100px' ),
				array( 'key' => 'active', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => 'Priority', 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'modified', 'label' => 'Modified', 'format' => 'ago' ),
			),
			'detail'    => array(
				// Re-fetch so the modal always has full code + fresh active flag.
				'detailRoute' => 'code-snippets/v1/snippets/{id}',
				// Code body as the main panel (plain-text <pre>, not an iframe).
				'messageKey'  => 'code',
				'skip'        => array(
					'code', 'code_error', 'network', 'shared_network',
					'condition_id', 'cloud_id', 'revision',
				),
			),
			'actions'   => array(
				// PUT with {active} is the reliable toggle path; the dedicated
				// /activate|/deactivate routes exist but return an unprepared
				// Snippet object that can 500 under rest_ensure_response.
				array(
					'label'  => 'Activate',
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => 'Edit in Code Snippets ↗',
					'href'  => admin_url( 'admin.php?page=edit-snippet&id={id}' ),
				),
				array(
					'label'   => 'Delete snippet',
					'method'  => 'DELETE',
					'route'   => 'code-snippets/v1/snippets/{id}',
					'confirm' => 'Delete this snippet permanently? Its code will be gone.',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );
