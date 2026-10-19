<?php
/**
 * Regressions for the v0.46.0 shipped-zip registry scan, egress lane
 * (findings that open v0.47.0).
 *
 *  - 00-2 WP Crontrol: the event list and detail cut cron arguments to 200
 *    bytes before the egress fence saw them, so a credential copy across
 *    the cut printed up to the cut, unmasked.
 *  - 15-1 WP Activity Log: the event detail cut each context value to 300
 *    bytes the same way.
 *  - The same shape at the admin-notice capture (399 characters, stored and
 *    served later), the notification list (159) and WP Mail SMTP's debug
 *    event list (119). One rule now serves them all: the whole text is
 *    masked first, then cut (Minn_Admin_Egress::clip()).
 *  - 05-1 Database browser: Contact Form 7's settings row (Stripe secret
 *    key, captcha secrets, Brevo key, Constant Contact tokens), Yoast SEO's
 *    (SEMrush, Wincher and MyYoast OAuth tokens) and Yoast's per-user AI
 *    tokens printed in full.
 *  - S3 (review round, the same class): Amelia's settings row, UpdraftPlus'
 *    remote storage rows and its UpdraftCentral and Migrator key rows.
 *
 * Every response is read as a browser receives it: dispatched, then through
 * the server's response filters, where the egress fence runs. The credential
 * is a core Connector key row made for the run. Fixtures (that row, a cron
 * event, an activity log event, a mail debug event, a user, a notice store)
 * are removed at the end however the run ends.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v047-registry-egress.test.php --user=admin --path=<site>
 *      WP Mail SMTP is kept inactive on the lab; its section loads it for the run:
 *      MINN_SWAP_ADD=wp-mail-smtp/wp_mail_smtp.php wp eval-file <same> --user=admin --path=<site> --require=<ABSOLUTE path>/tests/lib/plugin-swap.php
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = static function ( callable $fn ) {
	$done = false;
	$run  = static function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};

if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Minn_Admin_Egress' ) || ! class_exists( 'Minn_Admin_DB' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}
global $wpdb;
rest_get_server();
$tag = strtolower( wp_generate_password( 6, false ) );

// What a browser receives: dispatch, the server's response filters, the
// body as sent (the egress fence runs on rest_pre_echo_response).
$send = static function ( $method, $route, $query = array() ) {
	$server = rest_get_server();
	$req    = new WP_REST_Request( $method, $route );
	foreach ( $query as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	return array( $res->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
};
$as = static function ( $uid, callable $fn ) {
	$was = get_current_user_id();
	wp_set_current_user( $uid );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $was );
	}
};
// Both credential lists read again: the browser's and the fence's.
$forget = static function () {
	Minn_Admin_DB::forget_credentials();
	Minn_Admin_Egress::reset();
};
$fp = static function ( $k ) {
	return '[redacted:' . substr( hash( 'sha256', $k ), 0, 6 ) . ']';
};
$has = static function ( $hay, $needle ) {
	return false !== strpos( is_string( $hay ) ? $hay : (string) wp_json_encode( $hay ), (string) $needle );
};

// The credential: a core Connector key row (redacted by its name shape).
$key_row = 'connectors_minn047' . $tag . '_api_key';
$secret  = 'mEg' . $tag . 'Kx7Q2' . strtolower( wp_generate_password( 12, false, false ) ) . '94Ly3Zt8';
$head    = substr( $secret, 0, 8 );
if ( false !== get_option( $key_row, false ) ) {
	echo "SKIP  the probe row exists already\n";
	return;
}
$clean_key = $once( static function () use ( $key_row, $forget ) {
	delete_option( $key_row );
	$forget();
} );
add_option( $key_row, $secret, '', 'no' );
$forget();
$check( 'fixture: the browser and the fence count the probe key as a credential', in_array( $secret, Minn_Admin_DB::egress_credentials(), true ) );

try {
	/*
	 * The rule itself. Not present before the fix: the sections below are the
	 * repro through each route.
	 */
	if ( ! method_exists( 'Minn_Admin_Egress', 'clip' ) ) {
		$check( 'clip: Minn_Admin_Egress::clip() exists', false, 'missing' );
	} else {
		$long = str_repeat( 'a', 95 ) . ' ' . $secret . ' tail';
		$cut  = Minn_Admin_Egress::clip( $long, 100 );
		$check( 'clip: a key across the cut is masked before the cut, never shown in part', ! $has( $cut, $head ) && $has( $cut, $fp( $secret ) ) && '…' === mb_substr( $cut, -1 ), $cut );
		$check( 'clip: a mask the cut would split is kept whole', 1 === preg_match( '/\[redacted:[0-9a-f]{6}\]…$/', $cut ), $cut );
		$plain = str_repeat( 'b', 150 );
		$check( 'clip control: text with no key is cut where it was', str_repeat( 'b', 100 ) . '…' === Minn_Admin_Egress::clip( $plain, 100 ) );
		$check( 'clip control: text that fits comes back untouched (the fence sees it whole)', $secret === Minn_Admin_Egress::clip( $secret, 100 ) );
		$check( 'clip control: characters, not bytes, and no ellipsis when asked for none', 'ééé' === Minn_Admin_Egress::clip( 'éééé', 3, '' ) );
		$premasked = str_repeat( 'c', 96 ) . $fp( $secret ) . ' more';
		$check( 'clip: a mask already in stored text is not cut in two', 1 === preg_match( '/\[redacted:[0-9a-f]{6}\]…$/', Minn_Admin_Egress::clip( $premasked, 100 ) ) );
	}

	/*
	 * 00-2 WP Crontrol. Its display is JSON ["<padding>","<key>"]: the key
	 * starts at byte 190 and the cut falls at 200.
	 */
	if ( ! function_exists( 'minn_admin_crontrol_ready' ) || ! minn_admin_crontrol_ready() ) {
		$skip( '00-2 WP Crontrol is not active' );
	} else {
		$hook  = 'minn_v047_egress_' . $tag;
		$when  = time() + 3600;
		$args  = array( str_repeat( 'a', 185 ), $secret );
		$short = array( 'k', $secret );
		$wide  = array( str_repeat( 'w', 250 ) );
		$clean = $once( static function () use ( $hook ) {
			wp_unschedule_hook( $hook );
		} );
		try {
			wp_schedule_single_event( $when, $hook, $args );
			wp_schedule_single_event( $when + 60, $hook, $short );
			wp_schedule_single_event( $when + 120, $hook, $wide );
			$forget();
			list( $st, $d ) = $send( 'GET', '/minn-admin/v1/crontrol/events', array( 'search' => $hook, 'per_page' => 50 ) );
			$rows = array();
			foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
				if ( $hook === ( $it['hook'] ?? '' ) ) {
					$rows[ (string) ( $it['date'] ?? '' ) ] = $it;
				}
			}
			$at   = static function ( $ts ) use ( $rows ) {
				return $rows[ gmdate( 'Y-m-d\TH:i:s\Z', $ts ) ] ?? null;
			};
			$long = $at( $when );
			$check( "00-2 Crontrol list: the probe events are listed ({$st})", 200 === $st && 3 === count( $rows ), wp_json_encode( array_keys( $rows ) ) );
			$check( '00-2 Crontrol list: a key across the 200-byte cut prints no part of it', $long && ! $has( $long['args'], $head ) && $has( $long['args'], $fp( $secret ) ), $long ? substr( (string) $long['args'], -40 ) : 'no row' );
			$sh = $at( $when + 60 );
			$check( '00-2 Crontrol list control: short arguments holding the key show the mask (the fence, as before)', $sh && ! $has( $sh['args'], $secret ) && $has( $sh['args'], $fp( $secret ) ), $sh ? (string) $sh['args'] : 'no row' );
			$wd = $at( $when + 120 );
			$check( '00-2 Crontrol list control: long arguments with no key are cut at 200 as before', $wd && '["' . str_repeat( 'w', 198 ) . '…' === $wd['args'], $wd ? (string) $wd['args'] : 'no row' );
			if ( $long && ! empty( $long['id'] ) ) {
				list( $st, $dd ) = $send( 'GET', '/minn-admin/v1/crontrol/events/' . $long['id'] );
				$argrow          = null;
				foreach ( (array) ( $dd['sections'] ?? array() ) as $sec ) {
					foreach ( (array) ( $sec['rows'] ?? array() ) as $r ) {
						if ( 'Arguments' === ( $r['label'] ?? '' ) ) {
							$argrow = (string) $r['value'];
						}
					}
				}
				if ( null === $argrow ) {
					foreach ( (array) ( $dd['meta'] ?? array() ) as $r ) {
						if ( 'Arguments' === ( $r['label'] ?? '' ) ) {
							$argrow = (string) $r['value'];
						}
					}
				}
				$check( "00-2 Crontrol detail: the Arguments row prints no part of the key ({$st})", 200 === $st && ! $has( $dd, $head ) && null !== $argrow && $has( $argrow, $fp( $secret ) ), null === $argrow ? wp_json_encode( array_keys( (array) $dd ) ) : substr( $argrow, -40 ) );
			}
		} finally {
			$clean();
		}
	}

	/*
	 * 15-1 WP Activity Log. A logged custom field value whose key starts at
	 * byte 290; the cut falls at 300.
	 */
	$occ  = $wpdb->base_prefix . 'wsal_occurrences';
	$meta = $wpdb->base_prefix . 'wsal_metadata';
	if ( ! function_exists( 'minn_admin_wsal_can_view' ) || $occ !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $occ ) ) || $meta !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $meta ) ) || ! minn_admin_wsal_can_view() ) {
		$skip( '15-1 WP Activity Log is not active, or its tables are missing' );
	} else {
		$ids   = array();
		$clean = $once( static function () use ( &$ids, $occ, $meta ) {
			global $wpdb;
			foreach ( $ids as $id ) {
				$wpdb->delete( $meta, array( 'occurrence_id' => $id ) );
				$wpdb->delete( $occ, array( 'id' => $id ) );
			}
		} );
		try {
			$ok = $wpdb->insert( $occ, array( 'site_id' => get_current_blog_id(), 'alert_id' => 2053, 'created_on' => microtime( true ), 'client_ip' => '192.0.2.47', 'severity' => '200', 'object' => 'post', 'event_type' => 'modified', 'user_agent' => 'minn-v047', 'user_roles' => 'administrator', 'username' => 'minn047' . $tag, 'user_id' => get_current_user_id(), 'session_id' => '', 'post_status' => 'publish', 'post_type' => 'post', 'post_id' => 0 ) );
			$id = $ok ? (int) $wpdb->insert_id : 0;
			if ( $id ) {
				$ids[] = $id;
				$wpdb->insert( $meta, array( 'occurrence_id' => $id, 'name' => 'MetaKey', 'value' => 'integration_settings' ) );
				$wpdb->insert( $meta, array( 'occurrence_id' => $id, 'name' => 'MetaValue', 'value' => str_repeat( 'v', 290 ) . $secret ) );
				$wpdb->insert( $meta, array( 'occurrence_id' => $id, 'name' => 'MetaValueShort', 'value' => 'key=' . $secret ) );
				$wpdb->insert( $meta, array( 'occurrence_id' => $id, 'name' => 'MetaValueWide', 'value' => str_repeat( 'q', 400 ) ) );
			}
			$forget();
			list( $st, $d ) = $send( 'GET', '/minn-admin/v1/wsal/events/' . $id );
			$ctx            = array();
			foreach ( (array) ( $d['sections'] ?? array() ) as $sec ) {
				foreach ( (array) ( $sec['rows'] ?? array() ) as $r ) {
					$ctx[ (string) ( $r['label'] ?? '' ) ] = (string) ( $r['value'] ?? '' );
				}
			}
			$check( "15-1 WP Activity Log: the probe event opens ({$st})", $id && 200 === $st && isset( $ctx['MetaValue'] ), wp_json_encode( array_keys( $ctx ) ) );
			$check( '15-1 WP Activity Log: a context value with a key across the 300-byte cut prints no part of it', isset( $ctx['MetaValue'] ) && ! $has( $d, $head ) && $has( $ctx['MetaValue'], $fp( $secret ) ), substr( $ctx['MetaValue'] ?? '', -40 ) );
			$check( '15-1 WP Activity Log control: a short value holding the key shows the mask (the fence, as before)', 'key=' . $fp( $secret ) === ( $ctx['MetaValueShort'] ?? '' ), $ctx['MetaValueShort'] ?? '' );
			$check( '15-1 WP Activity Log control: a long value with no key is cut at 300 as before, and short ones print whole', str_repeat( 'q', 300 ) . '…' === ( $ctx['MetaValueWide'] ?? '' ) && 'integration_settings' === ( $ctx['MetaKey'] ?? '' ) );
		} finally {
			$clean();
		}
	}

	/*
	 * Admin notices: the capture cuts each notice's text at 399 characters
	 * and stores it; the notification list cuts it again at 159.
	 */
	if ( ! class_exists( 'Minn_Admin_Notices' ) ) {
		$skip( 'notices: Minn_Admin_Notices missing' );
	} else {
		$html    = '<div class="notice notice-warning"><p>Minn047 ' . str_repeat( 'n', 380 ) . ' ' . $secret . ' and some words after it</p></div>';
		$entries = Minn_Admin_Notices::parse( $html );
		$text    = (string) ( $entries[0]['text'] ?? '' );
		$check( 'notices capture: a key across the 399-character cut is stored masked, never in part', '' !== $text && ! $has( $text, $head ) && $has( $text, $fp( $secret ) ), substr( $text, -40 ) );
		$ctrl = Minn_Admin_Notices::parse( '<div class="notice notice-info"><p>' . str_repeat( 'p', 450 ) . '</p></div>' );
		$check( 'notices capture control: a long notice with no key is cut at 399 as before', str_repeat( 'p', 399 ) . '…' === ( $ctrl[0]['text'] ?? '' ) );

		$uid   = wp_insert_user( array( 'user_login' => 'minn047egress_' . $tag, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minn047egress_' . $tag . '@example.test', 'role' => 'administrator' ) );
		$key   = '';
		$clean = $once( static function () use ( &$uid, &$key ) {
			if ( $key ) {
				delete_transient( $key );
			}
			if ( $uid && ! is_wp_error( $uid ) ) {
				wp_delete_user( $uid );
			}
		} );
		try {
			if ( is_wp_error( $uid ) ) {
				$skip( 'notices list: fixture user not made' );
			} else {
				$m = new ReflectionMethod( 'Minn_Admin_Notices', 'store_key' );
				$m->setAccessible( true );
				$items = $as( $uid, static function () use ( $m, &$key, $secret ) {
					$key = (string) $m->invoke( null );
					set_transient( $key, array(
						'captured' => time(),
						'items'    => array(
							array( 'id' => 'minn047a', 'severity' => 'info', 'dismissible' => false, 'text' => str_repeat( 'm', 148 ) . ' ' . $secret . ' tail', 'links' => array(), 'owner' => array( 'name' => 'Probe', 'slug' => 'probe', 'type' => 'plugin' ) ),
							array( 'id' => 'minn047b', 'severity' => 'info', 'dismissible' => false, 'text' => str_repeat( 'o', 200 ), 'links' => array(), 'owner' => array( 'name' => 'Probe', 'slug' => 'probe', 'type' => 'plugin' ) ),
						),
						'menu_removed' => array(),
					), HOUR_IN_SECONDS );
					return Minn_Admin_Notices::items_for_user();
				} );
				$by = array();
				foreach ( (array) $items as $it ) {
					$by[ $it['id'] ] = (string) $it['title'];
				}
				$check( 'notices list: a stored notice with a key across the 159-character cut is listed masked, never in part', isset( $by['notice-minn047a'] ) && ! $has( $by['notice-minn047a'], $head ) && $has( $by['notice-minn047a'], $fp( $secret ) ), substr( $by['notice-minn047a'] ?? '', -40 ) );
				$check( 'notices list control: a long notice with no key is cut at 159 as before', 'Probe: ' . str_repeat( 'o', 159 ) . '…' === ( $by['notice-minn047b'] ?? '' ), $by['notice-minn047b'] ?? '' );
			}
		} finally {
			$clean();
		}
	}

	/*
	 * WP Mail SMTP's debug event list cuts each event at 119 characters.
	 */
	$dbg = $wpdb->prefix . 'wpmailsmtp_debug_events';
	if ( ! function_exists( 'minn_admin_wp_mail_smtp_active' ) || ! minn_admin_wp_mail_smtp_active() || $dbg !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $dbg ) ) ) {
		$skip( 'WP Mail SMTP is not loaded (run with MINN_SWAP_ADD=wp-mail-smtp/wp_mail_smtp.php), or its debug table is missing' );
	} else {
		$ids   = array();
		$clean = $once( static function () use ( &$ids, $dbg ) {
			global $wpdb;
			foreach ( $ids as $id ) {
				$wpdb->delete( $dbg, array( 'id' => $id ) );
			}
		} );
		try {
			$mark = 'minn047' . $tag;
			foreach ( array( $mark . ' ' . str_repeat( 'e', 92 ) . ' ' . $secret . ' tail', $mark . ' ' . str_repeat( 'f', 200 ) ) as $content ) {
				if ( $wpdb->insert( $dbg, array( 'content' => $content, 'initiator' => '', 'event_type' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ) ) {
					$ids[] = (int) $wpdb->insert_id;
				}
			}
			$forget();
			list( $st, $d ) = $send( 'GET', '/minn-admin/v1/wp-mail-smtp/events', array( 'search' => $mark ) );
			$by = array();
			foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
				$by[ (int) ( $it['id'] ?? 0 ) ] = (string) ( $it['content'] ?? '' );
			}
			$a = $by[ $ids[0] ?? -1 ] ?? null;
			$b = $by[ $ids[1] ?? -1 ] ?? null;
			$check( "WP Mail SMTP list: the probe events are listed ({$st})", 200 === $st && null !== $a && null !== $b, wp_json_encode( array_keys( $by ) ) );
			$check( 'WP Mail SMTP list: an event with a key across the 119-character cut prints no part of it', null !== $a && ! $has( $a, $head ) && $has( $a, $fp( $secret ) ), (string) $a );
			$check( 'WP Mail SMTP list control: a long event with no key is cut at 119 as before', $mark . ' ' . str_repeat( 'f', 119 - strlen( $mark ) - 1 ) . '…' === $b, (string) $b );
		} finally {
			$clean();
		}
	}
} finally {
	$clean_key();
}

