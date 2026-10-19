<?php
/**
 * The settings tier's rules, one check each, over every screen declared
 * through minn_admin_settings() and a fixture screen of the suite's own.
 *
 *  R1 declared whole   a declaration with a key the tier does not read, or
 *                      without its gate, layout, read or write, gets no route;
 *                      every declared screen answers GET and POST.
 *  R2 shared types     only fields in the settings form's types are offered,
 *                      and none of the adapter's own "_" keys leaves.
 *  R3 untouched        the values a page was served, sent back as served
 *                      (a credential as its [redacted:…] placeholder),
 *                      change nothing and write nothing.
 *  R4 masks            a placeholder left in an edited value is refused
 *                      (400 minn_egress_mask) and nothing is written; on a
 *                      'restore' (options-API) screen one goes back to its
 *                      credential where the page was shown it, byte for
 *                      byte, and one anywhere else is still refused.
 *  R5 field gates      a raw-output field needs unfiltered_html and code
 *                      edits left on (checked last: the constant stays), and the
 *                      screen's own per-field gate is asked, before anything
 *                      is written; an untouched gated field blocks nothing.
 *  R6 validator first  the plugin's validator is asked about every change
 *                      before the first write, and its refusal writes nothing.
 *  R7 named refusals   every refusal names its fields ({ key, label,
 *                      message }, the first in data.field) and writes nothing.
 *  R8 stale copy       a body whose base says the page loaded a value no
 *                      longer stored answers 409, compared as served so a
 *                      base cannot test a guess at a credential, and after
 *                      the field gates; a body without a base (an app cached
 *                      from before) saves as it did.
 *
 * Live screens run with the tier stopped where it would write
 * (Minn_Admin_Kernel_Settings::dry()), so nothing on the lab changes; the
 * fixture writes to memory. A screen the runner cannot address fails, so a
 * new screen comes with a way to reach it (see $addresses).
 *
 * To prove a check against a broken tier, load a copy of the plugin whose
 * kernel/class-minn-admin-kernel-settings.php is broken, with this one
 * skipped: MINN_ST_PLUGIN=<copy>/minn-admin.php wp --skip-plugins=minn-admin eval-file …
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/settings-tier-conformance.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

if ( getenv( 'MINN_ST_PLUGIN' ) && ! defined( 'MINN_ADMIN_FILE' ) ) {
	require getenv( 'MINN_ST_PLUGIN' );
}

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$once    = static function ( callable $fn ) {
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

if ( ! class_exists( 'Minn_Admin_Kernel_Settings' ) || ! function_exists( 'minn_admin_settings' ) || ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}

$tag = strtolower( wp_generate_password( 6, false ) );
$fp  = static function ( $k ) {
	return '[redacted:' . substr( hash( 'sha256', $k ), 0, 6 ) . ']';
};

// A credential the egress fence knows (a licence-shaped row), seeded before
// anything reads the list, so the fixture can serve it masked.
$key  = 'MinnSettingsTier' . strtoupper( wp_generate_password( 10, false ) ) . '7731qz';
$cred = 'pue_install_key_minnst' . $tag;
add_option( $cred, $key, '', 'no' );
// Two more: one holding a hyphen, one holding a slash (whose URL-encoded copy
// differs from it), for the restore's harder cases.
$hy = 'MinnTierHyphen' . strtoupper( wp_generate_password( 8, false ) ) . '-Tail' . strtoupper( wp_generate_password( 8, false ) ) . '4422qz';
$sl = 'MinnTierSlash' . strtoupper( wp_generate_password( 8, false ) ) . '/Tail' . strtoupper( wp_generate_password( 8, false ) ) . '9031qz';
add_option( $cred . 'hy', $hy, '', 'no' );
add_option( $cred . 'sl', $sl, '', 'no' );
Minn_Admin_Egress::reset();

$strip  = false;
$filter = static function ( $caps, $cap ) use ( &$strip ) {
	return $strip && 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
};
add_filter( 'map_meta_cap', $filter, 10, 2 );

$clean = $once( static function () use ( $cred, $filter ) {
	Minn_Admin_Kernel_Settings::dry( false );
	remove_filter( 'map_meta_cap', $filter, 10 );
	delete_option( $cred );
	delete_option( $cred . 'hy' );
	delete_option( $cred . 'sl' );
	Minn_Admin_Egress::reset();
} );

/*
 * The fixture screen: a store in memory, every rule's case in one tab.
 */
