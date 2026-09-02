<?php
/**
 * Bundled adapter: JetEngine meta boxes (Crocoblock) as an editor panel.
 *
 * JetEngine keeps its meta box definitions in the jet_engine_meta_boxes
 * option and the per-post-type fields in the jet_post_types table; both
 * are read here through jet_engine()'s own data classes, never raw. Field
 * values are plain post meta keyed by the field name, written through the
 * same calls Cherry_X_Post_Meta uses when the box is saved from wp-admin
 * (update_post_meta per field, false on empty). This adapter:
 *   1. Lists the simple fields for the post being edited via fieldsRoute,
 *      one group per meta box (plus the post type's own fields)
 *   2. Registers a `minn_jet` REST field on every show_in_rest post type
 *      for read/write of those values
 *
 * Stored shapes mirrored (from their post.php prepare/save paths):
 * switcher 'true'/'false'; checkbox a { key: 'true'|'false' } map unless
 * is_array, then a list of keys; select is_multiple a list; date 'Y-m-d'
 * or a timestamp when is_timestamp; media the id, the url, or a JSON
 * {id,url} per value_format; gallery a comma-joined id list (or a JSON
 * list of {id,url} for 'both'). Repeaters, posts pickers, maps, glossary-
 * sourced options and url-format galleries count as locked and link out.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_fields_active() {
	return function_exists( 'jet_engine' ) && is_object( jet_engine()->meta_boxes ) && is_object( jet_engine()->meta_boxes->data );
}

function minn_admin_jet_resolve_type( $rest_base ) {
	$rest_base = sanitize_key( $rest_base );
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
		$base = $obj->rest_base ? $obj->rest_base : $obj->name;
		if ( $base === $rest_base || $obj->name === $rest_base ) {
			return $obj->name;
		}
	}
	return 'post';
}

function minn_admin_jet_bool( $v ) {
	return ! empty( $v ) && filter_var( $v, FILTER_VALIDATE_BOOLEAN );
}

/** JetEngine's [{key,value}] option rows as value => label. */
function minn_admin_jet_choices( $field ) {
	$out = array();
	foreach ( ( isset( $field['options'] ) && is_array( $field['options'] ) ) ? $field['options'] : array() as $i => $opt ) {
		if ( is_array( $opt ) && isset( $opt['key'] ) ) {
			$out[ (string) $opt['key'] ] = (string) ( $opt['value'] ?? $opt['key'] );
		} elseif ( is_scalar( $opt ) ) {
			$out[ (string) $i ] = (string) $opt;
		}
	}
	return $out;
}

/**
 * Map one JetEngine field onto the panel vocabulary, or null when locked.
 *
 * @return array|null { name, label, type, choices?, min?, max?, help?, _jet }
 */
