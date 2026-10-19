<?php
/**
 * Bundled adapter: Formidable Forms — forms family (entries + forms).
 *
 * Formidable stores entries in {prefix}frm_items with per-answer rows in
 * frm_item_metas (arrays serialized, but read here ONLY through
 * FrmEntry::getOne — their model owns the unserialize). Labels come from
 * FrmField's models at runtime, and delete routes through
 * FrmEntry::destroy — their complete flow (metas go too, their
 * before/after hooks fire). Formidable has no entry trash: delete is
 * permanent and the confirm says so.
 *
 * The entries are a kernel resource (docs/adapter-kernel.md): the kernel
 * reads the item table and owns the routes, gates, paging, search and errors;
 * this file supplies the rows, the detail and the delete call.
 *
 * Clock: entries stamp current_time( 'mysql', 1 ) — UTC (unlike
 * Forminator's site-local stamps), so columns carry utc: true.
 *
 * Caps mirror the plugin: FrmAppHelper::permission_nonce_error passes
 * anyone with the granular capability OR the administrator role, so the
 * gates here are frm_view_entries / frm_delete_entries with the same
 * administrator fallback.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_formidable_active() {
	return class_exists( 'FrmEntry' ) && Minn_Admin_Kernel_SQL::exists( 'frm_items' );
}

/** Their permission model: granular cap OR administrator. */
function minn_admin_formidable_can( $cap = 'frm_view_entries' ) {
	return current_user_can( $cap ) || current_user_can( 'administrator' );
}

/** Field types that carry no answer (chrome, not data). */
function minn_admin_formidable_skip_types() {
	return array( 'captcha', 'html', 'divider', 'end_divider', 'break', 'summary', 'submit' );
}

/**
 * Input fields for a form via Formidable's own models:
 * [field_id => label], in form order.
 *
 * @param int $form_id Form id.
 * @return array<int,string>
 */
function minn_admin_formidable_fields( $form_id ) {
	static $cache = array();
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$out = array();
	try {
		foreach ( (array) FrmField::get_all_for_form( (int) $form_id ) as $field ) {
			if ( in_array( (string) $field->type, minn_admin_formidable_skip_types(), true ) ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) $field->name ) );
			$out[ (int) $field->id ] = $label ? $label : 'Field ' . $field->id;
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	$cache[ $form_id ] = $out;
	return $out;
}

/** Form titles map: [id => name] (published, non-template). */
function minn_admin_formidable_titles() {
	static $titles = null;
	if ( null === $titles ) {
		$titles = array();
		$rows   = Minn_Admin_Kernel_SQL::select( 'frm_forms', array( 'id', 'name' ), array( 'is_template' => 0, 'status' => 'published', 'parent_form_id' => array( 'any' => array( 0, null ) ) ) );
		foreach ( $rows as $r ) {
			$titles[ (int) $r['id'] ] = (string) $r['name'];
		}
	}
	return $titles;
}

/**
 * Answers for one entry as [field_id => flat string], through their own
 * entry model (its meta layer owns the serialized array shapes).
 *
 * @param int $entry_id Entry id.
 * @return array<int,string>
 */
