<?php
/**
 * Regressions for the v0.47.0 shipped-zip registry scan (findings that open
 * v0.48.0).
 *
 * Each section checks as the role the finding named, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive.
 *
 * Run: wp eval-file tests/security-v048-registry.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v048-registry: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
wp_set_current_user( $admin[0]->ID );

/*
 * Mail-1: logged mail read whole on a single site asked edit_users, which
 * WooCommerce grants a Shop Manager while narrowing edit_user to customers,
 * so an administrator's reset key in a mail log was theirs to read.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( is_multisite() || ! function_exists( 'minn_admin_mail_bodies_open' ) ) {
		echo "SKIP  Mail-1 needs a single site with the mail kit loaded\n";
		return;
	}
	if ( ! class_exists( 'WooCommerce' ) || ! get_role( 'shop_manager' ) ) {
		echo "SKIP  Mail-1 WooCommerce inactive\n";
		return;
	}
	$login = $admin[0]->user_login;
	$body  = 'Reset: ' . network_site_url( 'wp-login.php?action=rp&key=SECRETKEY123&login=' . rawurlencode( $login ) );
	$sm    = wp_insert_user( array( 'user_login' => 'minn-v048-sm-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'minn-v048-sm-' . wp_rand() . '@example.com' ) );
	try {
		wp_set_current_user( $sm );
		$check( 'Mail-1 a Shop Manager has edit_users (the premise)', current_user_can( 'edit_users' ) && ! current_user_can( 'edit_user', $admin[0]->ID ) );
		$check( 'Mail-1 a Shop Manager does not read mail bodies whole', ! minn_admin_mail_bodies_open() );
		$masked = minn_admin_mail_mask_reset_keys( $body );
		$check( 'Mail-1 an administrator\'s reset key is hidden from a Shop Manager', false === strpos( $masked, 'SECRETKEY123' ), $masked );
		wp_set_current_user( $admin[0]->ID );
		$check( 'Mail-1 control: an administrator reads bodies whole', minn_admin_mail_bodies_open() && false !== strpos( minn_admin_mail_mask_reset_keys( $body ), 'SECRETKEY123' ) );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $sm );
	}
} )();

/*
 * Case-1: core matches routes without regard to case and keeps the spelling,
 * so /Minn-Admin/v1/... ran Minn's handlers outside the egress mask and the
 * write fences. Any spelling but the registered one is refused.
 */
( function () use ( $check ) {
	foreach ( array( '/Minn-Admin/v1/overview', '/minn-admin/V1/overview', '/MINN-ADMIN/v1/notifications' ) as $route ) {
		$res = rest_do_request( new WP_REST_Request( 'GET', $route ) );
		$err = $res->as_error();
		$check( "Case-1 {$route} is refused before its handler", 400 === $res->get_status() && $err && 'minn_admin_route_case' === $err->get_error_code(), $res->get_status() . ' ' . wp_json_encode( $res->get_data() ) );
	}
	$res = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/overview' ) );
	$check( 'Case-1 control: the registered spelling still answers', 200 === $res->get_status(), (string) $res->get_status() );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/types/post' ) );
	$check( 'Case-1 control: another namespace is untouched', 200 === $res->get_status(), (string) $res->get_status() );
} )();

