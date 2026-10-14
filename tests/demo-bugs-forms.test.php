<?php
/**
 * Repros for the forms-family bugs found seeding the live demo: what the
 * entry lists and entry pages answer (D-04, D-07, D-08, D-10 to D-14, their
 * siblings, and the review's N2, S2, I6, I22 and S9).
 *
 * Each section seeds its own rows on this site, through the vendor's API
 * where it has one and its own tables where it does not, reads the routes
 * the client calls as an administrator, and removes the rows in a finally.
 * Where the client decides something (an action's when-gate, which column
 * is the pill), the check reads the surface descriptor and decides it the
 * way the client does. A section SKIPs when its plugin is not active.
 *
 * Run: wp eval-file tests/demo-bugs-forms.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\ndemo-bugs-forms: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

// An insert id worth using or deleting. A WP_Error or false casts to 0 or
// 1, and id 1 is a standing row (or the admin) on most tables.
$id_of = function ( $raw ) {
	if ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) {
		return (int) $raw > 1 ? (int) $raw : 0;
	}
	return 0;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( (int) $admin[0] );
rest_get_server();
$surfaces = apply_filters( 'minn_admin_surfaces', array() );
$token    = 'dbf' . strtolower( wp_generate_password( 8, false ) );

// The client's when-gate (surfaceActionVisible): the item's value at the
// key, as a string, against `equals`.
$visible = function ( $action, $item ) {
	if ( empty( $action['when'] ) ) {
		return true;
	}
	$v = $item;
	foreach ( explode( '.', (string) $action['when']['key'] ) as $k ) {
		$v = is_array( $v ) && array_key_exists( $k, $v ) ? $v[ $k ] : null;
	}
	return (string) $v === (string) $action['when']['equals'];
};
// The entry page's item: the listed row, then the route's own copy over it.
$page_item = function ( $listed, $detail ) {
	return array_merge( (array) $listed, is_array( $detail ) && isset( $detail['item'] ) && is_array( $detail['item'] ) ? $detail['item'] : array() );
};
// The value the list's pill column shows for a row.
$pill_of = function ( $surface, $item ) {
	foreach ( (array) ( $surface['collection']['columns'] ?? array() ) as $col ) {
		if ( 'pill' === ( $col['format'] ?? '' ) ) {
			return isset( $item[ $col['key'] ] ) ? (string) $item[ $col['key'] ] : '';
		}
	}
	return '';
};
$ids_of  = function ( $items ) {
	return array_map( function ( $r ) {
		return (int) $r['id'];
	}, (array) $items );
};
$rows_of = function ( $detail ) {
	$rows = array();
	foreach ( (array) ( $detail['sections'] ?? array() ) as $g ) {
		foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
			$rows[ (string) ( $r['label'] ?? '' ) ] = $r;
		}
	}
	return $rows;
};
// What the entry page prints for the Submitted row: the value, as is.
$is_raw_iso = function ( $v ) {
	return (bool) preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d/', (string) $v );
};

/* ===== WPForms: D-04, D-13, D-14 ===== */
if ( ! function_exists( 'minn_admin_wpforms_active' ) || ! minn_admin_wpforms_active() || empty( $surfaces['wpforms'] ) ) {
	$skip( 'WPForms (D-04, D-13, D-14): WPForms Pro is not active here' );
} else {
	$wpf_ids  = array();
	$wpf_form = get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
	$wpf_form = $wpf_form ? (int) $wpf_form[0] : 0;
	try {
		if ( ! $wpf_form ) {
			$skip( 'WPForms (D-04, D-13, D-14): no published form to add entries to' );
		} else {
			$wpf_add = function ( $name, $date, $viewed ) use ( $wpf_form, $token, $id_of ) {
				$fields = array(
					0 => array( 'id' => 0, 'type' => 'name', 'name' => 'Name', 'value' => $name ),
					1 => array( 'id' => 1, 'type' => 'email', 'name' => 'Email', 'value' => strtolower( strtok( $name, ' ' ) ) . '.' . $token . '@example.com' ),
					2 => array( 'id' => 2, 'type' => 'textarea', 'name' => 'Message', 'value' => 'Fixture ' . $token ),
				);
				$id = $id_of( wpforms()->obj( 'entry' )->add( array( 'form_id' => $wpf_form, 'status' => '', 'fields' => wp_json_encode( $fields ), 'date' => $date ) ) );
				if ( $id && $viewed ) {
					wpforms()->obj( 'entry' )->update( $id, array( 'viewed' => 1 ) );
				}
				return $id;
			};
			// The newest submission has the LOWER id; the read one came in
			// later with an older date, the way an import lands.
			$wpf_new   = $wpf_add( 'Ada Newer', '2026-10-09 19:44:00', 0 );
			$wpf_old   = $wpf_add( 'Bea Imported', '2026-01-05 09:00:00', 1 );
			$wpf_ids   = array_filter( array( $wpf_new, $wpf_old ) );
			$check( 'WPForms fixtures: two entries through their handler', $wpf_new && $wpf_old && $wpf_old > $wpf_new, "{$wpf_new}, {$wpf_old}" );

			// D-14: the list opens on its first filter option.
			$opts  = (array) $surfaces['wpforms']['collection']['filter']['options'];
			$first = (string) $opts[0][0];
			list( , $opened ) = $call( 'GET', '/minn-admin/v1/wpforms/entries', array( 'status' => $first, 'search' => $token ) );
			$check( 'D-14: WPForms opens on everything received, read entries included', array( $wpf_new, $wpf_old ) === array_values( array_intersect( array( $wpf_new, $wpf_old ), $ids_of( $opened['items'] ?? array() ) ) ),
				'first filter ' . $first . ', listed ' . implode( ',', $ids_of( $opened['items'] ?? array() ) ) );
			list( , $unread ) = $call( 'GET', '/minn-admin/v1/wpforms/entries', array( 'status' => 'unread', 'search' => $token ) );
			$check( 'D-14: the Unread filter is still there and still narrows', in_array( 'unread', array_map( 'strval', array_column( $opts, 0 ) ), true )
				&& array( $wpf_new ) === $ids_of( $unread['items'] ?? array() ), implode( ',', $ids_of( $unread['items'] ?? array() ) ) );

			// D-13: newest submission first, whatever the ids say.
			list( , $all ) = $call( 'GET', '/minn-admin/v1/wpforms/entries', array( 'status' => 'inbox', 'search' => $token ) );
			$check( 'D-13: WPForms sorts entries by submission date', array( $wpf_new, $wpf_old ) === $ids_of( $all['items'] ?? array() ), implode( ',', $ids_of( $all['items'] ?? array() ) ) );
			list( , $p1 ) = $call( 'GET', '/minn-admin/v1/wpforms/entries', array( 'status' => 'inbox', 'search' => $token, 'per_page' => 1, 'page' => 1 ) );
			list( , $p2 ) = $call( 'GET', '/minn-admin/v1/wpforms/entries', array( 'status' => 'inbox', 'search' => $token, 'per_page' => 1, 'page' => 2 ) );
			$check( 'D-13: WPForms pages follow that order and keep the total', array( $wpf_new ) === $ids_of( $p1['items'] ?? array() ) && array( $wpf_old ) === $ids_of( $p2['items'] ?? array() )
				&& 2 === (int) ( $p1['total'] ?? 0 ) && 2 === (int) ( $p2['total'] ?? 0 ), wp_json_encode( array( $ids_of( $p1['items'] ?? array() ), $ids_of( $p2['items'] ?? array() ), $p1['total'] ?? null ) ) );

			// D-04: the Submitted row is printed as sent, so it must arrive
			// formatted on the site's clock (opening marks the fixture read).
			list( $st, $det ) = $call( 'GET', '/minn-admin/v1/wpforms/entries/' . $wpf_new );
			$rows = $rows_of( $det );
			$sub  = (string) ( $rows['Submitted']['value'] ?? '' );
			$want = date_i18n( 'M j, Y g:i a', strtotime( get_date_from_gmt( '2026-10-09 19:44:00' ) ) );
			$check( 'D-04: WPForms sends the submission date formatted, not raw ISO', 200 === $st && ! $is_raw_iso( $sub ) && $want === $sub, $sub . ' (want ' . $want . ')' );

			// The D-10 class: the page's actions gate on the route's copy of
			// the row over the list's, which said unread before opening.
			$listed = array();
			foreach ( (array) ( $all['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $wpf_new ) {
					$listed = $it;
				}
			}
			$shown = array();
			foreach ( (array) $surfaces['wpforms']['collection']['actions'] as $a ) {
				if ( empty( $a['href'] ) && $visible( $a, $page_item( $listed, $det ) ) ) {
					$shown[] = (string) $a['label'];
				}
			}
			global $wpdb;
			$viewed = $wpdb->get_var( $wpdb->prepare( "SELECT viewed FROM {$wpdb->prefix}wpforms_entries WHERE entry_id = %d", $wpf_new ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$check( 'D-10 class: a WPForms entry opened unread offers what a read entry offers', 'unread' === ( $listed['status'] ?? '' ) && '1' === (string) $viewed
				&& array( 'Mark unread', 'Star', 'Mark spam', 'Trash' ) === $shown, implode( ', ', $shown ) );

			// The D-12 class: the page's pill shows the status; the panel
			// printed it again as a raw word.
			$raw = array_filter( $rows, function ( $r ) {
				return in_array( (string) ( $r['value'] ?? '' ), array( 'read', 'unread', 'spam', 'trash' ), true );
			} );
			$check( 'D-12 class: the WPForms Submission panel prints no raw status', ! $raw, implode( ', ', array_keys( $raw ) ) );
		}
	} finally {
		foreach ( $wpf_ids as $id ) {
			if ( $id > 1 ) {
				wpforms()->obj( 'entry' )->delete( $id );
			}
		}
	}
}

/* ===== Gravity Forms: D-07 ===== */
if ( ! class_exists( 'GFAPI' ) || empty( $surfaces['gravity-forms'] ) ) {
	$skip( 'Gravity Forms (D-07): Gravity Forms is not active here' );
} else {
	$gf_form    = 0;
	$gf_entries = array();
	try {
		$gf_form = $id_of( GFAPI::add_form( array(
			'title'  => 'Minn demo bugs ' . $token,
			'fields' => array(
				array(
					'type'       => 'name',
					'id'         => 1,
					'label'      => 'Name',
					'nameFormat' => 'advanced',
					'inputs'     => array(
						array( 'id' => '1.3', 'label' => 'First' ),
						array( 'id' => '1.6', 'label' => 'Last' ),
					),
				),
				array( 'type' => 'email', 'id' => 2, 'label' => 'Email' ),
				array( 'type' => 'textarea', 'id' => 3, 'label' => 'Message' ),
			),
		) ) );
		$gf_emma = $gf_form ? $id_of( GFAPI::add_entry( array( 'form_id' => $gf_form, '1.3' => 'Emma', '1.6' => 'Larsen', '2' => 'emma.' . $token . '@example.net', '3' => "Line one.\nLine two." ) ) ) : 0;
		$gf_noah = $gf_form ? $id_of( GFAPI::add_entry( array( 'form_id' => $gf_form, '1.3' => 'Noah', '1.6' => 'Berg', '2' => 'noah.' . $token . '@example.net', '3' => 'Read and starred.' ) ) ) : 0;
		$gf_entries = array_filter( array( $gf_emma, $gf_noah ) );
		if ( $gf_noah ) {
			GFAPI::update_entry_property( $gf_noah, 'is_read', 1 );
			GFAPI::update_entry_property( $gf_noah, 'is_starred', 1 );
		}
		$check( 'GF fixtures: a disposable form with two entries', $gf_form && $gf_emma && $gf_noah, "{$gf_form}: {$gf_emma}, {$gf_noah}" );

		if ( $gf_form && $gf_emma && $gf_noah ) {
			$gf_list = function ( $search = null ) use ( $call, $gf_form ) {
				$params = array(
					'sorting' => array( 'key' => 'date_created', 'direction' => 'DESC' ),
					'paging'  => array( 'page_size' => 25, 'current_page' => 1 ),
				);
				if ( null !== $search ) {
					$params['search'] = wp_json_encode( $search );
				}
				list( , $body ) = $call( 'GET', '/minn-admin/v1/gf/forms/' . $gf_form . '/entries', $params );
				$by = array();
				foreach ( (array) ( $body['entries'] ?? array() ) as $e ) {
					$by[ (int) $e['id'] ] = $e;
				}
				return $by;
			};
			$gf_surface = $surfaces['gravity-forms'];
			$listed     = $gf_list();
			$emma       = $listed[ $gf_emma ] ?? array();
			$noah       = $listed[ $gf_noah ] ?? array();

			// The row line: the client prints `summary` when the row has one,
			// and otherwise joins the raw numeric keys (1.3, 1.6, 2 …).
			$line = (string) ( $emma['summary'] ?? '' );
			$check( 'D-07: a Name field reads as one name in the row', false !== strpos( $line, 'Emma Larsen' ) && false === strpos( $line, 'Emma · Larsen' ), '' === $line ? '(no summary: the client joins the parts)' : $line );

			$check( 'D-07: the row pill says unread or read, not "active"', 'unread' === $pill_of( $gf_surface, $emma ) && 'read' === $pill_of( $gf_surface, $noah ),
				$pill_of( $gf_surface, $emma ) . ' / ' . $pill_of( $gf_surface, $noah ) );

			$star_col = '';
			foreach ( (array) $gf_surface['collection']['columns'] as $col ) {
				if ( '★' === ( $noah[ $col['key'] ] ?? null ) && '★' !== ( $emma[ $col['key'] ] ?? null ) ) {
					$star_col = (string) $col['key'];
				}
			}
			$check( 'D-07: a column marks starred entries', '' !== $star_col, $star_col );

			$filters = array_map( 'strval', array_column( (array) $gf_surface['collection']['filter']['options'], 0 ) );
			$unread  = array_keys( $gf_list( array( 'status' => 'unread' ) ) );
			$starred = array_keys( $gf_list( array( 'status' => 'starred' ) ) );
			$both    = array_keys( $gf_list( array( 'status' => 'unread', 'field_filters' => array( array( 'key' => 0, 'value' => 'Noah', 'operator' => 'contains' ) ) ) ) );
			$check( 'D-07: Unread and Starred filters, which keep the search', in_array( 'unread', $filters, true ) && in_array( 'starred', $filters, true )
				&& array( $gf_emma ) === $unread && array( $gf_noah ) === $starred && array() === $both,
				wp_json_encode( array( 'filters' => $filters, 'unread' => $unread, 'starred' => $starred, 'unread+Noah' => $both ) ) );

			// The entry page's pill is the route's status; opening marks the
			// entry read, and the list's pill then has to say the same.
			list( , $page ) = $call( 'GET', '/minn-admin/v1/gf/entries/' . $gf_emma );
			$after = $gf_list();
			$check( 'D-07: the list pill and the entry page agree', (string) ( $page['status'] ?? '' ) === $pill_of( $gf_surface, $after[ $gf_emma ] ?? array() ),
				( $page['status'] ?? '' ) . ' vs ' . $pill_of( $gf_surface, $after[ $gf_emma ] ?? array() ) );

			// N2: the client copies the route's item onto the cached list
			// row, so the item carries the row's pill and star as they now
			// stand, not only GF's raw flags.
			$pitem = (array) ( $page['item'] ?? array() );
			$check( 'N2: the GF entry item carries the row\'s state and star', isset( $pitem['state'], $pitem['starred'] )
				&& $pill_of( $gf_surface, $after[ $gf_emma ] ?? array() ) === $pitem['state'] && ( $after[ $gf_emma ][ $star_col ] ?? null ) === $pitem['starred'],
				wp_json_encode( array_intersect_key( $pitem, array( 'state' => 1, 'starred' => 1, 'is_read' => 1 ) ) ) );

			// I6: the default cell prints a dash for an empty value, so an
			// unstarred row needs a value that renders as nothing.
			$blank = function ( $v ) {
				return null !== $v && '' !== (string) $v && '' === trim( wp_strip_all_tags( (string) $v ) );
			};
			$check( 'I6: an unstarred GF row\'s star cell renders blank, not a dash', '' !== $star_col && $blank( $emma[ $star_col ] ?? null ),
				wp_json_encode( $emma[ $star_col ] ?? null ) );
		}
	} finally {
		foreach ( $gf_entries as $id ) {
			if ( $id > 1 ) {
				GFAPI::delete_entry( $id );
			}
		}
		if ( $gf_form > 1 ) {
			GFAPI::delete_form( $gf_form );
		}
	}
}

/* ===== CFDB7: D-08 ===== */
if ( ! function_exists( 'minn_admin_cfdb7_ready' ) || ! minn_admin_cfdb7_ready() || empty( $surfaces['cfdb7'] ) ) {
	$skip( 'CFDB7 (D-08): CFDB7 is not active here' );
} else {
	global $wpdb;
	$cf_table = $wpdb->prefix . 'db7_forms';
	$cf_id    = 0;
	try {
		$cf_form = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'numberposts' => 1, 'fields' => 'ids' ) );
		$cf_form = $cf_form ? (int) $cf_form[0] : 0;
		// What the visitor typed, stored the way CFDB7 stores it: its
		// cfdb7_before_send_mail swaps quotes, apostrophes and slashes for
		// entities before it serializes the answers.
		$typed = array(
			'your-name'    => "Ana O'Neil",
			'your-message' => 'I\'ve got a "fern" from https://example.com/' . $token . ' <b>and</b> & more',
		);
		$bl    = array( '\"', "\'", '/', '\\', '"', "'" );
		$wl    = array( '&quot;', '&#039;', '&#047;', '&#092;', '&quot;', '&#039;' );
		$data  = array( 'cfdb7_status' => 'unread' );
		foreach ( $typed as $k => $v ) {
			$data[ $k ] = str_replace( $bl, $wl, $v );
		}
		$ok    = $wpdb->insert( $cf_table, array(
			'form_post_id' => $cf_form,
			'form_value'   => serialize( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- CFDB7's own storage shape.
			'form_date'    => current_time( 'Y-m-d H:i:s' ),
		) );
		$cf_id = $ok ? $id_of( (int) $wpdb->insert_id ) : 0;
		$check( 'CFDB7 fixture: an entry stored with CFDB7\'s entities', $cf_id > 0 && false !== strpos( $data['your-name'], '&#039;' ), (string) $cf_id );
		if ( $cf_id ) {
			list( , $list ) = $call( 'GET', '/minn-admin/v1/cfdb7/entries', array( 'search' => $token ) );
			$row = array();
			foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $cf_id ) {
					$row = $it;
				}
			}
			$check( 'D-08: the CFDB7 row line reads as typed', false !== strpos( (string) ( $row['summary'] ?? '' ), "Ana O'Neil" ), (string) ( $row['summary'] ?? '(no row)' ) );

			// Search: a term with an apostrophe or quotes finds the entry
			// CFDB7 stored as entities; LIKE wildcards in a term stay literal.
			$finds = function ( $term ) use ( $call, $cf_id ) {
				list( , $hit ) = $call( 'GET', '/minn-admin/v1/cfdb7/entries', array( 'search' => $term, 'per_page' => 100 ) );
				return in_array( $cf_id, array_map( 'intval', array_column( (array) ( $hit['items'] ?? array() ), 'id' ) ), true );
			};
			$check( 'D-08 class: a CFDB7 search for a typed apostrophe or quotes finds the entry', $finds( "O'Neil" ) && $finds( 'a "fern" from' ) && $finds( $token ),
				wp_json_encode( array( "O'Neil" => $finds( "O'Neil" ), '"fern"' => $finds( 'a "fern" from' ), 'plain' => $finds( $token ) ) ) );
			$check( 'D-08 class: a CFDB7 search keeps LIKE wildcards literal', ! $finds( 'O_Neil' ) && ! $finds( 'Ana%Neil' ) );
			list( , $det ) = $call( 'GET', '/minn-admin/v1/cfdb7/entries/' . $cf_id );
			$rows = $rows_of( $det );
			$check( 'D-08: the CFDB7 entry shows the answers as typed, entities decoded',
				$typed['your-name'] === ( $rows['Name']['value'] ?? null ) && $typed['your-message'] === ( $rows['Message']['value'] ?? null ),
				wp_json_encode( array( $rows['Name']['value'] ?? null, $rows['Message']['value'] ?? null ) ) );

			// S2: opening marked the entry read; the page's Mark as unread
			// gates on the route's copy of the row over the list's.
			$unmark = null;
			foreach ( (array) $surfaces['cfdb7']['collection']['actions'] as $a ) {
				if ( isset( $a['route'] ) && '/unread' === substr( $a['route'], -7 ) ) {
					$unmark = $a;
				}
			}
			$check( 'S2: CFDB7 offers Mark as unread once opening marked the entry read',
				$unmark && 'unread' === ( $row['status'] ?? '' ) && $visible( $unmark, $page_item( $row, $det ) ),
				'page item status ' . ( $page_item( $row, $det )['status'] ?? '?' ) );
		}
	} finally {
		if ( $cf_id > 1 ) {
			$wpdb->delete( $cf_table, array( 'form_id' => $cf_id ), array( '%d' ) );
		}
	}
}

