<?php
/**
 * WPForms Pro: editing an entry's answers from the entry page.
 *
 * The entry route (adapters/wpforms.php) carries the family's `edit` block
 * (contract: gravity-forms-entry-edit.php). The save runs WPForms' own entry
 * edit (Pro\Admin\Entries\Edit::process(), what their Edit Entry screen
 * submits to): each field's validate() and format(), their hooks and
 * filters, the entry_fields rows, the entry's fields JSON, the "Entry
 * edited." record and wpforms_pro_admin_entries_edit_submit_completed.
 *
 * Their edit rewrites every editable field from the submission and blanks
 * any it does not receive (a file field's files are deleted with it). So for
 * the length of the save, their own wpforms_pro_admin_entries_edit_field_
 * editable filter leaves exactly the changed fields editable: everything
 * else is kept as stored. Their handler ends by sending JSON and dying; the
 * save runs it as an AJAX request whose wp_die throws, and reads the JSON it
 * printed (field errors come back on their fields).
 *
 * Editable here: single-line and paragraph text, email, website, number,
 * phone, drop-downs, radios, checkboxes (fixed choices), names and
 * addresses. Dates, ratings, sliders, rich text, uploads, payments and
 * dynamic choices stay in WPForms. Gated on their edit_entry_single.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Minn_Admin_WPForms_Halt' ) ) {
	/** Thrown from wp_die to end their handler without ending the request. */
	class Minn_Admin_WPForms_Halt extends Exception {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}

function minn_admin_wpforms_edit_supported() {
	return class_exists( '\WPForms\Pro\Admin\Entries\Edit' ) && is_object( wpforms()->obj( 'entry' ) ) && method_exists( wpforms()->obj( 'entry' ), 'get_editable_field_types' );
}

/** A choice field's choices as [posted value, label]: their inputs post the label unless values are shown. */
function minn_admin_wpforms_edit_choices( $field ) {
	$out = array();
	foreach ( (array) ( $field['choices'] ?? array() ) as $c ) {
		if ( ! is_array( $c ) ) {
			continue;
		}
		$label  = (string) ( $c['label'] ?? '' );
		$posted = ! empty( $field['show_values'] ) && '' !== (string) ( $c['value'] ?? '' ) ? (string) $c['value'] : $label;
		$out[]  = array( $posted, wp_strip_all_tags( '' !== $label ? $label : $posted ) );
	}
	return $out;
}

/** The parts of a name or address field: [key => label]. */
function minn_admin_wpforms_edit_parts( $field ) {
	if ( 'name' === $field['type'] ) {
		$format = (string) ( $field['format'] ?? 'first-last' );
		$parts  = array( 'first' => __( 'First', 'minn-admin' ) );
		if ( 'first-middle-last' === $format ) {
			$parts['middle'] = __( 'Middle', 'minn-admin' );
		}
		$parts['last'] = __( 'Last', 'minn-admin' );
		return $parts;
	}
	$parts = array( 'address1' => __( 'Address line 1', 'minn-admin' ) );
	if ( empty( $field['address2_hide'] ) ) {
		$parts['address2'] = __( 'Address line 2', 'minn-admin' );
	}
	$parts['city']  = __( 'City', 'minn-admin' );
	$parts['state'] = __( 'State / Province / Region', 'minn-admin' );
	if ( empty( $field['postal_hide'] ) ) {
		$parts['postal'] = __( 'Zip / Postal code', 'minn-admin' );
	}
	if ( 'us' !== ( $field['scheme'] ?? 'us' ) && empty( $field['country_hide'] ) ) {
		$parts['country'] = __( 'Country', 'minn-admin' );
	}
	return $parts;
}

/**
 * The page's control for a WPForms field, or '' to leave it to them.
 *
 * A calculated field (the Calculations add-on) and every field its formula
 * reads ($F3, $F3_first) are left to WPForms too: its own entry edit re-runs
 * the formulas, which this page does not, so an edit here overwrote a total
 * or left it stale in the entry, its exports and resent notifications. The
 * Gravity Forms side recomputes; this one stays out instead.
 *
 * @param array $field       The field.
 * @param array $form_fields Every field on the form.
 */
