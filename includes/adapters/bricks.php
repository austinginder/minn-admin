<?php
/**
 * Bundled adapter: Bricks templates (theme, bricks.io).
 *
 * Bricks stores templates as `bricks_template` posts (capability_type
 * 'post', deliberately not REST-exposed upstream, so Minn's content
 * switcher never sees them). The template kind lives in postmeta
 * `_bricks_template_type` (header / footer / content ["Single"] / section /
 * popup / archive / search / error, plus password_protection while that
 * feature is enabled); display conditions live as a repeater under
 * `_bricks_template_settings['templateConditions']`; tags/bundles are the
 * `template_tag` / `template_bundle` taxonomies. The builder edit URL is
 * the template's permalink with `?bricks=run` (pure front-end app, same as
 * the page-builders descriptor).
 *
 * Capability model: list/edit/trash ride the CPT's standard post caps, but
 * template CREATION (and duplication, which is creation) goes through
 * Bricks' own permission model — Builder_Permissions::user_has_permission(
 * 'create_templates' ), resolved against their bricks_full_access /
 * bricks_edit_content role caps and any custom capability sets. Never check
 * those raw caps directly. The Edit-in-Bricks link only renders for users
 * their Capabilities class lets into the builder.
 *
 * Deliberately not built: template canvas editing (the builder is one click
 * away), condition editing (their repeater has live ajax-fed term/post
 * pickers; conditions render read-only here), and the remote template
 * library (element trees, not blocks — nothing Minn's editor could insert).
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_bricks_active() {
	return defined( 'BRICKS_VERSION' ) && class_exists( '\Bricks\Templates' );
}

/**
 * The template-type vocabulary (raw meta value => label). Mirrors Bricks'
 * own control options; password_protection joins only while their setting
 * enables the feature, exactly like their type picker.
 *
 * @return array
 */
function minn_admin_bricks_template_types() {
	$types = array(
		'header'  => __( 'Header', 'minn-admin' ),
		'footer'  => __( 'Footer', 'minn-admin' ),
		'content' => __( 'Single', 'minn-admin' ),
		'section' => __( 'Section', 'minn-admin' ),
		'popup'   => __( 'Popup', 'minn-admin' ),
		'archive' => __( 'Archive', 'minn-admin' ),
		'search'  => __( 'Search results', 'minn-admin' ),
		'error'   => __( 'Error page', 'minn-admin' ),
	);
	if ( class_exists( '\Bricks\Database' ) && \Bricks\Database::get_setting( 'passwordProtectionEnabled', false ) ) {
		$types['password_protection'] = __( 'Password protection', 'minn-admin' );
	}
	return $types;
}

