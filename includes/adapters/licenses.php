<?php
/**
 * License visibility — Phase 0 of docs/license-manager.md.
 *
 * Enumerates every license-wanting plugin and theme on the site and
 * classifies each as valid / expired / invalid / missing / unknown from
 * LOCALLY STORED state only. Classification is strictly read-only: no
 * network calls, no vendor code execution, no writes, so it can never
 * burn an activation seat. Stored status is last-verified truth, not
 * live truth; rows carry a stale flag when the vendor's own cache lapsed.
 *
 * Phase 1 adds OPT-IN actions per provider: `activate( $secret )`,
 * `deactivate()` and `verify()` callables that route through the VENDOR'S
 * OWN activation code (never a reimplemented HTTP call), exposed at
 * POST minn-admin/v1/licenses/action. Paste-to-activate only: Minn never
 * stores, logs or echoes a pasted secret, and a failed activation is
 * never retried automatically (retries can burn paid seats). "Site limit
 * reached" is a first-class result code, not a generic error.
 *
 * Third parties can add providers via the `minn_admin_license_providers`
 * filter:
 *
 *   add_filter( 'minn_admin_license_providers', function ( $providers ) {
 *       $providers['my-plugin'] = array(
 *           'name'   => 'My Plugin Pro',
 *           'detect' => function () { return defined( 'MY_PLUGIN_VERSION' ); },
 *           'read'   => function () {
 *               return array( array(
 *                   'name'    => 'My Plugin Pro',
 *                   'kind'    => 'plugin',
 *                   'state'   => 'valid', // valid|expired|invalid|missing|unknown
 *                   'key'     => true,    // a license key/secret is stored
 *                   'expires' => '2027-01-01', // or 'lifetime' or ''
 *                   'note'    => __( 'Optional one-line detail', 'minn-admin' ),
 *               ) );
 *           },
 *       );
 *       return $providers;
 *   } );
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read a property from a value that may be an object (including
 * __PHP_Incomplete_Class when the vendor's classes are not loaded) or an
 * array. Protected props serialize with a "\0*\0" prefix under an (array)
 * cast; strip those so readers see plain names.
 */
function minn_admin_license_prop( $thing, $key, $default = null ) {
	if ( is_array( $thing ) ) {
		return array_key_exists( $key, $thing ) ? $thing[ $key ] : $default;
	}
	if ( is_object( $thing ) ) {
		foreach ( (array) $thing as $k => $v ) {
			$plain = ( "\0" === substr( (string) $k, 0, 1 ) ) ? substr( strrchr( $k, "\0" ), 1 ) : $k;
			if ( $plain === $key ) {
				return $v;
			}
		}
	}
	return $default;
}

/** Normalize a vendor expiry value to 'lifetime', 'Y-m-d' or ''. */
function minn_admin_license_expiry( $raw ) {
	if ( null === $raw || '' === $raw || false === $raw ) {
		return '';
	}
	if ( is_string( $raw ) && 'lifetime' === strtolower( trim( $raw ) ) ) {
		return 'lifetime';
	}
	$ts = is_numeric( $raw ) ? (int) $raw : strtotime( (string) $raw );
	return ( $ts && $ts > 0 ) ? gmdate( 'Y-m-d', $ts ) : '';
}

/** Whether a normalized expiry string is in the past. */
function minn_admin_license_expired( $expires ) {
	return $expires && 'lifetime' !== $expires && strtotime( $expires . ' 23:59:59' ) < time();
}

/**
 * The WPMU DEV Hub membership state, shared by the WPMU DEV and Smush Pro
 * readers (Smush Pro is unlocked by the same membership). Reads the Hub's
 * locally cached membership type from wdp_un_membership_data.
 *
 * @return array { state, note, type, key } — state is
 *   valid|invalid|expired|unknown|missing, type is the raw membership string.
 */
function minn_admin_wpmudev_membership() {
	$key = ( defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_APIKEY ) ? WPMUDEV_APIKEY : get_site_option( 'wpmudev_apikey' );
	if ( ! $key ) {
		return array( 'state' => 'missing', 'note' => '', 'type' => '', 'key' => false );
	}
	$data = get_site_option( 'wdp_un_membership_data' );
	$type = ( is_array( $data ) && ! empty( $data['membership'] ) ) ? $data['membership'] : '';
	if ( is_numeric( $type ) ) {
		$type = 'single'; // a numeric membership is a single-project license id
	}
	$map = array(
		'full'    => array( 'valid', __( 'full membership', 'minn-admin' ) ),
		'unit'    => array( 'valid', __( 'per-plugin membership', 'minn-admin' ) ),
		'single'  => array( 'valid', __( 'single-project membership', 'minn-admin' ) ),
		'free'    => array( 'valid', __( 'free Hub membership', 'minn-admin' ) ),
		'paused'  => array( 'invalid', __( 'membership paused', 'minn-admin' ) ),
		'expired' => array( 'expired', '' ),
	);
	$state = isset( $map[ $type ] ) ? $map[ $type ][0] : 'unknown';
	$note  = isset( $map[ $type ] ) ? $map[ $type ][1] : 'key stored; the Hub has not confirmed a membership';
	return array( 'state' => $state, 'note' => $note, 'type' => (string) $type, 'key' => true );
}

/**
 * SDK fingerprints per installed component: which plugins/themes embed a
 * known licensing SDK. A bounded filename walk (never file contents),
 * cached for a day and keyed to the installed set so installs/updates
 * re-scan. Returns array of [ 'component' => 'dir/file.php'|'theme:slug',
 * 'kind', 'name', 'slug', 'sdk' => freemius|edd|surecart ].
 */
/**
 * The Soflyy (WP All Import / WP All Export) EDD request, byte-identical to
 * the ones their $_POST-driven protected controllers build: edd_action +
 * license + item_name against the plugin's OWN stored info_api_url_new
 * '/check_license' endpoint. Returns the response's license status word
 * ('valid', 'invalid', 'expired', 'deactivated', …) or null when the
 * service is unreachable. Exists only because those controllers expose no
 * public callable; the params come from reading their code, never invented.
 *
 * @return string|null
 */
function minn_admin_soflyy_edd( $edd_action, $license, $item_name, $api_url ) {
	if ( '' === $license || '' === $api_url ) {
		return null;
	}
	// The endpoint comes from WP All Import's own stored option, so a tampered
	// or restored database (or a hostile value pushed down by their API) would
	// send the customer's paid license key to whatever host it names. Pin it.
	$host = strtolower( (string) ( wp_parse_url( $api_url, PHP_URL_HOST ) ?: '' ) );
	$ok   = 'https' === strtolower( (string) ( wp_parse_url( $api_url, PHP_URL_SCHEME ) ?: '' ) )
		&& ( 'wpallimport.com' === $host || substr( $host, -16 ) === '.wpallimport.com' );
	if ( ! $ok ) {
		return null;
	}
	$response = wp_remote_get( esc_url_raw( add_query_arg( array(
		'edd_action' => $edd_action,
		'license'    => $license,
		'item_name'  => urlencode( $item_name ),
	), $api_url . '/check_license' ) ), array( 'timeout' => 15, 'sslverify' => true ) );
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$data = json_decode( wp_remote_retrieve_body( $response ) );
	return ( is_object( $data ) && isset( $data->license ) ) ? (string) $data->license : null;
}

/**
 * The Events Calendar paid family, one entry per licensed product. `slug` is
 * the PUE slug (dashed; the key option underscores it), `url`/`file` are the
 * exact Tribe__PUE__Checker constructor args each plugin uses itself, and
 * `class` is the main class whose presence proves the vendor code is loaded
 * (actions attach only then).
 *
 * @return array
 */
function minn_admin_license_tec_products() {
	return array(
		'tec-events-calendar-pro' => array(
			'name'  => 'The Events Calendar Pro',
			'slug'  => 'events-calendar-pro',
			'file'  => 'events-calendar-pro/events-calendar-pro.php',
			'class' => 'Tribe__Events__Pro__Main',
			'url'   => 'http://tri.be/',
		),
		'tec-event-tickets-plus'  => array(
			'name'  => 'Event Tickets Plus',
			'slug'  => 'event-tickets-plus',
			'file'  => 'event-tickets-plus/event-tickets-plus.php',
			'class' => 'Tribe__Tickets_Plus__Main',
			'url'   => 'http://theeventscalendar.com/',
		),
		'tec-filterbar'           => array(
			'name'  => 'The Events Calendar Filter Bar',
			'slug'  => 'tribe-filterbar',
			'file'  => 'the-events-calendar-filterbar/the-events-calendar-filter-view.php',
			'class' => 'Tribe__Events__Filterbar__View',
			'url'   => 'http://tri.be/',
		),
		'tec-community'           => array(
			'name'  => 'The Events Calendar Community',
			'slug'  => 'events-community',
			'file'  => 'the-events-calendar-community-events/tribe-community-events.php',
			'class' => 'Tribe__Events__Community__Main',
			'url'   => 'https://pue.theeventscalendar.com/',
		),
	);
}

/**
 * Smash Balloon Pro family. Every product speaks EDD Software Licensing
 * against https://smashballoon.com/ (including "All Plugins" multi-product
 * keys). Storage shapes differ by generation:
 *  - triple: sbi_/cff_/sby_/ctf_/sbsw_ license_key + status + data options
 *  - settings: Reviews (sbr_settings) / TikTok (sbtt_global_settings) arrays
 *  - info: Feed Analytics (sb_analytics_license_info {key,status,expires})
 *
 * `item_const` is defined only while the plugin is loaded; `fallback_item`
 * is the EDD download name baked into that build (verified from source).
 *
 * @return array
 */
function minn_admin_license_smash_products() {
	return array(
		'smash-instagram'  => array(
			'name'          => 'Instagram Feed Pro',
			'file'          => 'instagram-feed-pro/instagram-feed.php',
			'item_const'    => 'SBI_PLUGIN_NAME',
			'fallback_item' => 'Instagram Feed Pro Developer',
			'storage'       => 'triple',
			'key_opt'       => 'sbi_license_key',
			'status_opt'    => 'sbi_license_status',
			'data_opt'      => 'sbi_license_data',
		),
		'smash-facebook'   => array(
			'name'          => 'Custom Facebook Feed Pro',
			'file'          => 'custom-facebook-feed-pro/custom-facebook-feed.php',
			'item_const'    => 'WPW_SL_ITEM_NAME',
			'fallback_item' => 'Custom Facebook Feed WordPress Plugin Smash',
			'storage'       => 'triple',
			'key_opt'       => 'cff_license_key',
			'status_opt'    => 'cff_license_status',
			'data_opt'      => 'cff_license_data',
		),
		'smash-youtube'    => array(
			'name'          => 'YouTube Feed Pro',
			'file'          => 'youtube-feed-pro/youtube-feed.php',
			'item_const'    => 'SBY_PLUGIN_EDD_NAME',
			'fallback_item' => 'Youtube Feed Pro Developer',
			'storage'       => 'triple',
			'key_opt'       => 'sby_license_key',
			'status_opt'    => 'sby_license_status',
			'data_opt'      => 'sby_license_data',
		),
		'smash-twitter'    => array(
			'name'          => 'Custom Twitter Feeds Pro',
			'file'          => 'custom-twitter-feeds-pro/custom-twitter-feed.php',
			'item_const'    => 'CTF_PRODUCT_NAME',
			'fallback_item' => 'Custom Twitter Feeds Developer',
			'storage'       => 'triple',
			'key_opt'       => 'ctf_license_key',
			'status_opt'    => 'ctf_license_status',
			'data_opt'      => 'ctf_license_data',
		),
		'smash-social-wall' => array(
			'name'          => 'Social Wall',
			'file'          => 'social-wall/social-wall.php',
			'item_const'    => 'SBSW_PLUGIN_EDD_NAME',
			'fallback_item' => 'Social Wall',
			'storage'       => 'triple',
			'key_opt'       => 'sbsw_license_key',
			'status_opt'    => 'sbsw_license_status',
			'data_opt'      => 'sbsw_license_data',
		),
		'smash-reviews'    => array(
			'name'          => 'Reviews Feed Pro',
			'file'          => 'reviews-feed-pro/sb-reviews-pro.php',
			'item_const'    => 'SBR_PLUGIN_NAME',
			'fallback_item' => 'Reviews Feed Pro',
			'storage'       => 'settings',
			'settings_opt'  => 'sbr_settings',
			'key_field'     => 'license_key',
			'status_field'  => 'license_status',
		),
		'smash-tiktok'     => array(
			'name'          => 'TikTok Feeds Pro',
			'file'          => 'tiktok-feeds-pro/tiktok-feeds-pro.php',
			'item_const'    => 'SBTT_PLUGIN_NAME',
			'fallback_item' => 'TikTok Feeds Pro Elite',
			'storage'       => 'settings',
			'settings_opt'  => 'sbtt_global_settings',
			'key_field'     => 'license_key',
			'status_field'  => 'license_status',
		),
		'smash-analytics'  => array(
			'name'          => 'Feed Analytics Pro',
			'file'          => 'sb-analytics/sb-analytics-pro.php',
			'item_const'    => 'SB_ANALYTICS_PLUGIN_EDD_NAME',
			'fallback_item' => 'Feed Analytics Pro',
			'storage'       => 'info',
			'info_opt'      => 'sb_analytics_license_info',
		),
	);
}

/**
 * Smash Balloon EDD Software Licensing request (activate / deactivate / check).
 * Mirrors each product's own params against smashballoon.com: edd_action,
 * license, item_name, url. Returns the decoded response as an array (empty on
 * transport failure).
 *
 * @param string $edd_action activate_license|deactivate_license|check_license
 * @param string $license    License key.
 * @param string $item_name  EDD download name for this product.
 * @return array
 */
function minn_admin_smash_edd( $edd_action, $license, $item_name ) {
	$license   = trim( (string) $license );
	$item_name = (string) $item_name;
	if ( '' === $license || '' === $item_name ) {
		return array();
	}
	$response = wp_remote_get(
		esc_url_raw(
			add_query_arg(
				array(
					'edd_action' => $edd_action,
					'license'    => $license,
					'item_name'  => rawurlencode( $item_name ),
					'url'        => home_url(),
				),
				'https://smashballoon.com/'
			)
		),
		array(
			'timeout'   => 30,
			'sslverify' => true,
		)
	);
	if ( is_wp_error( $response ) ) {
		return array();
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) ? $data : array();
}

/**
 * Resolve the EDD item name for a Smash product (constant while loaded).
 *
 * @param array $sp Product descriptor from minn_admin_license_smash_products().
 * @return string
 */
function minn_admin_smash_item_name( $sp ) {
	if ( ! empty( $sp['item_const'] ) && defined( $sp['item_const'] ) ) {
		$v = constant( $sp['item_const'] );
		if ( is_string( $v ) && '' !== $v ) {
			return $v;
		}
	}
	return isset( $sp['fallback_item'] ) ? (string) $sp['fallback_item'] : '';
}

/**
 * Read key/status/expires from a Smash product's stored options.
 *
 * @param array $sp Product descriptor.
 * @return array{key:string,status:string,expires:string,data:array}
 */
function minn_admin_smash_read_store( $sp ) {
	$out = array(
		'key'     => '',
		'status'  => '',
		'expires' => '',
		'data'    => array(),
	);
	$storage = isset( $sp['storage'] ) ? $sp['storage'] : 'triple';
	if ( 'triple' === $storage ) {
		$out['key']    = trim( (string) get_option( $sp['key_opt'], '' ) );
		$out['status'] = strtolower( trim( (string) get_option( $sp['status_opt'], '' ) ) );
		$data          = get_option( $sp['data_opt'], array() );
		if ( is_object( $data ) ) {
			$data = (array) $data;
		}
		$out['data'] = is_array( $data ) ? $data : array();
		if ( isset( $out['data']['expires'] ) ) {
			$out['expires'] = (string) $out['data']['expires'];
		}
		if ( '' === $out['status'] && isset( $out['data']['license'] ) ) {
			$out['status'] = strtolower( trim( (string) $out['data']['license'] ) );
		}
		return $out;
	}
	if ( 'settings' === $storage ) {
		$settings = get_option( $sp['settings_opt'], array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$kf            = $sp['key_field'];
		$sf            = $sp['status_field'];
		$out['key']    = isset( $settings[ $kf ] ) ? trim( (string) $settings[ $kf ] ) : '';
		$out['status'] = isset( $settings[ $sf ] ) ? strtolower( trim( (string) $settings[ $sf ] ) ) : '';
		// Reviews / TikTok may park the EDD payload under license_info; fall
		// back when the flat status field is empty (post-migration wipe).
		if ( ! empty( $settings['license_info'] ) && is_array( $settings['license_info'] ) ) {
			if ( isset( $settings['license_info']['expires'] ) ) {
				$out['expires'] = (string) $settings['license_info']['expires'];
			}
			if ( '' === $out['status'] && isset( $settings['license_info']['license'] ) ) {
				$out['status'] = strtolower( trim( (string) $settings['license_info']['license'] ) );
			}
		}
		$out['data'] = $settings;
		return $out;
	}
	if ( 'info' === $storage ) {
		$info = get_option( $sp['info_opt'], array() );
		if ( ! is_array( $info ) ) {
			$info = array();
		}
		$out['key']     = isset( $info['key'] ) ? trim( (string) $info['key'] ) : '';
		$out['status']  = isset( $info['status'] ) ? strtolower( trim( (string) $info['status'] ) ) : '';
		$out['expires'] = isset( $info['expires'] ) ? (string) $info['expires'] : '';
		$out['data']    = $info;
		return $out;
	}
	return $out;
}

/**
 * Persist a Smash license after a successful EDD activate/check.
 *
 * @param array  $sp     Product descriptor.
 * @param string $key    License key.
 * @param array  $remote Decoded EDD response.
 */
function minn_admin_smash_write_store( $sp, $key, $remote ) {
	$status  = isset( $remote['license'] ) ? (string) $remote['license'] : '';
	$expires = isset( $remote['expires'] ) ? (string) $remote['expires'] : '';
	$storage = isset( $sp['storage'] ) ? $sp['storage'] : 'triple';
	if ( 'triple' === $storage ) {
		update_option( $sp['key_opt'], $key );
		update_option( $sp['status_opt'], $status );
		update_option( $sp['data_opt'], $remote );
		return;
	}
	if ( 'settings' === $storage ) {
		$settings = get_option( $sp['settings_opt'], array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ $sp['key_field'] ]    = $key;
		$settings[ $sp['status_field'] ] = $status;
		if ( $remote ) {
			$settings['license_info'] = $remote;
		}
		update_option( $sp['settings_opt'], $settings );
		return;
	}
	if ( 'info' === $storage ) {
		update_option(
			$sp['info_opt'],
			array(
				'key'     => $key,
				'status'  => $status,
				'expires' => $expires,
			)
		);
	}
}

/**
 * Clear or mark inactive a Smash license after deactivate (key may stay for
 * one-click re-activate on Reviews/TikTok; classic products keep the key
 * and set status inactive, matching their own UI).
 *
 * @param array $sp    Product descriptor.
 * @param array $remote Optional deactivate response.
 */
function minn_admin_smash_clear_store( $sp, $remote = array() ) {
	$storage = isset( $sp['storage'] ) ? $sp['storage'] : 'triple';
	if ( 'triple' === $storage ) {
		if ( $remote ) {
			update_option( $sp['data_opt'], $remote );
		}
		update_option( $sp['status_opt'], 'inactive' );
		return;
	}
	if ( 'settings' === $storage ) {
		$settings = get_option( $sp['settings_opt'], array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		// Preserve license_key for one-click re-activate (Reviews contract).
		$settings[ $sp['status_field'] ] = '';
		if ( isset( $settings['license_info'] ) ) {
			$settings['license_info'] = '';
		}
		update_option( $sp['settings_opt'], $settings );
		return;
	}
	if ( 'info' === $storage ) {
		delete_option( $sp['info_opt'] );
	}
}

/**
 * Admin Columns Pro's DI container, for the license actions. Their main
 * file bails outside wp-admin (a hard is_admin() gate), so under REST the
 * plugin never loads and its ac/init container never exists. Replicate
 * their bootstrap instead (the Slider Revolution manual-load precedent):
 * both bundled autoloaders + api.php files, then the SAME six definition
 * files their main file merges — but skip the Loader step, which only
 * wires admin screens Minn does not need. Built once per request.
 *
 * @return object|null The AC\DI\Container, or null when unavailable.
 */
function minn_admin_acp_container() {
	static $container = null;
	if ( null !== $container ) {
		return $container ? $container : null;
	}
	$container = false;
	$dir       = WP_PLUGIN_DIR . '/admin-columns-pro';
	if ( ! file_exists( $dir . '/vendor/autoload.php' ) || ! file_exists( $dir . '/admin-columns/vendor/autoload.php' ) ) {
		return null;
	}
	try {
		require_once $dir . '/admin-columns/vendor/autoload.php';
		require_once $dir . '/admin-columns/api.php';
		require_once $dir . '/vendor/autoload.php';
		require_once $dir . '/api.php';
		if ( ! defined( 'ACP_FILE' ) ) {
			define( 'ACP_FILE', $dir . '/admin-columns-pro.php' );
		}
		if ( ! defined( 'ACP_VERSION' ) ) {
			$data = get_file_data( $dir . '/admin-columns-pro.php', array( 'Version' => 'Version' ) );
			define( 'ACP_VERSION', ! empty( $data['Version'] ) ? $data['Version'] : '0' );
		}
		// A failed require raises E_COMPILE_ERROR, which is NOT a Throwable and
		// cannot be caught — so an Admin Columns Pro build whose addon layout
		// differs (older version, partial install, an addon dir removed by a
		// scanner) took the whole request down with an uncatchable fatal. This
		// runs eagerly from the provider registry, so it bricked the Licenses
		// tab AND the System page: exactly the screens used to diagnose it.
		$mandatory = array(
			$dir . '/admin-columns/settings/container-definitions.php',
			$dir . '/settings/container-definitions.php',
		);
		foreach ( $mandatory as $file ) {
			if ( ! file_exists( $file ) ) {
				$container = false;
				return null;
			}
		}
		$optional = array(
			$dir . '/addons/acf/settings/container-definitions.php',
			$dir . '/addons/data-sources/settings/container-definitions.php',
			$dir . '/addons/gravityforms/settings/container-definitions.php',
			$dir . '/addons/woocommerce/settings/container-definitions.php',
		);
		$defs = array();
		foreach ( array_merge( $mandatory, $optional ) as $file ) {
			if ( file_exists( $file ) ) {
				$loaded = require $file;
				if ( is_array( $loaded ) ) {
					$defs[] = $loaded;
				}
			}
		}
		if ( ! $defs ) {
			$container = false;
			return null;
		}
		$definitions = array_merge( ...$defs );
		$container = new \AC\DI\Container( ( new \AC\Vendor\DI\ContainerBuilder() )->addDefinitions( $definitions )->build() );
	} catch ( \Throwable $e ) {
		$container = false;
		return null;
	}
	return $container;
}

/**
 * Map an EDD license response to Minn's {ok,code,message} result shape.
 *
 * @param array $remote EDD response array.
 * @return array
 */
function minn_admin_smash_classify( $remote ) {
	if ( ! $remote ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach smashballoon.com', 'minn-admin' ) );
	}
	$word  = isset( $remote['license'] ) ? strtolower( (string) $remote['license'] ) : '';
	$error = isset( $remote['error'] ) ? strtolower( (string) $remote['error'] ) : '';
	if ( 'valid' === $word ) {
		return array( 'ok' => true );
	}
	if ( 'no_activations_left' === $error || 'no_activations_left' === $word ) {
		$msg = __( 'You have reached the maximum number of sites for this Smash Balloon license', 'minn-admin' );
		if ( isset( $remote['site_count'], $remote['max_sites'] ) ) {
			/* translators: 1: number of sites the license is active on. 2: maximum number of sites the license allows. */
			$msg = sprintf( __( 'You have reached the maximum number of sites for this Smash Balloon license (%1$d/%2$d)', 'minn-admin' ), (int) $remote['site_count'], (int) $remote['max_sites'] );
		}
		return array( 'ok' => false, 'code' => 'site_limit', 'message' => $msg );
	}
	if ( 'expired' === $word || 'expired' === $error ) {
		return array( 'ok' => false, 'code' => 'expired', 'message' => __( 'This Smash Balloon license has expired', 'minn-admin' ) );
	}
	$msg = isset( $remote['errorMsg'] ) ? wp_strip_all_tags( (string) $remote['errorMsg'] ) : '';
	if ( ! $msg && $error ) {
		$msg = str_replace( '_', ' ', $error );
	}
	return array( 'ok' => false, 'code' => 'invalid', 'message' => $msg ? $msg : __( 'Smash Balloon did not accept that key', 'minn-admin' ) );
}

/**
 * Brainstorm Force products, read from their own registry option.
 *
 * Every BSF product (Astra Pro, the Ultimate Addons, Convert Pro, Schema
 * Pro, WP Portfolio, Spectra Pro, Premium Starter Templates …) shares one
 * licensing core — bsf-core, internally "graupi", bundled inside each
 * product under admin/bsf-core — and one registry option,
 * `brainstrom_products` (their spelling), keyed by the product id from
 * each product's admin/bsf.yml. Each product carries its OWN purchase key
 * and its own registration, so each becomes its own provider row.
 *
 * Pure option read: the vendor's own registry is the only source.
 *
 * @return array product_id => { id, kind, name, component, key, status, expires }
 */
function minn_admin_bsf_products() {
	$reg = get_option( 'brainstrom_products' );
	if ( ! is_array( $reg ) ) {
		return array();
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins = get_plugins();
	$out     = array();
	foreach ( array( 'plugins' => 'plugin', 'themes' => 'theme' ) as $group => $kind ) {
		if ( empty( $reg[ $group ] ) || ! is_array( $reg[ $group ] ) ) {
			continue;
		}
		foreach ( $reg[ $group ] as $slug => $p ) {
			if ( ! is_array( $p ) ) {
				continue;
			}
			$id = (string) ( ! empty( $p['id'] ) ? $p['id'] : $slug );
			if ( '' === $id ) {
				continue;
			}
			// `template` is 'dir/file.php' for plugins and the stylesheet
			// directory for themes; both are written by their init_bsf_core.
			$template  = isset( $p['template'] ) ? (string) $p['template'] : '';
			$component = '';
			if ( 'plugin' === $kind && false !== strpos( $template, '/' ) ) {
				$component = $template;
			} elseif ( 'theme' === $kind && '' !== $template ) {
				$component = 'theme:' . $template;
			}
			// product_name is filled by their bsf_update_all_product_version
			// from the component header; it can be absent or empty on a
			// registry that has only ever been seeded by init_bsf_core.
			$name = isset( $p['product_name'] ) ? trim( (string) $p['product_name'] ) : '';
			if ( '' === $name && 'plugin' === $kind && isset( $plugins[ $template ]['Name'] ) ) {
				$name = (string) $plugins[ $template ]['Name'];
			}
			if ( '' === $name && 'theme' === $kind && $template ) {
				$theme = wp_get_theme( $template );
				$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : '';
			}
			$out[ $id ] = array(
				'id'        => $id,
				'kind'      => $kind,
				'name'      => '' !== $name ? $name : $id,
				'component' => $component,
				'key'       => ! empty( $p['purchase_key'] ),
				'status'    => strtolower( trim( (string) ( $p['status'] ?? '' ) ) ),
				// bsf-core stores `expires` from the license-status API
				// (since their 1.29.14); older registries simply lack it.
				'expires'   => minn_admin_license_expiry( $p['expires'] ?? '' ),
			);
		}
	}
	return $out;
}

/**
 * Populate BSF's registry the way their own code does.
 *
 * init_bsf_core() is hooked to admin_init ONLY, so under REST the registry
 * is whatever a wp-admin pageload last wrote: a product installed since the
 * last dashboard visit is simply absent (routine on CLI-provisioned sites).
 * Their own WP-CLI command calls this first for exactly that reason.
 * get_plugins() lives in wp-admin/includes, which REST never loads.
 *
 * This WRITES their option, so the read path only reaches for it behind a
 * long transient (a self-heal, not a per-request side effect) while the
 * action path always syncs — you must be able to license a product the
 * moment it is installed.
 *
 * @return bool Whether bsf-core was loaded and the sync ran.
 */
function minn_admin_bsf_sync() {
	if ( ! function_exists( 'init_bsf_core' ) ) {
		return false;
	}
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	try {
		init_bsf_core();
		if ( function_exists( 'bsf_update_all_product_version' ) ) {
			bsf_update_all_product_version();
		}
	} catch ( \Throwable $e ) {
		return false;
	}
	return true;
}

/**
 * The shared bsf-core license manager, or null when no BSF product is
 * active (their core is bundled inside the products, so nothing licensing
 * related is loaded without one).
 */
function minn_admin_bsf_manager() {
	return class_exists( 'BSF_License_Manager' ) ? \BSF_License_Manager::instance() : null;
}

/** One product's RAW registry row, for snapshot and restore. */
function minn_admin_bsf_raw_row( $product_id ) {
	$reg = get_option( 'brainstrom_products' );
	if ( ! is_array( $reg ) ) {
		return null;
	}
	foreach ( array( 'plugins', 'themes' ) as $group ) {
		if ( isset( $reg[ $group ][ $product_id ] ) ) {
			return array( 'group' => $group, 'row' => $reg[ $group ][ $product_id ] );
		}
	}
	return null;
}

/** Put a snapshot back, used only when a rejected call wrote anyway. */
function minn_admin_bsf_restore_row( $product_id, $snapshot ) {
	if ( ! is_array( $snapshot ) || empty( $snapshot['group'] ) ) {
		return;
	}
	$reg = get_option( 'brainstrom_products' );
	if ( ! is_array( $reg ) ) {
		return;
	}
	$reg[ $snapshot['group'] ][ $product_id ] = $snapshot['row'];
	update_option( 'brainstrom_products', $reg );
}

/**
 * Classify a Brainstorm Force API message. Their endpoint answers with a
 * human sentence and no machine code, so the wording is all there is; the
 * seat-limit case has to stay first-class (a retry burns nothing, but the
 * user needs to know it is a seat problem and not a bad key).
 */
function minn_admin_bsf_message_code( $message ) {
	$m = strtolower( wp_strip_all_tags( (string) $message ) );
	if ( preg_match( '/(activation|site).{0,20}limit|limit.{0,20}(reach|exceed)|maximum number|no activations/', $m ) ) {
		return 'site_limit';
	}
	if ( false !== strpos( $m, 'expire' ) ) {
		return 'expired';
	}
	if ( preg_match( '/connecting to our license api|could not resolve|timed out|curl error/', $m ) ) {
		return 'error';
	}
	return 'invalid';
}

/**
 * Activate one BSF product through bsf-core's own activation flow.
 *
 * bsf_process_license_activation() is the complete vendor path: it calls
 * their API, and on acceptance writes the purchase key, status and expiry
 * into the registry itself. The nonce check lives in the admin_init
 * wrapper (bsf_activate_license), not in this method, which is why their
 * WP-CLI command can drive it too.
 */
function minn_admin_bsf_activate( $product_id, $secret ) {
	$mgr = minn_admin_bsf_manager();
	if ( ! $mgr ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Brainstorm Force licensing is not loaded on this site.', 'minn-admin' ) );
	}
	minn_admin_bsf_sync();
	$before = minn_admin_bsf_raw_row( $product_id );
	$res    = $mgr->bsf_process_license_activation( array(
		'license_key' => $secret,
		'product_id'  => $product_id,
	) );
	$ok  = is_array( $res ) && ! empty( $res['success'] ) && 'false' !== $res['success'];
	$msg = is_array( $res ) && isset( $res['message'] ) ? trim( wp_strip_all_tags( (string) $res['message'] ) ) : '';
	delete_transient( $product_id . '_license_status' );
	if ( $ok ) {
		return array( 'ok' => true, 'code' => '', 'message' => $msg );
	}
	// Their code writes nothing on rejection, but a key must never be able
	// to land halfway: put the row back if anything moved.
	$after = minn_admin_bsf_raw_row( $product_id );
	if ( $before && $after !== $before ) {
		minn_admin_bsf_restore_row( $product_id, $before );
	}
	return array(
		'ok'      => false,
		'code'    => minn_admin_bsf_message_code( $msg ),
		'message' => '' !== $msg ? $msg : __( 'Brainstorm Force did not accept that key.', 'minn-admin' ),
	);
}

/**
 * Deactivate one BSF product. This is a real deregistration against their
 * API, so the seat is freed; their method clears the stored key, status
 * and expiry on success.
 */
function minn_admin_bsf_deactivate( $product_id ) {
	$mgr = minn_admin_bsf_manager();
	if ( ! $mgr ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Brainstorm Force licensing is not loaded on this site.', 'minn-admin' ) );
	}
	$res = $mgr->process_license_deactivation( $product_id );
	$ok  = is_array( $res ) && ! empty( $res['success'] ) && 'false' !== $res['success'];
	$msg = is_array( $res ) && isset( $res['message'] ) ? trim( wp_strip_all_tags( (string) $res['message'] ) ) : '';
	delete_transient( $product_id . '_license_status' );
	return array(
		'ok'      => $ok,
		'code'    => $ok ? '' : 'error',
		'message' => '' !== $msg ? $msg : ( $ok ? '' : __( 'Brainstorm Force did not release that activation.', 'minn-admin' ) ),
	);
}

/**
 * Ask Brainstorm Force whether this product's stored key is still active.
 *
 * get_remote_license_status() caches its answer in a 6 hour transient, so
 * a verify has to drop that first or it would replay a stale yes. The call
 * also stores the returned expiry back into the registry, which is where
 * the row's renewal date comes from.
 */
function minn_admin_bsf_verify( $product_id ) {
	$mgr = minn_admin_bsf_manager();
	if ( ! $mgr ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Brainstorm Force licensing is not loaded on this site.', 'minn-admin' ) );
	}
	$row = minn_admin_bsf_raw_row( $product_id );
	$key = is_array( $row ) && ! empty( $row['row']['purchase_key'] ) ? (string) $row['row']['purchase_key'] : '';
	if ( '' === $key ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No license key is stored for this product.', 'minn-admin' ) );
	}
	delete_transient( $product_id . '_license_status' );
	$ok = (bool) $mgr->get_remote_license_status( $key, $product_id );
	if ( $ok ) {
		return array( 'ok' => true, 'code' => '', 'message' => __( 'Brainstorm Force confirmed this license is active.', 'minn-admin' ) );
	}
	// Their status call answers a bare boolean, but it stores the expiry
	// alongside it — an elapsed date is the difference between a lapsed
	// renewal and a key their API no longer recognizes.
	$after   = minn_admin_bsf_raw_row( $product_id );
	$expires = minn_admin_license_expiry( is_array( $after ) ? ( $after['row']['expires'] ?? '' ) : '' );
	if ( minn_admin_license_expired( $expires ) ) {
		return array( 'ok' => false, 'code' => 'expired', 'message' => __( 'This license expired on ', 'minn-admin' ) . $expires . '.' );
	}
	return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Brainstorm Force reports this license is not active on this site.', 'minn-admin' ) );
}

function minn_admin_license_fingerprints() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins = get_plugins();
	$themes  = wp_get_themes();
	$sig     = md5( wp_json_encode( array( array_keys( $plugins ), wp_list_pluck( $plugins, 'Version' ), array_keys( $themes ) ) ) );
	$cached  = get_transient( 'minn_admin_license_fp' );
	if ( is_array( $cached ) && isset( $cached['sig'], $cached['fp'] ) && $cached['sig'] === $sig ) {
		return $cached['fp'];
	}

	$sdk_of = function ( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return '';
		}
		// Cheap fixed-path checks first.
		if ( is_dir( $dir . '/freemius' ) || is_dir( $dir . '/vendor/freemius/wordpress-sdk' ) ) {
			return 'freemius';
		}
		// Bounded walk: filenames only, depth <= 3, skip asset-heavy dirs,
		// cap total entries so a huge theme (Divi) can't make this slow.
		$skip    = array( 'node_modules', 'assets', 'images', 'img', 'fonts', 'css', 'js', 'languages', 'lang', 'dist', 'build', 'blocks' );
		$visited = 0;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
					function ( $f ) use ( $skip ) {
						return ! ( $f->isDir() && in_array( strtolower( $f->getFilename() ), $skip, true ) );
					}
				),
				RecursiveIteratorIterator::SELF_FIRST
			);
			$it->setMaxDepth( 3 );
			foreach ( $it as $f ) {
				if ( ++$visited > 800 ) {
					break;
				}
				$fn = strtolower( $f->getFilename() );
				if ( $f->isFile() && preg_match( '/edd[_\-]?sl[_\-]?plugin[_\-]?updater/', $fn ) ) {
					return 'edd';
				}
				if ( $f->isDir() && 'licensing' === $fn && false !== stripos( $f->getPathname(), 'surecart' ) ) {
					return 'surecart';
				}
			}
		} catch ( \Throwable $e ) {
			return '';
		}
		return '';
	};

	$fp = array();
	foreach ( $plugins as $file => $meta ) {
		$dirname = dirname( $file );
		if ( '.' === $dirname ) {
			continue; // Single-file plugins embed no SDK dir.
		}
		$sdk = $sdk_of( WP_PLUGIN_DIR . '/' . $dirname );
		if ( $sdk ) {
			$fp[] = array(
				'component' => $file,
				'kind'      => 'plugin',
				'name'      => $meta['Name'] ? $meta['Name'] : $dirname,
				'slug'      => $dirname,
				'sdk'       => $sdk,
			);
		}
	}
	foreach ( $themes as $slug => $theme ) {
		$sdk = $sdk_of( $theme->get_stylesheet_directory() );
		if ( $sdk ) {
			$fp[] = array(
				'component' => 'theme:' . $slug,
				'kind'      => 'theme',
				'name'      => $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $slug,
				'slug'      => $slug,
				'sdk'       => $sdk,
			);
		}
	}
	set_transient( 'minn_admin_license_fp', array( 'sig' => $sig, 'fp' => $fp ), DAY_IN_SECONDS );
	return $fp;
}

