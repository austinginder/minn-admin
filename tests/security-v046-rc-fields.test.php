<?php
/**
 * RC audit (v0.46.0), field plugins: a save says what it did, and a field
 * the plugin's own screen withholds is neither served nor accepted.
 *
 *  - 09-1 Pods: a value Pods refuses (a malformed email, an emptied required
 *    field) ended the post save with Pods' own JSON 500 and an exit, after
 *    WordPress had stored the post; on a new post each retry made another
 *    draft. Pods' own object exits the same way outside REST, and a refusal
 *    that threw instead fell through to a raw meta write. Run over real REST
 *    (an application password), where the post is stored before the exit.
 *  - 09-2 ACPT: box visibility conditions (a USER condition, a post status)
 *    were ignored by the post panel and the option-page walk.
 *  - 09-3 ACF: a taxonomy field resolved a stored term id from any taxonomy.
 *  - 09-4 ACF: a post field with no type filter stored and resolved core's
 *    user_request posts (a privacy requester's email is the title).
 *  - 09-5 JetEngine content types: a create or edit JetEngine refused
 *    reported success (a WP_Error cast to id 1 answered with row #1).
 *  - The held-refusal store every panel shares (ACPT, Pods).
 *
 * Sections SKIP when their plugin is inactive. Everything a section makes
 * (users, an application password, Pods fields, an ACPT group, ACF local
 * fields, a menu, a privacy request, a content type and its table, posts)
 * is removed however the run ends.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v046-rc-fields.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

/**
 * A Pods panel save as the REST field runs it (the fields tier), answered
 * the way the adapter's own writer used to: an error naming what was
 * refused, or null.
 */
