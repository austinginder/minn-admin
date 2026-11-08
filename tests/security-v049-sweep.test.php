<?php
/**
 * Regressions for the v0.49.0 pre-flight fixture sweep: vendor updates that
 * changed what Minn's routes see.
 *
 *  - S1 ACF 6.8.11 maps add_post_meta on its field values to do_not_allow,
 *    so a duplicate copied none of a post's custom fields. A value now copies,
 *    with its field-key reference, when the field's group shows on the post
 *    for the caller and acf/prepare_field neither hides nor locks it; a field
 *    ACF hides, or one whose group doesn't show there, stays behind.
 *  - S2 Ninja Forms 3.16.0 keeps its PayPal connection's signing secret in
 *    nf_paypal_connect: the database browser redacts that row.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v049-sweep.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

require_once __DIR__ . '/lib/signals.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$once = static function ( callable $fn ) {
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

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();
$admin = get_current_user_id();
$tag   = strtolower( wp_generate_password( 6, false ) );

$call = static function ( $method, $route ) {
	$res = rest_do_request( new WP_REST_Request( $method, $route ) );
	return array( $res->get_status(), $res->get_data() );
};

/*
 * S1: a duplicate carries the ACF values ACF's own screen would let the caller save.
 */
( static function () use ( $check, $skip, $once, $call, $admin, $tag ) {
	if ( ! function_exists( 'acf_add_local_field_group' ) || ! function_exists( 'update_field' ) ) {
		$skip( 'S1 ACF is not active' );
		return;
	}
	$k = 'field_minn_sweep_' . $tag;
	acf_add_local_field_group( array(
		'key'      => 'group_minn_sweep_' . $tag,
		'title'    => 'Minn sweep ' . $tag,
		'fields'   => array(
			array( 'key' => $k . '_shown', 'name' => 'minn_sweep_shown_' . $tag, 'label' => 'Shown', 'type' => 'text' ),
			array( 'key' => $k . '_hidden', 'name' => 'minn_sweep_hidden_' . $tag, 'label' => 'Hidden', 'type' => 'text' ),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
	) );
	acf_add_local_field_group( array(
		'key'      => 'group_minn_sweep_pages_' . $tag,
		'title'    => 'Minn sweep pages ' . $tag,
		'fields'   => array(
			array( 'key' => $k . '_elsewhere', 'name' => 'minn_sweep_elsewhere_' . $tag, 'label' => 'Elsewhere', 'type' => 'text' ),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'page' ) ) ),
	) );
	$hide = static function ( $field ) use ( $k ) {
		return ( $k . '_hidden' ) === ( $field['key'] ?? '' ) ? false : $field;
	};
	add_filter( 'acf/prepare_field', $hide );

	$made  = array();
	$clean = $once( static function () use ( &$made, $hide ) {
		remove_filter( 'acf/prepare_field', $hide );
		foreach ( $made as $p ) {
			if ( $p ) {
				wp_delete_post( $p, true );
			}
		}
	} );
	try {
		foreach ( array( 'admin' => $admin, 'author' => 0 ) as $role => $uid ) {
			if ( 'author' === $role ) {
				$authors = get_users( array( 'role' => 'author', 'number' => 1, 'fields' => 'ID' ) );
				if ( ! $authors ) {
					$skip( 'S1 no Author on this site' );
					continue;
				}
				$uid = (int) $authors[0];
			}
			wp_set_current_user( $uid );
			$src    = wp_insert_post( array( 'post_title' => "minn v049 sweep {$role} {$tag}", 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $uid ) );
			$made[] = $src;
			update_field( $k . '_shown', 'shown value', $src );
			update_field( $k . '_hidden', 'hidden value', $src );
			update_field( $k . '_elsewhere', 'elsewhere value', $src );

			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/posts/{$src}/duplicate" );
			$copy   = (int) ( $d['id'] ?? 0 );
			$made[] = $copy;
			$check( "S1 {$role}: the copy is made", in_array( $st, array( 200, 201 ), true ) && $copy > 0, $st . ' ' . wp_json_encode( $d ) );
			$check( "S1 {$role}: a field ACF shows on the post is copied", 'shown value' === get_post_meta( $copy, 'minn_sweep_shown_' . $tag, true ), wp_json_encode( get_post_meta( $copy ) ) );
			$check( "S1 {$role}: with its field reference", ( $k . '_shown' ) === get_post_meta( $copy, '_minn_sweep_shown_' . $tag, true ) );
			$check( "S1 {$role}: and ACF reads it back", 'shown value' === get_field( $k . '_shown', $copy, false ) );
			$check( "S1 {$role}: a field acf/prepare_field hides stays behind", '' === get_post_meta( $copy, 'minn_sweep_hidden_' . $tag, true ) && '' === get_post_meta( $copy, '_minn_sweep_hidden_' . $tag, true ) );
			$check( "S1 {$role}: a field whose group shows on another type stays behind", '' === get_post_meta( $copy, 'minn_sweep_elsewhere_' . $tag, true ) && '' === get_post_meta( $copy, '_minn_sweep_elsewhere_' . $tag, true ) );
		}
	} finally {
		wp_set_current_user( $admin );
		$clean();
	}
} )();

