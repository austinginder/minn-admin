<?php
/**
 * One download door for backup archives that live on this server.
 *
 * REST cannot serve these: a link opened in a new tab carries no nonce
 * header, and WordPress treats a REST request without one as logged out.
 * admin-post.php is the cookie-authenticated door WordPress already has,
 * so the link is admin-post.php?action=minn_admin_backup_download with a
 * nonce, and each provider adapter answers with the files a row stands for
 * (a function named minn_admin_{provider}_download_files, given the row id;
 * an array of [ name, path ] entries, or a WP_Error). One file streams; a
 * set with several shows a short page of one link per file; the caller may
 * name a part to skip that page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MINN_ADMIN_BACKUP_DOWNLOAD_ACTION = 'minn_admin_backup_download';

/** The nonce-carrying link for a row (or one part of it). {id} survives for the row placeholder. */
function minn_admin_backup_download_url( $provider, $id = '{id}', $part = '' ) {
	$args = array(
		'action'   => MINN_ADMIN_BACKUP_DOWNLOAD_ACTION,
		'provider' => $provider,
		'id'       => $id,
		'_wpnonce' => wp_create_nonce( MINN_ADMIN_BACKUP_DOWNLOAD_ACTION ),
	);
	if ( '' !== $part ) {
		$args['part'] = $part;
	}
	// add_query_arg would encode the braces; the row placeholder must stay.
	return str_replace( '%7Bid%7D', '{id}', add_query_arg( $args, admin_url( 'admin-post.php' ) ) );
}

/** Is $path a real file inside $root? (no traversal, no symlink escape) */
function minn_admin_backup_path_inside( $path, $root ) {
	$real = realpath( (string) $path );
	$base = realpath( (string) $root );
	if ( ! $real || ! $base || ! is_file( $real ) ) {
		return false;
	}
	return 0 === strpos( $real, rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR );
}

/** Send a file as an attachment and stop. */
function minn_admin_backup_stream( $path, $name ) {
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	while ( ob_get_level() ) {
		ob_end_clean();
	}
	nocache_headers();
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
	header( 'Content-Length: ' . filesize( $path ) );
	header( 'X-Content-Type-Options: nosniff' );
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( ! $fh ) {
		wp_die( esc_html__( 'The file could not be opened.', 'minn-admin' ), '', array( 'response' => 500 ) );
	}
	while ( ! feof( $fh ) ) {
		echo fread( $fh, 1024 * 1024 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread
		flush();
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}

add_action( 'admin_post_' . MINN_ADMIN_BACKUP_DOWNLOAD_ACTION, function () {
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), MINN_ADMIN_BACKUP_DOWNLOAD_ACTION ) ) {
		wp_die( esc_html__( 'This download link has expired. Open the backup row again.', 'minn-admin' ), '', array( 'response' => 403 ) );
	}
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You are not allowed to download backups.', 'minn-admin' ), '', array( 'response' => 403 ) );
	}
	$provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
	$id       = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
	$part     = isset( $_GET['part'] ) ? sanitize_text_field( wp_unslash( $_GET['part'] ) ) : '';
	$resolver = 'minn_admin_' . str_replace( '-', '_', $provider ) . '_download_files';
	if ( '' === $provider || '' === $id || ! function_exists( $resolver ) ) {
		wp_die( esc_html__( 'Unknown backup provider.', 'minn-admin' ), '', array( 'response' => 404 ) );
	}
	// The provider decides who may download (its own capability) and which
	// files a row stands for; every path is checked against its folder.
	$files = call_user_func( $resolver, $id );
	if ( is_wp_error( $files ) ) {
		$data = $files->get_error_data();
		wp_die( esc_html( $files->get_error_message() ), '', array( 'response' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 404 ) );
	}
	$files = array_values( array_filter( (array) $files, function ( $f ) {
		return is_array( $f ) && ! empty( $f['path'] ) && ! empty( $f['root'] ) && minn_admin_backup_path_inside( $f['path'], $f['root'] );
	} ) );
	if ( ! $files ) {
		wp_die( esc_html__( 'No file for this backup is on this server. It may be stored remotely only.', 'minn-admin' ), '', array( 'response' => 404 ) );
	}
	if ( '' !== $part ) {
		foreach ( $files as $f ) {
			if ( isset( $f['part'] ) && (string) $f['part'] === $part ) {
				minn_admin_backup_stream( $f['path'], ! empty( $f['name'] ) ? $f['name'] : basename( $f['path'] ) );
			}
		}
		wp_die( esc_html__( 'That part of the backup is not on this server.', 'minn-admin' ), '', array( 'response' => 404 ) );
	}
	if ( 1 === count( $files ) ) {
		minn_admin_backup_stream( $files[0]['path'], ! empty( $files[0]['name'] ) ? $files[0]['name'] : basename( $files[0]['path'] ) );
	}
	// Several files: a page of links, one per file, on the same door.
	$links = '';
	foreach ( $files as $f ) {
		$url    = minn_admin_backup_download_url( $provider, $id, isset( $f['part'] ) ? (string) $f['part'] : basename( $f['path'] ) );
		$label  = ! empty( $f['label'] ) ? $f['label'] : ( ! empty( $f['name'] ) ? $f['name'] : basename( $f['path'] ) );
		$size   = size_format( (int) filesize( $f['path'] ) );
		$links .= '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a> <span>' . esc_html( $size ) . '</span></li>';
	}
	nocache_headers();
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Download backup', 'minn-admin' ) . '</title>'
		. '<style>body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#1c1d22;background:#f6f6f8;margin:0;padding:48px 24px}'
		. 'main{max-width:520px;margin:0 auto;background:#fff;border:1px solid #e3e4ea;border-radius:12px;padding:24px 28px}'
		. 'h1{font-size:17px;margin:0 0 4px}p{margin:0 0 16px;color:#5f6270}ul{list-style:none;padding:0;margin:0}'
		. 'li{display:flex;justify-content:space-between;gap:16px;padding:10px 0;border-top:1px solid #eef0f4}li:first-child{border-top:0}'
		. 'a{color:#4f46e5;text-decoration:none;font-weight:600}a:hover{text-decoration:underline}span{color:#8a8d9a;white-space:nowrap}</style></head><body><main>'
		. '<h1>' . esc_html__( 'This backup is several files', 'minn-admin' ) . '</h1>'
		. '<p>' . esc_html__( 'Download each one you need.', 'minn-admin' ) . '</p><ul>' . $links . '</ul></main></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
} );
