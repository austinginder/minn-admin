<?php
/**
 * Shared helper for adapters that store a link someone typed.
 *
 * Every field vocabulary Minn maps has some notion of a URL, and each one
 * ends up writing it into a place a theme will later print into an href. The
 * rule is the same wherever the value came from, so it lives here rather than
 * being restated per adapter: strip the characters a scheme check can be hid
 * behind, refuse the schemes that execute, and store the value WordPress
 * itself would store.
 *
 * `esc_url_raw` strips quotes rather than emptying the string, so a value
 * like `http://a" onmouseover=x` survives it and breaks out of an unescaped
 * attribute in a theme template. That is why the refusal is a separate step
 * and why callers are expected to treat null as "leave what was there alone"
 * rather than writing an empty string over the stored value.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Clean a submitted URL, or refuse it.
 *
 * @param mixed $url Submitted value.
 * @return string|null Cleaned URL, '' when the field is being cleared, or
 *                     null when the value is refused.
 */
function minn_admin_url_clean( $url ) {
	$url = (string) $url;
	if ( '' === trim( $url ) ) {
		return ''; // empty clears the field; callers handle that themselves
	}
	// Control characters and spaces are removed for the scheme test only: a
	// browser ignores them, so `java\nscript:` runs while a naive check reads
	// it as a relative path. The value stored is still the one submitted.
	$probe = preg_replace( '/[\x00-\x20\x7F]+/', '', $url );
	if ( preg_match( '/^(javascript|data|vbscript):/i', (string) $probe ) ) {
		return null;
	}
	$clean = esc_url_raw( $url );
	return '' === $clean ? null : $clean;
}

/** Predicate for callers that only need the yes/no. */
function minn_admin_url_ok( $url ) {
	return null !== minn_admin_url_clean( $url );
}
