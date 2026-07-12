<?php
/**
 * Bundled adapter: CFDB7 (Contact Form 7 Database Addon) entries.
 *
 * CFDB7 stores every CF7 submission as one PHP-serialized map in
 * {prefix}db7_forms.form_value. Shim rule: third-party blobs are NEVER
 * unserialize()d — a byte-length token scanner walks the s:LEN:"…" shape
 * instead (LEN is bytes, so quotes, semicolons and multibyte content in
 * values can't derail it, and nothing executes). Read/unread mirrors
 * CFDB7's own semantics: opening a message marks it read via fixed-token
 * string surgery on the blob, never a re-serialize.
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
	$out = array();
	$len = strlen( (string) $blob );
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
			break;
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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_cfdb7_ready() || ! minn_admin_cfdb7_can_view() ) {
		return $surfaces;
	}
	$surfaces['cfdb7'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace',
		'sub'        => 'CFDB7',
		'icon'       => 'inbox',
		'cap'        => 'read',
		'collection' => array(
			'viewLabel' => 'Messages',
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
				'label'   => 'Status',
				'options' => array(
					array( 'all', 'All' ),
					array( 'unread', 'Unread' ),
					array( 'read', 'Read' ),
				),
				'query'   => 'status={v}',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form', 'label' => 'Form' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/cfdb7/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'  => 'Mark as unread',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/cfdb7/entries/{id}/unread',
					'when'   => array( 'key' => 'status', 'equals' => 'read' ),
				),
				array(
					'label'   => 'Delete entry',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/cfdb7/entries/{id}',
					'confirm' => 'Delete this entry permanently? CFDB7 has no trash.',
					'danger'  => true,
				),
				array(
					'label' => 'Open in CFDB7 ↗',
					'href'  => admin_url( 'admin.php?page=cfdb7-list.php&fid={form_post_id}&ufid={id}' ),
				),
			),
			'bulk'      => array(
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/cfdb7/entries/{id}',
					'confirm' => 'Delete the selected entries permanently? CFDB7 has no trash.',
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
					return new WP_Error( 'not_found', 'Entry not found.', array( 'status' => 404 ) );
				}
				$values = minn_admin_cfdb7_values( (string) $row->form_value );

				// Opening marks read — CFDB7's own view semantics. Fixed-token
				// surgery on the blob; both tokens are constants we control.
				if ( ( $values['cfdb7_status'] ?? '' ) !== 'read' ) {
					$patched = str_replace(
						's:12:"cfdb7_status";s:6:"unread"',
						's:12:"cfdb7_status";s:4:"read"',
						(string) $row->form_value
					);
					if ( $patched !== (string) $row->form_value ) {
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
						'label' => 'Submitted',
						'value' => date_i18n( 'M j, Y g:i a', strtotime( (string) $row->form_date ) ),
					),
					array(
						'label' => 'Form',
						'value' => $titles[ (int) $row->form_post_id ] ?? ( 'Form #' . (int) $row->form_post_id ),
					),
				);

				return rest_ensure_response( array(
					'kind'     => 'entry',
					'title'    => $titles[ (int) $row->form_post_id ] ?? 'Message',
					'status'   => 'read',
					'sections' => array(
						array( 'title' => 'Responses', 'rows' => $answers ),
						array( 'title' => 'Submission', 'rows' => $meta ),
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
					return new WP_Error( 'not_found', 'Entry not found.', array( 'status' => 404 ) );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->delete( $table, array( 'form_id' => (int) $request['id'] ), array( '%d' ) );
				return rest_ensure_response( array( 'id' => (int) $request['id'], 'deleted' => true, 'message' => 'Entry deleted permanently.' ) );
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
				return new WP_Error( 'not_found', 'Entry not found.', array( 'status' => 404 ) );
			}
			$patched = str_replace(
				's:12:"cfdb7_status";s:4:"read"',
				's:12:"cfdb7_status";s:6:"unread"',
				(string) $row->form_value
			);
			if ( $patched !== (string) $row->form_value ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->update( $table, array( 'form_value' => $patched ), array( 'form_id' => (int) $row->form_id ), array( '%s' ), array( '%d' ) );
			}
			return rest_ensure_response( array( 'id' => (int) $row->form_id, 'status' => 'unread', 'message' => 'Marked as unread.' ) );
		},
	) );
} );
