<?php
/**
 * Regressions for the demo-site bugs in the backups family and the builder
 * and automation adapters (D-22, D-23, D-26, D-27, D-28).
 *
 * Each section reads what the client is handed (the boot payload's
 * descriptor, the route the client calls) and checks what the screen would
 * show. Fixtures go in through the vendor's own tables, files or options and
 * come out at the end; a vendor that is not active SKIPs its section.
 *
 * Run: wp eval-file tests/demo-bugs-backups-builders.test.php --user=admin --path=<site>
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
	printf( "\ndemo-bugs-backups-builders: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

// The boot payload's surfaces, read fresh.
$surfaces_ref = new ReflectionProperty( 'Minn_Admin_Surfaces', 'all_cache' );
$surfaces_ref->setAccessible( true );
$surface = function ( $id ) use ( $surfaces_ref ) {
	$surfaces_ref->setValue( null, null );
	foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
		if ( $id === $s['id'] ) {
			return $s;
		}
	}
	return null;
};

// What the surface detail dialog draws for a list item, read the way the
// client reads it: the head is the item's own title or name, else the
// surface label; the rows are every scalar key the detail does not skip.
$dialog = function ( $s, $item ) {
	$detail = (array) ( $s['collection']['detail'] ?? array() );
	$skip   = array_merge( array( 'id' ), (array) ( $detail['skip'] ?? array() ) );
	$rows   = array();
	foreach ( (array) $item as $k => $v ) {
		$k = (string) $k;
		if ( in_array( $k, $skip, true ) || 0 === strpos( $k, '_' ) || ( isset( $detail['messageKey'] ) && $k === $detail['messageKey'] ) ) {
			continue;
		}
		if ( null === $v || '' === $v || is_array( $v ) || is_object( $v ) ) {
			continue;
		}
		$rows[ $k ] = $v;
	}
	$own = trim( (string) ( $item['title'] ?? '' ) );
	if ( '' === $own ) {
		$own = trim( (string) ( $item['name'] ?? '' ) );
	}
	return array(
		'title' => '' !== $own ? $own : (string) $s['label'],
		'named' => '' !== $own,
		'rows'  => $rows,
	);
};
// A row nobody can read: a raw epoch or byte count, a bare true/false.
$raw_rows = function ( $rows ) {
	$bad = array();
	foreach ( $rows as $k => $v ) {
		if ( is_bool( $v ) || in_array( $k, array( 'ts', 'bytes', 'size_raw' ), true ) || ( is_numeric( $v ) && (float) $v >= 1e8 ) ) {
			$bad[] = $k . '=' . var_export( $v, true );
		}
	}
	return $bad;
};
$dialog_checks = function ( $tag, $s, $item ) use ( $check, $dialog, $raw_rows ) {
	$d = $dialog( $s, $item );
	$check( "{$tag}: the detail dialog is headed by the backup itself, with the plugin under it, not only \"{$s['label']}\"", $d['named'], $d['title'] );
	$bad = $raw_rows( $d['rows'] );
	$check( "{$tag}: no detail row is a raw stamp, byte count or true/false", ! $bad, implode( ', ', $bad ) );
	$dup = array_keys( array_filter( $d['rows'], function ( $v ) use ( $d ) {
		return (string) $v === $d['title'];
	} ) );
	$check( "{$tag}: no detail row repeats the title", ! $dup, implode( ', ', $dup ) );
	// A lowercase single token ("local", "locked", "s3") is a stored slug;
	// the client prints a detail value as sent.
	$slugs = array();
	foreach ( $d['rows'] as $k => $v ) {
		if ( is_string( $v ) && preg_match( '/^[a-z][a-z0-9_-]*$/', $v ) ) {
			$slugs[] = $k . '=' . $v;
		}
	}
	$check( "{$tag}: no detail row shows a raw lowercase value", ! $slugs, implode( ', ', $slugs ) );
};

$admin_login = wp_get_current_user()->user_login;

// --- D-22 BackWPup: the Last run card reads BackWPup's clock ----------------
// BackWPup stamps a job's lastrun with its start_time, time() shifted by the
// site's gmt_offset, so the stamp is a site-local wall clock written as an
// epoch. The card handed it to human_time_diff() as UTC and read 18 hours
// for an archive made 11 hours ago in Los Angeles. The job registry is read
// through a filter for this one check; nothing is written.
if ( ! function_exists( 'minn_admin_backwpup_active' ) || ! minn_admin_backwpup_active() ) {
	$skip( 'D-22 BackWPup is not active here' );
} elseif ( ! minn_admin_backwpup_folder_job_ids() ) {
	$skip( 'D-22 BackWPup has no job writing to the local folder' );
} elseif ( 0.0 === (float) get_option( 'gmt_offset', 0 ) ) {
	$skip( 'D-22 the site runs on UTC, so a local stamp and a UTC one cannot be told apart' );
} else {
	$d22_job   = minn_admin_backwpup_folder_job_ids()[0];
	$d22_ago   = 2 * HOUR_IN_SECONDS;
	// What BackWPup_Job writes: time() + gmt_offset hours.
	$d22_stamp = time() + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) - $d22_ago;
	$d22_hook  = function ( $jobs ) use ( $d22_job, $d22_stamp ) {
		foreach ( (array) $jobs as $k => $job ) {
			if ( is_array( $job ) && isset( $job['jobid'] ) ) {
				// Only the one job runs recently, so the newest stamp is ours.
				$jobs[ $k ]['lastrun'] = (int) $job['jobid'] === $d22_job ? $d22_stamp : 0;
			}
		}
		return $jobs;
	};
	add_filter( 'site_option_backwpup_jobs', $d22_hook );
	try {
		$d22_last = minn_admin_backwpup_last_run();
		$check( 'D-22 BackWPup: the newest job run reads as two hours ago, not two hours plus the site offset', abs( ( time() - $d22_ago ) - $d22_last ) < 120, sprintf( 'read %d s ago', time() - $d22_last ) );
		if ( minn_admin_backwpup_running() ) {
			$skip( 'D-22 BackWPup card: a job is running, so the card says so instead' );
		} else {
			$d22_rows = wp_list_pluck( minn_admin_backwpup_status_model()['rows'], 'value', 'label' );
			/* translators: %s: human-readable time since the last finished job run. */
			$d22_want = sprintf( __( '%s ago', 'minn-admin' ), human_time_diff( time() - $d22_ago ) );
			$check( 'D-22 BackWPup card: Last run says ' . $d22_want, ( $d22_rows[ __( 'Last run', 'minn-admin' ) ] ?? '' ) === $d22_want, (string) ( $d22_rows[ __( 'Last run', 'minn-admin' ) ] ?? '' ) );
		}
	} finally {
		remove_filter( 'site_option_backwpup_jobs', $d22_hook );
	}
}

