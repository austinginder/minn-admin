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

// --- 07-04 Memberships: a member is never re-resolved onto another account ---
// "Add plan" on a member page posted the member's username, and the lookup
// tried it as an email first: when the username is email-shaped and another
// account has since set that address as its email, the new membership went
// to that other account.
if ( ! function_exists( 'wc_memberships_get_membership_plan' ) ) {
	$skip( '07-04 WooCommerce Memberships inactive' );
} else {
	$wm_plan = get_page_by_path( 'minn-gold', OBJECT, 'wc_membership_plan' );
	$wm_tag  = wp_generate_password( 6, false, false );
	$wm_addr = "alice-{$wm_tag}@example.com";
	$wm_alice = wp_insert_user( array( 'user_login' => $wm_addr, 'user_email' => "alice-new-{$wm_tag}@example.com", 'user_pass' => wp_generate_password( 24 ), 'role' => 'customer' ) );
	$wm_mal   = wp_insert_user( array( 'user_login' => "mallory-{$wm_tag}", 'user_email' => $wm_addr, 'user_pass' => wp_generate_password( 24 ), 'role' => 'customer' ) );
	if ( ! $wm_plan || is_wp_error( $wm_alice ) || is_wp_error( $wm_mal ) ) {
		$check( '07-04 fixtures', false, ! $wm_plan ? 'no minn-gold plan' : 'user create failed' );
	} else {
		list( $wm_st, $wm_body ) = $call( 'POST', '/minn-admin/v1/wcm/members', array( 'customer' => $wm_addr, 'plan_id' => $wm_plan->ID ) );
		$wm_who = ( 200 === $wm_st && ! empty( $wm_body['id'] ) ) ? (int) get_post_field( 'post_author', (int) $wm_body['id'] ) : 0;
		$check( '07-04 memberships: an address that is one account\'s username and another\'s email is refused', 400 === $wm_st && ! wc_memberships_get_user_membership( $wm_mal, $wm_plan->ID ), $wm_st . ( $wm_who ? ' created for ' . ( $wm_who === $wm_mal ? 'the other account' : 'the member' ) : '' ) );
		list( $wm_st, $wm_body ) = $call( 'POST', '/minn-admin/v1/wcm/members', array( 'customer_id' => $wm_alice, 'plan_id' => $wm_plan->ID ) );
		$check( '07-04 control: the member page names its member by id and the plan goes to them', 200 === $wm_st && wc_memberships_get_user_membership( $wm_alice, $wm_plan->ID ) && ! wc_memberships_get_user_membership( $wm_mal, $wm_plan->ID ), $wm_st . ' ' . wp_json_encode( $wm_body['code'] ?? '' ) );
		foreach ( array( $wm_alice, $wm_mal ) as $wm_u ) {
			$wm_m = wc_memberships_get_user_membership( $wm_u, $wm_plan->ID );
			if ( $wm_m ) {
				wp_delete_post( $wm_m->get_id(), true );
			}
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( $wm_alice, $wm_mal ) as $wm_u ) {
		if ( ! is_wp_error( $wm_u ) && $wm_u ) {
			wp_delete_user( $wm_u );
		}
	}
}

// --- 07-03 Gift Cards: codes in order notes are masked below administrator ---
// The vendor masks the code in its order notes for shop managers only on
// wp-admin screens; Minn's order Timeline reads the notes over wc/v3, where
// that filter never runs.
if ( ! function_exists( 'wc_gc_mask_code' ) || ! function_exists( 'wc_create_order' ) || ! get_role( 'shop_manager' ) ) {
	$skip( '07-03 WooCommerce Gift Cards inactive' );
} else {
	$gc_code  = 'MNTS-' . strtoupper( wp_generate_password( 4, false, false ) ) . '-QRST-UVWX';
	$gc_order = wc_create_order();
	$gc_order->save();
	$gc_order->add_order_note( 'Debited $10.00 to gift card code <span class="woocommerce-giftcards-admin-note-code">' . $gc_code . '</span>.' );
	$gc_login = 'minn-gcmgr-' . wp_generate_password( 6, false, false );
	$gc_user  = wp_insert_user( array( 'user_login' => $gc_login, 'user_email' => $gc_login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'shop_manager' ) );
	$gc_notes = function () use ( $call, $gc_order ) {
		list( $st, $body ) = $call( 'GET', '/wc/v3/orders/' . $gc_order->get_id() . '/notes' );
		return array( $st, wp_json_encode( $body ) );
	};
	$gc_unmask = get_option( 'wc_gc_unmask_codes_for_shop_managers', null );
	update_option( 'wc_gc_unmask_codes_for_shop_managers', 'no' );
	wp_set_current_user( $gc_user );
	list( $gc_st, $gc_json ) = $gc_notes();
	$check( '07-03 gift cards: a shop manager reads the order note with the code masked', 200 === $gc_st && false === strpos( $gc_json, $gc_code ) && false !== strpos( $gc_json, 'woocommerce-giftcards-admin-note-code' ), $gc_st . ' ' . ( false === strpos( $gc_json, $gc_code ) ? 'masked' : 'full code' ) );
	wp_set_current_user( $admin );
	list( $gc_st, $gc_json ) = $gc_notes();
	$check( '07-03 control: an administrator still reads the full code', 200 === $gc_st && false !== strpos( $gc_json, $gc_code ), $gc_st . ' ' . ( false === strpos( $gc_json, $gc_code ) ? 'masked' : 'full code' ) );
	null === $gc_unmask ? delete_option( 'wc_gc_unmask_codes_for_shop_managers' ) : update_option( 'wc_gc_unmask_codes_for_shop_managers', $gc_unmask );
	$gc_order->delete( true );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $gc_user );
}

