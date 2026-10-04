<?php
/**
 * Gravity Forms: editing an entry's answers from the entry page.
 *
 * The entry route (adapters/gravity-forms.php) carries an `edit` block, the
 * family contract for editable answers: the route to save to, every field the
 * page may edit (in form order, empty ones included, so a missing phone number
 * can be filled in) with its inputs and their stored values, and the labels
 * of answers that stay in Gravity Forms. POST /gf/entries/{id}/answers saves.
 *
 * The write is their entry screen's, field by field: the form's trim setting,
 * the field's own get_value_save_input (each type's sanitizing and formatting:
 * dates from the field's format, numbers cleaned, phones formatted, choices
 * joined), GFAPI::update_entry_field (their gform_save_field_value filters),
 * then gform_after_update_entry with the entry as it was. Like their screen,
 * files, pricing, post fields and calculations are not edited here; neither
 * are times, international phone numbers, lists, repeaters, signatures and
 * add-on types, which need their own controls. A value that changed since the
 * page loaded refuses the save (409) rather than overwrite it.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Date formats their date fields store and parse (prepare_date). */
function minn_admin_gfe_date_formats() {
	return array(
		'mdy'       => 'm/d/Y',
		'dmy'       => 'd/m/Y',
		'dmy_dash'  => 'd-m-Y',
		'dmy_dot'   => 'd.m.Y',
		'ymd_slash' => 'Y/m/d',
		'ymd_dash'  => 'Y-m-d',
		'ymd_dot'   => 'Y.m.d',
	);
}

/**
 * The page's control for a field, or '' when the field stays in Gravity Forms.
 *
 * @param GF_Field $field The field.
 */
function minn_admin_gfe_kind( $field ) {
	if ( ! is_object( $field ) || ! empty( $field->displayOnly ) || GFCommon::is_pricing_field( $field->type ) || GFCommon::is_post_field( $field ) ) {
		return '';
	}
	if ( method_exists( $field, 'has_calculation' ) && $field->has_calculation() ) {
		return '';
	}
	$type = (string) GFFormsModel::get_input_type( $field );
	switch ( $type ) {
		case 'text':
		case 'hidden':
			return 'text';
		case 'textarea':
			return 'textarea';
		case 'email':
			return 'email';
		case 'website':
			return 'url';
		case 'number':
			return 'number';
		case 'phone':
			return 'formatted' === $field->phoneFormat ? '' : 'tel';
		case 'date':
			return 'date';
		case 'select':
		case 'radio':
			return 'choice';
		case 'multiselect':
			return 'multi';
		case 'checkbox':
			return 'checks';
		case 'name':
		case 'address':
			return is_array( $field->inputs ) ? 'parts' : '';
	}
	return '';
}

/** A field's choices as [value, label] pairs. */
function minn_admin_gfe_choices( $field ) {
	$out = array();
	foreach ( (array) $field->choices as $c ) {
		if ( is_array( $c ) ) {
			$out[] = array( (string) rgar( $c, 'value' ), wp_strip_all_tags( (string) rgar( $c, 'text' ) ) );
		}
	}
	return $out;
}

/**
 * Checkbox inputs paired with their choices (by key where their choices are
 * persistent, by position otherwise).
 */
function minn_admin_gfe_checks( $field ) {
	$inputs  = (array) $field->get_entry_inputs();
	$choices = array_values( (array) $field->choices );
	$by_key  = array();
	foreach ( $choices as $c ) {
		if ( is_array( $c ) && ! empty( $c['key'] ) ) {
			$by_key[ $c['key'] ] = $c;
		}
	}
	$out = array();
	foreach ( array_values( $inputs ) as $i => $input ) {
		$choice = ! empty( $input['key'] ) && isset( $by_key[ $input['key'] ] ) ? $by_key[ $input['key'] ] : ( $choices[ $i ] ?? null );
		if ( is_array( $choice ) ) {
			$out[] = array( 'id' => (string) $input['id'], 'value' => (string) rgar( $choice, 'value' ), 'label' => wp_strip_all_tags( (string) rgar( $choice, 'text' ) ) );
		}
	}
	return $out;
}

/**
 * The edit block for the entry route: editable fields with their inputs and
 * stored values, and the answers that stay in Gravity Forms. Null when the
 * person may not edit entries.
 */
