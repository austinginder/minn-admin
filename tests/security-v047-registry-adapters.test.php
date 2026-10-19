<?php
/**
 * Regressions for the v0.46.0 shipped-zip registry scan, adapters lane.
 *
 *  - 04-2 WP Job Manager panel: Featured and Listing Expiry were drawn for a
 *    listing editor WPJM's own box hides them from (their auth_edit_callback
 *    asks manage_job_listings), and a save of either was dropped without a
 *    word while the post answered 200.
 *  - 04-3 Perfmatters settings: a field Perfmatters prints raw, sent by a
 *    caller without unfiltered_html, was skipped and the tab answered
 *    "Settings saved", half-applying a mixed save.
 *  - 04-4 Gift cards: Adjust balance sent one absolute balance, set against
 *    whatever the card held when the request ran, so a redemption made while
 *    the dialog was open was credited back. It now takes a signed amount to
 *    add or take off; an absolute balance is taken only beside the balance
 *    the person saw, and refused with 409 when the card no longer holds it.
 *
 *  - S1 The Events Calendar panel (fix-delta review): a date it could not
 *    read, or a venue or organizer it refused, answered 400 after the event
 *    was stored, so a new event's editor never learned its id and every save
 *    made another draft.
 *  - S2 ACF, Meta Box, JetEngine and Seriously Simple Podcasting panels (fix-delta
 *    review): a value the panel refused kept the stored one but answered
 *    200 with nothing said, so the editor adopted it as saved.
 *  - R3 PowerPress panel and ACF options pages (from the fix-delta sweep): a
 *    refused media URL deleted the stored episode; PowerPress's own refusal
 *    came after the post was stored; details with no episode file, and an
 *    options-page value ACF refuses, were dropped under a success answer.
 *  - R4 (round 2 review): a typed address the URL rule refuses was written
 *    as a clear over the stored one (TEC website, SSP episode file, WP Job
 *    Manager file fields, the SEO canonical), and PowerPress's text and
 *    number fields stored what their cleaner made of a refused value.
 *  - R5 (final review): an emptied number box arrives as null, and the
 *    whole-number test refused it, so SSP and PowerPress episode and season
 *    numbers could not be cleared.
 *
 * Each section calls what the route calls, as the role the finding named,
 * with a control where the fix could over-block. Fixtures (users, a listing,
 * gift cards, the Perfmatters option) are put back at the end however the
 * run ends. The gift card section runs for every gift card plugin loaded
 * (PW Gift Cards, WooCommerce Gift Cards, YITH) and SKIPs the others.
 *
 * Run: MINN_SWAP_ADD=powerpress/powerpress.php wp eval-file <ABSOLUTE path>/tests/security-v047-registry-adapters.test.php --user=admin --path=<site> --require=<ABSOLUTE path>/tests/lib/plugin-swap.php
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
$call    = static function ( $method, $route, $body = null, $query = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $query as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
$as      = static function ( $uid, callable $fn ) {
	$was = get_current_user_id();
	wp_set_current_user( $uid );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $was );
	}
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
// The minn_field_errors row for one panel field, or null.
$field_err = static function ( $data, $panel, $key ) {
	foreach ( (array) ( is_array( $data ) ? ( $data['minn_field_errors'] ?? array() ) : array() ) as $row ) {
		if ( ( $row['field'] ?? '' ) === $panel && (string) ( $row['key'] ?? '' ) === (string) $key ) {
			return $row;
		}
	}
	return null;
};
$admin = get_current_user_id();
$tag   = strtolower( wp_generate_password( 6, false ) );

/*
 * 04-2 WP Job Manager. A listing editor holding the listing capabilities but
 * not manage_job_listings: WPJM's metabox leaves Featured and Listing Expiry
 * out for them, and its meta auth refuses the write.
 */
