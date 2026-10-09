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
	// A checkbox default rides one value per line through the Fields view.
	$cb_f = acf_update_field( array( 'key' => 'field_' . strtolower( wp_generate_password( 8, false ) ), 'label' => 'Colours', 'name' => 'minn_v042_cb', 'type' => 'checkbox', 'choices' => array( 'red' => 'Red', 'blue' => 'Blue' ), 'default_value' => array( 'red', 'blue' ), 'parent' => $group['ID'] ) );
	acf_get_store( 'fields' )->reset();
	list( , $list2 ) = $call( 'GET', '/minn-admin/v1/acf/schema/groups/' . $gkey . '/fields' );
	$cb_item         = null;
	foreach ( (array) ( $list2['items'] ?? array() ) as $it ) {
		if ( $it['id'] === $cb_f['key'] ) {
			$cb_item = $it;
		}
	}
	$call( 'PUT', '/minn-admin/v1/acf/schema/fields/' . $cb_f['key'], array( 'label' => 'Colours (relabelled)', 'default_value' => $cb_item['default_value'] ?? '', 'choices' => $cb_item['choices'] ?? '', 'required' => $cb_item['required'] ?? 'No' ) );
	acf_get_store( 'fields' )->reset();
	$cb_now = acf_get_field( $cb_f['key'] );
	$check( 'ACF: a relabel keeps a checkbox field\'s two defaults', array( 'red', 'blue' ) === array_values( (array) $cb_now['default_value'] ), wp_json_encode( $cb_now['default_value'] ) );
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
	$pm_msg = 'We\'re sorry, <a href="/contact">contact us</a>';
	minn_admin_perfmatters_save( array( 'perfmatters_options::login_url_message' => $pm_msg ) );
	$pm_now = get_option( 'perfmatters_options', array() );
	$check( 'Perfmatters: the login message keeps its quotes and link for an administrator', $pm_msg === ( $pm_now['login_url_message'] ?? null ), wp_json_encode( $pm_now['login_url_message'] ?? null ) );
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

