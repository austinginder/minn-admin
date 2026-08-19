<?php
/**
 * Bundled adapter: Advanced Custom Post Types (ACPT).
 *
 * ACPT's schema builder is a multi-step React canvas, so Minn inventories
 * field groups, post types and taxonomies and links each row to that native
 * canvas. Simple post fields are editable in Minn through ACPT's own value
 * API. Licensing joins the shared Licenses view through ACPT's own storage,
 * API client and public activation/deactivation methods.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** ACPT field types Minn can safely round-trip as simple controls. */
const MINN_ADMIN_ACPT_SIMPLE = array(
	'Text'     => 'text',
	'Textarea' => 'textarea',
	'Number'   => 'number',
	'Range'    => 'range',
	'Email'    => 'email',
	'Url'      => 'url',
	'Select'   => 'select',
	'Radio'    => 'radio',
	'Checkbox' => 'multicheck',
	'Toggle'   => 'true_false',
	'Color'    => 'color',
	'Date'     => 'date',
	'DateTime' => 'datetime',
	'Time'     => 'time',
);

/** Whether the active ACPT build exposes the APIs used by this adapter. */
function minn_admin_acpt_active() {
	return defined( 'ACPT_PLUGIN_VERSION' )
		&& class_exists( '\\ACPT\\Core\\Repository\\MetaRepository' )
		&& function_exists( 'get_acpt_field' )
		&& function_exists( 'save_acpt_meta_field_value' );
}

/** ACPT's admin capability. */
function minn_admin_acpt_can_manage() {
	return current_user_can( 'manage_options' );
}

/** Link into one route of ACPT's native hash-router canvas. */
function minn_admin_acpt_admin_url( $route = '' ) {
	return admin_url( 'admin.php?page=advanced-custom-post-type#/' . ltrim( (string) $route, '/' ) );
}

/** Resolve a REST base or post type slug to the registered post type name. */
function minn_admin_acpt_resolve_type( $rest_base ) {
	$rest_base = sanitize_key( $rest_base );
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
		$base = $obj->rest_base ? $obj->rest_base : $obj->name;
		if ( $base === $rest_base || $obj->name === $rest_base ) {
			return $obj->name;
		}
	}
	return 'post';
}

/** A readable summary of a field group's ACPT location rules. */
function minn_admin_acpt_location_label( $group ) {
	$parts = array();
	foreach ( $group->getBelongs() as $belong ) {
		$type = (string) $belong->getBelongsTo();
		$find = $belong->getFind();
		if ( is_array( $find ) ) {
			$find = implode( ', ', array_map( 'strval', $find ) );
		}
		$labels = array(
			'customPostType' => __( 'Post type', 'minn-admin' ),
			'taxonomy'       => __( 'Taxonomy', 'minn-admin' ),
			'optionPage'     => __( 'Option page', 'minn-admin' ),
			'user'           => __( 'User', 'minn-admin' ),
			'comment'        => __( 'Comment', 'minn-admin' ),
			'media'          => __( 'Media', 'minn-admin' ),
		);
		$label = isset( $labels[ $type ] ) ? $labels[ $type ] : ucwords( preg_replace( '/(?<!^)[A-Z]/', ' $0', $type ) );
		$parts[] = '' !== (string) $find ? $label . ': ' . $find : $label;
	}
	return $parts ? implode( ' · ', $parts ) : __( 'No location rules', 'minn-admin' );
}

/** Filter and paginate an in-memory collection for a Minn list response. */
function minn_admin_acpt_page( $items, WP_REST_Request $request, $keys ) {
	$search = trim( (string) $request['search'] );
	if ( '' !== $search ) {
		$items = array_values( array_filter( $items, function ( $item ) use ( $search, $keys ) {
			$text = '';
			foreach ( $keys as $key ) {
				$text .= ' ' . (string) ( $item[ $key ] ?? '' );
			}
			return false !== stripos( $text, $search );
		} ) );
	}
	$total    = count( $items );
	$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
	$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
	return array(
		'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
		'total' => $total,
	);
}

/** Field-group inventory from ACPT's repository models. */
function minn_admin_acpt_meta_groups( WP_REST_Request $request ) {
	$items = array();
	foreach ( \ACPT\Core\Repository\MetaRepository::get( array() ) as $group ) {
		$count = 0;
		foreach ( $group->getBoxes() as $box ) {
			$count += count( $box->getFields() );
		}
		$items[] = array(
			'id'       => $group->getId(),
			'title'    => $group->getUIName(),
			'name'     => $group->getName(),
			'fields'   => $count,
			'location' => minn_admin_acpt_location_label( $group ),
		);
	}
	return minn_admin_acpt_page( $items, $request, array( 'title', 'name', 'location' ) );
}

