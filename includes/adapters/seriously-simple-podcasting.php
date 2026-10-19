<?php
/**
 * Bundled adapter: Seriously Simple Podcasting (Castos, wp.org free).
 *
 * SSP's episodes are a REST-exposed CPT (`podcast`, plus any extra types
 * enabled in its settings via ssp_post_types()), so Minn's Content list and
 * editor already carry them. This adapter adds the "Podcast episode" editor
 * panel: episode file URL, type, duration, explicit/block, the iTunes
 * fields — read at request time from SSP's OWN schema
 * (CPT_Podcast_Handler::custom_fields() through their service container), so
 * a field SSP adds or a site disables (iTunes fields off) tracks live.
 *
 * Writes mirror SSP's metabox storage exactly: plain postmeta, checkboxes
 * as 'on'/''. SSP's own save_post handler bails without its metabox nonce,
 * so a Minn REST save never gets clobbered. Castos hosting sync (the hidden
 * podmotor and castos fields, sync_status) and the cover-image uploader stay
 * SSP's — cover image counts as locked with the wp-admin escape.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_ssp_active() {
	return defined( 'SSP_VERSION' )
		&& function_exists( 'ssp_app' )
		&& function_exists( 'ssp_post_types' );
}

/** SSP's live episode-field schema, or array() when unavailable. */
function minn_admin_ssp_raw_fields() {
	try {
		$handler = ssp_app()->get_service( 'cpt_podcast_handler' );
		if ( $handler && method_exists( $handler, 'custom_fields' ) ) {
			return (array) $handler->custom_fields();
		}
	} catch ( \Throwable $e ) {
		// Fall through — panel just renders empty.
	}
	return array();
}

/**
 * Map SSP's field schema onto the panel vocabulary.
 *
 * @return array{fields: array, locked: int}
 */
function minn_admin_ssp_mapped_fields() {
	$fields = array();
	$locked = 0;
	foreach ( minn_admin_ssp_raw_fields() as $key => $f ) {
		$type = isset( $f['type'] ) ? (string) $f['type'] : '';
		// Internal plumbing (Castos ids, raw byte sizes) — not user fields.
		if ( in_array( $type, array( 'hidden', 'sync_status' ), true ) ) {
			continue;
		}
		$label = isset( $f['name'] ) ? rtrim( wp_strip_all_tags( (string) $f['name'] ), ': ' ) : $key;
		switch ( $type ) {
			case 'episode_file':
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'url' );
				break;
			case 'text':
			case 'datepicker': // stored as display text; keep their format
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'text' );
				break;
			case 'number':
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'number' );
				break;
			case 'checkbox':
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'true_false' );
				break;
			case 'radio':
			case 'select':
				$options = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
				if ( ! $options ) {
					$locked++;
					break;
				}
				$fields[] = array(
					'name'    => $key,
					'label'   => $label,
					'type'    => 'radio' === $type ? 'radio' : 'select',
					'choices' => $options,
				);
				break;
			default:
				// image (cover) and anything SSP adds later that Minn can't draw.
				$locked++;
		}
	}
	return array( 'fields' => $fields, 'locked' => $locked );
}

/** Read panel values for an episode: { key => value } (checkboxes as bool). */
function minn_admin_ssp_read_values( $post_id ) {
	$out = array();
	foreach ( minn_admin_ssp_raw_fields() as $key => $f ) {
		$type = isset( $f['type'] ) ? (string) $f['type'] : '';
		if ( in_array( $type, array( 'hidden', 'sync_status', 'image' ), true ) ) {
			continue;
		}
		$val = get_post_meta( $post_id, $key, true );
		// SSP checkboxes store 'on' or '' (their metabox convention).
		$out[ $key ] = 'checkbox' === $type ? ( 'on' === $val ) : ( is_scalar( $val ) ? (string) $val : '' );
	}
	return $out;
}

/**
 * Write panel values with SSP's own storage conventions.
 *
 * @param int   $post_id Episode id.
 * @param array $values  Sent values, by meta key.
 * @return WP_Error|null The fields whose value was refused (each kept as
 *                       stored), or null.
 */