// --- #16 Gravity SMTP list never falls back to cc/bcc for a list-only role --
if ( function_exists( 'minn_admin_gravity_smtp_recipients' ) ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	// A blob whose `to` scope cannot be resolved but whose bcc can.
	$blob = serialize( array( 'bcc' => array( array( 'email' => 'hidden-bcc@example.com', 'name' => '' ) ), 'subject' => 'x' ) );
	remove_role( 'minn_v042_gsmtp' );
	add_role( 'minn_v042_gsmtp', 'Minn v042 mail log list', array( 'read' => true, minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG' ) => true ) );
	$gs_user = wp_insert_user( array( 'user_login' => 'minn_v042_gsmtp_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'minn_v042_gsmtp' ) );
	wp_set_current_user( $gs_user );
	$shown = minn_admin_gravity_smtp_recipients( $blob );
	$check( 'Gravity SMTP: a list-only role never sees bcc recipients in the To column', false === strpos( $shown, 'hidden-bcc' ), $shown );
	wp_set_current_user( $admin );
	$shown = minn_admin_gravity_smtp_recipients( $blob );
	$check( 'Gravity SMTP: a details-level caller still gets the best-effort column (control)', false !== strpos( $shown, 'hidden-bcc' ), $shown );
	wp_delete_user( $gs_user );
	remove_role( 'minn_v042_gsmtp' );
} else {
	$skip( 'Gravity SMTP adapter not loaded' );
}

// --- #32 Jetpack Stats top pages link only to this site --------------------
if ( function_exists( 'minn_admin_jetpack_stats_pages_refs' ) ) {
	$own_url = home_url( '/minn-v042-own/' );
	$stub    = new class( $own_url ) {
		private $own;
		public function __construct( $own ) {
			$this->own = $own;
		}
		public function get_top_posts( $args ) {
			return array( 'summary' => array( 'postviews' => array(
				array( 'href' => $this->own, 'title' => 'Own', 'views' => 3 ),
				array( 'href' => 'https://evil.example/phish', 'title' => 'Off-site', 'views' => 2 ),
				array( 'href' => 'javascript:alert(1)', 'title' => 'Script', 'views' => 1 ),
			) ) );
		}
		public function get_referrers( $args ) {
			return array();
		}
	};
	$jp    = minn_admin_jetpack_stats_pages_refs( $stub, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) );
	$urls  = wp_list_pluck( (array) ( $jp['pages'] ?? array() ), 'url', 'title' );
	$check( 'Jetpack Stats: an own-site page keeps its link (control)', ( $urls['Own'] ?? '' ) === $own_url, wp_json_encode( $urls ) );
	$check( 'Jetpack Stats: off-site and script URLs render unlinked', '' === ( $urls['Off-site'] ?? 'missing' ) && '' === ( $urls['Script'] ?? 'missing' ), wp_json_encode( $urls ) );
} else {
	$skip( 'Jetpack Stats adapter not loaded' );
}

// --- #14 ACPT repeater rows carry only the subs the caller may read --------
if ( function_exists( 'minn_admin_acpt_value_out' ) && defined( 'MINN_ADMIN_ACPT_SIMPLE' ) ) {
	$acpt_child = function ( $name, $read ) {
		return new class( $name, $read ) {
			private $n;
			private $r;
			public function __construct( $n, $r ) {
				$this->n = $n;
				$this->r = $r;
			}
			public function getType() {
				return 'Text';
			}
			public function getName() {
				return $this->n;
			}
			public function getLabelOrName() {
				return $this->n;
			}
			public function getOptions() {
				return array();
			}
			public function userPermissions() {
				return array( 'read' => $this->r, 'edit' => $this->r );
			}
		};
	};
	$acpt_rep   = new class( array( $acpt_child( 'title', true ), $acpt_child( 'salary', false ) ) ) {
		private $c;
		public function __construct( $c ) {
			$this->c = $c;
		}
		public function getType() {
			return 'Repeater';
		}
		public function getChildren() {
			return $this->c;
		}
		public function getId() {
			return 'minn_v042_rep';
		}
		public function getLabelOrName() {
			return 'Team';
		}
	};
	$acpt_rows = minn_admin_acpt_value_out( $acpt_rep, array( array( 'title' => 'Lead', 'salary' => '90000' ) ) );
	$acpt_vals = (array) ( $acpt_rows[0]['values'] ?? array() );
	$check( 'ACPT: a repeater row omits a sub-field the caller may not read', ! array_key_exists( 'salary', $acpt_vals ), wp_json_encode( $acpt_vals ) );
	$check( 'ACPT: a readable sub-field is still returned (control)', 'Lead' === ( $acpt_vals['title'] ?? null ), wp_json_encode( $acpt_vals ) );
} else {
	$skip( 'ACPT adapter not loaded' );
}

// --- #17 Gravity Forms notification edits keep an untouched HTML message ---
if ( class_exists( 'GFAPI' ) ) {
	$gf_form = GFAPI::get_form( 1 );
	if ( $gf_form && ! empty( $gf_form['notifications']['minnfixuser00002'] ) ) {
		$gf_nid  = 'minnfixuser00002';
		$gf_was  = $gf_form['notifications'][ $gf_nid ];
		$gf_html = '<html><head><style>td{padding:8px}</style></head><body><table><tr><td>{all_fields}</td></tr></table></body></html>';
		$gf_form['notifications'][ $gf_nid ]['message'] = $gf_html;
		GFAPI::update_form( $gf_form );
		// The notification page: load it, rename it, save what the client holds.
		list( , $gf_page ) = $call( 'GET', '/minn-admin/v1/gf/notifications/1:' . $gf_nid . '/full' );
		$gf_body           = is_array( $gf_page ) && isset( $gf_page['notification'] ) ? $gf_page['notification'] : array();
		$gf_body['name']   = 'User confirmation (renamed)';
		list( $st )        = $call( 'POST', '/minn-admin/v1/gf/notifications/1:' . $gf_nid . '/full', $gf_body );
		$gf_now = GFAPI::get_form( 1 )['notifications'][ $gf_nid ];
		$check( 'Gravity Forms: renaming a notification keeps its HTML message byte for byte', 200 === $st && $gf_html === $gf_now['message'], 'status ' . $st . ' ' . substr( (string) $gf_now['message'], 0, 80 ) );
		$restore = GFAPI::get_form( 1 );
		$restore['notifications'][ $gf_nid ] = $gf_was;
		GFAPI::update_form( $restore );
	} else {
		$skip( 'Gravity Forms fixture notification missing' );
	}
	// A caller without unfiltered_html still gets kses (control).
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	$gf_ed = wp_insert_user( array( 'user_login' => 'minn_v042_gf_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
	wp_set_current_user( $gf_ed );
	$check( 'Gravity Forms: a caller without unfiltered_html still gets kses (control)', function_exists( 'minn_admin_gf_kses' ) && false === strpos( minn_admin_gf_kses( '<p>x</p><script>alert(1)</script>' ), '<script' ) );
	wp_set_current_user( $admin );
	wp_delete_user( $gf_ed );
} else {
	$skip( 'Gravity Forms inactive' );
}

// --- #18 SEO panel keeps snippet variables -------------------------------
if ( defined( 'WPSEO_VERSION' ) ) {
	$seo_post = wp_insert_post( array( 'post_title' => 'Minn v042 seo probe', 'post_status' => 'draft' ) );
	update_post_meta( $seo_post, '_yoast_wpseo_title', '%%title%% %%sep%% %%category%%' );
	update_post_meta( $seo_post, '_yoast_wpseo_metadesc', '%%excerpt%% filed under %%category%%' );
	list( , $seo_read ) = $call( 'GET', '/wp/v2/posts/' . $seo_post, null, array( 'context' => 'edit' ) );
	$seo_vals           = (array) ( $seo_read['minn_seo'] ?? array() );
	$seo_vals['focus_keyword'] = 'minn probe';
	$call( 'POST', '/wp/v2/posts/' . $seo_post, array( 'minn_seo' => $seo_vals ) );
	$check( 'SEO (Yoast): an untouched title keeps its snippet variables', '%%title%% %%sep%% %%category%%' === get_post_meta( $seo_post, '_yoast_wpseo_title', true ), get_post_meta( $seo_post, '_yoast_wpseo_title', true ) );
	$check( 'SEO (Yoast): an untouched description keeps its snippet variables', '%%excerpt%% filed under %%category%%' === get_post_meta( $seo_post, '_yoast_wpseo_metadesc', true ), get_post_meta( $seo_post, '_yoast_wpseo_metadesc', true ) );
	$seo_vals['title'] = '%%category%% news <b>today</b>';
	$call( 'POST', '/wp/v2/posts/' . $seo_post, array( 'minn_seo' => $seo_vals ) );
	$check( 'SEO (Yoast): a typed title keeps its variable and loses its markup', '%%category%% news today' === get_post_meta( $seo_post, '_yoast_wpseo_title', true ), get_post_meta( $seo_post, '_yoast_wpseo_title', true ) );
	wp_delete_post( $seo_post, true );
} else {
	$skip( 'Yoast inactive' );
}

// --- #24 WPForms trash and restore keep an entry's typed status ------------
if ( function_exists( 'wpforms' ) && function_exists( 'minn_admin_wpforms_table' ) ) {
	global $wpdb;
	$wpf_entry = $wpdb->get_row( 'SELECT entry_id, status FROM ' . minn_admin_wpforms_table() . ' ORDER BY entry_id DESC LIMIT 1' );
	if ( $wpf_entry ) {
		$wpf_id  = (int) $wpf_entry->entry_id;
		$wpf_was = (string) $wpf_entry->status;
		$wpdb->update( minn_admin_wpforms_table(), array( 'status' => 'partial' ), array( 'entry_id' => $wpf_id ) );
		list( $st1 ) = $call( 'POST', '/minn-admin/v1/wpforms/entries/' . $wpf_id . '/status', array( 'status' => 'trash' ) );
		list( $st2 ) = $call( 'POST', '/minn-admin/v1/wpforms/entries/' . $wpf_id . '/status', array( 'status' => 'restore' ) );
		$wpf_now     = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM ' . minn_admin_wpforms_table() . ' WHERE entry_id = %d', $wpf_id ) );
		$wpf_left    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforms_entry_meta WHERE entry_id = %d AND type = 'status_prev'", $wpf_id ) );
		$check( 'WPForms: a partial entry trashed and restored comes back partial', 200 === $st1 && 200 === $st2 && 'partial' === $wpf_now, "{$st1}/{$st2} status=" . var_export( $wpf_now, true ) );
		$check( 'WPForms: restore consumes the status_prev record', 0 === $wpf_left, (string) $wpf_left );
		$wpdb->update( minn_admin_wpforms_table(), array( 'status' => $wpf_was ), array( 'entry_id' => $wpf_id ) );
	} else {
		$skip( 'WPForms has no entry to probe' );
	}
} else {
	$skip( 'WPForms inactive' );
}

// --- #10 Asset CleanUp saves start from the stored settings ----------------
if ( function_exists( 'minn_admin_asset_cleanup_save' ) && function_exists( 'minn_admin_asset_cleanup_option_key' ) ) {
	$acu_key = minn_admin_asset_cleanup_option_key();
	$acu_was = get_option( $acu_key, '' );
	$acu_arr = is_string( $acu_was ) && '' !== $acu_was ? (array) json_decode( $acu_was, true ) : array();
	$acu_arr = array_merge( $acu_arr, array( 'google_fonts_remove' => '1', 'google_fonts_display' => 'swap', 'google_fonts_preconnect' => '1', 'google_fonts_local' => '1' ) );
	update_option( $acu_key, wp_json_encode( $acu_arr ) );
	wp_cache_delete( $acu_key, 'options' );
	minn_admin_asset_cleanup_save( array( 'disable_emojis' => true ) );
	wp_cache_delete( $acu_key, 'options' );
	$acu_now = (array) json_decode( (string) get_option( $acu_key, '' ), true );
	$check( 'Asset CleanUp: an unrelated toggle keeps the stored Google Fonts preferences', 'swap' === ( $acu_now['google_fonts_display'] ?? '' ) && '1' === (string) ( $acu_now['google_fonts_preconnect'] ?? '' ) && '1' === (string) ( $acu_now['google_fonts_local'] ?? '' ), wp_json_encode( array_intersect_key( $acu_now, array_flip( array( 'google_fonts_display', 'google_fonts_preconnect', 'google_fonts_local' ) ) ) ) );
	$check( 'Asset CleanUp: the toggled setting itself is written (control)', '1' === (string) ( $acu_now['disable_emojis'] ?? '' ) );
	// A row never saved (common: not written on activation) must start from
	// Asset CleanUp's defaults, never from nothing.
	delete_option( $acu_key );
	wp_cache_delete( $acu_key, 'options' );
	$acu_res = minn_admin_asset_cleanup_save( array( 'disable_emojis' => true ) );
	wp_cache_delete( $acu_key, 'options' );
	$acu_new = (array) json_decode( (string) get_option( $acu_key, '' ), true );
	if ( class_exists( '\\WpAssetCleanUp\\Settings' ) ) {
		$acu_def = ( new \WpAssetCleanUp\Settings() )->defaultSettings;
		$check( 'Asset CleanUp: a save over a never-saved row keeps the defaults', ! is_wp_error( $acu_res ) && '1' === (string) ( $acu_new['disable_emojis'] ?? '' ) && (string) ( $acu_def['dashboard_show'] ?? '' ) === (string) ( $acu_new['dashboard_show'] ?? 'missing' ), wp_json_encode( array( 'dashboard_show' => $acu_new['dashboard_show'] ?? null, 'keys' => count( $acu_new ) ) ) );
	} else {
		$check( 'Asset CleanUp: a save over a never-saved row is refused when defaults are unknown', is_wp_error( $acu_res ) );
	}
	update_option( $acu_key, $acu_was );
} else {
	$skip( 'Asset CleanUp inactive' );
}

// --- #22 Store settings post option-array ids nested -----------------------
if ( function_exists( 'minn_admin_wc_settings_post_data' ) && class_exists( 'WC_Admin_Settings' ) ) {
	update_option( 'minn_v042_arr', array( 'one' => 'yes', 'two' => 'yes', 'three' => 'no' ) );
	$wc_fields = array(
		array( 'id' => 'minn_v042_arr[one]', 'type' => 'checkbox', 'default' => 'no' ),
		array( 'id' => 'minn_v042_arr[two]', 'type' => 'checkbox', 'default' => 'no' ),
		array( 'id' => 'minn_v042_arr[three]', 'type' => 'checkbox', 'default' => 'no' ),
	);
	$wc_post = minn_admin_wc_settings_post_data( $wc_fields, array( 'minn_v042_arr[three]' => true ) );
	WC_Admin_Settings::save_fields( $wc_fields, $wc_post );
	$wc_now = get_option( 'minn_v042_arr' );
	$check( 'Store settings: turning one option-array switch on keeps the others on', array( 'one' => 'yes', 'two' => 'yes', 'three' => 'yes' ) === (array) $wc_now, wp_json_encode( $wc_now ) );
	delete_option( 'minn_v042_arr' );
} else {
	$skip( 'WooCommerce settings not loaded' );
}

// --- #11 DB browser redacts more vendor credentials ------------------------
if ( class_exists( 'Minn_Admin_DB' ) ) {
	global $wpdb;
	$sec_opt   = 'minnv042secretopt' . wp_rand( 1000, 9999 );
	$opt_had   = get_option( 'wp_mail_smtp_mail_key', null );
	if ( null === $opt_had ) {
		add_option( 'wp_mail_smtp_mail_key', $sec_opt, '', false );
	}
	$opt_value = (string) get_option( 'wp_mail_smtp_mail_key' );
	$sec_meta  = 'minnv042trust' . wp_rand( 1000, 9999 );
	update_user_meta( $admin, 'tfa_trusted_devices', array( array( 'token' => $sec_meta ) ) );
	list( , $r1 ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'fcol' => 'option_name', 'fq' => 'wp_mail_smtp_mail_key' ) );
	list( , $r2 ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->usermeta, 'fcol' => 'meta_key', 'fq' => 'tfa_trusted_devices' ) );
	list( , $r3 ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->usermeta, 'fcol' => 'meta_value', 'fq' => $sec_meta ) );
	$check( 'DB browser: the WP Mail SMTP sealing key renders redacted', false === strpos( wp_json_encode( $r1 ), $opt_value ), substr( wp_json_encode( $r1 ), 0, 160 ) );
	$check( 'DB browser: a Simba TFA trusted-device token renders redacted', false === strpos( wp_json_encode( $r2 ), $sec_meta ), substr( wp_json_encode( $r2 ), 0, 160 ) );
	// The response echoes fq back, so judge the rows, not the whole body.
	$check( 'DB browser: a value search cannot find the trusted-device token', array() === (array) ( $r3['rows'] ?? array( 'missing' ) ) && 0 === (int) ( $r3['total'] ?? -1 ), wp_json_encode( array( $r3['total'] ?? null, count( (array) ( $r3['rows'] ?? array() ) ) ) ) );
	delete_user_meta( $admin, 'tfa_trusted_devices' );
	if ( null === $opt_had ) {
		delete_option( 'wp_mail_smtp_mail_key' );
	}
	// Prefix and gateway rows (fix-delta review OS-7).
	$gs_secret = 'minnv042gs' . wp_rand( 1000, 9999 );
	update_option( 'gravitysmtp_minnprobe', wp_json_encode( array( 'api_key' => $gs_secret ) ), false );
	list( , $r4 ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'fcol' => 'option_name', 'fq' => 'gravitysmtp_minnprobe' ) );
	$check( 'DB browser: a Gravity SMTP connector row renders redacted', false === strpos( wp_json_encode( $r4['rows'] ?? array() ), $gs_secret ), substr( wp_json_encode( $r4['rows'] ?? array() ), 0, 120 ) );
	list( , $r5 ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'fcol' => 'option_value', 'fq' => $gs_secret ) );
	$check( 'DB browser: a value search cannot find a Gravity SMTP key', 0 === (int) ( $r5['total'] ?? -1 ), wp_json_encode( $r5['total'] ?? null ) );
	delete_option( 'gravitysmtp_minnprobe' );
	if ( function_exists( 'WC' ) ) {
		$bacs_was = get_option( 'woocommerce_bacs_settings', null );
		$gw_mark  = 'minnv042gw' . wp_rand( 1000, 9999 );
		update_option( 'woocommerce_bacs_settings', array_merge( (array) $bacs_was, array( 'minn_probe' => $gw_mark ) ) );
		list( , $r6 ) = $call( 'GET', '/minn-admin/v1/db/rows', null, array( 'table' => $wpdb->options, 'fcol' => 'option_name', 'fq' => 'woocommerce_bacs_settings' ) );
		$check( 'DB browser: a payment gateway settings row renders redacted', false === strpos( wp_json_encode( $r6['rows'] ?? array() ), $gw_mark ), substr( wp_json_encode( $r6['rows'] ?? array() ), 0, 120 ) );
		null === $bacs_was ? delete_option( 'woocommerce_bacs_settings' ) : update_option( 'woocommerce_bacs_settings', $bacs_was );
	}
} else {
	$skip( 'DB browser not loaded' );
}

