<?php
/**
 * Bundled adapter: Fluent Forms entries.
 *
 * Fluent ships fluentform/v1 with full submissions CRUD, but responses use a
 * Laravel paginator envelope and store field values as a JSON `response`
 * blob — Minn's collection primitives want { items, total } plus a labeled
 * detail. The submissions are a kernel resource (docs/adapter-kernel.md):
 * the kernel reads the submissions table and owns the routes, gates and
 * errors; status changes and deletes go through Fluent's SubmissionService.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

// Fluent Forms' captcha secrets, its CleanTalk and Mailchimp keys, its
// mailing integrations' API keys, every payment gateway's settings (the
// prefix Fluent itself keeps out of its settings API), the key it encrypts
// them with and the salt it signs with (in the same database).
minn_admin_secrets( 'fluentform', array( 'options' => array( '_fluentform_reCaptcha_details', '_fluentform_hCaptcha_details', '_fluentform_turnstile_details', '_fluentform_cleantalk_details', '_fluentform_mailchimp_details', '_fluentform_activecampaign_settings', '_fluentform_campaignmonitor_settings', '_fluentform_constantcontact_settings', '_fluentform_getresponse_settings', '_fluentform_icontact_settings', 'fluentform_payment_settings_*', '_fluentform_encryption_key', '_fluentform_security_salt' ) ) );

/**
 * Fluent Forms is loaded.
 */
function minn_admin_fluent_forms_ready() {
	return defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' ) || class_exists( 'FluentForm\App\Models\Form' );
}

/**
 * Entry viewer capability (admins get full access via Fluent ACL).
 */
function minn_admin_fluent_forms_can_view() {
	if ( current_user_can( 'fluentform_full_access' ) || current_user_can( 'fluentform_entries_viewer' ) ) {
		return true;
	}
	// Fluent grants managers manage_options-level caps on install; fall back
	// so a plain admin still sees the surface before ACL has run.
	return current_user_can( 'manage_options' );
}

/** Entry manager capability: status changes and permanent deletes. */
function minn_admin_fluent_forms_can_manage() {
	return current_user_can( 'fluentform_manage_entries' )
		|| current_user_can( 'fluentform_full_access' )
		|| current_user_can( 'manage_options' );
}

/**
 * The set of form ids this user may touch, or false when unscoped.
 *
 * Fluent's Settings -> Managers lets an admin scope a manager to specific
 * forms (user meta _fluent_forms_has_specific_forms_permission /
 * _fluent_forms_allowed_forms). Their own screens honour it because every
 * Acl::hasPermission() call passes a form id; a bare current_user_can() does
 * not, which is how a scoped manager could read and delete EVERY form's
 * submissions through this adapter.
 *
 * Returns false (no restriction) or an array of ints (possibly empty, which
 * means "assigned to nothing" and must yield no rows, not all rows).
 */
function minn_admin_fluent_forms_scope() {
	if ( ! class_exists( '\FluentForm\App\Services\Manager\FormManagerService' ) ) {
		return false;
	}
	$scope = \FluentForm\App\Services\Manager\FormManagerService::getUserAllowedFormsScope();
	return false === $scope ? false : array_map( 'intval', (array) $scope );
}

/** The same scope in the kernel's terms: null for unrestricted, else the ids. */
function minn_admin_fluent_forms_allowed_form_ids() {
	$scope = minn_admin_fluent_forms_scope();
	return false === $scope ? null : $scope;
}

/**
 * Whether this user may act on ONE form, through Fluent's own object-aware ACL.
 */
function minn_admin_fluent_forms_can_form( $form_id, $permission = 'fluentform_entries_viewer' ) {
	$form_id = (int) $form_id;
	if ( $form_id <= 0 ) {
		return false;
	}
	if ( class_exists( '\FluentForm\App\Modules\Acl\Acl' ) ) {
		return (bool) \FluentForm\App\Modules\Acl\Acl::hasPermission( $permission, $form_id );
	}
	// No ACL class to ask — fall back to the scope list plus the flat cap.
	$scope = minn_admin_fluent_forms_scope();
	if ( false !== $scope && ! in_array( $form_id, $scope, true ) ) {
		return false;
	}
	return minn_admin_fluent_forms_can_view();
}

