<?php
/**
 * Regressions for the v0.44.0 release-candidate audit.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what got stored or what
 * came back, with a control where a fix could over-block. Sections SKIP when
 * their plugin is inactive, and the multisite ones SKIP on a single site.
 *
 * Run: wp eval-file tests/security-v044-rc.test.php --user=admin --path=<site> [--url=<subsite>]
 *
 * @package minn-admin
 */

// ACPT's WPAttachment.php calls set_time_limit( 5 ) at file scope, so once a
// section autoloads it every later section would have five seconds left.
// Each check and skip lifts the limit again.
$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v044-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
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
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// --- 03-04 Bricks: a key Bricks itself treats as valid stays -----------------
// Bricks counts processed, canceled (not refunded) and past_due licences as
// valid. Minn's restore-on-refusal used to treat everything but "active" as a
// refusal, so such a key was put back to the previous one, or removed.
if ( ! class_exists( '\Bricks\License' ) || ! function_exists( 'minn_admin_license_default_providers' ) ) {
	$skip( '03-04 Bricks inactive' );
} else {
	// The real key and status are snapshotted and put back exactly; the
	// Bricks licence server never hears from this section.
	$brx4_prev   = get_option( 'bricks_license_key', null );
	$brx4_status = get_option( '_transient_bricks_license_status', null );
	$brx4_tout   = get_option( '_transient_timeout_bricks_license_status', null );
	$brx4_answer = 'active';
	$brx4_http   = function ( $pre, $args, $url ) use ( &$brx4_answer ) {
		if ( 'my.bricksbuilder.io' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'status' => $brx4_answer ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return new WP_Error( 'minn_test_offline', 'offline' );
	};
	add_filter( 'pre_http_request', $brx4_http, PHP_INT_MAX, 3 );
	$brx4_row = function () use ( $call ) {
		list( , $d ) = $call( 'GET', '/minn-admin/v1/licenses' );
		foreach ( (array) ( $d['items'] ?? $d ) as $it ) {
			if ( is_array( $it ) && 'Bricks' === ( $it['name'] ?? '' ) ) {
				return $it;
			}
		}
		return null;
	};
	foreach ( array( 'processed', 'canceled', 'past_due' ) as $brx4_word ) {
		delete_option( 'bricks_license_key' );
		delete_transient( 'bricks_license_status' );
		$brx4_answer = $brx4_word;
		list( $brx4_st, $brx4_body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'bricks', 'action' => 'activate', 'secret' => 'minntestbricks' . $brx4_word ) );
		$brx4_key = get_option( 'bricks_license_key', null );
		$check( "03-04 Bricks: a {$brx4_word} licence activates and keeps its key", 200 === $brx4_st && ! empty( $brx4_body['ok'] ) && 'minntestbricks' . $brx4_word === $brx4_key, $brx4_st . ' ' . wp_json_encode( array_intersect_key( (array) $brx4_body, array_flip( array( 'ok', 'code', 'message' ) ) ) ) . ' key=' . ( null === $brx4_key ? 'absent' : 'kept' ) );
		$brx4_it = $brx4_row();
		$check( "03-04 Bricks: a {$brx4_word} licence reads valid", is_array( $brx4_it ) && 'valid' === ( $brx4_it['state'] ?? '' ), wp_json_encode( $brx4_it ) );
		list( , $brx4_v ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'bricks', 'action' => 'verify' ) );
		$check( "03-04 Bricks: re-verifying a {$brx4_word} licence succeeds", ! empty( $brx4_v['ok'] ), wp_json_encode( array_intersect_key( (array) $brx4_v, array_flip( array( 'ok', 'code', 'message' ) ) ) ) );
	}
	// Control: a refused key is still put back.
	update_option( 'bricks_license_key', 'minntestbricksworking' );
	set_transient( 'bricks_license_status', 'active', HOUR_IN_SECONDS );
	$brx4_answer = 'expired';
	list( , $brx4_body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'bricks', 'action' => 'activate', 'secret' => 'minntestbricksexpired' ) );
	$check( '03-04 Bricks control: an expired key is refused and the working one kept', empty( $brx4_body['ok'] ) && 'minntestbricksworking' === get_option( 'bricks_license_key', null ) && 'active' === get_transient( 'bricks_license_status' ), wp_json_encode( array_intersect_key( (array) $brx4_body, array_flip( array( 'ok', 'code', 'message' ) ) ) ) );

	remove_filter( 'pre_http_request', $brx4_http, PHP_INT_MAX );
	if ( null === $brx4_prev ) {
		delete_option( 'bricks_license_key' );
	} else {
		update_option( 'bricks_license_key', $brx4_prev );
	}
	foreach ( array( '_transient_bricks_license_status' => $brx4_status, '_transient_timeout_bricks_license_status' => $brx4_tout ) as $brx4_k => $brx4_v ) {
		if ( null === $brx4_v ) {
			delete_option( $brx4_k );
		} else {
			update_option( $brx4_k, $brx4_v, false );
		}
	}
	\Bricks\License::$license_key = $brx4_prev;
}