/* ===== Fluent Forms: D-10, D-11, D-13 ===== */
if ( ! function_exists( 'minn_admin_fluent_forms_ready' ) || ! minn_admin_fluent_forms_ready() || empty( $surfaces['fluent-forms'] ) ) {
	$skip( 'Fluent Forms (D-10, D-11, D-13): Fluent Forms is not active here' );
} else {
	global $wpdb;
	$ff_subs = $wpdb->prefix . 'fluentform_submissions';
	$ff_ids  = array();
	try {
		$ff_form = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}fluentform_forms WHERE status = 'published' ORDER BY ( title = 'Minn Contact' ) DESC, id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ff_add  = function ( $name, $status, $created ) use ( $wpdb, $ff_subs, $ff_form, $token, $id_of ) {
			$ok = $wpdb->insert( $ff_subs, array(
				'form_id'       => $ff_form,
				'serial_number' => 900000 + wp_rand( 1, 99999 ),
				'response'      => wp_json_encode( array( 'names' => $name, 'email' => strtolower( strtok( $name, ' ' ) ) . '.' . $token . '@example.com', 'message' => 'Fixture ' . $token ) ),
				'source_url'    => home_url( '/' ),
				'user_id'       => 0,
				'status'        => $status,
				'is_favourite'  => 0,
				'browser'       => 'Chrome',
				'device'        => 'desktop',
				'ip'            => '127.0.0.1',
				'created_at'    => $created,
				'updated_at'    => $created,
			) );
			return $ok ? $id_of( (int) $wpdb->insert_id ) : 0;
		};
		$now     = current_time( 'mysql' ); // Fluent stamps site-local time.
		$ff_new  = $ff_form ? $ff_add( 'Ada Newer', 'unread', $now ) : 0;
		$ff_old  = $ff_form ? $ff_add( 'Bea Imported', 'read', '2026-01-05 09:00:00' ) : 0;
		$ff_spam = $ff_form ? $ff_add( 'Cy Spammer', 'spam', $now ) : 0;
		$ff_bin  = $ff_form ? $ff_add( 'Di Binned', 'trashed', $now ) : 0;
		$ff_ids  = array_filter( array( $ff_new, $ff_old, $ff_spam, $ff_bin ) );
		$check( 'Fluent fixtures: received, read, spam and trashed submissions', 4 === count( $ff_ids ) && $ff_old > $ff_new, implode( ',', $ff_ids ) );

		if ( 4 === count( $ff_ids ) ) {
			// D-13: newest submission first, whatever the ids say.
			list( , $all ) = $call( 'GET', '/minn-admin/v1/fluent-forms/entries', array( 'status' => 'inbox', 'search' => $token ) );
			$check( 'D-13: Fluent Forms sorts entries by submission date', array( $ff_new, $ff_old ) === $ids_of( $all['items'] ?? array() ), implode( ',', $ids_of( $all['items'] ?? array() ) ) );
			list( , $p1 ) = $call( 'GET', '/minn-admin/v1/fluent-forms/entries', array( 'status' => 'inbox', 'search' => $token, 'per_page' => 1, 'page' => 1 ) );
			list( , $p2 ) = $call( 'GET', '/minn-admin/v1/fluent-forms/entries', array( 'status' => 'inbox', 'search' => $token, 'per_page' => 1, 'page' => 2 ) );
			$check( 'D-13: Fluent pages follow that order and keep the total', array( $ff_new ) === $ids_of( $p1['items'] ?? array() ) && array( $ff_old ) === $ids_of( $p2['items'] ?? array() )
				&& 2 === (int) ( $p1['total'] ?? 0 ) && 2 === (int) ( $p2['total'] ?? 0 ), wp_json_encode( array( $ids_of( $p1['items'] ?? array() ), $ids_of( $p2['items'] ?? array() ), $p1['total'] ?? null ) ) );

			// D-11: a form's count is its Received list's total.
			list( , $forms ) = $call( 'GET', '/minn-admin/v1/fluent-forms/forms', array( 'manage' => 1 ) );
			$count = null;
			foreach ( (array) $forms as $f ) {
				if ( (int) $f['id'] === $ff_form ) {
					$count = (int) $f['entries'];
				}
			}
			list( , $inbox ) = $call( 'GET', '/minn-admin/v1/fluent-forms/entries', array( 'status' => 'inbox', 'form_id' => $ff_form, 'per_page' => 1 ) );
			$check( 'D-11: a Fluent form\'s entry count leaves out spam and trash', null !== $count && (int) ( $inbox['total'] ?? -1 ) === $count, "count {$count}, received " . ( $inbox['total'] ?? '?' ) );

			// D-10: opening marks the entry read; the page's Mark as read
			// gates on the route's copy of the row over the list's.
			$mark = null;
			foreach ( (array) $surfaces['fluent-forms']['collection']['actions'] as $a ) {
				if ( isset( $a['when']['key'], $a['body']['status'] ) && 'status' === $a['when']['key'] && 'read' === $a['body']['status'] ) {
					$mark = $a;
				}
			}
			$listed = array();
			foreach ( (array) ( $all['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $ff_new ) {
					$listed = $it;
				}
			}
			list( , $det ) = $call( 'GET', '/minn-admin/v1/fluent-forms/entries/' . $ff_new );
			$stored = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$ff_subs} WHERE id = %d", $ff_new ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$check( 'D-10: Fluent drops Mark as read once opening marked the entry read',
				$mark && 'unread' === ( $listed['status'] ?? '' ) && 'read' === $stored && ! $visible( $mark, $page_item( $listed, $det ) ),
				'stored ' . $stored . ', page item status ' . ( $page_item( $listed, $det )['status'] ?? '?' ) );
		}
	} finally {
		foreach ( $ff_ids as $id ) {
			if ( $id > 1 ) {
				$wpdb->delete( $ff_subs, array( 'id' => $id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'fluentform_entry_details', array( 'submission_id' => $id ), array( '%d' ) );
			}
		}
	}
}

