<?php
/**
 * Regressions for what the v0.44.0 pre-flight fixture sweep proved: older
 * adapter bugs the plugin updates did not cause but the sweep's smoke runs
 * turned up (an AIOSEO image Minn wrote under the wrong type, a CFDB7 delete
 * that reached past its own uploads, a Folders provider that never loaded,
 * a credential the database browser printed). Each section replays the route
 * Minn's client calls and checks the stored result, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive; a few
 * load an inactive plugin for the run only, through a --require file.
 *
 * Run: wp eval-file tests/sweep-v044.test.php --user=admin --path=<site>   (from the site root)
 *
 * The Folders and OttoKit sections need plugins the dev site keeps off; load
 * them for the run with tests/lib/plugin-swap.php (its header has the
 * command), or those sections SKIP. The AIOSEO sections need AIOSEO in place
 * of Yoast: --require=tests/lib/aioseo-swap.php, which puts back what
 * AIOSEO's own load writes.
 *
 * @package minn-admin
 */

// ACPT's WPAttachment.php calls set_time_limit( 5 ) at file scope, so once a
// section autoloads it every later section would have five seconds left.
// Each check and skip lifts the limit again.
$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsweep-v044: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// --- DB browser redacts WP Mail SMTP's one-hour Connect token ----------------
// WP Mail SMTP keeps the token its logged-out connect endpoint is checked
// against in a transient for an hour; it is the endpoint's only credential.
( function () use ( $check, $skip, $call ) {
	if ( ! class_exists( 'Minn_Admin_DB' ) ) {
		$skip( 'database browser not loaded' );
		return;
	}
	global $wpdb;
	$name   = '_transient_wp_mail_smtp_connect_token';
	$secret = 'mnwpmsct' . wp_generate_password( 24, false );
	$was    = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
	$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $secret, 'autoload' => 'off' ) );
	try {
		list( $st, $res ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'fcol' => 'option_name', 'fq' => 'wp_mail_smtp_connect_token' ) );
		$cols = wp_list_pluck( (array) ( $res['columns'] ?? array() ), 'name' );
		$ki   = array_search( 'option_name', $cols, true );
		$vi   = array_search( 'option_value', $cols, true );
		$cell = null;
		$pk   = null;
		foreach ( (array) ( $res['rows'] ?? array() ) as $row ) {
			if ( false !== $ki && $name === ( $row[ $ki ] ?? null ) ) {
				$cell = $row[ $vi ] ?? null;
				$pk   = $row;
			}
		}
		$check( 'DB browser: the WP Mail SMTP connect token renders redacted', 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ), $st . ' ' . ( null === $cell ? 'row missing' : ( is_array( $cell ) ? 'array' : 'RAW ' . strlen( (string) $cell ) . ' bytes' ) ) );
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		list( $st2, $one ) = $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $id ) ) ) );
		$check( 'DB browser: its row detail holds no part of the token', 200 === $st2 && false === strpos( wp_json_encode( $one ), substr( $secret, 0, 12 ) ), $st2 . ( false === strpos( wp_json_encode( $one ), substr( $secret, 0, 12 ) ) ? '' : ' secret present' ) );
		list( $st3, $hit ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50, 'fcol' => 'option_value', 'fq' => substr( $secret, 0, 12 ) ) );
		$check( 'DB browser: a value search on a prefix of the token finds nothing', 200 === $st3 && 0 === (int) ( $hit['total'] ?? -1 ), 'total ' . ( $hit['total'] ?? '?' ) );
	} finally {
		if ( $was ) {
			$wpdb->replace( $wpdb->options, array( 'option_name' => $name, 'option_value' => $was['option_value'], 'autoload' => $was['autoload'] ) );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( $name, 'options' );
	}
} )();

