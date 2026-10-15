<?php
/**
 * Switching a WordPress site to Minn (Minn_Admin_Switch): what it trusts and
 * who may use it. Nothing here switches the site.
 *
 * - A release is used only with a signature from a key Minn Engine trusts:
 *   a signed minn.zip passes (MINN_SWITCH_TEST_ZIP, with its .sig beside it,
 *   when one is in hand), the same bytes with one changed do not, and
 *   neither does a zip with no signature or a forged one.
 * - Staging refuses a zip a trusted key did not sign, or one that does not
 *   match its release checksum, before anything is unpacked, and leaves no
 *   folder behind. (Entries outside minn/ are refused after the signature
 *   check, which only a release key can pass, so no test zip reaches it.)
 * - A release from the service must come from its download path.
 * - The routes answer only someone who may update core; `wp minn` is
 *   registered on WordPress, and nothing of this on the engine.
 *
 * Run: wp eval-file tests/minn-switch.test.php --user=admin --path=<site>
 *      MINN_SWITCH_TEST_ZIP=/path/to/minn.zip to include a real release.
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
if ( ! class_exists( 'Minn_Admin_Switch' ) ) {
	echo "FAIL  Minn_Admin_Switch is not loaded\n";
	exit( 1 );
}
if ( Minn_Admin::is_engine() ) {
	echo "SKIP  this runs on WordPress; the engine has its own wp minn\n";
	return;
}

$upgrade  = WP_CONTENT_DIR . '/upgrade';
$staged   = function () use ( $upgrade ) {
	return (array) glob( $upgrade . '/minn-switch-*', GLOB_ONLYDIR );
};
$before   = $staged();
$scratch  = get_temp_dir() . 'minn-switch-test-' . wp_generate_password( 8, false );
wp_mkdir_p( $scratch );
$make_zip = function ( $path, array $entries ) {
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	foreach ( $entries as $name => $body ) {
		$zip->addFromString( $name, $body );
	}
	$zip->close();
	return $path;
};

// --- Signatures ---------------------------------------------------------------
$real = (string) getenv( 'MINN_SWITCH_TEST_ZIP' );
if ( '' !== $real && is_file( $real ) && is_file( $real . '.sig' ) ) {
	$bytes = (string) file_get_contents( $real );
	$sig   = trim( (string) file_get_contents( $real . '.sig' ) );
	$check( 'a signed Minn release passes', Minn_Admin_Switch::signed( $bytes, $sig ) );
	$check( 'the same release with one byte changed does not', ! Minn_Admin_Switch::signed( substr( $bytes, 0, -1 ) . chr( ord( substr( $bytes, -1 ) ) ^ 1 ), $sig ) );
} else {
	echo "SKIP  a signed release (set MINN_SWITCH_TEST_ZIP to a minn.zip with its .sig beside it)\n";
}
$check( 'a forged signature does not pass', ! Minn_Admin_Switch::signed( 'minn', base64_encode( str_repeat( 'x', 64 ) ) ) );
$check( 'a signature that is not one does not pass', ! Minn_Admin_Switch::signed( 'minn', 'not a signature' ) );

// --- Releases in hand ----------------------------------------------------------
$missing = Minn_Admin_Switch::release( $scratch . '/nope.zip' );
$check( 'a zip that is not there is refused', is_wp_error( $missing ) && 'minn_switch_zip' === $missing->get_error_code() );
$unsigned = $make_zip( $scratch . '/unsigned.zip', array( 'minn/bootstrap.php' => "<?php define('MINN_ENGINE_VERSION', '9.9.9');" ) );
$no_sig   = Minn_Admin_Switch::release( $unsigned );
$check( 'a zip with no signature beside it is refused', is_wp_error( $no_sig ) && 'minn_switch_zip' === $no_sig->get_error_code() );

if ( PHP_VERSION_ID >= Minn_Admin_Switch::PHP_MIN ) {
	file_put_contents( $unsigned . '.sig', base64_encode( str_repeat( 'x', 64 ) ) );
	$forged = Minn_Admin_Switch::stage( Minn_Admin_Switch::release( $unsigned ) );
	$check( 'staging refuses a zip a trusted key did not sign', is_wp_error( $forged ) && 'minn_switch_signature' === $forged->get_error_code(), is_wp_error( $forged ) ? $forged->get_error_code() : 'staged' );
	$check( 'and leaves no folder behind', $staged() === $before, wp_json_encode( array_diff( $staged(), $before ) ) );
	$checksum = Minn_Admin_Switch::stage(
		array(
			'version'   => '9.9.9',
			'package'   => $unsigned,
			'sha256'    => str_repeat( '0', 64 ),
			'signature' => base64_encode( str_repeat( 'x', 64 ) ),
			'notes'     => '',
		)
	);
	$check( 'staging refuses a zip that does not match its release checksum', is_wp_error( $checksum ) && 'minn_switch_checksum' === $checksum->get_error_code(), is_wp_error( $checksum ) ? $checksum->get_error_code() : 'staged' );
} else {
	echo "SKIP  staging (Minn needs PHP 8.2)\n";
}

// --- Who may use it --------------------------------------------------------------
$routes = rest_get_server()->get_routes();
$check( 'the check, switch and status routes are registered on WordPress', isset( $routes['/minn-admin/v1/minn-switch/check'], $routes['/minn-admin/v1/minn-switch/run'], $routes['/minn-admin/v1/minn-switch/status'] ) );
$admin = get_current_user_id();
$check( 'an administrator who may update core may use them', current_user_can( 'update_core' ) === Minn_Admin_Switch::can() || ! wp_is_file_mod_allowed( 'minn_switch' ) );
$editor = (int) wp_insert_user(
	array(
		'user_login' => 'minn-switch-editor-' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'editor',
	)
);
if ( $editor > 1 ) {
	wp_set_current_user( $editor );
	$check( 'an editor may not', ! Minn_Admin_Switch::can() );
	$r   = new WP_REST_Request( 'POST', '/minn-admin/v1/minn-switch/check' );
	$res = rest_do_request( $r );
	$check( 'an editor asking for a check is refused before anything is fetched', 403 === $res->get_status(), (string) $res->get_status() );
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $editor );
}
$run = new WP_REST_Request( 'POST', '/minn-admin/v1/minn-switch/run' );
$run->set_param( 'token', str_repeat( 'a', 32 ) );
$run->set_param( 'confirm', 'nope' );
$res = rest_do_request( $run );
$check( 'a switch without a check of its own is refused', 409 === $res->get_status(), (string) $res->get_status() );
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$found = WP_CLI::get_runner()->find_command_to_run( array( 'minn', 'install' ) );
	$check( 'wp minn install is registered on WordPress', is_array( $found ) );
}

// --- Cleanup -----------------------------------------------------------------------
foreach ( (array) glob( $scratch . '/*' ) as $f ) {
	unlink( $f );
}
rmdir( $scratch );

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nminn-switch: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
