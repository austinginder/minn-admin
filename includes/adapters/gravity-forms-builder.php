<?php
/**
 * Gravity Forms: the form builder.
 *
 * The page at /gravity-forms/form/{id}: the form's fields laid out on the
 * same 12-column grid Gravity Forms renders, a settings panel for the
 * selected field, an Add field palette, drag to reorder, and one Save.
 *
 * What a field offers is Gravity Forms' own answer. Every field type
 * declares its editor settings (get_form_editor_field_settings()), and the
 * builder edits the ones on that list it knows how to store. A type Minn
 * has never heard of (an add-on's) still lists, moves, resizes and
 * relabels, and every property the builder does not edit rides through
 * the save untouched.
 *
 * Saves go through Gravity Forms' own editor save (GF_Form_CRUD_Handler),
 * the path their Save button takes: the same sanitizing, the same
 * duplicate-title check, gform_after_save_form for add-ons, and a removed
 * field is deleted the way their editor deletes one, its entry values,
 * conditional-logic references and notification routing included. That
 * part is deliberate: Gravity Forms gives the next new field the highest
 * id plus one, so values a deleted field left behind would surface under
 * the next field that reuses its id.
 *
 * New fields get the defaults their editor gives the same type
 * (SetDefaultValues in js.php): sub-inputs for Name, Address, Email
 * confirmation, Date and Time, one input per checkbox choice (skipping
 * multiples of ten, as theirs does), and consent's three inputs.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Their editor's save service class (GF 2.6+, namespaced). */
const MINN_ADMIN_GFB_SAVE_PROVIDER = '\\Gravity_Forms\\Gravity_Forms\\Save_Form\\GF_Save_Form_Service_Provider';

/**
 * The handler their editor's Save button uses, or null on a release
 * without one.
 *
 * @return object|null
 */
function minn_admin_gfb_crud_handler() {
	if ( ! class_exists( 'GFForms' ) || ! method_exists( 'GFForms', 'get_service_container' ) || ! class_exists( MINN_ADMIN_GFB_SAVE_PROVIDER ) ) {
		return null;
	}
	$key     = constant( MINN_ADMIN_GFB_SAVE_PROVIDER . '::GF_FORM_CRUD_HANDLER' );
	$handler = GFForms::get_service_container()->get( $key );
	return $handler && method_exists( $handler, 'save' ) ? $handler : null;
}

/** Whether the builder can run: GFAPI, the field registry and their editor's save. */
function minn_admin_gfb_available() {
	return class_exists( 'GFAPI' ) && class_exists( 'GF_Fields' ) && class_exists( MINN_ADMIN_GFB_SAVE_PROVIDER );
}

/**
 * The types the Add field palette offers, by group. Each one has a
 * defaults recipe in minn_admin_gfb_new_field(); a type missing from this
 * install (an older release) simply drops out.
 *
 * @return array[] { group, label, types[] }
 */
function minn_admin_gfb_palette( $form_id = 0 ) {
	$groups = array(
		array(
			'group' => 'standard',
			'label' => __( 'Standard', 'minn-admin' ),
			'types' => array( 'text', 'textarea', 'select', 'multiselect', 'number', 'checkbox', 'radio', 'hidden', 'html', 'section', 'page' ),
		),
		array(
			'group' => 'advanced',
			'label' => __( 'Advanced', 'minn-admin' ),
			'types' => array( 'name', 'email', 'phone', 'address', 'website', 'date', 'time', 'fileupload', 'consent', 'list' ),
		),
	);
	foreach ( $groups as &$g ) {
		$types = array();
		foreach ( $g['types'] as $type ) {
			$field = GF_Fields::get( $type );
			if ( ! $field ) {
				continue;
			}
			$entry = array( 'type' => $type, 'title' => (string) $field->get_form_editor_field_title() );
			// With a form, each type carries the row a new field of it starts
			// as: the defaults and the settings the save will apply, so the
			// page draws and configures it before it has an id.
			if ( $form_id ) {
				$entry['proto'] = minn_admin_gfb_field_row( GF_Fields::create( minn_admin_gfb_new_field( $type, 0, $form_id ) ) );
			}
			$types[] = $entry;
		}
		$g['types'] = $types;
	}
	unset( $g );
	return $groups;
}

/** Types the palette may create. */
function minn_admin_gfb_creatable() {
	$out = array();
	foreach ( minn_admin_gfb_palette() as $g ) {
		foreach ( $g['types'] as $t ) {
			$out[] = $t['type'];
		}
	}
	return $out;
}

/**
 * Gravity Forms editor settings → the field properties the builder edits
 * for each. A setting missing here (input masks, calculations, enhanced UI,
 * label placement…) is not offered, and its property is kept as stored.
 */
function minn_admin_gfb_setting_map() {
	return array(
		'label_setting'                   => array( 'label' ),
		'description_setting'             => array( 'description' ),
		'rules_setting'                   => array( 'isRequired' ),
		'duplicate_setting'               => array( 'noDuplicates' ),
		'placeholder_setting'             => array( 'placeholder' ),
		'placeholder_textarea_setting'    => array( 'placeholder' ),
		'default_value_setting'           => array( 'defaultValue' ),
		'default_value_textarea_setting'  => array( 'defaultValue' ),
		'maxlen_setting'                  => array( 'maxLength' ),
		'admin_label_setting'             => array( 'adminLabel' ),
		'css_class_setting'               => array( 'cssClass' ),
		'visibility_setting'              => array( 'visibility' ),
		'error_message_setting'           => array( 'errorMessage' ),
		'conditional_logic_field_setting' => array( 'conditionalLogic' ),
		'conditional_logic_page_setting'  => array( 'conditionalLogic' ),
		'choices_setting'                 => array( 'choices' ),
		'range_setting'                   => array( 'rangeMin', 'rangeMax' ),
		'number_format_setting'           => array( 'numberFormat' ),
		'phone_format_setting'            => array( 'phoneFormat' ),
		'date_input_type_setting'         => array( 'dateType' ),
		'date_format_setting'             => array( 'dateFormat' ),
		'time_format_setting'             => array( 'timeFormat' ),
		'address_setting'                 => array( 'addressType', 'subInputs' ),
		'name_setting'                    => array( 'subInputs' ),
		'email_confirm_setting'           => array( 'emailConfirmEnabled' ),
		'file_extensions_setting'         => array( 'allowedExtensions' ),
		'multiple_files_setting'          => array( 'multipleFiles', 'maxFiles' ),
		'file_size_setting'               => array( 'maxFileSize' ),
		'checkbox_label_setting'          => array( 'checkboxLabel' ),
		'content_setting'                 => array( 'content' ),
		'next_button_setting'             => array( 'nextButtonText' ),
		'previous_button_setting'         => array( 'previousButtonText' ),
	);
}

