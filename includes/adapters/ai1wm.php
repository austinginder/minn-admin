<?php
/**
 * Bundled adapter: All-in-One WP Migration — backups family.
 *
 * Lists local .wpress exports under AI1WM_BACKUPS_PATH via
 * Ai1wm_Backups::get_files() (their own recursive iterator). Delete goes
 * through Ai1wm_Backups::delete_file() + delete_label() so label cleanup
 * stays their code.
 *
 * EXPORTS RUN FROM MINN as a background job (7.1+ ships a REST controller,
 * ai1wm/v1): POST exports starts the pipeline, runs its first step and
 * fires the plugin's own non-blocking loopback chain, answering 202 with a
 * job id; the per-job status lives in option ai1wm_status_{job}
 * (type progress|info|download|done|error|canceled, message, archive);
 * DELETE cancels and cleans the storage folder. Minn dispatches to those
 * routes through rest_do_request so their permission callbacks decide,
 * and maps the answer onto its job contract (status, percent parsed from
 * their "N% complete" messages when the type carries none, the download
 * link as the result). Imports stay on their screen: an import replaces
 * the site and asks confirmation questions mid-flight by design.
 *
 * No freshness claims: exports are manual (the Disembark / Duplicator
 * precedent). Cap is `export`, matching their own REST controller.
 *
 * Id shape: base64url of the relative filename (may include subpaths), so
 * delete can resolve the exact archive without putting slashes in the
 * REST path.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_ai1wm_active() {
	// AI1WM_BACKUPS_PATH is ONE wp-content/ai1wm-backups directory for the
	// whole network and those archives hold every tenant's database, so on
	// multisite this is the network owner's surface. The plugin says so
	// itself: without the paid Multisite Extension it registers no admin
	// menu on a subsite at all. `export` would otherwise let every subsite
	// administrator through, since core's multisite capability pass never
	// strips it.
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	return defined( 'AI1WM_BACKUPS_PATH' )
		&& class_exists( 'Ai1wm_Backups' )
		&& method_exists( 'Ai1wm_Backups', 'get_files' );
}

/** base64url encode a filename for a REST-safe id. */
function minn_admin_ai1wm_id_encode( $filename ) {
	return rtrim( strtr( base64_encode( (string) $filename ), '+/', '-_' ), '=' );
}

/** Inverse of minn_admin_ai1wm_id_encode. */
function minn_admin_ai1wm_id_decode( $id ) {
	$b64 = strtr( (string) $id, '-_', '+/' );
	$pad = strlen( $b64 ) % 4;
	if ( $pad ) {
		$b64 .= str_repeat( '=', 4 - $pad );
	}
	$out = base64_decode( $b64, true );
	return false === $out ? '' : $out;
}

/** Display rows for local .wpress exports, newest first. */
function minn_admin_ai1wm_rows() {
	if ( ! minn_admin_ai1wm_active() ) {
		return array();
	}
	try {
		$files  = Ai1wm_Backups::get_files();
		$labels = method_exists( 'Ai1wm_Backups', 'get_labels' ) ? (array) Ai1wm_Backups::get_labels() : array();
	} catch ( \Throwable $e ) {
		return array();
	}
	$items = array();
	foreach ( (array) $files as $file ) {
		$filename = isset( $file['filename'] ) ? (string) $file['filename'] : '';
		if ( ! $filename ) {
			continue;
		}
		// Their delete_file only accepts supported archive names (no ..).
		if ( function_exists( 'ai1wm_is_filename_supported' ) && ! ai1wm_is_filename_supported( $filename ) ) {
			continue;
		}
		$size  = array_key_exists( 'size', $file ) && null !== $file['size'] ? (int) $file['size'] : 0;
		$mtime = isset( $file['mtime'] ) && null !== $file['mtime'] ? (int) $file['mtime'] : 0;
		$label = isset( $labels[ $filename ] ) ? (string) $labels[ $filename ] : '';
		$items[] = array(
			'id'          => minn_admin_ai1wm_id_encode( $filename ),
			'filename'    => $filename,
			'label'       => $label,
			'title'       => $label ? $label : $filename,
			'size'        => $size ? size_format( $size ) : '—',
			'size_raw' => $size,
			'date'     => $mtime ? gmdate( 'Y-m-d\TH:i:s\Z', $mtime ) : '',
			'ts'       => $mtime,
		);
	}
	// get_files already sorts newest-first; keep that order.
	return $items;
}

