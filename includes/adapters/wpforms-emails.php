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
 * the way their sender sets it up) over the form's latest entry, with only
 * the smart tags minn_admin_wpforms_preview_tags() names resolved.
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

/**
 * The smart tags a preview resolves from a real entry (Minn_Admin_Preview):
 * WPForms' own that read only the entry, the form, the site or the person
 * previewing. The user_* tags read the account that submitted the entry
 * (with none, the person previewing): its id and display name, which
 * WPForms' entry screen shows (class-entries-single.php), and its name
 * parts and address only for someone core would show them to, who may
 * list users (on a network, where a site lists only its members, for a
 * member of the site unless the caller is the network's owner). Its meta,
 * page_*, author_*, url_referer and query_var (the page and the request it
 * was sent from), user_ip (the sender's address) and every add-on's tag
 * stay placeholders.
 *
 * @param int $user_id The account the previewed entry was sent from (0: none or not known).
 * @return string[]
 */
function minn_admin_wpforms_preview_tags( $user_id = 0 ) {
	$tags    = array( 'user_id', 'user_display', 'field_id', 'field_html_id', 'field_value_id', 'all_fields', 'form_id', 'form_name', 'entry_id', 'entry_date', 'entry_type', 'entry_details_url', 'order_summary', 'admin_email', 'site_name', 'date', 'unique_value', 'url_login', 'url_logout', 'url_register', 'url_lost_password' );
	$user_id = (int) $user_id;
	if ( current_user_can( 'list_users' ) && ( ! is_multisite() || $user_id < 1 || Minn_Admin::network_owner() || is_user_member_of_blog( $user_id, get_current_blog_id() ) ) ) {
		array_push( $tags, 'user_full_name', 'user_first_name', 'user_last_name', 'user_email' );
	}
	return $tags;
}

