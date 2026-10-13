<?php
/**
 * Regressions for the v0.45.0 fix-delta review, ACF data integrity.
 *
 * Each section replays what Minn's client sends through the route it calls,
 * and checks what ACF stored, with a control where a fix could over-block:
 * NI-1 (a new repeater row or flexible section inherits the stored values of
 * the row that sat at its index), OS-1 (disabled sections and renamed labels
 * lost to a flexible-content write), r2 / OS-13 / S1 (fields from a seamless
 * clone), OS-15 (a term-saving taxonomy field in rows) and, from the second
 * review round, A1 (a new row's defaults) and A2 (the OS-1 lock on a
 * multilingual options page). Sections SKIP when ACF PRO is inactive.
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
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'minn\\_d1\\_opts\\_%' OR option_name LIKE '\\_minn\\_d1\\_opts\\_%'" ) as $name ) {
		delete_option( $name );
	}
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

// --- A1 a new row starts at each sub's default ------------------------------
// ACF's own new-row form posts every sub at its default_value, and a row
// appended before NI-1 left them absent, so ACF's read fell back to it. A
// sub Minn does not show (rich text here) takes the default; a sub it shows
// takes what the client sends, the default it was seeded with or a blank.
acf_add_local_field_group( array(
	'key'      => 'group_minn_d1_def',
	'title'    => 'MinnD1 defaults',
	'fields'   => array(
		array(
			'key'        => 'field_minn_d1_cards',
			'name'       => 'minn_d1_cards',
			'label'      => 'Cards',
			'type'       => 'repeater',
			'sub_fields' => array(
				array( 'key' => 'field_minn_d1_c_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array( 'key' => 'field_minn_d1_c_btn', 'name' => 'btn', 'label' => 'Button', 'type' => 'text', 'default_value' => 'Learn more' ),
				array( 'key' => 'field_minn_d1_c_tone', 'name' => 'tone', 'label' => 'Tone', 'type' => 'select', 'choices' => array( 'red' => 'Red', 'blue' => 'Blue' ), 'default_value' => 'blue' ),
				array( 'key' => 'field_minn_d1_c_on', 'name' => 'on', 'label' => 'On', 'type' => 'true_false', 'default_value' => 1 ),
				array( 'key' => 'field_minn_d1_c_note', 'name' => 'note', 'label' => 'Note', 'type' => 'wysiwyg', 'default_value' => 'Default note' ),
				// No default, in the shapes ACF stores it in.
				array( 'key' => 'field_minn_d1_c_kind', 'name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'choices' => array( 'a' => 'A', 'b' => 'B' ), 'default_value' => false ),
				array( 'key' => 'field_minn_d1_c_picks', 'name' => 'picks', 'label' => 'Picks', 'type' => 'select', 'multiple' => 1, 'choices' => array( 'a' => 'A', 'b' => 'B' ), 'default_value' => array() ),
				array( 'key' => 'field_minn_d1_c_flag', 'name' => 'flag', 'label' => 'Flag', 'type' => 'true_false', 'default_value' => 0 ),
			),
		),
		array(
			'key'     => 'field_minn_d1_dfx',
			'name'    => 'minn_d1_dfx',
			'label'   => 'Default sections',
			'type'    => 'flexible_content',
			'layouts' => array(
				'layout_minn_d1_d_card' => array(
					'key'        => 'layout_minn_d1_d_card',
					'name'       => 'card',
					'label'      => 'Card',
					'display'    => 'block',
					'sub_fields' => array(
						array( 'key' => 'field_minn_d1_d_head', 'name' => 'head', 'label' => 'Head', 'type' => 'text' ),
						array( 'key' => 'field_minn_d1_d_body', 'name' => 'body', 'label' => 'Body', 'type' => 'wysiwyg', 'default_value' => 'Body default' ),
					),
				),
			),
		),
	),
	'location' => $post_location,
) );
$a1_pid = $make_post( 'defaults', $contrib );
if ( ! $a1_pid ) {
	$check( 'A1 fixture', false );
} else {
	$seed( $a1_pid, 'minn_d1_cards', 'field_minn_d1_cards', array( array( 'title' => 'A', 'btn' => 'Go', 'tone' => 'red', 'on' => 0, 'note' => 'A note' ) ), array( 'title' => 'field_minn_d1_c_title', 'btn' => 'field_minn_d1_c_btn', 'tone' => 'field_minn_d1_c_tone', 'on' => 'field_minn_d1_c_on', 'note' => 'field_minn_d1_c_note' ) );
	wp_set_current_user( $contrib );
	list( , $a1_payload ) = $call( 'GET', '/minn-admin/v1/acf/fields', null, array( 'post_id' => $a1_pid, 'post_type' => 'post' ) );
	$a1_subs = array();
	foreach ( (array) ( $a1_payload['groups'] ?? array() ) as $a1_g ) {
		foreach ( (array) ( $a1_g['fields'] ?? array() ) as $a1_f ) {
			if ( 'minn_d1_cards' === ( $a1_f['name'] ?? '' ) ) {
				foreach ( (array) $a1_f['subfields'] as $a1_sf ) {
					$a1_subs[ $a1_sf['name'] ] = $a1_sf;
				}
			}
		}
	}
	$check( 'A1 the panel ships each shown sub\'s default for the client to seed a new row with', 'Learn more' === ( $a1_subs['btn']['default'] ?? null ) && 'blue' === ( $a1_subs['tone']['default'] ?? null ) && true === ( $a1_subs['on']['default'] ?? null ) && ! array_key_exists( 'default', $a1_subs['title'] ?? array() ), wp_json_encode( $a1_subs ) );
	$a1_none = array();
	foreach ( array( 'kind', 'picks', 'flag' ) as $a1_n ) {
		if ( ! isset( $a1_subs[ $a1_n ] ) || array_key_exists( 'default', $a1_subs[ $a1_n ] ) ) {
			$a1_none[ $a1_n ] = $a1_subs[ $a1_n ] ?? 'not served';
		}
	}
	$check( 'A1 a sub whose default means none (false, an empty list, an unticked toggle) ships no default', ! $a1_none, wp_json_encode( $a1_none ) );
	// What a new row hands ACF for the sub with no default.
	$a1_sent    = array();
	$a1_capture = function ( $v, $post_id, $field ) use ( &$a1_sent ) {
		$a1_sent[ $field['name'] ] = $v;
		return $v;
	};
	add_filter( 'acf/update_value/key=field_minn_d1_c_kind', $a1_capture, 10, 3 );
	$a1_rows = $panel( $a1_pid )['minn_d1_cards'] ?? null;
	if ( ! is_array( $a1_rows ) || 1 !== count( $a1_rows ) ) {
		$check( 'A1 the panel serves the repeater', false, wp_json_encode( $a1_rows ) );
	} else {
		$a1_st = $save(
			$a1_pid,
			array(
				'minn_d1_cards' => array(
					// A client that seeds blanks, the person leaving them blank.
					array( 'values' => array( 'title' => 'C', 'btn' => '', 'tone' => '', 'on' => false, 'kind' => '', 'picks' => '', 'flag' => false ) ),
					// A client that seeds the shipped defaults.
					array( 'values' => array( 'title' => 'D', 'btn' => 'Learn more', 'tone' => 'blue', 'on' => true ) ),
					$a1_rows[0],
				),
			)
		);
		$check( 'A1 a new row seeds a sub with no default as empty, not false', array( 'minn_d1_cards_0_kind' => '', 'minn_d1_cards_1_kind' => '' ) === array_intersect_key( $a1_sent, array( 'minn_d1_cards_0_kind' => 1, 'minn_d1_cards_1_kind' => 1 ) ), wp_json_encode( $a1_sent ) );
		$check( 'A1 a new row stores the default of a sub Minn does not show', 200 === $a1_st && 'Default note' === $meta( $a1_pid, 'minn_d1_cards_0_note' ) && 'Default note' === $meta( $a1_pid, 'minn_d1_cards_1_note' ), $a1_st . ' ' . wp_json_encode( array( $meta( $a1_pid, 'minn_d1_cards_0_note' ), $meta( $a1_pid, 'minn_d1_cards_1_note' ) ) ) );
		$check( 'A1 control: a shown sub sent blank stores the blank', '' === (string) $meta( $a1_pid, 'minn_d1_cards_0_btn' ) && '' === (string) $meta( $a1_pid, 'minn_d1_cards_0_tone' ) && empty( $meta( $a1_pid, 'minn_d1_cards_0_on' ) ), wp_json_encode( array( $meta( $a1_pid, 'minn_d1_cards_0_btn' ), $meta( $a1_pid, 'minn_d1_cards_0_tone' ), $meta( $a1_pid, 'minn_d1_cards_0_on' ) ) ) );
		$check( 'A1 control: a shown sub sent at its default stores the default', 'Learn more' === $meta( $a1_pid, 'minn_d1_cards_1_btn' ) && 'blue' === $meta( $a1_pid, 'minn_d1_cards_1_tone' ) && '1' === (string) $meta( $a1_pid, 'minn_d1_cards_1_on' ), wp_json_encode( array( $meta( $a1_pid, 'minn_d1_cards_1_btn' ), $meta( $a1_pid, 'minn_d1_cards_1_tone' ), $meta( $a1_pid, 'minn_d1_cards_1_on' ) ) ) );
		$check( 'A1 control: the stored row keeps its values', 'A' === $meta( $a1_pid, 'minn_d1_cards_2_title' ) && 'Go' === $meta( $a1_pid, 'minn_d1_cards_2_btn' ) && 'red' === $meta( $a1_pid, 'minn_d1_cards_2_tone' ) && '0' === (string) $meta( $a1_pid, 'minn_d1_cards_2_on' ) && 'A note' === $meta( $a1_pid, 'minn_d1_cards_2_note' ) );
	}
	remove_filter( 'acf/update_value/key=field_minn_d1_c_kind', $a1_capture, 10 );
	$a1_fst = $save( $a1_pid, array( 'minn_d1_dfx' => array( array( '__layout' => 'card', 'values' => array( 'head' => 'H' ) ) ) ) );
	$check( 'A1 a new flexible section stores the default of a sub Minn does not show', 200 === $a1_fst && 'H' === $meta( $a1_pid, 'minn_d1_dfx_0_head' ) && 'Body default' === $meta( $a1_pid, 'minn_d1_dfx_0_body' ), $a1_fst . ' ' . wp_json_encode( $meta( $a1_pid, 'minn_d1_dfx_0_body' ) ) );
	wp_set_current_user( $admin );
	wp_delete_post( $a1_pid, true );
}
acf_remove_local_field_group( 'group_minn_d1_def' );

// --- OS-1 disabled sections and renamed labels stay in wp-admin -------------
// ACF 6.5+ keeps them in `_{name}_layout_meta`. Its flexible field drops a
// disabled section from every read outside wp-admin, and its update_value
// rebuilds that meta from the rows it is given, so a write from Minn deleted
// the section and cleared every label, at any depth.
acf_add_local_field_group( array(
	'key'      => 'group_minn_d1_flex',
	'title'    => 'MinnD1 flex',
	'fields'   => array(
		array( 'key' => 'field_minn_d1_note', 'name' => 'minn_d1_note', 'label' => 'Note', 'type' => 'text' ),
		array(
			'key'     => 'field_minn_d1_page',
			'name'    => 'minn_d1_page',
			'label'   => 'Page',
			'type'    => 'flexible_content',
			'layouts' => array(
				'layout_minn_d1_p_hero' => array( 'key' => 'layout_minn_d1_p_hero', 'name' => 'hero', 'label' => 'Hero', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_p_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ) ) ),
				'layout_minn_d1_p_cta'  => array( 'key' => 'layout_minn_d1_p_cta', 'name' => 'cta', 'label' => 'CTA', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_p_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text' ) ) ),
				'layout_minn_d1_p_text' => array( 'key' => 'layout_minn_d1_p_text', 'name' => 'text', 'label' => 'Text', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_p_body', 'name' => 'body', 'label' => 'Body', 'type' => 'textarea' ) ) ),
			),
		),
		array(
			'key'        => 'field_minn_d1_wrap',
			'name'       => 'minn_d1_wrap',
			'label'      => 'Wrap',
			'type'       => 'repeater',
			'sub_fields' => array(
				array( 'key' => 'field_minn_d1_w_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
				array(
					'key'     => 'field_minn_d1_w_inner',
					'name'    => 'inner',
					'label'   => 'Inner',
					'type'    => 'flexible_content',
					'layouts' => array(
						'layout_minn_d1_w_a' => array( 'key' => 'layout_minn_d1_w_a', 'name' => 'a', 'label' => 'A', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_w_at', 'name' => 't', 'label' => 'T', 'type' => 'text' ) ) ),
						'layout_minn_d1_w_b' => array( 'key' => 'layout_minn_d1_w_b', 'name' => 'b', 'label' => 'B', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_w_bt', 'name' => 't', 'label' => 'T', 'type' => 'text' ) ) ),
					),
				),
			),
		),
	),
	'location' => $post_location,
) );
$layout_meta = function ( $pid, $name ) {
	wp_cache_delete( $pid, 'post_meta' );
	$m = acf_get_metadata_by_field( $pid, array( 'name' => '_' . $name . '_layout_meta' ) );
	return is_array( $m ) ? $m : array();
};
// Disabled and renamed through ACF's own update_value, as its screen saves them.
$o1_pid = $make_post( 'flex meta', $contrib );
if ( ! $o1_pid ) {
	$check( 'OS-1 fixture', false );
} else {
	update_field( 'field_minn_d1_note', 'Note', $o1_pid );
	update_field(
		'field_minn_d1_page',
		array(
			array( 'acf_fc_layout' => 'hero', 'field_minn_d1_p_title' => 'Hero' ),
			array( 'acf_fc_layout' => 'cta', 'field_minn_d1_p_label' => 'Buy', 'acf_fc_layout_disabled' => 1 ),
			array( 'acf_fc_layout' => 'text', 'field_minn_d1_p_body' => 'Body', 'acf_fc_layout_custom_label' => 'Intro text' ),
		),
		$o1_pid
	);
	update_field(
		'field_minn_d1_wrap',
		array(
			array(
				'field_minn_d1_w_title' => 'W',
				'field_minn_d1_w_inner' => array(
					array( 'acf_fc_layout' => 'a', 'field_minn_d1_w_at' => 'A0', 'acf_fc_layout_disabled' => 1 ),
					array( 'acf_fc_layout' => 'b', 'field_minn_d1_w_bt' => 'B1' ),
				),
			),
		),
		$o1_pid
	);
	$o1_meta  = $layout_meta( $o1_pid, 'minn_d1_page' );
	$o1_inner = $layout_meta( $o1_pid, 'minn_d1_wrap_0_inner' );
	if ( array( 1 ) !== ( $o1_meta['disabled'] ?? null ) || 'Intro text' !== ( $o1_meta['renamed'][2] ?? '' ) || array( 0 ) !== ( $o1_inner['disabled'] ?? null ) ) {
		$check( 'OS-1 fixture: ACF stored the layout meta', false, wp_json_encode( array( $o1_meta, $o1_inner ) ) );
	} else {
		wp_set_current_user( $contrib );
		// What the client sends after editing the text section: the read it
		// was served leaves the disabled section out.
		$o1_st = $save(
			$o1_pid,
			array(
				'minn_d1_note' => 'Note 2',
				'minn_d1_page' => array(
					array( '__idx' => 0, '__layout' => 'hero', 'values' => array( 'title' => 'Hero' ) ),
					array( '__idx' => 1, '__layout' => 'text', 'values' => array( 'body' => 'Body 2' ) ),
				),
				'minn_d1_wrap' => array( array( '__idx' => 0, 'values' => array( 'title' => 'W2' ) ) ),
			)
		);
		$o1_after = $layout_meta( $o1_pid, 'minn_d1_page' );
		$check( 'OS-1 a flexible save keeps the section disabled in wp-admin', 200 === $o1_st && array( 'hero', 'cta', 'text' ) === $meta( $o1_pid, 'minn_d1_page' ) && 'Buy' === $meta( $o1_pid, 'minn_d1_page_1_label' ), $o1_st . ' ' . wp_json_encode( $meta( $o1_pid, 'minn_d1_page' ) ) );
		$check( 'OS-1 and its disabled and renamed layout meta', array( 1 ) === ( $o1_after['disabled'] ?? null ) && 'Intro text' === ( $o1_after['renamed'][2] ?? '' ), wp_json_encode( $o1_after ) );
		$check( 'OS-1 a repeater save keeps a disabled section of a flexible field in its row', array( 'a', 'b' ) === $meta( $o1_pid, 'minn_d1_wrap_0_inner' ) && array( 0 ) === ( $layout_meta( $o1_pid, 'minn_d1_wrap_0_inner' )['disabled'] ?? null ), wp_json_encode( array( $meta( $o1_pid, 'minn_d1_wrap_0_inner' ), $layout_meta( $o1_pid, 'minn_d1_wrap_0_inner' ) ) ) );
		$check( 'OS-1 control: the rest of the post still saves', 'Note 2' === $meta( $o1_pid, 'minn_d1_note' ), wp_json_encode( $meta( $o1_pid, 'minn_d1_note' ) ) );
		$o1_g = $payload_group( $o1_pid, 'MinnD1 flex' );
		$check( 'OS-1 both fields count as living in wp-admin', ! in_array( 'minn_d1_page', $o1_g['names'], true ) && ! in_array( 'minn_d1_wrap', $o1_g['names'], true ) && 2 === $o1_g['locked'] && ! array_key_exists( 'minn_d1_page', $panel( $o1_pid ) ), wp_json_encode( $o1_g ) );
		wp_set_current_user( $admin );
	}
	wp_delete_post( $o1_pid, true );
}
// Control: no disabled or renamed section, still served and saved.
$o2_pid = $make_post( 'flex plain', $contrib );
if ( ! $o2_pid ) {
	$check( 'OS-1 fixture (plain)', false );
} else {
	update_field( 'field_minn_d1_page', array( array( 'acf_fc_layout' => 'hero', 'field_minn_d1_p_title' => 'Hero' ), array( 'acf_fc_layout' => 'text', 'field_minn_d1_p_body' => 'Body' ) ), $o2_pid );
	update_field( 'field_minn_d1_wrap', array( array( 'field_minn_d1_w_title' => 'W', 'field_minn_d1_w_inner' => array( array( 'acf_fc_layout' => 'a', 'field_minn_d1_w_at' => 'A0' ) ) ) ), $o2_pid );
	wp_set_current_user( $contrib );
	$o2_vals = $panel( $o2_pid );
	$o2_page = $o2_vals['minn_d1_page'] ?? null;
	$o2_wrap = $o2_vals['minn_d1_wrap'] ?? null;
	if ( ! is_array( $o2_page ) || 2 !== count( $o2_page ) || ! is_array( $o2_wrap ) || 1 !== count( $o2_wrap ) ) {
		$check( 'OS-1 control: a flexible field with no disabled or renamed section is served', false, wp_json_encode( array( $o2_page, $o2_wrap ) ) );
	} else {
		$o2_page[1]['values']['body'] = 'Body 2';
		$o2_wrap[0]['values']['title'] = 'W2';
		$o2_st = $save( $o2_pid, array( 'minn_d1_page' => $o2_page, 'minn_d1_wrap' => $o2_wrap ) );
		$check( 'OS-1 control: a flexible field with no disabled or renamed section still saves', 200 === $o2_st && 'Body 2' === $meta( $o2_pid, 'minn_d1_page_1_body' ) && 'W2' === $meta( $o2_pid, 'minn_d1_wrap_0_title' ) && 'A0' === $meta( $o2_pid, 'minn_d1_wrap_0_inner_0_t' ), $o2_st . ' ' . wp_json_encode( array( $meta( $o2_pid, 'minn_d1_page_1_body' ), $meta( $o2_pid, 'minn_d1_wrap_0_title' ) ) ) );
	}
	wp_set_current_user( $admin );
	wp_delete_post( $o2_pid, true );
}
acf_remove_local_field_group( 'group_minn_d1_flex' );

// Options page: a top-level flexible field and one inside a group (the group
// is written whole on any edit of its subs).
acf_add_options_page( array( 'page_title' => 'MinnD1 opts', 'menu_title' => 'MinnD1 opts', 'menu_slug' => 'minn-d1-opts', 'post_id' => 'minn_d1_opts', 'capability' => 'edit_posts', 'redirect' => false ) );
acf_add_local_field_group( array(
	'key'      => 'group_minn_d1_opts',
	'title'    => 'MinnD1 opts',
	'fields'   => array(
		array( 'key' => 'field_minn_d1_otext', 'name' => 'minn_d1_otext', 'label' => 'Text', 'type' => 'text' ),
		array(
			'key'     => 'field_minn_d1_ofx',
			'name'    => 'minn_d1_ofx',
			'label'   => 'Blocks',
			'type'    => 'flexible_content',
			'layouts' => array( 'layout_minn_d1_o_hero' => array( 'key' => 'layout_minn_d1_o_hero', 'name' => 'hero', 'label' => 'Hero', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_o_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ) ) ) ),
		),
		array(
			'key'        => 'field_minn_d1_og',
			'name'       => 'minn_d1_og',
			'label'      => 'Banner',
			'type'       => 'group',
			'sub_fields' => array(
				array( 'key' => 'field_minn_d1_og_tag', 'name' => 'tag', 'label' => 'Tag', 'type' => 'text' ),
				array(
					'key'     => 'field_minn_d1_og_fx',
					'name'    => 'fx',
					'label'   => 'Parts',
					'type'    => 'flexible_content',
					'layouts' => array( 'layout_minn_d1_og_a' => array( 'key' => 'layout_minn_d1_og_a', 'name' => 'a', 'label' => 'A', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_og_t', 'name' => 't', 'label' => 'T', 'type' => 'text' ) ) ) ),
				),
			),
		),
	),
	'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'minn-d1-opts' ) ) ),
) );
update_field( 'field_minn_d1_otext', 'Old', 'minn_d1_opts' );
update_field( 'field_minn_d1_ofx', array( array( 'acf_fc_layout' => 'hero', 'field_minn_d1_o_title' => 'H0' ), array( 'acf_fc_layout' => 'hero', 'field_minn_d1_o_title' => 'H1', 'acf_fc_layout_disabled' => 1 ) ), 'minn_d1_opts' );
update_field( 'field_minn_d1_og', array( 'field_minn_d1_og_tag' => 'Tag', 'field_minn_d1_og_fx' => array( array( 'acf_fc_layout' => 'a', 'field_minn_d1_og_t' => 'T0', 'acf_fc_layout_custom_label' => 'Renamed' ) ) ), 'minn_d1_opts' );
$opt_meta = function ( $name ) {
	wp_cache_delete( 'alloptions', 'options' );
	$m = acf_get_metadata_by_field( 'minn_d1_opts', array( 'name' => '_' . $name . '_layout_meta' ) );
	return is_array( $m ) ? $m : array();
};
$opt_raw = function ( $name ) {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'minn_d1_opts_' . $name, 'options' );
	return get_option( 'minn_d1_opts_' . $name );
};
if ( array( 1 ) !== ( $opt_meta( 'minn_d1_ofx' )['disabled'] ?? null ) || 'Renamed' !== ( $opt_meta( 'minn_d1_og_fx' )['renamed'][0] ?? '' ) ) {
	$check( 'OS-1 fixture: ACF stored the options layout meta', false, wp_json_encode( array( $opt_meta( 'minn_d1_ofx' ), $opt_meta( 'minn_d1_og_fx' ) ) ) );
} else {
	wp_set_current_user( $contrib );
	list( $op_st ) = $call(
		'POST',
		'/minn-admin/v1/acf/options/minn-d1-opts/tab-0',
		array(
			'values' => array(
				'field_minn_d1_otext'  => 'New',
				'field_minn_d1_ofx'    => array( array( '__idx' => 0, '__layout' => 'hero', 'values' => array( 'title' => 'H0 edited' ) ) ),
				'field_minn_d1_og_tag' => 'Tag 2',
			),
		)
	);
	$check( 'OS-1 an options-page flexible save keeps a disabled section', 200 === $op_st && array( 'hero', 'hero' ) === $opt_raw( 'minn_d1_ofx' ) && array( 1 ) === ( $opt_meta( 'minn_d1_ofx' )['disabled'] ?? null ), $op_st . ' ' . wp_json_encode( array( $opt_raw( 'minn_d1_ofx' ), $opt_meta( 'minn_d1_ofx' ) ) ) );
	$check( 'OS-1 an options-page group save keeps its flexible field\'s renamed label', 'Renamed' === ( $opt_meta( 'minn_d1_og_fx' )['renamed'][0] ?? '' ), wp_json_encode( $opt_meta( 'minn_d1_og_fx' ) ) );
	$check( 'OS-1 control: the options page\'s other field still saves', 'New' === $opt_raw( 'minn_d1_otext' ), wp_json_encode( $opt_raw( 'minn_d1_otext' ) ) );
	wp_set_current_user( $admin );
}
acf_remove_local_field_group( 'group_minn_d1_opts' );
$drop_opts();

// --- A2 the OS-1 lock reads the options page's own language -----------------
// On WPML or Polylang ACF keeps an options page's values under options_{lang}
// (acf_get_valid_post_id()). The lock read plain `options`, so a secondary
// language's disabled section was not seen and the save deleted it, and the
// default language's could lock a secondary language's field. The language
// is set through ACF's own settings, the seam both plugins use.
$lang      = 'en';
$lang_dflt = function () {
	return 'en';
};
$lang_cur  = function () use ( &$lang ) {
	return $lang;
};
add_filter( 'acf/settings/default_language', $lang_dflt, 99 );
add_filter( 'acf/settings/current_language', $lang_cur, 99 );
// Through delete_option(), so no cached copy outlives its row.
$drop_lang = function () use ( $wpdb ) {
	foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%minn\\_d1\\_l%'" ) as $name ) {
		delete_option( $name );
	}
	if ( function_exists( 'acf_get_field_type' ) && acf_get_field_type( 'flexible_content' ) ) {
		acf_get_field_type( 'flexible_content' )->layout_meta = array();
	}
};
$drop_lang();
acf_add_options_page( array( 'page_title' => 'MinnD1 lang', 'menu_title' => 'MinnD1 lang', 'menu_slug' => 'minn-d1-lang', 'capability' => 'edit_posts', 'redirect' => false ) );
acf_add_local_field_group( array(
	'key'      => 'group_minn_d1_lang',
	'title'    => 'MinnD1 lang',
	'fields'   => array(
		array( 'key' => 'field_minn_d1_ltext', 'name' => 'minn_d1_ltext', 'label' => 'Text', 'type' => 'text' ),
		array(
			'key'     => 'field_minn_d1_lfx',
			'name'    => 'minn_d1_lfx',
			'label'   => 'Blocks',
			'type'    => 'flexible_content',
			'layouts' => array( 'layout_minn_d1_l_hero' => array( 'key' => 'layout_minn_d1_l_hero', 'name' => 'hero', 'label' => 'Hero', 'display' => 'block', 'sub_fields' => array( array( 'key' => 'field_minn_d1_l_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ) ) ) ),
		),
	),
	'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => 'minn-d1-lang' ) ) ),
) );
$lang_opt  = function ( $name ) {
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( $name, 'options' );
	return get_option( $name );
};
$lang_meta = function ( $id ) {
	wp_cache_delete( 'alloptions', 'options' );
	$m = acf_get_metadata_by_field( $id, array( 'name' => '_minn_d1_lfx_layout_meta' ) );
	return is_array( $m ) ? $m : array();
};
// A section disabled in the French copy.
$lang = 'fr';
update_field( 'field_minn_d1_ltext', 'Old', 'options' );
update_field( 'field_minn_d1_lfx', array( array( 'acf_fc_layout' => 'hero', 'field_minn_d1_l_title' => 'F0' ), array( 'acf_fc_layout' => 'hero', 'field_minn_d1_l_title' => 'F1', 'acf_fc_layout_disabled' => 1 ) ), 'options' );
if ( array( 1 ) !== ( $lang_meta( 'options_fr' )['disabled'] ?? null ) || $lang_meta( 'options' ) ) {
	$check( 'A2 fixture: ACF stored the French layout meta under options_fr', false, wp_json_encode( array( $lang_meta( 'options_fr' ), $lang_meta( 'options' ) ) ) );
} else {
	wp_set_current_user( $contrib );
	list( $l_st ) = $call( 'POST', '/minn-admin/v1/acf/options/minn-d1-lang/tab-0', array( 'values' => array( 'field_minn_d1_ltext' => 'New', 'field_minn_d1_lfx' => array( array( '__idx' => 0, '__layout' => 'hero', 'values' => array( 'title' => 'F0 edited' ) ) ) ) ) );
	$check( 'A2 an options-page save in a second language keeps its disabled section', 200 === $l_st && array( 'hero', 'hero' ) === $lang_opt( 'options_fr_minn_d1_lfx' ) && array( 1 ) === ( $lang_meta( 'options_fr' )['disabled'] ?? null ), $l_st . ' ' . wp_json_encode( array( $lang_opt( 'options_fr_minn_d1_lfx' ), $lang_meta( 'options_fr' ) ) ) );
	$check( 'A2 control: the page\'s other field saves in that language', 'New' === $lang_opt( 'options_fr_minn_d1_ltext' ), wp_json_encode( $lang_opt( 'options_fr_minn_d1_ltext' ) ) );
	wp_set_current_user( $admin );
}
// The reverse: disabled in the default language, plain in the French copy.
$drop_lang();
$lang = 'en';
update_field( 'field_minn_d1_lfx', array( array( 'acf_fc_layout' => 'hero', 'field_minn_d1_l_title' => 'E0', 'acf_fc_layout_disabled' => 1 ) ), 'options' );
$lang = 'fr';
update_field( 'field_minn_d1_lfx', array( array( 'acf_fc_layout' => 'hero', 'field_minn_d1_l_title' => 'F0' ) ), 'options' );
if ( array( 0 ) !== ( $lang_meta( 'options' )['disabled'] ?? null ) || ! empty( $lang_meta( 'options_fr' )['disabled'] ) ) {
	$check( 'A2 fixture (reverse): the default language holds the disabled section', false, wp_json_encode( array( $lang_meta( 'options' ), $lang_meta( 'options_fr' ) ) ) );
} else {
	wp_set_current_user( $contrib );
	list( , $l_get ) = $call( 'GET', '/minn-admin/v1/acf/options/minn-d1-lang/tab-0' );
	$l_served = is_array( $l_get['values']['field_minn_d1_lfx'] ?? null ) && 1 === count( $l_get['values']['field_minn_d1_lfx'] );
	list( $l_st2 ) = $call( 'POST', '/minn-admin/v1/acf/options/minn-d1-lang/tab-0', array( 'values' => array( 'field_minn_d1_lfx' => array( array( '__idx' => 0, '__layout' => 'hero', 'values' => array( 'title' => 'F0 edited' ) ) ) ) ) );
	$check( 'A2 a second language\'s flexible field is not locked by the default language\'s disabled section', $l_served && 200 === $l_st2 && 'F0 edited' === $lang_opt( 'options_fr_minn_d1_lfx_0_title' ), $l_st2 . ' ' . wp_json_encode( array( $l_served, $lang_opt( 'options_fr_minn_d1_lfx_0_title' ) ) ) );
	$check( 'A2 control: the default language\'s disabled section is untouched', array( 'hero' ) === $lang_opt( 'options_minn_d1_lfx' ) && 'E0' === $lang_opt( 'options_minn_d1_lfx_0_title' ) && array( 0 ) === ( $lang_meta( 'options' )['disabled'] ?? null ), wp_json_encode( array( $lang_opt( 'options_minn_d1_lfx' ), $lang_meta( 'options' ) ) ) );
	wp_set_current_user( $admin );
}
acf_remove_local_field_group( 'group_minn_d1_lang' );
remove_filter( 'acf/settings/default_language', $lang_dflt, 99 );
remove_filter( 'acf/settings/current_language', $lang_cur, 99 );
$drop_lang();

// --- r2 / OS-13 / S1 fields from a seamless clone ----------------------------
// ACF composes their keys (field_CLONE_field_ORIG), which acf_get_field()
// cannot resolve. A repeater or a simple field read and wrote a stand-in
// meta key of the composed name (OS-13). A flexible field written through its
// resolved definition stored the composed key as its `_{name}` reference
// (S1), so a strict get_field() no longer found it; before r2 the write also
// emptied an old-shape link in a sub Minn does not show.
acf_add_local_field_group( array(
	'key'      => 'group_minn_d1_src',
	'title'    => 'MinnD1 source',
	'fields'   => array(
		array( 'key' => 'field_minn_d1_src_text', 'name' => 'minn_d1_src_text', 'label' => 'Source text', 'type' => 'text' ),
		array(
			'key'        => 'field_minn_d1_src_rep',
			'name'       => 'minn_d1_src_rep',
			'label'      => 'Source rows',
			'type'       => 'repeater',
			'sub_fields' => array( array( 'key' => 'field_minn_d1_src_rep_t', 'name' => 't', 'label' => 'T', 'type' => 'text' ) ),
		),
		array(
			'key'     => 'field_minn_d1_src_fx',
			'name'    => 'minn_d1_src_fx',
			'label'   => 'Source sections',
			'type'    => 'flexible_content',
			'layouts' => array(
				'layout_minn_d1_src_blk' => array(
					'key'        => 'layout_minn_d1_src_blk',
					'name'       => 'blk',
					'label'      => 'Block',
					'display'    => 'block',
					'sub_fields' => array(
						array( 'key' => 'field_minn_d1_src_title', 'name' => 'title', 'label' => 'Title', 'type' => 'text' ),
						array(
							'key'        => 'field_minn_d1_src_items',
							'name'       => 'items',
							'label'      => 'Items',
							'type'       => 'repeater',
							'sub_fields' => array( array( 'key' => 'field_minn_d1_src_cta', 'name' => 'cta', 'label' => 'CTA', 'type' => 'link' ) ),
						),
					),
				),
			),
		),
	),
	'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'minn_d1_nowhere' ) ) ),
) );
acf_add_local_field_group( array(
	'key'      => 'group_minn_d1_host',
	'title'    => 'MinnD1 host',
	'fields'   => array(
		array( 'key' => 'field_minn_d1_host_note', 'name' => 'minn_d1_host_note', 'label' => 'Host note', 'type' => 'text' ),
		array( 'key' => 'field_minn_d1_clone', 'name' => 'minn_d1_clone', 'label' => 'Clone', 'type' => 'clone', 'clone' => array( 'group_minn_d1_src' ), 'display' => 'seamless', 'layout' => 'block', 'prefix_label' => 0, 'prefix_name' => 0 ),
	),
	'location' => $post_location,
) );
$c_pid = $make_post( 'clone', $admin );
if ( ! $c_pid ) {
	$check( 'OS-13 fixture', false );
} else {
	foreach (
		array(
			'minn_d1_host_note'                => array( 'Host', 'field_minn_d1_host_note' ),
			'minn_d1_src_text'                 => array( 'Real', 'field_minn_d1_src_text' ),
			'minn_d1_src_rep'                  => array( 1, 'field_minn_d1_src_rep' ),
			'minn_d1_src_rep_0_t'              => array( 'R0', 'field_minn_d1_src_rep_t' ),
			'minn_d1_src_fx'                   => array( array( 'blk' ), 'field_minn_d1_src_fx' ),
			'minn_d1_src_fx_0_title'           => array( 'Blk', 'field_minn_d1_src_title' ),
			'minn_d1_src_fx_0_items'           => array( 1, 'field_minn_d1_src_items' ),
			'minn_d1_src_fx_0_items_0_cta'     => array( 'https://c.example/', 'field_minn_d1_src_cta' ),
		) as $c_k => $c_v
	) {
		update_post_meta( $c_pid, $c_k, $c_v[0] );
		update_post_meta( $c_pid, '_' . $c_k, $c_v[1] );
	}
	$c_vals = $panel( $c_pid );
	$check( 'S1 a cloned flexible field is not served', ! array_key_exists( 'minn_d1_src_fx', $c_vals ), wp_json_encode( $c_vals['minn_d1_src_fx'] ?? null ) );
	// What a client that was served it sends after editing the section title.
	$c_st = $save( $c_pid, array( 'minn_d1_src_fx' => array( array( '__idx' => 0, '__layout' => 'blk', 'values' => array( 'title' => 'Blk 2' ) ) ) ) );
	$check( 'S1 a cloned flexible field keeps its own key as its reference', 200 === $c_st && 'field_minn_d1_src_fx' === $meta( $c_pid, '_minn_d1_src_fx' ), $c_st . ' ' . wp_json_encode( $meta( $c_pid, '_minn_d1_src_fx' ) ) );
	$check( 'S1 that edit changes nothing stored: its section and a nested old-shape link (r2) stay as they were', 'Blk' === $meta( $c_pid, 'minn_d1_src_fx_0_title' ) && 'https://c.example/' === $link_url( $meta( $c_pid, 'minn_d1_src_fx_0_items_0_cta' ) ), wp_json_encode( array( $meta( $c_pid, 'minn_d1_src_fx_0_title' ), $meta( $c_pid, 'minn_d1_src_fx_0_items_0_cta' ) ) ) );
	$check( 'OS-13 a cloned text field is not served from a stand-in key', ! array_key_exists( 'minn_d1_src_text', $c_vals ) || 'Real' === $c_vals['minn_d1_src_text'], wp_json_encode( $c_vals['minn_d1_src_text'] ?? null ) );
	$check( 'OS-13 a cloned repeater is not served from a stand-in key', ! array_key_exists( 'minn_d1_src_rep', $c_vals ) || 1 === count( (array) $c_vals['minn_d1_src_rep'] ), wp_json_encode( $c_vals['minn_d1_src_rep'] ?? null ) );
	$c_st2 = $save( $c_pid, array( 'minn_d1_src_text' => 'Edited', 'minn_d1_src_rep' => array( array( 'values' => array( 't' => 'New' ) ) ), 'minn_d1_host_note' => 'Host 2' ) );
	wp_cache_delete( $c_pid, 'post_meta' );
	$c_stray = array_values( array_filter( array_keys( (array) get_post_meta( $c_pid ) ), function ( $k ) {
		return false !== strpos( $k, 'field_minn_d1_clone_field_' );
	} ) );
	$check( 'OS-13 an edit never lands in a stand-in meta key', 200 === $c_st2 && ! $c_stray, $c_st2 . ' ' . wp_json_encode( $c_stray ) );
	$check( 'OS-13 the real cloned values are untouched', 'Real' === $meta( $c_pid, 'minn_d1_src_text' ) && 'R0' === $meta( $c_pid, 'minn_d1_src_rep_0_t' ) );
	$check( 'OS-13 control: the host group\'s own field saves', 'Host 2' === $meta( $c_pid, 'minn_d1_host_note' ) );
	$c_g = $payload_group( $c_pid, 'MinnD1 host' );
	$check( 'OS-13 S1 the cloned text, repeater and flexible field count as living in wp-admin', 3 === $c_g['locked'] && array( 'minn_d1_host_note' ) === $c_g['names'], wp_json_encode( $c_g ) );
	wp_delete_post( $c_pid, true );
}
acf_remove_local_field_group( 'group_minn_d1_host' );
acf_remove_local_field_group( 'group_minn_d1_src' );

// --- OS-15 term-saving taxonomy fields that ACF would call more than once ---
// With no acf/save_post on a REST write, each call sets the post's terms
// outright: rows leave only the last row's, and a second field saving the
// same taxonomy loses its terms to an edit of the first.
$t_ids = array();
foreach ( array( 'alpha', 'beta', 'gamma' ) as $t_slug ) {
	$t = wp_insert_term( 'MinnD1 ' . $t_slug, 'post_tag', array( 'slug' => 'minn-d1-' . $t_slug ) );
	if ( is_array( $t ) && (int) $t['term_id'] > 1 ) {
		$t_ids[ $t_slug ] = (int) $t['term_id'];
	}
}
$post_terms = function ( $pid ) {
	clean_object_term_cache( $pid, 'post' );
	$ids = wp_get_object_terms( $pid, 'post_tag', array( 'fields' => 'ids' ) );
	$ids = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	sort( $ids );
	return $ids;
};
if ( 3 !== count( $t_ids ) ) {
	$check( 'OS-15 fixture: three tags', false );
} else {
	$tax = function ( $key, $name, $save, $type = 'select' ) {
		return array( 'key' => $key, 'name' => $name, 'label' => $name, 'type' => 'taxonomy', 'taxonomy' => 'post_tag', 'field_type' => $type, 'save_terms' => $save, 'load_terms' => 0, 'return_format' => 'id', 'allow_null' => 1 );
	};
	acf_add_local_field_group( array(
		'key'      => 'group_minn_d1_terms',
		'title'    => 'MinnD1 terms',
		'fields'   => array(
			array( 'key' => 'field_minn_d1_tagrows', 'name' => 'minn_d1_tagrows', 'label' => 'Tag rows', 'type' => 'repeater', 'sub_fields' => array( array( 'key' => 'field_minn_d1_tr_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text' ), $tax( 'field_minn_d1_tr_tag', 'tag', 1 ) ) ),
			array( 'key' => 'field_minn_d1_plainrows', 'name' => 'minn_d1_plainrows', 'label' => 'Plain rows', 'type' => 'repeater', 'sub_fields' => array( array( 'key' => 'field_minn_d1_pr_label', 'name' => 'label', 'label' => 'Label', 'type' => 'text' ), $tax( 'field_minn_d1_pr_tag', 'tag', 0 ) ) ),
		),
		'location' => $post_location,
	) );
	$s_pid = $make_post( 'terms rows', $admin );
	if ( ! $s_pid ) {
		$check( 'OS-15 fixture', false );
	} else {
		$seed( $s_pid, 'minn_d1_tagrows', 'field_minn_d1_tagrows', array( array( 'label' => 'A', 'tag' => $t_ids['alpha'] ), array( 'label' => 'B', 'tag' => $t_ids['beta'] ) ), array( 'label' => 'field_minn_d1_tr_label', 'tag' => 'field_minn_d1_tr_tag' ) );
		$seed( $s_pid, 'minn_d1_plainrows', 'field_minn_d1_plainrows', array( array( 'label' => 'P', 'tag' => $t_ids['gamma'] ) ), array( 'label' => 'field_minn_d1_pr_label', 'tag' => 'field_minn_d1_pr_tag' ) );
		wp_set_object_terms( $s_pid, array( $t_ids['alpha'], $t_ids['beta'] ), 'post_tag', false );
		$s_vals = $panel( $s_pid );
		// What the client sends when row A's label is edited.
		$s_st = $save( $s_pid, array( 'minn_d1_tagrows' => array( array( '__idx' => 0, 'values' => array( 'label' => 'A2' ) ), array( '__idx' => 1, 'values' => array( 'label' => 'B' ) ) ) ) );
		$s_want = array( $t_ids['alpha'], $t_ids['beta'] );
		sort( $s_want );
		$check( 'OS-15 a rows save keeps every row\'s saved terms on the post', 200 === $s_st && $s_want === $post_terms( $s_pid ), $s_st . ' ' . wp_json_encode( $post_terms( $s_pid ) ) );
		$check( 'OS-15 the term-saving repeater counts as living in wp-admin', ! array_key_exists( 'minn_d1_tagrows', $s_vals ) && ! in_array( 'minn_d1_tagrows', $payload_group( $s_pid, 'MinnD1 terms' )['names'], true ), wp_json_encode( array_keys( $s_vals ) ) );
		$s_plain = $s_vals['minn_d1_plainrows'] ?? null;
		if ( ! is_array( $s_plain ) || 1 !== count( $s_plain ) ) {
			$check( 'OS-15 control: a repeater whose taxonomy sub saves no terms is served', false, wp_json_encode( $s_plain ) );
		} else {
			$s_plain[0]['values']['label'] = 'P2';
			$s_st2 = $save( $s_pid, array( 'minn_d1_plainrows' => $s_plain ) );
			$check( 'OS-15 control: a repeater whose taxonomy sub saves no terms still saves', 200 === $s_st2 && 'P2' === $meta( $s_pid, 'minn_d1_plainrows_0_label' ) && (string) $t_ids['gamma'] === (string) $meta( $s_pid, 'minn_d1_plainrows_0_tag' ), $s_st2 . ' ' . wp_json_encode( $meta( $s_pid, 'minn_d1_plainrows_0_label' ) ) );
		}
		wp_delete_post( $s_pid, true );
	}
	acf_remove_local_field_group( 'group_minn_d1_terms' );

	// Two top-level fields saving the same taxonomy.
	acf_add_local_field_group( array(
		'key'      => 'group_minn_d1_terms2',
		'title'    => 'MinnD1 terms2',
		'fields'   => array( $tax( 'field_minn_d1_primary', 'minn_d1_primary', 1 ), $tax( 'field_minn_d1_more', 'minn_d1_more', 1, 'multi_select' ) ),
		'location' => $post_location,
	) );
	$s2_pid = $make_post( 'terms pair', $admin );
	if ( ! $s2_pid ) {
		$check( 'OS-15 fixture (pair)', false );
	} else {
		update_post_meta( $s2_pid, 'minn_d1_primary', $t_ids['alpha'] );
		update_post_meta( $s2_pid, '_minn_d1_primary', 'field_minn_d1_primary' );
		update_post_meta( $s2_pid, 'minn_d1_more', array( (string) $t_ids['beta'] ) );
		update_post_meta( $s2_pid, '_minn_d1_more', 'field_minn_d1_more' );
		wp_set_object_terms( $s2_pid, array( $t_ids['alpha'], $t_ids['beta'] ), 'post_tag', false );
		$s2_st = $save( $s2_pid, array( 'minn_d1_primary' => array( 'value' => (string) $t_ids['gamma'] ) ) );
		$check( 'OS-15 an edit of one term-saving field keeps the other\'s terms on the post', 200 === $s2_st && in_array( $t_ids['beta'], $post_terms( $s2_pid ), true ), $s2_st . ' ' . wp_json_encode( $post_terms( $s2_pid ) ) );
		wp_delete_post( $s2_pid, true );
	}
	acf_remove_local_field_group( 'group_minn_d1_terms2' );

	// Control: one term-saving field alone still edits, and sets the terms.
	acf_add_local_field_group( array(
		'key'      => 'group_minn_d1_terms3',
		'title'    => 'MinnD1 terms3',
		'fields'   => array( $tax( 'field_minn_d1_solo', 'minn_d1_solo', 1 ) ),
		'location' => $post_location,
	) );
	$s3_pid = $make_post( 'terms solo', $admin );
	if ( ! $s3_pid ) {
		$check( 'OS-15 fixture (solo)', false );
	} else {
		update_post_meta( $s3_pid, 'minn_d1_solo', $t_ids['alpha'] );
		update_post_meta( $s3_pid, '_minn_d1_solo', 'field_minn_d1_solo' );
		wp_set_object_terms( $s3_pid, array( $t_ids['alpha'] ), 'post_tag', false );
		$s3_served = array_key_exists( 'minn_d1_solo', $panel( $s3_pid ) );
		$s3_st     = $save( $s3_pid, array( 'minn_d1_solo' => array( 'value' => (string) $t_ids['gamma'] ) ) );
		$check( 'OS-15 control: a single term-saving field is served and saves its terms', $s3_served && 200 === $s3_st && (string) $t_ids['gamma'] === (string) $meta( $s3_pid, 'minn_d1_solo' ) && array( $t_ids['gamma'] ) === $post_terms( $s3_pid ), $s3_st . ' ' . wp_json_encode( array( $s3_served, $meta( $s3_pid, 'minn_d1_solo' ), $post_terms( $s3_pid ) ) ) );
		wp_delete_post( $s3_pid, true );
	}
	acf_remove_local_field_group( 'group_minn_d1_terms3' );
}
foreach ( $t_ids as $t_id ) {
	if ( $t_id > 1 ) {
		wp_delete_term( $t_id, 'post_tag' );
	}
}

$drop_user( $contrib );

$summary();
