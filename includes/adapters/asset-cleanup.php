<?php
/**
 * Asset CleanUp adapter — global performance settings as a Minn surface.
 *
 * The page-level CSS/JS unload manager is a canvas (DOM fetch of every asset
 * on a URL); Minn links out for that. Daily global toggles live in the JSON
 * option `wpassetcleanup_settings`.
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
			'label'  => 'Optimize',
			'groups' => array(
				array(
					'title'  => 'CSS',
					'fields' => array(
						array( 'key' => 'minify_loaded_css', 'label' => 'Minify loaded CSS', 'type' => 'toggle' ),
						array( 'key' => 'combine_loaded_css', 'label' => 'Combine loaded CSS', 'type' => 'toggle' ),
						array( 'key' => 'inline_css_files', 'label' => 'Inline CSS files', 'type' => 'toggle' ),
						array(
							'key'   => 'minify_loaded_css_exceptions',
							'label' => 'Minify CSS exceptions',
							'type'  => 'textarea',
							'help'  => 'One path/handle fragment per line.',
						),
					),
				),
				array(
					'title'  => 'JavaScript',
					'fields' => array(
						array( 'key' => 'minify_loaded_js', 'label' => 'Minify loaded JavaScript', 'type' => 'toggle' ),
						array( 'key' => 'combine_loaded_js', 'label' => 'Combine loaded JavaScript', 'type' => 'toggle' ),
						array(
							'key'   => 'minify_loaded_js_exceptions',
							'label' => 'Minify JS exceptions',
							'type'  => 'textarea',
							'help'  => 'One path/handle fragment per line.',
						),
					),
				),
			),
		),
		'cleanup'  => array(
			'label'  => 'Cleanup',
			'groups' => array(
				array(
					'title'  => 'Site-wide cleanup',
					'fields' => array(
						array( 'key' => 'disable_emojis', 'label' => 'Disable emojis', 'type' => 'toggle' ),
						array( 'key' => 'disable_oembed', 'label' => 'Disable oEmbed', 'type' => 'toggle' ),
						array( 'key' => 'remove_wp_version', 'label' => 'Remove WordPress version meta', 'type' => 'toggle' ),
						array( 'key' => 'remove_generator_tag', 'label' => 'Remove generator meta tags', 'type' => 'toggle' ),
						array( 'key' => 'remove_rsd_link', 'label' => 'Remove RSD link', 'type' => 'toggle' ),
						array( 'key' => 'remove_wlw_link', 'label' => 'Remove Windows Live Writer link', 'type' => 'toggle' ),
						array( 'key' => 'remove_shortlink', 'label' => 'Remove shortlink', 'type' => 'toggle' ),
						array( 'key' => 'remove_rest_api_link', 'label' => 'Remove REST API link', 'type' => 'toggle' ),
						array( 'key' => 'disable_xmlrpc', 'label' => 'Disable XML-RPC', 'type' => 'toggle' ),
					),
				),
			),
		),
		'fonts'    => array(
			'label'  => 'Fonts',
			'groups' => array(
				array(
					'title'  => 'Google Fonts',
					'fields' => array(
						array( 'key' => 'google_fonts_remove', 'label' => 'Remove Google Fonts', 'type' => 'toggle' ),
						array( 'key' => 'google_fonts_combine', 'label' => 'Combine Google Fonts requests', 'type' => 'toggle' ),
						array( 'key' => 'google_fonts_preconnect', 'label' => 'Preconnect to fonts.gstatic.com', 'type' => 'toggle' ),
					),
				),
			),
		),
		'misc'     => array(
			'label'  => 'Misc',
			'groups' => array(
				array(
					'title'  => 'Plugin behaviour',
					'fields' => array(
						array(
							'key'   => 'test_mode',
							'label' => 'Test mode',
							'type'  => 'toggle',
							'help'  => 'Only admins see optimizations; guests get an unoptimized view.',
						),
						array( 'key' => 'dashboard_show', 'label' => 'Manage assets in the Dashboard', 'type' => 'toggle' ),
						array( 'key' => 'frontend_show', 'label' => 'Manage assets on the front-end', 'type' => 'toggle' ),
						array( 'key' => 'hide_from_admin_bar', 'label' => 'Hide from admin bar', 'type' => 'toggle' ),
					),
				),
			),
		),
	);
}

/**
 * @return array key => field
 */
function minn_admin_asset_cleanup_fields_by_key() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	foreach ( minn_admin_asset_cleanup_schema() as $tab ) {
		foreach ( $tab['groups'] as $group ) {
			foreach ( $group['fields'] as $f ) {
				$map[ $f['key'] ] = $f;
			}
		}
	}
	return $map;
}

/**
 * Live settings from the JSON option (works when their classes no-load on REST).
 *
 * @return array
 */
function minn_admin_asset_cleanup_settings() {
	// Prefer their class when available (admin pageload).
	if ( class_exists( '\\WpAssetCleanUp\\Settings', false ) ) {
		try {
			$s     = new \WpAssetCleanUp\Settings();
			$force = isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] )
				&& method_exists( $GLOBALS['wp_object_cache'], 'delete' );
			return (array) $s->getAll( $force );
		} catch ( \Throwable $e ) {
			// fall through to option read
		}
	}
	$raw = get_option( minn_admin_asset_cleanup_option_key(), '' );
	if ( is_string( $raw ) && $raw !== '' ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
	}
	if ( is_array( $raw ) ) {
		return $raw;
	}
	return array();
}

