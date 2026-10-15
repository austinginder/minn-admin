<?php
/**
 * The forms family's kit: what every form-entries adapter would otherwise
 * build again. An entry list's surface (the form-picker tabs, the Status
 * filter, the Entry, Form, Status and When columns, the detail route) and
 * its Forms view, an entry's answers and detail, the status card, the row
 * fields and the delete declaration.
 *
 * Adapter tier: presentation and vendor-neutral formatting only. Routes,
 * gates and queries stay the kernel's; each adapter passes its vendor's
 * values (where entries live, who may read them, how a row reads, which
 * actions its vendor offers in which state).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * An entry list's surface over its kernel resource.
 *
 * @param string $res Resource id ("formidable/entries").
 * @param array  $o   sub, plugin and api, all three required (api is where
 *                    status and forms answer under minn-admin/v1:
 *                    "formidable"), forms (the tab list's route
 *                    when it is not <api>/forms), tab (its param, form_id by
 *                    default), messages (the list holds messages, not
 *                    entries), filter (value => label), lead (the columns
 *                    before Form, the Entry summary by default), form (the
 *                    Form column's key), more (columns before Status), pill
 *                    (the Status column's key, false for none), width (its
 *                    width), after (columns after it), utc (When is UTC),
 *                    actions, bulk, manage (minn_admin_forms_manage()) and
 *                    collection (keys merged over the built ones).
 * @return array
 */
function minn_admin_forms_surface( $res, array $o ) {
	$o += array(
		'forms'      => $o['api'] . '/forms',
		'tab'        => 'form_id',
		'messages'   => false,
		'lead'       => array( array( 'key' => 'summary', 'label' => __( 'Entry', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ) ),
		'form'       => 'form_title',
		'more'       => array(),
		'pill'       => 'status',
		'width'      => '96px',
		'after'      => array(),
		'utc'        => false,
		'actions'    => array(),
		'bulk'       => array(),
		'collection' => array(),
	);
	$pill  = $o['pill'] ? array( array( 'key' => $o['pill'], 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => $o['width'] ) ) : array();
	$when  = array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ) + ( $o['utc'] ? array( 'utc' => true ) : array() );
	$extra = array(
		'viewLabel' => $o['messages'] ? __( 'Messages', 'minn-admin' ) : __( 'Entries', 'minn-admin' ),
		'tabs'      => array(
			'route'    => 'minn-admin/v1/' . $o['forms'],
			'valueKey' => 'id',
			'labelKey' => 'title',
			'param'    => $o['tab'],
			'allLabel' => $o['messages'] ? __( 'All messages', 'minn-admin' ) : __( 'All entries', 'minn-admin' ),
		),
		'columns'   => array_merge( $o['lead'], array( array( 'key' => $o['form'], 'label' => __( 'Form', 'minn-admin' ) ) ), $o['more'], $pill, $o['after'], array( $when ) ),
		'detail'    => array( 'sectionsRoute' => 'minn-admin/v1/' . $res . '/{id}' ),
		'actions'   => $o['actions'],
		'bulk'      => $o['bulk'],
	);
	// The first option is where the list opens.
	if ( isset( $o['filter'] ) ) {
		$options = array();
		foreach ( $o['filter'] as $value => $label ) {
			$options[] = array( (string) $value, $label );
		}
		$extra['filter'] = array( 'label' => __( 'Status', 'minn-admin' ), 'options' => $options );
	}
	$surface = array(
		'label'      => __( 'Forms', 'minn-admin' ),
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => $o['sub'],
		'plugin'     => $o['plugin'],
		'icon'       => 'inbox',
		'cap'        => 'read', // the real gate is the adapter's active check, in its vendor's own capability model
		'status'     => array( 'route' => 'minn-admin/v1/' . $o['api'] . '/status' ),
		// Route, paging, search, the status-chart day range and the filter
		// query come from the resource, so they cannot drift from it.
		'collection' => Minn_Admin_Kernel::collection( $res, array_merge( $extra, $o['collection'] ) ),
	);
	if ( isset( $o['manage'] ) ) {
		$surface['manage'] = $o['manage'];
	}
	return $surface;
}

/** A surface's row and bulk actions over its resource's declared ones: ui( $slug, $keys ). */
function minn_admin_forms_ui( $res ) {
	return function ( $slug, array $ui ) use ( $res ) {
		return Minn_Admin_Kernel::ui( $res, $slug, $ui );
	};
}

/**
 * The Forms view: each form, its entry count, the vendor's builder for it.
 *
 * @param string $api     Where the forms answer under minn-admin/v1 ("formidable").
 * @param string $edit    The builder link's label ("Edit in Formidable ↗").
 * @param string $href    Its address, {id} for the form.
 * @param array  $columns Columns after Entries.
 * @param array  $more    Keys merged over the view (open, itemsKey, totalKey),
 *                        and entries: keys merged over the Entries column.
 * @return array
 */
