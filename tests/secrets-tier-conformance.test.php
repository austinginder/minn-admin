<?php
/**
 * The secrets tier: every credential store an adapter declares
 * (minn_admin_secrets(), includes/kernel/class-minn-admin-kernel-secrets.php)
 * is hidden, and an option an adapter touches that holds a key is declared.
 *
 * 0. Declarations. Each reads in full (no problems reported), and each sits
 *    at the top level of its adapter file, outside any function or
 *    condition, so it is made whether or not its plugin is active: a
 *    deactivated plugin's keys stay in the database. A frozen manifest
 *    lists every store moved off the central lists; each must stay
 *    declared, as the same kind.
 * 1. Every declared store is hidden. A fake key is seeded into each store
 *    declared or in the manifest (so a deleted declaration is still tested)
 *    (an option row, a row of a name shape, each declared leaf, a user meta
 *    key, a column), then read through
 *    the database browser's row route, its value search, and a minn-admin/v1
 *    route that hands the stored value back as an adapter that slips would
 *    (registered by this suite for the run): the key never appears whole,
 *    and the egress fence lists it. Tokens kept per user or per account
 *    (user_tokens, column_tokens) are checked hidden in the browser and, by
 *    design, left out of the fence's list, which stays bounded on a large
 *    site. A declared leaf's harder values are found too: a site key holding
 *    a "/", JSON below the leaf, the leaf's name in another case, a value
 *    nine levels down, one over 512 characters, a secret with one digit.
 *    A row that exists already is borrowed: its stored bytes are kept, the
 *    fixture is written with SQL (no option hooks: the vendor sees nothing),
 *    and the bytes are put back with SQL however the run ends. Each change
 *    is written to a restore file (0600, in the temp directory) before it
 *    is made, and a run that finds one replays it first, so a run killed
 *    outright leaves no fake key behind.
 * 2. The check that catches the next gap. Every option an adapter reads or
 *    writes (a literal name in get_option, update_option, add_option and
 *    their site and network forms, a literal prefix joined to a variable,
 *    and the names its declarations give) is read live, and the vendor
 *    shapes below are seeded for the adapters that own them. A value holding
 *    a credential-named leaf (a value stored bare is named by its row; key,
 *    secret, token, password, passphrase,
 *    api, licence or license, auth, jwt, private, salt, seed, nonce,
 *    credential) whose value looks like a secret fails unless the database
 *    browser redacts its row: a declaration or a central list.
 *    Sensible exclusions, so the check names only real keys:
 *    - a leaf whose name describes a credential rather than holds it: an id,
 *      a URL or endpoint, an expiry or date, a status, type, mode, label,
 *      name, hint, length or count, a flag (enabled, active, valid,
 *      required), a public or publishable key, a site key, an account login
 *      or email, a storage path, bucket, folder, region, host or port;
 *    - a value that is no secret: empty, numeric, shorter than 8
 *      characters, a URL, an email address, text with spaces, a lowercase
 *      setting word (disabled, automatic), a mask (****, •••), a fence
 *      token, or a fixed word a plugin stores (yes, no, on, off);
 *    - rows listed in $not_secret below, each with what it holds and why it
 *      stays readable.
 *
 * Prints names, never values.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/secrets-tier-conformance.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$once = static function ( callable $fn ) {
	$done = false;
	$run  = static function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};

if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Minn_Admin_Kernel_Secrets' ) || ! class_exists( 'Minn_Admin_DB' ) || ! class_exists( 'Minn_Admin_Egress' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}
global $wpdb;
rest_get_server();
$tag  = strtolower( wp_generate_password( 6, false ) );
$root = dirname( __DIR__ );

$send = static function ( $method, $route, $query = array() ) {
	$server = rest_get_server();
	$req    = new WP_REST_Request( $method, $route );
	foreach ( $query as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	return array( $res->get_status(), (string) wp_json_encode( $data ) );
};
$forget = static function () {
	Minn_Admin_DB::forget_credentials();
	Minn_Admin_Egress::reset();
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};
// A fake key: token alphabet, letters and digits, long enough for the fence.
$fake = static function ( $label ) use ( $tag ) {
	return 'mS' . $tag . substr( md5( $label . $tag ), 0, 20 ) . 'Q7z';
};

$declared = Minn_Admin_Kernel_Secrets::declared();

/*
 * 0. Declarations read in full, at the top level of their adapter files.
 */
$problems = Minn_Admin_Kernel_Secrets::problems();
$check( 'every declaration reads in full', ! $problems, implode( '; ', $problems ) );
$check( 'adapters declare credential stores (' . count( $declared ) . ' plugins)', count( $declared ) >= 4 );

$top_level = array();
$nested    = array();
foreach ( glob( $root . '/includes/adapters/*.php' ) as $file ) {
	$toks  = token_get_all( (string) file_get_contents( $file ) );
	$depth = 0;
	$n     = count( $toks );
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $toks[ $i ];
		if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
			++$depth;
		} elseif ( '}' === $t ) {
			--$depth;
		} elseif ( is_array( $t ) && T_STRING === $t[0] && 'minn_admin_secrets' === strtolower( $t[1] ) ) {
			$j = $i + 1;
			while ( $j < $n && is_array( $toks[ $j ] ) && T_WHITESPACE === $toks[ $j ][0] ) {
				++$j;
			}
			if ( '(' !== ( $toks[ $j ] ?? '' ) ) {
				continue;
			}
			if ( 0 === $depth ) {
				$top_level[ basename( $file ) ] = true;
			} else {
				$nested[] = basename( $file ) . ':' . $t[2];
			}
		}
	}
}
$check( 'every declaration is made at the top level of its adapter, whether or not its plugin is active', ! $nested, implode( ', ', $nested ) );
$files = array();
foreach ( $declared as $plugin => $d ) {
	foreach ( $d['files'] as $f ) {
		$files[ $f ] = true;
	}
}
$missing = array_diff( array_keys( $top_level ), array_keys( $files ) );
$check( 'every adapter that declares is loaded and its declaration recorded (' . count( $top_level ) . ' files)', ! $missing, implode( ', ', $missing ) );

