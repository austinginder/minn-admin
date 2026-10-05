<?php
/**
 * Contact Form 7: a form's email and messages page.
 *
 * /cf7/form/{id} edits what CF7 calls Mail, Mail (2) (the optional second
 * email, usually an autoresponder) and Messages (what visitors read after
 * sending, or when something is wrong). The save is CF7's own
 * wpcf7_save_contact_form() with the complete mail, mail_2 and messages
 * properties (their save replaces each property whole, so a key left out
 * would be blanked), followed by their configuration validator, whose
 * findings (a sender on another domain, an invalid recipient…) come back on
 * the fields they are about and are stored for CF7's own editor too.
 *
 * The preview fills the mail with the form's latest Flamingo message: field
 * tags from its answers, special tags from what Flamingo kept about the
 * submission or the site. With no message yet, tags show as typed.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Mail keys their sanitizer keeps (wpcf7_sanitize_mail). */
function minn_admin_cf7_mail_keys() {
	return array( 'recipient', 'sender', 'subject', 'additional_headers', 'body', 'attachments', 'use_html', 'exclude_blank', 'active' );
}

/** A mail property normalized for the page. */
function minn_admin_cf7_mail_out( $mail ) {
	$mail = is_array( $mail ) ? $mail : array();
	$out  = array();
	foreach ( minn_admin_cf7_mail_keys() as $k ) {
		$out[ $k ] = in_array( $k, array( 'use_html', 'exclude_blank', 'active' ), true ) ? ! empty( $mail[ $k ] ) : (string) ( $mail[ $k ] ?? '' );
	}
	return $out;
}

/** CF7's special mail tags, for the picker. */
function minn_admin_cf7_special_tags() {
	$tags = array(
		'_site_title'         => __( 'Site title', 'minn-admin' ),
		'_site_url'           => __( 'Site address', 'minn-admin' ),
		'_site_admin_email'   => __( 'Site admin email', 'minn-admin' ),
		'_date'               => __( 'Date sent', 'minn-admin' ),
		'_time'               => __( 'Time sent', 'minn-admin' ),
		'_url'                => __( 'Page the form was on', 'minn-admin' ),
		'_post_title'         => __( 'Title of that page', 'minn-admin' ),
		'_remote_ip'          => __( 'Sender’s IP address', 'minn-admin' ),
		'_user_agent'         => __( 'Sender’s browser', 'minn-admin' ),
		'_serial_number'      => __( 'Message number', 'minn-admin' ),
		'_user_display_name'  => __( 'Signed-in user’s name', 'minn-admin' ),
		'_user_email'         => __( 'Signed-in user’s email', 'minn-admin' ),
	);
	$out = array();
	foreach ( $tags as $tag => $label ) {
		$out[] = array( 'tag' => '[' . $tag . ']', 'label' => $label );
	}
	return $out;
}

/** The form's latest Flamingo message, for the preview. */
function minn_admin_cf7_latest_message( $contact_form ) {
	// No channel name, no lookup: an empty channel would match every form's messages.
	if ( ! class_exists( 'Flamingo_Inbound_Message' ) || '' === (string) $contact_form->name() ) {
		return null;
	}
	// Flamingo shows stored messages only to people who can manage users;
	// editing a form (any Editor, in CF7) doesn't include reading them.
	if ( ! current_user_can( 'flamingo_edit_inbound_messages' ) ) {
		return null;
	}
	$found = Flamingo_Inbound_Message::find( array(
		'channel'        => $contact_form->name(),
		'posts_per_page' => 1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'post_status'    => 'publish',
	) );
	return $found ? $found[0] : null;
}