// --- #27 render-blocks previews clamp Latest Posts and every post query ----
$rb_seed = array();
remove_action( 'publish_post', '_publish_post_hook', 5 );
for ( $i = 0; $i < 22; $i++ ) {
	$rb_seed[] = wp_insert_post( array( 'post_title' => 'Minn v042 clamp ' . $i, 'post_status' => 'publish' ) );
}
wp_cache_flush();
$published = (int) wp_count_posts( 'post' )->publish;
if ( $published > 20 ) {
	list( $st, $rb ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => array( '<!-- wp:latest-posts {"postsToShow":-1,"displayPostContent":false} /-->' ) ) );
	$items           = substr_count( (string) ( $rb['rendered'][0] ?? '' ), '<li' );
	$check( 'render-blocks: a Latest Posts preview set to all renders at most 20 items', 200 === $st && $items > 0 && $items <= 20, "{$items} of {$published}" );
	$after_q = new WP_Query( array( 'posts_per_page' => -1, 'fields' => 'ids', 'post_type' => 'post' ) );
	$check( 'render-blocks: the query cap ends with the render (control)', count( $after_q->posts ) === $published, count( $after_q->posts ) . " of {$published}" );
} else {
	$skip( 'fewer than 21 published posts to prove the Latest Posts clamp' );
}
foreach ( $rb_seed as $rb_id ) {
	wp_delete_post( $rb_id, true );
}

