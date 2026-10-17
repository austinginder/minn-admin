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
			// Gravity Forms' entry screen shows the submitter's login, nothing
			// more of the account; this form editor may not list users, so
			// that is all that fills (security-v046-rc-adapters covers the rest).
			$gf_login = get_userdata( $gf_sub )->user_login;
			list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_nroute}/preview", array( 'subject' => 'S', 'message' => 'by {created_by:user_login} / {created_by:user_login } / {created_by:display_name}' ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( 'OS-6 control: the login the entry screen shows still resolves, with or without a trailing space', 200 === $gf_st && 2 === substr_count( $gf_out, $gf_login ) && false === strpos( $gf_out, 'D45 Submitter' ), substr( $gf_out, 0, 200 ) );

			$gf_croute = "/minn-admin/v1/gf/confirmations/{$gf_a}:d45c";
			foreach ( $gf_variants as $gf_label => $gf_tag ) {
				list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_croute}/preview", array( 'type' => 'message', 'message' => 'M ' . $gf_tag ) );
				$gf_out = wp_json_encode( $gf_d );
				$check( "OS-6 the confirmation preview resolves no submitter meta or page field {$gf_label}", 200 === $gf_st && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 200 ) );
			}
			list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_croute}/preview", array( 'type' => 'redirect', 'url' => 'https://example.com/{created_by_minn_d45_secret}', 'queryString' => 'a={created_by_minn_d45_secret}&b={CREATED_BY:_minn_d45_secret}&c={form_title}&d={{user:description}' ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( 'OS-6 the redirect preview address carries no submitter meta or page field', 200 === $gf_st && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 220 ) );
			list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_croute}/preview", array( 'type' => 'message', 'message' => 'by {created_by:user_login}' ) );
			$check( 'OS-6 control: the confirmation preview still resolves the login', 200 === $gf_st && false !== strpos( wp_json_encode( $gf_d ), $gf_login ), substr( wp_json_encode( $gf_d ), 0, 200 ) );

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
				list( $gf_st, $gf_d ) = $call( 'POST', "{$gf_nroute}/test", array( 'fromName' => '{created_by:user_login}', 'subject' => 'S', 'message' => 'M' ) );
				$gf_out = wp_json_encode( $gf_mail );
				$check( 'OS-7 control: the test still goes to the caller alone, its sender the submitter\'s login', 200 === $gf_st && 1 === count( $gf_mail ) && false !== strpos( wp_json_encode( $gf_mail[0]['to'] ?? '' ), $gf_me ) && false !== strpos( $gf_out, $gf_login ), $gf_st . ' ' . substr( $gf_out, 0, 240 ) );
			} finally {
				remove_filter( 'pre_wp_mail', $gf_trap, 1 );
			}

			// --- OS-16 Gravity Forms: one form's entries list reads the form in its URL
			list( $gf_st, $gf_d ) = $call_q( 'GET', "/minn-admin/v1/gf/forms/{$gf_a}/entries", array( 'form' => (string) $gf_b ) );
			$gf_forms = array_values( array_unique( array_map( function ( $e ) {
				return (int) ( $e['form_id'] ?? 0 );
			}, (array) ( $gf_d['entries'] ?? array() ) ) ) );
			$check( 'OS-16 a form\'s entries list reads the form in its URL, not ?form=', 200 === $gf_st && array( $gf_a ) === $gf_forms, $gf_st . ' forms ' . wp_json_encode( $gf_forms ) );
			list( $gf_st, $gf_d ) = $call( 'GET', '/minn-admin/v1/gf/entries' );
			$gf_forms = array_map( function ( $e ) {
				return (int) ( $e['form_id'] ?? 0 );
			}, (array) ( $gf_d['entries'] ?? array() ) );
			$check( 'OS-16 control: the all-forms list still reads every form', 200 === $gf_st && in_array( $gf_a, $gf_forms, true ) && in_array( $gf_b, $gf_forms, true ), $gf_st . ' forms ' . wp_json_encode( array_values( array_unique( $gf_forms ) ) ) );
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

