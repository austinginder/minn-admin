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

	$surfaces['gravity-forms'] = array(
		'label'      => 'Forms',
		// Shared with Fluent Forms / Elementor / WPForms adapters when present;
		// topbar becomes a provider switcher when family size > 1.
		'family'     => 'forms',
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
			'tabs'      => array(
				'route'    => 'gf/v2/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => '_summary', 'label' => 'Entry', 'format' => 'entry-summary' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date_created', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				// The shim returns the whole display model: answers with the
				// form's real field labels (in form order), then the
				// submission details — no client-side label mapping.
				'sectionsRoute' => 'minn-admin/v1/gf/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Trash entry',
					'method'  => 'DELETE',
					'route'   => 'gf/v2/entries/{id}',
					'confirm' => 'Move this entry to trash?',
					'danger'  => true,
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
				array( 'key' => 'date_created', 'label' => 'Created', 'format' => 'ago' ),
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

			return rest_ensure_response( array(
				'title'    => $form['title'],
				'status'   => $entry['status'],
				'sections' => array(
					array( 'title' => 'Responses', 'rows' => $answers ),
					array( 'title' => 'Submission', 'rows' => $meta ),
				),
				'adminUrl' => admin_url( 'admin.php?page=gf_entries&view=entry&id=' . $entry['form_id'] . '&lid=' . $entry['id'] ),
			) );
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
