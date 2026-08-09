<?php
/**
 * Shared helper for design-library adapters: sideload the remote images a
 * template references into the media library and swap their URLs, so
 * inserted designs never hotlink a vendor CDN. Deduped by filename (an
 * already-imported image is reused, mirroring Stackable's own endpoint);
 * URLs already pointing at this site are left alone.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param string      $template Serialized block markup.
 * @param string|null $url_re   Optional PCRE matching the image URLs to
 *                              localize; defaults to any remote image URL.
 * @return array { template: string, attachments: int[] }
 */
/**
 * Refuse to fetch a design-library image from a private or loopback address.
 *
 * The default URL pattern matches ANY host, and Kadence/GenerateBlocks call
 * the helper without a pinned regex, so the set of hosts the server will fetch
 * is whatever the remote pattern payload contains. That is blind SSRF reach to
 * cloud metadata (169.254.169.254) and localhost services. Exploitation needs
 * the vendor library or an admin-added connection to misbehave, so this is
 * defence in depth rather than a live hole — but it costs one check.
 *
 * @param string $url Candidate image URL.
 * @return bool
 */
function minn_admin_localize_host_ok( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return false;
	}
	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	$host = strtolower( $parts['host'] );
	if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
		return false;
	}
	// Only judge literal IPs here; a hostname is left to WordPress' own
	// safe-request layer rather than resolved (a DNS lookup per image would be
	// its own problem, and re-resolution makes the check racy anyway).
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return (bool) filter_var(
			$host,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
	return true;
}

function minn_admin_localize_images( $template, $url_re = null ) {
	$attachments = array();
	if ( null === $url_re ) {
		$url_re = '#https?://[^\s"\'()\\\\<>]+\.(?:jpe?g|png|gif|webp|avif|mp4)#i';
	}
	preg_match_all( $url_re, $template, $m );
	$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
	$urls      = array();
	foreach ( array_unique( $m[0] ) as $url ) {
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home_host ) {
			$urls[] = $url;
		}
	}
	$urls = array_slice( $urls, 0, 12 );
	if ( ! $urls || ! current_user_can( 'upload_files' ) ) {
		return array( 'template' => $template, 'attachments' => $attachments );
	}

	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}
	if ( ! function_exists( 'download_url' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	if ( ! function_exists( 'wp_read_image_metadata' ) ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	foreach ( $urls as $url ) {
		try {
			$basename = sanitize_file_name( wp_basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
			if ( '' === $basename ) {
				continue;
			}
			$existing = get_posts( array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'     => '_wp_attached_file',
						'value'   => $basename,
						'compare' => 'LIKE',
					),
				),
			) );

			if ( $existing ) {
				$media_id = $existing[0];
			} else {
				if ( ! minn_admin_localize_host_ok( $url ) ) {
					continue;
				}
				$tmp = download_url( $url );
				if ( is_wp_error( $tmp ) ) {
					continue;
				}
				$media_id = media_handle_sideload( array(
					'name'     => $basename,
					'type'     => mime_content_type( $tmp ),
					'tmp_name' => $tmp,
					'size'     => wp_filesize( $tmp ),
				), 0 );
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				if ( is_wp_error( $media_id ) ) {
					continue;
				}
			}

			$local = wp_get_attachment_url( $media_id );
			if ( $local ) {
				// Cover the plain, JSON-slash-escaped and serializeAttributes
				// forms — server-authored markup mixes them freely.
				$template      = str_replace( array( $url, str_replace( '/', '\/', $url ) ), array( $local, str_replace( '/', '\/', $local ) ), $template );
				$attachments[] = (int) $media_id;
			}
		} catch ( \Throwable $e ) {
			continue; // A failed image keeps its remote URL — still renders.
		}
	}

	return array( 'template' => $template, 'attachments' => $attachments );
}
