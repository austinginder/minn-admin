<?php
/**
 * Bundled adapter: Pods (wp.org free).
 *
 * Sibling of the ACF / Meta Box editor-panel adapters. Pods free stores field
 * values as post meta (for extended post types) and does not expose a first-class
 * REST object like ACF's `acf` field, so this adapter:
 *   1. Lists simple fields for the post being edited via fieldsRoute
 *   2. Registers a `minn_pods` REST field on every show_in_rest post type for
 *      read/write through pods( $pod, $id )->field() / ->save()
 *
 * Complex field types (file, relationships, WYSIWYG, multi-pick, repeatable…)
 * count as locked and link out to wp-admin, same philosophy as ACF.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Pods field types Minn can render as panel inputs. */
const MINN_ADMIN_PODS_SIMPLE = array(
	'text'      => 'text',
	'website'   => 'url',
	'phone'     => 'text',
	'email'     => 'email',
	'password'  => 'text',
	'paragraph' => 'textarea',
	'code'      => 'textarea',
	'number'    => 'number',
	'currency'  => 'number',
	// Single custom-simple pick only (see map_field).
	'pick'      => 'select',
	'boolean'   => 'true_false',
);

/**
 * @return bool
 */
function minn_admin_pods_active() {
	return function_exists( 'pods' ) && function_exists( 'pods_api' );
}

/**
 * Resolve REST base (or post type slug) to a post type name.
 *
 * @param string $rest_base From the editor.
 * @return string
 */