/* ===== Elementor Pro forms: D-10 ===== */
if ( ! function_exists( 'minn_admin_elementor_forms_ready' ) || ! minn_admin_elementor_forms_ready() || empty( $surfaces['elementor-forms'] ) ) {
	$skip( 'Elementor forms (D-10): Elementor Pro form submissions are not available here' );
} else {
	$el_page = 0;
	$el_sub  = 0;
	$el_q    = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
	global $wpdb;
	try {
		$el_page = $id_of( wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Minn demo bugs ' . $token ) ) );
		$el_sub  = $el_page ? $id_of( $el_q->add_submission(
			array( 'post_id' => $el_page, 'element_id' => substr( $token, 0, 7 ), 'form_name' => 'Demo bugs ' . $token, 'referer' => home_url( '/' ), 'referer_title' => 'Home' ),
			array(
				array( 'id' => 'name', 'type' => 'text', 'value' => 'Ada Tester' ),
				array( 'id' => 'email', 'type' => 'email', 'value' => 'ada.' . $token . '@example.com' ),
			)
		) ) : 0;
		$check( 'Elementor fixture: an unread submission through their Query', $el_sub > 0, (string) $el_sub );
		if ( $el_sub ) {
			$mark = null;
			foreach ( (array) $surfaces['elementor-forms']['collection']['actions'] as $a ) {
				if ( isset( $a['when']['key'], $a['when']['equals'], $a['route'] ) && 'status' === $a['when']['key'] && 'unread' === $a['when']['equals'] && '/read' === substr( $a['route'], -5 ) ) {
					$mark = $a;
				}
			}
			list( , $list ) = $call( 'GET', '/minn-admin/v1/elementor/submissions', array( 'search' => $token ) );
			$listed = array();
			foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $el_sub ) {
					$listed = $it;
				}
			}
			list( , $det ) = $call( 'GET', '/minn-admin/v1/elementor/submissions/' . $el_sub );
			$raw = $el_q->get_submission( $el_sub );
			$check( 'D-10: Elementor drops Mark as read once opening marked the entry read',
				$mark && 'unread' === ( $listed['status'] ?? '' ) && ! empty( $raw['data']['is_read'] ) && ! $visible( $mark, $page_item( $listed, $det ) ),
				'page item status ' . ( $page_item( $listed, $det )['status'] ?? '?' ) );

			// I22: when Elementor's update does not land, the entry is still
			// unread and the page must keep saying so. The write is refused
			// here by blanking that one UPDATE, which wpdb answers with false.
			$el_q->update_submission( $el_sub, array( 'is_read' => 0 ) );
			$refuse = function ( $sql ) use ( $wpdb ) {
				return ( 0 === stripos( ltrim( $sql ), 'UPDATE' ) && false !== strpos( $sql, $wpdb->prefix . 'e_submissions`' ) && false !== strpos( $sql, 'is_read' ) ) ? '' : $sql;
			};
			add_filter( 'query', $refuse );
			try {
				list( , $det2 ) = $call( 'GET', '/minn-admin/v1/elementor/submissions/' . $el_sub );
			} finally {
				remove_filter( 'query', $refuse );
			}
			$raw2 = $el_q->get_submission( $el_sub );
			$check( 'I22: Elementor keeps an entry unread when its update did not land',
				empty( $raw2['data']['is_read'] ) && 'unread' === ( $det2['item']['status'] ?? '' ) && $visible( $mark, $page_item( $listed, $det2 ) ),
				'stored is_read ' . wp_json_encode( $raw2['data']['is_read'] ?? null ) . ', item status ' . ( $det2['item']['status'] ?? '?' ) );
		}
	} finally {
		if ( $el_sub > 1 ) {
			$el_q->delete_submission( $el_sub );
		}
		if ( $el_page > 1 && 'page' === get_post_type( $el_page ) ) {
			wp_delete_post( $el_page, true );
		}
	}
}

