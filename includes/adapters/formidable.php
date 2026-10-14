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
	global $wpdb;
	if ( ! class_exists( 'FrmEntry' ) ) {
		return false;
	}
	$table = $wpdb->prefix . 'frm_items';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
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
		global $wpdb;
		$titles = array();
		$rows   = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}frm_forms WHERE is_template = 0 AND status = 'published' AND ( parent_form_id = 0 OR parent_form_id IS NULL )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $rows as $r ) {
			$titles[ (int) $r->id ] = (string) $r->name;
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
				if ( is_array( $v ) ) {
					$flat = array();
					array_walk_recursive( $v, function ( $leaf ) use ( &$flat ) {
						// Same rule as the other flatteners: an absolute path
						// on this server is the upload's location, not its
						// answer, and printing it leaks the directory layout.
						if ( '' !== trim( (string) $leaf ) && ! minn_admin_is_server_path( $leaf ) ) {
							$flat[] = (string) $leaf;
						}
					} );
					$v = implode( ', ', $flat );
				}
				$out[ (int) $fid ] = trim( (string) $v );
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
	$answers = minn_admin_formidable_answers( (int) $row['id'] );
	$parts   = array();
	foreach ( minn_admin_formidable_fields( $form_id ) as $fid => $label ) {
		if ( isset( $answers[ $fid ] ) && '' !== $answers[ $fid ] ) {
			$parts[] = $answers[ $fid ];
		}
		if ( count( $parts ) >= 3 ) {
			break;
		}
	}
	return array(
		'id'         => (int) $row['id'],
		'summary'    => $parts ? implode( ' · ', $parts ) : __( '(empty entry)', 'minn-admin' ),
		'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
		'date'       => $row['created_at'],
	);
}

/** An entry's detail: every answer, then the submission's own facts. */
function minn_admin_formidable_view( array $entry ) {
	$id      = (int) $entry['id'];
	$form_id = (int) $entry['form_id'];
	$fields  = minn_admin_formidable_fields( $form_id );
	$answers = minn_admin_formidable_answers( $id );
	$titles  = minn_admin_formidable_titles();

	$rows = array();
	foreach ( $fields as $fid => $label ) {
		$rows[] = array(
			'label' => $label,
			'value' => isset( $answers[ $fid ] ) && '' !== $answers[ $fid ] ? $answers[ $fid ] : '—',
		);
	}
	foreach ( $answers as $fid => $value ) {
		if ( ! isset( $fields[ $fid ] ) && '' !== $value ) {
			$rows[] = array(
				'label' => __( 'Field ', 'minn-admin' ) . $fid,
				'value' => $value,
			);
		}
	}
	$meta = array(
		array( 'label' => __( 'Form', 'minn-admin' ), 'value' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id ),
		array( 'label' => __( 'Entry', 'minn-admin' ), 'value' => '#' . $id ),
		array( 'label' => __( 'Submitted', 'minn-admin' ), 'value' => date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( $entry['created_at'] ) ) ) ),
	);
	if ( ! empty( $entry['ip'] ) ) {
		$meta[] = array( 'label' => 'IP', 'value' => (string) $entry['ip'] );
	}
	return array(
		'kind'     => 'entry',
		'sections' => array(
			array( 'title' => __( 'Answers', 'minn-admin' ), 'rows' => $rows ),
			array( 'title' => __( 'Submission', 'minn-admin' ), 'rows' => $meta ),
		),
		'adminUrl' => admin_url( 'admin.php?page=formidable-entries&frm_action=show&id=' . $id ),
	);
}

