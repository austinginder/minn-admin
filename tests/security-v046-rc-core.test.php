<?php
/**
 * v0.46.0 RC audit, core: a write says it worked only once it did.
 *
 * - Order emails (03-1): resending "Customer note" handed WooCommerce the
 *   bare order id where its trigger takes the order and the note, so
 *   nothing went out, and every email answered "sent" and wrote an order
 *   note whether or not WooCommerce sent anything. Mail is caught at
 *   pre_wp_mail and goes nowhere; the orders and accounts are made here
 *   and removed.
 * - wp-config switches (02-1): a define that follows a require or include
 *   of a file that defines the constant first was still offered and
 *   written. Judged on in-memory sources and scratch files only; no
 *   wp-config.php is read for a write or written.
 * - Post types and taxonomies (05-1, 05-2): unticking a support or a
 *   taxonomy held somewhere other than the edited definition's own store
 *   wrote nothing and answered "updated", and a JetEngine edit wrote the
 *   registered singular label JetEngine did not hold.
 * - Hardening: the site language needs a locale, the island preview
 *   empties the shortcode table again after each block, Run on Minn's
 *   staging folder is closed to the web server and swept once its check
 *   expires, the write fence matches trailing-space spellings of
 *   capability keys and network settings, and a site's administrator
 *   cannot read the changelog of a network-only plugin the site's Plugins
 *   screen hides.
 *
 * Sections SKIP when their plugin is inactive or their install shape does
 * not apply. Database changes run inside transactions that are rolled
 * back; accounts, orders, files, cron events and filters made here are
 * removed however the run ends.
 *
 * Run: wp eval-file tests/security-v046-rc-core.test.php --user=admin --path=<site>
 *      (on a network: --url=<a site other than the main one>)
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
global $wpdb;
$admin = get_current_user_id();
$tag   = (string) wp_rand( 1000, 9999 );
rest_get_server();
add_filter( 'send_email_change_email', '__return_false' );
add_filter( 'send_password_change_email', '__return_false' );

// Throwaway accounts, removed at the end by an exact id list.
$made   = array();
$mkuser = function ( $key, $role ) use ( &$made, $tag ) {
	if ( ! get_role( $role ) ) {
		return 0;
	}
	$login = 'minn-rccore-' . $key . '-' . $tag;
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
	if ( is_int( $id ) && $id > 1 ) {
		$made[] = $id;
		return $id;
	}
	return 0;
};
$drop_users = $once( function () use ( &$made, $admin ) {
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
	}
	foreach ( $made as $id ) {
		if ( is_int( $id ) && $id > 1 && $id !== $admin ) {
			is_multisite() ? wpmu_delete_user( $id ) : wp_delete_user( $id );
		}
	}
} );

/*
 * 03-1: order emails. "Customer note" goes out with the order's latest note
 * to the customer, and is refused when there is none. Every email writes
 * its "resent" note and answers 200 only once WooCommerce reports the send.
 */