function minn_admin_gf_entry_edit_block( $form, $entry ) {
	if ( ! GFCommon::current_user_can_any( array( 'gravityforms_edit_entries', 'gform_full_access' ) ) ) {
		return null;
	}
	$fields = array();
	$locked = array();
	foreach ( (array) $form['fields'] as $field ) {
		if ( ! is_object( $field ) || ! empty( $field->displayOnly ) || in_array( $field->type, array( 'captcha', 'honeypot' ), true ) ) {
			continue;
		}
		$label = wp_strip_all_tags( GFCommon::get_label( $field ) );
		$kind  = minn_admin_gfe_kind( $field );
		if ( '' === $kind ) {
			if ( '' !== trim( (string) $field->get_value_export( $entry, (string) $field->id, true ) ) ) {
				$locked[] = $label;
			}
			continue;
		}
		$row = array( 'id' => (string) $field->id, 'label' => $label, 'kind' => $kind, 'inputs' => array() );
		if ( 'parts' === $kind ) {
			foreach ( (array) $field->inputs as $input ) {
				$value = (string) rgar( $entry, (string) $input['id'] );
				if ( ! empty( $input['isHidden'] ) && '' === $value ) {
					continue;
				}
				$row['inputs'][] = array(
					'id'    => (string) $input['id'],
					'label' => wp_strip_all_tags( (string) ( rgar( $input, 'customLabel' ) ? $input['customLabel'] : rgar( $input, 'label' ) ) ),
					'value' => $value,
				);
			}
		} elseif ( 'checks' === $kind ) {
			foreach ( minn_admin_gfe_checks( $field ) as $c ) {
				$row['inputs'][] = array( 'id' => $c['id'], 'label' => $c['label'], 'value' => (string) rgar( $entry, $c['id'] ), 'choice' => $c['value'] );
			}
		} elseif ( 'multi' === $kind ) {
			$row['inputs'][] = array( 'id' => (string) $field->id, 'value' => array_values( array_map( 'strval', (array) $field->to_array( rgar( $entry, (string) $field->id ) ) ) ) );
			$row['choices']  = minn_admin_gfe_choices( $field );
		} else {
			$row['inputs'][] = array( 'id' => (string) $field->id, 'value' => (string) rgar( $entry, (string) $field->id ) );
			if ( 'choice' === $kind ) {
				$row['choices'] = minn_admin_gfe_choices( $field );
				$row['other']   = 'radio' === GFFormsModel::get_input_type( $field ) && ! empty( $field->enableOtherChoice );
			}
		}
		if ( $row['inputs'] ) {
			$fields[] = $row;
		}
	}
	if ( ! $fields ) {
		return null;
	}
	return array(
		'route'  => 'minn-admin/v1/gf/entries/{id}/answers',
		'fields' => $fields,
		'locked' => $locked,
	);
}

/**
 * The stored value an edit turns into, through the field's own save
 * formatting. WP_Error names the input it refuses.
 *
 * @return string|WP_Error
 */
function minn_admin_gfe_prepare( $form, $field, $kind, $input, $value, $entry ) {
	$refuse = function ( $message ) use ( $input ) {
		return new WP_Error( 'minn_gfe_invalid', $message, array( 'status' => 400, 'field' => (string) $input['id'] ) );
	};
	$name = 'input_' . str_replace( '.', '_', (string) $input['id'] );
	if ( 'multi' === $kind ) {
		$allowed = wp_list_pluck( minn_admin_gfe_choices( $field ), 0 );
		$picked  = array();
		foreach ( (array) $value as $v ) {
			if ( is_scalar( $v ) && in_array( (string) $v, $allowed, true ) ) {
				$picked[] = (string) $v;
			}
		}
		return (string) $field->get_value_save_input( $picked, $form, $name, $entry['id'], $entry );
	}
	if ( 'checks' === $kind ) {
		$value = $value ? (string) $input['choice'] : '';
	} elseif ( ! is_scalar( $value ) ) {
		return $refuse( __( 'That answer could not be read.', 'minn-admin' ) );
	}
	$value = GFFormsModel::maybe_trim_input( (string) $value, $form['id'], $field );
	if ( 'email' === $kind && '' !== $value && ! is_email( $value ) ) {
		return $refuse( __( 'Enter a valid email address.', 'minn-admin' ) );
	}
	if ( 'choice' === $kind && '' !== $value && empty( $input['other'] ) && ! in_array( $value, wp_list_pluck( minn_admin_gfe_choices( $field ), 0 ), true ) && $value !== (string) $input['value'] ) {
		return $refuse( __( 'Choose one of the field’s choices.', 'minn-admin' ) );
	}
	if ( 'date' === $kind && '' !== $value ) {
		// The page sends Y-m-d; their parser reads the field's own format.
		$d = DateTime::createFromFormat( '!Y-m-d', $value );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $value ) {
			return $refuse( __( 'Enter a valid date.', 'minn-admin' ) );
		}
		$formats = minn_admin_gfe_date_formats();
		$value   = $d->format( $formats[ $field->dateFormat ] ?? 'm/d/Y' );
	}
	return (string) $field->get_value_save_input( $value, $form, $name, $entry['id'], $entry );
}

