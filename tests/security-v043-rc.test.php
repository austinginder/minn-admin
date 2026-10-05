<?php
/**
 * Regressions for the v0.43.0 release-candidate audit.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what came back or what got
 * stored, with a control where a fix could over-block. Sections SKIP when their
 * plugin is inactive.
 *
 * Run: wp eval-file tests/security-v043-rc.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v043-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );
$author = get_user_by( 'login', 'minn-author' );
$editor = get_user_by( 'login', 'minn-editor' );

// --- 01-01 / 06-01 /render-blocks drops a post id the caller cannot edit ---
if ( $author ) {
	$foreign = wp_insert_post( array( 'post_title' => 'Minn v043 foreign private', 'post_status' => 'private', 'post_author' => $admin ) );
	wp_set_current_user( $author->ID );
	$own    = wp_insert_post( array( 'post_title' => 'Minn v043 own draft', 'post_status' => 'draft', 'post_author' => $author->ID ) );
	$seen   = null;
	$spy    = function ( $blocks, $pid ) use ( &$seen ) {
		$seen = $pid;
	};
	$markup = array( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
	add_action( 'minn_admin_before_render_blocks', $spy, 1, 2 );
	list( $status ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $markup, 'post' => $foreign ) );
	$check( 'render-blocks: an Author naming an admin\'s private post renders without its id', 0 === $seen, "status {$status}, hooks saw " . var_export( $seen, true ) );
	$seen = null;
	$call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $markup, 'post' => $own ) );
	$check( 'render-blocks: the Author\'s own draft still reaches the hooks (control)', $own === $seen, 'hooks saw ' . var_export( $seen, true ) );
	remove_action( 'minn_admin_before_render_blocks', $spy, 1 );
	wp_set_current_user( $admin );
	wp_delete_post( $foreign, true );
	wp_delete_post( $own, true );
} else {
	$skip( 'render-blocks: no minn-author account' );
}

// A throwaway account holding exactly the caps given, on top of Subscriber.
$temp_users = array();
$temp_user  = function ( $login, $caps ) use ( &$temp_users ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.com', 'role' => 'subscriber' ) );
	$u  = new WP_User( $id );
	foreach ( $caps as $cap ) {
		$u->add_cap( $cap );
	}
	$temp_users[] = $id;
	return $id;
};

// --- 04-01 / 04-03 Gravity Forms previews need the entry-view capability ---
if ( class_exists( 'GFAPI' ) && function_exists( 'minn_admin_gfn_latest_entry' ) ) {
	$fid  = GFAPI::add_form( array(
		'title'         => 'Minn v043 GF ' . time(),
		'fields'        => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ) ),
		'notifications' => array( 'mv043' => array( 'id' => 'mv043', 'name' => 'Admin', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'New', 'message' => '{all_fields}', 'isActive' => true ) ),
	) );
	GFAPI::add_entry( array( 'form_id' => $fid, '1' => 'MinnSecretGF' ) );
	$form    = GFAPI::get_form( $fid );
	$cid     = (string) key( (array) $form['confirmations'] );
	$private = wp_insert_post( array( 'post_title' => 'Minn v043 private page', 'post_name' => 'minn-v043-private-slug', 'post_type' => 'page', 'post_status' => 'private', 'post_author' => $admin ) );
	$gf_ed   = $temp_user( 'minn-v043-gf-editor', array( 'gravityforms_edit_forms' ) );
	$sent    = 0;
	$abort   = function ( $email ) use ( &$sent ) {
		++$sent;
		$email['abort_email'] = true;
		return $email;
	};
	add_filter( 'gform_pre_send_email', $abort );
	$preview = function () use ( $call, $fid ) {
		return $call( 'POST', "/minn-admin/v1/gf/notifications/{$fid}:mv043/preview", array( 'subject' => 'New', 'message' => '{all_fields}' ) );
	};
	$confirm = function ( $body ) use ( $call, $fid, $cid ) {
		return $call( 'POST', "/minn-admin/v1/gf/confirmations/{$fid}:{$cid}/preview", $body );
	};

	wp_set_current_user( $gf_ed );
	list( $st, $d ) = $preview();
	$check( 'GF notification preview: an edit-forms-only user gets no entry', 200 === $st && false === strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	list( $st, $d ) = $call( 'GET', "/minn-admin/v1/gf/notifications/{$fid}:mv043/full" );
	$check( 'GF notification page: no latest entry id for that user', 0 === (int) ( $d['form']['latestEntry'] ?? -1 ), "status {$st}" );
	list( $st, $d ) = $confirm( array( 'type' => 'message', 'message' => '{Name:1}' ) );
	$check( 'GF confirmation preview: no entry for that user', 200 === $st && false === strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	$sent = 0;
	list( $st ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$fid}:mv043/test", array( 'subject' => 'New', 'message' => '{all_fields}' ) );
	$check( 'GF test send: refused for that user, nothing sent', $st >= 400 && 0 === $sent, "status {$st}, sent {$sent}" );
	list( $st, $d ) = $confirm( array( 'type' => 'page', 'pageId' => $private ) );
	$check( 'GF confirmation preview: no address for a page the user cannot read', '' === (string) ( $d['url'] ?? '' ), 'url ' . ( $d['url'] ?? '' ) );

	wp_set_current_user( $admin );
	list( $st, $d ) = $preview();
	$check( 'GF notification preview: an administrator still sees the latest entry (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	list( $st, $d ) = $confirm( array( 'type' => 'message', 'message' => '{Name:1}' ) );
	$check( 'GF confirmation preview: an administrator still sees it (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	remove_filter( 'gform_pre_send_email', $abort );
	GFAPI::delete_form( $fid );
	wp_delete_post( $private, true );
} else {
	$skip( 'Gravity Forms previews: Gravity Forms inactive' );
}

// --- 04-01 WPForms emails preview needs the entry-view capability --------
if ( function_exists( 'wpforms' ) && function_exists( 'wpforms_current_user_can' ) && class_exists( '\WPForms\Pro\Access\Capabilities' ) ) {
	$wf_id = wpforms()->obj( 'form' )->add( 'Minn v043 WPForms ' . time(), array(), array( 'template' => 'simple-contact-form-template' ) );
	$wf_e  = wpforms()->obj( 'entry' )->add( array( 'form_id' => $wf_id, 'status' => '', 'fields' => wp_json_encode( array( 3 => array( 'name' => 'Comment or Message', 'value' => 'MinnSecretWPF', 'id' => 3, 'type' => 'textarea' ) ) ) ) );
	$wf_ed = $temp_user( 'minn-v043-wpf-editor', array( 'wpforms_view_others_forms', 'wpforms_edit_others_forms' ) );
	$wf_pv = function () use ( $call, $wf_id ) {
		return $call( 'POST', "/minn-admin/v1/wpforms/forms/{$wf_id}/emails/preview", array( 'key' => '1', 'subject' => 'New', 'message' => '{all_fields}' ) );
	};
	// WPForms applies its granular caps only in wp-admin; under REST every
	// check falls back to its manage cap. Grant edit (never entries) the way
	// Access Controls would, so the role split is the one the finding names.
	$wf_edit = function ( $can, $caps ) {
		return 'edit_form_single' === $caps ? true : $can;
	};
	add_filter( 'wpforms_current_user_can', $wf_edit, 10, 2 );
	wp_set_current_user( $wf_ed );
	list( $st, $d ) = $wf_pv();
	$check( 'WPForms preview: an edit-forms-only user gets no entry', 200 === $st && false === strpos( (string) wp_json_encode( $d ), 'MinnSecretWPF' ), "status {$st}" );
	list( $st, $d ) = $call( 'GET', "/minn-admin/v1/wpforms/forms/{$wf_id}/emails" );
	$check( 'WPForms page: no latest entry id for that user', 200 === $st && 0 === (int) ( $d['latest'] ?? -1 ), "status {$st}" );
	remove_filter( 'wpforms_current_user_can', $wf_edit, 10 );
	wp_set_current_user( $admin );
	list( $st, $d ) = $wf_pv();
	$check( 'WPForms preview: an administrator still sees the latest entry (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretWPF' ), "status {$st}" );
	wpforms()->obj( 'entry' )->delete( $wf_e );
	wp_delete_post( $wf_id, true );
} else {
	$skip( 'WPForms preview: WPForms Pro inactive' );
}

// --- 05-01 CF7 mail preview needs Flamingo's read capability -------------
if ( function_exists( 'wpcf7_save_contact_form' ) && class_exists( 'Flamingo_Inbound_Message' ) && $editor ) {
	$cf = wpcf7_save_contact_form( array( 'title' => 'Minn v043 CF7 ' . time() ) );
	$cf = wpcf7_contact_form( $cf->id() );
	$tx = Flamingo_Inbound_Message::channel_taxonomy;
	$pt = term_exists( 'contact-form-7', $tx );
	$pt = $pt ? $pt : wp_insert_term( 'Contact Form 7', $tx, array( 'slug' => 'contact-form-7' ) );
	wp_insert_term( $cf->title(), $tx, array( 'slug' => $cf->name(), 'parent' => (int) $pt['term_id'] ) );
	$msg   = Flamingo_Inbound_Message::add( array( 'channel' => $cf->name(), 'subject' => 'x', 'from' => 'x <x@example.com>', 'fields' => array( 'your-name' => 'MinnSecretCF7', 'your-message' => 'MinnSecretCF7' ) ) );
	$cf_pv = function () use ( $call, $cf ) {
		return $call( 'POST', '/minn-admin/v1/cf7/forms/' . $cf->id() . '/mail/preview', array( 'which' => 'mail', 'subject' => '[your-name]', 'body' => '[your-name] [your-message]' ) );
	};
	wp_set_current_user( $editor->ID );
	list( $st, $d ) = $cf_pv();
	$check( 'CF7 preview: an Editor (no Flamingo access) gets no submission', false === strpos( (string) wp_json_encode( $d ), 'MinnSecretCF7' ), "status {$st}" );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/cf7/forms/' . $cf->id() . '/mail' );
	$check( 'CF7 page: no latest message id for an Editor', 200 === $st && 0 === (int) ( $d['latest'] ?? -1 ), "status {$st}" );
	wp_set_current_user( $admin );
	list( $st, $d ) = $cf_pv();
	$check( 'CF7 preview: an administrator still sees the latest submission (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretCF7' ), "status {$st}" );
	if ( $msg ) {
		wp_delete_post( $msg->id(), true );
	}
	$t = get_term_by( 'slug', $cf->name(), $tx );
	if ( $t ) {
		wp_delete_term( $t->term_id, $tx );
	}
	wp_delete_post( $cf->id(), true );
} else {
	$skip( 'CF7 preview: CF7, Flamingo or minn-editor missing' );
}

// --- 05-03 Fluent Forms preview needs the entries-viewer permission ------
if ( function_exists( 'wpFluent' ) && class_exists( '\FluentForm\App\Modules\Acl\Acl' ) ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$src = $wpdb->get_var( "SELECT form_fields FROM {$p}fluentform_forms ORDER BY id ASC LIMIT 1" );
	$wpdb->insert( "{$p}fluentform_forms", array( 'title' => 'Minn v043 Fluent', 'status' => 'published', 'type' => 'form', 'form_fields' => (string) $src, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$ff_id = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $ff_id, 'meta_key' => 'notifications', 'value' => wp_json_encode( array( 'name' => 'Admin email', 'sendTo' => array( 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => array() ), 'subject' => 'New', 'message' => '<p>{all_data}</p>', 'enabled' => true ) ) ) );
	$wpdb->insert( "{$p}fluentform_submissions", array( 'form_id' => $ff_id, 'serial_number' => 1, 'response' => wp_json_encode( array( 'names' => 'MinnSecretFF', 'message' => 'MinnSecretFF' ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$ff_ed = $temp_user( 'minn-v043-ff-manager', array( 'fluentform_forms_manager' ) );
	update_user_meta( $ff_ed, '_fluent_forms_has_role', 1 );
	$ff_pv = function () use ( $call, $ff_id ) {
		return $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$ff_id}/emails/preview", array( 'subject' => 'New', 'message' => '{inputs.names} {all_data}' ) );
	};
	wp_set_current_user( $ff_ed );
	list( $st, $d ) = $ff_pv();
	$check( 'Fluent preview: a forms manager without View Entries gets no entry', false === strpos( (string) wp_json_encode( $d ), 'MinnSecretFF' ), "status {$st}" );
	wp_set_current_user( $admin );
	list( $st, $d ) = $ff_pv();
	$check( 'Fluent preview: an administrator still sees the latest entry (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretFF' ), "status {$st}" );
	$wpdb->delete( "{$p}fluentform_submissions", array( 'form_id' => $ff_id ) );
	$wpdb->delete( "{$p}fluentform_form_meta", array( 'form_id' => $ff_id ) );
	$wpdb->delete( "{$p}fluentform_forms", array( 'id' => $ff_id ) );
} else {
	$skip( 'Fluent preview: Fluent Forms inactive' );
}

if ( $temp_users ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $temp_users as $id ) {
		wp_delete_user( $id );
	}
}

$summary();
