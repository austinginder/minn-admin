<?php
/**
 * Ids of real items for the page and entry captures (roundtrip-pages-capture.js,
 * roundtrip-entries-capture.js): the first of each kind the lab holds, so
 * every bespoke page can be opened on something that exists. Prints one JSON
 * object; a kind the site lacks is left out.
 *
 * Run: wp eval-file tests/lib/roundtrip-fixtures.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$out = array();
$first_post = function ( $type, $extra = array() ) {
	$ids = get_posts( array_merge( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ), $extra ) );
	return $ids ? (int) $ids[0] : null;
};

if ( class_exists( 'GFAPI' ) ) {
	foreach ( GFAPI::get_forms() as $form ) {
		$n = array_keys( (array) rgar( $form, 'notifications' ) );
		$c = array_keys( (array) rgar( $form, 'confirmations' ) );
		if ( $n && $c ) {
			$out['gf_form']         = (int) $form['id'];
			$out['gf_notification'] = $form['id'] . ':' . $n[0];
			$out['gf_confirmation'] = $form['id'] . ':' . $c[0];
			break;
		}
	}
}
if ( function_exists( 'wpforms' ) ) {
	$out['wpforms_form'] = $first_post( 'wpforms' );
}
if ( defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' ) ) {
	global $wpdb;
	$id = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}fluentform_forms ORDER BY id ASC LIMIT 1" );
	if ( $id ) {
		$out['fluent_form'] = (int) $id;
	}
}
if ( defined( 'WPCF7_VERSION' ) ) {
	$out['cf7_form'] = $first_post( 'wpcf7_contact_form' );
}
if ( function_exists( 'acf_get_field_groups' ) ) {
	foreach ( acf_get_field_groups() as $g ) {
		if ( ! empty( $g['ID'] ) ) {
			$out['acf_group'] = $g['key'];
			break;
		}
	}
}
$out['menu'] = ( $m = wp_get_nav_menus() ) ? (int) $m[0]->term_id : null;
$out['user'] = (int) ( get_users( array( 'role__in' => array( 'editor', 'author' ), 'number' => 1, 'orderby' => 'ID' ) )[0]->ID ?? 0 ) ?: null;
if ( function_exists( 'wc_get_orders' ) ) {
	$o = wc_get_orders( array( 'limit' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ) );
	$out['order']   = $o ? (int) $o[0] : null;
	$out['product'] = $first_post( 'product', array( 'post_status' => 'publish' ) );
}
if ( function_exists( 'wc_memberships_get_membership_plans' ) ) {
	$plans = wc_memberships_get_membership_plans();
	$out['plan'] = $plans ? (int) reset( $plans )->get_id() : null;
	$out['membership'] = $first_post( 'wc_user_membership' );
}

/*
 * Form entries whose answers the entry page edits (roundtrip-entries-capture.js,
 * the `answers` seed): the first one per plugin that is already read and not
 * in the spam or trash, since opening an unread entry marks it read, a write
 * the capture cannot answer for. One with no editable answer is passed over.
 */
global $wpdb;
$table_exists = function ( $name ) use ( $wpdb ) {
	return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
};
if ( function_exists( 'minn_admin_gf_entry_edit_block' ) && class_exists( 'GFAPI' ) && $table_exists( $wpdb->prefix . 'gf_entry' ) ) {
	foreach ( $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}gf_entry WHERE status = 'active' AND is_read = 1 ORDER BY id ASC LIMIT 50" ) as $id ) {
		$entry = GFAPI::get_entry( (int) $id );
		$form  = is_wp_error( $entry ) ? null : GFAPI::get_form( $entry['form_id'] );
		if ( $form && minn_admin_gf_entry_edit_block( $form, $entry ) ) {
			$out['gf_entry'] = (int) $id;
			break;
		}
	}
}
if ( function_exists( 'minn_admin_elementor_edit_block' ) && class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Query' ) && $table_exists( $wpdb->prefix . 'e_submissions' ) ) {
	$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
	foreach ( $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}e_submissions WHERE is_read = 1 AND status NOT LIKE '%trash%' ORDER BY id ASC LIMIT 50" ) as $id ) {
		$raw = $query->get_submission( (int) $id );
		if ( ! empty( $raw['data'] ) && minn_admin_elementor_edit_block( $raw['data'] ) ) {
			$out['elementor_entry'] = (int) $id;
			break;
		}
	}
}
if ( function_exists( 'minn_admin_ninja_forms_edit_block' ) ) {
	foreach ( get_posts( array( 'post_type' => 'nf_sub', 'post_status' => 'publish', 'numberposts' => 50, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $sub ) {
		if ( minn_admin_ninja_forms_edit_block( $sub, (int) get_post_meta( $sub->ID, '_form_id', true ) ) ) {
			$out['nf_entry'] = (int) $sub->ID;
			break;
		}
	}
}
if ( function_exists( 'minn_admin_wpforms_edit_block' ) && $table_exists( $wpdb->prefix . 'wpforms_entries' ) ) {
	foreach ( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wpforms_entries WHERE viewed = 1 AND status NOT IN ('spam', 'trash') ORDER BY entry_id ASC LIMIT 50" ) as $row ) {
		if ( minn_admin_wpforms_edit_block( $row ) ) {
			$out['wpforms_entry'] = (int) $row->entry_id;
			break;
		}
	}
}

$out = array_filter( $out, function ( $v ) {
	return null !== $v && '' !== $v;
} );
// lib/roundtrip-seed.php includes this file for the same items.
if ( ! empty( $GLOBALS['minn_rt_fixtures_return'] ) ) {
	return $out;
}
echo wp_json_encode( $out );