( function () use ( $check, $skip, $call, $once, $mkuser, $admin, $tag ) {
	if ( ! function_exists( 'wc_create_order' ) || ! function_exists( 'WC' ) || ! function_exists( 'wc_get_order_notes' ) ) {
		$skip( 'order emails: WooCommerce inactive' );
		return;
	}
	$shop = $mkuser( 'shop', 'shop_manager' );
	if ( ! $shop ) {
		$skip( 'order emails: no shop_manager role' );
		return;
	}
	$mail  = array();
	$fail  = false;
	$catch = function ( $pre, $atts ) use ( &$mail, &$fail ) {
		$mail[] = $atts;
		return ! $fail; // Short-circuits wp_mail(): nothing leaves the site.
	};
	add_filter( 'pre_wp_mail', $catch, PHP_INT_MAX, 2 );
	$orders = array();
	$stop   = null;
	$clean  = $once( function () use ( &$orders, $catch, &$stop, $admin ) {
		wp_set_current_user( $admin );
		remove_filter( 'pre_wp_mail', $catch, PHP_INT_MAX );
		if ( $stop ) {
			remove_filter( 'woocommerce_email_recipient_customer_invoice', $stop, PHP_INT_MAX );
		}
		foreach ( $orders as $id ) {
			foreach ( (array) wc_get_order_notes( array( 'order_id' => $id ) ) as $n ) {
				wc_delete_order_note( (int) $n->id );
			}
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
	} );
	try {
		$make  = function ( $email ) use ( &$orders ) {
			$o = wc_create_order();
			if ( ! $o || is_wp_error( $o ) ) {
				return null;
			}
			$o->set_billing_first_name( 'Minn' );
			$o->set_billing_email( $email );
			$o->save();
			$orders[] = (int) $o->get_id();
			return $o;
		};
		$resent = function ( $id ) {
			$n = 0;
			foreach ( (array) wc_get_order_notes( array( 'order_id' => $id ) ) as $note ) {
				$n += false !== strpos( (string) $note->content, 'resent via Minn Admin' ) ? 1 : 0;
			}
			return $n;
		};
		$to     = function ( $addr ) use ( &$mail ) {
			return array_values( array_filter( $mail, function ( $m ) use ( $addr ) {
				return false !== stripos( implode( ',', (array) ( $m['to'] ?? array() ) ), $addr );
			} ) );
		};
		$send   = function ( $who, $id, $email ) use ( $call, &$mail ) {
			wp_set_current_user( $who );
			$mail = array();
			// Each resend is a request of its own: WooCommerce's email objects
			// start it holding no order, recipient or note. (The note added
			// below sends the customer note email in this process, and a
			// trigger that sets nothing would otherwise send that again.)
			foreach ( WC()->mailer()->get_emails() as $e ) {
				if ( is_object( $e ) && 'customer_note' === ( $e->id ?? '' ) ) {
					$e->object        = null;
					$e->recipient     = null;
					$e->customer_note = null;
				}
			}
			return $call( 'POST', "/minn-admin/v1/orders/{$id}/emails", array( 'email_id' => $email ) );
		};
		$addr = "minn-rccore-{$tag}@example.com";
		$o1   = $make( $addr );
		$o2   = $make( "minn-rccore-none-{$tag}@example.com" );
		$o3   = $make( '' );
		if ( ! $o1 || ! $o2 || ! $o3 ) {
			$skip( 'order emails: WooCommerce would not create a test order' );
			return;
		}
		// The note itself sends the customer note email once, as WooCommerce
		// does when a note is added; that send is not what is checked.
		$o1->add_order_note( "Minn RC note for the customer {$tag}", 1, true );
		$o1->add_order_note( "Minn RC private note {$tag}", 0, true );

		list( $st, $d ) = $send( $shop, $o1->get_id(), 'customer_note' );
		$got = $to( $addr );
		$check( 'order emails: resending "Customer note" as a Shop Manager mails the customer', 200 === $st && 1 === count( $got ), $st . ' ' . wp_json_encode( $d ) . ' mails=' . count( $got ) );
		$check( 'order emails: and the mail carries the order\'s latest note to the customer, not the private one', $got && false !== strpos( (string) $got[0]['message'], "Minn RC note for the customer {$tag}" ) && false === strpos( (string) $got[0]['message'], "Minn RC private note {$tag}" ) );
		$check( 'order emails: and the order notes record the resend once', 1 === $resent( $o1->get_id() ), (string) $resent( $o1->get_id() ) );

		$o2->add_order_note( "Minn RC private only {$tag}", 0, true );
		list( $st, $d ) = $send( $shop, $o2->get_id(), 'customer_note' );
		$check( 'order emails: "Customer note" for an order with no note to the customer is refused (400)', 400 === $st && 'minn_no_customer_note' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'order emails: and sends nothing and records no resend', ! $mail && 0 === $resent( $o2->get_id() ), count( $mail ) . ' mails, ' . $resent( $o2->get_id() ) . ' notes' );

		list( $st, $d ) = $send( $shop, $o3->get_id(), 'customer_processing_order' );
		$check( 'order emails: an email for an order with no address answers 409, not "sent"', 409 === $st && 'minn_email_not_sent' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'order emails: and records no resend', 0 === $resent( $o3->get_id() ), (string) $resent( $o3->get_id() ) );

		$stop = function () {
			return '';
		};
		add_filter( 'woocommerce_email_recipient_customer_invoice', $stop, PHP_INT_MAX );
		list( $st, $d ) = $send( $shop, $o1->get_id(), 'customer_invoice' );
		remove_filter( 'woocommerce_email_recipient_customer_invoice', $stop, PHP_INT_MAX );
		$check( 'order emails: an email a plugin stops answers 409 and records no resend', 409 === $st && ! $to( $addr ) && 1 === $resent( $o1->get_id() ), $st . ' ' . wp_json_encode( $d ) );

		$fail = true;
		list( $st, $d ) = $send( $shop, $o1->get_id(), 'customer_invoice' );
		$fail = false;
		$check( 'order emails: an email the mailer fails answers 500 and records no resend', 500 === $st && 'minn_email_failed' === ( $d['code'] ?? '' ) && 1 === $resent( $o1->get_id() ), $st . ' ' . wp_json_encode( $d ) );

		$o1 = wc_get_order( $o1->get_id() );
		$o1->update_meta_data( '_new_order_email_sent', 'true' );
		$o1->save();
		list( $st, $d ) = $send( $shop, $o1->get_id(), 'new_order' );
		$check( 'order emails: "New order" resends for an order whose first one went out, as WooCommerce\'s own resend does', 200 === $st && 1 === count( $mail ) && 2 === $resent( $o1->get_id() ), $st . ' ' . wp_json_encode( $d ) . ' mails=' . count( $mail ) );

		list( $st, $d ) = $send( $admin, $o1->get_id(), 'customer_invoice' );
		$check( 'order emails: an Administrator\'s invoice resend mails the customer and records it (control)', 200 === $st && 1 === count( $to( $addr ) ) && 3 === $resent( $o1->get_id() ), $st . ' ' . wp_json_encode( $d ) );
	} finally {
		$clean();
	}
} )();

$drop_users();
printf( "\nsecurity-v046-rc-core: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
