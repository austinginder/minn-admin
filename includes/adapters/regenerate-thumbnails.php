<?php
/**
 * Regenerate Thumbnails (Alex Mills, 1M+) — a Regenerate button on the media
 * detail modal.
 *
 * The plugin's RegenerateThumbnails_Regenerator does the pixel work exactly as
 * its own Tools screen would; Minn only adds the per-image entry point. Gated
 * on the plugin's own capability property (filterable via regenerate_thumbs_cap,
 * default manage_options) so a site that loosened or tightened it is honored.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current user can regenerate thumbnails (drives the boot flag
 * and the endpoint permission).
 */
function minn_admin_regen_thumbs_available() {
	return function_exists( 'RegenerateThumbnails' )
		&& class_exists( 'RegenerateThumbnails_Regenerator' )
		&& current_user_can( RegenerateThumbnails()->capability );
}

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'RegenerateThumbnails' ) ) {
		return;
	}
	register_rest_route(
		'minn-admin/v1',
		'/media/(?P<id>\d+)/regenerate',
		array(
			'methods'             => 'POST',
			'permission_callback' => 'minn_admin_regen_thumbs_available',
			'callback'            => function ( $req ) {
				$regenerator = RegenerateThumbnails_Regenerator::get_instance( (int) $req['id'] );
				if ( is_wp_error( $regenerator ) ) {
					$regenerator->add_data( array( 'status' => 400 ) );
					return $regenerator;
				}
				// Full regenerate, not missing-only: the button exists for
				// "the theme's registered sizes changed" where thumbnail
				// files already exist at the old dimensions.
				try {
					$metadata = $regenerator->regenerate( array( 'only_regenerate_missing_thumbnails' => false ) );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'minn_regen_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
				if ( is_wp_error( $metadata ) ) {
					$metadata->add_data( array( 'status' => 400 ) );
					return $metadata;
				}
				return rest_ensure_response( array(
					'ok'    => true,
					'sizes' => isset( $metadata['sizes'] ) ? count( (array) $metadata['sizes'] ) : 0,
				) );
			},
		)
	);
} );
