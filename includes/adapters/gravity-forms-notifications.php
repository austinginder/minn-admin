<?php
/**
 * Gravity Forms: the notification page.
 *
 * /gravity-forms/notification/{form}:{id} edits one notification whole:
 * who it goes to (an address, an email field, or routing rules), who it is
 * from, the subject and message (with the form's merge tags), when it sends
 * (the event and conditional logic), auto-formatting and attachments. A
 * preview renders the message with the form's latest entry, and a test
 * sends it to the person editing. {form}:new starts a notification that
 * exists only once it is saved, so a half-made one never starts sending.
 *
 * The save is Gravity Forms' own editor save, step for step
 * (GFNotification's settings save callback): the same fields, the same
 * validation (is_valid_notification_email and the gform_is_valid_
 * notification_to filter), routing and logic through their sanitizers,
 * gform_pre_notification_save, and save_form_notifications. Duplicate and
 * delete are GFNotification's own, and the Active switch rides
 * update_notification_active so its hooks fire.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** GFNotification lives in a file Gravity Forms loads only in wp-admin. */
function minn_admin_gfn_load() {
	if ( ! class_exists( 'GFNotification' ) && class_exists( 'GFCommon' ) ) {
		$file = GFCommon::get_base_path() . '/notification.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
	return class_exists( 'GFNotification' ) && class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'save_form_notifications' );
}

/** The form as their notification page reads it (their filter included). */
function minn_admin_gfn_form( $form_id, $nid ) {
	$form = GFFormsModel::get_form_meta( $form_id );
	if ( ! is_array( $form ) || empty( $form['id'] ) ) {
		return null;
	}
	$form = gf_apply_filters( array( 'gform_form_notification_page', $form_id ), $form, $nid );
	if ( empty( $form['notifications'] ) || ! is_array( $form['notifications'] ) ) {
		$form['notifications'] = array();
	}
	return $form;
}

/** What a new notification starts as. */
function minn_admin_gfn_defaults() {
	return array(
		'id'                => '',
		'name'              => __( 'New notification', 'minn-admin' ),
		'isActive'          => true,
		'service'           => 'wordpress',
		'event'             => 'form_submission',
		'toType'            => 'email',
		'to'                => '{admin_email}',
		'from'              => '{admin_email}',
		'fromName'          => '',
		'replyTo'           => '',
		'cc'                => '',
		'bcc'               => '',
		/* translators: %s: the {form_title} merge tag. */
		'subject'           => sprintf( __( 'New submission from %s', 'minn-admin' ), '{form_title}' ),
		'message'           => '{all_fields}',
		'disableAutoformat' => false,
		'enableAttachments' => false,
		'conditionalLogic'  => null,
		'routing'           => null,
	);
}

/** Pairs for a select: [ value, label ]. */
function minn_admin_gfn_pairs( $map ) {
	$out = array();
	foreach ( (array) $map as $k => $v ) {
		$out[] = array( (string) $k, wp_strip_all_tags( is_array( $v ) ? (string) rgar( $v, 'label' ) : (string) $v ) );
	}
	return $out;
}

/** Email fields the Send To Field choice offers (their filter applied). */
function minn_admin_gfn_email_fields( $form ) {
	$fields = array();
	foreach ( (array) $form['fields'] as $field ) {
		if ( 'email' === GFFormsModel::get_input_type( $field ) ) {
			$fields[] = $field;
		}
	}
	$fields = GFNotification::append_filtered_notification_email_fields( $fields, $form );
	$out    = array();
	foreach ( (array) $fields as $field ) {
		if ( is_object( $field ) ) {
			$out[] = array( (string) $field->id, wp_strip_all_tags( GFCommon::get_label( $field ) ) );
		}
	}
	return $out;
}

/**
 * Form fields as rule sources: routing (their routing field types) and
 * conditional logic (fields their logic reads), with choices where the
 * field has them.
 */
