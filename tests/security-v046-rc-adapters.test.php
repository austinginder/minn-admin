<?php
/**
 * v0.46.0 RC audit, adapters: writes that went further than the person
 * asked, or than the vendor allows. Each section names the finding it
 * answers and what the save did before.
 *
 * Each check carries a control: the role the feature is for still writes,
 * and an administrator is unaffected. Sections SKIP when their plugin (or a
 * network) is missing; everything a section makes or changes is removed or
 * put back however the run ends. Outbound mail is caught and HTTP refused.
 *
 * Run (single site; Safe Redirect Manager rests inactive, so the swap loads it):
 *   MINN_SWAP_ADD=safe-redirect-manager/safe-redirect-manager.php \
 *   wp eval-file tests/security-v046-rc-adapters.test.php --user=admin --path=<site> --require=tests/lib/plugin-swap.php
 * The licence section runs where Beaver Builder or Brizy Pro is active, the
 * network section on a site of a network other than the main one:
 *   wp eval-file tests/security-v046-rc-adapters.test.php --user=admin --path=<network> --url=<subsite>
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( is_multisite() ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
}

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
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
// A real id from an insert: a WP_Error cast to int is user 1.
$valid_id = function ( $id ) {
	return is_int( $id ) && $id > 1;
};
$tag   = (string) wp_rand( 10000, 99999 );
$admin = get_current_user_id();
// Accounts made here, removed at the end.
$made_users = array();
$make_user  = function ( $role, array $extra = array() ) use ( &$made_users, $valid_id, $tag ) {
	$login = 'minn-rc-ad-' . $role . '-' . $tag . '-' . count( $made_users );
	$id    = wp_insert_user( array_merge( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ), $extra ) );
	if ( ! $valid_id( $id ) ) {
		return 0;
	}
	$made_users[] = $id;
	return $id;
};
$drop_users = $once( function () use ( &$made_users, $admin ) {
	wp_set_current_user( $admin );
	foreach ( $made_users as $id ) {
		if ( is_int( $id ) && $id > 1 && $id !== $admin ) {
			if ( is_multisite() ) {
				wpmu_delete_user( $id );
			} else {
				wp_delete_user( $id );
			}
		}
	}
	$made_users = array();
} );

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();

// Nothing leaves the site: mail is caught (and answered as sent, which is
// what makes Gravity Forms record a note), outbound HTTP refused.
$mail      = array();
$mail_trap = function ( $pre, $atts ) use ( &$mail ) {
	$mail[] = $atts;
	return true;
};
$no_http   = function () {
	return new WP_Error( 'minn_rc_blocked', 'Outbound HTTP is refused during this suite.' );
};
add_filter( 'pre_wp_mail', $mail_trap, 1, 2 );
add_filter( 'pre_http_request', $no_http, 1 );

/*
 * 07-1 · CF7 Mail. The page sends only the sections and keys that changed;
 * the save rebuilt both mail properties from the stored ones and ran
 * wpcf7_sanitize_mail() over them (kses for anyone without unfiltered_html)
 * and trimmed every message.
 */