/* ===== Ninja Forms: D-12, D-13 ===== */
if ( ! function_exists( 'minn_admin_ninja_forms_active' ) || ! minn_admin_ninja_forms_active() || empty( $surfaces['ninja-forms'] ) ) {
	$skip( 'Ninja Forms (D-12, D-13): Ninja Forms is not active here' );
} else {
	global $wpdb;
	$nf_ids = array();
	try {
		$nf_form  = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}nf3_forms ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$nf_field = $nf_form ? array_keys( minn_admin_ninja_forms_fields( $nf_form ) ) : array();
		// A submission is an nf_sub post with plain postmeta, the shape
		// Ninja Forms' own Submission model saves (no seq counter bumped).
		$nf_add = function ( $name, $date ) use ( $nf_form, $nf_field, $token, $id_of ) {
			$id = $id_of( wp_insert_post( array(
				'post_type'     => 'nf_sub',
				'post_status'   => 'publish',
				'post_title'    => '',
				'post_date'     => $date,
				'post_date_gmt' => get_gmt_from_date( $date ),
			) ) );
			if ( $id ) {
				update_post_meta( $id, '_form_id', $nf_form );
				update_post_meta( $id, '_seq_num', 90000 + $id );
				update_post_meta( $id, '_field_' . $nf_field[0], $name . ' ' . $token );
			}
			return $id;
		};
		$nf_new = $nf_field ? $nf_add( 'Ada Newer', current_time( 'mysql' ) ) : 0;
		$nf_old = $nf_field ? $nf_add( 'Bea Imported', '2026-01-05 09:00:00' ) : 0;
		$nf_ids = array_filter( array( $nf_new, $nf_old ) );
		$check( 'Ninja fixtures: two submissions', $nf_new && $nf_old && $nf_old > $nf_new, "{$nf_new}, {$nf_old}" );
		if ( $nf_new && $nf_old ) {
			list( , $all ) = $call( 'GET', '/minn-admin/v1/ninja-forms/entries', array( 'search' => $token ) );
			$listed = array();
			foreach ( (array) ( $all['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $nf_new ) {
					$listed = $it;
				}
			}
			// D-12: the row pill, the page's pill and its Submission panel.
			list( , $det ) = $call( 'GET', '/minn-admin/v1/ninja-forms/entries/' . $nf_new );
			$rows = $rows_of( $det );
			$raw  = array_filter( $rows, function ( $r ) {
				return 'publish' === (string) ( $r['value'] ?? '' );
			} );
			$check( 'D-12: Ninja Forms shows a live entry as received, never "publish"',
				'received' === $pill_of( $surfaces['ninja-forms'], $listed ) && 'received' === ( $det['status'] ?? '' ) && ! $raw,
				wp_json_encode( array( 'row' => $pill_of( $surfaces['ninja-forms'], $listed ), 'page' => $det['status'] ?? null, 'panel' => array_keys( $raw ) ) ) );
			$check( 'D-12: Ninja Forms still gates its actions on the post status', 'publish' === ( $listed['status'] ?? '' ) );

			// D-13: newest submission first, whatever the ids say.
			$check( 'D-13: Ninja Forms sorts entries by submission date', array( $nf_new, $nf_old ) === $ids_of( $all['items'] ?? array() ), implode( ',', $ids_of( $all['items'] ?? array() ) ) );
			list( , $p1 ) = $call( 'GET', '/minn-admin/v1/ninja-forms/entries', array( 'search' => $token, 'per_page' => 1, 'page' => 1 ) );
			list( , $p2 ) = $call( 'GET', '/minn-admin/v1/ninja-forms/entries', array( 'search' => $token, 'per_page' => 1, 'page' => 2 ) );
			$check( 'D-13: Ninja pages follow that order and keep the total', array( $nf_new ) === $ids_of( $p1['items'] ?? array() ) && array( $nf_old ) === $ids_of( $p2['items'] ?? array() )
				&& 2 === (int) ( $p1['total'] ?? 0 ) && 2 === (int) ( $p2['total'] ?? 0 ), wp_json_encode( array( $ids_of( $p1['items'] ?? array() ), $ids_of( $p2['items'] ?? array() ), $p1['total'] ?? null ) ) );
		}
	} finally {
		foreach ( $nf_ids as $id ) {
			if ( $id > 1 && 'nf_sub' === get_post_type( $id ) ) {
				wp_delete_post( $id, true );
			}
		}
	}
}