/**
 * Bundled vendor readers. Every reader only touches wp_options / postmeta
 * through core APIs (which handle their own unserialization); none call
 * into the vendor's classes and none go to the network. Option names and
 * shapes were verified in each vendor's source (docs/license-manager.md).
 */
function minn_admin_license_default_providers() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$plugins   = get_plugins();
	$themes    = wp_get_themes();
	$has       = function ( $file ) use ( $plugins ) {
		return isset( $plugins[ $file ] );
	};
	$has_theme = function ( $slug ) use ( $themes ) {
		return isset( $themes[ $slug ] );
	};
	$item = function ( $args ) {
		return wp_parse_args( $args, array(
			'name'    => '',
			'kind'    => 'plugin',
			'state'   => 'unknown',
			'key'     => false,
			'expires' => '',
			'note'    => '',
			'stale'   => false,
		) );
	};
	// The EDD Software Licensing status vocabulary, shared by every vendor
	// that speaks EDD's protocol under a renamed client (SearchWP, Soflyy,
	// Perfmatters, GP Premium): the status word maps straight to a state.
	// 'active' is Soflyy's post-activation word for a working license.
	$edd_state = function ( $word ) {
		$word = strtolower( trim( (string) $word ) );
		if ( in_array( $word, array( 'valid', 'active' ), true ) ) {
			return array( 'valid', '' );
		}
		if ( 'expired' === $word ) {
			return array( 'expired', '' );
		}
		if ( '' === $word ) {
			return array( 'unknown', 'key stored; no status recorded yet' );
		}
		return array( 'invalid', str_replace( '_', ' ', $word ) );
	};
	$breakdance_state = function ( $info ) {
		$edd     = is_array( $info ) && is_array( $info['edd_key_info'] ?? null ) ? $info['edd_key_info'] : array();
		$status  = strtolower( trim( (string) ( $edd['license'] ?? '' ) ) );
		$expires = minn_admin_license_expiry( $edd['expires'] ?? '' );
		$state   = 'unknown';
		$note    = '';
		if ( 'valid' === $status ) {
			$state = minn_admin_license_expired( $expires ) ? 'expired' : 'valid';
		} elseif ( 'expired' === $status ) {
			$state = 'expired';
		} elseif ( in_array( $status, array( 'inactive', 'site_inactive' ), true ) ) {
			$state = 'invalid';
			$note  = __( 'Key stored; Breakdance is not activated for this site', 'minn-admin' );
		} elseif ( '' !== $status ) {
			$state = 'invalid';
			$note  = str_replace( '_', ' ', $status );
		}
		return array( $state, $expires, $note, $status );
	};

	$providers = array();

	// Elementor Pro: key option + a {timeout, value: json} data wrapper.
	// value carries success/error/expires; error uses their status strings.
	$providers['elementor-pro'] = array(
		'name'      => 'Elementor Pro',
		'component' => 'elementor-pro/elementor-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'elementor-pro/elementor-pro.php' );
		},
		'read'      => function () use ( $item ) {
			$key = get_option( 'elementor_pro_license_key' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'Elementor Pro', 'state' => 'missing' ) ) );
			}
			$read_data = function ( $option ) {
				$raw = get_option( $option );
				if ( ! is_array( $raw ) || empty( $raw['value'] ) ) {
					return null;
				}
				$v = json_decode( (string) $raw['value'], true );
				return is_array( $v ) ? array( 'value' => $v, 'timeout' => (int) ( $raw['timeout'] ?? 0 ) ) : null;
			};
			$data = $read_data( '_elementor_pro_license_v2_data' );
			if ( ! $data ) {
				$data = $read_data( '_elementor_pro_license_v2_data_fallback' );
			}
			if ( ! $data ) {
				return array( $item( array( 'name' => 'Elementor Pro', 'key' => true, 'note' => __( 'Key stored; Elementor has not recorded a status yet', 'minn-admin' ) ) ) );
			}
			$v       = $data['value'];
			// Elementor stamps timeout with current_time() (site-local).
			$stale   = $data['timeout'] && $data['timeout'] < current_time( 'timestamp' );
			$expires = minn_admin_license_expiry( $v['expires'] ?? '' );
			$state   = 'unknown';
			$note    = '';
			if ( ! empty( $v['success'] ) ) {
				$state = minn_admin_license_expired( $expires ) ? 'expired' : 'valid';
			} elseif ( ! empty( $v['error'] ) ) {
				$err   = (string) $v['error'];
				$state = ( 'expired' === $err ) ? 'expired' : ( 'missing' === $err ? 'missing' : 'invalid' );
				$note  = str_replace( '_', ' ', $err );
			}
			return array( $item( array( 'name' => 'Elementor Pro', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $note, 'stale' => $stale ) ) );
		},
	);

	// ACF Pro: base64 key option + a parsed status array
	// {status, expiry (epoch), lifetime, refunded, error_msg}.
	$providers['acf-pro'] = array(
		'name'      => 'Advanced Custom Fields PRO',
		'component' => 'advanced-custom-fields-pro/acf.php',
		'detect'    => function () use ( $has ) {
			return $has( 'advanced-custom-fields-pro/acf.php' );
		},
		'read'      => function () use ( $item ) {
			$key    = get_option( 'acf_pro_license' );
			$status = get_option( 'acf_pro_license_status' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'ACF PRO', 'state' => 'missing' ) ) );
			}
			$s        = is_array( $status ) ? strtolower( (string) ( $status['status'] ?? '' ) ) : '';
			$lifetime = ! empty( $status['lifetime'] );
			$expires  = $lifetime ? 'lifetime' : minn_admin_license_expiry( $status['expiry'] ?? '' );
			$state    = 'unknown';
			$note     = '';
			if ( ! empty( $status['refunded'] ) ) {
				$state = 'invalid';
				$note  = 'refunded';
			} elseif ( 'active' === $s ) {
				$state = minn_admin_license_expired( $expires ) ? 'expired' : 'valid';
			} elseif ( 'expired' === $s ) {
				$state = 'expired';
			} elseif ( '' !== $s ) {
				$state = 'invalid';
				$note  = $s;
			}
			return array( $item( array( 'name' => 'ACF PRO', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $note ) ) );
		},
	);

	// WP Rocket: consumer_key/email/secret_key inside wp_rocket_settings.
	// Their own local integrity rule: secret_key == crc32(consumer_email).
	$providers['wp-rocket'] = array(
		'name'      => 'WP Rocket',
		'component' => 'wp-rocket/wp-rocket.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wp-rocket/wp-rocket.php' );
		},
		'read'      => function () use ( $item ) {
			$s  = get_option( 'wp_rocket_settings' );
			$ck = is_array( $s ) ? (string) ( $s['consumer_key'] ?? '' ) : '';
			$ce = is_array( $s ) ? (string) ( $s['consumer_email'] ?? '' ) : '';
			$sk = is_array( $s ) ? (string) ( $s['secret_key'] ?? '' ) : '';
			if ( '' === $ck && '' === $sk ) {
				return array( $item( array( 'name' => 'WP Rocket', 'state' => 'missing' ) ) );
			}
			$ok      = ( 8 === strlen( $ck ) && '' !== $sk && hash_equals( $sk, hash( 'crc32', $ce ) ) );
			$flagged = (bool) get_option( 'wp_rocket_no_licence' );
			$cust    = get_transient( 'wp_rocket_customer_data' );
			$expires = minn_admin_license_expiry( minn_admin_license_prop( $cust, 'licence_expiration', '' ) );
			$state   = ( $ok && ! $flagged ) ? ( minn_admin_license_expired( $expires ) ? 'expired' : 'valid' ) : 'invalid';
			return array( $item( array( 'name' => 'WP Rocket', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $flagged ? __( 'flagged unlicensed', 'minn-admin' ) : '' ) ) );
		},
	);


	// WP Migrate (Delicious Brains / WP Engine). Three key stores, read in
	// the plugin's own precedence: the WPMDB_LICENCE constant, then user
	// meta, then the global site option. User meta is where their own
	// activation writes, so a site licensed through their UI keeps its key
	// per person and the global option stays empty.
	// Validity is whatever their API last answered, cached per user in the
	// wpmdb_licence_response_{uid} site transient. Reads never call the API,
	// so a row can be stale; verify refreshes it.
	// Their API returns no expiry date in any response, so a row states the
	// license's condition without a renewal date.
	$providers['wp-migrate'] = array(
		'name'      => 'WP Migrate',
		'component' => 'wp-migrate-db-pro/wp-migrate-db-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wp-migrate-db-pro/wp-migrate-db-pro.php' );
		},
		'read'      => function () use ( $item ) {
			$name = 'WP Migrate';
			$key  = '';
			$note = '';
			if ( defined( 'WPMDB_LICENCE' ) && '' !== (string) WPMDB_LICENCE ) {
				$key  = (string) WPMDB_LICENCE;
				$note = __( 'Key set in wp-config', 'minn-admin' );
			}
			// Their own lookup order: the current user first, then the first
			// user holding a key at all, so an admin sees the site's license
			// even when the key belongs to a colleague.
			$holder = 0;
			if ( '' === $key ) {
				$mine = get_user_meta( get_current_user_id(), 'wpmdb_licence_key', true );
				if ( is_string( $mine ) && '' !== $mine ) {
					$key    = $mine;
					$holder = get_current_user_id();
				}
			}
			if ( '' === $key ) {
				$found = get_users( array(
					'number'       => 1,
					'meta_key'     => 'wpmdb_licence_key',
					'meta_value'   => '',
					'meta_compare' => '!=',
					'fields'       => array( 'ID' ),
				) );
				if ( ! empty( $found[0]->ID ) ) {
					$stored = get_user_meta( $found[0]->ID, 'wpmdb_licence_key', true );
					if ( is_string( $stored ) && '' !== $stored ) {
						$key    = $stored;
						$holder = (int) $found[0]->ID;
						$note   = __( 'Key belongs to another user on this site', 'minn-admin' );
					}
				}
			}
			if ( '' === $key ) {
				$settings = get_site_option( 'wpmdb_settings' );
				if ( is_array( $settings ) && ! empty( $settings['licence'] ) ) {
					$key = (string) $settings['licence'];
				}
			}
			if ( '' === $key ) {
				return array( $item( array( 'name' => $name, 'state' => 'missing' ) ) );
			}

			// The cached API answer is keyed to whichever user holds the key.
			$raw = false;
			if ( $holder ) {
				$raw = get_site_transient( 'wpmdb_licence_response_' . $holder );
			}
			if ( false === $raw ) {
				$raw = get_site_transient( 'wpmdb_licence_response' );
			}
			$data = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
			if ( ! is_array( $data ) ) {
				return array( $item( array( 'name' => $name, 'state' => 'unknown', 'key' => true, 'note' => '' !== $note ? $note : __( 'Key stored; no check recorded yet', 'minn-admin' ) ) ) );
			}
			if ( ! empty( $data['dbrains_api_down'] ) ) {
				return array( $item( array( 'name' => $name, 'state' => 'unknown', 'key' => true, 'stale' => true, 'note' => __( 'Could not reach the licensing service at the last check', 'minn-admin' ) ) ) );
			}
			$errors = ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) ? array_keys( $data['errors'] ) : array();
			if ( empty( $errors ) ) {
				return array( $item( array( 'name' => $name, 'state' => 'valid', 'key' => true, 'note' => $note ) ) );
			}
			$state = 'invalid';
			$word  = (string) $errors[0];
			$notes = array(
				// Their own wording: an expired subscription keeps the plugin
				// working, so the row says what is actually lost.
				'subscription_expired'   => __( 'Subscription expired; migrations still run, updates and support stop', 'minn-admin' ),
				'subscription_cancelled' => __( 'Subscription cancelled', 'minn-admin' ),
				'licence_not_found'      => __( 'The licensing service does not recognise this key', 'minn-admin' ),
				'activation_deactivated' => __( 'This site\'s activation was turned off; activating again re-enables it', 'minn-admin' ),
				'no_activations_left'    => __( 'Every activation on this license is in use', 'minn-admin' ),
				'no_licence'             => '',
			);
			if ( 'no_licence' === $word ) {
				return array( $item( array( 'name' => $name, 'state' => 'missing' ) ) );
			}
			if ( 'subscription_expired' === $word || 'subscription_cancelled' === $word ) {
				$state = 'expired';
			}
			$why = isset( $notes[ $word ] ) ? $notes[ $word ] : str_replace( '_', ' ', $word );
			return array( $item( array( 'name' => $name, 'state' => $state, 'key' => true, 'note' => '' !== $note ? $note . '. ' . $why : $why ) ) );
		},
	);

	// Gravity Forms stores the key md5-hashed; validity and expiry ride the
	// gform_version_info option their update checker maintains.
	$providers['gravityforms'] = array(
		'name'      => 'Gravity Forms',
		'component' => 'gravityforms/gravityforms.php',
		'detect'    => function () use ( $has ) {
			return $has( 'gravityforms/gravityforms.php' );
		},
		'read'      => function () use ( $item ) {
			$key = get_option( 'rg_gforms_key' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'Gravity Forms', 'state' => 'missing' ) ) );
			}
			$vi    = get_option( 'gform_version_info' );
			$flag  = ( is_array( $vi ) && isset( $vi['is_valid_key'] ) ) ? (string) $vi['is_valid_key'] : null;
			$state = null === $flag ? 'unknown' : ( '1' === $flag ? 'valid' : 'invalid' );
			$expires = ( is_array( $vi ) && ! empty( $vi['expiration_time'] ) ) ? minn_admin_license_expiry( $vi['expiration_time'] ) : '';
			if ( 'valid' === $state && minn_admin_license_expired( $expires ) ) {
				$state = 'expired';
			}
			return array( $item( array(
				'name'    => 'Gravity Forms',
				'state'   => $state,
				'key'     => true,
				'expires' => $expires,
				'note'    => null === $flag ? 'Key stored (hashed); no recorded check yet' : '',
			) ) );
		},
	);

	// Gravity SMTP: key inside the gravitysmtp_config JSON option; validity
	// only known via its remote connector, so the read stays presence-based.
	$providers['gravitysmtp'] = array(
		'name'      => 'Gravity SMTP',
		'component' => 'gravitysmtp/gravitysmtp.php',
		'detect'    => function () use ( $has ) {
			return $has( 'gravitysmtp/gravitysmtp.php' );
		},
		'read'      => function () use ( $item ) {
			$cfg = json_decode( (string) get_option( 'gravitysmtp_config' ), true );
			$key = is_array( $cfg ) && ! empty( $cfg['license_key'] );
			return array( $item( array(
				'name'  => 'Gravity SMTP',
				'state' => $key ? 'unknown' : 'missing',
				'key'   => $key,
				'note'  => $key ? 'Key stored; re-verify to check it with Gravity' : '',
			) ) );
		},
	);

	// Breakdance Pro stores both values as JSON through its Data option API.
	// The validity payload carries EDD's status, expiry and last-check time.
	$providers['breakdance'] = array(
		'name'      => 'Breakdance Pro',
		'component' => 'breakdance/plugin.php',
		'detect'    => function () use ( $has ) {
			return $has( 'breakdance/plugin.php' );
		},
		'read'      => function () use ( $item, $breakdance_state ) {
			$key  = json_decode( (string) get_option( 'breakdance_license_key', '' ), true );
			$info = json_decode( (string) get_option( 'breakdance_license_key_validity_info', '' ), true );
			if ( ! is_string( $key ) || '' === trim( $key ) ) {
				return array( $item( array( 'name' => 'Breakdance Pro', 'state' => 'missing' ) ) );
			}
			if ( ! is_array( $info ) ) {
				return array( $item( array(
					'name'  => 'Breakdance Pro',
					'key'   => true,
					'note'  => __( 'Key stored; Breakdance has not recorded a check yet', 'minn-admin' ),
				) ) );
			}
			list( $state, $expires, $note ) = $breakdance_state( $info );
			$checked = isset( $info['checked_at'] ) ? (int) $info['checked_at'] : 0;
			return array( $item( array(
				'name'    => 'Breakdance Pro',
				'state'   => $state,
				'key'     => true,
				'expires' => $expires,
				'note'    => $note,
				'stale'   => $checked > 0 && $checked < time() - WEEK_IN_SECONDS,
			) ) );
		},
		'activate_url' => admin_url( 'admin.php?page=breakdance_settings&tab=license' ),
	);

	// Bricks (theme): key option + a 7-day status transient ('active' = good).
	$providers['bricks'] = array(
		'name'      => 'Bricks',
		'component' => 'theme:bricks',
		'detect'    => function () use ( $has_theme ) {
			return $has_theme( 'bricks' );
		},
		'read'      => function () use ( $item ) {
			$key = get_option( 'bricks_license_key' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'Bricks', 'kind' => 'theme', 'state' => 'missing' ) ) );
			}
			$status = get_transient( 'bricks_license_status' );
			$state  = 'unknown';
			$note   = 'Status cache lapsed; Bricks re-checks weekly';
			$stale  = false === $status;
			if ( is_string( $status ) && '' !== $status ) {
				if ( 'active' === $status ) {
					$state = 'valid';
					$note  = '';
				} elseif ( 'error_remote' === $status ) {
					$note = __( 'Bricks could not reach its license server', 'minn-admin' );
				} else {
					$state = 'invalid';
					$note  = str_replace( '_', ' ', $status );
				}
			}
			return array( $item( array( 'name' => 'Bricks', 'kind' => 'theme', 'state' => $state, 'key' => true, 'note' => $note, 'stale' => $stale ) ) );
		},
	);

	// Divi / Elegant Themes: site options for credentials + account status.
	$providers['divi'] = array(
		'name'      => 'Divi (Elegant Themes)',
		'component' => 'theme:Divi',
		'detect'    => function () use ( $has_theme ) {
			return $has_theme( 'Divi' ) || $has_theme( 'Extra' );
		},
		'read'      => function () use ( $item ) {
			$opts = get_site_option( 'et_automatic_updates_options', array() );
			$cred = is_array( $opts ) && ( ! empty( $opts['username'] ) || ! empty( $opts['api_key'] ) );
			if ( ! $cred ) {
				return array( $item( array( 'name' => 'Divi (Elegant Themes)', 'kind' => 'theme', 'state' => 'missing' ) ) );
			}
			$status = strtolower( (string) get_site_option( 'et_account_status', 'not_active' ) );
			$state  = 'unknown';
			$note   = str_replace( '_', ' ', $status );
			if ( 'active' === $status ) {
				$state = 'valid';
				$note  = '';
			} elseif ( 'expired' === $status ) {
				$state = 'expired';
			} elseif ( 'not_active' === $status ) {
				$state = 'invalid';
			}
			return array( $item( array( 'name' => 'Divi (Elegant Themes)', 'kind' => 'theme', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// Beaver Builder: site option for the key + a subscription-info transient.
	$providers['beaver-builder'] = array(
		'name'      => 'Beaver Builder',
		'component' => 'bb-plugin/fl-builder.php',
		'detect'    => function () use ( $has ) {
			return $has( 'bb-plugin/fl-builder.php' );
		},
		'read'      => function () use ( $item ) {
			$key = get_site_option( 'fl_themes_subscription_email' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'Beaver Builder', 'state' => 'missing' ) ) );
			}
			$info    = get_transient( 'fl_get_subscription_info' );
			$active  = minn_admin_license_prop( $info, 'active', null );
			$expires = minn_admin_license_expiry( minn_admin_license_prop( $info, 'expiration', '' ) );
			$state   = 'unknown';
			$note    = '';
			$stale   = false === $info || null === $info;
			if ( $stale ) {
				$note = 'Status cache lapsed; Beaver Builder re-checks on its updates screen';
			} elseif ( $active ) {
				$state = minn_admin_license_expired( $expires ) ? 'expired' : 'valid';
			} else {
				$state = 'invalid';
			}
			return array( $item( array( 'name' => 'Beaver Builder', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $note, 'stale' => $stale ) ) );
		},
	);

	// WPBakery: an Envato purchase code, presence-only by design (their
	// isActivated() is literally "a code is stored").
	$providers['js-composer'] = array(
		'name'      => 'WPBakery Page Builder',
		'component' => 'js_composer/js_composer.php',
		'detect'    => function () use ( $has ) {
			return $has( 'js_composer/js_composer.php' );
		},
		'read'      => function () use ( $item ) {
			$code = get_option( 'wpb_js_js_composer_purchase_code' );
			if ( ! $code ) {
				$code = get_option( 'js_composer_purchase_code' );
			}
			return array( $item( array(
				'name'  => 'WPBakery Page Builder',
				'state' => $code ? 'unknown' : 'missing',
				'key'   => (bool) $code,
				'note'  => $code ? 'Purchase code stored; WPBakery records no validity state' : '',
			) ) );
		},
	);

	// Brizy Pro keeps its license on the Brizy project post's meta.
	$providers['brizy-pro'] = array(
		'name'      => 'Brizy Pro',
		'component' => 'brizy-pro/brizy-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'brizy-pro/brizy-pro.php' );
		},
		'read'      => function () use ( $item ) {
			global $wpdb;
			$val = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", 'brizy-license-key' ) );
			return array( $item( array(
				'name'  => 'Brizy Pro',
				'state' => $val ? 'unknown' : 'missing',
				'key'   => (bool) $val,
				'note'  => $val ? 'Key stored; Brizy records no readable validity state' : '',
			) ) );
		},
	);

	// Etch: its own wrapper around the SureCart SDK stores in plain options
	// (etch_license_key / etch_license_status, status 'valid' when active;
	// the key may instead live in the ETCH_LICENSE_KEY constant). A dedicated
	// reader beats the generic SureCart sweep, and claiming the component
	// keeps the generic layer off it.
	$providers['etch'] = array(
		'name'      => 'Etch',
		'component' => 'etch/etch.php',
		'detect'    => function () use ( $has ) {
			return $has( 'etch/etch.php' );
		},
		'read'      => function () use ( $item ) {
			$key      = get_option( 'etch_license_key' );
			$constant = defined( 'ETCH_LICENSE_KEY' ) && ETCH_LICENSE_KEY;
			if ( ! $key && ! $constant ) {
				return array( $item( array( 'name' => 'Etch', 'state' => 'missing' ) ) );
			}
			$status = strtolower( (string) get_option( 'etch_license_status' ) );
			$state  = 'valid' === $status ? 'valid' : ( '' === $status ? 'unknown' : 'invalid' );
			$note   = $constant ? 'Key set in wp-config (ETCH_LICENSE_KEY)' : ( 'valid' === $status ? '' : ( $status ? str_replace( '_', ' ', $status ) : 'Key stored; no recorded status' ) );
			return array( $item( array( 'name' => 'Etch', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// AnalyticsWP: site option {key, last_check, is_expired?, is_on_free_trial,
	// free_trial_end} via its bundled WooSoftwareLicense toolkit.
	$providers['analyticswp'] = array(
		'name'      => 'AnalyticsWP',
		'component' => 'analyticswp/analyticswp.php',
		'detect'    => function () use ( $has ) {
			return $has( 'analyticswp/analyticswp.php' );
		},
		'read'      => function () use ( $item ) {
			$d = get_site_option( 'analyticswp_slt_license' );
			if ( ! is_array( $d ) || empty( $d['key'] ) ) {
				return array( $item( array( 'name' => 'AnalyticsWP', 'state' => 'missing' ) ) );
			}
			$trial_end = minn_admin_license_expiry( $d['free_trial_end'] ?? '' );
			$state     = 'valid';
			$note      = '';
			if ( ! empty( $d['is_expired'] ) ) {
				$state = 'expired';
			} elseif ( ! empty( $d['is_on_free_trial'] ) ) {
				$note  = __( 'free trial', 'minn-admin' );
				$state = minn_admin_license_expired( $trial_end ) ? 'expired' : 'valid';
			}
			return array( $item( array( 'name' => 'AnalyticsWP', 'state' => $state, 'key' => true, 'expires' => $trial_end, 'note' => $note ) ) );
		},
	);

	// Brainstorm Force family (Astra Pro, the Ultimate Addons, Convert Pro,
	// Schema Pro, WP Portfolio, Spectra Pro, Premium Starter Templates …).
	// One shared licensing core and one registry option, but a separate
	// purchase key and registration per product — so each product becomes
	// its own provider: one row, one key field, one set of controls.
	// The list is whatever their registry holds, so a BSF product Minn has
	// never heard of is covered the day it ships.
	//
	// Their registry only refreshes on admin_init, which REST never runs.
	// Self-heal it at most twice a day so a CLI-installed product does not
	// stay invisible until somebody opens wp-admin.
	if ( class_exists( 'BSF_License_Manager' ) && ! get_transient( 'minn_admin_bsf_synced' ) ) {
		set_transient( 'minn_admin_bsf_synced', 1, 12 * HOUR_IN_SECONDS );
		minn_admin_bsf_sync();
	}
	// Actions route through bsf-core, which is only loaded while at least
	// one BSF product is active; without it the rows stay read-only.
	$bsf_actionable = class_exists( 'BSF_License_Manager' );
	foreach ( minn_admin_bsf_products() as $bsf_id => $bsf_product ) {
		$provider = array(
			'name'      => $bsf_product['name'],
			'component' => $bsf_product['component'],
			'detect'    => function () use ( $bsf_id ) {
				$all = minn_admin_bsf_products();
				return isset( $all[ $bsf_id ] );
			},
			'read'      => function () use ( $item, $bsf_id ) {
				$all = minn_admin_bsf_products();
				if ( ! isset( $all[ $bsf_id ] ) ) {
					return array();
				}
				$p     = $all[ $bsf_id ];
				$state = 'missing';
				$note  = '';
				if ( $p['key'] ) {
					if ( 'registered' === $p['status'] ) {
						$state = minn_admin_license_expired( $p['expires'] ) ? 'expired' : 'valid';
					} else {
						$state = 'unknown';
						$note  = $p['status']
							? str_replace( '_', ' ', $p['status'] )
							: 'Key stored; Brainstorm Force has not recorded a registration';
					}
				}
				return array( $item( array(
					'name'    => $p['name'],
					'kind'    => $p['kind'],
					'state'   => $state,
					'key'     => $p['key'],
					'expires' => $p['expires'],
					'note'    => $note,
				) ) );
			},
		);
		if ( $bsf_actionable ) {
			$provider['activate'] = function ( $secret ) use ( $bsf_id ) {
				return minn_admin_bsf_activate( $bsf_id, $secret );
			};
			// Deactivate and verify both need a stored key to act on.
			if ( $bsf_product['key'] ) {
				$provider['deactivate'] = function () use ( $bsf_id ) {
					return minn_admin_bsf_deactivate( $bsf_id );
				};
				$provider['verify'] = function () use ( $bsf_id ) {
					return minn_admin_bsf_verify( $bsf_id );
				};
			}
		}
		$providers[ 'bsf-' . sanitize_key( $bsf_id ) ] = $provider;
	}

	// WPMU DEV: one Hub API key (site option wpmudev_apikey, or the
	// WPMUDEV_APIKEY constant) unlocks the whole family. Membership status
	// is cached verbatim from their Hub in wdp_un_membership_data; the Hub
	// signals an invalid or expired key with an EMPTY membership string,
	// and a NUMERIC membership is a single-project license id.
	$providers['wpmudev'] = array(
		'name'      => 'WPMU DEV',
		'category'  => 'connection',
		'component' => 'wpmudev-updates/update-notifications.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wpmudev-updates/update-notifications.php' );
		},
		'read'      => function () use ( $item ) {
			$m = minn_admin_wpmudev_membership();
			if ( 'missing' === $m['state'] ) {
				return array( $item( array( 'name' => __( 'WPMU DEV membership', 'minn-admin' ), 'state' => 'missing' ) ) );
			}
			return array( $item( array( 'name' => __( 'WPMU DEV membership', 'minn-admin' ), 'state' => $m['state'], 'key' => true, 'note' => $m['note'] ) ) );
		},
	);

	// Smush Pro rides the Dashboard's key but keeps its OWN validity cache:
	// wp_smush_api_auth is a per-key map { key => { validity, timestamp } }
	// that Smush revalidates every 24 hours.
	$providers['smush-pro'] = array(
		'name'      => 'Smush Pro',
		'component' => 'wp-smush-pro/wp-smush.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wp-smush-pro/wp-smush.php' );
		},
		'read'      => function () use ( $item ) {
			$key = ( defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_APIKEY ) ? WPMUDEV_APIKEY : get_site_option( 'wpmudev_apikey' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'missing', 'note' => __( 'licensed through the WPMU DEV Dashboard key', 'minn-admin' ) ) ) );
			}
			$auth = get_site_option( 'wp_smush_api_auth' );
			$rec  = ( is_array( $auth ) && isset( $auth[ $key ] ) && is_array( $auth[ $key ] ) ) ? $auth[ $key ] : null;
			if ( $rec && ! empty( $rec['validity'] ) ) {
				$stale = ! empty( $rec['timestamp'] ) && ( time() - (int) $rec['timestamp'] ) > 2 * DAY_IN_SECONDS;
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'valid' === $rec['validity'] ? 'valid' : 'invalid', 'key' => true, 'stale' => $stale ) ) );
			}
			// Smush hasn't run its own 24h validation yet, so inherit the WPMU
			// DEV membership state — Smush Pro is unlocked by that membership.
			// A paid membership (full/unit/single) covers it; a free Hub
			// membership does not, so that stays unconfirmed.
			$m = minn_admin_wpmudev_membership();
			if ( in_array( $m['type'], array( 'full', 'unit', 'single' ), true ) ) {
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'valid', 'key' => true, 'note' => __( 'covered by the WPMU DEV membership', 'minn-admin' ) ) ) );
			}
			if ( 'expired' === $m['state'] ) {
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'expired', 'key' => true, 'note' => __( 'the WPMU DEV membership has expired', 'minn-admin' ) ) ) );
			}
			if ( 'invalid' === $m['state'] ) {
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'invalid', 'key' => true, 'note' => __( 'the WPMU DEV membership is paused', 'minn-admin' ) ) ) );
			}
			return array( $item( array( 'name' => 'Smush Pro', 'state' => 'unknown', 'key' => true, 'note' => __( 'Dashboard key present; Smush has not validated it yet', 'minn-admin' ) ) ) );
		},
	);

	// SearchWP: everything lives in ONE option, searchwp_license
	// { key, status, expires, remaining, type }. Its bundled EDD updater is
	// RENAMED (SearchWP\Updater), so the generic EDD filename sweep can
	// never fingerprint it; this reader is the coverage.
	$providers['searchwp'] = array(
		'name'      => 'SearchWP',
		'component' => 'searchwp/index.php',
		'detect'    => function () use ( $has ) {
			return $has( 'searchwp/index.php' );
		},
		'read'      => function () use ( $item, $edd_state ) {
			$lic = get_option( 'searchwp_license' );
			$key = ( defined( 'SEARCHWP_LICENSE_KEY' ) && SEARCHWP_LICENSE_KEY ) ? SEARCHWP_LICENSE_KEY : ( is_array( $lic ) && ! empty( $lic['key'] ) ? $lic['key'] : '' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'SearchWP', 'state' => 'missing' ) ) );
			}
			list( $state, $note ) = $edd_state( is_array( $lic ) && isset( $lic['status'] ) ? $lic['status'] : '' );
			$expires              = minn_admin_license_expiry( is_array( $lic ) && isset( $lic['expires'] ) ? $lic['expires'] : '' );
			if ( 'valid' === $state && minn_admin_license_expired( $expires ) ) {
				$state = 'expired';
			}
			if ( ! $note && is_array( $lic ) && ! empty( $lic['type'] ) ) {
				$note = 'plan: ' . $lic['type'];
			}
			return array( $item( array( 'name' => 'SearchWP', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $note ) ) );
		},
	);

	// Search & Filter Pro (v3): EDD protocol against license.searchandfilter.com
	// (item 526297), but state lives in the FREE base plugin's OWN options
	// table {prefix}search_filter_options as a JSON row named license-data
	// {status, expires, license, error, errorMessage} — not a wp_options row,
	// so the read is a prefix-scoped SELECT + json_decode. A fresh install
	// stores status "invalid" with an EMPTY license; empty key = missing.
	$providers['search-filter-pro'] = array(
		'name'      => 'Search & Filter Pro',
		'component' => 'search-filter-pro/search-filter-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'search-filter-pro/search-filter-pro.php' );
		},
		'read'      => function () use ( $item, $edd_state ) {
			global $wpdb;
			$table = $wpdb->prefix . 'search_filter_options';
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				return array( $item( array( 'name' => 'Search & Filter Pro', 'state' => 'missing', 'note' => __( 'no license store yet; the plugin has not run', 'minn-admin' ) ) ) );
			}
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table} WHERE name = %s", 'license-data' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix-scoped table name.
			$lic = $raw ? json_decode( $raw, true ) : null;
			$key = is_array( $lic ) && ! empty( $lic['license'] ) ? (string) $lic['license'] : '';
			if ( '' === $key ) {
				return array( $item( array( 'name' => 'Search & Filter Pro', 'state' => 'missing' ) ) );
			}
			list( $state, $note ) = $edd_state( isset( $lic['status'] ) ? $lic['status'] : '' );
			$expires              = minn_admin_license_expiry( isset( $lic['expires'] ) ? $lic['expires'] : '' );
			if ( 'valid' === $state && minn_admin_license_expired( $expires ) ) {
				$state = 'expired';
			}
			if ( 'invalid' === $state && ! empty( $lic['error'] ) ) {
				$note = str_replace( '_', ' ', (string) $lic['error'] );
			}
			return array( $item( array( 'name' => 'Search & Filter Pro', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $note ) ) );
		},
	);

	// WPForms Pro: option wpforms_license {key, type, is_expired, is_disabled,
	// is_invalid, is_limit_reached, is_flagged}. Their deactivate resets the
	// option to '' (a STRING, not an array). WPFORMS_LICENSE_KEY constant wins
	// over the option (their wpforms_get_license_key precedence). No expiry
	// date is stored locally; type is the plan level. Flags are only written
	// by their validation, so key + type + no flags reads valid. Lite ships
	// under the wpforms-lite directory, so wpforms/ is the Pro build.
	$providers['wpforms'] = array(
		'name'      => 'WPForms Pro',
		'component' => 'wpforms/wpforms.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wpforms/wpforms.php' );
		},
		'read'      => function () use ( $item ) {
			$lic      = get_option( 'wpforms_license' );
			$lic      = is_array( $lic ) ? $lic : array();
			$constant = defined( 'WPFORMS_LICENSE_KEY' ) && WPFORMS_LICENSE_KEY;
			$key      = $constant ? (string) WPFORMS_LICENSE_KEY : (string) ( $lic['key'] ?? '' );
			if ( '' === $key ) {
				return array( $item( array( 'name' => 'WPForms Pro', 'state' => 'missing' ) ) );
			}
			$type  = (string) ( $lic['type'] ?? '' );
			$state = 'unknown';
			$note  = 'key stored; not yet validated';
			if ( ! empty( $lic['is_invalid'] ) ) {
				$state = 'invalid';
				$note  = __( 'key rejected at the last check', 'minn-admin' );
			} elseif ( ! empty( $lic['is_disabled'] ) ) {
				$state = 'invalid';
				$note  = __( 'key disabled by the vendor', 'minn-admin' );
			} elseif ( ! empty( $lic['is_limit_reached'] ) ) {
				$state = 'invalid';
				$note  = __( 'site limit reached', 'minn-admin' );
			} elseif ( ! empty( $lic['is_expired'] ) ) {
				$state = 'expired';
				$note  = '';
			} elseif ( '' !== $type ) {
				$state = 'valid';
				$note  = 'plan: ' . $type;
				if ( ! empty( $lic['is_flagged'] ) ) {
					$note .= '; flagged by the vendor (activation soft cap)';
				}
			}
			if ( $constant ) {
				$note = trim( ( $note ? $note . '; ' : '' ) . 'key in wp-config (WPFORMS_LICENSE_KEY)' );
			}
			return array( $item( array( 'name' => 'WPForms Pro', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// Akismet: a SERVICE KEY, not a purchase license (free tier exists) —
	// the first connections-center row (docs/connections-center.md). Key in
	// option wordpress_api_key; WPCOM_API_KEY constant or the
	// akismet_get_api_key filter (Jetpack) can supply it instead, which
	// their predefined_api_key() reports when the plugin is active. Local
	// validity signal: their alert system (akismet_alert_code > 0 means the
	// API reported a key/account problem, message in akismet_alert_msg).
	$providers['akismet'] = array(
		'name'      => 'Akismet',
		'category'  => 'key',
		'component' => 'akismet/akismet.php',
		'detect'    => function () use ( $has ) {
			return $has( 'akismet/akismet.php' );
		},
		'read'      => function () use ( $item ) {
			$predefined = false;
			$key        = '';
			if ( class_exists( 'Akismet' ) ) {
				try {
					$key        = (string) Akismet::get_api_key();
					$predefined = (bool) Akismet::predefined_api_key();
				} catch ( \Throwable $e ) { /* fall through to raw reads */ }
			}
			if ( '' === $key ) {
				$key        = ( defined( 'WPCOM_API_KEY' ) && WPCOM_API_KEY ) ? (string) WPCOM_API_KEY : (string) get_option( 'wordpress_api_key', '' );
				$predefined = $predefined || ( defined( 'WPCOM_API_KEY' ) && WPCOM_API_KEY );
			}
			if ( '' === $key ) {
				return array( $item( array( 'name' => 'Akismet', 'state' => 'missing', 'note' => __( 'service key (free tier exists)', 'minn-admin' ) ) ) );
			}
			$alert = (int) get_option( 'akismet_alert_code', 0 );
			$state = $alert > 0 ? 'invalid' : 'valid';
			$note  = $alert > 0
				? trim( wp_strip_all_tags( (string) get_option( 'akismet_alert_msg', '' ) ) )
				: __( 'service key', 'minn-admin' );
			if ( $alert > 0 && '' === $note ) {
				/* translators: %d: Akismet alert code number. */
				$note = sprintf( __( 'Akismet reported a problem with this key (alert %d)', 'minn-admin' ), $alert );
			}
			if ( strlen( $note ) > 140 ) {
				$note = substr( $note, 0, 140 ) . '…';
			}
			if ( $predefined ) {
				$note .= '; key supplied in code (constant or filter)';
			}
			return array( $item( array( 'name' => 'Akismet', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// CleanTalk: a cloud ACCESS KEY, not a purchase license (same
	// connections-center shape as Akismet). Stored in
	// cleantalk_settings['apikey']; CLEANTALK_ACCESS_KEY constant wins
	// and is read-only. Local validity is cleantalk_data['key_is_ok'],
	// which their account-status check writes after talking to
	// cleantalk.org. Reads stay option-only so the row exists while
	// the plugin is inactive.
	$providers['cleantalk'] = array(
		'name'      => 'CleanTalk Anti-Spam',
		'category'  => 'key',
		'component' => 'cleantalk-spam-protect/cleantalk.php',
		'detect'    => function () use ( $has ) {
			return $has( 'cleantalk-spam-protect/cleantalk.php' );
		},
		'read'      => function () use ( $item ) {
			$predefined = function_exists( 'minn_admin_cleantalk_key_predefined' ) && minn_admin_cleantalk_key_predefined();
			$key        = function_exists( 'minn_admin_cleantalk_stored_key' )
				? minn_admin_cleantalk_stored_key()
				: ( $predefined
					? (string) CLEANTALK_ACCESS_KEY
					: (string) ( ( get_option( 'cleantalk_settings' ) ?: array() )['apikey'] ?? '' ) );
			if ( '' === $key ) {
				return array( $item( array( 'name' => 'CleanTalk Anti-Spam', 'state' => 'missing', 'note' => __( 'cloud access key (free trial, then paid)', 'minn-admin' ) ) ) );
			}
			$data  = get_option( 'cleantalk_data' );
			$data  = is_array( $data ) ? $data : array();
			$ok    = ! empty( $data['key_is_ok'] );
			$trial = ! empty( $data['license_trial'] );
			$state = $ok ? 'valid' : 'invalid';
			$note  = $ok
				? ( $trial ? __( 'cloud access key (trial)', 'minn-admin' ) : __( 'cloud access key', 'minn-admin' ) )
				: __( 'CleanTalk did not accept this access key', 'minn-admin' );
			if ( $predefined ) {
				$note .= '; key supplied in code (CLEANTALK_ACCESS_KEY)';
			}
			return array( $item( array( 'name' => 'CleanTalk Anti-Spam', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// Site connections (connections-center brick 3): three read-only
	// CONNECTION rows. Reads are pure option presence checks; the OAuth
	// ceremonies and disconnects stay on each plugin's own screen (the
	// activate_url renders as Connect ↗ while unconnected).

	// WooCommerce.com: the marketplace/updates connection official Woo
	// extensions ride. Auth in option woocommerce_helper_data ('auth' with
	// access_token = connected, their is_site_connected() rule;
	// 'auth_user_data' carries the email); the subscription list is cached
	// in transient _woocommerce_helper_subscriptions.
	$providers['woocommerce-com'] = array(
		'name'         => 'WooCommerce.com',
		'category'     => 'connection',
		'component'    => 'woocommerce/woocommerce.php',
		'detect'       => function () use ( $has ) {
			return $has( 'woocommerce/woocommerce.php' );
		},
		'activate_url' => function () {
			return admin_url( 'admin.php?page=wc-admin&tab=my-subscriptions&path=' . rawurlencode( '/extensions' ) );
		},
		'read'         => function () use ( $item ) {
			$data = get_option( 'woocommerce_helper_data', array() );
			$data = is_array( $data ) ? $data : array();
			$auth = isset( $data['auth'] ) && is_array( $data['auth'] ) ? $data['auth'] : array();
			if ( empty( $auth['access_token'] ) ) {
				return array( $item( array( 'name' => 'WooCommerce.com', 'state' => 'missing', 'note' => __( 'not connected; official extension subscriptions and updates ride this link', 'minn-admin' ) ) ) );
			}
			$email = isset( $data['auth_user_data']['email'] ) ? (string) $data['auth_user_data']['email'] : '';
			$subs  = get_transient( '_woocommerce_helper_subscriptions' );
			$note  = 'connected' . ( $email ? ' as ' . $email : '' );
			if ( is_array( $subs ) && count( $subs ) ) {
				/* translators: %d: number of WooCommerce.com extension subscriptions. */
				$note .= '; ' . sprintf( _n( '%d extension subscription', '%d extension subscriptions', count( $subs ), 'minn-admin' ), count( $subs ) );
			}
			return array( $item( array( 'name' => 'WooCommerce.com', 'state' => 'valid', 'key' => true, 'note' => $note ) ) );
		},
	);

	// SureCart: the store connection. Everything SureCart sells lives in
	// THEIR cloud — orders, customers, products — reached through a site
	// token, so this link is the whole local story. The token sits
	// ENCRYPTED in sc_api_token and is read for PRESENCE only, never
	// decrypted (their own isConnected() decrypts it; presence is the same
	// answer without handling the secret). No network: a license read never
	// calls a vendor API.
	$providers['surecart'] = array(
		'name'         => 'SureCart',
		'category'     => 'connection',
		'component'    => 'surecart/surecart.php',
		'detect'       => function () use ( $has ) {
			return $has( 'surecart/surecart.php' );
		},
		'activate_url' => function () {
			return admin_url( 'admin.php?page=sc-getting-started' );
		},
		'read'         => function () use ( $item ) {
			if ( '' === trim( (string) get_option( 'sc_api_token', '' ) ) ) {
				return array( $item( array(
					'name'  => 'SureCart',
					'state' => 'missing',
					'note'  => __( 'store not connected; orders, customers and products all live in SureCart', 'minn-admin' ),
				) ) );
			}
			return array( $item( array(
				'name'  => 'SureCart',
				'state' => 'valid',
				'key'   => true,
				'note'  => __( 'store connected', 'minn-admin' ),
			) ) );
		},
	);

	// Site Kit: Google connection state. Site credentials live ENCRYPTED in
	// googlesitekit_credentials (presence only, never decoded);
	// googlesitekit_has_connected_admins is their own connected-admins flag.
	$providers['site-kit'] = array(
		'name'         => 'Site Kit by Google',
		'category'     => 'connection',
		'component'    => 'google-site-kit/google-site-kit.php',
		'detect'       => function () use ( $has ) {
			return $has( 'google-site-kit/google-site-kit.php' );
		},
		'activate_url' => function () {
			return admin_url( 'admin.php?page=googlesitekit-splash' );
		},
		'read'         => function () use ( $item ) {
			$creds = get_option( 'googlesitekit_credentials' );
			if ( empty( $creds ) ) {
				return array( $item( array( 'name' => 'Site Kit by Google', 'state' => 'missing', 'note' => __( 'not set up; connect on the Site Kit screen', 'minn-admin' ) ) ) );
			}
			if ( ! get_option( 'googlesitekit_has_connected_admins' ) ) {
				return array( $item( array( 'name' => 'Site Kit by Google', 'state' => 'unknown', 'key' => true, 'note' => __( 'site credentials stored; no admin has finished connecting a Google account', 'minn-admin' ) ) ) );
			}
			return array( $item( array( 'name' => 'Site Kit by Google', 'state' => 'valid', 'key' => true, 'note' => __( 'Google account connected', 'minn-admin' ) ) ) );
		},
	);

	// Jetpack: the WordPress.com connection (blog token + master user).
	// PRESENCE only from jetpack_options / jetpack_private_options; the
	// tokens themselves are never read out.
	$providers['jetpack'] = array(
		'name'         => 'Jetpack',
		'category'     => 'connection',
		'component'    => 'jetpack/jetpack.php',
		'detect'       => function () use ( $has ) {
			return $has( 'jetpack/jetpack.php' );
		},
		'activate_url' => function () {
			return admin_url( 'admin.php?page=jetpack' );
		},
		'read'         => function () use ( $item ) {
			$opts    = get_option( 'jetpack_options' );
			$opts    = is_array( $opts ) ? $opts : array();
			$private = get_option( 'jetpack_private_options' );
			$private = is_array( $private ) ? $private : array();
			if ( empty( $private['blog_token'] ) || empty( $opts['id'] ) ) {
				return array( $item( array( 'name' => 'Jetpack', 'state' => 'missing', 'note' => __( 'not connected to WordPress.com', 'minn-admin' ) ) ) );
			}
			/* translators: %d: WordPress.com site ID. */
			$note = sprintf( __( 'connected to WordPress.com (site ID %d)', 'minn-admin' ), (int) $opts['id'] );
			if ( ! empty( $opts['master_user'] ) ) {
				$u = get_userdata( (int) $opts['master_user'] );
				if ( $u ) {
					/* translators: %s: WordPress.com user login name. */
					$note = sprintf( __( 'connected to WordPress.com as %s', 'minn-admin' ), $u->user_login );
				}
			}
			return array( $item( array( 'name' => 'Jetpack', 'state' => 'valid', 'key' => true, 'note' => $note ) ) );
		},
	);

	// Admin Columns Pro (v7): their own API on admincolumns.com. KEY FACT:
	// a successful activation DELETES the pasted subscription key and keeps
	// an activation TOKEN instead ("old key is no longer needed since 5.7"):
	// acp_activation_key (token) + acp_subscription_details {status:
	// active|cancelled|expired, renewal_method, expiry_date: unix ts, null =
	// lifetime} + acp_subscription_details_key (the token the details belong
	// to). acp_subscription_key only exists pre-activation; ACP_LICENCE
	// constant overrides. Network installs use site options (checked second).
	$providers['admin-columns-pro'] = array(
		'name'      => 'Admin Columns Pro',
		'component' => 'admin-columns-pro/admin-columns-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'admin-columns-pro/admin-columns-pro.php' );
		},
		'read'      => function () use ( $item ) {
			$get = function ( $k ) {
				$v = get_option( $k );
				return false === $v ? get_site_option( $k ) : $v;
			};
			$act_key = (string) $get( 'acp_activation_key' );
			$sub_key = ( defined( 'ACP_LICENCE' ) && ACP_LICENCE ) ? (string) ACP_LICENCE : (string) $get( 'acp_subscription_key' );
			$token   = '' !== $act_key ? $act_key : $sub_key;
			if ( '' === $token ) {
				return array( $item( array( 'name' => 'Admin Columns Pro', 'state' => 'missing' ) ) );
			}
			$details     = $get( 'acp_subscription_details' );
			$details_key = (string) $get( 'acp_subscription_details_key' );
			if ( ! is_array( $details ) || $details_key !== $token || empty( $details['status'] ) ) {
				return array( $item( array( 'name' => 'Admin Columns Pro', 'state' => 'unknown', 'key' => true, 'stale' => true, 'note' => __( 'key stored; no subscription details recorded yet', 'minn-admin' ) ) ) );
			}
			$status  = strtolower( (string) $details['status'] );
			$ts      = isset( $details['expiry_date'] ) ? $details['expiry_date'] : null;
			$expires = ( null === $ts || '' === $ts ) ? 'lifetime' : minn_admin_license_expiry( gmdate( 'Y-m-d', (int) $ts ) );
			$lapsed  = minn_admin_license_expired( $expires );
			if ( 'active' === $status ) {
				$state = $lapsed ? 'expired' : 'valid';
				$note  = '';
			} elseif ( 'cancelled' === $status ) {
				// Cancelled = no renewal; access runs until the expiry date.
				$state = $lapsed ? 'expired' : 'valid';
				$note  = 'cancelled; does not renew';
			} elseif ( 'expired' === $status ) {
				$state = 'expired';
				$note  = '';
			} else {
				$state = 'invalid';
				$note  = $status;
			}
			return array( $item( array( 'name' => 'Admin Columns Pro', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $note ) ) );
		},
	);

	// Gravity Perks: key in the gwp_settings SITE option (GPERKS_LICENSE_KEY
	// constant overrides); validity cached in a VERSION-SUFFIXED 12-hour
	// site transient gwp_license_data_{version}, so the version comes from
	// the installed plugin header when the plugin is inactive.
	$providers['gravityperks'] = array(
		'name'      => 'Gravity Perks',
		'component' => 'gravityperks/gravityperks.php',
		'detect'    => function () use ( $has ) {
			return $has( 'gravityperks/gravityperks.php' );
		},
		'read'      => function () use ( $item, $plugins ) {
			$s   = get_site_option( 'gwp_settings' );
			$key = ( defined( 'GPERKS_LICENSE_KEY' ) && GPERKS_LICENSE_KEY ) ? GPERKS_LICENSE_KEY : ( is_array( $s ) && ! empty( $s['license_key'] ) ? $s['license_key'] : '' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'Gravity Perks', 'state' => 'missing' ) ) );
			}
			$ver  = defined( 'GRAVITY_PERKS_VERSION' ) ? GRAVITY_PERKS_VERSION : ( isset( $plugins['gravityperks/gravityperks.php']['Version'] ) ? $plugins['gravityperks/gravityperks.php']['Version'] : '' );
			$data = $ver ? get_site_transient( 'gwp_license_data_' . $ver ) : false;
			if ( ! is_array( $data ) || ! isset( $data['valid'] ) ) {
				return array( $item( array( 'name' => 'Gravity Perks', 'state' => 'unknown', 'key' => true, 'stale' => true, 'note' => __( 'Gravity Perks re-checks every 12 hours; no cached status', 'minn-admin' ) ) ) );
			}
			$expires = minn_admin_license_expiry( isset( $data['expires'] ) ? $data['expires'] : '' );
			if ( ! empty( $data['valid'] ) ) {
				$state = minn_admin_license_expired( $expires ) ? 'expired' : 'valid';
				$note  = '';
			} else {
				$word  = isset( $data['license'] ) ? (string) $data['license'] : '';
				$state = 'expired' === $word ? 'expired' : 'invalid';
				$note  = $word ? str_replace( '_', ' ', $word ) : '';
			}
			return array( $item( array( 'name' => 'Gravity Perks', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $note ) ) );
		},
	);

	// Rank Math SEO PRO: the paid plugin rides the FREE plugin's
	// rankmath.com account connection (option rank_math_connect_data,
	// written by the free plugin's portal handshake). No key paste exists
	// anywhere; the activate_url below links to the registration screen.
	$providers['rank-math-pro'] = array(
		'name'      => 'Rank Math SEO PRO',
		'component' => 'seo-by-rank-math-pro/rank-math-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'seo-by-rank-math-pro/rank-math-pro.php' );
		},
		'read'      => function () use ( $item ) {
			$d = get_option( 'rank_math_connect_data' );
			if ( ! is_array( $d ) || empty( $d['api_key'] ) || empty( $d['username'] ) ) {
				return array( $item( array( 'name' => 'Rank Math SEO PRO', 'state' => 'missing', 'note' => __( 'connects through a rankmath.com account', 'minn-admin' ) ) ) );
			}
			$plan = ! empty( $d['plan'] ) ? (string) $d['plan'] : 'pro';
			return array( $item( array( 'name' => 'Rank Math SEO PRO', 'state' => 'valid', 'key' => true, 'note' => __( 'connected; plan: ', 'minn-admin' ) . $plan ) ) );
		},
	);

	// Yoast SEO Premium: MyYoast portal handshake owned by free wordpress-seo
	// (WPSEO_Addon_Manager + wpseo_site_information transient). There is no
	// paste-a-key path; activation is "Activate ↗" to Yoast's Licenses page.
	// Validity = has_valid_subscription( PREMIUM_SLUG ) against MyYoast
	// site data (expiry rides the subscription product payload).
	$providers['yoast-seo-premium'] = array(
		'name'      => 'Yoast SEO Premium',
		'component' => 'wordpress-seo-premium/wp-seo-premium.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wordpress-seo-premium/wp-seo-premium.php' );
		},
		'read'      => function () use ( $item, $has ) {
			if ( ! $has( 'wordpress-seo/wp-seo.php' ) ) {
				return array( $item( array(
					'name'  => 'Yoast SEO Premium',
					'state' => 'missing',
					'note'  => __( 'requires free Yoast SEO (wordpress-seo)', 'minn-admin' ),
				) ) );
			}
			if ( ! class_exists( 'WPSEO_Addon_Manager' ) ) {
				// Free plugin installed but not loaded (inactive).
				return array( $item( array(
					'name'  => 'Yoast SEO Premium',
					'state' => 'unknown',
					'note'  => __( 'activate free Yoast SEO to read MyYoast subscription state', 'minn-admin' ),
				) ) );
			}
			try {
				$mgr  = new WPSEO_Addon_Manager();
				$slug = WPSEO_Addon_Manager::PREMIUM_SLUG;
				$sub  = $mgr->get_subscription( $slug );
				if ( ! $sub ) {
					return array( $item( array(
						'name'  => 'Yoast SEO Premium',
						'state' => 'missing',
						'note'  => __( 'activate the subscription on MyYoast', 'minn-admin' ),
					) ) );
				}
				// map_subscription() stores expiry as expiry_date (from API expiryDate).
				$expires = '';
				if ( is_object( $sub ) && ! empty( $sub->expiry_date ) ) {
					$expires = minn_admin_license_expiry( $sub->expiry_date );
				}
				if ( $mgr->has_valid_subscription( $slug ) ) {
					return array( $item( array(
						'name'    => 'Yoast SEO Premium',
						'state'   => 'valid',
						'key'     => true,
						'expires' => $expires,
						'note'    => __( 'MyYoast subscription', 'minn-admin' ),
					) ) );
				}
				$state = minn_admin_license_expired( $expires ) ? 'expired' : 'invalid';
				return array( $item( array(
					'name'    => 'Yoast SEO Premium',
					'state'   => $state,
					'key'     => true,
					'expires' => $expires,
					'note'    => __( 'MyYoast subscription inactive or expired', 'minn-admin' ),
				) ) );
			} catch ( \Throwable $e ) {
				return array( $item( array(
					'name'  => 'Yoast SEO Premium',
					'state' => 'unknown',
					'note'  => __( 'could not read MyYoast subscription state', 'minn-admin' ),
				) ) );
			}
		},
	);

	// WP All Import Pro (Soflyy): everything inside the serialized
	// PMXI_Plugin_Options blob — licenses/statuses are CLASS-KEYED maps and
	// the key is stored base64-wrapped in a site salt (their server strips
	// the wrapper; the whole vendor flow sends it wrapped). 'active' is
	// what their activate flow stores for a good license. No expiry stored.
	$providers['wp-all-import'] = array(
		'name'      => 'WP All Import Pro',
		'component' => 'wp-all-import-pro/wp-all-import-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wp-all-import-pro/wp-all-import-pro.php' );
		},
		'read'      => function () use ( $item, $edd_state ) {
			$o   = get_option( 'PMXI_Plugin_Options' );
			$key = is_array( $o ) && ! empty( $o['licenses']['PMXI_Plugin'] );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'WP All Import Pro', 'state' => 'missing' ) ) );
			}
			list( $state, $note ) = $edd_state( isset( $o['statuses']['PMXI_Plugin'] ) ? $o['statuses']['PMXI_Plugin'] : '' );
			return array( $item( array( 'name' => 'WP All Import Pro', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// WP All Export Pro (Soflyy): same family, but FLAT license /
	// license_status fields in PMXE_Plugin_Options.
	$providers['wp-all-export'] = array(
		'name'      => 'WP All Export Pro',
		'component' => 'wp-all-export-pro/wp-all-export-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wp-all-export-pro/wp-all-export-pro.php' );
		},
		'read'      => function () use ( $item, $edd_state ) {
			$o   = get_option( 'PMXE_Plugin_Options' );
			$key = is_array( $o ) && ! empty( $o['license'] );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'WP All Export Pro', 'state' => 'missing' ) ) );
			}
			list( $state, $note ) = $edd_state( isset( $o['license_status'] ) ? $o['license_status'] : '' );
			return array( $item( array( 'name' => 'WP All Export Pro', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// Perfmatters: plain option pair (site options on multisite). Current
	// builds replaced the bundled EDD updater with a hand-rolled client, so
	// the generic EDD fingerprint no longer sees it (older builds matched);
	// this dedicated reader is the coverage now. No expiry is stored.
	$providers['perfmatters'] = array(
		'name'      => 'Perfmatters',
		'component' => 'perfmatters/perfmatters.php',
		'detect'    => function () use ( $has ) {
			return $has( 'perfmatters/perfmatters.php' );
		},
		'read'      => function () use ( $item, $edd_state ) {
			$get = is_multisite() ? 'get_site_option' : 'get_option';
			$key = ( defined( 'PERFMATTERS_LICENSE_KEY' ) && PERFMATTERS_LICENSE_KEY ) ? PERFMATTERS_LICENSE_KEY : call_user_func( $get, 'perfmatters_edd_license_key' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'Perfmatters', 'state' => 'missing' ) ) );
			}
			list( $state, $note ) = $edd_state( call_user_func( $get, 'perfmatters_edd_license_status' ) );
			return array( $item( array( 'name' => 'Perfmatters', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// Automatic.css: a stock EDD client behind a $_POST-driven settings
	// form. The option pair is automatic_css_license_key / _status (the key
	// is stored raw; the XXXX… masking on their screen is display-only),
	// which the generic EDD sweep can never find: the slug tokenizes to
	// automaticcss_plugin while the options say automatic_css — so the
	// sweep answered "missing" for a site holding a valid license. No
	// expiry is stored locally.
	$providers['automatic-css'] = array(
		'name'      => 'Automatic.css',
		'component' => 'automaticcss-plugin/automaticcss-plugin.php',
		'detect'    => function () use ( $has ) {
			return $has( 'automaticcss-plugin/automaticcss-plugin.php' );
		},
		'read'      => function () use ( $item, $edd_state ) {
			$key = trim( (string) get_option( 'automatic_css_license_key' ) );
			if ( '' === $key ) {
				return array( $item( array( 'name' => 'Automatic.css', 'state' => 'missing' ) ) );
			}
			list( $state, $note ) = $edd_state( get_option( 'automatic_css_license_status' ) );
			return array( $item( array( 'name' => 'Automatic.css', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// GeneratePress Premium: its option names break BOTH generic-sweep
	// assumptions (prefix gen_premium vs slug gp-premium, and the status
	// option is ..._license_key_status), so it gets a dedicated reader.
	// No expiry is stored.
	$providers['gp-premium'] = array(
		'name'      => 'GP Premium',
		'component' => 'gp-premium/gp-premium.php',
		'detect'    => function () use ( $has ) {
			return $has( 'gp-premium/gp-premium.php' );
		},
		'read'      => function () use ( $item, $edd_state ) {
			$key = get_option( 'gen_premium_license_key' );
			if ( ! $key ) {
				return array( $item( array( 'name' => 'GP Premium', 'state' => 'missing' ) ) );
			}
			list( $state, $note ) = $edd_state( get_option( 'gen_premium_license_key_status' ) );
			return array( $item( array( 'name' => 'GP Premium', 'state' => $state, 'key' => true, 'note' => $note ) ) );
		},
	);

	// Slider Revolution (ThemePunch): flat options; validity is the STRING
	// 'true'/'false' in revslider-valid, and a remote deregistration leaves
	// its reason in revslider-deregister-message.
	$providers['revslider'] = array(
		'name'      => 'Slider Revolution',
		'component' => 'revslider/revslider.php',
		'detect'    => function () use ( $has ) {
			return $has( 'revslider/revslider.php' );
		},
		'read'      => function () use ( $item ) {
			$code = get_option( 'revslider-code', '' );
			if ( ! $code ) {
				return array( $item( array( 'name' => 'Slider Revolution', 'state' => 'missing' ) ) );
			}
			if ( 'true' === get_option( 'revslider-valid', 'false' ) ) {
				return array( $item( array( 'name' => 'Slider Revolution', 'state' => 'valid', 'key' => true ) ) );
			}
			$why = wp_strip_all_tags( (string) get_option( 'revslider-deregister-message', '' ) );
			return array( $item( array( 'name' => 'Slider Revolution', 'state' => 'invalid', 'key' => true, 'note' => $why ? substr( $why, 0, 120 ) : __( 'code stored but not registered', 'minn-admin' ) ) ) );
		},
	);

	// LayerSlider (Kreatura): purchase code + a server-issued activation
	// id; layerslider-authorized-site is the 1/0 validity flag. A remote
	// cancellation zeroes it and stamps ls-show-canceled_activation_notice.
	$providers['layerslider'] = array(
		'name'      => 'LayerSlider',
		'component' => 'LayerSlider/layerslider.php',
		'detect'    => function () use ( $has ) {
			return $has( 'LayerSlider/layerslider.php' );
		},
		'read'      => function () use ( $item ) {
			$code = get_option( 'layerslider-purchase-code', '' );
			if ( ! $code ) {
				return array( $item( array( 'name' => 'LayerSlider', 'state' => 'missing' ) ) );
			}
			if ( get_option( 'layerslider-authorized-site' ) ) {
				return array( $item( array( 'name' => 'LayerSlider', 'state' => 'valid', 'key' => true ) ) );
			}
			$canceled = get_option( 'ls-show-canceled_activation_notice' );
			return array( $item( array( 'name' => 'LayerSlider', 'state' => 'invalid', 'key' => true, 'note' => $canceled ? __( 'activation was canceled remotely', 'minn-admin' ) : __( 'code stored but the site is not authorized', 'minn-admin' ) ) ) );
		},
	);

	// Envato Market: an account OAuth token (presence-only; Envato records
	// no local validity) plus optional per-item single-use tokens that DO
	// carry an authorized success/failed flag. Purchased-item counts ride
	// the plugin's own hourly site transients when warm.
	$providers['envato-market'] = array(
		'name'      => 'Envato Market',
		'category'  => 'connection',
		'component' => 'envato-market/envato-market.php',
		'detect'    => function () use ( $has ) {
			return $has( 'envato-market/envato-market.php' );
		},
		'read'      => function () use ( $item ) {
			$o = get_option( 'envato_market' );
			if ( ( ! is_array( $o ) || ! $o ) && is_multisite() ) {
				$o = get_site_option( 'envato_market' );
			}
			$o     = is_array( $o ) ? $o : array();
			$items = ( ! empty( $o['items'] ) && is_array( $o['items'] ) ) ? $o['items'] : array();
			$rows  = array();
			if ( empty( $o['token'] ) && ! $items ) {
				return array( $item( array( 'name' => 'Envato Market', 'state' => 'missing', 'note' => __( 'no account token or item tokens stored', 'minn-admin' ) ) ) );
			}
			if ( ! empty( $o['token'] ) ) {
				$counts = array();
				foreach ( array( 'themes' => 'envato_market_themes', 'plugins' => 'envato_market_plugins' ) as $label => $tr ) {
					$t = get_site_transient( $tr );
					if ( is_array( $t ) && isset( $t['purchased'] ) && is_array( $t['purchased'] ) && $t['purchased'] ) {
						$counts[] = count( $t['purchased'] ) . ' ' . $label;
					}
				}
				$note   = $counts ? 'covers ' . implode( ' + ', $counts ) . ' purchased' : __( 'Envato keeps no local validity record', 'minn-admin' );
				$rows[] = $item( array( 'name' => __( 'Envato Market account token', 'minn-admin' ), 'state' => 'unknown', 'key' => true, 'note' => $note ) );
			}
			foreach ( $items as $it ) {
				if ( ! is_array( $it ) || empty( $it['name'] ) ) {
					continue;
				}
				$auth  = isset( $it['authorized'] ) ? (string) $it['authorized'] : '';
				$state = 'success' === $auth ? 'valid' : ( 'failed' === $auth ? 'invalid' : 'unknown' );
				$rows[] = $item( array( 'name' => (string) $it['name'], 'kind' => ( isset( $it['type'] ) && 'theme' === $it['type'] ) ? 'theme' : 'plugin', 'state' => $state, 'key' => ! empty( $it['token'] ), 'note' => __( 'Envato single-item token', 'minn-admin' ) ) );
			}
			return $rows;
		},
	);

	// Avada (theme): fusion_registration_data['avada'] carries the purchase
	// code and a STRICT is_valid flag; one code covers the bundled Avada
	// plugins. Its store paths are nonce- or WP-CLI-coupled, so the row is
	// read-only for now (the Bricks-until-lab precedent).
	$providers['avada'] = array(
		'name'      => 'Avada',
		'component' => 'theme:Avada',
		'detect'    => function () use ( $has_theme ) {
			return $has_theme( 'Avada' );
		},
		'read'      => function () use ( $item ) {
			$d = get_option( 'fusion_registration_data' );
			$a = ( is_array( $d ) && isset( $d['avada'] ) && is_array( $d['avada'] ) ) ? $d['avada'] : array();
			if ( empty( $a['purchase_code'] ) && empty( $a['token'] ) ) {
				return array( $item( array( 'name' => 'Avada', 'kind' => 'theme', 'state' => 'missing' ) ) );
			}
			if ( true === ( isset( $a['is_valid'] ) ? $a['is_valid'] : false ) ) {
				return array( $item( array( 'name' => 'Avada', 'kind' => 'theme', 'state' => 'valid', 'key' => true, 'note' => __( 'covers the bundled Avada plugins', 'minn-admin' ) ) ) );
			}
			$err = ! empty( $a['errors'] ) ? wp_strip_all_tags( (string) $a['errors'] ) : '';
			return array( $item( array( 'name' => 'Avada', 'kind' => 'theme', 'state' => 'invalid', 'key' => true, 'note' => $err ? substr( $err, 0, 120 ) : '' ) ) );
		},
	);

	// The Events Calendar family: DEDICATED per-product providers with full
	// activate/deactivate/verify (below, Phase 1). PUE stores the key in
	// pue_install_key_{slug with underscores} and the recorded status in
	// pue_key_status_{dashed slug}_{domain} (plus a _timeout sibling); Event
	// Tickets Plus ALSO registers a StellarWP Uplink resource, whose stored
	// key wins inside their checker. minn_admin_license_tec_products() is the
	// single source for slugs/files so the registry reader below can skip
	// what these claim.
	foreach ( minn_admin_license_tec_products() as $pid => $tp ) {
		$providers[ $pid ] = array(
			'name'      => $tp['name'],
			'component' => $tp['file'],
			'detect'    => function () use ( $has, $tp ) {
				return $has( $tp['file'] );
			},
			'read'      => function () use ( $item, $tp ) {
				global $wpdb;
				$key = trim( (string) get_option( 'pue_install_key_' . str_replace( '-', '_', $tp['slug'] ), '' ) );
				if ( '' === $key ) {
					// Uplink-registered products (Event Tickets Plus) may hold
					// the key in the uplink option instead.
					$key = trim( (string) get_option( 'stellarwp_uplink_license_key_' . $tp['slug'], '' ) );
				}
				// Recorded status: 'valid' | 'invalid', domain-suffixed. The
				// _timeout sibling row must not match.
				$status = $wpdb->get_var( $wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s LIMIT 1",
					$wpdb->esc_like( 'pue_key_status_' . $tp['slug'] . '_' ) . '%',
					'%' . $wpdb->esc_like( '_timeout' )
				) );
				if ( '' === $key ) {
					$state = 'missing';
				} elseif ( null === $status ) {
					$state = 'unknown';
				} else {
					$state = ( 'valid' === $status ) ? 'valid' : 'invalid';
				}
				return array( $item( array(
					'name'  => $tp['name'],
					'state' => $state,
					'key'   => '' !== $key,
					'note'  => ( '' !== $key && null === $status ) ? 'key stored; not validated yet' : '',
				) ) );
			},
		);
	}

	// Kadence Blocks Pro: StellarWP Uplink under the free kadence-blocks
	// plugin's vendor namespace, slug 'kadence-blocks-pro'. The purchaser's
	// key SHIPS INSIDE THE PLUGIN BUILD (includes/uplink/Helper.php DATA
	// constant, the WP Rocket pattern), so a site can be fully licensed with
	// no key option stored; the uplink option overrides the file key.
	$providers['kadence-blocks-pro'] = array(
		'name'      => 'Kadence Blocks Pro',
		'component' => 'kadence-blocks-pro/kadence-blocks-pro.php',
		'detect'    => function () use ( $has ) {
			return $has( 'kadence-blocks-pro/kadence-blocks-pro.php' );
		},
		'read'      => function () use ( $item ) {
			global $wpdb;
			$key  = trim( (string) get_option( 'stellarwp_uplink_license_key_kadence-blocks-pro', '' ) );
			$note = '';
			if ( '' === $key && function_exists( '\KadenceWP\KadenceBlocks\StellarWP\Uplink\get_license_key' ) ) {
				try {
					$key = trim( (string) \KadenceWP\KadenceBlocks\StellarWP\Uplink\get_license_key( 'kadence-blocks-pro' ) );
					if ( '' !== $key ) {
						$note = __( 'key ships inside the plugin build', 'minn-admin' );
					}
				} catch ( \Throwable $e ) { /* stays missing */ }
			}
			$status = $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
				$wpdb->esc_like( 'stellarwp_uplink_license_key_status_kadence-blocks-pro_' ) . '%'
			) );
			if ( '' === $key ) {
				$state = 'missing';
			} elseif ( null === $status ) {
				$state = 'unknown';
			} else {
				$state = ( 'valid' === $status ) ? 'valid' : 'invalid';
			}
			return array( $item( array(
				'name'  => 'Kadence Blocks Pro',
				'state' => $state,
				'key'   => '' !== $key,
				'note'  => $note,
			) ) );
		},
	);

	// Smash Balloon Pro family: dedicated per-product providers (EDD on
	// smashballoon.com). Covers Instagram / Facebook / YouTube / Twitter /
	// Social Wall / Reviews / TikTok / Feed Analytics. An "All Plugins"
	// license activates each product with that product's own EDD item_name.
	// Actions attach only while the product is loaded (item_const defined).
	foreach ( minn_admin_license_smash_products() as $pid => $sp ) {
		$providers[ $pid ] = array(
			'name'      => $sp['name'],
			'component' => $sp['file'],
			'detect'    => function () use ( $has, $sp ) {
				return $has( $sp['file'] );
			},
			'read'      => function () use ( $item, $sp, $edd_state ) {
				$store = minn_admin_smash_read_store( $sp );
				if ( '' === $store['key'] ) {
					return array( $item( array( 'name' => $sp['name'], 'state' => 'missing' ) ) );
				}
				list( $state, $note ) = $edd_state( $store['status'] );
				// inactive / empty status with a stored key = not activated yet
				if ( in_array( $store['status'], array( '', 'inactive', 'deactivated' ), true ) ) {
					$state = 'invalid';
					$note  = $note ? $note : __( 'key stored but not activated on this site', 'minn-admin' );
				}
				$expires = minn_admin_license_expiry( $store['expires'] );
				if ( minn_admin_license_expired( $expires ) && 'valid' === $state ) {
					$state = 'expired';
				}
				return array( $item( array(
					'name'    => $sp['name'],
					'state'   => $state,
					'key'     => true,
					'expires' => $expires,
					'note'    => $note,
				) ) );
			},
		);
	}

	// Anything ELSE speaking PUE or Uplink, registry-style: presence-only for
	// PUE (no local validity), status-classified for Uplink. Slugs the
	// dedicated providers above claim are skipped.
	$providers['stellarwp'] = array(
		'name'      => 'StellarWP / The Events Calendar',
		'component' => 'stellarwp-registry',
		'detect'    => function () use ( $has ) {
			global $wpdb;
			if ( $has( 'events-calendar-pro/events-calendar-pro.php' ) ) {
				return true;
			}
			return (bool) $wpdb->get_var( "SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE 'pue\\_install\\_key\\_%' OR option_name LIKE 'stellarwp\\_uplink\\_license\\_key\\_%' LIMIT 1" );
		},
		'read'      => function () use ( $item, $has ) {
			global $wpdb;
			$rows = array();
			// Claimed by the dedicated providers above.
			$claimed_pue    = array();
			$claimed_uplink = array( 'kadence-blocks-pro' );
			foreach ( minn_admin_license_tec_products() as $tp ) {
				$claimed_pue[]    = str_replace( '-', '_', $tp['slug'] );
				$claimed_uplink[] = $tp['slug'];
			}
			foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'pue\\_install\\_key\\_%'" ) as $row ) {
				$slug = substr( $row->option_name, strlen( 'pue_install_key_' ) );
				if ( in_array( $slug, $claimed_pue, true ) ) {
					continue;
				}
				$name = ucwords( str_replace( '_', ' ', $slug ) );
				$key  = '' !== trim( (string) $row->option_value );
				$rows[] = $item( array(
					'name'  => $name,
					'state' => $key ? 'unknown' : 'missing',
					'key'   => $key,
					'note'  => $key ? 'key stored; validity is checked on The Events Calendar licenses screen' : '',
				) );
			}
			foreach ( (array) $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'stellarwp\\_uplink\\_license\\_key\\_%' AND option_name NOT LIKE 'stellarwp\\_uplink\\_license\\_key\\_status\\_%'" ) as $row ) {
				$slug   = substr( $row->option_name, strlen( 'stellarwp_uplink_license_key_' ) );
				if ( in_array( $slug, $claimed_uplink, true ) ) {
					continue;
				}
				$key    = '' !== trim( (string) $row->option_value );
				$status = $wpdb->get_var( $wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1",
					$wpdb->esc_like( 'stellarwp_uplink_license_key_status_' . $slug . '_' ) . '%'
				) );
				if ( ! $key ) {
					$state = 'missing';
				} elseif ( null === $status ) {
					$state = 'unknown';
				} else {
					$state = ( 'valid' === $status ) ? 'valid' : 'invalid';
				}
				$rows[] = $item( array(
					'name'  => ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ),
					'state' => $state,
					'key'   => $key,
					'note'  => ( $key && null === $status ) ? 'key stored; Uplink has not recorded a status yet' : '',
				) );
			}
			return $rows;
		},
	);

	// ----- Phase 1 actions -------------------------------------------------
	// Attached only while the vendor's own code is LOADED (active plugin/
	// theme), so the client never draws a control that cannot work. Every
	// action routes through the vendor's own activation path; Minn never
	// reimplements a vendor HTTP call and never retries a failure.

	// Slider Revolution: RevSliderLicense::activate_plugin($code) against the
	// ThemePunch servers returns true (stored + valid), 'exist' (the code is
	// registered to another site: a real seat-limit answer), 'banned', or
	// false. The license class and its RevSliderTracking dependency only load
	// in wp-admin, so they're included manually here (the load balancer is
	// lazily bound by RevSliderGlobals either way). NOTE: an unregistered
	// Slider Revolution cannot download updates at all (their update request
	// only carries the purchase code when revslider-valid is 'true'), so
	// activation is also what unblocks their "Activate To Update" screen.
	if ( class_exists( 'RevSliderGlobals' ) && defined( 'RS_PLUGIN_PATH' ) ) {
		$revslider_license = function () {
			foreach ( array( 'admin/includes/license.class.php', 'admin/includes/tracking.class.php', 'admin/includes/loadbalancer.class.php' ) as $inc ) {
				if ( file_exists( RS_PLUGIN_PATH . $inc ) ) {
					include_once RS_PLUGIN_PATH . $inc;
				}
			}
			return class_exists( 'RevSliderLicense' ) ? new RevSliderLicense() : null;
		};
		$providers['revslider']['secret_label'] = __( 'Purchase code', 'minn-admin' );
		$providers['revslider']['activate']     = function ( $secret ) use ( $revslider_license ) {
			$lic = $revslider_license();
			if ( ! $lic ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Slider Revolution\'s license class could not be loaded.', 'minn-admin' ) );
			}
			$res = $lic->activate_plugin( trim( (string) $secret ) );
			if ( true === $res ) {
				return array( 'ok' => true, 'code' => '', 'message' => '' );
			}
			if ( 'exist' === $res ) {
				return array( 'ok' => false, 'code' => 'site_limit', 'message' => __( 'This purchase code is already registered to another site. Deregister it there (or in your ThemePunch account) first.', 'minn-admin' ) );
			}
			if ( 'banned' === $res ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'ThemePunch reports this purchase code as banned.', 'minn-admin' ) );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'ThemePunch did not accept this purchase code.', 'minn-admin' ) );
		};
		$providers['revslider']['deactivate']   = function () use ( $revslider_license ) {
			$lic = $revslider_license();
			if ( ! $lic ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Slider Revolution\'s license class could not be loaded.', 'minn-admin' ) );
			}
			// Their own deregistration flow: frees the seat server-side.
			return (bool) $lic->deactivate_plugin()
				? array( 'ok' => true, 'code' => '', 'message' => __( 'The purchase code was deregistered with ThemePunch and the seat freed.', 'minn-admin' ) )
				: array( 'ok' => false, 'code' => 'error', 'message' => __( 'ThemePunch did not confirm the deregistration.', 'minn-admin' ) );
		};
		$providers['revslider']['verify']       = function () use ( $revslider_license ) {
			$code = trim( (string) get_option( 'revslider-code', '' ) );
			if ( '' === $code ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No purchase code is stored.', 'minn-admin' ) );
			}
			$lic = $revslider_license();
			if ( ! $lic ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Slider Revolution\'s license class could not be loaded.', 'minn-admin' ) );
			}
			// Re-activating the stored code is their own revalidation path;
			// 'exist' here means the registration moved to another site.
			$res = $lic->activate_plugin( $code );
			if ( true === $res ) {
				return array( 'ok' => true, 'code' => '', 'message' => '' );
			}
			return array( 'ok' => false, 'code' => 'exist' === $res ? 'site_limit' : 'invalid', 'message' => __( 'exist', 'minn-admin' ) === $res ? 'The stored code is now registered to another site.' : 'ThemePunch no longer accepts the stored code.' );
		};
	}

	// The Events Calendar family: everything through Tribe__PUE__Checker,
	// constructed with each plugin's own args. validate_key() is their
	// COMPLETE flow (PUE service call, key persisted only when accepted,
	// status + domain-suffixed options recorded). Response: status >= 1 is
	// valid; api_expired / api_invalid classify failures, and an api_invalid
	// message naming the install limit maps to site_limit. GOTCHA: for a
	// product with an Uplink resource (Event Tickets Plus), validate_key
	// IGNORES its argument and reads the resource's stored key, so activate
	// seeds the resource first and rolls it back if the service says no.
	if ( class_exists( 'Tribe__PUE__Checker' ) ) {
		$tec_checker  = function ( $tp ) {
			return new Tribe__PUE__Checker( $tp['url'], $tp['slug'], array(), $tp['file'] );
		};
		$tec_classify = function ( $res ) {
			if ( ! is_array( $res ) || empty( $res ) ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The key validation service did not answer.', 'minn-admin' ) );
			}
			$msg = isset( $res['message'] ) ? trim( wp_strip_all_tags( (string) $res['message'] ) ) : '';
			if ( ! empty( $res['status'] ) ) {
				return array( 'ok' => true, 'code' => '', 'message' => $msg );
			}
			$code = ! empty( $res['api_expired'] ) ? 'expired' : 'invalid';
			if ( false !== stripos( $msg, 'limit' ) || false !== stripos( $msg, 'installation' ) ) {
				$code = 'site_limit';
			}
			return array( 'ok' => false, 'code' => $code, 'message' => '' !== $msg ? $msg : __( 'The key was not accepted.', 'minn-admin' ) );
		};
		foreach ( minn_admin_license_tec_products() as $pid => $tp ) {
			if ( ! class_exists( $tp['class'] ) || ! isset( $providers[ $pid ] ) ) {
				continue;
			}
			/* translators: %s: name of the licensed product. */
			$providers[ $pid ]['secret_label'] = sprintf( __( '%s license key', 'minn-admin' ), $tp['name'] );
			$providers[ $pid ]['activate']     = function ( $secret ) use ( $tp, $tec_checker, $tec_classify ) {
				$checker  = $tec_checker( $tp );
				$resource = $checker->get_uplink_resource( $tp['slug'] );
				$prev     = $resource ? (string) $resource->get_license_key() : null;
				if ( $resource ) {
					$resource->set_license_key( $secret, 'local' );
				}
				$out = $tec_classify( $checker->validate_key( (string) $secret ) );
				if ( ! $out['ok'] && $resource ) {
					// Roll the seeded resource key back; the PUE option was
					// never written (validate_key stores only on success).
					$resource->set_license_key( (string) $prev, 'local' );
				}
				return $out;
			};
			$providers[ $pid ]['deactivate']   = function () use ( $tp, $tec_checker ) {
				global $wpdb;
				$checker = $tec_checker( $tp );
				delete_option( 'pue_install_key_' . str_replace( '-', '_', $tp['slug'] ) );
				$resource = $checker->get_uplink_resource( $tp['slug'] );
				if ( $resource ) {
					$resource->set_license_key( '', 'local' );
				}
				// Recorded statuses: the domain-suffixed option, its _timeout
				// sibling and the md5 transient (all named by the checker).
				$names = $wpdb->get_col( $wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'pue_key_status_' . $tp['slug'] . '_' ) . '%'
				) );
				foreach ( $names as $name ) {
					delete_option( $name );
				}
				if ( ! empty( $checker->pue_key_status_transient_name ) ) {
					delete_transient( $checker->pue_key_status_transient_name );
				}
				// Builds downloaded from a TEC account can EMBED the key (a
				// PUE Helper / Uplink KeyFactory constant); their checker
				// re-seeds the option from it on the next load, so removal
				// can only be temporary for those. Say so.
				$embedded = '';
				try {
					$embedded = (string) $checker->get_key( 'default' );
				} catch ( \Throwable $e ) { /* fine */ }
				return array(
					'ok'      => true,
					'message' => '' !== $embedded
						? __( 'The stored key was cleared, but this plugin build ships with the key embedded and will re-register it. Manage licensed domains on theeventscalendar.com.', 'minn-admin' )
						: __( 'The key was removed from this site. Manage its licensed domains on theeventscalendar.com.', 'minn-admin' ),
				);
			};
			$providers[ $pid ]['verify']       = function () use ( $tp, $tec_checker, $tec_classify ) {
				$checker = $tec_checker( $tp );
				$key     = (string) $checker->get_key();
				if ( '' === $key ) {
					return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No key is stored for this product.', 'minn-admin' ) );
				}
				return $tec_classify( $checker->validate_key( $key ) );
			};
		}
	}

	// Kadence Blocks Pro: StellarWP Uplink primitives under the free
	// kadence-blocks vendor namespace. validate_license() records the status
	// itself; the pasted key is stored first (uplink validates the STORED
	// key) and rolled back with its status if the service rejects it.
	// Deactivate mirrors their own Clear button (the option goes away; a key
	// baked into the plugin build remains as the fallback).
	if ( defined( 'KBP_VERSION' ) && function_exists( '\KadenceWP\KadenceBlocks\StellarWP\Uplink\validate_license' ) ) {
		$kbp_snapshot = function () {
			global $wpdb;
			return array(
				'key'    => get_option( 'stellarwp_uplink_license_key_kadence-blocks-pro' ),
				'status' => $wpdb->get_results( $wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( 'stellarwp_uplink_license_key_status_kadence-blocks-pro_' ) . '%'
				), ARRAY_A ),
			);
		};
		$kbp_restore  = function ( $snap ) {
			if ( false === $snap['key'] ) {
				delete_option( 'stellarwp_uplink_license_key_kadence-blocks-pro' );
			} else {
				update_option( 'stellarwp_uplink_license_key_kadence-blocks-pro', $snap['key'] );
			}
			foreach ( (array) $snap['status'] as $row ) {
				update_option( $row['option_name'], $row['option_value'] );
			}
		};
		$kbp_classify = function ( $res ) {
			if ( ! $res ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The Kadence licensing service did not answer.', 'minn-admin' ) );
			}
			if ( $res->is_valid() ) {
				return array( 'ok' => true, 'code' => '', 'message' => '' );
			}
			$result = (string) $res->get_result();
			$code   = 'expired' === $result ? 'expired' : ( 'unreachable' === $result ? 'error' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => __( 'unreachable', 'minn-admin' ) === $result ? 'The Kadence licensing service is unreachable.' : 'The key was not accepted (' . $result . ').' );
		};
		$providers['kadence-blocks-pro']['secret_label'] = __( 'Kadence license key', 'minn-admin' );
		$providers['kadence-blocks-pro']['activate']     = function ( $secret ) use ( $kbp_snapshot, $kbp_restore, $kbp_classify ) {
			$snap = $kbp_snapshot();
			try {
				\KadenceWP\KadenceBlocks\StellarWP\Uplink\set_license_key( 'kadence-blocks-pro', (string) $secret );
				$res = \KadenceWP\KadenceBlocks\StellarWP\Uplink\validate_license( 'kadence-blocks-pro', (string) $secret );
			} catch ( \Throwable $e ) {
				$kbp_restore( $snap );
				return array( 'ok' => false, 'code' => 'error', 'message' => $e->getMessage() );
			}
			$out = $kbp_classify( $res );
			if ( ! $out['ok'] ) {
				$kbp_restore( $snap );
			}
			return $out;
		};
		$providers['kadence-blocks-pro']['deactivate']   = function () {
			global $wpdb;
			delete_option( 'stellarwp_uplink_license_key_kadence-blocks-pro' );
			$names = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'stellarwp_uplink_license_key_status_kadence-blocks-pro_' ) . '%'
			) );
			foreach ( $names as $name ) {
				delete_option( $name );
			}
			$baked = '';
			try {
				$baked = function_exists( '\KadenceWP\KadenceBlocks\StellarWP\Uplink\get_license_key' )
					? (string) \KadenceWP\KadenceBlocks\StellarWP\Uplink\get_license_key( 'kadence-blocks-pro' )
					: '';
			} catch ( \Throwable $e ) { /* fine */ }
			return array(
				'ok'      => true,
				'message' => '' !== $baked
					? __( 'The stored key was cleared. A key baked into the plugin build remains as the fallback.', 'minn-admin' )
					: __( 'The stored key was cleared from this site.', 'minn-admin' ),
			);
		};
		$providers['kadence-blocks-pro']['verify']       = function () use ( $kbp_classify ) {
			try {
				$res = \KadenceWP\KadenceBlocks\StellarWP\Uplink\validate_license( 'kadence-blocks-pro' );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => $e->getMessage() );
			}
			return $kbp_classify( $res );
		};
	}

	// Elementor Pro: same sequence as its own ajax handler —
	// API::activate_license, then set_license_key + set_license_data on
	// success. 'no_activations_left' is the seat-limit code.
	if ( class_exists( '\ElementorPro\License\API' ) && class_exists( '\ElementorPro\License\Admin' ) ) {
		$providers['elementor-pro']['secret_label'] = __( 'Elementor Pro license key', 'minn-admin' );
		$providers['elementor-pro']['activate']     = function ( $secret ) {
			$data = \ElementorPro\License\API::activate_license( $secret );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data['success'] ) ) {
				$err  = isset( $data['error'] ) ? (string) $data['error'] : 'unknown';
				$code = 'no_activations_left' === $err ? 'site_limit' : ( 'expired' === $err ? 'expired' : 'invalid' );
				$msgs = array(
					'missing'       => __( 'Elementor does not recognize that key', 'minn-admin' ),
					'invalid'       => __( 'Elementor does not recognize that key', 'minn-admin' ),
					'expired'       => __( 'That Elementor Pro license has expired', 'minn-admin' ),
					'disabled'      => __( 'That license was disabled', 'minn-admin' ),
					'cancelled'     => __( 'That subscription was cancelled', 'minn-admin' ),
					'revoked'       => __( 'That license was revoked', 'minn-admin' ),
					'site_inactive' => __( 'That key is registered to a different domain', 'minn-admin' ),
				);
				return array( 'ok' => false, 'code' => $code, 'message' => isset( $msgs[ $err ] ) ? $msgs[ $err ] : str_replace( '_', ' ', $err ) );
			}
			\ElementorPro\License\Admin::set_license_key( $secret );
			\ElementorPro\License\API::set_license_data( $data );
			return array( 'ok' => true );
		};
		$providers['elementor-pro']['deactivate'] = function () {
			\ElementorPro\License\Admin::deactivate();
			return array( 'ok' => true );
		};
		$providers['elementor-pro']['verify'] = function () {
			$data = \ElementorPro\License\API::get_license_data( true ); // force a fresh check into their own cache
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			// Report their answer, never a blanket success: the endpoint's
			// check-memory records verify outcomes, and an unconditional ok
			// could stamp an unverified license "valid" forever.
			if ( ! is_array( $data ) || empty( $data['success'] ) ) {
				$err  = ( is_array( $data ) && isset( $data['error'] ) ) ? (string) $data['error'] : '';
				$code = ( 'expired' === $err ) ? 'expired' : 'invalid';
				return array( 'ok' => false, 'code' => $code, 'message' => '' !== $err ? str_replace( '_', ' ', $err ) : __( 'Elementor did not confirm the license', 'minn-admin' ) );
			}
			return array( 'ok' => true );
		};
	}

	// ACF Pro: their own activate/deactivate return {success, message}
	// (or WP_Error); $silent = true keeps their admin notices out of it.
	if ( function_exists( 'acf_pro_activate_license' ) ) {
		$providers['acf-pro']['secret_label'] = __( 'ACF PRO license key', 'minn-admin' );
		$providers['acf-pro']['activate']     = function ( $secret ) {
			$res = acf_pro_activate_license( $secret, true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$ok  = is_array( $res ) && ! empty( $res['success'] );
			$msg = is_array( $res ) && isset( $res['message'] ) ? wp_strip_all_tags( (string) $res['message'] ) : '';
			return array( 'ok' => $ok, 'code' => $ok ? '' : ( false !== stripos( $msg, 'site' ) && false !== stripos( $msg, 'limit' ) ? 'site_limit' : 'invalid' ), 'message' => $msg );
		};
		$providers['acf-pro']['deactivate'] = function () {
			$res = acf_pro_deactivate_license( true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$ok  = is_array( $res ) && ! empty( $res['success'] );
			$msg = is_array( $res ) && isset( $res['message'] ) ? wp_strip_all_tags( (string) $res['message'] ) : '';
			return array( 'ok' => $ok, 'message' => $msg );
		};
	}


	// WP Migrate: their activation is one API call plus a local write, which
	// is exactly what their own handler does. The API answers
	// activate_licence with an errors map; anything but an empty map means
	// the key was refused, and their handler still stores the key for the
	// three conditions where the license exists but cannot serve this site
	// (expired, api down, activation turned off), so the person is not made
	// to paste it again. That behaviour is mirrored here rather than
	// simplified, because their UI reads the same stored key afterwards.
	// There is no deactivate endpoint in their API at all, so removing is an
	// honest local removal and the message says the activation is not freed.
	if ( class_exists( '\DeliciousBrains\WPMDB\Pro\License' ) && class_exists( '\DeliciousBrains\WPMDB\WPMDBDI' ) ) {
		$wpm_license = function () {
			return \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Pro\License::class );
		};
		$wpm_api     = function () {
			return \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Pro\Api::class );
		};
		// Their site_url argument, built the way their handlers build it.
		$wpm_args    = function ( $key ) {
			return array(
				'licence_key' => rawurlencode( $key ),
				'site_url'    => rawurlencode( untrailingslashit( network_home_url( '', 'http' ) ) ),
			);
		};
		$wpm_classify = function ( $decoded ) {
			if ( ! is_array( $decoded ) ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The licensing service returned nothing readable', 'minn-admin' ) );
			}
			if ( ! empty( $decoded['dbrains_api_down'] ) ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach the licensing service', 'minn-admin' ) );
			}
			$errors = ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) ? array_keys( $decoded['errors'] ) : array();
			if ( empty( $errors ) ) {
				return array( 'ok' => true );
			}
			$word = (string) $errors[0];
			$map  = array(
				'subscription_expired'   => array( 'expired', __( 'That subscription has expired. Migrations still run; updates and support stop.', 'minn-admin' ) ),
				'subscription_cancelled' => array( 'expired', __( 'That subscription was cancelled.', 'minn-admin' ) ),
				'licence_not_found'      => array( 'invalid', __( 'WP Migrate does not recognise that key.', 'minn-admin' ) ),
				'no_activations_left'    => array( 'site_limit', __( 'Every activation on that license is in use.', 'minn-admin' ) ),
				'activation_deactivated' => array( 'invalid', __( 'This site\'s activation was turned off for that license.', 'minn-admin' ) ),
				'no_licence'             => array( 'invalid', __( 'No license key was sent.', 'minn-admin' ) ),
			);
			if ( isset( $map[ $word ] ) ) {
				return array( 'ok' => false, 'code' => $map[ $word ][0], 'message' => $map[ $word ][1] );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => str_replace( '_', ' ', $word ) );
		};

		$providers['wp-migrate']['secret_label'] = __( 'WP Migrate license key', 'minn-admin' );
		$providers['wp-migrate']['activate']     = function ( $secret ) use ( $wpm_license, $wpm_api, $wpm_args, $wpm_classify ) {
			$secret = trim( (string) $secret );
			if ( '' === $secret ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Enter a license key.', 'minn-admin' ) );
			}
			if ( defined( 'WPMDB_LICENCE' ) ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'This site sets its key in wp-config, which always wins. Change it there.', 'minn-admin' ) );
			}
			try {
				$raw     = $wpm_api()->dbrains_api_request( 'activate_licence', $wpm_args( $secret ) );
				$decoded = json_decode( (string) $raw, true );
				$result  = $wpm_classify( $decoded );
				// Their handler keeps the key for these three: the license is
				// real, it just cannot serve this site right now.
				$keep = ! empty( $result['ok'] );
				if ( ! $keep && is_array( $decoded ) ) {
					$errors = ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) ? array_keys( $decoded['errors'] ) : array();
					$word   = isset( $errors[0] ) ? (string) $errors[0] : '';
					$keep   = in_array( $word, array( 'subscription_expired', 'activation_deactivated' ), true ) || ! empty( $decoded['dbrains_api_down'] );
				}
				if ( $keep ) {
					$license = $wpm_license();
					$license->set_licence_key( $secret );
					// Their own post-activation cleanup, so the plugin's own
					// screen reads the same state Minn just wrote.
					delete_site_transient( 'update_plugins' );
					delete_site_transient( 'wpmdb_upgrade_data' );
					// Re-run their check so the cached answer matches the key.
					$license->check_license_status();
				}
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => $e->getMessage() );
			}
			return $result;
		};
		$providers['wp-migrate']['deactivate'] = function () use ( $wpm_license ) {
			if ( defined( 'WPMDB_LICENCE' ) ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'This site sets its key in wp-config. Remove it there.', 'minn-admin' ) );
			}
			try {
				$license = $wpm_license();
				// Mirrors their own remove handler, including the transients
				// that only make sense while a license is held.
				$license->set_licence_key( '' );
				delete_site_transient( 'update_plugins' );
				delete_site_transient( 'wpmdb_upgrade_data' );
				delete_site_transient( 'wpmdb_licence_response_' . get_current_user_id() );
				delete_site_transient( 'wpmdb_licence_response' );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => $e->getMessage() );
			}
			return array(
				'ok'      => true,
				'message' => __( 'The key was removed from this site. WP Migrate has no way to release the activation remotely, so free the seat in your account if you need it elsewhere.', 'minn-admin' ),
			);
		};
		$providers['wp-migrate']['verify'] = function () use ( $wpm_license, $wpm_api, $wpm_args, $wpm_classify ) {
			try {
				$license = $wpm_license();
				$key     = $license->get_licence_key();
				if ( ! $key ) {
					return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No license key is stored on this site.', 'minn-admin' ) );
				}
				// check_support_access is the request their own status check
				// uses, and it refreshes the cached answer the row reads.
				$raw = $wpm_api()->dbrains_api_request( 'check_support_access', $wpm_args( $key ) );
				set_site_transient( 'wpmdb_licence_response_' . get_current_user_id(), $raw, HOUR_IN_SECONDS );
				return $wpm_classify( json_decode( (string) $raw, true ) );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => $e->getMessage() );
			}
		};
	}

	// WP Rocket ships its credentials inside the vendor's zip (no key to
	// paste), so its one action is re-verify: rocket_check_key() validates
	// against their server and rewrites the stored state.
	if ( function_exists( 'rocket_check_key' ) ) {
		$providers['wp-rocket']['verify'] = function () {
			rocket_check_key();
			$flagged = (bool) get_option( 'wp_rocket_no_licence' );
			$errs    = get_transient( 'rocket_check_key_errors' );
			$msg     = ( is_array( $errs ) && $errs ) ? wp_strip_all_tags( (string) $errs[0] ) : '';
			return array( 'ok' => ! $flagged, 'code' => $flagged ? 'invalid' : '', 'message' => $flagged ? $msg : '' );
		};
	}

	// Beaver Builder: save_subscription_license() is their whole flow —
	// remote activate_domain, option write (cleared again on error) and
	// cache busts. No safe deactivate exists (their form just clears the
	// field locally), so none is offered.
	if ( class_exists( 'FLUpdater' ) && method_exists( 'FLUpdater', 'save_subscription_license' ) ) {
		$providers['beaver-builder']['secret_label'] = __( 'Beaver Builder license key', 'minn-admin' );
		$providers['beaver-builder']['activate']     = function ( $secret ) {
			$res = FLUpdater::save_subscription_license( $secret );
			$err = ( is_object( $res ) && ! empty( $res->error ) ) ? wp_strip_all_tags( (string) $res->error ) : '';
			if ( $err ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => $err );
			}
			FLUpdater::get_subscription_info(); // warm their status cache for read()
			return array( 'ok' => true );
		};
		$providers['beaver-builder']['verify'] = function () {
			delete_transient( 'fl_get_subscription_info' );
			$info   = FLUpdater::get_subscription_info();
			$active = $info && ! empty( $info->active );
			return array( 'ok' => (bool) $active, 'code' => $active ? '' : 'invalid', 'message' => $active ? '' : __( 'Beaver Builder reports the subscription as not active for this domain', 'minn-admin' ) );
		};
	}

	// Brizy Pro: their singleton's activate/deactivate throw an Exception
	// carrying the vendor message on failure; the action endpoint's
	// Throwable guard turns that into a plain error result.
	if ( class_exists( 'BrizyPro_Admin_License' ) ) {
		$providers['brizy-pro']['secret_label'] = __( 'Brizy Pro license key', 'minn-admin' );
		$providers['brizy-pro']['activate']     = function ( $secret ) {
			BrizyPro_Admin_License::_init()->activate( array( 'key' => $secret ) );
			return array( 'ok' => true );
		};
		$providers['brizy-pro']['deactivate'] = function () {
			BrizyPro_Admin_License::_init()->deactivate( array() );
			return array( 'ok' => true );
		};
	}

	// Breakdance: changeLicenseKey() is its complete save, check and activate
	// flow. Failed attempts restore both raw JSON options so a typo cannot
	// replace a working key or its last known status.
	if ( class_exists( '\Breakdance\Licensing\LicenseKeyManager' )
		&& defined( 'BREAKDANCE_MODE' )
		&& 'breakdance' === BREAKDANCE_MODE ) {
		$breakdance_result = function ( $manager ) use ( $breakdance_state ) {
			$info = $manager->getStoredLicenseKeyValidityInfo();
			if ( ! is_array( $info ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Breakdance did not recognize that key.', 'minn-admin' ) );
			}
			if ( 'pro' !== (string) ( $info['intended_subscription_mode'] ?? '' ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'That key does not include Breakdance Pro.', 'minn-admin' ) );
			}
			list( $state, , , $status ) = $breakdance_state( $info );
			if ( 'valid' === $state ) {
				return array( 'ok' => true );
			}
			if ( 'expired' === $state ) {
				return array( 'ok' => false, 'code' => 'expired', 'message' => __( 'That Breakdance Pro license has expired.', 'minn-admin' ) );
			}
			if ( in_array( $status, array( 'inactive', 'site_inactive' ), true ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Breakdance did not activate this site. Check the license site limit in your Breakdance account.', 'minn-admin' ) );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => $status ? str_replace( '_', ' ', $status ) : __( 'Breakdance did not recognize that key.', 'minn-admin' ) );
		};
		// Restore without a third argument: passing autoload explicitly would
		// flip the flag Breakdance chose if it stored these autoloaded.
		$breakdance_restore = function ( $key, $info ) {
			false === $key ? delete_option( 'breakdance_license_key' ) : update_option( 'breakdance_license_key', $key );
			false === $info ? delete_option( 'breakdance_license_key_validity_info' ) : update_option( 'breakdance_license_key_validity_info', $info );
		};
		$providers['breakdance']['secret_label'] = __( 'Breakdance Pro license key', 'minn-admin' );
		$providers['breakdance']['activate']     = function ( $secret ) use ( $breakdance_result, $breakdance_restore ) {
			$old_key  = get_option( 'breakdance_license_key', false );
			$old_info = get_option( 'breakdance_license_key_validity_info', false );
			$manager  = \Breakdance\Licensing\LicenseKeyManager::getInstance();
			// changeLicenseKey() persists the pasted key BEFORE the remote check
			// answers, so anything that throws in here leaves the rejected key
			// stored and the customer's working one gone. Restore on the
			// exception path too, exactly as the Kadence provider above does.
			try {
				$manager->changeLicenseKey( trim( (string) $secret ) );
				$result = $breakdance_result( $manager );
			} catch ( \Throwable $e ) {
				$breakdance_restore( $old_key, $old_info );
				return array(
					'ok'      => false,
					'code'    => 'error',
					'message' => trim( wp_strip_all_tags( (string) $e->getMessage() ) ),
				);
			}
			if ( empty( $result['ok'] ) ) {
				$breakdance_restore( $old_key, $old_info );
			}
			return $result;
		};
		$providers['breakdance']['verify'] = function () use ( $breakdance_result ) {
			$manager = \Breakdance\Licensing\LicenseKeyManager::getInstance();
			if ( ! $manager->getStoredLicenseKey() ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No Breakdance Pro key is stored.', 'minn-admin' ) );
			}
			$manager->refetchStoredLicenseKeyValidityInfo();
			return $breakdance_result( $manager );
		};
		$providers['breakdance']['deactivate'] = function () {
			$manager = \Breakdance\Licensing\LicenseKeyManager::getInstance();
			if ( ! $manager->deactivateLicense() ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Breakdance could not deactivate this license. Use its License screen to review the current status.', 'minn-admin' ) );
			}
			$info   = $manager->getStoredLicenseKeyValidityInfo();
			$status = is_array( $info ) && is_array( $info['edd_key_info'] ?? null ) ? (string) ( $info['edd_key_info']['license'] ?? '' ) : '';
			if ( 'valid' === $status ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Breakdance still reports this site as active, so the key was kept.', 'minn-admin' ) );
			}
			// The seat is already released remotely at this point, so a failure
			// clearing the local copy must not read as a failed deactivation.
			try {
				$manager->changeLicenseKey( null );
			} catch ( \Throwable $e ) {
				return array(
					'ok'      => true,
					'message' => __( 'The seat was freed, but the key could not be cleared here. Remove it on the Breakdance License screen.', 'minn-admin' ),
				);
			}
			return array( 'ok' => true, 'message' => __( 'The Breakdance Pro license was deactivated and its seat freed.', 'minn-admin' ) );
		};
	}

	// Bricks (active theme only): the non-ajax activate_license() path reads
	// the PUBLIC static key, only persists on a real status response, and
	// returns the status string (void = not recognized). Their deactivate
	// and revalidate handlers nonce-check unconditionally, so deactivation
	// stays on their screen; verify reuses activation with the stored key
	// (their own revalidate does exactly that).
	if ( class_exists( '\Bricks\License' ) ) {
		$providers['bricks']['secret_label'] = __( 'Bricks license key', 'minn-admin' );
		$providers['bricks']['activate']     = function ( $secret ) {
			\Bricks\License::$license_key = $secret;
			$status = \Bricks\License::activate_license();
			if ( 'active' === $status ) {
				return array( 'ok' => true );
			}
			if ( 'error_remote' === $status ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The Bricks license server is temporarily unavailable', 'minn-admin' ) );
			}
			/* translators: %s: status word returned by the Bricks license server. */
			return array( 'ok' => false, 'code' => 'invalid', 'message' => $status ? sprintf( __( 'Bricks returned status: %s', 'minn-admin' ), $status ) : __( 'Bricks did not recognize that key', 'minn-admin' ) );
		};
		$providers['bricks']['verify'] = function () {
			$key = get_option( 'bricks_license_key' );
			if ( ! $key ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			delete_transient( 'bricks_license_status' );
			\Bricks\License::$license_key = $key;
			$status = \Bricks\License::activate_license();
			return array( 'ok' => 'active' === $status, 'code' => 'active' === $status ? '' : 'invalid', 'message' => __( 'active', 'minn-admin' ) === $status ? '' : (string) $status );
		};
	}

	// Etch: wrapper methods throw readable messages; status lands in the
	// options its reader above consumes.
	if ( class_exists( '\Etch\WpAdmin\License' ) ) {
		$providers['etch']['secret_label'] = __( 'Etch license key', 'minn-admin' );
		$providers['etch']['activate']     = function ( $secret ) {
			\Etch\WpAdmin\License::get_instance()->activate_license( $secret );
			return array( 'ok' => true );
		};
		$providers['etch']['deactivate'] = function () {
			\Etch\WpAdmin\License::get_instance()->deactivate_license();
			return array( 'ok' => true );
		};
	}

	// Divi / Elegant Themes (active theme or an ET plugin loading et-core):
	// TWO secrets (username + API key) into their site option, then their
	// own checker validates during the theme-update check and stamps
	// et_account_status (active / expired / not_found). ET has no per-site
	// seats; deactivation is clearing the stored credentials.
	if ( class_exists( 'ET_Core_Updates' ) ) {
		$divi_check = function () {
			delete_site_transient( 'et_update_themes' ); // their own 10-min cache
			wp_update_themes(); // fires their pre_set_site_transient hook
			$status = strtolower( (string) get_site_option( 'et_account_status', 'not_active' ) );
			if ( 'active' === $status ) {
				return array( 'ok' => true );
			}
			$code = 'expired' === $status ? 'expired' : 'invalid';
			$msgs = array(
				'expired'    => __( 'Elegant Themes reports the subscription as expired', 'minn-admin' ),
				'not_found'  => __( 'Elegant Themes does not recognize that username', 'minn-admin' ),
				'not_active' => __( 'Elegant Themes did not confirm the account', 'minn-admin' ),
			);
			return array( 'ok' => false, 'code' => $code, 'message' => isset( $msgs[ $status ] ) ? $msgs[ $status ] : str_replace( '_', ' ', $status ) );
		};
		$providers['divi']['secret_fields'] = array(
			array( 'id' => 'username', 'label' => __( 'Elegant Themes username', 'minn-admin' ) ),
			array( 'id' => 'api_key', 'label' => __( 'API key', 'minn-admin' ) ),
		);
		$providers['divi']['activate'] = function ( $secrets ) use ( $divi_check ) {
			// Their checker reads the option, so it must be written before
			// validating. Snapshot first: a failed attempt restores the
			// previous credentials instead of clobbering a working pair
			// with a typo (better than Divi's own settings page).
			$prev_creds  = get_site_option( 'et_automatic_updates_options', array() );
			$prev_status = get_site_option( 'et_account_status', null );
			update_site_option( 'et_automatic_updates_options', array(
				'username' => sanitize_text_field( $secrets['username'] ),
				'api_key'  => sanitize_text_field( $secrets['api_key'] ),
			) );
			$result = $divi_check();
			if ( empty( $result['ok'] ) ) {
				update_site_option( 'et_automatic_updates_options', $prev_creds );
				if ( null === $prev_status ) {
					delete_site_option( 'et_account_status' );
				} else {
					update_site_option( 'et_account_status', $prev_status );
				}
				delete_site_transient( 'et_update_themes' );
			}
			return $result;
		};
		$providers['divi']['verify']     = $divi_check;
		$providers['divi']['deactivate'] = function () {
			update_site_option( 'et_automatic_updates_options', array() );
			delete_site_option( 'et_account_status' );
			delete_site_transient( 'et_update_themes' );
			return array( 'ok' => true, 'message' => __( 'Credentials removed; Elegant Themes has no per-site seats to release', 'minn-admin' ) );
		};
	}

	// Gravity Forms: GFFormsModel::save_key() is their whole flow (md5,
	// site registration, and it reverts to the previous key itself when the
	// new one cannot be used). Empty key = unlink the site. The version-info
	// refresh afterwards keeps the read-side classification current.
	if ( class_exists( 'GFFormsModel' ) && class_exists( 'GFCommon' ) ) {
		$gf_status = function () {
			$connector = GFForms::get_service_container()->get( \Gravity_Forms\Gravity_Forms\License\GF_License_Service_Provider::LICENSE_API_CONNECTOR );
			$info      = $connector->check_license( false, false );
			GFCommon::get_version_info( false ); // refresh gform_version_info for read()
			if ( $info->is_valid() ) {
				return array( 'ok' => true );
			}
			$msg  = wp_strip_all_tags( (string) $info->get_error_message() );
			$code = ( false !== stripos( $msg, 'sites' ) ) ? 'site_limit' : ( false !== stripos( $msg, 'expired' ) ? 'expired' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => $msg ? $msg : __( 'Gravity Forms did not validate the key', 'minn-admin' ) );
		};
		$providers['gravityforms']['secret_label'] = __( 'Gravity Forms license key', 'minn-admin' );
		$providers['gravityforms']['activate']     = function ( $secret ) use ( $gf_status ) {
			$prev = get_option( 'rg_gforms_key' );
			GFFormsModel::save_key( $secret );
			if ( get_option( 'rg_gforms_key' ) !== md5( trim( $secret ) ) ) {
				// Their own revert fired: the key was rejected outright.
				GFCommon::get_version_info( false );
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Gravity Forms did not accept that key', 'minn-admin' ) );
			}
			$res = $gf_status();
			if ( empty( $res['ok'] ) ) {
				// save_key accepted the key's shape but the license check
				// refused it (expired / site limit). Restore the prior state
				// so a failed activation never retains the pasted key.
				if ( is_string( $prev ) && '' !== $prev ) {
					update_option( 'rg_gforms_key', $prev );
				} else {
					GFFormsModel::save_key( '' ); // unlinks the site registration too
					delete_option( 'rg_gforms_key' );
				}
				GFCommon::get_version_info( false );
			}
			return $res;
		};
		$providers['gravityforms']['deactivate'] = function () {
			GFFormsModel::save_key( '' ); // unlinks the site with Gravity's server
			GFCommon::get_version_info( false );
			return array( 'ok' => true, 'message' => __( 'Site unlinked from the license', 'minn-admin' ) );
		};
		$providers['gravityforms']['verify'] = $gf_status;
	}

	// Gravity SMTP: validate through its container's license connector,
	// persist through its own plugin-opts data store (which is what its
	// settings endpoint does; constant locks stay respected).
	if ( class_exists( '\Gravity_Forms\Gravity_SMTP\Gravity_SMTP' ) ) {
		$smtp_check = function ( $key ) {
			$container = \Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
			$info      = $container->get( \Gravity_Forms\Gravity_Tools\Updates\Updates_Service_Provider::LICENSE_API_CONNECTOR )->check_license( $key );
			$status    = $info->get_status();
			if ( \Gravity_Forms\Gravity_Tools\License\License_Statuses::VALID_KEY === $status ) {
				return array( 'ok' => true );
			}
			$msg  = method_exists( $info, 'get_error_message' ) ? wp_strip_all_tags( (string) $info->get_error_message() ) : '';
			$code = ( false !== stripos( (string) $status, 'number_of_sites' ) || false !== stripos( $msg, 'sites' ) ) ? 'site_limit'
				: ( false !== stripos( (string) $status, 'expired' ) ? 'expired' : 'invalid' );
			// Gravity's API answers an unknown key with a bare REST no-route;
			// surface that as what it means.
			if ( 'rest_no_route' === (string) $status || false !== stripos( $msg, 'No route was found' ) ) {
				$msg = __( 'Gravity did not recognize that key', 'minn-admin' );
			}
			return array( 'ok' => false, 'code' => $code, 'message' => $msg ? $msg : str_replace( '_', ' ', (string) $status ) );
		};
		$smtp_store = function () {
			return \Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container()->get( \Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::DATA_STORE_PLUGIN_OPTS );
		};
		$providers['gravitysmtp']['secret_label'] = __( 'Gravity SMTP license key', 'minn-admin' );
		$providers['gravitysmtp']['activate']     = function ( $secret ) use ( $smtp_check, $smtp_store ) {
			$result = $smtp_check( $secret );
			if ( ! empty( $result['ok'] ) ) {
				$smtp_store()->save( 'license_key', $secret ); // only a validated key is stored
			}
			return $result;
		};
		$providers['gravitysmtp']['deactivate'] = function () use ( $smtp_store ) {
			$smtp_store()->save( 'license_key', '' );
			return array( 'ok' => true );
		};
		$providers['gravitysmtp']['verify'] = function () use ( $smtp_check ) {
			$cfg = json_decode( (string) get_option( 'gravitysmtp_config' ), true );
			$key = is_array( $cfg ) && isset( $cfg['license_key'] ) ? (string) $cfg['license_key'] : '';
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			return $smtp_check( $key );
		};
	}

	// WPMU DEV: mirror their auth endpoint minus the redirect — set_key,
	// then a FORCED hub_sync. The Hub answers an invalid or expired key
	// with an empty membership (hub_sync itself logs the site out in that
	// case). No paste field while the key is pinned in wp-config: set_key
	// would be silently overridden on the next boot.
	if ( class_exists( 'WPMUDEV_Dashboard' ) && ! ( defined( 'WPMUDEV_APIKEY' ) && WPMUDEV_APIKEY ) ) {
		$providers['wpmudev']['secret_label'] = __( 'WPMU DEV API key', 'minn-admin' );
		$providers['wpmudev']['activate']     = function ( $secret ) {
			WPMUDEV_Dashboard::$api->set_key( $secret );
			$res = WPMUDEV_Dashboard::$api->hub_sync( false, true );
			if ( false === $res ) {
				WPMUDEV_Dashboard::$api->set_key( '' ); // the rollback their own endpoint does
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach the WPMU DEV Hub', 'minn-admin' ) );
			}
			if ( empty( $res['membership'] ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'The Hub did not recognize that API key', 'minn-admin' ) );
			}
			return array( 'ok' => true );
		};
		$providers['wpmudev']['deactivate'] = function () {
			WPMUDEV_Dashboard::$site->logout( false );
			return array( 'ok' => true );
		};
		$providers['wpmudev']['verify'] = function () {
			if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			$res = WPMUDEV_Dashboard::$api->hub_sync( false, true );
			$ok  = is_array( $res ) && ! empty( $res['membership'] );
			return array( 'ok' => $ok, 'code' => $ok ? '' : 'invalid', 'message' => $ok ? '' : __( 'The Hub reports no active membership for this key', 'minn-admin' ) );
		};
	}

	// SearchWP: License::activate/deactivate return { success, data } where
	// a failure's data is the human message; maintenance() is their own
	// daily check_license pass and rewrites the stored status.
	if ( class_exists( '\SearchWP\License' ) ) {
		$providers['searchwp']['secret_label'] = __( 'SearchWP license key', 'minn-admin' );
		$providers['searchwp']['activate']     = function ( $secret ) {
			$res = \SearchWP\License::activate( $secret );
			if ( is_array( $res ) && ! empty( $res['success'] ) ) {
				return array( 'ok' => true );
			}
			$msg  = ( is_array( $res ) && isset( $res['data'] ) && is_string( $res['data'] ) ) ? wp_strip_all_tags( $res['data'] ) : '';
			$code = ( false !== stripos( $msg, 'limit' ) ) ? 'site_limit' : ( false !== stripos( $msg, 'expired' ) ? 'expired' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => $msg ? $msg : __( 'SearchWP did not accept that key', 'minn-admin' ) );
		};
		$providers['searchwp']['deactivate'] = function () {
			$lic = get_option( 'searchwp_license' );
			$key = ( is_array( $lic ) && ! empty( $lic['key'] ) ) ? (string) $lic['key'] : '';
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			$res = \SearchWP\License::deactivate( $key );
			$ok  = is_array( $res ) && ! empty( $res['success'] );
			$msg = ( is_array( $res ) && isset( $res['data'] ) && is_string( $res['data'] ) ) ? wp_strip_all_tags( $res['data'] ) : '';
			return array( 'ok' => $ok, 'code' => $ok ? '' : 'error', 'message' => $ok ? '' : $msg );
		};
		$providers['searchwp']['verify'] = function () {
			\SearchWP\License::maintenance();
			$lic = get_option( 'searchwp_license' );
			$ok  = is_array( $lic ) && isset( $lic['status'] ) && 'valid' === $lic['status'];
			return array( 'ok' => $ok, 'code' => $ok ? '' : 'invalid', 'message' => $ok ? '' : __( 'SearchWP did not confirm the license', 'minn-admin' ) );
		};
	}

	// WPForms Pro: WPForms_License is the complete flow — verify_key (their
	// activation) writes the option and resets flags, deactivate_key frees
	// the seat remotely and empties the option, validate_key persists status
	// flags. The class file only loads under is_admin()/cron/CLI, so REST
	// actions require it manually (the Settings-API/Slider-Revolution class
	// of gate). Constructing it runs their own periodic background check,
	// which is exactly what every wp-admin pageload does. Non-ajax calls
	// never wp_send_json; errors land in ->errors.
	if ( function_exists( 'wpforms' ) && defined( 'WPFORMS_PLUGIN_DIR' ) && file_exists( WPFORMS_PLUGIN_DIR . 'pro/includes/admin/class-license.php' ) ) {
		$wpf_license = function () {
			if ( ! class_exists( 'WPForms_License' ) ) {
				require_once WPFORMS_PLUGIN_DIR . 'pro/includes/admin/class-license.php';
			}
			$lic = method_exists( wpforms(), 'obj' ) ? wpforms()->obj( 'license' ) : null;
			return ( $lic instanceof WPForms_License ) ? $lic : new WPForms_License();
		};

		$providers['wpforms']['secret_label'] = __( 'WPForms license key', 'minn-admin' );
		$providers['wpforms']['activate']     = function ( $secret ) use ( $wpf_license ) {
			$lic = $wpf_license();
			if ( $lic->verify_key( $secret, false ) ) {
				return array( 'ok' => true );
			}
			$msg  = ! empty( $lic->errors ) ? wp_strip_all_tags( implode( ' ', $lic->errors ) ) : __( 'WPForms did not accept that key', 'minn-admin' );
			$code = ( false !== stripos( $msg, 'limit' ) ) ? 'site_limit' : ( false !== stripos( $msg, 'expired' ) ? 'expired' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => $msg );
		};
		$providers['wpforms']['deactivate'] = function () use ( $wpf_license ) {
			if ( defined( 'WPFORMS_LICENSE_KEY' ) && WPFORMS_LICENSE_KEY ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The key is defined in wp-config (WPFORMS_LICENSE_KEY); remove the constant to deactivate.', 'minn-admin' ) );
			}
			$lic = $wpf_license();
			if ( '' === $lic->get() ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			$lic->deactivate_key( false );
			if ( ! empty( $lic->errors ) ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => wp_strip_all_tags( implode( ' ', $lic->errors ) ) );
			}
			return array( 'ok' => true );
		};
		$providers['wpforms']['verify'] = function () use ( $wpf_license ) {
			$lic = $wpf_license();
			$key = $lic->get();
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			$status = $lic->validate_key( $key, false, false, true );
			if ( false === $status ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach the WPForms license API', 'minn-admin' ) );
			}
			$ok   = in_array( $status, array( 'valid', 'flagged' ), true );
			$code = $ok ? '' : ( 'expired' === $status ? 'expired' : ( 'limit_reached' === $status ? 'site_limit' : 'invalid' ) );
			/* translators: %s: status word returned by the WPForms license API. */
			return array( 'ok' => $ok, 'code' => $code, 'message' => $ok ? '' : sprintf( __( 'License check returned: %s', 'minn-admin' ), str_replace( '_', ' ', (string) $status ) ) );
		};
	}

	// Akismet: their own machinery end to end. Akismet_Admin::save_key() is
	// the COMPLETE activation flow (verify_key → subscription check via
	// get_akismet_user → stores only when the account is active); the admin
	// class is is_admin()/WP_CLI-gated so REST actions require it manually
	// (the WPForms pattern). Success test = the key actually stored; on
	// failure verify_key() distinguishes a bad key from an inactive
	// subscription from an unreachable API. deactivate_key() is their real
	// remote deregistration (their own full-deactivate precedent), followed
	// by the option cleanup their disconnect performs. Keys sanitize with
	// THEIR rule (12 hex chars).
	if ( class_exists( 'Akismet' ) && defined( 'AKISMET__PLUGIN_DIR' ) ) {
		$akismet_admin = function () {
			if ( ! class_exists( 'Akismet_Admin' ) && file_exists( AKISMET__PLUGIN_DIR . 'class.akismet-admin.php' ) ) {
				require_once AKISMET__PLUGIN_DIR . 'class.akismet-admin.php';
			}
			return class_exists( 'Akismet_Admin' );
		};

		$providers['akismet']['secret_label'] = __( 'Akismet API key', 'minn-admin' );
		$providers['akismet']['activate']     = function ( $secret ) use ( $akismet_admin ) {
			if ( Akismet::predefined_api_key() ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The key is supplied in code (WPCOM_API_KEY or a filter); it cannot be changed here.', 'minn-admin' ) );
			}
			$key = preg_replace( '/[^a-f0-9]/i', '', (string) $secret );
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'That does not look like an Akismet key (12 characters, letters a-f and digits).', 'minn-admin' ) );
			}
			if ( ! $akismet_admin() ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Akismet admin machinery unavailable.', 'minn-admin' ) );
			}
			Akismet_Admin::save_key( $key );
			if ( Akismet::get_api_key() === $key ) {
				return array( 'ok' => true );
			}
			$v = Akismet::verify_key( $key );
			if ( 'valid' === $v ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'The key is real but its Akismet plan is not active (missing, cancelled or suspended subscription).', 'minn-admin' ) );
			}
			if ( 'invalid' === $v ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Akismet did not recognize that key.', 'minn-admin' ) );
			}
			return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach the Akismet API. Please try again.', 'minn-admin' ) );
		};
		$providers['akismet']['deactivate'] = function () {
			if ( Akismet::predefined_api_key() ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'The key is supplied in code (WPCOM_API_KEY or a filter); remove it there.', 'minn-admin' ) );
			}
			$key = (string) Akismet::get_api_key();
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			try {
				Akismet::deactivate_key( $key );
			} catch ( \Throwable $e ) { /* remote deregistration is best-effort; local removal below is the point */ }
			delete_option( 'wordpress_api_key' );
			delete_option( 'akismet_alert_code' );
			delete_option( 'akismet_alert_msg' );
			return array( 'ok' => true, 'message' => __( 'Key removed from this site.', 'minn-admin' ) );
		};
		$providers['akismet']['verify'] = function () {
			$key = (string) Akismet::get_api_key();
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			$v = Akismet::verify_key( $key );
			if ( 'valid' === $v ) {
				return array( 'ok' => true );
			}
			if ( 'invalid' === $v ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Akismet reports this key as invalid.', 'minn-admin' ) );
			}
			return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach the Akismet API.', 'minn-admin' ) );
		};
	}

	// CleanTalk: paste-an-access-key through adapters/cleantalk.php
	// (same store as Settings → Connectors). Actions only attach while
	// the plugin is loaded, so the row is Turn-on-only while inactive.
	if ( defined( 'APBCT_VERSION' ) && function_exists( 'minn_admin_cleantalk_save_access_key' ) ) {
		$providers['cleantalk']['secret_label'] = __( 'CleanTalk access key', 'minn-admin' );
		$providers['cleantalk']['activate']     = function ( $secret ) {
			return minn_admin_cleantalk_save_access_key( $secret );
		};
		$providers['cleantalk']['deactivate'] = function () {
			return minn_admin_cleantalk_save_access_key( '' );
		};
		$providers['cleantalk']['verify'] = function () {
			return minn_admin_cleantalk_verify_access_key();
		};
	}

	// Search & Filter Pro: their REST controller's connect()/disconnect()
	// are public static and run the COMPLETE flow (EDD call + persistence
	// through the base plugin's Options store), so the actions call them
	// with a constructed request. Needs BOTH plugins active: Pro ships the
	// license classes, the free base ships the store they write through.
	// Their connect() persists a REJECTED key over a working one (their own
	// UI behavior); Minn snapshot-restores the prior valid data instead.
	if ( class_exists( '\Search_Filter_Pro\License\Rest_API' ) && class_exists( '\Search_Filter\Options' ) ) {
		$sfp_classify = function ( $data ) {
			$data   = is_array( $data ) ? $data : array();
			$status = strtolower( (string) ( $data['status'] ?? '' ) );
			$error  = strtolower( (string) ( $data['error'] ?? '' ) );
			$msg    = (string) ( $data['errorMessage'] ?? '' );
			if ( 'valid' === $status ) {
				return array( 'ok' => true, 'code' => '', 'message' => '' );
			}
			if ( 'no_activations_left' === $error ) {
				return array( 'ok' => false, 'code' => 'site_limit', 'message' => __( 'This license has reached its activation limit. Free a seat on searchandfilter.com first.', 'minn-admin' ) );
			}
			if ( 'expired' === $status || 'expired' === $error ) {
				return array( 'ok' => false, 'code' => 'expired', 'message' => __( 'This Search & Filter license has expired.', 'minn-admin' ) );
			}
			if ( $msg ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => wp_strip_all_tags( $msg ) );
			}
			if ( in_array( $error, array( 'missing', 'key_mismatch', 'item_name_mismatch' ), true ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'searchandfilter.com does not recognize that key.', 'minn-admin' ) );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => $error ? str_replace( '_', ' ', $error ) : __( 'searchandfilter.com did not accept that key.', 'minn-admin' ) );
		};
		$providers['search-filter-pro']['secret_label'] = __( 'Search & Filter license key', 'minn-admin' );
		$providers['search-filter-pro']['activate']     = function ( $secret ) use ( $sfp_classify ) {
			$prior = \Search_Filter_Pro\Core\License_Server::get_license_data();
			$req   = new \WP_REST_Request( 'GET', '/search-filter-pro/v1/license/connect' );
			$req->set_param( 'license', trim( (string) $secret ) );
			$res = \Search_Filter_Pro\License\Rest_API::connect( $req );
			$out = $sfp_classify( $res instanceof \WP_REST_Response ? $res->get_data() : null );
			if ( empty( $out['ok'] ) && 'valid' === ( $prior['status'] ?? '' ) && ! empty( $prior['license'] ) ) {
				\Search_Filter\Options::update( 'license-data', $prior );
			}
			return $out;
		};
		$providers['search-filter-pro']['deactivate']   = function () {
			$res  = \Search_Filter_Pro\License\Rest_API::disconnect();
			$data = $res instanceof \WP_REST_Response ? $res->get_data() : array();
			if ( is_array( $data ) && 'disconnected' === ( $data['status'] ?? '' ) ) {
				return array( 'ok' => true, 'code' => '', 'message' => __( 'The license was disconnected and the seat freed.', 'minn-admin' ) );
			}
			$msg = is_array( $data ) && ! empty( $data['errorMessage'] ) ? (string) $data['errorMessage'] : __( 'Search & Filter could not disconnect the license.', 'minn-admin' );
			return array( 'ok' => false, 'code' => 'error', 'message' => wp_strip_all_tags( $msg ) );
		};
		$providers['search-filter-pro']['verify']       = function () use ( $sfp_classify ) {
			$stored = \Search_Filter_Pro\Core\License_Server::get_license_data();
			if ( empty( $stored['license'] ) ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No license key is stored.', 'minn-admin' ) );
			}
			// Their own revalidation pass: re-checks the stored key against
			// the license server and updates status + expiry in place.
			\Search_Filter_Pro\Core\License_Server::refresh_health();
			return $sfp_classify( \Search_Filter_Pro\Core\License_Server::get_license_data() );
		};
	}

	// Admin Columns Pro: every service their nonce-bound ajax handlers
	// orchestrate resolves from the DI container (bootstrapped headless by
	// minn_admin_acp_container — their main file is is_admin()-gated), so
	// the actions run the handlers' EXACT sequences minus the nonce.
	// Their LicenseKey constructor THROWS on malformed keys (needs length
	// over 12 and a dash) — caught and mapped to a clean invalid.
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( is_plugin_active( 'admin-columns-pro/admin-columns-pro.php' ) && minn_admin_acp_container() ) {
		$acp           = minn_admin_acp_container();
		$acp_classify_error = function ( $error ) {
			$msg   = wp_strip_all_tags( (string) $error->get_error_message() );
			$codes = implode( ' ', $error->get_error_codes() );
			if ( preg_match( '/limit|maximum|max[_ ]sites|no_activations/i', $codes . ' ' . $msg ) ) {
				return array( 'ok' => false, 'code' => 'site_limit', 'message' => $msg ? $msg : __( 'This license has reached its activation limit.', 'minn-admin' ) );
			}
			if ( preg_match( '/expired/i', $codes . ' ' . $msg ) ) {
				return array( 'ok' => false, 'code' => 'expired', 'message' => $msg ? $msg : __( 'This Admin Columns license has expired.', 'minn-admin' ) );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => $msg ? $msg : __( 'admincolumns.com did not accept that key.', 'minn-admin' ) );
		};
		$providers['admin-columns-pro']['secret_label'] = __( 'Admin Columns Pro license key', 'minn-admin' );
		$providers['admin-columns-pro']['activate']     = function ( $secret ) use ( $acp, $acp_classify_error ) {
			try {
				$license_key = new \ACP\Type\LicenseKey( trim( (string) $secret ) );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'That does not look like an Admin Columns key (they are long and contain a dash).', 'minn-admin' ) );
			}
			$response = $acp->get( \ACP\ApiFactory::class )->create()->dispatch(
				new \ACP\API\Request\Activate( $license_key, $acp->get( \ACP\Type\SiteUrl::class ) )
			);
			// Permissions ride the API response in their handler; keep that.
			$acp->get( \ACP\Access\PermissionCheckerFactory::class )->create()
				->add_rule( new \ACP\Access\Rule\ApiPermissionResponse( $response ) )
				->add_rule( new \ACP\Access\Rule\ApiErrorResponse( $response ) )
				->apply();
			if ( $response->has_error() ) {
				return $acp_classify_error( $response->get_error() );
			}
			try {
				$activation_key = new \ACP\Type\Activation\Key( (string) $response->get( 'activation_key' ) );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'admincolumns.com accepted the key but returned an invalid activation token.', 'minn-admin' ) );
			}
			$acp->get( \ACP\Access\ActivationKeyStorage::class )->save( $activation_key );
			$acp->get( \ACP\Access\ActivationUpdater::class )->update( $activation_key );
			$acp->get( \ACP\Updates\PluginDataUpdater::class )->update( $activation_key );
			wp_clean_plugins_cache();
			wp_update_plugins();
			$msg = wp_strip_all_tags( (string) $response->get( 'message' ) );
			return array( 'ok' => true, 'code' => '', 'message' => $msg );
		};
		$providers['admin-columns-pro']['deactivate']   = function () use ( $acp, $acp_classify_error ) {
			// Their handler's order: resolve the token, clear local state,
			// then tell the API (local removal succeeds even offline).
			$token = $acp->get( \ACP\ActivationTokenFactory::class )->create();
			$acp->get( \ACP\LicenseKeyRepository::class )->delete();
			$acp->get( \ACP\Access\ActivationKeyStorage::class )->delete();
			$acp->get( \ACP\Access\ActivationStorage::class )->delete();
			$acp->get( \ACP\Access\PermissionCheckerFactory::class )->create()->apply();
			if ( ! $token ) {
				return array( 'ok' => true, 'code' => '', 'message' => __( 'No activation was stored; local license state cleared.', 'minn-admin' ) );
			}
			$response = $acp->get( \ACP\ApiFactory::class )->create()->dispatch(
				new \ACP\API\Request\Deactivate( $token, $acp->get( \ACP\Type\SiteUrl::class ) )
			);
			$acp->get( \ACP\Access\PermissionCheckerFactory::class )->create()
				->add_rule( new \ACP\Access\Rule\ApiErrorResponse( $response ) )
				->add_rule( new \ACP\Access\Rule\ApiPermissionResponse( $response ) )
				->apply();
			if ( $response->has_error() ) {
				$out            = $acp_classify_error( $response->get_error() );
				/* translators: %s: error message reported by admincolumns.com. */
				$out['message'] = sprintf( __( 'Local license state was cleared, but admincolumns.com reported: %s', 'minn-admin' ), $out['message'] );
				return $out;
			}
			$acp->get( \ACP\Updates\PluginDataUpdater::class )->update( $token );
			wp_clean_plugins_cache();
			wp_update_plugins();
			return array( 'ok' => true, 'code' => '', 'message' => __( 'The license was deactivated and the seat freed.', 'minn-admin' ) );
		};
		$providers['admin-columns-pro']['verify']       = function () use ( $acp, $acp_classify_error ) {
			$token = $acp->get( \ACP\ActivationTokenFactory::class )->create();
			if ( ! $token ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No license key is stored.', 'minn-admin' ) );
			}
			// Their own refresh: re-fetches subscription details and stores
			// status + expiry (also self-cleans a revoked activation).
			$response = $acp->get( \ACP\Access\ActivationUpdater::class )->update( $token );
			if ( $response->has_error() ) {
				return $acp_classify_error( $response->get_error() );
			}
			return array( 'ok' => true, 'code' => '', 'message' => '' );
		};
	}

	// Gravity Perks: mirror their form handler — store the key in the
	// gwp_settings site option, flush their 12-hour caches, then let their
	// own check flow (which self-activates an inactive key) decide.
	if ( class_exists( 'GravityPerks' ) && class_exists( 'GWPerks' ) ) {
		$gwp_save_key = function ( $key ) {
			$settings                = get_site_option( 'gwp_settings' );
			$settings                = is_array( $settings ) ? $settings : array();
			$settings['license_key'] = trim( (string) $key );
			update_site_option( 'gwp_settings', $settings );
		};
		$providers['gravityperks']['secret_label'] = __( 'Gravity Perks license key', 'minn-admin' );
		$providers['gravityperks']['activate']     = function ( $secret ) use ( $gwp_save_key ) {
			// Their check flow self-activates the stored key, so it must be
			// written first; snapshot and restore on failure so a rejected
			// key is not retained.
			$prev = get_site_option( 'gwp_settings' );
			$prev = ( is_array( $prev ) && isset( $prev['license_key'] ) ) ? (string) $prev['license_key'] : '';
			$gwp_save_key( $secret );
			GWPerks::flush_license( true );
			if ( GWPerks::has_valid_license() ) {
				return array( 'ok' => true );
			}
			$data = GravityPerks::get_api()->get_license_data();
			$gwp_save_key( $prev ); // restore — do not retain the rejected key
			GWPerks::flush_license( true );
			if ( is_array( $data ) && isset( $data['activations_left'] ) && 0 === (int) $data['activations_left'] ) {
				return array( 'ok' => false, 'code' => 'site_limit', 'message' => __( 'That key has reached its site limit', 'minn-admin' ) );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'Gravity Wiz did not validate that key', 'minn-admin' ) );
		};
		$providers['gravityperks']['deactivate'] = function () use ( $gwp_save_key ) {
			GravityPerks::get_api()->deactivate_license();
			$gwp_save_key( '' ); // their handler blanks the stored key after the remote release
			GWPerks::flush_license( true );
			return array( 'ok' => true );
		};
		$providers['gravityperks']['verify'] = function () {
			GWPerks::flush_license( true );
			$ok = GWPerks::has_valid_license();
			return array( 'ok' => (bool) $ok, 'code' => $ok ? '' : 'invalid', 'message' => $ok ? '' : __( 'Gravity Wiz reports the license as not valid for this site', 'minn-admin' ) );
		};
	}

	// Perfmatters: their static License::activate() takes the key directly
	// and validates it against the passed key, storing only STATUS on a
	// 'valid' response. The plugin reads the KEY from its own option to
	// function, so store the pasted key ONLY on success (paste-never-retain
	// on failure); check() supplies the why but also writes status, so the
	// prior state is snapshotted and restored on failure.
	if ( class_exists( '\Perfmatters\License' ) ) {
		$pm_get = is_multisite() ? 'get_site_option' : 'get_option';
		$pm_set = is_multisite() ? 'update_site_option' : 'update_option';
		$pm_del = is_multisite() ? 'delete_site_option' : 'delete_option';
		$providers['perfmatters']['secret_label'] = __( 'Perfmatters license key', 'minn-admin' );
		$providers['perfmatters']['activate']     = function ( $secret ) use ( $pm_get, $pm_set, $pm_del ) {
			$prev_status = call_user_func( $pm_get, 'perfmatters_edd_license_status' );
			if ( \Perfmatters\License::activate( $secret ) ) {
				call_user_func( $pm_set, 'perfmatters_edd_license_key', sanitize_text_field( $secret ) );
				return array( 'ok' => true );
			}
			$info = \Perfmatters\License::check( $secret );
			$word = ( is_object( $info ) && ! empty( $info->license ) ) ? (string) $info->license : '';
			// check() wrote a status for a key we are NOT keeping — restore.
			if ( false === $prev_status ) {
				call_user_func( $pm_del, 'perfmatters_edd_license_status' );
			} else {
				call_user_func( $pm_set, 'perfmatters_edd_license_status', $prev_status );
			}
			$code = 'expired' === $word ? 'expired' : ( 'no_activations_left' === $word ? 'site_limit' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => $word ? str_replace( '_', ' ', $word ) : __( 'Perfmatters did not accept that key', 'minn-admin' ) );
		};
		$providers['perfmatters']['deactivate'] = function () use ( $pm_del ) {
			$ok = \Perfmatters\License::deactivate();
			// Their deactivate() releases the seat and deletes the STATUS
			// but keeps the key (they expose a separate "remove" action for
			// that). Minn's single Deactivate means release-and-forget, so
			// also drop the key — the row reads missing like the others.
			if ( $ok ) {
				call_user_func( $pm_del, 'perfmatters_edd_license_key' );
			}
			return array( 'ok' => (bool) $ok, 'code' => $ok ? '' : 'error', 'message' => $ok ? '' : __( 'Perfmatters could not release this site', 'minn-admin' ) );
		};
		$providers['perfmatters']['verify'] = function () {
			$info = \Perfmatters\License::check(); // also refreshes the stored status
			$word = ( is_object( $info ) && ! empty( $info->license ) ) ? (string) $info->license : '';
			$ok   = 'valid' === $word;
			return array( 'ok' => $ok, 'code' => $ok ? '' : ( 'expired' === $word ? 'expired' : 'invalid' ), 'message' => $ok ? '' : ( $word ? str_replace( '_', ' ', $word ) : __( 'No response from the Perfmatters server', 'minn-admin' ) ) );
		};
	}

	// Automatic.css: activation lives in a $_POST-driven admin form whose
	// handler nonce-checks and redirect-exits, with no public callable — so
	// the request is mirrored byte-for-byte against their EDD store instead
	// (the Soflyy exception; every param comes from reading their
	// handle_license_activation(), never invented). One deliberate
	// deviation: their form stores even a rejected key, and Minn's contract
	// wins there — nothing is written on failure.
	if ( class_exists( '\\Automatic_CSS\\UI\\Settings_Page\\Plugin_Updater' ) ) {
		$acss_edd = function ( $edd_action, $license ) {
			$response = wp_remote_post( 'https://automaticcss.com/', array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => array(
					'edd_action'  => $edd_action,
					'license'     => $license,
					'item_id'     => 164,
					'item_name'   => rawurlencode( 'Automatic.css' ),
					'url'         => site_url(),
					'environment' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
				),
			) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}
			$data = json_decode( wp_remote_retrieve_body( $response ) );
			return ( is_object( $data ) && isset( $data->license ) ) ? $data : null;
		};
		$acss_code = function ( $word ) {
			if ( 'expired' === $word ) {
				return 'expired';
			}
			return 'no_activations_left' === $word ? 'site_limit' : 'invalid';
		};
		// The messages their own screen shows for each EDD answer, minus the
		// dates it interpolates ('missing' is EDD's word for an unknown key
		// and reads like Minn's missing STATE if echoed raw).
		$acss_word = function ( $word ) {
			switch ( $word ) {
				case 'missing':
				case 'item_name_mismatch':
					return __( 'That isn’t a valid Automatic.css license key.', 'minn-admin' );
				case 'invalid':
				case 'site_inactive':
					return __( 'The license is not active for this site’s URL.', 'minn-admin' );
				case 'disabled':
				case 'revoked':
					return __( 'That license key has been disabled.', 'minn-admin' );
				case 'expired':
					return __( 'That license key has expired.', 'minn-admin' );
				case 'no_activations_left':
					return __( 'That license key has reached its activation limit.', 'minn-admin' );
			}
			return '' !== $word ? str_replace( '_', ' ', $word ) : __( 'Automatic.css did not accept that key.', 'minn-admin' );
		};
		$providers['automatic-css']['secret_label'] = __( 'Automatic.css license key', 'minn-admin' );
		$providers['automatic-css']['activate']     = function ( $secret ) use ( $acss_edd, $acss_code, $acss_word ) {
			$secret = trim( (string) $secret );
			// Their own screen shows the stored key masked as XXXX…; a copy
			// of that mask is not a key.
			if ( false !== strpos( $secret, 'XXXXXXXX' ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'That looks like the masked key from Automatic.css’s screen — paste the full license key.', 'minn-admin' ) );
			}
			$data = $acss_edd( 'activate_license', $secret );
			if ( ! $data ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No response from the Automatic.css server.', 'minn-admin' ) );
			}
			if ( empty( $data->success ) ) {
				$word = (string) ( $data->error ?? $data->license );
				return array( 'ok' => false, 'code' => $acss_code( $word ), 'message' => $acss_word( $word ) );
			}
			// Mirror their success writes: the raw key, and the status word
			// the server answered with.
			update_option( 'automatic_css_license_key', sanitize_text_field( $secret ) );
			update_option( 'automatic_css_license_status', sanitize_key( (string) $data->license ) );
			return array( 'ok' => true );
		};
		$providers['automatic-css']['deactivate'] = function () use ( $acss_edd ) {
			$key  = trim( (string) get_option( 'automatic_css_license_key' ) );
			$data = '' !== $key ? $acss_edd( 'deactivate_license', $key ) : null;
			// Their handler deletes both options whatever the server said,
			// so removal is always local-true; the message stays honest
			// about whether the seat was actually released.
			delete_option( 'automatic_css_license_key' );
			delete_option( 'automatic_css_license_status' );
			$freed = $data && 'deactivated' === (string) $data->license;
			return array(
				'ok'      => true,
				'message' => $freed
					? __( 'Automatic.css deactivated; the seat was released.', 'minn-admin' )
					: __( 'Key removed from this site. Automatic.css did not confirm releasing the seat — check the account if the activation count matters.', 'minn-admin' ),
			);
		};
		$providers['automatic-css']['verify'] = function () use ( $acss_edd, $acss_code, $acss_word ) {
			$key = trim( (string) get_option( 'automatic_css_license_key' ) );
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No Automatic.css key is stored.', 'minn-admin' ) );
			}
			$data = $acss_edd( 'check_license', $key );
			if ( ! $data ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No response from the Automatic.css server.', 'minn-admin' ) );
			}
			// Read-only, exactly like their own check_license: the stored
			// status stays whatever the vendor last wrote. EDD answers
			// site_inactive for a URL the key wasn't activated on — normal
			// on a dev clone of a licensed site — and persisting that here
			// would flip a truthfully-valid row on the clone's original.
			$word = (string) $data->license;
			$ok   = 'valid' === $word;
			return array( 'ok' => $ok, 'code' => $ok ? '' : $acss_code( $word ), 'message' => $ok ? '' : $acss_word( $word ) );
		};
	}

	// GeneratePress Premium: drive its OWN REST route (the same code its
	// dashboard runs) via rest_do_request. Empty key + a valid stored one
	// is their deactivate branch; '***' is their masked-key flow, which
	// re-activates the stored key — a re-verify.
	if ( class_exists( 'GeneratePress_Pro_Rest' ) ) {
		// Their route unconditionally writes gen_premium_license_key at the
		// end (even on a rejected key), so snapshot the key + status and
		// restore them on failure — a rejected key is never retained.
		$gpp_route = function ( $key ) {
			$prev_key    = get_option( 'gen_premium_license_key' );
			$prev_status = get_option( 'gen_premium_license_key_status' );
			$req         = new WP_REST_Request( 'POST', '/generatepress-pro/v1/license' );
			$req->set_param( 'key', $key );
			$res  = rest_do_request( $req );
			$data = json_decode( wp_json_encode( $res->get_data() ), true );
			$ok   = is_array( $data ) && ! empty( $data['success'] );
			$msg  = ( is_array( $data ) && isset( $data['response'] ) && is_string( $data['response'] ) ) ? wp_strip_all_tags( $data['response'] ) : '';
			$code = '';
			if ( ! $ok ) {
				$code = ( false !== stripos( $msg, 'activation limit' ) ) ? 'site_limit' : ( false !== stripos( $msg, 'expired' ) ? 'expired' : 'invalid' );
				// Only restore when this was a real activation attempt (a
				// non-empty key); the deactivate path ('') legitimately clears.
				if ( '' !== $key && '***' !== $key ) {
					false === $prev_key ? delete_option( 'gen_premium_license_key' ) : update_option( 'gen_premium_license_key', $prev_key );
					false === $prev_status ? delete_option( 'gen_premium_license_key_status' ) : update_option( 'gen_premium_license_key_status', $prev_status );
				}
			}
			return array( 'ok' => $ok, 'code' => $code, 'message' => $ok ? '' : $msg );
		};
		$providers['gp-premium']['secret_label'] = __( 'GP Premium license key', 'minn-admin' );
		$providers['gp-premium']['activate']     = function ( $secret ) use ( $gpp_route ) {
			return $gpp_route( $secret );
		};
		$providers['gp-premium']['deactivate'] = function () use ( $gpp_route ) {
			return $gpp_route( '' );
		};
		$providers['gp-premium']['verify'] = function () use ( $gpp_route ) {
			if ( ! get_option( 'gen_premium_license_key' ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key stored', 'minn-admin' ) );
			}
			return $gpp_route( '***' );
		};
	}

	// WP All Export Pro: their LicenseActivator (no constructor deps) reads
	// the stored key, calls home and stores the status — so store the key
	// first, their way (updateOption salt-wraps it).
	if ( class_exists( 'PMXE_Plugin' ) && class_exists( '\Wpae\App\Service\License\LicenseActivator' ) ) {
		$pmxe_result = function ( $word ) {
			$ok = in_array( (string) $word, array( 'valid', 'active' ), true );
			if ( $ok ) {
				return array( 'ok' => true );
			}
			$code = 'expired' === $word ? 'expired' : ( 'no_activations_left' === $word ? 'site_limit' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => $word ? str_replace( '_', ' ', (string) $word ) : __( 'wpallimport.com did not confirm the license', 'minn-admin' ) );
		};
		$providers['wp-all-export']['secret_label'] = __( 'WP All Export license key', 'minn-admin' );
		$providers['wp-all-export']['activate']     = function ( $secret ) use ( $pmxe_result ) {
			// Their activator reads the stored key, so it must be written
			// first; snapshot the prior key + status and restore them on
			// failure (paste-never-retain — no leftover bogus key).
			$prev = PMXE_Plugin::getInstance()->getOption();
			PMXE_Plugin::getInstance()->updateOption( 'license', trim( $secret ) );
			( new \Wpae\App\Service\License\LicenseActivator() )->activateLicense( PMXE_Plugin::getEddName(), \Wpae\App\Service\License\LicenseActivator::CONTEXT_PMXE );
			$o      = get_option( 'PMXE_Plugin_Options' );
			$result = $pmxe_result( is_array( $o ) && isset( $o['license_status'] ) ? $o['license_status'] : '' );
			if ( empty( $result['ok'] ) ) {
				PMXE_Plugin::getInstance()->updateOption( array(
					'license'        => isset( $prev['license'] ) ? $prev['license'] : '',
					'license_status' => isset( $prev['license_status'] ) ? $prev['license_status'] : '',
				) );
			}
			return $result;
		};
		$providers['wp-all-export']['verify'] = function () use ( $pmxe_result ) {
			$word = ( new \Wpae\App\Service\License\LicenseActivator() )->checkLicense( PMXE_Plugin::getEddName(), PMXE_Plugin::getInstance()->getOption(), \Wpae\App\Service\License\LicenseActivator::CONTEXT_PMXE );
			if ( false === $word ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not reach wpallimport.com (or no key is stored)', 'minn-admin' ) );
			}
			PMXE_Plugin::getInstance()->updateOption( 'license_status', (string) $word );
			return $pmxe_result( $word );
		};
		$providers['wp-all-export']['deactivate'] = function () {
			// Soflyy licenses are unlimited-activation (license_limit 0):
			// their endpoint answers a deactivate_license request with the
			// plain license status and releases nothing, PROVEN 2026-07-11
			// against a real key. There is no seat to free; the honest
			// deactivate is removing the key from this site (their own
			// button only flips local state on a 'deactivated' word the
			// service never sends for these licenses).
			PMXE_Plugin::getInstance()->updateOption( array( 'license' => '', 'license_status' => '' ) );
			return array( 'ok' => true, 'code' => '', 'message' => __( 'The key was removed from this site. Soflyy licenses have no per-site seats to free.', 'minn-admin' ) );
		};
	}

	// WP All Import Pro: its whole license lifecycle (activate, deactivate)
	// lives in $_POST-driven protected controller methods with no public
	// callable, so these mirror those requests byte for byte through the
	// shared Soflyy helper: the class-keyed licenses/statuses maps in
	// PMXI_Plugin_Options are the storage (keys stored raw, verified against
	// their own form save), and the status word is stored exactly as their
	// controller stores it. Verify rides their public static check.
	if ( class_exists( 'PMXI_Plugin' ) && class_exists( 'PMXI_Admin_License' ) ) {
		$pmxi_word = function ( $word ) {
			$ok = in_array( (string) $word, array( 'valid', 'active' ), true );
			return array( 'ok' => $ok, 'code' => $ok ? '' : ( 'expired' === $word ? 'expired' : ( 'no_activations_left' === $word ? 'site_limit' : 'invalid' ) ), 'message' => $ok ? '' : ( $word ? str_replace( '_', ' ', (string) $word ) : __( 'wpallimport.com did not answer', 'minn-admin' ) ) );
		};
		$providers['wp-all-import']['secret_label'] = __( 'WP All Import license key', 'minn-admin' );
		$providers['wp-all-import']['activate']     = function ( $secret ) use ( $pmxi_word ) {
			$o    = PMXI_Plugin::getInstance()->getOption();
			$prev = array(
				'license' => isset( $o['licenses']['PMXI_Plugin'] ) ? $o['licenses']['PMXI_Plugin'] : '',
				'status'  => isset( $o['statuses']['PMXI_Plugin'] ) ? $o['statuses']['PMXI_Plugin'] : '',
			);
			$word = minn_admin_soflyy_edd( 'activate_license', trim( (string) $secret ), PMXI_Plugin::getEddName(), isset( $o['info_api_url_new'] ) ? (string) $o['info_api_url_new'] : '' );
			$out  = $pmxi_word( $word );
			// GOTCHA: updateOption salt+base64-wraps every value in the
			// licenses map, INCLUDING '' (which becomes a truthy blob the
			// reader mistakes for a stored key) — an empty key must be
			// UNSET, never written as ''.
			$keep = $out['ok'] ? trim( (string) $secret ) : (string) $prev['license'];
			if ( '' === $keep ) {
				unset( $o['licenses']['PMXI_Plugin'] );
			} else {
				$o['licenses']['PMXI_Plugin'] = $keep;
			}
			$o['statuses']['PMXI_Plugin'] = $out['ok'] ? (string) $word : $prev['status'];
			PMXI_Plugin::getInstance()->updateOption( $o );
			return $out;
		};
		$providers['wp-all-import']['deactivate']   = function () {
			// Same as WP All Export: no per-site seats (proven live), so
			// deactivation is local key removal. UNSET, never '' — their
			// option layer wraps '' into a truthy blob (see activate).
			$o = PMXI_Plugin::getInstance()->getOption();
			unset( $o['licenses']['PMXI_Plugin'] );
			$o['statuses']['PMXI_Plugin'] = '';
			PMXI_Plugin::getInstance()->updateOption( $o );
			return array( 'ok' => true, 'code' => '', 'message' => __( 'The key was removed from this site. Soflyy licenses have no per-site seats to free.', 'minn-admin' ) );
		};
		$providers['wp-all-import']['verify'] = function () use ( $pmxi_word ) {
			// NOT their PMXI_Admin_License::check_license static: it sends
			// the stored key still salt+base64-wrapped (their option layer
			// encodes on write and that static never decodes), so it always
			// answers 'invalid' for a wrapped key. Decode, then check.
			$o   = PMXI_Plugin::getInstance()->getOption();
			$key = PMXI_Plugin::decode( isset( $o['licenses']['PMXI_Plugin'] ) ? (string) $o['licenses']['PMXI_Plugin'] : '' );
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'No key is stored.', 'minn-admin' ) );
			}
			$word = minn_admin_soflyy_edd( 'check_license', $key, PMXI_Plugin::getEddName(), isset( $o['info_api_url_new'] ) ? (string) $o['info_api_url_new'] : '' );
			$out  = $pmxi_word( $word );
			$o['statuses']['PMXI_Plugin'] = (string) $word;
			PMXI_Plugin::getInstance()->updateOption( $o );
			return $out;
		};
	}

	// (Slider Revolution's full actions are attached above — the admin-only
	// classes turned out to be two plain include_once's away, proven with a
	// real purchase code 2026-07-11.)

	// LayerSlider: the global updater instance exposes handleActivation
	// with an explicit skip-referer mode (their own re-validation path
	// uses it). Its deactivation handler die()s mid-request, so releasing
	// the seat stays on their screen; verify re-runs activation with the
	// stored code, which IS their re-validation flow.
	if ( ! empty( $GLOBALS['LS_AutoUpdate'] ) && method_exists( $GLOBALS['LS_AutoUpdate'], 'handleActivation' ) ) {
		$ls_activate = function ( $code ) {
			$json = $GLOBALS['LS_AutoUpdate']->handleActivation( $code, array( 'skipRefererCheck' => true, 'returnData' => true ) );
			if ( is_object( $json ) && empty( $json->errCode ) ) {
				return array( 'ok' => true );
			}
			$msg  = ( is_object( $json ) && ! empty( $json->message ) ) ? wp_strip_all_tags( (string) $json->message ) : __( 'Kreatura did not accept that license key', 'minn-admin' );
			$code = ( false !== stripos( $msg, 'another site' ) || false !== stripos( $msg, 'in use' ) || false !== stripos( $msg, 'limit' ) ) ? 'site_limit' : 'invalid';
			return array( 'ok' => false, 'code' => $code, 'message' => $msg );
		};
		$providers['layerslider']['secret_label'] = __( 'LayerSlider license key', 'minn-admin' );
		$providers['layerslider']['activate']     = $ls_activate;
		$providers['layerslider']['verify']       = function () use ( $ls_activate ) {
			$code = get_option( 'layerslider-purchase-code', '' );
			if ( '' === $code ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No license key stored', 'minn-admin' ) );
			}
			return $ls_activate( $code );
		};
	}

	// Smash Balloon: EDD activate/deactivate/check against smashballoon.com
	// using each product's own item_name. Snapshot-restore key+status on a
	// rejected activate so a bad paste never clobbers a working seat.
	foreach ( minn_admin_license_smash_products() as $pid => $sp ) {
		if ( empty( $providers[ $pid ] ) ) {
			continue;
		}
		// Actions need the product loaded so constants/option writers match
		// the vendor's own storage shape.
		if ( empty( $sp['item_const'] ) || ! defined( $sp['item_const'] ) ) {
			continue;
		}
		/* translators: %s: name of the licensed product. */
		$providers[ $pid ]['secret_label'] = sprintf( __( '%s license key', 'minn-admin' ), $sp['name'] );
		$providers[ $pid ]['activate']     = function ( $secret ) use ( $sp ) {
			$secret = trim( (string) $secret );
			$item   = minn_admin_smash_item_name( $sp );
			if ( '' === $secret || '' === $item ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Missing license key or product name', 'minn-admin' ) );
			}
			$snap = minn_admin_smash_read_store( $sp );
			$remote = minn_admin_smash_edd( 'activate_license', $secret, $item );
			$out    = minn_admin_smash_classify( $remote );
			if ( ! empty( $out['ok'] ) ) {
				minn_admin_smash_write_store( $sp, $secret, $remote );
				return $out;
			}
			// Snapshot-restore: never leave a rejected key as "active".
			if ( '' !== $snap['key'] ) {
				if ( 'info' === $sp['storage'] ) {
					update_option(
						$sp['info_opt'],
						array(
							'key'     => $snap['key'],
							'status'  => $snap['status'],
							'expires' => $snap['expires'],
						)
					);
				} elseif ( 'settings' === $sp['storage'] ) {
					$settings = get_option( $sp['settings_opt'], array() );
					if ( ! is_array( $settings ) ) {
						$settings = array();
					}
					$settings[ $sp['key_field'] ]    = $snap['key'];
					$settings[ $sp['status_field'] ] = $snap['status'];
					update_option( $sp['settings_opt'], $settings );
				} else {
					update_option( $sp['key_opt'], $snap['key'] );
					update_option( $sp['status_opt'], $snap['status'] );
					if ( $snap['data'] ) {
						update_option( $sp['data_opt'], $snap['data'] );
					}
				}
			} else {
				// No prior key: ensure we did not partially write on a weird path.
				// activate_license only writes on success above, so nothing to undo.
			}
			return $out;
		};
		$providers[ $pid ]['deactivate'] = function () use ( $sp ) {
			$store = minn_admin_smash_read_store( $sp );
			$item  = minn_admin_smash_item_name( $sp );
			if ( '' === $store['key'] || '' === $item ) {
				minn_admin_smash_clear_store( $sp );
				return array( 'ok' => true, 'message' => __( 'No active Smash Balloon license on this product', 'minn-admin' ) );
			}
			$remote = minn_admin_smash_edd( 'deactivate_license', $store['key'], $item );
			minn_admin_smash_clear_store( $sp, $remote );
			return array( 'ok' => true, 'message' => __( 'License deactivated for ', 'minn-admin' ) . $sp['name'] );
		};
		$providers[ $pid ]['verify'] = function () use ( $sp ) {
			$store = minn_admin_smash_read_store( $sp );
			$item  = minn_admin_smash_item_name( $sp );
			if ( '' === $store['key'] || '' === $item ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'No key is stored', 'minn-admin' ) );
			}
			$remote = minn_admin_smash_edd( 'check_license', $store['key'], $item );
			$out    = minn_admin_smash_classify( $remote );
			if ( ! empty( $out['ok'] ) ) {
				minn_admin_smash_write_store( $sp, $store['key'], $remote );
			} elseif ( $remote ) {
				// Record the failed status without clearing the key.
				if ( 'triple' === $sp['storage'] && isset( $remote['license'] ) ) {
					update_option( $sp['status_opt'], (string) $remote['license'] );
					update_option( $sp['data_opt'], $remote );
				} elseif ( 'settings' === $sp['storage'] && isset( $remote['license'] ) ) {
					$settings = get_option( $sp['settings_opt'], array() );
					if ( ! is_array( $settings ) ) {
						$settings = array();
					}
					$settings[ $sp['status_field'] ] = (string) $remote['license'];
					update_option( $sp['settings_opt'], $settings );
				} elseif ( 'info' === $sp['storage'] && isset( $remote['license'] ) ) {
					update_option(
						$sp['info_opt'],
						array(
							'key'     => $store['key'],
							'status'  => (string) $remote['license'],
							'expires' => isset( $remote['expires'] ) ? (string) $remote['expires'] : '',
						)
					);
				}
			}
			return $out;
		};
	}

	// Rank Math: activation is the rankmath.com portal handshake owned by
	// the FREE plugin — link to its registration screen while it is loaded.
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$providers['rank-math-pro']['activate_url'] = admin_url( 'admin.php?page=rank-math&view=registration' );
	}

	// Yoast SEO Premium: MyYoast portal only (no paste). Link to free Yoast's
	// Licenses / Plans page while free is loaded; verify re-fetches MyYoast
	// site info by clearing the addon manager's site_information transients.
	if ( class_exists( 'WPSEO_Addon_Manager' ) && ! empty( $providers['yoast-seo-premium'] ) ) {
		$providers['yoast-seo-premium']['activate_url'] = admin_url( 'admin.php?page=wpseo_licenses' );
		$providers['yoast-seo-premium']['verify']       = function () {
			try {
				$mgr = new WPSEO_Addon_Manager();
				if ( method_exists( $mgr, 'remove_site_information_transients' ) ) {
					$mgr->remove_site_information_transients();
				} else {
					delete_transient( WPSEO_Addon_Manager::SITE_INFORMATION_TRANSIENT );
					delete_transient( WPSEO_Addon_Manager::SITE_INFORMATION_TRANSIENT_QUICK );
				}
				$slug = WPSEO_Addon_Manager::PREMIUM_SLUG;
				// Force a fresh MyYoast read (get_myyoast_site_information
				// refills the cleared transient).
				if ( method_exists( $mgr, 'get_myyoast_site_information' ) ) {
					$mgr->get_myyoast_site_information();
				}
				if ( $mgr->has_valid_subscription( $slug ) ) {
					return array( 'ok' => true, 'message' => __( 'MyYoast reports a valid Premium subscription', 'minn-admin' ) );
				}
				$sub = $mgr->get_subscription( $slug );
				if ( ! $sub ) {
					return array( 'ok' => false, 'code' => 'missing', 'message' => __( 'No Premium subscription linked to this site on MyYoast', 'minn-admin' ) );
				}
				return array( 'ok' => false, 'code' => 'invalid', 'message' => __( 'MyYoast reports the Premium subscription is inactive or expired', 'minn-admin' ) );
			} catch ( \Throwable $e ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => __( 'Could not refresh MyYoast subscription state', 'minn-admin' ) );
			}
		};
	}

	// Envato Market: token entry is a nonce-coupled Settings-API screen.
	if ( defined( 'ENVATO_MARKET_VERSION' ) ) {
		$providers['envato-market']['activate_url'] = admin_url( 'admin.php?page=' . ( defined( 'ENVATO_MARKET_SLUG' ) ? ENVATO_MARKET_SLUG : 'envato-market' ) );
	}

	// WPBakery activation is a token handshake through support.wpbakery.com
	// (no paste-a-code callable exists), so the honest control is a link to
	// its own activation screen.
	if ( isset( $providers['js-composer'] ) ) {
		$providers['js-composer']['activate_url'] = admin_url( 'admin.php?page=vc-updater' );
	}

	return $providers;
}