// --- 09-01 / 03-05 Multisite: System, logs, database and licences take a super admin ---
// manage_network_options maps to itself, so a role plugin can grant it without
// super admin. Core's Site Health refuses that account; Minn's System page, log
// viewer, database browser and licences answered it. Read-only probes: nothing
// here clears a log or touches a licence.
if ( ! is_multisite() ) {
	$skip( '09-01 multisite gates: single site' );
} else {
	$net_role = 'minn_netops_test';
	add_role( $net_role, 'Minn network settings test', array( 'read' => true, 'manage_network_options' => true ) );
	$net_login = 'minn-netops-' . wp_generate_password( 6, false, false );
	$net_id    = wp_insert_user( array( 'user_login' => $net_login, 'user_email' => $net_login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $net_role ) );
	if ( is_wp_error( $net_id ) ) {
		$check( '09-01 fixture: network settings account', false, $net_id->get_error_message() );
	} else {
		wp_set_current_user( $net_id );
		$check( '09-01 precondition: the account holds manage_network_options and is no super admin', current_user_can( 'manage_network_options' ) && ! is_super_admin(), '' );
		$check( '09-01 precondition: core\'s Site Health refuses it', ! current_user_can( 'view_site_health_checks' ), '' );
		global $wpdb;
		$net_probes = array(
			'the database browser\'s user table' => array( '/minn-admin/v1/db/rows', array( 'table' => $wpdb->base_prefix . 'users' ) ),
			'the database table list'           => array( '/minn-admin/v1/db/tables', array() ),
			'the System page'                   => array( '/minn-admin/v1/system', array() ),
			'the log sources'                   => array( '/minn-admin/v1/system/logs', array() ),
			'the licences'                      => array( '/minn-admin/v1/licenses', array() ),
		);
		foreach ( $net_probes as $net_label => $net_probe ) {
			list( $net_st ) = $call( 'GET', $net_probe[0], null, $net_probe[1] );
			$check( "09-01 multisite: a network settings account cannot read {$net_label}", in_array( $net_st, array( 401, 403 ), true ), (string) $net_st );
		}
		wp_set_current_user( $admin );
		if ( is_super_admin() ) {
			foreach ( array( 'the database table list' => '/minn-admin/v1/db/tables', 'the System page' => '/minn-admin/v1/system', 'the licences' => '/minn-admin/v1/licenses' ) as $net_label => $net_route ) {
				list( $net_st ) = $call( 'GET', $net_route );
				$check( "09-01 control: a super admin still reads {$net_label}", 200 === $net_st, (string) $net_st );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wpmu_delete_user( $net_id );
	}
	remove_role( $net_role );
	wp_set_current_user( $admin );
}

// --- 02-03 / 14-06 DB browser redacts the licences screen's key rows --------
// The licences screen reports these keys only as present; the database browser
// printed them whole and found them by value. Rows that already hold a real
// key are checked by shape only and never printed; absent ones are seeded with
// a tagged value and removed after.
if ( class_exists( 'Minn_Admin_DB' ) ) {
	global $wpdb;
	$dbl_tag  = 'mv44dbl' . wp_rand( 100000, 999999 );
	$dbl_rows = function ( $args ) use ( $call, $wpdb ) {
		list( , $res ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array_merge( array( 'table' => $wpdb->options, 'page' => 1, 'per_page' => 50 ), $args ) );
		return $res;
	};
	$dbl_cell = function ( $name ) use ( $dbl_rows ) {
		$res  = $dbl_rows( array( 'fcol' => 'option_name', 'fq' => $name ) );
		$cols = wp_list_pluck( (array) ( $res['columns'] ?? array() ), 'name' );
		$ki   = array_search( 'option_name', $cols, true );
		$vi   = array_search( 'option_value', $cols, true );
		foreach ( (array) ( $res['rows'] ?? array() ) as $row ) {
			if ( false !== $ki && false !== $vi && isset( $row[ $ki ] ) && $name === $row[ $ki ] ) {
				return $row[ $vi ];
			}
		}
		return null;
	};
	$dbl_seed = array(
		'envato_market'                               => array( 'token' => $dbl_tag . 'a_envato' ),
		'jetpack_secrets'                             => array( 'jetpack_register_1' => array( 'secret_1' => $dbl_tag . 'b_jp1', 'secret_2' => $dbl_tag . 'b_jp2', 'exp' => time() + 600 ) ),
		'wordpress_api_key'                           => $dbl_tag . 'c_akismet',
		'elementor_pro_license_key'                   => $dbl_tag . 'd_elementor',
		'sbi_license_key'                             => $dbl_tag . 'e_smash',
		'stellarwp_uplink_license_key_minn-rc-probe'  => $dbl_tag . 'f_uplink',
		'pue_install_key_minn_rc_probe'               => $dbl_tag . 'g_pue',
		'cleantalk_settings'                          => array( 'apikey' => $dbl_tag . 'h_cleantalk' ),
	);
	$dbl_made = array();
	foreach ( $dbl_seed as $dbl_name => $dbl_val ) {
		if ( null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $dbl_name ) ) ) {
			continue;
		}
		add_option( $dbl_name, $dbl_val, '', false );
		$dbl_made[] = $dbl_name;
	}
	foreach ( $dbl_made as $dbl_name ) {
		$dbl_c = $dbl_cell( $dbl_name );
		$check( "02-03 DB browser: the {$dbl_name} row is redacted", is_array( $dbl_c ) && ! empty( $dbl_c['redacted'] ), is_array( $dbl_c ) && ! empty( $dbl_c['redacted'] ) ? 'redacted' : ( null === $dbl_c ? 'row missing' : 'RAW' ) );
		$dbl_id = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $dbl_name ) );
		list( , $dbl_one ) = $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $dbl_id ) ) ) );
		$check( "02-03 DB browser: the {$dbl_name} row detail holds no part of the key", false === strpos( (string) wp_json_encode( $dbl_one ), $dbl_tag ), false === strpos( (string) wp_json_encode( $dbl_one ), $dbl_tag ) ? 'clean' : 'key present' );
	}
	$dbl_q = $dbl_rows( array( 'fcol' => 'option_value', 'fq' => $dbl_tag ) );
	$check( '02-03 DB browser: a value search on the keys finds none of them', 0 === (int) ( $dbl_q['total'] ?? -1 ), 'total ' . wp_json_encode( $dbl_q['total'] ?? null ) );
	// Real keys already stored: shape only.
	$dbl_existing = function_exists( 'minn_admin_license_secret_options' ) ? array_diff( minn_admin_license_secret_options()[0], $dbl_made ) : array();
	foreach ( $dbl_existing as $dbl_name ) {
		if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $dbl_name ) ) ) {
			continue;
		}
		// An empty row shows as empty: there is nothing to hide.
		$dbl_c = $dbl_cell( $dbl_name );
		$check( "02-03 DB browser: the stored {$dbl_name} row is redacted", '' === $dbl_c || ( is_array( $dbl_c ) && ! empty( $dbl_c['redacted'] ) ), '' === $dbl_c ? 'empty' : ( is_array( $dbl_c ) && ! empty( $dbl_c['redacted'] ) ? 'redacted' : ( null === $dbl_c ? 'row missing' : 'RAW' ) ) );
	}
	// Control: an ordinary row still renders and a value search finds it.
	add_option( 'minnv044dbl_control', $dbl_tag . 'x_control', '', false );
	$dbl_c = $dbl_cell( 'minnv044dbl_control' );
	$dbl_q = $dbl_rows( array( 'fcol' => 'option_value', 'fq' => $dbl_tag . 'x_control' ) );
	$check( '02-03 DB browser control: an ordinary row renders and is found by value', $dbl_tag . 'x_control' === $dbl_c && 1 === (int) ( $dbl_q['total'] ?? -1 ), wp_json_encode( $dbl_q['total'] ?? null ) );
	delete_option( 'minnv044dbl_control' );
	foreach ( $dbl_made as $dbl_name ) {
		delete_option( $dbl_name );
	}

	// --- 02-04 an upper-case table prefix still finds the keyed secrets ---
	// A case-folding server reports WP_options as wp_options; the table list
	// matches case-blind, so the redaction has to strip the prefix the same way.
	$dbl_base = new ReflectionMethod( 'Minn_Admin_DB', 'secret_base' );
	$dbl_base->setAccessible( true );
	$dbl_was  = array( $wpdb->prefix, $wpdb->base_prefix );
	$wpdb->prefix      = strtoupper( $dbl_was[0] );
	$wpdb->base_prefix = strtoupper( $dbl_was[1] );
	$dbl_got = $dbl_base->invoke( null, strtolower( $dbl_was[0] ) . 'options' );
	list( $wpdb->prefix, $wpdb->base_prefix ) = $dbl_was;
	$check( '02-04 DB browser: an upper-case prefix still resolves the options table for redaction', 'options' === $dbl_got, (string) $dbl_got );
} else {
	$skip( '02-03 DB browser not loaded' );
}