function minn_admin_forms_manage( $api, $edit, $href, array $columns = array(), array $more = array() ) {
	$entries = array( 'key' => 'entries', 'label' => __( 'Entries', 'minn-admin' ), 'format' => 'num' ) + (array) ( $more['entries'] ?? array() );
	unset( $more['entries'] );
	return array_merge( array(
		'viewLabel' => __( 'Forms', 'minn-admin' ),
		'route'     => 'minn-admin/v1/' . $api . '/forms?manage=1',
		'columns'   => array_merge( array( array( 'key' => 'title', 'label' => __( 'Form', 'minn-admin' ), 'format' => 'title' ), $entries ), $columns ),
		'detail'    => array(),
		'actions'   => array( array( 'label' => $edit, 'href' => $href ) ),
	), $more );
}

/** A list row's fields every entry list sends, then the vendor's own (its date among them). */
function minn_admin_forms_fields( array $more ) {
	return array_merge( array( 'id' => 'int', 'summary' => 'text', 'form_title' => 'text', 'status' => 'text' ), $more );
}

/**
 * An answer stored as a structure, as text: its leaves that say something,
 * joined. An absolute path on this server is an upload's location, not its
 * answer, and printing it leaks the directory layout.
 *
 * @param array  $value Stored answer.
 * @param string $glue  Between leaves.
 * @return string
 */
function minn_admin_forms_flat( array $value, $glue = ', ' ) {
	$flat = array();
	array_walk_recursive( $value, function ( $leaf ) use ( &$flat ) {
		if ( '' !== trim( (string) $leaf ) && ! minn_admin_is_server_path( $leaf ) ) {
			$flat[] = (string) $leaf;
		}
	} );
	return implode( $glue, $flat );
}

/**
 * A row's summary: its first three answers that are not empty, in the
 * form's field order (or as stored, with no fields), else a placeholder.
 *
 * @param array      $answers Answers (strings) by field key.
 * @param array|null $fields  The form's fields by the same key, or null.
 * @return string
 */
function minn_admin_forms_summary( array $answers, $fields = null ) {
	$parts = array();
	foreach ( array_keys( null === $fields ? $answers : $fields ) as $key ) {
		if ( isset( $answers[ $key ] ) && '' !== $answers[ $key ] ) {
			$parts[] = $answers[ $key ];
		}
		if ( count( $parts ) >= 3 ) {
			break;
		}
	}
	return $parts ? implode( ' · ', $parts ) : __( '(empty entry)', 'minn-admin' );
}

/**
 * The Answers rows: every field of the form with its answer or a dash, then
 * the answers whose field no longer exists on the form.
 *
 * @param array         $fields  Field key => label, in form order.
 * @param array         $answers Field key => answer.
 * @param callable|null $orphan  A gone field's label from its key ("Field 7" when null).
 * @return array[]
 */
function minn_admin_forms_answer_rows( array $fields, array $answers, $orphan = null ) {
	$rows = array();
	foreach ( $fields as $key => $label ) {
		$rows[] = array( 'label' => $label, 'value' => isset( $answers[ $key ] ) && '' !== $answers[ $key ] ? $answers[ $key ] : '—' );
	}
	foreach ( $answers as $key => $value ) {
		if ( ! isset( $fields[ $key ] ) && '' !== $value ) {
			$rows[] = array( 'label' => $orphan ? call_user_func( $orphan, $key ) : __( 'Field ', 'minn-admin' ) . $key, 'value' => $value );
		}
	}
	return $rows;
}

/** An answer row typed by its value: an email address (or a field named so), a link, or text. */
function minn_admin_forms_typed( $label, $value, $key = '' ) {
	$value = (string) $value;
	return array(
		'label' => $label,
		'value' => $value,
		'type'  => ( false !== strpos( (string) $key, 'email' ) || is_email( $value ) ) ? 'email' : ( 0 === strpos( $value, 'http' ) ? 'url' : 'text' ),
	);
}

/**
 * The Submission section's opening rows: the form, the entry's number and,
 * unless null, when it came.
 *
 * @param string      $form      The form's title.
 * @param int         $entry     The entry's number.
 * @param string|null $submitted When it came, as the entry page prints it, or null for none.
 * @return array[]
 */
function minn_admin_forms_meta( $form, $entry, $submitted = null ) {
	$rows = array(
		array( 'label' => __( 'Form', 'minn-admin' ), 'value' => $form ),
		array( 'label' => __( 'Entry', 'minn-admin' ), 'value' => '#' . $entry ),
	);
	if ( null !== $submitted ) {
		$rows[] = array( 'label' => __( 'Submitted', 'minn-admin' ), 'value' => $submitted );
	}
	return $rows;
}

