<?php
/**
 * Bundled adapter: Advanced Custom Fields (free and Pro).
 *
 * The proving adapter for the editor-panels framework. Values used to ride
 * ACF's own `acf` REST object, which only exists for field groups with
 * "Show in REST API" enabled — off by default, so on most real sites the
 * panel never rendered at all. Values now ride a dedicated `minn_acf` REST
 * field (the Meta Box / Pods pattern), read and written through ACF's own
 * get_field / update_field by field key, so every applicable group shows up
 * regardless of that setting. Complex field types (repeaters, galleries,
 * relationships…) defer to wp-admin, mirroring the editor's locked-mode
 * philosophy.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

const MINN_ADMIN_ACF_SIMPLE_TYPES = array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'true_false' );

/** Layout-only ACF field types: chrome, not data — never mapped, never counted as locked. */
const MINN_ADMIN_ACF_CHROME_TYPES = array( 'tab', 'message', 'accordion' );

/**
 * @return bool
 */
function minn_admin_acf_active() {
	return function_exists( 'acf_get_field_groups' )
		&& function_exists( 'acf_get_fields' )
		&& function_exists( 'get_field' )
		&& function_exists( 'update_field' );
}

/**
 * Field groups that apply to a post (or, for new posts, a post type).
 *
 * acf_get_field_groups applies ACF's own location rules, so block- and
 * options-page-scoped groups never match a post edit screen.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array
 */
function minn_admin_acf_groups_for( $post_id, $post_type ) {
	return acf_get_field_groups( $post_id ? array( 'post_id' => $post_id ) : array( 'post_type' => $post_type ) );
}

/**
 * Map one ACF field onto the panel vocabulary, or null if locked.
 *
 * @param array $f ACF field array.
 * @return array|null { name, label, type, choices?, min?, max?, key }
 */
function minn_admin_acf_map_field( $f ) {
	if ( empty( $f['name'] ) || empty( $f['type'] ) ) {
		return null;
	}
	if ( ! in_array( $f['type'], MINN_ADMIN_ACF_SIMPLE_TYPES, true ) || ! empty( $f['multiple'] ) ) {
		return null;
	}
	return array(
		'name'    => $f['name'],
		'label'   => $f['label'],
		'type'    => $f['type'],
		'choices' => ! empty( $f['choices'] ) ? $f['choices'] : null,
		'min'     => isset( $f['min'] ) && '' !== $f['min'] ? $f['min'] : null,
		'max'     => isset( $f['max'] ) && '' !== $f['max'] ? $f['max'] : null,
		'key'     => $f['key'],
	);
}

/**
 * Build the fieldsRoute response for a post.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array{groups: array}
 */
function minn_admin_acf_fields_payload( $post_id, $post_type ) {
	$out = array();
	foreach ( minn_admin_acf_groups_for( $post_id, $post_type ) as $group ) {
		$fields = acf_get_fields( $group );
		$mapped = array();
		$locked = 0;
		foreach ( (array) $fields as $f ) {
			if ( in_array( $f['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
				continue;
			}
			$simple = minn_admin_acf_map_field( $f );
			if ( ! $simple ) {
				$locked++;
				continue;
			}
			unset( $simple['key'] ); // internal — the panel keys inputs by name
			$mapped[] = $simple;
		}
		if ( $mapped || $locked ) {
			$out[] = array(
				'group'  => $group['title'],
				'fields' => $mapped,
				'locked' => $locked,
			);
		}
	}
	return array( 'groups' => $out );
}

/**
 * All simple fields that apply to a post, keyed by field name.
 *
 * @param int $post_id Post ID.
 * @return array { name => { name, type, key, … } }
 */
function minn_admin_acf_simple_fields_for_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$out = array();
	foreach ( minn_admin_acf_groups_for( $post_id, $post->post_type ) as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $f ) {
			$simple = minn_admin_acf_map_field( $f );
			if ( $simple ) {
				$out[ $simple['name'] ] = $simple;
			}
		}
	}
	return $out;
}