// --- S10 BackWPup: the backup-now poll reads the stamp in its own date's zone -
// The job poll turned lastrun back into UTC with today's gmt_offset. A stamp
// from the other half of the DST year came out an hour off, so a run started
// in winter and polled in summer looked older than its start and the poll
// said the job never started. A January start and its finished stamp are
// handed to the poll through a transient and a read filter; the transient
// is removed after.
if ( ! function_exists( 'minn_admin_backwpup_active' ) || ! minn_admin_backwpup_active() || ! minn_admin_backwpup_folder_job_ids() ) {
	$skip( 'S10 BackWPup is not active here, or has no local-folder job' );
} else {
	$s10_start = (int) get_gmt_from_date( gmdate( 'Y' ) . '-01-15 10:00:00', 'U' );
	$s10_jan   = ( new DateTime( '@' . $s10_start ) )->setTimezone( wp_timezone() )->getOffset();
	$s10_now   = ( new DateTime( 'now', wp_timezone() ) )->getOffset();
	if ( $s10_jan === $s10_now ) {
		$skip( 'S10 the site\'s zone has the same offset in January as today, so the two readings agree' );
	} else {
		$s10_job   = minn_admin_backwpup_folder_job_ids()[0];
		$s10_token = 'minns10' . wp_generate_password( 5, false, false );
		// What BackWPup writes as it starts: time() plus that day's offset.
		$s10_hook  = function ( $jobs ) use ( $s10_job, $s10_start, $s10_jan ) {
			foreach ( (array) $jobs as $k => $job ) {
				if ( is_array( $job ) && isset( $job['jobid'] ) && (int) $job['jobid'] === $s10_job ) {
					$jobs[ $k ]['lastrun']       = $s10_start + $s10_jan;
					$jobs[ $k ]['lastrunerrors'] = 0;
				}
			}
			return $jobs;
		};
		add_filter( 'site_option_backwpup_jobs', $s10_hook );
		set_transient( 'minn_backwpup_job_' . $s10_token, array( 'started' => $s10_start, 'jobid' => $s10_job ), HOUR_IN_SECONDS );
		try {
			if ( minn_admin_backwpup_running() ) {
				$skip( 'S10 a BackWPup job is running, so the poll reports that instead' );
			} else {
				list( $s10_st, $s10_body ) = $call( 'GET', '/minn-admin/v1/backwpup/job/' . $s10_token );
				$check( 'S10 BackWPup: a job started and stamped in January reads as finished', 200 === $s10_st && 'done' === ( $s10_body['status'] ?? '' ), $s10_st . ' ' . wp_json_encode( $s10_body ) );
			}
		} finally {
			remove_filter( 'site_option_backwpup_jobs', $s10_hook );
			delete_transient( 'minn_backwpup_job_' . $s10_token );
		}
	}
}

// --- D-23 Backups: what each provider's detail dialog shows ----------------
// BackWPup, UpdraftPlus and WPvivid rows carried no name, so their dialogs
// were headed by the family label alone ("Backups"). Disembark printed its
// sort stamp ("Ts 1791605400"), WP Migrate a bare "Compressed true" and
// "Bytes 133737" beside the size it already showed.
if ( ! function_exists( 'minn_admin_backwpup_active' ) || ! minn_admin_backwpup_active() ) {
	$skip( 'D-23 BackWPup is not active here' );
} else {
	$s = $surface( 'backwpup' );
	list( , $d23 ) = $call( 'GET', '/minn-admin/v1/backwpup/backups', null, array( 'per_page' => 5 ) );
	$item = $d23['items'][0] ?? null;
	if ( ! $s || ! $item ) {
		$skip( 'D-23 BackWPup has no archive in its local folder to open' );
	} else {
		$dialog_checks( 'D-23 BackWPup', $s, $item );
	}
}

if ( ! function_exists( 'minn_admin_updraftplus_active' ) || ! minn_admin_updraftplus_active() ) {
	$skip( 'D-23 UpdraftPlus is not active here' );
} else {
	$s = $surface( 'updraftplus' );
	list( , $d23 ) = $call( 'GET', '/minn-admin/v1/updraft/backups', null, array( 'per_page' => 5 ) );
	$item = $d23['items'][0] ?? null;
	if ( ! $s || ! $item ) {
		$skip( 'D-23 UpdraftPlus has no backup set to open' );
	} else {
		$dialog_checks( 'D-23 UpdraftPlus', $s, $item );
		// I25: the list's Stored column printed the raw service ids too
		// ("Googledrive"). Nothing gates on where, so the column can read
		// the label.
		$up_col = null;
		foreach ( (array) $s['collection']['columns'] as $col ) {
			if ( __( 'Stored', 'minn-admin' ) === ( $col['label'] ?? '' ) ) {
				$up_col = $col;
			}
		}
		$up_raw = array();
		foreach ( (array) ( $d23['items'] ?? array() ) as $row ) {
			$v = $up_col ? (string) ( $row[ $up_col['key'] ] ?? '' ) : '';
			if ( '' === $v || preg_match( '/^[a-z][a-z0-9_-]*(, [a-z][a-z0-9_-]*)*$/', $v ) ) {
				$up_raw[] = $v;
			}
		}
		$up_gated = array_filter( (array) $s['collection']['actions'], function ( $a ) {
			return 'where' === ( $a['when']['key'] ?? '' );
		} );
		$check( 'I25 UpdraftPlus: the Stored column shows where a set is kept in words, not a service id', $up_col && ! $up_raw, wp_json_encode( array( 'column' => $up_col, 'raw' => $up_raw ) ) );
		$check( 'I25 UpdraftPlus control: no action is gated on the raw where', ! $up_gated, wp_json_encode( array_values( $up_gated ) ) );
	}
}

// A scan session is a folder under uploads/disembark/ holding the manifest
// Disembark writes when a scan finishes, beside its file-list chunks.
if ( ! function_exists( 'minn_admin_disembark_active' ) || ! minn_admin_disembark_active() ) {
	$skip( 'D-23 Disembark is not active here' );
} elseif ( ! is_dir( minn_admin_disembark_dir() ) ) {
	$skip( 'D-23 Disembark has no working folder here' );
} else {
	$d23_token = 'minnd23' . strtolower( wp_generate_password( 20, false, false ) );
	$d23_dir   = minn_admin_disembark_dir() . $d23_token;
	try {
		wp_mkdir_p( $d23_dir );
		file_put_contents( $d23_dir . '/manifest.json', wp_json_encode( array( 'files' => 2 ) ) );
		file_put_contents( $d23_dir . '/files-1.json', wp_json_encode( array( 'index.php', 'wp-config.php' ) ) );
		$s = $surface( 'disembark' );
		list( , $d23 ) = $call( 'GET', '/minn-admin/v1/disembark/sessions', null, array( 'per_page' => 100 ) );
		$item = null;
		foreach ( (array) ( $d23['items'] ?? array() ) as $row ) {
			if ( $d23_token === $row['id'] ) {
				$item = $row;
			}
		}
		if ( ! $s || ! $item ) {
			$check( 'D-23 Disembark: the fixture session is listed', false, wp_json_encode( $d23 ) );
		} else {
			$dialog_checks( 'D-23 Disembark', $s, $item );
		}
	} finally {
		foreach ( array( 'manifest.json', 'files-1.json' ) as $f ) {
			if ( is_file( $d23_dir . '/' . $f ) ) {
				unlink( $d23_dir . '/' . $f );
			}
		}
		if ( is_dir( $d23_dir ) ) {
			rmdir( $d23_dir );
		}
	}
}

