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

/*
 * 09-L5: Gravity SMTP routing rules were addressed by their position, so a
 * rule added in their screen (or another tab) between the list and a click
 * moved every position, and Disable or Delete landed on a different rule.
 */
( function () use ( $check ) {
	if ( ! function_exists( 'minn_admin_gsmtp_routing_recipes' ) || ! minn_admin_gravity_smtp_active() || ! minn_admin_gsmtp_routing_available() ) {
		echo "SKIP  09-L5 Gravity SMTP routing inactive\n";
		return;
	}
	$was = minn_admin_gsmtp_routing_recipes();
	try {
		$a = array( 'name' => 'minn-rc A', 'enabled' => true, 'connector' => 'generic', 'conditions' => array( 'conjunct' => 'or', 'rules' => array() ) );
		$b = array( 'name' => 'minn-rc B', 'enabled' => true, 'connector' => 'generic', 'conditions' => array( 'conjunct' => 'or', 'rules' => array() ) );
		minn_admin_gsmtp_routing_save( array( $a, $b ) );
		$rows = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/gravity-smtp/routing' ) )->get_data();
		$rows = $rows['items'] ?? $rows;
		$bid  = null;
		foreach ( (array) $rows as $row ) {
			if ( 'minn-rc B' === ( $row['name'] ?? '' ) ) {
				$bid = (int) $row['id'];
			}
		}
		// Their screen adds a rule at the top after the list was read.
		$c = array( 'name' => 'minn-rc C', 'enabled' => true, 'connector' => 'generic', 'conditions' => array( 'conjunct' => 'or', 'rules' => array() ) );
		minn_admin_gsmtp_routing_save( array( $c, $a, $b ) );
		$r     = rest_do_request( new WP_REST_Request( 'POST', "/minn-admin/v1/gravity-smtp/routing/{$bid}/disable" ) );
		$after = array();
		foreach ( minn_admin_gsmtp_routing_recipes() as $recipe ) {
			$after[ $recipe['name'] ] = ! empty( $recipe['enabled'] ) && 'false' !== $recipe['enabled'];
		}
		$check( '09-L5 Disable reaches the rule that was listed (B), not what moved to its place', null !== $bid && false === ( $after['minn-rc B'] ?? null ), wp_json_encode( array( $r->get_status(), $after ) ) );
		$check( '09-L5 the rule now at that position (A) keeps its switch', true === ( $after['minn-rc A'] ?? null ) && true === ( $after['minn-rc C'] ?? null ) );
	} finally {
		minn_admin_gsmtp_routing_save( $was );
		$check( '09-L5 cleanup: the stored rules are back as they were', minn_admin_gsmtp_routing_recipes() == $was ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}
} )();

/*
 * 09-L1: Gravity SMTP's General tab asked only the general-settings cap. Their
 * screen also asks its own cap for test mode (which stops every email) and
 * for the email and debug log settings.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( ! function_exists( 'minn_admin_gravity_smtp_active' ) || ! minn_admin_gravity_smtp_active() || ! class_exists( 'Gravity_Forms\Gravity_SMTP\Users\Roles' ) ) {
		echo "SKIP  09-L1 Gravity SMTP inactive\n";
		return;
	}
	$roles = 'Gravity_Forms\Gravity_SMTP\Users\Roles';
	add_role( 'minn_v048rc_gsmtp', 'GSMTP general', array( 'read' => true, constant( "$roles::VIEW_GENERAL_SETTINGS" ) => true, constant( "$roles::EDIT_GENERAL_SETTINGS" ) => true ) );
	$u      = wp_insert_user( array( 'user_login' => 'minn-v048rc-gs-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v048rc_gsmtp', 'user_email' => 'minn-v048rc-gs-' . wp_rand() . '@example.com' ) );
	$before = minn_admin_gsmtp_settings_shape( 'general' )['values'];
	$post   = function ( $values ) {
		$q = new WP_REST_Request( 'POST', '/minn-admin/v1/gravity-smtp/settings/general' );
		$q->set_header( 'Content-Type', 'application/json' );
		$q->set_body( wp_json_encode( array( 'values' => $values ) ) );
		return rest_do_request( $q );
	};
	try {
		wp_set_current_user( $u );
		foreach ( array( 'test_mode' => ! $before['test_mode'], 'event_log_enabled' => ! $before['event_log_enabled'], 'debug_log_enabled' => ! $before['debug_log_enabled'] ) as $key => $v ) {
			$r = $post( array( $key => $v ) );
			$check( "09-L1 the general-settings cap alone does not change {$key}", 403 === $r->get_status() && minn_admin_gsmtp_settings_shape( 'general' )['values'][ $key ] === $before[ $key ], (string) $r->get_status() );
		}
		wp_set_current_user( $admin[0]->ID );
		$r = $post( array( 'debug_log_retention' => $before['debug_log_retention'] + 1 ) );
		$check( '09-L1 control: an administrator changes the debug log', 200 === $r->get_status() && minn_admin_gsmtp_settings_shape( 'general' )['values']['debug_log_retention'] === $before['debug_log_retention'] + 1, (string) $r->get_status() );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$post( array( 'debug_log_retention' => $before['debug_log_retention'], 'test_mode' => $before['test_mode'], 'event_log_enabled' => $before['event_log_enabled'], 'debug_log_enabled' => $before['debug_log_enabled'] ) );
		$drop_user( $u );
		remove_role( 'minn_v048rc_gsmtp' );
	}
} )();

/*
 * 09-L4: the database browser showed Gravity SMTP's raw message bodies and
 * debug lines to anyone past the preview cap, a reset link's key whole, to
 * an administrator the mail logs hide keys from.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( is_multisite() || ! function_exists( 'minn_admin_gravity_smtp_active' ) || ! minn_admin_gravity_smtp_active() ) {
		echo "SKIP  09-L4 needs a single site with Gravity SMTP\n";
		return;
	}
	global $wpdb;
	// A new administrator a cap map keeps from editing the first one (new,
	// as minn_admin_mail_bodies_open() remembers its answer per account).
	$ad   = wp_insert_user( array( 'user_login' => 'minn-v048rc-ad-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'user_email' => 'minn-v048rc-ad-' . wp_rand() . '@example.com' ) );
	$deny = function ( $caps, $cap, $uid, $args ) use ( $admin, $ad ) {
		return ( 'edit_user' === $cap && (int) $uid === (int) $ad && isset( $args[0] ) && (int) $args[0] === (int) $admin[0]->ID ) ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $deny, 10, 4 );
	wp_set_current_user( $ad );
	try {
		$check( '09-L4 control: this administrator reads mail with keys hidden', ! minn_admin_mail_bodies_open() );
		$check( '09-L4 the events table is refused to the database browser', Minn_Admin_Kernel::table_refused( $wpdb->prefix . 'gravitysmtp_events' ) );
		$check( '09-L4 the debug log table is refused to the database browser', Minn_Admin_Kernel::table_refused( $wpdb->prefix . 'gravitysmtp_debug_log' ) );
	} finally {
		remove_filter( 'map_meta_cap', $deny, 10 );
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $ad );
	}
	$check( '09-L4 control: an administrator who reads mail whole opens both', ! Minn_Admin_Kernel::table_refused( $wpdb->prefix . 'gravitysmtp_events' ) && ! Minn_Admin_Kernel::table_refused( $wpdb->prefix . 'gravitysmtp_debug_log' ) );
} )();

/*
 * 09-L3: the Gravity Forms entry list and detail left out a password field
 * only at the top level; a repeater holding one went out with every row's
 * password, where Gravity Forms' own screens skip it.
 */
( function () use ( $check ) {
	if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GF_Field_Repeater' ) ) {
		echo "SKIP  09-L3 Gravity Forms inactive\n";
		return;
	}
	$form_id = GFAPI::add_form( array(
		'title'  => 'minn-v048rc repeater ' . wp_rand(),
		'fields' => array(
			array( 'type' => 'text', 'id' => 4, 'label' => 'Team' ),
			array(
				'type'   => 'repeater',
				'id'     => 1,
				'label'  => 'Members',
				'fields' => array(
					array( 'type' => 'text', 'id' => 3, 'label' => 'Name' ),
					array( 'type' => 'password', 'id' => 2, 'label' => 'Password' ),
				),
			),
		),
	) );
	if ( is_wp_error( $form_id ) ) {
		$check( '09-L3 a repeater form was made', false, $form_id->get_error_message() );
		return;
	}
	global $wpdb;
	$entry_id = GFAPI::add_entry( array( 'form_id' => $form_id, '4' => 'Ferns' ) );
	// A repeater's rows are stored under each sub-field's id with the row in
	// item_index, which add_entry() does not write; their submission does.
	foreach ( array( '3' => 'Ana', '2' => 'RepeaterSecret01' ) as $k => $v ) {
		$wpdb->insert( $wpdb->prefix . 'gf_entry_meta', array( 'form_id' => $form_id, 'entry_id' => $entry_id, 'meta_key' => $k, 'meta_value' => $v, 'item_index' => '_0' ) );
	}
	GFFormsModel::flush_current_lead();
	try {
		$list   = rest_do_request( new WP_REST_Request( 'GET', "/minn-admin/v1/gf/forms/{$form_id}/entries" ) );
		$detail = rest_do_request( new WP_REST_Request( 'GET', "/minn-admin/v1/gf/entries/{$entry_id}" ) );
		$check( '09-L3 the entry list leaves out a repeater that holds a password', 200 === $list->get_status() && false === strpos( wp_json_encode( $list->get_data() ), 'RepeaterSecret01' ), (string) $list->get_status() );
		$check( '09-L3 the entry detail leaves it out too', 200 === $detail->get_status() && false === strpos( wp_json_encode( $detail->get_data() ), 'RepeaterSecret01' ), (string) $detail->get_status() );
		$check( '09-L3 control: the entry\'s other answers are listed', false !== strpos( wp_json_encode( $list->get_data() ), 'Ferns' ) );
	} finally {
		GFAPI::delete_entry( $entry_id );
		GFAPI::delete_form( $form_id );
	}
} )();