function minn_admin_gfn_fields( $form ) {
	$routing = GFNotification::get_routing_field_types();
	$out     = array();
	foreach ( (array) $form['fields'] as $field ) {
		if ( ! is_object( $field ) || ! empty( $field->displayOnly ) ) {
			continue;
		}
		$type    = (string) GFFormsModel::get_input_type( $field );
		$choices = null;
		if ( is_array( $field->choices ) && $field->choices ) {
			$choices = array();
			foreach ( $field->choices as $c ) {
				$choices[] = array( 'text' => wp_strip_all_tags( (string) rgar( $c, 'text' ) ), 'value' => (string) rgar( $c, 'value' ) );
			}
		}
		$logic = method_exists( $field, 'is_conditional_logic_supported' ) && $field->is_conditional_logic_supported();
		$route = in_array( $type, (array) $routing, true ) || in_array( (string) $field->type, (array) $routing, true );
		if ( ! $logic && ! $route ) {
			continue;
		}
		$out[] = array(
			'id'      => (string) $field->id,
			'label'   => wp_strip_all_tags( GFCommon::get_label( $field ) ),
			'type'    => $type,
			'choices' => $choices,
			'logic'   => (bool) $logic,
			'routing' => (bool) $route,
		);
	}
	return $out;
}

/** The form's merge tags, grouped, as their picker lists them. */
function minn_admin_gfn_merge_tags( $form, $event ) {
	$groups = GFCommon::get_merge_tags( $form['fields'], '', false );
	$out    = array();
	foreach ( (array) $groups as $key => $g ) {
		$tags = array();
		foreach ( (array) rgar( $g, 'tags' ) as $t ) {
			if ( ! empty( $t['tag'] ) ) {
				$tags[] = array( 'tag' => (string) $t['tag'], 'label' => wp_strip_all_tags( (string) rgar( $t, 'label' ) ) );
			}
		}
		// Their page adds the save-and-continue tags for the save events.
		if ( 'other' === $key && in_array( $event, array( 'form_saved', 'form_save_email_requested' ), true ) ) {
			$tags[] = array( 'tag' => '{save_link}', 'label' => __( 'Save & Continue Link', 'minn-admin' ) );
			$tags[] = array( 'tag' => '{save_token}', 'label' => __( 'Save & Continue Token', 'minn-admin' ) );
		}
		if ( $tags ) {
			$out[] = array( 'label' => rgar( $g, 'label' ) ? wp_strip_all_tags( (string) $g['label'] ) : '', 'tags' => $tags );
		}
	}
	return $out;
}

/** Normalize stored logic for the client (null when none). */
function minn_admin_gfn_logic_out( $logic ) {
	if ( ! is_array( $logic ) || empty( $logic['rules'] ) || ! is_array( $logic['rules'] ) ) {
		return null;
	}
	$rules = array();
	foreach ( $logic['rules'] as $r ) {
		if ( is_array( $r ) ) {
			$rules[] = array(
				'fieldId'  => (string) rgar( $r, 'fieldId' ),
				'operator' => rgar( $r, 'operator' ) ? (string) $r['operator'] : 'is',
				'value'    => (string) rgar( $r, 'value' ),
			);
		}
	}
	return array(
		'actionType' => 'hide' === rgar( $logic, 'actionType' ) ? 'hide' : 'show',
		'logicType'  => 'any' === rgar( $logic, 'logicType' ) ? 'any' : 'all',
		'rules'      => $rules,
	);
}

/** The latest received entry, for the preview and the test send. */
function minn_admin_gfn_latest_entry( $form_id ) {
	$entries = GFAPI::get_entries( $form_id, array( 'status' => 'active' ), array( 'key' => 'date_created', 'direction' => 'DESC' ), array( 'offset' => 0, 'page_size' => 1 ) );
	return is_array( $entries ) && $entries ? $entries[0] : null;
}

