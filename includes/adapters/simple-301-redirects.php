<?php
/**
 * Bundled adapter: Simple 301 Redirects.
 *
 * The plugin stores redirects as a flat `301_redirects` option — an
 * associative array of `from => to` (all 301s, no per-rule status). No REST
 * surface, so this is a shim: list / search / create / edit / delete over that
 * option. The option key IS the source path, so rows are indexed by a base64
 * of the source for stable ids (recomputed when the source is renamed).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_s301_active() {
	return class_exists( 'Simple301Redirects' );
}

/** Base64url-encode a source path for use as a REST id. */
function minn_admin_s301_encode_id( $from ) {
	return rtrim( strtr( base64_encode( (string) $from ), '+/', '-_' ), '=' );
}

/** Inverse of minn_admin_s301_encode_id. */
function minn_admin_s301_decode_id( $id ) {
	return (string) base64_decode( strtr( (string) $id, '-_', '+/' ) );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_s301_active() ) {
		return $surfaces;
	}
	$surfaces['simple-301-redirects'] = array(
		'label'      => __( 'Redirects', 'minn-admin' ),
		'family'     => 'redirects',
		'sub'        => 'Simple 301 Redirects',
		'icon'       => 'shuffle',
		'cap'        => 'manage_options',
		// Status card (v0.18.0): family parity with Redirection.
		'status'     => array( 'route' => 'minn-admin/v1/s301/status' ),
		'collection' => array(
			'route'    => 'minn-admin/v1/s301/redirects',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'search'   => 'search={q}',
			'columns'  => array(
				array( 'key' => 'from', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'to', 'label' => __( 'Target', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.4fr)' ),
			),
			'create'   => array(
				'label'  => __( 'Add redirect', 'minn-admin' ),
				'route'  => 'minn-admin/v1/s301/redirects',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'from', 'label' => __( 'Source URL', 'minn-admin' ), 'mono' => true, 'placeholder' => __( '/old-page', 'minn-admin' ) ),
					array( 'key' => 'to', 'label' => __( 'Target URL', 'minn-admin' ), 'mono' => true, 'placeholder' => __( '/new-page or https://…', 'minn-admin' ) ),
				),
			),
			'detail'   => array(
				'skip' => array( 'id' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/s301/redirects/{id}',
					'method' => 'PUT',
					'fields' => array(
						array( 'key' => 'from', 'label' => __( 'Source URL', 'minn-admin' ), 'mono' => true ),
						array( 'key' => 'to', 'label' => __( 'Target URL', 'minn-admin' ), 'mono' => true ),
					),
				),
			),
			'actions'  => array(
				array(
					'label'   => __( 'Delete redirect', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/s301/redirects/{id}',
					'confirm' => __( 'Delete this redirect permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
			'bulk'     => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/s301/redirects/{id}',
					'confirm' => __( 'Delete the selected redirects permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_s301_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( 'manage_options' );
	};

	// Status card: the whole store is one option array (no hits, no log),
	// so the honest card is rule count + the wildcard toggle.
	register_rest_route( 'minn-admin/v1', '/s301/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$rules     = (array) get_option( '301_redirects', array() );
			$wildcards = get_option( '301_redirects_wildcard', false );
			return rest_ensure_response( array(
				'rows' => array(
					array( 'label' => __( 'Redirect rules', 'minn-admin' ), 'value' => (string) count( $rules ) ),
					array(
						'label' => __( 'Wildcards', 'minn-admin' ),
						'value' => $wildcards ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ),
						'hint'  => $wildcards ? '* patterns match in sources' : '',
					),
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/s301/redirects', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$rows   = (array) get_option( '301_redirects', array() );
				$search = strtolower( trim( (string) $request['search'] ) );
				$items  = array();
				foreach ( $rows as $from => $to ) {
					if ( '' !== $search && strpos( strtolower( $from . ' ' . $to ), $search ) === false ) {
						continue;
					}
					$items[] = array(
						'id'   => minn_admin_s301_encode_id( $from ),
						'from' => (string) $from,
						'to'   => (string) $to,
					);
				}
				$total = count( $items );
				$page  = max( 1, (int) ( $request['page'] ?: 1 ) );
				$items = array_slice( $items, ( $page - 1 ) * 25, 25 );
				return rest_ensure_response( array( 'items' => array_values( $items ), 'total' => $total ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				// sanitize_text_field on both sides, matching the vendor's own
				// writer (Admin/Ajax.php) — without it the shared option ends
				// up holding values the plugin itself would never store.
				$from = sanitize_text_field( trim( (string) $request['from'] ) );
				$to   = sanitize_text_field( trim( (string) $request['to'] ) );
				if ( '' === $from || '' === $to ) {
					return new WP_Error( 'invalid', __( 'Source and target are both required.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$rows          = (array) get_option( '301_redirects', array() );
				$rows[ $from ] = $to;
				update_option( '301_redirects', $rows );
				return rest_ensure_response( array(
					'id'   => minn_admin_s301_encode_id( $from ),
					'from' => $from,
					'to'   => $to,
				) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/s301/redirects/(?P<id>[A-Za-z0-9\-_]+)', array(
		array(
			'methods'             => 'PUT',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$old  = minn_admin_s301_decode_id( $request['id'] );
				// sanitize_text_field on both sides, matching the vendor's own
				// writer (Admin/Ajax.php) — without it the shared option ends
				// up holding values the plugin itself would never store.
				$from = sanitize_text_field( trim( (string) $request['from'] ) );
				$to   = sanitize_text_field( trim( (string) $request['to'] ) );
				if ( '' === $from || '' === $to ) {
					return new WP_Error( 'invalid', __( 'Source and target are both required.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$rows = (array) get_option( '301_redirects', array() );
				if ( ! array_key_exists( $old, $rows ) ) {
					return new WP_Error( 'not_found', __( 'Redirect not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Renaming the source: drop the old key, write the new one.
				if ( $old !== $from ) {
					unset( $rows[ $old ] );
				}
				$rows[ $from ] = $to;
				update_option( '301_redirects', $rows );
				return rest_ensure_response( array(
					'id'   => minn_admin_s301_encode_id( $from ),
					'from' => $from,
					'to'   => $to,
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$from = minn_admin_s301_decode_id( $request['id'] );
				$rows = (array) get_option( '301_redirects', array() );
				if ( isset( $rows[ $from ] ) ) {
					unset( $rows[ $from ] );
					update_option( '301_redirects', $rows );
				}
				return rest_ensure_response( array( 'deleted' => $from ) );
			},
		),
	) );
} );
