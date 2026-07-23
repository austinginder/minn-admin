<?php
/**
 * Bundled adapter: Disembark backups.
 *
 * Disembark is a backup CONNECTOR, not a scheduler: the Disembark CLI (or
 * disembark.host) pulls a backup off-site through the plugin's token-guarded
 * REST namespace, and the site keeps no record that a pull completed. So this
 * surface never claims to answer "is my site backed up". What it does show:
 * the backup profile (last scan, database size, working files on disk), the
 * exact CLI command to run a backup from any terminal, the scan sessions
 * Disembark left behind, and cleanup for the whole-site archives those
 * sessions can hold in uploads/disembark/. Everything reads the plugin's own
 * options and file layout server-side under manage_options; Disembark's
 * token-authenticated HTTP routes are never called.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_disembark_active() {
	return class_exists( '\\Disembark\\Token' );
}

function minn_admin_disembark_dir() {
	return trailingslashit( wp_upload_dir()['basedir'] ) . 'disembark/';
}

/** Bytes (and file count) under a directory; links skipped like their cleanup. */
function minn_admin_disembark_du( $dir ) {
	$bytes = 0;
	$files = 0;
	if ( is_dir( $dir ) ) {
		$iter = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iter as $file ) {
			if ( $file->isFile() && ! $file->isLink() ) {
				$bytes += $file->getSize();
				$files++;
			}
		}
	}
	return array( $bytes, $files );
}

/** Scan sessions (workspace dirs with a finished manifest), newest first. */
function minn_admin_disembark_sessions() {
	$out = array();
	foreach ( glob( minn_admin_disembark_dir() . '*', GLOB_ONLYDIR ) ?: array() as $session ) {
		$manifest = $session . '/manifest.json';
		if ( ! file_exists( $manifest ) ) {
			continue;
		}
		list( $bytes, $files ) = minn_admin_disembark_du( $session );
		$token = basename( $session );
		$out[] = array(
			'id'     => $token,
			'name'   => 'Session ' . substr( $token, 0, 8 ),
			'chunks' => count( glob( $session . '/files-*.json' ) ?: array() ),
			'files'  => $files,
			'size'   => size_format( $bytes ),
			'date'   => gmdate( 'Y-m-d\TH:i:s\Z', (int) filemtime( $manifest ) ),
			'ts'     => (int) filemtime( $manifest ),
		);
	}
	usort( $out, function ( $a, $b ) {
		return $b['ts'] - $a['ts'];
	} );
	return $out;
}

/**
 * Delete every file (and then every empty dir) under $dir — Disembark's own
 * /cleanup logic, minus its text/plain streaming. The base disembark/ dir
 * itself stays. Symlinks are never followed or deleted through.
 */
function minn_admin_disembark_rm_contents( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $files as $file ) {
		if ( ! $file->isDir() && ! $file->isLink() ) {
			@unlink( $file->getPathname() );
		}
	}
	$dirs = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $dirs as $d ) {
		if ( $d->isDir() ) {
			@rmdir( $d->getPathname() );
		}
	}
}

/** The connect string `wp disembark cli-info` prints — the whole product in one line. */
function minn_admin_disembark_command() {
	return 'disembark connect ' . home_url() . ' ' . \Disembark\Token::get();
}