// --- CFDB7 delete removes only the files CFDB7's own delete would ------------
// CFDB7 1.4.2's bulk delete treats a stored value as an upload only under a key
// that ENDS in cfdb7_file, only when it is a plain string that is already a bare
// filename (never trimmed to one), only when it resolves inside cfdb7_uploads,
// and never when it is a .php file. Rows stored before 1.4.2 kept every key a
// visitor posted, so a submission could name index.php (the folder's own
// protective stub) or another visitor's upload. The DELETE is what the entry's
// "Delete entry" action and the bulk Delete both send.
( function () use ( $check, $skip, $call ) {
	global $wpdb;
	if ( ! function_exists( 'cfdb7_before_send_mail' ) ) {
		$skip( 'CFDB7 inactive: delete file rules' );
		return;
	}
	$cfs_table = $wpdb->prefix . 'db7_forms';
	if ( $cfs_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cfs_table ) ) ) {
		$skip( 'CFDB7 table missing: delete file rules' );
		return;
	}
	$cfs_uploads = wp_upload_dir()['basedir'];
	$cfs_dir     = $cfs_uploads . '/cfdb7_uploads/';
	$cfs_made    = false;
	if ( ! is_dir( $cfs_dir ) ) {
		$cfs_made = wp_mkdir_p( $cfs_dir );
	}
	// The folder's protective stub: remember it exactly so a failing run can
	// put it back, and so the check below compares bytes, not just presence.
	$cfs_index      = $cfs_dir . 'index.php';
	$cfs_index_was  = file_exists( $cfs_index ) ? file_get_contents( $cfs_index ) : null; // phpcs:ignore
	$cfs_index_time = file_exists( $cfs_index ) ? filemtime( $cfs_index ) : null;

	$cfs_tag   = 'minn-sw44-' . strtolower( wp_generate_password( 6, false, false ) ) . '-';
	$cfs_files = array(
		'legit'   => $cfs_tag . 'resume.txt',  // a real upload: must go (control)
		'php'     => $cfs_tag . 'probe.php',   // .php under a real suffix key
		'upper'   => $cfs_tag . 'shout.PHP',   // extension case
		'mid'     => $cfs_tag . 'mid.txt',     // marker in the middle of the key
		'caps'    => $cfs_tag . 'caps.txt',    // marker in another case
		'victim'  => $cfs_tag . 'victim.txt',  // named through a path, not a bare name
		'listed'  => $cfs_tag . 'listed.txt',  // named inside an array value
		'fb'      => $cfs_tag . 'fallback.txt', // a real upload on an undecodable row
		'fbphp'   => $cfs_tag . 'fallback.php', // .php on an undecodable row
	);
	foreach ( $cfs_files as $cfs_f ) {
		file_put_contents( $cfs_dir . $cfs_f, 'minn sweep fixture' ); // phpcs:ignore
	}
	// A link inside the folder that resolves outside it.
	$cfs_outside = $cfs_uploads . '/' . $cfs_tag . 'outside.txt';
	file_put_contents( $cfs_outside, 'minn sweep fixture' ); // phpcs:ignore
	$cfs_link     = $cfs_dir . $cfs_tag . 'link.txt';
	$cfs_link_ok  = function_exists( 'symlink' ) && @symlink( $cfs_outside, $cfs_link ); // phpcs:ignore

	$cfs_row = array(
		'cfdb7_status'       => 'unread',
		'your-name'          => 'Minn Sweep Fixture',
		'upload-1cfdb7_file' => $cfs_files['legit'],
		'probecfdb7_file'    => $cfs_files['php'],
		'shoutcfdb7_file'    => $cfs_files['upper'],
		'xcfdb7_filey'       => $cfs_files['mid'],
		'xCFDB7_FILE'        => $cfs_files['caps'],
		'pathcfdb7_file'     => '../cfdb7_uploads/' . $cfs_files['victim'],
		'listcfdb7_file'     => array( $cfs_files['listed'] ),
		'xcfdb7_file'        => 'index.php',
	);
	if ( $cfs_link_ok ) {
		$cfs_row['linkcfdb7_file'] = basename( $cfs_link );
	}
	$wpdb->insert( $cfs_table, array(
		'form_post_id' => 0,
		'form_value'   => serialize( $cfs_row ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		'form_date'    => current_time( 'mysql' ),
	) );
	$cfs_id = (int) $wpdb->insert_id;

	// A row whose blob no longer decodes (a value's length prefix overstates
	// it, the shape the old read/unread surgery left behind). Minn falls back
	// to its scanner there; the same rules must hold on that path.
	$cfs_fb_name = 'Fixture';
	$cfs_fb_blob = 'a:4:{s:12:"cfdb7_status";s:6:"unread";'
		. 's:18:"upload-1cfdb7_file";s:' . strlen( $cfs_files['fb'] ) . ':"' . $cfs_files['fb'] . '";'
		. 's:15:"probecfdb7_file";s:' . strlen( $cfs_files['fbphp'] ) . ':"' . $cfs_files['fbphp'] . '";'
		. 's:9:"your-name";s:' . ( strlen( $cfs_fb_name ) + 2 ) . ':"' . $cfs_fb_name . '";}';
	$wpdb->insert( $cfs_table, array(
		'form_post_id' => 0,
		'form_value'   => $cfs_fb_blob,
		'form_date'    => current_time( 'mysql' ),
	) );
	$cfs_fb_id = (int) $wpdb->insert_id;

	// A NUL byte in a stored name: realpath() throws on one, which must not
	// fail the whole delete.
	$wpdb->insert( $cfs_table, array(
		'form_post_id' => 0,
		'form_value'   => serialize( array( 'cfdb7_status' => 'unread', 'nulcfdb7_file' => $cfs_files['legit'] . "\0.txt" ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		'form_date'    => current_time( 'mysql' ),
	) );
	$cfs_nul_id = (int) $wpdb->insert_id;

	if ( ! $cfs_id || ! $cfs_fb_id || ! $cfs_nul_id ) {
		$check( 'CFDB7 fixture rows insert', false, $wpdb->last_error );
	} else {
		$check( 'CFDB7 fallback fixture really does not decode', ! is_array( Minn_Admin::decode_serialized( $cfs_fb_blob ) ) );

		list( $cfs_st ) = $call( 'DELETE', "/minn-admin/v1/cfdb7/entries/{$cfs_id}" );
		$cfs_gone       = ! $wpdb->get_var( $wpdb->prepare( "SELECT form_id FROM {$cfs_table} WHERE form_id = %d", $cfs_id ) ); // phpcs:ignore
		$check( 'CFDB7 delete answers 200 and removes the row (control)', 200 === $cfs_st && $cfs_gone, "status {$cfs_st}" );
		$check( 'CFDB7 delete removes the real upload (control)', ! file_exists( $cfs_dir . $cfs_files['legit'] ) );
		$check( 'CFDB7 delete leaves a .php file named under a cfdb7_file key', file_exists( $cfs_dir . $cfs_files['php'] ) );
		$check( 'CFDB7 delete leaves a .PHP file (extension case)', file_exists( $cfs_dir . $cfs_files['upper'] ) );
		$check( 'CFDB7 delete leaves a file named under a key with the marker mid-name', file_exists( $cfs_dir . $cfs_files['mid'] ) );
		$check( 'CFDB7 delete leaves a file named under a key with the marker in capitals', file_exists( $cfs_dir . $cfs_files['caps'] ) );
		$check( 'CFDB7 delete does not trim a path value down to a filename', file_exists( $cfs_dir . $cfs_files['victim'] ) );
		$check( 'CFDB7 delete leaves a file named inside an array value', file_exists( $cfs_dir . $cfs_files['listed'] ) );
		if ( $cfs_link_ok ) {
			$check( 'CFDB7 delete leaves a link that resolves outside cfdb7_uploads (and its target)', is_link( $cfs_link ) && file_exists( $cfs_outside ) );
		} else {
			$skip( 'CFDB7 symlink variant: symlink() unavailable' );
		}
		$cfs_index_now = file_exists( $cfs_index ) ? file_get_contents( $cfs_index ) : null; // phpcs:ignore
		$check( 'CFDB7 delete never touches cfdb7_uploads/index.php', $cfs_index_was === $cfs_index_now, null === $cfs_index_now ? 'index.php was deleted' : '' );

		list( $cfs_fb_st ) = $call( 'DELETE', "/minn-admin/v1/cfdb7/entries/{$cfs_fb_id}" );
		$check( 'CFDB7 delete of an undecodable row still removes its real upload (control)', 200 === $cfs_fb_st && ! file_exists( $cfs_dir . $cfs_files['fb'] ), "status {$cfs_fb_st}" );
		$check( 'CFDB7 delete of an undecodable row leaves its .php file', file_exists( $cfs_dir . $cfs_files['fbphp'] ) );

		list( $cfs_nul_st ) = $call( 'DELETE', "/minn-admin/v1/cfdb7/entries/{$cfs_nul_id}" );
		$cfs_nul_gone       = ! $wpdb->get_var( $wpdb->prepare( "SELECT form_id FROM {$cfs_table} WHERE form_id = %d", $cfs_nul_id ) ); // phpcs:ignore
		$check( 'CFDB7 delete of a row naming a NUL-byte value still answers 200 and removes the row', 200 === $cfs_nul_st && $cfs_nul_gone, "status {$cfs_nul_st}" );
	}

	// Cleanup: rows, fixture files, the link, and the stub exactly as it was.
	foreach ( array( $cfs_id, $cfs_fb_id, $cfs_nul_id ) as $cfs_rid ) {
		if ( $cfs_rid ) {
			$wpdb->delete( $cfs_table, array( 'form_id' => $cfs_rid ), array( '%d' ) );
		}
	}
	foreach ( $cfs_files as $cfs_f ) {
		if ( file_exists( $cfs_dir . $cfs_f ) ) {
			unlink( $cfs_dir . $cfs_f ); // phpcs:ignore
		}
	}
	if ( is_link( $cfs_link ) ) {
		unlink( $cfs_link ); // phpcs:ignore
	}
	if ( file_exists( $cfs_outside ) ) {
		unlink( $cfs_outside ); // phpcs:ignore
	}
	if ( null !== $cfs_index_was && ( ! file_exists( $cfs_index ) || file_get_contents( $cfs_index ) !== $cfs_index_was ) ) { // phpcs:ignore
		file_put_contents( $cfs_index, $cfs_index_was ); // phpcs:ignore
		touch( $cfs_index, $cfs_index_time );
		echo "      (restored cfdb7_uploads/index.php)\n";
	}
	if ( $cfs_made ) {
		@rmdir( $cfs_dir ); // phpcs:ignore
	}
} )();

// --- Forminator counts pending entries and keeps payment statuses ------------
// Forminator 1.58 parks an entry awaiting a Stripe checkout as `pending` and
// one whose payment failed as `failed`; its own form counts (count_entries, the
// Forms list and Minn's Manage view) take active + pending. Its payment lookups
// find an entry only while it is still pending, so a Minn spam/not-spam round
// trip that lands it on `active` orphans the payment. Spam is offered on a
// received entry and "Not spam" on a spam one; the routes are what both send.
( function () use ( $check, $skip, $call ) {
	global $wpdb;
	if ( ! class_exists( 'Forminator_API' ) || ! class_exists( 'Forminator_Form_Entry_Model' ) ) {
		$skip( 'Forminator inactive: pending/failed statuses' );
		return;
	}
	$fms_table = $wpdb->prefix . 'frmt_form_entry';
	if ( $fms_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fms_table ) ) ) {
		$skip( 'Forminator table missing: pending/failed statuses' );
		return;
	}
	$fms_forms = Forminator_API::get_forms( null, 1, 1 );
	if ( ! is_array( $fms_forms ) || ! $fms_forms ) {
		$skip( 'Forminator has no form to hold fixture entries' );
		return;
	}
	$fms_form = (int) $fms_forms[0]->id;
	$fms_tag  = 'Minn Sweep ' . strtolower( wp_generate_password( 6, false, false ) );

	$fms_ids = array();
	foreach ( array( 'active', 'pending', 'failed', 'draft', 'spam', 'spamflag' ) as $fms_kind ) {
		$fms_new = Forminator_API::add_form_entry( $fms_form, array(
			array( 'name' => 'name-1', 'value' => $fms_tag . ' ' . $fms_kind ),
		) );
		if ( is_wp_error( $fms_new ) || ! $fms_new ) {
			$check( "Forminator fixture entry ({$fms_kind})", false, is_wp_error( $fms_new ) ? $fms_new->get_error_message() : 'no id' );
			continue;
		}
		$fms_ids[ $fms_kind ] = (int) $fms_new;
	}
	$fms_set   = function ( $kind, $status, $is_spam ) use ( $wpdb, $fms_table, &$fms_ids ) {
		if ( isset( $fms_ids[ $kind ] ) ) {
			$wpdb->update( $fms_table, array( 'status' => $status, 'is_spam' => $is_spam ), array( 'entry_id' => $fms_ids[ $kind ] ), array( '%s', '%d' ), array( '%d' ) );
		}
	};
	$fms_flush = function () use ( $fms_form, &$fms_ids ) {
		Forminator_Form_Entry_Model::delete_form_entry_cache( $fms_form );
		foreach ( $fms_ids as $fms_eid ) {
			wp_cache_delete( $fms_eid, Forminator_Form_Entry_Model::FORM_ENTRY_CACHE_GROUP );
		}
	};
	$fms_row   = function ( $kind ) use ( $wpdb, $fms_table, &$fms_ids ) {
		return isset( $fms_ids[ $kind ] ) ? $wpdb->get_row( $wpdb->prepare( "SELECT status, is_spam FROM {$fms_table} WHERE entry_id = %d", $fms_ids[ $kind ] ) ) : null; // phpcs:ignore
	};
	$fms_desc  = function ( $r ) {
		return $r ? "status={$r->status} is_spam={$r->is_spam}" : 'missing';
	};
	$fms_set( 'pending', 'pending', 0 );
	$fms_set( 'failed', 'failed', 0 );
	$fms_set( 'draft', 'draft', 0 );
	$fms_set( 'spam', 'spam', 1 );    // spam at submit: Forminator writes both
	$fms_flush();

	if ( 6 === count( $fms_ids ) ) {
		// Counts: the received list, the status card and Forminator's own count agree.
		$fms_own = (int) Forminator_Form_Entry_Model::count_entries( $fms_form );
		list( , $fms_list ) = $call( 'GET', '/minn-admin/v1/forminator/entries', null, array( 'form_id' => $fms_form, 'per_page' => 100 ) );
		$check( 'Forminator received list total matches its own count_entries (active + pending)', isset( $fms_list['total'] ) && (int) $fms_list['total'] === $fms_own, 'list ' . ( $fms_list['total'] ?? '?' ) . " vs Forminator {$fms_own}" );
		list( , $fms_manage ) = $call( 'GET', '/minn-admin/v1/forminator/forms', null, array( 'manage' => 1 ) );
		$fms_manage_n = null;
		foreach ( (array) $fms_manage as $fms_m ) {
			if ( (int) $fms_m['id'] === $fms_form ) {
				$fms_manage_n = (int) $fms_m['entries'];
			}
		}
		$check( 'Forminator Manage view count matches the received list', null !== $fms_manage_n && $fms_manage_n === (int) ( $fms_list['total'] ?? -1 ), "manage {$fms_manage_n}" );

		$fms_all_own = 0;
		foreach ( (array) $wpdb->get_col( "SELECT DISTINCT form_id FROM {$fms_table} WHERE entry_type = 'custom-forms'" ) as $fms_fid ) { // phpcs:ignore
			Forminator_Form_Entry_Model::delete_form_entry_cache( (int) $fms_fid );
			$fms_all_own += (int) Forminator_Form_Entry_Model::count_entries( (int) $fms_fid );
		}
		list( , $fms_card ) = $call( 'GET', '/minn-admin/v1/forminator/status' );
		$fms_card_v = isset( $fms_card['rows'][0]['value'] ) ? (string) $fms_card['rows'][0]['value'] : '?';
		$check( 'Forminator status card counts what Forminator counts', number_format_i18n( $fms_all_own ) === $fms_card_v, "card {$fms_card_v} vs Forminator {$fms_all_own}" );

		$fms_items = array();
		foreach ( (array) ( $fms_list['items'] ?? array() ) as $fms_it ) {
			$fms_items[ (int) $fms_it['id'] ] = $fms_it;
		}
		$check( 'Forminator pending entry is listed as pending (so the client offers no spam action)', isset( $fms_items[ $fms_ids['pending'] ] ) && 'pending' === $fms_items[ $fms_ids['pending'] ]['status'], isset( $fms_items[ $fms_ids['pending'] ] ) ? 'status ' . $fms_items[ $fms_ids['pending'] ]['status'] : 'not listed' );
		$check( 'Forminator active entry is listed as received (control)', isset( $fms_items[ $fms_ids['active'] ] ) && 'received' === $fms_items[ $fms_ids['active'] ]['status'] );
		$check( 'Forminator failed and draft entries stay off the received list', ! isset( $fms_items[ $fms_ids['failed'] ] ) && ! isset( $fms_items[ $fms_ids['draft'] ] ) );

		// The reviewer's round trip on a pending payment entry.
		list( $fms_s1 ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids['pending']}/spam" );
		list( $fms_s2 ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids['pending']}/unspam" );
		$fms_r          = $fms_row( 'pending' );
		$check( 'Forminator spam + not-spam leaves a pending payment entry pending', $fms_r && 'pending' === $fms_r->status && 0 === (int) $fms_r->is_spam, $fms_desc( $fms_r ) . " (spam {$fms_s1}, unspam {$fms_s2})" );
		$check( 'Forminator refuses to mark a pending entry as spam', $fms_s1 >= 400 && $fms_s1 < 500, "status {$fms_s1}" );
		$check( 'Forminator refuses "not spam" on an entry that is not spam', $fms_s2 >= 400 && $fms_s2 < 500, "status {$fms_s2}" );
		$fms_found = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$fms_table} WHERE entry_id = %d AND status = %s", $fms_ids['pending'], 'pending' ) ); // phpcs:ignore
		$check( 'Forminator payment lookups still see the entry as pending', 1 === $fms_found );

		foreach ( array( 'failed', 'draft' ) as $fms_kind ) {
			list( $fms_st ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids[ $fms_kind ]}/spam" );
			list( $fms_su ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids[ $fms_kind ]}/unspam" );
			$fms_r          = $fms_row( $fms_kind );
			$check( "Forminator spam/not-spam leave a {$fms_kind} entry as it was", $fms_r && $fms_kind === $fms_r->status && 0 === (int) $fms_r->is_spam && $fms_st >= 400 && $fms_su >= 400, $fms_desc( $fms_r ) . " (spam {$fms_st}, unspam {$fms_su})" );
		}

		// Controls: the real round trip still works.
		list( $fms_a1, $fms_a1d ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids['active']}/spam" );
		$fms_r                    = $fms_row( 'active' );
		$check( 'Forminator marks a received entry as spam (control)', 200 === $fms_a1 && $fms_r && 'spam' === $fms_r->status && 1 === (int) $fms_r->is_spam && 'spam' === ( $fms_a1d['status'] ?? '' ), $fms_desc( $fms_r ) );
		list( $fms_a2 ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids['active']}/spam" );
		$check( 'Forminator refuses to mark a spam entry as spam again', $fms_a2 >= 400 && $fms_a2 < 500, "status {$fms_a2}" );
		// A spam flag on an active row: the shape from before Forminator's
		// status column, still in Minn's Spam filter. Set after the counts
		// (Forminator's count_entries reads status alone and would count it).
		$fms_set( 'spamflag', 'active', 1 );
		$fms_flush();
		list( , $fms_sp ) = $call( 'GET', '/minn-admin/v1/forminator/entries', null, array( 'form_id' => $fms_form, 'status' => 'spam', 'per_page' => 100 ) );
		$fms_spam_ids     = array_map( 'intval', wp_list_pluck( (array) ( $fms_sp['items'] ?? array() ), 'id' ) );
		$check( 'Forminator spam filter lists the spammed entry and both stored spam shapes (control)', in_array( $fms_ids['active'], $fms_spam_ids, true ) && in_array( $fms_ids['spam'], $fms_spam_ids, true ) && in_array( $fms_ids['spamflag'], $fms_spam_ids, true ) );
		list( $fms_a3, $fms_a3d ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids['active']}/unspam" );
		$fms_r                    = $fms_row( 'active' );
		$check( 'Forminator "not spam" returns a spammed entry to active (control)', 200 === $fms_a3 && $fms_r && 'active' === $fms_r->status && 0 === (int) $fms_r->is_spam && 'received' === ( $fms_a3d['status'] ?? '' ), $fms_desc( $fms_r ) );
		foreach ( array( 'spam', 'spamflag' ) as $fms_kind ) {
			list( $fms_u ) = $call( 'POST', "/minn-admin/v1/forminator/entries/{$fms_ids[ $fms_kind ]}/unspam" );
			$fms_r         = $fms_row( $fms_kind );
			$check( "Forminator \"not spam\" on stored spam ({$fms_kind}) lands on active (control)", 200 === $fms_u && $fms_r && 'active' === $fms_r->status && 0 === (int) $fms_r->is_spam, $fms_desc( $fms_r ) );
		}
		list( $fms_nf ) = $call( 'POST', '/minn-admin/v1/forminator/entries/999999999/spam' );
		$check( 'Forminator spam on a missing entry is a 404 (control)', 404 === $fms_nf, "status {$fms_nf}" );
	}

	// Cleanup through Minn's own DELETE (Forminator's complete cleanup), then
	// anything that survived.
	foreach ( $fms_ids as $fms_kind => $fms_eid ) {
		list( $fms_d ) = $call( 'DELETE', "/minn-admin/v1/forminator/entries/{$fms_eid}" );
		if ( 'pending' === $fms_kind ) {
			$fms_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}frmt_form_entry_meta WHERE entry_id = %d", $fms_eid ) ); // phpcs:ignore
			$check( 'Forminator delete removes a pending entry and its meta (control)', 200 === $fms_d && ! $fms_row( $fms_kind ) && 0 === $fms_left, "status {$fms_d}, meta rows {$fms_left}" );
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT entry_id FROM {$fms_table} WHERE entry_id = %d", $fms_eid ) ) ) { // phpcs:ignore
			Forminator_Form_Entry_Model::delete_by_entry( $fms_eid );
		}
	}
	$fms_flush();
} )();