// --- 02-01 / 02-02 The updater knows Minn by its real file and every pack type ---
// A pack offered for Minn's slug under any type but "theme" lands where Minn's
// catalogs load from (core installs every non-plugin, non-theme type into
// WP_LANG_DIR), so it has to meet the same gate as a plugin-typed one. And a
// site that installed Minn under another folder (GitHub's source zips unpack
// to minn-admin-<version>/) has to keep the sha256 pin and the drop of foreign
// translations. Nothing here downloads anything: the gate refuses before.
$upd4 = null;
foreach ( (array) ( $GLOBALS['wp_filter']['upgrader_pre_download']->callbacks ?? array() ) as $upd4_cbs ) {
	foreach ( $upd4_cbs as $upd4_cb ) {
		if ( is_array( $upd4_cb['function'] ) && $upd4_cb['function'][0] instanceof Minn_Admin_Updater ) {
			$upd4 = $upd4_cb['function'][0];
		}
	}
}
if ( ! $upd4 ) {
	$skip( '02-01 updater instance unavailable' );
} else {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$upd4_pu      = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
	$upd4_foreign = 'https://evil.example/minn-admin-zz_ZZ.zip';
	$upd4_offer   = function ( $type, $slug = 'minn-admin' ) use ( $upd4_foreign ) {
		return array( 'type' => $type, 'slug' => $slug, 'language' => 'zz_ZZ', 'version' => '9.9.9', 'updated' => '2026-10-01 00:00:00', 'package' => $upd4_foreign, 'autoupdate' => true );
	};
	$upd4_refused = function ( $offer ) use ( $upd4, $upd4_pu, $upd4_foreign ) {
		$r = $upd4->verify_package( false, $upd4_foreign, $upd4_pu, array( 'language_update_type' => $offer['type'], 'language_update' => (object) $offer ) );
		return is_wp_error( $r );
	};
	foreach ( array( 'core', 'Plugin', 'translation' ) as $upd4_type ) {
		$upd4_o = $upd4_offer( $upd4_type );
		$upd4_t = $upd4->scrub_translations( (object) array( 'translations' => array( $upd4_o ) ) );
		$check( "02-02 updater: a {$upd4_type}-typed pack for Minn from another source is not offered", array() === $upd4_t->translations, wp_json_encode( $upd4_t->translations ) );
		$check( "02-02 updater: ...and refused at download", $upd4_refused( $upd4_o ), '' );
	}
	// Controls: a theme-typed pack (it cannot leave WP_LANG_DIR/themes) and
	// another plugin's pack pass untouched.
	$check( '02-02 control: a theme-typed pack under the same slug is left to core', ! $upd4_refused( $upd4_offer( 'theme' ) ), '' );
	$check( '02-02 control: another slug\'s core-typed pack is left to core', ! $upd4_refused( $upd4_offer( 'core', 'akismet' ) ), '' );

	// A renamed install, as GitHub's source zip unpacks it.
	$upd4_ren              = clone $upd4;
	$upd4_ren->plugin_file = 'minn-admin-main/minn-admin.php';
	$upd4_ans = $upd4_ren->drop_foreign_translations( array( 'version' => '9.9.9', 'translations' => array( $upd4_offer( 'plugin', 'minn-admin-main' ) ) ), array(), 'minn-admin-main/minn-admin.php' );
	$check( '02-01 updater: a renamed install drops another updater\'s translations for it', ! isset( $upd4_ans['translations'] ), wp_json_encode( $upd4_ans ) );
	$upd4_r = $upd4_ren->verify_package( false, 'https://evil.example/m.zip', new Plugin_Upgrader( new Automatic_Upgrader_Skin() ), array( 'plugin' => 'minn-admin-main/minn-admin.php' ) );
	$check( '02-01 updater: a renamed install still refuses a plugin zip it cannot verify', is_wp_error( $upd4_r ), is_wp_error( $upd4_r ) ? $upd4_r->get_error_code() : var_export( $upd4_r, true ) );
	$check( '02-01 updater: a pack offered under the renamed folder\'s slug is Minn\'s', $upd4_ren->is_our_language_offer( $upd4_offer( 'plugin', 'minn-admin-main' ) ), '' );
	$check( '02-01 control: a renamed install still knows a pack offered as minn-admin', $upd4_ren->is_our_language_offer( $upd4_offer( 'plugin' ) ), '' );
}

