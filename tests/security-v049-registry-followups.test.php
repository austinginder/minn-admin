<?php
/**
 * Regressions for the v0.48.0 shipped-zip registry scan, second wave: the
 * same classes the first-wave fixes closed, in the files beside them.
 *
 * Owed-1: credential rows a vendor names as a key (Fluent Forms, Ninja
 *   Forms, Everest Forms, Seriously Simple Podcasting, WP Statistics,
 *   Jetpack, UpdraftPlus, Smash Balloon) were not declared, so the database
 *   browser and the egress fence showed them.
 * GFE-Mask-1: the Gravity Forms answer editor compared the page's copy of an
 *   answer (a credential in it masked) with the raw stored one, so an entry
 *   holding a key always answered 409, and without that check an edit still
 *   holding the mask stored the placeholder over the key.
 * Mail-Item-1: WP Mail Logging's flat item served the raw headers and error,
 *   FluentSMTP's the provider's reply, subject and source, with a reset
 *   link's key whole, to a reader the sections hide it from.
 * Session-1: the Elementor MCP and Novamira write gates checked a wp_rest
 *   nonce but not the Application Password a nonce minted for it verifies
 *   under. Static guard: every wp_rest nonce check sits beside
 *   Minn_Admin_REST::browser_session().
 * Bricks-Tags-1: a template save writes tags by name, so a tag name served
 *   masked and sent back created a tag named "[redacted:…]".
 * Answers-Mask-1: the Ninja Forms, WPForms and Elementor forms answer
 *   editors had GFE-Mask-1's compare. Static guard over every answer editor.
 * Mail-Row-1: list rows served a subject holding a reset link whole, and
 *   Post SMTP's and Gravity SMTP's flat items the error, solution, transport
 *   and subject. Static guard: every mail log row and item builder masks.
 *
 * Seeded rows are written with SQL (no option hooks: the vendor sees
 * nothing) and put back with SQL however the run ends; seeded log rows,
 * users, forms and entries are deleted. Prints names, never values.
 *
 * Run: wp eval-file tests/security-v049-registry-followups.test.php --user=admin --path=<site>
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
	printf( "\nsecurity-v049-registry-followups: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};

if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Minn_Admin_DB' ) || ! class_exists( 'Minn_Admin_Egress' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}
global $wpdb;
rest_get_server();
$tag   = strtolower( wp_generate_password( 6, false ) );
$admin = get_current_user_id();
$root  = dirname( __DIR__ );

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
};

// On a network wp_delete_user() only takes an account off this site.
$drop_user = static function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

/*
 * Owed-1: each row is a credential row, declared beside the adapter that
 * owns its plugin (Smash Balloon's in the licence reader, the one file that
 * reads Smash Balloon), and a key seeded into it never leaves whole.
 */
( function () use ( $check, $seed, $put_back, $forget, $fake, $send, $tag ) {
	$rows = array(
		'fluentform_stripe_webhook_secret'         => 'fluent-forms.php',
		'ninja_forms_oauth_client_secret'          => 'ninja-forms.php',
		'everest_forms_ai_api_key'                 => 'everest-forms.php',
		'everest_forms_restapi_keys'               => 'everest-forms.php',
		'everest_forms_admin_entry_approval_token' => 'everest-forms.php',
		'ss_podcasting_protection_password'        => 'seriously-simple-podcasting.php',
		'wp_statistics_daily_salt'                 => 'wp-statistics.php',
		'jetpack_forms_secret_key'                 => 'jetpack-stats.php',
		'jetpack_protect_key'                      => 'jetpack-stats.php',
		'bruteprotect_api_key'                     => 'jetpack-stats.php',
		'updraftplus_migration_temp_password'      => 'updraftplus.php',
		'updraftplus_product_key'                  => 'updraftplus.php',
		'cff_oembed_token'                         => 'licenses.php',
		'sbi_oembed_token'                         => 'licenses.php',
		'cff_access_token'                         => 'licenses.php',
		'cff_page_access_token'                    => 'licenses.php',
		'cff_license_key_multifeed'                => 'licenses.php',
	);
	$where = array();
	if ( method_exists( 'Minn_Admin_Kernel_Secrets', 'declared' ) ) {
		foreach ( (array) Minn_Admin_Kernel_Secrets::declared() as $d ) {
			foreach ( array_keys( (array) ( $d['options'] ?? array() ) ) as $name ) {
				$where[ $name ] = (array) ( $d['files'] ?? array() );
			}
		}
	}
	$open  = array();
	$moved = array();
	foreach ( $rows as $name => $file ) {
		if ( ! Minn_Admin_DB::is_credential_option( $name ) ) {
			$open[] = $name;
		} elseif ( $where ) {
			// A row, or a name shape ('*' for any run) that covers it.
			$in = array();
			foreach ( $where as $declared => $files ) {
				if ( $declared === $name || ( false !== strpos( $declared, '*' ) && fnmatch( $declared, $name ) ) ) {
					$in = array_merge( $in, $files );
				}
			}
			if ( ! in_array( $file, $in, true ) ) {
				$moved[] = "{$name} (not in {$file})";
			}
		}
	}
	$check( 'Owed-1 every owed row is a credential row (' . count( $rows ) . ' rows)', ! $open, implode( ', ', $open ) );
	if ( $where ) {
		$check( 'Owed-1 each is declared in its own adapter', ! $moved, implode( ', ', $moved ) );
	}

	// The values as each vendor stores them, read back through a route that
	// hands the stored row to the response as an adapter that slips would.
	$echo  = '';
	$route = '/followups-v049-probe-' . $tag;
	register_rest_route( 'minn-admin/v1', $route, array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => static function () use ( &$echo ) {
			return array( 'value' => $echo );
		},
	) );
	$shapes = array(
		'fluentform_stripe_webhook_secret' => array( 'whsec_' . $fake( 'stripe' ) ),
		'ninja_forms_oauth_client_secret'  => array( $fake( 'nf' ) ),
		'everest_forms_ai_api_key'         => array( 'sk-' . $fake( 'evf-ai' ) ),
		'everest_forms_restapi_keys'       => array( $fake( 'evf-rest' ) ),
		'cff_oembed_token'                 => array( array( 'access_token' => 'EAA' . $fake( 'cff' ), 'expiration_date' => time() + 3600 ) ),
		'cff_access_token'                 => array( 'EAA' . $fake( 'cff-at' ) ),
	);
	$leaked = array();
	try {
		foreach ( $shapes as $name => $shape ) {
			$value = $shape[0];
			$seed( $name, $value );
			$forget();
			$key  = is_array( $value ) ? $value['access_token'] : $value;
			$echo = get_option( $name );
			list( , $body ) = $send( 'GET', '/minn-admin/v1' . $route );
			if ( false !== strpos( $body, $key ) || false === strpos( $body, '[redacted:' ) ) {
				$leaked[] = $name;
			}
		}
	} finally {
		$put_back();
	}
	$check( 'Owed-1 a key seeded into each row in its vendor\'s shape leaves a minn-admin/v1 response masked (' . count( $shapes ) . ' rows)', ! $leaked, implode( ', ', $leaked ) );
} )();

