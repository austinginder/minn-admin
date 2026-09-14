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
	// Undecodable, or carrying a serialized object: copy the stored string
	// verbatim and let the plugin that wrote it decide what it means.
	return Minn_Admin::decode_serialized( $value, $value );
}

/**
 * Whether a flattened answer leaf is this server's own filesystem path.
 *
 * A file-upload answer is a structure, and one of its leaves is where the file
 * sits on disk. The renderer wants the address; the path is the server's
 * directory layout, which is nobody's business in an entries table. Forminator
 * handles this by knowing its own upload shape, but every form plugin stores
 * uploads differently, so the shape-independent question is whether the leaf
 * points inside this install.
 *
 * @param string $leaf Flattened leaf.
 * @return bool
 */
function minn_admin_is_server_path( $leaf ) {
	$leaf = trim( (string) $leaf );
	if ( '' === $leaf || false !== strpos( $leaf, '://' ) ) {
		return false; // a URL is the thing we want to keep
	}
	foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
		$root = (string) $root;
		if ( '' !== $root && 0 === strpos( $leaf, $root ) ) {
			return true;
		}
	}
	$uploads = wp_get_upload_dir();
	return ! empty( $uploads['basedir'] ) && 0 === strpos( $leaf, (string) $uploads['basedir'] );
}
