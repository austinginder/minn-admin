<?php
/**
 * Performance Lab adapter — WordPress Performance Team feature hub.
 *
 * Performance Lab is not a settings form: it installs and activates
 * standalone performance plugins (speculation-rules, webp-uploads, …).
 * Minn lists those features, shows active/installed/available state from
 * local plugin data (no API on the list path), and activates through
 * `perflab_install_and_activate_plugin()` so dependencies and caps stay
 * theirs. Deactivate uses core `deactivate_plugins`. Per-feature settings
 * screens and the Server Timing panel stay deep-linked.
 *
 * Family: `performance`.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return bool
 */
function minn_admin_performance_lab_active() {
	return defined( 'PERFLAB_VERSION' ) && function_exists( 'perflab_get_standalone_plugin_data' );
}

/**
 * Fallback titles when a feature plugin is not installed yet (no network call).
 *
 * @return array slug => title
 */
function minn_admin_performance_lab_titles() {
	return array(
		'auto-sizes'              => 'Auto-sizes for Lazy-loaded Images',
		'dominant-color-images'   => 'Image Placeholders',
		'embed-optimizer'         => 'Embed Optimizer',
		'image-prioritizer'       => 'Image Prioritizer',
		'performant-translations' => 'Performant Translations',
		'nocache-bfcache'         => 'Instant Back/Forward Cache',
		'speculation-rules'       => 'Speculative Loading',
		'view-transitions'        => 'View Transitions',
		'webp-uploads'            => 'Modern Image Formats',
	);
}

/**
 * Main plugin file for a standalone feature slug, or empty when not installed.
 *
 * @param string $slug Plugin directory slug.
 * @return string
 */
function minn_admin_performance_lab_file( $slug ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugins = get_plugins( '/' . $slug );
	if ( ! is_array( $plugins ) || ! $plugins ) {
		return '';
	}
	$names = array_keys( $plugins );
	return $slug . '/' . $names[0];
}

/**
 * One feature row for the collection.
 *
 * @param string $slug Plugin slug.
 * @param array  $meta { constant, experimental? } from Performance Lab.
 * @return array
 */
function minn_admin_performance_lab_item( $slug, $meta ) {
	$titles = minn_admin_performance_lab_titles();
	$file   = minn_admin_performance_lab_file( $slug );
	$name   = isset( $titles[ $slug ] ) ? $titles[ $slug ] : ucwords( str_replace( '-', ' ', $slug ) );

	if ( $file && function_exists( 'get_plugin_data' ) ) {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( is_readable( $path ) ) {
			$data = get_plugin_data( $path, false, false );
			if ( ! empty( $data['Name'] ) ) {
				$name = wp_strip_all_tags( $data['Name'] );
			}
		}
	}

	// Active = their version constant is defined (same signal the generator uses).
	$constant = isset( $meta['constant'] ) ? $meta['constant'] : '';
	$active   = $constant && defined( $constant );
	// Edge case: constant defined but plugin inactive mid-request — prefer is_plugin_active.
	if ( $file && function_exists( 'is_plugin_active' ) ) {
		$active = is_plugin_active( $file );
	}

	$status = $active ? 'active' : ( $file ? 'inactive' : 'available' );

	$item = array(
		'id'             => $slug,
		'name'           => $name,
		'slug'           => $slug,
		'status'         => $status,
		'status_label'   => 'active' === $status ? 'Active' : ( 'inactive' === $status ? 'Installed' : 'Available' ),
		// when-gates only support equals (docs/for-plugin-authors.md).
		'can_activate'   => $active ? '0' : '1',
		'can_deactivate' => $active ? '1' : '0',
		'experimental'   => ! empty( $meta['experimental'] ) ? '1' : '0',
		'settings_url'   => '',
		'has_settings'   => '0',
	);
	if ( function_exists( 'perflab_get_plugin_settings_url' ) ) {
		// Helper lives in admin/load.php — load when missing under REST.
		if ( ! function_exists( 'perflab_get_plugin_settings_url' ) && defined( 'PERFLAB_PLUGIN_DIR_PATH' ) ) {
			// no-op: already checked
		}
		$url = perflab_get_plugin_settings_url( $slug );
		if ( $url ) {
			$item['settings_url'] = $url;
			$item['has_settings'] = '1';
		}
	}
	return $item;
}

/**
 * All feature rows, stable order from Performance Lab's own map.
 *
 * @return array{items: array, total: int}
 */
function minn_admin_performance_lab_list() {
	$data  = perflab_get_standalone_plugin_data();
	$items = array();
	foreach ( $data as $slug => $meta ) {
		$items[] = minn_admin_performance_lab_item( $slug, (array) $meta );
	}
	return array(
		'items' => $items,
		'total' => count( $items ),
	);
}

/**
 * Status card above the list.
 *
 * @return array
 */