// --- 04-01 / 05-04 / 14-05 Email and notification saves keep what they leave out ---
// The pages now send only what changed, and the servers keep every key a save
// leaves out. Before, a body that named only the subject reset routing,
// conditions, BCC and Reply-To; one that named only a confirmation's name
// turned a redirect into an empty message. Each fixture is snapshotted and
// put back.
if ( class_exists( 'GFAPI' ) && function_exists( 'minn_admin_gfn_build' ) && ( $pb_form = GFAPI::get_form( (int) ( GFAPI::get_forms() ? GFAPI::get_forms()[0]['id'] : 0 ) ) ) ) {
	$pb_snap  = $pb_form;
	$pb_field = (string) $pb_form['fields'][0]->id;
	$pb_logic = array( 'actionType' => 'show', 'logicType' => 'all', 'rules' => array( array( 'fieldId' => $pb_field, 'operator' => 'is', 'value' => 'minn' ) ) );
	$pb_nid   = 'minnrc' . strtolower( wp_generate_password( 6, false, false ) );
	$pb_cid   = 'minnrc' . strtolower( wp_generate_password( 6, false, false ) );
	$pb_form['notifications'][ $pb_nid ] = array( 'id' => $pb_nid, 'name' => 'Minn RC partial ' . $pb_nid, 'event' => 'form_submission', 'service' => 'wordpress', 'toType' => 'email', 'to' => '{admin_email}', 'bcc' => 'archive@example.com', 'replyTo' => 'reply@example.com', 'subject' => 'Hello', 'message' => '{all_fields}', 'isActive' => true, 'conditionalLogic' => $pb_logic );
	$pb_form['confirmations'][ $pb_cid ] = array( 'id' => $pb_cid, 'name' => 'Minn RC partial ' . $pb_cid, 'isDefault' => false, 'type' => 'redirect', 'url' => 'https://example.com/thanks', 'message' => '', 'queryString' => 'a=1', 'conditionalLogic' => $pb_logic );
	GFAPI::update_form( $pb_form );
	list( $pb_st ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$pb_form['id']}:{$pb_nid}/full", array( 'subject' => 'Changed' ) );
	$pb_n = GFAPI::get_form( $pb_form['id'] )['notifications'][ $pb_nid ] ?? array();
	$check( '04-01 GF notification: a save naming only the subject keeps BCC, Reply-To and conditions', 200 === $pb_st && 'Changed' === ( $pb_n['subject'] ?? '' ) && 'archive@example.com' === ( $pb_n['bcc'] ?? '' ) && 'reply@example.com' === ( $pb_n['replyTo'] ?? '' ) && 1 === count( (array) ( $pb_n['conditionalLogic']['rules'] ?? array() ) ), $pb_st . ' ' . wp_json_encode( array( $pb_n['subject'] ?? null, $pb_n['bcc'] ?? null, $pb_n['replyTo'] ?? null, $pb_n['conditionalLogic'] ?? null ) ) );
	list( $pb_st ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$pb_form['id']}:{$pb_cid}/full", array( 'name' => 'Minn RC renamed ' . $pb_cid ) );
	$pb_c = GFAPI::get_form( $pb_form['id'] )['confirmations'][ $pb_cid ] ?? array();
	$check( '04-01 GF confirmation: a save naming only the name keeps the redirect and its conditions', 200 === $pb_st && 'Minn RC renamed ' . $pb_cid === ( $pb_c['name'] ?? '' ) && 'redirect' === ( $pb_c['type'] ?? '' ) && 'https://example.com/thanks' === ( $pb_c['url'] ?? '' ) && 'a=1' === ( $pb_c['queryString'] ?? '' ) && ! empty( $pb_c['conditionalLogic']['rules'] ), $pb_st . ' ' . wp_json_encode( array( $pb_c['type'] ?? null, $pb_c['url'] ?? null, $pb_c['queryString'] ?? null ) ) );
	GFAPI::update_form( $pb_snap );
} else {
	$skip( '04-01 Gravity Forms inactive or no form' );
}

if ( function_exists( 'minn_admin_fluent_notifications' ) && function_exists( 'wpFluent' ) && class_exists( '\FluentForm\App\Services\Settings\SettingsService' ) ) {
	global $wpdb;
	$pf_form = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}fluentform_forms ORDER BY id LIMIT 1" );
	if ( ! $pf_form ) {
		$skip( '05-x Fluent: no form' );
	} else {
		$pf_meta = $wpdb->prefix . 'fluentform_form_meta';
		$wpdb->insert( $pf_meta, array( 'form_id' => $pf_form, 'meta_key' => 'notifications', 'value' => wp_json_encode( array( 'name' => 'Minn RC partial', 'enabled' => false, 'sendTo' => array( 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => array() ), 'fromName' => 'Shop', 'fromEmail' => '', 'replyTo' => 'reply@example.com', 'bcc' => 'archive@example.com', 'subject' => 'Hello', 'message' => '<p>{all_data}</p>', 'conditionals' => array( 'status' => false, 'type' => 'all', 'conditions' => array() ) ) ) ) );
		$pf_mid  = (int) $wpdb->insert_id;
		$pf_set  = $wpdb->get_row( $wpdb->prepare( "SELECT id, value FROM {$pf_meta} WHERE form_id = %d AND meta_key = 'formSettings'", $pf_form ) );
		list( $pf_st ) = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$pf_form}/emails", array( 'notifications' => array( array( 'metaId' => $pf_mid, 'subject' => 'Changed' ) ) ) );
		$pf_n = minn_admin_fluent_notifications( $pf_form )[ $pf_mid ] ?? array();
		$check( '05-x Fluent notification: a save naming only the subject keeps BCC, Reply-To and the name', 200 === $pf_st && 'Changed' === ( $pf_n['subject'] ?? '' ) && 'archive@example.com' === ( $pf_n['bcc'] ?? '' ) && 'reply@example.com' === ( $pf_n['replyTo'] ?? '' ) && 'Minn RC partial' === ( $pf_n['name'] ?? '' ), $pf_st . ' ' . wp_json_encode( array( $pf_n['name'] ?? null, $pf_n['bcc'] ?? null, $pf_n['replyTo'] ?? null ) ) );
		if ( $pf_set ) {
			$pf_s = json_decode( (string) $pf_set->value, true );
			$pf_s['confirmation'] = array_merge( (array) ( $pf_s['confirmation'] ?? array() ), array( 'redirectTo' => 'customUrl', 'customUrl' => 'https://example.com/thanks', 'messageToShow' => 'Thanks' ) );
			$wpdb->update( $pf_meta, array( 'value' => wp_json_encode( $pf_s ) ), array( 'id' => $pf_set->id ) );
			list( $pf_st ) = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$pf_form}/emails", array( 'confirmation' => array( 'messageToShow' => 'Thanks again' ) ) );
			$pf_c = (array) ( json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$pf_meta} WHERE id = %d", $pf_set->id ) ), true )['confirmation'] ?? array() );
			$check( '05-x Fluent confirmation: a save naming only the message keeps the redirect', 200 === $pf_st && 'customUrl' === ( $pf_c['redirectTo'] ?? '' ) && 'https://example.com/thanks' === ( $pf_c['customUrl'] ?? '' ), $pf_st . ' ' . wp_json_encode( array( $pf_c['redirectTo'] ?? null, $pf_c['customUrl'] ?? null ) ) );
			$wpdb->update( $pf_meta, array( 'value' => $pf_set->value ), array( 'id' => $pf_set->id ) );
		}
		$wpdb->delete( $pf_meta, array( 'id' => $pf_mid ) );
	}
} else {
	$skip( '05-x Fluent Forms inactive' );
}