/** The page's whole read: the notification, its form, and every list it picks from. */
function minn_admin_gfn_payload( $form, $n, $is_new ) {
	$to_type = rgar( $n, 'toType' ) ? (string) $n['toType'] : 'email';
	$events  = GFNotification::get_notification_events( $form );
	$event   = rgar( $n, 'event' ) ? (string) $n['event'] : 'form_submission';
	$evpairs = minn_admin_gfn_pairs( $events );
	if ( ! isset( $events[ $event ] ) ) {
		$evpairs[] = array( sanitize_key( $event ), GFNotification::get_missing_notification_event_label( sanitize_key( $event ) ) );
	}
	$routing = array();
	foreach ( (array) rgar( $n, 'routing' ) as $r ) {
		if ( is_array( $r ) ) {
			$routing[] = array(
				'email'    => (string) rgar( $r, 'email' ),
				'fieldId'  => (string) rgar( $r, 'fieldId' ),
				'operator' => rgar( $r, 'operator' ) ? (string) $r['operator'] : 'is',
				'value'    => (string) rgar( $r, 'value' ),
			);
		}
	}
	$latest = minn_admin_gfn_latest_entry( $form['id'] );
	$nid    = $is_new ? 'new' : (string) $n['id'];
	return array(
		'id'                   => $form['id'] . ':' . $nid,
		'isNew'                => (bool) $is_new,
		'notification'         => array(
			'name'              => (string) rgar( $n, 'name' ),
			'isActive'          => ! isset( $n['isActive'] ) || false !== $n['isActive'],
			'service'           => rgar( $n, 'service' ) ? (string) $n['service'] : 'wordpress',
			'event'             => $event,
			'toType'            => $to_type,
			'toEmail'           => 'email' === $to_type ? (string) rgar( $n, 'to' ) : '',
			'toField'           => 'field' === $to_type ? (string) rgar( $n, 'to' ) : '',
			'routing'           => $routing,
			'from'              => (string) rgar( $n, 'from' ),
			'fromName'          => (string) rgar( $n, 'fromName' ),
			'replyTo'           => (string) rgar( $n, 'replyTo' ),
			'cc'                => (string) rgar( $n, 'cc' ),
			'bcc'               => (string) rgar( $n, 'bcc' ),
			'subject'           => (string) rgar( $n, 'subject' ),
			'message'           => (string) rgar( $n, 'message' ),
			'disableAutoformat' => (bool) rgar( $n, 'disableAutoformat' ),
			'enableAttachments' => (bool) rgar( $n, 'enableAttachments' ),
			'conditionalLogic'  => minn_admin_gfn_logic_out( rgar( $n, 'conditionalLogic' ) ),
		),
		'form'                 => array(
			'id'           => (int) $form['id'],
			'title'        => (string) $form['title'],
			'builderRoute' => function_exists( 'minn_admin_gfb_available' ) && minn_admin_gfb_available() ? 'gravity-forms/form/' . (int) $form['id'] : '',
			'latestEntry'  => $latest ? (int) $latest['id'] : 0,
		),
		'adminUrl'             => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id=' . (int) $form['id'] . ( $is_new ? '&nid=' : '&nid=' . rawurlencode( (string) $n['id'] ) ) ),
		'events'               => $evpairs,
		'services'             => minn_admin_gfn_pairs( GFNotification::get_notification_services() ),
		'emailFields'          => minn_admin_gfn_email_fields( $form ),
		'fields'               => minn_admin_gfn_fields( $form ),
		'mergeTags'            => minn_admin_gfn_merge_tags( $form, $event ),
		'ccEnabled'            => (bool) gf_apply_filters( array( 'gform_notification_enable_cc', $form['id'], rgar( $n, 'id' ) ), false, $n, $form ),
		'attachmentsAvailable' => (bool) GFCommon::get_fields_by_type( $form, array( 'fileupload' ) ),
		'fromWarningOff'       => (bool) gf_apply_filters( array( 'gform_notification_disable_from_warning', $form['id'], rgar( $n, 'id' ) ), false ),
		'siteHost'             => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		'adminEmail'           => (string) get_bloginfo( 'admin_email' ),
		'canHtml'              => current_user_can( 'unfiltered_html' ),
	);
}

/** A 400 that names the field it is about. */
function minn_admin_gfn_invalid( $field, $message ) {
	return new WP_Error( 'minn_gfn_invalid', $message, array( 'status' => 400, 'field' => $field ) );
}

/** Logic / routing rule operators their sanitizer keeps. */
function minn_admin_gfn_operators() {
	return array( 'is', 'isnot', '>', '<', 'contains', 'starts_with', 'ends_with' );
}

/**
 * Apply the page's fields to a notification the way their save callback
 * does, validating as their settings fields do. $stored is the saved
 * notification (or array() for a new one).
 *
 * @return array|WP_Error The notification ready to save.
 */