function minn_admin_wpforms_edit_kind( $field, $form_fields = array() ) {
	$type = (string) ( $field['type'] ?? '' );
	if ( ! in_array( $type, wpforms()->obj( 'entry' )->get_editable_field_types(), true ) || ! empty( $field['dynamic_choices'] ) || ! empty( $field['calculation_is_enabled'] ) ) {
		return '';
	}
	$ref = '/\$F' . preg_quote( (string) ( $field['id'] ?? '' ), '/' ) . '(?![0-9])/';
	foreach ( (array) $form_fields as $other ) {
		if ( is_array( $other ) && ! empty( $other['calculation_is_enabled'] )
			&& preg_match( $ref, (string) ( $other['calculation_code'] ?? '' ) . ' ' . (string) ( $other['calculation_code_php'] ?? '' ) ) ) {
			return '';
		}
	}
	switch ( $type ) {
		case 'text':
			return 'text';
		case 'textarea':
			return 'textarea';
		case 'email':
			return 'email';
		case 'url':
			return 'url';
		case 'number':
			return 'number';
		case 'phone':
			return 'tel';
		case 'radio':
			return 'choice';
		case 'select':
			return ! empty( $field['multiple'] ) ? 'multi' : 'choice';
		case 'checkbox':
			return 'multi';
		case 'name':
			return 'simple' === ( $field['format'] ?? 'first-last' ) ? 'text' : 'parts';
		case 'address':
			return 'parts';
	}
	return '';
}

/** Form data and the entry's stored fields. */
function minn_admin_wpforms_edit_context( $row ) {
	$form = get_post( (int) $row->form_id );
	if ( ! $form || 'wpforms' !== $form->post_type ) {
		return null;
	}
	$form_data = (array) wpforms_decode( $form->post_content );
	$stored    = (array) wpforms_decode( (string) $row->fields );
	return array( $form_data, $stored );
}

/** The entry route's edit block, or null when the person may not edit. */
function minn_admin_wpforms_edit_block( $row ) {
	if ( ! minn_admin_wpforms_edit_supported() || ! wpforms_current_user_can( 'edit_entry_single', (int) $row->entry_id ) ) {
		return null;
	}
	$ctx = minn_admin_wpforms_edit_context( $row );
	if ( ! $ctx ) {
		return null;
	}
	list( $form_data, $stored ) = $ctx;
	$fields = array();
	$locked = array();
	foreach ( (array) ( $form_data['fields'] ?? array() ) as $fid => $field ) {
		if ( ! is_array( $field ) || in_array( $field['type'] ?? '', array( 'divider', 'html', 'pagebreak', 'content', 'layout', 'repeater', 'entry-preview', 'captcha', 'internal-information' ), true ) ) {
			continue;
		}
		$fid   = (string) ( $field['id'] ?? $fid );
		$label = wp_strip_all_tags( (string) ( $field['label'] ?? '' ) );
		$label = '' !== $label ? $label : sprintf( 'Field %s', $fid );
		$have  = isset( $stored[ $fid ] ) && is_array( $stored[ $fid ] ) ? $stored[ $fid ] : array();
		$kind  = minn_admin_wpforms_edit_kind( $field, (array) ( $form_data['fields'] ?? array() ) );
		if ( '' === $kind ) {
			if ( '' !== trim( (string) ( $have['value'] ?? '' ) ) ) {
				$locked[] = $label;
			}
			continue;
		}
		$row_out = array( 'id' => $fid, 'label' => $label, 'kind' => $kind, 'inputs' => array() );
		if ( 'parts' === $kind ) {
			foreach ( minn_admin_wpforms_edit_parts( $field ) as $key => $part ) {
				$row_out['inputs'][] = array( 'id' => $fid . '.' . $key, 'label' => $part, 'value' => (string) ( $have[ $key ] ?? '' ) );
			}
		} elseif ( 'multi' === $kind ) {
			$raw = (string) ( $have['value_raw'] ?? $have['value'] ?? '' );
			$row_out['inputs'][] = array( 'id' => $fid, 'value' => '' === $raw ? array() : array_values( array_filter( array_map( 'strval', explode( "\n", $raw ) ), 'strlen' ) ) );
			$row_out['choices']  = minn_admin_wpforms_edit_choices( $field );
		} else {
			$value = 'choice' === $kind ? (string) ( $have['value_raw'] ?? $have['value'] ?? '' ) : (string) ( $have['value'] ?? '' );
			$row_out['inputs'][] = array( 'id' => $fid, 'value' => $value );
			if ( 'choice' === $kind ) {
				$row_out['choices'] = minn_admin_wpforms_edit_choices( $field );
			}
		}
		$fields[] = $row_out;
	}
	if ( ! $fields ) {
		return null;
	}
	return array( 'route' => 'minn-admin/v1/wpforms/entries/{id}/answers', 'fields' => $fields, 'locked' => $locked );
}

