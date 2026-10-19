<?php
/**
 * Fluent Forms: a form's email notifications and confirmation page.
 *
 * /fluent-forms/form/{id} edits the form's email notifications and what a
 * visitor sees after submitting (the default confirmation: a message, a page
 * or an address). The saves are Fluent's own: each notification through
 * SettingsService::store() (their notification validator, their sanitizer for
 * people without unfiltered HTML) and the confirmation through
 * SettingsService::saveGeneral() with the form's whole formSettings and
 * advancedValidationSettings (their save writes both whole), so only the
 * confirmation changes. A validation failure comes back on its field. The
 * page is a settings-tier screen: the tier owns its routes and the order of
 * a save, so every refusal comes before the first write.
 *
 * Only what Fluent offers in the edition installed: their free editor locks
 * adding notifications, recipient routing, notification conditions and the
 * extra conditional confirmations to Pro, so without Pro those are neither
 * offered nor touched (a stored routing list or condition set is kept as it
 * is). With Pro the page still has no routing editor, so a routing
 * notification keeps its list there too. The preview renders the
 * notification's subject and message with the form's latest entry through
 * their ShortCodeParser, the way their sender does, with only the codes
 * minn_admin_fluent_preview_tags() names resolved.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_fluent_emails_ready() {
	return class_exists( '\FluentForm\App\Services\Settings\SettingsService' ) && class_exists( '\FluentForm\App\Models\FormMeta' );
}

function minn_admin_fluent_has_pro() {
	return defined( 'FLUENTFORMPRO' ) || ( class_exists( '\FluentForm\App\Helpers\Helper' ) && method_exists( '\FluentForm\App\Helpers\Helper', 'hasPro' ) && \FluentForm\App\Helpers\Helper::hasPro() );
}

/**
 * Whether the preview may fill in a real entry. Fluent grants Manage Forms
 * and View Entries separately, so a manager who builds forms without seeing
 * submissions gets the tags as typed.
 */
function minn_admin_ffe_can_read_entries( $form_id ) {
	return class_exists( '\FluentForm\App\Modules\Acl\Acl' ) && \FluentForm\App\Modules\Acl\Acl::hasPermission( 'fluentform_entries_viewer', (int) $form_id );
}

/** The form row, or null. */
function minn_admin_fluent_form( $form_id ) {
	$form = wpFluent()->table( 'fluentform_forms' )->find( (int) $form_id );
	return $form ? $form : null;
}

/** A form meta value, decoded. */
function minn_admin_fluent_meta( $form_id, $key, $default = array() ) {
	$row = wpFluent()->table( 'fluentform_form_meta' )->where( 'form_id', (int) $form_id )->where( 'meta_key', $key )->first();
	if ( ! $row ) {
		return $default;
	}
	$v = json_decode( (string) $row->value, true );
	return is_array( $v ) ? $v : $default;
}

/** The form's notifications: [meta id => value]. */
function minn_admin_fluent_notifications( $form_id ) {
	$out = array();
	foreach ( wpFluent()->table( 'fluentform_form_meta' )->where( 'form_id', (int) $form_id )->where( 'meta_key', 'notifications' )->orderBy( 'id', 'ASC' )->get() as $row ) {
		$v = json_decode( (string) $row->value, true );
		if ( is_array( $v ) ) {
			$out[ (int) $row->id ] = $v;
		}
	}
	return $out;
}

/** A notification as the page edits it. */
function minn_admin_fluent_notification_out( $id, $n ) {
	$type = (string) ( $n['sendTo']['type'] ?? 'email' );
	return array(
		'metaId'      => (int) $id,
		'name'        => (string) ( $n['name'] ?? '' ),
		'enabled'     => ! empty( $n['enabled'] ),
		'toType'      => $type,
		'toEmail'     => (string) ( $n['sendTo']['email'] ?? '' ),
		'toField'     => (string) ( $n['sendTo']['field'] ?? '' ),
		'fromName'    => (string) ( $n['fromName'] ?? '' ),
		'fromEmail'   => (string) ( $n['fromEmail'] ?? '' ),
		'replyTo'     => (string) ( $n['replyTo'] ?? '' ),
		'bcc'         => (string) ( $n['bcc'] ?? '' ),
		'subject'     => (string) ( $n['subject'] ?? '' ),
		'message'     => (string) ( $n['message'] ?? '' ),
		// Pro-only settings already on the notification, kept as they are.
		'hasRouting'  => 'routing' === $type,
		'hasLogic'    => ! empty( $n['conditionals']['status'] ),
	);
}

