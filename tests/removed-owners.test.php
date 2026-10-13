<?php
/**
 * Plugins a menu tool hides from a client leave Minn with them, and only
 * those: the rules behind Minn_Admin_Notices::capture_removed_owners() and
 * Minn_Admin_Surfaces::owners_gone().
 *
 * - A surface several plugins share (Field Groups, Site Options) stays
 *   while any of them with admin pages of its own is still in the menu; a
 *   pro add-on with no pages of its own has no say.
 * - A plugin whose own page is hidden but whose content screen (a list of
 *   a post type it registered) is still in the menu is not gone.
 * - Who registered a post type is read from the registration itself.
 * - A stored removal no capture has refreshed for a week no longer counts.
 *
 * The capture runs over synthetic menu globals; the user option it reads
 * is put back raw at the end, and the throwaway plugin file is removed.
 *
 * Run: wp eval-file tests/removed-owners.test.php --user=admin --path=<site>
 */

global $menu, $submenu, $admin_page_hooks, $_parent_pages, $wpdb;

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$label}" . ( $ok || '' === $detail ? '' : "\n      {$detail}" ) . "\n";
};

$uid = get_current_user_id();
if ( ! $uid ) {
	echo "SKIP  run with --user=admin\n";
	return;
}
$option_key = $wpdb->get_blog_prefix() . Minn_Admin_Notices::REMOVED_OPTION;
$option_was = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $uid, $option_key ) );
$probe_dir  = WP_PLUGIN_DIR . '/minn-probe-owner';