// A WP Migrate backup is a .sql.gz in their uploads folder named
// {site}-backup-{14 digits}-{5 chars}, the shape their listing accepts.
if ( ! function_exists( 'minn_admin_wp_migrate_can' ) || ! minn_admin_wp_migrate_can() ) {
	$skip( 'D-23 WP Migrate is not active here, or this user cannot migrate' );
} elseif ( '' === minn_admin_wpm_backup_dir() || ! is_dir( minn_admin_wpm_backup_dir() ) ) {
	$skip( 'D-23 WP Migrate has no backup folder here' );
} else {
	$d23_file = rtrim( minn_admin_wpm_backup_dir(), '/\\' ) . '/minnd23-backup-' . gmdate( 'YmdHis' ) . '-' . strtolower( wp_generate_password( 5, false, false ) ) . '.sql.gz';
	try {
		file_put_contents( $d23_file, gzencode( "-- Minn D-23 fixture\n" ) );
		$s = $surface( 'wpmigrate-backups' );
		list( , $d23 ) = $call( 'GET', '/minn-admin/v1/wp-migrate/backups', null, array( 'per_page' => 100 ) );
		$item = null;
		foreach ( (array) ( $d23['items'] ?? array() ) as $row ) {
			if ( basename( $d23_file ) === $row['file'] ) {
				$item = $row;
			}
		}
		if ( ! $s || ! $item ) {
			$check( 'D-23 WP Migrate: the fixture backup is listed', false, wp_json_encode( $d23 ) );
		} else {
			$dialog_checks( 'D-23 WP Migrate', $s, $item );
		}
	} finally {
		if ( is_file( $d23_file ) ) {
			unlink( $d23_file );
		}
	}
}