/*
 * 09-L6: a design insert reused any attachment with the image's file name,
 * one on another author's private post included, and handed its id and URL
 * to an author who could not open it.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( ! function_exists( 'minn_admin_localize_images' ) ) {
		echo "SKIP  09-L6 media localize not loaded\n";
		return;
	}
	$name    = 'minn-v048rc-' . wp_rand() . '.jpg';
	$private = wp_insert_post( array( 'post_title' => 'minn-v048rc private', 'post_status' => 'private', 'post_author' => $admin[0]->ID ) );
	$att     = wp_insert_attachment( array( 'post_title' => 'minn-v048rc att', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_author' => $admin[0]->ID ), '2026/10/' . $name, $private );
	update_post_meta( $att, '_wp_attached_file', '2026/10/' . $name );
	$au = wp_insert_user( array( 'user_login' => 'minn-v048rc-au2-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => 'minn-v048rc-au2-' . wp_rand() . '@example.com' ) );
	// A host that answers nothing, so a fresh copy cannot be fetched.
	$tpl = '<!-- wp:image --><figure class="wp-block-image"><img src="https://minn-rc.invalid/img/' . $name . '"/></figure><!-- /wp:image -->';
	try {
		wp_set_current_user( $au );
		$out = minn_admin_localize_images( $tpl );
		$check( '09-L6 an author is not handed an attachment on another author\'s private post', ! in_array( $att, $out['attachments'], true ), wp_json_encode( $out['attachments'] ) );
		wp_set_current_user( $admin[0]->ID );
		$out = minn_admin_localize_images( $tpl );
		$check( '09-L6 control: its owner still reuses it', in_array( $att, $out['attachments'], true ), wp_json_encode( $out['attachments'] ) );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $au );
		wp_delete_post( $att, true );
		wp_delete_post( $private, true );
	}
} )();

/*
 * 07-L1 (not reproduced, kept as a guard): ACPT's own screen hides an option
 * page field a role's field permissions keep from it. Minn's option tabs
 * follow them through minn_admin_acpt_map_field(), which maps no field the
 * role may not both read and edit, so it is neither served nor saved.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( ! function_exists( 'minn_admin_acpt_option_tabs' ) || ! minn_admin_acpt_active() || ! class_exists( '\ACPT\Core\CQRS\Command\SaveMetaGroupCommand' ) || ! class_exists( '\ACPT\Core\CQRS\Command\SavePermissionCommand' ) ) {
		echo "SKIP  07-L1 ACPT inactive\n";
		return;
	}
	$sfx  = substr( md5( uniqid( '', true ) ), 0, 8 );
	$slug = 'minn-v048rc-opts-' . $sfx;
	$box  = 'minn_v048rc_box' . $sfx;
	$gid  = '';
	try {
		$gid = ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
			'name'    => 'minn-v048rc-acpt-' . $sfx,
			'label'   => 'Minn v048 RC ACPT',
			'belongs' => array( array( 'belongsTo' => 'optionPage', 'operator' => '=', 'find' => $slug, 'logic' => '' ) ),
			'boxes'   => array( array( 'name' => $box, 'label' => 'Box', 'fields' => array(
				array( 'name' => 'secret', 'type' => 'Text', 'label' => 'Secret' ),
				array( 'name' => 'shown', 'type' => 'Text', 'label' => 'Shown' ),
			) ) ),
		) ) )->execute();
	} catch ( \Throwable $e ) {
		echo 'SKIP  07-L1 could not build the fixture group (' . $e->getMessage() . ")\n";
		return;
	}
	$ids = array();
	foreach ( \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $gid ) )[0]->getBoxes() as $b ) {
		foreach ( $b->getFields() as $f ) {
			$ids[ $f->getName() ] = $f->getId();
		}
	}
	// Field permissions on "secret": ACPT then asks each role for
	// read_<box>_<field> and edit_<box>_<field>. It takes only a role that
	// exists.
	$type = \ACPT\Core\Helper\Strings::toDBFormat( $box ) . '_secret';
	add_role( 'minn_v048rc_acpt', 'ACPT reader', array( 'read' => true ) );
	add_role( 'minn_v048rc_acpt_ok', 'ACPT granted', array( 'read' => true, 'read_' . $type => true, 'edit_' . $type => true ) );
	( new \ACPT\Core\CQRS\Command\SavePermissionCommand( array(
		'entityId' => $ids['secret'],
		'items'    => array( array( 'id' => wp_generate_uuid4(), 'userRole' => 'minn_v048rc_acpt', 'permissions' => array( 'read' => true, 'edit' => true ) ) ),
	) ) )->execute();
	$no  = wp_insert_user( array( 'user_login' => 'minn-v048rc-ac-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v048rc_acpt', 'user_email' => 'minn-v048rc-ac-' . wp_rand() . '@example.com' ) );
	$yes = wp_insert_user( array( 'user_login' => 'minn-v048rc-ac2-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v048rc_acpt_ok', 'user_email' => 'minn-v048rc-ac2-' . wp_rand() . '@example.com' ) );
	$offered = function () use ( $slug ) {
		$out = array();
		foreach ( minn_admin_acpt_option_tabs( $slug ) as $t ) {
			$out += $t['lookup'];
		}
		return $out;
	};
	try {
		wp_set_current_user( $no );
		$got = $offered();
		$check( '07-L1 a role the field\'s permissions leave out is not offered it', ! isset( $got[ $ids['secret'] ] ) && isset( $got[ $ids['shown'] ] ), wp_json_encode( array_keys( $got ) ) );
		wp_set_current_user( $yes );
		$got = $offered();
		$check( '07-L1 control: a role granted it is offered it', isset( $got[ $ids['secret'] ] ), wp_json_encode( array_keys( $got ) ) );
	} finally {
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $no );
		$drop_user( $yes );
		remove_role( 'minn_v048rc_acpt' );
		remove_role( 'minn_v048rc_acpt_ok' );
		( new \ACPT\Core\CQRS\Command\SavePermissionCommand( array( 'entityId' => $ids['secret'], 'items' => array() ) ) )->execute();
		if ( class_exists( '\ACPT\Core\CQRS\Command\DeleteMetaGroupCommand' ) ) {
			( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $gid ) )->execute();
		}
	}
} )();

/*
 * 08-L2: Check for updates flushed the vendors' plugin update caches (and
 * asked Gravity Forms' servers) for someone who may only update languages.
 */
