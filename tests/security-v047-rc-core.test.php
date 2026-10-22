<?php
/**
 * v0.47.0 RC audit, core REST.
 *
 * - Order emails (03-L1): any of the eleven order emails went out for any
 *   order, a "Your order is complete" for an order still pending among them,
 *   and one the store had switched off was sent anyway for anyone who could
 *   edit the order. Now an email goes out only when it fits the order as it
 *   stands (WooCommerce's own rule for sending one from an order), and a
 *   switched-off one only for someone who manages WooCommerce's settings.
 *   Mail is caught at pre_wp_mail and goes nowhere; the orders, the role and
 *   the account are made here and removed.
 * - Theme changelog (03-L2): a folder inside a theme with a style.css of its
 *   own was read as a theme. A throwaway theme folder is made and removed.
 * - Duplicate (02-L2): a copy took every meta key that was not protected,
 *   past the auth_callback a plugin registered for it.
 * - Languages (02-I3): a translation file whose real path was a sibling
 *   folder sharing the languages folder's name was treated as inside it. A
 *   made-up locale (zz_ZQ) is used, so no real translation is touched.
 *
 * Run: wp eval-file tests/security-v047-rc-core.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
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
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
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

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
$admin = get_current_user_id();
$tag   = (string) wp_rand( 1000, 9999 );
rest_get_server();

/*
 * 03-L1: order emails fit the order, and a switched-off one goes out only
 * for someone who could switch it back on.
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag ) {
	if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'WC' ) || ! get_role( 'shop_manager' ) ) {
		$skip( 'order emails: WooCommerce inactive' );
		return;
	}
	$role = 'minn_rc_orders_' . $tag;
	$caps = get_role( 'shop_manager' )->capabilities;
	unset( $caps['manage_woocommerce'] );
	add_role( $role, 'Minn RC orders', $caps );
	$clerk = wp_insert_user( array( 'user_login' => "minn-rc-clerk-{$tag}", 'user_email' => "minn-rc-clerk-{$tag}@example.com", 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	$mail  = array();
	$catch = function ( $pre, $atts ) use ( &$mail ) {
		$mail[] = $atts;
		return true; // Short-circuits wp_mail(): nothing leaves the site.
	};
	add_filter( 'pre_wp_mail', $catch, PHP_INT_MAX, 2 );
	$off    = '__return_false';
	$orders = array();
	$clean  = $once( function () use ( &$orders, $catch, $off, $clerk, $role, $admin ) {
		wp_set_current_user( $admin );
		remove_filter( 'pre_wp_mail', $catch, PHP_INT_MAX );
		remove_filter( 'woocommerce_email_enabled_customer_completed_order', $off, 10 );
		foreach ( $orders as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( is_int( $clerk ) && $clerk > 1 ) {
			wp_delete_user( $clerk );
		}
		remove_role( $role );
	} );
	try {
		if ( ! is_int( $clerk ) ) {
			$skip( 'order emails: could not make the clerk account' );
			return;
		}
		$o = wc_create_order();
		$o->set_billing_first_name( 'Minn' );
		$o->set_billing_email( "minn-rc-buyer-{$tag}@example.com" );
		$o->set_status( 'pending' );
		$o->save();
		$orders[] = (int) $o->get_id();
		$id       = (int) $o->get_id();
		$fresh    = function () {
			// Each send is a request of its own: WooCommerce's email objects start
			// it holding no order or recipient.
			foreach ( WC()->mailer()->get_emails() as $e ) {
				if ( is_object( $e ) ) {
					$e->object    = null;
					$e->recipient = null;
				}
			}
		};
		$send = function ( $who, $email ) use ( $call, $id, &$mail, $fresh ) {
			wp_set_current_user( $who );
			$mail = array();
			$fresh();
			return $call( 'POST', "/minn-admin/v1/orders/{$id}/emails", array( 'email_id' => $email ) );
		};
		$shop = (int) $clerk;

		list( $st, $d ) = $send( $shop, 'customer_completed_order' );
		$check( 'order emails: "Completed order" for a pending order is refused (400)', 400 === $st && 'minn_email_not_for_order' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'order emails: and nothing is mailed', ! $mail, count( $mail ) . ' mails' );
		list( $st, $d ) = $send( $shop, 'customer_refunded_order' );
		$check( 'order emails: "Refunded order" for an order with nothing refunded is refused', 400 === $st, (string) $st );
		wp_set_current_user( $shop );
		list( $st, $d ) = $call( 'GET', "/minn-admin/v1/orders/{$id}/emails" );
		$ids = array_column( (array) ( $d['emails'] ?? array() ), 'id' );
		$check( 'order emails: the list offers the invoice and leaves out what does not fit', 200 === $st && in_array( 'customer_invoice', $ids, true ) && ! in_array( 'customer_completed_order', $ids, true ), wp_json_encode( $ids ) );
		list( $st, $d ) = $send( $shop, 'customer_invoice' );
		$check( 'order emails: control: the invoice goes out for any order', 200 === $st && 1 === count( $mail ), $st . ' ' . wp_json_encode( $d ) );

		// Completed: the email fits now. Switched off, it goes out only for
		// someone who manages WooCommerce's settings.
		$o = wc_get_order( $id );
		$o->set_status( 'completed' );
		$o->save();
		add_filter( 'woocommerce_email_enabled_customer_completed_order', $off, 10 );
		list( $st, $d ) = $send( $shop, 'customer_completed_order' );
		$check( 'order emails: a switched-off email is not forced out for someone without manage_woocommerce', 200 !== $st && ! $mail, $st . ' mails=' . count( $mail ) );
		list( $st, $d ) = $send( $admin, 'customer_completed_order' );
		$check( 'order emails: control: an administrator still sends it', 200 === $st && 1 === count( $mail ), $st . ' ' . wp_json_encode( $d ) );
		remove_filter( 'woocommerce_email_enabled_customer_completed_order', $off, 10 );
		list( $st, $d ) = $send( $shop, 'customer_completed_order' );
		$check( 'order emails: control: switched on, the clerk sends the email that fits', 200 === $st && 1 === count( $mail ), $st . ' ' . wp_json_encode( $d ) );
	} finally {
		$clean();
	}
} )();

/*
 * 03-L2: the theme changelog reads installed themes only.
 */
