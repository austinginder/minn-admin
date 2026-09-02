<?php
/**
 * Bundled adapter: UpdraftPlus backups.
 *
 * Answers "is my site backed up?" without leaving Minn: a read-only
 * Backups surface over UpdraftPlus_Backup_History, a status endpoint that
 * feeds the System health check, and a Back-up-now action that schedules
 * UpdraftPlus's OWN cron event (`updraft_backupnow_backup_all`) and lets
 * its resumption machinery do the work — Minn never runs the backup
 * in-request. Restores stay in wp-admin; that's surgery, not daily work.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_updraftplus_active() {
	// UpdraftPlus free has no multisite awareness: its get_table_prefix()
	// returns the BASE prefix and its backup walks a bare SHOW TABLES, so a
	// "back up everything" started from any subsite dumps wp_users, wp_usermeta
	// and every neighbouring subsite's tables to the network owner's remote
	// storage. The plugin says so itself, in an error-level notice on its own
	// screen that Minn does not reproduce. Same rule as every sibling here.
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	return class_exists( 'UpdraftPlus_Options' ) && class_exists( 'UpdraftPlus_Backup_History' );
}

/** Newest-first backup sets from UpdraftPlus's history option. */
function minn_admin_updraft_history() {
	$history = UpdraftPlus_Backup_History::get_history();
	if ( ! is_array( $history ) ) {
		return array();
	}
	krsort( $history, SORT_NUMERIC );
	$items = array();
	foreach ( $history as $ts => $set ) {
		if ( ! is_array( $set ) ) {
			continue;
		}
		$entities = array();
		foreach ( array( 'db' => 'Database', 'plugins' => 'Plugins', 'themes' => 'Themes', 'uploads' => 'Uploads', 'others' => 'Others', 'wpcore' => 'Core', 'more' => 'More' ) as $key => $label ) {
			if ( ! empty( $set[ $key ] ) ) {
				$entities[] = $label;
			}
		}
		$bytes = 0;
		foreach ( $set as $key => $value ) {
			if ( is_numeric( $value ) && '-size' === substr( (string) $key, -5 ) ) {
				$bytes += (int) $value;
			}
		}
		$service = isset( $set['service'] ) ? implode( ', ', array_diff( (array) $set['service'], array( 'none', '' ) ) ) : '';
		$items[] = array(
			'id'         => (int) $ts,
			'date'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts ),
			'components' => $entities ? implode( ' · ', $entities ) : '—',
			'size'       => $bytes ? size_format( $bytes ) : '—',
			'where'      => $service ? $service : 'local',
			'label'      => isset( $set['label'] ) ? (string) $set['label'] : '',
		);
	}
	return $items;
}

/** Is a backup currently running or resuming? (their own cron events) */
function minn_admin_updraft_running() {
	foreach ( (array) _get_cron_array() as $hooks ) {
		foreach ( array( 'updraft_backup_resume', 'updraft_backupnow_backup_all', 'updraft_backupnow_backup', 'updraft_backupnow_backup_database' ) as $hook ) {
			if ( ! empty( $hooks[ $hook ] ) ) {
				return true;
			}
		}
	}
	return false;
}

/** { time, success } of the last finished backup, or null. */
function minn_admin_updraft_last() {
	$last = UpdraftPlus_Options::get_updraft_option( 'updraft_last_backup', array() );
	if ( empty( $last['backup_time'] ) ) {
		return null;
	}
	return array(
		'time'    => (int) $last['backup_time'],
		'success' => ! empty( $last['success'] ),
	);
}

/** The UpdraftPlus_Admin instance, loading admin.php outside wp-admin (its constructor only hooks admin screens). */
function minn_admin_updraft_admin() {
	global $updraftplus_admin;
	if ( ! class_exists( 'UpdraftPlus_Admin' ) && defined( 'UPDRAFTPLUS_DIR' ) && file_exists( UPDRAFTPLUS_DIR . '/admin.php' ) ) {
		include_once UPDRAFTPLUS_DIR . '/admin.php';
	}
	if ( ! is_a( $updraftplus_admin, 'UpdraftPlus_Admin' ) && class_exists( 'UpdraftPlus_Admin' ) ) {
		$updraftplus_admin = new UpdraftPlus_Admin();
	}
	return is_a( $updraftplus_admin, 'UpdraftPlus_Admin' ) ? $updraftplus_admin : null;
}

/**
 * The nonce of the job UpdraftPlus booted for a start at $started: the
 * newest updraft_jobdata_* row whose backup_time is at or after it.
 */
