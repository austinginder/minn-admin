<?php
/**
 * RC v0.46.0 audit, egress: a value a minn-admin/v1 read showed with a
 * credential masked never writes the mask, and a mask goes back only to the
 * place that held its credential.
 *
 * Every minn-admin/v1 response shows each known credential as
 * "[redacted:<fp>]". The surface edit dialogs send each untouched field as
 * the read served it, so a snippet whose code holds a copy of a credential
 * came back with the mask in it, and a rename wrote the mask into live code
 * (12-1). On the option side the mask was restored anywhere in the row, so
 * a body that moved it under another name got the credential written where
 * the fence no longer recognises it (04-2). The credential list was never
 * read again after a credential row changed (04-3).
 *
 * The suite seeds credential rows of its own (licence key and licence
 * options rows, by the licences screen's name shapes) holding made-up
 * keys, and snippets, a memory and an Elementor template holding copies of
 * them. Sections SKIP when their plugin is inactive (HFCM runs on
 * builders.localhost and minndemo.localhost, Custom CSS & JS on minndemo;
 * without a Novamira Pro licence the memory section stands in for its post
 * type; Bricks runs on builders.localhost; the redirect plugins and Custom
 * CSS & JS load for the one process through tests/lib/plugin-swap.php).
 * Everything it makes is removed however the run ends.
 *
 * Run: MINN_SWAP_ADD=custom-css-js/custom-css-js.php,eps-301-redirects/eps-301-redirects.php,simple-301-redirects/wp-simple-301-redirects.php \
 *      wp eval-file <absolute path>/tests/security-v046-rc-egress.test.php --user=admin --path=<site> \
 *      --require=<absolute path>/tests/lib/plugin-swap.php
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

if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Minn_Admin_Egress' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}
global $wpdb;
rest_get_server();
$tag = strtolower( wp_generate_password( 6, false ) );

// What a browser receives: dispatch, the server's response filters, the
// body as sent (the egress fence runs on rest_pre_echo_response).
$send = function ( $method, $route, $body = null, $nonce = false ) {
	$server = rest_get_server();
	$req    = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	if ( $nonce ) {
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	}
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	return array( $res->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
};
$fp   = function ( $k ) {
	return '[redacted:' . substr( hash( 'sha256', $k ), 0, 6 ) . ']';
};
$has  = function ( $hay, $needle ) {
	return false !== strpos( (string) $hay, (string) $needle );
};
// The dialog's body: every field the read served, untouched, but these.
$dialog = function ( array $item, array $edit ) {
	foreach ( array( 'id', 'scope', 'modified', 'label', 'typeText', 'updated' ) as $k ) {
		unset( $item[ $k ] );
	}
	return array_merge( $item, $edit );
};

// The credential rows, seeded before anything reads the list (it is read
// once per request). Names follow the licences screen's shapes, so the
// fence treats them as licence rows; no plugin reads them.
$key   = 'MinnRcEgress' . strtoupper( wp_generate_password( 10, false ) ) . '4217zq';
$key2  = 'MinnRcSecond' . strtoupper( wp_generate_password( 10, false ) ) . '9031qz';
$key3  = 'MinnRcThird' . strtoupper( wp_generate_password( 10, false ) ) . '5520rk';
$row1  = 'pue_install_key_minnrc' . $tag;
$row2  = 'minnrc' . $tag . '_license_options';
$row3  = 'minnrc' . $tag . 'b_license_options';
$row4  = 'pue_install_key_minnrc' . $tag . 'late';
$plain = 'minn_rc_egress_plain_' . $tag;
$t1    = $fp( $key );
$t2    = $fp( $key2 );
$t3    = $fp( $key3 );
$row2_was = array( 'license_key' => $key2, 'title' => 'x' );
$row3_was = array( 'config' => wp_json_encode( array( 'api_key' => $key3, 'name' => 'a' ) ) );
$seed_clean = $once( function () use ( $row1, $row2, $row3, $row4, $plain ) {
	foreach ( array( $row1, $row2, $row3, $row4, $plain ) as $name ) {
		delete_option( $name );
	}
	Minn_Admin_Egress::reset();
} );
add_option( $row1, $key, '', 'no' );
add_option( $row2, $row2_was, '', 'no' );
add_option( $row3, $row3_was, '', 'no' );
Minn_Admin_Egress::reset();

register_rest_route( 'minn-admin/v1', '/minn-rc-egress-test/save', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( $req ) use ( $row2, $row3 ) {
	$sent = $req->get_json_params();
	$name = 'row3' === ( $sent['row'] ?? '' ) ? $row3 : $row2;
	update_option( $name, $sent['value'] );
	return array( 'saved' => true );
} ) );
register_rest_route( 'minn-admin/v1', '/minn-rc-egress-test/read', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () use ( $row4 ) {
	return array( 'late' => (string) get_option( $row4 ) );
} ) );

try {
	/*
	 * 04-2: a mask goes back only to the place that held its credential.
	 */
	$save = function ( $value, $row = 'row2' ) use ( $send ) {
		return $send( 'POST', '/minn-admin/v1/minn-rc-egress-test/save', array( 'row' => $row, 'value' => $value ) );
	};
	$back = function () use ( $row2, $row2_was, $row3, $row3_was ) {
		update_option( $row2, $row2_was );
		update_option( $row3, $row3_was );
	};

	list( $st ) = $save( array( 'license_key' => $t2, 'title' => 'y' ) );
	$now        = get_option( $row2 );
	$check( '04-2 control: a key sent back where it was keeps the stored key, and the rest of the save lands', 200 === $st && $key2 === ( $now['license_key'] ?? '' ) && 'y' === ( $now['title'] ?? '' ), $st . ' ' . wp_json_encode( $now ) );
	$back();

	list( $st, $d ) = $save( array( 'license_key' => '', 'title' => $t2 ) );
	$now            = get_option( $row2 );
	$check( '04-2: a mask moved to another key is refused, never written there as the key', ! $has( $now['title'] ?? '', $key2 ) && $key2 === ( $now['license_key'] ?? '' ) && 'x' === ( $now['title'] ?? '' ), wp_json_encode( $now ) );
	$check( '04-2: and the save answers 400 saying so', 400 === $st && 'minn_egress_mask_refused' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
	$back();

	list( $st ) = $save( array( 'license_key' => $t2 . ' note', 'title' => 'x' ) );
	$now        = get_option( $row2 );
	$check( '04-2: text typed beside the mask is refused (the key would stop counting as one)', 400 === $st && $key2 === ( $now['license_key'] ?? '' ), $st . ' ' . wp_json_encode( $now ) );
	$back();

	list( $st ) = $save( array( 'license_key' => $t1, 'title' => 'x' ) );
	$now        = get_option( $row2 );
	$check( '04-2: another row\'s mask never replaces this row\'s key with the placeholder', 400 === $st && $key2 === ( $now['license_key'] ?? '' ), $st . ' ' . wp_json_encode( $now ) );
	$back();

	list( $st ) = $save( array( 'license_key' => $key2, $t2 => 'active', 'title' => 'x' ) );
	$now        = get_option( $row2 );
	$check( '04-2: a mask as an array key is refused', 400 === $st && ! array_key_exists( $t2, (array) $now ), $st . ' ' . wp_json_encode( $now ) );
	$back();

	list( $st ) = $save( array( 'config' => wp_json_encode( array( 'api_key' => '', 'name' => $t3 ) ) ), 'row3' );
	$now        = get_option( $row3 );
	$check( '04-2: a mask moved inside a JSON string, to a key the fence does not count, is refused', 400 === $st && $row3_was === $now, $st . ' ' . wp_json_encode( $now ) );
	$back();

	list( $st ) = $save( array( 'config' => wp_json_encode( array( 'api_key' => $t3, 'name' => 'b' ) ) ), 'row3' );
	$conf       = json_decode( (string) ( get_option( $row3 )['config'] ?? '' ), true );
	$check( '04-2 control: the same JSON string saved with the key in place keeps it', 200 === $st && $key3 === ( $conf['api_key'] ?? '' ) && 'b' === ( $conf['name'] ?? '' ), $st . ' ' . wp_json_encode( $conf ) );
	$back();

	$typed      = 'MinnRcTyped' . strtoupper( wp_generate_password( 10, false ) ) . '8812';
	list( $st ) = $save( array( 'license_key' => $typed, 'title' => 'x' ) );
	$check( '04-2 control: a new key typed in is saved', 200 === $st && $typed === ( get_option( $row2 )['license_key'] ?? '' ) );
	$back();

	update_option( $row2, array( 'license_key' => $key2, 'title' => 'see ' . $t1 ) );
	list( $st ) = $save( array( 'license_key' => $t2, 'title' => 'see ' . $t1, 'more' => 'z' ) );
	$now        = get_option( $row2 );
	$check( '04-2 control: a mask the row already holds as text saves back as it is', 200 === $st && $key2 === ( $now['license_key'] ?? '' ) && 'see ' . $t1 === ( $now['title'] ?? '' ) && 'z' === ( $now['more'] ?? '' ), $st . ' ' . wp_json_encode( $now ) );
	$back();

	/*
	 * 12-1: a field sent back as the read served it writes nothing; a write
	 * that would store a mask is refused.
	 */
	$check( '12-1: a value equal to the stored one masked counts as unchanged', Minn_Admin::unchanged( 'a ' . $t1 . ' b', 'a ' . $key . ' b' ) );
	$check( '12-1: one equal to only part of it masked does not', ! Minn_Admin::unchanged( $t1, 'a ' . $key . ' b' ) && ! Minn_Admin::unchanged( 'a ' . $t2 . ' b', 'a ' . $key . ' b' ) );
	$check( '12-1: nor does a mask with no credential behind it', ! Minn_Admin::unchanged( 'x ' . $t1, 'x ' ) );
	$check( '12-1: a structured value compares the same way', Minn_Admin::unchanged( array( 'k' => $t1 ), array( 'k' => $key ) ) && ! Minn_Admin::unchanged( array( 'k' => $t1 ), array( 'k' => 'other' ) ) );

	// WPCode: an html snippet (never executed), switched off.
	( function () use ( $check, $skip, $send, $once, $dialog, $has, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_wpcode_active' ) || ! minn_admin_wpcode_active() ) {
			$skip( '12-1 WPCode: inactive' );
			return;
		}
		$id    = 0;
		$clean = $once( function () use ( &$id ) {
			if ( is_int( $id ) && $id > 1 && 'wpcode' === get_post_type( $id ) ) {
				wp_delete_post( $id, true );
			}
		} );
		try {
			$code           = "<!-- minn rc egress {$key} -->\n<p>hi</p>";
			list( $st, $d ) = $send( 'POST', '/minn-admin/v1/wpcode/snippets', array( 'name' => 'Minn rc egress ' . $tag, 'code' => $code, 'code_type' => 'html', 'location' => 'site_wide_footer', 'auto_insert' => true, 'priority' => 10, 'tags' => array(), 'active' => false ) );
			$id             = (int) ( $d['id'] ?? 0 );
			if ( $id < 2 ) {
				// A lab whose administrator may not write code here (no
				// unfiltered_html, file edits off) has nothing to test.
				if ( 403 === $st ) {
					$skip( '12-1 WPCode: this site refuses its administrator code writes (' . ( $d['code'] ?? '' ) . ')' );
					return;
				}
				$check( '12-1 WPCode: the test snippet was created', false, $st . ' ' . wp_json_encode( $d ) );
				return;
			}
			$stored = function () use ( $id ) {
				clean_post_cache( $id );
				return (string) get_post_field( 'post_content', $id );
			};
			list( , $item ) = $send( 'GET', "/minn-admin/v1/wpcode/snippets/{$id}" );
			$check( '12-1 WPCode: the read shows the copy masked (the fence works)', $has( $item['code'] ?? '', $t1 ) && ! $has( $item['code'] ?? '', $key ), wp_json_encode( $item['code'] ?? null ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", $dialog( $item, array( 'name' => 'Minn rc egress renamed ' . $tag, 'priority' => 20 ) ) );
			$check( '12-1 WPCode: a rename leaves the code holding the real key, not the mask', $has( $stored(), $key ) && ! $has( $stored(), '[redacted' ), $st . ' ' . $stored() );
			$check( '12-1 WPCode: and the rename lands (control)', 200 === $st && 'Minn rc egress renamed ' . $tag === get_the_title( $id ) );
			list( $st, $d ) = $send( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", $dialog( $item, array( 'code' => $item['code'] . "\n<!-- edited -->" ) ) );
			$check( '12-1 WPCode: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $has( $stored(), $key ) && ! $has( $stored(), 'edited' ), $st . ' ' . $stored() );
			list( $st, $d ) = $send( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", $dialog( $item, array( 'code_type' => 'text' ) ) );
			$check( '12-1 WPCode: a retype with the code untouched keeps the real key', 200 === $st && $has( $stored(), $key ) && ! $has( $stored(), '[redacted' ), $st . ' ' . $stored() );
			list( , $item ) = $send( 'GET', "/minn-admin/v1/wpcode/snippets/{$id}" );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", $dialog( $item, array( 'code' => str_replace( $t1, $key, (string) $item['code'] ) . "\n<!-- edited -->" ) ) );
			$check( '12-1 WPCode control: an edit with the real value typed back is written', 200 === $st && $has( $stored(), $key ) && $has( $stored(), 'edited' ), $st . ' ' . $stored() );
			list( $st, $d ) = $send( 'POST', '/minn-admin/v1/wpcode/snippets', array( 'name' => 'Minn rc egress copy ' . $tag, 'code' => '<!-- ' . $t1 . ' -->', 'code_type' => 'html', 'location' => 'site_wide_footer', 'active' => false ) );
			$copy           = (int) ( $d['id'] ?? 0 );
			if ( $copy > 1 && 'wpcode' === get_post_type( $copy ) ) {
				wp_delete_post( $copy, true );
			}
			$check( '12-1 WPCode: a new snippet carrying a mask is refused', 400 === $st && $copy < 1, $st . ' ' . wp_json_encode( $d ) );
		} finally {
			$clean();
		}
	} )();

	// FluentSnippets: a CSS snippet, switched off.
	( function () use ( $check, $skip, $send, $once, $dialog, $has, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_fluent_ready' ) || ! minn_admin_fluent_ready() ) {
			$skip( '12-1 FluentSnippets: inactive' );
			return;
		}
		$file  = '';
		$clean = $once( function () use ( &$file, $send ) {
			if ( '' !== $file ) {
				$send( 'DELETE', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) );
			}
		} );
		try {
			list( $st, $d ) = $send( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn rc egress ' . $tag, 'code' => "/* {$key} */\n.minn-rc-egress{}", 'type' => 'css', 'run_at' => 'wp_head', 'active' => false ) );
			$file           = (string) ( $d['id'] ?? '' );
			if ( '' === $file ) {
				// A lab whose administrator may not write code here (no
				// unfiltered_html, file edits off) has nothing to test.
				if ( 403 === $st ) {
					$skip( '12-1 FluentSnippets: this site refuses its administrator code writes (' . ( $d['code'] ?? '' ) . ')' );
					return;
				}
				$check( '12-1 FluentSnippets: the test snippet was created', false, $st . ' ' . wp_json_encode( $d ) );
				return;
			}
			$stored         = function () use ( $file ) {
				$now = minn_admin_fluent_load( $file );
				return is_wp_error( $now ) ? '' : (string) $now[2];
			};
			list( , $item ) = $send( 'GET', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) );
			$check( '12-1 FluentSnippets: the read shows the copy masked', $has( $item['code'] ?? '', $t1 ), wp_json_encode( $item['code'] ?? null ) );
			list( $st ) = $send( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), $dialog( $item, array( 'name' => 'Minn rc egress renamed ' . $tag ) ) );
			$check( '12-1 FluentSnippets: a rename leaves the file holding the real key', 200 === $st && $has( $stored(), $key ) && ! $has( $stored(), '[redacted' ), $st . ' ' . $stored() );
			list( $st ) = $send( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), $dialog( $item, array( 'code' => $item['code'] . "\n.edited{}" ) ) );
			$check( '12-1 FluentSnippets: an edit that keeps the mask is refused and writes nothing', 400 === $st && $has( $stored(), $key ) && ! $has( $stored(), 'edited' ), $st . ' ' . $stored() );
		} finally {
			$clean();
		}
	} )();

	// Custom CSS & JS: an active frontend CSS snippet, so the live file is written.
	( function () use ( $check, $skip, $send, $once, $dialog, $has, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_ccj_obj_can' ) || ! post_type_exists( 'custom-css-js' ) ) {
			$skip( '12-1 Custom CSS & JS: inactive' );
			return;
		}
		$id    = 0;
		$clean = $once( function () use ( &$id, $send ) {
			if ( is_int( $id ) && $id > 1 && 'custom-css-js' === get_post_type( $id ) ) {
				$send( 'DELETE', "/minn-admin/v1/ccj/snippets/{$id}" );
				if ( get_post( $id ) ) {
					wp_delete_post( $id, true );
				}
				if ( defined( 'CCJ_UPLOAD_DIR' ) && is_file( CCJ_UPLOAD_DIR . '/' . $id . '.css' ) ) {
					wp_delete_file( CCJ_UPLOAD_DIR . '/' . $id . '.css' );
				}
			}
		} );
		try {
			list( $st, $d ) = $send( 'POST', '/minn-admin/v1/ccj/snippets', array( 'name' => 'Minn rc egress ' . $tag, 'code' => "/* {$key} */\n.minn-rc-egress{}", 'language' => 'css', 'type' => 'header', 'side' => 'frontend', 'linking' => 'internal', 'active' => true ) );
			$id             = (int) ( $d['id'] ?? 0 );
			if ( $id < 2 ) {
				// A lab whose administrator may not write code here (no
				// unfiltered_html, file edits off) has nothing to test.
				if ( 403 === $st ) {
					$skip( '12-1 Custom CSS & JS: this site refuses its administrator code writes (' . ( $d['code'] ?? '' ) . ')' );
					return;
				}
				$check( '12-1 Custom CSS & JS: the test snippet was created', false, $st . ' ' . wp_json_encode( $d ) );
				return;
			}
			$stored = function () use ( $id ) {
				clean_post_cache( $id );
				return (string) get_post_field( 'post_content', $id );
			};
			$file   = function () use ( $id ) {
				$path = defined( 'CCJ_UPLOAD_DIR' ) ? CCJ_UPLOAD_DIR . '/' . $id . '.css' : '';
				return '' !== $path && is_file( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			};
			list( , $item ) = $send( 'GET', "/minn-admin/v1/ccj/snippets/{$id}" );
			$check( '12-1 Custom CSS & JS: the read shows the copy masked', $has( $item['code'] ?? '', $t1 ), wp_json_encode( $item['code'] ?? null ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/ccj/snippets/{$id}", $dialog( $item, array( 'name' => 'Minn rc egress renamed ' . $tag ) ) );
			$check( '12-1 Custom CSS & JS: a rename leaves the code holding the real key', 200 === $st && $has( $stored(), $key ) && ! $has( $stored(), '[redacted' ), $st . ' ' . $stored() );
			$check( '12-1 Custom CSS & JS: and the live file too', ! $has( $file(), '[redacted' ), $file() );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/ccj/snippets/{$id}", $dialog( $item, array( 'code' => $item['code'] . "\n.edited{}" ) ) );
			$check( '12-1 Custom CSS & JS: an edit that keeps the mask is refused and writes nothing', 400 === $st && $has( $stored(), $key ) && ! $has( $stored(), 'edited' ) && ! $has( $file(), '[redacted' ), $st . ' ' . $stored() );
		} finally {
			$clean();
		}
	} )();

	// Header Footer Code Manager: an html snippet, switched off.
	( function () use ( $check, $skip, $send, $once, $dialog, $has, $key, $t1, $tag ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_hfcm_active' ) || ! minn_admin_hfcm_active() ) {
			$skip( '12-1 HFCM: inactive (it runs on builders.localhost)' );
			return;
		}
		$id    = 0;
		$table = $wpdb->prefix . 'hfcm_scripts';
		$clean = $once( function () use ( &$id, $table, $tag ) {
			global $wpdb;
			if ( is_int( $id ) && $id > 0 ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE script_id = %d AND name LIKE %s", $id, '%' . $wpdb->esc_like( $tag ) ) ); // phpcs:ignore WordPress.DB
			}
		} );
		try {
			list( $st, $d ) = $send( 'POST', '/minn-admin/v1/hfcm/snippets', array( 'name' => 'Minn rc egress ' . $tag, 'code' => "<!-- {$key} -->", 'snippet_type' => 'html', 'location' => 'footer', 'device_type' => 'both', 'active' => false ) );
			$id             = (int) ( $d['id'] ?? 0 );
			if ( $id < 1 ) {
				// A lab whose administrator may not write code here (no
				// unfiltered_html, file edits off) has nothing to test.
				if ( 403 === $st ) {
					$skip( '12-1 HFCM: this site refuses its administrator code writes (' . ( $d['code'] ?? '' ) . ')' );
					return;
				}
				$check( '12-1 HFCM: the test snippet was created', false, $st . ' ' . wp_json_encode( $d ) );
				return;
			}
			$stored = function () use ( $id, $table ) {
				global $wpdb;
				return (string) $wpdb->get_var( $wpdb->prepare( "SELECT snippet FROM `{$table}` WHERE script_id = %d", $id ) ); // phpcs:ignore WordPress.DB
			};
			list( , $item ) = $send( 'GET', "/minn-admin/v1/hfcm/snippets/{$id}" );
			$check( '12-1 HFCM: the read shows the copy masked', $has( $item['code'] ?? '', $t1 ), wp_json_encode( $item['code'] ?? null ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/hfcm/snippets/{$id}", $dialog( $item, array( 'name' => 'Minn rc egress renamed ' . $tag ) ) );
			$check( '12-1 HFCM: a rename leaves the code holding the real key', 200 === $st && $has( $stored(), $key ) && ! $has( $stored(), '[redacted' ), $st . ' ' . $stored() );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/hfcm/snippets/{$id}", $dialog( $item, array( 'code' => $item['code'] . '<!-- edited -->' ) ) );
			$check( '12-1 HFCM: an edit that keeps the mask is refused and writes nothing', 400 === $st && $has( $stored(), $key ) && ! $has( $stored(), 'edited' ), $st . ' ' . $stored() );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/hfcm/snippets/{$id}", $dialog( $item, array( 'code' => "<!-- {$key} --><!-- edited -->" ) ) );
			$check( '12-1 HFCM control: an edit with the real value is written', 200 === $st && $has( $stored(), 'edited' ), $st . ' ' . $stored() );
		} finally {
			$clean();
		}
	} )();

	// Novamira memory: only the type changes.
	( function () use ( $check, $skip, $send, $once, $has, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_novamira_active' ) || ! minn_admin_novamira_active() || ! function_exists( 'minn_admin_novamira_can' ) || ! minn_admin_novamira_can() ) {
			$skip( '12-1 Novamira memory: inactive' );
			return;
		}
		// Novamira Pro registers its memories only under an active licence.
		// Without one, stand in for the post type and register this
		// adapter's routes again, in this process only.
		$standin = false;
		if ( ! minn_admin_novamira_memory_ready() ) {
			global $wp_filter;
			register_post_type( 'novamira_memory', array( 'public' => false ) );
			$standin = true;
			foreach ( $wp_filter['rest_api_init']->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( $cb['function'] instanceof Closure && '/adapters/novamira.php' === substr( (string) ( new ReflectionFunction( $cb['function'] ) )->getFileName(), -22 ) ) {
						call_user_func( $cb['function'], rest_get_server() );
					}
				}
			}
		}
		$id    = 0;
		$clean = $once( function () use ( &$id, $standin ) {
			if ( is_int( $id ) && $id > 1 && 'novamira_memory' === get_post_type( $id ) ) {
				wp_delete_post( $id, true );
			}
			if ( $standin ) {
				unregister_post_type( 'novamira_memory' );
			}
		} );
		try {
			$id = wp_insert_post( array( 'post_type' => 'novamira_memory', 'post_status' => 'publish', 'post_title' => 'Minn rc egress ' . $tag, 'post_content' => "Call the API with {$key} on staging." ), true );
			$id = is_wp_error( $id ) ? 0 : (int) $id;
			if ( $id < 2 ) {
				$check( '12-1 Novamira memory: the test memory was created', false );
				return;
			}
			update_post_meta( $id, '_novamira_memory_type', 'project' );
			// rest_do_request reads no query string in the path: the whole list.
			list( , $list ) = $send( 'GET', '/minn-admin/v1/novamira/memories' );
			$item           = null;
			foreach ( (array) ( $list['items'] ?? array() ) as $row ) {
				if ( $id === (int) ( $row['id'] ?? 0 ) ) {
					$item = $row;
				}
			}
			if ( ! $item ) {
				$check( '12-1 Novamira memory: the list serves the memory', false, wp_json_encode( $list ) );
				return;
			}
			$check( '12-1 Novamira memory: the read shows the copy masked', $has( $item['content'] ?? '', $t1 ), wp_json_encode( $item['content'] ?? null ) );
			list( $st ) = $send( 'POST', "/minn-admin/v1/novamira/memories/{$id}", array( 'name' => $item['name'], 'description' => $item['description'], 'content' => $item['content'], 'type' => 'reference' ), true );
			clean_post_cache( $id );
			$check( '12-1 Novamira memory: changing only the type leaves the content holding the real key', 200 === $st && $has( get_post_field( 'post_content', $id ), $key ) && ! $has( get_post_field( 'post_content', $id ), '[redacted' ) && 'reference' === get_post_meta( $id, '_novamira_memory_type', true ), $st . ' ' . get_post_field( 'post_content', $id ) );
			list( $st ) = $send( 'POST', "/minn-admin/v1/novamira/memories/{$id}", array( 'name' => $item['name'], 'description' => $item['description'], 'content' => $item['content'] . ' More.', 'type' => 'user' ), true );
			clean_post_cache( $id );
			$check( '12-1 Novamira memory: an edit that keeps the mask is refused and writes nothing, the type included', 400 === $st && $has( get_post_field( 'post_content', $id ), $key ) && 'reference' === get_post_meta( $id, '_novamira_memory_type', true ), $st . ' ' . get_post_field( 'post_content', $id ) );
		} finally {
			$clean();
		}
	} )();

	// Elementor template export: the read-side sibling.
	( function () use ( $check, $skip, $send, $once, $has, $key, $tag ) {
		if ( ! function_exists( 'minn_admin_elementor_template_post' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			$skip( '12-1 Elementor export: inactive' );
			return;
		}
		$ids   = array();
		$clean = $once( function () use ( &$ids ) {
			foreach ( $ids as $id ) {
				if ( is_int( $id ) && $id > 1 && 'elementor_library' === get_post_type( $id ) ) {
					wp_delete_post( $id, true );
				}
			}
		} );
		try {
			$make = function ( $title, $text ) use ( $send, &$ids ) {
				list( , $d ) = $send( 'POST', '/minn-admin/v1/elementor/templates', array( 'title' => $title, 'type' => 'section' ) );
				$id          = (int) ( $d['id'] ?? 0 );
				if ( $id > 1 ) {
					$ids[] = $id;
					update_post_meta( $id, '_elementor_edit_mode', 'builder' );
					update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( array( array( 'id' => 'a1b2c3d', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => $text ), 'elements' => array() ) ) ) ) );
				}
				return $id;
			};
			$with  = $make( 'Minn rc egress ' . $tag, 'Key ' . $key );
			$plain = $make( 'Minn rc egress plain ' . $tag, 'Hello there' );
			if ( $with < 2 || $plain < 2 ) {
				$check( '12-1 Elementor export: the test templates were created', false );
				return;
			}
			list( $st, $d ) = $send( 'GET', "/minn-admin/v1/elementor/templates/{$with}/export" );
			$check( '12-1 Elementor export: a template holding a credential is never handed out as a file with the mask in it', ! $has( $d['content'] ?? '', '[redacted' ) && ! $has( $d['content'] ?? '', $key ), $st . ' ' . substr( (string) ( $d['content'] ?? wp_json_encode( $d ) ), 0, 300 ) );
			$check( '12-1 Elementor export: it is refused with a reason instead', 409 === $st && 'minn_egress_export' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
			list( $st, $d ) = $send( 'GET', "/minn-admin/v1/elementor/templates/{$plain}/export" );
			$file           = json_decode( (string) ( $d['content'] ?? '' ), true );
			$check( '12-1 Elementor export control: a template without one exports', 200 === $st && is_array( $file ) && $has( wp_json_encode( $file ), 'Hello there' ), $st . ' ' . wp_json_encode( $d ) );
		} finally {
			$clean();
		}
	} )();

	/*
	 * Peers of 12-1: other writes fed by a masked read, and other exports.
	 */

	// Additional CSS (core's custom_css post for the active theme).
	( function () use ( $check, $skip, $send, $once, $has, $key, $t1, $tag ) {
		if ( ! function_exists( 'wp_get_custom_css_post' ) || ! current_user_can( 'edit_css' ) ) {
			$skip( '12-1 Additional CSS: the caller may not edit it here' );
			return;
		}
		$post  = wp_get_custom_css_post();
		$had   = $post ? (int) $post->ID : 0;
		$was   = $post ? (string) $post->post_content : '';
		$revs  = $had ? array_map( 'intval', wp_list_pluck( wp_get_post_revisions( $had ), 'ID' ) ) : array();
		$clean = $once( function () use ( $had, $was, $revs ) {
			$now = wp_get_custom_css_post();
			if ( $had ) {
				if ( $now && $was !== (string) $now->post_content ) {
					wp_update_custom_css_post( $was );
				}
				foreach ( wp_get_post_revisions( $had ) as $rev ) {
					if ( ! in_array( (int) $rev->ID, $revs, true ) ) {
						wp_delete_post_revision( $rev->ID );
					}
				}
			} elseif ( $now && 'custom_css' === $now->post_type ) {
				wp_delete_post( $now->ID, true );
				remove_theme_mod( 'custom_css_post_id' );
			}
		} );
		try {
			$seeded = $was . "\n/* minn rc egress {$tag} */\n.minn-rc-egress { background: url(https://example.com/i.png?k={$key}); }\n";
			wp_update_custom_css_post( $seeded );
			$stored = function () {
				$p = wp_get_custom_css_post();
				return $p ? (string) $p->post_content : '';
			};
			list( , $item ) = $send( 'GET', '/minn-admin/v1/custom-css' );
			$check( '12-1 Additional CSS: the read shows the copy masked', $has( $item['css'] ?? '', $t1 ), substr( (string) ( $item['css'] ?? '' ), -160 ) );
			list( $st ) = $send( 'POST', '/minn-admin/v1/custom-css', array( 'css' => $item['css'] ?? '', 'base' => $item['stamp'] ?? '' ) );
			$check( '12-1 Additional CSS: saving it back untouched leaves the stylesheet holding the real key', 200 === $st && $seeded === $stored(), $st . ' ' . substr( $stored(), -160 ) );
			list( $st, $d ) = $send( 'POST', '/minn-admin/v1/custom-css', array( 'css' => ( $item['css'] ?? '' ) . ".minn-rc-edited { color: red; }\n", 'base' => $item['stamp'] ?? '' ) );
			$check( '12-1 Additional CSS: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $seeded === $stored(), $st . ' ' . wp_json_encode( $d['code'] ?? null ) );
			list( , $item ) = $send( 'GET', '/minn-admin/v1/custom-css' );
			list( $st ) = $send( 'POST', '/minn-admin/v1/custom-css', array( 'css' => str_replace( $t1, $key, (string) ( $item['css'] ?? '' ) ) . ".minn-rc-edited { color: red; }\n", 'base' => $item['stamp'] ?? '' ) );
			$check( '12-1 Additional CSS control: an edit with the real value typed back is written', 200 === $st && $has( $stored(), $key ) && $has( $stored(), 'minn-rc-edited' ), (string) $st );
		} finally {
			$clean();
		}
	} )();

	// Bricks templates: the rename dialog and the export.
	( function () use ( $check, $skip, $send, $once, $has, $key, $t1, $tag ) {
		if ( ! defined( 'BRICKS_DB_TEMPLATE_SLUG' ) || ! function_exists( 'minn_admin_bricks_template_item' ) ) {
			$skip( '12-1 Bricks: inactive (it runs on builders.localhost)' );
			return;
		}
		$ids   = array();
		$clean = $once( function () use ( &$ids ) {
			foreach ( $ids as $id ) {
				if ( is_int( $id ) && $id > 1 && BRICKS_DB_TEMPLATE_SLUG === get_post_type( $id ) ) {
					wp_delete_post( $id, true );
				}
			}
		} );
		try {
			$make = function ( $title, $text ) use ( $send, &$ids ) {
				list( $st, $d ) = $send( 'POST', '/minn-admin/v1/bricks/templates', array( 'title' => $title, 'type' => 'section' ) );
				$id             = (int) ( $d['id'] ?? 0 );
				if ( $id > 1 ) {
					$ids[] = $id;
					update_post_meta( $id, BRICKS_DB_PAGE_CONTENT, array( array( 'id' => 'mrcegr', 'name' => 'heading', 'parent' => 0, 'children' => array(), 'settings' => array( 'text' => $text ) ) ) );
				}
				return array( $id, $st, $d );
			};
			list( $with, $st, $d ) = $make( 'Minn rc egress ' . $key, 'Key ' . $key );
			list( $plain )         = $make( 'Minn rc egress plain ' . $tag, 'Hello there' );
			if ( $with < 2 || $plain < 2 ) {
				$check( '12-1 Bricks: the test templates were created', false, $st . ' ' . wp_json_encode( $d ) );
				return;
			}
			$check( '12-1 Bricks: the create answer shows the title masked', $has( $d['title'] ?? '', $t1 ), wp_json_encode( $d['title'] ?? null ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/bricks/templates/{$with}", array( 'title' => $d['title'] ?? '', 'type' => $d['type'] ?? '' ) );
			clean_post_cache( $with );
			$check( '12-1 Bricks: saving the dialog untouched leaves the title holding the real key', 200 === $st && $has( get_post_field( 'post_title', $with ), $key ) && ! $has( get_post_field( 'post_title', $with ), '[redacted' ), $st . ' ' . get_post_field( 'post_title', $with ) );
			list( $st, $e ) = $send( 'GET', "/minn-admin/v1/bricks/templates/{$with}/export" );
			$check( '12-1 Bricks export: a template holding a credential is refused, never handed out masked', 409 === $st && 'minn_egress_export' === ( $e['code'] ?? '' ) && ! $has( wp_json_encode( $e ), '[redacted' ), $st . ' ' . substr( wp_json_encode( $e ), 0, 200 ) );
			list( $st, $e ) = $send( 'GET', "/minn-admin/v1/bricks/templates/{$plain}/export" );
			$check( '12-1 Bricks export control: a template without one exports', 200 === $st && $has( $e['content'] ?? '', 'Hello there' ), $st . ' ' . substr( wp_json_encode( $e ), 0, 200 ) );
		} finally {
			$clean();
		}
	} )();

	// ACF field group builder (the title) and export.
	( function () use ( $check, $skip, $send, $once, $has, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_acf_schema_group' ) || ! function_exists( 'acf_update_field_group' ) ) {
			$skip( '12-1 ACF field groups: ACF inactive' );
			return;
		}
		$gids  = array();
		$clean = $once( function () use ( &$gids ) {
			foreach ( $gids as $gid ) {
				if ( is_int( $gid ) && $gid > 1 && 'acf-field-group' === get_post_type( $gid ) ) {
					acf_delete_field_group( $gid );
				}
			}
		} );
		try {
			$make = function ( $gkey, $title ) use ( &$gids, $tag ) {
				$g   = acf_update_field_group( array( 'key' => $gkey, 'title' => $title, 'active' => false, 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ) ) );
				$gid = is_array( $g ) ? (int) ( $g['ID'] ?? 0 ) : 0;
				if ( $gid > 1 ) {
					$gids[] = $gid;
					acf_update_field( array( 'key' => 'field_' . substr( md5( $gkey ), 0, 12 ), 'label' => 'Note', 'name' => 'minnrcegress_note_' . $tag, 'type' => 'text', 'parent' => $gid ) );
				}
				return $gid;
			};
			$gkey = 'group_minnrcegress' . $tag;
			$pkey = 'group_minnrcegressp' . $tag;
			$gid  = $make( $gkey, 'Minn rc egress ' . $key );
			$pid  = $make( $pkey, 'Minn rc egress plain ' . $tag );
			if ( $gid < 2 || $pid < 2 ) {
				$check( '12-1 ACF field groups: the test groups were created', false );
				return;
			}
			list( , $payload ) = $send( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/full" );
			$check( '12-1 ACF builder: the read shows the title masked', $has( $payload['group']['title'] ?? '', $t1 ), wp_json_encode( $payload['group']['title'] ?? null ) );
			$rows = array();
			foreach ( (array) ( $payload['fields'] ?? array() ) as $f ) {
				$row = array( 'key' => $f['key'] );
				foreach ( array( 'label', 'instructions', 'required', 'default_value', 'placeholder', 'choices', 'min', 'max', 'step', 'rows', 'ui_on_text', 'ui_off_text', 'button_label' ) as $s ) {
					$row[ $s ] = $f[ $s ] ?? null;
				}
				$rows[] = $row;
			}
			$body       = array( 'stamp' => $payload['stamp'] ?? '', 'title' => $payload['group']['title'] ?? '', 'location' => $payload['group']['location'] ?? array(), 'fields' => $rows, 'active' => false );
			list( $st ) = $send( 'POST', "/minn-admin/v1/acf/schema/groups/{$gkey}/full", $body );
			clean_post_cache( $gid );
			$check( '12-1 ACF builder: a save with the title untouched leaves it holding the real key', 200 === $st && $has( get_post_field( 'post_title', $gid ), $key ) && ! $has( get_post_field( 'post_title', $gid ), '[redacted' ), $st . ' ' . get_post_field( 'post_title', $gid ) );
			list( $st, $e ) = $send( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/export" );
			$check( '12-1 ACF export: a group holding a credential is refused, never handed out masked', 409 === $st && 'minn_egress_export' === ( $e['code'] ?? '' ), $st . ' ' . substr( wp_json_encode( $e ), 0, 200 ) );
			list( $st, $e ) = $send( 'GET', "/minn-admin/v1/acf/schema/groups/{$pkey}/export" );
			$check( '12-1 ACF export control: a group without one exports', 200 === $st && $has( $e['content'] ?? '', $pkey ), $st . ' ' . substr( wp_json_encode( $e ), 0, 200 ) );
		} finally {
			$clean();
		}
	} )();

	// ACPT export.
	( function () use ( $check, $skip, $send, $once, $has, $key, $tag ) {
		if ( ! function_exists( 'minn_admin_acpt_active' ) || ! minn_admin_acpt_active() || ! class_exists( '\ACPT\Core\CQRS\Command\SaveMetaGroupCommand' ) ) {
			$skip( '12-1 ACPT export: ACPT inactive' );
			return;
		}
		$gids  = array();
		$clean = $once( function () use ( &$gids ) {
			foreach ( $gids as $gid ) {
				try {
					( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $gid ) )->execute();
				} catch ( \Throwable $e ) {
					echo 'WARN  could not delete the ACPT test group ' . $gid . "\n";
				}
			}
		} );
		try {
			$make  = function ( $suffix, $label ) use ( &$gids, $tag ) {
				$gid = (string) ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
					'name'    => 'minnrcegress' . $suffix . $tag,
					'label'   => $label,
					'belongs' => array( array( 'belongsTo' => \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE, 'operator' => '=', 'find' => 'post', 'logic' => '' ) ),
					'boxes'   => array( array( 'name' => 'minnrcegress_box' . $suffix . $tag, 'label' => 'Box', 'fields' => array( array( 'name' => 'note', 'label' => 'Note', 'type' => 'Text', 'isRequired' => false ) ) ) ),
				) ) )->execute();
				if ( '' !== $gid ) {
					$gids[] = $gid;
				}
				return $gid;
			};
			$with  = $make( 'k', 'Egress ' . $key );
			$plain = $make( 'p', 'Egress plain' );
			if ( '' === $with || '' === $plain ) {
				$check( '12-1 ACPT export: the test groups were created', false );
				return;
			}
			list( $st, $e ) = $send( 'GET', "/minn-admin/v1/acpt/schema/groups/{$with}/export" );
			$check( '12-1 ACPT export: a group holding a credential is refused, never handed out masked', 409 === $st && 'minn_egress_export' === ( $e['code'] ?? '' ), $st . ' ' . substr( wp_json_encode( $e ), 0, 200 ) );
			list( $st, $e ) = $send( 'GET', "/minn-admin/v1/acpt/schema/groups/{$plain}/export" );
			$check( '12-1 ACPT export control: a group without one exports', 200 === $st && $has( $e['content'] ?? '', 'minnrcegressp' . $tag ), $st . ' ' . substr( wp_json_encode( $e ), 0, 200 ) );
		} finally {
			$clean();
		}
	} )();

	// JetThemeCore template rename.
	( function () use ( $check, $skip, $send, $once, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_jet_tc_item' ) || ! post_type_exists( 'jet-theme-core' ) ) {
			$skip( '12-1 JetThemeCore: inactive' );
			return;
		}
		$id    = 0;
		$clean = $once( function () use ( &$id ) {
			if ( is_int( $id ) && $id > 1 && 'jet-theme-core' === get_post_type( $id ) ) {
				wp_delete_post( $id, true );
			}
		} );
		try {
			$id = wp_insert_post( array( 'post_type' => 'jet-theme-core', 'post_status' => 'publish', 'post_title' => 'Minn rc egress ' . $key ), true );
			$id = is_wp_error( $id ) ? 0 : (int) $id;
			if ( $id < 2 ) {
				$check( '12-1 JetThemeCore: the test template was created', false );
				return;
			}
			$served     = 'Minn rc egress ' . $t1;
			list( $st ) = $send( 'PUT', "/minn-admin/v1/jet-theme-core/templates/{$id}", array( 'title' => $served ) );
			clean_post_cache( $id );
			$check( '12-1 JetThemeCore: saving the title back as served leaves it holding the real key', 200 === $st && 'Minn rc egress ' . $key === get_post_field( 'post_title', $id ), $st . ' ' . get_post_field( 'post_title', $id ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/jet-theme-core/templates/{$id}", array( 'title' => $served . ' v2' ) );
			clean_post_cache( $id );
			$check( '12-1 JetThemeCore: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'Minn rc egress ' . $key === get_post_field( 'post_title', $id ), $st . ' ' . get_post_field( 'post_title', $id ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/jet-theme-core/templates/{$id}", array( 'title' => 'Minn rc egress renamed ' . $tag ) );
			clean_post_cache( $id );
			$check( '12-1 JetThemeCore control: a real rename is written', 200 === $st && 'Minn rc egress renamed ' . $tag === get_post_field( 'post_title', $id ) );
		} finally {
			$clean();
		}
	} )();

	// 301 Redirects (EPS): their own table, no restore.
	( function () use ( $check, $skip, $send, $key, $t1, $tag ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_eps301_active' ) || ! minn_admin_eps301_active() || ! Minn_Admin_Kernel_SQL::exists( 'redirects' ) ) {
			$skip( '12-1 301 Redirects: inactive (load it with MINN_SWAP_ADD=eps-301-redirects/eps-301-redirects.php)' );
			return;
		}
		$table = $wpdb->prefix . 'redirects';
		$from  = 'minn-rc-egress-' . $tag;
		$to    = 'https://example.com/dl?key=' . $key;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->insert( $table, array( 'url_from' => $from, 'url_to' => $to, 'status' => '301', 'type' => 'url', 'count' => 0 ) );
			$id = (int) $wpdb->insert_id;
			if ( $id <= 0 ) {
				$check( '12-1 301 Redirects: a rule is seeded', false, $wpdb->last_error );
				return;
			}
			$row          = function () use ( $wpdb, $table, $id ) {
				return $wpdb->get_row( $wpdb->prepare( "SELECT url_from, url_to, status FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
			};
			$served       = minn_admin_eps301_item( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore
			$served['to'] = str_replace( $key, $t1, $served['to'] );
			$route        = '/' . str_replace( '{id}', (string) $id, Minn_Admin_Kernel::action_path( 'eps301/redirects', 'edit' ) );
			list( $st )   = $send( 'PUT', $route, array( 'from' => $served['from'], 'to' => $served['to'], 'status' => '302' ) );
			$now          = $row();
			$check( '12-1 301 Redirects: a status-only edit keeps the target holding the real key', 200 === $st && $to === $now['url_to'] && '302' === $now['status'], $st . ' ' . wp_json_encode( $now ) );
			list( $st )   = $send( 'PUT', $route, array( 'from' => $served['from'], 'to' => $served['to'] . '&x=1', 'status' => '302' ) );
			$now          = $row();
			$check( '12-1 301 Redirects: an edit that keeps the mask is refused and writes nothing', 400 === $st && $to === $now['url_to'], $st . ' ' . wp_json_encode( $now ) );
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
	} )();

	// Simple 301 Redirects: one option, so an edited target gets its key back.
	( function () use ( $check, $skip, $send, $once, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_s301_active' ) || ! minn_admin_s301_active() ) {
			$skip( '12-1 Simple 301 Redirects: inactive (load it with MINN_SWAP_ADD=simple-301-redirects/wp-simple-301-redirects.php)' );
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
			$from          = '/minn-rc-egress-' . $tag;
			$to            = 'https://example.com/caf%C3%A9?key=' . $key;
			$rows          = is_array( $was ) ? $was : array();
			$rows[ $from ] = $to;
			update_option( '301_redirects', $rows );
			$id         = minn_admin_s301_encode_id( $from );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/s301/redirects/{$id}", array( 'from' => $from, 'to' => str_replace( $key, $t1, $to ) ) );
			$now        = (array) get_option( '301_redirects', array() );
			$check( '12-1 Simple 301: saving a rule untouched keeps its target byte for byte (the percent octets too)', 200 === $st && $to === ( $now[ $from ] ?? null ), $st . ' ' . wp_json_encode( $now[ $from ] ?? null ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/s301/redirects/{$id}", array( 'from' => $from, 'to' => 'https://example.com/new?key=' . $t1 ) );
			$now        = (array) get_option( '301_redirects', array() );
			$check( '12-1 Simple 301 control: an edited target beside the mask gets the key back (the option restore)', 200 === $st && 'https://example.com/new?key=' . $key === ( $now[ $from ] ?? null ), $st . ' ' . wp_json_encode( $now[ $from ] ?? null ) );
		} finally {
			$restore();
		}
	} )();

	/*
	 * 04-3: the credential list is read again after a credential row changes.
	 */
	$held = function () {
		$p = new ReflectionProperty( 'Minn_Admin_Egress', 'credentials' );
		if ( PHP_VERSION_ID < 80100 ) {
			$p->setAccessible( true );
		}
		return null !== $p->getValue();
	};
	$send( 'GET', '/minn-admin/v1/minn-rc-egress-test/read' );
	$send( 'GET', '/minn-admin/v1/wpcode/status' );
	// The list is read only for a response that looks keyed; ask it outright.
	Minn_Admin::unchanged( 'x ' . $t1, 'x ' . $key );
	// Rows are named only inside a minn-admin/v1 request, so the writes
	// run in one.
	register_rest_route( 'minn-admin/v1', '/minn-rc-egress-test/write', array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function () use ( $held, $plain, $row4, $key3, $t1, $key ) {
		Minn_Admin::unchanged( 'x ' . $t1, 'x ' . $key );
		// The fence names its cache by the adapters' declarations.
		$ck = 'egress_credentials' . ( class_exists( 'Minn_Admin_Kernel_Secrets' ) ? '_' . Minn_Admin_Kernel_Secrets::hash() : '' );
		wp_cache_set( $ck, array( 'stale' ), 'minn_admin', 60 );
		add_option( $plain, 'plain value', '', 'no' );
		$kept = $held();
		add_option( $row4, $key3 . 'Late7', '', 'no' );
		return array( 'kept' => $kept, 'dropped' => ! $held(), 'cache' => false === wp_cache_get( $ck, 'minn_admin' ) );
	} ) );
	list( , $w ) = $send( 'POST', '/minn-admin/v1/minn-rc-egress-test/write' );
	$check( '04-3 control: an ordinary option changing keeps the list', true === ( $w['kept'] ?? null ), wp_json_encode( $w ) );
	$check( '04-3: a credential row added drops the list held for this request', true === ( $w['dropped'] ?? null ) );
	$check( '04-3: and the object cache\'s copy', true === ( $w['cache'] ?? null ) );
	list( , $d ) = $send( 'GET', '/minn-admin/v1/minn-rc-egress-test/read' );
	$check( '04-3: a key saved earlier in the request is masked in the next response', ! $has( $d['late'] ?? '', $key3 . 'Late7' ), wp_json_encode( $d ) );
} finally {
	$seed_clean();
}

printf( "\nsecurity-v046-rc-egress: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