/**
 * Freemius components from fs_accounts. The SDK ships inside FREE plugins
 * too, so only premium installs (or installs holding a license) count as
 * license-wanting; a free Freemius plugin is not a row.
 */
function minn_admin_licenses_freemius( $fingerprints ) {
	$acc = get_option( 'fs_accounts' );
	if ( ! is_array( $acc ) || empty( $acc ) ) {
		// SDK present but never booted (all its plugins inactive): unknown.
		$out = array();
		foreach ( $fingerprints as $fp ) {
			$out[] = array(
				'name'      => $fp['name'],
				'kind'      => $fp['kind'],
				'state'     => 'unknown',
				'key'       => false,
				'note'      => __( 'Freemius-powered; no account state recorded yet', 'minn-admin' ),
				'component' => $fp['component'],
			);
		}
		return $out;
	}

	// all_licenses: module_id => [ FS_Plugin_License, ... ].
	$licenses_by_module = array();
	if ( ! empty( $acc['all_licenses'] ) && is_array( $acc['all_licenses'] ) ) {
		foreach ( $acc['all_licenses'] as $module_id => $lics ) {
			$licenses_by_module[ (string) $module_id ] = is_array( $lics ) ? $lics : array();
		}
	}

	$out = array();
	foreach ( $fingerprints as $fp ) {
		$sites = ( 'theme' === $fp['kind'] ) ? ( $acc['theme_sites'] ?? array() ) : ( $acc['sites'] ?? array() );
		// Freemius keys sites by ITS product slug, not the install directory
		// ('blocksy-companion-pro/' registers as 'blocksy-companion'); the
		// stored file_slug_map bridges plugin file → product slug.
		$slug = $fp['slug'];
		if ( 'plugin' === $fp['kind'] && ! empty( $acc['file_slug_map'][ $fp['component'] ] ) ) {
			$slug = (string) $acc['file_slug_map'][ $fp['component'] ];
		}
		$site  = is_array( $sites ) ? ( $sites[ $slug ] ?? null ) : null;
		if ( ! $site ) {
			continue; // Not a Freemius-tracked install (or never opted in).
		}
		$is_premium = (bool) minn_admin_license_prop( $site, 'is_premium', false );
		$license_id = minn_admin_license_prop( $site, 'license_id', null );
		if ( ! $is_premium && ! $license_id ) {
			continue; // Free product: nothing to license.
		}
		if ( ! $license_id ) {
			$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => 'missing', 'key' => false, 'note' => __( 'Premium install with no license attached', 'minn-admin' ), 'component' => $fp['component'] );
			continue;
		}
		$module_id = (string) minn_admin_license_prop( $site, 'plugin_id', '' );
		$license   = null;
		foreach ( $licenses_by_module[ $module_id ] ?? array() as $l ) {
			if ( (string) minn_admin_license_prop( $l, 'id', '' ) === (string) $license_id ) {
				$license = $l;
				break;
			}
		}
		if ( ! $license ) {
			$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => 'unknown', 'key' => true, 'note' => __( 'License attached but not readable locally', 'minn-admin' ), 'component' => $fp['component'] );
			continue;
		}
		$raw_exp = minn_admin_license_prop( $license, 'expiration', '' );
		$expires = ( null === $raw_exp || '' === $raw_exp ) ? 'lifetime' : minn_admin_license_expiry( $raw_exp );
		$state   = minn_admin_license_expired( $expires ) ? 'expired' : 'valid';
		$out[]   = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => $state, 'key' => true, 'expires' => $expires, 'component' => $fp['component'] );
	}
	return $out;
}

