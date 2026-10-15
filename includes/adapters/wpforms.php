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
 * The entries are a kernel resource (docs/adapter-kernel.md): the kernel
 * reads the entry table and owns the routes, gates and errors (search is a
 * LIKE over the fields JSON, their own fallback search shape). WRITES go through their
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

/**
 * Per-FORM entry capability.
 *
 * WPForms' Access Controls split entry access into own-forms and others-forms
 * and enforce that split through meta capabilities that must be passed the form
 * id — `view_entries_form_single`, `edit_entries_form_single`,
 * `delete_entries_form_single` (src/Pro/Access/Capabilities.php). The plugin
 * itself always calls them with an id (Views.php, ListTable.php, Analytics.php,
 * Preview.php). The bare `view_entries` is only the menu-level "can they see
 * the Entries item at all" check, so using it per row lets a user provisioned
 * for their OWN forms read and delete every other form's entries.
 *
 * On Lite the graded caps map down to the flat one, so this is a no-op there.
 */
function minn_admin_wpforms_can_form( $form_id, $cap = 'view_entries_form_single' ) {
	$form_id = (int) $form_id;
	if ( $form_id <= 0 || ! function_exists( 'wpforms_current_user_can' ) ) {
		return false;
	}
	return (bool) wpforms_current_user_can( $cap, $form_id );
}

/**
 * The form ids whose entries this user may read. Null means "no restriction".
 */