/**
 * The properties one field offers in the builder: its type's declared
 * editor settings, mapped, minus the cases the builder can't store
 * faithfully.
 *
 * @param GF_Field $field The field.
 * @return string[]
 */
function minn_admin_gfb_field_settings( $field ) {
	$map  = minn_admin_gfb_setting_map();
	$keys = array();
	foreach ( (array) $field->get_form_editor_field_settings() as $setting ) {
		if ( isset( $map[ $setting ] ) ) {
			$keys = array_merge( $keys, $map[ $setting ] );
		}
	}
	$type = (string) $field->type;
	// Choices are edited as text / value / selected only, so a type whose
	// choices carry more (prices, image choices, keyed multi-choice inputs)
	// keeps them in Gravity Forms' editor.
	if ( in_array( 'choices', $keys, true )
		&& ( ! in_array( $type, array( 'select', 'multiselect', 'checkbox', 'radio' ), true ) || ! empty( $field->enablePrice ) ) ) {
		$keys = array_diff( $keys, array( 'choices' ) );
	}
	// A hidden field is never shown, so there is nothing to require.
	if ( 'hidden' === $type ) {
		$keys = array_diff( $keys, array( 'isRequired' ) );
	}
	// Width: every field that takes a column. Page breaks and hidden fields
	// take none, and a section break always spans the form.
	if ( ! in_array( $type, array( 'page', 'hidden', 'section' ), true ) ) {
		$keys[] = 'span';
	}
	return array_values( array_unique( $keys ) );
}

/** The field as the plain array their editor round-trips. */
function minn_admin_gfb_field_array( $field ) {
	$arr = json_decode( wp_json_encode( $field ), true );
	return is_array( $arr ) ? $arr : array();
}

/** Normalize stored conditional logic for the client ('' when none). */
function minn_admin_gfb_logic_out( $logic ) {
	if ( ! is_array( $logic ) || empty( $logic['rules'] ) || ! is_array( $logic['rules'] ) ) {
		return null;
	}
	$rules = array();
	foreach ( $logic['rules'] as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$rules[] = array(
			'fieldId'  => (string) ( $r['fieldId'] ?? '' ),
			'operator' => (string) ( $r['operator'] ?? 'is' ),
			'value'    => (string) ( $r['value'] ?? '' ),
		);
	}
	return array(
		'actionType' => 'hide' === ( $logic['actionType'] ?? '' ) ? 'hide' : 'show',
		'logicType'  => 'any' === ( $logic['logicType'] ?? '' ) ? 'any' : 'all',
		'rules'      => $rules,
	);
}

/**
 * One field as the builder reads it: what it shows on the canvas and the
 * values of the settings it edits.
 *
 * @param GF_Field $field The field.
 * @return array
 */
function minn_admin_gfb_field_row( $field ) {
	$inputs = array();
	if ( is_array( $field->inputs ) ) {
		foreach ( $field->inputs as $in ) {
			$inputs[] = array(
				'id'          => (string) rgar( $in, 'id' ),
				'label'       => (string) rgar( $in, 'label' ),
				'customLabel' => (string) rgar( $in, 'customLabel' ),
				'placeholder' => (string) rgar( $in, 'placeholder' ),
				'isHidden'    => (bool) rgar( $in, 'isHidden' ),
			);
		}
	}
	$choices = null;
	$custom  = false;
	if ( is_array( $field->choices ) ) {
		$choices = array();
		foreach ( $field->choices as $c ) {
			$text  = (string) rgar( $c, 'text' );
			$value = (string) rgar( $c, 'value' );
			if ( $value !== $text ) {
				$custom = true;
			}
			$choices[] = array( 'text' => $text, 'value' => $value, 'isSelected' => (bool) rgar( $c, 'isSelected' ) );
		}
	}
	return array(
		'id'                  => (int) $field->id,
		'type'                => (string) $field->type,
		'inputType'           => (string) $field->get_input_type(),
		'title'               => (string) $field->get_form_editor_field_title(),
		'settings'            => minn_admin_gfb_field_settings( $field ),
		'logicSource'         => (bool) $field->is_conditional_logic_supported(),
		'label'               => (string) $field->label,
		'description'         => (string) $field->description,
		'isRequired'          => (bool) $field->isRequired,
		'noDuplicates'        => (bool) $field->noDuplicates,
		'placeholder'         => (string) $field->placeholder,
		'defaultValue'        => is_scalar( $field->defaultValue ) ? (string) $field->defaultValue : '',
		'maxLength'           => $field->maxLength ? (string) $field->maxLength : '',
		'adminLabel'          => (string) $field->adminLabel,
		'cssClass'            => (string) $field->cssClass,
		'visibility'          => $field->visibility ? (string) $field->visibility : 'visible',
		'errorMessage'        => (string) $field->errorMessage,
		'span'                => $field->layoutGridColumnSpan ? max( 1, min( 12, (int) $field->layoutGridColumnSpan ) ) : 12,
		'spacer'              => (int) $field->layoutSpacerGridColumnSpan,
		'choices'             => $choices,
		'enableChoiceValue'   => $field->enableChoiceValue ? true : $custom,
		'inputs'              => $inputs ? $inputs : null,
		'rangeMin'            => is_numeric( $field->rangeMin ) ? (string) $field->rangeMin : '',
		'rangeMax'            => is_numeric( $field->rangeMax ) ? (string) $field->rangeMax : '',
		'numberFormat'        => $field->numberFormat ? (string) $field->numberFormat : 'decimal_dot',
		'phoneFormat'         => (string) $field->phoneFormat,
		'dateType'            => $field->dateType ? (string) $field->dateType : 'datepicker',
		'dateFormat'          => $field->dateFormat ? (string) $field->dateFormat : 'mdy',
		'timeFormat'          => '24' === (string) $field->timeFormat ? '24' : '12',
		'addressType'         => (string) $field->addressType,
		'emailConfirmEnabled' => (bool) $field->emailConfirmEnabled,
		'allowedExtensions'   => (string) $field->allowedExtensions,
		'multipleFiles'       => (bool) $field->multipleFiles,
		'maxFiles'            => $field->maxFiles ? (string) $field->maxFiles : '',
		'maxFileSize'         => $field->maxFileSize ? (string) $field->maxFileSize : '',
		'checkboxLabel'       => (string) $field->checkboxLabel,
		'content'             => (string) $field->content,
		'nextButtonText'      => (string) rgars( (array) $field->nextButton, 'text' ),
		'previousButtonText'  => (string) rgars( (array) $field->previousButton, 'text' ),
		'conditionalLogic'    => minn_admin_gfb_logic_out( $field->conditionalLogic ),
	);
}