/**
 * EDD Software Licensing clients: option names are per-plugin convention
 * ({prefix}_license_key / {prefix}_license_status), so pair them against
 * the fingerprinted plugin's slug. The status VOCABULARY is standardized
 * by the EDD server even though option names are not.
 */
function minn_admin_licenses_edd( $fingerprints ) {
	global $wpdb;
	if ( empty( $fingerprints ) ) {
		return array();
	}
	// One bounded sweep for license-shaped options.
	$rows = $wpdb->get_results(
		"SELECT option_name, option_value FROM {$wpdb->options}
		 WHERE ( option_name LIKE '%license_key%' OR option_name LIKE '%license_status%' )
		 AND option_name NOT LIKE '\_transient%' AND LENGTH( option_value ) < 1000 LIMIT 300"
	);
	$opts = array();
	foreach ( (array) $rows as $r ) {
		$opts[ $r->option_name ] = $r->option_value;
	}

	$status_words = array( 'valid', 'invalid', 'expired', 'disabled', 'site_inactive', 'inactive', 'deactivated' );
	$out          = array();
	foreach ( $fingerprints as $fp ) {
		// Normalize slug to a matchable token: dashes → underscores, strip
		// a trailing _pro so 'my-plugin-pro' matches 'my_plugin_license_key'.
		$token = str_replace( '-', '_', strtolower( $fp['slug'] ) );
		$base  = preg_replace( '/_pro$/', '', $token );
		$key_present = false;
		$status      = '';
		foreach ( $opts as $name => $value ) {
			$lname = strtolower( $name );
			if ( false === strpos( $lname, $token ) && false === strpos( $lname, $base ) ) {
				continue;
			}
			if ( false !== strpos( $lname, 'license_key' ) && '' !== trim( (string) $value ) ) {
				$key_present = true;
			}
			if ( false !== strpos( $lname, 'license_status' ) ) {
				$v = trim( (string) $value );
				if ( in_array( strtolower( $v ), $status_words, true ) ) {
					$status = strtolower( $v );
				} elseif ( preg_match( '/"license"\s*[:;]\s*(?:s:\d+:)?"(\w+)"/', $v, $m ) ) {
					// Some clients store the whole check_license response
					// (serialized or JSON); the `license` field is the status.
					$status = strtolower( $m[1] );
				}
			}
		}
		$state = 'unknown';
		$note  = '';
		if ( 'valid' === $status ) {
			$state = 'valid';
		} elseif ( 'expired' === $status ) {
			$state = 'expired';
		} elseif ( '' !== $status ) {
			$state = 'invalid';
			$note  = str_replace( '_', ' ', $status );
		} elseif ( ! $key_present ) {
			$state = 'missing';
		} else {
			$note = 'Key stored; no readable status option';
		}
		$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => $state, 'key' => $key_present, 'note' => $note, 'component' => $fp['component'] );
	}
	return $out;
}

