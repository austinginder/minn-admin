<?php
/**
 * The email preview policy against the ways around it a review found: a CF7
 * tag tucked in an allowed tag's quoted value, Gravity Forms scanning the
 * form title and the answers again for {created_by:*}, add-on tags on
 * Gravity Forms' other merge-tag hooks and in the confirmation preview, an
 * add-on tag carried in a WPForms field value to a later message hook, a
 * Fluent Forms request key reached through an input whose name prefixes it,
 * and a shortcode typed into a Gravity Forms test send. Each check fails if
 * the secret it plants comes back.
 *
 * Every write runs inside a transaction that is rolled back; anything that
 * escapes it is swept and named.
 *
 * Run: wp eval-file tests/preview-policy-delta.test.php --user=admin --path=<absolute site path>
 *
 * @package minn-admin
 */
global $wpdb;
$results = array();
add_filter( 'pre_wp_mail', '__return_false' );
$call = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};
$flat = function ( $d ) {
	$o = '';
	array_walk_recursive( $d, function ( $v ) use ( &$o ) { $o .= ' ' . ( is_scalar( $v ) ? (string) $v : '' ); } );
	return $o;
};
$say = function ( $label, $leaked, $detail ) use ( &$results ) {
	$results[] = ! $leaked;
	printf( "%s  %s%s\n", $leaked ? 'FAIL' : 'PASS', $label, $leaked ? ' — ' . substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $detail ) ), 0, 300 ) : '' );
};
$admin = (int) get_current_user_id();
$S     = 'drsecret' . wp_generate_password( 10, false );
$made  = array();
$wpdb->query( 'START TRANSACTION' );
try {
	$sub = wp_insert_user( array( 'user_login' => 'minndr-sub-' . wp_generate_password( 5, false, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minndr' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.com', 'role' => 'subscriber' ) );
	$sub = is_int( $sub ) && $sub > 1 ? $sub : 0;
	$made[] = array( 'user', $sub );
	$wpdb->update( $wpdb->users, array( 'user_activation_key' => 'AK-' . $S ), array( 'ID' => $sub ) );
	clean_user_cache( $sub );

	// ---- CF7 ----------------------------------------------------------------
	if ( class_exists( 'WPCF7_ContactForm' ) && class_exists( 'Flamingo_Inbound_Message' ) ) {
		$cf = WPCF7_ContactForm::get_template( array( 'title' => 'Minn DR CF7' ) );
		$cf->set_properties( array( 'form' => '[text your-name]' ) );
		$id = (int) $cf->save();
		$made[] = array( 'post', $id );
		$cf = wpcf7_contact_form( $id );
		$term = wp_insert_term( $cf->title(), Flamingo_Inbound_Message::channel_taxonomy, array( 'slug' => $cf->name() ) );
		$made[] = array( 'term', array( is_array( $term ) ? (int) $term['term_id'] : 0, Flamingo_Inbound_Message::channel_taxonomy ) );
		$msg  = Flamingo_Inbound_Message::add( array(
			'channel' => $cf->name(),
			'subject' => 'S',
			'fields'  => array( 'your-name' => 'Nick' ),
			'meta'    => array( 'user_email' => 'UE-' . $S . '@example.com', 'remote_ip' => 'IP-' . $S, 'addon_token' => 'AT-' . $S ),
		) );
		$made[] = array( 'post', $msg ? (int) $msg->id() : 0 );
		$body = 'plain [_user_email] | nested [your-name "[_user_email]"] | single [_site_url \'[_remote_ip]\'] | addon [your-name "[_addon_token]"]';
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/cf7/forms/{$id}/mail/preview", array( 'which' => 'mail', 'mail' => array( 'recipient' => '[your-name "[_user_email]"]', 'sender' => 'x', 'subject' => 'S', 'body' => $body, 'use_html' => true ) ) );
		$out = $flat( $d );
		$say( "CF7 preview ({$st}): Flamingo meta read through a tag nested in an allowlisted tag's quoted value", false !== strpos( $out, $S ), $out );
	}

	// ---- Gravity Forms -------------------------------------------------------
	if ( class_exists( 'GFAPI' ) ) {
		add_role( 'minn_dr_gf', 'DR GF editor', array( 'read' => true, 'gravityforms_edit_forms' => true, 'gravityforms_view_entries' => true ) );
		$ed = wp_insert_user( array( 'user_login' => 'minndr-ed-' . wp_generate_password( 5, false, false ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minndred' . strtolower( wp_generate_password( 6, false, false ) ) . '@example.com', 'role' => 'minn_dr_gf' ) );
		$ed = is_int( $ed ) && $ed > 1 ? $ed : 0;
		$made[] = array( 'user', $ed );
		$note = array( 'drn' => array( 'id' => 'drn', 'name' => 'DR', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'S', 'message' => 'M', 'isActive' => true ) );
		$conf = array( 'drc' => array( 'id' => 'drc', 'name' => 'DRC', 'type' => 'message', 'message' => 'x', 'isDefault' => true ) );
		// The form editor sets the form title; the submitter typed field 1.
		$form = GFAPI::add_form( array( 'title' => 'DR {created_by:user_activation_key} ' . wp_generate_password( 4, false ), 'fields' => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Nick' ) ), 'notifications' => $note, 'confirmations' => $conf ) );
		$form = is_int( $form ) && $form > 0 ? $form : 0;
		$made[] = array( 'gf_form', $form );
		$eid = GFAPI::add_entry( array( 'form_id' => $form, 'created_by' => $sub, '1' => 'typed {created_by:user_activation_key}' ) );
		wp_set_current_user( $ed );
		printf( "info  GF editor can list_users: %s\n", current_user_can( 'list_users' ) ? 'yes' : 'no' );
		$route = "/minn-admin/v1/gf/notifications/{$form}:drn";
		list( $st, $d ) = $call( 'POST', "{$route}/preview", array( 'subject' => 'S', 'message' => 'T={form_title} | AF={all_fields}' ) );
		$say( "GF notification preview ({$st}): submitter's wp_users.user_activation_key via {form_title} / {all_fields} re-scan", false !== strpos( $flat( $d ), 'AK-' . $S ), $flat( $d ) );

		// Shortcodes in a test send.
		$ran = array();
		add_shortcode( 'minn_dr_sc', function () use ( &$ran, $S ) { $ran[] = get_current_user_id(); return 'SC-' . $S; } );
		add_shortcode( 'tag', function ( $atts ) use ( &$ran ) { $ran[] = 'tag:' . wp_json_encode( $atts ); return 'TAGSC'; } );
		$mail = array();
		$trap = function ( $pre, $atts ) use ( &$mail ) { $mail[] = $atts; return $pre; };
		add_filter( 'pre_wp_mail', $trap, 1, 2 );
		list( $st, $d ) = $call( 'POST', "{$route}/preview", array( 'subject' => 'S', 'message' => 'P [minn_dr_sc] {nobody a="b"}' ) );
		$say( "GF preview ({$st}): a typed shortcode runs", false !== strpos( $flat( $d ), 'SC-' . $S ), $flat( $d ) );
		$ran = array();
		list( $st, $d ) = $call( 'POST', "{$route}/test", array( 'subject' => 'S', 'message' => 'P [minn_dr_sc] {nobody a="b"}' ) );
		$sent = $flat( $mail );
		$say( "GF test send ({$st}): a typed shortcode runs as the previewer (ran as user " . implode( ',', $ran ) . ')', false !== strpos( $sent, 'SC-' . $S ), $sent );
		$say( "GF test send: a placeholder '[tag: …]' runs a [tag] shortcode with the typed attributes", false !== strpos( $sent, 'TAGSC' ), $sent );
		remove_filter( 'pre_wp_mail', $trap, 1 );
		remove_shortcode( 'minn_dr_sc' );
		remove_shortcode( 'tag' );

		// An add-on answering aux data (gform_merge_tag_data) and a pre-replace tag.
		$aux = function ( $data ) use ( $S ) { $data['minnaddon'] = array( 'secret' => 'AUX-' . $S ); return $data; };
		$pre = function ( $text ) use ( $S ) { return str_replace( '{minn_pre}', 'PRE-' . $S, $text ); };
		add_filter( 'gform_merge_tag_data', $aux );
		add_filter( 'gform_pre_replace_merge_tags', $pre );
		$msg = 'AUX {minnaddon:secret} PRE {minn_pre}';
		list( $st, $d ) = $call( 'POST', "{$route}/preview", array( 'subject' => 'S', 'message' => $msg ) );
		$say( "GF notification preview ({$st}): add-on tags on gform_merge_tag_data / gform_pre_replace_merge_tags", false !== strpos( $flat( $d ), $S ), $flat( $d ) );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$form}:drc/preview", array( 'type' => 'message', 'message' => $msg ) );
		$say( "GF confirmation preview ({$st}): the same add-on tags", false !== strpos( $flat( $d ), $S ), $flat( $d ) );
		remove_filter( 'gform_merge_tag_data', $aux );
		remove_filter( 'gform_pre_replace_merge_tags', $pre );
		wp_set_current_user( $admin );
	}

	// ---- WPForms -------------------------------------------------------------
	$wpf = $wpdb->prefix . 'wpforms_entries';
	if ( function_exists( 'wpforms' ) && class_exists( '\WPForms\Emails\Notifications' ) ) {
		$data = array(
			'fields'   => array( 1 => array( 'id' => '1', 'type' => 'text', 'label' => 'Nick' ) ),
			'settings' => array( 'form_title' => 'Minn DR WPForms', 'notification_enable' => '1', 'notifications' => array( 1 => array( 'notification_name' => 'D', 'email' => '{admin_email}', 'subject' => 'S', 'message' => '{all_fields}' ) ) ),
		);
		$form = wp_insert_post( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'post_title' => 'Minn DR WPForms', 'post_content' => wpforms_encode( $data ) ) );
		$made[] = array( 'post', (int) $form );
		$data['id'] = $form;
		wp_update_post( array( 'ID' => $form, 'post_content' => wpforms_encode( $data ) ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $wpf, array( 'form_id' => $form, 'post_id' => 0, 'user_id' => $sub, 'status' => '', 'type' => '', 'fields' => wp_json_encode( array( 1 => array( 'name' => 'Nick', 'value' => 'typed {minn_dr_late}', 'id' => 1, 'type' => 'text' ) ) ), 'meta' => '', 'date' => $now, 'date_modified' => $now, 'ip_address' => '', 'user_agent' => '', 'user_uuid' => '' ) );
		$made[] = array( 'row', array( $wpf, 'entry_id', (int) $wpdb->insert_id ) );
		// A stand-in add-on answering its tag on a whole-message hook own_callbacks() does not cover.
		$late = function ( $m ) use ( $S ) { return str_replace( '{minn_dr_late}', 'LATE-' . $S, (string) $m ); };
		add_filter( 'wpforms_emails_notifications_message', $late );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$form}/emails/preview", array( 'key' => '1', 'subject' => 'S', 'message' => '{all_fields}' ) );
		$say( "WPForms preview ({$st}): add-on tag carried by a field value, answered on wpforms_emails_notifications_message", false !== strpos( $flat( $d ), $S ), $flat( $d ) );
		remove_filter( 'wpforms_emails_notifications_message', $late );
	}

	// ---- Fluent Forms --------------------------------------------------------
	if ( class_exists( '\FluentForm\App\Services\FormBuilder\ShortCodeParser' ) ) {
		$ff = array( 'fields' => array(
			array( 'element' => 'input_text', 'attributes' => array( 'name' => 'nick' ), 'settings' => array( 'label' => 'Nick' ) ),
			array( 'element' => 'input_hidden', 'attributes' => array( 'name' => '_wp' ), 'settings' => array( 'label' => 'H' ) ),
		) );
		$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'title' => 'Minn DR Fluent', 'status' => 'published', 'form_fields' => wp_json_encode( $ff ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$fid = (int) $wpdb->insert_id;
		$made[] = array( 'row', array( $wpdb->prefix . 'fluentform_forms', 'id', $fid ) );
		$wpdb->insert( $wpdb->prefix . 'fluentform_submissions', array( 'form_id' => $fid, 'user_id' => $sub, 'response' => wp_json_encode( array( 'nick' => 'Nick', '_wp_http_referer' => '/REF-' . $S . '/', '__fluent_form_embded_post_id' => '1' ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$made[] = array( 'row', array( $wpdb->prefix . 'fluentform_submissions', 'id', (int) $wpdb->insert_id ) );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$fid}/emails/preview", array( 'subject' => 'S', 'message' => 'R {inputs._wp.value_http_referer} | direct {inputs._wp_http_referer}' ) );
		$say( "Fluent preview ({$st}): a request key read through an input whose name prefixes it ('.value' is cut anywhere)", false !== strpos( $flat( $d ), 'REF-' . $S ), $flat( $d ) );
	}
} catch ( Throwable $e ) {
	$results[] = false;
	echo 'FAIL  the suite ran without throwing — ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ line ' . $e->getLine() . "\n";
} finally {
	wp_set_current_user( $admin );
	$held = '1' === (string) $wpdb->get_var( 'SELECT @@in_transaction' );
	$wpdb->query( 'ROLLBACK' );
	wp_cache_flush();
	$results[] = $held;
	echo ( $held ? 'PASS' : 'FAIL' ) . "  the transaction held to the end\n";
	require_once ABSPATH . 'wp-admin/includes/user.php';
	$left = array();
	foreach ( $made as list( $k, $v ) ) {
		if ( 'user' === $k && $v > 1 && get_userdata( $v ) ) { $left[] = "user $v"; wp_delete_user( $v ); }
		if ( 'post' === $k && $v > 0 && get_post( $v ) ) { $left[] = "post $v"; wp_delete_post( $v, true ); }
		if ( 'row' === $k && $v[2] > 0 && $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$v[0]} WHERE {$v[1]} = %d", $v[2] ) ) ) { $left[] = "{$v[0]} {$v[2]}"; $wpdb->delete( $v[0], array( $v[1] => $v[2] ) ); }
		if ( 'term' === $k && $v[0] > 0 && term_exists( $v[0], $v[1] ) ) { $left[] = "term {$v[0]}"; wp_delete_term( $v[0], $v[1] ); }
		if ( 'gf_form' === $k && $v > 0 && GFAPI::form_id_exists( $v ) ) { $left[] = "gf form $v"; GFAPI::delete_form( $v ); }
	}
	$key   = $wpdb->get_blog_prefix() . 'user_roles';
	$roles = get_option( $key );
	if ( is_array( $roles ) && isset( $roles['minn_dr_gf'] ) ) { unset( $roles['minn_dr_gf'] ); update_option( $key, $roles ); $left[] = 'role minn_dr_gf'; }
	$results[] = ! $left;
	echo ( $left ? 'FAIL' : 'PASS' ) . '  nothing was left after the rollback' . ( $left ? ' — swept ' . implode( ', ', $left ) : '' ) . "\n";
}
$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\npreview-policy-delta: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
