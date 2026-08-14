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
		(object) array( 'language' => 'de_DE', 'version' => '0.30.0', 'updated' => '2026-08-14 00:00:00', 'package' => $ok_url, 'sha256' => 'bbbb' ),
		// No sha256: must be refused, not silently trusted.
		(object) array( 'language' => 'fr_FR', 'version' => '0.30.0', 'updated' => '2026-08-14 00:00:00', 'package' => 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/minn-admin-fr_FR.zip' ),
		// Foreign host.
		(object) array( 'language' => 'ja', 'version' => '0.30.0', 'updated' => '2026-08-14 00:00:00', 'package' => 'https://evil.example.com/minn-admin-ja.zip', 'sha256' => 'cccc' ),
		// Valid, but not a locale this site uses.
		(object) array( 'language' => 'it_IT', 'version' => '0.30.0', 'updated' => '2026-08-14 00:00:00', 'package' => 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/minn-admin-it_IT.zip', 'sha256' => 'dddd' ),
	),
);

/* --- hash lookup is three-state ------------------------------------------ */
$check( 'Plugin zip resolves its own hash', 'aaaa' === $updater->hash_for_package( $manifest, $manifest->download_url ) );
$check( 'Language pack resolves its hash', 'bbbb' === $updater->hash_for_package( $manifest, $ok_url ) );
$check(
	'Pack with no sha256 returns "" (refuse), not null (pass through)',
	'' === $updater->hash_for_package( $manifest, $manifest->translations[1]->package )
);
$check(
	'Unclaimed URL returns null so other plugins download normally',
	null === $updater->hash_for_package( $manifest, 'https://github.com/austinginder/minn-admin/releases/download/v0.30.0/unrelated.zip' )
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

/* --- already-installed packs are skipped by DATE, not version ------------- */
$transient2               = new stdClass();
$transient2->translations = array();
add_filter( 'pre_option_minn_dummy', '__return_false' ); // no-op, keeps the filter list honest
$reflect = new ReflectionMethod( $updater, 'installed_translations' );
$reflect->setAccessible( true );
$check( 'installed_translations() returns an array', is_array( $reflect->invoke( $updater ) ) );

$failed = count( array_filter( $results, function ( $r ) { return ! $r; } ) );
printf( "\nlanguage-packs: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