/**
 * The form id a submission belongs to (0 when the row is gone).
 */
function minn_admin_fluent_forms_entry_form_id( $entry_id ) {
	return (int) Minn_Admin_Kernel_SQL::latest( 'fluentform_submissions', 'form_id', array( 'id' => (int) $entry_id ), 'id' );
}

/**
 * The row-level check: Fluent's permission for the gate, asked for the form
 * behind the submission. A gate it does not know is refused.
 */
function minn_admin_fluent_forms_can_entry( $entry_id, $gate ) {
	$perms = array(
		'view'   => 'fluentform_entries_viewer',
		'manage' => 'fluentform_manage_entries',
	);
	if ( ! isset( $perms[ $gate ] ) ) {
		return false;
	}
	return minn_admin_fluent_forms_can_form( minn_admin_fluent_forms_entry_form_id( $entry_id ), $perms[ $gate ] );
}

/** A form's title ('' when the form is gone). */
function minn_admin_fluent_forms_form_title( $form_id ) {
	$row = Minn_Admin_Kernel_SQL::row( 'fluentform_forms', 'id', (int) $form_id, array( 'title' ) );
	return $row ? (string) $row['title'] : '';
}

/**
 * Field labels for a form id, keyed by input name.
 *
 * @param int $form_id Form ID.
 * @return array<string,string>
 */
function minn_admin_fluent_forms_labels( $form_id ) {
	$raw = Minn_Admin_Kernel_SQL::latest( 'fluentform_forms', 'form_fields', array( 'id' => (int) $form_id ), 'id' );
	if ( ! $raw ) {
		return array();
	}
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) || empty( $decoded['fields'] ) ) {
		return array();
	}
	$labels = array();
	foreach ( $decoded['fields'] as $field ) {
		$name = $field['attributes']['name'] ?? '';
		if ( ! $name ) {
			continue;
		}
		$admin = trim( (string) ( $field['settings']['admin_field_label'] ?? '' ) );
		$label = $admin !== ''
			? $admin
			: trim( (string) ( $field['settings']['label'] ?? '' ) );
		$labels[ $name ] = wp_strip_all_tags( $label !== '' ? $label : $name );
	}
	return $labels;
}

/**
 * Password answers as Fluent's own screens show them. A password field's
 * answer is replaced after the entry's integrations run, but until then (a
 * spam entry marked "skip processing", integrations still queued) the
 * stored answer is the password. Fluent's entry screens mask it whenever
 * its truncate_password_values filter says so (its response_render
 * filter); Minn masks the same answers, by the form's own password fields.
 *
 * @param array $map     Answers keyed by input name (or the raw response).
 * @param int   $form_id Form ID.
 * @return array
 */