if ( function_exists( 'minn_admin_wpforms_form_data' ) && function_exists( 'wpforms' ) ) {
	$pw_ids = get_posts( array( 'post_type' => 'wpforms', 'numberposts' => 1, 'fields' => 'ids' ) );
	if ( ! $pw_ids ) {
		$skip( '04-01 WPForms: no form' );
	} else {
		global $wpdb;
		$pw_id   = (int) $pw_ids[0];
		$pw_raw  = $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $pw_id ) );
		$pw_data = wpforms_decode( $pw_raw );
		$pw_data['settings']['confirmations']['9'] = array( 'name' => 'Minn RC partial', 'type' => 'redirect', 'message' => '', 'page' => '', 'redirect' => 'https://example.com/thanks' );
		// wpforms_encode() slashes for wp_update_post(), which unslashes.
		wp_update_post( array( 'ID' => $pw_id, 'post_content' => wpforms_encode( $pw_data ) ) );
		list( $pw_st ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$pw_id}/emails", array( 'confirmations' => array( array( 'key' => '9', 'name' => 'Minn RC renamed' ) ) ) );
		$pw_c = ( minn_admin_wpforms_form_data( $pw_id )['settings']['confirmations']['9'] ?? array() );
		$check( '04-01 WPForms confirmation: a save naming only the name keeps the redirect', 200 === $pw_st && 'Minn RC renamed' === ( $pw_c['name'] ?? '' ) && 'redirect' === ( $pw_c['type'] ?? '' ) && 'https://example.com/thanks' === ( $pw_c['redirect'] ?? '' ), $pw_st . ' ' . wp_json_encode( array( $pw_c['type'] ?? null, $pw_c['redirect'] ?? null ) ) );
		$wpdb->update( $wpdb->posts, array( 'post_content' => $pw_raw ), array( 'ID' => $pw_id ) );
		clean_post_cache( $pw_id );
	}
} else {
	$skip( '04-01 WPForms inactive' );
}

