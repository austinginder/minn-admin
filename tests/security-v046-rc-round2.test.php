<?php
/**
 * RC v0.46.0, fix-delta review 1 (round 2): what the first round's fixes
 * left open.
 *
 * N1    A credential holding "/" was matched only raw or once escaped, so
 *       JSON text inside a response (an export file, a stored JSON string,
 *       a field holding JSON) carried it as "a\\\/b" past the response
 *       fence and the export refusal, and the database browser's value
 *       search did not leave such a cell out.
 * 12-1  Writes that compared a sent value with the stored one byte for byte:
 *       an untouched field a read served with a credential masked was
 *       written back as "[redacted:…]" (Safe Redirect Manager, Rank Math,
 *       JetEngine content types and suggestions, the ACF and ACPT field
 *       editors and builders, Gravity Forms notifications), and an edited
 *       value still carrying a mask was stored (Contact Form 7 mail, Gravity
 *       Forms confirmations, Fluent Forms and WPForms emails).
 * 01-1  Fluent Forms masks password answers for every viewer, but its
 *       submission tables showed them in the database browser; Gravity
 *       Forms' entry view showed a password its own entry screen skips.
 * Info  N3 (the credential list named rows in requests that are not Minn's),
 *       N5 (a cross-definition attach written after the definition), N7
 *       (Pods answers '' and 0 read as a pass), N8 (Brizy Pro's network
 *       owner), N11 (dead code), /plugin-meta naming network-only plugins.
 *
 * The suite seeds credential rows of its own (licence row shapes, made-up
 * keys, one holding slashes) before anything reads the list, and fixtures
 * holding copies of them. Sections SKIP when their plugin is inactive (Safe
 * Redirect Manager loads for the one process through tests/lib/plugin-swap.php;
 * Rank Math's redirections module through this file's own hooks).
 * Everything it makes is removed however the run ends.
 *
 * Run: MINN_SWAP_ADD=safe-redirect-manager/safe-redirect-manager.php,simple-301-redirects/wp-simple-301-redirects.php \
 *      wp eval-file <absolute path>/tests/security-v046-rc-round2.test.php --user=admin --path=<site> \
 *      --require=<absolute path>/tests/lib/plugin-swap.php --require=<absolute path>/tests/security-v046-rc-round2.test.php
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

if ( ! function_exists( 'rest_get_server' ) ) {
	// Loaded early through --require: Rank Math's redirections module on.
	if ( class_exists( 'WP_CLI' ) ) {
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
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . substr( (string) $detail, 0, 400 ) );
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
$admin = get_current_user_id();
$tag   = strtolower( wp_generate_password( 6, false ) );

// What a browser receives: dispatch, the server's response filters, the
// body as sent (the egress fence runs on rest_pre_echo_response).
$send = function ( $method, $route, $body = null ) {
	$server = rest_get_server();
	$req    = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	return array( $res->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
};
$fp  = function ( $k ) {
	return '[redacted:' . substr( hash( 'sha256', $k ), 0, 6 ) . ']';
};
$has = function ( $hay, $needle ) {
	return false !== strpos( is_string( $hay ) ? $hay : (string) wp_json_encode( $hay ), (string) $needle );
};
$priv = function ( $class, $method, ...$args ) {
	$m = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return $m->invokeArgs( null, $args );
};

// The credential rows (licence row shapes), seeded before anything reads the
// list. $skey holds slashes, as a base64 secret does.
$key   = 'MinnR2Plain' . strtoupper( wp_generate_password( 10, false ) ) . '6412xk';
$skey  = 'MinnR2Slash' . strtoupper( wp_generate_password( 8, false ) ) . '/K7md' . wp_generate_password( 6, false ) . '/Zq88' . strtolower( wp_generate_password( 6, false ) );
$row1  = 'pue_install_key_minnr2' . $tag;
$row2  = 'minnr2' . $tag . '_license_options';
$row3  = 'minnr2' . $tag . 'c_license_options';
$t1    = $fp( $key );
$ts    = $fp( $skey );
$row3_was   = array( 'config' => wp_json_encode( array( 'api_key' => $skey, 'name' => 'a' ) ) );
$seed_clean = $once( function () use ( $row1, $row2, $row3 ) {
	foreach ( array( $row1, $row2, $row3 ) as $name ) {
		delete_option( $name );
	}
	Minn_Admin_Egress::reset();
} );
add_option( $row1, $key, '', 'no' );
add_option( $row2, array( 'license_key' => $skey ), '', 'no' );
add_option( $row3, $row3_was, '', 'no' );
Minn_Admin_Egress::reset();

register_rest_route( 'minn-admin/v1', '/minn-r2-test/json', array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () use ( $skey, $key ) {
	$once  = wp_json_encode( array( 'api_key' => $skey ) );
	$twice = wp_json_encode( array( 'inner' => $once ) );
	return array( 'once' => $once, 'twice' => $twice, 'plain' => 'k=' . $key, 'other' => 'nothing here' );
} ) );
register_rest_route( 'minn-admin/v1', '/minn-r2-test/option', array(
	array( 'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () use ( $row3 ) {
		return array( 'value' => get_option( $row3 ) );
	} ),
	array( 'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ( $req ) use ( $row3 ) {
		update_option( $row3, $req->get_json_params()['value'] ?? null );
		return array( 'saved' => true );
	} ),
) );

try {
	/*
	 * N1: a credential holding "/" inside JSON text.
	 */
	$check( 'N1 an export file (JSON text) holding a key with a slash is refused', null !== Minn_Admin_Egress::refuse_export( wp_json_encode( array( 'settings' => array( 'api_secret' => $skey ) ) ) ) );
	$check( 'N1 so is one whose JSON text holds JSON text holding it', null !== Minn_Admin_Egress::refuse_export( wp_json_encode( array( 'x' => wp_json_encode( array( 'api_secret' => $skey ) ) ) ) ) );
	$check( 'N1 control: an export without a key is not refused', null === Minn_Admin_Egress::refuse_export( wp_json_encode( array( 'settings' => array( 'url' => 'https://example.com/a/b' ) ) ) ) );
	list( $st, $d ) = $send( 'GET', '/minn-admin/v1/minn-r2-test/json' );
	$parts          = explode( '/', $skey );
	$leak           = function ( $text ) use ( $parts ) {
		return false !== strpos( (string) $text, $parts[1] ) || false !== strpos( (string) $text, $parts[0] );
	};
	$check( 'N1 a response field holding JSON text with the key carries no part of it', 200 === $st && ! $leak( $d['once'] ?? '' ) && $has( $d['once'] ?? '', $ts ), wp_json_encode( $d['once'] ?? $d ) );
	$check( 'N1 nor one holding JSON text inside JSON text', ! $leak( $d['twice'] ?? '' ) && $has( $d['twice'] ?? '', $ts ), wp_json_encode( $d['twice'] ?? null ) );
	$check( 'N1 control: the field stays valid JSON with the mask in place', $ts === ( json_decode( (string) ( $d['once'] ?? '' ), true )['api_key'] ?? null ), (string) ( $d['once'] ?? '' ) );
	$check( 'N1 control: a raw key and plain text answer as before', $has( $d['plain'] ?? '', $t1 ) && 'nothing here' === ( $d['other'] ?? null ) );

	// An option holding the key inside a JSON string: the read masks it, and
	// the form sending it back keeps the stored bytes.
	list( , $d ) = $send( 'GET', '/minn-admin/v1/minn-r2-test/option' );
	$served      = $d['value'] ?? array();
	$check( 'N1 an option\'s JSON string holding a slash key reads masked', ! $leak( wp_json_encode( $served ) ) && $has( (string) ( $served['config'] ?? '' ), $ts ), wp_json_encode( $served ) );
	list( $st ) = $send( 'POST', '/minn-admin/v1/minn-r2-test/option', array( 'value' => $served ) );
	$check( 'N1 control: saving that read back answers 200 and keeps the stored bytes', 200 === $st && get_option( $row3 ) === $row3_was, $st . ' ' . wp_json_encode( get_option( $row3 ) ) );
	update_option( $row3, $row3_was );
	$conf       = json_decode( (string) ( $served['config'] ?? '' ), true );
	$conf['name'] = 'b';
	list( $st ) = $send( 'POST', '/minn-admin/v1/minn-r2-test/option', array( 'value' => array( 'config' => wp_json_encode( $conf ) ) ) );
	$now        = json_decode( (string) ( get_option( $row3 )['config'] ?? '' ), true );
	$check( 'N1 control: an edit beside the masked key keeps the key and saves the edit', 200 === $st && $skey === ( $now['api_key'] ?? '' ) && 'b' === ( $now['name'] ?? '' ), $st . ' ' . wp_json_encode( $now ) );
	update_option( $row3, $row3_was );

	// The database browser: a cell holding the key JSON-escaped is a copy.
	if ( method_exists( 'Minn_Admin_DB', 'forget_credentials' ) ) {
		Minn_Admin_DB::forget_credentials();
		$cell = wp_json_encode( array( 'k' => $skey ) );
		$check( 'N1 the database browser counts a JSON-escaped copy of a slash key as a copy (cell redacted, left out of value search)', true === $priv( 'Minn_Admin_DB', 'holds_credential', $wpdb->options, $cell ), $cell );
		$check( 'N1 control: a plain cell is not', false === $priv( 'Minn_Admin_DB', 'holds_credential', $wpdb->options, '{"k":"https:\/\/example.com\/a\/b\/c"}' ) );
	}

	/*
	 * 12-1 siblings: an untouched field served masked keeps its stored value;
	 * an edit still carrying a mask is refused and writes nothing.
	 */
	$target = 'https://example.com/dl?key=' . $key;
	$masked = 'https://example.com/dl?key=' . $t1;

	// Safe Redirect Manager (post meta).
	( function () use ( $check, $skip, $send, $once, $key, $t1, $target, $masked, $tag ) {
		if ( ! function_exists( 'minn_admin_srm_active' ) || ! minn_admin_srm_active() ) {
			$skip( '12-1 Safe Redirect Manager: inactive (load it with MINN_SWAP_ADD=safe-redirect-manager/safe-redirect-manager.php)' );
			return;
		}
		$id    = 0;
		$clean = $once( function () use ( &$id ) {
			if ( is_int( $id ) && $id > 1 && 'redirect_rule' === get_post_type( $id ) ) {
				wp_delete_post( $id, true );
			}
			if ( function_exists( 'srm_flush_cache' ) ) {
				srm_flush_cache();
			}
		} );
		try {
			$made = srm_create_redirect( '/minn-r2-src-' . $tag, $target, 301 );
			$id   = is_int( $made ) ? $made : 0;
			if ( $id < 2 ) {
				$check( '12-1 SRM: a rule is seeded', false, wp_json_encode( $made ) );
				return;
			}
			$rule  = function () use ( $id ) {
				wp_cache_delete( $id, 'post_meta' );
				return minn_admin_srm_rule( $id );
			};
			$route = "/minn-admin/v1/srm/redirects/{$id}";
			list( $st ) = $send( 'PUT', $route, array( 'from' => '/minn-r2-src-' . $tag, 'to' => $masked, 'status_code' => 302 ) );
			$check( '12-1 SRM: a status-only edit keeps the target holding the real key', 200 === $st && $target === $rule()['to'] && 302 === $rule()['status_code'], $st . ' ' . wp_json_encode( $rule() ) );
			list( $st, $d ) = $send( 'PUT', $route, array( 'from' => '/minn-r2-src-' . $tag, 'to' => $masked . '&x=1', 'status_code' => 307 ) );
			$check( '12-1 SRM: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $target === $rule()['to'] && 302 === $rule()['status_code'], $st . ' ' . wp_json_encode( $rule() ) );
			list( $st ) = $send( 'PUT', $route, array( 'from' => '/minn-r2-src-' . $tag, 'to' => 'https://example.com/new-' . $tag ) );
			$check( '12-1 SRM control: a real target edit is written', 200 === $st && 'https://example.com/new-' . $tag === $rule()['to'], $st . ' ' . wp_json_encode( $rule() ) );
			list( $st ) = $send( 'POST', '/minn-admin/v1/srm/redirects', array( 'from' => '/minn-r2-new-' . $tag, 'to' => $masked, 'status_code' => 301 ) );
			$check( '12-1 SRM: a new rule carrying a mask is refused', 400 === $st && 0 === count( get_posts( array( 'post_type' => 'redirect_rule', 'post_status' => 'any', 'meta_key' => '_redirect_rule_from', 'meta_value' => '/minn-r2-new-' . $tag, 'fields' => 'ids' ) ) ), (string) $st );
		} finally {
			foreach ( get_posts( array( 'post_type' => 'redirect_rule', 'post_status' => 'any', 'meta_key' => '_redirect_rule_from', 'meta_value' => '/minn-r2-new-' . $tag, 'fields' => 'ids' ) ) as $stray ) {
				wp_delete_post( (int) $stray, true );
			}
			$clean();
		}
	} )();

	// Rank Math redirections (their own table).
	( function () use ( $check, $skip, $send, $target, $masked, $tag ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_rm_redirects_ready' ) || ! minn_admin_rm_redirects_ready() ) {
			$skip( '12-1 Rank Math redirections: inactive (pass this file to --require as well)' );
			return;
		}
		$table = $wpdb->prefix . 'rank_math_redirections';
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->insert( $table, array( 'sources' => serialize( array( array( 'ignore' => '', 'pattern' => 'minn-r2-' . $tag, 'comparison' => 'exact' ) ) ), 'url_to' => $target, 'header_code' => '301', 'hits' => 0, 'status' => 'active', 'created' => current_time( 'mysql' ), 'updated' => current_time( 'mysql' ) ) ); // phpcs:ignore -- their storage format
			$id = (int) $wpdb->insert_id;
			if ( $id <= 0 ) {
				$check( '12-1 Rank Math: a redirect is seeded', false, $wpdb->last_error );
				return;
			}
			$now   = function () use ( $wpdb, $table, $id ) {
				return $wpdb->get_row( $wpdb->prepare( "SELECT url_to, header_code FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
			};
			$route = '/' . str_replace( '{id}', (string) $id, Minn_Admin_Kernel::action_path( 'rankmath/redirects', 'edit' ) );
			list( $st ) = $send( 'POST', $route, array( 'url_to' => $masked, 'header_code' => '302' ) );
			$check( '12-1 Rank Math: a type-only edit keeps the target holding the real key', 200 === $st && $target === $now()['url_to'] && '302' === (string) $now()['header_code'], $st . ' ' . wp_json_encode( $now() ) );
			list( $st ) = $send( 'POST', $route, array( 'url_to' => $masked . '&x=1', 'header_code' => '307' ) );
			$check( '12-1 Rank Math: an edit that keeps the mask is refused and writes nothing', 400 === $st && $target === $now()['url_to'] && '302' === (string) $now()['header_code'], $st . ' ' . wp_json_encode( $now() ) );
			list( $st ) = $send( 'POST', $route, array( 'url_to' => 'https://example.com/moved-' . $tag, 'header_code' => '302' ) );
			$check( '12-1 Rank Math control: a real target edit is written', 200 === $st && 'https://example.com/moved-' . $tag === $now()['url_to'], $st . ' ' . wp_json_encode( $now() ) );
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
	} )();

	// JetEngine content types (their own table).
	( function () use ( $check, $skip, $send, $once, $key, $t1, $tag, $has ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_jet_cct_active' ) || ! minn_admin_jet_cct_active() || ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Factory' ) ) {
			$skip( '12-1 JetEngine content types: inactive' );
			return;
		}
		$slug    = 'minnr2' . $tag;
		$table   = $wpdb->prefix . 'jet_cct_' . $slug;
		$factory = null;
		$clean   = $once( function () use ( &$factory, $table ) {
			if ( $factory && $table === $factory->db->table() ) {
				$factory->db->drop_table();
			}
		} );
		try {
			$fields  = array(
				array( 'title' => 'Name', 'name' => 'name', 'object_type' => 'field', 'type' => 'text' ),
				array( 'title' => 'Note', 'name' => 'note', 'object_type' => 'field', 'type' => 'textarea' ),
			);
			$factory = new \Jet_Engine\Modules\Custom_Content_Types\Factory( array( 'slug' => $slug, 'name' => 'Minn r2', 'capability' => 'manage_options' ), $fields );
			$factory->db->create_table();
			\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->register_instance( $slug, $factory );
			$note = "Call the API with {$key} on staging.";
			$wpdb->insert( $table, array( 'name' => 'Row ' . $tag, 'note' => $note, 'cct_status' => 'publish' ) );
			$id = (int) $wpdb->insert_id;
			if ( $id < 1 ) {
				$check( '12-1 JetCCT: a row is seeded', false, $wpdb->last_error );
				return;
			}
			$row  = function () use ( $wpdb, $table, $id ) {
				return $wpdb->get_row( $wpdb->prepare( "SELECT name, note FROM {$table} WHERE _ID = %d", $id ), ARRAY_A ); // phpcs:ignore
			};
			$base = '/minn-admin/v1/jet-cct/' . $slug . '/items';
			list( , $item ) = $send( 'GET', $base . '/' . $id );
			$check( '12-1 JetCCT: the read shows the copy masked', $has( $item['note'] ?? '', $t1 ), wp_json_encode( $item['note'] ?? null ) );
			list( $st ) = $send( 'POST', $base . '/' . $id, array( 'name' => 'Renamed ' . $tag, 'note' => $item['note'] ?? '' ) );
			$check( '12-1 JetCCT: a rename leaves the note holding the real key', 200 === $st && $note === $row()['note'] && 'Renamed ' . $tag === $row()['name'], $st . ' ' . wp_json_encode( $row() ) );
			list( $st, $d ) = $send( 'POST', $base . '/' . $id, array( 'name' => 'Again ' . $tag, 'note' => ( $item['note'] ?? '' ) . ' More.' ) );
			$check( '12-1 JetCCT: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $note === $row()['note'] && 'Renamed ' . $tag === $row()['name'], $st . ' ' . wp_json_encode( $row() ) );
			list( $st ) = $send( 'POST', $base . '/' . $id, array( 'note' => 'Plain note ' . $tag ) );
			$check( '12-1 JetCCT control: a real edit is written', 200 === $st && 'Plain note ' . $tag === $row()['note'], $st . ' ' . wp_json_encode( $row() ) );
			list( $st ) = $send( 'POST', $base, array( 'name' => 'New ' . $t1 ) );
			$check( '12-1 JetCCT: a new item carrying a mask is refused', 400 === $st, (string) $st );
		} finally {
			$clean();
		}
	} )();

	// ACF field groups: the schema field edit and the builder's settings.
	( function () use ( $check, $skip, $send, $once, $key, $t1, $tag, $has ) {
		if ( ! function_exists( 'minn_admin_acf_schema_group' ) || ! function_exists( 'acf_update_field_group' ) ) {
			$skip( '12-1 ACF: inactive' );
			return;
		}
		$gid   = 0;
		$clean = $once( function () use ( &$gid ) {
			if ( is_int( $gid ) && $gid > 1 && 'acf-field-group' === get_post_type( $gid ) ) {
				acf_delete_field_group( $gid );
			}
		} );
		try {
			$gkey = 'group_minnr2' . $tag;
			$fkey = 'field_minnr2' . $tag;
			$g    = acf_update_field_group( array( 'key' => $gkey, 'title' => 'Minn r2 ' . $tag, 'active' => false, 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ) ) );
			$gid  = is_array( $g ) ? (int) ( $g['ID'] ?? 0 ) : 0;
			$def  = 'https://api.example.com/?key=' . $key;
			$ins  = 'Use ' . $key . ' for the sandbox.';
			if ( $gid > 1 ) {
				acf_update_field( array( 'key' => $fkey, 'label' => 'Endpoint', 'name' => 'minnr2_endpoint_' . $tag, 'type' => 'text', 'parent' => $gid, 'default_value' => $def, 'instructions' => $ins ) );
			}
			$field = function () use ( $fkey ) {
				acf_get_store( 'fields' )->reset();
				return acf_get_field( $fkey );
			};
			if ( $gid < 2 || ! $field() ) {
				$check( '12-1 ACF: the test group and field were created', false );
				return;
			}
			// The schema field dialog.
			list( , $list ) = $send( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/fields" );
			$item = null;
			foreach ( (array) ( $list['items'] ?? array() ) as $f ) {
				if ( $fkey === ( $f['id'] ?? '' ) ) {
					$item = $f;
				}
			}
			$check( '12-1 ACF field dialog: the read shows the default masked', $has( $item['default_value'] ?? '', $t1 ), wp_json_encode( $item ) );
			list( $st ) = $send( 'PUT', "/minn-admin/v1/acf/schema/fields/{$fkey}", array( 'label' => 'Endpoint URL', 'default_value' => $item['default_value'] ?? '', 'required' => $item['required'] ?? 'No', 'choices' => $item['choices'] ?? '' ) );
			$check( '12-1 ACF field dialog: a relabel keeps the default holding the real key', 200 === $st && $def === ( $field()['default_value'] ?? null ) && 'Endpoint URL' === ( $field()['label'] ?? null ), $st . ' ' . wp_json_encode( $field()['default_value'] ?? null ) );
			list( $st, $d ) = $send( 'PUT', "/minn-admin/v1/acf/schema/fields/{$fkey}", array( 'label' => 'Endpoint 2', 'default_value' => ( $item['default_value'] ?? '' ) . '&x=1' ) );
			$check( '12-1 ACF field dialog: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $def === ( $field()['default_value'] ?? null ) && 'Endpoint URL' === ( $field()['label'] ?? null ), $st . ' ' . wp_json_encode( $field() ) );

			// The builder: every setting of every row, as served.
			list( , $payload ) = $send( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/full" );
			$rows = array();
			foreach ( (array) ( $payload['fields'] ?? array() ) as $f ) {
				$r = array( 'key' => $f['key'] );
				foreach ( array( 'label', 'instructions', 'required', 'default_value', 'placeholder', 'choices', 'min', 'max', 'step', 'rows', 'ui_on_text', 'ui_off_text', 'button_label' ) as $s ) {
					$r[ $s ] = $f[ $s ] ?? null;
				}
				$rows[] = $r;
			}
			$check( '12-1 ACF builder: the read shows the instructions masked', $has( wp_json_encode( $rows ), $t1 ), wp_json_encode( $rows ) );
			$body = function ( array $rows, $stamp ) use ( $payload ) {
				return array( 'stamp' => $stamp, 'title' => $payload['group']['title'] ?? '', 'location' => $payload['group']['location'] ?? array(), 'fields' => $rows, 'active' => false );
			};
			$rows[0]['label'] = 'Endpoint from builder';
			list( $st, $saved ) = $send( 'POST', "/minn-admin/v1/acf/schema/groups/{$gkey}/full", $body( $rows, $payload['stamp'] ?? '' ) );
			$check( '12-1 ACF builder: a relabel keeps the instructions and default holding the real key', 200 === $st && $ins === ( $field()['instructions'] ?? null ) && $def === ( $field()['default_value'] ?? null ) && 'Endpoint from builder' === ( $field()['label'] ?? null ), $st . ' ' . wp_json_encode( array( $field()['instructions'] ?? null, $field()['default_value'] ?? null ) ) );
			list( , $payload ) = $send( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/full" );
			$rows[0]['label']        = 'Not written';
			$rows[0]['instructions'] = ( $rows[0]['instructions'] ?? '' ) . ' Edited.';
			list( $st, $d ) = $send( 'POST', "/minn-admin/v1/acf/schema/groups/{$gkey}/full", $body( $rows, $payload['stamp'] ?? '' ) );
			$check( '12-1 ACF builder: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $ins === ( $field()['instructions'] ?? null ) && 'Endpoint from builder' === ( $field()['label'] ?? null ), $st . ' ' . wp_json_encode( $field() ) );
			$rows[0]['instructions'] = 'Plain help ' . $tag;
			$rows[0]['label']        = 'Endpoint';
			list( $st ) = $send( 'POST', "/minn-admin/v1/acf/schema/groups/{$gkey}/full", $body( $rows, $payload['stamp'] ?? '' ) );
			$check( '12-1 ACF builder control: a real edit is written', 200 === $st && 'Plain help ' . $tag === ( $field()['instructions'] ?? null ) && $def === ( $field()['default_value'] ?? null ), $st . ' ' . wp_json_encode( $field() ) );
		} finally {
			$clean();
		}
	} )();

	// ACPT builder (its own tables).
	( function () use ( $check, $skip, $send, $once, $key, $t1, $tag, $has ) {
		if ( ! function_exists( 'minn_admin_acpt_active' ) || ! minn_admin_acpt_active() || ! class_exists( '\ACPT\Core\CQRS\Command\SaveMetaGroupCommand' ) ) {
			$skip( '12-1 ACPT: inactive' );
			return;
		}
		$gid   = '';
		$clean = $once( function () use ( &$gid ) {
			if ( '' !== $gid ) {
				try {
					( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $gid ) )->execute();
				} catch ( \Throwable $e ) {
					echo 'WARN  could not delete the ACPT test group ' . $gid . "\n";
				}
			}
		} );
		try {
			$ins = 'Use ' . $key . ' for the sandbox.';
			$gid = (string) ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
				'name'    => 'minnr2' . $tag,
				'label'   => 'Minn r2 ' . $tag,
				'belongs' => array( array( 'belongsTo' => \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE, 'operator' => '=', 'find' => 'post', 'logic' => '' ) ),
				'boxes'   => array( array( 'name' => 'minnr2_box' . $tag, 'label' => 'Box', 'fields' => array( array( 'name' => 'note', 'label' => 'Note', 'type' => 'Text', 'isRequired' => false, 'description' => $ins ) ) ) ),
			) ) )->execute();
			if ( '' === $gid ) {
				$check( '12-1 ACPT: the test group was created', false );
				return;
			}
			$stored = function () use ( $gid ) {
				$g = minn_admin_acpt_builder_group( $gid );
				$g = json_decode( wp_json_encode( $g ), true );
				return $g['boxes'][0]['fields'][0] ?? array();
			};
			list( , $payload ) = $send( 'GET', "/minn-admin/v1/acpt/schema/groups/{$gid}/full" );
			$rows = (array) ( $payload['fields'] ?? array() );
			$check( '12-1 ACPT builder: the read shows the help text masked', $has( wp_json_encode( $rows ), $t1 ), wp_json_encode( $rows ) );
			if ( ! isset( $rows[0]['sub_fields'][0] ) ) {
				$check( '12-1 ACPT builder: the payload carries the field', false, wp_json_encode( $payload ) );
				return;
			}
			$body = function ( $rows, $stamp ) use ( $payload ) {
				return array( 'stamp' => $stamp, 'title' => $payload['group']['title'] ?? '', 'location' => $payload['group']['location'] ?? array(), 'fields' => $rows );
			};
			$rows[0]['sub_fields'][0]['label'] = 'Note relabelled';
			list( $st ) = $send( 'POST', "/minn-admin/v1/acpt/schema/groups/{$gid}/full", $body( $rows, $payload['stamp'] ?? '' ) );
			$check( '12-1 ACPT builder: a relabel keeps the help text holding the real key', 200 === $st && $ins === ( $stored()['description'] ?? null ) && 'Note relabelled' === ( $stored()['label'] ?? null ), $st . ' ' . wp_json_encode( $stored() ) );
			list( , $payload ) = $send( 'GET', "/minn-admin/v1/acpt/schema/groups/{$gid}/full" );
			$rows = (array) ( $payload['fields'] ?? array() );
			$rows[0]['sub_fields'][0]['instructions'] .= ' Edited.';
			list( $st, $d ) = $send( 'POST', "/minn-admin/v1/acpt/schema/groups/{$gid}/full", $body( $rows, $payload['stamp'] ?? '' ) );
			$check( '12-1 ACPT builder: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $ins === ( $stored()['description'] ?? null ), $st . ' ' . wp_json_encode( $stored() ) );
		} finally {
			$clean();
		}
	} )();

	// Contact Form 7 mail (post meta): the page sends only what changed.
	( function () use ( $check, $skip, $send, $once, $key, $t1, $tag, $has ) {
		if ( ! defined( 'WPCF7_VERSION' ) || ! class_exists( 'WPCF7_ContactForm' ) || ! function_exists( 'minn_admin_cf7_mail_payload' ) ) {
			$skip( '12-1 CF7: inactive' );
			return;
		}
		$fid   = 0;
		$clean = $once( function () use ( &$fid ) {
			if ( is_int( $fid ) && $fid > 1 && 'wpcf7_contact_form' === get_post_type( $fid ) ) {
				wp_delete_post( $fid, true );
			}
		} );
		try {
			$body = "Hello [your-name]\nWebhook: https://hooks.example.com/?key={$key}";
			$form = WPCF7_ContactForm::get_template( array( 'title' => 'Minn r2 CF7 ' . $tag ) );
			$m1   = (array) $form->prop( 'mail' );
			$m1['body']    = $body;
			$m1['subject'] = 'Orig';
			$form->set_properties( array( 'mail' => $m1, 'messages' => array_merge( (array) $form->prop( 'messages' ), array( 'mail_sent_ok' => 'Thanks, ref ' . $key ) ) ) );
			$form->save();
			$fid = (int) $form->id();
			if ( $fid < 2 ) {
				$check( '12-1 CF7: the form was created', false );
				return;
			}
			$prop  = function ( $k ) use ( &$fid ) {
				wp_cache_delete( $fid, 'post_meta' );
				return get_post_meta( $fid, '_' . $k, true );
			};
			$route = "/minn-admin/v1/cf7/forms/{$fid}/mail";
			list( , $page ) = $send( 'GET', $route );
			$check( '12-1 CF7: the read shows the body masked', $has( $page['mail']['body'] ?? '', $t1 ), wp_json_encode( $page['mail']['body'] ?? null ) );
			list( $st ) = $send( 'POST', $route, array( 'mail' => array( 'subject' => 'New subject', 'body' => $page['mail']['body'] ?? '' ), 'messages' => array( 'mail_sent_ok' => $page['messages']['mail_sent_ok'] ?? '' ) ) );
			$check( '12-1 CF7: a subject edit beside the body as served keeps the body holding the real key', 200 === $st && $body === ( $prop( 'mail' )['body'] ?? null ) && 'New subject' === ( $prop( 'mail' )['subject'] ?? null ) && 'Thanks, ref ' . $key === ( $prop( 'messages' )['mail_sent_ok'] ?? null ), $st . ' ' . wp_json_encode( $prop( 'mail' )['body'] ?? null ) );
			list( $st, $d ) = $send( 'POST', $route, array( 'mail' => array( 'subject' => 'Not written', 'body' => ( $page['mail']['body'] ?? '' ) . "\nBye" ) ) );
			$check( '12-1 CF7: an edited body that keeps the mask is refused and writes nothing', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $body === ( $prop( 'mail' )['body'] ?? null ) && 'New subject' === ( $prop( 'mail' )['subject'] ?? null ), $st . ' ' . wp_json_encode( $prop( 'mail' ) ) );
			list( $st, $d ) = $send( 'POST', $route, array( 'messages' => array( 'mail_sent_ok' => ( $page['messages']['mail_sent_ok'] ?? '' ) . '!' ) ) );
			$check( '12-1 CF7: an edited message that keeps the mask is refused', 400 === $st && 'Thanks, ref ' . $key === ( $prop( 'messages' )['mail_sent_ok'] ?? null ), $st . ' ' . wp_json_encode( $prop( 'messages' )['mail_sent_ok'] ?? null ) );
			list( $st ) = $send( 'POST', $route, array( 'mail' => array( 'body' => "Hello [your-name]\nPlain body {$tag}" ) ) );
			$check( '12-1 CF7 control: a real body edit is written', 200 === $st && false !== strpos( (string) ( $prop( 'mail' )['body'] ?? '' ), 'Plain body ' . $tag ), $st . ' ' . wp_json_encode( $prop( 'mail' )['body'] ?? null ) );
		} finally {
			$clean();
		}
	} )();

	// Gravity Forms notifications and confirmations (form meta).
	( function () use ( $check, $skip, $send, $key, $t1, $tag, $has ) {
		if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_build' ) || ! function_exists( 'minn_admin_gfc_build' ) ) {
			$skip( '12-1 Gravity Forms: inactive' );
			return;
		}
		$msg  = "Ping https://hooks.example.com/?key={$key}";
		$fid  = GFAPI::add_form( array(
			'title'         => 'Minn r2 GF ' . $tag,
			'fields'        => array(),
			'notifications' => array( 'r2n' => array( 'id' => 'r2n', 'name' => 'R2', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'S', 'message' => $msg, 'isActive' => true ) ),
			'confirmations' => array( 'r2c' => array( 'id' => 'r2c', 'name' => 'R2 conf', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks ' . $key ) ),
		) );
		$fid  = is_int( $fid ) && $fid > 0 ? $fid : 0;
		try {
			if ( ! $fid ) {
				$check( '12-1 GF: the form was created', false );
				return;
			}
			$n = function () use ( $fid ) {
				GFFormsModel::flush_current_forms();
				return GFAPI::get_form( $fid )['notifications']['r2n'] ?? array();
			};
			$c = function () use ( $fid ) {
				GFFormsModel::flush_current_forms();
				return GFAPI::get_form( $fid )['confirmations']['r2c'] ?? array();
			};
			list( , $page ) = $send( 'GET', "/minn-admin/v1/gf/notifications/{$fid}:r2n/full" );
			$check( '12-1 GF notification: the read shows the message masked', $has( $page['notification']['message'] ?? '', $t1 ), wp_json_encode( $page['notification']['message'] ?? $page ) );
			list( $st ) = $send( 'POST', "/minn-admin/v1/gf/notifications/{$fid}:r2n/full", array( 'subject' => 'New S', 'message' => $page['notification']['message'] ?? '' ) );
			$check( '12-1 GF notification: a subject edit beside the message as served keeps the message holding the real key', 200 === $st && $msg === ( $n()['message'] ?? null ) && 'New S' === ( $n()['subject'] ?? null ), $st . ' ' . wp_json_encode( $n()['message'] ?? null ) );
			list( $st, $d ) = $send( 'POST', "/minn-admin/v1/gf/notifications/{$fid}:r2n/full", array( 'subject' => 'Not written', 'message' => ( $page['notification']['message'] ?? '' ) . ' More' ) );
			$check( '12-1 GF notification: an edited message that keeps the mask is refused and writes nothing', 400 === $st && $msg === ( $n()['message'] ?? null ) && 'New S' === ( $n()['subject'] ?? null ), $st . ' ' . wp_json_encode( $n() ) );
			list( $st ) = $send( 'POST', "/minn-admin/v1/gf/confirmations/{$fid}:r2c/full", array( 'message' => 'Thanks ' . $t1 ) );
			$check( '12-1 GF confirmation: a message sent back as served keeps the real key', 200 === $st && 'Thanks ' . $key === ( $c()['message'] ?? null ), $st . ' ' . wp_json_encode( $c()['message'] ?? null ) );
			list( $st, $d ) = $send( 'POST', "/minn-admin/v1/gf/confirmations/{$fid}:r2c/full", array( 'message' => 'Thanks ' . $t1 . ' and more' ) );
			$check( '12-1 GF confirmation: an edited message that keeps the mask is refused and writes nothing', 400 === $st && 'Thanks ' . $key === ( $c()['message'] ?? null ), $st . ' ' . wp_json_encode( $c()['message'] ?? null ) );
			list( $st ) = $send( 'POST', "/minn-admin/v1/gf/confirmations/{$fid}:r2c/full", array( 'message' => 'Plain thanks ' . $tag ) );
			$check( '12-1 GF confirmation control: a real edit is written', 200 === $st && false !== strpos( (string) ( $c()['message'] ?? '' ), 'Plain thanks ' . $tag ), $st . ' ' . wp_json_encode( $c()['message'] ?? null ) );
		} finally {
			if ( $fid ) {
				GFAPI::delete_form( $fid );
			}
		}
	} )();

	// WPForms emails (the form post).
	( function () use ( $check, $skip, $send, $key, $t1, $tag ) {
		if ( ! function_exists( 'wpforms' ) || ! function_exists( 'minn_admin_wpforms_emails_payload' ) ) {
			$skip( '12-1 WPForms emails: inactive' );
			return;
		}
		$fid = (int) wpforms()->obj( 'form' )->add( 'Minn r2 WPForms ' . $tag );
		try {
			if ( $fid < 2 ) {
				$check( '12-1 WPForms: the form was created', false );
				return;
			}
			$data = wpforms()->obj( 'form' )->get( $fid, array( 'content_only' => true ) );
			$data['settings']['notifications'] = array( '1' => array( 'notification_name' => 'Default', 'email' => '{admin_email}', 'subject' => 'S', 'message' => 'Ping key=' . $key ) );
			wpforms()->obj( 'form' )->update( $fid, wp_slash( $data ) );
			$msg = function () use ( $fid ) {
				$d = wpforms()->obj( 'form' )->get( $fid, array( 'content_only' => true, 'cap' => false ) );
				return (string) ( $d['settings']['notifications']['1']['message'] ?? '' );
			};
			if ( 'Ping key=' . $key !== $msg() ) {
				$check( '12-1 WPForms: the notification was seeded', false, $msg() );
				return;
			}
			list( $st, $d ) = $send( 'POST', "/minn-admin/v1/wpforms/forms/{$fid}/emails", array( 'notifications' => array( array( 'key' => '1', 'message' => 'Ping key=' . $t1 . ' more' ) ) ) );
			$check( '12-1 WPForms emails: an edited message that keeps the mask is refused and writes nothing', 400 === $st && 'Ping key=' . $key === $msg(), $st . ' ' . $msg() );
			list( $st ) = $send( 'POST', "/minn-admin/v1/wpforms/forms/{$fid}/emails", array( 'notifications' => array( array( 'key' => '1', 'message' => 'Plain ' . $tag ) ) ) );
			$check( '12-1 WPForms emails control: a real edit is written', 200 === $st && 'Plain ' . $tag === $msg(), $st . ' ' . $msg() );
		} finally {
			if ( $fid > 1 && 'wpforms' === get_post_type( $fid ) ) {
				wp_delete_post( $fid, true );
			}
		}
	} )();

	// Fluent Forms emails (form meta).
	( function () use ( $check, $skip, $send, $key, $t1, $tag ) {
		global $wpdb;
		if ( ! defined( 'FLUENTFORM' ) || ! function_exists( 'minn_admin_fluent_notifications' ) ) {
			$skip( '12-1 Fluent Forms emails: inactive' );
			return;
		}
		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->insert( "{$p}fluentform_forms", array( 'title' => 'Minn r2 Fluent ' . $tag, 'status' => 'published', 'form_fields' => wp_json_encode( array( 'fields' => array() ) ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
			$fid = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $fid, 'meta_key' => 'notifications', 'value' => wp_json_encode( array( 'name' => 'Admin email', 'sendTo' => array( 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => array() ), 'subject' => 'New', 'message' => '<p>Ping ' . $key . '</p>', 'enabled' => true ) ) ) );
			$mid = (int) $wpdb->insert_id;
			$msg = function () use ( $wpdb, $p, $mid ) {
				$v = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$p}fluentform_form_meta WHERE id = %d", $mid ) ), true ); // phpcs:ignore
				return (string) ( $v['message'] ?? '' );
			};
			list( $st ) = $send( 'POST', "/minn-admin/v1/fluent-forms/forms/{$fid}/emails", array( 'notifications' => array( array( 'metaId' => $mid, 'message' => '<p>Ping ' . $t1 . ' more</p>' ) ) ) );
			$check( '12-1 Fluent Forms emails: an edited message that keeps the mask is refused and writes nothing', 400 === $st && '<p>Ping ' . $key . '</p>' === $msg(), $st . ' ' . $msg() );
			list( $st ) = $send( 'POST', "/minn-admin/v1/fluent-forms/forms/{$fid}/emails", array( 'notifications' => array( array( 'metaId' => $mid, 'message' => '<p>Plain ' . $tag . '</p>' ) ) ) );
			$check( '12-1 Fluent Forms emails control: a real edit is written', 200 === $st && false !== strpos( $msg(), 'Plain ' . $tag ), $st . ' ' . $msg() );
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
	} )();

	// JetSearch suggestions (their own table).
	( function () use ( $check, $skip, $send, $key, $t1, $tag ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_jet_search_active' ) || ! minn_admin_jet_search_active() || ! Minn_Admin_Kernel_SQL::exists( MINN_ADMIN_JET_SEARCH_TABLE ) ) {
			$skip( '12-1 JetSearch: inactive' );
			return;
		}
		$table = $wpdb->prefix . MINN_ADMIN_JET_SEARCH_TABLE;
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->insert( $table, array( 'name' => 'promo ' . $key, 'weight' => 3, 'parent' => '', 'term' => '' ) );
			$id = (int) $wpdb->insert_id;
			$name = function () use ( $wpdb, $table, $id ) {
				return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore
			};
			$route = '/' . str_replace( '{id}', (string) $id, Minn_Admin_Kernel::action_path( 'jet-search/suggestions', 'edit' ) );
			list( $st ) = $send( 'POST', $route, array( 'name' => 'promo ' . $t1, 'weight' => 9, 'weight_seen' => 3 ) );
			$check( '12-1 JetSearch: a weight edit keeps the name holding the real key', 200 === $st && 'promo ' . $key === $name(), $st . ' ' . $name() );
			list( $st ) = $send( 'POST', $route, array( 'name' => 'promo ' . $t1 . ' x', 'weight' => 9, 'weight_seen' => 9 ) );
			$check( '12-1 JetSearch: an edit that keeps the mask is refused and writes nothing', 400 === $st && 'promo ' . $key === $name(), $st . ' ' . $name() );
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
	} )();

	// N10 (Simple 301 Redirects, one option keyed by source): renaming a
	// rule's source with its target untouched keeps the key, since the
	// untouched target is written as stored. Only a target also edited
	// beside its mask moves the mask to a new path, which keep() refuses.
	( function () use ( $check, $skip, $send, $once, $key, $t1, $tag ) {
		if ( ! function_exists( 'minn_admin_s301_active' ) || ! minn_admin_s301_active() ) {
			$skip( 'N10 Simple 301 Redirects: inactive (load it with MINN_SWAP_ADD=simple-301-redirects/wp-simple-301-redirects.php)' );
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
			$from          = '/minn-r2-s301-' . $tag;
			$to            = 'https://example.com/dl?key=' . $key;
			$rows          = is_array( $was ) ? $was : array();
			$rows[ $from ] = $to;
			update_option( '301_redirects', $rows );
			list( $st ) = $send( 'PUT', '/minn-admin/v1/s301/redirects/' . minn_admin_s301_encode_id( $from ), array( 'from' => $from . '-renamed', 'to' => 'https://example.com/dl?key=' . $t1 ) );
			$now        = (array) get_option( '301_redirects', array() );
			$check( 'N10 Simple 301: a source rename with the target untouched keeps the key under the new source', 200 === $st && $to === ( $now[ $from . '-renamed' ] ?? null ) && ! isset( $now[ $from ] ), $st . ' ' . wp_json_encode( $now[ $from . '-renamed' ] ?? null ) );
		} finally {
			$restore();
		}
	} )();

	// The shared compares a panel or page uses for "untouched".
	( function () use ( $check, $skip, $key, $t1 ) {
		if ( function_exists( 'minn_admin_jet_same_value' ) ) {
			$check( '12-1 JetEngine fields: a value sent back as served (masked) counts as untouched', minn_admin_jet_same_value( array( 'type' => 'textarea' ), 'see ' . $t1, 'see ' . $key ) );
			$check( '12-1 JetEngine fields control: an edit does not', ! minn_admin_jet_same_value( array( 'type' => 'textarea' ), 'see ' . $t1 . ' x', 'see ' . $key ) );
		}
		if ( function_exists( 'minn_admin_acpt_same' ) ) {
			$check( '12-1 ACPT panel: a value sent back as served (masked) counts as untouched', minn_admin_acpt_same( array( 'a' => 'see ' . $key ), array( 'a' => 'see ' . $t1 ) ) );
			$check( '12-1 ACPT panel control: an edit does not', ! minn_admin_acpt_same( array( 'a' => 'see ' . $key ), array( 'a' => 'see ' . $t1 . ' x' ) ) );
		}
		if ( function_exists( 'minn_admin_gfn_as_read' ) ) {
			$check( '12-1 GF routing/conditions: a list sent back as served (masked) counts as read', minn_admin_gfn_as_read( array( 'routing' => array( array( 'value' => $t1 ) ) ), 'routing', array( array( 'value' => $key ) ) ) );
		}
	} )();

	/*
	 * 01-1 sibling: password answers in the database browser.
	 */
	( function () use ( $check, $skip, $tag ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_fluent_forms_ready' ) || ! method_exists( 'Minn_Admin_Kernel', 'table_refused' ) || ! Minn_Admin_Kernel_SQL::exists( 'fluentform_forms' ) ) {
			$skip( '01-1 Fluent Forms: inactive' );
			return;
		}
		$p      = $wpdb->prefix;
		$refuse = function ( $t ) {
			$r = new ReflectionProperty( 'Minn_Admin_DB', 'hidden' );
			if ( PHP_VERSION_ID < 80100 ) {
				$r->setAccessible( true );
			}
			$r->setValue( null, array() );
			return true === Minn_Admin_Kernel::table_refused( $t );
		};
		$had = $refuse( $p . 'fluentform_submissions' );
		if ( $had ) {
			$skip( '01-1 Fluent Forms: this site already has a password form, so its tables are refused before the fixture' );
			return;
		}
		$check( '01-1 Fluent control: without a password form an administrator browses the submission tables', ! $refuse( $p . 'fluentform_submissions' ) && ! $refuse( $p . 'fluentform_entry_details' ) );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$fields = array( 'fields' => array( array( 'element' => 'input_password', 'attributes' => array( 'name' => 'pw' ), 'settings' => array( 'label' => 'Password' ) ) ) );
			$wpdb->insert( $p . 'fluentform_forms', array( 'title' => 'Minn r2 pw ' . $tag, 'status' => 'published', 'form_fields' => wp_json_encode( $fields ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
			$fid = (int) $wpdb->insert_id;
			$check( '01-1 Fluent: with a password form its submission tables are refused in the database browser', $refuse( $p . 'fluentform_submissions' ) && $refuse( $p . 'fluentform_entry_details' ) && $refuse( $p . 'fluentform_draft_submissions' ) );
			$off = function ( $truncate, $id ) use ( $fid ) {
				return (int) $id === $fid ? false : $truncate;
			};
			add_filter( 'fluentform/truncate_password_values', $off, 10, 2 );
			$check( '01-1 Fluent control: a form whose passwords Fluent shows (its filter) leaves the tables browsable', ! $refuse( $p . 'fluentform_submissions' ) );
			remove_filter( 'fluentform/truncate_password_values', $off, 10 );
			$check( '01-1 Fluent control: the entries list still answers the administrator', 200 === rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/fluent-forms/entries' ) )->get_status() );
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
	} )();

	( function () use ( $check, $skip, $send, $tag ) {
		if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GF_Field_Password' ) ) {
			$skip( '01-1 Gravity Forms passwords: inactive' );
			return;
		}
		$fid = GFAPI::add_form( array( 'title' => 'Minn r2 GF pw ' . $tag, 'fields' => array(
			array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ),
			array( 'id' => 2, 'type' => 'password', 'label' => 'Password' ),
		) ) );
		$fid = is_int( $fid ) && $fid > 0 ? $fid : 0;
		try {
			if ( ! $fid ) {
				$check( '01-1 GF: the form was created', false );
				return;
			}
			// A site with gform_encrypt_password on keeps the password, which
			// GFAPI reads back decrypted.
			$eid = GFAPI::add_entry( array( 'form_id' => $fid, '1' => 'Dana ' . $tag, '2' => 'hunter2-r2-' . $tag ) );
			$eid = is_int( $eid ) ? $eid : 0;
			list( $st, $d ) = $send( 'GET', "/minn-admin/v1/gf/entries/{$eid}" );
			$check( '01-1 GF: the entry view skips a password answer, as Gravity Forms\' entry screen does', 200 === $st && false === strpos( wp_json_encode( $d ), 'hunter2-r2-' . $tag ), $st . ' ' . substr( wp_json_encode( $d ), 0, 300 ) );
			$check( '01-1 GF control: other answers still show', false !== strpos( wp_json_encode( $d ), 'Dana ' . $tag ) );
		} finally {
			if ( $fid ) {
				GFAPI::delete_form( $fid );
			}
		}
	} )();

	/*
	 * Info items.
	 */
	// N3: outside a minn-admin/v1 request an option write names nothing.
	$named = new ReflectionProperty( 'Minn_Admin_Egress', 'named' );
	if ( PHP_VERSION_ID < 80100 ) {
		$named->setAccessible( true );
	}
	Minn_Admin::unchanged( 'x ' . $t1, 'x ' . $key );
	$plain = 'minn_r2_plain_' . $tag;
	add_option( $plain, 'v', '', 'no' );
	$at = get_current_blog_id() . ':' . $plain;
	$check( 'N3 an option written outside a Minn request is not named (no gateways or licence readers start)', ! array_key_exists( $at, (array) $named->getValue() ), wp_json_encode( array_keys( (array) $named->getValue() ) ) );
	$held = new ReflectionProperty( 'Minn_Admin_Egress', 'credentials' );
	if ( PHP_VERSION_ID < 80100 ) {
		$held->setAccessible( true );
	}
	$check( 'N3 control: the list is dropped instead, so the next Minn response reads it again', null === $held->getValue() );
	delete_option( $plain );
	Minn_Admin_Egress::reset();

	// N5: the other definitions' attachments are written first.
	$cpt = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-minn-admin-cpt.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$order_ok = true;
	foreach ( array( array( 'attach_in_tax( $tax, $slug, $attach )', "self::acf_write( \$slug, \$def, \$existing, \$only )" ), array( 'attach_in_type( $type, $slug, $attach )', 'self::minn_tax_write( $slug, $def, $only )' ) ) as $pair ) {
		$a = strpos( $cpt, $pair[0] );
		$b = strpos( $cpt, $pair[1] );
		$order_ok = $order_ok && false !== $a && false !== $b && $a < $b;
	}
	$check( 'N5 a definition save writes the other definitions\' attachments before its own change', $order_ok );

	// N7: Pods reads a validation answer of '' or 0 as a refusal.
	if ( function_exists( 'minn_admin_pods_refusal' ) && function_exists( 'pods' ) && function_exists( 'pods_api' ) ) {
		$pod = null;
		foreach ( (array) pods_api()->load_pods( array( 'type' => 'post_type', 'names' => true ) ) as $pname => $label ) {
			$try = pods( $pname );
			if ( $try && $try->valid() && $try->pod_data && count( (array) $try->pod_data->get_fields() ) ) {
				$pod = $try;
				break;
			}
		}
		if ( ! $pod ) {
			$skip( 'N7 Pods: no post-type pod with fields here' );
		} else {
			$fname = (string) array_keys( (array) $pod->pod_data->get_fields() )[0];
			$answer = '';
			$hook   = function () use ( &$answer ) {
				return $answer;
			};
			add_filter( 'pods_api_field_validation', $hook, PHP_INT_MAX );
			$r1     = minn_admin_pods_refusal( $pod, $fname, 'x', 0 );
			$answer = 0;
			$r2     = minn_admin_pods_refusal( $pod, $fname, 'x', 0 );
			$answer = true;
			$r3     = minn_admin_pods_refusal( $pod, $fname, 'x', 0 );
			remove_filter( 'pods_api_field_validation', $hook, PHP_INT_MAX );
			$check( 'N7 Pods: a validation answer of \'\' or 0, which save_pod_item() refuses, is a refusal', null !== $r1 && null !== $r2, wp_json_encode( array( $r1, $r2 ) ) );
			$check( 'N7 Pods control: true passes', null === $r3, wp_json_encode( $r3 ) );
		}
	} else {
		$skip( 'N7 Pods: inactive' );
	}

	// N11: the dead preview helper is gone.
	$check( 'N11 minn_admin_gfn_neutral_tags() (no callers) is gone', ! function_exists( 'minn_admin_gfn_neutral_tags' ) );

	// /plugin-meta on a network: a site's administrator is not told about a
	// network-only plugin that is not active there.
	if ( is_multisite() && ! is_main_site() ) {
		$file = '';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		// Every installed network-only plugin as the update check lists it,
		// for this process only.
		$listed = function ( $tr ) {
			$tr = is_object( $tr ) ? $tr : (object) array();
			$tr->no_update = (array) ( $tr->no_update ?? array() );
			foreach ( array_keys( get_plugins() ) as $f ) {
				if ( is_network_only_plugin( $f ) && ! isset( $tr->no_update[ $f ] ) && ! isset( $tr->response[ $f ] ) ) {
					$tr->no_update[ $f ] = (object) array( 'id' => 'w.org/plugins/' . dirname( $f ), 'slug' => dirname( $f ), 'icons' => array( '1x' => 'https://example.com/' . dirname( $f ) . '.png' ) );
				}
			}
			return $tr;
		};
		add_filter( 'site_transient_update_plugins', $listed );
		// The network lets site administrators reach the Plugins screen.
		$menu = function ( $items ) {
			return array_merge( (array) $items, array( 'plugins' => '1' ) );
		};
		add_filter( 'site_option_menu_items', $menu );
		// One network-only plugin read as not network-active, for this
		// process only (nothing is written): the case a site's
		// administrator must not be told about.
		$quiet = '';
		foreach ( array_keys( get_plugins() ) as $f ) {
			if ( is_network_only_plugin( $f ) && ! in_array( $f, (array) get_option( 'active_plugins', array() ), true ) ) {
				$quiet = $f;
				break;
			}
		}
		$sitewide = function ( $list ) use ( $quiet ) {
			unset( $list[ $quiet ] );
			return $list;
		};
		if ( '' !== $quiet ) {
			add_filter( 'site_option_active_sitewide_plugins', $sitewide );
			wp_cache_delete( 'plugins', 'plugins' );
		}
		$tr = get_site_transient( 'update_plugins' );
		foreach ( array_merge( array_keys( (array) ( $tr->response ?? array() ) ), array_keys( (array) ( $tr->no_update ?? array() ) ) ) as $f ) {
			if ( is_network_only_plugin( $f ) && ! is_plugin_active( $f ) ) {
				$file = $f;
				break;
			}
		}
		if ( '' === $file ) {
			remove_filter( 'site_transient_update_plugins', $listed );
			remove_filter( 'site_option_menu_items', $menu );
			remove_filter( 'site_option_active_sitewide_plugins', $sitewide );
			$skip( '/plugin-meta: no network-only plugin inactive on this site' );
		} else {
			$uid  = wp_insert_user( array( 'user_login' => 'minn_r2_site_' . $tag, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minn_r2_site_' . $tag . '@example.com', 'role' => 'administrator' ) );
			$uid  = is_int( $uid ) ? $uid : 0;
			try {
				if ( $uid > 1 ) {
					wp_set_current_user( $uid );
					list( $st, $d ) = $send( 'GET', '/minn-admin/v1/plugin-meta' );
					$check( '/plugin-meta: a site administrator gets no icon or link for a network-only plugin not active on the site', 200 === $st && ! array_key_exists( $file, (array) $d ), $st . ' ' . $file );
					wp_set_current_user( $admin );
					list( $st, $d ) = $send( 'GET', '/minn-admin/v1/plugin-meta' );
					$check( '/plugin-meta control: the network owner still gets it', 200 === $st && array_key_exists( $file, (array) $d ), $st . ' ' . $file );
				}
			} finally {
				remove_filter( 'site_transient_update_plugins', $listed );
				remove_filter( 'site_option_menu_items', $menu );
				remove_filter( 'site_option_active_sitewide_plugins', $sitewide );
				wp_set_current_user( $admin );
				if ( $uid > 1 && 'minn_r2_site_' . $tag === get_userdata( $uid )->user_login ) {
					require_once ABSPATH . 'wp-admin/includes/ms.php';
					wpmu_delete_user( $uid );
				}
			}
		}
	} else {
		$skip( '/plugin-meta: run on a network subsite' );
	}
} finally {
	$seed_clean();
}

printf( "\nsecurity-v046-rc-round2: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
