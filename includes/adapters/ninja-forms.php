<?php
/**
 * Bundled adapter: Ninja Forms — forms family (entries + forms).
 *
 * Ninja Forms keeps form definitions in {prefix}nf3_forms / nf3_fields but
 * stores every submission as an `nf_sub` post with plain postmeta
 * (`_form_id`, `_seq_num`, `_field_{id}` per answer). The entries are a
 * kernel resource (docs/adapter-kernel.md) over a callable source: the
 * list is core's WP_Query and a row is get_post(), so no custom-table SQL
 * and nothing serialized, while the kernel owns the routes, gates, state
 * checks and errors. Field labels come from Ninja Forms' own field models
 * at runtime, so a form edit updates the entry cards with no adapter
 * change. Trash routes through their own Submission model; restore uses
 * wp_untrash_post (Ninja has no restore helper of its own). Received /
 * Trash status filter keeps trashed rows visible and restorable inside Minn.
 *
 * Caps mirror the plugin: everything gates through its own
 * `ninja_forms_admin_submissions_capabilities` filter (default
 * manage_options via `ninja_forms_admin_menu_capabilities`), so a site
 * that grants submissions to editors grants Minn's view too.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_ninja_forms_active() {
	return class_exists( 'Ninja_Forms' ) && Minn_Admin_Kernel_SQL::exists( 'nf3_forms' );
}

function minn_admin_ninja_forms_can() {
	// Resolve this the way Ninja Forms resolves it: seed the submissions
	// filter with the literal manage_options, exactly as their own
	// blocks/bootstrap.php does when deciding who may see submission data.
	//
	// Chaining ninja_forms_admin_menu_capabilities into it first was wrong.
	// That filter governs one hidden options.php-parented Processing page and
	// nothing else, but its name makes it the obvious one to reach for when
	// opening the Ninja Forms menu to editors. A site that had done so was
	// handing the lower role every submission's answers plus trash, restore
	// and permanent delete here, while Ninja Forms' own Submissions screen
	// still asked for manage_options.
	$cap = apply_filters( 'ninja_forms_admin_submissions_capabilities', 'manage_options' );
	return current_user_can( $cap );
}

/** Field types that carry no answer (chrome, not data). */
function minn_admin_ninja_forms_skip_types() {
	return array( 'submit', 'html', 'hr', 'divider', 'recaptcha', 'recaptcha_v3', 'spam', 'note' );
}

/**
 * Input fields for a form via Ninja Forms' own models: [id => label],
 * in form order. A model-layer change degrades to raw meta keys.
 *
 * @param int $form_id Form id.
 * @return array<int,string>
 */
