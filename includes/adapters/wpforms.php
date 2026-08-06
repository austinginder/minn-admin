<?php
/**
 * Bundled adapter: WPForms Pro entries.
 *
 * Pro stores submissions in {prefix}wpforms_entries (`fields` is clean JSON
 * keyed by field id with name/value; `date` is UTC; `viewed`/`starred` are
 * tinyint flags; `status` is '' for inbox, 'spam', 'trash' — abandoned/partial
 * belong to their addon and stay on their screen). Lite stores no entries, so
 * the surface registers only when their entry handler exists.
 *
 * Reads are prefix-scoped SELECTs (family shim pattern; search is a LIKE over
 * the fields JSON, their own fallback search shape). WRITES go through their
 * handler — wpforms()->obj( 'entry' )->update()/delete() — so their hooks fire
 * and delete cleans entry_fields/entry_meta. Caps defer to
 * wpforms_current_user_can() (their Access\Capabilities model: view_entries /
 * edit_entries / delete_entries; enforced even without the access addon —
 * editors get nothing unless granted). Opening a detail marks the entry
 * viewed through their update, like their own entry screen.
 *
 * last-sweep: 2026-08-06
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Pro with entry storage loaded (Lite has no entry handler). */
function minn_admin_wpforms_active() {
	return function_exists( 'wpforms' ) && is_object( wpforms()->obj( 'entry' ) );
}

function minn_admin_wpforms_can( $cap = 'view_entries' ) {
	return function_exists( 'wpforms_current_user_can' ) && wpforms_current_user_can( $cap );
}

function minn_admin_wpforms_table() {
	global $wpdb;
	return $wpdb->prefix . 'wpforms_entries';
}

/** Map of wpforms form id => title, for tabs and entry meta. */
function minn_admin_wpforms_form_titles() {
	$titles = array();
	foreach ( get_posts( array(
		'post_type'      => 'wpforms',
		'post_status'    => 'publish',
		'posts_per_page' => 200,
		'fields'         => 'ids',
	) ) as $id ) {
		$titles[ (int) $id ] = get_the_title( $id ) ?: ( 'Form #' . $id );
	}
	return $titles;
}

/** Decoded fields JSON => [ label => flat value ] in field order. */
function minn_admin_wpforms_answers( $fields_json ) {
	$fields = json_decode( (string) $fields_json, true );
	if ( ! is_array( $fields ) ) {
		return array();
	}
	$out = array();
	foreach ( $fields as $f ) {
		if ( ! is_array( $f ) ) {
			continue;
		}
		$label = isset( $f['name'] ) && '' !== (string) $f['name'] ? (string) $f['name'] : ( 'Field #' . ( $f['id'] ?? '?' ) );
		$value = $f['value'] ?? '';
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}
		$out[ $label ] = trim( (string) $value );
	}
	return $out;
}

