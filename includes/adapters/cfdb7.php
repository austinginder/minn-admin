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
 * The entries are a kernel resource (docs/adapter-kernel.md): the kernel
 * owns the routes, the gate, ids, paging, the not-found answer and the
 * post-condition of each action. The read flag and the answers live inside
 * the serialized blob, which no column condition reaches, so the list source
 * adds the blob's own LIKE clauses to the kernel's page query.
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
 * @param string $blob         Raw form_value column.
 * @param bool   $strings_only Keep only values stored as strings (lists and
 *                             scalars dropped, not flattened): the delete path,
 *                             where a list is never an upload's filename.
 * @return array<string,string>
 */
function minn_admin_cfdb7_values( $blob, $strings_only = false ) {
	$out  = array();
	$blob = (string) $blob;

	// A CF7 submission is unauthenticated input, and CFDB7 stores every POST
	// key it receives. A decimal-numeric field name becomes a PHP INTEGER key,
	// which serializes as i:N; — a shape the byte-length scanner below cannot
	// read, so it stopped at that point and silently dropped every remaining
	// answer, the upload filename among them. Delete then found no file to
	// remove and reported the entry permanently deleted anyway.
	//
	// The shared decoder instantiates nothing and refuses object payloads,
	// so this is not the object-injection risk the scanner was written to
	// avoid; it is what CFDB7 itself uses on every one of its own read paths,
	// and what minn_admin_cfdb7_set_status() below already relies on.
	if ( is_serialized( $blob ) ) {
		$data = Minn_Admin::decode_serialized( $blob );
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				if ( $strings_only && ! is_string( $v ) ) {
					continue;
				}
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
		$n = (int) substr( $blob, $pos + 2, $colon - $pos - 2 );
		// A negative (length-desynced) count would drive $pos backwards and
		// spin the caller's loop forever on attacker-supplied submission
		// content; a real PHP string length is never negative.
		if ( $n < 0 ) {
			return null;
		}
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
		// Forward-progress backstop: every branch below must advance $pos.
		// A malformed token that leaves it where it started (or moves it
		// back) would spin forever on attacker-supplied content, so bail
		// the moment an iteration fails to move past its own start.
		$guard = $pos;
		$key   = $read_string();
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
				$inner_guard = $pos;
				$c           = $blob[ $pos ];
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
				if ( $pos <= $inner_guard ) {
					$pos = $len; // malformed member — stop scanning this blob
					break;
				}
			}
			if ( ! $strings_only ) {
				$out[ $key ] = implode( ', ', array_filter( $member, 'strlen' ) );
			}
		} elseif ( 'N' === $type ) {
			if ( ! $strings_only ) {
				$out[ $key ] = '';
			}
			$pos += 2;
		} elseif ( '' === $type ) {
			break;
		} else { // i / d / b value
			$scalar = $skip_scalar();
			if ( ! $strings_only ) {
				$out[ $key ] = $scalar;
			}
		}
		if ( $pos <= $guard ) {
			break; // no forward progress this iteration — malformed blob
		}
	}
	return $out;
}

/** A CF7 form's title by post id. */
function minn_admin_cfdb7_form_title( $id ) {
	$post = get_post( (int) $id );
	return $post && $post->post_title ? $post->post_title : ( 'Form #' . (int) $id );
}

/** CF7 form titles keyed by post id, for every form that has entries (the tabs). */
function minn_admin_cfdb7_form_titles() {
	$ids = array_map( 'intval', Minn_Admin_Kernel_SQL::distinct( 'db7_forms', 'form_post_id', array() ) );
	$out = array();
	foreach ( $ids as $id ) {
		$out[ $id ] = minn_admin_cfdb7_form_title( $id );
	}
	asort( $out );
	return $out;
}

/**
 * An answer as the visitor typed it. CFDB7 saves quotes, apostrophes and
 * slashes as HTML entities (&quot; &#039; &#047; &#092;), and its own screen
 * shows them decoded; Minn escapes on output, so it reads them decoded too.
 */
function minn_admin_cfdb7_text( $value ) {
	return html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
}