/** SureCart licensing SDK: {name}_license_options + activation id. */
function minn_admin_licenses_surecart( $fingerprints ) {
	global $wpdb;
	$out = array();
	foreach ( $fingerprints as $fp ) {
		$token = str_replace( '-', '_', strtolower( $fp['slug'] ) );
		$opt   = get_option( $token . '_license_options' );
		if ( ! is_array( $opt ) || empty( $opt ) ) {
			// The option key is the SDK client's chosen name, which may not
			// be the slug; sweep for any *_license_options holding sc_ keys.
			$row = $wpdb->get_var(
				"SELECT option_value FROM {$wpdb->options}
				 WHERE option_name LIKE '%\_license\_options' AND option_value LIKE '%sc\_license%' LIMIT 1"
			);
			// Decode without instantiating anything. This row was matched by a
			// wildcard rather than written by us, so whatever plugin owns it
			// chose its contents; the reader only wants a *license_key* string
			// leaf and never needs live objects.
			$opt = ( $row && is_serialized( $row ) )
				? unserialize( $row, array( 'allowed_classes' => false ) ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				: $row;
			$opt = is_array( $opt ) ? $opt : array();
		}
		$key = '';
		foreach ( $opt as $k => $v ) {
			if ( false !== strpos( (string) $k, 'license_key' ) && $v ) {
				$key = (string) $v;
			}
		}
		$out[] = array(
			'name'      => $fp['name'],
			'kind'      => $fp['kind'],
			'state'     => $key ? 'unknown' : 'missing',
			'key'       => (bool) $key,
			'note'      => $key ? 'Activation stored; SureCart keeps no local expiry' : '',
			'component' => $fp['component'],
		);
	}
	return $out;
}

/**
 * Who may read or change this site's licences.
 *
 * On multisite the answer is the network administrator, not a site one:
 * vendor credentials live in NETWORK options (Divi's et_automatic_updates_options,
 * Gravity Perks' gwp_settings), a deactivate is an irreversible seat release
 * at the vendor that the network owner paid for, and the read side carries
 * account identities (the WooCommerce.com address, the Jetpack master login).
 * manage_options is per-site and every subsite administrator holds it.
 *
 * Shared by the read route, the action route and the /system re-export so
 * the three can never drift apart.
 *
 * @return bool
 */
function minn_admin_licenses_can_manage() {
	return is_multisite()
		? current_user_can( 'manage_network_options' )
		: current_user_can( 'manage_options' );
}

/**
 * The assembled license picture: every license-wanting component with a
 * state, worst first. Vendor readers claim their components; SDK scanners
 * cover the rest of the fingerprinted set.
 */
function minn_admin_licenses() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	// License STATE reads from stored options even for inactive components,
	// but actions need the vendor's code loaded — rows for inactive
	// components carry `off` so the UI can dim them and say why there are
	// no controls instead of silently omitting them.
	$component_active = function ( $component ) {
		if ( 0 === strpos( (string) $component, 'theme:' ) ) {
			$slug = substr( $component, 6 );
			return get_stylesheet() === $slug || get_template() === $slug;
		}
		return is_plugin_active( $component );
	};
	// An off row carries its component so the client can offer "Turn on"
	// (activate the plugin / switch to the theme) right on the card — but
	// only when the file is really there to activate and the user holds
	// the matching cap.
	$turn_on_component = function ( $component ) {
		if ( 0 === strpos( (string) $component, 'theme:' ) ) {
			$slug = substr( $component, 6 );
			return current_user_can( 'switch_themes' ) && wp_get_theme( $slug )->exists() ? $component : '';
		}
		return current_user_can( 'activate_plugins' ) && file_exists( WP_PLUGIN_DIR . '/' . $component ) ? $component : '';
	};

	$providers = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
	$items     = array();
	$claimed   = array();
	// Minn's own recorded check outcomes (see the action endpoint): used
	// only to upgrade rows whose vendor keeps no local validity state.
	$checks = get_option( 'minn_admin_license_checks', array() );
	$checks = is_array( $checks ) ? $checks : array();
	foreach ( $providers as $id => $p ) {
		if ( empty( $p['detect'] ) || empty( $p['read'] ) || ! is_callable( $p['detect'] ) || ! is_callable( $p['read'] ) ) {
			continue;
		}
		try {
			if ( ! call_user_func( $p['detect'] ) ) {
				continue;
			}
			$rows = (array) call_user_func( $p['read'] );
		} catch ( \Throwable $e ) {
			continue; // A broken reader never breaks the dashboard.
		}
		if ( ! empty( $p['component'] ) ) {
			$claimed[ $p['component'] ] = true;
		}
		// Which Phase-1 actions this provider offers; the client only draws
		// controls for rows whose provider declares the callable.
		$can = array_values( array_filter( array( 'activate', 'deactivate', 'verify' ), function ( $a ) use ( $p ) {
			return ! empty( $p[ $a ] ) && is_callable( $p[ $a ] );
		} ) );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$row['id']     = sanitize_key( $id . '-' . $row['name'] );
			$row['source'] = (string) $id;
			// Connections-center classification (docs/connections-center.md):
			// license (default, paid seats/updates), key (service credential),
			// connection (account/site link). Drives grouping chips only.
			$row['category'] = in_array( $p['category'] ?? '', array( 'key', 'connection' ), true ) ? $p['category'] : 'license';
			// Upgrade an "unknown" row from Minn's own last check of this
			// provider, honestly timestamped. Never overrides a state the
			// vendor's stored data produced.
			if ( 'unknown' === ( $row['state'] ?? '' ) && ! empty( $row['key'] ) && isset( $checks[ $id ]['time'] ) ) {
				$chk  = $checks[ $id ];
				$when = human_time_diff( (int) $chk['time'] );
				if ( ! empty( $chk['ok'] ) ) {
					$row['state'] = 'valid';
					/* translators: %s: human-readable time difference, such as "2 hours". */
					$row['note']  = sprintf( __( 'verified %s ago from Minn', 'minn-admin' ), $when );
				} else {
					$row['state'] = ( 'expired' === ( $chk['code'] ?? '' ) ) ? 'expired' : 'invalid';
					/* translators: %s: human-readable time difference, such as "2 hours". */
					$row['note']  = sprintf( __( 'failed a check %s ago from Minn', 'minn-admin' ), $when );
				}
			}
			if ( $can ) {
				$row['can']    = $can;
				$row['secret'] = isset( $p['secret_label'] ) ? (string) $p['secret_label'] : __( 'License key', 'minn-admin' );
				if ( ! empty( $p['secret_fields'] ) && is_array( $p['secret_fields'] ) ) {
					$row['secretFields'] = array_values( array_map( function ( $f ) {
						return array( 'id' => sanitize_key( $f['id'] ), 'label' => (string) $f['label'] );
					}, $p['secret_fields'] ) );
				}
			}
			// Vendors with no callable activation path (portal handshakes)
			// can still hand the user a link to their own activation screen.
			if ( ! empty( $p['activate_url'] ) ) {
				$row['activateUrl'] = (string) ( is_callable( $p['activate_url'] ) ? call_user_func( $p['activate_url'] ) : $p['activate_url'] );
			}
			// Registry-style providers (bsf-registry, stellarwp-registry)
			// span several components, so only a real plugin-file or
			// theme: component can dim its rows.
			$component    = isset( $p['component'] ) ? (string) $p['component'] : '';
			$is_component = $component && ( false !== strpos( $component, '/' ) || 0 === strpos( $component, 'theme:' ) );
			if ( $is_component && ! $component_active( $component ) ) {
				$row['off'] = true;
				$on = $turn_on_component( $component );
				if ( $on ) {
					$row['turnOn'] = $on;
				}
			}
			$items[] = $row;
		}
	}

	$by_sdk = array( 'freemius' => array(), 'edd' => array(), 'surecart' => array() );
	foreach ( minn_admin_license_fingerprints() as $fp ) {
		if ( empty( $claimed[ $fp['component'] ] ) && isset( $by_sdk[ $fp['sdk'] ] ) ) {
			$by_sdk[ $fp['sdk'] ][] = $fp;
		}
	}
	foreach ( array(
		'freemius' => minn_admin_licenses_freemius( $by_sdk['freemius'] ),
		'edd'      => minn_admin_licenses_edd( $by_sdk['edd'] ),
		'surecart' => minn_admin_licenses_surecart( $by_sdk['surecart'] ),
	) as $sdk => $rows ) {
		foreach ( $rows as $row ) {
			$row['id']     = sanitize_key( $sdk . '-' . $row['name'] );
			$row['source'] = $sdk;
			if ( ! empty( $row['component'] ) && ! $component_active( $row['component'] ) ) {
				$row['off'] = true;
				$on = $turn_on_component( $row['component'] );
				if ( $on ) {
					$row['turnOn'] = $on;
				}
			}
			unset( $row['component'] );
			$row           = wp_parse_args( $row, array( 'expires' => '', 'note' => '', 'stale' => false, 'key' => false ) );
			$items[]       = $row;
		}
	}

	$rank = array( 'expired' => 0, 'invalid' => 1, 'missing' => 2, 'unknown' => 3, 'valid' => 4 );
	usort( $items, function ( $a, $b ) use ( $rank ) {
		$d = ( $rank[ $a['state'] ] ?? 5 ) - ( $rank[ $b['state'] ] ?? 5 );
		return $d ? $d : strcasecmp( $a['name'], $b['name'] );
	} );

	$summary = array( 'valid' => 0, 'expired' => 0, 'invalid' => 0, 'missing' => 0, 'unknown' => 0 );
	foreach ( $items as $it ) {
		if ( isset( $summary[ $it['state'] ] ) ) {
			$summary[ $it['state'] ]++;
		}
	}

	// Core connectors (WP 7.0 wp_get_connectors) join as READ-ONLY
	// connection rows AFTER the summary, so an AI provider with no key never
	// tips the System health check — editing stays in Settings → Connectors
	// (the client renders a doorway). Only real relationships list: a
	// connector appears when its key resolves (env/constant/database, core's
	// own precedence) or its companion plugin is active; the uninstalled
	// catalog stays out of the inventory.
	if ( function_exists( 'wp_get_connectors' ) ) {
		foreach ( wp_get_connectors() as $cid => $c ) {
			try {
				// Dedicated key providers already list these (spam cards +
				// Licenses rows). The Connectors section is still the editor.
				if ( in_array( (string) $cid, array( 'akismet', 'cleantalk' ), true ) ) {
					continue;
				}
				$auth    = isset( $c['authentication'] ) && is_array( $c['authentication'] ) ? $c['authentication'] : array();
				$env     = isset( $auth['env_var_name'] ) ? (string) $auth['env_var_name'] : '';
				$const   = isset( $auth['constant_name'] ) ? (string) $auth['constant_name'] : '';
				$setting = isset( $auth['setting_name'] ) ? (string) $auth['setting_name'] : '';
				$source  = 'none';
				if ( '' !== $env && false !== getenv( $env ) && '' !== getenv( $env ) ) {
					$source = 'env';
				} elseif ( '' !== $const && defined( $const ) && is_string( constant( $const ) ) && '' !== constant( $const ) ) {
					$source = 'constant';
				} elseif ( '' !== $setting && '' !== (string) get_option( $setting, '' ) ) {
					$source = 'database';
				}
				$plugin_active = false;
				if ( ! empty( $c['plugin']['file'] ) && is_string( $c['plugin']['file'] ) ) {
					if ( isset( $c['plugin']['is_active'] ) && is_callable( $c['plugin']['is_active'] ) ) {
						$plugin_active = (bool) call_user_func( $c['plugin']['is_active'] );
					} else {
						$plugin_active = is_plugin_active( $c['plugin']['file'] );
					}
				}
				if ( 'none' === $source && ! $plugin_active ) {
					continue;
				}
				$items[] = array(
					'name'     => isset( $c['name'] ) ? (string) $c['name'] : (string) $cid,
					'kind'     => 'plugin',
					'state'    => 'none' !== $source ? 'valid' : 'missing',
					'key'      => 'none' !== $source,
					'expires'  => '',
					'note'     => __( 'core connector', 'minn-admin' ) . ( 'database' === $source || 'none' === $source ? '' : '; key from ' . ( 'env' === $source ? 'the server environment' : 'a constant' ) ),
					'stale'    => false,
					'id'       => sanitize_key( 'core-connector-' . $cid ),
					'source'   => 'core-connectors',
					'category' => 'connection',
				);
			} catch ( \Throwable $e ) {
				continue; // one broken connector never breaks the inventory.
			}
		}
	}

	return array(
		'generated' => current_time( 'c' ),
		'items'     => $items,
		'summary'   => $summary,
	);
}