// --- 01-01 Block commenter adds a line that stands for that commenter alone ---
// Core matches each disallowed_keys line as a substring of a new comment's
// author, email, URL, text and IP and trashes the comment on a match. A short
// address the commenter picked (e@gmail.com) sits inside other people's, and an
// IP inside other IPs, so neither may become a line.
$cb_post = (int) get_option( 'page_on_front' ) ?: (int) get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) )[0];
if ( ! $cb_post ) {
	$skip( '01-01 no published post to comment on' );
} else {
	$cb_was  = get_option( 'disallowed_keys', '' );
	$cb_tag  = 'mv44cb' . wp_rand( 1000, 9999 );
	$cb_make = function ( $email, $ip = '203.0.113.7' ) use ( $cb_post ) {
		return (int) wp_insert_comment( array( 'comment_post_ID' => $cb_post, 'comment_author' => 'Minn test', 'comment_author_email' => $email, 'comment_author_IP' => $ip, 'comment_content' => 'Minn block test', 'comment_approved' => 0 ) );
	};
	$cb_short = $cb_make( "e@{$cb_tag}.example" );
	$cb_other = $cb_make( "jane@{$cb_tag}.example" );
	$cb_noip  = $cb_make( '' );
	$cb_own   = $cb_make( "solo.{$cb_tag}@example.org" );
	list( $cb_st, $cb_body ) = $call( 'POST', "/minn-admin/v1/comments/{$cb_short}/block" );
	$check( '01-01 block: an address inside another commenter\'s address is refused', 409 === $cb_st && $cb_was === get_option( 'disallowed_keys', '' ), $cb_st . ' ' . wp_json_encode( $cb_body['added'] ?? $cb_body['code'] ?? null ) );
	list( $cb_st, $cb_body ) = $call( 'POST', "/minn-admin/v1/comments/{$cb_noip}/block" );
	$check( '01-01 block: a comment with no email is refused, not blocked by IP', 400 === $cb_st && $cb_was === get_option( 'disallowed_keys', '' ), $cb_st . ' ' . wp_json_encode( $cb_body['added'] ?? $cb_body['code'] ?? null ) );
	list( $cb_st, $cb_body ) = $call( 'POST', "/minn-admin/v1/comments/{$cb_own}/block" );
	$check( '01-01 control: an address no one else\'s contains is blocked', 200 === $cb_st && in_array( "solo.{$cb_tag}@example.org", (array) ( $cb_body['added'] ?? array() ), true ), $cb_st . ' ' . wp_json_encode( $cb_body['added'] ?? null ) );
	update_option( 'disallowed_keys', $cb_was );
	foreach ( array( $cb_short, $cb_other, $cb_noip, $cb_own ) as $cb_id ) {
		wp_delete_comment( $cb_id, true );
	}
}