/** Custom-post-type inventory from ACPT's repository models. */
function minn_admin_acpt_post_types( WP_REST_Request $request ) {
	$items = array();
	foreach ( \ACPT\Core\Repository\CustomPostTypeRepository::get( array() ) as $model ) {
		$items[] = array(
			'id'      => $model->getId(),
			'title'   => $model->getPlural(),
			'name'    => $model->getName(),
			'singular' => $model->getSingular(),
			'posts'   => $model->getPostCount(),
			'source'  => $model->isNative() ? __( 'WordPress', 'minn-admin' ) : 'ACPT',
		);
	}
	return minn_admin_acpt_page( $items, $request, array( 'title', 'name', 'singular', 'source' ) );
}

/** Taxonomy inventory from ACPT's repository models. */
function minn_admin_acpt_taxonomies( WP_REST_Request $request ) {
	$items = array();
	foreach ( \ACPT\Core\Repository\TaxonomyRepository::get( array() ) as $model ) {
		$items[] = array(
			'id'       => $model->getId(),
			'title'    => $model->getPlural(),
			'slug'     => $model->getSlug(),
			'singular' => $model->getSingular(),
			'posts'    => $model->getPostCount(),
			'source'   => $model->isNative() ? __( 'WordPress', 'minn-admin' ) : 'ACPT',
		);
	}
	return minn_admin_acpt_page( $items, $request, array( 'title', 'slug', 'singular', 'source' ) );
}

/** Map one ACPT field to Minn's panel vocabulary, or null when complex. */
function minn_admin_acpt_map_field( $field ) {
	if ( $field->getParentId() || $field->getBlockId() || $field->getForgedBy() ) {
		return null;
	}
	$type = $field->getType();
	if ( ! isset( MINN_ADMIN_ACPT_SIMPLE[ $type ] ) ) {
		return null;
	}
	$permissions = $field->userPermissions();
	if ( empty( $permissions['read'] ) || empty( $permissions['edit'] ) ) {
		return null;
	}
	$mapped = array(
		'name'  => $field->getId(),
		'label' => $field->getLabelOrName(),
		'type'  => MINN_ADMIN_ACPT_SIMPLE[ $type ],
	);
	if ( in_array( $type, array( 'Select', 'Radio', 'Checkbox' ), true ) ) {
		$choices = array();
		foreach ( $field->getOptions() as $option ) {
			$choices[ $option->getValue() ] = $option->getLabel();
		}
		if ( ! $choices ) {
			return null;
		}
		$mapped['choices'] = $choices;
	}
	foreach ( array( 'min', 'max', 'step' ) as $bound ) {
		$value = $field->getAdvancedOption( $bound );
		if ( null !== $value && '' !== $value ) {
			$mapped[ $bound ] = $value;
		}
	}
	return $mapped;
}

/** ACPT groups visible for one exact post and type. */
function minn_admin_acpt_groups_for( $post_id, $post_type ) {
	$groups = array();
	foreach ( \ACPT\Core\Repository\MetaRepository::get( array(
		'belongsTo' => \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE,
		'find'      => $post_type,
	) ) as $group ) {
		if ( $group->isVisible( array(
			'post_id'   => $post_id ?: null,
			'belongsTo' => \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE,
			'find'      => $post_type,
		) ) ) {
			$groups[] = $group;
		}
	}
	return $groups;
}

/** Panel schema payload and an optional field-id lookup for value I/O. */
function minn_admin_acpt_fields_payload( $post_id, $post_type, $with_lookup = false ) {
	$groups = array();
	$lookup = array();
	foreach ( minn_admin_acpt_groups_for( $post_id, $post_type ) as $group ) {
		foreach ( $group->getBoxes() as $box ) {
			$mapped = array();
			$locked = 0;
			foreach ( $box->getFields() as $field ) {
				$permissions = $field->userPermissions();
				if ( empty( $permissions['read'] ) ) {
					continue;
				}
				// HTML is display chrome. Other unsupported types hold data and count as locked.
				if ( 'HTML' === $field->getType() ) {
					continue;
				}
				if ( empty( $permissions['edit'] ) ) {
					$locked++;
					continue;
				}
				$simple = minn_admin_acpt_map_field( $field );
				if ( ! $simple ) {
					$locked++;
					continue;
				}
				$mapped[]                 = $simple;
				$lookup[ $field->getId() ] = $field;
			}
			if ( $mapped || $locked ) {
				$groups[] = array(
					'group'  => $group->getUIName() . ' · ' . $box->getUiName(),
					'fields' => $mapped,
					'locked' => $locked,
				);
			}
		}
	}
	return $with_lookup ? array( 'groups' => $groups, 'lookup' => $lookup ) : array( 'groups' => $groups );
}