/**
 * Read all simple ACF values for a post as { field_name => value }.
 *
 * Raw (unformatted) values: a select's stored choice key is what the panel's
 * choices map wants; format_value could hand back labels or arrays depending
 * on each field's return_format.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function minn_admin_acf_read_values( $post_id ) {
	$out = array();
	foreach ( minn_admin_acf_simple_fields_for_post( $post_id ) as $name => $field ) {
		$val = get_field( $field['key'], $post_id, false );
		if ( 'true_false' === $field['type'] ) {
			$out[ $name ] = ! empty( $val );
		} elseif ( is_array( $val ) ) {
			// Shouldn't appear for simple non-multiple fields; don't leak structure.
			$out[ $name ] = '';
		} else {
			$out[ $name ] = $val;
		}
	}
	return $out;
}

/**
 * Write simple field values through ACF's own setter, by field key.
 *
 * Writing by key (not name) is what makes ACF store the `_name` key
 * reference correctly on first write.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Field name => value.
 */
function minn_admin_acf_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$allowed = minn_admin_acf_simple_fields_for_post( $post_id );
	foreach ( $values as $name => $value ) {
		if ( ! isset( $allowed[ $name ] ) ) {
			continue;
		}
		$field = $allowed[ $name ];
		if ( 'true_false' === $field['type'] ) {
			$value = ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		} elseif ( null === $value || false === $value ) {
			// The panel clears fields with empty values; ACF's own form save
			// stores '' rather than deleting the row — match it.
			$value = '';
		} elseif ( ! is_scalar( $value ) ) {
			continue;
		}
		update_field( $field['key'], $value, $post_id );
	}
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_acf_active() ) {
		return $panels;
	}
	$panels['acf'] = array(
		'label'       => __( 'Custom fields', 'minn-admin' ),
		'sub'         => 'ACF',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/acf/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_acf',
		'writeKey'    => 'minn_acf',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/acf/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $request ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return false;
			}
			$post_id = (int) $request['post_id'];
			return ! $post_id || current_user_can( 'edit_post', $post_id );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
			'post_type' => array( 'type' => 'string', 'default' => 'posts' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$post_id = (int) $request['post_id'];

			// The app passes the REST base; resolve it to a post type slug.
			$rest_base = sanitize_key( $request['post_type'] );
			$post_type = 'post';
			foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
				$base = $obj->rest_base ? $obj->rest_base : $obj->name;
				if ( $base === $rest_base || $obj->name === $rest_base ) {
					$post_type = $obj->name;
					break;
				}
			}

			// Authorize against the resolved target, the way the Pods and Meta
			// Box panels do. The permission callback can only see edit_posts and
			// the post id; with no post id its second clause short-circuits true,
			// which let a Contributor name any post type and read its private
			// field schema. A field group describes the site's data model, and
			// internal naming and credential field ids live in it.
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit this post.', 'minn-admin' ), array( 'status' => 403 ) );
				}
				$post_type = $post->post_type;
			} else {
				$type_obj = get_post_type_object( $post_type );
				if ( ! $type_obj || ! current_user_can( $type_obj->cap->edit_posts ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit that post type.', 'minn-admin' ), array( 'status' => 403 ) );
				}
			}

			return rest_ensure_response( minn_admin_acf_fields_payload( $post_id, $post_type ) );
		},
	) );

	// Values ride the post REST object as `minn_acf` (the Meta Box pattern) —
	// present whether or not the group opted into ACF's own REST exposure.
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
		register_rest_field(
			$type,
			'minn_acf',
			array(
				'get_callback'    => function ( $obj ) {
					$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
					if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
						return new stdClass(); // empty object in JSON
					}
					return (object) minn_admin_acf_read_values( $id );
				},
				'update_callback' => function ( $value, $post ) {
					if ( ! $post instanceof WP_Post ) {
						return;
					}
					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return;
					}
					if ( is_object( $value ) ) {
						$value = (array) $value;
					}
					if ( ! is_array( $value ) ) {
						return;
					}
					minn_admin_acf_write_values( $post->ID, $value );
				},
				'schema'          => array(
					'description' => __( 'ACF simple field values for Minn Admin.', 'minn-admin' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}
} );
