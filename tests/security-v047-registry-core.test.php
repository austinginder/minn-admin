<?php
/**
 * Regressions for the v0.46.0 shipped-zip registry scan, core lane (findings
 * that open v0.47.0).
 *
 *  - 00-1 (= 11-1) wp-config switches: a constant a PHP auto_prepend_file
 *    (php.ini or .user.ini) already defined read as a live, unlocked switch
 *    whenever the prepend's value matched wp-config's literal. The save then
 *    rewrote a line PHP never applies and answered that it applies on the
 *    next page load. Only files wp-config.php itself loaded were read, never
 *    what PHP ran before it.
 *  - 10-1 Run on Minn: the signature covers the zip's bytes, not which
 *    release they are, so the update service could name any release Minn
 *    ever signed. The switch now applies the engine's own rules: the archive
 *    must hold the version its release names (and the release must name a
 *    major.minor.patch version at that version's download path), and nothing
 *    older than the floor Minn Admin carries is installed.
 *
 * 00-1 is judged three ways: the seams on fixture prepends (in this process,
 * through the private helpers), a real auto_prepend_file in a child process
 * (`php -d auto_prepend_file=<fixture> wp eval-file`, php.ini untouched), and
 * a signed-in loopback request to the System route as a control on the web
 * stack this lab really runs. 10-1 judges release() on stubbed service
 * answers, the version rule directly, and stage() end to end on a real signed
 * release when one is in hand (MINN_SWITCH_TEST_ZIP, with its .sig beside
 * it). Nothing switches the site, and nothing writes wp-config.php. Fixtures
 * (files, a staged folder, a session, a filter) are removed however the run
 * ends.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v047-registry-core.test.php --user=admin --path=<site>
 *      MINN_SWITCH_TEST_ZIP=<a signed minn.zip> for the end-to-end staging checks.
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
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
// The floor Run on Minn keeps per site: cleared for a section, and put back
// as it was (value and autoload) however the run ends.
$floor_option = class_exists( 'Minn_Admin_Switch' ) && defined( 'Minn_Admin_Switch::FLOOR_OPTION' ) ? Minn_Admin_Switch::FLOOR_OPTION : 'minn_admin_switch_floor';
$keep_floor   = static function () use ( $floor_option, $once ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $floor_option ) );
	delete_option( $floor_option );
	return $once( static function () use ( $floor_option, $row ) {
		delete_option( $floor_option );
		if ( $row ) {
			add_option( $floor_option, maybe_unserialize( $row->option_value ), '', in_array( $row->autoload, array( 'yes', 'on', 'auto-on', 'auto' ), true ) ? 'yes' : 'no' );
		}
	} );
};
$private = static function ( $class, $method ) {
	if ( ! method_exists( $class, $method ) ) {
		return null;
	}
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m;
};

/*
 * 00-1 seams: what PHP ran before wp-config.php, and whether the literal is
 * live given it. Fixture files in a scratch folder stand in for prepends.
 */