// --- Folders by Premio answers the media-folders contract -------------------
// Folders 3.2.1 and later define FOLDERS_VERSION and never WCP_FOLDER_VERSION,
// so a provider gated on the old constant alone never registered: no folder
// combobox, no move route. FileBird and Real Media Library answer the contract
// before Folders, so this section needs Folders loaded without them.
( function () use ( $check, $skip, $call, $admin ) {
	if ( ! defined( 'FOLDERS_VERSION' ) && ! defined( 'WCP_FOLDER_VERSION' ) ) {
		$skip( 'Folders (Premio): plugin inactive' );
		return;
	}
	if ( ! taxonomy_exists( 'media_folder' ) ) {
		$skip( 'Folders (Premio): media is not enabled in its settings, so media_folder is unregistered' );
		return;
	}
	if ( defined( 'NJFB_VERSION' ) || function_exists( 'wp_rml_objects' ) ) {
		$skip( 'Folders (Premio): FileBird or Real Media Library answers the contract first' );
		return;
	}
	$fop_p = minn_admin_media_folders_provider();
	$check( 'Folders: the provider registers on this Folders build', $fop_p && 'Folders' === $fop_p['name'], ( $fop_p ? $fop_p['name'] : 'null' ) . ' (FOLDERS_VERSION ' . ( defined( 'FOLDERS_VERSION' ) ? FOLDERS_VERSION : 'undefined' ) . ')' );
	$fop_boot = minn_admin_media_folders_boot();
	$check( 'Folders: the boot payload names it and offers Move', is_array( $fop_boot ) && 'Folders' === $fop_boot['name'] && ! empty( $fop_boot['move'] ), wp_json_encode( $fop_boot ) );
	list( $fop_st, $fop_list ) = $call( 'GET', '/minn-admin/v1/media/folders' );
	// HappyFiles answers after Folders, so on a site running both the list
	// must still come from Folders.
	$check( 'Folders: GET media/folders answers from Folders', 200 === $fop_st && 'Folders' === ( $fop_list['name'] ?? '' ), $fop_st . ' ' . ( $fop_list['name'] ?? ( $fop_list['code'] ?? '' ) ) );
	if ( ! $fop_p || 'Folders' !== $fop_p['name'] || 200 !== $fop_st ) {
		return;
	}

	$fop_terms = array();
	$fop_att   = 0;
	$fop_tag   = 'minn-sweep-premio-' . wp_generate_password( 6, false );
	try {
		foreach ( array( 'a', 'b', 'c' ) as $fop_k ) {
			$fop_t = wp_insert_term( $fop_tag . '-' . $fop_k, 'media_folder' );
			$fop_terms[ $fop_k ] = is_wp_error( $fop_t ) ? 0 : (int) $fop_t['term_id'];
		}
		$fop_att = (int) wp_insert_attachment( array(
			'post_title'     => $fop_tag,
			'post_mime_type' => 'image/png',
			'post_status'    => 'inherit',
			'post_author'    => $admin,
		) );
		if ( ! $fop_att || in_array( 0, $fop_terms, true ) ) {
			$check( 'Folders: fixtures created', false, wp_json_encode( array( 'att' => $fop_att, 'terms' => $fop_terms ) ) );
			return;
		}
		$fop_has = function () use ( &$fop_att ) {
			$ids = wp_get_object_terms( $fop_att, 'media_folder', array( 'fields' => 'ids' ) );
			$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
			sort( $ids );
			return $ids;
		};
		$fop_sorted = function ( $ids ) {
			sort( $ids );
			return $ids;
		};

		// Into folder A through the route the Move control calls.
		list( $fop_st, $fop_res ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => $fop_terms['a'], 'ids' => array( $fop_att ) ) );
		$check( 'Folders: a move files the attachment in the target folder', 200 === $fop_st && ! empty( $fop_res['ok'] ) && array( $fop_terms['a'] ) === $fop_has(), $fop_st . ' terms ' . wp_json_encode( $fop_has() ) );
		list( $fop_st, $fop_ids ) = $call( 'GET', '/minn-admin/v1/media/folders/' . $fop_terms['a'] . '/ids' );
		$check( 'Folders: the folder\'s ids include the moved attachment', 200 === $fop_st && in_array( $fop_att, (array) ( $fop_ids['ids'] ?? array() ), true ), $fop_st . ' ' . wp_json_encode( $fop_ids['ids'] ?? null ) );
		list( $fop_st, $fop_list ) = $call( 'GET', '/minn-admin/v1/media/folders' );
		$fop_row = null;
		foreach ( (array) ( $fop_list['folders'] ?? array() ) as $fop_f ) {
			if ( (int) $fop_f['id'] === $fop_terms['a'] ) {
				$fop_row = $fop_f;
			}
		}
		$check( 'Folders: the folder list carries the folder and its count', $fop_row && 1 === (int) $fop_row['count'], wp_json_encode( $fop_row ) );

		// Their move (FoldersItems::save_folder_items) removes only the folder
		// being viewed and ADDS the target: an item filed in A and B, moved
		// from A into C, keeps B.
		wp_set_object_terms( $fop_att, array( $fop_terms['a'], $fop_terms['b'] ), 'media_folder', false );
		list( $fop_st ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => $fop_terms['c'], 'ids' => array( $fop_att ), 'from' => $fop_terms['a'] ) );
		$check( 'Folders: moving out of A into C keeps the attachment\'s other folder (their handler\'s semantics)', 200 === $fop_st && $fop_sorted( array( $fop_terms['b'], $fop_terms['c'] ) ) === $fop_has(), $fop_st . ' terms ' . wp_json_encode( $fop_has() ) );

		// Folder 0 is their bulk "Unassign" (folder -1): every folder cleared.
		list( $fop_st ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => 0, 'ids' => array( $fop_att ) ) );
		$check( 'Folders: moving to 0 clears every folder', 200 === $fop_st && array() === $fop_has(), $fop_st . ' terms ' . wp_json_encode( $fop_has() ) );
		list( $fop_st, $fop_ids ) = $call( 'GET', '/minn-admin/v1/media/folders/0/ids' );
		$check( 'Folders: the cleared attachment lists under Unassigned', 200 === $fop_st && in_array( $fop_att, (array) ( $fop_ids['ids'] ?? array() ), true ), (string) $fop_st );

		// Controls: a folder that does not exist is refused, and the move stays
		// bounded by edit_post for a role below the attachment's owner.
		list( $fop_st ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => 999999999, 'ids' => array( $fop_att ) ) );
		$check( 'Folders: a move into a missing folder is refused and changes nothing', 404 === $fop_st && array() === $fop_has(), $fop_st . ' terms ' . wp_json_encode( $fop_has() ) );
		list( $fop_st ) = $call( 'GET', '/minn-admin/v1/media/folders/999999999/ids' );
		$check( 'Folders: the ids of a missing folder answer 404', 404 === $fop_st, (string) $fop_st );
		$fop_author = get_users( array( 'role' => 'author', 'number' => 1, 'fields' => 'ID' ) );
		if ( $fop_author ) {
			wp_set_current_user( (int) $fop_author[0] );
			try {
				list( $fop_st ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => $fop_terms['a'], 'ids' => array( $fop_att ) ) );
			} finally {
				wp_set_current_user( $admin );
			}
			$check( 'Folders: an Author cannot file an administrator\'s attachment', 403 === $fop_st && array() === $fop_has(), $fop_st . ' terms ' . wp_json_encode( $fop_has() ) );
		} else {
			$skip( 'Folders: no Author account for the edit_post control' );
		}
	} finally {
		wp_set_current_user( $admin );
		if ( $fop_att ) {
			wp_delete_attachment( $fop_att, true );
		}
		foreach ( $fop_terms as $fop_tid ) {
			if ( $fop_tid ) {
				wp_delete_term( $fop_tid, 'media_folder' );
			}
		}
		$fop_left = get_terms( array( 'taxonomy' => 'media_folder', 'hide_empty' => false, 'search' => $fop_tag, 'fields' => 'ids' ) );
		$check( 'Folders: fixtures removed', ! get_post( $fop_att ) && ( is_wp_error( $fop_left ) || ! $fop_left ), 'terms left ' . wp_json_encode( $fop_left ) );
	}
} )();