/** Choices for the enum settings, from Gravity Forms' own lists where it has one. */
function minn_admin_gfb_enums( $form_id ) {
	// The save reads these once per field; their lists run filters.
	static $cache = array();
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$pairs = function ( $list ) {
		$out = array();
		foreach ( $list as $k => $v ) {
			$out[] = array( (string) $k, (string) ( is_array( $v ) ? rgar( $v, 'label' ) : $v ) );
		}
		return $out;
	};
	$phone   = GF_Fields::get( 'phone' );
	$address = GF_Fields::get( 'address' );
	return $cache[ $form_id ] = array( // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
		'visibility'   => array(
			array( 'visible', __( 'Visible', 'minn-admin' ) ),
			array( 'hidden', __( 'Hidden', 'minn-admin' ) ),
			array( 'administrative', __( 'Administrative', 'minn-admin' ) ),
		),
		'numberFormat' => array(
			array( 'decimal_dot', '9,999.99' ),
			array( 'decimal_comma', '9.999,99' ),
			array( 'currency', __( 'Currency', 'minn-admin' ) ),
		),
		'phoneFormat'  => $phone && method_exists( $phone, 'get_phone_formats' ) ? $pairs( $phone->get_phone_formats( $form_id ) ) : array(),
		'addressType'  => $address && method_exists( $address, 'get_address_types' ) ? $pairs( $address->get_address_types( $form_id ) ) : array(),
		'dateType'     => array(
			array( 'datepicker', __( 'Date picker', 'minn-admin' ) ),
			array( 'datefield', __( 'Date field', 'minn-admin' ) ),
			array( 'datedropdown', __( 'Date drop down', 'minn-admin' ) ),
		),
		'dateFormat'   => array(
			array( 'mdy', 'mm/dd/yyyy' ),
			array( 'dmy', 'dd/mm/yyyy' ),
			array( 'dmy_dash', 'dd-mm-yyyy' ),
			array( 'dmy_dot', 'dd.mm.yyyy' ),
			array( 'ymd_slash', 'yyyy/mm/dd' ),
			array( 'ymd_dash', 'yyyy-mm-dd' ),
			array( 'ymd_dot', 'yyyy.mm.dd' ),
		),
		'timeFormat'   => array(
			array( '12', __( '12 hour', 'minn-admin' ) ),
			array( '24', __( '24 hour', 'minn-admin' ) ),
		),
		'operator'     => array(
			array( 'is', __( 'is', 'minn-admin' ) ),
			array( 'isnot', __( 'is not', 'minn-admin' ) ),
			array( '>', __( 'greater than', 'minn-admin' ) ),
			array( '<', __( 'less than', 'minn-admin' ) ),
			array( 'contains', __( 'contains', 'minn-admin' ) ),
			array( 'starts_with', __( 'starts with', 'minn-admin' ) ),
			array( 'ends_with', __( 'ends with', 'minn-admin' ) ),
		),
	);
}

/** The whole-form payload the builder reads and every save returns. */
function minn_admin_gfb_payload( $form_id ) {
	$meta = GFFormsModel::get_form_meta( $form_id );
	$row  = GFFormsModel::get_form( $form_id );
	$fields = array();
	foreach ( (array) rgar( $meta, 'fields' ) as $field ) {
		$fields[] = minn_admin_gfb_field_row( $field );
	}
	return array(
		'form'    => array(
			'id'          => (int) $form_id,
			'title'       => (string) rgar( $meta, 'title' ),
			'description' => (string) rgar( $meta, 'description' ),
			'active'      => $row ? (bool) $row->is_active : true,
			'buttonText'  => (string) rgars( $meta, 'button/text' ),
			'entries'     => (int) GFAPI::count_entries( $form_id ),
			'adminUrl'    => admin_url( 'admin.php?page=gf_edit_forms&id=' . (int) $form_id ),
			'previewUrl'  => trailingslashit( site_url() ) . '?gf_page=preview&id=' . (int) $form_id,
		),
		'fields'  => $fields,
		'palette'    => minn_admin_gfb_palette( $form_id ),
		'enums'      => minn_admin_gfb_enums( $form_id ),
		// The merge tag picker: the form's tags for HTML content, and the
		// short list their editor offers for default values.
		'mergeTags'  => function_exists( 'minn_admin_gfn_merge_tags' ) && is_array( $meta ) ? minn_admin_gfn_merge_tags( $meta, '' ) : array(),
		'prepopTags' => minn_admin_gfb_prepop_tags(),
	);
}

/**
 * The tags their editor offers in a default value (form_admin.js
 * getMergeTags with isPrepop): no field, entry or form tags, since none of
 * those exist yet when a default is filled in.
 */
function minn_admin_gfb_prepop_tags() {
	$tags = array(
		'{ip}'                    => __( 'User IP Address', 'minn-admin' ),
		'{date_mdy}'              => __( 'Date (mm/dd/yyyy)', 'minn-admin' ),
		'{date_dmy}'              => __( 'Date (dd/mm/yyyy)', 'minn-admin' ),
		'{embed_post:ID}'         => __( 'Embed Post/Page Id', 'minn-admin' ),
		'{embed_post:post_title}' => __( 'Embed Post/Page Title', 'minn-admin' ),
		'{embed_url}'             => __( 'Embed URL', 'minn-admin' ),
		'{user_agent}'            => __( 'HTTP User Agent', 'minn-admin' ),
		'{referer}'               => __( 'HTTP Referer URL', 'minn-admin' ),
		'{user:display_name}'     => __( 'User Display Name', 'minn-admin' ),
		'{user:user_email}'       => __( 'User Email', 'minn-admin' ),
		'{user:user_login}'       => __( 'User Login', 'minn-admin' ),
	);
	$out = array();
	foreach ( $tags as $tag => $label ) {
		$out[] = array( 'tag' => $tag, 'label' => $label );
	}
	return array( array( 'label' => __( 'Other', 'minn-admin' ), 'tags' => $out ) );
}