( function () use ( $check, $skip, $call, $once, $make_user, $admin, $tag ) {
	if ( ! defined( 'WPCF7_VERSION' ) || ! class_exists( 'WPCF7_ContactForm' ) || ! function_exists( 'minn_admin_cf7_mail_payload' ) ) {
		$skip( '07-1 CF7 Mail: Contact Form 7 inactive' );
		return;
	}
	$role  = 'minn_rc_cf7_' . $tag;
	$fid   = 0;
	$clean = $once( function () use ( &$fid, $role, $admin ) {
		wp_set_current_user( $admin );
		if ( is_int( $fid ) && $fid > 1 && 'wpcf7_contact_form' === get_post_type( $fid ) ) {
			wp_delete_post( $fid, true );
		}
		remove_role( $role );
	} );
	try {
		// What CF7 asks to edit a form (publish_pages), without unfiltered_html.
		add_role( $role, 'Minn RC CF7 editor', array( 'read' => true, 'edit_posts' => true, 'edit_pages' => true, 'publish_pages' => true ) );
		$ed = $make_user( $role );
		wp_set_current_user( $admin );
		$html   = "<html><head><meta charset=\"utf-8\"><style>p{color:#c00}</style></head><body><p>Thanks [your-name]</p></body></html>";
		$legacy = "From: [your-name] <[your-email]>\nSubject: [your-subject]\n\n[your-message]";
		$form   = WPCF7_ContactForm::get_template( array( 'title' => 'Minn RC CF7 ' . $tag ) );
		$m1     = (array) $form->prop( 'mail' );
		$m2     = (array) $form->prop( 'mail_2' );
		$msgs   = (array) $form->prop( 'messages' );
		$m1['subject']           = 'Orig subject';
		$m1['body']              = $legacy;
		$m2['active']            = true;
		$m2['use_html']          = true;
		$m2['body']              = $html;
		$msgs['mail_sent_ok']    = 'Old thanks';
		$msgs['mail_sent_ng']    = ' Spaced <b>edge</b> ';
		$form->set_properties( array( 'mail' => $m1, 'mail_2' => $m2, 'messages' => $msgs ) );
		$form->save();
		$fid = (int) $form->id();
		if ( $fid < 2 || ! $ed ) {
			$check( '07-1 CF7 fixtures', false, 'could not create the form or the editor' );
			return;
		}
		$route = "/minn-admin/v1/cf7/forms/{$fid}/mail";
		$prop  = function ( $k ) use ( &$fid ) {
			wp_cache_delete( $fid, 'post_meta' );
			return get_post_meta( $fid, '_' . $k, true );
		};
		wp_set_current_user( $ed );
		$check( '07-1 the editor may edit the form and lacks unfiltered_html', current_user_can( 'wpcf7_edit_contact_form', $fid ) && ! current_user_can( 'unfiltered_html' ) );

		list( $st ) = $call( 'POST', $route, array( 'messages' => array( 'mail_sent_ok' => 'Thanks, we got it.' ) ) );
		$check( '07-1 control: one message changed answers 200 and is written', 200 === $st && 'Thanks, we got it.' === ( $prop( 'messages' )['mail_sent_ok'] ?? null ), $st . ' ' . wp_json_encode( $prop( 'messages' )['mail_sent_ok'] ?? null ) );
		$check( '07-1 a message save leaves the Mail body byte for byte ("<[your-email]>" kept)', $legacy === ( $prop( 'mail' )['body'] ?? null ), wp_json_encode( $prop( 'mail' )['body'] ?? null ) );
		$check( '07-1 a message save leaves the HTML Mail (2) body byte for byte (<style> and <head> kept)', $html === ( $prop( 'mail_2' )['body'] ?? null ), wp_json_encode( $prop( 'mail_2' )['body'] ?? null ) );
		$check( '07-1 a message save leaves another message untrimmed', ' Spaced <b>edge</b> ' === ( $prop( 'messages' )['mail_sent_ng'] ?? null ), wp_json_encode( $prop( 'messages' )['mail_sent_ng'] ?? null ) );

		list( $st ) = $call( 'POST', $route, array( 'mail_2' => array( 'use_html' => false ) ) );
		$check( '07-1 turning HTML off for Mail (2) writes the switch (control)', 200 === $st && empty( $prop( 'mail_2' )['use_html'] ), $st );
		$check( '07-1 and keeps the body it did not send', $html === ( $prop( 'mail_2' )['body'] ?? null ), wp_json_encode( $prop( 'mail_2' )['body'] ?? null ) );

		list( , $served ) = $call( 'GET', $route );
		list( $st ) = $call( 'POST', $route, array( 'mail' => array( 'subject' => 'New subject', 'body' => (string) ( $served['mail']['body'] ?? '' ) ) ) );
		$check( '07-1 a subject edit that sends the body back as served writes the subject (control)', 200 === $st && 'New subject' === ( $prop( 'mail' )['subject'] ?? null ), $st );
		$check( '07-1 and keeps that body byte for byte', $legacy === ( $prop( 'mail' )['body'] ?? null ), wp_json_encode( $prop( 'mail' )['body'] ?? null ) );

		list( $st ) = $call( 'POST', $route, array( 'mail' => 'not a section', 'mail_2' => array( 'body' => array( 'nested' ) ) ) );
		$check( '07-1 a section that is not an object, or a body that is not text, writes nothing', 200 === $st && $legacy === ( $prop( 'mail' )['body'] ?? null ) && $html === ( $prop( 'mail_2' )['body'] ?? null ), $st );

		$before = get_post_field( 'post_modified_gmt', $fid );
		list( $st ) = $call( 'POST', $route, array() );
		$check( '07-1 an empty save answers 200 and stores nothing', 200 === $st && $legacy === ( $prop( 'mail' )['body'] ?? null ) && ' Spaced <b>edge</b> ' === ( $prop( 'messages' )['mail_sent_ng'] ?? null ), $st );

		list( $st ) = $call( 'POST', $route, array( 'mail' => array( 'body' => $legacy . "\nEdited" ) ) );
		$body = (string) ( $prop( 'mail' )['body'] ?? '' );
		$check( '07-1 control: a body the editor changed is cleaned as CF7 cleans it for them', 200 === $st && false !== strpos( $body, 'Edited' ) && false === strpos( $body, '<[your-email]>' ), wp_json_encode( $body ) );

		wp_set_current_user( $admin );
		list( $st ) = $call( 'POST', $route, array( 'mail_2' => array( 'body' => $html . "\n<p>More</p>" ) ) );
		$check( '07-1 control: an administrator\'s body edit keeps its <style>', 200 === $st && false !== strpos( (string) ( $prop( 'mail_2' )['body'] ?? '' ), '<style>p{color:#c00}</style>' ), $st );
	} catch ( \Throwable $e ) {
		$check( 'section ran to the end', false, get_class( $e ) . ': ' . $e->getMessage() );
	} finally {
		$clean();
	}
} )();

$drop_users();
wp_set_current_user( $admin );
remove_filter( 'pre_wp_mail', $mail_trap, 1 );
remove_filter( 'pre_http_request', $no_http, 1 );
printf( "\nsecurity-v046-rc-adapters: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