// --- OttoKit's Account row reads the connection SureTriggers stores ---------
// SureTriggers keeps its token (encrypted) and the connected email inside the
// suretrigger_options array; it never wrote the suretriggers_secret_key or
// suretriggers_connected_email options, so every site read "Not connected".
// The token must never reach a response.
( function () use ( $check, $skip, $call, $admin ) {
	if ( ! function_exists( 'minn_admin_ottokit_active' ) || ! minn_admin_ottokit_active() ) {
		$skip( 'OttoKit: plugin inactive' );
		return;
	}
	if ( ! class_exists( '\SureTriggers\Models\SaasApiToken' ) || ! class_exists( '\SureTriggers\Controllers\OptionController' ) ) {
		$skip( 'OttoKit: this build has no SaasApiToken / OptionController' );
		return;
	}
	global $wpdb;
	$otk_names  = array( 'suretrigger_options', 'suretriggers_verify_connection' );
	$otk_was    = array();
	foreach ( $otk_names as $otk_n ) {
		$otk_was[ $otk_n ] = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $otk_n ), ARRAY_A );
	}
	$otk_static = \SureTriggers\Controllers\OptionController::$options;
	$otk_secret = 'mnottk' . wp_generate_password( 32, false );
	$otk_email  = 'minn-ottokit-fixture@example.com';
	$otk_seen   = array();
	// A connection as their own connect handler (AuthController::save_connection)
	// stores it: the token through SaasApiToken, the email beside it.
	$otk_put = function ( $token, $email, $verify ) {
		\SureTriggers\Controllers\OptionController::$options = array();
		delete_option( 'suretrigger_options' );
		if ( null !== $token ) {
			\SureTriggers\Models\SaasApiToken::save( $token );
		}
		if ( null !== $email ) {
			\SureTriggers\Controllers\OptionController::set_option( 'connected_email_key', $email );
		}
		if ( null === $verify ) {
			delete_option( 'suretriggers_verify_connection' );
		} else {
			update_option( 'suretriggers_verify_connection', $verify );
		}
	};
	$otk_account = function () use ( $call, &$otk_seen ) {
		list( $st, $res ) = $call( 'GET', '/minn-admin/v1/ottokit/status' );
		$otk_seen[]       = wp_json_encode( $res );
		$row              = (array) ( $res['rows'][0] ?? array() );
		return array( $st, (string) ( $row['value'] ?? '' ), (string) ( $row['hint'] ?? '' ) );
	};
	try {
		// Control first: no connection at all.
		$otk_put( null, null, null );
		list( $otk_st, $otk_none_value, $otk_none_hint ) = $otk_account();
		$check( 'OttoKit: a site never connected reads not connected', 200 === $otk_st && $otk_email !== $otk_none_value && '' !== $otk_none_hint, "$otk_st {$otk_none_value} / {$otk_none_hint}" );

		$otk_put( $otk_secret, $otk_email, 'suretriggers_connection_successful' );
		$otk_cipher = (string) ( get_option( 'suretrigger_options' )['secret_key'] ?? '' );
		$otk_before = md5( (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'suretrigger_options'" ) );
		list( $otk_st, $otk_v, $otk_h ) = $otk_account();
		$check( 'OttoKit: a verified connection shows the connected account', 200 === $otk_st && $otk_email === $otk_v && '' === $otk_h, "$otk_st {$otk_v} / {$otk_h}" );
		$otk_after = md5( (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'suretrigger_options'" ) );
		$check( 'OttoKit: reading the status writes nothing back to their options', $otk_before === $otk_after );

		$otk_put( $otk_secret, $otk_email, null );
		list( $otk_st, $otk_v ) = $otk_account();
		$check( 'OttoKit: a connection their verifier has not checked yet still reads connected', $otk_email === $otk_v, $otk_v );

		$otk_put( $otk_secret, '', 'suretriggers_connection_successful' );
		list( $otk_st, $otk_v ) = $otk_account();
		$check( 'OttoKit: a connection with no stored email reads connected, not the empty state', '' !== $otk_v && $otk_none_value !== $otk_v, $otk_v );

		$otk_put( $otk_secret, $otk_email, 'suretriggers_connection_error' );
		list( $otk_st, $otk_v, $otk_err_hint ) = $otk_account();
		$check( 'OttoKit: an error from their verifier is reported, not shown as connected', $otk_email !== $otk_v && '' !== $otk_err_hint && $otk_none_hint !== $otk_err_hint, "{$otk_v} / {$otk_err_hint}" );

		$otk_put( $otk_secret, $otk_email, 'suretriggers_connection_wp_error' );
		list( $otk_st, $otk_v, $otk_h ) = $otk_account();
		$check( 'OttoKit: their unreachable-service status (connection_wp_error) is reported too', $otk_email !== $otk_v && '' !== $otk_h && $otk_none_hint !== $otk_h, "{$otk_v} / {$otk_h}" );

		// A refused connect stores their 'connection-denied' marker in the token slot.
		$otk_put( 'connection-denied', '', 'suretriggers_connection_successful' );
		list( $otk_st, $otk_v, $otk_h ) = $otk_account();
		$check( 'OttoKit: a refused connect (their connection-denied marker) reads not connected', $otk_none_value === $otk_v && $otk_none_hint === $otk_h, "{$otk_v} / {$otk_h}" );

		// The secret, in either form, never leaves through any OttoKit route.
		$otk_put( $otk_secret, $otk_email, 'suretriggers_connection_successful' );
		list( , $otk_list ) = $call( 'GET', '/minn-admin/v1/ottokit/requests' );
		$otk_seen[]         = wp_json_encode( $otk_list );
		$otk_first          = (int) ( $otk_list['items'][0]['id'] ?? 0 );
		if ( $otk_first ) {
			list( , $otk_view ) = $call( 'GET', '/minn-admin/v1/ottokit/requests/' . $otk_first . '/view' );
			$otk_seen[]         = wp_json_encode( $otk_view );
		}
		$otk_all = implode( "\n", $otk_seen );
		$check( 'OttoKit: the token appears in no response, plain or encrypted', false === strpos( $otk_all, $otk_secret ) && ( '' === $otk_cipher || false === strpos( $otk_all, $otk_cipher ) ) && false === strpos( $otk_all, substr( $otk_secret, 0, 12 ) ), count( $otk_seen ) . ' responses scanned' );

		// Control: the status stays an administrator's (their menu's manage_options).
		$otk_editor = get_users( array( 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ) );
		if ( $otk_editor ) {
			wp_set_current_user( (int) $otk_editor[0] );
			try {
				list( $otk_st ) = $call( 'GET', '/minn-admin/v1/ottokit/status' );
			} finally {
				wp_set_current_user( $admin );
			}
			$check( 'OttoKit: an Editor still cannot read the status', 403 === $otk_st, (string) $otk_st );
		} else {
			$skip( 'OttoKit: no Editor account for the capability control' );
		}
	} finally {
		wp_set_current_user( $admin );
		foreach ( $otk_was as $otk_n => $otk_row ) {
			if ( $otk_row ) {
				$wpdb->replace( $wpdb->options, array( 'option_name' => $otk_n, 'option_value' => $otk_row['option_value'], 'autoload' => $otk_row['autoload'] ) );
			} else {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $otk_n ) );
			}
			wp_cache_delete( $otk_n, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		\SureTriggers\Controllers\OptionController::$options = $otk_static;
		$otk_ok = true;
		foreach ( $otk_was as $otk_n => $otk_row ) {
			$otk_now = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $otk_n ) );
			$otk_ok  = $otk_ok && ( $otk_row ? $otk_row['option_value'] === $otk_now : null === $otk_now );
		}
		$check( 'OttoKit: their connection options restored exactly', $otk_ok );
	}
} )();

