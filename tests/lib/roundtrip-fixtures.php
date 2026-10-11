<?php
/**
 * Ids of real items for the page capture (roundtrip-pages-capture.js): the
 * first of each kind the lab holds, so every bespoke page can be opened on
 * something that exists. Prints one JSON object; a kind the site lacks is
 * left out.
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

echo wp_json_encode( array_filter( $out, function ( $v ) {
	return null !== $v && '' !== $v;
} ) );