// --- #30 The self-updater only installs its own pinned package -------------
if ( class_exists( 'Minn_Admin_Updater' ) ) {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$hdr = get_plugin_data( MINN_ADMIN_DIR . 'minn-admin.php', false, false );
	$check( 'Updater: the plugin header names its Update URI, so wordpress.org is never asked', 'https://github.com/austinginder/minn-admin' === ( $hdr['UpdateURI'] ?? '' ), (string) ( $hdr['UpdateURI'] ?? '' ) );
	$upd = null;
	foreach ( (array) ( $GLOBALS['wp_filter']['upgrader_pre_download']->callbacks ?? array() ) as $cbs ) {
		foreach ( $cbs as $cb ) {
			if ( is_array( $cb['function'] ) && $cb['function'][0] instanceof Minn_Admin_Updater ) {
				$upd = $cb['function'][0];
			}
		}
	}
	if ( $upd ) {
		$foreign = 'https://downloads.wordpress.org/plugin/minn-admin.9.9.9.zip';
		$r       = $upd->verify_package( false, $foreign, null, array( 'plugin' => 'minn-admin/minn-admin.php' ) );
		$check( 'Updater: a foreign package offered for Minn itself is refused before download', is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_code() : var_export( $r, true ) );
		$r2 = $upd->verify_package( false, $foreign, null, array( 'plugin' => 'akismet/akismet.php' ) );
		$check( 'Updater: other plugins\' downloads pass through untouched (control)', false === $r2, var_export( $r2, true ) );
		$t           = new stdClass();
		$t->checked  = array( 'minn-admin/minn-admin.php' => MINN_ADMIN_VERSION );
		$t->response = array( 'minn-admin/minn-admin.php' => (object) array( 'slug' => 'minn-admin', 'new_version' => '9.9.9', 'package' => $foreign ) );
		$t           = $upd->update( $t );
		$left        = $t->response['minn-admin/minn-admin.php'] ?? null;
		$check( 'Updater: a foreign offer for Minn is dropped from the update transient', ! $left || false !== strpos( (string) ( $left->package ?? '' ), 'github.com/austinginder/minn-admin' ), $left ? (string) $left->package : 'removed' );
	} else {
		$skip( 'updater instance not found' );
	}
} else {
	$skip( 'Minn_Admin_Updater not loaded' );
}