// --- D-23 WPvivid: full backups read as full -------------------------------
// WPvivid's list row records only its archive files, never what went in
// (the backup_files key the old guess relied on is the demo seed's, not
// theirs). Its default merges every part into one {prefix}_backup_all.zip
// whatever was chosen, and the parts inside say which: their restore reads
// a merged archive's children and takes {…}_backup_db.zip as the database.
// The guess matched words across the whole file name instead, so a full
// backup read as Files, and a database backup of a www. site as Database ·
// Files. Sets go in through their own option, in the shape their
// add_new_backup() writes, with archives built the way their merge builds
// them.
if ( ! function_exists( 'minn_admin_wpvivid_active' ) || ! minn_admin_wpvivid_active() || ! minn_admin_wpvivid_can() ) {
	$skip( 'D-23 WPvivid is not active here, or this user is not its administrator' );
} elseif ( ! class_exists( 'ZipArchive' ) ) {
	$skip( 'D-23 WPvivid fixtures need ZipArchive' );
} else {
	$vv_dir   = trailingslashit( WP_CONTENT_DIR . '/' . WPvivid_Setting::get_backupdir() );
	$vv_made  = array();
	$vv_files = array();
	$vv_stamp = gmdate( 'Y-m-d-H-i' );
	$vv_zip   = function ( $path, $children ) use ( &$vv_files ) {
		$z = new ZipArchive();
		if ( true !== $z->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}
		$vv_files[] = $path;
		$json       = $children ? array( 'has_child' => 1, 'child_file' => array_fill_keys( $children, array() ) ) : array( 'dump_db' => 1, 'file_type' => 'databases' );
		$z->addFromString( 'wpvivid_package_info.json', wp_json_encode( $json ) );
		foreach ( $children as $child ) {
			$z->addFromString( $child, 'Minn D-23 fixture part' );
		}
		return $z->close();
	};
	$vv_set = function ( $prefix, $job, $children, $lock = 0 ) use ( $vv_dir, $vv_stamp, $vv_zip, &$vv_made ) {
		$id   = 'wpvivid-' . substr( md5( uniqid( 'minnd23', true ) ), 0, 13 );
		$base = ( '' !== $prefix ? $prefix . '_' : '' ) . $id . '_' . $vv_stamp;
		$name = $base . '_' . $job . '.zip';
		$kids = array_map( function ( $part ) use ( $base ) {
			return $base . '_' . $part . '.zip';
		}, $children );
		if ( ! $vv_zip( $vv_dir . $name, $kids ) ) {
			return '';
		}
		$list        = WPvivid_Setting::get_option( 'wpvivid_backup_list' );
		$list        = is_array( $list ) ? $list : array();
		$list[ $id ] = array(
			'type'          => 'Manual',
			'create_time'   => time(),
			'manual_delete' => 0,
			'local'         => array( 'path' => WPvivid_Setting::get_backupdir() ),
			'compress'      => array( 'compress_type' => 'zip' ),
			'save_local'    => 1,
			'backup_prefix' => $prefix,
			'log'           => '',
			'backup'        => array( 'result' => 'success', 'files' => array( array( 'file_name' => $name, 'size' => (int) filesize( $vv_dir . $name ) ) ) ),
			'remote'        => array(),
			'lock'          => $lock,
		);
		WPvivid_Setting::update_option( 'wpvivid_backup_list', $list );
		$vv_made[] = $id;
		return $id;
	};
	try {
		$vv_full  = $vv_set( 'minn-d23.test', 'backup_all', array( 'backup_db', 'backup_themes', 'backup_plugin', 'backup_uploads', 'backup_content', 'backup_core' ) );
		$vv_only  = $vv_set( 'minn-d23.test', 'backup_all', array( 'backup_themes', 'backup_plugin', 'backup_uploads', 'backup_content', 'backup_core' ) );
		// Locked from their list's padlock: kept until deleted by hand.
		$vv_db    = $vv_set( 'www.minn-d23.test', 'backup_db', array(), 1 );
		// I14: the file jobs their naming has beyond the five merged ones.
		$vv_others  = $vv_set( 'minn-d23.test', 'backup_others', array() );
		$vv_ufiles  = $vv_set( 'minn-d23.test', 'backup_uploads_files.part001', array() );
		$vv_custom  = $vv_set( 'minn-d23.test', 'backup_custom_other', array() );
		// N4: a merged set whose archive goes away after the first read.
		$vv_memo    = $vv_set( 'minn-d23.test', 'backup_all', array( 'backup_db', 'backup_themes' ) );
		$vv_rows    = array();
		foreach ( minn_admin_wpvivid_rows() as $row ) {
			$vv_rows[ $row['id'] ] = $row;
		}
		$vv_label = function ( $id ) use ( $vv_rows ) {
			return isset( $vv_rows[ $id ] ) ? (string) $vv_rows[ $id ]['components'] : '(not listed)';
		};
		foreach ( array( 'backup_others' => $vv_others, 'backup_uploads_files' => $vv_ufiles, 'backup_custom_other' => $vv_custom ) as $vv_job => $vv_id ) {
			$check( "I14 WPvivid: an archive of their {$vv_job} job reads Files", 'Files' === $vv_label( $vv_id ), $vv_label( $vv_id ) );
		}
		// The list, the status card and the status route each read the rows,
		// some of them twice a request, and a merged set is read by opening
		// its archives. Read once per request, a set keeps its first answer.
		$vv_memo_list = WPvivid_Setting::get_option( 'wpvivid_backup_list' );
		$vv_memo_file = isset( $vv_memo_list[ $vv_memo ]['backup']['files'][0]['file_name'] ) ? $vv_dir . $vv_memo_list[ $vv_memo ]['backup']['files'][0]['file_name'] : '';
		if ( '' !== $vv_memo_file && is_file( $vv_memo_file ) ) {
			unlink( $vv_memo_file );
		}
		$vv_again = '(not listed)';
		foreach ( minn_admin_wpvivid_rows() as $row ) {
			if ( $vv_memo === $row['id'] ) {
				$vv_again = (string) $row['components'];
			}
		}
		$check( 'N4 WPvivid: a merged set is opened once a request, so a second read keeps its answer', 'Database · Files' === $vv_label( $vv_memo ) && $vv_again === $vv_label( $vv_memo ), $vv_label( $vv_memo ) . ' then ' . $vv_again );
		$check( 'D-23 WPvivid: a merged backup of files and database reads Database · Files', 'Database · Files' === $vv_label( $vv_full ), $vv_label( $vv_full ) );
		$check( 'D-23 WPvivid control: a merged backup of files alone reads Files', 'Files' === $vv_label( $vv_only ), $vv_label( $vv_only ) );
		$check( 'D-23 WPvivid: a database backup of a www. site reads Database', 'Database' === $vv_label( $vv_db ), $vv_label( $vv_db ) );
		$s = $surface( 'wpvivid' );
		if ( $s && isset( $vv_rows[ $vv_full ] ) ) {
			list( , $d23 ) = $call( 'GET', '/minn-admin/v1/wpvivid/backups', null, array( 'per_page' => 100 ) );
			$item = null;
			foreach ( (array) ( $d23['items'] ?? array() ) as $row ) {
				if ( $vv_full === $row['id'] ) {
					$item = $row;
				}
			}
			$locked = null;
			foreach ( (array) ( $d23['items'] ?? array() ) as $row ) {
				if ( $vv_db === $row['id'] ) {
					$locked = $row;
				}
			}
			if ( $item && $locked ) {
				$dialog_checks( 'D-23 WPvivid', $s, $item );
				$dialog_checks( 'D-23 WPvivid, a locked set', $s, $locked );
				// Download and Delete are gated on the raw where and locked
				// values, which stay as stored beside the labels.
				$vv_offered = function ( $row, $pick ) use ( $s ) {
					foreach ( (array) $s['collection']['actions'] as $a ) {
						if ( $pick( $a ) ) {
							return empty( $a['when'] ) || (string) ( $row[ $a['when']['key'] ] ?? '' ) === (string) $a['when']['equals'];
						}
					}
					return null;
				};
				$vv_download = function ( $a ) {
					return ! empty( $a['href'] );
				};
				$vv_delete = function ( $a ) {
					return 'DELETE' === ( $a['method'] ?? '' );
				};
				$check( 'D-23 WPvivid control: Download is still offered on a set kept here, Delete still refused on a locked one and offered on an unlocked one', true === $vv_offered( $locked, $vv_download ) && false === $vv_offered( $locked, $vv_delete ) && true === $vv_offered( $item, $vv_delete ), wp_json_encode( array( 'where' => $locked['where'] ?? null, 'locked' => $locked['locked'] ?? null ) ) );
			} else {
				$check( 'D-23 WPvivid: the fixture sets are on the route', false, wp_json_encode( $d23 ) );
			}
		}
	} finally {
		foreach ( $vv_made as $id ) {
			WPvivid_Backuplist::delete_backup( $id );
		}
		foreach ( $vv_files as $path ) {
			if ( is_string( $path ) && is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
}

// --- D-26 Novamira: OAuth apps ----------------------------------------------
// The connections query asked for access_tokens.created_at, a column
// Novamira's schema does not have (includes/oauth/schema.php), so the
// whole query failed and no OAuth app was ever listed. A connected app is
// seeded in their three tables, the way their token flow leaves one.
if ( ! function_exists( 'minn_admin_novamira_active' ) || ! minn_admin_novamira_active() || ! minn_admin_novamira_can() ) {
	$skip( 'D-26 Novamira is not active here, or this user cannot manage it' );
} elseif ( ! minn_admin_novamira_oauth_ready() ) {
	$skip( 'D-26 Novamira has not created its OAuth tables here' );
} else {
	global $wpdb;
	$nv_p      = $wpdb->prefix . 'novamira_oauth_';
	$nv_client = 'minnd26' . strtolower( wp_generate_password( 16, false, false ) );
	$nv_access = hash( 'sha256', wp_generate_password( 40, false, false ) );
	$nv_fresh  = hash( 'sha256', wp_generate_password( 40, false, false ) );
	$nv_when   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
	try {
		$wpdb->insert( $nv_p . 'clients', array(
			'client_id'             => $nv_client,
			'client_name'           => 'Minn D-26 app',
			'redirect_uris'         => '[]',
			'is_confidential'       => 0,
			'created_at'            => $nv_when,
			'registered_by_ip_hash' => str_repeat( '0', 64 ),
			'admin_created'         => 0,
			'grant_types'           => '',
		) );
		$wpdb->insert( $nv_p . 'access_tokens', array(
			'identifier_hash' => $nv_access,
			'client_id'       => $nv_client,
			'user_id'         => get_current_user_id(),
			'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			'scopes'          => '',
			'revoked'         => 0,
		) );
		$wpdb->insert( $nv_p . 'refresh_tokens', array(
			'identifier_hash'   => $nv_fresh,
			'access_token_hash' => $nv_access,
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
			'revoked'           => 0,
		) );
		// Every failed query lands in $EZSQL_ERROR, suppressed or not;
		// last_error only holds the most recent query's.
		global $EZSQL_ERROR;
		$nv_seen  = is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0;
		$nv_quiet = $wpdb->suppress_errors( true );
		$nv_rows  = minn_admin_novamira_connections();
		$wpdb->suppress_errors( $nv_quiet );
		$nv_error = implode( ' | ', wp_list_pluck( array_slice( (array) $EZSQL_ERROR, $nv_seen ), 'error_str' ) );
		$nv_row = null;
		foreach ( $nv_rows as $row ) {
			if ( 'oauth:' . $nv_client === $row['id'] ) {
				$nv_row = $row;
			}
		}
		$check( 'D-26 Novamira: the OAuth apps query runs against their schema', '' === $nv_error, $nv_error );
		$check( 'D-26 Novamira: a connected OAuth app is listed', null !== $nv_row, wp_json_encode( wp_list_pluck( $nv_rows, 'id' ) ) );
		$check( 'D-26 Novamira: its Created is when the app registered, in UTC', $nv_row && strtotime( (string) $nv_row['created'] ) === strtotime( $nv_when . ' UTC' ), wp_json_encode( $nv_row ) );
	} finally {
		$wpdb->delete( $nv_p . 'refresh_tokens', array( 'identifier_hash' => $nv_fresh ) );
		$wpdb->delete( $nv_p . 'access_tokens', array( 'identifier_hash' => $nv_access ) );
		$wpdb->delete( $nv_p . 'clients', array( 'client_id' => $nv_client ) );
	}
}

// --- D-26 Novamira: the Abilities tabs --------------------------------------
// Novamira unregisters a switched-off ability on every request but its Hub
// and Minn's own novamira routes, which lift that policy. The boot payload is
// built outside them, so it knew a switched-off ability by its rule alone
// and grouped it under its provider: a tab ("Novamira", "WordPress") the
// route, which sees the ability under its category, answered 404, and the
// client then lost the whole view. A child run builds the payload the way
// the app's page does; the route answers over HTTP, because a CLI process
// starts the Abilities API before any route could lift the policy. One
// ability is switched off through Novamira's own rules helper for the
// length of the section and switched back after.
//
// With abilities on, the bar also drew two pairs of tabs of one name:
// Novamira's "Context" category beside Minn's Context (site instructions)
// tab, and its bundled MCP adapter's category beside the MCP Adapter
// plugin's, both "MCP Adapter". Their Hub never lists that bundled adapter's
// abilities, and the Context ability belongs with the instructions it
// serves. A second child run reads the bar with abilities switched on
// through option filters, writing nothing.
if ( ! function_exists( 'minn_admin_novamira_active' ) || ! minn_admin_novamira_active() || ! minn_admin_novamira_can() ) {
	$skip( 'D-26 tabs: Novamira is not active here, or this user cannot manage it' );
} elseif ( ! class_exists( 'WP_CLI' ) || ! function_exists( 'proc_open' ) || ! function_exists( 'wp_get_ability' ) || ! function_exists( 'novamira_update_ability_rules' ) ) {
	$skip( 'D-26 tabs need WP-CLI, a child process, the Abilities API and Novamira\'s rules helper' );
} elseif ( ! wp_get_ability( 'core/get-site-info' ) ) {
	$skip( 'D-26 tabs: core/get-site-info is not registered here to switch off' );
} else {
	global $wpdb;
	$nt_base    = realpath( sys_get_temp_dir() ) . '/minn-d26-' . wp_generate_password( 8, false, false );
	$nt_options = function () use ( $wpdb ) {
		return $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'novamira%' OR option_name LIKE 'nvp\\_%' ORDER BY option_name", OBJECT_K );
	};
	$nt_cron    = function () {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( (array) $events as $hook => $unused ) {
				if ( 0 === strpos( (string) $hook, 'novamira' ) ) {
					$hooks[ $hook ] = true;
				}
			}
		}
		return array_keys( $hooks );
	};
	$nt_opt_was   = $nt_options();
	$nt_cron_was  = $nt_cron();
	$nt_rules_was = get_option( 'novamira_ability_rules', null );
	$nt_session   = '';
	wp_mkdir_p( $nt_base );
	file_put_contents(
		$nt_base . '/on.php',
		"<?php\n"
		. "WP_CLI::add_wp_hook( 'pre_option_novamira_ai_abilities_enabled', function () { return '1'; } );\n"
		. "WP_CLI::add_wp_hook( 'pre_option_novamira_ai_abilities_domain', function () { return function_exists( 'novamira_ai_abilities_site_host' ) ? novamira_ai_abilities_site_host() : (string) wp_parse_url( home_url(), PHP_URL_HOST ); } );\n"
		// Nothing this run does is meant to stay: refuse every write to Novamira's options.
		. "WP_CLI::add_wp_hook( 'pre_update_option', function ( \$value, \$name, \$old ) { return 0 === strpos( (string) \$name, 'novamira' ) ? \$old : \$value; }, 10, 3 );\n"
	);
	file_put_contents( $nt_base . '/off.php', "<?php\n" );
	file_put_contents(
		$nt_base . '/child.php',
		"<?php\n"
		. "\$out = array( 'tabs' => array(), 'site_info_registered' => (bool) wp_get_ability( 'core/get-site-info' ), 'site_info_tab' => '' );\n"
		. "foreach ( Minn_Admin_Surfaces::for_current_user() as \$s ) { if ( 'novamira' === \$s['id'] ) { \$out['tabs'] = (array) ( \$s['settings']['tabs'] ?? array() ); } }\n"
		. "foreach ( minn_admin_novamira_abilities() as \$a ) { if ( 'core/get-site-info' === \$a['name'] ) { \$out['site_info_tab'] = sanitize_key( minn_admin_novamira_ability_group( \$a ) ); } }\n"
		. "\$ctx = minn_admin_novamira_tab_shape( 'context' ); \$out['context'] = array();\n"
		. "foreach ( (array) ( is_wp_error( \$ctx ) ? array() : \$ctx['groups'] ) as \$g ) { foreach ( (array) ( \$g['fields'] ?? array() ) as \$f ) { \$out['context'][] = \$f['key']; } }\n"
		. "\$out['agent_context'] = (bool) wp_get_ability( 'novamira/agent-context' ); \$out['context_locked'] = array(); \$out['agent_context_label'] = '';\n"
		. "foreach ( (array) ( is_wp_error( \$ctx ) ? array() : \$ctx['groups'] ) as \$g ) { \$out['context_locked'] = array_merge( \$out['context_locked'], (array) ( \$g['lockedLabels'] ?? array() ) ); }\n"
		// S1: an ability a Novamira feature owns (the Block Editor Queue's
		// write ability), as its tab shows it and as a save treats it.
		. "\$own = 'novamira/gutenberg-write-content';\n"
		. "foreach ( minn_admin_novamira_abilities() as \$a ) { if ( 'novamira/agent-context' === \$a['name'] ) { \$out['agent_context_label'] = \$a['label']; } if ( \$own === \$a['name'] ) { \$out['owned'] = array( 'tab' => sanitize_key( minn_admin_novamira_ability_group( \$a ) ), 'label' => \$a['label'], 'fields' => array(), 'locked' => array() ); } }\n"
		. "if ( ! empty( \$out['owned'] ) ) {\n"
		. "\t\$sh = minn_admin_novamira_tab_shape( \$out['owned']['tab'] );\n"
		. "\tforeach ( (array) ( is_wp_error( \$sh ) ? array() : \$sh['groups'] ) as \$g ) { \$out['owned']['fields'] = array_merge( \$out['owned']['fields'], wp_list_pluck( (array) ( \$g['fields'] ?? array() ), 'key' ) ); \$out['owned']['locked'] = array_merge( \$out['owned']['locked'], (array) ( \$g['lockedLabels'] ?? array() ) ); }\n"
		. "\t\$out['owned']['adminUrl'] = is_wp_error( \$sh ) ? '' : (string) ( \$sh['adminUrl'] ?? '' );\n"
		// What the save hands Novamira's rules, caught before it lands.
		. "\tadd_filter( 'pre_update_option_novamira_ability_rules', function ( \$v, \$old ) { \$GLOBALS['minn_d26_attempt'] = \$v; return \$old; }, 10, 2 );\n"
		. "\t\$req = new WP_REST_Request( 'POST', '/minn-admin/v1/novamira/abilities/' . \$out['owned']['tab'] ); \$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) ); \$req->set_header( 'content-type', 'application/json' ); \$req->set_body( wp_json_encode( array( 'values' => array( 'ability:' . \$own => false ) ) ) );\n"
		. "\t\$res = rest_do_request( \$req ); \$vals = (array) ( \$res->get_data()['values'] ?? array() );\n"
		. "\t\$out['owned']['save'] = \$res->get_status(); \$out['owned']['answer'] = array_key_exists( 'ability:' . \$own, \$vals ) ? \$vals[ 'ability:' . \$own ] : 'not a field';\n"
		. "\t\$out['owned']['attempt'] = isset( \$GLOBALS['minn_d26_attempt'] ) ? array_keys( (array) \$GLOBALS['minn_d26_attempt'] ) : array();\n"
		. "}\n"
		. "foreach ( minn_admin_novamira_abilities() as \$a ) { \$out['providers'][ sanitize_key( minn_admin_novamira_ability_group( \$a ) ) ][ \$a['provider'] ] = true; }\n"
		. "echo 'MINNPROBE ' . wp_json_encode( \$out ) . \"\\n\";\n"
	);
	$nt_run = function ( $require ) use ( $nt_base, $admin_login ) {
		$o = WP_CLI::runcommand( 'eval-file ' . escapeshellarg( $nt_base . '/child.php' ) . ' --user=' . escapeshellarg( $admin_login ) . ' --require=' . escapeshellarg( $nt_base . '/' . $require ), array( 'launch' => true, 'return' => 'all', 'exit_error' => false ) );
		return preg_match( '/^MINNPROBE (.+)$/m', (string) $o->stdout, $m ) ? (array) json_decode( $m[1], true ) : array( 'error' => substr( trim( $o->stderr . ' ' . $o->stdout ), 0, 300 ) );
	};
	// A signed-in REST request as this user: a session of its own, a
	// logged_in cookie for it and the wp_rest nonce that session mints.
	$nt_http = function ( $route ) use ( &$nt_session ) {
		$uid = get_current_user_id();
		$exp = time() + HOUR_IN_SECONDS;
		if ( '' === $nt_session ) {
			$nt_session = WP_Session_Tokens::get_instance( $uid )->create( $exp );
		}
		$cookie                       = wp_generate_auth_cookie( $uid, $exp, 'logged_in', $nt_session );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		$nonce                        = wp_create_nonce( 'wp_rest' );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$r = wp_remote_get( rest_url( $route ), array(
			'headers'   => array( 'X-WP-Nonce' => $nonce ),
			'cookies'   => array( LOGGED_IN_COOKIE => $cookie ),
			'sslverify' => false,
			'timeout'   => 120,
		) );
		return is_wp_error( $r ) ? array( 0, $r->get_error_message() ) : array( (int) wp_remote_retrieve_response_code( $r ), json_decode( wp_remote_retrieve_body( $r ), true ) );
	};
	try {
		$nt_rules                       = novamira_get_ability_rules();
		$nt_rules['core/get-site-info'] = array( 'disabled' => true );
		novamira_update_ability_rules( $nt_rules );

		$nt_boot = $nt_run( 'off.php' );
		if ( empty( $nt_boot['tabs'] ) || '' === (string) ( $nt_boot['site_info_tab'] ?? '' ) ) {
			$skip( 'D-26 tabs: the boot run drew no Abilities tabs — ' . wp_json_encode( $nt_boot ) );
		} elseif ( ! empty( $nt_boot['site_info_registered'] ) ) {
			$skip( 'D-26 tabs: Novamira did not unregister the switched-off ability in the boot run, so there is nothing to repro' );
		} else {
			$nt_tab = (string) $nt_boot['site_info_tab'];
			list( $nt_code, $nt_body ) = $nt_http( 'minn-admin/v1/novamira/abilities/' . $nt_tab );
			$nt_keys = is_array( $nt_body ) ? wp_list_pluck( (array) ( $nt_body['groups'][0]['fields'] ?? array() ), 'key' ) : array();
			$check( "D-26 Novamira: the tab the boot payload files a switched-off ability under ({$nt_tab}) is served by the route, with that ability's switch", 200 === $nt_code && in_array( 'ability:core/get-site-info', $nt_keys, true ), $nt_code . ' ' . substr( wp_json_encode( is_array( $nt_body ) ? ( $nt_body['message'] ?? $nt_keys ) : $nt_body ), 0, 200 ) );
			list( $nt_ctx_code ) = $nt_http( 'minn-admin/v1/novamira/abilities/context' );
			$check( 'D-26 Novamira control: the route answers the Context tab over HTTP', 200 === $nt_ctx_code, (string) $nt_ctx_code );
		}

		$nt_on = $nt_run( 'on.php' );
		if ( empty( $nt_on['tabs'] ) ) {
			$skip( 'D-26 tabs: the run with abilities on drew no Abilities tabs — ' . wp_json_encode( $nt_on ) );
		} else {
			$nt_ids    = wp_list_pluck( $nt_on['tabs'], 'id' );
			$nt_twice  = array_keys( array_filter( array_count_values( wp_list_pluck( $nt_on['tabs'], 'label' ) ), function ( $n ) {
				return $n > 1;
			} ) );
			$check( 'D-26 Novamira: no two Abilities tabs share a name', ! $nt_twice, implode( ', ', $nt_twice ) );
			$check( 'D-26 Novamira: no tab for the bundled MCP adapter their Hub hides', ! in_array( 'c-novamira-mcp-adapter', $nt_ids, true ), implode( ', ', $nt_ids ) );
			// Novamira's own tabs lead: the categories it registers for its
			// abilities, ahead of every tab no Novamira ability is in.
			$nt_core   = array_values( array_intersect( $nt_ids, array( 'c-filesystem', 'c-code-execution', 'c-admin-access', 'c-gutenberg', 'c-skill', 'c-design-system', 'c-memory' ) ) );
			$nt_others = array_values( array_filter( $nt_ids, function ( $id ) use ( $nt_on ) {
				return 'context' !== $id && ! isset( $nt_on['providers'][ $id ]['novamira'] );
			} ) );
			$nt_last   = $nt_core ? max( array_map( function ( $id ) use ( $nt_ids ) {
				return array_search( $id, $nt_ids, true );
			}, $nt_core ) ) : -1;
			$nt_first  = $nt_others ? array_search( $nt_others[0], $nt_ids, true ) : PHP_INT_MAX;
			$check( 'D-26 Novamira: its own tabs (Filesystem, Code Execution and the rest) come before every other plugin\'s', in_array( 'c-filesystem', $nt_core, true ) && in_array( 'c-code-execution', $nt_core, true ) && $nt_last < $nt_first, implode( ', ', array_slice( $nt_ids, 0, 12 ) ) );
			$nt_ctx = (array) ( $nt_on['context'] ?? array() );
			if ( empty( $nt_on['agent_context'] ) ) {
				$check( 'D-26 Novamira control: the Context tab holds the site instructions', in_array( 'context', $nt_ctx, true ), wp_json_encode( $nt_ctx ) );
			} else {
				// A switch, or (a feature's ability, S1) a locked row.
				$nt_ctx_has = in_array( 'ability:novamira/agent-context', $nt_ctx, true ) || in_array( (string) $nt_on['agent_context_label'], (array) ( $nt_on['context_locked'] ?? array() ), true );
				$check( 'D-26 Novamira: the Context tab holds the site instructions and the ability that serves them', in_array( 'context', $nt_ctx, true ) && $nt_ctx_has, wp_json_encode( array( $nt_ctx, $nt_on['context_locked'] ?? null ) ) );
			}
			// S1: Novamira's policy ignores a rule for an ability one of its
			// features owns, and their Hub refuses the switch. Minn drew it
			// as a switch and saved the rule, so it read off while agents
			// could still call it.
			$nt_own = $nt_on['owned'] ?? null;
			if ( ! $nt_own ) {
				$skip( 'S1 Novamira: the Block Editor Queue\'s write ability is not registered here with abilities on' );
			} else {
				$check( 'S1 Novamira: an ability a Novamira feature owns is a locked row, not a switch, linking to their Features screen', ! in_array( 'ability:novamira/gutenberg-write-content', (array) $nt_own['fields'], true ) && in_array( (string) $nt_own['label'], (array) $nt_own['locked'], true ) && false !== strpos( (string) $nt_own['adminUrl'], 'page=novamira-features' ), wp_json_encode( $nt_own ) );
				$check( 'S1 Novamira: a save asking to switch it off writes no rule for it and does not answer it as off', 200 === (int) $nt_own['save'] && false !== $nt_own['answer'] && ! in_array( 'novamira/gutenberg-write-content', (array) $nt_own['attempt'], true ), wp_json_encode( array( $nt_own['save'], $nt_own['answer'], $nt_own['attempt'] ) ) );
			}
		}

		// N3: Minn 0.44 drew the bundled MCP adapter's meta-abilities as
		// switches, so a site may hold a rule switching one off. Novamira's
		// policy honours it (the bridge breaks), Minn now hides the name as
		// their Hub does, and their Hub refuses it, so nothing could switch
		// it back. The next save clears it.
		if ( ! function_exists( 'novamira_ability_is_hub_hidden' ) || ! novamira_ability_is_hub_hidden( 'novamira-mcp-adapter/discover-abilities' ) ) {
			$skip( 'N3 Novamira does not hide its MCP adapter\'s abilities here' );
		} else {
			$nt_rules = novamira_get_ability_rules();
			$nt_rules['novamira-mcp-adapter/discover-abilities'] = array( 'disabled' => true );
			novamira_update_ability_rules( $nt_rules );
			minn_admin_novamira_save( array( 'ability:core/get-site-info' => false ) );
			$nt_after = novamira_get_ability_rules();
			$check( 'N3 Novamira: a save clears a rule for a meta-ability their Hub hides and refuses, and keeps the one it was asked for', ! isset( $nt_after['novamira-mcp-adapter/discover-abilities'] ) && ! empty( $nt_after['core/get-site-info']['disabled'] ), wp_json_encode( $nt_after ) );
		}
	} finally {
		if ( '' !== $nt_session ) {
			WP_Session_Tokens::get_instance( get_current_user_id() )->destroy( $nt_session );
		}
		if ( null === $nt_rules_was ) {
			delete_option( 'novamira_ability_rules' );
		} else {
			update_option( 'novamira_ability_rules', $nt_rules_was, false );
		}
		foreach ( array( 'child.php', 'on.php', 'off.php' ) as $f ) {
			if ( is_file( $nt_base . '/' . $f ) ) {
				unlink( $nt_base . '/' . $f );
			}
		}
		if ( is_dir( $nt_base ) ) {
			rmdir( $nt_base );
		}
		// The rules are back; anything else that moved, a run left behind.
		$nt_opt_now = $nt_options();
		foreach ( $nt_opt_now as $name => $row ) {
			if ( ! isset( $nt_opt_was[ $name ] ) ) {
				$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
				printf( "NOTE  D-26 removed option %s left behind\n", $name );
			} elseif ( $nt_opt_was[ $name ]->option_value !== $row->option_value ) {
				$wpdb->update( $wpdb->options, array( 'option_value' => $nt_opt_was[ $name ]->option_value ), array( 'option_name' => $name ) );
				printf( "NOTE  D-26 restored option %s\n", $name );
			}
			wp_cache_delete( $name, 'options' );
		}
		foreach ( array_diff( $nt_cron(), $nt_cron_was ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
			printf( "NOTE  D-26 cleared cron hook %s left behind\n", $hook );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}

// --- D-27 Elementor templates ------------------------------------------------
// Elementor's templates are posts: post_modified is site-local and
// post_modified_gmt UTC. The item sent the UTC column with no zone, which
// the list (marked utc) read right and the detail dialog read as local,
// seven hours off in Los Angeles. The status card counted every library
// post, the Default Kit among them, while the list leaves the kit out (15
// against 14). And no column said which templates are drafts.
if ( ! function_exists( 'minn_admin_elementor_templates_ready' ) || ! minn_admin_elementor_templates_ready() || ! minn_admin_elementor_can_manage() ) {
	$skip( 'D-27 Elementor is not active here, or this user cannot manage templates' );
} elseif ( ! isset( minn_admin_elementor_template_types()['section'] ) || ! minn_admin_elementor_can_create( 'section' ) ) {
	$skip( 'D-27 Elementor: this site offers no Section template to create' );
} else {
	$el_id = 0;
	try {
		list( $el_st, $el_made ) = $call( 'POST', '/minn-admin/v1/elementor/templates', array( 'title' => 'Minn D-27 draft section', 'type' => 'section' ) );
		$el_id = isset( $el_made['id'] ) ? $el_made['id'] : 0;
		if ( ! is_int( $el_id ) || $el_id <= 1 || 200 !== $el_st ) {
			$check( 'D-27 Elementor: the fixture template is created', false, wp_json_encode( array( $el_st, $el_made ) ) );
			$el_id = 0;
		} else {
			wp_update_post( array( 'ID' => $el_id, 'post_status' => 'draft' ) );
			$el_post = get_post( $el_id );
			$el_item = minn_admin_elementor_template_item( $el_post );
			$el_mod  = (string) $el_item['modified'];
			$check( 'D-27 Elementor: Modified carries its zone, so the detail dialog cannot read it as local', (bool) preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $el_mod ), $el_mod );
			$check( 'D-27 Elementor: Modified is post_modified_gmt as UTC', strtotime( $el_mod ) === strtotime( $el_post->post_modified_gmt . ' UTC' ), $el_mod . ' vs ' . $el_post->post_modified_gmt . ' UTC' );
			$el_s    = $surface( 'elementor-templates' );
			$el_cols = $el_s ? wp_list_pluck( (array) $el_s['collection']['columns'], 'format', 'key' ) : array();
			$check( 'D-27 Elementor: the list has a status pill, so a draft reads as one', 'pill' === ( $el_cols['status'] ?? '' ), wp_json_encode( $el_cols ) );
			$check( 'D-27 Elementor: the draft fixture carries status draft', 'draft' === $el_item['status'], (string) $el_item['status'] );
			list( , $el_status ) = $call( 'GET', '/minn-admin/v1/elementor/templates/status' );
			list( , $el_list )   = $call( 'GET', '/minn-admin/v1/elementor/templates' );
			$el_card  = wp_list_pluck( (array) ( $el_status['rows'] ?? array() ), 'value', 'label' );
			$el_count = (string) ( $el_card[ __( 'Templates', 'minn-admin' ) ] ?? '' );
			$el_kits  = get_posts( array( 'post_type' => \Elementor\TemplateLibrary\Source_Local::CPT, 'post_status' => 'any', 'meta_key' => \Elementor\Core\Base\Document::TYPE_META_KEY, 'meta_value' => 'kit', 'fields' => 'ids', 'numberposts' => -1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			$check( 'D-27 Elementor: the Templates count matches the list (' . count( $el_kits ) . ' kit post(s) here)', number_format_i18n( (int) ( $el_list['total'] ?? -1 ) ) === $el_count, "card {$el_count}, list " . (int) ( $el_list['total'] ?? -1 ) );
			// I24: the pill read a scheduled template's post_status, future,
			// as "Future"; core and Elementor call it Scheduled.
			$el_when = time() + DAY_IN_SECONDS;
			wp_update_post( array( 'ID' => $el_id, 'post_status' => 'future', 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $el_when ) ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $el_when ) ) );
			$el_future = minn_admin_elementor_template_item( get_post( $el_id ) );
			$check( 'I24 Elementor: a scheduled template\'s pill reads Scheduled', 'future' === get_post_status( $el_id ) && 'scheduled' === $el_future['status'], get_post_status( $el_id ) . ' -> ' . $el_future['status'] );
		}
	} finally {
		if ( is_int( $el_id ) && $el_id > 1 && get_post( $el_id ) ) {
			wp_clear_scheduled_hook( 'publish_future_post', array( $el_id ) );
			wp_delete_post( $el_id, true );
		}
	}
}

// --- S7 Bricks templates: Modified carries its zone ---------------------------
// The D-27 pattern: post_modified_gmt went out bare, which the list (marked
// utc) read right and the detail dialog read as site-local. Read-only: the
// newest template there is.
if ( ! function_exists( 'minn_admin_bricks_template_item' ) || ! class_exists( '\\Bricks\\Templates' ) || ! defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ) {
	$skip( 'S7 Bricks is not the active theme here' );
} else {
	$br_posts = get_posts( array( 'post_type' => BRICKS_DB_TEMPLATE_SLUG, 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'modified' ) );
	if ( ! $br_posts ) {
		$skip( 'S7 Bricks has no template here to read' );
	} else {
		$br_mod = (string) minn_admin_bricks_template_item( $br_posts[0] )['modified'];
		$check( 'S7 Bricks: Modified carries its zone and is post_modified_gmt as UTC', (bool) preg_match( '/(Z|[+-]\d\d:?\d\d)$/', $br_mod ) && strtotime( $br_mod ) === strtotime( $br_posts[0]->post_modified_gmt . ' UTC' ), $br_mod );
	}
}

// --- D-28 OttoKit: Retry where a request failed, Rerun where it was delivered -
// Their own screen offers Retry on a failed request and Rerun on a delivered
// one; both re-fire the webhook. Minn offered Retry on every row.
if ( ! function_exists( 'minn_admin_ottokit_active' ) || ! minn_admin_ottokit_active() ) {
	$skip( 'D-28 OttoKit (suretriggers) is not active here' );
} elseif ( ! minn_admin_ottokit_has_table() ) {
	$skip( 'D-28 OttoKit has no request log table here' );
} else {
	$ok_s = $surface( 'ottokit' );
	if ( ! $ok_s ) {
		$check( 'D-28 OttoKit: the surface is offered to this user', false );
	} else {
		$ok_by = array();
		foreach ( array( 'actions', 'bulk' ) as $where ) {
			foreach ( (array) ( $ok_s['collection'][ $where ] ?? array() ) as $a ) {
				if ( false !== strpos( (string) ( $a['route'] ?? '' ), '/retry' ) ) {
					$ok_by[ $where ][ (string) ( $a['when']['equals'] ?? '' ) ] = (string) $a['label'];
				}
			}
		}
		$check( 'D-28 OttoKit: the row and dialog offer Retry on a failed request', __( 'Retry', 'minn-admin' ) === ( $ok_by['actions']['failed'] ?? '' ), wp_json_encode( $ok_by ) );
		$check( 'D-28 OttoKit: the bulk Retry is offered only when the request failed', array( 'failed' => __( 'Retry', 'minn-admin' ) ) === ( $ok_by['bulk'] ?? null ), wp_json_encode( $ok_by ) );
		// I13: their screen offers Rerun on a delivered request.
		$check( 'I13 OttoKit: the row and dialog offer Rerun on a delivered request, as their own screen does', __( 'Rerun', 'minn-admin' ) === ( $ok_by['actions']['success'] ?? '' ) && ! isset( $ok_by['actions'][''] ), wp_json_encode( $ok_by ) );
	}
}

$summary();