/**
 * Run their entry edit for the given fields' submissions. Returns their JSON
 * response ({ success, data }) or WP_Error.
 */
function minn_admin_wpforms_edit_run( $form_id, $entry_id, array $submit ) {
	$edit           = new \WPForms\Pro\Admin\Entries\Edit();
	$edit->form_id  = (int) $form_id;
	$edit->entry_id = (int) $entry_id;
	$edit->errors   = array();
	$only           = array_map( 'strval', array_keys( $submit ) );

	$editable = function ( $is, $type, $field ) use ( $only ) {
		return $is && in_array( (string) ( $field['id'] ?? '' ), $only, true );
	};
	$ajax  = '__return_true';
	$halt  = function () {
		return function () {
			throw new Minn_Admin_WPForms_Halt();
		};
	};
	$quiet = function ( $trigger, $fn ) {
		return 'wp_send_json' === $fn ? false : $trigger;
	};
	add_filter( 'wpforms_pro_admin_entries_edit_field_editable', $editable, PHP_INT_MAX, 3 );
	add_filter( 'wp_doing_ajax', $ajax, PHP_INT_MAX );
	add_filter( 'wp_die_ajax_handler', $halt, PHP_INT_MAX );
	add_filter( 'doing_it_wrong_trigger_error', $quiet, 10, 2 );

	/** Their hook before an edit is processed (ajax_submit fires it). */
	do_action( 'wpforms_pro_admin_entries_edit_submit_before_processing', (int) $form_id, (int) $entry_id );
	ob_start();
	$error = null;
	try {
		( function ( $entry ) {
			$this->process( $entry );
		} )->call( $edit, array( 'id' => (int) $form_id, 'entry_id' => (int) $entry_id, 'fields' => $submit ) );
	} catch ( Minn_Admin_WPForms_Halt $h ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Their handler finished: it printed its JSON.
	} catch ( \Throwable $e ) {
		$error = $e;
	}
	$out = (string) ob_get_clean();
	remove_filter( 'wpforms_pro_admin_entries_edit_field_editable', $editable, PHP_INT_MAX );
	remove_filter( 'wp_doing_ajax', $ajax, PHP_INT_MAX );
	remove_filter( 'wp_die_ajax_handler', $halt, PHP_INT_MAX );
	remove_filter( 'doing_it_wrong_trigger_error', $quiet, 10 );

	if ( $error ) {
		return new WP_Error( 'minn_wpe_failed', $error->getMessage(), array( 'status' => 500 ) );
	}
	$res = json_decode( $out, true );
	if ( ! is_array( $res ) ) {
		return new WP_Error( 'minn_wpe_failed', __( 'WPForms did not answer the save.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return $res;
}

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'minn_admin_wpforms_active' ) || ! minn_admin_wpforms_active() || ! minn_admin_wpforms_edit_supported() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/wpforms/entries/(?P<id>\d+)/answers', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			return wpforms_current_user_can( 'edit_entry_single', (int) Minn_Admin::path_param( $request ) );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$id    = (int) Minn_Admin::path_param( $request );
			$guard = minn_admin_wpforms_guard_entry( $id, 'edit_entries_form_single' );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$table = minn_admin_wpforms_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d", $id ) );
			$block = $row ? minn_admin_wpforms_edit_block( $row ) : null;
			if ( ! $block ) {
				return new WP_Error( 'minn_wpe_none', __( 'This entry has no answers that can be edited here.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			list( $form_data ) = minn_admin_wpforms_edit_context( $row );

			$inputs = array();
			foreach ( $block['fields'] as $f ) {
				foreach ( $f['inputs'] as $i ) {
					$inputs[ $i['id'] ] = array( 'row' => $f, 'value' => $i['value'] );
				}
			}
			$body     = (array) $request->get_json_params();
			$values   = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
			$original = isset( $body['original'] ) && is_array( $body['original'] ) ? $body['original'] : array();
			$refuse   = function ( $input, $message ) {
				return new WP_Error( 'minn_wpe_invalid', $message, array( 'status' => 400, 'field' => (string) $input ) );
			};

			// The fields the edit touches, each with every part's value.
			$next = array();
			foreach ( $values as $input => $value ) {
				$input = (string) $input;
				if ( ! isset( $inputs[ $input ] ) ) {
					return new WP_Error( 'minn_wpe_locked', __( 'That answer can only be edited in WPForms.', 'minn-admin' ), array( 'status' => 400, 'field' => $input ) );
				}
				if ( array_key_exists( $input, $original ) && wp_json_encode( $original[ $input ] ) !== wp_json_encode( $inputs[ $input ]['value'] ) ) {
					return new WP_Error( 'minn_wpe_conflict', __( 'This entry changed since you opened it. Reload it to see the latest answers, then edit again.', 'minn-admin' ), array( 'status' => 409 ) );
				}
				$field_row = $inputs[ $input ]['row'];
				$allowed   = isset( $field_row['choices'] ) ? wp_list_pluck( $field_row['choices'], 0 ) : array();
				if ( 'multi' === $field_row['kind'] ) {
					$value = array_values( array_filter( (array) $value, function ( $v ) use ( $allowed ) {
						return is_scalar( $v ) && in_array( (string) $v, $allowed, true );
					} ) );
				} elseif ( ! is_scalar( $value ) ) {
					return $refuse( $input, __( 'That answer could not be read.', 'minn-admin' ) );
				} else {
					$value = (string) $value;
					if ( 'email' === $field_row['kind'] && '' !== $value && ! is_email( $value ) ) {
						return $refuse( $input, __( 'Enter a valid email address.', 'minn-admin' ) );
					}
					if ( 'choice' === $field_row['kind'] && '' !== $value && ! in_array( $value, $allowed, true ) && $value !== $inputs[ $input ]['value'] ) {
						return $refuse( $input, __( 'Choose one of the field’s choices.', 'minn-admin' ) );
					}
				}
				if ( wp_json_encode( $value ) === wp_json_encode( $inputs[ $input ]['value'] ) ) {
					continue;
				}
				$next[ $field_row['id'] ][ $input ] = $value;
			}
			if ( ! $next ) {
				return rest_ensure_response( array( 'changed' => array(), 'message' => __( 'Nothing changed', 'minn-admin' ) ) );
			}

			// Their submission shapes: name and address parts by key, an email
			// with a confirmation as primary + secondary, several choices as a list.
			$submit = array();
			$labels = array();
			foreach ( $block['fields'] as $f ) {
				if ( ! isset( $next[ $f['id'] ] ) ) {
					continue;
				}
				$labels[] = $f['label'];
				$field    = $form_data['fields'][ $f['id'] ] ?? array();
				if ( 'parts' === $f['kind'] ) {
					$parts = array();
					foreach ( $f['inputs'] as $i ) {
						$key           = substr( $i['id'], strlen( $f['id'] ) + 1 );
						$parts[ $key ] = $next[ $f['id'] ][ $i['id'] ] ?? $i['value'];
					}
					$submit[ $f['id'] ] = $parts;
				} else {
					$v = $next[ $f['id'] ][ $f['id'] ];
					$submit[ $f['id'] ] = 'email' === $f['kind'] && ! empty( $field['confirmation'] ) ? array( 'primary' => $v, 'secondary' => $v ) : $v;
				}
			}

			$res = minn_admin_wpforms_edit_run( (int) $row->form_id, $id, $submit );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( empty( $res['success'] ) ) {
				$errors = isset( $res['data']['errors'] ) && is_array( $res['data']['errors'] ) ? $res['data']['errors'] : array();
				foreach ( (array) ( $errors['field'] ?? array() ) as $fid => $msg ) {
					$input = (string) $fid;
					if ( is_array( $msg ) ) {
						$key   = (string) key( $msg );
						$msg   = (string) reset( $msg );
						$input = isset( $inputs[ $fid . '.' . $key ] ) ? $fid . '.' . $key : $input;
					}
					return $refuse( $input, wp_strip_all_tags( (string) $msg ) );
				}
				$general = trim( wp_strip_all_tags( (string) ( $errors['general'] ?? '' ) ) );
				return new WP_Error( 'minn_wpe_refused', '' !== $general ? $general : __( 'WPForms did not save the answers.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'changed' => $labels, 'message' => __( 'Answers saved', 'minn-admin' ) ) );
		},
	) );
} );