( function () use ( $check, $call, $once, $admin, $tag ) {
	wp_set_current_user( $admin );
	$root  = get_theme_root();
	$slug  = "minn-rc-theme-{$tag}";
	$dir   = "{$root}/{$slug}";
	$head  = "/*\nTheme Name: Minn RC %s {$tag}\nVersion: 1.0\n*/\n";
	$clean = $once( function () use ( $dir ) {
		foreach ( array( "{$dir}/inner/style.css", "{$dir}/inner/index.php", "{$dir}/style.css", "{$dir}/index.php" ) as $f ) {
			if ( file_exists( $f ) ) {
				unlink( $f );
			}
		}
		foreach ( array( "{$dir}/inner", $dir ) as $d ) {
			if ( is_dir( $d ) ) {
				rmdir( $d );
			}
		}
		search_theme_directories( true );
	} );
	try {
		wp_mkdir_p( "{$dir}/inner" );
		file_put_contents( "{$dir}/style.css", sprintf( $head, 'Outer' ) );
		file_put_contents( "{$dir}/index.php", "<?php\n" );
		file_put_contents( "{$dir}/inner/style.css", sprintf( $head, 'Inner' ) );
		file_put_contents( "{$dir}/inner/index.php", "<?php\n" );
		search_theme_directories( true );
		list( $st ) = $call( 'GET', '/minn-admin/v1/theme-changelog', null, array( 'theme' => "{$slug}/inner" ) );
		$check( 'theme changelog: a folder inside a theme is not a theme (404)', 404 === $st, (string) $st );
		list( $st ) = $call( 'GET', '/minn-admin/v1/theme-changelog', null, array( 'theme' => $slug ) );
		$check( 'theme changelog: control: the installed theme answers', 200 === $st, (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * 02-L2: a duplicate copies a key only where the caller could add it.
 */
( function () use ( $check, $call, $once, $admin, $tag ) {
	wp_set_current_user( $admin );
	$guarded = "minn_rc_guarded_{$tag}";
	$plain   = "minn_rc_plain_{$tag}";
	register_post_meta( 'post', $guarded, array( 'single' => true, 'type' => 'string', 'auth_callback' => '__return_false' ) );
	$src   = wp_insert_post( array( 'post_title' => "Minn RC duplicate {$tag}", 'post_status' => 'draft', 'post_type' => 'post' ) );
	$made  = array( $src );
	$clean = $once( function () use ( &$made, $guarded ) {
		foreach ( $made as $p ) {
			if ( $p ) {
				wp_delete_post( $p, true );
			}
		}
		unregister_post_meta( 'post', $guarded );
	} );
	try {
		add_post_meta( $src, $guarded, 'secret-flag' );
		add_post_meta( $src, $plain, 'plain-value' );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/posts/{$src}/duplicate" );
		$copy = (int) ( $d['id'] ?? 0 );
		$made[] = $copy;
		$check( 'duplicate: the copy is made', in_array( $st, array( 200, 201 ), true ) && $copy > 0, $st . ' ' . wp_json_encode( $d ) );
		$check( 'duplicate: a key whose auth_callback refuses the caller is not copied', '' === get_post_meta( $copy, $guarded, true ), (string) get_post_meta( $copy, $guarded, true ) );
		$check( 'duplicate: control: an ordinary key is copied', 'plain-value' === get_post_meta( $copy, $plain, true ) );
	} finally {
		$clean();
	}
} )();

/*
 * 02-I3: removing a language never takes a file whose real path is a
 * sibling folder that only starts with the languages folder's name.
 */
( function () use ( $check, $skip, $once, $tag ) {
	if ( ! function_exists( 'symlink' ) || ! is_dir( WP_LANG_DIR ) || ! wp_is_writable( WP_LANG_DIR ) ) {
		$skip( 'languages: no writable languages folder' );
		return;
	}
	$sibling = untrailingslashit( WP_LANG_DIR ) . "-minnrc{$tag}";
	$target  = "{$sibling}/zz_ZQ.mo";
	$link    = trailingslashit( WP_LANG_DIR ) . 'zz_ZQ.mo';
	$clean   = $once( function () use ( $sibling, $target, $link ) {
		if ( is_link( $link ) || file_exists( $link ) ) {
			unlink( $link );
		}
		if ( file_exists( $target ) ) {
			unlink( $target );
		}
		if ( is_dir( $sibling ) ) {
			rmdir( $sibling );
		}
	} );
	try {
		if ( file_exists( $link ) || ! wp_mkdir_p( $sibling ) ) {
			$skip( 'languages: could not make the sibling folder' );
			return;
		}
		file_put_contents( $target, 'not a translation' );
		symlink( $target, $link );
		$m = new ReflectionMethod( 'Minn_Admin_REST', 'remove_language_files' );
		$m->setAccessible( true );
		$m->invoke( null, 'zz_ZQ' );
		$check( 'languages: a file that resolves to a sibling folder is left alone', is_link( $link ), 'the link was removed' );
	} finally {
		$clean();
	}
} )();

/*
 * 05-L1: an update is offered only from the release asset of the version
 * the manifest names, so a newer version number cannot carry an older
 * genuine release (and its real hash) back onto a site.
 */
( function () use ( $check ) {
	if ( ! class_exists( 'Minn_Admin_Updater' ) ) {
		return;
	}
	$base   = 'https://github.com/austinginder/minn-admin/releases/download/';
	$plugin = plugin_basename( MINN_ADMIN_DIR . 'minn-admin.php' );
	$offer  = function ( $manifest ) use ( $plugin ) {
		$u = new class() extends Minn_Admin_Updater {
			public $manifest;
			public function request( $remote_only = false ) {
				return $this->manifest;
			}
		};
		$u->manifest = $manifest;
		$t           = new stdClass();
		$t->response = array();
		$t->checked  = array( $plugin => MINN_ADMIN_VERSION ); // update() offers nothing for an unchecked transient
		$t           = $u->update( $t );
		return isset( $t->response[ $plugin ] ) ? (string) $t->response[ $plugin ]->package : '';
	};
	$old = (object) array( 'version' => '9.9.9', 'download_url' => $base . 'v0.45.0/minn-admin.zip', 'sha256' => str_repeat( 'a', 64 ), 'tested' => '', 'requires_php' => '' );
	$check( 'updater: a manifest naming 9.9.9 with an older release\'s zip offers nothing', '' === $offer( $old ) );
	$new = (object) array( 'version' => '9.9.9', 'download_url' => $base . 'v9.9.9/minn-admin.zip', 'sha256' => str_repeat( 'a', 64 ), 'tested' => '', 'requires_php' => '' );
	$check( 'updater: control: 9.9.9 from its own release is offered', $base . 'v9.9.9/minn-admin.zip' === $offer( $new ) );

	add_filter( 'minn_admin_translation_locales', $locales = function () {
		return array( 'de_DE' );
	} );
	$u = new Minn_Admin_Updater();
	$m = new ReflectionMethod( $u, 'offer_translations' );
	$m->setAccessible( true );
	$packs = function ( $url ) use ( $u, $m ) {
		$t               = new stdClass();
		$t->translations = array();
		$m->invoke( $u, $t, (object) array( 'translations' => array( (object) array( 'language' => 'de_DE', 'version' => '99.0.0', 'updated' => '2026-10-21 00:00:00', 'package' => $url, 'sha256' => 'bbbb' ) ) ) );
		return wp_list_pluck( $t->translations, 'language' );
	};
	$check( 'updater: a pack named 99.0.0 served from an older release is not offered', ! $packs( $base . 'v0.45.0/minn-admin-de_DE.zip' ) );
	$check( 'updater: control: the pack from its own release is offered', array( 'de_DE' ) === $packs( $base . 'v99.0.0/minn-admin-de_DE.zip' ) );
	remove_filter( 'minn_admin_translation_locales', $locales );
} )();

wp_set_current_user( $admin );
$failed = count( array_filter( $results, function ( $ok ) {
	return ! $ok;
} ) );
echo "\nsecurity-v047-rc-core: " . ( count( $results ) - $failed ) . '/' . count( $results ) . " passed\n";
if ( $failed ) {
	exit( 1 );
}