/*
 * GFE-Mask-1: an answer holding a known credential is served masked; sent
 * back that way it is no conflict and no change, an edit still holding the
 * mask is refused with nothing written, and a clean edit is written.
 */
( function () use ( $check, $send, $seed, $put_back, $forget, $fake ) {
	if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gf_entry_edit_block' ) ) {
		echo "SKIP  GFE-Mask-1 Gravity Forms inactive\n";
		return;
	}
	$key  = $fake( 'gfe-mask' );
	$form = GFAPI::add_form(
		array(
			'title'  => 'minn v049 answers ' . wp_rand(),
			'fields' => array(
				array( 'id' => 1, 'type' => 'text', 'label' => 'Token' ),
				array( 'id' => 2, 'type' => 'text', 'label' => 'Note' ),
				array(
					'id'      => 3,
					'type'    => 'multiselect',
					'label'   => 'Picks',
					'choices' => array( array( 'text' => 'Plain', 'value' => 'Plain' ), array( 'text' => 'Other', 'value' => 'Other' ) ),
				),
			),
		)
	);
	if ( is_wp_error( $form ) ) {
		echo "SKIP  GFE-Mask-1 form not created\n";
		return;
	}
	$answer = 'Use ' . $key . ' here';
	// A pick the form no longer offers stays a ticked box (forms-kit.php),
	// here one holding the key.
	$picks = wp_json_encode( array( 'Plain', $key ) );
	$entry = GFAPI::add_entry( array( 'form_id' => $form, '1' => $answer, '2' => 'hello', '3' => $picks ) );
	try {
		if ( is_wp_error( $entry ) ) {
			$check( 'GFE-Mask-1 an entry to edit', false, $entry->get_error_message() );
			return;
		}
		$seed( 'rg_gforms_captcha_private_key', $key );
		$forget();
		$route  = "/minn-admin/v1/gf/entries/{$entry}";
		$save   = $route . '/answers';
		$served = array();
		list( $st, $body, $d ) = $send( 'GET', $route );
		foreach ( (array) ( $d['edit']['fields'] ?? array() ) as $f ) {
			foreach ( (array) $f['inputs'] as $in ) {
				$served[ (string) $in['id'] ] = $in['value'];
			}
		}
		$t1 = (string) ( $served['1'] ?? '' );
		$check( "GFE-Mask-1 the entry read shows the answer with the key masked (premise, {$st})", 200 === $st && false !== strpos( $t1, '[redacted:' ) && false === strpos( $body, $key ), substr( $t1, 0, 60 ) );
		$raw = function ( $id ) use ( $entry ) {
			return GFAPI::get_entry( $entry )[ $id ];
		};

		// The page sends only what changed, with the value it loaded.
		list( $st, $body ) = $send( 'POST', $save, array(), array( 'values' => array( '2' => 'hello again' ), 'original' => array( '2' => $served['2'] ?? '' ) ) );
		$check( "GFE-Mask-1 control: editing another answer saves ({$st})", 200 === $st && 'hello again' === $raw( '2' ) && $answer === $raw( '1' ) );

		list( $st, $body ) = $send( 'POST', $save, array(), array( 'values' => array( '1' => 'Use nothing here' ), 'original' => array( '1' => $t1 ) ) );
		$check( "GFE-Mask-1 editing the answer that holds a key is no conflict: the page's masked copy is the stored answer ({$st})", 200 === $st && 'Use nothing here' === $raw( '1' ), substr( $body, 0, 160 ) );
		GFAPI::update_entry_field( $entry, '1', $answer );

		list( $st, $body ) = $send( 'POST', $save, array(), array( 'values' => array( '1' => $t1 . ' today' ), 'original' => array( '1' => $t1 ) ) );
		$check( "GFE-Mask-1 an edit around the mask is refused with nothing written ({$st})", 400 === $st && $answer === $raw( '1' ) && false !== strpos( $body, 'minn_egress_mask' ), $answer === $raw( '1' ) ? substr( $body, 0, 120 ) : 'the placeholder was stored' );

		// An API caller that sends no original: the same refusal.
		list( $st ) = $send( 'POST', $save, array(), array( 'values' => array( '1' => $t1 . ' today', '2' => 'changed' ) ) );
		$check( "GFE-Mask-1 without an original too, and the other answer in the same save is not written either ({$st})", 400 === $st && $answer === $raw( '1' ) && 'hello again' === $raw( '2' ) );

		list( $st ) = $send( 'POST', $save, array(), array( 'values' => array( '1' => $t1 ) ) );
		$check( "GFE-Mask-1 the answer sent back as served is unchanged: the key stays ({$st})", 200 === $st && $answer === $raw( '1' ) );

		// A multi-select: ticking another box keeps the masked pick as stored.
		$p3 = (array) ( $served['3'] ?? array() );
		$check( 'GFE-Mask-1 the stored pick holding the key is served masked (premise)', 2 === count( $p3 ) && false !== strpos( (string) wp_json_encode( $p3 ), '[redacted:' ) );
		list( $st, $body ) = $send( 'POST', $save, array(), array( 'values' => array( '3' => array_merge( $p3, array( 'Other' ) ) ), 'original' => array( '3' => $p3 ) ) );
		// Stored the way the field saves a list (JSON or comma-joined).
		$picked = (string) $raw( '3' );
		$check( "GFE-Mask-1 ticking another box keeps the masked pick as the stored one ({$st})", 200 === $st && false !== strpos( $picked, $key ) && false !== strpos( $picked, 'Other' ) && false !== strpos( $picked, 'Plain' ) && false === strpos( $picked, '[redacted:' ), substr( $body, 0, 160 ) );
	} finally {
		GFAPI::delete_form( $form );
		$put_back();
	}
} )();

