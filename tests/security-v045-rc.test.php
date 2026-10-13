<?php
/**
 * Regressions for the v0.45.0 release-candidate audit.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what got stored or what
 * came back, with a control where a fix could over-block. Sections SKIP when
 * their plugin is inactive, and the multisite ones SKIP on a single site.
 *
 * Run: wp eval-file tests/security-v045-rc.test.php --user=admin --path=<site> [--url=<subsite>]
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
	printf( "\nsecurity-v045-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

// A throwaway account of one role; the id is checked before any later
// delete, since a WP_Error cast to int names user 1.
$make_user = function ( $role ) {
	$id = wp_insert_user(
		array(
			'user_login' => 'minn-rc45-' . $role . '-' . wp_generate_password( 6, false ),
			'user_pass'  => wp_generate_password( 24 ),
			'user_email' => 'minn-rc45-' . $role . '-' . wp_generate_password( 6, false ) . '@example.com',
			'role'       => $role,
		)
	);
	return is_wp_error( $id ) ? 0 : (int) $id;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
// The account the suite was started as (--user), for the sections that
// must run as it (a secondary network's super admin).
$cli_user = get_current_user_id();
wp_set_current_user( $admin );

// Whether a route's permission callback admits the current user, without
// running the route.
$perm_for = function ( $route, $method, $url_params = array() ) {
	$routes = rest_get_server()->get_routes();
	foreach ( $routes[ $route ] ?? array() as $handler ) {
		if ( ! empty( $handler['methods'][ $method ] ) ) {
			$req = new WP_REST_Request( $method, $route );
			$req->set_url_params( $url_params );
			return true === call_user_func( $handler['permission_callback'], $req );
		}
	}
	return null;
};

// --- 01-01 GenerateBlocks: island previews resolve no legacy dynamic data -----
// GenerateBlocks' first blocks read a post's title, excerpt, author email or
// any meta from their attributes, with no {{ }} tag for the stripper to find,
// and GenerateBlocks blanks them only on core's block renderer. A Contributor
// could read another author's draft and protected meta through the preview.
if ( ! class_exists( 'GenerateBlocks_Dynamic_Content' ) ) {
	$skip( '01-01 GenerateBlocks inactive' );
} else {
	$gb_pid = wp_insert_post( array( 'post_title' => 'MinnRc45SecretTitle', 'post_content' => 'MinnRc45SecretBody', 'post_status' => 'draft', 'post_author' => $admin ) );
	$gb_uid = $make_user( 'contributor' );
	if ( ! $gb_pid || is_wp_error( $gb_pid ) || ! $gb_uid ) {
		$check( '01-01 fixtures', false, 'could not create the draft or the contributor' );
	} else {
		update_post_meta( $gb_pid, '_minn_rc45_secret', 'MinnRc45SecretMeta' );
		$gb_headline = function ( $attrs ) {
			$u = 'rc45' . substr( md5( wp_json_encode( $attrs ) ), 0, 6 );
			return '<!-- wp:generateblocks/headline ' . wp_json_encode( array_merge( array( 'uniqueId' => $u, 'element' => 'p' ), $attrs ) ) . ' --><p class="gb-headline gb-headline-' . $u . ' gb-headline-text">Static words</p><!-- /wp:generateblocks/headline -->';
		};
		$gb_render = function ( $markup ) use ( $call ) {
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => array( $markup ) ) );
			return array( $st, (string) ( $d['rendered'][0] ?? '' ) );
		};
		$gb_cases = array(
			'post title' => array( 'useDynamicData' => true, 'dynamicContentType' => 'post-title', 'dynamicSource' => 'post-id', 'postId' => $gb_pid ),
			'protected meta' => array( 'useDynamicData' => true, 'dynamicContentType' => 'post-meta', 'metaFieldName' => '_minn_rc45_secret', 'dynamicSource' => 'post-id', 'postId' => $gb_pid ),
			'excerpt' => array( 'useDynamicData' => true, 'dynamicContentType' => 'post-excerpt', 'dynamicSource' => 'post-id', 'postId' => $gb_pid ),
		);
		wp_set_current_user( $gb_uid );
		foreach ( $gb_cases as $gb_label => $gb_attrs ) {
			list( $gb_st, $gb_html ) = $gb_render( $gb_headline( $gb_attrs ) );
			$check( "01-01 a Contributor's preview resolves no legacy {$gb_label} of another author's draft", 200 === $gb_st && false === strpos( $gb_html, 'MinnRc45Secret' ), $gb_st . ' ' . substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
		}
		list( $gb_st, $gb_html ) = $gb_render( '<!-- wp:generateblocks/text {"uniqueId":"rc45t","tagName":"p"} --><p class="gb-text">{{post_title id:' . $gb_pid . '}}</p><!-- /wp:generateblocks/text -->' );
		$check( '01-01 a Contributor\'s {{ }} tag still resolves nothing', 200 === $gb_st && false === strpos( $gb_html, 'MinnRc45Secret' ), substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
		list( $gb_st, $gb_html ) = $gb_render( $gb_headline( array() ) );
		$check( '01-01 control: a Contributor\'s plain headline still previews', 200 === $gb_st && false !== strpos( $gb_html, 'Static words' ), substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
		wp_set_current_user( $admin );
		list( $gb_st, $gb_html ) = $gb_render( $gb_headline( $gb_cases['post title'] ) );
		$check( '01-01 control: an administrator\'s legacy title still resolves', 200 === $gb_st && false !== strpos( $gb_html, 'MinnRc45SecretTitle' ), substr( wp_strip_all_tags( $gb_html ), 0, 80 ) );
	}
	wp_set_current_user( $admin );
	if ( $gb_pid && ! is_wp_error( $gb_pid ) ) {
		wp_delete_post( $gb_pid, true );
	}
	if ( $gb_uid ) {
		$drop_user( $gb_uid );
	}
}

// --- 01-02 Permalinks: a typed structure is stored as typed -------------------
// Where WordPress can't tell the server rewrites URLs, core puts /index.php in
// its presets and stores a typed structure verbatim on a single site. Minn
// prefixed every structure it saved there.
if ( is_multisite() ) {
	$skip( '01-02 single-site rule (a network adds its own /blog handling)' );
} else {
	global $wp_rewrite;
	$pl_prev  = (string) get_option( 'permalink_structure' );
	$pl_norew = function () {
		return false;
	};
	add_filter( 'got_url_rewrite', $pl_norew );
	list( , $pl_state ) = $call( 'GET', '/minn-admin/v1/permalinks' );
	$check( '01-02 the state names the /index.php preset prefix where rewriting is unknown', '/index.php' === ( $pl_state['index_prefix'] ?? null ), wp_json_encode( $pl_state['index_prefix'] ?? null ) );
	$pl_try = '/%postname%/' === $pl_prev ? '/%year%/%postname%/' : '/%postname%/';
	list( $pl_st, $pl_body ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => $pl_try ) );
	$check( '01-02 a typed structure is stored as typed', 200 === $pl_st && $pl_try === get_option( 'permalink_structure' ), $pl_st . ' ' . get_option( 'permalink_structure' ) );
	list( $pl_st, $pl_body ) = $call( 'POST', '/minn-admin/v1/permalinks', array( 'structure' => '/index.php' . $pl_try ) );
	$check( '01-02 control: the /index.php preset is stored with its prefix', 200 === $pl_st && '/index.php' . $pl_try === get_option( 'permalink_structure' ), get_option( 'permalink_structure' ) );
	remove_filter( 'got_url_rewrite', $pl_norew );
	add_filter( 'got_url_rewrite', '__return_true' );
	list( , $pl_state ) = $call( 'GET', '/minn-admin/v1/permalinks' );
	remove_filter( 'got_url_rewrite', '__return_true' );
	$check( '01-02 control: no preset prefix where the server rewrites', '' === ( $pl_state['index_prefix'] ?? null ), wp_json_encode( $pl_state['index_prefix'] ?? null ) );
	$wp_rewrite->set_permalink_structure( $pl_prev );
	flush_rewrite_rules();
	$check( '01-02 cleanup: the structure is back', $pl_prev === get_option( 'permalink_structure' ), get_option( 'permalink_structure' ) );
}

// --- 04-01, 04-02 Gravity Forms: previews resolve no tag that reads past the entry
// {created_by:<key>} reads the submitter's meta, {custom_field:<key>} the
// sending page's meta and {embed_post:<field>} any field of that page. The
// notification preview and test left only the first as a placeholder, and
// missed a key with a space; the confirmation preview resolved all three.
if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_neutral_tags' ) && ! function_exists( 'minn_admin_gfn_neutral_created_by' ) ) {
	$skip( '04-01/04-02 Gravity Forms inactive' );
} else {
	$gf_form = null;
	foreach ( GFAPI::get_forms() as $gf_f ) {
		if ( rgar( $gf_f, 'notifications' ) && rgar( $gf_f, 'confirmations' ) ) {
			$gf_form = $gf_f;
			break;
		}
	}
	if ( ! $gf_form ) {
		$skip( '04-01/04-02 no form with a notification and a confirmation' );
	} else {
		$gf_uid    = $make_user( 'subscriber' );
		$gf_secret = 'rc45seed' . wp_generate_password( 10, false );
		$gf_page   = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'MinnRc45GfPage', 'post_status' => 'draft', 'post_password' => 'rc45pagepw', 'post_content' => 'rc45pagebody' ) );
		update_user_meta( $gf_uid, 'minn_rc45_secret', $gf_secret );
		update_user_meta( $gf_uid, 'minn rc45 spaced', $gf_secret );
		update_post_meta( $gf_page, '_minn_rc45_gf', $gf_secret );
		wp_update_user( array( 'ID' => $gf_uid, 'display_name' => 'Rc45 Submitter' ) );
		$gf_entry = GFAPI::add_entry( array( 'form_id' => $gf_form['id'], 'created_by' => $gf_uid, 'source_id' => $gf_page, 'source_url' => get_permalink( $gf_page ), 'date_created' => gmdate( 'Y-m-d H:i:s', time() + 120 ) ) );
		$gf_tags   = 'A {created_by:minn_rc45_secret} B {created_by:minn rc45 spaced} C {custom_field:_minn_rc45_gf} D {embed_post:post_password} E {embed_post:post_content} by {created_by:display_name}';
		$gf_leaks  = function ( $out ) use ( $gf_secret ) {
			return false !== strpos( $out, $gf_secret ) || false !== strpos( $out, 'rc45pagepw' ) || false !== strpos( $out, 'rc45pagebody' );
		};
		try {
			$gf_nid = array_keys( $gf_form['notifications'] )[0];
			list( $gf_st, $gf_d ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$gf_form['id']}:{$gf_nid}/preview", array( 'subject' => 'S', 'message' => $gf_tags ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( '04-02 the notification preview resolves no page field, page meta or spaced user-meta key', 200 === $gf_st && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 200 ) );
			$check( '04-02 control: a profile field the entry screen shows still resolves', false !== strpos( $gf_out, 'Rc45 Submitter' ), substr( $gf_out, 0, 200 ) );
			$gf_cid = array_keys( $gf_form['confirmations'] )[0];
			list( $gf_st, $gf_d ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$gf_form['id']}:{$gf_cid}/preview", array( 'type' => 'message', 'message' => $gf_tags ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( '04-01 the confirmation preview resolves no submitter meta, page meta or page field', 200 === $gf_st && ! $gf_leaks( $gf_out ), $gf_st . ' ' . substr( $gf_out, 0, 200 ) );
			$check( '04-01 control: the confirmation preview still resolves a profile field', false !== strpos( $gf_out, 'Rc45 Submitter' ), substr( $gf_out, 0, 200 ) );
			list( $gf_st, $gf_d ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$gf_form['id']}:{$gf_cid}/preview", array( 'type' => 'redirect', 'url' => 'https://example.com/thanks', 'queryString' => 'a={created_by:minn_rc45_secret}&b={custom_field:_minn_rc45_gf}&c={embed_post:post_password}' ) );
			$gf_out = wp_json_encode( $gf_d );
			$check( '04-01 the redirect preview address carries no submitter meta or page field', 200 === $gf_st && ! $gf_leaks( urldecode( $gf_out ) ), $gf_st . ' ' . substr( $gf_out, 0, 200 ) );
		} finally {
			if ( $gf_entry && ! is_wp_error( $gf_entry ) ) {
				GFAPI::delete_entry( $gf_entry );
			}
			if ( $gf_page && ! is_wp_error( $gf_page ) ) {
				wp_delete_post( $gf_page, true );
			}
			if ( $gf_uid ) {
				$drop_user( $gf_uid );
			}
		}
	}
}

// --- 04-02 sweep, WPForms: the preview resolves no {user_meta} in any spelling --
// WPForms reads a smart tag's attributes in any order and a quoted key with
// spaces. The neutralizer only knew {user_meta key=…} written first, without
// spaces, so another attribute ahead of the key read the submitter's meta.
global $wpdb;
$wpf_table = $wpdb->prefix . 'wpforms_entries';
if ( ! function_exists( 'wpforms' ) || ! class_exists( '\WPForms\Emails\Notifications' ) || $wpf_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpf_table ) ) ) {
	$skip( '04-02 WPForms with entries inactive' );
} else {
	$wpf_form = get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
	if ( ! $wpf_form ) {
		$skip( '04-02 no WPForms form' );
	} else {
		$wpf_form   = (int) $wpf_form[0];
		$wpf_data   = (array) wpforms_decode( get_post_field( 'post_content', $wpf_form ) );
		$wpf_keys   = array_keys( (array) ( $wpf_data['settings']['notifications'] ?? array() ) );
		$wpf_uid    = $make_user( 'subscriber' );
		$wpf_secret = 'rc45wpf' . wp_generate_password( 10, false );
		update_user_meta( $wpf_uid, 'minn_rc45_secret', $wpf_secret );
		update_user_meta( $wpf_uid, 'minn rc45 spaced', $wpf_secret );
		wp_update_user( array( 'ID' => $wpf_uid, 'display_name' => 'Rc45 Wpf Submitter' ) );
		$wpf_now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( $wpf_table, array( 'form_id' => $wpf_form, 'post_id' => 0, 'user_id' => $wpf_uid, 'status' => '', 'type' => '', 'fields' => '{}', 'meta' => '', 'date' => $wpf_now, 'date_modified' => $wpf_now, 'ip_address' => '', 'user_agent' => '', 'user_uuid' => '' ) );
		$wpf_eid = (int) $wpdb->insert_id;
		try {
			list( $wpf_st, $wpf_d ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$wpf_form}/emails/preview", array( 'key' => (string) ( $wpf_keys[0] ?? '1' ), 'subject' => 'S', 'message' => 'A {user_meta x="1" key="minn_rc45_secret"} B {user_meta key="minn rc45 spaced"} C {user_meta key="minn_rc45_secret"} by {user_display}' ) );
			$wpf_out = wp_json_encode( $wpf_d );
			$check( '04-02 the WPForms preview resolves no {user_meta}, whatever its attribute order or spacing', 200 === $wpf_st && (int) ( $wpf_d['entry'] ?? 0 ) === $wpf_eid && false === strpos( $wpf_out, $wpf_secret ), $wpf_st . ' ' . substr( $wpf_out, 0, 220 ) );
			$check( '04-02 control: the WPForms preview still fills a profile tag', false !== strpos( $wpf_out, 'Rc45 Wpf Submitter' ), substr( $wpf_out, 0, 220 ) );
		} finally {
			if ( $wpf_eid ) {
				$wpdb->delete( $wpf_table, array( 'entry_id' => $wpf_eid ) );
			}
			if ( $wpf_uid ) {
				$drop_user( $wpf_uid );
			}
		}
	}
}

// --- 05-01, 05-02 Fluent Forms: the email preview masks the row and the page -----
// The preview masked the answers it handed Fluent's parser but passed the
// entry as an id, so Fluent reloaded the raw row for {submission.response},
// password included. And {embed_post.*} read the sending page's meta, its
// author's meta and a draft's body. Run on a throwaway form in a rolled-back
// transaction.
if ( ! class_exists( '\\FluentForm\\App\\Services\\FormBuilder\\ShortCodeParser' ) ) {
	$skip( '05-01/05-02 Fluent Forms inactive' );
} else {
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	try {
		$ff_page   = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'MinnRc45FfPage', 'post_status' => 'draft', 'post_author' => $admin, 'post_content' => 'rc45ffbody' ) );
		$ff_secret = 'rc45ff' . wp_generate_password( 10, false );
		update_post_meta( $ff_page, '_minn_rc45_ff', $ff_secret );
		update_user_meta( $admin, 'minn_rc45_ff_user', $ff_secret );
		$ff_fields = array( 'fields' => array(
			array( 'element' => 'input_text', 'attributes' => array( 'name' => 'nick' ), 'settings' => array( 'label' => 'Nick' ) ),
			array( 'element' => 'input_password', 'attributes' => array( 'name' => 'secret_pw' ), 'settings' => array( 'label' => 'Password' ) ),
		) );
		$wpdb->insert( $wpdb->prefix . 'fluentform_forms', array( 'title' => 'rc45 preview probe', 'status' => 'published', 'form_fields' => wp_json_encode( $ff_fields ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$ff_form = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'fluentform_submissions', array( 'form_id' => $ff_form, 'response' => wp_json_encode( array( 'nick' => 'Dana', 'secret_pw' => 'hunter2-rc45', '__fluent_form_embded_post_id' => $ff_page ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		list( $ff_st, $ff_d ) = $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$ff_form}/emails/preview", array( 'subject' => 'S', 'message' => 'A {submission.response} B {embed_post.meta._minn_rc45_ff} C {embed_post.post_content} D {embed_post.author.minn_rc45_ff_user} E {inputs.nick}' ) );
		$ff_out = wp_json_encode( $ff_d );
		$check( '05-01 the Fluent preview shows no stored password through {submission.response}', 200 === $ff_st && false === strpos( $ff_out, 'hunter2-rc45' ), $ff_st . ' ' . substr( $ff_out, 0, 220 ) );
		$check( '05-02 the Fluent preview resolves no field or meta of the sending page or its author', false === strpos( $ff_out, $ff_secret ) && false === strpos( $ff_out, 'rc45ffbody' ), substr( $ff_out, 0, 220 ) );
		$check( '05-01 control: an answer tag still fills', false !== strpos( $ff_out, 'Dana' ), substr( $ff_out, 0, 220 ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
}

// --- 09-02 Networks and sites are refused before anything is half built --------
// A secondary network's super admin may create a network through WP Multi
// Network, but the write fence keeps the new network's roles and
// administrator for the install's owners, so the network was made without
// them and the request answered 403. A create_sites holder who is not a super
// admin got the same for a new site.
if ( ! is_multisite() ) {
	$skip( '09-02 network only' );
} else {
	wp_set_current_user( $cli_user );
	$nw_owner = Minn_Admin::install_owner();
	if ( ! function_exists( 'switch_to_network' ) || ! function_exists( 'add_network' ) ) {
		$skip( '09-02 WP Multi Network inactive' );
	} elseif ( is_super_admin() && ! $nw_owner ) {
		$check( '09-02 a secondary network\'s super admin may not create a network', false === $perm_for( '/minn-admin/v1/wp-multi-network/networks', 'POST' ) );
		$check( '09-02 a secondary network\'s super admin may not move a site between networks', false === $perm_for( '/minn-admin/v1/wp-multi-network/sites/(?P<id>\d+)/move', 'POST', array( 'id' => get_current_blog_id() ) ) );
	} elseif ( $nw_owner ) {
		$check( '09-02 control: the install\'s owner may create a network', true === $perm_for( '/minn-admin/v1/wp-multi-network/networks', 'POST' ) );
		$check( '09-02 control: the install\'s owner may move a site', true === $perm_for( '/minn-admin/v1/wp-multi-network/sites/(?P<id>\d+)/move', 'POST', array( 'id' => get_current_blog_id() ) ) );
	} else {
		$skip( '09-02 WP Multi Network: run as a super admin' );
	}
	if ( is_super_admin() ) {
		$check( '09-02 control: a super admin may add a site', true === $perm_for( '/minn-admin/v1/network/sites', 'POST' ) );
	}
	$ns_uid = $make_user( 'subscriber' );
	if ( $ns_uid ) {
		$ns_grant = function ( $caps, $cap, $args ) use ( $ns_uid ) {
			if ( (int) ( $args[1] ?? 0 ) === $ns_uid ) {
				$caps['create_sites'] = true;
			}
			return $caps;
		};
		add_filter( 'user_has_cap', $ns_grant, 10, 3 );
		wp_set_current_user( $ns_uid );
		$check( '09-02 a create_sites holder who is not a super admin may not add a site', current_user_can( 'create_sites' ) && false === $perm_for( '/minn-admin/v1/network/sites', 'POST' ), current_user_can( 'create_sites' ) ? '' : 'precondition: the grant did not take' );
		remove_filter( 'user_has_cap', $ns_grant, 10 );
		wp_set_current_user( $admin );
		$drop_user( $ns_uid );
	}
	wp_set_current_user( $admin );
}

// --- 03-02 Akismet: the spam card names no part of the key --------------------
// The card printed the key's first four characters, which Akismet itself
// withholds when the key is pinned in code, to whoever reads /spam (every
// site administrator, on a network). An installed but inactive Akismet is
// loaded for this run only; nothing activates it.
if ( ! class_exists( 'Akismet' ) && file_exists( WP_PLUGIN_DIR . '/akismet/class.akismet.php' ) ) {
	require_once WP_PLUGIN_DIR . '/akismet/class.akismet.php';
}
if ( ! class_exists( 'Akismet' ) ) {
	$skip( '03-02 Akismet inactive' );
} elseif ( defined( 'WPCOM_API_KEY' ) ) {
	$skip( '03-02 the key is pinned in code here; the probe sets the option' );
} else {
	$ak_prev = get_option( 'wordpress_api_key', null );
	update_option( 'wordpress_api_key', 'qz7xminnrc45' );
	list( $ak_st, $ak_d ) = $call( 'GET', '/minn-admin/v1/spam' );
	$ak_out = wp_json_encode( $ak_d );
	if ( null === $ak_prev ) {
		delete_option( 'wordpress_api_key' );
	} else {
		update_option( 'wordpress_api_key', $ak_prev );
	}
	$check( '03-02 the spam card names no part of the Akismet key', 200 === $ak_st && false === strpos( $ak_out, 'qz7x' ), $ak_st . ' ' . substr( $ak_out, 0, 160 ) );
	$check( '03-02 control: the card still says a key is set', false !== strpos( $ak_out, '"configured":true' ), substr( $ak_out, 0, 160 ) );
}

// --- 03-03 CleanTalk: core's settings route keeps the card's tail rule ----------
// Core registers the connector's option as a REST setting and masks it to
// its last four characters. Minn's mask let that through for activate_plugins,
// where the card (8d32da5e) keeps the tail for a network's owner. No lab runs
// CleanTalk on a network, so off one this checks the mask asks the card's rule.
if ( ! function_exists( 'minn_admin_cleantalk_connector_rest_mask' ) || ! function_exists( 'minn_admin_cleantalk_key_tail_allowed' ) ) {
	$skip( '03-03 CleanTalk adapter not loaded' );
} else {
	$ct_rf  = new ReflectionFunction( 'minn_admin_cleantalk_connector_rest_mask' );
	$ct_src = implode( '', array_slice( file( $ct_rf->getFileName() ), $ct_rf->getStartLine() - 1, $ct_rf->getEndLine() - $ct_rf->getStartLine() + 1 ) );
	$check( '03-03 the wp/v2/settings mask asks the Connectors card\'s tail rule', false !== strpos( $ct_src, 'minn_admin_cleantalk_key_tail_allowed()' ) && false === strpos( $ct_src, "current_user_can( 'activate_plugins' )" ) );
	$ct_setting = function_exists( 'minn_admin_cleantalk_connector_setting' ) ? minn_admin_cleantalk_connector_setting() : '';
	if ( is_multisite() && '' !== $ct_setting && ! is_super_admin() && current_user_can( 'activate_plugins' ) ) {
		$check( '03-03 a site administrator reads the setting empty on a network', '' === minn_admin_cleantalk_connector_rest_mask( null, $ct_setting ) );
	}
}

// --- 03-05 Antispam Bee: a toggle saved from Minn reaches the plugin -----------
// Antispam Bee reads its options through its own object-cache entry and only
// its own writer refreshes it, so under a persistent cache Minn's toggle read
// as saved while the plugin kept the old value. The request's own cache
// stands in for a persistent one: it is primed before the save.
if ( ! class_exists( 'Antispam_Bee' ) || ! method_exists( 'Antispam_Bee', 'get_option' ) ) {
	$skip( '03-05 Antispam Bee inactive' );
} else {
	$asb_prev = get_option( 'antispam_bee', null );
	$asb_was  = (int) Antispam_Bee::get_option( 'flag_spam' );
	list( $asb_st ) = $call( 'POST', '/minn-admin/v1/spam', array( 'toggles' => array( 'antispam-bee' => array( 'flag_spam' => ! $asb_was ) ) ) );
	$asb_now = (int) Antispam_Bee::get_option( 'flag_spam' );
	if ( null === $asb_prev ) {
		delete_option( 'antispam_bee' );
	} else {
		update_option( 'antispam_bee', $asb_prev );
	}
	wp_cache_delete( 'antispam_bee' );
	$check( '03-05 Antispam Bee itself reads the toggle Minn saved', 200 === $asb_st && $asb_now === ( $asb_was ? 0 : 1 ), "status {$asb_st}, was {$asb_was}, plugin reads {$asb_now}" );
	$check( '03-05 cleanup: the option is back', $asb_prev === get_option( 'antispam_bee', null ) );
}

// --- 15-03 Restore, lock and unlock answer only for types with a screen --------
// Internal types (a privacy request) map to ordinary post caps, so an Editor
// passed delete_post and edit_post on them: duplicate refused them (08-2),
// restore, lock and unlock did not.
$sc_uid = $make_user( 'editor' );
$sc_req = wp_insert_post( array( 'post_type' => 'user_request', 'post_name' => 'export_personal_data', 'post_title' => 'rc45-requester@example.com', 'post_status' => 'request-pending', 'post_author' => $admin ) );
$sc_own = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Rc45 own post', 'post_status' => 'draft', 'post_author' => $sc_uid ) );
if ( ! $sc_uid || ! $sc_req || is_wp_error( $sc_req ) || ! $sc_own || is_wp_error( $sc_own ) ) {
	$check( '15-03 fixtures', false );
} else {
	wp_set_current_user( $sc_uid );
	list( $sc_st ) = $call( 'POST', "/minn-admin/v1/posts/{$sc_req}/lock", array() );
	$check( '15-03 an Editor may not lock a privacy request', 404 === $sc_st, (string) $sc_st );
	list( $sc_st ) = $call( 'POST', "/minn-admin/v1/posts/{$sc_req}/unlock", array() );
	$check( '15-03 an Editor may not unlock a privacy request', 404 === $sc_st, (string) $sc_st );
	wp_set_current_user( $admin );
	wp_trash_post( $sc_req );
	wp_set_current_user( $sc_uid );
	list( $sc_st ) = $call( 'POST', "/minn-admin/v1/posts/{$sc_req}/restore", array() );
	$check( '15-03 an Editor may not restore a trashed privacy request', 404 === $sc_st && 'trash' === get_post_status( $sc_req ), $sc_st . ' ' . get_post_status( $sc_req ) );
	list( $sc_st, $sc_d ) = $call( 'POST', "/minn-admin/v1/posts/{$sc_own}/lock", array() );
	$check( '15-03 control: an Editor still locks their own post', 200 === $sc_st && ! empty( $sc_d['acquired'] ), $sc_st . ' ' . wp_json_encode( $sc_d ) );
	wp_trash_post( $sc_own );
	list( $sc_st ) = $call( 'POST', "/minn-admin/v1/posts/{$sc_own}/restore", array() );
	$check( '15-03 control: an Editor still restores their own post', 200 === $sc_st && 'trash' !== get_post_status( $sc_own ), $sc_st . ' ' . get_post_status( $sc_own ) );
}
wp_set_current_user( $admin );
foreach ( array( $sc_req, $sc_own ) as $sc_p ) {
	if ( $sc_p && ! is_wp_error( $sc_p ) ) {
		wp_delete_post( $sc_p, true );
	}
}
if ( $sc_uid ) {
	$drop_user( $sc_uid );
}

// --- 10-02 Redirection: an anonymized IP setting survives a save ---------------
// Redirection keeps three IP levels (none, full, masked). Minn served a
// toggle, so a save that carried it wrote 1 over 2 and the logs started
// recording full visitor addresses. The save sends back what the read served.
if ( ! function_exists( 'red_get_options' ) || ! function_exists( 'red_set_options' ) ) {
	$skip( '10-02 Redirection inactive' );
} else {
	$rd_prev = get_option( 'redirection_options', null );
	red_set_options( array( 'ip_logging' => 2 ) );
	list( , $rd_get ) = $call( 'GET', '/minn-admin/v1/redirection/settings/general' );
	$rd_served = $rd_get['values']['ip_logging'] ?? null;
	list( $rd_st ) = $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'ip_logging' => $rd_served ) ) );
	$rd_now = (int) ( red_get_options()['ip_logging'] ?? -1 );
	$check( '10-02 saving the IP setting as served keeps Anonymize', 200 === $rd_st && 2 === $rd_now, 'served ' . wp_json_encode( $rd_served ) . ", status {$rd_st}, stored {$rd_now}" );
	list( $rd_st ) = $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'ip_logging' => '0' ) ) );
	$check( '10-02 control: turning IP logging off still stores 0', 200 === $rd_st && 0 === (int) ( red_get_options()['ip_logging'] ?? -1 ), (string) $rd_st );
	if ( null === $rd_prev ) {
		delete_option( 'redirection_options' );
	} else {
		update_option( 'redirection_options', $rd_prev );
	}
}

// --- 11-03 The Events Calendar: an untouched date keeps its seconds ------------
// The panel reads dates to the minute and refilled any it was not sent, so
// a cost-only save moved a 23:59:59 end to 23:59:00. TEC keeps stored dates
// when none are sent, and takes new times only as a pair.
if ( ! function_exists( 'minn_admin_tec_active' ) || ! minn_admin_tec_active() ) {
	$skip( '11-03 The Events Calendar inactive' );
} else {
	$tec_id = wp_insert_post( array( 'post_type' => 'tribe_events', 'post_title' => 'Rc45 seconds', 'post_status' => 'draft', 'post_author' => $admin ) );
	if ( ! $tec_id || is_wp_error( $tec_id ) ) {
		$check( '11-03 fixture', false );
	} else {
		$tec_set = function () use ( $tec_id ) {
			update_post_meta( $tec_id, '_EventStartDate', '2026-10-20 10:00:30' );
			update_post_meta( $tec_id, '_EventEndDate', '2026-10-20 23:59:59' );
			update_post_meta( $tec_id, '_EventStartDateUTC', '2026-10-20 14:00:30' );
			update_post_meta( $tec_id, '_EventEndDateUTC', '2026-10-21 03:59:59' );
			delete_post_meta( $tec_id, '_EventAllDay' );
		};
		$tec_save = function ( $vals ) use ( $call, $tec_id ) {
			list( $st ) = $call( 'POST', "/wp/v2/tribe_events/{$tec_id}", array( 'minn_tec' => $vals ) );
			return array( $st, get_post_meta( $tec_id, '_EventStartDate', true ), get_post_meta( $tec_id, '_EventEndDate', true ) );
		};
		$tec_set();
		list( $tec_st, $tec_s, $tec_e ) = $tec_save( array( 'cost' => '25' ) );
		$check( '11-03 a cost-only save leaves both dates to the second', 200 === $tec_st && '2026-10-20 10:00:30' === $tec_s && '2026-10-20 23:59:59' === $tec_e, "{$tec_st} {$tec_s} / {$tec_e}" );
		$tec_set();
		list( $tec_st, $tec_s, $tec_e ) = $tec_save( array( 'end' => '2026-10-20 22:00' ) );
		$check( '11-03 control: an end-only change saves, the start kept to the second', 200 === $tec_st && '2026-10-20 10:00:30' === $tec_s && '2026-10-20 22:00:00' === $tec_e, "{$tec_st} {$tec_s} / {$tec_e}" );
		$tec_set();
		list( $tec_st, $tec_s, $tec_e ) = $tec_save( array( 'start' => '2026-10-20 09:15', 'end' => '2026-10-20 23:59' ) );
		$check( '11-03 control: a start change saves; the end sent back as read keeps its seconds', 200 === $tec_st && '2026-10-20 09:15:00' === $tec_s && '2026-10-20 23:59:59' === $tec_e, "{$tec_st} {$tec_s} / {$tec_e}" );
		wp_delete_post( $tec_id, true );
	}
}

// --- 10-03 Aryo Activity Log: rows follow Aryo's own role scoping --------------
// Aryo appends a role clause to every query its screens run, so an account
// holding manage_options through a custom role reads no administrator's
// actions. Minn served every row to any manage_options holder.
global $wpdb;
$aal_table = $wpdb->prefix . 'aryo_activity_log';
if ( ! class_exists( 'AAL_Main' ) || ! class_exists( 'AAL_Log_Query' ) || $aal_table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $aal_table ) ) ) {
	$skip( '10-03 Aryo Activity Log inactive' );
} else {
	add_role( 'minn_rc45_manager', 'Rc45 manager', array( 'read' => true, 'manage_options' => true, 'edit_posts' => true ) );
	$aal_uid = $make_user( 'minn_rc45_manager' );
	$wpdb->insert( $aal_table, array( 'user_caps' => 'administrator', 'action' => 'activated', 'object_type' => 'Plugin', 'object_subtype' => '', 'object_name' => 'MinnRc45AdminAction', 'object_id' => 0, 'user_id' => $admin, 'hist_ip' => '203.0.113.45', 'hist_time' => (int) current_time( 'timestamp' ) ) );
	$aal_row = (int) $wpdb->insert_id;
	try {
		wp_set_current_user( $aal_uid );
		list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
		$aal_out = wp_json_encode( $aal_d );
		$check( '10-03 a custom role holding manage_options reads no administrator\'s action', 200 === $aal_st && false === strpos( $aal_out, 'MinnRc45AdminAction' ), $aal_st . ' ' . substr( $aal_out, 0, 160 ) );
		wp_set_current_user( $admin );
		list( $aal_st, $aal_d ) = $call( 'GET', '/minn-admin/v1/aryo/events', null, array( 'per_page' => 100 ) );
		$check( '10-03 control: an administrator still reads it', 200 === $aal_st && false !== strpos( wp_json_encode( $aal_d ), 'MinnRc45AdminAction' ), (string) $aal_st );
	} finally {
		wp_set_current_user( $admin );
		$wpdb->delete( $aal_table, array( 'histid' => $aal_row ) );
		if ( $aal_uid ) {
			$drop_user( $aal_uid );
		}
		remove_role( 'minn_rc45_manager' );
	}
}

// --- 02-04 CPT UI: its own capability filter keeps its definitions ------------
// CPT UI registers every screen at cptui_required_capabilities, which a site
// raises to keep post type definitions from a client's administrators. Minn's
// manager let that administrator rename or delete them anyway.
$cu_types = get_option( 'cptui_post_types', null );
$cu_slug  = is_array( $cu_types ) ? (string) array_key_first( $cu_types ) : '';
if ( ! function_exists( 'cptui_get_post_type_data' ) && ! defined( 'CPTUI_VERSION' ) ) {
	$skip( '02-04 Custom Post Type UI inactive' );
} elseif ( '' === $cu_slug || ! post_type_exists( $cu_slug ) ) {
	$skip( '02-04 no CPT UI post type registered' );
} else {
	$cu_lock = function () {
		return 'minn_rc45_agency';
	};
	$cu_row = function () use ( $call, $cu_slug ) {
		list( , $d ) = $call( 'GET', '/minn-admin/v1/post-types' );
		foreach ( (array) ( $d['items'] ?? $d['types'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ( $row['slug'] ?? $row['name'] ?? '' ) === $cu_slug ) {
				return $row;
			}
		}
		return null;
	};
	$cu_before = $cu_row();
	add_filter( 'cptui_required_capabilities', $cu_lock );
	try {
		$cu_locked = $cu_row();
		$check( '02-04 a CPT UI type the site locked reads as not editable', is_array( $cu_locked ) && empty( $cu_locked['editable'] ), wp_json_encode( $cu_locked ) );
		list( $cu_st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cu_slug}", array( 'label' => 'Rc45 renamed' ) );
		$check( '02-04 an administrator without CPT UI\'s access may not change it', 403 === $cu_st && get_option( 'cptui_post_types' ) === $cu_types, (string) $cu_st );
		list( $cu_st ) = $call( 'DELETE', "/minn-admin/v1/post-types/{$cu_slug}" );
		$check( '02-04 an administrator without CPT UI\'s access may not delete it', 403 === $cu_st && get_option( 'cptui_post_types' ) === $cu_types, (string) $cu_st );
	} finally {
		remove_filter( 'cptui_required_capabilities', $cu_lock );
		if ( get_option( 'cptui_post_types', null ) !== $cu_types ) {
			update_option( 'cptui_post_types', $cu_types );
			flush_rewrite_rules();
		}
	}
	$check( '02-04 control: unlocked, the administrator may still edit it', is_array( $cu_before ) && ! empty( $cu_before['editable'] ), wp_json_encode( $cu_before ) );
}

// --- 06-02 ACPT: the builder's choices round-trip -----------------------------
// The builder resends every choice field on any group save. Its parser
// trimmed before splitting, so an empty-value option (" : None") came back as
// a new, selectable ": None" and the stored one (and its default) was lost:
// the defect fabda202 fixed for ACF.
if ( ! function_exists( 'minn_admin_acpt_builder_choices_in' ) || ! function_exists( 'minn_admin_acpt_builder_choices_out' ) ) {
	$skip( '06-02 ACPT adapter not loaded' );
} else {
	$ap_stored = array(
		array( 'id' => 'opt-none', 'value' => '', 'label' => 'None', 'isDefault' => true ),
		array( 'id' => 'opt-wide', 'value' => '16:9', 'label' => 'Wide', 'isDefault' => false ),
		array( 'id' => 'opt-a', 'value' => 'a', 'label' => 'A', 'isDefault' => false ),
	);
	$ap_back = minn_admin_acpt_builder_choices_in( minn_admin_acpt_builder_choices_out( $ap_stored ), $ap_stored );
	$ap_pick = function ( $rows ) {
		return array_map( function ( $r ) {
			return array( $r['id'] ?? null, $r['value'], $r['label'], ! empty( $r['isDefault'] ) );
		}, $rows );
	};
	$check( '06-02 an ACPT group\'s choices come back as stored, an empty value and its default included', $ap_pick( $ap_back ) === $ap_pick( $ap_stored ), wp_json_encode( $ap_pick( $ap_back ) ) );
}

// --- 07-01 Membership plans: a save keeps rules changed or removed elsewhere ---
// The plan page sent every rule as it loaded them whenever any rule
// changed, so a rule deleted elsewhere came back under a new id and an edit
// made elsewhere reverted. Untouched rules now go as their id alone, and an
// edited rule that was removed meanwhile is refused (409). Rolled back.
if ( ! function_exists( 'wc_memberships_get_membership_plans' ) ) {
	$skip( '07-01 WooCommerce Memberships inactive' );
} else {
	$mp_plans = wc_memberships_get_membership_plans();
	if ( ! $mp_plans ) {
		$skip( '07-01 no membership plan' );
	} else {
		global $wpdb;
		$mp_id = (int) reset( $mp_plans )->get_id();
		$wpdb->query( 'START TRANSACTION' );
		try {
			list( , $mp_d ) = $call( 'GET', "/minn-admin/v1/wcm/plans/{$mp_id}" );
			$mp_t = (array) ( $mp_d['vocab']['targets']['content_restriction'] ?? array() );
			if ( count( $mp_t ) < 2 ) {
				$skip( '07-01 fewer than two content targets' );
			} else {
				$mp_row  = function ( $t, $rid = '', $delay = 0 ) {
					$r = array( 'target' => $t, 'object_ids' => array(), 'access_schedule' => $delay ? array( 'type' => 'delayed', 'amount' => $delay, 'period' => 'weeks' ) : array( 'type' => 'immediate' ) );
					if ( $rid ) {
						$r['id'] = $rid;
					}
					return $r;
				};
				$mp_read = function () use ( $call, $mp_id ) {
					wp_cache_flush();
					list( , $d ) = $call( 'GET', "/minn-admin/v1/wcm/plans/{$mp_id}" );
					$out = array();
					foreach ( (array) ( $d['rules']['content_restriction'] ?? array() ) as $r ) {
						$out[ (string) $r['id'] ] = $r;
					}
					return $out;
				};
				$mp_save = function ( $rules, $known ) use ( $call, $mp_id ) {
					list( $st ) = $call( 'POST', "/minn-admin/v1/wcm/plans/{$mp_id}", array( 'rules' => array( 'content_restriction' => $rules ), 'known_rules' => $known ) );
					return $st;
				};
				$mp_save( array( $mp_row( $mp_t[0][0] ), $mp_row( $mp_t[1][0] ) ), array() );
				$mp_known = array_keys( $mp_read() );
				list( $mp_r1, $mp_r2 ) = array_pad( $mp_known, 2, '' );
				// Elsewhere: R2 is removed, and R1 gets a two-week delay.
				$mp_save( array( $mp_row( $mp_t[0][0], $mp_r1, 2 ) ), $mp_known );
				// The page (opened before that) saves a new rule; R1 and R2 untouched.
				$mp_st  = $mp_save( array( array( 'id' => $mp_r1 ), array( 'id' => $mp_r2 ), $mp_row( $mp_t[1][0] ) ), $mp_known );
				$mp_now = $mp_read();
				$check( '07-01 a rule removed elsewhere is not brought back by a save', 200 === $mp_st && ! isset( $mp_now[ $mp_r2 ] ) && 2 === count( $mp_now ), $mp_st . ' ' . wp_json_encode( array_keys( $mp_now ) ) );
				$check( '07-01 an edit made elsewhere to a rule the page left alone stays', 'delayed' === ( $mp_now[ $mp_r1 ]['schedule']['type'] ?? $mp_now[ $mp_r1 ]['access_schedule']['type'] ?? '' ), wp_json_encode( $mp_now[ $mp_r1 ] ?? null ) );
				// The page edits R2 itself, which someone removed meanwhile.
				$mp_st = $mp_save( array( array( 'id' => $mp_r1 ), $mp_row( $mp_t[1][0], $mp_r2, 3 ) ), $mp_known );
				$check( '07-01 editing a rule removed elsewhere is refused, not re-created', 409 === $mp_st && 2 === count( $mp_read() ), $mp_st . ' ' . wp_json_encode( array_keys( $mp_read() ) ) );
				// The body the earlier client sent: every rule in full, R2 as it loaded.
				$mp_st = $mp_save( array( $mp_row( $mp_t[0][0], $mp_r1, 2 ), $mp_row( $mp_t[1][0], $mp_r2 ) ), $mp_known );
				$check( '07-01 a whole-rules body does not re-create a rule removed elsewhere', 409 === $mp_st && 2 === count( $mp_read() ), $mp_st . ' ' . wp_json_encode( array_keys( $mp_read() ) ) );
			}
		} finally {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		}
	}
}

// --- 11-02 Autoptimize network-wide: no lost save, the gate on the main site -----
// With Autoptimize network-active and per-site configuration off, its reader
// returns the network's values and only Network Admin writes them. Minn's
// save landed in a per-site option it never reads and answered "saved", and
// the gate read manage_network_options from the addressed subsite's roles.
if ( ! function_exists( 'minn_admin_autoptimize_active' ) || ! minn_admin_autoptimize_active() ) {
	$skip( '11-02 Autoptimize inactive' );
} elseif ( ! class_exists( 'autoptimizeOptionWrapper', false ) || ! method_exists( 'autoptimizeOptionWrapper', 'is_ao_active_for_network' ) || ! autoptimizeOptionWrapper::is_ao_active_for_network() || 'on' === autoptimizeOptionWrapper::get_option( 'autoptimize_enable_site_config' ) ) {
	list( $ao_st ) = $call( 'POST', '/minn-admin/v1/autoptimize/settings/js', array( 'values' => new stdClass() ) );
	$check( '11-02 control: a per-site Autoptimize still saves', 200 === $ao_st, (string) $ao_st );
} else {
	wp_set_current_user( $cli_user );
	$ao_before = get_option( 'autoptimize_js', null );
	list( $ao_st ) = $call( 'POST', '/minn-admin/v1/autoptimize/settings/js', array( 'values' => array( 'autoptimize_js' => 'on' === autoptimizeOptionWrapper::get_option( 'autoptimize_js' ) ? false : true ) ) );
	$check( '11-02 a save of the network\'s Autoptimize settings is refused, not lost', 409 === $ao_st && get_option( 'autoptimize_js', null ) === $ao_before, $ao_st . ' per-site ' . wp_json_encode( get_option( 'autoptimize_js', null ) ) );
	if ( get_option( 'autoptimize_js', null ) !== $ao_before ) {
		null === $ao_before ? delete_option( 'autoptimize_js' ) : update_option( 'autoptimize_js', $ao_before );
	}
	$ao_uid = $make_user( 'administrator' );
	if ( $ao_uid ) {
		$ao_grant = function ( $caps, $cap, $args ) use ( $ao_uid ) {
			if ( (int) ( $args[1] ?? 0 ) === $ao_uid && ! is_main_site() ) {
				$caps['manage_network_options'] = true;
			}
			return $caps;
		};
		add_filter( 'user_has_cap', $ao_grant, 10, 3 );
		wp_set_current_user( $ao_uid );
		$check( '11-02 a subsite-granted network capability does not open the network\'s Autoptimize settings', is_main_site() || ( current_user_can( 'manage_network_options' ) && false === $perm_for( '/minn-admin/v1/autoptimize/settings/(?P<tab>[a-z]+)', 'POST', array( 'tab' => 'js' ) ) ), is_main_site() ? 'main site: run from a subsite' : '' );
		remove_filter( 'user_has_cap', $ao_grant, 10 );
		wp_set_current_user( $admin );
		$drop_user( $ao_uid );
	}
	wp_set_current_user( $admin );
}

// --- 06-01 ACF: a repeater row's untouched sub-fields stay as stored ----------
// A repeater comes back whole whenever any sub in any row changes. Its subs
// skipped only untouched dates, so a value the read can't show (a Link
// sub-field still holding a plain URL, as after a type change) was served
// empty and written back empty for every row.
if ( ! function_exists( 'acf_add_local_field_group' ) || ! defined( 'ACF_PRO' ) ) {
	$skip( '06-01 ACF PRO inactive' );
} else {
	acf_add_local_field_group( array(
		'key'      => 'group_minn_rc45',
		'title'    => 'Rc45 links',
		'fields'   => array(
			array(
				'key'        => 'field_minn_rc45_links',
				'name'       => 'minn_rc45_links',
				'label'      => 'Rc45 links',
				'type'       => 'repeater',
				'sub_fields' => array(
					array( 'key' => 'field_minn_rc45_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
					array( 'key' => 'field_minn_rc45_cta', 'name' => 'cta', 'label' => 'CTA', 'type' => 'link' ),
				),
			),
		),
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
	) );
	$ar_pid = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Rc45 repeater', 'post_status' => 'draft', 'post_author' => $admin ) );
	if ( ! $ar_pid || is_wp_error( $ar_pid ) ) {
		$check( '06-01 fixture', false );
	} else {
		foreach ( array( 'minn_rc45_links' => 2, '_minn_rc45_links' => 'field_minn_rc45_links', 'minn_rc45_links_0_title' => 'A', '_minn_rc45_links_0_title' => 'field_minn_rc45_title', 'minn_rc45_links_0_cta' => 'https://a.example/', '_minn_rc45_links_0_cta' => 'field_minn_rc45_cta', 'minn_rc45_links_1_title' => 'B', '_minn_rc45_links_1_title' => 'field_minn_rc45_title', 'minn_rc45_links_1_cta' => 'https://b.example/', '_minn_rc45_links_1_cta' => 'field_minn_rc45_cta' ) as $ar_k => $ar_v ) {
			update_post_meta( $ar_pid, $ar_k, $ar_v );
		}
		list( , $ar_get ) = $call( 'GET', "/wp/v2/posts/{$ar_pid}", null, array( 'context' => 'edit' ) );
		$ar_get  = json_decode( wp_json_encode( $ar_get ), true );
		$ar_rows = $ar_get['minn_acf']['minn_rc45_links'] ?? null;
		if ( ! is_array( $ar_rows ) || count( $ar_rows ) < 2 ) {
			$check( '06-01 the panel serves the repeater', false, wp_json_encode( $ar_get['minn_acf'] ?? null ) );
		} else {
			$ar_rows[0]['values']['title'] = 'A2';
			list( $ar_st ) = $call( 'POST', "/wp/v2/posts/{$ar_pid}", array( 'minn_acf' => array( 'minn_rc45_links' => $ar_rows ) ) );
			// Kept as stored, or in the array shape ACF's own form saves it in.
			$ar_url = function ( $i ) use ( $ar_pid ) {
				$v = get_post_meta( $ar_pid, "minn_rc45_links_{$i}_cta", true );
				return is_array( $v ) ? (string) ( $v['url'] ?? '' ) : (string) $v;
			};
			$check( '06-01 editing one row\'s title leaves every row\'s link', 200 === $ar_st && 'https://a.example/' === $ar_url( 0 ) && 'https://b.example/' === $ar_url( 1 ), $ar_st . ' ' . wp_json_encode( array( get_post_meta( $ar_pid, 'minn_rc45_links_0_cta', true ), get_post_meta( $ar_pid, 'minn_rc45_links_1_cta', true ) ) ) );
			$check( '06-01 the panel shows an old-shape link\'s address', 'https://a.example/' === ( $ar_rows[0]['values']['cta']['url'] ?? '' ), wp_json_encode( $ar_rows[0]['values']['cta'] ?? null ) );
			$check( '06-01 control: the edited title saves', 'A2' === get_post_meta( $ar_pid, 'minn_rc45_links_0_title', true ), (string) get_post_meta( $ar_pid, 'minn_rc45_links_0_title', true ) );
		}
		wp_delete_post( $ar_pid, true );
	}
	if ( function_exists( 'acf_remove_local_field_group' ) ) {
		acf_remove_local_field_group( 'group_minn_rc45' );
	}
}

// --- 02-01, 02-02, 02-05 Database browser: copies, store tables, the cap -------
// The table list is memoized per request, so every fixture table is made
// before the first browser call in this run.
$db_cell   = function ( $table, $pk_col, $pk, $col ) use ( $call ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/row', null, array( 'table' => $table, 'pk' => wp_json_encode( array( $pk_col => (string) $pk ) ) ) );
	if ( 200 !== $st ) {
		return array( $st, null );
	}
	$i = array_search( $col, wp_list_pluck( $d['columns'], 'name' ), true );
	return array( $st, false === $i ? null : $d['cells'][ $i ] );
};
$db_shown  = function ( $cell, $needle ) {
	$text = is_array( $cell ) ? (string) ( $cell['v'] ?? '' ) : (string) $cell;
	return false !== strpos( $text, $needle );
};
global $wpdb;
$db_copy   = $wpdb->prefix . 'usermeta_minnrc45';
$db_bare   = $wpdb->prefix . 'usermeta_minnrc45b';
$db_seed   = 'MINNRC45TOTPSEEDABCDEFGHIJKLMNOP';
$db_hooks  = array();
$db_opt    = 'minn_rc45_secret_copy';
$wpdb->query( "CREATE TABLE `{$db_copy}` LIKE `{$wpdb->usermeta}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "CREATE TABLE `{$db_bare}` ( `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT, `meta_value` longtext, PRIMARY KEY (`umeta_id`) )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->insert( $db_copy, array( 'user_id' => $admin, 'meta_key' => '_two_factor_totp_key', 'meta_value' => $db_seed ) );
$db_seed_id = (int) $wpdb->insert_id;
$wpdb->insert( $db_copy, array( 'user_id' => $admin, 'meta_key' => 'first_name', 'meta_value' => 'Minnrc45 Plainname here' ) );
$db_plain_id = (int) $wpdb->insert_id;
$wpdb->insert( $db_bare, array( 'meta_value' => $db_seed ) );
$db_bare_id = (int) $wpdb->insert_id;

// 02-01: a copy of usermeta keeps the live table's key list.
list( $db_st, $db_c ) = $db_cell( $db_copy, 'umeta_id', $db_seed_id, 'meta_value' );
$check( '02-01 a usermeta copy\'s TOTP seed prints redacted', 200 === $db_st && is_array( $db_c ) && ! empty( $db_c['redacted'] ), $db_st . ' ' . wp_json_encode( $db_c ) );
list( , $db_d ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $db_copy, 'fcol' => 'meta_value', 'fq' => 'TOTPSEED' ) );
$check( '02-01 and a value search does not find it', is_array( $db_d ) && 0 === (int) ( $db_d['total'] ?? -1 ), wp_json_encode( $db_d['total'] ?? $db_d ) );
list( $db_st, $db_c ) = $db_cell( $db_bare, 'umeta_id', $db_bare_id, 'meta_value' );
$check( '02-01 a copy without its key column prints no value', 200 === $db_st && is_array( $db_c ) && ! empty( $db_c['redacted'] ), $db_st . ' ' . wp_json_encode( $db_c ) );
list( $db_st, $db_c ) = $db_cell( $db_copy, 'umeta_id', $db_plain_id, 'meta_value' );
$check( '02-01 control: an ordinary row in the copy prints', 200 === $db_st && $db_shown( $db_c, 'Minnrc45 Plainname' ), $db_st . ' ' . wp_json_encode( $db_c ) );

// 02-02: WooCommerce's tables follow the log viewer's rule.
if ( ! class_exists( 'WooCommerce' ) ) {
	$skip( '02-02 WooCommerce inactive' );
} else {
	$db_role_uid = $make_user( 'administrator' );
	$db_wc_table = $wpdb->prefix . 'woocommerce_order_items';
	if ( ! $db_role_uid ) {
		$check( '02-02 fixture user', false );
	} else {
		$db_u = new WP_User( $db_role_uid );
		$db_u->add_cap( 'manage_woocommerce', false );
		wp_set_current_user( $db_role_uid );
		list( $db_st ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $db_wc_table ) );
		$check( '02-02 a System-page role without manage_woocommerce cannot browse its order items', 404 === $db_st, (string) $db_st );
		list( , $db_list ) = $call( 'GET', '/minn-admin/v1/db/tables' );
		$check( '02-02 and the table list leaves WooCommerce\'s tables out', is_array( $db_list ) && ! in_array( $db_wc_table, wp_list_pluck( (array) ( $db_list['tables'] ?? array() ), 'name' ), true ) );
		list( $db_st ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->posts ) );
		$check( '02-02 control: that role still browses the posts table', 200 === $db_st, (string) $db_st );
		wp_set_current_user( $admin );
		$drop_user( $db_role_uid );
	}
	list( $db_st ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $db_wc_table ) );
	$check( '02-02 control: an administrator with manage_woocommerce browses it', 200 === $db_st, (string) $db_st );
}

// 02-05: once the copies list is capped, a long cell is withheld.
if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_webhooks' ) ) ) {
	$skip( '02-05 no wc_webhooks table to hold the credentials' );
} else {
	for ( $i = 0; $i < 400; $i++ ) {
		$wpdb->insert( $wpdb->prefix . 'wc_webhooks', array( 'status' => 'disabled', 'name' => 'minn-rc45-cap', 'user_id' => $admin, 'delivery_url' => 'https://example.com/', 'secret' => 'mrc45' . wp_generate_password( 40, false ), 'topic' => 'order.created', 'api_version' => 3 ) );
		$db_hooks[] = (int) $wpdb->insert_id;
	}
	$db_late = (string) $wpdb->get_var( $wpdb->prepare( "SELECT secret FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id = %d", end( $db_hooks ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	add_option( $db_opt, 'copied for a support ticket: ' . $db_late, '', 'no' );
	$db_oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $db_opt ) );
	list( $db_st, $db_c ) = $db_cell( $wpdb->options, 'option_id', $db_oid, 'option_value' );
	$check( '02-05 past the cap, a copy of a late credential does not print', 200 === $db_st && ! $db_shown( $db_c, $db_late ), $db_st . ' ' . wp_json_encode( $db_c ) );
	list( $db_st, $db_c ) = $db_cell( $wpdb->options, 'option_id', $db_oid, 'autoload' );
	$check( '02-05 control: a short cell still prints', 200 === $db_st && is_string( $db_c ) && '' !== $db_c, $db_st . ' ' . wp_json_encode( $db_c ) );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_webhooks WHERE name = 'minn-rc45-cap'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	delete_option( $db_opt );
}
$wpdb->query( "DROP TABLE IF EXISTS `{$db_copy}`, `{$db_bare}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// @sections

$summary();
