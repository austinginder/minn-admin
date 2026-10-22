<?php
/**
 * v0.47.0 RC audit, the low findings.
 *
 * - Post types and taxonomies (04-L2): a definition save with no stamp is
 *   a stale copy (409), as Minn_Admin::stale() reads one.
 * - Everest Forms (06-L1): a role holding only "view others' entries" no
 *   longer lists entries of its own forms.
 * - Gravity Forms (09-L3): the entry list never carries a password field.
 * - LatePoint (07-L3): a booking's customer notes need customer__view.
 * - Elementor templates (07-L5): a copy for someone without unfiltered_html
 *   leaves out a value Elementor's kses could not filter.
 * - JetEngine (07-L4): a raw-format textarea is kses'd for someone without
 *   unfiltered_html in the panel, and is a raw field on options pages.
 * - Store settings (10-F4, already held): a gateway save carrying a mask
 *   placeholder is refused and stores nothing. The egress fence keeps every
 *   credential row (gateway settings among them) as stored when a write
 *   would put a placeholder in it, so this pins that behaviour.
 * - The engine switch (05-I4): its status answers only the person who ran
 *   the check.
 * - One Time Login (08-L5): on a secondary network, a main-network super
 *   admin is not a target. Runs only there:
 *     --url=https://lab2.minnms.localhost/ --user=minn-net2-admin
 * - WP Freighter (10-F2): the per-tenant Minn switch needs activate_plugins.
 *   Runs only where Freighter is active (wpfreighter.localhost).
 *
 * Sections SKIP when their plugin or install shape is not here. What is
 * made is removed however the run ends.
 *
 * Run: wp eval-file tests/security-v047-rc-lows.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

require_once __DIR__ . '/lib/signals.php';

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
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
$once = function ( callable $fn ) {
	$done = false;
	$run  = function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};
// Withhold unfiltered_html from the current user for a section.
$no_unfiltered = function ( $caps, $cap ) {
	return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
};

$admin = get_current_user_id();
$tag   = (string) wp_rand( 1000, 9999 );
rest_get_server();

/*
 * 04-L2: a definition save with no stamp is stale.
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag ) {
	if ( ! class_exists( 'Minn_Admin_CPT' ) || ! current_user_can( 'manage_options' ) ) {
		$skip( 'definitions: run as an administrator' );
		return;
	}
	wp_set_current_user( $admin );
	$slug = 'minnrc' . $tag;
	$made = false;
	$clean = $once( function () use ( $call, &$made, $slug ) {
		if ( $made ) {
			$call( 'DELETE', "/minn-admin/v1/post-types/{$slug}" );
		}
	} );
	try {
		list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $slug, 'singular' => 'Rc', 'plural' => 'Rcs', 'public' => 1, 'show_in_rest' => 1, 'supports' => array( 'title' ), 'backend' => 'minn' ) );
		$made = 200 === $st;
		if ( post_type_exists( $slug ) ) {
			unregister_post_type( $slug );
		}
		Minn_Admin_CPT::register_native_types();
		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'description' => 'no stamp' ) );
		$check( 'definitions: a save with no stamp answers as stale (409)', 409 === $st, (string) $st );
		list( , $d ) = $call( 'GET', '/minn-admin/v1/post-types' );
		$stamp = '';
		foreach ( (array) ( $d['types'] ?? array() ) as $r ) {
			if ( $slug === ( $r['slug'] ?? '' ) ) {
				$stamp = (string) ( $r['stamp'] ?? '' );
			}
		}
		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'description' => 'stamped', 'base' => $stamp ) );
		$check( 'definitions: control: a stamped save goes through', 200 === $st, $st . ' stamp=' . $stamp );
	} finally {
		$clean();
	}
} )();

/*
 * 06-L1: an others-only Everest role does not list its own forms' entries.
 */