function minn_admin_updraft_find_nonce( $started ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 20", $wpdb->esc_like( 'updraft_jobdata_' ) . '%' ) );
	foreach ( (array) $names as $name ) {
		$data = get_site_option( $name, array() );
		if ( is_array( $data ) && ! empty( $data['backup_time'] ) && (int) $data['backup_time'] >= $started - 5 ) {
			return substr( $name, strlen( 'updraft_jobdata_' ) );
		}
	}
	return '';
}

/**
 * Minn job status for a start record { started, nonce, canceled? }: the
 * stage arithmetic is their print_active_job()'s (six stages: begun,
 * files, database per db, uploading, pruning, finished), read from the
 * jobdata their backup writes; percent = stage / 6.
 */
function minn_admin_updraft_job_status( $rec ) {
	global $updraftplus;
	if ( ! empty( $rec['canceled'] ) ) {
		return array( 'status' => 'canceled', 'message' => __( 'Backup stopped.', 'minn-admin' ) );
	}
	$started = (int) $rec['started'];
	$nonce   = ! empty( $rec['nonce'] ) ? (string) $rec['nonce'] : minn_admin_updraft_find_nonce( $started );
	$last    = minn_admin_updraft_last();
	if ( '' === $nonce ) {
		if ( $last && $last['time'] >= $started - 5 ) {
			return array( 'status' => $last['success'] ? 'done' : 'error', 'percent' => 100, 'message' => $last['success'] ? __( 'Backup finished.', 'minn-admin' ) : __( 'Backup finished with errors. Check UpdraftPlus.', 'minn-admin' ) );
		}
		if ( time() - $started > 180 && ! minn_admin_updraft_running() ) {
			return array( 'status' => 'error', 'message' => __( 'UpdraftPlus never started the backup. Check that WP-Cron can run on this site.', 'minn-admin' ) );
		}
		return array( 'status' => 'running', 'message' => __( 'Starting UpdraftPlus…', 'minn-admin' ) );
	}
	$jobdata = is_object( $updraftplus ) && method_exists( $updraftplus, 'jobdata_getarray' ) ? (array) $updraftplus->jobdata_getarray( $nonce ) : (array) get_site_option( 'updraft_jobdata_' . $nonce, array() );
	if ( empty( $jobdata ) ) {
		// UpdraftPlus deletes a job's data when the job ends, so a nonce
		// whose row is gone is a finished job; updraft_last_backup says how
		// it went.
		if ( $last && $last['time'] >= $started - 5 ) {
			return array( 'status' => $last['success'] ? 'done' : 'error', 'percent' => 100, 'message' => $last['success'] ? __( 'Backup finished.', 'minn-admin' ) : __( 'Backup finished with errors. Check UpdraftPlus.', 'minn-admin' ), '_nonce' => $nonce );
		}
		if ( ! minn_admin_updraft_running() ) {
			return array( 'status' => 'done', 'percent' => 100, 'message' => __( 'Backup finished.', 'minn-admin' ), '_nonce' => $nonce );
		}
		return array( 'status' => 'running', 'message' => __( 'Finishing…', 'minn-admin' ), '_nonce' => $nonce );
	}
	$status  = isset( $jobdata['jobstatus'] ) ? (string) $jobdata['jobstatus'] : '';
	$stage   = 0.0;
	$text    = __( 'Backup begun', 'minn-admin' );
	if ( 'filescreating' === $status ) {
		$stage = 1.0;
		$text  = __( 'Creating file backup zips', 'minn-admin' );
		if ( isset( $jobdata['filecreating_substatus']['i'], $jobdata['filecreating_substatus']['t'] ) ) {
			$stage = min( 2.0, 1 + $jobdata['filecreating_substatus']['i'] / max( (int) $jobdata['filecreating_substatus']['t'], 1 ) );
		}
	} elseif ( 'filescreated' === $status ) {
		$stage = 2.0;
		$text  = __( 'Created file backup zips', 'minn-admin' );
	} elseif ( 0 === strpos( $status, 'dbcreat' ) || 0 === strpos( $status, 'dbencrypt' ) ) {
		$stage = 0 === strpos( $status, 'dbcreated' ) || 0 === strpos( $status, 'dbencrypted' ) ? 4.0 : 3.0;
		$text  = 0 === strpos( $status, 'dbcreated' ) ? __( 'Created database backup', 'minn-admin' ) : __( 'Creating database backup', 'minn-admin' );
	} elseif ( in_array( $status, array( 'clouduploading', 'partialclouduploading' ), true ) ) {
		$stage = 4.0;
		$text  = __( 'Uploading files to remote storage', 'minn-admin' );
		if ( isset( $jobdata['uploading_substatus']['t'], $jobdata['uploading_substatus']['i'] ) ) {
			$t = max( (int) $jobdata['uploading_substatus']['t'], 1 );
			$stage = 4 + min( $jobdata['uploading_substatus']['i'] / $t, 1 );
		}
	} elseif ( 'pruning' === $status ) {
		$stage = 5.0;
		$text  = __( 'Pruning old backup sets', 'minn-admin' );
	} elseif ( 'resumingforerrors' === $status ) {
		$stage = 1.0;
		$text  = __( 'Waiting to retry after errors', 'minn-admin' );
	} elseif ( 'finished' === $status ) {
		$ok = ! ( $last && $last['time'] >= $started - 5 && ! $last['success'] );
		return array( 'status' => $ok ? 'done' : 'error', 'percent' => 100, 'message' => $ok ? __( 'Backup finished.', 'minn-admin' ) : __( 'Backup finished with errors. Check UpdraftPlus.', 'minn-admin' ), '_nonce' => $nonce );
	}
	return array( 'status' => 'running', 'percent' => (int) round( $stage / 6 * 100 ), 'message' => $text, '_nonce' => $nonce );
}

