<?php
/**
 * Bundled adapter: BackWPup — backups family.
 *
 * Lists archives from every job that stores to the local FOLDER destination
 * through BackWPup_Destination_Folder::file_get_list(), so sizes and mtimes
 * come from the files on disk (never reinvented). Delete goes through their
 * own file_delete(). Backup-now fires their runnow job URL via
 * BackWPup_Job::get_jobrun_url() — same kick their Jobs screen uses —
 * without holding the REST request open for the whole run.
 *
 * Caps honor BackWPup's own model: backwpup_backups (list),
 * backwpup_backups_delete (delete). Admins get those on install.
 *
 * Restores stay in wp-admin. Remote destinations (S3, Dropbox, …) stay on
 * BackWPup's screen — only the local folder is listed here.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_backwpup_active() {
	// BackWPup keeps its job registry in the `backwpup_jobs` SITE option and
	// writes archives to one network-shared folder, so on multisite the jobs
	// and their backups belong to the network owner. The plugin registers its
	// whole admin under network_admin_menu there, which means a subsite
	// administrator has no BackWPup screen at all.
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	return defined( 'BACKWPUP_PLUGIN_LOADED' )
		&& class_exists( 'BackWPup' )
		&& class_exists( 'BackWPup_Option' )
		&& class_exists( 'BackWPup_Job' );
}

/** Job ids that write to the local FOLDER destination. */
function minn_admin_backwpup_folder_job_ids() {
	if ( ! minn_admin_backwpup_active() ) {
		return array();
	}
	$ids = array();
	foreach ( (array) BackWPup_Option::get_job_ids() as $jobid ) {
		$jobid = (int) $jobid;
		if ( $jobid < 1 ) {
			continue;
		}
		$dests = BackWPup_Option::get( $jobid, 'destinations' );
		if ( is_array( $dests ) && in_array( 'FOLDER', $dests, true ) ) {
			$ids[] = $jobid;
		}
	}
	return $ids;
}

/**
 * Display rows for local FOLDER archives across every job, newest first.
 * Id shape: "{jobid}:{filename}" so delete can target the right jobdest.
 */
function minn_admin_backwpup_rows() {
	if ( ! minn_admin_backwpup_active() ) {
		return array();
	}
	try {
		$dest = BackWPup::get_destination( 'FOLDER' );
	} catch ( \Throwable $e ) {
		return array();
	}
	if ( ! $dest || ! method_exists( $dest, 'file_get_list' ) ) {
		return array();
	}

	$seen  = array();
	$items = array();
	foreach ( minn_admin_backwpup_folder_job_ids() as $jobid ) {
		$jobdest = $jobid . '_FOLDER';
		try {
			$files = $dest->file_get_list( $jobdest );
		} catch ( \Throwable $e ) {
			continue;
		}
		$job_name = (string) BackWPup_Option::get( $jobid, 'name' );
		if ( ! $job_name ) {
			$job_name = 'Job ' . $jobid;
		}
		foreach ( (array) $files as $file ) {
			$filename = isset( $file['filename'] ) ? (string) $file['filename'] : '';
			$path     = isset( $file['file'] ) ? (string) $file['file'] : '';
			if ( ! $filename ) {
				continue;
			}
			// Same archive can appear under multiple jobs sharing a folder —
			// keep the first (newest-sort later), note the job that listed it.
			$key = $path ? $path : ( $jobid . '|' . $filename );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$size         = isset( $file['filesize'] ) ? (int) $file['filesize'] : 0;
			$time         = isset( $file['time'] ) ? (int) $file['time'] : 0;
			$items[]      = array(
				'id'       => $jobid . ':' . $filename,
				'filename' => $filename,
				'job'      => $job_name,
				'jobid'    => $jobid,
				'size'     => $size ? size_format( $size ) : '—',
				'size_raw' => $size,
				'date'     => $time ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : '',
				'ts'       => $time,
			);
		}
	}
	usort( $items, function ( $a, $b ) {
		return (int) $b['ts'] - (int) $a['ts'];
	} );
	return $items;
}

/** Newest lastrun across jobs (UTC epoch) for the status card. */
function minn_admin_backwpup_last_run() {
	$latest = 0;
	foreach ( minn_admin_backwpup_folder_job_ids() as $jobid ) {
		$run = (int) BackWPup_Option::get( $jobid, 'lastrun' );
		if ( $run > $latest ) {
			$latest = $run;
		}
	}
	return $latest;
}

