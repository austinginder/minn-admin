<?php
/**
 * Bundled adapter: Everest Forms — forms family (entries + forms).
 *
 * Everest stores submissions in {prefix}evf_entries (+ per-answer rows in
 * evf_entrymeta). Reads are prefix-scoped SQL over the entry table (the
 * shim convention) with labels from the form's post_content field map at
 * runtime, and status/delete through EVF_Admin_Entries::update_status /
 * remove_entry — their complete flow (trash preserves prior status in
 * _evf_trash_entry_status meta; delete fires their before/after hooks).
 *
 * Clock: entries stamp current_time( 'mysql', true ) — UTC, so columns
 * carry utc: true.
 *
 * Caps mirror the plugin: everest_forms_view_entries (or
 * everest_forms_view_others_entries / manage_everest_forms) for reads,
 * everest_forms_delete_entries for trash/spam/delete.
 *
 * Status filter: publish (Received) / spam / trash — same three buckets
 * as their entries screen.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_everest_active() {
	global $wpdb;
	if ( ! function_exists( 'evf' ) && ! defined( 'EVF_VERSION' ) ) {
		return false;
	}
	$table = $wpdb->prefix . 'evf_entries';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

/** Their permission model: view-entries caps or the master manage cap. */
function minn_admin_everest_can( $cap = 'view' ) {
	if ( current_user_can( 'manage_everest_forms' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}
	if ( 'delete' === $cap ) {
		return current_user_can( 'everest_forms_delete_entries' );
	}
	return current_user_can( 'everest_forms_view_entries' )
		|| current_user_can( 'everest_forms_view_others_entries' );
}

/**
 * Ensure EVF_Admin_Entries is loadable so trash/delete go through their
 * static helpers (hooks + prior-status preservation).
 */
function minn_admin_everest_load_entries_class() {
	if ( class_exists( 'EVF_Admin_Entries' ) ) {
		return true;
	}
	if ( ! defined( 'EVF_ABSPATH' ) ) {
		return false;
	}
	$file = EVF_ABSPATH . 'includes/admin/class-evf-admin-entries.php';
	if ( ! file_exists( $file ) ) {
		return false;
	}
	// Instantiates on require (hooks admin_init/heartbeat — safe under REST).
	require_once $file;
	return class_exists( 'EVF_Admin_Entries' );
}

/** Field types that carry no answer (chrome, not data). */
function minn_admin_everest_skip_types() {
	return array(
		'html',
		'title',
		'divider',
		'captcha',
		'recaptcha',
		'hcaptcha',
		'turnstile',
		'honeypot',
		'privacy-policy',
		'private-note',
	);
}

/**
 * Input fields for a form via Everest's own form model:
 * [meta_key => label], in form order.
 *
 * @param int $form_id Form post id.
 * @return array<string,string>
 */
function minn_admin_everest_fields( $form_id ) {
	static $cache = array();
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$out = array();
	try {
		if ( ! function_exists( 'evf' ) || ! evf()->form ) {
			$cache[ $form_id ] = $out;
			return $out;
		}
		$form = evf()->form->get( (int) $form_id, array( 'content_only' => true ) );
		if ( ! is_array( $form ) || empty( $form['form_fields'] ) || ! is_array( $form['form_fields'] ) ) {
			$cache[ $form_id ] = $out;
			return $out;
		}
		foreach ( $form['form_fields'] as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = isset( $field['type'] ) ? (string) $field['type'] : '';
			if ( in_array( $type, minn_admin_everest_skip_types(), true ) ) {
				continue;
			}
			$key = isset( $field['meta-key'] ) ? (string) $field['meta-key'] : '';
			if ( '' === $key ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) );
			$out[ $key ] = $label ? $label : ucfirst( str_replace( array( '-', '_' ), ' ', $key ) );
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	$cache[ $form_id ] = $out;
	return $out;
}

/** Form titles map: [id => title] (published everest_form posts). */
function minn_admin_everest_titles() {
	static $titles = null;
	if ( null === $titles ) {
		$titles = array();
		if ( function_exists( 'evf_get_all_forms' ) ) {
			// Cap-aware list from their helper (also skips entry-storage-disabled forms).
			foreach ( (array) evf_get_all_forms( false, false ) as $id => $title ) {
				$titles[ (int) $id ] = (string) $title;
			}
		} else {
			$posts = get_posts( array(
				'post_type'      => 'everest_form',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			) );
			foreach ( $posts as $p ) {
				$titles[ (int) $p->ID ] = (string) $p->post_title;
			}
		}
	}
	return $titles;
}

/**
 * Flatten a stored meta value for list/detail display. Never unserialize —
 * plain strings pass through; JSON objects use a value key when present;
 * PHP-serialized blobs yield their string leaves via a safe regex.
 *
 * @param mixed $v Raw meta_value.
 * @return string
 */
function minn_admin_everest_flat_value( $v ) {
	if ( is_array( $v ) ) {
		$flat = array();
		array_walk_recursive( $v, function ( $leaf ) use ( &$flat ) {
			if ( '' !== trim( (string) $leaf ) ) {
				$flat[] = (string) $leaf;
			}
		} );
		return implode( ', ', $flat );
	}
	$v = (string) $v;
	if ( '' === $v ) {
		return '';
	}
	// JSON payload (some field types store {"value":…}).
	if ( ( '{' === $v[0] || '[' === $v[0] ) ) {
		$j = json_decode( $v, true );
		if ( is_array( $j ) ) {
			if ( isset( $j['value'] ) ) {
				return minn_admin_everest_flat_value( $j['value'] );
			}
			return minn_admin_everest_flat_value( $j );
		}
	}
	// PHP-serialized: pull s:N:"…" leaves (skip short structural keys).
	if ( preg_match( '/^[aObCds]:/', $v ) ) {
		$flat = array();
		if ( preg_match_all( '/s:\d+:"((?:[^"\\\\]|\\\\.)*)"/s', $v, $m ) ) {
			$skip = array( 'type', 'label', 'name', 'value', 'id', 'meta_key', 'meta-key' );
			foreach ( $m[1] as $s ) {
				$s = stripcslashes( $s );
				if ( '' === trim( $s ) || in_array( $s, $skip, true ) ) {
					continue;
				}
				// Field type tokens aren't answers.
				if ( preg_match( '/^(checkbox|radio|select|payment-)/', $s ) ) {
					continue;
				}
				$flat[] = $s;
			}
		}
		return $flat ? implode( ', ', $flat ) : '';
	}
	return trim( $v );
}

/**
 * Answers for one entry as [meta_key => flat string].
 *
 * @param int $entry_id Entry id.
 * @return array<string,string>
 */
function minn_admin_everest_answers( $entry_id ) {
	global $wpdb;
	$out  = array();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT meta_key, meta_value FROM {$wpdb->prefix}evf_entrymeta WHERE entry_id = %d AND meta_key NOT LIKE %s", // phpcs:ignore
		(int) $entry_id,
		$wpdb->esc_like( '_evf_' ) . '%'
	) );
	foreach ( (array) $rows as $row ) {
		$key = (string) $row->meta_key;
		if ( '' === $key || 0 === strpos( $key, '_' ) ) {
			continue;
		}
		$val = minn_admin_everest_flat_value( $row->meta_value );
		if ( '' !== $val ) {
			$out[ $key ] = $val;
		}
	}
	return $out;
}