// --- OttoKit's list descriptor passes Minn's own validator -----------------
// The Code column carried `'num' => true`, which is not a column key (the
// right-aligned numeric cell is `'format' => 'num'`), so the Integrations card
// flagged Minn's own adapter.
( function () use ( $check, $skip ) {
	if ( ! function_exists( 'minn_admin_ottokit_active' ) || ! minn_admin_ottokit_active() || ! minn_admin_ottokit_has_table() ) {
		$skip( 'OttoKit descriptor: plugin inactive or its request log table is missing' );
		return;
	}
	$otd_row = null;
	foreach ( (array) ( Minn_Admin_Surfaces::integrations()['surfaces'] ?? array() ) as $otd_s ) {
		if ( 'ottokit' === $otd_s['id'] ) {
			$otd_row = $otd_s;
		}
	}
	$check( 'OttoKit descriptor: the Integrations card reports no problems', $otd_row && ! $otd_row['problems'], $otd_row ? wp_json_encode( $otd_row['problems'] ) : 'surface missing' );
	$otd_cols = wp_list_pluck( (array) ( Minn_Admin_Surfaces::all()['ottokit']['collection']['columns'] ?? array() ), 'format', 'key' );
	$check( 'OttoKit descriptor: the response code renders as a numeric cell', 'num' === ( $otd_cols['response_code'] ?? '' ), wp_json_encode( $otd_cols ) );
} )();