/*
 * S1b: clones, repeater rows and role-limited groups, as ACF's screen shows them.
 */
( static function () use ( $check, $skip, $once, $call, $admin, $tag ) {
	if ( ! function_exists( 'acf_add_local_field_group' ) || ! acf_get_field_type( 'repeater' ) || ! acf_get_field_type( 'clone' ) ) {
		$skip( 'S1b needs ACF Pro' );
		return;
	}
	$editors = get_users( array( 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ) );
	if ( ! $editors ) {
		$skip( 'S1b no Editor on this site' );
		return;
	}
	$editor = (int) $editors[0];
	$p      = 'field_minn_sweep2_' . $tag;
	// A source group shown nowhere, cloned seamlessly into the visible one.
	acf_add_local_field_group( array(
		'key'      => 'group_minn_sweep2_src_' . $tag,
		'title'    => 'Minn sweep clone source ' . $tag,
		'fields'   => array( array( 'key' => $p . '_src', 'name' => 'minn_sweep2_cloned_' . $tag, 'label' => 'Cloned', 'type' => 'text' ) ),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'minn_nowhere_' . $tag ) ) ),
	) );
	acf_add_local_field_group( array(
		'key'      => 'group_minn_sweep2_' . $tag,
		'title'    => 'Minn sweep rows ' . $tag,
		'fields'   => array(
			array( 'key' => $p . '_clone', 'name' => 'minn_sweep2_clone_' . $tag, 'label' => 'Clone', 'type' => 'clone', 'clone' => array( $p . '_src' ), 'display' => 'seamless', 'prefix_name' => 0, 'prefix_label' => 0 ),
			array(
				'key'        => $p . '_rows',
				'name'       => 'minn_sweep2_rows_' . $tag,
				'label'      => 'Rows',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => $p . '_open', 'name' => 'open', 'label' => 'Open', 'type' => 'text' ),
					array( 'key' => $p . '_shut', 'name' => 'shut', 'label' => 'Shut', 'type' => 'text' ),
				),
			),
			array( 'key' => $p . '_locked', 'name' => 'minn_sweep2_locked_' . $tag, 'label' => 'Locked', 'type' => 'text' ),
			array( 'key' => $p . '_odd', 'name' => 'minn_sweep2_odd_' . $tag, 'label' => 'Odd', 'type' => 'text' ),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
	) );
	acf_add_local_field_group( array(
		'key'      => 'group_minn_sweep2_admins_' . $tag,
		'title'    => 'Minn sweep admins ' . $tag,
		'fields'   => array( array( 'key' => $p . '_admins', 'name' => 'minn_sweep2_admins_' . $tag, 'label' => 'Admins only', 'type' => 'text' ) ),
		'location' => array( array( array( 'param' => 'current_user_role', 'operator' => '==', 'value' => 'administrator' ) ) ),
	) );
	$hide = static function ( $field ) use ( $p ) {
		if ( ( $p . '_locked' ) === ( $field['key'] ?? '' ) ) {
			$field['readonly'] = 1;
		}
		return ( $p . '_shut' ) === ( $field['key'] ?? '' ) ? false : $field;
	};
	add_filter( 'acf/prepare_field', $hide );

	$made  = array();
	$clean = $once( static function () use ( &$made, $hide ) {
		remove_filter( 'acf/prepare_field', $hide );
		foreach ( $made as $id ) {
			if ( $id ) {
				wp_delete_post( $id, true );
			}
		}
	} );
	try {
		wp_set_current_user( $admin );
		$src    = wp_insert_post( array( 'post_title' => "minn v049 sweep rows {$tag}", 'post_status' => 'draft', 'post_type' => 'post', 'post_author' => $admin ) );
		$made[] = $src;
		update_field( $p . '_clone', array( $p . '_clone_' . $p . '_src' => 'clone value' ), $src );
		update_field( $p . '_rows', array( array( $p . '_open' => 'row open', $p . '_shut' => 'row shut' ) ), $src );
		update_field( $p . '_admins', 'admins value', $src );
		update_field( $p . '_locked', 'locked value', $src );
		// A value whose protected twin holds something other than a field key.
		update_post_meta( $src, 'minn_sweep2_odd_' . $tag, 'odd value' );
		update_post_meta( $src, '_minn_sweep2_odd_' . $tag, 'not a field key' );
		$check( 'S1b setup: the clone value is stored by name', 'clone value' === get_post_meta( $src, 'minn_sweep2_cloned_' . $tag, true ), wp_json_encode( get_post_meta( $src ) ) );

		foreach ( array( 'admin' => $admin, 'editor' => $editor ) as $role => $uid ) {
			wp_set_current_user( $uid );
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/posts/{$src}/duplicate" );
			$copy   = (int) ( $d['id'] ?? 0 );
			$made[] = $copy;
			$rows   = 'minn_sweep2_rows_' . $tag;
			$check( "S1b {$role}: the copy is made", $copy > 0, $st . ' ' . wp_json_encode( $d ) );
			$check( "S1b {$role}: a seamless clone of a group shown nowhere copies", 'clone value' === get_post_meta( $copy, 'minn_sweep2_cloned_' . $tag, true ) );
			$check( "S1b {$role}: a repeater's row count and shown sub field copy", '1' === (string) get_post_meta( $copy, $rows, true ) && 'row open' === get_post_meta( $copy, $rows . '_0_open', true ) );
			$check( "S1b {$role}: a sub field acf/prepare_field hides stays behind", '' === get_post_meta( $copy, $rows . '_0_shut', true ) && '' === get_post_meta( $copy, '_' . $rows . '_0_shut', true ) );
			$check( "S1b {$role}: a read-only field copies, as ACF's screen saves it", 'locked value' === get_post_meta( $copy, 'minn_sweep2_locked_' . $tag, true ) );
			$check( "S1b {$role}: a field's value copies without a reference", 'odd value' === get_post_meta( $copy, 'minn_sweep2_odd_' . $tag, true ) );
			$check( "S1b {$role}: a protected twin that isn't a field key stays behind", '' === get_post_meta( $copy, '_minn_sweep2_odd_' . $tag, true ) );
			$admins = get_post_meta( $copy, 'minn_sweep2_admins_' . $tag, true );
			if ( 'admin' === $role ) {
				$check( 'S1b admin: a group shown to administrators copies for one', 'admins value' === $admins );
			} else {
				$check( 'S1b editor: a group shown only to administrators stays behind', '' === $admins, (string) $admins );
			}
		}
	} finally {
		wp_set_current_user( $admin );
		$clean();
	}
} )();