function minn_admin_ai1wm_status_model() {
	$rows = minn_admin_ai1wm_rows();
	$disk = 0;
	foreach ( $rows as $r ) {
		$disk += (int) ( $r['size_raw'] ?? 0 );
	}
	$newest = $rows ? $rows[0] : null;
	$path   = defined( 'AI1WM_BACKUPS_PATH' ) ? AI1WM_BACKUPS_PATH : '';
	$hint   = $path ? basename( $path ) : '';

	return array(
		'rows'    => array(
			array(
				'label' => __( 'Newest export', 'minn-admin' ),
				'value' => $newest ? $newest['title'] : __( 'None yet', 'minn-admin' ),
				'hint'  => $newest && $newest['ts']
					? sprintf(
						/* translators: 1: human-readable time since the newest export was created. 2: export file size. */
						__( '%1$s ago · %2$s', 'minn-admin' ),
						human_time_diff( $newest['ts'] ),
						$newest['size']
					)
					: __( 'Exports are built manually from All-in-One WP Migration\'s screen.', 'minn-admin' ),
			),
			array(
				'label' => __( 'Exports', 'minn-admin' ),
				'value' => (string) count( $rows ),
				'hint'  => __( 'Manual exports; Minn makes no freshness claims for All-in-One WP Migration.', 'minn-admin' ),
			),
			array(
				'label' => __( 'On disk', 'minn-admin' ),
				'value' => $disk ? size_format( $disk ) : '0 B',
				'hint'  => $hint,
			),
		),
		'actions' => array_values( array_filter( array(
			minn_admin_ai1wm_rest_ready() ? array(
				'label'  => __( 'Export site', 'minn-admin' ),
				'route'  => 'minn-admin/v1/ai1wm/export',
				'method' => 'POST',
				'job'    => true,
				'fields' => array(
					array( 'key' => 'no_media', 'label' => __( 'Skip media library', 'minn-admin' ), 'type' => 'toggle', 'value' => false, 'required' => false ),
					array( 'key' => 'no_plugins', 'label' => __( 'Skip plugins', 'minn-admin' ), 'type' => 'toggle', 'value' => false, 'required' => false ),
					array( 'key' => 'no_themes', 'label' => __( 'Skip themes', 'minn-admin' ), 'type' => 'toggle', 'value' => false, 'required' => false ),
					array( 'key' => 'no_spam_comments', 'label' => __( 'Skip spam comments', 'minn-admin' ), 'type' => 'toggle', 'value' => true, 'required' => false ),
					array( 'key' => 'no_post_revisions', 'label' => __( 'Skip post revisions', 'minn-admin' ), 'type' => 'toggle', 'value' => false, 'required' => false ),
				),
			) : array(
				'label' => __( 'Export site ↗', 'minn-admin' ),
				'href'  => admin_url( 'admin.php?page=ai1wm_export' ),
			),
			array(
				'label' => __( 'Open backups ↗', 'minn-admin' ),
				'href'  => admin_url( 'admin.php?page=ai1wm_backups' ),
			),
		) ) ),
	);
}

/** Their REST controller (7.1+) is what makes an export drivable from here. */
function minn_admin_ai1wm_rest_ready() {
	return class_exists( 'Ai1wm_Rest_Controller' ) && defined( 'AI1WM_SECRET_KEY' );
}

/** One request to ai1wm/v1 through the REST server, so their gates run. */
function minn_admin_ai1wm_dispatch( $method, $path, $body = null ) {
	$req = new WP_REST_Request( $method, '/ai1wm/v1/' . ltrim( $path, '/' ) );
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	return array( 'code' => $res->get_status(), 'data' => (array) $res->get_data() );
}

/**
 * Their job status → Minn's job contract.
 *
 * Their percent rides only on 'progress' entries; the 'info' entries the
 * archiver writes carry it inside the text ("Archiving 27189 content
 * files...62% complete"), so it is read from there when absent. The
 * 'download' entry's message is an anchor to the finished archive: that
 * becomes the result link, and the export list is what shows the file.
 */