/*
 * 05-1 Database browser: Contact Form 7's and Yoast SEO's settings rows, and
 * Yoast's per-user AI tokens, are credential rows. The shapes are the
 * vendors' own (CF7 6.2 WPCF7::update_option; Yoast 28 WPSEO_Option_Wpseo
 * and OAuth_Token::to_array()).
 */
$cf7 = array(
	'version'          => '6.2.2',
	'stripe'           => array( 'api_keys' => array( 'publishable' => 'pk_test_' . $tag . '51Pub9Key8x', 'secret' => 'sk_live_' . $tag . '51Sec9Key8xYz' ) ),
	'recaptcha'        => array( '6Lc' . $tag . 'SiteKey000AAAAA111' => '6Lc' . $tag . 'SecretKey99BBBB222' ),
	'sendinblue'       => array( 'api_key' => 'xkeysib-' . $tag . '0123456789abcdef0123456789abcdef-Ab12Cd34Ef56Gh78' ),
	'constant_contact' => array( 'client_id' => 'cid-' . $tag, 'client_secret' => 'ccSec' . $tag . '4455Zz99', 'access_token' => 'ccAcc' . $tag . '7788Yy11', 'refresh_token' => 'ccRef' . $tag . '3322Xx66' ),
);
$tok  = array( 'access_token' => 'yAcc' . $tag . '1234Qq5678', 'refresh_token' => 'yRef' . $tag . '8765Ww4321', 'expires' => time() + 3600, 'has_expired' => false, 'created_at' => time(), 'error_count' => 0 );
$seo  = array( 'version' => '28.7', 'googleverify' => 'gv' . $tag . '123abc456', 'semrush_tokens' => $tok, 'wincher_tokens' => $tok, 'myyoast-oauth' => array( 'config' => array( 'clientId' => 'yoast-client', 'secret' => 'ySec' . $tag . '2468Ee1357' ), 'access_tokens' => array() ) );
$opt  = static function ( $name, $value ) use ( $wpdb ) {
	return Minn_Admin_DB::redacts_cell( $wpdb->options, 'option_value', array( 'option_name' => $name, 'option_value' => maybe_serialize( $value ) ) );
};
$check( "05-1 Contact Form 7's settings row (wpcf7) is redacted whole in the browser and named a credential row", $opt( 'wpcf7', $cf7 ) && Minn_Admin_DB::is_credential_option( 'wpcf7' ) );
$check( "05-1 Yoast SEO's settings row (wpseo) is redacted whole in the browser and named a credential row", $opt( 'wpseo', $seo ) && Minn_Admin_DB::is_credential_option( 'wpseo' ) );
$found = Minn_Admin_DB::credential_strings( maybe_serialize( $cf7 ) );
$want  = array( $cf7['stripe']['api_keys']['secret'], $cf7['sendinblue']['api_key'], $cf7['constant_contact']['client_secret'], $cf7['constant_contact']['access_token'], $cf7['constant_contact']['refresh_token'] );
$check( "05-1 CF7: the row's Stripe secret, Brevo key and Constant Contact secret and tokens become the fence's needles", ! array_diff( $want, array_map( 'strval', array_keys( $found ) ) ), wp_json_encode( array_keys( $found ) ) );
$found = Minn_Admin_DB::credential_strings( maybe_serialize( $seo ) );
$want  = array( $tok['access_token'], $tok['refresh_token'], $seo['myyoast-oauth']['config']['secret'] );
$check( "05-1 Yoast: the row's SEMrush, Wincher and MyYoast OAuth secrets become the fence's needles", ! array_diff( $want, array_map( 'strval', array_keys( $found ) ) ) && ! isset( $found[ $seo['googleverify'] ] ), wp_json_encode( array_keys( $found ) ) );
$check( '05-1 control: a row of neither plugin stays readable', ! $opt( 'wpcf7_probe_' . $tag, $cf7 ) && ! $opt( 'wpseo_titles', array( 'title-post' => '%%title%%' ) ) );

