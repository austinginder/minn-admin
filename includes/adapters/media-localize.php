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
 * Is this IPv4 address (4 raw bytes) publicly routable? Everything special
 * purpose is refused: this network, private, shared (100.64/10, where some
 * clouds put metadata), loopback, link-local, IETF protocol assignments,
 * documentation, benchmarking, relay, multicast and reserved.
 */
function minn_admin_localize_v4_public( $bytes ) {
	$n = unpack( 'N', $bytes );
	$n = (int) $n[1];
	$blocked = array(
		array( '0.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '100.64.0.0', 10 ), array( '127.0.0.0', 8 ),
		array( '169.254.0.0', 16 ), array( '172.16.0.0', 12 ), array( '192.0.0.0', 24 ), array( '192.0.2.0', 24 ),
		array( '192.88.99.0', 24 ), array( '192.168.0.0', 16 ), array( '198.18.0.0', 15 ), array( '198.51.100.0', 24 ),
		array( '203.0.113.0', 24 ), array( '224.0.0.0', 4 ), array( '240.0.0.0', 4 ),
	);
	foreach ( $blocked as $b ) {
		$mask = $b[1] ? ( ~0 << ( 32 - $b[1] ) ) & 0xFFFFFFFF : 0;
		if ( ( $n & $mask ) === ( ip2long( $b[0] ) & $mask ) ) {
			return false;
		}
	}
	return true;
}

/**
 * A routable public address, IPv4 or IPv6. Judged on the raw bytes, so the
 * answer does not depend on the PHP version's filter flags (7.4 and 8.0 pass
 * ::ffff:169.254.169.254), and every IPv6 form that carries an IPv4 address
 * (mapped, compatible, NAT64, 6to4) is judged by that address.
 *
 * @param string $ip Address.
 * @return bool
 */
function minn_admin_localize_ip_public( $ip ) {
	$bin = @inet_pton( trim( (string) $ip, '[]' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( false === $bin ) {
		return false;
	}
	if ( 4 === strlen( $bin ) ) {
		return minn_admin_localize_v4_public( $bin );
	}
	$hex = bin2hex( $bin );
	// ::ffff:a.b.c.d (mapped), ::a.b.c.d (compatible; also :: and ::1),
	// 64:ff9b::a.b.c.d (NAT64 well-known prefix).
	if ( 0 === strpos( $hex, '00000000000000000000ffff' ) || 0 === strpos( $hex, '000000000000000000000000' ) || 0 === strpos( $hex, '0064ff9b0000000000000000' ) ) {
		return minn_admin_localize_v4_public( substr( $bin, 12, 4 ) );
	}
	// 2002:aabb:ccdd::/16 (6to4) carries a.b.c.d in bytes 2-5.
	if ( 0 === strpos( $hex, '2002' ) ) {
		return minn_admin_localize_v4_public( substr( $bin, 2, 4 ) );
	}
	$first = hexdec( substr( $hex, 0, 4 ) );
	if ( ( $first & 0xFFC0 ) === 0xFE80 // fe80::/10 link-local
		|| ( $first & 0xFE00 ) === 0xFC00 // fc00::/7 unique-local
		|| ( $first & 0xFF00 ) === 0xFF00 // ff00::/8 multicast
		|| 0 === strpos( $hex, '20010db8' ) // 2001:db8::/32 documentation
		|| 0 === strpos( $hex, '20010000' ) // 2001::/32 Teredo (embeds a relay's IPv4)
		|| 0 === strpos( $hex, '0064ff9b0001' ) // 64:ff9b:1::/48 local-use NAT64
		|| 0 === strpos( $hex, '0100000000000000' ) // 100::/64 discard
	) {
		return false;
	}
	return true;
}

/**
 * Resolve a URL's host to the addresses a fetch could reach, or false when
 * any of them is not public (or the host cannot be resolved, or its IPv6
 * lookup fails: an unanswerable check is not a passed one).
 *
 * @param string $url Candidate URL.
 * @return string[]|false Public addresses.
 */
function minn_admin_localize_resolve( $url ) {
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
		return false;
	}
	if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
		return false;
	}
	$host = strtolower( trim( $parts['host'], '[]' ) );
	if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
		return false;
	}
	if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
		return minn_admin_localize_ip_public( $host ) ? array( $host ) : false;
	}
	// A hostname is resolved and every address judged. WordPress' own
	// safe-request check (download_url uses it) refuses private ranges but
	// not link-local 169.254.x.x before WP 7.1, where cloud metadata answers.
	// Only runs for the few images a design insert downloads (capped at 12).
	$ips = (array) gethostbynamel( $host );
	if ( function_exists( 'dns_get_record' ) ) {
		$aaaa = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $aaaa ) {
			return false;
		}
		foreach ( (array) $aaaa as $rec ) {
			if ( ! empty( $rec['ipv6'] ) ) {
				$ips[] = $rec['ipv6'];
			}
		}
	}
	$ips = array_values( array_filter( $ips ) );
	if ( ! $ips ) {
		return false;
	}
	foreach ( $ips as $ip ) {
		if ( ! minn_admin_localize_ip_public( $ip ) ) {
			return false;
		}
	}
	return $ips;
}

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
function minn_admin_localize_host_ok( $url ) {
	return false !== minn_admin_localize_resolve( $url );
}