/** The same section led by the date: when it came, then the form unless null. */
function minn_admin_forms_meta_dated( $submitted, $form = null ) {
	$rows = array( array( 'label' => __( 'Submitted', 'minn-admin' ), 'value' => $submitted ) );
	if ( null !== $form ) {
		$rows[] = array( 'label' => __( 'Form', 'minn-admin' ), 'value' => $form );
	}
	return $rows;
}

/**
 * An entry's detail: its answers, the submission's own facts, any further
 * sections, and the vendor's screen for it.
 *
 * @param array  $answers Answer rows.
 * @param array  $meta    The Submission section's rows.
 * @param string $admin   The vendor's screen for this entry.
 * @param array  $o       responses (the answers read as Responses), more
 *                        (sections after Submission), and the detail's own
 *                        keys (title, status, item, edit).
 * @return array
 */
function minn_admin_forms_entry( array $answers, array $meta, $admin, array $o = array() ) {
	$o       += array( 'responses' => false, 'more' => array() );
	$sections = array_merge(
		array(
			array( 'title' => $o['responses'] ? __( 'Responses', 'minn-admin' ) : __( 'Answers', 'minn-admin' ), 'rows' => $answers ),
			array( 'title' => __( 'Submission', 'minn-admin' ), 'rows' => $meta ),
		),
		$o['more']
	);
	$head = array_diff_key( $o, array( 'responses' => true, 'more' => true, 'edit' => true ) );
	return array_merge( array( 'kind' => 'entry' ), $head, array( 'sections' => $sections, 'adminUrl' => $admin ), array_intersect_key( $o, array( 'edit' => true ) ) );
}

/**
 * A forms status card: its headline row, the forms counted, the last 14
 * days and the vendor's own screen.
 *
 * @param array  $first     The headline row.
 * @param int    $forms     How many forms.
 * @param array  $days      The chart's days.
 * @param array  $open      The vendor screen's link (label, href).
 * @param string $secondary The chart's second series ('' for none).
 * @param string $primary   Its first ('' for Entries).
 * @return array
 */
function minn_admin_forms_card( array $first, $forms, array $days, array $open, $secondary = '', $primary = '' ) {
	return array(
		'rows'    => array( $first, array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => number_format_i18n( $forms ) ) ),
		'chart'   => minn_admin_chart_build( $days, '' !== $primary ? $primary : __( 'Entries', 'minn-admin' ), $secondary ),
		'actions' => array( $open ),
	);
}

/**
 * The Unread entries headline: how many wait, with the total and how many
 * of another bucket (spam, trash) as its hint.
 *
 * @param int    $unread Unread entries.
 * @param int    $total  Every entry received.
 * @param int    $more   How many sit in the bucket.
 * @param string $bucket Its name.
 * @return array
 */
function minn_admin_forms_unread( $unread, $total, $more = 0, $bucket = 'spam' ) {
	$hint = number_format_i18n( $total ) . ' total';
	if ( $more ) {
		$hint .= ', ' . number_format_i18n( $more ) . ' ' . $bucket;
	}
	return array( 'label' => __( 'Unread entries', 'minn-admin' ), 'value' => number_format_i18n( $unread ), 'hint' => $hint );
}

/** The card for a caller whose scope holds no form: nothing to count, nothing to chart. */
function minn_admin_forms_none( array $actions, $hint = '' ) {
	$first = array( 'label' => __( 'Unread entries', 'minn-admin' ), 'value' => '0' );
	if ( '' !== $hint ) {
		$first['hint'] = $hint;
	}
	return array( 'rows' => array( $first, array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => '0' ) ), 'actions' => $actions );
}

/**
 * The delete an entry list declares: permanent, through the vendor's own
 * delete, and done only once the row is gone.
 *
 * @param callable $run  Deletes one entry.
 * @param string   $fail The 500 when the entry is still there.
 * @param array    $o    Keys merged over it (gate, when, code, refuse, reply, done).
 * @return array
 */
function minn_admin_forms_delete( $run, $fail, array $o = array() ) {
	return array_merge( array(
		'method'    => 'DELETE',
		'gate'      => 'manage',
		'run'       => $run,
		'expect'    => 'gone',
		'fail_code' => 'delete_failed',
		'fail'      => $fail,
		'done'      => __( 'Entry deleted permanently.', 'minn-admin' ),
	), $o );
}

/** A delete's reply, naming the entry it removed. */
function minn_admin_forms_deleted( $id ) {
	return array( 'id' => (int) $id, 'deleted' => true );
}
