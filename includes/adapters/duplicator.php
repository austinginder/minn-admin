<?php
/**
 * Bundled adapter: Duplicator (Lite) — backups family.
 *
 * Duplicator stores one row per package in {prefix}duplicator_packages;
 * the `package` column is a serialized PHP object and is NEVER touched by
 * this shim (sizes come from the archive files on disk in its storage dir,
 * matched by name_hash). Free-tier packages are MANUAL builds, so Minn
 * makes no freshness claims (the Disembark precedent): the status card
 * reports the newest completed package and total disk footprint, honestly
 * labeled. Delete goes through Duplicator's OWN loader + delete() so its
 * file cleanup logic stays its code. Building a package stays on its
 * screen (a multi-step JS wizard).
 *
 * Two upstream quirks the code mirrors: (a) `created` is written with
 * current_time('mysql', get_option('gmt_offset', 1)) — the offset rides
 * the $gmt FLAG, so timestamps are UTC exactly when the site offset is
 * non-zero; (b) the packages table appears via dbDelta on an admin visit,
 * so every route SHOW TABLES-gates and answers empty before then.
 *
 * Caps mirror the plugin: its whole admin is gated on `export`.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Duplicator 5.0 is a different plugin underneath. Every DUP_* class is gone
 * in favour of a Duplicator\ namespace, and the storage moved:
 * {prefix}duplicator_packages became duplicator_backups (plus _entities and
 * _activity_logs). The migration is one-way, so a site is on one side or the
 * other and this adapter carries both: 1.5.x is still widely installed.
 *
 * Their own duplicator/v1 REST namespace does NOT help. It is scaffolding
 * with a single Versions endpoint that does not even register, so this stays
 * a shim. What 5.0 does give us is better than what it took away:
 * BackupRequestService is a purpose-built public API for requesting a
 * background backup, with real WP_Error codes for "another one is running",
 * "this server cannot background", and a missing default template.
 */
function minn_admin_duplicator_is_v5() {
	return class_exists( '\\Duplicator\\Package\\DupPackage' )
		&& class_exists( '\\Duplicator\\Package\\AbstractPackage' );
}

/** Their packages table, whichever generation is installed. */
function minn_admin_duplicator_table() {
	global $wpdb;
	if ( minn_admin_duplicator_is_v5() && method_exists( '\\Duplicator\\Package\\DupPackage', 'getTableName' ) ) {
		try {
			return (string) \Duplicator\Package\DupPackage::getTableName();
		} catch ( \Throwable $e ) {
			// Fall through to the literal below.
		}
	}
	return $wpdb->base_prefix . ( minn_admin_duplicator_is_v5() ? 'duplicator_backups' : 'duplicator_packages' );
}

