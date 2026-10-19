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
 * submission or the site. Only the tags minn_admin_cf7_preview_tags() names
 * are filled; any other shows as a placeholder. With no message yet, those
 * tags show as typed.
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

/**
 * The mail-tags a preview fills from a Flamingo message
 * (Minn_Admin_Preview): the form's own fields, and the special tags that
 * read the submission (its date, time, number and payment) or the site.
 * The sender's address and browser, the page the form was sent from and
 * its author, the signed-in sender's account, anything else the message
 * kept (a Turnstile answer, an add-on's value) and every add-on's tag stay
 * placeholders. The fill reads any key Flamingo kept, so the list is what
 * stands between a tag and that key.
 *
 * @param WPCF7_ContactForm $contact_form The form.
 * @return string[]
 */
function minn_admin_cf7_preview_tags( $contact_form ) {
	$tags = array( '_site_title', '_site_description', '_site_url', '_site_domain', '_site_admin_email', '_date', '_time', '_serial_number', '_invalid_fields', '_contact_form_title', '_stripe_payment_link' );
	foreach ( $contact_form->collect_mail_tags() as $name ) {
		$tags[] = (string) $name;
		$tags[] = '_raw_' . $name;
		$tags[] = '_format_' . $name;
	}
	return $tags;
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
	// Names start as CF7's do (a letter, or "_" for a special tag), so the
	// preview's tag pattern (Minn_Admin_Preview::CF7_TAG) sees every tag
	// this fills.
	$filled = preg_replace_callback( '/\[\s*([a-zA-Z_][a-zA-Z0-9_-]*)\s*\]/', function ( $m ) use ( $fields, $meta, $site, $message, &$blank ) {
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

/**
 * The mail property a save writes, or null when the page changed nothing in
 * it. Only a key the page sent with a value other than the stored one goes
 * through their sanitizer; every other key keeps its stored value byte for
 * byte. wpcf7_sanitize_mail() runs kses over the body for anyone without
 * unfiltered_html, so cleaning a stored body nobody edited again stripped an
 * HTML message's <style> and <head> (leaving its CSS as text) and a
 * "<[your-email]>" line. Their REST update and wpcf7_save_contact_form()
 * likewise touch only the properties sent.
 *
 * @param mixed $input   The page's keys for this property.
 * @param mixed $current The stored property.
 * @return array|WP_Error|null The property, a refusal, or null.
 */
function minn_admin_cf7_mail_merge( $input, $current ) {
	if ( ! is_array( $input ) ) {
		return null;
	}
	$current = is_array( $current ) ? $current : array();
	$stored  = minn_admin_cf7_mail_out( $current );
	$merged  = minn_admin_cf7_mail_in( $input, $current );
	$changed = array();
	$edited  = array();
	foreach ( minn_admin_cf7_mail_keys() as $k ) {
		// A key sent back as the page loaded it, a credential copy masked
		// (every response masks one), is unchanged too.
		if ( $merged[ $k ] !== $stored[ $k ] && ( ! is_string( $merged[ $k ] ) || ! Minn_Admin_Egress::served_as( $merged[ $k ], $stored[ $k ] ) ) ) {
			$changed[]    = $k;
			$edited[ $k ] = $merged[ $k ];
		}
	}
	if ( ! $changed ) {
		return null;
	}
	// The form keeps its mail in post meta, which has no restore: an edited
	// body still carrying a mask would be stored where the credential was.
	$masked = Minn_Admin_Egress::refuse_masks( $edited, $stored );
	if ( $masked ) {
		return $masked;
	}
	$clean = wpcf7_sanitize_mail( $merged );
	$out   = $current + $clean;
	foreach ( $changed as $k ) {
		$out[ $k ] = $clean[ $k ];
	}
	return $out;
}

/*
 * Contact Form 7 keeps every integration's secret in its one settings row
 * (the Stripe secret key, the reCAPTCHA and Turnstile secrets, the Brevo key,
 * the Constant Contact client secret and OAuth tokens), which its Integration
 * screen prints only masked. The captcha secrets are keyed by the public site
 * key, so no name along their path says secret: they are named here. Declared
 * whether or not CF7 is active (its row outlives it).
 */
minn_admin_secrets(
	'contact-form-7',
	array(
		'options' => array( 'wpcf7' => array( 'recaptcha/*', 'turnstile/*' ) ),
	)
);

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

	minn_admin_endpoint( '/cf7/forms/(?P<id>\d+)/mail', array(
		'methods'             => 'GET',
		'gate'                => $perm,
		'run'                 => function ( WP_REST_Request $request ) use ( $load ) {
			$form = $load( $request );
			return is_wp_error( $form ) ? $form : rest_ensure_response( minn_admin_cf7_mail_payload( $form ) );
		},
	) );
	minn_admin_endpoint( '/cf7/forms/(?P<id>\d+)/mail', array(
		'methods'             => 'POST',
		'gate'                => $perm,
		'run'                 => function ( WP_REST_Request $request ) use ( $load ) {
			$form = $load( $request );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$body  = (array) $request->get_json_params();
			$props = array();
			// What wpcf7_save_contact_form() does for these three, minus its
			// wpcf7_save_contact_form action: the panels listening there
			// (Brevo, AnalyticsWP) rebuild their settings from the editor's
			// $_POST, which a JSON save doesn't have, so firing it switched
			// them off. Their save() still fires wpcf7_after_save. As there,
			// a property the request does not carry is left as stored, and
			// within one only what changed is cleaned (minn_admin_cf7_mail_merge()).
			$mail = minn_admin_cf7_mail_merge( $body['mail'] ?? null, $form->prop( 'mail' ) );
			if ( is_wp_error( $mail ) ) {
				return $mail;
			}
			if ( null !== $mail ) {
				$mail['active'] = true;
				$props['mail']  = $mail;
			}
			$mail_2 = minn_admin_cf7_mail_merge( $body['mail_2'] ?? null, $form->prop( 'mail_2' ) );
			if ( is_wp_error( $mail_2 ) ) {
				return $mail_2;
			}
			if ( null !== $mail_2 ) {
				$props['mail_2'] = $mail_2;
			}
			if ( isset( $body['messages'] ) && is_array( $body['messages'] ) ) {
				$stored   = (array) $form->prop( 'messages' );
				$messages = $stored;
				foreach ( wpcf7_messages() as $key => $m ) {
					if ( ! isset( $body['messages'][ $key ] ) || ! is_scalar( $body['messages'][ $key ] ) ) {
						continue;
					}
					// The page shows the default for a message never stored.
					$sent = (string) $body['messages'][ $key ];
					$was  = (string) ( $stored[ $key ] ?? $m['default'] );
					if ( $sent !== $was && ! Minn_Admin_Egress::served_as( $sent, $was ) ) {
						$masked = Minn_Admin_Egress::refuse_masks( array( $key => $sent ), array( $key => $was ) );
						if ( $masked ) {
							return $masked;
						}
						$messages[ $key ] = trim( $sent ); // wpcf7_sanitize_messages()
					}
				}
				if ( $messages !== $stored ) {
					$props['messages'] = $messages;
				}
			}
			if ( ! $props ) {
				return rest_ensure_response( minn_admin_cf7_mail_payload( $form ) );
			}
			$form->set_properties( $props );
			if ( ! $form->save() ) {
				return new WP_Error( 'minn_cf7_failed', __( 'Contact Form 7 did not save the form.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$saved = wpcf7_contact_form( (int) $form->id() );
			// Their editor validates the configuration on every save.
			minn_admin_cf7_config_errors( $saved, true );
			return rest_ensure_response( minn_admin_cf7_mail_payload( wpcf7_contact_form( (int) $form->id() ) ) );
		},
	) );

	minn_admin_endpoint( '/cf7/forms/(?P<id>\d+)/mail/preview', array(
		'methods'             => 'POST',
		'gate'                => $perm,
		'run'                 => function ( WP_REST_Request $request ) use ( $load ) {
			$form = $load( $request );
			if ( is_wp_error( $form ) ) {
				return $form;
			}
			$body    = (array) $request->get_json_params();
			$which   = 'mail_2' === ( $body['which'] ?? '' ) ? 'mail_2' : 'mail';
			$mail    = minn_admin_cf7_mail_in( $body['mail'] ?? array(), $form->prop( $which ) );
			// [_user_email] reads the signed-in sender's account, [_post_*] the
			// page the form was sent from, and the fill reads any key Flamingo
			// kept. Only the tags minn_admin_cf7_preview_tags() names are
			// filled; any other is a placeholder first.
			$allow = minn_admin_cf7_preview_tags( $form );
			foreach ( array( 'recipient', 'sender', 'subject', 'body' ) as $k ) {
				$mail[ $k ] = Minn_Admin_Preview::restrict( $mail[ $k ], 'cf7', $allow );
			}
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
