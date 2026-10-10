<?php
/**
 * The database browser hides every licence credential the Licences screen reads.
 *
 * The browser redacts rows by name (Minn_Admin_DB, fed by
 * minn_admin_license_secret_options()), and that list missed rows in every
 * fix round of v0.44.0. This derives what it must cover instead:
 *
 * 1. Reads: each licence reader runs the way the Licences screen runs it,
 *    with every option, network option, meta key and table it reads recorded.
 * 2. Source: every literal option or meta name the provider files read or
 *    write, so a reader the lab cannot run (vendor missing, early return) is
 *    covered too.
 *    Each name from 1 and 2 is redacted, or listed in $not_secret below with
 *    what it holds instead.
 * 3. Copies: every credential the browser redacts in a vendor row is looked
 *    for, raw and base64-encoded, in every other cell of every table. A copy
 *    in an option, network option or meta row the browser shows fails,
 *    whatever that row is called. A copy in any other table (an activity
 *    log that recorded the change) is reported: rows there are not named
 *    for what they hold, so redaction by name cannot reach them.
 *
 * A row a reader reads that is a licence server's answer or an update cache
 * is redacted unless it is plainly status, flags or versions: those can
 * carry the key in a download link, and hiding a status row costs nothing.
 *
 * Prints names, never values.
 *
 * Run: node tests/license-redaction.test.js (wp eval-file, as an administrator).
 *
 * @package minn-admin
 */

global $wpdb;

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$summary = function () use ( &$results ) {
	$ok = count( array_filter( $results ) );
	printf( "\nlicense-redaction: %d/%d passed\n", $ok, count( $results ) );
	exit( count( $results ) === $ok ? 0 : 1 );
};

if ( ! function_exists( 'minn_admin_license_default_providers' ) || ! class_exists( 'Minn_Admin_DB' ) ) {
	$check( 'Minn Admin is active', false );
	$summary();
}
// The browser takes a super admin on a network.
$admin = is_multisite() ? array( (int) get_user_by( 'login', (string) current( get_super_admins() ) )->ID ) : get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( $admin ? (int) $admin[0] : 0 );

$is_secret = new ReflectionMethod( 'Minn_Admin_DB', 'is_secret_cell' );
$is_secret->setAccessible( true );
$hidden_cell = function ( $table, $col, $row ) use ( $is_secret ) {
	return (bool) $is_secret->invoke( null, $table, $col, $row );
};
$tables = array(
	'option'      => array( $wpdb->options, 'option_value', 'option_name' ),
	'sitemeta'    => array( $wpdb->sitemeta, 'meta_value', 'meta_key' ),
	'usermeta'    => array( $wpdb->usermeta, 'meta_value', 'meta_key' ),
	'postmeta'    => array( $wpdb->postmeta, 'meta_value', 'meta_key' ),
	'termmeta'    => array( $wpdb->termmeta, 'meta_value', 'meta_key' ),
	'commentmeta' => array( $wpdb->commentmeta, 'meta_value', 'meta_key' ),
);
// On a single site get_site_option() reads the options table.
$store_of = function ( $store ) {
	return 'sitemeta' === $store && ! is_multisite() ? 'option' : $store;
};
$hidden = function ( $store, $name ) use ( $tables, $hidden_cell, $store_of ) {
	list( $table, $value, $key ) = $tables[ $store_of( $store ) ];
	return $hidden_cell( $table, $value, array( $key => $name ) );
};