function minn_admin_gfn_build( $form, $stored, $body, $is_new ) {
	$n   = $stored;
	$val = function ( $key ) use ( $body, $stored ) {
		return array_key_exists( $key, $body ) && is_scalar( $body[ $key ] ) ? (string) $body[ $key ] : '';
	};

	$name = trim( sanitize_text_field( $val( 'name' ) ) );
	if ( '' === $name ) {
		return minn_admin_gfn_invalid( 'name', __( 'Give the notification a name.', 'minn-admin' ) );
	}
	foreach ( $form['notifications'] as $other_id => $other ) {
		if ( ( $is_new || (string) $other_id !== (string) rgar( $stored, 'id' ) ) && strtolower( (string) rgar( $other, 'name' ) ) === strtolower( $name ) ) {
			return minn_admin_gfn_invalid( 'name', __( 'Another notification on this form already uses that name.', 'minn-admin' ) );
		}
	}

	$events = GFNotification::get_notification_events( $form );
	$event  = $val( 'event' );
	if ( '' === $event || ( ! isset( $events[ $event ] ) && $event !== (string) rgar( $stored, 'event' ) ) ) {
		return minn_admin_gfn_invalid( 'event', __( 'Choose when the notification sends.', 'minn-admin' ) );
	}
	$services = GFNotification::get_notification_services();
	$service  = $val( 'service' ) ? $val( 'service' ) : 'wordpress';
	if ( ! isset( $services[ $service ] ) && $service !== (string) rgar( $stored, 'service' ) ) {
		$service = (string) key( $services );
	}

	$to_type = $val( 'toType' );
	if ( 'hidden' === (string) rgar( $stored, 'toType' ) ) {
		$to_type = 'hidden'; // set by code, kept as it is
	} elseif ( ! in_array( $to_type, array( 'email', 'field', 'routing' ), true ) ) {
		$to_type = 'email';
	}
	$to = (string) rgar( $stored, 'to' );
	if ( 'email' === $to_type ) {
		$to       = trim( $val( 'toEmail' ) );
		$is_valid = GFNotification::is_valid_notification_email( $to );
		$is_valid = apply_filters( 'gform_is_valid_notification_to', $is_valid, $to_type, $to, '' );
		if ( ! $is_valid ) {
			return minn_admin_gfn_invalid( 'toEmail', __( 'Enter a valid email address (or several, separated by commas) or a merge tag.', 'minn-admin' ) );
		}
	} elseif ( 'field' === $to_type ) {
		$to      = $val( 'toField' );
		$allowed = wp_list_pluck( minn_admin_gfn_email_fields( $form ), 0 );
		$is_valid = in_array( $to, $allowed, true );
		$is_valid = apply_filters( 'gform_is_valid_notification_to', $is_valid, $to_type, '', $to );
		if ( ! $is_valid ) {
			return minn_admin_gfn_invalid( 'toField', __( 'Choose the form’s email field to send to.', 'minn-admin' ) );
		}
	} elseif ( 'routing' === $to_type ) {
		$to = '';
	}

	$routing = null;
	if ( 'routing' === $to_type ) {
		$rules   = array();
		$sources = wp_list_pluck( minn_admin_gfn_fields( $form ), 'id' );
		foreach ( (array) ( $body['routing'] ?? array() ) as $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$email = trim( (string) ( $r['email'] ?? '' ) );
			if ( ! GFNotification::is_valid_notification_email( $email ) ) {
				return minn_admin_gfn_invalid( 'routing', __( 'Every routing rule needs a valid email address or merge tag.', 'minn-admin' ) );
			}
			$field_id = (string) ( $r['fieldId'] ?? '' );
			if ( ! in_array( $field_id, $sources, true ) ) {
				return minn_admin_gfn_invalid( 'routing', __( 'Every routing rule needs a field to test.', 'minn-admin' ) );
			}
			$rules[] = array(
				'email'    => $email,
				'fieldId'  => $field_id,
				'operator' => in_array( (string) ( $r['operator'] ?? '' ), minn_admin_gfn_operators(), true ) ? (string) $r['operator'] : 'is',
				'value'    => is_scalar( $r['value'] ?? '' ) ? (string) $r['value'] : '',
			);
		}
		if ( ! $rules ) {
			return minn_admin_gfn_invalid( 'routing', __( 'Add at least one routing rule.', 'minn-admin' ) );
		}
		$sanitized = GFFormsModel::sanitize_conditional_logic( array( 'rules' => $rules ) );
		$routing   = $sanitized['rules'];
	}

	foreach ( array( 'from' => __( 'From Email', 'minn-admin' ), 'replyTo' => __( 'Reply To', 'minn-admin' ), 'cc' => 'CC', 'bcc' => 'BCC' ) as $key => $label ) {
		$v = trim( $val( $key ) );
		if ( '' !== $v && ! GFNotification::is_valid_notification_email( $v ) ) {
			/* translators: %s: the field's name (From Email, Reply To, CC, BCC). */
			return minn_admin_gfn_invalid( $key, sprintf( __( 'Enter a valid email address or merge tag in %s.', 'minn-admin' ), $label ) );
		}
	}

	// Subject: their text field refuses what sanitizing would change; an
	// unchanged subject is kept exactly as stored.
	$subject = $val( 'subject' );
	if ( $subject !== (string) rgar( $stored, 'subject' ) && sanitize_text_field( $subject ) !== $subject ) {
		return minn_admin_gfn_invalid( 'subject', __( 'The subject has characters Gravity Forms does not allow.', 'minn-admin' ) );
	}
	if ( '' === trim( $subject ) ) {
		return minn_admin_gfn_invalid( 'subject', __( 'Give the notification a subject.', 'minn-admin' ) );
	}
	// Message: email-body HTML, filtered the way Gravity Forms filters it;
	// an unchanged message is kept exactly as stored.
	$message = $val( 'message' );
	if ( '' === trim( $message ) ) {
		return minn_admin_gfn_invalid( 'message', __( 'Write the message.', 'minn-admin' ) );
	}
	if ( $message !== (string) rgar( $stored, 'message' ) ) {
		$message = minn_admin_gf_kses( $message );
	}

	$logic = null;
	if ( ! empty( $body['conditionalLogic'] ) && is_array( $body['conditionalLogic'] ) && ! empty( $body['conditionalLogic']['rules'] ) ) {
		$rules = array();
		foreach ( (array) $body['conditionalLogic']['rules'] as $r ) {
			if ( is_array( $r ) && '' !== (string) ( $r['fieldId'] ?? '' ) ) {
				$rules[] = array(
					'fieldId'  => (string) $r['fieldId'],
					'operator' => in_array( (string) ( $r['operator'] ?? '' ), minn_admin_gfn_operators(), true ) ? (string) $r['operator'] : 'is',
					'value'    => is_scalar( $r['value'] ?? '' ) ? (string) $r['value'] : '',
				);
			}
		}
		if ( $rules ) {
			$logic = GFFormsModel::sanitize_conditional_logic( array(
				'actionType' => 'hide' === ( $body['conditionalLogic']['actionType'] ?? '' ) ? 'hide' : 'show',
				'logicType'  => 'any' === ( $body['conditionalLogic']['logicType'] ?? '' ) ? 'any' : 'all',
				'rules'      => $rules,
			) );
		}
	}

	// Their save callback, in order.
	unset( $n['type'] );
	$n['name']     = $name;
	$n['service']  = $service;
	$n['event']    = $event;
	$n['toType']   = $to_type;
	$n['to']       = $to;
	$n['from']     = trim( $val( 'from' ) );
	$n['fromName'] = sanitize_text_field( $val( 'fromName' ) );
	$n['replyTo']  = trim( $val( 'replyTo' ) );
	if ( gf_apply_filters( array( 'gform_notification_enable_cc', $form['id'], rgar( $stored, 'id' ) ), false, $stored, $form ) ) {
		$n['cc'] = trim( $val( 'cc' ) );
	}
	$n['bcc']               = trim( $val( 'bcc' ) );
	$n['subject']           = $subject;
	$n['message']           = $message;
	$n['disableAutoformat'] = ! empty( $body['disableAutoformat'] );
	$n['enableAttachments'] = ! empty( $body['enableAttachments'] );
	$n['conditionalLogic']  = $logic;
	if ( null !== $routing ) {
		$n['routing'] = $routing;
	}
	return GFCommon::fix_notification_routing( $n );
}

