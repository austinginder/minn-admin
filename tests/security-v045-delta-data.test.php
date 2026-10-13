<?php
/**
 * Regressions for the v0.45.0 fix-delta review (round 1): the database
 * browser, the licences screen and the spam card.
 *
 * Each section replays what Minn's client sends through the route it calls,
 * as the role the finding named, with a control where a fix could over-block.
 * Sections SKIP when their plugin is not installed, and the network ones
 * SKIP on a single site. No vendor server is contacted: every outbound
 * request during a licence action is answered locally. The Kadence section
 * defines a stand-in for Liquid Web's Harbor library, which cannot be
 * undone in this process, so it runs last.
 *
 * Run: wp eval-file tests/security-v045-delta-data.test.php --user=admin --path=<site> [--url=<subsite>]
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v045-delta-data: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	$id = (int) $id;
	if ( $id <= 1 ) {
		return false;
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

// Leftovers from an interrupted run, by login.
foreach ( get_users( array( 'search' => 'minn-d45-*', 'search_columns' => array( 'user_login' ), 'blog_id' => 0, 'fields' => 'ID' ) ) as $d45_old ) {
	$drop_user( (int) $d45_old );
}

// A throwaway account of one role; the id is checked before any later
// delete, since a WP_Error cast to int names user 1.
$make_user = function ( $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'minn-d45-' . $role . '-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'minn-d45-' . $role . '-' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => $role,
		)
	);
	return is_int( $id ) && $id > 1 ? $id : 0;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin && is_multisite() ) {
	$admin = get_users( array( 'login__in' => get_super_admins(), 'blog_id' => 0, 'number' => 1 ) );
}
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
if ( is_multisite() && ! is_super_admin( $admin ) ) {
	$supers = get_users( array( 'login__in' => get_super_admins(), 'blog_id' => 0, 'number' => 1 ) );
	$admin  = $supers ? (int) $supers[0]->ID : $admin;
}
wp_set_current_user( $admin );

// Whether a route's permission callback admits the current user, without
// running the route.
$perm_for = function ( $route, $method ) {
	$routes = rest_get_server()->get_routes();
	foreach ( $routes[ $route ] ?? array() as $handler ) {
		if ( ! empty( $handler['methods'][ $method ] ) ) {
			return true === call_user_func( $handler['permission_callback'], new WP_REST_Request( $method, $route ) );
		}
	}
	return null;
};

// A private static of the browser, for the checks that need its own answer.
$db_private = function ( $method, $args = array() ) {
	$m = new ReflectionMethod( 'Minn_Admin_DB', $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
};
$db_static = function ( $prop, $value = null, $set = false ) {
	$p = new ReflectionProperty( 'Minn_Admin_DB', $prop );
	$p->setAccessible( true );
	if ( $set ) {
		$p->setValue( null, $value );
	}
	return $p->getValue();
};

// Every outbound request is answered here while a licence action runs.
$offline = function ( $pre, $args, $url ) {
	return new WP_Error( 'http_request_failed', 'Minn D45: offline (' . wp_parse_url( (string) $url, PHP_URL_HOST ) . ')' );
};

$lic_raw = function ( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
};
$lic_put = function ( $name, $raw ) {
	global $wpdb;
	if ( null === $raw ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	} elseif ( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $name ) ) ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $raw, 'autoload' => 'off' ) );
	} else {
		$wpdb->update( $wpdb->options, array( 'option_value' => $raw ), array( 'option_name' => $name ) );
	}
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};

// ============================================================================
// Database browser. The table list is memoized per request, so every fixture
// table is made before the first browser call in this run.
// ============================================================================
global $wpdb;
$p   = $wpdb->prefix;
$d45 = array(
	'um_dash'  => $p . 'usermeta-bak',
	'um_cat'   => $p . 'usermetabak',
	'um_old'   => $p . 'usermetaold',
	'um_dot'   => $p . 'usermeta.bak',
	'op_dash'  => $p . 'options-old',
	'op_cat'   => $p . 'optionsbak',
	'plug'     => $p . 'minnd45pluginoptions',
	'kv'       => $p . 'minnd45myoptions',
	'wf2fa'    => $p . 'wfls_2fa_secrets_old',
	'otto'     => $p . 'suretriggers_webhook_requests_bak',
	'wfc'      => $p . 'wfconfig_old',
	'wfls'     => $p . 'wfls_settings_bak',
	'sfo'      => $p . 'search_filter_options_old',
	'wc_bak'   => $p . 'bak_wc_orders',
	'wc_old'   => $p . 'old_woocommerce_log',
	'wc_wp'    => $p . 'wp_wc_order_addresses',
	'wc_dash'  => $p . 'woocommerce-log',
	'wc_addr'  => $p . 'bak_wc_order_addresses',
	'blank'    => $p . 'minnd45_blank',
);
$d45_drop = function () use ( $wpdb, $d45 ) {
	foreach ( $d45 as $t ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $t ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
};
$d45_drop();
$q   = function ( $t ) {
	return '`' . str_replace( '`', '``', $t ) . '`';
};
$ids = array();
$sec = array(
	'seed'   => 'MINND45SEED' . wp_generate_password( 24, false ),
	'login'  => 'minnd45login' . wp_generate_password( 24, false ),
	'salt'   => 'minnd45salt' . wp_generate_password( 40, false ),
	'mudev'  => 'minnd45mudev' . wp_generate_password( 28, false ),
	'bearer' => 'minnd45bearer' . wp_generate_password( 32, false ),
	'wfkey'  => 'minnd45wfkey' . wp_generate_password( 36, false ),
	'wfhash' => 'minnd45wfhash' . wp_generate_password( 36, false ),
	'sfkey'  => 'minnd45sfkey' . wp_generate_password( 24, false ),
);
// wpdb::insert() reads a dotted name as database.table, so rows go in by hand.
$um_put = function ( $table, $key, $value ) use ( $wpdb, $q, $admin ) {
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$q( $table )} ( user_id, meta_key, meta_value ) VALUES ( %d, %s, %s )", $admin, $key, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (int) $wpdb->insert_id;
};
foreach ( array( 'um_dash', 'um_cat', 'um_old', 'um_dot' ) as $k ) {
	$wpdb->query( "CREATE TABLE {$q( $d45[ $k ] )} LIKE `{$wpdb->usermeta}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids[ $k ]['seed']  = $um_put( $d45[ $k ], '_two_factor_totp_key', $sec['seed'] );
	$ids[ $k ]['login'] = $um_put( $d45[ $k ], 'one_time_login_token', $sec['login'] );
	$ids[ $k ]['plain'] = $um_put( $d45[ $k ], 'first_name', 'Minnd45 Plainname here' );
}
foreach ( array( 'op_dash', 'op_cat' ) as $k ) {
	$wpdb->query( "CREATE TABLE {$q( $d45[ $k ] )} LIKE `{$wpdb->options}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->insert( $d45[ $k ], array( 'option_name' => 'auth_salt', 'option_value' => $sec['salt'], 'autoload' => 'no' ) );
	$ids[ $k ]['salt'] = (int) $wpdb->insert_id;
	$wpdb->insert( $d45[ $k ], array( 'option_name' => 'blogdescription', 'option_value' => 'Minnd45 plain tagline words', 'autoload' => 'no' ) );
	$ids[ $k ]['plain'] = (int) $wpdb->insert_id;
}
$wpdb->query( "CREATE TABLE {$q( $d45['plug'] )} ( `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `label` varchar(100) NOT NULL DEFAULT '', `payload` longtext, PRIMARY KEY (`id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $d45['plug'], array( 'label' => 'short', 'payload' => 'Minnd45 plugin payload, long enough to be checked' ) );
$ids['plug']['row'] = (int) $wpdb->insert_id;
$wpdb->query( "CREATE TABLE {$q( $d45['kv'] )} ( `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `option_name` varchar(191) NOT NULL DEFAULT '', `option_value` longtext, PRIMARY KEY (`option_id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $d45['kv'], array( 'option_name' => 'wpmudev_apikey', 'option_value' => $sec['mudev'] ) );
$ids['kv']['secret'] = (int) $wpdb->insert_id;
$wpdb->insert( $d45['kv'], array( 'option_name' => 'minnd45_plain', 'option_value' => 'Minnd45 plain option value' ) );
$ids['kv']['plain'] = (int) $wpdb->insert_id;
$wpdb->query( "CREATE TABLE {$q( $d45['wf2fa'] )} ( `id` int(11) unsigned NOT NULL AUTO_INCREMENT, `user_id` bigint(20) unsigned NOT NULL, `secret` tinyblob NOT NULL, `recovery` blob NOT NULL, `ctime` int(10) unsigned NOT NULL, `vtime` int(10) unsigned NOT NULL, `mode` varchar(20) NOT NULL DEFAULT 'authenticator', PRIMARY KEY (`id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $d45['wf2fa'], array( 'user_id' => $admin, 'secret' => random_bytes( 20 ), 'recovery' => random_bytes( 40 ), 'ctime' => time(), 'vtime' => time() ) );
$ids['wf2fa']['row'] = (int) $wpdb->insert_id;
$wpdb->query( "CREATE TABLE {$q( $d45['otto'] )} ( `id` mediumint(9) NOT NULL AUTO_INCREMENT, `request_url` varchar(255) NOT NULL, `request_data` longtext NOT NULL, PRIMARY KEY (`id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $d45['otto'], array( 'request_url' => 'https://example.com/minnd45-hook', 'request_data' => wp_json_encode( array( 'headers' => array( 'Authorization' => 'Bearer ' . $sec['bearer'] ) ) ) ) );
$ids['otto']['row'] = (int) $wpdb->insert_id;
$wpdb->query( "CREATE TABLE {$q( $d45['wfc'] )} ( `name` varchar(100) NOT NULL, `val` longblob, `autoload` enum('no','yes') NOT NULL DEFAULT 'yes', PRIMARY KEY (`name`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $d45['wfc'], array( 'name' => 'apiKey', 'val' => $sec['wfkey'] ) );
$wpdb->insert( $d45['wfc'], array( 'name' => 'scanStageMinnd45', 'val' => 'Minnd45 plain scan stage value' ) );
$wpdb->query( "CREATE TABLE {$q( $d45['wfls'] )} ( `name` varchar(191) NOT NULL, `value` longblob, `autoload` enum('no','yes') NOT NULL DEFAULT 'yes', PRIMARY KEY (`name`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $d45['wfls'], array( 'name' => 'shared-hash-secret', 'value' => $sec['wfhash'] ) );
$wpdb->insert( $d45['wfls'], array( 'name' => 'minnd45-plain', 'value' => 'Minnd45 plain login setting' ) );
$wpdb->query( "CREATE TABLE {$q( $d45['sfo'] )} ( `id` bigint(20) NOT NULL AUTO_INCREMENT, `name` varchar(200) NOT NULL, `value` longtext NOT NULL, PRIMARY KEY (`id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $d45['sfo'], array( 'name' => 'license-data', 'value' => wp_json_encode( array( 'license' => $sec['sfkey'], 'status' => 'valid' ) ) ) );
$ids['sfo']['secret'] = (int) $wpdb->insert_id;
$wpdb->insert( $d45['sfo'], array( 'name' => 'minnd45-plain', 'value' => 'Minnd45 plain filter setting' ) );
$ids['sfo']['plain'] = (int) $wpdb->insert_id;
foreach ( array( 'wc_bak', 'wc_old', 'wc_wp', 'wc_dash', 'wc_addr' ) as $k ) {
	$wpdb->query( "CREATE TABLE {$q( $d45[ $k ] )} ( `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `first_name` varchar(100), `address_1` varchar(200), PRIMARY KEY (`id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->insert( $d45[ $k ], array( 'first_name' => 'Minnd45 Shopper', 'address_1' => '1 Minnd45 Street' ) );
}
$wpdb->query( "CREATE TABLE {$q( $d45['blank'] )} ( `id` int(11) unsigned NOT NULL AUTO_INCREMENT, `v` varchar(1) NOT NULL DEFAULT '', PRIMARY KEY (`id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "INSERT INTO {$q( $d45['blank'] )} (`v`) VALUES " . implode( ',', array_fill( 0, 2000, "('')" ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

$made = array();
foreach ( $d45 as $k => $t ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ) {
		$made[] = $k;
	}
}
$check( 'fixtures: every copy table was made', count( $made ) === count( $d45 ) && ! in_array( 0, array_merge( ...array_values( array_map( 'array_values', $ids ) ) ), true ), implode( ',', array_diff( array_keys( $d45 ), $made ) ) );

$db_row  = function ( $table, $pk_col, $pk ) use ( $call ) {
	return $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $table, 'pk' => wp_json_encode( array( $pk_col => (string) $pk ) ) ) );
};
$db_cell = function ( $table, $pk_col, $pk, $col ) use ( $db_row ) {
	list( $st, $d ) = $db_row( $table, $pk_col, $pk );
	if ( 200 !== $st ) {
		return array( $st, null );
	}
	$i = array_search( $col, wp_list_pluck( $d['columns'], 'name' ), true );
	return array( $st, false === $i ? null : $d['cells'][ $i ] );
};
$redacted = function ( $cell ) {
	return is_array( $cell ) && ! empty( $cell['redacted'] ) && empty( $cell['unchecked'] );
};
$shown    = function ( $cell, $needle ) {
	$text = is_array( $cell ) ? (string) ( $cell['v'] ?? '' ) : (string) $cell;
	return false !== strpos( $text, $needle );
};
$has_text = function ( $data, $needle ) {
	return false !== strpos( (string) wp_json_encode( $data ), $needle );
};

// --- OS-2 A copy named without an underscore boundary keeps its store's list ---
foreach ( array( 'um_dash', 'um_cat', 'um_old', 'um_dot' ) as $k ) {
	$t = $d45[ $k ];
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $t, 'fcol' => 'meta_key', 'fq' => '_two_factor_totp_key' ) );
	$check( "OS-2 {$t}: a meta_key search prints no TOTP seed", 200 === $st && 1 === (int) ( $d['total'] ?? 0 ) && ! $has_text( $d['rows'] ?? null, $sec['seed'] ), $st . ' ' . substr( wp_json_encode( $d['rows'] ?? $d ), 0, 160 ) );
	list( $st, $c ) = $db_cell( $t, 'umeta_id', $ids[ $k ]['login'], 'meta_value' );
	$check( "OS-2 {$t}: a sign-in token prints redacted", 200 === $st && $redacted( $c ), $st . ' ' . wp_json_encode( $c ) );
	list( $st, $c ) = $db_cell( $t, 'umeta_id', $ids[ $k ]['plain'], 'meta_value' );
	$check( "OS-2 control: {$t}'s ordinary row prints", 200 === $st && $shown( $c, 'Minnd45 Plainname' ), $st . ' ' . wp_json_encode( $c ) );
}
foreach ( array( 'op_dash', 'op_cat' ) as $k ) {
	$t = $d45[ $k ];
	list( $st, $c ) = $db_cell( $t, 'option_id', $ids[ $k ]['salt'], 'option_value' );
	$check( "OS-2 {$t}: a salt prints redacted", 200 === $st && $redacted( $c ), $st . ' ' . wp_json_encode( $c ) );
	list( $st, $c ) = $db_cell( $t, 'option_id', $ids[ $k ]['plain'], 'option_value' );
	$check( "OS-2 control: {$t}'s ordinary row prints", 200 === $st && $shown( $c, 'Minnd45 plain tagline' ), $st . ' ' . wp_json_encode( $c ) );
}
// The live stores answer as their own names, as before.
$check(
	'OS-2 control: the live usermeta and options tables keep their own store',
	'usermeta' === $db_private( 'keyed_base', array( $wpdb->usermeta ) )
		&& 'usermeta' === $db_private( 'keyed_base', array( $wpdb->base_prefix . '2_usermeta' ) )
		&& 'options' === $db_private( 'keyed_base', array( $wpdb->options ) )
		&& 'options' === $db_private( 'keyed_base', array( $wpdb->base_prefix . 'options' ) )
);
$um_uid = $make_user( 'subscriber' );
if ( ! $um_uid ) {
	$check( 'OS-2 live usermeta fixture user', false );
} else {
	add_user_meta( $um_uid, '_two_factor_totp_key', $sec['seed'] . 'live' );
	update_user_meta( $um_uid, 'first_name', 'Minnd45 Liveplain' );
	$um_plain = (int) $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = 'first_name'", $um_uid ) );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->usermeta, 'fcol' => 'meta_key', 'fq' => '_two_factor_totp_key' ) );
	$check( 'OS-2 control: the live usermeta table still prints no TOTP seed', 200 === $st && ! $has_text( $d['rows'] ?? null, $sec['seed'] ), (string) $st );
	list( $st, $c ) = $db_cell( $wpdb->usermeta, 'umeta_id', $um_plain, 'meta_value' );
	$check( 'OS-2 control: and still prints an ordinary row', 200 === $st && $shown( $c, 'Minnd45 Liveplain' ), $st . ' ' . wp_json_encode( $c ) );
	$drop_user( $um_uid );
}
// A plugin's own table that merely contains a store's name.
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $d45['plug'], 'fcol' => 'payload', 'fq' => 'Minnd45 plugin' ) );
$sortable = wp_list_pluck( (array) ( $d['columns'] ?? array() ), 'sortable', 'name' );
$check( 'OS-2 control: a plugin table named ...options browses, searches and sorts as before', 200 === $st && 1 === (int) ( $d['total'] ?? 0 ) && $has_text( $d['rows'] ?? null, 'Minnd45 plugin payload' ) && ! empty( $sortable['label'] ), $st . ' ' . substr( wp_json_encode( $d ), 0, 160 ) );
list( $st, $c ) = $db_cell( $d45['kv'], 'option_id', $ids['kv']['secret'], 'option_value' );
$check( 'OS-2 a plugin table with option rows only gains redaction (a WPMU DEV key row)', 200 === $st && $redacted( $c ), $st . ' ' . wp_json_encode( $c ) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $d45['kv'], 'fcol' => 'option_value', 'fq' => 'Minnd45 plain' ) );
$check( 'OS-2 control: its ordinary rows print and stay searchable', 200 === $st && 1 === (int) ( $d['total'] ?? 0 ) && $has_text( $d['rows'] ?? null, 'Minnd45 plain option value' ), $st . ' ' . substr( wp_json_encode( $d ), 0, 160 ) );
$amelia = $wpdb->prefix . 'amelia_custom_fields_options';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $amelia ) ) ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $amelia ) );
	$check( "OS-2 control: a real plugin table ({$amelia}) still browses", 200 === $st && isset( $d['rows'] ), (string) $st );
}

// --- OS-3 Copies of Wordfence's 2FA secrets and OttoKit's request log --------
list( $st, $c ) = $db_cell( $d45['wf2fa'], 'id', $ids['wf2fa']['row'], 'recovery' );
$check( 'OS-3 a wfls_2fa_secrets copy prints its recovery codes redacted, not as hex', 200 === $st && $redacted( $c ) && ! isset( $c['hex'] ), $st . ' ' . substr( wp_json_encode( $c ), 0, 120 ) );
list( $st, $c ) = $db_cell( $d45['wf2fa'], 'id', $ids['wf2fa']['row'], 'secret' );
$check( 'OS-3 and its TOTP seed', 200 === $st && $redacted( $c ), $st . ' ' . substr( wp_json_encode( $c ), 0, 120 ) );
list( $st, $c ) = $db_cell( $d45['wf2fa'], 'id', $ids['wf2fa']['row'], 'user_id' );
$check( 'OS-3 control: the copy\'s user id prints', 200 === $st && (string) $admin === (string) $c, $st . ' ' . wp_json_encode( $c ) );
$check(
	'OS-3 control: the live tables keep their rule (recovery codes, request data)',
	true === $db_private( 'is_secret_cell', array( $wpdb->prefix . 'wfls_2fa_secrets', 'recovery', array() ) )
		&& true === $db_private( 'is_secret_cell', array( $wpdb->base_prefix . 'wfls_2fa_secrets', 'secret', array() ) )
		&& true === $db_private( 'is_secret_cell', array( $wpdb->prefix . 'suretriggers_webhook_requests', 'request_data', array() ) )
);
list( $st, $c ) = $db_cell( $d45['otto'], 'id', $ids['otto']['row'], 'request_data' );
$check( 'OS-3 a copy of OttoKit\'s request log prints no bearer token', 200 === $st && $redacted( $c ), $st . ' ' . substr( wp_json_encode( $c ), 0, 120 ) );
list( $st, $c ) = $db_cell( $d45['otto'], 'id', $ids['otto']['row'], 'request_url' );
$check( 'OS-3 control: its request URL prints', 200 === $st && $shown( $c, 'minnd45-hook' ), $st . ' ' . wp_json_encode( $c ) );

// --- OS-16 Copies of a vendor's key store, made before a key rotation -------
list( $st, $c ) = $db_cell( $d45['wfc'], 'name', 'apiKey', 'val' );
$check( 'OS-16 a wfconfig copy prints an old Wordfence API key redacted', 200 === $st && $redacted( $c ), $st . ' ' . wp_json_encode( $c ) );
list( $st, $c ) = $db_cell( $d45['wfc'], 'name', 'scanStageMinnd45', 'val' );
$check( 'OS-16 control: an ordinary wfconfig copy row prints', 200 === $st && $shown( $c, 'Minnd45 plain scan' ), $st . ' ' . wp_json_encode( $c ) );
list( $st, $c ) = $db_cell( $d45['wfls'], 'name', 'shared-hash-secret', 'value' );
$check( 'OS-16 a wfls_settings copy prints an old remembered-device key redacted', 200 === $st && $redacted( $c ), $st . ' ' . wp_json_encode( $c ) );
list( $st, $c ) = $db_cell( $d45['wfls'], 'name', 'minnd45-plain', 'value' );
$check( 'OS-16 control: an ordinary wfls_settings copy row prints', 200 === $st && $shown( $c, 'Minnd45 plain login' ), $st . ' ' . wp_json_encode( $c ) );
list( $st, $c ) = $db_cell( $d45['sfo'], 'id', $ids['sfo']['secret'], 'value' );
$check( 'OS-16 a Search & Filter options copy prints an old licence row redacted', 200 === $st && $redacted( $c ), $st . ' ' . wp_json_encode( $c ) );
list( $st, $c ) = $db_cell( $d45['sfo'], 'id', $ids['sfo']['plain'], 'value' );
$check( 'OS-16 control: an ordinary Search & Filter copy row prints', 200 === $st && $shown( $c, 'Minnd45 plain filter' ), $st . ' ' . wp_json_encode( $c ) );

// --- OS-4, NI-12(b) WooCommerce's tables and their copies -------------------
if ( is_multisite() ) {
	// Only a super admin opens the browser on a network, and a super admin
	// holds every capability, manage_woocommerce included.
	$skip( 'OS-4 single site only' );
	$skip( 'NI-12(b) single site only' );
} else {
	$wc_uid = $make_user( 'administrator' );
	if ( ! $wc_uid ) {
		$check( 'OS-4 fixture user', false );
	} else {
		( new WP_User( $wc_uid ) )->add_cap( 'manage_woocommerce', false );
		wp_set_current_user( $wc_uid );
		list( , $list ) = $call( 'GET', '/minn-admin/v1/db/tables' );
		$listed = wp_list_pluck( (array) ( $list['tables'] ?? array() ), 'name' );
		foreach ( array( 'wc_bak', 'wc_old', 'wc_wp', 'wc_dash', 'wc_addr' ) as $k ) {
			list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $d45[ $k ] ) );
			$check( "OS-4 a role without manage_woocommerce cannot browse {$d45[ $k ]}", 404 === $st && ! in_array( $d45[ $k ], $listed, true ), $st . ' ' . substr( wp_json_encode( $d ), 0, 100 ) );
		}
		list( $st ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $d45['plug'] ) );
		$check( 'OS-4 control: that role still browses a plugin\'s own table', 200 === $st, (string) $st );

		// NI-12(b): the health checks are cached for everyone, whoever runs them.
		$sessions = $wpdb->prefix . 'woocommerce_sessions';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sessions ) ) ) {
			$skip( 'NI-12(b) no woocommerce_sessions table' );
		} else {
			$health_was = get_transient( 'minn_admin_db_health' );
			$h_role     = Minn_Admin_DB::health_data( true );
			wp_set_current_user( $admin );
			$h_admin = Minn_Admin_DB::health_data( true );
			if ( false === $health_was ) {
				delete_transient( 'minn_admin_db_health' );
			} else {
				set_transient( 'minn_admin_db_health', $health_was, Minn_Admin_DB::HEALTH_TTL );
			}
			$check( 'NI-12(b) a run by a role without manage_woocommerce keeps the expired-sessions check', in_array( 'wc_sessions', wp_list_pluck( (array) $h_role['checks'], 'id' ), true ), implode( ',', wp_list_pluck( (array) $h_role['checks'], 'id' ) ) );
			$check( 'NI-12(b) control: an administrator\'s run has it', in_array( 'wc_sessions', wp_list_pluck( (array) $h_admin['checks'], 'id' ), true ) );
		}
		wp_set_current_user( $admin );
		$drop_user( $wc_uid );
	}
	if ( current_user_can( 'manage_woocommerce' ) ) {
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $d45['wc_addr'] ) );
		$check( 'OS-4 control: an administrator with manage_woocommerce browses the copy', 200 === $st && $has_text( $d['rows'] ?? null, 'Minnd45 Shopper' ), (string) $st );
	} else {
		$skip( 'OS-4 control: this administrator lacks manage_woocommerce' );
	}
}

// --- NI-12(a) A needle source that hit its limit but parsed to nothing --------
// The lab holds real credentials, so the state is simulated: while the probe
// runs, every needle-source query reads 2,000 empty values from a fixture.
$blank = $d45['blank'];
$sim   = function ( $sql ) use ( $blank ) {
	if ( preg_match( '/^SELECT `\w+` FROM `[^`]+` WHERE .+ LIMIT (2000|500)$/s', $sql, $m ) ) {
		return 'SELECT `v` FROM `' . $blank . '` LIMIT ' . $m[1];
	}
	return $sql;
};
add_filter( 'query', $sim );
$db_static( 'needles', null, true );
$sim_needles = $db_private( 'credential_needles' );
$sim_capped  = $db_static( 'needles_capped' );
list( $cap_st, $cap_c ) = $db_cell( $d45['plug'], 'id', $ids['plug']['row'], 'payload' );
list( $srch_st, $srch_d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $d45['plug'], 'fcol' => 'payload', 'fq' => 'Minnd45' ) );
list( $short_st, $short_c ) = $db_cell( $d45['plug'], 'id', $ids['plug']['row'], 'label' );
remove_filter( 'query', $sim );
$db_static( 'needles', null, true );
$db_static( 'needles_capped', false, true );
$check( 'NI-12(a) simulation: the copies list is capped and empty', array() === $sim_needles && true === $sim_capped, count( (array) $sim_needles ) . ' needles, capped ' . var_export( $sim_capped, true ) );
$check( 'NI-12(a) a capped list with no needles still withholds a long cell', 200 === $cap_st && is_array( $cap_c ) && ! empty( $cap_c['unchecked'] ), $cap_st . ' ' . wp_json_encode( $cap_c ) );
$check( 'NI-12(a) and refuses a value search', 400 === $srch_st && 'minn_db_search_capped' === ( $srch_d['code'] ?? '' ), $srch_st . ' ' . wp_json_encode( $srch_d['code'] ?? $srch_d['total'] ?? null ) );
$check( 'NI-12(a) control: a short cell still prints', 200 === $short_st && 'short' === $short_c, $short_st . ' ' . wp_json_encode( $short_c ) );
list( $st, $c ) = $db_cell( $d45['plug'], 'id', $ids['plug']['row'], 'payload' );
$check( 'NI-12(a) control: off the cap, the long cell prints', 200 === $st && $shown( $c, 'Minnd45 plugin payload' ), $st . ' ' . wp_json_encode( $c ) );
list( $st ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $d45['plug'], 'fcol' => 'payload', 'fq' => 'Minnd45' ) );
$check( 'NI-12(a) control: and a value search answers', 200 === $st, (string) $st );

// --- OS-5 ACF PRO's failed-activation record holds the pinned key ------------
$acf_key = 'MINND45ACF' . wp_generate_password( 30, false );
$check( 'OS-5 the licence secret rows name ACF\'s activation-error transients', function_exists( 'minn_admin_license_secret_options' ) && ! array_diff( array( '_transient_acf_activation_error', '_site_transient_acf_activation_error' ), minn_admin_license_secret_options()[0] ) );
if ( wp_using_ext_object_cache() ) {
	$skip( 'OS-5 a persistent object cache keeps transients out of the database' );
} else {
	$acf_names = array( '_transient_acf_activation_error', '_transient_timeout_acf_activation_error' );
	$acf_was   = array();
	foreach ( $acf_names as $n ) {
		$acf_was[ $n ] = $lic_raw( $n );
	}
	set_transient( 'acf_activation_error', array( 'error' => 'Minn D45 earlier error', 'license' => $acf_key ), HOUR_IN_SECONDS );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'fcol' => 'option_name', 'fq' => 'acf_activation_error' ) );
	$check( 'OS-5 the database browser prints no key from ACF\'s activation error', 200 === $st && $has_text( $d['rows'] ?? null, '_transient_acf_activation_error' ) && ! $has_text( $d['rows'] ?? null, $acf_key ), $st . ' ' . substr( wp_json_encode( $d['rows'] ?? $d ), 0, 200 ) );
	foreach ( $acf_was as $n => $raw ) {
		$lic_put( $n, $raw );
	}
	// The network's copy: a site transient, in sitemeta on a network.
	$site_table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
	$site_kcol  = is_multisite() ? 'meta_key' : 'option_name';
	$site_vcol  = is_multisite() ? 'meta_value' : 'option_value';
	$site_rows  = function () use ( $wpdb, $site_table, $site_kcol ) {
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$site_table}` WHERE `{$site_kcol}` IN ( '_site_transient_acf_activation_error', '_site_transient_timeout_acf_activation_error' )" . ( is_multisite() ? ' AND site_id = %d' : ' AND 1 = %d' ), is_multisite() ? get_current_network_id() : 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	};
	$site_was = $site_rows();
	set_site_transient( 'acf_activation_error', array( 'error' => 'Minn D45 earlier error', 'license' => $acf_key ), HOUR_IN_SECONDS );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $site_table, 'fcol' => $site_kcol, 'fq' => 'acf_activation_error' ) );
	$check( "OS-5 nor from its network copy ({$site_table})", 200 === $st && $has_text( $d['rows'] ?? null, '_site_transient_acf_activation_error' ) && ! $has_text( $d['rows'] ?? null, $acf_key ), $st . ' ' . substr( wp_json_encode( $d['rows'] ?? $d ), 0, 200 ) );
	delete_site_transient( 'acf_activation_error' );
	foreach ( $site_was as $row ) {
		$wpdb->replace( $site_table, $row );
	}
	wp_cache_flush();
}

$d45_drop();
$check( 'cleanup: the fixture tables are gone', ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $d45['blank'] ) ) ) && ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $d45['um_dot'] ) ) ) );

// ============================================================================
// Licences and the spam card.
// ============================================================================
$lic_can = function ( $source ) {
	foreach ( (array) ( minn_admin_licenses()['items'] ?? array() ) as $r ) {
		if ( ( $r['source'] ?? '' ) === $source ) {
			return (array) ( $r['can'] ?? array() );
		}
	}
	return null;
};
$lic_act = function ( $provider, $secret ) use ( $call ) {
	return $call( 'POST', '/minn-admin/v1/licenses/action', array( 'provider' => $provider, 'action' => 'activate', 'secret' => $secret ) );
};

// --- OS-5 A refused paste puts back no ACF error that holds a key ------------
if ( ! function_exists( 'acf_pro_get_license_option' ) || ! $lic_can( 'acf-pro' ) ) {
	$skip( 'OS-5 restore: ACF PRO offers no actions here' );
} elseif ( function_exists( 'acf_pro_is_legacy_multisite' ) && acf_pro_is_legacy_multisite() ) {
	$skip( 'OS-5 restore: legacy network (NI-13(b) covers it)' );
} elseif ( defined( 'ACF_PRO_LICENSE' ) && ACF_PRO_LICENSE ) {
	$skip( 'OS-5 restore: a key is pinned here, so the route refuses before ACF runs' );
} elseif ( wp_using_ext_object_cache() ) {
	$skip( 'OS-5 restore: a persistent object cache keeps transients out of the database' );
} else {
	$acf_names = array( 'acf_pro_license_status', '_transient_acf_activation_error', '_transient_timeout_acf_activation_error' );
	$acf_was   = array();
	foreach ( $acf_names as $n ) {
		$acf_was[ $n ] = $lic_raw( $n );
	}
	update_option( 'acf_pro_license_status', array( 'status' => 'active', 'name' => 'MinnD45', 'lifetime' => true, 'next_check' => time() + DAY_IN_SECONDS ), true );
	set_transient( 'acf_activation_error', array( 'error' => 'Minn D45 pinned-key error', 'license' => $acf_key ) );
	if ( function_exists( 'acf_get_store' ) && acf_get_store( 'acf_pro_license_status' ) ) {
		acf_get_store( 'acf_pro_license_status' )->reset();
	}
	$acf_mock = function ( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'connect.advancedcustomfields.com' ) ) {
			return new WP_Error( 'http_request_failed', 'Minn D45: offline' );
		}
		return array( 'headers' => array(), 'body' => wp_json_encode( array( 'status' => 0, 'message' => 'Minn D45: that key is not valid.' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	};
	add_filter( 'pre_http_request', $acf_mock, 1, 3 );
	list( $acf_st, $acf_d ) = $lic_act( 'acf-pro', 'minn-d45-not-a-key' );
	remove_filter( 'pre_http_request', $acf_mock, 1 );
	wp_cache_delete( 'acf_pro_license_status', 'options' );
	wp_cache_delete( '_transient_acf_activation_error', 'options' );
	$acf_err    = get_transient( 'acf_activation_error' );
	$acf_status = get_option( 'acf_pro_license_status' );
	foreach ( $acf_was as $n => $raw ) {
		$lic_put( $n, $raw );
	}
	$check( 'OS-5 a refused ACF paste does not put back an activation error holding a key', false === strpos( (string) wp_json_encode( $acf_err ), $acf_key ), $acf_st . ' ' . wp_json_encode( $acf_err ) );
	$check( 'OS-5 control: the working licence\'s status is still put back', is_array( $acf_status ) && 'MinnD45' === ( $acf_status['name'] ?? '' ) && empty( $acf_d['ok'] ), wp_json_encode( $acf_status ) );
}
// NI-13(b) needs a network whose ACF licence is legacy multisite, which no
// lab has; the closure skips its restore there (verified by reading).
$skip( 'NI-13(b) no legacy-multisite ACF network in the lab; verified by reading' );

// --- NI-13(a) EAEL: a refused paste rolls back only its error and back-off ---
// Essential Addons Pro, installed but inactive, is loaded for this run only:
// its hooks have all fired, so only its classes arrive. Nothing activates it.
$eael_file = WP_PLUGIN_DIR . '/essential-addons-elementor/essential_adons_elementor.php';
if ( ! defined( 'EAEL_PRO_PLUGIN_FILE' ) && file_exists( $eael_file ) && defined( 'EAEL_PLUGIN_VERSION' ) ) {
	require_once $eael_file;
}
if ( ! class_exists( '\Essential_Addons_Elementor\Pro\Classes\License\Manager' ) || ! $lic_can( 'essential-addons-elementor' ) ) {
	$skip( 'NI-13(a) Essential Addons Pro unavailable' );
} else {
	$eael_like = array( 'essential-addons-elementor\_license%', 'essential-addons-elementor-license%', 'wpdeveloper\_sl\_failed\_http\_%' );
	$eael_snap = function () use ( $wpdb, $eael_like ) {
		$out = array();
		foreach ( $eael_like as $l ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $l ), ARRAY_A ) as $r ) {
				$out[ $r['option_name'] ] = $r['option_value'];
			}
		}
		return $out;
	};
	$eael_was  = $eael_snap();
	// Another administrator activates a key on EAEL's own screen while
	// Minn's paste is waiting on the licence server.
	$eael_mock = function ( $pre, $args, $url ) {
		update_option( 'essential-addons-elementor_license', 'MINND45-CONCURRENT-KEY', 'no' );
		update_option( 'essential-addons-elementor_license_status', 'valid', 'no' );
		return new WP_Error( 'http_request_failed', 'Minn D45: offline' );
	};
	add_filter( 'pre_http_request', $eael_mock, 1, 3 );
	list( $eael_st, $eael_d ) = $lic_act( 'essential-addons-elementor', 'minn-d45-not-a-key' );
	remove_filter( 'pre_http_request', $eael_mock, 1 );
	wp_cache_delete( 'alloptions', 'options' );
	$eael_after = $eael_snap();
	$eael_err   = 'essential-addons-elementor_license_data_error';
	foreach ( array_keys( $eael_was + $eael_after ) as $eael_n ) {
		$lic_put( $eael_n, $eael_was[ $eael_n ] ?? null );
	}
	$check( 'NI-13(a) a concurrent activation on EAEL\'s own screen survives a refused paste', 'MINND45-CONCURRENT-KEY' === ( $eael_after['essential-addons-elementor_license'] ?? '' ) && 'valid' === ( $eael_after['essential-addons-elementor_license_status'] ?? '' ), wp_json_encode( array( $eael_after['essential-addons-elementor_license'] ?? null, $eael_after['essential-addons-elementor_license_status'] ?? null ) ) );
	$backoff_was   = array_filter( $eael_was, function ( $k ) { return 0 === strpos( $k, 'wpdeveloper_sl_failed_http_' ); }, ARRAY_FILTER_USE_KEY );
	$backoff_after = array_filter( $eael_after, function ( $k ) { return 0 === strpos( $k, 'wpdeveloper_sl_failed_http_' ); }, ARRAY_FILTER_USE_KEY );
	$check( 'NI-13(a) control: the error and back-off rows are still put back', ( $eael_was[ $eael_err ] ?? null ) === ( $eael_after[ $eael_err ] ?? null ) && $backoff_was === $backoff_after && empty( $eael_d['ok'] ), $eael_st . ' ' . wp_json_encode( array( $eael_after[ $eael_err ] ?? null, $backoff_after ) ) );
	$check( 'NI-13(a) cleanup: EAEL\'s licence rows are as they were', $eael_was === $eael_snap() );
}

// --- OS-9 LayerSlider: licence actions follow its custom capability ----------
// No action runs, so an inactive LayerSlider gets a stand-in for its updater.
$ls_stub = false;
if ( empty( $GLOBALS['LS_AutoUpdate'] ) ) {
	$GLOBALS['LS_AutoUpdate'] = new class() {
		public function handleActivation( $code, $args = array() ) {
			return (object) array( 'errCode' => 'minn-d45', 'message' => 'Minn D45 stand-in' );
		}
	};
	$ls_stub = true;
}
$ls_default = $lic_can( 'layerslider' );
if ( ! $ls_default ) {
	$skip( 'OS-9 LayerSlider not installed' );
} else {
	$ls_was = $lic_raw( 'layerslider_custom_capability' );
	update_option( 'layerslider_custom_capability', 'minn_d45_ls_cap' );
	$ls_narrow = $lic_can( 'layerslider' );
	update_option( 'layerslider_custom_capability', 'edit_posts' );
	$ls_held = $lic_can( 'layerslider' );
	$lic_put( 'layerslider_custom_capability', $ls_was );
	$check( 'OS-9 no LayerSlider licence actions for an administrator its custom capability leaves out', array() === $ls_narrow, wp_json_encode( $ls_narrow ) );
	$check( 'OS-9 control: an administrator who holds the custom capability keeps them', (bool) $ls_held, wp_json_encode( $ls_held ) );
	$check( 'OS-9 control: with no custom capability set, the administrator keeps them', (bool) $ls_default, wp_json_encode( $ls_default ) );
}
if ( $ls_stub ) {
	unset( $GLOBALS['LS_AutoUpdate'] );
}

// --- OS-9 sweep: Freemius and Slider Revolution ask their own caps -----------
if ( ! $lic_can( 'independent-analytics-pro' ) ) {
	$skip( 'OS-9 sweep: Independent Analytics Pro offers no actions here' );
} elseif ( is_multisite() ) {
	$skip( 'OS-9 sweep: Freemius asks activate_plugins on a single site only' );
} else {
	$fs_uid = $make_user( 'administrator' );
	if ( ! $fs_uid ) {
		$check( 'OS-9 sweep fixture user', false );
	} else {
		( new WP_User( $fs_uid ) )->add_cap( 'activate_plugins', false );
		wp_set_current_user( $fs_uid );
		$fs_can = $lic_can( 'independent-analytics-pro' );
		wp_set_current_user( $admin );
		$drop_user( $fs_uid );
		$check( 'OS-9 sweep: no Freemius licence actions without activate_plugins (Independent Analytics Pro)', array() === $fs_can, wp_json_encode( $fs_can ) );
		$check( 'OS-9 sweep control: an administrator keeps them', (bool) $lic_can( 'independent-analytics-pro' ) );
	}
}
$rs_gate = function_exists( 'minn_admin_license_vendor_gates' ) ? ( minn_admin_license_vendor_gates()['revslider'] ?? null ) : null;
$rs_was  = $lic_raw( 'revslider-global-settings' );
$rs_uid  = $make_user( 'administrator' );
if ( ! $rs_uid ) {
	$check( 'OS-9 sweep fixture user', false );
} else {
	( new WP_User( $rs_uid ) )->add_cap( 'edit_pages', false );
	update_option( 'revslider-global-settings', wp_json_encode( array( 'permission' => 'editor' ) ) );
	wp_set_current_user( $rs_uid );
	$rs_narrow = is_callable( $rs_gate ) ? $rs_gate() : true;
	wp_set_current_user( $admin );
	$rs_admin = is_callable( $rs_gate ) ? $rs_gate() : null;
	update_option( 'revslider-global-settings', wp_json_encode( array( 'permission' => 'admin' ) ) );
	$rs_default = is_callable( $rs_gate ) ? $rs_gate() : null;
	$lic_put( 'revslider-global-settings', $rs_was );
	$drop_user( $rs_uid );
	$check( 'OS-9 sweep: Slider Revolution set to Editor leaves out a role without edit_pages', false === $rs_narrow, var_export( $rs_narrow, true ) );
	$check( 'OS-9 sweep control: an administrator passes it, either setting', true === $rs_admin && true === $rs_default );
}

// --- NI-7 Kadence Blocks Pro on a site with Liquid Web's Harbor library ------
// Last: the Harbor stand-in cannot be removed from this process.
if ( ! $lic_can( 'kadence-blocks-pro' ) ) {
	$skip( 'NI-7 Kadence Blocks Pro offers no actions here' );
} else {
	add_filter( 'kadence_blocks_pro_should_display_uplink_license_field', '__return_false', 999 );
	$kb_without = function_exists( 'lw_harbor_get_product_activation_base_url' ) ? null : $lic_can( 'kadence-blocks-pro' );
	if ( ! function_exists( 'lw_harbor_get_product_activation_base_url' ) ) {
		/** Stand-in for Liquid Web's Harbor library. */
		function lw_harbor_get_product_activation_base_url() {
			return 'https://example.com/minn-d45-harbor/';
		}
	}
	$kb_with = $lic_can( 'kadence-blocks-pro' );
	remove_filter( 'kadence_blocks_pro_should_display_uplink_license_field', '__return_false', 999 );
	$check( 'NI-7 on a Harbor site, Kadence\'s hidden legacy field leaves the administrator their actions', (bool) $kb_with, wp_json_encode( $kb_with ) );
	if ( null === $kb_without ) {
		$skip( 'NI-7 control: Harbor is really installed here' );
	} else {
		$check( 'NI-7 control: without Harbor, the hidden field still removes them', array() === $kb_without, wp_json_encode( $kb_without ) );
	}
}

$summary();
