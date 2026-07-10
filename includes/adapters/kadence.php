<?php
/**
 * Bundled adapter: Kadence Blocks design library (sections).
 *
 * Kadence serves its prebuilt sections from its cloud through the plugin's
 * OWN `kb-design-library/v1` REST proxy (edit_posts-gated, file-cached in
 * uploads by the plugin). The free tier is served with an empty api_key, so
 * no license is needed for the 350+ free sections. Minn drives that proxy
 * internally via rest_do_request — respecting Kadence's caching — and
 * reshapes the results to the same contract as the Stackable adapter:
 *
 * - GET  minn-admin/v1/kadence/designs        slim free list
 *   { designs: [ { id, label, category } ] } for the slash menu.
 * - POST minn-admin/v1/kadence/designs/{id}   insert-ready template
 *   { template, block, attachments } — remote images sideloaded and swapped
 *   (shared helper, adapters/media-localize.php).
 *
 * Content is full serialized wp:kadence/* markup produced by Kadence's own
 * save() — valid in Gutenberg by construction, same trust level as the
 * plugin's own inserter.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_kadence_active() {
	return defined( 'KADENCE_BLOCKS_VERSION' );
}

// Register as a design source (docs/for-plugin-authors.md).
add_filter( 'minn_admin_design_sources', function ( $sources ) {
	if ( minn_admin_kadence_active() ) {
		$sources['kadence'] = array(
			'label' => 'Kadence',
			'route' => 'minn-admin/v1/kadence/designs',
		);
	}
	return $sources;
} );

/**
 * The section library via Kadence's own proxy (its file cache applies).
 *
 * @return array Raw listing keyed like ptn-19198 => entry.
 */
function minn_admin_kadence_library() {
	$req = new WP_REST_Request( 'GET', '/kb-design-library/v1/get_library' );
	$req->set_query_params( array( 'key' => 'section' ) );
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return array();
	}
	$data = $res->get_data();
	if ( is_string( $data ) ) {
		$data = json_decode( $data, true );
	}
	return is_array( $data ) ? $data : array();
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_kadence_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/kadence/designs', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function () {
			$out = array();
			foreach ( minn_admin_kadence_library() as $design ) {
				if ( ! is_array( $design ) || ! empty( $design['pro'] ) || ! empty( $design['locked'] ) || empty( $design['id'] ) ) {
					continue;
				}
				$cats = is_array( $design['categories'] ?? null ) ? array_values( $design['categories'] ) : array();
				$out[] = array(
					'id'       => (string) $design['id'],
					'label'    => (string) ( $design['name'] ?? $design['id'] ),
					'category' => (string) ( $cats[0] ?? '' ),
				);
			}
			usort( $out, function ( $a, $b ) {
				return strcasecmp( $a['category'] . ' ' . $a['label'], $b['category'] . ' ' . $b['label'] );
			} );
			return array( 'designs' => $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/kadence/designs/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$req = new WP_REST_Request( 'GET', '/kb-design-library/v1/get_pattern_content' );
			$req->set_query_params( array(
				'library'      => 'section',
				'key'          => 'section',
				'pattern_id'   => (string) $request['id'],
				'pattern_type' => 'pattern',
			) );
			$res  = rest_do_request( $req );
			$body = $res->get_data();
			if ( is_string( $body ) ) {
				$body = json_decode( $body, true );
			}
			$template = is_array( $body ) ? (string) ( $body['content'] ?? '' ) : '';
			if ( $res->is_error() || '' === trim( $template ) || false === strpos( $template, '<!-- wp:' ) ) {
				return new WP_Error( 'minn_design_not_found', __( 'Design not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}

			$localized = minn_admin_localize_images( $template );

			$block = 'kadence/rowlayout';
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
