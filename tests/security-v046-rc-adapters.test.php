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

/*
 * 07-2 / 07-3 · Gravity Forms previews and test send. A test send ran their
 * send on the real latest entry, which records a notification note on it;
 * the previews and the test send filled the submitter's email, roles and
 * name, which Gravity Forms' entry screen never shows (only the login).
 */
( function () use ( $check, $skip, $call, $once, $make_user, $admin, $tag, &$mail ) {
	if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_guarded' ) || ! function_exists( 'minn_admin_gfc_preview' ) ) {
		$skip( '07-2/07-3 Gravity Forms: inactive' );
		return;
	}
	$role  = 'minn_rc_gf_' . $tag;
	$form  = 0;
	$clean = $once( function () use ( &$form, $role, $admin ) {
		wp_set_current_user( $admin );
		if ( is_int( $form ) && $form > 0 ) {
			GFAPI::delete_form( $form );
		}
		remove_role( $role );
	} );
	try {
		add_role( $role, 'Minn RC GF form editor', array( 'read' => true, 'gravityforms_edit_forms' => true, 'gravityforms_view_entries' => true ) );
		$ed   = $make_user( $role );
		$smail = 'minn-rc-submitter-' . $tag . '@example.com';
		$sub  = $make_user( 'subscriber', array( 'user_login' => 'minn-rc-submitter-' . $tag, 'user_email' => $smail, 'display_name' => 'RcSubmitter' . $tag, 'first_name' => 'RcFirst' . $tag, 'user_url' => 'https://rc-' . $tag . '.example.com' ) );
		$note = array( 'rcn' => array( 'id' => 'rcn', 'name' => 'RC note', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'S', 'message' => 'M', 'isActive' => true ) );
		$conf = array( 'rcc' => array( 'id' => 'rcc', 'name' => 'RC', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks' ) );
		$form = GFAPI::add_form( array( 'title' => 'Minn RC GF ' . $tag, 'fields' => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Nick' ) ), 'notifications' => $note, 'confirmations' => $conf ) );
		$form = is_int( $form ) && $form > 0 ? $form : 0;
		$eid  = $form && $sub ? GFAPI::add_entry( array( 'form_id' => $form, 'created_by' => $sub, '1' => 'typed {created_by:user_email}' ) ) : 0;
		$eid  = is_int( $eid ) ? $eid : 0;
		if ( ! $ed || ! $sub || ! $form || ! $eid ) {
			$check( '07-2/07-3 GF fixtures', false, 'could not create the accounts, the form or the entry' );
			return;
		}
		$login  = get_userdata( $sub )->user_login;
		$nroute = "/minn-admin/v1/gf/notifications/{$form}:rcn";
		$croute = "/minn-admin/v1/gf/confirmations/{$form}:rcc";
		$notes  = function () use ( $eid ) {
			return count( (array) GFFormsModel::get_lead_notes( $eid ) );
		};
		$profile = function ( $text ) use ( $smail, $tag ) {
			$leak = array();
			foreach ( array( 'email' => $smail, 'name' => 'RcSubmitter' . $tag, 'first name' => 'RcFirst' . $tag, 'url' => 'rc-' . $tag . '.example.com' ) as $what => $needle ) {
				if ( false !== strpos( (string) $text, $needle ) ) {
					$leak[] = $what;
				}
			}
			return $leak;
		};
		$tags = '{created_by:user_email} | {created_by:roles} | {created_by:display_name} | {created_by:first_name} | {created_by:user_url} | L={created_by:user_login} | I={created_by:ID} | {Nick:1}';

		wp_set_current_user( $ed );
		$check( '07-3 the form editor reads entries but may not list users', GFCommon::current_user_can_any( 'gravityforms_view_entries' ) && ! current_user_can( 'list_users' ) );

		// 07-2: the test send leaves the entry's notes alone.
		$was  = $notes();
		$mail = array();
		list( $st, $d ) = $call( 'POST', "{$nroute}/test", array( 'subject' => 'S', 'message' => 'M {Nick:1}', 'fromName' => '{created_by:user_email}' ) );
		$check( '07-2 control: the form editor\'s test send goes out, to them alone', 200 === $st && 1 === count( $mail ) && false !== strpos( wp_json_encode( $mail[0]['to'] ?? '' ), get_userdata( $ed )->user_email ), $st . ' ' . count( $mail ) );
		$check( '07-2 the test send writes no note on the real latest entry', $was === $notes(), $was . ' → ' . $notes() );
		$check( '07-3 the test send puts none of the submitter\'s profile in the mail, sender included', ! $profile( wp_json_encode( $mail ) ), implode( ',', $profile( wp_json_encode( $mail ) ) ) );

		// 07-3: previews show the login and id, nothing more of the account.
		list( $st, $d ) = $call( 'POST', "{$nroute}/preview", array( 'subject' => 'S {created_by:user_email}', 'message' => $tags ) );
		$out = wp_json_encode( $d );
		$check( '07-3 the notification preview is made from the entry', 200 === $st && $eid === (int) ( $d['entry'] ?? 0 ), $st );
		$check( '07-3 the notification preview fills no email, role, name or URL of the submitter', 200 === $st && ! $profile( $out ) && false === strpos( $out, 'subscriber' ), implode( ',', $profile( $out ) ) . ' ' . substr( $out, 0, 300 ) );
		$check( '07-3 those tags show as placeholders', false !== strpos( $out, '[tag: created_by:user_email]' ) && false !== strpos( $out, '[tag: created_by:roles]' ), substr( $out, 0, 300 ) );
		$check( '07-3 control: the login and id the entry screen shows still fill', false !== strpos( $out, 'L=' . $login ) && false !== strpos( $out, 'I=' . $sub ), substr( $out, 0, 300 ) );
		foreach ( array(
			'with a trailing space' => '{created_by:user_email }',
			'in upper case'         => '{CREATED_BY:user_email}',
			'with no colon'         => '{created_byuser_email}',
			'in doubled braces'     => '{{created_by:user_email}}',
			'inside another tag'    => '{created_by:{created_by:user_email}}',
			'split across a line'   => "{created_by:user_email\n}",
		) as $label => $variant ) {
			list( $st, $d ) = $call( 'POST', "{$nroute}/preview", array( 'subject' => 'S', 'message' => 'M ' . $variant, 'disableAutoformat' => true ) );
			$check( "07-3 the email does not fill {$label}", 200 === $st && ! $profile( wp_json_encode( $d ) ), $st . ' ' . substr( wp_json_encode( $d ), 0, 200 ) );
		}
		list( $st, $d ) = $call( 'POST', "{$croute}/preview", array( 'type' => 'message', 'message' => 'by {created_by:user_email} {created_by:display_name} L={created_by:user_login}' ) );
		$out = wp_json_encode( $d );
		$check( '07-3 the confirmation preview fills no email or name of the submitter', 200 === $st && ! $profile( $out ), implode( ',', $profile( $out ) ) . ' ' . substr( $out, 0, 200 ) );
		$check( '07-3 control: the confirmation preview still fills the login', false !== strpos( $out, 'L=' . $login ), substr( $out, 0, 200 ) );
		list( $st, $d ) = $call( 'POST', "{$croute}/preview", array( 'type' => 'redirect', 'url' => 'https://example.com/t', 'queryString' => 'e={created_by:user_email}&n={created_by:display_name}' ) );
		$check( '07-3 the redirect preview address carries no email or name of the submitter', 200 === $st && ! $profile( urldecode( wp_json_encode( $d ) ) ), substr( wp_json_encode( $d ), 0, 200 ) );

		// An administrator lists users, so the profile fields fill for them.
		wp_set_current_user( $admin );
		list( $st, $d ) = $call( 'POST', "{$nroute}/preview", array( 'subject' => 'S', 'message' => $tags ) );
		$out = wp_json_encode( $d );
		$check( '07-3 control: for an administrator the email and name still fill', 200 === $st && in_array( 'email', $profile( $out ), true ) && in_array( 'name', $profile( $out ), true ), substr( $out, 0, 300 ) );
		$was  = $notes();
		$mail = array();
		list( $st ) = $call( 'POST', "{$nroute}/test", array( 'subject' => 'S', 'message' => 'M' ) );
		$check( '07-2 an administrator\'s test send goes out and writes no note either', 200 === $st && 1 === count( $mail ) && $was === $notes(), $st . ' ' . count( $mail ) . ' ' . $was . ' → ' . $notes() );

		// Their own send records its note: the mechanism the test now stays out of.
		$was = $notes();
		$n   = $note['rcn'];
		$n['to'] = 'minn-rc-control@example.com';
		GFCommon::send_notification( $n, GFAPI::get_form( $form ), GFAPI::get_entry( $eid ) );
		$check( 'GF control: their own send still records its note on the entry', $notes() === $was + 1, $was . ' → ' . $notes() );
	} catch ( \Throwable $e ) {
		$check( 'section ran to the end', false, get_class( $e ) . ': ' . $e->getMessage() );
	} finally {
		$clean();
	}
} )();

/*
 * 10-1 · WooCommerce Memberships. A body without end_date read as "no end"
 * (an expired membership revived with no end) and one without
 * subscription_id as "unlink".
 */
( function () use ( $check, $skip, $call, $once, $make_user, $admin, $tag ) {
	if ( ! function_exists( 'minn_admin_wcm_active' ) || ! minn_admin_wcm_active() || ! function_exists( 'wc_memberships_create_user_membership' ) ) {
		$skip( '10-1 Memberships: WooCommerce Memberships inactive' );
		return;
	}
	$plan_id = 0;
	$mid     = 0;
	$clean   = $once( function () use ( &$plan_id, &$mid, $admin ) {
		wp_set_current_user( $admin );
		if ( is_int( $mid ) && $mid > 1 && 'wc_user_membership' === get_post_type( $mid ) ) {
			try {
				wc_memberships()->get_user_memberships_instance()->deleteUserMembership( $mid );
			} catch ( \Throwable $e ) {
				wp_delete_post( $mid, true );
			}
		}
		if ( is_int( $plan_id ) && $plan_id > 1 && 'wc_membership_plan' === get_post_type( $plan_id ) ) {
			wp_delete_post( $plan_id, true );
		}
	} );
	try {
		wp_set_current_user( $admin );
		$sm   = $make_user( 'shop_manager' );
		$cust = $make_user( 'customer' );
		$plan = wc_memberships()->get_plans_instance()->createPlan( array( 'name' => 'Minn RC plan ' . $tag, 'status' => 'publish' ) );
		$plan_id = $plan ? (int) $plan->get_id() : 0;
		$m       = ( $plan_id > 1 && $cust ) ? wc_memberships_create_user_membership( array( 'plan_id' => $plan_id, 'user_id' => $cust ) ) : null;
		$mid     = $m ? (int) $m->get_id() : 0;
		if ( ! $sm || $mid < 2 ) {
			$check( '10-1 Memberships fixtures', false, 'could not create the shop manager, the plan or the membership' );
			return;
		}
		$status = function () use ( &$mid ) {
			clean_post_cache( $mid );
			return (string) wc_memberships_get_user_membership( $mid )->get_status();
		};
		$end = function () use ( &$mid ) {
			wp_cache_delete( $mid, 'post_meta' );
			return (string) get_post_meta( $mid, '_end_date', true );
		};
		minn_admin_wcm_apply_end_date( wc_memberships_get_user_membership( $mid ), '2020-01-01' );
		$was_end = $end();
		$base    = "/minn-admin/v1/wcm/members/{$mid}";
		wp_set_current_user( $sm );
		$check( '10-1 the shop manager may edit the membership, which has expired', current_user_can( 'edit_post', $mid ) && 'expired' === $status(), $status() );

		list( $st, $d ) = $call( 'POST', "{$base}/end-date", array() );
		$check( '10-1 an end-date body without end_date is refused (400, missing parameter)', 400 === $st && 'rest_missing_callback_param' === ( $d['code'] ?? '' ), $st . ' ' . ( $d['code'] ?? '' ) );
		$check( '10-1 and the expired membership stays expired with its end', 'expired' === $status() && $was_end === $end(), $status() . ' ' . $end() );
		list( $st, $d ) = $call( 'POST', "{$base}/end-date", array( 'end_date' => null ) );
		$check( '10-1 an end_date of null is refused as missing', 400 === $st && 'expired' === $status(), $st . ' ' . $status() );
		list( $st, $d ) = $call( 'POST', "{$base}/end-date", array( 'end_date' => array( '2031-01-01' ) ) );
		$check( '10-1 an end_date that is not text is refused', 400 === $st && 'rest_invalid_param' === ( $d['code'] ?? '' ) && 'expired' === $status(), $st . ' ' . ( $d['code'] ?? '' ) );

		list( $st, $d ) = $call( 'POST', "{$base}/subscription", array() );
		$check( '10-1 a subscription body without subscription_id is refused before the run (400, missing parameter)', 400 === $st && 'rest_missing_callback_param' === ( $d['code'] ?? '' ), $st . ' ' . ( $d['code'] ?? '' ) );
		list( $st, $d ) = $call( 'POST', "{$base}/subscription", array( 'subscription_id' => 'abc' ) );
		$check( '10-1 a subscription_id that is not a number is refused', 400 === $st && 'rest_invalid_param' === ( $d['code'] ?? '' ), $st . ' ' . ( $d['code'] ?? '' ) );
		list( $st, $d ) = $call( 'POST', "{$base}/subscription", array( 'subscription_id' => 0 ) );
		$check( '10-1 control: an explicit 0 still reaches the unlink (not linked here)', 400 === $st && in_array( $d['code'] ?? '', array( 'not_linked', 'unsupported' ), true ), $st . ' ' . ( $d['code'] ?? '' ) );

		list( $st ) = $call( 'POST', "{$base}/end-date", array( 'end_date' => '' ) );
		$check( '10-1 control: an explicit empty end_date still clears the end and revives it', 200 === $st && 'active' === $status() && '' === $end(), $st . ' ' . $status() . ' ' . $end() );
		list( $st ) = $call( 'POST', "{$base}/end-date", array( 'end_date' => '2031-03-04' ) );
		$check( '10-1 control: a date still sets the end', 200 === $st && 0 === strpos( (string) minn_admin_wcm_local_day( $end() ), '2031-03-04' ), $st . ' ' . $end() );

		wp_set_current_user( $admin );
		list( $st, $d ) = $call( 'POST', "{$base}/end-date", array() );
		$check( '10-1 an administrator\'s body without end_date is refused too', 400 === $st && '' !== $end(), $st );
	} catch ( \Throwable $e ) {
		$check( 'section ran to the end', false, get_class( $e ) . ': ' . $e->getMessage() );
	} finally {
		$clean();
	}
} )();

/*
 * 10-2 · JetBooking. Their update endpoint checks the unit and dates are
 * free before any change; a status move skipped it, so a cancelled booking
 * could be put back over nights another booking now holds.
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag, $valid_id ) {
	if ( ! function_exists( 'minn_admin_jet_booking_active' ) || ! minn_admin_jet_booking_active() || ! minn_admin_jet_booking_has_tables() || ! function_exists( 'jet_abaf' ) ) {
		$skip( '10-2 JetBooking: not active' );
		return;
	}
	global $wpdb;
	$jt    = minn_admin_jet_booking_table();
	$ids   = array();
	$email = 'minn-rc-jb-' . $tag . '@example.com';
	$clean = $once( function () use ( &$ids, $jt, $email ) {
		global $wpdb;
		foreach ( $ids as $id ) {
			if ( is_int( $id ) && $id > 0 ) {
				$wpdb->delete( $jt, array( 'booking_id' => $id, 'user_email' => $email ) );
			}
		}
	} );
	try {
		wp_set_current_user( $admin );
		// An item id no post holds, so no real stay shares the nights.
		$item = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 100000 + (int) $tag; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$day  = strtotime( ( (int) gmdate( 'Y' ) + 2 ) . '-02-10 00:00:00 UTC' );
		$add  = function ( $status, $in_days, $nights ) use ( $wpdb, $jt, $item, $day, $email, $valid_id, &$ids ) {
			$in = $day + $in_days * DAY_IN_SECONDS;
			$wpdb->insert( $jt, array( 'status' => $status, 'apartment_id' => $item, 'apartment_unit' => 0, 'check_in_date' => $in + 1, 'check_out_date' => $in + $nights * DAY_IN_SECONDS, 'user_id' => 0, 'user_email' => $email ) );
			$id = (int) $wpdb->insert_id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			return $id;
		};
		$status = function ( $id ) use ( $wpdb, $jt ) {
			return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$jt} WHERE booking_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		};
		// Their own rule for the item's days (days off, weekdays switched
		// off) applies to every check here, so the nights must pass it.
		if ( ! jet_abaf()->tools->is_booking_period_available( array( 'apartment_id' => $item, 'check_in_date' => $day + 1, 'check_out_date' => $day + 4 * DAY_IN_SECONDS ) ) ) {
			$skip( '10-2 JetBooking: the lab\'s booking days leave out the test nights' );
			return;
		}
		$old  = $add( 'cancelled', 0, 2 );
		$held = $add( 'pending', 0, 2 );
		$temp = $add( 'created', 1, 1 );
		$next = $add( 'cancelled', 2, 2 );
		if ( ! $old || ! $held || ! $temp || ! $next ) {
			$check( '10-2 JetBooking fixtures', false, 'could not insert the bookings' );
			return;
		}
		$move = function ( $id, $to ) use ( $call ) {
			return $call( 'POST', "/minn-admin/v1/jet-booking/bookings/{$id}/status", array( 'status' => $to ) );
		};
		list( $st, $d ) = $move( $old, 'pending' );
		$check( '10-2 putting a cancelled booking back over nights another booking holds is refused (409)', 409 === $st, $st . ' ' . wp_json_encode( $d['code'] ?? $d ) );
		$check( '10-2 and it stays cancelled', 'cancelled' === $status( $old ), $status( $old ) );
		list( $st ) = $move( $old, 'completed' );
		$check( '10-2 nor can it move to another status that holds the nights', 409 === $st && 'cancelled' === $status( $old ), $st . ' ' . $status( $old ) );
		list( $st ) = $move( $temp, 'processing' );
		$check( '10-2 a temporary booking is refused the same way', 409 === $st && 'created' === $status( $temp ), $st . ' ' . $status( $temp ) );
		list( $st ) = $move( $next, 'pending' );
		$check( '10-2 control: one checking in on the day the other checks out goes back (200)', 200 === $st && 'pending' === $status( $next ), $st . ' ' . $status( $next ) );
		list( $st ) = $move( $held, 'processing' );
		$check( '10-2 control: a move between statuses that both hold the nights is not checked (200)', 200 === $st && 'processing' === $status( $held ), $st . ' ' . $status( $held ) );
		list( $st ) = $move( $held, 'cancelled' );
		$check( '10-2 control: cancelling is never refused (200)', 200 === $st && 'cancelled' === $status( $held ), $st . ' ' . $status( $held ) );
		list( $st ) = $move( $old, 'pending' );
		$check( '10-2 control: once the nights are free the cancelled booking goes back (200)', 200 === $st && 'pending' === $status( $old ), $st . ' ' . $status( $old ) );
	} catch ( \Throwable $e ) {
		$check( 'section ran to the end', false, get_class( $e ) . ': ' . $e->getMessage() );
	} finally {
		$clean();
	}
} )();

/*
 * 06-1 · Licences. Beaver Builder's licence form sits on its settings screen
 * (fl_builder_admin_settings_capability); Brizy Pro's licence tab exists
 * only while white label is off, its "show license tab" is on, or the
 * white-label session is open. Neither gated Minn's licence actions. No
 * action reaches a vendor here: a check that finds an action still attached
 * fails without calling it, and outbound HTTP is refused.
 */
( function () use ( $check, $skip, $call, $admin ) {
	if ( ! function_exists( 'minn_admin_license_default_providers' ) || ! function_exists( 'minn_admin_licenses_can_manage' ) ) {
		$skip( '06-1 Licences: the licences adapter is not loaded' );
		return;
	}
	wp_set_current_user( $admin );
	$prov = function () {
		return apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
	};
	$acts = function ( $id ) use ( $prov ) {
		$p = $prov()[ $id ] ?? array();
		return array_values( array_filter( array( 'activate', 'deactivate', 'verify' ), function ( $a ) use ( $p ) {
			return ! empty( $p[ $a ] ) && is_callable( $p[ $a ] );
		} ) );
	};

	if ( ! class_exists( 'FLUpdater' ) || ! in_array( 'activate', $acts( 'beaver-builder' ), true ) ) {
		$skip( '06-1 Beaver Builder: not active here, or its licence save is missing' );
	} else {
		$cap  = function () {
			return 'minn_rc_agency_only';
		};
		$back = function ( $p ) {
			if ( isset( $p['beaver-builder'] ) ) {
				$p['beaver-builder']['activate'] = function () {
					return array( 'ok' => true );
				};
			}
			return $p;
		};
		add_filter( 'fl_builder_admin_settings_capability', $cap );
		try {
			$check( '06-1 Beaver Builder: an administrator its settings screen refuses gets no licence action', array() === $acts( 'beaver-builder' ), implode( ',', $acts( 'beaver-builder' ) ) );
			if ( array() === $acts( 'beaver-builder' ) ) {
				list( $st, $d ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'beaver-builder', 'action' => 'activate', 'secret' => 'minn-rc-not-a-key' ) );
				$check( '06-1 Beaver Builder: the activate request is refused before any vendor call', 404 === $st && 'no_provider' === ( $d['code'] ?? '' ), $st . ' ' . ( $d['code'] ?? '' ) );
			} else {
				$check( '06-1 Beaver Builder: the activate request is refused before any vendor call', false, 'activate is still attached (not called)' );
			}
			add_filter( 'minn_admin_license_providers', $back, 50 );
			$check( '06-1 Beaver Builder: an action a filter attaches again is held to the same gate', array() === $acts( 'beaver-builder' ), implode( ',', $acts( 'beaver-builder' ) ) );
		} finally {
			remove_filter( 'minn_admin_license_providers', $back, 50 );
			remove_filter( 'fl_builder_admin_settings_capability', $cap );
		}
		$check( '06-1 Beaver Builder control: an administrator its screen admits keeps activate and verify', array( 'activate', 'verify' ) === $acts( 'beaver-builder' ), implode( ',', $acts( 'beaver-builder' ) ) );
	}

	if ( ! class_exists( 'BrizyPro_Admin_WhiteLabel' ) || ! class_exists( 'BrizyPro_Whitelabel_Value' ) || ! class_exists( 'BrizyPro_Admin_License' ) || ! in_array( 'deactivate', $acts( 'brizy-pro' ), true ) ) {
		$skip( '06-1 Brizy Pro: not active here, or its white label is already on' );
		return;
	}
	// Their white label as the agency would leave it, held on their own
	// object for this process only: nothing is written to their settings.
	$wl   = BrizyPro_Admin_WhiteLabel::_init();
	$prop = new ReflectionProperty( $wl, 'values' );
	$prop->setAccessible( true );
	$orig = $prop->getValue( $wl );
	$sess = 0;
	$sess_f = function () use ( &$sess ) {
		return $sess;
	};
	$with = function ( $brand, $show ) use ( $wl, $prop ) {
		$vals                     = (array) $wl->getValues();
		$vals['brizy']            = new BrizyPro_Whitelabel_Value( 'brizy', 'text', $brand, 'Company Name' );
		$vals['show-license-tab'] = new BrizyPro_Whitelabel_Value( 'show-license-tab', 'checkbox', $show, 'Show license tab' );
		$prop->setValue( $wl, $vals );
	};
	add_filter( 'pre_transient_' . BrizyPro_Admin_WhiteLabel::WL_SESSION_KEY, $sess_f );
	try {
		$with( 'Agency Builder', 0 );
		$check( '06-1 Brizy Pro: with white label on and the licence tab hidden, no licence action', array() === $acts( 'brizy-pro' ), implode( ',', $acts( 'brizy-pro' ) ) );
		if ( array() === $acts( 'brizy-pro' ) ) {
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => 'brizy-pro', 'action' => 'deactivate' ) );
			$check( '06-1 Brizy Pro: the deactivate request is refused before any vendor call', 404 === $st && 'no_provider' === ( $d['code'] ?? '' ), $st . ' ' . ( $d['code'] ?? '' ) );
		} else {
			$check( '06-1 Brizy Pro: the deactivate request is refused before any vendor call', false, 'deactivate is still attached (not called)' );
		}
		$with( 'Agency Builder', 1 );
		$check( '06-1 Brizy Pro control: "show license tab" on brings the actions back', array( 'activate', 'deactivate' ) === $acts( 'brizy-pro' ), implode( ',', $acts( 'brizy-pro' ) ) );
		$with( 'Agency Builder', 0 );
		$sess = 1;
		$check( '06-1 Brizy Pro control: an open white-label session brings them back, as it shows their tab', array( 'activate', 'deactivate' ) === $acts( 'brizy-pro' ), implode( ',', $acts( 'brizy-pro' ) ) );
	} finally {
		$prop->setValue( $wl, $orig );
		remove_filter( 'pre_transient_' . BrizyPro_Admin_WhiteLabel::WL_SESSION_KEY, $sess_f );
	}
	$check( '06-1 Brizy Pro control: with white label off the actions stay', array( 'activate', 'deactivate' ) === $acts( 'brizy-pro' ), implode( ',', $acts( 'brizy-pro' ) ) );
} )();

