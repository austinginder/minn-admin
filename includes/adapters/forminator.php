<?php
/**
 * Bundled adapter: Forminator — forms family (entries + forms).
 *
 * Forminator stores submissions in {prefix}frmt_form_entry (+ per-answer
 * rows in frmt_form_entry_meta), with complex answers (name fields,
 * checkboxes, uploads) as arrays under one meta key. The entries are a
 * kernel resource (docs/adapter-kernel.md): the kernel reads the entry
 * table and owns the routes, gates and errors, answers are hydrated through
 * Forminator's OWN entry model (its meta layer owns the array shapes),
 * labels come from its form models at runtime, and delete goes through
 * Forminator_API::delete_entry — its complete cleanup (meta rows go with
 * the entry). Forminator has no entry trash: delete is permanent and the
 * confirm says so.
 *
 * Clock: entries stamp date_i18n() — SITE-LOCAL, emitted naked (the
 * client parses zoneless datetimes as local).
 *
 * Caps mirror the plugin: forminator_is_user_allowed('forminator-entries')
 * resolves its own permission model (manage_options / manage_forminator /
 * the granular manage_forminator_submissions its Permissions settings can
 * grant), so a site that grants submissions to editors grants Minn's view.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

// Forminator's captcha secrets and its PayPal and Stripe configurations.
minn_admin_secrets( 'forminator', array( 'options' => array( 'forminator_*captcha_secret', 'forminator_hcaptcha_secret', 'forminator_turnstile_secret', 'forminator_paypal_configuration', 'forminator_stripe_configuration' ) ) );

function minn_admin_forminator_active() {
	return class_exists( 'Forminator_API' ) && Minn_Admin_Kernel_SQL::exists( 'frmt_form_entry' );
}

function minn_admin_forminator_can() {
	if ( function_exists( 'forminator_is_user_allowed' ) ) {
		return forminator_is_user_allowed( 'forminator-entries' );
	}
	return current_user_can( 'manage_options' );
}

/** Field types that carry no answer (chrome, not data). */
function minn_admin_forminator_skip_types() {
	return array( 'html', 'section', 'page-break', 'pagination', 'captcha', 'cloudflare-turnstile' );
}

/**
 * Input fields for a form via Forminator's own models:
 * [element_id => label], in form order. Degrades to raw meta keys.
 *
 * @param int $form_id Form id.
 * @return array<string,string>
 */
function minn_admin_forminator_fields( $form_id ) {
	static $cache = array();
	if ( isset( $cache[ $form_id ] ) ) {
		return $cache[ $form_id ];
	}
	$out = array();
	try {
		$form = Forminator_API::get_form( (int) $form_id );
		if ( ! is_wp_error( $form ) && $form ) {
			foreach ( (array) $form->get_fields() as $field ) {
				// Their field model resolves properties via __get with no
				// __isset — isset() is always false; read directly.
				$type = (string) $field->type;
				if ( in_array( $type, minn_admin_forminator_skip_types(), true ) ) {
					continue;
				}
				$slug  = (string) $field->slug;
				$label = trim( wp_strip_all_tags( (string) $field->field_label ) );
				if ( '' !== $slug ) {
					$out[ $slug ] = $label ? $label : ucfirst( str_replace( '-', ' ', $slug ) );
				}
			}
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	$cache[ $form_id ] = $out;
	return $out;
}

/** Form display titles: [id => title] (settings.formName is the human name). */
function minn_admin_forminator_titles() {
	static $titles = null;
	if ( null === $titles ) {
		$titles = array();
		try {
			foreach ( (array) Forminator_API::get_forms( null, 1, 100 ) as $f ) {
				$name = isset( $f->settings['formName'] ) && '' !== $f->settings['formName']
					? (string) $f->settings['formName']
					: (string) $f->name;
				$titles[ (int) $f->id ] = $name;
			}
		} catch ( \Throwable $e ) {
			$titles = array();
		}
	}
	return $titles;
}

/**
 * Answers for one entry as [element_id => flat string], hydrated through
 * Forminator's own entry model (arrays flatten to comma lists; addon and
 * internal keys are skipped).
 *
 * @param int $entry_id Entry id.
 * @return array<string,string>
 */
function minn_admin_forminator_answers( $entry_id ) {
	$out = array();
	try {
		$entry = new Forminator_Form_Entry_Model( (int) $entry_id );
		foreach ( (array) $entry->meta_data as $key => $meta ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, 'forminator_addon_' ) || 0 === strpos( $key, '_' ) ) {
				continue;
			}
			$v = isset( $meta['value'] ) ? $meta['value'] : '';
			// An upload answer is a structure, and one of its leaves is the
			// file's absolute path on disk. Their own renderer shows the URL
			// and nothing else, so flattening every leaf printed the server's
			// directory layout into a row that only needed the address.
			if ( is_array( $v ) && isset( $v['file']['file_url'] ) ) {
				$urls = array_filter( array_map( 'strval', (array) $v['file']['file_url'] ) );
				$v    = implode( ', ', $urls );
			} elseif ( is_array( $v ) ) {
				$v = minn_admin_forms_flat( $v );
			}
			$out[ $key ] = trim( (string) $v );
		}
	} catch ( \Throwable $e ) {
		$out = array();
	}
	return $out;
}

