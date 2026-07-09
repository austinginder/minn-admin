<?php
/**
 * Bundled adapter: Fluent Forms entries.
 *
 * Fluent ships fluentform/v1 with full submissions CRUD, but responses use a
 * Laravel paginator envelope and store field values as a JSON `response`
 * blob — Minn's collection primitives want { items, total } plus a labeled
 * detail. This shim reads via their tables/API-shaped data and normalizes.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

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

/**
 * Field labels for a form id, keyed by input name.
 *
 * @param int $form_id Form ID.
 * @return array<string,string>
 */
function minn_admin_fluent_forms_labels( $form_id ) {
	global $wpdb;
	$table = $wpdb->prefix . 'fluentform_forms';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$raw   = $wpdb->get_var( $wpdb->prepare( "SELECT form_fields FROM `{$table}` WHERE id = %d", $form_id ) );
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
		if ( is_array( $v ) ) {
			// Name fields etc. flatten to "First Last".
			$flat = array();
			array_walk_recursive( $v, function ( $leaf ) use ( &$flat ) {
				if ( '' !== trim( (string) $leaf ) ) {
					$flat[] = (string) $leaf;
				}
			} );
			$out[ $k ] = implode( ' ', $flat );
		} else {
			$out[ $k ] = (string) $v;
		}
	}
	return $out;
}

/**
 * Build a list-row summary from a response map.
 *
 * @param array $map Field map.
 * @return string
 */
