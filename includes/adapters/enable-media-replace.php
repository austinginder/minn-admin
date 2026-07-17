<?php
/**
 * Enable Media Replace (ShortPixel, 600k+) — a "Replace file" action on the
 * media detail modal.
 *
 * The plugin's own ReplaceController does everything (old files removed,
 * new file copied over the same path, attachment metadata + thumbnails
 * regenerated, thumbnail references search-replaced, caches kicked, their
 * wp_handle_replace / enable-media-replace-upload-done hooks fired). Minn
 * only adds the per-item entry point and scopes it to EMR's plain replace
 * mode: same filename, same URL. Rename-and-search or moving the file to a
 * new location stays on EMR's own screen in wp-admin.
 *
 * Caps mirror EMR exactly: upload_files plus their checkImagePermission()
 * (which honors an EMR_CAPABILITY wp-config override and falls back to
 * edit_post on the attachment).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether EMR is loaded and the current user may replace files at all
 * (drives the boot flag; the endpoint re-checks per attachment).
 */
function minn_admin_emr_available() {
	return function_exists( 'emr' )
		&& class_exists( '\EnableMediaReplace\Controller\ReplaceController' )
		&& current_user_can( 'upload_files' );
}

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'emr' ) ) {
		return;
	}
	register_rest_route(
		'minn-admin/v1',
		'/media/(?P<id>\d+)/replace',
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) {
				return minn_admin_emr_available()
					&& emr()->checkImagePermission( get_post( (int) $req['id'] ) );
			},
			'callback'            => 'minn_admin_emr_replace',
		)
	);
} );

function minn_admin_emr_replace( $req ) {
	$post_id = (int) $req['id'];
	$post    = get_post( $post_id );
	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error( 'minn_emr_not_attachment', 'Not a media item.', array( 'status' => 404 ) );
	}

	$files = $req->get_file_params();
	$file  = isset( $files['file'] ) ? $files['file'] : null;
	if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'minn_emr_no_file', 'The upload did not arrive. Try again.', array( 'status' => 400 ) );
	}

	// Validate the upload the way core does, then hold EMR's plain-replace
	// contract: the file keeps its name and URL, so the new content must be
	// the same type or the extension would lie about what it serves.
	$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	if ( empty( $check['ext'] ) || empty( $check['type'] ) ) {
		return new WP_Error( 'minn_emr_bad_type', 'That file type is not allowed here.', array( 'status' => 400 ) );
	}
	$current_mime = get_post_mime_type( $post_id );
	if ( $current_mime && $check['type'] !== $current_mime ) {
		return new WP_Error(
			'minn_emr_type_mismatch',
			sprintf( 'Replacing in place keeps the same URL, so the new file must stay %s. To swap the type, upload it as a new file.', $current_mime ),
			array( 'status' => 400 )
		);
	}

	$controller = new \EnableMediaReplace\Controller\ReplaceController( $post_id );
	$params     = array(
		'post_id'           => $post_id,
		'replace_type'      => 'replace',
		// Keep the upload date, refresh only the modified stamp — EMR's
		// TIME_UPDATEMODIFIED, its own default.
		'timestamp_replace' => \EnableMediaReplace\Controller\ReplaceController::TIME_UPDATEMODIFIED,
		'new_date'          => current_time( 'mysql' ),
		'new_location'      => false,
		'location_dir'      => null,
		'is_custom_date'    => false,
		'remove_background' => false,
		'uploadFile'        => $file['tmp_name'],
		'new_filename'      => sanitize_file_name( $file['name'] ),
	);

	try {
		if ( false === $controller->setupParams( $params ) ) {
			return new WP_Error(
				'minn_emr_setup_failed',
				'Enable Media Replace refused the file (error ' . (int) $controller->returnLastError() . ').',
				array( 'status' => 400 )
			);
		}
		$controller->run();
		$err = $controller->returnLastError();
		if ( $err ) {
			return new WP_Error( 'minn_emr_failed', 'The replace did not complete (error ' . (int) $err . ').', array( 'status' => 500 ) );
		}
	} catch ( \Throwable $e ) {
		return new WP_Error( 'minn_emr_failed', $e->getMessage(), array( 'status' => 500 ) );
	}

	clean_post_cache( $post_id );
	$meta = wp_get_attachment_metadata( $post_id );
	$path = get_attached_file( $post_id );
	return rest_ensure_response( array(
		'ok'       => true,
		'url'      => wp_get_attachment_url( $post_id ),
		'mime'     => get_post_mime_type( $post_id ),
		'width'    => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'   => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'filesize' => ( $path && file_exists( $path ) ) ? filesize( $path ) : null,
	) );
}