// Rows the readers read that hold no credential, with what they hold. A
// trailing * matches any rest of the name. Options and network options share
// this list, as they share the browser's.
$not_secret = array(
	'option'   => array(
		// WordPress itself: the readers' plugin, theme and date checks.
		'active_plugins'                             => 'core',
		'active_sitewide_plugins'                    => 'core',
		'site_admins'                                => 'core: the super admin logins',
		'menu_items'                                 => 'core: network menu switches',
		'siteurl'                                    => 'core',
		'home'                                       => 'core',
		'stylesheet'                                 => 'core',
		'template'                                   => 'core',
		'timezone_string'                            => 'core',
		'gmt_offset'                                 => 'core',
		'woocommerce_custom_orders_table_enabled'    => 'WooCommerce storage switch',
		'_transient_timeout_*'                       => 'a transient expiry time',
		'_site_transient_timeout_*'                  => 'a transient expiry time',
		// Minn's own.
		'minn_admin_license_checks'                  => "Minn's record of its own checks: ok, code, time",
		'_transient_minn_admin_license_fp'           => 'which component carries which licensing SDK',
		'_transient_minn_admin_bsf_synced'           => 'a sync flag',
		'minn_test_*'                                => "the lab's fixture switches",
		// Status, expiry and account details beside a key kept elsewhere.
		'*_license_status'                           => 'an EDD-style status word',
		'*_license_key_status'                       => 'an EDD-style status word',
		'cff_license_data'                           => 'EDD check answer: status, expiry, seats, customer',
		'ctf_license_data'                           => 'EDD check answer: status, expiry, seats, customer',
		'sbi_license_data'                           => 'EDD check answer: status, expiry, seats, customer',
		'sbsw_license_data'                          => 'EDD check answer: status, expiry, seats, customer',
		'sby_license_data'                           => 'EDD check answer: status, expiry, seats, customer',
		'acp_subscription_details'                   => 'Admin Columns Pro: status, renewal method, expiry',
		'_elementor_pro_license_v2_data'             => 'Elementor Pro: the last check answer (status, expiry, features)',
		'_transient_wpseo_site_information'          => 'Yoast: subscriptions and expiry',
		'_site_transient_wpmdb_licence_response*'    => 'WP Migrate: the last check answer (status, messages, add-ons)',
		'_site_transient_envato_market_*'            => 'Envato Market: purchased items list',
		'gform_version_info'                         => 'Gravity Forms: versions and expiring download links',
		'googlesitekit_has_connected_admins'         => 'Site Kit: a flag',
		'jetpack_options'                            => 'Jetpack: site id and heartbeat (tokens are in jetpack_private_options)',
		'layerslider-authorized-site'                => 'LayerSlider: a flag',
		'ls-show-canceled_activation_notice'         => 'LayerSlider: a flag',
		'revslider-valid'                            => 'Slider Revolution: a flag',
		'revslider-deregister-message'               => 'Slider Revolution: a message',
		'essential-addons-elementor-license-status'  => 'an EDD-style status word',
		'gsmtp_version_info'                         => 'Gravity SMTP: versions and expiring download links',
		'_site_transient_wpmdb_upgrade_data'         => 'WP Migrate: add-on versions and icons',
		'_site_transient_jet_dashboard_license_expire_check' => 'Crocoblock: a check flag',
		'wdp_un_membership_data'                     => 'WPMU DEV: membership level and Hub site id (the key is wpmudev_apikey)',
		'et_account_status'                          => 'Divi: a status word',
		'wp_rocket_no_licence'                       => 'WP Rocket: a flag',
		'_transient_rocket_check_key_errors'         => 'WP Rocket: error messages',
		'nvp_license_error'                          => 'Novamira Pro: an error message',
		'nvp_license_domain'                         => 'Novamira Pro: the licensed domain',
		'akismet_alert_code'                         => 'Akismet: an alert code',
		'akismet_alert_msg'                          => 'Akismet: an alert message',
		'seopress_pro_license_key_error'             => 'SEOPress Pro: an error message',
		'seopress_pro_license_expiry'                => 'SEOPress Pro: the expiry date',
		'seopress_pro_license_automatic_attempt'     => 'SEOPress Pro: a retry flag',
		'seopress_pro_license_home_url'              => 'SEOPress Pro: the licensed home URL',
	),
	'usermeta' => array(
		$wpdb->get_blog_prefix() . 'capabilities' => 'core roles',
	),
	'postmeta' => array(),
	// Tables beyond the key/value ones above.
	'table'    => array(),
);
// Names built at run time from a list the lab may not reach (a multisite-only
// read, a reader that returns before its second read), spelled out. Each
// spelling is checked like any other name.
$resolved = array(
	'option'   => array(
		'*_license'        => array( 'monsterinsights_license', 'exactmetrics_license', 'essential-addons-elementor_license' ),
		'*-license-key'    => array( 'essential-addons-elementor-license-key' ),
		'*-license-status' => array( 'essential-addons-elementor-license-status' ),
	),
	'sitemeta' => array(
		'*_network_license' => array( 'monsterinsights_network_license', 'exactmetrics_network_license' ),
	),
);
$listed = function ( $store, $name ) use ( $not_secret ) {
	$list = in_array( $store, array( 'option', 'sitemeta' ), true ) ? $not_secret['option'] : ( $not_secret[ $store ] ?? array() );
	foreach ( $list as $pattern => $why ) {
		$re = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
		if ( preg_match( $re, $name ) ) {
			return $pattern;
		}
	}
	return null;
};
$used = array();