( static function () use ( $check, $skip, $once, $private ) {
	if ( ! method_exists( 'Minn_Admin_REST', 'config_switch' ) ) {
		$skip( '00-1 seams: no config_switch()' );
		return;
	}
	$scan   = $private( 'Minn_Admin_REST', 'config_define_scan' );
	$live   = $private( 'Minn_Admin_REST', 'config_define_live' );
	$before = $private( 'Minn_Admin_REST', 'config_files_before' );
	$name   = 'MINN_V047_CORE_CFG_PROBE';
	if ( ! defined( $name ) ) {
		define( $name, true );
	}
	$dir = trailingslashit( get_temp_dir() ) . 'minn-v047-core-' . wp_generate_password( 8, false );
	wp_mkdir_p( $dir );
	// PHP lists files by their real path (the temp folder may sit behind a link).
	$dir          = (string) realpath( $dir );
	$include_path = get_include_path();
	$clean        = $once( static function () use ( $dir, $include_path ) {
		set_include_path( $include_path );
		foreach ( (array) glob( $dir . '/*.php' ) as $f ) {
			unlink( $f );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	} );
	try {
		$put      = static function ( $file, $code ) use ( $dir ) {
			file_put_contents( "{$dir}/{$file}", $code );
			return "{$dir}/{$file}";
		};
		$defines  = $put( 'prepend-defines.php', "<?php\nif ( ! defined( '{$name}' ) ) {\n\tdefine( '{$name}', true );\n}\n" );
		$looped   = $put( 'prepend-looped.php', "<?php\nforeach ( array( 'MINN_V047_CORE_A' => 1 ) as \$k => \$v ) {\n\tdefine( \$k, \$v );\n}\n" );
		$elsewise = $put( 'prepend-other.php', "<?php\ndefine( 'MINN_V047_CORE_OTHER', true );\nif ( {$name} ) {\n\terror_reporting( E_ALL );\n}\n" );
		$reads    = $put( 'local-reads.php', "<?php\n\$minn_v047 = 1;\n" );
		$missing  = $dir . '/prepend-missing.php';
		// Wordfence's optimized firewall prepends wordfence-waf.php, which
		// includes its compiled rules (wflogs/rules.php, about 900 KB) on
		// every request: a large file of defined() checks and no define.
		$checks   = str_repeat( "if ( defined( 'WFWAF_MINN_V047' ) && \$minn_v047 ) {\n\t\$minn_v047_rules[] = array( 'id' => 1, 'pattern' => '/x/' );\n}\n", 6000 );
		$rules    = $put( 'rules.php', "<?php\n" . $checks );
		$waf      = $put( 'prepend-waf.php', "<?php\ninclude __DIR__ . '/rules.php';\n" );
		$named    = $put( 'rules-named.php', "<?php\n" . $checks . "if ( defined( '{$name}' ) ) {\n\t\$minn_v047 = 2;\n}\n" );
		$big_def  = $put( 'big-defines.php', "<?php\n" . $checks . "define( '{$name}', true );\n" );
		$huge     = $put( 'huge.php', "<?php\n" . str_repeat( '// ' . str_repeat( 'x', 1020 ) . "\n", 8300 ) );
		$plain    = "<?php\ndefine( '{$name}', true );\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$after    = "<?php\nrequire_once __DIR__ . '/wp-config-local.php';\ndefine( '{$name}', true );\nrequire_once ABSPATH . 'wp-settings.php';\n";

		// What PHP ran before wp-config.php.
		$check( '00-1 there is a reader for what PHP ran before wp-config.php (config_files_before)', null !== $before );
		if ( null !== $before ) {
			$config = realpath( ABSPATH . 'wp-config.php' );
			$got    = $before->invoke( null, array(), $defines );
			$check( '00-1 the auto_prepend_file PHP names is read', array( $defines ) === $got, wp_json_encode( $got ) );
			set_include_path( $dir . PATH_SEPARATOR . $include_path );
			$got = $before->invoke( null, array(), 'prepend-defines.php' );
			set_include_path( $include_path );
			$check( '00-1 a prepend named relative to include_path is read where PHP finds it', array( realpath( $defines ) ) === $got, wp_json_encode( $got ) );
			$got = $before->invoke( null, array(), $missing );
			$check( '00-1 a prepend that cannot be found is kept as named (so it reads as unreadable)', array( $missing ) === $got, wp_json_encode( $got ) );
			$got = $before->invoke( null, array(), '' );
			$check( '00-1 no prepend reads nothing (control)', array() === $got, wp_json_encode( $got ) );
			if ( false !== $config ) {
				$list = array( ABSPATH . 'index.php', $defines, $looped, ABSPATH . 'wp-blog-header.php', ABSPATH . 'wp-load.php', $config, $reads, ABSPATH . 'wp-settings.php' );
				$got  = $before->invoke( null, $list, '' );
				$check( '00-1 every file PHP lists ahead of wp-config.php is read: the prepend, what it loaded, the entry script', array_slice( $list, 0, 5 ) === $got, wp_json_encode( $got ) );
				$got  = $before->invoke( null, $list, $defines );
				$want = array_slice( $list, 0, 5 );
				sort( $want );
				sort( $got );
				$check( '00-1 a prepend PHP also lists is read once', $want === $got, wp_json_encode( $got ) );
				$got = $before->invoke( null, array( $defines, $reads ), '' );
				$check( '00-1 a list without wp-config.php (WP-CLI) adds nothing from it (control)', array() === $got, wp_json_encode( $got ) );
			} else {
				$skip( '00-1 included-files cases: no wp-config.php under ABSPATH' );
			}
		}

		// The verdict on the line, given what ran before it.
		$is_live = static function ( $src, $loaded, $first ) use ( $scan, $live, $name ) {
			$s = $scan->invoke( null, $name, $src );
			return 'bool' === $s['kind'] && $live->invoke( null, $name, $s, $loaded, $first );
		};
		$cases = array(
			// label => [ wp-config source, files it loaded, files PHP ran before it, live? ]
			'a plain define with nothing run ahead is a switch (control)' => array( $plain, array(), array(), true ),
			'a plain define after a prepend that defines it is locked' => array( $plain, array(), array( $defines ), false ),
			'a plain define after a prepend that defines constants in a loop is locked' => array( $plain, array(), array( $looped ), false ),
			'a plain define after a prepend that cannot be read is locked' => array( $plain, array(), array( $missing ), false ),
			'a plain define after a prepend that only reads it stays a switch (control)' => array( $plain, array(), array( $elsewise ), true ),
			'core\'s own entry files ahead of wp-config.php leave it a switch (control)' => array( $plain, array(), array( ABSPATH . 'index.php', ABSPATH . 'wp-blog-header.php', ABSPATH . 'wp-load.php' ), true ),
			'a define after a harmless include and a prepend that defines it is locked' => array( $after, array( $reads ), array( $defines ), false ),
			'a define after a harmless include with a harmless prepend stays a switch (control)' => array( $after, array( $reads ), array( $elsewise ), true ),
			'N1 a prepend that loads a large file (over 512 KB) of defined() checks alone leaves it a switch (Wordfence\'s compiled rules)' => array( $plain, array(), array( $waf, $rules ), true ),
			'N1 a large file that checks this very constant with defined() alone leaves it a switch' => array( $plain, array(), array( $named ), true ),
			'N1 a large file that does define it is still read, and locks (control)' => array( $plain, array(), array( $big_def ), false ),
			'N1 a file too large to read at all (over 8 MB) still locks' => array( $plain, array(), array( $huge ), false ),
		);
		// I1: past a size bound a file is judged by pattern and never
		// tokenized (a few MB of source is hundreds of MB of tokens). The
		// bound is the reader's third parameter, so small fixtures stand in
		// for large files here. I2: the pass-over needs no define word at
		// all, so a comment between define and its parenthesis still counts.
		$by = $private( 'Minn_Admin_REST', 'config_defined_by_loaded' );
		$fx = static function ( $file, $code ) use ( $put ) {
			return $put( $file, "<?php\n" . $code );
		};
		$pattern = array(
			// label => [ file, may define past the bound? ]
			'a define of it inside a comment locks (no tokens, so it fails closed)' => array( $fx( 'p-comment.php', "// define( '{$name}', true );\n" ), true ),
			'a define with a name it cannot read locks' => array( $looped, true ),
			'a define of it with a comment before the parenthesis locks' => array( $fx( 'p-gap.php', "define /* x */ ( \"{$name}\", true );\n" ), true ),
			'a const of that name locks' => array( $fx( 'p-const.php', "const {$name} = true;\n" ), true ),
			'defined() checks of it alone do not (control)' => array( $fx( 'p-defined.php', "if ( defined( '{$name}' ) ) {\n\t\$x = 1;\n}\n" ), false ),
			'a define of another constant does not (control)' => array( $fx( 'p-other.php', "define( 'MINN_V047_CORE_OTHER', true );\n" ), false ),
			'a firewall rule matching define text in a string does not (control)' => array( $fx( 'p-rule.php', "\$r = '#^define\\\\(\\\\s*\\'DONOTCACHEDB\\',\\\\s*true';\n" ), false ),
		);
		foreach ( $pattern as $label => $c ) {
			$got = $by ? (bool) $by->invoke( null, $name, array( $c[0] ), 16 ) : null;
			$check( 'I1 past the size bound, ' . $label, $c[1] === $got, var_export( $got, true ) );
		}
		$got = $by ? (bool) $by->invoke( null, $name, array( $pattern['a define of it inside a comment locks (no tokens, so it fails closed)'][0] ) ) : null;
		$check( 'I1 under the bound the same comment is tokenized and does not lock (control)', false === $got, var_export( $got, true ) );
		$gap  = $fx( 'p-gap-dynamic.php', "foreach ( array( 'MINN_V047_CORE_A' => 1 ) as \$k => \$v ) {\n\tdefine /* x */ ( \$k, \$v );\n}\n" );
		$hash = $fx( 'p-hash-dynamic.php', "foreach ( array( 'MINN_V047_CORE_A' => 1 ) as \$k => \$v ) {\n\tdefine # c\n\t( \$k, \$v );\n}\n" );
		$check( 'I2 a define with a comment before its parenthesis is read, not passed over (/* */)', true === ( $by ? (bool) $by->invoke( null, $name, array( $gap ) ) : null ) );
		$check( 'I2 and with a # comment and a line break', true === ( $by ? (bool) $by->invoke( null, $name, array( $hash ) ) : null ) );
		$check( 'N1 the fixture rules file is over 512 KB and the huge one over 8 MB', filesize( $rules ) > 524288 && filesize( $big_def ) > 524288 && filesize( $huge ) > 8388608, filesize( $rules ) . ' / ' . filesize( $huge ) );
		foreach ( $cases as $label => $c ) {
			$got = $is_live( $c[0], $c[1], $c[2] );
			$check( '00-1 ' . $label, $c[3] === $got, $got ? 'offered as a switch' : 'locked' );
		}
	} finally {
		$clean();
	}
} )();

/*
 * 00-1 for real: PHP runs a fixture as auto_prepend_file in a child process
 * (WP-CLI with no plugins, so it starts in a second), and the child asks
 * config_switch() what the System page would show. php.ini is not touched.
 */
( static function () use ( $check, $skip, $once, $private ) {
	if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'proc_open' ) ) {
		$skip( '00-1 child process: needs WP-CLI and proc_open' );
		return;
	}
	$script = getenv( 'WP_CLI_PHAR_PATH' ) ? getenv( 'WP_CLI_PHAR_PATH' ) : ( $GLOBALS['argv'][0] ?? '' );
	if ( '' === $script || ! is_file( $script ) ) {
		$skip( '00-1 child process: cannot tell how WP-CLI was started' );
		return;
	}
	$dir = trailingslashit( get_temp_dir() ) . 'minn-v047-core-child-' . wp_generate_password( 8, false );
	wp_mkdir_p( $dir );
	$clean = $once( static function () use ( $dir ) {
		foreach ( (array) glob( $dir . '/*.php' ) as $f ) {
			unlink( $f );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	} );
	$class = dirname( __DIR__ ) . '/includes/class-minn-admin-rest.php';
	// One child: the prepend PHP runs, then the probe asks for the switch.
	$child = static function ( $prepend, $name, $src, $define_self ) use ( $dir, $script, $class ) {
		$probe = $dir . '/probe-' . wp_generate_password( 6, false, false ) . '.php';
		file_put_contents(
			$probe,
			"<?php\nrequire_once " . var_export( $class, true ) . ";\n"
			. ( $define_self ? 'if ( ! defined( ' . var_export( $name, true ) . ' ) ) { define( ' . var_export( $name, true ) . ", true ); }\n" : '' )
			. "echo 'MINNPROBE ' . wp_json_encode( array( 'prepend' => ini_get( 'auto_prepend_file' ), 'switch' => Minn_Admin_REST::config_switch( " . var_export( $name, true ) . ', ' . var_export( $src, true ) . " ) ) ) . \"\\n\";\n"
		);
		$cmd = escapeshellarg( \WP_CLI\Utils\get_php_binary() ) . ' -d ' . escapeshellarg( 'auto_prepend_file=' . $prepend ) . ' ' . escapeshellarg( $script )
			. ' eval-file ' . escapeshellarg( $probe ) . ' --path=' . escapeshellarg( ABSPATH ) . ' --skip-plugins --skip-themes';
		$run = WP_CLI::launch( $cmd, false, true );
		return preg_match( '/^MINNPROBE (.+)$/m', (string) $run->stdout, $m ) ? (array) json_decode( $m[1], true ) : array( 'error' => substr( trim( $run->stderr . ' ' . $run->stdout ), 0, 300 ) );
	};
	try {
		$name    = 'MINN_V047_CORE_PREPEND_PROBE';
		$src     = "<?php\ndefine( '{$name}', true );\nrequire_once ABSPATH . 'wp-settings.php';\n";
		$defines = $dir . '/prepend-defines.php';
		file_put_contents( $defines, "<?php\ndefine( '{$name}', true );\n" );
		$other = $dir . '/prepend-other.php';
		file_put_contents( $other, "<?php\ndefine( 'MINN_V047_CORE_PREPEND_OTHER', true );\n" );

		$r = $child( $defines, $name, $src, false );
		$check( '00-1 child: PHP ran the fixture as auto_prepend_file', isset( $r['prepend'] ) && realpath( (string) $r['prepend'] ) === realpath( $defines ), wp_json_encode( $r ) );
		$sw = (array) ( $r['switch'] ?? array() );
		$check( '00-1 child: a define the prepend already made is shown as defined elsewhere, not offered', true === ( $sw['locked'] ?? null ) && 'elsewhere' === ( $sw['lock'] ?? '' ), wp_json_encode( $r ) );
		$r  = $child( $other, $name, $src, true );
		$sw = (array) ( $r['switch'] ?? array() );
		$check( '00-1 child: a prepend that defines something else leaves the switch (control)', false === ( $sw['locked'] ?? null ), wp_json_encode( $r ) );
		$big = $dir . '/prepend-rules.php';
		file_put_contents( $big, "<?php\n" . str_repeat( "if ( defined( 'WFWAF_MINN_V047' ) && \$minn_v047 ) {\n\t\$minn_v047_rules[] = array( 'id' => 1, 'pattern' => '/x/' );\n}\n", 6000 ) );
		$r  = $child( $big, $name, $src, true );
		$sw = (array) ( $r['switch'] ?? array() );
		$check( 'N1 child: a prepend over 512 KB holding only defined() checks leaves the switch', filesize( $big ) > 524288 && false === ( $sw['locked'] ?? null ), wp_json_encode( $r ) );

		// The finding's own shape on this site's wp-config.php: the prepend
		// defines WP_DEBUG_DISPLAY to the value its literal holds.
		$scan     = $private( 'Minn_Admin_REST', 'config_define_scan' );
		$path     = ABSPATH . 'wp-config.php';
		$contents = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
		$s        = ( '' !== $contents && $scan ) ? $scan->invoke( null, 'WP_DEBUG_DISPLAY', $contents ) : array( 'kind' => 'absent' );
		$here     = '' !== $contents ? Minn_Admin_REST::config_switch( 'WP_DEBUG_DISPLAY', $contents ) : array();
		if ( 'bool' !== $s['kind'] || ! empty( $here['locked'] ) ) {
			$skip( '00-1 child: this site\'s wp-config.php has no live literal WP_DEBUG_DISPLAY to shadow (' . $s['kind'] . ')' );
		} else {
			$debug = $dir . '/prepend-debug-display.php';
			file_put_contents( $debug, "<?php\ndefine( 'WP_DEBUG_DISPLAY', " . ( $s['value'] ? 'true' : 'false' ) . " );\n" );
			$r  = $child( $debug, 'WP_DEBUG_DISPLAY', $contents, false );
			$sw = (array) ( $r['switch'] ?? array() );
			$check( '00-1 child: a prepend defining WP_DEBUG_DISPLAY as this wp-config.php does locks "Show errors on screen"', true === ( $sw['locked'] ?? null ) && 'elsewhere' === ( $sw['lock'] ?? '' ), wp_json_encode( $r ) );
		}
	} finally {
		$clean();
	}
} )();

