<?php
/**
 * Bundled adapter: Meta Box (wp.org free).
 *
 * Sibling of the ACF editor-panel adapter. Meta Box free does not expose a
 * first-class REST object like ACF's `acf` field, so this adapter:
 *   1. Lists simple fields for the post being edited via fieldsRoute
 *   2. Registers a `minn_meta_box` REST field on every show_in_rest post type
 *      for read/write of those values through rwmb_get_value / rwmb_set_meta
 *
 * Complex field types (clones, media, post/user pickers, maps…) count as
 * locked and link out to wp-admin, same philosophy as ACF.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Meta Box field types Minn can render as panel inputs. */
const MINN_ADMIN_META_BOX_SIMPLE = array(
	'text'     => 'text',
	'textarea' => 'textarea',
	'number'   => 'number',
	'range'    => 'range',
	'email'    => 'email',
	'url'      => 'url',
	'select'   => 'select',
	'radio'    => 'radio',
	'checkbox' => 'true_false',
	'switch'   => 'true_false',
);

/**
 * @return bool
 */
function minn_admin_meta_box_active() {
	return function_exists( 'rwmb_get_registry' ) && function_exists( 'rwmb_get_value' ) && function_exists( 'rwmb_set_meta' );
}

/**
 * Resolve REST base (or post type slug) to a post type name.
 *
 * @param string $rest_base From the editor.
 * @return string
 */
function minn_admin_meta_box_resolve_type( $rest_base ) {
	$rest_base = sanitize_key( $rest_base );
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
		$base = $obj->rest_base ? $obj->rest_base : $obj->name;
		if ( $base === $rest_base || $obj->name === $rest_base ) {
			return $obj->name;
		}
	}
	return 'post';
}

/**
 * Map one Meta Box field onto the panel vocabulary, or null if locked.
 *
 * @param array $field Normalized Meta Box field.
 * @return array|null { name, label, type, choices?, min?, max? }
 */
function minn_admin_meta_box_map_field( $field ) {
	if ( empty( $field['id'] ) || empty( $field['type'] ) ) {
		return null;
	}
	// Cloneable / multi-value fields are not safe as single panel inputs.
	if ( ! empty( $field['clone'] ) || ! empty( $field['multiple'] ) ) {
		return null;
	}
	$type = $field['type'];
	if ( ! isset( MINN_ADMIN_META_BOX_SIMPLE[ $type ] ) ) {
		return null;
	}
	$mapped = array(
		'name'  => $field['id'],
		'label' => ! empty( $field['name'] ) ? $field['name'] : $field['id'],
		'type'  => MINN_ADMIN_META_BOX_SIMPLE[ $type ],
	);
	if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
		// Meta Box options are value => label; panel formNormField accepts choices object.
		$mapped['choices'] = $field['options'];
	}
	if ( isset( $field['min'] ) && '' !== $field['min'] && null !== $field['min'] ) {
		$mapped['min'] = $field['min'];
	}
	if ( isset( $field['max'] ) && '' !== $field['max'] && null !== $field['max'] ) {
		$mapped['max'] = $field['max'];
	}
	return $mapped;
}

/**
 * Meta boxes that apply to a post type (and optional post id for location rules).
 *
 * @param string $post_type Post type slug.
 * @param int    $post_id   0 for new posts.
 * @return array List of RW_Meta_Box objects.
 */
function minn_admin_meta_box_boxes_for( $post_type, $post_id = 0 ) {
	if ( ! minn_admin_meta_box_active() ) {
		return array();
	}
	$registry = rwmb_get_registry( 'meta_box' );
	$all = $registry->get_by( array( 'object_type' => 'post' ) );
	$out = array();
	foreach ( $all as $mb ) {
		// Prefer the settings array: __get('post_types') can be empty after normalize.
		$types = ! empty( $mb->meta_box['post_types'] )
			? (array) $mb->meta_box['post_types']
			: (array) $mb->post_types;
		if ( $types && ! in_array( $post_type, $types, true ) ) {
			continue;
		}
		// Honour rwmb_show filters (capability / context gates).
		$show = apply_filters( 'rwmb_show', true, $mb->meta_box );
		$show = apply_filters( "rwmb_show_{$mb->id}", $show, $mb->meta_box );
		if ( ! $show ) {
			continue;
		}
		$out[] = $mb;
	}
	return $out;
}

/**
 * Build the fieldsRoute response for a post.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array{groups: array}
 */
