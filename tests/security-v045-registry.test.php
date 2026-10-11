<?php
/**
 * Regressions for the v0.44.0 shipped-zip registry scan (findings that open
 * v0.45.0).
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what got stored, with a
 * control where a fix could over-block. Sections SKIP when their plugin is
 * inactive.
 *
 * Run: wp eval-file tests/security-v045-registry.test.php --user=admin --path=<site>
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
	printf( "\nsecurity-v045-registry: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
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
wp_set_current_user( $admin[0]->ID );

/*
 * 14-1: the Pods panel resends every field it shows. Pods parses a number or
 * currency value in the field's own format (thousands separator removed, then
 * the decimal mark made a dot), so the raw '19.99' the panel read came back
 * as 1999.00 wherever '.' is the thousands separator: a de_DE site's
 * localized default, or an explicit 9.999,99 format as here.
 */
( function () use ( $check, $skip, $call ) {
	if ( ! function_exists( 'pods_api' ) || ! function_exists( 'minn_admin_pods_read_values' ) ) {
		$skip( '14-1 Pods inactive' );
		return;
	}
	$api = pods_api();
	$pod = $api->load_pod( array( 'name' => 'post' ) );
	if ( ! $pod ) {
		$skip( '14-1 no Pods pod extends post' );
		return;
	}
	$made = array();
	foreach ( array(
		'minn_v045_price' => array( 'type' => 'currency', 'currency_format' => '9.999,99', 'currency_decimals' => 2 ),
		'minn_v045_ratio' => array( 'type' => 'number', 'number_format' => '9.999,99', 'number_decimals' => 2 ),
		'minn_v045_plain' => array( 'type' => 'number', 'number_format' => '9,999.99', 'number_decimals' => 2 ),
	) as $name => $opts ) {
		$id = $api->save_field( array_merge( array( 'pod' => 'post', 'name' => $name, 'label' => $name ), $opts ) );
		if ( $id ) {
			$made[] = $name;
		}
	}
	$post_id = wp_insert_post( array( 'post_title' => 'v045 Pods probe', 'post_status' => 'draft' ) );
	try {
		$check( '14-1 fixture fields and post created', 3 === count( $made ) && $post_id, implode( ',', $made ) );
		// Pods' own form sends the value as the field formats it.
		pods( 'post', $post_id )->save( array( 'minn_v045_price' => '19,99', 'minn_v045_ratio' => '1.234,50', 'minn_pods_subtitle' => 'alt' ) );
		$check( '14-1 Pods stores the canonical dot-decimal', '19.99' === get_post_meta( $post_id, 'minn_v045_price', true ) && '1234.50' === get_post_meta( $post_id, 'minn_v045_ratio', true ),
			get_post_meta( $post_id, 'minn_v045_price', true ) . ' / ' . get_post_meta( $post_id, 'minn_v045_ratio', true ) );

		// The panel save: every value as the read served it, one text edited.
		$served = minn_admin_pods_read_values( $post_id );
		$served['minn_pods_subtitle'] = 'neu';
		list( $status ) = $call( 'POST', '/wp/v2/posts/' . $post_id, array( 'minn_pods' => $served ) );
		wp_cache_flush();
		$check( '14-1 a text edit saves', 200 === $status && 'neu' === get_post_meta( $post_id, 'minn_pods_subtitle', true ), $status . ' ' . get_post_meta( $post_id, 'minn_pods_subtitle', true ) );
		$check( '14-1 the untouched currency field keeps 19.99', '19.99' === get_post_meta( $post_id, 'minn_v045_price', true ), get_post_meta( $post_id, 'minn_v045_price', true ) );
		$check( '14-1 the untouched number field keeps 1234.50', '1234.50' === get_post_meta( $post_id, 'minn_v045_ratio', true ), get_post_meta( $post_id, 'minn_v045_ratio', true ) );

		// An edited value arrives as the number input holds it (dot decimal).
		$served = minn_admin_pods_read_values( $post_id );
		$served['minn_v045_price'] = '25.50';
		$served['minn_v045_ratio'] = '7.25';
		$served['minn_v045_plain'] = '3.75';
		list( $status ) = $call( 'POST', '/wp/v2/posts/' . $post_id, array( 'minn_pods' => $served ) );
		wp_cache_flush();
		$check( '14-1 an edited currency value is stored as typed', '25.50' === get_post_meta( $post_id, 'minn_v045_price', true ), get_post_meta( $post_id, 'minn_v045_price', true ) );
		$check( '14-1 an edited number value is stored as typed', '7.25' === get_post_meta( $post_id, 'minn_v045_ratio', true ), get_post_meta( $post_id, 'minn_v045_ratio', true ) );
		$check( '14-1 control: a dot-decimal format field is stored as typed', '3.75' === get_post_meta( $post_id, 'minn_v045_plain', true ), get_post_meta( $post_id, 'minn_v045_plain', true ) );
	} finally {
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
		foreach ( $made as $name ) {
			$api->delete_field( array( 'pod' => 'post', 'name' => $name ) );
		}
	}
} )();

/*
 * 04-4, the class: a surface's edit modal sends its `preserve` keys as they
 * were when the modal opened. A key one of the same list's actions sets (an
 * on/off switch) would be put back by any later save from a modal opened
 * before the action ran, so no surface may preserve one.
 */
( function () use ( $check, $call ) {
	list( $status, $d ) = $call( 'GET', '/minn-admin/v1/surfaces' );
	$list  = isset( $d['surfaces'] ) ? $d['surfaces'] : (array) $d;
	$colls = array();
	foreach ( $list as $s ) {
		$s = json_decode( wp_json_encode( $s ), true );
		foreach ( array( $s['collection'] ?? null, $s['manage'] ?? null ) as $c ) {
			if ( $c ) {
				$colls[ $s['id'] ][] = $c;
			}
		}
		foreach ( (array) ( $s['views'] ?? array() ) as $v ) {
			if ( ! empty( $v['collection'] ) ) {
				$colls[ $s['id'] ][] = $v['collection'];
			}
		}
	}
	$bad = array();
	foreach ( $colls as $sid => $list_of ) {
		foreach ( $list_of as $c ) {
			$preserve = (array) ( $c['detail']['edit']['preserve'] ?? array() );
			if ( ! $preserve ) {
				continue;
			}
			$set = array();
			foreach ( array_merge( (array) ( $c['actions'] ?? array() ), (array) ( $c['bulk'] ?? array() ), (array) ( $c['status']['actions'] ?? array() ) ) as $a ) {
				foreach ( array_keys( (array) ( $a['body'] ?? array() ) ) as $k ) {
					$set[ $k ] = true;
				}
			}
			foreach ( $preserve as $k ) {
				if ( isset( $set[ $k ] ) ) {
					$bad[] = "{$sid} preserves {$k}";
				}
			}
		}
	}
	$check( '04-4 class: no surface preserves a key one of its own actions sets', 200 === $status && ! $bad, implode( '; ', $bad ) );
} )();

$summary();