/*
 * 11-1 · Network settings. Core lets any manage_network_options holder save
 * the network settings screen; Minn's write fence keeps five of those
 * options for the network's owner. A save from anyone else wrote the
 * unfenced keys, then answered 403 for the rest; an invalid address
 * answered 400 after the keys before it were written.
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag, $valid_id ) {
	if ( ! is_multisite() || ! is_super_admin() || ! function_exists( 'minn_admin_network_settings_save' ) || ! class_exists( 'Minn_Admin_Fences' ) ) {
		$skip( '11-1 Network settings: needs a network and a super admin' );
		return;
	}
	$keys = array( 'blog_upload_space', 'fileupload_maxk', 'upload_filetypes', 'menu_items', 'registration', 'registrationnotification', 'add_new_users', 'admin_email', 'new_admin_email' );
	$net  = get_current_network_id();
	$had  = array();
	foreach ( $keys as $k ) {
		$sentinel  = new stdClass();
		$v         = get_network_option( $net, $k, $sentinel );
		$had[ $k ] = $v === $sentinel ? $sentinel : $v;
	}
	$main  = (int) get_main_site_id();
	$role  = 'minn_rc_netopt_' . $tag;
	$uid   = 0;
	$clean = $once( function () use ( &$uid, $had, $net, $main, $role, $admin ) {
		wp_set_current_user( $admin );
		foreach ( $had as $k => $v ) {
			if ( $v instanceof stdClass ) {
				delete_network_option( $net, $k );
			} elseif ( get_network_option( $net, $k ) !== $v ) {
				update_network_option( $net, $k, $v );
			}
		}
		if ( is_int( $uid ) && $uid > 1 && $uid !== $admin && ! is_super_admin( $uid ) ) {
			wpmu_delete_user( $uid );
		}
		switch_to_blog( $main );
		remove_role( $role );
		restore_current_blog();
	} );
	$opt = function ( $k ) use ( $net ) {
		wp_cache_delete( $net . ':' . $k, 'site-options' );
		return get_network_option( $net, $k );
	};
	$keys_of = function ( $d ) {
		$out = array();
		foreach ( (array) ( $d['groups'][0]['fields'] ?? array() ) as $f ) {
			$out[] = $f['key'];
		}
		return $out;
	};
	try {
		// A main-site role holding manage_network_options: core's network
		// settings screen admits it; it is not a super admin.
		switch_to_blog( $main );
		add_role( $role, 'Minn RC network options', array( 'read' => true, 'manage_network' => true, 'manage_network_options' => true ) );
		$login = 'minn-rc-netopt-' . $tag;
		$uid   = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.com', 'user_pass' => wp_generate_password( 24 ), 'role' => $role ) );
		restore_current_blog();
		if ( ! $valid_id( $uid ) ) {
			$check( '11-1 fixtures', false, 'could not create the main-site account' );
			return;
		}
		$space = (int) $opt( 'blog_upload_space' );
		$maxk  = (int) $opt( 'fileupload_maxk' );
		$notif = 'yes' === $opt( 'registrationnotification' );

		wp_set_current_user( $uid );
		$check( '11-1 the account holds manage_network_options on the main site and is not a super admin', minn_admin_network_can( 'manage_network_options' ) && ! is_super_admin() );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/network/settings/uploads' );
		$check( '11-1 the Uploads form leaves out the two fields only a super admin may change', 200 === $st && array( 'blog_upload_space' ) === $keys_of( $d ) && ! array_key_exists( 'fileupload_maxk', (array) ( $d['values'] ?? array() ) ), $st . ' ' . implode( ',', $keys_of( $d ) ) );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/network/settings/registration' );
		$check( '11-1 the Registration form keeps only the notification switch', 200 === $st && array( 'registrationnotification' ) === $keys_of( $d ), $st . ' ' . implode( ',', $keys_of( $d ) ) );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/network/settings/sites' );
		$check( '11-1 the Sites form leaves out the plugins switch', 200 === $st && array( 'admin_email' ) === $keys_of( $d ), $st . ' ' . implode( ',', $keys_of( $d ) ) );

		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/network/settings/uploads', array( 'values' => array( 'blog_upload_space' => $space + 7, 'fileupload_maxk' => $maxk + 3 ) ) );
		$check( '11-1 a save naming a super-admin-only field is refused whole (403)', 403 === $st && 'minn_network_owner' === ( $d['code'] ?? '' ), $st . ' ' . ( $d['code'] ?? '' ) );
		$check( '11-1 and writes neither field', $space === (int) $opt( 'blog_upload_space' ) && $maxk === (int) $opt( 'fileupload_maxk' ), $opt( 'blog_upload_space' ) . ' ' . $opt( 'fileupload_maxk' ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/network/settings/registration', array( 'values' => array( 'registrationnotification' => ! $notif, 'registration' => 'all' ) ) );
		$check( '11-1 the same on Registration: the notification switch stays as it was', 403 === $st && $notif === ( 'yes' === $opt( 'registrationnotification' ) ), $st . ' ' . $opt( 'registrationnotification' ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/network/settings/uploads', array( 'values' => array( 'blog_upload_space' => $space + 7 ) ) );
		$check( '11-1 control: the field core lets them change is written (200)', 200 === $st && $space + 7 === (int) $opt( 'blog_upload_space' ), $st . ' ' . $opt( 'blog_upload_space' ) );

		wp_set_current_user( $admin );
		update_network_option( $net, 'blog_upload_space', (string) $space );
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/network/settings/uploads' );
		$check( '11-1 control: a super admin gets every Uploads field', 200 === $st && array( 'blog_upload_space', 'fileupload_maxk', 'upload_filetypes' ) === $keys_of( $d ), implode( ',', $keys_of( $d ) ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/network/settings/uploads', array( 'values' => array( 'blog_upload_space' => $space + 9, 'fileupload_maxk' => $maxk + 5 ) ) );
		$check( '11-1 control: a super admin\'s save writes both', 200 === $st && $space + 9 === (int) $opt( 'blog_upload_space' ) && $maxk + 5 === (int) $opt( 'fileupload_maxk' ), $st );

		$items = (array) $opt( 'menu_items' );
		$on    = ! empty( $items['plugins'] );
		list( $st ) = $call( 'POST', '/minn-admin/v1/network/settings/sites', array( 'values' => array( 'menu_items_plugins' => ! $on, 'admin_email' => 'not an address' ) ) );
		$now = (array) $opt( 'menu_items' );
		$check( '11-1 an invalid address refuses the save before the plugins switch is written', 400 === $st && $on === ! empty( $now['plugins'] ), $st . ' ' . wp_json_encode( $now ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/network/settings/registration', array( 'values' => array( 'registrationnotification' => ! $notif, 'registration' => 'everyone' ) ) );
		$check( '11-1 a choice the field does not offer refuses the save, nothing written', 400 === $st && $notif === ( 'yes' === $opt( 'registrationnotification' ) ), $st . ' ' . $opt( 'registrationnotification' ) );

		// A list stored in a shape other than core's normalized one comes
		// back from the form unchanged: it is not rewritten.
		update_network_option( $net, 'upload_filetypes', 'jpg JPEG,png' );
		list( $st ) = $call( 'POST', '/minn-admin/v1/network/settings/uploads', array( 'values' => array( 'upload_filetypes' => 'jpg JPEG,png', 'blog_upload_space' => $space + 1 ) ) );
		$check( '11-1 a field sent back as loaded is not cleaned or written again', 200 === $st && 'jpg JPEG,png' === $opt( 'upload_filetypes' ) && $space + 1 === (int) $opt( 'blog_upload_space' ), $st . ' ' . wp_json_encode( $opt( 'upload_filetypes' ) ) );
		list( $st ) = $call( 'POST', '/minn-admin/v1/network/settings/uploads', array( 'values' => array( 'upload_filetypes' => 'jpg JPEG,png gif' ) ) );
		$check( '11-1 control: a changed list is normalized as core does', 200 === $st && 'jpg jpeg png gif' === $opt( 'upload_filetypes' ), $st . ' ' . wp_json_encode( $opt( 'upload_filetypes' ) ) );
	} catch ( \Throwable $e ) {
		$check( 'section ran to the end', false, get_class( $e ) . ': ' . $e->getMessage() );
	} finally {
		$clean();
	}
} )();

/*
 * 13-2 · Safe Redirect Manager. The edit turned a status left out (or
 * empty) into 301, refused a body without the source or target, and ran an
 * untouched target through SRM's sanitizer (a 410 rule's empty target
 * became "/").
 */