/*
 * 00-1 control on the web stack this lab runs: the System route, signed in
 * over a loopback request, judges every switch as this process does. Over
 * HTTP PHP lists the entry script and core's loader ahead of wp-config.php
 * (and whatever the server prepends), so a reader that over-blocked would
 * lock them there.
 */
( static function () use ( $check, $skip, $once ) {
	if ( ! Minn_Admin::install_owner() ) {
		$skip( '00-1 loopback: the suite user does not own the install' );
		return;
	}
	$path     = ABSPATH . 'wp-config.php';
	$contents = is_readable( $path ) ? (string) file_get_contents( $path ) : '';
	if ( '' === $contents ) {
		$skip( '00-1 loopback: no readable wp-config.php under ABSPATH' );
		return;
	}
	$uid     = get_current_user_id();
	$exp     = time() + HOUR_IN_SECONDS;
	$session = WP_Session_Tokens::get_instance( $uid )->create( $exp );
	$clean   = $once( static function () use ( $uid, $session ) {
		WP_Session_Tokens::get_instance( $uid )->destroy( $session );
	} );
	try {
		$cookie                      = wp_generate_auth_cookie( $uid, $exp, 'logged_in', $session );
		$_COOKIE[ LOGGED_IN_COOKIE ] = $cookie;
		$nonce                       = wp_create_nonce( 'wp_rest' );
		unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
		$r = wp_remote_get(
			rest_url( 'minn-admin/v1/system' ),
			array(
				'headers'   => array( 'X-WP-Nonce' => $nonce ),
				'cookies'   => array( LOGGED_IN_COOKIE => $cookie ),
				'sslverify' => false,
				'timeout'   => 120,
			)
		);
		$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		$body = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
		$web  = is_array( $body ) ? (array) ( $body['config']['constants'] ?? array() ) : array();
		if ( 200 !== $code || ! $web ) {
			$skip( '00-1 loopback: the System route did not answer with switches (' . $code . ( is_wp_error( $r ) ? ' ' . $r->get_error_message() : '' ) . ')' );
			return;
		}
		$moved = array();
		foreach ( $web as $c ) {
			$mine = Minn_Admin_REST::config_switch( (string) $c['name'], $contents );
			if ( (bool) $c['locked'] !== (bool) $mine['locked'] || (string) ( $c['lock'] ?? '' ) !== (string) $mine['lock'] ) {
				$moved[] = $c['name'] . ': web ' . ( $c['lock'] ?? '' ) . ' / here ' . $mine['lock'];
			}
		}
		$check( '00-1 loopback: the web stack here judges every switch as this process does (control, ' . count( $web ) . ' switches)', ! $moved, implode( '; ', $moved ) );
	} finally {
		$clean();
	}
} )();

