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
	// A phone number is a line of text to everything except the keyboard
	// a browser offers for it, so it round-trips like one.
	'Phone'    => 'text',
	// ACPT's own value API speaks attachment ids for these, which is what
	// the media picker hands back; the URL sibling it also stores is its
	// business, written by the same call.
	'Image'    => 'image',
);

/**
 * Types ACPT stores as a small object around the value a writer types, and
 * the key holding that value. A URL keeps a display label beside it and a
 * phone number an international dialling code; the control edits the part a
 * person types, and the rest is carried through untouched. Reading these as
 * plain values gave an empty box on a field that had content in it.
 */
const MINN_ADMIN_ACPT_STRUCTURED = array(
	'Phone' => 'value',
	'Url'   => 'url',
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

/** Map one ACPT field to Minn's panel vocabulary, or null when complex. */
function minn_admin_acpt_map_field( $field ) {
	if ( $field->getParentId() || $field->getBlockId() || $field->getForgedBy() ) {
		return null;
	}
	$type = $field->getType();
	if ( 'Repeater' !== $type && ! isset( MINN_ADMIN_ACPT_SIMPLE[ $type ] ) ) {
		return null;
	}
	$permissions = $field->userPermissions();
	if ( empty( $permissions['read'] ) || empty( $permissions['edit'] ) ) {
		return null;
	}
	if ( 'Repeater' === $type ) {
		return minn_admin_acpt_map_repeater( $field );
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

/**
 * Map an ACPT repeater onto the panel's `rows` control.
 *
 * One level deep, like the ACF repeater: sub-fields from the simple set edit
 * in row cards, anything else counts as locked and is PRESERVED by the write
 * path's row merge. A repeater whose every sub is unmappable is not offered
 * at all, since an empty row card would only be a place to lose data.
 *
 * @param object $field ACPT MetaFieldModel for the repeater.
 * @return array|null { name, label, type: 'rows', subfields, subLocked, subs }
 */
function minn_admin_acpt_map_repeater( $field ) {
	$subs   = array();
	$locked = 0;
	foreach ( $field->getChildren() as $child ) {
		$child_type = $child->getType();
		if ( ! isset( MINN_ADMIN_ACPT_SIMPLE[ $child_type ] ) || 'Repeater' === $child_type ) {
			++$locked;
			continue;
		}
		$perms = $child->userPermissions();
		if ( empty( $perms['read'] ) || empty( $perms['edit'] ) ) {
			++$locked;
			continue;
		}
		$sub = array(
			// Rows are keyed by the sub-field's own name in ACPT's storage,
			// not by its id, so that is what a row value must be addressed by.
			'name'  => $child->getName(),
			'label' => $child->getLabelOrName(),
			'type'  => MINN_ADMIN_ACPT_SIMPLE[ $child_type ],
		);
		if ( in_array( $child_type, array( 'Select', 'Radio', 'Checkbox' ), true ) ) {
			$choices = array();
			foreach ( $child->getOptions() as $option ) {
				$choices[ $option->getValue() ] = $option->getLabel();
			}
			if ( ! $choices ) {
				++$locked;
				continue;
			}
			$sub['choices'] = $choices;
		}
		$subs[] = $sub;
	}
	if ( ! $subs ) {
		return null;
	}
	return array(
		'name'      => $field->getId(),
		'label'     => $field->getLabelOrName(),
		'type'      => 'rows',
		'subfields' => $subs,
		'subLocked' => $locked,
		'subs'      => $subs,
	);
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

/**
 * One stored ACPT value → what the form control expects.
 *
 * Shared by the post panel and the option pages: both read through the same
 * vendor helper and hand the result to the same controls, so the coercion
 * belongs in one place rather than beside each caller.
 *
 * @param object $field ACPT MetaFieldModel.
 * @param mixed  $value Raw value from get_acpt_field.
 * @return mixed
 */
function minn_admin_acpt_value_out( $field, $value ) {
	$type = $field->getType();
	if ( 'Toggle' === $type ) {
		return ! empty( $value ) && 'false' !== $value && '0' !== (string) $value;
	}
	if ( 'Checkbox' === $type ) {
		return is_array( $value ) ? array_values( $value ) : ( '' === (string) $value ? array() : array( $value ) );
	}
	if ( 'Image' === $type ) {
		// ACPT answers with the attachment id; the picker wants to show the
		// picture it stands for.
		$att = (int) $value;
		if ( ! $att ) {
			return '';
		}
		$url = wp_get_attachment_image_url( $att, 'medium' );
		return array( 'id' => $att, 'url' => $url ? $url : wp_get_attachment_url( $att ) );
	}
	if ( 'Repeater' === $type ) {
		// ACPT hands back rows already in row order, keyed by sub name.
		$rows = array();
		foreach ( array_values( is_array( $value ) ? $value : array() ) as $i => $row ) {
			$rows[] = array(
				'__idx'  => $i,
				'values' => (object) ( is_array( $row ) ? $row : array() ),
			);
		}
		return $rows;
	}
	if ( isset( MINN_ADMIN_ACPT_STRUCTURED[ $type ] ) && ( is_array( $value ) || is_object( $value ) ) ) {
		$part = ( (array) $value )[ MINN_ADMIN_ACPT_STRUCTURED[ $type ] ] ?? '';
		return is_scalar( $part ) ? (string) $part : '';
	}
	// A field that has never been filled in reads back as null, which a text
	// control would render as the word "null"; empty is empty.
	if ( null === $value || is_array( $value ) || is_object( $value ) ) {
		return '';
	}
	return $value;
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
		$out[ $id ] = minn_admin_acpt_value_out( $field, $value );
	}
	return $out;
}

/**
 * The rows control's [{ __idx, values }] → the row list ACPT stores.
 *
 * `__idx` names the row a card came from, so a sub-field this panel does not
 * offer keeps whatever it already held: an edit overlays only the subs it
 * actually shows. A brand new row starts empty and takes only what was typed.
 *
 * @param object $field ACPT MetaFieldModel for the repeater.
 * @param mixed  $value Submitted rows.
 * @param array  $args  post_id / box_name / field_name for the read-back.
 * @return array|null Rows for ACPT, or null when the input is unusable.
 */
function minn_admin_acpt_rows_in( $field, $value, $args ) {
	if ( ! is_array( $value ) ) {
		return null;
	}
	$mapped = minn_admin_acpt_map_repeater( $field );
	if ( ! $mapped ) {
		return null;
	}
	$names = wp_list_pluck( $mapped['subs'], 'name' );
	$orig  = get_acpt_field( array_merge( $args, array( 'format' => 'only_value', 'return' => 'raw' ) ) );
	$orig  = is_array( $orig ) ? array_values( $orig ) : array();
	$rows  = array();
	foreach ( $value as $row ) {
		$row  = (array) $row;
		$vals = isset( $row['values'] ) ? (array) $row['values'] : array();
		$base = isset( $row['__idx'] ) && is_numeric( $row['__idx'] ) && isset( $orig[ (int) $row['__idx'] ] ) && is_array( $orig[ (int) $row['__idx'] ] )
			? $orig[ (int) $row['__idx'] ]
			: array();
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $vals ) ) {
				$base[ $name ] = is_scalar( $vals[ $name ] ) ? $vals[ $name ] : '';
			}
		}
		$rows[] = $base;
	}
	return $rows;
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
		minn_admin_acpt_write_one( $field, $value, array(
			'post_id'    => $post_id,
			'box_name'   => $field->getBox()->getName(),
			'field_name' => $field->getName(),
		) );
	}
}

