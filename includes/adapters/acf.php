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

// color_picker stores a plain color string and renders as a text input;
// image stores an attachment id and rides the form engine's image control
// ({ id, url }) in the editor panel and a pick-button row in the inspector.
const MINN_ADMIN_ACF_SIMPLE_TYPES = array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'true_false', 'color_picker', 'image' );

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
		} elseif ( 'image' === $field['type'] ) {
			// The form engine's image control speaks { id, url }.
			$id = is_numeric( $val ) ? (int) $val : 0;
			$out[ $name ] = $id ? array(
				'id'  => $id,
				'url' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
			) : '';
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
		} elseif ( 'image' === $field['type'] ) {
			// { id, url } from the image control, a bare id, or empty = clear.
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = (array) $value;
				$value = isset( $value['id'] ) ? (int) $value['id'] : 0;
			}
			$value = is_numeric( $value ) && (int) $value > 0 ? (int) $value : '';
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

/**
 * Map ACF block fields onto a `dataForm` descriptor for the block inspector.
 *
 * ACF blocks (Pro) store their content inside the comment's `data` attribute
 * as { field_name: value, _field_name: field_key }. The generic inspector
 * rightly skips object attributes, which left these blocks uneditable in Minn
 * (and worse, exposed the raw name/mode wrapper attrs). This describes each
 * registered acf/* block's simple fields so the inspector renders a real form.
 *
 * @return array Block name => descriptor additions.
 */
