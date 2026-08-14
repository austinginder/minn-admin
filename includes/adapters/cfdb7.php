<?php
/**
 * Bundled adapter: CFDB7 (Contact Form 7 Database Addon) entries.
 *
 * CFDB7 stores every CF7 submission as one PHP-serialized map in
 * {prefix}db7_forms.form_value. Blobs are decoded with allowed_classes set
 * to false, which instantiates no classes at all, so object injection is not
 * reachable — the same decode CFDB7 uses on its own read paths. A byte-length
 * token scanner remains as a fallback for a blob that no longer decodes.
 *
 * The scanner alone was not enough: a submission carrying a numerically-named
 * field stores an integer key, which the scanner could not read, and it then
 * abandoned the rest of the entry — hiding the answers and, worse, hiding the
 * uploaded file from the delete path.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_cfdb7_ready() {
	return function_exists( 'cfdb7_before_send_mail' );
}

function minn_admin_cfdb7_can_view() {
	// Their own menu gate: the dedicated cap when granted, else admin.
	return current_user_can( 'cfdb7_access' ) || current_user_can( 'manage_options' );
}

/**
 * Flatten a CFDB7 serialized map to [key => string] without unserialize().
 *
 * Handles the shapes CFDB7 writes: string values, list arrays (checkboxes;
 * their i:N keys are skipped, string members joined), and numeric scalars.
 *
 * @param string $blob Raw form_value column.
 * @return array<string,string>
 */
function minn_admin_cfdb7_values( $blob ) {
	$out  = array();
	$blob = (string) $blob;

	// A CF7 submission is unauthenticated input, and CFDB7 stores every POST
	// key it receives. A decimal-numeric field name becomes a PHP INTEGER key,
	// which serializes as i:N; — a shape the byte-length scanner below cannot
	// read, so it stopped at that point and silently dropped every remaining
	// answer, the upload filename among them. Delete then found no file to
	// remove and reported the entry permanently deleted anyway.
	//
	// allowed_classes => false instantiates nothing at all, so this is not the
	// object-injection risk the scanner was written to avoid; it is what CFDB7
	// itself uses on every one of its own read paths, and what
	// minn_admin_cfdb7_set_status() below already relies on.
	if ( is_serialized( $blob ) ) {
		$data = @unserialize( $blob, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				if ( is_array( $v ) ) {
					$v = implode( ', ', array_filter( array_map( 'strval', $v ), 'strlen' ) );
				}
				if ( is_scalar( $v ) || null === $v ) {
					$out[ (string) $k ] = (string) $v;
				}
			}
			return $out;
		}
	}

	// Fallback for a blob that no longer decodes: read what can be read rather
	// than nothing, and never stop early on a key shape we do not recognise.
	$len = strlen( $blob );
	if ( 'a:' !== substr( (string) $blob, 0, 2 ) ) {
		return $out;
	}
	$pos = strpos( $blob, '{' );
	if ( false === $pos ) {
		return $out;
	}
	$pos++;

	$read_string = function () use ( $blob, $len, &$pos ) {
		if ( $pos >= $len || 's' !== $blob[ $pos ] ) {
			return null;
		}
		$colon = strpos( $blob, ':', $pos + 2 );
		if ( false === $colon ) {
			return null;
		}
		$n     = (int) substr( $blob, $pos + 2, $colon - $pos - 2 );
		$start = $colon + 2; // past :"
		$val   = substr( $blob, $start, $n );
		$pos   = $start + $n + 2; // past ";
		return $val;
	};
	$skip_scalar = function () use ( $blob, $len, &$pos ) {
		$semi = strpos( $blob, ';', $pos );
		$val  = false === $semi ? '' : substr( $blob, $pos + 2, $semi - $pos - 2 );
		$pos  = false === $semi ? $len : $semi + 1;
		return $val;
	};

	while ( $pos < $len && '}' !== $blob[ $pos ] ) {
		$key = $read_string();
		if ( null === $key ) {
			// Not a string key (i:N; for a numeric field name). Skip past it
			// rather than abandoning the rest of the entry.
			$semi = strpos( $blob, ';', $pos );
			if ( false === $semi ) {
				break;
			}
			$pos = $semi + 1;
			$read_string();
			continue;
		}
		$type = $pos < $len ? $blob[ $pos ] : '';
		if ( 's' === $type ) {
			$out[ $key ] = (string) $read_string();
		} elseif ( 'a' === $type ) {
			$open = strpos( $blob, '{', $pos );
			if ( false === $open ) {
				break;
			}
			$pos    = $open + 1;
			$depth  = 1;
			$member = array();
			while ( $pos < $len && $depth > 0 ) {
				$c = $blob[ $pos ];
				if ( '}' === $c ) {
					$depth--;
					$pos++;
				} elseif ( 's' === $c ) {
					$member[] = (string) $read_string();
				} elseif ( 'a' === $c ) {
					$depth++;
					$inner = strpos( $blob, '{', $pos );
					$pos   = false === $inner ? $len : $inner + 1;
				} elseif ( 'N' === $c ) {
					$pos += 2;
				} else { // i / d / b keys and scalars
					$skip_scalar();
				}
			}
			$out[ $key ] = implode( ', ', array_filter( $member, 'strlen' ) );
		} elseif ( 'N' === $type ) {
			$out[ $key ] = '';
			$pos        += 2;
		} elseif ( '' === $type ) {
			break;
		} else { // i / d / b value
			$out[ $key ] = $skip_scalar();
		}
	}
	return $out;
}