/** The form's email inputs as [name, label] (Send to: a field). */
function minn_admin_fluent_email_fields( $form ) {
	$out = array();
	if ( ! class_exists( '\FluentForm\App\Modules\Form\FormFieldsParser' ) ) {
		return $out;
	}
	foreach ( (array) \FluentForm\App\Modules\Form\FormFieldsParser::getInputs( $form, array( 'element', 'label', 'admin_label' ) ) as $name => $input ) {
		if ( 'input_email' === ( $input['element'] ?? '' ) ) {
			$label = trim( wp_strip_all_tags( (string) ( $input['admin_label'] ?? '' ) ) );
			$label = '' !== $label ? $label : trim( wp_strip_all_tags( (string) ( $input['label'] ?? '' ) ) );
			$out[] = array( (string) $name, '' !== $label ? $label : (string) $name );
		}
	}
	return $out;
}

/** Their smart tags, grouped for Minn's picker. */
function minn_admin_fluent_tags( $form ) {
	$out = array();
	if ( ! class_exists( '\FluentForm\App\Services\FormBuilder\EditorShortCode' ) ) {
		return $out;
	}
	foreach ( (array) \FluentForm\App\Services\FormBuilder\EditorShortCode::getShortCodes( $form ) as $group ) {
		$tags = array();
		foreach ( (array) ( $group['shortcodes'] ?? array() ) as $tag => $label ) {
			$tags[] = array( 'tag' => (string) $tag, 'label' => wp_strip_all_tags( (string) $label ) );
		}
		if ( $tags ) {
			$out[] = array( 'label' => wp_strip_all_tags( (string) ( $group['title'] ?? '' ) ), 'tags' => $tags );
		}
	}
	return $out;
}

/**
 * The smart codes a preview resolves from a real submission
 * (Minn_Admin_Preview): Fluent's own that read only the submission, the
 * form, the site or the person previewing. {user.*} reads whoever is signed
 * in, here the person previewing, so only its profile fields. {inputs.*}
 * names the form's own inputs: any other key reads what the submission kept
 * of the request (the page it was sent from, the referer). The submission's
 * address, browser, page and account, its meta, {embed_post.*},
 * {cookie.*}, {ip}, {browser.*}, {http_referer}, {chat_gpt_response.*}
 * (which asks OpenAI and stores the answer) and every add-on's code stay
 * placeholders.
 *
 * @param object $form The form row.
 * @return string[]
 */
function minn_admin_fluent_preview_tags( $form ) {
	$tags = array(
		'all_data', 'all_data_without_hidden_fields', 'form_title', 'labels.*', 'admin_email', 'wp.admin_email', 'wp.site_url', 'wp.site_title', 'date.*', 'random_string.*',
		'submission.id', 'submission.serial_number', 'submission.form_id', 'submission.status', 'submission.is_favourite', 'submission.created_at', 'submission.updated_at', 'submission.admin_view_url',
		'submission.currency', 'submission.payment_status', 'submission.payment_method', 'submission.payment_type', 'submission.payment_total', 'submission.total_paid',
		'payment.receipt', 'payment.summary', 'payment.summary_list', 'payment.order_items', 'payment.subscription_items', 'payment.payment_status', 'payment.payment_total', 'payment.payment_method',
		'user.ID', 'user.id', 'user.display_name', 'user.first_name', 'user.last_name', 'user.nickname', 'user.user_email', 'user.user_login',
	);
	if ( class_exists( '\FluentForm\App\Modules\Form\FormFieldsParser' ) ) {
		// Each input by name, with its value, its label and the parts a
		// compound input keeps (a name field's first_name), and nothing
		// looser: Fluent strips ".value" and ".label" anywhere in a key, so a
		// wildcard after an input's name reaches any submitted key that
		// starts with it ({inputs._wp.value_http_referer}).
		foreach ( (array) \FluentForm\App\Modules\Form\FormFieldsParser::getInputs( $form, array( 'raw' ) ) as $name => $input ) {
			$tags[] = 'inputs.' . $name;
			$tags[] = 'inputs.' . $name . '.value';
			$tags[] = 'inputs.' . $name . '.label';
			$parts  = is_array( $input ) && isset( $input['raw']['fields'] ) && is_array( $input['raw']['fields'] ) ? array_keys( $input['raw']['fields'] ) : array();
			foreach ( $parts as $part ) {
				if ( preg_match( '/^[A-Za-z0-9_-]+$/', (string) $part ) && false === strpos( (string) $part, 'value' ) && false === strpos( (string) $part, 'label' ) ) {
					$tags[] = 'inputs.' . $name . '.' . $part;
				}
			}
		}
	}
	return $tags;
}

