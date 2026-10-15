<?php
/**
 * Bundled adapter: SureForms (Wave B).
 *
 * SureForms stores every submission in its own {prefix}srfm_entries table
 * (free feature; opt-out is a per-form do_not_store_entries flag). `form_data`
 * is JSON keyed per block with the label encoded in the key
 * (minn_admin_sureforms_answers() reads it back the way SureForms does). Read/unread/trash status, per-form tabs
 * from the sureforms_form CPT, search, delete, and a status card. The entries
 * are a kernel resource (docs/adapter-kernel.md): the kernel owns the routes,
 * gates, paging and errors; json_decode only, never unserialize. `created_at`
 * is written on the site's clock (current_time), converted to UTC via the
 * shared helper.
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

/** Whether SureForms has created its entries table yet (asked every time). */
function minn_admin_sureforms_has_table() {
	return Minn_Admin_Kernel_SQL::exists( 'srfm_entries' );
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
 * A submission's answers as [label => value]. SureForms keys each answer by
 * its block ("srfm-input-<block>-lbl-<encoded label>-<slug>") and reads the
 * label back out of the key (Helper::get_field_label_from_key, as its own
 * entries export does); keys it excludes (form id, honeypot…) are dropped.
 * Older or hand-made rows keyed by a plain label pass through. The label
 * comes from the submitted key, so it is escaped where it is shown.
 */
function minn_admin_sureforms_answers( array $data ) {
	$helper   = '\SRFM\Inc\Helper';
	$excluded = class_exists( $helper ) && method_exists( $helper, 'get_excluded_fields' ) ? (array) $helper::get_excluded_fields() : array();
	$out      = array();
	foreach ( $data as $key => $value ) {
		$key = (string) $key;
		if ( in_array( $key, $excluded, true ) ) {
			continue;
		}
		$label = $key;
		if ( false !== strpos( $key, '-lbl-' ) && class_exists( $helper ) && method_exists( $helper, 'get_field_label_from_key' ) ) {
			$decoded = trim( wp_strip_all_tags( (string) $helper::get_field_label_from_key( $key ) ) );
			$label   = '' !== $decoded ? $decoded : $key;
		}
		// Two fields can share a label; keep both answers.
		$base = $label;
		for ( $n = 2; array_key_exists( $label, $out ); $n++ ) {
			$label = $base . ' (' . $n . ')';
		}
		$out[ $label ] = $value;
	}
	return $out;
}

/**
 * An answer as the visitor typed it. SureForms stores each string answer
 * through htmlspecialchars(), so an ampersand or an apostrophe arrives as
 * &amp; or &#039;; Minn escapes on output, so the entry reads them decoded.
 * (The list's summary keeps them: its title cell reads the value as HTML.)
 */
function minn_admin_sureforms_text( $value ) {
	return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
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
	return $summary ?: __( '(empty entry)', 'minn-admin' );
}

/**
 * One form's Views + Conversion rate from SureForms' own calculation.
 *
 * Their Forms_Data::calculate_form_metrics() is private and reachable only
 * through their forms-list route, which also saves a per-page preference
 * whenever a page size is passed. Reflection runs the same method without
 * that write (a documented exception to never-reimplement: the rate depends
 * on their tracking window and on excluding editors' own test submissions,
 * which must not be re-derived here).
 *
 * @return array { views: int|null, conversion: float|null } nulls = not tracked.
 */
function minn_admin_sureforms_metrics( $form_id ) {
	static $method = false;
	if ( false === $method ) {
		$method = null;
		if ( class_exists( '\\SRFM\\Inc\\Forms_Data' ) && method_exists( '\\SRFM\\Inc\\Forms_Data', 'get_instance' ) ) {
			try {
				$m = new ReflectionMethod( '\\SRFM\\Inc\\Forms_Data', 'calculate_form_metrics' );
				$m->setAccessible( true );
				$method = $m;
			} catch ( \Throwable $e ) {
				$method = null;
			}
		}
	}
	if ( ! class_exists( '\\SRFM\\Inc\\Form_Views' ) || ! \SRFM\Inc\Form_Views::get_instance()->is_tracking_enabled() ) {
		return array( 'views' => null, 'conversion' => null );
	}
	if ( $method ) {
		try {
			$r = $method->invoke( \SRFM\Inc\Forms_Data::get_instance(), (int) $form_id );
			return array(
				'views'      => (int) ( $r['views'] ?? 0 ),
				'conversion' => isset( $r['conversion_rate'] ) ? (float) $r['conversion_rate'] : null,
			);
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the raw count.
		}
	}
	return array( 'views' => (int) \SRFM\Inc\Form_Views::get_instance()->get_views( (int) $form_id ), 'conversion' => null );
}

/**
 * A page of entries. The kernel has read the request (page, status, form
 * tab, search term, day range); this adds what only SureForms needs: its
 * table may not exist yet, and answers are stored through htmlspecialchars()
 * (which left apostrophes alone before PHP 8.1), inside SureForms' own
 * encode_json, so the search term is matched in each stored form.
 *
 * @param array $q The kernel's list query.
 * @return array [ rows, total ]
 */
function minn_admin_sureforms_list( array $q ) {
	if ( ! minn_admin_sureforms_has_table() ) {
		return array( array(), 0 );
	}
	global $wpdb;
	list( $sql, $args ) = Minn_Admin_Kernel::list_where( $q );
	$search = sanitize_text_field( $q['search'] );
	if ( '' !== $search ) {
		$ors = array();
		foreach ( array_unique( array( $search, htmlspecialchars( $search ), htmlspecialchars( $search, ENT_COMPAT | ENT_HTML401 ) ) ) as $term ) {
			$ors[]  = 'e.form_data LIKE %s';
			$args[] = '%' . $wpdb->esc_like( substr( (string) wp_json_encode( $term, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 1, -1 ) ) . '%';
		}
		$sql[] = '( ' . implode( ' OR ', $ors ) . ' )';
	}
	// created_at is site-local, like the status-chart bounds.
	list( $range_sql, $range_args ) = minn_admin_chart_range_clause( $q['request'], 'e.created_at', 'local' );
	// Newest first by when it was sent, so a row added out of order (an
	// import, a restored backup) lands where it belongs.
	list( $rows, $total ) = Minn_Admin_Kernel_SQL::page( array(
		'table'   => 'srfm_entries',
		'key'     => 'ID',
		'columns' => array( 'ID', 'form_id', 'form_data', 'status', 'created_at' ),
		'sql'     => array_merge( $sql, $range_sql ),
		'args'    => array_merge( $args, $range_args ),
		'order'   => 'created_at',
		'dir'     => 'DESC',
		'limit'   => $q['per_page'],
		'offset'  => ( $q['page'] - 1 ) * $q['per_page'],
	) );
	$titles = minn_admin_sureforms_form_titles();
	foreach ( $rows as $i => $r ) {
		$fid                     = (int) $r['form_id'];
		$rows[ $i ]['form_title'] = isset( $titles[ $fid ] ) ? $titles[ $fid ] : ( 'Form #' . $fid );
	}
	return array( $rows, $total );
}

/** An entry's list row. */
function minn_admin_sureforms_row( array $r ) {
	$data = json_decode( (string) $r['form_data'], true );
	$data = is_array( $data ) ? $data : array();
	return array(
		'id'         => (int) $r['ID'],
		'summary'    => minn_admin_sureforms_summary( minn_admin_sureforms_answers( $data ) ),
		'form_title' => (string) $r['form_title'],
		'status'     => (string) $r['status'],
		'date'       => minn_admin_db_local_to_utc_iso( $r['created_at'] ),
	);
}

/** An entry's detail: answers, the submission's facts, its activity log. */
function minn_admin_sureforms_view( array $raw ) {
	$row     = (object) $raw;
	$data    = json_decode( (string) $row->form_data, true );
	$data    = is_array( $data ) ? minn_admin_sureforms_answers( $data ) : array();
	$answers = array();
	foreach ( $data as $label => $value ) {
		$value     = is_array( $value )
			? implode( ', ', array_filter( array_map( 'strval', $value ) ) )
			: minn_admin_sureforms_text( $value );
		$answers[] = array(
			'label' => (string) $label,
			'value' => '' !== (string) $value ? (string) $value : '—',
		);
	}
	$titles = minn_admin_sureforms_form_titles();
	$meta   = array(
		array( 'label' => __( 'Form', 'minn-admin' ), 'value' => isset( $titles[ (int) $row->form_id ] ) ? $titles[ (int) $row->form_id ] : ( '#' . (int) $row->form_id ) ),
		array( 'label' => __( 'Entry', 'minn-admin' ), 'value' => '#' . (int) $row->ID ),
	);
	// The entry header and the Submission panel print this as it comes, so
	// it is a date on the site's clock, as on the other form providers, not
	// an ISO string.
	$iso = minn_admin_db_local_to_utc_iso( $row->created_at );
	if ( '' !== $iso ) {
		$meta[] = array( 'label' => __( 'Submitted', 'minn-admin' ), 'value' => wp_date( 'M j, Y g:i a', strtotime( $iso ) ) );
	}
	// Their entry activity log (2.12.8+ also records the notification
	// emails): newest first, { title, messages[], timestamp } with site-local
	// timestamps.
	$activity = array();
	$logs     = json_decode( (string) ( $row->logs ?? '' ), true );
	foreach ( is_array( $logs ) ? array_slice( $logs, 0, 20 ) : array() as $log ) {
		if ( ! is_array( $log ) || empty( $log['title'] ) ) {
			continue;
		}
		// Site-local already, so the site's own date format applies as-is.
		$when       = ! empty( $log['timestamp'] ) ? (string) mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $log['timestamp'] ) : '';
		$messages   = array_filter( array_map( 'wp_strip_all_tags', array_map( 'strval', (array) ( $log['messages'] ?? array() ) ) ) );
		$activity[] = array(
			'label' => (string) $log['title'] . ( '' !== $when ? ' · ' . $when : '' ),
			'value' => $messages ? implode( "\n", $messages ) : '—',
		);
	}
	$sections = array(
		array( 'title' => __( 'Answers', 'minn-admin' ), 'rows' => $answers ),
		array( 'title' => __( 'Submission', 'minn-admin' ), 'rows' => $meta ),
	);
	if ( $activity ) {
		$sections[] = array( 'title' => __( 'Activity', 'minn-admin' ), 'rows' => $activity );
	}
	// The status rides the item, not the panel: the page's pill and its
	// action gates read it there, also when opened by a link.
	return array(
		'kind'     => 'entry',
		'item'     => array(
			'id'     => (int) $row->ID,
			'status' => (string) $row->status,
		),
		'sections' => $sections,
		'adminUrl' => admin_url( 'admin.php?page=sureforms_entries&entry_id=' . (int) $row->ID ),
	);
}

// SureForms gates reads and writes alike on its one admin capability. Status
// changes and deletes take an entry in any state.
minn_admin_resource( 'sureforms/entries', function () {
	return array(
		'active'  => 'minn_admin_sureforms_active',
		'gates'   => array(
			'view'   => 'minn_admin_sureforms_can',
			'manage' => 'minn_admin_sureforms_can',
		),
		'labels'  => array(
			'not_found' => __( 'Entry not found', 'minn-admin' ),
		),
		'source'  => array(
			'table'  => 'srfm_entries',
			'key'    => 'ID',
			'detail' => array(),
			'list'   => 'minn_admin_sureforms_list',
		),
		'list'    => array(
			// The default view hides trash (their inbox behaviour).
			'filter' => array(
				'param'   => 'status',
				'values'  => array(
					'all'    => array( 'status' => array( 'not' => array( 'trash' ) ) ),
					'unread' => array( 'status' => 'unread' ),
					'read'   => array( 'status' => 'read' ),
					'trash'  => array( 'status' => 'trash' ),
				),
				'default' => 'all',
			),
			'tab'    => array( 'param' => 'form_id', 'column' => 'form_id' ),
			// The term is matched by the source above, in each stored encoding.
			'search' => array( 'param' => 'search' ),
			'range'  => array( 'column' => 'created_at', 'clock' => 'local' ),
			'row'    => 'minn_admin_sureforms_row',
			'fields' => array(
				'id'         => 'int',
				'summary'    => 'text',
				'form_title' => 'text',
				'status'     => 'text',
				// UTC with a Z, from the site-local column (built in the row).
				'date'       => 'text',
			),
		),
		'item'    => array( 'view' => 'minn_admin_sureforms_view' ),
		'actions' => array(
			'status' => array(
				'gate'   => 'manage',
				'run'    => function ( $id, $row, WP_REST_Request $request ) {
					$status = sanitize_key( (string) $request->get_param( 'status' ) );
					if ( ! in_array( $status, array( 'read', 'unread', 'trash' ), true ) ) {
						return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
					}
					// Read back by the expectation below: an update that wrote
					// nothing is not done.
					Minn_Admin_Kernel_SQL::update( 'srfm_entries', 'ID', (int) $id, array( 'status' => $status ) );
					return $status;
				},
				'expect' => function ( $id, $fresh, $status ) {
					return is_array( $fresh ) && (string) $status === (string) $fresh['status'];
				},
				'fail_code' => 'update_failed',
				'fail'      => __( 'Could not update the entry.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh, $status ) {
					return array( 'status' => $status );
				},
			),
			// Through their model, as their own delete does: it fires
			// srfm_before_delete_entry, which add-ons use to remove what they
			// keep for an entry (uploaded files among them).
			'delete' => array(
				'method'    => 'DELETE',
				'gate'      => 'manage',
				'run'       => function ( $id ) {
					if ( class_exists( '\SRFM\Inc\Database\Tables\Entries' ) && method_exists( '\SRFM\Inc\Database\Tables\Entries', 'delete' ) ) {
						$deleted = \SRFM\Inc\Database\Tables\Entries::delete( $id );
					} else {
						do_action( 'srfm_before_delete_entry', $id );
						$deleted = Minn_Admin_Kernel_SQL::delete( 'srfm_entries', 'ID', (int) $id );
					}
					return $deleted ? true : new WP_Error( 'not_found', __( 'Entry not found', 'minn-admin' ), array( 'status' => 404 ) );
				},
				'expect'    => 'gone',
				'fail_code' => 'delete_failed',
				'fail'      => __( 'SureForms reported success but the entry still exists.', 'minn-admin' ),
				'reply'     => function () {
					return array( 'deleted' => true );
				},
				'done'      => __( 'Entry deleted.', 'minn-admin' ),
			),
		),
	);
} );

// Forms list for the tab strip, and the Forms view.
minn_admin_endpoint( 'sureforms/forms', array(
	'active' => 'minn_admin_sureforms_active',
	'gate'   => 'minn_admin_sureforms_can',
	'run'    => function ( WP_REST_Request $request ) {
		$out    = array();
		$manage = ! empty( $request['manage'] );
		foreach ( minn_admin_sureforms_form_titles() as $id => $title ) {
			if ( ! $manage ) {
				$out[] = array( 'id' => $id, 'title' => $title );
				continue;
			}
			$m       = minn_admin_sureforms_metrics( $id );
			$entries = class_exists( '\\SRFM\\Inc\\Database\\Tables\\Entries' )
				? (int) \SRFM\Inc\Database\Tables\Entries::get_total_entries_by_status( 'all', (int) $id )
				: 0;
			$out[]   = array(
				'id'         => $id,
				'title'      => $title,
				'status'     => (string) get_post_status( $id ),
				'entries'    => $entries,
				'views'      => null === $m['views'] ? '—' : number_format_i18n( $m['views'] ),
				'conversion' => null === $m['conversion'] ? '—' : number_format_i18n( $m['conversion'], 1 ) . '%',
			);
		}
		return $out;
	},
) );

minn_admin_endpoint( 'sureforms/status', array(
	'active' => 'minn_admin_sureforms_active',
	'gate'   => 'minn_admin_sureforms_can',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open SureForms ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=sureforms_entries' ) ) );
		if ( ! minn_admin_sureforms_has_table() ) {
			return array(
				'rows'    => array( array( 'label' => __( 'Entries', 'minn-admin' ), 'value' => '—', 'hint' => __( 'No submissions yet', 'minn-admin' ) ) ),
				'actions' => $open,
			);
		}
		// created_at is site-local, so the chart groups on the site's day.
		$kept = array( 'status' => array( 'not' => array( 'trash' ) ) );
		$n    = Minn_Admin_Kernel::inbox_counts( array(
			'table'    => 'srfm_entries',
			'received' => $kept,
			'unread'   => array( 'status' => 'unread' ),
			'chart'    => array( 'column' => 'created_at', 'clock' => 'local', 'where' => $kept, 'split' => 'status' ),
		) );
		return array(
			'rows'    => array(
				array(
					'label' => __( 'Unread entries', 'minn-admin' ),
					'value' => number_format_i18n( $n['unread'] ),
					'hint'  => number_format_i18n( $n['received'] ) . ' total',
				),
				array( 'label' => __( 'Forms', 'minn-admin' ), 'value' => number_format_i18n( count( minn_admin_sureforms_form_titles() ) ) ),
			),
			'chart'   => minn_admin_chart_build( $n['days'], __( 'Entries', 'minn-admin' ) ),
			'actions' => $open,
		);
	},
) );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	// Every sibling forms adapter gates registration on its own helper; without
	// it the nav entry is advertised to every Contributor and dead-ends on 403.
	if ( ! minn_admin_sureforms_active() || ! minn_admin_sureforms_can() ) {
		return $surfaces;
	}
	$ui = function ( $slug, $ui ) {
		return Minn_Admin_Kernel::ui( 'sureforms/entries', $slug, $ui );
	};
	$surfaces['sureforms'] = array(
		'label'      => __( 'Forms', 'minn-admin' ),
		'family'     => 'forms',
		'group'      => 'workspace',
		'sub'        => 'SureForms',
		'plugin'     => 'sureforms',
		'icon'       => 'inbox',
		'cap'        => 'read', // real gate is minn_admin_sureforms_can().
		'status'     => array( 'route' => 'minn-admin/v1/sureforms/status' ),
		// Route, paging, search, the status-chart day range and the filter
		// query come from the resource, so they cannot drift from it.
		'collection' => Minn_Admin_Kernel::collection( 'sureforms/entries', array(
			'viewLabel' => __( 'Entries', 'minn-admin' ),
			'tabs'      => array(
				'route'    => 'minn-admin/v1/sureforms/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'form_id',
				'allLabel' => __( 'All entries', 'minn-admin' ),
			),
			'filter'    => array(
				'label'   => __( 'Status', 'minn-admin' ),
				// The first option is where the list opens: everything
				// received, as on the other form providers.
				'options' => array(
					array( 'all', 'Received' ),
					array( 'unread', 'Unread' ),
					array( 'read', 'Read' ),
					array( 'trash', 'Trash' ),
				),
			),
			'columns'   => array(
				array( 'key' => 'summary', 'label' => __( 'Entry', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'form_title', 'label' => __( 'Form', 'minn-admin' ) ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/sureforms/entries/{id}',
			),
			'actions'   => array(
				$ui( 'status', array( 'label' => __( 'Mark read', 'minn-admin' ), 'body' => array( 'status' => 'read' ), 'when' => 'unread' ) ),
				$ui( 'status', array( 'label' => __( 'Mark unread', 'minn-admin' ), 'body' => array( 'status' => 'unread' ), 'when' => 'read' ) ),
				$ui( 'status', array( 'label' => __( 'Trash', 'minn-admin' ), 'body' => array( 'status' => 'trash' ), 'when' => 'read' ) ),
				$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'when' => 'trash' ) ),
			),
			'bulk'      => array(
				$ui( 'status', array( 'label' => __( 'Mark read', 'minn-admin' ), 'body' => array( 'status' => 'read' ) ) ),
				$ui( 'status', array( 'label' => __( 'Trash', 'minn-admin' ), 'body' => array( 'status' => 'trash' ), 'danger' => true ) ),
			),
		) ),
		// Forms with SureForms' own Views and Conversion rate (2.12.6+). Both
		// read "—" until their Form views tracking setting is on, exactly as
		// their Forms list hides the columns.
		'manage'     => array(
			'viewLabel' => __( 'Forms', 'minn-admin' ),
			'route'     => 'minn-admin/v1/sureforms/forms?manage=1',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Form', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'entries', 'label' => __( 'Entries', 'minn-admin' ), 'format' => 'num', 'width' => '84px' ),
				array( 'key' => 'views', 'label' => __( 'Views', 'minn-admin' ), 'width' => '84px' ),
				array( 'key' => 'conversion', 'label' => __( 'Conversion', 'minn-admin' ), 'width' => '104px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '96px' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => __( 'Edit in SureForms ↗', 'minn-admin' ),
					'href'  => admin_url( 'post.php?post={id}&action=edit' ),
				),
			),
		),
	);
	return $surfaces;
} );
