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
 *                      (400 minn_egress_mask) and nothing is written.
 *  R5 field gates      a raw-output field needs unfiltered_html, and the
 *                      screen's own per-field gate is asked, before anything
 *                      is written; an untouched gated field blocks nothing.
 *  R6 validator first  the plugin's validator is asked about every change
 *                      before the first write, and its refusal writes nothing.
 *  R7 named refusals   every refusal names its fields ({ key, label,
 *                      message }, the first in data.field) and writes nothing.
 *  R8 stale copy       a body whose base says the page loaded a value no
 *                      longer stored answers 409; a body without a base (an
 *                      app cached from before) saves as it did.
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
		if ( null !== $ctx['tab'] && 'main' !== $ctx['tab'] ) {
			return null;
		}
		$f = function ( $key, $label, $type, $extra = array() ) {
			return array_merge( array( 'key' => $key, 'label' => $label, 'type' => $type, '_at' => $key ), $extra );
		};
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
			$GLOBALS['minn_st']['store'][ $fields[ $k ]['_at'] ] = 'toggle' === $fields[ $k ]['type'] ? ( $v ? '1' : '' ) : $v;
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
);
$bad_taken = array();
foreach ( $bad_decls as $why => $decl ) {
	if ( minn_admin_settings( 'minn-st-bad-' . md5( $why ), $decl ) ) {
		$bad_taken[] = $why;
	}
}
$declared = minn_admin_settings( 'minn-st-fixture', $fixture );

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

	$check( 'R1 declared whole: a declaration with an unknown key, no write, no gate or a read it cannot call is refused', ! $bad_taken && 4 <= count( Minn_Admin_Kernel_Settings::refused() ), wp_json_encode( $bad_taken ) );
	$no_route = true;
	foreach ( array( 'a', 'b', 'c', 'd' ) as $x ) {
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
	$check( 'R2 shared types: a field of a type the form cannot draw is not offered, nor its value served', 200 === $st && ! in_array( 'odd', $offered, true ) && ! array_key_exists( 'odd', (array) ( $page['values'] ?? array() ) ) && 6 === count( $offered ), $st . ' ' . wp_json_encode( $offered ) );
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
		$probed = 0;
		foreach ( $urls as $url ) {
			$served = Minn_Admin_Kernel_Settings::served( $id, new WP_REST_Request( 'GET', $url ) );
			if ( is_wp_error( $served ) ) {
				$check( "{$id} {$url}: the runner reads what it serves", false, $served->get_error_code() );
				continue;
			}
			++$seen;
			$fields = $served['fields'];
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
			$check( "R3 untouched: {$url} sent back as served (" . count( $shown ) . ' values) changes nothing', 200 === $st && array() === ( $j['changes'] ?? null ), $st . ' ' . wp_json_encode( array( $j, $d['code'] ?? null ) ) );

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
				$want = isset( $def['validate'] ) ? array( 'compare', 'stale', 'gates', 'masks', 'validate' ) : array( 'compare', 'stale', 'gates', 'masks' );
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
	}
	Minn_Admin_Kernel_Settings::dry( false );
	$check( 'every declared screen reachable here was probed', $seen > 0, (string) $seen );
} finally {
	$clean();
}

$pass = count( array_filter( $results ) );
printf( "\nsettings-tier-conformance: %d/%d passed (%d screens declared)\n", $pass, count( $results ), count( Minn_Admin_Kernel_Settings::screens() ) );
