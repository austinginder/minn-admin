<?php
/**
 * Changes planted on purpose, to prove the parity diff catches them:
 *
 *   node tests/parity/capture.js --out /tmp/planted.json --only everest --writes everest-forms --require tests/parity/plant.php
 *   node tests/parity/diff.js tests/.parity/<reference>.json /tmp/planted.json --only everest
 *
 * must FAIL on each: a renamed key, a date sent with an offset, an id sent
 * as a string, a route gone, a gate opened to everyone, a descriptor label.
 * Needs Everest Forms active with at least one entry.
 *
 * @package minn-admin
 */
WP_CLI::add_wp_hook( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( '/minn-admin/v1/everest/entries' === $req->get_route() && $res instanceof WP_REST_Response ) {
		$d = $res->get_data();
		foreach ( $d['items'] as &$it ) {
			$it['form'] = $it['form_title'];
			unset( $it['form_title'] );
			$it['date'] = $it['date'] . '+00:00';
			$it['id']   = (string) $it['id'];
		}
		$res->set_data( $d );
	}
	return $res;
}, 10, 3 );
WP_CLI::add_wp_hook( 'rest_endpoints', function ( $e ) {
	unset( $e['/minn-admin/v1/everest/status'] );
	if ( isset( $e['/minn-admin/v1/everest/forms'][0] ) ) {
		$e['/minn-admin/v1/everest/forms'][0]['permission_callback'] = '__return_true';
	}
	return $e;
} );
WP_CLI::add_wp_hook( 'minn_admin_surfaces', function ( $s ) {
	if ( isset( $s['everest-forms']['collection']['actions'][0]['label'] ) ) {
		$s['everest-forms']['collection']['actions'][0]['label'] = 'Spam it';
	}
	return $s;
}, 99 );
