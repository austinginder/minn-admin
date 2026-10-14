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

/**
 * The latest received entry, for the previews and the test send. Only for
 * someone Gravity Forms lets read entries: editing a form doesn't include it,
 * so without gravityforms_view_entries the previews keep their tags as typed.
 */
function minn_admin_gfn_latest_entry( $form_id ) {
	if ( ! GFCommon::current_user_can_any( 'gravityforms_view_entries' ) ) {
		return null;
	}
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

/**
 * Routing or logic rules through their sanitizer, as their save runs it: an
 * operator stays whatever is_valid_operator() accepts (its list is wider than
 * the page's picker, and add-ons extend it through a filter). The page sends
 * every rule back on any save, so an operator their check refuses but this
 * list already stored (an add-on's, with its validator not loaded here) is
 * kept as stored rather than becoming "is" under an unrelated edit.
 *
 * @param array $logic        { rules[], ... } as the page sent it.
 * @param array $stored_rules The same list's rules as stored.
 */
function minn_admin_gfn_sanitize_rules( $logic, $stored_rules ) {
	$sanitized = GFFormsModel::sanitize_conditional_logic( $logic );
	$stored    = array();
	foreach ( (array) $stored_rules as $r ) {
		if ( is_array( $r ) && isset( $r['operator'] ) && is_string( $r['operator'] ) ) {
			$stored[] = $r['operator'];
		}
	}
	foreach ( (array) rgar( $logic, 'rules' ) as $i => $r ) {
		if ( isset( $sanitized['rules'][ $i ]['operator'] ) && $sanitized['rules'][ $i ]['operator'] !== $r['operator'] && in_array( $r['operator'], $stored, true ) ) {
			$sanitized['rules'][ $i ]['operator'] = $r['operator'];
		}
	}
	return $sanitized;
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
				'operator' => is_scalar( $r['operator'] ?? '' ) ? (string) ( $r['operator'] ?? '' ) : '',
				'value'    => is_scalar( $r['value'] ?? '' ) ? (string) $r['value'] : '',
			);
		}
		if ( ! $rules ) {
			return minn_admin_gfn_invalid( 'routing', __( 'Add at least one routing rule.', 'minn-admin' ) );
		}
		$sanitized = minn_admin_gfn_sanitize_rules( array( 'rules' => $rules ), rgar( $stored, 'routing' ) );
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
					'operator' => is_scalar( $r['operator'] ?? '' ) ? (string) ( $r['operator'] ?? '' ) : '',
					'value'    => is_scalar( $r['value'] ?? '' ) ? (string) $r['value'] : '',
				);
			}
		}
		if ( $rules ) {
			$logic = minn_admin_gfn_sanitize_rules( array(
				'actionType' => 'hide' === ( $body['conditionalLogic']['actionType'] ?? '' ) ? 'hide' : 'show',
				'logicType'  => 'any' === ( $body['conditionalLogic']['logicType'] ?? '' ) ? 'any' : 'all',
				'rules'      => $rules,
			), rgars( $stored, 'conditionalLogic/rules' ) );
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

/**
 * The merge tags a notification preview or test send resolves from a real
 * entry (Minn_Admin_Preview): Gravity Forms' own that read only the entry,
 * the form, the site or the person previewing. A field tag is named by the
 * form's own input ids. {entry:*} hands a column over raw, with none of the
 * brace and bracket encoding a field tag gets (so a value would be read
 * again as a tag, or run as a shortcode in a test send), so only the
 * columns Gravity Forms itself writes. {user:*} reads the person
 * previewing, so only its profile fields. {created_by:*} (the submitter's
 * account), {embed_post:*}, {custom_field:*} and {embed_url} (the page it
 * was sent from), {ip}, {user_agent} and {referer} (the sender's request),
 * {payment_action:*}, the save-and-continue tags and every add-on's tag
 * stay placeholders.
 *
 * @param array $form The form.
 * @return string[]
 */
function minn_admin_gfn_preview_tags( $form ) {
	$tags = array(
		'all_fields', 'all_fields:*', 'all_fields_display_empty', 'pricing_fields', 'pricing_fields:*',
		'form_title', 'form_id', 'entry_id', 'entry_url', 'post_id', 'post_edit_url', 'admin_email', 'admin_url', 'logout_url',
		'date_mdy', 'date_dmy', 'today', 'today:*', 'date_created', 'date_created:*', 'date_updated', 'date_updated:*', 'payment_date', 'payment_date:*',
		'entry:id', 'entry:form_id', 'entry:post_id', 'entry:date_created', 'entry:date_updated', 'entry:is_starred', 'entry:is_read', 'entry:status',
		'entry:currency', 'entry:payment_status', 'entry:payment_date', 'entry:payment_amount', 'entry:payment_method', 'entry:transaction_id', 'entry:transaction_type', 'entry:is_fulfilled',
		'user:ID', 'user:id', 'user:display_name', 'user:user_email', 'user:user_login', 'user:first_name', 'user:last_name', 'user:nickname',
		// The submitter's profile fields Gravity Forms' entry screen shows;
		// any other key of their account stays a placeholder.
		'created_by:ID', 'created_by:id', 'created_by:display_name', 'created_by:user_login', 'created_by:user_email', 'created_by:first_name', 'created_by:last_name', 'created_by:nickname', 'created_by:roles', 'created_by:user_url',
	);
	foreach ( (array) rgar( $form, 'fields' ) as $field ) {
		if ( is_object( $field ) && '' !== (string) $field->id ) {
			$tags[] = ':' . $field->id;
			$tags[] = ':' . $field->id . '.*';
		}
	}
	return $tags;
}

/**
 * A preview's or test send's text, ready for Gravity Forms: every tag off
 * the allowlist a placeholder, and the submitter's allowed profile fields
 * filled here from the entry, braces encoded. minn_admin_gfn_guarded() then
 * hands Gravity Forms the entry with no submitter, so a {created_by:*}
 * carried in the form title or an answer, which Gravity Forms scans again
 * after putting them in, reads nothing (not even a column of their account).
 *
 * @param string $text  Subject, message, address or URL.
 * @param array  $form  The form.
 * @param array  $entry The entry, as stored.
 * @return string
 */
function minn_admin_gfn_prepare( $text, $form, $entry ) {
	$text = Minn_Admin_Preview::restrict( (string) $text, 'gf', minn_admin_gfn_preview_tags( $form ) );
	$uid  = (int) rgar( (array) $entry, 'created_by' );
	$user = $uid > 0 ? get_userdata( $uid ) : false;
	return (string) preg_replace_callback( '/\{created_by:(ID|id|display_name|user_login|user_email|first_name|last_name|nickname|roles|user_url) ?\}/', function ( $m ) use ( $user ) {
		if ( ! $user ) {
			return '';
		}
		$v = 'roles' === $m[1] ? implode( ', ', (array) $user->roles ) : ( 'id' === $m[1] ? $user->ID : $user->get( $m[1] ) );
		return str_replace( array( '{', '}' ), array( '&#123;', '&#125;' ), (string) $v );
	}, $text );
}

/**
 * Merge tags that read past the entry: {created_by:<key>} reads any meta of
 * the account that submitted it (Gravity Forms answers it through
 * WP_User::get()), {custom_field:<key>} any meta of the page it was sent
 * from, protected keys included, and {embed_post:<field>} any field of that
 * page, private or draft. A preview made from a real entry shows only the
 * profile fields Gravity Forms' entry screen shows; every other such tag
 * stays a placeholder (the notification preview and test send go further:
 * minn_admin_gfn_preview_tags()). The created_by match is Gravity
 * Forms' own (common.php): the colon is optional, so
 * {created_by_two_factor_totp_key} reads that key too, and a key runs up to
 * the closing brace across lines, less one trailing space. Real sends are
 * unchanged.
 *
 * @param string $text Subject, message or address with merge tags.
 * @return string
 */
function minn_admin_gfn_neutral_tags( $text ) {
	$text = (string) preg_replace_callback( '/\{created_by:?(.*?)(?:\s)?\}/ism', function ( $m ) {
		$safe = array( 'ID', 'id', 'display_name', 'user_login', 'user_email', 'first_name', 'last_name', 'nickname', 'roles', 'user_url' );
		/* translators: %s: a user property named in a merge tag. */
		return in_array( $m[1], $safe, true ) ? $m[0] : sprintf( __( '[user meta: %s]', 'minn-admin' ), sanitize_key( $m[1] ) );
	}, (string) $text );
	return (string) preg_replace_callback( '/\{(?:custom_field|embed_post):([^}]*)\}/i', function ( $m ) {
		/* translators: %s: a field of the page a form was sent from, named in a merge tag. */
		return sprintf( __( '[page field: %s]', 'minn-admin' ), sanitize_key( $m[1] ) );
	}, $text );
}

/**
 * Runs a preview render or a test send with the entry's page and its
 * submitter's meta out of reach. Gravity Forms resolves {embed_post:*},
 * {custom_field:*} and {created_by:*} after {form_title} and the answers,
 * and {created_by:*} after {user:*} too, so a value the form editor, the
 * viewer or the submitter wrote can carry one of those tags in, where no
 * reading of the typed text sees it. The render gets an entry naming no
 * page ({embed_url} reads empty), and the submitter's meta reads empty
 * except the profile fields the entry screen shows.
 *
 * Add-ons answer their own tags on gform_replace_merge_tags, and what they
 * do there is theirs: User Registration's {set_password_url} mints a
 * password reset for the account tied to the entry, which can be an
 * administrator's, and writes its key. Only Gravity Forms' own callbacks
 * run on that filter here, so an add-on's tag stays as typed, and those
 * two tags become placeholders ahead of any callback, composed or not.
 *
 * @param array    $entry  The entry.
 * @param callable $render Called with the guarded entry.
 * @return mixed What $render returns.
 */
function minn_admin_gfn_guarded( $entry, $render ) {
	global $wpdb, $wp_filter;
	$uid  = (int) rgar( $entry, 'created_by' );
	$keep = array( 'first_name', 'last_name', 'nickname', $wpdb->get_blog_prefix() . 'capabilities', $wpdb->get_blog_prefix() . 'user_level' );
	$deny = function ( $value, $object_id, $meta_key, $single ) use ( $uid, $keep ) {
		if ( ! $uid || (int) $object_id !== $uid || in_array( (string) $meta_key, $keep, true ) ) {
			return $value;
		}
		return $single ? '' : array();
	};
	$entry['source_id']  = '';
	$entry['source_url'] = '';
	// The submitter's allowed fields are already filled (minn_admin_gfn_prepare()).
	$entry['created_by'] = '';
	$hook = 'gform_replace_merge_tags';
	$held = isset( $wp_filter[ $hook ] ) && $wp_filter[ $hook ] instanceof WP_Hook ? $wp_filter[ $hook ] : null;
	$only = new WP_Hook();
	foreach ( $held ? $held->callbacks : array() as $priority => $callbacks ) {
		foreach ( $callbacks as $cb ) {
			if ( minn_admin_gfn_is_core_callback( $cb['function'] ) ) {
				$only->add_filter( $hook, $cb['function'], $priority, $cb['accepted_args'] );
			}
		}
	}
	$only->add_filter( $hook, function ( $text ) {
		return str_replace( array( '{set_password_url}', '{activation_url}' ), array( '[set_password_url]', '[activation_url]' ), (string) $text );
	}, 9, 1 );
	$wp_filter[ $hook ] = $only;
	add_filter( 'get_user_metadata', $deny, PHP_INT_MAX, 4 );
	try {
		return $render( $entry );
	} finally {
		remove_filter( 'get_user_metadata', $deny, PHP_INT_MAX );
		if ( $held ) {
			$wp_filter[ $hook ] = $held;
		} else {
			unset( $wp_filter[ $hook ] );
		}
	}
}

/**
 * Whether a hook callback is Gravity Forms' own: its code lives in the
 * Gravity Forms plugin folder. Anything that can't be traced is not.
 *
 * @param callable $fn Callback.
 * @return bool
 */
function minn_admin_gfn_is_core_callback( $fn ) {
	try {
		if ( is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
			$fn = explode( '::', $fn, 2 );
		}
		if ( is_array( $fn ) ) {
			$ref = new ReflectionMethod( $fn[0], $fn[1] );
		} elseif ( is_object( $fn ) && ! $fn instanceof Closure ) {
			$ref = new ReflectionMethod( $fn, '__invoke' );
		} else {
			$ref = new ReflectionFunction( $fn );
		}
	} catch ( Throwable $e ) {
		return false;
	}
	$file = $ref->getFileName();
	$base = trailingslashit( wp_normalize_path( (string) GFCommon::get_base_path() ) );
	return is_string( $file ) && 0 === strpos( wp_normalize_path( $file ), $base );
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
				// The page sends what it changed; anything it leaves out keeps
				// its stored value (as the page itself reads it), so a page left
				// open does not put back routing, conditions or a BCC changed
				// elsewhere since, and a partial body resets nothing.
				if ( ! $is_new ) {
					$body = array_merge( minn_admin_gfn_payload( $form, $stored, false )['notification'], $body );
				}
				$n = minn_admin_gfn_build( $form, $stored, $body, $is_new );
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
			$subject    = minn_admin_gfn_prepare( $n['subject'], $form, $entry );
			$message    = minn_admin_gfn_prepare( $n['message'], $form, $entry );
			return rest_ensure_response( minn_admin_gfn_guarded( $entry, function ( $entry ) use ( $subject, $message, $form, $autoformat ) {
				return array(
					'entry'   => (int) $entry['id'],
					'subject' => (string) GFCommon::replace_variables( $subject, $form, $entry, false, false, false, 'text' ),
					'html'    => (string) GFCommon::replace_variables( $message, $form, $entry, false, false, $autoformat, 'html' ),
				);
			} ) );
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
			if ( ! GFCommon::current_user_can_any( 'gravityforms_view_entries' ) ) {
				return new WP_Error( 'minn_gfn_test', __( 'A test sends the form’s latest entry, and your account can’t view entries.', 'minn-admin' ), array( 'status' => 403 ) );
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

			foreach ( array( 'subject', 'message', 'from', 'fromName', 'replyTo' ) as $key ) {
				if ( isset( $n[ $key ] ) ) {
					$n[ $key ] = minn_admin_gfn_prepare( $n[ $key ], $form, $entry );
				}
			}
			$n['subject'] = '[' . __( 'Test', 'minn-admin' ) . '] ' . (string) rgar( $n, 'subject' );
			// Gravity Forms runs shortcodes in the message as it sends; a test
			// is a preview that lands in an inbox, and the preview runs none,
			// so a shortcode typed here goes out as the text it is.
			$result       = minn_admin_gfn_guarded( $entry, function ( $entry ) use ( $n, $form ) {
				global $shortcode_tags;
				$held           = $shortcode_tags;
				$shortcode_tags = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				try {
					return GFCommon::send_notification( $n, $form, $entry );
				} finally {
					$shortcode_tags = $held; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				}
			} );
			if ( ! is_array( $result ) ) {
				return new WP_Error( 'minn_gfn_test', __( 'Gravity Forms could not send the test. Check the site’s mail settings.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'email' => $me->user_email, 'entry' => (int) $entry['id'] ) );
		},
	) );
} );