/** CF7 form titles keyed by post id (for tabs, list rows, detail meta). */
function minn_admin_cfdb7_form_titles() {
	global $wpdb;
	$table = $wpdb->prefix . 'db7_forms';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-derived table.
	$ids = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT form_post_id FROM `{$table}`" ) );
	$out = array();
	foreach ( $ids as $id ) {
		$post       = get_post( $id );
		$out[ $id ] = $post && $post->post_title ? $post->post_title : ( 'Form #' . $id );
	}
	asort( $out );
	return $out;
}

/** First few real answers as the list-row summary (fluent-forms style). */
function minn_admin_cfdb7_summary( $values ) {
	$parts = array();
	foreach ( $values as $key => $v ) {
		if ( 'cfdb7_status' === $key || '' === trim( (string) $v ) || false !== strpos( $key, 'cfdb7_file' ) ) {
			continue;
		}
		$parts[] = trim( (string) $v );
		if ( count( $parts ) >= 3 ) {
			break;
		}
	}
	return $parts ? implode( ' · ', $parts ) : '(empty entry)';
}

/**
 * Rewrite a CFDB7 entry's read/unread flag without string surgery.
 *
 * The old approach ran str_replace for a fixed 31-byte token over the WHOLE
 * serialized blob. The tokens are constants, but the HAYSTACK is not: CFDB7
 * serializes raw Contact Form 7 submission values, so an unauthenticated
 * visitor can type that token into a form field. The replacement then fires
 * inside their own answer, shrinking the value by two bytes while its s:LEN:
 * prefix still declares the old length — the blob desyncs, unserialize()
 * returns false, and every CFDB7 consumer breaks for that row permanently
 * (their CSV export feeds the false straight into array_keys(), a fatal).
 *
 * Decode with allowed_classes => false (exactly what CFDB7 itself does on all
 * four of its own read paths, so the object-injection risk the surgery was
 * avoiding is already handled), set the key, re-serialize.
 *
 * @param string $blob   Raw form_value column.
 * @param string $status 'read' or 'unread'.
 * @return string|null New blob, or null when nothing should be written.
 */
