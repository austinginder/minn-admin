<?php
/**
 * Bundled adapter: Otter Blocks preview CSS.
 *
 * Otter generates per-post CSS rather than shipping a stylesheet: classic
 * themeisle-blocks CSS is built server-side on save, and atomic-wind CSS is
 * Tailwind, JIT-compiled in the visitor's browser — both end up cached in
 * postmeta (`_themeisle_gutenberg_block_styles`, `_atomic_wind_css`) and are
 * only emitted on front-end hooks with a real $post. A bare do_blocks()
 * render can never produce them, so island previews of Otter content would
 * be unstyled (the giant-unconstrained-SVG look).
 *
 * But when Minn previews islands of an EXISTING post, that cached CSS is one
 * postmeta lookup away — the render-blocks endpoint passes the post id, and
 * this filter hands the cache to the client's preview-scoping pipeline.
 *
 * Limit: a brand-new atomic-wind section that has never rendered on the
 * front end has no cache yet (the Tailwind compiler runs in the browser on
 * first view). One front-end visit warms it.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_render_styles', function ( $styles, $blocks, $post_id ) {
	if ( ! $post_id || ! defined( 'OTTER_BLOCKS_VERSION' ) ) {
		return $styles;
	}
	foreach ( array( '_themeisle_gutenberg_block_styles', '_atomic_wind_css' ) as $meta_key ) {
		$css = get_post_meta( $post_id, $meta_key, true );
		if ( is_string( $css ) && '' !== trim( $css ) ) {
			$styles['inline'] .= "\n" . $css;
		}
	}
	return $styles;
}, 10, 3 );
