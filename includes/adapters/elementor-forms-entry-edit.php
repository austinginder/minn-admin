<?php
/**
 * Elementor Pro forms: editing a submission's answers from the entry page.
 *
 * The entry route (adapters/elementor-forms.php) carries the family's `edit`
 * block (contract: gravity-forms-entry-edit.php). The write is Elementor's
 * own: their submissions REST update (PUT elementor/v1/form-submissions/{id}
 * with `values`) lands in Query::update_submission(), which rewrites the
 * stored value rows and stamps updated_at. Like their route it is gated on
 * manage_options and sanitizes every value; paragraph answers keep their line
 * breaks (sanitize_textarea_field) where their route's sanitize_text_field
 * would fold them into one line.
 *
 * Their update only rewrites value rows the submission already has, so only
 * those are offered. Field types come from the form snapshot Elementor saves
 * with submissions; without one (submissions from before snapshots) answers
 * edit as text, email or paragraph by their content. Uploads, signatures,
 * passwords, acceptance boxes and payment fields stay in Elementor.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** A snapshot field's options ("Label|value" lines) as [value, label] pairs. */
function minn_admin_elementor_edit_options( $field ) {
	$out = array();
	foreach ( (array) ( $field['options'] ?? array() ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		$parts = explode( '|', $line, 2 );
		$label = trim( $parts[0] );
		$value = isset( $parts[1] ) ? trim( $parts[1] ) : $label;
		$out[] = array( $value, wp_strip_all_tags( $label ) );
	}
	return $out;
}

/** The page's control for an answer, from its snapshot field (or its content). */
function minn_admin_elementor_edit_kind( $field, $key, $value ) {
	if ( ! $field ) {
		if ( false !== strpos( $value, "\n" ) || preg_match( '/message|comment/i', $key ) ) {
			return 'textarea';
		}
		return ( false !== stripos( $key, 'email' ) || is_email( $value ) ) ? 'email' : 'text';
	}
	$type    = (string) ( $field['type'] ?? 'text' );
	$options = minn_admin_elementor_edit_options( $field );
	switch ( $type ) {
		case 'text':
		case 'hidden':
		case 'time':
			return 'text';
		case 'email':
			return 'email';
		case 'tel':
			return 'tel';
		case 'url':
			return 'url';
		case 'number':
			return 'number';
		case 'textarea':
			return 'textarea';
		case 'date':
			return '' === $value || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? 'date' : 'text';
		case 'select':
			return $options ? ( ! empty( $field['is_multiple'] ) ? 'multi' : 'choice' ) : 'text';
		case 'radio':
			return $options ? 'choice' : 'text';
		case 'checkbox':
			return $options ? 'multi' : 'text';
	}
	return '';
}

/** The entry route's edit block, or null when the person may not edit. */
function minn_admin_elementor_edit_block( $sub ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return null;
	}
	$snapshot = array();
	foreach ( (array) ( $sub['form']['fields'] ?? array() ) as $f ) {
		if ( ! empty( $f['id'] ) ) {
			$snapshot[ (string) $f['id'] ] = $f;
		}
	}
	$fields = array();
	$locked = array();
	foreach ( (array) ( $sub['values'] ?? array() ) as $v ) {
		$key   = isset( $v['key'] ) ? (string) $v['key'] : '';
		$value = isset( $v['value'] ) ? (string) $v['value'] : '';
		if ( '' === $key ) {
			continue;
		}
		$field = $snapshot[ $key ] ?? null;
		$label = $field && ! empty( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
		$kind  = minn_admin_elementor_edit_kind( $field, $key, $value );
		if ( '' === $kind ) {
			if ( '' !== trim( $value ) ) {
				$locked[] = $label;
			}
			continue;
		}
		$row = array( 'id' => $key, 'label' => $label, 'kind' => $kind );
		if ( 'multi' === $kind ) {
			$row['inputs']  = array( array( 'id' => $key, 'value' => '' === trim( $value ) ? array() : array_map( 'trim', explode( ', ', $value ) ) ) );
			$row['choices'] = minn_admin_elementor_edit_options( $field );
		} else {
			$row['inputs'] = array( array( 'id' => $key, 'value' => $value ) );
			if ( 'choice' === $kind ) {
				$row['choices'] = minn_admin_elementor_edit_options( $field );
			}
		}
		$fields[] = $row;
	}
	if ( ! $fields ) {
		return null;
	}
	return array( 'route' => 'minn-admin/v1/elementor/submissions/{id}/answers', 'fields' => $fields, 'locked' => $locked );
}

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'minn_admin_elementor_forms_ready' ) || ! minn_admin_elementor_forms_ready() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/elementor/submissions/(?P<id>\d+)/answers', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			$id    = (int) Minn_Admin::path_param( $request );
			$raw   = $query->get_submission( $id );
			if ( ! $raw || empty( $raw['data'] ) ) {
				return new WP_Error( 'not_found', __( 'Submission not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$block = minn_admin_elementor_edit_block( $raw['data'] );
			if ( ! $block ) {
				return new WP_Error( 'minn_ele_none', __( 'This submission has no answers that can be edited here.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$rows = array();
			foreach ( $block['fields'] as $row ) {
				$rows[ $row['id'] ] = $row;
			}
			$body     = (array) $request->get_json_params();
			$values   = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
			$original = isset( $body['original'] ) && is_array( $body['original'] ) ? $body['original'] : array();
			$refuse   = function ( $key, $message ) {
				return new WP_Error( 'minn_ele_invalid', $message, array( 'status' => 400, 'field' => (string) $key ) );
			};

			$writes = array();
			foreach ( $values as $key => $value ) {
				$key = (string) $key;
				if ( ! isset( $rows[ $key ] ) ) {
					return new WP_Error( 'minn_ele_locked', __( 'That answer can only be edited in Elementor.', 'minn-admin' ), array( 'status' => 400, 'field' => $key ) );
				}
				$row    = $rows[ $key ];
				$stored = $row['inputs'][0]['value'];
				if ( array_key_exists( $key, $original ) && wp_json_encode( $original[ $key ] ) !== wp_json_encode( $stored ) ) {
					return new WP_Error( 'minn_ele_conflict', __( 'This submission changed since you opened it. Reload it to see the latest answers, then edit again.', 'minn-admin' ), array( 'status' => 409 ) );
				}
				$allowed = isset( $row['choices'] ) ? wp_list_pluck( $row['choices'], 0 ) : array();
				if ( 'multi' === $row['kind'] ) {
					$picked = array();
					foreach ( (array) $value as $v ) {
						if ( is_scalar( $v ) && in_array( (string) $v, $allowed, true ) ) {
							$picked[] = sanitize_text_field( (string) $v );
						}
					}
					// Stored the way their form stores several choices.
					$out = implode( ', ', $picked );
				} else {
					if ( ! is_scalar( $value ) ) {
						return $refuse( $key, __( 'That answer could not be read.', 'minn-admin' ) );
					}
					$out = 'textarea' === $row['kind'] ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value );
					if ( 'email' === $row['kind'] && '' !== $out && ! is_email( $out ) ) {
						return $refuse( $key, __( 'Enter a valid email address.', 'minn-admin' ) );
					}
					if ( 'number' === $row['kind'] && '' !== $out && ! is_numeric( $out ) ) {
						return $refuse( $key, __( 'Enter a number.', 'minn-admin' ) );
					}
					if ( 'choice' === $row['kind'] && '' !== $out && ! in_array( $out, $allowed, true ) && $out !== $stored ) {
						return $refuse( $key, __( 'Choose one of the field’s choices.', 'minn-admin' ) );
					}
				}
				$was = is_array( $stored ) ? implode( ', ', $stored ) : (string) $stored;
				if ( $out !== $was ) {
					$writes[ $key ] = $out;
				}
			}
			if ( $writes && false === $query->update_submission( $id, array(), $writes ) ) {
				return new WP_Error( 'minn_ele_failed', __( 'Elementor did not save the answers.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$labels = array();
			foreach ( array_keys( $writes ) as $key ) {
				$labels[] = $rows[ $key ]['label'];
			}
			return rest_ensure_response( array(
				'changed' => $labels,
				'message' => $writes ? __( 'Answers saved', 'minn-admin' ) : __( 'Nothing changed', 'minn-admin' ),
			) );
		},
	) );
} );