/*
 * S2: Ninja Forms' PayPal connection row is a credential.
 */
( static function () use ( $check, $skip, $once, $tag ) {
	global $wpdb;
	if ( ! class_exists( 'Minn_Admin_DB' ) ) {
		$skip( 'S2 no database browser' );
		return;
	}
	if ( false !== get_option( 'nf_paypal_connect' ) ) {
		$skip( 'S2 nf_paypal_connect already exists here; not touching a real connection' );
		return;
	}
	$secret = 'nfpp' . $tag . 'Qx7Lm2Vb9Rt4Kw8Zs3Hd6Jf1';
	add_option( 'nf_paypal_connect', array( 'site_id' => 'site-' . $tag, 'credential' => $secret, 'tracking_id' => 'trk-' . $tag, 'merchant_id' => '', 'attempts' => array() ), '', false );
	$clean = $once( static function () {
		delete_option( 'nf_paypal_connect' );
	} );
	try {
		if ( method_exists( 'Minn_Admin_DB', 'forget_credentials' ) ) {
			Minn_Admin_DB::forget_credentials();
		}
		$id  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'nf_paypal_connect' ) );
		$res = rest_get_server()->dispatch( ( static function () use ( $wpdb, $id ) {
			$r = new WP_REST_Request( 'GET', '/minn-admin/v1/db/row' );
			$r->set_param( 'table', $wpdb->options );
			$r->set_param( 'pk', wp_json_encode( array( 'option_id' => $id ) ) );
			return $r;
		} )() );
		$body = (string) wp_json_encode( $res->get_data() );
		$check( 'S2 the database browser answers for the row', 200 === $res->get_status(), $res->get_status() . ' ' . substr( $body, 0, 200 ) );
		$check( 'S2 Ninja Forms\' PayPal signing secret is redacted', false === strpos( $body, $secret ), substr( $body, 0, 300 ) );
	} finally {
		$clean();
	}
} )();

