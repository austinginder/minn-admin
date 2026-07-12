<?php
/**
 * Bundled adapter: Gravity Forms.
 *
 * Pure descriptor — Gravity Forms ships its own REST API (gf/v2) with cookie
 * auth, so no shim is needed. Entries are listed per form (tabs), with a
 * detail view that resolves field labels from the form schema, and a Trash
 * action.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return $surfaces;
	}

	// Gravity Forms only registers its gf/v2 routes when the REST API is
	// enabled (Forms → Settings → REST API), so hide the surface until then.
	$webapi = get_option( 'gravityformsaddon_gravityformswebapi_settings' );
	if ( empty( $webapi['enabled'] ) ) {
		return $surfaces;
	}

	// GF admins usually carry gform_full_access rather than the granular caps,
	// and only GF's own resolver maps between them — gate here, not via 'cap'.
	if ( ! GFCommon::current_user_can_any( array( 'gravityforms_view_entries', 'gform_full_access' ) ) ) {
		return $surfaces;
	}

	// Notifications are form-admin config (recipient addresses live in them),
	// so the whole view is gated on GF's edit-forms capability — evaluated
	// through GF's own resolver here at build time, because a view-level
	// `cap` runs plain current_user_can and admins hold gform_full_access,
	// not the granular caps.
	$can_edit_forms = GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );

	$surfaces['gravity-forms'] = array(
		'label'      => 'Forms',
		// Shared with Fluent Forms / Elementor / WPForms adapters when present;
		// topbar becomes a provider switcher when family size > 1.
		'family'     => 'forms',
		// Entries are incoming human messages — inbox-shaped, so this family
		// claims the Workspace nav group (everything else defaults to Tools).
		'group'      => 'workspace',
		'sub'        => 'Gravity Forms',
		'icon'       => 'inbox',
		'cap'        => 'read',
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'gf/v2/forms/{tab}/entries',
			'allRoute'  => 'gf/v2/entries',
			'query'     => 'sorting[key]=date_created&sorting[direction]=DESC',
			'pageQuery' => 'paging[page_size]=25&paging[current_page]={page}',
			// gf/v2 takes search criteria as a JSON string; key 0 = any field.
			'search'    => array(
				'param' => 'search',
				'json'  => array( 'field_filters' => array( array( 'key' => 0, 'value' => '{q}', 'operator' => 'contains' ) ) ),
			),
			'itemsKey'  => 'entries',
			'totalKey'  => 'total_count',
			// Second list dimension beside the form tabs. gf/v2 takes status
			// inside the same JSON `search` criteria the search box uses, so
			// the json form merges with it instead of clobbering the param.
			'filter'    => array(
				'label'   => 'Status',
				'options' => array(
					array( 'active', 'Received' ),
					array( 'spam', 'Spam' ),
					array( 'trash', 'Trash' ),
				),
				'param'   => 'search',
				'json'    => array( 'status' => '{v}' ),
			),
			'tabs'      => array(
				'route'    => 'gf/v2/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => '_summary', 'label' => 'Entry', 'format' => 'entry-summary' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				// GF stores entry dates in UTC (MySQL, no zone).
				array( 'key' => 'date_created', 'label' => 'Date', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				// The shim returns the whole display model: answers with the
				// form's real field labels (in form order), then the
				// submission details — no client-side label mapping.
				'sectionsRoute' => 'minn-admin/v1/gf/entries/{id}',
			),
			// Entry workflow rides GF's own gf/v2/entries/{id}/properties PUT
			// (is_starred / is_read / status), gated by GF at
			// gravityforms_edit_entries. The list shows active entries only
			// (gf/v2's default), so restore-from-spam/trash stays in wp-admin
			// until Minn grows a status filter dimension.
			'actions'   => array(
				array(
					'label'  => 'Star',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'is_starred' => 1 ),
					'when'   => array( 'key' => 'is_starred', 'equals' => '0' ),
				),
				array(
					'label'  => 'Unstar',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'is_starred' => 0 ),
					'when'   => array( 'key' => 'is_starred', 'equals' => '1' ),
				),
				array(
					'label'   => 'Resend notifications',
					'method'  => 'POST',
					'route'   => 'gf/v2/entries/{id}/notifications',
					'confirm' => 'Resend this entry’s notifications (all active ones for its form)?',
				),
				array(
					'label'  => 'Add note',
					'method' => 'POST',
					// Shimmed: gf/v2's notes POST creates the note but then 500s
					// preparing its own response (prepare_note_for_response returns
					// a WP_Error their controller set_status()es — their admin UI
					// never exercises this route). GFAPI::add_note is reliable.
					'route'  => 'minn-admin/v1/gf/entries/{id}/notes',
					'fields' => array(
						array( 'key' => 'value', 'label' => 'Note', 'type' => 'textarea', 'rows' => 3, 'placeholder' => 'Visible on the entry here and in Gravity Forms.' ),
					),
				),
				array(
					'label'   => 'Mark as spam',
					'method'  => 'PUT',
					'route'   => 'gf/v2/entries/{id}/properties',
					'body'    => array( 'status' => 'spam' ),
					'confirm' => 'Mark this entry as spam? Find it under the Spam filter.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'active' ),
				),
				array(
					'label'  => 'Not spam',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'status' => 'active' ),
					'when'   => array( 'key' => 'status', 'equals' => 'spam' ),
				),
				array(
					'label'  => 'Restore',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'status' => 'active' ),
					'when'   => array( 'key' => 'status', 'equals' => 'trash' ),
				),
				array(
					'label'   => 'Trash entry',
					'method'  => 'DELETE',
					'route'   => 'gf/v2/entries/{id}',
					'confirm' => 'Move this entry to trash?',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'active' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'gf/v2/entries/{id}?force=1',
					'confirm' => 'Delete this entry permanently? There is no undo.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trash' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'gf/v2/entries/{id}?force=1',
					'confirm' => 'Delete this entry permanently? There is no undo.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'spam' ),
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Star',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'is_starred' => 1 ),
					'when'   => array( 'key' => 'is_starred', 'equals' => '0' ),
				),
				array(
					'label'  => 'Mark read',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'is_read' => 1 ),
					'when'   => array( 'key' => 'is_read', 'equals' => '0' ),
				),
				array(
					'label'   => 'Spam',
					'method'  => 'PUT',
					'route'   => 'gf/v2/entries/{id}/properties',
					'body'    => array( 'status' => 'spam' ),
					'confirm' => 'Mark the selected entries as spam?',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'active' ),
				),
				array(
					'label'  => 'Not spam',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'status' => 'active' ),
					'when'   => array( 'key' => 'status', 'equals' => 'spam' ),
				),
				array(
					'label'  => 'Restore',
					'method' => 'PUT',
					'route'  => 'gf/v2/entries/{id}/properties',
					'body'   => array( 'status' => 'active' ),
					'when'   => array( 'key' => 'status', 'equals' => 'trash' ),
				),
				array(
					'label'   => 'Trash',
					'method'  => 'DELETE',
					'route'   => 'gf/v2/entries/{id}',
					'confirm' => 'Move the selected entries to trash?',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'active' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'gf/v2/entries/{id}?force=1',
					'confirm' => 'Delete the selected entries permanently? There is no undo.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trash' ),
				),
			),
		),
		// The Manage view: the forms themselves. Deliberately NOT a form
		// builder — GF's editor (field types, conditional logic, feeds) is one
		// click away; Minn covers the daily moves: see, toggle, jump.
		'manage'     => array(
			'viewLabel' => 'Forms',
			'route'     => 'minn-admin/v1/gf/forms',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Form', 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => 'Entries', 'format' => 'num' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date_created', 'label' => 'Created', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/gf/forms/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'status', 'equals' => 'active' ),
				),
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/gf/forms/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'status', 'equals' => 'inactive' ),
				),
				array(
					'label' => 'Edit in Gravity Forms ↗',
					'href'  => admin_url( 'admin.php?page=gf_edit_forms&id={id}' ),
				),
			),
		),
	);

	// The Notifications view: every notification across forms (or per form
	// via the tabs), with activate/deactivate and the daily edits (name,
	// send-to address, subject, message) through GF's own storage. NOT a
	// notification builder — routing rules, conditional logic and events
	// are GF's editor, one click away.
	if ( $can_edit_forms ) {
		// Per-form settings: the {id} in the route makes the settings view
		// ITEM-scoped — entered from a form row's "Form settings" action,
		// never from the view switcher. The schema is read from GF's own
		// Settings framework at request time (the mapper below).
		$surfaces['gravity-forms']['settings'] = array(
			'label' => 'Form settings',
			'tabs'  => array( array( 'id' => 'form', 'label' => 'Form settings' ) ),
			'route' => 'minn-admin/v1/gf/forms/{id}/settings/{tab}',
		);
		array_unshift(
			$surfaces['gravity-forms']['manage']['actions'],
			array( 'label' => 'Form settings', 'settingsItem' => true )
		);
		$surfaces['gravity-forms']['views'] = array(
			array(
				'viewLabel' => 'Notifications',
				'route'     => 'minn-admin/v1/gf/forms/{tab}/notifications',
				'allRoute'  => 'minn-admin/v1/gf/notifications',
				'pageQuery' => 'per_page=25&page={page}',
				'itemsKey'  => 'items',
				'totalKey'  => 'total',
				'tabs'      => array(
					'route'    => 'gf/v2/forms',
					'valueKey' => 'id',
					'labelKey' => 'title',
					'allLabel' => 'All notifications',
				),
				'columns'   => array(
					array( 'key' => 'name', 'label' => 'Notification', 'format' => 'title' ),
					array( 'key' => 'form', 'label' => 'Form' ),
					array( 'key' => 'event', 'label' => 'Event' ),
					array( 'key' => 'to', 'label' => 'To' ),
					array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				),
				'detail'    => array(
					'skip' => array( 'form_id', 'nid' ),
					'edit' => array(
						'route'  => 'minn-admin/v1/gf/notifications/{id}',
						'fields' => array(
							array( 'key' => 'name', 'label' => 'Name' ),
							array( 'key' => 'to_email', 'label' => 'Send to', 'required' => false, 'placeholder' => 'address@example.com, {admin_email} — email-type notifications only' ),
							array( 'key' => 'subject', 'label' => 'Subject' ),
							array( 'key' => 'message', 'label' => 'Message', 'type' => 'textarea', 'rows' => 8 ),
						),
					),
				),
				'actions'   => array(
					array(
						'label'  => 'Deactivate',
						'method' => 'POST',
						'route'  => 'minn-admin/v1/gf/notifications/{id}/active',
						'body'   => array( 'active' => false ),
						'when'   => array( 'key' => 'status', 'equals' => 'active' ),
					),
					array(
						'label'  => 'Activate',
						'method' => 'POST',
						'route'  => 'minn-admin/v1/gf/notifications/{id}/active',
						'body'   => array( 'active' => true ),
						'when'   => array( 'key' => 'status', 'equals' => 'inactive' ),
					),
					array(
						'label' => 'Edit in Gravity Forms ↗',
						'href'  => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=notification&id={form_id}&nid={nid}' ),
					),
				),
			),
		);
	}

	return $surfaces;
} );

/**
 * Shim endpoints. GF's own gf/v2 API covers entry listing, but the entry
 * DETAIL needs the form schema to be readable (labels, choice text, composite
 * fields), and the forms list needs is_active + entry counts that gf/v2/forms
 * doesn't expose — both are one GFAPI call server-side.
 */
