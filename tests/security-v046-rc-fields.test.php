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
	if ( ! function_exists( 'pods_api' ) || ! function_exists( 'minn_admin_pods_write_values' ) || ! minn_admin_pods_active() ) {
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
			$err = minn_admin_pods_write_values( $id, array( $email => 'raw@' ) );
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
			$err = minn_admin_pods_write_values( $id, array( $text => 'Hooked ' . $tag ) );
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
			$err = minn_admin_pods_write_values( $id, array( $text => 'After ' . $tag ) );
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

printf( "\nsecurity-v046-rc-fields: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
