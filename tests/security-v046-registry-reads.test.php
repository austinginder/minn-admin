<?php
/**
 * Regressions for the v0.45.0 shipped-zip registry scan, the reads lane
 * (findings that open v0.46.0): #3 network gates, #7 the database browser
 * and each plugin's own viewer rule, #8 titles in the revision comparison.
 *
 * Each section calls the route Minn's client calls, as the role the finding
 * named, with a control where the fix could over-block. Sections SKIP when
 * their plugin is inactive or the site is the wrong shape: run it on a single
 * site (the browser, ACF) and on a network's main site (the network gates).
 *
 * Run: wp eval-file tests/security-v046-registry-reads.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res  = rest_do_request( $r );
	$data = json_decode( wp_json_encode( $res->get_data() ), true );
	return array( $res->get_status(), $data );
};
// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	if ( ! is_int( $id ) || $id < 2 ) {
		return; // never a WP_Error cast to 1, never the first account
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $id );
		return;
	}
	wp_delete_user( $id );
};
$make_user = function ( $login, $role ) {
	$id = wp_insert_user( array(
		'user_login' => $login . '_' . wp_generate_password( 6, false, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '.' . wp_generate_password( 6, false, false ) . '@example.com',
		'role'       => $role,
	) );
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$admin = get_current_user_id();
if ( ! $admin || ! current_user_can( 'manage_options' ) || ( is_multisite() && ! is_super_admin() ) ) {
	echo "SKIP  run with --user=<an administrator> (a super admin on a network)\n";
	return;
}

// --- #8 (08-1) The revision comparison prints a title only for an
// attachment the viewer may read ---
// ACF stores any number in an image or file field and core's Custom Fields
// box lets the post's author write one, so the id can name another author's
// private post; the comparison printed its title to the draft's Contributor.
( function () use ( $check, $skip, $call, $admin, $make_user, $drop_user ) {
	if ( ! function_exists( 'acf_add_local_field_group' ) || ! function_exists( 'minn_admin_acf_display_value' ) ) {
		$skip( '#8 ACF inactive' );
		return;
	}
	$made = array( 'posts' => array(), 'users' => array() );
	try {
		acf_add_local_field_group( array(
			'key'      => 'group_minn_v046_rev',
			'title'    => 'Minn v046 revision probe',
			'fields'   => array(
				array( 'key' => 'field_minn_v046_img', 'name' => 'minn_v046_img', 'label' => 'Probe image', 'type' => 'image', 'return_format' => 'id' ),
				array( 'key' => 'field_minn_v046_file', 'name' => 'minn_v046_file', 'label' => 'Probe file', 'type' => 'file', 'return_format' => 'id' ),
			),
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
		) );
		$secret = 'Minn v046 private target ' . wp_generate_password( 6, false, false );
		$target = wp_insert_post( array( 'post_title' => $secret, 'post_status' => 'private', 'post_type' => 'post', 'post_author' => $admin ) );
		$att    = wp_insert_post( array( 'post_title' => 'Minn v046 readable picture', 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_mime_type' => 'image/png', 'post_author' => $admin ) );
		$writer = $make_user( 'minn_v046_contrib', 'contributor' );
		foreach ( array( $target, $att ) as $p ) {
			if ( is_int( $p ) && $p > 0 ) {
				$made['posts'][] = $p;
			}
		}
		if ( $writer ) {
			$made['users'][] = $writer;
		}
		if ( ! $writer || count( $made['posts'] ) < 2 ) {
			$check( '#8 setup', false, 'could not make the posts or the contributor' );
			return;
		}
		$draft = wp_insert_post( array( 'post_title' => 'Minn v046 contributor draft', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $writer ) );
		$made['posts'][] = (int) $draft;
		$rev = _wp_put_post_revision( get_post( $draft ) );
		if ( ! is_int( $rev ) || $rev < 1 ) {
			$check( '#8 setup', false, 'no revision' );
			return;
		}
		$made['posts'][] = $rev;
		// The revision recorded both fields empty; ACF's reference meta is what
		// says a value was written there.
		foreach ( array( 'minn_v046_img' => 'field_minn_v046_img', 'minn_v046_file' => 'field_minn_v046_file' ) as $name => $key ) {
			add_metadata( 'post', $rev, $name, '' );
			add_metadata( 'post', $rev, '_' . $name, $key );
			update_post_meta( $draft, '_' . $name, $key );
		}
		// What the author writes by hand: another post's id, and an image.
		update_post_meta( $draft, 'minn_v046_img', (string) $target );
		update_post_meta( $draft, 'minn_v046_file', (string) $att );
		wp_set_current_user( $writer );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/revision-fields/' . $draft . '/' . $rev );
		$now = array();
		foreach ( (array) ( $d['groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['rows'] ?? array() ) as $r ) {
				$now[ $r['label'] ] = $r['now'];
			}
		}
		$shown = wp_json_encode( $now );
		$check( "#8 a Contributor's revision comparison never names another author's private post", 200 === $st && isset( $now['Probe image'] ) && false === strpos( $shown, $secret ) && '#' . $target === $now['Probe image'], $st . ' ' . $shown );
		$check( '#8 control: an attachment they may read keeps its title', 'Minn v046 readable picture' === ( $now['Probe file'] ?? null ), $shown );
		wp_set_current_user( $admin );
		$check( '#8 the display rule itself: a non-attachment is its number even for someone who may read it', '#' . $target === minn_admin_acf_display_value( array( 'type' => 'image' ), $target ) );
	} finally {
		wp_set_current_user( $admin );
		foreach ( array_reverse( $made['posts'] ) as $p ) {
			wp_delete_post( $p, true );
		}
		foreach ( $made['users'] as $u ) {
			$drop_user( $u );
		}
		if ( function_exists( 'acf_remove_local_field_group' ) ) {
			acf_remove_local_field_group( 'group_minn_v046_rev' );
		}
	}
} )();

// --- #7 (05-1) The database browser leaves out a table its plugin's own
// viewer rule keeps the viewer from ---
// The System page's caps (manage_options, view_site_health_checks) are not
// each plugin's own rule: an administrator a gift card plugin's privilege, an
// activity log's "only admins" or a mail log's capability refuses read every
// card number, event and message in the browser. The role here holds the
// System page's caps without WordPress's administrator role or
// manage_woocommerce.
( function () use ( $check, $skip, $call, $admin, $make_user, $drop_user ) {
	global $wpdb;
	// WP Activity Log keeps the first user it saw for the whole process.
	$become = function ( $id ) {
		wp_set_current_user( $id );
		if ( class_exists( '\\WSAL\\Helpers\\User_Helper' ) && property_exists( '\\WSAL\\Helpers\\User_Helper', 'current_user' ) ) {
			$ref = new ReflectionProperty( '\\WSAL\\Helpers\\User_Helper', 'current_user' );
			$ref->setAccessible( true );
			$ref->setValue( null, null );
		}
	};
	if ( is_multisite() ) {
		$skip( '#7 a network: only its owner reaches the browser, run this part on a single site' );
		return;
	}
	if ( ! class_exists( 'Minn_Admin_DB' ) || ! class_exists( 'Minn_Admin_Kernel' ) ) {
		$skip( '#7 database browser not loaded' );
		return;
	}
	$role = 'minn_v046_sysadmin';
	remove_role( $role );
	add_role( $role, 'Minn v046 system admin', array( 'read' => true, 'edit_posts' => true, 'manage_options' => true, 'install_plugins' => true ) );
	$uid = $make_user( 'minn_v046_sys', $role );
	$wpml_was = array_key_exists( 'wpml_settings', $GLOBALS ) ? $GLOBALS['wpml_settings'] : null;
	try {
		if ( ! $uid ) {
			$check( '#7 setup', false, 'no user' );
			return;
		}
		$exists = function ( $base ) use ( $wpdb ) {
			$t = $wpdb->prefix . $base;
			return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ? $t : '';
		};
		$listed = function () use ( $call ) {
			list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/tables', null, array( 'all' => true ) );
			return 200 === $st ? array_map( 'strtolower', array_column( (array) ( $d['tables'] ?? array() ), 'name' ) ) : null;
		};
		// Out of the list, and 404 for its rows, one row and its structure.
		$hidden = function ( $t ) use ( $call, $listed ) {
			$names = $listed();
			list( $a ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $t ) );
			list( $b ) = $call( 'GET', '/minn-admin/v1/db/structure', null, array( 'table' => $t ) );
			list( $c ) = $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $t, 'pk' => wp_json_encode( array( 'id' => 1 ) ) ) );
			return is_array( $names ) && ! in_array( strtolower( $t ), $names, true ) && 404 === $a && 404 === $b && 404 === $c;
		};
		$shown = function ( $t ) use ( $call, $listed ) {
			$names = $listed();
			list( $a ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $t ) );
			return is_array( $names ) && in_array( strtolower( $t ), $names, true ) && 200 === $a;
		};

		$become( $uid );
		if ( ! Minn_Admin_REST::can_read_system() ) {
			$skip( '#7 the probe role cannot open the System page here (file mods off?)' );
			return;
		}

		// PW Gift Cards: every screen at PWGC_REQUIRES_PRIVILEGE.
		$pw = $exists( 'pimwick_gift_card' );
		if ( ! $pw || ! function_exists( 'minn_admin_pwgc_can' ) ) {
			$skip( '#7 PW Gift Cards has no table here' );
		} elseif ( minn_admin_pwgc_can() ) {
			$skip( '#7 PW Gift Cards admits the probe role here' );
		} else {
			$act = $exists( 'pimwick_gift_card_activity' );
			$check( "#7 PW Gift Cards' cards are left out for a role its privilege refuses", $hidden( $pw ) && ( ! $act || $hidden( $act ) ) );
			$become( $admin );
			$check( '#7 PW Gift Cards control: an administrator it admits still browses them', $shown( $pw ) );
			$become( $uid );
		}

		// WP Activity Log: its own "who may view" setting.
		$ws = $exists( 'wsal_occurrences' );
		if ( ! $ws || ! function_exists( 'minn_admin_wsal_can_view' ) || ! defined( 'WSAL_VERSION' ) ) {
			$skip( '#7 WP Activity Log inactive' );
		} elseif ( minn_admin_wsal_can_view() ) {
			$skip( '#7 WP Activity Log admits the probe role here' );
		} else {
			list( $own ) = $call( 'GET', '/minn-admin/v1/wsal/events' );
			$meta = $exists( 'wsal_metadata' );
			$check( "#7 WP Activity Log's events and their details are left out where its own view refuses (Minn's: {$own})", 403 === $own && $hidden( $ws ) && ( ! $meta || $hidden( $meta ) ) );
			$become( $admin );
			$check( '#7 WP Activity Log control: an administrator it admits still browses them', $shown( $ws ) );
			$become( $uid );
		}

		// WP Mail Logging: the capability its "can see submission data" names.
		$ml = $exists( 'wpml_mails' );
		if ( ! $ml || ! function_exists( 'minn_admin_wpml_can' ) || ! minn_admin_wpml_active() ) {
			$skip( '#7 WP Mail Logging inactive' );
		} else {
			minn_admin_wpml_can(); // loads their settings into $wpml_settings
			$check( '#7 WP Mail Logging control: its default capability (manage_options) lets the probe role browse its mails', minn_admin_wpml_can() && $shown( $ml ) );
			$GLOBALS['wpml_settings'] = array_merge( (array) ( $GLOBALS['wpml_settings'] ?? array() ), array( 'can-see-submission-data' => 'manage_woocommerce' ) );
			$check( '#7 WP Mail Logging: raise its capability and its mails are left out', ! minn_admin_wpml_can() && $hidden( $ml ) );
		}

		// Activity Log (Aryo): its role scoping, whole or not at all.
		$ar = $exists( 'aryo_activity_log' );
		if ( ! $ar || ! function_exists( 'minn_admin_aryo_scope' ) ) {
			$skip( '#7 Activity Log (Aryo) has no table here' );
		} else {
			$check( "#7 Activity Log (Aryo): a role its scoping keeps from other roles' rows does not browse the table", $hidden( $ar ) );
			$become( $admin );
			$check( '#7 Activity Log (Aryo) control: an administrator it shows everything still browses it', $shown( $ar ) );
			$become( $uid );
		}

		// Every kernel resource over a table of its own: a list that refuses
		// this role is a table the browser leaves out.
		$disagree = array();
		$n        = 0;
		$names    = $listed();
		foreach ( Minn_Admin_Kernel::resources() as $id => $res ) {
			$table = $res['source']['table'] ?? null;
			if ( 0 === strpos( $id, 'minn-conformance/' ) || ! is_string( $table ) || in_array( $table, array( 'posts', 'postmeta', 'users', 'usermeta', 'options', 'comments' ), true ) ) {
				continue;
			}
			$full = ( empty( $res['source']['network'] ) ? $wpdb->prefix : $wpdb->base_prefix ) . $table;
			if ( 0 !== strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ), $full ) ) {
				continue;
			}
			$n++;
			$gate = Minn_Admin_Kernel::yes( $res['gates']['view'] ?? null );
			if ( ! $gate && is_array( $names ) && in_array( strtolower( $full ), $names, true ) ) {
				$disagree[] = $id;
			}
		}
		$check( "#7 every resource whose own list refuses the role leaves its table out of the browser ({$n} tables)", $n > 0 && ! $disagree, implode( ', ', $disagree ) );

		// A plugin that cannot say who may read its log (inactive here) does
		// not have its log shown.
		foreach ( array( 'simple_history' => 'Simple_History\\Simple_History', 'stream' => 'WP_Stream\\Plugin' ) as $base => $class ) {
			$t = $exists( $base );
			if ( ! $t || class_exists( $class ) ) {
				$skip( "#7 {$base}: no table, or its plugin is active" );
				continue;
			}
			$become( $admin );
			$check( "#7 {$base}: with its plugin inactive nobody can be told it may read the log, so the table is left out", $hidden( $t ) );
			$become( $uid );
		}

		// WooCommerce's rule is unchanged.
		$wc = $exists( 'wc_orders' );
		if ( $wc ) {
			$check( "#7 control: WooCommerce's tables stay out for a role without manage_woocommerce", $hidden( $wc ) );
		}
		// A table nobody's rule names stays.
		$become( $uid );
		$check( "#7 control: a core table and a plugin's unruled table stay for the System page's role", $shown( $wpdb->posts ) );
	} finally {
		$become( $admin );
		if ( null === $wpml_was ) {
			unset( $GLOBALS['wpml_settings'] );
		} else {
			$GLOBALS['wpml_settings'] = $wpml_was;
		}
		$drop_user( $uid );
		remove_role( $role );
	}
} )();

// --- #3 (03-1) A network capability someone holds without owning the
// network is refused before anything irreversible runs ---
// Core admits a main-site role holding delete_sites (or the network plugin,
// theme and options caps) to Network Admin, and Minn admitted it to these
// routes; the write fence then refused the follow-on writes after the site
// was gone or the plugin's network activation had run.
( function () use ( $check, $skip, $call, $admin, $make_user, $drop_user ) {
	if ( ! is_multisite() || ! function_exists( 'minn_admin_network_can_delete_sites' ) ) {
		$skip( '#3 not a network: run this part on a network\'s main site' );
		return;
	}
	if ( ! is_main_site() ) {
		$skip( '#3 run on the main site' );
		return;
	}
	$role = 'minn_v046_netrole';
	remove_role( $role );
	add_role( $role, 'Minn v046 network role', array_fill_keys( array( 'read', 'edit_posts', 'manage_options', 'activate_plugins', 'manage_sites', 'delete_sites', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'manage_network_users' ), true ) );
	$holder  = $make_user( 'minn_v046_net', $role );
	$target  = $make_user( 'minn_v046_tgt', 'subscriber' );
	$site    = 0;
	$plugin  = 'hello-dolly/hello.php';
	$theme   = '';
	$fired   = array();
	$listen  = function () use ( &$fired ) {
		$fired[] = current_action();
	};
	$allowed_was = get_site_option( 'allowedthemes' );
	try {
		if ( ! $holder || ! $target ) {
			$check( '#3 setup', false, 'no users' );
			return;
		}
		$net  = get_network();
		$slug = 'minnv046' . strtolower( wp_generate_password( 6, false, false ) );
		$made = wp_insert_site( array(
			'domain'  => is_subdomain_install() ? $slug . '.' . $net->domain : $net->domain,
			'path'    => is_subdomain_install() ? '/' : $net->path . $slug . '/',
			'title'   => 'Minn v046 throwaway',
			'user_id' => $admin,
		) );
		$site = is_wp_error( $made ) ? 0 : (int) $made;
		foreach ( wp_get_themes() as $stylesheet => $t ) {
			$on = (array) get_site_option( 'allowedthemes', array() );
			if ( empty( $on[ $stylesheet ] ) ) {
				$theme = $stylesheet;
				break;
			}
		}
		foreach ( array( 'activate_plugin', 'deactivate_plugin', 'grant_super_admin', 'revoke_super_admin', 'wp_uninitialize_site', 'wp_delete_site' ) as $hook ) {
			add_action( $hook, $listen );
		}

		wp_set_current_user( $holder );
		$check( '#3 the probe account is not the network\'s owner', ! Minn_Admin::network_owner() && current_user_can( 'delete_sites' ) );
		if ( $site ) {
			list( $st, $d ) = $call( 'DELETE', '/minn-admin/v1/network/sites/' . $site );
			$check( '#3 Delete site is refused before the site is touched', 403 === $st && 'rest_forbidden' === ( $d['code'] ?? '' ) && get_site( $site ) && ! array_intersect( array( 'wp_uninitialize_site', 'wp_delete_site' ), $fired ), $st . ' ' . ( $d['code'] ?? '' ) . ' site=' . ( get_site( $site ) ? 'kept' : 'GONE' ) . ' ' . implode( ',', $fired ) );
		} else {
			$check( '#3 setup: a throwaway site', false, is_wp_error( $made ) ? $made->get_error_message() : '' );
		}
		if ( isset( get_plugins()[ $plugin ] ) && ! is_plugin_active_for_network( $plugin ) ) {
			$fired = array();
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/network/plugins/activate', array( 'plugin' => $plugin, 'on' => true ) );
			$check( "#3 network activation is refused before the plugin's activation runs", 403 === $st && 'rest_forbidden' === ( $d['code'] ?? '' ) && ! in_array( 'activate_plugin', $fired, true ) && ! is_plugin_active_for_network( $plugin ), $st . ' ' . ( $d['code'] ?? '' ) . ' ' . implode( ',', $fired ) );
		} else {
			$skip( '#3 Hello Dolly is not installed, or is network active' );
		}
		if ( '' !== $theme ) {
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/network/themes/enable', array( 'theme' => $theme, 'on' => true ) );
			$on = (array) get_site_option( 'allowedthemes', array() );
			$check( '#3 making a theme available to the network is refused up front', 403 === $st && 'rest_forbidden' === ( $d['code'] ?? '' ) && empty( $on[ $theme ] ), $st . ' ' . ( $d['code'] ?? '' ) );
		} else {
			$skip( '#3 every theme is already network enabled' );
		}
		$fired = array();
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/network/users/' . $target . '/super', array( 'on' => true ) );
		$check( '#3 granting network administrator is refused before core announces the grant', 403 === $st && 'rest_forbidden' === ( $d['code'] ?? '' ) && ! is_super_admin( $target ) && ! in_array( 'grant_super_admin', $fired, true ), $st . ' ' . ( $d['code'] ?? '' ) . ' ' . implode( ',', $fired ) );
		$offered = array();
		foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
			if ( isset( $s['id'] ) && 'network-sites' === $s['id'] ) {
				foreach ( (array) ( $s['collection']['actions'] ?? array() ) as $a ) {
					$offered[] = strtoupper( (string) ( $a['method'] ?? 'POST' ) ) . ' ' . ( $a['route'] ?? '' );
				}
			}
		}
		$check( '#3 the Sites list does not offer the account Delete', ! in_array( 'DELETE minn-admin/v1/network/sites/{id}', $offered, true ), implode( ' | ', $offered ) );
		$check( '#3 the gates the Extensions switches ask say no', ! minn_admin_network_can_activate_plugins() && ! minn_admin_network_can_enable_themes() && ! minn_admin_network_can_grant_super() && ! minn_admin_network_can_delete_sites() );

		wp_set_current_user( $admin );
		$check( "#3 control: the network's owner passes every one of these gates", minn_admin_network_can_activate_plugins() && minn_admin_network_can_enable_themes() && minn_admin_network_can_grant_super() && minn_admin_network_can_delete_sites() );
		if ( $site ) {
			list( $st ) = $call( 'DELETE', '/minn-admin/v1/network/sites/' . $site );
			$check( '#3 control: the owner deletes the throwaway site', 200 === $st && ! get_site( $site ), (string) $st );
			if ( ! get_site( $site ) ) {
				$site = 0;
			}
		}
	} finally {
		foreach ( array( 'activate_plugin', 'deactivate_plugin', 'grant_super_admin', 'revoke_super_admin', 'wp_uninitialize_site', 'wp_delete_site' ) as $hook ) {
			remove_action( $hook, $listen );
		}
		wp_set_current_user( $admin );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( is_plugin_active_for_network( $plugin ) ) {
			deactivate_plugins( $plugin, true, true );
		}
		if ( $target && is_super_admin( $target ) ) {
			revoke_super_admin( $target );
		}
		if ( false === $allowed_was ) {
			delete_site_option( 'allowedthemes' );
		} else {
			update_site_option( 'allowedthemes', $allowed_was );
		}
		if ( $site && get_site( $site ) ) {
			wp_delete_site( $site );
		}
		// A delete the fence stopped halfway (the pre-fix shape) leaves its
		// members' role rows for the gone id.
		if ( $site && ! get_site( $site ) ) {
			global $wpdb;
			foreach ( array( 'capabilities', 'user_level' ) as $k ) {
				delete_metadata( 'user', 0, $wpdb->get_blog_prefix( $site ) . $k, '', true );
			}
		}
		$drop_user( $holder );
		$drop_user( $target );
		remove_role( $role );
	}
} )();

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nsecurity-v046-registry-reads: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