$GLOBALS['minn_st'] = array(
	'store' => array(),
	'log'   => array(),
	'may'   => true,
);
$fixture_store = array(
	'plain'   => 'One',
	'flag'    => '1',
	'code'    => '<b>bold</b>',
	'gated'   => 'Gate',
	'checked' => 'ok',
	'secret'  => 'Endpoint ' . $key,
	'odd'     => 'cells',
	'rows'    => array( array( 'svc' => 'a', 'key' => $key ), array( 'svc' => 'b', 'key' => 'x' ) ),
	'num'     => '1234567890123456789',
	'far'     => 'away',
	'pair'    => $hy . '-' . $key,
	'snippet' => "<script>k='" . $key . "'</script>",
	'links'   => array( array( 'svc' => 'a', 'key' => $sl ), array( 'svc' => 'b', 'key' => rawurlencode( $sl ) ), array( 'svc' => 'c', 'key' => 'x' ) ),
);
$reset_fixture = static function () use ( $fixture_store ) {
	$GLOBALS['minn_st']['store'] = $fixture_store;
	$GLOBALS['minn_st']['log']   = array();
	$GLOBALS['minn_st']['may']   = true;
};
$reset_fixture();
$fixture = array(
	'route'    => 'minn-st-fixture/(?P<tab>[a-z]+)',
	'gate'     => function ( WP_REST_Request $r ) {
		return current_user_can( 'manage_options' );
	},
	'layout'   => function ( $ctx ) {
		if ( null !== $ctx['tab'] && ! in_array( $ctx['tab'], array( 'main', 'side' ), true ) ) {
			return null;
		}
		$f = function ( $key, $label, $type, $extra = array() ) {
			return array_merge( array( 'key' => $key, 'label' => $label, 'type' => $type, '_at' => $key ), $extra );
		};
		if ( 'side' === $ctx['tab'] ) {
			return array( 'groups' => array( array( 'title' => 'Side', 'fields' => array( $f( 'elsewhere', 'Elsewhere', 'text', array( '_at' => 'far' ) ) ) ) ) );
		}
		return array(
			'groups'   => array(
				array(
					'title'  => 'Fixture',
					'fields' => array(
						$f( 'plain', 'Plain', 'text' ),
						$f( 'flag', 'Flag', 'toggle' ),
						$f( 'code', 'Code', 'textarea', array( '_raw' => true ) ),
						$f( 'gated', 'Gated', 'text' ),
						$f( 'checked', 'Checked', 'text' ),
						$f( 'secret', 'Endpoint', 'text' ),
						$f( 'odd', 'Odd', 'spreadsheet' ),
						$f( 'rows', 'Integrations', 'rows' ),
						$f( 'num', 'Account id', 'number' ),
						$f( 'pair', 'Key pair', 'text' ),
						$f( 'snippet', 'Snippet', 'textarea', array( '_raw' => true ) ),
						$f( 'links', 'Links', 'rows' ),
					),
					'locked' => 0,
				),
			),
			'adminUrl' => '',
		);
	},
	'read'     => function ( $field ) {
		$v = $GLOBALS['minn_st']['store'][ $field['_at'] ] ?? '';
		return 'toggle' === $field['type'] ? ! empty( $v ) : $v;
	},
	'may'      => function ( $field ) {
		return 'gated' !== $field['key'] || $GLOBALS['minn_st']['may'];
	},
	'validate' => function ( $changes ) {
		$GLOBALS['minn_st']['log'][] = 'validate';
		if ( isset( $changes['checked'] ) && 'bad' === $changes['checked'] ) {
			return new WP_Error( 'minn_st_invalid', 'Not that one.', array( 'status' => 400, 'field' => 'checked' ) );
		}
		if ( isset( $changes['checked'] ) && 'vague' === $changes['checked'] ) {
			return new WP_Error( 'minn_st_invalid', 'Something is off.', array( 'status' => 400 ) );
		}
		return null;
	},
	'write'    => function ( $changes, $fields ) {
		$GLOBALS['minn_st']['log'][] = 'write';
		foreach ( $changes as $k => $v ) {
			$GLOBALS['minn_st']['store'][ $fields[ $k ]['_at'] ] = 'toggle' === $fields[ $k ]['type'] ? ( Minn_Admin_Kernel_Settings::on( $v ) ? '1' : '' ) : $v;
		}
		return true;
	},
);

/*
 * R1, the declaration half: one the tier cannot read whole gets no route.
 */
$bad_decls = array(
	'an unknown key'   => array_merge( $fixture, array( 'route' => 'minn-st-bad-a', 'writes' => '__return_true' ) ),
	'no write'         => array_diff_key( array_merge( $fixture, array( 'route' => 'minn-st-bad-b' ) ), array( 'write' => 1 ) ),
	'no gate'          => array_diff_key( array_merge( $fixture, array( 'route' => 'minn-st-bad-c' ) ), array( 'gate' => 1 ) ),
	'a read it cannot call' => array_merge( $fixture, array( 'route' => 'minn-st-bad-d', 'read' => 'minn_st_no_such_function' ) ),
	'a gate that takes something else' => array_merge( $fixture, array( 'route' => 'minn-st-bad-e', 'gate' => function ( $user ) {
		return true;
	} ) ),
);
$bad_taken = array();
foreach ( $bad_decls as $why => $decl ) {
	if ( minn_admin_settings( 'minn-st-bad-' . md5( $why ), $decl ) ) {
		$bad_taken[] = $why;
	}
}
$declared = minn_admin_settings( 'minn-st-fixture', $fixture );
// The same screen over an options-API store, where a placeholder sent back
// goes back to its credential.
$declared = minn_admin_settings( 'minn-st-options', array_merge( $fixture, array( 'route' => 'minn-st-options/(?P<tab>[a-z]+)', 'restore' => true ) ) ) && $declared;

