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
 * pages (a deprecated experiment on its own CPT), and Site Settings (a
 * canvas inside the editor, not a PHP form). Floating Buttons live on
 * `e-floating-buttons` and join this surface as extra type tabs.
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_elementor_templates_ready() {
	return defined( 'ELEMENTOR_VERSION' )
		&& class_exists( '\Elementor\Plugin' )
		&& class_exists( '\Elementor\TemplateLibrary\Source_Local' );
}

/** Floating Buttons module is loaded (its own CPT, not the library). */
function minn_admin_elementor_floating_ready() {
	return minn_admin_elementor_templates_ready()
		&& class_exists( '\Elementor\Modules\FloatingButtons\Module' );
}

/** The Floating Buttons CPT slug, or '' when the module is absent. */
function minn_admin_elementor_floating_cpt() {
	return minn_admin_elementor_floating_ready()
		? \Elementor\Modules\FloatingButtons\Module::CPT_FLOATING_BUTTONS
		: '';
}

/** Whether $type is a Floating Buttons / Bars variant. */
function minn_admin_elementor_is_floating_type( $type ) {
	return in_array( (string) $type, array( 'floating-buttons', 'floating-bars' ), true );
}

/** Whether the current user may see and manage the library, through Elementor's own gate. */
function minn_admin_elementor_can_manage() {
	if ( ! minn_admin_elementor_templates_ready() ) {
		return false;
	}
	if ( class_exists( '\Elementor\User' ) && method_exists( '\Elementor\User', 'is_current_user_can_edit_post_type' ) ) {
		if ( \Elementor\User::is_current_user_can_edit_post_type( \Elementor\TemplateLibrary\Source_Local::CPT ) ) {
			return true;
		}
		$float = minn_admin_elementor_floating_cpt();
		if ( $float && \Elementor\User::is_current_user_can_edit_post_type( $float ) ) {
			return true;
		}
		return false;
	}
	return current_user_can( 'edit_posts' );
}

/**
 * Whether the current user may create (and therefore duplicate) a library item.
 *
 * The type decides which post type is written, and the two do not answer to
 * the same capability. Floating Buttons are their own content type, which
 * Elementor registers with every capability set to managing site options, so
 * deriving the answer from the ordinary template library would hand someone
 * who may write a post the ability to write into an administrators-only type.
 * The capability has to come from the type about to be created.
 *
 * @param string $type Template type slug. '' means the template library.
 * @return bool
 */
