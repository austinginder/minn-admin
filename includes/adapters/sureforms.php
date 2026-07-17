<?php
/**
 * Bundled adapter: SureForms (Wave B).
 *
 * SureForms stores every submission in its own {prefix}srfm_entries table
 * (free feature; opt-out is a per-form do_not_store_entries flag). `form_data`
 * is clean JSON keyed by the human field label, so entries render as contact
 * cards with zero label resolution. Read/unread/trash status, per-form tabs
 * from the sureforms_form CPT, search, delete, and a status card. Prefix-scoped
 * SELECTs; json_decode only, never unserialize. `created_at` is a DB timestamp
 * (session zone) normalized to UTC via the shared helper.
 *
 * last-sweep: 2026-07-17
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_sureforms_active() {
	return defined( 'SRFM_VER' ) || class_exists( 'SRFM\\Inc\\Database\\Tables\\Entries' );
}

/**
 * SureForms gates its admin through Helper::current_user_can (manage_options
 * by default, filterable). Defer to it when present.
 */
function minn_admin_sureforms_can() {
	if ( class_exists( 'SRFM\\Inc\\Helper' ) && method_exists( 'SRFM\\Inc\\Helper', 'current_user_can' ) ) {
		return (bool) \SRFM\Inc\Helper::current_user_can();
	}
	return current_user_can( 'manage_options' );
}

function minn_admin_sureforms_table() {
	global $wpdb;
	return $wpdb->prefix . 'srfm_entries';
}

/** Map of sureforms_form post id => title, for tabs and entry meta. */
function minn_admin_sureforms_form_titles() {
	$titles = array();
	foreach ( get_posts( array(
		'post_type'      => 'sureforms_form',
		'post_status'    => array( 'publish', 'draft' ),
		'posts_per_page' => 200,
		'fields'         => 'ids',
	) ) as $id ) {
		$titles[ (int) $id ] = get_the_title( $id ) ?: ( 'Form #' . $id );
	}
	return $titles;
}

/**
 * A short contact summary from decoded form_data: prefer a name and an email,
 * else the first couple of answers. Mirrors the forms-family entry summary.
 */
