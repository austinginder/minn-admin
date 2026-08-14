<?php
/**
 * Bundled adapter: WPvivid Backup & Migration (free) — backups family.
 *
 * List + status + delete + backup-now over WPvivid's own APIs. History
 * lives in option `wpvivid_backup_list` (id-keyed sets with create_time
 * as a UTC epoch); sizes come from the file entries already stored in
 * that option via WPvivid_Backuplist::get_size(). Delete goes through
 * $wpvivid_plugin->delete_backup_by_id() so local/remote cleanup stays
 * their code. Backup-now prepares a task through
 * WPvivid_Public_Interface::prepare_backup() then schedules Minn's own
 * single-shot cron hook which calls their backup() — same split their
 * admin-ajax prepare/backup_now pair uses, without holding a REST
 * request open for the whole run.
 *
 * Restores stay in wp-admin (surgery, not daily work). Cap is
 * manage_options, matching their ajax_check_security default.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wpvivid_active() {
	// WPvivid keeps its archives in one network-shared wp-content/wpvividbackups
	// directory and registers its admin under network_admin_menu on multisite,
	// so the backup sets, the delete and the run-now all belong to the network
	// owner there. manage_options is per site and would admit every subsite
	// administrator.
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	return defined( 'WPVIVID_PLUGIN_DIR' )
		&& class_exists( 'WPvivid_Backuplist' )
		&& class_exists( 'WPvivid_Setting' );
}

/**
 * Kick a prepared task via cron (registered while the plugin is active).
 * Their backup() ends in die() — fine under a cron request.
 *
 * @param string $task_id WPvivid task id.
 */
function minn_admin_wpvivid_run_task( $task_id ) {
	global $wpvivid_plugin;
	if ( ! minn_admin_wpvivid_active() || ! $wpvivid_plugin || ! is_string( $task_id ) || '' === $task_id ) {
		return;
	}
	if ( ! class_exists( 'WPvivid_taskmanager' ) ) {
		return;
	}
	$task = WPvivid_taskmanager::get_task( $task_id );
	if ( ! $task ) {
		return;
	}
	try {
		if ( method_exists( $wpvivid_plugin, 'update_last_backup_time' ) ) {
			$wpvivid_plugin->update_last_backup_time( $task );
		}
		if ( method_exists( $wpvivid_plugin, 'backup' ) ) {
			$wpvivid_plugin->backup( $task_id );
		}
	} catch ( \Throwable $e ) {
		// Cron must never fatal the site; their own handlers write logs.
	}
}
add_action( 'minn_admin_wpvivid_run', 'minn_admin_wpvivid_run_task', 10, 1 );

/** Human label for a WPvivid backup_files value. */
function minn_admin_wpvivid_components_label( $backup_files, $type = '' ) {
	$map = array(
		'files+db' => 'Database · Files',
		'files'    => 'Files',
		'db'       => 'Database',
	);
	$files = is_string( $backup_files ) ? $backup_files : '';
	if ( isset( $map[ $files ] ) ) {
		return $map[ $files ];
	}
	// Fall back to type (Manual / Cron / Upload) when the set predates
	// storing backup_files on the list row, or was uploaded.
	$type = is_string( $type ) ? $type : '';
	return $type ? $type : 'Backup';
}

/**
 * Infer components from filenames when backup_files isn't on the row
 * (completed sets store file_name entries under backup.files).
 */
function minn_admin_wpvivid_components_from_files( $backup ) {
	$names = array();
	if ( ! empty( $backup['backup']['files'] ) && is_array( $backup['backup']['files'] ) ) {
		foreach ( $backup['backup']['files'] as $file ) {
			if ( ! empty( $file['file_name'] ) ) {
				$names[] = strtolower( (string) $file['file_name'] );
			}
		}
	} elseif ( ! empty( $backup['backup']['data']['type'] ) && is_array( $backup['backup']['data']['type'] ) ) {
		foreach ( $backup['backup']['data']['type'] as $type ) {
			foreach ( (array) ( $type['files'] ?? array() ) as $file ) {
				if ( ! empty( $file['file_name'] ) ) {
					$names[] = strtolower( (string) $file['file_name'] );
				}
			}
		}
	}
	if ( ! $names ) {
		return '';
	}
	$joined = implode( ' ', $names );
	$has_db = (bool) preg_match( '/(^|[^a-z])db([^a-z]|$)|database|sql/i', $joined );
	// "all" / themes / plugins / uploads / content → files side.
	$has_files = (bool) preg_match( '/themes|plugins|uploads|content|www|files|all/i', $joined );
	if ( $has_db && $has_files ) {
		return 'Database · Files';
	}
	if ( $has_db ) {
		return 'Database';
	}
	if ( $has_files ) {
		return 'Files';
	}
	return '';
}