/*
 * ACF-1: the ACF block preview hook rendered through ACF's preview path with
 * no post for any edit_posts caller; ACF's own preview asks
 * acf/blocks/render_capability there.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( ! function_exists( 'acf_has_block_type' ) || ! acf_has_block_type( 'acf/minn-inline' ) ) {
		echo "SKIP  ACF-1 needs ACF PRO and the acf/minn-inline fixture\n";
		return;
	}
	$markup = '<!-- wp:acf/minn-inline {"name":"acf/minn-inline","data":{},"mode":"preview"} /-->';
	$render = function ( $post ) use ( $markup ) {
		$r = new WP_REST_Request( 'POST', '/minn-admin/v1/render-blocks' );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( array( 'blocks' => array( $markup ), 'post' => $post ) ) );
		$d = rest_do_request( $r )->get_data();
		return (string) ( $d['rendered'][0] ?? '' );
	};
	$mode = function ( $html ) {
		return preg_match( '#class="acfi-mode">(\w+)<#', $html, $m ) ? $m[1] : '';
	};
	$cid = wp_insert_user( array( 'user_login' => 'minn-v048-co-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor', 'user_email' => 'minn-v048-co-' . wp_rand() . '@example.com' ) );
	$own = wp_insert_post( array( 'post_title' => 'minn v048 acf own', 'post_status' => 'draft', 'post_author' => $cid ) );
	try {
		wp_set_current_user( $cid );
		$check( 'ACF-1 a Contributor with no post gets the front-end render', 'front' === $mode( $render( 0 ) ), $mode( $render( 0 ) ) );
		$check( 'ACF-1 a Contributor on a post they cannot edit gets the front-end render', 'front' === $mode( $render( 1 ) ), $mode( $render( 1 ) ) );
		$check( 'ACF-1 control: a Contributor on their own draft gets the preview', 'preview' === $mode( $render( $own ) ), $mode( $render( $own ) ) );
		wp_set_current_user( $admin[0]->ID );
		$check( 'ACF-1 control: an administrator with no post gets the preview', 'preview' === $mode( $render( 0 ) ), $mode( $render( 0 ) ) );
		$check( 'ACF-1 the preview filter comes off after the render', 'front' === $mode( do_blocks( $markup ) ), 'a later render took the preview path' );
		$narrow = function () {
			return 'minn_v048_nobody_holds_this';
		};
		add_filter( 'acf/blocks/render_capability', $narrow );
		$check( 'ACF-1 a site narrowing render_capability is honoured', 'front' === $mode( $render( 0 ) ), $mode( $render( 0 ) ) );
		remove_filter( 'acf/blocks/render_capability', $narrow );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		wp_delete_post( $own, true );
		$drop_user( $cid );
	}
} )();

/*
 * Sucuri-1: Sucuri's audit queue is install-wide, so it asks install_owner()
 * like the sibling install-wide stores, not network_owner().
 */
( function () use ( $check ) {
	$src = (string) file_get_contents( dirname( __DIR__ ) . '/includes/adapters/sucuri.php' );
	$check( 'Sucuri-1 the audit queue gate asks install_owner()', (bool) preg_match( '#function minn_admin_sucuri_can\(\)[^}]*Minn_Admin::install_owner\(\)#s', $src ) );
} )();

/*
 * TM-1: the Transients list cut a value to 120 characters before the egress
 * fence saw it, so a key straddling the cut left its prefix behind.
 */
( function () use ( $check ) {
	if ( ! function_exists( 'minn_admin_tm_row' ) ) {
		echo "SKIP  TM-1 Transients Manager adapter not loaded\n";
		return;
	}
	// A credential the fence knows (a core Connector key row, redacted by
	// its name shape) copied into a long transient, placed so the
	// 120-character cut falls inside it.
	$forget  = static function () {
		Minn_Admin_DB::forget_credentials();
		Minn_Admin_Egress::reset();
	};
	$key_row = 'connectors_minn048tm' . wp_rand() . '_api_key';
	$secret  = 'mTm048Kx7Q2' . strtolower( wp_generate_password( 12, false, false ) ) . '94Ly3Zt8';
	$name    = 'minn_v048_tm_' . wp_rand();
	add_option( $key_row, $secret, '', 'no' );
	$forget();
	set_transient( $name, str_repeat( 'x', 110 ) . $secret . ' tail', 600 );
	try {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $name ) );
		$out = minn_admin_tm_row( $row, 0, time() );
		$check( 'TM-1 fixture: the fence counts the probe key as a credential', in_array( $secret, Minn_Admin_DB::egress_credentials(), true ) );
		$check( 'TM-1 no prefix of a straddling key survives the cut', false === strpos( (string) $out['preview'], 'mTm048Kx' ), (string) $out['preview'] );
	} finally {
		delete_transient( $name );
		delete_option( $key_row );
		$forget();
	}
} )();