/**
 * Write one field through ACPT's own setter.
 *
 * `$args` carries the context ACPT keys a value by, which is a post id for the
 * editor panel and a menu slug for an option page; everything about coercing
 * the value is the same either way, so both callers share this.
 *
 * @param object $field ACPT MetaFieldModel.
 * @param mixed  $value Submitted value.
 * @param array  $args  Context plus box_name / field_name.
 */
function minn_admin_acpt_write_one( $field, $value, $args ) {
	$type = $field->getType();
	if ( ( '' === $value || null === $value ) && ! in_array( $type, array( 'Checkbox', 'Repeater' ), true ) ) {
		delete_acpt_meta_field_value( $args );
		return;
	}
	if ( 'Toggle' === $type ) {
		$value = ! empty( $value ) && 'false' !== $value && '0' !== (string) $value;
	} elseif ( 'Checkbox' === $type ) {
		$value = is_array( $value ) ? array_values( array_map( 'sanitize_text_field', $value ) ) : array();
	} elseif ( 'Image' === $type ) {
		// The picker sends { id, url } (or a bare id); ACPT stores by id and
		// derives the URL itself. An id that is not an attachment is refused
		// rather than written, so a stray value cannot blank a picture that
		// is fine.
		$att = is_array( $value ) || is_object( $value )
			? (int) ( ( (array) $value )['id'] ?? 0 )
			: (int) $value;
		if ( $att < 1 || 'attachment' !== get_post_type( $att ) ) {
			return;
		}
		$value = $att;
	} elseif ( 'Url' === $type ) {
		// ACPT keeps a display label beside the address and, given only an
		// address, sets the label to match it. A label someone wrote is not
		// Minn's to overwrite, so it is passed back; one that merely mirrored
		// the old address follows the new one, which is what ACPT would do.
		$current   = get_acpt_field( array_merge( $args, array( 'format' => 'only_value', 'return' => 'raw' ) ) );
		$current   = is_array( $current ) ? $current : array();
		$old_url   = isset( $current['url'] ) ? (string) $current['url'] : '';
		$old_label = isset( $current['label'] ) ? (string) $current['label'] : '';
		$next      = (string) $value;
		$value     = array(
			'url'   => $next,
			'label' => ( '' !== $old_label && $old_label !== $old_url ) ? $old_label : $next,
		);
	} elseif ( 'Repeater' === $type ) {
		$value = minn_admin_acpt_rows_in( $field, $value, $args );
		if ( null === $value ) {
			return; // malformed input never clobbers stored rows
		}
	}
	$args['value'] = $value;
	save_acpt_meta_field_value( $args );
}

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

/* ===== ACPT option pages as settings-only surfaces ===== */

/**
 * Option pages the current user may manage.
 *
 * Each page carries its own capability, the one ACPT hands to add_menu_page,
 * so that is the gate Minn honors rather than inventing one. A page whose
 * capability is empty denies everyone in wp-admin (current_user_can('')), and
 * it has to read the same way here or Minn would grant what ACPT refuses.
 *
 * @return array Menu slug => option page model.
 */
function minn_admin_acpt_option_pages_allowed() {
	$out = array();
	if ( ! minn_admin_acpt_active() || ! class_exists( '\\ACPT\\Core\\Repository\\OptionPageRepository' ) ) {
		return $out;
	}
	try {
		$pages = \ACPT\Core\Repository\OptionPageRepository::get( array() );
	} catch ( \Throwable $e ) {
		return $out;
	}
	foreach ( (array) $pages as $page ) {
		$slug = method_exists( $page, 'getMenuSlug' ) ? (string) $page->getMenuSlug() : '';
		if ( '' === $slug ) {
			continue;
		}
		$cap = method_exists( $page, 'getCapability' ) ? (string) $page->getCapability() : '';
		if ( '' === $cap || ! current_user_can( $cap ) ) {
			continue;
		}
		$out[ $slug ] = $page;
	}
	return $out;
}

/** The meta groups attached to one option page, visibility rules honored. */
function minn_admin_acpt_option_groups_for( $slug ) {
	$groups = array();
	foreach ( \ACPT\Core\Repository\MetaRepository::get( array(
		'belongsTo' => \ACPT\Constants\MetaTypes::OPTION_PAGE,
		'find'      => $slug,
	) ) as $group ) {
		if ( $group->isVisible( array(
			'belongsTo' => \ACPT\Constants\MetaTypes::OPTION_PAGE,
			'find'      => $slug,
		) ) ) {
			$groups[] = $group;
		}
	}
	return $groups;
}

/**
 * One tab per meta box on the page: a box is the grouping ACPT's own screen
 * shows, so it is the one a reader already recognises. Ids are positional and
 * both reads and writes re-derive the same walk, so they stay stable for a
 * given schema.
 *
 * @param string $slug Option page menu slug.
 * @return array[] { id, label, fields (mapped), lookup (id => field model), locked }
 */