/**
 * Normalize whatever a provider action returns into { ok, code, message }.
 * Codes: '' (fine), 'invalid', 'site_limit', 'expired', 'error'. A vendor
 * WP_Error or thrown Throwable becomes a plain 'error' result; nothing a
 * provider does can take the endpoint down.
 */
function minn_admin_license_result( $raw ) {
	if ( is_wp_error( $raw ) ) {
		return array( 'ok' => false, 'code' => 'error', 'message' => $raw->get_error_message() );
	}
	if ( is_bool( $raw ) || null === $raw ) {
		return array( 'ok' => (bool) $raw, 'code' => $raw ? '' : 'error', 'message' => '' );
	}
	if ( is_array( $raw ) ) {
		return array(
			'ok'      => ! empty( $raw['ok'] ),
			'code'    => isset( $raw['code'] ) ? (string) $raw['code'] : ( empty( $raw['ok'] ) ? 'error' : '' ),
			'message' => isset( $raw['message'] ) ? (string) $raw['message'] : '',
		);
	}
	return array( 'ok' => false, 'code' => 'error', 'message' => '' );
}

/**
 * Flush vendor-side update caches that would otherwise hide a just-unlocked
 * commercial update. Gravity SMTP (and Gravity Tools siblings) are the hard
 * case: their Auto_Updater freezes the license key on a Common object at
 * container build, and version offerings sit in GFCache for a day. Their own
 * plugins.php / update-core.php screens flush that cache; Minn has to do the
 * same, plus refresh the Common key mid-request after an activate.
 */