function minn_admin_jet_map_field( $field ) {
	$name = isset( $field['name'] ) ? (string) $field['name'] : '';
	$type = isset( $field['type'] ) ? (string) $field['type'] : '';
	if ( '' === $name || '' === $type ) {
		return null;
	}
	if ( isset( $field['object_type'] ) && 'field' !== $field['object_type'] ) {
		return null; // tabs, accordions, endpoints: chrome, not data
	}
	if ( in_array( $type, array( 'html', 'heading' ), true ) ) {
		return null;
	}
	if ( ! empty( $field['options_source'] ) && 'manual' !== $field['options_source'] ) {
		return null; // glossary / callback sourced options
	}
	$label  = isset( $field['title'] ) && '' !== $field['title'] ? (string) $field['title'] : $name;
	$mapped = array( 'name' => $name, 'label' => $label, '_jet' => array( 'type' => $type ) );
	if ( ! empty( $field['description'] ) ) {
		$mapped['help'] = wp_strip_all_tags( (string) $field['description'] );
	}
	switch ( $type ) {
		case 'text':
		case 'iconpicker':
			$mapped['type'] = 'text';
			break;
		case 'textarea':
			$mapped['type'] = 'textarea';
			break;
		case 'wysiwyg':
			$mapped['type'] = 'wysiwyg';
			break;
		case 'number':
			$mapped['type'] = 'number';
			foreach ( array( 'min_value' => 'min', 'max_value' => 'max', 'step_value' => 'step' ) as $from => $to ) {
				if ( isset( $field[ $from ] ) && '' !== $field[ $from ] && null !== $field[ $from ] ) {
					$mapped[ $to ] = $field[ $from ];
				}
			}
			break;
		case 'switcher':
			$mapped['type'] = 'true_false';
			break;
		case 'colorpicker':
			$mapped['type'] = 'color_picker';
			break;
		case 'date':
			$mapped['type'] = 'date';
			$mapped['_jet']['timestamp'] = minn_admin_jet_bool( $field['is_timestamp'] ?? false );
			break;
		case 'datetime-local':
			$mapped['type'] = 'datetime';
			$mapped['_jet']['timestamp'] = minn_admin_jet_bool( $field['is_timestamp'] ?? false );
			break;
		case 'time':
			$mapped['type'] = 'time';
			$mapped['_jet']['timestamp'] = minn_admin_jet_bool( $field['is_timestamp'] ?? false );
			break;
		case 'select':
			$choices = minn_admin_jet_choices( $field );
			if ( ! $choices ) {
				return null;
			}
			if ( minn_admin_jet_bool( $field['is_multiple'] ?? false ) ) {
				$mapped['type']    = 'multicheck';
				$mapped['choices'] = $choices;
			} else {
				$mapped['type']    = 'select';
				$mapped['choices'] = array( '' => '—' ) + $choices;
			}
			break;
		case 'radio':
			$choices = minn_admin_jet_choices( $field );
			if ( ! $choices ) {
				return null;
			}
			$mapped['type']    = 'radio';
			$mapped['choices'] = $choices;
			break;
		case 'checkbox':
			$choices = minn_admin_jet_choices( $field );
			if ( ! $choices || minn_admin_jet_bool( $field['allow_custom'] ?? false ) ) {
				return null;
			}
			$mapped['type']             = 'multicheck';
			$mapped['choices']          = $choices;
			$mapped['_jet']['is_array'] = minn_admin_jet_bool( $field['is_array'] ?? false );
			break;
		case 'media':
			$fmt = ! empty( $field['value_format'] ) ? (string) $field['value_format'] : 'id';
			if ( ! in_array( $fmt, array( 'id', 'url', 'both' ), true ) ) {
				return null;
			}
			$mapped['type']           = 'image';
			$mapped['_jet']['format'] = $fmt;
			break;
		case 'gallery':
			$fmt = ! empty( $field['value_format'] ) ? (string) $field['value_format'] : 'id';
			if ( ! in_array( $fmt, array( 'id', 'both' ), true ) ) {
				return null; // url-only galleries have no ids to hand the picker
			}
			$mapped['type']           = 'gallery';
			$mapped['_jet']['format'] = $fmt;
			break;
		default:
			return null; // repeater, posts, map, glossary…
	}
	return $mapped;
}

/**
 * Meta box groups that apply to a post type (and, when given, a post):
 * every 'post' meta box whose allowed_post_type names it, honoring the
 * box's allowed / excluded post conditions, plus the fields the JetEngine
 * post type definition carries itself.
 *
 * @return array[] { group, fields: mapped[], locked }
 */