function minn_admin_acpt_option_tabs( $slug ) {
	$tabs = array();
	foreach ( minn_admin_acpt_option_groups_for( $slug ) as $group ) {
		foreach ( $group->getBoxes() as $box ) {
			$mapped = array();
			$lookup = array();
			$locked = 0;
			foreach ( $box->getFields() as $field ) {
				$simple = minn_admin_acpt_map_field( $field );
				if ( ! $simple ) {
					++$locked;
					continue;
				}
				$mapped[]                  = $simple;
				$lookup[ $field->getId() ] = $field;
			}
			if ( ! $mapped && ! $locked ) {
				continue;
			}
			$tabs[] = array(
				'id'     => 'tab-' . count( $tabs ),
				'label'  => $box->getUiName(),
				'fields' => $mapped,
				'lookup' => $lookup,
				'locked' => $locked,
			);
		}
	}
	return $tabs;
}

/** Read one option page field through ACPT's own value helper. */
function minn_admin_acpt_option_value( $slug, $field ) {
	$value = get_acpt_field( array(
		'option_page' => $slug,
		'box_name'    => $field->getBox()->getName(),
		'field_name'  => $field->getName(),
		'format'      => 'only_value',
		'return'      => 'raw',
	) );
	return minn_admin_acpt_value_out( $field, $value );
}

/** The settings payload for one tab: control descriptors plus their values. */
function minn_admin_acpt_option_tab_shape( $slug, $tab_id ) {
	$tab = null;
	foreach ( minn_admin_acpt_option_tabs( $slug ) as $t ) {
		if ( $t['id'] === $tab_id ) {
			$tab = $t;
			break;
		}
	}
	if ( ! $tab ) {
		return new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$fields = array();
	$values = array();
	foreach ( $tab['fields'] as $f ) {
		$sf = array(
			'name'  => $f['name'],
			'label' => $f['label'],
			'type'  => $f['type'],
		);
		foreach ( array( 'min', 'max', 'step', 'subfields', 'subLocked' ) as $extra ) {
			if ( isset( $f[ $extra ] ) ) {
				$sf[ $extra ] = $f[ $extra ];
			}
		}
		if ( isset( $f['choices'] ) ) {
			$sf['options'] = array();
			foreach ( (array) $f['choices'] as $value => $label ) {
				$sf['options'][] = array( (string) $value, (string) $label );
			}
		}
		$fields[] = $sf;
		$model    = isset( $tab['lookup'][ $f['name'] ] ) ? $tab['lookup'][ $f['name'] ] : null;
		if ( $model ) {
			$values[ $f['name'] ] = minn_admin_acpt_option_value( $slug, $model );
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
		'adminUrl' => admin_url( 'admin.php?page=' . rawurlencode( $slug ) ),
	);
}

/** Write edited option page values through ACPT's own setter. */
function minn_admin_acpt_option_save( $slug, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$lookup = array();
	foreach ( minn_admin_acpt_option_tabs( $slug ) as $t ) {
		$lookup += $t['lookup'];
	}
	foreach ( $values as $id => $value ) {
		if ( ! isset( $lookup[ $id ] ) ) {
			continue; // only ever the page's own mapped fields
		}
		$field = $lookup[ $id ];
		$args  = array(
			'option_page' => $slug,
			'box_name'    => $field->getBox()->getName(),
			'field_name'  => $field->getName(),
		);
		minn_admin_acpt_write_one( $field, $value, $args );
	}
}

// Option pages gather under the shared Site options item rather than each
// claiming a place in the sidebar; see adapters/option-pages.php.
add_filter( 'minn_admin_option_pages', function ( $pages ) {
	foreach ( minn_admin_acpt_option_pages_allowed() as $slug => $page ) {
		$tabs = minn_admin_acpt_option_tabs( $slug );
		if ( ! $tabs ) {
			continue; // a page with no fields is a menu entry, not a tab
		}
		$tab_list = array();
		foreach ( $tabs as $t ) {
			$tab_list[] = array( 'id' => $t['id'], 'label' => $t['label'] );
		}
		$pages[] = array(
			'id'     => 'acpt:' . $slug,
			'label'  => method_exists( $page, 'getMenuTitle' ) ? $page->getMenuTitle() : $slug,
			'source' => 'ACPT',
			'cap'    => method_exists( $page, 'getCapability' ) ? (string) $page->getCapability() : 'manage_options',
			'tabs'   => $tab_list,
			'route'  => 'minn-admin/v1/acpt/options/' . rawurlencode( $slug ) . '/{tab}',
		);
	}
	return $pages;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acpt_active() ) {
		return;
	}
	$resolve = function ( $req ) {
		$slug  = rawurldecode( (string) $req['page'] );
		$pages = minn_admin_acpt_option_pages_allowed();
		return isset( $pages[ $slug ] ) ? $slug : null;
	};
	register_rest_route( 'minn-admin/v1', '/acpt/options/(?P<page>[A-Za-z0-9_%.\-]+)/(?P<tab>tab-\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req ); // the page's own capability decided this
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				return rest_ensure_response( minn_admin_acpt_option_tab_shape( $resolve( $req ), (string) $req['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req );
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				$slug = $resolve( $req );
				$body = $req->get_json_params();
				minn_admin_acpt_option_save( $slug, isset( $body['values'] ) ? $body['values'] : array() );
				return rest_ensure_response( minn_admin_acpt_option_tab_shape( $slug, (string) $req['tab'] ) );
			},
		),
	) );
} );

/* ===== ACPT field groups on the shared Field groups item ===== */

/** Where one ACPT group is attached, in words. */
function minn_admin_acpt_group_applies_to( $group ) {
	$labels = array();
	foreach ( (array) $group->getBelongs() as $belong ) {
		$kind = method_exists( $belong, 'getBelongsTo' ) ? (string) $belong->getBelongsTo() : '';
		$find = method_exists( $belong, 'getFind' ) ? (string) $belong->getFind() : '';
		if ( '' === $find ) {
			continue;
		}
		switch ( $kind ) {
			case \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE:
				$obj      = get_post_type_object( $find );
				$labels[] = $obj && ! empty( $obj->labels->name ) ? $obj->labels->name : $find;
				break;
			case \ACPT\Constants\MetaTypes::OPTION_PAGE:
				$pages    = minn_admin_acpt_option_pages_allowed();
				$labels[] = isset( $pages[ $find ] ) && method_exists( $pages[ $find ], 'getMenuTitle' )
					? $pages[ $find ]->getMenuTitle()
					: $find;
				break;
			case \ACPT\Constants\MetaTypes::TAXONOMY:
				$tax      = get_taxonomy( $find );
				$labels[] = $tax && ! empty( $tax->labels->name ) ? $tax->labels->name : $find;
				break;
			case \ACPT\Constants\MetaTypes::USER:
				$labels[] = __( 'Users', 'minn-admin' );
				break;
			default:
				$labels[] = $find;
		}
	}
	return $labels ? implode( ', ', array_unique( $labels ) ) : '';
}

/** One row per ACPT field group. */
function minn_admin_acpt_group_rows() {
	$rows = array();
	foreach ( \ACPT\Core\Repository\MetaRepository::get( array() ) as $group ) {
		$boxes  = (array) $group->getBoxes();
		$fields = 0;
		foreach ( $boxes as $box ) {
			$fields += count( (array) $box->getFields() );
		}
		$rows[] = array(
			'id'       => method_exists( $group, 'getId' ) ? (string) $group->getId() : (string) $group->getName(),
			'name'     => method_exists( $group, 'getUIName' ) ? $group->getUIName() : $group->getName(),
			'applies'  => minn_admin_acpt_group_applies_to( $group ),
			'boxes'    => count( $boxes ),
			'fields'   => $fields,
			// ACPT's schema builder is its own multi-step canvas, so the row
			// points at the group there rather than pretending to rebuild it.
			'editUrl'  => minn_admin_acpt_admin_url( 'meta' ),
		);
	}
	usort( $rows, function ( $a, $b ) {
		return strcasecmp( (string) $a['name'], (string) $b['name'] );
	} );
	return $rows;
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acpt_active() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/acpt/groups', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_acpt_can_manage',
		'callback'            => function ( WP_REST_Request $request ) {
			$rows = minn_admin_acpt_group_rows();
			$q    = strtolower( trim( (string) $request->get_param( 'q' ) ) );
			if ( '' !== $q ) {
				$rows = array_values( array_filter( $rows, function ( $row ) use ( $q ) {
					return false !== strpos( strtolower( $row['name'] . ' ' . $row['applies'] ), $q );
				} ) );
			}
			return rest_ensure_response( array( 'items' => $rows, 'total' => count( $rows ) ) );
		},
	) );
} );

