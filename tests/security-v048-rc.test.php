<?php
/**
 * Regressions for the v0.48.0 release-candidate audit (RC 5ea840af).
 *
 * Each section checks as the role the finding named, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive.
 *
 * Run: wp eval-file tests/security-v048-rc.test.php --user=admin --path=<site>
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
	printf( "\nsecurity-v048-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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
 * Mail (06-L1, 10-L1, 10-L2): the reset-key mask skipped any body without
 * "key=" or "key%3D" written whole, before a transcript's soft breaks were
 * joined. A break inside the word, a doubly encoded link and an "=" written
 * as an entity all came back with the key readable.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( is_multisite() || ! function_exists( 'minn_admin_mail_mask_reset_keys' ) ) {
		echo "SKIP  Mail needs a single site with the mail kit loaded\n";
		return;
	}
	$login = wp_login_url();
	$pre   = '2026-10-28 10:00:00 CLIENT -> SERVER: ';
	$cases = array(
		'a soft break inside "key"'  => "{$pre}<a href=3D\"{$login}?action=3Drp&k=\n{$pre}ey=3DSplitKey0123456789ab&login=3Droot\">Reset</a>",
		'a soft break inside "hash"' => "{$pre}<a href=3D\"{$login}?action=3Drp&ha=\n{$pre}sh=3DHashSplit0123456789&login=3Droot\">Reset</a>",
		'a doubly encoded wrapper'   => 'https://click.example/t?u=' . rawurlencode( rawurlencode( "{$login}?action=rp&key=TwiceKey0123456789ab&login=root" ) ),
		'"=" as &#61;'               => "<a href=\"{$login}?action=rp&amp;key&#61;DecKey0123456789abc&amp;login=root\">Reset</a>",
		'"=" as &#x3d;'              => "<a href=\"{$login}?action=rp&amp;key&#x3d;HexEqKey0123456789a&amp;login=root\">Reset</a>",
		'"=" as &equals;'            => "<a href=\"{$login}?action=rp&amp;key&equals;NamedKey0123456789a&amp;login=root\">Reset</a>",
	);
	$keys = array( 'SplitKey0123456789ab', 'HashSplit0123456789', 'TwiceKey0123456789ab', 'DecKey0123456789abc', 'HexEqKey0123456789a', 'NamedKey0123456789a' );
	$ed   = wp_insert_user( array( 'user_login' => 'minn-v048rc-ed-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'minn-v048rc-ed-' . wp_rand() . '@example.com' ) );
	try {
		wp_set_current_user( $ed );
		$i = 0;
		foreach ( $cases as $label => $body ) {
			$out = minn_admin_mail_mask_reset_keys( $body );
			$key = $keys[ $i++ ];
			$check( "Mail {$label}: the key is hidden from a reader the log hides keys from", false === strpos( $out, substr( $key, 0, 12 ) ), substr( $out, 0, 220 ) );
		}
		$order = home_url( '/checkout/order-received/7/?key=wc_order_KeepMe0123456' );
		$check( 'Mail control: an order link keeps its key', minn_admin_mail_mask_reset_keys( $order ) === $order );
		$prose = "Your key=value pairs are saved.\nNothing to hide here.";
		$check( 'Mail control: a body without a link comes back as logged', minn_admin_mail_mask_reset_keys( $prose ) === $prose );
		wp_set_current_user( $admin[0]->ID );
		$check( 'Mail control: an administrator reads every body as logged', minn_admin_mail_mask_reset_keys( $cases['"=" as &#61;'] ) === $cases['"=" as &#61;'] );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $ed );
	}
} )();

/*
 * 02-L1: the Media view's month filter counted every upload on the site per
 * month for anyone with edit_posts. Core's Media screens ask upload_files,
 * which a Contributor does not have.
 */
( function () use ( $check, $drop_user, $admin ) {
	$co = wp_insert_user( array( 'user_login' => 'minn-v048rc-co-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor', 'user_email' => 'minn-v048rc-co-' . wp_rand() . '@example.com' ) );
	$au = wp_insert_user( array( 'user_login' => 'minn-v048rc-au-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => 'minn-v048rc-au-' . wp_rand() . '@example.com' ) );
	try {
		wp_set_current_user( $co );
		$r = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/media/months' ) );
		$check( '02-L1 a Contributor does not get the site\'s upload counts', 403 === $r->get_status(), (string) $r->get_status() );
		wp_set_current_user( $au );
		$r = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/media/months' ) );
		$check( '02-L1 control: an Author, who can upload, gets the months', 200 === $r->get_status(), (string) $r->get_status() );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $co );
		$drop_user( $au );
	}
} )();

/*
 * 02-L2: a coupon's orders listed every order that used it, ids and total,
 * for anyone with edit_shop_orders. A marketplace vendor holds that without
 * edit_others_shop_orders and learned other sellers' order volume.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( ! class_exists( 'WooCommerce' ) || ! get_role( 'shop_manager' ) ) {
		echo "SKIP  02-L2 WooCommerce inactive\n";
		return;
	}
	$coupon = wp_insert_post( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'post_title' => 'minn-v048rc-' . wp_rand() ) );
	add_role( 'minn_v048rc_vendor', 'Vendor', array( 'read' => true, 'edit_shop_orders' => true, 'edit_shop_coupons' => true, 'edit_others_shop_coupons' => true, 'edit_published_shop_coupons' => true ) );
	$ve = wp_insert_user( array( 'user_login' => 'minn-v048rc-ve-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v048rc_vendor', 'user_email' => 'minn-v048rc-ve-' . wp_rand() . '@example.com' ) );
	$sm = wp_insert_user( array( 'user_login' => 'minn-v048rc-sm-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'shop_manager', 'user_email' => 'minn-v048rc-sm-' . wp_rand() . '@example.com' ) );
	try {
		wp_set_current_user( $ve );
		$r = rest_do_request( new WP_REST_Request( 'GET', "/minn-admin/v1/coupons/{$coupon}/orders" ) );
		$check( '02-L2 edit_shop_orders without edit_others_shop_orders does not list a coupon\'s orders', 403 === $r->get_status(), (string) $r->get_status() );
		wp_set_current_user( $sm );
		$r = rest_do_request( new WP_REST_Request( 'GET', "/minn-admin/v1/coupons/{$coupon}/orders" ) );
		$check( '02-L2 control: a Shop Manager lists them', 200 === $r->get_status(), (string) $r->get_status() );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $ve );
		$drop_user( $sm );
		remove_role( 'minn_v048rc_vendor' );
		wp_delete_post( $coupon, true );
	}
} )();

/*
 * 08-L1: Otter Blocks keeps a Stripe secret key, an OpenAI key and its
 * reCAPTCHA and Turnstile secrets as plain option rows, none declared, so the
 * database browser showed them and the egress mask never looked for them.
 */
( function () use ( $check ) {
	$names = Minn_Admin_Kernel_Secrets::option_names();
	foreach ( array( 'themeisle_stripe_api_key', 'themeisle_open_ai_api_key', 'themeisle_google_captcha_api_secret_key', 'themeisle_cloudflare_turnstile_secret_key' ) as $row ) {
		$check( "08-L1 {$row} is declared a secret", in_array( $row, $names, true ) );
	}
	$check( '08-L1 control: the browser-side map key is not', ! in_array( 'themeisle_google_map_block_api_key', $names, true ) );
} )();

$summary();