function minn_admin_cfdb7_set_status( $blob, $status ) {
	$blob = (string) $blob;
	if ( ! is_serialized( $blob ) ) {
		return null;
	}
	$data = @unserialize( $blob, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
	if ( ! is_array( $data ) ) {
		return null;
	}
	if ( isset( $data['cfdb7_status'] ) && (string) $data['cfdb7_status'] === $status ) {
		return null;
	}
	$data['cfdb7_status'] = $status;
	return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_cfdb7_ready() || ! minn_admin_cfdb7_can_view() ) {
		return $surfaces;
	}
	$surfaces['cfdb7'] = array(
		'label'      => __( 'Forms', 'minn-admin' ),
		'family'     => 'forms',
		'group'      => 'workspace',
		'sub'        => 'CFDB7',
		'icon'       => 'inbox',
		'cap'        => 'read',
		'collection' => array(
			'viewLabel' => __( 'Messages', 'minn-admin' ),
			'route'     => 'minn-admin/v1/cfdb7/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/cfdb7/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_post_id',
				'allLabel' => 'All messages',
			),
			// Read/unread lives in the serialized blob as cfdb7_status.
			'filter'    => array(
				'label'   => __( 'Status', 'minn-admin' ),
				'options' => array(
					array( 'all', 'All' ),
					array( 'unread', 'Unread' ),
					array( 'read', 'Read' ),
				),
				'query'   => 'status={v}',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => __( 'Entry', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form', 'label' => __( 'Form', 'minn-admin' ) ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/cfdb7/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'  => __( 'Mark as unread', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/cfdb7/entries/{id}/unread',
					'when'   => array( 'key' => 'status', 'equals' => 'read' ),
				),
				array(
					'label'   => __( 'Delete entry', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/cfdb7/entries/{id}',
					'confirm' => __( 'Delete this entry permanently? CFDB7 has no trash.', 'minn-admin' ),
					'danger'  => true,
				),
				array(
					'label' => __( 'Open in CFDB7 ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=cfdb7-list.php&fid={form_post_id}&ufid={id}' ),
				),
			),
			'bulk'      => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/cfdb7/entries/{id}',
					'confirm' => __( 'Delete the selected entries permanently? CFDB7 has no trash.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_cfdb7_ready() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/cfdb7/forms', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_cfdb7_can_view',
		'callback'            => function () {
			$out = array();
			foreach ( minn_admin_cfdb7_form_titles() as $id => $title ) {
				$out[] = array(
					'id'    => $id,
					'title' => $title,
				);
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/cfdb7/entries', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_cfdb7_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table    = $wpdb->prefix . 'db7_forms';
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );

			$status = sanitize_key( (string) ( $request['status'] ?: 'all' ) );
			if ( ! in_array( $status, array( 'all', 'read', 'unread' ), true ) ) {
				$status = 'all';
			}

			$where = array( '1=1' );
			$args  = array();
			if ( $request['form_post_id'] ) {
				$where[] = 'form_post_id = %d';
				$args[]  = (int) $request['form_post_id'];
			}
			if ( $request['search'] ) {
				// Their own list screen searches the raw blob with LIKE.
				$where[] = 'form_value LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
			}
			// Read/unread is a fixed token inside the serialized blob.
			if ( 'read' === $status ) {
				$where[] = 'form_value LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( 's:12:"cfdb7_status";s:4:"read"' ) . '%';
			} elseif ( 'unread' === $status ) {
				// Absent or "unread" both count as unread for their screen.
				$where[] = '(form_value LIKE %s OR form_value NOT LIKE %s)';
				$args[]  = '%' . $wpdb->esc_like( 's:12:"cfdb7_status";s:6:"unread"' ) . '%';
				$args[]  = '%' . $wpdb->esc_like( 's:12:"cfdb7_status";s:4:"read"' ) . '%';
			}
			$where_sql = implode( ' AND ', $where );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var(
				$args
					? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", ...$args )
					: "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}"
			);
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT form_id, form_post_id, form_value, form_date FROM `{$table}`
				 WHERE {$where_sql} ORDER BY form_id DESC LIMIT %d OFFSET %d",
				array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable

			$titles = minn_admin_cfdb7_form_titles();
			$items  = array();
			foreach ( (array) $rows as $r ) {
				$values  = minn_admin_cfdb7_values( (string) $r->form_value );
				$items[] = array(
					'id'           => (int) $r->form_id,
					'form_post_id' => (int) $r->form_post_id,
					'summary'      => minn_admin_cfdb7_summary( $values ),
					'form'         => $titles[ (int) $r->form_post_id ] ?? ( 'Form #' . (int) $r->form_post_id ),
					'status'       => ( $values['cfdb7_status'] ?? '' ) === 'read' ? 'read' : 'unread',
					// form_date is current_time() = site-local; leave un-zoned.
					'date'         => str_replace( ' ', 'T', (string) $r->form_date ),
				);
			}

			return rest_ensure_response( array(
				'items' => $items,
				'total' => $total,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/cfdb7/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_cfdb7_can_view',
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$table = $wpdb->prefix . 'db7_forms';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT form_id, form_post_id, form_value, form_date FROM `{$table}` WHERE form_id = %d",
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$values = minn_admin_cfdb7_values( (string) $row->form_value );

				// Opening marks read — CFDB7's own view semantics.
				if ( ( $values['cfdb7_status'] ?? '' ) !== 'read' ) {
					$patched = minn_admin_cfdb7_set_status( $row->form_value, 'read' );
					if ( null !== $patched ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->update( $table, array( 'form_value' => $patched ), array( 'form_id' => (int) $row->form_id ), array( '%s' ), array( '%d' ) );
					}
				}

				$upload_url = wp_upload_dir()['baseurl'] . '/cfdb7_uploads/';
				$answers    = array();
				foreach ( $values as $key => $value ) {
					if ( 'cfdb7_status' === $key || '' === trim( (string) $value ) ) {
						continue;
					}
					$is_file = false;
					if ( false !== strpos( $key, 'cfdb7_file' ) ) {
						$key     = str_replace( 'cfdb7_file', '', $key );
						$is_file = true;
					}
					$label     = preg_replace( '/^your[-_]/', '', (string) $key );
					$label     = ucwords( str_replace( array( '-', '_' ), ' ', $label ) );
					$answers[] = array(
						'label' => $label . ( $is_file ? ' (file)' : '' ),
						'value' => $is_file ? $upload_url . $value : (string) $value,
						'type'  => $is_file ? 'url'
							: ( is_email( (string) $value ) ? 'email'
								: ( 0 === strpos( (string) $value, 'http' ) ? 'url' : 'text' ) ),
					);
				}

				$titles = minn_admin_cfdb7_form_titles();
				$meta   = array(
					array(
						'label' => __( 'Submitted', 'minn-admin' ),
						'value' => date_i18n( 'M j, Y g:i a', strtotime( (string) $row->form_date ) ),
					),
					array(
						'label' => __( 'Form', 'minn-admin' ),
						'value' => $titles[ (int) $row->form_post_id ] ?? ( 'Form #' . (int) $row->form_post_id ),
					),
				);

				return rest_ensure_response( array(
					'kind'     => 'entry',
					'title'    => $titles[ (int) $row->form_post_id ] ?? 'Message',
					'status'   => 'read',
					'sections' => array(
						array( 'title' => __( 'Responses', 'minn-admin' ), 'rows' => $answers ),
						array( 'title' => __( 'Submission', 'minn-admin' ), 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=cfdb7-list.php&fid=' . (int) $row->form_post_id . '&ufid=' . (int) $row->form_id ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => 'minn_admin_cfdb7_can_view',
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$table = $wpdb->prefix . 'db7_forms';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT form_id FROM `{$table}` WHERE form_id = %d",
					(int) $request['id']
				) );
				if ( ! $exists ) {
					return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Remove the files the entry uploaded before the row that names
				// them, exactly as CFDB7's own bulk delete does: the values whose
				// key carries the cfdb7_file marker are filenames under
				// uploads/cfdb7_uploads. Dropping only the row would strand a
				// resume or ID scan on disk at a guessable URL with nothing left
				// pointing at it. Values come from the byte-length scanner rather
				// than unserialize(), per this adapter's rule about their blob.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$blob = (string) $wpdb->get_var( $wpdb->prepare( "SELECT form_value FROM {$table} WHERE form_id = %d", (int) $request['id'] ) );
				$dir  = wp_upload_dir()['basedir'] . '/cfdb7_uploads/';
				foreach ( minn_admin_cfdb7_values( $blob ) as $key => $value ) {
					if ( false === strpos( $key, 'cfdb7_file' ) || '' === trim( (string) $value ) ) {
						continue;
					}
					// basename() so a traversal-shaped value can never reach out
					// of the uploads directory.
					$file = $dir . basename( (string) $value );
					if ( is_readable( $file ) && ! is_dir( $file ) ) {
						wp_delete_file( $file );
					}
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->delete( $table, array( 'form_id' => (int) $request['id'] ), array( '%d' ) );
				return rest_ensure_response( array( 'id' => (int) $request['id'], 'deleted' => true, 'message' => __( 'Entry deleted permanently.', 'minn-admin' ) ) );
			},
		),
	) );

	// Mark unread: reverse of the fixed-token read surgery (never re-serialize).
	register_rest_route( 'minn-admin/v1', '/cfdb7/entries/(?P<id>\d+)/unread', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_cfdb7_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = $wpdb->prefix . 'db7_forms';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT form_id, form_value FROM `{$table}` WHERE form_id = %d",
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$patched = minn_admin_cfdb7_set_status( $row->form_value, 'unread' );
			if ( null !== $patched ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update( $table, array( 'form_value' => $patched ), array( 'form_id' => (int) $row->form_id ), array( '%s' ), array( '%d' ) );
			}
			return rest_ensure_response( array( 'id' => (int) $row->form_id, 'status' => 'unread', 'message' => __( 'Marked as unread.', 'minn-admin' ) ) );
		},
	) );
} );
