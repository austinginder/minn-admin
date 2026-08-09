<?php
/**
 * Self-updater. Checks the manifest.json on GitHub and feeds WordPress the
 * update package from GitHub Releases — same pattern as Disembark.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Updater {

	public $plugin_slug;
	public $version;
	public $cache_key;
	public $cache_allowed;

	const MANIFEST_URL = 'https://raw.githubusercontent.com/austinginder/minn-admin/main/manifest.json';

	/** Hosts the update package may legitimately come from. */
	const PACKAGE_HOSTS = array( 'github.com', 'objects.githubusercontent.com', 'codeload.github.com' );

	public function __construct() {
		// Test the VALUE, not just the definition: define( 'MINN_ADMIN_DEV_MODE',
		// false ) is the natural way to switch a dev flag off and used to leave
		// TLS verification disabled anyway. And scope the relaxation to this
		// plugin's own requests — the global filters turned off certificate
		// verification for EVERY outbound HTTPS request WordPress makes
		// (api.wordpress.org updates, license checks, payment APIs), and
		// http_request_host_is_external => __return_true removed the
		// internal-address protection wp_safe_remote_get() relies on, turning a
		// blocked SSRF in any other plugin into a reachable one.
		if ( defined( 'MINN_ADMIN_DEV_MODE' ) && MINN_ADMIN_DEV_MODE ) {
			add_filter( 'http_request_args', array( $this, 'dev_mode_request_args' ), 10, 2 );
		}
		$this->plugin_slug   = 'minn-admin';
		$this->version       = MINN_ADMIN_VERSION;
		$this->cache_key     = 'minn_admin_updater';
		// Honour the transient. With this false the guard in request() was
		// always true, so EVERY read of the update transient — most wp-admin
		// page loads, wp-cron, the admin-bar nag — made a live 30s GitHub
		// request, and so did every unrelated plugin/theme/core download.
		$this->cache_allowed = true;

		add_filter( 'plugins_api', array( $this, 'info' ), 30, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_package' ), 10, 4 );
	}

	/**
	 * Relax TLS for THIS plugin's own requests only, under dev mode.
	 *
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function dev_mode_request_args( $args, $url ) {
		if ( self::MANIFEST_URL === $url || $this->is_our_package_url( $url ) ) {
			$args['sslverify'] = false;
		}
		return $args;
	}

	/**
	 * Whether a URL is a plausible package URL for this plugin: https, on a
	 * GitHub host, under this repo. The manifest is fetched over TLS from a
	 * pinned URL, but its download_url was previously handed to the upgrader
	 * with no check at all — a manifest naming an http:// URL would have
	 * WordPress fetch executable code in the clear.
	 *
	 * @param string $url Candidate URL.
	 * @return bool
	 */
	public function is_our_package_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		if ( ! in_array( $host, self::PACKAGE_HOSTS, true ) ) {
			return false;
		}
		return false !== strpos( (string) ( $parts['path'] ?? '' ), '/austinginder/minn-admin/' );
	}

	/**
	 * Verify the update zip against the manifest's sha256 before install.
	 *
	 * Only intercepts our own package URL (https, GitHub host, this repo), and
	 * REQUIRES the manifest to publish a sha256 for it. The manifest travels
	 * over TLS from the repo while the zip comes from GitHub's release CDN; the
	 * pinned hash ties the two together.
	 *
	 * @param bool|string|WP_Error $reply      Filter chain value.
	 * @param string               $package    Package URL being downloaded.
	 * @param WP_Upgrader          $upgrader   Upgrader instance.
	 * @param array                $hook_extra Extra install context.
	 * @return bool|string|WP_Error Local file path on verified download.
	 */
	public function verify_package( $reply, $package, $upgrader, $hook_extra = array() ) {
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		// Cheap check BEFORE the network call: this filter fires for every
		// plugin, theme and core download on the site, and request() used to
		// block each one on a GitHub fetch just to discover it wasn't ours.
		if ( ! $this->is_our_package_url( $package ) ) {
			return $reply;
		}
		$remote = $this->request();
		if ( empty( $remote->download_url ) || $package !== $remote->download_url ) {
			return $reply;
		}
		// The hash is MANDATORY for our own package. Treating "manifest has no
		// sha256" as "verification not required" makes integrity opt-out for
		// whoever serves the manifest, and silently degrades if a release
		// script ever forgets the field.
		if ( empty( $remote->sha256 ) ) {
			return new WP_Error(
				'minn_admin_missing_package_hash',
				'Minn Admin update rejected: the release manifest does not publish a sha256 for this package.'
			);
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$hash = (string) hash_file( 'sha256', $file );
		if ( ! hash_equals( strtolower( (string) $remote->sha256 ), $hash ) ) {
			wp_delete_file( $file );
			return new WP_Error(
				'minn_admin_bad_package_hash',
				'Minn Admin update rejected: the downloaded package does not match the sha256 published in the release manifest.'
			);
		}
		return $file;
	}

	public function request() {
		// Get the local manifest as a fallback.
		$manifest_file  = dirname( __DIR__ ) . '/manifest.json';
		$local_manifest = null;
		if ( file_exists( $manifest_file ) ) {
			$local_manifest = json_decode( file_get_contents( $manifest_file ) );
		}

		if ( ! is_object( $local_manifest ) ) {
			$local_manifest = new \stdClass();
		}

		$remote = get_transient( $this->cache_key );

		if ( false === $remote || ! $this->cache_allowed ) {
			$remote_response = wp_remote_get(
				self::MANIFEST_URL,
				array(
					'timeout' => 30,
					'headers' => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $remote_response ) || 200 !== wp_remote_retrieve_response_code( $remote_response ) || empty( wp_remote_retrieve_body( $remote_response ) ) ) {
				// Back off briefly on failure too, or an unreachable GitHub
				// re-blocks on the next pageload, and the next, and the next.
				set_transient( $this->cache_key, $local_manifest, 5 * MINUTE_IN_SECONDS );
				return $local_manifest;
			}

			$remote = json_decode( wp_remote_retrieve_body( $remote_response ) );
			// An hour, not a day: long enough that the update transient's many
			// readers (admin page loads, wp-cron, the admin-bar nag) stop
			// making a live 30s GitHub request each, short enough that a fresh
			// release still surfaces promptly. `wp transient delete
			// minn_admin_updater` forces an immediate re-read.
			set_transient( $this->cache_key, $remote, HOUR_IN_SECONDS );
		}

		if ( is_object( $remote ) ) {
			return $remote;
		}

		return $local_manifest;
	}

	public function info( $response, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $this->plugin_slug !== $args->slug ) {
			return $response;
		}

		$remote = $this->request();
		if ( ! $remote || empty( $remote->version ) ) {
			return $response;
		}

		$response                 = new \stdClass();
		$response->name           = $remote->name;
		$response->slug           = $remote->slug;
		$response->version        = $remote->version;
		$response->tested         = $remote->tested;
		$response->requires       = $remote->requires;
		$response->author         = $remote->author;
		$response->author_profile = $remote->author_profile;
		$response->homepage       = $remote->homepage;
		$response->download_link  = $remote->download_url;
		$response->trunk          = $remote->download_url;
		$response->requires_php   = $remote->requires_php;
		$response->last_updated   = $remote->last_updated;
		$response->sections       = array( 'description' => $remote->sections->description );

		if ( ! empty( $remote->banners ) ) {
			$response->banners = array(
				'low'  => $remote->banners->low,
				'high' => $remote->banners->high,
			);
		}
		return $response;
	}

	public function update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->request();
		// Never offer an update whose package we would refuse to install: the
		// URL has to be https on a GitHub host under this repo, and the
		// manifest has to publish a hash to check the download against.
		if ( $remote && ! empty( $remote->download_url )
			&& ( ! $this->is_our_package_url( $remote->download_url ) || empty( $remote->sha256 ) ) ) {
			return $transient;
		}
		if ( $remote && isset( $remote->version ) && version_compare( $this->version, $remote->version, '<' ) ) {
			$response               = new \stdClass();
			$response->slug         = $this->plugin_slug;
			$response->plugin       = "{$this->plugin_slug}/{$this->plugin_slug}.php";
			$response->new_version  = $remote->version;
			$response->package      = $remote->download_url;
			$response->tested       = $remote->tested;
			$response->requires_php = $remote->requires_php;

			$transient->response[ $response->plugin ] = $response;
		}
		return $transient;
	}

	public function purge( $upgrader, $options ) {
		if ( $this->cache_allowed && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
		}
	}
}
