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
 * GF-Mask-1: the Gravity Forms per-form settings save wrote an edited value
 *   still holding an egress mask into the form, where the credential was.
 * WPC-Auto-1: WPCode keeps snippet writes away from automation until its
 *   "AI & Automation Access" switch is on; Minn's WPCode routes wrote for an
 *   Application Password. A static guard holds every WPCode write route to
 *   the switch. Defines REST_REQUEST for its last checks, so it runs last.
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

/*
 * GF-Mask-1: the per-form settings save refuses an edit that still holds a
 * mask, keeps a value sent back as served, and writes a clean edit.
 */
( function () use ( $check, $send, $seed, $put_back, $forget, $fake ) {
	if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gf_form_settings_save' ) ) {
		echo "SKIP  GF-Mask-1 Gravity Forms inactive\n";
		return;
	}
	// A key in a row declared since before this round, so the check stands
	// on its own.
	$key  = $fake( 'gf-mask' );
	$desc = '<p>Find us: <iframe src="https://maps.example.test/embed?key=' . $key . '"></iframe></p>';
	$form = GFAPI::add_form(
		array(
			'title'       => 'minn v049 mask ' . wp_rand(),
			'description' => $desc,
			'fields'      => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Answer' ) ),
		)
	);
	if ( is_wp_error( $form ) ) {
		echo "SKIP  GF-Mask-1 form not created\n";
		return;
	}
	try {
		$seed( 'rg_gforms_captcha_private_key', $key );
		$forget();
		$route                  = "/minn-admin/v1/gf/forms/{$form}/settings/form";
		list( $st, $body, $d )  = $send( 'GET', $route );
		$served                 = (string) ( $d['values']['description'] ?? '' );
		$check( "GF-Mask-1 the settings read shows the description with the key masked (premise, {$st})", 200 === $st && false !== strpos( $served, '[redacted:' ) && false === strpos( $body, $key ), substr( $served, 0, 120 ) );

		list( $st, $body ) = $send( 'POST', $route, array(), array( 'values' => array( 'description' => $served . '<p>Open daily.</p>' ) ) );
		$stored            = (string) GFAPI::get_form( $form )['description'];
		$check( "GF-Mask-1 an edit around the mask is refused with nothing written ({$st})", 400 === $st && $desc === $stored && false !== strpos( $body, 'minn_egress_mask' ), $desc === $stored ? '' : 'the placeholder was stored' );

		list( $st ) = $send( 'POST', $route, array(), array( 'values' => array( 'description' => $served ) ) );
		$stored     = (string) GFAPI::get_form( $form )['description'];
		$check( "GF-Mask-1 the description sent back as served is unchanged: the key stays ({$st})", 200 === $st && $desc === $stored );

		$clean        = '<p>Open daily.</p>';
		list( $st )   = $send( 'POST', $route, array(), array( 'values' => array( 'description' => $clean, 'title' => GFAPI::get_form( $form )['title'] ) ) );
		$after        = GFAPI::get_form( $form );
		$check( "GF-Mask-1 an edit holding no mask is written ({$st})", 200 === $st && false !== strpos( (string) $after['description'], 'Open daily.' ) && false === strpos( (string) $after['description'], '[redacted:' ) );
	} finally {
		GFAPI::delete_form( $form );
		$put_back();
	}
} )();

/*
 * WPC-Auto-1: with WPCode's "AI & Automation Access" off, a write that is not
 * a browser session (an Application Password, a REST request without a
 * valid wp_rest nonce) cannot create, edit, turn on or off or delete a
 * snippet; Minn's own screens (a valid nonce) still can.
 */
