<?php
/**
 * Shared helper for copying post meta from one post to another.
 *
 * `get_post_meta( $id )` with no key returns values in their stored, still
 * serialized form, so a copy has to decode them or `add_post_meta` serializes
 * them a second time. Decoding is the awkward part: `maybe_unserialize`
 * instantiates whatever class a serialized string names, and post meta is
 * writable by anyone who can edit the post through core's own custom-fields
 * box, so the string is not necessarily one this site wrote.
 *
 * Nothing being copied here needs to become an object. Arrays, strings and
 * numbers are what plugins actually store, so decode with class construction
 * switched off and copy anything else across untouched as the string it
 * already was.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decode a stored meta value for copying, without instantiating classes.
 *
 * @param mixed $value Raw value as returned by get_post_meta( $id ).
 * @return mixed Decoded value, or the original when it is not safely decodable.
 */
function minn_admin_meta_copy_value( $value ) {
	if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
		return $value;
	}
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
	if ( false === $decoded && 'b:0;' !== $value ) {
		return $value; // undecodable: copy the stored string verbatim
	}
	// A serialized object comes back as __PHP_Incomplete_Class with classes
	// off. Copying that would store something the origin never held, so keep
	// the original string instead and let the plugin that wrote it decide.
	if ( minn_admin_meta_has_incomplete_class( $decoded ) ) {
		return $value;
	}
	return $decoded;
}

/**
 * Whether a decoded value contains an incomplete class anywhere inside it.
 *
 * @param mixed $value Decoded value.
 * @param int   $depth Recursion guard.
 * @return bool
 */
function minn_admin_meta_has_incomplete_class( $value, $depth = 0 ) {
	if ( $depth > 10 ) {
		return true;
	}
	if ( is_object( $value ) ) {
		return true;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $item ) {
			if ( minn_admin_meta_has_incomplete_class( $item, $depth + 1 ) ) {
				return true;
			}
		}
	}
	return false;
}