/** Contact summary: prefer a name and an email, else the first answers. */
function minn_admin_wpforms_summary( $fields_json ) {
	$name  = '';
	$email = '';
	$rest  = array();
	foreach ( minn_admin_wpforms_answers( $fields_json ) as $label => $value ) {
		if ( '' === $value ) {
			continue;
		}
		$lc = strtolower( $label );
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

/** Display status: spam/trash win outright, else read state from `viewed`. */
function minn_admin_wpforms_display_status( $row ) {
	$status = (string) $row->status;
	if ( in_array( $status, array( 'spam', 'trash' ), true ) ) {
		return $status;
	}
	return ( (int) $row->viewed ) ? 'read' : 'unread';
}

/** Server-built model for the surface status card (forms-family shape). */
function minn_admin_wpforms_status_model() {
	global $wpdb;
	$table = minn_admin_wpforms_table();
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$unread = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status NOT IN ('spam','trash') AND viewed = 0" );
	$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status NOT IN ('spam','trash')" );
	$spam   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'spam'" );
	// phpcs:enable
	$forms = count( minn_admin_wpforms_form_titles() );
	$hint  = number_format_i18n( $total ) . ' total';
	if ( $spam ) {
		$hint .= ', ' . number_format_i18n( $spam ) . ' spam';
	}
	return array(
		'rows'    => array(
			array( 'label' => 'Unread entries', 'value' => number_format_i18n( $unread ), 'hint' => $hint ),
			array( 'label' => 'Forms', 'value' => number_format_i18n( $forms ) ),
		),
		'actions' => array(
			array( 'label' => 'Open WPForms ↗', 'href' => admin_url( 'admin.php?page=wpforms-entries' ) ),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wpforms_active() || ! minn_admin_wpforms_can() ) {
		return $surfaces;
	}

	$surfaces['wpforms'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'WPForms',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is minn_admin_wpforms_can().
		'status'     => array( 'route' => 'minn-admin/v1/wpforms/status' ),
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'minn-admin/v1/wpforms/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/wpforms/forms',
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
					array( 'starred', 'Starred' ),
					array( 'spam', 'Spam' ),
					array( 'trash', 'Trash' ),
				),
				'query'   => 'status={v}',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => 'Form' ),
				array( 'key' => 'starred', 'label' => '★', 'width' => '40px' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/wpforms/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'  => 'Mark read',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'   => array( 'status' => 'read' ),
					'when'   => array( 'key' => 'status', 'equals' => 'unread' ),
				),
				array(
					'label'  => 'Mark unread',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'   => array( 'status' => 'unread' ),
					'when'   => array( 'key' => 'status', 'equals' => 'read' ),
				),
				array(
					'label'  => 'Star',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/star',
					'body'   => array( 'on' => 1 ),
					'when'   => array( 'key' => 'starred', 'equals' => '' ),
				),
				array(
					'label'  => 'Unstar',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/star',
					'body'   => array( 'on' => 0 ),
					'when'   => array( 'key' => 'starred', 'equals' => '★' ),
				),
				array(
					'label'  => 'Mark spam',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'   => array( 'status' => 'spam' ),
					'when'   => array( 'key' => 'status', 'equals' => 'read' ),
				),
				array(
					'label'  => 'Not spam',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'   => array( 'status' => 'restore' ),
					'when'   => array( 'key' => 'status', 'equals' => 'spam' ),
				),
				array(
					'label'  => 'Trash',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'   => array( 'status' => 'trash' ),
					'when'   => array( 'key' => 'status', 'equals' => 'read' ),
				),
				array(
					'label'  => 'Restore',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'   => array( 'status' => 'restore' ),
					'when'   => array( 'key' => 'status', 'equals' => 'trash' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpforms/entries/{id}',
					'confirm' => 'Delete this entry permanently? There is no undo.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trash' ),
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Mark read',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'   => array( 'status' => 'read' ),
				),
				array(
					'label'   => 'Trash',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/wpforms/entries/{id}/status',
					'body'    => array( 'status' => 'trash' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpforms_active() ) {
		return;
	}
	$view = function () {
		return minn_admin_wpforms_can( 'view_entries' );
	};
	$edit = function () {
		return minn_admin_wpforms_can( 'edit_entries' );
	};

	// Forms list for the tab strip.
	register_rest_route( 'minn-admin/v1', '/wpforms/forms', array(
		'methods'             => 'GET',
		'permission_callback' => $view,
		'callback'            => function () {
			$out = array();
			foreach ( minn_admin_wpforms_form_titles() as $id => $title ) {
				$out[] = array( 'id' => $id, 'title' => $title );
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wpforms/entries', array(
		'methods'             => 'GET',
		'permission_callback' => $view,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = minn_admin_wpforms_table();
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );
			$form_id  = (int) $request->get_param( 'form_id' );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where  = array();
			$params = array();
			if ( 'spam' === $status || 'trash' === $status ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			} else {
				// Inbox behavior: spam and trash stay in their buckets.
				$where[] = "status NOT IN ('spam','trash')";
				if ( 'unread' === $status ) {
					$where[] = 'viewed = 0';
				} elseif ( 'read' === $status ) {
					$where[] = 'viewed = 1';
				} elseif ( 'starred' === $status ) {
					$where[] = 'starred = 1';
				}
			}
			if ( $form_id ) {
				$where[]  = 'form_id = %d';
				$params[] = $form_id;
			}
			if ( '' !== $search ) {
				$where[]  = 'fields LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			}
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT entry_id, form_id, status, viewed, starred, fields, date FROM {$table} {$where_sql} ORDER BY entry_id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$titles = minn_admin_wpforms_form_titles();
			$items  = array_map( function ( $r ) use ( $titles ) {
				return array(
					'id'         => (int) $r->entry_id,
					'summary'    => minn_admin_wpforms_summary( $r->fields ),
					'form_title' => isset( $titles[ (int) $r->form_id ] ) ? $titles[ (int) $r->form_id ] : ( 'Form #' . (int) $r->form_id ),
					'starred'    => ( (int) $r->starred ) ? '★' : '',
					'status'     => minn_admin_wpforms_display_status( $r ),
					'date'       => $r->date ? gmdate( 'c', strtotime( $r->date . ' UTC' ) ) : '',
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wpforms/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $view,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$table = minn_admin_wpforms_table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d", (int) $request['id'] ) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				// Their entry screen marks an opened entry viewed; mirror it
				// through their handler (hooks fire). Never for spam/trash.
				if ( ! (int) $row->viewed && ! in_array( (string) $row->status, array( 'spam', 'trash' ), true ) && minn_admin_wpforms_can( 'edit_entries' ) ) {
					try {
						wpforms()->obj( 'entry' )->update( (int) $row->entry_id, array( 'viewed' => 1 ) );
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
						// Read-only view still renders.
					}
				}
				$answers = array();
				foreach ( minn_admin_wpforms_answers( $row->fields ) as $label => $value ) {
					$answers[] = array( 'label' => $label, 'value' => '' !== $value ? $value : '—' );
				}
				$titles = minn_admin_wpforms_form_titles();
				$meta   = array(
					array( 'label' => 'Form', 'value' => isset( $titles[ (int) $row->form_id ] ) ? $titles[ (int) $row->form_id ] : ( '#' . (int) $row->form_id ) ),
					array( 'label' => 'Entry', 'value' => '#' . (int) $row->entry_id ),
					array( 'label' => 'Status', 'value' => minn_admin_wpforms_display_status( $row ) ),
				);
				if ( $row->date ) {
					$meta[] = array( 'label' => 'Submitted', 'value' => gmdate( 'c', strtotime( $row->date . ' UTC' ) ) );
				}
				if ( '' !== (string) $row->ip_address ) {
					$meta[] = array( 'label' => 'IP address', 'value' => (string) $row->ip_address );
				}
				if ( (int) $row->starred ) {
					$meta[] = array( 'label' => 'Starred', 'value' => '★' );
				}
				return rest_ensure_response( array(
					'kind'     => 'entry',
					'sections' => array(
						array( 'title' => 'Answers', 'rows' => $answers ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=wpforms-entries&view=details&entry_id=' . (int) $row->entry_id ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function () {
				return minn_admin_wpforms_can( 'delete_entries' );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$id    = (int) $request['id'];
				$table = minn_admin_wpforms_table();
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$table} WHERE entry_id = %d", $id ) );
				if ( ! $exists ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				// Their delete cleans entry_fields/entry_meta and fires hooks.
				try {
					$ok = (bool) wpforms()->obj( 'entry' )->delete( $id );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'delete_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
				if ( ! $ok ) {
					return new WP_Error( 'delete_failed', 'WPForms could not delete this entry.', array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'deleted' => true, 'message' => 'Entry deleted.' ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/wpforms/entries/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $request ) {
			$op = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! in_array( $op, array( 'read', 'unread', 'spam', 'trash', 'restore' ), true ) ) {
				return new WP_Error( 'bad_status', 'Unknown status', array( 'status' => 400 ) );
			}
			global $wpdb;
			$id    = (int) $request['id'];
			$table = minn_admin_wpforms_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$table} WHERE entry_id = %d", $id ) );
			if ( ! $exists ) {
				return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
			}
			$data = array();
			if ( 'read' === $op ) {
				$data = array( 'viewed' => 1 );
			} elseif ( 'unread' === $op ) {
				$data = array( 'viewed' => 0 );
			} elseif ( 'restore' === $op ) {
				$data = array( 'status' => '' );
			} else {
				$data = array( 'status' => $op );
			}
			try {
				wpforms()->obj( 'entry' )->update( $id, $data );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'update_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'ok' => true, 'status' => $op ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wpforms/entries/(?P<id>\d+)/star', array(
		'methods'             => 'POST',
		'permission_callback' => $edit,
		'callback'            => function ( WP_REST_Request $request ) {
			$on = (int) (bool) $request->get_param( 'on' );
			try {
				wpforms()->obj( 'entry' )->update( (int) $request['id'], array( 'starred' => $on ) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'update_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'ok' => true, 'starred' => $on ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wpforms/status', array(
		'methods'             => 'GET',
		'permission_callback' => $view,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_wpforms_status_model() );
		},
	) );
} );