/*
 * 10-1 release(): the service's answer must name a major.minor.patch
 * version and that version's own download, as the engine's Release reads
 * it. Answers are stubbed; nothing leaves this process.
 */
( static function () use ( $check, $skip, $once ) {
	if ( ! class_exists( 'Minn_Admin_Switch' ) || Minn_Admin::is_engine() ) {
		$skip( '10-1 release: Run on Minn is not loaded here' );
		return;
	}
	$answer = null;
	$stub   = static function ( $pre, $args, $url ) use ( &$answer ) {
		if ( Minn_Admin_Switch::SERVICE . 'minn/releases/latest' !== $url ) {
			return $pre;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $answer ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	};
	add_filter( 'pre_http_request', $stub, 10, 3 );
	$clean = $once( static function () use ( $stub ) {
		remove_filter( 'pre_http_request', $stub, 10 );
	} );
	try {
		$sig  = base64_encode( str_repeat( 'x', 64 ) );
		$base = Minn_Admin_Switch::SERVICE . 'minn/download/';
		$ask  = static function ( $version, $package ) use ( &$answer, $sig ) {
			$answer = array(
				'version'   => $version,
				'package'   => $package,
				'sha256'    => str_repeat( 'ab', 32 ),
				'signature' => $sig,
				'notes'     => '',
			);
			return Minn_Admin_Switch::release();
		};
		$r = $ask( '0.2.0', $base . '0.2.0/minn.zip' );
		$check( '10-1 release: a release at its own version\'s download is taken (control)', is_array( $r ) && '0.2.0' === $r['version'], is_wp_error( $r ) ? $r->get_error_code() : '' );
		$r = $ask( '0.2.0', $base . '0.1.0/minn.zip' );
		$check( '10-1 release: a release naming one version and another version\'s download is refused', is_wp_error( $r ) && 'minn_switch_service' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r ) );
		$r = $ask( '', $base . 'minn.zip' );
		$check( '10-1 release: a release that names no version is refused', is_wp_error( $r ) && 'minn_switch_service' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r ) );
		$r = $ask( '0.2.0-rc1', $base . '0.2.0-rc1/minn.zip' );
		$check( '10-1 release: a version that is not major.minor.patch is refused', is_wp_error( $r ) && 'minn_switch_service' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r ) );
		$r = $ask( '0.2.0', 'https://github.com/austinginder/minn-engine/releases/download/v0.2.0/minn.zip' );
		$check( '10-1 release: a package off the service is still refused (control)', is_wp_error( $r ) && 'minn_switch_service' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r ) );
	} finally {
		$clean();
	}
} )();