/** Server-built model for the surface status card (distinct from /updraft/status). */
function minn_admin_updraft_status_model() {
	$last    = minn_admin_updraft_last();
	$running = minn_admin_updraft_running();
	$history = minn_admin_updraft_history();
	$count   = count( $history );

	if ( $running ) {
		$last_value = __( 'Running now…', 'minn-admin' );
		$last_hint  = __( 'UpdraftPlus is building or resuming a set', 'minn-admin' );
	} elseif ( $last ) {
	$last_value = sprintf(
		/* translators: %s: human-readable time since the last backup. */
		__( '%s ago', 'minn-admin' ),
		human_time_diff( $last['time'] )
	);
		$last_hint  = $last['success'] ? __( 'Completed successfully', 'minn-admin' ) : __( 'Finished with errors — check UpdraftPlus', 'minn-admin' );
	} else {
		$last_value = 'Never';
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
				'label' => __( 'Status', 'minn-admin' ),
				'value' => $running ? 'Running' : 'Idle',
				'hint'  => __( 'Jobs run through UpdraftPlus\'s own cron machinery', 'minn-admin' ),
			),
		),
		'actions' => array(
			array(
				'label'   => __( 'Back up everything now', 'minn-admin' ),
				'route'   => 'minn-admin/v1/updraft/backup-now',
				'method'  => 'POST',
				'job'     => true,
				'body'    => array( 'what' => 'all' ),
				'confirm' => __( 'Start a full backup now? UpdraftPlus will run it in the background.', 'minn-admin' ),
			),
			array(
				'label'  => __( 'Database only', 'minn-admin' ),
				'route'  => 'minn-admin/v1/updraft/backup-now',
				'method' => 'POST',
				'job'    => true,
				'body'   => array( 'what' => 'db' ),
			),
			array(
				'label' => __( 'Open UpdraftPlus ↗', 'minn-admin' ),
				'href'  => admin_url( 'options-general.php?page=updraftplus' ),
			),
		),
	);
}

/**
 * UpdraftPlus answers this itself, through two filters a site can use to
 * narrow or widen who reaches its screens. manage_options is only the default
 * that feeds the first of them, so asking for it directly ignored both.
 *
 * @return bool
 */
