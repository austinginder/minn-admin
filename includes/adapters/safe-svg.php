<?php
/**
 * Bundled adapter: Safe SVG (wp.org free) + SVG Support (Benbodhi, 1M).
 *
 * WordPress core blocks SVG uploads; both plugins allow them. Minn already
 * labels image/svg* as SVG in the media library; this adapter only:
 *   1. Boots a `safeSvg` flag so the media toolbar can show an SVG filter
 *      tab, plus `svgProvider` naming which plugin enables it (drives the
 *      detail note's wording)
 *   2. Does NOT reimplement sanitization — each plugin's upload_mimes +
 *      sanitizer stay the source of truth (SVG Support's sanitize-on-upload
 *      and role restrictions are its own settings, so Minn's note claims
 *      only "uploads enabled", never "sanitized", for it)
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Safe SVG is active for the current request.
 *
 * @return bool
 */
function minn_admin_safe_svg_active() {
	return defined( 'SAFE_SVG_VERSION' )
		|| class_exists( 'safe_svg', false )
		|| class_exists( 'SafeSvg\\safe_svg', false )
		|| function_exists( 'safe_svg_upload_mimes' );
}

/**
 * Which SVG-enabling plugin is active: 'Safe SVG', 'SVG Support', or null.
 * Safe SVG wins when both are active (it always sanitizes).
 *
 * @return string|null
 */
function minn_admin_svg_provider() {
	if ( minn_admin_safe_svg_active() ) {
		return 'Safe SVG';
	}
	if ( defined( 'BODHI_SVGS_VERSION' ) ) {
		return 'SVG Support';
	}
	return null;
}

// Boot flags are stamped in Minn_Admin::boot_payload() as `safeSvg`
// (boolean, either provider) + `svgProvider` (the name).