( function () use ( $check, $send, $tag ) {
	// Static guard: every run closure in the WPCode adapter that writes
	// (a resource create or action, or an endpoint whose methods are not
	// GET) asks the switch before anything else.
	$file = dirname( __DIR__ ) . '/includes/adapters/wpcode.php';
	if ( ! is_readable( $file ) ) {
		$file = WP_PLUGIN_DIR . '/minn-admin/includes/adapters/wpcode.php';
	}
	$toks = array_values(
		array_filter(
			token_get_all( (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions
			static function ( $t ) {
				return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
	$text = static function ( $from, $to ) use ( $toks ) {
		$out = '';
		for ( $k = $from; $k <= $to; $k++ ) {
			$out .= is_array( $toks[ $k ] ) ? $toks[ $k ][1] : $toks[ $k ];
			$out .= ' ';
		}
		return $out;
	};
	$n      = count( $toks );
	$writes = array();
	$gated  = 0;
	for ( $i = 0; $i < $n; $i++ ) {
		$t = $toks[ $i ];
		if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] || "'run'" !== $t[1] || T_DOUBLE_ARROW !== ( $toks[ $i + 1 ][0] ?? null ) ) {
			continue;
		}
		// The array literal holding this 'run', and the call it is an
		// argument of (minn_admin_endpoint, or a resource's create/action).
		$depth = 0;
		$open  = null;
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			$c = $toks[ $j ];
			if ( ')' === $c || ']' === $c ) {
				++$depth;
			} elseif ( '(' === $c || '[' === $c ) {
				if ( 0 === $depth ) {
					$open = $j;
					break;
				}
				--$depth;
			}
		}
		$depth = 0;
		$close = $n - 1;
		for ( $j = $open + 1; $j < $n; $j++ ) {
			$c = $toks[ $j ];
			if ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				if ( 0 === $depth ) {
					$close = $j;
					break;
				}
				--$depth;
			}
		}
		$block    = $text( $open, $close );
		$endpoint = $open >= 3 && is_array( $toks[ $open - 1 ] ) && 'array' === strtolower( $toks[ $open - 1 ][1] ) && preg_match( "/minn_admin_endpoint\s*\(\s*'[^']*'\s*,\s*$/", $text( max( 0, $open - 5 ), $open - 2 ) );
		if ( $endpoint ) {
			$write = (bool) preg_match( "/'methods'\s*=>\s*(?!'GET')/", $block );
		} else {
			$write = ! preg_match( "/'method'\s*=>\s*'GET'/", $block );
		}
		if ( ! $write ) {
			continue;
		}
		// The closure's body, from its first brace to the matching one.
		$from = $i + 2;
		while ( $from < $n && '{' !== $toks[ $from ] ) {
			++$from;
		}
		$depth = 0;
		$to    = $from;
		for ( $j = $from; $j < $n; $j++ ) {
			if ( '{' === $toks[ $j ] || ( is_array( $toks[ $j ] ) && in_array( $toks[ $j ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
			} elseif ( '}' === $toks[ $j ] ) {
				--$depth;
				if ( 0 === $depth ) {
					$to = $j;
					break;
				}
			}
		}
		$writes[] = $t[2];
		// The gate is the body's first statement: nothing is read or written
		// before it.
		$gated += preg_match( '/^\{\s*\$(\w+)\s*=\s*minn_admin_wpcode_authoring_refused\s*\(\s*\$request\s*\)\s*;\s*if\s*\(\s*\$\1\s*\)\s*\{\s*return\s+\$\1\s*;/', $text( $from, $to ) ) ? 1 : 0;
	}
	$check( 'WPC-Auto-1 guard: every WPCode write route asks the automation switch first (' . count( $writes ) . ' routes, lines ' . implode( ', ', $writes ) . ')', count( $writes ) >= 4 && count( $writes ) === $gated, "{$gated} of " . count( $writes ) );

	if ( ! function_exists( 'minn_admin_wpcode_active' ) || ! minn_admin_wpcode_active() || ! class_exists( 'WPCode_Abilities_API' ) || ! method_exists( 'WPCode_Abilities_API', 'write_enabled' ) ) {
		echo "SKIP  WPC-Auto-1 WPCode 2.4+ inactive\n";
		return;
	}
	// The switch is set in memory through WPCode's own filter: nothing
	// stored changes.
	$off = static function () {
		return false;
	};
	$on  = static function () {
		return true;
	};
	$as_app_password = static function ( $fn ) {
		global $wp_rest_application_password_uuid;
		$held                              = $wp_rest_application_password_uuid;
		$wp_rest_application_password_uuid = wp_generate_uuid4();
		try {
			return $fn();
		} finally {
			$wp_rest_application_password_uuid = $held;
		}
	};
	$route = '/minn-admin/v1/wpcode/snippets';
	$body  = array( 'name' => 'Minn v049 automation ' . $tag, 'code' => '// minn v049', 'code_type' => 'php', 'location' => 'on_demand', 'auto_insert' => true, 'priority' => 10, 'active' => false, 'tags' => array(), 'desc' => '' );
	$made  = array();
	$start = get_posts( array( 'post_type' => 'wpcode', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	$count = static function () {
		return count( get_posts( array( 'post_type' => 'wpcode', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
	};
	try {
		add_filter( 'wpcode_abilities_write_enabled', $off, PHP_INT_MAX );
		list( $st, , $d ) = $send( 'POST', $route, array(), $body );
		$id               = (int) ( is_array( $d ) ? ( $d['id'] ?? 0 ) : 0 );
		if ( $id ) {
			$made[] = $id;
		}
		$check( "WPC-Auto-1 control: a signed-in session (no Application Password) creates a snippet with the switch off ({$st})", 200 === $st && $id > 0, wp_json_encode( is_array( $d ) ? ( $d['code'] ?? '' ) : '' ) );
		if ( ! $id ) {
			return;
		}
		$before = $count();
		list( $st, , $d ) = $as_app_password( static function () use ( $send, $route, $body ) {
			return $send( 'POST', $route, array(), array( 'name' => 'Minn v049 app pw' ) + $body );
		} );
		$check( "WPC-Auto-1 with the switch off, an Application Password cannot create a snippet ({$st})", 403 === $st && 'minn_wpcode_automation_off' === ( $d['code'] ?? '' ) && $before === $count(), wp_json_encode( $d['code'] ?? '' ) );
		$code_was = (string) get_post_field( 'post_content', $id );
		list( $st ) = $as_app_password( static function () use ( $send, $route, $id ) {
			return $send( 'PUT', $route . '/' . $id, array(), array( 'code' => '// minn v049 rewritten' ) );
		} );
		clean_post_cache( $id );
		$check( "WPC-Auto-1 nor edit one ({$st})", 403 === $st && $code_was === (string) get_post_field( 'post_content', $id ) );
		list( $st ) = $as_app_password( static function () use ( $send, $route, $id ) {
			return $send( 'POST', $route . '/' . $id . '/active', array(), array( 'active' => true ) );
		} );
		clean_post_cache( $id );
		$check( "WPC-Auto-1 nor turn one on ({$st})", 403 === $st && 'publish' !== get_post_status( $id ) );
		list( $st ) = $as_app_password( static function () use ( $send, $route, $id ) {
			return $send( 'DELETE', $route . '/' . $id );
		} );
		clean_post_cache( $id );
		$check( "WPC-Auto-1 nor delete one ({$st})", 403 === $st && false !== get_post_status( $id ) );

		remove_filter( 'wpcode_abilities_write_enabled', $off, PHP_INT_MAX );
		add_filter( 'wpcode_abilities_write_enabled', $on, PHP_INT_MAX );
		list( $st ) = $as_app_password( static function () use ( $send, $route, $id ) {
			return $send( 'PUT', $route . '/' . $id, array(), array( 'priority' => 11 ) );
		} );
		$check( "WPC-Auto-1 control: with the switch on, an Application Password may edit ({$st})", 200 === $st );
		remove_filter( 'wpcode_abilities_write_enabled', $on, PHP_INT_MAX );

		// Over HTTP a sign-in that is not a cookie session arrives without a
		// nonce. Last in the suite: REST_REQUEST stays defined.
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
		add_filter( 'wpcode_abilities_write_enabled', $off, PHP_INT_MAX );
		list( $st, , $d ) = $send( 'POST', $route, array(), array( 'name' => 'Minn v049 no nonce' ) + $body );
		$check( "WPC-Auto-1 with the switch off, a REST request with no wp_rest nonce cannot create a snippet ({$st})", 403 === $st && 'minn_wpcode_automation_off' === ( $d['code'] ?? '' ) );
		list( $st ) = $send( 'POST', $route, array( '_wpnonce' => 'abcdef1234' ), array( 'name' => 'Minn v049 bad nonce' ) + $body );
		$check( "WPC-Auto-1 nor one with a forged nonce ({$st})", 403 === $st );
		$req = new WP_REST_Request( 'PUT', $route . '/' . $id );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$check( 'WPC-Auto-1 a valid nonce does not admit an Application Password', function_exists( 'minn_admin_wpcode_authoring_refused' ) && null !== $as_app_password( static function () use ( $req ) {
			return minn_admin_wpcode_authoring_refused( $req );
		} ) );
		list( $st ) = $send( 'POST', $route . '/' . $id . '/active', array( '_wpnonce' => wp_create_nonce( 'wp_rest' ) ), array( 'active' => false ) );
		$check( "WPC-Auto-1 control: Minn's own screens (a valid wp_rest nonce) still write with the switch off ({$st})", 200 === $st );
	} finally {
		remove_filter( 'wpcode_abilities_write_enabled', $off, PHP_INT_MAX );
		remove_filter( 'wpcode_abilities_write_enabled', $on, PHP_INT_MAX );
		// What this section made, and any snippet a refusal under test let
		// through.
		foreach ( get_posts( array( 'post_type' => 'wpcode', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) {
			if ( in_array( $p->ID, $made, true ) || ( ! in_array( $p->ID, $start, true ) && 0 === strpos( $p->post_title, 'Minn v049 ' ) ) ) {
				wp_delete_post( $p->ID, true );
			}
		}
	}
} )();

$summary();