function minn_admin_meta_box_fields_payload( $post_id, $post_type ) {
	$groups = array();
	foreach ( minn_admin_meta_box_boxes_for( $post_type, $post_id ) as $mb ) {
		$mapped = array();
		$locked = 0;
		// RW_Meta_Box exposes fields via __get — isset( $mb->fields ) is false.
		$fields = (array) $mb->fields;
		foreach ( $fields as $field ) {
			// Divider / heading / custom_html are chrome, not locked data.
			if ( in_array( $field['type'] ?? '', array( 'divider', 'heading', 'custom_html', 'button' ), true ) ) {
				continue;
			}
			$simple = minn_admin_meta_box_map_field( $field );
			if ( ! $simple ) {
				$locked++;
				continue;
			}
			$mapped[] = $simple;
		}
		if ( $mapped || $locked ) {
			// Prefer settings title: __get can surface empty for some boxes.
			$title = '';
			if ( ! empty( $mb->meta_box['title'] ) ) {
				$title = (string) $mb->meta_box['title'];
			} elseif ( $mb->title ) {
				$title = (string) $mb->title;
			} else {
				$title = (string) $mb->id;
			}
			$groups[] = array(
				'group'  => $title,
				'fields' => $mapped,
				'locked' => $locked,
			);
		}
	}
	return array( 'groups' => $groups );
}

/**
 * All simple field ids that apply to a post (for REST get/update).
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function minn_admin_meta_box_simple_ids_for_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$ids = array();
	foreach ( minn_admin_meta_box_boxes_for( $post->post_type, $post_id ) as $mb ) {
		foreach ( (array) $mb->fields as $field ) {
			$simple = minn_admin_meta_box_map_field( $field );
			if ( $simple ) {
				$ids[] = $simple['name'];
			}
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Read all simple Meta Box values for a post as { field_id => value }.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function minn_admin_meta_box_read_values( $post_id ) {
	$out = array();
	foreach ( minn_admin_meta_box_simple_ids_for_post( $post_id ) as $id ) {
		$val = rwmb_get_value( $id, array(), $post_id );
		// Checkbox/switch store 0/1 or empty — normalize booleans for the panel.
		$field = function_exists( 'rwmb_get_field_settings' ) ? rwmb_get_field_settings( $id, array(), $post_id ) : null;
		$type  = is_array( $field ) && ! empty( $field['type'] ) ? $field['type'] : '';
		if ( in_array( $type, array( 'checkbox', 'switch' ), true ) ) {
			$out[ $id ] = ! empty( $val );
		} else {
			// Arrays shouldn't appear for simple non-multiple fields; stringify edge cases.
			if ( is_array( $val ) ) {
				$out[ $id ] = '';
			} else {
				$out[ $id ] = $val;
			}
		}
	}
	return $out;
}

/**
 * Write simple field values through Meta Box's own setter.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Field id => value.
 */
function minn_admin_meta_box_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$allowed = array_flip( minn_admin_meta_box_simple_ids_for_post( $post_id ) );
	foreach ( $values as $key => $value ) {
		if ( ! isset( $allowed[ $key ] ) ) {
			continue;
		}
		$field = function_exists( 'rwmb_get_field_settings' ) ? rwmb_get_field_settings( $key, array(), $post_id ) : null;
		$type  = is_array( $field ) && ! empty( $field['type'] ) ? $field['type'] : '';
		if ( in_array( $type, array( 'checkbox', 'switch' ), true ) ) {
			// Meta Box checkbox/switch expect 1 or empty/0.
			$value = ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		}
		// Empty string on a clearable select: delete the meta so the field is truly empty.
		if ( ( '' === $value || null === $value ) && function_exists( 'rwmb_delete_meta' ) ) {
			rwmb_delete_meta( $post_id, $key );
			continue;
		}
		rwmb_set_meta( $post_id, $key, $value );
	}
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_meta_box_active() ) {
		return $panels;
	}
	$panels['meta-box'] = array(
		'label'       => 'Custom fields',
		'sub'         => 'Meta Box',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/meta-box/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_meta_box',
		'writeKey'    => 'minn_meta_box',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_meta_box_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/meta-box/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
			'post_type' => array( 'type' => 'string', 'default' => 'posts' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$post_id   = (int) $request['post_id'];
			$post_type = minn_admin_meta_box_resolve_type( $request['post_type'] );
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post && current_user_can( 'edit_post', $post_id ) ) {
					$post_type = $post->post_type;
				} elseif ( $post ) {
					return new WP_Error( 'rest_forbidden', 'You cannot edit this post.', array( 'status' => 403 ) );
				}
			}
			return rest_ensure_response( minn_admin_meta_box_fields_payload( $post_id, $post_type ) );
		},
	) );

	// Values ride the post REST object (same shape ACF uses with its `acf` key).
	$types = get_post_types( array( 'show_in_rest' => true ), 'names' );
	foreach ( $types as $type ) {
		register_rest_field(
			$type,
			'minn_meta_box',
			array(
				'get_callback'    => function ( $obj ) {
					$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
					if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
						return new stdClass(); // empty object in JSON
					}
					return (object) minn_admin_meta_box_read_values( $id );
				},
				'update_callback' => function ( $value, $post ) {
					if ( ! $post instanceof WP_Post ) {
						return;
					}
					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return;
					}
					// REST may deliver object as array or stdClass.
					if ( is_object( $value ) ) {
						$value = (array) $value;
					}
					if ( ! is_array( $value ) ) {
						return;
					}
					minn_admin_meta_box_write_values( $post->ID, $value );
				},
				'schema'          => array(
					'description' => 'Meta Box simple field values for Minn Admin.',
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}
} );