add_action( 'rest_api_init', function () {
	if ( ! class_exists( 'GFAPI' ) ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/gf/entries/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return GFCommon::current_user_can_any( array( 'gravityforms_view_entries', 'gform_full_access' ) );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$entry = GFAPI::get_entry( (int) $request['id'] );
			if ( is_wp_error( $entry ) ) {
				return new WP_Error( 'not_found', 'Entry not found.', array( 'status' => 404 ) );
			}
			$form    = GFAPI::get_form( $entry['form_id'] );
			$answers = array();
			foreach ( $form['fields'] as $field ) {
				if ( in_array( $field->type, array( 'html', 'section', 'page', 'captcha' ), true ) ) {
					continue;
				}
				// use_text=true resolves choice values to their labels.
				$value = $field->get_value_export( $entry, (string) $field->id, true );
				if ( '' === trim( (string) $value ) ) {
					continue;
				}
				$answers[] = array(
					'label' => wp_strip_all_tags( GFCommon::get_label( $field ) ),
					'value' => $value,
					'type'  => in_array( $field->type, array( 'website', 'fileupload' ), true ) ? 'url' : $field->type,
				);
			}

			$meta   = array();
			$meta[] = array(
				'label' => 'Submitted',
				'value' => date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( $entry['date_created'] ) ) ),
			);
			if ( ! empty( $entry['source_url'] ) ) {
				$meta[] = array( 'label' => 'Source', 'value' => $entry['source_url'], 'type' => 'url' );
			}
			if ( ! empty( $entry['ip'] ) ) {
				$meta[] = array( 'label' => 'IP', 'value' => $entry['ip'] );
			}
			if ( ! empty( $entry['created_by'] ) ) {
				$user   = get_userdata( (int) $entry['created_by'] );
				$meta[] = array( 'label' => 'User', 'value' => $user ? $user->display_name : '#' . $entry['created_by'] );
			}
			if ( ! empty( $entry['payment_status'] ) ) {
				$meta[] = array( 'label' => 'Payment', 'value' => trim( $entry['payment_status'] . ' ' . rgar( $entry, 'payment_amount' ) ) );
			}

			// Notes (admin + notification logs). Notification notes store raw
			// HTML (<div> wrappers, a "View Email" anchor) that wp-admin
			// renders; Minn escapes detail values, so serve display-ready
			// text and surface the first link as its own url row.
			$note_rows = array();
			if ( class_exists( 'GFFormsModel' ) && method_exists( 'GFFormsModel', 'get_lead_notes' ) ) {
				foreach ( (array) GFFormsModel::get_lead_notes( $entry['id'] ) as $note ) {
					$raw  = (string) $note->value;
					$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
					$note_rows[] = array(
						'label' => trim( ( isset( $note->user_name ) ? $note->user_name : '' ) . ' · ' . date_i18n( 'M j, g:i a', strtotime( get_date_from_gmt( $note->date_created ) ) ), ' ·' ),
						'value' => $text,
					);
					if ( preg_match( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>([^<]*)</i', $raw, $m ) && wp_http_validate_url( $m[1] ) ) {
						$note_rows[] = array(
							'label' => trim( wp_strip_all_tags( $m[2] ) ) ? trim( wp_strip_all_tags( $m[2] ) ) : 'Link',
							'value' => $m[1],
							'type'  => 'url',
						);
					}
				}
			}

			// Opening the entry in Minn marks it read, exactly like opening it
			// in GF's own entries screen (same view capability gates both).
			if ( empty( $entry['is_read'] ) ) {
				GFAPI::update_entry_property( $entry['id'], 'is_read', 1 );
			}

			$sections = array(
				array( 'title' => 'Responses', 'rows' => $answers ),
				array( 'title' => 'Submission', 'rows' => $meta ),
			);
			if ( $note_rows ) {
				$sections[] = array( 'title' => 'Notes', 'rows' => $note_rows );
			}

			return rest_ensure_response( array(
				// Form name only — the client entry layout promotes name/email
				// into a hero; never dump every answer into the modal title.
				'kind'     => 'entry',
				'title'    => $form['title'],
				// GF's "active" just means not spam/trash — surface as
				// "received" so the pill doesn't look like a form toggle.
				'status'   => ( 'active' === $entry['status'] ) ? 'received' : $entry['status'],
				'sections' => $sections,
				'adminUrl' => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry['id'] ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gf/entries/(?P<id>\d+)/notes', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return GFCommon::current_user_can_any( array( 'gravityforms_edit_entries', 'gform_full_access' ) );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$entry = GFAPI::get_entry( (int) $request['id'] );
			if ( is_wp_error( $entry ) ) {
				return new WP_Error( 'not_found', 'Entry not found.', array( 'status' => 404 ) );
			}
			$body  = $request->get_json_params();
			$value = sanitize_textarea_field( (string) ( isset( $body['value'] ) ? $body['value'] : '' ) );
			if ( '' === $value ) {
				return new WP_Error( 'empty_note', 'Write a note first.', array( 'status' => 400 ) );
			}
			$user    = wp_get_current_user();
			$note_id = GFAPI::add_note( (int) $entry['id'], $user->ID, $user->display_name, $value );
			if ( is_wp_error( $note_id ) ) {
				return new WP_Error( 'note_failed', $note_id->get_error_message(), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'id' => $note_id ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gf/forms', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return GFCommon::current_user_can_any( array( 'gravityforms_view_entries', 'gform_full_access' ) );
		},
		'callback'            => function () {
			$rows = array();
			foreach ( GFFormsModel::get_forms( null, 'title' ) as $f ) {
				$rows[] = array(
					'id'           => (int) $f->id,
					'title'        => $f->title,
					'entries'      => (int) GFAPI::count_entries( $f->id, array( 'status' => 'active' ) ),
					'status'       => $f->is_active ? 'active' : 'inactive',
					'date_created' => $f->date_created,
				);
			}
			return rest_ensure_response( $rows );
		},
	) );

	$can_edit_forms = function () {
		return GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );
	};

	// One notification row for the Notifications view. Composite id
	// "{form_id}:{notification_id}" — notification ids are uniqid() strings,
	// unique only within their form.
	$notification_row = function ( $form, $n ) {
		$to_type = isset( $n['toType'] ) && '' !== $n['toType'] ? $n['toType'] : 'email';
		$to      = '';
		if ( 'email' === $to_type ) {
			$to = (string) ( isset( $n['to'] ) ? $n['to'] : '' );
		} elseif ( 'field' === $to_type ) {
			$field = GFFormsModel::get_field( $form, isset( $n['to'] ) ? $n['to'] : 0 );
			$to    = 'Field: ' . ( $field ? wp_strip_all_tags( GFCommon::get_label( $field ) ) : '#' . ( isset( $n['to'] ) ? $n['to'] : '?' ) );
		} elseif ( 'routing' === $to_type ) {
			$rules = isset( $n['routing'] ) && is_array( $n['routing'] ) ? count( $n['routing'] ) : 0;
			$to    = sprintf( 'Routing (%d rule%s)', $rules, 1 === $rules ? '' : 's' );
		} else {
			$to = '—';
		}
		$events = array(
			'form_submission'           => 'Form is submitted',
			'form_saved'                => 'Draft is saved',
			'form_save_email_requested' => 'Draft link is requested',
		);
		$event  = isset( $n['event'] ) && '' !== $n['event'] ? (string) $n['event'] : 'form_submission';
		return array(
			'id'       => $form['id'] . ':' . $n['id'],
			'form_id'  => (int) $form['id'],
			'nid'      => (string) $n['id'],
			'name'     => (string) ( isset( $n['name'] ) ? $n['name'] : '' ),
			'form'     => (string) $form['title'],
			'event'    => isset( $events[ $event ] ) ? $events[ $event ] : ucfirst( str_replace( '_', ' ', $event ) ),
			'to'       => $to,
			// The edit form's send-to field: only an email-type notification
			// has an editable address (field/routing recipients are GF's
			// editor); the save route refuses writes for other types.
			'to_email' => 'email' === $to_type ? (string) ( isset( $n['to'] ) ? $n['to'] : '' ) : '',
			'subject'  => (string) ( isset( $n['subject'] ) ? $n['subject'] : '' ),
			'message'  => (string) ( isset( $n['message'] ) ? $n['message'] : '' ),
			'status'   => ( ! isset( $n['isActive'] ) || false !== $n['isActive'] ) ? 'active' : 'inactive',
		);
	};

	// List: one form's notifications, or every form's (the All tab).
	$list_notifications = function ( WP_REST_Request $request ) use ( $notification_row ) {
		$form_id  = (int) $request['form'];
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$rows     = array();
		if ( $form_id ) {
			$form = GFAPI::get_form( $form_id );
			if ( ! $form ) {
				return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );
			}
			$forms = array( $form );
		} else {
			$forms = array();
			foreach ( GFFormsModel::get_forms( null, 'title' ) as $f ) {
				$form = GFAPI::get_form( $f->id );
				if ( $form ) {
					$forms[] = $form;
				}
			}
		}
		foreach ( $forms as $form ) {
			foreach ( (array) ( isset( $form['notifications'] ) ? $form['notifications'] : array() ) as $n ) {
				if ( is_array( $n ) && isset( $n['id'] ) ) {
					$rows[] = $notification_row( $form, $n );
				}
			}
		}
		return rest_ensure_response( array(
			'items' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total' => count( $rows ),
		) );
	};

	register_rest_route( 'minn-admin/v1', '/gf/notifications', array(
		'methods'             => 'GET',
		'permission_callback' => $can_edit_forms,
		'callback'            => $list_notifications,
	) );
	register_rest_route( 'minn-admin/v1', '/gf/forms/(?P<form>\d+)/notifications', array(
		'methods'             => 'GET',
		'permission_callback' => $can_edit_forms,
		'callback'            => $list_notifications,
	) );

	// Activate/deactivate through GF's own toggle (it fires their
	// gform_pre_notification_(de)activated hooks and writes their column).
	register_rest_route( 'minn-admin/v1', '/gf/notifications/(?P<form>\d+):(?P<nid>[a-zA-Z0-9_.-]+)/active', array(
		'methods'             => 'POST',
		'permission_callback' => $can_edit_forms,
		'args'                => array(
			'active' => array( 'type' => 'boolean', 'required' => true ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$result = GFFormsModel::update_notification_active( (int) $request['form'], (string) $request['nid'], (bool) $request['active'] );
			if ( is_wp_error( $result ) ) {
				$result->add_data( array( 'status' => 404 ) );
				return $result;
			}
			GFFormsModel::flush_current_forms();
			return rest_ensure_response( array( 'id' => $request['form'] . ':' . $request['nid'], 'active' => (bool) $request['active'] ) );
		},
	) );

	// Edit the daily fields (name, send-to, subject, message) — a
	// read-modify-write on the form's own notifications array through
	// GFFormsModel::save_form_notifications, GF's dedicated write path.
	register_rest_route( 'minn-admin/v1', '/gf/notifications/(?P<form>\d+):(?P<nid>[a-zA-Z0-9_.-]+)', array(
		'methods'             => 'POST',
		'permission_callback' => $can_edit_forms,
		'callback'            => function ( WP_REST_Request $request ) {
			$form_id = (int) $request['form'];
			$nid     = (string) $request['nid'];
			$form    = GFFormsModel::get_form_meta( $form_id );
			if ( ! $form || ! isset( $form['notifications'][ $nid ] ) ) {
				return new WP_Error( 'not_found', 'Notification not found.', array( 'status' => 404 ) );
			}
			$body = $request->get_json_params();
			$n    = $form['notifications'][ $nid ];

			$name = sanitize_text_field( (string) ( isset( $body['name'] ) ? $body['name'] : '' ) );
			if ( '' === $name ) {
				return new WP_Error( 'empty_name', 'Give the notification a name.', array( 'status' => 400 ) );
			}
			// GF enforces unique names per form (its is_unique_name check).
			foreach ( $form['notifications'] as $other_id => $other ) {
				if ( $other_id !== $nid && isset( $other['name'] ) && strtolower( $other['name'] ) === strtolower( $name ) ) {
					return new WP_Error( 'dup_name', 'Another notification on this form already uses that name.', array( 'status' => 400 ) );
				}
			}
			$n['name']    = $name;
			$n['subject'] = sanitize_text_field( (string) ( isset( $body['subject'] ) ? $body['subject'] : '' ) );
			if ( '' === $n['subject'] ) {
				return new WP_Error( 'empty_subject', 'Give the notification a subject.', array( 'status' => 400 ) );
			}
			// Message is email-body HTML; kses keeps normal markup and GF
			// merge tags ({all_fields}) are plain text that passes untouched.
			$n['message'] = wp_kses_post( (string) ( isset( $body['message'] ) ? $body['message'] : '' ) );

			$to_type = isset( $n['toType'] ) && '' !== $n['toType'] ? $n['toType'] : 'email';
			$to      = trim( (string) ( isset( $body['to_email'] ) ? $body['to_email'] : '' ) );
			if ( '' !== $to ) {
				if ( 'email' !== $to_type ) {
					return new WP_Error( 'not_email_type', 'This notification routes by ' . $to_type . '; edit its recipients in Gravity Forms.', array( 'status' => 400 ) );
				}
				// GF accepts comma-separated addresses and merge tags
				// ({admin_email}, {Email:2}) in the To field.
				foreach ( array_map( 'trim', explode( ',', $to ) ) as $piece ) {
					if ( ! is_email( $piece ) && ! preg_match( '/^\{[^{}]+\}$/', $piece ) ) {
						return new WP_Error( 'bad_to', '"' . $piece . '" is not an email address or merge tag.', array( 'status' => 400 ) );
					}
				}
				$n['to'] = $to;
			}
			// An empty send-to never clears the stored address (the field
			// also rides along empty for field/routing notifications).

			$form['notifications'][ $nid ] = $n;
			GFFormsModel::flush_current_forms();
			GFFormsModel::save_form_notifications( $form_id, $form['notifications'] );
			return rest_ensure_response( array( 'id' => $form_id . ':' . $nid ) );
		},
	) );

	// Per-form settings — the GF Settings-framework mapper (schema read from
	// GFFormSettings::form_settings_fields() at request time; save is a
	// read-modify-write of the mapped keys through GFAPI::update_form).
	register_rest_route( 'minn-admin/v1', '/gf/forms/(?P<id>\d+)/settings/(?P<tab>[a-z_-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can_edit_forms,
			'callback'            => function ( WP_REST_Request $request ) {
				$form = GFAPI::get_form( (int) $request['id'] );
				if ( ! $form ) {
					return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_gf_form_settings_shape( $form ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_edit_forms,
			'callback'            => 'minn_admin_gf_form_settings_save',
		),
	) );

	register_rest_route( 'minn-admin/v1', '/gf/forms/(?P<id>\d+)/active', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );
		},
		'args'                => array(
			'active' => array( 'type' => 'boolean', 'required' => true ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) $request['id'];
			if ( ! GFAPI::form_id_exists( $id ) ) {
				return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );
			}
			GFAPI::update_forms_property( array( $id ), 'is_active', $request['active'] ? '1' : '0' );
			return rest_ensure_response( array( 'id' => $id, 'status' => $request['active'] ? 'active' : 'inactive' ) );
		},
	) );
} );

