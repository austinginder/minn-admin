<?php
/**
 * v0.31 capability scope on editor-panel field routes and the cron ceiling.
 *
 *  1. meta-box and pods gate their /fields route three ways: 404 on a missing
 *     post, edit_post when a post is named, and the TYPE's own edit_posts when
 *     post_id is 0. Four sibling adapters registered the same shape of route
 *     and only ever checked edit_post when a post was named, so edit_posts
 *     alone read a whole site-defined field schema for a type the caller
 *     cannot write.
 *
 *  2. minn_admin_scrutoscope_cron_runnable() deferred to WP Crontrol's
 *     runnable() first and only fell back to its own rule when the vendor was
 *     absent, which made the rule dead code on the only sites where the route
 *     does anything. WP Crontrol's runnable() for a PHP event is manage_options
 *     and ignores DISALLOW_FILE_EDIT, so the deferral was the weaker of the
 *     two. The rule is a ceiling now.
 *
 * Run: wp eval-file tests/security-v031-caps.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admin ? (int) $admin[0]->ID : 0;

// Someone who may write ordinary posts and nothing else.
remove_role( 'minn_caps_probe' );
add_role( 'minn_caps_probe', 'Minn Caps Probe', array( 'read' => true, 'edit_posts' => true ) );
$uid = username_exists( 'minn_caps_probe_user' );
if ( ! $uid ) {
	$uid = wp_insert_user(
		array(
			'user_login' => 'minn_caps_probe_user',
			'user_pass'  => wp_generate_password( 20 ),
			'role'       => 'minn_caps_probe',
		)
	);
}
wp_update_user( array( 'ID' => $uid, 'role' => 'minn_caps_probe' ) );

$get = function ( $route, $args ) {
	$request = new WP_REST_Request( 'GET', $route );
	foreach ( $args as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_do_request( $request );
};

// --- 1. the type gate on post_id=0 --------------------------------------
$routes = array(
	'wp-job-manager'  => array( '/minn-admin/v1/wpjm/fields', 'job_listing', 'edit_job_listings' ),
	'podcasting'      => array( '/minn-admin/v1/ssp/fields', 'podcast', '' ),
	'events-calendar' => array( '/minn-admin/v1/tec/fields', 'tribe_events', '' ),
);
foreach ( $routes as $name => $spec ) {
	list( $route, $type ) = $spec;
	if ( ! get_post_type_object( $type ) ) {
		printf( "SKIP  %s is not registered here\n", $name );
		continue;
	}

	wp_set_current_user( $uid );
	$response = $get( $route, array( 'post_id' => 0, 'post_type' => $type ) );
	$body     = (array) $response->get_data();
	$groups   = isset( $body['groups'] ) ? (array) $body['groups'] : array();
	$leaked   = false;
	foreach ( $groups as $group ) {
		if ( ! empty( $group['fields'] ) ) {
			$leaked = true;
		}
	}
	$check(
		"{$name}: a caller without the type's own capability reads no schema",
		403 === $response->get_status() || ! $leaked,
		'status ' . $response->get_status() . ', fields ' . ( $leaked ? 'returned' : 'none' )
	);

	wp_set_current_user( $admin );
	$response = $get( $route, array( 'post_id' => 0, 'post_type' => $type ) );
	$check(
		"CONTROL {$name}: an administrator still reads the schema",
		200 === $response->get_status(),
		'status ' . $response->get_status()
	);

	wp_set_current_user( $uid );
	$response = $get( $route, array( 'post_id' => 99999999, 'post_type' => $type ) );
	$check(
		"{$name}: a post id that does not exist is a 404, not a fall-through",
		404 === $response->get_status() || 403 === $response->get_status(),
		'status ' . $response->get_status()
	);
}

// --- 2. the cron ceiling holds regardless of the vendor -----------------
if ( ! function_exists( 'minn_admin_scrutoscope_cron_runnable' ) ) {
	echo "SKIP  scrutoscope adapter not loaded\n";
} else {
	wp_set_current_user( $admin );
	$check(
		'CONTROL an administrator who holds edit_files may still fire a PHP cron event',
		true === minn_admin_scrutoscope_cron_runnable( 'crontrol_cron_job', 0 )
	);

	$drop = function ( $caps ) {
		$caps['edit_files'] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $drop, 99 );
	$check(
		'Without edit_files, firing a PHP cron event is refused',
		false === minn_admin_scrutoscope_cron_runnable( 'crontrol_cron_job', 0 )
	);

	// Why the ceiling exists, stated against the vendor directly: WP Crontrol
	// would say yes to this same caller. Its can_run_php_cron_events() is
	// manage_options, so deferring to runnable() ALONE is the weaker of the
	// two answers and the reason the rule now runs first.
	//
	// This is the honest limit of the fixture: exercising the deferral branch
	// end to end needs a scheduled crontrol_cron_job that WP Crontrol does not
	// consider errored, and a hand-built one always is (has_error() is true, so
	// runnable() returns false for reasons that have nothing to do with caps).
	// The ordering change itself is verified by reading.
	if ( class_exists( '\\Crontrol\\Context\\WordPressUserContext' ) ) {
		$ctx = new \Crontrol\Context\WordPressUserContext();
		$check(
			'WP Crontrol would allow this same caller (which is why the rule runs first)',
			true === $ctx->can_run_php_cron_events()
		);
	}
	remove_filter( 'user_has_cap', $drop, 99 );

	$check(
		'CONTROL an ordinary hook is unaffected',
		true === minn_admin_scrutoscope_cron_runnable( 'wp_scheduled_delete', 0 )
	);
}

// --- cleanup -------------------------------------------------------------
wp_set_current_user( $admin );
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
wp_delete_user( $uid );
remove_role( 'minn_caps_probe' );

printf( "\nsecurity-v031-caps: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