( function () use ( $check, $skip, $once, $admin, $tag ) {
	if ( ! function_exists( 'minn_admin_everest_allowed_form_ids' ) || ! post_type_exists( 'everest_form' ) ) {
		$skip( 'Everest Forms: inactive' );
		return;
	}
	$role  = 'minn_rc_evf_' . $tag;
	add_role( $role, 'Minn RC EVF', array( 'read' => true, 'everest_forms_view_others_entries' => true ) );
	$user  = wp_insert_user( array( 'user_login' => "minn-rc-evf-{$tag}", 'user_email' => "minn-rc-evf-{$tag}@example.com", 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	$own   = wp_insert_post( array( 'post_type' => 'everest_form', 'post_status' => 'publish', 'post_title' => "Minn RC own {$tag}", 'post_author' => (int) $user ) );
	$other = wp_insert_post( array( 'post_type' => 'everest_form', 'post_status' => 'publish', 'post_title' => "Minn RC other {$tag}", 'post_author' => $admin ) );
	$clean = $once( function () use ( $own, $other, $user, $role, $admin ) {
		wp_set_current_user( $admin );
		wp_delete_post( $own, true );
		wp_delete_post( $other, true );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_int( $user ) && $user > 1 ) {
			is_multisite() ? wpmu_delete_user( $user ) : wp_delete_user( $user );
		}
		remove_role( $role );
	} );
	try {
		wp_set_current_user( (int) $user );
		$ids = minn_admin_everest_allowed_form_ids();
		$check( 'Everest Forms: an others-only role is scoped to other people\'s forms', is_array( $ids ) && ! in_array( (int) $own, $ids, true ) && in_array( (int) $other, $ids, true ), wp_json_encode( $ids ) );
	} finally {
		$clean();
	}
} )();

/*
 * 09-L3: the Gravity Forms entry list leaves out password fields.
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		$skip( 'Gravity Forms: inactive' );
		return;
	}
	wp_set_current_user( $admin );
	$form_id = GFAPI::add_form( array(
		'title'  => "Minn RC password {$tag}",
		'fields' => array(
			array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ),
			array( 'id' => 2, 'type' => 'password', 'label' => 'Password' ),
		),
	) );
	$clean = $once( function () use ( $form_id ) {
		if ( is_int( $form_id ) ) {
			GFAPI::delete_form( $form_id );
		}
	} );
	try {
		if ( ! is_int( $form_id ) ) {
			$skip( 'Gravity Forms: could not make a form' );
			return;
		}
		GFAPI::add_entry( array( 'form_id' => $form_id, '1' => 'Robin', '2' => 'hunter2-minn-rc' ) );
		list( $st, $d ) = $call( 'GET', "/minn-admin/v1/gf/forms/{$form_id}/entries" );
		$json = wp_json_encode( $d );
		$check( 'Gravity Forms: the entry list never carries a password', 200 === $st && false === strpos( $json, 'hunter2-minn-rc' ), $st . ' ' . substr( $json, 0, 200 ) );
		$check( 'Gravity Forms: control: other answers are listed', false !== strpos( $json, 'Robin' ) );
	} finally {
		$clean();
	}
} )();

/*
 * 07-L3: a booking's customer notes need customer__view.
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag ) {
	global $wpdb;
	if ( ! class_exists( 'OsRolesHelper' ) || ! function_exists( 'minn_admin_latepoint_detail' ) ) {
		$skip( 'LatePoint: inactive' );
		return;
	}
	wp_set_current_user( $admin );
	$row = $wpdb->get_row( "SELECT b.id, b.customer_id, c.admin_notes FROM {$wpdb->prefix}latepoint_bookings b JOIN {$wpdb->prefix}latepoint_customers c ON c.id = b.customer_id ORDER BY b.id LIMIT 1" );
	if ( ! $row ) {
		$skip( 'LatePoint: no booking to read' );
		return;
	}
	$note  = "Minn RC internal note {$tag}";
	$deny  = function ( $can, $capability ) {
		return in_array( 'customer__view', (array) $capability, true ) ? false : $can;
	};
	$clean = $once( function () use ( $wpdb, $row, $deny ) {
		remove_filter( 'latepoint_user_has_capability', $deny, 99 );
		$wpdb->update( $wpdb->prefix . 'latepoint_customers', array( 'admin_notes' => $row->admin_notes ), array( 'id' => (int) $row->customer_id ) );
	} );
	try {
		$wpdb->update( $wpdb->prefix . 'latepoint_customers', array( 'admin_notes' => $note ), array( 'id' => (int) $row->customer_id ) );
		list( , $d ) = $call( 'GET', '/minn-admin/v1/latepoint/bookings/' . (int) $row->id );
		$check( 'LatePoint: control: with customer__view the notes show', false !== strpos( wp_json_encode( $d ), $note ) );
		add_filter( 'latepoint_user_has_capability', $deny, 99, 2 );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/latepoint/bookings/' . (int) $row->id );
		$check( 'LatePoint: without customer__view the booking reads without the notes', 200 === $st && false === strpos( wp_json_encode( $d ), $note ), (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * 07-L5: an Elementor template copy leaves out a value kses could not filter.
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag, $no_unfiltered ) {
	if ( ! class_exists( '\Elementor\Plugin' ) || ! post_type_exists( 'elementor_library' ) ) {
		$skip( 'Elementor templates: inactive' );
		return;
	}
	wp_set_current_user( $admin );
	$data = wp_json_encode( array( array( 'id' => 'a1', 'elType' => 'widget', 'widgetType' => 'html', 'settings' => array( 'html' => '<script>window.minnRc=1</script>' ), 'elements' => array() ) ) );
	$src  = wp_insert_post( array( 'post_type' => 'elementor_library', 'post_status' => 'publish', 'post_title' => "Minn RC template {$tag}" ) );
	update_post_meta( $src, '_elementor_template_type', 'page' );
	update_post_meta( $src, '_elementor_edit_mode', 'builder' );
	update_post_meta( $src, '_elementor_data', wp_slash( $data ) );
	$made  = array( $src );
	// Throws on the planted value only: Elementor's own create runs kses too.
	$throw = function ( $content ) {
		if ( is_string( $content ) && false !== strpos( $content, 'minnRc' ) ) {
			throw new RuntimeException( 'kses failed' );
		}
		return $content;
	};
	$clean = $once( function () use ( &$made, $no_unfiltered, $throw, $tag ) {
		remove_filter( 'map_meta_cap', $no_unfiltered, 99 );
		remove_filter( 'pre_kses', $throw, 1 );
		// A copy made before a failure answered has no id in the reply.
		$copies = get_posts( array( 'post_type' => 'elementor_library', 'post_status' => 'any', 'numberposts' => 10, 'fields' => 'ids', 's' => "Minn RC template {$tag}" ) );
		foreach ( array_unique( array_merge( $made, $copies ) ) as $p ) {
			wp_delete_post( (int) $p, true );
		}
	} );
	try {
		add_filter( 'map_meta_cap', $no_unfiltered, 99, 2 );
		add_filter( 'pre_kses', $throw, 1 );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/elementor/templates/{$src}/duplicate" );
		remove_filter( 'pre_kses', $throw, 1 );
		remove_filter( 'map_meta_cap', $no_unfiltered, 99 );
		$copy   = (int) ( $d['id'] ?? 0 );
		$made[] = $copy;
		$stored = $copy ? (string) get_post_meta( $copy, '_elementor_data', true ) : '';
		$check( 'Elementor templates: a value kses could not filter is not copied raw', $copy > 0 && false === strpos( $stored, '<script>' ), $st . ' ' . substr( $stored, 0, 120 ) );
	} finally {
		$clean();
	}
} )();

/*
 * 07-L4: JetEngine raw textareas.
 */
( function () use ( $check, $skip, $admin, $no_unfiltered ) {
	if ( ! function_exists( 'minn_admin_jet_value_in' ) || ! function_exists( 'minn_admin_jet_options_layout' ) ) {
		$skip( 'JetEngine: adapter not loaded' );
		return;
	}
	wp_set_current_user( $admin );
	$f = array( 'name' => 'minn_rc_notes', 'type' => 'textarea', '_jet' => array( 'type' => 'textarea', 'value_format' => 'raw' ) );
	add_filter( 'map_meta_cap', $no_unfiltered, 99, 2 );
	$in = minn_admin_jet_value_in( $f, 'Hi <script>alert(1)</script> there' );
	remove_filter( 'map_meta_cap', $no_unfiltered, 99 );
	$check( 'JetEngine: a raw textarea from someone without unfiltered_html is kses\'d', is_string( $in ) && false === stripos( $in, '<script' ), (string) $in );
	$page   = (object) array( 'meta_box' => array( array( 'name' => 'minn_rc_notes', 'title' => 'Notes', 'type' => 'textarea', 'object_type' => 'field' ) ) );
	$layout = minn_admin_jet_options_layout( $page, 'minn-rc-options' );
	$field  = $layout['groups'][0]['fields'][0] ?? array();
	$check( 'JetEngine: an options page\'s raw textarea is a raw field (unfiltered_html and code edits)', ! empty( $field['_raw'] ), wp_json_encode( array_diff_key( $field, array( '_jet' => 1 ) ) ) );
} )();

/*
 * 10-F4: a gateway save carrying a mask placeholder is refused.
 */
( function () use ( $check, $skip, $call, $once, $admin ) {
	if ( ! function_exists( 'WC' ) || ! function_exists( 'minn_admin_wc_settings_gateway' ) || ! minn_admin_wc_settings_gateway( 'cheque' ) ) {
		$skip( 'store settings: WooCommerce or its cheque gateway is not here' );
		return;
	}
	wp_set_current_user( $admin );
	$was   = get_option( 'woocommerce_cheque_settings', null );
	$clean = $once( function () use ( $was ) {
		null === $was ? delete_option( 'woocommerce_cheque_settings' ) : update_option( 'woocommerce_cheque_settings', $was );
	} );
	try {
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/wc/payment_gateways/cheque', array( 'values' => array( 'description' => 'Pay with [redacted:1a2b3c] here' ) ) );
		$now = (array) get_option( 'woocommerce_cheque_settings', array() );
		$check( 'store settings: a save carrying a mask placeholder is refused and stores nothing', 400 === $st && 0 === strpos( (string) ( $d['code'] ?? '' ), 'minn_egress_mask' ) && false === strpos( (string) ( $now['description'] ?? '' ), '[redacted:' ), $st . ' ' . wp_json_encode( $d ) );
	} finally {
		$clean();
	}
} )();

/*
 * 05-I4: the switch's status answers only the person who ran the check.
 */
( function () use ( $check, $skip, $call, $once, $admin ) {
	if ( ! class_exists( 'Minn_Admin_Switch' ) || ! Minn_Admin_Switch::can() ) {
		$skip( 'engine switch: not offered to this user here' );
		return;
	}
	wp_set_current_user( $admin );
	$token = wp_generate_password( 32, false );
	$clean = $once( function () use ( $token ) {
		delete_transient( 'minn_switch_' . $token );
	} );
	try {
		set_transient( 'minn_switch_' . $token, array( 'user' => $admin + 100000, 'state' => 'checked', 'dir' => '/nonexistent', 'engine' => '/nonexistent', 'light' => 'GREEN' ), 60 );
		list( $st ) = $call( 'GET', '/minn-admin/v1/minn-switch/status', null, array( 'token' => $token ) );
		$check( 'engine switch: someone else\'s check is not known here', 404 === $st, (string) $st );
		set_transient( 'minn_switch_' . $token, array( 'user' => $admin, 'state' => 'checked', 'dir' => '/nonexistent', 'engine' => '/nonexistent', 'light' => 'GREEN' ), 60 );
		list( $st ) = $call( 'GET', '/minn-admin/v1/minn-switch/status', null, array( 'token' => $token ) );
		$check( 'engine switch: control: your own check answers', 200 === $st, (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * 08-L5: on a secondary network, a main-network super admin is no target.
 */
( function () use ( $check, $skip, $call ) {
	if ( ! is_multisite() || is_main_network() || ! function_exists( 'minn_admin_otl_active' ) || ! minn_admin_otl_active() ) {
		$skip( 'One Time Login: runs on a secondary network with One Time Login active' );
		return;
	}
	if ( Minn_Admin::install_owner() || ! is_super_admin() ) {
		$skip( 'One Time Login: run as a secondary network\'s super admin' );
		return;
	}
	$owners = (array) get_network_option( get_main_network_id(), 'site_admins', array() );
	$target = $owners ? get_user_by( 'login', (string) reset( $owners ) ) : null;
	if ( ! $target ) {
		$skip( 'One Time Login: no main-network super admin found' );
		return;
	}
	$had = get_user_meta( (int) $target->ID, 'one_time_login_token', false );
	list( $st ) = $call( 'POST', '/minn-admin/v1/otl/' . (int) $target->ID );
	$check( 'One Time Login: a secondary network\'s super admin cannot mint a link for the install\'s owner', 403 === $st, (string) $st );
	// On code that lets it through, a live sign-in link now exists: take it
	// back to what the account held before.
	if ( 403 !== $st ) {
		delete_user_meta( (int) $target->ID, 'one_time_login_token' );
		foreach ( (array) $had as $v ) {
			add_user_meta( (int) $target->ID, 'one_time_login_token', $v );
		}
	}
} )();

/*
 * 10-F2: the per-tenant Minn switch needs activate_plugins.
 */
( function () use ( $check, $skip, $call, $once, $tag ) {
	if ( ! function_exists( 'minn_admin_freighter_can' ) || ! minn_admin_freighter_can() ) {
		$skip( 'WP Freighter: not active here' );
		return;
	}
	$sites = class_exists( '\WPFreighter\Sites' ) ? (array) \WPFreighter\Sites::fetch() : array();
	$site  = $sites ? reset( $sites ) : null;
	$id    = is_array( $site ) ? (int) ( $site['stacked_site_id'] ?? 0 ) : ( is_object( $site ) ? (int) ( $site->stacked_site_id ?? 0 ) : 0 );
	if ( ! $id ) {
		$skip( 'WP Freighter: no tenant to address' );
		return;
	}
	$role  = 'minn_rc_fr_' . $tag;
	add_role( $role, 'Minn RC freighter', array( 'read' => true, 'manage_options' => true ) );
	$user  = wp_insert_user( array( 'user_login' => "minn-rc-fr-{$tag}", 'user_email' => "minn-rc-fr-{$tag}@example.com", 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	$prev  = get_current_user_id();
	$clean = $once( function () use ( $user, $role, $prev ) {
		wp_set_current_user( $prev );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_int( $user ) && $user > 1 ) {
			wp_delete_user( $user );
		}
		remove_role( $role );
	} );
	try {
		wp_set_current_user( (int) $user );
		// The body asks for what the tenant has now, so even a run on code
		// that lets it through changes nothing.
		list( , $d ) = $call( 'GET', '/minn-admin/v1/freighter/sites' );
		$on = true;
		foreach ( (array) ( $d['items'] ?? $d ) as $s ) {
			if ( is_array( $s ) && (int) ( $s['id'] ?? 0 ) === $id && isset( $s['minn'] ) ) {
				$on = (bool) $s['minn'];
			}
		}
		list( $st ) = $call( 'POST', "/minn-admin/v1/freighter/sites/{$id}/minn", array( 'on' => $on ) );
		$check( 'WP Freighter: manage_options without activate_plugins cannot switch Minn for a tenant', 403 === $st, (string) $st );
	} finally {
		$clean();
	}
} )();

wp_set_current_user( $admin );
$failed = count( array_filter( $results, function ( $ok ) {
	return ! $ok;
} ) );
echo "\nsecurity-v047-rc-lows: " . ( count( $results ) - $failed ) . '/' . count( $results ) . " passed\n";
if ( $failed ) {
	exit( 1 );
}
