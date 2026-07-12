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
						if ( '' !== trim( (string) $leaf ) ) {
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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_formidable_active() || ! minn_admin_formidable_can() ) {
		return $surfaces;
	}

	$surfaces['formidable'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'Formidable',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is the filter above (their cap model)
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'minn-admin/v1/formidable/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/formidable/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => 'Form' ),
				// created_at is current_time( 'mysql', 1 ) — UTC.
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/formidable/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/formidable/entries/{id}',
					'confirm' => 'Delete this entry permanently? Formidable has no entry trash — there is no undo.',
					'danger'  => true,
				),
			),
		),
		'manage'     => array(
			'viewLabel' => 'Forms',
			'route'     => 'minn-admin/v1/formidable/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Form', 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => 'Entries', 'format' => 'num' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => 'Edit in Formidable ↗',
					'href'  => admin_url( 'admin.php?page=formidable&frm_action=edit&id={id}' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_formidable_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/formidable/forms', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return minn_admin_formidable_can();
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$manage = ! empty( $request['manage'] );
			$out    = array();
			foreach ( minn_admin_formidable_titles() as $id => $title ) {
				$row = array( 'id' => (int) $id, 'title' => $title );
				if ( $manage ) {
					$row['entries'] = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}frm_items WHERE form_id = %d AND is_draft = 0 AND parent_item_id = 0", // phpcs:ignore
						$id
					) );
				}
				$out[] = $row;
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/formidable/entries', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return minn_admin_formidable_can();
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$items_t  = $wpdb->prefix . 'frm_items';
			$metas_t  = $wpdb->prefix . 'frm_item_metas';

			// Submitted entries only (drafts and child/repeater rows are
			// Formidable's own workflows).
			$where = 'WHERE e.is_draft = 0 AND e.parent_item_id = 0';
			$args  = array();
			if ( $request['form_id'] ) {
				$where .= ' AND e.form_id = %d';
				$args[] = (int) $request['form_id'];
			}
			if ( $request['search'] ) {
				$where .= " AND EXISTS ( SELECT 1 FROM {$metas_t} m WHERE m.item_id = e.id AND m.meta_value LIKE %s )";
				$args[] = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
			}
			$total = (int) $wpdb->get_var( $args
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$items_t} e {$where}", ...$args ) // phpcs:ignore
				: "SELECT COUNT(*) FROM {$items_t} e {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.id, e.form_id, e.created_at FROM {$items_t} e {$where} ORDER BY e.id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				...array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );

			$titles = minn_admin_formidable_titles();
			$items  = array();
			foreach ( (array) $rows as $row ) {
				$form_id = (int) $row->form_id;
				$fields  = minn_admin_formidable_fields( $form_id );
				$answers = minn_admin_formidable_answers( (int) $row->id );
				$parts   = array();
				foreach ( $fields as $fid => $label ) {
					if ( isset( $answers[ $fid ] ) && '' !== $answers[ $fid ] ) {
						$parts[] = $answers[ $fid ];
					}
					if ( count( $parts ) >= 3 ) {
						break;
					}
				}
				$items[] = array(
					'id'         => (int) $row->id,
					'summary'    => $parts ? implode( ' · ', $parts ) : '(empty entry)',
					'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
					'date'       => str_replace( ' ', 'T', (string) $row->created_at ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/formidable/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return minn_admin_formidable_can();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$entry = FrmEntry::getOne( (int) $request['id'], true );
				if ( ! $entry ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				$form_id = (int) $entry->form_id;
				$fields  = minn_admin_formidable_fields( $form_id );
				$answers = minn_admin_formidable_answers( (int) $entry->id );
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
							'label' => 'Field ' . $fid,
							'value' => $value,
						);
					}
				}
				$meta = array(
					array( 'label' => 'Form', 'value' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id ),
					array( 'label' => 'Entry', 'value' => '#' . (int) $entry->id ),
					array( 'label' => 'Submitted', 'value' => date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( $entry->created_at ) ) ) ),
				);
				if ( ! empty( $entry->ip ) ) {
					$meta[] = array( 'label' => 'IP', 'value' => (string) $entry->ip );
				}
				return rest_ensure_response( array(
					'kind'     => 'entry',
					'sections' => array(
						array( 'title' => 'Answers', 'rows' => $rows ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=formidable-entries&frm_action=show&id=' . (int) $entry->id ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function () {
				return minn_admin_formidable_can( 'frm_delete_entries' );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$id = (int) $request['id'];
				if ( ! FrmEntry::getOne( $id ) ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				// Their complete flow: metas deleted, their hooks fire.
				$result = FrmEntry::destroy( $id );
				if ( ! $result ) {
					return new WP_Error( 'delete_failed', 'Formidable could not delete the entry.', array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => 'Entry deleted permanently.' ) );
			},
		),
	) );
} );