/*
 * 10-1 the version rule stage() applies to what the archive holds: the
 * version its release names, and nothing older than the floor.
 */
( static function () use ( $check, $skip, $keep_floor, $floor_option ) {
	if ( ! class_exists( 'Minn_Admin_Switch' ) || Minn_Admin::is_engine() ) {
		$skip( '10-1 rule: Run on Minn is not loaded here' );
		return;
	}
	$has = method_exists( 'Minn_Admin_Switch', 'version_error' ) && defined( 'Minn_Admin_Switch::ENGINE_FLOOR' );
	$check( '10-1 rule: Minn Admin carries a floor and a version rule (ENGINE_FLOOR, version_error)', $has );
	if ( ! $has ) {
		return;
	}
	$restore = $keep_floor();
	try {
		$floor = Minn_Admin_Switch::ENGINE_FLOOR;
		$check( '10-1 rule: the floor is a major.minor.patch version', 1 === preg_match( '/^\d+\.\d+\.\d+$/', $floor ), $floor );
		$parts = array_map( 'intval', explode( '.', $floor ) );
		$older = $parts[2] > 0 ? "{$parts[0]}.{$parts[1]}." . ( $parts[2] - 1 ) : ( $parts[1] > 0 ? "{$parts[0]}." . ( $parts[1] - 1 ) . '.99' : '0.0.0' );
		$newer = "{$parts[0]}.{$parts[1]}." . ( $parts[2] + 1 );
		$code  = static function ( $e ) {
			return is_wp_error( $e ) ? $e->get_error_code() : 'none';
		};
		$cases = array(
			// label => [ the archive holds, the release names, refusal ]
			'an archive holding the version its release names, at the floor, is taken (control)' => array( $floor, $floor, 'none' ),
			'a newer one is taken (control)' => array( $newer, $newer, 'none' ),
			'an archive holding another version than its release names is refused' => array( $floor, $newer, 'minn_switch_version' ),
			'and the other way round' => array( $newer, $floor, 'minn_switch_version' ),
			'a release older than the floor is refused, though signed in its day' => array( $older, $older, 'minn_switch_old' ),
			'a pre-release of the floor version is older than it' => array( $floor . '-rc1', $floor . '-rc1', 'minn_switch_old' ),
			'an archive whose bootstrap names no version is refused' => array( '', $floor, 'minn_switch_version' ),
			'a zip in hand (no release names a version) at the floor is taken (control)' => array( $floor, '', 'none' ),
			'a zip in hand older than the floor is refused' => array( $older, '', 'minn_switch_old' ),
			'a zip in hand naming no version is refused' => array( '', '', 'minn_switch_old' ),
		);
		foreach ( $cases as $label => $c ) {
			$got = $code( Minn_Admin_Switch::version_error( $c[0], $c[1] ) );
			$check( '10-1 rule: ' . $label, $c[2] === $got, $got );
		}

		// N2: the floor rises on its own with what this site has switched to.
		$remember = method_exists( 'Minn_Admin_Switch', 'remember' ) ? new ReflectionMethod( 'Minn_Admin_Switch', 'remember' ) : null;
		$check( 'N2 Minn Admin keeps the highest release this site has switched to (FLOOR_OPTION, remember)', defined( 'Minn_Admin_Switch::FLOOR_OPTION' ) && null !== $remember );
		if ( null === $remember ) {
			return;
		}
		$remember->setAccessible( true );
		global $wpdb;
		$remember->invoke( null, $newer );
		$autoload = (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $floor_option ) );
		$check( 'N2 a release switched to is remembered, and not autoloaded', $newer === get_option( $floor_option ) && in_array( $autoload, array( 'no', 'off', 'auto-off' ), true ), wp_json_encode( array( get_option( $floor_option ), $autoload ) ) );
		$got = $code( Minn_Admin_Switch::version_error( $floor, $floor ) );
		$check( "N2 once this site has switched to {$newer}, the signed {$floor} is refused", 'minn_switch_old' === $got, $got );
		$got = $code( Minn_Admin_Switch::version_error( $floor, '' ) );
		$check( "N2 and so is {$floor} as a zip in hand", 'minn_switch_old' === $got, $got );
		$got = $code( Minn_Admin_Switch::version_error( $newer, $newer ) );
		$check( "N2 {$newer} itself is still taken, so a check can run again (control)", 'none' === $got, $got );
		$remember->invoke( null, $floor );
		$check( 'N2 remembering an older release never lowers the floor', $newer === get_option( $floor_option ), wp_json_encode( get_option( $floor_option ) ) );
		update_option( $floor_option, 'not a version', false );
		$got = $code( Minn_Admin_Switch::version_error( $floor, $floor ) );
		$check( 'N2 a stored value that is not a version leaves the shipped floor (control)', 'none' === $got, $got );
	} finally {
		$restore();
	}
} )();

