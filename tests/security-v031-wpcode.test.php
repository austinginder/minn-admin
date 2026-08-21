<?php
/**
 * v0.31 WPCode adapter write-path regressions.
 *
 * Two holes, both on the snippet write path:
 *
 *  1. PUT /wpcode/snippets/{id} decided whether the request was authoring from
 *     the presence of `code` alone. The editor sends `active` on every save, so
 *     a body of just {"active": true} reached the write with the guard told it
 *     was not a write, publishing a snippet the same caller was refused
 *     permission to edit. The dedicated /active route always refused it.
 *
 *  2. WPCode's own submit listener refuses code matching
 *     WPCode_Snippet_Execute::is_code_not_allowed(). That check lives in the
 *     form handler rather than in WPCode_Snippet::save(), so writing through
 *     the model skipped it.
 *
 * Controls cover the other half: a caller who should still be able to work is
 * not blocked, and an administrator is unaffected.
 *
 * Run: wp eval-file tests/security-v031-wpcode.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v031-wpcode: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};

if ( ! class_exists( 'WPCode_Snippet' ) ) {
	echo "SKIP  WPCode is inactive\n";
	return;
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;

// A caller who may author and activate snippets but holds no markup trust.
// WPCode grants both snippet caps to every role with manage_options, so this
// is the shape of a multisite subsite administrator on a real network.
remove_role( 'minn_sec_probe' );
add_role(
	'minn_sec_probe',
	'Minn Sec Probe',
	array(
		'read'                     => true,
		'wpcode_edit_snippets'     => true,
		'wpcode_activate_snippets' => true,
	)
);
$uid = username_exists( 'minn_sec_probe_user' );
if ( ! $uid ) {
	$uid = wp_insert_user(
		array(
			'user_login' => 'minn_sec_probe_user',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'minn_sec_probe',
		)
	);
}
wp_update_user( array( 'ID' => $uid, 'role' => 'minn_sec_probe' ) );

$created = array();

$make = function ( $type, $active, $code ) use ( &$created ) {
	$snippet = new WPCode_Snippet(
		array(
			'title'       => 'probe ' . $type . ' ' . wp_generate_password( 6, false ),
			'code'        => $code,
			'code_type'   => $type,
			'location'    => 'everywhere',
			'auto_insert' => 1,
			'active'      => $active,
		)
	);
	$id        = (int) $snippet->save();
	$created[] = $id;
	return $id;
};
$put = function ( $id, $body ) {
	$request = new WP_REST_Request( 'PUT', '/minn-admin/v1/wpcode/snippets/' . $id );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
};
$post = function ( $route, $body ) {
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	return rest_do_request( $request );
};

// --- 1. activation through PUT ------------------------------------------
wp_set_current_user( $admin );
$inactive = $make( 'html', false, '<script>probe()</script>' );

wp_set_current_user( $uid );
$check( 'Probe role holds no unfiltered_html', ! current_user_can( 'unfiltered_html' ) );
$check( 'Probe role may edit the snippet', current_user_can( 'edit_post', $inactive ) );

$response = $put( $inactive, array( 'active' => true ) );
$stored   = new WPCode_Snippet( $inactive );
$check(
	'PUT {active:true} on an inactive markup snippet is refused',
	403 === $response->get_status() && ! $stored->is_active(),
	'status ' . $response->get_status() . ', active ' . var_export( $stored->is_active(), true )
);

$response = $post( '/minn-admin/v1/wpcode/snippets/' . $inactive . '/active', array( 'active' => true ) );
$check(
	'The dedicated /active route agrees, so neither is a way around the other',
	403 === $response->get_status(),
	'status ' . $response->get_status()
);

// --- 2. control: ordinary editing stays open ----------------------------
wp_set_current_user( $admin );
$active_snippet = $make( 'html', true, '<b>ok</b>' );

wp_set_current_user( $uid );
$response = $put( $active_snippet, array( 'name' => 'probe renamed', 'active' => true ) );
$stored   = new WPCode_Snippet( $active_snippet );
$stored->get_title();
$check(
	'CONTROL renaming an already-active snippet still saves',
	200 === $response->get_status() && 'probe renamed' === $stored->get_title() && $stored->is_active(),
	'status ' . $response->get_status() . ', title ' . $stored->get_title()
);

// --- 3. restricted code --------------------------------------------------
wp_set_current_user( $admin );
$restricted = str_repeat( "eval(\$x);\n", 6 );
$check(
	'WPCode itself treats the payload as restricted',
	class_exists( 'WPCode_Snippet_Execute' ) && WPCode_Snippet_Execute::is_code_not_allowed( $restricted )
);

$response = $post(
	'/minn-admin/v1/wpcode/snippets',
	array( 'name' => 'probe restricted', 'code_type' => 'php', 'code' => $restricted )
);
if ( 200 === $response->get_status() ) {
	$data      = $response->get_data();
	$created[] = isset( $data['id'] ) ? (int) $data['id'] : 0;
}
$check(
	'Restricted code is refused on create',
	403 === $response->get_status(),
	'status ' . $response->get_status()
);

// --- 3b. retarget an inert on_demand PHP snippet into a running bucket ---
// on_demand has no auto-runner, so moving a live PHP snippet from it to
// `everywhere` starts execution just as switching it on would. The guard
// must treat that as a code write and refuse a caller without
// unfiltered_html, even though the body carries no `code`.
wp_set_current_user( $admin );
$parked = new WPCode_Snippet(
	array(
		'title'       => 'probe parked ' . wp_generate_password( 6, false ),
		'code'        => 'return 1;',
		'code_type'   => 'php',
		'location'    => 'on_demand',
		'auto_insert' => 1,
		'active'      => true,
	)
);
$parked_id = (int) $parked->save();
$created[]  = $parked_id;

wp_set_current_user( $uid );
$response = $put( $parked_id, array( 'location' => 'everywhere' ) );
$stored   = new WPCode_Snippet( $parked_id );
$check(
	'Retargeting on_demand -> everywhere is refused without unfiltered_html',
	403 === $response->get_status() && 'on_demand' === (string) $stored->get_location(),
	'status ' . $response->get_status() . ', location ' . $stored->get_location()
);

// --- 4. controls: the administrator is unaffected ------------------------
$response = $post(
	'/minn-admin/v1/wpcode/snippets',
	array( 'name' => 'probe ordinary', 'code_type' => 'php', 'code' => 'return 1;' )
);
if ( 200 === $response->get_status() ) {
	$data      = $response->get_data();
	$created[] = isset( $data['id'] ) ? (int) $data['id'] : 0;
}
$check(
	'CONTROL an administrator still authors ordinary php',
	200 === $response->get_status(),
	'status ' . $response->get_status()
);

$fresh    = $make( 'html', false, '<b>x</b>' );
$response = $put( $fresh, array( 'active' => true ) );
$stored   = new WPCode_Snippet( $fresh );
$check(
	'CONTROL an administrator still activates through PUT',
	200 === $response->get_status() && $stored->is_active(),
	'status ' . $response->get_status()
);

// --- 5. the type classifier fails closed --------------------------------
// `blocks` used to match no arm of it: not executing, so no DISALLOW_FILE_EDIT
// check; not needs-raw, so no refusal; not the literal 'text', so no kses. It
// was the one type stored with nothing applied to it.
$check( 'blocks is treated as raw markup', true === minn_admin_wpcode_type_needs_raw( 'blocks' ) );
$check( 'text is still filtered rather than refused', false === minn_admin_wpcode_type_needs_raw( 'text' ) );
$check(
	'a code type nobody has classified lands on the safe side',
	true === minn_admin_wpcode_type_executes( 'some_type_added_later' )
);

wp_set_current_user( $uid );
$response = $post(
	'/minn-admin/v1/wpcode/snippets',
	array( 'name' => 'probe blocks', 'code_type' => 'blocks', 'code' => '<!-- wp:html --><script>x()</script><!-- /wp:html -->' )
);
if ( 200 === $response->get_status() ) {
	$data      = $response->get_data();
	$created[] = isset( $data['id'] ) ? (int) $data['id'] : 0;
}
$check(
	'A caller without unfiltered_html cannot author a blocks snippet',
	403 === $response->get_status(),
	'status ' . $response->get_status()
);

wp_set_current_user( $admin );
$response = $post(
	'/minn-admin/v1/wpcode/snippets',
	array( 'name' => 'probe blocks ok', 'code_type' => 'blocks', 'code' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->' )
);
if ( 200 === $response->get_status() ) {
	$data      = $response->get_data();
	$created[] = isset( $data['id'] ) ? (int) $data['id'] : 0;
}
$check(
	'CONTROL an administrator still authors a blocks snippet',
	200 === $response->get_status(),
	'status ' . $response->get_status()
);

// --- cleanup -------------------------------------------------------------
wp_set_current_user( $admin );
foreach ( array_unique( array_filter( $created ) ) as $post_id ) {
	wp_delete_post( $post_id, true );
}
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
wp_delete_user( $uid );
remove_role( 'minn_sec_probe' );

$summary();