/** The confirmation as the page edits it. */
function minn_admin_fluent_confirmation_out( $c ) {
	$c    = (array) $c;
	$page = ! empty( $c['customPage'] ) ? get_post( (int) $c['customPage'] ) : null;
	return array(
		'redirectTo'           => (string) ( $c['redirectTo'] ?? 'samePage' ),
		'messageToShow'        => (string) ( $c['messageToShow'] ?? '' ),
		'samePageFormBehavior' => 'reset_form' === ( $c['samePageFormBehavior'] ?? '' ) ? 'reset_form' : 'hide_form',
		'customPage'           => $page ? (string) $page->ID : '',
		'customUrl'            => (string) ( $c['customUrl'] ?? '' ),
	);
}
/** The page's read. */
function minn_admin_fluent_emails_payload( $form ) {
	$settings = minn_admin_fluent_meta( $form->id, 'formSettings' );
	$c        = (array) ( $settings['confirmation'] ?? array() );
	$page     = ! empty( $c['customPage'] ) ? get_post( (int) $c['customPage'] ) : null;
	$pages    = array();
	foreach ( get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title', 'number' => 500 ) ) as $p ) {
		$pages[] = array( (string) $p->ID, get_the_title( $p ) ? get_the_title( $p ) : '#' . $p->ID );
	}
	if ( $page && ! in_array( (string) $page->ID, wp_list_pluck( $pages, 0 ), true ) && current_user_can( 'read_post', $page->ID ) ) {
		$pages[] = array( (string) $page->ID, get_the_title( $page ) );
	}
	$notifications = array();
	foreach ( minn_admin_fluent_notifications( $form->id ) as $id => $n ) {
		$notifications[] = minn_admin_fluent_notification_out( $id, $n );
	}
	$latest = minn_admin_ffe_can_read_entries( $form->id ) ? wpFluent()->table( 'fluentform_submissions' )->where( 'form_id', (int) $form->id )->orderBy( 'id', 'DESC' )->first() : null;
	return array(
		'id'            => (int) $form->id,
		'title'         => (string) $form->title,
		'adminUrl'      => admin_url( 'admin.php?page=fluent_forms&form_id=' . (int) $form->id . '&route=settings&sub_route=form_settings#/email-settings' ),
		'hasPro'        => minn_admin_fluent_has_pro(),
		'notifications' => $notifications,
		'emailFields'   => minn_admin_fluent_email_fields( $form ),
		'confirmation'  => minn_admin_fluent_confirmation_out( $c ),
		'pages'         => $pages,
		'mergeTags'     => minn_admin_fluent_tags( $form ),
		'latest'        => $latest ? (int) $latest->id : 0,
		'canHtml'       => current_user_can( 'unfiltered_html' ),
	);
}

/** Their validation error as Minn's: the first message, on the page's field name. */
function minn_admin_fluent_validation_error( $e, $prefix ) {
	$errors = method_exists( $e, 'errors' ) ? (array) $e->errors() : array();
	$map    = array( 'sendTo.email' => 'toEmail', 'sendTo.field' => 'toField', 'sendTo.type' => 'toEmail', 'sendTo.routing' => 'toEmail' );
	foreach ( $errors as $key => $messages ) {
		$message = is_array( $messages ) ? (string) reset( $messages ) : (string) $messages;
		$field   = $map[ $key ] ?? $key;
		return new WP_Error( 'minn_ffe_invalid', wp_strip_all_tags( $message ), array( 'status' => 400, 'field' => $prefix . $field ) );
	}
	return new WP_Error( 'minn_ffe_invalid', wp_strip_all_tags( $e->getMessage() ), array( 'status' => 400 ) );
}

