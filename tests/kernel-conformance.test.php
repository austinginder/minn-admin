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
 *    handler tier's gate and ids.
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
}
$kernel_actions = array();
foreach ( $real as $id => $res ) {
	foreach ( (array) ( $res['actions'] ?? array() ) as $slug => $a ) {
		$m = strtoupper( (string) ( $a['method'] ?? 'POST' ) );
		$kernel_actions[ $m . ' minn-admin/v1/' . $id . '/{id}' . ( 'DELETE' === $m ? '' : '/' . $slug ) ] = $a;
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
		$key = strtoupper( $node['method'] ) . ' ' . $node['route'];
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
