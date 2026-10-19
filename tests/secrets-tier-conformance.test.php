<?php
/**
 * The secrets tier: every credential store an adapter declares
 * (minn_admin_secrets(), includes/kernel/class-minn-admin-kernel-secrets.php)
 * is hidden, and an option an adapter touches that holds a key is declared.
 *
 * 0. Declarations. Each reads in full (no problems reported), and each sits
 *    at the top level of its adapter file, outside any function or
 *    condition, so it is made whether or not its plugin is active: a
 *    deactivated plugin's keys stay in the database.
 * 1. Every declared store is hidden. A fake key is seeded into each store
 *    (a declared option row, a row of a declared name shape, each declared
 *    leaf, a declared user meta key, a declared column), then read through
 *    the database browser's row route, its value search, and a minn-admin/v1
 *    route that hands the stored value back as an adapter that slips would
 *    (registered by this suite for the run): the key never appears whole,
 *    and the egress fence lists it. Per-user tokens (user_tokens) are
 *    checked hidden in the browser and, by design, left out of the fence's
 *    list, which stays bounded on a site with many users.
 *    A row that exists already is borrowed: its stored bytes are kept, the
 *    fixture is written with SQL (no option hooks: the vendor sees nothing),
 *    and the bytes are put back with SQL however the run ends.
 * 2. The check that catches the next gap. Every option an adapter reads or
 *    writes (a literal name in get_option, update_option, add_option and
 *    their site and network forms, a literal prefix joined to a variable,
 *    and the names its declarations give) is read live, and the vendor
 *    shapes below are seeded for the adapters that own them. A value holding
 *    a credential-named leaf (key, secret, token, password, passphrase,
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
	$check( $searched ? "{$what}: the egress fence lists every fake key in it" : "{$what}: a per-user token stays out of the fence's list (hidden by name only)", ! $miss, implode( ', ', $miss ) );
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

// Borrowed and seeded option rows, put back with SQL however the run ends.
$borrowed = array();
$put_back = $once( static function () use ( &$borrowed, $wpdb, $forget ) {
	foreach ( $borrowed as $name => $was ) {
		if ( null === $was ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => $was['option_value'], 'autoload' => $was['autoload'] ), array( 'option_name' => $name ) );
		}
		wp_cache_delete( $name, 'options' );
	}
	$forget();
} );
$seed_option = static function ( $name, $value ) use ( &$borrowed, $wpdb ) {
	$raw = maybe_serialize( $value );
	$was = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
	if ( ! array_key_exists( $name, $borrowed ) ) {
		$borrowed[ $name ] = $was ? $was : null;
	}
	if ( $was ) {
		$wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => $name ) );
	} else {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => 'off' ) );
	}
	wp_cache_delete( $name, 'options' );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
};

$fixture_user = 0;
$col_rows     = array();
$drop_user    = $once( static function () use ( &$fixture_user, &$col_rows, $wpdb ) {
	if ( $fixture_user ) {
		wp_delete_user( $fixture_user );
	}
	foreach ( $col_rows as $r ) {
		$wpdb->delete( $r[0], array( $r[1] => $r[2] ) );
	}
} );

try {
	// Seed every declared option row and shape first, then read them all:
	// the fence's list is built once, as a request builds it.
	$opt_cases = array();
	foreach ( $declared as $plugin => $d ) {
		foreach ( $d['options'] as $name => $leaves ) {
			$at = strpos( $name, '*' );
			$row_name = false === $at ? $name : substr( $name, 0, $at ) . 'minn' . $tag . substr( $name, $at + 1 );
			list( $value, $fakes ) = $fixture( $row_name, $leaves );
			$id = $seed_option( $row_name, $value );
			$opt_cases[] = array( "{$plugin} option {$name}" . ( $leaves ? ' (leaves ' . implode( ', ', $leaves ) . ')' : '' ), $id, $row_name, $value, $fakes, $leaves );
		}
	}
	// Declared columns, on a row added to the live table when it exists.
	$col_cases = array();
	foreach ( $declared as $plugin => $d ) {
		foreach ( $d['columns'] as $table => $cols ) {
			$t = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $table ) ) );
			if ( ! $t ) {
				$skip( "{$plugin} columns of {$table}: no such table here" );
				continue;
			}
			$pk = $wpdb->get_results( "SHOW KEYS FROM `{$t}` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the name SHOW TABLES gave.
			if ( 1 !== count( $pk ) ) {
				$skip( "{$plugin} columns of {$table}: no single-column primary key to clean up by" );
				continue;
			}
			$pkc  = $pk[0]['Column_name'];
			$row  = array();
			$cols_meta = $wpdb->get_results( "SHOW COLUMNS FROM `{$t}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
			foreach ( $cols_meta as $c ) {
				if ( 'NO' === $c['Null'] && null === $c['Default'] && false === strpos( (string) $c['Extra'], 'auto_increment' ) ) {
					$row[ $c['Field'] ] = preg_match( '/int|decimal|float|double/i', $c['Type'] ) ? 0 : '';
				}
			}
			$fakes = array();
			foreach ( $cols as $col ) {
				$k            = $fake( $table . $col );
				$row[ $col ]  = wp_json_encode( array( 'access_token' => $k, 'token_type' => 'Bearer' ) );
				$fakes[ $col ] = $k;
			}
			if ( ! $wpdb->insert( $t, $row ) ) {
				$skip( "{$plugin} columns of {$table}: the fixture row was refused ({$wpdb->last_error})" );
				continue;
			}
			$col_rows[]  = array( $t, $pkc, (int) $wpdb->insert_id );
			$col_cases[] = array( $plugin, $t, $pkc, (int) $wpdb->insert_id, $fakes, $row );
		}
	}
	// Declared user meta, on a fixture user.
	$um_cases = array();
	$um_all   = array();
	foreach ( $declared as $plugin => $d ) {
		foreach ( array( 'usermeta' => true, 'user_tokens' => false ) as $kind => $searched ) {
			foreach ( $d[ $kind ] as $key ) {
				$um_all[] = array( $plugin, $kind, $key, $searched );
			}
		}
	}
	if ( $um_all ) {
		$fixture_user = wp_insert_user( array( 'user_login' => 'minnsecrets_' . $tag, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minnsecrets_' . $tag . '@example.test', 'role' => 'subscriber' ) );
		if ( is_wp_error( $fixture_user ) ) {
			$fixture_user = 0;
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
	foreach ( $col_cases as $c ) {
		list( $plugin, $t, $pkc, $id, $fakes, $row ) = $c;
		foreach ( $fakes as $col => $k ) {
			$through( "{$plugin} column {$t}.{$col}", $t, array( $pkc => $id ), $col, '', '', $row[ $col ], array( $k ) );
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
	$drop_user();
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
$credential_leaf = static function ( $path, $value ) {
	$segs = array_values(
		array_filter(
			explode( '/', trim( $path, '/' ) ),
			static function ( $s ) {
				return '' !== $s && ! ctype_digit( $s );
			}
		)
	);
	$leaf = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1_$2', (string) end( $segs ) ) );
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
			if ( $credential_leaf( $leaf[0], $leaf[1] ) ) {
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