/**
 * The page's fields for the settings tier: each notification's and the
 * confirmation's, keyed as the page names them ("n12.subject",
 * "confirmation.customUrl"), each knowing where it reads from.
 *
 * @param object $form The form row.
 * @return array { groups }
 */
function minn_admin_fluent_emails_layout( $form ) {
	$labels = array(
		'name'      => array( __( 'Name', 'minn-admin' ), 'text' ),
		'enabled'   => array( __( 'Enabled', 'minn-admin' ), 'toggle' ),
		'toType'    => array( __( 'Send to', 'minn-admin' ), 'select' ),
		'toEmail'   => array( __( 'Send to', 'minn-admin' ), 'text' ),
		'toField'   => array( __( 'Send to', 'minn-admin' ), 'select' ),
		'fromName'  => array( __( 'From name', 'minn-admin' ), 'text' ),
		'fromEmail' => array( __( 'From email', 'minn-admin' ), 'text' ),
		'replyTo'   => array( __( 'Reply to', 'minn-admin' ), 'text' ),
		'bcc'       => array( __( 'Bcc', 'minn-admin' ), 'text' ),
		'subject'   => array( __( 'Subject', 'minn-admin' ), 'text' ),
		'message'   => array( __( 'Message', 'minn-admin' ), 'wysiwyg' ),
	);
	$groups = array();
	foreach ( minn_admin_fluent_notifications( $form->id ) as $id => $n ) {
		$title  = (string) ( $n['name'] ?? '' );
		$fields = array();
		foreach ( $labels as $k => $l ) {
			$fields[] = array(
				'key'   => 'n' . $id . '.' . $k,
				'label' => ( '' !== $title ? $title . ' · ' : '' ) . $l[0],
				'type'  => $l[1],
				'_n'    => (int) $id,
				'_k'    => $k,
			);
		}
		$groups[] = array( 'title' => $title, 'fields' => $fields );
	}
	$fields = array();
	foreach ( array(
		'redirectTo'           => array( __( 'After submit', 'minn-admin' ), 'select' ),
		'messageToShow'        => array( __( 'Message', 'minn-admin' ), 'wysiwyg' ),
		'samePageFormBehavior' => array( __( 'After submit', 'minn-admin' ), 'select' ),
		'customPage'           => array( __( 'Page', 'minn-admin' ), 'select' ),
		'customUrl'            => array( __( 'Address', 'minn-admin' ), 'url' ),
	) as $k => $l ) {
		$fields[] = array(
			'key'   => 'confirmation.' . $k,
			'label' => __( 'Confirmation', 'minn-admin' ) . ' · ' . $l[0],
			'type'  => $l[1],
			'_k'    => $k,
		);
	}
	$groups[] = array( 'title' => __( 'Confirmation', 'minn-admin' ), 'fields' => $fields );
	return array( 'groups' => $groups );
}

/**
 * The body's values by field key. The page sends each changed notification
 * as its changed keys beside its metaId, and the confirmation's changed
 * keys; one not on this form refuses the save.
 *
 * @param array $body Body (or the base the page loaded, in the same shape).
 * @param array $ctx  Context: the form.
 * @return array|WP_Error
 */
function minn_admin_fluent_emails_sent( $body, $ctx ) {
	$stored = minn_admin_fluent_notifications( $ctx['form']->id );
	$out    = array();
	foreach ( (array) ( $body['notifications'] ?? array() ) as $in ) {
		$id = is_array( $in ) ? (int) ( $in['metaId'] ?? 0 ) : 0;
		if ( ! isset( $stored[ $id ] ) ) {
			return new WP_Error( 'minn_ffe_unknown', __( 'That notification is not on this form.', 'minn-admin' ), array( 'status' => 400, 'field' => 'n' . $id ) );
		}
		foreach ( $in as $k => $v ) {
			if ( 'metaId' !== $k ) {
				$out[ 'n' . $id . '.' . $k ] = $v;
			}
		}
	}
	if ( isset( $body['confirmation'] ) && is_array( $body['confirmation'] ) ) {
		foreach ( $body['confirmation'] as $k => $v ) {
			$out[ 'confirmation.' . $k ] = $v;
		}
	}
	return $out;
}

