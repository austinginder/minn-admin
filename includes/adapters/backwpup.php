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
		$last_value = 'Running now…';
		$last_hint  = 'BackWPup is building a backup';
	} elseif ( $last > 0 ) {
		$last_value = human_time_diff( $last ) . ' ago';
		$last_hint  = 'Last finished job run';
	} else {
		$last_value = 'Never';
		$last_hint  = 'No finished job run recorded yet';
	}

	$actions = array();
	// Offer run-now for the first FOLDER job (the install default "First backup").
	if ( $jobs ) {
		$actions[] = array(
			'label'   => 'Run first job now',
			'route'   => 'minn-admin/v1/backwpup/run',
			'method'  => 'POST',
			'body'    => array( 'jobid' => $jobs[0] ),
			'confirm' => 'Start the BackWPup job now? It runs in the background through BackWPup\'s own runner.',
		);
	}
	$actions[] = array(
		'label' => 'Open BackWPup ↗',
		'href'  => admin_url( 'admin.php?page=backwpup' ),
	);

	return array(
		'rows'    => array(
			array(
				'label' => 'Last run',
				'value' => $last_value,
				'hint'  => $last_hint,
			),
			array(
				'label' => 'Local archives',
				'value' => (string) count( $rows ),
				'hint'  => $disk ? size_format( $disk ) . ' on disk' : 'Nothing in the local folder yet',
			),
			array(
				'label' => 'Jobs (local folder)',
				'value' => (string) count( $jobs ),
				'hint'  => 'Only jobs that write to Website Server are listed',
			),
			array(
				'label' => 'Status',
				'value' => $running ? 'Running' : 'Idle',
				'hint'  => 'Jobs run through BackWPup\'s own cron/auth machinery',
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
			'label'   => 'Delete archive',
			'method'  => 'DELETE',
			'route'   => 'minn-admin/v1/backwpup/backups/{id}',
			'confirm' => 'Delete this backup archive from the local folder permanently?',
			'danger'  => true,
		);
	}

	$surfaces['backwpup'] = array(
		'label'      => 'Backups',
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
				array( 'key' => 'filename', 'label' => 'Archive', 'format' => 'title' ),
				array( 'key' => 'size', 'label' => 'Size', 'format' => 'text' ),
				array( 'key' => 'job', 'label' => 'Job', 'format' => 'text' ),
				array( 'key' => 'date', 'label' => 'Created', 'format' => 'ago', 'utc' => true ),
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
	$perm = function () {
		return current_user_can( 'backwpup_backups' ) || current_user_can( 'manage_options' );
	};
	$perm_delete = function () {
		return current_user_can( 'backwpup_backups_delete' ) || current_user_can( 'manage_options' );
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
				return new WP_Error( 'bad_id', 'Invalid backup id.', array( 'status' => 400 ) );
			}
			$jobid    = (int) substr( $raw, 0, $pos );
			$filename = substr( $raw, $pos + 1 );
			if ( $jobid < 1 || '' === $filename || false !== strpos( $filename, '..' ) || false !== strpos( $filename, '/' ) || false !== strpos( $filename, '\\' ) ) {
				return new WP_Error( 'bad_id', 'Invalid backup id.', array( 'status' => 400 ) );
			}
			try {
				$dest = BackWPup::get_destination( 'FOLDER' );
				if ( ! $dest || ! method_exists( $dest, 'file_delete' ) ) {
					return new WP_Error( 'no_dest', 'BackWPup folder destination unavailable.', array( 'status' => 500 ) );
				}
				$dest->file_delete( $jobid . '_FOLDER', $filename );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', 'BackWPup could not delete: ' . $e->getMessage(), array( 'status' => 500 ) );
			}
			// Confirm gone from the list.
			foreach ( minn_admin_backwpup_rows() as $r ) {
				if ( $r['id'] === $jobid . ':' . $filename ) {
					return new WP_Error( 'delete_failed', 'BackWPup reported success but the archive is still listed.', array( 'status' => 500 ) );
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
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$jobid = (int) ( $request->get_param( 'jobid' ) ?: 0 );
			$jobs  = minn_admin_backwpup_folder_job_ids();
			if ( $jobid < 1 ) {
				$jobid = $jobs ? $jobs[0] : 0;
			}
			if ( ! in_array( $jobid, $jobs, true ) ) {
				return new WP_Error( 'bad_job', 'Unknown or non-folder BackWPup job.', array( 'status' => 400 ) );
			}
			if ( minn_admin_backwpup_running() ) {
				return rest_ensure_response( array(
					'ok'      => true,
					'message' => 'A BackWPup job is already running.',
				) );
			}
			try {
				// Same kick their Jobs screen uses for "run now".
				BackWPup_Job::get_jobrun_url( 'runnow', $jobid );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'run_failed', 'BackWPup could not start the job: ' . $e->getMessage(), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'Backup job started in the background.',
			) );
		},
	) );
} );