add_filter( 'minn_admin_field_group_sources', function ( $sources ) {
	if ( ! minn_admin_acpt_active() || ! minn_admin_acpt_can_manage() ) {
		return $sources;
	}
	$sources[] = array(
		'id'         => 'acpt',
		'label'      => 'ACPT',
		'cap'        => 'manage_options',
		'collection' => array(
			'viewLabel' => 'ACPT',
			// Rows open the shared group builder page on its ACPT backend
			// (collection.open — a group carries a whole workflow).
			'open'      => array( 'route' => 'field-groups/acpt/{id}' ),
			'route'     => 'minn-admin/v1/acpt/groups',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => true,
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Group', 'minn-admin' ) ),
				array( 'key' => 'applies', 'label' => __( 'Applies to', 'minn-admin' ) ),
				array( 'key' => 'boxes', 'label' => __( 'Boxes', 'minn-admin' ), 'width' => 90, 'format' => 'num' ),
				array( 'key' => 'fields', 'label' => __( 'Fields', 'minn-admin' ), 'width' => 90, 'format' => 'num' ),
			),
			'actions'   => array(
				// The row opens Minn's builder; ACPT's own canvas stays one
				// click away for everything the builder does not model.
				array( 'label' => __( 'Edit in ACPT', 'minn-admin' ), 'href' => '{editUrl}' ),
			),
		),
	);
	return $sources;
} );

/* ===== ACPT group builder: the shared schema canvas, ACPT backend ===== */

/**
 * The field types Minn's builder can create and configure for ACPT, keyed by
 * the builder's shared type ids. `settings` names the extra knobs beyond the
 * universal label / name / instructions / required / default set — ACPT keeps
 * min / max / step in a field's advanced options and its choice lists in
 * option rows, and has no placeholder storage at all, so the vocabulary is
 * narrower than ACF's.
 *
 * @return array id => { acpt: MetaFieldModel type, settings: string[] }
 */
function minn_admin_acpt_builder_types() {
	return array(
		'text'         => array( 'acpt' => 'Text', 'settings' => array() ),
		'textarea'     => array( 'acpt' => 'Textarea', 'settings' => array() ),
		'number'       => array( 'acpt' => 'Number', 'settings' => array( 'min', 'max', 'step' ) ),
		'range'        => array( 'acpt' => 'Range', 'settings' => array( 'min', 'max', 'step' ) ),
		'email'        => array( 'acpt' => 'Email', 'settings' => array() ),
		'url'          => array( 'acpt' => 'Url', 'settings' => array() ),
		'select'       => array( 'acpt' => 'Select', 'settings' => array( 'choices' ) ),
		'radio'        => array( 'acpt' => 'Radio', 'settings' => array( 'choices' ) ),
		'multicheck'   => array( 'acpt' => 'Checkbox', 'settings' => array( 'choices' ) ),
		'true_false'   => array( 'acpt' => 'Toggle', 'settings' => array() ),
		'color_picker' => array( 'acpt' => 'Color', 'settings' => array() ),
		'date'         => array( 'acpt' => 'Date', 'settings' => array() ),
		'datetime'     => array( 'acpt' => 'DateTime', 'settings' => array() ),
		'time'         => array( 'acpt' => 'Time', 'settings' => array() ),
		'phone'        => array( 'acpt' => 'Phone', 'settings' => array() ),
		'image'        => array( 'acpt' => 'Image', 'settings' => array() ),
		'repeater'     => array( 'acpt' => 'Repeater', 'settings' => array() ),
	);
}

/** Builder id for one ACPT field type, or '' when the builder can't edit it. */
function minn_admin_acpt_builder_id_of( $acpt_type ) {
	foreach ( minn_admin_acpt_builder_types() as $id => $def ) {
		if ( $def['acpt'] === $acpt_type ) {
			return $id;
		}
	}
	return '';
}

/** ACPT option rows → the builder's "value : Label" lines. */
function minn_admin_acpt_builder_choices_out( $options ) {
	$lines = array();
	foreach ( (array) $options as $option ) {
		$value = (string) ( $option['value'] ?? '' );
		$label = (string) ( $option['label'] ?? '' );
		$lines[] = ( $value === $label || '' === $label ) ? $value : $value . ' : ' . $label;
	}
	return implode( "\n", $lines );
}

