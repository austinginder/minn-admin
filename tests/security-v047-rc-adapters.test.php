<?php
/**
 * v0.47.0 RC audit, adapters.
 *
 * - BackWPup (08-M1): the archive delete took any job id and file name
 *   without path characters, and BackWPup's file_delete() builds the folder
 *   from the job's settings, so a job that does not exist resolved to
 *   wp-content and a delete there removed whatever the name said. Now only
 *   an archive BackWPup's own listing returns is deleted. A throwaway file
 *   in wp-content stands in for object-cache.php.
 * - Mail logs (08-M2): on a network, a site's administrator could ask for a
 *   super admin's password reset on their own site's login page and read
 *   the link in that site's mail log. Every mail log's body now hides the
 *   key of a reset link from anyone who is not the network owner. Checked
 *   on the helper every mail log's detail goes through; on a single site it
 *   only checks that nothing is hidden.
 *
 * Run: wp eval-file tests/security-v047-rc-adapters.test.php --user=admin --path=<site>
 *      (on a network: --url=<a site other than the main one>)
 * The WPCode section needs WPCode, which the dev site keeps off: add
 *   MINN_SWAP_ADD=insert-headers-and-footers/ihaf.php ... --require=tests/lib/plugin-swap.php
 * The last section defines DISALLOW_FILE_EDIT, which cannot be undone in the
 * process, so it runs last.
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
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
$admin = get_current_user_id();
$tag   = (string) wp_rand( 1000, 9999 );
rest_get_server();

/*
 * 08-M1: BackWPup deletes only an archive its own listing returns.
 */
( function () use ( $check, $skip, $call, $once, $tag ) {
	if ( ! function_exists( 'minn_admin_backwpup_active' ) || ! minn_admin_backwpup_active() ) {
		$skip( 'BackWPup: inactive' );
		return;
	}
	if ( is_multisite() && ! Minn_Admin::network_owner() ) {
		$skip( 'BackWPup: the network owner runs this section' );
		return;
	}
	$name  = "minn-rc-sentinel-{$tag}.php";
	$file  = WP_CONTENT_DIR . '/' . $name;
	$clean = $once( function () use ( $file ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
	} );
	try {
		file_put_contents( $file, "<?php // Minn RC sentinel\n" );
		list( $st ) = $call( 'DELETE', '/minn-admin/v1/backwpup/backups/' . rawurlencode( '999999:' . $name ) );
		clearstatcache();
		$check( 'BackWPup: a delete for a job that does not exist leaves wp-content alone', file_exists( $file ) && 404 === $st, $st . ( file_exists( $file ) ? '' : ' (the file was deleted)' ) );
		$rows = minn_admin_backwpup_rows();
		$job  = $rows ? (int) $rows[0]['jobid'] : 0;
		if ( $job ) {
			list( $st ) = $call( 'DELETE', '/minn-admin/v1/backwpup/backups/' . rawurlencode( $job . ':.htaccess' ) );
			$check( 'BackWPup: a real job\'s .htaccess is not an archive to delete', 404 === $st, (string) $st );
			// On code that lets the delete through, put BackWPup's own folder
			// protection back, so a failing run leaves no dump reachable.
			if ( 404 !== $st && class_exists( 'BackWPup_File' ) && method_exists( 'BackWPup_File', 'protect_folder' ) ) {
				$protect = new ReflectionMethod( 'BackWPup_File', 'protect_folder' );
				$protect->setAccessible( true );
				$protect->invoke( null, untrailingslashit( BackWPup_File::get_absolute_path( (string) BackWPup_Option::get( $job, 'backupdir' ) ) ) );
			}
		} else {
			$skip( 'BackWPup: no archive listed for the .htaccess check' );
		}
	} finally {
		$clean();
	}
} )();

/*
 * 08-M2: a reset link's key is hidden from anyone on a network who is not
 * its owner.
 */