function minn_admin_pods_resolve_type( $rest_base ) {
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
 * Read a Pods field arg from a Whatsit or array.
 *
 * @param object|array $field Field object.
 * @param string       $key   Arg name.
 * @param mixed        $default Default.
 * @return mixed
 */
function minn_admin_pods_field_arg( $field, $key, $default = null ) {
	if ( is_object( $field ) ) {
		if ( isset( $field[ $key ] ) ) {
			return $field[ $key ];
		}
		if ( method_exists( $field, 'get_arg' ) ) {
			$v = $field->get_arg( $key );
			return ( null !== $v && '' !== $v ) ? $v : $default;
		}
		return $default;
	}
	return is_array( $field ) && array_key_exists( $key, $field ) ? $field[ $key ] : $default;
}

/**
 * Parse Pods pick_custom ("value|Label\n…") into choices map.
 *
 * @param string $custom Raw pick_custom.
 * @return array value => label
 */
function minn_admin_pods_parse_pick_custom( $custom ) {
	$choices = array();
	$custom  = (string) $custom;
	if ( '' === $custom ) {
		return $choices;
	}
	// Pods accepts newlines or commas as separators.
	$lines = preg_split( '/[\r\n]+/', $custom );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( false !== strpos( $line, '|' ) ) {
			list( $val, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
		} else {
			$val   = $line;
			$label = $line;
		}
		if ( '' !== $val ) {
			$choices[ $val ] = $label !== '' ? $label : $val;
		}
	}
	return $choices;
}

/**
 * Map one Pods field onto the panel vocabulary, or null if locked.
 *
 * @param object|array $field Pods field.
 * @return array|null { name, label, type, choices?, min?, max? }
 */
function minn_admin_pods_map_field( $field ) {
	$name = (string) minn_admin_pods_field_arg( $field, 'name', '' );
	$type = (string) minn_admin_pods_field_arg( $field, 'type', '' );
	if ( '' === $name || '' === $type ) {
		return null;
	}
	// Chrome / layout-only.
	if ( in_array( $type, array( 'heading', 'html' ), true ) ) {
		return null;
	}
	// Repeatable fields are multi-value — not safe as a single panel input.
	$repeatable = minn_admin_pods_field_arg( $field, 'repeatable', 0 );
	if ( ! empty( $repeatable ) && '0' !== (string) $repeatable ) {
		return null;
	}
	if ( ! isset( MINN_ADMIN_PODS_SIMPLE[ $type ] ) ) {
		return null;
	}
	// Pick: only single custom-simple lists map cleanly to a select.
	if ( 'pick' === $type ) {
		$pick_object = (string) minn_admin_pods_field_arg( $field, 'pick_object', '' );
		$format_type = (string) minn_admin_pods_field_arg( $field, 'pick_format_type', 'single' );
		if ( 'custom-simple' !== $pick_object ) {
			return null;
		}
		if ( $format_type && 'single' !== $format_type ) {
			return null;
		}
		$choices = minn_admin_pods_parse_pick_custom( minn_admin_pods_field_arg( $field, 'pick_custom', '' ) );
		if ( ! $choices ) {
			return null;
		}
		// Empty option so clearing is possible.
		$choices = array( '' => '—' ) + $choices;
		return array(
			'name'    => $name,
			'label'   => (string) ( minn_admin_pods_field_arg( $field, 'label', $name ) ?: $name ),
			'type'    => 'select',
			'choices' => $choices,
		);
	}

	$mapped = array(
		'name'  => $name,
		'label' => (string) ( minn_admin_pods_field_arg( $field, 'label', $name ) ?: $name ),
		'type'  => MINN_ADMIN_PODS_SIMPLE[ $type ],
	);
	$min = minn_admin_pods_field_arg( $field, 'number_min', null );
	if ( null === $min || '' === $min ) {
		$min = minn_admin_pods_field_arg( $field, 'min', null );
	}
	$max = minn_admin_pods_field_arg( $field, 'number_max', null );
	if ( null === $max || '' === $max ) {
		$max = minn_admin_pods_field_arg( $field, 'max', null );
	}
	if ( null !== $min && '' !== $min ) {
		$mapped['min'] = $min;
	}
	if ( null !== $max && '' !== $max ) {
		$mapped['max'] = $max;
	}
	return $mapped;
}

/**
 * Pods that extend a given post type (object match).
 *
 * @param string $post_type Post type slug.
 * @return array List of pod objects (Whatsit) keyed by name.
 */
function minn_admin_pods_for_type( $post_type ) {
	if ( ! minn_admin_pods_active() ) {
		return array();
	}
	try {
		$pods = pods_api()->load_pods(
			array(
				'type'   => 'post_type',
				'object' => $post_type,
				'fields' => true,
			)
		);
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		return array();
	}
	return is_array( $pods ) ? $pods : array();
}

/**
 * Build the fieldsRoute response for a post.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array{groups: array}
 */
function minn_admin_pods_fields_payload( $post_id, $post_type ) {
	$groups = array();
	foreach ( minn_admin_pods_for_type( $post_type ) as $pod_name => $pod ) {
		$fields = array();
		if ( is_object( $pod ) && method_exists( $pod, 'get_fields' ) ) {
			$fields = (array) $pod->get_fields();
		} elseif ( is_array( $pod ) && ! empty( $pod['fields'] ) ) {
			$fields = (array) $pod['fields'];
		}
		$mapped = array();
		$locked = 0;
		foreach ( $fields as $field ) {
			$type = (string) minn_admin_pods_field_arg( $field, 'type', '' );
			// Heading/html are chrome, not locked data.
			if ( in_array( $type, array( 'heading', 'html' ), true ) ) {
				continue;
			}
			$simple = minn_admin_pods_map_field( $field );
			if ( ! $simple ) {
				$locked++;
				continue;
			}
			$mapped[] = $simple;
		}
		if ( $mapped || $locked ) {
			$label = is_object( $pod ) && method_exists( $pod, 'get_arg' )
				? (string) ( $pod->get_arg( 'label' ) ?: $pod_name )
				: (string) ( is_array( $pod ) && ! empty( $pod['label'] ) ? $pod['label'] : $pod_name );
			$groups[] = array(
				'group'  => $label,
				'fields' => $mapped,
				'locked' => $locked,
			);
		}
	}
	return array( 'groups' => $groups );
}

/**
 * Simple field names that apply to a post (for REST get/update).
 *
 * @param int $post_id Post ID.
 * @return array name => panel type
 */
function minn_admin_pods_simple_map_for_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$map = array();
	foreach ( minn_admin_pods_for_type( $post->post_type ) as $pod ) {
		$fields = array();
		if ( is_object( $pod ) && method_exists( $pod, 'get_fields' ) ) {
			$fields = (array) $pod->get_fields();
		} elseif ( is_array( $pod ) && ! empty( $pod['fields'] ) ) {
			$fields = (array) $pod['fields'];
		}
		foreach ( $fields as $field ) {
			$simple = minn_admin_pods_map_field( $field );
			if ( $simple ) {
				$map[ $simple['name'] ] = $simple['type'];
			}
		}
	}
	return $map;
}