/**
 * "value : Label" lines → ACPT option rows, ids preserved by value so a
 * relabel or reorder never orphans stored selections.
 *
 * @param string $lines  Submitted choices text.
 * @param array  $stored Stored option rows for this field (may be empty).
 * @return array Option rows for fullHydrateFromArray.
 */
function minn_admin_acpt_builder_choices_in( $lines, $stored ) {
	$by_value = array();
	foreach ( (array) $stored as $option ) {
		$by_value[ (string) ( $option['value'] ?? '' ) ] = $option;
	}
	$out = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $lines ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = preg_split( '/\s*:\s*/', $line, 2 );
		$value = $parts[0];
		$label = isset( $parts[1] ) && '' !== $parts[1] ? $parts[1] : $value;
		$row   = array( 'label' => $label, 'value' => $value, 'isDefault' => false );
		if ( isset( $by_value[ $value ] ) ) {
			$row['id']        = $by_value[ $value ]['id'] ?? null;
			$row['isDefault'] = ! empty( $by_value[ $value ]['isDefault'] );
			if ( null === $row['id'] ) {
				unset( $row['id'] );
			}
		}
		$out[] = $row;
	}
	return $out;
}

/** One advanced-option value from a field's serialized advanced options. */
function minn_admin_acpt_builder_adv_out( $field_json, $key ) {
	foreach ( (array) ( $field_json['advancedOptions'] ?? array() ) as $option ) {
		if ( ( $option['key'] ?? '' ) === $key && '' !== (string) ( $option['value'] ?? '' ) ) {
			return (string) $option['value'];
		}
	}
	return '';
}

/**
 * One stored field (its jsonSerialize array) → a builder row.
 *
 * Types outside the map, dataset-forged fields and block children list as
 * read-only rows: identity, order and label stay editable, configuration
 * stays ACPT's. A repeater nests one level, so a repeater met below the box
 * level also locks.
 *
 * @param array $field_json Field jsonSerialize array.
 * @param int   $depth      0 directly in a box, +1 per repeater.
 * @return array
 */
function minn_admin_acpt_builder_field_out( $field_json, $depth = 0 ) {
	$bid  = minn_admin_acpt_builder_id_of( (string) ( $field_json['type'] ?? '' ) );
	$edit = '' !== $bid && empty( $field_json['forgedBy'] ) && empty( $field_json['blockId'] );
	if ( 'repeater' === $bid && $depth > 0 ) {
		$edit = false;
	}
	$children = array();
	foreach ( (array) ( $field_json['children'] ?? array() ) as $child ) {
		$children[] = minn_admin_acpt_builder_field_out( $child, $depth + 1 );
	}
	$default = $field_json['defaultValue'] ?? '';
	$out     = array(
		'key'           => (string) $field_json['id'],
		'label'         => (string) ( $field_json['label'] ?? '' ),
		'name'          => (string) ( $field_json['name'] ?? '' ),
		'type'          => '' !== $bid ? $bid : (string) ( $field_json['type'] ?? '' ),
		'editable'      => $edit,
		'required'      => ! empty( $field_json['isRequired'] ),
		'instructions'  => (string) ( $field_json['description'] ?? '' ),
		'default_value' => is_scalar( $default ) ? (string) $default : '',
		'placeholder'   => '',
		'choices'       => minn_admin_acpt_builder_choices_out( $field_json['options'] ?? array() ),
		'min'           => minn_admin_acpt_builder_adv_out( $field_json, 'min' ),
		'max'           => minn_admin_acpt_builder_adv_out( $field_json, 'max' ),
		'step'          => minn_admin_acpt_builder_adv_out( $field_json, 'step' ),
		'rows'          => '',
		'ui_on_text'    => '',
		'ui_off_text'   => '',
		'button_label'  => '',
		'subCount'      => count( $children ),
	);
	if ( 'repeater' === $bid && $edit ) {
		$out['sub_fields'] = $children;
	}
	return $out;
}

/** Fields of one serialized box that are its own (not repeater/block children). */
function minn_admin_acpt_builder_box_fields( $box_json ) {
	$out = array();
	foreach ( (array) ( $box_json['fields'] ?? array() ) as $field ) {
		if ( ! empty( $field['parentId'] ) || ! empty( $field['blockId'] ) ) {
			continue;
		}
		$out[] = $field;
	}
	return $out;
}

/**
 * Stored belongs chain → the builder's OR sets of AND rows. ACPT stores a
 * flat chain where each row's own logic joins it to the next and an OR ends
 * a block (Logics::extractLogicBlocks), which is the same shape read the
 * other way round. Rows the catalog covers edit; anything else (user / media
 * targets, IN lists) renders read-only and re-saves verbatim by its id.
 */
function minn_admin_acpt_builder_location_out( $belongs ) {
	$map    = array(
		\ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE => 'post_type',
		\ACPT\Constants\MetaTypes::TAXONOMY         => 'taxonomy',
		\ACPT\Constants\MetaTypes::OPTION_PAGE      => 'option_page',
	);
	$groups  = array();
	$current = array();
	$total   = count( (array) $belongs );
	foreach ( array_values( (array) $belongs ) as $i => $b ) {
		$kind     = (string) ( $b['belongsTo'] ?? '' );
		$operator = (string) ( $b['operator'] ?? '' );
		$find     = (string) ( $b['find'] ?? '' );
		$simple   = isset( $map[ $kind ] ) && in_array( $operator, array( '=', '!=' ), true ) && false === strpos( $find, ',' );
		$rule     = $simple
			? array(
				'param'    => $map[ $kind ],
				'operator' => '=' === $operator ? '==' : '!=',
				'value'    => $find,
				'id'       => (string) ( $b['id'] ?? '' ),
			)
			: array(
				'param'    => $kind,
				'operator' => '' !== $operator ? $operator : '·',
				'value'    => $find,
				'id'       => (string) ( $b['id'] ?? '' ),
			);
		$current[] = $rule;
		$is_last   = $i === $total - 1;
		if ( $is_last || 'OR' === (string) ( $b['logic'] ?? '' ) ) {
			$groups[]  = $current;
			$current   = array();
		}
	}
	return $groups;
}

