<?php
/**
 * Bundled adapter: Meta Box (wp.org free).
 *
 * Sibling of the ACF editor-panel adapter. Meta Box free does not expose a
 * first-class REST object like ACF's `acf` field, so this adapter declares a
 * fields-tier panel (minn_admin_fields(), includes/kernel/class-minn-admin-kernel-panels.php):
 * the simple fields of the boxes that apply to the post, read and written
 * through rwmb_get_value / rwmb_set_meta under a `minn_meta_box` REST field on
 * every show_in_rest post type.
 *
 * Complex field types (clones, media, post/user pickers, maps…) count as
 * locked and link out to wp-admin, same philosophy as ACF.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Meta Box field types Minn can render as panel inputs, as the tier's types. */
const MINN_ADMIN_META_BOX_SIMPLE = array(
	'text'     => 'text',
	'textarea' => 'textarea',
	'number'   => 'number',
	'range'    => 'number',
	'email'    => 'email',
	'url'      => 'url',
	'select'   => 'select',
	'radio'    => 'select',
	'checkbox' => 'checkbox',
	'switch'   => 'checkbox',
);

/**
 * @return bool
 */
function minn_admin_meta_box_active() {
	return function_exists( 'rwmb_get_registry' ) && function_exists( 'rwmb_get_value' ) && function_exists( 'rwmb_set_meta' );
}

/**
 * Map one Meta Box field onto the panel vocabulary, or null if locked.
 *
 * @param array $field Normalized Meta Box field.
 * @return array|null A fields-tier field.
 */
function minn_admin_meta_box_map_field( $field ) {
	if ( empty( $field['id'] ) || empty( $field['type'] ) ) {
		return null;
	}
	// Cloneable / multi-value fields are not safe as single panel inputs.
	if ( ! empty( $field['clone'] ) || ! empty( $field['multiple'] ) ) {
		return null;
	}
	// save_field => false: display-only or code-managed. Meta Box's own save
	// skips it, so Minn neither offers it nor clears its stored value.
	if ( isset( $field['save_field'] ) && ! $field['save_field'] ) {
		return null;
	}
	// Readonly or disabled (on the field or in its HTML attributes): Meta Box
	// renders the input locked, so its own screen never lets the value change.
	// Offering it here as editable would. Locked, like ACF's readonly fields.
	$attrs = isset( $field['attributes'] ) && is_array( $field['attributes'] ) ? $field['attributes'] : array();
	foreach ( array( 'readonly', 'disabled' ) as $flag ) {
		if ( ! empty( $field[ $flag ] ) || ! empty( $attrs[ $flag ] ) ) {
			return null;
		}
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
	if ( in_array( $type, array( 'range', 'radio' ), true ) ) {
		// Drawn as Meta Box names them (the panel reads range as a number
		// box and radio as a select).
		$mapped['input'] = $type;
	}
	if ( 'textarea' === $type ) {
		// Meta Box keeps post HTML in a textarea (wp_kses_post).
		$mapped['html'] = true;
	}
	if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
		// Meta Box options are value => label; panel formNormField accepts choices object.
		$mapped['choices'] = $field['options'];
	}
	foreach ( array( 'min', 'max' ) as $bound ) {
		if ( isset( $field[ $bound ] ) && is_numeric( $field[ $bound ] ) ) {
			$mapped[ $bound ] = $field[ $bound ];
		}
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
 * The panel's groups: one per box that applies to the post.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array[]
 */
function minn_admin_meta_box_groups( $post_id, $post_type ) {
	$groups = array();
	foreach ( minn_admin_meta_box_boxes_for( $post_type, $post_id ) as $mb ) {
		$mapped = array();
		$locked = 0;
		// RW_Meta_Box exposes fields via __get — isset( $mb->fields ) is false.
		foreach ( (array) $mb->fields as $field ) {
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
	return $groups;
}

/**
 * Read all simple Meta Box values for a post as { field_id => value }.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function minn_admin_meta_box_read_values( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$out = array();
	foreach ( minn_admin_meta_box_boxes_for( $post->post_type, $post_id ) as $mb ) {
		foreach ( (array) $mb->fields as $field ) {
			$simple = minn_admin_meta_box_map_field( $field );
			if ( ! $simple || array_key_exists( $simple['name'], $out ) ) {
				continue;
			}
			$val = rwmb_get_value( $simple['name'], array(), $post_id );
			if ( 'checkbox' === $simple['type'] ) {
				// Checkbox/switch store 0/1 or empty.
				$out[ $simple['name'] ] = ! empty( $val );
			} else {
				// Arrays shouldn't appear for simple non-multiple fields.
				$out[ $simple['name'] ] = is_array( $val ) ? '' : $val;
			}
		}
	}
	return $out;
}

/**
 * Write cleaned values through Meta Box's own setter.
 *
 * @param int   $post_id Post ID.
 * @param array $changes Field id => cleaned value, null to clear.
 * @param array $fields  The offered fields, by id.
 */
function minn_admin_meta_box_store( $post_id, $changes, $fields ) {
	foreach ( $changes as $key => $value ) {
		if ( null === $value ) {
			// Delete the meta so the field is truly empty.
			if ( function_exists( 'rwmb_delete_meta' ) ) {
				rwmb_delete_meta( $post_id, $key );
			} else {
				delete_post_meta( $post_id, $key );
			}
			continue;
		}
		if ( 'checkbox' === $fields[ $key ]['type'] ) {
			// Meta Box checkbox/switch expect 1 or 0.
			$value = $value ? 1 : 0;
		}
		// Slashed: Meta Box's own form feeds this pipeline the slashed $_POST
		// and its storage write (update_metadata()) unslashes, so a raw REST
		// value lost a level of backslashes (^\d+$ saved as ^d+$).
		rwmb_set_meta( $post_id, $key, wp_slash( $value ) );
	}
	return null;
}

minn_admin_fields( 'meta-box', function () {
	return array(
		'active'      => 'minn_admin_meta_box_active',
		'label'       => __( 'Custom fields', 'minn-admin' ),
		'sub'         => 'Meta Box',
		'key'         => 'minn_meta_box',
		'types'       => function () {
			return get_post_types( array( 'show_in_rest' => true ), 'names' );
		},
		'groups'      => 'minn_admin_meta_box_groups',
		'read'        => 'minn_admin_meta_box_read_values',
		'write'       => 'minn_admin_meta_box_store',
		'description' => __( 'Meta Box simple field values for Minn Admin.', 'minn-admin' ),
	);
} );