/** Input objects the way their editor's Input() builds them. */
function minn_admin_gfb_input( $id, $label, $autocomplete = null ) {
	$in = array( 'id' => (string) $id, 'label' => $label, 'name' => '' );
	if ( null !== $autocomplete ) {
		$in['autocompleteAttribute'] = $autocomplete;
	}
	return $in;
}

/** Checkbox inputs for a choice list: x.1 … skipping multiples of ten (x.10 would read as x.1). */
function minn_admin_gfb_checkbox_inputs( $id, $choices ) {
	$inputs = array();
	$skip   = 0;
	foreach ( array_values( (array) $choices ) as $i => $c ) {
		if ( 0 === ( $i + 1 + $skip ) % 10 ) {
			++$skip;
		}
		$inputs[] = minn_admin_gfb_input( $id . '.' . ( $i + 1 + $skip ), (string) rgar( $c, 'text' ) );
	}
	return $inputs;
}

/** Date sub-inputs for a date type (none for the date picker). */
function minn_admin_gfb_date_inputs( $id, $date_type ) {
	if ( 'datefield' !== $date_type && 'datedropdown' !== $date_type ) {
		return null;
	}
	$inputs = array(
		minn_admin_gfb_input( $id . '.1', __( 'Month', 'minn-admin' ) ),
		minn_admin_gfb_input( $id . '.2', __( 'Day', 'minn-admin' ) ),
		minn_admin_gfb_input( $id . '.3', __( 'Year', 'minn-admin' ) ),
	);
	if ( 'datedropdown' === $date_type ) {
		foreach ( $inputs as &$in ) {
			$in['placeholder'] = $in['label'];
		}
		unset( $in );
	}
	return $inputs;
}

/** Email inputs with confirmation on (none when it is off). */
function minn_admin_gfb_email_inputs( $id, $placeholder = '' ) {
	$first = minn_admin_gfb_input( $id, __( 'Enter Email', 'minn-admin' ), 'email' );
	if ( '' !== (string) $placeholder ) {
		$first['placeholder'] = (string) $placeholder;
	}
	return array( $first, minn_admin_gfb_input( $id . '.2', __( 'Confirm Email', 'minn-admin' ), 'email' ) );
}

/**
 * A new field of $type with the defaults their editor gives it.
 *
 * @param string $type    Field type (one of minn_admin_gfb_creatable()).
 * @param int    $id      The id it takes.
 * @param int    $form_id The form.
 * @return array
 */