/** Server-built model for the surface status card. */
function minn_admin_disembark_status_model() {
	global $wpdb;
	$scan     = get_option( 'disembark_last_scan_stats', null );
	$sessions = minn_admin_disembark_sessions();
	list( $bytes ) = minn_admin_disembark_du( minn_admin_disembark_dir() );
	$db = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
		DB_NAME
	) );

	$rows = array(
		array(
			'label' => 'Last scan',
			'value' => is_array( $scan ) && ! empty( $scan['timestamp'] )
				? human_time_diff( (int) $scan['timestamp'] ) . ' ago'
				: 'Never',
			'hint'  => is_array( $scan ) && ! empty( $scan['timestamp'] )
				? number_format_i18n( (int) ( $scan['total_files'] ?? 0 ) ) . ' files · ' . size_format( (int) ( $scan['total_size'] ?? 0 ) )
				: 'Run one from the CLI or Tools → Disembark',
		),
		array(
			'label' => 'Database',
			'value' => $db ? size_format( $db ) : '—',
			'hint'  => 'exported with every backup',
		),
		array(
			'label' => 'Working files',
			'value' => $bytes ? size_format( $bytes ) : 'None',
			'hint'  => $bytes
				/* translators: %s: number of Disembark scan sessions. */
				? sprintf( _n( '%s scan session on disk', '%s scan sessions on disk', count( $sessions ), 'minn-admin' ), number_format_i18n( count( $sessions ) ) )
				: 'Nothing left behind',
		),
	);

	$actions = array();
	if ( $bytes ) {
		$actions[] = array(
			'label'   => 'Clean up working files',
			'route'   => 'minn-admin/v1/disembark/cleanup',
			'method'  => 'POST',
			'confirm' => sprintf(
				'Delete %s of Disembark working files (manifests, exports, zips)? Backups you already downloaded are unaffected.',
				size_format( $bytes )
			),
			'danger'  => true,
		);
	}
	$actions[] = array(
		'label'   => 'Regenerate token',
		'route'   => 'minn-admin/v1/disembark/regenerate-token',
		'method'  => 'POST',
		'confirm' => 'Regenerate the site token? The current CLI command and any saved connections stop working.',
	);
	$actions[] = array(
		'label' => 'Open Disembark ↗',
		'href'  => admin_url( 'tools.php?page=disembark' ),
	);

	return array(
		'rows'    => $rows,
		'command' => array(
			'label' => 'Back up from any terminal',
			'text'  => minn_admin_disembark_command(),
			'hint'  => 'Requires the Disembark CLI; disembark.host runs the same flow from a browser.',
		),
		'actions' => $actions,
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_disembark_active() ) {
		return $surfaces;
	}
	$surfaces['disembark'] = array(
		'label'      => 'Backups',
		'sub'        => 'Disembark',
		'icon'       => 'database',
		'cap'        => 'manage_options',
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/disembark/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/disembark/sessions',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'viewLabel' => 'Scan sessions',
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'Scan session', 'format' => 'title' ),
				array( 'key' => 'chunks', 'label' => 'Chunks', 'format' => 'num' ),
				array( 'key' => 'size', 'label' => 'On disk', 'format' => 'text' ),
				array( 'key' => 'date', 'label' => 'Scanned', 'format' => 'ago', 'utc' => true ),
			),
			'actions'   => array(
				array(
					'label'   => 'Delete session files',
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/disembark/sessions/{id}/delete',
					'confirm' => 'Delete this scan session\'s files from the server?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_disembark_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'minn-admin/v1', '/disembark/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_disembark_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/disembark/sessions', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$all      = minn_admin_disembark_sessions();
			return rest_ensure_response( array(
				'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $all ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/disembark/sessions/(?P<id>[A-Za-z0-9]{4,64})/delete', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$base = minn_admin_disembark_dir();
			// The id pattern already forbids traversal; realpath containment
			// is belt-and-braces before a recursive delete.
			$dir  = realpath( $base . $request['id'] );
			$root = realpath( $base );
			if ( ! $dir || ! $root || 0 !== strpos( $dir, $root . DIRECTORY_SEPARATOR ) || ! is_dir( $dir ) ) {
				return new WP_Error( 'not_found', 'No such session.', array( 'status' => 404 ) );
			}
			minn_admin_disembark_rm_contents( $dir );
			@rmdir( $dir );
			return rest_ensure_response( array( 'ok' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/disembark/cleanup', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function () {
			list( $bytes ) = minn_admin_disembark_du( minn_admin_disembark_dir() );
			minn_admin_disembark_rm_contents( minn_admin_disembark_dir() );
			return rest_ensure_response( array(
				'ok'    => true,
				'freed' => $bytes,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/disembark/regenerate-token', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function () {
			// Same write their own regenerate endpoint performs.
			update_option( 'disembark_token', wp_generate_password( 42, false ) );
			return rest_ensure_response( array(
				'ok'      => true,
				'command' => minn_admin_disembark_command(),
			) );
		},
	) );
} );