/* ===== Per-form settings: the GF Settings-framework mapper =============
 *
 * GFFormSettings::form_settings_fields( $form ) is a runtime schema (the
 * same Settings-framework descriptors GF renders its own screen from), so
 * the mapper is written once against the FRAMEWORK: sections walk into
 * Minn form-engine groups, and a GF release that adds a field shows up in
 * Minn with no adapter change. Unmappable controls (schedule date-times,
 * legacy markupVersion, unknown types) count as locked with the deep link.
 */

/**
 * form_settings_fields() calls gform_tooltip(), which lives in an include
 * REST requests never load (the Settings-API lesson: load the framework's
 * own dependencies before asking it for its schema).
 */
function minn_admin_gf_settings_deps() {
	require_once GFCommon::get_base_path() . '/tooltips.php';
	require_once GFCommon::get_base_path() . '/form_settings.php';
}

/**
 * GF's own initial-values flattening (their get_initial_values is
 * private): a nested form key like save.button.text reads as
 * saveButtonText — exactly how their renderer seeds fields, so mapped
 * fields read values the same way their own screen does.
 */
function minn_admin_gf_flat_form_values( $form ) {
	$out = array();
	foreach ( $form as $key => $value ) {
		if ( in_array( $key, array( 'fields', 'notifications', 'confirmations' ), true ) ) {
			continue;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $sk => $sv ) {
				if ( is_array( $sv ) ) {
					foreach ( $sv as $tk => $tv ) {
						if ( ! is_array( $tv ) ) {
							$out[ $key . ucfirst( (string) $sk ) . ucfirst( (string) $tk ) ] = $tv;
						}
					}
				} else {
					$out[ $key . ucfirst( (string) $sk ) ] = $sv;
				}
			}
			continue;
		}
		$out[ $key ] = $value;
	}
	return $out;
}