/** Display rows for the backup list, newest first. */
function minn_admin_wpvivid_rows() {
	if ( ! minn_admin_wpvivid_active() ) {
		return array();
	}
	$list = WPvivid_Backuplist::get_backuplist();
	if ( ! is_array( $list ) || ! $list ) {
		return array();
	}
	$items = array();
	foreach ( $list as $id => $set ) {
		if ( ! is_array( $set ) ) {
			continue;
		}
		$ts = isset( $set['create_time'] ) ? (int) $set['create_time'] : 0;
		$bytes = 0;
		try {
			$bytes = (int) WPvivid_Backuplist::get_size( $id );
		} catch ( \Throwable $e ) {
			$bytes = 0;
		}
		$remote = ! empty( $set['remote'] ) && is_array( $set['remote'] )
			? array_filter( $set['remote'] )
			: array();
		$where = $remote ? 'remote' : 'local';
		// Prefer an explicit backup_files on the set (newer shapes), else
		// guess from filenames, else fall back to type.
		$components = '';
		if ( ! empty( $set['backup_files'] ) ) {
			$components = minn_admin_wpvivid_components_label( $set['backup_files'], $set['type'] ?? '' );
		} else {
			$components = minn_admin_wpvivid_components_from_files( $set );
			if ( ! $components ) {
				$components = minn_admin_wpvivid_components_label( '', $set['type'] ?? '' );
			}
		}
		$locked = ! empty( $set['lock'] ) && (string) $set['lock'] !== '0';
		$items[] = array(
			'id'         => (string) $id,
			'components' => $components,
			'size'       => $bytes ? size_format( $bytes ) : '—',
			'where'      => $where,
			'type'       => isset( $set['type'] ) ? (string) $set['type'] : '',
			'locked'     => $locked ? 'locked' : '',
			'date'       => $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '',
			'ts'         => $ts,
		);
	}
	return $items;
}