function minn_admin_duplicator_active() {
	global $wpdb;
	if ( ! defined( 'DUPLICATOR_VERSION' ) && ! class_exists( 'DUP_Package' ) && ! minn_admin_duplicator_is_v5() ) {
		return false;
	}
	// Duplicator keeps ONE packages table on base_prefix and its archives
	// hold the WHOLE network's database — on multisite that's super-admin
	// data (its own menu lives in Network Admin there).
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	$table = minn_admin_duplicator_table();
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

/** Their created-column quirk: UTC exactly when the site offset is truthy. */
function minn_admin_duplicator_dates_are_gmt() {
	// 5.0 writes created with gmdate(), so it is UTC unconditionally. 1.5's
	// quirk was that it passed the OFFSET as current_time()'s $gmt FLAG, which
	// made the column UTC exactly when the site offset was truthy.
	if ( minn_admin_duplicator_is_v5() ) {
		return true;
	}
	return (bool) get_option( 'gmt_offset', 1 );
}

/** Duplicator's storage dir (wp-content/backups-dup-lite by default). */
function minn_admin_duplicator_ssdir() {
	try {
		if ( class_exists( 'DUP_Settings' ) && method_exists( 'DUP_Settings', 'getSsdirPath' ) ) {
			return (string) DUP_Settings::getSsdirPath();
		}
		// 5.0 has no settings-level accessor for this, but every package can
		// name its own archive's full path, so take the directory from the
		// newest one and fall back to the default below when there are none.
		if ( minn_admin_duplicator_is_v5() ) {
			$newest = minn_admin_duplicator_v5_newest();
			if ( $newest && method_exists( $newest, 'getLocalPackageFilePath' ) ) {
				$path = $newest->getLocalPackageFilePath( \Duplicator\Package\AbstractPackage::FILE_TYPE_ARCHIVE );
				if ( $path ) {
					return dirname( (string) $path );
				}
			}
		}
	} catch ( \Throwable $e ) {
		// Fall through to the default location.
	}
	// The default moved with 5.0 (backups-dup-lite became duplicator-backups),
	// and their mu-plugin publishes the new name. Returning 1.5's default on a
	// 5.0 site would measure an empty legacy folder for "On disk" and look for
	// downloads in the wrong place.
	if ( minn_admin_duplicator_is_v5() ) {
		$name = defined( 'DUPLICATOR_MU_SSDIR_NAME' ) ? (string) DUPLICATOR_MU_SSDIR_NAME : 'duplicator-backups';
		return WP_CONTENT_DIR . '/' . $name;
	}
	return WP_CONTENT_DIR . '/backups-dup-lite';
}

/** Newest package object on 5.0, or null. Their own query layer, never raw SQL. */
function minn_admin_duplicator_v5_newest() {
	if ( ! minn_admin_duplicator_is_v5() ) {
		return null;
	}
	try {
		$found = \Duplicator\Package\DupPackage::dbSelect( '', 1, 0, '`id` DESC' );
		return $found ? reset( $found ) : null;
	} catch ( \Throwable $e ) {
		return null;
	}
}

/** One package object by id on 5.0, or null. */
function minn_admin_duplicator_v5_package( $id ) {
	if ( ! minn_admin_duplicator_is_v5() ) {
		return null;
	}
	try {
		$p = \Duplicator\Package\DupPackage::getById( (int) $id );
		return $p ? $p : null;
	} catch ( \Throwable $e ) {
		return null;
	}
}

/** Archive size on disk for a package, matched by its name_hash file stem. */
function minn_admin_duplicator_archive_size( $name, $hash ) {
	$dir = minn_admin_duplicator_ssdir();
	if ( ! $name || ! $hash || ! is_dir( $dir ) ) {
		return 0;
	}
	$size = 0;
	foreach ( (array) glob( $dir . '/' . $name . '_' . $hash . '_archive.*' ) as $file ) {
		$size += (int) @filesize( $file );
	}
	return $size;
}

/** Display rows for the packages table, newest first. */
function minn_admin_duplicator_rows() {
	global $wpdb;
	$table = minn_admin_duplicator_table();
	// 5.0 dropped the owner column and added archive_name (which saves
	// reconstructing the file stem) plus flags. Select per generation rather
	// than asking for columns the installed one does not have.
	$cols = minn_admin_duplicator_is_v5()
		? 'id, name, hash, status, created, archive_name'
		: 'id, name, hash, status, created, owner';
	$rows = $wpdb->get_results( "SELECT {$cols} FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$gmt  = minn_admin_duplicator_dates_are_gmt();
	$items = array();
	foreach ( (array) $rows as $r ) {
		$status = 'building';
		if ( (int) $r->status >= 100 ) {
			$status = 'completed';
		} elseif ( (int) $r->status < 0 ) {
			$status = 'error';
		}
		$size    = minn_admin_duplicator_archive_size( $r->name, $r->hash );
		// The installer is a second file beside the archive; a package
		// restored from elsewhere may have only the archive on disk.
		$inst    = $r->name && $r->hash ? glob( minn_admin_duplicator_ssdir() . '/' . $r->name . '_' . $r->hash . '_installer.*' ) : array();
		$items[] = array(
			'id'        => (int) $r->id,
			'name'      => (string) $r->name,
			'status'    => $status,
			'installer' => $inst ? 'yes' : 'no',
			'size'    => $size ? size_format( $size ) : '—',
			// 5.0 keeps no owner: the column is gone and the package object
			// carries no equivalent, so the column reads empty rather than
			// inventing an attribution.
			'owner'   => isset( $r->owner ) ? (string) $r->owner : '',
			'created' => $gmt ? str_replace( ' ', 'T', (string) $r->created ) . 'Z' : (string) $r->created,
		);
	}
	return $items;
}

/**
 * Their build capability (DUP_Util::hasCapability('export') on every build
 * ajax handler).
 *
 * Duplicator does not ask current_user_can('export') directly: it runs the
 * name through a filter first, which is how WPFront User Role Editor narrows
 * who may use it. Restating the literal here meant a site that had narrowed
 * Duplicator still had it wide open through Minn. Ask the vendor's own
 * question instead. (hasCapability() itself is not usable as a predicate: its
 * default mode wp_die()s rather than returning false.)
 */
function minn_admin_duplicator_cap() {
	return (string) apply_filters( 'wpfront_user_role_editor_duplicator_translate_capability', 'export' );
}

/**
 * 5.0 replaced the filtered-capability-name dance with a real manager:
 * CapMng::can( CAP_*, false ) answers without dying, and the plugin maps its
 * own duplicator_* caps onto roles. Ask that, exactly as their own
 * controllers do, rather than restating a capability name it may have
 * narrowed.
 */
function minn_admin_duplicator_v5_can( $cap ) {
	if ( ! class_exists( '\\Duplicator\\Core\\CapMng' ) ) {
		return false;
	}
	try {
		return (bool) \Duplicator\Core\CapMng::can( $cap, false );
	} catch ( \Throwable $e ) {
		return false;
	}
}

function minn_admin_duplicator_can_build() {
	if ( ! minn_admin_duplicator_active() ) {
		return false;
	}
	if ( minn_admin_duplicator_is_v5() ) {
		return minn_admin_duplicator_v5_can( \Duplicator\Core\CapMng::CAP_CREATE );
	}
	return class_exists( 'DUP_Package' ) && class_exists( 'DUP_Settings' ) && current_user_can( minn_admin_duplicator_cap() );
}


/**
 * A package's archive and installer, for the download door. The installer
 * sits on disk under their server-side extension (.php.bak) and downloads
 * under the name their screen gives it.
 */
function minn_admin_duplicator_download_files( $id ) {
	if ( ! minn_admin_duplicator_can_build() ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to download backups.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	global $wpdb;
	$table = minn_admin_duplicator_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, hash FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $row ) {
		return new WP_Error( 'not_found', __( 'Package not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( minn_admin_duplicator_is_v5() ) {
		return minn_admin_duplicator_v5_download_files( (int) $id, $row );
	}
	$root    = DUP_Settings::getSsdirPath();
	$package = DUP_Package::getByID( (int) $id );
	// Their package object names its files; the table row's name and hash
	// name the same files when the stored object is missing that (their
	// files are {name}_{hash}_archive.zip and {name}_{hash}_installer.php.bak).
	$stem    = $row['name'] . '_' . $row['hash'];
	$zip     = $package ? $package->getLocalPackageFile( DUP_PackageFileType::Archive ) : null;
	if ( ! $zip ) {
		$found = glob( $root . '/' . $stem . '_archive.*' );
		$zip   = $found ? $found[0] : null;
	}
	$inst = $package ? $package->getLocalPackageFile( DUP_PackageFileType::Installer ) : null;
	if ( ! $inst ) {
		$found = glob( $root . '/' . $stem . '_installer.*' );
		$inst  = $found ? $found[0] : null;
	}
	$files = array();
	if ( $zip ) {
		$files[] = array( 'part' => 'archive', 'name' => basename( $zip ), 'label' => __( 'Archive', 'minn-admin' ), 'path' => $zip, 'root' => $root );
	}
	if ( $inst ) {
		$name = $package && method_exists( $package, 'getInstDownloadName' ) ? (string) $package->getInstDownloadName() : '';
		if ( '' === $name || false === strpos( $name, 'installer' ) ) {
			$name = $stem . '_installer.php';
		}
		$files[] = array( 'part' => 'installer', 'name' => $name, 'label' => __( 'Installer', 'minn-admin' ), 'path' => $inst, 'root' => $root );
	}
	return $files;
}

/**
 * The 5.0 download door. Their package object names both files' full paths
 * (getLocalPackageFilePath), so nothing has to reconstruct a stem here; the
 * glob fallback stays for a package whose stored object no longer loads.
 */
function minn_admin_duplicator_v5_download_files( $id, $row ) {
	$package = minn_admin_duplicator_v5_package( $id );
	$root    = minn_admin_duplicator_ssdir();
	$stem    = $row['name'] . '_' . $row['hash'];
	$types   = array(
		'archive'   => array( \Duplicator\Package\AbstractPackage::FILE_TYPE_ARCHIVE, __( 'Archive', 'minn-admin' ) ),
		'installer' => array( \Duplicator\Package\AbstractPackage::FILE_TYPE_INSTALLER, __( 'Installer', 'minn-admin' ) ),
	);
	$files = array();
	foreach ( $types as $part => $spec ) {
		list( $type, $label ) = $spec;
		$path = null;
		if ( $package && method_exists( $package, 'getLocalPackageFilePath' ) ) {
			try {
				$path = $package->getLocalPackageFilePath( $type );
			} catch ( \Throwable $e ) {
				$path = null;
			}
		}
		if ( ! $path || ! file_exists( $path ) ) {
			$found = glob( $root . '/' . $stem . '_' . $part . '.*' );
			$path  = $found ? $found[0] : null;
		}
		if ( ! $path ) {
			continue;
		}
		// The installer sits on disk under a server-side extension
		// (.php.bak) and must download under a plain one.
		$name = basename( $path );
		if ( 'installer' === $part ) {
			$name = preg_replace( '/\.bak$/', '', $name );
			if ( '' === $name || false === strpos( $name, 'installer' ) ) {
				$name = $stem . '_installer.php';
			}
		}
		$files[] = array( 'part' => $part, 'name' => $name, 'label' => $label, 'path' => $path, 'root' => $root );
	}
	return $files;
}

/**
 * Start a 5.0 backup. Everything the 1.5 path does by hand (replay the
 * wizard, scan, persist the file lists, then drive chunks) is one call here:
 * BackupRequestService owns the default template, the process lock and the
 * queue. Its WP_Error answers are better than anything this shim could
 * phrase, so they are passed through rather than restated.
 */
function minn_admin_duplicator_v5_build( $name, $db_only ) {
	if ( ! class_exists( '\\Duplicator\\Package\\BackupRequestService' ) ) {
		return new WP_Error( 'unsupported', __( 'This version of Duplicator cannot start a backup from here.', 'minn-admin' ), array( 'status' => 501 ) );
	}
	try {
		$service = new \Duplicator\Package\BackupRequestService();
		$action  = $db_only
			? \Duplicator\Package\Create\BuildComponents::COMP_ACTION_DB
			: \Duplicator\Package\Create\BuildComponents::COMP_ACTION_ALL;
		$result  = $service->request( 'Minn Admin', '' !== $name ? $name : __( 'Requested from Minn', 'minn-admin' ), $action );
	} catch ( \Throwable $e ) {
		return new WP_Error( 'build_failed', __( 'Duplicator could not start the backup: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$package_id = (int) $result;
	$token      = wp_generate_password( 12, false );
	set_transient( 'minn_duplicator_job_' . $token, array( 'started' => time(), 'package_id' => $package_id ), 6 * HOUR_IN_SECONDS );
	return rest_ensure_response( array(
		'ok'  => true,
		'job' => array(
			'id'           => $token,
			'label'        => __( 'Building package', 'minn-admin' ),
			'message'      => __( 'Queued. Building…', 'minn-admin' ),
			'statusRoute'  => 'minn-admin/v1/duplicator/build/' . $token,
			'statusMethod' => 'POST',
			'stopRoute'    => 'minn-admin/v1/duplicator/build/' . $token,
			'stopMethod'   => 'DELETE',
		),
	) );
}

/**
 * Where a 5.0 build stands. Duplicator drives its own runner, so a poll only
 * reports: it never advances a chunk the way the 1.5 path has to.
 */
function minn_admin_duplicator_v5_progress( $package_id ) {
	if ( ! $package_id || ! class_exists( '\\Duplicator\\Package\\BackupRequestService' ) ) {
		return array( 'status' => 'error', 'message' => __( 'Unknown build.', 'minn-admin' ) );
	}
	try {
		$service = new \Duplicator\Package\BackupRequestService();
		$state   = $service->getStatus( (int) $package_id );
	} catch ( \Throwable $e ) {
		return array( 'status' => 'error', 'message' => __( 'Duplicator build failed: ', 'minn-admin' ) . $e->getMessage() );
	}
	if ( is_wp_error( $state ) ) {
		return array( 'status' => 'error', 'message' => $state->get_error_message() );
	}
	$their   = isset( $state['status'] ) ? (string) $state['status'] : '';
	$message = isset( $state['message'] ) && '' !== $state['message']
		? (string) $state['message']
		: __( 'Building…', 'minn-admin' );
	if ( \Duplicator\Package\BackupRequestService::STATUS_COMPLETE === $their ) {
		return array( 'status' => 'done', 'percent' => 100, 'message' => __( 'Package built.', 'minn-admin' ) );
	}
	if ( \Duplicator\Package\BackupRequestService::STATUS_CANCELLED === $their ) {
		return array( 'status' => 'canceled', 'message' => __( 'Build stopped.', 'minn-admin' ) );
	}
	if ( \Duplicator\Package\BackupRequestService::STATUS_FAILED === $their
		|| \Duplicator\Package\BackupRequestService::STATUS_MISSING === $their ) {
		return array( 'status' => 'error', 'message' => $message );
	}
	// Queued, running or cancelling: their package status is the same 0-100
	// scale the 1.5 path reported, so the bar reads the same either way.
	$percent = 1;
	$package = minn_admin_duplicator_v5_package( $package_id );
	if ( $package && method_exists( $package, 'getStatus' ) ) {
		$percent = max( 1, min( 99, (int) $package->getStatus() ) );
	}
	return array( 'status' => 'running', 'percent' => $percent, 'message' => $message );
}

/**
 * A database-only scan walks no files and leaves no file or folder list
 * behind, yet their installer step embeds both and stops when either is
 * missing. Empty lists are what a scan with the root filtered produces,
 * so leave them in place.
 */
function minn_admin_duplicator_ensure_lists( $package ) {
	if ( ! $package || empty( $package->Archive->ExportOnlyDB ) || ! method_exists( $package, 'get_files_list_filename' ) ) {
		return;
	}
	foreach ( array( $package->get_files_list_filename(), $package->get_dirs_list_filename() ) as $name ) {
		$path = DUP_Settings::getSsdirTmpPath() . '/' . $name;
		if ( ! file_exists( $path ) ) {
			touch( $path );
		}
	}
}

/**
 * One build chunk for the job record, mirroring their two ajax build
 * handlers. DupArchive builds are chunked (runDupArchiveBuild() answers
 * whether it finished); a ZipArchive build runs in one call, as theirs
 * does. The package's Status is their own progress scale (10 start, 20
 * database, 40 archive, 60 validation, 65 installer, 100 complete).
 *
 * @return array Minn job status (+ _package_id for the caller to remember).
 */
function minn_admin_duplicator_build_step( $rec ) {
	$labels = array(
		DUP_PackageStatus::START         => __( 'Starting the build…', 'minn-admin' ),
		DUP_PackageStatus::DBSTART       => __( 'Exporting the database…', 'minn-admin' ),
		DUP_PackageStatus::DBDONE        => __( 'Database exported. Archiving files…', 'minn-admin' ),
		DUP_PackageStatus::ARCSTART      => __( 'Archiving files…', 'minn-admin' ),
		DUP_PackageStatus::ARCVALIDATION => __( 'Validating the archive…', 'minn-admin' ),
		DUP_PackageStatus::ARCDONE       => __( 'Archive built. Writing the installer…', 'minn-admin' ),
	);
	$id      = ! empty( $rec['package_id'] ) ? (int) $rec['package_id'] : 0;
	$package = $id ? DUP_Package::getByID( $id ) : null;
	if ( ! $package ) {
		$package = DUP_Package::getActive();
		if ( empty( $package->ScanFile ) || ! is_readable( DUP_Settings::getSsdirTmpPath() . '/' . $package->ScanFile ) ) {
			return array( 'status' => 'error', 'message' => __( 'The scan result is missing; start the build again.', 'minn-admin' ) );
		}
		$zip = class_exists( 'DUP_Archive_Build_Mode' ) && $package->Archive->getBuildMode() == DUP_Archive_Build_Mode::ZipArchive; // phpcs:ignore Universal.Operators.StrictComparisons
		$package->save( $zip ? 'zip' : 'daf' ); // creates the packages row; their ajax does the same on the first call
		DUP_Settings::Set( 'active_package_id', $package->ID );
		DUP_Settings::Save();
		$id = (int) $package->ID;
	}
	if ( (int) $package->Status === DUP_PackageStatus::ERROR ) {
		return array( 'status' => 'error', 'message' => __( 'Duplicator reported a build error. Check its log.', 'minn-admin' ), '_package_id' => $id );
	}
	if ( (int) $package->Status >= DUP_PackageStatus::COMPLETE ) {
		return array( 'status' => 'done', 'percent' => 100, 'message' => __( 'Package built.', 'minn-admin' ), '_package_id' => $id );
	}
	$zip = class_exists( 'DUP_Archive_Build_Mode' ) && $package->Archive->getBuildMode() == DUP_Archive_Build_Mode::ZipArchive; // phpcs:ignore Universal.Operators.StrictComparisons
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( $zip ) {
		minn_admin_duplicator_ensure_lists( $package );
		$package->runZipBuild(); // their one-shot ZipArchive build
	} else {
		$package->runDupArchiveBuild(); // one chunk; their loop calls this until it reports complete
	}
	$package = DUP_Package::getByID( $id );
	$status  = $package ? (int) $package->Status : DUP_PackageStatus::ERROR;
	if ( DUP_PackageStatus::ERROR === $status ) {
		return array( 'status' => 'error', 'message' => __( 'Duplicator reported a build error. Check its log.', 'minn-admin' ), '_package_id' => $id );
	}
	if ( $status >= DUP_PackageStatus::COMPLETE ) {
		return array( 'status' => 'done', 'percent' => 100, 'message' => __( 'Package built.', 'minn-admin' ), '_package_id' => $id );
	}
	$text = __( 'Building…', 'minn-admin' );
	foreach ( $labels as $at => $label ) {
		if ( $status >= $at ) {
			$text = $label;
		}
	}
	return array( 'status' => 'running', 'percent' => max( 1, min( 99, $status ) ), 'message' => $text, '_package_id' => $id );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_duplicator_active() ) {
		return $surfaces;
	}

	$surfaces['duplicator'] = array(
		'label'      => __( 'Backups', 'minn-admin' ),
		'sub'        => 'Duplicator',
		'icon'       => 'database',
		'cap'        => 'export',
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/duplicator/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/duplicator/packages',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			// 5.0 dropped the owner column and keeps no equivalent, so on those
			// sites the By column could never be filled. An always-empty column
			// reads as a stray dash once the list stacks on a phone and the
			// labels are gone, so it is not offered there at all.
			'columns'   => array_values( array_filter( array(
				array( 'key' => 'name', 'label' => __( 'Package', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				minn_admin_duplicator_is_v5()
					? null
					: array( 'key' => 'owner', 'label' => __( 'By', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago' ),
			) ) ),
			'detail'    => array(
				'skip' => array( 'name', 'installer' ),
			),
			'actions'   => array(
				// The archive and the installer that goes with it, the pair
				// their Packages screen offers; nothing until a build finished.
				array( 'label' => __( 'Download archive', 'minn-admin' ), 'href' => minn_admin_backup_download_url( 'duplicator', '{id}', 'archive' ), 'when' => array( 'key' => 'status', 'equals' => 'completed' ) ),
				array( 'label' => __( 'Download installer', 'minn-admin' ), 'href' => minn_admin_backup_download_url( 'duplicator', '{id}', 'installer' ), 'when' => array( 'key' => 'installer', 'equals' => 'yes' ) ),
			),
		),
	);
	// Delete sits on their CAP_CREATE rung (their packageDelete ajax), so a
	// Backup-Read-only user never sees a button the route would refuse.
	if ( minn_admin_duplicator_can_build() ) {
		$surfaces['duplicator']['collection']['actions'][] = array(
			'label'   => __( 'Delete package', 'minn-admin' ),
			'method'  => 'DELETE',
			'route'   => 'minn-admin/v1/duplicator/packages/{id}',
			'confirm' => __( 'Delete this package and its archive files permanently?', 'minn-admin' ),
			'danger'  => true,
		);
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_duplicator_active() ) {
		return;
	}
	$perm = function () {
		// Network-shared packages table (see minn_admin_duplicator_active).
		if ( ! Minn_Admin::network_owner() ) {
			return false;
		}
		// 5.0 replaced the filtered capability NAME with a capability
		// MANAGER, and maps its own duplicator_* caps onto roles. Asking
		// current_user_can('export') there would leave a site that had
		// narrowed Duplicator wide open through Minn, which is the whole
		// reason the 1.5 branch goes through their filter rather than
		// restating a literal.
		if ( minn_admin_duplicator_is_v5() ) {
			return minn_admin_duplicator_v5_can( \Duplicator\Core\CapMng::CAP_BASIC );
		}
		return current_user_can( minn_admin_duplicator_cap() );
	};

	register_rest_route( 'minn-admin/v1', '/duplicator/packages', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$all      = minn_admin_duplicator_rows();
			return rest_ensure_response( array(
				'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $all ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/duplicator/packages/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		// Their packageDelete ajax asks CAP_CREATE, one rung above the
		// CAP_BASIC the list reads at; the build gate already resolves to
		// that rung on 5.0 and to their filtered cap on 1.5.
		'permission_callback' => 'minn_admin_duplicator_can_build',
		'callback'            => function ( WP_REST_Request $request ) {
			// Duplicator's own loader + delete() — its file cleanup, not a
			// re-guess. (Their getByID unserializes their own blob; this
			// shim never does.) getByID does NOT copy the row id onto the
			// object — delete() queries WHERE id = $this->ID, so a blob
			// whose stored ID drifted (site clones, fixtures) silently
			// no-ops. Pin it, and verify the row is really gone.
			global $wpdb;
			$id = (int) Minn_Admin::path_param( $request );
			try {
				$package = minn_admin_duplicator_is_v5()
					? minn_admin_duplicator_v5_package( $id )
					: DUP_Package::getByID( $id );
				if ( ! $package ) {
					return new WP_Error( 'not_found', __( 'Package not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// 1.5's getByID did not copy the row id onto the object, so
				// delete() could no-op against a drifted stored ID and had to
				// be pinned. 5.0 hydrates the id itself and made the property
				// protected, so pinning it there is both unnecessary and fatal.
				if ( ! minn_admin_duplicator_is_v5() ) {
					$package->ID = $id;
				}
				$package->delete();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', __( 'Duplicator could not delete: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			$table = minn_admin_duplicator_table();
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return new WP_Error( 'delete_failed', __( 'Duplicator reported success but the package row is still there.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'deleted' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/duplicator/build', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_duplicator_can_build',
		'callback'            => function ( WP_REST_Request $request ) {
			$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
			$db_only = filter_var( $request->get_param( 'db_only' ), FILTER_VALIDATE_BOOLEAN );
			if ( minn_admin_duplicator_is_v5() ) {
				return minn_admin_duplicator_v5_build( $name, $db_only );
			}
			try {
				// Their wizard, step by step: save the active package from
				// the form defaults, scan, remember the scan file. Every
				// wizard field is sent, empty where the form would be empty,
				// because saveActive() reads them all.
				DUP_Util::initSnapshotDirectory();
				// A fresh package, not getActive(): the stored one comes back
				// unserialized with the previous run's private file-list
				// handles, so its scan writes the lists under the old name and
				// the build looks for them under the new one.
				$package = new DUP_Package();
				$package->saveActive( array(
					'package-name'           => '' !== $name ? $name : DUP_Package::getDefaultName(),
					'package-notes'          => '',
					'auto-select-components' => $db_only ? 'database' : '',
					'dbhost'                 => '',
					'dbport'                 => '',
					'dbname'                 => '',
					'dbuser'                 => '',
					'dbcharset'              => '',
					'dbcollation'            => '',
					'secure-pass'            => '',
				) );
				$package = DUP_Package::getActive();
				$package->runScanner();
				minn_admin_duplicator_ensure_lists( $package );
				$package->saveActiveItem( 'ScanFile', $package->ScanFile );
				$package->Archive->saveActiveItem( $package, 'dirsCount', $package->Archive->dirsCount );
				$package->Archive->saveActiveItem( $package, 'filesCount', $package->Archive->filesCount );
				DUP_Settings::Set( 'active_package_id', -1 );
				DUP_Settings::Save();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'scan_failed', __( 'Duplicator could not scan the site: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			$token = wp_generate_password( 12, false );
			set_transient( 'minn_duplicator_job_' . $token, array( 'started' => time(), 'package_id' => 0 ), 6 * HOUR_IN_SECONDS );
			return rest_ensure_response( array(
				'ok'  => true,
				'job' => array(
					'id'           => $token,
					'label'        => __( 'Building package', 'minn-admin' ),
					'message'      => __( 'Site scanned. Building…', 'minn-admin' ),
					'statusRoute'  => 'minn-admin/v1/duplicator/build/' . $token,
					'statusMethod' => 'POST',
					'stopRoute'    => 'minn-admin/v1/duplicator/build/' . $token,
					'stopMethod'   => 'DELETE',
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/duplicator/build/(?P<token>[A-Za-z0-9]{12})', array(
		array(
			// Each poll runs one build chunk, the way their screen's ajax loop
			// does, and answers with where the package stands.
			'methods'             => 'POST',
			'permission_callback' => 'minn_admin_duplicator_can_build',
			'callback'            => function ( WP_REST_Request $request ) {
				$rec = get_transient( 'minn_duplicator_job_' . $request['token'] );
				if ( ! is_array( $rec ) ) {
					return new WP_Error( 'not_found', __( 'Unknown build', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! empty( $rec['canceled'] ) ) {
					return rest_ensure_response( array( 'status' => 'canceled', 'message' => __( 'Build stopped.', 'minn-admin' ) ) );
				}
				if ( minn_admin_duplicator_is_v5() ) {
					return rest_ensure_response( minn_admin_duplicator_v5_progress( (int) $rec['package_id'] ) );
				}
				try {
					$out = minn_admin_duplicator_build_step( $rec );
				} catch ( \Throwable $e ) {
					$out = array( 'status' => 'error', 'message' => __( 'Duplicator build failed: ', 'minn-admin' ) . $e->getMessage() );
				}
				if ( ! empty( $out['_package_id'] ) && (int) $out['_package_id'] !== (int) $rec['package_id'] ) {
					$rec['package_id'] = (int) $out['_package_id'];
					set_transient( 'minn_duplicator_job_' . $request['token'], $rec, 6 * HOUR_IN_SECONDS );
				}
				unset( $out['_package_id'] );
				return rest_ensure_response( $out );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => 'minn_admin_duplicator_can_build',
			'callback'            => function ( WP_REST_Request $request ) {
				$rec = get_transient( 'minn_duplicator_job_' . $request['token'] );
				if ( ! is_array( $rec ) ) {
					return new WP_Error( 'not_found', __( 'Unknown build', 'minn-admin' ), array( 'status' => 404 ) );
				}
				try {
					if ( minn_admin_duplicator_is_v5() ) {
						// Their own cancellation: the runner sees it on its next
						// step and unwinds, rather than a status we forced.
						$package = minn_admin_duplicator_v5_package( (int) $rec['package_id'] );
						if ( $package && method_exists( $package, 'setForCancel' ) ) {
							$package->setForCancel();
						}
					} else {
						if ( ! empty( $rec['package_id'] ) ) {
							$package = DUP_Package::getByID( (int) $rec['package_id'] );
							if ( $package ) {
								$package->setStatus( DUP_PackageStatus::ERROR ); // their stop: the package reads as failed and can be deleted
							}
						}
						DUP_Settings::Set( 'active_package_id', -1 );
						DUP_Settings::Save();
					}
				} catch ( \Throwable $e ) {
					// The token is what stops the next chunk either way.
				}
				$rec['canceled'] = true;
				set_transient( 'minn_duplicator_job_' . $request['token'], $rec, 6 * HOUR_IN_SECONDS );
				return rest_ensure_response( array( 'ok' => true, 'status' => 'canceled', 'message' => __( 'Build stopped.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/duplicator/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$rows      = minn_admin_duplicator_rows();
			$completed = array_values( array_filter( $rows, function ( $r ) {
				return 'completed' === $r['status'];
			} ) );
			$disk = 0;
			$dir  = minn_admin_duplicator_ssdir();
			if ( is_dir( $dir ) ) {
				foreach ( (array) glob( $dir . '/*' ) as $file ) {
					if ( is_file( $file ) ) {
						$disk += (int) @filesize( $file );
					}
				}
			}
			$newest = $completed ? $completed[0] : null;
			$gmt    = minn_admin_duplicator_dates_are_gmt();
			$when   = '';
			if ( $newest && $newest['created'] ) {
				$ts   = strtotime( $gmt ? $newest['created'] : get_gmt_from_date( $newest['created'] ) . 'Z' );
				/* translators: %s: human-readable elapsed time. */
				$when = $ts ? sprintf( __( '%s ago', 'minn-admin' ), human_time_diff( $ts ) ) : '';
			}
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => __( 'Newest package', 'minn-admin' ),
						'value' => $newest ? $newest['name'] : __( 'None yet', 'minn-admin' ),
						'hint'  => $newest ? trim( $when . ' · ' . $newest['size'], ' ·' ) : 'Packages are built manually from Duplicator\'s screen.',
					),
					array(
						'label' => __( 'Packages', 'minn-admin' ),
						'value' => (string) count( $rows ),
						'hint'  => __( 'Manual builds; Minn makes no freshness claims for Duplicator.', 'minn-admin' ),
					),
					array(
						'label' => __( 'On disk', 'minn-admin' ),
						'value' => $disk ? size_format( $disk ) : '0 B',
						'hint'  => basename( $dir ),
					),
				),
				'actions' => array_values( array_filter( array(
					minn_admin_duplicator_can_build() ? array(
						'label'  => __( 'Build a package', 'minn-admin' ),
						'route'  => 'minn-admin/v1/duplicator/build',
						'method' => 'POST',
						'job'    => true,
						'fields' => array(
							array( 'key' => 'name', 'label' => __( 'Package name', 'minn-admin' ), 'value' => '', 'placeholder' => __( 'Today\'s date and the site name', 'minn-admin' ), 'required' => false ),
							array( 'key' => 'db_only', 'label' => __( 'Database only', 'minn-admin' ), 'type' => 'toggle', 'value' => false, 'required' => false ),
						),
					) : null,
					array( 'label' => __( 'Open Duplicator ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=duplicator' ) ),
				) ) ),
			) );
		},
	) );
} );