/**
 * Each changed notification and the confirmation, built whole and put
 * through Fluent's own validator (the call their store() and saveGeneral()
 * make), before the first write.
 *
 * @param array $changes Changed values, by field key.
 * @param array $fields  Their fields, by the same key.
 * @param array $ctx     Context: the form.
 * @return array|WP_Error { writes: notifications by meta id, general: formSettings or null }
 */
function minn_admin_fluent_emails_validate( $changes, $fields, $ctx ) {
	$form   = $ctx['form'];
	$stored = minn_admin_fluent_notifications( $form->id );
	$per    = array();
	$conf   = array();
	foreach ( $changes as $key => $v ) {
		if ( isset( $fields[ $key ]['_n'] ) ) {
			$per[ $fields[ $key ]['_n'] ][ $fields[ $key ]['_k'] ] = $v;
		} else {
			$conf[ $fields[ $key ]['_k'] ] = $v;
		}
	}
	$text = function ( $in, $k ) {
		return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';
	};

	// Notifications: each changed one, as their store() will take it.
	$writes = array();
	foreach ( $per as $id => $in ) {
		$n = $stored[ $id ];
		// A routing list is Pro's and is edited in Fluent Forms, with or
		// without Pro here: the page offers no routing editor, so a stored
		// list is kept as it is on any save.
		$type = array_key_exists( 'toType', $in ) ? $text( $in, 'toType' ) : (string) ( $n['sendTo']['type'] ?? 'email' );
		if ( 'routing' === ( $n['sendTo']['type'] ?? '' ) ) {
			$type = 'routing';
		} elseif ( ! in_array( $type, array( 'email', 'field' ), true ) ) {
			$type = 'email';
		}
		if ( array_key_exists( 'name', $in ) ) {
			$n['name'] = sanitize_text_field( $text( $in, 'name' ) );
		}
		// The switch only when it changed, so one turned off elsewhere stays off.
		if ( array_key_exists( 'enabled', $in ) ) {
			$n['enabled'] = Minn_Admin_Kernel_Settings::on( $in['enabled'] );
		}
		$n['sendTo']['type'] = $type;
		if ( 'email' === $type && array_key_exists( 'toEmail', $in ) ) {
			$n['sendTo']['email'] = trim( $text( $in, 'toEmail' ) );
		} elseif ( 'field' === $type && array_key_exists( 'toField', $in ) ) {
			$n['sendTo']['field'] = $text( $in, 'toField' );
		}
		foreach ( array( 'fromName', 'fromEmail', 'replyTo', 'bcc', 'subject', 'message' ) as $k ) {
			if ( array_key_exists( $k, $in ) ) {
				$n[ $k ] = $text( $in, $k );
			}
		}
		try {
			\FluentForm\App\Services\Settings\Validator::validate( 'notifications', $n );
		} catch ( \Throwable $e ) {
			return minn_admin_fluent_validation_error( $e, 'n' . $id . '.' );
		}
		$writes[ $id ] = $n;
	}

	// The confirmation: their saveGeneral() with both settings whole. Only
	// the keys that changed are written, so a key left as stored keeps its
	// bytes: normalizing all five on every save trimmed a stored address,
	// turned an empty one into null and a page id 42 into '42', and reset a
	// behaviour the page reads as "hide the form".
	$general = null;
	if ( $conf ) {
		$settings = minn_admin_fluent_meta( $form->id, 'formSettings' );
		$c        = (array) ( $settings['confirmation'] ?? array() );
		if ( array_key_exists( 'redirectTo', $conf ) ) {
			$c['redirectTo'] = in_array( $text( $conf, 'redirectTo' ), array( 'samePage', 'customPage', 'customUrl' ), true ) ? $text( $conf, 'redirectTo' ) : 'samePage';
		}
		if ( array_key_exists( 'messageToShow', $conf ) ) {
			$c['messageToShow'] = $text( $conf, 'messageToShow' );
		}
		if ( array_key_exists( 'samePageFormBehavior', $conf ) ) {
			$c['samePageFormBehavior'] = 'reset_form' === $text( $conf, 'samePageFormBehavior' ) ? 'reset_form' : 'hide_form';
		}
		if ( array_key_exists( 'customPage', $conf ) ) {
			$c['customPage'] = absint( $text( $conf, 'customPage' ) ) ? (string) absint( $text( $conf, 'customPage' ) ) : null;
		}
		if ( array_key_exists( 'customUrl', $conf ) ) {
			$c['customUrl'] = '' !== trim( $text( $conf, 'customUrl' ) ) ? trim( $text( $conf, 'customUrl' ) ) : null;
		}
		// Their validator requires a type; one never stored reads as the
		// same page.
		if ( ! isset( $c['redirectTo'] ) ) {
			$c['redirectTo'] = 'samePage';
		}
		if ( ( array_key_exists( 'redirectTo', $conf ) || array_key_exists( 'customPage', $conf ) ) && 'customPage' === $c['redirectTo'] && ! empty( $c['customPage'] ) && ( ! get_post( (int) $c['customPage'] ) || ! current_user_can( 'read_post', (int) $c['customPage'] ) ) ) {
			return new WP_Error( 'minn_ffe_invalid', __( 'Choose a published page.', 'minn-admin' ), array( 'status' => 400, 'field' => 'confirmation.customPage' ) );
		}
		try {
			\FluentForm\App\Services\Settings\Validator::validate( 'confirmations', $c );
		} catch ( \Throwable $e ) {
			return minn_admin_fluent_validation_error( $e, 'confirmation.' );
		}
		$settings['confirmation'] = $c;
		$general                  = $settings;
	}
	return array(
		'writes'  => $writes,
		'general' => $general,
	);
}