/**
 * Trash / spam / restore / delete through their Admin_Entries helpers when
 * available; otherwise prefix-scoped SQL mirroring the same semantics.
 *
 * @param int    $entry_id Entry id.
 * @param string $op       trash|publish|spam|delete.
 * @param int    $form_id  Form id (delete only).
 * @return true|WP_Error
 */
function minn_admin_everest_set_status( $entry_id, $op, $form_id = 0 ) {
	minn_admin_everest_load_entries_class();
	if ( 'delete' === $op ) {
		if ( class_exists( 'EVF_Admin_Entries' ) ) {
			$ok = EVF_Admin_Entries::remove_entry( (int) $entry_id, (int) $form_id );
			return $ok ? true : new WP_Error( 'delete_failed', 'Everest Forms could not delete the entry.', array( 'status' => 500 ) );
		}
		global $wpdb;
		do_action( 'everest_forms_before_delete_entries', $entry_id );
		$del = $wpdb->delete( $wpdb->prefix . 'evf_entries', array( 'entry_id' => (int) $entry_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'evf_entrymeta', array( 'entry_id' => (int) $entry_id ), array( '%d' ) );
		do_action( 'everest_forms_after_delete_entries', $form_id, $entry_id );
		return $del ? true : new WP_Error( 'delete_failed', 'Could not delete the entry.', array( 'status' => 500 ) );
	}

	// unspam is their alias for restore-from-spam → publish.
	$status = 'unspam' === $op ? 'publish' : $op;
	if ( ! in_array( $status, array( 'publish', 'trash', 'spam' ), true ) ) {
		return new WP_Error( 'bad_status', 'Unknown status.', array( 'status' => 400 ) );
	}
	if ( class_exists( 'EVF_Admin_Entries' ) ) {
		// Their update_status handles trash prior-status meta + unspam→publish.
		$target = ( 'publish' === $status && 'unspam' === $op ) ? 'unspam' : $status;
		EVF_Admin_Entries::update_status( (int) $entry_id, $target );
		return true;
	}
	// Minimal fallback (no prior-status meta).
	global $wpdb;
	$wpdb->update(
		$wpdb->prefix . 'evf_entries',
		array( 'status' => $status ),
		array( 'entry_id' => (int) $entry_id ),
		array( '%s' ),
		array( '%d' )
	);
	return true;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_everest_active() || ! minn_admin_everest_can() ) {
		return $surfaces;
	}

	$surfaces['everest-forms'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => 'Everest Forms',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is the filter above (their cap model)
		'collection' => array(
			'viewLabel' => 'Entries',
			'route'     => 'minn-admin/v1/everest/entries',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			// Same three buckets as their entries screen.
			'filter'    => array(
				'label'   => 'Status',
				'options' => array(
					array( 'publish', 'Received' ),
					array( 'spam', 'Spam' ),
					array( 'trash', 'Trash' ),
				),
				'query'   => 'status={v}',
			),
			'tabs'      => array(
				'route'    => 'minn-admin/v1/everest/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => 'Entry', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => 'Form' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '96px' ),
				// date_created is current_time( 'mysql', true ) — UTC.
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/everest/entries/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Mark as spam',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/everest/entries/{id}/spam',
					'confirm' => 'Mark this entry as spam? Find it under the Spam filter.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'publish' ),
				),
				array(
					'label'  => 'Not spam',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/everest/entries/{id}/unspam',
					'when'   => array( 'key' => 'status', 'equals' => 'spam' ),
				),
				array(
					'label'   => 'Trash entry',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/everest/entries/{id}/trash',
					'confirm' => 'Move this entry to trash?',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'publish' ),
				),
				array(
					'label'  => 'Restore',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/everest/entries/{id}/restore',
					'when'   => array( 'key' => 'status', 'equals' => 'trash' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/everest/entries/{id}',
					'confirm' => 'Delete this entry permanently? There is no undo.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trash' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/everest/entries/{id}',
					'confirm' => 'Delete this entry permanently? There is no undo.',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'spam' ),
				),
			),
			'bulk'      => array(
				array(
					'label'   => 'Mark as spam',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/everest/entries/{id}/spam',
					'confirm' => 'Mark the selected entries as spam?',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'publish' ),
				),
				array(
					'label'   => 'Trash',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/everest/entries/{id}/trash',
					'confirm' => 'Move the selected entries to trash?',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'publish' ),
				),
				array(
					'label'  => 'Restore',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/everest/entries/{id}/restore',
					'when'   => array( 'key' => 'status', 'equals' => 'trash' ),
				),
				array(
					'label'   => 'Delete permanently',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/everest/entries/{id}',
					'confirm' => 'Delete the selected entries permanently?',
					'danger'  => true,
					'when'    => array( 'key' => 'status', 'equals' => 'trash' ),
				),
			),
		),
		'manage'     => array(
			'viewLabel' => 'Forms',
			'route'     => 'minn-admin/v1/everest/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Form', 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => 'Entries', 'format' => 'num' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => 'Edit in Everest Forms ↗',
					'href'  => admin_url( 'admin.php?page=evf-builder&tab=fields&form_id={id}' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_everest_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/everest/forms', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return minn_admin_everest_can();
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$manage = ! empty( $request['manage'] );
			$out    = array();
			foreach ( minn_admin_everest_titles() as $id => $title ) {
				$row = array( 'id' => (int) $id, 'title' => $title );
				if ( $manage ) {
					$row['entries'] = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}evf_entries WHERE form_id = %d AND status = 'publish'", // phpcs:ignore
						$id
					) );
					$post = get_post( $id );
					$row['status'] = ( $post && 'publish' === $post->post_status ) ? 'active' : ( $post ? (string) $post->post_status : 'unknown' );
				}
				$out[] = $row;
			}
			return rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/everest/entries', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return minn_admin_everest_can();
		},
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
			$entry_t  = $wpdb->prefix . 'evf_entries';
			$meta_t   = $wpdb->prefix . 'evf_entrymeta';

			$status = (string) ( $request['status'] ?: 'publish' );
			if ( ! in_array( $status, array( 'publish', 'spam', 'trash' ), true ) ) {
				$status = 'publish';
			}

			$where = 'WHERE e.status = %s';
			$args  = array( $status );
			if ( $request['form_id'] ) {
				$where .= ' AND e.form_id = %d';
				$args[] = (int) $request['form_id'];
			}
			if ( $request['search'] ) {
				// Answer-meta LIKE, excluding their bookkeeping keys.
				$where .= " AND EXISTS ( SELECT 1 FROM {$meta_t} m WHERE m.entry_id = e.entry_id AND m.meta_key NOT LIKE %s AND m.meta_value LIKE %s )";
				$args[] = $wpdb->esc_like( '_evf_' ) . '%';
				$args[] = '%' . $wpdb->esc_like( (string) $request['search'] ) . '%';
			}
			$total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$entry_t} e {$where}", // phpcs:ignore
				...$args
			) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT e.entry_id, e.form_id, e.status, e.date_created FROM {$entry_t} e {$where} ORDER BY e.entry_id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				...array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );

			$titles = minn_admin_everest_titles();
			$items  = array();
			foreach ( (array) $rows as $row ) {
				$form_id = (int) $row->form_id;
				$fields  = minn_admin_everest_fields( $form_id );
				$answers = minn_admin_everest_answers( (int) $row->entry_id );
				$parts   = array();
				foreach ( $fields as $slug => $label ) {
					if ( isset( $answers[ $slug ] ) && '' !== $answers[ $slug ] ) {
						$parts[] = $answers[ $slug ];
					}
					if ( count( $parts ) >= 3 ) {
						break;
					}
				}
				// Fallback when the form no longer carries those fields.
				if ( ! $parts ) {
					foreach ( $answers as $val ) {
						if ( '' !== $val ) {
							$parts[] = $val;
						}
						if ( count( $parts ) >= 3 ) {
							break;
						}
					}
				}
				$items[] = array(
					'id'         => (int) $row->entry_id,
					'summary'    => $parts ? implode( ' · ', $parts ) : '(empty entry)',
					'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
					'status'     => (string) $row->status,
					'date'       => str_replace( ' ', 'T', (string) $row->date_created ),
				);
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/everest/entries/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return minn_admin_everest_can();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT entry_id, form_id, status, date_created, user_ip_address, viewed FROM {$wpdb->prefix}evf_entries WHERE entry_id = %d", // phpcs:ignore
					(int) $request['id']
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				$form_id = (int) $row->form_id;
				$fields  = minn_admin_everest_fields( $form_id );
				$answers = minn_admin_everest_answers( (int) $row->entry_id );
				$titles  = minn_admin_everest_titles();

				$rows = array();
				foreach ( $fields as $slug => $label ) {
					$rows[] = array(
						'label' => $label,
						'value' => isset( $answers[ $slug ] ) && '' !== $answers[ $slug ] ? $answers[ $slug ] : '—',
					);
				}
				foreach ( $answers as $slug => $value ) {
					if ( ! isset( $fields[ $slug ] ) && '' !== $value ) {
						$rows[] = array(
							'label' => ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) ),
							'value' => $value,
						);
					}
				}
				// date_created is UTC — format in site TZ for the meta card.
				$meta = array(
					array( 'label' => 'Form', 'value' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id ),
					array( 'label' => 'Entry', 'value' => '#' . (int) $row->entry_id ),
					array( 'label' => 'Status', 'value' => (string) $row->status ),
					array( 'label' => 'Submitted', 'value' => date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( $row->date_created ) ) ) ),
				);
				if ( ! empty( $row->user_ip_address ) ) {
					$meta[] = array( 'label' => 'IP', 'value' => (string) $row->user_ip_address );
				}
				// Opening a detail marks the entry viewed (their semantics).
				if ( empty( $row->viewed ) ) {
					$wpdb->update(
						$wpdb->prefix . 'evf_entries',
						array( 'viewed' => 1 ),
						array( 'entry_id' => (int) $row->entry_id ),
						array( '%d' ),
						array( '%d' )
					);
				}
				return rest_ensure_response( array(
					'kind'     => 'entry',
					'status'   => (string) $row->status,
					'sections' => array(
						array( 'title' => 'Answers', 'rows' => $rows ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=evf-entries&form_id=' . $form_id . '&view-entry=' . (int) $row->entry_id ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function () {
				return minn_admin_everest_can( 'delete' );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$id  = (int) $request['id'];
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT entry_id, form_id, status FROM {$wpdb->prefix}evf_entries WHERE entry_id = %d", // phpcs:ignore
					$id
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				// Permanent delete is for trash/spam only (Received → Trash first).
				if ( ! in_array( (string) $row->status, array( 'trash', 'spam' ), true ) ) {
					return new WP_Error( 'not_trashed', 'Move the entry to trash (or spam) before deleting permanently.', array( 'status' => 400 ) );
				}
				$result = minn_admin_everest_set_status( $id, 'delete', (int) $row->form_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$still = $wpdb->get_var( $wpdb->prepare(
					"SELECT entry_id FROM {$wpdb->prefix}evf_entries WHERE entry_id = %d", // phpcs:ignore
					$id
				) );
				if ( $still ) {
					return new WP_Error( 'delete_failed', 'Everest Forms reported success but the entry still exists.', array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => 'Entry deleted permanently.' ) );
			},
		),
	) );

	foreach ( array(
		'trash'   => 'trash',
		'restore' => 'publish',
		'spam'    => 'spam',
		'unspam'  => 'unspam',
	) as $slug => $op ) {
		register_rest_route( 'minn-admin/v1', '/everest/entries/(?P<id>\d+)/' . $slug, array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return minn_admin_everest_can( 'delete' );
			},
			'callback'            => function ( WP_REST_Request $request ) use ( $op, $slug ) {
				global $wpdb;
				$id  = (int) $request['id'];
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT entry_id, form_id, status FROM {$wpdb->prefix}evf_entries WHERE entry_id = %d", // phpcs:ignore
					$id
				) );
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Entry not found', array( 'status' => 404 ) );
				}
				$cur = (string) $row->status;
				if ( 'trash' === $slug && 'publish' !== $cur ) {
					return new WP_Error( 'bad_status', 'Only received entries can be trashed.', array( 'status' => 400 ) );
				}
				if ( 'restore' === $slug && 'trash' !== $cur ) {
					return new WP_Error( 'bad_status', 'Only trashed entries can be restored.', array( 'status' => 400 ) );
				}
				if ( 'spam' === $slug && 'publish' !== $cur ) {
					return new WP_Error( 'bad_status', 'Only received entries can be marked spam.', array( 'status' => 400 ) );
				}
				if ( 'unspam' === $slug && 'spam' !== $cur ) {
					return new WP_Error( 'bad_status', 'Only spam entries can be unmarked.', array( 'status' => 400 ) );
				}
				$result = minn_admin_everest_set_status( $id, $op, (int) $row->form_id );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$fresh = $wpdb->get_var( $wpdb->prepare(
					"SELECT status FROM {$wpdb->prefix}evf_entries WHERE entry_id = %d", // phpcs:ignore
					$id
				) );
				$msgs = array(
					'trash'   => 'Entry moved to trash.',
					'restore' => 'Entry restored.',
					'spam'    => 'Entry marked as spam.',
					'unspam'  => 'Entry marked not spam.',
				);
				return rest_ensure_response( array(
					'ok'      => true,
					'status'  => (string) $fresh,
					'message' => $msgs[ $slug ],
				) );
			},
		) );
	}
} );
