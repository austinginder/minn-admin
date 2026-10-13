<?php
/**
 * Plugins a menu tool hides from a client leave Minn with them, and only
 * those: the rules behind Minn_Admin_Notices::capture_removed_owners() and
 * Minn_Admin_Surfaces::owners_gone().
 *
 * - A surface several plugins share (Field Groups, Site Options) stays
 *   while any of them with admin pages of its own is still in the menu; a
 *   pro add-on with no pages of its own has no say.
 * - A plugin whose own page is hidden is gone even while its content screen
 *   (a list of a post type it registered) is still in the menu; only a
 *   surface that is that list (contentTypes) stays.
 * - Who registered a post type is read from the registration itself.
 * - A page a shared framework draws (Crocoblock's jet-dashboard) belongs to
 *   none of the plugins bundling it.
 * - A surface naming the pages that host it (adminPages) leaves when all of
 *   them are hidden, and a view with its own plugin leaves with it.
 * - System › Integrations names no surface a menu tool hid.
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
	// A capture's working state, or null where this build keeps none.
	$capture_state = static function ( $name ) {
		if ( ! property_exists( 'Minn_Admin_Notices', $name ) ) {
			return null;
		}
		$ref = new ReflectionProperty( 'Minn_Admin_Notices', $name );
		$ref->setAccessible( true );
		return $ref->getValue();
	};
	$with_list = Minn_Admin_Notices::capture_removed_owners();
	$check( 'a plugin whose own pages are hidden is gone though its post type list is in the menu', in_array( 'minn-probe-owner', $with_list, true ), wp_json_encode( $with_list ) );
	$check( 'and the capture records the list it still has', array( 'pt:minn_probe_pt' ) === ( ( (array) $capture_state( 'content_kept' ) )['minn-probe-owner'] ?? null ), wp_json_encode( $capture_state( 'content_kept' ) ) );
	$menu = array();
	$gone_without = Minn_Admin_Notices::capture_removed_owners();
	$check( 'control: with that list hidden too, it is gone', in_array( 'minn-probe-owner', $gone_without, true ), wp_json_encode( $gone_without ) );
	$check( 'and keeps no list', null !== $capture_state( 'content_kept' ) && ! isset( ( (array) $capture_state( 'content_kept' ) )['minn-probe-owner'] ) );
	$check( 'the capture records which plugins have pages', in_array( 'minn-probe-owner', (array) $capture_state( 'owners_with_pages' ), true ) );

	// --- a shared framework's page is no one's --------------------------------
	wp_mkdir_p( $probe_dir . '/jet-dashboard' );
	file_put_contents( $probe_dir . '/jet-dashboard/page.php', "<?php\nfunction minn_probe_owner_dash() {}\n" );
	include_once $probe_dir . '/jet-dashboard/page.php';
	remove_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );
	add_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_dash' );
	Minn_Admin_Notices::capture_removed_owners();
	$check( 'a page a bundled framework draws is not credited to the plugin bundling it', ! in_array( 'minn-probe-owner', (array) $capture_state( 'owners_with_pages' ), true ), wp_json_encode( $capture_state( 'owners_with_pages' ) ) );
	remove_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_dash' );

	// --- hosting pages and the surfaces that name them ------------------------
	$all_ref = new ReflectionProperty( 'Minn_Admin_Surfaces', 'all_cache' );
	$all_ref->setAccessible( true );
	$probe_surfaces = function ( $surfaces ) {
		$base = array( 'cap' => 'read', 'collection' => array( 'route' => 'minn-admin/v1/overview' ) );
		$surfaces['minn-probe-list']   = $base + array( 'label' => 'Probe list', 'plugin' => 'minn-probe-owner', 'contentTypes' => array( 'pt:minn_probe_pt' ) );
		$surfaces['minn-probe-config'] = $base + array( 'label' => 'Probe config', 'plugin' => 'minn-probe-owner' );
		$surfaces['minn-probe-hosted'] = $base + array( 'label' => 'Probe hosted', 'plugin' => 'minn-probe-nopage', 'adminPages' => array( 'minn-probe-host' ) );
		$surfaces['minn-probe-viewed'] = $base + array( 'label' => 'Probe viewed', 'plugin' => 'minn-probe-base', 'views' => array( array( 'viewLabel' => 'Mine', 'route' => 'minn-admin/v1/overview' ), array( 'viewLabel' => 'Theirs', 'plugin' => 'minn-probe-viewer', 'route' => 'minn-admin/v1/overview' ) ) );
		return $surfaces;
	};
	add_filter( 'minn_admin_surfaces', $probe_surfaces, 999 );
	$all_ref->setValue( null, null );
	$menu             = array( array( 'Probe items', 'edit_posts', 'edit.php?post_type=minn_probe_pt' ) );
	$admin_page_hooks = array();
	$_parent_pages    = array( 'minn-probe-host' => 'minn-probe-parent' );
	$host_vouch       = static function ( $hidden, $slug, $parent ) {
		return $hidden || ( 'minn-probe-host' === $slug && 'minn-probe-parent' === $parent );
	};
	add_filter( 'minn_admin_admin_page_hidden', $host_vouch, 10, 3 );
	Minn_Admin_Notices::capture_removed_owners();
	$check( 'the capture records a hosting page a menu tool hid', in_array( 'minn-probe-host', (array) $capture_state( 'pages_hidden' ), true ), wp_json_encode( $capture_state( 'pages_hidden' ) ) );
	remove_filter( 'minn_admin_admin_page_hidden', $host_vouch, 10 );

	update_user_option( $uid, Minn_Admin_Notices::REMOVED_OPTION, array( 'nav' => array(), 'owners' => array( 'minn-probe-owner', 'minn-probe-viewer' ), 'paged' => array( 'minn-probe-owner', 'minn-probe-viewer', 'minn-probe-base' ), 'content' => array( 'minn-probe-owner' => array( 'pt:minn_probe_pt' ) ), 'pages' => array( 'minn-probe-host' ), 'at' => time() ) );
	$probe_by_id = array();
	foreach ( Minn_Admin_Surfaces::for_current_user() as $probe_s ) {
		$probe_by_id[ $probe_s['id'] ] = $probe_s;
	}
	$check( 'the surface that is the list still in the menu stays', isset( $probe_by_id['minn-probe-list'] ) );
	$check( 'the same plugin\'s other surface leaves', ! isset( $probe_by_id['minn-probe-config'] ) );
	$check( 'a surface whose every hosting page is hidden leaves', ! isset( $probe_by_id['minn-probe-hosted'] ) );
	$probe_views = isset( $probe_by_id['minn-probe-viewed'] ) ? wp_list_pluck( (array) ( $probe_by_id['minn-probe-viewed']['views'] ?? array() ), 'viewLabel' ) : null;
	$check( 'a view whose own plugin is gone leaves, and the surface stays', array( 'Mine' ) === $probe_views, wp_json_encode( $probe_views ) );
	$probe_rows = wp_list_pluck( (array) ( Minn_Admin_Surfaces::integrations()['surfaces'] ?? array() ), 'id' );
	$check( 'System › Integrations does not name a hidden surface', ! in_array( 'minn-probe-config', $probe_rows, true ) && in_array( 'minn-probe-list', $probe_rows, true ), wp_json_encode( array_values( array_filter( $probe_rows, function ( $r ) { return 0 === strpos( $r, 'minn-probe' ); } ) ) ) );
	update_user_option( $uid, Minn_Admin_Notices::REMOVED_OPTION, array( 'nav' => array(), 'owners' => array(), 'paged' => array(), 'at' => time() ) );
	$probe_ids = wp_list_pluck( Minn_Admin_Surfaces::for_current_user(), 'id' );
	$check( 'control: with nothing hidden every probe surface shows', ! array_diff( array( 'minn-probe-list', 'minn-probe-config', 'minn-probe-hosted', 'minn-probe-viewed' ), $probe_ids ) );
	remove_filter( 'minn_admin_surfaces', $probe_surfaces, 999 );
	$all_ref->setValue( null, null );
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

	// The stored removals, spelled out; anything not given is empty.
	$store_hiding = static function ( $say ) use ( $uid ) {
		update_user_option( $uid, Minn_Admin_Notices::REMOVED_OPTION, $say + array( 'nav' => array(), 'owners' => array(), 'paged' => array(), 'content' => array(), 'pages' => array(), 'at' => time() ) );
	};

	// --- NI-5: a plugin's own content list stays with its other pages hidden --
	// Simple Custom CSS and JS is the list's whole plugin; while it is not
	// active here its post type is registered in memory so its surface shows.
	if ( ! post_type_exists( 'custom-css-js' ) ) {
		register_post_type( 'custom-css-js', array( 'public' => false, 'show_ui' => true ) );
		$ccj_temp = true;
	}
	$all_ref->setValue( null, null );
	$registry = Minn_Admin_Surfaces::all();
	foreach ( array( 'jet-theme-core' => 'pt:jet-theme-core', 'custom-css-js' => 'pt:custom-css-js' ) as $own_id => $own_list ) {
		if ( ! isset( $registry[ $own_id ] ) ) {
			echo "SKIP  NI-5 {$own_id}: its surface is not registered here\n";
			continue;
		}
		$store_hiding( array( 'owners' => array( $own_id ), 'paged' => array( $own_id ), 'content' => array( $own_id => array( $own_list ) ) ) );
		$check( "NI-5 {$own_id}'s list stays while its wp-admin list is in the menu and its other pages are hidden", in_array( $own_id, $ids(), true ) );
		$store_hiding( array( 'owners' => array( $own_id ), 'paged' => array( $own_id ) ) );
		$check( "NI-5 control: with that list hidden too, {$own_id} leaves", ! in_array( $own_id, $ids(), true ) );
	}
	if ( ! empty( $ccj_temp ) ) {
		unregister_post_type( 'custom-css-js' );
		$ccj_temp = false;
		$all_ref->setValue( null, null );
		$registry = Minn_Admin_Surfaces::all();
	}

	// --- Field Groups: ACF's list stays with it, ACPT's view leaves with ACPT -
	$fg_s      = $fg_id && isset( $registry[ $fg_id ] ) ? $registry[ $fg_id ] : null;
	$fg_active = array_map( 'dirname', (array) get_option( 'active_plugins', array() ) );
	$fg_dir    = (string) current( array_intersect( array( 'advanced-custom-fields-pro', 'advanced-custom-fields', 'secure-custom-fields' ), $fg_active ) );
	$fg_now    = static function () use ( $fg_id ) {
		foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
			if ( $fg_id === $s['id'] ) {
				return $s;
			}
		}
		return null;
	};
	$fg_has_acpt = static function ( $s ) {
		foreach ( (array) ( $s['views'] ?? array() ) as $v ) {
			if ( false !== strpos( (string) ( $v['route'] ?? '' ), 'acpt/groups' ) ) {
				return true;
			}
		}
		return false;
	};
	if ( ! $fg_s || '' === $fg_dir || false === strpos( (string) ( $fg_s['collection']['route'] ?? '' ), 'acf/schema/groups' ) ) {
		echo "SKIP  Field Groups: ACF's groups are not its main list here\n";
	} else {
		$store_hiding( array( 'owners' => array( $fg_dir ), 'paged' => array( $fg_dir ), 'content' => array( $fg_dir => array( 'pt:acf-field-group' ) ) ) );
		$check( 'Field Groups stays while ACF\'s Field Groups list is in the menu and its other pages are hidden', null !== $fg_now() );
		$store_hiding( array( 'owners' => array( $fg_dir ), 'paged' => array( $fg_dir ) ) );
		$check( 'control: with that list hidden too, Field Groups leaves', null === $fg_now() );
		if ( ! $fg_has_acpt( $fg_s ) ) {
			echo "SKIP  Field Groups has no ACPT view here\n";
		} else {
			$store_hiding( array( 'owners' => array( 'advanced-custom-post-type' ), 'paged' => array( $fg_dir, 'advanced-custom-post-type' ) ) );
			$fg_after = $fg_now();
			$check( 'with only ACPT hidden, its view leaves Field Groups and ACF\'s groups stay', null !== $fg_after && ! $fg_has_acpt( $fg_after ) );
			$store_hiding( array( 'owners' => array( $fg_dir ), 'paged' => array( $fg_dir, 'advanced-custom-post-type' ) ) );
			$fg_after = $fg_now();
			$fg_acf_left = $fg_after ? array_filter( (array) ( $fg_after['views'] ?? array() ), function ( $v ) { return false !== strpos( (string) ( $v['route'] ?? '' ), 'acf/schema' ); } ) : array();
			$check( 'with ACF hidden and ACPT shown, ACPT\'s groups become the Field Groups list and ACF\'s lists leave', $fg_after && false !== strpos( (string) ( $fg_after['collection']['route'] ?? '' ), 'acpt/groups' ) && ! $fg_acf_left, wp_json_encode( $fg_after ? $fg_after['collection']['route'] ?? null : null ) );
			$store_hiding( array() );
			$check( 'control: with nothing hidden, the ACPT view shows', $fg_has_acpt( $fg_now() ) );
		}
	}

	// --- WooCommerce Memberships leaves with its Members list -----------------
	// For a shop manager its Members and Plans lists are WooCommerce
	// submenus (Memberships removes the Plans item except on the plans
	// screen), and its Import / Export and Profile Fields pages hang under
	// the Members list. So it has no menu page the capture counts as its
	// own, and the Members list hosts the whole area. The menu below is that
	// shape, with a menu tool hiding WooCommerce > Memberships.
	if ( ! isset( $registry['woocommerce-memberships'] ) ) {
		echo "SKIP  WooCommerce Memberships inactive\n";
	} else {
		$wcm_list   = 'edit.php?post_type=wc_user_membership';
		$wcm_hidden = static function ( $hidden, $slug, $parent ) use ( $wcm_list ) {
			return $hidden || ( $wcm_list === $slug && 'woocommerce' === $parent );
		};
		$wcm_in     = static function () {
			return in_array( 'woocommerce-memberships', wp_list_pluck( Minn_Admin_Surfaces::for_current_user(), 'id' ), true );
		};
		$menu             = array( array( 'WooCommerce', 'manage_woocommerce', 'woocommerce' ) );
		$submenu          = array( 'woocommerce' => array( array( 'Orders', 'manage_woocommerce', 'admin.php?page=wc-orders' ) ) );
		$admin_page_hooks = array( 'woocommerce' => 'woocommerce' );
		$_parent_pages    = array(
			$wcm_list                               => 'woocommerce',
			'edit.php?post_type=wc_membership_plan' => 'woocommerce',
			'wc_memberships_import_export'          => $wcm_list,
			'wc_memberships_profile_fields'         => $wcm_list,
		);
		add_filter( 'minn_admin_admin_page_hidden', $wcm_hidden, 10, 3 );
		Minn_Admin_Notices::capture_removed_owners();
		remove_filter( 'minn_admin_admin_page_hidden', $wcm_hidden, 10 );
		$wcm_pages = (array) $capture_state( 'pages_hidden' );
		$check( 'Memberships: the capture records its Members list as hidden by the menu tool', in_array( $wcm_list, $wcm_pages, true ), wp_json_encode( $wcm_pages ) );
		$check( 'Memberships: its own pages under the Members list are not counted as menu pages of its own', ! in_array( 'woocommerce-memberships', (array) $capture_state( 'owners_with_pages' ), true ), wp_json_encode( $capture_state( 'owners_with_pages' ) ) );
		$store_hiding( array( 'pages' => $wcm_pages ) );
		$check( 'Memberships leaves Minn, members and plans together, when WooCommerce > Memberships is hidden', ! $wcm_in() );
		$store_hiding( array( 'pages' => array( 'edit.php?post_type=wc_membership_plan' ) ) );
		$check( 'control: a hidden Plans item alone does not take Memberships away', $wcm_in() );
		$store_hiding( array() );
		$check( 'control: with nothing hidden, Memberships stays', $wcm_in() );
	}

	// --- NI-6: JetSmartFilters' Filters do not leave with JetSearch -----------
	if ( ! isset( $registry['jet-search'] ) || ! function_exists( 'minn_admin_jsf_active' ) || ! minn_admin_jsf_active() ) {
		echo "SKIP  NI-6 needs JetSearch and JetSmartFilters\n";
	} else {
		$store_hiding( array( 'pages' => array( 'jet-dashboard-settings-page' ) ) );
		$jsf_ids = $ids();
		$check( 'NI-6 with JetSearch\'s hosting page hidden, JetSmartFilters\' Filters still show', ! in_array( 'jet-search', $jsf_ids, true ) && in_array( 'jet-smart-filters', $jsf_ids, true ), wp_json_encode( array_values( array_filter( $jsf_ids, function ( $i ) { return 0 === strpos( $i, 'jet-s' ); } ) ) ) );
		$store_hiding( array() );
		$jsf_lists = array();
		foreach ( Minn_Admin_Surfaces::for_current_user() as $jsf_s ) {
			foreach ( array_merge( array( $jsf_s['collection'] ?? null, $jsf_s['manage'] ?? null ), (array) ( $jsf_s['views'] ?? array() ) ) as $jsf_c ) {
				if ( is_array( $jsf_c ) && false !== strpos( (string) ( $jsf_c['route'] ?? '' ), 'jet-smart-filters/filters' ) ) {
					$jsf_lists[] = $jsf_s['id'];
				}
			}
		}
		$check( 'NI-6 with nothing hidden, Search and Filters both show and the filters list appears once', array( 'jet-smart-filters' ) === $jsf_lists && in_array( 'jet-search', $ids(), true ), wp_json_encode( $jsf_lists ) );
		$jsf_card = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/jet-search/status' ) )->get_data();
		$check( 'NI-6 the indexer row is on the Filters card only, not repeated on Search\'s', ! in_array( __( 'Indexer', 'minn-admin' ), wp_list_pluck( (array) ( $jsf_card['rows'] ?? array() ), 'label' ), true ), wp_json_encode( $jsf_card['rows'] ?? null ) );
	}

	// --- OS-11: YITH Gift Cards follows its panel -----------------------------
	if ( ! isset( $registry['yith-gift-cards'] ) ) {
		echo "SKIP  OS-11 YITH Gift Cards inactive\n";
	} else {
		$check( 'OS-11 the capture asks about the YITH Gift Cards panel', in_array( 'yith_woocommerce_gift_cards_panel', Minn_Admin_Surfaces::declared_admin_pages(), true ) );
		$store_hiding( array( 'pages' => array( 'yith_woocommerce_gift_cards_panel' ) ) );
		$check( 'OS-11 YITH Gift Cards leaves when a menu tool hides its panel', ! in_array( 'yith-gift-cards', $ids(), true ) );
		$store_hiding( array() );
		$check( 'OS-11 control: with the panel shown it stays', in_array( 'yith-gift-cards', $ids(), true ) );
	}

	// --- OS-16 and NI-14 (b): a list follows its own plugin -------------------
	$surface_now = static function ( $id ) {
		foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
			if ( $id === $s['id'] ) {
				return $s;
			}
		}
		return null;
	};
	if ( ! isset( $registry['cf7']['manage'] ) ) {
		echo "SKIP  OS-16 needs Contact Form 7 and Flamingo\n";
	} else {
		$cf7_paged = array( 'contact-form-7', 'flamingo' );
		$store_hiding( array( 'pages' => array( 'flamingo_inbound' ), 'owners' => array( 'flamingo' ), 'paged' => $cf7_paged ) );
		$cf7_s = $surface_now( 'cf7' );
		$check( 'CF7 with only Flamingo hidden, its inbox leaves and Contact Form 7\'s forms stay as the list', $cf7_s && false !== strpos( (string) ( $cf7_s['collection']['route'] ?? '' ), 'cf7/forms' ) && ! isset( $cf7_s['manage'] ) && ! isset( $cf7_s['status'] ), wp_json_encode( $cf7_s ? array( $cf7_s['collection']['route'] ?? null, array_keys( $cf7_s ) ) : null ) );
		$store_hiding( array( 'pages' => array( 'flamingo_inbound' ) ) );
		$cf7_s = $surface_now( 'cf7' );
		$check( 'CF7 with only Flamingo\'s inbox page hidden, the same', $cf7_s && false !== strpos( (string) ( $cf7_s['collection']['route'] ?? '' ), 'cf7/forms' ) );
		$store_hiding( array( 'pages' => array( 'flamingo_inbound' ), 'owners' => array( 'flamingo', 'contact-form-7' ), 'paged' => $cf7_paged ) );
		$check( 'control: with both hidden, the surface leaves', null === $surface_now( 'cf7' ) );
		$store_hiding( array( 'owners' => array( 'contact-form-7' ), 'paged' => $cf7_paged ) );
		$cf7_s = $surface_now( 'cf7' );
		$check( 'OS-16 with only Contact Form 7 hidden, the inbox stays and the Forms view leaves', $cf7_s && ! isset( $cf7_s['manage'] ) );
		$store_hiding( array() );
		$cf7_s = null;
		foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
			$cf7_s = 'cf7' === $s['id'] ? $s : $cf7_s;
		}
		$check( 'OS-16 control: with nothing hidden, messages and forms both show', $cf7_s && isset( $cf7_s['manage'] ) );
	}
	$probe_keys = function ( $surfaces ) {
		$route = 'minn-admin/v1/overview';
		$surfaces['minn-probe-keys'] = array(
			'label'      => 'Probe keys',
			'cap'        => 'read',
			'plugin'     => 'minn-probe-owner',
			'collection' => array( 'route' => $route, 'columns' => array( array( 'key' => 'a', 'label' => 'A' ) ), 'plugin' => 'minn-probe-x', 'adminPages' => array( 'minn-probe-y' ) ),
			'manage'     => array( 'route' => $route, 'viewLabel' => 'Theirs', 'plugin' => 'minn-probe-viewer', 'adminPages' => array( 'minn-probe-m' ), 'columns' => array( array( 'key' => 'a', 'label' => 'A' ) ) ),
			'views'      => array( array( 'viewLabel' => 'Listed', 'route' => $route, 'plugin' => 'minn-probe-viewer', 'contentTypes' => array( 'pt:minn_probe_pt' ), 'adminPages' => array( 'minn-probe-z' ), 'columns' => array( array( 'key' => 'a', 'label' => 'A' ) ) ) ),
		);
		return $surfaces;
	};
	add_filter( 'minn_admin_surfaces', $probe_keys, 999 );
	$all_ref->setValue( null, null );
	$store_hiding( array() );
	$keys_row = null;
	foreach ( (array) ( Minn_Admin_Surfaces::integrations()['surfaces'] ?? array() ) as $row ) {
		$keys_row = 'minn-probe-keys' === ( $row['id'] ?? '' ) ? $row : $keys_row;
	}
	$keys_problems = (array) ( $keys_row['problems'] ?? array() );
	$check( 'NI-14b a view\'s own contentTypes is not reported as unknown', $keys_row && ! preg_grep( '/^views\[0\]: unknown key/', $keys_problems ), wp_json_encode( $keys_problems ) );
	$check( 'plugin and adminPages on a surface\'s main collection are not reported, as the main list now follows them', ! preg_grep( '/^collection: unknown key/', $keys_problems ), wp_json_encode( $keys_problems ) );
	$keys_declared = Minn_Admin_Surfaces::declared_admin_pages();
	$check( 'the capture asks about the hosting pages a main list and manage name', in_array( 'minn-probe-y', $keys_declared, true ) && in_array( 'minn-probe-m', $keys_declared, true ), wp_json_encode( array_values( array_filter( $keys_declared, function ( $p ) { return 0 === strpos( $p, 'minn-probe' ); } ) ) ) );
	$check( 'NI-14b manage\'s own plugin is not reported', ! preg_grep( '/^manage: unknown key/', $keys_problems ), wp_json_encode( $keys_problems ) );
	$store_hiding( array( 'owners' => array( 'minn-probe-viewer' ), 'paged' => array( 'minn-probe-owner', 'minn-probe-viewer' ) ) );
	$keys_s = null;
	foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
		$keys_s = 'minn-probe-keys' === $s['id'] ? $s : $keys_s;
	}
	$check( 'NI-14b manage leaves with its own plugin, as a view does, and the surface stays', $keys_s && ! isset( $keys_s['manage'] ) && array() === (array) ( $keys_s['views'] ?? array() ), wp_json_encode( $keys_s ? array_keys( $keys_s ) : null ) );
	$store_hiding( array( 'owners' => array( 'minn-probe-x' ), 'paged' => array( 'minn-probe-owner', 'minn-probe-x' ) ) );
	$keys_s = $surface_now( 'minn-probe-keys' );
	$check( 'a main list whose own plugin is hidden gives way to the next list, and the surface stays', $keys_s && 'Theirs' === ( $keys_s['collection']['viewLabel'] ?? null ) && ! isset( $keys_s['manage'] ), wp_json_encode( $keys_s ? $keys_s['collection'] : null ) );
	$store_hiding( array( 'owners' => array( 'minn-probe-x', 'minn-probe-viewer' ), 'paged' => array( 'minn-probe-owner', 'minn-probe-x', 'minn-probe-viewer' ) ) );
	$check( 'and with every list hidden, the surface leaves', null === $surface_now( 'minn-probe-keys' ) );
	$store_hiding( array() );
	$keys_s = $surface_now( 'minn-probe-keys' );
	$check( 'control: with nothing hidden, the main list is its own', $keys_s && ! isset( $keys_s['collection']['viewLabel'] ) && isset( $keys_s['manage'] ) );
	remove_filter( 'minn_admin_surfaces', $probe_keys, 999 );
	$all_ref->setValue( null, null );

	// --- OS-12: pages bundled frameworks draw are no one's --------------------
	foreach ( array( 'freemius', 'plugin-fw', 'CMB2' ) as $fw ) {
		$fw_fn = 'minn_probe_owner_fw_' . substr( md5( $fw ), 0, 8 );
		wp_mkdir_p( $probe_dir . '/' . $fw );
		file_put_contents( $probe_dir . '/' . $fw . '/page.php', "<?php\nfunction {$fw_fn}() {}\n" );
		include_once $probe_dir . '/' . $fw . '/page.php';
		$menu             = array();
		$submenu          = array();
		$admin_page_hooks = array( 'minn-probe-owner' => 'minn-probe-owner' );
		$_parent_pages    = array( 'minn-probe-owner' => false );
		add_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), $fw_fn );
		Minn_Admin_Notices::capture_removed_owners();
		$check( "OS-12 a page the bundled {$fw} draws is not credited to the plugin carrying it", ! in_array( 'minn-probe-owner', (array) $capture_state( 'owners_with_pages' ), true ), wp_json_encode( $capture_state( 'owners_with_pages' ) ) );
		remove_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), $fw_fn );
	}
	add_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );
	Minn_Admin_Notices::capture_removed_owners();
	$check( 'OS-12 control: the plugin\'s own page is still credited to it', in_array( 'minn-probe-owner', (array) $capture_state( 'owners_with_pages' ), true ) );
	remove_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );

	// --- FW: a framework page under the plugin's own menu, from its own copy -
	// Rank Math's CMB2 settings pages sit under its own top-level menu. With
	// its Dashboard hidden, WordPress makes the first page left the menu's
	// link, and that settings page is still in front of the client.
	$other_dir = WP_PLUGIN_DIR . '/minn-probe-other';
	wp_mkdir_p( $other_dir . '/cmb2' );
	file_put_contents( $other_dir . '/cmb2/page.php', "<?php\nfunction minn_probe_other_fw() {}\n" );
	include_once $other_dir . '/cmb2/page.php';
	$fw_capture = function ( $fn ) use ( $vouch ) {
		global $menu, $submenu, $admin_page_hooks, $_parent_pages;
		$menu             = array( array( 'Probe', 'read', 'minn-probe-fw' ) );
		$submenu          = array( 'minn-probe-fw' => array( array( 'Settings', 'read', 'minn-probe-fw' ) ) );
		$admin_page_hooks = array( 'minn-probe-owner' => 'minn-probe-owner', 'minn-probe-fw' => 'minn-probe-fw' );
		$_parent_pages    = array( 'minn-probe-owner' => false, 'minn-probe-fw' => 'minn-probe-owner' );
		add_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );
		add_action( get_plugin_page_hookname( 'minn-probe-fw', 'minn-probe-owner' ), $fn );
		add_filter( 'minn_admin_admin_page_hidden', $vouch, 10, 2 );
		$removed = Minn_Admin_Notices::capture_removed_owners();
		remove_filter( 'minn_admin_admin_page_hidden', $vouch, 10 );
		remove_action( get_plugin_page_hookname( 'minn-probe-fw', 'minn-probe-owner' ), $fn );
		remove_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );
		return $removed;
	};
	$fw_removed = $fw_capture( 'minn_probe_owner_fw_' . substr( md5( 'CMB2' ), 0, 8 ) );
	$check( 'FW a framework settings page under the plugin\'s own menu, from its own copy, keeps the plugin while it is shown', ! in_array( 'minn-probe-owner', $fw_removed, true ), wp_json_encode( $fw_removed ) );
	$fw_removed = $fw_capture( 'minn_probe_other_fw' );
	$check( 'control: drawn from another plugin\'s copy of the framework, it is not the plugin\'s, which is hidden', in_array( 'minn-probe-owner', $fw_removed, true ), wp_json_encode( $fw_removed ) );

	// --- NI-14 (c): a docroot named like a framework keeps hiding -------------
	// Only a fresh process can have another plugins folder, so a child run
	// loads this site with WP_PLUGIN_DIR under a .../jet-dashboard/ folder
	// (Minn symlinked in, nothing else active) and asks who owns a page.
	if ( ! class_exists( 'WP_CLI' ) || ! function_exists( 'proc_open' ) || ! function_exists( 'symlink' ) ) {
		echo "SKIP  NI-14c needs WP-CLI and a child process\n";
	} else {
		$dr_base = realpath( sys_get_temp_dir() ) . '/minn-probe-docroot-' . wp_generate_password( 8, false, false );
		$dr_dir  = $dr_base . '/jet-dashboard/plugins';
		wp_mkdir_p( $dr_dir . '/minn-probe-owner' );
		file_put_contents( $dr_dir . '/minn-probe-owner/page.php', "<?php\nfunction minn_probe_owner_page() {}\n" );
		$dr_linked = @symlink( wp_normalize_path( dirname( MINN_ADMIN_FILE ) ), $dr_dir . '/' . basename( dirname( MINN_ADMIN_FILE ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$dr_main   = basename( dirname( MINN_ADMIN_FILE ) ) . '/' . basename( MINN_ADMIN_FILE );
		file_put_contents(
			$dr_base . '/require.php',
			"<?php\ndefine( 'WP_PLUGIN_DIR', " . var_export( $dr_dir, true ) . " );\n"
			. "WP_CLI::add_wp_hook( 'pre_option_active_plugins', function () { return array( " . var_export( $dr_main, true ) . " ); } );\n"
			. "WP_CLI::add_wp_hook( 'pre_site_option_active_sitewide_plugins', function () { return array(); } );\n"
			. "WP_CLI::add_wp_hook( 'pre_option_minn_admin_rewrites_healed', function () { return defined( 'MINN_ADMIN_VERSION' ) ? MINN_ADMIN_VERSION : false; } );\n"
		);
		file_put_contents(
			$dr_base . '/child.php',
			"<?php\nglobal \$menu, \$submenu, \$admin_page_hooks, \$_parent_pages;\n"
			. "include_once WP_PLUGIN_DIR . '/minn-probe-owner/page.php';\n"
			. "\$menu = array(); \$submenu = array(); \$admin_page_hooks = array( 'minn-probe-owner' => 'minn-probe-owner' ); \$_parent_pages = array( 'minn-probe-owner' => false );\n"
			. "add_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );\n"
			. "Minn_Admin_Notices::capture_removed_owners();\n"
			. "\$r = new ReflectionProperty( 'Minn_Admin_Notices', 'owners_with_pages' ); \$r->setAccessible( true );\n"
			. "echo 'MINNPROBE ' . wp_json_encode( array( 'dir' => WP_PLUGIN_DIR, 'paged' => \$r->getValue() ) ) . \"\\n\";\n"
		);
		$dr_out = $dr_linked ? WP_CLI::runcommand( 'eval-file ' . escapeshellarg( $dr_base . '/child.php' ) . ' --skip-themes --require=' . escapeshellarg( $dr_base . '/require.php' ), array( 'launch' => true, 'return' => 'all', 'exit_error' => false ) ) : null;
		$dr_got = $dr_out && preg_match( '/^MINNPROBE (.+)$/m', (string) $dr_out->stdout, $dr_m ) ? json_decode( $dr_m[1], true ) : null;
		if ( ! is_array( $dr_got ) || $dr_got['dir'] !== $dr_dir ) {
			echo 'SKIP  NI-14c the child run did not load from the probe plugins folder' . ( $dr_out ? ': ' . substr( trim( $dr_out->stderr . ' ' . $dr_out->stdout ), 0, 200 ) : '' ) . "\n";
		} else {
			$check( 'NI-14c under a docroot with a /jet-dashboard/ folder, a plugin\'s own page is still credited to it', in_array( 'minn-probe-owner', (array) $dr_got['paged'], true ), wp_json_encode( $dr_got ) );
		}
		if ( is_link( $dr_dir . '/' . basename( dirname( MINN_ADMIN_FILE ) ) ) ) {
			unlink( $dr_dir . '/' . basename( dirname( MINN_ADMIN_FILE ) ) );
		}
		foreach ( array( $dr_dir . '/minn-probe-owner/page.php', $dr_base . '/require.php', $dr_base . '/child.php' ) as $f ) {
			if ( is_file( $f ) ) {
				unlink( $f );
			}
		}
		foreach ( array( $dr_dir . '/minn-probe-owner', $dr_dir, $dr_base . '/jet-dashboard', $dr_base ) as $d ) {
			if ( is_dir( $d ) ) {
				rmdir( $d );
			}
		}
	}

	// --- NI-14 (a): a hide that only pages or lists carry refreshes the nav ----
	// The capture answers surfaces_changed so the app re-reads its surfaces
	// at once. wp_send_json() ends the request, so its die is turned into an
	// exception and the JSON caught.
	$respond = function () {
		$saved = array();
		foreach ( array( 'admin_notices', 'all_admin_notices' ) as $h ) {
			$saved[ $h ] = $GLOBALS['wp_filter'][ $h ] ?? null;
			unset( $GLOBALS['wp_filter'][ $h ] );
		}
		$levels = ob_get_level();
		while ( ob_get_level() ) {
			ob_end_flush();
		}
		$started = false;
		$start   = function ( $v ) use ( &$started ) {
			if ( ! $started ) {
				$started = true;
				ob_start();
			}
			return $v;
		};
		$die     = function () {
			return function () {
				throw new RuntimeException( 'minn-probe-die' );
			};
		};
		add_filter( 'minn_admin_respect_removed_menus', $start, 1 );
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		$json = '';
		try {
			Minn_Admin_Notices::capture_and_respond();
		} catch ( RuntimeException $e ) {
			$json = $started ? (string) ob_get_clean() : '';
		}
		remove_filter( 'minn_admin_respect_removed_menus', $start, 1 );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
		foreach ( $saved as $h => $was ) {
			if ( null !== $was ) {
				$GLOBALS['wp_filter'][ $h ] = $was;
			}
		}
		for ( $i = 0; $i < $levels; $i++ ) {
			ob_start();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	};
	$notices_key  = 'minn_admin_notices_v5_' . $uid;
	$notices_was  = get_transient( $notices_key );
	$seen_was     = get_user_meta( $uid, 'minn_admin_notice_seen', true );
	$notices_kept = true;
	$host_surface = function ( $surfaces ) {
		$surfaces['minn-probe-hosted'] = array( 'label' => 'Probe hosted', 'cap' => 'read', 'plugin' => 'minn-probe-nopage', 'adminPages' => array( 'minn-probe-host' ), 'collection' => array( 'route' => 'minn-admin/v1/overview' ) );
		return $surfaces;
	};
	$host_hidden = static function ( $hidden, $slug ) {
		return $hidden || 'minn-probe-host' === $slug;
	};
	add_filter( 'minn_admin_surfaces', $host_surface, 999 );
	add_filter( 'minn_admin_admin_page_hidden', $host_hidden, 10, 2 );
	$all_ref->setValue( null, null );
	$menu             = array();
	$submenu          = array();
	$admin_page_hooks = array();
	$_parent_pages    = array( 'minn-probe-host' => 'minn-probe-parent' );
	$store_hiding( array() );
	$r = $respond();
	$check( 'NI-14a a hosting page newly hidden says the surfaces changed', true === ( $r['surfaces_changed'] ?? null ), wp_json_encode( $r ) );
	$r = $respond();
	$check( 'NI-14a control: the same capture again says nothing changed', array_key_exists( 'surfaces_changed', $r ) && false === $r['surfaces_changed'], wp_json_encode( $r ) );
	remove_filter( 'minn_admin_admin_page_hidden', $host_hidden, 10 );
	$r = $respond();
	$check( 'NI-14a that page shown again says the surfaces changed', true === ( $r['surfaces_changed'] ?? null ), wp_json_encode( $r ) );
	$admin_page_hooks = array( 'minn-probe-owner' => 'minn-probe-owner' );
	$_parent_pages    = array( 'minn-probe-owner' => false );
	add_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );
	add_filter( 'minn_admin_admin_page_hidden', $vouch, 10, 2 );
	$r = $respond();
	$check( 'NI-14a control: a plugin newly hidden still says the surfaces changed', true === ( $r['surfaces_changed'] ?? null ), wp_json_encode( $r ) );
	remove_filter( 'minn_admin_admin_page_hidden', $vouch, 10 );
	remove_action( get_plugin_page_hookname( 'minn-probe-owner', '' ), 'minn_probe_owner_page' );
	remove_filter( 'minn_admin_surfaces', $host_surface, 999 );
	$all_ref->setValue( null, null );
} finally {
	if ( ! empty( $ccj_temp ) && post_type_exists( 'custom-css-js' ) ) {
		unregister_post_type( 'custom-css-js' );
	}
	if ( ! empty( $notices_kept ) ) {
		if ( false === $notices_was ) {
			delete_transient( $notices_key );
		} else {
			set_transient( $notices_key, $notices_was, DAY_IN_SECONDS );
		}
		if ( '' === $seen_was ) {
			delete_user_meta( $uid, 'minn_admin_notice_seen' );
		} else {
			update_user_meta( $uid, 'minn_admin_notice_seen', $seen_was );
		}
	}
	if ( null === $option_was ) {
		$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $uid, 'meta_key' => $option_key ) );
	} else {
		$wpdb->update( $wpdb->usermeta, array( 'meta_value' => $option_was ), array( 'user_id' => $uid, 'meta_key' => $option_key ) );
	}
	clean_user_cache( $uid );
	if ( post_type_exists( 'minn_probe_pt' ) ) {
		unregister_post_type( 'minn_probe_pt' );
	}
	foreach ( array( 'jet-dashboard', 'freemius', 'plugin-fw', 'CMB2' ) as $f ) {
		if ( is_file( $probe_dir . '/' . $f . '/page.php' ) ) {
			unlink( $probe_dir . '/' . $f . '/page.php' );
		}
		if ( is_dir( $probe_dir . '/' . $f ) ) {
			rmdir( $probe_dir . '/' . $f );
		}
	}
	foreach ( array( 'probe.php', 'page.php' ) as $f ) {
		if ( is_file( $probe_dir . '/' . $f ) ) {
			unlink( $probe_dir . '/' . $f );
		}
	}
	$other_dir = WP_PLUGIN_DIR . '/minn-probe-other';
	if ( is_file( $other_dir . '/cmb2/page.php' ) ) {
		unlink( $other_dir . '/cmb2/page.php' );
	}
	foreach ( array( $other_dir . '/cmb2', $other_dir ) as $d ) {
		if ( is_dir( $d ) ) {
			rmdir( $d );
		}
	}
	if ( is_dir( $probe_dir ) ) {
		rmdir( $probe_dir );
	}
}

$failed = count( array_filter( $results, function ( $r ) { return ! $r; } ) );
printf( "\nremoved-owners: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