/** Is a backup currently running? (their taskmanager status strings) */
function minn_admin_wpvivid_running() {
	if ( ! class_exists( 'WPvivid_taskmanager' ) ) {
		return false;
	}
	try {
		return (bool) WPvivid_taskmanager::is_tasks_backup_running();
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * { time, success } of the newest completed set, or null.
 * Listed backups are completed; failed runs never land in the list.
 */
function minn_admin_wpvivid_last() {
	$rows = minn_admin_wpvivid_rows();
	if ( $rows && ! empty( $rows[0]['ts'] ) ) {
		return array(
			'time'    => (int) $rows[0]['ts'],
			'success' => true,
		);
	}
	// No completed set: peek at their last task message for a failed run.
	$msg = get_option( 'wpvivid_last_msg', array() );
	if ( ! is_array( $msg ) || empty( $msg['status']['start_time'] ) ) {
		return null;
	}
	$str = isset( $msg['status']['str'] ) ? (string) $msg['status']['str'] : '';
	if ( in_array( $str, array( 'completed', 'error', 'cancel' ), true ) || ! empty( $msg['status']['error'] ) ) {
		return array(
			'time'    => (int) $msg['status']['start_time'],
			'success' => 'completed' === $str && empty( $msg['status']['error'] ),
		);
	}
	return null;
}

/** Schedule summary for the status card. */
function minn_admin_wpvivid_schedule_hint() {
	if ( ! class_exists( 'WPvivid_Schedule' ) ) {
		return array( 'enable' => false, 'label' => __( 'Not scheduled', 'minn-admin' ) );
	}
	try {
		$s = WPvivid_Schedule::get_schedule();
	} catch ( \Throwable $e ) {
		return array( 'enable' => false, 'label' => __( 'Not scheduled', 'minn-admin' ) );
	}
	if ( empty( $s['enable'] ) ) {
		return array( 'enable' => false, 'label' => __( 'Not scheduled', 'minn-admin' ) );
	}
	$rec = isset( $s['recurrence'] ) ? (string) $s['recurrence'] : '';
	$next = ! empty( $s['next_start'] ) ? (int) $s['next_start'] : 0;
	$label = $rec ? $rec : __( 'On a schedule', 'minn-admin' );
	if ( $next > 0 ) {
		$label .= ' · next ' . human_time_diff( $next );
	}
	return array( 'enable' => true, 'label' => $label );
}

/** Server-built model for the surface status card. */
function minn_admin_wpvivid_status_model() {
	$last    = minn_admin_wpvivid_last();
	$running = minn_admin_wpvivid_running();
	$rows    = minn_admin_wpvivid_rows();
	$count   = count( $rows );
	$sched   = minn_admin_wpvivid_schedule_hint();

	if ( $running ) {
		$last_value = __( 'Running now…', 'minn-admin' );
		$last_hint  = __( 'WPvivid is building a backup', 'minn-admin' );
	} elseif ( $last ) {
		/* translators: %s: human-readable elapsed time. */
		$last_value = sprintf( __( '%s ago', 'minn-admin' ), human_time_diff( $last['time'] ) );
		$last_hint  = $last['success'] ? __( 'Completed successfully', 'minn-admin' ) : __( 'Finished with errors — check WPvivid', 'minn-admin' );
	} else {
		$last_value = __( 'Never', 'minn-admin' );
		$last_hint  = __( 'No finished backup recorded yet', 'minn-admin' );
	}

	return array(
		'rows'    => array(
			array(
				'label' => __( 'Last backup', 'minn-admin' ),
				'value' => $last_value,
				'hint'  => $last_hint,
			),
			array(
				'label' => __( 'Sets kept', 'minn-admin' ),
				'value' => (string) $count,
				'hint'  => $count
					? __( 'Newest first in the list below (retention may prune older sets)', 'minn-admin' )
					: __( 'Nothing on disk yet', 'minn-admin' ),
			),
			array(
				'label' => __( 'Schedule', 'minn-admin' ),
				'value' => $sched['enable'] ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ),
				'hint'  => $sched['label'],
			),
			array(
				'label' => __( 'Status', 'minn-admin' ),
				'value' => $running ? __( 'Running now…', 'minn-admin' ) : __( 'Inactive', 'minn-admin' ),
				'hint'  => __( 'Jobs run through WPvivid\'s own backup machinery', 'minn-admin' ),
			),
		),
		'actions' => array(
			array(
				'label'   => __( 'Back up everything now', 'minn-admin' ),
				'route'   => 'minn-admin/v1/wpvivid/backup-now',
				'method'  => 'POST',
				'body'    => array( 'what' => 'all' ),
				'confirm' => __( 'Start a full backup now? WPvivid will run it in the background.', 'minn-admin' ),
			),
			array(
				'label'  => __( 'Database only', 'minn-admin' ),
				'route'  => 'minn-admin/v1/wpvivid/backup-now',
				'method' => 'POST',
				'body'   => array( 'what' => 'db' ),
			),
			array(
				'label' => __( 'Open WPvivid ↗', 'minn-admin' ),
				'href'  => admin_url( 'admin.php?page=WPvivid' ),
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wpvivid_active() ) {
		return $surfaces;
	}
	$surfaces['wpvivid'] = array(
		'label'      => __( 'Backups', 'minn-admin' ),
		'sub'        => 'WPvivid',
		'icon'       => 'database',
		'cap'        => 'manage_options',
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/wpvivid/card' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/wpvivid/backups',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'components', 'label' => __( 'Backup', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'where', 'label' => __( 'Stored', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'locked', 'label' => __( 'Lock', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => __( 'Date', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'components', 'ts' ),
			),
			'actions'   => array(
				array(
					'label'   => __( 'Delete backup', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpvivid/backups/{id}',
					'confirm' => __( 'Delete this backup and its archive files permanently?', 'minn-admin' ),
					'danger'  => true,
					// locked is '' on free rows, 'locked' when pinned.
					'when'    => array( 'key' => 'locked', 'equals' => '' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpvivid_active() ) {
		return;
	}
	$perm = function () {
		// Network-shared archive directory (see minn_admin_wpvivid_active).
		return current_user_can( 'manage_options' ) && Minn_Admin::network_owner();
	};

	register_rest_route( 'minn-admin/v1', '/wpvivid/backups', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$all      = minn_admin_wpvivid_rows();
			return rest_ensure_response( array(
				'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $all ),
			) );
		},
	) );

	// Machine-readable status (System health + suite + poll completion).
	register_rest_route( 'minn-admin/v1', '/wpvivid/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( array(
				'last'    => minn_admin_wpvivid_last(),
				'running' => minn_admin_wpvivid_running(),
				'history' => count( minn_admin_wpvivid_rows() ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wpvivid/card', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_wpvivid_status_model() );
		},
	) );

	// Id is their task id (alphanumeric + hyphen/underscore), not a DB int.
	register_rest_route( 'minn-admin/v1', '/wpvivid/backups/(?P<id>[A-Za-z0-9_-]+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpvivid_plugin;
			$id = sanitize_key( (string) $request['id'] );
			if ( ! $id ) {
				return new WP_Error( 'invalid_id', __( 'Backup id is required.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( ! WPvivid_Backuplist::get_backup_by_id( $id ) ) {
				return new WP_Error( 'not_found', __( 'Backup not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			if ( ! $wpvivid_plugin || ! method_exists( $wpvivid_plugin, 'delete_backup_by_id' ) ) {
				return new WP_Error( 'unavailable', __( 'WPvivid is not ready to delete backups.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			try {
				// force=0 honors their lock; locked sets refuse with their message.
				$ret = $wpvivid_plugin->delete_backup_by_id( $id, 0 );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', __( 'WPvivid could not delete: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			if ( empty( $ret['result'] ) || 'success' !== $ret['result'] ) {
				return new WP_Error(
					'delete_failed',
					! empty( $ret['error'] ) ? (string) $ret['error'] : __( 'WPvivid refused the delete.', 'minn-admin' ),
					array( 'status' => 400 )
				);
			}
			return rest_ensure_response( array( 'deleted' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wpvivid/backup-now', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( minn_admin_wpvivid_running() ) {
				return new WP_Error(
					'already_running',
					__( 'A WPvivid backup is already running. Wait for it to finish.', 'minn-admin' ),
					array( 'status' => 409 )
				);
			}
			$what = sanitize_key( (string) $request->get_param( 'what' ) );
			// Same option shape their Backup Now UI posts (string 0/1 flags).
			$options = array(
				'backup_files' => 'db' === $what ? 'db' : 'files+db',
				'local'        => '1',
				'remote'       => '0',
				'ismerge'      => '1',
				'lock'         => '0',
				'type'         => 'Manual',
				'action'       => 'backup',
			);
			if ( ! class_exists( 'WPvivid_Public_Interface' ) ) {
				return new WP_Error( 'unavailable', __( 'WPvivid public interface is not loaded.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			try {
				$iface = new WPvivid_Public_Interface();
				$ret   = $iface->prepare_backup( $options );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'prepare_failed', __( 'WPvivid could not prepare: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			if ( empty( $ret['result'] ) || 'success' !== $ret['result'] || empty( $ret['task_id'] ) ) {
				return new WP_Error(
					'prepare_failed',
					! empty( $ret['error'] ) ? (string) $ret['error'] : __( 'WPvivid refused to prepare the backup.', 'minn-admin' ),
					array( 'status' => 400 )
				);
			}
			$task_id = (string) $ret['task_id'];
			// Kick via cron so the REST reply returns immediately — their
			// backup() holds the request for the whole run (and dies).
			wp_schedule_single_event( time() - 1, 'minn_admin_wpvivid_run', array( $task_id ) );
			spawn_cron();
			$message = 'db' === $what
				? __( 'Database backup started — WPvivid is running it in the background.', 'minn-admin' )
				: __( 'Full backup started — WPvivid is running it in the background.', 'minn-admin' );
			return rest_ensure_response( array(
				'started' => true,
				'what'    => 'db' === $what ? 'db' : 'all',
				'task_id' => $task_id,
				'message' => $message,
			) );
		},
	) );
} );
