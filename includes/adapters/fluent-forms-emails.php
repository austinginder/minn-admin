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
 * confirmation changes. A validation failure comes back on its field.
 *
 * Only what Fluent offers in the edition installed: their free editor locks
 * adding notifications, recipient routing, notification conditions and the
 * extra conditional confirmations to Pro, so without Pro those are neither
 * offered nor touched (a stored routing list or condition set is kept as it
 * is). With Pro the page still has no routing editor, so a routing
 * notification keeps its list there too. The preview renders the
 * notification's subject and message with the form's latest entry through
 * their ShortCodeParser, the way their sender does.
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
		'confirmation'  => array(
			'redirectTo'           => (string) ( $c['redirectTo'] ?? 'samePage' ),
			'messageToShow'        => (string) ( $c['messageToShow'] ?? '' ),
			'samePageFormBehavior' => 'reset_form' === ( $c['samePageFormBehavior'] ?? '' ) ? 'reset_form' : 'hide_form',
			'customPage'           => $page ? (string) $page->ID : '',
			'customUrl'            => (string) ( $c['customUrl'] ?? '' ),
		),
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

	register_rest_route( 'minn-admin/v1', '/fluent-forms/forms/(?P<id>\d+)/emails', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
				$form = $load( $request );
				return is_wp_error( $form ) ? $form : rest_ensure_response( minn_admin_fluent_emails_payload( $form ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
				$form = $load( $request );
				if ( is_wp_error( $form ) ) {
					return $form;
				}
				$body    = (array) $request->get_json_params();
				$service = new \FluentForm\App\Services\Settings\SettingsService();
				$stored  = minn_admin_fluent_notifications( $form->id );

				// Notifications: each existing one through their store().
				foreach ( (array) ( $body['notifications'] ?? array() ) as $in ) {
					$id = (int) ( $in['metaId'] ?? 0 );
					if ( ! isset( $stored[ $id ] ) ) {
						return new WP_Error( 'minn_ffe_unknown', __( 'That notification is not on this form.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$n    = $stored[ $id ];
					$text = function ( $k ) use ( $in ) {
						return isset( $in[ $k ] ) && is_scalar( $in[ $k ] ) ? (string) $in[ $k ] : '';
					};
					// The page sends the keys it changed; one it leaves out
					// keeps its stored value.
					$has = function ( $k ) use ( $in ) {
						return array_key_exists( $k, $in );
					};
					// A routing list is Pro's and is edited in Fluent Forms, with
					// or without Pro here: the page offers no routing editor, so
					// a stored list is kept as it is on any save.
					$type = $has( 'toType' ) ? $text( 'toType' ) : (string) ( $n['sendTo']['type'] ?? 'email' );
					if ( 'routing' === ( $n['sendTo']['type'] ?? '' ) ) {
						$type = 'routing';
					} elseif ( ! in_array( $type, array( 'email', 'field' ), true ) ) {
						$type = 'email';
					}
					if ( $has( 'name' ) ) {
						$n['name'] = sanitize_text_field( $text( 'name' ) );
					}
					// The switch only when sent: the page sends it only when it
					// was flipped there, so one turned off elsewhere stays off.
					if ( array_key_exists( 'enabled', $in ) ) {
						$n['enabled'] = ! empty( $in['enabled'] );
					}
					$n['sendTo']['type']   = $type;
					if ( 'email' === $type && $has( 'toEmail' ) ) {
						$n['sendTo']['email'] = trim( $text( 'toEmail' ) );
					} elseif ( 'field' === $type && $has( 'toField' ) ) {
						$n['sendTo']['field'] = $text( 'toField' );
					}
					foreach ( array( 'fromName', 'fromEmail', 'replyTo', 'bcc', 'subject', 'message' ) as $k ) {
						if ( $has( $k ) ) {
							$n[ $k ] = $text( $k );
						}
					}
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

				// The confirmation: their saveGeneral() with both settings whole.
				if ( isset( $body['confirmation'] ) && is_array( $body['confirmation'] ) ) {
					$settings = minn_admin_fluent_meta( $form->id, 'formSettings' );
					$c        = (array) ( $settings['confirmation'] ?? array() );
					// Keys the page leaves out keep their stored values.
					$in = array_merge(
						array(
							'redirectTo'           => $c['redirectTo'] ?? 'samePage',
							'messageToShow'        => $c['messageToShow'] ?? '',
							'samePageFormBehavior' => $c['samePageFormBehavior'] ?? 'hide_form',
							'customPage'           => $c['customPage'] ?? 0,
							'customUrl'            => $c['customUrl'] ?? '',
						),
						$body['confirmation']
					);
					$to       = (string) ( $in['redirectTo'] ?? 'samePage' );
					$c['redirectTo']           = in_array( $to, array( 'samePage', 'customPage', 'customUrl' ), true ) ? $to : 'samePage';
					$c['messageToShow']        = is_scalar( $in['messageToShow'] ?? '' ) ? (string) ( $in['messageToShow'] ?? '' ) : '';
					$c['samePageFormBehavior'] = 'reset_form' === ( $in['samePageFormBehavior'] ?? '' ) ? 'reset_form' : 'hide_form';
					$c['customPage']           = absint( $in['customPage'] ?? 0 ) ? (string) absint( $in['customPage'] ) : null;
					$c['customUrl']            = trim( is_scalar( $in['customUrl'] ?? '' ) ? (string) ( $in['customUrl'] ?? '' ) : '' );
					$c['customUrl']            = '' !== $c['customUrl'] ? $c['customUrl'] : null;
					if ( 'customPage' === $c['redirectTo'] && $c['customPage'] && ( ! get_post( (int) $c['customPage'] ) || ! current_user_can( 'read_post', (int) $c['customPage'] ) ) ) {
						return new WP_Error( 'minn_ffe_invalid', __( 'Choose a published page.', 'minn-admin' ), array( 'status' => 400, 'field' => 'confirmation.customPage' ) );
					}
					$settings['confirmation'] = $c;
					try {
						$service->saveGeneral( array(
							'form_id'                    => (int) $form->id,
							'formSettings'               => wp_json_encode( $settings ),
							'advancedValidationSettings' => wp_json_encode( minn_admin_fluent_meta( $form->id, 'advancedValidationSettings' ) ),
						) );
					} catch ( \Throwable $e ) {
						return minn_admin_fluent_validation_error( $e, 'confirmation.' );
					}
				}
				return rest_ensure_response( minn_admin_fluent_emails_payload( minn_admin_fluent_form( $form->id ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-forms/forms/(?P<id>\d+)/emails/preview', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
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
				$out = \FluentForm\App\Services\FormBuilder\ShortCodeParser::parse( $parts, (int) $entry->id, json_decode( (string) $entry->response, true ), $form, false, 'notifications' );
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
