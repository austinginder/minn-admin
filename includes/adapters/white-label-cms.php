<?php
/**
 * White Label CMS (white-label-cms): agencies hide plugin menus from their
 * clients there, so Minn hides those plugins' screens from the same people.
 *
 * White Label CMS keeps a list of top-level menus and submenus to hide and
 * applies it to everyone except its own White Label CMS Admins (and network
 * super admins). Minn's notices capture asks, page by page, whether a menu
 * tool hid a page on purpose. This answers from those settings, through
 * White Label CMS's own checks for who counts as a client. A plugin whose
 * every page is hidden then leaves Minn for that user
 * (Minn_Admin_Notices::capture_removed_owners()).
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_admin_page_hidden', 'minn_admin_wlcms_page_hidden', 10, 3 );

// A change to what White Label CMS hides reaches Minn on the next load.
add_action( 'add_option_wlcms_options', array( 'Minn_Admin_Notices', 'expire_captures' ) );
add_action( 'update_option_wlcms_options', array( 'Minn_Admin_Notices', 'expire_captures' ) );

/**
 * Whether White Label CMS hides an admin page from the current user.
 *
 * @param bool   $hidden Whether another tool already said so.
 * @param string $slug   The page's menu slug.
 * @param string $parent Its parent menu slug, '' for a top-level page.
 * @return bool
 */
function minn_admin_wlcms_page_hidden( $hidden, $slug, $parent ) {
	if ( $hidden || ! function_exists( 'wlcms' ) || ! function_exists( 'wlcms_field_setting' ) || ! function_exists( 'is_wlcms_admin' ) ) {
		return $hidden;
	}
	$menus = wlcms()->Admin_Menus();
	if ( ! is_object( $menus ) || ! method_exists( $menus, 'enable_admin_menu' ) || ! method_exists( $menus, 'is_legacy_menu_role' ) ) {
		return $hidden;
	}
	// The gate White Label CMS's own rebuild_user_admin_menu() applies.
	if ( ( ! $menus->enable_admin_menu() && ! $menus->is_legacy_menu_role() ) || is_wlcms_admin() ) {
		return $hidden;
	}
	$set  = wlcms_field_setting( 'admin_menus' );
	$main = isset( $set['main'] ) && is_array( $set['main'] ) ? $set['main'] : array();
	$sub  = isset( $set['sub'] ) && is_array( $set['sub'] ) ? $set['sub'] : array();
	// Slugs are compared the way White Label CMS compares them.
	if ( '' === $parent ) {
		return in_array( urldecode( remove_query_arg( 'return', $slug ) ), $main, true );
	}
	// A hidden top-level menu takes its submenus with it.
	if ( in_array( urldecode( remove_query_arg( 'return', $parent ) ), $main, true ) ) {
		return true;
	}
	$glue = method_exists( $menus, 'get_submenu_placeholder' ) ? $menus->get_submenu_placeholder() : '_wlcms_';
	return in_array( $parent . $glue . sanitize_title( remove_query_arg( 'return', $slug ) ), $sub, true );
}