function minn_admin_flush_vendor_update_caches() {
	// Gravity SMTP — request-scoped Common key + DAY GFCache of offerings.
	// Their Utils_Service_Provider lives in the shared Gravity_Tools ns
	// (vendored into gravitysmtp), not under Gravity_SMTP\Utils.
	if ( class_exists( '\Gravity_Forms\Gravity_SMTP\Gravity_SMTP' )
		&& class_exists( '\Gravity_Forms\Gravity_Tools\Utils\Utils_Service_Provider' )
		&& class_exists( '\Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider' )
		&& class_exists( '\Gravity_Forms\Gravity_Tools\Updates\Updates_Service_Provider' ) ) {
		try {
			$c = \Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container();
			// Re-read the key the activate path just wrote — Common is a
			// singleton built at request start with the previous (empty)
			// value, so get_key() would otherwise still be ''.
			if ( method_exists( $c, 'get' ) ) {
				$common = $c->get( \Gravity_Forms\Gravity_Tools\Utils\Utils_Service_Provider::COMMON );
				$router = $c->get( \Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::DATA_STORE_ROUTER );
				$key    = '';
				if ( $router && method_exists( $router, 'get_plugin_setting' ) ) {
					$key = (string) $router->get_plugin_setting( 'license_key', '' );
				}
				if ( $common && is_object( $common ) ) {
					$ref = new \ReflectionObject( $common );
					if ( $ref->hasProperty( 'key' ) ) {
						$prop = $ref->getProperty( 'key' );
						// Needed on PHP 7.4/8.0, a no-op since 8.1 and a
						// deprecation warning on 8.5 — hence the guard.
						if ( PHP_VERSION_ID < 80100 ) {
							$prop->setAccessible( true );
						}
						$prop->setValue( $common, $key );
					}
				}
				// Drop the DAY offerings cache (mirrors their plugins.php
				// "always show updates when you visit" filter).
				$cache = $c->get( \Gravity_Forms\Gravity_Tools\Utils\Utils_Service_Provider::CACHE );
				if ( $cache && method_exists( $cache, 'delete' ) ) {
					$cache->delete( 'gsmtp_gforms_plugins' );
				}
				// License connector keeps static request caches too.
				$conn = $c->get( \Gravity_Forms\Gravity_Tools\Updates\Updates_Service_Provider::LICENSE_API_CONNECTOR );
				if ( $conn ) {
					$cr = new \ReflectionClass( $conn );
					foreach ( array( 'plugins', 'license_info' ) as $static ) {
						if ( $cr->hasProperty( $static ) ) {
							$sp = $cr->getProperty( $static );
							// Same PHP 7.4/8.0-only requirement as above.
							if ( PHP_VERSION_ID < 80100 ) {
								$sp->setAccessible( true );
							}
							$sp->setValue( null, null );
						}
					}
				}
			}
			delete_option( 'gsmtp_version_info' );
		} catch ( \Throwable $e ) {
			// Best-effort; never break the update check.
		}
	}

	// Gravity Forms core: its version_info option is the equivalent cache.
	// get_version_info( false ) is what their own force-check does.
	if ( class_exists( 'GFCommon' ) && method_exists( 'GFCommon', 'get_version_info' ) ) {
		try {
			// Their helper with cache=false hits the API and rewrites the option.
			\GFCommon::get_version_info( false );
		} catch ( \Throwable $e ) {
			// ignore
		}
	}

	/**
	 * Let third-party adapters flush their own update caches when Minn
	 * force-checks. Fire before wp_update_plugins so their
	 * site_transient_update_plugins filters see fresh data.
	 */
	do_action( 'minn_admin_flush_vendor_update_caches' );
}