( function () use ( $check, $drop_user, $admin ) {
	if ( ! function_exists( 'minn_admin_force_update_check' ) ) {
		echo "SKIP  08-L2 licences not loaded\n";
		return;
	}
	$ran  = 0;
	$hook = function () use ( &$ran ) {
		++$ran;
	};
	add_action( 'minn_admin_flush_vendor_update_caches', $hook );
	add_role( 'minn_v048rc_lang', 'Languages', array( 'read' => true, 'update_languages' => true ) );
	$u = wp_insert_user( array( 'user_login' => 'minn-v048rc-lg-' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v048rc_lang', 'user_email' => 'minn-v048rc-lg-' . wp_rand() . '@example.com' ) );
	// No outbound calls from the check itself.
	$block = function () {
		return new WP_Error( 'minn_test_offline', 'offline' );
	};
	add_filter( 'pre_http_request', $block );
	try {
		wp_set_current_user( $u );
		minn_admin_force_update_check( 'languages' );
		$check( '08-L2 a languages-only check leaves the plugin update caches', 0 === $ran, (string) $ran );
		wp_set_current_user( $admin[0]->ID );
		minn_admin_force_update_check( 'languages' );
		$check( '08-L2 control: an administrator\'s check flushes them', $ran > 0, (string) $ran );
	} finally {
		remove_filter( 'pre_http_request', $block );
		remove_action( 'minn_admin_flush_vendor_update_caches', $hook );
		wp_set_current_user( $admin[0]->ID );
		$drop_user( $u );
		remove_role( 'minn_v048rc_lang' );
	}
} )();

/*
 * 05-L1: a batch's progress record was a file named by the token its GET URL
 * carries, so where .htaccess is ignored the web server handed it out by
 * that name with no age check, and the temp dir's paths stayed in it.
 */
( function () use ( $check ) {
	$token = substr( hash( 'sha256', uniqid( '', true ) ), 0, 40 );
	$path  = Minn_Admin_REST::progress_file_path( $token );
	if ( '' === $path ) {
		echo "SKIP  05-L1 this layout writes no progress file\n";
		return;
	}
	try {
		$check( '05-L1 the record is not filed under the URL\'s token', $token . '.json' !== basename( $path ), basename( $path ) );
		Minn_Admin_REST::progress_write( $token, $path, array( 'items' => array( array( 'error' => 'Could not copy ' . trailingslashit( get_temp_dir() ) . 'minn-rc-x.zip' ) ) ) );
		$raw = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$check( '05-L1 the temp dir\'s path is stripped from the public record', '' !== $raw && false === strpos( str_replace( '\\/', '/', $raw ), untrailingslashit( get_temp_dir() ) ), $raw );
		$r = wp_remote_get( plugins_url( 'progress.php', MINN_ADMIN_DIR . 'minn-admin.php' ) . '?t=' . $token, array( 'sslverify' => false, 'timeout' => 20 ) );
		$d = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		$check( '05-L1 control: progress.php still reads it by the token', is_array( $d ) && ! empty( $d['items'] ), substr( (string) wp_remote_retrieve_body( $r ), 0, 160 ) );
	} finally {
		Minn_Admin_REST::progress_expire( $token );
		delete_transient( 'minn_bulk_update_' . $token );
		wp_delete_file( $path );
	}
} )();

$summary();