try {
	// --- shared surfaces ------------------------------------------------------
	$fg = array( 'advanced-custom-fields-pro', 'advanced-custom-fields', 'secure-custom-fields', 'advanced-custom-post-type' );
	$check( 'a shared surface stays while another plugin in it still has its pages', false === Minn_Admin_Surfaces::owners_gone( $fg, array( 'advanced-custom-post-type' ), array( 'advanced-custom-fields-pro', 'advanced-custom-post-type' ) ) );
	$check( 'and leaves once every plugin in it with pages is gone', true === Minn_Admin_Surfaces::owners_gone( $fg, array( 'advanced-custom-post-type', 'advanced-custom-fields-pro' ), array( 'advanced-custom-fields-pro', 'advanced-custom-post-type' ) ) );
	$check( 'a pro add-on with no pages of its own has no say', true === Minn_Admin_Surfaces::owners_gone( array( 'formidable', 'formidable-pro' ), array( 'formidable' ), array( 'formidable' ) ) );
	$check( 'before a capture recorded who has pages, one plugin gone is enough', true === Minn_Admin_Surfaces::owners_gone( array( 'a-plugin', 'b-plugin' ), array( 'a-plugin' ), array() ) );
	$check( 'a surface none of whose plugins is gone stays', false === Minn_Admin_Surfaces::owners_gone( array( 'x-plugin' ), array( 'a-plugin' ), array( 'a-plugin', 'x-plugin' ) ) );

	// --- who registered a post type ------------------------------------------
	wp_mkdir_p( $probe_dir );
	file_put_contents( $probe_dir . '/probe.php', "<?php\nfunction minn_probe_owner_register() {\n\tregister_post_type( 'minn_probe_pt', array( 'public' => false, 'show_ui' => true ) );\n}\n" );
	include_once $probe_dir . '/probe.php';
	add_action( 'registered_post_type', array( 'Minn_Admin_Notices', 'note_content_owner' ), 10, 1 );
	minn_probe_owner_register();
	remove_action( 'registered_post_type', array( 'Minn_Admin_Notices', 'note_content_owner' ), 10 );
	$owners_ref = new ReflectionProperty( 'Minn_Admin_Notices', 'content_owners' );
	$owners_ref->setAccessible( true );
	$noted = (array) $owners_ref->getValue();
	$check( 'a post type is credited to the plugin that registered it', 'minn-probe-owner' === ( $noted['pt:minn_probe_pt'] ?? '' ), wp_json_encode( $noted ) );

	// --- a content screen still in the menu keeps its plugin -----------------
	// The probe plugin's own page is hidden and vouched for; its post type's
	// list stays in the menu.
	file_put_contents( $probe_dir . '/page.php', "<?php\nfunction minn_probe_owner_page() {}\n" );
	include_once $probe_dir . '/page.php';
	$menu             = array( array( 'Probe items', 'edit_posts', 'edit.php?post_type=minn_probe_pt' ) );
	$submenu          = array();
	$admin_page_hooks = array( 'minn-probe-owner' => 'minn-probe-owner' );
	$_parent_pages    = array( 'minn-probe-owner' => false );
	add_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );
	$vouch = static function ( $hidden, $slug ) {
		return $hidden || 'minn-probe-owner' === $slug;
	};
	add_filter( 'minn_admin_admin_page_hidden', $vouch, 10, 2 );
	$kept_by_content = Minn_Admin_Notices::capture_removed_owners();
	$check( 'a plugin whose post type list is still in the menu is not gone', ! in_array( 'minn-probe-owner', $kept_by_content, true ), wp_json_encode( $kept_by_content ) );
	$menu = array();
	$gone_without = Minn_Admin_Notices::capture_removed_owners();
	$check( 'control: with that list hidden too, it is gone', in_array( 'minn-probe-owner', $gone_without, true ), wp_json_encode( $gone_without ) );
	$paged_ref = new ReflectionProperty( 'Minn_Admin_Notices', 'owners_with_pages' );
	$paged_ref->setAccessible( true );
	$check( 'the capture records which plugins have pages', in_array( 'minn-probe-owner', (array) $paged_ref->getValue(), true ) );
	remove_filter( 'minn_admin_admin_page_hidden', $vouch, 10 );

	// --- stored removals expire without a fresh capture ----------------------
	update_user_option( $uid, Minn_Admin_Notices::REMOVED_OPTION, array( 'nav' => array(), 'owners' => array( 'minn-probe-owner' ), 'paged' => array( 'minn-probe-owner' ), 'at' => time() - 2 * DAY_IN_SECONDS ) );
	$check( 'a removal captured recently counts', array( 'minn-probe-owner' ) === Minn_Admin_Notices::removed_owners() );
	update_user_option( $uid, Minn_Admin_Notices::REMOVED_OPTION, array( 'nav' => array(), 'owners' => array( 'minn-probe-owner' ), 'paged' => array( 'minn-probe-owner' ), 'at' => time() - 8 * DAY_IN_SECONDS ) );
	$check( 'one no capture refreshed for over a week does not', array() === Minn_Admin_Notices::removed_owners() );

	// --- Field Groups on this site, end to end --------------------------------
	$ids = static function () {
		return wp_list_pluck( Minn_Admin_Surfaces::for_current_user(), 'id' );
	};
	$fg_id = defined( 'MINN_ADMIN_FIELD_GROUPS_SURFACE' ) ? MINN_ADMIN_FIELD_GROUPS_SURFACE : '';
	$all   = Minn_Admin_Surfaces::all();
	$fg_plugins = $fg_id && isset( $all[ $fg_id ] ) ? (array) ( $all[ $fg_id ]['plugin'] ?? array() ) : array();
	if ( count( $fg_plugins ) > 1 ) {
		$one = $fg_plugins[ count( $fg_plugins ) - 1 ];
		update_user_option( $uid, Minn_Admin_Notices::REMOVED_OPTION, array( 'nav' => array(), 'owners' => array( $one ), 'paged' => $fg_plugins, 'at' => time() ) );
		$check( "Field Groups stays when one of its plugins ({$one}) is hidden", in_array( $fg_id, $ids(), true ) );
		update_user_option( $uid, Minn_Admin_Notices::REMOVED_OPTION, array( 'nav' => array(), 'owners' => $fg_plugins, 'paged' => $fg_plugins, 'at' => time() ) );
		$check( 'and leaves when all of them are', ! in_array( $fg_id, $ids(), true ) );
	} else {
		echo "SKIP  Field Groups has fewer than two plugins on this site\n";
	}
} finally {
	if ( null === $option_was ) {
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $uid, 'meta_key' => $option_key ) );
	} else {
		$wpdb->update( $wpdb->usermeta, array( 'meta_value' => $option_was ), array( 'user_id' => $uid, 'meta_key' => $option_key ) );
	}
	clean_user_cache( $uid );
	if ( post_type_exists( 'minn_probe_pt' ) ) {
		unregister_post_type( 'minn_probe_pt' );
	}
	foreach ( array( 'probe.php', 'page.php' ) as $f ) {
		if ( is_file( $probe_dir . '/' . $f ) ) {
			unlink( $probe_dir . '/' . $f );
		}
	}
	if ( is_dir( $probe_dir ) ) {
		rmdir( $probe_dir );
	}
}

$failed = count( array_filter( $results, function ( $r ) { return ! $r; } ) );
printf( "\nremoved-owners: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
