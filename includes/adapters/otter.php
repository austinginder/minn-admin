<?php
/**
 * Bundled adapter: Otter Blocks preview CSS + map fallback.
 *
 * Otter has two CSS models Minn has to bridge for island previews:
 *
 * 1. Classic `themeisle-blocks/*` stylesheets live at
 *    `build/blocks/{slug}/style.css` and are declared as handle names in
 *    block.json (`"style": "otter-review-style"`). Otter only
 *    `wp_register_style`s those handles inside `enqueue_block_styles( $post )`
 *    when `has_block()` finds them on a real front-end post — a bare REST
 *    `do_blocks()` never registers them, so the style queue-diff is empty
 *    and previews render as giant unconstrained SVGs (Product Review stars)
 *    or unstyled shells.
 *
 * 2. Per-post generated CSS is cached in postmeta
 *    (`_themeisle_gutenberg_block_styles`, `_atomic_wind_css`) and is only
 *    emitted on front-end hooks with a real $post. The postmeta path recovers
 *    those; atomic-wind (Tailwind, JIT in the browser) also gets a warm URL.
 *
 * Maps (`themeisle-blocks/leaflet-map`) are a third case: the server outputs
 * an empty container + an inline script that pushes attrs onto
 * `window.themeisleLeafletMaps`, and Leaflet paints the map client-side.
 * Island previews set HTML via `innerHTML`, so scripts never run. For Minn
 * previews we swap in OpenStreetMap's static embed (the same approach Otter
 * already uses for AMP), which needs no JS.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register Otter front-end style handles for every themeisle-blocks/* present
 * in the submitted markup, before do_blocks runs. Once registered, core's
 * render path auto-enqueues them and Minn's queue-diff hands them to the
 * client — same path Stackable/Kadence already take when they enqueue from
 * a render_block filter.
 *
 * @param array $blocks  Submitted block markup strings.
 * @param int   $post_id Post being edited (unused; markup is the source of truth).
 */
add_action(
	'minn_admin_before_render_blocks',
	function ( $blocks, $post_id ) {
		if ( ! defined( 'OTTER_BLOCKS_VERSION' ) || ! defined( 'OTTER_BLOCKS_PATH' ) || ! defined( 'OTTER_BLOCKS_URL' ) ) {
			return;
		}
		$slugs = minn_admin_otter_block_slugs( $blocks );
		if ( ! $slugs ) {
			return;
		}
		// Shared deps Otter registers at init (leaflet CSS, FA, …).
		$deps_map = array(
			'leaflet-map' => array( 'leaflet', 'leaflet-gesture-handling' ),
			'slider'      => array( 'glidejs-core', 'glidejs-theme' ),
		);
		$ver = OTTER_BLOCKS_VERSION;
		foreach ( $slugs as $slug ) {
			$path = OTTER_BLOCKS_PATH . '/build/blocks/' . $slug . '/style.css';
			if ( ! file_exists( $path ) ) {
				continue;
			}
			// Match Otter's handle naming: block.json "style" field.
			// register_block_type_from_metadata stored it on the block type.
			$handle = minn_admin_otter_style_handle( $slug );
			if ( ! $handle || wp_styles()->query( $handle ) ) {
				continue;
			}
			$deps = isset( $deps_map[ $slug ] ) ? $deps_map[ $slug ] : array();
			wp_register_style(
				$handle,
				OTTER_BLOCKS_URL . 'build/blocks/' . $slug . '/style.css',
				$deps,
				$ver
			);
			wp_style_add_data( $handle, 'path', $path );
		}
	},
	10,
	2
);

/**
 * Recover per-post Otter/atomic-wind CSS caches + belt-and-suspenders style
 * URLs for any classic themeisle block in the submitted markup (covers the
 * case where registration above somehow misses a handle).
 */
