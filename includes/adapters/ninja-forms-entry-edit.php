<?php
/**
 * Ninja Forms: editing a submission's answers from the entry page.
 *
 * The entry route (adapters/ninja-forms.php) carries the family's `edit`
 * block (see gravity-forms-entry-edit.php for the contract). The write is the
 * one Ninja Forms' own submissions screen makes: their REST update
 * (ninja-forms-submissions/submissions/update) lands in the submission
 * model's update_field_value() and save(), which encode each value the way
 * their display decodes it. Who may edit is theirs too: manage_options,
 * through their ninja_forms_api_allow_update_submission filter.
 *
 * Editable: text-like fields (text, names, address parts, hidden, date as
 * stored), email, phone, number, paragraph, single-choice lists and
 * multi-choice lists with their options. Files, signatures, products,
 * repeaters, the single checkbox and anything an add-on adds stay in Ninja
 * Forms.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Their update permission: manage_options through their own filter. */
function minn_admin_ninja_forms_can_edit() {
	$allowed = current_user_can( 'manage_options' );
	return (bool) apply_filters( 'ninja_forms_api_allow_update_submission', $allowed, new WP_REST_Request( 'POST', '/ninja-forms-submissions/submissions/update' ) );
}

/** The page's control for a Ninja Forms field type, or '' to leave it to them. */
function minn_admin_ninja_forms_edit_kind( $field ) {
	$type = (string) $field->get_setting( 'type' );
	$map  = array(
		'textbox'   => 'text',
		'firstname' => 'text',
		'lastname'  => 'text',
		'address'   => 'text',
		'address2'  => 'text',
		'city'      => 'text',
		'zip'       => 'text',
		'hidden'    => 'text',
		'date'      => 'text', // stored in the field's own format
		'email'     => 'email',
		'phone'     => 'tel',
		'number'    => 'number',
		'textarea'  => 'textarea',
	);
	if ( isset( $map[ $type ] ) ) {
		return $map[ $type ];
	}
	$options = minn_admin_ninja_forms_edit_options( $field );
	if ( in_array( $type, array( 'listselect', 'listradio', 'liststate', 'listcountry' ), true ) ) {
		return $options ? 'choice' : 'text';
	}
	if ( in_array( $type, array( 'listcheckbox', 'listmultiselect' ), true ) && $options ) {
		return 'multi';
	}
	return '';
}

/** A list field's options as [value, label] pairs. */
function minn_admin_ninja_forms_edit_options( $field ) {
	$out = array();
	foreach ( (array) $field->get_setting( 'options' ) as $o ) {
		if ( is_array( $o ) && isset( $o['value'] ) ) {
			$out[] = array( (string) $o['value'], wp_strip_all_tags( (string) ( $o['label'] ?? $o['value'] ) ) );
		}
	}
	return $out;
}

/** A submission's stored answer, read back the way Ninja Forms reads it. */
function minn_admin_ninja_forms_edit_value( $post_id, $field_id, $kind ) {
	$v = minn_admin_ninja_forms_decode( get_post_meta( $post_id, '_field_' . (int) $field_id, true ) );
	if ( 'multi' === $kind ) {
		return array_values( array_map( 'strval', is_array( $v ) ? $v : ( '' === (string) $v ? array() : array( $v ) ) ) );
	}
	return is_array( $v ) ? implode( ', ', array_map( 'strval', $v ) ) : (string) $v;
}

