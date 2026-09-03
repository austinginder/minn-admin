<?php
/**
 * Shared gate for an incoming attachment id.
 *
 * An id alone says nothing about whether this person may attach that file.
 * Uploads are served without authentication, so pointing a field at a
 * stranger's attachment publishes it, and walking the ids is trivial. Every
 * mapper that accepts a picture therefore has to ask the same three questions,
 * and each one that asked them separately was a chance to forget: the ACF,
 * ACPT and SEO mappers all reached this rule, and the JetEngine mapper that
 * landed later did not. One choke point means the next mapper inherits it.
 *
 * A refusal is not a clear. Returning the same answer for both meant that
 * saving a panel while holding a picture you cannot read DELETED the picture,
 * so an editor's image vanished when a contributor saved the post around it.
 * Callers treat null as "leave the stored value alone" and '' as "the writer
 * emptied this field".
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate an incoming attachment id.
 *
 * @param mixed $value Incoming value: { id, ... }, a bare id, or empty.
 * @return int|string|null Attachment id, '' to clear, or null to refuse.
 */
function minn_admin_attachment_in( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		$value = (array) $value;
		$value = isset( $value['id'] ) ? $value['id'] : 0;
	}
	// An empty submission is someone clearing the field, and clearing it is
	// what they asked for.
	if ( null === $value || false === $value || '' === $value ) {
		return '';
	}
	$att = is_numeric( $value ) ? (int) $value : 0;
	if ( $att < 1 || 'attachment' !== get_post_type( $att )
		|| ! current_user_can( 'upload_files' ) || ! current_user_can( 'read_post', $att ) ) {
		return null;
	}
	return $att;
}
