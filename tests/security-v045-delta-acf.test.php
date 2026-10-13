<?php
/**
 * Regressions for the v0.45.0 fix-delta review, ACF data integrity.
 *
 * Each section replays what Minn's client sends through the route it calls,
 * and checks what ACF stored, with a control where a fix could over-block:
 * NI-1 (a new repeater row or flexible section inherits the stored values of
 * the row that sat at its index). Sections SKIP when ACF PRO is inactive.
 *
 * Run: wp eval-file tests/security-v045-delta-acf.test.php --user=admin --path=<site>
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
	printf( "\nsecurity-v045-delta-acf: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
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

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );

if ( ! function_exists( 'acf_add_local_field_group' ) || ! defined( 'ACF_PRO' ) || ! function_exists( 'acf_add_options_page' ) ) {
	$skip( 'ACF PRO inactive' );
	$summary();
}

// Leftovers from an interrupted run, swept by title, login, slug and name.
global $wpdb;
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE 'MinnD1 %'" ) as $old_pid ) {
	if ( (int) $old_pid > 1 ) {
		wp_delete_post( (int) $old_pid, true );
	}
}
foreach ( $wpdb->get_col( "SELECT ID FROM {$wpdb->users} WHERE user_login LIKE 'minn-d1-%'" ) as $old_uid ) {
	if ( (int) $old_uid > 1 ) {
		$drop_user( (int) $old_uid );
	}
}
foreach ( array( 'minn-d1-alpha', 'minn-d1-beta', 'minn-d1-gamma' ) as $old_slug ) {
	$old_term = get_term_by( 'slug', $old_slug, 'post_tag' );
	if ( $old_term && (int) $old_term->term_id > 1 ) {
		wp_delete_term( (int) $old_term->term_id, 'post_tag' );
	}
}
$drop_opts = function () use ( $wpdb ) {
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'minn\\_d1\\_opts\\_%' OR option_name LIKE '\\_minn\\_d1\\_opts\\_%'" );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};
$drop_opts();

$post_location = array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) );
$make_post     = function ( $title, $author ) {
	$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'MinnD1 ' . $title, 'post_status' => 'draft', 'post_author' => $author ) );
	return is_int( $id ) && $id > 1 ? $id : 0;
};
// Stored rows written the way ACF stores them: each sub under rep_i_name,
// with its field-key reference beside it.
$seed = function ( $pid, $name, $key, $rows, $keys ) {
	update_post_meta( $pid, $name, is_array( reset( $rows ) ) && isset( reset( $rows )['acf_fc_layout'] ) ? wp_list_pluck( $rows, 'acf_fc_layout' ) : count( $rows ) );
	update_post_meta( $pid, '_' . $name, $key );
	foreach ( array_values( $rows ) as $i => $row ) {
		foreach ( $row as $sub => $v ) {
			if ( 'acf_fc_layout' === $sub ) {
				continue;
			}
			update_post_meta( $pid, "{$name}_{$i}_{$sub}", $v );
			update_post_meta( $pid, "_{$name}_{$i}_{$sub}", $keys[ $sub ] );
		}
	}
};
$panel = function ( $pid ) use ( $call ) {
	list( , $d ) = $call( 'GET', "/wp/v2/posts/{$pid}", null, array( 'context' => 'edit' ) );
	return is_array( $d['minn_acf'] ?? null ) ? $d['minn_acf'] : array();
};
$save = function ( $pid, $values ) use ( $call ) {
	list( $st ) = $call( 'POST', "/wp/v2/posts/{$pid}", array( 'minn_acf' => $values ) );
	return $st;
};
// The fieldsRoute payload's group by title: { names, locked }.
$payload_group = function ( $pid, $title ) use ( $call ) {
	list( , $d ) = $call( 'GET', '/minn-admin/v1/acf/fields', null, array( 'post_id' => $pid, 'post_type' => 'post' ) );
	foreach ( (array) ( $d['groups'] ?? array() ) as $g ) {
		if ( $title === ( $g['group'] ?? '' ) ) {
			return array( 'names' => wp_list_pluck( (array) $g['fields'], 'name' ), 'locked' => (int) $g['locked'] );
		}
	}
	return array( 'names' => array(), 'locked' => 0 );
};
$meta = function ( $pid, $key ) {
	wp_cache_delete( $pid, 'post_meta' );
	return get_post_meta( $pid, $key, true );
};
$link_url = function ( $v ) {
	return is_array( $v ) ? (string) ( $v['url'] ?? '' ) : (string) $v;
};

$contrib = wp_insert_user(
	array(
		'user_login' => 'minn-d1-contrib-' . wp_generate_password( 6, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => 'minn-d1-contrib-' . wp_generate_password( 6, false ) . '@example.com',
		'role'       => 'contributor',
	)
);
$contrib = is_int( $contrib ) && $contrib > 1 ? $contrib : 0;
if ( ! $contrib ) {
	$check( 'fixture: a contributor', false );
	$summary();
}

// --- NI-1 a new repeater row or flexible section starts blank ---------------
// ACF's update_row() writes only the subs a row carries. A new row's blanks
// read as "sent back as served" and were left out, so the row kept what the
// row that last sat at its index stored, and so did every sub Minn does not
// show and every value the parser refused.
acf_add_local_field_group( array(
	'key'      => 'group_minn_d1_rows',
	'title'    => 'MinnD1 rows',
	'fields'   => array(
		array(
			'key'        => 'field_minn_d1_team',
			'name'       => 'minn_d1_team',
			'label'      => 'Team',
			'type'       => 'repeater',
			'sub_fields' => array(
				array( 'key' => 'field_minn_d1_name', 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
				array( 'key' => 'field_minn_d1_site', 'name' => 'site', 'label' => 'Site', 'type' => 'url' ),
				array( 'key' => 'field_minn_d1_featured', 'name' => 'featured', 'label' => 'Featured', 'type' => 'true_false' ),
				array( 'key' => 'field_minn_d1_cta', 'name' => 'cta', 'label' => 'CTA', 'type' => 'link' ),
				array( 'key' => 'field_minn_d1_when', 'name' => 'when', 'label' => 'When', 'type' => 'date_picker' ),
				array( 'key' => 'field_minn_d1_bio', 'name' => 'bio', 'label' => 'Bio', 'type' => 'wysiwyg' ),
			),
		),
		array(
			'key'     => 'field_minn_d1_fx',
			'name'    => 'minn_d1_fx',
			'label'   => 'Sections',
			'type'    => 'flexible_content',
			'layouts' => array(
				'layout_minn_d1_hero' => array(
					'key'        => 'layout_minn_d1_hero',
					'name'       => 'hero',
					'label'      => 'Hero',
					'display'    => 'block',
					'sub_fields' => array(
						array( 'key' => 'field_minn_d1_hero_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
						array( 'key' => 'field_minn_d1_hero_on', 'name' => 'on', 'label' => 'On', 'type' => 'true_false' ),
						array( 'key' => 'field_minn_d1_hero_site', 'name' => 'site', 'label' => 'Site', 'type' => 'url' ),
						array( 'key' => 'field_minn_d1_hero_body', 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg' ),
					),
				),
			),
		),
	),
	'location' => $post_location,
) );
$team_keys = array( 'name' => 'field_minn_d1_name', 'site' => 'field_minn_d1_site', 'featured' => 'field_minn_d1_featured', 'cta' => 'field_minn_d1_cta', 'when' => 'field_minn_d1_when', 'bio' => 'field_minn_d1_bio' );
$team_rows = array(
	array( 'name' => 'A', 'site' => 'https://a.example/', 'featured' => 1, 'cta' => array( 'title' => 'A cta', 'url' => 'https://a-cta.example/', 'target' => '' ), 'when' => '20260101', 'bio' => 'A bio' ),
	// An old-shape link and a date the picker cannot show: 06-01's case.
	array( 'name' => 'B', 'site' => 'https://b.example/', 'featured' => 1, 'cta' => 'https://b-old.example/', 'when' => '2026/02/03', 'bio' => 'B bio' ),
);
// What the client seeds a row it adds with (app.js), plus the name typed.
$blank_row = function ( $name ) {
	return array( 'values' => array( 'name' => $name, 'site' => '', 'featured' => false, 'cta' => '', 'when' => '' ) );
};
$is_blank = function ( $pid, $i, $label ) use ( $meta, $link_url, $check ) {
	$got = array();
	foreach ( array( 'site', 'featured', 'cta', 'when', 'bio' ) as $sub ) {
		$v = $meta( $pid, "minn_d1_team_{$i}_{$sub}" );
		if ( 'cta' === $sub ? '' !== $link_url( $v ) : ! empty( $v ) ) {
			$got[ $sub ] = $v;
		}
	}
	$check( $label, ! $got, wp_json_encode( $got ) );
};

// 1. A row added at the top, as a Contributor on their own draft.
$n1_pid = $make_post( 'rows top', $contrib );
if ( ! $n1_pid ) {
	$check( 'NI-1 fixture', false );
} else {
	$seed( $n1_pid, 'minn_d1_team', 'field_minn_d1_team', $team_rows, $team_keys );
	wp_set_current_user( $contrib );
	$n1_rows = $panel( $n1_pid )['minn_d1_team'] ?? null;
	if ( ! is_array( $n1_rows ) || 2 !== count( $n1_rows ) ) {
		$check( 'NI-1 the panel serves the repeater', false, wp_json_encode( $n1_rows ) );
	} else {
		$n1_st = $save( $n1_pid, array( 'minn_d1_team' => array( $blank_row( 'C' ), $n1_rows[0], $n1_rows[1] ) ) );
		$check( 'NI-1 a row added at the top saves its name', 200 === $n1_st && 'C' === $meta( $n1_pid, 'minn_d1_team_0_name' ) && '3' === (string) $meta( $n1_pid, 'minn_d1_team' ), $n1_st . ' ' . wp_json_encode( $meta( $n1_pid, 'minn_d1_team_0_name' ) ) );
		$is_blank( $n1_pid, 0, 'NI-1 a row added at the top does not take the first row\'s link, flag, date, URL or bio' );
		$check( 'NI-1 control (06-01): the row moved down keeps every value', 'A' === $meta( $n1_pid, 'minn_d1_team_1_name' ) && 'https://a.example/' === $meta( $n1_pid, 'minn_d1_team_1_site' ) && '1' === (string) $meta( $n1_pid, 'minn_d1_team_1_featured' ) && 'https://a-cta.example/' === $link_url( $meta( $n1_pid, 'minn_d1_team_1_cta' ) ) && '20260101' === $meta( $n1_pid, 'minn_d1_team_1_when' ) && 'A bio' === $meta( $n1_pid, 'minn_d1_team_1_bio' ), wp_json_encode( array( $meta( $n1_pid, 'minn_d1_team_1_site' ), $meta( $n1_pid, 'minn_d1_team_1_cta' ), $meta( $n1_pid, 'minn_d1_team_1_when' ) ) ) );
		$check( 'NI-1 control (06-01): an untouched old-shape link and a stored date stay as stored', 'https://b-old.example/' === $link_url( $meta( $n1_pid, 'minn_d1_team_2_cta' ) ) && '2026/02/03' === $meta( $n1_pid, 'minn_d1_team_2_when' ), wp_json_encode( array( $meta( $n1_pid, 'minn_d1_team_2_cta' ), $meta( $n1_pid, 'minn_d1_team_2_when' ) ) ) );
	}
	wp_set_current_user( $admin );
	wp_delete_post( $n1_pid, true );
}

// 2. One row deleted and one added in the same save, as an administrator.
$n2_pid = $make_post( 'rows swap', $admin );
if ( ! $n2_pid ) {
	$check( 'NI-1 fixture (swap)', false );
} else {
	$seed( $n2_pid, 'minn_d1_team', 'field_minn_d1_team', $team_rows, $team_keys );
	$n2_rows = $panel( $n2_pid )['minn_d1_team'] ?? null;
	if ( ! is_array( $n2_rows ) || 2 !== count( $n2_rows ) ) {
		$check( 'NI-1 the panel serves the repeater (swap)', false, wp_json_encode( $n2_rows ) );
	} else {
		$n2_st = $save( $n2_pid, array( 'minn_d1_team' => array( $n2_rows[0], $blank_row( 'D' ) ) ) );
		$check( 'NI-1 a row added where one was deleted saves its name', 200 === $n2_st && 'D' === $meta( $n2_pid, 'minn_d1_team_1_name' ) && '2' === (string) $meta( $n2_pid, 'minn_d1_team' ), $n2_st . ' ' . wp_json_encode( $meta( $n2_pid, 'minn_d1_team_1_name' ) ) );
		$is_blank( $n2_pid, 1, 'NI-1 a row added where one was deleted does not take the deleted row\'s values' );
		$check( 'NI-1 control: the kept row is unchanged', 'A' === $meta( $n2_pid, 'minn_d1_team_0_name' ) && 'https://a-cta.example/' === $link_url( $meta( $n2_pid, 'minn_d1_team_0_cta' ) ) );
	}
	wp_delete_post( $n2_pid, true );
}

// 3. A refused value in a new row (a script URL) is no value, not the old row's.
$n3_pid = $make_post( 'rows refused', $contrib );
if ( ! $n3_pid ) {
	$check( 'NI-1 fixture (refused)', false );
} else {
	$seed( $n3_pid, 'minn_d1_team', 'field_minn_d1_team', array( $team_rows[0] ), $team_keys );
	wp_set_current_user( $contrib );
	$n3_rows = $panel( $n3_pid )['minn_d1_team'] ?? array();
	$n3_new  = $blank_row( 'E' );
	$n3_new['values']['site'] = 'javascript:alert(1)';
	$n3_st = $save( $n3_pid, array( 'minn_d1_team' => array( $n3_new, $n3_rows[0] ?? array() ) ) );
	$check( 'NI-1 a refused URL in a new row stores no URL, not the first row\'s', 200 === $n3_st && '' === (string) $meta( $n3_pid, 'minn_d1_team_0_site' ) && 'https://a.example/' === $meta( $n3_pid, 'minn_d1_team_1_site' ), $n3_st . ' ' . wp_json_encode( $meta( $n3_pid, 'minn_d1_team_0_site' ) ) );
	wp_set_current_user( $admin );
	wp_delete_post( $n3_pid, true );
}

// 4. A new flexible section of the layout already stored at its index.
$n4_pid = $make_post( 'flex new', $contrib );
if ( ! $n4_pid ) {
	$check( 'NI-1 fixture (flex)', false );
} else {
	$seed( $n4_pid, 'minn_d1_fx', 'field_minn_d1_fx', array( array( 'acf_fc_layout' => 'hero', 'title' => 'H1', 'on' => 1, 'site' => 'https://h.example/', 'body' => 'H body' ) ), array( 'title' => 'field_minn_d1_hero_title', 'on' => 'field_minn_d1_hero_on', 'site' => 'field_minn_d1_hero_site', 'body' => 'field_minn_d1_hero_body' ) );
	wp_set_current_user( $contrib );
	$n4_rows = $panel( $n4_pid )['minn_d1_fx'] ?? null;
	if ( ! is_array( $n4_rows ) || 1 !== count( $n4_rows ) ) {
		$check( 'NI-1 the panel serves the flexible field', false, wp_json_encode( $n4_rows ) );
	} else {
		$n4_st = $save( $n4_pid, array( 'minn_d1_fx' => array( array( '__layout' => 'hero', 'values' => array( 'title' => 'N', 'on' => false, 'site' => '' ) ), $n4_rows[0] ) ) );
		$n4_got = array( $meta( $n4_pid, 'minn_d1_fx_0_title' ), $meta( $n4_pid, 'minn_d1_fx_0_on' ), $meta( $n4_pid, 'minn_d1_fx_0_site' ), $meta( $n4_pid, 'minn_d1_fx_0_body' ) );
		$check( 'NI-1 a new section of a stored layout does not take the old section\'s flag, URL or body', 200 === $n4_st && 'N' === $n4_got[0] && empty( $n4_got[1] ) && '' === (string) $n4_got[2] && '' === (string) $n4_got[3], $n4_st . ' ' . wp_json_encode( $n4_got ) );
		$check( 'NI-1 control: the section moved down keeps its values', 'H1' === $meta( $n4_pid, 'minn_d1_fx_1_title' ) && 'https://h.example/' === $meta( $n4_pid, 'minn_d1_fx_1_site' ) && 'H body' === $meta( $n4_pid, 'minn_d1_fx_1_body' ) );
	}
	wp_set_current_user( $admin );
	wp_delete_post( $n4_pid, true );
}
acf_remove_local_field_group( 'group_minn_d1_rows' );

$drop_user( $contrib );

$summary();