// --- 01-02 The Overview's store counts take the same cap as their cards ---
// A marketplace-vendor shape (edit_shop_orders on their own orders, not
// edit_others_shop_orders) read the store-wide awaiting-payment, on-hold,
// to-fulfil and failed counts on every Overview load.
if ( ! class_exists( 'WooCommerce' ) ) {
	$skip( '01-02 WooCommerce inactive' );
} else {
	add_role( 'minn_vendor_test', 'Minn vendor test', array( 'read' => true, 'edit_posts' => true, 'edit_shop_orders' => true, 'read_shop_order' => true ) );
	$ov_login = 'minn-vendor-' . wp_generate_password( 6, false, false );
	$ov_id    = wp_insert_user( array( 'user_login' => $ov_login, 'user_email' => $ov_login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'minn_vendor_test' ) );
	wp_set_current_user( $ov_id );
	list( $ov_st, $ov_body ) = $call( 'GET', '/minn-admin/v1/overview' );
	$check( '01-02 overview: an own-orders account gets no store-wide order counts', 200 === $ov_st && null === ( $ov_body['store'] ?? null ), $ov_st . ' ' . wp_json_encode( $ov_body['store'] ?? null ) );
	wp_set_current_user( $admin );
	list( $ov_st, $ov_body ) = $call( 'GET', '/minn-admin/v1/overview' );
	$check( '01-02 control: an administrator still gets them', 200 === $ov_st && is_array( $ov_body['store'] ?? null ), $ov_st . ' ' . wp_json_encode( $ov_body['store'] ?? null ) );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $ov_id );
	remove_role( 'minn_vendor_test' );
}