/** Read simple field values through ACPT's public value helper. */
function minn_admin_acpt_read_values( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$schema = minn_admin_acpt_fields_payload( $post_id, $post->post_type, true );
	$out    = array();
	foreach ( $schema['lookup'] as $id => $field ) {
		$value = get_acpt_field( array(
			'post_id'    => $post_id,
			'box_name'   => $field->getBox()->getName(),
			'field_name' => $field->getName(),
			'format'     => 'only_value',
			'return'     => 'raw',
		) );
		if ( 'Toggle' === $field->getType() ) {
			$value = ! empty( $value ) && 'false' !== $value && '0' !== (string) $value;
		} elseif ( 'Checkbox' === $field->getType() ) {
			$value = is_array( $value ) ? array_values( $value ) : ( '' === (string) $value ? array() : array( $value ) );
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			$value = '';
		}
		$out[ $id ] = $value;
	}
	return $out;
}

/** Write allowed simple fields through ACPT's public value helpers. */
function minn_admin_acpt_write_values( $post_id, $values ) {
	$post = get_post( $post_id );
	if ( ! $post || ! is_array( $values ) ) {
		return;
	}
	$schema = minn_admin_acpt_fields_payload( $post_id, $post->post_type, true );
	foreach ( $values as $id => $value ) {
		if ( ! isset( $schema['lookup'][ $id ] ) ) {
			continue;
		}
		$field = $schema['lookup'][ $id ];
		$args  = array(
			'post_id'    => $post_id,
			'box_name'   => $field->getBox()->getName(),
			'field_name' => $field->getName(),
		);
		if ( ( '' === $value || null === $value ) && 'Checkbox' !== $field->getType() ) {
			delete_acpt_meta_field_value( $args );
			continue;
		}
		if ( 'Toggle' === $field->getType() ) {
			$value = ! empty( $value ) && 'false' !== $value && '0' !== (string) $value;
		} elseif ( 'Checkbox' === $field->getType() ) {
			$value = is_array( $value ) ? array_values( array_map( 'sanitize_text_field', $value ) ) : array();
		}
		$args['value'] = $value;
		save_acpt_meta_field_value( $args );
	}
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_acpt_active() ) {
		return $surfaces;
	}
	$surfaces['acpt'] = array(
		'label'      => __( 'Content models', 'minn-admin' ),
		'sub'        => 'ACPT',
		'icon'       => 'blocks',
		'group'      => 'tools',
		'cap'        => 'manage_options',
		'collection' => array(
			'viewLabel' => __( 'Field groups', 'minn-admin' ),
			'route'     => 'minn-admin/v1/acpt/meta-groups',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Field group', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'format' => 'mono' ),
				array( 'key' => 'fields', 'label' => __( 'Fields', 'minn-admin' ) ),
				array( 'key' => 'location', 'label' => __( 'Location', 'minn-admin' ) ),
			),
			'detail'    => array(),
			'actions'   => array(
				array( 'label' => __( 'Edit in ACPT ↗', 'minn-admin' ), 'href' => minn_admin_acpt_admin_url( 'edit_meta/{id}' ) ),
			),
		),
		'views'      => array(
			array(
				'viewLabel' => __( 'Post types', 'minn-admin' ),
				'route'     => 'minn-admin/v1/acpt/post-types',
				'pageQuery' => 'per_page=25&page={page}',
				'itemsKey'  => 'items',
				'totalKey'  => 'total',
				'search'    => 'search={q}',
				'columns'   => array(
					array( 'key' => 'title', 'label' => __( 'Post type', 'minn-admin' ), 'format' => 'title' ),
					array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'format' => 'mono' ),
					array( 'key' => 'singular', 'label' => __( 'Singular', 'minn-admin' ) ),
					array( 'key' => 'posts', 'label' => __( 'Posts', 'minn-admin' ) ),
					array( 'key' => 'source', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'pill' ),
				),
				'detail'    => array(),
				'actions'   => array(
					array( 'label' => __( 'Edit in ACPT ↗', 'minn-admin' ), 'href' => minn_admin_acpt_admin_url( 'edit/{name}' ) ),
				),
			),
			array(
				'viewLabel' => __( 'Taxonomies', 'minn-admin' ),
				'route'     => 'minn-admin/v1/acpt/taxonomies',
				'pageQuery' => 'per_page=25&page={page}',
				'itemsKey'  => 'items',
				'totalKey'  => 'total',
				'search'    => 'search={q}',
				'columns'   => array(
					array( 'key' => 'title', 'label' => __( 'Taxonomy', 'minn-admin' ), 'format' => 'title' ),
					array( 'key' => 'slug', 'label' => __( 'Slug', 'minn-admin' ), 'format' => 'mono' ),
					array( 'key' => 'singular', 'label' => __( 'Singular', 'minn-admin' ) ),
					array( 'key' => 'posts', 'label' => __( 'Posts', 'minn-admin' ) ),
					array( 'key' => 'source', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'pill' ),
				),
				'detail'    => array(),
				'actions'   => array(
					array( 'label' => __( 'Edit in ACPT ↗', 'minn-admin' ), 'href' => minn_admin_acpt_admin_url( 'edit_taxonomy/{slug}' ) ),
				),
			),
		),
	);
	return $surfaces;
} );

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_acpt_active() ) {
		return $panels;
	}
	$panels['acpt'] = array(
		'label'       => __( 'Custom fields', 'minn-admin' ),
		'sub'         => 'ACPT',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/acpt/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_acpt',
		'writeKey'    => 'minn_acpt',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acpt_active() ) {
		return;
	}
	foreach ( array(
		'/acpt/meta-groups' => 'minn_admin_acpt_meta_groups',
		'/acpt/post-types'  => 'minn_admin_acpt_post_types',
		'/acpt/taxonomies'  => 'minn_admin_acpt_taxonomies',
	) as $route => $callback ) {
		register_rest_route( 'minn-admin/v1', $route, array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_acpt_can_manage',
			'callback'            => function ( WP_REST_Request $request ) use ( $callback ) {
				return rest_ensure_response( call_user_func( $callback, $request ) );
			},
		) );
	}

	register_rest_route( 'minn-admin/v1', '/acpt/fields', array(
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
			$post_type = minn_admin_acpt_resolve_type( $request['post_type'] );
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
			return rest_ensure_response( minn_admin_acpt_fields_payload( $post_id, $post_type ) );
		},
	) );

	foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
		register_rest_field( $type, 'minn_acpt', array(
			'get_callback'    => function ( $obj ) {
				$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
				return ( $id && current_user_can( 'edit_post', $id ) ) ? (object) minn_admin_acpt_read_values( $id ) : new stdClass();
			},
			'update_callback' => function ( $value, $post ) {
				if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return;
				}
				if ( is_object( $value ) ) {
					$value = (array) $value;
				}
				if ( is_array( $value ) ) {
					minn_admin_acpt_write_values( $post->ID, $value );
				}
			},
			'schema'          => array(
				'description' => __( 'ACPT simple field values for Minn Admin.', 'minn-admin' ),
				'type'        => 'object',
				'context'     => array( 'edit' ),
			),
		) );
	}
} );

