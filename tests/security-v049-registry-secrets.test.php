<?php
/**
 * Regressions for the v0.48.0 shipped-zip registry scan, secrets lane
 * (findings that open v0.49.0).
 *
 * Creds-1: credential rows the vendors themselves keep secret (WPForms'
 *   PayPal Commerce connection and marketing accounts, Bookly's sensitive
 *   options and purchase codes, Gravity Forms' Gravity API secret, WPCode's
 *   library connection, Burst's share tokens, Bricks' settings row,
 *   Autoptimize's critical CSS key) were in no list: the database browser
 *   printed them and the egress fence held no needle for their copies. Each
 *   is seeded in its vendor's own layout and read back through the browser,
 *   its value search, the fence's list and a minn-admin/v1 response that
 *   hands a copy back.
 *
 * Seeded rows are written with SQL (no option hooks: the vendor sees
 * nothing) and put back with SQL however the run ends. Prints names, never
 * values.
 *
 * Run: wp eval-file tests/security-v049-registry-secrets.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : " — {$detail}" );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v049-registry-secrets: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};

if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Minn_Admin_DB' ) || ! class_exists( 'Minn_Admin_Egress' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}
global $wpdb;
rest_get_server();
$tag = strtolower( wp_generate_password( 6, false ) );

// A request as the browser gets it: the egress fence runs on the way out.
$send = static function ( $method, $route, $params = array(), $json = null ) {
	$server = rest_get_server();
	$req    = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	if ( null !== $json ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( (string) wp_json_encode( $json ) );
	}
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	$json = (string) wp_json_encode( $data );
	return array( $res->get_status(), $json, json_decode( $json, true ) );
};
$forget = static function () {
	Minn_Admin_DB::forget_credentials();
	Minn_Admin_Egress::reset();
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};
// A fake key: letters and digits mixed, long enough for the fence.
$fake = static function ( $label ) use ( $tag ) {
	return 'mK' . $tag . substr( md5( $label . $tag ), 0, 22 ) . 'Z9q';
};

// Option rows borrowed by SQL, put back by SQL on every exit.
$borrowed = array();
$put_back = static function () use ( &$borrowed, $wpdb, $forget ) {
	foreach ( $borrowed as $name => $was ) {
		if ( null === $was ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		} else {
			$wpdb->update( $wpdb->options, array( 'option_value' => $was['option_value'], 'autoload' => $was['autoload'] ), array( 'option_name' => $name ) );
		}
		wp_cache_delete( $name, 'options' );
	}
	$borrowed = array();
	$forget();
};
register_shutdown_function( $put_back );
$seed = static function ( $name, $value ) use ( &$borrowed, $wpdb ) {
	if ( ! array_key_exists( $name, $borrowed ) ) {
		$borrowed[ $name ] = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A );
	}
	$raw = maybe_serialize( $value );
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ) {
		$wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => $name ) );
	} else {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => 'off' ) );
	}
	wp_cache_delete( $name, 'options' );
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) );
};

// The route an adapter that slips would be: it hands back what is put in
// $echo, under minn-admin/v1, where the egress fence runs.
$echo = '';
register_rest_route(
	'minn-admin/v1',
	'/secrets-v049-probe-' . $tag,
	array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => static function () use ( &$echo ) {
			return array( 'value' => $echo );
		},
	)
);
$probe = '/minn-admin/v1/secrets-v049-probe-' . $tag;

/*
 * Creds-1: each store in its vendor's layout. 'keys' are the values the
 * fence must find (credentials long and key-shaped enough to search for).
 */
