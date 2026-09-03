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

/** The one archive a row stands for ("{jobid}:{filename}"), for the download door. */
function minn_admin_backwpup_download_files( $id ) {
	// Their own download capability, the one their Backups screen checks.
	if ( ! minn_admin_backwpup_active() || ! current_user_can( 'backwpup_backups_download' ) || ! Minn_Admin::network_owner() ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to download backups.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	$pos = strpos( (string) $id, ':' );
	if ( false === $pos ) {
		return new WP_Error( 'not_found', __( 'Archive not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$jobid    = (int) substr( $id, 0, $pos );
	$filename = basename( substr( $id, $pos + 1 ) );
	$dest = null;
	try {
		$dest  = BackWPup::get_destination( 'FOLDER' );
		$files = $dest && method_exists( $dest, 'file_get_list' ) ? (array) $dest->file_get_list( $jobid . '_FOLDER' ) : array();
	} catch ( \Throwable $e ) {
		$files = array();
	}
	// The job's own configured folder, which is what file_get_list() walked.
	// dirname() of the file itself would ask whether a file is inside its own
	// directory, which is always true: the door's containment check is the one
	// defence this provider has and it has to be given a real base to check
	// against. Their stored value is relative to wp-content, so it goes through
	// their own resolver, the same call file_get_list() makes.
	$root = '';
	try {
		$stored = (string) BackWPup_Option::get( $jobid, 'backupdir' );
		if ( '' === $stored && $dest && method_exists( $dest, 'option_defaults' ) ) {
			$defaults = (array) $dest->option_defaults();
			$stored   = isset( $defaults['backupdir'] ) ? (string) $defaults['backupdir'] : '';
		}
		if ( '' !== $stored && class_exists( 'BackWPup_File' ) && method_exists( 'BackWPup_File', 'get_absolute_path' ) ) {
			$root = (string) BackWPup_File::get_absolute_path( $stored );
		}
	} catch ( \Throwable $e ) {
		$root = '';
	}
	if ( '' === $root ) {
		return new WP_Error( 'not_found', __( 'The backup folder could not be resolved.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	foreach ( $files as $file ) {
		if ( isset( $file['filename'], $file['file'] ) && (string) $file['filename'] === $filename ) {
			return array( array( 'part' => $filename, 'name' => $filename, 'path' => (string) $file['file'], 'root' => $root ) );
		}
	}
	return new WP_Error( 'not_found', __( 'Archive not found.', 'minn-admin' ), array( 'status' => 404 ) );
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
	if ( $jobs && current_user_can( 'backwpup_jobs_start' ) ) {
		$options = array();
		foreach ( $jobs as $jid ) {
			$name      = BackWPup_Option::get( $jid, 'name' );
			$options[] = array( (string) $jid, $name ? (string) $name : sprintf( /* translators: %d: job id */ __( 'Job %d', 'minn-admin' ), $jid ) );
		}
		$action = array(
			'label'  => __( 'Run job now', 'minn-admin' ),
			'route'  => 'minn-admin/v1/backwpup/run',
			'method' => 'POST',
			'job'    => true,
		);
		if ( count( $options ) > 1 ) {
			$action['fields'] = array( array( 'key' => 'jobid', 'label' => __( 'Job', 'minn-admin' ), 'type' => 'select', 'options' => $options, 'value' => $options[0][0] ) );
		} else {
			$action['body'] = array( 'jobid' => $jobs[0] );
		}
		$actions[] = $action;
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
	if ( ! current_user_can( 'backwpup_backups' ) ) {
		return $surfaces;
	}

	$can_delete = current_user_can( 'backwpup_backups_delete' );
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
			// Conditioned like Delete above and Run now on the status card:
			// their own screen hides it without this capability, and the door
			// refuses it, so offering it here only advertises a 403.
			'actions'   => array_merge(
				current_user_can( 'backwpup_backups_download' )
					? array( array( 'label' => __( 'Download', 'minn-admin' ), 'href' => minn_admin_backup_download_url( 'backwpup' ) ) )
					: array(),
				$actions
			),
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
		return current_user_can( 'backwpup_backups' )
			&& Minn_Admin::network_owner();
	};
	$perm_delete = function () {
		return current_user_can( 'backwpup_backups_delete' )
			&& Minn_Admin::network_owner();
	};
	// STARTING a job is a different capability from viewing the list.
	// BackWPup ships a real non-admin role, backwpup_check ("jobs checker"),
	// with backwpup_backups => true but backwpup_jobs_start => false
	// (inc/class-install.php), and gates Run-now on the latter
	// (inc/class-page-jobs.php). Using $perm here let that role kick full
	// backup runs on demand.
	$perm_run = function () {
		return current_user_can( 'backwpup_jobs_start' )
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
			$raw = rawurldecode( Minn_Admin::path_param( $request ) );
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

	register_rest_route( 'minn-admin/v1', '/backwpup/job/(?P<token>[A-Za-z0-9]{12})', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$rec = get_transient( 'minn_backwpup_job_' . $request['token'] );
				if ( ! is_array( $rec ) ) {
					return new WP_Error( 'not_found', __( 'Unknown backup job', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! empty( $rec['canceled'] ) ) {
					return rest_ensure_response( array( 'status' => 'canceled', 'message' => __( 'Backup stopped.', 'minn-admin' ) ) );
				}
				$started = (int) $rec['started'];
				$jobid   = (int) $rec['jobid'];
				$work    = null;
				try {
					$work = BackWPup_Job::get_working_data(); // their running-file snapshot
				} catch ( \Throwable $e ) {
					$work = null;
				}
				if ( $work && is_object( $work ) ) {
					$job_of = isset( $work->job['jobid'] ) ? (int) $work->job['jobid'] : 0;
					if ( ! $job_of || $job_of === $jobid ) {
						$percent = isset( $work->step_percent ) ? (int) $work->step_percent : null;
						$msg     = isset( $work->lastmsg ) ? trim( wp_strip_all_tags( (string) $work->lastmsg ) ) : '';
						$out     = array( 'status' => 'running', 'message' => '' !== $msg ? $msg : __( 'Working…', 'minn-admin' ) );
						if ( null !== $percent ) {
							$out['percent'] = max( 0, min( 100, $percent ) );
						}
						if ( ! empty( $work->user_abort ) ) {
							$out['message'] = __( 'Stopping…', 'minn-admin' );
						}
						return rest_ensure_response( $out );
					}
				}
				// Nothing running: finished when the job's lastrun stamp
				// moved past our start (they write it at job start, and the
				// running file is gone only once the job ended). lastrun is
				// their start_time = current_time( 'timestamp' ), a
				// site-local naive epoch, so it is shifted to UTC before the
				// compare.
				$lastrun = (int) BackWPup_Option::get( $jobid, 'lastrun' ) - (int) ( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
				if ( $lastrun >= $started - 5 ) {
					$errors = (int) BackWPup_Option::get( $jobid, 'lastrunerrors' );
					return rest_ensure_response( array(
						'status'  => $errors > 0 ? 'error' : 'done',
						'percent' => 100,
						'message' => $errors > 0 ? __( 'Backup finished with errors. Check the BackWPup log.', 'minn-admin' ) : __( 'Backup finished.', 'minn-admin' ),
					) );
				}
				if ( time() - $started > 180 ) {
					return rest_ensure_response( array( 'status' => 'error', 'message' => __( 'BackWPup never started the job. Check that its runner (wp-cron.php) is reachable.', 'minn-admin' ) ) );
				}
				return rest_ensure_response( array( 'status' => 'running', 'message' => __( 'Starting BackWPup…', 'minn-admin' ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm_run,
			'callback'            => function ( WP_REST_Request $request ) {
				$rec = get_transient( 'minn_backwpup_job_' . $request['token'] );
				if ( ! is_array( $rec ) ) {
					return new WP_Error( 'not_found', __( 'Unknown backup job', 'minn-admin' ), array( 'status' => 404 ) );
				}
				try {
					BackWPup_Job::user_abort(); // their Abort button
				} catch ( \Throwable $e ) {
					return new WP_Error( 'abort_failed', __( 'BackWPup could not stop the job.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$rec['canceled'] = true;
				set_transient( 'minn_backwpup_job_' . $request['token'], $rec, 6 * HOUR_IN_SECONDS );
				return rest_ensure_response( array( 'ok' => true, 'status' => 'canceled', 'message' => __( 'Backup stopped.', 'minn-admin' ) ) );
			},
		),
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
			$token = wp_generate_password( 12, false );
			set_transient( 'minn_backwpup_job_' . $token, array( 'started' => time(), 'jobid' => $jobid ), 6 * HOUR_IN_SECONDS );
			$name = BackWPup_Option::get( $jobid, 'name' );
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => __( 'Backup job started in the background.', 'minn-admin' ),
				'job'     => array(
					'id'          => $token,
					/* translators: %s: BackWPup job name. */
					'label'       => $name ? sprintf( __( 'Running %s', 'minn-admin' ), $name ) : __( 'Running backup job', 'minn-admin' ),
					'message'     => __( 'Starting BackWPup…', 'minn-admin' ),
					'statusRoute' => 'minn-admin/v1/backwpup/job/' . $token,
					'stopRoute'   => 'minn-admin/v1/backwpup/job/' . $token,
					'stopMethod'  => 'DELETE',
				),
			) );
		},
	) );
} );