/** True when a job is currently running (their working-data transient). */
function minn_admin_backwpup_running() {
	try {
		$data = BackWPup_Job::get_working_data();
		return ! empty( $data );
	} catch ( \Throwable $e ) {
		return false;
	}
}

function minn_admin_backwpup_status_model() {
	$rows    = minn_admin_backwpup_rows();
	$last    = minn_admin_backwpup_last_run();
	$running = minn_admin_backwpup_running();
	$jobs    = minn_admin_backwpup_folder_job_ids();
	$disk    = 0;
	foreach ( $rows as $r ) {
		$disk += (int) ( $r['size_raw'] ?? 0 );
	}

	if ( $running ) {
		$last_value = __( 'Running now…', 'minn-admin' );
		$last_hint  = __( 'BackWPup is building a backup', 'minn-admin' );
	} elseif ( $last > 0 ) {
		$last_value = sprintf(
			/* translators: %s: human-readable time since the last finished job run. */
			__( '%s ago', 'minn-admin' ),
			human_time_diff( $last )
		);
		$last_hint  = __( 'Last finished job run', 'minn-admin' );
	} else {
		$last_value = __( 'Never', 'minn-admin' );
		$last_hint  = __( 'No finished job run recorded yet', 'minn-admin' );
	}

	$actions = array();
	// Offer run-now for the first FOLDER job (the install default "First
	// backup"), and only to users who hold BackWPup's own start capability —
	// otherwise the card advertises a button the route will refuse.
	if ( $jobs && ( current_user_can( 'backwpup_jobs_start' ) || current_user_can( 'manage_options' ) ) ) {
		$actions[] = array(
			'label'   => __( 'Run first job now', 'minn-admin' ),
			'route'   => 'minn-admin/v1/backwpup/run',
			'method'  => 'POST',
			'body'    => array( 'jobid' => $jobs[0] ),
			'confirm' => __( 'Start the BackWPup job now? It runs in the background through BackWPup\'s own runner.', 'minn-admin' ),
		);
	}
	$actions[] = array(
		'label' => __( 'Open BackWPup ↗', 'minn-admin' ),
		'href'  => admin_url( 'admin.php?page=backwpup' ),
	);

	return array(
		'rows'    => array(
			array(
				'label' => __( 'Last run', 'minn-admin' ),
				'value' => $last_value,
				'hint'  => $last_hint,
			),
			array(
				'label' => __( 'Local archives', 'minn-admin' ),
				'value' => (string) count( $rows ),
				'hint'  => $disk
					? sprintf(
						/* translators: %s: total size of local backup archives. */
						__( '%s on disk', 'minn-admin' ),
						size_format( $disk )
					)
					: __( 'Nothing in the local folder yet', 'minn-admin' ),
			),
			array(
				'label' => __( 'Jobs (local folder)', 'minn-admin' ),
				'value' => (string) count( $jobs ),
				'hint'  => __( 'Only jobs that write to Website Server are listed', 'minn-admin' ),
			),
			array(
				'label' => __( 'Status', 'minn-admin' ),
				'value' => $running ? 'Running' : 'Idle',
				'hint'  => __( 'Jobs run through BackWPup\'s own cron/auth machinery', 'minn-admin' ),
			),
		),
		'actions' => $actions,
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_backwpup_active() ) {
		return $surfaces;
	}
	if ( ! current_user_can( 'backwpup_backups' ) && ! current_user_can( 'manage_options' ) ) {
		return $surfaces;
	}

	$can_delete = current_user_can( 'backwpup_backups_delete' ) || current_user_can( 'manage_options' );
	$actions    = array();
	if ( $can_delete ) {
		$actions[] = array(
			'label'   => __( 'Delete archive', 'minn-admin' ),
			'method'  => 'DELETE',
			'route'   => 'minn-admin/v1/backwpup/backups/{id}',
			'confirm' => __( 'Delete this backup archive from the local folder permanently?', 'minn-admin' ),
			'danger'  => true,
		);
	}

	$surfaces['backwpup'] = array(
		'label'      => __( 'Backups', 'minn-admin' ),
		'sub'        => 'BackWPup',
		'icon'       => 'database',
		// Cap is loose; routes re-check BackWPup's own caps.
		'cap'        => 'read',
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/backwpup/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/backwpup/backups',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'filename', 'label' => __( 'Archive', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'job', 'label' => __( 'Job', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'date', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'filename', 'size_raw', 'ts', 'jobid' ),
			),
			'actions'   => $actions,
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_backwpup_active() ) {
		return;
	}
	// Network-shared job registry and archive folder (see
	// minn_admin_backwpup_active): the per-action BackWPup capabilities below
	// answer WHICH verb, this answers WHOSE data.
	$perm = function () {
		return ( current_user_can( 'backwpup_backups' ) || current_user_can( 'manage_options' ) )
			&& Minn_Admin::network_owner();
	};
	$perm_delete = function () {
		return ( current_user_can( 'backwpup_backups_delete' ) || current_user_can( 'manage_options' ) )
			&& Minn_Admin::network_owner();
	};
	// STARTING a job is a different capability from viewing the list.
	// BackWPup ships a real non-admin role, backwpup_check ("jobs checker"),
	// with backwpup_backups => true but backwpup_jobs_start => false
	// (inc/class-install.php), and gates Run-now on the latter
	// (inc/class-page-jobs.php). Using $perm here let that role kick full
	// backup runs on demand.
	$perm_run = function () {
		return ( current_user_can( 'backwpup_jobs_start' ) || current_user_can( 'manage_options' ) )
			&& Minn_Admin::network_owner();
	};

	register_rest_route( 'minn-admin/v1', '/backwpup/backups', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$all      = minn_admin_backwpup_rows();
			// Strip size_raw from the wire (internal for status card only).
			$slice = array_map( function ( $r ) {
				unset( $r['size_raw'] );
				return $r;
			}, array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) );
			return rest_ensure_response( array(
				'items' => $slice,
				'total' => count( $all ),
			) );
		},
	) );

	// Id is "{jobid}:{filename}" — filename may include dots; path allows it.
	register_rest_route( 'minn-admin/v1', '/backwpup/backups/(?P<id>.+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm_delete,
		'callback'            => function ( WP_REST_Request $request ) {
			$raw = rawurldecode( (string) $request['id'] );
			$pos = strpos( $raw, ':' );
			if ( false === $pos ) {
				return new WP_Error( 'bad_id', __( 'Invalid backup id.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$jobid    = (int) substr( $raw, 0, $pos );
			$filename = substr( $raw, $pos + 1 );
			if ( $jobid < 1 || '' === $filename || false !== strpos( $filename, '..' ) || false !== strpos( $filename, '/' ) || false !== strpos( $filename, '\\' ) ) {
				return new WP_Error( 'bad_id', __( 'Invalid backup id.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			try {
				$dest = BackWPup::get_destination( 'FOLDER' );
				if ( ! $dest || ! method_exists( $dest, 'file_delete' ) ) {
					return new WP_Error( 'no_dest', __( 'BackWPup folder destination unavailable.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$dest->file_delete( $jobid . '_FOLDER', $filename );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', __( 'BackWPup could not delete: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			// Confirm gone from the list.
			foreach ( minn_admin_backwpup_rows() as $r ) {
				if ( $r['id'] === $jobid . ':' . $filename ) {
					return new WP_Error( 'delete_failed', __( 'BackWPup reported success but the archive is still listed.', 'minn-admin' ), array( 'status' => 500 ) );
				}
			}
			return rest_ensure_response( array( 'deleted' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/backwpup/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_backwpup_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/backwpup/run', array(
		'methods'             => 'POST',
		'permission_callback' => $perm_run,
		'callback'            => function ( WP_REST_Request $request ) {
			$jobid = (int) ( $request->get_param( 'jobid' ) ?: 0 );
			$jobs  = minn_admin_backwpup_folder_job_ids();
			if ( $jobid < 1 ) {
				$jobid = $jobs ? $jobs[0] : 0;
			}
			if ( ! in_array( $jobid, $jobs, true ) ) {
				return new WP_Error( 'bad_job', __( 'Unknown or non-folder BackWPup job.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( minn_admin_backwpup_running() ) {
				return rest_ensure_response( array(
					'ok'      => true,
					'message' => __( 'A BackWPup job is already running.', 'minn-admin' ),
				) );
			}
			try {
				// Same kick their Jobs screen uses for "run now".
				BackWPup_Job::get_jobrun_url( 'runnow', $jobid );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'run_failed', __( 'BackWPup could not start the job: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => __( 'Backup job started in the background.', 'minn-admin' ),
			) );
		},
	) );
} );