/**
 * Conditions for one of the list's two buckets, over custom-form entries.
 *
 * Received is what Forminator itself counts as a form's entries
 * (count_entries, its Forms list, Minn's Manage view): active plus pending,
 * where pending is an entry still waiting on its Stripe checkout. Failed
 * payments, drafts and abandoned entries stay on Forminator's own screen.
 * Spam is the is_spam flag, stored with status spam (or active on rows
 * older than its status column).
 *
 * @param string $bucket 'received' or 'spam'.
 * @return array
 */
function minn_admin_forminator_bucket( $bucket ) {
	return 'spam' === $bucket
		? array( 'entry_type' => 'custom-forms', 'is_spam' => 1, 'status' => array( 'active', 'spam' ) )
		: array( 'entry_type' => 'custom-forms', 'is_spam' => 0, 'status' => array( 'active', 'pending' ) );
}

/** Whether a stored row meets a set of column conditions (value or list of values). */
function minn_admin_forminator_matches( array $row, array $conds ) {
	foreach ( $conds as $col => $values ) {
		if ( ! in_array( (string) ( $row[ $col ] ?? '' ), array_map( 'strval', (array) $values ), true ) ) {
			return false;
		}
	}
	return true;
}

/**
 * One entry row, or null. The table also holds quiz and poll entries, which
 * are not this resource's and answer not found.
 *
 * @param int $id Entry id.
 * @return array|null
 */
function minn_admin_forminator_entry( $id ) {
	$row = Minn_Admin_Kernel_SQL::row( 'frmt_form_entry', 'entry_id', (int) $id, array( 'entry_id', 'form_id', 'entry_type', 'status', 'is_spam', 'date_created' ) );
	return $row && 'custom-forms' === (string) $row['entry_type'] ? $row : null;
}

/** Whether an entry row is still in the table, whatever its type. */
function minn_admin_forminator_exists( $id ) {
	return null !== Minn_Admin_Kernel_SQL::row( 'frmt_form_entry', 'entry_id', (int) $id, array( 'entry_id' ) );
}

/**
 * Server-built model for the surface status card (SureForms parity).
 * Counts mirror the entries list's scope exactly: custom-form entries in
 * the received and spam buckets (minn_admin_forminator_bucket()).
 */