function minn_admin_elementor_can_create( $type = '' ) {
	if ( ! minn_admin_elementor_can_manage() ) {
		return false;
	}
	$cpt = \Elementor\TemplateLibrary\Source_Local::CPT;
	if ( minn_admin_elementor_is_floating_type( $type ) ) {
		$cpt = minn_admin_elementor_floating_cpt();
		// The module is switched off: there is no type to create into.
		if ( ! $cpt ) {
			return false;
		}
	}
	$pto = get_post_type_object( $cpt );
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
		'floating-buttons', 'floating-bars',
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
	if ( minn_admin_elementor_floating_ready() ) {
		$float_types = \Elementor\Modules\FloatingButtons\Module::get_floating_elements_types();
		if ( is_array( $float_types ) ) {
			foreach ( $float_types as $slug => $label ) {
				$out[ (string) $slug ] = (string) $label;
			}
		}
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

/** Normalize a library or floating-buttons post into a surface item. */
function minn_admin_elementor_template_item( $post ) {
	$types = minn_admin_elementor_template_types();
	$float = minn_admin_elementor_floating_cpt();
	if ( $float && $float === $post->post_type ) {
		$type = (string) get_post_meta( $post->ID, \Elementor\Modules\FloatingButtons\Module::FLOATING_ELEMENTS_TYPE_META_KEY, true );
		if ( '' === $type ) {
			$type = 'floating-buttons';
		}
	} else {
		$type = (string) \Elementor\TemplateLibrary\Source_Local::get_template_type( $post->ID );
	}
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
	$post  = get_post( (int) $id );
	$float = minn_admin_elementor_floating_cpt();
	$ok    = $post && (
		\Elementor\TemplateLibrary\Source_Local::CPT === $post->post_type
		|| ( $float && $float === $post->post_type )
	);
	if ( ! $ok ) {
		return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$item = minn_admin_elementor_template_item( $post );
	if ( ! isset( minn_admin_elementor_template_types()[ $item['type'] ] ) ) {
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
		// Filtering the list is a browsing concern; creating is a capability
		// concern. Someone may look through Floating Buttons without being
		// offered a new one.
		$type_tabs[] = array( $value, $label );
		if ( minn_admin_elementor_can_create( $value ) ) {
			$type_select[] = array( $value, $label );
		}
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
		'status'     => array( 'route' => 'minn-admin/v1/elementor/templates/status' ),
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

	register_rest_route( 'minn-admin/v1', '/elementor/templates/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$lib = wp_count_posts( \Elementor\TemplateLibrary\Source_Local::CPT );
			$n   = 0;
			foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $st ) {
				$n += isset( $lib->$st ) ? (int) $lib->$st : 0;
			}
			$rows = array(
				array( 'label' => __( 'Templates', 'minn-admin' ), 'value' => number_format_i18n( $n ) ),
			);
			$float = minn_admin_elementor_floating_cpt();
			if ( $float ) {
				$fc = wp_count_posts( $float );
				$fn = 0;
				foreach ( array( 'publish', 'draft', 'pending', 'future', 'private' ) as $st ) {
					$fn += isset( $fc->$st ) ? (int) $fc->$st : 0;
				}
				$rows[] = array( 'label' => __( 'Floating buttons', 'minn-admin' ), 'value' => number_format_i18n( $fn ) );
			}
			$actions = array(
				array(
					'label' => __( 'Open Elementor ↗', 'minn-admin' ),
					'href'  => admin_url( 'edit.php?post_type=' . \Elementor\TemplateLibrary\Source_Local::CPT ),
				),
			);
			if ( defined( 'EAEL_PLUGIN_VERSION' ) && current_user_can( 'manage_options' ) ) {
				$actions[] = array(
					'label' => __( 'Open Essential Addons ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=eael-settings' ),
				);
			}
			return rest_ensure_response( array(
				'rows'    => $rows,
				'actions' => $actions,
			) );
		},
	) );

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
				$float   = minn_admin_elementor_floating_cpt();
				if ( '' !== $type && ! isset( $types[ $type ] ) ) {
					return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
				}
				$is_float = minn_admin_elementor_is_floating_type( $type );
				if ( $is_float && ! $float ) {
					return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
				}
				$allowed = $is_float
					? array( $type )
					: array_values( array_filter( array_keys( $types ), function ( $t ) {
						return ! minn_admin_elementor_is_floating_type( $t );
					} ) );
				if ( '' !== $type && ! $is_float ) {
					$allowed = array( $type );
				}
				$args = array(
					'post_type'      => $is_float ? $float : \Elementor\TemplateLibrary\Source_Local::CPT,
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					// Naming the unpublished statuses explicitly switches off
					// the scoping WP_Query would otherwise apply, so without
					// this everyone who can write a post sees every author's
					// unfinished templates. Every action on a row needs
					// permission for that row anyway, so a row you cannot
					// edit is a row you cannot use.
					'perm'           => 'editable',
					'posts_per_page' => 25,
					'paged'          => $page,
					'orderby'        => $orderby,
					'order'          => $order,
					'meta_query'     => array(
						array(
							'key'     => $is_float
								? \Elementor\Modules\FloatingButtons\Module::FLOATING_ELEMENTS_TYPE_META_KEY
								: \Elementor\Core\Base\Document::TYPE_META_KEY,
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
			'permission_callback' => function ( WP_REST_Request $request ) {
				// Gate on the type being asked for, not on the library: the
				// two differ by an administrator capability for Floating
				// Buttons.
				return minn_admin_elementor_can_create( sanitize_key( (string) $request['type'] ) );
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
				$is_float  = minn_admin_elementor_is_floating_type( $type );
				$float_cpt = minn_admin_elementor_floating_cpt();
				if ( $is_float && ! $float_cpt ) {
					return new WP_Error( 'invalid', __( 'Floating Buttons are not available on this site.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$doc_type  = $is_float ? \Elementor\Modules\FloatingButtons\Module::FLOATING_BUTTONS_DOCUMENT_TYPE : $type;
				$post_type = $is_float ? $float_cpt : \Elementor\TemplateLibrary\Source_Local::CPT;
				try {
					$document = \Elementor\Plugin::$instance->documents->create(
						$doc_type,
						array(
							'post_title'  => $title,
							'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
							'post_type'   => $post_type,
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
				if ( $is_float ) {
					update_post_meta( $id, \Elementor\Modules\FloatingButtons\Module::FLOATING_ELEMENTS_TYPE_META_KEY, $type );
				}
				return rest_ensure_response( minn_admin_elementor_template_item( get_post( $id ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/elementor/templates/(?P<id>\d+)', array(
		array(
			'methods'             => 'PUT',
			'permission_callback' => function ( WP_REST_Request $request ) {
				return minn_admin_elementor_can_edit_item( (int) Minn_Admin::path_param( $request ) );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = minn_admin_elementor_template_post( (int) Minn_Admin::path_param( $request ) );
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
				return current_user_can( 'delete_post', (int) Minn_Admin::path_param( $request ) ) && minn_admin_elementor_can_manage();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = minn_admin_elementor_template_post( (int) Minn_Admin::path_param( $request ) );
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
			return minn_admin_elementor_can_edit_item( (int) Minn_Admin::path_param( $request ) );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = minn_admin_elementor_template_post( (int) Minn_Admin::path_param( $request ) );
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
			if ( ! minn_admin_elementor_can_edit_item( (int) Minn_Admin::path_param( $request ) ) ) {
				return false;
			}
			// A copy is written into the same type as its source, so the
			// capability is the source's too.
			$src   = get_post( (int) Minn_Admin::path_param( $request ) );
			$float = minn_admin_elementor_floating_cpt();
			$type  = ( $src && $float && $float === $src->post_type ) ? 'floating-buttons' : '';
			return minn_admin_elementor_can_create( $type );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = minn_admin_elementor_template_post( (int) Minn_Admin::path_param( $request ) );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$item      = minn_admin_elementor_template_item( $post );
			$type      = $item['type'];
			$is_float  = minn_admin_elementor_is_floating_type( $type );
			$float_cpt = minn_admin_elementor_floating_cpt();
			$doc_type  = $is_float ? \Elementor\Modules\FloatingButtons\Module::FLOATING_BUTTONS_DOCUMENT_TYPE : $type;
			$post_type = $is_float ? $float_cpt : \Elementor\TemplateLibrary\Source_Local::CPT;
			/* translators: %s: the source template's title. */
			$copy_title = sprintf( __( '%s (copy)', 'minn-admin' ), $post->post_title );
			try {
				$document = \Elementor\Plugin::$instance->documents->create(
					$doc_type,
					array(
						'post_title'  => $copy_title,
						'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
						'post_type'   => $post_type,
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
			$copy_keys = array( '_elementor_data', '_elementor_page_settings', '_elementor_popup_display_settings' );
			if ( $is_float ) {
				$copy_keys[] = \Elementor\Modules\FloatingButtons\Module::FLOATING_ELEMENTS_TYPE_META_KEY;
			}
			foreach ( $copy_keys as $key ) {
				delete_post_meta( $new_id, $key );
				$values = get_post_meta( $post->ID, $key, false );
				foreach ( $values as $value ) {
					// Elementor runs its own kses over an element tree for
					// anyone without unfiltered_html (core/base/document.php
					// does this on save), and copying the meta straight across
					// went around that boundary: an editor duplicating a
					// template an administrator had authored would carry the
					// administrator's unfiltered markup onto a post of their
					// own. Ask the vendor's own filter, not a local guess.
					// Their save() runs kses over the whole decoded save payload
					// (elements AND settings), so the element tree is decoded
					// first: kses over the encoded JSON string re-quotes
					// attributes inside it and corrupts the copy.
					if ( ! current_user_can( 'unfiltered_html' )
						&& class_exists( '\Elementor\Utils' )
						&& method_exists( '\Elementor\Utils', 'kses_post_deep' ) ) {
						try {
							if ( '_elementor_data' === $key && is_string( $value ) ) {
								$tree = json_decode( $value, true );
								if ( is_array( $tree ) ) {
									$value = wp_json_encode( \Elementor\Utils::kses_post_deep( $tree ) );
								} else {
									$value = wp_kses_post( $value );
								}
							} else {
								$value = \Elementor\Utils::kses_post_deep( $value );
							}
						} catch ( \Throwable $e ) { /* fall through with the stored value */ }
					}
					// get_post_meta() has already turned these back into real
					// values. Doing it a second time would take a value that
					// merely looks like stored data and rebuild it into an
					// object, which is a doorway nobody needs open.
					add_post_meta( $new_id, $key, wp_slash( $value ) );
				}
			}
			try {
				$document->save( array() );
			} catch ( \Throwable $e ) { /* content is already on the post */ }
			return rest_ensure_response( minn_admin_elementor_template_item( get_post( $new_id ) ) );
		},
	) );
} );
