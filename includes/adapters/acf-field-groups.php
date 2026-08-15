<?php
/**
 * Bundled adapter: ACF field-group management (free and Pro).
 *
 * The schema side of the ACF story: a Field Groups surface (list, create,
 * rename, duplicate, activate/deactivate, trash) plus a Fields view with
 * per-group tabs (add, edit, reorder, delete simple fields), all through
 * ACF's own field-group and field APIs — never raw writes into its posts.
 *
 * Boundaries, deliberate: code-registered groups (acf-json / PHP) are listed
 * read-only (their source of truth is the codebase); a field's NAME is never
 * editable (renaming orphans every stored value); complex field types,
 * conditional logic, presentation and multi-rule locations stay in ACF's own
 * editor, one honest link away. Deleting a field or trashing a group never
 * touches stored values, and the copy says so.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Field types the add-field form offers (a subset of the panel's simple set). */
function minn_admin_acf_schema_field_types() {
	return array(
		'text'         => __( 'Text', 'minn-admin' ),
		'textarea'     => __( 'Text area', 'minn-admin' ),
		'number'       => __( 'Number', 'minn-admin' ),
		'email'        => __( 'Email', 'minn-admin' ),
		'url'          => 'URL',
		'select'       => __( 'Select', 'minn-admin' ),
		'radio'        => __( 'Radio', 'minn-admin' ),
		'true_false'   => __( 'True / False', 'minn-admin' ),
		'color_picker' => __( 'Color', 'minn-admin' ),
		'image'        => __( 'Image', 'minn-admin' ),
	);
}

function minn_admin_acf_schema_active() {
	return function_exists( 'acf_get_field_groups' )
		&& function_exists( 'acf_get_fields' )
		&& function_exists( 'acf_update_field_group' )
		&& function_exists( 'acf_update_field' )
		&& function_exists( 'acf_get_setting' );
}

/** ACF's own admin capability (filterable via their settings). */
function minn_admin_acf_schema_cap() {
	return (string) acf_get_setting( 'capability' );
}

/**
 * One human line for a group's location rules ("Post type: Pages · +1 more").
 *
 * @param array $group Field group array.
 * @return string
 */
function minn_admin_acf_schema_location_label( $group ) {
	$rules = isset( $group['location'][0] ) && is_array( $group['location'][0] ) ? $group['location'][0] : array();
	$parts = array();
	foreach ( $rules as $rule ) {
		$param = (string) ( $rule['param'] ?? '' );
		$value = (string) ( $rule['value'] ?? '' );
		if ( 'post_type' === $param ) {
			$obj     = get_post_type_object( $value );
			$parts[] = sprintf( __( 'Post type: %s', 'minn-admin' ), $obj ? $obj->labels->name : $value );
		} elseif ( 'block' === $param ) {
			$bt      = WP_Block_Type_Registry::get_instance()->get_registered( $value );
			$parts[] = sprintf( __( 'Block: %s', 'minn-admin' ), $bt && $bt->title ? $bt->title : $value );
		} elseif ( 'options_page' === $param ) {
			$page    = function_exists( 'acf_get_options_page' ) ? acf_get_options_page( $value ) : null;
			$parts[] = sprintf( __( 'Options page: %s', 'minn-admin' ), $page && ! empty( $page['page_title'] ) ? $page['page_title'] : $value );
		} elseif ( 'taxonomy' === $param ) {
			$tax     = get_taxonomy( $value );
			$parts[] = sprintf( __( 'Taxonomy: %s', 'minn-admin' ), $tax ? $tax->labels->name : $value );
		} else {
			$parts[] = $param . ' = ' . $value;
		}
	}
	$extra = count( (array) $group['location'] ) - 1;
	$label = implode( ' & ', $parts );
	if ( $extra > 0 ) {
		/* translators: %d: number of additional location rule groups. */
		$label .= ' · ' . sprintf( __( '+%d more', 'minn-admin' ), $extra );
	}
	return $label ? $label : __( 'No location rules', 'minn-admin' );
}

/**
 * Groups the manager works with. `source` separates DB groups (editable)
 * from code-registered ones (acf-json / PHP: read-only, code is their truth).
 *
 * @return array[] Raw ACF group arrays, each with minn keys source/db_id.
 */
function minn_admin_acf_schema_groups() {
	$out = array();
	foreach ( (array) acf_get_field_groups() as $g ) {
		$g['minn_source'] = ! empty( $g['ID'] ) ? 'db' : 'code';
		$out[]            = $g;
	}
	return $out;
}