/** Fill a mail template's tags from a Flamingo message (and the site). */
function minn_admin_cf7_fill( $text, $message, $exclude_blank = false ) {
	$fields = $message && is_array( $message->fields ) ? $message->fields : array();
	$meta   = $message && is_array( $message->meta ) ? $message->meta : array();
	$site   = array(
		'_site_title'       => get_bloginfo( 'name' ),
		'_site_description' => get_bloginfo( 'description' ),
		'_site_url'         => home_url(),
		'_site_admin_email' => get_bloginfo( 'admin_email' ),
	);
	$blank  = array();
	$filled = preg_replace_callback( '/\[\s*(_?[a-zA-Z0-9_-]+)\s*\]/', function ( $m ) use ( $fields, $meta, $site, $message, &$blank ) {
		$name = $m[1];
		if ( ! $message && ! isset( $site[ $name ] ) ) {
			return $m[0];
		}
		$value = null;
		if ( array_key_exists( $name, $fields ) ) {
			$value = $fields[ $name ];
		} elseif ( isset( $site[ $name ] ) ) {
			$value = $site[ $name ];
		} elseif ( '_' === $name[0] && array_key_exists( substr( $name, 1 ), $meta ) ) {
			$value = $meta[ substr( $name, 1 ) ];
		}
		if ( null === $value ) {
			return $m[0];
		}
		$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		if ( '' === $value ) {
			$blank[] = $m[0];
		}
		return $value;
	}, (string) $text );
	if ( $exclude_blank && $blank ) {
		// Their "exclude lines with blank mail-tags": drop those lines.
		$lines = explode( "\n", (string) $text );
		$keep  = array();
		foreach ( $lines as $line ) {
			$drop = false;
			foreach ( $blank as $tag ) {
				if ( false !== strpos( $line, $tag ) ) {
					$drop = true;
					break;
				}
			}
			if ( ! $drop ) {
				$keep[] = $line;
			}
		}
		return minn_admin_cf7_fill( implode( "\n", $keep ), $message, false );
	}
	return $filled;
}

/** CF7's configuration findings, by section ("mail.sender" => [messages]). */
function minn_admin_cf7_config_errors( $contact_form, $save = false ) {
	if ( ! function_exists( 'wpcf7_validate_configuration' ) || ! wpcf7_validate_configuration() || ! class_exists( 'WPCF7_ConfigValidator' ) ) {
		return array();
	}
	$validator = new WPCF7_ConfigValidator( $contact_form );
	$validator->validate();
	if ( $save ) {
		$validator->save();
	}
	$out = array();
	foreach ( (array) $validator->collect_error_messages( array( 'decodes_html_entities' => true ) ) as $section => $errors ) {
		$out[ $section ] = array_values( array_filter( array_map( function ( $e ) {
			return trim( wp_strip_all_tags( (string) ( $e['message'] ?? '' ) ) );
		}, (array) $errors ) ) );
	}
	return $out;
}

/** The page's read. */
function minn_admin_cf7_mail_payload( $contact_form ) {
	$messages = array();
	$labels   = array();
	$stored   = (array) $contact_form->prop( 'messages' );
	foreach ( wpcf7_messages() as $key => $m ) {
		$messages[ $key ] = (string) ( $stored[ $key ] ?? $m['default'] );
		$labels[]         = array( $key, wp_strip_all_tags( html_entity_decode( (string) $m['description'], ENT_QUOTES ) ) );
	}
	$field_tags = array();
	foreach ( $contact_form->collect_mail_tags() as $name ) {
		$field_tags[] = array( 'tag' => '[' . $name . ']', 'label' => $name );
	}
	$latest = minn_admin_cf7_latest_message( $contact_form );
	return array(
		'id'            => (int) $contact_form->id(),
		'title'         => (string) $contact_form->title(),
		'adminUrl'      => admin_url( 'admin.php?page=wpcf7&post=' . (int) $contact_form->id() . '&action=edit' ),
		'mail'          => minn_admin_cf7_mail_out( $contact_form->prop( 'mail' ) ),
		'mail_2'        => minn_admin_cf7_mail_out( $contact_form->prop( 'mail_2' ) ),
		'messages'      => $messages,
		'messageLabels' => $labels,
		'mergeTags'     => array_values( array_filter( array(
			$field_tags ? array( 'label' => __( 'Form fields', 'minn-admin' ), 'tags' => $field_tags ) : null,
			array( 'label' => __( 'About the message and site', 'minn-admin' ), 'tags' => minn_admin_cf7_special_tags() ),
		) ) ),
		'latest'        => $latest ? (int) $latest->id() : 0,
		'hasFlamingo'   => class_exists( 'Flamingo_Inbound_Message' ),
		'configErrors'  => minn_admin_cf7_config_errors( $contact_form ),
		'canHtml'       => current_user_can( 'unfiltered_html' ),
	);
}