// --- #5 Memberships lookups stay on this site (multisite) ------------------
if ( is_multisite() && function_exists( 'minn_admin_wcm_find_user' ) ) {
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}
	$ms_other = wpmu_create_user( 'minnv042other' . wp_rand( 100, 999 ), wp_generate_password(), 'minn-v042-other-' . wp_rand() . '@example.com' );
	remove_user_from_blog( $ms_other, get_current_blog_id() );
	$ms_admin = wpmu_create_user( 'minnv042siteadmin' . wp_rand( 100, 999 ), wp_generate_password(), 'minn-v042-sa-' . wp_rand() . '@example.com' );
	add_user_to_blog( get_current_blog_id(), $ms_admin, 'administrator' );
	wp_set_current_user( $ms_admin );
	$check( 'Memberships (multisite): a site administrator cannot resolve another site\'s account', null === minn_admin_wcm_find_user( get_userdata( $ms_other )->user_email ) );
	wp_set_current_user( $admin );
	wpmu_delete_user( $ms_other );
	wpmu_delete_user( $ms_admin );
}

// --- OS-1 Event details never collapses an Events Calendar Pro series ------
if ( class_exists( 'Tribe__Events__Pro__Main' ) && class_exists( 'Tribe__Events__API' ) ) {
	$rec_e = Tribe__Events__API::createEvent( array( 'post_title' => 'Minn v042 recurring', 'post_status' => 'draft', 'EventStartDate' => '2026-11-10', 'EventStartTime' => '19:00:00', 'EventEndDate' => '2026-11-10', 'EventEndTime' => '21:00:00' ) );
	update_post_meta( $rec_e, '_EventRecurrence', array( 'rules' => array( array( 'type' => 'Every Week', 'custom' => array( 'interval' => 1, 'same-time' => 'yes', 'week' => array( 'day' => array( 2 ) ) ), 'end-type' => 'After', 'end-count' => 4 ) ), 'exclusions' => array(), 'description' => '' ) );
	list( $st ) = $call( 'POST', '/wp/v2/tribe_events/' . $rec_e, array( 'minn_tec' => array( 'cost' => '15', 'start' => '2026-11-10 19:00', 'end' => '2026-11-10 21:00' ) ) );
	$rec_after  = get_post_meta( $rec_e, '_EventRecurrence', true );
	$check( 'Events Calendar Pro: a panel save never deletes a series\' recurrence rules', 200 === $st && is_array( $rec_after ) && ! empty( $rec_after['rules'] ), 'status ' . $st );
	wp_delete_post( $rec_e, true );
} else {
	$skip( 'Events Calendar Pro inactive' );
}

