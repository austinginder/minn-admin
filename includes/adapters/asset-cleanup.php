<?php
/**
 * Asset CleanUp adapter — global performance settings as a Minn surface.
 *
 * The page-level CSS/JS unload manager is a canvas (DOM fetch of every asset
 * on a URL); Minn links out for that. Daily global toggles live in the JSON
 * option `wpassetcleanup_settings`. The screen is declared to the settings
 * tier, which owns its routes and the order of a save.
 *
 * HARD-WON: Asset CleanUp Lite deliberately no-loads on REST API requests
 * (`assetCleanUpIsRestCall` in early-triggers.php) so its Settings class is
 * never present when Minn's routes run. Detection uses is_plugin_active, and
 * reads/writes go through the option JSON directly (same shape SettingsAdmin
 * writes). Never gate on class_exists for this plugin under REST.
 *
 * Family: `performance` (shared nav with Perfmatters, Autoptimize, Performance Lab).
 */

defined( 'ABSPATH' ) || exit;

/**
 * True when Asset CleanUp (Lite or Pro) is an active plugin.
 *
 * Prefer the active_plugins option (always available under REST). Do not use
 * class_exists: Lite returns early on REST and never loads its classes.
 *
 * @return bool
 */
function minn_admin_asset_cleanup_active() {
	$files = array(
		'wp-asset-clean-up/wpacu.php',
		'wp-asset-clean-up-pro/wpacu.php',
	);
	$active = (array) get_option( 'active_plugins', array() );
	foreach ( $files as $file ) {
		if ( in_array( $file, $active, true ) ) {
			return true;
		}
	}
	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		foreach ( $files as $file ) {
			if ( isset( $network[ $file ] ) ) {
				return true;
			}
		}
	}
	// Fallback when the option list is filtered mid-request.
	if ( function_exists( 'is_plugin_active' ) ) {
		foreach ( $files as $file ) {
			if ( is_plugin_active( $file ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Option key (same plugin ID as Lite/Pro).
 *
 * @return string
 */
function minn_admin_asset_cleanup_option_key() {
	return 'wpassetcleanup_settings';
}

/**
 * Curated daily settings. Full estate stays on their Settings screen.
 *
 * @return array tab_id => { label, groups: [ { title, fields[] } ] }
 */
function minn_admin_asset_cleanup_schema() {
	return array(
		'optimize' => array(
			'label'  => __( 'Optimize', 'minn-admin' ),
			'groups' => array(
				array(
					'title'  => 'CSS',
					'fields' => array(
						array( 'key' => 'minify_loaded_css', 'label' => __( 'Minify loaded CSS', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'combine_loaded_css', 'label' => __( 'Combine loaded CSS', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'inline_css_files', 'label' => __( 'Inline CSS files', 'minn-admin' ), 'type' => 'toggle' ),
						array(
							'key'   => 'minify_loaded_css_exceptions',
							'label' => __( 'Minify CSS exceptions', 'minn-admin' ),
							'type'  => 'textarea',
							'help'  => 'One path/handle fragment per line.',
						),
					),
				),
				array(
					'title'  => __( 'JavaScript', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'minify_loaded_js', 'label' => __( 'Minify loaded JavaScript', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'combine_loaded_js', 'label' => __( 'Combine loaded JavaScript', 'minn-admin' ), 'type' => 'toggle' ),
						array(
							'key'   => 'minify_loaded_js_exceptions',
							'label' => __( 'Minify JS exceptions', 'minn-admin' ),
							'type'  => 'textarea',
							'help'  => 'One path/handle fragment per line.',
						),
					),
				),
			),
		),
		'cleanup'  => array(
			'label'  => __( 'Cleanup', 'minn-admin' ),
			'groups' => array(
				array(
					'title'  => __( 'Site-wide cleanup', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'disable_emojis', 'label' => __( 'Disable emojis', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'disable_oembed', 'label' => __( 'Disable oEmbed', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'remove_wp_version', 'label' => __( 'Remove WordPress version meta', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'remove_generator_tag', 'label' => __( 'Remove generator meta tags', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'remove_rsd_link', 'label' => __( 'Remove RSD link', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'remove_wlw_link', 'label' => __( 'Remove Windows Live Writer link', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'remove_shortlink', 'label' => __( 'Remove shortlink', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'remove_rest_api_link', 'label' => __( 'Remove REST API link', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'disable_xmlrpc', 'label' => __( 'Disable XML-RPC', 'minn-admin' ), 'type' => 'toggle' ),
					),
				),
			),
		),
		'fonts'    => array(
			'label'  => __( 'Fonts', 'minn-admin' ),
			'groups' => array(
				array(
					'title'  => __( 'Google Fonts', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'google_fonts_remove', 'label' => __( 'Remove Google Fonts', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'google_fonts_combine', 'label' => __( 'Combine Google Fonts requests', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'google_fonts_preconnect', 'label' => __( 'Preconnect to fonts.gstatic.com', 'minn-admin' ), 'type' => 'toggle' ),
					),
				),
			),
		),
		'misc'     => array(
			'label'  => __( 'Misc', 'minn-admin' ),
			'groups' => array(
				array(
					'title'  => __( 'Plugin behaviour', 'minn-admin' ),
					'fields' => array(
						array(
							'key'   => 'test_mode',
							'label' => __( 'Test mode', 'minn-admin' ),
							'type'  => 'toggle',
							'help'  => 'Only admins see optimizations; guests get an unoptimized view.',
						),
						array( 'key' => 'dashboard_show', 'label' => __( 'Manage assets in the Dashboard', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'frontend_show', 'label' => __( 'Manage assets on the front-end', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'hide_from_admin_bar', 'label' => __( 'Hide from admin bar', 'minn-admin' ), 'type' => 'toggle' ),
					),
				),
			),
		),
	);
}

/**
 * One tab as offered, every tab (null) for a save from PHP, or null for a tab
 * that does not exist.
 *
 * @param string|null $tab_id Tab id.
 * @return array|null { groups, adminUrl, closed }
 */
function minn_admin_asset_cleanup_layout( $tab_id ) {
	$schema = minn_admin_asset_cleanup_schema();
	if ( null !== $tab_id && ! isset( $schema[ $tab_id ] ) ) {
		return null;
	}
	$groups = array();
	// While Asset CleanUp has no stored settings (never saved, or saved with
	// every value at its default, which deletes the row) a save could only
	// start from nothing and switch every default-on setting off. Until one
	// differs every field is locked, with the reason and the way to its
	// Settings page, and a save answers that reason.
	$unknown = null === minn_admin_asset_cleanup_stored_settings();
	$why     = __( 'Asset CleanUp stores no settings while every one is at its default, and Minn cannot tell its defaults from your choices. Change one on its Settings page, then edit them here.', 'minn-admin' );
	foreach ( null === $tab_id ? array_merge( ...array_values( wp_list_pluck( $schema, 'groups' ) ) ) : $schema[ $tab_id ]['groups'] as $group ) {
		if ( $unknown ) {
			$groups[] = array(
				'title'  => $group['title'],
				'desc'   => $groups ? '' : $why,
				'fields' => array(),
				'locked' => count( $group['fields'] ),
			);
			continue;
		}
		foreach ( $group['fields'] as $i => $f ) {
			$group['fields'][ $i ] = $f + ( 'textarea' === $f['type'] ? array( 'rows' => 4, 'mono' => true ) : array() );
		}
		$groups[] = $group + array( 'locked' => 0 );
	}
	return array(
		'groups'   => $groups,
		'adminUrl' => admin_url( 'admin.php?page=wpassetcleanup_settings' ),
		'closed'   => $unknown ? new WP_Error( 'minn_acu_unknown', $why, array( 'status' => 409 ) ) : null,
	);
}

/**
 * The settings as stored, before Asset CleanUp's runtime filtering: what a
 * write must start from. Their own getAllStored() when the class loads (it
 * also supplies the defaults when the row was never saved, which is common:
 * the row is not written on activation and is deleted when a save equals
 * the defaults); otherwise the raw JSON row. Null when neither can answer,
 * because a write that starts from nothing would switch every default-on
 * setting off.
 *
 * @return array|null
 */
function minn_admin_asset_cleanup_stored_settings() {
	wp_cache_delete( minn_admin_asset_cleanup_option_key(), 'options' );
	if ( class_exists( '\\WpAssetCleanUp\\Settings' ) ) {
		try {
			$s      = new \WpAssetCleanUp\Settings();
			$stored = $s->getAllStored( true );
			if ( is_array( $stored ) && $stored ) {
				return $stored;
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // fall back to the row
		}
	}
	$raw = get_option( minn_admin_asset_cleanup_option_key(), '' );
	if ( is_string( $raw ) && '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) && $decoded ? $decoded : null;
	}
	return is_array( $raw ) && $raw ? $raw : null;
}

/**
 * Save edited keys from PHP, over every tab, through the settings tier's
 * checks (the same path the tab's POST takes).
 *
 * @param array $values Sent values, by field key.
 * @return true|WP_Error
 */
function minn_admin_asset_cleanup_save( $values ) {
	return minn_admin_settings_save( 'asset-cleanup', array( 'values' => (array) $values ), array( 'tab' => null ) );
}

/**
 * Asset CleanUp answers this itself: a super admin always, otherwise their
 * configured access role or their own assetcleanup_manager capability.
 * manage_options appears nowhere in that answer.
 *
 * @return bool
 */
function minn_admin_asset_cleanup_can() {
	if ( class_exists( '\\WpAssetCleanUp\\Menu' )
		&& method_exists( '\\WpAssetCleanUp\\Menu', 'userCanAccessPlugin' ) ) {
		try {
			return (bool) \WpAssetCleanUp\Menu::userCanAccessPlugin();
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Fall through to the mirrored test below.
		}
	}
	return is_super_admin() || current_user_can( 'administrator' ) || current_user_can( 'assetcleanup_manager' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_asset_cleanup_active() || ! minn_admin_asset_cleanup_can() ) {
		return $surfaces;
	}
	$tabs = array();
	foreach ( minn_admin_asset_cleanup_schema() as $id => $tab ) {
		$tabs[] = array( 'id' => $id, 'label' => $tab['label'] );
	}
	$surfaces['asset-cleanup'] = array(
		'label'    => __( 'Performance', 'minn-admin' ),
		'sub'      => 'Asset CleanUp',
		'plugin'     => array( 'wp-asset-clean-up', 'wp-asset-clean-up-pro' ),
		'family'   => 'performance',
		'icon'     => 'gear',
		// Their answer is a resolver (a super admin, the administrator role, or
		// the capability their settings screen hands out), not a capability
		// name, and that capability sits on no role until someone grants it. So
		// naming it here hid this surface from the very people who own the site.
		'cap'      => 'read',
		'settings' => array(
			'label' => __( 'Settings', 'minn-admin' ),
			'tabs'  => $tabs,
			'route' => 'minn-admin/v1/asset-cleanup/settings/{tab}',
		),
	);
	return $surfaces;
} );


// The settings are one JSON row, so the screen reads and writes it itself:
// served from the stored copy a write starts from, and written whole.
minn_admin_settings( 'asset-cleanup', array(
	'route'  => 'asset-cleanup/settings/(?P<tab>[a-z]+)',
	'active' => 'minn_admin_asset_cleanup_active',
	'gate'   => 'minn_admin_asset_cleanup_can',
	'layout' => function ( $ctx ) {
		return minn_admin_asset_cleanup_layout( $ctx['tab'] );
	},
	'read'   => function ( $f, $ctx ) {
		if ( ! isset( $ctx['memo']['stored'] ) ) {
			$ctx['memo']['stored'] = (array) minn_admin_asset_cleanup_stored_settings();
		}
		$v = $ctx['memo']['stored'][ $f['key'] ] ?? '';
		if ( 'toggle' === $f['type'] ) {
			return Minn_Admin_Kernel_Settings::on( $v );
		}
		return is_array( $v ) ? implode( "\n", array_map( 'strval', $v ) ) : ( is_scalar( $v ) ? (string) $v : '' );
	},
	// Start from what is STORED, never from getAll(): that is the stored
	// array run through filterSettings(), which nulls the Google Fonts
	// delivery keys while removal is on, blanks combine_loaded_js under WP
	// Rocket's delay-JS and applies ?wpacu_test_mode overrides for this one
	// request. Their own Settings class warns that any complete-array writer
	// must start from the stored copy, and their updateOption() does. An
	// off is kept as '' for predictable round trips (getAll fills a missing
	// key as '' too).
	'write'  => function ( $changes, $fields ) {
		$settings = (array) minn_admin_asset_cleanup_stored_settings();
		foreach ( $changes as $key => $v ) {
			$settings[ $key ] = 'toggle' === $fields[ $key ]['type'] ? ( Minn_Admin_Kernel_Settings::on( $v ) ? '1' : '' ) : ( is_scalar( $v ) ? (string) $v : '' );
		}
		$json = wp_json_encode( $settings );
		if ( false === $json ) {
			return new WP_Error( 'minn_acu_encode', __( 'Could not encode settings.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		if ( class_exists( '\\WpAssetCleanUp\\Misc', false ) && method_exists( '\\WpAssetCleanUp\\Misc', 'addUpdateOption' ) ) {
			\WpAssetCleanUp\Misc::addUpdateOption( minn_admin_asset_cleanup_option_key(), $json );
		} else {
			update_option( minn_admin_asset_cleanup_option_key(), $json, false );
		}
		return true;
	},
) );