/** Resolve one group by key; null when unknown. */
function minn_admin_acf_schema_group( $key ) {
	foreach ( minn_admin_acf_schema_groups() as $g ) {
		if ( $g['key'] === $key ) {
			return $g;
		}
	}
	return null;
}

/** List-row shape for the Groups collection. */
function minn_admin_acf_schema_group_item( $g ) {
	$fields = (array) acf_get_fields( $g );
	return array(
		'id'       => $g['key'],
		'title'    => (string) $g['title'],
		'key'      => $g['key'],
		'location' => minn_admin_acf_schema_location_label( $g ),
		'fields'   => count( $fields ),
		'status'   => 'code' === $g['minn_source'] ? 'code' : ( ! empty( $g['active'] ) ? 'active' : 'inactive' ),
		'source'   => $g['minn_source'],
	);
}

/** List-row shape for the Fields view (includes the edit-form seed values). */
function minn_admin_acf_schema_field_item( $f, $group ) {
	$choices = '';
	if ( ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
		$lines = array();
		foreach ( $f['choices'] as $value => $label ) {
			$lines[] = (string) $value === (string) $label ? (string) $value : $value . ' : ' . $label;
		}
		$choices = implode( "\n", $lines );
	}
	return array(
		'id'            => $f['key'],
		'label'         => (string) $f['label'],
		'name'          => (string) $f['name'],
		'type'          => (string) $f['type'],
		'group'         => (string) $group['title'],
		'required'      => empty( $f['required'] ) ? __( 'No', 'minn-admin' ) : __( 'Yes', 'minn-admin' ),
		'default_value' => is_scalar( $f['default_value'] ?? '' ) ? (string) ( $f['default_value'] ?? '' ) : '',
		'choices'       => $choices,
		'source'        => $group['minn_source'],
	);
}

/**
 * Parse the "value : Label" one-per-line choices text (ACF's own convention).
 *
 * @param string $text Textarea content.
 * @return array
 */
function minn_admin_acf_schema_parse_choices( $text ) {
	$out = array();
	foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( false !== strpos( $line, ':' ) ) {
			list( $value, $label ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$out[ $value ]         = '' !== $label ? $label : $value;
		} else {
			$out[ $line ] = $line;
		}
	}
	return $out;
}