/** Server-built model for the surface status card. */
function minn_admin_formidable_status_model() {
	$submitted = minn_admin_formidable_submitted();
	// created_at is current_time( 'mysql', 1 ): UTC, so the chart buckets each
	// row onto the site's day and the week's window is UTC too. Formidable
	// keeps no spam: every row counts into the one series.
	$n    = Minn_Admin_Kernel::inbox_counts( array(
		'table'    => 'frm_items',
		'received' => $submitted,
		'chart'    => array( 'column' => 'created_at', 'clock' => 'utc', 'where' => $submitted, 'split' => 'form_id', 'secondary' => array() ),
	) );
	$week = Minn_Admin_Kernel_SQL::count( 'frm_items', $submitted + array(
		'created_at' => array( 'op' => '>=', 'value' => gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) ) ),
	) );
	return array(
		'rows'    => array(
			array(
				'label' => __( 'Entries', 'minn-admin' ),
				'value' => number_format_i18n( $n['received'] ),
				'hint'  => sprintf(
					/* translators: %s: number of entries received in the last 7 days. */
					__( '%s in the last 7 days', 'minn-admin' ),
					number_format_i18n( $week )
				),
			),
			array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => number_format_i18n( count( minn_admin_formidable_titles() ) ) ),
		),
		'chart'   => minn_admin_chart_build( $n['days'], __( 'Entries', 'minn-admin' ) ),
		'actions' => array(
			array( 'label' => __( 'Open Formidable ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=formidable-entries' ) ),
		),
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
			'delete' => array(
				'method'    => 'DELETE',
				'gate'      => 'delete',
				// Their complete flow: metas deleted, their hooks fire.
				'run'       => function ( $id ) {
					return FrmEntry::destroy( $id ) ? true : new WP_Error( 'delete_failed', __( 'Formidable could not delete the entry.', 'minn-admin' ), array( 'status' => 500 ) );
				},
				'expect'    => 'gone',
				'fail_code' => 'delete_failed',
				'fail'      => __( 'Formidable could not delete the entry.', 'minn-admin' ),
				'done'      => __( 'Entry deleted permanently.', 'minn-admin' ),
			),
		),
	);
} );

minn_admin_endpoint( 'formidable/forms', array(
	'active' => 'minn_admin_formidable_active',
	'gate'   => 'minn_admin_formidable_can',
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
	'gate'   => 'minn_admin_formidable_can',
	'run'    => 'minn_admin_formidable_status_model',
) );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_formidable_active() || ! minn_admin_formidable_can() ) {
		return $surfaces;
	}
	$ui = function ( $slug, $ui ) {
		return Minn_Admin_Kernel::ui( 'formidable/entries', $slug, $ui );
	};

	$surfaces['formidable'] = array(
		'label'      => __( 'Forms', 'minn-admin' ),
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'Formidable',
		'plugin'     => array( 'formidable', 'formidable-pro' ),
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is the filter above (their cap model)
		'status'     => array( 'route' => 'minn-admin/v1/formidable/status' ),
		// Route, paging, search and the status-chart day range come from the
		// resource, so they cannot drift from it.
		'collection' => Minn_Admin_Kernel::collection( 'formidable/entries', array(
			'viewLabel' => __( 'Entries', 'minn-admin' ),
			'tabs'      => array(
				'route'    => 'minn-admin/v1/formidable/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => __( 'All entries', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => __( 'Entry', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => __( 'Form', 'minn-admin' ) ),
				// created_at is current_time( 'mysql', 1 ) — UTC.
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/formidable/entries/{id}',
			),
			'actions'   => array(
				$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? Formidable has no entry trash — there is no undo.', 'minn-admin' ), 'danger' => true ) ),
			),
			'bulk'      => array(
				$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected entries permanently? There is no undo.', 'minn-admin' ), 'danger' => true ) ),
			),
		) ),
		'manage'     => array(
			'viewLabel' => __( 'Forms', 'minn-admin' ),
			'route'     => 'minn-admin/v1/formidable/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Form', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => __( 'Entries', 'minn-admin' ), 'format' => 'num' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => __( 'Edit in Formidable ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=formidable&frm_action=edit&id={id}' ),
				),
			),
		),
	);
	return $surfaces;
} );