// --- 1. What the readers read ------------------------------------------------

$scope = '';
$reads = array();
$note  = function ( $store, $name ) use ( &$reads, &$scope ) {
	if ( '' !== $scope && '' !== (string) $name ) {
		$reads[ $store ][ (string) $name ][ $scope ] = true;
	}
};
$core_tables = array_map( 'strtolower', array( $wpdb->options, $wpdb->sitemeta, $wpdb->usermeta, $wpdb->postmeta, $wpdb->termmeta, $wpdb->commentmeta, $wpdb->users, $wpdb->posts, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->comments, $wpdb->links, $wpdb->site, $wpdb->blogs, $wpdb->blogmeta ) );
$tracers     = array(
	array( 'pre_option', function ( $pre, $name ) use ( $note ) {
		$note( 'option', $name );
		return $pre;
	}, 2 ),
	array( 'pre_site_option', function ( $pre, $name ) use ( $note ) {
		$note( 'sitemeta', $name );
		return $pre;
	}, 2 ),
	array( 'query', function ( $sql ) use ( $note, $wpdb, $core_tables ) {
		if ( preg_match_all( '/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?(' . preg_quote( $wpdb->base_prefix, '/' ) . '[A-Za-z0-9_]+)`?/i', $sql, $m ) ) {
			foreach ( $m[1] as $t ) {
				if ( ! in_array( strtolower( $t ), $core_tables, true ) ) {
					$note( 'table', $t );
				}
			}
		}
		return $sql;
	}, 1 ),
);
foreach ( array( 'user', 'post', 'term', 'comment' ) as $type ) {
	$tracers[] = array( "get_{$type}_metadata", function ( $value, $id, $key ) use ( $note, $type ) {
		$note( "{$type}meta", $key );
		return $value;
	}, 3 );
}
foreach ( $tracers as $t ) {
	add_filter( $t[0], $t[1], PHP_INT_MAX, $t[2] );
}

$providers = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
$claimed   = array();
$ran       = 0;
foreach ( $providers as $id => $p ) {
	if ( empty( $p['detect'] ) || empty( $p['read'] ) || ! is_callable( $p['detect'] ) || ! is_callable( $p['read'] ) ) {
		continue;
	}
	$scope = (string) $id;
	try {
		if ( call_user_func( $p['detect'] ) ) {
			call_user_func( $p['read'] );
			$ran++;
			if ( ! empty( $p['component'] ) ) {
				$claimed[ $p['component'] ] = true;
			}
		}
	} catch ( \Throwable $e ) {
		$check( "reader {$id} runs", false, get_class( $e ) );
	}
}
$scope  = 'fingerprints';
$by_sdk = array( 'freemius' => array(), 'edd' => array(), 'surecart' => array() );
foreach ( minn_admin_license_fingerprints() as $fp ) {
	if ( empty( $claimed[ $fp['component'] ] ) && isset( $by_sdk[ $fp['sdk'] ] ) ) {
		$by_sdk[ $fp['sdk'] ][] = $fp;
	}
}
foreach ( $by_sdk as $sdk => $fps ) {
	$scope = "sdk:{$sdk}";
	call_user_func( "minn_admin_licenses_{$sdk}", $fps );
}
// And the whole screen, for anything read outside the readers.
$scope = 'screen';
minn_admin_licenses();
$scope = '';
foreach ( $tracers as $t ) {
	remove_filter( $t[0], $t[1], PHP_INT_MAX );
}
// A lab with few licensed plugins (the multisite one) lowers the floors.
$floor       = function ( $name, $default ) {
	return false === getenv( $name ) ? $default : (int) getenv( $name );
};
$min_readers = $floor( 'MINN_MIN_READERS', 20 );
$check( 'the lab runs licence readers', $ran >= $min_readers, "{$ran} detected" );

