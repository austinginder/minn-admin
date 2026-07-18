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

/** Write panel values with SSP's own storage conventions. */
function minn_admin_ssp_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$schema = minn_admin_ssp_raw_fields();
	foreach ( $values as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue;
		}
		$type = isset( $schema[ $key ]['type'] ) ? (string) $schema[ $key ]['type'] : '';
		switch ( $type ) {
			case 'hidden':
			case 'sync_status':
			case 'image':
				continue 2; // never writable from the panel
			case 'checkbox':
				$value = ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 'on' : '';
				break;
			case 'episode_file':
				$value = esc_url_raw( (string) $value );
				break;
			case 'number':
				$value = '' === (string) $value ? '' : (string) absint( $value );
				break;
			case 'radio':
			case 'select':
				$options = isset( $schema[ $key ]['options'] ) && is_array( $schema[ $key ]['options'] ) ? $schema[ $key ]['options'] : array();
				if ( ! array_key_exists( (string) $value, $options ) ) {
					continue 2; // unknown choice — refuse silently, keep stored value
				}
				$value = (string) $value;
				break;
			default:
				$value = sanitize_text_field( (string) $value );
		}
		update_post_meta( $post_id, $key, $value );
	}
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_ssp_active() ) {
		return $panels;
	}
	$panels['ssp'] = array(
		'label'       => 'Podcast episode',
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

	register_rest_route( 'minn-admin/v1', '/ssp/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
			'post_type' => array( 'type' => 'string', 'default' => 'posts' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			// Resolve the editor's rest_base back to a type name.
			$rest_base = sanitize_key( $request['post_type'] );
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
				if ( $post && ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'rest_forbidden', 'You cannot edit this post.', array( 'status' => 403 ) );
				}
				if ( $post ) {
					$post_type = $post->post_type;
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
						'group'  => 'Episode details',
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
				'update_callback' => function ( $value, $post ) {
					if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
						return;
					}
					if ( is_object( $value ) ) {
						$value = (array) $value;
					}
					minn_admin_ssp_write_values( $post->ID, $value );
				},
				'schema'          => array(
					'description' => 'Seriously Simple Podcasting episode fields for Minn Admin.',
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}
} );