/**
 * Read all simple Pods values for a post as { field_name => value }.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function minn_admin_pods_read_values( $post_id ) {
	$out  = array();
	$post = get_post( $post_id );
	if ( ! $post || ! minn_admin_pods_active() ) {
		return $out;
	}
	$simple = minn_admin_pods_simple_map_for_post( $post_id );
	if ( ! $simple ) {
		return $out;
	}
	// Prefer pods() so relationship/boolean normalization stays Pods' job.
	// Fall back to raw post meta if the pod object won't load.
	$pod_obj = null;
	try {
		$pod_obj = pods( $post->post_type, $post_id );
		if ( ! $pod_obj || ( method_exists( $pod_obj, 'valid' ) && ! $pod_obj->valid() ) ) {
			$pod_obj = null;
		}
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		$pod_obj = null;
	}

	foreach ( $simple as $name => $panel_type ) {
		$val = null;
		if ( $pod_obj && method_exists( $pod_obj, 'field' ) ) {
			$val = $pod_obj->field( $name );
		} else {
			$val = get_post_meta( $post_id, $name, true );
		}
		if ( 'true_false' === $panel_type ) {
			$out[ $name ] = ! empty( $val ) && '0' !== (string) $val && 'false' !== (string) $val;
		} elseif ( is_array( $val ) ) {
			// Multi-value shouldn't appear for simple fields; stringify edge cases.
			$out[ $name ] = '';
		} else {
			$out[ $name ] = $val;
		}
	}
	return $out;
}

/**
 * Write simple field values through Pods' own saver.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Field name => value.
 */
function minn_admin_pods_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) || ! minn_admin_pods_active() ) {
		return;
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return;
	}
	$allowed = minn_admin_pods_simple_map_for_post( $post_id );
	$data    = array();
	foreach ( $values as $key => $value ) {
		if ( ! isset( $allowed[ $key ] ) ) {
			continue;
		}
		$panel_type = $allowed[ $key ];
		if ( 'true_false' === $panel_type ) {
			$value = ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		}
		// Empty select: clear meta.
		if ( ( '' === $value || null === $value ) && 'true_false' !== $panel_type ) {
			$data[ $key ] = '';
			continue;
		}
		$data[ $key ] = $value;
	}
	if ( ! $data ) {
		return;
	}
	try {
		$pod_obj = pods( $post->post_type, $post_id );
		if ( $pod_obj && method_exists( $pod_obj, 'save' ) ) {
			// pods()->save accepts field map for an existing item.
			$pod_obj->save( $data );
			return;
		}
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Fall through to raw meta.
	}
	// Fallback: write post meta directly (extended post-type storage).
	foreach ( $data as $key => $value ) {
		if ( '' === $value || null === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, $value );
		}
	}
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_pods_active() ) {
		return $panels;
	}
	$panels['pods'] = array(
		'label'       => 'Custom fields',
		'sub'         => 'Pods',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/pods/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_pods',
		'writeKey'    => 'minn_pods',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_pods_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/pods/fields', array(
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
			$post_type = minn_admin_pods_resolve_type( $request['post_type'] );
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( $post && current_user_can( 'edit_post', $post_id ) ) {
					$post_type = $post->post_type;
				} elseif ( $post ) {
					return new WP_Error( 'rest_forbidden', 'You cannot edit this post.', array( 'status' => 403 ) );
				}
			}
			return rest_ensure_response( minn_admin_pods_fields_payload( $post_id, $post_type ) );
		},
	) );

	$types = get_post_types( array( 'show_in_rest' => true ), 'names' );
	foreach ( $types as $type ) {
		register_rest_field(
			$type,
			'minn_pods',
			array(
				'get_callback'    => function ( $obj ) {
					$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
					if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
						return new stdClass();
					}
					return (object) minn_admin_pods_read_values( $id );
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
					minn_admin_pods_write_values( $post->ID, $value );
				},
				'schema'          => array(
					'description' => 'Pods simple field values for Minn Admin.',
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}
} );
