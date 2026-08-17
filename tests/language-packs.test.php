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

	public function request() {
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