function minn_v046_pods_save( $id, $values ) {
	$rows = Minn_Admin_Kernel_Panels::save( Minn_Admin_Kernel_Panels::panel( 'pods' ), get_post( $id ), $values );
	return $rows ? new WP_Error( 'minn_pods_refused', implode( ' ', array_unique( array_column( $rows, 'message' ) ) ) ) : null;
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null ) {
	// rest_do_request() does not read a query string in the path.
	$query = array();
	if ( false !== strpos( $route, '?' ) ) {
		list( $route, $qs ) = explode( '?', $route, 2 );
		parse_str( $qs, $query );
	}
	$r = new WP_REST_Request( $method, $route );
	foreach ( $query as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
// The same request as one user, then back to whoever ran the suite.
$as      = function ( $uid, callable $fn ) {
	$was = get_current_user_id();
	wp_set_current_user( $uid );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $was );
	}
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = function ( callable $fn ) {
	$done = false;
	$run  = function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};
// A disposable account; its id only when it is a real new one.
$user = function ( $login, $role ) {
	$id = wp_insert_user( array(
		'user_login' => $login,
		'user_pass'  => wp_generate_password( 32 ),
		'user_email' => $login . '@example.invalid',
		'role'       => $role,
	) );
	return ( is_int( $id ) && $id > 1 ) ? $id : 0;
};
$drop_user = function ( $id, $login ) {
	$u = ( is_int( $id ) && $id > 1 ) ? get_userdata( $id ) : false;
	if ( $u && $login === $u->user_login ) {
		wp_delete_user( $id );
	}
};
$field_err = function ( $d, $panel, $key ) {
	foreach ( (array) ( is_array( $d ) ? ( $d['minn_field_errors'] ?? array() ) : array() ) as $e ) {
		if ( $panel === ( $e['field'] ?? '' ) && (string) $key === (string) ( $e['key'] ?? '' ) ) {
			return $e;
		}
	}
	return null;
};
$fmsg = function ( $d ) {
	return is_array( $d ) ? (string) ( $d['minn_field_message'] ?? $d['message'] ?? '' ) : (string) $d;
};

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();
$admin = get_current_user_id();
$tag   = (string) wp_rand( 1000, 9999 );

/*
 * The held-refusal store: every panel whose plugin can refuse a value holds
 * the refusal on the request, and the post's own answer carries them all
 * with its status kept.
 */
( function () use ( $check, $skip ) {
	if ( ! function_exists( 'minn_admin_acpt_held_error' ) ) {
		$skip( 'held refusals: the ACPT adapter is not loaded' );
		return;
	}
	$ok = function_exists( 'minn_admin_panel_held' ) && function_exists( 'minn_admin_panel_report_held' );
	$check( 'held refusals: one store serves every panel (minn_admin_panel_held)', $ok );
	if ( ! $ok ) {
		return;
	}
	$r = new WP_REST_Request( 'POST', '/wp/v2/posts' );
	minn_admin_panel_held( $r, new WP_Error( 'a', 'ACPT said no.', array( 'fields' => array( array( 'key' => 'k1', 'label' => 'One', 'message' => 'm1' ) ) ) ), 'minn_acpt' );
	minn_admin_panel_held( $r, new WP_Error( 'b', 'Pods said no.', array( 'fields' => array( array( 'key' => 'k2', 'label' => 'Two', 'message' => 'm2' ) ) ) ), 'minn_pods' );
	$res  = minn_admin_panel_report_held( new WP_REST_Response( array( 'id' => 5 ), 201 ), $r );
	$data = $res->get_data();
	$keys = array();
	foreach ( (array) ( $data['minn_field_errors'] ?? array() ) as $e ) {
		$keys[] = ( $e['field'] ?? '' ) . ':' . ( $e['key'] ?? '' );
	}
	$check( 'held refusals: both panels\' refusals ride on the 201, each under its own write key', 201 === $res->get_status() && array( 'minn_acpt:k1', 'minn_pods:k2' ) === $keys && false !== strpos( (string) ( $data['minn_field_message'] ?? '' ), 'Pods said no.' ), $res->get_status() . ' ' . wp_json_encode( $data ) );
	$r2 = new WP_REST_Request( 'POST', '/wp/v2/posts' );
	$check( 'held refusals: taken once, and never by another request', empty( minn_admin_panel_held( $r ) ) && empty( minn_admin_panel_held( $r2 ) ) );
	minn_admin_acpt_held_error( $r2, new WP_Error( 'c', 'Held.' ) );
	$h = minn_admin_panel_held( $r2 );
	$check( 'held refusals: ACPT\'s own name for the store still holds under minn_acpt (control)', 1 === count( $h ) && 'minn_acpt' === ( $h[0]['panel'] ?? '' ) );
} )();

/*
 * 09-1 Pods. Two fields seeded on the pod that extends posts: an Email and a
 * required Text. A Contributor saves through real REST with an application
 * password, as the editor does.
 */
( function () use ( $check, $skip, $call, $as, $once, $user, $drop_user, $field_err, $fmsg, $tag, $admin ) {
	if ( ! function_exists( 'pods_api' ) || ! class_exists( 'Minn_Admin_Kernel_Panels' ) || ! minn_admin_pods_active() ) {
		$skip( 'Pods: inactive' );
		return;
	}
	$api = pods_api();
	$pod = $api->load_pod( array( 'name' => 'post' ), false );
	if ( ! $pod ) {
		$skip( 'Pods: no pod extends post' );
		return;
	}
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		$skip( 'Pods: no application passwords in this WordPress' );
		return;
	}
	$email  = 'minnrc_email' . $tag;
	$req    = 'minnrc_req' . $tag;
	$text   = 'minnrc_text' . $tag;
	$login  = 'minnrc_pods_c' . $tag;
	$title  = 'Minn rc Pods create ' . $tag;
	$uid    = 0;
	$made   = array();
	$clean  = $once( function () use ( &$uid, &$made, $api, $email, $req, $text, $login, $title, $drop_user ) {
		remove_all_filters( 'pods_error_die' );
		$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $title, 'fields' => 'ids', 'posts_per_page' => 50 ) );
		foreach ( array_unique( array_merge( array_map( 'intval', $made ), array_map( 'intval', (array) $ids ) ) ) as $pid ) {
			$p = $pid > 0 ? get_post( $pid ) : null;
			if ( $p && 0 === strpos( $p->post_title, 'Minn rc Pods' ) ) {
				wp_delete_post( $pid, true );
			}
		}
		foreach ( array( $email, $req, $text ) as $name ) {
			try {
				$api->delete_field( array( 'pod' => 'post', 'name' => $name ) );
			} catch ( \Throwable $e ) {
				echo 'WARN  could not delete the Pods test field ' . $name . "\n";
			}
			delete_post_meta_by_key( $name );
		}
		$drop_user( $uid, $login );
	} );
	try {
		$api->save_field( array( 'pod' => 'post', 'name' => $email, 'label' => 'RC email', 'type' => 'email' ) );
		$api->save_field( array( 'pod' => 'post', 'name' => $req, 'label' => 'RC required', 'type' => 'text', 'required' => 1 ) );
		$api->save_field( array( 'pod' => 'post', 'name' => $text, 'label' => 'RC text', 'type' => 'text' ) );
		$uid = $user( $login, 'contributor' );
		$pw  = $uid ? WP_Application_Passwords::create_new_application_password( $uid, array( 'name' => 'minn rc ' . $tag ) ) : null;
		$key = is_array( $pw ) ? (string) $pw[0] : '';
		$check( 'Pods: fixture fields, a Contributor and an application password exist', $uid && '' !== $key && pods_api()->load_field( array( 'pod' => 'post', 'name' => $email ) ), is_wp_error( $pw ) ? $pw->get_error_message() : '' );
		if ( ! $uid || '' === $key ) {
			return;
		}
		$http = function ( $method, $route, $body = null ) use ( $login, $key ) {
			$r = wp_remote_request( rest_url( ltrim( $route, '/' ) ), array(
				'method'    => $method,
				'headers'   => array(
					'Authorization' => 'Basic ' . base64_encode( $login . ':' . $key ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					'Content-Type'  => 'application/json',
				),
				'body'      => null === $body ? null : wp_json_encode( $body ),
				'sslverify' => false,
				'timeout'   => 120,
			) );
			if ( is_wp_error( $r ) ) {
				return array( 0, $r->get_error_message() );
			}
			$raw  = wp_remote_retrieve_body( $r );
			$json = json_decode( $raw, true );
			return array( (int) wp_remote_retrieve_response_code( $r ), null === $json ? substr( $raw, 0, 300 ) : $json );
		};
		$count = function () use ( $title, $uid ) {
			return count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $title, 'author' => $uid, 'fields' => 'ids', 'posts_per_page' => 50 ) ) );
		};
		$meta  = function ( $pid, $name ) {
			wp_cache_delete( $pid, 'post_meta' );
			return get_post_meta( $pid, $name, true );
		};
		list( $st, $d ) = $http( 'GET', '/minn-admin/v1/pods/fields?post_type=posts' );
		$names = array();
		foreach ( (array) ( is_array( $d ) ? ( $d['groups'] ?? array() ) : array() ) as $g ) {
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				$names[] = $f['name'] ?? '';
			}
		}
		$check( 'Pods: the Contributor reaches the panel over REST and it lists the seeded fields', 200 === $st && in_array( $email, $names, true ) && in_array( $req, $names, true ), $st . ' ' . wp_json_encode( $d ) );
		if ( 200 !== $st ) {
			return;
		}

		// The editor's create, then each retry: it records the id only from
		// a successful answer, and the malformed address stays in the box.
		$id      = 0;
		$answers = array();
		foreach ( array( 1, 2, 3 ) as $n ) {
			$body = array( 'minn_pods' => array( $email => 'jane@' ) );
			if ( $id ) {
				list( $st, $d ) = $http( 'POST', '/wp/v2/posts/' . $id, $body );
			} else {
				list( $st, $d ) = $http( 'POST', '/wp/v2/posts', $body + array( 'title' => $title, 'content' => 'Body ' . $n, 'status' => 'draft' ) );
			}
			$answers[] = array( $st, $d );
			if ( in_array( $st, array( 200, 201 ), true ) && is_array( $d ) && ! empty( $d['id'] ) ) {
				$id     = (int) $d['id'];
				$made[] = $id;
			}
		}
		$created = $id;
		if ( ! $id ) {
			// The pre-fix answer was Pods' own 500: find a draft it made anyway,
			// so the update checks below still run against it.
			$found = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => $title, 'author' => $uid, 'fields' => 'ids', 'posts_per_page' => 1 ) );
			$id    = $found ? (int) $found[0] : 0;
		}
		list( $st1, $d1 ) = $answers[0];
		$check( 'Pods create: a value Pods refuses on a new post\'s first save answers 201 with the post', 201 === $st1 && $created > 0, $st1 . ' ' . wp_json_encode( $d1 ) );
		$e = $field_err( $d1, 'minn_pods', $email );
		$check( 'Pods create: and the answer names the refused field', $e && 'RC email' === ( $e['label'] ?? '' ) && false !== strpos( $fmsg( $d1 ), '“RC email”' ), wp_json_encode( is_array( $d1 ) ? array( $d1['minn_field_errors'] ?? null, $fmsg( $d1 ) ) : $d1 ) );
		$check( 'Pods create: two more saves with the value still in the box make no second draft', 1 === $count(), 'drafts titled for this run: ' . $count() );
		$check( 'Pods create: the retries update the same post and still name the field', $created && 200 === $answers[1][0] && 200 === $answers[2][0] && $field_err( $answers[2][1], 'minn_pods', $email ), wp_json_encode( array( $answers[1][0], $answers[2][0] ) ) );
		$check( 'Pods create: the refused address is not stored', $id && '' === (string) $meta( $id, $email ), $id ? wp_json_encode( $meta( $id, $email ) ) : 'no post' );
		if ( ! $id ) {
			return;
		}

		// Control: what Pods takes is stored, with no field error.
		list( $st, $d ) = $http( 'POST', '/wp/v2/posts/' . $id, array( 'minn_pods' => array( $email => 'jane@example.com', $req => 'kept' ) ) );
		$check( 'Pods: a valid address and a required value are stored with no field error (control)', 200 === $st && empty( $d['minn_field_errors'] ) && 'jane@example.com' === $meta( $id, $email ) && 'kept' === $meta( $id, $req ), $st . ' ' . $fmsg( $d ) );

		// An emptied required field, beside a title change and a text edit.
		list( $st, $d ) = $http( 'POST', '/wp/v2/posts/' . $id, array( 'title' => $title, 'content' => 'Edited body', 'minn_pods' => array( $req => '', $text => 'Beside ' . $tag ) ) );
		$check( 'Pods: an emptied required field is named, and the post save answers 200', 200 === $st && $field_err( $d, 'minn_pods', $req ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'Pods: and keeps its stored value', 'kept' === $meta( $id, $req ), wp_json_encode( $meta( $id, $req ) ) );
		$check( 'Pods: the field beside it is written, and the answer says so', 'Beside ' . $tag === $meta( $id, $text ) && false !== strpos( $fmsg( $d ), 'other changes were saved' ), wp_json_encode( array( $meta( $id, $text ), $fmsg( $d ) ) ) );
		$check( 'Pods: the post\'s own content saved in the same request', false !== strpos( (string) get_post_field( 'post_content', $id ), 'Edited body' ) );

		// Two refusals in one save are both named.
		list( $st, $d ) = $http( 'POST', '/wp/v2/posts/' . $id, array( 'minn_pods' => array( $req => '', $email => 'not an address' ) ) );
		$check( 'Pods: two refused fields are both named', 200 === $st && $field_err( $d, 'minn_pods', $req ) && $field_err( $d, 'minn_pods', $email ) && false !== strpos( $fmsg( $d ), '“RC email”' ) && false !== strpos( $fmsg( $d ), '“RC required”' ), $st . ' ' . $fmsg( $d ) );
		$check( 'Pods: and both keep their stored values', 'kept' === $meta( $id, $req ) && 'jane@example.com' === $meta( $id, $email ), wp_json_encode( array( $meta( $id, $req ), $meta( $id, $email ) ) ) );

		// The same saves in this process. Pods' own object ends the process
		// on a refusal here ('exit' mode); the suite turns that exit into a
		// throw so it lives to report it, and counts it.
		$died   = array();
		$no_die = function ( $bypass, $error ) use ( &$died ) {
			$died[] = wp_strip_all_tags( is_scalar( $error ) ? (string) $error : wp_json_encode( $error ) );
			throw new RuntimeException( 'Pods would have ended the request.' );
		};
		add_filter( 'pods_error_die', $no_die, 10, 2 );
		$as( $admin, function () use ( $check, $id, $email, $meta ) {
			$err = minn_v046_pods_save( $id, array( $email => 'raw@' ) );
			$check( 'Pods (in process): a value Pods refuses is answered as refused', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), '“RC email”' ), is_wp_error( $err ) ? $err->get_error_message() : wp_json_encode( $err ) );
			$check( 'Pods (in process): and is never written another way', 'jane@example.com' === $meta( $id, $email ), wp_json_encode( $meta( $id, $email ) ) );
		} );

		// A refusal the field check cannot foresee (a hook on Pods' own
		// save): it throws into Minn's catch instead of printing and exiting,
		// and nothing is written raw.
		$hook = function ( $pieces ) {
			if ( isset( $pieces['params']->pod ) && 'post' === $pieces['params']->pod ) {
				pods_error( 'Refused by the suite.' );
			}
			return $pieces;
		};
		add_filter( 'pods_api_pre_save_pod_item', $hook, 10 );
		try {
			$err = minn_v046_pods_save( $id, array( $text => 'Hooked ' . $tag ) );
		} catch ( \Throwable $e ) {
			$err = $e;
		} finally {
			remove_filter( 'pods_api_pre_save_pod_item', $hook, 10 );
		}
		$check( 'Pods: a refusal raised on Pods\' own save is answered as refused', is_wp_error( $err ) && false !== strpos( $err->get_error_message(), 'Refused by the suite.' ), $err instanceof \Throwable ? get_class( $err ) . ': ' . $err->getMessage() : ( is_wp_error( $err ) ? $err->get_error_message() : wp_json_encode( $err ) ) );
		$check( 'Pods: and the value it refused is not written raw', 'Beside ' . $tag === $meta( $id, $text ), wp_json_encode( $meta( $id, $text ) ) );
		// The same hook after Pods has written: the store says it was saved.
		$after = function ( $pieces ) {
			if ( isset( $pieces['params']->pod ) && 'post' === $pieces['params']->pod ) {
				pods_error( 'Refused after the write.' );
			}
			return $pieces;
		};
		add_filter( 'pods_api_post_save_pod_item', $after, 10 );
		try {
			$err = minn_v046_pods_save( $id, array( $text => 'After ' . $tag ) );
		} catch ( \Throwable $e ) {
			$err = $e;
		} finally {
			remove_filter( 'pods_api_post_save_pod_item', $after, 10 );
		}
		$check( 'Pods: a value Pods wrote before a later hook threw is not reported as refused', ! is_wp_error( $err ) && ! $err instanceof \Throwable && 'After ' . $tag === $meta( $id, $text ), ( is_wp_error( $err ) ? $err->get_error_message() : ( $err instanceof \Throwable ? $err->getMessage() : '' ) ) . ' ' . wp_json_encode( $meta( $id, $text ) ) );
		remove_filter( 'pods_error_die', $no_die, 10 );
		$check( 'Pods (in process): Pods never ended the request', ! $died, implode( ' | ', $died ) );
		$check( 'Pods: no forced error mode is left behind', ! has_filter( 'pods_error_mode' ) || 'exception' !== apply_filters( 'pods_error_mode', 'json', 'x', null ) );

		// Controls: an administrator saves through the same panel.
		list( $st, $d ) = $as( $admin, function () use ( $call, $id, $email ) {
			return $call( 'POST', '/wp/v2/posts/' . $id, array( 'minn_pods' => array( $email => 'admin@example.com' ) ) );
		} );
		$check( 'Pods: an administrator\'s valid save is stored with no field error (control)', 200 === $st && empty( $d['minn_field_errors'] ) && 'admin@example.com' === $meta( $id, $email ), $st . ' ' . $fmsg( $d ) );
		list( $st, $d ) = $as( $admin, function () use ( $call, $id ) {
			return $call( 'GET', '/wp/v2/posts/' . $id . '?context=edit' );
		} );
		$check( 'Pods: the panel still reads the stored values (control)', 200 === $st && 'admin@example.com' === ( $d['minn_pods'][ $email ] ?? null ) && 'kept' === ( $d['minn_pods'][ $req ] ?? null ), $st . ' ' . wp_json_encode( $d['minn_pods'] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * 09-2 ACPT. One group on posts with three boxes: an open one, one kept to
 * the administrator by a USER condition, and one shown only on published
 * posts. And a group on an option page with a box kept the same way, beside
 * a field with its own permissions (a control: the field mapper refuses it).
 */
( function () use ( $check, $skip, $call, $as, $once, $user, $drop_user, $tag, $admin ) {
	if ( ! function_exists( 'minn_admin_acpt_active' ) || ! minn_admin_acpt_active() || ! class_exists( '\ACPT\Core\CQRS\Command\SaveMetaGroupCommand' ) ) {
		$skip( 'ACPT: inactive' );
		return;
	}
	$login  = 'minnrc_acpt_e' . $tag;
	$uid    = 0;
	$gid    = '';
	$ogid   = '';
	$post   = 0;
	$perm   = '';
	$page   = 'minnrc-acpt-' . $tag;
	$clean  = $once( function () use ( &$uid, &$gid, &$ogid, &$post, &$perm, $login, $drop_user, $tag ) {
		global $wpdb;
		if ( $post > 0 && 'Minn rc ACPT' === get_the_title( $post ) ) {
			wp_delete_post( $post, true );
		}
		// What the option-page check wrote: options under the run's own box names.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'minnrco_' ) . '%' . $wpdb->esc_like( $tag ) . '%' ) ) as $opt ) { // phpcs:ignore
			delete_option( $opt );
		}
		if ( '' !== $perm && class_exists( '\ACPT\Core\Repository\PermissionRepository' ) ) {
			try {
				\ACPT\Core\Repository\PermissionRepository::deleteByEntityId( $perm );
			} catch ( \Throwable $e ) {
				echo "WARN  could not delete the ACPT test permission\n";
			}
		}
		foreach ( array( $gid, $ogid ) as $g ) {
			if ( '' !== $g ) {
				try {
					( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $g ) )->execute();
				} catch ( \Throwable $e ) {
					echo 'WARN  could not delete the ACPT test group ' . $g . "\n";
				}
			}
		}
		$drop_user( $uid, $login );
	} );
	try {
		$cond = function ( $type, $value ) {
			return array( 'type' => array( 'type' => $type, 'value' => $type ), 'operator' => '=', 'value' => (string) $value, 'logic' => null, 'backEnd' => 1, 'frontEnd' => 1 );
		};
		$gid = (string) ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
			'name'    => 'minnrc' . $tag,
			'label'   => 'RC',
			'belongs' => array( array( 'belongsTo' => \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE, 'operator' => '=', 'find' => 'post', 'logic' => '' ) ),
			'boxes'   => array(
				array( 'name' => 'minnrc_open' . $tag, 'label' => 'Open', 'fields' => array( array( 'name' => 'note', 'label' => 'Note', 'type' => 'Text', 'isRequired' => false ) ) ),
				array( 'name' => 'minnrc_mine' . $tag, 'label' => 'Mine', 'visibilityConditions' => array( $cond( 'USER', $admin ) ), 'fields' => array( array( 'name' => 'secret', 'label' => 'Secret', 'type' => 'Text', 'isRequired' => false ) ) ),
				array( 'name' => 'minnrc_live' . $tag, 'label' => 'Live', 'visibilityConditions' => array( $cond( 'POST_STATUS', 'publish' ) ), 'fields' => array( array( 'name' => 'live', 'label' => 'Live note', 'type' => 'Text', 'isRequired' => false ) ) ),
			),
		) ) )->execute();
		$uid  = $user( $login, 'editor' );
		$post = (int) wp_insert_post( array( 'post_title' => 'Minn rc ACPT', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $uid ) );
		$ids  = array();
		$boxes = array();
		$g     = \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $gid ) );
		foreach ( $g ? $g[0]->getBoxes() : array() as $b ) {
			$boxes[ $b->getLabel() ] = $b->getName();
			foreach ( $b->getFields() as $f ) {
				$ids[ $f->getName() ] = (string) $f->getId();
			}
		}
		$conds = 0;
		foreach ( $g ? $g[0]->getBoxes() : array() as $b ) {
			$conds += count( $b->getVisibilityConditions() );
		}
		$check( 'ACPT: the test group, its two box conditions, an Editor and a draft exist', 3 === count( $ids ) && 2 === $conds && $uid && $post > 0, wp_json_encode( array( $ids, $conds, $uid, $post ) ) );
		if ( 3 !== count( $ids ) || ! $uid || ! $post ) {
			return;
		}
		save_acpt_meta_field_value( array( 'post_id' => $post, 'box_name' => $boxes['Mine'], 'field_name' => 'secret', 'value' => 'For the admin only' ) );
		$raw = function ( $box, $name ) use ( $post ) {
			return get_acpt_field( array( 'post_id' => $post, 'box_name' => $box, 'field_name' => $name, 'format' => 'only_value', 'return' => 'raw' ) );
		};
		$labels = function ( $d ) {
			return implode( ' | ', wp_list_pluck( (array) ( is_array( $d ) ? ( $d['groups'] ?? array() ) : array() ), 'group' ) );
		};

		list( $st, $d ) = $as( $uid, function () use ( $call, $post ) {
			return $call( 'GET', '/minn-admin/v1/acpt/fields?post_id=' . $post );
		} );
		$check( 'ACPT: a box kept to another account is not listed for the Editor', 200 === $st && false === strpos( $labels( $d ), 'Mine' ), $st . ' ' . $labels( $d ) );
		$check( 'ACPT: nor a box ACPT shows only on published posts, on a draft', 200 === $st && false === strpos( $labels( $d ), 'Live' ), $labels( $d ) );
		$check( 'ACPT: the open box is listed (control)', false !== strpos( $labels( $d ), 'Open' ), $labels( $d ) );
		list( $st, $d ) = $as( $uid, function () use ( $call, $post ) {
			return $call( 'GET', '/wp/v2/posts/' . $post . '?context=edit' );
		} );
		$vals = (array) ( $d['minn_acpt'] ?? array() );
		$check( 'ACPT: the hidden box\'s value is not served to the Editor', 200 === $st && ! array_key_exists( $ids['secret'], $vals ), wp_json_encode( $vals ) );
		list( $st, $d ) = $as( $uid, function () use ( $call, $post, $ids ) {
			return $call( 'POST', '/wp/v2/posts/' . $post, array( 'minn_acpt' => array( $ids['secret'] => 'Overwritten', $ids['live'] => 'Live text', $ids['note'] => 'Open text' ) ) );
		} );
		$check( 'ACPT: nor accepted from the Editor', 'For the admin only' === $raw( $boxes['Mine'], 'secret' ), wp_json_encode( $raw( $boxes['Mine'], 'secret' ) ) );
		$check( 'ACPT: nor the published-only box on a draft', in_array( $raw( $boxes['Live'], 'live' ), array( null, '' ), true ), wp_json_encode( $raw( $boxes['Live'], 'live' ) ) );
		$check( 'ACPT: the open field in the same save is written (control)', 200 === $st && 'Open text' === $raw( $boxes['Open'], 'note' ), $st . ' ' . wp_json_encode( $raw( $boxes['Open'], 'note' ) ) );

		// Controls: the account the condition names sees and writes its box.
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/acpt/fields?post_id=' . $post );
		$check( 'ACPT: the administrator the condition names gets the box (control)', 200 === $st && false !== strpos( $labels( $d ), 'Mine' ), $labels( $d ) );
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts/' . $post, array( 'minn_acpt' => array( $ids['secret'] => 'Admin edit' ) ) );
		$check( 'ACPT: and writes it (control)', 200 === $st && 'Admin edit' === $raw( $boxes['Mine'], 'secret' ), $st . ' ' . wp_json_encode( $raw( $boxes['Mine'], 'secret' ) ) );

		// The option-page walk (listing a page needs an ACPT licence, so the
		// walk and its writer are driven directly).
		$ogid = (string) ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( array(
			'name'    => 'minnrco' . $tag,
			'label'   => 'RC options',
			'belongs' => array( array( 'belongsTo' => \ACPT\Constants\MetaTypes::OPTION_PAGE, 'operator' => '=', 'find' => $page, 'logic' => '' ) ),
			'boxes'   => array(
				array( 'name' => 'minnrco_open' . $tag, 'label' => 'General', 'fields' => array(
					array( 'name' => 'site_note', 'label' => 'Site note', 'type' => 'Text', 'isRequired' => false ),
					array( 'name' => 'gated', 'label' => 'Gated', 'type' => 'Text', 'isRequired' => false ),
				) ),
				array( 'name' => 'minnrco_keys' . $tag, 'label' => 'Integrations', 'visibilityConditions' => array( $cond( 'USER', $admin ) ), 'fields' => array( array( 'name' => 'api_key', 'label' => 'API key', 'type' => 'Text', 'isRequired' => false ) ) ),
			),
		) ) )->execute();
		$og = \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $ogid ) );
		$oids = array();
		foreach ( $og ? $og[0]->getBoxes() : array() as $b ) {
			foreach ( $b->getFields() as $f ) {
				$oids[ $f->getName() ] = (string) $f->getId();
			}
		}
		// ACPT's own field permissions: a row for the administrator role makes
		// the field ask for read_/edit_<box>_<field>, which an Editor lacks.
		if ( isset( $oids['gated'] ) && class_exists( '\ACPT\Core\CQRS\Command\SavePermissionCommand' ) ) {
			$perm = $oids['gated'];
			( new \ACPT\Core\CQRS\Command\SavePermissionCommand( array(
				'entityId' => $perm,
				'items'    => array( array( 'id' => wp_generate_uuid4(), 'userRole' => 'administrator', 'permissions' => array( 'read' => true, 'edit' => true ) ) ),
			) ) )->execute();
		}
		$tab_fields = function () use ( $page ) {
			$out = array();
			foreach ( minn_admin_acpt_option_tabs( $page ) as $t ) {
				foreach ( $t['fields'] as $f ) {
					$out[] = (string) $f['name'];
				}
			}
			return $out;
		};
		$check( 'ACPT options: the option-page group exists', 3 === count( $oids ), wp_json_encode( $oids ) );
		if ( 3 === count( $oids ) ) {
			$ed = $as( $uid, $tab_fields );
			$ad = $tab_fields();
			$check( 'ACPT options: a box kept to another account gives the Editor no tab and no field', ! in_array( $oids['api_key'], $ed, true ), wp_json_encode( $ed ) );
			$check( 'ACPT options: a field whose own permissions the Editor fails is not offered (control)', '' !== $perm && ! in_array( $oids['gated'], $ed, true ), wp_json_encode( $ed ) );
			$check( 'ACPT options: the open field is offered to the Editor (control)', in_array( $oids['site_note'], $ed, true ), wp_json_encode( $ed ) );
			$check( 'ACPT options: the administrator the condition names gets the kept box (control)', in_array( $oids['api_key'], $ad, true ), wp_json_encode( $ad ) );
			$before = get_acpt_field( array( 'option_page' => $page, 'box_name' => 'minnrco_keys' . $tag, 'field_name' => 'api_key', 'format' => 'only_value', 'return' => 'raw' ) );
			$as( $uid, function () use ( $page, $oids ) {
				minn_admin_acpt_option_save( $page, array( $oids['api_key'] => 'sk_from_editor', $oids['site_note'] => 'Editor note' ) );
			} );
			$after = get_acpt_field( array( 'option_page' => $page, 'box_name' => 'minnrco_keys' . $tag, 'field_name' => 'api_key', 'format' => 'only_value', 'return' => 'raw' ) );
			$note  = get_acpt_field( array( 'option_page' => $page, 'box_name' => 'minnrco_open' . $tag, 'field_name' => 'site_note', 'format' => 'only_value', 'return' => 'raw' ) );
			$check( 'ACPT options: the kept box is not written from the Editor', $before === $after && 'sk_from_editor' !== $after, wp_json_encode( $after ) );
			$check( 'ACPT options: the open field in the same save is written (control)', 'Editor note' === $note, wp_json_encode( $note ) );
		}
	} finally {
		$clean();
	}
} )();