function minn_admin_wpforms_allowed_form_ids() {
	// The universe must be every form the entries table can point at, not the
	// menu's list. minn_admin_wpforms_form_titles() is capped at 200 and
	// publish-only, so deriving the scope from it, and then treating
	// "allowed matches the sample" as unrestricted, dropped the constraint
	// entirely on a site with more forms than the cap or with entries on an
	// unpublished form — exactly the rows the sample could not evaluate.
	// Every form the entries table points at, counted in one grouped read. A
	// read that fills its cap may have missed some, so it is never taken for
	// the whole universe: the caller is then held to the forms it was seen to
	// pass, never left unrestricted.
	$cap    = 5000;
	$counts = Minn_Admin_Kernel_SQL::totals( 'wpforms_entries', array( 'form_id' ), array( 'n' => array( 'count' => '*' ) ), array(), array(), $cap );
	$capped = count( $counts ) >= $cap;
	$ids    = array_map( 'intval', array_column( $counts, 'form_id' ) );
	foreach ( array_keys( minn_admin_wpforms_form_titles() ) as $fid ) {
		$ids[] = (int) $fid;
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( ! $ids ) {
		return array();
	}
	$allowed = array();
	foreach ( $ids as $fid ) {
		if ( minn_admin_wpforms_can_form( $fid ) ) {
			$allowed[] = $fid;
		}
	}
	// Unrestricted only when the caller passes for every form that exists,
	// which is a statement about the real universe rather than a sample.
	return ! $capped && count( $allowed ) === count( $ids ) ? null : $allowed;
}

/**
 * The form an entry belongs to (0 when the row is gone).
 */
function minn_admin_wpforms_entry_form_id( $entry_id ) {
	return (int) Minn_Admin_Kernel_SQL::latest( 'wpforms_entries', 'form_id', array( 'entry_id' => (int) $entry_id ), 'entry_id' );
}

/**
 * 403 unless the caller may act on the form behind this entry.
 */
function minn_admin_wpforms_guard_entry( $entry_id, $cap = 'view_entries_form_single' ) {
	$form_id = minn_admin_wpforms_entry_form_id( $entry_id );
	if ( ! $form_id ) {
		return new WP_Error( 'not_found', __( 'Entry not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( ! minn_admin_wpforms_can_form( $form_id, $cap ) ) {
		return new WP_Error( 'forbidden', __( 'You cannot access entries for that form.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	return $form_id;
}

function minn_admin_wpforms_table() {
	global $wpdb;
	return $wpdb->prefix . 'wpforms_entries';
}

/** Map of wpforms form id => title, for tabs and entry meta. */
/**
 * Form titles this user may actually SEE, for the tab strip and status card.
 *
 * WPForms' own getter defaults $args['cap'] to view_form_single once Access
 * Controls are on, so a user provisioned for their own forms does not see
 * other authors' form names in the plugin. The entry routes were scoped in
 * 0.26.0; these two siblings in the same file were not, and a form TITLE is
 * business structure ("Legal Complaint Intake") and a targeting aid.
 *
 * @return array<int, string> id => title
 */
function minn_admin_wpforms_visible_form_titles() {
	$titles = array();
	foreach ( minn_admin_wpforms_form_titles() as $id => $title ) {
		if ( minn_admin_wpforms_can_form( $id, 'view_form_single' ) ) {
			$titles[ (int) $id ] = $title;
		}
	}
	return $titles;
}

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
	return $summary ?: __( '(empty entry)', 'minn-admin' );
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
	// Confine the tallies to the forms this user may read, the way the entry
	// routes are confined; site-wide counts told a per-form user how much
	// traffic every other form gets.
	$allowed = minn_admin_wpforms_allowed_form_ids();
	if ( is_array( $allowed ) && ! $allowed ) {
		return minn_admin_forms_none( array() );
	}
	// date is UTC: the chart groups onto the site's day in SQL. Entries
	// arrive from unauthenticated submissions, so a per-row fetch is unbounded.
	$inbox = array( 'status' => array( 'not' => array( 'spam', 'trash' ) ) );
	$n     = Minn_Admin_Kernel::inbox_counts( array(
		'table'    => 'wpforms_entries',
		'scope'    => array( 'column' => 'form_id', 'allowed' => $allowed ),
		'received' => $inbox,
		'unread'   => $inbox + array( 'viewed' => 0 ),
		'spam'     => array( 'status' => 'spam' ),
		'chart'    => array( 'column' => 'date', 'clock' => 'utc', 'where' => array( 'status' => array( '', 'spam' ) ), 'split' => 'status', 'secondary' => array( 'spam' ) ),
	) );
	return minn_admin_forms_card(
		minn_admin_forms_unread( $n['unread'], $n['received'], $n['spam'] ),
		count( minn_admin_wpforms_visible_form_titles() ),
		$n['days'],
		array( 'label' => __( 'Open WPForms ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=wpforms-entries' ) ),
		__( 'Spam', 'minn-admin' )
	);
}

/** An entry's list row. */
function minn_admin_wpforms_row( array $row ) {
	// Once per page of rows, per user: the titles are what that user may see.
	static $seen = array();
	$uid = get_current_user_id();
	if ( ! isset( $seen[ $uid ] ) ) {
		$seen[ $uid ] = minn_admin_wpforms_visible_form_titles();
	}
	$titles  = $seen[ $uid ];
	$form_id = (int) $row['form_id'];
	return array(
		'id'         => (int) $row['entry_id'],
		'summary'    => minn_admin_wpforms_summary( $row['fields'] ),
		'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : ( 'Form #' . $form_id ),
		'starred'    => ( (int) $row['starred'] ) ? '★' : '',
		'status'     => minn_admin_wpforms_display_status( (object) $row ),
		'date'       => $row['date'],
	);
}

/** An entry's detail; opening it marks it viewed, as their entry screen does. */
function minn_admin_wpforms_view( array $raw ) {
	$row = (object) $raw;
	// Through their handler (hooks fire), never for spam or trash, and only
	// for someone who may edit this form's entries: WPForms grades editing
	// per form, so someone allowed to read form B and edit only form A must
	// not flip a row on B.
	if ( ! (int) $row->viewed && ! in_array( (string) $row->status, array( 'spam', 'trash' ), true )
		&& minn_admin_wpforms_can( 'edit_entries' )
		&& ! is_wp_error( minn_admin_wpforms_guard_entry( (int) $row->entry_id, 'edit_entries_form_single' ) ) ) {
		try {
			if ( wpforms()->obj( 'entry' )->update( (int) $row->entry_id, array( 'viewed' => 1 ) ) ) {
				$row->viewed = 1;
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Read-only view still renders.
		}
	}
	$answers = array();
	foreach ( minn_admin_wpforms_answers( $row->fields ) as $label => $value ) {
		$answers[] = array( 'label' => $label, 'value' => '' !== $value ? $value : '—' );
	}
	$titles = minn_admin_wpforms_visible_form_titles();
	// `date` is UTC: shown on the site's clock, as the other form adapters
	// show theirs (the page prints this as is).
	$meta   = minn_admin_forms_meta(
		isset( $titles[ (int) $row->form_id ] ) ? $titles[ (int) $row->form_id ] : ( '#' . (int) $row->form_id ),
		(int) $row->entry_id,
		$row->date ? date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( $row->date ) ) ) : null
	);
	if ( '' !== (string) $row->ip_address ) {
		$meta[] = array( 'label' => __( 'IP address', 'minn-admin' ), 'value' => (string) $row->ip_address );
	}
	if ( (int) $row->starred ) {
		$meta[] = array( 'label' => __( 'Starred', 'minn-admin' ), 'value' => '★' );
	}
	return minn_admin_forms_entry( $answers, $meta, admin_url( 'admin.php?page=wpforms-entries&view=details&entry_id=' . (int) $row->entry_id ), array(
		// The row as it stands now, after opening marked it viewed: the
		// page's actions gate on it, not on the list's copy.
		'item' => array(
			'id'      => (int) $row->entry_id,
			'status'  => minn_admin_wpforms_display_status( $row ),
			'starred' => (int) $row->starred ? '★' : '',
		),
		// Editable answers (adapters/wpforms-entry-edit.php).
		'edit' => function_exists( 'minn_admin_wpforms_edit_block' ) ? minn_admin_wpforms_edit_block( $row ) : null,
	) );
}

/**
 * The per-form capability behind a gate name: WPForms' Access Controls grade
 * entries per form through meta capabilities that take the form id.
 */
function minn_admin_wpforms_can_entry( $entry_id, $gate ) {
	$caps = array(
		'view'   => 'view_entries_form_single',
		'edit'   => 'edit_entries_form_single',
		'delete' => 'delete_entries_form_single',
	);
	// A gate this does not know is a no, never the weakest yes.
	return isset( $caps[ $gate ] ) && minn_admin_wpforms_can_form( minn_admin_wpforms_entry_form_id( $entry_id ), $caps[ $gate ] );
}

$minn_admin_wpforms_inbox = array( 'status' => array( 'not' => array( 'spam', 'trash' ) ) );
minn_admin_resource( 'wpforms/entries', function () use ( $minn_admin_wpforms_inbox ) {
	return array(
		'active'    => 'minn_admin_wpforms_active',
		'gates'     => array(
			'view'   => function () {
				return minn_admin_wpforms_can( 'view_entries' );
			},
			'edit'   => function () {
				return minn_admin_wpforms_can( 'edit_entries' );
			},
			'delete' => function () {
				return minn_admin_wpforms_can( 'delete_entries' );
			},
		),
		'item_gate' => 'minn_admin_wpforms_can_entry',
		'labels'    => array(
			'not_found' => __( 'Entry not found.', 'minn-admin' ),
			'forbidden' => __( 'You cannot access entries for that form.', 'minn-admin' ),
		),
		'source'    => array(
			'table'   => 'wpforms_entries',
			'key'     => 'entry_id',
			'columns' => array( 'entry_id', 'form_id', 'status', 'viewed', 'starred', 'fields', 'date' ),
			'detail'  => array(),
		),
		'list'      => array(
			// Inbox behaviour: spam and trash stay in their buckets, and the
			// first option is everything received, so read entries are not hidden.
			'filter' => array(
				'param'   => 'status',
				'values'  => array(
					'inbox'   => $minn_admin_wpforms_inbox,
					'unread'  => $minn_admin_wpforms_inbox + array( 'viewed' => 0 ),
					'read'    => $minn_admin_wpforms_inbox + array( 'viewed' => 1 ),
					'starred' => $minn_admin_wpforms_inbox + array( 'starred' => 1 ),
					'spam'    => array( 'status' => 'spam' ),
					'trash'   => array( 'status' => 'trash' ),
				),
				'default' => 'inbox',
			),
			'tab'    => array(
				'param'  => 'form_id',
				'column' => 'form_id',
				'gate'   => 'minn_admin_wpforms_can_form',
				'refuse' => __( 'You cannot access entries for that form.', 'minn-admin' ),
			),
			// MANDATORY scope: without it an unfiltered list scans the whole
			// entries table, returning other forms' submitter names and emails to
			// a user provisioned only for their own forms.
			'scope'  => array( 'column' => 'form_id', 'allowed' => 'minn_admin_wpforms_allowed_form_ids' ),
			// Their own fallback search shape: a LIKE over the fields JSON.
			'search' => array( 'param' => 'search', 'columns' => array( 'fields' ) ),
			'range'  => array( 'column' => 'date', 'clock' => 'utc' ),
			// Newest submission first by the date WPForms stored, so an entry
			// added out of order (an import) lands where it belongs.
			'order'  => array( 'column' => 'date', 'dir' => 'DESC' ),
			'row'    => 'minn_admin_wpforms_row',
			'fields' => minn_admin_forms_fields( array(
				'starred' => 'text',
				'date'    => array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'iso-utc' ),
			) ),
		),
		'item'      => array( 'view' => 'minn_admin_wpforms_view' ),
		'actions'   => array(
			'star'   => array(
				'gate'   => 'edit',
				'run'    => function ( $id, $row, WP_REST_Request $request ) {
					$on = (int) (bool) $request->get_param( 'on' );
					wpforms()->obj( 'entry' )->update( $id, array( 'starred' => $on ) );
					return $on;
				},
				'expect' => function ( $id, $fresh, $on ) {
					return is_array( $fresh ) && (int) $fresh['starred'] === (int) $on;
				},
				'reply'  => function ( $id, $fresh, $on ) {
					return array( 'starred' => (int) $on );
				},
			),
			// Their delete cleans entry_fields and entry_meta and fires hooks.
			'delete' => minn_admin_forms_delete( function ( $id ) {
				return wpforms()->obj( 'entry' )->delete( $id ) ? true : new WP_Error( 'delete_failed', __( 'WPForms could not delete this entry.', 'minn-admin' ), array( 'status' => 500 ) );
			}, __( 'WPForms could not delete this entry.', 'minn-admin' ), array(
				'gate'  => 'delete',
				'reply' => function () {
					return array( 'deleted' => true );
				},
				'done'  => __( 'Entry deleted.', 'minn-admin' ),
			) ),
		),
	);
} );
unset( $minn_admin_wpforms_inbox );

// Read, unread, spam, trash and restore share one route whose body names the
// change, and the capability depends on which: WPForms offers trash, spam and
// restore only under the delete capability ("Trash can share the same
// capabilities as deleting", its entries ListTable); read and unread ride edit.
minn_admin_endpoint( 'wpforms/entries/(?P<id>\d+)/status', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_wpforms_active',
	'gate'    => function () {
		return minn_admin_wpforms_can( 'edit_entries' ) || minn_admin_wpforms_can( 'delete_entries' );
	},
	'run'     => function ( WP_REST_Request $request, $ctx ) {
		$op = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! in_array( $op, array( 'read', 'unread', 'spam', 'trash', 'restore' ), true ) ) {
			return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$id  = $ctx['id'];
		$row = Minn_Admin_Kernel::load( 'wpforms/entries', $id, in_array( $op, array( 'spam', 'trash', 'restore' ), true ) ? 'delete' : 'edit' );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		// WPForms' own trash and restore keep an entry's typed status
		// (partial, abandoned, a payment state) in a status_prev meta row:
		// trash records it, restore puts it back and deletes the row
		// (Pro\Admin\Entries\ListTable). Skipping that brought a partial
		// entry back as a completed one, here and in WPForms' screen.
		// Spam and not-spam go through WPForms' own SpamEntry, as its list's
		// bulk actions do: the spam reason row, the "Marked as not spam" log
		// line and the hooks (Akismet reporting among them) come with it. Its
		// update runs at edit_entry_single, so that is checked first; a
		// refused call would still leave its meta row behind.
		$spam_obj = null;
		try {
			$spam_obj = wpforms()->obj( 'spam_entry' );
		} catch ( \Throwable $e ) {
			$spam_obj = null;
		}
		$current  = wpforms()->obj( 'entry' )->get( $id );
		$via_spam = $spam_obj && $current && (
			( 'spam' === $op && method_exists( $spam_obj, 'set_as_spam' ) )
			|| ( 'restore' === $op && 'spam' === (string) $current->status && method_exists( $spam_obj, 'set_as_not_spam' ) )
		);
		$refused  = new WP_Error( 'update_refused', __( 'WPForms did not allow that change to this entry.', 'minn-admin' ), array( 'status' => 403 ) );
		if ( $via_spam ) {
			if ( function_exists( 'wpforms_current_user_can' ) && ! wpforms_current_user_can( 'edit_entry_single', $id ) ) {
				return $refused;
			}
			if ( 'spam' === $op ) {
				$spam_obj->set_as_spam( $id, (int) $current->form_id, wp_get_current_user()->display_name );
			} else {
				$spam_obj->set_as_not_spam( $current );
			}
			$after = wpforms()->obj( 'entry' )->get( $id );
			$now   = $after ? (string) $after->status : '';
			if ( ( 'spam' === $op ) !== ( 'spam' === $now ) ) {
				return $refused;
			}
			return array( 'ok' => true, 'status' => $op );
		}
		$prev_status = null;
		$prev_meta   = null;
		if ( 'read' === $op ) {
			$data = array( 'viewed' => 1 );
		} elseif ( 'unread' === $op ) {
			$data = array( 'viewed' => 0 );
		} elseif ( 'restore' === $op ) {
			$restore_to = '';
			try {
				$meta = wpforms()->obj( 'entry_meta' )->get_meta( array( 'entry_id' => $id, 'type' => 'status_prev' ) );
				if ( $meta && isset( $meta[0]->status ) ) {
					$restore_to = (string) $meta[0]->status;
					$prev_meta  = $meta[0];
				}
			} catch ( \Throwable $e ) {
				unset( $e ); // No meta store: restore to plain, as before.
			}
			$data = array( 'status' => $restore_to );
		} else {
			if ( 'trash' === $op ) {
				$prev_status = $current && isset( $current->status ) ? (string) $current->status : '';
			}
			$data = array( 'status' => $op );
		}
		// Their screen passes the delete cap for trash, spam and restore;
		// without it WPForms checks edit_entry_single and silently refuses a
		// delete-only role. A refused update touches nothing else.
		$ok = wpforms()->obj( 'entry' )->update( $id, $data, '', '', in_array( $op, array( 'spam', 'trash', 'restore' ), true ) ? array( 'cap' => 'delete_entry_single' ) : array() );
		if ( ! $ok ) {
			return $refused;
		}
		if ( 'trash' === $op && '' !== (string) $prev_status && 'trash' !== $prev_status ) {
			wpforms()->obj( 'entry_meta' )->add(
				array(
					'entry_id' => $id,
					'form_id'  => (int) $row['form_id'],
					'user_id'  => get_current_user_id(),
					'type'     => 'status_prev',
					'data'     => '',
					'status'   => $prev_status,
				),
				'entry_meta'
			);
		}
		if ( $prev_meta && isset( $prev_meta->id ) ) {
			wpforms()->obj( 'entry_meta' )->delete_by( 'id', $prev_meta->id );
		}
		return array( 'ok' => true, 'status' => $op );
	},
) );

// Forms list for the tab strip, and the Forms view with entry counts.
minn_admin_endpoint( 'wpforms/forms', array(
	'active' => 'minn_admin_wpforms_active',
	'gate'   => function () {
		return minn_admin_wpforms_can( 'view_entries' );
	},
	'run'    => function ( WP_REST_Request $request ) {
		$out = array();
		if ( $request->get_param( 'manage' ) ) {
			// The Forms view: entry counts and when each form last changed.
			$counts = array();
			foreach ( Minn_Admin_Kernel_SQL::totals( 'wpforms_entries', array( 'form_id' ), array( 'n' => array( 'count' => '*' ) ), array(), array(), 5000 ) as $r ) {
				$counts[ (int) $r['form_id'] ] = (int) $r['n'];
			}
			foreach ( minn_admin_wpforms_visible_form_titles() as $id => $title ) {
				$out[] = array(
					'id'      => $id,
					'title'   => $title,
					// WPForms prints a form's entry count only to people who
					// may read that form's entries; the others get none.
					'entries' => minn_admin_wpforms_can_form( $id ) ? ( $counts[ $id ] ?? 0 ) : null,
					'date'    => str_replace( ' ', 'T', (string) get_post_field( 'post_modified_gmt', $id ) ) . 'Z',
				);
			}
			return array( 'items' => $out, 'total' => count( $out ) );
		}
		foreach ( minn_admin_wpforms_visible_form_titles() as $id => $title ) {
			$out[] = array( 'id' => $id, 'title' => $title );
		}
		return $out;
	},
) );

minn_admin_endpoint( 'wpforms/status', array(
	'active' => 'minn_admin_wpforms_active',
	'gate'   => function () {
		return minn_admin_wpforms_can( 'view_entries' );
	},
	'run'    => 'minn_admin_wpforms_status_model',
) );

minn_admin_add_surface( 'wpforms', function () {
	return minn_admin_wpforms_active() && minn_admin_wpforms_can();
}, function () {
	// Read, unread, spam, trash and restore ride the status endpoint above.
	$status = function ( $label, $op, $when, $extra = array() ) {
		$a = array(
			'label'  => $label,
			'method' => 'POST',
			'route'  => 'minn-admin/v1/wpforms/entries/{id}/status',
			'body'   => array( 'status' => $op ),
		);
		if ( null !== $when ) {
			$a['when'] = array( 'key' => 'status', 'equals' => $when );
		}
		return array_merge( $a, $extra );
	};
	$ui = minn_admin_forms_ui( 'wpforms/entries' );
	return minn_admin_forms_surface( 'wpforms/entries', array(
		'sub'     => 'WPForms',
		'plugin'  => array( 'wpforms-lite', 'wpforms' ),
		'api'     => 'wpforms',
		'filter'  => array( 'inbox' => 'Received', 'unread' => 'Unread', 'read' => 'Read', 'starred' => 'Starred', 'spam' => 'Spam', 'trash' => 'Trash' ),
		'more'    => array( array( 'key' => 'starred', 'label' => '★', 'width' => '40px' ) ),
		'utc'     => true,
		'actions' => array(
			$status( __( 'Mark read', 'minn-admin' ), 'read', 'unread' ),
			$status( __( 'Mark unread', 'minn-admin' ), 'unread', 'read' ),
			$ui( 'star', array( 'label' => __( 'Star', 'minn-admin' ), 'body' => array( 'on' => 1 ), 'key' => 'starred', 'when' => '' ) ),
			$ui( 'star', array( 'label' => __( 'Unstar', 'minn-admin' ), 'body' => array( 'on' => 0 ), 'key' => 'starred', 'when' => '★' ) ),
			$status( __( 'Mark spam', 'minn-admin' ), 'spam', 'read' ),
			$status( __( 'Not spam', 'minn-admin' ), 'restore', 'spam' ),
			$status( __( 'Trash', 'minn-admin' ), 'trash', 'read' ),
			$status( __( 'Restore', 'minn-admin' ), 'restore', 'trash' ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'key' => 'status', 'when' => 'trash' ) ),
		),
		'bulk'    => array(
			$status( __( 'Mark read', 'minn-admin' ), 'read', null ),
			$status( __( 'Trash', 'minn-admin' ), 'trash', null, array( 'danger' => true ) ),
		),
		// The forms themselves: each opens its notifications and confirmations
		// page (wpforms-emails.php); building forms stays in WPForms.
		'manage'  => minn_admin_forms_manage( 'wpforms', __( 'Edit in WPForms ↗', 'minn-admin' ), admin_url( 'admin.php?page=wpforms-builder&view=fields&form_id={id}' ), array(
			array( 'key' => 'date', 'label' => __( 'Updated', 'minn-admin' ), 'format' => 'ago' ),
		), array(
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'open'     => array( 'route' => 'wpforms/form/{id}' ),
		) ),
	) );
} );