function minn_admin_jet_groups_for( $post_type, $post_id = 0 ) {
	$groups = array();
	$push   = function ( $label, $raw_fields ) use ( &$groups ) {
		$mapped = array();
		$locked = 0;
		foreach ( (array) $raw_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( isset( $field['object_type'] ) && 'field' !== $field['object_type'] ) {
				continue;
			}
			if ( in_array( $field['type'] ?? '', array( 'html', 'heading' ), true ) ) {
				continue;
			}
			$m = minn_admin_jet_map_field( $field );
			if ( ! $m ) {
				$locked++;
				continue;
			}
			$mapped[] = $m;
		}
		if ( $mapped || $locked ) {
			$groups[] = array( 'group' => $label, 'fields' => $mapped, 'locked' => $locked );
		}
	};
	try {
		foreach ( (array) jet_engine()->meta_boxes->data->get_items() as $box ) {
			$args = ( isset( $box['args'] ) && is_array( $box['args'] ) ) ? $box['args'] : array();
			if ( 'post' !== ( $args['object_type'] ?? 'post' ) ) {
				continue;
			}
			$types = isset( $args['allowed_post_type'] ) ? (array) $args['allowed_post_type'] : array();
			if ( ! in_array( $post_type, $types, true ) ) {
				continue;
			}
			$active = isset( $args['active_conditions'] ) ? (array) $args['active_conditions'] : array();
			if ( $post_id ) {
				$allowed  = ( in_array( 'allowed_posts', $active, true ) || ! empty( $args['allowed_posts'] ) ) ? array_map( 'intval', (array) ( $args['allowed_posts'] ?? array() ) ) : array();
				$excluded = ( in_array( 'excluded_posts', $active, true ) || ! empty( $args['excluded_posts'] ) ) ? array_map( 'intval', (array) ( $args['excluded_posts'] ?? array() ) ) : array();
				if ( $allowed && ! in_array( (int) $post_id, $allowed, true ) ) {
					continue;
				}
				if ( $excluded && in_array( (int) $post_id, $excluded, true ) ) {
					continue;
				}
			}
			$push( ! empty( $args['name'] ) ? (string) $args['name'] : __( 'Settings', 'minn-admin' ), $box['meta_fields'] ?? array() );
		}
		if ( is_object( jet_engine()->cpt ) && is_object( jet_engine()->cpt->data ) ) {
			foreach ( (array) jet_engine()->cpt->data->get_items() as $row ) {
				if ( ( $row['slug'] ?? '' ) !== $post_type ) {
					continue;
				}
				$edit = jet_engine()->cpt->data->get_item_for_edit( $row['id'] );
				if ( is_array( $edit ) && ! empty( $edit['meta_fields'] ) ) {
					$obj = get_post_type_object( $post_type );
					/* translators: %s: post type name. */
					$push( sprintf( __( '%s fields', 'minn-admin' ), $obj ? $obj->labels->singular_name : $post_type ), $edit['meta_fields'] );
				}
			}
		}
	} catch ( \Throwable $e ) {
		return $groups;
	}
	return $groups;
}

/** name => mapped field, for one post. */
function minn_admin_jet_field_map( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$map = array();
	foreach ( minn_admin_jet_groups_for( $post->post_type, $post_id ) as $g ) {
		foreach ( $g['fields'] as $f ) {
			$map[ $f['name'] ] = $f;
		}
	}
	return $map;
}

function minn_admin_jet_image_out( $id ) {
	$id = (int) $id;
	if ( $id <= 0 ) {
		return '';
	}
	$url = wp_get_attachment_url( $id );
	return $url ? array( 'id' => $id, 'url' => $url ) : '';
}