// --- 04-02 Gravity SMTP: Send a test email takes Gravity SMTP's own capability too ---
// Their endpoint asks for VIEW_TOOLS_SENDATEST; Minn asked only for
// EDIT_GENERAL_SETTINGS. Asked of the route's permission callback directly, so
// no test mail goes out.
if ( ! function_exists( 'minn_admin_gsmtp_cap' ) || ! class_exists( 'Gravity_Forms\Gravity_SMTP\Users\Roles' ) ) {
	$skip( '04-02 Gravity SMTP inactive' );
} else {
	$gs_routes = rest_get_server()->get_routes();
	$gs_perm   = $gs_routes['/minn-admin/v1/gravity-smtp/send-test'][0]['permission_callback'] ?? null;
	add_role( 'minn_gsmtp_test', 'Minn GSMTP test', array( 'read' => true, minn_admin_gsmtp_cap( 'EDIT_GENERAL_SETTINGS' ) => true, minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG' ) => true ) );
	$gs_login = 'minn-gsmtp-' . wp_generate_password( 6, false, false );
	$gs_user  = wp_insert_user( array( 'user_login' => $gs_login, 'user_email' => $gs_login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'minn_gsmtp_test' ) );
	wp_set_current_user( $gs_user );
	$check( '04-02 Gravity SMTP: a settings editor without Send a Test cannot send one', is_callable( $gs_perm ) && ! call_user_func( $gs_perm, new WP_REST_Request( 'POST', '/minn-admin/v1/gravity-smtp/send-test' ) ), '' );
	get_user_by( 'id', $gs_user )->add_cap( minn_admin_gsmtp_cap( 'VIEW_TOOLS_SENDATEST' ) );
	wp_set_current_user( 0 );
	wp_set_current_user( $gs_user );
	$check( '04-02 control: with both capabilities the test is allowed', is_callable( $gs_perm ) && call_user_func( $gs_perm, new WP_REST_Request( 'POST', '/minn-admin/v1/gravity-smtp/send-test' ) ), '' );
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $gs_user );
	remove_role( 'minn_gsmtp_test' );
}

// --- 05-01 Fluent Forms: the email preview never reads the viewer's cookies ---
// {cookie.NAME} resolves from the current request; in the preview that is the
// viewer's own, so a planted subject read back the HttpOnly login cookie.
if ( ! class_exists( '\FluentForm\App\Services\FormBuilder\ShortCodeParser' ) || ! function_exists( 'wpFluent' ) ) {
	$skip( '05-01 Fluent Forms inactive' );
} else {
	$fc_entry = wpFluent()->table( 'fluentform_submissions' )->orderBy( 'id', 'DESC' )->first();
	if ( ! $fc_entry ) {
		$skip( '05-01 Fluent Forms: no entry to preview against' );
	} else {
		$fc_was = $_COOKIE;
		$_COOKIE['minn_probe_cookie'] = 'minnprobesecret' . wp_rand( 1000, 9999 );
		list( $fc_st, $fc_body ) = $call( 'POST', '/minn-admin/v1/fluent-forms/forms/' . (int) $fc_entry->form_id . '/emails/preview', array( 'subject' => 'Hi {cookie.minn_probe_cookie}', 'message' => '<p>{cookie.minn_probe_cookie}</p>' ) );
		$fc_leak = false !== strpos( wp_json_encode( $fc_body ), $_COOKIE['minn_probe_cookie'] );
		$check( '05-01 Fluent preview: a {cookie.*} tag does not read the viewer\'s cookie', 200 === $fc_st && ! empty( $fc_body['entry'] ) && ! $fc_leak, $fc_st . ' ' . ( $fc_leak ? 'cookie read back' : 'clean' ) );
		$check( '05-01 control: the viewer\'s cookies are back after the preview', isset( $_COOKIE['minn_probe_cookie'] ), '' );
		$_COOKIE = $fc_was;
	}
}

// --- 05-02 Fluent Forms: status changes go through Fluent's own service ---
// The status route wrote the column directly, so a status an add-on withholds
// for an entry was written anyway and the after-update action never fired; the
// detail view marked entries read even where the site turned auto-read off.
if ( ! class_exists( '\FluentForm\App\Services\Submission\SubmissionService' ) || ! function_exists( 'wpFluent' ) ) {
	$skip( '05-02 Fluent Forms inactive' );
} else {
	global $wpdb;
	$fs_table = $wpdb->prefix . 'fluentform_submissions';
	$fs_form  = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}fluentform_forms ORDER BY id LIMIT 1" );
	$wpdb->insert( $fs_table, array( 'form_id' => $fs_form, 'serial_number' => 9999, 'response' => wp_json_encode( array( 'names' => array( 'first_name' => 'Minn' ) ) ), 'source_url' => home_url( '/' ), 'user_id' => 0, 'status' => 'unread', 'is_favourite' => 0, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$fs_id     = (int) $wpdb->insert_id;
	$fs_status = function () use ( $wpdb, $fs_table, $fs_id ) {
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$fs_table} WHERE id = %d", $fs_id ) );
	};
	$fs_noread = '__return_false';
	add_filter( 'fluentform/auto_read_submission', $fs_noread );
	$call( 'GET', "/minn-admin/v1/fluent-forms/entries/{$fs_id}" );
	remove_filter( 'fluentform/auto_read_submission', $fs_noread );
	$check( '05-02 Fluent: opening an entry leaves it unread where the site turned auto-read off', 'unread' === $fs_status(), $fs_status() );
	$fs_hold = function ( $statuses, $form_id, $submission_id ) use ( $fs_id ) {
		if ( (int) $submission_id === $fs_id ) {
			unset( $statuses['spam'] );
		}
		return $statuses;
	};
	add_filter( 'fluentform/entry_statuses_for_mutation', $fs_hold, 10, 3 );
	list( $fs_st ) = $call( 'POST', "/minn-admin/v1/fluent-forms/entries/{$fs_id}/status", array( 'status' => 'spam' ) );
	remove_filter( 'fluentform/entry_statuses_for_mutation', $fs_hold, 10 );
	$check( '05-02 Fluent: a status an add-on withholds for this entry is refused', 400 === $fs_st && 'unread' === $fs_status(), $fs_st . ' ' . $fs_status() );
	$fs_fired = 0;
	$fs_count = function () use ( &$fs_fired ) {
		$fs_fired++;
	};
	add_action( 'fluentform/after_submission_status_update', $fs_count );
	list( $fs_st ) = $call( 'POST', "/minn-admin/v1/fluent-forms/entries/{$fs_id}/status", array( 'status' => 'read' ) );
	remove_action( 'fluentform/after_submission_status_update', $fs_count );
	$check( '05-02 Fluent: a status change fires Fluent\'s after-update action (control: it saves)', 200 === $fs_st && 'read' === $fs_status() && 1 === $fs_fired, $fs_st . ' ' . $fs_status() . ' fired ' . $fs_fired );
	$wpdb->delete( $fs_table, array( 'id' => $fs_id ) );
}

// --- 05-03 SureForms: a permanent delete runs SureForms' own delete hook ---
// Minn deleted the row with raw SQL, so srfm_before_delete_entry (add-ons'
// per-entry cleanup, uploaded files among them) never fired. Needs SureForms
// loaded: MINN_SWAP_ADD=sureforms/sureforms.php --require=tests/lib/plugin-swap.php
if ( ! function_exists( 'minn_admin_sureforms_table' ) || ! defined( 'SRFM_VER' ) ) {
	$skip( '05-03 SureForms inactive (swap it in)' );
} else {
	global $wpdb;
	$sf_table = minn_admin_sureforms_table();
	$sf_form  = (int) ( get_posts( array( 'post_type' => 'sureforms_form', 'numberposts' => 1, 'fields' => 'ids', 'post_status' => 'any' ) )[0] ?? 0 );
	$wpdb->insert( $sf_table, array( 'form_id' => $sf_form, 'user_id' => 0, 'form_data' => wp_json_encode( array( 'srfm-email' => 'minn@example.com' ) ), 'status' => 'read', 'type' => '', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$sf_id    = (int) $wpdb->insert_id;
	$sf_fired = array();
	$sf_hook  = function ( $id ) use ( &$sf_fired ) {
		$sf_fired[] = (int) $id;
	};
	add_action( 'srfm_before_delete_entry', $sf_hook );
	list( $sf_st ) = $call( 'DELETE', "/minn-admin/v1/sureforms/entries/{$sf_id}" );
	remove_action( 'srfm_before_delete_entry', $sf_hook );
	$sf_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sf_table} WHERE ID = %d", $sf_id ) );
	$check( '05-03 SureForms: a permanent delete fires srfm_before_delete_entry for the entry', 200 === $sf_st && array( $sf_id ) === $sf_fired, $sf_st . ' fired ' . wp_json_encode( $sf_fired ) );
	$check( '05-03 control: the entry is gone', 0 === $sf_left, (string) $sf_left );
	list( $sf_st ) = $call( 'DELETE', "/minn-admin/v1/sureforms/entries/{$sf_id}" );
	$check( '05-03 control: deleting it again answers 404', 404 === $sf_st, (string) $sf_st );
	$wpdb->delete( $sf_table, array( 'ID' => $sf_id ) );
}

// --- 06-01 ACF schema saves keep backslashes ---
// acf_update_field() and acf_update_field_group() unslash what they get (ACF's
// editor hands them the slashed $_POST). Minn handed them stored or REST
// values, so every builder save, field edit and move took one level of
// backslashes off: a ==pattern rule ^\d{5}$ became ^d{5}$.
if ( ! function_exists( 'acf_import_field_group' ) || ! function_exists( 'minn_admin_acf_builder_save' ) ) {
	$skip( '06-01 ACF PRO inactive' );
} else {
	$as_key  = 'group_minnrc' . strtolower( wp_generate_password( 6, false, false ) );
	$as_zip  = 'field_' . substr( $as_key, 6 ) . 'zip';
	$as_note = 'field_' . substr( $as_key, 6 ) . 'note';
	$as_rule = '^\d{5}$';
	$as_hint = 'Saved under C:\Temp';
	$as_g    = acf_import_field_group( wp_slash( array(
		'key'      => $as_key,
		'title'    => 'Minn RC slashes',
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
		'fields'   => array(
			array( 'key' => $as_zip, 'label' => 'Zip', 'name' => 'minnrc_zip', 'type' => 'text' ),
			array( 'key' => $as_note, 'label' => 'Note', 'name' => 'minnrc_note', 'type' => 'text', 'instructions' => $as_hint, 'conditional_logic' => array( array( array( 'field' => $as_zip, 'operator' => '==pattern', 'value' => $as_rule ) ) ) ),
		),
	) ) );
	$as_read = function () use ( $as_note ) {
		acf_flush_field_cache( acf_get_field( $as_note ) );
		$f = acf_get_field( $as_note );
		return array( (string) ( $f['instructions'] ?? '' ), (string) ( $f['conditional_logic'][0][0]['value'] ?? '' ) );
	};
	$check( '06-01 precondition: the fixture stores its backslashes', array( $as_hint, $as_rule ) === $as_read(), wp_json_encode( $as_read() ) );
	list( , $as_payload ) = $call( 'GET', "/minn-admin/v1/acf/schema/groups/{$as_key}/full" );
	list( $as_st ) = $call( 'POST', "/minn-admin/v1/acf/schema/groups/{$as_key}/full", (array) $as_payload );
	$check( '06-01 ACF builder: a save keeps the backslashes in every field\'s settings', 200 === $as_st && array( $as_hint, $as_rule ) === $as_read(), $as_st . ' ' . wp_json_encode( $as_read() ) );
	list( $as_st ) = $call( 'PUT', "/minn-admin/v1/acf/schema/fields/{$as_note}", array( 'label' => 'Note renamed' ) );
	$check( '06-01 ACF field edit: a label edit keeps them', 200 === $as_st && array( $as_hint, $as_rule ) === $as_read() && 'Note renamed' === acf_get_field( $as_note )['label'], $as_st . ' ' . wp_json_encode( $as_read() ) );
	list( $as_st ) = $call( 'POST', "/minn-admin/v1/acf/schema/fields/{$as_note}/move", array( 'dir' => 'up' ) );
	$check( '06-01 ACF move: a reorder keeps them (control: the order changes)', 200 === $as_st && array( $as_hint, $as_rule ) === $as_read() && 0 === (int) acf_get_field( $as_note )['menu_order'], $as_st . ' ' . wp_json_encode( $as_read() ) );
	if ( ! empty( $as_g['ID'] ) ) {
		acf_delete_field_group( $as_g['ID'] );
	}
}

// --- 06-02 ACPT: a field whose read runs a shortcode or decodes HTML is locked ---
// get_acpt_field() renders a Text-family value (do_shortcode, and an entity
// decode when the field allows HTML); ACPT's own box edits the stored value.
// The panel showed the render, so an edit stored the output over the
// shortcode, and ACPT's setter stripped an allow_html field's markup.
if ( function_exists( 'minn_admin_acpt_active' ) && minn_admin_acpt_active() && class_exists( '\\ACPT\\Core\\CQRS\\Command\\SaveMetaGroupCommand' ) && class_exists( '\\ACPT\\Core\\CQRS\\Command\\DeleteMetaGroupCommand' ) ) {
	$ac_sfx = substr( md5( uniqid( '', true ) ), 0, 8 );
	$ac_box = 'minn_v044_acpt_sc' . $ac_sfx;
	$ac_gid = '';
	try {
		$ac_gid = ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
			'name'    => 'minn-v044-acpt-sc-' . $ac_sfx,
			'label'   => 'Minn v044 ACPT shortcode',
			'belongs' => array( array( 'belongsTo' => 'customPostType', 'operator' => '=', 'find' => 'post', 'logic' => '' ) ),
			'boxes'   => array( array( 'name' => $ac_box, 'label' => 'Box', 'fields' => array(
				array( 'name' => 'note', 'type' => 'Text', 'label' => 'Note' ),
				array( 'name' => 'rich', 'type' => 'Textarea', 'label' => 'Rich', 'advancedOptions' => array( array( 'key' => 'allow_html', 'value' => '1' ) ) ),
				array( 'name' => 'plain', 'type' => 'Text', 'label' => 'Plain' ),
			) ) ),
		) ) )->execute();
	} catch ( \Throwable $e ) {
		$skip( '06-02 ACPT: could not build the fixture group (' . $e->getMessage() . ')' );
	}
	if ( $ac_gid ) {
		$ac_sc = 'minn_v044_acpt_sc_' . $ac_sfx;
		add_shortcode( $ac_sc, function () {
			return '19.00';
		} );
		$ac_pid = wp_insert_post( array( 'post_title' => 'Minn v044 ACPT shortcode', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
		foreach ( array( 'note' => 'Price: [' . $ac_sc . ']', 'plain' => 'Hello' ) as $ac_n => $ac_v ) {
			save_acpt_meta_field_value( array( 'post_id' => $ac_pid, 'box_name' => $ac_box, 'field_name' => $ac_n, 'value' => $ac_v ) );
		}
		update_post_meta( $ac_pid, $ac_box . '_rich', '&lt;b&gt;Bold&lt;/b&gt; text' );
		$ac_ids = array();
		foreach ( \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $ac_gid ) )[0]->getBoxes() as $ac_b ) {
			foreach ( $ac_b->getFields() as $ac_f ) {
				$ac_ids[ $ac_f->getName() ] = $ac_f->getId();
			}
		}
		$ac_lookup = minn_admin_acpt_fields_payload( $ac_pid, 'post', true )['lookup'];
		$check( '06-02 ACPT: a field holding a shortcode and an allow_html field are locked, a plain one offered', ! isset( $ac_lookup[ $ac_ids['note'] ] ) && ! isset( $ac_lookup[ $ac_ids['rich'] ] ) && isset( $ac_lookup[ $ac_ids['plain'] ] ), wp_json_encode( array( 'note' => isset( $ac_lookup[ $ac_ids['note'] ] ), 'rich' => isset( $ac_lookup[ $ac_ids['rich'] ] ), 'plain' => isset( $ac_lookup[ $ac_ids['plain'] ] ) ) ) );
		list( $ac_st ) = $call( 'POST', '/wp/v2/posts/' . $ac_pid, array( 'minn_acpt' => array( $ac_ids['note'] => 'Price: 19.00 each', $ac_ids['rich'] => 'Bold text, edited', $ac_ids['plain'] => 'Hello again' ) ) );
		$ac_meta = function ( $n ) use ( $ac_pid, $ac_box ) {
			wp_cache_delete( $ac_pid, 'post_meta' );
			return (string) get_post_meta( $ac_pid, $ac_box . '_' . $n, true );
		};
		$check( '06-02 ACPT: a save keeps the shortcode and the markup as stored', 200 === $ac_st && 'Price: [' . $ac_sc . ']' === $ac_meta( 'note' ) && '&lt;b&gt;Bold&lt;/b&gt; text' === $ac_meta( 'rich' ), $ac_st . ' ' . wp_json_encode( array( $ac_meta( 'note' ), $ac_meta( 'rich' ) ) ) );
		$check( '06-02 control: the plain field saves', 'Hello again' === $ac_meta( 'plain' ), $ac_meta( 'plain' ) );
		wp_delete_post( $ac_pid, true );
		( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $ac_gid ) )->execute();
	}
} else {
	$skip( '06-02 ACPT inactive' );
}