/**
 * Write what the validator built. The confirmation goes first: saveGeneral()
 * validates it after its own cleaning (without unfiltered_html, a
 * javascript: address comes out empty), which the check before cannot see,
 * and it validates before it writes. Each notification was validated exactly
 * as store() validates it.
 *
 * @param array $plan From minn_admin_fluent_emails_validate().
 * @param array $fields Changed fields.
 * @param array $ctx  Context: the form.
 * @return true|WP_Error
 */
function minn_admin_fluent_emails_write( $plan, $fields, $ctx ) {
	$form    = $ctx['form'];
	$service = new \FluentForm\App\Services\Settings\SettingsService();
	if ( null !== $plan['general'] ) {
		try {
			$service->saveGeneral( array(
				'form_id'                    => (int) $form->id,
				'formSettings'               => wp_json_encode( $plan['general'] ),
				'advancedValidationSettings' => wp_json_encode( minn_admin_fluent_meta( $form->id, 'advancedValidationSettings' ) ),
			) );
		} catch ( \Throwable $e ) {
			return minn_admin_fluent_validation_error( $e, 'confirmation.' );
		}
	}
	foreach ( $plan['writes'] as $id => $n ) {
		try {
			$service->store( array(
				'form_id'  => (int) $form->id,
				'meta_key' => 'notifications',
				'meta_id'  => $id,
				'value'    => wp_json_encode( $n ),
			) );
		} catch ( \Throwable $e ) {
			return minn_admin_fluent_validation_error( $e, 'n' . $id . '.' );
		}
	}
	return true;
}