/** A field plus its owning DB group, or WP_Error (unknown / code-registered). */
function minn_admin_acf_schema_editable_field( $key ) {
	$field = acf_get_field( $key );
	if ( ! $field ) {
		return new WP_Error( 'not_found', __( 'Field not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	// Walk parents (sub-fields have field parents) up to the group.
	$group = function_exists( 'acf_get_field_group' ) ? acf_get_field_group( $field['parent'] ) : null;
	if ( ! $group || empty( $group['ID'] ) ) {
		return new WP_Error( 'read_only', __( 'This field is registered in code — edit it where it is defined.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return array( 'field' => $field, 'group' => $group );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_acf_schema_active() ) {
		return $surfaces;
	}
	// One flat "attach to" choice list for new groups: the common single-rule
	// locations. Multi-rule locations are ACF-editor territory.
	$locations = array();
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
		if ( preg_match( '/^(acf-|wp_|edd_|elementor_)/', $pt->name ) || 'attachment' === $pt->name ) {
			continue;
		}
		$locations[] = array( 'post_type:' . $pt->name, sprintf( __( 'Post type: %s', 'minn-admin' ), $pt->labels->name ) );
	}
	if ( function_exists( 'acf_get_options_pages' ) ) {
		foreach ( (array) acf_get_options_pages() as $page ) {
			if ( empty( $page['menu_slug'] ) ) {
				continue;
			}
			$locations[] = array( 'options_page:' . $page['menu_slug'], sprintf( __( 'Options page: %s', 'minn-admin' ), $page['page_title'] ?: $page['menu_slug'] ) );
		}
	}
	if ( function_exists( 'acf_get_block_types' ) ) {
		foreach ( acf_get_block_types() as $name => $bt ) {
			$locations[] = array( 'block:' . $name, sprintf( __( 'Block: %s', 'minn-admin' ), ! empty( $bt['title'] ) ? $bt['title'] : $name ) );
		}
	}

	$surfaces['acf-field-groups'] = array(
		'label'      => __( 'Field Groups', 'minn-admin' ),
		'sub'        => 'ACF',
		'icon'       => 'grid',
		'cap'        => minn_admin_acf_schema_cap(),
		'collection' => array(
			'viewLabel' => __( 'Groups', 'minn-admin' ),
			// Rows open the group builder page (collection.open — the
			// pages-vs-modals test: a group carries a whole workflow).
			'open'      => array( 'route' => 'field-groups/{id}' ),
			'route'     => 'minn-admin/v1/acf/schema/groups',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'tabs'      => array(
				'param'    => 'status',
				'static'   => array(
					array( 'active', __( 'Active', 'minn-admin' ) ),
					array( 'inactive', __( 'Inactive', 'minn-admin' ) ),
					array( 'code', __( 'In code', 'minn-admin' ) ),
				),
				'allLabel' => __( 'All', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Field group', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'location', 'label' => __( 'Location', 'minn-admin' ), 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'fields', 'label' => __( 'Fields', 'minn-admin' ), 'format' => 'num', 'width' => '70px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
			),
			'detail'    => array( 'skip' => array( 'id', 'source' ) ),
			'create'    => array(
				'label'  => __( 'Add field group', 'minn-admin' ),
				'route'  => 'minn-admin/v1/acf/schema/groups',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'title', 'label' => __( 'Title', 'minn-admin' ) ),
					array( 'key' => 'location', 'label' => __( 'Shown on', 'minn-admin' ), 'type' => 'select', 'options' => $locations ),
				),
			),
			'actions'   => array(
				array(
					'label' => __( 'Deactivate', 'minn-admin' ),
					'route' => 'minn-admin/v1/acf/schema/groups/{id}/deactivate',
					'when'  => array( 'key' => 'status', 'equals' => 'active' ),
				),
				array(
					'label' => __( 'Activate', 'minn-admin' ),
					'route' => 'minn-admin/v1/acf/schema/groups/{id}/activate',
					'when'  => array( 'key' => 'status', 'equals' => 'inactive' ),
				),
				array(
					'label' => __( 'Duplicate', 'minn-admin' ),
					'route' => 'minn-admin/v1/acf/schema/groups/{id}/duplicate',
					'when'  => array( 'key' => 'source', 'equals' => 'db' ),
				),
				array(
					'label'   => __( 'Move to trash', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/acf/schema/groups/{id}',
					'confirm' => __( 'Trash this field group? Its fields stop showing everywhere, but values already saved on posts stay in the database. The group is recoverable from ACF\'s trash.', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'source', 'equals' => 'db' ),
				),
			),
		),
		'views'      => array(
			array(
				'viewLabel' => __( 'Fields', 'minn-admin' ),
				'route'     => 'minn-admin/v1/acf/schema/groups/{tab}/fields',
				'allRoute'  => 'minn-admin/v1/acf/schema/fields',
				'itemsKey'  => 'items',
				'totalKey'  => 'total',
				'tabs'      => array(
					'route'    => 'minn-admin/v1/acf/schema/group-tabs',
					'valueKey' => 'id',
					'labelKey' => 'title',
					'allLabel' => __( 'All groups', 'minn-admin' ),
				),
				'columns'   => array(
					array( 'key' => 'label', 'label' => __( 'Field', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.1fr)' ),
					array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'format' => 'mono' ),
					array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'width' => '110px' ),
					array( 'key' => 'group', 'label' => __( 'Group', 'minn-admin' ) ),
				),
				'detail'    => array(
					'skip' => array( 'id', 'source', 'default_value', 'choices' ),
					'edit' => array(
						'route'  => 'minn-admin/v1/acf/schema/fields/{id}',
						'method' => 'PUT',
						'fields' => array(
							array( 'key' => 'label', 'label' => __( 'Label', 'minn-admin' ) ),
							array( 'key' => 'default_value', 'label' => __( 'Default value', 'minn-admin' ), 'required' => false ),
							array( 'key' => 'choices', 'label' => __( 'Choices (select and radio; one per line, "value : Label")', 'minn-admin' ), 'type' => 'textarea', 'rows' => 4, 'required' => false, 'mono' => true ),
							array( 'key' => 'required', 'label' => __( 'Required', 'minn-admin' ), 'type' => 'select', 'options' => array( array( 'No', __( 'No', 'minn-admin' ) ), array( 'Yes', __( 'Yes', 'minn-admin' ) ) ) ),
						),
					),
				),
				'actions'   => array(
					array(
						'label' => __( 'Move up', 'minn-admin' ),
						'route' => 'minn-admin/v1/acf/schema/fields/{id}/move',
						'body'  => array( 'dir' => 'up' ),
						'when'  => array( 'key' => 'source', 'equals' => 'db' ),
					),
					array(
						'label' => __( 'Move down', 'minn-admin' ),
						'route' => 'minn-admin/v1/acf/schema/fields/{id}/move',
						'body'  => array( 'dir' => 'down' ),
						'when'  => array( 'key' => 'source', 'equals' => 'db' ),
					),
					array(
						'label'   => __( 'Delete field', 'minn-admin' ),
						'method'  => 'DELETE',
						'route'   => 'minn-admin/v1/acf/schema/fields/{id}',
						'confirm' => __( 'Delete this field definition? Values already saved on posts stay in the database.', 'minn-admin' ),
						'danger'  => true,
						'when'    => array( 'key' => 'source', 'equals' => 'db' ),
					),
				),
			),
		),
	);

	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_schema_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( minn_admin_acf_schema_cap() );
	};

	register_rest_route( 'minn-admin/v1', '/acf/schema/groups', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$status = trim( (string) $request['status'] );
				$search = trim( (string) $request['search'] );
				$items  = array();
				foreach ( minn_admin_acf_schema_groups() as $g ) {
					$item = minn_admin_acf_schema_group_item( $g );
					if ( $status && $item['status'] !== $status ) {
						continue;
					}
					if ( $search && false === stripos( $item['title'] . ' ' . $item['key'], $search ) ) {
						continue;
					}
					$items[] = $item;
				}
				return rest_ensure_response( array( 'items' => $items, 'total' => count( $items ) ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$title    = trim( (string) $request['title'] );
				$location = trim( (string) $request['location'] );
				if ( '' === $title ) {
					return new WP_Error( 'invalid', __( 'A title is required.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$sep = strpos( $location, ':' );
				if ( false === $sep ) {
					return new WP_Error( 'invalid', __( 'Pick where the group should show.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$param = sanitize_key( substr( $location, 0, $sep ) );
				$value = substr( $location, $sep + 1 );
				if ( ! in_array( $param, array( 'post_type', 'options_page', 'block', 'taxonomy' ), true ) ) {
					return new WP_Error( 'invalid', __( 'Unsupported location.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$group = acf_update_field_group( array(
					'key'      => uniqid( 'group_' ),
					'title'    => $title,
					'active'   => true,
					'location' => array( array( array( 'param' => $param, 'operator' => '==', 'value' => $value ) ) ),
					'fields'   => array(),
				) );
				if ( ! $group ) {
					return new WP_Error( 'failed', __( 'ACF refused to create the group.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$group['minn_source'] = 'db';
				return rest_ensure_response( minn_admin_acf_schema_group_item( $group ) );
			},
		),
	) );

	// Light tab source for the Fields view.
	register_rest_route( 'minn-admin/v1', '/acf/schema/group-tabs', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$out = array();
			foreach ( minn_admin_acf_schema_groups() as $g ) {
				$out[] = array( 'id' => $g['key'], 'title' => $g['title'] );
			}
			return rest_ensure_response( $out );
		},
	) );

	// Group verbs. Every mutation resolves the group fresh and refuses
	// code-registered ones — the descriptor's `when` gate is cosmetic only.
	$db_group = function ( WP_REST_Request $request ) {
		$group = minn_admin_acf_schema_group( (string) $request['key'] );
		if ( ! $group ) {
			return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		if ( 'db' !== $group['minn_source'] ) {
			return new WP_Error( 'read_only', __( 'This group is registered in code — edit it where it is defined.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		return $group;
	};

	register_rest_route( 'minn-admin/v1', '/acf/schema/groups/(?P<key>[a-zA-Z0-9_\-]+)/(?P<verb>activate|deactivate|rename|duplicate)', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $db_group ) {
			$group = $db_group( $request );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			$verb = (string) $request['verb'];
			if ( 'duplicate' === $verb ) {
				if ( ! function_exists( 'acf_duplicate_field_group' ) ) {
					return new WP_Error( 'unsupported', __( 'This ACF version cannot duplicate groups.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$copy = acf_duplicate_field_group( $group['ID'] );
				return rest_ensure_response( array( 'ok' => true, 'message' => $copy ? sprintf( __( 'Duplicated as “%s”.', 'minn-admin' ), $copy['title'] ) : __( 'Duplicate failed.', 'minn-admin' ) ) );
			}
			if ( 'rename' === $verb ) {
				$title = trim( (string) $request['title'] );
				if ( '' === $title ) {
					return new WP_Error( 'invalid', __( 'A title is required.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$group['title'] = $title;
			} else {
				$group['active'] = 'activate' === $verb;
			}
			acf_update_field_group( $group );
			return rest_ensure_response( array( 'ok' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/acf/schema/groups/(?P<key>[a-zA-Z0-9_\-]+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $db_group ) {
			// force=1 deletes permanently, trashed groups included (a trashed
			// group no longer registers, so resolve its post directly: trash
			// renames post_name to key__trashed). Not exposed in the
			// descriptor; suites use it to clean up what they create.
			if ( $request['force'] && function_exists( 'acf_delete_field_group' ) ) {
				$key   = (string) $request['key'];
				$group = minn_admin_acf_schema_group( $key );
				$pid   = $group && 'db' === $group['minn_source'] ? (int) $group['ID'] : 0;
				if ( ! $pid ) {
					global $wpdb;
					$pid = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'acf-field-group' AND post_name IN (%s, %s) LIMIT 1",
						$key, $key . '__trashed'
					) );
				}
				if ( ! $pid ) {
					return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				acf_delete_field_group( $pid );
				return rest_ensure_response( array( 'ok' => true ) );
			}
			$group = $db_group( $request );
			if ( is_wp_error( $group ) ) {
				return $group;
			}
			if ( function_exists( 'acf_trash_field_group' ) ) {
				acf_trash_field_group( $group['ID'] );
			} else {
				wp_trash_post( $group['ID'] );
			}
			return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Field group moved to ACF\'s trash.', 'minn-admin' ) ) );
		},
	) );

	// Fields of one group ({tab} = group key) and of all groups.
	$field_items = function ( $groups ) {
		$items = array();
		foreach ( $groups as $g ) {
			foreach ( (array) acf_get_fields( $g ) as $f ) {
				$items[] = minn_admin_acf_schema_field_item( $f, $g );
			}
		}
		return $items;
	};
	$create_field = function ( $group, WP_REST_Request $request ) {
		if ( ! $group || 'db' !== $group['minn_source'] ) {
			return new WP_Error( 'invalid', __( 'Pick a field group Minn can edit.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$label = trim( (string) $request['label'] );
		$type  = sanitize_key( (string) $request['type'] );
		if ( '' === $label ) {
			return new WP_Error( 'invalid', __( 'A label is required.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( ! array_key_exists( $type, minn_admin_acf_schema_field_types() ) ) {
			return new WP_Error( 'invalid', __( 'Unsupported field type.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		// ACF's own name convention: the label, lowercased, spaces to
		// underscores. Must be unique within the group.
		$name     = str_replace( '-', '_', sanitize_title( $label ) );
		$existing = wp_list_pluck( (array) acf_get_fields( $group ), 'name' );
		if ( in_array( $name, $existing, true ) ) {
			return new WP_Error( 'invalid', __( 'A field with that name already exists in the group.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$field = array(
			'key'        => uniqid( 'field_' ),
			'label'      => $label,
			'name'       => $name,
			'type'       => $type,
			'parent'     => $group['ID'],
			'menu_order' => count( $existing ),
		);
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$field['choices'] = minn_admin_acf_schema_parse_choices( (string) $request['choices'] );
			if ( ! $field['choices'] ) {
				return new WP_Error( 'invalid', __( 'Select and radio fields need at least one choice.', 'minn-admin' ), array( 'status' => 400 ) );
			}
		}
		if ( 'image' === $type ) {
			$field['return_format'] = 'id';
		}
		$saved = acf_update_field( $field );
		if ( ! $saved ) {
			return new WP_Error( 'failed', __( 'ACF refused to create the field.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( minn_admin_acf_schema_field_item( $saved, $group ) );
	};

	register_rest_route( 'minn-admin/v1', '/acf/schema/groups/(?P<key>[a-zA-Z0-9_\-]+)/fields', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $field_items ) {
				$group = minn_admin_acf_schema_group( (string) $request['key'] );
				if ( ! $group ) {
					return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$items = $field_items( array( $group ) );
				return rest_ensure_response( array( 'items' => $items, 'total' => count( $items ) ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $create_field ) {
				return $create_field( minn_admin_acf_schema_group( (string) $request['key'] ), $request );
			},
		),
	) );
	register_rest_route( 'minn-admin/v1', '/acf/schema/fields', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () use ( $field_items ) {
			$items = $field_items( minn_admin_acf_schema_groups() );
			return rest_ensure_response( array( 'items' => $items, 'total' => count( $items ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/acf/schema/fields/(?P<key>[a-zA-Z0-9_\-]+)', array(
		array(
			'methods'             => 'PUT',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$found = minn_admin_acf_schema_editable_field( (string) $request['key'] );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				$field          = $found['field'];
				$field['label'] = trim( (string) $request['label'] ) ?: $field['label'];
				if ( null !== $request['default_value'] ) {
					$field['default_value'] = (string) $request['default_value'];
				}
				if ( null !== $request['required'] ) {
					$field['required'] = 'Yes' === (string) $request['required'] ? 1 : 0;
				}
				// Choices only mean something on choice fields; an edit form
				// shared across types must not bolt them onto a text field.
				if ( null !== $request['choices'] && in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
					$choices = minn_admin_acf_schema_parse_choices( (string) $request['choices'] );
					if ( ! $choices ) {
						return new WP_Error( 'invalid', __( 'Select and radio fields need at least one choice.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$field['choices'] = $choices;
				}
				acf_update_field( $field );
				return rest_ensure_response( minn_admin_acf_schema_field_item( acf_get_field( $field['key'] ), $found['group'] + array( 'minn_source' => 'db' ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$found = minn_admin_acf_schema_editable_field( (string) $request['key'] );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				acf_delete_field( $found['field']['ID'] );
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Field deleted. Stored values remain in the database.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/acf/schema/fields/(?P<key>[a-zA-Z0-9_\-]+)/move', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$found = minn_admin_acf_schema_editable_field( (string) $request['key'] );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$dir    = 'up' === (string) $request['dir'] ? -1 : 1;
			$fields = array_values( (array) acf_get_fields( $found['group'] ) );
			$idx    = null;
			foreach ( $fields as $i => $f ) {
				if ( $f['key'] === $found['field']['key'] ) {
					$idx = $i;
					break;
				}
			}
			$j = null === $idx ? null : $idx + $dir;
			if ( null === $j || $j < 0 || $j >= count( $fields ) ) {
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Already at the edge.', 'minn-admin' ) ) );
			}
			// Swap positions, then persist EVERY field's menu_order: rows
			// created at different times can share order values, and a bare
			// two-row swap leaves such ties unresolved.
			$tmp           = $fields[ $idx ];
			$fields[ $idx ] = $fields[ $j ];
			$fields[ $j ]   = $tmp;
			foreach ( $fields as $i => $f ) {
				if ( (int) $f['menu_order'] !== $i ) {
					$f['menu_order'] = $i;
					acf_update_field( $f );
				}
			}
			return rest_ensure_response( array( 'ok' => true ) );
		},
	) );
} );

/* ===== The group builder: whole-group read and transactional save ===== */

/**
 * Field types the builder edits in full, with the settings each carries.
 * Everything else still lists in the builder (reorder, relabel, delete) but
 * keeps its configuration in ACF's own editor.
 *
 * @return array type => settings keys the save path may write.
 */
function minn_admin_acf_builder_types() {
	$base = array( 'label', 'instructions', 'required', 'default_value' );
	return array(
		'text'         => array_merge( $base, array( 'placeholder' ) ),
		'textarea'     => array_merge( $base, array( 'placeholder', 'rows' ) ),
		'number'       => array_merge( $base, array( 'placeholder', 'min', 'max', 'step' ) ),
		'range'        => array_merge( $base, array( 'min', 'max', 'step' ) ),
		'email'        => array_merge( $base, array( 'placeholder' ) ),
		'url'          => array_merge( $base, array( 'placeholder' ) ),
		'select'       => array_merge( $base, array( 'choices' ) ),
		'radio'        => array_merge( $base, array( 'choices' ) ),
		'true_false'   => array( 'label', 'instructions', 'required', 'default_value', 'ui_on_text', 'ui_off_text' ),
		'color_picker' => $base,
		'image'        => array( 'label', 'instructions', 'required' ),
		'gallery'      => array( 'label', 'instructions', 'required' ),
		'wysiwyg'      => array( 'label', 'instructions', 'required' ),
	);
}

/**
 * One field's full builder shape. Choices serialize to the "value : Label"
 * lines convention; unsupported types carry editable: false and only their
 * identity survives a save (label, order, existence).
 *
 * @param array $f ACF field array.
 * @return array
 */
function minn_admin_acf_builder_field( $f ) {
	$types   = minn_admin_acf_builder_types();
	$type    = (string) ( $f['type'] ?? '' );
	$edit    = array_key_exists( $type, $types );
	$choices = '';
	if ( ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
		$lines = array();
		foreach ( $f['choices'] as $value => $label ) {
			$lines[] = (string) $value === (string) $label ? (string) $value : $value . ' : ' . $label;
		}
		$choices = implode( "\n", $lines );
	}
	return array(
		'key'           => (string) $f['key'],
		'label'         => (string) $f['label'],
		'name'          => (string) $f['name'],
		'type'          => $type,
		'editable'      => $edit,
		'required'      => ! empty( $f['required'] ),
		'instructions'  => (string) ( $f['instructions'] ?? '' ),
		'default_value' => is_scalar( $f['default_value'] ?? '' ) ? (string) ( $f['default_value'] ?? '' ) : '',
		'placeholder'   => (string) ( $f['placeholder'] ?? '' ),
		'choices'       => $choices,
		'min'           => isset( $f['min'] ) && '' !== $f['min'] ? (string) $f['min'] : '',
		'max'           => isset( $f['max'] ) && '' !== $f['max'] ? (string) $f['max'] : '',
		'step'          => isset( $f['step'] ) && '' !== $f['step'] ? (string) $f['step'] : '',
		'rows'          => isset( $f['rows'] ) && '' !== $f['rows'] ? (string) $f['rows'] : '',
		'ui_on_text'    => (string) ( $f['ui_on_text'] ?? '' ),
		'ui_off_text'   => (string) ( $f['ui_off_text'] ?? '' ),
		'subCount'      => 'repeater' === $type ? count( (array) ( $f['sub_fields'] ?? array() ) ) : 0,
	);
}

/**
 * The whole-group payload the builder reads and the save returns.
 *
 * @param array $group ACF group array (with minn_source).
 * @return array
 */
function minn_admin_acf_builder_payload( $group ) {
	$fields = array();
	foreach ( (array) acf_get_fields( $group ) as $f ) {
		$fields[] = minn_admin_acf_builder_field( $f );
	}
	return array(
		'group'  => array(
			'key'           => $group['key'],
			'title'         => (string) $group['title'],
			'active'        => ! empty( $group['active'] ),
			'source'        => $group['minn_source'],
			'location'      => (array) $group['location'],
			'locationLabel' => minn_admin_acf_schema_location_label( $group ),
			'adminUrl'      => ! empty( $group['ID'] ) ? admin_url( 'post.php?post=' . (int) $group['ID'] . '&action=edit' ) : '',
		),
		'fields' => $fields,
		'types'  => array_keys( minn_admin_acf_builder_types() ),
	);
}

/**
 * Save the whole group as one unit: title, active, ordered field list.
 * Existing fields overlay ONLY the settings their type declares (name and
 * type immutable); new fields (no key) are created in place; stored fields
 * absent from the payload are deleted. Validation happens before any write.
 *
 * @param array $group ACF group array (db source, verified by the caller).
 * @param array $body  Request body.
 * @return array|WP_Error Fresh payload or refusal.
 */
function minn_admin_acf_builder_save( $group, $body ) {
	$types    = minn_admin_acf_builder_types();
	$existing = array();
	foreach ( (array) acf_get_fields( $group ) as $f ) {
		$existing[ $f['key'] ] = $f;
	}
	$title = isset( $body['title'] ) ? trim( (string) $body['title'] ) : (string) $group['title'];
	if ( '' === $title ) {
		return new WP_Error( 'invalid', __( 'A title is required.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$rows = isset( $body['fields'] ) && is_array( $body['fields'] ) ? array_values( $body['fields'] ) : null;
	if ( null === $rows ) {
		return new WP_Error( 'invalid', __( 'Missing fields list.', 'minn-admin' ), array( 'status' => 400 ) );
	}

	// ---- Validate every row before writing anything. ----
	$names = array();
	$plans = array();
	foreach ( $rows as $i => $row ) {
		$row = (array) $row;
		$key = isset( $row['key'] ) ? (string) $row['key'] : '';
		if ( $key && isset( $existing[ $key ] ) ) {
			$cur  = $existing[ $key ];
			$name = (string) $cur['name'];
			$type = (string) $cur['type'];
		} else {
			$type = sanitize_key( (string) ( $row['type'] ?? '' ) );
			if ( ! array_key_exists( $type, $types ) ) {
				return new WP_Error( 'invalid', __( 'New fields need one of the supported types.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$label = trim( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label ) {
				return new WP_Error( 'invalid', __( 'Every field needs a label.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$name = str_replace( '-', '_', sanitize_title( trim( (string) ( $row['name'] ?? '' ) ) ?: $label ) );
			if ( '' === $name ) {
				return new WP_Error( 'invalid', __( 'Every field needs a name.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$cur = null;
			$key = '';
		}
		if ( isset( $names[ $name ] ) ) {
			/* translators: %s: the duplicated field name. */
			return new WP_Error( 'invalid', sprintf( __( 'Two fields share the name “%s”.', 'minn-admin' ), $name ), array( 'status' => 400 ) );
		}
		$names[ $name ] = true;
		if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
			$choices = minn_admin_acf_schema_parse_choices( (string) ( $row['choices'] ?? '' ) );
			if ( ! $choices ) {
				return new WP_Error( 'invalid', __( 'Select and radio fields need at least one choice.', 'minn-admin' ), array( 'status' => 400 ) );
			}
		}
		$plans[] = array( 'row' => $row, 'key' => $key, 'cur' => $cur, 'type' => $type, 'name' => $name, 'order' => $i );
	}

	// ---- Group settings. ----
	$group['title'] = $title;
	if ( array_key_exists( 'active', $body ) ) {
		$group['active'] = ! empty( $body['active'] );
	}
	acf_update_field_group( $group );

	// ---- Fields: update / create in payload order, then delete the absent. ----
	$kept = array();
	foreach ( $plans as $plan ) {
		$row      = $plan['row'];
		$type     = $plan['type'];
		$settings = isset( $types[ $type ] ) ? $types[ $type ] : array( 'label' );
		if ( $plan['cur'] ) {
			$field = $plan['cur'];
			// Unsupported types keep everything but their label and order.
			if ( ! isset( $types[ $type ] ) ) {
				$settings = array( 'label' );
			}
		} else {
			$field = array(
				'key'    => uniqid( 'field_' ),
				'name'   => $plan['name'],
				'type'   => $type,
				'parent' => $group['ID'],
			);
			if ( 'image' === $type ) {
				$field['return_format'] = 'id';
			}
		}
		foreach ( $settings as $setting ) {
			if ( ! array_key_exists( $setting, $row ) ) {
				continue;
			}
			$v = $row[ $setting ];
			if ( 'required' === $setting ) {
				$field['required'] = ! empty( $v ) && 'false' !== $v ? 1 : 0;
			} elseif ( 'choices' === $setting ) {
				$field['choices'] = minn_admin_acf_schema_parse_choices( (string) $v );
			} elseif ( in_array( $setting, array( 'min', 'max', 'step', 'rows' ), true ) ) {
				$field[ $setting ] = '' === trim( (string) $v ) ? '' : (float) $v;
			} elseif ( 'label' === $setting ) {
				$field['label'] = trim( (string) $v ) ?: $field['label'];
			} else {
				$field[ $setting ] = is_scalar( $v ) ? (string) $v : '';
			}
		}
		$field['menu_order'] = $plan['order'];
		$saved = acf_update_field( $field );
		if ( $saved ) {
			$kept[ $saved['key'] ] = true;
		}
	}
	foreach ( $existing as $key => $f ) {
		if ( ! isset( $kept[ $key ] ) ) {
			acf_delete_field( $f['ID'] );
		}
	}
	$fresh = minn_admin_acf_schema_group( $group['key'] );
	return minn_admin_acf_builder_payload( $fresh ? $fresh : $group );
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_schema_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( minn_admin_acf_schema_cap() );
	};
	register_rest_route( 'minn-admin/v1', '/acf/schema/groups/(?P<key>[a-zA-Z0-9_\-]+)/full', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$group = minn_admin_acf_schema_group( (string) $request['key'] );
				if ( ! $group ) {
					return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_acf_builder_payload( $group ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$group = minn_admin_acf_schema_group( (string) $request['key'] );
				if ( ! $group ) {
					return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( 'db' !== $group['minn_source'] ) {
					return new WP_Error( 'read_only', __( 'This group is registered in code — edit it where it is defined.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				return rest_ensure_response( minn_admin_acf_builder_save( $group, (array) $request->get_json_params() ) );
			},
		),
	) );
} );