$keyed_tables = ( new ReflectionClass( 'Minn_Admin_DB' ) )->getConstant( 'KEYED_SECRETS' );
$reads_open   = 0;
foreach ( $reads as $store => $names ) {
	ksort( $names );
	foreach ( $names as $name => $scopes ) {
		$by = implode( ', ', array_slice( array_keys( $scopes ), 0, 3 ) );
		if ( 'table' === $store ) {
			$base = strtolower( preg_replace( '/^\d+_/', '', substr( $name, strlen( $wpdb->prefix ) ) ) );
			$ok   = isset( $keyed_tables[ $base ] ) || $listed( 'table', $name );
			$check( "table {$name} (read by {$by}) is a keyed secret table or listed", $ok, $ok ? '' : 'add its credential rows to Minn_Admin_DB::KEYED_SECRETS, or list it in $not_secret' );
			continue;
		}
		if ( $hidden( $store, $name ) ) {
			continue; // Redacted: nothing to say per row.
		}
		$pattern = $listed( $store, $name );
		if ( $pattern ) {
			$used[ $pattern ] = true;
			continue;
		}
		$reads_open++;
		$check( "{$store} {$name} (read by {$by}) is redacted or listed", false, 'a credential row goes in minn_admin_license_secret_options(); anything else in $not_secret with what it holds' );
	}
}
$check( 'every row the readers read is redacted or listed as no credential', 0 === $reads_open, count( $reads['option'] ?? array() ) . ' options, ' . count( $reads['sitemeta'] ?? array() ) . ' network options' );

// --- 2. What the provider files name -----------------------------------------