( function () use ( $check, $once, $admin, $tag ) {
	if ( ! function_exists( 'minn_admin_mail_mask_reset_keys' ) ) {
		$check( 'mail logs: the mask helper exists', false );
		return;
	}
	$login = wp_login_url();
	$body  = "<p>Someone has requested a password reset.</p>\n<a href=\"{$login}?action=rp&amp;key=AbCdEf123456&amp;login=root\">Reset</a>\n{$login}?action=rp&key=ZyXw987654&login=root\nhttps://shop.example/my-account/lost-password/?key=Wc0011223344&id=1\nhttps://shop.example/checkout/order-received/7/?key=wc_order_keepme";
	if ( ! is_multisite() ) {
		wp_set_current_user( $admin );
		$check( 'mail logs: on a single site the administrator sees the body as logged', minn_admin_mail_mask_reset_keys( $body ) === $body );
		// A log a plugin opens to other roles hides reset keys from them.
		$ed = get_user_by( 'login', 'minn-editor' );
		if ( $ed ) {
			wp_set_current_user( $ed->ID );
			$out = minn_admin_mail_mask_reset_keys( $body );
			wp_set_current_user( $admin );
			$check( 'mail logs: an Editor reading a mail log never sees a reset key', false === strpos( $out, 'AbCdEf123456' ) && false === strpos( $out, 'ZyXw987654' ), $out );
		}
		return;
	}
	$sub   = wp_insert_user( array( 'user_login' => "minn-rc-siteadmin-{$tag}", 'user_email' => "minn-rc-siteadmin-{$tag}@example.com", 'user_pass' => wp_generate_password( 24 ), 'role' => 'administrator' ) );
	$clean = $once( function () use ( $sub, $admin ) {
		wp_set_current_user( $admin );
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		if ( is_int( $sub ) && $sub > 1 ) {
			wpmu_delete_user( $sub );
		}
	} );
	try {
		wp_set_current_user( (int) $sub );
		$out = minn_admin_mail_mask_reset_keys( $body );
		$check( 'mail logs: a site administrator on a network never sees a reset key', false === strpos( $out, 'AbCdEf123456' ) && false === strpos( $out, 'ZyXw987654' ) && false === strpos( $out, 'Wc0011223344' ), $out );
		$check( 'mail logs: control: other links keep their key', false !== strpos( $out, 'wc_order_keepme' ) );
		// A resend to another address would carry the key out whole.
		if ( function_exists( 'minn_admin_fluent_smtp_resend' ) ) {
			$req = new WP_REST_Request( 'POST', '/minn-admin/v1/fluent-smtp/emails/1/resend' );
			$req->set_param( 'to', "minn-rc-catch-{$tag}@example.com" );
			$sent  = 0;
			$catch = function () use ( &$sent ) {
				++$sent;
				return true;
			};
			add_filter( 'pre_wp_mail', $catch, PHP_INT_MAX );
			$r = minn_admin_fluent_smtp_resend( 1, array( 'to' => 'root@example.com', 'subject' => 'Password Reset', 'body' => $body ), $req );
			remove_filter( 'pre_wp_mail', $catch, PHP_INT_MAX );
			$check( 'mail logs: a reset email cannot be resent to another address by a site administrator', is_wp_error( $r ) && 'minn_mail_reset_link' === $r->get_error_code() && 0 === $sent, is_wp_error( $r ) ? $r->get_error_code() : 'sent' );
		}
		wp_set_current_user( $admin );
		$check( 'mail logs: control: the install owner sees the body as logged', ! Minn_Admin::install_owner() || minn_admin_mail_mask_reset_keys( $body ) === $body );
	} finally {
		$clean();
	}
} )();

/*
 * 08-M2 (fix delta): every mail log's item route hands its body through the
 * mask, not only the detail sections. A source check, run anywhere: a log
 * whose item serves a 'message' must build it with the mask.
 */
( function () use ( $check ) {
	$open = array();
	foreach ( array( 'fluent-smtp', 'wp-mail-logging', 'post-smtp', 'gravity-smtp', 'suremails', 'site-mailer' ) as $a ) {
		$src = (string) file_get_contents( MINN_ADMIN_DIR . "includes/adapters/{$a}.php" );
		if ( preg_match_all( "/'message'\s*\]?\s*=\s*>?\s*(.+);?$/m", $src, $m ) ) {
			foreach ( $m[1] as $rhs ) {
				if ( preg_match( '/\$r\[/', $rhs ) && false === strpos( $rhs, 'minn_admin_mail_mask_reset_keys' ) ) {
					$open[] = $a . ': ' . trim( $rhs );
				}
			}
		}
	}
	$check( 'mail logs: every item route masks the body it serves', ! $open, implode( ' | ', $open ) );
} )();

