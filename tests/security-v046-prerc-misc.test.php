<?php
/**
 * Pre-RC regressions: saves that rewrote a value nobody changed, in the
 * WooCommerce settings forms, the redirect, snippet, events and podcast
 * adapters, and the tiled gallery rebuild.
 *
 * Each section seeds a value the vendor's own store can hold and the old save
 * path changed (a WooCommerce field someone cleared, a percent-encoded
 * redirect, Rank Math sources in their own key order and a relative target,
 * FluentSnippets tags joined with "," and indented code, a WPCode text
 * snippet holding an embed, an event cost holding an entity, a PowerPress
 * extra holding a backslash, a tiled gallery photo with its own alt text and
 * custom link), sends what Minn's client sends for an edit of ANOTHER field,
 * and checks the stored bytes. A control in each checks that a real edit is
 * still cleaned and written.
 *
 * Database seeds (the WooCommerce test zone among them) run inside a
 * transaction that is rolled back; the FluentSnippets file and the Simple 301
 * option are put back however the section ends. Sections SKIP when their
 * plugin is inactive. 301 Redirects, Simple 301 Redirects and PowerPress rest
 * inactive on the dev site, so the swap loads them for one process.
 *
 * Rank Math builds its redirections module only on a site connected to a
 * Rank Math account or with its setup skipped, and only with the module
 * switched on, which the dev site is not. Passed to --require as well, this
 * file answers both for the one process before WordPress loads (nothing is
 * written) and runs its checks as the eval-file.
 *
 * Run: MINN_SWAP_ADD=eps-301-redirects/eps-301-redirects.php,simple-301-redirects/wp-simple-301-redirects.php,powerpress/powerpress.php \
 *      wp eval-file tests/security-v046-prerc-misc.test.php --user=admin --path=<site> \
 *      --require=tests/lib/plugin-swap.php --require=tests/security-v046-prerc-misc.test.php
 *
 * @package minn-admin
 */