/** First few real answers as the list-row summary (fluent-forms style). */
function minn_admin_cfdb7_summary( $values ) {
	$parts = array();
	foreach ( $values as $key => $v ) {
		if ( 'cfdb7_status' === $key || '' === trim( (string) $v ) || false !== strpos( $key, 'cfdb7_file' ) ) {
			continue;
		}
		$parts[] = trim( minn_admin_cfdb7_text( $v ) );
		if ( count( $parts ) >= 3 ) {
			break;
		}
	}
	return $parts ? implode( ' · ', $parts ) : __( '(empty entry)', 'minn-admin' );
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
	$data = Minn_Admin::decode_serialized( $blob );
	if ( ! is_array( $data ) ) {
		return null;
	}
	if ( isset( $data['cfdb7_status'] ) && (string) $data['cfdb7_status'] === $status ) {
		return null;
	}
	$data['cfdb7_status'] = $status;
	return serialize( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
}


/** Read or unread, by CFDB7's rule: only an explicit "read" flag is read. */
function minn_admin_cfdb7_state( array $values ) {
	return ( $values['cfdb7_status'] ?? '' ) === 'read' ? 'read' : 'unread';
}

/**
 * A page of entries: the kernel's paged query plus the clauses only the blob
 * can answer (the read flag and the answers are inside form_value).
 *
 * @param array    $q       The kernel's list query.
 * @param string[] $columns Columns to read.
 * @return array [ rows, total ]
 */
function minn_admin_cfdb7_list( array $q, array $columns ) {
	global $wpdb;
	list( $sql, $args ) = Minn_Admin_Kernel::list_where( $q );
	// Read/unread is a fixed token inside the serialized blob.
	$read = '%' . $wpdb->esc_like( 's:12:"cfdb7_status";s:4:"read"' ) . '%';
	if ( 'read' === $q['filter'] ) {
		$sql[]  = 'e.form_value LIKE %s';
		$args[] = $read;
	} elseif ( 'unread' === $q['filter'] ) {
		// Absent or "unread" both count as unread for their screen.
		$sql[]  = '(e.form_value LIKE %s OR e.form_value NOT LIKE %s)';
		$args[] = '%' . $wpdb->esc_like( 's:12:"cfdb7_status";s:6:"unread"' ) . '%';
		$args[] = $read;
	}
	if ( '' !== $q['search'] ) {
		// Their own list screen searches the raw blob with LIKE. The blob holds
		// quotes, apostrophes and slashes as the entities cfdb7_before_send_mail
		// swapped in, so the term is matched that way too, swapped exactly as it
		// swaps them.
		$stored = str_replace(
			array( '\"', "\'", '/', '\\', '"', "'" ),
			array( '&quot;', '&#039;', '&#047;', '&#092;', '&quot;', '&#039;' ),
			$q['search']
		);
		$sql[]  = '(e.form_value LIKE %s OR e.form_value LIKE %s)';
		$args[] = '%' . $wpdb->esc_like( $q['search'] ) . '%';
		$args[] = '%' . $wpdb->esc_like( $stored ) . '%';
	}
	// The declared range: form_date is current_time, i.e. SITE-LOCAL, the same
	// clock the chart's bounds arrive on.
	list( $range_sql, $range_args ) = minn_admin_chart_range_clause( $q['request'], 'e.form_date', 'local' );
	return Minn_Admin_Kernel_SQL::page( array(
		'table'   => 'db7_forms',
		'key'     => 'form_id',
		'columns' => $columns,
		'sql'     => array_merge( $sql, $range_sql ),
		'args'    => array_merge( $args, $range_args ),
		'order'   => 'form_id',
		'dir'     => 'DESC',
		'limit'   => $q['per_page'],
		'offset'  => ( $q['page'] - 1 ) * $q['per_page'],
	) );
}

/** An entry's list row. */
function minn_admin_cfdb7_row( array $row ) {
	$values = minn_admin_cfdb7_values( (string) $row['form_value'] );
	return array(
		'id'           => (int) $row['form_id'],
		'form_post_id' => (int) $row['form_post_id'],
		'summary'      => minn_admin_cfdb7_summary( $values ),
		'form'         => minn_admin_cfdb7_form_title( $row['form_post_id'] ),
		'status'       => minn_admin_cfdb7_state( $values ),
		'date'         => $row['form_date'],
	);
}

/** An entry's detail; opening it marks it read, CFDB7's own view semantics. */
function minn_admin_cfdb7_view( array $row ) {
	$id      = (int) $row['form_id'];
	$form_id = (int) $row['form_post_id'];
	$values  = minn_admin_cfdb7_values( (string) $row['form_value'] );

	// The entry counts as read only once the write landed.
	$status = minn_admin_cfdb7_state( $values );
	if ( 'unread' === $status ) {
		$patched = minn_admin_cfdb7_set_status( $row['form_value'], 'read' );
		if ( null !== $patched ) {
			if ( Minn_Admin_Kernel_SQL::update( 'db7_forms', 'form_id', (int) $id, array( 'form_value' => $patched ) ) ) {
				$status = 'read';
			}
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
		} else {
			$value = minn_admin_cfdb7_text( $value );
		}
		$label     = preg_replace( '/^your[-_]/', '', (string) $key );
		$label     = ucwords( str_replace( array( '-', '_' ), ' ', $label ) );
		$answers[] = $is_file ? array( 'label' => $label . ' (file)', 'value' => $upload_url . $value, 'type' => 'url' ) : minn_admin_forms_typed( $label, $value );
	}

	$title = minn_admin_cfdb7_form_title( $form_id );
	return minn_admin_forms_entry( $answers, minn_admin_forms_meta_dated( date_i18n( 'M j, Y g:i a', strtotime( (string) $row['form_date'] ) ), $title ), admin_url( 'admin.php?page=cfdb7-list.php&fid=' . $form_id . '&ufid=' . $id ), array(
		'responses' => true,
		'title'     => $title,
		'status'    => $status,
		// The row as it stands now, after opening marked it read: the page's
		// actions gate on it, not on the list's copy.
		'item'      => array(
			'id'           => $id,
			'form_post_id' => $form_id,
			'status'       => $status,
		),
	) );
}

/**
 * Delete an entry: the files it uploaded first, then the row that names them,
 * as CFDB7's own bulk delete does. Dropping only the row would strand a resume
 * or ID scan on disk at a guessable URL with nothing left pointing at it.
 *
 * Every value in the row is visitor input, and a row stored before CFDB7 began
 * keeping only its form's own fields can carry any key the visitor posted, so
 * a value counts as an upload under exactly CFDB7's rules and no looser: the
 * key ENDS in the cfdb7_file marker CFDB7 appends, the value is a string that
 * is already a bare filename (one carrying a path is refused, never trimmed to
 * the name at its end), it resolves to a file inside cfdb7_uploads (a link
 * pointing out does not count), and it is not .php, which is never an upload
 * and is what the folder's own index.php stub is. Values come through the
 * shared data-only decoder with the scanner fallback, lists dropped.
 *
 * @param int   $id  Entry id.
 * @param array $row The entry as loaded.
 * @return true
 */
function minn_admin_cfdb7_delete( $id, array $row ) {
	$dir  = wp_upload_dir()['basedir'] . '/cfdb7_uploads';
	$root = realpath( $dir );
	foreach ( minn_admin_cfdb7_values( (string) $row['form_value'], true ) as $key => $value ) {
		if ( 'cfdb7_file' !== substr( (string) $key, -10 ) || '' === $value || basename( $value ) !== $value ) {
			continue;
		}
		// realpath() throws on a NUL byte, which would fail the whole delete
		// on one stored value; no upload is named so.
		if ( false !== strpos( $value, "\0" ) ) {
			continue;
		}
		$file = realpath( $dir . '/' . $value );
		if ( false === $file || false === $root || ! is_file( $file ) || 0 !== strpos( $file, trailingslashit( $root ) ) ) {
			continue;
		}
		if ( 'php' === strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) ) {
			continue;
		}
		wp_delete_file( $file );
	}
	Minn_Admin_Kernel_SQL::delete( 'db7_forms', 'form_id', (int) $id );
	return true;
}

