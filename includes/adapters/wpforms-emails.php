<?php
/**
 * WPForms: a form's notifications and confirmations page.
 *
 * /wpforms/form/{id} edits the form's email notifications (on or off for the
 * form, then each one's recipients, CC where the site enables it, subject,
 * sender, reply-to and message) and its confirmations (a message, a page or
 * a redirect). They live in the form's own settings, so the save is
 * WPForms' form update (wpforms()->obj( 'form' )->update(), what their
 * builder's save calls) with the form as stored and only these settings
 * changed. It is not a builder save, so it does not pass their 'save_form'
 * context: the builder-only save filters behind it expect the builder's raw
 * post (JSON strings). (Their file-upload and camera save filters re-encode
 * every saved form through stripslashes, so a backslash typed into these
 * settings is lost here exactly as it is in their builder.)
 *
 * Adding or removing notifications and confirmations and their conditional
 * logic stay in the WPForms builder (stored conditions are kept). The
 * preview runs their own notification email (Emails\Notifications, set up
 * the way their sender sets it up) over the form's latest entry.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** The form's data as stored, or null. */
function minn_admin_wpforms_form_data( $form_id ) {
	$data = wpforms()->obj( 'form' )->get( (int) $form_id, array( 'content_only' => true, 'cap' => 'edit_form_single' ) );
	if ( ! is_array( $data ) || ! $data ) {
		return null;
	}
	$data['id'] = (int) $form_id; // a form made from a template may not carry its id yet
	return $data;
}

/** Their smart tags for the picker: the form's fields, then the general tags. */
function minn_admin_wpforms_tags( $data ) {
	$fields = array();
	foreach ( (array) ( $data['fields'] ?? array() ) as $f ) {
		if ( is_array( $f ) && isset( $f['id'] ) && ! in_array( $f['type'] ?? '', array( 'divider', 'html', 'pagebreak', 'content', 'layout', 'captcha', 'entry-preview' ), true ) ) {
			$fields[] = array( 'tag' => '{field_id="' . (int) $f['id'] . '"}', 'label' => wp_strip_all_tags( (string) ( $f['label'] ?? 'Field ' . $f['id'] ) ) );
		}
	}
	$general = array();
	if ( is_object( wpforms()->obj( 'smart_tags' ) ) ) {
		foreach ( (array) wpforms()->obj( 'smart_tags' )->get_smart_tags() as $tag => $label ) {
			$general[] = array( 'tag' => '{' . $tag . '}', 'label' => wp_strip_all_tags( (string) $label ) );
		}
	}
	return array_values( array_filter( array(
		$fields ? array( 'label' => __( 'Form fields', 'minn-admin' ), 'tags' => $fields ) : null,
		$general ? array( 'label' => __( 'Other', 'minn-admin' ), 'tags' => $general ) : null,
	) ) );
}