function minn_admin_ninja_forms_fields( $form_id ) {
	static $cache = array();
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$out = array();
	try {
		$fields = Ninja_Forms()->form( (int) $form_id )->get_fields();
		$sort   = array();
		foreach ( (array) $fields as $field ) {
			$type = (string) $field->get_setting( 'type' );
			if ( in_array( $type, minn_admin_ninja_forms_skip_types(), true ) ) {
				continue;
			}
			$label  = trim( wp_strip_all_tags( (string) $field->get_setting( 'label' ) ) );
			$sort[] = array(
				'id'    => (int) $field->get_id(),
				'order' => (int) $field->get_setting( 'order' ),
				'label' => $label ? $label : 'Field ' . $field->get_id(),
			);
		}
		usort( $sort, function ( $a, $b ) {
			return $a['order'] - $b['order'];
		} );
		foreach ( $sort as $f ) {
			$out[ $f['id'] ] = $f['label'];
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	$cache[ $form_id ] = $out;
	return $out;
}

/** Form titles map from nf3_forms. */
function minn_admin_ninja_forms_titles() {
	static $titles = null;
	if ( null === $titles ) {
		$titles = array();
		foreach ( Minn_Admin_Kernel_SQL::select( 'nf3_forms', array( 'id', 'title' ) ) as $r ) {
			$titles[ (int) $r['id'] ] = (string) $r['title'];
		}
	}
	return $titles;
}

/**
 * The status an entry shows: a live submission is a published nf_sub post,
 * which the forms family calls received (the Received filter's own word).
 */
function minn_admin_ninja_forms_state( $post_status ) {
	return 'publish' === (string) $post_status ? 'received' : (string) $post_status;
}

/** A stored answer as Ninja Forms reads it back (their decode_submission_value). */
function minn_admin_ninja_forms_decode( $value ) {
	if ( class_exists( 'WPN_Helper' ) && method_exists( 'WPN_Helper', 'decode_submission_value' ) ) {
		return WPN_Helper::decode_submission_value( $value );
	}
	return $value;
}

/**
 * Answers for one nf_sub post as [field_id => flat string], from its own
 * postmeta (plain core meta; checkbox arrays flatten to comma lists).
 *
 * @param int $post_id Submission post id.
 * @return array<int,string>
 */
function minn_admin_ninja_forms_answers( $post_id ) {
	$out = array();
	foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
		if ( ! preg_match( '/^_field_(\d+)$/', (string) $key, $m ) ) {
			continue;
		}
		// Submitter-authored: a visitor can type a serialized payload into a
		// text field and Ninja Forms stores the string verbatim. The shared
		// decoder instantiates nothing and refuses object payloads; arrays and
		// scalars (all NF stores) round-trip.
		$raw = $values[0];
		$v   = is_serialized( $raw ) ? Minn_Admin::decode_serialized( $raw, $raw ) : $raw;
		// Ninja Forms encodes answers as it stores them (& as &amp;, an
		// apostrophe as &#039;) and decodes them wherever it reads one back;
		// Minn escapes on output, so read them decoded too.
		$v = minn_admin_ninja_forms_decode( $v );
		// An upload answer carries the file's path on disk beside its
		// address; the row only ever needed the address.
		$out[ (int) $m[1] ] = trim( (string) ( is_array( $v ) ? minn_admin_forms_flat( $v ) : $v ) );
	}
	return $out;
}

/** A submission post as the resource's row: the post's own status and date. */
function minn_admin_ninja_forms_sub_row( WP_Post $post ) {
	return array(
		'id'      => (int) $post->ID,
		'status'  => (string) $post->post_status,
		'date'    => (string) $post->post_date,
		'form_id' => (int) get_post_meta( $post->ID, '_form_id', true ),
	);
}

/**
 * One submission, or null for anything that is not an nf_sub post.
 *
 * @param int $id Post id.
 * @return array|null
 */
function minn_admin_ninja_forms_get( $id ) {
	$post = get_post( (int) $id );
	return $post && 'nf_sub' === $post->post_type ? minn_admin_ninja_forms_sub_row( $post ) : null;
}

/** Whether the post is still in the posts table (read past the object cache). */
function minn_admin_ninja_forms_exists( $id ) {
	return null !== Minn_Admin_Kernel_SQL::row( 'posts', 'ID', (int) $id, array( 'ID' ) );
}

/**
 * A page of submissions through core's WP_Query.
 *
 * @param array $q The kernel's list query (page, size, filter, tab, search, request).
 * @return array [ rows, total ]
 */
function minn_admin_ninja_forms_list( array $q ) {
	$meta_query = array();
	if ( $q['tab'] ) {
		$meta_query[] = array(
			'key'   => '_form_id',
			'value' => (string) (int) $q['tab'],
		);
	}
	if ( '' !== $q['search'] ) {
		// Answers live in per-field postmeta; a keyless LIKE clause
		// searches across all of a submission's values.
		$meta_query[] = array(
			'value'   => $q['search'],
			'compare' => 'LIKE',
		);
	}
	// A status-chart bar narrows the list to that day. Submissions are
	// nf_sub POSTS, so this is core's own date_query on post_date, which is
	// the site's clock: the same clock the bounds arrive on and the same one
	// the chart buckets in. inclusive keeps both edges of the day.
	$date_query = array();
	$after      = (string) $q['request']->get_param( 'after' );
	$before     = (string) $q['request']->get_param( 'before' );
	if ( '' !== $after || '' !== $before ) {
		$date_query = array( array( 'inclusive' => true ) );
		if ( '' !== $after ) {
			$date_query[0]['after'] = $after;
		}
		if ( '' !== $before ) {
			$date_query[0]['before'] = $before;
		}
	}
	$wq   = new WP_Query( array(
		'post_type'      => 'nf_sub',
		'post_status'    => $q['filter'],
		'date_query'     => $date_query,
		'posts_per_page' => $q['per_page'],
		'paged'          => $q['page'],
		// Newest submission first by its post date, so an entry added out of
		// order (an import) lands where it belongs; the id breaks ties so
		// pages never overlap.
		'orderby'        => array(
			'date' => 'DESC',
			'ID'   => 'DESC',
		),
		'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		'no_found_rows'  => false,
	) );
	$rows = array();
	foreach ( $wq->posts as $post ) {
		$rows[] = minn_admin_ninja_forms_sub_row( $post );
	}
	return array( $rows, (int) $wq->found_posts );
}

/** A submission's list row: its first answers as the summary. */
function minn_admin_ninja_forms_row( array $row ) {
	$form_id = (int) $row['form_id'];
	$titles  = minn_admin_ninja_forms_titles();
	return array(
		'id'         => (int) $row['id'],
		'summary'    => minn_admin_forms_summary( minn_admin_ninja_forms_answers( $row['id'] ), minn_admin_ninja_forms_fields( $form_id ) ),
		'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
		'status'     => (string) $row['status'],
		'state'      => minn_admin_ninja_forms_state( $row['status'] ),
		'seq'        => (int) get_post_meta( $row['id'], '_seq_num', true ),
		'date'       => $row['date'],
	);
}

/** A submission's detail: every answer, then the submission's own facts. */
function minn_admin_ninja_forms_view( array $row ) {
	$post    = get_post( (int) $row['id'] );
	$form_id = (int) $row['form_id'];
	$titles  = minn_admin_ninja_forms_titles();
	$rows    = minn_admin_forms_answer_rows( minn_admin_ninja_forms_fields( $form_id ), minn_admin_ninja_forms_answers( $post->ID ) );
	$meta    = minn_admin_forms_meta( isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id, (int) get_post_meta( $post->ID, '_seq_num', true ), date_i18n( 'M j, Y g:i a', strtotime( $post->post_date ) ) );
	return minn_admin_forms_entry( $rows, $meta, admin_url( 'admin.php?page=nf-submissions&form_id=' . $form_id ), array(
		// The page's pill; a raw "publish" read as a published post.
		'status' => minn_admin_ninja_forms_state( $post->post_status ),
		// Editable answers (adapters/ninja-forms-entry-edit.php).
		'edit'   => function_exists( 'minn_admin_ninja_forms_edit_block' ) ? minn_admin_ninja_forms_edit_block( $post, $form_id ) : null,
	) );
}

/**
 * Restore a trashed submission. Ninja has no restore helper, so this is
 * wp_untrash_post, as core trash works. Core restores to draft since 5.6
 * unless told otherwise; a draft submission shows in neither Minn's list nor
 * Ninja's, so restore to the status the entry held when it was trashed,
 * publish by default, the way Ninja's own submissions screen expects.
 *
 * @param int $id Post id.
 * @return true|WP_Error
 */
function minn_admin_ninja_forms_restore( $id ) {
	$to_status = function ( $new_status, $post_id, $previous_status ) use ( $id ) {
		if ( (int) $post_id !== (int) $id ) {
			return $new_status;
		}
		return in_array( $previous_status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $previous_status : 'publish';
	};
	add_filter( 'wp_untrash_post_status', $to_status, 10, 3 );
	$result = wp_untrash_post( $id );
	remove_filter( 'wp_untrash_post_status', $to_status, 10 );
	return $result ? true : new WP_Error( 'restore_failed', __( 'Could not restore the entry.', 'minn-admin' ), array( 'status' => 500 ) );
}

minn_admin_resource( 'ninja-forms/entries', function () {
	return array(
		'active'  => 'minn_admin_ninja_forms_active',
		'gates'   => array(
			'view'   => 'minn_admin_ninja_forms_can',
			'manage' => 'minn_admin_ninja_forms_can',
		),
		'labels'  => array(
			'not_found' => __( 'Entry not found', 'minn-admin' ),
		),
		'source'  => array(
			'list'   => 'minn_admin_ninja_forms_list',
			'get'    => 'minn_admin_ninja_forms_get',
			'exists' => 'minn_admin_ninja_forms_exists',
		),
		'list'    => array(
			'filter' => array( 'param' => 'status', 'column' => 'post_status', 'values' => array( 'publish', 'trash' ), 'default' => 'publish' ),
			'tab'    => array( 'param' => 'form_id', 'column' => '_form_id' ),
			'search' => array( 'param' => 'search' ),
			// The source reads after/before into its date_query; this gives the
			// descriptor its day range.
			'range'  => array( 'column' => 'post_date', 'clock' => 'local' ),
			'row'    => 'minn_admin_ninja_forms_row',
			'fields' => minn_admin_forms_fields( array(
				'state' => 'text',
				'seq'   => 'int',
				// post_date is site-local; emitted naked (the client parses local).
				'date'  => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'naked' ),
			) ),
		),
		'item'    => array( 'view' => 'minn_admin_ninja_forms_view' ),
		'actions' => array(
			// Their own model's trash (their screen's semantics).
			'trash'   => array(
				'gate'      => 'manage',
				'run'       => function ( $id ) {
					Ninja_Forms()->form()->sub( $id )->get()->trash();
					return true;
				},
				'expect'    => array( 'status' => 'trash' ),
				'fail_code' => 'trash_failed',
				'fail'      => __( 'Ninja Forms reported success but the entry is not in the trash.', 'minn-admin' ),
				'reply'     => function () {
					return array( 'status' => 'trash' );
				},
				'done'      => __( 'Moved to trash.', 'minn-admin' ),
			),
			'restore' => array(
				'gate'      => 'manage',
				'when'      => array( 'status' => array( 'trash' ) ),
				'code'      => 'not_trashed',
				'refuse'    => __( 'Only trashed entries can be restored.', 'minn-admin' ),
				'run'       => 'minn_admin_ninja_forms_restore',
				'expect'    => function ( $id, $fresh ) {
					return is_array( $fresh ) && 'trash' !== $fresh['status'];
				},
				'fail_code' => 'restore_failed',
				'fail'      => __( 'Could not restore the entry.', 'minn-admin' ),
				'reply'     => function () {
					return array( 'status' => 'publish' );
				},
				'done'      => __( 'Entry restored.', 'minn-admin' ),
			),
			'delete'  => minn_admin_forms_delete( function ( $id ) {
				Ninja_Forms()->form()->sub( $id )->get()->delete();
				return true;
			}, __( 'Entry still exists after delete.', 'minn-admin' ), array(
				'when'   => array( 'status' => array( 'trash' ) ),
				'code'   => 'not_trashed',
				'refuse' => __( 'Move the entry to trash before deleting permanently.', 'minn-admin' ),
			) ),
		),
	);
} );

/**
 * Server-built model for the surface status card (SureForms parity).
 * Ninja Forms tracks no read state, so the headline is the live
 * submission count (nf_sub publish — the same live count the manage
 * view uses, not nf3_forms.subs which survives deletes).
 */
function minn_admin_ninja_forms_status_model() {
	$live = array( 'post_type' => 'nf_sub', 'post_status' => 'publish' );
	// nf_sub post_date is site-local, so the chart groups on the site's days.
	$n     = Minn_Admin_Kernel::inbox_counts( array(
		'table'    => 'posts',
		'received' => $live,
		'chart'    => array( 'column' => 'post_date', 'clock' => 'local', 'where' => $live, 'split' => 'post_status' ),
	) );
	$trash = Minn_Admin_Kernel_SQL::count( 'posts', array( 'post_type' => 'nf_sub', 'post_status' => 'trash' ) );
	return minn_admin_forms_card(
		array(
			'label' => __( 'Submissions', 'minn-admin' ),
			'value' => number_format_i18n( $n['received'] ),
			'hint'  => $trash
				/* translators: %s: number of trashed submissions. */
				? sprintf( _n( '%s in trash', '%s in trash', $trash, 'minn-admin' ), number_format_i18n( $trash ) )
				: __( 'All stored submissions', 'minn-admin' ),
		),
		count( minn_admin_ninja_forms_titles() ),
		$n['days'],
		array( 'label' => __( 'Open Ninja Forms ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=nf-submissions' ) ),
		'',
		__( 'Submissions', 'minn-admin' )
	);
}

minn_admin_endpoint( 'ninja-forms/status', array(
	'active' => 'minn_admin_ninja_forms_active',
	'gate'   => 'minn_admin_ninja_forms_can',
	'run'    => 'minn_admin_ninja_forms_status_model',
) );

minn_admin_endpoint( 'ninja-forms/forms', array(
	'active' => 'minn_admin_ninja_forms_active',
	'gate'   => 'minn_admin_ninja_forms_can',
	'run'    => function ( WP_REST_Request $request ) {
		$rows   = Minn_Admin_Kernel_SQL::select( 'nf3_forms', array( 'id', 'title', 'created_at' ), array(), array( 'title' => 'ASC', 'id' => 'ASC' ) );
		$manage = ! empty( $request['manage'] );
		$out    = array();
		foreach ( array_map( function ( $r ) {
			return (object) $r;
		}, $rows ) as $r ) {
			$row = array(
				'id'    => (int) $r->id,
				'title' => (string) $r->title,
			);
			if ( $manage ) {
				// Live count, not the plugin's all-time `subs` counter
				// (which keeps counting after entries are deleted).
				$row['entries'] = Minn_Admin_Kernel_SQL::count(
					array( 'core' => 'posts', 'joins' => array( 'm' => array( 'core' => 'postmeta', 'on' => array( 'post_id' => 'e.ID' ) ) ) ),
					array( 'post_type' => 'nf_sub', 'post_status' => 'publish', 'm.meta_key' => '_form_id', 'm.meta_value' => (string) $r->id )
				);
				$row['date']    = $r->created_at ? str_replace( ' ', 'T', (string) $r->created_at ) : '';
			}
			$out[] = $row;
		}
		return $out;
	},
) );

minn_admin_add_surface( 'ninja-forms', function () {
	return minn_admin_ninja_forms_active() && minn_admin_ninja_forms_can();
}, function () {
	$ui = minn_admin_forms_ui( 'ninja-forms/entries' );
	return minn_admin_forms_surface( 'ninja-forms/entries', array(
		'sub'     => 'Ninja Forms',
		'plugin'  => 'ninja-forms',
		'api'     => 'ninja-forms',
		'filter'  => array( 'publish' => 'Received', 'trash' => 'Trash' ),
		// `state` is the shown status; `status` stays the post's own for the
		// action gates.
		'pill'    => 'state',
		'after'   => array( array( 'key' => 'seq', 'label' => '#', 'format' => 'num' ) ),
		'actions' => array(
			$ui( 'trash', array( 'label' => __( 'Trash entry', 'minn-admin' ), 'confirm' => __( 'Move this entry to trash?', 'minn-admin' ), 'danger' => true, 'when' => 'publish' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'when' => 'trash' ) ),
		),
		'bulk'    => array(
			$ui( 'trash', array( 'label' => __( 'Trash', 'minn-admin' ), 'confirm' => __( 'Move the selected entries to trash?', 'minn-admin' ), 'danger' => true, 'when' => 'publish' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected entries permanently?', 'minn-admin' ), 'danger' => true, 'when' => 'trash' ) ),
		),
		'manage'  => minn_admin_forms_manage( 'ninja-forms', __( 'Edit in Ninja Forms ↗', 'minn-admin' ), admin_url( 'admin.php?page=ninja-forms&form_id={id}' ), array(
			array( 'key' => 'date', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago' ),
		) ),
	) );
} );