$files = array( 'includes/adapters/licenses.php' );
foreach ( glob( dirname( __DIR__, 2 ) . '/includes/adapters/*.php' ) as $f ) {
	if ( preg_match( "/add_filter\(\s*'minn_admin_license_providers'/", (string) file_get_contents( $f ) ) ) {
		$files[] = 'includes/adapters/' . basename( $f );
	}
}
$files = array_unique( $files );
// function => [ store, which argument holds the name, prefix the store adds ].
$calls = array(
	'get_option'            => array( 'option', 0, '' ),
	'add_option'            => array( 'option', 0, '' ),
	'update_option'         => array( 'option', 0, '' ),
	'delete_option'         => array( 'option', 0, '' ),
	'get_site_option'       => array( 'sitemeta', 0, '' ),
	'add_site_option'       => array( 'sitemeta', 0, '' ),
	'update_site_option'    => array( 'sitemeta', 0, '' ),
	'delete_site_option'    => array( 'sitemeta', 0, '' ),
	'get_network_option'    => array( 'sitemeta', 1, '' ),
	'update_network_option' => array( 'sitemeta', 1, '' ),
	'get_transient'         => array( 'option', 0, '_transient_' ),
	'set_transient'         => array( 'option', 0, '_transient_' ),
	'delete_transient'      => array( 'option', 0, '_transient_' ),
	'get_site_transient'    => array( 'option', 0, '_site_transient_' ),
	'set_site_transient'    => array( 'option', 0, '_site_transient_' ),
	'delete_site_transient' => array( 'option', 0, '_site_transient_' ),
	'get_user_meta'         => array( 'usermeta', 1, '' ),
	'update_user_meta'      => array( 'usermeta', 1, '' ),
	'delete_user_meta'      => array( 'usermeta', 1, '' ),
	'get_user_option'       => array( 'usermeta', 0, '' ),
	'update_user_option'    => array( 'usermeta', 1, '' ),
	'get_post_meta'         => array( 'postmeta', 1, '' ),
	'update_post_meta'      => array( 'postmeta', 1, '' ),
	'delete_post_meta'      => array( 'postmeta', 1, '' ),
);
$named = array();
foreach ( $files as $rel ) {
	$toks = token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $rel ) );
	$toks = array_values( array_filter( $toks, function ( $t ) {
		return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
	} ) );
	$n = count( $toks );
	for ( $i = 0; $i < $n - 1; $i++ ) {
		$t = $toks[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $calls[ strtolower( $t[1] ) ] ) || '(' !== $toks[ $i + 1 ] ) {
			continue;
		}
		$prev = $toks[ $i - 1 ] ?? null;
		if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			continue;
		}
		list( $store, $want, $add ) = $calls[ strtolower( $t[1] ) ];
		// Split the top-level arguments.
		$args  = array( array() );
		$depth = 0;
		for ( $j = $i + 2; $j < $n; $j++ ) {
			$x = $toks[ $j ];
			if ( in_array( $x, array( '(', '[', '{' ), true ) ) {
				$depth++;
			} elseif ( in_array( $x, array( ')', ']', '}' ), true ) ) {
				if ( 0 === $depth-- ) {
					break;
				}
			} elseif ( ',' === $x && 0 === $depth ) {
				$args[] = array();
				continue;
			}
			$args[ count( $args ) - 1 ][] = $x;
		}
		$arg = $args[ $want ] ?? array();
		$lit = function ( $x ) {
			return is_array( $x ) && T_CONSTANT_ENCAPSED_STRING === $x[0] ? stripcslashes( substr( $x[1], 1, -1 ) ) : null;
		};
		$prefix = null;
		$suffix = null;
		if ( 1 === count( $arg ) && null !== $lit( $arg[0] ) ) {
			$prefix = $lit( $arg[0] );
			$suffix = false; // Exact.
		} elseif ( count( $arg ) >= 3 ) {
			$first = $lit( $arg[0] );
			$last  = $lit( $arg[ count( $arg ) - 1 ] );
			if ( null !== $first && '.' === $arg[1] ) {
				$prefix = $first;
			}
			if ( null !== $last && '.' === $arg[ count( $arg ) - 2 ] ) {
				$suffix = $last;
			}
			if ( null === $prefix && null === $suffix ) {
				continue; // Fully dynamic: the reads pass above sees it.
			}
		} else {
			continue;
		}
		$line = is_array( $t ) ? $t[2] : 0;
		if ( false === $suffix ) {
			$name = $add . $prefix;
			$show = $name;
		} else {
			$name = $add . (string) $prefix . 'minnprobe' . (string) $suffix;
			$show = $add . (string) $prefix . '*' . (string) $suffix;
		}
		$named[ $store ][ $show ] = array( $name, "{$rel}:{$line}" );
	}
}
$static_open = 0;
foreach ( $named as $store => $names ) {
	foreach ( $names as $show => $info ) {
		list( $name, $where ) = $info;
		if ( false !== strpos( $show, '*' ) ) {
			// Built at run time. A redaction shape or a listed pattern that
			// covers every spelling settles it; otherwise the reads pass
			// judged each spelling it saw, and one it never saw needs
			// spelling out in $resolved.
			if ( empty( $resolved[ $store ][ $show ] ) && ( $hidden( $store, $name ) || $listed( $store, $name ) ) ) {
				$pattern = $listed( $store, $name );
				if ( $pattern ) {
					$used[ $pattern ] = true;
				}
				continue;
			}
			$re   = '/^' . str_replace( '\*', '.+', preg_quote( $show, '/' ) ) . '$/';
			$seen = array_filter( array_keys( ( $reads[ $store ] ?? array() ) + ( $reads[ $store_of( $store ) ] ?? array() ) ), function ( $n ) use ( $re ) {
				return (bool) preg_match( $re, $n );
			} );
			$spelled = $resolved[ $store ][ $show ] ?? null;
			if ( $seen && ! $spelled ) {
				continue;
			}
			if ( ! $spelled ) {
				$static_open++;
				$check( "{$store} {$show} ({$where}) was read on this site or is spelled out", false, 'nothing here read a name of that shape: list its spellings in $resolved' );
				continue;
			}
			foreach ( $spelled as $one ) {
				if ( ! preg_match( $re, $one ) ) {
					$static_open++;
					$check( "{$store} {$one} matches {$show}", false );
				} elseif ( ! $hidden( $store, $one ) && ! $listed( $store, $one ) ) {
					$static_open++;
					$check( "{$store} {$one} ({$where}) is redacted or listed", false, 'a credential row goes in minn_admin_license_secret_options(); anything else in $not_secret with what it holds' );
				}
			}
			continue;
		}
		if ( $hidden( $store, $name ) ) {
			continue;
		}
		$pattern = $listed( $store, $name ) ?? $listed( $store, $show );
		if ( $pattern ) {
			$used[ $pattern ] = true;
			continue;
		}
		$static_open++;
		$check( "{$store} {$show} ({$where}) is redacted or listed", false, 'a credential row goes in minn_admin_license_secret_options(); anything else in $not_secret with what it holds' );
	}
}
$check( 'every option and meta name the provider files use is redacted or listed', 0 === $static_open, count( $named['option'] ?? array() ) . ' option names in ' . implode( ', ', $files ) );

