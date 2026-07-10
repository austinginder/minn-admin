<?php
/**
 * Bundled adapter: Stackable design library.
 *
 * Stackable's blocks are static-save (their markup only exists in the editor
 * JS), so Minn can't synthesize them — but Stackable publishes its Design
 * Library as DATA: library.json on their CDN carries, per design, the full
 * serialized block markup its own save() produced. That markup is valid by
 * construction (verified: opens in Gutenberg with no invalid-content
 * warning), renders server-side via do_blocks(), and drops into Minn as one
 * island per design. No JS runtime, no per-block templates: PHP scrapes what
 * it needs on demand.
 *
 * Two routes:
 * - GET  minn-admin/v1/stackable/designs        slim free-tier list
 *   { designs: [ { id, label, category } ] } for the slash menu.
 * - POST minn-admin/v1/stackable/designs/{id}   insert-ready template
 *   { template, block, attachments } — CDN images are sideloaded into the
 *   media library (dedup by filename, mirroring Stackable's own
 *   design_library_image endpoint) and their URLs swapped before the markup
 *   ever reaches the editor. Sideload is skipped without upload_files; the
 *   template then keeps its CDN URLs, which still render.
 *
 * The library read shares Stackable's own transient
 * (stackable_get_design_library_v4, 7 days) so the two caches never fight.
 * Free-tier designs only: premium templates assume Stackable Premium blocks.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the adapter is live (Stackable active).
 *
 * @return bool
 */
function minn_admin_stackable_active() {
	return class_exists( 'Stackable_Design_Library' );
}

// Register as a design source (docs/for-plugin-authors.md — the same filter
// third-party block libraries use).
add_filter( 'minn_admin_design_sources', function ( $sources ) {
	if ( minn_admin_stackable_active() ) {
		$sources['stackable'] = array(
			'label' => 'Stackable',
			'route' => 'minn-admin/v1/stackable/designs',
		);
	}
	return $sources;
} );

/**
 * The design library, keyed by design id. Reads Stackable's transient first
 * and populates it with the exact shape Stackable_Design_Library caches
 * ($designs['v4'] = decoded library.json) when cold.
 *
 * @return array
 */
function minn_admin_stackable_library() {
	$designs = get_transient( 'stackable_get_design_library_v4' );

	if ( empty( $designs ) || ! is_array( $designs ) ) {
		$designs  = array();
		$content  = null;
		$response = wp_remote_get( trailingslashit( STACKABLE_DESIGN_LIBRARY_URL ) . 'library-v4/library.json' );
		if ( ! is_wp_error( $response ) ) {
			$content = json_decode( wp_remote_retrieve_body( $response ), true );
		}
		$designs['v4'] = $content;
		set_transient( 'stackable_get_design_library_v4', $designs, 7 * DAY_IN_SECONDS );
	}

	return isset( $designs['v4'] ) && is_array( $designs['v4'] ) ? $designs['v4'] : array();
}

/**
 * Sideload the CDN images a template references and swap their URLs.
 * Mirrors Stackable's design_library_image endpoint: an attachment whose
 * filename already matches is reused instead of re-downloaded.
 *
 * @param string $template Serialized block markup.
 * @return array { template: string, attachments: int[] }
 */
function minn_admin_stackable_localize_images( $template ) {
	// Shared sideloader (adapters/media-localize.php), scoped to Stackable's CDN.
	return minn_admin_localize_images( $template, '#https://stackable-files\.pages\.dev/[^\s"\'\\\\)]+\.(?:jpe?g|png|gif|webp|mp4)#i' );
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_stackable_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/stackable/designs', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function () {
			$out = array();
			foreach ( minn_admin_stackable_library() as $id => $design ) {
				if ( ! is_array( $design ) || 'free' !== ( $design['plan'] ?? '' ) || empty( $design['template'] ) ) {
					continue;
				}
				$out[] = array(
					'id'       => (string) $id,
					'label'    => (string) ( $design['label'] ?? $id ),
					'category' => (string) ( $design['category'] ?? '' ),
				);
			}
			usort( $out, function ( $a, $b ) {
				return strcasecmp( $a['category'] . ' ' . $a['label'], $b['category'] . ' ' . $b['label'] );
			} );
			return array( 'designs' => $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/stackable/designs/(?P<id>[\w-]+)', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$library = minn_admin_stackable_library();
			$id      = $request['id'];
			$design  = isset( $library[ $id ] ) && is_array( $library[ $id ] ) ? $library[ $id ] : null;
			if ( ! $design || 'free' !== ( $design['plan'] ?? '' ) || empty( $design['template'] ) ) {
				return new WP_Error( 'minn_design_not_found', __( 'Design not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}

			$localized = minn_admin_stackable_localize_images( (string) $design['template'] );

			// Root block name for the island chip.
			$block = 'stackable/columns';
			if ( preg_match( '/^<!--\s*wp:([a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*)/', trim( $localized['template'] ), $m ) ) {
				$block = $m[1];
			}

			return array(
				'template'    => $localized['template'],
				'block'       => $block,
				'attachments' => $localized['attachments'],
			);
		},
	) );
} );
