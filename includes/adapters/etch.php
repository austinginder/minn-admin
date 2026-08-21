<?php
/**
 * Bundled adapter: Etch.
 *
 * Etch keeps a page's CSS in its own style store and prints it as one inline
 * <style> block on wp_head, which a REST render never reaches. Its blocks DO
 * register their style ids while they render, so after do_blocks() the CSS a
 * preview needs is sitting in Etch's register with nobody to compile it: the
 * preview came out with the right words and none of the design, which reads
 * as a broken page rather than an editable one.
 *
 * Copy and image editing need no adapter. Etch stores each line in a
 * wp:etch/text block's `content`, which the editor arms as an in-place run.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Etch's style register, or '' when this site does not run Etch. */
function minn_admin_etch_styles_class() {
	$class = '\\Etch\\Blocks\\Global\\StylesRegister';
	return class_exists( $class ) ? $class : '';
}

/**
 * Etch's own renderer, captured rather than reimplemented.
 *
 * The method is public and prints; calling it is what a front-end page does.
 * Prefer the instance Etch already hooked to wp_head so the callback that
 * runs here is byte-for-byte the one that runs on the site. A throwaway
 * instance is the fallback, with its constructor's wp_head hook removed
 * again: this must not leave a second printer behind.
 *
 * @return string CSS, or '' when there is nothing to render.
 */
function minn_admin_etch_page_css() {
	$class = minn_admin_etch_styles_class();
	if ( '' === $class ) {
		return '';
	}
	$callback = null;
	global $wp_filter;
	if ( isset( $wp_filter['wp_head'] ) && ! empty( $wp_filter['wp_head']->callbacks ) ) {
		foreach ( $wp_filter['wp_head']->callbacks as $hooked ) {
			foreach ( (array) $hooked as $entry ) {
				$fn = isset( $entry['function'] ) ? $entry['function'] : null;
				if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) && is_object( $fn[0] )
					&& $fn[0] instanceof $class && 'render_frontend_styles' === $fn[1] ) {
					$callback = $fn;
					break 2;
				}
			}
		}
	}
	$temp = null;
	if ( null === $callback ) {
		try {
			$temp     = new $class();
			$callback = array( $temp, 'render_frontend_styles' );
		} catch ( Throwable $e ) {
			return '';
		}
	}
	$out = '';
	try {
		ob_start();
		call_user_func( $callback );
		$out = (string) ob_get_clean();
	} catch ( Throwable $e ) {
		// A vendor change must never cost the writer their preview.
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$out = '';
	}
	if ( $temp ) {
		remove_action( 'wp_head', array( $temp, 'render_frontend_styles' ), 99 );
	}
	// The method prints a full <style> element; the preview wants the rules.
	if ( '' === trim( $out ) ) {
		return '';
	}
	$css = preg_replace( '#</?style\b[^>]*>#i', '', $out );
	return is_string( $css ) ? trim( $css ) : '';
}

add_filter(
	'minn_admin_render_styles',
	function ( $styles, $blocks, $post_id ) {
		unset( $post_id );
		if ( '' === minn_admin_etch_styles_class() ) {
			return $styles;
		}
		$has_etch = false;
		foreach ( (array) $blocks as $markup ) {
			if ( is_string( $markup ) && false !== strpos( $markup, '<!-- wp:etch/' ) ) {
				$has_etch = true;
				break;
			}
		}
		if ( ! $has_etch ) {
			return $styles;
		}
		// Runs after do_blocks(), which is what filled Etch's register.
		$css = minn_admin_etch_page_css();
		if ( '' !== $css ) {
			$styles['inline'] = trim( ( isset( $styles['inline'] ) ? $styles['inline'] : '' ) . "\n" . $css );
		}
		return $styles;
	},
	10,
	3
);