foreach ( $not_secret as $store => $list ) {
	foreach ( array_keys( $list ) as $pattern ) {
		if ( empty( $used[ $pattern ] ) && 'table' !== $store ) {
			printf( "NOTE  %s %s is listed but nothing read it on this site\n", $store, $pattern );
		}
	}
}

// --- 3. Copies of a credential in a row the browser shows ---------------------

// The string leaves of a stored value, through PHP serialization, JSON and a
// base64 wrapper (ACF PRO keeps its key as base64 of a serialized array).
$leaves = function ( $v, $path = '', $depth = 0 ) use ( &$leaves ) {
	if ( $depth > 6 ) {
		return array();
	}
	if ( is_string( $v ) ) {
		$t = trim( $v );
		if ( is_serialized( $t ) ) {
			$u = @unserialize( $t, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false !== $u || 'b:0;' === $t ) {
				return $leaves( $u, $path, $depth + 1 );
			}
		}
		if ( '' !== $t && ( '{' === $t[0] || '[' === $t[0] ) ) {
			$j = json_decode( $t, true );
			if ( is_array( $j ) ) {
				return $leaves( $j, $path, $depth + 1 );
			}
		}
		if ( strlen( $t ) >= 24 && preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $t ) ) {
			$b = base64_decode( $t, true );
			if ( false !== $b && ( is_serialized( $b ) || ( '' !== $b && '{' === $b[0] ) ) ) {
				return $leaves( $b, $path, $depth + 1 );
			}
		}
		return array( array( $path, $t ) );
	}
	if ( is_array( $v ) || is_object( $v ) ) {
		$out = array();
		foreach ( (array) $v as $k => $x ) {
			$out = array_merge( $out, $leaves( $x, $path . '/' . preg_replace( '/^\0[^\0]*\0/', '', (string) $k ), $depth + 1 ) );
		}
		return $out;
	}
	return array();
};
// A leaf that is a key or token: token alphabet, long, letters and digits
// both, and (inside a structure) under a name that says so.
$credential = function ( $path, $v ) {
	if ( strlen( $v ) < 16 || strlen( $v ) > 512 || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $v ) ) {
		return false;
	}
	if ( preg_match_all( '/\d/', $v ) < 3 || preg_match_all( '/[A-Za-z]/', $v ) < 3 ) {
		return false;
	}
	$leaf = strtolower( (string) substr( strrchr( '/' . $path, '/' ), 1 ) );
	if ( '' === $path ) {
		return true;
	}
	if ( preg_match( '/public|status|expir|date|time|count|limit|url|email|name|slug|type|version|item|plugin|theme|site|domain/', $leaf ) ) {
		return false;
	}
	return (bool) preg_match( '/key|licen[cs]e|token|secret|code|pass|auth|api|sig|hash|seed|salt|nonce|^val$|^value$|^\d*$/', $leaf );
};
// Where the browser redacts a vendor credential row: options and network
// options, and every keyed table's listed rows.
$sources = array();
$add_src = function ( $from, $value ) use ( &$sources, $leaves, $credential ) {
	$n = 0;
	foreach ( $leaves( $value ) as $l ) {
		if ( $credential( $l[0], $l[1] ) ) {
			$sources[ $l[1] ] = $from . $l[0];
			$n++;
		}
	}
	return $n;
};
$source_options = array();
foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_timeout\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_timeout\\_%'", ARRAY_A ) as $r ) {
	if ( $hidden( 'option', $r['option_name'] ) && ! in_array( $r['option_name'], array( 'auth_key', 'secure_auth_key', 'logged_in_key', 'nonce_key', 'auth_salt', 'secure_auth_salt', 'logged_in_salt', 'nonce_salt' ), true ) ) {
		if ( $add_src( 'option ' . $r['option_name'], $r['option_value'] ) ) {
			$source_options[] = $r['option_name'];
		}
	}
}
if ( is_multisite() ) {
	foreach ( $wpdb->get_results( "SELECT meta_key, meta_value FROM {$wpdb->sitemeta}", ARRAY_A ) as $r ) {
		if ( $hidden( 'sitemeta', $r['meta_key'] ) ) {
			$add_src( 'network option ' . $r['meta_key'], $r['meta_value'] );
		}
	}
}
$all_tables = $wpdb->get_col( 'SHOW TABLES' );
foreach ( $keyed_tables as $base => $spec ) {
	if ( in_array( $base, array( 'options', 'sitemeta', 'usermeta' ), true ) ) {
		continue; // Above, or user sessions and 2FA seeds (not vendor rows).
	}
	list( $vcol, $kcol, $keys ) = $spec;
	foreach ( $all_tables as $t ) {
		if ( 0 !== strcasecmp( $t, $wpdb->prefix . $base ) ) {
			continue;
		}
		$in = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL -- identifiers from the class constant and SHOW TABLES.
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT `{$kcol}` k, `{$vcol}` v FROM `{$t}` WHERE `{$kcol}` IN ({$in})", $keys ), ARRAY_A ) as $r ) {
			$add_src( "{$base} " . $r['k'], $r['v'] );
		}
	}
}
foreach ( $wpdb->get_results( "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'wpmdb_licence_key'", ARRAY_A ) as $r ) {
	$add_src( 'usermeta wpmdb_licence_key', $r['meta_value'] );
}
$check( 'the lab holds vendor credentials to trace', count( $sources ) >= $floor( 'MINN_MIN_CREDENTIALS', 10 ), count( $sources ) . ' found' );