// Every store moved off the central lists (or found by the gap check
// below), frozen: a declaration deleted
// from its adapter (a row, a leaf, a user meta key, a column) fails here and
// in part 1, which seeds these whether or not they are still declared. An
// entry leaves this list only with a reason in its commit.
$manifest = array(
	'contact-form-7'         => array( 'options' => array( 'wpcf7' => array( 'recaptcha/*', 'turnstile/*' ) ) ),
	'wordpress-seo'          => array(
		'options'     => array( 'wpseo' => array() ),
		'user_tokens' => array( '_yoast_wpseo_ai_generator_access_jwt', '_yoast_wpseo_ai_generator_refresh_jwt' ),
	),
	'ameliabooking'          => array(
		'options'       => array( 'amelia_settings' => array() ),
		'column_tokens' => array(
			'amelia_providers_to_google_calendar'  => array( 'token' ),
			'amelia_providers_to_outlook_calendar' => array( 'token' ),
		),
	),
	'updraftplus'            => array(
		'options'  => array_fill_keys(
			array(
				'updraft_updraftvault', 'updraft_dropbox', 'updraft_s3', 'updraft_cloudfiles', 'updraft_googledrive', 'updraft_onedrive',
				'updraft_ftp', 'updraft_azure', 'updraft_sftp', 'updraft_googlecloud', 'updraft_backblaze', 'updraft_webdav', 'updraft_s3generic',
				'updraft_pcloud', 'updraft_openstack', 'updraft_dreamobjects',
				'updraft_central_localkeys', 'updraft_migrator_localkeys', 'updraft_remotesites', 'updraft_encryptionphrase',
				'updraft_adminlocking', 'updraft_extradbs', 'updraftplus-addons_options',
				'updraft_googledrive_secret', 'updraft_googledrive_token', 'updraft_dropboxtk_access_token', 'updraft_dropboxtk_request_token',
				'updraft_s3_pass', 'updraft_s3generic_pass', 'updraft_dreamobjects_pass', 'updraft_cloudfiles_apikey', 'updraft_ftp_pass',
				'updraft_sftp_settings', 'updraft_webdav_settings', 'updraft_jobdata_*',
			),
			array()
		),
		'usermeta' => array( 'updraftcentral_login_key' ),
	),
	'suretriggers'           => array( 'options' => array( 'suretrigger_options' => array() ) ),
	'wp-statistics'          => array( 'options' => array_fill_keys( array( 'wp_statistics', 'wp_statistics_licenses', 'wp_statistics_cipher_key' ), array() ) ),
	'better-wp-security'     => array( 'options' => array( 'itsec-storage' => array() ) ),
	'cleantalk-spam-protect' => array( 'options' => array( 'cleantalk_sfw_worker_self_tokens' => array() ) ),
	'jetpack'                => array( 'options' => array_fill_keys( array( 'jetpack_private_options', 'jetpack_secrets' ), array() ) ),
	'wp-migrate-db-pro'      => array( 'usermeta' => array( 'wpmdb_licence_key' ) ),
	'disembark'              => array( 'options' => array( 'disembark_token' => array() ) ),
);
$undeclared = array();
$entries    = 0;
foreach ( $manifest as $plugin => $m ) {
	$d = $declared[ $plugin ] ?? array();
	foreach ( $m as $kind => $list ) {
		foreach ( $list as $k => $v ) {
			++$entries;
			if ( 'options' === $kind ) {
				$have = $d['options'][ $k ] ?? null;
				if ( null === $have || array_diff( $v, $have ) ) {
					$undeclared[] = "{$plugin} {$kind} {$k}" . ( $v ? ' (' . implode( ', ', null === $have ? $v : array_diff( $v, $have ) ) . ')' : '' );
				}
			} elseif ( 'columns' === $kind || 'column_tokens' === $kind ) {
				$miss = array_diff( $v, $d[ $kind ][ $k ] ?? array() );
				if ( $miss ) {
					$undeclared[] = "{$plugin} {$kind} {$k}." . implode( ',', $miss );
				}
			} elseif ( ! in_array( $v, $d[ $kind ] ?? array(), true ) ) {
				$undeclared[] = "{$plugin} {$kind} {$v}";
			}
		}
	}
}
$check( "every store moved off the central lists is still declared, as the same kind ({$entries} entries)", ! $undeclared, implode( '; ', $undeclared ) );

// A malformed declaration registers its readable form and reports every
// item it drops or widens; a new declaration makes every list read before
// it stale. Probe declarations live only in this process.
$rp_db     = new ReflectionProperty( 'Minn_Admin_DB', 'credentials' );
$rp_egress = new ReflectionProperty( 'Minn_Admin_Egress', 'credentials' );
$rp_db->setAccessible( true );
$rp_egress->setAccessible( true );
Minn_Admin_DB::egress_credentials();
$rp_egress->setValue( null, array( 'stale' => 'abcdef' ) );
$before = count( Minn_Admin_Kernel_Secrets::problems() );
$pt     = 'minnprobe' . $tag;
add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
minn_admin_secrets(
	$pt,
	array(
		'options'       => $pt . '_lone',
		'columns'       => array( 'token' ),
		'column_tokens' => array( $pt . '_t' => 'cal' ),
		'usermeta'      => array( '*' . $pt . '_um' ),
	)
);
minn_admin_secrets( $pt . '2', array( 'options' => array( $pt . '_a_*_b_*', '*', $pt . '_leafy' => array( 'ok', 7 ), '*_' . $pt, $pt . '%x', $pt . '_all' => array( '*' ) ) ) );
remove_filter( 'doing_it_wrong_trigger_error', '__return_false' );
$grew = count( Minn_Admin_Kernel_Secrets::problems() ) - $before;
$check( "a malformed declaration reports every item it drops or widens ({$grew} of 10)", 10 === $grew, implode( '; ', array_slice( Minn_Admin_Kernel_Secrets::problems(), $before ) ) );
$check(
	'a malformed declaration still registers its readable form: a lone name, the widest shape, a lone column, a suffix-only user meta shape, a named row beside a bad leaf',
	Minn_Admin_DB::is_credential_option( $pt . '_lone' ) && Minn_Admin_DB::is_credential_option( $pt . '_a_x_b_y' ) && Minn_Admin_DB::is_credential_option( $pt . '_leafy' )
		&& Minn_Admin_DB::redacts_cell( $wpdb->prefix . $pt . '_t', 'cal', array( 'cal' => 'x' ) )
		&& Minn_Admin_DB::redacts_cell( $wpdb->usermeta, 'meta_value', array( 'meta_key' => 'x' . $pt . '_um', 'meta_value' => 'x' ) )
);
$check( "a declaration of '*' alone is refused, not an every-row shape", ! Minn_Admin_DB::is_credential_option( 'blogname' ) );
$check( "a new declaration drops the browser's and the fence's lists read before it", null === $rp_db->getValue() && null === $rp_egress->getValue() );

