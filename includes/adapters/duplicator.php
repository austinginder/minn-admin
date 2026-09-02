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

function minn_admin_duplicator_active() {
	global $wpdb;
	if ( ! defined( 'DUPLICATOR_VERSION' ) && ! class_exists( 'DUP_Package' ) ) {
		return false;
	}
	// Duplicator keeps ONE packages table on base_prefix and its archives
	// hold the WHOLE network's database — on multisite that's super-admin
	// data (its own menu lives in Network Admin there).
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	$table = $wpdb->base_prefix . 'duplicator_packages';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

/** Their created-column quirk: UTC exactly when the site offset is truthy. */
function minn_admin_duplicator_dates_are_gmt() {
	return (bool) get_option( 'gmt_offset', 1 );
}

/** Duplicator's storage dir (wp-content/backups-dup-lite by default). */
function minn_admin_duplicator_ssdir() {
	try {
		if ( class_exists( 'DUP_Settings' ) && method_exists( 'DUP_Settings', 'getSsdirPath' ) ) {
			return (string) DUP_Settings::getSsdirPath();
		}
	} catch ( \Throwable $e ) {
		// Fall through to the default location.
	}
	return WP_CONTENT_DIR . '/backups-dup-lite';
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
	$table = $wpdb->base_prefix . 'duplicator_packages';
	$rows  = $wpdb->get_results( "SELECT id, name, hash, status, created, owner FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$gmt   = minn_admin_duplicator_dates_are_gmt();
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
			'owner'   => (string) $r->owner,
			'created' => $gmt ? str_replace( ' ', 'T', (string) $r->created ) . 'Z' : (string) $r->created,
		);
	}
	return $items;
}

/** Their build capability (DUP_Util::hasCapability('export') on every build ajax handler). */
function minn_admin_duplicator_can_build() {
	return minn_admin_duplicator_active() && class_exists( 'DUP_Package' ) && class_exists( 'DUP_Settings' ) && current_user_can( 'export' );
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
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, hash FROM {$wpdb->base_prefix}duplicator_packages WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	if ( ! $row ) {
		return new WP_Error( 'not_found', __( 'Package not found.', 'minn-admin' ), array( 'status' => 404 ) );
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
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Package', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'owner', 'label' => __( 'By', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'skip' => array( 'name', 'installer' ),
			),
			'actions'   => array(
				// The archive and the installer that goes with it, the pair
				// their Packages screen offers; nothing until a build finished.
				array( 'label' => __( 'Download archive', 'minn-admin' ), 'href' => minn_admin_backup_download_url( 'duplicator', '{id}', 'archive' ), 'when' => array( 'key' => 'status', 'equals' => 'completed' ) ),
				array( 'label' => __( 'Download installer', 'minn-admin' ), 'href' => minn_admin_backup_download_url( 'duplicator', '{id}', 'installer' ), 'when' => array( 'key' => 'installer', 'equals' => 'yes' ) ),
				array(
					'label'   => __( 'Delete package', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/duplicator/packages/{id}',
					'confirm' => __( 'Delete this package and its archive files permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_duplicator_active() ) {
		return;
	}
	$perm = function () {
		// Network-shared packages table (see minn_admin_duplicator_active).
		return current_user_can( 'export' ) && Minn_Admin::network_owner();
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
		'permission_callback' => $perm,
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
				$package = DUP_Package::getByID( $id );
				if ( ! $package ) {
					return new WP_Error( 'not_found', __( 'Package not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$package->ID = $id;
				$package->delete();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', __( 'Duplicator could not delete: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			$table = $wpdb->base_prefix . 'duplicator_packages';
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
					if ( ! empty( $rec['package_id'] ) ) {
						$package = DUP_Package::getByID( (int) $rec['package_id'] );
						if ( $package ) {
							$package->setStatus( DUP_PackageStatus::ERROR ); // their stop: the package reads as failed and can be deleted
						}
					}
					DUP_Settings::Set( 'active_package_id', -1 );
					DUP_Settings::Save();
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
