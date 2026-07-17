<?php
/**
 * Bundled adapter: WPCode (Insert Headers and Footers).
 *
 * WPCode stores snippets as a private CPT (`wpcode`) with no public REST surface,
 * so this is the shim pattern (same idea as Gravity SMTP / Stream): a thin
 * minn-admin/v1/wpcode collection over WPCode_Snippet, plus a Snippets surface
 * that matches the Code Snippets UX (list, edit, toggle, create, delete).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wpcode_active() {
	return class_exists( 'WPCode_Snippet' ) || defined( 'WPCODE_VERSION' ) || defined( 'WPCODE_PLUGIN_VERSION' );
}

/**
 * Normalize a WPCode_Snippet into the shared Snippets list/detail shape.
 *
 * @param WPCode_Snippet $snippet Snippet object.
 * @return array
 */
function minn_admin_wpcode_item( $snippet ) {
	$post = $snippet->get_post_data();
	$modified = $post && $post->post_modified ? $post->post_modified : '';
	return array(
		'id'        => (int) $snippet->get_id(),
		'name'      => $snippet->get_title(),
		'desc'      => (string) $snippet->get_note(),
		'code'      => $snippet->get_code(),
		'tags'      => array_values( (array) $snippet->get_tags() ),
		// Column "scope" = type · location for a one-glance scan.
		'scope'     => trim( $snippet->get_code_type() . ' · ' . $snippet->get_location(), ' ·' ),
		'code_type' => $snippet->get_code_type(),
		'location'  => $snippet->get_location(),
		'active'    => (bool) $snippet->is_active(),
		'priority'  => (int) $snippet->get_priority(),
		'modified'  => $modified,
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wpcode_active() ) {
		return $surfaces;
	}

	$type_options = array(
		array( 'php', 'PHP' ),
		array( 'js', 'JavaScript' ),
		array( 'css', 'CSS' ),
		array( 'html', 'HTML' ),
		array( 'text', 'Text' ),
	);
	$location_options = array(
		array( 'everywhere', 'Everywhere' ),
		array( 'frontend_only', 'Front-end only' ),
		array( 'admin_only', 'Admin only' ),
		array( 'site_wide_header', 'Site-wide header' ),
		array( 'site_wide_body', 'Site-wide body' ),
		array( 'site_wide_footer', 'Site-wide footer' ),
		array( 'before_post', 'Before post' ),
		array( 'after_post', 'After post' ),
		array( 'before_content', 'Before content' ),
		array( 'after_content', 'After content' ),
		array( 'after_paragraph', 'After paragraph' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => 'Name', 'placeholder' => 'Disable comments' ),
		array( 'key' => 'desc', 'label' => 'Note', 'type' => 'textarea', 'rows' => 2, 'required' => false ),
		array(
			'key'         => 'code',
			'label'       => 'Code',
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => "add_filter( '…', '…' );",
		),
		array( 'key' => 'code_type', 'label' => 'Type', 'type' => 'select', 'options' => $type_options ),
		array( 'key' => 'location', 'label' => 'Location', 'type' => 'select', 'options' => $location_options ),
		array( 'key' => 'priority', 'label' => 'Priority', 'type' => 'number' ),
		array( 'key' => 'tags', 'label' => 'Tags', 'type' => 'tags', 'required' => false ),
	);

	$surfaces['wpcode'] = array(
		'label'      => 'Snippets',
		'family'     => 'snippets',
		'sub'        => 'WPCode',
		'icon'       => 'code',
		'cap'        => 'wpcode_edit_snippets',
		// Status card (v0.18.0): family parity with Code Snippets.
		'status'     => array( 'route' => 'minn-admin/v1/wpcode/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/wpcode/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'filter'    => array(
				'label'   => 'Status',
				'options' => array(
					array( 'all', 'All' ),
					array( 'active', 'Active' ),
					array( 'inactive', 'Inactive' ),
				),
				'query'   => 'active={v}',
			),
			'create'    => array(
				'label'    => 'Add snippet',
				'route'    => 'minn-admin/v1/wpcode/snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'    => false,
					'code_type' => 'php',
					'location'  => 'everywhere',
					'priority'  => 10,
					'tags'      => array(),
					'code'      => '',
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'Snippet', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => 'Type · location', 'format' => 'mono', 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'active', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => 'Priority', 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'modified', 'label' => 'Modified', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/wpcode/snippets/{id}',
				'skip'        => array(
					'code', 'name', 'desc', 'scope', 'code_type', 'location',
					'priority', 'tags', 'active',
				),
				'edit'        => array(
					'route'    => 'minn-admin/v1/wpcode/snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'active' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => 'Edit in WPCode ↗',
					'href'  => admin_url( 'admin.php?page=wpcode-snippet-manager&snippet_id={id}' ),
				),
				array(
					'label'   => 'Delete snippet',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpcode/snippets/{id}',
					'confirm' => 'Delete this snippet permanently? Its code will be gone.',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpcode/snippets/{id}',
					'confirm' => 'Delete the selected snippets permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpcode_active() || ! class_exists( 'WPCode_Snippet' ) ) {
		return;
	}

	$can_edit = function () {
		return current_user_can( 'wpcode_edit_snippets' );
	};
	$can_act = function () {
		return current_user_can( 'wpcode_activate_snippets' );
	};

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?? 25 ) ) );
					$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
					// Active filter: WPCode stores active as post status
					// publish=active, draft/private=inactive (their model).
					$active = sanitize_key( (string) ( $request['active'] ?? 'all' ) );
					$status = array( 'publish', 'draft', 'private' );
					if ( 'active' === $active ) {
						$status = array( 'publish' );
					} elseif ( 'inactive' === $active ) {
						$status = array( 'draft', 'private' );
					}
					$q = new WP_Query(
						array(
							'post_type'      => 'wpcode',
							'post_status'    => $status,
							'posts_per_page' => $per_page,
							'paged'          => $page,
							'orderby'        => 'modified',
							'order'          => 'DESC',
						)
					);
					$items = array();
					foreach ( $q->posts as $post ) {
						$items[] = minn_admin_wpcode_item( new WPCode_Snippet( $post ) );
					}
					return rest_ensure_response(
						array(
							'items' => $items,
							'total' => (int) $q->found_posts,
						)
					);
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					// load_from_array (via the array constructor) can set private
					// fields like priority/note that are not assignable from outside.
					$snippet = new WPCode_Snippet(
						array(
							'title'       => sanitize_text_field( (string) $request['name'] ),
							'code'        => (string) ( $request['code'] ?? '' ),
							'code_type'   => sanitize_key( (string) ( $request['code_type'] ?? 'php' ) ),
							'location'    => sanitize_key( (string) ( $request['location'] ?? 'everywhere' ) ),
							'priority'    => (int) ( $request['priority'] ?? 10 ),
							'tags'        => array_map( 'sanitize_text_field', (array) ( $request['tags'] ?? array() ) ),
							'note'        => sanitize_textarea_field( (string) ( $request['desc'] ?? '' ) ),
							'auto_insert' => 1,
							'active'      => ! empty( $request['active'] ),
						)
					);
					$id = $snippet->save();
					if ( ! $id ) {
						return new WP_Error( 'wpcode_save_failed', 'Could not create the snippet.', array( 'status' => 500 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $id ) ) );
				},
			),
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$snippet = new WPCode_Snippet( (int) $request['id'] );
					if ( ! $snippet->get_id() ) {
						return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( $snippet ) );
				},
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$snippet = new WPCode_Snippet( (int) $request['id'] );
					if ( ! $snippet->get_id() ) {
						return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
					}
					// Hydrate getters so load_from_array merges onto real state.
					$snippet->get_title();
					$snippet->get_code();
					$snippet->get_code_type();
					$snippet->get_location();
					$snippet->get_priority();
					$snippet->get_tags();
					$snippet->get_note();
					$snippet->is_active();
					// Location is only written when auto_insert === 1 (WPCode
					// save() gate) — force it so location edits stick.
					$snippet->get_auto_insert();

					$patch = array( 'auto_insert' => 1 );
					if ( null !== $request['name'] ) {
						$patch['title'] = sanitize_text_field( (string) $request['name'] );
					}
					if ( null !== $request['code'] ) {
						$patch['code'] = (string) $request['code'];
					}
					if ( null !== $request['code_type'] ) {
						$patch['code_type'] = sanitize_key( (string) $request['code_type'] );
					}
					if ( null !== $request['location'] ) {
						$patch['location'] = sanitize_key( (string) $request['location'] );
					}
					if ( null !== $request['priority'] ) {
						$patch['priority'] = (int) $request['priority'];
					}
					if ( null !== $request['tags'] ) {
						$patch['tags'] = array_map( 'sanitize_text_field', (array) $request['tags'] );
					}
					if ( null !== $request['desc'] ) {
						$patch['note'] = sanitize_textarea_field( (string) $request['desc'] );
					}
					if ( null !== $request['active'] && current_user_can( 'wpcode_activate_snippets' ) ) {
						$patch['active'] = (bool) $request['active'];
					}
					$snippet->load_from_array( $patch );
					if ( ! $snippet->save() ) {
						return new WP_Error( 'wpcode_save_failed', 'Could not save the snippet.', array( 'status' => 500 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $request['id'] ) ) );
				},
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$id = (int) $request['id'];
					$post = get_post( $id );
					if ( ! $post || 'wpcode' !== $post->post_type ) {
						return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
					}
					$ok = wp_delete_post( $id, true );
					if ( ! $ok ) {
						return new WP_Error( 'wpcode_delete_failed', 'Could not delete the snippet.', array( 'status' => 500 ) );
					}
					return new WP_REST_Response( null, 204 );
				},
			),
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets/(?P<id>\d+)/active',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_act,
			'callback'            => function ( WP_REST_Request $request ) {
				$snippet = new WPCode_Snippet( (int) $request['id'] );
				if ( ! $snippet->get_id() ) {
					return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
				}
				if ( ! empty( $request['active'] ) ) {
					$snippet->activate();
				} else {
					$snippet->deactivate();
				}
				return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $request['id'] ) ) );
			},
			'args'                => array(
				'active' => array( 'type' => 'boolean', 'required' => true ),
			),
		)
	);
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpcode_active() ) {
		return;
	}
	// Status card: WPCode snippets are a CPT — publish = active, everything
	// else inactive (their model, same rule the list filter uses). Code types
	// ride the _wpcode_code_type meta on active snippets.
	register_rest_route( 'minn-admin/v1', '/wpcode/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'wpcode_edit_snippets' );
		},
		'callback'            => function () {
			global $wpdb;
			$counts   = wp_count_posts( 'wpcode' );
			$active   = isset( $counts->publish ) ? (int) $counts->publish : 0;
			$inactive = 0;
			foreach ( array( 'draft', 'private', 'pending' ) as $st ) {
				$inactive += isset( $counts->$st ) ? (int) $counts->$st : 0;
			}
			$rows = array(
				array(
					'label' => 'Active snippets',
					'value' => (string) $active,
					'hint'  => $inactive ? $inactive . ' inactive' : 'nothing inactive',
				),
			);
			$types = $wpdb->get_results(
				"SELECT pm.meta_value AS t, COUNT(*) AS c FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wpcode_code_type'
				 WHERE p.post_type = 'wpcode' AND p.post_status = 'publish'
				 GROUP BY pm.meta_value ORDER BY c DESC LIMIT 3"
			);
			if ( $types ) {
				$rows[] = array(
					'label' => 'Running types',
					'value' => implode( ' · ', array_map( function ( $t ) {
						return $t->c . ' ' . $t->t;
					}, $types ) ),
				);
			}
			$last = $wpdb->get_row(
				"SELECT post_title, post_modified FROM {$wpdb->posts}
				 WHERE post_type = 'wpcode' AND post_status IN ( 'publish', 'draft', 'private' )
				 ORDER BY post_modified DESC LIMIT 1"
			);
			if ( $last ) {
				$rows[] = array(
					'label' => 'Last change',
					'value' => (string) $last->post_title,
					'hint'  => substr( (string) $last->post_modified, 0, 10 ),
				);
			}
			return rest_ensure_response( array( 'rows' => $rows ) );
		},
	) );
} );
