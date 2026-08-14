<?php
/**
 * Bundled adapter: Advanced Custom Fields (free and Pro).
 *
 * The proving adapter for the editor-panels framework. ACF field groups with
 * "Show in REST API" enabled already expose a read/write `acf` object on the
 * post REST response — this adapter adds a shim that describes which fields
 * apply to the post being edited, and Minn's editor renders the simple ones
 * as native inputs. Complex field types (repeaters, galleries, relationships…)
 * defer to wp-admin, mirroring the editor's locked-mode philosophy.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

const MINN_ADMIN_ACF_SIMPLE_TYPES = array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'true_false' );

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return $panels;
	}
	$panels['acf'] = array(
		'label'       => 'Custom fields',
		'sub'         => 'ACF',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/acf/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'acf',
		'writeKey'    => 'acf',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
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
					return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'rest_forbidden', 'You cannot edit this post.', array( 'status' => 403 ) );
				}
				$post_type = $post->post_type;
			} else {
				$type_obj = get_post_type_object( $post_type );
				if ( ! $type_obj || ! current_user_can( $type_obj->cap->edit_posts ) ) {
					return new WP_Error( 'rest_forbidden', 'You cannot edit that post type.', array( 'status' => 403 ) );
				}
			}

			$groups = acf_get_field_groups( $post_id ? array( 'post_id' => $post_id ) : array( 'post_type' => $post_type ) );
			$out    = array();

			foreach ( $groups as $group ) {
				// Only groups exposed to REST can round-trip values through wp/v2.
				if ( empty( $group['show_in_rest'] ) ) {
					continue;
				}
				$fields = acf_get_fields( $group );
				$mapped = array();
				$locked = 0;
				foreach ( (array) $fields as $f ) {
					$simple = in_array( $f['type'], MINN_ADMIN_ACF_SIMPLE_TYPES, true ) && empty( $f['multiple'] );
					if ( ! $simple ) {
						$locked++;
						continue;
					}
					$mapped[] = array(
						'name'    => $f['name'],
						'label'   => $f['label'],
						'type'    => $f['type'],
						'choices' => ! empty( $f['choices'] ) ? $f['choices'] : null,
						'min'     => isset( $f['min'] ) && '' !== $f['min'] ? $f['min'] : null,
						'max'     => isset( $f['max'] ) && '' !== $f['max'] ? $f['max'] : null,
					);
				}
				if ( $mapped || $locked ) {
					$out[] = array(
						'group'  => $group['title'],
						'fields' => $mapped,
						'locked' => $locked,
					);
				}
			}

			return rest_ensure_response( array( 'groups' => $out ) );
		},
	) );
} );