function minn_admin_forminator_status_model() {
	// date_created is date_i18n (site-local), so the chart groups on the
	// site's days. The two buckets are counted apart (their conditions do not
	// join with AND) and their days added together.
	$chart    = array( 'column' => 'date_created', 'clock' => 'local', 'split' => 'is_spam', 'secondary' => array( 1 ) );
	$received = Minn_Admin_Kernel::inbox_counts( array(
		'resource' => 'forminator/entries',
		'received' => minn_admin_forminator_bucket( 'received' ),
		'chart'    => $chart + array( 'where' => minn_admin_forminator_bucket( 'received' ) ),
	) );
	$spammed  = Minn_Admin_Kernel::inbox_counts( array(
		'resource' => 'forminator/entries',
		'spam'     => minn_admin_forminator_bucket( 'spam' ),
		'chart'    => $chart + array( 'where' => minn_admin_forminator_bucket( 'spam' ) ),
	) );
	$days = $received['days'];
	foreach ( $spammed['days'] as $d => $p ) {
		$days[ $d ]['value']     += $p['value'];
		$days[ $d ]['secondary'] += $p['secondary'];
	}
	$spam = $spammed['spam'];
	return minn_admin_forms_card(
		array(
			'label' => __( 'Entries', 'minn-admin' ),
			'value' => number_format_i18n( $received['received'] ),
			'hint'  => $spam ? number_format_i18n( $spam ) . ' spam' : __( 'All received entries', 'minn-admin' ),
		),
		count( minn_admin_forminator_titles() ),
		$days,
		array( 'label' => __( 'Open Forminator ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=forminator-entries' ) ),
		__( 'Spam', 'minn-admin' )
	);
}

/** An entry's list row: its first answers as the summary. */
function minn_admin_forminator_row( array $row ) {
	$form_id = (int) $row['form_id'];
	$titles  = minn_admin_forminator_titles();
	return array(
		'id'         => (int) $row['entry_id'],
		'summary'    => minn_admin_forms_summary( minn_admin_forminator_answers( (int) $row['entry_id'] ), minn_admin_forminator_fields( $form_id ) ),
		'form_title' => isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id,
		// A pending entry reads as pending, which also keeps the spam actions
		// (offered on `received` only) off it.
		'status'     => ! empty( $row['is_spam'] ) ? 'spam' : ( 'pending' === (string) $row['status'] ? 'pending' : 'received' ),
		'date'       => $row['date_created'],
	);
}

/** An entry's detail: every answer, then the submission's own facts. */
function minn_admin_forminator_view( array $row ) {
	$id      = (int) $row['entry_id'];
	$form_id = (int) $row['form_id'];
	$titles  = minn_admin_forminator_titles();
	$rows    = minn_admin_forms_answer_rows( minn_admin_forminator_fields( $form_id ), minn_admin_forminator_answers( $id ), function ( $slug ) {
		return ucfirst( str_replace( '-', ' ', $slug ) );
	} );
	$meta    = minn_admin_forms_meta( isset( $titles[ $form_id ] ) ? $titles[ $form_id ] : '#' . $form_id, $id, date_i18n( 'M j, Y g:i a', strtotime( $row['date_created'] ) ) );
	return minn_admin_forms_entry( $rows, $meta, admin_url( 'admin.php?page=forminator-entries&form_type=forminator_forms&form_id=' . $form_id ) );
}

/**
 * Move an entry between a completed submission and spam. Their entry table
 * owns is_spam + status (the same columns their model writes). The source
 * state rides the UPDATE itself, so a payment webhook moving the entry
 * between the kernel's state check and this write is not overwritten.
 *
 * @param int    $id      Entry id.
 * @param int    $form_id Form id (for their cache).
 * @param array  $from    Conditions the row must still meet.
 * @param array  $to      is_spam and status to write.
 * @param string $refusal The 400 when the row has moved on.
 * @return true|WP_Error
 */
function minn_admin_forminator_move( $id, $form_id, array $from, array $to, $refusal ) {
	// Only a row still in the state it is moved from: the further conditions
	// narrow the key-bound update.
	$changed = Minn_Admin_Kernel_SQL::update( 'frmt_form_entry', 'entry_id', (int) $id, array( 'is_spam' => (int) $to['is_spam'], 'status' => (string) $to['status'] ), $from );
	if ( ! $changed ) {
		return new WP_Error( 'bad_status', $refusal, array( 'status' => 400 ) );
	}
	if ( class_exists( 'Forminator_Form_Entry_Model' ) ) {
		if ( method_exists( 'Forminator_Form_Entry_Model', 'delete_form_entry_cache' ) ) {
			Forminator_Form_Entry_Model::delete_form_entry_cache( (int) $form_id );
		}
		// Their entry model caches the loaded row (status included) per
		// entry id; their own status writes drop it too.
		if ( defined( 'Forminator_Form_Entry_Model::FORM_ENTRY_CACHE_GROUP' ) ) {
			wp_cache_delete( (int) $id, Forminator_Form_Entry_Model::FORM_ENTRY_CACHE_GROUP );
		}
	}
	return true;
}

