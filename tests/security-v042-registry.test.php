<?php
/**
 * Regressions for the v0.42.0 shipped-zip registry audit (WP Registry #2189).
 *
 * Most of that round is one class: a Minn save that rewrites vendor state the
 * user never touched. Each section replays what Minn's client sends through the
 * route it actually calls and checks the stored result, with a control where a
 * fix could over-block. Sections SKIP when their plugin is inactive. Browser-
 * level halves live in stored-choices.test.js and tec-panel.test.js.
 *
 * Run: wp eval-file tests/security-v042-registry.test.php --user=admin --path=<site>
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
	printf( "\nsecurity-v042-registry: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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

// --- #1 Redirection in-place edit keeps conditions, flags and order -------
if ( class_exists( 'Red_Item' ) ) {
	global $wpdb;
	$surfaces = Minn_Admin_Surfaces::all();
	$edit     = $surfaces['redirection']['collection']['detail']['edit'];
	$deep     = function ( $item, $k ) {
		foreach ( explode( '.', $k ) as $p ) {
			$item = is_array( $item ) && array_key_exists( $p, $item ) ? $item[ $p ] : null;
		}
		return $item;
	};
	$row      = function ( $id ) use ( $wpdb ) {
		return $wpdb->get_row( $wpdb->prepare( "SELECT match_data, position, action_data FROM {$wpdb->prefix}redirection_items WHERE id=%d", $id ), ARRAY_A );
	};
	$cases    = array(
		'login rule' => array( 'url' => '/minn-v042-login-' . time(), 'match_type' => 'login', 'action_type' => 'url', 'action_code' => 301, 'group_id' => 1, 'action_data' => array( 'logged_in' => '/members', 'logged_out' => '/login' ) ),
		'flagged rule at position 5' => array( 'url' => '/minn-v042-flags-' . time(), 'match_type' => 'url', 'action_type' => 'url', 'action_code' => 301, 'group_id' => 1, 'position' => 5, 'match_data' => array( 'source' => array( 'flag_query' => 'pass' ) ), 'action_data' => array( 'url' => '/flag-target' ) ),
	);
	foreach ( $cases as $label => $args ) {
		$item = Red_Item::create( $args );
		if ( is_wp_error( $item ) ) {
			$check( "Redirection seed: {$label}", false, $item->get_error_message() );
			continue;
		}
		$id   = $item->get_id();
		$json = Red_Item::get_by_id( $id )->to_json();
		// What the client sends: preserve keys, then every edit field.
		$body = array();
		foreach ( (array) $edit['preserve'] as $k ) {
			$v = $deep( $json, $k );
			if ( null !== $v ) {
				$body[ $k ] = $v;
			}
		}
		foreach ( $edit['fields'] as $f ) {
			$v     = $deep( $json, $f['key'] );
			$parts = explode( '.', $f['key'] );
			$ref   = &$body;
			foreach ( array_slice( $parts, 0, -1 ) as $p ) {
				if ( ! isset( $ref[ $p ] ) || ! is_array( $ref[ $p ] ) ) {
					$ref[ $p ] = array();
				}
				$ref = &$ref[ $p ];
			}
			$ref[ end( $parts ) ] = null === $v ? '' : $v;
			unset( $ref );
		}
		list( $st ) = $call( 'POST', '/redirection/v1/redirect/' . $id, $body );
		$minn       = $row( $id );
		// Vendor control: Redirection's own UI posts the whole item back.
		$call( 'POST', '/redirection/v1/redirect/' . $id, $json );
		$vendor = $row( $id );
		$check( "Redirection unchanged save keeps the {$label} exactly as Redirection's own save would", 200 === $st && $minn == $vendor, wp_json_encode( array( 'minn' => $minn, 'vendor' => $vendor ) ) );
		$wpdb->delete( $wpdb->prefix . 'redirection_items', array( 'id' => $id ) );
	}

	// --- #19 the two log retentions are independent ----------------------
	$orig = red_get_options();
	red_set_options( array( 'expire_redirect' => 7, 'expire_404' => -1 ) );
	list( $st ) = $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'expire_redirect' => '30' ) ) );
	$o          = red_get_options();
	$check( 'Redirection: changing the redirect log leaves a disabled 404 log off', 200 === $st && 30 === (int) $o['expire_redirect'] && -1 === (int) $o['expire_404'], wp_json_encode( array( $o['expire_redirect'], $o['expire_404'] ) ) );
	$call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'expire_404' => '0' ) ) );
	$o = red_get_options();
	$check( 'Redirection: "forever" (0) is storable for a log', 0 === (int) $o['expire_404'], (string) $o['expire_404'] );
	list( $st ) = $call( 'POST', '/minn-admin/v1/redirection/settings/general', array( 'values' => array( 'expire_404' => 'soon' ) ) );
	$check( 'Redirection: a retention outside the vocabulary is refused', 400 === $st, (string) $st );
	red_set_options( array( 'expire_redirect' => $orig['expire_redirect'], 'expire_404' => $orig['expire_404'] ) );
} else {
	$skip( 'Redirection inactive' );
}

// --- #9 / #33 ACF field schema round trips ---------------------------------
if ( function_exists( 'acf_update_field_group' ) && function_exists( 'acf_get_store' ) ) {
	$gkey  = 'group_minn_v042_' . strtolower( wp_generate_password( 6, false ) );
	$group = acf_update_field_group( array( 'key' => $gkey, 'title' => 'Minn v042 probe', 'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ) ) );
	$req_f = acf_update_field( array( 'key' => 'field_' . strtolower( wp_generate_password( 8, false ) ), 'label' => 'Must', 'name' => 'minn_v042_must', 'type' => 'text', 'required' => 1, 'parent' => $group['ID'] ) );
	$sel_f = acf_update_field( array(
		'key'           => 'field_' . strtolower( wp_generate_password( 8, false ) ),
		'label'         => 'Ratio',
		'name'          => 'minn_v042_ratio',
		'type'          => 'select',
		'multiple'      => 1,
		'choices'       => array( '16:9' => 'Widescreen', '4:3' => 'Standard' ),
		'default_value' => array( '16:9', '4:3' ),
		'parent'        => $group['ID'],
	) );
	acf_get_store( 'field-groups' )->reset();
	acf_get_store( 'fields' )->reset();

	// The Fields view row under a German locale, saved back the way the client
	// does: a seed that is not one of the select's values falls back to 'No'.
	update_user_meta( $admin, 'locale', 'de_DE' );
	switch_to_user_locale( $admin );
	list( , $list ) = $call( 'GET', '/minn-admin/v1/acf/schema/groups/' . $gkey . '/fields' );
	$item           = null;
	foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
		if ( $it['id'] === $req_f['key'] ) {
			$item = $it;
		}
	}
	$seed = $item['required'] ?? null;
	$sent = in_array( $seed, array( 'No', 'Yes' ), true ) ? $seed : 'No';
	$call( 'PUT', '/minn-admin/v1/acf/schema/fields/' . $req_f['key'], array( 'label' => 'Must (relabelled)', 'default_value' => $item['default_value'] ?? '', 'choices' => $item['choices'] ?? '', 'required' => $sent ) );
	restore_current_locale();
	delete_user_meta( $admin, 'locale' );
	acf_get_store( 'fields' )->reset();
	$after = acf_get_field( $req_f['key'] );
	$check( 'ACF: Required seeds as an option value on a translated site', 'Yes' === $seed, var_export( $seed, true ) );
	$check( 'ACF: relabelling a required field keeps it required', ! empty( $after['required'] ), var_export( $after['required'] ?? null, true ) );

	list( , $full ) = $call( 'GET', '/minn-admin/v1/acf/schema/groups/' . $gkey . '/full' );
	list( $st )     = $call( 'POST', '/minn-admin/v1/acf/schema/groups/' . $gkey . '/full', $full );
	acf_get_store( 'fields' )->reset();
	$sel = acf_get_field( $sel_f['key'] );
	$check( 'ACF builder: an unchanged save keeps choice values that contain a colon', 200 === $st && array( '16:9' => 'Widescreen', '4:3' => 'Standard' ) === $sel['choices'], wp_json_encode( $sel['choices'] ) );
	$check( 'ACF builder: an unchanged save keeps a multiple-select default', array( '16:9', '4:3' ) === array_values( (array) $sel['default_value'] ), wp_json_encode( $sel['default_value'] ) );
	acf_delete_field_group( $group['ID'] );
} else {
	$skip( 'ACF inactive' );
}

// --- #25 Duplicate keeps backslashes; #29 CCJ code keeps backslashes ------
$bs_content = '<!-- wp:paragraph {"note":"a\u003cb"} --><p>C:\\Users\\minn and a regex \\d+</p><!-- /wp:paragraph -->';
$src        = wp_insert_post( wp_slash( array( 'post_title' => 'Minn v042 slash \\ probe', 'post_content' => $bs_content, 'post_status' => 'draft' ) ) );
$check( 'Duplicate seed stored its backslashes', get_post( $src )->post_content === $bs_content );
list( $st, $dup ) = $call( 'POST', '/minn-admin/v1/posts/' . $src . '/duplicate' );
$copy             = ! empty( $dup['id'] ) ? get_post( (int) $dup['id'] ) : null;
$check( 'Duplicate copies content byte for byte, backslashes included', $copy && $copy->post_content === $bs_content, $copy ? substr( $copy->post_content, 0, 90 ) : 'status ' . $st );
$check( 'Duplicate copies a title with a backslash', $copy && false !== strpos( $copy->post_title, '\\' ), $copy ? $copy->post_title : '' );
if ( $copy ) {
	wp_delete_post( $copy->ID, true );
}
wp_delete_post( $src, true );

if ( function_exists( 'minn_admin_ccj_active' ) && minn_admin_ccj_active() && post_type_exists( 'custom-css-js' ) ) {
	$css = ".icon:before { content: \"\\f101\"; }\n";
	list( $st, $made ) = $call( 'POST', '/minn-admin/v1/ccj/snippets', array( 'name' => 'Minn v042 slash', 'code' => $css, 'language' => 'css', 'type' => 'header', 'side' => 'frontend', 'linking' => 'internal', 'priority' => 5, 'active' => false ) );
	$cid               = (int) ( $made['id'] ?? 0 );
	$check( 'CCJ create keeps backslashes in the code', $cid && get_post( $cid )->post_content === $css, $cid ? get_post( $cid )->post_content : 'status ' . $st );
	if ( $cid ) {
		$css2 = ".icon:after { content: \"\\f102\"; }\n";
		$call( 'PUT', '/minn-admin/v1/ccj/snippets/' . $cid, array( 'code' => $css2, 'name' => 'Minn v042 slash \\ renamed' ) );
		clean_post_cache( $cid );
		$check( 'CCJ update keeps backslashes in the code', get_post( $cid )->post_content === $css2, get_post( $cid )->post_content );
		$call( 'DELETE', '/minn-admin/v1/ccj/snippets/' . $cid );
	}
} else {
	$skip( 'Custom CSS & JS inactive' );
}

// --- #26 Term merge honours the per-term caps and the default term --------
$merge_tax = taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
$t_def     = wp_insert_term( 'Minn v042 default ' . wp_rand(), $merge_tax );
$t_into    = wp_insert_term( 'Minn v042 into ' . wp_rand(), $merge_tax );
$t_other   = wp_insert_term( 'Minn v042 other ' . wp_rand(), $merge_tax );
if ( ! is_wp_error( $t_def ) && ! is_wp_error( $t_into ) && ! is_wp_error( $t_other ) ) {
	$was_default = get_option( 'default_' . $merge_tax );
	update_option( 'default_' . $merge_tax, $t_def['term_id'] );
	list( $st ) = $call( 'POST', '/minn-admin/v1/terms/merge', array( 'taxonomy' => $merge_tax, 'from' => $t_def['term_id'], 'into' => $t_into['term_id'] ) );
	$check( "Term merge refuses to merge away the {$merge_tax} default term", 400 === $st && term_exists( (int) $t_def['term_id'], $merge_tax ), 'status ' . $st );
	update_option( 'default_' . $merge_tax, $was_default );
	list( $st ) = $call( 'POST', '/minn-admin/v1/terms/merge', array( 'taxonomy' => $merge_tax, 'from' => $t_other['term_id'], 'into' => $t_into['term_id'] ) );
	$check( 'Term merge still merges an ordinary term (control)', 200 === $st && ! term_exists( (int) $t_other['term_id'], $merge_tax ), 'status ' . $st );
	foreach ( array( $t_def, $t_into, $t_other ) as $t ) {
		wp_delete_term( (int) $t['term_id'], $merge_tax );
	}
} else {
	$skip( 'term merge seed failed' );
}

// --- #31 System diagnostics and the database viewer sit behind Site Health --
if ( ! is_multisite() ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	remove_role( 'minn_v042_optmgr' );
	add_role( 'minn_v042_optmgr', 'Minn v042 options manager', array( 'read' => true, 'edit_posts' => true, 'manage_options' => true ) );
	$optmgr = wp_insert_user( array( 'user_login' => 'minn_v042_optmgr_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v042_optmgr' ) );
	wp_set_current_user( $optmgr );
	list( $st_sys ) = $call( 'GET', '/minn-admin/v1/system' );
	list( $st_db )  = $call( 'GET', '/minn-admin/v1/db/tables' );
	$check( 'System diagnostics refuse manage_options without Site Health access', 403 === $st_sys, 'status ' . $st_sys );
	$check( 'Database viewer refuses manage_options without Site Health access', 403 === $st_db, 'status ' . $st_db );
	wp_set_current_user( $admin );
	list( $st_sys ) = $call( 'GET', '/minn-admin/v1/system' );
	list( $st_db )  = $call( 'GET', '/minn-admin/v1/db/tables' );
	$check( 'An administrator still reads System and the database viewer (control)', 200 === $st_sys && 200 === $st_db, "system {$st_sys} db {$st_db}" );
	wp_delete_user( $optmgr );
	remove_role( 'minn_v042_optmgr' );
}

// --- #15 Duplicator 5 downloads sit on its export rung ---------------------
if ( function_exists( 'minn_admin_duplicator_is_v5' ) && minn_admin_duplicator_active() && minn_admin_duplicator_is_v5() ) {
	global $wpdb;
	$prow = $wpdb->get_row( 'SELECT id, name, hash FROM ' . minn_admin_duplicator_table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
	if ( $prow ) {
		$fake = minn_admin_duplicator_ssdir() . '/' . $prow['name'] . '_' . $prow['hash'] . '_archive.zip';
		$made = ! file_exists( $fake ) && file_put_contents( $fake, 'probe' );
		$ok0  = minn_admin_duplicator_download_files( (int) $prow['id'] );
		$check( 'Duplicator: export rung downloads a package (control)', ! is_wp_error( $ok0 ) && count( $ok0 ) >= 1 );
		$deny = function ( $on, $cap ) {
			return \Duplicator\Core\CapMng::CAP_EXPORT === $cap ? false : $on;
		};
		add_filter( 'duplicator_cap_enabled', $deny, 10, 2 );
		$r = minn_admin_duplicator_download_files( (int) $prow['id'] );
		$check( 'Duplicator: no download without CAP_EXPORT, even with CAP_CREATE', is_wp_error( $r ) && minn_admin_duplicator_can_build(), is_wp_error( $r ) ? $r->get_error_code() : count( $r ) . ' file(s)' );
		remove_filter( 'duplicator_cap_enabled', $deny, 10 );
		if ( $made ) {
			unlink( $fake );
		}
	} else {
		$skip( 'Duplicator has no package to probe' );
	}
} else {
	$skip( 'Duplicator 5 inactive' );
}

// --- #20 PowerPress "Roles and Capabilities" gates the episode panel -------
if ( function_exists( 'minn_admin_powerpress_active' ) && minn_admin_powerpress_active() ) {
	$editor = get_user_by( 'login', 'minn-author' ); // the Author role carries no edit_podcast here
	if ( $editor ) {
		$general_was = get_option( 'powerpress_general', array() );
		$pp_post     = wp_insert_post( array( 'post_title' => 'Minn v042 podcast probe', 'post_status' => 'draft', 'post_author' => $editor->ID ) );
		$pp_write    = function () use ( $call, $pp_post ) {
			return $call( 'POST', '/wp/v2/posts/' . $pp_post, array( 'minn_powerpress' => array( 'url' => 'https://example.com/minn-v042.mp3', 'size' => '1234', 'duration' => '00:01:00' ) ) );
		};
		update_option( 'powerpress_general', array_merge( (array) $general_was, array( 'use_caps' => 1 ) ) );
		wp_set_current_user( $editor->ID );
		$pp_write();
		list( , $pp_read ) = $call( 'GET', '/wp/v2/posts/' . $pp_post, null, array( 'context' => 'edit' ) );
		wp_set_current_user( $admin );
		$check( 'PowerPress (roles on): an author without edit_podcast cannot attach an episode', '' === (string) get_post_meta( $pp_post, 'enclosure', true ), (string) get_post_meta( $pp_post, 'enclosure', true ) );
		$check( 'PowerPress (roles on): an author without edit_podcast reads no episode fields', empty( (array) ( $pp_read['minn_powerpress'] ?? array() ) ), wp_json_encode( $pp_read['minn_powerpress'] ?? null ) );
		update_option( 'powerpress_general', array_merge( (array) $general_was, array( 'use_caps' => 0 ) ) );
		wp_set_current_user( $editor->ID );
		$pp_write();
		wp_set_current_user( $admin );
		$check( 'PowerPress (roles off): the author attaches the episode (control)', false !== strpos( (string) get_post_meta( $pp_post, 'enclosure', true ), 'minn-v042.mp3' ) );
		update_option( 'powerpress_general', $general_was );
		wp_delete_post( $pp_post, true );
	} else {
		$skip( 'no minn-author fixture user' );
	}
} else {
	$skip( 'PowerPress inactive' );
}

// --- #21 Meta Box readonly / disabled fields stay locked -------------------
if ( function_exists( 'rwmb_get_registry' ) && function_exists( 'minn_admin_meta_box_write_values' ) ) {
	$mb_box = rwmb_get_registry( 'meta_box' )->make( array(
		'id'         => 'minn_v042_mb',
		'title'      => 'Minn v042 probe',
		'post_types' => array( 'post' ),
		'fields'     => array(
			array( 'id' => 'minn_v042_ro', 'name' => 'Locked', 'type' => 'text', 'readonly' => true ),
			array( 'id' => 'minn_v042_dis', 'name' => 'Disabled', 'type' => 'text', 'attributes' => array( 'disabled' => true ) ),
			array( 'id' => 'minn_v042_ok', 'name' => 'Open', 'type' => 'text' ),
		),
	) );
	// A box made after init never reaches the field registry the setter reads.
	if ( method_exists( $mb_box, 'register_fields' ) ) {
		$mb_box->register_fields();
	}
	$mb_post = wp_insert_post( array( 'post_title' => 'Minn v042 meta box probe', 'post_status' => 'draft' ) );
	update_post_meta( $mb_post, 'minn_v042_ro', 'set by code' );
	update_post_meta( $mb_post, 'minn_v042_dis', 'set by code' );
	minn_admin_meta_box_write_values( $mb_post, array( 'minn_v042_ro' => 'overwritten', 'minn_v042_dis' => 'overwritten', 'minn_v042_ok' => 'written' ) );
	$check( 'Meta Box: a readonly field is not written', 'set by code' === get_post_meta( $mb_post, 'minn_v042_ro', true ), get_post_meta( $mb_post, 'minn_v042_ro', true ) );
	$check( 'Meta Box: a field disabled in its attributes is not written', 'set by code' === get_post_meta( $mb_post, 'minn_v042_dis', true ), get_post_meta( $mb_post, 'minn_v042_dis', true ) );
	$check( 'Meta Box: an ordinary field is still written (control)', 'written' === get_post_meta( $mb_post, 'minn_v042_ok', true ) );
	wp_delete_post( $mb_post, true );
} else {
	$skip( 'Meta Box inactive' );
}

// --- #12 Perfmatters login_url_message needs the raw-output rule -----------
if ( function_exists( 'minn_admin_perfmatters_save' ) && function_exists( 'perfmatters_settings' ) ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	$pm_was  = get_option( 'perfmatters_options', array() );
	remove_role( 'minn_v042_pm' );
	add_role( 'minn_v042_pm', 'Minn v042 settings manager', array( 'read' => true, 'manage_options' => true ) );
	$pm_user = wp_insert_user( array( 'user_login' => 'minn_v042_pm_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v042_pm' ) );
	wp_set_current_user( $pm_user );
	minn_admin_perfmatters_save( array( 'perfmatters_options::login_url_message' => 'Gone fishing <b>now</b>' ) );
	$pm_now = get_option( 'perfmatters_options', array() );
	$check( 'Perfmatters: login_url_message is not written without unfiltered_html', ( $pm_was['login_url_message'] ?? null ) === ( $pm_now['login_url_message'] ?? null ), wp_json_encode( $pm_now['login_url_message'] ?? null ) );
	wp_set_current_user( $admin );
	minn_admin_perfmatters_save( array( 'perfmatters_options::login_url_message' => 'Gone fishing' ) );
	$pm_now = get_option( 'perfmatters_options', array() );
	$check( 'Perfmatters: an administrator still sets login_url_message (control)', 'Gone fishing' === ( $pm_now['login_url_message'] ?? null ), wp_json_encode( $pm_now['login_url_message'] ?? null ) );
	update_option( 'perfmatters_options', $pm_was );
	wp_delete_user( $pm_user );
	remove_role( 'minn_v042_pm' );
} else {
	$skip( 'Perfmatters inactive' );
}

// --- #13 Bricks' latched answers never outlive a demotion to user 0 --------
if ( class_exists( '\Bricks\Capabilities' ) && function_exists( 'minn_admin_bricks_forms_can_view' ) ) {
	wp_set_current_user( $admin );
	\Bricks\Capabilities::$capabilities_set       = true;
	\Bricks\Capabilities::$form_submission_access = true;
	wp_set_current_user( 0 ); // core's nonce-less cookie demotion
	\Bricks\Capabilities::$capabilities_set = true;
	$check( 'Bricks: a signed-out request never reads form submissions, whatever Bricks latched', ! minn_admin_bricks_forms_can_view() && ! minn_admin_bricks_fallback_access() );
	wp_set_current_user( $admin );
	\Bricks\Capabilities::$capabilities_set = false;
} else {
	$skip( 'Bricks not loaded' );
}

$summary();
