<?php
/**
 * Bulk-update progress, readable while WordPress is in maintenance mode.
 *
 * A plugin batch puts the site in maintenance mode whenever an active
 * plugin is being replaced, and WordPress answers every request with a 503
 * until the batch lifts it, REST included. The panel that shows the batch
 * would be blind for exactly the part worth watching. So the batch also
 * writes its progress record to a file, and this script reads it back
 * without loading WordPress at all.
 *
 * The file lives at <wp-content>/minn-admin-progress/<token>.json, where
 * the token is 40 random hex characters minted by the client; anything else
 * is refused, and nothing but that one file is ever read. The record holds
 * plugin files, versions, byte counts and states: nothing a site owner would
 * mind another site owner seeing, and the token is the only way to it.
 */

header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store, max-age=0' );
header( 'X-Content-Type-Options: nosniff' );

$token = isset( $_GET['t'] ) ? (string) $_GET['t'] : ''; // phpcs:ignore WordPress.Security.NonceVerification
if ( ! preg_match( '/^[a-f0-9]{40}$/', $token ) ) {
	http_response_code( 400 );
	echo '{"known":false}';
	exit;
}

// The plugin lives at <wp-content>/plugins/minn-admin; the batch only hands
// out this URL when that layout holds, so the walk up is safe. The walk
// starts from the path the web server used, not __DIR__: a symlinked plugin
// directory (shared between sites) would otherwise resolve to the wrong
// site's wp-content.
$script  = isset( $_SERVER['SCRIPT_FILENAME'] ) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
$content = dirname( ( '' !== $script && is_file( $script ) ) ? $script : __FILE__, 3 );
$file    = $content . '/minn-admin-progress/' . $token . '.json';
$raw     = is_file( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
if ( false === $raw || '' === $raw ) {
	echo '{"known":false}';
	exit;
}
$data = json_decode( $raw, true );
if ( ! is_array( $data ) ) {
	echo '{"known":false}';
	exit;
}
// wp-content normally sits in the site root; the maintenance flag there is
// what the reader wants to explain a 503 elsewhere.
$data['maintenance'] = is_file( dirname( $content ) . '/.maintenance' );
echo wp_json_encode_fallback( $data );

function wp_json_encode_fallback( $data ) {
	return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions
}