/** Whether the current user may create templates, through Bricks' own resolver. */
function minn_admin_bricks_can_create() {
	if ( class_exists( '\Bricks\Builder_Permissions' ) && method_exists( '\Bricks\Builder_Permissions', 'user_has_permission' ) ) {
		try {
			return (bool) \Bricks\Builder_Permissions::user_has_permission( 'create_templates' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
	return current_user_can( 'edit_posts' );
}

/**
 * Human-readable summary of a template's display conditions.
 *
 * Reads the templateConditions repeater: each row's `main` picks the shape
 * (any / frontpage / postType / archiveType / search / error / terms / ids /
 * hook) with sub-fields per shape. `exclude` inverts a row. Term values are
 * stored as "{taxonomy}::{term_id}"; ids are post IDs.
 *
 * @param int $post_id Template post ID.
 * @return string Semicolon-joined summaries, '' when unassigned.
 */
function minn_admin_bricks_conditions_summary( $post_id ) {
	$settings   = get_post_meta( $post_id, BRICKS_DB_TEMPLATE_SETTINGS, true );
	$conditions = is_array( $settings ) && isset( $settings['templateConditions'] ) && is_array( $settings['templateConditions'] )
		? $settings['templateConditions']
		: array();
	$parts      = array();

	$post_type_names = function ( $slugs ) {
		$names = array();
		foreach ( (array) $slugs as $slug ) {
			$obj     = get_post_type_object( (string) $slug );
			$names[] = $obj ? $obj->labels->name : (string) $slug;
		}
		return implode( ', ', $names );
	};
	$term_names = function ( $values ) {
		$names = array();
		foreach ( (array) $values as $value ) {
			$value = (string) $value;
			if ( 'all' === $value ) {
				$names[] = __( 'All terms', 'minn-admin' );
				continue;
			}
			$id   = (int) substr( $value, strrpos( $value, ':' ) + 1 );
			$term = $id ? get_term( $id ) : null;
			$names[] = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
		}
		return implode( ', ', $names );
	};

	foreach ( $conditions as $c ) {
		if ( ! is_array( $c ) || empty( $c['main'] ) ) {
			continue;
		}
		$text = '';
		switch ( (string) $c['main'] ) {
			case 'any':
				$text = __( 'Entire website', 'minn-admin' );
				break;
			case 'frontpage':
				$text = __( 'Front page', 'minn-admin' );
				break;
			case 'postType':
				$text = __( 'Post type', 'minn-admin' ) . ': ' . $post_type_names( $c['postType'] ?? array() );
				break;
			case 'archiveType':
				$kinds = array();
				foreach ( (array) ( $c['archiveType'] ?? array() ) as $a ) {
					switch ( (string) $a ) {
						case 'any':
							$kinds[] = __( 'all archives', 'minn-admin' );
							break;
						case 'postType':
							$kinds[] = $post_type_names( $c['archivePostTypes'] ?? array() );
							break;
						case 'author':
							$kinds[] = __( 'author', 'minn-admin' );
							break;
						case 'date':
							$kinds[] = __( 'date', 'minn-admin' );
							break;
						case 'term':
							$kinds[] = $term_names( $c['archiveTerms'] ?? array() );
							break;
					}
				}
				$text = __( 'Archive', 'minn-admin' ) . ( $kinds ? ': ' . implode( ', ', array_filter( $kinds ) ) : '' );
				break;
			case 'search':
				$text = __( 'Search results', 'minn-admin' );
				break;
			case 'error':
				$text = __( 'Error page', 'minn-admin' );
				break;
			case 'terms':
				$text = __( 'Terms', 'minn-admin' ) . ': ' . $term_names( $c['terms'] ?? array() );
				break;
			case 'ids':
				$titles = array();
				foreach ( (array) ( $c['ids'] ?? array() ) as $id ) {
					$title    = get_the_title( (int) $id );
					$titles[] = '' !== $title ? $title : ( '#' . (int) $id );
				}
				$text = implode( ', ', $titles );
				break;
			case 'hook':
				$text = __( 'Hook', 'minn-admin' ) . ': ' . (string) ( $c['hookName'] ?? '' );
				break;
			default:
				$text = (string) $c['main'];
		}
		if ( '' === $text ) {
			continue;
		}
		if ( ! empty( $c['exclude'] ) ) {
			/* translators: %s: a display-condition summary, e.g. "Front page". */
			$text = sprintf( __( 'Exclude: %s', 'minn-admin' ), $text );
		}
		$parts[] = $text;
	}
	return implode( ' · ', $parts );
}

/** Normalize a template post into a surface item. */
function minn_admin_bricks_template_item( $post ) {
	$types = minn_admin_bricks_template_types();
	$type  = (string) \Bricks\Templates::get_template_type( $post->ID );
	$tags  = get_the_terms( $post, BRICKS_DB_TEMPLATE_TAX_TAG );
	$param = defined( 'BRICKS_BUILDER_PARAM' ) ? BRICKS_BUILDER_PARAM : 'bricks';
	return array(
		'id'         => (int) $post->ID,
		'title'      => '' !== $post->post_title ? html_entity_decode( $post->post_title, ENT_QUOTES ) : __( '(no title)', 'minn-admin' ),
		'type'       => $type,
		'typeLabel'  => isset( $types[ $type ] ) ? $types[ $type ] : ( '' !== $type ? $type : '—' ),
		'conditions' => minn_admin_bricks_conditions_summary( $post->ID ),
		'tags'       => ( $tags && ! is_wp_error( $tags ) ) ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '',
		'status'     => (string) $post->post_status,
		'modified'   => (string) $post->post_modified_gmt,
		'editUrl'    => add_query_arg( $param, 'run', get_permalink( $post ) ),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_bricks_active() ) {
		return $surfaces;
	}
	$types       = minn_admin_bricks_template_types();
	$type_tabs   = array();
	$type_select = array();
	foreach ( $types as $value => $label ) {
		$type_tabs[]   = array( $value, $label );
		$type_select[] = array( $value, $label );
	}

	$actions = array();
	// Builder access is Bricks' own gate, not a WP capability — a user who can
	// manage the list without builder access just doesn't get the edit link.
	if ( class_exists( '\Bricks\Capabilities' ) && \Bricks\Capabilities::current_user_can_use_builder() ) {
		$actions[] = array(
			'label' => __( 'Edit in Bricks', 'minn-admin' ),
			'href'  => '{editUrl}',
		);
	}
	if ( minn_admin_bricks_can_create() ) {
		$actions[] = array(
			'label' => __( 'Duplicate', 'minn-admin' ),
			'route' => 'minn-admin/v1/bricks/templates/{id}/duplicate',
		);
	}
	$actions[] = array(
		'label'   => __( 'Move to trash', 'minn-admin' ),
		'method'  => 'DELETE',
		'route'   => 'minn-admin/v1/bricks/templates/{id}',
		'confirm' => __( 'Move this template to the trash? Anywhere it is assigned stops using it.', 'minn-admin' ),
		'danger'  => true,
	);

	$surfaces['bricks-templates'] = array(
		'label'      => __( 'Templates', 'minn-admin' ),
		'sub'        => 'Bricks',
		'icon'       => 'columns',
		'cap'        => 'edit_posts',
		'collection' => array(
			'route'     => 'minn-admin/v1/bricks/templates',
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
			'create'    => minn_admin_bricks_can_create() ? array(
				'label'  => __( 'Add template', 'minn-admin' ),
				'route'  => 'minn-admin/v1/bricks/templates',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
					array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_select ),
				),
			) : null,
			'detail'    => array(
				'skip' => array( 'id', 'type', 'typeLabel', 'editUrl' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/bricks/templates/{id}',
					'method' => 'PUT',
					'fields' => array(
						array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
						array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_select ),
					),
				),
			),
			'actions'   => $actions,
			'bulk'      => array(
				array(
					'label'   => __( 'Move to trash', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/bricks/templates/{id}',
					'confirm' => __( 'Move the selected templates to the trash?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	if ( null === $surfaces['bricks-templates']['collection']['create'] ) {
		unset( $surfaces['bricks-templates']['collection']['create'] );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_bricks_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( 'edit_posts' );
	};

	register_rest_route( 'minn-admin/v1', '/bricks/templates', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$type    = sanitize_key( (string) $request['type'] );
				$search  = trim( (string) $request['search'] );
				$page    = max( 1, (int) ( $request['page'] ?: 1 ) );
				$orderby = in_array( $request['orderby'], array( 'title', 'modified' ), true ) ? $request['orderby'] : 'modified';
				$order   = 'asc' === strtolower( (string) $request['order'] ) ? 'ASC' : 'DESC';
				$args    = array(
					'post_type'      => BRICKS_DB_TEMPLATE_SLUG,
					'post_status'    => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page' => 25,
					'paged'          => $page,
					'orderby'        => $orderby,
					'order'          => $order,
				);
				if ( '' !== $search ) {
					$args['s'] = $search;
				}
				if ( '' !== $type ) {
					$args['meta_query'] = array(
						array(
							'key'   => BRICKS_DB_TEMPLATE_TYPE,
							'value' => $type,
						),
					);
				}
				$query = new WP_Query( $args );
				return rest_ensure_response( array(
					'items' => array_map( 'minn_admin_bricks_template_item', $query->posts ),
					'total' => (int) $query->found_posts,
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return minn_admin_bricks_can_create();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$title = trim( (string) $request['title'] );
				$type  = sanitize_key( (string) $request['type'] );
				if ( '' === $title ) {
					return new WP_Error( 'invalid', __( 'A template needs a title.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( ! isset( minn_admin_bricks_template_types()[ $type ] ) ) {
					return new WP_Error( 'invalid', __( 'Pick a template type.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				// Mirrors Bricks' own create_template: publish for publishers,
				// pending otherwise, type stored as postmeta after insert.
				$id = wp_insert_post( array(
					'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
					'post_title'  => esc_html( $title ),
					'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
				), true );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				update_post_meta( $id, BRICKS_DB_TEMPLATE_TYPE, $type );
				if ( 'password_protection' === $type && class_exists( '\Bricks\Password_Protection' ) && method_exists( '\Bricks\Password_Protection', 'populate_template' ) ) {
					\Bricks\Password_Protection::populate_template( $id );
				}
				return rest_ensure_response( minn_admin_bricks_template_item( get_post( $id ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/templates/(?P<id>\d+)', array(
		array(
			'methods'             => 'PUT',
			'permission_callback' => function ( WP_REST_Request $request ) {
				return current_user_can( 'edit_post', (int) $request['id'] );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
					return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$title = trim( (string) $request['title'] );
				if ( '' !== $title && $title !== $post->post_title ) {
					$result = wp_update_post( array( 'ID' => $post->ID, 'post_title' => esc_html( $title ) ), true );
					if ( is_wp_error( $result ) ) {
						return $result;
					}
				}
				$type = sanitize_key( (string) $request['type'] );
				if ( '' !== $type ) {
					if ( ! isset( minn_admin_bricks_template_types()[ $type ] ) ) {
						return new WP_Error( 'invalid', __( 'Unknown template type.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					update_post_meta( $post->ID, BRICKS_DB_TEMPLATE_TYPE, $type );
				}
				return rest_ensure_response( minn_admin_bricks_template_item( get_post( $post->ID ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function ( WP_REST_Request $request ) {
				return current_user_can( 'delete_post', (int) $request['id'] );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
					return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Trash, not delete: assignments are worth being able to undo
				// from wp-admin's template list.
				if ( ! wp_trash_post( $post->ID ) ) {
					return new WP_Error( 'failed', __( 'The template could not be trashed.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'trashed' => (int) $post->ID ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/bricks/templates/(?P<id>\d+)/duplicate', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			return minn_admin_bricks_can_create() && current_user_can( 'edit_post', (int) $request['id'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = get_post( (int) $request['id'] );
			if ( ! $post || BRICKS_DB_TEMPLATE_SLUG !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Template not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			/* translators: %s: the source template's title. */
			$copy_title = sprintf( __( '%s (copy)', 'minn-admin' ), $post->post_title );
			$new_id     = wp_insert_post( array(
				'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
				'post_title'  => $copy_title,
				'post_type'   => BRICKS_DB_TEMPLATE_SLUG,
			), true );
			if ( is_wp_error( $new_id ) ) {
				return $new_id;
			}
			// Everything Bricks knows about a template rides postmeta (type,
			// element tree, page/template settings) — copy it all except the
			// editor-session keys. Serialized values need the slash round-trip
			// or they double-serialize.
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( in_array( $key, array( '_edit_lock', '_edit_last' ), true ) ) {
					continue;
				}
				foreach ( $values as $value ) {
					add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
				}
			}
			foreach ( array( BRICKS_DB_TEMPLATE_TAX_TAG, BRICKS_DB_TEMPLATE_TAX_BUNDLE ) as $tax ) {
				$terms = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
				if ( $terms && ! is_wp_error( $terms ) ) {
					wp_set_object_terms( $new_id, $terms, $tax );
				}
			}
			return rest_ensure_response( minn_admin_bricks_template_item( get_post( $new_id ) ) );
		},
	) );
} );