$stores = array(
	'wpforms_paypal_commerce_connections' => array(
		'live' => array( 'client_id' => 'AZcid' . $tag, 'merchant_id' => 'MRCH' . $tag, 'secret' => '%secret%', 'access_token' => '%access_token%', 'token_expires_in' => 32400, 'type' => 'third_party', 'status' => 'valid' ),
	),
	'wpforms_providers'                   => array(
		'mailchimpv3'       => array( 'acct' . $tag => array( 'api' => '%mailchimp%', 'label' => 'Shop list', 'date' => 1700000000 ) ),
		'constant-contact-v3' => array( 'cc' . $tag => array( 'access_token' => '%cc_access%', 'refresh_token' => '%cc_refresh%', 'label' => 'News' ) ),
	),
	'wpforms_connect'                     => '%wpforms_connect%',
	'bookly_gc_client_secret'             => '%bookly_gc%',
	'bookly_oc_app_secret'                => '%bookly_oc%',
	'bookly_zoom_oauth_client_secret'     => '%bookly_zoom%',
	'bookly_smtp_password'                => '%bookly_smtp%',
	'bookly_cloud_zapier_api_key'         => '%bookly_zapier%',
	'bookly_cloud_cron_api_key'           => '%bookly_cron%',
	'bookly_pro_purchase_code'            => '%bookly_pro_code%',
	'gf_site_secret'                      => '%gf_site_secret%',
	'rg_gforms_captcha_secret_key'        => '%gf_captcha_secret%',
	'wpcode_library_api_auth'             => array( 'key' => '%wpcode_key%', 'username' => 'site-owner', 'webhook_secret' => '%wpcode_webhook%', 'client_id' => 'cl' . $tag, 'connected_at' => 1700000000 ),
	'wpcode_connect'                      => '%wpcode_connect%',
	'wpcode_connect_token'                => '%wpcode_oth%',
	'burst_share_tokens'                  => array( array( 'token' => '%burst_share%', 'expires' => 0, 'created' => 1700000000 ) ),
	'burst_options_settings'              => array( 'license' => '%burst_license%', 'enable_turbo_mode' => false ),
	'bricks_global_settings'              => array( 'postTypes' => array( 'page' ), 'apiKeySendgrid' => 'SG.%sendgrid_a%.%sendgrid_b%', 'apiSecretKeyTurnstile' => '%bricks_turnstile%', 'apiKeyMailchimp' => '%bricks_mailchimp%', 'instagramAccessToken' => '%bricks_instagram%', 'myTemplatesPassword' => '%bricks_mytemplates%' ),
	'autoptimize_ccss_key'                => '%autoptimize_ccss%',
);
// Rows hidden by name only: no value in them is a key the fence searches
// for (Burst's Search Console tokens are encrypted at rest).
$by_name = array( 'burst_gsc_tokens', 'burst_admin_options_notifications' );

