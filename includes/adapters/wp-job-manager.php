<?php
/**
 * Bundled adapter: WP Job Manager (80k).
 *
 * Job listings are a REST-exposed CPT (job_listing, rest_base
 * job-listings), so Minn's Content list and editor already carry them; this
 * adapter adds the "Job listing" editor panel with the whole details
 * estate — location, company fields, application email/URL, salary, the
 * remote/filled/featured flags and the expiry date — read at request time
 * from WPJM's OWN schema (WP_Job_Manager_Post_Types::get_job_listing_fields(),
 * which is already filtered through job_manager_job_listing_data_fields, so
 * site and add-on customizations track live).
 *
 * Writes prefer each field's own declared sanitize_callback (WPJM ships
 * them: application accepts email OR url, dates validate to Y-m-d),
 * falling back per data_type. Their admin save handler is nonce-gated, so
 * Minn's REST saves are never clobbered.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wpjm_active() {
	return class_exists( 'WP_Job_Manager_Post_Types' )
		&& method_exists( 'WP_Job_Manager_Post_Types', 'get_job_listing_fields' )
		&& post_type_exists( 'job_listing' );
}

/** WPJM's live field schema (already site-filtered), or array(). */
function minn_admin_wpjm_raw_fields() {
	try {
		return (array) WP_Job_Manager_Post_Types::get_job_listing_fields();
	} catch ( \Throwable $e ) {
		return array();
	}
}

/**
 * Map WPJM's schema onto the panel vocabulary.
 *
 * @return array{fields: array, locked: int}
 */
function minn_admin_wpjm_mapped_fields() {
	$fields = array();
	$locked = 0;
	foreach ( minn_admin_wpjm_raw_fields() as $key => $f ) {
		$type  = isset( $f['auth_edit_field_type'] ) ? (string) $f['auth_edit_field_type'] : ( isset( $f['type'] ) ? (string) $f['type'] : 'text' );
		$label = isset( $f['label'] ) ? wp_strip_all_tags( (string) $f['label'] ) : $key;
		$out   = array( 'name' => $key, 'label' => $label );
		if ( ! empty( $f['placeholder'] ) && is_string( $f['placeholder'] ) ) {
			$out['placeholder'] = $f['placeholder'];
		}
		switch ( $type ) {
			case 'text':
				$out['type'] = 'text';
				break;
			case 'file': // company video: a URL with an uploader in wp-admin
				$out['type'] = 'url';
				break;
			case 'checkbox':
				$out['type'] = 'true_false';
				break;
			case 'select':
				$options = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
				if ( ! $options ) {
					$locked++;
					continue 2;
				}
				$out['type']    = 'select';
				$out['choices'] = $options;
				break;
			default:
				$locked++;
				continue 2;
		}
		$fields[] = $out;
	}
	return array( 'fields' => $fields, 'locked' => $locked );
}

/** Read panel values: { key => value } (checkboxes as bool). */
function minn_admin_wpjm_read_values( $post_id ) {
	$out = array();
	foreach ( minn_admin_wpjm_raw_fields() as $key => $f ) {
		$type = isset( $f['auth_edit_field_type'] ) ? (string) $f['auth_edit_field_type'] : ( isset( $f['type'] ) ? (string) $f['type'] : 'text' );
		if ( ! in_array( $type, array( 'text', 'file', 'checkbox', 'select' ), true ) ) {
			continue;
		}
		$val = get_post_meta( $post_id, $key, true );
		$out[ $key ] = 'checkbox' === $type ? ( (int) $val > 0 ) : ( is_scalar( $val ) ? (string) $val : '' );
	}
	return $out;
}

/** Write panel values through WPJM's own per-field sanitizers. */
function minn_admin_wpjm_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$schema = minn_admin_wpjm_raw_fields();
	foreach ( $values as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue;
		}
		$f    = $schema[ $key ];
		$type = isset( $f['auth_edit_field_type'] ) ? (string) $f['auth_edit_field_type'] : ( isset( $f['type'] ) ? (string) $f['type'] : 'text' );
		switch ( $type ) {
			case 'checkbox':
				// WPJM stores 1/0 integers (data_type integer).
				$value = ( ! empty( $value ) && 'false' !== (string) $value && '0' !== (string) $value ) ? 1 : 0;
				break;
			case 'select':
				$options = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
				if ( ! array_key_exists( (string) $value, $options ) ) {
					continue 2; // unknown choice — keep the stored value
				}
				$value = (string) $value;
				break;
			case 'file':
				$value = esc_url_raw( (string) $value );
				break;
			case 'text':
				$value = is_scalar( $value ) ? (string) $value : '';
				break;
			default:
				continue 2;
		}
		// Their own field sanitizer wins when declared (application's
		// email-or-url rule, the expiry date's Y-m-d validation).
		if ( 'checkbox' !== $type && isset( $f['sanitize_callback'] ) && is_callable( $f['sanitize_callback'] ) ) {
			try {
				$value = call_user_func( $f['sanitize_callback'], $value, $key );
			} catch ( \Throwable $e ) {
				continue;
			}
		} elseif ( 'text' === $type ) {
			$value = sanitize_text_field( $value );
		}
		update_post_meta( $post_id, $key, $value );
	}
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_wpjm_active() ) {
		return $panels;
	}
	$panels['wpjm'] = array(
		'label'       => 'Job listing',
		'sub'         => 'WP Job Manager',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/wpjm/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_wpjm',
		'writeKey'    => 'minn_wpjm',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpjm_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/wpjm/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
			'post_type' => array( 'type' => 'string', 'default' => 'posts' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$rest_base = sanitize_key( $request['post_type'] );
			$post_id   = (int) $request['post_id'];
			$post_type = $post_id && get_post( $post_id ) ? get_post( $post_id )->post_type : '';
			if ( '' === $post_type ) {
				foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
					$base = $obj->rest_base ? $obj->rest_base : $obj->name;
					if ( $base === $rest_base || $obj->name === $rest_base ) {
						$post_type = $obj->name;
						break;
					}
				}
			}
			if ( 'job_listing' !== $post_type ) {
				return rest_ensure_response( array( 'groups' => array() ) );
			}
			if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'rest_forbidden', 'You cannot edit this listing.', array( 'status' => 403 ) );
			}
			$mapped = minn_admin_wpjm_mapped_fields();
			return rest_ensure_response( array(
				'groups' => array(
					array( 'group' => 'Listing details', 'fields' => $mapped['fields'], 'locked' => $mapped['locked'] ),
				),
			) );
		},
	) );

	register_rest_field(
		'job_listing',
		'minn_wpjm',
		array(
			'get_callback'    => function ( $obj ) {
				$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
				if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
					return new stdClass();
				}
				return (object) minn_admin_wpjm_read_values( $id );
			},
			'update_callback' => function ( $value, $post ) {
				if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return;
				}
				if ( is_object( $value ) ) {
					$value = (array) $value;
				}
				minn_admin_wpjm_write_values( $post->ID, $value );
			},
			'schema'          => array(
				'description' => 'WP Job Manager listing fields for Minn Admin.',
				'type'        => 'object',
				'context'     => array( 'edit' ),
			),
		)
	);
} );