function minn_admin_ssp_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return null;
	}
	$refused = array();
	$schema = minn_admin_ssp_raw_fields();
	// The panel sends every field when any one changes; an untouched one is
	// left as SSP's own form stored it (sanitize_text_field would delete a
	// %XX-shaped run from it).
	$held = minn_admin_ssp_read_values( $post_id );
	foreach ( $values as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue;
		}
		$type = isset( $schema[ $key ]['type'] ) ? (string) $schema[ $key ]['type'] : '';
		if ( array_key_exists( $key, $held ) && Minn_Admin::unchanged( $value, $held[ $key ], 'checkbox' === $type ? 'bool' : 'text' ) ) {
			continue;
		}
		switch ( $type ) {
			case 'hidden':
			case 'sync_status':
			case 'image':
				continue 2; // never writable from the panel
			case 'checkbox':
				$value = ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 'on' : '';
				break;
			case 'episode_file':
				// Only an emptied box clears. An address the house rule
				// refuses (javascript:, an unknown scheme) cleaned to '' and
				// cleared the stored file without a word.
				$typed = null === $value ? '' : ( is_scalar( $value ) ? trim( (string) $value ) : null );
				$clean = null === $typed ? null : minn_admin_url_clean( $typed );
				if ( null === $clean ) {
					$refused[] = array(
						'key'     => (string) $key,
						'label'   => isset( $schema[ $key ]['name'] ) ? rtrim( wp_strip_all_tags( (string) $schema[ $key ]['name'] ), ': ' ) : (string) $key,
						'message' => __( 'That URL is not valid.', 'minn-admin' ),
					);
					continue 2;
				}
				$value = $clean;
				break;
			case 'number':
				// A whole number, or empty to clear: absint() made 'abc' a 0
				// and -5 a 5. An emptied number box arrives as null: empty.
				$typed = null === $value ? '' : ( is_scalar( $value ) ? trim( (string) $value ) : null );
				if ( null === $typed || ( '' !== $typed && ! ctype_digit( $typed ) ) ) {
					$refused[] = array(
						'key'     => (string) $key,
						'label'   => isset( $schema[ $key ]['name'] ) ? rtrim( wp_strip_all_tags( (string) $schema[ $key ]['name'] ), ': ' ) : (string) $key,
						'message' => __( 'Enter a whole number.', 'minn-admin' ),
					);
					continue 2;
				}
				$value = '' === $typed ? '' : (string) absint( $typed );
				break;
			case 'radio':
			case 'select':
				$options = isset( $schema[ $key ]['options'] ) && is_array( $schema[ $key ]['options'] ) ? $schema[ $key ]['options'] : array();
				if ( ! array_key_exists( (string) $value, $options ) ) {
					// Not one of the field's choices: the stored value stays,
					// and the answer says so.
					$refused[] = array(
						'key'     => (string) $key,
						'label'   => isset( $schema[ $key ]['name'] ) ? rtrim( wp_strip_all_tags( (string) $schema[ $key ]['name'] ), ': ' ) : (string) $key,
						'message' => __( 'That is not one of this field\'s choices.', 'minn-admin' ),
					);
					continue 2;
				}
				$value = (string) $value;
				break;
			default:
				$value = sanitize_text_field( (string) $value );
		}
		update_post_meta( $post_id, $key, wp_slash( $value ) ); // update_post_meta() unslashes
	}
	if ( ! $refused ) {
		return null;
	}
	return new WP_Error(
		'minn_ssp_refused',
		_n( 'One episode field was not saved.', 'Some episode fields were not saved.', count( $refused ), 'minn-admin' ),
		array( 'status' => 400, 'fields' => $refused )
	);
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_ssp_active() ) {
		return $panels;
	}
	$panels['ssp'] = array(
		'label'       => __( 'Podcast episode', 'minn-admin' ),
		'sub'         => 'Seriously Simple Podcasting',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/ssp/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_ssp',
		'writeKey'    => 'minn_ssp',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ssp_active() ) {
		return;
	}

	minn_admin_endpoint( '/ssp/fields', array(
		'methods'             => 'GET',
		'gate'                => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer' ),
			'post_type' => array( 'type' => 'string' ),
		),
		'run'                 => function ( WP_REST_Request $request ) {
			// Resolve the editor's rest_base back to a type name.
			$rest_base = sanitize_key( (string) ( $request['post_type'] ?? 'posts' ) );
			$post_type = 'post';
			foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
				$base = $obj->rest_base ? $obj->rest_base : $obj->name;
				if ( $base === $rest_base || $obj->name === $rest_base ) {
					$post_type = $obj->name;
					break;
				}
			}
			$post_id = (int) $request['post_id'];
			if ( $post_id ) {
				$post = get_post( $post_id );
				// A missing post used to fall through with the caller's own
				// post_type intact, which is the same hole as post_id=0.
				if ( ! $post ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit this post.', 'minn-admin' ), array( 'status' => 403 ) );
				}
				$post_type = $post->post_type;
			} else {
				// No post to authorize against, so authorize against the TYPE.
				// The field list comes from SSP's own custom_fields(), so
				// edit_posts alone let a Contributor read the whole episode
				// schema for a type they cannot write.
				$type_obj = get_post_type_object( $post_type );
				if ( ! $type_obj || ! current_user_can( $type_obj->cap->edit_posts ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit posts of that type.', 'minn-admin' ), array( 'status' => 403 ) );
				}
			}
			// The panel only exists on episode types (podcast + any extras
			// enabled in SSP's settings).
			if ( ! in_array( $post_type, (array) ssp_post_types(), true ) ) {
				return rest_ensure_response( array( 'groups' => array() ) );
			}
			$mapped = minn_admin_ssp_mapped_fields();
			return rest_ensure_response( array(
				'groups' => array(
					array(
						'group'  => __( 'Episode details', 'minn-admin' ),
						'fields' => $mapped['fields'],
						'locked' => $mapped['locked'],
					),
				),
			) );
		},
	) );

	// Values ride the post REST object (the ACF/Meta Box dedicated-field
	// pattern — never the generic meta API).
	foreach ( (array) ssp_post_types() as $type ) {
		if ( ! get_post_type_object( $type ) || ! get_post_type_object( $type )->show_in_rest ) {
			continue;
		}
		register_rest_field(
			$type,
			'minn_ssp',
			array(
				'get_callback'    => function ( $obj ) {
					$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
					if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
						return new stdClass();
					}
					return (object) minn_admin_ssp_read_values( $id );
				},
				'update_callback' => function ( $value, $post, $field_name = '', $request = null ) {
					if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
						return;
					}
					if ( is_object( $value ) ) {
						$value = (array) $value;
					}
					$error = minn_admin_ssp_write_values( $post->ID, $value );
					// Never an error from here: the post is already saved, a
					// new one included. The refusal rides on the post's own
					// answer instead (minn_admin_panel_report_held(),
					// adapters/acpt.php).
					if ( is_wp_error( $error ) && $request instanceof WP_REST_Request && function_exists( 'minn_admin_panel_held' ) ) {
						minn_admin_panel_held( $request, $error, 'minn_ssp' );
					}
				},
				'schema'          => array(
					'description' => __( 'Seriously Simple Podcasting episode fields for Minn Admin.', 'minn-admin' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}
} );
