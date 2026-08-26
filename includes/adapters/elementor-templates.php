<?php
/**
 * Bundled adapter: Elementor templates (free Saved Templates + Pro Theme Builder).
 *
 * Elementor stores both saved templates and Theme Builder parts as
 * `elementor_library` posts. The kind lives in `_elementor_template_type`;
 * display conditions (Pro) live as slash-joined strings in
 * `_elementor_conditions`. Minn hides this CPT from Content on purpose
 * (plumbing, not a writing surface), so this adapter is the listing.
 *
 * Create goes through Elementor's own Documents_Manager::create(), which
 * seeds `_elementor_edit_mode` and the type meta the way their
 * `elementor_new_post` admin action does. The edit URL is their canvas
 * (`post.php?action=elementor`). Caps go through Elementor\User (the role
 * blacklist in elementor_exclude_user_roles plus the CPT's edit_posts),
 * never a blanket manage_options.
 *
 * Deliberately not built: the canvas, condition / popup-trigger authoring,
 * changing a template's type after create (a header's widgets are not a
 * footer), the cloud / remote library, import (ajax + $_FILES), landing
 * pages and floating buttons (separate CPTs), and Site Settings (a canvas
 * inside the editor, not a PHP form).
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_elementor_templates_ready() {
	return defined( 'ELEMENTOR_VERSION' )
		&& class_exists( '\Elementor\Plugin' )
		&& class_exists( '\Elementor\TemplateLibrary\Source_Local' );
}

/** Whether the current user may see and manage the library, through Elementor's own gate. */
function minn_admin_elementor_can_manage() {
	if ( ! minn_admin_elementor_templates_ready() ) {
		return false;
	}
	if ( class_exists( '\Elementor\User' ) && method_exists( '\Elementor\User', 'is_current_user_can_edit_post_type' ) ) {
		return (bool) \Elementor\User::is_current_user_can_edit_post_type( \Elementor\TemplateLibrary\Source_Local::CPT );
	}
	return current_user_can( 'edit_posts' );
}

/** Whether the current user may create (and therefore duplicate) a library item. */
function minn_admin_elementor_can_create() {
	if ( ! minn_admin_elementor_can_manage() ) {
		return false;
	}
	$pto = get_post_type_object( \Elementor\TemplateLibrary\Source_Local::CPT );
	if ( ! $pto || empty( $pto->cap->create_posts ) ) {
		return current_user_can( 'edit_posts' );
	}
	return current_user_can( $pto->cap->create_posts );
}

/** Whether the current user may open this template in the Elementor canvas. */
function minn_admin_elementor_can_edit_item( $post_id ) {
	if ( class_exists( '\Elementor\User' ) && method_exists( '\Elementor\User', 'is_current_user_can_edit' ) ) {
		return (bool) \Elementor\User::is_current_user_can_edit( (int) $post_id );
	}
	return current_user_can( 'edit_post', (int) $post_id );
}

/**
 * Live type vocabulary (raw meta value => label) from Elementor's document
 * registry, filtered to templates that live on the library CPT. Theme
 * Builder types (header, footer, …) only appear while Pro is loaded.
 *
 * @return array
 */
function minn_admin_elementor_template_types() {
	if ( ! minn_admin_elementor_templates_ready() ) {
		return array();
	}
	$preferred = array(
		'header', 'footer', 'single', 'single-post', 'single-page',
		'archive', 'search-results', 'error-404', 'product', 'product-archive',
		'popup', 'loop-item', 'page', 'section', 'container', 'widget',
	);
	$skip = array( 'kit', 'not-supported', 'cloud-template-preview' );
	$cpt  = \Elementor\TemplateLibrary\Source_Local::CPT;
	$out  = array();
	try {
		$all = \Elementor\Plugin::$instance->documents->get_document_types();
	} catch ( \Throwable $e ) {
		return array();
	}
	if ( ! is_array( $all ) ) {
		return array();
	}
	foreach ( $all as $type => $class ) {
		$type = (string) $type;
		if ( in_array( $type, $skip, true ) || ! is_string( $class ) || ! class_exists( $class ) ) {
			continue;
		}
		if ( ! is_callable( array( $class, 'get_property' ) ) ) {
			continue;
		}
		$cpts = (array) $class::get_property( 'cpt' );
		if ( ! in_array( $cpt, $cpts, true ) ) {
			continue;
		}
		if ( false === $class::get_property( 'show_in_library' ) ) {
			continue;
		}
		// Elementor's own screens key off admin_tab_group: Theme Builder
		// ('theme'), Popups ('popup'), Saved Templates ('library'). The
		// library group also contains atomic components (e-div-block, …)
		// and third-party document types; keep only the four template
		// kinds their Saved Templates screen actually lists.
		$group = (string) $class::get_property( 'admin_tab_group' );
		if ( 'theme' === $group || 'popup' === $group ) {
			// Theme Builder + Popups: include.
		} elseif ( 'library' === $group && in_array( $type, array( 'page', 'section', 'container', 'widget' ), true ) ) {
			// Saved Templates: the four kinds their screen lists.
		} else {
			continue;
		}
		$label       = is_callable( array( $class, 'get_title' ) ) ? (string) $class::get_title() : $type;
		$out[ $type ] = $label ? $label : $type;
	}
	$ordered = array();
	foreach ( $preferred as $type ) {
		if ( isset( $out[ $type ] ) ) {
			$ordered[ $type ] = $out[ $type ];
			unset( $out[ $type ] );
		}
	}
	return $ordered + $out;
}