function minn_admin_updraftplus_can() {
	if ( class_exists( 'UpdraftPlus_Options' )
		&& method_exists( 'UpdraftPlus_Options', 'user_can_manage' ) ) {
		try {
			return (bool) UpdraftPlus_Options::user_can_manage();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the mirrored test below.
		}
	}
	$can = current_user_can( apply_filters( 'option_page_capability_updraft-options-group', 'manage_options' ) );
	// They ask twice: a capability filter, and then a yes-or-no filter that can
	// grant access outright. Mirroring only the first dropped half of their
	// answer.
	return (bool) apply_filters( 'updraft_user_can_manage', $can, false );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_updraftplus_active() || ! minn_admin_updraftplus_can() ) {
		return $surfaces;
	}
	$surfaces['updraftplus'] = array(
		'label'      => __( 'Backups', 'minn-admin' ),
		'sub'        => 'UpdraftPlus',
		'icon'       => 'database',
		// Their answer is a resolver, not a capability name; the filter above
		// is the real gate (the Solid Security / WP Mail Logging precedent).
		'cap'        => 'read',
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/updraft/card' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/updraft/backups',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'components', 'label' => __( 'Backup', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'where', 'label' => __( 'Stored', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => __( 'Date', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_updraftplus_active() ) {
		return;
	}
	$perm = function () {
		// Network-shared archives (see minn_admin_updraftplus_active).
		return minn_admin_updraftplus_can() && Minn_Admin::network_owner();
	};

	register_rest_route( 'minn-admin/v1', '/updraft/backups', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$all      = minn_admin_updraft_history();
			return rest_ensure_response( array(
				'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $all ),
			) );
		},
	) );

	// Machine-readable status (System health + suite + poll completion).
	register_rest_route( 'minn-admin/v1', '/updraft/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( array(
				'last'    => minn_admin_updraft_last(),
				'running' => minn_admin_updraft_running(),
				'history' => count( minn_admin_updraft_history() ),
			) );
		},
	) );

	// Surface status card (rows + actions) — same shape as Disembark/Duplicator.
	register_rest_route( 'minn-admin/v1', '/updraft/card', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_updraft_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/updraft/job/(?P<token>[A-Za-z0-9]{12})', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$rec = get_transient( 'minn_updraft_job_' . $request['token'] );
				if ( ! is_array( $rec ) ) {
					return new WP_Error( 'not_found', __( 'Unknown backup job', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$out = minn_admin_updraft_job_status( $rec );
				if ( ! empty( $out['_nonce'] ) && $out['_nonce'] !== $rec['nonce'] ) {
					$rec['nonce'] = $out['_nonce'];
					set_transient( 'minn_updraft_job_' . $request['token'], $rec, 6 * HOUR_IN_SECONDS );
				}
				unset( $out['_nonce'] );
				return rest_ensure_response( $out );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$rec = get_transient( 'minn_updraft_job_' . $request['token'] );
				if ( ! is_array( $rec ) ) {
					return new WP_Error( 'not_found', __( 'Unknown backup job', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$nonce = $rec['nonce'] ? $rec['nonce'] : minn_admin_updraft_find_nonce( (int) $rec['started'] );
				if ( $nonce ) {
					$admin = minn_admin_updraft_admin();
					if ( $admin ) {
						try {
							$admin->activejobs_delete( $nonce ); // their "delete job": unschedules the resumption + drops the lock
						} catch ( \Throwable $e ) {
							// The flag file below still stops the running resumption.
						}
					}
				}
				// A start that has not booted yet: drop the one-shot cron events.
				foreach ( array( 'updraft_backupnow_backup_all', 'updraft_backupnow_backup_database' ) as $hook ) {
					wp_unschedule_hook( $hook );
				}
				$rec['canceled'] = true;
				set_transient( 'minn_updraft_job_' . $request['token'], $rec, 6 * HOUR_IN_SECONDS );
				return rest_ensure_response( array( 'ok' => true, 'status' => 'canceled', 'message' => __( 'Backup stopped.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/updraft/backup-now', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$what  = sanitize_key( (string) $request->get_param( 'what' ) );
			$event = 'db' === $what ? 'updraft_backupnow_backup_database' : 'updraft_backupnow_backup_all';
			// Same options their own Backup Now dialog passes; nocloud=0
			// means "send to configured remote storage, if any".
			$options = array(
				'nocloud'     => 0,
				'use_nonce'   => false,
				'always_keep' => false,
			);
			wp_schedule_single_event( time() - 1, $event, array( $options ) );
			// Kick cron immediately so the job starts without waiting for
			// the next visitor; UpdraftPlus resumes itself from there.
			spawn_cron();
			$message = 'db' === $what
				? __( 'Database backup started — UpdraftPlus is running it in the background.', 'minn-admin' )
				: __( 'Full backup started — UpdraftPlus is running it in the background.', 'minn-admin' );
			// The job id (their nonce) only exists once the cron event has
			// booted the backup; the token stands in until the status route
			// finds the jobdata whose backup_time follows this start.
			$token = wp_generate_password( 12, false );
			set_transient( 'minn_updraft_job_' . $token, array( 'started' => time(), 'nonce' => '' ), 6 * HOUR_IN_SECONDS );
			return rest_ensure_response( array(
				'started' => true,
				'what'    => 'db' === $what ? 'db' : 'all',
				'message' => $message,
				'job'     => array(
					'id'          => $token,
					'label'       => 'db' === $what ? __( 'Backing up database', 'minn-admin' ) : __( 'Backing up site', 'minn-admin' ),
					'message'     => __( 'Starting UpdraftPlus…', 'minn-admin' ),
					'statusRoute' => 'minn-admin/v1/updraft/job/' . $token,
					'stopRoute'   => 'minn-admin/v1/updraft/job/' . $token,
					'stopMethod'  => 'DELETE',
				),
			) );
		},
	) );
} );