// --- 08-01 / 08-02 / 14-04 SEO robots: a change starts from what the post inherits ---
// SureRank and Rank Math both apply site or post-type robots while a post has
// none of its own, and a post's own set replaces them whole. A single switch
// written over nothing dropped the inherited others, and SureRank's off
// deleted the last key and handed the post back to a site-wide noindex.
// Run under tests/lib/seo-swap.php with MINN_SEO_SWAP=rank-math or surerank.
$rb_prov = function_exists( 'minn_admin_seo_plugin' ) ? ( minn_admin_seo_plugin()['name'] ?? '' ) : '';
$rb_seed = function ( $pid ) use ( $call ) {
	list( , $read ) = $call( 'GET', '/wp/v2/posts/' . $pid, null, array( 'context' => 'edit' ) );
	$vals = (array) ( $read['minn_seo'] ?? array() );
	foreach ( $vals as $k => $v ) {
		if ( false === $v ) {
			$vals[ $k ] = null;
		}
	}
	return array( $vals, (array) ( $read['minn_seo'] ?? array() ) );
};
if ( 'SureRank' === $rb_prov ) {
	$rb_was = get_option( 'surerank_settings', null );
	update_option( 'surerank_settings', array_merge( (array) $rb_was, array( 'no_index' => array( 'post' ), 'no_follow' => array( 'post' ), 'no_archive' => array() ) ) );
	$rb_cache = new ReflectionProperty( '\SureRank\Inc\Functions\Settings', 'cached_settings' );
	$rb_cache->setAccessible( true );
	$rb_cache->setValue( null, null );
	$rb_meta = function ( $pid, $k ) {
		wp_cache_delete( $pid, 'post_meta' );
		return (string) get_post_meta( $pid, 'surerank_settings_post_' . $k, true );
	};
	// An all-empty post on a noindexed, nofollowed type reads as inheriting both.
	$rb_a = wp_insert_post( array( 'post_title' => 'Minn v044 surerank robots A', 'post_status' => 'draft' ) );
	list( $rb_vals, $rb_raw ) = $rb_seed( $rb_a );
	$check( '08-01 SureRank: a post with no robots of its own reads the site-wide noindex and nofollow', true === ( $rb_raw['robots_noindex'] ?? null ) && true === ( $rb_raw['robots_nofollow'] ?? null ) && false === ( $rb_raw['robots_noarchive'] ?? null ), wp_json_encode( array( $rb_raw['robots_noindex'] ?? null, $rb_raw['robots_nofollow'] ?? null, $rb_raw['robots_noarchive'] ?? null ) ) );
	$rb_vals['robots_noarchive'] = true;
	$call( 'POST', '/wp/v2/posts/' . $rb_a, array( 'minn_seo' => $rb_vals ) );
	$check( '08-01 SureRank: switching one on keeps the inherited noindex and nofollow', 'yes' === $rb_meta( $rb_a, 'no_index' ) && 'yes' === $rb_meta( $rb_a, 'no_follow' ) && 'yes' === $rb_meta( $rb_a, 'no_archive' ), wp_json_encode( array( $rb_meta( $rb_a, 'no_index' ), $rb_meta( $rb_a, 'no_follow' ), $rb_meta( $rb_a, 'no_archive' ) ) ) );
	// 14-04: the last 'yes' switched off is stored as 'no', not deleted.
	$rb_b = wp_insert_post( array( 'post_title' => 'Minn v044 surerank robots B', 'post_status' => 'draft' ) );
	update_post_meta( $rb_b, 'surerank_settings_post_no_index', 'yes' );
	list( $rb_vals ) = $rb_seed( $rb_b );
	$rb_vals['robots_noindex'] = null;
	$call( 'POST', '/wp/v2/posts/' . $rb_b, array( 'minn_seo' => $rb_vals ) );
	$check( '14-04 SureRank: switching off the last per-post noindex stores "no"', 'no' === $rb_meta( $rb_b, 'no_index' ), wp_json_encode( $rb_meta( $rb_b, 'no_index' ) ) );
	list( , $rb_raw ) = $rb_seed( $rb_b );
	$check( '14-04 control: and reads back off', false === ( $rb_raw['robots_noindex'] ?? null ), wp_json_encode( $rb_raw['robots_noindex'] ?? null ) );
	wp_delete_post( $rb_a, true );
	wp_delete_post( $rb_b, true );
	null === $rb_was ? delete_option( 'surerank_settings' ) : update_option( 'surerank_settings', $rb_was );
} elseif ( 'Rank Math' === $rb_prov && function_exists( 'rank_math' ) ) {
	// In memory for this process only: the post type noindexes its posts.
	rank_math()->settings->set( 'titles', 'pt_post_custom_robots', 'on' );
	rank_math()->settings->set( 'titles', 'pt_post_robots', array( 'noindex' ) );
	$rb_c = wp_insert_post( array( 'post_title' => 'Minn v044 rank math robots', 'post_status' => 'draft' ) );
	list( $rb_vals, $rb_raw ) = $rb_seed( $rb_c );
	$check( '08-02 Rank Math: a post with no robots of its own still reads Default for indexing', '' === ( $rb_raw['robots_index'] ?? null ), wp_json_encode( $rb_raw['robots_index'] ?? null ) );
	$rb_vals['robots_noarchive'] = true;
	$call( 'POST', '/wp/v2/posts/' . $rb_c, array( 'minn_seo' => $rb_vals ) );
	wp_cache_delete( $rb_c, 'post_meta' );
	$rb_set = (array) get_post_meta( $rb_c, 'rank_math_robots', true );
	$check( '08-02 Rank Math: switching No archive on keeps the post type\'s noindex', in_array( 'noindex', $rb_set, true ) && in_array( 'noarchive', $rb_set, true ), wp_json_encode( $rb_set ) );
	wp_delete_post( $rb_c, true );
} else {
	$skip( '08-x SEO robots: neither SureRank nor Rank Math is the active provider (swap one in)' );
}