function minn_admin_acf_block_forms() {
	if ( ! function_exists( 'acf_get_block_types' ) || ! function_exists( 'acf_get_block_fields' ) ) {
		return array(); // blocks are ACF Pro
	}
	$out = array();
	foreach ( acf_get_block_types() as $name => $block_type ) {
		$fields = array();
		$alias  = array();
		$locked = 0;
		foreach ( (array) acf_get_block_fields( array( 'name' => $name ) ) as $f ) {
			if ( in_array( $f['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
				continue;
			}
			$simple = minn_admin_acf_map_field( $f );
			if ( ! $simple ) {
				$locked++;
				continue;
			}
			$entry = array(
				'name'  => $simple['name'],
				'label' => $simple['label'],
			);
			switch ( $simple['type'] ) {
				case 'textarea':
					$entry['control'] = 'textarea';
					break;
				case 'number':
				case 'range':
					$entry['control'] = 'number';
					break;
				case 'true_false':
					$entry['control'] = 'checkbox';
					break;
				case 'select':
				case 'radio':
					$entry['control'] = 'select';
					break;
				case 'image':
					$entry['control'] = 'image';
					break;
				default:
					$entry['control'] = 'text';
			}
			if ( ! empty( $simple['choices'] ) && is_array( $simple['choices'] ) ) {
				$entry['options'] = array();
				foreach ( $simple['choices'] as $value => $label ) {
					$entry['options'][] = array( (string) $value, (string) $label );
				}
			}
			$fields[]                  = $entry;
			$alias[ $simple['name'] ] = $simple['key'];
		}
		$out[ $name ] = array(
			// The wrapper attrs are plumbing: `name` edits corrupt the block,
			// `data` is the map the form below owns, `mode` is a Gutenberg UI
			// concern. Hidden even when no fields mapped.
			'attributes' => array(
				'name' => array( 'hide' => true ),
				'data' => array( 'hide' => true ),
				'mode' => array( 'hide' => true ),
				'id'   => array( 'hide' => true ),
			),
		);
		if ( $fields || $locked ) {
			$out[ $name ]['dataForm'] = array(
				'attr'   => 'data',
				'fields' => $fields,
				'alias'  => $alias,
				'locked' => $locked,
			);
		}
	}
	return $out;
}

add_filter( 'minn_admin_block_forms', function ( $forms ) {
	if ( ! minn_admin_acf_active() ) {
		return $forms;
	}
	foreach ( minn_admin_acf_block_forms() as $name => $form ) {
		$forms[ $name ] = isset( $forms[ $name ] ) && is_array( $forms[ $name ] )
			? array_merge( $forms[ $name ], $form )
			: $form;
	}
	return $forms;
} );

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

/* ===== ACF options pages (Pro) as settings-only surfaces ===== */

/**
 * Options pages are ACF Pro; the free plugin has no registry to read.
 *
 * @return bool
 */
function minn_admin_acf_options_active() {
	return minn_admin_acf_active() && function_exists( 'acf_get_options_pages' );
}

/**
 * Options pages the current user may manage, keyed by menu slug. Each page
 * declares its own capability (ACF defaults to edit_posts); Minn honors it
 * rather than inventing a gate.
 *
 * @return array
 */
function minn_admin_acf_options_pages_allowed() {
	$out = array();
	foreach ( (array) acf_get_options_pages() as $page ) {
		if ( empty( $page['menu_slug'] ) ) {
			continue;
		}
		// Redirect parents exist only to hold children in the admin menu.
		if ( ! empty( $page['redirect'] ) && ! empty( $page['child_slugs'] ) ) {
			continue;
		}
		$cap = ! empty( $page['capability'] ) ? $page['capability'] : 'edit_posts';
		if ( ! current_user_can( $cap ) ) {
			continue;
		}
		$out[ $page['menu_slug'] ] = $page;
	}
	return $out;
}

/**
 * Tab layout for one options page. ACF `tab` fields are the natural section
 * boundaries: each starts a Minn tab that collects the fields after it (ACF's
 * own semantics); fields before any tab land in a tab named for their group.
 * Ids are positional (tab-0…) — both GET and POST re-derive the same walk, so
 * they stay stable within a schema version.
 *
 * @param array $page Options page array.
 * @return array[] { id, label, fields (simple maps incl. key), locked }
 */
function minn_admin_acf_options_tabs( $page ) {
	$tabs    = array();
	$current = -1;
	$open    = function ( $label ) use ( &$tabs, &$current ) {
		$tabs[]  = array( 'label' => $label, 'fields' => array(), 'locked' => 0 );
		$current = count( $tabs ) - 1;
	};
	foreach ( acf_get_field_groups( array( 'options_page' => $page['menu_slug'] ) ) as $group ) {
		$current = -1;
		foreach ( (array) acf_get_fields( $group ) as $f ) {
			$type = $f['type'] ?? '';
			if ( 'tab' === $type ) {
				$open( $f['label'] );
				continue;
			}
			if ( in_array( $type, MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
				continue;
			}
			if ( $current < 0 ) {
				$open( $group['title'] );
			}
			$simple = minn_admin_acf_map_field( $f );
			// Image fields count as locked here: the settings engine has no
			// media-picker binding (the editor panel and block inspector do).
			if ( ! $simple || 'image' === $simple['type'] ) {
				$tabs[ $current ]['locked']++;
				continue;
			}
			$tabs[ $current ]['fields'][] = $simple;
		}
	}
	$out = array();
	foreach ( $tabs as $tab ) {
		if ( ! $tab['fields'] && ! $tab['locked'] ) {
			continue;
		}
		$tab['id'] = 'tab-' . count( $out );
		$out[]     = $tab;
	}
	return $out;
}

/**
 * The wp-admin URL for an options page (its locked-fields link-out).
 *
 * @param array $page Options page array.
 * @return string
 */
function minn_admin_acf_options_admin_url( $page ) {
	$parent = ! empty( $page['parent_slug'] ) ? (string) $page['parent_slug'] : '';
	$base   = ( $parent && false !== strpos( $parent, '.php' ) ) ? $parent : 'admin.php';
	return admin_url( $base . '?page=' . rawurlencode( $page['menu_slug'] ) );
}

/**
 * GET/POST shape for one options-page tab: { groups, values, adminUrl }.
 * Values key by ACF field KEY (unique and unambiguous across groups).
 *
 * @param array  $page   Options page array.
 * @param string $tab_id Positional tab id.
 * @return array|WP_Error
 */
function minn_admin_acf_options_tab_shape( $page, $tab_id ) {
	$tabs = minn_admin_acf_options_tabs( $page );
	$tab  = null;
	foreach ( $tabs as $t ) {
		if ( $t['id'] === $tab_id ) {
			$tab = $t;
			break;
		}
	}
	if ( ! $tab ) {
		return new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$post_id = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
	$fields  = array();
	$values  = array();
	foreach ( $tab['fields'] as $f ) {
		$sf = array(
			'key'   => $f['key'],
			'label' => $f['label'],
			'type'  => 'true_false' === $f['type'] ? 'toggle'
				: ( in_array( $f['type'], array( 'select', 'radio' ), true ) ? 'select'
				: ( in_array( $f['type'], array( 'number', 'range' ), true ) ? 'number'
				: ( 'textarea' === $f['type'] ? 'textarea' : 'text' ) ) ),
		);
		if ( 'select' === $sf['type'] ) {
			$sf['options'] = array();
			foreach ( (array) ( $f['choices'] ?? array() ) as $value => $label ) {
				$sf['options'][] = array( (string) $value, (string) $label );
			}
		}
		$fields[] = $sf;

		$v = get_field( $f['key'], $post_id, false );
		if ( 'toggle' === $sf['type'] ) {
			$values[ $f['key'] ] = ! empty( $v );
		} elseif ( is_array( $v ) ) {
			$values[ $f['key'] ] = '';
		} else {
			$values[ $f['key'] ] = null === $v ? '' : $v;
		}
	}
	return array(
		'groups'   => array(
			array(
				'title'  => '',
				'fields' => $fields,
				'locked' => $tab['locked'],
			),
		),
		'values'   => $values,
		'adminUrl' => minn_admin_acf_options_admin_url( $page ),
	);
}

/**
 * Save edited values through ACF's own setter. Keys are whitelisted against
 * the page's mapped fields (never an arbitrary update_field), coerced to
 * ACF's stored shapes (1/0 for true_false, strings otherwise).
 *
 * @param array $page   Options page array.
 * @param array $values Field key => value.
 */
function minn_admin_acf_options_save( $page, $values ) {
	$byKey = array();
	foreach ( minn_admin_acf_options_tabs( $page ) as $tab ) {
		foreach ( $tab['fields'] as $f ) {
			$byKey[ $f['key'] ] = $f;
		}
	}
	$post_id = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
	foreach ( (array) $values as $key => $v ) {
		if ( ! isset( $byKey[ $key ] ) ) {
			continue;
		}
		if ( 'true_false' === $byKey[ $key ]['type'] ) {
			$v = ( ! empty( $v ) && 'false' !== $v && '0' !== (string) $v ) ? 1 : 0;
		} elseif ( null === $v || false === $v ) {
			$v = '';
		} elseif ( ! is_scalar( $v ) ) {
			continue;
		}
		update_field( $key, $v, $post_id );
	}
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_acf_options_active() ) {
		return $surfaces;
	}
	foreach ( minn_admin_acf_options_pages_allowed() as $slug => $page ) {
		$tabs = minn_admin_acf_options_tabs( $page );
		if ( ! $tabs ) {
			continue;
		}
		$tab_list = array();
		foreach ( $tabs as $t ) {
			$tab_list[] = array( 'id' => $t['id'], 'label' => $t['label'] );
		}
		$surfaces[ 'acf-options-' . sanitize_key( $slug ) ] = array(
			'label'    => $page['page_title'] ? $page['page_title'] : $page['menu_slug'],
			'sub'      => 'ACF',
			'icon'     => 'gear',
			'cap'      => ! empty( $page['capability'] ) ? $page['capability'] : 'edit_posts',
			'settings' => array(
				'label' => __( 'Settings', 'minn-admin' ),
				'tabs'  => $tab_list,
				'route' => 'minn-admin/v1/acf/options/' . rawurlencode( $slug ) . '/{tab}',
			),
		);
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_options_active() ) {
		return;
	}
	$resolve = function ( $req ) {
		$pages = minn_admin_acf_options_pages_allowed();
		$slug  = rawurldecode( (string) $req['page'] );
		return isset( $pages[ $slug ] ) ? $pages[ $slug ] : null;
	};
	register_rest_route( 'minn-admin/v1', '/acf/options/(?P<page>[A-Za-z0-9_%.\-]+)/(?P<tab>tab-\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req ); // allowed-pages already filters by the page's own capability
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				return rest_ensure_response( minn_admin_acf_options_tab_shape( $resolve( $req ), (string) $req['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req );
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				$page   = $resolve( $req );
				$body   = $req->get_json_params();
				$values = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
				minn_admin_acf_options_save( $page, $values );
				return rest_ensure_response( minn_admin_acf_options_tab_shape( $page, (string) $req['tab'] ) );
			},
		),
	) );
} );