function minn_admin_gfb_new_field( $type, $id, $form_id ) {
	$proto = GF_Fields::get( $type );
	$f     = array(
		'id'                   => $id,
		'formId'               => $form_id,
		'type'                 => $type,
		'label'                => $proto ? (string) $proto->get_form_editor_field_title() : '',
		'adminLabel'           => '',
		'isRequired'           => false,
		'size'                 => 'large',
		'errorMessage'         => '',
		'visibility'           => 'visible',
		'inputs'               => null,
		'description'          => '',
		'cssClass'             => '',
		'layoutGridColumnSpan' => 12,
	);
	$three = array(
		array( 'text' => __( 'First Choice', 'minn-admin' ), 'value' => __( 'First Choice', 'minn-admin' ), 'isSelected' => false, 'price' => '' ),
		array( 'text' => __( 'Second Choice', 'minn-admin' ), 'value' => __( 'Second Choice', 'minn-admin' ), 'isSelected' => false, 'price' => '' ),
		array( 'text' => __( 'Third Choice', 'minn-admin' ), 'value' => __( 'Third Choice', 'minn-admin' ), 'isSelected' => false, 'price' => '' ),
	);
	switch ( $type ) {
		case 'section':
		case 'html':
			$f['displayOnly'] = true;
			if ( 'html' === $type ) {
				$f['content'] = '';
			}
			break;
		case 'page':
			$f['label']          = '';
			$f['displayOnly']    = true;
			$f['nextButton']     = array( 'type' => 'text', 'text' => __( 'Next', 'minn-admin' ), 'imageUrl' => '' );
			$f['previousButton'] = array( 'type' => 'text', 'text' => __( 'Previous', 'minn-admin' ), 'imageUrl' => '' );
			unset( $f['layoutGridColumnSpan'] );
			break;
		case 'name':
			$prefixes = array();
			foreach ( explode( ', ', __( 'Mr., Mrs., Miss, Ms., Mx., Dr., Prof., Rev.', 'minn-admin' ) ) as $p ) {
				$p = wp_strip_all_tags( $p );
				if ( '' !== $p ) {
					$prefixes[] = array( 'text' => $p, 'value' => $p );
				}
			}
			$prefix              = minn_admin_gfb_input( $id . '.2', gf_apply_filters( array( 'gform_name_prefix', $form_id ), __( 'Prefix', 'minn-admin' ), $form_id ), 'honorific-prefix' );
			$prefix['choices']   = $prefixes;
			$prefix['isHidden']  = true;
			$prefix['inputType'] = 'radio';
			$middle              = minn_admin_gfb_input( $id . '.4', gf_apply_filters( array( 'gform_name_middle', $form_id ), __( 'Middle', 'minn-admin' ), $form_id ), 'additional-name' );
			$middle['isHidden']  = true;
			$suffix              = minn_admin_gfb_input( $id . '.8', gf_apply_filters( array( 'gform_name_suffix', $form_id ), __( 'Suffix', 'minn-admin' ), $form_id ), 'honorific-suffix' );
			$suffix['isHidden']  = true;
			$f['nameFormat']     = 'advanced';
			$f['validateState']  = true;
			$f['inputs']         = array(
				$prefix,
				minn_admin_gfb_input( $id . '.3', gf_apply_filters( array( 'gform_name_first', $form_id ), __( 'First', 'minn-admin' ), $form_id ), 'given-name' ),
				$middle,
				minn_admin_gfb_input( $id . '.6', gf_apply_filters( array( 'gform_name_last', $form_id ), __( 'Last', 'minn-admin' ), $form_id ), 'family-name' ),
				$suffix,
			);
			break;
		case 'checkbox':
			$f['choices']       = $three;
			$f['validateState'] = true;
			$f['inputs']        = minn_admin_gfb_checkbox_inputs( $id, $three );
			break;
		case 'radio':
		case 'select':
			$f['choices']       = $three;
			$f['validateState'] = true;
			break;
		case 'multiselect':
			$f['choices']       = $three;
			$f['validateState'] = true;
			$f['storageType']   = 'json';
			break;
		case 'address':
			$address            = GF_Fields::get( 'address' );
			$f['addressType']   = $address ? (string) $address->get_default_address_type( $form_id ) : 'international';
			$f['validateState'] = true;
			$f['inputs']        = array(
				minn_admin_gfb_input( $id . '.1', gf_apply_filters( array( 'gform_address_street', $form_id ), __( 'Street Address', 'minn-admin' ), $form_id ), 'address-line1' ),
				minn_admin_gfb_input( $id . '.2', gf_apply_filters( array( 'gform_address_street2', $form_id ), __( 'Address Line 2', 'minn-admin' ), $form_id ), 'address-line2' ),
				minn_admin_gfb_input( $id . '.3', gf_apply_filters( array( 'gform_address_city', $form_id ), __( 'City', 'minn-admin' ), $form_id ), 'address-level2' ),
				minn_admin_gfb_input( $id . '.4', gf_apply_filters( array( 'gform_address_state', $form_id ), __( 'State / Province', 'minn-admin' ), $form_id ), 'address-level1' ),
				minn_admin_gfb_input( $id . '.5', gf_apply_filters( array( 'gform_address_zip', $form_id ), __( 'ZIP / Postal Code', 'minn-admin' ), $form_id ), 'postal-code' ),
				minn_admin_gfb_input( $id . '.6', gf_apply_filters( array( 'gform_address_country', $form_id ), __( 'Country', 'minn-admin' ), $form_id ), 'country-name' ),
			);
			break;
		case 'email':
			$f['autocompleteAttribute'] = 'email';
			break;
		case 'number':
			$f['numberFormat'] = 'decimal_dot';
			break;
		case 'phone':
			$f['phoneFormat']           = 'formatted';
			$f['autocompleteAttribute'] = 'tel';
			break;
		case 'date':
			$f['dateType'] = 'datefield';
			$f['inputs']   = minn_admin_gfb_date_inputs( $id, 'datefield' );
			break;
		case 'time':
			$f['inputs'] = array(
				minn_admin_gfb_input( $id . '.1', __( 'Hour', 'minn-admin' ) ),
				minn_admin_gfb_input( $id . '.2', __( 'Minute', 'minn-admin' ) ),
				minn_admin_gfb_input( $id . '.3', __( 'AM/PM', 'minn-admin' ) ),
			);
			break;
		case 'website':
			$f['autocompleteAttribute'] = 'url';
			$f['placeholder']           = 'https://';
			break;
		case 'fileupload':
			$f['storageType'] = 'json';
			break;
		case 'hidden':
			$f['validateState'] = true;
			unset( $f['layoutGridColumnSpan'] );
			break;
		case 'consent':
			$consent              = minn_admin_gfb_input( $id . '.1', __( 'Consent', 'minn-admin' ) );
			$text                 = minn_admin_gfb_input( $id . '.2', __( 'Text', 'minn-admin' ) );
			$text['isHidden']     = true;
			$desc                 = minn_admin_gfb_input( $id . '.3', __( 'Description', 'minn-admin' ) );
			$desc['isHidden']     = true;
			$f['inputs']          = array( $consent, $text, $desc );
			$f['checkboxLabel']   = __( 'I agree to the privacy policy.', 'minn-admin' );
			$f['inputType']       = 'consent';
			$f['choices']         = array( array( 'text' => __( 'Checked', 'minn-admin' ), 'value' => '1', 'isSelected' => false, 'price' => '' ) );
			break;
	}
	return $f;
}

/** Loose sameness for builder values: arrays by key, scalars as strings (true ≡ '1', false ≡ ''). */
function minn_admin_gfb_same( $a, $b ) {
	if ( is_array( $a ) || is_array( $b ) ) {
		if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $k => $v ) {
			if ( ! array_key_exists( $k, $b ) || ! minn_admin_gfb_same( $v, $b[ $k ] ) ) {
				return false;
			}
		}
		return true;
	}
	$s = function ( $v ) {
		return is_bool( $v ) ? ( $v ? '1' : '' ) : (string) $v;
	};
	return $s( $a ) === $s( $b );
}

/**
 * The part of a submitted row that differs from the field as the builder
 * read it, so an untouched setting is never rewritten (a stored '' width
 * stays '', unchanged checkbox choices keep their inputs). Choices travel
 * with their show-values flag, since one decides how the other is read.
 *
 * @param GF_Field $field The stored field.
 * @param array    $row   The submitted row.
 * @return array
 */
function minn_admin_gfb_changed( $field, $row ) {
	$base = minn_admin_gfb_field_row( $field );
	$out  = array();
	foreach ( $row as $k => $v ) {
		if ( array_key_exists( $k, $base ) && ! minn_admin_gfb_same( $base[ $k ], $v ) ) {
			$out[ $k ] = $v;
		}
	}
	// Sub-inputs arrive as their own list (show/hide and sub-label per part).
	if ( isset( $row['subInputs'] ) && is_array( $row['subInputs'] ) ) {
		$was = array();
		foreach ( (array) $base['inputs'] as $in ) {
			$was[] = array( 'id' => $in['id'], 'isHidden' => $in['isHidden'], 'customLabel' => $in['customLabel'] );
		}
		$now = array();
		foreach ( $row['subInputs'] as $in ) {
			$now[] = array( 'id' => (string) ( $in['id'] ?? '' ), 'isHidden' => ! empty( $in['isHidden'] ), 'customLabel' => (string) ( $in['customLabel'] ?? '' ) );
		}
		if ( ! minn_admin_gfb_same( $was, $now ) ) {
			$out['subInputs'] = $row['subInputs'];
		}
	}
	if ( isset( $out['choices'] ) || isset( $out['enableChoiceValue'] ) ) {
		$out['choices']           = $row['choices'] ?? $base['choices'];
		$out['enableChoiceValue'] = $row['enableChoiceValue'] ?? $base['enableChoiceValue'];
	}
	return $out;
}