/** A mail property from the page, every key present (their save replaces it whole). */
function minn_admin_cf7_mail_in( $input, $current ) {
	$out = minn_admin_cf7_mail_out( $current );
	foreach ( minn_admin_cf7_mail_keys() as $k ) {
		if ( is_array( $input ) && array_key_exists( $k, $input ) ) {
			$out[ $k ] = in_array( $k, array( 'use_html', 'exclude_blank', 'active' ), true ) ? ! empty( $input[ $k ] ) : ( is_scalar( $input[ $k ] ) ? (string) $input[ $k ] : $out[ $k ] );
		}
	}
	return $out;
}

add_action( 'rest_api_init', function () {
	if ( ! defined( 'WPCF7_VERSION' ) || ! function_exists( 'wpcf7_save_contact_form' ) || ! function_exists( 'wpcf7_messages' ) ) {
		return;
	}
	$perm = function ( WP_REST_Request $request ) {
		return current_user_can( 'wpcf7_edit_contact_form', (int) Minn_Admin::path_param( $request ) );
	};
	$load = function ( WP_REST_Request $request ) {
		$form = wpcf7_contact_form( (int) Minn_Admin::path_param( $request ) );
		return $form ? $form : new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
	};

	register_rest_route( 'minn-admin/v1', '/cf7/forms/(?P<id>\d+)/mail', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
				$form = $load( $request );
				return is_wp_error( $form ) ? $form : rest_ensure_response( minn_admin_cf7_mail_payload( $form ) );
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
				$body     = (array) $request->get_json_params();
				$messages = (array) $form->prop( 'messages' );
				foreach ( wpcf7_messages() as $key => $m ) {
					if ( isset( $body['messages'][ $key ] ) && is_scalar( $body['messages'][ $key ] ) ) {
						$messages[ $key ] = (string) $body['messages'][ $key ];
					} elseif ( ! isset( $messages[ $key ] ) ) {
						$messages[ $key ] = (string) $m['default'];
					}
				}
				// What wpcf7_save_contact_form() does for these three, minus its
				// wpcf7_save_contact_form action: the panels listening there
				// (Brevo, AnalyticsWP) rebuild their settings from the editor's
				// $_POST, which a JSON save doesn't have, so firing it switched
				// them off. Their save() still fires wpcf7_after_save.
				$mail           = wpcf7_sanitize_mail( minn_admin_cf7_mail_in( $body['mail'] ?? array(), $form->prop( 'mail' ) ) );
				$mail['active'] = true;
				$form->set_properties( array(
					'mail'     => $mail,
					'mail_2'   => wpcf7_sanitize_mail( minn_admin_cf7_mail_in( $body['mail_2'] ?? array(), $form->prop( 'mail_2' ) ) ),
					'messages' => wpcf7_sanitize_messages( $messages ),
				) );
				if ( ! $form->save() ) {
					return new WP_Error( 'minn_cf7_failed', __( 'Contact Form 7 did not save the form.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$saved = wpcf7_contact_form( (int) $form->id() );
				// Their editor validates the configuration on every save.
				minn_admin_cf7_config_errors( $saved, true );
				return rest_ensure_response( minn_admin_cf7_mail_payload( wpcf7_contact_form( (int) $form->id() ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/cf7/forms/(?P<id>\d+)/mail/preview', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
			$form = $load( $request );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$body    = (array) $request->get_json_params();
			$which   = 'mail_2' === ( $body['which'] ?? '' ) ? 'mail_2' : 'mail';
			$mail    = minn_admin_cf7_mail_in( $body['mail'] ?? array(), $form->prop( $which ) );
			$message = minn_admin_cf7_latest_message( $form );
			$html    = minn_admin_cf7_fill( $mail['body'], $message, $mail['exclude_blank'] );
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$html = wpcf7_kses( $html, 'mail' );
			}
			return rest_ensure_response( array(
				'message' => $message ? (int) $message->id() : 0,
				'to'      => minn_admin_cf7_fill( $mail['recipient'], $message ),
				'from'    => minn_admin_cf7_fill( $mail['sender'], $message ),
				'subject' => minn_admin_cf7_fill( $mail['subject'], $message ),
				'html'    => $mail['use_html'] ? $html : '<pre style="white-space:pre-wrap;font:14px/1.5 -apple-system,BlinkMacSystemFont,sans-serif;margin:0">' . esc_html( $html ) . '</pre>',
			) );
		},
	) );
} );