// --- SEO sections: shared fixtures (the AIOSEO ones need AIOSEO loaded for the run:
// --require=tests/lib/aioseo-swap.php; the every-provider ones run under whichever is active)

// Shared by the SEO sections below: a throwaway attachment (a real 1x1 PNG, or
// a text file) and the panel's seeding of what it sends back untouched.
$aio_fixture = function ( $kind ) {
	$name  = 'minn-sweep-v044-seo-' . wp_generate_password( 6, false, false ) . ( 'png' === $kind ? '.png' : '.txt' );
	$bytes = 'png' === $kind
		? base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' )
		: "Minn sweep v044 probe\n";
	$up = wp_upload_bits( $name, null, $bytes );
	if ( ! empty( $up['error'] ) ) {
		return null;
	}
	$id = wp_insert_attachment( array(
		'post_title'     => $name,
		'post_mime_type' => 'png' === $kind ? 'image/png' : 'text/plain',
		'post_status'    => 'inherit',
	), $up['file'] );
	return $id ? array( 'id' => (int) $id, 'url' => (string) wp_get_attachment_url( $id ) ) : null;
};
// The client seeds the panel from the read and turns a false on any field
// that is not an ACF true_false into null; an untouched save sends that back.
$aio_seed = function ( $post_id ) use ( $call ) {
	list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $post_id, null, array( 'context' => 'edit' ) );
	$vals           = (array) ( $read['minn_seo'] ?? array() );
	foreach ( $vals as $k => $v ) {
		if ( false === $v ) {
			$vals[ $k ] = null;
		}
	}
	return $vals;
};
$aio_active = function () {
	return function_exists( 'minn_admin_seo_plugin' ) ? (string) ( minn_admin_seo_plugin()['name'] ?? '' ) : '';
};

// --- AIOSEO renders the social and X images Minn sets -----------------------
// In AIOSEO 'custom' is "Image from Custom Field" and 'custom_image' is the
// uploaded image whose URL sits in og_image_custom_url. Minn wrote 'custom'
// with the URL, so AIOSEO looked for a custom field, found none, and the
// og:image the editor picked never appeared on the page.
( function () use ( $check, $skip, $call, $aio_fixture, $aio_seed, $aio_active ) {
	if ( 'AIOSEO' !== $aio_active() ) {
		$skip( 'AIOSEO is not the active SEO provider (run with the AIOSEO swap)' );
		return;
	}
	$model = '\AIOSEO\Plugin\Common\Models\Post';
	$img   = $aio_fixture( 'png' );
	if ( ! $img ) {
		$skip( 'AIOSEO: no image fixture (uploads not writable)' );
		return;
	}
	$pid   = wp_insert_post( array( 'post_title' => 'Minn v044 aioseo image probe', 'post_status' => 'draft' ) );
	try {
		$send = array(
			'social_image'         => $img,
			'twitter_use_facebook' => false,
			'twitter_image'        => $img,
		);
		list( $st ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $send ) );
		$row        = $model::getPost( $pid );
		$check( 'AIOSEO: the social and X images save', 200 === $st, 'status ' . $st );
		$check( 'AIOSEO: the social image is stored as an uploaded image', 'custom_image' === $row->og_image_type && $img['url'] === $row->og_image_custom_url, $row->og_image_type . ' ' . $row->og_image_custom_url );
		$check( 'AIOSEO: the X image is stored as an uploaded image', 'custom_image' === $row->twitter_image_type && $img['url'] === $row->twitter_image_custom_url, $row->twitter_image_type . ' ' . $row->twitter_image_custom_url );
		aioseo()->meta->metaData->bustPostCache( $pid );
		$og = aioseo()->social->facebook->getImage( $pid );
		$og = is_array( $og ) ? (string) $og[0] : (string) $og;
		$check( 'AIOSEO: its og:image is the image Minn set', $img['url'] === $og, $og );
		aioseo()->meta->metaData->bustPostCache( $pid );
		$tw = aioseo()->social->twitter->getImage( $pid );
		$tw = is_array( $tw ) ? (string) $tw[0] : (string) $tw;
		$check( 'AIOSEO: its twitter:image is the image Minn set', $img['url'] === $tw, $tw );
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
		$check( 'AIOSEO: Minn reads both images back', (int) ( $read['minn_seo']['social_image']['id'] ?? 0 ) === $img['id'] && (int) ( $read['minn_seo']['twitter_image']['id'] ?? 0 ) === $img['id'], wp_json_encode( array( $read['minn_seo']['social_image'] ?? null, $read['minn_seo']['twitter_image'] ?? null ) ) );

		// An earlier Minn save: 'custom' plus the URL, no custom field named.
		$row                 = $model::getPost( $pid );
		$row->og_image_type  = 'custom';
		$row->save();
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
		$check( 'AIOSEO: an image an earlier Minn build saved still shows in the panel', (int) ( $read['minn_seo']['social_image']['id'] ?? 0 ) === $img['id'], wp_json_encode( $read['minn_seo']['social_image'] ?? null ) );
		$vals                = $aio_seed( $pid );
		$vals['description'] = 'Minn v044 probe description';
		$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $vals ) );
		$row = $model::getPost( $pid );
		$check( 'AIOSEO: an untouched save leaves that earlier image as stored', 'custom' === $row->og_image_type && $img['url'] === $row->og_image_custom_url, $row->og_image_type . ' ' . $row->og_image_custom_url );

		// Control: AIOSEO's own "Image from Custom Field" with a field named is
		// not an uploaded image, even with a stale upload URL in the row.
		$row                         = $model::getPost( $pid );
		$row->og_image_custom_fields = 'minn_probe_image_field';
		$row->save();
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
		$check( 'AIOSEO: its "Image from Custom Field" source does not read as an uploaded image (control)', null === ( $read['minn_seo']['social_image'] ?? null ), wp_json_encode( $read['minn_seo']['social_image'] ?? null ) );
	} finally {
		wp_delete_post( $pid, true );
		if ( $img ) {
			wp_delete_attachment( $img['id'], true );
		}
	}
} )();

