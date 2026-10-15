<?php
/**
 * Bundled adapter: Everest Forms — forms family (entries + forms).
 *
 * Everest stores submissions in {prefix}evf_entries (+ per-answer rows in
 * evf_entrymeta). The entries are a kernel resource (docs/adapter-kernel.md):
 * the kernel reads the entry table and owns the routes, gates and errors,
 * this file supplies labels from the form's post_content field map at
 * runtime, and status/delete go through EVF_Admin_Entries::update_status /
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
	if ( ! function_exists( 'evf' ) && ! defined( 'EVF_VERSION' ) ) {
		return false;
	}
	return Minn_Admin_Kernel_SQL::exists( 'evf_entries' );
}

/** Their permission model: view-entries caps or the master manage cap. */
function minn_admin_everest_can( $cap = 'view' ) {
	if ( current_user_can( 'manage_everest_forms' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}
	if ( 'delete' === $cap ) {
		return current_user_can( 'everest_forms_delete_entries' );
	}
	if ( 'edit' === $cap ) {
		return current_user_can( 'everest_forms_edit_entries' )
			|| current_user_can( 'everest_forms_edit_others_entries' );
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

/**
 * The status an entry shows: Everest keeps a live entry as "publish", which
 * the forms family calls received (the Received filter's own word).
 */
function minn_admin_everest_state( $status ) {
	return 'publish' === (string) $status ? 'received' : (string) $status;
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
		// An upload answer carries the file's path on disk beside its
		// address; the row only ever needed the address.
		return minn_admin_forms_flat( $v );
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
	$out  = array();
	$rows = Minn_Admin_Kernel_SQL::select( 'evf_entrymeta', array( 'meta_key', 'meta_value' ), array( 'entry_id' => (int) $entry_id, 'meta_key' => array( 'none' => array( array( 'starts' => '_evf_' ) ) ) ), array( 'meta_id' => 'ASC' ) );
	foreach ( array_map( function ( $r ) {
		return (object) $r;
	}, $rows ) as $row ) {
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
			return $ok ? true : new WP_Error( 'delete_failed', __( 'Everest Forms could not delete the entry.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		do_action( 'everest_forms_before_delete_entries', $entry_id );
		$del = Minn_Admin_Kernel_SQL::delete( 'evf_entries', 'entry_id', (int) $entry_id );
		Minn_Admin_Kernel_SQL::delete( 'evf_entrymeta', 'entry_id', (int) $entry_id );
		do_action( 'everest_forms_after_delete_entries', $form_id, $entry_id );
		return $del ? true : new WP_Error( 'delete_failed', __( 'Could not delete the entry.', 'minn-admin' ), array( 'status' => 500 ) );
	}

	// unspam is their alias for restore-from-spam → publish.
	$status = 'unspam' === $op ? 'publish' : $op;
	if ( ! in_array( $status, array( 'publish', 'trash', 'spam' ), true ) ) {
		return new WP_Error( 'bad_status', __( 'Unknown status.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( class_exists( 'EVF_Admin_Entries' ) ) {
		// Their update_status handles trash prior-status meta + unspam→publish.
		$target = ( 'publish' === $status && 'unspam' === $op ) ? 'unspam' : $status;
		EVF_Admin_Entries::update_status( (int) $entry_id, $target );
		return true;
	}
	// Minimal fallback (no prior-status meta).
	Minn_Admin_Kernel_SQL::update( 'evf_entries', 'entry_id', (int) $entry_id, array( 'status' => $status ) );
	return true;
}

/** Server-built model for the surface status card (SureForms parity). */
function minn_admin_everest_status_model() {
	// Counts follow the same scope as the list: a caller provisioned for their
	// own forms must not learn other authors' submission volumes.
	$scope = minn_admin_everest_allowed_form_ids();
	$open  = array( 'label' => __( 'Open Everest Forms ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=evf-entries' ) );
	if ( is_array( $scope ) && ! $scope ) {
		return minn_admin_forms_none( array( $open ), __( '0 total', 'minn-admin' ) );
	}
	// date_created is UTC: the chart buckets each row onto the site's day.
	$n = Minn_Admin_Kernel::inbox_counts( array(
		'table'    => 'evf_entries',
		'scope'    => array( 'column' => 'form_id', 'allowed' => $scope ),
		'received' => array( 'status' => 'publish' ),
		'unread'   => array( 'status' => 'publish', 'viewed' => 1 ),
		'spam'     => array( 'status' => 'spam' ),
		'chart'    => array( 'column' => 'date_created', 'clock' => 'utc', 'where' => array( 'status' => array( 'publish', 'spam' ) ), 'split' => 'status', 'secondary' => array( 'spam' ) ),
	) );
	// "Unread" is everything received but not opened, a NULL included.
	$unread = max( 0, $n['received'] - $n['unread'] );
	$forms  = is_array( $scope ) ? count( $scope ) : count( minn_admin_everest_titles() );
	return minn_admin_forms_card( minn_admin_forms_unread( $unread, $n['received'], $n['spam'] ), $forms, $n['days'], $open, __( 'Spam', 'minn-admin' ) );
}

/**
 * Per-ENTRY capability, through Everest's own object-aware mapping.
 *
 * Everest authorizes entries with meta capabilities that take the ENTRY id —
 * everest_forms_view_entry / edit_entry / delete_entry. EVF_Install's
 * filter_map_meta_cap resolves the entry to its form, compares the form's
 * post_author with the current user, and maps to the *_entries (own) or
 * *_others_entries (someone else's) primitive. Checking only the own-forms
 * primitives with no id collapses that split, so a user granted entries for
 * their own forms reads and deletes every author's submissions.
 */
/**
 * Which forms this caller may read entries for, or null for unrestricted.
 *
 * Everest resolves an ENTRY to its form and compares the form's post_author
 * before mapping everest_forms_view_entry down to the *own* or the *others*
 * primitive (EVF_Install::filter_map_meta_cap). A caller provisioned for their
 * own forms only must therefore not see anyone else's entries.
 *
 * The universe is deliberately every form regardless of status and with no
 * row cap: a scope computed from a truncated or publish-only sample would be
 * narrower than the data it is filtering, and the gap would fail open.
 *
 * @return int[]|null Allowed form ids, or null when the caller may read all.
 */
function minn_admin_everest_allowed_form_ids() {
	if ( current_user_can( 'manage_everest_forms' )
		|| current_user_can( 'manage_options' )
		|| current_user_can( 'everest_forms_view_others_entries' ) ) {
		return null;
	}
	// Every status the site knows, trash included ('any' leaves trash out),
	// and no query filters: another plugin's pre_get_posts must not reshape
	// a security scope.
	$ids = get_posts( array(
		'post_type'        => 'everest_form',
		'post_status'      => array_keys( get_post_stati() ),
		'numberposts'      => -1,
		'fields'           => 'ids',
		'author'           => get_current_user_id(),
		'suppress_filters' => true,
	) );
	return array_map( 'intval', (array) $ids );
}

function minn_admin_everest_can_entry( $entry_id, $context = 'view' ) {
	$entry_id = (int) $entry_id;
	$map      = array(
		'view'   => 'everest_forms_view_entry',
		'edit'   => 'everest_forms_edit_entry',
		'delete' => 'everest_forms_delete_entry',
	);
	// A context this does not know is a no, never the weakest yes.
	if ( $entry_id <= 0 || ! isset( $map[ $context ] ) ) {
		return false;
	}
	if ( current_user_can( 'manage_everest_forms' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}
	return current_user_can( $map[ $context ], $entry_id );
}

/** An entry's list row: its first answers as the summary. */
function minn_admin_everest_row( array $row ) {
	$form_id = (int) $row['form_id'];
	$titles  = minn_admin_everest_titles();
	$answers = minn_admin_everest_answers( (int) $row['entry_id'] );
	$fields  = minn_admin_everest_fields( $form_id );
	return array(
		'id'         => (int) $row['entry_id'],
		// Every stored answer says something, so a form that no longer
		// carries any of them falls back to the answers as stored.
		'summary'    => minn_admin_forms_summary( $answers, array_intersect_key( $answers, $fields ) ? $fields : null ),
		'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
		'status'     => (string) $row['status'],
		'state'      => minn_admin_everest_state( $row['status'] ),
		'date'       => $row['date_created'],
	);
}

/** An entry's detail: every answer, then the submission's own facts. */
function minn_admin_everest_view( array $row ) {
	$id      = (int) $row['entry_id'];
	$form_id = (int) $row['form_id'];
	$titles  = minn_admin_everest_titles();
	$rows    = minn_admin_forms_answer_rows( minn_admin_everest_fields( $form_id ), minn_admin_everest_answers( $id ), function ( $slug ) {
		return ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) );
	} );
	// date_created is UTC; the meta card shows the site's time.
	$meta = minn_admin_forms_meta( isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id, $id, date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( $row['date_created'] ) ) ) );
	if ( ! empty( $row['user_ip_address'] ) ) {
		$meta[] = array( 'label' => 'IP', 'value' => (string) $row['user_ip_address'] );
	}
	// Opening a detail marks the entry viewed (their semantics).
	if ( empty( $row['viewed'] ) ) {
		Minn_Admin_Kernel_SQL::update( 'evf_entries', 'entry_id', (int) $id, array( 'viewed' => 1 ) );
	}
	// The page's pill; a raw "publish" read as a published post.
	return minn_admin_forms_entry( $rows, $meta, admin_url( 'admin.php?page=evf-entries&form_id=' . $form_id . '&view-entry=' . $id ), array( 'status' => minn_admin_everest_state( $row['status'] ) ) );
}

/** An action's reply carries the status the entry holds now. */
function minn_admin_everest_status_reply( $id, $fresh ) {
	return array( 'status' => (string) ( $fresh['status'] ?? '' ) );
}

// Everest's own screen restores from trash under the edit meta cap and
// trashes, spams and deletes under the delete one (class-evf-admin-entries.php
// untrash_entry vs trash_entry); the item gate asks it per entry.
minn_admin_resource( 'everest/entries', function () {
	return array(
		'active'    => 'minn_admin_everest_active',
		'gates'     => array(
			'view'   => 'minn_admin_everest_can',
			'edit'   => function () {
				return minn_admin_everest_can( 'edit' );
			},
			'delete' => function () {
				return minn_admin_everest_can( 'delete' );
			},
		),
		'item_gate' => 'minn_admin_everest_can_entry',
		'labels'    => array(
			'not_found' => __( 'Entry not found.', 'minn-admin' ),
			'forbidden' => __( 'You cannot access that entry.', 'minn-admin' ),
		),
		'source'    => array(
			'table'   => 'evf_entries',
			'key'     => 'entry_id',
			'columns' => array( 'entry_id', 'form_id', 'status', 'date_created' ),
			'detail'  => array( 'entry_id', 'form_id', 'status', 'date_created', 'user_ip_address', 'viewed' ),
		),
		'list'      => array(
			// Same three buckets as their entries screen.
			'filter' => array( 'param' => 'status', 'column' => 'status', 'values' => array( 'publish', 'spam', 'trash' ), 'default' => 'publish' ),
			'tab'    => array( 'param' => 'form_id', 'column' => 'form_id' ),
			// The list returns real answer values, so it is the bulk read the
			// per-entry gate exists to prevent: scoped to the caller's own forms
			// unless they hold the others or manage capability.
			'scope'  => array(
				'column'  => 'form_id',
				'allowed' => 'minn_admin_everest_allowed_form_ids',
				'refuse'  => __( 'You cannot view entries for that form.', 'minn-admin' ),
			),
			// Answer meta, minus their bookkeeping keys.
			'search' => array( 'param' => 'search', 'meta' => array( 'table' => 'evf_entrymeta', 'key' => 'entry_id', 'name' => 'meta_key', 'value' => 'meta_value', 'skip' => '_evf_' ) ),
			'range'  => array( 'column' => 'date_created', 'clock' => 'utc' ),
			'order'  => array( 'column' => 'entry_id', 'dir' => 'DESC' ),
			'row'    => 'minn_admin_everest_row',
			'fields' => minn_admin_forms_fields( array(
				'state' => 'text',
				// current_time( 'mysql', true ): the descriptor's column says utc.
				'date'  => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'naked' ),
			) ),
		),
		'item'      => array( 'view' => 'minn_admin_everest_view' ),
		'actions'   => array(
			'trash'   => array(
				'gate'   => 'delete',
				'when'   => array( 'status' => array( 'publish' ) ),
				'refuse' => __( 'Only received entries can be trashed.', 'minn-admin' ),
				'run'    => function ( $id, $row ) {
					return minn_admin_everest_set_status( $id, 'trash', (int) $row['form_id'] );
				},
				'expect' => array( 'status' => 'trash' ),
				'reply'  => 'minn_admin_everest_status_reply',
				'done'   => __( 'Entry moved to trash.', 'minn-admin' ),
			),
			'restore' => array(
				'gate'   => 'edit',
				'when'   => array( 'status' => array( 'trash' ) ),
				'refuse' => __( 'Only trashed entries can be restored.', 'minn-admin' ),
				'run'    => function ( $id, $row ) {
					// Their restore puts back the status the entry was trashed from,
					// read from _evf_trash_entry_status. A trash that wrote none (their
					// own Abilities API) would restore to an empty status that no
					// filter lists and no action accepts, so it goes to received.
					$prior = Minn_Admin_Kernel_SQL::latest( 'evf_entrymeta', 'meta_value', array( 'entry_id' => (int) $id, 'meta_key' => '_evf_trash_entry_status' ), 'meta_id', 'ASC' );
					$op    = in_array( (string) $prior, array( 'publish', 'spam' ), true ) ? 'publish' : 'unspam';
					return minn_admin_everest_set_status( $id, $op, (int) $row['form_id'] );
				},
				'expect' => array( 'status' => array( 'publish', 'spam' ) ),
				'reply'  => 'minn_admin_everest_status_reply',
				'done'   => __( 'Entry restored.', 'minn-admin' ),
			),
			'spam'    => array(
				'gate'   => 'delete',
				'when'   => array( 'status' => array( 'publish' ) ),
				'refuse' => __( 'Only received entries can be marked spam.', 'minn-admin' ),
				'run'    => function ( $id, $row ) {
					return minn_admin_everest_set_status( $id, 'spam', (int) $row['form_id'] );
				},
				'expect' => array( 'status' => 'spam' ),
				'reply'  => 'minn_admin_everest_status_reply',
				'done'   => __( 'Entry marked as spam.', 'minn-admin' ),
			),
			'unspam'  => array(
				'gate'   => 'delete',
				'when'   => array( 'status' => array( 'spam' ) ),
				'refuse' => __( 'Only spam entries can be unmarked.', 'minn-admin' ),
				'run'    => function ( $id, $row ) {
					return minn_admin_everest_set_status( $id, 'unspam', (int) $row['form_id'] );
				},
				'expect' => array( 'status' => 'publish' ),
				'reply'  => 'minn_admin_everest_status_reply',
				'done'   => __( 'Entry marked not spam.', 'minn-admin' ),
			),
			// Permanent delete is for trash or spam only (Received goes to trash first).
			'delete'  => minn_admin_forms_delete( function ( $id, $row ) {
				return minn_admin_everest_set_status( $id, 'delete', (int) $row['form_id'] );
			}, __( 'Everest Forms reported success but the entry still exists.', 'minn-admin' ), array(
				'gate'   => 'delete',
				'when'   => array( 'status' => array( 'trash', 'spam' ) ),
				'code'   => 'not_trashed',
				'refuse' => __( 'Move the entry to trash (or spam) before deleting permanently.', 'minn-admin' ),
			) ),
		),
	);
} );
minn_admin_endpoint( 'everest/status', array(
	'active' => 'minn_admin_everest_active',
	// Their gate takes a capability, so it is asked with its own default:
	// the kernel asks an endpoint's gate with the request.
	'gate'   => function () {
		return minn_admin_everest_can();
	},
	'run'    => function () {
		return minn_admin_everest_status_model();
	},
) );

minn_admin_endpoint( 'everest/forms', array(
	'active' => 'minn_admin_everest_active',
	// Their gate takes a capability, so it is asked with its own default:
	// the kernel asks an endpoint's gate with the request.
	'gate'   => function () {
		return minn_admin_everest_can();
	},
	'run'    => function ( WP_REST_Request $request ) {
		$manage = ! empty( $request['manage'] );
		$scope  = minn_admin_everest_allowed_form_ids();
		$out    = array();
		foreach ( minn_admin_everest_titles() as $id => $title ) {
			if ( is_array( $scope ) && ! in_array( (int) $id, $scope, true ) ) {
				continue;
			}
			$row = array( 'id' => (int) $id, 'title' => $title );
			if ( $manage ) {
				$row['entries'] = Minn_Admin_Kernel_SQL::count( 'evf_entries', array( 'form_id' => (int) $id, 'status' => 'publish' ) );
				$post           = get_post( $id );
				$row['status']  = ( $post && 'publish' === $post->post_status ) ? 'active' : ( $post ? (string) $post->post_status : 'unknown' );
			}
			$out[] = $row;
		}
		return $out;
	},
) );

minn_admin_add_surface( 'everest-forms', function () {
	return minn_admin_everest_active() && minn_admin_everest_can();
}, function () {
	$ui = minn_admin_forms_ui( 'everest/entries' );
	return minn_admin_forms_surface( 'everest/entries', array(
		'sub'     => 'Everest Forms',
		'plugin'  => 'everest-forms',
		'api'     => 'everest',
		'filter'  => array( 'publish' => 'Received', 'spam' => 'Spam', 'trash' => 'Trash' ),
		// `state` is the shown status; `status` stays Everest's own for the
		// action gates.
		'pill'    => 'state',
		'utc'     => true,
		'actions' => array(
			$ui( 'spam', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'confirm' => __( 'Mark this entry as spam? Find it under the Spam filter.', 'minn-admin' ), 'danger' => true, 'when' => 'publish' ) ),
			$ui( 'unspam', array( 'label' => __( 'Not spam', 'minn-admin' ), 'when' => 'spam' ) ),
			$ui( 'trash', array( 'label' => __( 'Trash entry', 'minn-admin' ), 'confirm' => __( 'Move this entry to trash?', 'minn-admin' ), 'danger' => true, 'when' => 'publish' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'when' => 'spam' ) ),
		),
		'bulk'    => array(
			$ui( 'spam', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'confirm' => __( 'Mark the selected entries as spam?', 'minn-admin' ), 'danger' => true, 'when' => 'publish' ) ),
			$ui( 'trash', array( 'label' => __( 'Trash', 'minn-admin' ), 'confirm' => __( 'Move the selected entries to trash?', 'minn-admin' ), 'danger' => true, 'when' => 'publish' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected entries permanently?', 'minn-admin' ), 'danger' => true, 'when' => 'trash' ) ),
		),
		'manage'  => minn_admin_forms_manage( 'everest', __( 'Edit in Everest Forms ↗', 'minn-admin' ), admin_url( 'admin.php?page=evf-builder&tab=fields&form_id={id}' ), array(
			array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
		) ),
	) );
} );