/*
 * 1. Every declared store is hidden in the browser and found by the fence.
 */

// The route an adapter that slips would be: it hands back what the suite
// puts in $echo, under minn-admin/v1, where the egress fence runs.
$echo  = '';
$probe = '/minn-admin/v1/secrets-probe-' . $tag;
register_rest_route(
	'minn-admin/v1',
	'/secrets-probe-' . $tag,
	array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => static function () use ( &$echo ) {
			return array( 'value' => $echo );
		},
	)
);
$browser_cell = static function ( $table, $pk, $col ) use ( $send ) {
	list( $st, $body ) = $send( 'GET', '/minn-admin/v1/db/row', array( 'table' => $table, 'pk' => wp_json_encode( $pk ) ) );
	$d    = json_decode( $body, true );
	$cell = null;
	foreach ( (array) ( $d['columns'] ?? array() ) as $i => $c ) {
		if ( $col === ( $c['name'] ?? '' ) ) {
			$cell = $d['cells'][ $i ] ?? null;
		}
	}
	return array( $st, $body, $cell );
};
// One store's fakes through every reader: the browser's row and value
// search, the fence's list and a route that hands the stored value back.
$through = static function ( $what, $table, $pk, $col, $key_col, $key, $stored, array $fakes, $searched = true ) use ( $check, $send, $browser_cell, &$echo, $probe ) {
	list( $st, $body, $cell ) = $browser_cell( $table, $pk, $col );
	$whole = array();
	foreach ( $fakes as $f ) {
		if ( false !== strpos( $body, $f ) ) {
			$whole[] = 'browser';
		}
	}
	$check( "{$what}: the browser's row opens redacted ({$st})", 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ) && ! $whole, null === $cell ? 'no cell' : 'printed' );
	$sq = array( 'table' => $table, 'fcol' => $col, 'fq' => substr( $fakes[0], 2, 14 ), 'per_page' => 100 );
	list( $st, $body ) = $send( 'GET', '/minn-admin/v1/db/rows', $sq );
	$found = ( '' !== $key_col && false !== strpos( $body, '"' . $key . '"' ) ) || false !== strpos( $body, $fakes[0] );
	$check( "{$what}: a value search for part of the key does not answer for the row ({$st})", ! $found );
	$list = Minn_Admin_DB::egress_credentials();
	$miss = array();
	foreach ( $fakes as $i => $f ) {
		if ( $searched !== in_array( $f, $list, true ) ) {
			$miss[] = '#' . $i;
		}
	}
	$check( $searched ? "{$what}: the egress fence lists every fake key in it" : "{$what}: a token kept per user or account stays out of the fence's list (hidden by name only)", ! $miss, implode( ', ', $miss ) );
	if ( $searched ) {
		$echo           = $stored;
		list( $st, $b ) = $send( 'GET', $probe );
		$echo           = '';
		$leak           = array_filter(
			$fakes,
			static function ( $f ) use ( $b ) {
				return false !== strpos( $b, $f );
			}
		);
		$check( "{$what}: a minn-admin/v1 response handing the stored value back shows no key whole ({$st})", 200 === $st && ! $leak && false !== strpos( $b, '[redacted:' ), count( $leak ) . ' whole' );
	}
};

// A fixture for an option row: its declared leaves, each holding a fake key
// under a public-looking name (hex, which spells no credential word), or a
// bare fake key for a row declared whole.
$fixture = static function ( $name, array $leaves ) use ( $fake ) {
	if ( ! $leaves ) {
		$k = $fake( $name );
		return array( $k, array( $k ) );
	}
	$value = array();
	$fakes = array();
	foreach ( $leaves as $leaf ) {
		$segs = explode( '/', trim( $leaf, '/' ) );
		$at   = &$value;
		foreach ( $segs as $seg ) {
			$seg = '*' === $seg ? '6Lc' . substr( md5( $name . $leaf ), 0, 24 ) : $seg;
			if ( ! isset( $at[ $seg ] ) || ! is_array( $at[ $seg ] ) ) {
				$at[ $seg ] = array();
			}
			$at = &$at[ $seg ];
		}
		$k       = $fake( $name . $leaf );
		$at      = $k;
		$fakes[] = $k;
		unset( $at );
	}
	return array( $value, $fakes );
};