/*
 * Mail-Item-1: the flat item routes mask a reset link's key in every text
 * they serve, as the sections do, for a reader who does not read mail whole;
 * one who does reads it whole (control).
 */
( function () use ( $check, $send, $drop_user, $admin, $tag, $wpdb ) {
	if ( ! function_exists( 'minn_admin_mail_mask_reset_keys' ) ) {
		echo "SKIP  Mail-Item-1 the mail kit is not loaded\n";
		return;
	}
	$k = 'Mk' . $tag . 'q7Lw9PaZx3Vb8Nc2';
	$link = wp_login_url() . "?action=rp&key={$k}&login=root";
	// An Editor given manage_options: both plugins' default capability, but
	// no edit_users, so mail is not read whole.
	$reader = wp_insert_user( array(
		'user_login' => 'minn-v049-fu-' . wp_rand(),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'editor',
		'user_email' => 'minn-v049-fu-' . wp_rand() . '@example.com',
	) );
	if ( is_wp_error( $reader ) ) {
		$check( 'Mail-Item-1 a reader to run as', false, $reader->get_error_message() );
		return;
	}
	( new WP_User( $reader ) )->add_cap( 'manage_options' );
	$exists = function ( $t ) use ( $wpdb ) {
		$t = $wpdb->prefix . $t;
		return 0 === strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ), $t );
	};
	$ids = array();
	try {
		$cases = array();
		if ( function_exists( 'minn_admin_wpml_active' ) && minn_admin_wpml_active() && $exists( 'wpml_mails' ) ) {
			$wpdb->insert( $wpdb->prefix . 'wpml_mails', array(
				'timestamp'      => current_time( 'mysql' ),
				'host'           => '127.0.0.1',
				'receiver'       => 'root@example.com',
				'subject'        => 'Password Reset ' . $link,
				'message'        => 'Reset: ' . $link,
				'headers'        => "X-Reset: {$link}\nContent-Type: text/plain",
				'attachments'    => '',
				'error'          => 'Could not send: ' . $link,
				'plugin_version' => '0',
			) );
			$ids['wpml_mails/mail_id'] = (int) $wpdb->insert_id;
			$cases['WP Mail Logging'] = '/minn-admin/v1/wpml/emails/' . $ids['wpml_mails/mail_id'];
		} else {
			echo "SKIP  Mail-Item-1 WP Mail Logging inactive\n";
		}
		if ( function_exists( 'minn_admin_fluent_smtp_can' ) && $exists( 'fsmpt_email_logs' ) ) {
			$wpdb->insert( $wpdb->prefix . 'fsmpt_email_logs', array(
				'to'         => serialize( array( array( 'email' => 'root@example.com', 'name' => '' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- FluentSMTP's own stored shape.
				'from'       => 'site@example.com',
				'subject'    => 'Password Reset ' . $link,
				'body'       => 'Reset: ' . $link,
				'status'     => 'failed',
				'response'   => '550 rejected: ' . $link,
				'source'     => $link,
				'retries'    => 0,
				'created_at' => current_time( 'mysql' ),
			) );
			$ids['fsmpt_email_logs/id'] = (int) $wpdb->insert_id;
			$cases['FluentSMTP'] = '/minn-admin/v1/fluent-smtp/emails/' . $ids['fsmpt_email_logs/id'];
		} else {
			echo "SKIP  Mail-Item-1 FluentSMTP inactive\n";
		}
		foreach ( $cases as $what => $route ) {
			wp_set_current_user( $reader );
			list( $st, $body ) = $send( 'GET', $route );
			if ( 200 !== $st ) {
				echo "SKIP  Mail-Item-1 {$what} does not admit the reader here ({$st})\n";
				wp_set_current_user( $admin );
				continue;
			}
			$check( "Mail-Item-1 {$what}: the flat item hides the reset key in every field for a reader who does not read mail whole", false === strpos( $body, substr( $k, 4, 12 ) ), substr( $body, 0, 200 ) );
			wp_set_current_user( $admin );
			list( $st, $body ) = $send( 'GET', $route );
			$check( "Mail-Item-1 {$what} control: who reads mail whole reads the item whole ({$st})", 200 === $st && false !== strpos( $body, $k ) );
		}
	} finally {
		wp_set_current_user( $admin );
		foreach ( $ids as $where => $id ) {
			list( $table, $col ) = explode( '/', $where );
			$wpdb->delete( $wpdb->prefix . $table, array( $col => $id ) );
		}
		$drop_user( $reader );
	}
} )();

/*
 * Session-1: the MCP write gates refuse an Application Password even with a
 * valid nonce, and admit a browser session (control).
 */
( function () use ( $check ) {
	$gates = array(
		'Elementor MCP' => array( 'minn_admin_elementor_mcp_can_write', 'minn_admin_elementor_mcp_can' ),
		'Novamira'      => array( 'minn_admin_novamira_can_write', 'minn_admin_novamira_can' ),
	);
	$as_app_password = function ( $fn ) {
		global $wp_rest_application_password_uuid;
		$held                              = $wp_rest_application_password_uuid;
		$wp_rest_application_password_uuid = wp_generate_uuid4();
		try {
			return $fn();
		} finally {
			$wp_rest_application_password_uuid = $held;
		}
	};
	foreach ( $gates as $what => $fns ) {
		list( $write, $can ) = $fns;
		if ( ! function_exists( $write ) || ! function_exists( $can ) || ! $can() ) {
			echo "SKIP  Session-1 {$what} inactive or not manageable here\n";
			continue;
		}
		$req = new WP_REST_Request( 'POST', '/minn-admin/v1/session-probe' );
		$check( "Session-1 {$what} control: a browser session with a valid nonce may write", (bool) $write( ( function () use ( $req ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			return $req;
		} )() ) );
		$check( "Session-1 {$what}: an Application Password with a nonce minted for it may not write", ! $as_app_password( function () use ( $write ) {
			$r = new WP_REST_Request( 'POST', '/minn-admin/v1/session-probe' );
			$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
			return (bool) $write( $r );
		} ) );
		$check( "Session-1 {$what}: no nonce, no write", ! $write( new WP_REST_Request( 'POST', '/minn-admin/v1/session-probe' ) ) );
	}
} )();

/*
 * Session-1 static guard: a wp_rest nonce check means "a browser session",
 * and a nonce alone does not prove one, so every function that verifies one
 * also asks Minn_Admin_REST::browser_session() (or is it).
 */
( function () use ( $check, $root ) {
	$found = 0;
	$bare  = array();
	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $f ) {
		if ( 'php' !== $f->getExtension() ) {
			continue;
		}
		$src = (string) file_get_contents( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! preg_match_all( '/wp_verify_nonce\s*\([^;]*?[\'"]wp_rest[\'"]/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $m[0] as $hit ) {
			++$found;
			$start = strrpos( substr( $src, 0, $hit[1] ), 'function ' );
			$body  = false === $start ? '' : substr( $src, $start, $hit[1] - $start );
			if ( false === strpos( $body, 'browser_session(' ) && ! preg_match( '/^function\s+browser_session\b/', $body ) ) {
				$bare[] = substr( $f->getPathname(), strlen( $root ) + 1 ) . ':' . ( substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1 );
			}
		}
	}
	$check( "Session-1 guard: every wp_rest nonce check asks browser_session() too ({$found} checks)", $found > 0 && ! $bare, implode( ', ', $bare ) );
} )();

/*
 * Bricks-Tags-1: a tag name sent back as served is the stored tag; a mask
 * typed around is refused; a new name is a new tag (control). Bricks is not
 * on the lab, so the save's tag step is checked directly and the route is
 * checked to read tags before it writes anything.
 */
( function () use ( $check, $seed, $put_back, $forget, $fake, $root ) {
	if ( ! function_exists( 'minn_admin_bricks_template_tags' ) ) {
		$check( 'Bricks-Tags-1 the tag step is loaded', false, 'minn_admin_bricks_template_tags() is missing' );
		return;
	}
	$key = $fake( 'bricks-tag' );
	try {
		$seed( 'rg_gforms_captcha_private_key', $key );
		$forget();
		$current = array( 'Header', 'key ' . $key );
		$served  = Minn_Admin_Egress::masked( $current );
		$check( 'Bricks-Tags-1 the tag holding the key is served masked (premise)', false !== strpos( (string) wp_json_encode( $served ), '[redacted:' ) && false === strpos( (string) wp_json_encode( $served ), $key ) );
		$out = minn_admin_bricks_template_tags( $served, $current );
		$check( 'Bricks-Tags-1 tags sent back as served are the stored tags, no placeholder tag', is_array( $out ) && in_array( 'key ' . $key, $out, true ) && in_array( 'Header', $out, true ) && 2 === count( $out ) );
		$out = minn_admin_bricks_template_tags( array_merge( $served, array( 'Footer' ) ), $current );
		$check( 'Bricks-Tags-1 control: a new name beside them is a new tag', is_array( $out ) && in_array( 'Footer', $out, true ) && in_array( 'key ' . $key, $out, true ) && 3 === count( $out ) );
		$out = minn_admin_bricks_template_tags( array( $served[1] . ' copy' ), $current );
		$check( 'Bricks-Tags-1 a name typed around the mask is refused', is_wp_error( $out ) && 'minn_egress_mask' === $out->get_error_code() );
		$out = minn_admin_bricks_template_tags( array( '' ), $current );
		$check( 'Bricks-Tags-1 control: an empty list clears', array() === $out );
	} finally {
		$put_back();
	}
	$src = (string) file_get_contents( $root . '/includes/adapters/bricks.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$a   = strpos( $src, "'/bricks/templates/(?P<id>\\d+)', array(\n\t\t'methods'             => 'PUT'" );
	$put = false === $a ? '' : substr( $src, $a, strpos( $src, 'minn_admin_endpoint(', $a + 10 ) - $a );
	$t   = strpos( $put, 'minn_admin_bricks_template_tags(' );
	$w   = strpos( $put, 'wp_update_post(' );
	$check( 'Bricks-Tags-1 the template save reads its tags through the tag step before any write', '' !== $put && false !== $t && false !== $w && $t < $w && false === strpos( $put, "array_map( function ( \$tag )" ) );
} )();

/*
 * Answers-Mask-1: the other answer editors (Ninja Forms, WPForms, Elementor
 * Pro forms) had the same compare as Gravity Forms: an answer holding a key
 * always answered 409, and one sent back without an original stored the
 * placeholder over the key. Each runs the same checks on a fixture made the
 * vendor's way, read back raw from the vendor's store.
 */
( function () use ( $check, $send, $seed, $put_back, $forget, $fake ) {
	$key    = $fake( 'answers-mask' );
	$answer = 'Use ' . $key . ' here';
	// The edit block wherever the read carries it.
	$block_of = function ( $d ) use ( &$block_of ) {
		if ( ! is_array( $d ) ) {
			return null;
		}
		if ( isset( $d['route'], $d['fields'] ) && is_string( $d['route'] ) && '/answers' === substr( $d['route'], -8 ) ) {
			return $d;
		}
		foreach ( $d as $v ) {
			$b = $block_of( $v );
			if ( $b ) {
				return $b;
			}
		}
		return null;
	};
	$run = function ( $what, $read, $write, callable $raw ) use ( $check, $send, $block_of, $answer ) {
		list( $st, $body, $d ) = $send( 'GET', $read );
		$input  = null;
		$served = '';
		foreach ( (array) ( $block_of( $d )['fields'] ?? array() ) as $f ) {
			foreach ( (array) $f['inputs'] as $in ) {
				if ( is_string( $in['value'] ) && false !== strpos( $in['value'], '[redacted:' ) ) {
					$input  = (string) $in['id'];
					$served = $in['value'];
				}
			}
		}
		$check( "Answers-Mask-1 {$what}: the read serves the answer with the key masked (premise, {$st})", null !== $input && false === strpos( $body, substr( $answer, 6, 20 ) ) );
		if ( null === $input ) {
			return;
		}
		list( $st, $body ) = $send( 'POST', $write, array(), array( 'values' => array( $input => $served . ' today' ), 'original' => array( $input => $served ) ) );
		$check( "Answers-Mask-1 {$what}: an edit around the mask is refused with nothing written, not a false conflict ({$st})", 400 === $st && false !== strpos( $body, 'minn_egress_mask' ) && $answer === $raw( $input ), substr( $body, 0, 120 ) );
		list( $st ) = $send( 'POST', $write, array(), array( 'values' => array( $input => $served ) ) );
		$check( "Answers-Mask-1 {$what}: sent back as served with no original, the key stays ({$st})", 200 === $st && $answer === $raw( $input ), $answer === $raw( $input ) ? '' : 'the placeholder was stored' );
		list( $st, $body ) = $send( 'POST', $write, array(), array( 'values' => array( $input => 'Use nothing here' ), 'original' => array( $input => $served ) ) );
		$check( "Answers-Mask-1 {$what}: a clean edit of that answer is no conflict and is written ({$st})", 200 === $st && 'Use nothing here' === $raw( $input ), substr( $body, 0, 120 ) );
	};
	try {
		$seed( 'rg_gforms_captcha_private_key', $key );
		$forget();

		// Ninja Forms: an nf_sub post with plain postmeta, as its model saves.
		if ( function_exists( 'minn_admin_ninja_forms_active' ) && minn_admin_ninja_forms_active() && function_exists( 'minn_admin_ninja_forms_edit_block' ) ) {
			global $wpdb;
			$form = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}nf3_forms ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sub  = $form ? (int) wp_insert_post( array( 'post_type' => 'nf_sub', 'post_status' => 'publish', 'post_title' => '' ) ) : 0;
			try {
				$text = '';
				if ( $sub ) {
					update_post_meta( $sub, '_form_id', $form );
					foreach ( (array) ( minn_admin_ninja_forms_edit_block( get_post( $sub ), $form )['fields'] ?? array() ) as $f ) {
						if ( '' === $text && in_array( $f['kind'], array( 'text', 'textarea' ), true ) ) {
							$text = (string) $f['id'];
						}
					}
				}
				if ( '' === $text ) {
					echo "SKIP  Answers-Mask-1 Ninja Forms has no form with a text field here\n";
				} else {
					update_post_meta( $sub, '_field_' . $text, $answer );
					$run( 'Ninja Forms', "/minn-admin/v1/ninja-forms/entries/{$sub}", "/minn-admin/v1/ninja-forms/entries/{$sub}/answers", function ( $id ) use ( $sub ) {
						wp_cache_delete( $sub, 'post_meta' );
						return (string) get_post_meta( $sub, '_field_' . $id, true );
					} );
				}
			} finally {
				if ( $sub > 1 && 'nf_sub' === get_post_type( $sub ) ) {
					wp_delete_post( $sub, true );
				}
			}
		} else {
			echo "SKIP  Answers-Mask-1 Ninja Forms inactive\n";
		}

		// WPForms Pro: a form with one text field and an entry, its own way.
		if ( function_exists( 'minn_admin_wpforms_edit_supported' ) && minn_admin_wpforms_edit_supported() ) {
			$form  = (int) wpforms()->obj( 'form' )->add( 'Minn v049 answers' );
			$entry = 0;
			try {
				if ( $form ) {
					wpforms()->obj( 'form' )->update( $form, array(
						'id'       => $form,
						'field_id' => 2,
						'settings' => array( 'form_title' => 'Minn v049 answers' ),
						'fields'   => array( 1 => array( 'id' => 1, 'type' => 'text', 'label' => 'Token' ) ),
					) );
					$fields = array( 1 => array( 'name' => 'Token', 'value' => $answer, 'id' => 1, 'type' => 'text' ) );
					$entry  = (int) wpforms()->obj( 'entry' )->add( array( 'form_id' => $form, 'status' => '', 'fields' => wp_json_encode( $fields ) ) );
					if ( $entry ) {
						wpforms()->obj( 'entry_fields' )->save( $fields, wpforms()->obj( 'form' )->get( $form, array( 'content_only' => true ) ), $entry );
					}
				}
				if ( ! $entry ) {
					echo "SKIP  Answers-Mask-1 WPForms would not store a test entry\n";
				} else {
					$run( 'WPForms', "/minn-admin/v1/wpforms/entries/{$entry}", "/minn-admin/v1/wpforms/entries/{$entry}/answers", function () use ( $entry ) {
						$e = wpforms()->obj( 'entry' )->get( $entry );
						$f = (array) wpforms_decode( (string) ( $e->fields ?? '' ) );
						return (string) ( $f[1]['value'] ?? $f['1']['value'] ?? '' );
					} );
				}
			} finally {
				if ( $entry ) {
					wpforms()->obj( 'entry' )->delete( $entry );
				}
				if ( $form ) {
					wp_delete_post( $form, true );
				}
			}
		} else {
			echo "SKIP  Answers-Mask-1 WPForms Pro inactive\n";
		}

		// Elementor Pro: a submission through their Query.
		if ( function_exists( 'minn_admin_elementor_forms_ready' ) && minn_admin_elementor_forms_ready() ) {
			$q    = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
			$page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Minn v049 answers' ) );
			$sub  = 0;
			try {
				$sub = $page ? (int) $q->add_submission(
					array( 'post_id' => $page, 'element_id' => 'mv049a', 'form_name' => 'Minn v049 answers', 'referer' => home_url( '/' ), 'referer_title' => 'Home' ),
					array( array( 'id' => 'name', 'type' => 'text', 'value' => $answer ) )
				) : 0;
				if ( $sub < 1 ) {
					echo "SKIP  Answers-Mask-1 Elementor would not store a test submission\n";
				} else {
					$run( 'Elementor forms', "/minn-admin/v1/elementor/submissions/{$sub}", "/minn-admin/v1/elementor/submissions/{$sub}/answers", function ( $id ) use ( $q, $sub ) {
						foreach ( (array) ( $q->get_submission( $sub )['data']['values'] ?? array() ) as $v ) {
							if ( (string) ( $v['key'] ?? '' ) === $id ) {
								return (string) $v['value'];
							}
						}
						return '';
					} );
				}
			} finally {
				if ( $sub > 1 ) {
					$q->delete_submission( $sub );
				}
				if ( $page > 1 && 'page' === get_post_type( $page ) ) {
					wp_delete_post( $page, true );
				}
			}
		} else {
			echo "SKIP  Answers-Mask-1 Elementor Pro form submissions unavailable\n";
		}
	} finally {
		$put_back();
	}
} )();

/*
 * Answers-Mask-1 static guard: every answer editor compares the page's copy
 * through the served-as test and refuses a mask before it writes, so the
 * next one cannot copy the raw compare.
 */
( function () use ( $check, $root ) {
	$editors = glob( $root . '/includes/adapters/*-entry-edit.php' );
	$bad     = array();
	foreach ( (array) $editors as $file ) {
		$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$why = array();
		if ( preg_match( '/wp_json_encode\(\s*\$original\[/', $src ) ) {
			$why[] = 'compares the original raw';
		}
		if ( false !== strpos( $src, '$original' ) && ! preg_match( '/(?:Minn_Admin::unchanged|minn_admin_gfe_same)\(\s*\$original\[/', $src ) ) {
			$why[] = 'no served-as compare of the original';
		}
		if ( false === strpos( $src, 'Minn_Admin_Egress::refuse_masks(' ) ) {
			$why[] = 'no refuse_masks()';
		}
		if ( $why ) {
			$bad[] = basename( $file ) . ' (' . implode( ', ', $why ) . ')';
		}
	}
	$check( 'Answers-Mask-1 guard: every answer editor compares as served and refuses masks (' . count( (array) $editors ) . ' editors)', count( (array) $editors ) >= 4 && ! $bad, implode( '; ', $bad ) );
} )();

/*
 * Mail-Row-1: every mail log's list rows and flat item mask a reset link's
 * key, in the subject and every other text, for a reader who does not read
 * mail whole; one who does reads them whole (control). A log whose plugin is
 * not loaded here SKIPs (run-php.sh swaps in Post SMTP, SureMails and Site
 * Mailer for a second run).
 */
( function () use ( $check, $send, $drop_user, $admin, $tag, $wpdb ) {
	if ( ! function_exists( 'minn_admin_mail_mask_reset_keys' ) ) {
		echo "SKIP  Mail-Row-1 the mail kit is not loaded\n";
		return;
	}
	$k      = 'Rw' . $tag . 'q7Lw9PaZx3Vb8Nc2';
	$link   = wp_login_url() . "?action=rp&key={$k}&login=root";
	// Gravity SMTP's subject column holds 100 characters.
	$short  = '/wp-login.php?action=rp&key=' . $k . '&login=root';
	$now    = current_time( 'mysql' );
	$cases  = array(
		'WP Mail Logging' => array( 'wpml/emails', 'wpml_mails', 'mail_id', true, array(
			'timestamp' => $now, 'host' => '127.0.0.1', 'receiver' => 'root@example.com', 'subject' => 'Password Reset ' . $link,
			'message' => 'Reset: ' . $link, 'headers' => "X-Reset: {$link}", 'attachments' => '', 'error' => 'Could not send: ' . $link, 'plugin_version' => '0',
		) ),
		'FluentSMTP'      => array( 'fluent-smtp/emails', 'fsmpt_email_logs', 'id', true, array(
			'to' => serialize( array( array( 'email' => 'root@example.com', 'name' => '' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- FluentSMTP's own stored shape.
			'from' => 'site@example.com', 'subject' => 'Password Reset ' . $link, 'body' => 'Reset: ' . $link, 'status' => 'failed',
			'response' => '550 rejected: ' . $link, 'source' => $link, 'retries' => 0, 'created_at' => $now,
		) ),
		'Gravity SMTP'    => array( 'gravity-smtp/events', 'gravitysmtp_events', 'id', true, array(
			'date_created' => gmdate( 'Y-m-d H:i:s' ), 'date_updated' => gmdate( 'Y-m-d H:i:s' ), 'status' => 'failed', 'service' => 'wp_mail',
			'subject' => 'Reset ' . $short, 'message' => 'Reset: ' . $link, 'extra' => 'a:0:{}',
		) ),
		'Post SMTP'       => array( 'post-smtp/emails', 'post_smtp_logs', 'id', true, array(
			'solution' => 'Check ' . $link, 'success' => 'Could not send: ' . $link, 'from_header' => 'site@example.com', 'to_header' => 'root@example.com',
			'original_to' => 'root@example.com', 'original_subject' => 'Password Reset ' . $link, 'original_message' => 'Reset: ' . $link,
			'transport_uri' => 'smtp://relay.example ' . $link, 'time' => time(),
		) ),
		'SureMails'       => array( 'suremails/emails', 'suremails_email_log', 'id', false, array(
			'email_from' => 'site@example.com', 'email_to' => serialize( array( 'root@example.com' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- SureMails' own stored shape.
			'subject' => 'Password Reset ' . $link, 'body' => 'Reset: ' . $link, 'headers' => '', 'attachments' => '', 'status' => 'failed',
			'response' => '', 'connection' => '', 'created_at' => $now, 'updated_at' => $now,
		) ),
		'Site Mailer'     => array( 'site-mailer/emails', 'site_mail_logs', 'id', false, array(
			'to' => '["root@example.com"]', 'subject' => 'Password Reset ' . $link, 'message' => 'Reset: ' . $link, 'source' => 'minn-test',
			'status' => 'failed', 'created_at' => $now, 'updated_at' => $now,
		) ),
	);
	$reader = wp_insert_user( array(
		'user_login' => 'minn-v049-row-' . wp_rand(),
		'user_pass'  => wp_generate_password( 24 ),
		'role'       => 'editor',
		'user_email' => 'minn-v049-row-' . wp_rand() . '@example.com',
	) );
	if ( is_wp_error( $reader ) ) {
		$check( 'Mail-Row-1 a reader to run as', false, $reader->get_error_message() );
		return;
	}
	// Each log's own reading capability, none of which reads mail whole.
	$reader_caps = array( 'manage_options' );
	if ( function_exists( 'minn_admin_gsmtp_cap' ) ) {
		$reader_caps[] = minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG' );
		$reader_caps[] = minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG_DETAILS' );
	}
	if ( function_exists( 'minn_admin_post_smtp_cap' ) ) {
		$reader_caps[] = minn_admin_post_smtp_cap();
	}
	$user = new WP_User( $reader );
	foreach ( $reader_caps as $cap ) {
		$user->add_cap( $cap );
	}
	$routes = rest_get_server()->get_routes();
	$seeded = array();
	try {
		foreach ( $cases as $what => $c ) {
			list( $res, $table, $col, $item, $row ) = $c;
			$full = $wpdb->prefix . $table;
			if ( ! isset( $routes[ '/minn-admin/v1/' . $res ] ) || 0 !== strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) ) ), $full ) ) {
				echo "SKIP  Mail-Row-1 {$what} is not loaded here\n";
				continue;
			}
			if ( ! $wpdb->insert( $full, $row ) ) {
				$check( "Mail-Row-1 {$what}: a row is seeded", false, $wpdb->last_error );
				continue;
			}
			$id              = (int) $wpdb->insert_id;
			$seeded[ $full ] = array( $col, $id );
			$mine            = function ( $body ) use ( $id ) {
				return (bool) preg_match( '/"id":' . $id . '[,}]/', (string) $body );
			};
			wp_set_current_user( 0 );
			wp_set_current_user( $reader );
			if ( minn_admin_mail_bodies_open() ) {
				echo "SKIP  Mail-Row-1 {$what}: the reader reads mail whole here\n";
				wp_set_current_user( $admin );
				continue;
			}
			list( $st, $body ) = $send( 'GET', '/minn-admin/v1/' . $res, array( 'per_page' => 50 ) );
			if ( 200 !== $st || ! $mine( $body ) ) {
				echo "SKIP  Mail-Row-1 {$what}: the list does not show the reader the seeded row here ({$st})\n";
			} else {
				$check( "Mail-Row-1 {$what}: the list row hides the reset key in its subject for a reader who does not read mail whole", false === strpos( $body, substr( $k, 4, 12 ) ) );
			}
			if ( $item ) {
				list( $st, $ibody ) = $send( 'GET', '/minn-admin/v1/' . $res . '/' . $id );
				$check( "Mail-Row-1 {$what}: the flat item hides the reset key in every field ({$st})", 200 === $st && false === strpos( $ibody, substr( $k, 4, 12 ) ), substr( $ibody, 0, 200 ) );
			}
			wp_set_current_user( $admin );
			// A plugin swapped in for this process never ran its activation,
			// which grants an administrator its log capability.
			$grant = function ( $all ) use ( $reader_caps ) {
				return array_merge( $all, array_fill_keys( $reader_caps, true ) );
			};
			add_filter( 'user_has_cap', $grant );
			try {
				list( $st, $body ) = $send( 'GET', '/minn-admin/v1/' . $res, array( 'per_page' => 50 ) );
			} finally {
				remove_filter( 'user_has_cap', $grant );
			}
			$check( "Mail-Row-1 {$what} control: who reads mail whole reads the subject whole ({$st})", 200 === $st && false !== strpos( $body, $k ) );
		}
	} finally {
		wp_set_current_user( $admin );
		foreach ( $seeded as $full => $at ) {
			$wpdb->delete( $full, array( $at[0] => $at[1] ) );
		}
		$drop_user( $reader );
	}
} )();

/*
 * Mail-Row-1 static guard: every mail log adapter builds its list rows and
 * flat item through minn_admin_mail_masked(), so the next one cannot serve a
 * subject raw.
 */
( function () use ( $check, $root ) {
	$files = array();
	foreach ( (array) glob( $root . '/includes/adapters/*.php' ) as $file ) {
		$src = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( 'mail-kit.php' !== basename( $file ) && preg_match( '/minn_admin_mail_(?:sections|surface|card|bodies_open|mask_reset_keys)\(/', $src ) ) {
			$files[ basename( $file ) ] = $src;
		}
	}
	$bad = array();
	$n   = 0;
	foreach ( $files as $name => $src ) {
		preg_match_all( "/'row'\s*=>\s*([^\n]*)|'view'\s*=>\s*(function\b[^\n]*|minn_admin_mail_masked\([^\n]*)/", $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		foreach ( $m as $hit ) {
			$what = isset( $hit[2] ) && '' !== $hit[2][0] ? $hit[2][0] : $hit[1][0];
			++$n;
			if ( 0 !== strpos( $what, 'minn_admin_mail_masked(' ) ) {
				$bad[] = $name . ':' . ( substr_count( substr( $src, 0, $hit[0][1] ), "\n" ) + 1 );
			}
		}
	}
	$check( 'Mail-Row-1 guard: every mail log row and item builder is minn_admin_mail_masked() (' . count( $files ) . " adapters, {$n} builders)", count( $files ) >= 7 && $n > 0 && ! $bad, implode( ', ', $bad ) );
} )();

$summary();