/** Save through their write path; returns the saved notification id. */
function minn_admin_gfn_store( $form, $n, $is_new ) {
	if ( $is_new ) {
		$n['id']       = uniqid();
		$n['isActive'] = true;
	}
	$n = gf_apply_filters( array( 'gform_pre_notification_save', $form['id'] ), $n, $form, $is_new );
	$n = GFFormsModel::trim_conditional_logic_values_from_element( $n, $form );
	$form['notifications'][ $n['id'] ] = $n;
	GFFormsModel::flush_current_forms();
	GFFormsModel::save_form_notifications( $form['id'], $form['notifications'] );
	GFFormsModel::flush_current_forms();
	return (string) $n['id'];
}

/** A notification built from the page's unsaved fields, for preview and test. */
function minn_admin_gfn_draft( $form, $stored, $body ) {
	$n = $stored;
	foreach ( array( 'subject', 'message', 'from', 'fromName', 'replyTo' ) as $key ) {
		if ( isset( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
			$n[ $key ] = (string) $body[ $key ];
		}
	}
	// An edited message is filtered as the save would filter it.
	if ( isset( $n['message'] ) && (string) $n['message'] !== (string) rgar( $stored, 'message' ) ) {
		$n['message'] = minn_admin_gf_kses( (string) $n['message'] );
	}
	$n['disableAutoformat'] = ! empty( $body['disableAutoformat'] );
	if ( ! isset( $n['id'] ) ) {
		$n['id'] = 'minn-preview';
	}
	if ( ! isset( $n['name'] ) ) {
		$n['name'] = isset( $body['name'] ) && is_scalar( $body['name'] ) ? (string) $body['name'] : __( 'New notification', 'minn-admin' );
	}
	return $n;
}

add_action( 'rest_api_init', function () {
	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}
	$perm = function () {
		return GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );
	};
	$resolve = function ( WP_REST_Request $request ) {
		if ( ! minn_admin_gfn_load() ) {
			return new WP_Error( 'minn_gfn_unavailable', __( 'This version of Gravity Forms can’t be edited from here. Use Gravity Forms.', 'minn-admin' ), array( 'status' => 501 ) );
		}
		$form_id = (int) Minn_Admin::path_param( $request, 'form' );
		$nid     = (string) Minn_Admin::path_param( $request, 'nid' );
		$form    = minn_admin_gfn_form( $form_id, $nid );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		$is_new = 'new' === $nid;
		if ( ! $is_new && ! isset( $form['notifications'][ $nid ] ) ) {
			return new WP_Error( 'not_found', __( 'Notification not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		$stored = $is_new ? array() : $form['notifications'][ $nid ];
		return array( $form, $stored, $is_new, $nid );
	};
	$id_re = '(?P<form>\d+):(?P<nid>[a-zA-Z0-9_.-]+)';

	register_rest_route( 'minn-admin/v1', '/gf/notifications/' . $id_re . '/full', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
				$r = $resolve( $request );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				list( $form, $stored, $is_new ) = $r;
				return rest_ensure_response( minn_admin_gfn_payload( $form, $is_new ? minn_admin_gfn_defaults() : $stored, $is_new ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
				$r = $resolve( $request );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				list( $form, $stored, $is_new ) = $r;
				$body = (array) $request->get_json_params();
				$n    = minn_admin_gfn_build( $form, $stored, $body, $is_new );
				if ( is_wp_error( $n ) ) {
					return $n;
				}
				$nid = minn_admin_gfn_store( $form, $n, $is_new );
				// The Active switch through their own toggle (its hooks fire).
				if ( array_key_exists( 'isActive', $body ) ) {
					$was = $is_new ? true : ( ! isset( $stored['isActive'] ) || false !== $stored['isActive'] );
					if ( (bool) $body['isActive'] !== $was ) {
						GFFormsModel::update_notification_active( $form['id'], $nid, (bool) $body['isActive'] );
						GFFormsModel::flush_current_forms();
					}
				}
				$fresh = minn_admin_gfn_form( $form['id'], $nid );
				return rest_ensure_response( minn_admin_gfn_payload( $fresh, $fresh['notifications'][ $nid ], false ) );
			},
		),
	) );

	// Start a new notification for a form (the list's New notification): it
	// exists only once its page is saved, so this just names the page.
	register_rest_route( 'minn-admin/v1', '/gf/notifications/new', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$form_id = (int) $request->get_param( 'form' );
			if ( ! $form_id || ! GFAPI::form_id_exists( $form_id ) ) {
				return new WP_Error( 'not_found', __( 'Choose a form for the notification.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array(
				'id'      => $form_id . ':new',
				'message' => __( 'Set up the notification, then save it.', 'minn-admin' ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gf/notifications/' . $id_re . '/duplicate', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, , $is_new, $nid ) = $r;
			if ( $is_new ) {
				return new WP_Error( 'minn_gfn_unsaved', __( 'Save the notification before duplicating it.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$before = array_keys( $form['notifications'] );
			GFNotification::duplicate_notification( $nid, $form['id'] );
			GFFormsModel::flush_current_forms();
			$after = minn_admin_gfn_form( $form['id'], $nid );
			$new   = array_values( array_diff( array_keys( $after['notifications'] ), $before ) );
			if ( ! $new ) {
				return new WP_Error( 'minn_gfn_dup', __( 'Gravity Forms did not duplicate the notification.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'id' => $form['id'] . ':' . $new[0], 'message' => __( 'Notification duplicated', 'minn-admin' ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gf/notifications/' . $id_re, array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, , $is_new, $nid ) = $r;
			if ( $is_new ) {
				return new WP_Error( 'not_found', __( 'Notification not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			GFNotification::delete_notification( $nid, $form['id'] );
			GFFormsModel::flush_current_forms();
			return rest_ensure_response( array( 'deleted' => true, 'message' => __( 'Notification deleted', 'minn-admin' ) ) );
		},
	) );

	// Preview: the subject and message rendered with the form's latest
	// entry, the way their send renders them (merge tags replaced,
	// auto-formatting applied unless it is off). Shortcodes are left as
	// typed; running them here could act on the site.
	register_rest_route( 'minn-admin/v1', '/gf/notifications/' . $id_re . '/preview', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, $stored ) = $r;
			$n     = minn_admin_gfn_draft( $form, $stored, (array) $request->get_json_params() );
			$entry = minn_admin_gfn_latest_entry( $form['id'] );
			if ( ! $entry ) {
				return rest_ensure_response( array(
					'entry'   => 0,
					'subject' => (string) $n['subject'],
					'html'    => (string) $n['message'],
				) );
			}
			$autoformat = empty( $n['disableAutoformat'] );
			return rest_ensure_response( array(
				'entry'   => (int) $entry['id'],
				'subject' => (string) GFCommon::replace_variables( (string) $n['subject'], $form, $entry, false, false, false, 'text' ),
				'html'    => (string) GFCommon::replace_variables( (string) $n['message'], $form, $entry, false, false, $autoformat, 'html' ),
			) );
		},
	) );

	// Send test: the page's current subject, sender and message, built from
	// the form's latest entry, sent ONLY to the person editing (recipients,
	// CC, BCC and routing are replaced), through their own send.
	register_rest_route( 'minn-admin/v1', '/gf/notifications/' . $id_re . '/test', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, $stored ) = $r;
			$me = wp_get_current_user();
			if ( ! $me || ! is_email( $me->user_email ) ) {
				return new WP_Error( 'minn_gfn_test', __( 'Your account has no email address to send a test to.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$entry = minn_admin_gfn_latest_entry( $form['id'] );
			if ( ! $entry ) {
				return new WP_Error( 'minn_gfn_test', __( 'A test uses the form’s latest entry, and this form has none yet.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$n = minn_admin_gfn_draft( $form, $stored, (array) $request->get_json_params() );
			foreach ( array( 'from', 'replyTo' ) as $key ) {
				if ( '' !== trim( (string) rgar( $n, $key ) ) && ! GFNotification::is_valid_notification_email( (string) $n[ $key ] ) ) {
					return minn_admin_gfn_invalid( $key, __( 'Fix the sender addresses before sending a test.', 'minn-admin' ) );
				}
			}
			$n['toType']  = 'email';
			$n['to']      = $me->user_email;
			$n['cc']      = '';
			$n['bcc']     = '';
			$n['routing'] = null;
			$n['subject'] = '[' . __( 'Test', 'minn-admin' ) . '] ' . (string) rgar( $n, 'subject' );
			$result       = GFCommon::send_notification( $n, $form, $entry );
			if ( ! is_array( $result ) ) {
				return new WP_Error( 'minn_gfn_test', __( 'Gravity Forms could not send the test. Check the site’s mail settings.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'email' => $me->user_email, 'entry' => (int) $entry['id'] ) );
		},
	) );
} );
