<?php
/**
 * Regressions for the v0.45.0 fix-delta review, round 1: form previews,
 * path parameters and core REST routes.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what got stored, sent or
 * served, with a control where a fix could over-block. Sections SKIP when
 * their plugin is inactive.
 *
 * Run: wp eval-file tests/security-v045-delta-routes.test.php --user=admin --path=<site> [--url=<subsite>]
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	set_time_limit( 0 );
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v045-delta-routes: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};
// A query string the way a browser sends one: in the GET bag, beside the
// URL parameters the router matched.
$call_q  = function ( $method, $route, $query, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	$r->set_query_params( $query );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( ! is_int( $id ) || $id <= 1 ) {
		return false;
	}
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

// Leftovers of an interrupted run, by login.
foreach ( get_users( array( 'search' => 'minn-d45-*', 'search_columns' => array( 'user_login' ), 'blog_id' => 0 ) ) as $d45_old ) {
	$drop_user( (int) $d45_old->ID );
}

// A throwaway account of one role; the id is checked before any later
// delete, since a WP_Error cast to int names user 1.
$make_user = function ( $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'minn-d45-' . $role . '-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'minn-d45-' . $role . '-' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => $role,
		)
	);
	return is_int( $id ) && $id > 1 ? $id : 0;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// --- OS-6, OS-7 Gravity Forms: previews and test sends resolve no tag past the entry
// Gravity Forms' own created_by match makes the colon optional, so
// {created_by_two_factor_totp_key} read the submitter's meta in both
// previews, and the test send left from, fromName and replyTo as typed.
if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_neutral_tags' ) ) {
	$skip( 'OS-6/OS-7 Gravity Forms inactive' );
} else {
	foreach ( GFAPI::get_forms( null ) as $gf_old ) {
		if ( 0 === strpos( (string) $gf_old['title'], 'MinnD45 ' ) ) {
			GFAPI::delete_form( (int) $gf_old['id'] );
		}
	}
	add_role( 'minn_d45_gf', 'D45 form editor', array( 'read' => true, 'gravityforms_edit_forms' => true, 'gravityforms_view_entries' => true ) );
	$gf_secret = 'd45seed' . wp_generate_password( 10, false );
	$gf_sub    = $make_user( 'subscriber' );
	$gf_ed     = $make_user( 'minn_d45_gf' );
	$gf_page   = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'MinnD45GfPage', 'post_status' => 'draft', 'post_password' => 'd45pagepw', 'post_content' => 'd45pagebody', 'post_author' => $admin ) );
	$gf_page   = is_int( $gf_page ) && $gf_page > 1 ? $gf_page : 0;
	$gf_note   = array( 'd45n' => array( 'id' => 'd45n', 'name' => 'D45', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'S', 'message' => 'M', 'isActive' => true ) );
	$gf_conf   = array( 'd45c' => array( 'id' => 'd45c', 'name' => 'D45', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks' ) );
	// The title is the form editor's to set; a tag in it is resolved after
	// {form_title} is.
	$gf_a      = GFAPI::add_form( array( 'title' => 'MinnD45 A {created_by:_minn_d45_secret} {embed_post:post_password} {custom_field:_minn_d45_gf}', 'fields' => array(), 'notifications' => $gf_note, 'confirmations' => $gf_conf ) );
	$gf_b      = GFAPI::add_form( array( 'title' => 'MinnD45 B', 'fields' => array(), 'notifications' => $gf_note, 'confirmations' => $gf_conf ) );
	$gf_a      = is_int( $gf_a ) && $gf_a > 0 ? $gf_a : 0;
	$gf_b      = is_int( $gf_b ) && $gf_b > 0 ? $gf_b : 0;
	$gf_entry  = 0;
	$gf_entry_b = 0;
	try {
		if ( ! $gf_sub || ! $gf_ed || ! $gf_page || ! $gf_a || ! $gf_b ) {
			$check( 'OS-6 fixtures', false, 'could not create the accounts, the page or the forms' );
		} else {
			update_user_meta( $gf_sub, '_minn_d45_secret', $gf_secret );
			update_post_meta( $gf_page, '_minn_d45_gf', $gf_secret );
			wp_update_user( array( 'ID' => $gf_sub, 'display_name' => 'D45 Submitter', 'first_name' => 'D45First' ) );
			// The viewer's own profile is theirs to write, and {user:*} is
			// resolved before {created_by:*}.
			update_user_meta( $gf_ed, 'description', 'created_by_minn_d45_secret}' );
			$gf_entry   = GFAPI::add_entry( array( 'form_id' => $gf_a, 'created_by' => $gf_sub, 'source_id' => $gf_page, 'source_url' => get_permalink( $gf_page ) ) );
			$gf_entry_b = GFAPI::add_entry( array( 'form_id' => $gf_b, 'created_by' => $gf_sub ) );
			$gf_entry   = is_int( $gf_entry ) ? $gf_entry : 0;
			$gf_entry_b = is_int( $gf_entry_b ) ? $gf_entry_b : 0;
			$gf_leaks   = function ( $out ) use ( $gf_secret ) {
				$out = urldecode( (string) $out );
				return false !== strpos( $out, $gf_secret ) || false !== strpos( $out, 'd45pagepw' ) || false !== strpos( $out, 'd45pagebody' );
			};
			$gf_variants = array(
				'with no colon'                       => '{created_by_minn_d45_secret}',
				'in upper case'                       => '{CREATED_BY:_minn_d45_secret}',
				'with a trailing space'               => '{Created_By_minn_d45_secret }',
				'inside another tag'                  => '{created_by:{created_by_minn_d45_secret}}',
				'in doubled braces'                   => '{{created_by_minn_d45_secret}}',
				'assembled from the form title'       => '{form_title}',
				'assembled from the viewer\'s profile' => '{{user:description}',
			);
			wp_set_current_user( $gf_ed );
			$gf_nroute = "/minn-admin/v1/gf/notifications/{$gf_a}:d45n";
			foreach ( $gf_variants as $gf_label => $gf_tag ) {
				list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_nroute}/preview", array( 'subject' => 'S ' . $gf_tag, 'message' => 'M ' . $gf_tag ) );
				$gf_out = wp_json_encode( $gf_d );
				$check( "OS-6 the notification preview resolves no submitter meta or page field {$gf_label}", 200 === $gf_st && (int) ( $gf_d['entry'] ?? 0 ) === $gf_entry && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 200 ) );
			}
			// A key runs across lines where nothing turns a newline into <br />.
			list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_nroute}/preview", array( 'subject' => "S {created_by:_minn_d45_secret\n}", 'message' => "M {created_by_minn_d45_secret\n}", 'disableAutoformat' => true ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( 'OS-6 the notification preview resolves no submitter meta split across lines', 200 === $gf_st && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 200 ) );
			list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_nroute}/preview", array( 'subject' => 'S', 'message' => 'by {created_by:display_name} / {created_by:display_name } / {created_by:first_name} / {created_by:roles}' ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( 'OS-6 control: a profile field the entry screen shows still resolves, with or without a trailing space', 200 === $gf_st && 2 === substr_count( $gf_out, 'D45 Submitter' ) && false !== strpos( $gf_out, 'D45First' ) && false !== strpos( $gf_out, 'subscriber' ), substr( $gf_out, 0, 200 ) );

			$gf_croute = "/minn-admin/v1/gf/confirmations/{$gf_a}:d45c";
			foreach ( $gf_variants as $gf_label => $gf_tag ) {
				list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_croute}/preview", array( 'type' => 'message', 'message' => 'M ' . $gf_tag ) );
				$gf_out = wp_json_encode( $gf_d );
				$check( "OS-6 the confirmation preview resolves no submitter meta or page field {$gf_label}", 200 === $gf_st && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 200 ) );
			}
			list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_croute}/preview", array( 'type' => 'redirect', 'url' => 'https://example.com/{created_by_minn_d45_secret}', 'queryString' => 'a={created_by_minn_d45_secret}&b={CREATED_BY:_minn_d45_secret}&c={form_title}&d={{user:description}' ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( 'OS-6 the redirect preview address carries no submitter meta or page field', 200 === $gf_st && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 220 ) );
			list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_croute}/preview", array( 'type' => 'message', 'message' => 'by {created_by:display_name}' ) );
			$check( 'OS-6 control: the confirmation preview still resolves a profile field', 200 === $gf_st && false !== strpos( wp_json_encode( $gf_d ), 'D45 Submitter' ), substr( wp_json_encode( $gf_d ), 0, 200 ) );

			// The test send goes through wp_mail; it is caught before anything
			// leaves the site.
			$gf_mail = array();
			$gf_trap = function ( $pre, $atts ) use ( &$gf_mail ) {
				$gf_mail[] = $atts;
				return true;
			};
			add_filter( 'pre_wp_mail', $gf_trap, 1, 2 );
			try {
				$gf_me = get_userdata( $gf_ed )->user_email;
				foreach ( array(
					'fromName' => array( 'fromName' => '{created_by_minn_d45_secret}' ),
					'from'     => array( 'from' => '{created_by:_minn_d45_secret}' ),
					'replyTo'  => array( 'replyTo' => '{CREATED_BY:_minn_d45_secret}' ),
					'fromName assembled from the form title' => array( 'fromName' => '{form_title}' ),
				) as $gf_label => $gf_body ) {
					$gf_mail = array();
					list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_nroute}/test", $gf_body + array( 'subject' => 'S', 'message' => 'M' ) );
					$gf_out = wp_json_encode( $gf_mail );
					$check( "OS-7 the test send puts no submitter meta or page field in {$gf_label}", ! $gf_leaks( $gf_out ) && ( 200 !== $gf_st || 1 === count( $gf_mail ) ), $gf_st . ' ' . count( $gf_mail ) . ' ' . substr( $gf_out, 0, 240 ) );
				}
				$gf_mail = array();
				list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_nroute}/test", array( 'fromName' => '{created_by:display_name}', 'subject' => 'S', 'message' => 'M' ) );
				$gf_out = wp_json_encode( $gf_mail );
				$check( 'OS-7 control: the test still goes to the caller alone, its sender a profile field', 200 === $gf_st && 1 === count( $gf_mail ) && false !== strpos( wp_json_encode( $gf_mail[0]['to'] ?? '' ), $gf_me ) && false !== strpos( $gf_out, 'D45 Submitter' ), $gf_st . ' ' . substr( $gf_out, 0, 240 ) );
			} finally {
				remove_filter( 'pre_wp_mail', $gf_trap, 1 );
			}

		}
	} finally {
		wp_set_current_user( $admin );
		foreach ( array( $gf_a, $gf_b ) as $gf_f ) {
			if ( $gf_f ) {
				GFAPI::delete_form( $gf_f );
			}
		}
		if ( $gf_page ) {
			wp_delete_post( $gf_page, true );
		}
		foreach ( array( $gf_sub, $gf_ed ) as $gf_u ) {
			$drop_user( $gf_u );
		}
		remove_role( 'minn_d45_gf' );
	}
}

$summary();