/** Stored meta → panel value. */
function minn_admin_jet_value_out( $f, $raw ) {
	$jt = $f['_jet'];
	switch ( $f['type'] ) {
		case 'true_false':
			return minn_admin_jet_bool( $raw );
		case 'number':
			return ( null === $raw || '' === $raw || false === $raw ) ? '' : (string) $raw;
		case 'multicheck':
			if ( is_array( $raw ) ) {
				$on = array();
				foreach ( $raw as $k => $v ) {
					if ( is_int( $k ) && is_scalar( $v ) ) {
						$on[] = (string) $v; // list of keys
					} elseif ( minn_admin_jet_bool( $v ) ) {
						$on[] = (string) $k; // key => 'true' map
					}
				}
				return array_values( array_intersect( $on, array_keys( $f['choices'] ) ) );
			}
			return ( '' !== (string) $raw && isset( $f['choices'][ (string) $raw ] ) ) ? array( (string) $raw ) : array();
		case 'date':
		case 'datetime':
		case 'time':
			if ( null === $raw || '' === $raw || false === $raw ) {
				return '';
			}
			if ( ! empty( $jt['timestamp'] ) && is_numeric( $raw ) ) {
				$ts = (int) $raw;
				return 'date' === $f['type'] ? gmdate( 'Y-m-d', $ts ) : ( 'time' === $f['type'] ? gmdate( 'H:i', $ts ) : gmdate( 'Y-m-d\TH:i', $ts ) );
			}
			return (string) $raw;
		case 'image':
			if ( 'both' === $jt['format'] ) {
				$d = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
				return ( is_array( $d ) && ! empty( $d['id'] ) ) ? minn_admin_jet_image_out( $d['id'] ) : '';
			}
			if ( 'url' === $jt['format'] ) {
				$id = $raw ? attachment_url_to_postid( (string) $raw ) : 0;
				return $id ? minn_admin_jet_image_out( $id ) : '';
			}
			return minn_admin_jet_image_out( $raw );
		case 'gallery':
			$ids = array();
			if ( 'both' === $jt['format'] ) {
				$d = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
				foreach ( is_array( $d ) ? $d : array() as $it ) {
					if ( is_array( $it ) && ! empty( $it['id'] ) ) {
						$ids[] = (int) $it['id'];
					}
				}
			} else {
				$ids = array_map( 'intval', array_filter( explode( ',', (string) $raw ) ) );
			}
			$out = array();
			foreach ( $ids as $id ) {
				$img = minn_admin_jet_image_out( $id );
				if ( $img ) {
					$out[] = $img;
				}
			}
			return $out;
		default:
			return is_scalar( $raw ) ? (string) $raw : '';
	}
}

/**
 * Panel value → the exact stored shape, or null to clear (their save
 * writes false on an empty control; false stores as '').
 */
function minn_admin_jet_value_in( $f, $value ) {
	$jt = $f['_jet'];
	switch ( $f['type'] ) {
		case 'true_false':
			return ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 'true' : 'false';
		case 'number':
			return ( '' === $value || null === $value ) ? null : ( is_numeric( $value ) ? (string) ( $value + 0 ) : null );
		case 'multicheck':
			$on = array_values( array_intersect( array_map( 'strval', (array) $value ), array_keys( $f['choices'] ) ) );
			if ( 'checkbox' === $jt['type'] && empty( $jt['is_array'] ) ) {
				$map = array();
				foreach ( array_keys( $f['choices'] ) as $k ) {
					$map[ $k ] = in_array( (string) $k, $on, true ) ? 'true' : 'false';
				}
				return $map;
			}
			return $on ? $on : null;
		case 'select':
		case 'radio':
			$value = (string) $value;
			return ( '' !== $value && isset( $f['choices'][ $value ] ) ) ? $value : null;
		case 'date':
		case 'datetime':
		case 'time':
			$value = trim( (string) $value );
			if ( '' === $value ) {
				return null;
			}
			if ( ! empty( $jt['timestamp'] ) ) {
				$ts = strtotime( 'time' === $f['type'] ? '1970-01-01 ' . $value . ' UTC' : $value . ' UTC' );
				return $ts ? (string) $ts : null;
			}
			return sanitize_text_field( $value );
		case 'image':
			$id = is_array( $value ) ? (int) ( $value['id'] ?? 0 ) : (int) $value;
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				return null;
			}
			if ( 'url' === $jt['format'] ) {
				return (string) wp_get_attachment_url( $id );
			}
			if ( 'both' === $jt['format'] ) {
				return wp_json_encode( array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) ) );
			}
			return (string) $id;
		case 'gallery':
			$ids = array();
			foreach ( (array) $value as $it ) {
				$id = is_array( $it ) ? (int) ( $it['id'] ?? 0 ) : (int) $it;
				if ( $id > 0 && 'attachment' === get_post_type( $id ) ) {
					$ids[] = $id;
				}
			}
			if ( ! $ids ) {
				return null;
			}
			if ( 'both' === $jt['format'] ) {
				return wp_json_encode( array_map( function ( $id ) {
					return array( 'id' => $id, 'url' => wp_get_attachment_url( $id ) );
				}, $ids ) );
			}
			return implode( ',', $ids );
		case 'wysiwyg':
			$value = (string) $value;
			return '' === trim( $value ) ? null : ( function_exists( 'jet_engine_sanitize_wysiwyg' ) ? jet_engine_sanitize_wysiwyg( $value ) : wp_kses_post( $value ) );
		case 'textarea':
			$value = (string) $value;
			return '' === trim( $value ) ? null : ( function_exists( 'jet_engine_sanitize_textarea' ) ? jet_engine_sanitize_textarea( $value ) : sanitize_textarea_field( $value ) );
		case 'color_picker':
			$value = trim( (string) $value );
			return '' === $value ? null : sanitize_text_field( $value );
		default:
			$value = (string) $value;
			return '' === trim( $value ) ? null : wp_kses_post( $value );
	}
}

