<?php
/**
 * Bundled adapter: Essential Blocks preview CSS.
 *
 * Essential Blocks bakes each block's generated CSS INTO the saved markup as
 * a `blockMeta` attribute ({desktop, tab, mobile} CSS strings) and only
 * materializes it to a per-post file on front-end hooks — a bare do_blocks()
 * render links nothing. The CSS is right there in the markup Minn submits,
 * so extract the desktop tier and hand it to the preview-scoping pipeline.
 * (Tablet/mobile tiers need EB's media-query wrapping; previews render at
 * desktop width, so the desktop tier is the honest subset.)
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_render_styles', function ( $styles, $blocks, $post_id ) {
	if ( ! defined( 'ESSENTIAL_BLOCKS_DIR_PATH' ) && ! class_exists( 'EssentialBlocks\Plugin' ) ) {
		return $styles;
	}
	foreach ( (array) $blocks as $markup ) {
		if ( ! is_string( $markup ) || false === strpos( $markup, '"blockMeta"' ) ) {
			continue;
		}
		// The desktop CSS is a JSON string value inside the block comment —
		// decode each occurrence individually (braces inside the CSS make
		// whole-object matching unreliable).
		if ( preg_match_all( '/"desktop":"((?:\\\\.|[^"\\\\])*)"/', $markup, $m ) ) {
			foreach ( $m[1] as $encoded ) {
				$css = json_decode( '"' . $encoded . '"' );
				if ( is_string( $css ) && '' !== trim( $css ) ) {
					$styles['inline'] .= "\n" . $css;
				}
			}
		}
	}
	return $styles;
}, 10, 3 );
