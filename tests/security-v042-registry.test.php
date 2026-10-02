<?php
/**
 * Regressions for the v0.42.0 shipped-zip registry audit (WP Registry #2189).
 *
 * Most of that round is one class: a Minn save that rewrites vendor state the
 * user never touched. Each section replays what Minn's client sends through the
 * route it actually calls and checks the stored result, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive. Browser-
 * level halves live in stored-choices.test.js and tec-panel.test.js.
 *
 * Run: wp eval-file tests/security-v042-registry.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v042-registry: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// --- #1 Redirection in-place edit keeps conditions, flags and order -------
if ( class_exists( 'Red_Item' ) ) {
	global $wpdb;
	$surfaces = Minn_Admin_Surfaces::all();
	$edit     = $surfaces['redirection']['collection']['detail']['edit'];
	$deep     = function ( $item, $k ) {
		foreach ( explode( '.', $k ) as $p ) {
			$item = is_array( $item ) && array_key_exists( $p, $item ) ? $item[ $p ] : null;
		}
		return $item;
	};
	$row      = function ( $id ) use ( $wpdb ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT match_data, position, action_data FROM {$wpdb->prefix}redirection_items WHERE id=%d", $id ), ARRAY_A );
	};
	$cases    = array(
		'login rule' => array( 'url' => '/minn-v042-login-' . time(), 'match_type' => 'login', 'action_type' => 'url', 'action_code' => 301, 'group_id' => 1, 'action_data' => array( 'logged_in' => '/members', 'logged_out' => '/login' ) ),
		'flagged rule at position 5' => array( 'url' => '/minn-v042-flags-' . time(), 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301, 'group_id' => 1, 'position' => 5, 'match_data' => array( 'source' => array( 'flag_query' => 'pass' ) ), 'action_data' => array( 'url' => '/flag-target' ) ),
	);
	foreach ( $cases as $label => $args ) {
		$item = Red_Item::create( $args );
		if ( is_wp_error( $item ) ) {
			$check( "Redirection seed: {$label}", false, $item->get_error_message() );
			continue;
		}
		$id   = $item->get_id();
		$json = Red_Item::get_by_id( $id )->to_json();
		// What the client sends: preserve keys, then every edit field.
		$body = array();
		foreach ( (array) $edit['preserve'] as $k ) {
			$v = $deep( $json, $k );
			if ( null !== $v ) {
				$body[ $k ] = $v;
			}
		}
		foreach ( $edit['fields'] as $f ) {
			$v     = $deep( $json, $f['key'] );
			$parts = explode( '.', $f['key'] );
			$ref   = &$body;
			foreach ( array_slice( $parts, 0, -1 ) as $p ) {
				if ( ! isset( $ref[ $p ] ) || ! is_array( $ref[ $p ] ) ) {
					$ref[ $p ] = array();
				}
				$ref = &$ref[ $p ];
			}
			$ref[ end( $parts ) ] = null === $v ? '' : $v;
			unset( $ref );
		}
		list( $st ) = $call( 'POST', '/redirection/v1/redirect/' . $id, $body );
		$minn       = $row( $id );
		// Vendor control: Redirection's own UI posts the whole item back.
		$call( 'POST', '/redirection/v1/redirect/' . $id, $json );
		$vendor = $row( $id );
		$check( "Redirection unchanged save keeps the {$label} exactly as Redirection's own save would", 200 === $st && $minn == $vendor, wp_json_encode( array( 'minn' => $minn, 'vendor' => $vendor ) ) );
		$wpdb->delete( $wpdb->prefix . 'redirection_items', array( 'id' => $id ) );
	}

	// --- #19 the two log retentions are independent ----------------------
	$orig = red_get_options();
	red_set_options( array( 'expire_redirect' => 7, 'expire_404' => -1 ) );
	list( $st ) = $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'expire_redirect' => '30' ) ) );
	$o          = red_get_options();
	$check( 'Redirection: changing the redirect log leaves a disabled 404 log off', 200 === $st && 30 === (int) $o['expire_redirect'] && -1 === (int) $o['expire_404'], wp_json_encode( array( $o['expire_redirect'], $o['expire_404'] ) ) );
	$call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'expire_404' => '0' ) ) );
	$o = red_get_options();
	$check( 'Redirection: "forever" (0) is storable for a log', 0 === (int) $o['expire_404'], (string) $o['expire_404'] );
	list( $st ) = $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'expire_404' => 'soon' ) ) );
	$check( 'Redirection: a retention outside the vocabulary is refused', 400 === $st, (string) $st );
	red_set_options( array( 'expire_redirect' => $orig['expire_redirect'], 'expire_404' => $orig['expire_404'] ) );
} else {
	$skip( 'Redirection inactive' );
}

// --- #9 / #33 ACF field schema round trips ---------------------------------
if ( function_exists( 'acf_update_field_group' ) && function_exists( 'acf_get_store' ) ) {
	$gkey  = 'group_minn_v042_' . strtolower( wp_generate_password( 6, false ) );
	$group = acf_update_field_group( array( 'key' => $gkey, 'title' => 'Minn v042 probe', 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ) ) );
	$req_f = acf_update_field( array( 'key' => 'field_' . strtolower( wp_generate_password( 8, false ) ), 'label' => 'Must', 'name' => 'minn_v042_must', 'type' => 'text', 'required' => 1, 'parent' => $group['ID'] ) );
	$sel_f = acf_update_field( array(
		'key'           => 'field_' . strtolower( wp_generate_password( 8, false ) ),
		'label'         => 'Ratio',
		'name'          => 'minn_v042_ratio',
		'type'          => 'select',
		'multiple'      => 1,
		'choices'       => array( '16:9' => 'Widescreen', '4:3' => 'Standard' ),
		'default_value' => array( '16:9', '4:3' ),
		'parent'        => $group['ID'],
	) );
	acf_get_store( 'field-groups' )->reset();
	acf_get_store( 'fields' )->reset();

	// The Fields view row under a German locale, saved back the way the client
	// does: a seed that is not one of the select's values falls back to 'No'.
	update_user_meta( $admin, 'locale', 'de_DE' );
	switch_to_user_locale( $admin );
	list( , $list ) = $call( 'GET', '/minn-admin/v1/acf/schema/groups/' . $gkey . '/fields' );
	$item           = null;
	foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
		if ( $it['id'] === $req_f['key'] ) {
			$item = $it;
		}
	}
	$seed = $item['required'] ?? null;
	$sent = in_array( $seed, array( 'No', 'Yes' ), true ) ? $seed : 'No';
	$call( 'PUT', '/minn-admin/v1/acf/schema/fields/' . $req_f['key'], array( 'label' => 'Must (relabelled)', 'default_value' => $item['default_value'] ?? '', 'choices' => $item['choices'] ?? '', 'required' => $sent ) );
	restore_current_locale();
	delete_user_meta( $admin, 'locale' );
	acf_get_store( 'fields' )->reset();
	$after = acf_get_field( $req_f['key'] );
	$check( 'ACF: Required seeds as an option value on a translated site', 'Yes' === $seed, var_export( $seed, true ) );
	$check( 'ACF: relabelling a required field keeps it required', ! empty( $after['required'] ), var_export( $after['required'] ?? null, true ) );

	list( , $full ) = $call( 'GET', '/minn-admin/v1/acf/schema/groups/' . $gkey . '/full' );
	list( $st )     = $call( 'POST', '/minn-admin/v1/acf/schema/groups/' . $gkey . '/full', $full );
	acf_get_store( 'fields' )->reset();
	$sel = acf_get_field( $sel_f['key'] );
	$check( 'ACF builder: an unchanged save keeps choice values that contain a colon', 200 === $st && array( '16:9' => 'Widescreen', '4:3' => 'Standard' ) === $sel['choices'], wp_json_encode( $sel['choices'] ) );
	$check( 'ACF builder: an unchanged save keeps a multiple-select default', array( '16:9', '4:3' ) === array_values( (array) $sel['default_value'] ), wp_json_encode( $sel['default_value'] ) );
	acf_delete_field_group( $group['ID'] );
} else {
	$skip( 'ACF inactive' );
}

$summary();