/**
 * Force a fresh plugins (and themes) update check. Commercial plugins often
 * only report updates once a license is stored (the key rides their request),
 * so a just-activated license would otherwise wait up to 12h for core's next
 * check. Clears the site transients first so wp_update_* actually re-hits the
 * endpoints (core short-circuits when last_checked is still fresh).
 *
 * @param string $component Optional 'dir/file.php' or 'theme:slug' to pick
 *                          out that one component's available update.
 * @return array {
 *   @type array      $plugins Map of plugin_file => new_version.
 *   @type array      $themes  Map of stylesheet => new_version.
 *   @type array|null $update  The component's pending update, or null.
 * }
 */
function minn_admin_force_update_check( $component = '' ) {
	$out = array(
		'plugins' => array(),
		'themes'  => array(),
		'update'  => null,
	);
	$component = is_string( $component ) ? $component : '';
	$is_theme  = 0 === strpos( $component, 'theme:' );
	$stylesheet = $is_theme ? substr( $component, 6 ) : '';

	// Vendor caches BEFORE the core check — Gravity SMTP's Auto_Updater
	// only injects into the transient when its Common key + offerings
	// cache already know about the license.
	minn_admin_flush_vendor_update_caches();

	if ( current_user_can( 'update_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		delete_site_transient( 'update_plugins' );
		// Extra stats force core past the "nothing changed" short-circuit
		// even if something re-seeds the transient mid-request.
		wp_update_plugins( array( 'minn_admin' => 1 ) );
		$tr = get_site_transient( 'update_plugins' );
		if ( $tr && ! empty( $tr->response ) && is_array( $tr->response ) ) {
			foreach ( $tr->response as $file => $data ) {
				if ( is_object( $data ) && ! empty( $data->new_version ) ) {
					$out['plugins'][ $file ] = (string) $data->new_version;
				}
			}
		}
	}

	// Themes: always refresh when the component is a theme; otherwise only
	// if the caller is doing a full check (empty component).
	if ( current_user_can( 'update_themes' ) && ( $is_theme || '' === $component ) ) {
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		delete_site_transient( 'update_themes' );
		wp_update_themes( array( 'minn_admin' => 1 ) );
		$tr = get_site_transient( 'update_themes' );
		if ( $tr && ! empty( $tr->response ) && is_array( $tr->response ) ) {
			foreach ( $tr->response as $slug => $data ) {
				$ver = is_array( $data ) ? (string) ( $data['new_version'] ?? '' ) : '';
				if ( $ver ) {
					$out['themes'][ $slug ] = $ver;
				}
			}
		}
	}

	if ( $component && $is_theme && $stylesheet && ! empty( $out['themes'][ $stylesheet ] ) ) {
		$theme = wp_get_theme( $stylesheet );
		$out['update'] = array(
			'kind'    => 'theme',
			'file'    => $stylesheet,
			'name'    => $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet,
			'version' => $out['themes'][ $stylesheet ],
			'current' => $theme->exists() ? (string) $theme->get( 'Version' ) : '',
		);
	} elseif ( $component && ! $is_theme && ! empty( $out['plugins'][ $component ] ) ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all  = get_plugins();
		$name = isset( $all[ $component ]['Name'] ) ? (string) $all[ $component ]['Name'] : $component;
		$cur  = isset( $all[ $component ]['Version'] ) ? (string) $all[ $component ]['Version'] : '';
		$out['update'] = array(
			'kind'    => 'plugin',
			'file'    => $component,
			'name'    => $name,
			'version' => $out['plugins'][ $component ],
			'current' => $cur,
		);
	}

	return $out;
}

add_action( 'rest_api_init', function () {
	// Licences are NETWORK-scoped on multisite: the credentials live in site
	// options, the seats belong to whoever bought them, and releasing one is
	// an irreversible call to the vendor. manage_options is per-site, so
	// without this any subsite administrator could spend or wipe the network
	// owner's licences, and read their account identities. This is the same
	// boundary can_read_logs() and the database viewer already draw.
	$can = 'minn_admin_licenses_can_manage';
	register_rest_route(
		'minn-admin/v1',
		'/licenses',
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function () {
				return rest_ensure_response( minn_admin_licenses() );
			},
		)
	);
	register_rest_route(
		'minn-admin/v1',
		'/licenses/action',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $req ) {
				$provider_id = sanitize_key( (string) $req->get_param( 'provider' ) );
				$action      = (string) $req->get_param( 'action' );
				// The secret is used for this one call and never stored,
				// logged or echoed back.
				$secret = trim( (string) $req->get_param( 'secret' ) );
				if ( ! in_array( $action, array( 'activate', 'deactivate', 'verify' ), true ) ) {
					return new WP_Error( 'bad_action', __( 'Unknown action.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$providers = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
				$p         = isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : null;
				if ( ! $p || empty( $p[ $action ] ) || ! is_callable( $p[ $action ] ) ) {
					return new WP_Error( 'no_provider', __( 'No such action for this provider.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Multi-secret providers (Divi's username + API key) declare
				// secret_fields and receive an id-keyed array; single-secret
				// providers receive the plain string. Secrets are never
				// stored, logged or echoed back either way.
				$payload = $secret;
				if ( 'activate' === $action && ! empty( $p['secret_fields'] ) && is_array( $p['secret_fields'] ) ) {
					$secrets = $req->get_param( 'secrets' );
					$payload = array();
					foreach ( $p['secret_fields'] as $f ) {
						$fid = sanitize_key( $f['id'] );
						$val = is_array( $secrets ) && isset( $secrets[ $fid ] ) ? trim( (string) $secrets[ $fid ] ) : '';
						if ( '' === $val ) {
							return new WP_Error( 'no_secret', __( 'Fill in every field first.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						$payload[ $fid ] = $val;
					}
				} elseif ( 'activate' === $action && '' === $secret ) {
					return new WP_Error( 'no_secret', __( 'Paste a key first.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				try {
					$raw = ( 'activate' === $action )
						? call_user_func( $p[ $action ], $payload )
						: call_user_func( $p[ $action ] );
					$result = minn_admin_license_result( $raw );
				} catch ( \Throwable $e ) {
					// Vendor exceptions can carry markup or PHP-error noise;
					// strip to plain text like every deliberate vendor message.
					$msg    = trim( wp_strip_all_tags( (string) $e->getMessage() ) );
					$result = array( 'ok' => false, 'code' => 'error', 'message' => '' !== $msg ? $msg : __( 'The plugin reported an error during the request.', 'minn-admin' ) );
				}
				// Some vendors (Gravity SMTP, Brizy) keep no local validity
				// state, so their rows would read "unknown" forever even
				// after a successful activation.
				// Minn remembers ITS OWN check outcome per provider — the
				// status word and a timestamp, never a key — and the read
				// side upgrades unknown rows from it with "as of" honesty.
				$checks = get_option( 'minn_admin_license_checks', array() );
				$checks = is_array( $checks ) ? $checks : array();
				if ( 'deactivate' === $action && ! empty( $result['ok'] ) ) {
					unset( $checks[ $provider_id ] );
					update_option( 'minn_admin_license_checks', $checks, false );
				} elseif ( 'verify' === $action || ( 'activate' === $action && ! empty( $result['ok'] ) ) ) {
					// A failed activate leaves the vendor's stored state
					// unchanged, so the previous memory stays truthful.
					$checks[ $provider_id ] = array(
						'ok'   => ! empty( $result['ok'] ),
						'code' => isset( $result['code'] ) ? (string) $result['code'] : '',
						'time' => time(),
					);
					update_option( 'minn_admin_license_checks', $checks, false );
				}
				// Fresh classification rides along so the client repaints
				// from the vendor's now-current stored state in one round trip.
				$result['licenses'] = minn_admin_licenses();

				// After a successful activate (or re-verify), force a fresh
				// update check. Licensed commercial plugins (Gravity SMTP,
				// Elementor Pro, …) only report updates once a key is stored;
				// without this the user would wait for core's 12h cadence or
				// leave Minn for wp-admin's "Check again". The response
				// carries the component's pending update (if any) plus full
				// maps so the client can refresh badges/notifications without
				// a second round trip.
				if ( ! empty( $result['ok'] ) && in_array( $action, array( 'activate', 'verify' ), true ) ) {
					$component = isset( $p['component'] ) ? (string) $p['component'] : '';
					try {
						$checked = minn_admin_force_update_check( $component );
						$result['updates_checked'] = true;
						$result['pluginUpdates']   = $checked['plugins'];
						$result['themeUpdates']    = $checked['themes'];
						if ( ! empty( $checked['update'] ) ) {
							$result['update'] = $checked['update'];
						}
					} catch ( \Throwable $e ) {
						// Update check is best-effort; never fail the license action.
						$result['updates_checked'] = false;
					}
				}

				return rest_ensure_response( $result );
			},
		)
	);

	// Explicit "Check for updates" (Extensions toolbar / palette). Same
	// force path as post-activate; returns fresh plugin + theme maps.
	register_rest_route(
		'minn-admin/v1',
		'/check-updates',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'update_plugins' ) || current_user_can( 'update_themes' ) || current_user_can( 'update_languages' );
			},
			'callback'            => function () {
				try {
					$checked = minn_admin_force_update_check( '' );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'check_failed', __( 'Could not check for updates.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$translations = Minn_Admin_REST::translation_update_summary();
				return rest_ensure_response( array(
					'ok'                => true,
					'pluginUpdates'     => $checked['plugins'],
					'themeUpdates'      => $checked['themes'],
					'translations'      => $translations['count'],
					'translationGroups' => $translations['groups'],
					'plugins'           => count( $checked['plugins'] ),
					'themes'            => count( $checked['themes'] ),
				) );
			},
		)
	);
} );