add_action( 'rest_api_init', function () {
	if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFAPI', 'update_entry_field' ) ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/gf/entries/(?P<id>\d+)/answers', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return GFCommon::current_user_can_any( array( 'gravityforms_edit_entries', 'gform_full_access' ) );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$entry = GFAPI::get_entry( (int) Minn_Admin::path_param( $request ) );
			if ( is_wp_error( $entry ) ) {
				return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$form  = GFAPI::get_form( $entry['form_id'] );
			$block = $form ? minn_admin_gf_entry_edit_block( $form, $entry ) : null;
			if ( ! $block ) {
				return new WP_Error( 'minn_gfe_none', __( 'This entry has no answers that can be edited here.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$body     = (array) $request->get_json_params();
			$values   = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
			$original = isset( $body['original'] ) && is_array( $body['original'] ) ? $body['original'] : array();

			// Every input the page may write, with its field and control.
			$editable = array();
			foreach ( $block['fields'] as $row ) {
				foreach ( $row['inputs'] as $input ) {
					$editable[ $input['id'] ] = array( 'row' => $row, 'input' => $input + array( 'other' => ! empty( $row['other'] ) ) );
				}
			}

			$writes = array();
			foreach ( $values as $input_id => $value ) {
				$input_id = (string) $input_id;
				if ( ! isset( $editable[ $input_id ] ) ) {
					return new WP_Error( 'minn_gfe_locked', __( 'That answer can only be edited in Gravity Forms.', 'minn-admin' ), array( 'status' => 400, 'field' => $input_id ) );
				}
				$e     = $editable[ $input_id ];
				$field = GFAPI::get_field( $form, $e['row']['id'] );
				// Refuse to overwrite a value someone changed since the page loaded.
				if ( array_key_exists( $input_id, $original ) && wp_json_encode( $original[ $input_id ] ) !== wp_json_encode( $e['input']['value'] ) ) {
					return new WP_Error( 'minn_gfe_conflict', __( 'This entry changed since you opened it. Reload it to see the latest answers, then edit again.', 'minn-admin' ), array( 'status' => 409 ) );
				}
				$stored = minn_admin_gfe_prepare( $form, $field, $e['row']['kind'], $e['input'], $value, $entry );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				if ( (string) rgar( $entry, $input_id ) !== $stored ) {
					$writes[ $input_id ] = array( 'value' => $stored, 'label' => $e['row']['label'] );
				}
			}

			$original_entry = $entry;
			$changed        = array();
			foreach ( $writes as $input_id => $w ) {
				$result = GFAPI::update_entry_field( $entry['id'], $input_id, $w['value'] );
				if ( true !== $result && ! is_numeric( $result ) ) {
					return new WP_Error( 'minn_gfe_failed', __( 'Gravity Forms did not save the answer.', 'minn-admin' ), array( 'status' => 500, 'field' => $input_id ) );
				}
				$changed[ $w['label'] ] = true;
			}
			if ( $changed ) {
				GFAPI::update_entry_property( $entry['id'], 'date_updated', gmdate( 'Y-m-d H:i:s' ) );
				/** Their entry screen's hook after an edit (gform_after_update_entry). */
				gf_do_action( array( 'gform_after_update_entry', $form['id'] ), $form, $entry['id'], $original_entry );
				// The notes trail records who changed which answers (labels only).
				if ( GFCommon::current_user_can_any( array( 'gravityforms_edit_entry_notes', 'gform_full_access' ) ) ) {
					$me = wp_get_current_user();
					/* translators: %s: comma-separated field labels. */
					GFAPI::add_note( $entry['id'], $me->ID, $me->display_name, sprintf( __( 'Edited answers: %s', 'minn-admin' ), implode( ', ', array_keys( $changed ) ) ) );
				}
			}
			return rest_ensure_response( array(
				'changed' => array_keys( $changed ),
				'message' => $changed ? __( 'Answers saved', 'minn-admin' ) : __( 'Nothing changed', 'minn-admin' ),
			) );
		},
	) );
} );