function minn_admin_fluent_forms_summary( $map ) {
	$parts = array();
	foreach ( $map as $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			continue;
		}
		$parts[] = $v;
		if ( count( $parts ) >= 3 ) {
			break;
		}
	}
	return $parts ? implode( ' · ', $parts ) : '(empty entry)';
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_fluent_forms_ready() ) {
		return $surfaces;
	}
	if ( ! minn_admin_fluent_forms_can_view() ) {
		return $surfaces;
	}

	$surfaces['fluent-forms'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'sub'        => 'Fluent Forms',
		'icon'       => 'inbox',
		'cap'        => 'read',
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'minn-admin/v1/fluent-forms/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/fluent-forms/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => 'Form' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/fluent-forms/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Delete entry',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}',
					'confirm' => 'Delete this entry permanently?',
					'danger'  => true,
				),
				array(
					'label' => 'Open in Fluent Forms ↗',
					// Detail modal also carries adminUrl with the form-scoped entry deep link.
					'href'  => admin_url( 'admin.php?page=fluent_forms&route=entries#/entries/{id}' ),
				),
			),
		),
		'manage'     => array(
			'viewLabel' => 'Forms',
			'route'     => 'minn-admin/v1/fluent-forms/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Form', 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => 'Entries', 'format' => 'num' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'date', 'label' => 'Updated', 'format' => 'ago' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => 'Edit in Fluent Forms ↗',
					'href'  => admin_url( 'admin.php?page=fluent_forms&route=editor&form_id={id}' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_fluent_forms_ready() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/fluent-forms/forms', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_fluent_forms_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$forms_table = $wpdb->prefix . 'fluentform_forms';
			$subs_table  = $wpdb->prefix . 'fluentform_submissions';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived tables.
			$rows = $wpdb->get_results(
				"SELECT f.id, f.title, f.status, f.updated_at,
					(SELECT COUNT(*) FROM `{$subs_table}` s WHERE s.form_id = f.id) AS entries
				 FROM `{$forms_table}` f
				 ORDER BY f.title ASC"
			);
			// phpcs:enable
			$manage = ! empty( $request['manage'] );
			$out    = array();
			foreach ( (array) $rows as $r ) {
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
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-forms/entries', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_fluent_forms_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$subs_table  = $wpdb->prefix . 'fluentform_submissions';
			$forms_table = $wpdb->prefix . 'fluentform_forms';
			$per_page    = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page        = max( 1, (int) ( $request['page'] ?: 1 ) );

			$where = array( '1=1' );
			$args  = array();
			if ( $request['form_id'] ) {
				$where[] = 's.form_id = %d';
				$args[]  = (int) $request['form_id'];
			}
			if ( $request['search'] ) {
				$like    = '%' . $wpdb->esc_like( $request['search'] ) . '%';
				$where[] = 's.response LIKE %s';
				$args[]  = $like;
			}
			$where_sql = implode( ' AND ', $where );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$subs_table}` s WHERE {$where_sql}",
				$args
			) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT s.id, s.form_id, s.response, s.status, s.created_at, f.title AS form_title
				 FROM `{$subs_table}` s
				 LEFT JOIN `{$forms_table}` f ON f.id = s.form_id
				 WHERE {$where_sql}
				 ORDER BY s.id DESC
				 LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$items = array();
			foreach ( (array) $rows as $r ) {
				$map     = minn_admin_fluent_forms_response_map( $r->response );
				$items[] = array(
					'id'         => (int) $r->id,
					'form_id'    => (int) $r->form_id,
					'summary'    => minn_admin_fluent_forms_summary( $map ),
					'form_title' => $r->form_title ?: ( 'Form #' . $r->form_id ),
					'status'     => $r->status ? (string) $r->status : 'unread',
					// Fluent stores site-local datetimes; timeAgo treats bare
					// strings as UTC, so leave as local-looking ISO without Z.
					'date'       => $r->created_at ? str_replace( ' ', 'T', (string) $r->created_at ) : '',
				);
			}

			return rest_ensure_response( array(
				'items' => $items,
				'total' => $total,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-forms/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_fluent_forms_can_view',
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$subs_table = $wpdb->prefix . 'fluentform_submissions';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM `{$subs_table}` WHERE id = %d",
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found.', array( 'status' => 404 ) );
				}

				$map    = minn_admin_fluent_forms_response_map( $row->response );
				$labels = minn_admin_fluent_forms_labels( (int) $row->form_id );
				$answers = array();
				foreach ( $map as $key => $val ) {
					if ( '' === trim( $val ) ) {
						continue;
					}
					$label = $labels[ $key ] ?? ucwords( str_replace( array( '_', '-' ), ' ', $key ) );
					$answers[] = array(
						'label' => $label,
						'value' => $val,
						'type'  => ( false !== strpos( $key, 'email' ) || is_email( $val ) ) ? 'email'
							: ( ( 0 === strpos( $val, 'http' ) ) ? 'url' : 'text' ),
					);
				}

				$forms_table = $wpdb->prefix . 'fluentform_forms';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$form_title  = $wpdb->get_var( $wpdb->prepare(
					"SELECT title FROM `{$forms_table}` WHERE id = %d",
					(int) $row->form_id
				) );

				$meta   = array();
				$meta[] = array(
					'label' => 'Submitted',
					'value' => $row->created_at
						? date_i18n( 'M j, Y g:i a', strtotime( $row->created_at ) )
						: '',
				);
				if ( $form_title ) {
					$meta[] = array( 'label' => 'Form', 'value' => $form_title );
				}
				if ( ! empty( $row->source_url ) ) {
					$meta[] = array( 'label' => 'Source', 'value' => $row->source_url, 'type' => 'url' );
				}
				if ( ! empty( $row->ip ) ) {
					$meta[] = array( 'label' => 'IP', 'value' => $row->ip );
				}
				if ( ! empty( $row->browser ) || ! empty( $row->device ) ) {
					$meta[] = array(
						'label' => 'Client',
						'value' => trim( ( $row->device ?: '' ) . ' · ' . ( $row->browser ?: '' ), ' ·' ),
					);
				}

				return rest_ensure_response( array(
					'kind'     => 'entry',
					'title'    => $form_title ?: ( 'Form #' . (int) $row->form_id ),
					'status'   => $row->status ? (string) $row->status : 'unread',
					'sections' => array(
						array( 'title' => 'Responses', 'rows' => $answers ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url(
						'admin.php?page=fluent_forms&route=entries&form_id=' . (int) $row->form_id
						. '#/entries/' . (int) $row->id
					),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function () {
				return current_user_can( 'fluentform_manage_entries' )
					|| current_user_can( 'fluentform_full_access' )
					|| current_user_can( 'manage_options' );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$id         = (int) $request['id'];
				$subs_table = $wpdb->prefix . 'fluentform_submissions';
				$det_table  = $wpdb->prefix . 'fluentform_entry_details';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM `{$subs_table}` WHERE id = %d",
					$id
				) );
				if ( ! $exists ) {
					return new WP_Error( 'not_found', 'Entry not found.', array( 'status' => 404 ) );
				}
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->delete( $det_table, array( 'submission_id' => $id ), array( '%d' ) );
				$wpdb->delete( $subs_table, array( 'id' => $id ), array( '%d' ) );
				// phpcs:enable
				return rest_ensure_response( array( 'id' => $id, 'deleted' => true ) );
			},
		),
	) );
} );