function minn_admin_formidable_answers( $entry_id ) {
	$out = array();
	try {
		$entry = FrmEntry::getOne( (int) $entry_id, true );
		if ( $entry && isset( $entry->metas ) ) {
			foreach ( (array) $entry->metas as $fid => $v ) {
				$out[ (int) $fid ] = trim( (string) ( is_array( $v ) ? minn_admin_forms_flat( $v ) : $v ) );
			}
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	return $out;
}

/**
 * The rows this surface ever names: submitted, top-level entries. Their table
 * also holds drafts and repeater child rows, which belong to Formidable's own
 * workflows.
 */
function minn_admin_formidable_submitted() {
	return array( 'is_draft' => 0, 'parent_item_id' => 0 );
}

/**
 * One entry the routes may read or delete, or null. A route that only ever
 * names entries must not read a draft, and destroying a repeater child row
 * would take one sub-row out of a live parent entry; neither is ever listed.
 *
 * @param int $id Entry id.
 * @return array|null
 */
function minn_admin_formidable_get( $id ) {
	$row = Minn_Admin_Kernel_SQL::row( 'frm_items', 'id', (int) $id, array( 'id', 'form_id', 'created_at', 'ip', 'is_draft', 'parent_item_id' ) );
	return $row && empty( $row['is_draft'] ) && (int) $row['parent_item_id'] <= 0 ? $row : null;
}

/** An entry's list row: its first answers as the summary. */
function minn_admin_formidable_row( array $row ) {
	$form_id = (int) $row['form_id'];
	$titles  = minn_admin_formidable_titles();
	return array(
		'id'         => (int) $row['id'],
		'summary'    => minn_admin_forms_summary( minn_admin_formidable_answers( (int) $row['id'] ), minn_admin_formidable_fields( $form_id ) ),
		'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
		'date'       => $row['created_at'],
	);
}

/** An entry's detail: every answer, then the submission's own facts. */
function minn_admin_formidable_view( array $entry ) {
	$id      = (int) $entry['id'];
	$form_id = (int) $entry['form_id'];
	$titles  = minn_admin_formidable_titles();
	$rows    = minn_admin_forms_answer_rows( minn_admin_formidable_fields( $form_id ), minn_admin_formidable_answers( $id ) );
	$meta    = minn_admin_forms_meta( isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id, $id, date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( $entry['created_at'] ) ) ) );
	if ( ! empty( $entry['ip'] ) ) {
		$meta[] = array( 'label' => 'IP', 'value' => (string) $entry['ip'] );
	}
	return minn_admin_forms_entry( $rows, $meta, admin_url( 'admin.php?page=formidable-entries&frm_action=show&id=' . $id ) );
}

/** Server-built model for the surface status card. */
function minn_admin_formidable_status_model() {
	$submitted = minn_admin_formidable_submitted();
	// created_at is current_time( 'mysql', 1 ): UTC, so the chart buckets each
	// row onto the site's day and the week's window is UTC too. Formidable
	// keeps no spam: every row counts into the one series.
	$n    = Minn_Admin_Kernel::inbox_counts( array(
		'resource' => 'formidable/entries',
		'received' => $submitted,
		'chart'    => array( 'column' => 'created_at', 'clock' => 'utc', 'where' => $submitted, 'split' => 'form_id', 'secondary' => array() ),
	) );
	$week = Minn_Admin_Kernel_SQL::count( 'frm_items', $submitted + array(
		'created_at' => array( 'op' => '>=', 'value' => gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) ) ),
	) );
	return minn_admin_forms_card(
		array(
			'label' => __( 'Entries', 'minn-admin' ),
			'value' => number_format_i18n( $n['received'] ),
			'hint'  => sprintf(
				/* translators: %s: number of entries received in the last 7 days. */
				__( '%s in the last 7 days', 'minn-admin' ),
				number_format_i18n( $week )
			),
		),
		count( minn_admin_formidable_titles() ),
		$n['days'],
		array( 'label' => __( 'Open Formidable ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=formidable-entries' ) )
	);
}