// What a browser receives: dispatch, the server's response filters, the
// body as sent (the egress fence runs on rest_pre_echo_response).
$server = rest_get_server();
$send   = static function ( $method, $route, $body = null ) use ( $server ) {
	$req = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	return array( $res->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
};
// A value as a response would carry it.
$masked = static function ( $data, $route ) use ( $server ) {
	return json_decode( (string) wp_json_encode( apply_filters( 'rest_pre_echo_response', $data, $server, new WP_REST_Request( 'GET', $route ) ) ), true );
};
$routes  = $server->get_routes();
$methods = static function ( $route ) use ( $routes ) {
	$out = array();
	foreach ( (array) ( $routes[ $route ] ?? array() ) as $h ) {
		$out = array_merge( $out, array_keys( (array) ( $h['methods'] ?? array() ) ) );
	}
	return $out;
};
$names_ok = static function ( $d, array $keys ) {
	$fields = (array) ( $d['data']['fields'] ?? array() );
	foreach ( $fields as $f ) {
		if ( ! is_array( $f ) || ! isset( $f['key'], $f['label'], $f['message'] ) || '' === (string) $f['label'] || '' === (string) $f['message'] ) {
			return false;
		}
	}
	return $keys === array_column( $fields, 'key' ) && ( $keys[0] ?? null ) === ( $d['data']['field'] ?? null );
};
$wrote_nothing = static function () {
	return ! in_array( 'write', $GLOBALS['minn_st']['log'], true );
};

try {
	/* ---------- The fixture: each rule against its own case ---------- */
	$fx = '/minn-admin/v1/minn-st-fixture/main';

	$check( 'R1 declared whole: a declaration with an unknown key, no write, no gate, a read it cannot call or a gate that takes something else is refused', ! $bad_taken && 5 <= count( Minn_Admin_Kernel_Settings::refused() ), wp_json_encode( $bad_taken ) );
	$no_route = true;
	foreach ( array( 'a', 'b', 'c', 'd', 'e' ) as $x ) {
		$no_route = $no_route && ! isset( $routes[ '/minn-admin/v1/minn-st-bad-' . $x ] );
	}
	$check( 'R1 declared whole: and gets no route', $no_route );
	$check( 'R1 declared whole: the fixture answers GET and POST', $declared && ! array_diff( array( 'GET', 'POST' ), $methods( '/minn-admin/v1/minn-st-fixture/(?P<tab>[a-z]+)' ) ), wp_json_encode( $methods( '/minn-admin/v1/minn-st-fixture/(?P<tab>[a-z]+)' ) ) );

	list( $st, $page ) = $send( 'GET', $fx );
	$offered = array();
	$leaked  = array();
	foreach ( (array) ( $page['groups'] ?? array() ) as $g ) {
		foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
			$offered[] = $f['key'];
			foreach ( array_keys( $f ) as $k ) {
				if ( 0 === strpos( (string) $k, '_' ) ) {
					$leaked[] = $f['key'] . '.' . $k;
				}
			}
		}
	}
	$check( 'R2 shared types: a field of a type the form cannot draw is not offered, nor its value served', 200 === $st && ! in_array( 'odd', $offered, true ) && ! array_key_exists( 'odd', (array) ( $page['values'] ?? array() ) ) && 11 === count( $offered ), $st . ' ' . wp_json_encode( $offered ) );
	$check( 'R2 shared types: none of the adapter\'s own keys leaves', ! $leaked, wp_json_encode( $leaked ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'odd' => 'changed' ) ) );
	$check( 'R2 shared types: nor is it taken', 200 === $st && 'cells' === $GLOBALS['minn_st']['store']['odd'] && $wrote_nothing(), $st . ' ' . wp_json_encode( $GLOBALS['minn_st'] ) );

	$values = (array) ( $page['values'] ?? array() );
	$check( 'R3 untouched: the credential is served as its placeholder', 'Endpoint ' . $fp( $key ) === ( $values['secret'] ?? null ), wp_json_encode( $values['secret'] ?? null ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => $values ) );
	$check( 'R3 untouched: every served value sent back changes nothing and writes nothing', 200 === $st && $wrote_nothing() && array() === ( Minn_Admin_Kernel_Settings::journal()['changes'] ?? null ) && $fixture_store === $GLOBALS['minn_st']['store'], $st . ' ' . wp_json_encode( array( Minn_Admin_Kernel_Settings::journal(), $GLOBALS['minn_st']['log'] ) ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'flag' => true, 'plain' => 'One' ) ) );
	$check( 'R3 untouched: a switch sent in another spelling of its state is no change', 200 === $st && $wrote_nothing(), wp_json_encode( Minn_Admin_Kernel_Settings::journal() ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'secret' => $values['secret'] ) ) );
	$check( 'R3 untouched: beside a real edit, only the edit is written', 200 === $st && 'Two' === $GLOBALS['minn_st']['store']['plain'] && 'Endpoint ' . $key === $GLOBALS['minn_st']['store']['secret'] && array( 'plain' ) === Minn_Admin_Kernel_Settings::journal()['changes'], wp_json_encode( $GLOBALS['minn_st']['store'] ) );

	$reset_fixture();
	list( $st, $d ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'secret' => $values['secret'] . ' v2' ) ) );
	$check( 'R4 masks: an edited value that keeps the placeholder is refused with 400 minn_egress_mask', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
	$check( 'R4 masks: and nothing is written, not the edit beside it either', $wrote_nothing() && $fixture_store === $GLOBALS['minn_st']['store'], wp_json_encode( $GLOBALS['minn_st'] ) );
	$check( 'R7 named refusals: the mask refusal names the field', $names_ok( $d, array( 'secret' ) ), wp_json_encode( $d['data'] ?? null ) );

	$reset_fixture();
	$strip          = true;
	list( $st, $d ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'code' => '<i>new</i>' ) ) );
	$check( 'R5 field gates: without unfiltered_html, a changed raw-output field refuses the save with 403', 403 === $st, $st . ' ' . wp_json_encode( $d ) );
	$check( 'R5 field gates: before anything is written, the plain edit beside it included', $wrote_nothing() && 'One' === $GLOBALS['minn_st']['store']['plain'], wp_json_encode( $GLOBALS['minn_st'] ) );
	$check( 'R7 named refusals: the raw-output refusal names the field', $names_ok( $d, array( 'code' ) ), wp_json_encode( $d['data'] ?? null ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'code' => $fixture_store['code'] ) ) );
	$check( 'R5 field gates control: a raw-output field sent back as served blocks nothing', 200 === $st && 'Two' === $GLOBALS['minn_st']['store']['plain'], $st );
	$strip = false;
	$reset_fixture();
	$GLOBALS['minn_st']['may'] = false;
	list( $st, $d )            = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'gated' => 'Open' ) ) );
	$check( 'R5 field gates: the screen\'s own per-field gate refuses with 403 before any write', 403 === $st && $wrote_nothing() && 'One' === $GLOBALS['minn_st']['store']['plain'], $st . ' ' . wp_json_encode( $GLOBALS['minn_st'] ) );
	$check( 'R7 named refusals: the per-field gate names the field', $names_ok( $d, array( 'gated' ) ), wp_json_encode( $d['data'] ?? null ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'gated' => 'Open' ) ) );
	$check( 'R5 field gates control: a caller the gate admits saves', 200 === $st && 'Open' === $GLOBALS['minn_st']['store']['gated'], $st );

	$reset_fixture();
	list( $st, $d ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'checked' => 'bad' ) ) );
	$check( 'R6 validator first: its refusal answers its own error, and nothing is written', 400 === $st && 'minn_st_invalid' === ( $d['code'] ?? '' ) && $wrote_nothing() && 'One' === $GLOBALS['minn_st']['store']['plain'], $st . ' ' . wp_json_encode( $GLOBALS['minn_st'] ) );
	$check( 'R7 named refusals: the validator\'s refusal names the field it gave', $names_ok( $d, array( 'checked' ) ), wp_json_encode( $d['data'] ?? null ) );
	$reset_fixture();
	list( $st, $d ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'checked' => 'vague' ) ) );
	$check( 'R7 named refusals: a refusal that names nothing names every change it was asked about', 400 === $st && $names_ok( $d, array( 'plain', 'checked' ) ) && $wrote_nothing(), wp_json_encode( $d['data'] ?? null ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'checked' => 'fine' ) ) );
	$check( 'R6 validator first: a clean save asks it once, then writes once', 200 === $st && array( 'validate', 'write' ) === $GLOBALS['minn_st']['log'] && 'fine' === $GLOBALS['minn_st']['store']['checked'], wp_json_encode( $GLOBALS['minn_st']['log'] ) );

	$reset_fixture();
	list( $st, $d ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two' ), 'base' => array( 'plain' => 'Zero' ) ) );
	$check( 'R8 stale copy: a base the store no longer holds answers 409 minn_stale', 409 === $st && 'minn_stale' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
	$check( 'R8 stale copy: and writes nothing', $wrote_nothing() && 'One' === $GLOBALS['minn_st']['store']['plain'], wp_json_encode( $GLOBALS['minn_st'] ) );
	$check( 'R7 named refusals: the 409 names the field', $names_ok( $d, array( 'plain' ) ), wp_json_encode( $d['data'] ?? null ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'secret' => $values['secret'] ), 'base' => array( 'plain' => 'One', 'secret' => $values['secret'] ) ) );
	$check( 'R8 stale copy control: the base the page loaded (a placeholder included) saves', 200 === $st && 'Two' === $GLOBALS['minn_st']['store']['plain'], $st );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two' ) ) );
	$check( 'R8 stale copy control: a body with no base saves as before', 200 === $st && 'Two' === $GLOBALS['minn_st']['store']['plain'], $st );

	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'elsewhere' => 'here' ) ) );
	$check( 'R2 shared types: a key from another tab is ignored', 200 === $st && array() === ( Minn_Admin_Kernel_Settings::journal()['changes'] ?? null ) && 'away' === $GLOBALS['minn_st']['store']['far'] && $wrote_nothing(), $st . ' ' . wp_json_encode( Minn_Admin_Kernel_Settings::journal() ) );

	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'flag' => 'off' ) ) );
	$check( 'R3 untouched: a switch sent as "off" reads as off in the compare and the write alike', 200 === $st && '' === $GLOBALS['minn_st']['store']['flag'], $st . ' ' . wp_json_encode( $GLOBALS['minn_st']['store']['flag'] ) );

	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'num' => '01234567890123456789' ) ) );
	$check( 'R3 untouched: a long whole number in another spelling is no change', 200 === $st && $wrote_nothing(), wp_json_encode( Minn_Admin_Kernel_Settings::journal() ) );
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'num' => '1234567890123456790' ) ) );
	$check( 'R3 untouched: a long whole number that differs in its last digit is a change, and is written', 200 === $st && '1234567890123456790' === $GLOBALS['minn_st']['store']['num'], $st . ' ' . wp_json_encode( $GLOBALS['minn_st']['store']['num'] ) );

	$strip = true;
	$reset_fixture();
	list( $st ) = $send( 'POST', $fx, array( 'values' => array( 'code' => '<i>new</i>' ), 'base' => array( 'code' => 'not what was stored' ) ) );
	$strip = false;
	$check( 'R5 field gates: a field the caller may not change answers 403 before its base is looked at', 403 === $st && $wrote_nothing(), (string) $st );

	// An options-API screen: a placeholder goes back where it was shown.
	$ox = '/minn-admin/v1/minn-st-options/main';
	$reset_fixture();
	list( $st, $opage ) = $send( 'GET', $ox );
	$ov   = (array) ( $opage['values'] ?? array() );
	$rows = (array) ( $ov['rows'] ?? array() );
	$check( 'R4 masks (restore): the options screen shows the row\'s key as its placeholder', 200 === $st && $fp( $key ) === ( $rows[0]['key'] ?? null ), $st . ' ' . wp_json_encode( $rows ) );
	$edit           = $rows;
	$edit[1]['svc'] = 'c';
	list( $st, $d ) = $send( 'POST', $ox, array( 'values' => array( 'rows' => $edit ) ) );
	$got            = $GLOBALS['minn_st']['store']['rows'];
	$check( 'R4 masks (restore): an edit to another row saves, and the key beside it stays byte for byte', 200 === $st && $key === ( $got[0]['key'] ?? null ) && 'c' === ( $got[1]['svc'] ?? null ), $st . ' ' . wp_json_encode( array( $d['code'] ?? null, $got ) ) );
	$reset_fixture();
	list( $st, $d ) = $send( 'POST', $ox, array( 'values' => array( 'secret' => ( $ov['secret'] ?? '' ) . ' v2' ) ) );
	$check( 'R4 masks (restore): text typed beside the placeholder saves with the credential back in place', 200 === $st && 'Endpoint ' . $key . ' v2' === $GLOBALS['minn_st']['store']['secret'], $st . ' ' . wp_json_encode( array( $d['code'] ?? null, $GLOBALS['minn_st']['store']['secret'] ) ) );
	$reset_fixture();
	$moved          = $rows;
	$moved[1]['svc'] = $fp( $key );
	list( $st, $d )  = $send( 'POST', $ox, array( 'values' => array( 'plain' => 'Two', 'rows' => $moved ) ) );
	$check( 'R4 masks (restore): a placeholder moved to a place it was not shown is refused, and nothing is written', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $wrote_nothing() && $fixture_store === $GLOBALS['minn_st']['store'], $st . ' ' . wp_json_encode( $GLOBALS['minn_st'] ) );
	$check( 'R7 named refusals: the moved placeholder names its field', $names_ok( $d, array( 'rows' ) ), wp_json_encode( $d['data'] ?? null ) );
	$reset_fixture();
	list( $st, $d ) = $send( 'POST', $ox, array( 'values' => array( 'plain' => 'One ' . $fp( $key ) ) ) );
	$check( 'R4 masks (restore): a placeholder pasted into a field that never held it is refused', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && $wrote_nothing(), $st . ' ' . wp_json_encode( $d['code'] ?? null ) );

	$guess = static function ( $g ) use ( $send, $ox, $ov, $reset_fixture ) {
		$reset_fixture();
		list( $st ) = $send( 'POST', $ox, array( 'values' => array( 'secret' => ( $ov['secret'] ?? '' ) . ' x' ), 'base' => array( 'secret' => $g ) ) );
		return $st;
	};
	$right = $guess( 'Endpoint ' . $key );
	$wrong = $guess( 'Endpoint not-the-key' );
	$check( 'R8 stale copy: a base cannot test a guess at a credential (the right guess and a wrong one answer alike, 409)', 409 === $right && $right === $wrong && $wrote_nothing(), $right . ' / ' . $wrong );
	$check( 'R8 stale copy control: the base as the page was shown it (placeholder and all) saves', 200 === $guess( $ov['secret'] ?? '' ), '' );
	// A guess sent as the value itself, beside a base: the stored value and
	// any other answer alike (409), so neither the value nor the base tells.
	$probe = static function ( $value ) use ( $send, $ox, $reset_fixture ) {
		$reset_fixture();
		list( $st ) = $send( 'POST', $ox, array( 'values' => array( 'secret' => $value ), 'base' => array( 'secret' => 'a page that never loaded' ) ) );
		return $st;
	};
	$right = $probe( 'Endpoint ' . $key );
	$wrong = $probe( 'Endpoint not-the-key' );
	$check( 'R8 stale copy: a guess sent as the value beside a base answers alike, right or wrong (409)', 409 === $right && $right === $wrong && $wrote_nothing(), $right . ' / ' . $wrong );

	// A guess at a key inside a raw-output field, from a caller the gate
	// refuses: the stored key and any other answer alike (403).
	$raw_probe = static function ( $k ) use ( $send, $ox, $reset_fixture, &$strip ) {
		$reset_fixture();
		$strip      = true;
		list( $st ) = $send( 'POST', $ox, array( 'values' => array( 'snippet' => "<script>k='" . $k . "'</script>" ) ) );
		$strip      = false;
		return $st;
	};
	$right = $raw_probe( $key );
	$wrong = $raw_probe( 'MinnSettingsTierNOTTHEKEY00007731qz' );
	$check( 'R5 field gates: a guess at a key in a raw-output field answers alike, right or wrong (403)', 403 === $right && $right === $wrong && $wrote_nothing(), $right . ' / ' . $wrong );

	// Two placeholders with text typed between them, the first credential
	// holding the same character typed: each goes back whole.
	$reset_fixture();
	$pair_shown = $fp( $hy ) . '-' . $fp( $key );
	$check( 'R4 masks (restore): the pair is shown as two placeholders', $pair_shown === ( $ov['pair'] ?? null ), wp_json_encode( $ov['pair'] ?? null ) );
	list( $st, $d ) = $send( 'POST', $ox, array( 'values' => array( 'pair' => $fp( $hy ) . ' -' . $fp( $key ) ) ) );
	$check( 'R4 masks (restore): text typed between two placeholders puts each credential back whole', 200 === $st && $hy . ' -' . $key === $GLOBALS['minn_st']['store']['pair'], $st . ' ' . wp_json_encode( array( $d['code'] ?? null, $GLOBALS['minn_st']['store']['pair'] ) ) );

	// The same credential raw in one row and URL-encoded in another: an
	// edit to a third row leaves both as stored.
	$reset_fixture();
	$links = (array) ( $ov['links'] ?? array() );
	$check( 'R4 masks (restore): both copies of the slashed key are shown as its placeholder', $fp( $sl ) === ( $links[0]['key'] ?? null ) && $fp( $sl ) === ( $links[1]['key'] ?? null ), wp_json_encode( $links ) );
	$links[2]['svc'] = 'c2';
	list( $st, $d )  = $send( 'POST', $ox, array( 'values' => array( 'links' => $links ) ) );
	$got             = $GLOBALS['minn_st']['store']['links'];
	// The options API's own restore puts a key back as the credential at that
	// place, so the encoded copy may come back raw; the raw one stays raw.
	$check( 'R4 masks (restore): an edit to another row leaves the raw key byte for byte, and the encoded row still holds the key', 200 === $st && $sl === ( $got[0]['key'] ?? null ) && in_array( $got[1]['key'] ?? null, array( $sl, rawurlencode( $sl ) ), true ) && 'c2' === ( $got[2]['svc'] ?? null ), $st . ' ' . wp_json_encode( array( $d['code'] ?? null, $got ) ) );

	/* ---------- Every declared screen ---------- */

	// Where each screen can be reached: every settings route the app is
	// handed (surfaces and the shared option pages), plus the screens
	// addressed by an item.
	$candidates = array();
	foreach ( (array) apply_filters( 'minn_admin_surfaces', array() ) as $s ) {
		if ( ! empty( $s['settings']['route'] ) && ! empty( $s['settings']['tabs'] ) ) {
			foreach ( $s['settings']['tabs'] as $t ) {
				$candidates[] = '/' . str_replace( array( '{tab}', '{id}' ), array( $t['id'], '' ), ! empty( $t['route'] ) ? $t['route'] : $s['settings']['route'] );
			}
		}
	}
	foreach ( (array) apply_filters( 'minn_admin_option_pages', array() ) as $p ) {
		foreach ( (array) ( $p['tabs'] ?? array() ) as $t ) {
			$candidates[] = '/' . str_replace( '{tab}', $t['id'], ! empty( $t['route'] ) ? $t['route'] : (string) ( $p['route'] ?? '' ) );
		}
	}
	global $wpdb;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'fluentform_form_meta' ) ) ) {
		$form = (int) $wpdb->get_var( "SELECT form_id FROM {$wpdb->prefix}fluentform_form_meta WHERE meta_key = 'notifications' ORDER BY form_id LIMIT 1" );
		if ( $form ) {
			$candidates[] = '/minn-admin/v1/fluent-forms/forms/' . $form . '/emails';
		}
	}
	$candidates[] = $fx;
	$candidates[] = $ox;
	// A screen whose body is its own shape: how flat values go into it.
	$bodies = array(
		'fluent-forms-emails' => static function ( array $flat ) {
			$n = array();
			$c = array();
			foreach ( $flat as $k => $v ) {
				if ( preg_match( '/^n(\d+)\.(.+)$/', $k, $m ) ) {
					$n[ $m[1] ]['metaId'] = (int) $m[1];
					$n[ $m[1] ][ $m[2] ]  = $v;
				} elseif ( 0 === strpos( $k, 'confirmation.' ) ) {
					$c[ substr( $k, 13 ) ] = $v;
				}
			}
			return array( 'notifications' => array_values( $n ), 'confirmation' => $c );
		},
	);
	$body_for = static function ( $id, array $flat, ?array $base = null ) use ( $bodies ) {
		$b = isset( $bodies[ $id ] ) ? $bodies[ $id ]( $flat ) : array( 'values' => $flat );
		if ( null !== $base ) {
			$b['base'] = isset( $bodies[ $id ] ) ? $bodies[ $id ]( $base ) : $base;
		}
		return $b;
	};

	Minn_Admin_Kernel_Settings::dry( true );
	$screens = Minn_Admin_Kernel_Settings::screens();
	$seen    = 0;
	foreach ( $screens as $id => $def ) {
		$pattern = '/minn-admin/v1/' . $def['route'];
		if ( ! isset( $routes[ $pattern ] ) ) {
			$skip( "{$id}: its plugin is not active here" );
			continue;
		}
		$check( "R1 declared whole: {$id} answers GET and POST and declares its layout, read and write", ! array_diff( array( 'GET', 'POST' ), $methods( $pattern ) ) && isset( $def['layout'], $def['read'], $def['write'], $def['gate'] ), wp_json_encode( $methods( $pattern ) ) );
		$urls = array_values( array_unique( array_filter( $candidates, static function ( $u ) use ( $pattern ) {
			return (bool) preg_match( '#^' . $pattern . '$#', $u );
		} ) ) );
		if ( ! $urls ) {
			$check( "{$id}: the runner can reach it (add a way to address it here)", false );
			continue;
		}
		$probed  = 0;
		$keys_at = array();
		foreach ( $urls as $url ) {
			$served = Minn_Admin_Kernel_Settings::served( $id, new WP_REST_Request( 'GET', $url ) );
			if ( is_wp_error( $served ) ) {
				$check( "{$id} {$url}: the runner reads what it serves", false, $served->get_error_code() );
				continue;
			}
			++$seen;
			$fields          = $served['fields'];
			$keys_at[ $url ] = array_keys( $fields );
			$shown  = $masked( $served['values'], $url );
			$bad    = array();
			foreach ( $fields as $k => $f ) {
				if ( ! in_array( $f['type'] ?? 'text', Minn_Admin_Kernel_Settings::TYPES, true ) || '' === (string) ( $f['label'] ?? '' ) ) {
					$bad[] = $k;
				}
			}
			list( $st, $page ) = $send( 'GET', $url );
			$leak = array();
			foreach ( (array) ( $page['groups'] ?? array() ) as $g ) {
				foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
					foreach ( array_keys( (array) $f ) as $k ) {
						if ( 0 === strpos( (string) $k, '_' ) ) {
							$leak[] = $k;
						}
					}
				}
			}
			$check( "R2 shared types: {$url} offers only labelled fields of the shared types, none with the adapter's own keys", 200 === $st && ! $bad && ! $leak, $st . ' ' . wp_json_encode( array( $bad, $leak ) ) );

			list( $st, $d ) = $send( 'POST', $url, $body_for( $id, $shown ) );
			$j              = Minn_Admin_Kernel_Settings::journal();
			$check( "R3 untouched: {$url} sent back as served (" . count( $shown ) . ' values) changes nothing', 200 === $st && count( $shown ) > 0 && array() === ( $j['changes'] ?? null ), $st . ' ' . wp_json_encode( array( $j, $d['code'] ?? null ) ) );

			// One plain text field to edit: no raw-output rule, a string value.
			$pick = null;
			foreach ( $fields as $k => $f ) {
				if ( in_array( $f['type'] ?? 'text', array( 'text', 'textarea', 'url', 'email', 'wysiwyg' ), true ) && empty( $f['_raw'] ) && is_string( $served['values'][ $k ] ) ) {
					$pick = $k;
					break;
				}
			}
			if ( null === $pick ) {
				$skip( "R4/R6/R8 {$url}: no plain text field to edit" );
			} else {
				$was            = $shown[ $pick ];
				list( $st, $d ) = $send( 'POST', $url, $body_for( $id, array( $pick => $was . ' [redacted:0f1e2d]' ) ) );
				$j              = Minn_Admin_Kernel_Settings::journal();
				$check( "R4 masks: {$url} refuses a placeholder left in {$pick}", 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ) && empty( $j['wrote'] ), $st . ' ' . wp_json_encode( $d['code'] ?? null ) );
				$check( "R7 named refusals: {$url} names {$pick} and only it", $names_ok( $d, array( $pick ) ), wp_json_encode( $d['data'] ?? null ) );

				list( $st, $d ) = $send( 'POST', $url, $body_for( $id, array( $pick => $was . ' st' ), array( $pick => 'minn-st-stale ' . $tag ) ) );
				$check( "R8 stale copy: {$url} answers 409 for a base it no longer holds", 409 === $st && 'minn_stale' === ( $d['code'] ?? '' ) && $names_ok( $d, array( $pick ) ), $st . ' ' . wp_json_encode( $d ) );
				list( $st, $d ) = $send( 'POST', $url, $body_for( $id, array( $pick => $was . ' st' ), array( $pick => $was ) ) );
				$j              = Minn_Admin_Kernel_Settings::journal();
				$check( "R8 stale copy control: {$url} takes the base the page loaded", 200 === $st && array( $pick ) === ( $j['changes'] ?? null ), $st . ' ' . wp_json_encode( array( $j, $d['code'] ?? null, $d['message'] ?? null ) ) );
				$want = isset( $def['validate'] ) ? array( 'compare', 'gates', 'stale', 'masks', 'validate' ) : array( 'compare', 'gates', 'stale', 'masks' );
				$check( "R6 validator first: {$url} asks " . ( isset( $def['validate'] ) ? 'its validator' : 'every check' ) . ' in order, before the write', $want === ( $j['steps'] ?? null ), wp_json_encode( $j['steps'] ?? null ) );
			}

			$raw = array_keys( array_filter( $fields, static function ( $f ) {
				return ! empty( $f['_raw'] );
			} ) );
			if ( $raw ) {
				$strip   = true;
				$refused = array();
				foreach ( $raw as $k ) {
					list( $st, $d ) = $send( 'POST', $url, $body_for( $id, array( $k => ( is_string( $served['values'][ $k ] ) ? $served['values'][ $k ] : '' ) . 'x' ) ) );
					if ( 403 !== $st || ! $names_ok( $d, array( $k ) ) ) {
						$refused[] = $k . ':' . $st;
					}
				}
				$strip = false;
				$check( "R5 field gates: {$url} refuses each of its " . count( $raw ) . ' raw-output fields to a caller without unfiltered_html, by name', ! $refused, wp_json_encode( $refused ) );
			}
			++$probed;
		}
		// A key another tab offers, sent to this one, is ignored.
		$tabs = array_keys( $keys_at );
		if ( count( $tabs ) > 1 ) {
			$foreign = array_values( array_diff( $keys_at[ $tabs[1] ], $keys_at[ $tabs[0] ] ) );
			if ( $foreign ) {
				list( $st ) = $send( 'POST', $tabs[0], $body_for( $id, array( $foreign[0] => 'minn-st ' . $tag ) ) );
				$j          = Minn_Admin_Kernel_Settings::journal();
				$check( "R2 shared types: {$tabs[0]} ignores {$foreign[0]}, which another tab offers", 200 === $st && array() === ( $j['changes'] ?? null ) && empty( $j['wrote'] ), $st . ' ' . wp_json_encode( $j ) );
			}
		}
		if ( 'fluent-forms-emails' === $id ) {
			list( $st, $d ) = $send( 'POST', $tabs[0], array( 'notifications' => array( array( 'metaId' => 999999999, 'subject' => 'x' ) ) ) );
			$check( "R7 named refusals: {$tabs[0]} names a notification that is not on the form", 400 === $st && 'minn_ffe_unknown' === ( $d['code'] ?? '' ) && $names_ok( $d, array( 'n999999999' ) ), $st . ' ' . wp_json_encode( $d ) );
		}
	}
	Minn_Admin_Kernel_Settings::dry( false );
	$check( 'every declared screen reachable here was probed', $seen > 0, (string) $seen );

	// The shared Site options route hands a save to the page that owns the
	// tab; a body that is not JSON would reach it as no change.
	if ( isset( $routes['/minn-admin/v1/options/(?P<tab>tab-\d+)'] ) ) {
		list( $st, $d ) = $send( 'POST', '/minn-admin/v1/options/tab-0' );
		$check( 'R1 declared whole: Site options refuses a save that is not JSON (400)', 400 === $st && 'invalid_body' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d['code'] ?? null ) );
	}

	// Code edits turned off for the site: a raw-output field is refused even
	// to a caller holding unfiltered_html. Last, since a constant stays.
	if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
		define( 'DISALLOW_FILE_EDIT', true );
	}
	if ( true === DISALLOW_FILE_EDIT && current_user_can( 'unfiltered_html' ) ) {
		$reset_fixture();
		list( $st, $d ) = $send( 'POST', $fx, array( 'values' => array( 'plain' => 'Two', 'code' => '<i>new</i>' ) ) );
		$check( 'R5 field gates: with code edits turned off, a changed raw-output field is refused to a caller with unfiltered_html', 403 === $st && $wrote_nothing() && $names_ok( $d, array( 'code' ) ), $st . ' ' . wp_json_encode( $GLOBALS['minn_st']['log'] ) );
	} else {
		$skip( 'R5 code edits: this site turns them off already, or the caller lacks unfiltered_html' );
	}
} finally {
	$clean();
}

$pass = count( array_filter( $results ) );
printf( "\nsettings-tier-conformance: %d/%d passed (%d screens declared)\n", $pass, count( $results ), count( Minn_Admin_Kernel_Settings::screens() ) );
