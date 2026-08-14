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
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT form_id FROM `{$wpdb->prefix}fluentform_submissions` WHERE id = %d",
		(int) $entry_id
	) );
}

/**
 * 403 unless the caller may act on the form behind this submission.
 */
function minn_admin_fluent_forms_guard_entry( $entry_id, $permission = 'fluentform_entries_viewer' ) {
	$form_id = minn_admin_fluent_forms_entry_form_id( $entry_id );
	if ( ! $form_id ) {
		return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( ! minn_admin_fluent_forms_can_form( $form_id, $permission ) ) {
		return new WP_Error( 'forbidden', __( 'You cannot access entries for that form.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	return $form_id;
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
	return $parts ? implode( ' · ', $parts ) : __( '(empty entry)', 'minn-admin' );
}

/** Server-built model for the surface status card (SureForms parity). */
function minn_admin_fluent_forms_status_model() {
	global $wpdb;
	$subs  = $wpdb->prefix . 'fluentform_submissions';
	$forms = $wpdb->prefix . 'fluentform_forms';
	// Counters follow the same scope as the list: Fluent scopes its own
	// admin-bar and menu counts the same way, so a manager provisioned for one
	// form must not learn the whole estate's volume.
	$scope  = minn_admin_fluent_forms_scope();
	$clause = '';
	$params = array();
	if ( is_array( $scope ) ) {
		if ( ! $scope ) {
			return array(
				'rows'    => array(
					array( 'label' => __( 'Unread entries', 'minn-admin' ), 'value' => '0', 'hint' => __( '0 total', 'minn-admin' ) ),
					array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => '0' ),
				),
				'actions' => array(
					array( 'label' => __( 'Open Fluent Forms ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=fluent_forms_all_entries' ) ),
				),
			);
		}
		$clause = ' AND form_id IN (' . implode( ',', array_fill( 0, count( $scope ), '%d' ) ) . ')';
		$params = $scope;
	}
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$count = function ( $where ) use ( $wpdb, $subs, $clause, $params ) {
		$sql = "SELECT COUNT(*) FROM {$subs} WHERE {$where}{$clause}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	};
	$unread = $count( "status = 'unread'" );
	$total  = $count( "status <> 'trashed'" );
	$spam   = $count( "status = 'spam'" );
	$nforms = is_array( $scope ) ? count( $scope ) : (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$forms}" );
	// phpcs:enable
	$hint = number_format_i18n( $total ) . ' total';
	if ( $spam ) {
		$hint .= ', ' . number_format_i18n( $spam ) . ' spam';
	}
	return array(
		'rows'    => array(
			array( 'label' => __( 'Unread entries', 'minn-admin' ), 'value' => number_format_i18n( $unread ), 'hint' => $hint ),
			array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => number_format_i18n( $nforms ) ),
		),
		'actions' => array(
			array( 'label' => __( 'Open Fluent Forms ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=fluent_forms_all_entries' ) ),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_fluent_forms_ready() ) {
		return $surfaces;
	}
	if ( ! minn_admin_fluent_forms_can_view() ) {
		return $surfaces;
	}

	$surfaces['fluent-forms'] = array(
		'label'      => __( 'Forms', 'minn-admin' ),
		'family'     => 'forms',
		'status'     => array( 'route' => 'minn-admin/v1/fluent-forms/status' ),
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'Fluent Forms',
		'icon'       => 'inbox',
		'cap'        => 'read',
		'collection' => array(
			'viewLabel' => __( 'Entries', 'minn-admin' ),
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
				'allLabel' => __( 'All entries', 'minn-admin' ),
			),
			// Their status column: unread/read/spam/trashed (favorites is a separate flag).
			'filter'    => array(
				'label'   => __( 'Status', 'minn-admin' ),
				'options' => array(
					array( 'inbox', 'Received' ),
					array( 'spam', 'Spam' ),
					array( 'trashed', 'Trash' ),
				),
				'query'   => 'status={v}',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => __( 'Entry', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => __( 'Form', 'minn-admin' ) ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/fluent-forms/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'  => __( 'Mark as read', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'   => array( 'status' => 'read' ),
					'when'   => array( 'key' => 'status', 'equals' => 'unread' ),
				),
				array(
					'label'   => __( 'Mark as spam', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'    => array( 'status' => 'spam' ),
					'confirm' => __( 'Mark this entry as spam? Find it under the Spam filter.', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'inbox' ),
				),
				array(
					'label'  => __( 'Not spam', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'   => array( 'status' => 'unread' ),
					'when'   => array( 'key' => 'status', 'equals' => 'spam' ),
				),
				array(
					'label'   => __( 'Trash entry', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'    => array( 'status' => 'trashed' ),
					'confirm' => __( 'Move this entry to trash?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'inbox' ),
				),
				array(
					'label'  => __( 'Restore', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'   => array( 'status' => 'unread' ),
					'when'   => array( 'key' => 'status', 'equals' => 'trashed' ),
				),
				array(
					'label'   => __( 'Delete permanently', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}',
					'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trashed' ),
				),
				array(
					'label'   => __( 'Delete permanently', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}',
					'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'spam' ),
				),
				array(
					'label' => __( 'Open in Fluent Forms ↗', 'minn-admin' ),
					// Detail modal also carries adminUrl with the form-scoped entry deep link.
					'href'  => admin_url( 'admin.php?page=fluent_forms&route=entries#/entries/{id}' ),
				),
			),
			'bulk'      => array(
				array(
					'label'   => __( 'Mark as spam', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'    => array( 'status' => 'spam' ),
					'confirm' => __( 'Mark the selected entries as spam?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'inbox' ),
				),
				array(
					'label'   => __( 'Trash', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'    => array( 'status' => 'trashed' ),
					'confirm' => __( 'Move the selected entries to trash?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'bucket', 'equals' => 'inbox' ),
				),
				array(
					'label'  => __( 'Restore', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-forms/entries/{id}/status',
					'body'   => array( 'status' => 'unread' ),
					'when'   => array( 'key' => 'status', 'equals' => 'trashed' ),
				),
				array(
					'label'   => __( 'Delete permanently', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/fluent-forms/entries/{id}',
					'confirm' => __( 'Delete the selected entries permanently?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trashed' ),
				),
			),
		),
		'manage'     => array(
			'viewLabel' => __( 'Forms', 'minn-admin' ),
			'route'     => 'minn-admin/v1/fluent-forms/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Form', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => __( 'Entries', 'minn-admin' ), 'format' => 'num' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'date', 'label' => __( 'Updated', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => __( 'Edit in Fluent Forms ↗', 'minn-admin' ),
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

	register_rest_route( 'minn-admin/v1', '/fluent-forms/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_fluent_forms_can_view',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_fluent_forms_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-forms/forms', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_fluent_forms_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$forms_table = $wpdb->prefix . 'fluentform_forms';
			$subs_table  = $wpdb->prefix . 'fluentform_submissions';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived tables.
			// Scope the form estate the same way the entries are scoped. Fluent's
			// own dropdowns, reports and search all wrap this in
			// whereIn('id', $allowForms ?: [0]); without it a manager scoped to
			// one form still enumerates every form's title and traffic volume.
			$scope = minn_admin_fluent_forms_scope();
			if ( is_array( $scope ) && ! $scope ) {
				$rows = array();
			} elseif ( is_array( $scope ) ) {
				$in   = implode( ',', array_fill( 0, count( $scope ), '%d' ) );
				$rows = $wpdb->get_results( $wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived tables; ids bound below.
					"SELECT f.id, f.title, f.status, f.updated_at,
						(SELECT COUNT(*) FROM `{$subs_table}` s WHERE s.form_id = f.id) AS entries
					 FROM `{$forms_table}` f
					 WHERE f.id IN ({$in})
					 ORDER BY f.title ASC",
					$scope
				) );
			} else {
				$rows = $wpdb->get_results(
					"SELECT f.id, f.title, f.status, f.updated_at,
						(SELECT COUNT(*) FROM `{$subs_table}` s WHERE s.form_id = f.id) AS entries
					 FROM `{$forms_table}` f
					 ORDER BY f.title ASC"
				);
			}
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

			$bucket = sanitize_key( (string) ( $request['status'] ?: 'inbox' ) );
			if ( ! in_array( $bucket, array( 'inbox', 'spam', 'trashed' ), true ) ) {
				$bucket = 'inbox';
			}

			$where = array( '1=1' );
			$args  = array();
			if ( 'inbox' === $bucket ) {
				// Received = not spam and not trash (unread + read + favorites).
				$where[] = "s.status NOT IN ('spam','trashed')";
			} elseif ( 'spam' === $bucket ) {
				$where[] = "s.status = 'spam'";
			} else {
				$where[] = "s.status = 'trashed'";
			}
			if ( $request['form_id'] ) {
				$form_id = (int) $request['form_id'];
				if ( ! minn_admin_fluent_forms_can_form( $form_id ) ) {
					return new WP_Error( 'forbidden', __( 'You cannot access entries for that form.', 'minn-admin' ), array( 'status' => 403 ) );
				}
				$where[] = 's.form_id = %d';
				$args[]  = $form_id;
			}

			// MANDATORY scope for managers assigned to specific forms. Without
			// it an unfiltered list returns every form's submissions — names,
			// emails, phone numbers and message bodies for forms this user was
			// never granted. An empty scope means "assigned to nothing".
			$scope = minn_admin_fluent_forms_scope();
			if ( false !== $scope ) {
				if ( ! $scope ) {
					return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
				}
				$where[] = 's.form_id IN (' . implode( ',', array_fill( 0, count( $scope ), '%d' ) ) . ')';
				$args    = array_merge( $args, $scope );
			}
			if ( $request['search'] ) {
				$like    = '%' . $wpdb->esc_like( $request['search'] ) . '%';
				$where[] = 's.response LIKE %s';
				$args[]  = $like;
			}
			$where_sql = implode( ' AND ', $where );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) ( $args
				? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$subs_table}` s WHERE {$where_sql}", ...$args ) )
				: $wpdb->get_var( "SELECT COUNT(*) FROM `{$subs_table}` s WHERE {$where_sql}" ) );
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
				$status  = $r->status ? (string) $r->status : 'unread';
				$items[] = array(
					'id'         => (int) $r->id,
					'form_id'    => (int) $r->form_id,
					'summary'    => minn_admin_fluent_forms_summary( $map ),
					'form_title' => $r->form_title ?: ( 'Form #' . $r->form_id ),
					'status'     => $status,
					'bucket'     => $bucket,
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
				$guard = minn_admin_fluent_forms_guard_entry( (int) $request['id'] );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
				$subs_table = $wpdb->prefix . 'fluentform_submissions';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM `{$subs_table}` WHERE id = %d",
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}

				// Opening a detail marks unread → read (Fluent Forms' own screen semantics).
				if ( 'unread' === (string) $row->status ) {
					$wpdb->update(
						$subs_table,
						array( 'status' => 'read' ),
						array( 'id' => (int) $row->id ),
						array( '%s' ),
						array( '%d' )
					);
					$row->status = 'read';
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
					'label' => __( 'Submitted', 'minn-admin' ),
					'value' => $row->created_at
						? date_i18n( 'M j, Y g:i a', strtotime( $row->created_at ) )
						: '',
				);
				if ( $form_title ) {
					$meta[] = array( 'label' => __( 'Form', 'minn-admin' ), 'value' => $form_title );
				}
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

				return rest_ensure_response( array(
					'kind'     => 'entry',
					'title'    => $form_title ?: ( 'Form #' . (int) $row->form_id ),
					'status'   => $row->status ? (string) $row->status : 'unread',
					'sections' => array(
						array( 'title' => __( 'Responses', 'minn-admin' ), 'rows' => $answers ),
						array( 'title' => __( 'Submission', 'minn-admin' ), 'rows' => $meta ),
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
				$id    = (int) $request['id'];
				$guard = minn_admin_fluent_forms_guard_entry( $id, 'fluentform_manage_entries' );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
				$subs_table = $wpdb->prefix . 'fluentform_submissions';
				$det_table  = $wpdb->prefix . 'fluentform_entry_details';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, status, form_id FROM `{$subs_table}` WHERE id = %d",
					$id
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Permanent delete is for trash/spam only (Received → Trash first).
				if ( ! in_array( (string) $row->status, array( 'trashed', 'spam' ), true ) ) {
					return new WP_Error( 'not_trashed', __( 'Move the entry to trash (or spam) before deleting permanently.', 'minn-admin' ), array( 'status' => 400 ) );
				}

				// Delete through Fluent Forms' own service. A submission is more
				// than its two obvious tables: their deleteEntries() removes the
				// files the entry uploaded, clears submission meta, the activity
				// log (which holds rendered notification bodies and recipient
				// addresses) and any order, transaction, subscription and
				// scheduled-action rows, and fires the hooks add-ons use to drop
				// their own copies. Deleting the two tables by hand answered
				// "deleted permanently" while the uploaded file was still being
				// served from the URL the detail view had just linked, which is
				// the wrong answer to give someone honouring an erasure request.
				$form_id = (int) $row->form_id;
				if ( class_exists( '\FluentForm\App\Services\Submission\SubmissionService' ) ) {
					try {
						( new \FluentForm\App\Services\Submission\SubmissionService() )
							->deleteEntries( array( $id ), $form_id );
						return rest_ensure_response( array( 'id' => $id, 'deleted' => true, 'message' => __( 'Entry deleted permanently.', 'minn-admin' ) ) );
					} catch ( \Throwable $e ) {
						// Fall through to the manual path below rather than
						// leaving the entry half-deleted.
					}
				}

				// Last resort when their service is absent: cover the same
				// ground by hand and still fire their hooks, so add-on cleanup
				// runs even here.
				do_action( 'fluentform/before_deleting_entries', array( $id ), $form_id );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->delete( $wpdb->prefix . 'fluentform_submission_meta', array( 'response_id' => $id ), array( '%d' ) );
				$wpdb->delete(
					$wpdb->prefix . 'fluentform_logs',
					array( 'source_id' => $id, 'source_type' => 'submission_item' ),
					array( '%d', '%s' )
				);
				$wpdb->delete( $det_table, array( 'submission_id' => $id ), array( '%d' ) );
				$wpdb->delete( $subs_table, array( 'id' => $id ), array( '%d' ) );
				// phpcs:enable
				do_action( 'fluentform/after_deleting_submissions', array( $id ), $form_id );
				return rest_ensure_response( array( 'id' => $id, 'deleted' => true, 'message' => __( 'Entry deleted permanently.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/fluent-forms/entries/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'fluentform_manage_entries' )
				|| current_user_can( 'fluentform_full_access' )
				|| current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$id    = (int) $request['id'];
			$guard = minn_admin_fluent_forms_guard_entry( $id, 'fluentform_manage_entries' );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
			$status     = sanitize_key( (string) ( $request['status'] ?? '' ) );
			$allowed    = array( 'unread', 'read', 'spam', 'trashed' );
			$subs_table = $wpdb->prefix . 'fluentform_submissions';
			if ( ! in_array( $status, $allowed, true ) ) {
				return new WP_Error( 'bad_status', __( 'Unknown status.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$subs_table}` WHERE id = %d",
				$id
			) );
			if ( ! $exists ) {
				return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$wpdb->update(
				$subs_table,
				array( 'status' => $status ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
			$msgs = array(
				'unread'  => __( 'Entry restored.', 'minn-admin' ),
				'read'    => __( 'Marked as read.', 'minn-admin' ),
				'spam'    => __( 'Marked as spam.', 'minn-admin' ),
				'trashed' => __( 'Moved to trash.', 'minn-admin' ),
			);
			return rest_ensure_response( array(
				'id'      => $id,
				'status'  => $status,
				'ok'      => true,
				'message' => $msgs[ $status ] ?? __( 'Status updated.', 'minn-admin' ),
			) );
		},
	) );
} );