( function () use ( $check, $skip, $call, $as, $once, $field_err, $admin, $tag ) {
	if ( ! function_exists( 'minn_admin_wpjm_active' ) || ! minn_admin_wpjm_active() ) {
		$skip( '04-2 WPJM: WP Job Manager is not active' );
		return;
	}
	$users   = array();
	$listing = 0;
	$clean   = $once( static function () use ( &$users, &$listing ) {
		if ( $listing ) {
			wp_delete_post( $listing, true );
		}
		foreach ( $users as $uid ) {
			wp_delete_user( $uid );
		}
	} );
	try {
		$uid = wp_insert_user( array( 'user_login' => "minn047_jobs_{$tag}", 'user_pass' => wp_generate_password( 24 ), 'user_email' => "minn047_jobs_{$tag}@example.test", 'role' => 'editor' ) );
		if ( is_wp_error( $uid ) ) {
			$check( '04-2 WPJM: a listing editor exists', false, $uid->get_error_message() );
			return;
		}
		$users[] = $uid;
		$u       = new WP_User( $uid );
		foreach ( array( 'edit_job_listings', 'edit_others_job_listings', 'edit_published_job_listings', 'publish_job_listings', 'read_job_listing' ) as $cap ) {
			$u->add_cap( $cap );
		}
		$listing = wp_insert_post( array( 'post_type' => 'job_listing', 'post_title' => 'Minn v047 job ' . $tag, 'post_status' => 'publish', 'post_author' => $admin ) );
		update_post_meta( $listing, '_job_expires', '2027-03-01' );
		update_post_meta( $listing, '_featured', 1 );
		update_post_meta( $listing, '_job_location', 'Old Town' );
		$check( '04-2 WPJM: the editor can edit the listing but cannot manage listings', $listing && user_can( $uid, 'edit_post', $listing ) && ! user_can( $uid, 'manage_job_listings' ) );

		$names = static function ( $who ) use ( $as, $call, $listing ) {
			list( $st, $d ) = $as( $who, static function () use ( $call, $listing ) {
				return $call( 'GET', '/minn-admin/v1/wpjm/fields', null, array( 'post_id' => $listing, 'post_type' => 'job-listings' ) );
			} );
			$out = array();
			foreach ( (array) ( $d['groups'] ?? array() ) as $g ) {
				foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
					$out[] = (string) ( $f['name'] ?? '' );
				}
			}
			return array( $st, $out );
		};
		list( $st, $editor_fields ) = $names( $uid );
		$check( '04-2 WPJM: the panel draws the editor\'s ordinary fields', 200 === $st && in_array( '_job_location', $editor_fields, true ), $st . ' ' . wp_json_encode( $editor_fields ) );
		$check( '04-2 WPJM: the panel leaves Featured and Listing Expiry out for the editor', ! in_array( '_featured', $editor_fields, true ) && ! in_array( '_job_expires', $editor_fields, true ), wp_json_encode( $editor_fields ) );
		list( , $admin_fields ) = $names( $admin );
		$check( '04-2 WPJM control: an administrator still gets both', in_array( '_featured', $admin_fields, true ) && in_array( '_job_expires', $admin_fields, true ), wp_json_encode( $admin_fields ) );

		list( $st, $d ) = $as( $uid, static function () use ( $call, $listing ) {
			return $call( 'GET', '/wp/v2/job-listings/' . $listing, null, array( 'context' => 'edit' ) );
		} );
		$vals = (array) ( $d['minn_wpjm'] ?? array() );
		$check( '04-2 WPJM: the editor\'s values leave both out too', 200 === $st && array_key_exists( '_job_location', $vals ) && ! array_key_exists( '_featured', $vals ) && ! array_key_exists( '_job_expires', $vals ), $st . ' ' . wp_json_encode( array_keys( $vals ) ) );

		list( $st, $d ) = $as( $uid, static function () use ( $call, $listing ) {
			return $call( 'POST', '/wp/v2/job-listings/' . $listing, array( 'minn_wpjm' => array( '_job_expires' => '2027-12-31', '_featured' => false, '_job_location' => 'New Town' ) ) );
		} );
		$check( '04-2 WPJM: the save answers 200 with the listing', 200 === $st && (int) ( $d['id'] ?? 0 ) === (int) $listing, $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		$check( '04-2 WPJM: the answer names Listing Expiry as not saved', (bool) $field_err( $d, 'minn_wpjm', '_job_expires' ), wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( '04-2 WPJM: and Featured', (bool) $field_err( $d, 'minn_wpjm', '_featured' ), wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( '04-2 WPJM: and not the location it stored', ! $field_err( $d, 'minn_wpjm', '_job_location' ), wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( '04-2 WPJM: Expiry and Featured stay as stored, the location is written', '2027-03-01' === get_post_meta( $listing, '_job_expires', true ) && 1 === (int) get_post_meta( $listing, '_featured', true ) && 'New Town' === get_post_meta( $listing, '_job_location', true ), wp_json_encode( array( get_post_meta( $listing, '_job_expires', true ), get_post_meta( $listing, '_featured', true ), get_post_meta( $listing, '_job_location', true ) ) ) );

		list( $st, $d ) = $call( 'POST', '/wp/v2/job-listings/' . $listing, array( 'minn_wpjm' => array( '_job_expires' => '2027-12-31' ) ) );
		$check( '04-2 WPJM control: an administrator\'s Expiry is stored, with no refusal', 200 === $st && '2027-12-31' === get_post_meta( $listing, '_job_expires', true ) && empty( $d['minn_field_errors'] ), $st . ' ' . get_post_meta( $listing, '_job_expires', true ) . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * 04-3 Perfmatters. An administrator without unfiltered_html (a subsite
 * administrator, or any under DISALLOW_UNFILTERED_HTML) saves the CDN tab
 * with the CDN URL and an ordinary toggle.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_perfmatters_save' ) || ! function_exists( 'perfmatters_settings' ) || ! minn_admin_perfmatters_can() ) {
		$skip( '04-3 Perfmatters: not active, or this user cannot reach its settings' );
		return;
	}
	global $wpdb;
	// The stored row itself, put back byte for byte: update_option() would
	// run it through Perfmatters' sanitizer again.
	$had    = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", 'perfmatters_options' ) );
	$strip  = false;
	$filter = static function ( $caps, $cap ) use ( &$strip ) {
		return $strip && 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
	};
	add_filter( 'map_meta_cap', $filter, 10, 2 );
	$clean = $once( static function () use ( $had, $filter ) {
		global $wpdb;
		remove_filter( 'map_meta_cap', $filter, 10 );
		if ( $had ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $had->option_value, 'autoload' => $had->autoload ), array( 'option_name' => 'perfmatters_options' ) );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'perfmatters_options' ) );
		}
		wp_cache_delete( 'perfmatters_options', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	} );
	try {
		list( $st, $tab ) = $call( 'GET', '/minn-admin/v1/perfmatters/settings/cdn' );
		$values = (array) ( $tab['values'] ?? array() );
		$url    = 'perfmatters_options:cdn:cdn_url';
		$toggle = '';
		foreach ( (array) ( $tab['groups'] ?? array() ) as $g ) {
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				if ( '' === $toggle && 'toggle' === ( $f['type'] ?? '' ) ) {
					$toggle = (string) $f['key'];
				}
			}
		}
		if ( 200 !== $st || ! array_key_exists( $url, $values ) || '' === $toggle ) {
			$skip( '04-3 Perfmatters: the CDN tab has no CDN URL and toggle here (' . $st . ')' );
			return;
		}
		$was_on  = ! empty( $values[ $toggle ] );
		$was_url = (string) $values[ $url ];
		$new_url = 'https://cdn-' . wp_generate_password( 6, false ) . '.example.test';
		$stored  = static function () use ( $url, $toggle ) {
			wp_cache_delete( 'perfmatters_options', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$o = (array) get_option( 'perfmatters_options', array() );
			list( , $sec, $id ) = array_pad( explode( ':', $toggle ), 3, '' );
			$on = '' === $sec ? ! empty( $o[ $id ] ) : ! empty( $o[ $sec ][ $id ] );
			return array( 'url' => (string) ( $o['cdn']['cdn_url'] ?? '' ), 'on' => $on );
		};

		$strip = true;
		$check( '04-3 Perfmatters: the caller holds manage_options but not unfiltered_html', current_user_can( 'manage_options' ) && ! current_user_can( 'unfiltered_html' ) && minn_admin_perfmatters_can() );
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/perfmatters/settings/cdn', array( 'values' => array( $toggle => ! $was_on, $url => $new_url ) ) );
		$check( '04-3 Perfmatters: a save with the CDN URL is refused with 403', 403 === $st, $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		$check( '04-3 Perfmatters: the refusal names the field, and only that one', 403 === $st && array( $url ) === (array) ( $d['data']['fields'] ?? null ), wp_json_encode( $d ) );
		$after = $stored();
		$check( '04-3 Perfmatters: nothing is written, not even the toggle beside it', $after['url'] === $was_url && $after['on'] === $was_on, wp_json_encode( $after ) );

		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/perfmatters/settings/cdn', array( 'values' => array( $toggle => ! $was_on, $url => $was_url ) ) );
		$after = $stored();
		$check( '04-3 Perfmatters control: the CDN URL sent back as loaded does not block the toggle', 200 === $st && $after['on'] === ! $was_on && $after['url'] === $was_url, $st . ' ' . wp_json_encode( $after ) );

		$strip = false;
		list( $st ) = $call( 'POST', '/minn-admin/v1/perfmatters/settings/cdn', array( 'values' => array( $url => $new_url ) ) );
		$after = $stored();
		$check( '04-3 Perfmatters control: a caller with unfiltered_html saves the CDN URL', 200 === $st && $after['url'] === $new_url, $st . ' ' . wp_json_encode( $after ) );
	} finally {
		$clean();
	}
} )();

/*
 * 04-4 Gift cards. A card worth 50; a redemption of 20 lands while the
 * Adjust balance dialog is open; the person meant to take 10 off.
 */
$vendors = array(
	'pwgc' => array(
		'name'    => 'PW Gift Cards',
		'active'  => static function () {
			return function_exists( 'minn_admin_pwgc_active' ) && minn_admin_pwgc_active();
		},
		'create'  => array( 'amount' => 50 ),
		'balance' => static function ( $id ) {
			$c = minn_admin_pwgc_load( $id );
			return is_wp_error( $c ) ? null : (float) $c->get_balance( true );
		},
		'spend'   => static function ( $id, $amount ) {
			$c = minn_admin_pwgc_load( $id );
			if ( ! is_wp_error( $c ) ) {
				$c->debit( -1 * $amount, 'Minn v047 suite redemption' );
			}
		},
		'delete'  => static function ( $id ) {
			$c = minn_admin_pwgc_load( $id );
			if ( ! is_wp_error( $c ) ) {
				$c->delete();
			}
		},
	),
	'wcgc' => array(
		'name'    => 'WooCommerce Gift Cards',
		'active'  => static function () {
			return function_exists( 'minn_admin_wcgc_active' ) && minn_admin_wcgc_active();
		},
		'create'  => array( 'amount' => 50, 'recipient' => "minn047_gc_{$tag}@example.test", 'send' => 'no' ),
		'balance' => static function ( $id ) {
			$c = wc_gc_get_gift_card( (int) $id );
			return $c && $c->get_id() ? (float) WC_GC()->db->giftcards->get( (int) $id )->get_balance() : null;
		},
		'spend'   => static function ( $id, $amount ) {
			WC_GC()->db->giftcards->debit_giftcard( (int) $id, $amount );
		},
		'delete'  => static function ( $id ) {
			global $wpdb;
			WC_GC()->db->giftcards->delete( (int) $id );
			$wpdb->delete( $wpdb->prefix . 'woocommerce_gc_activity', array( 'gc_id' => (int) $id ) );
		},
	),
	'ywgc' => array(
		'name'    => 'YITH Gift Cards',
		'active'  => static function () {
			return function_exists( 'minn_admin_ywgc_active' ) && minn_admin_ywgc_active();
		},
		'create'  => array( 'amount' => 50, 'send' => 'no' ),
		'balance' => static function ( $id ) {
			wp_cache_delete( (int) $id, 'post_meta' );
			return '' === get_post_meta( $id, '_ywgc_balance_total', true ) ? null : (float) get_post_meta( $id, '_ywgc_balance_total', true );
		},
		'spend'   => static function ( $id, $amount ) {
			$gc = minn_admin_ywgc_load( $id );
			$gc->update_balance( (float) $gc->get_balance() - $amount );
		},
		'delete'  => static function ( $id ) {
			wp_delete_post( (int) $id, true );
		},
	),
);
$descriptor = static function ( $prefix ) {
	foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
		foreach ( (array) ( $s['collection']['actions'] ?? array() ) as $a ) {
			if ( isset( $a['route'] ) && false !== strpos( (string) $a['route'], $prefix . '/gift-cards/' ) && '/balance' === substr( (string) $a['route'], -8 ) ) {
				return $a;
			}
		}
	}
	return null;
};
foreach ( $vendors as $prefix => $v ) {
	( function () use ( $prefix, $v, $check, $skip, $call, $once, $descriptor ) {
		$label = '04-4 ' . $v['name'];
		if ( ! call_user_func( $v['active'] ) ) {
			$skip( $label . ': not active' );
			return;
		}
		$cards = array();
		$clean = $once( static function () use ( &$cards, $v ) {
			foreach ( $cards as $id ) {
				call_user_func( $v['delete'], $id );
			}
		} );
		try {
			$card = static function () use ( $call, $prefix, $v, &$cards ) {
				list( $st, $d ) = $call( 'POST', '/minn-admin/v1/' . $prefix . '/gift-cards', $v['create'] );
				$id = 200 === $st ? (int) ( $d['id'] ?? 0 ) : 0;
				if ( $id ) {
					$cards[] = $id;
				}
				return array( $id, $st, $d );
			};
			$balance = static function ( $id ) use ( $v ) {
				return call_user_func( $v['balance'], $id );
			};
			$post = static function ( $id, $body ) use ( $call, $prefix ) {
				return $call( 'POST', '/minn-admin/v1/' . $prefix . '/gift-cards/' . $id . '/balance', $body );
			};

			$a      = $descriptor( $prefix );
			$fields = $a ? array_map( static function ( $f ) {
				return (string) ( $f['key'] ?? '' );
			}, (array) ( $a['fields'] ?? array() ) ) : array();
			$check( $label . ': the dialog asks for an amount to add or take off, not a new balance', array( 'adjust' ) === $fields, wp_json_encode( $fields ) );

			list( $id, $st, $d ) = $card();
			if ( ! $id ) {
				$skip( $label . ': could not issue a fixture card (' . $st . ' ' . wp_json_encode( $d['message'] ?? $d['code'] ?? '' ) . ')' );
				return;
			}
			$check( $label . ': a card worth 50', 50.0 === $balance( $id ), wp_json_encode( $balance( $id ) ) );
			call_user_func( $v['spend'], $id, 20 );
			$check( $label . ': a redemption of 20 lands while the dialog is open', 30.0 === $balance( $id ), wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'adjust' => -10 ) );
			$check( $label . ': taking 10 off answers 200', 200 === $st, $st . ' ' . wp_json_encode( $d ) );
			$check( $label . ': and leaves the card at 20, the redemption kept', 20.0 === $balance( $id ), wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'balance' => 40 ) );
			$check( $label . ': a bare absolute balance is refused', 400 === $st && 20.0 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'balance' => 40, 'balance_seen' => 50 ) );
			$check( $label . ': an absolute balance set against one the card no longer holds answers 409', 409 === $st && 20.0 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'balance' => 25, 'balance_seen' => 20 ) );
			$check( $label . ' control: an absolute balance set against the one it holds is stored', 200 === $st && 25.0 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'adjust' => 5.5 ) );
			$check( $label . ' control: adding 5.50 is stored', 200 === $st && 30.5 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'adjust' => -100 ) );
			$check( $label . ': taking off more than it holds is refused, not clamped', 400 === $st && 30.5 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'adjust' => 0 ) );
			$check( $label . ': an amount of nothing is refused', 400 === $st && 30.5 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'adjust' => '1e20' ) );
			$check( $label . ': scientific notation is refused', 400 === $st && 30.5 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );

			list( $st, $d ) = $post( $id, array( 'adjust' => -30.5 ) );
			$check( $label . ' control: the card can be emptied', 200 === $st && 0.0 === $balance( $id ), $st . ' ' . wp_json_encode( $balance( $id ) ) );
		} finally {
			$clean();
		}
	} )();
}