/** The location vocabulary the builder edits for ACPT, resolved live. */
function minn_admin_acpt_builder_location_choices() {
	$out = array();
	$pt  = array();
	foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $obj ) {
		if ( preg_match( '/^(acf-|wp_|edd_|elementor_)/', $obj->name ) || 'attachment' === $obj->name ) {
			continue;
		}
		$pt[] = array( $obj->name, $obj->labels->name );
	}
	$out['post_type'] = array( 'label' => __( 'Post type', 'minn-admin' ), 'values' => $pt );
	$tax = array();
	foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $obj ) {
		$tax[] = array( $obj->name, $obj->labels->name );
	}
	if ( $tax ) {
		$out['taxonomy'] = array( 'label' => __( 'Taxonomy', 'minn-admin' ), 'values' => $tax );
	}
	$pages = array();
	foreach ( minn_admin_acpt_option_pages_allowed() as $slug => $page ) {
		$pages[] = array( $slug, method_exists( $page, 'getMenuTitle' ) ? $page->getMenuTitle() : $slug );
	}
	if ( $pages ) {
		$out['option_page'] = array( 'label' => __( 'Options page', 'minn-admin' ), 'values' => $pages );
	}
	return $out;
}

/** Resolve one group id to its model, or null. */
function minn_admin_acpt_builder_group( $id ) {
	try {
		$found = \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $id ) );
	} catch ( \Throwable $e ) {
		return null;
	}
	return ! empty( $found ) ? $found[0] : null;
}

/** The whole GET …/full payload for one group model. */
function minn_admin_acpt_builder_payload( $group ) {
	// jsonSerialize is the faithful shape ACPT's own canvas round-trips —
	// arrayRepresentation drops keys (quickEdit, rule messages) that a
	// re-save through the vendor command would then silently reset.
	$g    = json_decode( wp_json_encode( $group ), true );
	$rows = array();
	foreach ( (array) ( $g['boxes'] ?? array() ) as $box ) {
		$fields = array();
		foreach ( minn_admin_acpt_builder_box_fields( $box ) as $field ) {
			$fields[] = minn_admin_acpt_builder_field_out( $field );
		}
		$rows[] = array(
			'key'        => (string) $box['id'],
			'label'      => (string) ( $box['label'] ?? '' ),
			'name'       => (string) ( $box['name'] ?? '' ),
			'type'       => 'box',
			'editable'   => true,
			'required'   => false,
			'sub_fields' => $fields,
			'subCount'   => count( $fields ),
		);
	}
	$settings = array( 'box' => array() );
	foreach ( minn_admin_acpt_builder_types() as $id => $def ) {
		$settings[ $id ] = $def['settings'];
	}
	return array(
		'group'           => array(
			'key'           => (string) $g['id'],
			'title'         => (string) ( '' !== (string) ( $g['label'] ?? '' ) ? $g['label'] : $g['name'] ),
			// No 'active' key: ACPT groups have no on/off flag, so the
			// builder never renders the switch.
			'source'        => 'db',
			'location'      => minn_admin_acpt_builder_location_out( $g['belongs'] ?? array() ),
			'locationLabel' => minn_admin_acpt_group_applies_to( $group ),
			'adminUrl'      => minn_admin_acpt_admin_url( 'meta' ),
		),
		'fields'          => $rows,
		'types'           => array_keys( minn_admin_acpt_builder_types() ),
		'typeSettings'    => $settings,
		'rootType'        => 'box',
		'locationChoices' => minn_admin_acpt_builder_location_choices(),
	);
}

/**
 * Rebuild a field's advanced options from submitted min / max / step,
 * keeping every other advanced option (and the ids of the three when they
 * already existed) exactly as stored.
 *
 * @param array $row    Submitted builder row.
 * @param array $stored Stored advancedOptions rows.
 * @return array|WP_Error
 */
function minn_admin_acpt_builder_adv_in( $row, $stored ) {
	$out   = array();
	$by_key = array();
	foreach ( (array) $stored as $option ) {
		$key = (string) ( $option['key'] ?? '' );
		if ( in_array( $key, array( 'min', 'max', 'step' ), true ) ) {
			$by_key[ $key ] = $option;
			continue;
		}
		$out[] = $option;
	}
	foreach ( array( 'min', 'max', 'step' ) as $key ) {
		$value = trim( (string) ( $row[ $key ] ?? '' ) );
		if ( '' === $value ) {
			continue;
		}
		if ( ! is_numeric( $value ) ) {
			return new WP_Error( 'minn_bad_number', sprintf(
				/* translators: 1: setting name (min, max, step), 2: field name. */
				__( '“%1$s” on “%2$s” must be a number.', 'minn-admin' ),
				$key,
				(string) ( $row['name'] ?? $row['key'] ?? '' )
			), array( 'status' => 400 ) );
		}
		$entry = array( 'key' => $key, 'value' => $value );
		if ( isset( $by_key[ $key ]['id'] ) ) {
			$entry['id'] = $by_key[ $key ]['id'];
		}
		$out[] = $entry;
	}
	return $out;
}

/**
 * Resolve one submitted field row against the stored fields of its list.
 *
 * Existing keys keep their stored array whole (name, type, permissions,
 * visibility conditions, everything the builder does not model) and overlay
 * only the settings the builder edits; unsupported types overlay label
 * alone. New rows must carry a supported type, a usable name and, for
 * choice types, at least one choice. Anything wrong refuses the WHOLE save:
 * ACPT's own command silently discards malformed rows and then deletes the
 * fields they stood for, so nothing may reach it unvalidated.
 *
 * @param array $row    Submitted row.
 * @param array $stored id => stored field jsonSerialize array for this list.
 * @param array $names  Names already claimed in this list (by reference).
 * @param int   $depth  0 directly in a box, +1 per repeater.
 * @return array|WP_Error
 */
