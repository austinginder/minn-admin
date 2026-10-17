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

/*
 * 02-1: a define after a require or include. PHP keeps the first define, so
 * a line after a file that defined the constant first is dead even when the
 * values match. Judged on sources in memory and scratch "loaded" files.
 */
( function () use ( $check, $skip, $once ) {
	if ( ! method_exists( 'Minn_Admin_REST', 'config_switch' ) ) {
		$skip( 'wp-config: no config_switch()' );
		return;
	}
	$scan = new ReflectionMethod( 'Minn_Admin_REST', 'config_define_scan' );
	$scan->setAccessible( true );
	$live = new ReflectionMethod( 'Minn_Admin_REST', 'config_define_live' );
	$live->setAccessible( true );
	$name = 'MINN_RCCORE_CFG_PROBE';
	if ( ! defined( $name ) ) {
		define( $name, false );
	}
	$dir = trailingslashit( get_temp_dir() ) . 'minn-rccore-cfg-' . wp_generate_password( 8, false );
	wp_mkdir_p( $dir );
	$clean = $once( function () use ( $dir ) {
		foreach ( (array) glob( $dir . '/*.php' ) as $f ) {
			unlink( $f );
		}
		if ( is_dir( $dir ) ) {
			rmdir( $dir );
		}
	} );
	try {
		$put     = function ( $file, $code ) use ( $dir ) {
			file_put_contents( "{$dir}/{$file}", $code );
			return "{$dir}/{$file}";
		};
		$defines = $put( 'defines.php', "<?php\ndefine( '{$name}', false );\n" );
		$reads   = $put( 'reads.php', "<?php\ndefine( 'MINN_RCCORE_OTHER', 'x' );\nif ( {$name} ) {\n\terror_reporting( 0 );\n}\n" );
		$looped  = $put( 'looped.php', "<?php\nforeach ( array( 'MINN_RCCORE_A' => 1 ) as \$k => \$v ) {\n\tdefine( \$k, \$v );\n}\n" );
		$consts  = $put( 'consts.php', "<?php\nconst {$name} = false;\n" );
		$is_live = function ( $src, $loaded ) use ( $scan, $live, $name ) {
			$s = $scan->invoke( null, $name, "<?php\n" . $src );
			return 'bool' === $s['kind'] && $live->invoke( null, $name, $s, $loaded );
		};
		$plain    = "define( '{$name}', false );\n";
		$fallback = "if ( ! defined( '{$name}' ) ) {\n\tdefine( '{$name}', false );\n}\n";
		$settings = "require_once ABSPATH . 'wp-settings.php';\n";
		$cases    = array(
			// label => [ source, loaded files, live? ]
			'a plain define with nothing loaded ahead is a switch (control)' => array( $plain . $settings, array(), true ),
			'a plain define after a require of a file that defines it is locked' => array( "require_once __DIR__ . '/wp-config-local.php';\n" . $plain . $settings, array( $defines ), false ),
			'a top-of-file fallback after such a require is locked' => array( "require_once __DIR__ . '/wp-config-local.php';\n" . $fallback . $settings, array( $defines ), false ),
			'a define after WP Freighter\'s branch include of a file that does not define it stays a switch (control)' => array( "if ( file_exists( dirname( __FILE__ ) . '/wp-content/freighter.php' ) ) { require_once( dirname( __FILE__ ) . '/wp-content/freighter.php' ); }\n" . $plain . $settings, array( $reads ), true ),
			'the same branch include of a file that defines it is locked' => array( "if ( file_exists( dirname( __FILE__ ) . '/local.php' ) ) { require_once( dirname( __FILE__ ) . '/local.php' ); }\n" . $plain . $settings, array( $defines ), false ),
			'an if: … endif; include of a file that defines it is locked' => array( "if ( file_exists( __DIR__ . '/local.php' ) ) :\n\tinclude __DIR__ . '/local.php';\nendif;\n" . $plain . $settings, array( $defines ), false ),
			'an include whose path holds no string at all is locked' => array( "require \$minn_local_config;\n" . $plain . $settings, array(), false ),
			'wp-settings.php loaded through a variable, then the define, is locked' => array( "\$s = 'wp-settings.php';\nrequire_once ABSPATH . \$s;\n" . $plain, array(), false ),
			'a loaded file that defines constants in a loop locks the define' => array( "include __DIR__ . '/settings.php';\n" . $plain . $settings, array( $looped ), false ),
			'a loaded file that declares it as a const locks the define' => array( "include __DIR__ . '/settings.php';\n" . $plain . $settings, array( $consts ), false ),
			'a loaded file that cannot be read locks the define' => array( "include __DIR__ . '/settings.php';\n" . $plain . $settings, array( $dir . '/missing.php' ), false ),
			'an include after the define does not count (control)' => array( $plain . "include __DIR__ . '/after.php';\n" . $settings, array( $defines ), true ),
			'a define whose literal differs from the running value stays locked (control)' => array( "define( '{$name}', true );\n" . $settings, array(), false ),
		);
		foreach ( $cases as $label => $c ) {
			$got = $is_live( $c[0], $c[1] );
			$check( 'wp-config: ' . $label, $c[2] === $got, $got ? 'offered as a switch' : 'locked' );
		}
		// What the System page and the save see for the same source.
		$sw = Minn_Admin_REST::config_switch( $name, "<?php\n" . $plain . $settings );
		$check( 'wp-config: config_switch() still offers the plain define (control)', false === $sw['locked'], wp_json_encode( $sw ) );
		$sw = Minn_Admin_REST::config_switch( $name, "<?php\nrequire \$minn_local_config;\n" . $plain . $settings );
		$check( 'wp-config: config_switch() shows a define after an unnamed include as defined elsewhere', true === $sw['locked'] && 'elsewhere' === $sw['lock'], wp_json_encode( $sw ) );

		// The shapes the earlier reviews ran keep the kinds they had.
		$shapes = array(
			'plain'                         => array( "<?php\ndefine( 'WP_DEBUG', false );\nrequire_once ABSPATH . 'wp-settings.php';\n", 'bool' ),
			'late'                          => array( "<?php\nrequire_once ABSPATH . 'wp-settings.php';\ndefine( 'WP_DEBUG', false );\n", 'late' ),
			'include_first_fallback'        => array( "<?php\nrequire_once __DIR__ . '/wp-config-local.php';\nif ( ! defined( 'WP_DEBUG' ) ) { define( 'WP_DEBUG', false ); }\nrequire_once ABSPATH . 'wp-settings.php';\n", 'bool' ),
			'include_first_plain'           => array( "<?php\ninclude 'local.php';\ndefine( 'WP_DEBUG', false );\nrequire_once ABSPATH . 'wp-settings.php';\n", 'bool' ),
			'alt_then_plain'                => array( "<?php\nif ( file_exists( 'x' ) ) : include 'x'; endif;\ndefine( 'WP_DEBUG', false );\nrequire_once ABSPATH . 'wp-settings.php';\n", 'bool' ),
			'alt_inside'                    => array( "<?php\nif ( 1 ) : define( 'WP_DEBUG', false ); endif;\n", 'branch' ),
			'alt_else'                      => array( "<?php\nif ( 1 ) : \$a=1; else : define( 'WP_DEBUG', false ); endif;\n", 'branch' ),
			'alt_elseif'                    => array( "<?php\nif ( 1 ) : \$a=1; elseif ( 2 ) : define( 'WP_DEBUG', false ); endif;\n", 'branch' ),
			'while_alt'                     => array( "<?php\nwhile ( 0 ) : define( 'WP_DEBUG', false ); endwhile;\n", 'branch' ),
			'nested_fallback'               => array( "<?php\nif ( getenv('X') ) { if ( ! defined( 'WP_DEBUG' ) ) { define( 'WP_DEBUG', false ); } }\n", 'branch' ),
			'top_fallback'                  => array( "<?php\nif ( ! defined( 'WP_DEBUG' ) ) { define( 'WP_DEBUG', false ); }\n", 'bool' ),
			'or_fallback'                   => array( "<?php\ndefined( 'WP_DEBUG' ) || define( 'WP_DEBUG', false );\n", 'bool' ),
			'or_fallback_in_if'             => array( "<?php\nif ( 1 ) { defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', false ); }\n", 'branch' ),
			'or_fallback_after_assign'      => array( "<?php\n\$x = defined( 'WP_DEBUG' ) || define( 'WP_DEBUG', false );\n", 'branch' ),
			'elseif_fallback'               => array( "<?php\nif ( 0 ) { } elseif ( ! defined( 'WP_DEBUG' ) ) { define( 'WP_DEBUG', false ); }\n", 'branch' ),
			'ternary_in_if_header'          => array( "<?php\nif ( \$a ? 1 : 0 ) define( 'WP_DEBUG', false );\n", 'branch' ),
			'case_label'                    => array( "<?php\nswitch ( 1 ) : case 1 : define( 'WP_DEBUG', false ); endswitch;\n", 'branch' ),
			'short_tag_close'               => array( "<?php\n\$a = 1 ?>\n<?php define( 'WP_DEBUG', false );\n", 'bool' ),
			'after_close_tag_no_semicolon'  => array( "<?php if (1) ?><?php define( 'WP_DEBUG', false );\n", 'bool' ),
			'goto_label'                    => array( "<?php\nlbl: define( 'WP_DEBUG', false );\n", 'branch' ),
			'return_before'                 => array( "<?php\nreturn; define( 'WP_DEBUG', false );\n", 'bool' ),
			'exit_before'                   => array( "<?php\nif ( PHP_SAPI === 'cli' ) exit;\ndefine( 'WP_DEBUG', false );\n", 'bool' ),
			'settings_in_func'              => array( "<?php\nfunction boot() { require_once ABSPATH . 'wp-settings.php'; }\ndefine( 'WP_DEBUG', false );\nboot();\n", 'late' ),
			'settings_double_quote'         => array( "<?php\nrequire_once( dirname( __FILE__ ) . \"/wp-settings.php\" );\ndefine( 'WP_DEBUG', false );\n", 'late' ),
			'settings_concat_var'           => array( "<?php\n\$s = 'wp-settings.php';\nrequire_once ABSPATH . \$s;\ndefine( 'WP_DEBUG', false );\n", 'bool' ),
			'heredoc'                       => array( "<?php\n\$x = <<<EOT\ndefine( 'WP_DEBUG', true );\nEOT;\ndefine( 'WP_DEBUG', false );\n", 'custom' ),
			'at_sign'                       => array( "<?php\n@define( 'WP_DEBUG', false );\n", 'bool' ),
			'declare_alt'                   => array( "<?php\ndeclare(ticks=1) : define( 'WP_DEBUG', false ); enddeclare;\n", 'branch' ),
			'brace_if_no_paren_colon'       => array( "<?php\nif ( 1 ) { } define( 'WP_DEBUG', false );\n", 'bool' ),
			'semicolon_inside_if_braceless' => array( "<?php\nif ( \$x ) \$y = 1; define( 'WP_DEBUG', false );\n", 'bool' ),
			'else_braceless'                => array( "<?php\nif ( \$x ) \$y = 1; else define( 'WP_DEBUG', false );\n", 'branch' ),
			'for_braceless_body'            => array( "<?php\nfor ( ;0; ) define( 'WP_DEBUG', false );\n", 'branch' ),
			'and_chain'                     => array( "<?php\ngetenv('X') and define( 'WP_DEBUG', false );\n", 'branch' ),
			'alt_for'                       => array( "<?php\nfor ( \$i=0;\$i<0;\$i++ ) : define( 'WP_DEBUG', false ); endfor;\n", 'branch' ),
		);
		$moved = array();
		foreach ( $shapes as $k => $c ) {
			$kind = Minn_Admin_REST::config_define_kind( 'WP_DEBUG', $c[0] );
			if ( $kind !== $c[1] ) {
				$moved[] = "{$k}: {$c[1]} → {$kind}";
			}
		}
		$check( 'wp-config: the 34 shapes the earlier reviews ran keep their kinds', ! $moved, implode( '; ', $moved ) );
		$new = Minn_Admin_REST::config_rewrite( 'WP_DEBUG', true, $shapes['plain'][0] );
		$check( 'wp-config: the rewrite of a plain define is unchanged (control)', is_string( $new ) && false !== strpos( $new, "define( 'WP_DEBUG', true );" ), is_wp_error( $new ) ? $new->get_error_code() : '' );
	} finally {
		$clean();
	}
} )();

