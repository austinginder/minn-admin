<?php
/**
 * Bundled adapter: Safe SVG (wp.org free).
 *
 * WordPress core blocks SVG uploads. Safe SVG sanitizes and allows them.
 * Minn already labels image/svg* as SVG in the media library; this adapter
 * only:
 *   1. Boots a `safeSvg` flag so the media toolbar can show an SVG filter
 *      and a short "SVG uploads on" affordance
 *   2. Does NOT reimplement sanitization — Safe SVG's upload_mimes +
 *      sanitizer stay the source of truth
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

// Boot flag is stamped in Minn_Admin::boot_payload() as `safeSvg`
// (same pattern as regenThumbs).