add_filter(
	'minn_admin_render_styles',
	function ( $styles, $blocks, $post_id ) {
		if ( ! defined( 'OTTER_BLOCKS_VERSION' ) ) {
			return $styles;
		}

		// Classic per-block stylesheets — always, even without a post id
		// (slash-insert of a bare review block before first save).
		if ( defined( 'OTTER_BLOCKS_PATH' ) && defined( 'OTTER_BLOCKS_URL' ) ) {
			$deps_urls = array(
				'leaflet-map' => array(
					OTTER_BLOCKS_URL . 'assets/leaflet/leaflet.css',
					OTTER_BLOCKS_URL . 'assets/leaflet/leaflet-gesture-handling.min.css',
				),
			);
			foreach ( minn_admin_otter_block_slugs( $blocks ) as $slug ) {
				$path = OTTER_BLOCKS_PATH . '/build/blocks/' . $slug . '/style.css';
				if ( ! file_exists( $path ) ) {
					continue;
				}
				if ( isset( $deps_urls[ $slug ] ) ) {
					foreach ( $deps_urls[ $slug ] as $dep_url ) {
						$styles['urls'][] = $dep_url;
					}
				}
				$styles['urls'][] = OTTER_BLOCKS_URL . 'build/blocks/' . $slug . '/style.css';
			}
			if ( ! empty( $styles['urls'] ) ) {
				$styles['urls'] = array_values( array_unique( $styles['urls'] ) );
			}
		}

		if ( ! $post_id ) {
			return $styles;
		}

		foreach ( array( '_themeisle_gutenberg_block_styles', '_atomic_wind_css' ) as $meta_key ) {
			$css = get_post_meta( $post_id, $meta_key, true );
			if ( is_string( $css ) && '' !== trim( $css ) ) {
				$styles['inline'] .= "\n" . $css;
			}
		}

		// Atomic-wind CSS is compiled in the BROWSER on a front-end view, and
		// Otter clears the cache on every save — a post being actively edited is
		// usually cold. Hand the client a warm URL: it loads the page in a
		// hidden iframe so Otter's own compiler runs (and, for editors, its
		// style-builder persists the cache), then re-fetches these styles.
		$post = get_post( $post_id );
		if ( $post && false !== strpos( $post->post_content, '<!-- wp:atomic-wind/' )
			&& '' === trim( (string) get_post_meta( $post_id, '_atomic_wind_css', true ) ) ) {
			$url = 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post );
			if ( $url ) {
				$styles['warm'] = add_query_arg( 'minn_warm', '1', $url );
			}
		}
		return $styles;
	},
	10,
	3
);

/**
 * Leaflet maps: empty div + init script. Island previews can't run scripts,
 * so replace with OpenStreetMap's embed iframe (Otter's own AMP path).
 *
 * @param string $html Rendered HTML from do_blocks.
 * @param string $raw  Original block markup.
 * @return string
 */
add_filter(
	'minn_admin_rendered_html',
	function ( $html, $raw ) {
		if ( ! is_string( $raw ) || false === strpos( $raw, 'themeisle-blocks/leaflet-map' ) ) {
			return $html;
		}
		$attrs = minn_admin_otter_comment_attrs( $raw );
		$bbox  = isset( $attrs['bbox'] ) ? (string) $attrs['bbox'] : '';
		if ( '' === $bbox ) {
			return $html;
		}
		// Otter stores bbox both raw and urlencoded; the embed wants encoded.
		$bbox = rawurlencode( rawurldecode( $bbox ) );
		$h    = isset( $attrs['height'] ) ? $attrs['height'] : 400;
		if ( is_string( $h ) ) {
			$h = (int) preg_replace( '/[^0-9]/', '', $h );
		}
		$h = $h > 0 ? (int) $h : 400;
		$src = 'https://www.openstreetmap.org/export/embed.html?bbox=' . $bbox . '&layer=mapnik';
		return sprintf(
			'<iframe class="wp-block-themeisle-blocks-leaflet-map minn-otter-map-preview" src="%s" style="border:0;width:100%%;height:%dpx;display:block" loading="lazy" title="Map"></iframe>',
			esc_url( $src ),
			$h
		);
	},
	10,
	2
);

/**
 * Unique themeisle-blocks slugs present in submitted markup.
 *
 * @param array $blocks Markup strings.
 * @return string[] e.g. ['review','leaflet-map']
 */
function minn_admin_otter_block_slugs( $blocks ) {
	$slugs = array();
	if ( ! is_array( $blocks ) ) {
		return $slugs;
	}
	foreach ( $blocks as $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			continue;
		}
		if ( preg_match_all( '/<!--\s*wp:(themeisle-blocks\/([a-z0-9-]+))/', $raw, $m ) ) {
			foreach ( $m[2] as $slug ) {
				$slugs[ $slug ] = true;
			}
		}
	}
	return array_keys( $slugs );
}

/**
 * Resolve the style handle Otter declared for a block slug.
 *
 * @param string $slug Block slug without namespace.
 * @return string|null
 */
function minn_admin_otter_style_handle( $slug ) {
	$type = WP_Block_Type_Registry::get_instance()->get_registered( 'themeisle-blocks/' . $slug );
	if ( $type && ! empty( $type->style_handles[0] ) ) {
		return $type->style_handles[0];
	}
	// Fallback to Otter's conventional naming when the type is missing.
	return 'otter-' . $slug . '-style';
}

/**
 * Parse JSON attributes from a single self-closing/open block comment.
 *
 * @param string $raw Block markup.
 * @return array
 */
function minn_admin_otter_comment_attrs( $raw ) {
	if ( ! preg_match( '/<!--\s*wp:themeisle-blocks\/[a-z0-9-]+\s+(\{.*?\})\s*\/?-->/s', $raw, $m ) ) {
		return array();
	}
	$attrs = json_decode( $m[1], true );
	return is_array( $attrs ) ? $attrs : array();
}