function minn_admin_ai1wm_job_status( $data ) {
	$type    = (string) ( $data['type'] ?? '' );
	$raw     = (string) ( $data['message'] ?? '' );
	$status  = (string) ( $data['status'] ?? 'running' );
	$map     = array( 'complete' => 'done', 'running' => 'running', 'error' => 'error', 'canceled' => 'canceled', 'confirm' => 'running' );
	$out     = array( 'status' => $map[ $status ] ?? 'running' );
	$percent = isset( $data['percent'] ) && is_numeric( $data['percent'] ) ? (int) $data['percent'] : null;
	if ( null === $percent && preg_match( '/(\d{1,3})\s*%/', wp_strip_all_tags( $raw ), $m ) ) {
		$percent = (int) $m[1];
	}
	if ( null !== $percent ) {
		$out['percent'] = max( 0, min( 100, $percent ) );
	}
	$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( array( '<em>', '<span>' ), ' ', $raw ) ) ) );
	if ( 'download' === $type ) {
		$href = '';
		if ( preg_match( '/href="([^"]+)"/', $raw, $m ) ) {
			$href = html_entity_decode( $m[1] );
		}
		$archive = (string) ( $data['archive'] ?? '' );
		$out['message'] = $archive
			/* translators: %s: the export file name. */
			? sprintf( __( 'Export finished: %s', 'minn-admin' ), $archive )
			: __( 'Export finished.', 'minn-admin' );
		if ( $href && 0 === strpos( $href, 'http' ) ) {
			$out['result'] = array( 'label' => __( 'Download export', 'minn-admin' ), 'href' => $href );
		}
	} elseif ( 'done' === $type ) {
		$out['message'] = '' !== $text ? $text : __( 'Export finished.', 'minn-admin' );
	} elseif ( 'error' === $type ) {
		$title          = trim( wp_strip_all_tags( (string) ( $data['title'] ?? '' ) ) );
		$out['message'] = trim( $title . ( $title && $text ? ': ' : '' ) . $text );
	} else {
		$out['message'] = '' !== $text ? $text : __( 'Preparing export…', 'minn-admin' );
	}
	return $out;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_ai1wm_active() ) {
		return $surfaces;
	}
	// The same predicate the routes behind this surface use. It gated on
	// `export` while every route it points at asks ai1wm_import_site, so the
	// nav offered a Backups item that answered 403 on open — and told the
	// System page a lower bar than the one actually enforced.
	if ( ! current_user_can( 'ai1wm_import_site' ) || ! Minn_Admin::network_owner() ) {
		return $surfaces;
	}

	$surfaces['ai1wm'] = array(
		'label'      => __( 'Backups', 'minn-admin' ),
		'sub'        => 'All-in-One WP Migration',
		'icon'       => 'database',
		'cap'        => 'read', // the real gate is on the routes, checked above
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/ai1wm/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/ai1wm/exports',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Export', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'date', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'title', 'size_raw', 'ts', 'label' ),
			),
			'actions'   => array(
				array( 'label' => __( 'Download', 'minn-admin' ), 'href' => minn_admin_backup_download_url( 'ai1wm', '{id}' ) ),
				array(
					'label'   => __( 'Delete export', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/ai1wm/exports/{id}',
					'confirm' => __( 'Delete this .wpress export permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

/**
 * One export's archive, for the download door.
 *
 * AI1WM was the one provider whose Download did not go through the door: it
 * handed out its own archive URL, which needs the backups folder to be
 * web-readable and carries no nonce, so the link worked for anyone who had it
 * — or, on the streamed fallback, carried the site's AI1WM secret key in the
 * REST payload. The id only chooses a row; the path is built here and the door
 * proves it is really inside the backups folder before streaming.
 */
function minn_admin_ai1wm_download_files( $id ) {
	if ( ! current_user_can( 'ai1wm_import_site' ) || ! Minn_Admin::network_owner() ) {
		return new WP_Error( 'forbidden', __( 'You are not allowed to download backups.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	if ( ! defined( 'AI1WM_BACKUPS_PATH' ) ) {
		return new WP_Error( 'not_found', __( 'The backup folder could not be found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$filename = minn_admin_ai1wm_id_decode( $id );
	// The same shaping the delete route applies, for the same reason: the
	// route pattern constrains the ENCODED id and says nothing about what
	// comes back out of the decode.
	if ( ! $filename
		|| ! preg_match( '/^[A-Za-z0-9._-]+\.wpress$/', $filename )
		|| false !== strpos( $filename, '..' )
		|| ( function_exists( 'ai1wm_is_filename_supported' ) && ! ai1wm_is_filename_supported( $filename ) )
	) {
		return new WP_Error( 'bad_id', __( 'Invalid export id.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return array(
		array(
			'name' => $filename,
			'path' => trailingslashit( AI1WM_BACKUPS_PATH ) . $filename,
			'root' => AI1WM_BACKUPS_PATH,
		),
	);
}

add_filter( 'minn_admin_backup_download_providers', function ( $r ) {
	$r['ai1wm'] = 'minn_admin_ai1wm_download_files';
	return $r;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ai1wm_active() ) {
		return;
	}
	$perm = function () {
		// Network-shared archive directory (see minn_admin_ai1wm_active).
		// Their Backups screen is registered to ai1wm_import_site, not to
		// export, which is their Export screen. The difference matters on a
		// host that turns off file changes, where that capability resolves to
		// nobody and AI1WM withdraws the archive list from everyone. Reading
		// an archive's name is most of the way to downloading it, so it
		// answers to the same capability their own list does.
		return current_user_can( 'ai1wm_import_site' ) && Minn_Admin::network_owner();
	};
	// Deleting an archive is destructive (a bare unlink, no trash), and
	// AI1WM gates its OWN delete on ai1wm_import_site — a meta cap mapping to
	// import + install_plugins + install_themes, which DISALLOW_FILE_MODS
	// turns off site-wide. Listing at `export` is parity; deleting at
	// `export` is not.
	$perm_delete = function () {
		return current_user_can( 'ai1wm_import_site' ) && Minn_Admin::network_owner();
	};

	register_rest_route( 'minn-admin/v1', '/ai1wm/exports', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$all      = minn_admin_ai1wm_rows();
			$slice    = array_map( function ( $r ) {
				unset( $r['size_raw'] );
				return $r;
			}, array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) );
			return rest_ensure_response( array(
				'items' => $slice,
				'total' => count( $all ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ai1wm/exports/(?P<id>[A-Za-z0-9_-]+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm_delete,
		'callback'            => function ( WP_REST_Request $request ) {
			$filename = minn_admin_ai1wm_id_decode( Minn_Admin::path_param( $request ) );
			// The route pattern constrains the ENCODED id, so it says nothing
			// about what comes back out of the decode. Shape the decoded name
			// ourselves rather than resting on a vendor helper that an older
			// or forked copy may not define — absence of it should not mean
			// absence of a check.
			if ( ! $filename
				|| ! preg_match( '/^[A-Za-z0-9._-]+\.wpress$/', $filename )
				|| false !== strpos( $filename, '..' )
				|| ( function_exists( 'ai1wm_is_filename_supported' ) && ! ai1wm_is_filename_supported( $filename ) )
			) {
				return new WP_Error( 'bad_id', __( 'Invalid export id.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			try {
				$ok = Ai1wm_Backups::delete_file( $filename );
				if ( method_exists( 'Ai1wm_Backups', 'delete_label' ) ) {
					Ai1wm_Backups::delete_label( $filename );
				}
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', __( 'All-in-One WP Migration could not delete: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			if ( ! $ok ) {
				// Already gone counts as success (idempotent).
				foreach ( minn_admin_ai1wm_rows() as $r ) {
					if ( $r['filename'] === $filename ) {
						return new WP_Error( 'delete_failed', __( 'Could not delete the export.', 'minn-admin' ), array( 'status' => 500 ) );
					}
				}
			}
			return rest_ensure_response( array( 'deleted' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ai1wm/export', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return minn_admin_ai1wm_rest_ready() && current_user_can( 'export' );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$options = array();
			foreach ( array( 'no_media', 'no_plugins', 'no_themes', 'no_spam_comments', 'no_post_revisions', 'no_inactive_themes', 'no_inactive_plugins', 'no_muplugins', 'no_cache', 'no_database' ) as $k ) {
				$v = $request->get_param( $k );
				if ( null !== $v && filter_var( $v, FILTER_VALIDATE_BOOLEAN ) ) {
					$options[ $k ] = true;
				}
			}
			$r = minn_admin_ai1wm_dispatch( 'POST', 'exports', array( 'options' => (object) $options ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$job = (string) ( $r['data']['job_id'] ?? '' );
			if ( '' === $job ) {
				return new WP_Error( 'export_failed', __( 'All-in-One WP Migration did not start the export.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'ok'  => true,
				'job' => array(
					'id'          => $job,
					'label'       => __( 'Exporting site', 'minn-admin' ),
					'message'     => __( 'Preparing export…', 'minn-admin' ),
					'statusRoute' => 'minn-admin/v1/ai1wm/export/' . $job,
					'stopRoute'   => 'minn-admin/v1/ai1wm/export/' . $job,
					'stopMethod'  => 'DELETE',
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ai1wm/export/(?P<job>[a-f0-9]{13,40})', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return minn_admin_ai1wm_rest_ready() && current_user_can( 'export' );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				// A stop races the loopback step still running: their cancel
				// writes 'canceled' and deletes the storage folder, and that
				// step then overwrites the status with a "could not open"
				// error. The stop is what the person did, so it is what a
				// later poll (a reload mid-job) reads.
				if ( get_transient( 'minn_ai1wm_canceled_' . $request['job'] ) ) {
					return rest_ensure_response( array( 'status' => 'canceled', 'message' => __( 'Export stopped.', 'minn-admin' ) ) );
				}
				$r = minn_admin_ai1wm_dispatch( 'GET', 'exports/' . $request['job'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				return rest_ensure_response( minn_admin_ai1wm_job_status( $r['data'] ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function () {
				return minn_admin_ai1wm_rest_ready() && current_user_can( 'export' );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$r = minn_admin_ai1wm_dispatch( 'DELETE', 'exports/' . $request['job'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				set_transient( 'minn_ai1wm_canceled_' . $request['job'], 1, 10 * MINUTE_IN_SECONDS );
				return rest_ensure_response( array( 'ok' => true, 'status' => 'canceled', 'message' => __( 'Export stopped.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/ai1wm/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_ai1wm_status_model() );
		},
	) );
} );