/*
 * 09-3 and 09-4 ACF. A local group on posts: a taxonomy field (category,
 * one pick), a relationship and a post object field with no type filter.
 */
( function () use ( $check, $skip, $call, $as, $once, $user, $drop_user, $tag, $admin ) {
	if ( ! function_exists( 'acf_add_local_field_group' ) || ! function_exists( 'minn_admin_acf_relation_entry' ) ) {
		$skip( 'ACF: inactive' );
		return;
	}
	$clogin = 'minnrc_acf_c' . $tag;
	$elogin = 'minnrc_acf_e' . $tag;
	$cid    = 0;
	$eid    = 0;
	$posts  = array();
	$menu   = 0;
	$cat    = 0;
	$reqid  = 0;
	$clean  = $once( function () use ( &$cid, &$eid, &$posts, &$menu, &$cat, &$reqid, $clogin, $elogin, $drop_user, $tag ) {
		foreach ( $posts as $pid ) {
			$p = $pid > 0 ? get_post( $pid ) : null;
			if ( $p && 0 === strpos( $p->post_title, 'Minn rc ACF' ) ) {
				wp_delete_post( $pid, true );
			}
		}
		$r = $reqid > 0 ? get_post( $reqid ) : null;
		if ( $r && 'user_request' === $r->post_type && 'minnrc-' . $tag . '@example.invalid' === $r->post_title ) {
			wp_delete_post( $reqid, true );
		}
		$m = $menu > 0 ? get_term( $menu, 'nav_menu' ) : null;
		if ( $m instanceof WP_Term && 'Minn rc menu ' . $tag === $m->name ) {
			wp_delete_nav_menu( $menu );
		}
		$c = $cat > 0 ? get_term( $cat, 'category' ) : null;
		if ( $c instanceof WP_Term && 'Minn rc cat ' . $tag === $c->name ) {
			wp_delete_term( $cat, 'category' );
		}
		acf_remove_local_field_group( 'group_minnrc' . $tag );
		$drop_user( $cid, $clogin );
		$drop_user( $eid, $elogin );
	} );
	try {
		acf_add_local_field_group( array(
			'key'      => 'group_minnrc' . $tag,
			'title'    => 'Minn rc relations',
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
			'fields'   => array(
				array( 'key' => 'field_minnrc_topic', 'name' => 'minnrc_topic', 'label' => 'Topic', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'select', 'save_terms' => 0, 'load_terms' => 0, 'return_format' => 'id' ),
				array( 'key' => 'field_minnrc_related', 'name' => 'minnrc_related', 'label' => 'Related', 'type' => 'relationship', 'post_type' => array(), 'return_format' => 'id' ),
				array( 'key' => 'field_minnrc_obj', 'name' => 'minnrc_obj', 'label' => 'Object', 'type' => 'post_object', 'post_type' => array(), 'multiple' => 0, 'return_format' => 'id' ),
				array( 'key' => 'field_minnrc_note', 'name' => 'minnrc_note', 'label' => 'Note', 'type' => 'text' ),
			),
		) );
		$cid   = $user( $clogin, 'contributor' );
		$eid   = $user( $elogin, 'editor' );
		$mk    = wp_create_nav_menu( 'Minn rc menu ' . $tag );
		$menu  = is_int( $mk ) ? $mk : 0;
		$ct    = wp_insert_term( 'Minn rc cat ' . $tag, 'category' );
		$cat   = is_array( $ct ) ? (int) $ct['term_id'] : 0;
		$rq    = wp_create_user_request( 'minnrc-' . $tag . '@example.invalid', 'export_personal_data' );
		$reqid = is_int( $rq ) ? $rq : 0;
		$cpost = (int) wp_insert_post( array( 'post_title' => 'Minn rc ACF contributor', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $cid ) );
		$epost = (int) wp_insert_post( array( 'post_title' => 'Minn rc ACF editor', 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $eid ) );
		$other = (int) wp_insert_post( array( 'post_title' => 'Minn rc ACF target', 'post_status' => 'publish', 'post_type' => 'post', 'post_author' => $admin ) );
		$posts = array( $cpost, $epost, $other );
		$check( 'ACF: two accounts, a menu, a category, a privacy request and three posts exist', $cid && $eid && $menu > 0 && $cat > 0 && $reqid > 0 && $cpost > 0 && $epost > 0 && $other > 0, wp_json_encode( array( $cid, $eid, $menu, $cat, $reqid, $cpost, $epost, $other ) ) );
		if ( ! ( $cid && $eid && $menu > 0 && $cat > 0 && $reqid > 0 && $cpost > 0 && $epost > 0 && $other > 0 ) ) {
			return;
		}
		$store = function ( $pid, $name, $value ) {
			update_post_meta( $pid, $name, $value );
			update_post_meta( $pid, '_' . $name, 'field_' . $name );
		};
		$meta  = function ( $pid, $name ) {
			wp_cache_delete( $pid, 'post_meta' );
			return get_post_meta( $pid, $name, true );
		};
		$read  = function ( $uid, $pid ) use ( $as, $call ) {
			return $as( $uid, function () use ( $call, $pid ) {
				list( $st, $d ) = $call( 'GET', '/wp/v2/posts/' . $pid . '?context=edit' );
				return array( $st, (array) ( $d['minn_acf'] ?? array() ) );
			} );
		};

		// 09-3: a menu's term id stored in the Contributor's own draft, as
		// ACF's own metabox accepts it.
		$store( $cpost, 'minnrc_topic', (string) $menu );
		list( $st, $v ) = $read( $cid, $cpost );
		$topic = $v['minnrc_topic'] ?? null;
		$check( 'ACF 09-3: a term id from another taxonomy does not resolve to its name', 200 === $st && ( ! is_array( $topic ) || false === strpos( (string) ( $topic['label'] ?? '' ), 'Minn rc menu' ) ), $st . ' ' . wp_json_encode( $topic ) );
		$as( $cid, function () use ( $call, $cpost ) {
			$call( 'POST', '/wp/v2/posts/' . $cpost, array( 'minn_acf' => array( 'minnrc_topic' => '', 'minnrc_note' => 'Edited' ) ) );
		} );
		$check( 'ACF 09-3: and it reads as withheld: a save sending the empty box keeps it', (string) $menu === (string) $meta( $cpost, 'minnrc_topic' ) && 'Edited' === $meta( $cpost, 'minnrc_note' ), wp_json_encode( array( $meta( $cpost, 'minnrc_topic' ), $meta( $cpost, 'minnrc_note' ) ) ) );
		$store( $cpost, 'minnrc_topic', (string) $cat );
		list( $st, $v ) = $read( $cid, $cpost );
		$check( 'ACF 09-3: a term of the field\'s own taxonomy resolves (control)', is_array( $v['minnrc_topic'] ?? null ) && 'Minn rc cat ' . $tag === ( $v['minnrc_topic']['label'] ?? '' ), wp_json_encode( $v['minnrc_topic'] ?? null ) );
		$as( $cid, function () use ( $call, $cpost, $menu ) {
			$call( 'POST', '/wp/v2/posts/' . $cpost, array( 'minn_acf' => array( 'minnrc_topic' => array( 'value' => (string) $menu ) ) ) );
		} );
		$check( 'ACF 09-3: a foreign term id is not stored by hand (control)', (string) $cat === (string) $meta( $cpost, 'minnrc_topic' ), wp_json_encode( $meta( $cpost, 'minnrc_topic' ) ) );

		// 09-4: the privacy request, by hand, into fields with no type filter.
		list( $st, $d ) = $as( $eid, function () use ( $call, $epost, $reqid, $other ) {
			return $call( 'POST', '/wp/v2/posts/' . $epost, array( 'minn_acf' => array( 'minnrc_related' => array( array( 'value' => (string) $reqid ), array( 'value' => (string) $other ) ), 'minnrc_obj' => array( 'value' => (string) $reqid ) ) ) );
		} );
		$rel = array_map( 'strval', (array) $meta( $epost, 'minnrc_related' ) );
		$check( 'ACF 09-4: a privacy request is not stored in a relationship field by an Editor', ! in_array( (string) $reqid, $rel, true ), wp_json_encode( $rel ) );
		$check( 'ACF 09-4: nor in a post object field', (string) $reqid !== (string) $meta( $epost, 'minnrc_obj' ), wp_json_encode( $meta( $epost, 'minnrc_obj' ) ) );
		$check( 'ACF 09-4: the ordinary post beside it is stored (control)', in_array( (string) $other, $rel, true ), wp_json_encode( $rel ) );
		// Stored by other means (ACF's own screen, an import): not resolved.
		$store( $epost, 'minnrc_related', array( (string) $reqid, (string) $other ) );
		$store( $epost, 'minnrc_obj', (string) $reqid );
		foreach ( array( 'Editor' => $eid, 'administrator' => $admin ) as $who => $uid ) {
			list( $st, $v ) = $read( $uid, $epost );
			$all = wp_json_encode( array( $v['minnrc_related'] ?? null, $v['minnrc_obj'] ?? null ) );
			$check( 'ACF 09-4: a stored privacy request does not resolve (its title is the requester\'s email) for the ' . $who, 200 === $st && false === strpos( $all, 'minnrc-' . $tag ), $all );
		}
		list( $st, $v ) = $read( $eid, $epost );
		$labels = wp_list_pluck( (array) ( $v['minnrc_related'] ?? array() ), 'label' );
		$check( 'ACF 09-4: the ordinary post still resolves (control)', in_array( 'Minn rc ACF target', $labels, true ), wp_json_encode( $labels ) );
		$as( $eid, function () use ( $call, $epost, $other ) {
			$call( 'POST', '/wp/v2/posts/' . $epost, array( 'minn_acf' => array( 'minnrc_related' => array( array( 'value' => (string) $other ) ), 'minnrc_obj' => '' ) ) );
		} );
		$rel = array_map( 'strval', (array) $meta( $epost, 'minnrc_related' ) );
		$check( 'ACF 09-4: a save of what was served keeps the withheld request where it was', array( (string) $reqid, (string) $other ) === $rel && (string) $reqid === (string) $meta( $epost, 'minnrc_obj' ), wp_json_encode( array( $rel, $meta( $epost, 'minnrc_obj' ) ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * 09-5 JetEngine content types. A type whose fields name a column its table
 * does not have (a field added without updating the table): JetEngine's
 * handler answers a WP_Error. Row #1 exists, so the cast answered with it.
 */
( function () use ( $check, $skip, $call, $once, $tag ) {
	if ( ! function_exists( 'minn_admin_jet_cct_active' ) || ! minn_admin_jet_cct_active() || ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Factory' ) ) {
		$skip( 'JetEngine content types: inactive' );
		return;
	}
	global $wpdb;
	$slug    = 'minnrc' . $tag;
	$table   = $wpdb->prefix . 'jet_cct_' . $slug;
	$factory = null;
	$manager = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager;
	$hook    = null;
	$clean   = $once( function () use ( &$factory, &$hook, $table, $slug ) {
		if ( $hook ) {
			remove_action( 'jet-engine/custom-content-types/updated-item/' . $slug, $hook );
		}
		if ( $factory && $table === $factory->db->table() ) {
			$factory->db->drop_table();
		}
	} );
	$errors = $wpdb->suppress_errors( true );
	try {
		$fields  = array( array( 'title' => 'Name', 'name' => 'name', 'object_type' => 'field', 'type' => 'text' ) );
		$factory = new \Jet_Engine\Modules\Custom_Content_Types\Factory( array( 'slug' => $slug, 'name' => 'Minn rc', 'capability' => 'manage_options' ), $fields );
		$factory->db->create_table();
		$manager->register_instance( $slug, $factory );
		$wpdb->insert( $table, array( 'name' => 'Row one', 'cct_status' => 'publish' ) );
		$one = (int) $wpdb->insert_id;
		$check( 'JetEngine CCT: a test type and its row #1 exist', 1 === $one, $wpdb->last_error );
		if ( 1 !== $one ) {
			return;
		}
		$name_of = function ( $id ) use ( $wpdb, $table ) {
			return $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE _ID = %d", $id ) ); // phpcs:ignore
		};
		$rows = function () use ( $wpdb, $table ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		};
		// The same type with a field its table lacks.
		$broken = new \Jet_Engine\Modules\Custom_Content_Types\Factory( array( 'slug' => $slug, 'name' => 'Minn rc', 'capability' => 'manage_options' ), array_merge( $fields, array( array( 'title' => 'Extra', 'name' => 'extra', 'object_type' => 'field', 'type' => 'text' ) ) ) );
		$manager->register_instance( $slug, $broken );
		$base = '/minn-admin/v1/jet-cct/' . $slug . '/items';

		list( $st, $d ) = $call( 'POST', $base, array( 'name' => 'Never added', 'extra' => 'x' ) );
		$check( 'JetEngine CCT: a create JetEngine refuses is not answered as added', 200 !== $st && empty( $d['ok'] ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'JetEngine CCT: and row #1 is not named as the new item', (int) ( $d['id'] ?? 0 ) !== 1, wp_json_encode( $d ) );
		$check( 'JetEngine CCT: nothing was added', 1 === $rows(), (string) $rows() );

		list( $st, $d ) = $call( 'POST', $base . '/1', array( 'name' => 'Lost edit', 'extra' => 'y' ) );
		$check( 'JetEngine CCT: an edit JetEngine refuses is not answered as saved', 200 !== $st, $st . ' ' . wp_json_encode( $d ) );
		$check( 'JetEngine CCT: and the row is as it was', 'Row one' === $name_of( 1 ), (string) $name_of( 1 ) );

		// A refused edit whose error JetEngine loses before it asks (an update
		// hook runs a query in between): the row decides.
		$hook = function () use ( $wpdb ) {
			$wpdb->query( 'SELECT 1' ); // phpcs:ignore
		};
		add_action( 'jet-engine/custom-content-types/updated-item/' . $slug, $hook );
		list( $st, $d ) = $call( 'POST', $base . '/1', array( 'name' => 'Lost quietly', 'extra' => 'z' ) );
		$check( 'JetEngine CCT: an edit that left the row as it was is not answered as saved', 200 !== $st && 'Row one' === $name_of( 1 ), $st . ' ' . wp_json_encode( $d ) . ' ' . $name_of( 1 ) );
		list( $st, $d ) = $call( 'POST', $base . '/1/status', array( 'status' => 'draft' ) );
		$check( 'JetEngine CCT: a status change JetEngine refuses is not answered as done', 200 !== $st, $st . ' ' . wp_json_encode( $d ) );
		remove_action( 'jet-engine/custom-content-types/updated-item/' . $slug, $hook );
		$hook = null;

		// Controls: with the table up to date, the same saves are written.
		$manager->register_instance( $slug, $factory );
		list( $st, $d ) = $call( 'POST', $base, array( 'name' => 'Added ' . $tag ) );
		$new = (int) ( $d['id'] ?? 0 );
		$check( 'JetEngine CCT: a create is answered with the new item (control)', 200 === $st && ! empty( $d['ok'] ) && $new > 1 && 'Added ' . $tag === $name_of( $new ), $st . ' ' . wp_json_encode( $d ) );
		list( $st, $d ) = $call( 'POST', $base . '/1', array( 'name' => 'Edited ' . $tag ) );
		$check( 'JetEngine CCT: an edit is written and answered 200 (control)', 200 === $st && 'Edited ' . $tag === $name_of( 1 ) && 'Edited ' . $tag === ( $d['name'] ?? null ), $st . ' ' . wp_json_encode( $d ) );
		list( $st, $d ) = $call( 'POST', $base . '/1', array( 'name' => 'Edited ' . $tag ) );
		$check( 'JetEngine CCT: an edit that changes nothing answers 200 (control)', 200 === $st, $st . ' ' . wp_json_encode( $d ) );
		list( $st ) = $call( 'POST', $base . '/1/status', array( 'status' => 'draft' ) );
		$check( 'JetEngine CCT: a status change is written (control)', 200 === $st && 'draft' === $wpdb->get_var( "SELECT cct_status FROM {$table} WHERE _ID = 1" ), (string) $st ); // phpcs:ignore
	} finally {
		$wpdb->suppress_errors( $errors );
		$clean();
	}
} )();

printf( "\nsecurity-v046-rc-fields: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