/** A submitted logic rule's field reference: a stored id ("5", "5.3") or a new field's temp key. */
function minn_admin_gfb_logic_ref( $ref ) {
	$ref = (string) $ref;
	if ( preg_match( '/^\d+(\.\d+)?$/', $ref ) || preg_match( '/^new:[A-Za-z0-9_-]{1,40}$/', $ref ) ) {
		return $ref;
	}
	return '';
}

/**
 * Overlay one submitted row onto a field array: only the properties the
 * field offers ($allowed), each coerced to its type. Everything else in
 * $arr is kept as stored.
 *
 * @param array    $arr     The field array (stored or fresh defaults).
 * @param array    $row     The submitted row.
 * @param string[] $allowed Keys the field offers.
 * @param int      $form_id The form.
 * @return array|WP_Error
 */
function minn_admin_gfb_overlay( $arr, $row, $allowed, $form_id ) {
	$id  = (string) $arr['id'];
	$has = function ( $key ) use ( $row, $allowed ) {
		return in_array( $key, $allowed, true ) && array_key_exists( $key, $row );
	};
	$enum_keys = function ( $list ) {
		return array_map( function ( $p ) {
			return (string) $p[0];
		}, $list );
	};
	$enums = minn_admin_gfb_enums( $form_id );

	// Text. Gravity Forms' own save runs sanitize_settings() over every
	// field (maybe_wp_kses for labels, descriptions and HTML content), so
	// these are stored as theirs would be.
	foreach ( array( 'label', 'description', 'placeholder', 'adminLabel', 'cssClass', 'errorMessage', 'checkboxLabel', 'content', 'allowedExtensions' ) as $key ) {
		if ( $has( $key ) ) {
			$arr[ $key ] = is_scalar( $row[ $key ] ) ? (string) $row[ $key ] : '';
		}
	}
	if ( $has( 'defaultValue' ) ) {
		$arr['defaultValue'] = is_scalar( $row['defaultValue'] ) ? (string) $row['defaultValue'] : '';
	}
	foreach ( array( 'isRequired', 'noDuplicates', 'multipleFiles' ) as $key ) {
		if ( $has( $key ) ) {
			$arr[ $key ] = (bool) $row[ $key ];
		}
	}
	foreach ( array( 'maxLength', 'maxFiles', 'maxFileSize' ) as $key ) {
		if ( $has( $key ) ) {
			$v           = trim( (string) $row[ $key ] );
			$arr[ $key ] = '' === $v ? '' : absint( $v );
		}
	}
	foreach ( array( 'rangeMin', 'rangeMax' ) as $key ) {
		if ( $has( $key ) ) {
			$v           = trim( (string) $row[ $key ] );
			$arr[ $key ] = is_numeric( $v ) ? $v : '';
		}
	}
	foreach ( array( 'visibility', 'numberFormat', 'phoneFormat', 'dateFormat', 'timeFormat', 'addressType' ) as $key ) {
		if ( $has( $key ) && in_array( (string) $row[ $key ], $enum_keys( $enums[ $key ] ), true ) ) {
			$arr[ $key ] = (string) $row[ $key ];
		}
	}
	if ( $has( 'dateType' ) && in_array( (string) $row['dateType'], $enum_keys( $enums['dateType'] ), true )
		&& (string) $row['dateType'] !== (string) rgar( $arr, 'dateType' ) ) {
		$arr['dateType'] = (string) $row['dateType'];
		$arr['inputs']   = minn_admin_gfb_date_inputs( $id, $arr['dateType'] );
	}
	if ( $has( 'emailConfirmEnabled' ) && (bool) $row['emailConfirmEnabled'] !== (bool) rgar( $arr, 'emailConfirmEnabled' ) ) {
		$arr['emailConfirmEnabled'] = (bool) $row['emailConfirmEnabled'];
		$arr['inputs']              = $arr['emailConfirmEnabled'] ? minn_admin_gfb_email_inputs( $id, (string) rgar( $arr, 'placeholder' ) ) : null;
	}
	foreach ( array( 'nextButtonText' => 'nextButton', 'previousButtonText' => 'previousButton' ) as $key => $prop ) {
		if ( $has( $key ) ) {
			$btn          = is_array( rgar( $arr, $prop ) ) ? $arr[ $prop ] : array( 'type' => 'text', 'imageUrl' => '' );
			$btn['text']  = sanitize_text_field( (string) $row[ $key ] );
			$arr[ $prop ] = $btn;
		}
	}
	if ( $has( 'span' ) ) {
		$span = max( 1, min( 12, (int) $row['span'] ) );
		if ( (int) rgar( $arr, 'layoutGridColumnSpan' ) !== $span ) {
			$arr['layoutGridColumnSpan'] = $span;
			// A spacer that no longer fits beside the field would push it onto
			// a row of its own; their editor drops it the same way.
			if ( $span + (int) rgar( $arr, 'layoutSpacerGridColumnSpan' ) > 12 ) {
				unset( $arr['layoutSpacerGridColumnSpan'] );
			}
		}
	}

	// Sub-inputs (Name, Address): show/hide and custom sub-labels only,
	// matched by input id; ids and the rest of each input stay as stored.
	if ( $has( 'subInputs' ) && is_array( $row['subInputs'] ) && is_array( rgar( $arr, 'inputs' ) ) ) {
		// Keyed by the part after the dot: a new field's inputs reached the
		// page as "0.3" and are saved as "{id}.3".
		$suffix = function ( $input_id ) {
			$input_id = (string) $input_id;
			$dot      = strpos( $input_id, '.' );
			return false === $dot ? '' : substr( $input_id, $dot + 1 );
		};
		$sub = array();
		foreach ( $row['subInputs'] as $s ) {
			if ( is_array( $s ) && isset( $s['id'] ) ) {
				$sub[ $suffix( $s['id'] ) ] = $s;
			}
		}
		$visible = 0;
		foreach ( $arr['inputs'] as &$in ) {
			$sid = $suffix( rgar( $in, 'id' ) );
			if ( isset( $sub[ $sid ] ) ) {
				$in['isHidden']    = ! empty( $sub[ $sid ]['isHidden'] );
				$in['customLabel'] = sanitize_text_field( (string) ( $sub[ $sid ]['customLabel'] ?? '' ) );
			}
			if ( empty( $in['isHidden'] ) ) {
				++$visible;
			}
		}
		unset( $in );
		if ( ! $visible ) {
			return new WP_Error( 'minn_gfb_inputs', sprintf( /* translators: %s: the field's label. */ __( '“%s” needs at least one visible part.', 'minn-admin' ), wp_strip_all_tags( (string) rgar( $arr, 'label' ) ) ), array( 'status' => 400 ) );
		}
	}

	// Choices: text / value / selected. Radio and drop-down keep one
	// default at most; checkboxes rebuild one input per choice, as theirs do.
	if ( $has( 'choices' ) && is_array( $row['choices'] ) ) {
		$values  = ! empty( $row['enableChoiceValue'] );
		$single  = in_array( (string) $arr['type'], array( 'radio', 'select' ), true );
		$picked  = false;
		$choices = array();
		foreach ( $row['choices'] as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$text = trim( (string) ( $c['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			// With values shown, an empty value stays empty: Gravity Forms
			// submits it as such (a "Not sure yet" that should read as no answer).
			$value     = $values ? trim( (string) ( $c['value'] ?? '' ) ) : $text;
			$sel       = ! empty( $c['isSelected'] ) && ! ( $single && $picked );
			$picked    = $picked || $sel;
			$choices[] = array( 'text' => $text, 'value' => $value, 'isSelected' => $sel, 'price' => '' );
		}
		if ( ! $choices ) {
			return new WP_Error( 'minn_gfb_choices', sprintf( /* translators: %s: the field's label. */ __( '“%s” needs at least one choice.', 'minn-admin' ), wp_strip_all_tags( (string) rgar( $arr, 'label' ) ) ), array( 'status' => 400 ) );
		}
		$arr['choices']           = $choices;
		$arr['enableChoiceValue'] = $values;
		if ( 'checkbox' === $arr['type'] ) {
			$arr['inputs'] = minn_admin_gfb_checkbox_inputs( $id, $choices );
		}
	}

	// Conditional logic: show/hide when all/any rules match. Rule
	// references are checked against the final field list in the save.
	if ( $has( 'conditionalLogic' ) ) {
		$logic = $row['conditionalLogic'];
		if ( ! is_array( $logic ) || empty( $logic['rules'] ) || ! is_array( $logic['rules'] ) ) {
			$arr['conditionalLogic'] = '';
		} else {
			$ops   = $enum_keys( $enums['operator'] );
			$rules = array();
			foreach ( $logic['rules'] as $r ) {
				$ref = is_array( $r ) ? minn_admin_gfb_logic_ref( $r['fieldId'] ?? '' ) : '';
				if ( '' === $ref ) {
					continue;
				}
				$rules[] = array(
					'fieldId'  => $ref,
					'operator' => in_array( (string) ( $r['operator'] ?? '' ), $ops, true ) ? (string) $r['operator'] : 'is',
					'value'    => is_scalar( $r['value'] ?? '' ) ? (string) $r['value'] : '',
				);
			}
			$arr['conditionalLogic'] = $rules ? array(
				'actionType' => 'hide' === ( $logic['actionType'] ?? '' ) ? 'hide' : 'show',
				'logicType'  => 'any' === ( $logic['logicType'] ?? '' ) ? 'any' : 'all',
				'rules'      => $rules,
			) : '';
		}
	}
	return $arr;
}

/**
 * Save the builder's form: title, description, submit button text, the
 * active flag and the ordered field list, through their editor's save.
 *
 * @param int   $form_id The form.
 * @param array $body    { title, description, buttonText, active, known[], fields[] }.
 * @return array|WP_Error The fresh payload.
 */
function minn_admin_gfb_save( $form_id, $body ) {
	$meta = GFFormsModel::get_form_meta( $form_id );
	if ( ! is_array( $meta ) || empty( $meta['id'] ) ) {
		return new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	// What their editor sends: the display meta, without the notification
	// and confirmation lists (those live in their own columns).
	unset( $meta['notifications'], $meta['confirmations'] );

	$rows  = isset( $body['fields'] ) && is_array( $body['fields'] ) ? array_values( $body['fields'] ) : null;
	$known = isset( $body['known'] ) && is_array( $body['known'] ) ? array_map( 'intval', $body['known'] ) : null;
	if ( null === $rows || null === $known ) {
		return new WP_Error( 'minn_gfb_body', __( 'The builder sent an incomplete form.', 'minn-admin' ), array( 'status' => 400 ) );
	}

	$stored = array();
	foreach ( (array) $meta['fields'] as $field ) {
		$stored[ (int) $field->id ] = $field;
	}
	// A field added elsewhere (their editor, another tab) since this page
	// loaded would read as removed here. Refuse rather than delete it.
	if ( array_diff( array_keys( $stored ), $known ) ) {
		return new WP_Error( 'minn_gfb_stale', __( 'This form changed since you opened it. Reload to get the latest version, then make your changes again.', 'minn-admin' ), array( 'status' => 409 ) );
	}

	if ( array_key_exists( 'title', $body ) ) {
		$meta['title'] = trim( wp_strip_all_tags( (string) $body['title'] ) );
	}
	if ( array_key_exists( 'description', $body ) ) {
		$meta['description'] = (string) $body['description'];
	}
	if ( array_key_exists( 'buttonText', $body ) ) {
		$button         = is_array( rgar( $meta, 'button' ) ) ? $meta['button'] : array( 'type' => 'text', 'imageUrl' => '' );
		$button['text'] = sanitize_text_field( (string) $body['buttonText'] );
		$meta['button'] = $button;
	}

	$creatable = minn_admin_gfb_creatable();
	$next      = GFFormsModel::get_next_field_id( $meta['fields'] );
	$temp      = array();
	$seen      = array();
	$out       = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$rid = isset( $row['id'] ) ? (int) $row['id'] : 0;
		if ( $rid ) {
			if ( ! isset( $stored[ $rid ] ) ) {
				return new WP_Error( 'minn_gfb_stale', __( 'A field on this form was removed elsewhere. Reload to get the latest version.', 'minn-admin' ), array( 'status' => 409 ) );
			}
			if ( isset( $seen[ $rid ] ) ) {
				return new WP_Error( 'minn_gfb_dup', __( 'The same field appears twice.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$seen[ $rid ] = true;
			$field        = $stored[ $rid ];
			$arr          = minn_admin_gfb_overlay( minn_admin_gfb_field_array( $field ), minn_admin_gfb_changed( $field, $row ), minn_admin_gfb_field_settings( $field ), $form_id );
		} else {
			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( ! in_array( $type, $creatable, true ) ) {
				return new WP_Error( 'minn_gfb_type', __( 'That field type can’t be added here.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$nid = $next++;
			if ( isset( $row['tempId'] ) && preg_match( '/^[A-Za-z0-9_-]{1,40}$/', (string) $row['tempId'] ) ) {
				$temp[ 'new:' . $row['tempId'] ] = $nid;
			}
			$arr = minn_admin_gfb_new_field( $type, $nid, $form_id );
			$arr = minn_admin_gfb_overlay( $arr, $row, minn_admin_gfb_field_settings( GF_Fields::create( $arr ) ), $form_id );
		}
		if ( is_wp_error( $arr ) ) {
			return $arr;
		}
		$out[] = $arr;
	}

	// Resolve new fields' temp keys in logic rules, and drop rules that
	// point at no field this save keeps (their delete would drop them too).
	$final = array();
	foreach ( $out as $arr ) {
		$final[ (string) $arr['id'] ] = true;
	}
	foreach ( $out as &$arr ) {
		if ( empty( $arr['conditionalLogic'] ) || ! is_array( $arr['conditionalLogic'] ) ) {
			continue;
		}
		$rules = array();
		foreach ( (array) $arr['conditionalLogic']['rules'] as $r ) {
			$ref = (string) $r['fieldId'];
			if ( isset( $temp[ $ref ] ) ) {
				$r['fieldId'] = (string) $temp[ $ref ];
			}
			$base = strtok( (string) $r['fieldId'], '.' );
			if ( isset( $final[ $base ] ) && 0 !== strpos( (string) $r['fieldId'], 'new:' ) ) {
				$rules[] = $r;
			}
		}
		$arr['conditionalLogic']['rules'] = $rules;
		if ( ! $rules ) {
			$arr['conditionalLogic'] = '';
		}
	}
	unset( $arr );

	$meta['fields']        = $out;
	$meta['deletedFields'] = array_values( array_diff( array_intersect( $known, array_keys( $stored ) ), array_keys( $seen ) ) );

	$handler = minn_admin_gfb_crud_handler();
	if ( ! $handler ) {
		return new WP_Error( 'minn_gfb_unavailable', __( 'This version of Gravity Forms doesn’t offer its editor’s save to other screens. Edit the form in Gravity Forms.', 'minn-admin' ), array( 'status' => 501 ) );
	}
	// Their handler reads the JSON the way it arrives from their editor's
	// POST: slashed (it runs stripslashes first).
	$result = $handler->save( $form_id, wp_slash( wp_json_encode( $meta ) ) );
	$status = (string) rgar( $result, 'status' );
	if ( 'duplicate_title' === $status ) {
		return new WP_Error( 'minn_gfb_title', __( 'Another form already has this title.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( 'missing_title' === $status ) {
		return new WP_Error( 'minn_gfb_title', __( 'Give the form a title first.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( 'success' !== $status ) {
		return new WP_Error( 'minn_gfb_save', __( 'Gravity Forms did not save the form.', 'minn-admin' ), array( 'status' => 500 ) );
	}

	if ( array_key_exists( 'active', $body ) ) {
		GFAPI::update_forms_property( array( $form_id ), 'is_active', $body['active'] ? '1' : '0' );
	}
	GFFormsModel::flush_current_form( GFFormsModel::get_form_cache_key( $form_id ) );
	return minn_admin_gfb_payload( $form_id );
}

/**
 * Create an empty form through their editor's save (form id 0), which adds
 * the default notification and confirmation their own New Form does.
 *
 * @param array $body { title, description }.
 * @return array|WP_Error { id, message }
 */
function minn_admin_gfb_create( $body ) {
	$title = trim( wp_strip_all_tags( (string) ( $body['title'] ?? '' ) ) );
	if ( '' === $title ) {
		return new WP_Error( 'minn_gfb_title', __( 'Give the form a title first.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$meta    = array(
		'title'                => $title,
		'description'          => (string) ( $body['description'] ?? '' ),
		'labelPlacement'       => 'top_label',
		'descriptionPlacement' => 'below',
		'button'               => array( 'type' => 'text', 'text' => __( 'Submit', 'minn-admin' ), 'imageUrl' => '' ),
		'fields'               => array(),
	);
	$handler = minn_admin_gfb_crud_handler();
	if ( ! $handler ) {
		return new WP_Error( 'minn_gfb_unavailable', __( 'This version of Gravity Forms doesn’t offer its editor’s save to other screens. Create the form in Gravity Forms.', 'minn-admin' ), array( 'status' => 501 ) );
	}
	$result = $handler->save( 0, wp_slash( wp_json_encode( $meta ) ) );
	$status  = (string) rgar( $result, 'status' );
	if ( 'duplicate_title' === $status ) {
		return new WP_Error( 'minn_gfb_title', __( 'Another form already has this title.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$id = (int) rgars( $result, 'meta/id' );
	if ( 'success' !== $status || ! $id ) {
		return new WP_Error( 'minn_gfb_save', __( 'Gravity Forms did not create the form.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return array( 'id' => $id, 'message' => __( 'Form created', 'minn-admin' ) );
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_gfb_available() ) {
		return;
	}
	$perm = function () {
		return GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );
	};
	register_rest_route( 'minn-admin/v1', '/gf/forms/(?P<id>\d+)/builder', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id = (int) Minn_Admin::path_param( $request );
				if ( ! GFAPI::form_id_exists( $id ) ) {
					return new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_gfb_payload( $id ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id = (int) Minn_Admin::path_param( $request );
				if ( ! GFAPI::form_id_exists( $id ) ) {
					return new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_gfb_save( $id, (array) $request->get_json_params() ) );
			},
		),
	) );
	// Creating shares the list route's path (GET there lists the forms).
	register_rest_route( 'minn-admin/v1', '/gf/forms', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return GFCommon::current_user_can_any( array( 'gravityforms_create_form', 'gform_full_access' ) );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			return rest_ensure_response( minn_admin_gfb_create( (array) $request->get_json_params() ) );
		},
	) );
} );
