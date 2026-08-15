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

	/**
	 * Repository paths packages may live under, as an ALLOWLIST rather than
	 * one hardcoded prefix. Language packs are published alongside the plugin
	 * today; if they ever move to their own repository, that is one more
	 * entry here plus a manifest URL, not a rewrite of the download gate.
	 *
	 * Each entry is matched ANCHORED at the start of the URL path. Unanchored,
	 * anyone could satisfy it with a repository of their own containing that
	 * directory path.
	 */
	const PACKAGE_PATHS = array( '/austinginder/minn-admin/' );

	/**
	 * Distinct user locales, cached a day. The query is cheap but it runs on
	 * an update check, and update checks run on a schedule nobody asked for.
	 */
	const USER_LOCALES_KEY = 'minn_admin_user_locales';

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
		// ANCHORED, not a substring search. Unanchored, anyone could satisfy
		// this with a repository of their own containing that directory path
		// (github.com/<attacker>/<repo>/raw/main/austinginder/minn-admin/x.zip),
		// which is the opposite of what the check claims to enforce.
		// objects.githubusercontent.com is only ever reached as a redirect
		// target inside download_url(), never as a manifest download_url, so it
		// does not carry the owner/repo pair and legitimately never matches.
		$path = (string) ( $parts['path'] ?? '' );
		foreach ( self::PACKAGE_PATHS as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The sha256 the manifest publishes for a given package URL.
	 *
	 * Three-state on purpose:
	 *   null  the manifest does not claim this URL at all. The caller decides
	 *         whether it is unrelated; verify_package() has already established
	 *         ownership from the URL and therefore refuses this state.
	 *   ''    the manifest claims it but publishes no hash. That is a refusal,
	 *         not a pass: treating "no sha256" as "verification not required"
	 *         makes integrity opt-out for whoever serves the manifest, and
	 *         degrades silently if a release script forgets the field.
	 *   hash  verify against this.
	 *
	 * @param object|null $remote  Decoded manifest.
	 * @param string      $package Package URL being downloaded.
	 * @return string|null
	 */
	public function hash_for_package( $remote, $package ) {
		if ( ! is_object( $remote ) ) {
			return null;
		}
		if ( ! empty( $remote->download_url ) && $package === $remote->download_url ) {
			return isset( $remote->sha256 ) ? (string) $remote->sha256 : '';
		}
		foreach ( (array) ( $remote->translations ?? array() ) as $pack ) {
			$pack = (object) $pack;
			if ( ! empty( $pack->package ) && $package === $pack->package ) {
				return isset( $pack->sha256 ) ? (string) $pack->sha256 : '';
			}
		}
		return null;
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
		// Look the hash up BY PACKAGE URL. The manifest publishes one for the
		// plugin zip and one for every language pack, and a translation
		// package is a downloaded file like any other: skipping the check for
		// it would make integrity opt-out for whoever serves the manifest.
		$expected = $this->hash_for_package( $remote, $package );
		if ( null === $expected || '' === $expected ) {
			return new WP_Error(
				'minn_admin_missing_package_hash',
				__( 'Minn Admin update rejected: the release manifest does not publish a sha256 for this package.', 'minn-admin' )
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
		if ( ! hash_equals( strtolower( $expected ), $hash ) ) {
			wp_delete_file( $file );
			return new WP_Error(
				'minn_admin_bad_package_hash',
				__( 'Minn Admin update rejected: the downloaded package does not match the sha256 published in the release manifest.', 'minn-admin' )
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

		$this->offer_translations( $transient, $remote );
		return $transient;
	}

	/**
	 * Offer language packs through core's own translation-update path.
	 *
	 * WordPress reads pending translation updates from the same transient it
	 * reads plugin updates from, and Language_Pack_Upgrader::async_upgrade()
	 * is already hooked to upgrader_process_complete: updating the plugin
	 * installs its packs straight after, with no code from us. They also show
	 * up under Dashboard, Updates, and they land in WP_LANG_DIR/plugins/,
	 * which core checks BEFORE the plugin's own languages/ directory and
	 * which survives a plugin reinstall.
	 *
	 * Only locales the site actually uses are offered. A site running one
	 * language has no reason to download thirteen.
	 *
	 * @param object      $transient The update_plugins transient.
	 * @param object|null $remote    Decoded manifest.
	 */
	protected function offer_translations( $transient, $remote ) {
		if ( ! is_object( $remote ) || empty( $remote->translations ) ) {
			return;
		}
		$wanted = $this->wanted_locales();
		if ( ! $wanted ) {
			return;
		}
		if ( ! isset( $transient->translations ) || ! is_array( $transient->translations ) ) {
			$transient->translations = array();
		}
		$installed = $this->installed_translations();

		foreach ( (array) $remote->translations as $pack ) {
			$pack = (object) $pack;
			if ( empty( $pack->language ) || empty( $pack->package ) ) {
				continue;
			}
			if ( ! in_array( $pack->language, $wanted, true ) ) {
				continue;
			}
			// Same refusal as the plugin package: never OFFER something we
			// would refuse to install.
			if ( ! $this->is_our_package_url( $pack->package ) || empty( $pack->sha256 ) ) {
				continue;
			}
			// Skip a pack the site already has, comparing VERSIONS.
			//
			// PO-Revision-Date looks like the better key and is not: the
			// catalog pipeline restamps that header on every run, so a
			// regeneration that changed no strings would still re-offer
			// thirteen packs. Packs ship with releases, one set per version,
			// so the version is the honest question — and a translation-only
			// fix is a patch release, which answers it.
			//
			// A pack with NO readable version is one installed before this
			// shipped a .po to read it from; offering it replaces it with one
			// that has the headers, which is what we want.
			$have = $installed[ $pack->language ] ?? '';
			$offered = isset( $pack->version ) ? (string) $pack->version : $this->version;
			if ( '' !== $have && version_compare( $have, $offered, '>=' ) ) {
				continue;
			}
			$transient->translations[] = array(
				'type'       => 'plugin',
				'slug'       => $this->plugin_slug,
				'language'   => $pack->language,
				'version'    => isset( $pack->version ) ? $pack->version : $this->version,
				'updated'    => isset( $pack->updated ) ? $pack->updated : gmdate( 'Y-m-d H:i:s' ),
				'package'    => $pack->package,
				'autoupdate' => true,
			);
		}
	}

	/**
	 * Locales this site actually READS Minn Admin in.
	 *
	 * Deliberately narrower than get_available_languages(), which is every
	 * language installed on the site: a site that once installed five core
	 * languages and uses one has no business downloading five Minn catalogs at
	 * ~250KB each. What qualifies is the site language, a language some user
	 * actually chose, and any Minn pack already on disk (so an installed
	 * translation keeps getting updates even if nobody currently has it set).
	 *
	 * @return string[]
	 */
	protected function wanted_locales() {
		$locales = array( get_locale() );
		if ( function_exists( 'get_user_locale' ) ) {
			$locales[] = get_user_locale();
		}
		$locales = array_merge( $locales, $this->user_locales(), array_keys( $this->installed_translations() ) );
		/**
		 * Filter the locales language packs are offered for.
		 *
		 * @param string[] $locales Locale codes.
		 */
		$locales = apply_filters( 'minn_admin_translation_locales', $locales );
		return array_values( array_unique( array_filter( array_map( 'strval', (array) $locales ) ) ) );
	}

	/**
	 * Every locale a user on this site has actually picked.
	 *
	 * One meta query rather than a user enumeration: on a site with thousands
	 * of users, walking them to read one meta key each is not worth a
	 * translation check. Distinct values only, and only ones that are really
	 * installed — a stale meta value naming a language nobody has cannot pull
	 * a pack down.
	 *
	 * @return string[]
	 */
	protected function user_locales() {
		global $wpdb;
		$cached = get_transient( self::USER_LOCALES_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value != '' LIMIT 50",
				'locale'
			)
		);
		$available = get_available_languages();
		$out = array_values( array_intersect( (array) $found, $available ) );
		set_transient( self::USER_LOCALES_KEY, $out, DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * Version of each language pack already on disk, keyed by locale.
	 *
	 * Read out of Project-Id-Version ("Minn Admin 0.30.0"), the only one of
	 * the four headers core surfaces that can carry a version at all
	 * (wp_get_pomo_file_data). build-packs.sh stamps it.
	 *
	 * This depends on packs shipping their .po. wp_get_installed_translations()
	 * reads headers from the .po and skips any .mo with no .po beside it, so a
	 * .mo-only pack reports as NOT INSTALLED and gets re-offered on every
	 * check for the life of the site.
	 *
	 * @return array<string,string> Locale => version, '' when unreadable.
	 */
	protected function installed_translations() {
		$out = array();
		if ( ! function_exists( 'wp_get_installed_translations' ) ) {
			require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		}
		if ( ! function_exists( 'wp_get_installed_translations' ) ) {
			return $out;
		}
		$installed = wp_get_installed_translations( 'plugins' );
		foreach ( (array) ( $installed[ $this->plugin_slug ] ?? array() ) as $locale => $data ) {
			$out[ $locale ] = self::version_from_project_id( $data['Project-Id-Version'] ?? '' );
		}
		return $out;
	}

	/**
	 * The version out of a gettext Project-Id-Version header.
	 *
	 * The convention is "<project> <version>", so take the last whitespace
	 * separated run that looks like one. Returns '' when there is none, which
	 * the caller treats as "unknown, offer it".
	 *
	 * @param string $header Raw header value.
	 * @return string
	 */
	protected static function version_from_project_id( $header ) {
		if ( ! preg_match( '/([0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.]+)?)\s*$/', trim( (string) $header ), $m ) ) {
			return '';
		}
		return $m[1];
	}

	public function purge( $upgrader, $options ) {
		if ( $this->cache_allowed && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
		}
	}
}