// --- AIOSEO: editing one field keeps the image sources AIOSEO set ----------
// Minn folds every image source it does not model (featured, attached, an
// uploaded URL outside the media library) to an empty image, and an empty
// image sent back unchanged was written as "clear": editing only the
// description reset the og:image to the site default and wiped the URL.
( function () use ( $check, $skip, $call, $aio_fixture, $aio_seed, $aio_active ) {
	if ( 'AIOSEO' !== $aio_active() ) {
		$skip( 'AIOSEO is not the active SEO provider (run with the AIOSEO swap)' );
		return;
	}
	$model = '\AIOSEO\Plugin\Common\Models\Post';
	$img   = $aio_fixture( 'png' );
	if ( ! $img ) {
		$skip( 'AIOSEO: no image fixture (uploads not writable)' );
		return;
	}
	$a     = wp_insert_post( array( 'post_title' => 'Minn v044 aioseo sources probe', 'post_status' => 'draft' ) );
	$b     = wp_insert_post( array( 'post_title' => 'Minn v044 aioseo external image probe', 'post_status' => 'draft' ) );
	$ext   = 'https://example.com/minn-v044-probe.png';
	try {
		$row                     = $model::getPost( $a );
		$row->og_image_type      = 'featured';
		$row->twitter_use_og     = false;
		$row->twitter_image_type = 'attach';
		$row->save();
		$raw_row = function ( $post_id ) {
			global $wpdb;
			$r = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post_id ), ARRAY_A );
			unset( $r['updated'] );
			return $r;
		};
		$before              = $raw_row( $a );
		$vals                = $aio_seed( $a );
		$vals['description'] = 'Minn v044 probe description';
		list( $st ) = $call( 'POST', '/wp/v2/posts/' . $a, array( 'minn_seo' => $vals ) );
		$row = $model::getPost( $a );
		$check( 'AIOSEO: the edited description saves', 200 === $st && 'Minn v044 probe description' === $row->description, 'status ' . $st );
		$check( 'AIOSEO: an untouched "featured image" social source stays', 'featured' === $row->og_image_type, (string) $row->og_image_type );
		$check( 'AIOSEO: an untouched "attached image" X source stays', 'attach' === $row->twitter_image_type, (string) $row->twitter_image_type );
		$after   = $raw_row( $a );
		$changed = array_keys( array_diff_assoc( array_map( 'strval', $after ), array_map( 'strval', $before ) ) + array_diff_key( $before, $after ) );
		$check( 'AIOSEO: an untouched save changes the description column and nothing else', array( 'description' ) === $changed, wp_json_encode( $changed ) );
		// Every empty shape the image can arrive in, over that empty read.
		$empties = array( '', false, 0, array( 'id' => 0, 'url' => '' ) );
		foreach ( $empties as $empty ) {
			$call( 'POST', '/wp/v2/posts/' . $a, array( 'minn_seo' => array( 'social_image' => $empty, 'twitter_image' => $empty ) ) );
		}
		$row = $model::getPost( $a );
		$check( 'AIOSEO: no empty image shape resets a source it does not show', 'featured' === $row->og_image_type && 'attach' === $row->twitter_image_type, $row->og_image_type . ' ' . $row->twitter_image_type );

		$row                           = $model::getPost( $b );
		$row->og_image_type            = 'custom_image';
		$row->og_image_custom_url      = $ext;
		$row->twitter_use_og           = false;
		$row->twitter_image_type       = 'custom_image';
		$row->twitter_image_custom_url = $ext;
		$row->save();
		$vals                = $aio_seed( $b );
		$vals['description'] = 'Minn v044 probe description';
		$call( 'POST', '/wp/v2/posts/' . $b, array( 'minn_seo' => $vals ) );
		$row = $model::getPost( $b );
		$check( 'AIOSEO: an untouched uploaded image from outside the media library stays', 'custom_image' === $row->og_image_type && $ext === $row->og_image_custom_url, $row->og_image_type . ' ' . $row->og_image_custom_url );
		$check( 'AIOSEO: the same for the X image', 'custom_image' === $row->twitter_image_type && $ext === $row->twitter_image_custom_url, $row->twitter_image_type . ' ' . $row->twitter_image_custom_url );

		// Control: an image the panel shows can still be cleared.
		$call( 'POST', '/wp/v2/posts/' . $a, array( 'minn_seo' => array( 'social_image' => $img ) ) );
		$vals                 = $aio_seed( $a );
		$vals['social_image'] = null;
		$call( 'POST', '/wp/v2/posts/' . $a, array( 'minn_seo' => $vals ) );
		$row = $model::getPost( $a );
		$check( 'AIOSEO: clearing a shown image still clears it (control)', 'default' === $row->og_image_type && empty( $row->og_image_custom_url ), $row->og_image_type . ' ' . $row->og_image_custom_url );
	} finally {
		wp_delete_post( $a, true );
		wp_delete_post( $b, true );
		if ( $img ) {
			wp_delete_attachment( $img['id'], true );
		}
	}
} )();

// --- AIOSEO: the focus keyword Minn sets is the one AIOSEO's editor shows ---
// AIOSEO 5 keeps the keyword in its own focus_keyword column and its editor
// reads that column first; the old keyphrases blob is copied in only while the
// column is empty. Minn wrote the blob alone, so after AIOSEO had filled the
// column once, every keyword Minn set (or cleared) was invisible to it.
( function () use ( $check, $skip, $call, $aio_seed, $aio_active ) {
	if ( 'AIOSEO' !== $aio_active() ) {
		$skip( 'AIOSEO is not the active SEO provider (run with the AIOSEO swap)' );
		return;
	}
	$model = '\AIOSEO\Plugin\Common\Models\Post';
	if ( ! method_exists( $model, 'getKeywordColumnsWithLegacyFallback' ) || ! method_exists( $model, 'getKeyphrasesFromKeywordColumns' ) ) {
		$skip( 'AIOSEO before 5.0.0.1 keeps no focus_keyword column' );
		return;
	}
	$shows = function ( $post_id ) use ( $model ) {
		return (string) $model::getKeywordColumnsWithLegacyFallback( $model::getPost( $post_id ) )['focus_keyword'];
	};
	$pid = wp_insert_post( array( 'post_title' => 'Minn v044 aioseo keyword probe', 'post_status' => 'draft' ) );
	$col = wp_insert_post( array( 'post_title' => 'Minn v044 aioseo column-only probe', 'post_status' => 'draft' ) );
	try {
		// What AIOSEO's own editor saves: the column and the blob in step.
		$extra                    = array( array( 'word' => 'minn extra', 'score' => 0 ) );
		$row                      = $model::getPost( $pid );
		$row->focus_keyword       = 'gamma';
		$row->additional_keywords = wp_json_encode( $extra );
		$row->keyphrases          = wp_json_encode( $model::getKeyphrasesFromKeywordColumns( 'gamma', $extra, null ) );
		$row->save();
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
		$check( 'AIOSEO: Minn reads the keyword AIOSEO set', 'gamma' === ( $read['minn_seo']['focus_keyword'] ?? null ), wp_json_encode( $read['minn_seo']['focus_keyword'] ?? null ) );

		$vals                  = $aio_seed( $pid );
		$vals['focus_keyword'] = 'delta';
		$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $vals ) );
		$row     = $model::getPost( $pid );
		$phrases = json_decode( (string) wp_json_encode( $row->keyphrases ), true );
		$check( 'AIOSEO: a keyword Minn sets is in the focus_keyword column', 'delta' === $row->focus_keyword, wp_json_encode( $row->focus_keyword ) );
		$check( 'AIOSEO: ...and in the keyphrases blob', 'delta' === ( $phrases['focus']['keyphrase'] ?? null ), wp_json_encode( $phrases['focus'] ?? null ) );
		$check( 'AIOSEO: its editor shows the keyword Minn set', 'delta' === $shows( $pid ), $shows( $pid ) );
		$check( 'AIOSEO: the additional keyphrases stay as stored', 'minn extra' === ( $phrases['additional'][0]['keyphrase'] ?? null ) && 'minn extra' === ( json_decode( (string) wp_json_encode( $row->additional_keywords ), true )[0]['word'] ?? null ), wp_json_encode( array( $phrases['additional'] ?? null, $row->additional_keywords ) ) );

		$vals                  = $aio_seed( $pid );
		$vals['focus_keyword'] = '';
		$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $vals ) );
		$row = $model::getPost( $pid );
		$check( 'AIOSEO: a keyword Minn clears is gone from the column', empty( $row->focus_keyword ), wp_json_encode( $row->focus_keyword ) );
		$check( 'AIOSEO: its editor shows no keyword after Minn clears it', '' === $shows( $pid ), $shows( $pid ) );
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
		$check( 'AIOSEO: Minn reads the cleared keyword as empty', '' === ( $read['minn_seo']['focus_keyword'] ?? null ), wp_json_encode( $read['minn_seo']['focus_keyword'] ?? null ) );

		// A row whose keyword lives only in the column.
		$row                = $model::getPost( $col );
		$row->focus_keyword = 'zeta';
		$row->keyphrases    = null;
		$row->save();
		list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $col, null, array( 'context' => 'edit' ) );
		$check( 'AIOSEO: Minn reads a keyword that lives only in the column', 'zeta' === ( $read['minn_seo']['focus_keyword'] ?? null ), wp_json_encode( $read['minn_seo']['focus_keyword'] ?? null ) );
		$vals                = $aio_seed( $col );
		$vals['description'] = 'Minn v044 probe description';
		$call( 'POST', '/wp/v2/posts/' . $col, array( 'minn_seo' => $vals ) );
		$check( 'AIOSEO: an untouched save keeps that keyword (control)', 'zeta' === $shows( $col ), $shows( $col ) );
	} finally {
		wp_delete_post( $pid, true );
		wp_delete_post( $col, true );
	}
} )();

