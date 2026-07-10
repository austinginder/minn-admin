<?php
/**
 * License visibility — Phase 0 of docs/license-manager.md.
 *
 * Enumerates every license-wanting plugin and theme on the site and
 * classifies each as valid / expired / invalid / missing / unknown from
 * LOCALLY STORED state only. Strictly read-only: no network calls, no
 * vendor code execution, no writes, so it can never burn an activation
 * seat. Stored status is last-verified truth, not live truth; rows carry
 * a stale flag when the vendor's own cache has lapsed.
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
 * SDK fingerprints per installed component: which plugins/themes embed a
 * known licensing SDK. A bounded filename walk (never file contents),
 * cached for a day and keyed to the installed set so installs/updates
 * re-scan. Returns array of [ 'component' => 'dir/file.php'|'theme:slug',
 * 'kind', 'name', 'slug', 'sdk' => freemius|edd|surecart ].
 */
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

	// Gravity Forms stores the key md5-hashed and no local validity state.
	$providers['gravityforms'] = array(
		'name'      => 'Gravity Forms',
		'component' => 'gravityforms/gravityforms.php',
		'detect'    => function () use ( $has ) {
			return $has( 'gravityforms/gravityforms.php' );
		},
		'read'      => function () use ( $item ) {
			$key = get_option( 'rg_gforms_key' );
			return array( $item( array(
				'name'  => 'Gravity Forms',
				'state' => $key ? 'unknown' : 'missing',
				'key'   => (bool) $key,
				'note'  => $key ? 'Key stored (hashed); Gravity Forms keeps no local validity state' : '',
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
				'name'  => $fp['name'],
				'kind'  => $fp['kind'],
				'state' => 'unknown',
				'key'   => false,
				'note'  => 'Freemius-powered; no account state recorded yet',
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
			$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => 'missing', 'key' => false, 'note' => 'Premium install with no license attached' );
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
			$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => 'unknown', 'key' => true, 'note' => 'License attached but not readable locally' );
			continue;
		}
		$raw_exp = minn_admin_license_prop( $license, 'expiration', '' );
		$expires = ( null === $raw_exp || '' === $raw_exp ) ? 'lifetime' : minn_admin_license_expiry( $raw_exp );
		$state   = minn_admin_license_expired( $expires ) ? 'expired' : 'valid';
		$out[]   = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => $state, 'key' => true, 'expires' => $expires );
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
		$out[] = array( 'name' => $fp['name'], 'kind' => $fp['kind'], 'state' => $state, 'key' => $key_present, 'note' => $note );
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
			'name'  => $fp['name'],
			'kind'  => $fp['kind'],
			'state' => $key ? 'unknown' : 'missing',
			'key'   => (bool) $key,
			'note'  => $key ? 'Activation stored; SureCart keeps no local expiry' : '',
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
	$providers = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
	$items     = array();
	$claimed   = array();
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
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$row['id']     = sanitize_key( $id . '-' . $row['name'] );
			$row['source'] = (string) $id;
			$items[]       = $row;
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

add_action( 'rest_api_init', function () {
	register_rest_route(
		'minn-admin/v1',
		'/licenses',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function () {
				return rest_ensure_response( minn_admin_licenses() );
			},
		)
	);
} );
