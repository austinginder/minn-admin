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
 * Each section calls what the route calls, as the role the finding named,
 * with a control where the fix could over-block. Fixtures (users, a listing,
 * gift cards, the Perfmatters option) are put back at the end however the
 * run ends. The gift card section runs for every gift card plugin loaded
 * (PW Gift Cards, WooCommerce Gift Cards, YITH) and SKIPs the others.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v047-registry-adapters.test.php --user=admin --path=<site>
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

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