/*
 * 08-L3, 09-L1, 04-L1: credential stores no adapter declared print redacted
 * in the database browser. A row that is not there yet is made with a fake
 * key and removed; a row that is there is read, never written.
 */
( function () use ( $check, $skip, $once, $admin ) {
	global $wpdb;
	wp_set_current_user( $admin );
	if ( ! method_exists( 'Minn_Admin_REST', 'can_read_system' ) || ! Minn_Admin_REST::can_read_system() ) {
		$skip( 'credential rows: the database browser is closed to this user here' );
		return;
	}
	$fake  = 'minnRCkey' . wp_rand( 100000, 999999 ) . 'Qz7Lw9Pa';
	$rows  = array( 'backwpup_cfg_jobrunauthkey', 'backwpup_jobs', 'wpvivid_upload_setting', 'srfm_security_settings_options', 'srfm_options', 'frm_options', 'plausible_analytics_settings', 'ss_podcasting_podmotor_account_api_token', 'powerpress_creds', 'powerpress_general', 'wp_2fa_secret_key' );
	$made  = array();
	$meta  = 0;
	$clean = $once( function () use ( &$made, &$meta, $admin ) {
		foreach ( $made as $name ) {
			delete_option( $name );
		}
		if ( $meta ) {
			delete_user_meta( $admin, 'wp_2fa_totp_key' );
		}
	} );
	$cell = function ( $table, $fcol, $fq, $vcol ) {
		$r = new WP_REST_Request( 'GET', '/minn-admin/v1/db/rows' );
		$r->set_param( 'table', $table );
		$r->set_param( 'fcol', $fcol );
		$r->set_param( 'fq', $fq );
		$data = rest_do_request( $r )->get_data();
		$cols = wp_list_pluck( (array) ( $data['columns'] ?? array() ), 'name' );
		foreach ( (array) ( $data['rows'] ?? array() ) as $row ) {
			if ( $fq === $row[ array_search( $fcol, $cols, true ) ] ) {
				return $row[ array_search( $vcol, $cols, true ) ];
			}
		}
		return 'missing';
	};
	try {
		$open = array();
		foreach ( $rows as $name ) {
			if ( false === get_option( $name ) ) {
				add_option( $name, $fake, '', false );
				$made[] = $name;
			}
			$c = $cell( $wpdb->options, 'option_name', $name, 'option_value' );
			if ( ! ( is_array( $c ) && ! empty( $c['redacted'] ) ) ) {
				$open[] = $name . ( 'missing' === $c ? ' (missing)' : '' );
			}
		}
		$check( 'credential rows: every newly declared row prints redacted in the database browser', ! $open, implode( ', ', $open ) );
		if ( ! get_user_meta( $admin, 'wp_2fa_totp_key', true ) ) {
			$meta = add_user_meta( $admin, 'wp_2fa_totp_key', $fake, true ) ? 1 : 0;
		}
		$c = $cell( $wpdb->usermeta, 'meta_key', 'wp_2fa_totp_key', 'meta_value' );
		$check( 'credential rows: WP 2FA\'s authenticator seed prints redacted', is_array( $c ) && ! empty( $c['redacted'] ), is_array( $c ) ? 'not redacted' : (string) $c );
	} finally {
		$clean();
	}
} )();

/*
 * 07-L1, 09-L2: code that goes into every page answers to the code-edits
 * directive. This defines DISALLOW_FILE_EDIT, which cannot be undone in
 * this process, so it runs last.
 */