function minn_admin_fluent_forms_mask_passwords( $map, $form_id ) {
	// Helper::shouldHidePassword() answers true only while Fluent's own entry
	// screens render (they define FLUENTFORM_RENDERING_ENTRY); these views are
	// entry renderings, so ask the same site-level filter it applies.
	if ( ! is_array( $map ) || ! defined( 'FLUENTFORM' )
		|| ! apply_filters( 'fluentform/truncate_password_values', true, (int) $form_id ) ) {
		return $map;
	}
	$raw    = Minn_Admin_Kernel_SQL::latest( 'fluentform_forms', 'form_fields', array( 'id' => (int) $form_id ), 'id' );
	$fields = json_decode( (string) $raw, true );
	$names  = array();
	$walk   = function ( $list ) use ( &$walk, &$names ) {
		foreach ( (array) $list as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			if ( 'input_password' === ( $f['element'] ?? '' ) && ! empty( $f['attributes']['name'] ) ) {
				$names[] = (string) $f['attributes']['name'];
			}
			foreach ( (array) ( $f['columns'] ?? array() ) as $col ) {
				$walk( $col['fields'] ?? array() );
			}
			if ( ! empty( $f['fields'] ) ) {
				$walk( $f['fields'] );
			}
		}
	};
	$walk( is_array( $fields ) ? ( $fields['fields'] ?? array() ) : array() );
	foreach ( $names as $name ) {
		if ( isset( $map[ $name ] ) && '' !== (string) ( is_scalar( $map[ $name ] ) ? $map[ $name ] : 'x' ) ) {
			$map[ $name ] = str_repeat( '*', 6 ) . ' ' . __( '(truncated)', 'fluentform' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		}
	}
	return $map;
}

/**
 * Whether anyone may read Fluent's submission tables whole. Its entry
 * screens mask a password answer for every viewer while its truncate filter
 * holds for the form (minn_admin_fluent_forms_mask_passwords()), and the
 * stored answer is the password until the form's integrations replace it,
 * so a form with a password field keeps everyone out of the tables that
 * hold its answers (the database browser asks this as the resource's table
 * gate). Asked as Fluent asks it: the form's fields hold an input_password
 * element and the filter says truncate. Answered whether or not Fluent is
 * active, since its tables outlive it.
 *
 * @return bool
 */
function minn_admin_fluent_forms_tables_whole() {
	$forms = Minn_Admin_Kernel_SQL::select( 'fluentform_forms', array( 'id' ), array( 'form_fields' => array( 'contains' => '"element":"input_password"' ) ) );
	foreach ( $forms as $f ) {
		if ( apply_filters( 'fluentform/truncate_password_values', true, (int) $f['id'] ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Decode a submission response JSON into a flat string map.
 *
 * @param string|array $response Raw response column or array.
 * @return array<string,string>
 */
function minn_admin_fluent_forms_response_map( $response ) {
	if ( is_array( $response ) ) {
		$map = $response;
	} else {
		$map = json_decode( (string) $response, true );
		if ( ! is_array( $map ) ) {
			return array();
		}
	}
	$out = array();
	foreach ( $map as $k => $v ) {
		// Name fields etc. flatten to "First Last".
		$out[ $k ] = is_array( $v ) ? minn_admin_forms_flat( $v, ' ' ) : (string) $v;
	}
	return $out;
}

/** Server-built model for the surface status card (SureForms parity). */
function minn_admin_fluent_forms_status_model() {
	// Counters follow the same scope as the list: Fluent scopes its own
	// admin-bar and menu counts the same way, so a manager provisioned for one
	// form must not learn the whole estate's volume.
	$scope = minn_admin_fluent_forms_allowed_form_ids();
	$open  = array( 'label' => __( 'Open Fluent Forms ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=fluent_forms_all_entries' ) );
	if ( is_array( $scope ) && ! $scope ) {
		return minn_admin_forms_none( array( $open ), __( '0 total', 'minn-admin' ) );
	}
	// created_at is site-local current_time, so the chart groups on the site's days.
	$kept = array( 'status' => array( 'not' => array( 'trashed' ) ) );
	$n    = Minn_Admin_Kernel::inbox_counts( array(
		'resource' => 'fluent-forms/entries',
		'received' => $kept,
		'unread'   => array( 'status' => 'unread' ),
		'spam'     => array( 'status' => 'spam' ),
		'chart'    => array( 'column' => 'created_at', 'clock' => 'local', 'where' => $kept, 'split' => 'status', 'secondary' => array( 'spam' ) ),
	) );
	$nforms = is_array( $scope ) ? count( $scope ) : Minn_Admin_Kernel_SQL::count( 'fluentform_forms' );
	return minn_admin_forms_card( minn_admin_forms_unread( $n['unread'], $n['received'], $n['spam'] ), $nforms, $n['days'], $open, __( 'Spam', 'minn-admin' ) );
}

/** A submission's list row; `bucket` is the filter it was listed under. */
function minn_admin_fluent_forms_row( array $r, array $q ) {
	$map   = minn_admin_fluent_forms_mask_passwords( minn_admin_fluent_forms_response_map( $r['response'] ), (int) $r['form_id'] );
	$title = minn_admin_fluent_forms_form_title( (int) $r['form_id'] );
	return array(
		'id'         => (int) $r['id'],
		'form_id'    => (int) $r['form_id'],
		'summary'    => minn_admin_forms_summary( array_map( 'trim', $map ) ),
		'form_title' => $title ?: ( 'Form #' . $r['form_id'] ),
		'status'     => $r['status'] ? (string) $r['status'] : 'unread',
		'bucket'     => (string) $q['filter'],
		'date'       => $r['created_at'],
	);
}

/** A submission's detail; opening it marks it read, as Fluent's screen does. */
function minn_admin_fluent_forms_view( array $raw ) {
	$row = (object) $raw;

	// Opening a detail marks unread → read (Fluent Forms' own screen
	// semantics), unless the site turned that off with their filter.
	$form_row  = wpFluent()->table( 'fluentform_forms' )->where( 'id', (int) $row->form_id )->first();
	$auto_read = apply_filters_deprecated( 'fluentform_auto_read', array( true, $form_row ), defined( 'FLUENTFORM_FRAMEWORK_UPGRADE' ) ? FLUENTFORM_FRAMEWORK_UPGRADE : '', 'fluentform/auto_read_submission' );
	$auto_read = apply_filters( 'fluentform/auto_read_submission', $auto_read, $form_row );
	if ( 'unread' === (string) $row->status && $auto_read ) {
		Minn_Admin_Kernel_SQL::update( 'fluentform_submissions', 'id', (int) $row->id, array( 'status' => 'read' ) );
		$row->status = 'read';
	}

	$map     = minn_admin_fluent_forms_mask_passwords( minn_admin_fluent_forms_response_map( $row->response ), (int) $row->form_id );
	$labels  = minn_admin_fluent_forms_labels( (int) $row->form_id );
	$answers = array();
	foreach ( $map as $key => $val ) {
		if ( '' !== trim( $val ) ) {
			$answers[] = minn_admin_forms_typed( $labels[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $key ) ), $val, $key );
		}
	}

	$form_title = minn_admin_fluent_forms_form_title( (int) $row->form_id );
	$meta       = minn_admin_forms_meta_dated( $row->created_at ? date_i18n( 'M j, Y g:i a', strtotime( $row->created_at ) ) : '', $form_title ? $form_title : null );
	if ( ! empty( $row->source_url ) ) {
		$meta[] = array( 'label' => __( 'Source', 'minn-admin' ), 'value' => $row->source_url, 'type' => 'url' );
	}
	if ( ! empty( $row->ip ) ) {
		$meta[] = array( 'label' => 'IP', 'value' => $row->ip );
	}
	if ( ! empty( $row->browser ) || ! empty( $row->device ) ) {
		$meta[] = array(
			'label' => __( 'Client', 'minn-admin' ),
			'value' => trim( ( $row->device ?: '' ) . ' · ' . ( $row->browser ?: '' ), ' ·' ),
		);
	}

	$status = $row->status ? (string) $row->status : 'unread';
	return minn_admin_forms_entry( $answers, $meta, admin_url( 'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $row->form_id . '#/entries/' . (int) $row->id ), array(
		'responses' => true,
		'title'     => $form_title ?: ( 'Form #' . (int) $row->form_id ),
		'status'    => $status,
		// The row as it stands now, after opening marked it read:
		// the page's actions gate on it, not on the list's copy.
		'item'      => array(
			'id'      => (int) $row->id,
			'form_id' => (int) $row->form_id,
			'status'  => $status,
			'bucket'  => in_array( $status, array( 'spam', 'trashed' ), true ) ? $status : 'inbox',
		),
	) );
}

/**
 * Delete through Fluent Forms' own service. A submission is more than its
 * two obvious tables: their deleteEntries() removes the files the entry
 * uploaded, clears submission meta, the activity log (which holds rendered
 * notification bodies and recipient addresses) and any order, transaction,
 * subscription and scheduled-action rows, and fires the hooks add-ons use to
 * drop their own copies. Deleting the two tables by hand answered "deleted
 * permanently" while the uploaded file was still being served from the URL
 * the detail view had just linked, which is the wrong answer to give someone
 * honouring an erasure request.
 */
function minn_admin_fluent_forms_delete( $id, $form_id ) {
	if ( class_exists( '\FluentForm\App\Services\Submission\SubmissionService' ) ) {
		try {
			( new \FluentForm\App\Services\Submission\SubmissionService() )->deleteEntries( array( $id ), $form_id );
			return true;
		} catch ( \Throwable $e ) {
			// Fall through to the manual path below rather than leaving the
			// entry half-deleted.
			unset( $e );
		}
	}
	// Last resort when their service is absent: cover the same ground by hand
	// and still fire their hooks, so add-on cleanup runs even here.
	do_action( 'fluentform/before_deleting_entries', array( $id ), $form_id );
	Minn_Admin_Kernel_SQL::delete( 'fluentform_submission_meta', 'response_id', (int) $id );
	Minn_Admin_Kernel_SQL::delete( 'fluentform_logs', 'source_id', (int) $id, array( 'source_type' => 'submission_item' ) );
	Minn_Admin_Kernel_SQL::delete( 'fluentform_entry_details', 'submission_id', (int) $id );
	Minn_Admin_Kernel_SQL::delete( 'fluentform_submissions', 'id', (int) $id );
	do_action( 'fluentform/after_deleting_submissions', array( $id ), $form_id );
	return true;
}

minn_admin_resource( 'fluent-forms/entries', function () {
	return array(
		'active'    => 'minn_admin_fluent_forms_ready',
		'gates'     => array(
			'view'   => 'minn_admin_fluent_forms_can_view',
			'manage' => 'minn_admin_fluent_forms_can_manage',
			// Password answers are masked for everyone (see the function).
			'table'  => 'minn_admin_fluent_forms_tables_whole',
		),
		'item_gate' => 'minn_admin_fluent_forms_can_entry',
		'labels'    => array(
			'not_found' => __( 'Entry not found.', 'minn-admin' ),
			'forbidden' => __( 'You cannot access entries for that form.', 'minn-admin' ),
		),
		'source'    => array(
			'table'   => 'fluentform_submissions',
			'key'     => 'id',
			'columns' => array( 'id', 'form_id', 'response', 'status', 'created_at' ),
			'detail'  => array(),
			// Every answer field by field, each entry's meta, the log of what
			// happened to it, and what Fluent's own entry delete removes with
			// it: its payment orders, transactions and subscriptions, and the
			// queued notification and integration feeds rendered from its
			// answers. Pro's saved drafts are answers too, and the form view
			// log carries each visitor's IP per form.
			'tables'  => array( 'fluentform_entry_details', 'fluentform_submission_meta', 'fluentform_logs', 'fluentform_order_items', 'fluentform_transactions', 'fluentform_subscriptions', 'ff_scheduled_actions', 'fluentform_draft_submissions', 'fluentform_form_analytics' ),
		),
		'list'      => array(
			// Their status column: unread/read/spam/trashed (favorites is a
			// separate flag). Received is everything not spam and not trash.
			'filter' => array(
				'param'   => 'status',
				'values'  => array(
					'inbox'   => array( 'status' => array( 'not' => array( 'spam', 'trashed' ) ) ),
					'spam'    => array( 'status' => 'spam' ),
					'trashed' => array( 'status' => 'trashed' ),
				),
				'default' => 'inbox',
			),
			'tab'    => array(
				'param'  => 'form_id',
				'column' => 'form_id',
				'gate'   => 'minn_admin_fluent_forms_can_form',
				'refuse' => __( 'You cannot access entries for that form.', 'minn-admin' ),
			),
			// MANDATORY scope for managers assigned to specific forms. Without
			// it an unfiltered list returns every form's submissions — names,
			// emails, phone numbers and message bodies for forms this user was
			// never granted. An empty scope means "assigned to nothing".
			'scope'  => array(
				'column'  => 'form_id',
				'allowed' => 'minn_admin_fluent_forms_allowed_form_ids',
				'refuse'  => __( 'You cannot access entries for that form.', 'minn-admin' ),
			),
			'search' => array( 'param' => 'search', 'columns' => array( 'response' ) ),
			// created_at is site-local current_time, the same clock the
			// status-chart bounds arrive on.
			'range'  => array( 'column' => 'created_at', 'clock' => 'local' ),
			// Newest submission first by the time Fluent stored, so an entry
			// added out of order (an import) lands where it belongs.
			'order'  => array( 'column' => 'created_at', 'dir' => 'DESC' ),
			'row'    => 'minn_admin_fluent_forms_row',
			'fields' => minn_admin_forms_fields( array(
				'form_id' => 'int',
				'bucket'  => 'text',
				// Site-local; timeAgo treats bare strings as UTC, so it leaves
				// as local-looking ISO without a Z.
				'date'    => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'naked' ),
			) ),
		),
		'item'      => array( 'view' => 'minn_admin_fluent_forms_view' ),
		'actions'   => array(
			// One route whose body names the status, as the descriptor sends it.
			// Through their service, as the delete goes through theirs: it refuses
			// a status an add-on withholds for this entry and fires the
			// after-update action add-ons listen on.
			'status' => array(
				'gate'   => 'manage',
				'run'    => function ( $id, $row, WP_REST_Request $request ) {
					$status = sanitize_key( (string) ( $request['status'] ?? '' ) );
					if ( ! in_array( $status, array( 'unread', 'read', 'spam', 'trashed' ), true ) ) {
						return new WP_Error( 'bad_status', __( 'Unknown status.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					if ( class_exists( '\FluentForm\App\Services\Submission\SubmissionService' ) ) {
						try {
							( new \FluentForm\App\Services\Submission\SubmissionService() )->updateStatus( array( 'entry_id' => $id, 'status' => $status ) );
						} catch ( \Throwable $e ) {
							return new WP_Error( 'bad_status', __( 'Fluent Forms does not allow that status for this entry.', 'minn-admin' ), array( 'status' => 400 ) );
						}
					} else {
						Minn_Admin_Kernel_SQL::update( 'fluentform_submissions', 'id', (int) $id, array( 'status' => $status ) );
					}
					return $status;
				},
				'expect' => function ( $id, $fresh, $status ) {
					return is_array( $fresh ) && (string) $status === (string) $fresh['status'];
				},
				'reply'  => function ( $id, $fresh, $status ) {
					$msgs = array(
						'unread'  => __( 'Entry restored.', 'minn-admin' ),
						'read'    => __( 'Marked as read.', 'minn-admin' ),
						'spam'    => __( 'Marked as spam.', 'minn-admin' ),
						'trashed' => __( 'Moved to trash.', 'minn-admin' ),
					);
					return array(
						'id'      => (int) $id,
						'status'  => $status,
						'message' => $msgs[ $status ] ?? __( 'Status updated.', 'minn-admin' ),
					);
				},
			),
			// Permanent delete is for trash/spam only (Received → Trash first).
			'delete' => minn_admin_forms_delete( function ( $id, $row ) {
				return minn_admin_fluent_forms_delete( $id, (int) $row['form_id'] );
			}, __( 'Fluent Forms reported success but the entry still exists.', 'minn-admin' ), array(
				'when'   => array( 'status' => array( 'trashed', 'spam' ) ),
				'code'   => 'not_trashed',
				'refuse' => __( 'Move the entry to trash (or spam) before deleting permanently.', 'minn-admin' ),
				'reply'  => 'minn_admin_forms_deleted',
			) ),
		),
	);
} );

minn_admin_endpoint( 'fluent-forms/status', array(
	'active' => 'minn_admin_fluent_forms_ready',
	'gate'   => 'minn_admin_fluent_forms_can_view',
	'run'    => function () {
		return minn_admin_fluent_forms_status_model();
	},
) );

minn_admin_endpoint( 'fluent-forms/forms', array(
	'active' => 'minn_admin_fluent_forms_ready',
	'gate'   => 'minn_admin_fluent_forms_can_view',
	'run'    => function ( WP_REST_Request $request ) {
		// A form's count is what its Received list holds: spam and trash
		// are their own buckets.
		// Scope the form estate the same way the entries are scoped. Fluent's
		// own dropdowns, reports and search all wrap this in
		// whereIn('id', $allowForms ?: [0]); without it a manager scoped to
		// one form still enumerates every form's title and traffic volume.
		$scope = minn_admin_fluent_forms_scope();
		$rows  = array();
		if ( ! is_array( $scope ) || $scope ) {
			$counts = array();
			foreach ( Minn_Admin_Kernel_SQL::totals( 'fluentform_submissions', array( 'form_id' ), array( 'n' => array( 'count' => '*' ) ), array( 'status' => array( 'not' => array( 'spam', 'trashed' ) ) ), array(), 5000 ) as $c ) {
				$counts[ (int) $c['form_id'] ] = (int) $c['n'];
			}
			foreach ( Minn_Admin_Kernel_SQL::select( 'fluentform_forms', array( 'id', 'title', 'status', 'updated_at' ), is_array( $scope ) ? array( 'id' => array_map( 'intval', $scope ) ) : array(), array( 'title' => 'ASC', 'id' => 'ASC' ) ) as $f ) {
				$rows[] = (object) ( $f + array( 'entries' => $counts[ (int) $f['id'] ] ?? 0 ) );
			}
		}
		$manage = ! empty( $request['manage'] );
		$out    = array();
		foreach ( $rows as $r ) {
			$row = array(
				'id'    => (int) $r->id,
				'title' => $r->title,
			);
			if ( $manage ) {
				$row['entries'] = (int) $r->entries;
				$row['status']  = ( 'published' === $r->status ) ? 'active' : (string) $r->status;
				$row['date']    = $r->updated_at
					? str_replace( ' ', 'T', (string) $r->updated_at )
					: '';
			}
			$out[] = $row;
		}
		return $out;
	},
) );

minn_admin_add_surface( 'fluent-forms', function () {
	return minn_admin_fluent_forms_ready() && minn_admin_fluent_forms_can_view();
}, function () {
	$ui = minn_admin_forms_ui( 'fluent-forms/entries' );
	return minn_admin_forms_surface( 'fluent-forms/entries', array(
		'sub'     => 'Fluent Forms',
		'plugin'  => array( 'fluentform', 'fluentformpro' ),
		'api'     => 'fluent-forms',
		'filter'  => array( 'inbox' => 'Received', 'spam' => 'Spam', 'trashed' => 'Trash' ),
		'width'   => '100px',
		'actions' => array(
			$ui( 'status', array( 'label' => __( 'Mark as read', 'minn-admin' ), 'body' => array( 'status' => 'read' ), 'when' => 'unread' ) ),
			$ui( 'status', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'body' => array( 'status' => 'spam' ), 'confirm' => __( 'Mark this entry as spam? Find it under the Spam filter.', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'status', array( 'label' => __( 'Not spam', 'minn-admin' ), 'body' => array( 'status' => 'unread' ), 'when' => 'spam' ) ),
			$ui( 'status', array( 'label' => __( 'Trash entry', 'minn-admin' ), 'body' => array( 'status' => 'trashed' ), 'confirm' => __( 'Move this entry to trash?', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'status', array( 'label' => __( 'Restore', 'minn-admin' ), 'body' => array( 'status' => 'unread' ), 'when' => 'trashed' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'when' => 'trashed' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'when' => 'spam' ) ),
			array(
				'label' => __( 'Open in Fluent Forms ↗', 'minn-admin' ),
				// Detail modal also carries adminUrl with the form-scoped entry deep link.
				'href'  => admin_url( 'admin.php?page=fluent_forms&route=entries#/entries/{id}' ),
			),
		),
		'bulk'    => array(
			$ui( 'status', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'body' => array( 'status' => 'spam' ), 'confirm' => __( 'Mark the selected entries as spam?', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'status', array( 'label' => __( 'Trash', 'minn-admin' ), 'body' => array( 'status' => 'trashed' ), 'confirm' => __( 'Move the selected entries to trash?', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'status', array( 'label' => __( 'Restore', 'minn-admin' ), 'body' => array( 'status' => 'unread' ), 'when' => 'trashed' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected entries permanently?', 'minn-admin' ), 'danger' => true, 'when' => 'trashed' ) ),
		),
		// A row opens the form's emails and confirmation page
		// (fluent-forms-emails.php), for the people who manage forms.
		'manage'  => minn_admin_forms_manage( 'fluent-forms', __( 'Edit in Fluent Forms ↗', 'minn-admin' ), admin_url( 'admin.php?page=fluent_forms&route=editor&form_id={id}' ), array(
			array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
			array( 'key' => 'date', 'label' => __( 'Updated', 'minn-admin' ), 'format' => 'ago' ),
		), array( 'open' => array( 'route' => 'fluent-forms/form/{id}' ) ) ),
	) );
} );