/*
 * Mask-1: reset links in shapes the mask did not know (Ultimate Member,
 * MemberPress, a percent-encoded link inside a click-tracking wrapper, other
 * entity spellings of "&"), and a key cut by quoted-printable soft breaks in
 * a logged SMTP transcript, stayed readable to someone the log hides keys
 * from.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( is_multisite() || ! function_exists( 'minn_admin_mail_mask_reset_keys' ) ) {
		echo "SKIP  Mask-1 needs a single site with the mail kit loaded\n";
		return;
	}
	$site  = home_url( '/' );
	$login = wp_login_url();
	$cases = array(
		'Ultimate Member'          => "{$site}password-reset/?act=reset_password&hash=UmKey0123456789abcdef&login=root",
		'MemberPress'              => "{$site}login/?action=reset_password&mkey=MpKey0123456789abcdef&u=root",
		'an encoded wrapper'       => 'https://click.example/t?u=' . rawurlencode( "{$login}?action=rp&key=WrapKey0123456789ab&login=root" ),
		'&#x26; separators'        => "<a href=\"{$login}?action=rp&#x26;key=HexKey0123456789abc&#x26;login=root\">Reset</a>",
		'&#0038; separators'       => "<a href=\"{$login}?action=rp&#0038;key=PadKey0123456789abc&#0038;login=root\">Reset</a>",
		'&amp;amp; separators'     => "<a href=\"{$login}?action=rp&amp;amp;key=AmpKey0123456789abc&amp;amp;login=root\">Reset</a>",
	);
	$link  = "{$login}?action=rp&key=QpKey0123456789abcdefgh&login=root";
	$qp    = quoted_printable_encode( str_repeat( 'Hello there. ', 6 ) . '<a href="' . $link . '">Reset</a>' );
	$cases['an SMTP transcript cut by soft breaks'] = implode( "\n", array_map( function ( $l ) {
		return '2026-10-28 10:00:00 CLIENT -> SERVER: ' . $l;
	}, preg_split( '/\r?\n/', $qp ) ) );
	$keys  = array( 'UmKey0123456789abcdef', 'MpKey0123456789abcdef', 'WrapKey0123456789ab', 'HexKey0123456789abc', 'PadKey0123456789abc', 'AmpKey0123456789abc', 'QpKey0123456789abcdefgh' );
	$ed    = wp_insert_user( array( 'user_login' => 'minn-v048-ed-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'minn-v048-ed-' . wp_rand() . '@example.com' ) );
	try {
		wp_set_current_user( $ed );
		$i = 0;
		foreach ( $cases as $label => $body ) {
			$out = minn_admin_mail_mask_reset_keys( $body );
			$key = $keys[ $i++ ];
			// The transcript's key may be cut, so look for any 8 of it.
			$leak = false;
			for ( $o = 0; $o + 8 <= strlen( $key ); $o++ ) {
				$leak = $leak || false !== strpos( str_replace( array( "=\r\n", "=\n" ), '', $out ), substr( $key, $o, 8 ) );
			}
			$check( "Mask-1 {$label}: the key is hidden from a reader the log hides keys from", ! $leak, substr( $out, 0, 220 ) );
		}
		$order = "{$site}checkout/order-received/7/?key=wc_order_KeepMe0123456";
		$check( 'Mask-1 control: an order link keeps its key', minn_admin_mail_mask_reset_keys( $order ) === $order );
		$plain = "{$login}?action=rp&key=PlainKey0123456789ab&login=root";
		$check( 'Mask-1 control: a plain core link is still masked', false === strpos( minn_admin_mail_mask_reset_keys( $plain ), 'PlainKey0123456789ab' ) );
		wp_set_current_user( $admin[0]->ID );
		$check( 'Mask-1 control: an administrator reads every body as logged', minn_admin_mail_mask_reset_keys( $cases['Ultimate Member'] ) === $cases['Ultimate Member'] );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $ed );
	}
} )();

/*
 * GF-1: a notification or confirmation preview resolved a password field's
 * tag from the latest entry, which with gform_encrypt_password on holds the
 * submitter's password decrypted; Gravity Forms' entry screen and
 * {all_fields} skip it.
 */
