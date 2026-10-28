<?php
/**
 * Switching a WordPress site to Minn Engine, from Minn Admin.
 *
 * Two doors, one path: `wp minn install` (and `wp minn preflight`) on the
 * command line, and an experimental card on the System screen that stays
 * hidden until it is unlocked. Both take Minn's latest release from the Minn
 * update service and nowhere else, check it against the sha256 its release
 * records and an Ed25519 signature from a key Minn Engine trusts, refuse it
 * unless it holds the version its release names and is no older than
 * ENGINE_FLOOR (the signature does not say which release it is), unpack it
 * into wp-content/upgrade, and hand the site to the engine's own installer
 * (Minn\Cli\Installer, inside the release): the install logic lives in the
 * engine, never here. WordPress's own files move to a folder beside the site
 * and `wp minn eject` (the engine's) brings them back; Minn Admin stays in
 * wp-content/plugins either way.
 *
 * On a site Minn already runs this registers nothing: the engine answers
 * `wp minn` itself.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Switch {

	/** The Minn update service, the one place a Minn release is taken from. */
	const SERVICE = 'https://updates.minn.run/v1/';

	/**
	 * The public halves of the keys that sign Minn releases, as Minn Engine
	 * trusts them (Ops\EngineUpdate::KEYS): the working key, then the offline
	 * recovery key. Keep the two lists the same.
	 */
	const KEYS = array(
		'+Usjnr1ZTudBbWToqYLkZ1Oy2yKN07d9+u62AIJ2FG8=',
		'QpNzSZpXf3TPiHs9mqur30xFrOXs/yFG4uNqBkVajQk=',
	);

	/**
	 * The oldest Minn release Minn Admin installs. A signature covers a
	 * release's bytes, not which release it is, so a release signed in its
	 * day stays signed after a newer one replaces it; the engine's own
	 * updater refuses one not newer than the running engine, and a site
	 * switching has none, so this stands in for it. Raised to a release
	 * that fixes what an older one would bring back. Each site also keeps
	 * its own floor above it (FLOOR_OPTION).
	 */
	const ENGINE_FLOOR = '0.1.0';

	/**
	 * The highest release this site has switched to: fetched, matched to
	 * its checksum, signed by a trusted key, holding the version its
	 * release names, and installed. Nothing older is staged again. It
	 * rises only once a switch completes: never on what the service names
	 * (a service that is not to be trusted could name a version no release
	 * carries and so refuse every real release after it), and not at a
	 * check, which installs nothing (a release pulled after sites checked
	 * it would leave them refusing the one that replaces it). Not
	 * autoloaded: only the switch reads it.
	 */
	const FLOOR_OPTION = 'minn_admin_switch_floor';

	/** The PHP Minn Engine needs; its code does not parse on older. */
	const PHP_MIN = 80200;

	const MAX_DOWNLOAD = 67108864;

	/** How long a checked release waits, staged, for the switch. */
	const STAGE_TTL = 3600;

	/** The one-off cron event that removes a staging folder nobody came back for. */
	const SWEEP_HOOK = 'minn_admin_switch_sweep';

	public static function init() {
		if ( Minn_Admin::is_engine() ) {
			return;
		}
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( self::SWEEP_HOOK, array( __CLASS__, 'sweep' ) );
		// The engine's own commands, loaded with --require=minn/cli.php,
		// already answer `wp minn`.
		if ( defined( 'WP_CLI' ) && WP_CLI && ! class_exists( 'Minn\Cli\Commands', false ) ) {
			WP_CLI::add_command( 'minn', 'Minn_Admin_Switch_Command' );
		}
	}

	/** The webroot the engine installs into: where WordPress's own files are. */
	public static function root() {
		return untrailingslashit( ABSPATH );
	}

	/**
	 * The release to install: a minn.zip in hand (its signature in the
	 * .sig file beside it), or Minn's latest release from the update
	 * service.
	 *
	 * @param string $zip A local minn.zip, or '' for the latest release.
	 * @return array|WP_Error version, package (a URL or a path), sha256 ('' for a zip in hand), signature, notes
	 */
	public static function release( $zip = '' ) {
		if ( '' !== $zip ) {
			if ( ! is_file( $zip ) || ! is_readable( $zip ) ) {
				/* translators: %s: a file path. */
				return new WP_Error( 'minn_switch_zip', sprintf( __( '%s is not a file Minn Admin can read.', 'minn-admin' ), $zip ) );
			}
			$sig = is_readable( $zip . '.sig' ) ? trim( (string) file_get_contents( $zip . '.sig' ) ) : '';
			if ( '' === $sig ) {
				/* translators: %s: a file path. */
				return new WP_Error( 'minn_switch_zip', sprintf( __( 'No signature beside %s: a Minn release ships minn.zip.sig with it.', 'minn-admin' ), $zip ) );
			}
			return array(
				'version'   => '',
				'package'   => $zip,
				'sha256'    => '',
				'signature' => $sig,
				'notes'     => '',
			);
		}
		$response = wp_safe_remote_get(
			self::SERVICE . 'minn/releases/latest',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'user-agent'  => 'Minn Admin/' . MINN_ADMIN_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			/* translators: %s: why the request failed. */
			return new WP_Error( 'minn_switch_service', sprintf( __( 'The Minn update service did not answer: %s', 'minn-admin' ), $response->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			return new WP_Error( 'minn_switch_none', __( 'No Minn release is published yet.', 'minn-admin' ) );
		}
		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$package = is_array( $release ) ? (string) ( $release['package'] ?? '' ) : '';
		$version = is_array( $release ) ? (string) ( $release['version'] ?? '' ) : '';
		// What the engine's own updater takes as a release (Ops\Release): a
		// major.minor.patch version, downloaded from that version's path.
		if ( 200 !== $code || ! is_array( $release ) || ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) || self::SERVICE . 'minn/download/' . $version . '/minn.zip' !== $package || ! preg_match( '/^[0-9a-f]{64}$/', (string) ( $release['sha256'] ?? '' ) ) || '' === (string) ( $release['signature'] ?? '' ) ) {
			/* translators: %d: an HTTP status code. */
			return new WP_Error( 'minn_switch_service', sprintf( __( 'The Minn update service answered %d without a release Minn Admin can install.', 'minn-admin' ), $code ) );
		}
		return array(
			'version'   => $version,
			'package'   => $package,
			'sha256'    => (string) $release['sha256'],
			'signature' => (string) $release['signature'],
			'notes'     => (string) ( $release['notes'] ?? '' ),
		);
	}

	/**
	 * Fetches a release into a staging folder of its own, checks it and
	 * unpacks it: the engine's folder, ready for its installer.
	 *
	 * @param array $release From release().
	 * @return array|WP_Error token, dir (the staging folder), engine (its minn/ folder), version, speaks (the WordPress it speaks)
	 */
	public static function stage( array $release ) {
		if ( PHP_VERSION_ID < self::PHP_MIN ) {
			/* translators: %s: the PHP version this site runs. */
			return new WP_Error( 'minn_switch_php', sprintf( __( 'Minn needs PHP 8.2 or newer; this site runs PHP %s.', 'minn-admin' ), PHP_VERSION ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'minn_switch_zip', __( "This PHP has no zip extension, so Minn's release cannot be unpacked.", 'minn-admin' ) );
		}
		self::sweep();
		$token = wp_generate_password( 32, false );
		$dir   = WP_CONTENT_DIR . '/upgrade/minn-switch-' . $token;
		if ( ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: a folder path. */
			return new WP_Error( 'minn_switch_stage', sprintf( __( 'Minn Admin could not create %s.', 'minn-admin' ), $dir ) );
		}
		// The web server can reach wp-content/upgrade. The staged engine is
		// code, and a failed switch's report holds the installer's lines and
		// paths: neither is for it to list or hand out (Apache honours the
		// deny; nginx needs the matching location rule), and the folder
		// goes once its check expires, whether or not anyone checks again.
		file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// Nor is the folder's own name for a listing of upgrade/ to give away.
		if ( ! file_exists( WP_CONTENT_DIR . '/upgrade/index.php' ) ) {
			file_put_contents( WP_CONTENT_DIR . '/upgrade/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		file_put_contents( $dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		self::schedule_sweep();
		$zip = $dir . '/minn.zip';
		if ( is_file( $release['package'] ) ) {
			$copied = copy( $release['package'], $zip );
		} else {
			$response = wp_safe_remote_get(
				$release['package'],
				array(
					'timeout'             => 300,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $zip,
					'limit_response_size' => self::MAX_DOWNLOAD,
					'user-agent'          => 'Minn Admin/' . MINN_ADMIN_VERSION . '; ' . home_url( '/' ),
				)
			);
			$copied = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response );
		}
		if ( ! $copied || ! is_file( $zip ) ) {
			self::remove( $dir );
			return new WP_Error( 'minn_switch_download', __( "Minn's release could not be downloaded.", 'minn-admin' ) );
		}
		$bytes = (string) file_get_contents( $zip );
		if ( '' !== $release['sha256'] && ! hash_equals( strtolower( $release['sha256'] ), hash( 'sha256', $bytes ) ) ) {
			self::remove( $dir );
			return new WP_Error( 'minn_switch_checksum', __( 'The minn.zip downloaded does not match the checksum its release publishes, so it is not used.', 'minn-admin' ) );
		}
		if ( ! self::signed( $bytes, $release['signature'] ) ) {
			self::remove( $dir );
			return new WP_Error( 'minn_switch_signature', __( 'This minn.zip is not signed by a key Minn trusts, so it is not used.', 'minn-admin' ) );
		}
		$archive = new ZipArchive();
		if ( true !== $archive->open( $zip ) ) {
			self::remove( $dir );
			return new WP_Error( 'minn_switch_zip', __( "Minn's release could not be opened.", 'minn-admin' ) );
		}
		// Every entry stays inside the one top folder a release ships.
		for ( $i = 0; $i < $archive->numFiles; $i++ ) {
			$name = (string) $archive->getNameIndex( $i );
			if ( 0 !== strpos( $name, 'minn/' ) || false !== strpos( $name, '..' ) || false !== strpos( $name, '\\' ) ) {
				$archive->close();
				self::remove( $dir );
				/* translators: %s: a path inside the archive. */
				return new WP_Error( 'minn_switch_zip', sprintf( __( "Minn's release holds %s, outside its minn/ folder, so it is not used.", 'minn-admin' ), $name ) );
			}
		}
		$refused = self::version_error( self::version_of( (string) $archive->getFromName( 'minn/bootstrap.php' ) ), (string) $release['version'] );
		if ( $refused ) {
			$archive->close();
			self::remove( $dir );
			return $refused;
		}
		$opened = $archive->extractTo( $dir );
		$archive->close();
		unlink( $zip );
		$engine = $dir . '/minn';
		if ( ! $opened || ! is_file( $engine . '/bootstrap.php' ) || ! is_file( $engine . '/bin/minn' ) || ! is_file( $engine . '/src/Minn/Cli/Installer.php' ) ) {
			self::remove( $dir );
			return new WP_Error( 'minn_switch_zip', __( "Minn's release is not a Minn Engine: its minn/ folder is incomplete.", 'minn-admin' ) );
		}
		$version = self::version_of( (string) file_get_contents( $engine . '/bootstrap.php' ) );
		$core    = (string) @file_get_contents( $engine . '/src/Minn/Engine.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$speaks  = preg_match( "/const WP_VERSION = '([^']+)'/", $core, $m ) ? $m[1] : '';
		return array(
			'token'   => $token,
			'dir'     => $dir,
			'engine'  => $engine,
			'version' => $version,
			'speaks'  => $speaks,
		);
	}

	/**
	 * Why an archive holding this version is not installed, or null: it
	 * must hold the version its release names (a zip in hand names none),
	 * and not be older than the floor (floor()). The engine's updater
	 * applies the same two rules to the same signed archives, its floor
	 * being the engine it runs.
	 *
	 * @param string $holds The version the archive's bootstrap.php names (version_of()).
	 * @param string $named The version its release names, or '' for a zip in hand.
	 * @return WP_Error|null
	 */
	public static function version_error( $holds, $named ) {
		$holds = '' === (string) $holds ? '0.0.0' : (string) $holds;
		if ( '' !== (string) $named && $holds !== (string) $named ) {
			/* translators: 1: the Minn version a release archive holds, 2: the version its release names. */
			return new WP_Error( 'minn_switch_version', sprintf( __( 'The archive holds Minn %1$s, not the %2$s its release names, so it is not used.', 'minn-admin' ), $holds, $named ) );
		}
		$floor = self::floor();
		if ( version_compare( $holds, $floor, '<' ) ) {
			/* translators: 1: a Minn version, 2: the oldest Minn version Minn Admin installs. */
			return new WP_Error( 'minn_switch_old', sprintf( __( 'Minn %1$s is older than Minn %2$s, the oldest release Minn Admin installs, so it is not used.', 'minn-admin' ), $holds, $floor ) );
		}
		return null;
	}

	/** The oldest release this site installs: ENGINE_FLOOR, or the highest it has switched to when that is newer. */
	private static function floor() {
		$seen = (string) get_option( self::FLOOR_OPTION, '' );
		return preg_match( '/^\d+\.\d+\.\d+$/', $seen ) && version_compare( $seen, self::ENGINE_FLOOR, '>' ) ? $seen : self::ENGINE_FLOOR;
	}

	/** Raises this site's floor to a release it has switched to; it never falls. */
	public static function remember( $version ) {
		$seen = (string) get_option( self::FLOOR_OPTION, '' );
		if ( preg_match( '/^\d+\.\d+\.\d+$/', (string) $version ) && ( ! preg_match( '/^\d+\.\d+\.\d+$/', $seen ) || version_compare( $version, $seen, '>' ) ) ) {
			update_option( self::FLOOR_OPTION, (string) $version, false );
		}
	}

	/** The version a Minn bootstrap.php names, read as the engine reads it; '' when it names none. */
	private static function version_of( $bootstrap ) {
		return preg_match( "/MINN_ENGINE_VERSION', '([^']+)'/", (string) $bootstrap, $m ) ? $m[1] : '';
	}

	/**
	 * Runs one of the engine's installer verbs against this site, in this
	 * process, and hands back its exit code and report.
	 *
	 * @param string $engine  The staged minn/ folder.
	 * @param array  $argv    The verb and its options, as `php minn/bin/minn` takes them.
	 * @return array|WP_Error code, lines
	 */
	public static function run( $engine, array $argv ) {
		require_once $engine . '/src/Minn/Autoloader.php';
		\Minn\Autoloader::register();
		if ( ! method_exists( 'Minn\Cli\Installer', 'run' ) ) {
			return new WP_Error( 'minn_switch_old', __( 'This Minn release is older than Minn Admin can install from WordPress; use its install.sh instead.', 'minn-admin' ) );
		}
		$verb = array_shift( $argv );
		return \Minn\Cli\Installer::run( array_merge( array( $verb, self::root() ), $argv ), $engine );
	}

	/**
	 * Where WordPress's own files will go, and whether this process can put
	 * them there: the engine's default, a folder beside the site.
	 *
	 * @param string $park A folder the caller chose, or '' for the default.
	 * @return array path, ready
	 */
	public static function park( $park = '' ) {
		$path = '' !== $park ? untrailingslashit( $park ) : dirname( self::root() ) . '/wp-parked';
		$ready = is_dir( $path ) ? ( is_writable( $path ) && count( (array) scandir( $path ) ) <= 2 ) : is_writable( dirname( $path ) );
		return array(
			'path'  => $path,
			'ready' => $ready,
		);
	}

	/** Whether one of the trusted keys made this signature over these bytes. */
	public static function signed( $bytes, $signature ) {
		if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return false;
		}
		$raw = base64_decode( (string) $signature, true );
		if ( false === $raw || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $raw ) ) {
			return false;
		}
		foreach ( self::KEYS as $key ) {
			$public = base64_decode( $key, true );
			try {
				if ( false !== $public && SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES === strlen( $public ) && sodium_crypto_sign_verify_detached( $raw, $bytes, $public ) ) {
					return true;
				}
			} catch ( Exception $e ) {
				continue;
			}
		}
		return false;
	}

	/** Removes a staging folder and everything in it. */
	public static function remove( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Staging folders older than a check is kept for are removed, unless
	 * their check is still held (a switch running from one). The sweep
	 * comes back while any folder is left.
	 */
	public static function sweep() {
		$left = false;
		foreach ( (array) glob( WP_CONTENT_DIR . '/upgrade/minn-switch-*', GLOB_ONLYDIR ) as $old ) {
			if ( ! is_string( $old ) ) {
				continue;
			}
			$token = substr( basename( $old ), strlen( 'minn-switch-' ) );
			if ( filemtime( $old ) < time() - self::STAGE_TTL && ! ( preg_match( '/^[A-Za-z0-9]{32}$/', $token ) && false !== get_transient( 'minn_switch_' . $token ) ) ) {
				self::remove( $old );
			} else {
				$left = true;
			}
		}
		if ( $left ) {
			self::schedule_sweep();
		}
	}

	/** One sweep a little after a staged check expires, unless one is due already. */
	private static function schedule_sweep() {
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_single_event( time() + self::STAGE_TTL + MINUTE_IN_SECONDS, self::SWEEP_HOOK );
		}
	}

	/* ===== The System screen's card (experimental, unlocked in the app) ===== */

	public static function routes() {
		register_rest_route(
			'minn-admin/v1',
			'/minn-switch/check',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => array( __CLASS__, 'rest_check' ),
			)
		);
		register_rest_route(
			'minn-admin/v1',
			'/minn-switch/run',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => array( __CLASS__, 'rest_run' ),
				'args'                => array(
					'token'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'confirm' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'minn-admin/v1',
			'/minn-switch/status',
			array(
				'methods'             => 'GET',
				'permission_callback' => array( __CLASS__, 'can' ),
				'callback'            => array( __CLASS__, 'rest_status' ),
				'args'                => array(
					'token' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/** Switching the software that runs the site is a core change: update_core, on one site, where files may change. */
	public static function can() {
		return current_user_can( 'update_core' ) && ! is_multisite() && wp_is_file_mod_allowed( 'minn_switch' );
	}

	/** The host a person types to confirm the switch. */
	public static function host() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Checks the latest release and the site: fetched, verified, staged and
	 * preflighted, nothing moved. The release stays staged for the switch.
	 * A site may name a signed minn.zip in hand with MINN_SWITCH_ZIP (a path
	 * in wp-config.php) while no release is published.
	 */
	public static function rest_check() {
		$release = self::release( defined( 'MINN_SWITCH_ZIP' ) ? (string) MINN_SWITCH_ZIP : '' );
		if ( is_wp_error( $release ) ) {
			return self::refusal( $release );
		}
		$staged = self::stage( $release );
		if ( is_wp_error( $staged ) ) {
			return self::refusal( $staged );
		}
		$preflight = self::run( $staged['engine'], array( 'preflight' ) );
		if ( is_wp_error( $preflight ) ) {
			self::remove( $staged['dir'] );
			return self::refusal( $preflight );
		}
		$light = 0 === $preflight['code'] ? ( self::amber( $preflight['lines'] ) ? 'AMBER' : 'GREEN' ) : 'RED';
		$park  = self::park();
		set_transient(
			'minn_switch_' . $staged['token'],
			array(
				'dir'     => $staged['dir'],
				'engine'  => $staged['engine'],
				'version' => $staged['version'],
				'user'    => get_current_user_id(),
				'light'   => $light,
				'state'   => 'checked',
			),
			self::STAGE_TTL
		);
		return rest_ensure_response(
			array(
				'token'   => $staged['token'],
				'version' => $staged['version'],
				'speaks'  => $staged['speaks'],
				'site'    => get_bloginfo( 'version' ),
				'light'   => $light,
				'lines'   => $preflight['lines'],
				'park'    => $park,
				'host'    => self::host(),
				'notes'   => $release['notes'],
			)
		);
	}

	/**
	 * Switches the site to the release a check staged. The swap runs once
	 * this response has gone, after WordPress's own shutdown work, so
	 * nothing WordPress still needs moves under it; the app then waits for
	 * the site to answer as Minn.
	 */
	public static function rest_run( WP_REST_Request $request ) {
		$token = (string) $request['token'];
		$held  = preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ? get_transient( 'minn_switch_' . $token ) : false;
		if ( ! is_array( $held ) || (int) $held['user'] !== get_current_user_id() || 'checked' !== $held['state'] || ! is_dir( $held['engine'] ) ) {
			return new WP_Error( 'minn_switch_expired', __( 'That check has expired. Check the site again.', 'minn-admin' ), array( 'status' => 409 ) );
		}
		if ( 'RED' === $held['light'] ) {
			return new WP_Error( 'minn_switch_red', __( 'Preflight is RED for this site, so it stays on WordPress.', 'minn-admin' ), array( 'status' => 409 ) );
		}
		if ( strtolower( trim( (string) $request['confirm'] ) ) !== strtolower( self::host() ) ) {
			return new WP_Error( 'minn_switch_confirm', __( "Type the site's address to confirm.", 'minn-admin' ), array( 'status' => 400 ) );
		}
		$park = self::park();
		if ( ! $park['ready'] ) {
			/* translators: 1: a folder path, 2: a command. */
			return new WP_Error( 'minn_switch_park', sprintf( __( 'The web server cannot create %1$s for WordPress\'s own files. Switch from the command line instead: %2$s', 'minn-admin' ), $park['path'], 'wp minn install --park=<folder>' ), array( 'status' => 409 ) );
		}
		// One switch at a time, as core's upgrader holds its own lock: two
		// clicks would start two installers moving the same files.
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! WP_Upgrader::create_lock( 'minn_switch', self::STAGE_TTL ) ) {
			return new WP_Error( 'minn_switch_running', __( 'A switch is already running on this site.', 'minn-admin' ), array( 'status' => 409 ) );
		}
		$held['state'] = 'switching';
		set_transient( 'minn_switch_' . $token, $held, self::STAGE_TTL );
		ignore_user_abort( true );
		register_shutdown_function( array( __CLASS__, 'switch_now' ), $token, $held );
		return rest_ensure_response( array( 'state' => 'switching' ) );
	}

	/**
	 * The swap itself, at the end of the request. A failed switch's report
	 * is kept with the check, out of the web server's reach: WordPress is
	 * still here to read it (rest_status). If it works the folder goes and
	 * the site answers as Minn.
	 *
	 * @param string $token The check's token.
	 * @param array  $held  What the check kept.
	 */
	public static function switch_now( $token, array $held ) {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$result = self::run( $held['engine'], array( 'install' ) );
		if ( is_wp_error( $result ) ) {
			$result = array(
				'code'  => 1,
				'lines' => array( $result->get_error_message() ),
			);
		}
		if ( 0 === $result['code'] ) {
			self::remember( (string) $held['version'] );
			// The engine copied itself into the webroot; its staged copy is spent.
			self::remove( $held['dir'] );
			WP_Upgrader::release_lock( 'minn_switch' );
			return;
		}
		$held['state'] = 'failed';
		$held['lines'] = array_values( array_map( 'strval', (array) ( $result['lines'] ?? array() ) ) );
		set_transient( 'minn_switch_' . $token, $held, self::STAGE_TTL );
		WP_Upgrader::release_lock( 'minn_switch' );
	}

	/** Where a switch stands while WordPress still answers: switching, or failed with the installer's report. */
	public static function rest_status( WP_REST_Request $request ) {
		$token = (string) $request['token'];
		$held  = preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ? get_transient( 'minn_switch_' . $token ) : false;
		// The person who ran the check, as rest_run() holds it: the installer's
		// report names folders and the engine's state.
		if ( ! is_array( $held ) || (int) ( $held['user'] ?? 0 ) !== get_current_user_id() ) {
			return new WP_Error( 'minn_switch_expired', __( 'That switch is not known here.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		if ( 'failed' === $held['state'] ) {
			return rest_ensure_response(
				array(
					'state' => 'failed',
					'lines' => (array) ( $held['lines'] ?? array() ),
				)
			);
		}
		return rest_ensure_response( array( 'state' => $held['state'] ) );
	}

	/** Whether a preflight report holds an AMBER line. */
	private static function amber( array $lines ) {
		foreach ( $lines as $line ) {
			if ( false !== strpos( (string) $line, '[AMBER]' ) ) {
				return true;
			}
		}
		return false;
	}

	private static function refusal( WP_Error $error ) {
		$error->add_data( array( 'status' => 'minn_switch_none' === $error->get_error_code() ? 404 : 409 ) );
		return $error;
	}
}

/**
 * Runs this WordPress site on Minn Engine: checks it, or switches it.
 *
 * Minn's release comes from the Minn update service, is checked against its
 * checksum and signature, and is installed by the engine's own installer.
 * Once Minn runs the site, `wp minn` is the engine's: `wp minn eject` puts
 * WordPress back.
 *
 * ## EXAMPLES
 *
 *     # What the site will and will not get on Minn, changing nothing
 *     $ wp minn preflight
 *
 *     # Switch the site to Minn
 *     $ wp minn install
 */
class Minn_Admin_Switch_Command {

	/**
	 * Reports what this site will and will not get on Minn, changing nothing.
	 *
	 * ## OPTIONS
	 *
	 * [--zip=<file>]
	 * : A minn.zip in hand instead of the latest release. Its signature is read from <file>.sig.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function preflight( $args, $assoc_args ) {
		$staged = $this->staged( $assoc_args );
		$report = Minn_Admin_Switch::run( $staged['engine'], array( 'preflight' ) );
		Minn_Admin_Switch::remove( $staged['dir'] );
		if ( is_wp_error( $report ) ) {
			WP_CLI::error( $report->get_error_message() );
		}
		$this->say( $report['lines'] );
		if ( 0 !== $report['code'] ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Switches this site to Minn.
	 *
	 * Downloads Minn's latest release from the Minn update service, checks
	 * its checksum and signature, shows what the site will get, and after you
	 * confirm, installs it: WordPress's own files move to a folder beside the
	 * site, Minn takes their place, and wp-config.php, wp-content and the
	 * database are left as they are. `wp minn eject` reverses it.
	 *
	 * ## OPTIONS
	 *
	 * [--zip=<file>]
	 * : A minn.zip in hand instead of the latest release. Its signature is read from <file>.sig.
	 *
	 * [--park=<dir>]
	 * : Where WordPress's own files go. Default: wp-parked beside the site.
	 *
	 * [--force]
	 * : Install even when preflight is RED.
	 *
	 * [--yes]
	 * : Switch without asking.
	 *
	 * [--[no-]cache-flush]
	 * : Purge the host's caches once the site answers as Minn (`wp cache flush`), as Minn's installer does. Default: on; --no-cache-flush skips it, and a host's page cache keeps WordPress's pages until purged.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function install( $args, $assoc_args ) {
		$staged = $this->staged( $assoc_args );
		$report = Minn_Admin_Switch::run( $staged['engine'], array( 'preflight' ) );
		if ( is_wp_error( $report ) ) {
			Minn_Admin_Switch::remove( $staged['dir'] );
			WP_CLI::error( $report->get_error_message() );
		}
		$this->say( $report['lines'] );
		$force = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		if ( 0 !== $report['code'] && ! $force ) {
			Minn_Admin_Switch::remove( $staged['dir'] );
			WP_CLI::error( __( 'Preflight is RED, so the site stays on WordPress. Pass --force to install anyway.', 'minn-admin' ) );
		}
		$park = Minn_Admin_Switch::park( (string) ( $assoc_args['park'] ?? '' ) );
		if ( ! $park['ready'] ) {
			Minn_Admin_Switch::remove( $staged['dir'] );
			/* translators: %s: a folder path. */
			WP_CLI::error( sprintf( __( "Cannot put WordPress's own files in %s: it is not writable, or not empty. Choose a folder outside the site with --park=<dir>.", 'minn-admin' ), $park['path'] ) );
		}
		WP_CLI::log( '' );
		/* translators: 1: Minn's version, 2: the WordPress version Minn speaks, 3: the WordPress version this site runs. */
		WP_CLI::log( sprintf( __( 'Minn %1$s (speaks WordPress %2$s), signature verified. This site runs WordPress %3$s.', 'minn-admin' ), $staged['version'], $staged['speaks'] ? $staged['speaks'] : '?', get_bloginfo( 'version' ) ) );
		/* translators: %s: a folder path. */
		WP_CLI::log( sprintf( __( "WordPress's own files move to %s; `wp minn eject` brings them back.", 'minn-admin' ), $park['path'] ) );
		WP_CLI::confirm( __( 'Switch this site to Minn?', 'minn-admin' ), $assoc_args );
		$options = array( 'install' );
		if ( isset( $assoc_args['park'] ) ) {
			$options[] = '--park=' . $park['path'];
		}
		if ( $force ) {
			$options[] = '--force';
		}
		$result = Minn_Admin_Switch::run( $staged['engine'], $options );
		Minn_Admin_Switch::remove( $staged['dir'] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		// The installer prints its preflight again; what follows its result line is the install.
		$lines = $result['lines'];
		$at    = false;
		foreach ( $lines as $i => $line ) {
			if ( 0 === strpos( (string) $line, 'Result: ' ) ) {
				$at = $i;
			}
		}
		$this->say( false === $at ? $lines : array_slice( $lines, $at + 1 ) );
		if ( 0 !== $result['code'] ) {
			WP_CLI::error( __( 'Minn was not installed; the site is still WordPress.', 'minn-admin' ) );
		}
		Minn_Admin_Switch::remember( (string) $staged['version'] );
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'cache-flush', true ) ) {
			$this->flush_caches();
		}
		/* translators: %s: Minn Admin's address. */
		WP_CLI::success( sprintf( __( 'This site now runs on Minn. Minn Admin is at %s', 'minn-admin' ), home_url( '/minn-admin/' ) ) );
		// WordPress's files have moved away under this process: stop before anything asks for one.
		WP_CLI::halt( 0 );
	}

	/**
	 * Purges the host's caches once the site answers as Minn, as Minn's own
	 * installer does: a page cache (Kinsta's, for one) keeps serving
	 * WordPress's pages until purged, and an opcode cache may run the old
	 * index.php for a while, so a purge made too early is filled again by
	 * WordPress. WordPress's files have moved away under this process, so
	 * the site is asked with PHP's own stream (nothing WordPress loads
	 * lazily), and the flush runs in a new `wp`, which boots Minn.
	 */
	private function flush_caches() {
		$url    = home_url( '/' );
		$seen   = false;
		$misses = 0;
		/* translators: %s: the site's address. */
		WP_CLI::log( sprintf( __( 'Waiting for %s to answer as Minn…', 'minn-admin' ), $url ) );
		for ( $try = 0; $try < 30 && ! $seen; $try++ ) {
			if ( $try > 0 ) {
				sleep( 3 );
			}
			$headers = $this->headers_of( $url );
			if ( null === $headers ) {
				if ( ++$misses >= 3 ) {
					break;
				}
				continue;
			}
			$seen = (bool) preg_grep( '/^x-powered-by:\s*Minn\b/i', $headers );
		}
		if ( ! $seen ) {
			WP_CLI::warning( __( 'The site did not answer as Minn yet; flushing anyway. If it still shows WordPress in a minute, run: wp cache flush', 'minn-admin' ) );
		}
		$flush = WP_CLI::runcommand(
			'cache flush',
			array(
				'launch'     => true,
				'return'     => 'all',
				'exit_error' => false,
			)
		);
		if ( 0 === (int) $flush->return_code ) {
			WP_CLI::log( __( 'Caches flushed (wp cache flush).', 'minn-admin' ) );
		} else {
			WP_CLI::warning( __( 'wp cache flush failed. If the host caches pages, purge its cache now.', 'minn-admin' ) );
		}
	}

	/**
	 * The response headers a fresh request for the address gets, or null when
	 * nothing answers. A logged-in cookie name and a query string of its own
	 * step past page caches; the cookie's value signs nobody in.
	 */
	private function headers_of( $url ) {
		$context = stream_context_create(
			array(
				'http' => array(
					'method'          => 'GET',
					'header'          => "Cookie: wordpress_logged_in_minn_install=1\r\n",
					'timeout'         => 15,
					'follow_location' => 0,
					'ignore_errors'   => true,
				),
			)
		);
		$handle = @fopen( $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'minn-install=' . mt_rand(), 'r', false, $context );
		if ( false === $handle ) {
			return null;
		}
		$meta = stream_get_meta_data( $handle );
		fclose( $handle );
		return isset( $meta['wrapper_data'] ) ? array_map( 'strval', (array) $meta['wrapper_data'] ) : null;
	}

	/** The release fetched, checked and staged, or the command ends. */
	private function staged( $assoc_args ) {
		$zip     = isset( $assoc_args['zip'] ) ? (string) $assoc_args['zip'] : '';
		$release = Minn_Admin_Switch::release( $zip );
		if ( is_wp_error( $release ) ) {
			WP_CLI::error( $release->get_error_message() );
		}
		/* translators: %s: Minn's version. */
		WP_CLI::log( '' === $zip ? sprintf( __( 'Downloading Minn %s from the Minn update service…', 'minn-admin' ), $release['version'] ) : sprintf( /* translators: %s: a file path. */ __( 'Checking %s…', 'minn-admin' ), $zip ) );
		$staged = Minn_Admin_Switch::stage( $release );
		if ( is_wp_error( $staged ) ) {
			WP_CLI::error( $staged->get_error_message() );
		}
		return $staged;
	}

	private function say( array $lines ) {
		foreach ( $lines as $line ) {
			WP_CLI::log( (string) $line );
		}
	}
}