/** Normalize an ACPT license API error into Minn's action result contract. */
function minn_admin_acpt_license_error( $message ) {
	$message = trim( wp_strip_all_tags( (string) $message ) );
	$lower   = strtolower( $message );
	$code    = 'invalid';
	if ( false !== strpos( $lower, 'expired' ) ) {
		$code = 'expired';
	} elseif ( false !== strpos( $lower, 'limit' ) || false !== strpos( $lower, 'maximum' ) || false !== strpos( $lower, 'already active' ) || false !== strpos( $lower, 'activation left' ) ) {
		$code = 'site_limit';
	} elseif ( false !== strpos( $lower, 'server' ) || false !== strpos( $lower, 'response' ) || false !== strpos( $lower, 'timeout' ) || false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'curl' ) || false !== strpos( $lower, 'connect' ) || false !== strpos( $lower, 'resolve' ) ) {
		$code = 'error';
	}
	return array(
		'ok'      => false,
		'code'    => $code,
		'message' => $message ?: __( 'ACPT did not accept that license.', 'minn-admin' ),
	);
}

add_filter( 'minn_admin_license_providers', function ( $providers ) {
	$component = 'advanced-custom-post-type/advanced-custom-post-type.php';
	$providers['acpt'] = array(
		'name'      => 'ACPT',
		'component' => $component,
		'detect'    => function () use ( $component ) {
			return file_exists( WP_PLUGIN_DIR . '/' . $component );
		},
		'read'      => function () {
			if ( class_exists( '\\ACPT\\Admin\\ACPT_License_Manager' ) ) {
				$stored = \ACPT\Admin\ACPT_License_Manager::getLicense();
			} else {
				$stored = get_option( hash( 'ripemd128', 'acpt_license_active' ) );
			}
			$valid = is_array( $stored )
				&& ! empty( $stored['activation_id'] )
				&& ! empty( $stored['license'] )
				&& isset( $stored['site_name'], $stored['site_url'], $stored['user_email'], $stored['user_id'] );
			return array( array(
				'name'      => 'ACPT',
				'kind'      => 'plugin',
				'component' => 'advanced-custom-post-type/advanced-custom-post-type.php',
				'state'     => $valid ? 'valid' : 'missing',
				'key'       => $valid,
				'expires'   => '',
				'note'      => $valid ? __( 'Local ACPT activation record', 'minn-admin' ) : '',
			) );
		},
	);

	if ( class_exists( '\\ACPT\\Admin\\ACPT_License_Manager' ) && class_exists( '\\ACPT\\Utils\\Http\\ACPTApiClient' ) ) {
		$providers['acpt']['secret_fields'] = array(
			array( 'id' => 'license', 'label' => __( 'ACPT license code', 'minn-admin' ) ),
			array( 'id' => 'email', 'label' => __( 'Account email', 'minn-admin' ) ),
		);
		$providers['acpt']['activate'] = function ( $secrets ) {
			// ACPT's public activate() wrapper cannot be called safely because its
			// nonce check has the wrong signature. Keep the same vendor-owned path:
			// its API client first, then its public local-record writer on success.
			$license = sanitize_text_field( $secrets['license'] );
			$email   = sanitize_email( $secrets['email'] );
			if ( ! is_email( $email ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Enter the email address for the ACPT account.', 'minn-admin' ) );
			}
			$data = \ACPT\Utils\Http\ACPTApiClient::call( '/license/activate', array(
				'license'  => $license,
				'email'    => $email,
				'siteName' => sanitize_text_field( get_bloginfo( 'name' ) ),
				'siteUrl'  => get_bloginfo( 'url' ),
				'ip'       => class_exists( '\\ACPT\\Utils\\PHP\\IP' ) ? \ACPT\Utils\PHP\IP::getClientIP() : '',
			) );
			if ( ! is_array( $data ) ) {
				return minn_admin_acpt_license_error( __( 'ACPT returned no activation response.', 'minn-admin' ) );
			}
			if ( ! empty( $data['error'] ) ) {
				return minn_admin_acpt_license_error( $data['error'] );
			}
			if ( empty( $data['id'] ) || empty( $data['user_email'] ) || ! isset( $data['user_id'] ) ) {
				return minn_admin_acpt_license_error( __( 'ACPT returned an incomplete activation response.', 'minn-admin' ) );
			}
			$saved = \ACPT\Admin\ACPT_License_Manager::activateLicense(
				$data['id'],
				$license,
				get_bloginfo( 'name' ),
				get_bloginfo( 'url' ),
				$data['user_email'],
				$data['user_id']
			);
			return $saved
				? array( 'ok' => true, 'message' => __( 'ACPT activated.', 'minn-admin' ) )
				: array( 'ok' => false, 'code' => 'error', 'message' => __( 'ACPT accepted the license, but its local activation record could not be saved.', 'minn-admin' ) );
		};
		$providers['acpt']['deactivate'] = function () {
			$ok = \ACPT\Admin\ACPT_License_Manager::destroy();
			return $ok
				? array( 'ok' => true, 'message' => __( 'ACPT deactivated.', 'minn-admin' ) )
				: array( 'ok' => false, 'code' => 'error', 'message' => __( 'ACPT did not confirm deactivation.', 'minn-admin' ) );
		};
		if ( class_exists( '\\ACPT\\Core\\CQRS\\Query\\FetchLicenseQuery' ) ) {
			$providers['acpt']['verify'] = function () {
				if ( ! \ACPT\Admin\ACPT_License_Manager::isLicenseValid() ) {
					return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No ACPT activation is stored.', 'minn-admin' ) );
				}
				try {
					$result = ( new \ACPT\Core\CQRS\Query\FetchLicenseQuery() )->execute();
					return is_array( $result )
						? array( 'ok' => true, 'message' => __( 'ACPT verified the activation.', 'minn-admin' ) )
						: minn_admin_acpt_license_error( __( 'ACPT returned no verification response.', 'minn-admin' ) );
				} catch ( \Throwable $error ) {
					return minn_admin_acpt_license_error( $error->getMessage() );
				}
			};
		}
	}

	return $providers;
} );
