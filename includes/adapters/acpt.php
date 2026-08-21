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
		$type = $field->getType();
		if ( 'Toggle' === $type ) {
			$value = ! empty( $value ) && 'false' !== $value && '0' !== (string) $value;
		} elseif ( 'Checkbox' === $type ) {
			$value = is_array( $value ) ? array_values( $value ) : ( '' === (string) $value ? array() : array( $value ) );
		} elseif ( 'Image' === $type ) {
			// ACPT answers with the attachment id; the picker wants to show
			// the picture it stands for.
			$att   = (int) $value;
			$url   = $att ? wp_get_attachment_image_url( $att, 'medium' ) : '';
			$value = $att ? array( 'id' => $att, 'url' => $url ? $url : wp_get_attachment_url( $att ) ) : '';
		} elseif ( 'Repeater' === $type ) {
			// ACPT hands back rows already in row order, keyed by sub name.
			$rows = array();
			foreach ( array_values( is_array( $value ) ? $value : array() ) as $i => $row ) {
				$rows[] = array(
					'__idx'  => $i,
					'values' => (object) ( is_array( $row ) ? $row : array() ),
				);
			}
			$value = $rows;
		} elseif ( is_array( $value ) || is_object( $value ) ) {
			$value = '';
		}
		$out[ $id ] = $value;
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
		$args  = array(
			'post_id'    => $post_id,
			'box_name'   => $field->getBox()->getName(),
			'field_name' => $field->getName(),
		);
		$type = $field->getType();
		if ( ( '' === $value || null === $value ) && ! in_array( $type, array( 'Checkbox', 'Repeater' ), true ) ) {
			delete_acpt_meta_field_value( $args );
			continue;
		}
		if ( 'Toggle' === $type ) {
			$value = ! empty( $value ) && 'false' !== $value && '0' !== (string) $value;
		} elseif ( 'Checkbox' === $type ) {
			$value = is_array( $value ) ? array_values( array_map( 'sanitize_text_field', $value ) ) : array();
		} elseif ( 'Image' === $type ) {
			// The picker sends { id, url } (or a bare id); ACPT stores by id
			// and derives the URL itself. An id that is not an attachment is
			// refused rather than written, so a stray value cannot blank a
			// picture that is fine.
			$att = is_array( $value ) || is_object( $value )
				? (int) ( ( (array) $value )['id'] ?? 0 )
				: (int) $value;
			if ( $att < 1 || 'attachment' !== get_post_type( $att ) ) {
				continue;
			}
			$value = $att;
		} elseif ( 'Repeater' === $type ) {
			$value = minn_admin_acpt_rows_in( $field, $value, $args );
			if ( null === $value ) {
				continue; // malformed input never clobbers stored rows
			}
		}
		$args['value'] = $value;
		save_acpt_meta_field_value( $args );
	}
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