/**
 * Walk the schema into { groups, values, spec }. `spec` is the save
 * route's whitelist (key => type/options/allow_html), derived from the
 * same walk so the schema and the write path can never drift.
 *
 * @param array $form Full form (GFAPI::get_form).
 * @return array
 */
function minn_admin_gf_map_form_settings( $form ) {
	minn_admin_gf_settings_deps();
	$flat     = minn_admin_gf_flat_form_values( $form );
	$sections = GFFormSettings::form_settings_fields( $form );
	$groups   = array();
	$values   = array();
	$spec     = array();

	// Names the generic walk must never write: markupVersion stores 1/2
	// with inverted toggle semantics (legacy forms only).
	$never = array( 'markupVersion' );

	$options_of = function ( $choices ) {
		$options = array();
		foreach ( (array) $choices as $c ) {
			if ( is_array( $c ) && array_key_exists( 'value', $c ) ) {
				$options[] = array( (string) $c['value'], (string) ( isset( $c['label'] ) ? $c['label'] : $c['value'] ) );
			}
		}
		return $options;
	};

	// Minn's showWhen is single-key; a field's LAST dependency rule is its
	// nearest parent. Empty rule values mean "parent is truthy" (GF's
	// convention for toggle parents). Multi-value rules don't map; the
	// field just always shows, which is safe (it saves fine either way).
	$show_when_of = function ( $f ) {
		if ( empty( $f['dependency']['fields'] ) || ! is_array( $f['dependency']['fields'] ) ) {
			return null;
		}
		$dep = end( $f['dependency']['fields'] );
		if ( ! is_array( $dep ) || empty( $dep['field'] ) ) {
			return null;
		}
		$vals = isset( $dep['values'] ) ? array_values( (array) $dep['values'] ) : array();
		if ( count( $vals ) > 1 ) {
			return null;
		}
		return array( 'key' => (string) $dep['field'], 'equals' => $vals ? (string) $vals[0] : true );
	};

	foreach ( $sections as $section ) {
		$fields = array();
		$locked = 0;

		$walk = function ( $list, $inherited_show ) use ( &$walk, &$fields, &$locked, &$values, &$spec, $flat, $never, $options_of, $show_when_of ) {
			foreach ( (array) $list as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
				if ( 'html' === $type || 'save' === $type || ! empty( $f['hidden'] ) ) {
					continue; // chrome, not settings
				}
				$name = isset( $f['name'] ) ? (string) $f['name'] : '';
				$show = $show_when_of( $f );
				if ( ! $show && $inherited_show ) {
					$show = $inherited_show;
				}
				if ( '' === $name || in_array( $name, $never, true ) ) {
					$locked++;
					continue;
				}
				$label   = isset( $f['label'] ) ? wp_strip_all_tags( (string) $f['label'] ) : $name;
				$default = isset( $f['default_value'] ) ? $f['default_value'] : null;
				$current = array_key_exists( $name, $flat ) ? $flat[ $name ] : $default;

				switch ( $type ) {
					case 'text':
						$number = isset( $f['input_type'] ) && 'number' === $f['input_type'];
						$out    = array( 'key' => $name, 'label' => $label );
						if ( $number ) {
							$out['type'] = 'number';
						}
						if ( $show ) {
							$out['showWhen'] = $show;
						}
						$fields[]        = $out;
						$values[ $name ] = $number ? (string) (int) $current : (string) $current;
						$spec[ $name ]   = array( 'type' => $number ? 'number' : 'text' );
						break;
					case 'textarea':
						$out = array( 'key' => $name, 'label' => $label, 'type' => 'textarea', 'rows' => 4 );
						if ( $show ) {
							$out['showWhen'] = $show;
						}
						$fields[]        = $out;
						$values[ $name ] = (string) $current;
						$spec[ $name ]   = array( 'type' => 'textarea', 'allow_html' => ! empty( $f['allow_html'] ) );
						break;
					case 'select':
					case 'radio':
						$options = $options_of( isset( $f['choices'] ) ? $f['choices'] : array() );
						if ( ! $options ) {
							$locked++;
							break;
						}
						$out = array( 'key' => $name, 'label' => $label, 'type' => 'select', 'options' => $options );
						if ( $show ) {
							$out['showWhen'] = $show;
						}
						$fields[]        = $out;
						$values[ $name ] = (string) $current;
						$spec[ $name ]   = array( 'type' => 'select', 'options' => wp_list_pluck( $options, 0 ) );
						break;
					case 'toggle':
						$out = array( 'key' => $name, 'label' => $label, 'type' => 'toggle' );
						if ( $show ) {
							$out['showWhen'] = $show;
						}
						$fields[]        = $out;
						$values[ $name ] = (bool) $current;
						$spec[ $name ]   = array( 'type' => 'toggle' );
						break;
					case 'checkbox':
						// GF's single-checkbox idiom is a boolean toggle whose
						// nested fields reveal when it's on.
						$choices = isset( $f['choices'] ) ? (array) $f['choices'] : array();
						if ( 1 !== count( $choices ) || ! isset( $choices[0]['name'] ) || $choices[0]['name'] !== $name ) {
							$locked++;
							break;
						}
						$out = array( 'key' => $name, 'label' => $label, 'type' => 'toggle' );
						if ( $show ) {
							$out['showWhen'] = $show;
						}
						$fields[]        = $out;
						$values[ $name ] = (bool) $current;
						$spec[ $name ]   = array( 'type' => 'toggle' );
						if ( ! empty( $f['fields'] ) ) {
							$walk( $f['fields'], array( 'key' => $name, 'equals' => true ) );
						}
						break;
					case 'text_and_select':
						// A two-control composite (limit entries count +
						// period); each input is its own Minn field.
						$text   = isset( $f['inputs']['text'] ) ? $f['inputs']['text'] : null;
						$select = isset( $f['inputs']['select'] ) ? $f['inputs']['select'] : null;
						if ( ! is_array( $text ) || empty( $text['name'] ) || ! is_array( $select ) || empty( $select['name'] ) ) {
							$locked++;
							break;
						}
						$tname  = (string) $text['name'];
						$number = isset( $text['input_type'] ) && 'number' === $text['input_type'];
						$outt   = array( 'key' => $tname, 'label' => $label );
						if ( $number ) {
							$outt['type'] = 'number';
						}
						if ( $show ) {
							$outt['showWhen'] = $show;
						}
						$fields[]         = $outt;
						$values[ $tname ] = $number ? (string) (int) ( isset( $flat[ $tname ] ) ? $flat[ $tname ] : 0 ) : (string) ( isset( $flat[ $tname ] ) ? $flat[ $tname ] : '' );
						$spec[ $tname ]   = array( 'type' => $number ? 'number' : 'text' );
						$sname            = (string) $select['name'];
						$options          = $options_of( isset( $select['choices'] ) ? $select['choices'] : array() );
						if ( $options ) {
							$outs = array( 'key' => $sname, 'label' => 'Period', 'type' => 'select', 'options' => $options );
							if ( $show ) {
								$outs['showWhen'] = $show;
							}
							$fields[]         = $outs;
							$values[ $sname ] = (string) ( isset( $flat[ $sname ] ) ? $flat[ $sname ] : '' );
							$spec[ $sname ]   = array( 'type' => 'select', 'options' => wp_list_pluck( $options, 0 ) );
						}
						break;
					default:
						// date_time (schedule), conditional-logic widgets and
						// anything GF adds later that Minn can't draw.
						$locked++;
				}
			}
		};

		$walk( isset( $section['fields'] ) ? $section['fields'] : array(), null );

		if ( $fields || $locked ) {
			$group = array(
				'title'  => isset( $section['title'] ) ? wp_strip_all_tags( (string) $section['title'] ) : '',
				'fields' => $fields,
			);
			if ( $locked ) {
				$group['locked'] = $locked;
			}
			$groups[] = $group;
		}
	}

	return array( 'groups' => $groups, 'values' => $values, 'spec' => $spec );
}

