<?php
/**
 * v0.34 object and filesystem boundary regressions.
 *
 * Run: wp eval-file tests/security-v034-boundaries.test.php --user=admin --path=<site>
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

// --- 1. every debug-log projection refuses an outside-install file -------
$debug_path = Minn_Admin_Logs::debug_log_path();
$default_log = WP_CONTENT_DIR . '/debug.log';
if ( $debug_path !== $default_log || is_link( $debug_path ) || ! wp_is_writable( dirname( $debug_path ) ) ) {
	echo "SKIP  debug log cannot be replaced with a symlink fixture on this site\n";
} else {
	$outside = tempnam( sys_get_temp_dir(), 'minn-log-boundary-' );
	$backup  = $debug_path . '.minn-security-backup-' . wp_generate_password( 12, false, false );
	$secret  = 'MINN-OUTSIDE-LOG-SECRET-' . wp_generate_password( 20, false, false );
	$had_log = file_exists( $debug_path );
	$moved   = ! $had_log || rename( $debug_path, $backup );
	$linked  = false;
	try {
		file_put_contents( $outside, $secret );
		$linked = $moved && symlink( $outside, $debug_path );
		clearstatcache( true, $debug_path );
		$check( 'Outside-log fixture is outside the site boundary', $linked && ! Minn_Admin_Logs::site_owned( $debug_path ) );

		if ( $linked ) {
			$response = Minn_Admin_REST::read_debug_log( new WP_REST_Request( 'GET', '/minn-admin/v1/system/debug-log' ) );
			$data     = (array) rest_ensure_response( $response )->get_data();
			$check(
				'Legacy debug-log reader returns no outside-install content',
				false === strpos( (string) ( $data['content'] ?? '' ), $secret ),
				'content bytes ' . strlen( (string) ( $data['content'] ?? '' ) )
			);
			$check(
				'Legacy debug-log reader exposes no absolute outside path',
				( $data['path'] ?? '' ) !== $outside && false === strpos( (string) ( $data['path'] ?? '' ), dirname( $outside ) )
			);

			$reflection = new ReflectionMethod( Minn_Admin_REST::class, 'config_state' );
			$reflection->setAccessible( true );
			$config = (array) $reflection->invoke( null );
			$log    = (array) ( $config['log'] ?? array() );
			$check(
				'System config exposes no absolute outside log path or size',
				( $log['path'] ?? '' ) !== $outside
					&& false === strpos( (string) ( $log['path'] ?? '' ), dirname( $outside ) )
					&& empty( $log['size_human'] ),
				'path ' . (string) ( $log['path'] ?? '' ) . ', size ' . (string) ( $log['size_human'] ?? '' )
			);
		}
	} finally {
		if ( $linked && is_link( $debug_path ) ) {
			unlink( $debug_path );
		}
		if ( $had_log && file_exists( $backup ) ) {
			rename( $backup, $debug_path );
		}
		if ( file_exists( $outside ) ) {
			unlink( $outside );
		}
	}
}

// --- 2. duplicate respects a post type's distinct create capability ------
register_post_type(
	'minn_boundary_item',
	array(
		'public'          => false,
		'show_ui'         => false,
		'capability_type' => array( 'minn_boundary_item', 'minn_boundary_items' ),
		'map_meta_cap'    => true,
		'capabilities'    => array( 'create_posts' => 'create_minn_boundary_items' ),
	)
);
remove_role( 'minn_boundary_editor' );
add_role(
	'minn_boundary_editor',
	'Minn Boundary Editor',
	array(
		'read'                            => true,
		'edit_posts'                      => true,
		'list_users'                      => true,
		'edit_minn_boundary_items'        => true,
		'edit_others_minn_boundary_items' => true,
		'edit_published_minn_boundary_items' => true,
		'read_private_minn_boundary_items'   => true,
	)
);
$uid = username_exists( 'minn_boundary_editor_user' );
if ( ! $uid ) {
	$uid = wp_insert_user(
		array(
			'user_login' => 'minn_boundary_editor_user',
			'user_pass'  => wp_generate_password( 24 ),
			'role'       => 'minn_boundary_editor',
		)
	);
}
wp_update_user( array( 'ID' => $uid, 'role' => 'minn_boundary_editor' ) );
wp_set_current_user( $admin );
$source_id = wp_insert_post(
	array(
		'post_type'   => 'minn_boundary_item',
		'post_status' => 'publish',
		'post_title'  => 'Minn boundary source',
	),
	true
);

$duplicate_id = 0;
if ( is_wp_error( $source_id ) ) {
	$check( 'Duplicate fixture post created', false, $source_id->get_error_message() );
} else {
	wp_set_current_user( $uid );
	$type = get_post_type_object( 'minn_boundary_item' );
	$check( 'Fixture may edit the source item', current_user_can( 'edit_post', $source_id ) );
	$check( 'Fixture may not create this post type', ! current_user_can( $type->cap->create_posts ) );
	$request = new WP_REST_Request( 'POST', '/minn-admin/v1/posts/' . $source_id . '/duplicate' );
	$request->set_url_params( array( 'id' => $source_id ) );
	$response = rest_do_request( $request );
	$data     = (array) $response->get_data();
	$duplicate_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
	$check(
		'Duplicate refuses a caller without the post type create capability',
		403 === $response->get_status() && ! $duplicate_id,
		'status ' . $response->get_status() . ', created ' . $duplicate_id
	);
}

// --- 3. image-block never resolves an attachment the caller cannot read --
wp_set_current_user( $admin );
$private_parent = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'private',
		'post_title'  => 'Minn private attachment parent',
	),
	true
);
$attachment_id = is_wp_error( $private_parent ) ? 0 : wp_insert_attachment(
	array(
		'post_title'     => 'Minn private attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => 'image/png',
		'post_parent'    => $private_parent,
	),
	'',
	$private_parent,
	true
);
$attachment_id = is_wp_error( $attachment_id ) ? 0 : (int) $attachment_id;
if ( $attachment_id ) {
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'MINN PRIVATE ALT' );
}
$image_filter = function ( $blocks ) {
	$blocks['minn-test/private-image'] = array(
		'label'   => 'Private image probe',
		'rebuild' => function ( $images ) {
			return '<!-- ' . wp_json_encode( $images ) . ' -->';
		},
	);
	return $blocks;
};
add_filter( 'minn_admin_image_blocks', $image_filter );

wp_set_current_user( $uid );
if ( ! $attachment_id || current_user_can( 'read_post', $attachment_id ) ) {
	echo "SKIP  this WordPress fixture does not produce an unreadable inherited attachment\n";
} else {
	$request = new WP_REST_Request( 'POST', '/minn-admin/v1/image-block' );
	$request->set_param( 'block', 'minn-test/private-image' );
	$request->set_param( 'ids', array( $attachment_id ) );
	$request->set_param( 'raw', '' );
	$response = rest_do_request( $request );
	$data     = (array) $response->get_data();
	$check(
		'Image-block refuses an attachment the caller cannot read',
		400 === $response->get_status()
			&& false === strpos( (string) ( $data['markup'] ?? '' ), 'MINN PRIVATE ALT' ),
		'status ' . $response->get_status()
	);

	$routes = rest_get_server()->get_routes();
	$folder_route = '/minn-admin/v1/media/folders/(?P<id>\d+)/ids';
	if ( ! isset( $routes[ $folder_route ] ) ) {
		echo "SKIP  no media-folder provider registered on this site\n";
	} else {
		$folder_filter = function () use ( $attachment_id ) {
			return array(
				'name'    => 'Boundary fixture',
				'folders' => function () {
					return array();
				},
				'ids'     => function () use ( $attachment_id ) {
					return array( $attachment_id );
				},
			);
		};
		add_filter( 'minn_admin_media_folders', $folder_filter, -999 );
		$request = new WP_REST_Request( 'GET', '/minn-admin/v1/media/folders/777/ids' );
		$request->set_url_params( array( 'id' => 777 ) );
		$response = rest_do_request( $request );
		$data     = (array) $response->get_data();
		$check(
			'Media-folder ids omit attachments the caller cannot read',
			200 === $response->get_status() && ! in_array( $attachment_id, (array) ( $data['ids'] ?? array() ), true ),
			'status ' . $response->get_status() . ', ids ' . implode( ',', (array) ( $data['ids'] ?? array() ) )
		);
		remove_filter( 'minn_admin_media_folders', $folder_filter, -999 );
	}
}
remove_filter( 'minn_admin_image_blocks', $image_filter );

// --- 4. a delegated list_users grant still respects edit_user per account -
wp_set_current_user( $admin );
$forbidden_uid = username_exists( 'minn_boundary_forbidden_user' );
if ( ! $forbidden_uid ) {
	$forbidden_uid = wp_insert_user(
		array(
			'user_login' => 'minn_boundary_forbidden_user',
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'minn-boundary-private@example.test',
			'role'       => 'editor',
		)
	);
}
$forbidden_uid = is_wp_error( $forbidden_uid ) ? 0 : (int) $forbidden_uid;
if ( $forbidden_uid ) {
	update_user_meta(
		$forbidden_uid,
		'session_tokens',
		array(
			hash( 'sha256', 'minn-boundary-session' ) => array(
				'expiration' => time() + HOUR_IN_SECONDS,
				'login'      => time(),
				'ip'         => '192.0.2.1',
				'ua'         => 'Minn boundary fixture',
			),
		)
	);
}
$user_scope = function ( $caps, $cap, $user_id, $args ) use ( $uid, $forbidden_uid ) {
	if ( (int) $user_id !== (int) $uid || 'edit_user' !== $cap ) {
		return $caps;
	}
	$target = isset( $args[0] ) ? (int) $args[0] : 0;
	return $target === $forbidden_uid ? array( 'do_not_allow' ) : array( 'list_users' );
};
add_filter( 'map_meta_cap', $user_scope, 999, 4 );
wp_set_current_user( $uid );
if ( ! $forbidden_uid ) {
	$check( 'User-scope fixture created', false );
} else {
	$check( 'Fixture has the collection capability', current_user_can( 'list_users' ) );
	$check( 'Fixture cannot edit the protected account', ! current_user_can( 'edit_user', $forbidden_uid ) );

	$request = new WP_REST_Request( 'GET', '/minn-admin/v1/users' );
	$request->set_param( 'per_page', 100 );
	$response = rest_do_request( $request );
	$listed   = wp_list_pluck( (array) $response->get_data(), 'id' );
	$check(
		'Users list omits accounts outside delegated edit_user scope',
		200 === $response->get_status() && ! in_array( $forbidden_uid, array_map( 'intval', $listed ), true ),
		'status ' . $response->get_status()
	);

	$request = new WP_REST_Request( 'GET', '/minn-admin/v1/users' );
	$request->set_param( 'per_page', 100 );
	$request->set_param( 'session', 'active' );
	$response = rest_do_request( $request );
	$active   = wp_list_pluck( (array) $response->get_data(), 'id' );
	$check(
		'Active-session filter omits accounts outside delegated edit_user scope',
		200 === $response->get_status() && ! in_array( $forbidden_uid, array_map( 'intval', $active ), true ),
		'status ' . $response->get_status()
	);
}
remove_filter( 'map_meta_cap', $user_scope, 999 );

// --- 5. an options-scope multicheck without an allowlist is filtered ------
// A declared choice list is its own allowlist. With ACF's "Allow Custom" set,
// or no choices declared, the value is whatever was sent, and on the options
// scope it lands in a site-global row a theme prints with the_field(), which
// does not escape. Anyone without unfiltered_html must not get raw markup in
// there; everyone else must be left alone.
if ( ! function_exists( 'minn_admin_acf_value_in' ) ) {
	echo "SKIP  ACF adapter not loaded on this site\n";
} else {
	$payload    = '<img src=x onerror=alert(1)>bad';
	$open_field = array( 'type' => 'multicheck', 'name' => 'boundary_probe', 'key' => 'field_boundary_probe', 'anyChoice' => true, 'choices' => array() );
	$list_field = array( 'type' => 'multicheck', 'name' => 'boundary_probe2', 'key' => 'field_boundary_probe2', 'choices' => array( 'red' => 'Red' ) );

	$writer = wp_insert_user( array(
		'user_login' => 'minn-acf-boundary-' . wp_generate_password( 6, false, false ),
		'user_pass'  => wp_generate_password( 20 ),
		'role'       => 'author', // has edit_posts, does NOT have unfiltered_html
	) );
	if ( is_wp_error( $writer ) ) {
		$check( 'ACF boundary fixture user created', false, $writer->get_error_message() );
		$writer = 0;
	} else {
		$as = function ( $uid, $field, $scope ) use ( $payload ) {
			wp_set_current_user( $uid );
			$out = minn_admin_acf_value_in( $field, array( $payload ), $scope );
			return is_array( $out ) ? implode( '|', $out ) : '';
		};

		$check( 'Fixture writer lacks unfiltered_html', ! user_can( $writer, 'unfiltered_html' ) );
		$check(
			'Options-scope multicheck strips the handler',
			false === strpos( $as( $writer, $open_field, 'options' ), 'onerror' ),
			$as( $writer, $open_field, 'options' )
		);
		// Controls: the fix must not over-block, and must not change the post
		// scope, which is ACF's own behaviour over one post the caller owns.
		$check(
			'A user with unfiltered_html still stores markup',
			false !== strpos( $as( $admin, $open_field, 'options' ), 'onerror' )
		);
		$check(
			'Post scope is unchanged',
			false !== strpos( $as( $writer, $open_field, 'post' ), 'onerror' )
		);
		$check(
			'A declared choice list still drops anything outside it',
			'' === $as( $writer, $list_field, 'options' )
		);
	}
	wp_set_current_user( $admin );
}

// --- 6. comment feeds name their post, so they gate on reading it ---------
// A comment query is not scoped by post status. Core filters every row of its
// recent-comments widget through read_post for exactly that reason, because a
// row carries the post's TITLE. The drill-down is the sharper case: it is a
// hardcoded query, so no comments_clauses filter runs and plugin rows that are
// technically comments (WooCommerce order notes, Action Scheduler logs) come
// with it.
wp_set_current_user( $admin );
$secret_title = 'Minn private boundary ' . wp_generate_password( 10, false, false );
$note_text    = 'MINN-ORDER-NOTE-' . wp_generate_password( 10, false, false );
$private_id   = wp_insert_post( array(
	'post_title'   => $secret_title,
	'post_status'  => 'private',
	'post_type'    => 'post',
	'post_content' => 'boundary fixture',
) );
$public_id = wp_insert_post( array(
	'post_title'   => 'Minn public boundary ' . wp_generate_password( 6, false, false ),
	'post_status'  => 'publish',
	'post_type'    => 'post',
	'post_content' => 'boundary fixture',
) );
$private_comment = 0;
$public_comment  = 0;
$note_comment    = 0;
if ( ! is_wp_error( $private_id ) && ! is_wp_error( $public_id ) ) {
	$private_comment = wp_insert_comment( array(
		'comment_post_ID'  => $private_id,
		'comment_author'   => 'Boundary Commenter',
		'comment_content'  => 'on a private post',
		'comment_approved' => 1,
	) );
	$public_comment = wp_insert_comment( array(
		'comment_post_ID'  => $public_id,
		'comment_author'   => 'Boundary Commenter',
		'comment_content'  => 'on a public post',
		'comment_approved' => 1,
	) );
	// A plugin row that is a comment. Uses the public post deliberately: the
	// point is the comment_type, not the post's readability.
	$note_comment = wp_insert_comment( array(
		'comment_post_ID'  => $public_id,
		'comment_author'   => $note_text,
		'comment_content'  => $note_text,
		'comment_type'     => 'order_note',
		'comment_approved' => 1,
	) );
}

$reader = wp_insert_user( array(
	'user_login' => 'minn-feed-boundary-' . wp_generate_password( 6, false, false ),
	'user_pass'  => wp_generate_password( 20 ),
	'role'       => 'author', // edit_posts, but no read_private_posts
) );

if ( is_wp_error( $reader ) || ! $private_comment || ! $public_comment ) {
	$check( 'Comment-feed fixtures created', false );
	$reader = is_wp_error( $reader ) ? 0 : $reader;
} else {
	$window = new WP_REST_Request( 'GET', '/minn-admin/v1/overview/activity' );
	$window->set_param( 'from', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
	$window->set_param( 'to', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );
	$feed_text = function ( $uid ) use ( $window ) {
		wp_set_current_user( $uid );
		$out = array();
		$drill = (array) rest_ensure_response( Minn_Admin_REST::overview_activity( $window ) )->get_data();
		foreach ( (array) ( $drill['items'] ?? array() ) as $item ) {
			$out[] = (string) ( $item['text'] ?? '' );
		}
		$over = (array) rest_ensure_response( Minn_Admin_REST::overview( new WP_REST_Request( 'GET', '/minn-admin/v1/overview' ) ) )->get_data();
		foreach ( (array) ( $over['activity'] ?? array() ) as $item ) {
			$out[] = (string) ( $item['text'] ?? '' );
		}
		foreach ( (array) Minn_Admin_REST::notifications() as $item ) {
			$out[] = is_array( $item ) ? (string) ( $item['title'] ?? '' ) : '';
		}
		return implode( "\n", $out );
	};

	$reader_sees = $feed_text( $reader );
	$check( 'Fixture reader cannot read the private post', ! user_can( $reader, 'read_post', $private_id ) );
	$check(
		'Comment feeds omit a private post\'s title',
		false === strpos( $reader_sees, $secret_title ),
		'feed rows ' . substr_count( $reader_sees, "\n" )
	);
	$check(
		'The activity drill-down omits plugin comment rows',
		false === strpos( $reader_sees, $note_text )
	);
	// Controls: the feed still works, and a reader still sees what they may.
	$check(
		'A public post\'s comment still reaches the feed',
		false !== strpos( $reader_sees, 'Boundary Commenter' )
	);
	$admin_sees = $feed_text( $admin );
	$check(
		'An administrator still sees the private post\'s comment',
		false !== strpos( $admin_sees, $secret_title )
	);
	$check(
		'An administrator is not shown plugin comment rows either',
		false === strpos( $admin_sees, $note_text )
	);
}
wp_set_current_user( $admin );

// --- 7. activating a snippet is judged by where it runs, not what it calls itself
// WPCode's own form filters the location list in JavaScript only, so a snippet
// can sit at an executing location while still typed html or text. Every write
// path resolves the effective type from the location first; the activate,
// delete and read paths passed no location, so those snippets were judged at
// the lowest tier and slipped past the site's no-code-editing directive.
//
// DISALLOW_FILE_EDIT is defined here rather than in wp-config because that is
// the boundary being tested, and the earlier sections have already run.
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true );
}
if ( ! class_exists( 'WPCode_Snippet' ) || ! function_exists( 'minn_admin_wpcode_location_executes' ) ) {
	echo "SKIP  WPCode not active on this site\n";
} else {
	wp_set_current_user( $admin );
	$snippet_ids = array();
	$make_snippet = function ( $location ) use ( &$snippet_ids ) {
		$sid = wp_insert_post( array(
			'post_type'    => 'wpcode',
			'post_status'  => 'draft',
			'post_title'   => 'Minn boundary snippet ' . wp_generate_password( 6, false, false ),
			'post_content' => '<?php /* boundary fixture */ ?>',
		) );
		if ( ! is_wp_error( $sid ) ) {
			$snippet_ids[] = $sid;
			wp_set_object_terms( $sid, 'html', 'wpcode_type' ); // the LOW tier
			wp_set_object_terms( $sid, $location, 'wpcode_location' );
		}
		return is_wp_error( $sid ) ? 0 : $sid;
	};
	$set_active = function ( $sid, $active ) {
		$req = new WP_REST_Request( 'POST', "/minn-admin/v1/wpcode/snippets/{$sid}/active" );
		$req->set_param( 'id', $sid );
		$req->set_param( 'active', $active );
		return rest_do_request( $req )->get_status();
	};

	$executing = $make_snippet( 'everywhere' );
	$inert     = $make_snippet( 'site_wide_header' );
	if ( ! $executing || ! $inert ) {
		$check( 'WPCode boundary fixtures created', false );
	} else {
		$check(
			'Fixture is markup-typed at an executing location',
			'html' === ( new WPCode_Snippet( $executing ) )->get_code_type()
				&& minn_admin_wpcode_location_executes( (string) ( new WPCode_Snippet( $executing ) )->get_location() )
		);
		$check( 'Site forbids editing code from the dashboard', ! Minn_Admin::code_edits_allowed() );

		$status = $set_active( $executing, true );
		$check(
			'Activating it is refused while the site forbids code edits',
			403 === $status && 'publish' !== get_post_status( $executing ),
			'status ' . $status . ', post ' . get_post_status( $executing )
		);
		// Controls. The directive is about code the site RUNS, so a markup
		// snippet at a location that only prints it is unaffected; and
		// switching something off is never blocked by a bar on authoring it.
		$inert_status = $set_active( $inert, true );
		$check(
			'A markup snippet at a non-executing location still activates',
			200 === $inert_status && 'publish' === get_post_status( $inert ),
			'status ' . $inert_status . ', post ' . get_post_status( $inert )
		);
		wp_update_post( array( 'ID' => $executing, 'post_status' => 'publish' ) );
		$off_status = $set_active( $executing, false );
		$check(
			'Deactivating an executing snippet is still allowed',
			200 === $off_status && 'publish' !== get_post_status( $executing ),
			'status ' . $off_status . ', post ' . get_post_status( $executing )
		);
	}
	foreach ( $snippet_ids as $sid ) {
		wp_delete_post( $sid, true );
	}
}

// --- cleanup -------------------------------------------------------------
wp_set_current_user( $admin );
foreach ( array( $duplicate_id, $attachment_id, is_wp_error( $private_parent ) ? 0 : $private_parent, is_wp_error( $source_id ) ? 0 : $source_id ) as $post_id ) {
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
}
if ( ! function_exists( 'wp_delete_user' ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
}
wp_delete_user( $uid );
if ( $forbidden_uid ) {
	wp_delete_user( $forbidden_uid );
}
if ( ! empty( $writer ) && ! is_wp_error( $writer ) ) {
	wp_delete_user( $writer );
}
if ( ! empty( $reader ) && ! is_wp_error( $reader ) ) {
	wp_delete_user( $reader );
}
foreach ( array( $private_comment, $public_comment, $note_comment ) as $comment_id ) {
	if ( $comment_id ) {
		wp_delete_comment( (int) $comment_id, true );
	}
}
foreach ( array( $private_id, $public_id ) as $post_id ) {
	if ( $post_id && ! is_wp_error( $post_id ) ) {
		wp_delete_post( (int) $post_id, true );
	}
}
remove_role( 'minn_boundary_editor' );
unregister_post_type( 'minn_boundary_item' );

printf( "\nsecurity-v034-boundaries: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