function minn_admin_sureforms_summary( array $data ) {
	$name  = '';
	$email = '';
	$rest  = array();
	foreach ( $data as $label => $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			continue;
		}
		$lc = strtolower( (string) $label );
		if ( '' === $email && ( false !== strpos( $lc, 'email' ) || is_email( $value ) ) ) {
			$email = $value;
		} elseif ( '' === $name && false !== strpos( $lc, 'name' ) ) {
			$name = $value;
		} else {
			$rest[] = $value;
		}
	}
	$parts = array_filter( array( $name, $email ) );
	if ( ! $parts ) {
		$parts = array_slice( $rest, 0, 2 );
	}
	$summary = implode( ' · ', $parts );
	if ( strlen( $summary ) > 80 ) {
		$summary = substr( $summary, 0, 80 ) . '…';
	}
	return $summary ?: '(empty entry)';
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_sureforms_active() ) {
		return $surfaces;
	}
	$surfaces['sureforms'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace',
		'sub'        => 'SureForms',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is minn_admin_sureforms_can().
		'status'     => array( 'route' => 'minn-admin/v1/sureforms/status' ),
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'minn-admin/v1/sureforms/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/sureforms/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => 'All entries',
			),
			'filter'    => array(
				'label'   => 'Status',
				'options' => array(
					array( 'unread', 'Unread' ),
					array( 'read', 'Read' ),
					array( 'trash', 'Trash' ),
				),
				'query'   => 'status={v}',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => 'Form' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/sureforms/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'  => 'Mark read',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/sureforms/entries/{id}/status',
					'body'   => array( 'status' => 'read' ),
					'when'   => array( 'key' => 'status', 'equals' => 'unread' ),
				),
				array(
					'label'  => 'Mark unread',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/sureforms/entries/{id}/status',
					'body'   => array( 'status' => 'unread' ),
					'when'   => array( 'key' => 'status', 'equals' => 'read' ),
				),
				array(
					'label'   => 'Trash',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/sureforms/entries/{id}/status',
					'body'    => array( 'status' => 'trash' ),
					'when'    => array( 'key' => 'status', 'equals' => 'read' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/sureforms/entries/{id}',
					'confirm' => 'Delete this entry permanently? There is no undo.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trash' ),
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Mark read',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/sureforms/entries/{id}/status',
					'body'   => array( 'status' => 'read' ),
				),
				array(
					'label'   => 'Trash',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/sureforms/entries/{id}/status',
					'body'    => array( 'status' => 'trash' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_sureforms_active() ) {
		return;
	}
	$perm      = 'minn_admin_sureforms_can';
	$has_table = function () {
		global $wpdb;
		$t = minn_admin_sureforms_table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	};

	// Forms list for the tab strip.
	register_rest_route( 'minn-admin/v1', '/sureforms/forms', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$out = array();
			foreach ( minn_admin_sureforms_form_titles() as $id => $title ) {
				$out[] = array( 'id' => $id, 'title' => $title );
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/sureforms/entries', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $has_table ) {
			if ( ! $has_table() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$table    = minn_admin_sureforms_table();
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );
			$form_id  = (int) $request->get_param( 'form_id' );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where  = array( '1=1' );
			$params = array();
			if ( in_array( $status, array( 'read', 'unread', 'trash' ), true ) ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			} else {
				// Default view hides trash (their inbox behavior).
				$where[] = "status <> 'trash'";
			}
			if ( $form_id ) {
				$where[]  = 'form_id = %d';
				$params[] = $form_id;
			}
			if ( '' !== $search ) {
				$where[]  = 'form_data LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, form_id, form_data, status, created_at FROM {$table} {$where_sql} ORDER BY ID DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$titles = minn_admin_sureforms_form_titles();
			$items  = array_map( function ( $r ) use ( $titles ) {
				$data = json_decode( (string) $r->form_data, true );
				$data = is_array( $data ) ? $data : array();
				return array(
					'id'         => (int) $r->ID,
					'summary'    => minn_admin_sureforms_summary( $data ),
					'form_title' => isset( $titles[ (int) $r->form_id ] ) ? $titles[ (int) $r->form_id ] : ( 'Form #' . (int) $r->form_id ),
					'status'     => (string) $r->status,
					'date'       => minn_admin_db_local_to_utc_iso( $r->created_at ),
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/sureforms/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$table = minn_admin_sureforms_table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE ID = %d", (int) $request['id'] ) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				$data   = json_decode( (string) $row->form_data, true );
				$data   = is_array( $data ) ? $data : array();
				$answers = array();
				foreach ( $data as $label => $value ) {
					if ( is_array( $value ) ) {
						$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
					}
					$answers[] = array(
						'label' => (string) $label,
						'value' => '' !== (string) $value ? (string) $value : '—',
					);
				}
				$titles = minn_admin_sureforms_form_titles();
				$meta   = array(
					array( 'label' => 'Form', 'value' => isset( $titles[ (int) $row->form_id ] ) ? $titles[ (int) $row->form_id ] : ( '#' . (int) $row->form_id ) ),
					array( 'label' => 'Entry', 'value' => '#' . (int) $row->ID ),
					array( 'label' => 'Status', 'value' => (string) $row->status ),
				);
				$iso = minn_admin_db_local_to_utc_iso( $row->created_at );
				if ( '' !== $iso ) {
					$meta[] = array( 'label' => 'Submitted', 'value' => $iso );
				}
				return rest_ensure_response( array(
					'kind'     => 'entry',
					'sections' => array(
						array( 'title' => 'Answers', 'rows' => $answers ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=sureforms_entries&entry_id=' . (int) $row->ID ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$table = minn_admin_sureforms_table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE ID = %d", (int) $request['id'] ) );
				if ( ! $deleted ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				return rest_ensure_response( array( 'deleted' => true, 'message' => 'Entry deleted.' ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/sureforms/entries/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$status = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( $status, array( 'read', 'unread', 'trash' ), true ) ) {
				return new WP_Error( 'bad_status', 'Unknown status', array( 'status' => 400 ) );
			}
			global $wpdb;
			$table   = minn_admin_sureforms_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$updated = $wpdb->update( $table, array( 'status' => $status ), array( 'ID' => (int) $request['id'] ), array( '%s' ), array( '%d' ) );
			if ( false === $updated ) {
				return new WP_Error( 'update_failed', 'Could not update the entry.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'ok' => true, 'status' => $status ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/sureforms/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () use ( $has_table ) {
			$admin_url = admin_url( 'admin.php?page=sureforms_entries' );
			if ( ! $has_table() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => 'Entries', 'value' => '—', 'hint' => 'No submissions yet' ) ),
					'actions' => array( array( 'label' => 'Open SureForms ↗', 'href' => $admin_url ) ),
				) );
			}
			global $wpdb;
			$table  = minn_admin_sureforms_table();
			$unread = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'unread'" ); // phpcs:ignore
			$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status <> 'trash'" ); // phpcs:ignore
			$forms  = count( minn_admin_sureforms_form_titles() );
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => 'Unread entries',
						'value' => number_format_i18n( $unread ),
						'hint'  => number_format_i18n( $total ) . ' total',
					),
					array( 'label' => 'Forms', 'value' => number_format_i18n( $forms ) ),
				),
				'actions' => array( array( 'label' => 'Open SureForms ↗', 'href' => $admin_url ) ),
			) );
		},
	) );
} );
