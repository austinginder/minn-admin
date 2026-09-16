<?php
/**
 * Bundled adapter: JetThemeCore theme templates (Crocoblock, builder-templates family).
 *
 * JetThemeCore stores every theme part (header, footer, single, archive,
 * page, section) as a `jet-theme-core` post: the structure in
 * `_jet_template_type`, the canvas in `_jet_template_content_type`
 * ('default' = block editor, 'elementor'), display conditions in
 * `_jet_template_conditions`. Minn hides that CPT from Content on purpose
 * (plumbing, the elementor_library rule), so this surface is the listing:
 * type tabs from their structures registry, the canvas as a pill, a
 * plain-language conditions summary (their own verbose renderer, tags
 * stripped), Active when their conditions manager resolves the template,
 * rename in place, add through their create_template() (which seeds the
 * canvas meta per content type), trash, and Edit ↗ to their edit link.
 * Condition authoring and the canvases stay in JetThemeCore.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_tc_active() {
	return function_exists( 'jet_theme_core' ) && is_object( jet_theme_core()->templates ) && is_object( jet_theme_core()->structures ) && post_type_exists( 'jet-theme-core' );
}

/**
 * Whether the current user may manage theme-core templates.
 *
 * The edit_posts line inside their create_template() is not the gate: the
 * jet-theme-core post type remaps every capability to manage_options, and
 * their REST endpoints inherit a base permission_callback that asks for
 * manage_options too, so that inner check only ever runs behind a locked
 * door. Reading the post type's own remapped capability asks the question
 * their door asks, and keeps answering it correctly if they change it.
 */
function minn_admin_jet_tc_cap() {
	$pto = get_post_type_object( 'jet-theme-core' );
	return $pto ? (string) $pto->cap->edit_posts : 'manage_options';
}

function minn_admin_jet_tc_can() {
	return current_user_can( minn_admin_jet_tc_cap() );
}

/** id => label from their structures registry. */
function minn_admin_jet_tc_types() {
	try {
		return (array) jet_theme_core()->structures->get_structures_for_post_type();
	} catch ( \Throwable $e ) {
		return array();
	}
}

function minn_admin_jet_tc_content_types() {
	$out = array( 'default' => __( 'Block Editor', 'minn-admin' ) );
	try {
		foreach ( (array) jet_theme_core()->templates->get_template_content_type_options() as $opt ) {
			if ( is_array( $opt ) && isset( $opt['value'] ) ) {
				$out[ (string) $opt['value'] ] = (string) ( $opt['label'] ?? $opt['value'] );
			}
		}
	} catch ( \Throwable $e ) {
		return $out;
	}
	return $out;
}

/** Their verbose conditions block as one plain line. */
function minn_admin_jet_tc_conditions( $post_id ) {
	try {
		$m = jet_theme_core()->template_conditions_manager;
		if ( ! is_object( $m ) || ! method_exists( $m, 'post_conditions_verbose' ) ) {
			return '';
		}
		$html = (string) $m->post_conditions_verbose( $post_id );
	} catch ( \Throwable $e ) {
		return '';
	}
	$html = preg_replace( '#</div>#', ' · ', $html );
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	return trim( $text, ' ·' );
}