/** Server-built model for the surface status card. */
function minn_admin_cfdb7_status_model() {
	// form_date is site-local current_time, so the chart groups on the site's
	// days. CFDB7 keeps no spam: every row counts into the one series.
	$n = Minn_Admin_Kernel::inbox_counts( array(
		'resource' => 'cfdb7/entries',
		'received' => array(),
		'chart'    => array( 'column' => 'form_date', 'clock' => 'local', 'split' => 'form_post_id', 'secondary' => array() ),
	) );
	// Read/unread is a fixed token inside the serialized blob, which no column
	// condition reaches; a row without the token is unread on their screen too
	// (the list's rule).
	$unread = Minn_Admin_Kernel_SQL::count( 'db7_forms', array( 'form_value' => array( 'none' => array( array( 'contains' => 's:12:"cfdb7_status";s:4:"read"' ) ) ) ) );
	return minn_admin_forms_card(
		array(
			'label' => __( 'Unread entries', 'minn-admin' ),
			'value' => number_format_i18n( $unread ),
			'hint'  => sprintf(
				/* translators: %s: total number of stored entries. */
				__( '%s total', 'minn-admin' ),
				number_format_i18n( $n['received'] )
			),
		),
		count( minn_admin_cfdb7_form_titles() ),
		$n['days'],
		array( 'label' => __( 'Open CFDB7 ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=cfdb7-list.php' ) )
	);
}