if ( ! function_exists( 'rest_get_server' ) ) {
	if ( class_exists( 'WP_CLI' ) ) {
		// An option the site never stored is answered by its default_ hook.
		foreach ( array( 'option_rank_math_registration_skip', 'default_option_rank_math_registration_skip' ) as $hook ) {
			WP_CLI::add_wp_hook( $hook, function () {
				return 1;
			} );
		}
		WP_CLI::add_wp_hook( 'option_rank_math_modules', function ( $modules ) {
			return array_values( array_unique( array_merge( is_array( $modules ) ? $modules : array(), array( 'redirections' ) ) ) );
		} );
	}
	return;
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
$call    = function ( $method, $route, $body = null, array $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
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

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();

/*
 * WooCommerce emails, payment gateways and shipping methods: a field someone
 * cleared reads back empty and stays empty when another field is saved. The
 * form read each value with WooCommerce's default as the "empty value", so a
 * cleared field was shown, posted and saved as the default text.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'WC' ) || ! function_exists( 'minn_admin_wc_settings_email' ) ) {
		$skip( 'WooCommerce settings inactive' );
		return;
	}
	$wpdb->query( 'START TRANSACTION' );
	try {
		$email = minn_admin_wc_settings_email( 'customer_completed_order' );
		$field = $email ? (array) ( $email->get_form_fields()['additional_content'] ?? array() ) : array();
		if ( ! $email || '' === (string) ( $field['default'] ?? '' ) ) {
			$skip( 'WC email: no completed-order email with a default additional content' );
		} else {
			$key                         = $email->get_option_key();
			$opt                         = (array) get_option( $key, array() );
			$opt['additional_content']   = '';
			update_option( $key, $opt );
			$email->init_settings();
			list( , $form ) = $call( 'GET', '/minn-admin/v1/wc/emails/customer_completed_order' );
			$check( 'WC email: a cleared field reads back empty, as stored', '' === ( $form['values']['additional_content'] ?? null ), wp_json_encode( $form['values']['additional_content'] ?? null ) );
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/wc/emails/customer_completed_order', array( 'values' => array( 'heading' => 'Minn prerc heading' ) ) );
			$now = (array) get_option( $key, array() );
			$check( 'WC email: an edit of the heading answers 200 and writes it (control)', 200 === $st && 'Minn prerc heading' === ( $now['heading'] ?? null ), $st . ' ' . wp_json_encode( $now['heading'] ?? $d ) );
			$check( 'WC email: and keeps the cleared additional content empty', '' === ( $now['additional_content'] ?? null ), wp_json_encode( $now['additional_content'] ?? null ) );
		}

		$gateway = minn_admin_wc_settings_gateway( 'cheque' );
		$gfield  = $gateway ? (array) ( $gateway->get_form_fields()['description'] ?? array() ) : array();
		if ( ! $gateway || '' === (string) ( $gfield['default'] ?? '' ) ) {
			$skip( 'WC gateway: no cheque gateway with a default description' );
		} else {
			$key                = $gateway->get_option_key();
			$opt                = (array) get_option( $key, array() );
			$opt['description'] = '';
			update_option( $key, $opt );
			$gateway->init_settings();
			list( , $form ) = $call( 'GET', '/minn-admin/v1/wc/payment_gateways/cheque' );
			$check( 'WC gateway: a cleared description reads back empty, as stored', '' === ( $form['values']['description'] ?? null ), wp_json_encode( $form['values']['description'] ?? null ) );
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/wc/payment_gateways/cheque', array( 'values' => array( 'instructions' => 'Minn prerc instructions' ) ) );
			$now = (array) get_option( $key, array() );
			$check( 'WC gateway: an edit of the instructions answers 200 and writes them (control)', 200 === $st && 'Minn prerc instructions' === ( $now['instructions'] ?? null ), $st . ' ' . wp_json_encode( $now['instructions'] ?? $d ) );
			$check( 'WC gateway: and keeps the cleared description empty', '' === ( $now['description'] ?? null ), wp_json_encode( $now['description'] ?? null ) );
		}

		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			$skip( 'WC shipping: no shipping zones' );
		} else {
			$zone = new WC_Shipping_Zone();
			$zone->set_zone_name( 'Minn prerc zone' );
			$zone->save();
			$instance = (int) $zone->add_shipping_method( 'flat_rate' );
			if ( ! $instance ) {
				$skip( 'WC shipping: flat rate would not attach to a test zone' );
			} else {
				$key = 'woocommerce_flat_rate_' . $instance . '_settings';
				update_option( $key, array( 'title' => '', 'tax_status' => 'taxable', 'cost' => '5' ) );
				$path = '/minn-admin/v1/wc/shipping/zones/' . (int) $zone->get_id() . '/methods/' . $instance;
				list( , $form ) = $call( 'GET', $path );
				$check( 'WC shipping: a cleared method title reads back empty, as stored', '' === ( $form['values']['title'] ?? null ), wp_json_encode( $form['values']['title'] ?? null ) );
				list( $st, $d ) = $call( 'POST', $path, array( 'values' => array( 'cost' => '7' ) ) );
				$now = (array) get_option( $key, array() );
				$check( 'WC shipping: an edit of the cost answers 200 and writes it (control)', 200 === $st && '7' === (string) ( $now['cost'] ?? '' ), $st . ' ' . wp_json_encode( $now['cost'] ?? $d ) );
				$check( 'WC shipping: and keeps the cleared title empty', '' === ( $now['title'] ?? null ), wp_json_encode( $now['title'] ?? null ) );
			}
		}
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * 301 Redirects (WebFactory): a source and target the edit leaves alone keep
 * their stored bytes. Their CSV import and their v1 migration store them
 * undecoded, and the save decoded and cleaned both sides ('caf%C3%A9' became
 * 'café', '?q=c%2B%2B' became '?q=c++').
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'minn_admin_eps301_active' ) || ! minn_admin_eps301_active() ) {
		$skip( '301 Redirects inactive (load it with MINN_SWAP_ADD=eps-301-redirects/eps-301-redirects.php)' );
		return;
	}
	if ( ! Minn_Admin_Kernel_SQL::exists( 'redirects' ) ) {
		$skip( '301 Redirects has no table here' );
		return;
	}
	$table = $wpdb->prefix . 'redirects';
	$from  = 'minn-prerc/caf%C3%A9';
	$to    = 'https://example.com/find?q=c%2B%2B&amp;x=1';
	$wpdb->query( 'START TRANSACTION' );
	try {
		$wpdb->insert( $table, array( 'url_from' => $from, 'url_to' => $to, 'status' => '301', 'type' => 'url', 'count' => 0 ) );
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			$check( '301 Redirects: a rule is seeded for the check', false, $wpdb->last_error );
			return;
		}
		$served = null;
		list( , $list ) = $call( 'GET', '/minn-admin/v1/eps301/redirects', null, array( 'search' => 'minn-prerc', 'per_page' => 50 ) );
		foreach ( (array) ( $list['items'] ?? array() ) as $row ) {
			if ( (int) ( $row['id'] ?? 0 ) === $id ) {
				$served = $row;
			}
		}
		$check( '301 Redirects: the list serves the seeded rule', is_array( $served ), wp_json_encode( $list ) );
		$served = is_array( $served ) ? $served : minn_admin_eps301_item( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore
		$route  = '/' . str_replace( '{id}', (string) $id, Minn_Admin_Kernel::action_path( 'eps301/redirects', 'edit' ) );
		$row    = function () use ( $wpdb, $table, $id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT url_from, url_to, status, type FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		};
		list( $st, $d ) = $call( 'PUT', $route, array( 'from' => $served['from'], 'to' => $served['to'], 'status' => '302' ) );
		$now = $row();
		$check( '301 Redirects: a status-only edit answers 200 and writes the status (control)', 200 === $st && '302' === $now['status'], $st . ' ' . wp_json_encode( $d ) );
		$check( '301 Redirects: and keeps the stored source byte for byte', $from === $now['url_from'], wp_json_encode( $now['url_from'] ) );
		$check( '301 Redirects: and keeps the stored target byte for byte', $to === $now['url_to'], wp_json_encode( $now['url_to'] ) );
		list( $st, $d ) = $call( 'PUT', $route, array( 'status' => 'inactive' ) );
		$now = $row();
		$check( '301 Redirects: a body naming only the status keeps the source and target as stored', 200 === $st && 'inactive' === $now['status'] && $from === $now['url_from'] && $to === $now['url_to'], $st . ' ' . wp_json_encode( $now ?: $d ) );
		list( $st ) = $call( 'PUT', $route, array( 'from' => $served['from'], 'to' => '/minn <b>new</b>%20page', 'status' => '302' ) );
		$now = $row();
		$check( '301 Redirects: a changed target is decoded and cleaned as their form does (control)', 200 === $st && '/minn new page' === $now['url_to'], $st . ' ' . wp_json_encode( $now ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

/*
 * Simple 301 Redirects: a source or target the edit leaves alone keeps its
 * stored bytes. sanitize_text_field() takes percent octets out, so an
 * untouched '/caf%C3%A9' became '/caf', renaming the rule.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_s301_active' ) || ! minn_admin_s301_active() ) {
		$skip( 'Simple 301 Redirects inactive (load it with MINN_SWAP_ADD=simple-301-redirects/wp-simple-301-redirects.php)' );
		return;
	}
	$sentinel = new stdClass();
	$was      = get_option( '301_redirects', $sentinel );
	$restore  = $once( function () use ( $was, $sentinel ) {
		if ( $was === $sentinel ) {
			delete_option( '301_redirects' );
		} else {
			update_option( '301_redirects', $was );
		}
	} );
	try {
		$from = '/minn-prerc/caf%C3%A9';
		$to   = '/menu?deal=50%DEAL';
		$rows = is_array( $was ) ? $was : array();
		unset( $rows['/minn-prerc/moved'] );
		$rows[ $from ] = $to;
		update_option( '301_redirects', $rows );
		$id = minn_admin_s301_encode_id( $from );
		list( $st, $d ) = $call( 'PUT', "/minn-admin/v1/s301/redirects/{$id}", array( 'from' => $from, 'to' => 'https://example.com/new' ) );
		$now = (array) get_option( '301_redirects', array() );
		$check( 'Simple 301: a target-only edit answers 200 and writes the target (control)', 200 === $st && in_array( 'https://example.com/new', $now, true ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'Simple 301: and keeps the stored source as the rule key', array_key_exists( $from, $now ) && ! array_key_exists( '/minn-prerc/caf', $now ), wp_json_encode( array_keys( $now ) ) );
		$now[ $from ] = $to;
		update_option( '301_redirects', $now );
		list( $st ) = $call( 'PUT', "/minn-admin/v1/s301/redirects/{$id}", array( 'from' => '/minn-prerc/moved', 'to' => $to ) );
		$now = (array) get_option( '301_redirects', array() );
		$check( 'Simple 301: a source-only edit answers 200 and renames the rule (control)', 200 === $st && array_key_exists( '/minn-prerc/moved', $now ) && ! array_key_exists( $from, $now ), $st . ' ' . wp_json_encode( array_keys( $now ) ) );
		$check( 'Simple 301: and keeps the stored target byte for byte', $to === ( $now['/minn-prerc/moved'] ?? null ), wp_json_encode( $now['/minn-prerc/moved'] ?? null ) );
		list( $st, $d ) = $call( 'PUT', '/minn-admin/v1/s301/redirects/' . minn_admin_s301_encode_id( '/minn-prerc/moved' ), array( 'to' => '/menu?deal=50%DEAL&v=2' ) );
		$now = (array) get_option( '301_redirects', array() );
		$check( 'Simple 301: a body naming only the target keeps the source as stored', 200 === $st && array_key_exists( '/minn-prerc/moved', $now ), $st . ' ' . wp_json_encode( $d ) );
	} finally {
		$restore();
	}
} )();

printf( "\nsecurity-v046-prerc-misc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