/** The page's read. */
function minn_admin_wpforms_emails_payload( $data ) {
	$settings      = (array) ( $data['settings'] ?? array() );
	$notifications = array();
	foreach ( (array) ( $settings['notifications'] ?? array() ) as $key => $n ) {
		// WPForms only ever makes integer ids. Any other key came from a crafted
		// builder save (it builds the form from posted input names) and is not
		// served: the page puts keys into markup.
		if ( ! ctype_digit( (string) $key ) ) {
			continue;
		}
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
		if ( ! ctype_digit( (string) $key ) ) {
			continue;
		}
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
	$latest = wpforms_current_user_can( 'view_entries_form_single', (int) $data['id'] ) ? (int) Minn_Admin_Kernel_SQL::latest( 'wpforms_entries', 'entry_id', array( 'form_id' => (int) $data['id'] ), 'entry_id' ) : 0;
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

	minn_admin_endpoint( '/wpforms/forms/(?P<id>\d+)/emails', array(
		'methods'             => 'GET',
		'gate'                => $perm,
		'run'                 => function ( WP_REST_Request $request ) use ( $load ) {
			$data = $load( $request );
			return is_wp_error( $data ) ? $data : rest_ensure_response( minn_admin_wpforms_emails_payload( $data ) );
		},
	) );
	minn_admin_endpoint( '/wpforms/forms/(?P<id>\d+)/emails', array(
		'methods'             => 'POST',
		'gate'                => $perm,
		'run'                 => function ( WP_REST_Request $request ) use ( $load ) {
			$data = $load( $request );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$body = (array) $request->get_json_params();
			$was  = (array) ( $data['settings'] ?? array() );
			$s    = &$data['settings'];
			$text = function ( $a, $k ) {
				return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : '';
			};
			if ( array_key_exists( 'enabled', $body ) ) {
				$s['notification_enable'] = ! empty( $body['enabled'] ) ? '1' : '0';
			}
			foreach ( (array) ( $body['notifications'] ?? array() ) as $in ) {
				$key = $text( $in, 'key' );
				if ( ! ctype_digit( $key ) || ! isset( $s['notifications'][ $key ] ) ) {
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
				if ( ! ctype_digit( $key ) || ! isset( $s['confirmations'][ $key ] ) ) {
					return new WP_Error( 'minn_wpfe_unknown', __( 'That confirmation is not on this form.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				// The page sends a changed confirmation with only its changed
				// keys. Each key is written only when it differs from the stored
				// one, so a key left out or sent back as stored keeps its bytes:
				// rewriting all five on every save ran a name their builder had
				// stored as typed through sanitize_text_field ('Promo 50%DEAL'
				// became 'Promo 50AL') and turned a type the page reads as a
				// message into one.
				$was     = (array) $s['confirmations'][ $key ];
				$changed = function ( $field ) use ( $in, $was, $text ) {
					return array_key_exists( $field, $in ) && $text( $in, $field ) !== $text( $was, $field );
				};
				$type = $changed( 'type' ) ? $text( $in, 'type' ) : $text( $was, 'type' );
				if ( $changed( 'type' ) && ! in_array( $type, array( 'message', 'page', 'redirect' ), true ) ) {
					$type = 'message';
				}
				$page = absint( $changed( 'page' ) ? $text( $in, 'page' ) : $text( $was, 'page' ) );
				$url  = $changed( 'redirect' ) ? trim( $text( $in, 'redirect' ) ) : $text( $was, 'redirect' );
				// Where visitors go is checked when this save changes it.
				$aims = $changed( 'type' ) || $changed( 'page' ) || $changed( 'redirect' );
				if ( $aims && 'page' === $type && ( ! $page || ! get_post( $page ) || ! current_user_can( 'read_post', $page ) ) ) {
					return new WP_Error( 'minn_wpfe_invalid', __( 'Choose the page visitors go to.', 'minn-admin' ), array( 'status' => 400, 'field' => 'c' . $key . '.page' ) );
				}
				if ( $aims && 'redirect' === $type && ( '' === trim( $url ) || ! wp_http_validate_url( trim( $url ) ) ) ) {
					return new WP_Error( 'minn_wpfe_invalid', __( 'Enter a full address (https://…).', 'minn-admin' ), array( 'status' => 400, 'field' => 'c' . $key . '.redirect' ) );
				}
				$write = array(
					'name'     => sanitize_text_field( $text( $in, 'name' ) ),
					'type'     => $type,
					'message'  => $text( $in, 'message' ),
					'page'     => $page ? (string) $page : '',
					'redirect' => $url,
				);
				foreach ( $write as $field => $value ) {
					if ( $changed( $field ) ) {
						$s['confirmations'][ $key ][ $field ] = $value;
					}
				}
			}
			unset( $s );
			// The form is a post, which has no restore: a value edited with a
			// credential copy still masked in it would be stored where it was.
			$masked = Minn_Admin_Egress::refuse_masks( array( 'notifications' => $data['settings']['notifications'] ?? array(), 'confirmations' => $data['settings']['confirmations'] ?? array() ), array( 'notifications' => $was['notifications'] ?? array(), 'confirmations' => $was['confirmations'] ?? array() ) );
			if ( $masked ) {
				return $masked;
			}
			// Not a builder save (see the file comment). Their update unslashes
			// its input unless their form-data slashing is on.
			$payload = function_exists( 'wpforms_is_form_data_slashing_enabled' ) && wpforms_is_form_data_slashing_enabled() ? $data : wp_slash( $data );
			$saved   = wpforms()->obj( 'form' )->update( (int) $data['id'], $payload );
			if ( ! $saved ) {
				return new WP_Error( 'minn_wpfe_failed', __( 'WPForms did not save the form.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( minn_admin_wpforms_emails_payload( minn_admin_wpforms_form_data( (int) $data['id'] ) ) );
		},
	) );

	minn_admin_endpoint( '/wpforms/forms/(?P<id>\d+)/emails/preview', array(
		'methods'             => 'POST',
		'gate'                => $perm,
		'run'                 => function ( WP_REST_Request $request ) use ( $load ) {
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
			// Editing a form doesn't include reading its entries; WPForms'
			// own preview renders placeholder content for the same reason.
			$newest = ! wpforms_current_user_can( 'view_entries_form_single', (int) $data['id'] ) ? array() : Minn_Admin_Kernel_SQL::select( 'wpforms_entries', array( 'entry_id', 'fields', 'user_id' ), array( 'form_id' => (int) $data['id'] ), array( 'entry_id' => 'DESC' ), 1 );
			$entry  = $newest ? (object) $newest[0] : null;
			// {user_meta key="…"} reads any meta of the account that submitted
			// the entry the preview uses (a 2FA seed, a licence key), and an
			// add-on's tag does whatever the add-on does. Only the tags
			// minn_admin_wpforms_preview_tags() names resolve; any other is a
			// placeholder before WPForms reads the text. A real send is unchanged.
			$allow   = minn_admin_wpforms_preview_tags( $entry ? (int) $entry->user_id : 0 );
			$subject = Minn_Admin_Preview::restrict( $subject, 'wpforms', $allow );
			$message = Minn_Admin_Preview::restrict( $message, 'wpforms', $allow );
			if ( ! $entry || ! class_exists( '\WPForms\Emails\Notifications' ) ) {
				return rest_ensure_response( array( 'entry' => 0, 'subject' => $subject, 'html' => wpautop( $message ) ) );
			}
			$fields = (array) wpforms_decode( (string) $entry->fields );
			$n      = (array) ( $data['settings']['notifications'][ $key ] ?? array() );
			$emails = ( new \WPForms\Emails\Notifications() )->init( (string) ( $n['template'] ?? '' ) );
			// WPForms replaces each tag through the whole text, value included,
			// and its whole-text hooks run after every tag: a field value the
			// submitter typed can carry an add-on's tag there. Only WPForms' own
			// callbacks run on those hooks here.
			$render = function () use ( $emails, $data, $fields, $key, $entry, $subject, $message ) {
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
					return array( $subject, '' !== trim( $html ) ? $html : $body );
				}
				return array(
					wpforms_process_smart_tags( $subject, $data, $fields, (int) $entry->entry_id ),
					wpforms_process_smart_tags( $message, $data, $fields, (int) $entry->entry_id ),
				);
			};
			// Every hook that sees the message or a field value on its way into
			// it: an add-on's filter on any of them could answer a tag carried
			// in what the submitter typed.
			$hooks = array( 'wpforms_process_smart_tags', 'wpforms_smart_tag_process', 'wpforms_smarttags_process_value', 'wpforms_emails_notifications_message', 'wpforms_emails_notifications_processed_message', 'wpforms_emails_notifications_field_message_html', 'wpforms_html_field_value', 'wpforms_emails_templates_general_build_content' );
			$names = minn_admin_wpforms_preview_tags();
			if ( is_object( wpforms()->obj( 'smart_tags' ) ) ) {
				$names = array_merge( $names, array_keys( (array) wpforms()->obj( 'smart_tags' )->get_smart_tags() ) );
			}
			foreach ( array_unique( $names ) as $tag ) {
				$hooks[] = 'wpforms_smarttags_process_' . $tag . '_value';
			}
			list( $subject, $html ) = Minn_Admin_Preview::own_callbacks( $hooks, WPFORMS_PLUGIN_DIR, $render );
			return rest_ensure_response( array( 'entry' => (int) $entry->entry_id, 'subject' => wp_strip_all_tags( (string) $subject ), 'html' => (string) $html ) );
		},
	) );
} );