// The lab's own rows, through the browser's routes.
foreach ( array( 'wpcf7', 'wpseo' ) as $o ) {
	$oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $o ) );
	if ( ! $oid ) {
		$skip( "05-1 {$o}: no such row here" );
		continue;
	}
	list( $st, $d ) = $send( 'GET', '/minn-admin/v1/db/row', array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $oid ) ) ) );
	$cell           = null;
	foreach ( (array) ( $d['columns'] ?? array() ) as $i => $c ) {
		if ( 'option_value' === ( $c['name'] ?? '' ) ) {
			$cell = $d['cells'][ $i ] ?? null;
		}
	}
	$check( "05-1 {$o}: the site's row opens redacted in the browser ({$st})", 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ), wp_json_encode( $cell ) );
}

// Yoast's per-user AI tokens, on a fixture user.
$jwt   = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJtaW5uIn0.' . 'Sig' . $tag . '0aB1cD2eF3gH4iJ5kL6mN7oP8';
$juid  = wp_insert_user( array( 'user_login' => 'minn047jwt_' . $tag, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minn047jwt_' . $tag . '@example.test', 'role' => 'subscriber' ) );
$clean = $once( static function () use ( &$juid ) {
	if ( $juid && ! is_wp_error( $juid ) ) {
		wp_delete_user( $juid );
	}
} );
try {
	if ( is_wp_error( $juid ) ) {
		$skip( '05-1 Yoast AI tokens: fixture user not made' );
	} else {
		foreach ( array( '_yoast_wpseo_ai_generator_access_jwt', '_yoast_wpseo_ai_generator_refresh_jwt' ) as $mk ) {
			update_user_meta( $juid, $mk, $jwt );
			$check( "05-1 Yoast {$mk} is redacted in the browser", Minn_Admin_DB::redacts_cell( $wpdb->usermeta, 'meta_value', array( 'meta_key' => $mk, 'meta_value' => $jwt ) ) );
			$umid           = (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $juid, $mk ) );
			list( $st, $d ) = $send( 'GET', '/minn-admin/v1/db/row', array( 'table' => $wpdb->usermeta, 'pk' => wp_json_encode( array( 'umeta_id' => $umid ) ) ) );
			$cell           = null;
			foreach ( (array) ( $d['columns'] ?? array() ) as $i => $c ) {
				if ( 'meta_value' === ( $c['name'] ?? '' ) ) {
					$cell = $d['cells'][ $i ] ?? null;
				}
			}
			$check( "05-1 Yoast {$mk}: the row opens redacted ({$st})", 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ) && ! $has( $d, 'Sig' . $tag ), wp_json_encode( $cell ) );
		}
		$check( '05-1 control: a user meta row of no listed key stays readable', ! Minn_Admin_DB::redacts_cell( $wpdb->usermeta, 'meta_value', array( 'meta_key' => '_yoast_wpseo_profile_updated', 'meta_value' => '1700000000' ) ) );
	}
} finally {
	$clean();
}