/*
 * 05-1 and 05-2: post type and taxonomy edits. The modal ticks the
 * REGISTERED lists; a box moved there is applied to every definition that
 * holds it, or refused naming where it comes from, never answered
 * "updated" with nothing written. JetEngine keeps only the singular label
 * someone typed.
 */
( function () use ( $check, $skip, $call, $wpdb, $tag ) {
	if ( is_multisite() ) {
		$skip( 'post types: single site only' );
		return;
	}
	if ( ! defined( 'CPTUI_VERSION' ) || ! function_exists( 'cptui_register_single_post_type' ) || ! function_exists( 'cptui_register_single_taxonomy' ) ) {
		$skip( 'post types: CPT UI inactive' );
		return;
	}
	$row = function ( $kind, $slug ) use ( $call ) {
		list( , $d ) = $call( 'GET', '/minn-admin/v1/' . ( 'type' === $kind ? 'post-types' : 'taxonomies' ) );
		foreach ( (array) ( $d[ 'type' === $kind ? 'types' : 'taxonomies' ] ?? array() ) as $r ) {
			if ( $slug === $r['slug'] ) {
				return $r;
			}
		}
		return null;
	};
	$opt = function ( $option, $slug ) {
		return (array) ( ( (array) get_option( $option, array() ) )[ $slug ] ?? array() );
	};
	$snap = function () {
		return wp_json_encode( array( get_option( 'cptui_post_types' ), get_option( 'cptui_taxonomies' ), get_option( Minn_Admin_CPT::OPTION ), get_option( Minn_Admin_CPT::OPTION_TAX ) ) );
	};
	$wpdb->query( 'START TRANSACTION' );
	$locked = null;
	try {
		$cpt   = 'mrcb' . $tag;
		$own   = 'mrco' . $tag;
		$named = 'mrcn' . $tag;
		$code  = 'mrcc' . $tag;
		list( $s1 ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $cpt, 'singular' => 'Book', 'plural' => 'Books', 'description' => '', 'public' => 1, 'supports' => array( 'title', 'editor', 'author' ), 'backend' => 'cptui' ) );
		$types = (array) get_option( 'cptui_post_types', array() );
		if ( isset( $types[ $cpt ] ) && ! post_type_exists( $cpt ) ) {
			cptui_register_single_post_type( $types[ $cpt ] );
		}
		list( $s2 ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $own, 'singular' => 'Genre', 'plural' => 'Genres', 'object_types' => array( $cpt ), 'backend' => 'cptui' ) );
		list( $s3 ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $named, 'singular' => 'Shelf', 'plural' => 'Shelves', 'object_types' => array(), 'backend' => 'cptui' ) );
		if ( 200 !== $s1 || 200 !== $s2 || 200 !== $s3 ) {
			$skip( "post types: CPT UI would not take the fixtures ({$s1}/{$s2}/{$s3})" );
			return;
		}
		// The type names $named in its own definition; $own attaches itself.
		$types                         = (array) get_option( 'cptui_post_types', array() );
		$types[ $cpt ]['taxonomies']   = array( $named );
		update_option( 'cptui_post_types', $types );
		$reload = function ( $runtime = true ) use ( $cpt, $own, $named, $code ) {
			foreach ( array( $own, $named, $code ) as $t ) {
				if ( taxonomy_exists( $t ) ) {
					unregister_taxonomy( $t );
				}
			}
			if ( post_type_exists( $cpt ) ) {
				unregister_post_type( $cpt );
			}
			$taxes = (array) get_option( 'cptui_taxonomies', array() );
			cptui_register_single_taxonomy( $taxes[ $own ] );
			cptui_register_single_taxonomy( $taxes[ $named ] );
			cptui_register_single_post_type( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] );
			if ( $runtime ) {
				// What a theme or plugin does in code.
				register_taxonomy( $code, $cpt, array( 'public' => true, 'show_ui' => true, 'label' => 'Code tags' ) );
				add_post_type_support( $cpt, 'excerpt' );
				remove_post_type_support( $cpt, 'author' );
			}
		};
		$save   = function ( $kind, $slug, $body ) use ( $call, $row ) {
			$shown = $row( $kind, $slug );
			return $call( 'POST', '/minn-admin/v1/' . ( 'type' === $kind ? 'post-types/' : 'taxonomies/' ) . $slug, array_merge( $body( $shown ), array( 'base' => $shown['stamp'] ?? '' ) ) );
		};
		$without = function ( $list, $v ) {
			return array_values( array_diff( (array) $list, array( $v ) ) );
		};

		$reload();
		$shown = $row( 'type', $cpt );
		$check( 'post types: the fixture shows a taxonomy attached from its own side, one the type names, one from code, a runtime support and not a support removed at runtime', in_array( $own, (array) $shown['taxonomies'], true ) && in_array( $named, (array) $shown['taxonomies'], true ) && in_array( $code, (array) $shown['taxonomies'], true ) && in_array( 'excerpt', (array) $shown['supports'], true ) && ! in_array( 'author', (array) $shown['supports'], true ), wp_json_encode( $shown ) );

		list( $st, $d ) = $save( 'type', $cpt, function ( $s ) use ( $without, $own ) {
			return array( 'taxonomies' => $without( $s['taxonomies'], $own ) );
		} );
		$reload();
		$check( 'post types: unticking a taxonomy that attaches itself (CPT UI "Attach to post type") detaches it in the taxonomy', 200 === $st && ! in_array( $cpt, (array) ( $opt( 'cptui_taxonomies', $own )['object_types'] ?? array() ), true ), $st . ' ' . wp_json_encode( array( $d, $opt( 'cptui_taxonomies', $own )['object_types'] ?? null ) ) );
		$check( 'post types: and the type no longer shows it', ! in_array( $own, (array) $row( 'type', $cpt )['taxonomies'], true ) );

		$before = $snap();
		list( $st, $d ) = $save( 'type', $cpt, function ( $s ) use ( $without, $code ) {
			return array( 'taxonomies' => $without( $s['taxonomies'], $code ) );
		} );
		$check( 'post types: unticking a taxonomy code attaches answers 409, naming where it comes from', 409 === $st && 'minn_attached_elsewhere' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'post types: and writes nothing', $before === $snap() );

		list( $st, $d ) = $save( 'type', $cpt, function ( $s ) use ( $without ) {
			return array( 'supports' => $without( $s['supports'], 'excerpt' ) );
		} );
		$check( 'post types: unticking a support the theme adds at runtime answers 409', 409 === $st && 'minn_support_elsewhere' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
		list( $st, $d ) = $save( 'type', $cpt, function ( $s ) {
			return array( 'supports' => array_merge( (array) $s['supports'], array( 'author' ) ) );
		} );
		$check( 'post types: ticking a stored support the theme takes off at runtime answers 409', 409 === $st && 'minn_support_elsewhere' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'post types: and neither wrote anything', $before === $snap() );

		list( $st ) = $save( 'type', $cpt, function ( $s ) use ( $without, $named ) {
			return array( 'taxonomies' => $without( $s['taxonomies'], $named ) );
		} );
		$reload();
		$check( 'post types: unticking a taxonomy the type names is written in the type (control)', 200 === $st && ! in_array( $named, (array) ( $opt( 'cptui_post_types', $cpt )['taxonomies'] ?? array() ), true ) && ! in_array( $named, (array) $row( 'type', $cpt )['taxonomies'], true ), (string) $st );
		list( $st ) = $save( 'type', $cpt, function ( $s ) {
			return array( 'supports' => array_merge( (array) $s['supports'], array( 'thumbnail' ) ) );
		} );
		$sup = (array) ( $opt( 'cptui_post_types', $cpt )['supports'] ?? array() );
		$check( 'post types: ticking a support is written, and the runtime one stays out (control)', 200 === $st && in_array( 'thumbnail', $sup, true ) && ! in_array( 'excerpt', $sup, true ), $st . ' ' . wp_json_encode( $sup ) );

		// The taxonomy side: a type that names the taxonomy holds it there.
		$types                       = (array) get_option( 'cptui_post_types', array() );
		$types[ $cpt ]['taxonomies'] = array( $named );
		update_option( 'cptui_post_types', $types );
		$reload();
		$check( 'taxonomies: the fixture shows the type that names the taxonomy', in_array( $cpt, (array) $row( 'tax', $named )['object_types'], true ) );
		list( $st, $d ) = $save( 'tax', $named, function ( $s ) use ( $without, $cpt ) {
			return array( 'object_types' => $without( $s['object_types'], $cpt ) );
		} );
		$reload();
		$check( 'taxonomies: unticking a post type that names the taxonomy detaches it in the post type', 200 === $st && ! in_array( $named, (array) ( $opt( 'cptui_post_types', $cpt )['taxonomies'] ?? array() ), true ), $st . ' ' . wp_json_encode( array( $d, $opt( 'cptui_post_types', $cpt )['taxonomies'] ?? null ) ) );
		$check( 'taxonomies: and the taxonomy no longer shows it', ! in_array( $cpt, (array) $row( 'tax', $named )['object_types'], true ) );
		$taxes                           = (array) get_option( 'cptui_taxonomies', array() );
		$taxes[ $own ]['object_types']   = array( $cpt );
		update_option( 'cptui_taxonomies', $taxes );
		$reload();
		list( $st ) = $save( 'tax', $own, function ( $s ) use ( $without, $cpt ) {
			return array( 'object_types' => $without( $s['object_types'], $cpt ) );
		} );
		$check( 'taxonomies: unticking a post type the taxonomy holds is written there (control)', 200 === $st && ! in_array( $cpt, (array) ( $opt( 'cptui_taxonomies', $own )['object_types'] ?? array() ), true ), (string) $st );
		$reload();
		list( $st, $d ) = $save( 'tax', $code, function ( $s ) use ( $without, $cpt ) {
			return array( 'object_types' => $without( $s['object_types'], $cpt ) );
		} );
		$check( 'taxonomies: a taxonomy registered in code still cannot be edited (control)', 400 === $st, $st . ' ' . wp_json_encode( $d ) );

		// Every box unticked: an empty list would register title and editor again.
		$reload( false );
		list( $st ) = $save( 'type', $cpt, function () {
			return array( 'supports' => array() );
		} );
		$reload( false );
		$check( 'post types: unticking every support keeps them off (CPT UI\'s "None")', 200 === $st && array() === array_values( (array) $row( 'type', $cpt )['supports'] ), $st . ' ' . wp_json_encode( array( $opt( 'cptui_post_types', $cpt )['supports'] ?? null, $row( 'type', $cpt )['supports'] ) ) );
		list( $st ) = $save( 'type', $cpt, function () {
			return array( 'supports' => array( 'title' ) );
		} );
		$reload( false );
		$check( 'post types: and ticking one again takes effect (control)', 200 === $st && array( 'title' ) === array_values( (array) $row( 'type', $cpt )['supports'] ), $st . ' ' . wp_json_encode( $row( 'type', $cpt )['supports'] ) );

		// A holder the caller may not edit: CPT UI kept to another capability.
		$minn = 'mrcm' . $tag;
		$own2 = 'mrcp' . $tag;
		list( $s1 ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $minn, 'singular' => 'Dish', 'plural' => 'Dishes', 'description' => '', 'public' => 1, 'supports' => array( 'title' ), 'taxonomies' => array(), 'backend' => 'minn' ) );
		list( $s2 ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $own2, 'singular' => 'Pot', 'plural' => 'Pots', 'object_types' => array( $minn ), 'backend' => 'cptui' ) );
		if ( 200 !== $s1 || 200 !== $s2 ) {
			$skip( "post types: the locked-holder fixture did not take ({$s1}/{$s2})" );
		} else {
			if ( taxonomy_exists( $own2 ) ) {
				unregister_taxonomy( $own2 );
			}
			cptui_register_single_taxonomy( ( (array) get_option( 'cptui_taxonomies', array() ) )[ $own2 ] );
			$locked = function () {
				return 'minn_rccore_nobody';
			};
			add_filter( 'cptui_required_capabilities', $locked );
			$before = $snap();
			list( $st, $d ) = $save( 'type', $minn, function ( $s ) use ( $without, $own2 ) {
				return array( 'taxonomies' => $without( $s['taxonomies'], $own2 ) );
			} );
			remove_filter( 'cptui_required_capabilities', $locked );
			$check( 'post types: unticking a taxonomy held in CPT UI that this site keeps from the caller answers 409', 409 === $st && 'minn_attached_elsewhere' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
			$check( 'post types: and writes nothing', $before === $snap() );
		}

		// Minn's own store: its types register before every taxonomy but
		// core's, so a taxonomy ticked on a Minn type has to attach later.
		$mt = 'mrcq' . $tag;
		$mx = 'mrcr' . $tag;
		list( $s1 ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $mt, 'singular' => 'Jar', 'plural' => 'Jars', 'description' => '', 'public' => 1, 'supports' => array( 'title' ), 'taxonomies' => array(), 'backend' => 'minn' ) );
		list( $s2 ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $mx, 'singular' => 'Lid', 'plural' => 'Lids', 'object_types' => array( 'post' ), 'backend' => 'minn' ) );
		if ( 200 !== $s1 || 200 !== $s2 ) {
			$skip( "post types: Minn's store would not take the fixtures ({$s1}/{$s2})" );
		} else {
			// What the next page load does, in init's order.
			$minn_reload = function () use ( $mt, $mx ) {
				if ( taxonomy_exists( $mx ) ) {
					unregister_taxonomy( $mx );
				}
				if ( post_type_exists( $mt ) ) {
					unregister_post_type( $mt );
				}
				Minn_Admin_CPT::register_native_types();
				Minn_Admin_CPT::register_native_taxonomies();
				if ( method_exists( 'Minn_Admin_CPT', 'attach_native_taxonomies' ) ) {
					Minn_Admin_CPT::attach_native_taxonomies();
				}
			};
			$minn_reload();
			list( $st ) = $save( 'type', $mt, function ( $s ) use ( $mx ) {
				return array( 'taxonomies' => array_merge( (array) $s['taxonomies'], array( $mx ) ) );
			} );
			$minn_reload();
			$check( 'post types: a Minn taxonomy ticked on a Minn type is still attached on the next load', 200 === $st && in_array( $mx, (array) $row( 'type', $mt )['taxonomies'], true ), $st . ' ' . wp_json_encode( $row( 'type', $mt )['taxonomies'] ?? null ) );
			list( $st ) = $save( 'type', $mt, function ( $s ) use ( $without, $mx ) {
				return array( 'taxonomies' => $without( $s['taxonomies'], $mx ) );
			} );
			$minn_reload();
			$check( 'post types: and unticking it detaches it (control)', 200 === $st && ! in_array( $mx, (array) $row( 'type', $mt )['taxonomies'], true ), $st . ' ' . wp_json_encode( $row( 'type', $mt )['taxonomies'] ?? null ) );
		}

		// ACF registers an empty supports list as none at all.
		if ( function_exists( 'acf_get_setting' ) && acf_get_setting( 'enable_post_types' ) && function_exists( 'acf_get_internal_post_type_posts' ) ) {
			$acf  = 'mrca' . $tag;
			$find = function () use ( $acf ) {
				foreach ( (array) acf_get_internal_post_type_posts( 'acf-post-type' ) as $settings ) {
					if ( ( $settings['post_type'] ?? '' ) === $acf ) {
						return $settings;
					}
				}
				return null;
			};
			list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $acf, 'singular' => 'Car', 'plural' => 'Cars', 'description' => '', 'public' => 1, 'supports' => array(), 'backend' => 'acf' ) );
			if ( 200 !== $st || ! $find() ) {
				$skip( "post types: ACF would not take a test post type ({$st})" );
			} else {
				if ( post_type_exists( $acf ) ) {
					unregister_post_type( $acf );
				}
				register_post_type( $acf, array( 'labels' => array( 'name' => 'Cars', 'singular_name' => 'Car' ), 'public' => true, 'show_ui' => true, 'supports' => false ) );
				list( $st ) = $save( 'type', $acf, function () {
					return array( 'supports' => array( 'title' ) );
				} );
				$acf_sup = (array) ( $find()['supports'] ?? array() );
				$check( 'post types: ticking a support on an ACF type with none is written', 200 === $st && array( 'title' ) === array_values( $acf_sup ), $st . ' ' . wp_json_encode( $acf_sup ) );
			}
		} else {
			$skip( 'post types: ACF post types off' );
		}

		// JetEngine keeps a type's taxonomies on the taxonomy, and a singular
		// label only when one was typed.
		if ( function_exists( 'jet_engine' ) && is_object( jet_engine()->cpt ) && is_object( jet_engine()->cpt->data ) && is_object( jet_engine()->taxonomies ) && is_object( jet_engine()->taxonomies->data ) ) {
			$jtype = 'mrcj' . $tag;
			$jtax  = 'mrck' . $tag;
			$ttab  = $wpdb->prefix . 'jet_post_types';
			$xtab  = $wpdb->prefix . 'jet_taxonomies';
			$forget = function () {
				if ( is_object( jet_engine()->db ) && property_exists( jet_engine()->db, 'query_cache' ) ) {
					jet_engine()->db->query_cache = array();
				}
				jet_engine()->cpt->data->reset_raw_cache();
				jet_engine()->taxonomies->data->reset_raw_cache();
			};
			$jrow   = function ( $table, $slug ) use ( $wpdb, $forget ) {
				$forget();
				$r = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB
				if ( ! $r ) {
					return null;
				}
				foreach ( array( 'labels', 'args', 'object_type' ) as $col ) {
					if ( isset( $r[ $col ] ) ) {
						$r[ $col ] = Minn_Admin::decode_serialized( (string) $r[ $col ], array() );
					}
				}
				return $r;
			};
			list( $s1 ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $jtype, 'singular' => 'Fish', 'plural' => 'Fishes', 'description' => '', 'public' => 1, 'supports' => array( 'title' ), 'backend' => 'jet' ) );
			list( $s2 ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $jtax, 'singular' => 'Reef', 'plural' => 'Reefs', 'object_types' => array( 'post' ), 'backend' => 'jet' ) );
			$tr = $jrow( $ttab, $jtype );
			$xr = $jrow( $xtab, $jtax );
			if ( 200 !== $s1 || 200 !== $s2 || ! $tr || ! $xr ) {
				$skip( "post types: JetEngine would not take the fixtures ({$s1}/{$s2})" );
			} else {
				$jreg = function ( $types, $singular_type = 'Fish', $singular_tax = 'Reef' ) use ( $jtype, $jtax ) {
					if ( taxonomy_exists( $jtax ) ) {
						unregister_taxonomy( $jtax );
					}
					if ( post_type_exists( $jtype ) ) {
						unregister_post_type( $jtype );
					}
					register_post_type( $jtype, array( 'labels' => array( 'name' => 'Fishes', 'singular_name' => $singular_type ), 'public' => true, 'show_ui' => true, 'supports' => array( 'title' ) ) );
					register_taxonomy( $jtax, $types, array( 'labels' => array( 'name' => 'Reefs', 'singular_name' => $singular_tax ), 'public' => true, 'show_ui' => true ) );
				};
				$jreg( array( 'post' ) );
				list( $st, $d ) = $save( 'type', $jtype, function ( $s ) use ( $jtax ) {
					return array( 'taxonomies' => array_merge( (array) $s['taxonomies'], array( $jtax ) ) );
				} );
				$xr = $jrow( $xtab, $jtax );
				$check( 'post types: ticking a taxonomy on a JetEngine type attaches it in the taxonomy, where JetEngine keeps it', 200 === $st && in_array( $jtype, (array) $xr['object_type'], true ) && in_array( 'post', (array) $xr['object_type'], true ), $st . ' ' . wp_json_encode( array( $d, $xr['object_type'] ?? null ) ) );
				$jreg( array( 'post', $jtype ) );
				list( $st ) = $save( 'type', $jtype, function ( $s ) use ( $without, $jtax ) {
					return array( 'taxonomies' => $without( $s['taxonomies'], $jtax ) );
				} );
				$xr = $jrow( $xtab, $jtax );
				$check( 'post types: and unticking it detaches it there, keeping the rest', 200 === $st && ! in_array( $jtype, (array) $xr['object_type'], true ) && in_array( 'post', (array) $xr['object_type'], true ), $st . ' ' . wp_json_encode( $xr['object_type'] ?? null ) );

				// 05-2: no singular label stored; the registered one is a translation.
				$labels = (array) $tr['labels'];
				unset( $labels['singular_name'] );
				$wpdb->update( $ttab, array( 'labels' => maybe_serialize( $labels ) ), array( 'id' => (int) $tr['id'] ) ); // phpcs:ignore WordPress.DB
				$xlabels = (array) $jrow( $xtab, $jtax )['labels'];
				unset( $xlabels['singular_name'] );
				$wpdb->update( $xtab, array( 'labels' => maybe_serialize( $xlabels ) ), array( 'id' => (int) $xr['id'] ) ); // phpcs:ignore WordPress.DB
				$jreg( array( 'post' ), 'Fisch (übersetzt)', 'Riff (übersetzt)' );
				list( $st ) = $save( 'type', $jtype, function () {
					return array( 'has_archive' => 1 );
				} );
				$tr = $jrow( $ttab, $jtype );
				$check( 'JetEngine: an unrelated edit leaves a singular label JetEngine does not hold unheld', 200 === $st && ! isset( $tr['labels']['singular_name'] ) && ! empty( $tr['args']['has_archive'] ), $st . ' ' . wp_json_encode( $tr['labels'] ?? null ) );
				list( $st ) = $save( 'tax', $jtax, function () {
					return array( 'hierarchical' => 1 );
				} );
				$xr = $jrow( $xtab, $jtax );
				$check( 'JetEngine: the same for a taxonomy', 200 === $st && ! isset( $xr['labels']['singular_name'] ), $st . ' ' . wp_json_encode( $xr['labels'] ?? null ) );
				list( $st ) = $save( 'type', $jtype, function () {
					return array( 'singular' => 'Trout' );
				} );
				$tr = $jrow( $ttab, $jtype );
				$check( 'JetEngine: a singular label typed in the modal is written (control)', 200 === $st && 'Trout' === ( $tr['labels']['singular_name'] ?? null ), $st . ' ' . wp_json_encode( $tr['labels'] ?? null ) );
				$jreg( array( 'post' ), 'Trout' );
				list( $st ) = $save( 'type', $jtype, function () {
					return array( 'has_archive' => 0 );
				} );
				$tr = $jrow( $ttab, $jtype );
				$check( 'JetEngine: and a stored one rides along untouched (control)', 200 === $st && 'Trout' === ( $tr['labels']['singular_name'] ?? null ), $st . ' ' . wp_json_encode( $tr['labels'] ?? null ) );
			}
		} else {
			$skip( 'post types: JetEngine inactive' );
		}
	} finally {
		if ( $locked ) {
			remove_filter( 'cptui_required_capabilities', $locked );
		}
		$wpdb->query( 'ROLLBACK' );
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( array( 'cptui_post_types', 'cptui_taxonomies', Minn_Admin_CPT::OPTION, Minn_Admin_CPT::OPTION_TAX, 'rewrite_rules' ) as $o ) {
			wp_cache_delete( $o, 'options' );
		}
	}
} )();

$drop_users();
printf( "\nsecurity-v046-rc-core: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