// CFDB7 has one capability for everything it shows and does: its menu gate.
$minn_admin_cfdb7_columns = array( 'form_id', 'form_post_id', 'form_value', 'form_date' );
minn_admin_resource( 'cfdb7/entries', function () use ( $minn_admin_cfdb7_columns ) {
	return array(
		'active'  => 'minn_admin_cfdb7_ready',
		'gates'   => array(
			'view'   => 'minn_admin_cfdb7_can_view',
			'manage' => 'minn_admin_cfdb7_can_view',
		),
		'labels'  => array(
			'not_found' => __( 'Entry not found.', 'minn-admin' ),
		),
		'source'  => array(
			'table'   => 'db7_forms',
			'key'     => 'form_id',
			'columns' => $minn_admin_cfdb7_columns,
			'list'    => function ( array $q ) use ( $minn_admin_cfdb7_columns ) {
				return minn_admin_cfdb7_list( $q, $minn_admin_cfdb7_columns );
			},
		),
		'list'    => array(
			// The read flag is inside the blob; the list source turns the value
			// into its token clause.
			'filter' => array(
				'param'   => 'status',
				'values'  => array(
					'all'    => array(),
					'unread' => array(),
					'read'   => array(),
				),
				'default' => 'all',
			),
			'tab'    => array( 'param' => 'form_post_id', 'column' => 'form_post_id' ),
			'search' => array( 'param' => 'search' ),
			'range'  => array( 'column' => 'form_date', 'clock' => 'local' ),
			'row'    => 'minn_admin_cfdb7_row',
			'fields' => array(
				'id'           => 'int',
				'form_post_id' => 'int',
				'summary'      => 'text',
				'form'         => 'text',
				'status'       => 'text',
				// current_time() is site-local: sent un-zoned.
				'date'         => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'naked' ),
			),
		),
		'item'    => array( 'view' => 'minn_admin_cfdb7_view' ),
		'actions' => array(
			// The same rewrite CFDB7's own bulk action makes (decode the entry, set
			// cfdb7_status, serialize it back). An entry already unread is left as
			// it is.
			'unread' => array(
				'gate'   => 'manage',
				'run'    => function ( $id, $row ) {
					$patched = minn_admin_cfdb7_set_status( $row['form_value'], 'unread' );
					if ( null !== $patched ) {
						Minn_Admin_Kernel_SQL::update( 'db7_forms', 'form_id', (int) $id, array( 'form_value' => $patched ) );
					}
					return true;
				},
				'expect' => function ( $id, $fresh ) {
					return is_array( $fresh ) && 'unread' === minn_admin_cfdb7_state( minn_admin_cfdb7_values( (string) $fresh['form_value'] ) );
				},
				'fail'   => __( 'The entry could not be marked as unread.', 'minn-admin' ),
				'reply'  => function ( $id ) {
					return array( 'id' => (int) $id, 'status' => 'unread' );
				},
				'done'   => __( 'Marked as unread.', 'minn-admin' ),
			),
			// CFDB7 has no trash: delete is permanent from any state.
			'delete' => minn_admin_forms_delete( 'minn_admin_cfdb7_delete', __( 'CFDB7 could not delete the entry.', 'minn-admin' ), array( 'reply' => 'minn_admin_forms_deleted' ) ),
		),
	);
} );
unset( $minn_admin_cfdb7_columns );

minn_admin_endpoint( 'cfdb7/forms', array(
	'active' => 'minn_admin_cfdb7_ready',
	'gate'   => 'minn_admin_cfdb7_can_view',
	'run'    => function () {
		$out = array();
		foreach ( minn_admin_cfdb7_form_titles() as $id => $title ) {
			$out[] = array(
				'id'    => $id,
				'title' => $title,
			);
		}
		return $out;
	},
) );

minn_admin_endpoint( 'cfdb7/status', array(
	'active' => 'minn_admin_cfdb7_ready',
	'gate'   => 'minn_admin_cfdb7_can_view',
	'run'    => 'minn_admin_cfdb7_status_model',
) );

minn_admin_add_surface( 'cfdb7', function () {
	return minn_admin_cfdb7_ready() && minn_admin_cfdb7_can_view();
}, function () {
	$ui = minn_admin_forms_ui( 'cfdb7/entries' );
	return minn_admin_forms_surface( 'cfdb7/entries', array(
		'sub'      => 'CFDB7',
		'plugin'   => 'contact-form-cfdb7',
		'api'      => 'cfdb7',
		'tab'      => 'form_post_id',
		'messages' => true,
		// Read/unread lives in the serialized blob as cfdb7_status.
		'filter'   => array( 'all' => 'All', 'unread' => 'Unread', 'read' => 'Read' ),
		'form'     => 'form',
		'actions'  => array(
			$ui( 'unread', array( 'label' => __( 'Mark as unread', 'minn-admin' ), 'when' => 'read' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete entry', 'minn-admin' ), 'confirm' => __( 'Delete this entry permanently? CFDB7 has no trash.', 'minn-admin' ), 'danger' => true ) ),
			array(
				'label' => __( 'Open in CFDB7 ↗', 'minn-admin' ),
				'href'  => admin_url( 'admin.php?page=cfdb7-list.php&fid={form_post_id}&ufid={id}' ),
			),
		),
		'bulk'     => array(
			$ui( 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => __( 'Delete the selected entries permanently? CFDB7 has no trash.', 'minn-admin' ), 'danger' => true ) ),
		),
	) );
} );