minn_admin_resource( 'formidable/entries', function () {
	return array(
		'active'  => 'minn_admin_formidable_active',
		'gates'   => array(
			'view'   => 'minn_admin_formidable_can',
			'delete' => function () {
				return minn_admin_formidable_can( 'frm_delete_entries' );
			},
		),
		'labels'  => array(
			'not_found' => __( 'Entry not found', 'minn-admin' ),
		),
		'source'  => array(
			'table'   => 'frm_items',
			'key'     => 'id',
			'columns' => array( 'id', 'form_id', 'created_at' ),
			'get'     => 'minn_admin_formidable_get',
			// Gone means gone from the table, not only from what get() shows.
			'exists'  => function ( $id ) {
				return null !== Minn_Admin_Kernel_SQL::row( 'frm_items', 'id', (int) $id, array( 'id' ) );
			},
		),
		'list'    => array(
			// The list has no filter of its own; a one-value filter is the
			// kernel's fixed condition, whatever a request asks for.
			'filter' => array(
				'param'   => 'status',
				'values'  => array( 'submitted' => minn_admin_formidable_submitted() ),
				'default' => 'submitted',
			),
			'tab'    => array( 'param' => 'form_id', 'column' => 'form_id' ),
			'search' => array( 'param' => 'search', 'meta' => array( 'table' => 'frm_item_metas', 'key' => 'item_id', 'value' => 'meta_value' ) ),
			'range'  => array( 'column' => 'created_at', 'clock' => 'utc' ),
			// Newest submission first by the date Formidable stored, so an entry
			// added out of order (an import) lands where it belongs.
			'order'  => array( 'column' => 'created_at', 'dir' => 'DESC' ),
			'row'    => 'minn_admin_formidable_row',
			'fields' => array(
				'id'         => 'int',
				'summary'    => 'text',
				'form_title' => 'text',
				// current_time( 'mysql', 1 ): the descriptor's column says utc.
				'date'       => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'naked' ),
			),
		),
		'item'    => array( 'view' => 'minn_admin_formidable_view' ),
		'actions' => array(
			// Formidable has no entry trash: delete is permanent from any state.
			// Their complete flow: metas deleted, their hooks fire.
			'delete' => minn_admin_forms_delete( function ( $id ) {
				return FrmEntry::destroy( $id ) ? true : new WP_Error( 'delete_failed', __( 'Formidable could not delete the entry.', 'minn-admin' ), array( 'status' => 500 ) );
			}, __( 'Formidable could not delete the entry.', 'minn-admin' ), array( 'gate' => 'delete' ) ),
		),
	);
} );

minn_admin_endpoint( 'formidable/forms', array(
	'active' => 'minn_admin_formidable_active',
	// Their gate takes a capability, so it is asked with its own default:
	// the kernel asks an endpoint's gate with the request.
	'gate'   => function () {
		return minn_admin_formidable_can();
	},
	'run'    => function ( WP_REST_Request $request ) {
		$manage = ! empty( $request['manage'] );
		$out    = array();
		foreach ( minn_admin_formidable_titles() as $id => $title ) {
			$row = array( 'id' => (int) $id, 'title' => $title );
			if ( $manage ) {
				$row['entries'] = Minn_Admin_Kernel_SQL::count( 'frm_items', array( 'form_id' => (int) $id ) + minn_admin_formidable_submitted() );
			}
			$out[] = $row;
		}
		return $out;
	},
) );

minn_admin_endpoint( 'formidable/status', array(
	'active' => 'minn_admin_formidable_active',
	// Their gate takes a capability, so it is asked with its own default:
	// the kernel asks an endpoint's gate with the request.
	'gate'   => function () {
		return minn_admin_formidable_can();
	},
	'run'    => 'minn_admin_formidable_status_model',
) );

minn_admin_add_surface( 'formidable', function () {
	return minn_admin_formidable_active() && minn_admin_formidable_can();
}, function () {
	$ui = minn_admin_forms_ui( 'formidable/entries' );
	return minn_admin_forms_surface( 'formidable/entries', array(
		'sub'     => 'Formidable',
		'plugin'  => array( 'formidable', 'formidable-pro' ),
		'api'     => 'formidable',
		// The row carries no status, so there is no Status column; created_at
		// is current_time( 'mysql', 1 ), UTC.
		'pill'    => false,
		'utc'     => true,
		'actions' => array(
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? Formidable has no entry trash — there is no undo.', 'minn-admin' ), 'danger' => true ) ),
		),
		'bulk'    => array(
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected entries permanently? There is no undo.', 'minn-admin' ), 'danger' => true ) ),
		),
		'manage'  => minn_admin_forms_manage( 'formidable', __( 'Edit in Formidable ↗', 'minn-admin' ), admin_url( 'admin.php?page=formidable&frm_action=edit&id={id}' ) ),
	) );
} );