/**
 * The spam and not-spam actions.
 *
 * Forminator's own screen has no spam toggle; spam is decided at submit,
 * when it stores is_spam = 1 with status spam. Minn's toggle only moves an
 * entry between a completed submission and that pair. Pending and failed
 * are payment states its Stripe lookups match on, and spam keeps no record
 * of the status it replaced, so marking one as spam (and back) would land
 * it on active and orphan its payment: those, drafts, and anything already
 * where the action would put it are refused, never rewritten. The list shows
 * a derived status (received, pending, spam), so the state check reads the
 * stored columns.
 */
function minn_admin_forminator_spam_actions() {
	$out = array();
	foreach ( array(
		'spam'   => array(
			array( 'entry_type' => 'custom-forms', 'is_spam' => 0, 'status' => 'active' ),
			array( 'is_spam' => 1, 'status' => 'spam' ),
			'spam',
			__( 'Marked as spam.', 'minn-admin' ),
			__( 'Only a completed entry can be marked as spam. Pending and failed payment entries stay as they are so Forminator can still match them to their payments.', 'minn-admin' ),
		),
		'unspam' => array(
			minn_admin_forminator_bucket( 'spam' ),
			array( 'is_spam' => 0, 'status' => 'active' ),
			'received',
			__( 'Marked not spam.', 'minn-admin' ),
			__( 'Only a spam entry can be marked not spam.', 'minn-admin' ),
		),
	) as $slug => $cfg ) {
		list( $from, $to, $shown, $done, $refusal ) = $cfg;
		$out[ $slug ] = array(
			'gate'   => 'manage',
			'when'   => function ( $row ) use ( $from ) {
				return minn_admin_forminator_matches( $row, $from );
			},
			'refuse' => $refusal,
			'run'    => function ( $id, $row ) use ( $from, $to, $refusal ) {
				return minn_admin_forminator_move( $id, (int) $row['form_id'], $from, $to, $refusal );
			},
			'expect' => $to,
			'reply'  => function () use ( $shown ) {
				return array( 'status' => $shown );
			},
			'done'   => $done,
		);
	}
	return $out;
}

minn_admin_resource( 'forminator/entries', function () {
	return array(
		'active'  => 'minn_admin_forminator_active',
		'gates'   => array(
			'view'   => 'minn_admin_forminator_can',
			'manage' => 'minn_admin_forminator_can',
		),
		'labels'  => array(
			'not_found' => __( 'Entry not found', 'minn-admin' ),
		),
		// The list reads the table; a single row goes through `get`, which
		// answers only for custom-form entries, and `exists` asks the table
		// whether a deleted row is really gone.
		'source'  => array(
			'table'   => 'frmt_form_entry',
			'key'     => 'entry_id',
			'columns' => array( 'entry_id', 'form_id', 'date_created', 'is_spam', 'status' ),
			'get'     => 'minn_admin_forminator_entry',
			'exists'  => 'minn_admin_forminator_exists',
		),
		'list'    => array(
			// Received vs Spam (no trash in free).
			'filter' => array(
				'param'   => 'status',
				'values'  => array(
					'received' => minn_admin_forminator_bucket( 'received' ),
					'spam'     => minn_admin_forminator_bucket( 'spam' ),
				),
				'default' => 'received',
			),
			'tab'    => array( 'param' => 'form_id', 'column' => 'form_id' ),
			// Same shape as Forminator's own entries search: a LIKE over answer
			// meta, excluding its addon bookkeeping rows.
			'search' => array( 'param' => 'search', 'meta' => array( 'table' => 'frmt_form_entry_meta', 'key' => 'entry_id', 'name' => 'meta_key', 'value' => 'meta_value', 'skip' => 'forminator_addon_' ) ),
			// A status-chart bar narrows the list to that day; date_created is
			// SITE-LOCAL, the same clock the bounds arrive on.
			'range'  => array( 'column' => 'date_created', 'clock' => 'local' ),
			// Newest submission first by the date Forminator stored, so an entry
			// added out of order (an import) lands where it belongs.
			'order'  => array( 'column' => 'date_created', 'dir' => 'DESC' ),
			'row'    => 'minn_admin_forminator_row',
			'fields' => minn_admin_forms_fields( array( 'date' => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'naked' ) ) ),
		),
		'item'    => array( 'view' => 'minn_admin_forminator_view' ),
		'actions' => array_merge( minn_admin_forminator_spam_actions(), array(
			'delete' => minn_admin_forms_delete( function ( $id, $row ) {
				// Their complete cleanup (meta and upload bookkeeping go too).
				// The module type is their own check that the entry belongs to
				// a form (1.58+; earlier versions ignore the extra argument).
				$result = Forminator_API::delete_entry( (int) $row['form_id'], $id, 'custom-forms' );
				if ( is_wp_error( $result ) ) {
					$result->add_data( array( 'status' => 400 ) );
				}
				return $result;
			}, __( 'Forminator reported success but the entry still exists.', 'minn-admin' ) ),
		) ),
	);
} );