// --- 11-01 HappyFiles: Uncategorized follows HappyFiles' own folder settings ---
// With "multiple folders per item" on and "remove from all folders" off,
// HappyFiles' own drop on Uncategorized takes an item out of the folder being
// viewed only (and out of nothing from All). Minn cleared every folder.
// Needs HappyFiles as the provider: MINN_SWAP_DROP=filebird/filebird.php
if ( ! function_exists( 'minn_admin_media_folders_provider' ) || 'HappyFiles' !== ( minn_admin_media_folders_provider()['name'] ?? '' ) ) {
	$skip( '11-01 HappyFiles is not the media folders provider (swap FileBird out)' );
} else {
	$hf_opts = array( 'happyfiles_multiple_folders' => get_option( 'happyfiles_multiple_folders', null ), 'happyfiles_remove_from_all_folders' => get_option( 'happyfiles_remove_from_all_folders', null ) );
	$hf_a    = wp_insert_term( 'Minn RC folder A ' . wp_generate_password( 4, false, false ), 'happyfiles_category' );
	$hf_b    = wp_insert_term( 'Minn RC folder B ' . wp_generate_password( 4, false, false ), 'happyfiles_category' );
	$hf_att  = wp_insert_attachment( array( 'post_title' => 'Minn RC HappyFiles item', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => $admin ) );
	$hf_in   = function () use ( $hf_att ) {
		clean_object_term_cache( $hf_att, 'attachment' );
		$ids = wp_get_object_terms( $hf_att, 'happyfiles_category', array( 'fields' => 'ids' ) );
		sort( $ids );
		return array_map( 'intval', $ids );
	};
	$hf_both = array( (int) $hf_a['term_id'], (int) $hf_b['term_id'] );
	sort( $hf_both );
	$hf_file = function () use ( $hf_att, $hf_both ) {
		wp_set_object_terms( $hf_att, $hf_both, 'happyfiles_category', false );
	};
	update_option( 'happyfiles_multiple_folders', 1 );
	update_option( 'happyfiles_remove_from_all_folders', 0 );
	$hf_file();
	list( $hf_st ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => 0, 'ids' => array( $hf_att ), 'from' => (int) $hf_a['term_id'] ) );
	$check( '11-01 HappyFiles: Uncategorized while viewing a folder removes only that folder', 200 === $hf_st && array( (int) $hf_b['term_id'] ) === $hf_in(), $hf_st . ' ' . wp_json_encode( $hf_in() ) );
	$hf_file();
	list( $hf_st ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => 0, 'ids' => array( $hf_att ), 'from' => 0 ) );
	$check( '11-01 HappyFiles: Uncategorized from All changes nothing', 200 === $hf_st && $hf_both === $hf_in(), $hf_st . ' ' . wp_json_encode( $hf_in() ) );
	update_option( 'happyfiles_remove_from_all_folders', 1 );
	$hf_file();
	list( $hf_st ) = $call( 'POST', '/minn-admin/v1/media/folders/move', array( 'folder' => 0, 'ids' => array( $hf_att ), 'from' => (int) $hf_a['term_id'] ) );
	$check( '11-01 control: with "remove from all folders" on, every folder is cleared', 200 === $hf_st && array() === $hf_in(), $hf_st . ' ' . wp_json_encode( $hf_in() ) );
	foreach ( $hf_opts as $hf_k => $hf_v ) {
		null === $hf_v ? delete_option( $hf_k ) : update_option( $hf_k, $hf_v );
	}
	wp_delete_attachment( $hf_att, true );
	wp_delete_term( (int) $hf_a['term_id'], 'happyfiles_category' );
	wp_delete_term( (int) $hf_b['term_id'], 'happyfiles_category' );
}