/* ===== Everest Forms: D-12 ===== */
if ( ! function_exists( 'minn_admin_everest_active' ) || ! minn_admin_everest_active() || empty( $surfaces['everest-forms'] ) ) {
	$skip( 'Everest Forms (D-12): Everest Forms is not active here' );
} else {
	global $wpdb;
	$evf_id = 0;
	try {
		$evf_form  = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'everest_form' AND post_status = 'publish' ORDER BY ( post_title = 'Minn EVF Fixture' ) DESC, ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$evf_field = $evf_form ? array_keys( minn_admin_everest_fields( $evf_form ) ) : array();
		// Their form task's insert: a UTC stamp, publish, unviewed.
		$ok = $evf_field && $wpdb->insert( $wpdb->prefix . 'evf_entries', array(
			'form_id'         => $evf_form,
			'user_id'         => 0,
			'user_device'     => '',
			'user_ip_address' => '127.0.0.1',
			'status'          => 'publish',
			'referer'         => '',
			'fields'          => '{}',
			'date_created'    => current_time( 'mysql', true ),
			'viewed'          => 0,
			'starred'         => 0,
		) );
		$evf_id = $ok ? $id_of( (int) $wpdb->insert_id ) : 0;
		if ( $evf_id ) {
			$wpdb->insert( $wpdb->prefix . 'evf_entrymeta', array( 'entry_id' => $evf_id, 'meta_key' => $evf_field[0], 'meta_value' => 'Ada Tester ' . $token ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$check( 'Everest fixture: one received entry', $evf_id > 0, (string) $evf_id );
		if ( $evf_id ) {
			list( , $list ) = $call( 'GET', '/minn-admin/v1/everest/entries', array( 'search' => $token ) );
			$listed = array();
			foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
				if ( (int) $it['id'] === $evf_id ) {
					$listed = $it;
				}
			}
			list( , $det ) = $call( 'GET', '/minn-admin/v1/everest/entries/' . $evf_id );
			$rows = $rows_of( $det );
			$raw  = array_filter( $rows, function ( $r ) {
				return 'publish' === (string) ( $r['value'] ?? '' );
			} );
			$check( 'D-12: Everest Forms shows a live entry as received, never "publish"',
				'received' === $pill_of( $surfaces['everest-forms'], $listed ) && 'received' === ( $det['status'] ?? '' ) && ! $raw,
				wp_json_encode( array( 'row' => $pill_of( $surfaces['everest-forms'], $listed ), 'page' => $det['status'] ?? null, 'panel' => array_keys( $raw ) ) ) );
			$check( 'D-12: Everest Forms still gates its actions on its own status', 'publish' === ( $listed['status'] ?? '' ) );
		}
	} finally {
		if ( $evf_id > 1 ) {
			$wpdb->delete( $wpdb->prefix . 'evf_entrymeta', array( 'entry_id' => $evf_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'evf_entries', array( 'entry_id' => $evf_id ), array( '%d' ) );
		}
	}
}

/* ===== Forminator and Formidable: S9 (the D-13 sort) ===== */
// Two entries each: the newest has the LOWER id, the one added later
// carries an older date, the way an import lands. Rows go in with the
// vendor's own columns; neither plugin's API takes a submission date.
$sorts = function ( $label, $route, $new, $old ) use ( $call, $check, $ids_of, $token ) {
	list( , $all ) = $call( 'GET', $route, array( 'search' => $token ) );
	$check( "S9: {$label} sorts entries by submission date", array( $new, $old ) === $ids_of( $all['items'] ?? array() ), implode( ',', $ids_of( $all['items'] ?? array() ) ) );
	list( , $p1 ) = $call( 'GET', $route, array( 'search' => $token, 'per_page' => 1, 'page' => 1 ) );
	list( , $p2 ) = $call( 'GET', $route, array( 'search' => $token, 'per_page' => 1, 'page' => 2 ) );
	$check( "S9: {$label} pages follow that order and keep the total", array( $new ) === $ids_of( $p1['items'] ?? array() ) && array( $old ) === $ids_of( $p2['items'] ?? array() )
		&& 2 === (int) ( $p1['total'] ?? 0 ) && 2 === (int) ( $p2['total'] ?? 0 ), wp_json_encode( array( $ids_of( $p1['items'] ?? array() ), $ids_of( $p2['items'] ?? array() ), $p1['total'] ?? null ) ) );
};

if ( ! class_exists( 'Forminator_Form_Entry_Model' ) || empty( $surfaces['forminator'] ) ) {
	$skip( 'Forminator (S9): Forminator is not active here' );
} else {
	global $wpdb;
	$fm_ids = array();
	try {
		$fm_form = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'forminator_forms' ORDER BY ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Forminator stamps date_created with date_i18n: site-local.
		$fm_add = function ( $name, $date ) use ( $wpdb, $fm_form, $token, $id_of ) {
			$ok = $wpdb->insert( $wpdb->prefix . 'frmt_form_entry', array(
				'entry_type'   => 'custom-forms',
				'form_id'      => $fm_form,
				'is_spam'      => 0,
				'status'       => 'active',
				'date_created' => $date,
				'date_updated' => $date,
			) );
			$id = $ok ? $id_of( (int) $wpdb->insert_id ) : 0;
			if ( $id ) {
				$wpdb->insert( $wpdb->prefix . 'frmt_form_entry_meta', array( 'entry_id' => $id, 'meta_key' => 'name-1', 'meta_value' => $name . ' ' . $token, 'date_created' => $date, 'date_updated' => $date ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
			return $id;
		};
		$fm_new = $fm_form ? $fm_add( 'Ada Newer', current_time( 'mysql' ) ) : 0;
		$fm_old = $fm_form ? $fm_add( 'Bea Imported', '2026-01-05 09:00:00' ) : 0;
		$fm_ids = array_filter( array( $fm_new, $fm_old ) );
		$check( 'Forminator fixtures: two entries', $fm_new && $fm_old && $fm_old > $fm_new, "{$fm_new}, {$fm_old}" );
		if ( $fm_new && $fm_old ) {
			$sorts( 'Forminator', '/minn-admin/v1/forminator/entries', $fm_new, $fm_old );
		}
	} finally {
		foreach ( $fm_ids as $id ) {
			if ( $id > 1 ) {
				$wpdb->delete( $wpdb->prefix . 'frmt_form_entry_meta', array( 'entry_id' => $id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'frmt_form_entry', array( 'entry_id' => $id ), array( '%d' ) );
			}
		}
	}
}

if ( ! class_exists( 'FrmField' ) || empty( $surfaces['formidable'] ) ) {
	$skip( 'Formidable (S9): Formidable is not active here' );
} else {
	global $wpdb;
	$fd_ids = array();
	try {
		$fd_form  = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}frm_forms WHERE is_template = 0 AND status = 'published' ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$fd_field = $fd_form ? array_keys( minn_admin_formidable_fields( $fd_form ) ) : array();
		// Formidable stamps created_at with current_time( 'mysql', 1 ): UTC.
		$fd_add = function ( $name, $date ) use ( $wpdb, $fd_form, $fd_field, $token, $id_of ) {
			$ok = $wpdb->insert( $wpdb->prefix . 'frm_items', array(
				'item_key'       => substr( $token . wp_generate_password( 6, false ), 0, 20 ),
				'name'           => $name,
				'form_id'        => $fd_form,
				'post_id'        => 0,
				'user_id'        => 0,
				'parent_item_id' => 0,
				'is_draft'       => 0,
				'created_at'     => $date,
				'updated_at'     => $date,
			) );
			$id = $ok ? $id_of( (int) $wpdb->insert_id ) : 0;
			if ( $id ) {
				$wpdb->insert( $wpdb->prefix . 'frm_item_metas', array( 'item_id' => $id, 'field_id' => (int) $fd_field[0], 'meta_value' => $name . ' ' . $token, 'created_at' => $date ) );
			}
			return $id;
		};
		$fd_new = $fd_field ? $fd_add( 'Ada Newer', current_time( 'mysql', true ) ) : 0;
		$fd_old = $fd_field ? $fd_add( 'Bea Imported', '2026-01-05 14:00:00' ) : 0;
		$fd_ids = array_filter( array( $fd_new, $fd_old ) );
		$check( 'Formidable fixtures: two entries', $fd_new && $fd_old && $fd_old > $fd_new, "{$fd_new}, {$fd_old}" );
		if ( $fd_new && $fd_old ) {
			$sorts( 'Formidable', '/minn-admin/v1/formidable/entries', $fd_new, $fd_old );
		}
	} finally {
		foreach ( $fd_ids as $id ) {
			if ( $id > 1 ) {
				$wpdb->delete( $wpdb->prefix . 'frm_item_metas', array( 'item_id' => $id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'frm_items', array( 'id' => $id ), array( '%d' ) );
			}
		}
	}
}

$summary();