// --- OS-2 / OS-9 WPCode keeps backslashes and the compress-output flag -----
if ( class_exists( 'WPCode_Snippet' ) && function_exists( 'minn_admin_wpcode_guard_type' ) ) {
	$wc_code = "\$x = preg_replace( '/\\d+/', '#', 'a1' ); // \"\\\\\" stays\n";
	list( $st, $made ) = $call( 'POST', '/minn-admin/v1/wpcode/snippets', array( 'name' => 'Minn v042 slash', 'code' => $wc_code, 'code_type' => 'php', 'location' => 'on_demand', 'auto_insert' => true, 'priority' => 10, 'active' => false, 'tags' => array(), 'desc' => '' ) );
	$wc_id             = (int) ( $made['id'] ?? 0 );
	$check( 'WPCode: create keeps the code\'s backslashes', $wc_id && get_post( $wc_id )->post_content === $wc_code, $wc_id ? get_post( $wc_id )->post_content : 'status ' . $st );
	if ( $wc_id ) {
		update_post_meta( $wc_id, '_wpcode_compress_output', true );
		$call( 'PUT', '/minn-admin/v1/wpcode/snippets/' . $wc_id, array( 'priority' => 11 ) );
		clean_post_cache( $wc_id );
		$check( 'WPCode: a priority-only edit leaves the stored code byte for byte', get_post( $wc_id )->post_content === $wc_code, get_post( $wc_id )->post_content );
		$check( 'WPCode: an edit keeps the compress-output flag', (bool) get_post_meta( $wc_id, '_wpcode_compress_output', true ) );
		wp_delete_post( $wc_id, true );
	}
	// An ACTIVE snippet with quotes: WPCode test-runs it on save and must see
	// the code unslashed (round-2 review R2-1), and still refuse broken code.
	$q_code = '$minn_v042_q = "ok"; // it\'s preg_match( \'/\d+/\', "1" );';
	list( , $qa ) = $call( 'POST', '/minn-admin/v1/wpcode/snippets', array( 'name' => 'Minn v042 active quotes', 'code' => $q_code, 'code_type' => 'php', 'location' => 'on_demand', 'auto_insert' => true, 'priority' => 10, 'active' => true, 'tags' => array(), 'desc' => '' ) );
	$q_id         = (int) ( $qa['id'] ?? 0 );
	$check( 'WPCode: an active snippet with quotes is created active', $q_id && 'publish' === get_post_status( $q_id ), $q_id ? get_post_status( $q_id ) : 'no id' );
	if ( $q_id ) {
		$call( 'PUT', '/minn-admin/v1/wpcode/snippets/' . $q_id, array( 'priority' => 12 ) );
		clean_post_cache( $q_id );
		$check( 'WPCode: a priority edit keeps an active quoted snippet active and byte for byte', 'publish' === get_post_status( $q_id ) && get_post( $q_id )->post_content === $q_code, get_post_status( $q_id ) );
		wp_delete_post( $q_id, true );
	}
	list( , $qb ) = $call( 'POST', '/minn-admin/v1/wpcode/snippets', array( 'name' => 'Minn v042 broken', 'code' => '$minn_v042_broken = ;', 'code_type' => 'php', 'location' => 'on_demand', 'auto_insert' => true, 'priority' => 10, 'active' => true, 'tags' => array(), 'desc' => '' ) );
	$b_id         = (int) ( $qb['id'] ?? 0 );
	$check( 'WPCode: broken code is still refused activation (control)', ! $b_id || 'publish' !== get_post_status( $b_id ), $b_id ? get_post_status( $b_id ) : 'refused' );
	if ( $b_id ) {
		wp_delete_post( $b_id, true );
	}
} else {
	$skip( 'WPCode inactive' );
}