function minn_admin_performance_lab_status() {
	$list   = minn_admin_performance_lab_list();
	$active = 0;
	foreach ( $list['items'] as $it ) {
		if ( ( $it['status'] ?? '' ) === 'active' ) {
			$active++;
		}
	}
	$total = (int) $list['total'];
	return array(
		'rows'    => array(
			array(
				'label' => 'Active features',
				'value' => $active . ' of ' . $total,
				'hint'  => 'Standalone plugins managed by Performance Lab',
			),
			array(
				'label' => 'Performance Lab',
				'value' => defined( 'PERFLAB_VERSION' ) ? 'v' . PERFLAB_VERSION : '—',
			),
		),
		'actions' => array(
			array(
				'label' => 'Open Performance features ↗',
				'href'  => admin_url( 'options-general.php?page=' . ( defined( 'PERFLAB_SCREEN' ) ? PERFLAB_SCREEN : 'performance-lab' ) ),
			),
		),
	);
}

/**
 * Install + activate via Performance Lab's own helper (and its deps).
 *
 * @param string $slug Feature slug.
 * @return true|WP_Error
 */
function minn_admin_performance_lab_activate( $slug ) {
	$data = perflab_get_standalone_plugin_data();
	if ( ! isset( $data[ $slug ] ) ) {
		return new WP_Error( 'minn_unknown_feature', 'Unknown Performance Lab feature.', array( 'status' => 404 ) );
	}
	if ( ! function_exists( 'perflab_install_and_activate_plugin' ) ) {
		// Their helper lives in admin/plugins.php; load it for REST.
		if ( defined( 'PERFLAB_PLUGIN_DIR_PATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
			if ( is_readable( PERFLAB_PLUGIN_DIR_PATH . 'includes/admin/plugins.php' ) ) {
				require_once PERFLAB_PLUGIN_DIR_PATH . 'includes/admin/load.php';
				require_once PERFLAB_PLUGIN_DIR_PATH . 'includes/admin/plugins.php';
			}
		}
	}
	if ( ! function_exists( 'perflab_install_and_activate_plugin' ) ) {
		return new WP_Error( 'minn_no_activate', 'Performance Lab activate helper is not available.', array( 'status' => 500 ) );
	}
	$result = perflab_install_and_activate_plugin( $slug );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return true;
}

/**
 * Deactivate a standalone feature that is already installed.
 *
 * @param string $slug Feature slug.
 * @return true|WP_Error
 */
function minn_admin_performance_lab_deactivate( $slug ) {
	$data = perflab_get_standalone_plugin_data();
	if ( ! isset( $data[ $slug ] ) ) {
		return new WP_Error( 'minn_unknown_feature', 'Unknown Performance Lab feature.', array( 'status' => 404 ) );
	}
	$file = minn_admin_performance_lab_file( $slug );
	if ( ! $file ) {
		return new WP_Error( 'minn_not_installed', 'That feature is not installed.', array( 'status' => 400 ) );
	}
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! current_user_can( 'activate_plugin', $file ) ) {
		return new WP_Error( 'minn_cannot_deactivate', 'You cannot deactivate this plugin.', array( 'status' => 403 ) );
	}
	deactivate_plugins( $file, false, is_multisite() && is_plugin_active_for_network( $file ) );
	return true;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_performance_lab_active() ) {
		return $surfaces;
	}
	$surfaces['performance-lab'] = array(
		'label'      => 'Performance',
		'sub'        => 'Performance Lab',
		'family'     => 'performance',
		'icon'       => 'gear',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/performance-lab/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/performance-lab/features',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => false,
			'columns'   => array(
				// format title = first-column weight (no undocumented "primary" key).
				array( 'key' => 'name', 'label' => 'Feature', 'format' => 'title' ),
				array( 'key' => 'status_label', 'label' => 'Status', 'format' => 'pill', 'width' => '120px' ),
				array( 'key' => 'slug', 'label' => 'Slug', 'width' => '180px' ),
			),
			'actions'   => array(
				array(
					'label'  => 'Activate',
					'route'  => 'minn-admin/v1/performance-lab/features/{id}/activate',
					'method' => 'POST',
					'when'   => array( 'key' => 'can_activate', 'equals' => '1' ),
				),
				array(
					'label'   => 'Deactivate',
					'route'   => 'minn-admin/v1/performance-lab/features/{id}/deactivate',
					'method'  => 'POST',
					'confirm' => 'Deactivate this Performance Lab feature?',
					'when'    => array( 'key' => 'can_deactivate', 'equals' => '1' ),
				),
				array(
					'label' => 'Settings ↗',
					'href'  => '{settings_url}',
					'when'  => array( 'key' => 'has_settings', 'equals' => '1' ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_performance_lab_active() ) {
		return;
	}
	$can = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'minn-admin/v1', '/performance-lab/status', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_performance_lab_status() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/performance-lab/features', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_performance_lab_list() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/performance-lab/features/(?P<id>[a-z0-9_-]+)/activate', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( $req ) {
			$r = minn_admin_performance_lab_activate( $req['id'] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			return rest_ensure_response( array( 'ok' => true, 'item' => minn_admin_performance_lab_item( $req['id'], (array) ( perflab_get_standalone_plugin_data()[ $req['id'] ] ?? array() ) ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/performance-lab/features/(?P<id>[a-z0-9_-]+)/deactivate', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => function ( $req ) {
			$r = minn_admin_performance_lab_deactivate( $req['id'] );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			return rest_ensure_response( array( 'ok' => true, 'item' => minn_admin_performance_lab_item( $req['id'], (array) ( perflab_get_standalone_plugin_data()[ $req['id'] ] ?? array() ) ) ) );
		},
	) );
} );