( function () use ( $check ) {
	if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_preview_tags' ) ) {
		echo "SKIP  GF-1 Gravity Forms inactive\n";
		return;
	}
	$form_id = GFAPI::add_form( array(
		'title'         => 'minn v048 password preview ' . wp_rand(),
		'fields'        => array(
			array( 'id' => 1, 'type' => 'text', 'label' => 'Answer' ),
			array( 'id' => 2, 'type' => 'password', 'label' => 'Password' ),
		),
		'notifications' => array(
			'n1' => array( 'id' => 'n1', 'name' => 'Admin', 'to' => '{admin_email}', 'toType' => 'email', 'event' => 'form_submission', 'subject' => 'New', 'message' => '{all_fields}', 'isActive' => true ),
		),
		'confirmations' => array(
			'c1' => array( 'id' => 'c1', 'name' => 'Default', 'type' => 'message', 'message' => 'Thanks', 'isDefault' => true ),
		),
	) );
	if ( is_wp_error( $form_id ) ) {
		echo "SKIP  GF-1 form not created\n";
		return;
	}
	$entry_id = GFAPI::add_entry( array( 'form_id' => $form_id, '1' => 'carried {Password:2}', '2' => 'MinnPlainSecret048' ) );
	try {
		$r = new WP_REST_Request( 'POST', "/minn-admin/v1/gf/notifications/{$form_id}:n1/preview" );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( array( 'subject' => 'S {Password:2}', 'message' => 'A {Password:2} B {:2} C {Answer:1} D {all_fields}' ) ) );
		$res = rest_do_request( $r );
		$out = wp_json_encode( $res->get_data() );
		$check( 'GF-1 the preview rendered from the entry (premise)', 200 === $res->get_status() && false !== strpos( $out, 'carried' ), $res->get_status() . ' ' . substr( $out, 0, 160 ) );
		$check( 'GF-1 no password reaches a notification preview, typed or carried in an answer', false === strpos( $out, 'MinnPlainSecret048' ), substr( $out, 0, 300 ) );
		$r = new WP_REST_Request( 'POST', "/minn-admin/v1/gf/confirmations/{$form_id}:c1/preview" );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( array( 'message' => 'X {Password:2} Y {:2}' ) ) );
		$out = wp_json_encode( rest_do_request( $r )->get_data() );
		$check( 'GF-1 no password reaches a confirmation preview', false === strpos( $out, 'MinnPlainSecret048' ), substr( $out, 0, 200 ) );
	} finally {
		GFAPI::delete_form( $form_id );
	}
} )();

/*
 * GSMTP-1: switching Gravity SMTP's primary service replaced the whole
 * enabled_connector map, so every other enabled integration (the backup,
 * a routing target) read as disabled.
 */
( function () use ( $check ) {
	if ( ! class_exists( 'Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store' ) ) {
		echo "SKIP  GSMTP-1 Gravity SMTP inactive\n";
		return;
	}
	$plugin = new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store();
	$opts   = new Gravity_Forms\Gravity_SMTP\Data_Store\Opts_Data_Store();
	$keep   = array();
	foreach ( array( 'enabled_connector', 'primary_connector', 'backup_connector' ) as $map ) {
		$keep[ $map ] = $plugin->get( $map, 'config', array() );
	}
	$flags = array();
	foreach ( array( 'generic', 'postmark', 'sendgrid' ) as $c ) {
		foreach ( array( 'enabled', 'is_primary', 'is_backup' ) as $f ) {
			$flags[ $c ][ $f ] = $opts->get( $f, $c );
		}
	}
	$plugin->save( 'enabled_connector', array( 'generic' => true, 'postmark' => true, 'sendgrid' => true ) );
	$plugin->save( 'primary_connector', array( 'generic' => true ) );
	$plugin->save( 'backup_connector', array( 'postmark' => true ) );
	$opts->save( 'is_primary', true, 'generic' );
	$opts->save( 'is_backup', true, 'postmark' );
	$opts->save( 'enabled', true, 'postmark' );
	try {
		$r = new WP_REST_Request( 'POST', '/minn-admin/v1/gravity-smtp/settings/sending' );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( array( 'values' => array( 'primary_connector' => 'sendgrid' ) ) ) );
		$res     = rest_do_request( $r );
		$enabled = (array) $plugin->get( 'enabled_connector', 'config', array() );
		$primary = (array) $plugin->get( 'primary_connector', 'config', array() );
		$check( 'GSMTP-1 the switch saved (premise)', 200 === $res->get_status() && ! empty( $primary['sendgrid'] ), $res->get_status() . ' ' . wp_json_encode( $primary ) );
		$check( 'GSMTP-1 the backup stays enabled', ! empty( $enabled['postmark'] ) && ! empty( $enabled['generic'] ) && ! empty( $enabled['sendgrid'] ), wp_json_encode( $enabled ) );
		$check( 'GSMTP-1 one primary', empty( $primary['generic'] ) && ! $opts->get( 'is_primary', 'generic' ), wp_json_encode( $primary ) );
		$backup = (array) $plugin->get( 'backup_connector', 'config', array() );
		$check( 'GSMTP-1 the backup stays the backup', ! empty( $backup['postmark'] ), wp_json_encode( $backup ) );
	} finally {
		foreach ( $keep as $map => $v ) {
			$plugin->save( $map, is_array( $v ) ? $v : array() );
		}
		foreach ( $flags as $c => $fs ) {
			foreach ( $fs as $f => $v ) {
				$opts->save( $f, $v, $c );
			}
		}
	}
} )();

$summary();