$keys     = array();
$fill     = static function ( $v ) use ( &$fill, &$keys, $fake ) {
	if ( is_array( $v ) ) {
		return array_map( $fill, $v );
	}
	if ( ! is_string( $v ) ) {
		return $v;
	}
	return preg_replace_callback(
		'/%([a-z_]+)%/',
		static function ( $m ) use ( &$keys, $fake ) {
			$keys[ $m[1] ] = $fake( $m[1] );
			return $keys[ $m[1] ];
		},
		$v
	);
};
$ids      = array();
$per_row  = array();
$shop_row = 0;
try {
	foreach ( $stores as $name => $layout ) {
		$before          = array_keys( $keys );
		$value           = $fill( $layout );
		$ids[ $name ]    = $seed( $name, $value );
		$per_row[ $name ] = array_values( array_diff( array_keys( $keys ), $before ) );
	}
	foreach ( $by_name as $name ) {
		$ids[ $name ] = $seed( $name, 'burst_gsc_tokens' === $name ? 'enc:' . base64_encode( $fake( 'gsc' ) ) : array( 'slack_webhook_url' => 'https://hooks.slack.com/services/T0' . $tag . '/B0' . $tag . '/' . $fake( 'slack' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}
	// Bookly copies each add-on's purchase code into its shop row.
	$shop = $wpdb->prefix . 'bookly_shop';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $shop ) ) ) ) {
		$keys['bookly_shop_license'] = substr( $fake( 'bookly_shop_license' ), 0, 29 ) . 'Z9q';
		$row                         = array();
		foreach ( $wpdb->get_results( "SHOW COLUMNS FROM `{$shop}`", ARRAY_A ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the name SHOW TABLES gave.
			if ( 'NO' === $c['Null'] && null === $c['Default'] && false === strpos( (string) $c['Extra'], 'auto_increment' ) ) {
				$row[ $c['Field'] ] = preg_match( '/int|decimal|float|double/i', $c['Type'] ) ? 0 : ( preg_match( '/date|time/i', $c['Type'] ) ? current_time( 'mysql' ) : '' );
			}
		}
		$row['slug']    = 'bookly-addon-minn-' . $tag;
		$row['license'] = $keys['bookly_shop_license'];
		$shop_row       = $wpdb->insert( $shop, $row ) ? (int) $wpdb->insert_id : 0;
	}
	$forget();

	$list = Minn_Admin_DB::egress_credentials();
	foreach ( array_merge( array_keys( $stores ), $by_name ) as $name ) {
		list( $st, $body, $d ) = $send( 'GET', '/minn-admin/v1/db/row', array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $ids[ $name ] ) ) ) );
		$cell = null;
		foreach ( (array) ( $d['columns'] ?? array() ) as $i => $c ) {
			if ( 'option_value' === ( $c['name'] ?? '' ) ) {
				$cell = $d['cells'][ $i ] ?? null;
			}
		}
		$whole = array_filter(
			$per_row[ $name ] ?? array(),
			static function ( $k ) use ( $body, $keys ) {
				return false !== strpos( $body, $keys[ $k ] );
			}
		);
		$check( "Creds-1 {$name}: a credential row by name, opened redacted in the database browser ({$st})", Minn_Admin_DB::is_credential_option( $name ) && 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ) && ! $whole, null === $cell ? 'no cell' : ( $whole ? 'printed' : 'not redacted' ) );
		if ( ! empty( $per_row[ $name ] ) ) {
			list( $st, $body ) = $send( 'GET', '/minn-admin/v1/db/rows', array( 'table' => $wpdb->options, 'fcol' => 'option_value', 'fq' => substr( $keys[ $per_row[ $name ][0] ], 2, 14 ), 'per_page' => 100 ) );
			$check( "Creds-1 {$name}: a value search for part of a key does not answer for the row ({$st})", false === strpos( $body, '"' . $name . '"' ) );
		}
		$miss = array_filter(
			$per_row[ $name ] ?? array(),
			static function ( $k ) use ( $list, $keys ) {
				return ! in_array( $keys[ $k ], $list, true );
			}
		);
		if ( ! empty( $per_row[ $name ] ) ) {
			$check( "Creds-1 {$name}: every key in it is one of the egress fence's needles", ! $miss, implode( ', ', $miss ) );
		}
	}
	if ( $shop_row ) {
		list( $st, $body ) = $send( 'GET', '/minn-admin/v1/db/row', array( 'table' => $shop, 'pk' => wp_json_encode( array( 'id' => $shop_row ) ) ) );
		$check( "Creds-1 bookly_shop.license: an add-on's purchase code is not printed in its shop row ({$st})", 200 === $st && false === strpos( $body, $keys['bookly_shop_license'] ) );
		$check( 'Creds-1 bookly_shop.license: the purchase code is one of the egress fence\'s needles', in_array( $keys['bookly_shop_license'], $list, true ) );
	} else {
		echo "SKIP  Creds-1 bookly_shop.license: no Bookly shop table here, or it refused the fixture row\n";
	}

	// A copy of every key in some other response (a cron argument, a log
	// line, a cached answer) leaves masked.
	$echo                 = array( 'log' => 'connected with ' . implode( ' and ', $keys ) );
	list( $st, $b )       = $send( 'GET', $probe );
	$echo                 = '';
	$leak                 = array_keys(
		array_filter(
			$keys,
			static function ( $k ) use ( $b ) {
				return false !== strpos( $b, $k );
			}
		)
	);
	$check( 'Creds-1 a minn-admin/v1 response holding a copy of each key shows none of them whole (' . count( $keys ) . ' keys)', 200 === $st && ! $leak, implode( ', ', $leak ) );
} finally {
	if ( $shop_row ) {
		$wpdb->delete( $shop, array( 'id' => $shop_row ) );
	}
	$put_back();
}

$summary();