// Each credential raw, and the part of its base64 encoding that does not
// depend on where it sits in the encoded string (EDD package tokens and
// similar carry the key that way), standard and URL-safe.
$needles = array();
foreach ( $sources as $v => $from ) {
	$needles[ $v ] = $v;
	for ( $s = 0; $s < 3; $s++ ) {
		$len  = (int) floor( ( strlen( $v ) - $s ) / 3 ) * 3;
		$core = base64_encode( substr( $v, $s, $len ) );
		if ( strlen( $core ) >= 16 ) {
			$needles[ $core ]                      = $v;
			$needles[ strtr( $core, '+/', '-_' ) ] = $v;
		}
	}
}
$text_types = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'json', 'blob', 'mediumblob', 'longblob', 'tinyblob' );
$kv_tables  = array_map( 'strtolower', array( $wpdb->options, $wpdb->sitemeta, $wpdb->usermeta, $wpdb->postmeta, $wpdb->termmeta, $wpdb->commentmeta ) );
$copies     = 0;
$logged     = array();
$scanned    = 0;
foreach ( $all_tables as $t ) {
	if ( 0 !== stripos( $t, $wpdb->base_prefix ) ) {
		continue;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL -- identifier from SHOW TABLES.
	$cols = $wpdb->get_results( "SHOW COLUMNS FROM `{$t}`", ARRAY_A );
	$keys = array();
	$text = array();
	foreach ( (array) $cols as $c ) {
		if ( 'PRI' === $c['Key'] ) {
			$keys[] = $c['Field'];
		}
		if ( in_array( strtolower( preg_replace( '/\(.*/', '', $c['Type'] ) ), $text_types, true ) ) {
			$text[] = $c['Field'];
		}
	}
	foreach ( $text as $col ) {
		$or = array();
		foreach ( array_keys( $needles ) as $needle ) {
			$or[] = $wpdb->prepare( "`{$col}` LIKE %s", '%' . $wpdb->esc_like( $needle ) . '%' ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$scanned++;
		// phpcs:ignore WordPress.DB.PreparedSQL -- identifiers from SHOW TABLES / SHOW COLUMNS, values prepared above.
		$rows = $wpdb->get_results( "SELECT * FROM `{$t}` WHERE " . implode( ' OR ', $or ) . ' LIMIT 50', ARRAY_A );
		foreach ( (array) $rows as $row ) {
			if ( $hidden_cell( $t, $col, $row ) ) {
				continue;
			}
			$cell = (string) $row[ $col ];
			foreach ( $needles as $needle => $v ) {
				if ( false === strpos( $cell, $needle ) ) {
					continue;
				}
				$id = isset( $row['option_name'] ) ? $row['option_name'] : ( isset( $row['meta_key'] ) ? $row['meta_key'] : implode( ',', array_map( function ( $k ) use ( $row ) {
					return $k . '=' . $row[ $k ];
				}, $keys ) ) );
				if ( ! in_array( strtolower( $t ), $kv_tables, true ) ) {
					$logged[ "{$t}.{$col} copies {$sources[ $v ]}" ] = ( $logged[ "{$t}.{$col} copies {$sources[ $v ]}" ] ?? 0 ) + 1;
					break;
				}
				$copies++;
				$check( "{$t}.{$col} [{$id}] holds no copy of {$sources[ $v ]}", false, ( $needle === $v ? 'raw' : 'base64' ) . ', ' . strlen( $v ) . ' chars: redact that row too' );
				break;
			}
		}
	}
}
$check( 'no option, network option or meta row the browser shows holds a credential it redacts elsewhere', 0 === $copies, "{$scanned} columns, " . count( $sources ) . ' credentials' );
foreach ( $logged as $what => $n ) {
	printf( "NOTE  %s in %d%s row(s): shown by the browser, not reachable by name\n", $what, $n, 50 === $n ? '+' : '' );
}

// --- 4. What the browser answers ---------------------------------------------

// The predicate above is the one the rows route calls; this asks the route.
$shown = 0;
foreach ( $source_options as $name ) {
	$req = new WP_REST_Request( 'GET', '/minn-admin/v1/db/rows' );
	$req->set_param( 'table', $wpdb->options );
	$req->set_param( 'fcol', 'option_name' );
	$req->set_param( 'fq', $name );
	$res  = rest_do_request( $req );
	$data = $res->get_data();
	if ( 200 !== $res->get_status() ) {
		$shown++;
		$check( "the browser answers for option {$name}", false, (string) $res->get_status() );
		continue;
	}
	$cols = wp_list_pluck( $data['columns'], 'name' );
	$ni   = array_search( 'option_name', $cols, true );
	$vi   = array_search( 'option_value', $cols, true );
	foreach ( $data['rows'] as $row ) {
		if ( $row[ $ni ] === $name && ( ! is_array( $row[ $vi ] ) || empty( $row[ $vi ]['redacted'] ) ) ) {
			$shown++;
			$check( "the browser hides option {$name}", false );
		}
	}
}
$check( "the database browser's rows answer redacts each of them", 0 === $shown, count( $source_options ) . ' rows' );

$summary();