// The page's save: the settings tier compares each sent key with what the
// page was served, refuses a hidden key's placeholder left in an edited
// value, and asks Fluent's own validator for every changed notification and
// the confirmation before the first write.
minn_admin_settings( 'fluent-forms-emails', array(
	'route'    => 'fluent-forms/forms/(?P<id>\d+)/emails',
	'active'   => function () {
		return function_exists( 'minn_admin_fluent_forms_ready' ) && minn_admin_fluent_forms_ready() && minn_admin_fluent_emails_ready();
	},
	'gate'     => function ( WP_REST_Request $request ) {
		return minn_admin_fluent_forms_can_form( (int) Minn_Admin::path_param( $request ), 'fluentform_forms_manager' );
	},
	'target'   => function ( WP_REST_Request $request ) {
		$form = minn_admin_fluent_form( (int) Minn_Admin::path_param( $request ) );
		return $form ? array( 'form' => $form ) : new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
	},
	'layout'   => function ( $ctx ) {
		return minn_admin_fluent_emails_layout( $ctx['form'] );
	},
	'read'     => function ( $field, $ctx ) {
		$memo = $ctx['memo'];
		if ( isset( $field['_n'] ) ) {
			if ( ! isset( $memo['n'] ) ) {
				$memo['n'] = minn_admin_fluent_notifications( $ctx['form']->id );
			}
			return minn_admin_fluent_notification_out( $field['_n'], $memo['n'][ $field['_n'] ] ?? array() )[ $field['_k'] ] ?? '';
		}
		if ( ! isset( $memo['c'] ) ) {
			$memo['c'] = minn_admin_fluent_confirmation_out( minn_admin_fluent_meta( $ctx['form']->id, 'formSettings' )['confirmation'] ?? array() );
		}
		return $memo['c'][ $field['_k'] ] ?? '';
	},
	'view'     => function ( $ctx ) {
		return minn_admin_fluent_emails_payload( minn_admin_fluent_form( $ctx['form']->id ) );
	},
	'sent'     => 'minn_admin_fluent_emails_sent',
	'validate' => 'minn_admin_fluent_emails_validate',
	'write'    => 'minn_admin_fluent_emails_write',
) );

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'minn_admin_fluent_forms_ready' ) || ! minn_admin_fluent_forms_ready() || ! minn_admin_fluent_emails_ready() ) {
		return;
	}
	$perm = function ( WP_REST_Request $request ) {
		return minn_admin_fluent_forms_can_form( (int) Minn_Admin::path_param( $request ), 'fluentform_forms_manager' );
	};
	$load = function ( WP_REST_Request $request ) {
		$form = minn_admin_fluent_form( (int) Minn_Admin::path_param( $request ) );
		return $form ? $form : new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
	};

	minn_admin_endpoint( '/fluent-forms/forms/(?P<id>\d+)/emails/preview', array(
		'methods'             => 'POST',
		'gate'                => $perm,
		'run'                 => function ( WP_REST_Request $request ) use ( $load ) {
			$form = $load( $request );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$body  = (array) $request->get_json_params();
			$parts = array(
				'subject' => is_scalar( $body['subject'] ?? '' ) ? (string) ( $body['subject'] ?? '' ) : '',
				'message' => is_scalar( $body['message'] ?? '' ) ? (string) ( $body['message'] ?? '' ) : '',
			);
			if ( ! current_user_can( 'unfiltered_html' ) && function_exists( 'fluentform_sanitize_html' ) ) {
				$parts['message'] = fluentform_sanitize_html( $parts['message'] );
			}
			// {embed_post.*} reads the page the entry names (its author's meta,
			// any of its meta, a draft's body) and an add-on's code does what
			// the add-on does. Only the codes minn_admin_fluent_preview_tags()
			// names resolve; any other is a placeholder before Fluent reads the
			// text. A real send is unchanged.
			$allow = minn_admin_fluent_preview_tags( $form );
			foreach ( $parts as $part => $text ) {
				$parts[ $part ] = Minn_Admin_Preview::restrict( $text, 'fluent', $allow );
			}
			$entry = minn_admin_ffe_can_read_entries( $form->id ) ? wpFluent()->table( 'fluentform_submissions' )->where( 'form_id', (int) $form->id )->orderBy( 'id', 'DESC' )->first() : null;
			if ( ! $entry ) {
				return rest_ensure_response( array( 'entry' => 0, 'subject' => $parts['subject'], 'html' => $parts['message'] ) );
			}
			// {cookie.*} reads the current request's cookies: in a real send
			// the submitter's, here the viewer's own, HttpOnly login cookie
			// included. The preview resolves them against none.
			$cookies = $_COOKIE;
			$_COOKIE = array();
			try {
				\FluentForm\App\Services\FormBuilder\ShortCodeParser::resetData();
				$response = json_decode( (string) $entry->response, true );
				if ( function_exists( 'minn_admin_fluent_forms_mask_passwords' ) ) {
					$response = minn_admin_fluent_forms_mask_passwords( $response, (int) $form->id );
				}
				// The row goes in with its answers masked: given only an id,
				// Fluent reloads the raw row for {submission.response} and
				// {all_data}.
				$entry->response = wp_json_encode( $response );
				$out = \FluentForm\App\Services\FormBuilder\ShortCodeParser::parse( $parts, $entry, $response, $form, false, 'notifications' );
				\FluentForm\App\Services\FormBuilder\ShortCodeParser::resetData();
			} finally {
				$_COOKIE = $cookies;
			}
			return rest_ensure_response( array(
				'entry'   => (int) $entry->id,
				'subject' => wp_strip_all_tags( (string) ( $out['subject'] ?? '' ) ),
				'html'    => (string) ( $out['message'] ?? '' ),
			) );
		},
	) );
} );
