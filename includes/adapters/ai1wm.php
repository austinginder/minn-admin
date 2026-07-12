<?php
/**
 * Bundled adapter: All-in-One WP Migration — backups family.
 *
 * LIST-ONLY of local .wpress exports under AI1WM_BACKUPS_PATH via
 * Ai1wm_Backups::get_files() (their own recursive iterator). Delete goes
 * through Ai1wm_Backups::delete_file() + delete_label() so label cleanup
 * stays their code. Exports and imports stay on ServMask's multi-step
 * wizard screens (deep links) — free AIOWM does not expose a simple
 * "export now" callable outside that flow.
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
			'id'       => minn_admin_ai1wm_id_encode( $filename ),
			'filename' => $filename,
			'label'    => $label,
			'title'    => $label ? $label : $filename,
			'size'     => $size ? size_format( $size ) : '—',
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
				'label' => 'Newest export',
				'value' => $newest ? $newest['title'] : 'None yet',
				'hint'  => $newest && $newest['ts']
					? human_time_diff( $newest['ts'] ) . ' ago · ' . $newest['size']
					: 'Exports are built manually from All-in-One WP Migration\'s screen.',
			),
			array(
				'label' => 'Exports',
				'value' => (string) count( $rows ),
				'hint'  => 'Manual exports; Minn makes no freshness claims for All-in-One WP Migration.',
			),
			array(
				'label' => 'On disk',
				'value' => $disk ? size_format( $disk ) : '0 B',
				'hint'  => $hint,
			),
		),
		'actions' => array(
			array(
				'label' => 'Export site ↗',
				'href'  => admin_url( 'admin.php?page=ai1wm_export' ),
			),
			array(
				'label' => 'Open backups ↗',
				'href'  => admin_url( 'admin.php?page=ai1wm_backups' ),
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_ai1wm_active() ) {
		return $surfaces;
	}
	if ( ! current_user_can( 'export' ) ) {
		return $surfaces;
	}

	$surfaces['ai1wm'] = array(
		'label'      => 'Backups',
		'sub'        => 'All-in-One WP Migration',
		'icon'       => 'database',
		'cap'        => 'export',
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/ai1wm/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/ai1wm/exports',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Export', 'format' => 'title' ),
				array( 'key' => 'size', 'label' => 'Size', 'format' => 'text' ),
				array( 'key' => 'date', 'label' => 'Created', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'skip' => array( 'title', 'size_raw', 'ts', 'label' ),
			),
			'actions'   => array(
				array(
					'label'   => 'Delete export',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/ai1wm/exports/{id}',
					'confirm' => 'Delete this .wpress export permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ai1wm_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( 'export' );
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
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$filename = minn_admin_ai1wm_id_decode( (string) $request['id'] );
			if ( ! $filename
				|| false !== strpos( $filename, '..' )
				|| ( function_exists( 'ai1wm_is_filename_supported' ) && ! ai1wm_is_filename_supported( $filename ) )
			) {
				return new WP_Error( 'bad_id', 'Invalid export id.', array( 'status' => 400 ) );
			}
			try {
				$ok = Ai1wm_Backups::delete_file( $filename );
				if ( method_exists( 'Ai1wm_Backups', 'delete_label' ) ) {
					Ai1wm_Backups::delete_label( $filename );
				}
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', 'All-in-One WP Migration could not delete: ' . $e->getMessage(), array( 'status' => 500 ) );
			}
			if ( ! $ok ) {
				// Already gone counts as success (idempotent).
				foreach ( minn_admin_ai1wm_rows() as $r ) {
					if ( $r['filename'] === $filename ) {
						return new WP_Error( 'delete_failed', 'Could not delete the export.', array( 'status' => 500 ) );
					}
				}
			}
			return rest_ensure_response( array( 'deleted' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ai1wm/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_ai1wm_status_model() );
		},
	) );
} );
