<?php
/**
 * The SEO panel follows each provider's own "show the SEO box" switch for a
 * content type, and the types the provider never boxes. Switched off, the
 * panel offers no fields and a REST write of minn_seo is refused.
 *
 * One provider per run: Yoast resident, the rest through the swaps.
 *
 *   wp eval-file tests/seo-box-gate.test.php --user=admin
 *   MINN_SEO_SWAP=rank-math wp eval-file tests/seo-box-gate.test.php --user=admin --require=tests/lib/seo-swap.php
 *   MINN_SEO_SWAP=seopress  wp eval-file tests/seo-box-gate.test.php --user=admin --require=tests/lib/seo-swap.php
 *   MINN_SEO_SWAP=siteseo   wp eval-file tests/seo-box-gate.test.php --user=admin --require=tests/lib/seo-swap.php
 *   wp eval-file tests/seo-box-gate.test.php --user=admin --require=tests/lib/aioseo-swap.php
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};

$plugin = function_exists( 'minn_admin_seo_plugin' ) ? minn_admin_seo_plugin() : null;
if ( ! $plugin ) {
	echo "SKIP  no SEO provider active\n";
	return;
}
$name = (string) $plugin['name'];
echo "provider: {$name}\n";

// Each provider's switch for a type, written the way its own settings
// screen stores it; the returned closure puts the stored value back.
$switch = array(
	'Yoast SEO' => function ( $type, $on ) {
		$was = get_option( 'wpseo_titles' );
		WPSEO_Options::set( 'display-metabox-pt-' . $type, $on );
		return function () use ( $was ) {
			update_option( 'wpseo_titles', $was );
			WPSEO_Options::clear_cache();
		};
	},
	'Rank Math' => function ( $type, $on ) {
		$was = get_option( 'rank-math-options-titles' );
		$set = (array) $was;
		$set[ 'pt_' . $type . '_add_meta_box' ] = $on ? 'on' : 'off';
		update_option( 'rank-math-options-titles', $set );
		\RankMath\Helper::get_settings( 'titles', null ); // warm
		rank_math()->settings->reset();
		return function () use ( $was ) {
			update_option( 'rank-math-options-titles', $was );
			rank_math()->settings->reset();
		};
	},
	'AIOSEO'    => function ( $type, $on ) {
		$was = aioseo()->dynamicOptions->noConflict()->searchAppearance->postTypes->{$type}->advanced->showMetaBox;
		aioseo()->dynamicOptions->noConflict()->searchAppearance->postTypes->{$type}->advanced->showMetaBox = $on;
		return function () use ( $was, $type ) {
			aioseo()->dynamicOptions->noConflict()->searchAppearance->postTypes->{$type}->advanced->showMetaBox = $was;
		};
	},
	'SEOPress'  => function ( $type, $on ) {
		$was = get_option( 'seopress_titles_option_name' );
		$set = is_array( $was ) ? $was : array();
		$set['seopress_titles_single_titles'][ $type ]['enable'] = $on ? '' : '1';
		if ( $on ) {
			unset( $set['seopress_titles_single_titles'][ $type ]['enable'] );
		}
		update_option( 'seopress_titles_option_name', $set );
		return function () use ( $was ) {
			false === $was ? delete_option( 'seopress_titles_option_name' ) : update_option( 'seopress_titles_option_name', $was );
		};
	},
	'SiteSEO'   => function ( $type, $on ) {
		$was = get_option( 'siteseo_titles_option_name' );
		$set = is_array( $was ) ? $was : array();
		$set['titles_single_titles'][ $type ]['disabled'] = ! $on;
		update_option( 'siteseo_titles_option_name', $set );
		return function () use ( $was ) {
			false === $was ? delete_option( 'siteseo_titles_option_name' ) : update_option( 'siteseo_titles_option_name', $was );
		};
	},
);
if ( ! isset( $switch[ $name ] ) ) {
	echo "SKIP  {$name} has no per-type switch to flip\n";
	return;
}

$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'minn seo box gate', 'post_status' => 'draft' ) );
$put  = function () use ( $page ) {
	$r = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $page );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( wp_json_encode( array( 'minn_seo' => array( 'canonical' => 'https://elsewhere.example/minn-box-gate' ) ) ) );
	$res = rest_do_request( $r );
	return array( $res->get_status(), (string) wp_json_encode( get_post_meta( $page ) ) );
};
try {
	$check( "{$name}: the box shows for pages by default (control)", true === minn_admin_seo_shows_for_type( $plugin, 'page' ) );

	$restore = $switch[ $name ]( 'page', false );
	try {
		$check( "{$name}: switched off for pages, the gate closes", false === minn_admin_seo_shows_for_type( $plugin, 'page' ) );
		list( $status, $meta ) = $put();
		$check( "{$name}: switched off, a minn_seo write stores nothing", false === strpos( $meta, 'elsewhere.example' ), $status . ' ' . substr( $meta, 0, 160 ) );
	} finally {
		$restore();
	}
	$restore = $switch[ $name ]( 'page', true );
	try {
		$check( "{$name}: switched back on, the gate opens (control)", true === minn_admin_seo_shows_for_type( $plugin, 'page' ) );
	} finally {
		$restore();
	}

	// A type with REST that the provider never boxes (synced patterns are
	// not public). SEOPress boxes by its switch alone.
	if ( 'SEOPress' !== $name ) {
		$check( "{$name}: a type it never boxes is closed", false === minn_admin_seo_shows_for_type( $plugin, 'wp_block' ) );
	}
} finally {
	wp_delete_post( $page, true );
}

printf( "\nseo-box-gate (%s): %d/%d passed\n", $name, count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
