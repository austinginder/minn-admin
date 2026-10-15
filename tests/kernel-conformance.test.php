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
 *    fields leaving, a secret never; each field type on awkward stored
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
 *    card sums and counts, key-bound writes and the database's own clock.
 * 2. Every real declaration: each action has a declared gate and a
 *    post-condition, each descriptor action that points at a kernel route
 *    names a declared action with its method and a state the action accepts,
 *    and each collection descriptor's route, paging, search and keys agree
 *    with the resource they read.
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
		'max'      => 3,
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
			'token' => 'secret',
		),
	),
	'item'      => array(
		'view'   => function ( $row ) {
			return array( 'id' => (int) $row['id'], 'status' => $row['status'], 'token' => $row['token'] );
		},
		'fields' => array( 'id' => 'int', 'status' => 'text', 'token' => 'secret' ),
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
// A declaration whose doing-it-wrong notices are part of what is checked.
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
Minn_Admin_Kernel::resource( 'minn-conformance/callable', array_merge( $no_row_gate, array(
	'source' => array(
		'list' => function () use ( $wpdb, $t ) {
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
$check( 'a secret field never leaves', false === strpos( wp_json_encode( $d ), $secret ) );
$check( 'an int field is an int', is_int( $d['items'][0]['id'] ) );
list( , $d2 ) = $call( 'GET', $base, array( 'page' => 2 ) );
$check( 'page two continues where page one stopped', array( 5 ) === array_column( $d2['items'], 'id' ), wp_json_encode( $d2 ) );
list( , $d ) = $call( 'GET', $base, array( 'per_page' => 1000 ) );
$check( 'a page size above the cap is capped', 3 === count( $d['items'] ) );
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
list( , $d ) = $call( 'GET', $base, array( 'after' => '2026-01-02 12:00:00', 'status' => 'spam' ) );
$check( 'a chart day range narrows on the declared column', 1 === $d['total'] );
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

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nkernel-conformance: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