/** The page's read. */
function minn_admin_wpforms_emails_payload( $data ) {
	$settings      = (array) ( $data['settings'] ?? array() );
	$notifications = array();
	foreach ( (array) ( $settings['notifications'] ?? array() ) as $key => $n ) {
		$n               = (array) $n;
		$notifications[] = array(
			'key'            => (string) $key,
			'name'           => (string) ( $n['notification_name'] ?? '' ),
			'email'          => (string) ( $n['email'] ?? '' ),
			'carboncopy'     => (string) ( $n['carboncopy'] ?? '' ),
			'subject'        => (string) ( $n['subject'] ?? '' ),
			'sender_name'    => (string) ( $n['sender_name'] ?? '' ),
			'sender_address' => (string) ( $n['sender_address'] ?? '' ),
			'replyto'        => (string) ( $n['replyto'] ?? '' ),
			'message'        => (string) ( $n['message'] ?? '' ),
			'hasLogic'       => ! empty( $n['conditional_logic'] ),
		);
	}
	$confirmations = array();
	foreach ( (array) ( $settings['confirmations'] ?? array() ) as $key => $c ) {
		$c               = (array) $c;
		$type            = in_array( $c['type'] ?? '', array( 'message', 'page', 'redirect' ), true ) ? $c['type'] : 'message';
		$confirmations[] = array(
			'key'      => (string) $key,
			'name'     => (string) ( $c['name'] ?? '' ),
			'type'     => $type,
			'message'  => (string) ( $c['message'] ?? '' ),
			'page'     => ! empty( $c['page'] ) ? (string) absint( $c['page'] ) : '',
			'redirect' => (string) ( $c['redirect'] ?? '' ),
			'hasLogic' => ! empty( $c['conditional_logic'] ),
		);
	}
	$pages = array();
	foreach ( get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title', 'number' => 500 ) ) as $p ) {
		$pages[] = array( (string) $p->ID, get_the_title( $p ) ? get_the_title( $p ) : '#' . $p->ID );
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$latest = wpforms_current_user_can( 'view_entries_form_single', (int) $data['id'] ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(entry_id) FROM {$wpdb->prefix}wpforms_entries WHERE form_id = %d", (int) $data['id'] ) ) : 0;
	return array(
		'id'             => (int) $data['id'],
		'title'          => (string) ( $settings['form_title'] ?? get_the_title( (int) $data['id'] ) ),
		'adminUrl'       => admin_url( 'admin.php?page=wpforms-builder&view=settings&form_id=' . (int) $data['id'] ),
		'enabled'        => ! isset( $settings['notification_enable'] ) || '1' === (string) $settings['notification_enable'],
		'ccEnabled'      => (bool) wpforms_setting( 'email-carbon-copy' ),
		'defaultSubject' => sprintf( /* translators: %s: form title. */ esc_html__( 'New %s Entry', 'minn-admin' ), (string) ( $settings['form_title'] ?? '' ) ),
		'notifications'  => $notifications,
		'confirmations'  => $confirmations,
		'pages'          => $pages,
		'mergeTags'      => minn_admin_wpforms_tags( $data ),
		'latest'         => $latest,
		'canHtml'        => current_user_can( 'unfiltered_html' ),
	);
}

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'minn_admin_wpforms_active' ) || ! minn_admin_wpforms_active() || ! is_object( wpforms()->obj( 'form' ) ) ) {
		return;
	}
	$perm = function ( WP_REST_Request $request ) {
		return wpforms_current_user_can( 'edit_form_single', (int) Minn_Admin::path_param( $request ) );
	};
	$load = function ( WP_REST_Request $request ) {
		$data = minn_admin_wpforms_form_data( (int) Minn_Admin::path_param( $request ) );
		return $data ? $data : new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
	};

	register_rest_route( 'minn-admin/v1', '/wpforms/forms/(?P<id>\d+)/emails', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
				$data = $load( $request );
				return is_wp_error( $data ) ? $data : rest_ensure_response( minn_admin_wpforms_emails_payload( $data ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
				$data = $load( $request );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$body = (array) $request->get_json_params();
				$s    = &$data['settings'];
				$text = function ( $a, $k ) {
					return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : '';
				};
				if ( array_key_exists( 'enabled', $body ) ) {
					$s['notification_enable'] = ! empty( $body['enabled'] ) ? '1' : '0';
				}
				foreach ( (array) ( $body['notifications'] ?? array() ) as $in ) {
					$key = $text( $in, 'key' );
					if ( ! isset( $s['notifications'][ $key ] ) ) {
						return new WP_Error( 'minn_wpfe_unknown', __( 'That notification is not on this form.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					foreach ( array( 'notification_name' => 'name', 'email' => 'email', 'carboncopy' => 'carboncopy', 'subject' => 'subject', 'sender_name' => 'sender_name', 'sender_address' => 'sender_address', 'replyto' => 'replyto', 'message' => 'message' ) as $stored => $field ) {
						if ( array_key_exists( $field, $in ) ) {
							$s['notifications'][ $key ][ $stored ] = $text( $in, $field );
						}
					}
					$addr = trim( $s['notifications'][ $key ]['sender_address'] ?? '' );
					if ( '' !== $addr && false === strpos( $addr, '{' ) && ! is_email( $addr ) ) {
						return new WP_Error( 'minn_wpfe_invalid', __( 'Enter a valid email address or a smart tag.', 'minn-admin' ), array( 'status' => 400, 'field' => 'n' . $key . '.sender_address' ) );
					}
				}
				foreach ( (array) ( $body['confirmations'] ?? array() ) as $in ) {
					$key = $text( $in, 'key' );
					if ( ! isset( $s['confirmations'][ $key ] ) ) {
						return new WP_Error( 'minn_wpfe_unknown', __( 'That confirmation is not on this form.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					// Keys the page leaves out keep their stored values.
					$was = $s['confirmations'][ $key ];
					$in  = array_merge(
						array(
							'name'     => $was['name'] ?? '',
							'type'     => $was['type'] ?? 'message',
							'message'  => $was['message'] ?? '',
							'page'     => $was['page'] ?? '',
							'redirect' => $was['redirect'] ?? '',
						),
						$in
					);
					$type = in_array( $text( $in, 'type' ), array( 'message', 'page', 'redirect' ), true ) ? $text( $in, 'type' ) : 'message';
					$page = absint( $in['page'] ?? 0 );
					$url  = trim( $text( $in, 'redirect' ) );
					if ( 'page' === $type && ( ! $page || ! get_post( $page ) || ! current_user_can( 'read_post', $page ) ) ) {
						return new WP_Error( 'minn_wpfe_invalid', __( 'Choose the page visitors go to.', 'minn-admin' ), array( 'status' => 400, 'field' => 'c' . $key . '.page' ) );
					}
					if ( 'redirect' === $type && ( '' === $url || ! wp_http_validate_url( $url ) ) ) {
						return new WP_Error( 'minn_wpfe_invalid', __( 'Enter a full address (https://…).', 'minn-admin' ), array( 'status' => 400, 'field' => 'c' . $key . '.redirect' ) );
					}
					$s['confirmations'][ $key ]['name']     = sanitize_text_field( $text( $in, 'name' ) );
					$s['confirmations'][ $key ]['type']     = $type;
					$s['confirmations'][ $key ]['message']  = $text( $in, 'message' );
					$s['confirmations'][ $key ]['page']     = $page ? (string) $page : '';
					$s['confirmations'][ $key ]['redirect'] = $url;
				}
				unset( $s );
				// Not a builder save (see the file comment). Their update unslashes
				// its input unless their form-data slashing is on.
				$payload = function_exists( 'wpforms_is_form_data_slashing_enabled' ) && wpforms_is_form_data_slashing_enabled() ? $data : wp_slash( $data );
				$saved   = wpforms()->obj( 'form' )->update( (int) $data['id'], $payload );
				if ( ! $saved ) {
					return new WP_Error( 'minn_wpfe_failed', __( 'WPForms did not save the form.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( minn_admin_wpforms_emails_payload( minn_admin_wpforms_form_data( (int) $data['id'] ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/wpforms/forms/(?P<id>\d+)/emails/preview', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $load ) {
			$data = $load( $request );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$body    = (array) $request->get_json_params();
			$key     = isset( $body['key'] ) && is_scalar( $body['key'] ) ? (string) $body['key'] : '';
			$subject = isset( $body['subject'] ) && is_scalar( $body['subject'] ) ? (string) $body['subject'] : '';
			$message = isset( $body['message'] ) && is_scalar( $body['message'] ) ? (string) $body['message'] : '';
			$subject = '' !== trim( $subject ) ? $subject : sprintf( /* translators: %s: form title. */ __( 'New %s Entry', 'minn-admin' ), (string) ( $data['settings']['form_title'] ?? '' ) );
			$message = '' !== trim( $message ) ? $message : '{all_fields}';
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$message = wp_strip_all_tags( $message );
			}
			global $wpdb;
			// Editing a form doesn't include reading its entries; WPForms'
			// own preview renders placeholder content for the same reason.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$entry = ! wpforms_current_user_can( 'view_entries_form_single', (int) $data['id'] ) ? null : $wpdb->get_row( $wpdb->prepare( "SELECT entry_id, fields FROM {$wpdb->prefix}wpforms_entries WHERE form_id = %d ORDER BY entry_id DESC LIMIT 1", (int) $data['id'] ) );
			if ( ! $entry || ! class_exists( '\WPForms\Emails\Notifications' ) ) {
				return rest_ensure_response( array( 'entry' => 0, 'subject' => $subject, 'html' => wpautop( $message ) ) );
			}
			$fields = (array) wpforms_decode( (string) $entry->fields );
			$n      = (array) ( $data['settings']['notifications'][ $key ] ?? array() );
			$emails = ( new \WPForms\Emails\Notifications() )->init( (string) ( $n['template'] ?? '' ) );
			if ( $emails instanceof \WPForms\Emails\Notifications ) {
				// Their sender's setup, then their own processing: the email
				// template (which {all_fields} renders through) around the message.
				$emails->__set( 'form_data', $data );
				$emails->__set( 'fields', $fields );
				$emails->__set( 'notification_id', $key );
				$emails->__set( 'entry_id', (int) $entry->entry_id );
				$emails->__set( 'message', '' );
				$emails->process_email_template( $message );
				list( $subject, $body ) = ( function ( $s, $m ) {
					return array( $this->process_subject( $s ), $this->process_message( $m ) );
				} )->call( $emails, $subject, $message );
				$html = (string) $emails->__get( 'message' );
				$html = '' !== trim( $html ) ? $html : $body;
			} else {
				$subject = wpforms_process_smart_tags( $subject, $data, $fields, (int) $entry->entry_id );
				$html    = wpforms_process_smart_tags( $message, $data, $fields, (int) $entry->entry_id );
			}
			return rest_ensure_response( array( 'entry' => (int) $entry->entry_id, 'subject' => wp_strip_all_tags( (string) $subject ), 'html' => (string) $html ) );
		},
	) );
} );
