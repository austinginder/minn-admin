<?php
/**
 * On a network, a language another site runs in is not offered for removal.
 *
 * Every site reads the one shared WP_LANG_DIR and keeps its own WPLANG (a
 * site with none falls back to the network's). Languages › Remove used to
 * protect only the current site's language and users' choices, so a super
 * admin on an English site could delete the language another site runs in,
 * and that site's administrator could not reinstall it.
 *
 * Plants two fake core catalogs (a .po and .mo pair each, which is all
 * wp_get_installed_translations() reads) for locales the lab doesn't use,
 * gives one to another site, then to the network default, and removes
 * everything it planted.
 *
 * Run from a site that is not the main one, as a super admin:
 * wp eval-file tests/multisite-languages.test.php --user=admin --url=https://minnmsd.localhost/store/ --path=<network>
 */

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

if ( ! is_multisite() || is_main_site() || ! is_super_admin() ) {
	echo "SKIP  needs a network, a site other than the main one, and a super admin\n";
	return;
}

$a     = 'gd_GB';
$b     = 'cy_GB';
$other = 0;
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $id ) {
	if ( (int) $id !== get_current_blog_id() && ! is_main_site( $id ) ) {
		$other = (int) $id;
		break;
	}
}
$installed = get_available_languages();
if ( ! $other || in_array( $a, $installed, true ) || in_array( $b, $installed, true ) ) {
	echo "SKIP  needs a third site, and neither {$a} nor {$b} installed\n";
	return;
}

$planted = array();
$made    = ! is_dir( WP_LANG_DIR );
wp_mkdir_p( WP_LANG_DIR );
foreach ( array( $a, $b ) as $loc ) {
	file_put_contents( WP_LANG_DIR . "/{$loc}.po", "msgid \"\"\nmsgstr \"\"\n\"Language: {$loc}\\n\"\n\"PO-Revision-Date: 2026-01-01 00:00+0000\\n\"\n" );
	file_put_contents( WP_LANG_DIR . "/{$loc}.mo", '' );
	$planted[] = WP_LANG_DIR . "/{$loc}.po";
	$planted[] = WP_LANG_DIR . "/{$loc}.mo";
}
$other_had  = get_blog_option( $other, 'WPLANG', null );
$network_had = get_site_option( 'WPLANG', '' );
$row = static function ( $loc ) {
	// Core caches each language directory's file list (key: md5 of the
	// path with its trailing slash).
	wp_cache_delete( md5( trailingslashit( WP_LANG_DIR ) ), 'translation_files' );
	foreach ( Minn_Admin_REST::installed_languages() as $lang ) {
		if ( $lang['locale'] === $loc ) {
			return $lang;
		}
	}
	return null;
};
$remove = static function ( $loc ) {
	$req = new WP_REST_Request( 'POST', '/minn-admin/v1/languages/remove' );
	$req->set_param( 'locale', $loc );
	$out = Minn_Admin_REST::remove_language( $req );
	return is_wp_error( $out ) ? $out->get_error_code() : 'removed';
};

try {
	$r = $row( $a );
	$check( 'a language nothing uses is offered for removal', $r && $r['removable'], wp_json_encode( $r ) );

	update_blog_option( $other, 'WPLANG', $a );
	$r = $row( $a );
	$check( 'a language another site runs in is kept', $r && ! $r['removable'] && 'sites' === $r['reason'] && 1 === $r['sites'], wp_json_encode( $r ) );
	$check( 'and its removal is refused', 'minn_admin_locale_network' === $remove( $a ) );
	$check( 'its files are still there', is_file( WP_LANG_DIR . "/{$a}.mo" ) );

	// The main site has no WPLANG of its own here, so it runs in the
	// network's language.
	$main_has = get_blog_option( get_main_site_id(), 'WPLANG', null );
	update_site_option( 'WPLANG', $b );
	$r = $row( $b );
	if ( null === $main_has ) {
		$check( 'the network language counts for sites without their own', $r && ! $r['removable'] && 'sites' === $r['reason'], wp_json_encode( $r ) );
	}
	update_site_option( 'WPLANG', $network_had );

	add_filter( 'wp_is_large_network', '__return_true' );
	$r = $row( $b );
	$check( 'on a network too large to check, nothing is offered', $r && ! $r['removable'] && 'network' === $r['reason'], wp_json_encode( $r ) );
	$check( 'and removal is refused', 'minn_admin_locale_large_network' === $remove( $b ) );
	remove_filter( 'wp_is_large_network', '__return_true' );
} finally {
	if ( null === $other_had ) {
		delete_blog_option( $other, 'WPLANG' );
	} else {
		update_blog_option( $other, 'WPLANG', $other_had );
	}
	update_site_option( 'WPLANG', $network_had );
	array_map( 'unlink', array_filter( $planted, 'is_file' ) );
	if ( $made ) {
		@rmdir( WP_LANG_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- only if still empty.
	}
}

if ( in_array( false, $results, true ) ) {
	exit( 1 );
}