function minn_admin_jet_read_values( $post_id ) {
	$out = array();
	foreach ( minn_admin_jet_field_map( $post_id ) as $name => $f ) {
		$out[ $name ] = minn_admin_jet_value_out( $f, get_post_meta( $post_id, $name, true ) );
	}
	return $out;
}

function minn_admin_jet_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$map = minn_admin_jet_field_map( $post_id );
	foreach ( $values as $name => $value ) {
		if ( ! isset( $map[ $name ] ) ) {
			continue; // only the post's own mapped fields
		}
		$stored = minn_admin_jet_value_in( $map[ $name ], $value );
		if ( null === $stored ) {
			// Their empty-control write: update_post_meta( id, key, false ).
			update_post_meta( $post_id, $name, false );
		} else {
			update_post_meta( $post_id, $name, $stored );
		}
	}
}

/** The fieldsRoute payload: groups with the internal _jet hints stripped. */
function minn_admin_jet_fields_payload( $post_type, $post_id ) {
	$groups = minn_admin_jet_groups_for( $post_type, $post_id );
	foreach ( $groups as &$g ) {
		foreach ( $g['fields'] as &$f ) {
			unset( $f['_jet'] );
		}
	}
	return array( 'groups' => $groups );
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_jet_fields_active() ) {
		return $panels;
	}
	$panels['jet-engine'] = array(
		'label'       => __( 'Custom fields', 'minn-admin' ),
		'sub'         => 'JetEngine',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/jet-engine/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_jet',
		'writeKey'    => 'minn_jet',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_fields_active() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/jet-engine/fields', array(
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
			$post_type = minn_admin_jet_resolve_type( $request['post_type'] );
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
			return rest_ensure_response( minn_admin_jet_fields_payload( $post_type, $post_id ) );
		},
	) );

	foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
		register_rest_field( $type, 'minn_jet', array(
			'get_callback'    => function ( $obj ) {
				$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
				if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
					return new stdClass();
				}
				return (object) minn_admin_jet_read_values( $id );
			},
			'update_callback' => function ( $value, $post ) {
				if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return;
				}
				if ( is_object( $value ) ) {
					$value = (array) $value;
				}
				if ( is_array( $value ) ) {
					minn_admin_jet_write_values( $post->ID, $value );
				}
			},
			'schema'          => array(
				'description' => __( 'JetEngine field values for Minn Admin.', 'minn-admin' ),
				'type'        => 'object',
				'context'     => array( 'edit' ),
			),
		) );
	}
} );
