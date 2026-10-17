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

/**
 * ACF's own admin capability (filterable via their settings), and nobody's
 * while show_admin is off: developers turn it off to keep field groups away
 * from client administrators, and acf_current_user_can_admin() asks both.
 */
function minn_admin_acf_schema_cap() {
	if ( ! acf_get_setting( 'show_admin' ) ) {
		return 'do_not_allow';
	}
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
		$disp  = $value;
		$name  = $param;
		if ( 'post_type' === $param ) {
			$obj  = get_post_type_object( $value );
			$name = __( 'Post type', 'minn-admin' );
			$disp = $obj ? $obj->labels->name : $value;
		} elseif ( 'block' === $param ) {
			$bt   = WP_Block_Type_Registry::get_instance()->get_registered( $value );
			$name = __( 'Block', 'minn-admin' );
			$disp = $bt && $bt->title ? $bt->title : $value;
		} elseif ( 'options_page' === $param ) {
			$page = function_exists( 'acf_get_options_page' ) ? acf_get_options_page( $value ) : null;
			$name = __( 'Options page', 'minn-admin' );
			$disp = $page && ! empty( $page['page_title'] ) ? $page['page_title'] : $value;
		} elseif ( 'taxonomy' === $param ) {
			$tax  = get_taxonomy( $value );
			$name = __( 'Taxonomy', 'minn-admin' );
			$disp = $tax ? $tax->labels->name : $value;
		} elseif ( 'current_user_role' === $param ) {
			$name = __( 'User role', 'minn-admin' );
		} elseif ( 'page_template' === $param || 'post_template' === $param ) {
			$name = __( 'Template', 'minn-admin' );
		} elseif ( 'post_status' === $param ) {
			$name = __( 'Status', 'minn-admin' );
		}
		if ( '!=' === ( $rule['operator'] ?? '==' ) ) {
			/* translators: %s: the value a location rule excludes. */
			$disp = sprintf( __( 'not %s', 'minn-admin' ), $disp );
		}
		$parts[] = $name . ': ' . $disp;
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

/**
 * Write a group through ACF's own updater without Minn's own key. ACF stores
 * every key of the array it is given in the group's settings, so writing
 * the array minn_admin_acf_schema_groups() returns stored `minn_source`
 * into the group on every save, and its exports carried it.
 *
 * @param array $group ACF group array (may carry minn_source).
 * @return array|false What acf_update_field_group() returns.
 */
function minn_admin_acf_schema_group_store( $group ) {
	unset( $group['minn_source'] );
	// Slashed for the unslash inside; see the create route.
	return acf_update_field_group( wp_slash( $group ) );
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

/**
 * A field's choices as the edit forms show them: "value : Label" per line,
 * the value alone when the two match (ACF's own convention). A save
 * compares the text it is sent with this text for the stored choices, so
 * choices nobody touched are never parsed back: a label holding " : " or a
 * line break does not survive the round trip.
 *
 * @param mixed $choices Stored choices.
 * @return string
 */
function minn_admin_acf_schema_choices_text( $choices ) {
	if ( empty( $choices ) || ! is_array( $choices ) ) {
		return '';
	}
	$lines = array();
	foreach ( $choices as $value => $label ) {
		$lines[] = (string) $value === (string) $label ? (string) $value : $value . ' : ' . $label;
	}
	return implode( "\n", $lines );
}

/** List-row shape for the Fields view (includes the edit-form seed values). */
function minn_admin_acf_schema_field_item( $f, $group ) {
	$choices = minn_admin_acf_schema_choices_text( $f['choices'] ?? null );
	return array(
		'id'            => $f['key'],
		'label'         => (string) $f['label'],
		'name'          => (string) $f['name'],
		'type'          => (string) $f['type'],
		'group'         => (string) $group['title'],
		// The option VALUE, never its label: the edit select offers the
		// literal 'Yes'/'No' (labels translated) and the PUT compares against
		// 'Yes'. A translated seed ('Ja') matched no option, so on a non-English
		// site every edit cleared the required flag.
		'required'      => empty( $f['required'] ) ? 'No' : 'Yes',
		'default_value' => minn_admin_acf_schema_default_text( $f ),
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
	// ACF's own decoder, so text Minn wrote from a field's choices comes back
	// as those choices: an empty value (' : None') and an empty label
	// ('x : ') included.
	if ( function_exists( 'acf_decode_choices' ) ) {
		$decoded = acf_decode_choices( (string) $text );
		return is_array( $decoded ) ? $decoded : array();
	}
	$out = array();
	foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		// The same rule: split on ' : ' (spaces included) before trimming,
		// so a value with a colon of its own (16:9, a URL) survives and an
		// empty value or label stays empty.
		if ( false !== strpos( $line, ' : ' ) ) {
			$parts                   = explode( ' : ', $line );
			$out[ trim( $parts[0] ) ] = trim( $parts[1] );
		} else {
			$out[ trim( $line ) ] = trim( $line );
		}
	}
	return $out;
}

/**
 * A field's default value as edit-form text. Checkbox and multiple-select
 * defaults are arrays; they ride one per line, the shape ACF's own setting
 * takes and its update_field decodes back into an array, instead of going
 * out as '' and clearing the default on the next save.
 *
 * @param array $f ACF field array.
 * @return string
 */
function minn_admin_acf_schema_default_text( $f ) {
	$d = $f['default_value'] ?? '';
	if ( is_array( $d ) ) {
		return implode( "\n", array_map( 'strval', array_filter( $d, 'is_scalar' ) ) );
	}
	return is_scalar( $d ) ? (string) $d : '';
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

// Field groups share one item across plugins; see adapters/field-groups.php.
add_filter( 'minn_admin_field_group_sources', function ( $sources ) {
	if ( ! minn_admin_acf_schema_active() ) {
		return $sources;
	}
	// One flat "attach to" choice list for new groups: the common single-rule
	// locations. Multi-rule locations are ACF-editor territory.
	$locations = array();
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
		if ( preg_match( '/^(acf-|wp_|edd_|elementor_)/', $pt->name ) || 'attachment' === $pt->name ) {
			continue;
		}
		/* translators: %s: post type name, e.g. Posts. */
		$locations[] = array( 'post_type:' . $pt->name, sprintf( __( 'Post type: %s', 'minn-admin' ), $pt->labels->name ) );
	}
	if ( function_exists( 'acf_get_options_pages' ) ) {
		foreach ( (array) acf_get_options_pages() as $page ) {
			if ( empty( $page['menu_slug'] ) ) {
				continue;
			}
			/* translators: %s: ACF options page title. */
			$locations[] = array( 'options_page:' . $page['menu_slug'], sprintf( __( 'Options page: %s', 'minn-admin' ), $page['page_title'] ?: $page['menu_slug'] ) );
		}
	}
	if ( function_exists( 'acf_get_block_types' ) ) {
		foreach ( acf_get_block_types() as $name => $bt ) {
			/* translators: %s: ACF block title. */
			$locations[] = array( 'block:' . $name, sprintf( __( 'Block: %s', 'minn-admin' ), ! empty( $bt['title'] ) ? $bt['title'] : $name ) );
		}
	}

	$sources[] = array(
		'id'         => 'acf',
		'label'      => 'ACF',
		'plugin'     => array( 'advanced-custom-fields-pro', 'advanced-custom-fields', 'secure-custom-fields' ),
		'cap'        => minn_admin_acf_schema_cap(),
		'collection' => array(
			'viewLabel' => 'ACF',
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
			'import'    => array(
				'label'  => __( 'Import', 'minn-admin' ),
				'route'  => 'minn-admin/v1/acf/schema/import',
				'accept' => '.json,application/json',
				'hint'   => __( 'Field group JSON exported from ACF or from Minn. Groups that already exist here are updated in place; post types and taxonomies stay in ACF\'s own tools.', 'minn-admin' ),
			),
			'actions'   => array(
				array(
					'label'    => __( 'Export JSON', 'minn-admin' ),
					'route'    => 'minn-admin/v1/acf/schema/groups/{id}/export',
					'download' => true,
				),
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
							// A textarea: checkbox and multiple-select defaults ride one
							// value per line, which a single-line input would collapse.
							array( 'key' => 'default_value', 'label' => __( 'Default value', 'minn-admin' ), 'type' => 'textarea', 'rows' => 2, 'required' => false ),
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

	return $sources;
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
				// acf_update_field_group() and acf_update_field() unslash what
				// they are given (ACF's editor hands them the slashed $_POST),
				// so every write here slashes first or a backslash is lost.
				$group = acf_update_field_group( wp_slash( array(
					'key'      => uniqid( 'group_' ),
					'title'    => $title,
					'active'   => true,
					'location' => array( array( array( 'param' => $param, 'operator' => '==', 'value' => $value ) ) ),
					'fields'   => array(),
				) ) );
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
		$group = minn_admin_acf_schema_group( Minn_Admin::path_param( $request, 'key' ) );
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
			$verb = (string) Minn_Admin::path_param( $request, 'verb' );
			if ( 'duplicate' === $verb ) {
				if ( ! function_exists( 'acf_duplicate_field_group' ) ) {
					return new WP_Error( 'unsupported', __( 'This ACF version cannot duplicate groups.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$copy = acf_duplicate_field_group( $group['ID'] );
				/* translators: %s: title of the newly created copy. */
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
			minn_admin_acf_schema_group_store( $group );
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
				$key   = (string) Minn_Admin::path_param( $request, 'key' );
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
		$saved = acf_update_field( wp_slash( $field ) );
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
				$group = minn_admin_acf_schema_group( (string) Minn_Admin::path_param( $request, 'key' ) );
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
				return $create_field( minn_admin_acf_schema_group( (string) Minn_Admin::path_param( $request, 'key' ) ), $request );
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
				$found = minn_admin_acf_schema_editable_field( (string) Minn_Admin::path_param( $request, 'key' ) );
				if ( is_wp_error( $found ) ) {
					return $found;
				}
				$field = $found['field'];
				// The dialog sends every field, an untouched one as the row
				// serves it; that one stays as stored. Parsed back from its
				// text, a choice label holding " : " lost its tail.
				$served = minn_admin_acf_schema_field_item( $field, $found['group'] + array( 'minn_source' => 'db' ) );
				if ( null !== $request['label'] && (string) $request['label'] !== $served['label'] ) {
					$field['label'] = trim( (string) $request['label'] ) ?: $field['label'];
				}
				if ( null !== $request['default_value'] && (string) $request['default_value'] !== $served['default_value'] ) {
					$field['default_value'] = (string) $request['default_value'];
				}
				if ( null !== $request['required'] && (string) $request['required'] !== $served['required'] ) {
					$field['required'] = 'Yes' === (string) $request['required'] ? 1 : 0;
				}
				// Choices only mean something on choice fields; an edit form
				// shared across types must not bolt them onto a text field.
				if ( null !== $request['choices'] && (string) $request['choices'] !== $served['choices'] && in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
					$choices = minn_admin_acf_schema_parse_choices( (string) $request['choices'] );
					if ( ! $choices ) {
						return new WP_Error( 'invalid', __( 'Select and radio fields need at least one choice.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$field['choices'] = $choices;
				}
				acf_update_field( wp_slash( $field ) );
				return rest_ensure_response( minn_admin_acf_schema_field_item( acf_get_field( $field['key'] ), $found['group'] + array( 'minn_source' => 'db' ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$found = minn_admin_acf_schema_editable_field( (string) Minn_Admin::path_param( $request, 'key' ) );
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
			$found = minn_admin_acf_schema_editable_field( (string) Minn_Admin::path_param( $request, 'key' ) );
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
					// ACF's own reorder: writes the order and nothing else.
					acf_update_field( wp_slash( $f ), array( 'menu_order' ) );
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
	$base  = array( 'label', 'instructions', 'required', 'default_value' );
	$types = array(
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
	// Repeater is a Pro type: only offer it where ACF registers it (free
	// ACF would store a field it cannot render).
	if ( function_exists( 'acf_get_field_type' ) && acf_get_field_type( 'repeater' ) ) {
		$types['repeater'] = array( 'label', 'instructions', 'required', 'min', 'max', 'button_label' );
	}
	return $types;
}

/**
 * One field's full builder shape. Choices serialize to the "value : Label"
 * lines convention; unsupported types carry editable: false and only their
 * identity survives a save (label, order, existence).
 *
 * @param array $f     ACF field array.
 * @param int   $depth 0 for top-level fields, 1 inside a repeater.
 * @return array
 */
function minn_admin_acf_builder_field( $f, $depth = 0 ) {
	$types = minn_admin_acf_builder_types();
	$type  = (string) ( $f['type'] ?? '' );
	$edit  = array_key_exists( $type, $types );
	// Repeaters nest one level: a repeater inside a repeater still lists
	// (label, order, existence) but configures in ACF's own editor.
	if ( 'repeater' === $type && $depth > 0 ) {
		$edit = false;
	}
	$choices = minn_admin_acf_schema_choices_text( $f['choices'] ?? null );
	$out = array(
		'key'           => (string) $f['key'],
		'label'         => (string) $f['label'],
		'name'          => (string) $f['name'],
		'type'          => $type,
		'editable'      => $edit,
		'required'      => ! empty( $f['required'] ),
		'instructions'  => (string) ( $f['instructions'] ?? '' ),
		'default_value' => minn_admin_acf_schema_default_text( $f ),
		'placeholder'   => (string) ( $f['placeholder'] ?? '' ),
		'choices'       => $choices,
		'min'           => isset( $f['min'] ) && '' !== $f['min'] ? (string) $f['min'] : '',
		'max'           => isset( $f['max'] ) && '' !== $f['max'] ? (string) $f['max'] : '',
		'step'          => isset( $f['step'] ) && '' !== $f['step'] ? (string) $f['step'] : '',
		'rows'          => isset( $f['rows'] ) && '' !== $f['rows'] ? (string) $f['rows'] : '',
		'ui_on_text'    => (string) ( $f['ui_on_text'] ?? '' ),
		'ui_off_text'   => (string) ( $f['ui_off_text'] ?? '' ),
		'button_label'  => (string) ( $f['button_label'] ?? '' ),
		'subCount'      => 'repeater' === $type ? count( (array) ( $f['sub_fields'] ?? array() ) ) : 0,
	);
	if ( 'repeater' === $type && $edit ) {
		$subs = array();
		foreach ( (array) ( $f['sub_fields'] ?? array() ) as $sf ) {
			$subs[] = minn_admin_acf_builder_field( $sf, $depth + 1 );
		}
		$out['sub_fields'] = $subs;
	}
	return $out;
}


/**
 * The location-rule vocabulary the builder edits: params with their value
 * choices, resolved live. Rules with params outside this set still render
 * (read-only) and survive saves verbatim — exotic locations stay ACF-editor
 * territory without being lost.
 *
 * @return array param => { label, values: [ [ value, label ] ] }
 */
function minn_admin_acf_location_choices() {
	$out = array();
	$pt  = array();
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $obj ) {
		if ( preg_match( '/^(acf-|wp_|edd_|elementor_)/', $obj->name ) || 'attachment' === $obj->name ) {
			continue;
		}
		$pt[] = array( $obj->name, $obj->labels->name );
	}
	$out['post_type'] = array( 'label' => __( 'Post type', 'minn-admin' ), 'values' => $pt );

	$tpl = array( array( 'default', __( 'Default template', 'minn-admin' ) ) );
	foreach ( (array) wp_get_theme()->get_page_templates() as $file => $label ) {
		$tpl[] = array( (string) $file, (string) $label );
	}
	$out['page_template'] = array( 'label' => __( 'Page template', 'minn-admin' ), 'values' => $tpl );

	$statuses = array();
	foreach ( (array) get_post_statuses() as $slug => $label ) {
		$statuses[] = array( (string) $slug, (string) $label );
	}
	$out['post_status'] = array( 'label' => __( 'Post status', 'minn-admin' ), 'values' => $statuses );

	$tax = array();
	foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $t ) {
		$tax[] = array( $t->name, $t->labels->name );
	}
	$out['taxonomy'] = array( 'label' => __( 'Taxonomy', 'minn-admin' ), 'values' => $tax );

	if ( function_exists( 'acf_get_options_pages' ) ) {
		$ops = array();
		foreach ( (array) acf_get_options_pages() as $page ) {
			if ( ! empty( $page['menu_slug'] ) ) {
				$ops[] = array( $page['menu_slug'], $page['page_title'] ?: $page['menu_slug'] );
			}
		}
		if ( $ops ) {
			$out['options_page'] = array( 'label' => __( 'Options page', 'minn-admin' ), 'values' => $ops );
		}
	}
	if ( function_exists( 'acf_get_block_types' ) ) {
		$blocks = array();
		foreach ( acf_get_block_types() as $name => $bt ) {
			$blocks[] = array( $name, ! empty( $bt['title'] ) ? $bt['title'] : $name );
		}
		if ( $blocks ) {
			$out['block'] = array( 'label' => __( 'Block', 'minn-admin' ), 'values' => $blocks );
		}
	}
	$roles = array();
	foreach ( (array) wp_roles()->get_names() as $slug => $label ) {
		$roles[] = array( (string) $slug, translate_user_role( $label ) );
	}
	$out['current_user_role'] = array( 'label' => __( 'User role', 'minn-admin' ), 'values' => $roles );
	return $out;
}

/**
 * Validate + sanitize a submitted location shape: OR groups of AND rules.
 * Editable params must carry a value from the live catalog; unknown params
 * pass through verbatim (the builder only ever re-sends them unchanged).
 *
 * @param mixed $location Submitted location.
 * @return array|WP_Error
 */
function minn_admin_acf_location_sanitize( $location ) {
	if ( ! is_array( $location ) ) {
		return new WP_Error( 'invalid', __( 'Malformed location rules.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$choices = minn_admin_acf_location_choices();
	$groups  = array();
	foreach ( $location as $rules ) {
		if ( ! is_array( $rules ) ) {
			return new WP_Error( 'invalid', __( 'Malformed location rules.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$clean = array();
		foreach ( $rules as $rule ) {
			$rule     = (array) $rule;
			$param    = (string) ( $rule['param'] ?? '' );
			$operator = '!=' === ( $rule['operator'] ?? '' ) ? '!=' : '==';
			$value    = (string) ( $rule['value'] ?? '' );
			if ( '' === $param || '' === $value ) {
				return new WP_Error( 'invalid', __( 'Every rule needs a value.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( isset( $choices[ $param ] ) ) {
				$ok = false;
				foreach ( $choices[ $param ]['values'] as $pair ) {
					if ( (string) $pair[0] === $value ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					return new WP_Error( 'invalid', __( 'That location value isn’t available on this site.', 'minn-admin' ), array( 'status' => 400 ) );
				}
			}
			$clean[] = array( 'param' => $param, 'operator' => $operator, 'value' => $value );
		}
		if ( $clean ) {
			$groups[] = $clean;
		}
	}
	if ( ! $groups ) {
		return new WP_Error( 'invalid', __( 'A field group needs at least one location rule.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return $groups;
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
	$out = array(
		'group'  => array(
			'key'           => $group['key'],
			'title'         => (string) $group['title'],
			'active'        => ! empty( $group['active'] ),
			'source'        => $group['minn_source'],
			'location'      => (array) $group['location'],
			'locationLabel' => minn_admin_acf_schema_location_label( $group ),
			'adminUrl'      => ! empty( $group['ID'] ) ? admin_url( 'post.php?post=' . (int) $group['ID'] . '&action=edit' ) : '',
		),
		'fields'          => $fields,
		'types'           => array_keys( minn_admin_acf_builder_types() ),
		'locationChoices' => minn_admin_acf_location_choices(),
	);
	// The page saves the whole group back; the stamp lets the save refuse
	// one made from a copy the store has moved past.
	$out['stamp'] = Minn_Admin::stamp( array( $out['group'], $out['fields'] ) );
	return $out;
}

/**
 * Resolve one submitted builder row against the stored fields it may
 * reference: an existing key keeps its stored name and type (immutable), a
 * new row must carry a supported type, a label and a usable name. $names
 * accumulates by reference for duplicate detection within one list.
 *
 * @param mixed $row    Submitted row.
 * @param array $stored key => stored ACF field array for this list.
 * @param array $names  Names already claimed in this list (by reference).
 * @param int   $order  Position in the submitted list.
 * @param bool  $sub    True inside a repeater (repeaters nest one level).
 * @return array|WP_Error Plan array or refusal.
 */
function minn_admin_acf_builder_plan_row( $row, $stored, &$names, $order, $sub = false ) {
	$types = minn_admin_acf_builder_types();
	$row   = (array) $row;
	$key   = isset( $row['key'] ) ? (string) $row['key'] : '';
	if ( $key && isset( $stored[ $key ] ) ) {
		$cur  = $stored[ $key ];
		$name = (string) $cur['name'];
		$type = (string) $cur['type'];
	} else {
		$type = sanitize_key( (string) ( $row['type'] ?? '' ) );
		if ( ! array_key_exists( $type, $types ) || ( $sub && 'repeater' === $type ) ) {
			return new WP_Error( 'invalid', $sub
				? __( 'Sub fields need one of the supported types; repeaters nest one level.', 'minn-admin' )
				: __( 'New fields need one of the supported types.', 'minn-admin' ), array( 'status' => 400 ) );
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
	// New choice fields must arrive with choices; an existing row may omit
	// the key entirely, or send it back as served (both keep what's stored,
	// see minn_admin_acf_builder_overlay()).
	$as_served = $cur && array_key_exists( 'choices', $row ) && is_scalar( $row['choices'] ) && (string) $row['choices'] === minn_admin_acf_schema_choices_text( $cur['choices'] ?? null );
	if ( in_array( $type, array( 'select', 'radio' ), true ) && ( ! $key || array_key_exists( 'choices', $row ) ) && ! $as_served ) {
		if ( ! minn_admin_acf_schema_parse_choices( (string) ( $row['choices'] ?? '' ) ) ) {
			return new WP_Error( 'invalid', __( 'Select and radio fields need at least one choice.', 'minn-admin' ), array( 'status' => 400 ) );
		}
	}
	return array( 'row' => $row, 'key' => $key, 'cur' => $cur, 'type' => $type, 'name' => $name, 'order' => $order );
}

/**
 * Overlay a submitted row's declared settings onto a field array. Only the
 * keys in $settings are touched; everything else survives verbatim.
 *
 * @param array $field    ACF field array (stored or new).
 * @param array $row      Submitted row.
 * @param array $settings Setting keys the field's type declares.
 * @return array
 */
function minn_admin_acf_builder_overlay( $field, $row, $settings ) {
	// The builder sends every setting of every row, an untouched one as the
	// payload served it. That one keeps its stored value: parsed back, a
	// choice label holding " : " lost its tail, a step of 'any' became 0 and
	// a min of '0.5' became a float.
	$served = ! empty( $field['ID'] ) ? minn_admin_acf_builder_field( $field ) : null;
	foreach ( $settings as $setting ) {
		if ( ! array_key_exists( $setting, $row ) ) {
			continue;
		}
		$v = $row[ $setting ];
		if ( $served && array_key_exists( $setting, $served ) ) {
			$same = 'required' === $setting
				? ( ! empty( $v ) && 'false' !== $v ) === (bool) $served['required']
				: ( null === $v || is_scalar( $v ) ) && (string) $v === (string) $served[ $setting ];
			if ( $same ) {
				continue;
			}
		}
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
	return $field;
}

/**
 * Save the whole group as one unit: title, active, ordered field list.
 * Existing fields overlay ONLY the settings their type declares (name and
 * type immutable); new fields (no key) are created in place; stored fields
 * absent from the payload are deleted. Repeaters carry a one-level
 * sub_fields list saved under the same discipline against the repeater's
 * own children. Validation happens before any write.
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
		$plan = minn_admin_acf_builder_plan_row( $row, $existing, $names, $i );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		// Repeaters carry their own one-level list; validate it now so a
		// bad sub refuses before anything is written. A row without the
		// sub_fields key keeps its stored children untouched.
		if ( 'repeater' === $plan['type'] && ( ! $plan['key'] || array_key_exists( 'sub_fields', $plan['row'] ) ) ) {
			$cur_subs = array();
			if ( $plan['cur'] ) {
				foreach ( (array) ( $plan['cur']['sub_fields'] ?? array() ) as $sf ) {
					$cur_subs[ $sf['key'] ] = $sf;
				}
			}
			$sub_rows  = isset( $plan['row']['sub_fields'] ) && is_array( $plan['row']['sub_fields'] ) ? array_values( $plan['row']['sub_fields'] ) : array();
			$sub_names = array();
			$sub_plans = array();
			foreach ( $sub_rows as $j => $srow ) {
				$sp = minn_admin_acf_builder_plan_row( $srow, $cur_subs, $sub_names, $j, true );
				if ( is_wp_error( $sp ) ) {
					return $sp;
				}
				$sub_plans[] = $sp;
			}
			$plan['subs']     = $sub_plans;
			$plan['cur_subs'] = $cur_subs;
		}
		$plans[] = $plan;
	}

	// ---- Group settings. ----
	// A title sent back as stored keeps its stored bytes (edge spaces too),
	// and so does one sent back as served (a credential copy masked).
	if ( ! isset( $body['title'] ) || ! Minn_Admin::unchanged( $body['title'], $group['title'] ) ) {
		$masked = Minn_Admin_Egress::refuse_masks( array( 'title' => $title ), array( 'title' => $group['title'] ) );
		if ( $masked ) {
			return $masked;
		}
		$group['title'] = $title;
	}
	if ( array_key_exists( 'active', $body ) ) {
		$group['active'] = ! empty( $body['active'] );
	}
	if ( array_key_exists( 'location', $body ) ) {
		$loc = minn_admin_acf_location_sanitize( $body['location'] );
		if ( is_wp_error( $loc ) ) {
			return $loc;
		}
		$group['location'] = $loc;
	}
	minn_admin_acf_schema_group_store( $group );

	// ---- Fields: update / create in payload order, then delete the absent. ----
	$kept = array();
	foreach ( $plans as $plan ) {
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
		$field               = minn_admin_acf_builder_overlay( $field, $plan['row'], $settings );
		$field['menu_order'] = $plan['order'];
		// Repeater children are separate fields written below; ACF's own
		// update path strips this key too.
		unset( $field['sub_fields'] );
		$saved = acf_update_field( wp_slash( $field ) );
		if ( ! $saved ) {
			continue;
		}
		$kept[ $saved['key'] ] = true;
		if ( isset( $plan['subs'] ) ) {
			$kept_subs = array();
			foreach ( $plan['subs'] as $sp ) {
				// A nested repeater sub keeps everything but label and order
				// (one editable level), same as unsupported types.
				$ssettings = isset( $types[ $sp['type'] ] ) && 'repeater' !== $sp['type'] ? $types[ $sp['type'] ] : array( 'label' );
				if ( $sp['cur'] ) {
					$sub = $sp['cur'];
				} else {
					$sub = array(
						'key'  => uniqid( 'field_' ),
						'name' => $sp['name'],
						'type' => $sp['type'],
					);
					if ( 'image' === $sp['type'] ) {
						$sub['return_format'] = 'id';
					}
				}
				$sub               = minn_admin_acf_builder_overlay( $sub, $sp['row'], $ssettings );
				$sub['menu_order'] = $sp['order'];
				$sub['parent']     = $saved['ID'];
				unset( $sub['sub_fields'] );
				$ssaved = acf_update_field( wp_slash( $sub ) );
				if ( $ssaved ) {
					$kept_subs[ $ssaved['key'] ] = true;
				}
			}
			foreach ( $plan['cur_subs'] as $skey => $sf ) {
				if ( ! isset( $kept_subs[ $skey ] ) ) {
					acf_delete_field( $sf['ID'] );
				}
			}
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

/**
 * Drop caller-supplied identity from an imported field tree.
 *
 * ACF resolves a field's ID from its key only when it already knows that key,
 * so a field carrying a brand-new key keeps whatever ID the uploaded file set,
 * and acf_update_field() then rewrites the post row that ID names. Recurse into
 * sub_fields and layouts, because a repeater or flexible-content field carries
 * the same slots one level down.
 *
 * @param array $fields Field definitions from an uploaded file.
 * @return array
 */
function minn_admin_acf_strip_field_identity( $fields ) {
	$out = array();
	foreach ( (array) $fields as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		unset( $field['ID'], $field['parent'] );
		if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
			$field['sub_fields'] = minn_admin_acf_strip_field_identity( $field['sub_fields'] );
		}
		if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			foreach ( $field['layouts'] as $lk => $layout ) {
				if ( is_array( $layout ) && isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
					$field['layouts'][ $lk ]['sub_fields'] = minn_admin_acf_strip_field_identity( $layout['sub_fields'] );
				}
			}
		}
		$out[] = $field;
	}
	return $out;
}

/**
 * Import field groups from ACF-shaped export JSON. A group whose key
 * already exists as a DB group updates IN PLACE — the round trip ACF's own
 * tool doesn't offer (acf_import_field_group never resolves the key, so a
 * plain re-import inserts a duplicate post; passing the existing ID is what
 * flips it into an update). Code-registered keys refuse: their source of
 * truth is the codebase. Non-group entries (post types, taxonomies) are
 * counted and skipped. Validation happens before any write.
 *
 * @param string $content Raw JSON: one group object or an array of them.
 * @return array|WP_Error Summary { ok, created, updated, skipped, message }.
 */
function minn_admin_acf_schema_import( $content ) {
	$data = json_decode( (string) $content, true );
	if ( null === $data || ! is_array( $data ) ) {
		return new WP_Error( 'invalid', __( 'That isn’t valid JSON.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( isset( $data['key'] ) ) {
		$data = array( $data );
	}
	$groups  = array();
	$skipped = 0;
	foreach ( $data as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['key'] ) || ! is_string( $entry['key'] ) ) {
			return new WP_Error( 'invalid', __( 'That JSON doesn’t look like an ACF export.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( 0 !== strpos( $entry['key'], 'group_' ) ) {
			$skipped++;
			continue;
		}
		if ( ! isset( $entry['title'] ) || '' === trim( (string) $entry['title'] ) || ! isset( $entry['fields'] ) || ! is_array( $entry['fields'] ) ) {
			return new WP_Error( 'invalid', __( 'That JSON doesn’t look like an ACF field group export.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$existing = minn_admin_acf_schema_group( $entry['key'] );
		if ( $existing && 'db' !== $existing['minn_source'] ) {
			/* translators: %s: the title of a field group registered in code. */
			return new WP_Error( 'invalid', sprintf( __( '“%s” is registered in code on this site — an import can’t override it.', 'minn-admin' ), $entry['title'] ), array( 'status' => 400 ) );
		}
		// Identity is ours to decide, never the uploaded file's. A caller-set
		// ID survives into acf_import_field_group(), which passes it to
		// wp_update_post() and rewrites whatever post row it names into a
		// field group. Resolve it from the key we just looked up instead.
		unset( $entry['ID'] );
		if ( $existing ) {
			$entry['ID'] = (int) $existing['ID'];
		}
		// The fields inside carry the same identity slots, and ACF only remaps
		// an ID for a key it already knows: a field with a new key keeps
		// whatever ID the file gave it, and acf_update_field() then takes the
		// wp_update_post() branch and rewrites that post row into an acf-field.
		// `parent` is the same shape. Let ACF's own key-to-ID map supply both.
		if ( isset( $entry['fields'] ) && is_array( $entry['fields'] ) ) {
			$entry['fields'] = minn_admin_acf_strip_field_identity( $entry['fields'] );
		}
		$groups[] = $entry;
	}
	if ( ! $groups ) {
		return new WP_Error( 'invalid', __( 'No field groups found in that file.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$created = 0;
	$updated = 0;
	foreach ( $groups as $entry ) {
		if ( empty( $entry['ID'] ) ) {
			$created++;
		} else {
			$updated++;
		}
		acf_import_field_group( $entry );
	}
	$bits = array();
	if ( $created ) {
		/* translators: %d: number of field groups created by the import. */
		$bits[] = sprintf( _n( '%d group imported', '%d groups imported', $created, 'minn-admin' ), $created );
	}
	if ( $updated ) {
		/* translators: %d: number of field groups updated in place by the import. */
		$bits[] = sprintf( _n( '%d group updated', '%d groups updated', $updated, 'minn-admin' ), $updated );
	}
	if ( $skipped ) {
		/* translators: %d: number of non-field-group entries the import skipped. */
		$bits[] = sprintf( _n( '%d entry left to ACF’s own tools', '%d entries left to ACF’s own tools', $skipped, 'minn-admin' ), $skipped );
	}
	return array(
		'ok'      => true,
		'created' => $created,
		'updated' => $updated,
		'skipped' => $skipped,
		'message' => implode( ', ', $bits ) . '.',
	);
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_schema_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( minn_admin_acf_schema_cap() );
	};
	register_rest_route( 'minn-admin/v1', '/acf/schema/groups/(?P<key>[a-zA-Z0-9_\-]+)/export', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$group = minn_admin_acf_schema_group( (string) Minn_Admin::path_param( $request, 'key' ) );
			if ( ! $group ) {
				return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			// ACF's own Tools shape: fields nested on the group, prepared
			// for export, wrapped in an array — their import reads this
			// file and ours reads theirs.
			$group['fields'] = acf_get_fields( $group );
			$export          = acf_prepare_field_group_for_export( $group );
			$json            = function_exists( 'acf_json_encode' ) ? acf_json_encode( array( $export ) ) : wp_json_encode( array( $export ), JSON_PRETTY_PRINT );
			// A credential in the group would leave masked: a file that
			// imports broken is refused, never handed out.
			$refused = Minn_Admin_Egress::refuse_export( $json );
			if ( $refused ) {
				return $refused;
			}
			return rest_ensure_response( array(
				'filename' => 'acf-export-' . sanitize_title( $export['title'] ) . '-' . gmdate( 'Y-m-d' ) . '.json',
				'content'  => $json,
				'mime'     => 'application/json',
			) );
		},
	) );
	register_rest_route( 'minn-admin/v1', '/acf/schema/import', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			return rest_ensure_response( minn_admin_acf_schema_import( (string) $request['content'] ) );
		},
	) );
	register_rest_route( 'minn-admin/v1', '/acf/schema/groups/(?P<key>[a-zA-Z0-9_\-]+)/full', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$group = minn_admin_acf_schema_group( (string) Minn_Admin::path_param( $request, 'key' ) );
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
				$group = minn_admin_acf_schema_group( (string) Minn_Admin::path_param( $request, 'key' ) );
				if ( ! $group ) {
					return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( 'db' !== $group['minn_source'] ) {
					return new WP_Error( 'read_only', __( 'This group is registered in code — edit it where it is defined.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				// A field added in ACF (or by an acf-json sync) after the page
				// loaded would read as removed and be deleted; a group switched
				// off would come back on. Refuse a save from an older copy.
				$body  = (array) $request->get_json_params();
				$stale = Minn_Admin::stale( $body['stamp'] ?? '', minn_admin_acf_builder_payload( $group )['stamp'] );
				if ( $stale ) {
					return $stale;
				}
				return rest_ensure_response( minn_admin_acf_builder_save( $group, $body ) );
			},
		),
	) );
} );
