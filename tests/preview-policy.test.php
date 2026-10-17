<?php
/**
 * The shared preview policy (Minn_Admin_Preview): an email preview, and a
 * test send to the person previewing, resolves only the tags its adapter's
 * allowlist names.
 *
 * For each form plugin that is active, a throwaway form and entry are
 * previewed through Minn's own route, as an administrator and as a role that
 * edits forms and reads entries but not users (where the vendor grants one).
 * The message carries (a) a field tag, which fills from the entry, (b) the
 * vendor's tag for the submitter's account, (c) a tag an add-on registers
 * here on the vendor's own tag hook, answering a secret, and (d) a tag
 * nobody registered: only (a) resolves, (b) to (d) come back as placeholders
 * and no secret appears. A control renders the same tags through the
 * vendor's own render or send path, mail blocked, and finds them resolved
 * as before. Everything is written inside a transaction that is rolled back;
 * an account still there afterwards is deleted behind an id check.
 *
 * Run: wp eval-file tests/preview-policy.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

global $wpdb;

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};
// Everything a response says, as one string.
$flat = function ( $d ) {
	$out = '';
	array_walk_recursive( $d, function ( $v ) use ( &$out ) {
		$out .= ' ' . ( is_scalar( $v ) ? (string) $v : '' );
	} );
	return $out;
};
$ph   = function ( $out, $name ) {
	return false !== strpos( $out, '[tag: ' . $name );
};
$cut  = function ( $s ) {
	return substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $s ) ) ), 0, 260 );
};

if ( ! class_exists( 'Minn_Admin_Preview' ) && file_exists( dirname( __DIR__ ) . '/includes/class-minn-admin-preview.php' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-minn-admin-preview.php';
}

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

// No mail leaves the site while this runs.
add_filter( 'pre_wp_mail', '__return_false' );

$s_meta  = 'ppmeta' . wp_generate_password( 12, false );
$s_acct  = 'ppacct' . wp_generate_password( 12, false );
$s_addon = 'ppaddon' . wp_generate_password( 12, false );
$s_carry = 'ppcarry' . wp_generate_password( 12, false );
$secrets = function ( $out ) use ( $s_meta, $s_acct, $s_addon, $s_carry ) {
	$found = array();
	foreach ( array( 'submitter meta' => $s_meta, 'submitter account' => $s_acct, 'add-on' => $s_addon, 'carried add-on' => $s_carry ) as $what => $s ) {
		if ( false !== stripos( (string) $out, $s ) ) {
			$found[] = $what;
		}
	}
	return $found;
};

// Everything a section makes is written inside its transaction and listed
// here. A transaction can end early without a word (a deadlock rolls it back
// and later writes autocommit), so after the rollback whatever is still
// there is deleted, each id checked first (a WP_Error cast to int names
// user 1), and the section fails.
$made  = array();
$track = function ( $kind, $value ) use ( &$made ) {
	$made[] = array( $kind, $value );
	return $value;
};
$make_user = function ( $role, $extra = array() ) use ( $track ) {
	$tag = 'minnpp-' . $role . '-' . wp_generate_password( 6, false, false );
	$id  = wp_insert_user( array_merge( array( 'user_login' => $tag, 'user_pass' => wp_generate_password( 24 ), 'user_email' => strtolower( $tag ) . '@example.com', 'role' => $role ), $extra ) );
	return $track( 'user', is_int( $id ) && $id > 1 ? $id : 0 );
};
$sweep = function () use ( $wpdb, &$made ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$left = array();
	foreach ( $made as list( $kind, $v ) ) {
		switch ( $kind ) {
			case 'user':
				if ( is_int( $v ) && $v > 1 && get_userdata( $v ) ) {
					$left[] = "user {$v}";
					wp_delete_user( $v );
				}
				if ( is_int( $v ) && $v > 1 && ! get_userdata( $v ) && $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d", $v ) ) ) {
					$left[] = "usermeta of {$v}";
					$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d", $v ) );
				}
				break;
			case 'post':
				if ( is_int( $v ) && $v > 0 && get_post( $v ) ) {
					$left[] = "post {$v}";
					wp_delete_post( $v, true );
				}
				break;
			case 'gf_form':
				if ( is_int( $v ) && $v > 0 && class_exists( 'GFAPI' ) && GFAPI::form_id_exists( $v ) ) {
					$left[] = "gf form {$v}";
					GFAPI::delete_form( $v );
				}
				break;
			case 'row': // array( table, id column, id )
				if ( is_int( $v[2] ) && $v[2] > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v[0]} WHERE {$v[1]} = %d", $v[2] ) ) ) {
					$left[] = "{$v[0]} {$v[2]}";
					$wpdb->delete( $v[0], array( $v[1] => $v[2] ) );
				}
				break;
			case 'term': // array( term id, taxonomy )
				if ( is_int( $v[0] ) && $v[0] > 0 && term_exists( $v[0], $v[1] ) ) {
					$left[] = "term {$v[0]}";
					wp_delete_term( $v[0], $v[1] );
				}
				break;
			case 'meta': // array( user id, key ) on an account the suite did not make
				if ( is_int( $v[0] ) && $v[0] > 0 && metadata_exists( 'user', $v[0], $v[1] ) ) {
					$left[] = "meta {$v[1]} of {$v[0]}";
					delete_user_meta( $v[0], $v[1] );
				}
				break;
		}
	}
	// remove_role() writes back every role this process holds in memory,
	// the rolled-back ones included, so the stored list is cleaned directly.
	$key   = $wpdb->get_blog_prefix() . 'user_roles';
	$roles = get_option( $key );
	if ( is_array( $roles ) ) {
		$keep = array();
		foreach ( $roles as $role => $def ) {
			if ( 0 === strpos( (string) $role, 'minn_pp_' ) ) {
				$left[] = "role {$role}";
			} else {
				$keep[ $role ] = $def;
			}
		}
		if ( count( $keep ) !== count( $roles ) ) {
			update_option( $key, $keep );
		}
	}
	return $left;
};
$in_transaction = function ( $label, $fn ) use ( $wpdb, $check, $sweep, &$made ) {
	$made = array();
	$wpdb->query( 'START TRANSACTION' );
	$held = true;
	try {
		$fn();
	} catch ( Throwable $e ) {
		$check( "{$label}: ran without an error", false, get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
	} finally {
		wp_set_current_user( 0 );
		$held = '1' === (string) $wpdb->get_var( 'SELECT @@in_transaction' );
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
		$left = $sweep();
		wp_cache_flush();
		if ( ! $held || $left ) {
			$check( "{$label}: every write stayed inside the transaction and was rolled back", false, ( $held ? '' : 'the transaction ended early; ' ) . ( $left ? 'deleted after the rollback: ' . implode( ', ', $left ) : 'nothing was left' ) );
		}
	}
};

// --- The helper itself ---------------------------------------------------------
if ( ! class_exists( 'Minn_Admin_Preview' ) ) {
	$skip( 'helper: Minn_Admin_Preview is not installed' );
} else {
	$r     = function ( $text, $grammar, $allow ) {
		return Minn_Admin_Preview::restrict( $text, $grammar, $allow );
	};
	$cases = array(
		array( 'wpforms', 'A {field_id="3"} {user_meta key="k"}', array( 'field_id' ), 'A {field_id="3"} [tag: user_meta key="k"]', 'an allowlisted tag stays, any other is a placeholder' ),
		array( 'wpforms', '{{user_meta key="k"}}', array( 'user_meta' ), '[tag: user_meta key="k"]}', 'a brace run is one tag with a brace in its name' ),
		array( 'wpforms', '{field_id="{user_meta key="k"}"}', array( 'field_id', 'user_meta' ), '[tag: field_id=" user_meta key="k"]"}', 'a tag nested in an allowlisted one takes it with it' ),
		array( 'wpforms', 'x { y {field_id="1"}', array( 'field_id' ), 'x [tag: y field_id="1"]', 'an unclosed brace ahead of a tag joins it, as their pattern reads it' ),
		array( 'wpforms', 'x { y', array(), 'x { y', 'a brace no pattern can close stays' ),
		array( 'wpforms', "{user_meta\nkey=\"k\"}", array( 'user_meta' ), '[tag: user_meta key="k"]', 'a tag across a line break is no tag here' ),
		array( 'fluent', '{inputs.nick} {wp.user.x} {labels.inputs.x}', array( 'inputs.nick', 'wp.*', 'labels.*' ), '{inputs.nick} [tag: wp.user.x] [tag: labels.inputs.x]', 'a group marker past the start is routed elsewhere by Fluent' ),
		array( 'fluent', '{ inputs.nick} {date.d M Y}', array( 'inputs.nick', 'date.*' ), '[tag: inputs.nick] {date.d M Y}', 'a leading space is not trimmed; a family takes any key' ),
		array( 'gf', '{Nick:1} {entry:1:value} {user:display_name} {created_by:k}', array( ':1', 'user:display_name' ), '{:1} {:1:value} {user:display_name} [tag: created_by:k]', 'a field tag goes over by its id alone' ),
		array( 'gf', '{Nick:9} {:1.3}', array( ':1', ':1.*' ), '[tag: Nick:9] {:1.3}', 'a field the form lacks is a placeholder; an input id joins its field' ),
		array( 'gf', '{created_by:k }', array(), '[tag: created_by:k]', 'a named tag is matched whole' ),
		array( 'cf7', '[your-name] [_user_email] [[_user_email]] [ _site_url ] [x "y"]', array( 'your-name', '_site_url' ), '[your-name] [tag: _user_email] [[tag: _user_email]] [ _site_url ] [tag: x "y"]', 'bracket tags by CF7\'s pattern, spaces and values included' ),
		array( 'cf7', "[_user\n_email] [\n_user_email\n]", array(), "[_user\n_email] [tag: _user_email]", 'a tag across a line break is still read, as the fill reads it' ),
	);
	foreach ( $cases as $c ) {
		$got = $r( $c[1], $c[0], $c[2] );
		$check( "helper ({$c[0]}): {$c[4]}", $c[3] === $got, wp_json_encode( $got ) );
	}
	$check( 'helper: a placeholder is read as no tag by any grammar', ! preg_match( '/[{}]/', Minn_Admin_Preview::placeholder( '{x}' ) ) && ! preg_match( Minn_Admin_Preview::CF7_TAG, Minn_Admin_Preview::placeholder( '[_x "y"]' ) ) && ! preg_match( '/\[\s*([a-zA-Z_][a-zA-Z0-9_-]*)\s*\]/', Minn_Admin_Preview::placeholder( '[_x]' ) ), Minn_Admin_Preview::placeholder( '[_x "y"]' ) );
	$pp_tr = function ( $tr, $text, $domain ) {
		return 'minn-admin' === $domain && '[tag: %s]' === $text ? '{etiqueta: %s}' : $tr;
	};
	add_filter( 'gettext', $pp_tr, 10, 3 );
	$check( 'helper: a translation that would read as a tag falls back to the English', '[tag: x]' === Minn_Admin_Preview::placeholder( '{x}' ), Minn_Admin_Preview::placeholder( '{x}' ) );
	remove_filter( 'gettext', $pp_tr, 10 );
}

// --- WPForms -------------------------------------------------------------------
$wpf_table = $wpdb->prefix . 'wpforms_entries';
if ( ! function_exists( 'wpforms' ) || ! function_exists( 'minn_admin_wpforms_active' ) || ! minn_admin_wpforms_active() || ! class_exists( '\WPForms\Emails\Notifications' ) || $wpf_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpf_table ) ) ) {
	$skip( 'WPForms with entries inactive' );
} else {
	$in_transaction( 'WPForms', function () use ( $wpdb, $track, $wpf_table, $admin, $call, $check, $flat, $ph, $cut, $secrets, $make_user, $s_meta, $s_acct, $s_addon, $s_carry ) {
		$sub = $make_user( 'subscriber', array( 'display_name' => 'Pp ' . $s_acct, 'user_email' => $s_acct . '@example.com' ) );
		update_user_meta( $sub, 'minn_pp_secret', $s_meta );
		add_role( 'minn_pp_wpf', 'PP WPForms editor', array( 'read' => true, 'wpforms_view_own_forms' => true, 'wpforms_view_others_forms' => true, 'wpforms_edit_own_forms' => true, 'wpforms_edit_others_forms' => true, 'wpforms_view_entries_own_forms' => true, 'wpforms_view_entries_others_forms' => true ) );
		$ed   = $make_user( 'minn_pp_wpf' );
		$data = array(
			'fields'   => array(
				1 => array( 'id' => '1', 'type' => 'text', 'label' => 'Nick' ),
				2 => array( 'id' => '2', 'type' => 'text', 'label' => 'Note' ),
			),
			'settings' => array(
				'form_title'          => 'Minn PP WPForms',
				'notification_enable' => '1',
				'notifications'       => array( 1 => array( 'notification_name' => 'Default', 'email' => '{admin_email}', 'subject' => 'S', 'message' => '{all_fields}' ) ),
			),
		);
		$form = wp_insert_post( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'Minn PP WPForms', 'post_author' => $admin, 'post_content' => wpforms_encode( $data ) ) );
		$form = $track( 'post', is_int( $form ) ? $form : 0 );
		if ( ! $sub || ! $ed || ! $form ) {
			$check( 'WPForms fixtures', false, 'could not create the accounts or the form' );
			return;
		}
		$data['id'] = $form;
		wp_update_post( array( 'ID' => $form, 'post_content' => wpforms_encode( $data ) ) );
		$fields = array(
			1 => array( 'name' => 'Nick', 'value' => 'PpNickValue', 'id' => 1, 'type' => 'text' ),
			2 => array( 'name' => 'Note', 'value' => 'note {minn_pp_carried} end', 'id' => 2, 'type' => 'text' ),
		);
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $wpf_table, array( 'form_id' => $form, 'post_id' => 0, 'user_id' => $sub, 'status' => '', 'type' => '', 'fields' => wp_json_encode( $fields ), 'meta' => '', 'date' => $now, 'date_modified' => $now, 'ip_address' => '', 'user_agent' => '', 'user_uuid' => '' ) );
		$eid = $track( 'row', array( $wpf_table, 'entry_id', (int) $wpdb->insert_id ) )[2];

		// A fake add-on: its tag is registered and answered on WPForms' own
		// hooks, and its whole-text hook answers a tag a field value carries.
		$reg   = function ( $tags ) {
			return array_merge( (array) $tags, array( 'minn_pp_addon' => 'PP add-on' ) );
		};
		$value = function ( $v, $tag ) use ( $s_addon ) {
			return 'minn_pp_addon' === $tag ? $s_addon : $v;
		};
		$whole = function ( $content ) use ( $s_carry ) {
			return str_replace( '{minn_pp_carried}', $s_carry, (string) $content );
		};
		add_filter( 'wpforms_smart_tags', $reg );
		add_filter( 'wpforms_smarttags_process_value', $value, 10, 2 );
		add_filter( 'wpforms_smart_tag_process', $whole );
		$msg = 'A {field_id="1"} B {user_meta key="minn_pp_secret"} B2 {user_email} C {minn_pp_addon} D {minn_pp_nobody} E {field_id="2"}';
		try {
			wp_set_current_user( $admin );
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$form}/emails/preview", array( 'key' => '1', 'subject' => 'Subj {user_meta key="minn_pp_secret"} {field_id="1"}', 'message' => $msg ) );
			$out  = $flat( $d );
			$leak = $secrets( $out );
			$ok   = 200 === $st;
			$check( 'WPForms administrator: the preview is made from the entry', $ok && $eid === (int) ( $d['entry'] ?? 0 ), $st . ' entry ' . (int) ( $d['entry'] ?? 0 ) );
			$check( 'WPForms administrator: (a) a field tag fills from the entry', $ok && false !== strpos( $out, 'PpNickValue' ), $cut( $out ) );
			// The submitter's address is core's to show to someone who may
			// list users, as this person may; the account's meta never.
			$check( 'WPForms administrator: (b) {user_meta} of the submitter is a placeholder', $ok && $ph( $out, 'user_meta' ) && ! in_array( 'submitter meta', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
			$check( 'WPForms administrator: (b) control: {user_email} resolves for someone who may list users', $ok && in_array( 'submitter account', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
			$check( 'WPForms administrator: (c) an add-on\'s tag is a placeholder', $ok && $ph( $out, 'minn_pp_addon' ) && ! in_array( 'add-on', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
			$check( 'WPForms administrator: (c) an add-on\'s tag carried in by a field value does not run', $ok && false !== strpos( $out, 'note ' ) && ! in_array( 'carried add-on', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
			$check( 'WPForms administrator: (d) an unregistered tag is a placeholder', $ok && $ph( $out, 'minn_pp_nobody' ), $cut( $out ) );
			// WPForms maps its form capabilities only in wp-admin and its own
			// form preview (Access\Capabilities::init_allowed()), so over REST
			// a role that only edits forms is refused: there is no such role
			// to preview as.
			wp_set_current_user( $ed );
			list( $st ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$form}/emails/preview", array( 'key' => '1', 'subject' => 'S', 'message' => $msg ) );
			$check( 'WPForms form editor (WPForms grants its caps only in wp-admin): the route refuses the role', 403 === $st && ! current_user_can( 'list_users' ), (string) $st );
			// Control: their own render, as a real send runs it, is unchanged.
			wp_set_current_user( $admin );
			$real = wpforms_process_smart_tags( $msg, wpforms()->obj( 'form' )->get( $form, array( 'content_only' => true ) ), $fields, $eid, 'notification' );
			$check( 'WPForms control: their own render still resolves every tag', false !== strpos( $real, $s_meta ) && false !== strpos( $real, $s_addon ) && false !== strpos( $real, $s_carry ) && false !== strpos( $real, 'PpNickValue' ) && false === strpos( $real, '[tag: ' ), $cut( $real ) );
		} finally {
			remove_filter( 'wpforms_smart_tags', $reg );
			remove_filter( 'wpforms_smarttags_process_value', $value, 10 );
			remove_filter( 'wpforms_smart_tag_process', $whole );
		}
	} );
}

// --- Fluent Forms --------------------------------------------------------------
if ( ! class_exists( '\FluentForm\App\Services\FormBuilder\ShortCodeParser' ) || ! function_exists( 'minn_admin_fluent_form' ) ) {
	$skip( 'Fluent Forms inactive' );
} else {
	$in_transaction( 'Fluent Forms', function () use ( $wpdb, $track, $admin, $call, $check, $flat, $ph, $cut, $secrets, $make_user, $s_meta, $s_acct, $s_addon ) {
		$sub = $make_user( 'subscriber', array( 'display_name' => 'Pp ' . $s_acct, 'user_email' => $s_acct . '@example.com' ) );
		add_role( 'minn_pp_ff', 'PP Fluent manager', array( 'read' => true, 'fluentform_forms_manager' => true, 'fluentform_entries_viewer' => true ) );
		$ed = $make_user( 'minn_pp_ff' );
		// {user.<meta>} reads the signed-in account's meta: the submitter's
		// in a real send, the previewer's here. Both carry the secret.
		foreach ( array( $sub, $ed, $admin ) as $u ) {
			update_user_meta( $u, 'minn_pp_secret', $s_meta );
		}
		$track( 'meta', array( $admin, 'minn_pp_secret' ) );
		$ff_fields = array( 'fields' => array( array( 'element' => 'input_text', 'attributes' => array( 'name' => 'nick' ), 'settings' => array( 'label' => 'Nick' ) ) ) );
		$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'title' => 'Minn PP Fluent', 'status' => 'published', 'form_fields' => wp_json_encode( $ff_fields ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$form = $track( 'row', array( $wpdb->prefix . 'fluentform_forms', 'id', (int) $wpdb->insert_id ) )[2];
		$wpdb->insert( $wpdb->prefix . 'fluentform_submissions', array( 'form_id' => $form, 'user_id' => $sub, 'response' => wp_json_encode( array( 'nick' => 'PpNickValue' ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$eid = $track( 'row', array( $wpdb->prefix . 'fluentform_submissions', 'id', (int) $wpdb->insert_id ) )[2];
		if ( ! $sub || ! $ed || ! $form || ! $eid ) {
			$check( 'Fluent Forms fixtures', false, 'could not create the accounts, the form or the entry' );
			return;
		}
		// A fake add-on, answering its code on Fluent's own callback hook.
		$hook  = 'fluentform/shortcode_parser_callback_minn_pp_addon';
		$addon = function () use ( $s_addon ) {
			return $s_addon;
		};
		add_filter( $hook, $addon );
		$msg = 'A {inputs.nick} B {user.minn_pp_secret} C {minn_pp_addon} D {minn_pp_nobody}';
		try {
			foreach ( array( 'administrator' => $admin, 'form manager who cannot list users' => $ed ) as $who => $uid ) {
				wp_set_current_user( $uid );
				if ( $uid === $ed && current_user_can( 'list_users' ) ) {
					$check( "Fluent {$who}: the role reads no users", false );
				}
				list( $st, $d ) = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$form}/emails/preview", array( 'subject' => 'Subj {user.minn_pp_secret} {inputs.nick}', 'message' => $msg ) );
				$out  = $flat( $d );
				$leak = $secrets( $out );
				$check( "Fluent {$who}: the preview is made from the entry", 200 === $st && $eid === (int) ( $d['entry'] ?? 0 ), $st . ' entry ' . (int) ( $d['entry'] ?? 0 ) );
				$check( "Fluent {$who}: (a) an input tag fills from the entry", 200 === $st && false !== strpos( $out, 'PpNickValue' ), $cut( $out ) );
				$check( "Fluent {$who}: (b) {user.<meta>} is a placeholder", 200 === $st && $ph( $out, 'user.minn_pp_secret' ) && ! in_array( 'submitter meta', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
				$check( "Fluent {$who}: (c) an add-on's code is a placeholder", 200 === $st && $ph( $out, 'minn_pp_addon' ) && ! in_array( 'add-on', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
				$check( "Fluent {$who}: (d) an unregistered code is a placeholder", 200 === $st && $ph( $out, 'minn_pp_nobody' ), $cut( $out ) );
			}
			// Control: their parser, as a real send runs it, is unchanged.
			wp_set_current_user( $sub );
			\FluentForm\App\Services\FormBuilder\ShortCodeParser::resetData();
			$row  = wpFluent()->table( 'fluentform_submissions' )->find( $eid );
			$real = (string) \FluentForm\App\Services\FormBuilder\ShortCodeParser::parse( $msg, $row, json_decode( $row->response, true ), minn_admin_fluent_form( $form ), false, true );
			\FluentForm\App\Services\FormBuilder\ShortCodeParser::resetData();
			$check( 'Fluent control: their own parser still resolves every code', false !== strpos( $real, $s_meta ) && false !== strpos( $real, $s_addon ) && false !== strpos( $real, 'PpNickValue' ) && false === strpos( $real, '[tag: ' ), $cut( $real ) );
		} finally {
			remove_filter( $hook, $addon );
		}
	} );
}

// --- Contact Form 7 + Flamingo -------------------------------------------------
if ( ! defined( 'WPCF7_VERSION' ) || ! class_exists( 'WPCF7_ContactForm' ) || ! class_exists( 'Flamingo_Inbound_Message' ) || ! function_exists( 'minn_admin_cf7_fill' ) ) {
	$skip( 'Contact Form 7 with Flamingo inactive' );
} else {
	$in_transaction( 'Contact Form 7', function () use ( $track, $admin, $call, $check, $flat, $ph, $cut, $secrets, $make_user, $s_meta, $s_acct, $s_addon ) {
		$ed = $make_user( 'editor' );
		$cf = WPCF7_ContactForm::get_template( array( 'title' => 'Minn PP CF7' ) );
		$cf->set_properties( array( 'form' => '[text your-name]' ) );
		$id = $track( 'post', (int) $cf->save() );
		$cf = $id ? wpcf7_contact_form( $id ) : null;
		if ( ! $ed || ! $cf || '' === (string) $cf->name() ) {
			$check( 'CF7 fixtures', false, 'could not create the account or the form' );
			return;
		}
		$term = wp_insert_term( $cf->title(), Flamingo_Inbound_Message::channel_taxonomy, array( 'slug' => $cf->name() ) );
		$track( 'term', array( is_array( $term ) ? (int) $term['term_id'] : 0, Flamingo_Inbound_Message::channel_taxonomy ) );
		// The message as CF7 hands it to Flamingo, plus a key an add-on
		// stores through wpcf7_flamingo_inbound_message_parameters.
		$msg = Flamingo_Inbound_Message::add( array(
			'channel' => $cf->name(),
			'subject' => 'S',
			'fields'  => array( 'your-name' => 'PpNickValue' ),
			'meta'    => array( 'user_email' => $s_acct . '@example.com', 'user_display_name' => 'Pp ' . $s_acct, 'remote_ip' => '203.0.113.9', 'site_title' => 'Site', 'minn_pp_addon' => $s_addon, 'minn_pp_meta' => $s_meta ),
		) );
		$mid = $track( 'post', $msg ? (int) $msg->id() : 0 );
		// A fake add-on, answering its special tag on CF7's own hook.
		$addon = function ( $out, $name ) use ( $s_addon ) {
			return '_minn_pp_addon' === $name ? $s_addon : $out;
		};
		add_filter( 'wpcf7_special_mail_tags', $addon, 10, 2 );
		$body = 'A [your-name] B [_user_email] C [_minn_pp_addon] D [minn_pp_nobody] E [_minn_pp_meta]';
		try {
			wp_set_current_user( $admin );
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/cf7/forms/{$id}/mail/preview", array( 'which' => 'mail', 'mail' => array( 'recipient' => '[_user_email]', 'sender' => '[_minn_pp_addon] <wordpress@example.com>', 'subject' => 'Subj [_user_email] [your-name]', 'body' => $body, 'use_html' => true ) ) );
			$out  = $flat( $d );
			$leak = $secrets( $out );
			$check( 'CF7 administrator: the preview is made from the Flamingo message', 200 === $st && $mid && $mid === (int) ( $d['message'] ?? 0 ), $st . ' message ' . (int) ( $d['message'] ?? 0 ) . ' of ' . $mid );
			$check( 'CF7 administrator: (a) a field tag fills from the message', false !== strpos( $out, 'PpNickValue' ), $cut( $out ) );
			$check( 'CF7 administrator: (b) [_user_email] of the submitter is a placeholder, in the body and the addresses', $ph( (string) ( $d['to'] ?? '' ), '_user_email' ) && $ph( (string) ( $d['html'] ?? '' ), '_user_email' ) && ! in_array( 'submitter account', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
			$check( 'CF7 administrator: (c) an add-on\'s tag is a placeholder, in the body and the sender', $ph( (string) ( $d['from'] ?? '' ), '_minn_pp_addon' ) && $ph( (string) ( $d['html'] ?? '' ), '_minn_pp_addon' ) && ! in_array( 'add-on', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
			$check( 'CF7 administrator: (d) an unregistered tag, and any other key Flamingo kept, is a placeholder', $ph( $out, 'minn_pp_nobody' ) && $ph( $out, '_minn_pp_meta' ) && ! in_array( 'submitter meta', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
			// Flamingo shows messages only to people who can edit users, so an
			// Editor (who edits CF7 forms) previews with no message at all.
			wp_set_current_user( $ed );
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/cf7/forms/{$id}/mail/preview", array( 'which' => 'mail', 'mail' => array( 'recipient' => '[_user_email]', 'subject' => 'S', 'body' => $body, 'use_html' => true ) ) );
			$out = $flat( $d );
			$check( 'CF7 editor (no message reader): no message, the tags it would not fill as placeholders', 200 === $st && 0 === (int) ( $d['message'] ?? -1 ) && $ph( $out, '_user_email' ) && $ph( $out, '_minn_pp_addon' ) && false !== strpos( $out, '[your-name]' ) && ! $secrets( $out ), $st . ' ' . $cut( $out ) );
			// Control: their own replacement, as a real send runs it, is unchanged.
			wp_set_current_user( $admin );
			$real = (string) wpcf7_mail_replace_tags( 'C [_minn_pp_addon] S [_site_url]' );
			$check( 'CF7 control: their own tag replacement still answers the add-on\'s tag', false !== strpos( $real, $s_addon ) && false === strpos( $real, '[tag: ' ), $cut( $real ) );
		} finally {
			remove_filter( 'wpcf7_special_mail_tags', $addon, 10 );
		}
	} );
}

// --- Gravity Forms -------------------------------------------------------------
if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_guarded' ) ) {
	$skip( 'Gravity Forms inactive' );
} else {
	$in_transaction( 'Gravity Forms', function () use ( $track, $admin, $call, $check, $flat, $ph, $cut, $secrets, $make_user, $s_meta, $s_acct, $s_addon ) {
		$sub = $make_user( 'subscriber', array( 'display_name' => 'Pp ' . $s_acct, 'user_email' => $s_acct . '@example.com' ) );
		update_user_meta( $sub, 'minn_pp_secret', $s_meta );
		add_role( 'minn_pp_gf', 'PP GF editor', array( 'read' => true, 'gravityforms_edit_forms' => true, 'gravityforms_view_entries' => true ) );
		$ed   = $make_user( 'minn_pp_gf' );
		$note = array( 'ppn' => array( 'id' => 'ppn', 'name' => 'PP', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'S', 'message' => 'M', 'isActive' => true ) );
		$form = GFAPI::add_form( array( 'title' => 'Minn PP GF ' . wp_generate_password( 6, false ), 'fields' => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Nick' ) ), 'notifications' => $note, 'confirmations' => array() ) );
		$form = $track( 'gf_form', is_int( $form ) && $form > 0 ? $form : 0 );
		$eid  = $form ? GFAPI::add_entry( array( 'form_id' => $form, 'created_by' => $sub, '1' => 'PpNickValue' ) ) : 0;
		$eid  = is_int( $eid ) ? $eid : 0;
		if ( ! $sub || ! $ed || ! $form || ! $eid ) {
			$check( 'GF fixtures', false, 'could not create the accounts, the form or the entry' );
			return;
		}
		// A fake add-on, answering its tag on Gravity Forms' own tag filter.
		$addon = function ( $text ) use ( $s_addon ) {
			return str_replace( '{minn_pp_addon}', $s_addon, (string) $text );
		};
		add_filter( 'gform_replace_merge_tags', $addon );
		$msg   = 'A {Nick:1} B {created_by:minn_pp_secret} B2 {created_by:user_email} C {minn_pp_addon} D {minn_pp_nobody}';
		$route = "/minn-admin/v1/gf/notifications/{$form}:ppn";
		$mail  = array();
		$trap  = function ( $pre, $atts ) use ( &$mail ) {
			$mail[] = $atts;
			return $pre;
		};
		add_filter( 'pre_wp_mail', $trap, 1, 2 );
		try {
			foreach ( array( 'administrator' => $admin, 'form editor who cannot list users' => $ed ) as $who => $uid ) {
				wp_set_current_user( $uid );
				if ( $uid === $ed && current_user_can( 'list_users' ) ) {
					$check( "GF {$who}: the role reads no users", false );
				}
				list( $st, $d ) = $call( 'POST', "{$route}/preview", array( 'subject' => 'Subj {created_by:user_email} {Nick:1}', 'message' => $msg ) );
				$out  = $flat( $d );
				$leak = $secrets( $out );
				$check( "GF {$who}: the preview is made from the entry", 200 === $st && $eid === (int) ( $d['entry'] ?? 0 ), $st . ' entry ' . (int) ( $d['entry'] ?? 0 ) );
				$check( "GF {$who}: (a) a field tag fills from the entry", 200 === $st && false !== strpos( $out, 'PpNickValue' ), $cut( $out ) );
				$check( "GF {$who}: (b) {created_by:<meta>} of the submitter is a placeholder", 200 === $st && $ph( $out, 'created_by:minn_pp_secret' ) && ! in_array( 'submitter meta', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
				// The entry screen shows the submitter's login only; their email
				// is core's to show to someone who may list users.
				if ( $uid === $ed ) {
					$check( "GF {$who}: (b) {created_by:user_email} is a placeholder", 200 === $st && $ph( $out, 'created_by:user_email' ) && ! in_array( 'submitter account', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
				} else {
					$check( "GF {$who}: (b) control: {created_by:user_email} resolves for someone who may list users", 200 === $st && in_array( 'submitter account', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
				}
				$check( "GF {$who}: (c) an add-on's tag is a placeholder", 200 === $st && $ph( $out, 'minn_pp_addon' ) && ! in_array( 'add-on', $leak, true ), implode( ',', $leak ) . ' ' . $cut( $out ) );
				$check( "GF {$who}: (d) an unregistered tag is a placeholder", 200 === $st && $ph( $out, 'minn_pp_nobody' ), $cut( $out ) );

				$mail = array();
				list( $st, $d ) = $call( 'POST', "{$route}/test", array( 'subject' => 'Subj {created_by:user_email}', 'message' => $msg, 'fromName' => '{created_by:user_email} {minn_pp_addon}' ) );
				$sent = $flat( $mail );
				$leak = $secrets( $sent );
				$me   = get_userdata( $uid )->user_email;
				$check( "GF {$who}: the test send goes to the caller alone", 200 === $st && 1 === count( $mail ) && false !== strpos( wp_json_encode( $mail[0]['to'] ?? '' ), $me ), $st . ' ' . count( $mail ) . ' ' . wp_json_encode( $mail[0]['to'] ?? '' ) );
				$check( "GF {$who}: (a) the test send fills a field tag", 200 === $st && false !== strpos( $sent, 'PpNickValue' ), $cut( $sent ) );
				$check( "GF {$who}: (b-d) the test send leaves the submitter's meta, an add-on's and an unregistered tag as placeholders, sender included", 200 === $st && $ph( $sent, 'created_by:minn_pp_secret' ) && $ph( $sent, 'minn_pp_addon' ) && $ph( $sent, 'minn_pp_nobody' ) && ! array_diff( $leak, array( 'submitter account' ) ), implode( ',', $leak ) . ' ' . $cut( $sent ) );
			}
			// Control: their own send, mail blocked, is unchanged.
			wp_set_current_user( $admin );
			$mail = array();
			$n    = $note['ppn'];
			$n['to']      = 'minn-pp-control@example.com';
			$n['message'] = $msg;
			GFCommon::send_notification( $n, GFAPI::get_form( $form ), GFAPI::get_entry( $eid ) );
			$sent = $flat( $mail );
			$check( 'GF control: their own send still resolves every tag', 1 === count( $mail ) && false !== strpos( $sent, $s_meta ) && false !== strpos( $sent, $s_acct ) && false !== strpos( $sent, $s_addon ) && false !== strpos( $sent, 'PpNickValue' ) && false === strpos( $sent, '[tag: ' ), count( $mail ) . ' ' . $cut( $sent ) );
		} finally {
			remove_filter( 'gform_replace_merge_tags', $addon );
			remove_filter( 'pre_wp_mail', $trap, 1 );
		}
	} );
}

wp_set_current_user( $admin );
remove_filter( 'pre_wp_mail', '__return_false' );
printf( "\npreview-policy: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