// --- 11-02 /image-block takes pictures through the shared attachment gate ---
// The Jetpack Tiled Gallery rebuild door checked read_post only, so a
// Contributor (no upload_files) baked library images into their draft that
// every other picture-taking mapper refuses, and its id list had no ceiling.
// A stand-in image block is registered for this process (Jetpack's is the
// only bundled one).
$ib_hook = function ( $blocks ) {
	$blocks['minn-test/gallery'] = array(
		'label'   => 'Minn test gallery',
		'rebuild' => function ( $images ) {
			return '<!-- minn-test -->' . implode( ',', wp_list_pluck( $images, 'id' ) );
		},
	);
	return $blocks;
};
add_filter( 'minn_admin_image_blocks', $ib_hook );
global $wpdb;
$ib_img = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_parent = 0 ORDER BY ID DESC LIMIT 1" );
if ( ! $ib_img ) {
	$skip( '11-02 no unattached image in the library' );
} else {
	$ib_login = 'minn-ibcontrib-' . wp_generate_password( 6, false, false );
	$ib_user  = wp_insert_user( array( 'user_login' => $ib_login, 'user_email' => $ib_login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => 'contributor' ) );
	wp_set_current_user( $ib_user );
	list( $ib_st ) = $call( 'POST', '/minn-admin/v1/image-block', array( 'block' => 'minn-test/gallery', 'ids' => array( $ib_img ), 'raw' => '' ) );
	$check( '11-02 image-block: a Contributor (no upload_files) cannot bake a library image in', 403 === $ib_st, (string) $ib_st );
	wp_set_current_user( $admin );
	list( $ib_st, $ib_body ) = $call( 'POST', '/minn-admin/v1/image-block', array( 'block' => 'minn-test/gallery', 'ids' => array( $ib_img ), 'raw' => '' ) );
	$check( '11-02 control: an administrator still rebuilds the block', 200 === $ib_st && false !== strpos( (string) ( $ib_body['markup'] ?? '' ), (string) $ib_img ), (string) $ib_st );
	list( $ib_st ) = $call( 'POST', '/minn-admin/v1/image-block', array( 'block' => 'minn-test/gallery', 'ids' => range( 1, 501 ), 'raw' => '' ) );
	$check( '11-02 image-block: more than 500 ids is refused before any lookup', 400 === $ib_st, (string) $ib_st );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $ib_user );
}
remove_filter( 'minn_admin_image_blocks', $ib_hook );