/**
 * Pro's own "Instances" column: include-conditions only, already labelled
 * ("Entire Site", "Singular #12"). Library types with no conditions return ''.
 *
 * @param int $post_id Template post ID.
 * @return string
 */
function minn_admin_elementor_conditions_summary( $post_id ) {
	if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
		return '';
	}
	try {
		$module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
		if ( ! $module || ! method_exists( $module, 'get_conditions_manager' ) ) {
			return '';
		}
		$instances = $module->get_conditions_manager()->get_document_instances( (int) $post_id );
	} catch ( \Throwable $e ) {
		return '';
	}
	if ( empty( $instances ) || ! is_array( $instances ) ) {
		return '';
	}
	return implode( ' · ', array_map( 'wp_strip_all_tags', array_values( $instances ) ) );
}

/**
 * Canvas URL through the document object, falling back to the well-known
 * post.php?action=elementor form the page-builders adapter already uses.
 *
 * @param int $post_id Template post ID.
 * @return string
 */
function minn_admin_elementor_template_edit_url( $post_id ) {
	try {
		$document = \Elementor\Plugin::$instance->documents->get( (int) $post_id );
		if ( $document && method_exists( $document, 'get_edit_url' ) ) {
			$url = $document->get_edit_url();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
	} catch ( \Throwable $e ) { /* fall through */ }
	return admin_url( 'post.php?post=' . (int) $post_id . '&action=elementor' );
}

/** Normalize a library post into a surface item. */
function minn_admin_elementor_template_item( $post ) {
	$types = minn_admin_elementor_template_types();
	$type  = (string) \Elementor\TemplateLibrary\Source_Local::get_template_type( $post->ID );
	return array(
		'id'         => (int) $post->ID,
		'title'      => '' !== $post->post_title ? html_entity_decode( $post->post_title, ENT_QUOTES ) : __( '(no title)', 'minn-admin' ),
		'type'       => $type,
		'typeLabel'  => isset( $types[ $type ] ) ? $types[ $type ] : ( '' !== $type ? $type : '—' ),
		'conditions' => minn_admin_elementor_conditions_summary( $post->ID ),
		'status'     => (string) $post->post_status,
		'modified'   => (string) $post->post_modified_gmt,
		'editUrl'    => minn_admin_elementor_template_edit_url( $post->ID ),
	);
}

/**
 * Refuse anything that is not a library template of a type we list.
 *
 * @param int $id Post ID.
 * @return WP_Post|WP_Error
 */
function minn_admin_elementor_template_post( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || \Elementor\TemplateLibrary\Source_Local::CPT !== $post->post_type ) {
		return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$type = (string) \Elementor\TemplateLibrary\Source_Local::get_template_type( $post->ID );
	if ( ! isset( minn_admin_elementor_template_types()[ $type ] ) ) {
		return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return $post;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_elementor_templates_ready() || ! minn_admin_elementor_can_manage() ) {
		return $surfaces;
	}
	$types = minn_admin_elementor_template_types();
	if ( ! $types ) {
		return $surfaces;
	}
	$type_tabs   = array();
	$type_select = array();
	foreach ( $types as $value => $label ) {
		$type_tabs[]   = array( $value, $label );
		$type_select[] = array( $value, $label );
	}

	$actions = array();
	$actions[] = array(
		'label' => __( 'Edit in Elementor', 'minn-admin' ),
		'href'  => '{editUrl}',
	);
	if ( minn_admin_elementor_can_create() ) {
		$actions[] = array(
			'label' => __( 'Duplicate', 'minn-admin' ),
			'route' => 'minn-admin/v1/elementor/templates/{id}/duplicate',
		);
	}
	$actions[] = array(
		'label'    => __( 'Export template', 'minn-admin' ),
		'route'    => 'minn-admin/v1/elementor/templates/{id}/export',
		'download' => true,
	);
	$actions[] = array(
		'label'   => __( 'Move to trash', 'minn-admin' ),
		'method'  => 'DELETE',
		'route'   => 'minn-admin/v1/elementor/templates/{id}',
		'confirm' => __( 'Move this template to the trash? Anywhere it is assigned stops using it.', 'minn-admin' ),
		'danger'  => true,
	);

	$surfaces['elementor-templates'] = array(
		'label'      => __( 'Templates', 'minn-admin' ),
		'sub'        => 'Elementor',
		'family'     => 'builder-templates',
		'icon'       => 'columns',
		'cap'        => 'edit_posts',
		'collection' => array(
			'route'     => 'minn-admin/v1/elementor/templates',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'type',
				'static'   => $type_tabs,
				'allLabel' => __( 'All', 'minn-admin' ),
			),
			'search'    => 'search={q}',
			'sortQuery' => 'orderby={by}&order={dir}',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Template', 'minn-admin' ), 'format' => 'title', 'sort' => 'title' ),
				array( 'key' => 'typeLabel', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'pill', 'width' => '130px' ),
				array( 'key' => 'conditions', 'label' => __( 'Conditions', 'minn-admin' ), 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago', 'utc' => true, 'sort' => 'modified' ),
			),
			'create'    => minn_admin_elementor_can_create() ? array(
				'label'  => __( 'Add template', 'minn-admin' ),
				'route'  => 'minn-admin/v1/elementor/templates',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
					array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_select ),
				),
			) : null,
			'detail'    => array(
				'skip' => array( 'id', 'type', 'typeLabel', 'editUrl' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/elementor/templates/{id}',
					'method' => 'PUT',
					'fields' => array(
						array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
					),
				),
			),
			'actions'   => $actions,
			'bulk'      => array(
				array(
					'label'   => __( 'Move to trash', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/elementor/templates/{id}',
					'confirm' => __( 'Move the selected templates to the trash?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	if ( null === $surfaces['elementor-templates']['collection']['create'] ) {
		unset( $surfaces['elementor-templates']['collection']['create'] );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_elementor_templates_ready() ) {
		return;
	}
	$perm = function () {
		return minn_admin_elementor_can_manage();
	};

	register_rest_route( 'minn-admin/v1', '/elementor/templates', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$types   = minn_admin_elementor_template_types();
				$type    = sanitize_key( (string) $request['type'] );
				$search  = trim( (string) $request['search'] );
				$page    = max( 1, (int) ( $request['page'] ?: 1 ) );
				$orderby = in_array( $request['orderby'], array( 'title', 'modified' ), true ) ? $request['orderby'] : 'modified';
				$order   = 'asc' === strtolower( (string) $request['order'] ) ? 'ASC' : 'DESC';
				$allowed = array_keys( $types );
				if ( '' !== $type ) {
					if ( ! isset( $types[ $type ] ) ) {
						return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
					}
					$allowed = array( $type );
				}
				$args = array(
					'post_type'      => \Elementor\TemplateLibrary\Source_Local::CPT,
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page' => 25,
					'paged'          => $page,
					'orderby'        => $orderby,
					'order'          => $order,
					'meta_query'     => array(
						array(
							'key'     => \Elementor\Core\Base\Document::TYPE_META_KEY,
							'value'   => $allowed,
							'compare' => 'IN',
						),
					),
				);
				if ( '' !== $search ) {
					$args['s'] = $search;
				}
				$query = new WP_Query( $args );
				return rest_ensure_response( array(
					'items' => array_map( 'minn_admin_elementor_template_item', $query->posts ),
					'total' => (int) $query->found_posts,
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return minn_admin_elementor_can_create();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$title = trim( (string) $request['title'] );
				$type  = sanitize_key( (string) $request['type'] );
				if ( '' === $title ) {
					return new WP_Error( 'invalid', __( 'A template needs a title.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( ! isset( minn_admin_elementor_template_types()[ $type ] ) ) {
					return new WP_Error( 'invalid', __( 'Pick a template type.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				try {
					$document = \Elementor\Plugin::$instance->documents->create(
						$type,
						array(
							'post_title'  => $title,
							'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
							'post_type'   => \Elementor\TemplateLibrary\Source_Local::CPT,
						)
					);
				} catch ( \Throwable $e ) {
					return new WP_Error( 'failed', __( 'Elementor could not create this template.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				if ( is_wp_error( $document ) ) {
					return $document;
				}
				$id = (int) $document->get_main_id();
				if ( $id < 1 ) {
					return new WP_Error( 'failed', __( 'Elementor could not create this template.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( minn_admin_elementor_template_item( get_post( $id ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/templates/(?P<id>\d+)', array(
		array(
			'methods'             => 'PUT',
			'permission_callback' => function ( WP_REST_Request $request ) {
				return minn_admin_elementor_can_edit_item( (int) $request['id'] );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = minn_admin_elementor_template_post( (int) $request['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				$title = trim( (string) $request['title'] );
				if ( '' === $title ) {
					return new WP_Error( 'invalid', __( 'A template needs a title.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$updated = wp_update_post( array(
					'ID'         => $post->ID,
					'post_title' => $title,
				), true );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				return rest_ensure_response( minn_admin_elementor_template_item( get_post( $post->ID ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function ( WP_REST_Request $request ) {
				return current_user_can( 'delete_post', (int) $request['id'] ) && minn_admin_elementor_can_manage();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = minn_admin_elementor_template_post( (int) $request['id'] );
				if ( is_wp_error( $post ) ) {
					return $post;
				}
				if ( ! wp_trash_post( $post->ID ) ) {
					return new WP_Error( 'failed', __( 'The template could not be trashed.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'trashed' => (int) $post->ID ) );
			},
		),
	) );

	// Document::get_export_data() is public; Source_Local::export_template()
	// sends headers and dies, and prepare_template_export() is private. The
	// JSON shape matches their interchange file so it imports through their
	// own importer.
	register_rest_route( 'minn-admin/v1', '/elementor/templates/(?P<id>\d+)/export', array(
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $request ) {
			return minn_admin_elementor_can_edit_item( (int) $request['id'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = minn_admin_elementor_template_post( (int) $request['id'] );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			try {
				$document = \Elementor\Plugin::$instance->documents->get( $post->ID );
				if ( ! $document || ! method_exists( $document, 'get_export_data' ) ) {
					return new WP_Error( 'failed', __( 'Elementor could not export this template.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$data = $document->get_export_data();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'failed', __( 'Elementor could not export this template.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			if ( empty( $data['content'] ) ) {
				return new WP_Error( 'empty_template', __( 'The template is empty.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$content = apply_filters( 'elementor/template_library/sources/local/export/elements', $data['content'] );
			$export  = array(
				'content'       => $content,
				'page_settings' => isset( $data['settings'] ) ? $data['settings'] : array(),
				'version'       => class_exists( '\Elementor\DB' ) ? \Elementor\DB::DB_VERSION : '',
				'title'         => $document->get_main_post()->post_title,
				'type'          => \Elementor\TemplateLibrary\Source_Local::get_template_type( $post->ID ),
			);
			return rest_ensure_response( array(
				'filename' => 'elementor-' . $post->ID . '-' . gmdate( 'Y-m-d' ) . '.json',
				'content'  => wp_json_encode( $export ),
				'mime'     => 'application/json',
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/templates/(?P<id>\d+)/duplicate', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			return minn_admin_elementor_can_create() && minn_admin_elementor_can_edit_item( (int) $request['id'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = minn_admin_elementor_template_post( (int) $request['id'] );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$type = (string) \Elementor\TemplateLibrary\Source_Local::get_template_type( $post->ID );
			/* translators: %s: the source template's title. */
			$copy_title = sprintf( __( '%s (copy)', 'minn-admin' ), $post->post_title );
			try {
				$document = \Elementor\Plugin::$instance->documents->create(
					$type,
					array(
						'post_title'  => $copy_title,
						'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
						'post_type'   => \Elementor\TemplateLibrary\Source_Local::CPT,
					)
				);
			} catch ( \Throwable $e ) {
				return new WP_Error( 'failed', __( 'Elementor could not duplicate this template.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			if ( is_wp_error( $document ) ) {
				return $document;
			}
			$new_id = (int) $document->get_main_id();
			if ( $new_id < 1 ) {
				return new WP_Error( 'failed', __( 'Elementor could not duplicate this template.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			// Content-bearing meta only. Conditions stay off the copy: a
			// second header with "Entire Site" would conflict with the
			// original. CSS meta regenerates on save().
			foreach ( array( '_elementor_data', '_elementor_page_settings', '_elementor_popup_display_settings' ) as $key ) {
				delete_post_meta( $new_id, $key );
				$values = get_post_meta( $post->ID, $key, false );
				foreach ( $values as $value ) {
					add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}
			try {
				$document->save( array() );
			} catch ( \Throwable $e ) { /* content is already on the post */ }
			return rest_ensure_response( minn_admin_elementor_template_item( get_post( $new_id ) ) );
		},
	) );
} );
