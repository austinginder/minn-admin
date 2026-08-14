<?php
/**
 * Bundled adapter: Code Snippets.
 *
 * Pure descriptor over Code Snippets' own REST API (code-snippets/v1). Lists
 * snippets with name, scope, active status, priority and last modified; detail
 * edits name/description/code/scope/priority/tags in place (same pattern as
 * Redirection); activate / deactivate / delete ride the plugin's own endpoints.
 * "Edit in Code Snippets ↗" remains for the full admin (safe mode, cloud, etc.).
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

	// Free-tier scopes from Snippet::get_all_scopes(). Pro CSS/JS scopes still
	// round-trip via preserve if present; the select covers the common set.
	$scope_options = array(
		array( 'global', 'Global (everywhere)' ),
		array( 'admin', 'Admin only' ),
		array( 'front-end', 'Front-end only' ),
		array( 'single-use', 'Single use' ),
		array( 'content', 'Content (shortcode)' ),
		array( 'head-content', 'Site head' ),
		array( 'footer-content', 'Site footer' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'placeholder' => __( 'Disable emojis', 'minn-admin' ) ),
		array( 'key' => 'desc', 'label' => __( 'Description', 'minn-admin' ), 'type' => 'textarea', 'rows' => 2, 'required' => false ),
		array(
			'key'         => 'code',
			'label'       => __( 'Code', 'minn-admin' ),
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => "add_filter( '…', '…' );",
		),
		array( 'key' => 'scope', 'label' => __( 'Scope', 'minn-admin' ), 'type' => 'select', 'options' => $scope_options ),
		array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'type' => 'number' ),
		array( 'key' => 'tags', 'label' => __( 'Tags', 'minn-admin' ), 'type' => 'tags', 'required' => false, 'placeholder' => __( 'media, sample', 'minn-admin' ) ),
	);

	// A Code Snippets snippet is PHP the plugin eval()s, so authoring one is code
	// editing in the sense DISALLOW_FILE_EDIT means, the same call Minn already
	// makes for WPCode PHP snippets. Writes here go to the plugin's OWN route, so
	// this is Minn declining to offer the affordance rather than a boundary; the
	// plugin's own screen is unaffected, exactly as it is for WPCode.
	$code_edits = ! class_exists( 'Minn_Admin' ) || Minn_Admin::code_edits_allowed();

	$surfaces['code-snippets'] = array(
		'label'      => __( 'Snippets', 'minn-admin' ),
		// Surfaces that share a family collapse to one sidebar item; the topbar
		// sub badge becomes a provider switcher when more than one is active.
		'family'     => 'snippets',
		'sub'        => 'Code Snippets',
		'icon'       => 'code',
		'cap'        => $cap,
		// Status card (v0.18.0): what's running at a glance. First card in
		// the snippets family.
		'status'     => array( 'route' => 'minn-admin/v1/code-snippets/status' ),
		'collection' => array(
			'route'     => 'code-snippets/v1/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			// Their list endpoint has no free-text search; names are scannable
			// in the title column and full code is editable in the detail.
			'create'    => array(
				'label'    => __( 'Add snippet', 'minn-admin' ),
				'route'    => 'code-snippets/v1/snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'   => false,
					'scope'    => 'global',
					'priority' => 10,
					'tags'     => array(),
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Snippet', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => __( 'Scope', 'minn-admin' ), 'format' => 'mono', 'width' => '100px' ),
				array( 'key' => 'active', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'format' => 'num', 'width' => '80px' ),
				// Code Snippets stores/returns modified via gmdate (UTC, no Z).
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				// Re-fetch so the modal always has full code + fresh active flag.
				'detailRoute' => 'code-snippets/v1/snippets/{id}',
				// Static rows hide editable fields; the form is the editor.
				'skip'        => array(
					'code', 'code_error', 'network', 'shared_network',
					'condition_id', 'cloud_id', 'revision', 'name', 'desc',
					'scope', 'priority', 'tags', 'active',
				),
				// In-place edit through PUT /snippets/{id} — same path as
				// activate/deactivate, so only fields present are updated.
				'edit'        => array(
					'route'    => 'code-snippets/v1/snippets/{id}',
					'method'   => 'PUT',
					// Keep activation + network flags when saving content.
					'preserve' => array( 'active', 'network', 'shared_network', 'condition_id' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				// PUT with {active} is the reliable toggle path; the dedicated
				// /activate|/deactivate routes exist but return an unprepared
				// Snippet object that can 500 under rest_ensure_response.
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => __( 'Edit in Code Snippets ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=edit-snippet&id={id}' ),
				),
				array(
					'label'   => __( 'Delete snippet', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'code-snippets/v1/snippets/{id}',
					'confirm' => __( 'Delete this snippet permanently? Its code will be gone.', 'minn-admin' ),
					'danger'  => true,
				),
			),
			// Their list has no active= filter, so bulk is the multi-item win.
			'bulk'      => array(
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'code-snippets/v1/snippets/{id}',
					'confirm' => __( 'Delete the selected snippets permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);

	if ( ! $code_edits ) {
		unset( $surfaces['code-snippets']['collection']['create'] );
		unset( $surfaces['code-snippets']['collection']['detail']['edit'] );
		// Activating a snippet runs its code. Deactivating one stops code
		// running, so it stays available on a hardened site.
		$surfaces['code-snippets']['collection']['actions'] = array_values(
			array_filter(
				$surfaces['code-snippets']['collection']['actions'],
				function ( $a ) {
					return empty( $a['label'] ) || 'Activate' !== $a['label'];
				}
			)
		);
	}

	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! defined( 'CODE_SNIPPETS_VERSION' ) ) {
		return;
	}
	// Status card: counts from their own table ({prefix}snippets; active
	// 1=active, 0=inactive, -1=trashed per their class-db docblock), the
	// latest change, and a safe-mode warning row when the constant is armed
	// (safe mode means NOTHING executes — worth saying out loud).
	register_rest_route( 'minn-admin/v1', '/code-snippets/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			$cap = function_exists( 'Code_Snippets\\code_snippets' )
				? \Code_Snippets\code_snippets()->get_cap_name()
				: 'manage_options';
			return current_user_can( $cap );
		},
		'callback'            => function () {
			global $wpdb;
			$table = $wpdb->prefix . 'snippets';
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return rest_ensure_response( array( 'rows' => array() ) );
			}
			$counts = $wpdb->get_results( "SELECT active, COUNT(*) AS c FROM {$table} GROUP BY active" ); // phpcs:ignore
			$active   = 0;
			$inactive = 0;
			$trashed  = 0;
			foreach ( (array) $counts as $r ) {
				if ( 1 === (int) $r->active ) {
					$active = (int) $r->c;
				} elseif ( -1 === (int) $r->active ) {
					$trashed = (int) $r->c;
				} else {
					$inactive = (int) $r->c;
				}
			}
			$hint = array();
			if ( $inactive ) {
				$hint[] = $inactive . ' inactive';
			}
			if ( $trashed ) {
				$hint[] = $trashed . ' trashed';
			}
			$rows = array(
				array(
					'label' => __( 'Active snippets', 'minn-admin' ),
					'value' => (string) $active,
					'hint'  => $hint ? implode( ' · ', $hint ) : 'nothing inactive',
				),
			);
			$scopes = $wpdb->get_results( "SELECT scope, COUNT(*) AS c FROM {$table} WHERE active = 1 GROUP BY scope ORDER BY c DESC LIMIT 3" ); // phpcs:ignore
			if ( $scopes ) {
				$rows[] = array(
					'label' => __( 'Running scopes', 'minn-admin' ),
					'value' => implode( ' · ', array_map( function ( $s ) {
						return $s->c . ' ' . $s->scope;
					}, $scopes ) ),
				);
			}
			$last = $wpdb->get_row( "SELECT name, modified FROM {$table} WHERE active >= 0 ORDER BY modified DESC LIMIT 1" ); // phpcs:ignore
			if ( $last && $last->modified && '0000-00-00 00:00:00' !== $last->modified ) {
				$rows[] = array(
					'label' => __( 'Last change', 'minn-admin' ),
					'value' => (string) $last->name,
					'hint'  => substr( (string) $last->modified, 0, 10 ),
				);
			}
			if ( defined( 'CODE_SNIPPETS_SAFE_MODE' ) && CODE_SNIPPETS_SAFE_MODE ) {
				$rows[] = array(
					'label' => __( 'Safe mode', 'minn-admin' ),
					'value' => 'On',
					'hint'  => __( 'No snippets are executing while safe mode is armed', 'minn-admin' ),
				);
			}
			return rest_ensure_response( array( 'rows' => $rows ) );
		},
	) );
} );
