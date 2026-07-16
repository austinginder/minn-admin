<?php
/**
 * Deleting the plugin removes its table. Deactivation keeps data (the
 * WordPress convention); only an explicit delete is destructive.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'campfire_feedback' ); // phpcs:ignore