/**
 * @param array $field    Field def.
 * @param array $settings Live settings.
 * @return mixed
 */
function minn_admin_asset_cleanup_value( $field, $settings ) {
	$v = isset( $settings[ $field['key'] ] ) ? $settings[ $field['key'] ] : '';
	if ( 'toggle' === $field['type'] ) {
		return ! empty( $v ) && '0' !== (string) $v && 'false' !== (string) $v;
	}
	if ( is_array( $v ) ) {
		return implode( "\n", array_map( 'strval', $v ) );
	}
	return is_scalar( $v ) ? (string) $v : '';
}

/**
 * @param string $tab_id Tab.
 * @return array|WP_Error
 */
function minn_admin_asset_cleanup_tab_shape( $tab_id ) {
	$schema = minn_admin_asset_cleanup_schema();
	if ( ! isset( $schema[ $tab_id ] ) ) {
		return new WP_Error( 'minn_no_tab', 'Unknown settings tab.', array( 'status' => 404 ) );
	}
	$settings = minn_admin_asset_cleanup_settings();
	$tab      = $schema[ $tab_id ];
	$groups   = array();
	$values   = array();
	foreach ( $tab['groups'] as $group ) {
		$fields = array();
		foreach ( $group['fields'] as $f ) {
			$out = array(
				'key'   => $f['key'],
				'label' => $f['label'],
				'type'  => $f['type'],
			);
			if ( ! empty( $f['help'] ) ) {
				$out['help'] = $f['help'];
			}
			if ( 'textarea' === $f['type'] ) {
				$out['rows'] = 4;
				$out['mono'] = true;
			}
			$fields[]            = $out;
			$values[ $f['key'] ] = minn_admin_asset_cleanup_value( $f, $settings );
		}
		$groups[] = array(
			'title'  => $group['title'],
			'fields' => $fields,
			'locked' => 0,
		);
	}
	return array(
		'groups'   => $groups,
		'values'   => $values,
		'adminUrl' => admin_url( 'admin.php?page=wpassetcleanup_settings' ),
	);
}

/**
 * Merge edited keys and write the JSON option.
 *
 * @param array $values Client values.
 * @return true|WP_Error
 */
function minn_admin_asset_cleanup_save( $values ) {
	$by_key   = minn_admin_asset_cleanup_fields_by_key();
	$settings = minn_admin_asset_cleanup_settings();
	$changed  = false;
	foreach ( (array) $values as $key => $v ) {
		if ( ! isset( $by_key[ $key ] ) ) {
			continue;
		}
		$type = $by_key[ $key ]['type'];
		if ( 'toggle' === $type ) {
			$on               = ! empty( $v ) && 'false' !== $v && '0' !== (string) $v;
			$settings[ $key ] = $on ? '1' : '';
		} else {
			$settings[ $key ] = is_scalar( $v ) ? (string) $v : '';
		}
		$changed = true;
	}
	if ( ! $changed ) {
		return true;
	}
	// Mirror SettingsAdmin::update: drop empty strings from the blob so
	// defaults apply for never-set keys, but keep our explicit offs as ''.
	// Their form stores off as absent; we keep the key as '' for predictable
	// round-trips of the fields we map (getAll fills missing keys as '').
	$json = wp_json_encode( $settings );
	if ( false === $json ) {
		return new WP_Error( 'minn_acu_encode', 'Could not encode settings.', array( 'status' => 500 ) );
	}
	if ( class_exists( '\\WpAssetCleanUp\\Misc', false ) && method_exists( '\\WpAssetCleanUp\\Misc', 'addUpdateOption' ) ) {
		\WpAssetCleanUp\Misc::addUpdateOption( minn_admin_asset_cleanup_option_key(), $json );
	} else {
		update_option( minn_admin_asset_cleanup_option_key(), $json, false );
	}
	return true;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_asset_cleanup_active() ) {
		return $surfaces;
	}
	$tabs = array();
	foreach ( minn_admin_asset_cleanup_schema() as $id => $tab ) {
		$tabs[] = array( 'id' => $id, 'label' => $tab['label'] );
	}
	$surfaces['asset-cleanup'] = array(
		'label'    => 'Performance',
		'sub'      => 'Asset CleanUp',
		'family'   => 'performance',
		'icon'     => 'gear',
		'cap'      => 'manage_options',
		'settings' => array(
			'label' => 'Settings',
			'tabs'  => $tabs,
			'route' => 'minn-admin/v1/asset-cleanup/settings/{tab}',
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_asset_cleanup_active() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/asset-cleanup/settings/(?P<tab>[a-z]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( $req ) {
				return rest_ensure_response( minn_admin_asset_cleanup_tab_shape( $req['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( $req ) {
				$body   = $req->get_json_params();
				$values = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
				$saved  = minn_admin_asset_cleanup_save( $values );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				return rest_ensure_response( minn_admin_asset_cleanup_tab_shape( $req['tab'] ) );
			},
		),
	) );
} );