( function () use ( $check, $skip, $admin ) {
	wp_set_current_user( $admin );
	if ( ! Minn_Admin::code_edits_allowed() ) {
		$skip( 'code edits: this site already disallows them, so there is no before' );
		return;
	}
	$ccj_inline = array( 'language' => 'css', 'side' => 'frontend', 'linking' => 'internal' );
	$ccj_linked = array( 'language' => 'css', 'side' => 'frontend', 'linking' => 'external' );
	$has_ccj    = function_exists( 'minn_admin_ccj_can_write_code' );
	$has_wpcode = function_exists( 'minn_admin_wpcode_guard_type' ) && function_exists( 'wpcode' );
	$wpcode_ok  = function ( $type ) {
		$g = minn_admin_wpcode_guard_type( $type );
		return ! is_wp_error( $g );
	};
	$before_ccj    = $has_ccj ? minn_admin_ccj_can_write_code( $ccj_inline ) : null;
	$before_wpcode = $has_wpcode ? $wpcode_ok( 'html' ) : null;
	define( 'DISALLOW_FILE_EDIT', true );
	if ( $has_ccj ) {
		$check( 'code edits: inline CSS (which can close its <style>) is refused when code edits are off', true === $before_ccj && false === minn_admin_ccj_can_write_code( $ccj_inline ) );
		$check( 'code edits: control: linked front end CSS stays open', true === minn_admin_ccj_can_write_code( $ccj_linked ) );
		$check( 'code edits: taking inline CSS out (a delete) stays open when code edits are off', true === minn_admin_ccj_can_write_code( $ccj_inline, false ) );
		$err = minn_admin_ccj_code_error( $ccj_inline );
		$check( 'code edits: the refusal names the setting, not a capability', false !== strpos( $err->get_error_message(), 'disallows editing code' ), $err->get_error_message() );
	} else {
		$skip( 'code edits: custom-css-js adapter not loaded' );
	}
	if ( $has_wpcode ) {
		$after = minn_admin_wpcode_guard_type( 'html' );
		$check( 'code edits: a WPCode HTML snippet is refused when code edits are off', true === $before_wpcode && is_wp_error( $after ) && false !== strpos( $after->get_error_message(), 'disallows editing code' ), is_wp_error( $after ) ? $after->get_error_message() : 'allowed' );
		$check( 'code edits: control: a WPCode text snippet is still filtered, not refused', $wpcode_ok( 'text' ) );
	} else {
		$skip( 'code edits: WPCode inactive' );
	}
	if ( function_exists( 'minn_admin_bricks_strip_unfiltered_html' ) ) {
		$kept = minn_admin_bricks_strip_unfiltered_html( array( 'customScriptsHeader' => '<script>x</script>', 'other' => 1 ), 0 );
		$check( 'code edits: Bricks page scripts are dropped from a save when code edits are off', ! isset( $kept['customScriptsHeader'] ) && 1 === ( $kept['other'] ?? null ), wp_json_encode( $kept ) );
	}
} )();

/*
 * Fix delta: Ninja Forms passwords, JetEngine raw formats.
 */
( function () use ( $check, $admin ) {
	if ( function_exists( 'minn_admin_ninja_forms_skip_types' ) ) {
		$skip = minn_admin_ninja_forms_skip_types();
		$check( 'Ninja Forms: password answers are never shown', in_array( 'password', $skip, true ) && in_array( 'passwordconfirm', $skip, true ) );
	}
	if ( function_exists( 'minn_admin_jet_value_in' ) ) {
		$deny = function ( $caps, $cap ) {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		wp_set_current_user( $admin );
		add_filter( 'map_meta_cap', $deny, 99, 2 );
		$in = minn_admin_jet_value_in( array( 'name' => 'x', 'type' => 'textarea', '_jet' => array( 'type' => 'textarea', 'value_format' => 'legacy' ) ), 'a <script>b</script>' );
		remove_filter( 'map_meta_cap', $deny, 99 );
		$check( 'JetEngine: any format JetEngine stores raw (not only "raw") is kses\'d', is_string( $in ) && false === stripos( $in, '<script' ), (string) $in );
	}
} )();

wp_set_current_user( $admin );
$failed = count( array_filter( $results, function ( $ok ) {
	return ! $ok;
} ) );
echo "\nsecurity-v047-rc-adapters: " . ( count( $results ) - $failed ) . '/' . count( $results ) . " passed\n";
if ( $failed ) {
	exit( 1 );
}