/*
 * S3 (review round, sibling of 05-1): Amelia's settings row, UpdraftPlus'
 * remote storage rows and its UpdraftCentral / Migrator key rows are
 * credential rows. Ninja Forms' settings row stays readable: its own
 * settings screen prints its reCAPTCHA secret in a plain field to the same
 * administrator.
 */
$up_rows = array( 'updraft_updraftvault', 'updraft_dropbox', 'updraft_s3', 'updraft_cloudfiles', 'updraft_googledrive', 'updraft_onedrive', 'updraft_ftp', 'updraft_azure', 'updraft_sftp', 'updraft_googlecloud', 'updraft_backblaze', 'updraft_webdav', 'updraft_s3generic', 'updraft_pcloud', 'updraft_openstack', 'updraft_dreamobjects', 'updraft_central_localkeys', 'updraft_migrator_localkeys', 'updraft_remotesites', 'updraft_encryptionphrase' );
$miss    = array();
foreach ( array_merge( array( 'amelia_settings' ), $up_rows ) as $o ) {
	if ( ! Minn_Admin_DB::is_credential_option( $o ) || ! Minn_Admin_DB::redacts_cell( $wpdb->options, 'option_value', array( 'option_name' => $o, 'option_value' => 'x' ) ) ) {
		$miss[] = $o;
	}
}
$check( 'S3 Amelia settings and every UpdraftPlus storage and key row are redacted whole in the browser and named credential rows', ! $miss, implode( ', ', $miss ) );
$check( 'S3 control: UpdraftPlus rows holding no secret (history, last backup, email destination) and Ninja Forms settings stay readable', ! Minn_Admin_DB::is_credential_option( 'updraft_backup_history' ) && ! Minn_Admin_DB::is_credential_option( 'updraft_last_backup' ) && ! Minn_Admin_DB::is_credential_option( 'updraft_email' ) && ! Minn_Admin_DB::is_credential_option( 'ninja_forms_settings' ) );
// The vendors' own shapes (Amelia's JSON settings; UpdraftPlus' per-instance storage settings).
$am    = array(
	'general'       => array( 'gMapApiKey' => '', 'googleRecaptcha' => array( 'siteKey' => '6LcSite' . $tag . '1234abcd', 'secret' => '6LcSecr' . $tag . '5678efgh' ) ),
	'notifications' => array( 'mailgunApiKey' => 'key-' . $tag . '0123456789abcdef', 'smtpPassword' => 'Smtp' . $tag . '4321pass', 'whatsAppAccessToken' => 'EAAG' . $tag . '998877aa' ),
	'googleCalendar' => array( 'clientID' => 'gcal-client', 'clientSecret' => 'GOCSPX-' . $tag . '1122Bb33' ),
	'payments'      => array( 'stripe' => array( 'livePublishableKey' => 'pk_live_' . $tag . '51Pub9x', 'liveSecretKey' => 'sk_live_' . $tag . '51Sec9x' ), 'payPal' => array( 'liveApiClientId' => 'pp-client', 'liveApiSecret' => 'EPp' . $tag . '7766Secret5' ) ),
	'roles'         => array( 'customerCabinet' => array( 'headerJwtSecret' => 'Jw' . $tag . '12ab34cd56', 'tokenValidTime' => 1209600 ) ),
);
$found = array_map( 'strval', array_keys( Minn_Admin_DB::credential_strings( wp_json_encode( $am ) ) ) );
$want  = array( $am['general']['googleRecaptcha']['secret'], $am['notifications']['mailgunApiKey'], $am['notifications']['smtpPassword'], $am['notifications']['whatsAppAccessToken'], $am['googleCalendar']['clientSecret'], $am['payments']['stripe']['liveSecretKey'], $am['payments']['payPal']['liveApiSecret'], $am['roles']['customerCabinet']['headerJwtSecret'] );
$check( "S3 Amelia: the row's calendar, mail, WhatsApp, payment and cabinet JWT secrets become the fence's needles, its publishable and site keys do not", ! array_diff( $want, $found ) && ! in_array( $am['payments']['stripe']['livePublishableKey'], $found, true ) && ! in_array( $am['general']['googleRecaptcha']['siteKey'], $found, true ), wp_json_encode( $found ) );
$ud    = array( 'version' => 1, 'settings' => array( 's-' . md5( $tag ) => array( 'appkey' => '', 'secret' => '', 'tk_access_token' => 'sl.' . $tag . 'B1x2Y3z4AccessTok9', 'folder' => 'site-backups-2026' ) ) );
$found = array_map( 'strval', array_keys( Minn_Admin_DB::credential_strings( serialize( $ud ) ) ) );
$check( "S3 UpdraftPlus: a storage row's token becomes a needle, its folder does not", in_array( 'sl.' . $tag . 'B1x2Y3z4AccessTok9', $found, true ) || in_array( $tag . 'B1x2Y3z4AccessTok9', $found, true ), wp_json_encode( $found ) );
foreach ( array( 'amelia_settings', 'updraft_dropbox' ) as $o ) {
	$oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $o ) );
	if ( ! $oid ) {
		$skip( "S3 {$o}: no such row here" );
		continue;
	}
	list( $st, $d ) = $send( 'GET', '/minn-admin/v1/db/row', array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $oid ) ) ) );
	$cell           = null;
	foreach ( (array) ( $d['columns'] ?? array() ) as $i => $c ) {
		if ( 'option_value' === ( $c['name'] ?? '' ) ) {
			$cell = $d['cells'][ $i ] ?? null;
		}
	}
	$check( "S3 {$o}: the site's row opens redacted in the browser ({$st})", 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ), is_array( $cell ) ? 'not redacted' : wp_json_encode( $cell ) );
}
// Amelia's generated cabinet JWT secret, read from the site's own row (never printed).
$forget();
$live = json_decode( (string) get_option( 'amelia_settings', '' ), true );
$jwt  = is_array( $live ) ? (string) ( $live['roles']['customerCabinet']['headerJwtSecret'] ?? '' ) : '';
if ( strlen( $jwt ) < 16 || preg_match_all( '/\d/', $jwt ) < 3 || preg_match_all( '/[A-Za-z]/', $jwt ) < 3 ) {
	$skip( "S3 Amelia: this site's cabinet JWT secret is absent or too plain to count as a key" );
} else {
	$check( "S3 Amelia: the site's customer cabinet JWT secret is one of the fence's credentials", in_array( $jwt, Minn_Admin_DB::egress_credentials(), true ) );
}
$n = count( Minn_Admin_DB::egress_credentials() );
$check( "S3 the fence's credential list stays bounded on this site ({$n} credentials)", $n > 0 && $n < 500 );

$pass = count( array_filter( $results ) );
printf( "\n%d/%d passed\n", $pass, count( $results ) );
if ( $pass !== count( $results ) ) {
	exit( 1 );
}