( function () use ( $check, $skip, $call, $once, $admin, $tag ) {
	if ( ! function_exists( 'minn_admin_srm_active' ) || ! minn_admin_srm_active() ) {
		$skip( '13-2 Safe Redirect Manager: inactive (load it with MINN_SWAP_ADD, see the header)' );
		return;
	}
	wp_set_current_user( $admin );
	if ( ! minn_admin_srm_can() ) {
		$skip( '13-2 Safe Redirect Manager: this administrator lacks its capability' );
		return;
	}
	$ids   = array();
	$clean = $once( function () use ( &$ids ) {
		foreach ( $ids as $id ) {
			if ( is_int( $id ) && $id > 1 && 'redirect_rule' === get_post_type( $id ) ) {
				wp_delete_post( $id, true );
			}
		}
		if ( function_exists( 'srm_flush_cache' ) ) {
			srm_flush_cache();
		}
	} );
	try {
		$one  = srm_create_redirect( '/minn-rc-src-' . $tag, '/minn-rc-dst-' . $tag, 301 );
		$gone = srm_create_redirect( '/minn-rc-gone-' . $tag, '/minn-rc-x-' . $tag, 410 );
		foreach ( array( $one, $gone ) as $id ) {
			if ( is_int( $id ) && $id > 1 ) {
				$ids[] = $id;
			}
		}
		if ( 2 !== count( $ids ) ) {
			$check( '13-2 SRM fixtures', false, 'could not create the rules' );
			return;
		}
		// SRM ignores a 410's target; a rule stored that way keeps none.
		update_post_meta( $gone, '_redirect_rule_to', '' );
		$rule = function ( $id ) {
			wp_cache_delete( $id, 'post_meta' );
			return minn_admin_srm_rule( $id );
		};
		$put = function ( $id, $body ) use ( $call ) {
			return $call( 'PUT', "/minn-admin/v1/srm/redirects/{$id}", $body );
		};
		$src = '/minn-rc-src-' . $tag;
		$dst = '/minn-rc-dst-' . $tag;

		list( $st ) = $put( $one, array( 'status_code' => 302 ) );
		$r = $rule( $one );
		$check( '13-2 a body with only the status writes it and keeps the source and target', 200 === $st && 302 === $r['status_code'] && $src === $r['from'] && $dst === $r['to'], $st . ' ' . wp_json_encode( $r ) );
		list( $st ) = $put( $one, array( 'from' => $src, 'to' => $dst ) );
		$check( '13-2 a body without the status keeps it (never 301)', 200 === $st && 302 === $rule( $one )['status_code'], $st . ' ' . $rule( $one )['status_code'] );
		list( $st ) = $put( $one, array( 'from' => $src, 'to' => $dst, 'status_code' => '' ) );
		$check( '13-2 an empty status is refused, not turned into 301', 400 === $st && 302 === $rule( $one )['status_code'], $st . ' ' . $rule( $one )['status_code'] );
		list( $st ) = $put( $one, array( 'status_code' => 999 ) );
		$check( '13-2 a status SRM does not know is refused', 400 === $st && 302 === $rule( $one )['status_code'], (string) $st );
		list( $st ) = $put( $one, array( 'from' => array( 'x' ) ) );
		$check( '13-2 a source that is not text is refused and nothing changes', 400 === $st && $src === $rule( $one )['from'], $st . ' ' . $rule( $one )['from'] );
		list( $st ) = $put( $gone, array( 'from' => '/minn-rc-gone-' . $tag, 'to' => '', 'status_code' => 410 ) );
		$check( '13-2 a 410 rule saved untouched keeps its empty target (not "/")', 200 === $st && '' === $rule( $gone )['to'], $st . ' ' . wp_json_encode( $rule( $gone )['to'] ) );

		list( $st ) = $put( $one, array( 'from' => 'minn-rc-renamed-' . $tag, 'to' => $dst, 'status_code' => 302 ) );
		$check( '13-2 control: a changed source is cleaned as SRM cleans it and written', 200 === $st && '/minn-rc-renamed-' . $tag === $rule( $one )['from'], $st . ' ' . $rule( $one )['from'] );
		list( $st ) = $put( $one, array( 'to' => 'www.example.com/minn-rc' ) );
		$check( '13-2 control: a changed target is cleaned as SRM cleans it and written', 200 === $st && 'http://www.example.com/minn-rc' === $rule( $one )['to'], $st . ' ' . $rule( $one )['to'] );
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
