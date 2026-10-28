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

$summary();
