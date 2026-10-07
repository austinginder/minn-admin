<?php
/**
 * Language-pack delivery, checked against the real updater.
 *
 * These are the gates that decide what gets downloaded and installed into a
 * site, so they are worth pinning: a regression here either stops offering
 * translations (visible, annoying) or starts accepting packages from
 * somewhere it should not (invisible, much worse).
 *
 * Run: wp eval-file tests/language-packs.test.php --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};

$updater = new Minn_Admin_Updater();

$ok_url  = 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/minn-admin-de_DE.zip';
$manifest = (object) array(
	'version'      => '0.30.0',
	'download_url' => 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/minn-admin.zip',
	'sha256'       => 'aaaa',
	'translations' => array(
		// Deliberately newer than any pack a development site may already have;
		// this gate is about wanted/verified offers, not installed-version skips.
		(object) array( 'language' => 'de_DE', 'version' => '99.0.0', 'updated' => '2026-08-14 00:00:00', 'package' => $ok_url, 'sha256' => 'bbbb' ),
		// No sha256: must be refused, not silently trusted.
		(object) array( 'language' => 'fr_FR', 'version' => '0.30.0', 'updated' => '2026-08-14 00:00:00', 'package' => 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/minn-admin-fr_FR.zip' ),
		// Foreign host.
		(object) array( 'language' => 'ja', 'version' => '0.30.0', 'updated' => '2026-08-14 00:00:00', 'package' => 'https://evil.example.com/minn-admin-ja.zip', 'sha256' => 'cccc' ),
		// Valid, but not a locale this site uses.
		(object) array( 'language' => 'it_IT', 'version' => '0.30.0', 'updated' => '2026-08-14 00:00:00', 'package' => 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/minn-admin-it_IT.zip', 'sha256' => 'dddd' ),
	),
);

class Minn_Admin_Updater_Manifest_Drift_Test extends Minn_Admin_Updater {
	public $mock_manifest;

	public function request( $remote_only = false ) {
		return $this->mock_manifest;
	}
}

/* --- hash lookup is three-state ------------------------------------------ */
$check( 'Plugin zip resolves its own hash', 'aaaa' === $updater->hash_for_package( $manifest, $manifest->download_url ) );
$check( 'Language pack resolves its hash', 'bbbb' === $updater->hash_for_package( $manifest, $ok_url ) );
$check(
	'Pack with no sha256 returns "" (refuse), not null (pass through)',
	'' === $updater->hash_for_package( $manifest, $manifest->translations[1]->package )
);
$check(
	'Hash lookup distinguishes an unclaimed URL',
	null === $updater->hash_for_package( $manifest, 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/unrelated.zip' )
);

/* --- cached offers stay bound to a hash after manifest drift ------------- */
$drift = new Minn_Admin_Updater_Manifest_Drift_Test();
$drift->mock_manifest = (object) array(
	'download_url' => 'https://github.com/austinginder/minn-admin/releases/download/v0.30.1/minn-admin.zip',
	'sha256'       => str_repeat( 'a', 64 ),
	'translations' => array(),
);
$drift_result = $drift->verify_package( false, $ok_url, null );
$check(
	'A cached Minn package absent from the current manifest is refused',
	is_wp_error( $drift_result ) && 'minn_admin_missing_package_hash' === $drift_result->get_error_code()
);
$check(
	'An unrelated package still passes through untouched',
	false === $drift->verify_package( false, 'https://downloads.wordpress.org/plugin/hello-dolly.zip', null )
);

/* --- package URL gate ---------------------------------------------------- */
$check( 'Accepts a pack under this repo', $updater->is_our_package_url( $ok_url ) );
$check( 'Rejects a foreign host', ! $updater->is_our_package_url( 'https://evil.example.com/minn-admin-ja.zip' ) );
$check( 'Rejects plain http', ! $updater->is_our_package_url( 'http://github.com/austinginder/minn-admin/x.zip' ) );
$check(
	'Rejects an unanchored path on a GitHub host',
	! $updater->is_our_package_url( 'https://github.com/attacker/repo/raw/main/austinginder/minn-admin/x.zip' )
);

/* --- what actually gets offered ------------------------------------------ */
add_filter( 'minn_admin_translation_locales', function () {
	return array( 'de_DE', 'fr_FR', 'ja', 'es_ES' );
} );

$method = new ReflectionMethod( $updater, 'offer_translations' );
$method->setAccessible( true );
$transient               = new stdClass();
$transient->translations = array();
$method->invoke( $updater, $transient, $manifest );

$offered = wp_list_pluck( $transient->translations, 'language' );
$check( 'Offers the valid, wanted locale', in_array( 'de_DE', $offered, true ) );
$check( 'Never offers a pack it could not verify', ! in_array( 'fr_FR', $offered, true ) );
$check( 'Never offers a pack from a foreign host', ! in_array( 'ja', $offered, true ) );
$check( 'Does not offer locales the site cannot display', ! in_array( 'it_IT', $offered, true ) );

$first = $transient->translations[0] ?? array();
$check( 'Entry is shaped the way core reads it', 'plugin' === ( $first['type'] ?? '' ) && 'minn-admin' === ( $first['slug'] ?? '' ) && true === ( $first['autoupdate'] ?? null ) );

/* --- formal locales fall back to the parent catalog ---------------------- */
$check( 'de_DE_formal lists de_DE as a catalog parent', array( 'de_DE_formal', 'de_DE' ) === Minn_Admin::catalog_locales( 'de_DE_formal' ) );
$check( 'nl_NL_formal lists nl_NL as a catalog parent', array( 'nl_NL_formal', 'nl_NL' ) === Minn_Admin::catalog_locales( 'nl_NL_formal' ) );
$check( 'pt_PT_ao90 lists pt_PT as a catalog parent', array( 'pt_PT_ao90', 'pt_PT' ) === Minn_Admin::catalog_locales( 'pt_PT_ao90' ) );
$check( 'de_DE is not rewritten', array( 'de_DE' ) === Minn_Admin::catalog_locales( 'de_DE' ) );
$check( 'pt_PT does not fall through to pt_BR', array( 'pt_PT' ) === Minn_Admin::catalog_locales( 'pt_PT' ) );
$check( 'de_CH lists de_DE as a catalog parent', array( 'de_CH', 'de_DE' ) === Minn_Admin::catalog_locales( 'de_CH' ) );
$check( 'de_CH_informal walks through de_CH to de_DE', array( 'de_CH_informal', 'de_CH', 'de_DE' ) === Minn_Admin::catalog_locales( 'de_CH_informal' ) );
$check( 'de_AT lists de_DE as a catalog parent', array( 'de_AT', 'de_DE' ) === Minn_Admin::catalog_locales( 'de_AT' ) );
$check( 'fr_CA does not fall through to fr_FR', array( 'fr_CA' ) === Minn_Admin::catalog_locales( 'fr_CA' ) );

remove_all_filters( 'minn_admin_translation_locales' );
add_filter( 'minn_admin_translation_locales', function () {
	return array( 'de_DE_formal' );
} );
$formal_offer               = new stdClass();
$formal_offer->translations = array();
$method->invoke( $updater, $formal_offer, $manifest );
$offered_formal = wp_list_pluck( $formal_offer->translations, 'language' );
$check( 'A formal locale still receives the parent pack', in_array( 'de_DE', $offered_formal, true ) );

remove_all_filters( 'minn_admin_translation_locales' );
add_filter( 'minn_admin_translation_locales', function () {
	return array( 'de_CH' );
} );
$swiss_offer               = new stdClass();
$swiss_offer->translations = array();
$method->invoke( $updater, $swiss_offer, $manifest );
$check( 'A Swiss German site receives the de_DE pack', in_array( 'de_DE', wp_list_pluck( $swiss_offer->translations, 'language' ), true ) );

// The version checks below assume the formal locale above is still wanted.
remove_all_filters( 'minn_admin_translation_locales' );
add_filter( 'minn_admin_translation_locales', function () {
	return array( 'de_DE_formal' );
} );

$installedLookup = new ReflectionMethod( $updater, 'installed_translations' );
$installedLookup->setAccessible( true );
$haveDePack = ! empty( $installedLookup->invoke( $updater )['de_DE'] );
if ( $haveDePack ) {
	$check( 'plugin_locale remaps de_DE_formal onto the installed de_DE catalog', 'de_DE' === Minn_Admin::plugin_locale( 'de_DE_formal', 'minn-admin' ) );
	$check( 'plugin_locale leaves other domains alone', 'de_DE_formal' === Minn_Admin::plugin_locale( 'de_DE_formal', 'woocommerce' ) );

	$admin = get_user_by( 'login', 'admin' );
	if ( $admin ) {
		$uid  = (int) $admin->ID;
		$prev = get_user_meta( $uid, 'locale', true );
		wp_set_current_user( $uid );
		update_user_meta( $uid, 'locale', 'de_DE_formal' );
		clean_user_cache( $uid );
		$formal_map = Minn_Admin::js_translations();
		update_user_meta( $uid, 'locale', $prev );
		clean_user_cache( $uid );
		$check( 'js_translations serves de_DE strings for de_DE_formal', isset( $formal_map['Overview'] ) && 'Übersicht' === $formal_map['Overview'] );

		$swiss_maps = array();
		foreach ( array( 'de_CH', 'de_CH_informal', 'de_AT' ) as $regional ) {
			update_user_meta( $uid, 'locale', $regional );
			clean_user_cache( $uid );
			$swiss_maps[ $regional ] = Minn_Admin::js_translations();
		}
		update_user_meta( $uid, 'locale', $prev );
		clean_user_cache( $uid );
		foreach ( $swiss_maps as $regional => $map ) {
			$check( "js_translations serves German for {$regional}", isset( $map['Overview'] ) && 'Übersicht' === $map['Overview'] );
		}
		$check( 'plugin_locale remaps de_CH onto the installed de_DE catalog', 'de_DE' === Minn_Admin::plugin_locale( 'de_CH', 'minn-admin' ) );
	}

	/* --- the PHP half loads the parent catalog too -------------------------- */
	// core's loader only looks for the exact locale's file, so this is what
	// keeps REST labels German for a de_CH user. load_textdomain() makes its
	// locale argument current for EVERY domain: the parent must be filed
	// under de_CH, or the next domain to load flips the controller back and
	// Minn's strings go dark (English in the browser, German under wp eval).
	if ( class_exists( 'WP_Translation_Controller' ) ) {
		$controller  = WP_Translation_Controller::get_instance();
		$prev_locale = $controller->get_locale();
		$controller->set_locale( 'de_CH' );
		unload_textdomain( 'minn-admin' );
		Minn_Admin::load_parent_catalog( 'de_CH' );
		$check( 'Loading the parent catalog leaves the current locale alone', 'de_CH' === $controller->get_locale() );
		$controller->set_locale( 'de_CH' );
		$check( 'PHP strings are German for de_CH after another domain loads', 'Guten Morgen' === __( 'Good morning', 'minn-admin' ) );
		unload_textdomain( 'minn-admin' );
		$controller->set_locale( $prev_locale );
	}

	if ( in_array( 'de_CH', get_available_languages(), true ) ) {
		unload_textdomain( 'minn-admin' );
		switch_to_locale( 'de_CH' );
		$minn_switched = __( 'Good morning', 'minn-admin' );
		$core_switched = __( 'Close' );
		restore_previous_locale();
		$check( 'A switch to de_CH reloads the parent catalog', 'Guten Morgen' === $minn_switched, $minn_switched );
		$check( "core's own de_CH strings survive beside it", 'Schliessen' === $core_switched, $core_switched );
	} else {
		echo "SKIP  de_CH locale switch (core de_CH language not installed)\n";
	}
} else {
	echo "SKIP  formal catalog fallback (no de_DE pack installed in wp-content/languages/plugins)\n";
}

/* --- already-installed packs are skipped by VERSION ------------------------ */
$reflect = new ReflectionMethod( $updater, 'installed_translations' );
$reflect->setAccessible( true );
$check( 'installed_translations() returns an array', is_array( $reflect->invoke( $updater ) ) );

// The version can only be read when packs ship their .po: core's
// wp_get_installed_translations() takes headers from the .po and skips any
// .mo with no .po beside it. A .mo-only pack reports as not installed, and
// every pack is then re-offered on every check for the life of the site.
$parse = new ReflectionMethod( $updater, 'version_from_project_id' );
$parse->setAccessible( true );
$check( 'Reads a version out of Project-Id-Version', '0.29.0' === $parse->invoke( null, 'Minn Admin 0.29.0' ) );
$check( 'Reads a prerelease version', '1.0.0-beta.2' === $parse->invoke( null, 'Minn Admin 1.0.0-beta.2' ) );
$check( 'An unstamped header parses to empty, not garbage', '' === $parse->invoke( null, 'Minn Admin' ) );

$offer = new ReflectionMethod( $updater, 'offer_translations' );
$offer->setAccessible( true );
$mkPack = function ( $version ) {
	return (object) array(
		'language' => 'de_DE',
		'version'  => $version,
		'updated'  => '2026-08-14 00:00:00',
		'package'  => "https://github.com/austinginder/minn-admin/releases/download/v$version/minn-admin-de_DE.zip",
		'sha256'   => str_repeat( 'a', 64 ),
	);
};
$countFor = function ( $version ) use ( $offer, $updater, $mkPack ) {
	$t = new stdClass();
	$t->translations = array();
	$offer->invoke( $updater, $t, (object) array( 'translations' => array( $mkPack( $version ) ) ) );
	return count( $t->translations );
};

$haveDe = $reflect->invoke( $updater );
if ( ! empty( $haveDe['de_DE'] ) ) {
	$installedVersion = $haveDe['de_DE'];
	$older = '0.0.1';
	$newer = '999.0.0';
	$check( 'A pack at the installed version is not re-offered', 0 === $countFor( $installedVersion ) );
	$check( 'An older pack is not offered', 0 === $countFor( $older ) );
	$check( 'A newer pack IS offered', 1 === $countFor( $newer ) );
} else {
	echo "SKIP  version gating (no de_DE pack installed in wp-content/languages/plugins)\n";
}

$failed = count( array_filter( $results, function ( $r ) { return ! $r; } ) );
printf( "\nlanguage-packs: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