/*
 * 10-1 end to end: stage() on a real signed release, the service's answer
 * naming another version than the archive holds. Only staging runs (fetch,
 * verify, unpack); the folder it makes is removed.
 */
( static function () use ( $check, $skip, $once, $keep_floor, $floor_option ) {
	if ( ! class_exists( 'Minn_Admin_Switch' ) || Minn_Admin::is_engine() || is_multisite() || PHP_VERSION_ID < Minn_Admin_Switch::PHP_MIN || ! class_exists( 'ZipArchive' ) ) {
		$skip( '10-1 stage: needs a single WordPress site on PHP 8.2+ with zip' );
		return;
	}
	$zip = (string) getenv( 'MINN_SWITCH_TEST_ZIP' );
	if ( '' === $zip || ! is_file( $zip ) || ! is_file( $zip . '.sig' ) ) {
		$skip( '10-1 stage: set MINN_SWITCH_TEST_ZIP to a signed minn.zip with its .sig beside it' );
		return;
	}
	$bytes = (string) file_get_contents( $zip );
	$sig   = trim( (string) file_get_contents( $zip . '.sig' ) );
	if ( ! Minn_Admin_Switch::signed( $bytes, $sig ) ) {
		$skip( '10-1 stage: MINN_SWITCH_TEST_ZIP is not signed by a key Minn trusts' );
		return;
	}
	$archive = new ZipArchive();
	$holds   = '';
	if ( true === $archive->open( $zip ) ) {
		$holds = preg_match( "/MINN_ENGINE_VERSION', '([^']+)'/", (string) $archive->getFromName( 'minn/bootstrap.php' ), $m ) ? $m[1] : '';
		$archive->close();
	}
	if ( ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $holds, $v ) ) {
		$skip( '10-1 stage: the signed zip names no major.minor.patch version (' . $holds . ')' );
		return;
	}
	$other  = "{$v[1]}.{$v[2]}." . ( (int) $v[3] + 1 );
	$hook   = Minn_Admin_Switch::SWEEP_HOOK;
	$had    = wp_next_scheduled( $hook );
	$listed = static function () {
		return (array) glob( WP_CONTENT_DIR . '/upgrade/minn-switch-*', GLOB_ONLYDIR );
	};
	$was    = $listed();
	$clean  = $once( static function () use ( $listed, $was, $hook, $had ) {
		foreach ( array_diff( $listed(), $was ) as $d ) {
			Minn_Admin_Switch::remove( $d );
		}
		if ( ! $had ) {
			wp_clear_scheduled_hook( $hook );
		}
	} );
	$restore = $keep_floor();
	try {
		$release = array(
			'version'   => $other,
			'package'   => $zip,
			'sha256'    => hash( 'sha256', $bytes ),
			'signature' => $sig,
			'notes'     => '',
		);
		$r = Minn_Admin_Switch::stage( $release );
		$check( "10-1 stage: a signed archive holding {$holds} is refused when its release names {$other}", is_wp_error( $r ) && 'minn_switch_version' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_code() : 'staged as ' . $r['version'] );
		clearstatcache();
		$check( '10-1 stage: and leaves no folder behind', array() === array_diff( $listed(), $was ), wp_json_encode( array_values( array_diff( $listed(), $was ) ) ) );
		$check( 'N2 stage: a refused archive is not remembered', false === get_option( $floor_option ), wp_json_encode( get_option( $floor_option ) ) );
		if ( is_array( $r ) ) {
			Minn_Admin_Switch::remove( $r['dir'] );
		}
		$release['version'] = $holds;
		$r                  = Minn_Admin_Switch::stage( $release );
		$ok                 = is_array( $r ) && $holds === $r['version'];
		$check( "10-1 stage: the same archive is staged when its release names {$holds} (control)", $ok, is_wp_error( $r ) ? $r->get_error_code() : wp_json_encode( $r['version'] ?? null ) );
		$check( 'I4 stage: a check alone (staged, nothing switched) leaves the floor where it was', false === get_option( $floor_option ), wp_json_encode( get_option( $floor_option ) ) );
		if ( is_array( $r ) ) {
			Minn_Admin_Switch::remove( $r['dir'] );
		}
		$release['version'] = '';
		$release['sha256']  = '';
		$r                  = Minn_Admin_Switch::stage( $release );
		$check( "10-1 stage: and as a zip in hand, at or above the floor (control)", is_array( $r ) && $holds === $r['version'], is_wp_error( $r ) ? $r->get_error_code() : '' );
		if ( is_array( $r ) ) {
			Minn_Admin_Switch::remove( $r['dir'] );
		}
		// A site that has switched to a newer release: the same signed archive,
		// named as itself, is a replay of a superseded release.
		update_option( $floor_option, $other, false );
		$release['version'] = $holds;
		$release['sha256']  = hash( 'sha256', $bytes );
		$r                  = Minn_Admin_Switch::stage( $release );
		$check( "N2 stage: once this site has switched to {$other}, the signed {$holds} is refused", is_wp_error( $r ) && 'minn_switch_old' === $r->get_error_code(), is_wp_error( $r ) ? $r->get_error_code() : 'staged as ' . $r['version'] );
		clearstatcache();
		$check( 'N2 stage: and leaves no folder behind', array() === array_diff( $listed(), $was ), wp_json_encode( array_values( array_diff( $listed(), $was ) ) ) );
		if ( is_array( $r ) ) {
			Minn_Admin_Switch::remove( $r['dir'] );
		}
	} finally {
		$restore();
		$clean();
	}
} )();