// --- SEO image fields take only images, and clear only what is shown ---------
// Every provider. The field is a social image, and the vendors (AIOSEO 5.0.3's
// own seed-image check among them) answer an attachment that is not an image
// with a refusal. The untouched and cleared rounds are the controls for the
// unchanged-image rule above.
( function () use ( $check, $skip, $call, $aio_fixture, $aio_seed, $aio_active ) {
	$name = $aio_active();
	if ( '' === $name ) {
		$skip( 'no SEO provider active' );
		return;
	}
	$pid = wp_insert_post( array( 'post_title' => 'Minn v044 seo image-type probe', 'post_status' => 'draft' ) );
	$img = $aio_fixture( 'png' );
	$txt = $aio_fixture( 'txt' );
	try {
		$map = minn_admin_seo_field_map( minn_admin_seo_plugin(), $pid );
		if ( ! isset( $map['social_image'] ) || ! $img || ! $txt ) {
			$skip( "SEO ({$name}): no social image field here, or no fixture" );
			return;
		}
		$image_of = function () use ( $call, $pid ) {
			list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
			return $read['minn_seo']['social_image'] ?? null;
		};
		list( $st ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => array( 'social_image' => $txt ) ) );
		$check( "SEO ({$name}): a text file is refused as the social image", $st >= 400 && null === $image_of(), 'status ' . $st . ' ' . wp_json_encode( $image_of() ) );
		if ( isset( $map['twitter_image'] ) ) {
			list( $st ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => array( 'twitter_use_facebook' => false, 'twitter_image' => $txt ) ) );
			list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
			$check( "SEO ({$name}): a text file is refused as the X image", $st >= 400 && null === ( $read['minn_seo']['twitter_image'] ?? null ), 'status ' . $st );
		}
		list( $st ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => array( 'social_image' => $img ) ) );
		$check( "SEO ({$name}): an image still saves (control)", 200 === $st && (int) ( $image_of()['id'] ?? 0 ) === $img['id'], 'status ' . $st . ' ' . wp_json_encode( $image_of() ) );
		$vals                = $aio_seed( $pid );
		$vals['description'] = 'Minn v044 probe description';
		$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $vals ) );
		$check( "SEO ({$name}): an untouched image survives an edit to another field", (int) ( $image_of()['id'] ?? 0 ) === $img['id'], wp_json_encode( $image_of() ) );
		$vals                 = $aio_seed( $pid );
		$vals['social_image'] = null;
		$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $vals ) );
		$check( "SEO ({$name}): clearing the image clears it (control)", null === $image_of(), wp_json_encode( $image_of() ) );
	} finally {
		wp_delete_post( $pid, true );
		foreach ( array( $img, $txt ) as $att ) {
			if ( $att ) {
				wp_delete_attachment( $att['id'], true );
			}
		}
	}
} )();

// --- SEO text fields ignore a value that is not text ------------------------
// Every provider. An array or object for a text field was turned into '' and
// cleared what was stored. The panel never sends one, so it is nothing to
// write. (The REST schema already refuses one for the three keys it declares,
// title, description and focus keyword, so this uses the undeclared ones.)
( function () use ( $check, $skip, $call, $aio_active ) {
	$name = $aio_active();
	if ( '' === $name ) {
		$skip( 'no SEO provider active' );
		return;
	}
	$pid = wp_insert_post( array( 'post_title' => 'Minn v044 seo shape probe', 'post_status' => 'draft' ) );
	try {
		$pick = array();
		foreach ( minn_admin_seo_field_map( minn_admin_seo_plugin(), $pid ) as $field => $def ) {
			$type = $def['type'] ?? 'text';
			if ( in_array( $field, array( 'title', 'description', 'focus_keyword' ), true ) || isset( $def['sanitize'] ) || isset( $pick[ $type ] ) ) {
				continue;
			}
			if ( 'text' === $type || 'textarea' === $type ) {
				$pick[ $type ] = $field;
			}
		}
		if ( ! $pick ) {
			$skip( "SEO ({$name}): no undeclared text field to probe" );
			return;
		}
		$read_of = function () use ( $call, $pid ) {
			list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
			return (array) ( $read['minn_seo'] ?? array() );
		};
		$set = array();
		foreach ( $pick as $field ) {
			$set[ $field ] = 'Minn v044 probe ' . $field;
		}
		$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $set ) );
		$bad = array();
		foreach ( array_values( $pick ) as $i => $field ) {
			$bad[ $field ] = 0 === $i ? array( 'x' ) : array( 'a' => 1 );
		}
		list( $st ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $bad ) );
		$now        = $read_of();
		foreach ( $pick as $field ) {
			$check( "SEO ({$name}): a non-text value sent for {$field} leaves it as stored", $set[ $field ] === ( $now[ $field ] ?? null ), 'status ' . $st . ' ' . wp_json_encode( $now[ $field ] ?? null ) );
		}
		// The same for every other scalar type (a toggle cast an array to true).
		$others = array();
		foreach ( minn_admin_seo_field_map( minn_admin_seo_plugin(), $pid ) as $field => $def ) {
			if ( in_array( $def['type'] ?? 'text', array( 'toggle', 'select', 'number' ), true ) ) {
				$others[ $field ] = array( 'x' );
			}
		}
		if ( $others ) {
			$was = array_intersect_key( $read_of(), $others );
			$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $others ) );
			$now = array_intersect_key( $read_of(), $others );
			$check( "SEO ({$name}): an array sent for a toggle, select or number changes nothing", $was === $now, wp_json_encode( array_diff_assoc( array_map( 'wp_json_encode', $now ), array_map( 'wp_json_encode', $was ) ) ) );
		}
		$edit = array();
		foreach ( array_values( $pick ) as $i => $field ) {
			$edit[ $field ] = 0 === $i ? 'Minn v044 probe edited' : '';
		}
		$call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => $edit ) );
		$now = $read_of();
		$ok  = true;
		foreach ( $edit as $field => $want ) {
			$ok = $ok && $want === ( $now[ $field ] ?? null );
		}
		$check( "SEO ({$name}): text still saves and an empty string still clears (control)", $ok, wp_json_encode( array_intersect_key( $now, $edit ) ) );
	} finally {
		wp_delete_post( $pid, true );
	}
} )();

// @sections

// Flamingo files a contact for every user a section creates and keeps it
// after the user is deleted; the fixtures' leftovers go here.
if ( post_type_exists( 'flamingo_contact' ) ) {
	global $wpdb;
	foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'flamingo_contact' AND post_title LIKE 'minn-%@example.com'" ) as $minn_contact ) {
		if ( ! get_user_by( 'email', $minn_contact->post_title ) ) {
			wp_delete_post( (int) $minn_contact->ID, true );
		}
	}
}

$summary();