/**
 * Download one design-library image with every hop judged and PINNED: the
 * addresses checked are the ones curl connects to (CURLOPT_RESOLVE), so a
 * DNS answer that changes between the check and the fetch cannot move it.
 *
 * @param string $url Image URL.
 * @return string|WP_Error Temp file path.
 */
function minn_admin_localize_download( $url ) {
	$pins = array();
	$pin  = function ( $u ) use ( &$pins ) {
		$ips = minn_admin_localize_resolve( $u );
		if ( ! $ips ) {
			return false;
		}
		$p    = wp_parse_url( $u );
		$host = strtolower( trim( $p['host'], '[]' ) );
		$port = ! empty( $p['port'] ) ? (int) $p['port'] : ( 'https' === strtolower( $p['scheme'] ) ? 443 : 80 );
		$ip   = $ips[0];
		$pins[ $host . ':' . $port ] = $host . ':' . $port . ':' . ( false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip );
		return true;
	};
	if ( ! $pin( $url ) ) {
		return new WP_Error( 'minn_unsafe_host', __( 'Refused a non-public address.', 'minn-admin' ) );
	}
	// Every redirect hop is judged and pinned the same way: core's own
	// redirect check does not refuse link-local before WP 7.1.
	$hop  = function ( $location ) use ( $pin ) {
		if ( ! $pin( (string) $location ) ) {
			// Requests 2 (WP 6.2+) or Requests 1: either way WP_Http turns
			// it into a WP_Error for download_url().
			$cls = class_exists( '\\WpOrg\\Requests\\Exception' ) ? '\\WpOrg\\Requests\\Exception' : 'Requests_Exception';
			throw new $cls( __( 'Redirect to a non-public address refused.', 'minn-admin' ), 'minn_unsafe_redirect' );
		}
	};
	$curl = function ( $handle ) use ( &$pins ) {
		if ( $pins && defined( 'CURLOPT_RESOLVE' ) ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, array_values( $pins ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	};
	add_action( 'requests-requests.before_redirect', $hop );
	add_action( 'http_api_curl', $curl );
	try {
		return download_url( $url );
	} finally {
		remove_action( 'requests-requests.before_redirect', $hop );
		remove_action( 'http_api_curl', $curl );
	}
}

/**
 * @param string      $template Serialized block markup.
 * @param string|null $url_re   Optional PCRE matching the image URLs to
 *                              localize; defaults to any remote image URL.
 * @return array { template: string, attachments: int[] }
 */
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
				$tmp = minn_admin_localize_download( $url );
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
