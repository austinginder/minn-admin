<?php
/**
 * Bundled adapter: Safe Redirect Manager.
 *
 * SRM keeps redirects as a `redirect_rule` CPT with meta, not exposed over
 * REST — so this is the shim pattern (docs/for-plugin-authors.md): a small
 * REST collection over SRM's own public functions (srm_get_redirects /
 * srm_create_redirect / srm_delete_redirect_by_id), plus a descriptor that
 * lists, searches, creates and deletes through it. Editing/regex stays in
 * SRM's own screen.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_srm_active() {
	return defined( 'SRM_VERSION' ) && function_exists( 'srm_get_redirects' ) && function_exists( 'srm_create_redirect' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_srm_active() ) {
		return $surfaces;
	}
	$surfaces['safe-redirect-manager'] = array(
		'label'      => 'Redirects',
		'sub'        => 'Safe Redirect Manager',
		'icon'       => 'shuffle',
		'cap'        => 'manage_options',
		'collection' => array(
			'route'    => 'minn-admin/v1/srm/redirects',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'search'   => 'search={q}',
			'columns'  => array(
				array( 'key' => 'from', 'label' => 'Source', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'Target' ),
				array( 'key' => 'status_code', 'label' => 'Code', 'format' => 'mono' ),
				array( 'key' => 'regex', 'label' => 'Regex' ),
			),
			'create'   => array(
				'label'    => 'Add redirect',
				'route'    => 'minn-admin/v1/srm/redirects',
				'method'   => 'POST',
				'defaults' => array( 'status_code' => 301 ),
				'fields'   => array(
					array( 'key' => 'from', 'label' => 'Source URL', 'mono' => true, 'placeholder' => '/old-page' ),
					array( 'key' => 'to', 'label' => 'Target URL', 'mono' => true, 'placeholder' => '/new-page or https://…' ),
					array( 'key' => 'status_code', 'label' => 'HTTP status', 'type' => 'number', 'value' => 301 ),
				),
			),
			'actions'  => array(
				array(
					'label'   => 'Delete redirect',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/srm/redirects/{id}',
					'confirm' => 'Delete this redirect permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_srm_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( 'manage_options' );
	};

	register_rest_route( 'minn-admin/v1', '/srm/redirects', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$rows   = srm_get_redirects();
				$search = strtolower( trim( (string) $request['search'] ) );
				$items  = array();
				foreach ( $rows as $r ) {
					$from = (string) ( $r['redirect_from'] ?? '' );
					$to   = (string) ( $r['redirect_to'] ?? '' );
					if ( '' !== $search && strpos( strtolower( $from . ' ' . $to ), $search ) === false ) {
						continue;
					}
					$items[] = array(
						'id'          => (int) ( $r['ID'] ?? 0 ),
						'from'        => $from,
						'to'          => $to,
						'status_code' => (int) ( $r['status_code'] ?? 302 ),
						'regex'       => ! empty( $r['enable_regex'] ) ? 'yes' : '',
					);
				}
				$total   = count( $items );
				$page    = max( 1, (int) ( $request['page'] ?: 1 ) );
				$per     = 25;
				$items   = array_slice( $items, ( $page - 1 ) * $per, $per );
				return rest_ensure_response( array( 'items' => array_values( $items ), 'total' => $total ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id = srm_create_redirect(
					(string) $request['from'],
					(string) $request['to'],
					(int) ( $request['status_code'] ?: 301 )
				);
				if ( is_wp_error( $id ) ) {
					return new WP_Error( 'create_failed', $id->get_error_message(), array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'created' => (int) $id ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/srm/redirects/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (int) $request['id'];
			if ( function_exists( 'srm_delete_redirect_by_id' ) ) {
				srm_delete_redirect_by_id( $id );
			} else {
				wp_delete_post( $id, true );
			}
			return rest_ensure_response( array( 'deleted' => $id ) );
		},
	) );
} );
