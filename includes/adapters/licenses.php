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
 *                   'note'    => 'Optional one-line detail',
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
		'full'    => array( 'valid', 'full membership' ),
		'unit'    => array( 'valid', 'per-plugin membership' ),
		'single'  => array( 'valid', 'single-project membership' ),
		'free'    => array( 'valid', 'free Hub membership' ),
		'paused'  => array( 'invalid', 'membership paused' ),
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
				return array( $item( array( 'name' => 'Elementor Pro', 'key' => true, 'note' => 'Key stored; Elementor has not recorded a status yet' ) ) );
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
			return array( $item( array( 'name' => 'WP Rocket', 'state' => $state, 'key' => true, 'expires' => $expires, 'note' => $flagged ? 'flagged unlicensed' : '' ) ) );
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
					$note = 'Bricks could not reach its license server';
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
				$note  = 'free trial';
				$state = minn_admin_license_expired( $trial_end ) ? 'expired' : 'valid';
			}
			return array( $item( array( 'name' => 'AnalyticsWP', 'state' => $state, 'key' => true, 'expires' => $trial_end, 'note' => $note ) ) );
		},
	);

	// Brainstorm Force family (Astra Pro, Ultimate Addons, Spectra Pro …):
	// one registry option, per-product purchase_key + 'registered' status.
	$providers['bsf'] = array(
		'name'      => 'Brainstorm Force products',
		'component' => 'bsf-registry',
		'detect'    => function () {
			$reg = get_option( 'brainstrom_products' );
			return is_array( $reg ) && ! empty( $reg );
		},
		'read'      => function () use ( $item ) {
			$reg   = get_option( 'brainstrom_products' );
			$items = array();
			foreach ( array( 'plugins' => 'plugin', 'themes' => 'theme' ) as $group => $kind ) {
				if ( empty( $reg[ $group ] ) || ! is_array( $reg[ $group ] ) ) {
					continue;
				}
				foreach ( $reg[ $group ] as $slug => $p ) {
					if ( ! is_array( $p ) ) {
						continue;
					}
					$name = (string) ( $p['product_name'] ?? $slug );
					$key  = ! empty( $p['purchase_key'] );
					$reg_ok = isset( $p['status'] ) && 'registered' === $p['status'];
					$items[] = $item( array(
						'name'  => $name,
						'kind'  => $kind,
						'state' => $key ? ( $reg_ok ? 'valid' : 'unknown' ) : 'missing',
						'key'   => $key,
						'note'  => $key && $reg_ok ? 'registered' : '',
					) );
				}
			}
			return $items;
		},
	);

	// WPMU DEV: one Hub API key (site option wpmudev_apikey, or the
	// WPMUDEV_APIKEY constant) unlocks the whole family. Membership status
	// is cached verbatim from their Hub in wdp_un_membership_data; the Hub
	// signals an invalid or expired key with an EMPTY membership string,
	// and a NUMERIC membership is a single-project license id.
	$providers['wpmudev'] = array(
		'name'      => 'WPMU DEV',
		'component' => 'wpmudev-updates/update-notifications.php',
		'detect'    => function () use ( $has ) {
			return $has( 'wpmudev-updates/update-notifications.php' );
		},
		'read'      => function () use ( $item ) {
			$m = minn_admin_wpmudev_membership();
			if ( 'missing' === $m['state'] ) {
				return array( $item( array( 'name' => 'WPMU DEV membership', 'state' => 'missing' ) ) );
			}
			return array( $item( array( 'name' => 'WPMU DEV membership', 'state' => $m['state'], 'key' => true, 'note' => $m['note'] ) ) );
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
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'missing', 'note' => 'licensed through the WPMU DEV Dashboard key' ) ) );
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
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'valid', 'key' => true, 'note' => 'covered by the WPMU DEV membership' ) ) );
			}
			if ( 'expired' === $m['state'] ) {
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'expired', 'key' => true, 'note' => 'the WPMU DEV membership has expired' ) ) );
			}
			if ( 'invalid' === $m['state'] ) {
				return array( $item( array( 'name' => 'Smush Pro', 'state' => 'invalid', 'key' => true, 'note' => 'the WPMU DEV membership is paused' ) ) );
			}
			return array( $item( array( 'name' => 'Smush Pro', 'state' => 'unknown', 'key' => true, 'note' => 'Dashboard key present; Smush has not validated it yet' ) ) );
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
				return array( $item( array( 'name' => 'Gravity Perks', 'state' => 'unknown', 'key' => true, 'stale' => true, 'note' => 'Gravity Perks re-checks every 12 hours; no cached status' ) ) );
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
				return array( $item( array( 'name' => 'Rank Math SEO PRO', 'state' => 'missing', 'note' => 'connects through a rankmath.com account' ) ) );
			}
			$plan = ! empty( $d['plan'] ) ? (string) $d['plan'] : 'pro';
			return array( $item( array( 'name' => 'Rank Math SEO PRO', 'state' => 'valid', 'key' => true, 'note' => 'connected; plan: ' . $plan ) ) );
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
			return array( $item( array( 'name' => 'Slider Revolution', 'state' => 'invalid', 'key' => true, 'note' => $why ? substr( $why, 0, 120 ) : 'code stored but not registered' ) ) );
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
			return array( $item( array( 'name' => 'LayerSlider', 'state' => 'invalid', 'key' => true, 'note' => $canceled ? 'activation was canceled remotely' : 'code stored but the site is not authorized' ) ) );
		},
	);

	// Envato Market: an account OAuth token (presence-only; Envato records
	// no local validity) plus optional per-item single-use tokens that DO
	// carry an authorized success/failed flag. Purchased-item counts ride
	// the plugin's own hourly site transients when warm.
	$providers['envato-market'] = array(
		'name'      => 'Envato Market',
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
				return array( $item( array( 'name' => 'Envato Market', 'state' => 'missing', 'note' => 'no account token or item tokens stored' ) ) );
			}
			if ( ! empty( $o['token'] ) ) {
				$counts = array();
				foreach ( array( 'themes' => 'envato_market_themes', 'plugins' => 'envato_market_plugins' ) as $label => $tr ) {
					$t = get_site_transient( $tr );
					if ( is_array( $t ) && isset( $t['purchased'] ) && is_array( $t['purchased'] ) && $t['purchased'] ) {
						$counts[] = count( $t['purchased'] ) . ' ' . $label;
					}
				}
				$note   = $counts ? 'covers ' . implode( ' + ', $counts ) . ' purchased' : 'Envato keeps no local validity record';
				$rows[] = $item( array( 'name' => 'Envato Market account token', 'state' => 'unknown', 'key' => true, 'note' => $note ) );
			}
			foreach ( $items as $it ) {
				if ( ! is_array( $it ) || empty( $it['name'] ) ) {
					continue;
				}
				$auth  = isset( $it['authorized'] ) ? (string) $it['authorized'] : '';
				$state = 'success' === $auth ? 'valid' : ( 'failed' === $auth ? 'invalid' : 'unknown' );
				$rows[] = $item( array( 'name' => (string) $it['name'], 'kind' => ( isset( $it['type'] ) && 'theme' === $it['type'] ) ? 'theme' : 'plugin', 'state' => $state, 'key' => ! empty( $it['token'] ), 'note' => 'Envato single-item token' ) );
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
				return array( $item( array( 'name' => 'Avada', 'kind' => 'theme', 'state' => 'valid', 'key' => true, 'note' => 'covers the bundled Avada plugins' ) ) );
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
						$note = 'key ships inside the plugin build';
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
				return array( 'ok' => false, 'code' => 'error', 'message' => 'The key validation service did not answer.' );
			}
			$msg = isset( $res['message'] ) ? trim( wp_strip_all_tags( (string) $res['message'] ) ) : '';
			if ( ! empty( $res['status'] ) ) {
				return array( 'ok' => true, 'code' => '', 'message' => $msg );
			}
			$code = ! empty( $res['api_expired'] ) ? 'expired' : 'invalid';
			if ( false !== stripos( $msg, 'limit' ) || false !== stripos( $msg, 'installation' ) ) {
				$code = 'site_limit';
			}
			return array( 'ok' => false, 'code' => $code, 'message' => '' !== $msg ? $msg : 'The key was not accepted.' );
		};
		foreach ( minn_admin_license_tec_products() as $pid => $tp ) {
			if ( ! class_exists( $tp['class'] ) || ! isset( $providers[ $pid ] ) ) {
				continue;
			}
			$providers[ $pid ]['secret_label'] = $tp['name'] . ' license key';
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
						? 'The stored key was cleared, but this plugin build ships with the key embedded and will re-register it. Manage licensed domains on theeventscalendar.com.'
						: 'The key was removed from this site. Manage its licensed domains on theeventscalendar.com.',
				);
			};
			$providers[ $pid ]['verify']       = function () use ( $tp, $tec_checker, $tec_classify ) {
				$checker = $tec_checker( $tp );
				$key     = (string) $checker->get_key();
				if ( '' === $key ) {
					return array( 'ok' => false, 'code' => 'error', 'message' => 'No key is stored for this product.' );
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
				return array( 'ok' => false, 'code' => 'error', 'message' => 'The Kadence licensing service did not answer.' );
			}
			if ( $res->is_valid() ) {
				return array( 'ok' => true, 'code' => '', 'message' => '' );
			}
			$result = (string) $res->get_result();
			$code   = 'expired' === $result ? 'expired' : ( 'unreachable' === $result ? 'error' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => 'unreachable' === $result ? 'The Kadence licensing service is unreachable.' : 'The key was not accepted (' . $result . ').' );
		};
		$providers['kadence-blocks-pro']['secret_label'] = 'Kadence license key';
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
					? 'The stored key was cleared. A key baked into the plugin build remains as the fallback.'
					: 'The stored key was cleared from this site.',
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
		$providers['elementor-pro']['secret_label'] = 'Elementor Pro license key';
		$providers['elementor-pro']['activate']     = function ( $secret ) {
			$data = \ElementorPro\License\API::activate_license( $secret );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			if ( empty( $data['success'] ) ) {
				$err  = isset( $data['error'] ) ? (string) $data['error'] : 'unknown';
				$code = 'no_activations_left' === $err ? 'site_limit' : ( 'expired' === $err ? 'expired' : 'invalid' );
				$msgs = array(
					'missing'       => 'Elementor does not recognize that key',
					'invalid'       => 'Elementor does not recognize that key',
					'expired'       => 'That Elementor Pro license has expired',
					'disabled'      => 'That license was disabled',
					'cancelled'     => 'That subscription was cancelled',
					'revoked'       => 'That license was revoked',
					'site_inactive' => 'That key is registered to a different domain',
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
			\ElementorPro\License\API::get_license_data( true ); // force a fresh check into their own cache
			return array( 'ok' => true );
		};
	}

	// ACF Pro: their own activate/deactivate return {success, message}
	// (or WP_Error); $silent = true keeps their admin notices out of it.
	if ( function_exists( 'acf_pro_activate_license' ) ) {
		$providers['acf-pro']['secret_label'] = 'ACF PRO license key';
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
		$providers['beaver-builder']['secret_label'] = 'Beaver Builder license key';
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
			return array( 'ok' => (bool) $active, 'code' => $active ? '' : 'invalid', 'message' => $active ? '' : 'Beaver Builder reports the subscription as not active for this domain' );
		};
	}

	// Brizy Pro: their singleton's activate/deactivate throw an Exception
	// carrying the vendor message on failure; the action endpoint's
	// Throwable guard turns that into a plain error result.
	if ( class_exists( 'BrizyPro_Admin_License' ) ) {
		$providers['brizy-pro']['secret_label'] = 'Brizy Pro license key';
		$providers['brizy-pro']['activate']     = function ( $secret ) {
			BrizyPro_Admin_License::_init()->activate( array( 'key' => $secret ) );
			return array( 'ok' => true );
		};
		$providers['brizy-pro']['deactivate'] = function () {
			BrizyPro_Admin_License::_init()->deactivate( array() );
			return array( 'ok' => true );
		};
	}

	// Bricks (active theme only): the non-ajax activate_license() path reads
	// the PUBLIC static key, only persists on a real status response, and
	// returns the status string (void = not recognized). Their deactivate
	// and revalidate handlers nonce-check unconditionally, so deactivation
	// stays on their screen; verify reuses activation with the stored key
	// (their own revalidate does exactly that).
	if ( class_exists( '\Bricks\License' ) ) {
		$providers['bricks']['secret_label'] = 'Bricks license key';
		$providers['bricks']['activate']     = function ( $secret ) {
			\Bricks\License::$license_key = $secret;
			$status = \Bricks\License::activate_license();
			if ( 'active' === $status ) {
				return array( 'ok' => true );
			}
			if ( 'error_remote' === $status ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => 'The Bricks license server is temporarily unavailable' );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => $status ? 'Bricks returned status: ' . $status : 'Bricks did not recognize that key' );
		};
		$providers['bricks']['verify'] = function () {
			$key = get_option( 'bricks_license_key' );
			if ( ! $key ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => 'No key stored' );
			}
			delete_transient( 'bricks_license_status' );
			\Bricks\License::$license_key = $key;
			$status = \Bricks\License::activate_license();
			return array( 'ok' => 'active' === $status, 'code' => 'active' === $status ? '' : 'invalid', 'message' => 'active' === $status ? '' : (string) $status );
		};
	}

	// Etch: wrapper methods throw readable messages; status lands in the
	// options its reader above consumes.
	if ( class_exists( '\Etch\WpAdmin\License' ) ) {
		$providers['etch']['secret_label'] = 'Etch license key';
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
				'expired'    => 'Elegant Themes reports the subscription as expired',
				'not_found'  => 'Elegant Themes does not recognize that username',
				'not_active' => 'Elegant Themes did not confirm the account',
			);
			return array( 'ok' => false, 'code' => $code, 'message' => isset( $msgs[ $status ] ) ? $msgs[ $status ] : str_replace( '_', ' ', $status ) );
		};
		$providers['divi']['secret_fields'] = array(
			array( 'id' => 'username', 'label' => 'Elegant Themes username' ),
			array( 'id' => 'api_key', 'label' => 'API key' ),
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
			return array( 'ok' => true, 'message' => 'Credentials removed; Elegant Themes has no per-site seats to release' );
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
			return array( 'ok' => false, 'code' => $code, 'message' => $msg ? $msg : 'Gravity Forms did not validate the key' );
		};
		$providers['gravityforms']['secret_label'] = 'Gravity Forms license key';
		$providers['gravityforms']['activate']     = function ( $secret ) use ( $gf_status ) {
			GFFormsModel::save_key( $secret );
			if ( get_option( 'rg_gforms_key' ) !== md5( trim( $secret ) ) ) {
				// Their own revert fired: the key was rejected outright.
				GFCommon::get_version_info( false );
				return array( 'ok' => false, 'code' => 'invalid', 'message' => 'Gravity Forms did not accept that key' );
			}
			return $gf_status();
		};
		$providers['gravityforms']['deactivate'] = function () {
			GFFormsModel::save_key( '' ); // unlinks the site with Gravity's server
			GFCommon::get_version_info( false );
			return array( 'ok' => true, 'message' => 'Site unlinked from the license' );
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
				$msg = 'Gravity did not recognize that key';
			}
			return array( 'ok' => false, 'code' => $code, 'message' => $msg ? $msg : str_replace( '_', ' ', (string) $status ) );
		};
		$smtp_store = function () {
			return \Gravity_Forms\Gravity_SMTP\Gravity_SMTP::container()->get( \Gravity_Forms\Gravity_SMTP\Connectors\Connector_Service_Provider::DATA_STORE_PLUGIN_OPTS );
		};
		$providers['gravitysmtp']['secret_label'] = 'Gravity SMTP license key';
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
				return array( 'ok' => false, 'code' => 'invalid', 'message' => 'No key stored' );
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
		$providers['wpmudev']['secret_label'] = 'WPMU DEV API key';
		$providers['wpmudev']['activate']     = function ( $secret ) {
			WPMUDEV_Dashboard::$api->set_key( $secret );
			$res = WPMUDEV_Dashboard::$api->hub_sync( false, true );
			if ( false === $res ) {
				WPMUDEV_Dashboard::$api->set_key( '' ); // the rollback their own endpoint does
				return array( 'ok' => false, 'code' => 'error', 'message' => 'Could not reach the WPMU DEV Hub' );
			}
			if ( empty( $res['membership'] ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => 'The Hub did not recognize that API key' );
			}
			return array( 'ok' => true );
		};
		$providers['wpmudev']['deactivate'] = function () {
			WPMUDEV_Dashboard::$site->logout( false );
			return array( 'ok' => true );
		};
		$providers['wpmudev']['verify'] = function () {
			if ( ! WPMUDEV_Dashboard::$api->has_key() ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => 'No key stored' );
			}
			$res = WPMUDEV_Dashboard::$api->hub_sync( false, true );
			$ok  = is_array( $res ) && ! empty( $res['membership'] );
			return array( 'ok' => $ok, 'code' => $ok ? '' : 'invalid', 'message' => $ok ? '' : 'The Hub reports no active membership for this key' );
		};
	}

	// SearchWP: License::activate/deactivate return { success, data } where
	// a failure's data is the human message; maintenance() is their own
	// daily check_license pass and rewrites the stored status.
	if ( class_exists( '\SearchWP\License' ) ) {
		$providers['searchwp']['secret_label'] = 'SearchWP license key';
		$providers['searchwp']['activate']     = function ( $secret ) {
			$res = \SearchWP\License::activate( $secret );
			if ( is_array( $res ) && ! empty( $res['success'] ) ) {
				return array( 'ok' => true );
			}
			$msg  = ( is_array( $res ) && isset( $res['data'] ) && is_string( $res['data'] ) ) ? wp_strip_all_tags( $res['data'] ) : '';
			$code = ( false !== stripos( $msg, 'limit' ) ) ? 'site_limit' : ( false !== stripos( $msg, 'expired' ) ? 'expired' : 'invalid' );
			return array( 'ok' => false, 'code' => $code, 'message' => $msg ? $msg : 'SearchWP did not accept that key' );
		};
		$providers['searchwp']['deactivate'] = function () {
			$lic = get_option( 'searchwp_license' );
			$key = ( is_array( $lic ) && ! empty( $lic['key'] ) ) ? (string) $lic['key'] : '';
			if ( '' === $key ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => 'No key stored' );
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
			return array( 'ok' => $ok, 'code' => $ok ? '' : 'invalid', 'message' => $ok ? '' : 'SearchWP did not confirm the license' );
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
		$providers['gravityperks']['secret_label'] = 'Gravity Perks license key';
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
				return array( 'ok' => false, 'code' => 'site_limit', 'message' => 'That key has reached its site limit' );
			}
			return array( 'ok' => false, 'code' => 'invalid', 'message' => 'Gravity Wiz did not validate that key' );
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
			return array( 'ok' => (bool) $ok, 'code' => $ok ? '' : 'invalid', 'message' => $ok ? '' : 'Gravity Wiz reports the license as not valid for this site' );
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
		$providers['perfmatters']['secret_label'] = 'Perfmatters license key';
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
			return array( 'ok' => false, 'code' => $code, 'message' => $word ? str_replace( '_', ' ', $word ) : 'Perfmatters did not accept that key' );
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
			return array( 'ok' => (bool) $ok, 'code' => $ok ? '' : 'error', 'message' => $ok ? '' : 'Perfmatters could not release this site' );
		};
		$providers['perfmatters']['verify'] = function () {
			$info = \Perfmatters\License::check(); // also refreshes the stored status
			$word = ( is_object( $info ) && ! empty( $info->license ) ) ? (string) $info->license : '';
			$ok   = 'valid' === $word;
			return array( 'ok' => $ok, 'code' => $ok ? '' : ( 'expired' === $word ? 'expired' : 'invalid' ), 'message' => $ok ? '' : ( $word ? str_replace( '_', ' ', $word ) : 'No response from the Perfmatters server' ) );
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
		$providers['gp-premium']['secret_label'] = 'GP Premium license key';
		$providers['gp-premium']['activate']     = function ( $secret ) use ( $gpp_route ) {
			return $gpp_route( $secret );
		};
		$providers['gp-premium']['deactivate'] = function () use ( $gpp_route ) {
			return $gpp_route( '' );
		};
		$providers['gp-premium']['verify'] = function () use ( $gpp_route ) {
			if ( ! get_option( 'gen_premium_license_key' ) ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => 'No key stored' );
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
			return array( 'ok' => false, 'code' => $code, 'message' => $word ? str_replace( '_', ' ', (string) $word ) : 'wpallimport.com did not confirm the license' );
		};
		$providers['wp-all-export']['secret_label'] = 'WP All Export license key';
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
				return array( 'ok' => false, 'code' => 'error', 'message' => 'Could not reach wpallimport.com (or no key is stored)' );
			}
			PMXE_Plugin::getInstance()->updateOption( 'license_status', (string) $word );
			return $pmxe_result( $word );
		};
	}

	// WP All Import Pro: its only paste-activation path reads $_POST inside
	// a nonce-gated admin controller, so activation stays on its screen
	// (linked below); verify rides the public static check their own
	// licenses screen uses and stores the result exactly as it does.
	if ( class_exists( 'PMXI_Plugin' ) && class_exists( 'PMXI_Admin_License' ) ) {
		$providers['wp-all-import']['verify'] = function () {
			$word = PMXI_Admin_License::check_license( 'PMXI_Plugin' );
			if ( false === $word ) {
				return array( 'ok' => false, 'code' => 'error', 'message' => 'Could not reach wpallimport.com (or no key is stored)' );
			}
			$o = PMXI_Plugin::getInstance()->getOption();
			$o['statuses']['PMXI_Plugin'] = (string) $word;
			PMXI_Plugin::getInstance()->updateOption( $o );
			$ok = in_array( (string) $word, array( 'valid', 'active' ), true );
			return array( 'ok' => $ok, 'code' => $ok ? '' : ( 'expired' === $word ? 'expired' : 'invalid' ), 'message' => $ok ? '' : str_replace( '_', ' ', (string) $word ) );
		};
		$providers['wp-all-import']['activate_url'] = admin_url( 'admin.php?page=pmxi-admin-settings' );
	}

	// Slider Revolution: its RevSliderLicense::activate_plugin() is welded to
	// admin-only classes (RevSliderTracking, the RevSliderLoadBalancer that
	// is only registered in RevSliderGlobals during admin init), none of
	// which load in a REST request. Reproducing that boot order is exactly
	// the vendor-internals guessing the guardrails forbid, so the honest
	// control is a link to its own activation screen.
	if ( defined( 'RS_REVISION' ) ) {
		$providers['revslider']['activate_url'] = admin_url( 'admin.php?page=revslider' );
	}

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
			$msg  = ( is_object( $json ) && ! empty( $json->message ) ) ? wp_strip_all_tags( (string) $json->message ) : 'Kreatura did not accept that license key';
			$code = ( false !== stripos( $msg, 'another site' ) || false !== stripos( $msg, 'in use' ) || false !== stripos( $msg, 'limit' ) ) ? 'site_limit' : 'invalid';
			return array( 'ok' => false, 'code' => $code, 'message' => $msg );
		};
		$providers['layerslider']['secret_label'] = 'LayerSlider license key';
		$providers['layerslider']['activate']     = $ls_activate;
		$providers['layerslider']['verify']       = function () use ( $ls_activate ) {
			$code = get_option( 'layerslider-purchase-code', '' );
			if ( '' === $code ) {
				return array( 'ok' => false, 'code' => 'invalid', 'message' => 'No license key stored' );
			}
			return $ls_activate( $code );
		};
	}

	// Rank Math: activation is the rankmath.com portal handshake owned by
	// the FREE plugin — link to its registration screen while it is loaded.
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$providers['rank-math-pro']['activate_url'] = admin_url( 'admin.php?page=rank-math&view=registration' );
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
				'note'      => 'Freemius-powered; no account state recorded yet',
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
			$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => 'missing', 'key' => false, 'note' => 'Premium install with no license attached', 'component' => $fp['component'] );
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
			$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => 'unknown', 'key' => true, 'note' => 'License attached but not readable locally', 'component' => $fp['component'] );
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
			$opt = $row ? maybe_unserialize( $row ) : array();
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
			// Upgrade an "unknown" row from Minn's own last check of this
			// provider, honestly timestamped. Never overrides a state the
			// vendor's stored data produced.
			if ( 'unknown' === ( $row['state'] ?? '' ) && ! empty( $row['key'] ) && isset( $checks[ $id ]['time'] ) ) {
				$chk  = $checks[ $id ];
				$when = human_time_diff( (int) $chk['time'] ) . ' ago';
				if ( ! empty( $chk['ok'] ) ) {
					$row['state'] = 'valid';
					$row['note']  = 'verified ' . $when . ' from Minn';
				} else {
					$row['state'] = ( 'expired' === ( $chk['code'] ?? '' ) ) ? 'expired' : 'invalid';
					$row['note']  = 'failed a check ' . $when . ' from Minn';
				}
			}
			if ( $can ) {
				$row['can']    = $can;
				$row['secret'] = isset( $p['secret_label'] ) ? (string) $p['secret_label'] : 'License key';
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

add_action( 'rest_api_init', function () {
	$can = function () {
		return current_user_can( 'manage_options' );
	};
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
					return new WP_Error( 'bad_action', 'Unknown action.', array( 'status' => 400 ) );
				}
				$providers = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
				$p         = isset( $providers[ $provider_id ] ) ? $providers[ $provider_id ] : null;
				if ( ! $p || empty( $p[ $action ] ) || ! is_callable( $p[ $action ] ) ) {
					return new WP_Error( 'no_provider', 'No such action for this provider.', array( 'status' => 404 ) );
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
							return new WP_Error( 'no_secret', 'Fill in every field first.', array( 'status' => 400 ) );
						}
						$payload[ $fid ] = $val;
					}
				} elseif ( 'activate' === $action && '' === $secret ) {
					return new WP_Error( 'no_secret', 'Paste a key first.', array( 'status' => 400 ) );
				}
				try {
					$raw = ( 'activate' === $action )
						? call_user_func( $p[ $action ], $payload )
						: call_user_func( $p[ $action ] );
					$result = minn_admin_license_result( $raw );
				} catch ( \Throwable $e ) {
					$result = array( 'ok' => false, 'code' => 'error', 'message' => $e->getMessage() );
				}
				// Some vendors (Gravity SMTP, Brizy) keep no local validity
				// state, so their rows would read "unknown" forever even
				// after a successful activation (Austin's anchor repro).
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
				return rest_ensure_response( $result );
			},
		)
	);
} );