// --- OS-16 Fluent Forms Pro: a preview runs no ChatGPT feed -------------------
// {chat_gpt_response.<form>_<feed>} asks OpenAI for an answer when Fluent
// Forms Pro's ChatGPT module is on, and stores it on the entry. Where Pro is
// not installed, a stand-in with Pro's names records whether the parser
// reached it. Run on a throwaway form in a rolled-back transaction.
if ( ! class_exists( '\\FluentForm\\App\\Services\\FormBuilder\\ShortCodeParser' ) ) {
	$skip( 'OS-16 Fluent Forms inactive' );
} else {
	$ff_calls = array();
	$ff_stub  = ! defined( 'FLUENTFORMPRO' ) && ! class_exists( '\\FluentFormPro\\classes\\Chat\\ChatFieldController' );
	if ( $ff_stub ) {
		define( 'FLUENTFORMPRO', true );
		$GLOBALS['minn_d45_chat_calls'] = &$ff_calls;
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a namespaced stand-in can only be declared this way mid-file.
		eval( 'namespace FluentFormPro\classes\Chat; class ChatFieldController { public $api; public function __construct( $app = null ) { $this->api = new class() { public function isApiEnabled() { return true; } }; } public function chatGPTSubmissionMessageHandler( $form_id, $feed_id, $parser = null ) { $GLOBALS["minn_d45_chat_calls"][] = array( $form_id, $feed_id ); return "d45-openai-answer"; } }' );
	}
	$ff_http = array();
	$ff_mock = function ( $pre, $args, $url ) use ( &$ff_http ) {
		if ( false !== strpos( (string) $url, 'openai.com' ) ) {
			$ff_http[] = $url;
			return array( 'headers' => array(), 'body' => '{"choices":[{"message":{"content":"d45-openai-answer"}}]}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $ff_mock, 1, 3 );
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		$ff_fields = array( 'fields' => array( array( 'element' => 'input_text', 'attributes' => array( 'name' => 'nick' ), 'settings' => array( 'label' => 'Nick' ) ) ) );
		$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'title' => 'd45 chat probe', 'status' => 'published', 'form_fields' => wp_json_encode( $ff_fields ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$ff_form = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'fluentform_submissions', array( 'form_id' => $ff_form, 'response' => wp_json_encode( array( 'nick' => 'Dana' ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$ff_entry = (int) $wpdb->insert_id;
		list( $ff_st, $ff_d ) = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$ff_form}/emails/preview", array( 'subject' => "S {chat_gpt_response.{$ff_form}_7}", 'message' => "A {chat_gpt_response.{$ff_form}_7} B {{x chat_gpt_response.{$ff_form}_8} C {inputs.nick}" ) );
		$ff_out  = wp_json_encode( $ff_d );
		$ff_meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submission_meta WHERE response_id = %d AND meta_key LIKE %s", $ff_entry, 'chat\_gpt\_response%' ) );
		$check( 'OS-16 the Fluent preview asks no ChatGPT feed for an answer and stores none', 200 === $ff_st && ! $ff_calls && ! $ff_http && 0 === $ff_meta && false === strpos( $ff_out, 'd45-openai-answer' ), $ff_st . ' calls ' . count( $ff_calls ) . ' http ' . count( $ff_http ) . ' meta ' . $ff_meta . ' ' . substr( $ff_out, 0, 200 ) );
		$check( 'OS-16 the ChatGPT tag stays a placeholder', 3 === substr_count( $ff_out, "chat_gpt_response.{$ff_form}_" ), substr( $ff_out, 0, 220 ) );
		$check( 'OS-16 control: an answer tag still fills', false !== strpos( $ff_out, 'Dana' ), substr( $ff_out, 0, 220 ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
		remove_filter( 'pre_http_request', $ff_mock, 1 );
	}
	if ( $ff_stub ) {
		echo "      (Fluent Forms Pro is not installed: its ChatGPT module was a stand-in)\n";
	}
}

// --- OS-8 Aryo Activity Log before 2.14: rows still follow Aryo's role scoping --
// Aryo 2.13 and older ship no AAL_Log_Query, so the scope came back '' and
// every query ran unscoped. Where Aryo is not loaded, a stand-in AAL_Main
// gives this request the routes an old Aryo would, without the class.
global $wpdb;
$aal_table = $wpdb->prefix . 'aryo_activity_log';
if ( class_exists( 'AAL_Log_Query' ) ) {
	$skip( 'OS-8 this Aryo ships AAL_Log_Query (2.14+): 10-03 in security-v045-rc covers it' );
} elseif ( $aal_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aal_table ) ) ) {
	$skip( 'OS-8 no Aryo log table on this site' );
} else {
	if ( ! class_exists( 'AAL_Main' ) ) {
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- a stand-in class for this request only.
		eval( 'class AAL_Main {}' );
		// rest_api_init has usually run by now; the kernel registers what is active now.
		if ( did_action( 'rest_api_init' ) ) {
			Minn_Admin_Kernel::register();
		}
	}
	add_role( 'minn_d45_manager', 'D45 manager', array( 'read' => true, 'manage_options' => true, 'edit_posts' => true ) );
	add_role( 'minn_d45_auditor', 'D45 auditor', array( 'read' => true, 'view_all_aryo_activity_log' => true ) );
	$aal_uid = $make_user( 'minn_d45_manager' );
	$aal_aud = $make_user( 'minn_d45_auditor' );
	// An editor granted manage_options as an extra cap: its first role (the
	// key Aryo reads) is editor, so Aryo shows it editor/author/guest rows.
	$aal_ed  = $make_user( 'editor' );
	if ( $aal_ed ) {
		$u = new WP_User( $aal_ed );
		$u->add_cap( 'manage_options' );
	}
	$aal_rows = array();
	foreach ( array(
		array( 'administrator', 'MinnD45AdminAction', '203.0.113.46' ),
		array( 'editor', 'MinnD45EditorAction', '203.0.113.47' ),
		array( 'guest', 'MinnD45GuestAction', '203.0.113.48' ),
		// A type a third party logs outside Aryo's module table.
		array( 'administrator', 'MinnD45CustomType', '203.0.113.49', 'MinnD45Custom' ),
	) as $aal_seed ) {
		$wpdb->insert( $aal_table, array( 'user_caps' => $aal_seed[0], 'action' => 'activated', 'object_type' => $aal_seed[3] ?? 'Plugin', 'object_subtype' => '', 'object_name' => $aal_seed[1], 'object_id' => 0, 'user_id' => $admin, 'hist_ip' => $aal_seed[2], 'hist_time' => (int) current_time( 'timestamp' ) ) );
		$aal_rows[] = (int) $wpdb->insert_id;
	}
	try {
		if ( ! $aal_uid || ! $aal_aud || ! $aal_ed || in_array( 0, $aal_rows, true ) ) {
			$check( 'OS-8 fixtures', false );
		} else {
			wp_set_current_user( $aal_uid );
			list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
			$aal_out = wp_json_encode( $aal_d );
			$check( 'OS-8 without AAL_Log_Query a custom role holding manage_options reads no administrator\'s action', 200 === $aal_st && false === strpos( $aal_out, 'MinnD45AdminAction' ) && false === strpos( $aal_out, '203.0.113.46' ), $aal_st . ' ' . substr( $aal_out, 0, 160 ) );
			list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/status' );
			$check( 'OS-8 and its status card counts none of them', 200 === $aal_st && '0' === ( $aal_d['rows'][1]['value'] ?? null ), $aal_st . ' ' . substr( wp_json_encode( $aal_d['rows'] ?? null ), 0, 160 ) );
			wp_set_current_user( $aal_ed );
			list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
			$aal_out = wp_json_encode( $aal_d );
			$check( 'OS-8 an editor granted manage_options reads editor actions but not an administrator\'s', 200 === $aal_st && false !== strpos( $aal_out, 'MinnD45EditorAction' ) && false === strpos( $aal_out, 'MinnD45AdminAction' ), $aal_st . ' ' . substr( $aal_out, 0, 160 ) );
			// Aryo's own aal_init_caps filter narrows that row, and old Aryo
			// shows the editor only what is left.
			$aal_narrow = function ( $caps ) {
				$caps['editor'] = array( 'guest' );
				return $caps;
			};
			add_filter( 'aal_init_caps', $aal_narrow );
			list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
			remove_filter( 'aal_init_caps', $aal_narrow );
			$aal_out = wp_json_encode( $aal_d );
			$check( 'OS-8 a site narrowing editor\'s row through aal_init_caps shows that editor only the rows left', 200 === $aal_st && false !== strpos( $aal_out, 'MinnD45GuestAction' ) && false === strpos( $aal_out, 'MinnD45EditorAction' ) && false === strpos( $aal_out, 'MinnD45AdminAction' ), $aal_st . ' ' . substr( $aal_out, 0, 160 ) );
			wp_set_current_user( $aal_aud );
			list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
			$check( 'OS-8 control: an account Aryo lets view everything still reads it', 200 === $aal_st && false !== strpos( wp_json_encode( $aal_d ), 'MinnD45AdminAction' ), (string) $aal_st );
			wp_set_current_user( $admin );
			list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
			$check( 'OS-8 control: an administrator still reads it', 200 === $aal_st && false !== strpos( wp_json_encode( $aal_d ), 'MinnD45AdminAction' ), (string) $aal_st );
			$check( 'OS-8 an object type outside Aryo\'s module table stays hidden, from an administrator too', 200 === $aal_st && false === strpos( wp_json_encode( $aal_d ), 'MinnD45CustomType' ), substr( wp_json_encode( $aal_d ), 0, 160 ) );
			$aal_types = function ( $roles ) {
				$roles['manage_options'][] = 'MinnD45Custom';
				return $roles;
			};
			add_filter( 'aal_init_roles', $aal_types );
			list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
			remove_filter( 'aal_init_roles', $aal_types );
			$check( 'OS-8 control: a type the site adds through aal_init_roles shows', 200 === $aal_st && false !== strpos( wp_json_encode( $aal_d ), 'MinnD45CustomType' ), (string) $aal_st );
		}
	} finally {
		wp_set_current_user( $admin );
		foreach ( $aal_rows as $aal_row ) {
			if ( $aal_row ) {
				$wpdb->delete( $aal_table, array( 'histid' => $aal_row ) );
			}
		}
		$drop_user( $aal_uid );
		$drop_user( $aal_aud );
		$drop_user( $aal_ed );
		remove_role( 'minn_d45_manager' );
		remove_role( 'minn_d45_auditor' );
	}
}

// --- NI-4, OS-10 WooCommerce: settings and shipping act on the page and zone in the URL
// The settings save checked the URL's page against the sections Minn lists
// but saved the body's, and the shipping helpers read zone and instance
// from the body, so a DELETE of zone 5 carrying {"zone":7} deleted zone 7.
if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'minn_admin_wc_settings_pages' ) ) {
	$skip( 'NI-4/OS-10 WooCommerce inactive' );
} else {
	$wc_uid = $make_user( 'shop_manager' );
	if ( ! $wc_uid ) {
		$check( 'NI-4 fixture user', false );
	} else {
		wp_set_current_user( $wc_uid );
		// Every listener of a settings save is held back for the run, so
		// nothing is written, and a recorder on each page's first hook says
		// which page the save reached.
		global $wp_filter;
		$wc_saved = array();
		$wc_held  = array();
		$wc_flush = get_option( 'woocommerce_queue_flush_rewrite_rules', null );
		$wc_hooks = array( 'woocommerce_update_options', 'woocommerce_settings_saved' );
		foreach ( array_keys( minn_admin_wc_settings_pages() ) as $wc_tab ) {
			$wc_hooks[] = 'woocommerce_settings_save_' . $wc_tab;
			$wc_hooks[] = 'woocommerce_update_options_' . $wc_tab;
		}
		foreach ( $wc_hooks as $wc_h ) {
			$wc_held[ $wc_h ] = $wp_filter[ $wc_h ] ?? null;
			unset( $wp_filter[ $wc_h ] );
			if ( 0 === strpos( $wc_h, 'woocommerce_settings_save_' ) ) {
				$wc_tab = substr( $wc_h, strlen( 'woocommerce_settings_save_' ) );
				add_action( $wc_h, function () use ( $wc_tab, &$wc_saved ) {
					$wc_saved[] = $wc_tab;
				} );
			}
		}
		try {
			$wc_pages = minn_admin_wc_settings_pages();
			$wc_other = array_values( array_intersect( array( 'subscriptions', 'checkout' ), array_keys( $wc_pages ) ) );
			foreach ( $wc_other as $wc_tab ) {
				$wc_saved = array();
				list( $wc_st ) = $call( 'POST', '/minn-admin/v1/wc/settings/general/default', array( 'page' => $wc_tab, 'section' => 'default', 'values' => array( 'woocommerce_store_city' => 'D45 City' ) ) );
				$check( "NI-4 a save to general/default whose body names {$wc_tab} saves no {$wc_tab} page", ! in_array( $wc_tab, $wc_saved, true ), $wc_st . ' saved ' . wp_json_encode( $wc_saved ) );
			}
			if ( ! $wc_other ) {
				$skip( 'NI-4 neither Subscriptions nor Payments is registered' );
			}
			$wc_saved = array();
			list( $wc_st ) = $call( 'POST', '/minn-admin/v1/wc/settings/general/default', array( 'values' => array( 'woocommerce_store_city' => 'D45 City' ) ) );
			$check( 'NI-4 control: the URL\'s own page is the one saved', array( 'general' ) === $wc_saved, $wc_st . ' saved ' . wp_json_encode( $wc_saved ) );
			list( $wc_st, $wc_d ) = $call_q( 'GET', '/minn-admin/v1/wc/settings/general/default', array( 'page' => 'tax', 'section' => 'standard' ) );
			$check( 'NI-4 the GET serves the URL\'s section, not ?page=&section=', 200 === $wc_st && 'general' === ( $wc_d['page'] ?? null ) && '' === ( $wc_d['section'] ?? null ), $wc_st . ' ' . wp_json_encode( array( $wc_d['page'] ?? null, $wc_d['section'] ?? null ) ) );
		} finally {
			foreach ( $wc_held as $wc_h => $wc_hook ) {
				if ( null === $wc_hook ) {
					unset( $wp_filter[ $wc_h ] );
				} else {
					$wp_filter[ $wc_h ] = $wc_hook;
				}
			}
			if ( null === $wc_flush ) {
				delete_option( 'woocommerce_queue_flush_rewrite_rules' );
			} else {
				update_option( 'woocommerce_queue_flush_rewrite_rules', $wc_flush );
			}
		}

		// Throwaway zones, each with one flat rate.
		foreach ( WC_Shipping_Zones::get_zones() as $wc_old ) {
			if ( 0 === strpos( (string) $wc_old['zone_name'], 'MinnD45 ' ) ) {
				WC_Shipping_Zones::delete_zone( (int) $wc_old['id'] );
			}
		}
		$wc_zone = function ( $name ) {
			$z = new WC_Shipping_Zone( null );
			$z->set_zone_name( $name );
			$z->save();
			return array( (int) $z->get_id(), (int) $z->add_shipping_method( 'flat_rate' ) );
		};
		list( $wc_za, $wc_ia ) = $wc_zone( 'MinnD45 A' );
		list( $wc_zb, $wc_ib ) = $wc_zone( 'MinnD45 B' );
		try {
			if ( ! $wc_za || ! $wc_zb || ! $wc_ia || ! $wc_ib ) {
				$check( 'OS-10 fixtures', false );
			} else {
				list( $wc_st ) = $call( 'DELETE', "/minn-admin/v1/wc/shipping/zones/{$wc_za}/methods/{$wc_ia}", array( 'zone' => $wc_zb, 'instance' => $wc_ib ) );
				$check( 'OS-10 a method DELETE removes the method in its URL, not the body\'s', 200 === $wc_st && ! WC_Shipping_Zones::get_shipping_method( $wc_ia ) && WC_Shipping_Zones::get_shipping_method( $wc_ib ), $wc_st . ' a ' . wp_json_encode( (bool) WC_Shipping_Zones::get_shipping_method( $wc_ia ) ) . ' b ' . wp_json_encode( (bool) WC_Shipping_Zones::get_shipping_method( $wc_ib ) ) );
				list( $wc_st ) = $call( 'DELETE', "/minn-admin/v1/wc/shipping/zones/{$wc_za}", array( 'zone' => $wc_zb ) );
				$check( 'OS-10 a zone DELETE removes the zone in its URL, not the body\'s', 200 === $wc_st && ! WC_Shipping_Zones::get_zone( $wc_za ) && WC_Shipping_Zones::get_zone( $wc_zb ), $wc_st . ' a ' . wp_json_encode( (bool) WC_Shipping_Zones::get_zone( $wc_za ) ) . ' b ' . wp_json_encode( (bool) WC_Shipping_Zones::get_zone( $wc_zb ) ) );
			}
		} finally {
			foreach ( array( $wc_za, $wc_zb ) as $wc_z ) {
				if ( $wc_z && WC_Shipping_Zones::get_zone( $wc_z ) ) {
					WC_Shipping_Zones::delete_zone( $wc_z );
				}
			}
		}
		wp_set_current_user( $admin );
		$drop_user( $wc_uid );
	}
}

// --- NI-9 Plugin update: an administrator's failed reactivation says so --------
// switched_off was also true when an administrator's reactivation failed, so
// the toast told an administrator their account can't switch plugins on.
// The "update" here drops the plugin's main file, so switching it back on
// fails the way a missing dependency would.
if ( is_multisite() ) {
	$skip( 'NI-9 single site only' );
} elseif ( ! class_exists( 'ZipArchive' ) ) {
	$skip( 'NI-9 no ZipArchive' );
} else {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$up_slug = 'minn-d45-upd';
	$up_file = "{$up_slug}/{$up_slug}.php";
	$up_dir  = WP_PLUGIN_DIR . "/{$up_slug}";
	$up_zip  = get_temp_dir() . 'minn-d45-upd-1.1.zip';
	$up_wipe = function () use ( $up_dir ) {
		foreach ( (array) glob( $up_dir . '/*' ) as $up_f ) {
			unlink( $up_f );
		}
		if ( is_dir( $up_dir ) ) {
			rmdir( $up_dir );
		}
	};
	$up_wipe();
	$up_zipf = new ZipArchive();
	$up_zipf->open( $up_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$up_zipf->addFromString( "{$up_slug}/renamed.php", "<?php\n/**\n * Plugin Name: Minn D45 update probe\n * Version: 1.1\n */\n" );
	$up_zipf->close();
	wp_mkdir_p( $up_dir );
	file_put_contents( "{$up_dir}/{$up_slug}.php", "<?php\n/**\n * Plugin Name: Minn D45 update probe\n * Version: 1.0\n */\n" );
	wp_clean_plugins_cache( false );
	activate_plugin( $up_file );
	$up_t = get_site_transient( 'update_plugins' );
	$up_t = is_object( $up_t ) ? $up_t : new stdClass();
	$up_t->response = isset( $up_t->response ) ? (array) $up_t->response : array();
	$up_t->response[ $up_file ] = (object) array( 'id' => $up_file, 'slug' => $up_slug, 'plugin' => $up_file, 'new_version' => '1.1', 'url' => '', 'package' => $up_zip );
	set_site_transient( 'update_plugins', $up_t );
	// The vendor's upgrade routine switches it off.
	$up_off = function () use ( $up_file ) {
		deactivate_plugins( $up_file, true );
	};
	add_action( 'upgrader_process_complete', $up_off, 1 );
	try {
		list( $up_st, $up_d ) = $call( 'POST', '/minn-admin/v1/plugins/update', array( 'plugin' => $up_file ) );
		$check( 'NI-9 an administrator whose reactivation fails is not told their account can\'t switch plugins on', 200 === $up_st && empty( $up_d['switched_off'] ), $up_st . ' ' . wp_json_encode( $up_d ) );
		$check( 'NI-9 the failed reactivation comes back as such, with WordPress\'s reason', ! empty( $up_d['reactivation_failed'] ) && '' !== (string) ( $up_d['reactivate_error'] ?? '' ), wp_json_encode( $up_d ) );
		$check( 'NI-9 control: the update itself landed', 200 === $up_st && ! empty( $up_d['updated'] ) && file_exists( "{$up_dir}/renamed.php" ), $up_st );
	} finally {
		remove_action( 'upgrader_process_complete', $up_off, 1 );
		deactivate_plugins( $up_file, true );
		$up_t = get_site_transient( 'update_plugins' );
		if ( is_object( $up_t ) && isset( $up_t->response[ $up_file ] ) ) {
			unset( $up_t->response[ $up_file ] );
			set_site_transient( 'update_plugins', $up_t );
		}
		$up_wipe();
		if ( file_exists( $up_zip ) ) {
			unlink( $up_zip );
		}
		wp_clean_plugins_cache( false );
	}
}

// --- OS-14 Permalinks: the main site of a subdirectory network keeps /blog -----
// Core shows that site's structure and bases without the /blog prefix it
// keeps there, and puts it back on save (options-permalink.php); Minn
// stored what the client sent, so picking "Post name" moved every post off
// /blog. No lab network is a subdirectory install, so "this is that site"
// comes from the filter that carries core's test.
global $wp_rewrite;
$pl_prev = array( (string) get_option( 'permalink_structure' ), (string) get_option( 'category_base' ), (string) get_option( 'tag_base' ) );
try {
	$wp_rewrite->set_permalink_structure( '/blog/%year%/%monthnum%/%day%/%postname%/' );
	$wp_rewrite->set_category_base( '/blog/topics' );
	$wp_rewrite->set_tag_base( '' );
	add_filter( 'minn_admin_subdirectory_main_site', '__return_true' );
	add_filter( 'got_url_rewrite', '__return_true' );
	list( $pl_st, $pl_d ) = $call( 'GET', '/minn-admin/v1/permalinks' );
	$check( 'OS-14 the structure and bases are served without /blog, as core shows them', 200 === $pl_st && '/%year%/%monthnum%/%day%/%postname%/' === ( $pl_d['structure'] ?? null ) && '/topics' === ( $pl_d['category_base'] ?? null ) && '' === ( $pl_d['tag_base'] ?? null ), $pl_st . ' ' . wp_json_encode( array( $pl_d['structure'] ?? null, $pl_d['category_base'] ?? null, $pl_d['tag_base'] ?? null ) ) );
	list( $pl_st, $pl_d ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => '/%postname%/', 'category_base' => '/topics', 'tag_base' => 'labels' ) );
	$check( 'OS-14 picking a preset keeps the posts under /blog', 200 === $pl_st && '/blog/%postname%/' === get_option( 'permalink_structure' ), $pl_st . ' ' . get_option( 'permalink_structure' ) );
	// Core reads a base back without its slashes (_wp_filter_taxonomy_base).
	$check( 'OS-14 and a base keeps /blog too', 'blog/topics' === get_option( 'category_base' ) && 'blog/labels' === get_option( 'tag_base' ), wp_json_encode( array( get_option( 'category_base' ), get_option( 'tag_base' ) ) ) );
	remove_filter( 'got_url_rewrite', '__return_true' );
	add_filter( 'got_url_rewrite', '__return_false' );
	list( $pl_st ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => '/index.php/%year%/%postname%/', 'category_base' => '/topics', 'tag_base' => '/labels' ) );
	$check( 'OS-14 where rewriting is unknown, /index.php stands in for /blog, as core does', 200 === $pl_st && '/index.php/%year%/%postname%/' === get_option( 'permalink_structure' ), $pl_st . ' ' . get_option( 'permalink_structure' ) );
	remove_filter( 'got_url_rewrite', '__return_false' );
	remove_filter( 'minn_admin_subdirectory_main_site', '__return_true' );
	$wp_rewrite->set_permalink_structure( '/blog/%postname%/' );
	list( $pl_st ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => '/%year%/%postname%/' ) );
	$check( 'OS-14 control: anywhere else a typed structure is stored as typed', 200 === $pl_st && '/%year%/%postname%/' === get_option( 'permalink_structure' ), $pl_st . ' ' . get_option( 'permalink_structure' ) );
} finally {
	remove_filter( 'minn_admin_subdirectory_main_site', '__return_true' );
	remove_filter( 'got_url_rewrite', '__return_true' );
	remove_filter( 'got_url_rewrite', '__return_false' );
	$wp_rewrite->set_permalink_structure( $pl_prev[0] );
	$wp_rewrite->set_category_base( $pl_prev[1] );
	$wp_rewrite->set_tag_base( $pl_prev[2] );
	flush_rewrite_rules();
	$check( 'OS-14 cleanup: the structure and bases are back', array( (string) get_option( 'permalink_structure' ), (string) get_option( 'category_base' ), (string) get_option( 'tag_base' ) ) === $pl_prev, wp_json_encode( $pl_prev ) );
}

// --- UR Gravity Forms User Registration: a preview mints no password-reset link
// With GF User Registration active, {set_password_url} and {activation_url}
// resolve through its gform_replace_merge_tags callback to a working reset
// link for the account tied to the latest entry (an administrator, through an
// update feed), and minting one writes a new reset key. The guard holds back
// every non-core callback on that filter during a preview or test send and
// neutralizes both tags itself, so they never resolve and no key is written.
if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_guarded' ) ) {
	$skip( 'UR Gravity Forms inactive' );
} else {
	// Find GF User Registration without a hardcoded path: it is either active
	// here, or installed in a sibling Cove site's plugins folder.
	$ur_loaded = class_exists( 'GF_User_Registration' );
	if ( ! $ur_loaded ) {
		$ur_sites = (string) dirname( ABSPATH, 2 );
		$ur_hits  = (array) glob( $ur_sites . '/*/public/wp-content/plugins/gravityformsuserregistration/userregistration.php' );
		if ( $ur_hits ) {
			require_once $ur_hits[0];
			if ( class_exists( 'GF_User_Registration_Bootstrap' ) && method_exists( 'GF_User_Registration_Bootstrap', 'load' ) ) {
				GF_User_Registration_Bootstrap::load();
			}
			$ur_loaded = class_exists( 'GF_User_Registration' ) && function_exists( 'gf_user_registration' );
		}
	}
	if ( ! $ur_loaded ) {
		$skip( 'UR GF User Registration not installed in any sibling site' );
	} else {
		$ur_http = function () {
			return new WP_Error( 'minn_d45_no_http', 'blocked' );
		};
		add_filter( 'pre_http_request', $ur_http, 1 );
		// Register the add-on's merge-tag callback the way its pre_init does,
		// unless this site already loaded it.
		$ur       = gf_user_registration();
		$ur_cb    = array( $ur, 'replace_merge_tags' );
		$ur_added = false;
		if ( false === has_filter( 'gform_replace_merge_tags', $ur_cb ) ) {
			add_filter( 'gform_replace_merge_tags', $ur_cb, 10, 7 );
			$ur_added = true;
		}
		foreach ( GFAPI::get_forms( null ) as $ur_old ) {
			if ( 0 === strpos( (string) $ur_old['title'], 'MinnD45UR ' ) ) {
				GFAPI::delete_form( (int) $ur_old['id'] );
			}
		}
		add_role( 'minn_d45_urmgr', 'D45 UR manager', array( 'read' => true, 'gravityforms_edit_forms' => true, 'gravityforms_view_entries' => true ) );
		$ur_mgr  = $make_user( 'minn_d45_urmgr' );
		$ur_reg  = $make_user( 'administrator' );
		$ur_note = array( 'd45ur' => array( 'id' => 'd45ur', 'name' => 'D45', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'S', 'message' => 'M', 'isActive' => true ) );
		$ur_conf = array( 'd45urc' => array( 'id' => 'd45urc', 'name' => 'D45', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks' ) );
		$ur_form = GFAPI::add_form( array( 'title' => 'MinnD45UR form', 'fields' => array(), 'notifications' => $ur_note, 'confirmations' => $ur_conf ) );
		$ur_form = is_int( $ur_form ) && $ur_form > 0 ? $ur_form : 0;
		$ur_entry = 0;
		$ur_feed  = 0;
		try {
			if ( ! $ur_mgr || ! $ur_reg || ! $ur_form ) {
				$check( 'UR fixtures', false, 'could not create the accounts or the form' );
			} else {
				$ur_entry = GFAPI::add_entry( array( 'form_id' => $ur_form, 'created_by' => $ur_reg ) );
				$ur_entry = is_int( $ur_entry ) ? $ur_entry : 0;
				// The add-on links the registrant to the entry through user meta.
				update_user_meta( $ur_reg, 'entry_id', $ur_entry );
				if ( is_callable( array( 'GFAPI', 'add_feed' ) ) ) {
					$ur_feed = (int) GFAPI::add_feed( $ur_form, array( 'feedName' => 'D45', 'feedType' => 'create', 'password' => '', 'sendEmail' => true ), 'gravityformsuserregistration' );
				}
				$ur_key_before = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $ur_reg ) );
				$ur_leaks = function ( $out ) {
					$out = urldecode( (string) $out );
					return false !== strpos( $out, 'action=rp' ) || false !== strpos( $out, 'gfur_activation=' );
				};
				$ur_tags = 'A {set_password_url} B {activation_url} C {form_title}';
				wp_set_current_user( $ur_mgr );
				list( $ur_st, $ur_d ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$ur_form}:d45ur/preview", array( 'subject' => 'S ' . $ur_tags, 'message' => 'M ' . $ur_tags ) );
				$ur_out = wp_json_encode( $ur_d );
				$check( 'UR the notification preview mints no reset or activation link', 200 === $ur_st && ! $ur_leaks( $ur_out ), $ur_st . ' ' . substr( $ur_out, 0, 200 ) );
				$check( 'UR the notification preview leaves both tags as placeholders', (bool) preg_match( '/\[(tag: )?set_password_url\]/', $ur_out ) && (bool) preg_match( '/\[(tag: )?activation_url\]/', $ur_out ), substr( $ur_out, 0, 200 ) );
				list( $ur_st, $ur_d ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$ur_form}:d45urc/preview", array( 'type' => 'message', 'message' => 'M ' . $ur_tags ) );
				$ur_out = wp_json_encode( $ur_d );
				$check( 'UR the confirmation preview mints no reset or activation link', 200 === $ur_st && ! $ur_leaks( $ur_out ), $ur_st . ' ' . substr( $ur_out, 0, 200 ) );
				// The test send is mailed to the caller; catch it before it leaves.
				$ur_mail = array();
				$ur_trap = function ( $pre, $atts ) use ( &$ur_mail ) {
					$ur_mail[] = $atts;
					return true;
				};
				add_filter( 'pre_wp_mail', $ur_trap, 1, 2 );
				try {
					list( $ur_st, $ur_d ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$ur_form}:d45ur/test", array( 'subject' => 'S ' . $ur_tags, 'message' => 'M ' . $ur_tags ) );
				} finally {
					remove_filter( 'pre_wp_mail', $ur_trap, 1 );
				}
				$check( 'UR the test send mails no reset or activation link', ! $ur_leaks( wp_json_encode( $ur_mail ) ) && ( 200 !== $ur_st || 1 === count( $ur_mail ) ), $ur_st . ' ' . count( $ur_mail ) . ' ' . substr( wp_json_encode( $ur_mail ), 0, 200 ) );
				$ur_key_after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_activation_key FROM {$wpdb->users} WHERE ID = %d", $ur_reg ) );
				$check( 'UR no reset key was written for the registrant', $ur_key_before === $ur_key_after, "before '{$ur_key_before}' after '{$ur_key_after}'" );
				// Control: the add-on's tag still resolves on a real render, so
				// only the preview/test guard holds it back.
				wp_set_current_user( $admin );
				$ur_entry_obj = GFAPI::get_entry( $ur_entry );
				$ur_real = (string) GFCommon::replace_variables( '{set_password_url}', GFAPI::get_form( $ur_form ), $ur_entry_obj, false, false, false, 'text' );
				$check( 'UR control: a real render still resolves the reset link', false !== strpos( urldecode( $ur_real ), 'action=rp' ) && false !== strpos( $ur_real, 'key=' ), substr( $ur_real, 0, 120 ) );
			}
		} finally {
			wp_set_current_user( $admin );
			if ( $ur_feed && is_callable( array( 'GFAPI', 'delete_feed' ) ) ) {
				GFAPI::delete_feed( $ur_feed );
			}
			if ( $ur_entry ) {
				GFAPI::delete_entry( $ur_entry );
			}
			if ( $ur_form ) {
				GFAPI::delete_form( $ur_form );
			}
			$drop_user( $ur_mgr );
			$drop_user( $ur_reg );
			remove_role( 'minn_d45_urmgr' );
			if ( $ur_added ) {
				remove_filter( 'gform_replace_merge_tags', $ur_cb, 10 );
			}
			remove_filter( 'pre_http_request', $ur_http, 1 );
		}
	}
}

$summary();