/*
 * I4: the floor rises when a switch completes, never at Check. switch_now()
 * runs the staged engine's installer; here the staged engine is a stub whose
 * Installer answers the code the case sets, so nothing moves.
 */
( static function () use ( $check, $skip, $once, $keep_floor, $floor_option ) {
	if ( ! class_exists( 'Minn_Admin_Switch' ) || Minn_Admin::is_engine() || class_exists( 'Minn\\Autoloader', false ) || class_exists( 'Minn\\Cli\\Installer', false ) ) {
		$skip( 'I4 switch: Run on Minn is not loaded here, or an engine is loaded in this process' );
		return;
	}
	$base = trailingslashit( get_temp_dir() ) . 'minn-v047-core-switch-' . wp_generate_password( 8, false );
	$stub = $base . '/engine';
	wp_mkdir_p( $stub . '/src/Minn/Cli' );
	file_put_contents( $stub . '/src/Minn/Autoloader.php', "<?php\nnamespace Minn;\nclass Autoloader {\n\tpublic static function register() {\n\t\tspl_autoload_register( static function ( \$c ) {\n\t\t\tif ( 'Minn\\\\Cli\\\\Installer' === \$c ) {\n\t\t\t\trequire __DIR__ . '/Cli/Installer.php';\n\t\t\t}\n\t\t} );\n\t}\n}\n" );
	file_put_contents( $stub . '/src/Minn/Cli/Installer.php', "<?php\nnamespace Minn\\Cli;\nclass Installer {\n\tpublic static function run( array \$argv, \$engine ) {\n\t\t\$GLOBALS['minn_v047_installer_calls'][] = \$argv;\n\t\treturn array( 'code' => (int) ( \$GLOBALS['minn_v047_installer_code'] ?? 1 ), 'lines' => array( 'minn-v047 stub' ) );\n\t}\n}\n" );
	$restore = $keep_floor();
	$clean   = $once( static function () use ( $base, $restore ) {
		$restore();
		Minn_Admin_Switch::remove( $base );
	} );
	try {
		$held = static function ( $name, $version ) use ( $base, $stub ) {
			wp_mkdir_p( "{$base}/{$name}" );
			return array( 'dir' => "{$base}/{$name}", 'engine' => $stub, 'version' => $version, 'user' => get_current_user_id(), 'light' => 'GREEN', 'state' => 'switching' );
		};
		$version = '9.8.7';
		$GLOBALS['minn_v047_installer_code'] = 1;
		$failed  = $held( 'failed', $version );
		Minn_Admin_Switch::switch_now( str_repeat( 'a', 32 ), $failed );
		$ran = (array) ( $GLOBALS['minn_v047_installer_calls'] ?? array() );
		$check( 'I4 switch: the stub installer is what ran, asked to install', 1 === count( $ran ) && 'install' === ( $ran[0][0] ?? '' ), wp_json_encode( $ran ) );
		$check( 'I4 switch: a switch that failed leaves the floor where it was', false === get_option( $floor_option ) && is_file( $failed['dir'] . '/result.json' ), wp_json_encode( get_option( $floor_option ) ) );
		$GLOBALS['minn_v047_installer_code'] = 0;
		$done = $held( 'done', $version );
		Minn_Admin_Switch::switch_now( str_repeat( 'b', 32 ), $done );
		clearstatcache();
		$check( "I4 switch: a completed switch raises the floor to its release ({$version})", $version === get_option( $floor_option ) && ! is_dir( $done['dir'] ), wp_json_encode( get_option( $floor_option ) ) );
		$got = Minn_Admin_Switch::version_error( '9.8.6', '9.8.6' );
		$check( 'I4 switch: and an older release is refused after it', is_wp_error( $got ) && 'minn_switch_old' === $got->get_error_code(), is_wp_error( $got ) ? $got->get_error_code() : 'none' );
	} finally {
		unset( $GLOBALS['minn_v047_installer_code'], $GLOBALS['minn_v047_installer_calls'] );
		$clean();
	}
} )();

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\nsecurity-v047-registry-core: %d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