// The cases: every declaration, and every entry of the manifest whether or
// not it is still declared, so a declaration deleted from its adapter is
// still seeded here and fails.
$cases = $manifest;
foreach ( $declared as $plugin => $d ) {
	foreach ( array( 'options', 'usermeta', 'user_tokens', 'columns', 'column_tokens' ) as $kind ) {
		foreach ( $d[ $kind ] as $k => $v ) {
			if ( 'options' === $kind ) {
				$cases[ $plugin ]['options'][ $k ] = array_values( array_unique( array_merge( $cases[ $plugin ]['options'][ $k ] ?? array(), $v ) ) );
			} elseif ( 'columns' === $kind || 'column_tokens' === $kind ) {
				$cases[ $plugin ][ $kind ][ $k ] = array_values( array_unique( array_merge( $cases[ $plugin ][ $kind ][ $k ] ?? array(), $v ) ) );
			} else {
				$cases[ $plugin ][ $kind ][] = $v;
			}
		}
	}
}


// Every fixture is written to a restore file before it is made, and the file
// is replayed when the next run starts: a run killed outright (SIGKILL, a
// crashed process) leaves no fake key in a vendor's live row. The file holds
// the borrowed rows' stored bytes, so it lives in a directory only this user
// can enter (0700, made under umask 077, refused when it is a link or
// another user's), is created exclusively, and a file there that is a link
// or another user's is never read. What a file asks is checked before it is
// done: only option rows this suite seeds, only rows of the declared tables
// by their declared column, only the fixture accounts it makes.
$restore_dir  = rtrim( get_temp_dir(), '/' ) . '/minn-secrets-tier-' . getmyuid();
$restore_file = $restore_dir . '/restore-' . md5( ABSPATH . '|' . get_current_blog_id() ) . '.json';
$owned        = static function ( $path, $dir ) {
	clearstatcache( true, $path );
	if ( is_link( $path ) || ! file_exists( $path ) || fileowner( $path ) !== getmyuid() ) {
		return false;
	}
	return $dir ? ( is_dir( $path ) && 0700 === ( fileperms( $path ) & 0777 ) ) : is_file( $path );
};
$old_umask = umask( 077 );
if ( ! file_exists( $restore_dir ) && ! is_link( $restore_dir ) ) {
	mkdir( $restore_dir, 0700 );
}
umask( $old_umask );
if ( ! $owned( $restore_dir, true ) ) {
	$check( 'the restore directory is a private directory of this user', false, 'refusing to write fixtures without a safe restore file' );
	return;
}
$journal      = array( 'options' => array(), 'rows' => array(), 'users' => array() );
$save_journal = static function () use ( &$journal, $restore_file, $owned ) {
	$old_umask = umask( 077 );
	try {
		if ( ! file_exists( $restore_file ) && ! is_link( $restore_file ) ) {
			$fh = fopen( $restore_file, 'x' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $fh ) {
				throw new RuntimeException( 'restore file not created' );
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! $owned( $restore_file, false ) ) {
			throw new RuntimeException( 'restore file is not this user\'s own' );
		}
		file_put_contents( $restore_file, (string) wp_json_encode( $journal ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	} finally {
		umask( $old_umask );
	}
};
// What a restore file may ask: option rows the cases seed (a shape's row
// carries the suite's six-character tag), declared tables by a declared
// column, fixture logins.
$allowed_option = static function ( $name ) use ( $cases ) {
	foreach ( $cases as $d ) {
		foreach ( array_keys( $d['options'] ?? array() ) as $n ) {
			$at = strpos( $n, '*' );
			if ( false === $at ? $n === $name : preg_match( '/^' . preg_quote( substr( $n, 0, $at ), '/' ) . 'minn[a-z0-9]{6}' . preg_quote( substr( $n, $at + 1 ), '/' ) . '$/', $name ) ) {
				return true;
			}
		}
	}
	return false;
};
$allowed_row = static function ( $table, $col ) use ( $cases, $wpdb ) {
	foreach ( $cases as $d ) {
		foreach ( array( 'columns', 'column_tokens' ) as $kind ) {
			foreach ( $d[ $kind ] ?? array() as $t => $cols ) {
				if ( $wpdb->prefix . $t === $table && in_array( $col, $cols, true ) ) {
					return true;
				}
			}
		}
	}
	return false;
};
$replay = static function ( array $j ) use ( $wpdb, $allowed_option, $allowed_row ) {
	$refused = 0;
	foreach ( (array) ( $j['options'] ?? array() ) as $name => $was ) {
		if ( ! is_string( $name ) || ! $allowed_option( $name ) || ( null !== $was && ( ! isset( $was['v'], $was['a'] ) || ! is_string( $was['v'] ) || ! is_string( $was['a'] ) ) ) ) {
			++$refused;
			continue;
		}
		if ( null === $was ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => base64_decode( $was['v'] ), 'autoload' => $was['a'] ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		wp_cache_delete( $name, 'options' );
	}
	foreach ( (array) ( $j['rows'] ?? array() ) as $r ) {
		if ( ! is_array( $r ) || 3 !== count( $r ) || ! is_string( $r[0] ) || ! is_string( $r[1] ) || ! is_string( $r[2] ) || ! $allowed_row( $r[0], $r[1] ) ) {
			++$refused;
			continue;
		}
		$wpdb->delete( $r[0], array( $r[1] => $r[2] ) );
	}
	foreach ( (array) ( $j['users'] ?? array() ) as $login ) {
		if ( ! is_string( $login ) || ! preg_match( '/^minnsecrets_[a-z0-9]{6}$/', $login ) ) {
			++$refused;
			continue;
		}
		$u = get_user_by( 'login', $login );
		if ( $u ) {
			if ( is_multisite() ) {
				require_once ABSPATH . 'wp-admin/includes/ms.php';
				wpmu_delete_user( $u->ID );
			} else {
				wp_delete_user( $u->ID );
			}
		}
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return $refused;
};
if ( is_link( $restore_file ) || ( file_exists( $restore_file ) && ! $owned( $restore_file, false ) ) ) {
	$check( 'a restore file that is a link or another user\'s is never replayed', true );
	unlink( $restore_file );
} elseif ( file_exists( $restore_file ) ) {
	$left    = json_decode( (string) file_get_contents( $restore_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$refused = $replay( is_array( $left ) ? $left : array() );
	unlink( $restore_file );
	$check( 'an earlier run that ended without its cleanup is put back from its restore file', 0 === $refused, "{$refused} entries outside what this suite seeds were refused" );
}
$put_back = $once( static function () use ( &$journal, $replay, $restore_file, $forget ) {
	$replay( $journal );
	if ( file_exists( $restore_file ) && ! is_link( $restore_file ) ) {
		unlink( $restore_file );
	}
	// The scan-rule record was written for this run's lists (its probe
	// declarations change the stamp): the next list build writes it again.
	delete_option( 'minn_admin_egress_loose' );
	$forget();
} );
// A planted restore file asks nothing outside what the suite seeds.
$planted = array(
	'options' => array( 'siteurl' => null, 'blogname' => array( 'v' => base64_encode( 'x' ), 'a' => 'on' ) ),
	'rows'    => array( array( $wpdb->users, 'ID', '1' ), array( $wpdb->prefix . 'amelia_providers_to_google_calendar', 'id', '1' ) ),
	'users'   => array( 'admin', 'minnsecrets_' . $tag . 'x' ),
);
$rejected = 0;
foreach ( $planted['options'] as $name => $unused ) {
	$rejected += $allowed_option( $name ) ? 0 : 1;
}
foreach ( $planted['rows'] as $r ) {
	$rejected += $allowed_row( $r[0], $r[1] ) ? 0 : 1;
}
foreach ( $planted['users'] as $login ) {
	$rejected += preg_match( '/^minnsecrets_[a-z0-9]{6}$/', $login ) ? 0 : 1;
}
$check( "a restore file is held to what the suite seeds: no other option, table, column or account ({$rejected} of 6 planted entries refused)", 6 === $rejected );

$seed_option = static function ( $name, $value ) use ( &$journal, $save_journal, $wpdb ) {
	$raw = maybe_serialize( $value );
	$was = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
	if ( ! array_key_exists( $name, $journal['options'] ) ) {
		$journal['options'][ $name ] = $was ? array( 'v' => base64_encode( $was['option_value'] ), 'a' => $was['autoload'] ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$save_journal();
	}
	if ( $was ) {
		$wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => $name ) );
	} else {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => 'off' ) );
	}
	wp_cache_delete( $name, 'options' );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
};


try {
	// Seed every option row and shape first, then read them all: the
	// fence's list is built once, as a request builds it.
	$opt_cases = array();
	foreach ( $cases as $plugin => $d ) {
		foreach ( $d['options'] ?? array() as $name => $leaves ) {
			$at       = strpos( $name, '*' );
			$row_name = false === $at ? $name : substr( $name, 0, $at ) . 'minn' . $tag . substr( $name, $at + 1 );
			list( $value, $fakes ) = $fixture( $row_name, $leaves );
			$id          = $seed_option( $row_name, $value );
			$opt_cases[] = array( "{$plugin} option {$name}" . ( $leaves ? ' (leaves ' . implode( ', ', $leaves ) . ')' : '' ), $id, $row_name, $value, $fakes, $leaves );
		}
	}
	// The values a declared leaf must still find: a site key holding a "/",
	// a JSON string below the leaf, the leaf's name in another case, a value
	// nine levels down, a value longer than 512 characters, and a captcha
	// secret with a single digit.
	$hard = null;
	foreach ( $cases['contact-form-7']['options'] ?? array() as $name => $leaves ) {
		if ( in_array( 'recaptcha/*', $leaves, true ) && in_array( 'turnstile/*', $leaves, true ) ) {
			$hf       = array(
				'slash' => $fake( 'slash' ),
				'json'  => $fake( 'json' ),
				'case'  => $fake( 'case' ),
				'deep'  => $fake( 'deep' ),
				'long'  => str_repeat( $fake( 'long' ), 20 ),
				'digit' => 'Lo' . ucfirst( preg_replace( '/\d/', '', $tag ) ) . 'XyZwAbCdEfGh-1',
			);
			$hard     = array( $hf, array(
				'recaptcha' => array(
					'6Lc/site+key/' . $tag              => $hf['slash'],
					'deep'                              => array( 'a' => array( 'b' => array( 'c' => array( 'd' => array( 'e' => array( 'f' => array( 'g' => $hf['deep'] ) ) ) ) ) ) ),
					'6LcLong' . $tag                    => $hf['long'],
				),
				'turnstile' => array(
					'0x4AAAA' . $tag => wp_json_encode( array( 'k1' => $hf['json'] ) ),
					'0x4BBBB' . $tag => $hf['digit'],
				),
				'RECAPTCHA' => array( '6LcUpper' . $tag => $hf['case'] ),
			) );
		}
	}
	// Declared columns, on a row added to the live table when it exists.
	$col_cases = array();
	foreach ( $cases as $plugin => $d ) {
		foreach ( array( 'columns' => true, 'column_tokens' => false ) as $kind => $searched ) {
			foreach ( $d[ $kind ] ?? array() as $table => $cols ) {
				$t = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) );
				if ( ! $t ) {
					$skip( "{$plugin} {$kind} of {$table}: no such table here" );
					continue;
				}
				$pk = $wpdb->get_results( "SHOW KEYS FROM `{$t}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the name SHOW TABLES gave.
				if ( 1 !== count( $pk ) ) {
					$skip( "{$plugin} {$kind} of {$table}: no single-column primary key to clean up by" );
					continue;
				}
				$pkc       = $pk[0]['Column_name'];
				$row       = array();
				$cols_meta = $wpdb->get_results( "SHOW COLUMNS FROM `{$t}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
				foreach ( $cols_meta as $c ) {
					if ( 'NO' === $c['Null'] && null === $c['Default'] && false === strpos( (string) $c['Extra'], 'auto_increment' ) ) {
						$row[ $c['Field'] ] = preg_match( '/int|decimal|float|double/i', $c['Type'] ) ? 0 : '';
					}
				}
				$fakes = array();
				foreach ( $cols as $col ) {
					$k             = $fake( $table . $col );
					$row[ $col ]   = wp_json_encode( array( 'access_token' => $k, 'token_type' => 'Bearer' ) );
					$fakes[ $col ] = $k;
				}
				$first                = array_key_first( $fakes );
				$journal['rows'][]    = array( $t, $first, $row[ $first ] );
				$save_journal();
				if ( ! $wpdb->insert( $t, $row ) ) {
					$skip( "{$plugin} {$kind} of {$table}: the fixture row was refused" );
					continue;
				}
				$col_cases[] = array( "{$plugin} {$kind}", $t, $pkc, (int) $wpdb->insert_id, $fakes, $row, $searched );
			}
		}
	}
	// Declared user meta, on a fixture user.
	$um_cases = array();
	$um_all   = array();
	foreach ( $cases as $plugin => $d ) {
		foreach ( array( 'usermeta' => true, 'user_tokens' => false ) as $kind => $searched ) {
			foreach ( array_unique( $d[ $kind ] ?? array() ) as $key ) {
				$um_all[] = array( $plugin, $kind, $key, $searched );
			}
		}
	}
	if ( $um_all ) {
		$login               = 'minnsecrets_' . $tag;
		$journal['users'][] = $login;
		$save_journal();
		$fixture_user = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.test', 'role' => 'subscriber' ) );
		if ( is_wp_error( $fixture_user ) ) {
			$skip( 'declared user meta: fixture user not made' );
		} else {
			foreach ( $um_all as $u ) {
				list( $plugin, $kind, $key, $searched ) = $u;
				$meta_key = str_replace( '*', 'minn' . $tag, $key );
				$k        = $fake( $meta_key );
				update_user_meta( $fixture_user, $meta_key, $k );
				$umid       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $fixture_user, $meta_key ) );
				$um_cases[] = array( "{$plugin} {$kind} {$key}", $umid, $meta_key, $k, $searched );
			}
		}
	}
	$forget();

	foreach ( $opt_cases as $c ) {
		list( $what, $id, $row_name, $value, $fakes, $leaves ) = $c;
		$through( $what, $wpdb->options, array( 'option_id' => $id ), 'option_value', 'option_name', $row_name, $value, $fakes );
		$check( "{$what}: named a credential row for every screen that shows option rows", Minn_Admin_DB::is_credential_option( $row_name ) );
		if ( $leaves ) {
			// The leaves are why the declaration names them: by their names
			// alone they are no credential.
			$by_name = array_map( 'strval', array_keys( Minn_Admin_DB::credential_strings( maybe_serialize( $value ) ) ) );
			$check( "{$what}: its leaves are found only because they are declared", ! array_intersect( $fakes, $by_name ) );
		}
	}
	if ( $hard ) {
		list( $hf, $hv ) = $hard;
		// The hard values go into the declared row itself, borrowed as above.
		$seed_option( 'wpcf7', $hv );
		$forget();
		$list = Minn_Admin_DB::egress_credentials();
		foreach ( $hf as $what => $k ) {
			$check( "contact-form-7 leaves: a {$what} value under a declared leaf is one of the fence's needles", in_array( $k, $list, true ) );
		}
		$echo           = array( 'note' => 'secret ' . $hf['digit'] );
		list( $st, $b ) = $send( 'GET', $probe );
		$echo           = '';
		$check( "contact-form-7 leaves: a response holding only the single-digit secret is scanned and masked ({$st})", 200 === $st && false === strpos( $b, $hf['digit'] ) && false !== strpos( $b, '[redacted:' ) );
	}
	// A row declared whole whose value is one bare key with a single digit
	// (about one Disembark token in forty).
	if ( isset( $cases['disembark']['options']['disembark_token'] ) ) {
		$low = 'Lo' . strtoupper( preg_replace( '/\d/', '', $tag ) ) . 'qRsTuVwXyZaBcDeFgHiJkLmNoPqRsTu7';
		$seed_option( 'disembark_token', $low );
		$forget();
		$check( "disembark option disembark_token: a bare key with a single digit is one of the fence's needles", in_array( $low, Minn_Admin_DB::egress_credentials(), true ) );
		$echo           = array( 'command' => 'disembark connect https://example.test ' . $low );
		list( $st, $b ) = $send( 'GET', $probe );
		$echo           = '';
		$check( "disembark option disembark_token: a response holding only that key is scanned and masked ({$st})", 200 === $st && false === strpos( $b, $low ) && false !== strpos( $b, '[redacted:' ) );
	}
	foreach ( $col_cases as $c ) {
		list( $what, $t, $pkc, $id, $fakes, $row, $searched ) = $c;
		foreach ( $fakes as $col => $k ) {
			$through( "{$what} {$t}.{$col}", $t, array( $pkc => $id ), $col, '', '', $row[ $col ], array( $k ), $searched );
		}
	}
	foreach ( $um_cases as $c ) {
		list( $what, $umid, $meta_key, $k, $searched ) = $c;
		$through( $what, $wpdb->usermeta, array( 'umeta_id' => $umid ), 'meta_value', 'meta_key', $meta_key, $k, array( $k ), $searched );
	}
	$n = count( Minn_Admin_DB::egress_credentials() );
	$check( "the fence's list stays bounded with every store seeded ({$n} credentials)", $n > 0 && $n < 500 );
} finally {
	$put_back();
	$echo = '';
}

/*
 * 2. Every option an adapter touches that holds a key is covered.
 */

// Vendor shapes, seeded for the adapter that owns them (the vendors' own
// layouts), so a declaration removed from its adapter fails here even on a
// lab where the plugin has saved nothing.
$seeds = array(
	'cf7-mail.php'    => array(
		'wpcf7' => array(
			'stripe'           => array( 'api_keys' => array( 'publishable' => 'pk_test_51Pub9Key8x' . $tag, 'secret' => 'sk_live_51Sec9Key8xYz' . $tag ) ),
			'sendinblue'       => array( 'api_key' => 'xkeysib-0123456789abcdef0123' . $tag ),
			'constant_contact' => array( 'client_id' => 'cid-' . $tag, 'client_secret' => 'ccSec4455Zz99' . $tag ),
		),
	),
	'seo.php'         => array(
		'wpseo' => array( 'semrush_tokens' => array( 'access_token' => 'yAcc1234Qq5678' . $tag, 'expires' => 1700000000 ), 'myyoast-oauth' => array( 'config' => array( 'clientId' => 'yoast-client', 'secret' => 'ySec2468Ee1357' . $tag ) ) ),
	),
	'amelia.php'      => array(
		'amelia_settings' => wp_json_encode( array( 'notifications' => array( 'mailgunApiKey' => 'key-0123456789abcdef' . $tag ), 'roles' => array( 'customerCabinet' => array( 'headerJwtSecret' => 'Jw12ab34cd56' . $tag ) ) ) ),
	),
	'updraftplus.php' => array(
		'updraft_dropbox'          => array( 'version' => 1, 'settings' => array( 's-' . md5( $tag ) => array( 'tk_access_token' => 'sl.B1x2Y3z4AccessTok9' . $tag, 'folder' => 'site-backups' ) ) ),
		'updraft_s3'               => array( 'version' => 1, 'settings' => array( 's-' . md5( $tag ) => array( 'accesskey' => 'AKIA0000EXAMPLE' . $tag, 'secretkey' => 'wJalrXUtnFEMI0K7MDENG' . $tag, 'path' => 'bucket/backups' ) ) ),
		'updraft_jobdata_' . $tag  => array( 'option_cache' => array( 'updraft_ftp' => array( 'settings' => array( 's-1' => array( 'host' => 'ftp.example.test', 'pass' => 'Ftp0Pass9Word7' . $tag ) ) ) ) ),
	),
	'wp-statistics.php'  => array(
		'wp_statistics'          => array( 'geoip_license_type' => 'user-license', 'geoip_license_key' => 'MaxM1nd0Key2' . $tag ),
		'wp_statistics_licenses' => array( 'wp-statistics-data-plus' => array( 'license' => 'Wps0Lic9Key8' . $tag, 'status' => 'valid' ) ),
	),
	'ottokit.php'        => array(
		'suretrigger_options' => array( 'secret_key' => 'Ott0Kit9Secret8' . $tag, 'connected_email' => 'a@example.test' ),
	),
	'solid-security.php' => array(
		'itsec-storage' => array( 'network-brute-force' => array( 'api_key' => 'Nbf0ApiKey9' . $tag, 'api_secret' => 'Nbf0Secret9' . $tag ) ),
	),
	'jetpack-stats.php'  => array(
		'jetpack_private_options' => array( 'blog_token' => 'Jp0Blog9Tok8' . $tag . '.Jp0Secret9Part8' . $tag ),
	),
);
// Rows an adapter touches that hold a credential-named leaf and stay
// readable, with why.
$not_secret = array(
	// Ninja Forms' own settings screen prints its reCAPTCHA secret in a plain
	// field to the same administrator.
	'ninja_forms_settings' => 'Ninja Forms settings: its own screen prints the captcha secret',
);

// Literal option names in each adapter, and literal prefixes joined to a
// variable ("updraft_jobdata_" . $nonce).
$calls = array(
	'get_option'            => 0,
	'add_option'            => 0,
	'update_option'         => 0,
	'get_site_option'       => 0,
	'add_site_option'       => 0,
	'update_site_option'    => 0,
	'get_network_option'    => 1,
	'update_network_option' => 1,
);
$touched = array(); // name or prefix* => [ files ].
foreach ( glob( $root . '/includes/adapters/*.php' ) as $file ) {
	$base = basename( $file );
	$toks = array_values(
		array_filter(
			token_get_all( (string) file_get_contents( $file ) ),
			static function ( $t ) {
				return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
	$n = count( $toks );
	for ( $i = 0; $i < $n - 2; $i++ ) {
		$t = $toks[ $i ];
		if ( ! is_array( $t ) || T_STRING !== $t[0] || ! isset( $calls[ strtolower( $t[1] ) ] ) || '(' !== $toks[ $i + 1 ] ) {
			continue;
		}
		// Skip to the argument that holds the name.
		$j = $i + 2;
		for ( $skip_args = $calls[ strtolower( $t[1] ) ]; $skip_args > 0 && $j < $n; $j++ ) {
			if ( ',' === $toks[ $j ] ) {
				--$skip_args;
			}
		}
		$a = $toks[ $j ] ?? null;
		if ( ! is_array( $a ) || T_CONSTANT_ENCAPSED_STRING !== $a[0] ) {
			continue;
		}
		$name = stripslashes( substr( $a[1], 1, -1 ) );
		$next = $toks[ $j + 1 ] ?? null;
		if ( '.' === $next ) {
			$name .= '*';
		} elseif ( ',' !== $next && ')' !== $next ) {
			continue;
		}
		$touched[ $name ][ $base ] = true;
	}
}
foreach ( $declared as $plugin => $d ) {
	foreach ( array_keys( $d['options'] ) as $name ) {
		foreach ( $d['files'] as $f ) {
			$touched[ $name ][ $f ] = true;
		}
	}
}
// A seeded row is one its adapter reaches through the vendor's own API.
foreach ( $seeds as $f => $rows ) {
	foreach ( array_keys( $rows ) as $sname ) {
		$touched[ $sname ][ $f ] = true;
	}
}

// The string leaves of a value, through PHP serialization (no classes) and
// JSON, with their paths.
$leaves_of = static function ( $v, $path = '', $depth = 0 ) use ( &$leaves_of ) {
	if ( $depth > 8 ) {
		return array();
	}
	if ( is_string( $v ) ) {
		$t = trim( $v );
		if ( is_serialized( $t ) ) {
			$u = @unserialize( $t, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions -- no classes: nothing is woken.
			if ( false !== $u || 'b:0;' === $t ) {
				return $leaves_of( $u, $path, $depth + 1 );
			}
		}
		if ( '' !== $t && ( '{' === $t[0] || '[' === $t[0] ) ) {
			$j = json_decode( $t, true );
			if ( is_array( $j ) ) {
				return $leaves_of( $j, $path, $depth + 1 );
			}
		}
		return array( array( $path, $t ) );
	}
	if ( is_array( $v ) || is_object( $v ) ) {
		$out = array();
		foreach ( (array) $v as $k => $x ) {
			$out = array_merge( $out, $leaves_of( $x, $path . '/' . preg_replace( '/^\0[^\0]*\0/', '', (string) $k ), $depth + 1 ) );
		}
		return $out;
	}
	return array();
};
// A credential-named leaf whose value looks like a secret (see the header).
// A value stored bare has no leaf name, so the row's own name stands in.
$credential_leaf = static function ( $path, $value, $row ) {
	$segs = array_values(
		array_filter(
			explode( '/', trim( $path, '/' ) ),
			static function ( $s ) {
				return '' !== $s && ! ctype_digit( $s );
			}
		)
	);
	$leaf = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', $segs ? (string) end( $segs ) : (string) $row ) );
	$leaf = str_replace( '-', '_', $leaf );
	if ( '' === $leaf || ! preg_match( '/secret|token|passw|passphrase|apikey|api_key|licen[cs]e|jwt|credential|(^|_)(key|keys|pass|pwd|api|auth|private|salt|seed|nonce|accesskey|secretkey)(_|$)/', $leaf ) ) {
		return false;
	}
	if ( preg_match( '/(^|_)(id|ids|url|uri|urls|endpoint|expires?|expiry|expiration|expires_at|created|updated|at|time|date|status|state|type|types|mode|name|label|title|hint|length|count|limit|enabled|enable|active|valid|validated|required|version|public|publishable|site|sitekey|field|fields|format|placeholder|description|message|text|error|errors|last4|masked|path|folder|dir|bucket|region|host|port|email|user|username|login|method|scope|scopes|page|prefix|domain|remember|notice|option|options|setting|settings|location|role|roles)$/', $leaf )
		|| preg_match( '/(^|_)site_key$|^public_|^publishable_/', $leaf ) ) {
		return false;
	}
	$v = (string) $value;
	if ( strlen( $v ) < 8 || is_numeric( $v ) || preg_match( '/\s/', $v ) || false !== strpos( $v, '://' ) || is_email( $v )
		|| preg_match( '/^[a-z_\-]+$/', $v ) || preg_match( '/^[*•x\-]+$/u', $v ) || false !== strpos( $v, '[redacted' )
		|| in_array( strtolower( $v ), array( 'yes', 'no', 'on', 'off', 'true', 'false', 'enabled', 'disabled' ), true ) ) {
		return false;
	}
	return true;
};

$scanned = 0;
$keyed   = 0;
$gaps    = array();
$covered = static function ( $name ) {
	return Minn_Admin_DB::is_credential_option( $name );
};
foreach ( $touched as $name => $by ) {
	$values = array();
	if ( false !== strpos( $name, '*' ) ) {
		list( $pre, $suf ) = explode( '*', $name, 2 );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 20", $wpdb->esc_like( $pre ) . '%' . $wpdb->esc_like( $suf ) ), ARRAY_A );
		foreach ( $rows as $r ) {
			$values[] = array( $r['option_name'], $r['option_value'], 'live' );
		}
	} else {
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( null !== $raw ) {
			$values[] = array( $name, $raw, 'live' );
		}
		if ( is_multisite() ) {
			$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = %s LIMIT 1", $name ) );
			if ( null !== $raw ) {
				$values[] = array( $name, $raw, 'live network' );
			}
		}
	}
	foreach ( array_keys( $by ) as $f ) {
		foreach ( $seeds[ $f ] ?? array() as $sname => $svalue ) {
			$matches = false === strpos( $name, '*' ) ? $sname === $name : 0 === strpos( $sname, strstr( $name, '*', true ) );
			if ( $matches ) {
				$values[] = array( $sname, maybe_serialize( $svalue ), 'seeded' );
			}
		}
	}
	foreach ( $values as $v ) {
		list( $row, $raw, $from ) = $v;
		++$scanned;
		$hits = array();
		foreach ( $leaves_of( (string) $raw ) as $leaf ) {
			if ( $credential_leaf( $leaf[0], $leaf[1], $row ) ) {
				$hits[] = '' === $leaf[0] ? '(whole value)' : $leaf[0];
			}
		}
		if ( ! $hits ) {
			continue;
		}
		++$keyed;
		if ( $covered( $row ) || isset( $not_secret[ $row ] ) ) {
			continue;
		}
		$key          = $row . ' (' . $from . ', ' . implode( ' ', array_keys( $by ) ) . ')';
		$gaps[ $key ] = array_slice( array_unique( $hits ), 0, 3 );
	}
}
foreach ( $gaps as $key => $hits ) {
	$check( "option {$key} holds a key and is declared or listed", false, 'leaves ' . implode( ', ', $hits ) . ': declare it with minn_admin_secrets() in its adapter, or list it in $not_secret with what it holds' );
}
$check( "every option adapters touch that holds a key is covered ({$scanned} values of " . count( $touched ) . " names, {$keyed} holding a key)", ! $gaps && $keyed > 0, count( $gaps ) . ' uncovered' );

$pass = count( array_filter( $results ) );
printf( "\nsecrets-tier-conformance: %d/%d passed\n", $pass, count( $results ) );
if ( $pass !== count( $results ) ) {
	exit( 1 );
}