/*
 * S1 The Events Calendar (fix-delta review). A new event's first save with a
 * Starts the panel cannot read: TEC's field used to answer 400 after
 * WordPress had stored the event, so the editor never learned its id and
 * each retry made another event draft. Venue refusals took the same path.
 */
( function () use ( $check, $skip, $call, $once, $field_err, $admin, $tag ) {
	if ( ! post_type_exists( 'tribe_events' ) || ! class_exists( 'Tribe__Events__API' ) || ! function_exists( 'minn_admin_tec_panel_values' ) ) {
		$skip( 'S1 TEC: The Events Calendar is not active' );
		return;
	}
	$title = 'Minn v047 tec ' . $tag;
	$page  = 0;
	// By title in the table itself: TEC's query filters leave an event with
	// no stored dates out of get_posts(), and such a draft is the point here.
	$ids   = static function () use ( $title ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tribe_events' AND post_title = %s", $title ) ) );
	};
	$clean = $once( static function () use ( $ids, &$page ) {
		foreach ( $ids() as $pid ) {
			wp_delete_post( $pid, true );
		}
		if ( $page ) {
			wp_delete_post( $page, true );
		}
	} );
	try {
		$drafts = static function () use ( $ids ) {
			return count( $ids() );
		};
		$tec     = array( 'start' => '2026-13-01 10:00' );
		$answers = array( $call( 'POST', '/wp/v2/tribe_events', array( 'title' => $title, 'status' => 'draft', 'minn_tec' => $tec ) ) );
		$id      = (int) ( $answers[0][1]['id'] ?? 0 );
		for ( $i = 0; $i < 2; $i++ ) {
			$answers[] = $id ? $call( 'POST', '/wp/v2/tribe_events/' . $id, array( 'title' => $title, 'minn_tec' => $tec ) ) : $call( 'POST', '/wp/v2/tribe_events', array( 'title' => $title, 'status' => 'draft', 'minn_tec' => $tec ) );
		}
		$check( 'S1 TEC: a new event with an unreadable start answers 201 with its id', 201 === $answers[0][0] && $id > 0, $answers[0][0] . ' ' . wp_json_encode( $answers[0][1]['code'] ?? '' ) );
		$check( 'S1 TEC: the answer names Starts as not saved', (bool) $field_err( $answers[0][1], 'minn_tec', 'start' ), wp_json_encode( $answers[0][1]['minn_field_errors'] ?? null ) );
		$check( 'S1 TEC: the retries update that event, so one draft exists', 1 === $drafts() && 200 === $answers[1][0] && 200 === $answers[2][0], $drafts() . ' drafts; ' . $answers[1][0] . '/' . $answers[2][0] );

		if ( ! $id ) {
			return;
		}
		list( $st, $d ) = $call( 'POST', '/wp/v2/tribe_events/' . $id, array( 'minn_tec' => array( 'start' => '2026-11-02 10:00', 'end' => '2026-11-02 12:00' ) ) );
		$check( 'S1 TEC control: readable dates are stored with no refusal', 200 === $st && empty( $d['minn_field_errors'] ) && 0 === strpos( (string) get_post_meta( $id, '_EventStartDate', true ), '2026-11-02 10:00' ), $st . ' ' . get_post_meta( $id, '_EventStartDate', true ) . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );

		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Minn v047 not a venue ' . $tag, 'post_author' => $admin ) );
		$was  = get_post_meta( $id, '_EventVenueID', true );
		list( $st, $d ) = $call( 'POST', '/wp/v2/tribe_events/' . $id, array( 'minn_tec' => array( 'venue' => array( 'value' => (string) $page, 'label' => 'x' ), 'end' => '2026-11-02 13:00' ) ) );
		$check( 'S1 TEC: a page sent as the venue answers 200 and names Venue', 200 === $st && $field_err( $d, 'minn_tec', 'venue' ) && ! $field_err( $d, 'minn_tec', 'end' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? $d['code'] ?? null ) );
		$check( 'S1 TEC: the venue stays as stored and the new end is written', (string) $was === (string) get_post_meta( $id, '_EventVenueID', true ) && 0 === strpos( (string) get_post_meta( $id, '_EventEndDate', true ), '2026-11-02 13:00' ), wp_json_encode( array( get_post_meta( $id, '_EventVenueID', true ), get_post_meta( $id, '_EventEndDate', true ) ) ) );

		list( $st, $d ) = $call( 'POST', '/wp/v2/tribe_events/' . $id, array( 'minn_tec' => array( 'start' => '2026-11-03 10:00', 'end' => '2026-11-02 09:00' ) ) );
		// TEC moves an end before the start itself (its own form does too), so
		// this is no refusal; what matters is that the answer is the event.
		$check( 'S1 TEC: an end before the start still answers 200 with the event', 200 === $st && (int) ( $d['id'] ?? 0 ) === $id, $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? $d['code'] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * S2 Panels that refuse a value (fix-delta review). Each kept the stored
 * value, as it should, but answered 200 with nothing said, so the editor
 * adopted what was typed as saved.
 */
$s2_post = static function ( $label ) use ( $admin, $tag ) {
	return (int) wp_insert_post( array( 'post_title' => 'Minn v047 ' . $label . ' ' . $tag, 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
};

( function () use ( $check, $skip, $call, $once, $field_err, $tag, $s2_post ) {
	if ( ! function_exists( 'acf_add_local_field_group' ) || ! function_exists( 'minn_admin_acf_write_values' ) ) {
		$skip( 'S2 ACF: not active' );
		return;
	}
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
		if ( function_exists( 'acf_remove_local_field_group' ) ) {
			acf_remove_local_field_group( 'group_minn_v047_s2' );
		}
	} );
	try {
		acf_add_local_field_group( array(
			'key'      => 'group_minn_v047_s2',
			'title'    => 'Minn v047 refusal probe',
			'fields'   => array(
				array( 'key' => 'field_minn_v047_url', 'name' => 'minn_v047_url', 'label' => 'Probe link', 'type' => 'url' ),
				array( 'key' => 'field_minn_v047_day', 'name' => 'minn_v047_day', 'label' => 'Probe day', 'type' => 'date_picker' ),
				array( 'key' => 'field_minn_v047_txt', 'name' => 'minn_v047_txt', 'label' => 'Probe text', 'type' => 'text' ),
			),
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
		) );
		$pid = $s2_post( 'acf' );
		update_field( 'field_minn_v047_url', 'https://example.com/kept', $pid );
		update_field( 'field_minn_v047_day', '20261101', $pid );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_acf' => array( 'minn_v047_url' => 'javascript:alert(1)', 'minn_v047_day' => 'someday', 'minn_v047_txt' => 'Written ' . $tag ) ) );
		$check( 'S2 ACF: the save answers 200 and names the link and the date', 200 === $st && $field_err( $d, 'minn_acf', 'minn_v047_url' ) && $field_err( $d, 'minn_acf', 'minn_v047_day' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'S2 ACF: and not the text it stored', ! $field_err( $d, 'minn_acf', 'minn_v047_txt' ), wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'S2 ACF: what is stored is unchanged: link and date kept, text written', 'https://example.com/kept' === get_post_meta( $pid, 'minn_v047_url', true ) && '20261101' === get_post_meta( $pid, 'minn_v047_day', true ) && 'Written ' . $tag === get_post_meta( $pid, 'minn_v047_txt', true ), wp_json_encode( array( get_post_meta( $pid, 'minn_v047_url', true ), get_post_meta( $pid, 'minn_v047_day', true ), get_post_meta( $pid, 'minn_v047_txt', true ) ) ) );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_acf' => array( 'minn_v047_url' => '', 'minn_v047_day' => '' ) ) );
		$check( 'S2 ACF control: clearing is not a refusal', 200 === $st && empty( $d['minn_field_errors'] ) && '' === get_post_meta( $pid, 'minn_v047_url', true ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once, $field_err, $tag, $s2_post ) {
	if ( ! function_exists( 'rwmb_get_registry' ) || ! function_exists( 'minn_admin_meta_box_write_values' ) ) {
		$skip( 'S2 Meta Box: not active' );
		return;
	}
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		$box = rwmb_get_registry( 'meta_box' )->make( array(
			'id'         => 'minn_v047_mb',
			'title'      => 'Minn v047 refusal probe',
			'post_types' => array( 'post' ),
			'fields'     => array(
				array( 'id' => 'minn_v047_mb_url', 'name' => 'Probe link', 'type' => 'url' ),
				array( 'id' => 'minn_v047_mb_txt', 'name' => 'Probe text', 'type' => 'text' ),
			),
		) );
		// A box made after init never reaches the field registry the setter reads.
		if ( method_exists( $box, 'register_fields' ) ) {
			$box->register_fields();
		}
		$pid = $s2_post( 'mb' );
		update_post_meta( $pid, 'minn_v047_mb_url', 'https://example.com/kept' );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_meta_box' => array( 'minn_v047_mb_url' => 'javascript:alert(1)', 'minn_v047_mb_txt' => 'Written' ) ) );
		$check( 'S2 Meta Box: a javascript: link answers 200 and is named', 200 === $st && $field_err( $d, 'minn_meta_box', 'minn_v047_mb_url' ) && ! $field_err( $d, 'minn_meta_box', 'minn_v047_mb_txt' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'S2 Meta Box: the link stays as stored, the text is written', 'https://example.com/kept' === get_post_meta( $pid, 'minn_v047_mb_url', true ) && 'Written' === get_post_meta( $pid, 'minn_v047_mb_txt', true ), wp_json_encode( array( get_post_meta( $pid, 'minn_v047_mb_url', true ), get_post_meta( $pid, 'minn_v047_mb_txt', true ) ) ) );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once, $field_err, $tag, $s2_post ) {
	if ( ! function_exists( 'minn_admin_jet_write_values' ) || ! minn_admin_jet_fields_active() ) {
		$skip( 'S2 JetEngine: not active' );
		return;
	}
	global $wpdb;
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		$pid   = $s2_post( 'jet' );
		$map   = minn_admin_jet_field_map( $pid );
		$image = '';
		$pick  = '';
		$text  = '';
		foreach ( $map as $name => $f ) {
			if ( '' === $image && 'image' === ( $f['type'] ?? '' ) ) {
				$image = $name;
			} elseif ( '' === $pick && in_array( $f['type'] ?? '', array( 'select', 'radio' ), true ) ) {
				$pick = $name;
			} elseif ( '' === $text && 'text' === ( $f['type'] ?? '' ) ) {
				$text = $name;
			}
		}
		if ( '' === $image || '' === $text ) {
			$skip( 'S2 JetEngine: no picture and text field on posts here' );
			return;
		}
		$dead = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ) + 500000; // never an attachment
		$body = array( $image => (string) $dead, $text => 'Written ' . $tag );
		if ( '' !== $pick ) {
			$body[ $pick ] = 'minn-not-a-choice';
		}
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_jet' => $body ) );
		$check( 'S2 JetEngine: a picture that is no attachment answers 200 and is named', 200 === $st && $field_err( $d, 'minn_jet', $image ) && ! $field_err( $d, 'minn_jet', $text ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		if ( '' !== $pick ) {
			$check( 'S2 JetEngine: and an unknown choice', (bool) $field_err( $d, 'minn_jet', $pick ), wp_json_encode( $d['minn_field_errors'] ?? null ) );
		}
		$check( 'S2 JetEngine: nothing refused is stored, the text is written', '' === get_post_meta( $pid, $image, true ) && ( '' === $pick || '' === get_post_meta( $pid, $pick, true ) ) && 'Written ' . $tag === get_post_meta( $pid, $text, true ), wp_json_encode( array( get_post_meta( $pid, $image, true ), '' === $pick ? null : get_post_meta( $pid, $pick, true ), get_post_meta( $pid, $text, true ) ) ) );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once, $field_err, $tag, $admin ) {
	if ( ! function_exists( 'minn_admin_ssp_write_values' ) || ! minn_admin_ssp_active() || ! post_type_exists( 'podcast' ) ) {
		$skip( 'S2 SSP: not active' );
		return;
	}
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		$pid = (int) wp_insert_post( array( 'post_title' => 'Minn v047 ssp ' . $tag, 'post_status' => 'draft', 'post_type' => 'podcast', 'post_author' => $admin ) );
		update_post_meta( $pid, 'episode_type', 'audio' );
		list( $st, $d ) = $call( 'POST', '/wp/v2/podcast/' . $pid, array( 'minn_ssp' => array( 'episode_type' => 'hologram', 'itunes_title' => 'Written ' . $tag ) ) );
		$check( 'S2 SSP: an unknown episode type answers 200 and is named', 200 === $st && $field_err( $d, 'minn_ssp', 'episode_type' ) && ! $field_err( $d, 'minn_ssp', 'itunes_title' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'S2 SSP: the type stays as stored, the title is written', 'audio' === get_post_meta( $pid, 'episode_type', true ) && 'Written ' . $tag === get_post_meta( $pid, 'itunes_title', true ), wp_json_encode( array( get_post_meta( $pid, 'episode_type', true ), get_post_meta( $pid, 'itunes_title', true ) ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * R3 PowerPress panel (from the fix-delta sweep). A media URL esc_url_raw()
 * empties was read as a deliberate clear and deleted the stored episode; the
 * 403 for a user PowerPress keeps from episodes came after the post was
 * stored; and details typed before there was an episode file were dropped
 * without a word. PowerPress rests inactive on the lab: this section runs
 * under MINN_SWAP_ADD=powerpress/powerpress.php.
 */
( function () use ( $check, $skip, $call, $as, $once, $field_err, $admin, $tag ) {
	if ( ! function_exists( 'minn_admin_powerpress_active' ) || ! minn_admin_powerpress_active() ) {
		$skip( 'R3 PowerPress: not active (run with MINN_SWAP_ADD=powerpress/powerpress.php)' );
		return;
	}
	global $wpdb;
	$title   = 'Minn v047 pp ' . $tag;
	$users   = array();
	$general = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", 'powerpress_general' ) );
	$clean   = $once( static function () use ( $title, &$users, $general ) {
		global $wpdb;
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", $title ) ) as $pid ) {
			wp_delete_post( (int) $pid, true );
		}
		foreach ( $users as $uid ) {
			wp_delete_user( $uid );
		}
		if ( $general ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $general->option_value, 'autoload' => $general->autoload ), array( 'option_name' => 'powerpress_general' ) );
		} else {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'powerpress_general' ) );
		}
		wp_cache_delete( 'powerpress_general', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	} );
	try {
		$episode = "https://example.com/minn-v047.mp3\n4321\naudio/mpeg\n" . serialize( array( 'duration' => '01:02:03', 'set_duration' => 1 ) ); // phpcs:ignore -- their storage format
		$pid     = (int) wp_insert_post( array( 'post_title' => $title, 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
		update_post_meta( $pid, 'enclosure', wp_slash( $episode ) );

		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'url' => 'javascript:alert(1)', 'subtitle' => 'Kept apart ' . $tag ) ) );
		$enc = (string) get_post_meta( $pid, 'enclosure', true );
		$check( 'R3 PowerPress: a javascript: media URL keeps the stored episode', 0 === strpos( $enc, 'https://example.com/minn-v047.mp3' ) && false !== strpos( $enc, '01:02:03' ), wp_json_encode( $enc ) );
		$check( 'R3 PowerPress: and is named on the 200 answer', 200 === $st && $field_err( $d, 'minn_powerpress', 'url' ) && ! $field_err( $d, 'minn_powerpress', 'subtitle' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'R3 PowerPress: the subtitle beside it is stored', false !== strpos( $enc, 'Kept apart ' . $tag ), wp_json_encode( $enc ) );

		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'duration' => 'about an hour' ) ) );
		$check( 'R3 PowerPress: an unreadable duration is named and the stored one kept', 200 === $st && $field_err( $d, 'minn_powerpress', 'duration' ) && false !== strpos( (string) get_post_meta( $pid, 'enclosure', true ), '01:02:03' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );

		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'url' => '' ) ) );
		$check( 'R3 PowerPress control: an emptied media URL still removes the episode, with no refusal', 200 === $st && '' === get_post_meta( $pid, 'enclosure', true ) && empty( $d['minn_field_errors'] ), $st . ' ' . wp_json_encode( get_post_meta( $pid, 'enclosure', true ) ) );

		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'size' => '1234', 'duration' => '00:10:00' ) ) );
		$check( 'R3 PowerPress: size and duration with no episode file are named', 200 === $st && $field_err( $d, 'minn_powerpress', 'size' ) && $field_err( $d, 'minn_powerpress', 'duration' ) && '' === get_post_meta( $pid, 'enclosure', true ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );

		// PowerPress's Roles and Capabilities: episodes for edit_podcast only,
		// which PowerPress gives editors and up; an author writes posts
		// without it.
		$uid = wp_insert_user( array( 'user_login' => "minn047_pp_{$tag}", 'user_pass' => wp_generate_password( 24 ), 'user_email' => "minn047_pp_{$tag}@example.test", 'role' => 'author' ) );
		if ( is_wp_error( $uid ) || user_can( $uid, 'edit_podcast' ) ) {
			$check( 'R3 PowerPress: an author without edit_podcast exists', false, is_wp_error( $uid ) ? $uid->get_error_message() : 'the author role holds edit_podcast here' );
			return;
		}
		$users[] = $uid;
		$g       = get_option( 'powerpress_general', array() );
		$g       = is_array( $g ) ? $g : array();
		$g['use_caps'] = 1;
		update_option( 'powerpress_general', $g );
		$refused = $as( $uid, static function () use ( $call, $title ) {
			return array(
				'panel'  => function_exists( 'minn_admin_powerpress_can_podcast' ) ? minn_admin_powerpress_can_podcast() : null,
				'fields' => $call( 'GET', '/minn-admin/v1/powerpress/fields', null, array( 'post_type' => 'posts' ) ),
				'first'  => $call( 'POST', '/wp/v2/posts', array( 'title' => $title, 'status' => 'draft', 'minn_powerpress' => array( 'subtitle' => 'Not mine' ) ) ),
			);
		} );
		$check( 'R3 PowerPress control: the author is not offered the fields', false === $refused['panel'] && 403 === $refused['fields'][0], wp_json_encode( array( $refused['panel'], $refused['fields'][0] ) ) );
		$first = $refused['first'];
		$check( 'R3 PowerPress: a new post sent anyway answers 201 with its id', 201 === $first[0] && ! empty( $first[1]['id'] ), $first[0] . ' ' . wp_json_encode( $first[1]['code'] ?? '' ) );
		$check( 'R3 PowerPress: and names what PowerPress refused', (bool) $field_err( $first[1], 'minn_powerpress', 'subtitle' ), wp_json_encode( $first[1]['minn_field_errors'] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * R3 ACF options pages (from the fix-delta sweep). A value the parser
 * refuses was skipped while the rest stored and the view said "Settings
 * saved". The whole save is now refused before anything is written.
 */
( function () use ( $check, $skip, $call, $once, $tag ) {
	if ( ! function_exists( 'acf_add_options_page' ) || ! function_exists( 'minn_admin_acf_options_save' ) ) {
		$skip( 'R3 ACF options: ACF PRO options pages are not available' );
		return;
	}
	$clean = $once( static function () {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'minn\\_v047\\_opts\\_%' OR option_name LIKE '\\_minn\\_v047\\_opts\\_%'" );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( function_exists( 'acf_remove_local_field_group' ) ) {
			acf_remove_local_field_group( 'group_minn_v047_opts' );
		}
	} );
	try {
		acf_add_options_page( array( 'page_title' => 'Minn v047 opts', 'menu_title' => 'Minn v047 opts', 'menu_slug' => 'minn-v047-opts', 'post_id' => 'minn_v047_opts', 'capability' => 'edit_posts', 'redirect' => false ) );
		acf_add_local_field_group( array(
			'key'      => 'group_minn_v047_opts',
			'title'    => 'Minn v047 opts',
			'fields'   => array(
				array( 'key' => 'field_minn_v047_olink', 'name' => 'minn_v047_olink', 'label' => 'Shop link', 'type' => 'url' ),
				array( 'key' => 'field_minn_v047_otext', 'name' => 'minn_v047_otext', 'label' => 'Tagline', 'type' => 'text' ),
			),
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'minn-v047-opts' ) ) ),
		) );
		update_field( 'field_minn_v047_olink', 'https://example.com/shop', 'minn_v047_opts' );
		update_field( 'field_minn_v047_otext', 'Old', 'minn_v047_opts' );
		$raw = static function ( $name ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'minn_v047_opts_' . $name, 'options' );
			return get_option( 'minn_v047_opts_' . $name );
		};
		$route = '/minn-admin/v1/acf/options/minn-v047-opts/tab-0';
		list( $st, $d ) = $call( 'POST', $route, array( 'values' => array( 'field_minn_v047_olink' => 'javascript:alert(1)', 'field_minn_v047_otext' => 'New ' . $tag ) ) );
		$check( 'R3 ACF options: a refused link refuses the save with 400', 400 === $st, $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
		$check( 'R3 ACF options: naming the link and only the link', array( 'field_minn_v047_olink' ) === array_column( (array) ( $d['data']['fields'] ?? array() ), 'key' ) && false !== strpos( (string) ( $d['message'] ?? '' ), 'Shop link' ), wp_json_encode( $d ) );
		$check( 'R3 ACF options: nothing is written, not even the text beside it', 'https://example.com/shop' === $raw( 'minn_v047_olink' ) && 'Old' === $raw( 'minn_v047_otext' ), wp_json_encode( array( $raw( 'minn_v047_olink' ), $raw( 'minn_v047_otext' ) ) ) );
		list( $st ) = $call( 'POST', $route, array( 'values' => array( 'field_minn_v047_olink' => 'https://example.com/new', 'field_minn_v047_otext' => 'New ' . $tag ) ) );
		$check( 'R3 ACF options control: a clean save stores both', 200 === $st && 'https://example.com/new' === $raw( 'minn_v047_olink' ) && 'New ' . $tag === $raw( 'minn_v047_otext' ), $st . ' ' . wp_json_encode( array( $raw( 'minn_v047_olink' ), $raw( 'minn_v047_otext' ) ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * R4 (round 2 review). A typed address the house URL rule refuses was
 * cleaned to '' and written as a clear over the stored one, with nothing
 * said: TEC's event website, SSP's episode file, WP Job Manager's file
 * fields and the SEO canonical. PowerPress's text and number fields had the
 * same shape (a value the cleaner empties written as an unset, '1.5' stored
 * as 15), and a non-scalar media URL deleted the episode. Each now keeps
 * what is stored and is named; only a truly empty value clears.
 */
$r4_bad = 'javascript:alert(1)';
( function () use ( $check, $skip, $call, $once, $field_err, $admin, $tag, $r4_bad ) {
	if ( ! class_exists( 'Tribe__Events__API' ) || ! function_exists( 'minn_admin_tec_panel_values' ) ) {
		$skip( 'R4 TEC: not active' );
		return;
	}
	$ev    = 0;
	$clean = $once( static function () use ( &$ev ) {
		if ( $ev ) {
			wp_delete_post( $ev, true );
		}
	} );
	try {
		$ev = (int) Tribe__Events__API::createEvent( array( 'post_title' => 'Minn v047 r4 tec ' . $tag, 'post_status' => 'draft', 'EventStartDate' => '2026-11-10', 'EventStartTime' => '19:00:00', 'EventEndDate' => '2026-11-10', 'EventEndTime' => '21:00:00', 'EventURL' => 'https://example.com/event' ) );
		$check( 'R4 TEC: an event with a website exists', $ev > 0 && 'https://example.com/event' === get_post_meta( $ev, '_EventURL', true ), wp_json_encode( get_post_meta( $ev, '_EventURL', true ) ) );
		list( $st, $d ) = $call( 'POST', '/wp/v2/tribe_events/' . $ev, array( 'minn_tec' => array( 'website' => $r4_bad ) ) );
		$check( 'R4 TEC: a javascript: website is named on the 200 answer', 200 === $st && $field_err( $d, 'minn_tec', 'website' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'R4 TEC: and the stored website stays', 'https://example.com/event' === get_post_meta( $ev, '_EventURL', true ), wp_json_encode( get_post_meta( $ev, '_EventURL', true ) ) );
		list( $st, $d ) = $call( 'POST', '/wp/v2/tribe_events/' . $ev, array( 'minn_tec' => array( 'website' => '' ) ) );
		$check( 'R4 TEC control: an emptied website clears it, with no refusal', 200 === $st && '' === (string) get_post_meta( $ev, '_EventURL', true ) && empty( $d['minn_field_errors'] ), $st . ' ' . wp_json_encode( get_post_meta( $ev, '_EventURL', true ) ) );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once, $field_err, $admin, $tag, $r4_bad ) {
	if ( ! function_exists( 'minn_admin_ssp_active' ) || ! minn_admin_ssp_active() || ! post_type_exists( 'podcast' ) ) {
		$skip( 'R4 SSP: not active' );
		return;
	}
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		$pid = (int) wp_insert_post( array( 'post_title' => 'Minn v047 r4 ssp ' . $tag, 'post_status' => 'draft', 'post_type' => 'podcast', 'post_author' => $admin ) );
		update_post_meta( $pid, 'audio_file', 'https://example.com/episode.mp3' );
		update_post_meta( $pid, 'itunes_episode_number', '7' );
		list( $st, $d ) = $call( 'POST', '/wp/v2/podcast/' . $pid, array( 'minn_ssp' => array( 'audio_file' => $r4_bad, 'itunes_episode_number' => '1.5' ) ) );
		$check( 'R4 SSP: a javascript: episode file and a 1.5 episode number are named', 200 === $st && $field_err( $d, 'minn_ssp', 'audio_file' ) && $field_err( $d, 'minn_ssp', 'itunes_episode_number' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'R4 SSP: and both stay as stored', 'https://example.com/episode.mp3' === get_post_meta( $pid, 'audio_file', true ) && '7' === get_post_meta( $pid, 'itunes_episode_number', true ), wp_json_encode( array( get_post_meta( $pid, 'audio_file', true ), get_post_meta( $pid, 'itunes_episode_number', true ) ) ) );
		list( $st, $d ) = $call( 'POST', '/wp/v2/podcast/' . $pid, array( 'minn_ssp' => array( 'audio_file' => '', 'itunes_episode_number' => '12' ) ) );
		$check( 'R4 SSP control: an emptied file clears and a whole number saves', 200 === $st && '' === get_post_meta( $pid, 'audio_file', true ) && '12' === get_post_meta( $pid, 'itunes_episode_number', true ) && empty( $d['minn_field_errors'] ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once, $field_err, $admin, $tag, $r4_bad ) {
	if ( ! function_exists( 'minn_admin_wpjm_active' ) || ! minn_admin_wpjm_active() ) {
		$skip( 'R4 WPJM: not active' );
		return;
	}
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		$pid = (int) wp_insert_post( array( 'post_type' => 'job_listing', 'post_title' => 'Minn v047 r4 job ' . $tag, 'post_status' => 'publish', 'post_author' => $admin ) );
		update_post_meta( $pid, '_company_video', 'https://example.com/video' );
		list( $st, $d ) = $call( 'POST', '/wp/v2/job-listings/' . $pid, array( 'minn_wpjm' => array( '_company_video' => $r4_bad ) ) );
		$check( 'R4 WPJM: a javascript: company video is named on the 200 answer', 200 === $st && $field_err( $d, 'minn_wpjm', '_company_video' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'R4 WPJM: and the stored video stays', 'https://example.com/video' === get_post_meta( $pid, '_company_video', true ), wp_json_encode( get_post_meta( $pid, '_company_video', true ) ) );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once, $field_err, $admin, $tag, $r4_bad ) {
	$plugin = function_exists( 'minn_admin_seo_plugin' ) ? minn_admin_seo_plugin() : null;
	$url    = '';
	if ( is_array( $plugin ) ) {
		foreach ( minn_admin_seo_field_map( $plugin, 0 ) as $k => $def ) {
			if ( 'url' === ( $def['sanitize'] ?? '' ) ) {
				$url = (string) $k;
				break;
			}
		}
	}
	if ( '' === $url ) {
		$skip( 'R4 SEO: no provider with an address field is active' );
		return;
	}
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		$pid = (int) wp_insert_post( array( 'post_title' => 'Minn v047 r4 seo ' . $tag, 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
		call_user_func( $plugin['write'], $pid, $url, 'https://example.com/canonical' );
		$read = static function () use ( $plugin, $pid, $url ) {
			$r = (array) call_user_func( $plugin['read'], $pid );
			return (string) ( $r[ $url ] ?? '' );
		};
		$check( 'R4 SEO: a stored ' . $url . ' exists', 'https://example.com/canonical' === $read(), $read() );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => array( $url => $r4_bad, 'title' => 'Kept r4 ' . $tag ) ) );
		$check( 'R4 SEO: a javascript: ' . $url . ' is named on the 200 answer, the title is not', 200 === $st && $field_err( $d, 'minn_seo', $url ) && ! $field_err( $d, 'minn_seo', 'title' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$check( 'R4 SEO: and the stored ' . $url . ' stays', 'https://example.com/canonical' === $read(), $read() );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_seo' => array( $url => '' ) ) );
		$check( 'R4 SEO control: an emptied ' . $url . ' clears, with no refusal', 200 === $st && '' === $read() && empty( $d['minn_field_errors'] ), $st . ' ' . $read() );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once, $field_err, $admin, $tag ) {
	if ( ! function_exists( 'minn_admin_powerpress_active' ) || ! minn_admin_powerpress_active() ) {
		$skip( 'R4 PowerPress: not active (run with MINN_SWAP_ADD=powerpress/powerpress.php)' );
		return;
	}
	$pid   = 0;
	$clean = $once( static function () use ( &$pid ) {
		if ( $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		$pid = (int) wp_insert_post( array( 'post_title' => 'Minn v047 r4 pp ' . $tag, 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
		update_post_meta( $pid, 'enclosure', wp_slash( "https://example.com/r4.mp3\n99\naudio/mpeg\n" . serialize( array( 'subtitle' => 'Kept sub', 'episode_no' => '7', 'season' => '2' ) ) ) ); // phpcs:ignore -- their storage format
		$vals = static function () use ( $pid ) {
			return minn_admin_powerpress_read_values( $pid );
		};
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'subtitle' => '<b></b>', 'episode_no' => '1.5', 'season' => 'abc' ) ) );
		$check( 'R4 PowerPress: text the cleaner empties and non-whole numbers are named', 200 === $st && $field_err( $d, 'minn_powerpress', 'subtitle' ) && $field_err( $d, 'minn_powerpress', 'episode_no' ) && $field_err( $d, 'minn_powerpress', 'season' ), $st . ' ' . wp_json_encode( $d['minn_field_errors'] ?? null ) );
		$v = $vals();
		$check( 'R4 PowerPress: and all three stay as stored', 'Kept sub' === $v['subtitle'] && '7' === $v['episode_no'] && '2' === $v['season'], wp_json_encode( $v ) );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'url' => array( 'x' ) ) ) );
		$check( 'R4 PowerPress: a media URL sent as a list is named and the episode kept', 200 === $st && $field_err( $d, 'minn_powerpress', 'url' ) && 'https://example.com/r4.mp3' === $vals()['url'], $st . ' ' . wp_json_encode( array( $d['minn_field_errors'] ?? null, $vals()['url'] ) ) );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'episode_no' => '12', 'season' => '', 'subtitle' => '' ) ) );
		$v = $vals();
		$check( 'R4 PowerPress control: a whole number saves and emptied boxes clear', 200 === $st && empty( $d['minn_field_errors'] ) && '12' === $v['episode_no'] && '' === $v['season'] && '' === $v['subtitle'], $st . ' ' . wp_json_encode( $v ) );
	} finally {
		$clean();
	}
} )();

/*
 * R5 (final review). The panel sends an emptied number box as null
 * (formControlValue()), and the whole-number test refused it, so an episode
 * or season number could no longer be cleared. Null is an empty box.
 */
( function () use ( $check, $skip, $call, $once, $field_err, $admin, $tag ) {
	$ssp = function_exists( 'minn_admin_ssp_active' ) && minn_admin_ssp_active() && post_type_exists( 'podcast' );
	$pp  = function_exists( 'minn_admin_powerpress_active' ) && minn_admin_powerpress_active();
	if ( ! $ssp && ! $pp ) {
		$skip( 'R5: neither Seriously Simple Podcasting nor PowerPress is active' );
		return;
	}
	$made  = array();
	$clean = $once( static function () use ( &$made ) {
		foreach ( $made as $pid ) {
			wp_delete_post( $pid, true );
		}
	} );
	try {
		if ( $ssp ) {
			$pid    = (int) wp_insert_post( array( 'post_title' => 'Minn v047 r5 ssp ' . $tag, 'post_status' => 'draft', 'post_type' => 'podcast', 'post_author' => $admin ) );
			$made[] = $pid;
			update_post_meta( $pid, 'itunes_episode_number', '7' );
			update_post_meta( $pid, 'itunes_season_number', '2' );
			list( $st, $d ) = $call( 'POST', '/wp/v2/podcast/' . $pid, array( 'minn_ssp' => array( 'itunes_episode_number' => null, 'itunes_season_number' => null ) ) );
			$check( 'R5 SSP: emptied episode and season numbers (sent as null) clear, with no refusal', 200 === $st && empty( $d['minn_field_errors'] ) && '' === get_post_meta( $pid, 'itunes_episode_number', true ) && '' === get_post_meta( $pid, 'itunes_season_number', true ), $st . ' ' . wp_json_encode( array( $d['minn_field_errors'] ?? null, get_post_meta( $pid, 'itunes_episode_number', true ), get_post_meta( $pid, 'itunes_season_number', true ) ) ) );
		} else {
			$skip( 'R5 SSP: not active' );
		}
		if ( $pp ) {
			$pid    = (int) wp_insert_post( array( 'post_title' => 'Minn v047 r5 pp ' . $tag, 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
			$made[] = $pid;
			update_post_meta( $pid, 'enclosure', wp_slash( "https://example.com/r5.mp3\n99\naudio/mpeg\n" . serialize( array( 'episode_no' => '7', 'season' => '2' ) ) ) ); // phpcs:ignore -- their storage format
			list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $pid, array( 'minn_powerpress' => array( 'episode_no' => null, 'season' => null ) ) );
			$v = minn_admin_powerpress_read_values( $pid );
			$check( 'R5 PowerPress: emptied episode and season numbers (sent as null) clear, with no refusal', 200 === $st && empty( $d['minn_field_errors'] ) && '' === $v['episode_no'] && '' === $v['season'], $st . ' ' . wp_json_encode( array( $d['minn_field_errors'] ?? null, $v['episode_no'], $v['season'] ) ) );
		} else {
			$skip( 'R5 PowerPress: not active (run with MINN_SWAP_ADD=powerpress/powerpress.php)' );
		}
	} finally {
		$clean();
	}
} )();

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