/** The entry route's edit block, or null when the person may not edit. */
function minn_admin_ninja_forms_edit_block( $post, $form_id ) {
	if ( ! minn_admin_ninja_forms_can_edit() ) {
		return null;
	}
	$fields = array();
	$locked = array();
	try {
		$sorted = array();
		foreach ( (array) Ninja_Forms()->form( (int) $form_id )->get_fields() as $field ) {
			if ( in_array( (string) $field->get_setting( 'type' ), minn_admin_ninja_forms_skip_types(), true ) ) {
				continue;
			}
			$sorted[] = $field;
		}
		usort( $sorted, function ( $a, $b ) {
			return (int) $a->get_setting( 'order' ) - (int) $b->get_setting( 'order' );
		} );
		foreach ( $sorted as $field ) {
			$id    = (int) $field->get_id();
			$label = trim( wp_strip_all_tags( (string) $field->get_setting( 'label' ) ) );
			$label = $label ? $label : 'Field ' . $id;
			$kind  = minn_admin_ninja_forms_edit_kind( $field );
			if ( '' === $kind ) {
				$v = minn_admin_ninja_forms_edit_value( $post->ID, $id, 'text' );
				if ( '' !== trim( $v ) ) {
					$locked[] = $label;
				}
				continue;
			}
			$row = array(
				'id'     => (string) $id,
				'label'  => $label,
				'kind'   => $kind,
				'inputs' => array( array( 'id' => (string) $id, 'value' => minn_admin_ninja_forms_edit_value( $post->ID, $id, $kind ) ) ),
			);
			if ( 'choice' === $kind || 'multi' === $kind ) {
				$row['choices'] = minn_admin_ninja_forms_edit_options( $field );
			}
			$fields[] = $row;
		}
	} catch ( \Throwable $e ) {
		return null;
	}
	if ( ! $fields ) {
		return null;
	}
	return array( 'route' => 'minn-admin/v1/ninja-forms/entries/{id}/answers', 'fields' => $fields, 'locked' => $locked );
}

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'minn_admin_ninja_forms_active' ) || ! minn_admin_ninja_forms_active() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/ninja-forms/entries/(?P<id>\d+)/answers', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return minn_admin_ninja_forms_can() && minn_admin_ninja_forms_can_edit();
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$post = get_post( (int) Minn_Admin::path_param( $request ) );
			if ( ! $post || 'nf_sub' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Entry not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$block = minn_admin_ninja_forms_edit_block( $post, (int) get_post_meta( $post->ID, '_form_id', true ) );
			if ( ! $block ) {
				return new WP_Error( 'minn_nfe_none', __( 'This entry has no answers that can be edited here.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$body     = (array) $request->get_json_params();
			$values   = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
			$original = isset( $body['original'] ) && is_array( $body['original'] ) ? $body['original'] : array();
			$rows     = array();
			foreach ( $block['fields'] as $row ) {
				$rows[ $row['id'] ] = $row;
			}
			$refuse = function ( $id, $message ) {
				return new WP_Error( 'minn_nfe_invalid', $message, array( 'status' => 400, 'field' => (string) $id ) );
			};

			$writes = array();
			foreach ( $values as $id => $value ) {
				$id = (string) $id;
				if ( ! isset( $rows[ $id ] ) ) {
					// An unknown key would write a stray _field_ row: refuse it.
					return new WP_Error( 'minn_nfe_locked', __( 'That answer can only be edited in Ninja Forms.', 'minn-admin' ), array( 'status' => 400, 'field' => $id ) );
				}
				$row    = $rows[ $id ];
				$stored = $row['inputs'][0]['value'];
				if ( array_key_exists( $id, $original ) && wp_json_encode( $original[ $id ] ) !== wp_json_encode( $stored ) ) {
					return new WP_Error( 'minn_nfe_conflict', __( 'This entry changed since you opened it. Reload it to see the latest answers, then edit again.', 'minn-admin' ), array( 'status' => 409 ) );
				}
				$allowed = isset( $row['choices'] ) ? wp_list_pluck( $row['choices'], 0 ) : array();
				if ( 'multi' === $row['kind'] ) {
					$picked = array();
					foreach ( (array) $value as $v ) {
						if ( is_scalar( $v ) && in_array( (string) $v, $allowed, true ) ) {
							$picked[] = (string) $v;
						}
					}
					$value = $picked;
				} else {
					if ( ! is_scalar( $value ) ) {
						return $refuse( $id, __( 'That answer could not be read.', 'minn-admin' ) );
					}
					// As their update: the model encodes the value; nothing else
					// rewrites it.
					$value = (string) $value;
					if ( 'email' === $row['kind'] && '' !== $value && ! is_email( $value ) ) {
						return $refuse( $id, __( 'Enter a valid email address.', 'minn-admin' ) );
					}
					if ( 'number' === $row['kind'] && '' !== $value && ! is_numeric( $value ) ) {
						return $refuse( $id, __( 'Enter a number.', 'minn-admin' ) );
					}
					if ( 'choice' === $row['kind'] && '' !== $value && ! in_array( $value, $allowed, true ) && $value !== $stored ) {
						return $refuse( $id, __( 'Choose one of the field’s choices.', 'minn-admin' ) );
					}
				}
				if ( wp_json_encode( $value ) !== wp_json_encode( $stored ) ) {
					$writes[ $id ] = $value;
				}
			}

			if ( $writes ) {
				// Their update path: the submission model, one save.
				$sub = Ninja_Forms()->form()->get_sub( $post->ID );
				foreach ( $writes as $id => $value ) {
					$sub->update_field_value( (int) $id, $value );
				}
				$sub->save();
			}
			$labels = array();
			foreach ( array_keys( $writes ) as $id ) {
				$labels[] = $rows[ $id ]['label'];
			}
			return rest_ensure_response( array(
				'changed' => $labels,
				'message' => $writes ? __( 'Answers saved', 'minn-admin' ) : __( 'Nothing changed', 'minn-admin' ),
			) );
		},
	) );
} );
