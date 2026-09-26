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
 * defence in depth rather than a live hole, but it costs one check.
 *
 * @param string $url Candidate image URL.
 * @return bool
 */
/**
 * A routable public address: not private, reserved, loopback or link-local
 * (PHP's filter flags), and not the 100.64.0.0/10 shared range some clouds
 * put metadata services on, which the flags do not cover.
 */
function minn_admin_localize_ip_public( $ip ) {
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return false;
	}
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
		$n = ip2long( $ip );
		if ( ( $n & 0xFFC00000 ) === ( ip2long( '100.64.0.0' ) & 0xFFC00000 ) ) {
			return false;
		}
	} elseif ( preg_match( '/^(fe80|fc|fd)/i', $ip ) ) {
		return false; // link-local and unique-local IPv6
	}
	return true;
}

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
	$public = 'minn_admin_localize_ip_public';
	$bare = trim( $host, '[]' );
	if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
		return $public( $bare );
	}
	// A hostname is resolved and every address judged. WordPress' own
	// safe-request check (download_url uses it) refuses private ranges but
	// not link-local 169.254.x.x, where cloud metadata answers. Only runs for
	// the few images a design insert actually downloads (capped at 12).
	$ips = (array) gethostbynamel( $host );
	if ( function_exists( 'dns_get_record' ) ) {
		foreach ( (array) @dns_get_record( $host, DNS_AAAA ) as $rec ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! empty( $rec['ipv6'] ) ) {
				$ips[] = $rec['ipv6'];
			}
		}
	}
	$ips = array_filter( $ips );
	if ( ! $ips ) {
		return false;
	}
	foreach ( $ips as $ip ) {
		if ( ! $public( $ip ) ) {
			return false;
		}
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
			// The whole trailing segment, anchored at BOTH ends. WP_Meta_Query
			// wraps a bare LIKE as %value%, and prefixing the '/' only fixed
			// the front: "hero.jpg" still matched "hero.jpg.webp", so the
			// design silently pointed at a different picture. There is no way
			// to drop the trailing wildcard through meta_query, so ask
			// directly. Uploads that are not organised into month folders have
			// no '/' at all, hence the equality arm.
			global $wpdb;
			$existing = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_wp_attached_file'
				   AND ( pm.meta_value LIKE %s OR pm.meta_value = %s )
				   AND p.post_type = 'attachment'
				 LIMIT 1",
				'%/' . $wpdb->esc_like( $basename ),
				$basename
			) );

			if ( $existing ) {
				$media_id = $existing;
			} else {
				if ( ! minn_admin_localize_host_ok( $url ) ) {
					continue;
				}
				// Every redirect hop is judged the same way: core's own
				// redirect check does not refuse link-local before WP 7.1.
				$hop = function ( $location ) {
					if ( ! minn_admin_localize_host_ok( (string) $location ) ) {
						// Requests 2 (WP 6.2+) or Requests 1: either way WP_Http
						// turns it into a WP_Error for download_url().
						$cls = class_exists( '\\WpOrg\\Requests\\Exception' ) ? '\\WpOrg\\Requests\\Exception' : 'Requests_Exception';
						throw new $cls( 'Redirect to a non-public address refused.', 'minn_unsafe_redirect' );
					}
				};
				add_action( 'requests-requests.before_redirect', $hop );
				try {
					$tmp = download_url( $url );
				} finally {
					remove_action( 'requests-requests.before_redirect', $hop );
				}
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
