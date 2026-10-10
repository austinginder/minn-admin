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
