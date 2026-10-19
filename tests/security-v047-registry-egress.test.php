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

$pass = count( array_filter( $results ) );
printf( "\n%d/%d passed\n", $pass, count( $results ) );
if ( $pass !== count( $results ) ) {
	exit( 1 );
}
