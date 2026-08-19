<?php
/**
 * WP Migrate (Delicious Brains / WP Engine) — push and pull from Minn.
 *
 * WP Migrate's own screen is a React app that drives the migration from the
 * browser over two channels, and this adapter hands Minn what it needs to
 * drive the same ones rather than reimplementing any of the work:
 *
 *   1. their REST namespace `mdb-api/v1` (verify-connection,
 *      initiate-migration, finalize-migration, cancel-migration), which
 *      Minn's client can call directly because a wp_rest nonce authenticates
 *      any REST route and their permission callback is a capability check;
 *   2. `wp_ajax_wpmdb_migrate_table`, the chunked loop the browser polls
 *      once per table until the response says the table is finished. That
 *      handler authenticates with a nonce for the `migrate-table` action and
 *      binds to no screen, so Minn mints the same nonce here.
 *
 * Nothing about the migration itself is re-implemented: every row that moves
 * is moved by WP Migrate's own code, on both ends.
 *
 * The capability is theirs, including their filter, so a site that has
 * narrowed who may migrate keeps that answer in Minn.
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP Migrate Pro is loaded and its migration machinery is available.
 */
function minn_admin_wp_migrate_active() {
	return defined( 'WPMDB_PRO' ) && WPMDB_PRO && class_exists( '\DeliciousBrains\WPMDB\WPMDBDI' );
}

/**
 * Their own capability answer, filter included.
 */
function minn_admin_wp_migrate_cap() {
	$cap = is_multisite() ? 'manage_network_options' : 'export';
	return (string) apply_filters( 'wpmdb_ajax_cap', $cap );
}

function minn_admin_wp_migrate_can() {
	return minn_admin_wp_migrate_active() && current_user_can( minn_admin_wp_migrate_cap() );
}

/**
 * A migration only runs while the license is good enough for their own
 * connection gate, which passes on an expired subscription by their design
 * (migrations keep working; updates and support stop).
 */
function minn_admin_wp_migrate_licensed() {
	if ( ! minn_admin_wp_migrate_active() ) {
		return false;
	}
	try {
		$license = \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Pro\License::class );
		return (bool) $license->is_valid_licence();
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Installed version, read from the plugin header (WP Migrate defines no
 * version constant of its own).
 */
function minn_admin_wp_migrate_version() {
	if ( ! defined( 'WPMDBPRO_FILE' ) ) {
		return '';
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( WPMDBPRO_FILE, false, false );
	return isset( $data['Version'] ) ? (string) $data['Version'] : '';
}

/**
 * This site's own details, built by WP Migrate's own code so a migration
 * started from Minn describes the local end exactly as their screen does.
 * Their local-site-details route answers add-on availability only, and
 * their React app reads the rest from its page bootstrap, so Minn reads it
 * from the same source: Util::site_details() and the prefix-scoped table
 * list, which is the set their "all tables with prefix" option means.
 */
function minn_admin_wp_migrate_local() {
	try {
		$di      = \DeliciousBrains\WPMDB\WPMDBDI::getInstance();
		$util    = $di->get( \DeliciousBrains\WPMDB\Common\Util\Util::class );
		$table   = $di->get( \DeliciousBrains\WPMDB\Common\Sql\Table::class );
		$details = $util->site_details();
		return array(
			'site_url' => isset( $details['site_url'] ) ? $details['site_url'] : untrailingslashit( site_url() ),
			'home_url' => isset( $details['home_url'] ) ? $details['home_url'] : untrailingslashit( home_url() ),
			'prefix'   => isset( $details['prefix'] ) ? $details['prefix'] : $GLOBALS['wpdb']->base_prefix,
			// The find-and-replace path pair is the site root on disk, which
			// is what their remote reports for its own end.
			'path'     => \DeliciousBrains\WPMDB\Common\Util\Util::get_absolute_root_file_path(),
			'tables'   => array_values( (array) $table->get_tables( 'prefix' ) ),
			'details'  => $details,
		);
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Boot payload for the Migrate view. The migrate-table nonce is the same one
 * their own page embeds, so it is handed over only to a user who holds their
 * capability, and never on a site where the plugin is not running.
 */
function minn_admin_wp_migrate_boot() {
	if ( ! minn_admin_wp_migrate_can() ) {
		return null;
	}
	$settings = get_site_option( 'wpmdb_settings' );
	$settings = is_array( $settings ) ? $settings : array();
	return array(
		'restBase'  => 'mdb-api/v1',
		'ajax'      => admin_url( 'admin-ajax.php' ),
		// Util::create_nonce runs wp_create_nonce with their nonce filters
		// removed, which is what their handler verifies against.
		'nonce'     => \DeliciousBrains\WPMDB\Common\Util\Util::create_nonce( 'migrate-table' ),
		'licensed'  => minn_admin_wp_migrate_licensed(),
		// They define no version constant; the header is the reliable source.
		'version'   => minn_admin_wp_migrate_version(),
		'adminUrl'  => admin_url( 'tools.php?page=wp-migrate-db-pro' ),
		// What this site will accept FROM a remote, which is the answer the
		// other end needs and the one people forget to turn on.
		'allowPush' => ! empty( $settings['allow_push'] ),
		'allowPull' => ! empty( $settings['allow_pull'] ),
		'prefix'    => $GLOBALS['wpdb']->base_prefix,
		'siteUrl'   => untrailingslashit( home_url() ),
		// This end of the migration, described the way their own code
		// describes it.
		'local'     => minn_admin_wp_migrate_local(),
	);
}