/*
 * S3: a relationship saved from Minn goes through ACF's submitted-values
 * checks (ACF 6.8.11+): a post this user can't read isn't added, one already
 * stored stays, and a bidirectional field adds its back-reference only to an
 * item this user may edit.
 */
( static function () use ( $check, $skip, $once, $admin, $tag ) {
	if ( ! function_exists( '_acf_save_submitted_values' ) || ! function_exists( 'minn_admin_acf_raw_update' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
		$skip( 'S3 needs ACF 6.8.11 or later' );
		return;
	}
	$authors = get_users( array( 'role' => 'author', 'number' => 1, 'fields' => 'ID' ) );
	if ( ! $authors ) {
		$skip( 'S3 no Author on this site' );
		return;
	}
	$author = (int) $authors[0];
	$rel    = 'field_minn_sweep_rel_' . $tag;
	$bi     = 'field_minn_sweep_bi_' . $tag;
	acf_add_local_field_group( array(
		'key'      => 'group_minn_sweep_rel_' . $tag,
		'title'    => 'Minn sweep relations ' . $tag,
		'fields'   => array(
			array( 'key' => $rel, 'name' => 'minn_sweep_rel_' . $tag, 'label' => 'Related', 'type' => 'relationship', 'post_type' => array( 'post' ), 'return_format' => 'id' ),
			array( 'key' => $bi, 'name' => 'minn_sweep_bi_' . $tag, 'label' => 'Linked', 'type' => 'relationship', 'post_type' => array( 'post' ), 'return_format' => 'id', 'bidirectional' => 1, 'bidirectional_target' => array( $bi ) ),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
	) );
	$untrusted = '__return_false';
	add_filter( 'acf/is_trusted_save_context', $untrusted, 99 );

	$made  = array();
	$clean = $once( static function () use ( &$made, $untrusted ) {
		remove_filter( 'acf/is_trusted_save_context', $untrusted, 99 );
		foreach ( $made as $p ) {
			if ( $p ) {
				wp_delete_post( $p, true );
			}
		}
	} );
	try {
		$mk = static function ( $title, $status, $owner ) use ( &$made, $tag ) {
			$id     = wp_insert_post( array( 'post_title' => "minn v049 sweep {$title} {$tag}", 'post_status' => $status, 'post_type' => 'post', 'post_author' => $owner ) );
			$made[] = $id;
			return (int) $id;
		};
		$secret   = $mk( 'admin private', 'private', $admin );
		$stored   = $mk( 'admin private kept', 'private', $admin );
		$public   = $mk( 'admin published', 'publish', $admin );
		$own      = $mk( 'author own', 'draft', $author );
		$own_peer = $mk( 'author peer', 'draft', $author );

		// What an administrator picked earlier stays when the Author resends it.
		wp_set_current_user( $admin );
		update_field( $rel, array( $stored ), $own );

		wp_set_current_user( $author );
		minn_admin_acf_raw_update( array( 'key' => $rel ), array( $stored, $public, $secret ), $own );
		$saved = array_map( 'intval', (array) get_post_meta( $own, 'minn_sweep_rel_' . $tag, true ) );
		$check( 'S3 a private post the Author can\'t read is not added', ! in_array( $secret, $saved, true ), wp_json_encode( $saved ) );
		$check( 'S3 control: a published post is added', in_array( $public, $saved, true ), wp_json_encode( $saved ) );
		$check( 'S3 control: a post already stored stays', in_array( $stored, $saved, true ), wp_json_encode( $saved ) );

		minn_admin_acf_raw_update( array( 'key' => $bi ), array( $public, $own_peer ), $own );
		$back_public = array_map( 'intval', (array) get_post_meta( $public, 'minn_sweep_bi_' . $tag, true ) );
		$back_peer   = array_map( 'intval', (array) get_post_meta( $own_peer, 'minn_sweep_bi_' . $tag, true ) );
		$check( 'S3 no back-reference is written into a post the Author can\'t edit', ! in_array( $own, $back_public, true ), wp_json_encode( $back_public ) );
		$check( 'S3 control: the back-reference lands on the Author\'s own post', in_array( $own, $back_peer, true ), wp_json_encode( $back_peer ) );
	} finally {
		wp_set_current_user( $admin );
		$clean();
	}
} )();

/*
 * S4: Password Protected's password hash is a credential.
 */
( static function () use ( $check, $skip, $once, $tag ) {
	global $wpdb;
	if ( ! class_exists( 'Minn_Admin_DB' ) ) {
		$skip( 'S4 no database browser' );
		return;
	}
	if ( false !== get_option( 'password_protected_password' ) ) {
		$skip( 'S4 password_protected_password already exists here; not touching it' );
		return;
	}
	$hash = md5( 'minn-sweep-' . $tag );
	add_option( 'password_protected_password', $hash, '', false );
	$clean = $once( static function () {
		delete_option( 'password_protected_password' );
	} );
	try {
		if ( method_exists( 'Minn_Admin_DB', 'forget_credentials' ) ) {
			Minn_Admin_DB::forget_credentials();
		}
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'password_protected_password' ) );
		$r  = new WP_REST_Request( 'GET', '/minn-admin/v1/db/row' );
		$r->set_param( 'table', $wpdb->options );
		$r->set_param( 'pk', wp_json_encode( array( 'option_id' => $id ) ) );
		$res  = rest_get_server()->dispatch( $r );
		$body = (string) wp_json_encode( $res->get_data() );
		$check( 'S4 the database browser answers for the row', 200 === $res->get_status(), (string) $res->get_status() );
		$check( 'S4 Password Protected\'s password hash is redacted', false === strpos( $body, $hash ), substr( $body, 0, 300 ) );
	} finally {
		$clean();
	}
} )();

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
