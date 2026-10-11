<?php
/**
 * A second copy of Minn Admin in another folder stays out of the way while
 * the first runs, and activating it switches the site over (issue #67).
 *
 * GitHub's source zips unpack to minn-admin-<version>/ and a branch download
 * to minn-admin-main/, which WordPress installs beside minn-admin/ instead of
 * replacing it. Before 0.45 the second copy ran on the first one's folder: it
 * redefined the constants (a warning on every request) and either fataled on
 * a file the first lacked or activated and never ran.
 *
 * Builds a throwaway plugins/minn-admin-copytest/ holding this checkout's
 * minn-admin.php (MINN_COPY_SRC names another main file, for a before run),
 * activates it through core's activate_plugin() inside a transaction that is
 * rolled back, puts active_plugins back if anything committed it anyway, and
 * removes the folder.
 *
 * Run: wp eval-file tests/duplicate-copy.test.php --user=admin --path=<site>
 */

global $wpdb, $pagenow;

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

if ( is_multisite() || wp_using_ext_object_cache() ) {
	echo "SKIP  needs a single site without a persistent object cache (the run is rolled back)\n";
	return;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
$own  = plugin_basename( MINN_ADMIN_FILE );
$copy = 'minn-admin-copytest/minn-admin.php';
$dir  = WP_PLUGIN_DIR . '/minn-admin-copytest';
$src  = getenv( 'MINN_COPY_SRC' ) ? getenv( 'MINN_COPY_SRC' ) : MINN_ADMIN_DIR . 'minn-admin.php';
$raw  = static function () use ( $wpdb ) {
	return $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'" );
};
$before = $raw();

if ( ! current_user_can( 'activate_plugins' ) ) {
	echo "SKIP  run with --user=admin\n";
	return;
}

$notice = static function ( $screen ) use ( &$pagenow ) {
	$was     = $pagenow;
	$pagenow = $screen;
	wp_cache_delete( 'plugins', 'plugins' );
	ob_start();
	Minn_Admin::copies_notice();
	$pagenow = $was;
	return trim( wp_strip_all_tags( ob_get_clean() ) );
};
$set_active = static function ( array $list ) {
	update_option( 'active_plugins', $list );
};

if ( is_dir( $dir ) ) {
	array_map( 'unlink', glob( $dir . '/*' ) );
	rmdir( $dir );
}
mkdir( $dir );
copy( $src, $dir . '/minn-admin.php' );

$wpdb->query( 'START TRANSACTION' );
try {
	// The activation, the way Plugins › Activate does it: core includes the
	// copy while this one runs, then fires its activation hook.
	$warnings = array();
	set_error_handler(
		// Only the copy's own: other plugins' activation listeners may warn.
		static function ( $no, $str, $file = '' ) use ( &$warnings, $dir ) {
			if ( 0 === strpos( (string) $file, $dir ) ) {
				$warnings[] = $str;
			}
			return true;
		},
		E_WARNING | E_NOTICE | E_USER_WARNING
	);
	wp_cache_delete( 'plugins', 'plugins' );
	$out = activate_plugin( $copy );
	restore_error_handler();
	$active = (array) get_option( 'active_plugins' );
	$state  = wp_json_encode( array_values( array_intersect( $active, array( $own, $copy ) ) ) );

	$check( 'activating the second copy succeeds', null === $out, is_wp_error( $out ) ? $out->get_error_message() : '' );
	$check( 'loading it next to the running copy raises no warnings', ! $warnings, implode( ' | ', array_slice( $warnings, 0, 4 ) ) );
	$check( 'the copy activated is active', in_array( $copy, $active, true ), $state );
	$check( 'the copy that was running is switched off', ! in_array( $own, $active, true ), $state );
	$switch = get_site_transient( 'minn_admin_copy_switch' );
	$check(
		'the switch is recorded for the notice',
		is_array( $switch ) && MINN_ADMIN_VERSION === ( $switch['from'] ?? '' ) && $own === ( $switch['off'] ?? '' ) && $copy === ( $switch['to'] ?? '' ),
		wp_json_encode( $switch )
	);

	// The notices, from the running copy's side.
	delete_site_transient( 'minn_admin_copy_switch' );
	$set_active( array( $own, $copy ) );
	$text = $notice( 'index.php' );
	$check( 'both active: every admin screen says which copy runs', false !== strpos( $text, 'Two copies of Minn Admin are active' ) && false !== strpos( $text, 'plugins/minn-admin-copytest is not' ), $text );

	$set_active( array( $own ) );
	$text = $notice( 'plugins.php' );
	$check( 'an idle copy is named on Plugins', false !== strpos( $text, 'Another copy of Minn Admin is installed' ) && false !== strpos( $text, 'plugins/minn-admin-copytest is not active' ), $text );
	$check( 'an idle copy is not mentioned elsewhere', '' === $notice( 'index.php' ), $notice( 'index.php' ) );

	set_site_transient( 'minn_admin_copy_switch', array( 'from' => '0.1.0', 'off' => $copy, 'to' => $own ), HOUR_IN_SECONDS );
	$text = $notice( 'plugins.php' );
	$check( 'after a switch Plugins confirms it once', false !== strpos( $text, 'is now the copy that runs' ) && false !== strpos( $text, 'version 0.1.0 in plugins/minn-admin-copytest' ), $text );
	$check( 'the confirmation is not repeated', false === get_site_transient( 'minn_admin_copy_switch' ) && false === strpos( $notice( 'plugins.php' ), 'is now the copy that runs' ) );

	set_site_transient( 'minn_admin_copy_switch', array( 'from' => '0.1.0', 'off' => $copy, 'to' => $own ), HOUR_IN_SECONDS );
	$set_active( array( $own, $copy ) );
	$text = $notice( 'plugins.php' );
	$check( 'a switch since undone is not confirmed', false === strpos( $text, 'is now the copy that runs' ) && false !== strpos( $text, 'Two copies of Minn Admin are active' ), $text );

	$editor = get_users( array( 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ) );
	if ( $editor ) {
		$admin = get_current_user_id();
		wp_set_current_user( (int) $editor[0] );
		$check( 'only people who can activate plugins see it', '' === $notice( 'index.php' ) );
		wp_set_current_user( $admin );
	}
} finally {
	$wpdb->query( 'ROLLBACK' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'active_plugins', 'options' );
	if ( $raw() !== $before ) {
		$wpdb->update( $wpdb->options, array( 'option_value' => $before ), array( 'option_name' => 'active_plugins' ) );
		$check( 'the rollback held (active_plugins put back by hand)', false );
	}
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%minn_admin_copy_switch%'" );
	array_map( 'unlink', glob( $dir . '/*' ) );
	rmdir( $dir );
}

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