// --- 01-03 A shop manager's order email goes From their own address ---
// A free subject and body is not WooCommerce's customer note (a fixed subject
// from the store's sender), so it follows the rule every other Minn composer
// does: administrators send as the site, anyone else as themselves.
if ( ! function_exists( 'wc_create_order' ) || ! get_role( 'shop_manager' ) ) {
	$skip( '01-03 WooCommerce inactive' );
} else {
	$om_login = 'minn-shopmgr-' . wp_generate_password( 6, false, false );
	$om_user  = wp_insert_user( array( 'user_login' => $om_login, 'user_email' => $om_login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'shop_manager' ) );
	$om_order = wc_create_order();
	$om_order->set_billing_email( 'minn-order-customer@example.com' );
	$om_order->set_billing_first_name( 'Minn' );
	$om_order->save();
	$om_mail  = array();
	$om_trap  = function ( $return, $atts ) use ( &$om_mail ) {
		$om_mail[] = $atts;
		return true;
	};
	add_filter( 'pre_wp_mail', $om_trap, PHP_INT_MAX, 2 );
	$om_from = function () use ( &$om_mail ) {
		$last = end( $om_mail );
		foreach ( (array) ( $last['headers'] ?? array() ) as $h ) {
			if ( 0 === stripos( $h, 'From:' ) ) {
				return trim( substr( $h, 5 ) );
			}
		}
		return '';
	};
	wp_set_current_user( $om_user );
	list( $om_st ) = $call( 'POST', '/minn-admin/v1/orders/' . $om_order->get_id() . '/email', array( 'subject' => 'Minn test', 'message' => 'Hello' ) );
	$om_f = $om_from();
	$check( '01-03 order email: a shop manager sends From their own address', 200 === $om_st && false !== strpos( $om_f, $om_login . '@example.com' ) && false === strpos( $om_f, (string) get_option( 'admin_email' ) ), $om_st . ' ' . $om_f );
	wp_set_current_user( $admin );
	list( $om_st ) = $call( 'POST', '/minn-admin/v1/orders/' . $om_order->get_id() . '/email', array( 'subject' => 'Minn test', 'message' => 'Hello' ) );
	$om_f = $om_from();
	$check( '01-03 control: an administrator still sends as the site', 200 === $om_st && false !== strpos( $om_f, (string) get_option( 'admin_email' ) ), $om_st . ' ' . $om_f );
	remove_filter( 'pre_wp_mail', $om_trap, PHP_INT_MAX );
	$om_order->delete( true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $om_user );
}

// @sections

// Flamingo files a contact for every user a section creates and keeps it
// after the user is deleted; the fixtures' leftovers go here.
if ( post_type_exists( 'flamingo_contact' ) ) {
	global $wpdb;
	foreach ( $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'flamingo_contact' AND post_title LIKE 'minn-%@example.com'" ) as $minn_contact ) {
		if ( ! get_user_by( 'email', $minn_contact->post_title ) ) {
			wp_delete_post( (int) $minn_contact->ID, true );
		}
	}
}

$summary();
