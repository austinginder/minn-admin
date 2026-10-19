<?php
/**
 * The resource kernel's conformance runner (docs/adapter-kernel.md).
 *
 * Tests scale with the kernel, not with adapters. Two parts:
 *
 * 1. The kernel itself, on a synthetic resource over a temporary table
 *    (gone when the process ends): no gate, no route; gates and the row gate
 *    in their order (404, then 403, then the wrong-state 400); ids, page
 *    sizes and filters from a fixed set; an empty scope that can never widen;
 *    search and filter values that never reach SQL unprepared; only declared
 *    fields leaving, a seeded secret in an undeclared column never; each field type on awkward stored
 *    values; a write whose effect is not there never reporting success; a
 *    throw answering in one shape with nothing of the exception in it; the
 *    handler tier's gate and ids. Then the log shapes, on a network table
 *    two tenants share: a fixed condition on every read (list, item, card,
 *    tab list) that matches nothing when it cannot be read; facets, LIKE and
 *    'any' rules that never widen; status-card counts over the list's own
 *    rows; a missing table; an array source; an id that is not a number.
 *    Then the booking shapes (joins, views with their own order, cards
 *    ahead) and the mail shapes: further reads of an item, NOT any, a
 *    delete that needs a condition, a send's own answer as its proof and a
 *    chart's rule-split and labels; then the redirect shapes: a create on the
 *    collection route that reads its row back, edits on the item route,
 *    card sums and counts, key-bound writes and the database's own clock;
 *    then the gift card shapes: a filter taking several values at once,
 *    groups of conditions ('#any') in SQL and PHP, and money sums; then the
 *    traffic shapes: grouped totals over joins and the days rows fall on;
 *    then what the kernel review closed: a filter with no values keeping
 *    its scope, a declaration that throws, an endpoint gate's signature,
 *    writes to a core table however named, errors scrubbed at any depth,
 *    inbox counts under a scope, gone proven by the store, typed args, a
 *    send's proof, one declaration per id and route, unknown keys refused;
 *    then the database's own clock (a column NOW() wrote) in a day range,
 *    chart days, grouped totals, a window and a last event's age.
 * 2. Every real declaration: each action has a declared gate and a
 *    post-condition, a resource over a table whose item or a read sits
 *    behind a gate stricter than its view gate says what a reader of the
 *    whole table must pass, each descriptor action that points at a kernel route
 *    names a declared action with its method and a state the action accepts,
 *    and each collection descriptor's route, paging, search and keys agree
 *    with the resource they read; and the kernel refused none of them.
 *
 * Run: wp eval-file tests/kernel-conformance.test.php --user=admin --path=<absolute site path>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
if ( ! class_exists( 'Minn_Admin_Kernel' ) ) {
	echo "FAIL  the kernel is not loaded\n";
	exit( 1 );
}
$admin = get_current_user_id();
if ( ! $admin || ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
$call = function ( $method, $route, $params = array(), $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( $params ) {
		$r->set_query_params( $params );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};

// --- 1. The kernel on a synthetic resource -----------------------------------
global $wpdb;
$t = $wpdb->prefix . 'minn_conf_things';
$wpdb->query( "CREATE TEMPORARY TABLE {$t} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, owner BIGINT NOT NULL, status VARCHAR(20) NOT NULL, title VARCHAR(200) NOT NULL, created DATETIME NOT NULL, token VARCHAR(64) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$secret = 'minn-conformance-secret-' . wp_generate_password( 12, false );
$seed   = array(
	array( 1, 10, 'open', 'Alpha', '2026-01-02 03:04:05' ),
	array( 2, 10, 'open', 'Beta 100% _off_', '2026-01-02 03:04:05' ),
	array( 3, 20, 'spam', "Gamma ' OR 1=1 --", '2026-01-03 00:00:00' ),
	array( 4, 20, 'trash', 'Delta &amp; Sons', '2026-01-04 00:00:00' ),
	array( 5, 30, 'open', 'Epsilon', '0000-00-00 00:00:00' ),
);
foreach ( $seed as $s ) {
	$wpdb->insert( $t, array( 'id' => $s[0], 'owner' => $s[1], 'status' => $s[2], 'title' => $s[3], 'created' => $s[4], 'token' => $secret ) );
}
$gate_on  = true;
$row_on   = true;
$scope    = null;
$runs     = array();
$throwing = false;
$def      = array(
	'gates'     => array(
		'view'   => function () use ( &$gate_on ) {
			return $gate_on;
		},
		'manage' => function () use ( &$gate_on ) {
			return $gate_on;
		},
	),
	'item_gate' => function ( $id, $gate ) use ( &$row_on, &$runs ) {
		$runs[] = "gate:{$id}:{$gate}";
		return $row_on;
	},
	'labels'    => array( 'not_found' => 'No such thing.', 'forbidden' => 'Not your thing.' ),
	'source'    => array( 'table' => 'minn_conf_things', 'key' => 'id', 'columns' => array( 'id', 'owner', 'status', 'title', 'created', 'token' ) ),
	'list'      => array(
		'per_page' => 2,
		'filter'   => array( 'param' => 'status', 'column' => 'status', 'values' => array( 'open', 'spam', 'trash' ), 'default' => 'open' ),
		'tab'      => array( 'param' => 'owner', 'column' => 'owner' ),
		'scope'    => array( 'column' => 'owner', 'allowed' => function () use ( &$scope ) {
			return $scope;
		}, 'refuse' => 'Not your owner.' ),
		'search'   => array( 'param' => 'search', 'columns' => array( 'title' ) ),
		'range'    => array( 'column' => 'created', 'clock' => 'local' ),
		'order'    => array( 'column' => 'created', 'dir' => 'DESC' ),
		'row'      => function ( $row ) {
			return array( 'id' => $row['id'], 'title' => $row['title'], 'when' => $row['created'], 'token' => $row['token'], 'extra' => 'undeclared' );
		},
		'fields'   => array(
			'id'    => 'int',
			'title' => array( 'type' => 'text', 'decode' => true ),
			'when'  => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'iso' ),
		),
	),
	'item'      => array(
		'view'   => function ( $row ) {
			return array( 'id' => (int) $row['id'], 'status' => $row['status'], 'token' => $row['token'] );
		},
		'fields' => array( 'id' => 'int', 'status' => 'text' ),
	),
	'actions'   => array(
		'close'  => array(
			'gate'   => 'manage',
			'when'   => array( 'status' => array( 'open' ) ),
			'code'   => 'not_open',
			'refuse' => 'Only open things close.',
			'run'    => function ( $id ) use ( &$runs, $wpdb, $t ) {
				$runs[] = "close:{$id}";
				$wpdb->update( $t, array( 'status' => 'closed' ), array( 'id' => $id ) );
				return true;
			},
			'expect' => array( 'status' => 'closed' ),
			'reply'  => function ( $id, $fresh ) {
				return array( 'status' => $fresh['status'] );
			},
			'done'   => 'Closed.',
		),
		'fake'   => array(
			'gate'   => 'manage',
			'run'    => function () use ( &$throwing ) {
				if ( $throwing ) {
					throw new RuntimeException( 'vendor said /srv/secret/path.php SELECT * FROM wp_users' );
				}
				return true; // claims success, changes nothing
			},
			'expect' => array( 'status' => 'faked' ),
		),
		'refuse' => array(
			'gate'   => 'manage',
			'run'    => function () {
				return new WP_Error( 'vendor_refused', 'The vendor refused.', array( 'status' => 409 ) );
			},
			'expect' => 'gone',
		),
		'nogate' => array(
			'gate' => 'nonexistent',
			'run'  => '__return_true',
		),
		'remove' => array(
			'method' => 'DELETE',
			'gate'   => 'manage',
			'when'   => array( 'status' => array( 'trash' ) ),
			'run'    => function ( $id ) use ( $wpdb, $t ) {
				$wpdb->delete( $t, array( 'id' => $id ) );
				return true;
			},
			'expect' => 'gone',
		),
	),
);
// Declarations that are wrong on purpose: their notices are not printed, and
// what the kernel refused is read from Minn_Admin_Kernel::refused() (1h, 2).
add_filter( 'doing_it_wrong_trigger_error', '__return_false' );
Minn_Admin_Kernel::resource( 'minn-conformance/things', $def );
Minn_Admin_Kernel::resource( 'minn-conformance/nogate', array_merge( $def, array( 'gates' => array( 'manage' => '__return_true' ) ) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/badcol', array_merge( $def, array( 'source' => array( 'table' => 'minn_conf_things', 'key' => 'id', 'columns' => array( 'id', 'title; DROP TABLE x' ) ) ) ) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-nogate', array( 'run' => '__return_true' ) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep/(?P<id>\d+)', array(
	'gate' => function () use ( &$gate_on ) {
		return $gate_on;
	},
	'run'  => function ( $r, $ctx ) use ( &$throwing ) {
		if ( $throwing ) {
			throw new RuntimeException( 'endpoint leaked /srv/secret' );
		}
		return array( 'id' => $ctx['id'] );
	},
) );

Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-item/(?P<id>\d+)', array(
	'gate' => function ( WP_REST_Request $r ) {
		return 7 === (int) Minn_Admin::path_param( $r );
	},
	'run'  => function ( $r, $ctx ) {
		return array( 'id' => $ctx['id'] );
	},
) );

// Shapes a check can answer with that are not a yes: each must deny.
$shape     = true;
$shape_fn  = function () use ( &$shape ) {
	return $shape;
};
$sc_answer = null;
$shapes    = array_merge( $def, array(
	'gates'     => array( 'view' => $shape_fn, 'manage' => $shape_fn ),
	'item_gate' => function () use ( &$shape ) {
		return true === $shape ? true : $shape;
	},
) );
Minn_Admin_Kernel::resource( 'minn-conformance/shapes', $shapes );
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-shapes', array( 'gate' => $shape_fn, 'run' => function () {
	return array( 'secret' => 'leaked' );
} ) );
// active: false and 0 register nothing.
Minn_Admin_Kernel::resource( 'minn-conformance/off', array_merge( $def, array( 'active' => false ) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/off0', array_merge( $def, array( 'active' => 0 ) ) );
// A scope function that fails, a scoped resource with no row gate, and a
// callable source that returns rows outside its scope.
Minn_Admin_Kernel::resource( 'minn-conformance/scopefail', array_merge( $def, array(
	'list' => array_merge( $def['list'], array( 'scope' => array( 'column' => 'owner', 'allowed' => function () use ( &$sc_answer ) {
		return $sc_answer;
	} ) ) ),
) ) );
$no_row_gate = $def;
unset( $no_row_gate['item_gate'] );
$no_row_gate['list']['scope'] = array( 'column' => 'owner', 'allowed' => function () {
	return array( 30 );
} );
Minn_Admin_Kernel::resource( 'minn-conformance/norowgate', $no_row_gate );
$cl_tab = 'not asked';
Minn_Admin_Kernel::resource( 'minn-conformance/callable', array_merge( $no_row_gate, array(
	'source' => array(
		'list' => function ( $q ) use ( $wpdb, $t, &$cl_tab ) {
			$cl_tab = $q['tab'] ?? null;
			$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id", ARRAY_A ); // phpcs:ignore
			return array( $rows, count( $rows ) );
		},
		'get'  => function ( $id ) use ( $wpdb, $t ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		},
	),
) ) );
// A declaration built by a function when first needed: a closure, or a
// static method named as array( 'Class', 'method' ).
class Minn_Conformance_Lazy {
	public static $def = array();
	public static function def() {
		return self::$def;
	}
}
Minn_Conformance_Lazy::$def = $def;
Minn_Admin_Kernel::resource( 'minn-conformance/lazy-fn', function () use ( $def ) {
	return $def;
} );
Minn_Admin_Kernel::resource( 'minn-conformance/lazy-static', array( 'Minn_Conformance_Lazy', 'def' ) );
// The item route answers to the gate the item names.
Minn_Admin_Kernel::resource( 'minn-conformance/itemgate', array_merge( $def, array(
	'gates' => array( 'view' => '__return_true', 'manage' => function () use ( &$gate_on ) {
		return $gate_on;
	} ),
	'item'  => array_merge( $def['item'], array( 'gate' => 'manage' ) ),
) ) );
// Writes with a misspelled post-condition, or none, get no route.
Minn_Admin_Kernel::resource( 'minn-conformance/expects', array_merge( $def, array( 'actions' => array(
	'typo' => array( 'gate' => 'manage', 'run' => '__return_true', 'expect' => 'deleted' ),
	'none' => array( 'gate' => 'manage', 'run' => '__return_true' ),
) ) ) );
// A handler's WP_Error leaves with an error status and no debug data.
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-error', array( 'gate' => '__return_true', 'run' => function () {
	return new WP_Error( 'db', 'Duplicate entry', array( 'status' => 200, 'sql' => 'SELECT * FROM wp_users', 'field' => 'email' ) );
} ) );
rest_get_server();
$base = '/minn-admin/v1/minn-conformance/things';

// No gate, no route.
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/nogate' );
$check( 'a resource without a view gate registers no list route', 404 === $st, (string) $st );
list( $st ) = $call( 'POST', $base . '/1/nogate' );
$check( 'an action naming an undeclared gate has no route', 404 === $st, (string) $st );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep-nogate' );
$check( 'an endpoint without a gate has no route', 404 === $st, (string) $st );

// The list.
list( $st, $d ) = $call( 'GET', $base );
$check( 'the list answers items and total for the default filter', 200 === $st && 3 === $d['total'] && 2 === count( $d['items'] ), wp_json_encode( $d ) );
$check( 'the list sorts by its column and breaks ties by the key', array( 2, 1 ) === array_column( $d['items'], 'id' ), wp_json_encode( array_column( $d['items'], 'id' ) ) );
$check( 'undeclared fields never leave', ! array_key_exists( 'extra', $d['items'][0] ) );
$check( 'a secret the row builder hands over in an undeclared field never leaves', false === strpos( wp_json_encode( $d ), $secret ) );
$check( 'an int field is an int', is_int( $d['items'][0]['id'] ) );
list( , $d2 ) = $call( 'GET', $base, array( 'page' => 2 ) );
$check( 'page two continues where page one stopped', array( 5 ) === array_column( $d2['items'], 'id' ), wp_json_encode( $d2 ) );
list( , $d ) = $call( 'GET', $base, array( 'per_page' => -5 ) );
$check( 'a negative page size is one', 1 === count( $d['items'] ) );
list( , $d ) = $call( 'GET', $base, array( 'status' => "open' OR '1'='1" ) );
$check( 'a filter value outside the set falls back to the default', 3 === $d['total'], (string) $d['total'] );
list( , $d ) = $call( 'GET', $base, array( 'status' => 'spam' ) );
$check( 'a filter value in the set narrows the list', 1 === $d['total'] && 3 === $d['items'][0]['id'] );
list( , $d ) = $call( 'GET', $base, array( 'status' => 'spam', 'search' => "' OR 1=1 --" ) );
$check( 'a search with quotes is a value, not SQL', 1 === $d['total'], wp_json_encode( $d ) );
list( , $d ) = $call( 'GET', $base, array( 'search' => '%' ) );
$check( 'a search for % matches a literal percent only', 1 === $d['total'] && 2 === $d['items'][0]['id'], wp_json_encode( $d ) );
list( , $d ) = $call( 'GET', $base, array( 'search' => '_' ) );
$check( 'a search for _ matches a literal underscore only', 1 === $d['total'], wp_json_encode( $d ) );
list( , $d ) = $call( 'GET', $base, array( 'owner' => '10 OR 1=1' ) );
$check( 'a tab value is read as an integer', 2 === $d['total'], wp_json_encode( $d ) );
list( , $d )  = $call( 'GET', $base, array( 'after' => '2026-01-02 12:00:00', 'status' => 'spam' ) );
list( , $d2 ) = $call( 'GET', $base, array( 'after' => '2026-01-02 12:00:00' ) );
$check( 'a chart day range narrows on the declared column', 1 === $d['total'] && 0 === $d2['total'], wp_json_encode( array( $d['total'], $d2['total'] ) ) );
list( , $d ) = $call( 'GET', $base, array( 'status' => 'trash' ) );
$check( 'stored entities reach the client as text', 'Delta & Sons' === $d['items'][0]['title'], wp_json_encode( $d ) );

// Scope.
$scope        = array();
list( , $d )  = $call( 'GET', $base, array( 'owner' => 10 ) );
$check( 'an empty scope answers an empty list, whatever tab is asked', 0 === $d['total'] && array() === $d['items'] );
$scope        = array( 30 );
list( , $d )  = $call( 'GET', $base );
$check( 'a scope limits the list to its ids', 1 === $d['total'] && 5 === $d['items'][0]['id'] );
list( $st, $d ) = $call( 'GET', $base, array( 'owner' => 10 ) );
$check( 'a tab outside a non-empty scope is refused', 403 === $st && 'forbidden' === $d['code'] && 'Not your owner.' === $d['message'], $st . ' ' . wp_json_encode( $d ) );
$scope        = null;

// The item and its gates, in order.
list( $st, $d ) = $call( 'GET', $base . '/1' );
$check( 'the item answers its declared fields', 200 === $st && array( 'id' => 1, 'status' => 'open' ) === $d, wp_json_encode( $d ) );
list( $st, $d ) = $call( 'GET', $base . '/999' );
$check( 'a missing item is 404 with the declared message', 404 === $st && 'not_found' === $d['code'] && 'No such thing.' === $d['message'] );
list( $st ) = $call( 'GET', $base . '/0' );
$check( 'id 0 is not found', 404 === $st, (string) $st );
list( $st ) = $call( 'GET', $base . '/-1' );
$check( 'a negative id matches no route', 404 === $st, (string) $st );
$row_on         = false;
$runs           = array();
list( $st, $d ) = $call( 'GET', $base . '/1' );
$check( 'the row gate refuses an item with 403', 403 === $st && 'forbidden' === $d['code'] );
$check( 'the row gate is asked with the id and the view gate', array( 'gate:1:view' ) === $runs, wp_json_encode( $runs ) );
$runs           = array();
list( $st, $d ) = $call( 'POST', $base . '/1/close' );
$check( 'the row gate refuses an action with 403 before it runs', 403 === $st && array( 'gate:1:manage' ) === $runs, $st . ' ' . wp_json_encode( $runs ) );
$row_on         = true;
$gate_on        = false;
list( $st ) = $call( 'GET', $base );
$check( 'a closed view gate refuses the list', in_array( $st, array( 401, 403 ), true ), (string) $st );
list( $st ) = $call( 'POST', $base . '/1/close' );
$check( 'a closed action gate refuses the action', in_array( $st, array( 401, 403 ), true ), (string) $st );
$gate_on = true;

// Actions.
list( $st, $d ) = $call( 'POST', $base . '/3/close' );
$check( 'an action on a row in the wrong state is a 400 with its code', 400 === $st && 'not_open' === $d['code'] && 'Only open things close.' === $d['message'], $st . ' ' . wp_json_encode( $d ) );
$wpdb->query( 'START TRANSACTION' );
list( $st, $d ) = $call( 'POST', $base . '/1/close' );
$check( 'an action replies ok, its reply and its message', 200 === $st && array( 'ok' => true, 'status' => 'closed', 'message' => 'Closed.' ) === $d, wp_json_encode( $d ) );
$wpdb->query( 'ROLLBACK' );
list( $st, $d ) = $call( 'POST', $base . '/1/fake' );
$check( 'a write whose effect is not there never reports success', 500 === $st && 'not_applied' === $d['code'] && ! isset( $d['ok'] ), $st . ' ' . wp_json_encode( $d ) );
list( $st, $d ) = $call( 'POST', $base . '/1/refuse' );
$check( 'a vendor refusal comes back as itself', 409 === $st && 'vendor_refused' === $d['code'], $st . ' ' . wp_json_encode( $d ) );
$throwing       = true;
list( $st, $d ) = $call( 'POST', $base . '/1/fake' );
$check( 'a throw answers 500 minn_failed', 500 === $st && 'minn_failed' === $d['code'], $st . ' ' . wp_json_encode( $d ) );
$check( 'nothing of the exception reaches the client', false === strpos( wp_json_encode( $d ), 'secret' ) && false === strpos( wp_json_encode( $d ), 'SELECT' ) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep/7' );
$check( 'an endpoint that throws answers the same shape', 500 === $st && 'minn_failed' === $d['code'] && false === strpos( wp_json_encode( $d ), 'secret' ) );
$throwing = false;
list( $st, $d ) = $call( 'DELETE', $base . '/1' );
$check( 'a DELETE action rides the item route and checks its state', 400 === $st, $st . ' ' . wp_json_encode( $d ) );
$wpdb->query( 'START TRANSACTION' );
list( $st, $d ) = $call( 'DELETE', $base . '/4' );
$check( 'a DELETE whose row is gone replies ok', 200 === $st && true === $d['ok'], $st . ' ' . wp_json_encode( $d ) );
$wpdb->query( 'ROLLBACK' );

// The handler tier.
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep/7' );
$check( 'an endpoint gets its id typed', 200 === $st && array( 'id' => 7 ) === $d, wp_json_encode( $d ) );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep/0' );
$check( 'an endpoint refuses id 0', 404 === $st, (string) $st );
$gate_on    = false;
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep/7' );
$check( 'an endpoint gate refuses', in_array( $st, array( 401, 403 ), true ), (string) $st );
$gate_on = true;
list( $st7, $d7 ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep-item/7' );
list( $st8 )       = $call( 'GET', '/minn-admin/v1/minn-conformance/ep-item/8' );
$check( "an endpoint's gate is asked with the request, so a gate on one item refuses before the handler runs", 200 === $st7 && 7 === ( $d7['id'] ?? 0 ) && 403 === $st8, $st7 . ' ' . $st8 );

// A declared column that is not an identifier fails closed.
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/badcol' );
$check( 'a column that is not an identifier fails closed', 500 === $st && 'minn_failed' === $d['code'], $st . ' ' . wp_json_encode( $d ) );


// Check shapes that are not a yes.
$sbase = '/minn-admin/v1/minn-conformance/shapes';
foreach ( array( 'a WP_Error' => new WP_Error( 'no', 'Denied.' ), 'the number 1' => 1, 'a string' => 'yes', 'null' => null ) as $what => $answer ) {
	$shape      = $answer;
	list( $st ) = $call( 'GET', $sbase );
	$check( "a view gate answering {$what} refuses the list", in_array( $st, array( 401, 403 ), true ), (string) $st );
	list( $st ) = $call( 'POST', $sbase . '/1/close' );
	$check( "an action gate answering {$what} refuses the action", in_array( $st, array( 401, 403 ), true ), (string) $st );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep-shapes' );
	$check( "an endpoint gate answering {$what} refuses it", in_array( $st, array( 401, 403 ), true ) && ! isset( $d['secret'] ), (string) $st );
}
$shape = true;
list( $st ) = $call( 'GET', $sbase . '/1' );
$check( 'the shapes resource answers when every check says yes', 200 === $st, (string) $st );
$shapes_rowgate = function ( $answer ) use ( &$shape ) {
	$shape = $answer;
};
// The row gate alone: views stay open while the row gate says WP_Error.
Minn_Admin_Kernel::resource( 'minn-conformance/rowshape', array_merge( $def, array( 'item_gate' => function () {
	return new WP_Error( 'no', 'Denied.' );
} ) ) );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/rowshape/1' );
$check( 'a row gate answering a WP_Error refuses the item', 403 === $st, (string) $st );
Minn_Admin_Kernel::resource( 'minn-conformance/tabshape', array_merge( $def, array( 'list' => array_merge( $def['list'], array( 'tab' => array( 'param' => 'owner', 'column' => 'owner', 'gate' => function () {
	return new WP_Error( 'no', 'Denied.' );
} ) ) ) ) ) );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/tabshape', array( 'owner' => 10 ) );
$check( 'a tab gate answering a WP_Error refuses the tab', 403 === $st, (string) $st );

// active.
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/off' );
$check( "'active' => false registers no route", 404 === $st, (string) $st );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/off0' );
$check( "'active' => 0 registers no route", 404 === $st, (string) $st );

// Scope that fails, scope without a row gate, scope over a callable source.
foreach ( array( 'false' => false, 'a WP_Error' => new WP_Error( 'x', 'x' ), 'an empty string' => '' ) as $what => $answer ) {
	$sc_answer   = $answer;
	list( , $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/scopefail' );
	$check( "a scope answering {$what} is an empty list, not every row", 0 === ( $d['total'] ?? -1 ), wp_json_encode( $d ) );
}
$sc_answer = null;
list( , $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/scopefail' );
$check( 'a scope answering null is every row', 3 === ( $d['total'] ?? -1 ), wp_json_encode( $d ) );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/norowgate/1' );
$check( 'without a row gate, an item outside the scope is refused', 403 === $st, (string) $st );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/norowgate/5' );
$check( 'without a row gate, an item inside the scope answers', 200 === $st, (string) $st );
list( $st ) = $call( 'POST', '/minn-admin/v1/minn-conformance/norowgate/1/close' );
$check( 'without a row gate, an action on a row outside the scope is refused', 403 === $st, (string) $st );
list( , $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/callable' );
$check( "a callable source's rows outside the scope never leave", array( 5 ) === array_column( (array) ( $d['items'] ?? array() ), 'id' ), wp_json_encode( $d ) );
$call( 'GET', '/minn-admin/v1/minn-conformance/callable', array( 'owner' => '30 OR 1=1' ) );
$check( 'a tab value reaches a source as an integer', 30 === $cl_tab, wp_json_encode( $cl_tab ) );

// Lazy declarations.
list( , $want ) = $call( 'GET', '/minn-admin/v1/minn-conformance/things' );
foreach ( array( 'lazy-fn' => 'a closure', 'lazy-static' => "array( 'Class', 'method' )" ) as $slug => $what ) {
	list( $st, $d ) = $call( 'GET', "/minn-admin/v1/minn-conformance/{$slug}" );
	$check( "a declaration from {$what} registers and lists as the array would", 200 === $st && ( $want['total'] ?? -1 ) === ( $d['total'] ?? -2 ), $st . ' ' . wp_json_encode( $d ) );
}

// The item's own gate.
$gate_on     = false;
list( $st )  = $call( 'GET', '/minn-admin/v1/minn-conformance/itemgate/1' );
$check( 'the item route answers to the gate the item names', in_array( $st, array( 401, 403 ), true ), (string) $st );
list( $st )  = $call( 'GET', '/minn-admin/v1/minn-conformance/itemgate' );
$check( 'while the list keeps the view gate', 200 === $st, (string) $st );
$gate_on = true;

// Post-conditions.
list( $st ) = $call( 'POST', '/minn-admin/v1/minn-conformance/expects/1/typo' );
$check( 'an action whose expect is not a known shape has no route', 404 === $st, (string) $st );
list( $st ) = $call( 'POST', '/minn-admin/v1/minn-conformance/expects/1/none' );
$check( 'an action with no expect has no route', 404 === $st, (string) $st );

// Errors and pages.
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep-error' );
$check( "a handler's error keeps its code, message and the data the client reads", 'db' === ( $d['code'] ?? '' ) && 'email' === ( $d['data']['field'] ?? '' ), wp_json_encode( $d ) );
$check( "a handler's error never answers a success status", 500 === $st, (string) $st );
$check( "a handler's error drops debug data", ! isset( $d['data']['sql'] ), wp_json_encode( $d ) );
$wpdb->last_error = '';
list( $st, $d )   = $call( 'GET', $base, array( 'page' => '99999999999999999999' ) );
$check( 'a huge page number is a valid empty page, not a SQL error', 200 === $st && array() === $d['items'] && '' === $wpdb->last_error, $st . ' ' . $wpdb->last_error );

// Field types on awkward stored values.
$f   = array( 'Minn_Admin_Kernel_Fields', 'encode' );
$utc = array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'naked' );
$check( 'datetime: a UTC column, naked, keeps its clock', '2026-01-02T03:04:05' === call_user_func( $f, $utc, '2026-01-02 03:04:05' ) );
$check( 'datetime: an empty value is empty', '' === call_user_func( $f, $utc, '' ) && '' === call_user_func( $f, $utc, null ) );
$iso = call_user_func( $f, array( 'type' => 'datetime', 'stored' => 'utc', 'wire' => 'iso' ), '2026-01-02 03:04:05' );
$check( 'datetime: a UTC column as ISO carries an offset and the same instant', strtotime( $iso ) === strtotime( '2026-01-02 03:04:05 UTC' ) && preg_match( '/[+-]\d\d:\d\d$/', $iso ), $iso );
$ep = call_user_func( $f, array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'iso' ), '1767323045' );
$check( 'datetime: an epoch as ISO is the same instant', strtotime( $ep ) === 1767323045, $ep );
$check( 'datetime: a non-numeric epoch is empty', '' === call_user_func( $f, array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'iso' ), 'yesterday' ) );
$check( 'text: decode turns entities into text', 'Smith & Sons "x"' === call_user_func( $f, array( 'type' => 'text', 'decode' => true ), 'Smith &amp; Sons &quot;x&quot;' ) );
$check( 'text: an array is not text', '' === call_user_func( $f, array( 'type' => 'text' ), array( 'a' ) ) );
$check( 'enum: a value outside the set is empty', '' === call_user_func( $f, array( 'type' => 'enum', 'values' => array( 'a', 'b' ) ), 'c' ) );
$check( 'int: a numeric prefix is read, the rest dropped', 12 === call_user_func( $f, array( 'type' => 'int' ), '12abc' ) );

// --- 1b. Log shapes: a network table, fixed conditions, facets, cards ------
// Two tenants in one network table; only site 1 is ever this site's.
$lt = $wpdb->base_prefix . 'minn_conf_log';
$wpdb->query( "CREATE TEMPORARY TABLE {$lt} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, site_id BIGINT NOT NULL, kind VARCHAR(20) NOT NULL, src VARCHAR(40) NULL, actor VARCHAR(60) NOT NULL, created BIGINT NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$now  = time();
$logs = array(
	array( 1, 1, 'a', '', 'ann', $now - 60 ),
	array( 2, 1, 'a', null, 'bob 100%', $now - 2 * DAY_IN_SECONDS ),
	array( 3, 1, 'b', 'rest', 'cy_d', $now - 3 * DAY_IN_SECONDS ),
	array( 4, 1, 'b', 'rest|app:x', 'dee', $now - 10 * DAY_IN_SECONDS ),
	array( 5, 1, 'c', 'app:y', 'eve', $now - 20 * DAY_IN_SECONDS ),
	array( 6, 1, 'c', 'restx', 'fay', $now - 30 ),
	array( 7, 2, 'a', 'rest', 'other-tenant', $now - 30 ),
	array( 8, 2, 'z', '', 'other-tenant', $now - 30 ),
);
foreach ( $logs as $g ) {
	$wpdb->insert( $lt, array( 'id' => $g[0], 'site_id' => $g[1], 'kind' => $g[2], 'src' => $g[3], 'actor' => $g[4], 'created' => $g[5] ) );
}
$site_answer = array( 'site_id' => 1 );
$col_calls   = 0;
$page_calls  = array();
Minn_Admin_Kernel::resource( 'minn-conformance/log', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array(
		'table'   => 'minn_conf_log',
		'network' => true,
		'key'     => 'id',
		'columns' => function () use ( &$col_calls ) {
			$col_calls++;
			return array( 'id', 'site_id', 'kind', 'src', 'actor', 'created' );
		},
		'where'   => function () use ( &$site_answer ) {
			return $site_answer;
		},
	),
	'list'   => array(
		'facets' => array(
			array( 'param' => 'kind', 'column' => 'kind', 'type' => 'key' ),
			array( 'param' => 'level', 'values' => array(
				'hot'  => array( 'kind' => array( 'a', 'b' ) ),
				'late' => function () {
					return array( 'kind' => 'c' );
				},
			) ),
			array( 'param' => 'src', 'values' => function ( $v ) {
				if ( 'browser' === $v ) {
					return array( 'src' => array( 'any' => array( '', null ) ) );
				}
				if ( 'app' === $v ) {
					return array( 'src' => array( 'any' => array( array( 'contains' => '|app:' ), array( 'starts' => 'app:' ) ) ) );
				}
				return array( 'src' => array( 'any' => array( $v, array( 'starts' => $v . '|' ) ) ) );
			} ),
		),
		'search' => array(
			'columns' => array( 'actor' ),
			'also'    => function ( $term ) {
				return 'magic' === $term ? array( 'kind' => 'c' ) : null;
			},
		),
		'range'  => array( 'column' => 'created', 'clock' => 'epoch' ),
		'order'  => array( 'column' => 'created' ),
		'batch'  => function ( array $rows ) use ( &$page_calls ) {
			$page_calls[] = count( $rows );
			return 'batched';
		},
		'row'    => function ( array $r, array $q ) {
			return array( 'id' => (int) $r['id'], 'actor' => $r['actor'], 'date' => $r['created'], 'page' => $q['batch'] . '@' . $q['page'], 'site_id' => $r['site_id'] );
		},
		'fields' => array( 'id' => 'int', 'actor' => 'text', 'page' => 'text', 'date' => array( 'type' => 'datetime', 'stored' => 'epoch', 'wire' => 'z' ) ),
	),
	'item'   => array( 'view' => function ( array $row ) {
		return array( 'id' => (int) $row['id'] );
	} ),
) );
$check( 'a source whose columns are a function asks it only when a read runs', 0 === $col_calls, (string) $col_calls );
$ids = function ( $params = array() ) use ( $call ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/log', $params );
	return array( $st, array_column( (array) ( $d['items'] ?? array() ), 'id' ), $d );
};
list( $st, $got, $d ) = $ids();
$check( "a fixed condition holds on the list: only this site's rows", 200 === $st && array( 6, 1, 2, 3, 4, 5 ) === $got && 6 === ( $d['total'] ?? -1 ), wp_json_encode( $d ) );
$check( 'the batch hook runs once per page and its answer reaches each row beside the page number', array( 6 ) === $page_calls && 'batched@1' === ( $d['items'][0]['page'] ?? '' ), wp_json_encode( array( $page_calls, $d['items'][0]['page'] ?? null ) ) );
$check( 'an undeclared field never leaves, even one the row built', ! isset( $d['items'][0]['site_id'] ) );
$first = array_values( array_filter( (array) $d['items'], function ( $i ) {
	return 1 === $i['id'];
} ) )[0] ?? array();
$check( "the 'z' wire is UTC with a Z", gmdate( 'Y-m-d\TH:i:s\Z', $now - 60 ) === ( $first['date'] ?? '' ), (string) ( $first['date'] ?? '' ) );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/log/7' );
$check( "another site's row by id is not found (404), never forbidden", 404 === $st, (string) $st );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/log/3' );
$check( "this site's row by id answers", 200 === $st, (string) $st );
foreach ( array( 'a WP_Error' => new WP_Error( 'x', 'x' ), 'false' => false, 'a string' => 'site_id = 1' ) as $what => $answer ) {
	$site_answer = $answer;
	list( $st, $got ) = $ids();
	list( $ist )      = $call( 'GET', '/minn-admin/v1/minn-conformance/log/1' );
	$n                = Minn_Admin_Kernel::log_counts( 'minn-conformance/log' );
	$check( "a fixed condition answering {$what} matches nothing: list, item and card", 200 === $st && array() === $got && 404 === $ist && 0 === ( $n['total'] ?? -1 ) && array() === Minn_Admin_Kernel::distinct( 'minn-conformance/log', 'kind' ), $st . ' ' . $ist . ' ' . wp_json_encode( $n ) );
}
$site_answer = array( 'site_id' => 1 );
list( , $got ) = $ids( array( 'kind' => 'B' ) );
$check( "a 'key' facet matches the value as sanitize_key() leaves it", array( 3, 4 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'kind' => "' OR 1=1 --" ) );
$check( "a 'key' facet's hostile value narrows to its sanitized self, never widens", array() === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'level' => 'hot' ) );
$check( 'a mapped facet value narrows by its conditions', array( 1, 2, 3, 4 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'level' => 'late' ) );
$check( 'a mapped facet value may be a function answering conditions', array( 6, 5 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'level' => 'nope' ) );
$check( 'an unknown facet value narrows nothing', 6 === count( $got ), wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'src' => 'browser' ) );
$check( "'any' of an empty string and NULL", array( 1, 2 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'src' => 'rest' ) );
$check( "'any' of exact and 'starts': rest and rest|app, not restx", array( 3, 4 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'src' => 'app' ) );
$check( "'contains' and 'starts' together", array( 4, 5 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'src' => '%' ) );
$check( "a LIKE value's wildcard is a literal, never a wildcard", array() === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'search' => '_' ) );
$check( "a search for '_' finds only the row holding one", array( 3 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'search' => 'magic' ) );
$check( "a search's 'also' conditions are one more way to match", array( 6, 5 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $ids( array( 'search' => 'bob' ) );
$check( 'a search still matches its columns', array( 2 ) === $got, wp_json_encode( $got ) );
$n = Minn_Admin_Kernel::log_counts( 'minn-conformance/log', array(
	'top'    => array( 'column' => 'kind', 'window' => 30 * DAY_IN_SECONDS ),
	'counts' => array( 'b_week' => array( 'where' => array( 'kind' => 'b' ), 'window' => 7 * DAY_IN_SECONDS ), 'c_ever' => array( 'where' => array( 'kind' => 'c' ) ) ),
	'chart'  => array( 'split' => 'kind', 'secondary' => array( 'c' ) ),
) );
$check( "a card counts only this site's rows, by the list's date column", 1 + 1 === $n['day'] && 4 === $n['week'] && 6 === $n['total'], wp_json_encode( array( $n['day'], $n['week'], $n['total'] ) ) );
$check( "a card's latest is the newest row by key, on this site", (string) ( $now - 30 ) === $n['last'], (string) $n['last'] );
$check( "a card's top value breaks ties by the value", array( 'value' => 'a', 'count' => 2 ) === $n['top'], wp_json_encode( $n['top'] ) );
$check( "a card's named counts keep their windows", 1 === $n['counts']['b_week'] && 2 === $n['counts']['c_ever'], wp_json_encode( $n['counts'] ) );
$chart_total = 0;
foreach ( $n['days'] as $day ) {
	$chart_total += (int) $day['value'] + (int) $day['secondary'];
}
$check( "a card's chart counts this site's last fourteen days", 5 === $chart_total, wp_json_encode( $n['days'] ) );
$check( 'a tab list is the distinct values on this site only', array( 'a', 'b', 'c' ) === Minn_Admin_Kernel::distinct( 'minn-conformance/log', 'kind' ) );
$check( 'a card from a bare source has no scope but its own conditions', 2 === Minn_Admin_Kernel::log_counts( array( 'table' => 'minn_conf_log', 'network' => true, 'key' => 'id', 'where' => array( 'site_id' => 2 ), 'date' => array( 'column' => 'created', 'clock' => 'epoch' ) ) )['total'] );
// A table that is not there.
Minn_Admin_Kernel::resource( 'minn-conformance/gone', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_nowhere', 'key' => 'id', 'columns' => array( 'id' ) ),
	'list'   => array( 'range' => array( 'column' => 'created', 'clock' => 'epoch' ), 'row' => function ( $r ) {
		return $r;
	} ),
	'item'   => array( 'view' => function ( $r ) {
		return $r;
	} ),
) );
$wpdb->last_error = '';
list( $st, $d )   = $call( 'GET', '/minn-admin/v1/minn-conformance/gone' );
list( $ist )      = $call( 'GET', '/minn-admin/v1/minn-conformance/gone/1' );
$check( 'a missing table is an empty list, a 404 and no card, never a SQL error', 200 === $st && 0 === ( $d['total'] ?? -1 ) && 404 === $ist && null === Minn_Admin_Kernel::log_counts( 'minn-conformance/gone' ) && '' === $wpdb->last_error, $st . ' ' . $ist . ' ' . $wpdb->last_error );
$check( 'has_column knows a column from a missing one', Minn_Admin_Kernel_SQL::has_column( array( 'table' => 'minn_conf_log', 'network' => true ), 'src' ) && ! Minn_Admin_Kernel_SQL::has_column( array( 'table' => 'minn_conf_log', 'network' => true ), 'nope' ) );
list( $tag_sql ) = Minn_Admin_Kernel_SQL::where( array( "kind\0one" => 'a', "kind\0two" => array( 'not' => array( 'b' ) ) ) );
$check( 'two rules on one column, tagged, both apply', 2 === count( $tag_sql ), wp_json_encode( $tag_sql ) );
// An array source.
$mem = array(
	array( 'id' => 'f1', 'who' => 'Ann', 'locked' => true, 'level' => 'warn', 'when' => $now - 60 ),
	array( 'id' => 'f2', 'who' => 'bob', 'locked' => false, 'level' => 'info', 'when' => $now - 3 * DAY_IN_SECONDS ),
	array( 'id' => 'f3', 'who' => 'Cy', 'locked' => true, 'level' => 'info', 'when' => $now - 30 * DAY_IN_SECONDS ),
);
Minn_Admin_Kernel::resource( 'minn-conformance/mem', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'rows' => function () use ( $mem ) {
		return $mem;
	} ),
	'list'   => array(
		'per_page' => 2,
		'facets'   => array(
			array( 'param' => 'kind', 'values' => array( 'locked' => array( 'locked' => true ) ) ),
			array( 'param' => 'level', 'column' => 'level', 'type' => 'key' ),
		),
		'search'   => array( 'columns' => array( 'who' ) ),
		'range'    => array( 'column' => 'when', 'clock' => 'epoch' ),
		'fields'   => array( 'id' => 'text', 'who' => 'text' ),
	),
) );
$mids = function ( $params = array() ) use ( $call ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mem', $params );
	return array( array_column( (array) ( $d['items'] ?? array() ), 'id' ), $d['total'] ?? -1, $d );
};
list( $got, $total, $d ) = $mids();
$check( 'an array source pages in its own order, with the full total', array( 'f1', 'f2' ) === $got && 3 === $total, wp_json_encode( $d ) );
$check( 'an array source with no row builder sends only the declared fields', array( 'id', 'who' ) === array_keys( (array) ( $d['items'][0] ?? array() ) ), wp_json_encode( $d['items'][0] ?? null ) );
list( $got ) = $mids( array( 'kind' => 'locked' ) );
$check( 'an array facet matches a boolean as a boolean', array( 'f1', 'f3' ) === $got, wp_json_encode( $got ) );
list( $got ) = $mids( array( 'level' => 'INFO' ) );
$check( "an array 'key' facet matches as sanitized", array( 'f2', 'f3' ) === $got, wp_json_encode( $got ) );
list( $got ) = $mids( array( 'search' => 'AN' ) );
$check( 'an array search ignores case', array( 'f1' ) === $got, wp_json_encode( $got ) );
list( $got ) = $mids( array( 'after' => wp_date( 'Y-m-d', $now - 7 * DAY_IN_SECONDS ) ) );
$check( 'an array range narrows by its column', array( 'f1', 'f2' ) === $got, wp_json_encode( $got ) );
$check( 'matches() mirrors where(): IN, NOT, operators, IS NULL', Minn_Admin_Kernel_SQL::matches( array( 'a' => 2, 'b' => null ), array( 'a' => array( 1, 2 ), "a\0x" => array( 'not' => array( 3 ) ), "a\0y" => array( 'op' => '>=', 'value' => 2 ), 'b' => null ) )
	&& ! Minn_Admin_Kernel_SQL::matches( array( 'a' => 2 ), array( 'a' => array() ) ) && ! Minn_Admin_Kernel_SQL::matches( array( 'a' => 2 ), array( 'a' => array( 'any' => array() ) ) ) );
// What the review of the log shapes found.
$warned = array();
set_error_handler( function ( $no, $msg ) use ( &$warned ) {
	$warned[] = $msg;
	return true;
} );
list( $st, $got ) = $ids( array( 'kind' => array( 'a' ), 'search' => array( 'x' ) ) );
restore_error_handler();
$check( 'a facet or search sent as an array narrows nothing and raises no notice', 200 === $st && 6 === count( $got ) && ! $warned, wp_json_encode( $warned ) );
$threw = false;
try {
	Minn_Admin_Kernel_SQL::where( array( 'kind' => array( 'oops' => 'a' ) ) );
} catch ( InvalidArgumentException $e ) {
	$threw = true;
}
$check( 'a keyed array that is no known rule is refused, never read as a list', $threw );
list( $gap_sql, $gap_args ) = Minn_Admin_Kernel_SQL::where( array( 'kind' => array( 3 => 'a', 7 => 'b' ) ) );
$check( 'a list with gaps in its keys is still a list', array( 'kind IN (%s,%s)' ) === $gap_sql && array( 'a', 'b' ) === $gap_args, wp_json_encode( $gap_sql ) );
$check( "matches(): a boolean equals a boolean or 1 and 0, never the text 'false'", Minn_Admin_Kernel_SQL::matches( array( 'x' => true ), array( 'x' => true ) ) && Minn_Admin_Kernel_SQL::matches( array( 'x' => '1' ), array( 'x' => true ) ) && ! Minn_Admin_Kernel_SQL::matches( array( 'x' => 'false' ), array( 'x' => true ) ) && ! Minn_Admin_Kernel_SQL::matches( array( 'x' => '2' ), array( 'x' => true ) ) );
$check( 'matches(): an operator compares numbers only when both are numbers', ! Minn_Admin_Kernel_SQL::matches( array( 'x' => 'abc' ), array( 'x' => array( 'op' => '>', 'value' => 0 ) ) ) && Minn_Admin_Kernel_SQL::matches( array( 'x' => '10' ), array( 'x' => array( 'op' => '>', 'value' => 9 ) ) ) );
$check( "matches(): NULL meets 'any' with a NULL member, and an empty NOT", Minn_Admin_Kernel_SQL::matches( array( 'x' => null ), array( 'x' => array( 'any' => array( '', null ) ) ) ) && Minn_Admin_Kernel_SQL::matches( array( 'x' => null ), array( 'x' => array( 'not' => array() ) ) ) && ! Minn_Admin_Kernel_SQL::matches( array( 'x' => null ), array( 'x' => array( 'not' => array( 1 ) ) ) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/mem-scoped', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array(
		'rows'  => function () use ( $mem ) {
			return $mem;
		},
		'where' => array( 'level' => 'info' ),
	),
	'list'   => array( 'fields' => array( 'id' => 'text' ) ),
) );
list( , $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mem-scoped' );
$check( "an array source is held to its declared 'where'", array( 'f2', 'f3' ) === array_column( (array) ( $d['items'] ?? array() ), 'id' ), wp_json_encode( $d ) );
// A column list that happens to name a class and its method stays a list.
class Minn_Conf_Cols {
	public static $called = 0;
	public static function kind() {
		self::$called++;
		return array( 'nope' );
	}
}
$ct = $wpdb->prefix . 'minn_conf_cols';
$wpdb->query( "CREATE TEMPORARY TABLE {$ct} ( Minn_Conf_Cols BIGINT UNSIGNED NOT NULL PRIMARY KEY, kind VARCHAR(20) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$wpdb->insert( $ct, array( 'Minn_Conf_Cols' => 1, 'kind' => 'k' ) );
Minn_Admin_Kernel::resource( 'minn-conformance/cols', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_cols', 'key' => 'Minn_Conf_Cols', 'columns' => array( 'Minn_Conf_Cols', 'kind' ) ),
	'list'   => array( 'fields' => array( 'kind' => 'text' ) ),
) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/cols' );
$check( "a two-column list naming a class and its method is read as columns, never called", 200 === $st && 0 === Minn_Conf_Cols::$called && 'k' === ( $d['items'][0]['kind'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
Minn_Admin_Kernel::resource( 'minn-conformance/nometa', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_log', 'network' => true, 'key' => 'id', 'columns' => array( 'id', 'actor' ), 'where' => array( 'site_id' => 1 ) ),
	'list'   => array(
		'search' => array( 'columns' => array( 'actor' ), 'meta' => array( 'table' => 'minn_conf_nowhere_meta', 'key' => 'log_id', 'name' => 'k', 'value' => 'v' ) ),
		'fields' => array( 'id' => 'int' ),
	),
) );
$wpdb->last_error = '';
list( $st, $d )   = $call( 'GET', '/minn-admin/v1/minn-conformance/nometa', array( 'search' => 'ann' ) );
$check( 'a search over a meta table that is not there searches the columns, never a SQL error', 200 === $st && array( 1 ) === array_column( (array) ( $d['items'] ?? array() ), 'id' ) && '' === $wpdb->last_error, $st . ' ' . $wpdb->last_error );
$check( 'the readers answer nothing for a table that is not there', 0 === Minn_Admin_Kernel_SQL::count( 'minn_conf_nowhere' ) && array() === Minn_Admin_Kernel_SQL::select( 'minn_conf_nowhere', array( 'id' ) ) && null === Minn_Admin_Kernel_SQL::top( 'minn_conf_nowhere', 'id', array() ) && array() === Minn_Admin_Kernel_SQL::distinct( 'minn_conf_nowhere', 'id', array() ) && '' === $wpdb->last_error, $wpdb->last_error );

// An endpoint id of another shape.
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-hex/(?P<id>[a-f0-9]{32})', array( 'gate' => '__return_true', 'run' => function ( $r, $ctx ) {
	return array( 'id' => $ctx['id'] );
} ) );
$hex            = md5( 'minn' ); // Starts with a letter: an int cast would make it 0.
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/ep-hex/' . $hex );
$check( 'an endpoint id that is not \d+ reaches the handler as its string', 200 === $st && $hex === ( $d['id'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );

// An endpoint's args: checked after its gate, as an action's are, so a caller
// the gate refuses learns nothing from a 400; args with callbacks WordPress
// would run before the gate leave it with no route.
$ep_gate  = true;
$ep_seen  = array();
$ep_early = false;
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-args', array(
	'methods' => 'POST',
	'gate'    => function () use ( &$ep_gate ) {
		return $ep_gate;
	},
	'args'    => array(
		'on' => array( 'type' => 'boolean', 'required' => true ),
		'n'  => array( 'type' => 'integer' ),
	),
	'run'     => function ( WP_REST_Request $r ) use ( &$ep_seen ) {
		$ep_seen[] = gettype( $r['on'] ) . ':' . wp_json_encode( $r['on'] );
		return array( 'done' => true );
	},
) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-args-early', array(
	'methods' => 'POST',
	'gate'    => '__return_true',
	'args'    => array( 'x' => array( 'type' => 'string', 'sanitize_callback' => function ( $v ) use ( &$ep_early ) {
		$ep_early = true;
		return $v;
	} ) ),
	'run'     => '__return_true',
) );
$ep_base          = '/minn-admin/v1/minn-conformance/ep-args';
list( $st, $d )   = $call( 'POST', $ep_base, array(), array() );
list( $st2, $d2 ) = $call( 'POST', $ep_base, array(), array( 'on' => 'maybe' ) );
$ep_gate          = false;
list( $st3 )      = $call( 'POST', $ep_base, array(), array() );
list( $st4 )      = $call( 'POST', $ep_base, array(), array( 'on' => 'maybe' ) );
$ep_gate          = true;
$call( 'POST', $ep_base, array(), array( 'on' => 'false' ) );
list( $st5 ) = $call( 'POST', $ep_base . '-early', array(), array( 'x' => 'y' ) );
$check( "an endpoint's arguments are checked after its gate, missing and invalid apart, and reach it as their type; args with callbacks have no route", 400 === $st && 'rest_missing_callback_param' === ( $d['code'] ?? '' ) && 400 === $st2 && 'rest_invalid_param' === ( $d2['code'] ?? '' ) && 403 === $st3 && 403 === $st4 && array( 'boolean:false' ) === $ep_seen && 404 === $st5 && ! $ep_early, implode( ' ', array( $st, $st2, $st3, $st4, $st5 ) ) . ' ' . wp_json_encode( $ep_seen ) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-args-cap/(?P<file>[a-z.]+)', array(
	'methods' => 'POST',
	'gate'    => '__return_true',
	'args'    => array( 'file' => array( 'type' => 'string' ) ),
	'run'     => function ( WP_REST_Request $r ) {
		return array( 'file' => Minn_Admin::path_param( $r, 'file' ) );
	},
) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-args-default', array(
	'methods' => 'POST',
	'gate'    => '__return_true',
	'args'    => array( 'on' => array( 'type' => 'boolean', 'default' => true ) ),
	'run'     => '__return_true',
) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/ep-args-req', array(
	'methods' => 'POST',
	'gate'    => '__return_true',
	'args'    => array( 'on' => array( 'type' => 'boolean', 'required' => 'false' ) ),
	'run'     => '__return_true',
) );
$ep_seen          = array();
list( $st )       = $call( 'POST', '/minn-admin/v1/minn-conformance/ep-args-cap/a.php', array(), array( 'file' => 'b.php' ) );
list( $st2 )      = $call( 'POST', '/minn-admin/v1/minn-conformance/ep-args-default', array(), array() );
list( $st3 )      = $call( 'POST', '/minn-admin/v1/minn-conformance/ep-args-req', array(), array() );
list( $st4, $d4 ) = $call( 'POST', $ep_base, array(), array( 'on' => null ) );
list( $st5 )      = $call( 'POST', $ep_base, array(), array( 'on' => true, 'n' => null ) );
$check( 'args never name what the route captures, carry no default and only a boolean required, or the endpoint has no route; a null is no value', 404 === $st && 404 === $st2 && 404 === $st3 && 400 === $st4 && 'rest_missing_callback_param' === ( $d4['code'] ?? '' ) && 200 === $st5 && array( 'boolean:true' ) === $ep_seen, implode( ' ', array( $st, $st2, $st3, $st4, $st5 ) ) . ' ' . wp_json_encode( $ep_seen ) );

// --- 1c. Booking shapes: joins, views with their own order, cards ahead ----
$bk  = $wpdb->prefix . 'minn_conf_bk';
$bkc = $wpdb->prefix . 'minn_conf_bk_cust';
$bki = $wpdb->prefix . 'minn_conf_bk_items';
$wpdb->query( "CREATE TEMPORARY TABLE {$bk} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, owner BIGINT NOT NULL, status VARCHAR(20) NOT NULL, cust_id BIGINT NOT NULL, starts DATETIME NOT NULL, note VARCHAR(60) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$wpdb->query( "CREATE TEMPORARY TABLE {$bkc} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(60) NOT NULL, email VARCHAR(60) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$wpdb->query( "CREATE TEMPORARY TABLE {$bki} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, bk_id BIGINT NOT NULL, label VARCHAR(60) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
foreach ( array( array( 1, 'Ann Able', 'ann@x.test' ), array( 2, 'Bo Brook', 'bo@x.test' ) ) as $c ) {
	$wpdb->insert( $bkc, array( 'id' => $c[0], 'name' => $c[1], 'email' => $c[2] ) );
}
$utc_day = function ( $days, $hour = 12 ) {
	return ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( sprintf( '%+d days', $days ) )->setTime( $hour, 0 )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
};
// id, owner, status, customer, starts (UTC), note.
$bks = array(
	array( 1, 10, 'approved', 1, $utc_day( 0 ), 'today, approved' ),
	array( 2, 10, 'pending', 2, $utc_day( 0, 15 ), 'today, pending' ),
	array( 3, 10, 'approved', 2, $utc_day( 3 ), 'in three days' ),
	array( 4, 10, 'canceled', 1, $utc_day( 5 ), 'canceled' ),
	array( 5, 10, 'approved', 1, $utc_day( -4 ), 'four days ago' ),
	array( 6, 20, 'approved', 1, $utc_day( 1 ), 'another owner' ),
);
foreach ( $bks as $b ) {
	$wpdb->insert( $bk, array( 'id' => $b[0], 'owner' => $b[1], 'status' => $b[2], 'cust_id' => $b[3], 'starts' => $b[4], 'note' => $b[5] ) );
}
// Several items per booking: the first-row join picks the lowest id.
foreach ( array( array( 7, 3, 'second' ), array( 4, 3, 'first' ), array( 9, 1, 'only' ) ) as $it ) {
	$wpdb->insert( $bki, array( 'id' => $it[0], 'bk_id' => $it[1], 'label' => $it[2] ) );
}
$gate_rows = array();
Minn_Admin_Kernel::resource( 'minn-conformance/bk', array(
	'gates'     => array( 'view' => '__return_true', 'manage' => '__return_true' ),
	'item_gate' => function ( $id, $gate, $row = null ) use ( &$gate_rows ) {
		$gate_rows[] = is_array( $row ) ? (int) $row['id'] : 'none';
		return true;
	},
	'source'    => array(
		'table'   => 'minn_conf_bk',
		'key'     => 'id',
		'joins'   => array(
			'c'  => array( 'table' => 'minn_conf_bk_cust', 'on' => array( 'id' => 'e.cust_id' ) ),
			'it' => array( 'table' => 'minn_conf_bk_items', 'first' => array( 'key' => 'id', 'by' => 'bk_id', 'of' => 'id' ) ),
		),
		'columns' => array( 'id', 'status', 'starts', 'note' => 'what', 'c.name', 'c.email' => 'customer_email', 'it.label' => 'first_item' ),
		'where'   => array( 'owner' => 10 ),
	),
	'list'      => array(
		'filter' => array(
			'param'   => 'range',
			'default' => 'upcoming',
			'values'  => array(
				'upcoming' => function () {
					return array( 'status' => array( 'approved', 'pending' ), 'starts' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc', 0 ) ) );
				},
				'today'    => function () {
					return array( "starts\0from" => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc', 0 ) ), "starts\0to" => array( 'op' => '<', 'value' => Minn_Admin_Kernel::at( 'utc', 1 ) ) );
				},
				'broken'   => function () {
					return new WP_Error( 'x', 'x' );
				},
				'all'      => array(),
			),
			'order'   => array( 'upcoming' => 'ASC', 'today' => 'ASC' ),
		),
		'search' => array( 'columns' => array( 'c.name', 'c.email', 'note' ) ),
		'order'  => array( 'column' => 'starts', 'dir' => 'DESC' ),
		'fields' => array( 'id' => 'int', 'what' => 'text', 'name' => 'text', 'customer_email' => 'text', 'first_item' => 'text' ),
	),
	'item'      => array( 'view' => function ( array $row ) {
		return $row;
	} ),
	'actions'   => array( 'touch' => array( 'gate' => 'manage', 'run' => '__return_true', 'expect' => function () {
		return true;
	} ) ),
) );
$bids = function ( $params = array() ) use ( $call ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/bk', $params );
	return array( $st, array_column( (array) ( $d['items'] ?? array() ), 'id' ), $d );
};
list( $st, $got, $d ) = $bids( array( 'range' => 'all' ) );
$check( 'a joined list reads joined columns under their names, and never another owner', 200 === $st && array( 4, 3, 2, 1, 5 ) === $got && 'Ann Able' === ( $d['items'][4]['name'] ?? '' ) && 'ann@x.test' === ( $d['items'][4]['customer_email'] ?? '' ), wp_json_encode( $d ) );
$by = array_column( (array) $d['items'], null, 'id' );
$check( "a 'first' join reads the lowest row of the many, and none when there is none", 'first' === ( $by[3]['first_item'] ?? '' ) && 'only' === ( $by[1]['first_item'] ?? '' ) && '' === ( $by[2]['first_item'] ?? 'x' ), wp_json_encode( $by ) );
$check( "a plain column can be named in the row ('note' => 'what')", 'canceled' === ( $by[4]['what'] ?? '' ) );
list( , $got ) = $bids();
$check( 'a view whose conditions are a function, with its own order (upcoming soonest first)', array( 1, 2, 3 ) === $got, wp_json_encode( $got ) );
list( , $got ) = $bids( array( 'range' => 'today' ) );
$check( "today's bounds on a UTC column are the site's local day", array( 1, 2 ) === $got, wp_json_encode( $got ) );
list( $st, $got, $d ) = $bids( array( 'range' => 'broken' ) );
$check( 'a view that cannot answer shows nothing', 200 === $st && array() === $got && 0 === ( $d['total'] ?? -1 ), wp_json_encode( $d ) );
list( , $got ) = $bids( array( 'range' => 'all', 'search' => 'bo@' ) );
$check( 'search reaches a joined column', array( 3, 2 ) === $got, wp_json_encode( $got ) );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/bk/6' );
$check( "another owner's booking by id is not found", 404 === $st, (string) $st );
$gate_rows      = array();
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/bk/3' );
$check( 'an item reads through the joins', 200 === $st && 'Bo Brook' === ( $d['name'] ?? '' ) && 'first' === ( $d['first_item'] ?? '' ), wp_json_encode( $d ) );
$call( 'POST', '/minn-admin/v1/minn-conformance/bk/3/touch' );
$check( 'the row gate is handed the row it decides on', array( 3, 3 ) === $gate_rows, wp_json_encode( $gate_rows ) );
$check( 'a card counts through the joins, the conditions and the owner', 2 === Minn_Admin_Kernel::count( 'minn-conformance/bk', array( 'c.name' => 'Bo Brook' ) ) && 5 === Minn_Admin_Kernel::count( 'minn-conformance/bk' ) );
$check( "a card's first is the soonest value among the rows it may see", $utc_day( 0 ) === Minn_Admin_Kernel::first( 'minn-conformance/bk', 'starts', array( 'starts' => array( 'op' => '>=', 'value' => Minn_Admin_Kernel::at( 'utc', 0 ) ) ) ) );
$ahead = Minn_Admin_Kernel::days( 'minn-conformance/bk', array( 'column' => 'starts', 'clock' => 'utc', 'ahead' => true, 'where' => array( 'status' => array( 'approved', 'pending' ) ), 'split' => 'status', 'secondary' => array( 'pending' ) ) );
$keys  = array_keys( $ahead );
$today = $ahead[ Minn_Admin_Kernel::at( 'date', 0 ) ] ?? array();
$in3   = $ahead[ Minn_Admin_Kernel::at( 'date', 3 ) ] ?? array();
$check( 'a chart ahead is fourteen days from today', 14 === count( $keys ) && Minn_Admin_Kernel::at( 'date', 0 ) === $keys[0] && Minn_Admin_Kernel::at( 'date', 13 ) === $keys[13], wp_json_encode( $keys ) );
$check( "a chart ahead splits without overlap: today's approved and pending apart", 1 === ( $today['value'] ?? -1 ) && 1 === ( $today['secondary'] ?? -1 ) && 1 === ( $in3['value'] ?? -1 ) && 0 === ( $in3['secondary'] ?? -1 ), wp_json_encode( array( $today, $in3 ) ) );
$check( "a chart ahead counts no other owner's rows and no past ones", 3 === array_sum( array_map( function ( $x ) {
	return (int) $x['value'] + (int) $x['secondary'];
}, $ahead ) ), wp_json_encode( $ahead ) );
$site_answer = false;
$check( 'a card whose conditions cannot be read counts nothing and has no first', 0 === Minn_Admin_Kernel::count( 'minn-conformance/log' ) && null === Minn_Admin_Kernel::first( 'minn-conformance/log', 'created' ) && 0 === array_sum( array_column( Minn_Admin_Kernel::days( 'minn-conformance/log', array( 'column' => 'created', 'clock' => 'epoch' ) ), 'value' ) ) );
$site_answer = array( 'site_id' => 1 );
$zone = wp_timezone();
$mid  = new DateTimeImmutable( 'today', $zone );
$check( "at(): 'now' on the epoch clock is now", abs( Minn_Admin_Kernel::at( 'epoch' ) - time() ) <= 2 );
$check( "at(): a local epoch is the epoch plus the site's offset", Minn_Admin_Kernel::at( 'local-epoch', 0 ) === $mid->getTimestamp() + $mid->getOffset() );
$check( "at(): day 0 is the site's midnight on every clock", $mid->format( 'Y-m-d' ) === Minn_Admin_Kernel::at( 'date', 0 ) && $mid->format( 'Y-m-d 00:00:00' ) === Minn_Admin_Kernel::at( 'local', 0 ) && $mid->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) === Minn_Admin_Kernel::at( 'utc', 0 ) );
$check( "columns(): '*' is every main-table column beside joined ones", 'e.*, c.name AS name' === Minn_Admin_Kernel_SQL::columns( array( '*', 'c.name' ) ) );
$bad = false;
try {
	Minn_Admin_Kernel_SQL::from( array( 'table' => 'minn_conf_bk', 'joins' => array( 'e' => array( 'table' => 'x', 'on' => array( 'id' => 'e.id' ) ) ) ) );
} catch ( InvalidArgumentException $e ) {
	$bad = true;
}
$check( "a join may not take the main table's alias", $bad );
// From the review of the booking shapes.
$seen_where = null;
Minn_Admin_Kernel::resource( 'minn-conformance/srcfilter', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'list' => function ( array $q ) use ( &$seen_where ) {
		$seen_where = $q['where'];
		return array( array(), 0 );
	} ),
	'list'   => array( 'filter' => array( 'param' => 'status', 'values' => array( 'publish' => array( 'post_status' => 'publish' ), 'spam' => array( 'post_status' => 'spam' ) ) ) ),
) );
$call( 'GET', '/minn-admin/v1/minn-conformance/srcfilter', array( 'status' => 'spam' ) );
$check( "a callable source reads a filter's conditions under their own names", 'spam' === ( $seen_where['post_status'] ?? null ), wp_json_encode( $seen_where ) );
$wpdb->query( "ALTER TABLE {$bkc} ADD COLUMN owner BIGINT NOT NULL DEFAULT 10" ); // phpcs:ignore
Minn_Admin_Kernel::resource( 'minn-conformance/bkscope', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array(
		'table'   => 'minn_conf_bk',
		'key'     => 'id',
		'joins'   => array( 'c' => array( 'table' => 'minn_conf_bk_cust', 'on' => array( 'id' => 'e.cust_id' ) ) ),
		'columns' => array( 'id', 'c.owner' ),
	),
	'list'   => array( 'scope' => array( 'column' => 'owner', 'allowed' => function () {
		return array( 10 );
	} ) ),
	'item'   => array( 'view' => function ( array $row ) {
		return $row;
	} ),
) );
list( $st )     = $call( 'GET', '/minn-admin/v1/minn-conformance/bkscope/6' );
list( $ok, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/bkscope/1' );
$check( "a joined column sharing the scope column's name never stands in for it", 403 === $st && 200 === $ok && ! array_key_exists( '_minn_scope', (array) $d ), $st . ' ' . $ok . ' ' . wp_json_encode( $d ) );
$check( 'a core table is named the way $wpdb names it', $wpdb->users === Minn_Admin_Kernel_SQL::table( array( 'core' => 'users' ) ) && $wpdb->posts === Minn_Admin_Kernel_SQL::table( array( 'core' => 'posts' ) ) );
$bad = false;
try {
	Minn_Admin_Kernel_SQL::table( array( 'core' => 'nope' ) );
} catch ( InvalidArgumentException $e ) {
	$bad = true;
}
$check( 'a core table that is not one is refused', $bad );
$bad = false;
try {
	Minn_Admin_Kernel_SQL::from( array( 'table' => 'minn_conf_bk', 'joins' => array( 'E' => array( 'table' => 'x', 'on' => array( 'id' => 'e.id' ) ) ) ) );
} catch ( InvalidArgumentException $e ) {
	$bad = true;
}
$check( "a join may not take the main table's alias in another case", $bad );
$no_col = Minn_Admin_Kernel_SQL::has_column( 'minn_conf_bk', 'late_column' );
$wpdb->query( "ALTER TABLE {$bk} ADD COLUMN late_column VARCHAR(10) NULL" ); // phpcs:ignore
$check( 'a column a migration adds after a "no" is seen at the next read', ! $no_col && Minn_Admin_Kernel_SQL::has_column( 'minn_conf_bk', 'late_column' ) );

// --- 1d. Mail shapes: more reads of an item, NOT any, deletes, sends -------
$ml = $wpdb->prefix . 'minn_conf_mail';
$wpdb->query( "CREATE TEMPORARY TABLE {$ml} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, owner BIGINT NOT NULL, error VARCHAR(60) NULL, created DATETIME NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$ml_now = current_time( 'mysql' );
// id, owner, error (NULL and '' are a delivered email; any text a failure).
foreach ( array( array( 1, 10, null ), array( 2, 10, '' ), array( 3, 10, 'SMTP timeout' ), array( 4, 10, 'Sent ( ** Fallback ** )' ), array( 5, 20, 'another owner' ), array( 6, 10, 'to purge' ), array( 7, 10, 'to keep' ) ) as $m ) {
	$wpdb->insert( $ml, array( 'id' => $m[0], 'owner' => $m[1], 'error' => $m[2], 'created' => $ml_now ) );
}
$ml_ok     = array( null, '' );
$detail_on = true;
$ml_row_on = true;
$ml_answer = true;
$ml_fire   = false;
Minn_Admin_Kernel::resource( 'minn-conformance/mail', array(
	'gates'     => array(
		'view'   => '__return_true',
		'detail' => function () use ( &$detail_on ) {
			return $detail_on;
		},
	),
	'item_gate' => function () use ( &$ml_row_on ) {
		return $ml_row_on;
	},
	'labels'    => array( 'not_found' => 'No such email.' ),
	'source'    => array( 'table' => 'minn_conf_mail', 'key' => 'id', 'columns' => array( 'id', 'error', 'created' ), 'where' => array( 'owner' => 10 ) ),
	'list'      => array(
		'facets' => array( array( 'param' => 'status', 'values' => array(
			'sent'   => array( 'error' => array( 'any' => $ml_ok ) ),
			'failed' => array( 'error' => array( 'none' => $ml_ok ) ),
		) ) ),
		'order'  => array( 'column' => 'id' ),
		'fields' => array( 'id' => 'int' ),
	),
	'item'      => array( 'gate' => 'detail', 'view' => function ( array $row ) {
		return array( 'id' => (int) $row['id'] );
	}, 'fields' => array( 'id' => 'int' ) ),
	'reads'     => array(
		'view'   => array( 'gate' => 'detail', 'view' => function ( array $row, WP_REST_Request $r ) {
			return array( 'saw' => (int) $row['id'], 'route' => $r->get_route(), 'leak' => 'undeclared' );
		}, 'fields' => array( 'saw' => 'int', 'route' => 'text' ) ),
		'plain'  => array( 'view' => '__return_empty_array' ),
		'nogate' => array( 'gate' => 'undeclared', 'view' => '__return_empty_array' ),
	),
	'actions'   => array(
		'send'  => array( 'gate' => 'view', 'run' => function () use ( &$ml_answer, &$ml_fire ) {
			if ( $ml_fire ) {
				do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP connect() failed.' ) );
			}
			return $ml_answer;
		}, 'expect' => 'ran' ),
		'purge' => array( 'method' => 'DELETE', 'gate' => 'view', 'run' => function ( $id ) {
			return Minn_Admin_Kernel_SQL::delete( 'minn_conf_mail', 'id', $id, array( 'owner' => 10 ) );
		}, 'expect' => 'gone' ),
	),
) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/1/view' );
$check( 'a further read of an item answers on its own route with the loaded row, only its declared fields leaving', 200 === $st && 1 === ( $d['saw'] ?? 0 ) && '/minn-admin/v1/minn-conformance/mail/1/view' === ( $d['route'] ?? '' ) && ! array_key_exists( 'leak', (array) $d ), $st . ' ' . wp_json_encode( $d ) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/99/view' );
list( $st5 )    = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/5/view' );
$check( "a further read of a missing item, or of one the source's conditions exclude, is not found", 404 === $st && 'No such email.' === ( $d['message'] ?? '' ) && 404 === $st5, $st . ' ' . $st5 );
$detail_on   = false;
list( $st )  = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/1/view' );
list( $stp ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/1/plain' );
$detail_on   = true;
list( $stq ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/1/plain' );
$check( "a further read that names no gate answers to the item's", 403 === $stp && 200 === $stq, $stp . ' ' . $stq );
$ml_row_on   = false;
list( $st2 ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/1/view' );
$ml_row_on   = true;
$check( 'a further read answers to its own gate, then to the row gate', 403 === $st && 403 === $st2, $st . ' ' . $st2 );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mail/1/nogate' );
$check( 'a further read naming no declared gate has no route', 404 === $st && 'rest_no_route' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
$ml_ids = function ( $params ) use ( $call ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/mail', $params );
	return 200 === $st ? array_column( (array) ( $d['items'] ?? array() ), 'id' ) : $st;
};
$failed = $ml_ids( array( 'status' => 'failed' ) );
$sent   = $ml_ids( array( 'status' => 'sent' ) );
$check( "'none' is NOT any of its rules in SQL, a NULL value meeting its IS NULL arm", array( 7, 6, 4, 3 ) === $failed && array( 2, 1 ) === $sent, wp_json_encode( array( $failed, $sent ) ) );
$none = function ( $v, $rule ) {
	try {
		return Minn_Admin_Kernel_SQL::matches( array( 'error' => $v ), array( 'error' => $rule ) );
	} catch ( InvalidArgumentException $e ) {
		return 'refused';
	}
};
$check( "'none' is SQL only: held in PHP it is refused, where NOT of a narrower test would be wider", 'refused' === $none( null, array( 'none' => array( 'sent' ) ) ) && 'refused' === $none( 'SENT', array( 'none' => array( 'sent' ) ) ) && 'refused' === $none( 'x', array( 'none' => array() ) ) );
$check( "PHP's negations are never wider than SQL's: case, trailing spaces and a number's text", false === $none( 'SENT', array( 'not' => array( 'sent' ) ) ) && false === $none( 'sent ', array( 'op' => '!=', 'value' => 'sent' ) ) && false === $none( 'abc', array( 'op' => '!=', 'value' => 0 ) ) && false === $none( null, array( 'not' => array( 'a' ) ) ) && true === $none( 'x', array( 'not' => array( 'sent' ) ) ) && true === $none( 'x', array( 'op' => '!=', 'value' => 'sent' ) ) );
$two = 0;
foreach ( array( array( 'none' => array( 'q' ), 'starts' => 'y' ), array( 'any' => array( 1 ), 'none' => array( 2 ) ), array( 'none' => null ), array( 'any' => 'x' ), array( 'op' => '=', 'value' => 1, 'x' => 2 ) ) as $bad_rule ) {
	try {
		Minn_Admin_Kernel_SQL::where( array( 'error' => $bad_rule ) );
	} catch ( InvalidArgumentException $e ) {
		$two++;
	}
	try {
		Minn_Admin_Kernel_SQL::matches( array( 'error' => 'y' ), array( 'error' => $bad_rule ) );
	} catch ( InvalidArgumentException $e ) {
		$two++;
	}
}
$check( 'a rule of two kinds, or an any or none over no list, is refused in SQL and in PHP', 10 === $two, (string) $two );
try {
	list( $none_sql ) = Minn_Admin_Kernel_SQL::where( array( 'error' => array( 'none' => array() ) ) );
	list( $any_sql )  = Minn_Admin_Kernel_SQL::where( array( 'error' => array( 'any' => array() ) ) );
} catch ( InvalidArgumentException $e ) {
	$none_sql = $any_sql = 'refused';
}
$check( "an empty 'none' matches every row and an empty 'any' none", array( '1 = 1' ) === $none_sql && array( '1 = 0' ) === $any_sql && false === $none( 'x', array( 'any' => array() ) ), wp_json_encode( array( $none_sql, $any_sql ) ) );
$refused = 0;
$kept    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ml}" ); // phpcs:ignore
foreach ( array( array(), 0, -1, array( 0 ), 'abc', array( 2, -3 ) ) as $bad_ids ) {
	try {
		Minn_Admin_Kernel_SQL::delete( 'minn_conf_mail', 'id', $bad_ids, array( 'id' => array( 'not' => array() ) ) );
	} catch ( \Throwable $e ) {
		$refused++;
	}
}
$check( 'a delete names its rows by key, each id above zero, or is refused and empties nothing', 6 === $refused && $kept === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ml}" ), $refused . ' refused' ); // phpcs:ignore
$ml_del = function ( ...$a ) {
	try {
		return Minn_Admin_Kernel_SQL::delete( ...$a );
	} catch ( \Throwable $e ) {
		return 'threw';
	}
};
$check( "a delete's further conditions only narrow it", 0 === $ml_del( 'minn_conf_mail', 'id', 5, array( 'owner' => 10 ) ) && in_array( '5', $wpdb->get_col( "SELECT id FROM {$ml}" ), true ) ); // phpcs:ignore
list( $st, $d ) = $call( 'DELETE', '/minn-admin/v1/minn-conformance/mail/6' );
$left           = $wpdb->get_col( "SELECT id FROM {$ml} ORDER BY id" ); // phpcs:ignore
list( $again )  = $call( 'DELETE', '/minn-admin/v1/minn-conformance/mail/6' );
$check( 'a delete removes the row its conditions name and nothing else, and is then not found', 200 === $st && ! in_array( '6', $left, true ) && in_array( '7', $left, true ) && 6 === count( $left ) && 404 === $again, $st . ' ' . $again . ' ' . wp_json_encode( $left ) );
$check( 'a delete from a table that is not there removes nothing', 0 === $ml_del( 'minn_conf_no_table', 'id', 1 ) );
$ml_sent = function ( $answer, $fire = false ) use ( $call, &$ml_answer, &$ml_fire ) {
	$ml_answer      = $answer;
	$ml_fire        = $fire;
	list( $st, $d ) = $call( 'POST', '/minn-admin/v1/minn-conformance/mail/1/send' );
	$ml_fire        = false;
	return 200 === $st ? 200 : $st . ':' . ( $d['code'] ?? '' );
};
$held = array( $ml_sent( true ), $ml_sent( 'a@x.test' ), $ml_sent( array( 'sent' => true, 'logged' => false ) ) );
$fell = array_unique( array( $ml_sent( false ), $ml_sent( null ), $ml_sent( '' ), $ml_sent( '0' ), $ml_sent( ' ' ), $ml_sent( array() ), $ml_sent( 1 ), $ml_sent( array( 'a@x.test' ) ), $ml_sent( array( false ) ), $ml_sent( array( 'sent' => false ) ) ) );
$check( "a send ('ran') holds on true, whom it sent to or a list saying sent, and nothing else", array( 200, 200, 200 ) === $held && array( '500:not_sent' ) === $fell, wp_json_encode( array( $held, $fell ) ) );
$check( 'a send during which WordPress reports a mail failure did not happen, whatever it answers', '500:not_sent' === $ml_sent( true, true ) && '500:not_sent' === $ml_sent( 'a@x.test', true ) && 200 === $ml_sent( true ) );
$today = current_time( 'Y-m-d' );
$rule  = Minn_Admin_Kernel::days( 'minn-conformance/mail', array( 'column' => 'created', 'clock' => 'local', 'split' => 'error', 'secondary' => array( 'none' => $ml_ok ) ) );
$vals  = Minn_Admin_Kernel::days( 'minn-conformance/mail', array( 'column' => 'created', 'clock' => 'local', 'split' => 'error', 'secondary' => array( 'SMTP timeout' ) ) );
$check( "a chart's secondary may be a rule: any failure text, never another owner's", 2 === ( $rule[ $today ]['value'] ?? -1 ) && 3 === ( $rule[ $today ]['secondary'] ?? -1 ), wp_json_encode( $rule[ $today ] ?? null ) );
$check( "a chart's secondary as values still counts only those values", 4 === ( $vals[ $today ]['value'] ?? -1 ) && 1 === ( $vals[ $today ]['secondary'] ?? -1 ), wp_json_encode( $vals[ $today ] ?? null ) );
// Three hundred texts in one day are still two chart rows, counted as the card counts.
for ( $i = 1; $i <= 300; $i++ ) {
	$wpdb->insert( $ml, array( 'id' => 1000 + $i, 'owner' => 10, 'error' => 'e' . $i, 'created' => $ml_now ) );
}
$wpdb->insert( $ml, array( 'id' => 2000, 'owner' => 10, 'error' => ' ', 'created' => $ml_now ) );
$many  = Minn_Admin_Kernel::days( 'minn-conformance/mail', array( 'column' => 'created', 'clock' => 'local', 'split' => 'error', 'secondary' => array( 'none' => $ml_ok ) ) );
$grown = $wpdb->num_rows;
$fails = Minn_Admin_Kernel::count( 'minn-conformance/mail', array( 'error' => array( 'none' => $ml_ok ) ) );
$check( "a chart split by a rule is two rows a day however many texts, and agrees with the card's count", $grown <= 28 && $fails === ( $many[ $today ]['secondary'] ?? -1 ) && $fails >= 303, $grown . ' rows, ' . $fails . ' counted, ' . wp_json_encode( $many[ $today ] ?? null ) );
$lab  = Minn_Admin_Kernel::days( 'minn-conformance/mail', array( 'column' => 'created', 'clock' => 'local', 'label' => 'M j' ) );
$none = Minn_Admin_Kernel::days( 'minn-conformance/no-such', array( 'column' => 'created', 'clock' => 'local', 'label' => 'M j' ) );
$want = gmdate( 'M j', strtotime( $today . ' 00:00:00 UTC' ) );
$check( "a chart's labels take a date format and its days keep their keys, an empty chart too", $want === ( $lab[ $today ]['label'] ?? '' ) && $want === ( $none[ $today ]['label'] ?? '' ) && 14 === count( $none ), wp_json_encode( array( $lab[ $today ] ?? null, $none[ $today ] ?? null ) ) );
$sql = array( 'type' => 'datetime', 'wire' => 'sql' );
$check( "a datetime's sql wire is the stored shape with a space, on the stored clock, '' for none", '2026-01-02 03:04:05' === Minn_Admin_Kernel_Fields::encode( $sql + array( 'stored' => 'site' ), '2026-01-02 03:04:05' ) && '2026-01-02 03:04:05' === Minn_Admin_Kernel_Fields::encode( $sql + array( 'stored' => 'utc' ), '2026-01-02T03:04:05' ) && gmdate( 'Y-m-d H:i:s', 1767323045 ) === Minn_Admin_Kernel_Fields::encode( $sql + array( 'stored' => 'local-epoch' ), 1767323045 ) && '' === Minn_Admin_Kernel_Fields::encode( $sql + array( 'stored' => 'site' ), '' ) );
$noticed = array();
set_error_handler( function ( $no, $str ) use ( &$noticed ) {
	$noticed[] = $str;
	return true;
} );
$rr = new WP_REST_Request( 'GET', '/x' );
$rr->set_query_params( array( 'after' => array( 'x' ), 'before' => '2026-01-02 00:00:00' ) );
$range = minn_admin_chart_range_clause( $rr, 'created', 'local' );
restore_error_handler();
$check( 'a date bound that is not text is no bound, and no notice', ! $noticed && array( 'created <= %s' ) === $range[0], wp_json_encode( array( $noticed, $range ) ) );
$bounds = function_exists( 'minn_admin_chart_bound' ) ? array_map( 'minn_admin_chart_bound', array( "1' OR 1=1", 'yesterday', '2026-02-30', '2026-01-02T25:00:00', '2026-01-02', '2026-01-02T03:04:05', ' 2026-01-02 03:04:05 ' ) ) : array();
$rr->set_query_params( array( 'after' => "1' OR 1=1", 'before' => 'yesterday' ) );
$junk = minn_admin_chart_range_clause( $rr, 'created', 'utc' );
$check( "a date bound is the chart's own shape or none: words, impossible dates and quotes are never guessed", array( '', '', '', '', '2026-01-02 00:00:00', '2026-01-02 03:04:05', '2026-01-02 03:04:05' ) === $bounds && array() === $junk[0], wp_json_encode( array( $bounds, $junk ) ) );

// --- 1e. Redirect shapes: create, edits on the item route, sums, writes ---
$rd = $wpdb->prefix . 'minn_conf_redir';
$wpdb->query( "CREATE TEMPORARY TABLE {$rd} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, owner BIGINT NOT NULL, src VARCHAR(60) NOT NULL, dst VARCHAR(60) NULL, status VARCHAR(20) NOT NULL, hits INT NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
foreach ( array( array( 10, '/a', '/x', '301', 5 ), array( 10, '/b', '/y', '302', 7 ), array( 10, '/c', '/z', '301', 0 ), array( 20, '/d', '/w', '301', 100 ) ) as $r ) {
	$wpdb->insert( $rd, array( 'owner' => $r[0], 'src' => $r[1], 'dst' => $r[2], 'status' => $r[3], 'hits' => $r[4] ) );
}
$rd_ids     = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$rd} ORDER BY id" ) ); // phpcs:ignore
$rd_manage  = true;
$rd_answer  = null;
$rd_writes  = array();
$rd_kernel  = method_exists( 'Minn_Admin_Kernel', 'handle_create' );
$rd_write   = function ( $what, ...$args ) {
	try {
		return call_user_func_array( array( 'Minn_Admin_Kernel_SQL', $what ), $args );
	} catch ( \Throwable $e ) {
		return 'threw';
	}
};
Minn_Admin_Kernel::resource( 'minn-conformance/redir', array(
	'gates'   => array(
		'view'   => '__return_true',
		'manage' => function () use ( &$rd_manage ) {
			return $rd_manage;
		},
	),
	'source'  => array( 'table' => 'minn_conf_redir', 'key' => 'id', 'columns' => array( 'id', 'src', 'dst', 'status', 'hits' ), 'where' => array( 'owner' => 10 ) ),
	'list'    => array( 'order' => array( 'column' => 'id' ), 'fields' => array( 'id' => 'int', 'src' => 'text' ) ),
	'create'  => array(
		'gate'  => 'manage',
		'run'   => function ( WP_REST_Request $r ) use ( &$rd_answer, $rd_write ) {
			if ( null !== $rd_answer ) {
				return $rd_answer;
			}
			if ( '' === (string) $r['src'] ) {
				return new WP_Error( 'invalid', 'Source needed.', array( 'status' => 400 ) );
			}
			return $rd_write( 'insert', 'minn_conf_redir', array( 'owner' => 10, 'src' => (string) $r['src'], 'dst' => (string) $r['dst'], 'status' => '301', 'hits' => 0 ) );
		},
		'reply' => function ( $id, $fresh ) {
			return array( 'src' => $fresh['src'] );
		},
	),
	'actions' => array(
		'edit'  => array(
			'method' => 'PUT',
			'gate'   => 'manage',
			'run'    => function ( $id, array $row, WP_REST_Request $r ) use ( $rd_write, &$rd_writes ) {
				$dst         = (string) $r['dst'];
				$rd_writes[] = "edit:{$id}";
				// A vendor whose save rewrites the value the form sent ("!" drops).
				$rd_write( 'update', 'minn_conf_redir', 'id', $id, array( 'dst' => str_replace( '!', '', $dst ) ), array( 'owner' => 10 ) );
				return $dst;
			},
			'expect' => function ( $id, $fresh, $dst ) {
				return is_array( $fresh ) && $dst === $fresh['dst'];
			},
		),
		'touch' => array(
			'item'   => true,
			'gate'   => 'manage',
			'run'    => function ( $id ) use ( &$rd_writes ) {
				$rd_writes[] = "touch:{$id}";
				return true;
			},
			'expect' => function () {
				return true;
			},
		),
		'flag'  => array( 'gate' => 'manage', 'run' => function ( $id, $row, WP_REST_Request $r ) use ( &$rd_writes ) {
			$rd_writes[] = 'flag:' . gettype( $r['level'] ) . ':' . wp_json_encode( $r['level'] );
			return true;
		}, 'args' => array( 'level' => array( 'required' => true, 'type' => 'integer' ) ), 'expect' => function () {
			return true;
		} ),
	),
) );
// Shapes the kernel refuses: a create it cannot prove or scope, actions it
// cannot route once, args WordPress would run before the gate.
Minn_Admin_Kernel::resource( 'minn-conformance/redir-scoped', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_redir', 'key' => 'id', 'columns' => array( 'id', 'owner' ) ),
	'list'   => array( 'scope' => array( 'column' => 'owner', 'allowed' => function () {
		return array( 20 );
	} ), 'fields' => array( 'id' => 'int' ) ),
	'create' => array( 'gate' => 'view', 'run' => '__return_true' ),
) );
Minn_Admin_Kernel::resource( 'minn-conformance/redir-rows', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'rows' => '__return_empty_array', 'get' => '__return_null' ),
	'list'   => array( 'fields' => array( 'id' => 'int' ) ),
	'create' => array( 'gate' => 'view', 'run' => '__return_true' ),
) );
$rd_odd = array();
$rd_yes = function () {
	return true;
};
Minn_Admin_Kernel::resource( 'minn-conformance/redir-odd', array(
	'gates'   => array( 'view' => '__return_true' ),
	'source'  => array( 'table' => 'minn_conf_redir', 'key' => 'id', 'columns' => array( 'id' ) ),
	'actions' => array(
		'first'  => array( 'item' => true, 'gate' => 'view', 'run' => function () use ( &$rd_odd ) {
			$rd_odd[] = 'first';
			return true;
		}, 'expect' => $rd_yes ),
		'second' => array( 'item' => true, 'gate' => 'view', 'run' => function () use ( &$rd_odd ) {
			$rd_odd[] = 'second';
			return true;
		}, 'expect' => $rd_yes ),
		'peek'   => array( 'item' => true, 'method' => 'GET', 'gate' => 'view', 'run' => '__return_true', 'expect' => $rd_yes ),
		'both'   => array( 'method' => 'DELETE,GET', 'gate' => 'view', 'run' => '__return_true', 'expect' => $rd_yes ),
		'Bad Slug' => array( 'gate' => 'view', 'run' => '__return_true', 'expect' => $rd_yes ),
		'early'  => array( 'gate' => 'view', 'run' => '__return_true', 'expect' => $rd_yes, 'args' => array( 'x' => array( 'type' => 'string', 'validate_callback' => '__return_true' ) ) ),
		'flat'   => array( 'gate' => 'view', 'run' => '__return_true', 'expect' => $rd_yes, 'args' => 'x' ),
		'idarg'  => array( 'gate' => 'view', 'run' => '__return_true', 'expect' => $rd_yes, 'args' => array( 'id' => array( 'type' => 'integer' ) ) ),
	),
) );
Minn_Admin_Kernel::resource( 'minn-conformance/redir-nogate', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_redir', 'key' => 'id', 'columns' => array( 'id' ) ),
	'list'   => array( 'fields' => array( 'id' => 'int' ) ),
	'create' => array( 'gate' => 'undeclared', 'run' => '__return_true' ),
) );
$rd_base = '/minn-admin/v1/minn-conformance/redir';
list( $st, $d ) = $call( 'POST', $rd_base, array(), array( 'src' => '/new', 'dst' => '/there' ) );
$made           = (int) ( $d['id'] ?? 0 );
$check( 'a create answers on the collection route, the new row read back, its reply over { ok, id }', 200 === $st && true === ( $d['ok'] ?? null ) && $made > 0 && '/new' === ( $d['src'] ?? '' ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rd} WHERE id = %d AND src = '/new'", $made ) ), $st . ' ' . wp_json_encode( $d ) ); // phpcs:ignore
$rd_manage   = false;
list( $st )  = $call( 'POST', $rd_base, array(), array( 'src' => '/refused' ) );
$rd_manage   = true;
list( $st2 ) = $call( 'POST', '/minn-admin/v1/minn-conformance/redir-nogate', array(), array() );
$check( 'a create answers to its gate, and one naming no declared gate has no route', 403 === $st && 404 === $st2 && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rd} WHERE src = '/refused'" ), $st . ' ' . $st2 ); // phpcs:ignore
$rd_fail = array();
foreach ( array( 0, 'abc', $rd_ids[3], 999999, true, 1.0, '1abc', ' 1', array( 'id' => true ), new WP_REST_Response( array( 'id' => $rd_ids[0] ) ), $rd_ids[0], new WP_Error( 'nope', 'No.', array( 'status' => 409 ) ) ) as $answer ) {
	$rd_answer      = $answer;
	list( $st, $d ) = $call( 'POST', $rd_base, array(), array( 'src' => '/x' ) );
	$rd_fail[]      = $st . ':' . ( $d['code'] ?? '' );
}
$rd_answer = null;
$check( "a create is done only when a new row reads back: no odd answer becomes an id, an old row proves nothing, its own error passes as it is", array_merge( array_fill( 0, 11, '500:not_applied' ), array( '409:nope' ) ) === $rd_fail, wp_json_encode( $rd_fail ) );
list( $st )  = $call( 'POST', '/minn-admin/v1/minn-conformance/redir-scoped', array(), array() );
list( $st2 ) = $call( 'POST', '/minn-admin/v1/minn-conformance/redir-rows', array(), array() );
$check( 'a create has no route beside a scope it cannot ask before its row exists, nor over a source that is no table without its own proof', 404 === $st && 404 === $st2, $st . ' ' . $st2 );
list( $st, $d ) = $call( 'POST', $rd_base, array(), array( 'src' => str_repeat( 'x', 61 ) ) );
$check( 'a value longer than its column is no create, and no cut row is left', 500 === $st && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rd} WHERE src LIKE 'xxxxxxxxxx%'" ), $st . ' ' . wp_json_encode( $d ) ); // phpcs:ignore
list( $st )  = $call( 'POST', $rd_base, array(), array( 'src' => '' ) );
$check( "a create's own refusal answers with its status", 400 === $st, (string) $st );
$rd_writes      = array();
list( $st, $d ) = $call( 'PUT', $rd_base . '/' . $rd_ids[0], array(), array( 'dst' => '/moved' ) );
list( $st2 )    = $call( 'POST', $rd_base . '/' . $rd_ids[0], array(), array() );
list( $st3 )    = $call( 'POST', $rd_base . '/' . $rd_ids[0] . '/flag', array(), array( 'level' => 2 ) );
$check( 'PUT and a POST that says item answer on the item route, each its own action; a plain POST keeps its path', 200 === $st && 200 === $st2 && 200 === $st3 && array( "edit:{$rd_ids[0]}", "touch:{$rd_ids[0]}", 'flag:integer:2' ) === $rd_writes && '/moved' === $wpdb->get_var( $wpdb->prepare( "SELECT dst FROM {$rd} WHERE id = %d", $rd_ids[0] ) ), $st . ' ' . $st2 . ' ' . $st3 . ' ' . wp_json_encode( $rd_writes ) ); // phpcs:ignore
list( $st, $d ) = $call( 'PUT', $rd_base . '/' . $rd_ids[0], array(), array( 'dst' => '/moved!' ) );
list( $st4 )    = $call( 'PUT', $rd_base . '/' . $rd_ids[3], array(), array( 'dst' => '/nope' ) );
$check( "an edit whose stored row does not read as sent is no success; another owner's row is not found", 500 === $st && 'not_applied' === ( $d['code'] ?? '' ) && 404 === $st4 && '/w' === $wpdb->get_var( $wpdb->prepare( "SELECT dst FROM {$rd} WHERE id = %d", $rd_ids[3] ) ), $st . ' ' . $st4 ); // phpcs:ignore
list( $st, $d )   = $call( 'POST', $rd_base . '/' . $rd_ids[0] . '/flag', array(), array() );
list( $st2, $d2 ) = $call( 'POST', $rd_base . '/' . $rd_ids[0] . '/flag', array(), array( 'level' => 'many' ) );
$rd_manage        = false;
list( $st3 )      = $call( 'POST', $rd_base . '/' . $rd_ids[0] . '/flag', array(), array() );
$rd_manage        = true;
$rd_writes        = array();
$call( 'POST', $rd_base . '/' . $rd_ids[0] . '/flag', array(), array( 'level' => '3' ) );
$check( "an action's arguments are checked after its gate, missing and invalid apart, and reach it as their type", 400 === $st && 'rest_missing_callback_param' === ( $d['code'] ?? '' ) && 400 === $st2 && 'rest_invalid_param' === ( $d2['code'] ?? '' ) && 403 === $st3 && array( 'flag:integer:3' ) === $rd_writes, $st . ' ' . $st2 . ' ' . $st3 . ' ' . wp_json_encode( $rd_writes ) );
$odd_base = '/minn-admin/v1/minn-conformance/redir-odd/' . $rd_ids[0];
list( $st )  = $call( 'POST', $odd_base );
list( $st2 ) = $call( 'GET', $odd_base );
list( $st3 ) = $call( 'GET', $odd_base . '/both' );
list( $st4 ) = $call( 'POST', $odd_base . '/early', array(), array( 'x' => 'y' ) );
list( $st5 ) = $call( 'POST', $odd_base . '/flat' );
list( $st6 ) = $call( 'POST', $odd_base . '/idarg' );
$check( 'one action per method on a route, never a GET, a slug as written, args without callbacks or an id: anything else has no route', 200 === $st && array( 'first' ) === $rd_odd && 404 === $st2 && 404 === $st3 && 404 === $st4 && 404 === $st5 && 404 === $st6, implode( ' ', array( $st, $st2, $st3, $st4, $st5, $st6 ) ) . ' ' . wp_json_encode( $rd_odd ) );
$paths = $rd_kernel ? array( Minn_Admin_Kernel::action_path( 'minn-conformance/redir', 'edit' ), Minn_Admin_Kernel::action_path( 'minn-conformance/redir', 'touch' ), Minn_Admin_Kernel::action_path( 'minn-conformance/redir', 'flag' ) ) : array();
$check( "a descriptor's route for an action is where it answers", array( 'minn-admin/v1/minn-conformance/redir/{id}', 'minn-admin/v1/minn-conformance/redir/{id}', 'minn-admin/v1/minn-conformance/redir/{id}/flag' ) === $paths && ( $rd_kernel && 'minn-admin/v1/minn-conformance/redir/{id}' === Minn_Admin_Kernel::ui( 'minn-conformance/redir', 'edit', array() )['route'] ), wp_json_encode( $paths ) );
$sum = $rd_kernel ? Minn_Admin_Kernel::sum( 'minn-conformance/redir', 'hits' ) : -1;
$check( "a card's sum counts only the rows the caller may see", 12 === $sum && 112 === $rd_write( 'sum', 'minn_conf_redir', 'hits' ) && 0 === $rd_write( 'sum', 'minn_conf_no_table', 'hits' ), $sum . ' ' . wp_json_encode( $rd_write( 'sum', 'minn_conf_redir', 'hits' ) ) );
$counts = $rd_write( 'counts', 'minn_conf_redir', 'status', array( 'owner' => 10 ), 5 );
$check( "counts are a column's values most first, ties by value; top is the first of them", is_array( $counts ) && array( '301', '302' ) === array_column( $counts, 'value' ) && array( 3, 1 ) === array_column( $counts, 'count' ) && Minn_Admin_Kernel_SQL::top( 'minn_conf_redir', 'status', array( 'owner' => 10 ) ) === $counts[0], wp_json_encode( $counts ) );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rd}" ); // phpcs:ignore
$bad    = array(
	$rd_write( 'update', 'minn_conf_redir', 'id', array(), array( 'dst' => '/all' ) ),
	$rd_write( 'update', 'minn_conf_redir', 'id', 0, array( 'dst' => '/all' ) ),
	$rd_write( 'update', 'minn_conf_redir', 'id', array( $rd_ids[1], -1 ), array( 'dst' => '/all' ) ),
	$rd_write( 'update', 'minn_conf_redir', 'id', $rd_ids[1], array() ),
	$rd_write( 'update', 'minn_conf_redir', 'id', $rd_ids[1], array( 'dst' => array( 'x' ) ) ),
	$rd_write( 'insert', 'minn_conf_redir', array() ),
	$rd_write( 'insert', 'minn_conf_redir', array( 'owner' => 10, 'src' => new stdClass(), 'status' => '301', 'hits' => 0 ) ),
);
$check( 'an update names its rows by key, each above zero, and a write needs scalar values; anything else is refused', method_exists( 'Minn_Admin_Kernel_SQL', 'update' ) && array_fill( 0, 7, 'threw' ) === $bad && $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rd}" ) && 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rd} WHERE dst = '/all'" ), wp_json_encode( $bad ) ); // phpcs:ignore
$n = $rd_write( 'update', 'minn_conf_redir', 'id', array( $rd_ids[1], $rd_ids[3] ), array( 'dst' => null ), array( 'owner' => 10 ) );
$core = array(
	$rd_write( 'insert', array( 'core' => 'options' ), array( 'option_name' => 'minn_conf_x', 'option_value' => 'x' ) ),
	$rd_write( 'insert', 'options', array( 'option_name' => 'minn_conf_x', 'option_value' => 'x' ) ),
	$rd_write( 'update', 'options', 'option_id', 999999999, array( 'option_value' => 'x' ) ),
	$rd_write( 'delete', 'usermeta', 'umeta_id', 999999999 ),
	$rd_write( 'update', 'minn_conf_redir', 'id', $rd_ids[2], array( 'id' => 999 ) ),
);
$check( "the write primitives never touch WordPress's own tables, nor write the key that names their rows", array_fill( 0, 5, 'threw' ) === $core && null === get_option( 'minn_conf_x', null ), wp_json_encode( $core ) );
// A kernel without the refusal would have written it: never leave it behind.
$wpdb->delete( $wpdb->options, array( 'option_name' => 'minn_conf_x' ) );
wp_cache_delete( 'minn_conf_x', 'options' );
wp_cache_delete( 'alloptions', 'options' );
$fit = array(
	$rd_write( 'update', 'minn_conf_redir', 'id', $rd_ids[2], array( 'src' => null ) ),
	$rd_write( 'update', 'minn_conf_redir', 'id', $rd_ids[2], array( 'hits' => 'abc' ) ),
	$rd_write( 'update', 'minn_conf_redir', 'id', $rd_ids[2], array( 'nope' => 'x' ) ),
	$rd_write( 'insert', 'minn_conf_redir', array( 'owner' => 10, 'src' => str_repeat( 'y', 61 ), 'status' => '301', 'hits' => 0 ) ),
);
$c_row = $wpdb->get_row( $wpdb->prepare( "SELECT src, hits FROM {$rd} WHERE id = %d", $rd_ids[2] ), ARRAY_A ); // phpcs:ignore
$check( 'a value that does not fit its column as it is (NULL in NOT NULL, text in a number, an unknown column, too long) writes nothing', array( 0, 0, 0, 0 ) === $fit && '/c' === $c_row['src'] && '0' === (string) $c_row['hits'], wp_json_encode( array( $fit, $c_row ) ) );
$check( "an update's further conditions only narrow it, and NULL writes NULL", 1 === $n && null === $wpdb->get_var( $wpdb->prepare( "SELECT dst FROM {$rd} WHERE id = %d", $rd_ids[1] ) ) && '/w' === $wpdb->get_var( $wpdb->prepare( "SELECT dst FROM {$rd} WHERE id = %d", $rd_ids[3] ) ), wp_json_encode( $n ) ); // phpcs:ignore
$check( 'an insert into a table that is not there writes nothing', 0 === $rd_write( 'insert', 'minn_conf_no_table', array( 'x' => 1 ) ) );
$db_now = $wpdb->get_var( 'SELECT NOW()' );
$db_iso = Minn_Admin_Kernel_Fields::datetime( $db_now, 'db', 'iso-utc' );
$winter = '2026-01-15 12:00:00';
$w_iso  = Minn_Admin_Kernel_Fields::datetime( $winter, 'db', 'iso-utc' );
$w_want = gmdate( 'c', (int) $wpdb->get_var( $wpdb->prepare( 'SELECT UNIX_TIMESTAMP(%s)', $winter ) ) );
$check( "a datetime on the database's own clock reads as the instant the database reads, on that date's offset; a never-set one, or none at all, as none", '' !== $db_iso && abs( strtotime( $db_iso ) - time() ) <= 120 && $w_want === $w_iso && '' === Minn_Admin_Kernel_Fields::datetime( '1970-01-01 00:00:00', 'db', 'iso-utc' ) && '' === Minn_Admin_Kernel_Fields::datetime( 'now', 'db', 'iso-utc' ) && '' === Minn_Admin_Kernel_Fields::datetime( '2026-02-30 00:00:00', 'db', 'iso-utc' ), $db_now . ' → ' . $db_iso . '; ' . $winter . ' → ' . $w_iso . ' want ' . $w_want );

// --- 1f. Gift card shapes: several statuses at once, groups, money sums -----
$gc = $wpdb->prefix . 'minn_conf_cards';
$wpdb->query( "CREATE TEMPORARY TABLE {$gc} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, owner BIGINT NOT NULL, active TINYINT NOT NULL, expires BIGINT NOT NULL, amount DECIMAL(15,6) NOT NULL, kind VARCHAR(20) NOT NULL, note VARCHAR(20) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$gc_now  = time();
$gc_seed = array(
	array( 1, 10, 1, 0, '10.004000', 'a', '5.005' ),
	array( 2, 10, 1, 1000, '5.005000', 'b', '0.125' ),
	array( 3, 10, 0, 0, '2.500000', 'a', '2.5' ),
	array( 4, 10, 1, $gc_now + 30 * DAY_IN_SECONDS, '1.115000', 'b', '1.000' ),
	array( 5, 20, 1, 0, '99.000000', 'a', '99' ),
	// In none of the declared views: never listed, whatever is asked.
	array( 6, 10, 2, 0, '0.000000', 'a', '0' ),
);
foreach ( $gc_seed as $s ) {
	$wpdb->insert( $gc, array( 'id' => $s[0], 'owner' => $s[1], 'active' => $s[2], 'expires' => $s[3], 'amount' => $s[4], 'kind' => $s[5], 'note' => $s[6] ) );
}
$gc_values = array(
	'active'   => function () {
		return array( 'active' => 1, 'expires' => array( 'any' => array( 0, array( 'op' => '>', 'value' => time() ) ) ) );
	},
	'expired'  => function () {
		return array( 'active' => 1, 'expires' => array( 'op' => '!=', 'value' => 0 ), "expires\0to" => array( 'op' => '<=', 'value' => time() ) );
	},
	'disabled' => array( 'active' => 0 ),
	'broken'   => function () {
		return 'not conditions';
	},
);
$gc_rows = function () use ( $gc_seed ) {
	$out = array();
	foreach ( $gc_seed as $s ) {
		if ( 10 === $s[1] ) {
			$out[] = array( 'id' => $s[0], 'active' => $s[2], 'expires' => $s[3], 'amount' => $s[4], 'kind' => $s[5], 'note' => $s[6] );
		}
	}
	return $out;
};
$gc_seen = null;
Minn_Admin_Kernel::resource( 'minn-conformance/cards', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_cards', 'key' => 'id', 'columns' => array( 'id', 'active', 'expires', 'amount', 'kind' ), 'where' => array( 'owner' => 10 ) ),
	'list'   => array( 'filter' => array( 'param' => 'status', 'many' => true, 'values' => $gc_values ), 'facets' => array( array( 'param' => 'kind', 'column' => 'kind', 'type' => 'key' ) ), 'order' => array( 'column' => 'id', 'dir' => 'ASC' ), 'fields' => array( 'id' => 'int' ) ),
) );
Minn_Admin_Kernel::resource( 'minn-conformance/cards-rows', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'rows' => $gc_rows ),
	'list'   => array( 'filter' => array( 'param' => 'status', 'many' => true, 'values' => $gc_values ), 'fields' => array( 'id' => 'int' ) ),
) );
Minn_Admin_Kernel::resource( 'minn-conformance/cards-kinds', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array(
		// Reads only the values asked for, never $q['where']: a value it was
		// not asked for, or none it knows, must still never list everything.
		'list' => function ( array $q ) use ( $gc_rows, &$gc_seen ) {
			$gc_seen = $q['filter'];
			$rows    = array_values( array_filter( $gc_rows(), function ( $row ) use ( $q ) {
				return ! $q['filter'] || in_array( $row['kind'], $q['filter'], true );
			} ) );
			return array( $rows, count( $rows ) );
		},
		'get'  => function () {
			return null;
		},
	),
	'list'   => array( 'filter' => array( 'param' => 'kind', 'column' => 'kind', 'many' => true, 'values' => array( 'a', 'b' ) ), 'fields' => array( 'id' => 'int' ) ),
) );
$gc_ids = function ( $route, $params ) use ( $call ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/' . $route, $params );
	return 200 === $st ? array_column( (array) ( $d['items'] ?? array() ), 'id' ) : $st;
};
$gc_cases = array(
	array( array(), array( 1, 2, 3, 4 ) ),
	array( array( 'status' => 'any' ), array( 1, 2, 3, 4 ) ),
	array( array( 'status' => 'active' ), array( 1, 4 ) ),
	array( array( 'status' => array( 'active', 'disabled' ) ), array( 1, 3, 4 ) ),
	array( array( 'status' => array( 'expired', 'any' ) ), array( 2 ) ),
	array( array( 'status' => array( 'active', 'nope' ) ), array( 1, 4 ) ),
	array( array( 'status' => 'nope' ), array() ),
	array( array( 'status' => array( 'nope' ) ), array() ),
	array( array( 'status' => array( array( 'active' ) ) ), array() ),
	array( array( 'status' => array( 'broken' ) ), array() ),
	array( array( 'status' => array( 'broken', 'disabled' ) ), array( 3 ) ),
);
$gc_got = array();
$gc_ok  = true;
foreach ( $gc_cases as $c ) {
	$sql_ids       = $gc_ids( 'cards', $c[0] );
	$row_ids       = $gc_ids( 'cards-rows', $c[0] );
	$gc_got[]      = array( $c[0], $sql_ids, $row_ids );
	$gc_ok         = $gc_ok && $c[1] === $sql_ids && $c[1] === $row_ids;
}
$check( "a filter that takes several values lists the rows in any of them (status[]=a&status[]=b), every row for none or 'any', and nothing for a value it does not know, one that is not text or one whose conditions cannot be said, in SQL and in PHP alike", $gc_ok, wp_json_encode( $gc_got ) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/cards', array( 'status' => array( 'active', 'disabled' ), 'per_page' => 2 ) );
$check( "several values page and count as one list", 200 === $st && 3 === ( $d['total'] ?? 0 ) && array( 1, 3 ) === array_column( (array) ( $d['items'] ?? array() ), 'id' ), $st . ' ' . wp_json_encode( $d ) );
$k_ids   = $gc_ids( 'cards-kinds', array( 'kind' => array( 'b', 'nope' ) ) );
$k_seen  = $gc_seen;
$gc_seen = 'not asked';
$k_none  = $gc_ids( 'cards-kinds', array( 'kind' => array( 'nope', array( 'b' ) ) ) );
$check( "a callable source reads the values asked for that it knows in \$q['filter'], and is never asked at all when none is known", array( 2, 4 ) === $k_ids && array( 'b' ) === $k_seen && array() === $k_none && 'not asked' === $gc_seen, wp_json_encode( array( $k_ids, $k_seen, $k_none, $gc_seen ) ) );
$f_ids = $gc_ids( 'cards', array( 'status' => array( 'active', 'disabled' ), 'kind' => 'b' ) );
$check( 'several values narrow alongside a facet, each still applying', array( 4 ) === $f_ids, wp_json_encode( $f_ids ) );
$g_rows  = $gc_rows();
$g_where = array(
	'#any' => array(
		array( 'active' => 0 ),
		array( 'kind' => 'b', '#any' => array( array( 'expires' => 1000 ), array( 'expires' => array( 'op' => '>', 'value' => $gc_now ) ) ) ),
	),
);
$g_sql = array_map( 'intval', array_column( Minn_Admin_Kernel_SQL::select( 'minn_conf_cards', array( 'id' ), array_merge( array( 'owner' => 10 ), $g_where ), array( 'id' => 'ASC' ) ), 'id' ) );
$g_php = array_values( array_map( function ( $r ) {
	return $r['id'];
}, array_filter( $g_rows, function ( $r ) use ( $g_where ) {
	return Minn_Admin_Kernel_SQL::matches( $r, $g_where );
} ) ) );
$check( "'#any' holds a row that meets any of its groups, each an AND of conditions that may hold its own '#any', alike in SQL and PHP", array( 2, 3, 4 ) === $g_sql && array( 2, 3, 4 ) === $g_php, wp_json_encode( array( $g_sql, $g_php ) ) );
$g_none = Minn_Admin_Kernel_SQL::select( 'minn_conf_cards', array( 'id' ), array( 'owner' => 10, '#any' => array() ) );
$g_all  = Minn_Admin_Kernel_SQL::select( 'minn_conf_cards', array( 'id' ), array( 'owner' => 10, "#any\0x" => array( array() ) ) );
$check( "an empty '#any' matches nothing, and a group with no conditions every row", array() === $g_none && 5 === count( $g_all ) && ! Minn_Admin_Kernel_SQL::matches( $g_rows[0], array( '#any' => array() ) ) && Minn_Admin_Kernel_SQL::matches( $g_rows[0], array( '#any' => array( array() ) ) ), wp_json_encode( array( $g_none, count( $g_all ) ) ) );
$g_bad = array();
foreach ( array( 'x', array( array( 1, 2 ) ), array( 'a' => array( 'active' => 1 ) ), array( 'nope' ) ) as $bad ) {
	foreach ( array( 'select', 'matches' ) as $how ) {
		try {
			'select' === $how ? Minn_Admin_Kernel_SQL::select( 'minn_conf_cards', array( 'id' ), array( '#any' => $bad ) ) : Minn_Admin_Kernel_SQL::matches( $g_rows[0], array( '#any' => $bad ) );
			$g_bad[] = 'ran';
		} catch ( InvalidArgumentException $e ) {
			$g_bad[] = 'minn_kernel_rule=#any' === $e->getMessage() ? 'refused' : $e->getMessage();
		}
	}
}
$check( "an '#any' that is not a list of condition groups is refused in SQL and in PHP", array_fill( 0, 8, 'refused' ) === $g_bad, wp_json_encode( $g_bad ) );
$s_money = Minn_Admin_Kernel::sum( 'minn-conformance/cards', 'amount', array(), 2 );
$s_whole = Minn_Admin_Kernel::sum( 'minn-conformance/cards', 'amount' );
$s_where = Minn_Admin_Kernel_SQL::sum( 'minn_conf_cards', 'amount', array( 'owner' => 10, 'active' => 1 ), 2 );
$s_gone  = Minn_Admin_Kernel_SQL::sum( 'minn_conf_no_table', 'amount', array(), 2 );
$s_text  = Minn_Admin_Kernel_SQL::sum( 'minn_conf_cards', 'note', array( 'owner' => 10 ), 2 );
$check( "a money sum rounds each row to the store's decimals before adding them (10.00 + 5.01 + 2.50 + 1.12), held to the source's conditions; a whole sum stays a whole number", is_float( $s_money ) && abs( $s_money - 18.63 ) < 0.0001 && 19 === $s_whole && abs( $s_where - 16.13 ) < 0.0001 && 0.0 === $s_gone, wp_json_encode( array( $s_money, $s_whole, $s_where, $s_gone ) ) );
$check( 'a money sum over a text column rounds each value as an exact decimal (5.005 to 5.01, 0.125 to 0.13), as PHP and the store do', abs( $s_text - 8.64 ) < 0.0001, wp_json_encode( $s_text ) );

// --- 1f2. A sort a person picks: declared columns only ---------------------
$so_seen  = 'not asked';
$so_wrong = array();
add_action( 'doing_it_wrong_run', function ( $fn, $msg ) use ( &$so_wrong ) {
	if ( false !== strpos( (string) $msg, 'minn-conformance/cards-' ) && false !== strpos( (string) $msg, 'sort' ) ) {
		$so_wrong[] = (string) $msg;
	}
}, 10, 2 );
// A second table to join (a temporary table cannot be opened twice in one query).
$gc2 = $wpdb->prefix . 'minn_conf_cards2';
$wpdb->query( "CREATE TEMPORARY TABLE {$gc2} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, amount DECIMAL(15,6) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
foreach ( $gc_seed as $s ) {
	$wpdb->insert( $gc2, array( 'id' => $s[0], 'amount' => $s[4] ) );
}
$so_list = array( 'sort' => array( 'columns' => array( 'amount', 'kind', 'label' => 'note', 'joined' => 'o.amount' ) ), 'fields' => array( 'id' => 'int' ) );
Minn_Admin_Kernel::resource( 'minn-conformance/cards-sorted', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array(
		'table'   => 'minn_conf_cards',
		'key'     => 'id',
		'columns' => array( 'id', 'amount', 'kind', 'note' ),
		'joins'   => array( 'o' => array( 'table' => 'minn_conf_cards2', 'on' => array( 'id' => 'e.id' ) ) ),
		'where'   => array( 'owner' => 10 ),
	),
	'list'   => array_merge( $so_list, array(
		'order'  => array( 'column' => 'id', 'dir' => 'ASC' ),
		'filter' => array( 'param' => 'view', 'values' => array( 'all' => array(), 'rev' => array() ), 'order' => array( 'rev' => 'DESC' ) ),
	) ),
) );
Minn_Admin_Kernel::resource( 'minn-conformance/cards-sorted-rows', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'rows' => $gc_rows ),
	'list'   => $so_list,
) );
Minn_Admin_Kernel::resource( 'minn-conformance/cards-sorted-call', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array(
		'list' => function ( array $q ) use ( $gc_rows, &$so_seen ) {
			$so_seen = $q['sort'] ?? null;
			$rows    = $gc_rows();
			return array( $rows, count( $rows ) );
		},
		'get'  => function () {
			return null;
		},
	),
	'list'   => array( 'sort' => array( 'columns' => array( 'amount', 'label' => 'note' ) ), 'facets' => array( array( 'param' => 'sortby', 'column' => 'kind', 'type' => 'key' ) ), 'fields' => array( 'id' => 'int' ) ),
) );
$so_bad_base = array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_cards', 'key' => 'id', 'columns' => array( 'id', 'amount' ), 'where' => array( 'owner' => 10 ) ),
);
foreach ( array(
	'cards-badsort'   => array( 'columns' => array( 'label' => 'note; DROP TABLE x' ) ),
	'cards-notmine'   => array( 'columns' => array( 'label' => 'note' ) ),
	'cards-noalias'   => array( 'columns' => array( 'other' => 'x.amount' ) ),
	'cards-takenkey'  => array( 'columns' => array( 'amount' ) ),
) as $so_id => $so_sort ) {
	// A facet that asks for the sort's own request key.
	$so_facets = 'cards-takenkey' === $so_id ? array( array( 'param' => 'order', 'values' => array( 'oldest' => array( 'kind' => 'x' ) ) ) ) : array();
	Minn_Admin_Kernel::resource( 'minn-conformance/' . $so_id, $so_bad_base + array( 'list' => array( 'order' => array( 'column' => 'id', 'dir' => 'ASC' ), 'sort' => $so_sort, 'facets' => $so_facets, 'fields' => array( 'id' => 'int' ) ) ) );
}
$so_cases = array(
	array( array(), array( 1, 2, 3, 4, 6 ), array( 1, 2, 3, 4, 6 ) ),
	array( array( 'orderby' => 'amount', 'order' => 'asc' ), array( 6, 4, 3, 2, 1 ), array( 6, 4, 3, 2, 1 ) ),
	array( array( 'orderby' => 'label', 'order' => 'asc' ), array( 6, 2, 4, 3, 1 ), array( 6, 2, 4, 3, 1 ) ),
	array( array( 'orderby' => 'label', 'order' => 'desc' ), array( 1, 3, 4, 2, 6 ), array( 1, 3, 4, 2, 6 ) ),
	array( array( 'orderby' => 'label' ), array( 1, 3, 4, 2, 6 ), array( 1, 3, 4, 2, 6 ) ),
	array( array( 'orderby' => 'label', 'order' => 'sideways' ), array( 1, 3, 4, 2, 6 ), array( 1, 3, 4, 2, 6 ) ),
	array( array( 'orderby' => 'joined', 'order' => 'asc' ), array( 6, 4, 3, 2, 1 ), array( 6, 4, 3, 2, 1 ) ),
	array( array( 'orderby' => 'kind', 'order' => 'asc' ), array( 1, 3, 6, 2, 4 ), array( 1, 3, 6, 2, 4 ) ),
	array( array( 'orderby' => 'note', 'order' => 'asc' ), array( 1, 2, 3, 4, 6 ), array( 1, 2, 3, 4, 6 ) ),
	array( array( 'orderby' => 'id', 'order' => 'desc' ), array( 1, 2, 3, 4, 6 ), array( 1, 2, 3, 4, 6 ) ),
	array( array( 'orderby' => 'amount desc, id', 'order' => 'asc' ), array( 1, 2, 3, 4, 6 ), array( 1, 2, 3, 4, 6 ) ),
	array( array( 'orderby' => array( 'amount' ), 'order' => 'asc' ), array( 1, 2, 3, 4, 6 ), array( 1, 2, 3, 4, 6 ) ),
);
$so_got = array();
$so_ok  = true;
foreach ( $so_cases as $c ) {
	$sql_ids  = $gc_ids( 'cards-sorted', $c[0] );
	$row_ids  = $gc_ids( 'cards-sorted-rows', $c[0] );
	$so_got[] = array( $c[0], $sql_ids, $row_ids );
	$so_ok    = $so_ok && $c[1] === $sql_ids && $c[2] === $row_ids;
}
$check( "a list sorts by a declared column a person picks (?orderby=<name>&order=asc|desc, descending unless asc), a public name maps to its column or a joined alias's, and any other value, a column it does not name, or one that is not text keeps the declared order, in SQL and in PHP alike", $so_ok, wp_json_encode( $so_got ) );
$so_tie     = $gc_ids( 'cards-sorted', array( 'orderby' => 'kind', 'order' => 'desc' ) );
$so_tie_php = $gc_ids( 'cards-sorted-rows', array( 'orderby' => 'kind', 'order' => 'desc' ) );
$check( 'rows that tie are ordered by the key in the sort\'s direction in SQL, and keep the order they came in PHP', array( 4, 2, 6, 3, 1 ) === $so_tie && array( 2, 4, 1, 3, 6 ) === $so_tie_php, wp_json_encode( array( $so_tie, $so_tie_php ) ) );
$so_rev    = $gc_ids( 'cards-sorted', array( 'view' => 'rev' ) );
$so_rev_by = $gc_ids( 'cards-sorted', array( 'view' => 'rev', 'orderby' => 'kind', 'order' => 'asc' ) );
$check( "a filter value's own order applies when no sort is picked, and a pick overrides it", array( 6, 4, 3, 2, 1 ) === $so_rev && array( 1, 3, 6, 2, 4 ) === $so_rev_by, wp_json_encode( array( $so_rev, $so_rev_by ) ) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/cards-sorted', array( 'orderby' => 'amount', 'order' => 'asc', 'per_page' => 2, 'page' => 2 ) );
$check( 'a sorted list pages and counts as one list', 200 === $st && 5 === ( $d['total'] ?? 0 ) && array( 3, 2 ) === array_column( (array) ( $d['items'] ?? array() ), 'id' ), $st . ' ' . wp_json_encode( $d ) );
$gc_ids( 'cards-sorted-call', array( 'orderby' => 'label', 'order' => 'asc' ) );
$so_call = $so_seen;
$gc_ids( 'cards-sorted-call', array( 'sortby' => 'amount', 'order' => 'asc' ) );
$so_none = $so_seen;
$check( "a callable source reads the pick in \$q['sort'] (by, its column, dir) under orderby and order, and none under any other key", array( 'by' => 'label', 'column' => 'note', 'dir' => 'ASC' ) === $so_call && null === $so_none, wp_json_encode( array( $so_call, $so_none ) ) );
$so_bad_ids = array();
foreach ( array( 'cards-badsort', 'cards-notmine', 'cards-noalias', 'cards-takenkey' ) as $so_id ) {
	$so_bad_ids[ $so_id ] = array( $gc_ids( $so_id, array( 'orderby' => 'label', 'order' => 'asc' ) ), Minn_Admin_Kernel::collection( 'minn-conformance/' . $so_id )['sortQuery'] ?? null );
}
$so_bad_ok = 4 === count( array_filter( $so_bad_ids, function ( $v ) {
	return array( 1, 2, 3, 4, 6 ) === $v[0] && null === $v[1];
} ) );
$check( "a sort whose column is no identifier, not the table's own, behind an alias it does not join, or whose request key another list parameter uses is dropped with a notice that says why, the list keeping its order and the client given no sortQuery", $so_bad_ok && count( $so_wrong ) >= 4, wp_json_encode( array( $so_bad_ids, $so_wrong ) ) );
$so_coll    = Minn_Admin_Kernel::collection( 'minn-conformance/cards-sorted' );
$so_coll_c  = Minn_Admin_Kernel::collection( 'minn-conformance/cards-sorted-call' );
$so_no_coll = Minn_Admin_Kernel::collection( 'minn-conformance/cards' );
$check( 'collection() hands the client the sortQuery, and none for a list with no sort', 'orderby={by}&order={dir}' === ( $so_coll['sortQuery'] ?? '' ) && 'orderby={by}&order={dir}' === ( $so_coll_c['sortQuery'] ?? '' ) && ! isset( $so_no_coll['sortQuery'] ), wp_json_encode( array( $so_coll['sortQuery'] ?? null, $so_coll_c['sortQuery'] ?? null ) ) );
$so_mixed = array( array( 'id' => 1, 'v' => '1.10' ), array( 'id' => 2, 'v' => '1.5' ), array( 'id' => 3, 'v' => '1.7a' ), array( 'id' => 4, 'v' => '200' ), array( 'id' => 5, 'v' => '1e3' ), array( 'id' => 6, 'v' => '1f' ) );
Minn_Admin_Kernel::resource( 'minn-conformance/cards-mixed', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'rows' => function () use ( $so_mixed ) {
		return $so_mixed;
	} ),
	'list'   => array( 'sort' => array( 'columns' => array( 'v' ) ), 'fields' => array( 'id' => 'int' ) ),
) );
$so_mix_asc  = $gc_ids( 'cards-mixed', array( 'orderby' => 'v', 'order' => 'asc' ) );
$so_mix_desc = $gc_ids( 'cards-mixed', array( 'orderby' => 'v', 'order' => 'desc' ) );
$check( 'an array source sorts numbers before text, numbers by value and text naturally, one order whichever way it runs', array( 1, 2, 4, 5, 3, 6 ) === $so_mix_asc && array( 6, 3, 5, 4, 2, 1 ) === $so_mix_desc, wp_json_encode( array( $so_mix_asc, $so_mix_desc ) ) );

// --- 1g. Traffic shapes: grouped totals, day buckets ------------------------
if ( ! method_exists( 'Minn_Admin_Kernel_SQL', 'totals' ) ) {
	$check( 'the SQL layer reads grouped totals', false, 'no Minn_Admin_Kernel_SQL::totals()' );
} else {
	$th = $wpdb->prefix . 'minn_conf_hits';
	$tp = $wpdb->prefix . 'minn_conf_paths';
	$wpdb->query( "CREATE TEMPORARY TABLE {$th} ( id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, day DATE NOT NULL, at DATETIME NOT NULL, ts BIGINT NOT NULL, path_id BIGINT NOT NULL, visitor VARCHAR(20) NOT NULL, views INT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
	$wpdb->query( "CREATE TEMPORARY TABLE {$tp} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, path VARCHAR(60) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
	foreach ( array( 1 => '/a', 2 => '/b', 3 => '/c' ) as $pid => $pp ) {
		$wpdb->insert( $tp, array( 'id' => $pid, 'path' => $pp ) );
	}
	// 20:00 UTC: the next day for a site ten hours ahead, so a day group
	// that lost its shift reads the wrong day.
	$t_eve = gmmktime( 20, 0, 0, 3, 10, 2026 );
	foreach ( array(
		array( '2026-03-10', 1, 'u1', 2 ),
		array( '2026-03-10', 1, 'u1', 3 ),
		array( '2026-03-10', 1, 'u2', null ),
		array( '2026-03-11', 2, 'u1', 4 ),
		array( '2026-03-11', 2, 'u3', 1 ),
		array( '2026-03-11', 3, 'u4', 5 ),
		array( '2026-03-12', 9, 'u5', 1 ),
		array( '2026-03-12', 4, 'u6', null ),
	) as $h ) {
		$ts = $t_eve + ( (int) substr( $h[0], 8, 2 ) - 10 ) * DAY_IN_SECONDS;
		$wpdb->insert( $th, array( 'day' => $h[0], 'at' => gmdate( 'Y-m-d H:i:s', $ts ), 'ts' => $ts, 'path_id' => $h[1], 'visitor' => $h[2], 'views' => $h[3] ) );
	}
	$t_src = array( 'table' => 'minn_conf_hits', 'joins' => array( 'p' => array( 'table' => 'minn_conf_paths', 'on' => array( 'id' => 'e.path_id' ) ) ) );
	$t_top = Minn_Admin_Kernel_SQL::totals(
		$t_src,
		array( 'path_id' ),
		array( 'hits' => array( 'count' => '*' ), 'visitors' => array( 'distinct' => 'visitor' ), 'views' => array( 'sum' => 'views' ), 'path' => array( 'max' => 'p.path' ) ),
		array( 'day' => array( 'op' => '<=', 'value' => '2026-03-11' ) ),
		array( 'hits' => 'DESC', 'visitors' => 'DESC' ),
		2
	);
	$t_want = array(
		array( 'path_id' => '1', 'hits' => '3', 'visitors' => '2', 'views' => '5', 'path' => '/a' ),
		array( 'path_id' => '2', 'hits' => '2', 'visitors' => '2', 'views' => '5', 'path' => '/b' ),
	);
	$check( 'grouped totals count, count distinct, sum and take a joined column per group, under conditions, in the declared order, at most the limit', $t_want === $t_top, wp_json_encode( $t_top ) );
	$t_null = Minn_Admin_Kernel_SQL::totals( 'minn_conf_hits', array( 'path_id' ), array( 'v' => array( 'sum' => 'views' ) ), array( 'path_id' => 4 ) );
	$check( 'a sum over a group whose values are all NULL is 0, not nothing', array( array( 'path_id' => '4', 'v' => '0' ) ) === $t_null, wp_json_encode( $t_null ) );
	$t_tie = array_column( Minn_Admin_Kernel_SQL::totals( 'minn_conf_hits', array( 'path_id' ), array( 'v' => array( 'sum' => 'views' ) ), array(), array( 'v' => 'DESC' ), 10 ), 'path_id' );
	$t_sql = (string) $wpdb->last_query;
	$check( 'rows that tie on the order are broken by the groups, so a limit always cuts the same ones', array( '1', '2', '3', '9', '4' ) === $t_tie && false !== strpos( $t_sql, 'ORDER BY COALESCE(SUM(e.views),0) DESC, e.path_id ASC' ), wp_json_encode( array( $t_tie, $t_sql ) ) );
	$t_zone = function () {
		return '';
	};
	$t_ten  = function () {
		return 10;
	};
	add_filter( 'pre_option_timezone_string', $t_zone );
	add_filter( 'pre_option_gmt_offset', $t_ten );
	$t_days = array();
	foreach ( array( 'date' => 'day', 'local' => 'at', 'local-epoch' => 'ts', 'epoch' => 'ts', 'utc' => 'at' ) as $clock => $col ) {
		$t_days[ $clock ] = array_column( Minn_Admin_Kernel_SQL::totals( 'minn_conf_hits', array( 'd' => array( 'day' => $col, 'clock' => $clock ) ), array( 'n' => array( 'count' => '*' ) ), array(), array( 'd' => 'ASC' ), 10 ), 'n', 'd' );
	}
	remove_filter( 'pre_option_timezone_string', $t_zone );
	remove_filter( 'pre_option_gmt_offset', $t_ten );
	$t_utc  = array( '2026-03-10' => '3', '2026-03-11' => '3', '2026-03-12' => '2' );
	$t_site = array( '2026-03-11' => '3', '2026-03-12' => '3', '2026-03-13' => '2' );
	$check( "a day group reads a DATE column as it is, a datetime's date, an epoch's UTC day unshifted, and the site day of an epoch or a UTC datetime (ten hours ahead, the next day), each as Y-m-d", $t_utc === $t_days['date'] && $t_utc === $t_days['local'] && $t_utc === $t_days['local-epoch'] && $t_site === $t_days['epoch'] && $t_site === $t_days['utc'], wp_json_encode( $t_days ) );
	$t_inj = Minn_Admin_Kernel_SQL::totals( 'minn_conf_hits', array( 'visitor' ), array( 'n' => array( 'count' => '*' ) ), array( 'visitor' => "u1' OR 1=1 -- " ), array(), 10 );
	$check( 'a value in a grouped total is a placeholder, never SQL', array() === $t_inj, wp_json_encode( $t_inj ) );
	$t_bad = array();
	foreach ( array(
		array( 'minn_conf_hits', array( 'path_id' ), array( 'n' => array( 'avg' => 'views' ) ), array() ),
		array( 'minn_conf_hits', array( 'path_id' ), array( 'path_id' => array( 'count' => '*' ) ), array() ),
		array( 'minn_conf_hits', array( 'path_id' ), array( 'n' => array( 'count' => '*' ) ), array( 'views' => 'DESC' ) ),
		array( 'minn_conf_no_table', array( 'path_id' ), array( 'n' => array( 'count' => '*' ) ), array( 'views' => 'DESC' ) ),
		array( 'minn_conf_hits', array( 'd' => array( 'day' => 'at', 'clock' => 'mars' ) ), array( 'n' => array( 'count' => '*' ) ), array() ),
		array( 'minn_conf_hits', array( 'd' => array( 'day' => 'at' ) ), array( 'n' => array( 'count' => '*' ) ), array() ),
		array( 'minn_conf_hits', array( 'path_id' ), array( 'n' => array( 'sum' => 'views; DROP TABLE x' ) ), array() ),
		array( 'minn_conf_hits', array(), array(), array() ),
	) as $b ) {
		try {
			Minn_Admin_Kernel_SQL::totals( $b[0], $b[1], $b[2], array(), $b[3], 10 );
			$t_bad[] = 'ran';
		} catch ( InvalidArgumentException $e ) {
			$t_bad[] = 'refused';
		}
	}
	$check( 'a total of an unknown kind, a name used twice, an order by what is not selected (whether or not the table is there), an unknown or missing clock, a column that is no identifier, and nothing to select are refused', array_fill( 0, 8, 'refused' ) === $t_bad, wp_json_encode( $t_bad ) );
	$t_kw = Minn_Admin_Kernel_SQL::totals( 'minn_conf_hits', array( 'path_id' => 'key' ), array( 'rank' => array( 'count' => '*' ) ), array( 'path_id' => 9 ) );
	$check( 'a total or group named like a reserved word is still one', array( array( 'key' => '9', 'rank' => '1' ) ) === $t_kw, wp_json_encode( $t_kw ) );
	$check( 'grouped totals over a table that is not there are none, and the limit holds between 1 and 5,000', array() === Minn_Admin_Kernel_SQL::totals( 'minn_conf_no_table', array( 'x' ), array( 'n' => array( 'count' => '*' ) ) ) && 1 === count( Minn_Admin_Kernel_SQL::totals( 'minn_conf_hits', array( 'path_id' ), array( 'n' => array( 'count' => '*' ) ), array(), array(), 0 ) ) );
}

// A vendor's full table name, as the SQL layer names it.
$nm_site = Minn_Admin_Kernel_SQL::named( $wpdb->prefix . 'minn_conf_hits' );
$nm_net  = Minn_Admin_Kernel_SQL::named( $wpdb->base_prefix . 'minn_conf_net' );
$check( "a vendor's full table name reads as the site's table, or the network's on the base prefix", 'minn_conf_hits' === $nm_site && Minn_Admin_Kernel_SQL::table( $nm_site ) === $wpdb->prefix . 'minn_conf_hits' && ( $wpdb->prefix === $wpdb->base_prefix ? 'minn_conf_net' === $nm_net : array( 'table' => 'minn_conf_net', 'network' => true ) === $nm_net ) && 'other_table' === Minn_Admin_Kernel_SQL::named( 'other_table' ), wp_json_encode( array( $nm_site, $nm_net ) ) );

// What an error says survives unless it quotes a query or a server path.
$cl_keep = array( 'UpdraftPlus could not delete the set.', 'Select a state from the list', 'Could not delete: Unable to remove file from Dropbox', 'Insert a value into the field' );
$cl_drop = array( 'SELECT * FROM wp_users WHERE 1', 'DELETE FROM `wp_minn` WHERE id = 1', "You have an error in your SQL syntax; check the manual near 'x'", 'failed in ' . ABSPATH . 'wp-config.php' );
$cl_ok   = true;
foreach ( $cl_keep as $m ) {
	$cl_ok = $cl_ok && $m === Minn_Admin_Kernel::clean_error( new WP_Error( 'x', $m, array( 'status' => 400 ) ) )->get_error_message();
}
foreach ( $cl_drop as $m ) {
	$cl_ok = $cl_ok && $m !== Minn_Admin_Kernel::clean_error( new WP_Error( 'x', $m, array( 'status' => 400 ) ) )->get_error_message();
}
$check( "an error keeps a vendor's own words, and loses one that quotes SQL as a database prints it or a server path", $cl_ok );
// A site prefix WordPress allows ("1wp_") is a table name MySQL takes; the
// declared part still has to be an identifier.
$tb_prefix      = $wpdb->prefix;
$wpdb->prefix   = '1wp_';
$tb_digit       = Minn_Admin_Kernel_SQL::table( 'minn_conf_hits' );
$wpdb->prefix   = $tb_prefix;
$tb_nested      = Minn_Admin_Kernel_SQL::table( array( 'table' => array( 'table' => 'minn_conf_net', 'network' => true ) ) );
try {
	Minn_Admin_Kernel_SQL::table( '1bad name' );
	$tb_bad = 'ran';
} catch ( InvalidArgumentException $e ) {
	$tb_bad = 'refused';
}
$check( "a table on a prefix that starts with a digit is read; a spec in the table's place is the network's table; a declared name that is no identifier is refused", '1wp_minn_conf_hits' === $tb_digit && $wpdb->base_prefix . 'minn_conf_net' === $tb_nested && 'refused' === $tb_bad, wp_json_encode( array( $tb_digit, $tb_nested, $tb_bad ) ) );

// --- 1h. Closed by the kernel review: each check fails without its fix -------
$rt = $wpdb->prefix . 'minn_conf_review';
$wpdb->query( "CREATE TEMPORARY TABLE {$rt} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, owner BIGINT NOT NULL, status VARCHAR(20) NOT NULL, created DATETIME NOT NULL, tiny TINYINT NOT NULL DEFAULT 0, n INT NOT NULL DEFAULT 0, big BIGINT UNSIGNED NOT NULL DEFAULT 0 ) ENGINE=InnoDB" ); // phpcs:ignore
$rv_today = minn_admin_chart_day( 0 );
foreach ( array(
	array( 1, 10, 'open', '2026-01-02 10:00:00' ),
	array( 2, 10, 'open', '2026-01-03 10:00:00' ),
	array( 3, 20, 'spam', '2026-01-03 11:00:00' ),
	array( 4, 20, 'open', '2026-01-05 10:00:00' ),
	array( 5, 30, 'open', '2026-01-06 10:00:00' ),
	array( 6, 30, 'spam', '2026-01-06 11:00:00' ),
	array( 7, 10, 'open', $rv_today . ' 10:00:00' ),
	array( 8, 20, 'open', $rv_today . ' 10:00:00' ),
) as $s ) {
	$wpdb->insert( $rt, array( 'id' => $s[0], 'owner' => $s[1], 'status' => $s[2], 'created' => $s[3] ) );
}
$rv_def = array(
	'gates'  => array( 'view' => '__return_true', 'manage' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_review', 'key' => 'id', 'columns' => array( 'id', 'owner', 'status', 'created' ) ),
	'list'   => array(
		'per_page' => 2,
		'range'    => array( 'column' => 'created', 'clock' => 'local' ),
		'order'    => array( 'column' => 'id', 'dir' => 'ASC' ),
		'fields'   => array( 'id' => 'int' ),
	),
	'item'   => array(
		'view'   => function ( $row ) {
			return array( 'id' => (int) $row['id'] );
		},
		'fields' => array( 'id' => 'int' ),
	),
);
$rv = '/minn-admin/v1/minn-conformance/review';
Minn_Admin_Kernel::resource( 'minn-conformance/review', $rv_def );
$rv_drew = function ( $needle ) {
	foreach ( method_exists( 'Minn_Admin_Kernel', 'refused' ) ? Minn_Admin_Kernel::refused() : array() as $m ) {
		if ( false !== strpos( $m, $needle ) ) {
			return true;
		}
	}
	return false;
};

// Two earlier checks held without their primitive; these have the rows to tell.
Minn_Admin_Kernel::resource( 'minn-conformance/review-many', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'rows' => function () {
		return array_map( function ( $i ) {
			return array( 'id' => $i );
		}, range( 1, 150 ) );
	} ),
	'list'   => array( 'per_page' => 500, 'fields' => array( 'id' => 'int' ) ),
) );
list( , $d )  = $call( 'GET', $rv . '-many', array( 'per_page' => 1000 ) );
list( , $d2 ) = $call( 'GET', $rv . '-many' );
$check( 'a page is never longer than 100 rows, asked for or declared, over more rows than that', 100 === count( $d['items'] ) && 150 === $d['total'] && 100 === count( $d2['items'] ), wp_json_encode( array( count( $d['items'] ), $d['total'], count( $d2['items'] ) ) ) );
list( , $d ) = $call( 'GET', $rv, array( 'after' => '2026-01-04', 'before' => '2026-01-06 10:30:00' ) );
$check( 'a chart day range keeps only the rows inside it', 2 === $d['total'] && array( 4, 5 ) === array_column( $d['items'], 'id' ), wp_json_encode( $d ) );

// A filter whose values came out empty loses its list route, not its scope.
Minn_Admin_Kernel::resource( 'minn-conformance/review-nofilter', array_merge( $rv_def, array(
	'list' => array_merge( $rv_def['list'], array(
		'filter' => array( 'param' => 'status', 'column' => 'status', 'values' => array() ),
		'scope'  => array( 'column' => 'owner', 'allowed' => function () {
			return array( 30 );
		} ),
	) ),
) ) );
list( $st ) = $call( 'GET', $rv . '-nofilter' );
list( $st1 ) = $call( 'GET', $rv . '-nofilter/1' );
list( $st5 ) = $call( 'GET', $rv . '-nofilter/5' );
$check( 'a filter whose values came out empty loses its list route, and its items keep the scope', 404 === $st && 403 === $st1 && 200 === $st5 && $rv_drew( 'minn-conformance/review-nofilter declares a filter with no values' ), "{$st} {$st1} {$st5}" );

// A declaration that throws is a resource with no routes, never a fatal.
$rv_threw = false;
try {
	Minn_Admin_Kernel::resource( 'minn-conformance/review-throws', function () {
		throw new RuntimeException( 'vendor class renamed in /srv/secret/class.php' );
	} );
	Minn_Admin_Kernel::collection( 'minn-conformance/review-throws', array( 'columns' => array() ) );
} catch ( \Throwable $e ) {
	$rv_threw = true;
}
list( $st ) = $call( 'GET', $rv . '-throws' );
list( $st2 ) = $call( 'GET', $rv );
$check( 'a declaration that throws registers nothing and stops nothing else', ! $rv_threw && 404 === $st && 200 === $st2 && $rv_drew( "minn-conformance/review-throws's declaration threw" ), ( $rv_threw ? 'threw ' : '' ) . "{$st} {$st2}" );

// An endpoint's gate is held to its signature when the route registers.
Minn_Admin_Kernel::endpoint( 'minn-conformance/review-gate-user', array(
	'gate' => function ( $user_id = 0 ) {
		return true;
	},
	'run'  => '__return_true',
) );
list( $st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/review-gate-user' );
$check( 'an endpoint whose gate takes something other than the request has no route', 404 === $st && $rv_drew( 'minn-conformance/review-gate-user: its gate takes' ), (string) $st );

// Writes to a core table are refused however the spec names it. Each would
// match no row if it ran (an id nothing has, a name nothing holds).
$rv_core = function ( callable $fn ) {
	try {
		$fn();
	} catch ( InvalidArgumentException $e ) {
		return 'minn_kernel_core_table' === $e->getMessage();
	}
	return false;
};
$rv_never = array( 'option_name' => 'minn-conformance-never-' . wp_generate_password( 8, false ) );
$check( "a write to a core table is refused however its spec names it: nested or on the base prefix (on a network site, the main site's)", $rv_core( function () use ( $rv_never ) {
	Minn_Admin_Kernel_SQL::delete( array( 'table' => array( 'table' => 'options', 'network' => true ) ), 'option_id', 2147483000, $rv_never );
} ) && $rv_core( function () use ( $rv_never ) {
	Minn_Admin_Kernel_SQL::delete( array( 'table' => 'options', 'network' => true ), 'option_id', 2147483000, $rv_never );
} ) && $rv_core( function () {
	Minn_Admin_Kernel_SQL::update( array( 'table' => array( 'table' => 'posts', 'network' => true ) ), 'ID', 2147483000, array( 'post_title' => 'x' ), array( 'post_title' => 'minn-conformance-never' ) );
} ) );

// What an error says, at any depth.
$cl_keep2 = array( 'Please select one from the list.', 'wp_mail() reported the message could not be sent.', 'See https://example.com/docs/setup.php for the steps.' );
$cl_drop2 = array( 'select * from wp_users', 'delete from `wp_minn` where id = 1', "Duplicate entry 'a@b.c' for key 'email'", 'SQLSTATE[23000]: Integrity constraint violation', 'failed in ' . WP_CONTENT_DIR . '/plugins/x/y.php', 'Fatal error in /srv/www/site/wp-content/plugins/x/class-y.php on line 3' );
$cl_ok2   = true;
foreach ( $cl_keep2 as $m ) {
	$cl_ok2 = $cl_ok2 && $m === Minn_Admin_Kernel::clean_error( new WP_Error( 'x', $m, array( 'status' => 400 ) ) )->get_error_message();
}
foreach ( $cl_drop2 as $m ) {
	$cl_ok2 = $cl_ok2 && $m !== Minn_Admin_Kernel::clean_error( new WP_Error( 'x', $m, array( 'status' => 400 ) ) )->get_error_message();
}
$check( "an error loses lowercase SQL, a database's own words and any server path, and keeps a sentence", $cl_ok2 );
Minn_Admin_Kernel::endpoint( 'minn-conformance/review-err-nested', array( 'gate' => '__return_true', 'run' => function () {
	return new WP_Error( 'x', 'Nope.', array( 'status' => 409, 'field' => 'email', 'details' => array( 'query' => 'SELECT 1', 'why' => 'select * from wp_users', 'ok' => 'kept' ) ) );
} ) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/review-err-response', array( 'gate' => '__return_true', 'run' => function () {
	return new WP_REST_Response( array( 'code' => 'vendor', 'message' => "WordPress database error Table 'x' doesn't exist", 'data' => array( 'status' => 500, 'sql' => 'SELECT * FROM wp_x' ) ), 500 );
} ) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/review-err-nested' );
$check( "an error's nested data loses debug keys and quoted SQL, and keeps the rest", 409 === $st && 'email' === ( $d['data']['field'] ?? '' ) && ! array_key_exists( 'query', (array) ( $d['data']['details'] ?? array() ) ) && '' === ( $d['data']['details']['why'] ?? null ) && 'kept' === ( $d['data']['details']['ok'] ?? '' ), wp_json_encode( $d ) );
list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/review-err-response' );
$check( "a vendor's error response keeps its status and shape, scrubbed as an error is", 500 === $st && 'vendor' === ( $d['code'] ?? '' ) && __( 'That did not work. Nothing was reported as done.', 'minn-admin' ) === ( $d['message'] ?? null ) && ! isset( $d['data']['sql'] ), wp_json_encode( $d ) );

// Inbox counts: over a table no resource lists, or over a resource's rows
// under the same rules as its list (fixed conditions and scope).
$rv_sc    = null;
$rv_fixed = array();
Minn_Admin_Kernel::resource( 'minn-conformance/review-inbox', array_merge( $rv_def, array(
	'source' => array_merge( $rv_def['source'], array( 'where' => function () use ( &$rv_fixed ) {
		return $rv_fixed;
	} ) ),
	'list'   => array_merge( $rv_def['list'], array( 'scope' => array( 'column' => 'owner', 'allowed' => function () use ( &$rv_sc ) {
		return $rv_sc;
	} ) ) ),
) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/review-inbox-unread', array_merge( $rv_def, array( 'list' => array_merge( $rv_def['list'], array( 'scope' => array( 'column' => 'owner' ) ) ) ) ) );
$rv_inbox = function ( $on, array $received = array() ) {
	return Minn_Admin_Kernel::inbox_counts( array(
		$on ? 'resource' : 'table' => $on ? $on : 'minn_conf_review',
		'received' => $received,
		'spam'     => array( 'status' => 'spam' ),
		'chart'    => array( 'column' => 'created', 'clock' => 'local', 'split' => 'status', 'secondary' => array( 'spam' ) ),
	) );
};
$rv_n = function ( $n ) use ( $rv_today ) {
	return array( $n['received'], $n['spam'], $n['days'][ $rv_today ]['value'] ?? -1 );
};
$n_open  = $rv_n( $rv_inbox( false ) );
$n_null  = $rv_n( $rv_inbox( 'minn-conformance/review-inbox' ) );
$rv_sc   = array( 10 );
$n_10    = $rv_n( $rv_inbox( 'minn-conformance/review-inbox' ) );
$rv_sc   = null;
$rv_fixed = array( 'owner' => 20 );
$n_20    = $rv_n( $rv_inbox( 'minn-conformance/review-inbox' ) );
$n_20_10 = $rv_n( $rv_inbox( 'minn-conformance/review-inbox', array( 'owner' => 10 ) ) );
$rv_fixed = array();
$check( "inbox counts: a table counts every row; a resource's count only the rows its list shows, its scope and its fixed conditions alike, and a card's own condition on the same column narrows further, never replacing them", array( 8, 2, 2 ) === $n_open && array( 8, 2, 2 ) === $n_null && array( 3, 0, 1 ) === $n_10 && array( 3, 1, 1 ) === $n_20 && array( 0, 1, 1 ) === $n_20_10, wp_json_encode( array( $n_open, $n_null, $n_10, $n_20, $n_20_10 ) ) );
$rv_empty = array();
foreach ( array( array(), false, 'all', new WP_Error( 'x' ) ) as $allowed ) {
	$rv_sc      = $allowed;
	$rv_empty[] = $rv_n( $rv_inbox( 'minn-conformance/review-inbox' ) );
}
$rv_sc      = null;
$rv_fixed   = false;
$rv_empty[] = $rv_n( $rv_inbox( 'minn-conformance/review-inbox' ) );
$rv_fixed   = array();
$rv_empty[] = $rv_n( $rv_inbox( 'minn-conformance/review-inbox-unread' ) );
$rv_empty[] = $rv_n( $rv_inbox( 'minn-conformance/review-nowhere' ) );
$check( 'inbox counts: an empty scope, one that is not ids, a fixed condition that cannot answer, a declaration the kernel refused or one never made counts nothing', array_fill( 0, 7, array( 0, 0, 0 ) ) === $rv_empty, wp_json_encode( $rv_empty ) );

// Gone is proven by the store, never by a read that could not be made.
$rv_where = true;
Minn_Admin_Kernel::resource( 'minn-conformance/review-gone', array_merge( $rv_def, array(
	'source'  => array_merge( $rv_def['source'], array( 'where' => function () use ( &$rv_where ) {
		return $rv_where ? array() : false;
	} ) ),
	'actions' => array( 'drop' => array(
		'method' => 'DELETE',
		'gate'   => 'manage',
		'run'    => function () use ( &$rv_where ) {
			$rv_where = false; // the read after the run cannot be made
			return true;
		},
		'expect' => 'gone',
	) ),
) ) );
list( $st, $d ) = $call( 'DELETE', $rv . '-gone/1' );
$rv_where = true;
$check( 'a delete whose read-back cannot be made never counts as gone', 500 === $st && 'not_applied' === ( $d['code'] ?? '' ) && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rt} WHERE id = 1" ), $st . ' ' . wp_json_encode( $d ) ); // phpcs:ignore

// Arg schemas declare their type.
Minn_Admin_Kernel::endpoint( 'minn-conformance/review-args', array( 'gate' => '__return_true', 'methods' => 'POST', 'args' => array( 'note' => array( 'maxLength' => 5 ) ), 'run' => '__return_true' ) );
list( $st ) = $call( 'POST', '/minn-admin/v1/minn-conformance/review-args', array(), array( 'note' => 'far too long' ) );
$check( 'an arg schema without a type gives its endpoint no route', 404 === $st, (string) $st );

// A send's text proves it only when it names an address.
Minn_Admin_Kernel::resource( 'minn-conformance/review-send', array_merge( $rv_def, array( 'actions' => array(
	'say'  => array( 'gate' => 'manage', 'run' => function () {
		return 'Queued';
	}, 'expect' => 'ran' ),
	'mail' => array( 'gate' => 'manage', 'run' => function () {
		return 'Dana <dana@example.com>';
	}, 'expect' => 'ran' ),
) ) ) );
list( $st ) = $call( 'POST', $rv . '-send/1/say' );
list( $st2 ) = $call( 'POST', $rv . '-send/1/mail' );
$check( "a send's text proves it only when it names an address", 500 === $st && 200 === $st2, "{$st} {$st2}" );

// A callable source's own get is held to the source's fixed conditions.
Minn_Admin_Kernel::resource( 'minn-conformance/review-get', array_merge( $rv_def, array(
	'source' => array(
		'rows'  => function () use ( $wpdb, $rt ) {
			return $wpdb->get_results( "SELECT * FROM {$rt} ORDER BY id", ARRAY_A ); // phpcs:ignore
		},
		'get'   => function ( $id ) use ( $wpdb, $rt ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rt} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
		},
		'where' => array( 'owner' => 10 ),
	),
) ) );
list( , $d ) = $call( 'GET', $rv . '-get' );
list( $st1 ) = $call( 'GET', $rv . '-get/1' );
list( $st3 ) = $call( 'GET', $rv . '-get/3' );
$check( "a source's fixed conditions hold for a row read through its own get", 3 === ( $d['total'] ?? null ) && 200 === $st1 && 404 === $st3, wp_json_encode( array( $d['total'] ?? null, $st1, $st3 ) ) );

// One declaration per id, one per method and route.
Minn_Admin_Kernel::resource( 'minn-conformance/review', array_merge( $rv_def, array( 'gates' => array( 'view' => '__return_false' ) ) ) );
$rv_first = Minn_Admin_Kernel::resources()['minn-conformance/review'] ?? array();
Minn_Admin_Kernel::endpoint( 'minn-conformance/review-dup', array( 'gate' => '__return_true', 'run' => function () {
	return array( 'which' => 'first' );
} ) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/review-dup', array( 'gate' => '__return_true', 'run' => function () {
	return array( 'which' => 'second' );
} ) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/review', array( 'gate' => '__return_true', 'run' => function () {
	return array( 'which' => 'endpoint' );
} ) );
list( , $d ) = $call( 'GET', '/minn-admin/v1/minn-conformance/review-dup' );
list( , $d2 ) = $call( 'GET', $rv );
$check( 'a second declaration of a resource id or of a method on a route is refused, the first kept', '__return_true' === ( $rv_first['gates']['view'] ?? null ) && 'first' === ( $d['which'] ?? '' ) && isset( $d2['items'] ) && $rv_drew( 'minn-conformance/review is declared twice' ) && $rv_drew( 'GET /minn-conformance/review-dup is already declared' ) && $rv_drew( 'GET /minn-conformance/review is already declared' ), wp_json_encode( array( $rv_first['gates']['view'] ?? null, $d, array_keys( (array) $d2 ) ) ) );

// A declaration the kernel cannot read whole registers nothing.
$rv_scope = array( 'column' => 'owner', 'allowed' => function () {
	return array( 30 );
} );
Minn_Admin_Kernel::resource( 'minn-conformance/review-topscope', array_merge( $rv_def, array( 'scope' => $rv_scope ) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/review-whentypo', array_merge( $rv_def, array( 'actions' => array( 'close' => array(
	'gate'   => 'manage',
	'whne'   => array( 'status' => array( 'spam' ) ),
	'run'    => '__return_true',
	'expect' => function () {
		return true;
	},
) ) ) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/review-fieldtype', array_merge( $rv_def, array( 'list' => array_merge( $rv_def['list'], array( 'fields' => array( 'id' => 'innt' ) ) ) ) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/review-noallowed', array_merge( $rv_def, array( 'list' => array_merge( $rv_def['list'], array( 'scope' => array( 'column' => 'owner' ) ) ) ) ) );
Minn_Admin_Kernel::endpoint( 'minn-conformance/review-method', array( 'gate' => '__return_true', 'method' => 'POST', 'run' => '__return_true' ) );
// Keys the kernel no longer reads: a page cap, renamed response keys, renamed
// sort keys, a create's message and reply fields, the types float and secret.
$rv_gone = array(
	'max'    => array( 'list' => array_merge( $rv_def['list'], array( 'max' => 3 ) ) ),
	'keys'   => array( 'list' => array_merge( $rv_def['list'], array( 'keys' => array( 'items' => 'rows' ) ) ) ),
	'sortby' => array( 'list' => array_merge( $rv_def['list'], array( 'sort' => array( 'columns' => array( 'id' ), 'param' => 'by' ) ) ) ),
	'done'   => array( 'create' => array( 'gate' => 'manage', 'run' => '__return_zero', 'done' => 'Made.' ) ),
	'cfield' => array( 'create' => array( 'gate' => 'manage', 'run' => '__return_zero', 'fields' => array( 'id' => 'int' ) ) ),
	'float'  => array( 'list' => array_merge( $rv_def['list'], array( 'fields' => array( 'id' => 'float' ) ) ) ),
	'secret' => array( 'item' => array_merge( $rv_def['item'], array( 'fields' => array( 'id' => 'int', 'x' => 'secret' ) ) ) ),
	'jnet'   => array( 'source' => array_merge( $rv_def['source'], array( 'joins' => array( 'o' => array( 'table' => 'minn_conf_review', 'network' => true, 'on' => array( 'id' => 'e.id' ) ) ) ) ) ),
);
foreach ( $rv_gone as $slug => $over ) {
	Minn_Admin_Kernel::resource( 'minn-conformance/review-gone-' . $slug, array_merge( $rv_def, $over ) );
}
list( $a ) = $call( 'GET', $rv . '-topscope/1' );
list( $b ) = $call( 'POST', $rv . '-whentypo/1/close' );
list( $c ) = $call( 'GET', $rv . '-fieldtype' );
list( $e ) = $call( 'GET', $rv . '-noallowed/1' );
list( $f ) = $call( 'GET', '/minn-admin/v1/minn-conformance/review-method' );
$check( 'a declaration holding a key the kernel does not read, a field type it cannot encode, or a scope it cannot ask registers nothing', 404 === $a && 404 === $b && 404 === $c && 404 === $e && 404 === $f, "{$a} {$b} {$c} {$e} {$f}" );
$check( 'each one says why', $rv_drew( 'minn-conformance/review-topscope: the resource holds "scope"' ) && $rv_drew( 'minn-conformance/review-whentypo: actions.close holds "whne"' ) && $rv_drew( 'minn-conformance/review-fieldtype: list.fields.id has no type' ) && $rv_drew( 'minn-conformance/review-noallowed: list.scope needs' ) && $rv_drew( 'minn-conformance/review-method: the endpoint holds "method"' ), implode( ' | ', array_slice( method_exists( 'Minn_Admin_Kernel', 'refused' ) ? Minn_Admin_Kernel::refused() : array(), -8 ) ) );
$rv_gone_st = array();
foreach ( array_keys( $rv_gone ) as $slug ) {
	list( $rv_gone_st[ $slug ] ) = $call( 'GET', $rv . '-gone-' . $slug . '/1' );
}
$check( 'a declaration naming a key or field type the kernel no longer reads (list.max, list.keys, the sort\'s param, create.done and create.fields, float, secret, a join\'s network) registers nothing', array_fill_keys( array_keys( $rv_gone ), 404 ) === $rv_gone_st, wp_json_encode( $rv_gone_st ) );
$check( 'a field type the kernel does not know never leaves', array( 'a' => 1 ) === Minn_Admin_Kernel_Fields::out( array( 'a' => 'int', 'b' => 'innt' ), array( 'a' => '1', 'b' => 'raw value' ) ) );

// Identifiers, keys and whole numbers in writes.
$rv_nl = false;
try {
	Minn_Admin_Kernel_SQL::ident( "status\n" );
} catch ( InvalidArgumentException $e ) {
	$rv_nl = true;
}
$check( 'an identifier with a trailing newline is refused', $rv_nl );
$rv_key = false;
try {
	Minn_Admin_Kernel_SQL::update( 'minn_conf_review', 'id', 2, array( 'ID' => 9 ) );
} catch ( InvalidArgumentException $e ) {
	$rv_key = 'minn_kernel_update_key' === $e->getMessage();
}
$check( "an update never writes its key, whatever the key's case", $rv_key && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rt} WHERE id = 2" ) ); // phpcs:ignore
$rv_fit = function ( array $values ) {
	return Minn_Admin_Kernel_SQL::update( 'minn_conf_review', 'id', 3, $values );
};
$rv_refused = 0 === $rv_fit( array( 'tiny' => 300 ) ) && 0 === $rv_fit( array( 'n' => 5.5 ) ) && 0 === $rv_fit( array( 'n' => '1e3' ) ) && 0 === $rv_fit( array( 'n' => '2147483648' ) ) && 0 === $rv_fit( array( 'big' => -1 ) ) && 0 === $rv_fit( array( 'big' => '18446744073709551616' ) );
$rv_kept    = 1 === $rv_fit( array( 'tiny' => -128 ) ) && 1 === $rv_fit( array( 'n' => '-2147483648' ) ) && 1 === $rv_fit( array( 'big' => '18446744073709551615' ) );
$rv_row     = $wpdb->get_row( "SELECT tiny, n, big FROM {$rt} WHERE id = 3", ARRAY_A ); // phpcs:ignore
$check( 'a write holds whole numbers to their column: no fraction, no exponent, nothing past its range', $rv_refused && $rv_kept && array( 'tiny' => '-128', 'n' => '-2147483648', 'big' => '18446744073709551615' ) === $rv_row, wp_json_encode( array( $rv_refused, $rv_kept, $rv_row ) ) );

// A refused declaration is refused to every reader, not only to routes: a
// card, a row read by id or a tab list would read its unread scope as none.
$rv_load = Minn_Admin_Kernel::load( 'minn-conformance/review-topscope', 1 );
$check( 'a refused declaration counts nothing and reads no row', 0 === Minn_Admin_Kernel::count( 'minn-conformance/review-topscope' ) && is_wp_error( $rv_load ) && 404 === ( $rv_load->get_error_data()['status'] ?? 0 ) && array() === Minn_Admin_Kernel::distinct( 'minn-conformance/review-topscope', 'owner' ), wp_json_encode( array( Minn_Admin_Kernel::count( 'minn-conformance/review-topscope' ), is_wp_error( $rv_load ) ) ) );

// Gone is proven only by a table or the store's own exists.
$rv_rows = function () use ( $wpdb, $rt ) {
	return $wpdb->get_results( "SELECT * FROM {$rt} ORDER BY id", ARRAY_A ); // phpcs:ignore
};
$rv_get  = function ( $id ) use ( $wpdb, $rt ) {
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rt} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore
};
Minn_Admin_Kernel::resource( 'minn-conformance/review-noexists', array_merge( $rv_def, array(
	'source'  => array( 'rows' => $rv_rows, 'get' => $rv_get ),
	'actions' => array( 'drop' => array( 'method' => 'DELETE', 'gate' => 'manage', 'run' => '__return_true', 'expect' => 'gone' ) ),
) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/review-badexists', array_merge( $rv_def, array(
	'source' => array( 'rows' => $rv_rows, 'get' => $rv_get, 'exists' => 'minn_conformance_no_such_function' ),
) ) );
list( $st ) = $call( 'DELETE', $rv . '-noexists/1' );
list( $st2 ) = $call( 'GET', $rv . '-badexists' );
$check( "a delete proving gone through a source that is no table and has no exists gets no route, and an exists that is not callable refuses its resource", 404 === $st && 404 === $st2 && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rt} WHERE id = 1" ), "{$st} {$st2}" ); // phpcs:ignore

// A create's misspelt expect and a clock the kernel does not read.
Minn_Admin_Kernel::resource( 'minn-conformance/review-createtypo', array_merge( $rv_def, array(
	'create' => array( 'gate' => 'manage', 'run' => function () {
		return 99;
	}, 'expect' => 'exist' ),
) ) );
Minn_Admin_Kernel::resource( 'minn-conformance/review-clock', array_merge( $rv_def, array( 'list' => array_merge( $rv_def['list'], array( 'range' => array( 'column' => 'created', 'clock' => 'utcc' ) ) ) ) ) );
list( $st ) = $call( 'POST', $rv . '-createtypo', array(), array() );
list( $st2 ) = $call( 'GET', $rv . '-clock' );
$check( "a create whose expect is neither 'exists' nor a function, and a range on an unknown clock, register nothing", 404 === $st && 404 === $st2, "{$st} {$st2}" );

// A callable list is held to the source's fixed conditions.
Minn_Admin_Kernel::resource( 'minn-conformance/review-listwhere', array_merge( $rv_def, array(
	'source' => array(
		'list'  => function () use ( $rv_rows ) {
			$rows = $rv_rows();
			return array( $rows, count( $rows ) ); // ignores the fixed condition it is handed
		},
		'get'   => $rv_get,
		'where' => array( 'owner' => 10 ),
	),
) ) );
list( , $d ) = $call( 'GET', $rv . '-listwhere' );
$check( "a callable list's rows are held to the source's fixed conditions, its total too", 3 === ( $d['total'] ?? null ) && array( 1, 2, 7 ) === array_column( (array) ( $d['items'] ?? array() ), 'id' ), wp_json_encode( $d ) );

// A network site writes only its own tables.
$rv_net = 'allowed';
try {
	Minn_Admin_Kernel_SQL::delete( array( 'table' => 'minn_conf_never', 'network' => true ), 'id', 2147483000 );
} catch ( InvalidArgumentException $e ) {
	$rv_net = $e->getMessage();
}
$rv_sub = is_multisite() && $wpdb->prefix !== $wpdb->base_prefix;
$check( $rv_sub ? "a network site's write to a table on the base prefix is refused" : 'a single site writes a table on its own prefix', $rv_sub ? 'minn_kernel_network_write' === $rv_net : 'allowed' === $rv_net, $rv_net );

// A declaration whose parts are not arrays is refused, never a fatal at
// rest_api_init (the whole REST API) and never read as no list.
$rv_threw2 = false;
try {
	Minn_Admin_Kernel::resource( 'minn-conformance/review-closure-source', array_merge( $rv_def, array( 'source' => function () {
		return array();
	} ) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/review-list-true', array_merge( $rv_def, array( 'list' => true ) ) );
} catch ( \Throwable $e ) {
	$rv_threw2 = true;
}
list( $st ) = $call( 'GET', $rv . '-closure-source' );
list( $st2 ) = $call( 'GET', $rv . '-list-true' );
list( $st3 ) = $call( 'GET', $rv );
$check( 'a declaration whose source is a function, or whose list is not a list, registers nothing and stops nothing else', ! $rv_threw2 && 404 === $st && 404 === $st2 && 200 === $st3, ( $rv_threw2 ? 'threw ' : '' ) . "{$st} {$st2} {$st3}" );

// A send proves itself with addresses only, not a mailer's error quoting one.
Minn_Admin_Kernel::resource( 'minn-conformance/review-send2', array_merge( $rv_def, array( 'actions' => array(
	'err'  => array( 'gate' => 'manage', 'run' => function () {
		return 'SMTP Error: The following recipients failed: bob@example.com';
	}, 'expect' => 'ran' ),
	'many' => array( 'gate' => 'manage', 'run' => function () {
		return 'Dana <dana@example.com>, bob@example.com';
	}, 'expect' => 'ran' ),
	'name' => array( 'gate' => 'manage', 'run' => function () {
		return '"Smith, Dana" <dana@example.com>, Lee, Kim <kim@example.com>, Team @ Acme <team@example.com>, bob@example.com';
	}, 'expect' => 'ran' ),
	'word' => array( 'gate' => 'manage', 'run' => function () {
		return 'Queued for dana';
	}, 'expect' => 'ran' ),
	'smtp' => array( 'gate' => 'manage', 'run' => function () {
		return '550 5.1.1 <dana@example.com>: Recipient address rejected';
	}, 'expect' => 'ran' ),
	'typo' => array( 'gate' => 'manage', 'run' => function () {
		return 'dana@gmailcom';
	}, 'expect' => 'ran' ),
) ) ) );
list( $st ) = $call( 'POST', $rv . '-send2/1/err' );
list( $st2 ) = $call( 'POST', $rv . '-send2/1/many' );
list( $st3 ) = $call( 'POST', $rv . '-send2/1/name' );
list( $st4 ) = $call( 'POST', $rv . '-send2/1/word' );
list( $st5 ) = $call( 'POST', $rv . '-send2/1/smtp' );
list( $st6 ) = $call( 'POST', $rv . '-send2/1/typo' );
$check( "a send's text proves it only when it is addresses WordPress would mail (a name may hold a comma), not words, a mailer's reply or a typo", 500 === $st && 200 === $st2 && 200 === $st3 && 500 === $st4 && 500 === $st5 && 500 === $st6, "{$st} {$st2} {$st3} {$st4} {$st5} {$st6}" );

// More of what a database or a server prints, and sentences that only look like it.
$cl_keep3 = array( 'Could not send from wp_mail(): SMTP down', 'Duplicate entry: a redirect for this URL already exists.', 'Incorrect password value.', 'Access denied for user role editor.', 'A redirect from /blog/2020/old-post already exists.', 'POST /v3/mg.example.com/messages returned 401', '421 4.7.0 Error: too many connections from 192.0.2.4' );
$cl_drop3 = array( "Can't connect to MySQL server on 'db-prod-3.internal' (111)", "Can't connect to server on 'db' (115)", "Column 'email' cannot be null", 'Lost connection to MySQL server during query', "Unknown database 'acme_live'", 'unlink(/var/www/acme/shared/uploads/secret.csv): Permission denied', 'Could not open /home/acme/backups/db-2026-10-15.sql.gz', 'Could not write /tmp/backup.zip', 'Could not read /home2/acme/public_html/export.csv', 'Could not open /kunden/homepages/1/d2/backup.sql', 'insert into ' . $wpdb->base_prefix . 'minn_x(id) values (1)' );
$cl_ok3   = true;
$cl_bad3  = array();
foreach ( $cl_keep3 as $m ) {
	if ( $m !== Minn_Admin_Kernel::clean_error( new WP_Error( 'x', $m, array( 'status' => 400 ) ) )->get_error_message() ) {
		$cl_bad3[] = 'dropped: ' . $m;
	}
}
foreach ( $cl_drop3 as $m ) {
	if ( $m === Minn_Admin_Kernel::clean_error( new WP_Error( 'x', $m, array( 'status' => 400 ) ) )->get_error_message() ) {
		$cl_bad3[] = 'kept: ' . $m;
	}
}
$check( "an error loses a database's connection and column errors and any server path, and keeps sentences that only look like them", ! $cl_bad3, implode( ' | ', $cl_bad3 ) );

// --- 1i. The database's own clock: a column NOW() wrote ---------------------
// A database five hours from UTC, whatever the site's zone, for this section.
$dc_zone = (string) $wpdb->get_var( 'SELECT @@session.time_zone' );
$wpdb->query( "SET time_zone = '+05:00'" ); // phpcs:ignore
$dt = $wpdb->prefix . 'minn_conf_dbclock';
$wpdb->query( "CREATE TEMPORARY TABLE {$dt} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, created DATETIME NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$wpdb->query( "INSERT INTO {$dt} ( id, created ) VALUES ( 1, NOW() ), ( 2, NOW() - INTERVAL 3 DAY ), ( 3, NOW() - INTERVAL 20 DAY ), ( 4, NOW() - INTERVAL 2 HOUR )" ); // phpcs:ignore
Minn_Admin_Kernel::resource( 'minn-conformance/dbclock', array(
	'gates'  => array( 'view' => '__return_true' ),
	'source' => array( 'table' => 'minn_conf_dbclock', 'key' => 'id', 'columns' => array( 'id', 'created' ) ),
	'list'   => array(
		'range'  => array( 'column' => 'created', 'clock' => 'db' ),
		'order'  => array( 'column' => 'id', 'dir' => 'ASC' ),
		'fields' => array(
			'id'      => 'int',
			'created' => array( 'type' => 'datetime', 'stored' => 'db', 'wire' => 'iso-utc' ),
		),
	),
) );
$dc       = '/minn-admin/v1/minn-conformance/dbclock';
$dc_today = 1 + ( time() - 2 * HOUR_IN_SECONDS >= (int) Minn_Admin_Kernel::at( 'epoch', 0 ) ? 1 : 0 ); // row 4 is today unless it is not yet 2 a.m.
list( , $d ) = $call( 'GET', $dc );
$dc_first    = strtotime( (string) ( $d['items'][0]['created'] ?? '' ) );
$check( "the database's clock: a row NOW() wrote leaves as the instant it was", $dc_first && abs( $dc_first - time() ) < 300, wp_json_encode( $d['items'][0] ?? null ) );
list( , $d )  = $call( 'GET', $dc, array( 'after' => minn_admin_chart_day( 0 ) ) );
list( , $d2 ) = $call( 'GET', $dc, array( 'after' => minn_admin_chart_day( -5 ), 'before' => minn_admin_chart_day( -2 ) . ' 23:59:59' ) );
$check( "the database's clock: a day range reads its bounds as the database does", $dc_today === ( $d['total'] ?? null ) && array( 2 ) === array_column( (array) ( $d2['items'] ?? array() ), 'id' ), wp_json_encode( array( $d['total'] ?? null, $dc_today, array_column( (array) ( $d2['items'] ?? array() ), 'id' ) ) ) );
$dc_days = array();
$dc_tot  = array();
try {
	$dc_days = Minn_Admin_Kernel::days( 'minn-conformance/dbclock', array( 'column' => 'created', 'clock' => 'db' ) );
	$dc_tot  = Minn_Admin_Kernel_SQL::totals( 'minn_conf_dbclock', array( 'day' => array( 'day' => 'created', 'clock' => 'db' ) ), array( 'n' => array( 'count' => '*' ) ) );
} catch ( \Throwable $e ) {
	$dc_days = array( 'threw' => $e->getMessage() );
}
$check( "the database's clock: chart days put each row on its site day", $dc_today === ( $dc_days[ minn_admin_chart_day( 0 ) ]['value'] ?? null ) && 1 === ( $dc_days[ minn_admin_chart_day( -3 ) ]['value'] ?? null ) && 3 === array_sum( array_column( array_filter( $dc_days, 'is_array' ), 'value' ) ), wp_json_encode( $dc_days ) );
$check( "the database's clock: grouped totals fall on the same days", in_array( array( 'day' => minn_admin_chart_day( 0 ), 'n' => (string) $dc_today ), $dc_tot, true ) && in_array( array( 'day' => minn_admin_chart_day( -20 ), 'n' => '1' ), $dc_tot, true ), wp_json_encode( $dc_tot ) );
$dc_ago = Minn_Admin_Kernel::ago( $wpdb->get_var( "SELECT created FROM {$dt} WHERE id = 1" ), 'db' ); // phpcs:ignore
$check( "the database's clock: a window counts on it, and a last event's age reads it", 1 === Minn_Admin_Kernel::count( 'minn-conformance/dbclock', array( 'created' => Minn_Admin_Kernel::since( 'db', HOUR_IN_SECONDS ) ) ) && false === strpos( $dc_ago, 'hour' ), wp_json_encode( array( Minn_Admin_Kernel::count( 'minn-conformance/dbclock', array( 'created' => Minn_Admin_Kernel::since( 'db', HOUR_IN_SECONDS ) ) ), $dc_ago ) ) );
$wpdb->query( $wpdb->prepare( 'SET time_zone = %s', '' === $dc_zone ? 'SYSTEM' : $dc_zone ) ); // phpcs:ignore

// --- 1j. A whole table, for screens that show tables raw -------------------
// The database browser leaves out a table a resource's own rule keeps the
// viewer from (Minn_Admin_Kernel::table_refused()): its gate, the gates its
// item and reads answer to, its 'table' gate, a scope short of everything, a
// tab gate with no scope, a fixed condition it cannot read.
// None of these tables exist; the answer comes from the declarations alone.
if ( ! method_exists( 'Minn_Admin_Kernel', 'table_refused' ) ) {
	$check( 'a whole table: Minn_Admin_Kernel::table_refused() exists', false );
} else {
	$tr_list = array( 'fields' => array( 'id' => 'int' ) );
	$tr_def  = function ( $table, $gate, array $more = array() ) use ( $tr_list ) {
		return array_replace_recursive( array(
			'gates'  => array( 'view' => $gate ),
			'source' => array( 'table' => $table, 'key' => 'id', 'columns' => array( 'id', 'owner' ) ),
			'list'   => $tr_list,
		), $more );
	};
	$tr_scope = array( 1 );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-open', $tr_def( 'minn_conf_tr_open', '__return_true', array(
		'source' => array( 'joins' => array( 'j' => array( 'table' => 'minn_conf_tr_shared', 'on' => array( 'id' => 'e.owner' ) ) ) ),
		'list'   => array( 'search' => array( 'meta' => array( 'table' => 'minn_conf_tr_meta', 'key' => 'thing_id', 'name' => 'k', 'value' => 'v' ) ) ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-shut', $tr_def( 'minn_conf_tr_shut', '__return_false', array(
		'source' => array( 'joins' => array(
			'j' => array( 'table' => 'minn_conf_tr_shared', 'on' => array( 'id' => 'e.owner' ) ),
			'k' => array( 'table' => 'minn_conf_tr_shutjoin', 'on' => array( 'id' => 'e.owner' ) ),
			'u' => array( 'core' => 'users', 'on' => array( 'ID' => 'e.owner' ) ),
		) ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-scope', $tr_def( 'minn_conf_tr_scope', '__return_true', array(
		'list' => array( 'scope' => array( 'column' => 'owner', 'allowed' => function () use ( &$tr_scope ) {
			return $tr_scope;
		} ) ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-tab', $tr_def( 'minn_conf_tr_tab', '__return_true', array(
		'list' => array( 'tab' => array( 'param' => 'owner', 'column' => 'owner', 'gate' => '__return_true' ) ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-where-bad', $tr_def( 'minn_conf_tr_wbad', '__return_true', array(
		'source' => array( 'where' => function () {
			return 'not conditions';
		} ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-where-ok', $tr_def( 'minn_conf_tr_wok', '__return_true', array(
		'source' => array( 'where' => function () {
			return array( 'owner' => get_current_blog_id() );
		} ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-throws', $tr_def( 'minn_conf_tr_throws', function () {
		throw new RuntimeException( 'vendor class renamed' );
	} ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-off', array_merge( $tr_def( 'minn_conf_tr_off', '__return_false' ), array( 'active' => false ) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-core', $tr_def( 'posts', '__return_false' ) );
	// A plugin may list its own table in $wpdb->tables; that does not make it core's.
	$wpdb->tables[] = 'minn_conf_tr_listed';
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-listed', $tr_def( 'minn_conf_tr_listed', '__return_false' ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-net', $tr_def( 'minn_conf_tr_net', '__return_false', array( 'source' => array( 'network' => true ) ) ) );
	$tr = function ( $name ) {
		return Minn_Admin_Kernel::table_refused( $name );
	};
	$p  = $wpdb->prefix;
	$check( 'a whole table: one a resource shows whole is shown, and so are its joins and meta', ! $tr( $p . 'minn_conf_tr_open' ) && ! $tr( $p . 'minn_conf_tr_meta' ) && ! $tr( $p . 'minn_conf_tr_shared' ) );
	$check( "a whole table: one whose resource's gate says no is refused, in any case", $tr( $p . 'minn_conf_tr_shut' ) && $tr( strtoupper( $p . 'minn_conf_tr_shut' ) ) );
	$check( 'a whole table: a join only a refusing resource reads is refused, one another resource admits is not', $tr( $p . 'minn_conf_tr_shutjoin' ) && ! $tr( $p . 'minn_conf_tr_shared' ) );
	$check( 'a whole table: a core table a resource joins or reads a slice of is never refused', ! $tr( $wpdb->users ) && ! $tr( $wpdb->posts ) );
	$check( "a whole table: a plugin's table listed in \$wpdb->tables is still the plugin's, and refused with its resource", $tr( $p . 'minn_conf_tr_listed' ) );
	$wpdb->tables = array_values( array_diff( $wpdb->tables, array( 'minn_conf_tr_listed' ) ) );
	$tr_scope_some = $tr( $p . 'minn_conf_tr_scope' );
	$tr_scope      = null;
	$tr_scope_all  = $tr( $p . 'minn_conf_tr_scope' );
	$tr_scope      = 'not a list';
	$tr_scope_odd  = $tr( $p . 'minn_conf_tr_scope' );
	$check( 'a whole table: a scope short of every row is refused, no restriction is not, an answer that is not a list is refused', $tr_scope_some && ! $tr_scope_all && $tr_scope_odd, wp_json_encode( array( $tr_scope_some, $tr_scope_all, $tr_scope_odd ) ) );
	$check( 'a whole table: a tab gate with no scope to say which tabs pass is refused', $tr( $p . 'minn_conf_tr_tab' ) );
	$check( 'a whole table: a fixed condition that cannot be read is refused, one that can is the slice and not asked', $tr( $p . 'minn_conf_tr_wbad' ) && ! $tr( $p . 'minn_conf_tr_wok' ) );
	$check( 'a whole table: a gate that throws refuses', $tr( $p . 'minn_conf_tr_throws' ) );
	$check( "a whole table: an inactive plugin's resource still refuses, since its tables outlive it", $tr( $p . 'minn_conf_tr_off' ) );
	$check( "a whole table: a network table is the one on the base prefix, and the same name under a site's prefix answers as its copy", $tr( $wpdb->base_prefix . 'minn_conf_tr_net' ) && $tr( $wpdb->prefix . 'minn_conf_tr_net' ) );
	$check( 'a whole table: a table no resource reads is not refused', ! $tr( $p . 'minn_conf_tr_nobody' ) && ! $tr( '' ) );

	// A fixed condition that is the viewer's own scope ('viewer'), and the
	// tables such a resource joins.
	$tr_mine = array();
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-viewer', $tr_def( 'minn_conf_tr_viewer', '__return_true', array(
		'source' => array(
			'joins'  => array( 'v' => array( 'table' => 'minn_conf_tr_vjoin', 'on' => array( 'id' => 'e.owner' ) ) ),
			'where'  => function () use ( &$tr_mine ) {
				return $tr_mine;
			},
			'viewer' => true,
		),
	) ) );
	$tr_all      = ! $tr( $p . 'minn_conf_tr_viewer' ) && ! $tr( $p . 'minn_conf_tr_vjoin' );
	$tr_mine     = array( 'owner' => 5 );
	$tr_narrowed = $tr( $p . 'minn_conf_tr_viewer' ) && $tr( $p . 'minn_conf_tr_vjoin' );
	$check( "a whole table: a viewer's own scope admits only someone it narrows nothing for, and its joins follow", $tr_all && $tr_narrowed, wp_json_encode( array( $tr_all, $tr_narrowed ) ) );
	$check( "a whole table: a 'viewer' flag is a declaration key the kernel reads", ! in_array( true, array_map( function ( $m ) {
		return false !== strpos( $m, 'tr-viewer' );
	}, Minn_Admin_Kernel::refused() ), true ) );
	// A join is shown only through a resource that shows all of its own table.
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-scopejoin', $tr_def( 'minn_conf_tr_scopejoin', '__return_true', array(
		'source' => array( 'joins' => array( 'j' => array( 'table' => 'minn_conf_tr_sjoin', 'on' => array( 'id' => 'e.owner' ) ) ) ),
		'list'   => array( 'scope' => array( 'column' => 'owner', 'allowed' => function () {
			return array( 1 );
		} ) ),
	) ) );
	$check( "a whole table: a join is refused when its only reader's scope is short, though that reader's gate says yes", $tr( $p . 'minn_conf_tr_sjoin' ) );
	// Copies answer as the table does; a one-word name is matched whole only.
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-word', $tr_def( 'minnconfword', '__return_false' ) );
	$tr_copies = array(
		$tr( $p . 'minn_conf_tr_shut_old' ),
		$tr( $p . 'bak_minn_conf_tr_shut' ),
		$tr( strtoupper( $p ) . 'MINN_CONF_TR_SHUT2' ),
		$tr( $wpdb->base_prefix . '7_minn_conf_tr_shut' ),
		$tr( $p . 'minn_conf_tr_shutjoin_bak' ),
	);
	$check( 'a whole table: a copy of a refused table is refused, under a word before or after, another site\'s prefix, any case', ! in_array( false, $tr_copies, true ), wp_json_encode( $tr_copies ) );
	$check( 'a whole table: a copy of a table shown whole is shown', ! $tr( $p . 'minn_conf_tr_open_bak' ) );
	$check( 'a whole table: a one-word name is refused whole, never found inside another name', $tr( $p . 'minnconfword' ) && ! $tr( $p . 'minnconfword_old' ) && ! $tr( $p . 'old_minnconfword' ) );

	// The vendor's other tables holding the same data ('tables') take the
	// source's own answer, copies included.
	$tr_more_gate = false;
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-more', $tr_def( 'minn_conf_tr_more', function () use ( &$tr_more_gate ) {
		return $tr_more_gate;
	}, array( 'source' => array( 'tables' => array( 'minn_conf_tr_more_fields', array( 'table' => 'minn_conf_tr_more_net', 'network' => true ) ) ) ) ) );
	$tr_more_shut = array( $tr( $p . 'minn_conf_tr_more_fields' ), $tr( $wpdb->base_prefix . 'minn_conf_tr_more_net' ), $tr( $p . 'minn_conf_tr_more_fields_bak' ) );
	$tr_more_gate = true;
	$tr_more_open = array( $tr( $p . 'minn_conf_tr_more_fields' ), $tr( $wpdb->base_prefix . 'minn_conf_tr_more_net' ) );
	$check( "a whole table: a resource's other tables are refused with it, copies and a network one included, and shown with it", ! in_array( false, $tr_more_shut, true ) && ! in_array( true, $tr_more_open, true ), wp_json_encode( array( $tr_more_shut, $tr_more_open ) ) );
	$tr_more_viewer = array( 'owner' => 3 );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-more-viewer', $tr_def( 'minn_conf_tr_mview', '__return_true', array(
		'source' => array(
			'tables' => array( 'minn_conf_tr_mview_pay' ),
			'where'  => function () use ( &$tr_more_viewer ) {
				return $tr_more_viewer;
			},
			'viewer' => true,
		),
	) ) );
	$check( "a whole table: a viewer-scoped resource's other tables follow its scope", $tr( $p . 'minn_conf_tr_mview_pay' ) );
	$tr_more_refused = function ( $id ) {
		foreach ( Minn_Admin_Kernel::refused() as $m ) {
			if ( false !== strpos( $m, $id ) ) {
				return true;
			}
		}
		return false;
	};
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-more-bad', $tr_def( 'minn_conf_tr_mbad', '__return_true', array( 'source' => array( 'tables' => 'minn_conf_tr_mbad_fields' ) ) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-more-nosrc', array(
		'gates'  => array( 'view' => '__return_true' ),
		'source' => array( 'rows' => '__return_empty_array', 'tables' => array( 'minn_conf_tr_mnosrc' ) ),
		'list'   => array( 'fields' => array( 'id' => 'int' ) ),
	) );
	Minn_Admin_Kernel::resources();
	$check( "a whole table: 'tables' that is not a list of names, or beside no source table, is a declaration the kernel refuses", $tr_more_refused( 'tr-more-bad' ) && $tr_more_refused( 'tr-more-nosrc' ) && ! $tr_more_refused( 'tr-more:' ) && ! $tr_more_refused( 'tr-more-viewer' ) );

	// A whole table holds every column: the ones the item and each further
	// read show behind their own gates (a message body, a booking's meta),
	// and the ones the rows mask past any gate (a gift card code), which the
	// resource's 'table' gate answers for. Its other tables, joins and
	// copies follow.
	$tr_one  = function ( $row ) {
		return array( 'id' => (int) $row['id'] );
	};
	$tr_item = false;
	$tr_read = false;
	$tr_tbl  = false;
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-item', $tr_def( 'minn_conf_tr_item', '__return_true', array(
		'gates' => array( 'detail' => function () use ( &$tr_item ) {
			return $tr_item;
		} ),
		'item'  => array( 'gate' => 'detail', 'view' => $tr_one, 'fields' => array( 'id' => 'int' ) ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-read', $tr_def( 'minn_conf_tr_read', '__return_true', array(
		'gates' => array( 'preview' => function () use ( &$tr_read ) {
			return $tr_read;
		} ),
		'item'  => array( 'view' => $tr_one, 'fields' => array( 'id' => 'int' ) ),
		'reads' => array( 'body' => array( 'gate' => 'preview', 'view' => $tr_one, 'fields' => array( 'id' => 'int' ) ) ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-whole', $tr_def( 'minn_conf_tr_whole', '__return_true', array(
		'gates'  => array( 'table' => function () use ( &$tr_tbl ) {
			return $tr_tbl;
		} ),
		'source' => array(
			'tables' => array( 'minn_conf_tr_whole_meta' ),
			'joins'  => array( 'w' => array( 'table' => 'minn_conf_tr_wjoin', 'on' => array( 'id' => 'e.owner' ) ) ),
		),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-whole-throws', $tr_def( 'minn_conf_tr_wthrow', '__return_true', array( 'gates' => array( 'table' => function () {
		throw new RuntimeException( 'vendor class renamed' );
	} ) ) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-whole-odd', $tr_def( 'minn_conf_tr_wodd', '__return_true', array( 'gates' => array( 'table' => function () {
		return 1;
	} ) ) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-item-none', $tr_def( 'minn_conf_tr_inone', '__return_true', array(
		'item' => array( 'gate' => 'nobody-declared', 'view' => $tr_one, 'fields' => array( 'id' => 'int' ) ),
	) ) );
	$tr_shut_all = array(
		$tr( $p . 'minn_conf_tr_item' ),
		$tr( $p . 'minn_conf_tr_read' ),
		$tr( $p . 'minn_conf_tr_whole' ),
		$tr( $p . 'minn_conf_tr_whole_meta' ),
		$tr( $p . 'minn_conf_tr_wjoin' ),
		$tr( $p . 'minn_conf_tr_whole_bak' ),
	);
	$check( "a whole table: an item gate, a further read's gate or the 'table' gate that says no refuses it though the view gate says yes, with its other tables, joins and copies", ! in_array( false, $tr_shut_all, true ), wp_json_encode( $tr_shut_all ) );
	list( $tr_list_st ) = $call( 'GET', '/minn-admin/v1/minn-conformance/tr-whole' );
	$check( "a whole table: the 'table' gate answers for whole tables only, never the resource's own list", 200 === $tr_list_st, (string) $tr_list_st );
	$tr_item     = true;
	$tr_read     = true;
	$tr_tbl      = true;
	$tr_open_all = array(
		$tr( $p . 'minn_conf_tr_item' ),
		$tr( $p . 'minn_conf_tr_read' ),
		$tr( $p . 'minn_conf_tr_whole' ),
		$tr( $p . 'minn_conf_tr_whole_meta' ),
		$tr( $p . 'minn_conf_tr_wjoin' ),
	);
	$check( 'a whole table: shown once every gate a read of it answers to says yes', ! in_array( true, $tr_open_all, true ), wp_json_encode( $tr_open_all ) );
	$check( "a whole table: a 'table' gate that throws or answers anything but true refuses, and so does an item gate nobody declared", $tr( $p . 'minn_conf_tr_wthrow' ) && $tr( $p . 'minn_conf_tr_wodd' ) && $tr( $p . 'minn_conf_tr_inone' ) );

	// A viewer's own scope that is never empty (a log's list of types) is
	// admitted by the adapter's own answer that it shows this viewer all the
	// plugin shows anyone ('viewer' as a callable), and only by a strict true.
	$tr_vsees = false;
	$tr_vwhere = array( 'owner' => array( 1, 2 ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-vcall', $tr_def( 'minn_conf_tr_vcall', '__return_true', array(
		'source' => array(
			'tables' => array( 'minn_conf_tr_vcall_meta' ),
			'where'  => function () use ( &$tr_vwhere ) {
				return $tr_vwhere;
			},
			'viewer' => function () use ( &$tr_vsees ) {
				return $tr_vsees;
			},
		),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-vthrow', $tr_def( 'minn_conf_tr_vthrow', '__return_true', array(
		'source' => array( 'where' => array( 'owner' => 1 ), 'viewer' => function () {
			throw new RuntimeException( 'vendor class renamed' );
		} ),
	) ) );
	Minn_Admin_Kernel::resource( 'minn-conformance/tr-vodd', $tr_def( 'minn_conf_tr_vodd', '__return_true', array(
		'source' => array( 'where' => array( 'owner' => 1 ), 'viewer' => function () {
			return 'yes';
		} ),
	) ) );
	$tr_v_no   = array( $tr( $p . 'minn_conf_tr_vcall' ), $tr( $p . 'minn_conf_tr_vcall_meta' ) );
	$tr_vsees  = true;
	$tr_v_yes  = array( $tr( $p . 'minn_conf_tr_vcall' ), $tr( $p . 'minn_conf_tr_vcall_meta' ) );
	$tr_vwhere = 'not conditions';
	$tr_v_bad  = $tr( $p . 'minn_conf_tr_vcall' );
	$check( "a whole table: a viewer scope the adapter says shows this viewer everything is shown, one it does not is refused, and an unreadable scope stays refused", ! in_array( false, $tr_v_no, true ) && ! in_array( true, $tr_v_yes, true ) && $tr_v_bad, wp_json_encode( array( $tr_v_no, $tr_v_yes, $tr_v_bad ) ) );
	$check( "a whole table: a 'viewer' answer that throws or is anything but true refuses", $tr( $p . 'minn_conf_tr_vthrow' ) && $tr( $p . 'minn_conf_tr_vodd' ) );
}

// --- 1k. Blind spots: meta search, 'ends', PATCH, every field encoding ------
$bt = $wpdb->prefix . 'minn_conf_blind';
$bm = $wpdb->prefix . 'minn_conf_blind_meta';
$wpdb->query( "CREATE TEMPORARY TABLE {$bt} ( id BIGINT UNSIGNED NOT NULL PRIMARY KEY, name VARCHAR(40) NOT NULL, path VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
$wpdb->query( "CREATE TEMPORARY TABLE {$bm} ( meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, item_id BIGINT UNSIGNED NOT NULL, k VARCHAR(40) NOT NULL, v VARCHAR(200) NOT NULL ) ENGINE=InnoDB" ); // phpcs:ignore
foreach ( array( array( 1, 'Ann', '/a/report.pdf', 'open' ), array( 2, 'Bo', '/b/photo.png', 'open' ), array( 3, 'Cy', '/c/report.PDF.bak', 'open' ), array( 4, 'Di', '/d/x.pdf', 'done' ), array( 5, 'Ed', '/e/x_pdf', 'open' ) ) as $s ) {
	$wpdb->insert( $bt, array( 'id' => $s[0], 'name' => $s[1], 'path' => $s[2], 'status' => $s[3] ) );
}
foreach ( array( array( 1, 'email', 'ann@x.test' ), array( 2, '_hidden', 'needle-hidden' ), array( 2, 'email', 'bo@x.test' ), array( 3, 'email', 'cy@needle.test' ), array( 4, 'phone', 'needle 555' ) ) as $s ) {
	$wpdb->insert( $bm, array( 'item_id' => $s[0], 'k' => $s[1], 'v' => $s[2] ) );
}
$bl_renamed = array();
$bl_def     = array(
	'gates'   => array( 'view' => '__return_true', 'manage' => '__return_true' ),
	'source'  => array( 'table' => 'minn_conf_blind', 'key' => 'id', 'columns' => array( 'id', 'name', 'path', 'status' ) ),
	'list'    => array(
		'order'  => array( 'column' => 'id', 'dir' => 'ASC' ),
		'search' => array( 'columns' => array( 'name' ), 'meta' => array( 'table' => 'minn_conf_blind_meta', 'key' => 'item_id', 'name' => 'k', 'value' => 'v', 'skip' => '_' ) ),
		'facets' => array( array( 'param' => 'kind', 'values' => array( 'pdf' => array( 'path' => array( 'ends' => '.pdf' ) ), 'odd' => array( 'path' => array( 'ends' => '_pdf' ) ) ) ) ),
		'fields' => array( 'id' => 'int' ),
	),
	'item'    => array(
		'view'   => function ( $row ) {
			return array( 'id' => (int) $row['id'], 'name' => $row['name'] );
		},
		'fields' => array( 'id' => 'int', 'name' => 'text' ),
	),
	'actions' => array(
		'rename' => array(
			'method' => 'PATCH',
			'gate'   => 'manage',
			'when'   => array( 'status' => array( 'open' ) ),
			'args'   => array( 'name' => array( 'type' => 'string', 'required' => true ) ),
			'run'    => function ( $id, $row, $r ) use ( $wpdb, $bt, &$bl_renamed ) {
				$bl_renamed[] = $id;
				$wpdb->update( $bt, array( 'name' => (string) $r['name'] ), array( 'id' => $id ) );
				return true;
			},
			'expect' => function ( $id, $fresh, $ran ) {
				return is_array( $fresh ) && 'Renamed' === $fresh['name'];
			},
		),
		'again'  => array(
			'method' => 'PATCH',
			'gate'   => 'manage',
			'run'    => '__return_true',
			'expect' => array( 'status' => 'open' ),
		),
	),
);
Minn_Admin_Kernel::resource( 'minn-conformance/blind', $bl_def );
$bl_only                             = $bl_def;
$bl_only['list']['search']['meta']   = array( 'table' => 'minn_conf_blind_meta', 'key' => 'item_id', 'name' => 'k', 'value' => 'v', 'only' => 'email' );
Minn_Admin_Kernel::resource( 'minn-conformance/blind-only', $bl_only );
$bl = '/minn-admin/v1/minn-conformance/blind';
$bl_ids = function ( $route, array $params ) use ( $call ) {
	list( , $d ) = $call( 'GET', $route, $params );
	return array_column( (array) ( $d['items'] ?? array() ), 'id' );
};
$bl_meta = array(
	'needle' => $bl_ids( $bl, array( 'search' => 'needle' ) ),
	'ann'    => $bl_ids( $bl, array( 'search' => 'ann' ) ),
	'bo@'    => $bl_ids( $bl, array( 'search' => 'bo@' ) ),
	'only'   => $bl_ids( $bl . '-only', array( 'search' => 'needle' ) ),
	'%'      => $bl_ids( $bl, array( 'search' => '%' ) ),
);
$check( "a search matches a row by its meta values: a name it skips never, one it is limited to only, the row's own meta and no other row's, a wildcard as a literal", array( 3, 4 ) === $bl_meta['needle'] && array( 1 ) === $bl_meta['ann'] && array( 2 ) === $bl_meta['bo@'] && array( 3 ) === $bl_meta['only'] && array() === $bl_meta['%'], wp_json_encode( $bl_meta ) );
$bl_ends = array( $bl_ids( $bl, array( 'kind' => 'pdf' ) ), $bl_ids( $bl, array( 'kind' => 'odd' ) ) );
$bl_php  = array(
	Minn_Admin_Kernel_SQL::matches( array( 'p' => '/A/X.PDF' ), array( 'p' => array( 'ends' => '.pdf' ) ) ),
	Minn_Admin_Kernel_SQL::matches( array( 'p' => '/c/x.pdf.bak' ), array( 'p' => array( 'ends' => '.pdf' ) ) ),
	Minn_Admin_Kernel_SQL::matches( array( 'p' => 'pdf' ), array( 'p' => array( 'ends' => 'x.pdf' ) ) ),
	Minn_Admin_Kernel_SQL::matches( array( 'p' => 'anything' ), array( 'p' => array( 'ends' => '' ) ) ),
);
$check( "'ends' matches a value's end only, its wildcards literal, in SQL and in PHP alike", array( array( 1, 4 ), array( 5 ) ) === $bl_ends && array( true, false, false, true ) === $bl_php, wp_json_encode( array( $bl_ends, $bl_php ) ) );
$wpdb->query( 'START TRANSACTION' );
list( $st, $d )   = $call( 'PATCH', $bl . '/2', array(), array( 'name' => 'Renamed' ) );
list( $st2, $d2 ) = $call( 'PATCH', $bl . '/4', array(), array( 'name' => 'Renamed' ) );
list( $st3, $d3 ) = $call( 'PATCH', $bl . '/2', array(), array() );
list( $st4 )      = $call( 'POST', $bl . '/2/rename', array(), array( 'name' => 'Renamed' ) );
list( $st5 )      = $call( 'PATCH', $bl . '/2/rename', array(), array( 'name' => 'Renamed' ) );
$wpdb->query( 'ROLLBACK' );
$bl_ui = Minn_Admin_Kernel::ui( 'minn-conformance/blind', 'rename', array( 'label' => 'Rename' ) );
$check( 'a PATCH action answers on the item route, in its state only, its args checked, one PATCH per route, and a descriptor points at it', 200 === $st && true === ( $d['ok'] ?? null ) && 400 === $st2 && 'bad_status' === ( $d2['code'] ?? '' ) && 400 === $st3 && 'rest_missing_callback_param' === ( $d3['code'] ?? '' ) && 404 === $st4 && 404 === $st5 && array( 2 ) === $bl_renamed && 'PATCH' === $bl_ui['method'] && 'minn-admin/v1/minn-conformance/blind/{id}' === $bl_ui['route'] && $rv_drew( 'minn-conformance/blind action again: PATCH on its route is taken' ), wp_json_encode( array( $st, $d, $st2, $st3, $st4, $st5, $bl_renamed, $bl_ui ) ) );

// Every field encoding, on a site ten hours ahead of UTC.
$bl_zone = function () {
	return '';
};
$bl_ten  = function () {
	return 10;
};
add_filter( 'pre_option_timezone_string', $bl_zone );
add_filter( 'pre_option_gmt_offset', $bl_ten );
$enc  = function ( $spec, $v ) {
	return Minn_Admin_Kernel_Fields::encode( Minn_Admin_Kernel_Fields::spec( $spec ), $v );
};
$at   = '2026-01-02 03:04:05';
$ts   = 1767323045; // 2026-01-02 03:04:05 UTC.
$dt   = function ( $stored, $wire ) {
	return array( 'type' => 'datetime', 'stored' => $stored, 'wire' => $wire );
};
$encs = array(
	'bool 0'                     => array( false, $enc( 'bool', '0' ) ),
	'bool 1'                     => array( true, $enc( 'bool', 1 ) ),
	'int of text'                => array( 0, $enc( 'int', 'x' ) ),
	'text of a number'           => array( '5', $enc( 'text', 5 ) ),
	'text keeps entities'        => array( 'a &amp; b', $enc( 'text', 'a &amp; b' ) ),
	'nullable text null'         => array( null, $enc( array( 'type' => 'text', 'nullable' => true ), null ) ),
	'nullable int set'           => array( 7, $enc( array( 'type' => 'int', 'nullable' => true ), '7' ) ),
	'text null'                  => array( '', $enc( 'text', null ) ),
	'int null'                   => array( 0, $enc( 'int', null ) ),
	'enum in its set'            => array( 'b', $enc( array( 'type' => 'enum', 'values' => array( 'a', 'b' ) ), 'b' ) ),
	'enum of a number'           => array( '2', $enc( array( 'type' => 'enum', 'values' => array( 1, 2 ) ), 2 ) ),
	'raw'                        => array( array( 'x' => array( 1 ) ), $enc( 'raw', array( 'x' => array( 1 ) ) ) ),
	'a spec with no type is raw' => array( array( 1 ), $enc( array(), array( 1 ) ) ),
	'site naked'                 => array( '2026-01-02T03:04:05', $enc( $dt( 'site', 'naked' ), $at ) ),
	'site sql'                   => array( '2026-01-02 03:04:05', $enc( $dt( 'site', 'sql' ), $at ) ),
	'site iso'                   => array( '2026-01-02T03:04:05+10:00', $enc( $dt( 'site', 'iso' ), $at ) ),
	'site iso-utc'               => array( '2026-01-01T17:04:05+00:00', $enc( $dt( 'site', 'iso-utc' ), $at ) ),
	'site z'                     => array( '2026-01-01T17:04:05Z', $enc( $dt( 'site', 'z' ), $at ) ),
	'utc iso'                    => array( '2026-01-02T13:04:05+10:00', $enc( $dt( 'utc', 'iso' ), $at ) ),
	'utc z'                      => array( '2026-01-02T03:04:05Z', $enc( $dt( 'utc', 'z' ), $at ) ),
	'utc z of a zero date'       => array( '', $enc( $dt( 'utc', 'z' ), '0000-00-00 00:00:00' ) ),
	'utc iso of words'           => array( '', $enc( $dt( 'utc', 'iso' ), 'not a date' ) ),
	'epoch naked'                => array( '2026-01-02T13:04:05', $enc( $dt( 'epoch', 'naked' ), $ts ) ),
	'epoch sql'                  => array( '2026-01-02 13:04:05', $enc( $dt( 'epoch', 'sql' ), (string) $ts ) ),
	'epoch iso'                  => array( '2026-01-02T13:04:05+10:00', $enc( $dt( 'epoch', 'iso' ), $ts ) ),
	'epoch iso-utc'              => array( '2026-01-02T03:04:05+00:00', $enc( $dt( 'epoch', 'iso-utc' ), $ts ) ),
	'epoch z'                    => array( '2026-01-02T03:04:05Z', $enc( $dt( 'epoch', 'z' ), $ts ) ),
	'local-epoch naked'          => array( '2026-01-02T03:04:05', $enc( $dt( 'local-epoch', 'naked' ), $ts ) ),
	'local-epoch iso-utc'        => array( '2026-01-01T17:04:05+00:00', $enc( $dt( 'local-epoch', 'iso-utc' ), $ts ) ),
	'local-epoch z'              => array( '2026-01-01T17:04:05Z', $enc( $dt( 'local-epoch', 'z' ), $ts ) ),
	'a datetime with no wire'    => array( '2026-01-02T03:04:05+10:00', $enc( array( 'type' => 'datetime' ), $at ) ),
);
remove_filter( 'pre_option_timezone_string', $bl_zone );
remove_filter( 'pre_option_gmt_offset', $bl_ten );
$enc_bad = array();
foreach ( $encs as $what => $pair ) {
	if ( $pair[0] !== $pair[1] ) {
		$enc_bad[ $what ] = $pair[1];
	}
}
$check( 'every field encoding gives its one shape: bool, int, text, nullable, enum, raw, and each datetime clock on each wire', ! $enc_bad, wp_json_encode( $enc_bad ) );
$check( 'a field the row lacks is left out, and a declared null leaves as its type', array( 'a' => '', 'n' => null ) === Minn_Admin_Kernel_Fields::out( array( 'a' => 'text', 'n' => array( 'type' => 'int', 'nullable' => true ), 'gone' => 'int' ), array( 'a' => null, 'n' => null ) ) );

// --- 2. Every real declaration ----------------------------------------------
$real = array_filter( Minn_Admin_Kernel::resources(), function ( $r ) {
	return 0 !== strpos( $r['id'], 'minn-conformance/' );
} );
foreach ( $real as $id => $res ) {
	foreach ( (array) ( $res['actions'] ?? array() ) as $slug => $a ) {
		if ( 'gone' === ( $a['expect'] ?? null ) && ! empty( $res['source']['get'] ) ) {
			$check( "{$id} {$slug}: a callable source proves gone from the store (source.exists)", is_callable( $res['source']['exists'] ?? null ) );
		}
		$gate = (string) ( $a['gate'] ?? '' );
		$check( "{$id} {$slug}: names a declared gate", '' !== $gate && is_callable( $res['gates'][ $gate ] ?? null ), $gate );
		$check( "{$id} {$slug}: declares what must hold after it runs", ! empty( $a['expect'] ) );
	}
	foreach ( (array) ( $res['reads'] ?? array() ) as $slug => $read ) {
		$check( "{$id} read {$slug}: declares the fields it sends", ! empty( $read['fields'] ) );
	}
	// The database browser shows a resource's tables raw, every column the
	// item and its reads keep behind a stricter gate included: a resource
	// whose item or a read answers to a gate other than its view gate says
	// what a reader of its whole table must pass ('table'), the columns its
	// rows mask past that gate as well.
	if ( isset( $res['source']['table'] ) ) {
		$item  = is_array( $res['item'] ?? null ) ? $res['item'] : array();
		$names = array( (string) ( $item['gate'] ?? 'view' ) );
		foreach ( (array) ( $res['reads'] ?? array() ) as $read ) {
			$names[] = (string) ( ( is_array( $read ) ? ( $read['gate'] ?? null ) : null ) ?? ( $item['gate'] ?? 'view' ) );
		}
		$stricter = array_values( array_filter( array_unique( $names ), function ( $n ) use ( $res ) {
			return 'view' !== $n && ( $res['gates'][ $n ] ?? null ) !== ( $res['gates']['view'] ?? null );
		} ) );
		if ( $stricter ) {
			$check( "{$id}: an item or read behind '" . implode( "', '", $stricter ) . "' declares what a reader of its whole table must pass (gates.table)", is_callable( $res['gates']['table'] ?? null ) );
		}
	}
}
$kernel_actions = array();
foreach ( $real as $id => $res ) {
	foreach ( (array) ( $res['actions'] ?? array() ) as $slug => $a ) {
		$m = strtoupper( (string) ( $a['method'] ?? 'POST' ) );
		$key = $m . ' ' . ( method_exists( 'Minn_Admin_Kernel', 'action_path' ) ? Minn_Admin_Kernel::action_path( $id, $slug ) : 'minn-admin/v1/' . $id . '/{id}' . ( 'DELETE' === $m ? '' : '/' . $slug ) );
		$check( "{$id} {$slug}: the only {$m} action on its route", ! isset( $kernel_actions[ $key ] ), $key );
		$kernel_actions[ $key ] = $a;
	}
	if ( ! empty( $res['create'] ) ) {
		$c = (string) ( $res['create']['gate'] ?? '' );
		$check( "{$id} create: names a declared gate", '' !== $c && is_callable( $res['gates'][ $c ] ?? null ), $c );
	}
}
// Kernel endpoints a descriptor may also point at (a body-driven action).
foreach ( Minn_Admin_Kernel::endpoints() as $ep ) {
	if ( 0 === strpos( $ep['path'], 'minn-conformance/' ) ) {
		continue;
	}
	foreach ( (array) ( $ep['methods'] ?? 'GET' ) as $m ) {
		foreach ( array_map( 'trim', explode( ',', strtoupper( (string) $m ) ) ) as $one ) {
			$kernel_actions[ $one . ' minn-admin/v1/' . preg_replace( '/\(\?P<id>[^)]*\)/', '{id}', $ep['path'] ) ] = array( 'endpoint' => true );
		}
	}
}
$walk = function ( $node, $where, $sid ) use ( &$walk, $check, $kernel_actions, $real ) {
	if ( ! is_array( $node ) ) {
		return;
	}
	// A collection reading a kernel resource agrees with it.
	if ( isset( $node['route'], $node['itemsKey'] ) && is_string( $node['route'] ) ) {
		$rid = preg_replace( '#^minn-admin/v1/#', '', strtok( $node['route'], '?' ) );
		if ( isset( $real[ $rid ] ) && ! empty( $real[ $rid ]['list'] ) ) {
			$want = Minn_Admin_Kernel::collection( $rid );
			foreach ( array( 'pageQuery', 'search', 'itemsKey', 'totalKey', 'dateQuery' ) as $k ) {
				if ( isset( $want[ $k ] ) || isset( $node[ $k ] ) ) {
					$check( "{$sid}{$where} {$k} agrees with {$rid}", ( $want[ $k ] ?? null ) === ( $node[ $k ] ?? null ), wp_json_encode( array( $node[ $k ] ?? null, $want[ $k ] ?? null ) ) );
				}
			}
			if ( isset( $node['filter'] ) && ! empty( $real[ $rid ]['list']['filter'] ) ) {
				$vals = (array) $real[ $rid ]['list']['filter']['values'];
				$vals = array_keys( $vals ) === range( 0, count( $vals ) - 1 ) ? $vals : array_keys( $vals );
				foreach ( (array) ( $node['filter']['options'] ?? array() ) as $opt ) {
					$check( "{$sid}{$where} filter option " . ( $opt[0] ?? '?' ) . " is one {$rid} accepts", in_array( (string) ( $opt[0] ?? '' ), array_map( 'strval', $vals ), true ) );
				}
			}
			// A filter bar's statuses are values the resource's filter knows:
			// one it did not would list nothing.
			if ( isset( $node['filterBar']['statuses'] ) && ! empty( $real[ $rid ]['list']['filter'] ) ) {
				$vals = (array) $real[ $rid ]['list']['filter']['values'];
				$vals = array_keys( $vals ) === range( 0, count( $vals ) - 1 ) ? $vals : array_keys( $vals );
				foreach ( (array) $node['filterBar']['statuses'] as $opt ) {
					$check( "{$sid}{$where} filter bar status " . ( $opt[0] ?? '?' ) . " is one {$rid} accepts", in_array( (string) ( $opt[0] ?? '' ), array_map( 'strval', $vals ), true ) );
				}
			}
		}
	}
	// A descriptor action pointing at a kernel route names a declared action
	// with its method, shown only in a state the action accepts.
	if ( isset( $node['route'], $node['method'] ) && is_string( $node['route'] ) ) {
		// A query on the route ("?force=1") is not part of which route it is.
		$key = strtoupper( $node['method'] ) . ' ' . strtok( $node['route'], '?' );
		if ( preg_match( '#^minn-admin/v1/(.+)/\{id\}#', $node['route'], $m ) && isset( $real[ $m[1] ] ) ) {
			$check( "{$sid}{$where} {$key} is a declared action", isset( $kernel_actions[ $key ] ) );
			$a = $kernel_actions[ $key ] ?? null;
			if ( $a && isset( $node['when']['key'], $node['when']['equals'] ) && is_array( $a['when'] ?? null ) ) {
				$ok = isset( $a['when'][ $node['when']['key'] ] ) && in_array( (string) $node['when']['equals'], array_map( 'strval', (array) $a['when'][ $node['when']['key'] ] ), true );
				$check( "{$sid}{$where} {$key} shows only when the action accepts the row", $ok, wp_json_encode( $node['when'] ) );
			}
		}
	}
	foreach ( $node as $k => $v ) {
		$walk( $v, $where . ( is_int( $k ) ? "[{$k}]" : ".{$k}" ), $sid );
	}
};
foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
	if ( is_array( $s ) && isset( $s['id'] ) ) {
		$walk( $s, '', $s['id'] );
	}
}
printf( "kernel resources checked: %d (%s)\n", count( $real ), implode( ', ', array_keys( $real ) ) );

// An endpoint's gate is asked with the request, as WordPress asks a
// permission callback: a gate that takes anything else (a capability with a
// default) would be handed the request in its place.
$odd_gates = array();
$n_gates   = 0;
foreach ( Minn_Admin_Kernel::endpoints() as $ep ) {
	$g = $ep['gate'] ?? null;
	if ( ! is_callable( $g ) || 0 === strpos( (string) ( $ep['path'] ?? '' ), 'minn-conformance/' ) ) {
		continue;
	}
	$n_gates++;
	$g  = is_string( $g ) && false !== strpos( $g, '::' ) ? explode( '::', $g, 2 ) : $g;
	$rf = is_array( $g ) ? new ReflectionMethod( $g[0], $g[1] ) : ( is_object( $g ) && ! $g instanceof Closure ? new ReflectionMethod( $g, '__invoke' ) : new ReflectionFunction( $g ) );
	foreach ( $rf->getParameters() as $prm ) {
		if ( false === strpos( (string) $prm->getType(), 'WP_REST_Request' ) ) {
			$odd_gates[] = $ep['path'];
			break;
		}
	}
}
$check( "every endpoint's gate takes nothing or the request it is asked with ({$n_gates})", $n_gates > 0 && ! $odd_gates, implode( ', ', $odd_gates ) );

// The kernel refused nothing a real adapter declares: no key it does not
// read, no gate it cannot ask, no route twice. It records each refusal
// whenever the REST server started, which under WP-CLI is before this runs,
// and resources() above built every declaration, a plugin's that is not
// active on this lab included.
$real_refused = array_values( array_filter( method_exists( 'Minn_Admin_Kernel', 'refused' ) ? Minn_Admin_Kernel::refused() : array( 'Minn_Admin_Kernel::refused() is missing' ), function ( $m ) {
	return false === strpos( $m, 'minn-conformance' );
} ) );
$check( 'the kernel refused no real declaration, in whole or in part', ! $real_refused, implode( ' | ', array_slice( $real_refused, 0, 5 ) ) );

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nkernel-conformance: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