// --- Round-2 siblings: TEC link check, ACPT colon choices, GF subject, WPForms spam
if ( class_exists( 'Tribe__Events__API' ) && function_exists( 'minn_admin_tec_active' ) && minn_admin_tec_active() ) {
	$page_priv = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Minn v042 private page' ) );
	$ev        = Tribe__Events__API::createEvent( array( 'post_title' => 'Minn v042 link check', 'post_status' => 'draft', 'EventStartDate' => '2026-11-10', 'EventStartTime' => '19:00:00', 'EventEndDate' => '2026-11-10', 'EventEndTime' => '21:00:00' ) );
	list( $st ) = $call( 'POST', '/wp/v2/tribe_events/' . $ev, array( 'minn_tec' => array( 'venue' => array( 'value' => (string) $page_priv, 'label' => 'x' ) ) ) );
	$check( 'The Events Calendar: a page id is refused as a venue', 400 === $st && ! get_post_meta( $ev, '_EventVenueID', true ), 'status ' . $st );
	wp_delete_post( $ev, true );
	wp_delete_post( $page_priv, true );
}
if ( function_exists( 'minn_admin_acpt_builder_choices_in' ) ) {
	$acpt_c = minn_admin_acpt_builder_choices_in( "16:9 : Widescreen\n4:3 : Standard", array() );
	$check( 'ACPT builder: a choice value holding a colon is not split', '16:9' === ( $acpt_c[0]['value'] ?? '' ) && 'Widescreen' === ( $acpt_c[0]['label'] ?? '' ), wp_json_encode( $acpt_c[0] ?? null ) );
}
if ( class_exists( 'GFAPI' ) ) {
	$gf2 = GFAPI::get_form( 1 );
	if ( $gf2 && ! empty( $gf2['notifications']['minnfixuser00002'] ) ) {
		$gf2_was = $gf2['notifications']['minnfixuser00002'];
		$gf2['notifications']['minnfixuser00002']['subject'] = 'Save 50%AB today';
		GFAPI::update_form( $gf2 );
		$call( 'POST', '/minn-admin/v1/gf/notifications/1:minnfixuser00002', array( 'name' => $gf2_was['name'], 'to_email' => '', 'subject' => 'Save 50%AB today', 'message' => (string) ( $gf2_was['message'] ?? '' ) ) );
		$check( 'Gravity Forms: an untouched subject keeps its %AB', 'Save 50%AB today' === GFAPI::get_form( 1 )['notifications']['minnfixuser00002']['subject'] );
		$r = GFAPI::get_form( 1 );
		$r['notifications']['minnfixuser00002'] = $gf2_was;
		GFAPI::update_form( $r );
	}
}
if ( function_exists( 'wpforms' ) && function_exists( 'minn_admin_wpforms_table' ) ) {
	global $wpdb;
	$wt  = minn_admin_wpforms_table();
	$wr  = $wpdb->get_row( "SELECT entry_id, status FROM {$wt} ORDER BY entry_id DESC LIMIT 1" );
	if ( $wr ) {
		$wid = (int) $wr->entry_id;
		$wpdb->update( $wt, array( 'status' => '' ), array( 'entry_id' => $wid ) );
		$call( 'POST', '/minn-admin/v1/wpforms/entries/' . $wid . '/status', array( 'status' => 'spam' ) );
		$reason = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpforms_entry_meta WHERE entry_id=%d AND type='spam'", $wid ) );
		$call( 'POST', '/minn-admin/v1/wpforms/entries/' . $wid . '/status', array( 'status' => 'restore' ) );
		$check( 'WPForms: spam and not-spam go through WPForms\' own bookkeeping', $reason >= 1 && '' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wt} WHERE entry_id=%d", $wid ) ), (string) $reason );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wpforms_entry_meta WHERE entry_id=%d AND type IN ('spam','log')", $wid ) );
		$wpdb->update( $wt, array( 'status' => (string) $wr->status ), array( 'entry_id' => $wid ) );
	}
}

$summary();