minn_admin_endpoint( 'forminator/status', array(
	'active' => 'minn_admin_forminator_active',
	'gate'   => 'minn_admin_forminator_can',
	'run'    => 'minn_admin_forminator_status_model',
) );

minn_admin_endpoint( 'forminator/forms', array(
	'active' => 'minn_admin_forminator_active',
	'gate'   => 'minn_admin_forminator_can',
	'run'    => function ( WP_REST_Request $request ) {
		$manage = ! empty( $request['manage'] );
		$out    = array();
		try {
			foreach ( (array) Forminator_API::get_forms( null, 1, 100 ) as $f ) {
				$row = array(
					'id'    => (int) $f->id,
					'title' => isset( $f->settings['formName'] ) && '' !== $f->settings['formName']
						? (string) $f->settings['formName']
						: (string) $f->name,
				);
				if ( $manage ) {
					$row['entries'] = (int) Forminator_Form_Entry_Model::count_entries( (int) $f->id );
					$row['status']  = 'publish' === (string) $f->status ? 'active' : (string) $f->status;
				}
				$out[] = $row;
			}
		} catch ( \Throwable $e ) {
			$out = array();
		}
		return $out;
	},
) );

minn_admin_add_surface( 'forminator', function () {
	return minn_admin_forminator_active() && minn_admin_forminator_can();
}, function () {
	$ui = minn_admin_forms_ui( 'forminator/entries' );
	return minn_admin_forms_surface( 'forminator/entries', array(
		'sub'     => 'Forminator',
		'plugin'  => array( 'forminator', 'forminator-pro' ),
		'api'     => 'forminator',
		'filter'  => array( 'received' => 'Received', 'spam' => 'Spam' ),
		'actions' => array(
			$ui( 'spam', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'confirm' => __( 'Mark this entry as spam? Find it under the Spam filter.', 'minn-admin' ), 'danger' => true, 'when' => 'received' ) ),
			$ui( 'unspam', array( 'label' => __( 'Not spam', 'minn-admin' ), 'when' => 'spam' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? Forminator has no entry trash — there is no undo.', 'minn-admin' ), 'danger' => true ) ),
		),
		'bulk'    => array(
			$ui( 'spam', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'confirm' => __( 'Mark the selected entries as spam?', 'minn-admin' ), 'danger' => true, 'when' => 'received' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected entries permanently? There is no undo.', 'minn-admin' ), 'danger' => true ) ),
		),
		'manage'  => minn_admin_forms_manage( 'forminator', __( 'Edit in Forminator ↗', 'minn-admin' ), admin_url( 'admin.php?page=forminator-cform-wizard&id={id}' ), array(
			array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
		) ),
	) );
} );