/** GET shape for the per-form settings view. */
function minn_admin_gf_form_settings_shape( $form ) {
	$mapped = minn_admin_gf_map_form_settings( $form );
	return array(
		'groups'   => $mapped['groups'],
		'values'   => $mapped['values'],
		'adminUrl' => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=settings&id=' . (int) $form['id'] ),
	);
}

/**
 * POST: write one form's changed settings. Generic keys coerce by their
 * own schema entry (toggles cast, selects whitelist against their own
 * choices, numbers absint — the same rules GF's save applies); the few
 * composite keys route through GF's own helpers (save-and-continue
 * activation, spam-confirmation toggle). Everything lands in one
 * GFAPI::update_form call: read-modify-write, smallest window.
 */
function minn_admin_gf_form_settings_save( WP_REST_Request $request ) {
	$form = GFAPI::get_form( (int) $request['id'] );
	if ( ! $form ) {
		return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );
	}
	$body = $request->get_json_params();
	$vals = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();

	$mapped = minn_admin_gf_map_form_settings( $form );
	$spec   = $mapped['spec'];

	// Title mirrors GF's own validation: required, unique across forms
	// (their screen refuses; GFAPI would silently rename to "title(2)").
	if ( array_key_exists( 'title', $vals ) ) {
		$title = sanitize_text_field( (string) $vals['title'] );
		if ( '' === $title ) {
			return new WP_Error( 'empty_title', 'Give the form a title.', array( 'status' => 400 ) );
		}
		foreach ( GFFormsModel::get_forms() as $f ) {
			if ( (int) $f->id !== (int) $form['id'] && strtolower( $f->title ) === strtolower( $title ) ) {
				return new WP_Error( 'dup_title', 'Another form already uses that title.', array( 'status' => 400 ) );
			}
		}
		$vals['title'] = $title;
	}

	$save_enabled_before = ! empty( $form['save']['enabled'] );

	foreach ( $vals as $key => $value ) {
		if ( ! isset( $spec[ $key ] ) ) {
			continue; // never write a key the live schema doesn't declare
		}
		$s = $spec[ $key ];
		switch ( $s['type'] ) {
			case 'toggle':
				$value = (bool) $value;
				break;
			case 'number':
				$value = absint( $value );
				break;
			case 'select':
				if ( ! in_array( (string) $value, array_map( 'strval', $s['options'] ), true ) ) {
					return new WP_Error( 'bad_choice', '"' . $value . '" is not one of the choices for ' . $key . '.', array( 'status' => 400 ) );
				}
				$value = (string) $value;
				break;
			case 'textarea':
				$value = ! empty( $s['allow_html'] ) ? wp_kses_post( (string) $value ) : sanitize_textarea_field( (string) $value );
				break;
			default:
				$value = sanitize_text_field( (string) $value );
		}
		// The composite keys write where GF's own save writes them.
		if ( 'saveEnabled' === $key ) {
			$form['save']['enabled']        = (bool) $value;
			$form['save']['button']['type'] = 'link';
		} elseif ( 'saveButtonText' === $key ) {
			$form['save']['button']['text'] = $value;
			if ( ! isset( $form['save']['button']['type'] ) ) {
				$form['save']['button']['type'] = 'link';
			}
		} else {
			$form[ $key ] = $value;
		}
	}

	// GF's own side-effect helpers, exactly where their save runs them.
	if ( isset( $vals['enableSpamConfirmation'] ) && method_exists( 'GFFormSettings', 'toggle_spam_confirmation' ) ) {
		$form = GFFormSettings::toggle_spam_confirmation( $form );
	}
	$save_enabled_after = ! empty( $form['save']['enabled'] );
	if ( $save_enabled_after !== $save_enabled_before ) {
		$form = $save_enabled_after ? GFFormSettings::activate_save( $form ) : GFFormSettings::deactivate_save( $form );
	}

	$form['version'] = GFForms::$version;
	$result          = GFAPI::update_form( $form );
	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 400 ) );
		return $result;
	}
	return rest_ensure_response( minn_admin_gf_form_settings_shape( GFAPI::get_form( (int) $form['id'] ) ) );
}