function minn_admin_jet_tc_item( $post ) {
	$types  = minn_admin_jet_tc_types();
	$ctypes = minn_admin_jet_tc_content_types();
	$type   = (string) get_post_meta( $post->ID, '_jet_template_type', true );
	$ctype  = (string) get_post_meta( $post->ID, '_jet_template_content_type', true );
	$edit   = '';
	try {
		$edit = (string) jet_theme_core()->templates->get_template_edit_link( $post->ID, $ctype ? $ctype : 'default' );
	} catch ( \Throwable $e ) {
		$edit = '';
	}
	return array(
		'id'           => (int) $post->ID,
		'title'        => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'minn-admin' ),
		'type'         => $type,
		'typeLabel'    => $type ? ( $types[ $type ] ?? $type ) : __( 'Unassigned', 'minn-admin' ),
		'contentType'  => $ctypes[ $ctype ] ?? ( $ctype ? $ctype : $ctypes['default'] ),
		'conditions'   => minn_admin_jet_tc_conditions( $post->ID ),
		'status'       => $post->post_status,
		'modified'     => mysql2date( 'c', $post->post_modified, false ),
		'editUrl'      => $edit ? $edit : get_edit_post_link( $post->ID, 'raw' ),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_jet_tc_active() || ! minn_admin_jet_tc_can() ) {
		return $surfaces;
	}
	$type_tabs   = array();
	$type_select = array();
	foreach ( minn_admin_jet_tc_types() as $id => $label ) {
		$type_tabs[]   = array( $id, $label );
		$type_select[] = array( $id, $label );
	}
	$type_tabs[] = array( 'unassigned', __( 'Unassigned', 'minn-admin' ) );
	$ct_select   = array();
	foreach ( minn_admin_jet_tc_content_types() as $id => $label ) {
		$ct_select[] = array( $id, $label );
	}
	$surfaces['jet-theme-core'] = array(
		'label'      => __( 'Templates', 'minn-admin' ),
		'sub'        => 'JetThemeCore',
		'plugin'     => 'jet-theme-core',
		'family'     => 'builder-templates',
		'icon'       => 'columns',
		// Derived from the same place minn_admin_jet_tc_can() reads, so the
		// descriptor and the gate cannot answer differently if JetThemeCore
		// changes its capabilities map. It said edit_posts while every route
		// resolved to manage_options.
		'cap'        => minn_admin_jet_tc_cap(),
		'status'     => array( 'route' => 'minn-admin/v1/jet-theme-core/templates/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/jet-theme-core/templates',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'pageQuery' => 'per_page=25&page={page}',
			'tabs'      => array( 'param' => 'type', 'static' => $type_tabs, 'allLabel' => __( 'All', 'minn-admin' ) ),
			'search'    => 'search={q}',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Template', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'typeLabel', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'pill', 'width' => '120px' ),
				array( 'key' => 'contentType', 'label' => __( 'Canvas', 'minn-admin' ), 'width' => '120px' ),
				array( 'key' => 'conditions', 'label' => __( 'Conditions', 'minn-admin' ), 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago' ),
			),
			'create'    => array(
				'label'  => __( 'Add template', 'minn-admin' ),
				'route'  => 'minn-admin/v1/jet-theme-core/templates',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
					array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_select ),
					array( 'key' => 'contentType', 'label' => __( 'Canvas', 'minn-admin' ), 'type' => 'select', 'options' => $ct_select ),
				),
			),
			'detail'    => array(
				'skip' => array( 'id', 'type', 'typeLabel', 'editUrl', 'status' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/jet-theme-core/templates/{id}',
					'method' => 'PUT',
					'fields' => array( array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ) ),
				),
			),
			'actions'   => array(
				array( 'label' => __( 'Edit ↗', 'minn-admin' ), 'href' => '{editUrl}' ),
				array(
					'label'   => __( 'Move to trash', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/jet-theme-core/templates/{id}',
					'confirm' => __( 'Move this template to the trash? Anywhere it is assigned stops using it.', 'minn-admin' ),
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'   => __( 'Move to trash', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/jet-theme-core/templates/{id}',
					'confirm' => __( 'Move the selected templates to the trash?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_tc_active() ) {
		return;
	}
	$perm = 'minn_admin_jet_tc_can';
	$find = function ( $id ) {
		$p = get_post( (int) $id );
		return ( $p && 'jet-theme-core' === $p->post_type && 'trash' !== $p->post_status ) ? $p : null;
	};

	register_rest_route( 'minn-admin/v1', '/jet-theme-core/templates', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
				$type     = sanitize_key( (string) $request->get_param( 'type' ) );
				$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
				$args     = array(
					'post_type'      => 'jet-theme-core',
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => $per_page,
					'paged'          => $page,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					's'              => $search,
				);
				if ( 'unassigned' === $type ) {
					$args['meta_query'] = array( 'relation' => 'OR', array( 'key' => '_jet_template_type', 'compare' => 'NOT EXISTS' ), array( 'key' => '_jet_template_type', 'value' => '' ) );
				} elseif ( '' !== $type ) {
					$args['meta_query'] = array( array( 'key' => '_jet_template_type', 'value' => $type ) );
				}
				$q     = new WP_Query( $args );
				$items = array();
				foreach ( $q->posts as $p ) {
					if ( current_user_can( 'edit_post', $p->ID ) ) {
						$items[] = minn_admin_jet_tc_item( $p );
					}
				}
				return rest_ensure_response( array( 'items' => $items, 'total' => (int) $q->found_posts ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
				$type  = sanitize_key( (string) $request->get_param( 'type' ) );
				$ct    = sanitize_key( (string) $request->get_param( 'contentType' ) );
				if ( ! isset( minn_admin_jet_tc_types()[ $type ] ) ) {
					return new WP_Error( 'bad_type', __( 'Pick a template type.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( ! isset( minn_admin_jet_tc_content_types()[ $ct ] ) ) {
					$ct = 'default';
				}
				try {
					// Their own create: seeds the canvas meta per content type.
					$res = jet_theme_core()->templates->create_template( $type, $ct, $title, array() );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'create_failed', __( 'JetThemeCore could not create the template.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				if ( ! is_array( $res ) || empty( $res['newTemplateId'] ) ) {
					return new WP_Error( 'create_failed', is_array( $res ) && ! empty( $res['message'] ) ? wp_strip_all_tags( (string) $res['message'] ) : __( 'JetThemeCore could not create the template.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$post = get_post( (int) $res['newTemplateId'] );
				return rest_ensure_response( array( 'ok' => true, 'id' => (int) $res['newTemplateId'], 'item' => $post ? minn_admin_jet_tc_item( $post ) : null ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-theme-core/templates/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $find ) {
				$p = $find( Minn_Admin::path_param( $request ) );
				if ( ! $p || ! current_user_can( 'edit_post', $p->ID ) ) {
					return new WP_Error( 'not_found', __( 'Template not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_jet_tc_item( $p ) );
			},
		),
		array(
			'methods'             => 'PUT',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $find ) {
				$p = $find( Minn_Admin::path_param( $request ) );
				if ( ! $p || ! current_user_can( 'edit_post', $p->ID ) ) {
					return new WP_Error( 'not_found', __( 'Template not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
				if ( '' === $title ) {
					return new WP_Error( 'missing_title', __( 'A template needs a title.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$r = wp_update_post( array( 'ID' => $p->ID, 'post_title' => $title ), true );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return rest_ensure_response( minn_admin_jet_tc_item( get_post( $p->ID ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $find ) {
				$p = $find( Minn_Admin::path_param( $request ) );
				if ( ! $p || ! current_user_can( 'delete_post', $p->ID ) ) {
					return new WP_Error( 'not_found', __( 'Template not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! wp_trash_post( $p->ID ) ) {
					return new WP_Error( 'trash_failed', __( 'Could not trash that template.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Template moved to the trash.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-theme-core/templates/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$counts = wp_count_posts( 'jet-theme-core' );
			$n      = 0;
			foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $st ) {
				$n += isset( $counts->$st ) ? (int) $counts->$st : 0;
			}
			$rows = array( array( 'label' => __( 'Templates', 'minn-admin' ), 'value' => number_format_i18n( $n ) ) );
			foreach ( minn_admin_jet_tc_types() as $id => $label ) {
				$q = new WP_Query( array( 'post_type' => 'jet-theme-core', 'post_status' => array( 'publish', 'draft', 'pending', 'future', 'private' ), 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_jet_template_type', 'value' => $id ) ) ) );
				if ( $q->found_posts ) {
					$rows[] = array( 'label' => $label, 'value' => number_format_i18n( (int) $q->found_posts ) );
				}
			}
			return rest_ensure_response( array(
				'rows'    => array_slice( $rows, 0, 4 ),
				'actions' => array( array( 'label' => __( 'Open JetThemeCore ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=jet-theme-builder' ) ) ),
			) );
		},
	) );
} );
