<?php
/**
 * Bundled adapter: GenerateBlocks pattern library.
 *
 * GB serves its patterns from patterns.generatepress.com through the
 * plugin's OWN `generateblocks/v1` REST proxy (edit_posts-gated,
 * transient-cached, public key baked into the plugin — no account). The
 * listing already carries each pattern's FULL serialized markup with its
 * generated CSS stored in the blocks' `css` attributes, which Minn's
 * render-blocks already inlines via the generateblocks_do_inline_styles
 * filter — so previews arrive styled.
 *
 * Same adapter contract as Stackable/Kadence:
 * - GET  minn-admin/v1/generateblocks/designs       slim list
 * - POST minn-admin/v1/generateblocks/designs/{id}  insert-ready template
 *   (remote images sideloaded via the shared adapters/media-localize.php).
 *
 * Design ids are "{libraryId}--{patternId}" — every ENABLED library is
 * included (the free default plus any user-added connections, which carry
 * their own keys in GB's own settings).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_generateblocks_active() {
	return defined( 'GENERATEBLOCKS_VERSION' ) && class_exists( 'GenerateBlocks_Pattern_Library_Rest' );
}

// Register as a design source (docs/for-plugin-authors.md).
add_filter( 'minn_admin_design_sources', function ( $sources ) {
	if ( minn_admin_generateblocks_active() ) {
		$sources['generateblocks'] = array(
			'label' => 'GenerateBlocks',
			'route' => 'minn-admin/v1/generateblocks/designs',
		);
	}
	return $sources;
} );

/**
 * Enabled pattern libraries via GB's own proxy.
 *
 * @return array[] Each { id, domain, publicKey }.
 */
function minn_admin_gb_libraries() {
	$res = rest_do_request( new WP_REST_Request( 'GET', '/generateblocks/v1/pattern-library/libraries' ) );
	// GB returns library OBJECTS with protected props that only flatten via
	// JsonSerializable — normalize through a JSON round-trip.
	$data = $res->is_error() ? array() : json_decode( wp_json_encode( $res->get_data() ), true );
	$out  = array();
	foreach ( (array) ( $data['data'] ?? array() ) as $lib ) {
		$lib = (array) $lib;
		if ( ! empty( $lib['isEnabled'] ) && ! empty( $lib['id'] ) && ! empty( $lib['domain'] ) ) {
			$out[] = $lib;
		}
	}
	return $out;
}

/**
 * Patterns for one library (GB's transient cache applies). Returns raw
 * entries: { id, label, pattern, categories: int[] }.
 *
 * @param array $lib Library descriptor.
 * @return array{patterns: array, categories: array}
 */
function minn_admin_gb_patterns( $lib ) {
	$args = array(
		'libraryId'  => $lib['id'],
		'libraryUrl' => $lib['domain'],
		'publicKey'  => (string) ( $lib['publicKey'] ?? '' ),
	);
	$fetch = function ( $route ) use ( $args ) {
		$req = new WP_REST_Request( 'GET', '/generateblocks/v1/pattern-library/' . $route );
		$req->set_query_params( $args );
		$res = rest_do_request( $req );
		if ( $res->is_error() ) {
			return array();
		}
		$data = json_decode( wp_json_encode( $res->get_data() ), true );
		return (array) ( $data['response']['data'] ?? array() );
	};
	return array(
		'patterns'   => $fetch( 'patterns' ),
		'categories' => $fetch( 'categories' ),
	);
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_generateblocks_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/generateblocks/designs', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function () {
			$out = array();
			foreach ( minn_admin_gb_libraries() as $lib ) {
				$set  = minn_admin_gb_patterns( $lib );
				$cats = array();
				foreach ( $set['categories'] as $c ) {
					$c = (array) $c;
					if ( isset( $c['id'] ) ) {
						$cats[ $c['id'] ] = (string) ( $c['name'] ?? '' );
					}
				}
				foreach ( $set['patterns'] as $p ) {
					$p = (array) $p;
					if ( empty( $p['id'] ) || empty( $p['pattern'] ) || false === strpos( $p['pattern'], '<!-- wp:' ) ) {
						continue;
					}
					$cat_ids = (array) ( $p['categories'] ?? array() );
					$out[]   = array(
						'id'       => $lib['id'] . '--' . $p['id'],
						'label'    => (string) ( $p['label'] ?? $p['id'] ),
						'category' => (string) ( $cats[ $cat_ids[0] ?? 0 ] ?? '' ),
					);
				}
			}
			usort( $out, function ( $a, $b ) {
				return strcasecmp( $a['category'] . ' ' . $a['label'], $b['category'] . ' ' . $b['label'] );
			} );
			return array( 'designs' => $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/generateblocks/designs/(?P<id>[^/]+)', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function ( $request ) {
			$parts      = explode( '--', (string) $request['id'], 2 );
			$library_id = $parts[0];
			$pattern_id = $parts[1] ?? '';
			$template   = '';
			foreach ( minn_admin_gb_libraries() as $lib ) {
				if ( $lib['id'] !== $library_id ) {
					continue;
				}
				$set = minn_admin_gb_patterns( $lib );
				foreach ( $set['patterns'] as $p ) {
					$p = (array) $p;
					if ( (string) ( $p['id'] ?? '' ) === $pattern_id && ! empty( $p['pattern'] ) ) {
						$template = (string) $p['pattern'];
						break 2;
					}
				}
			}
			if ( '' === trim( $template ) || false === strpos( $template, '<!-- wp:' ) ) {
				return new WP_Error( 'minn_design_not_found', __( 'Design not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}

			$localized = minn_admin_localize_images( $template );

			$block = 'generateblocks/element';
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