function minn_admin_acpt_builder_plan_field( $row, $stored, &$names, $depth ) {
	$types = minn_admin_acpt_builder_types();
	$row   = (array) $row;
	$key   = isset( $row['key'] ) ? (string) $row['key'] : '';

	if ( '' !== $key ) {
		if ( ! isset( $stored[ $key ] ) ) {
			return new WP_Error( 'minn_unknown_field', __( 'A submitted field does not exist in this group — reload and try again.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$base = $stored[ $key ];
		$bid  = minn_admin_acpt_builder_id_of( (string) ( $base['type'] ?? '' ) );
		$edit = '' !== $bid && empty( $base['forgedBy'] ) && empty( $base['blockId'] ) && ! ( 'repeater' === $bid && $depth > 0 );
		$base['label'] = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
		$names[]       = strtolower( (string) $base['name'] );
		if ( ! $edit ) {
			return $base;
		}
		$base['description'] = sanitize_textarea_field( (string) ( $row['instructions'] ?? '' ) );
		$base['isRequired']  = ! empty( $row['required'] );
		$default             = $row['default_value'] ?? '';
		$base['defaultValue'] = is_scalar( $default ) ? (string) $default : '';
		if ( in_array( 'choices', $types[ $bid ]['settings'], true ) ) {
			$choices = minn_admin_acpt_builder_choices_in( $row['choices'] ?? '', $base['options'] ?? array() );
			if ( ! $choices ) {
				return new WP_Error( 'minn_no_choices', sprintf(
					/* translators: %s: field name. */
					__( '“%s” needs at least one choice.', 'minn-admin' ),
					(string) $base['name']
				), array( 'status' => 400 ) );
			}
			$base['options'] = $choices;
		}
		if ( in_array( 'min', $types[ $bid ]['settings'], true ) ) {
			$adv = minn_admin_acpt_builder_adv_in( $row, $base['advancedOptions'] ?? array() );
			if ( is_wp_error( $adv ) ) {
				return $adv;
			}
			$base['advancedOptions'] = $adv;
		}
		if ( 'repeater' === $bid ) {
			$children = minn_admin_acpt_builder_plan_list( $row['sub_fields'] ?? array(), $base['children'] ?? array(), $depth + 1 );
			if ( is_wp_error( $children ) ) {
				return $children;
			}
			$base['children'] = $children;
		}
		return $base;
	}

	// New field.
	$bid = (string) ( $row['type'] ?? '' );
	if ( ! isset( $types[ $bid ] ) || ( 'repeater' === $bid && $depth > 0 ) ) {
		return new WP_Error( 'minn_bad_type', __( 'That field type can’t be created here.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$name = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
	if ( '' === $name || ! preg_match( '/^[a-z0-9_\-]+$/', $name ) ) {
		return new WP_Error( 'minn_bad_name', __( 'Every new field needs a name: letters, numbers and underscores.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( in_array( $name, $names, true ) ) {
		return new WP_Error( 'minn_dup_name', sprintf(
			/* translators: %s: field name. */
			__( 'Two fields here would share the name “%s”.', 'minn-admin' ),
			$name
		), array( 'status' => 400 ) );
	}
	$names[] = $name;
	$new     = array(
		'name'        => $name,
		'label'       => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
		'type'        => $types[ $bid ]['acpt'],
		'description' => sanitize_textarea_field( (string) ( $row['instructions'] ?? '' ) ),
		'isRequired'  => ! empty( $row['required'] ),
	);
	$default = $row['default_value'] ?? '';
	if ( is_scalar( $default ) && '' !== (string) $default ) {
		$new['defaultValue'] = (string) $default;
	}
	if ( in_array( 'choices', $types[ $bid ]['settings'], true ) ) {
		$choices = minn_admin_acpt_builder_choices_in( $row['choices'] ?? '', array() );
		if ( ! $choices ) {
			return new WP_Error( 'minn_no_choices', sprintf(
				/* translators: %s: field name. */
				__( '“%s” needs at least one choice.', 'minn-admin' ),
				$name
			), array( 'status' => 400 ) );
		}
		$new['options'] = $choices;
	}
	if ( in_array( 'min', $types[ $bid ]['settings'], true ) ) {
		$adv = minn_admin_acpt_builder_adv_in( $row, array() );
		if ( is_wp_error( $adv ) ) {
			return $adv;
		}
		if ( $adv ) {
			$new['advancedOptions'] = $adv;
		}
	}
	if ( 'repeater' === $bid ) {
		$children = minn_admin_acpt_builder_plan_list( $row['sub_fields'] ?? array(), array(), $depth + 1 );
		if ( is_wp_error( $children ) ) {
			return $children;
		}
		$new['children'] = $children;
	}
	return $new;
}

/**
 * Resolve one submitted field list (a box's fields or a repeater's subs).
 *
 * @param mixed $rows          Submitted rows.
 * @param array $stored_fields Stored field jsonSerialize arrays for the list.
 * @param int   $depth         0 directly in a box, +1 per repeater.
 * @return array|WP_Error
 */
function minn_admin_acpt_builder_plan_list( $rows, $stored_fields, $depth ) {
	$stored = array();
	foreach ( (array) $stored_fields as $field ) {
		if ( isset( $field['id'] ) ) {
			$stored[ (string) $field['id'] ] = $field;
		}
	}
	$out   = array();
	$names = array();
	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) && ! is_object( $row ) ) {
			return new WP_Error( 'minn_bad_row', __( 'Malformed field row.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$planned = minn_admin_acpt_builder_plan_field( (array) $row, $stored, $names, $depth );
		if ( is_wp_error( $planned ) ) {
			return $planned;
		}
		$out[] = $planned;
	}
	return $out;
}

/**
 * The builder's OR sets of AND rows → ACPT's flat belongs chain. Rows on
 * catalog params write belongsTo / operator / find; rows off it must name a
 * stored belong by id and re-save it verbatim. Within a set rows join with
 * AND; the row that closes a set carries OR, which is exactly how ACPT's
 * own Logics::extractLogicBlocks reads the chain back.
 *
 * @param mixed $location       Submitted location groups.
 * @param array $stored_belongs Stored belongs jsonSerialize arrays.
 * @return array|WP_Error
 */
function minn_admin_acpt_builder_belongs_in( $location, $stored_belongs ) {
	$map = array(
		'post_type'   => \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE,
		'taxonomy'    => \ACPT\Constants\MetaTypes::TAXONOMY,
		'option_page' => \ACPT\Constants\MetaTypes::OPTION_PAGE,
	);
	$by_id = array();
	foreach ( (array) $stored_belongs as $belong ) {
		if ( isset( $belong['id'] ) ) {
			$by_id[ (string) $belong['id'] ] = $belong;
		}
	}
	$choices = minn_admin_acpt_builder_location_choices();
	$out     = array();
	foreach ( (array) $location as $rules ) {
		$rules = array_values( (array) $rules );
		$count = count( $rules );
		foreach ( $rules as $i => $rule ) {
			$rule  = (array) $rule;
			$param = (string) ( $rule['param'] ?? '' );
			$logic = ( $i === $count - 1 ) ? 'OR' : 'AND';
			if ( isset( $map[ $param ] ) ) {
				$operator = '!=' === (string) ( $rule['operator'] ?? '' ) ? '!=' : '=';
				$value    = (string) ( $rule['value'] ?? '' );
				$allowed  = wp_list_pluck( $choices[ $param ]['values'] ?? array(), 0 );
				if ( ! in_array( $value, array_map( 'strval', $allowed ), true ) ) {
					return new WP_Error( 'minn_bad_location', sprintf(
						/* translators: %s: submitted location value. */
						__( '“%s” isn’t an available location choice.', 'minn-admin' ),
						$value
					), array( 'status' => 400 ) );
				}
				$entry = array(
					'belongsTo' => $map[ $param ],
					'operator'  => $operator,
					'find'      => $value,
					'logic'     => $logic,
				);
				$id = (string) ( $rule['id'] ?? '' );
				if ( '' !== $id && isset( $by_id[ $id ] ) ) {
					$entry['id'] = $id;
				}
				$out[] = $entry;
				continue;
			}
			// Off-catalog rules only ever re-save what is already stored.
			$id = (string) ( $rule['id'] ?? '' );
			if ( '' === $id || ! isset( $by_id[ $id ] ) ) {
				return new WP_Error( 'minn_bad_location', __( 'A location rule of that kind can only be edited in ACPT.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$stored          = $by_id[ $id ];
			$stored['logic'] = $logic;
			$out[]           = $stored;
		}
	}
	return $out;
}

/**
 * Validate the whole submitted group and write it through ACPT's own
 * SaveMetaGroupCommand — the same transactional whole-group save its React
 * canvas uses, orphan removal included, so omitted rows delete exactly as
 * they would there. Validation happens BEFORE the command is built and any
 * refusal writes nothing.
 *
 * @param object $group Stored MetaGroupModel.
 * @param array  $body  Submitted { title, location, fields }.
 * @return true|WP_Error
 */
function minn_admin_acpt_builder_save( $group, $body ) {
	if ( ! class_exists( '\\ACPT\\Core\\CQRS\\Command\\SaveMetaGroupCommand' ) ) {
		return new WP_Error( 'minn_no_command', __( 'This ACPT build has no group save command.', 'minn-admin' ), array( 'status' => 501 ) );
	}
	$g          = json_decode( wp_json_encode( $group ), true );
	$stored_box = array();
	foreach ( (array) ( $g['boxes'] ?? array() ) as $box ) {
		$stored_box[ (string) $box['id'] ] = $box;
	}
	$boxes     = array();
	$box_names = array();
	foreach ( (array) ( $body['fields'] ?? array() ) as $row ) {
		$row = (array) $row;
		$key = isset( $row['key'] ) ? (string) $row['key'] : '';
		if ( '' !== $key ) {
			if ( ! isset( $stored_box[ $key ] ) ) {
				return new WP_Error( 'minn_unknown_box', __( 'A submitted box does not exist in this group — reload and try again.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$base          = $stored_box[ $key ];
			$base['label'] = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$fields        = minn_admin_acpt_builder_plan_list( $row['sub_fields'] ?? array(), minn_admin_acpt_builder_box_fields( $base ), 0 );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}
			$base['fields'] = $fields;
			$box_names[]    = strtolower( (string) $base['name'] );
			$boxes[]        = $base;
			continue;
		}
		if ( 'box' !== (string) ( $row['type'] ?? '' ) ) {
			return new WP_Error( 'minn_bad_row', __( 'Top-level rows in an ACPT group are boxes.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$name = strtolower( trim( (string) ( $row['name'] ?? '' ) ) );
		if ( '' === $name || ! preg_match( '/^[a-z0-9_\-]+$/', $name ) ) {
			return new WP_Error( 'minn_bad_name', __( 'Every new box needs a name: letters, numbers and underscores.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( in_array( $name, $box_names, true ) ) {
			return new WP_Error( 'minn_dup_name', sprintf(
				/* translators: %s: box name. */
				__( 'Two boxes here would share the name “%s”.', 'minn-admin' ),
				$name
			), array( 'status' => 400 ) );
		}
		$box_names[] = $name;
		$fields      = minn_admin_acpt_builder_plan_list( $row['sub_fields'] ?? array(), array(), 0 );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$boxes[] = array(
			'name'   => $name,
			'label'  => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			'fields' => $fields,
		);
	}
	$belongs = minn_admin_acpt_builder_belongs_in( $body['location'] ?? array(), $g['belongs'] ?? array() );
	if ( is_wp_error( $belongs ) ) {
		return $belongs;
	}
	$title = sanitize_text_field( (string) ( $body['title'] ?? '' ) );
	$data  = array(
		'id'       => (string) $g['id'],
		'name'     => (string) $g['name'],
		'label'    => '' !== $title ? $title : null,
		'display'  => (string) ( $g['display'] ?? '' ),
		'context'  => (string) ( $g['context'] ?? '' ),
		'priority' => (string) ( $g['priority'] ?? '' ),
		'belongs'  => $belongs,
		'boxes'    => $boxes,
	);
	try {
		$command = new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( $data );
		$command->execute();
	} catch ( \Throwable $e ) {
		return new WP_Error( 'minn_acpt_save', wp_strip_all_tags( $e->getMessage() ), array( 'status' => 400 ) );
	}
	return true;
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acpt_active() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/acpt/schema/groups/(?P<id>[A-Za-z0-9_\-]+)/full', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_acpt_can_manage',
			'callback'            => function ( WP_REST_Request $request ) {
				$group = minn_admin_acpt_builder_group( (string) $request['id'] );
				if ( ! $group ) {
					return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_acpt_builder_payload( $group ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => 'minn_admin_acpt_can_manage',
			'callback'            => function ( WP_REST_Request $request ) {
				$group = minn_admin_acpt_builder_group( (string) $request['id'] );
				if ( ! $group ) {
					return new WP_Error( 'not_found', __( 'Field group not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$saved = minn_admin_acpt_builder_save( $group, (array) $request->get_json_params() );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$fresh = minn_admin_acpt_builder_group( (string) $request['id'] );
				if ( ! $fresh ) {
					return new WP_Error( 'minn_acpt_save', __( 'The group saved but could not be read back.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( minn_admin_acpt_builder_payload( $fresh ) );
			},
		),
	) );
} );