// --- 03-02 WP Migrate: a refused paste never replaces a working key ---
// WP Migrate's own handler keeps a pasted key the server answers "expired",
// "activation turned off" or "API down" for, so the person need not paste it
// again. Over a working key that replaced it with one that cannot serve the
// site. The licence server never hears from this section; the stored key and
// settings are put back exactly.
if ( ! class_exists( '\DeliciousBrains\WPMDB\Pro\License' ) || defined( 'WPMDB_LICENCE' ) ) {
	$skip( '03-02 WP Migrate Pro inactive (or its key is a constant)' );
} else {
	$wm_meta  = 'wpmdb_licence_key';
	$wm_prev  = get_user_meta( $admin, $wm_meta, true );
	$wm_set   = get_site_option( 'wpmdb_settings', null );
	$wm_http  = function ( $pre, $args, $url ) {
		if ( false !== strpos( (string) wp_parse_url( $url, PHP_URL_HOST ), 'deliciousbrains' ) ) {
			return array( 'headers' => array(), 'body' => wp_json_encode( array( 'errors' => array( 'subscription_expired' => 'Expired' ) ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $wm_http, PHP_INT_MAX, 3 );
	update_user_meta( $admin, $wm_meta, 'minntestwpmworkingkey' );
	list( , $wm_body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'wp-migrate', 'action' => 'activate', 'secret' => 'minntestwpmexpiredkey' ) );
	$wm_now = get_user_meta( $admin, $wm_meta, true );
	$check( '03-02 WP Migrate: an expired paste over a working key keeps the working key', empty( $wm_body['ok'] ) && 'minntestwpmworkingkey' === $wm_now, wp_json_encode( array( $wm_body['code'] ?? null, 'minntestwpmworkingkey' === $wm_now ? 'working' : 'replaced' ) ) );
	delete_user_meta( $admin, $wm_meta );
	list( , $wm_body ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'wp-migrate', 'action' => 'activate', 'secret' => 'minntestwpmexpiredkey' ) );
	$check( '03-02 control: with no key stored, the expired key is kept as WP Migrate keeps it', 'minntestwpmexpiredkey' === get_user_meta( $admin, $wm_meta, true ), wp_json_encode( get_user_meta( $admin, $wm_meta, true ) ) );
	remove_filter( 'pre_http_request', $wm_http, PHP_INT_MAX );
	'' === $wm_prev ? delete_user_meta( $admin, $wm_meta ) : update_user_meta( $admin, $wm_meta, $wm_prev );
	null === $wm_set ? delete_site_option( 'wpmdb_settings' ) : update_site_option( 'wpmdb_settings', $wm_set );
	delete_site_transient( 'wpmdb_licence_response_' . $admin );
	delete_site_transient( 'wpmdb_licence_response' );
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
