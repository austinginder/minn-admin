<?php
/**
 * Bundled adapter: Safe Redirect Manager.
 *
 * SRM keeps redirects as a `redirect_rule` CPT with meta, not exposed over
 * REST — so this is the shim pattern (docs/for-plugin-authors.md): a small
 * REST collection over SRM's own public functions (srm_get_redirects /
 * srm_create_redirect / srm_delete_redirect_by_id), plus in-place edit via
 * the same meta keys SRM's admin screen writes. Regex/notes still live in
 * SRM's own screen for power users.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_srm_active() {
	return defined( 'SRM_VERSION' ) && function_exists( 'srm_get_redirects' ) && function_exists( 'srm_create_redirect' );
}

/**
 * Update an existing redirect_rule by post ID (source / target / status).
 *
 * srm_create_redirect upserts by source path, which would fork a new post if
 * the user renames the source — so edit-by-id writes meta directly, same as
 * SRM's own save_post handler.
 *
 * @param int    $id          redirect_rule post ID.
 * @param string $from        Source path.
 * @param string $to          Target path/URL.
 * @param int    $status_code HTTP status.
 * @return int|WP_Error
 */
function minn_admin_srm_update_redirect( $id, $from, $to, $status_code = 301 ) {
	$post = get_post( (int) $id );
	if ( ! $post || 'redirect_rule' !== $post->post_type ) {
		return new WP_Error( 'not_found', 'Redirect not found.', array( 'status' => 404 ) );
	}

	$allow_regex = (bool) get_post_meta( $post->ID, '_redirect_rule_from_regex', true );
	$from        = function_exists( 'srm_sanitize_redirect_from' )
		? srm_sanitize_redirect_from( $from, $allow_regex )
		: sanitize_text_field( $from );
	$to          = function_exists( 'srm_sanitize_redirect_to' )
		? srm_sanitize_redirect_to( $to )
		: esc_url_raw( $to );
	$code        = absint( $status_code );

	if ( '' === $from || '' === $to ) {
		return new WP_Error( 'invalid', 'Source and target are both required.', array( 'status' => 400 ) );
	}
	if ( function_exists( 'srm_get_valid_status_codes' )
		&& ! in_array( $code, srm_get_valid_status_codes(), true ) ) {
		return new WP_Error( 'invalid', 'Invalid status code.', array( 'status' => 400 ) );
	}

	update_post_meta( $post->ID, '_redirect_rule_from', wp_slash( $from ) );
	update_post_meta( $post->ID, '_redirect_rule_to', $to );
	update_post_meta( $post->ID, '_redirect_rule_status_code', $code );
	if ( function_exists( 'srm_flush_cache' ) ) {
		srm_flush_cache();
	}
	return (int) $post->ID;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_srm_active() ) {
		return $surfaces;
	}
	$surfaces['safe-redirect-manager'] = array(
		'label'      => 'Redirects',
		'family'     => 'redirects',
		'sub'        => 'Safe Redirect Manager',
		'icon'       => 'shuffle',
		'cap'        => 'manage_options',
		// Status card (v0.18.0): family parity with Redirection.
		'status'     => array( 'route' => 'minn-admin/v1/srm/status' ),
		'collection' => array(
			'route'    => 'minn-admin/v1/srm/redirects',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'search'   => 'search={q}',
			'columns'  => array(
				array( 'key' => 'from', 'label' => 'Source', 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'to', 'label' => 'Target', 'format' => 'mono', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'status_code', 'label' => 'Code', 'format' => 'mono', 'width' => '64px' ),
				array( 'key' => 'regex', 'label' => 'Regex', 'width' => '64px' ),
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
			'detail'   => array(
				'skip' => array( 'id', 'regex' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/srm/redirects/{id}',
					'method' => 'PUT',
					'fields' => array(
						array( 'key' => 'from', 'label' => 'Source URL', 'mono' => true ),
						array( 'key' => 'to', 'label' => 'Target URL', 'mono' => true ),
						array( 'key' => 'status_code', 'label' => 'HTTP status', 'type' => 'number' ),
					),
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
			'bulk'     => array(
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/srm/redirects/{id}',
					'confirm' => 'Delete the selected redirects permanently?',
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

	// Status card: counts over SRM's redirect_rule CPT — status-code mix and
	// regex-rule count from their own meta keys. SRM stores no hit counts,
	// so the card honestly stops at rules.
	register_rest_route( 'minn-admin/v1', '/srm/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			global $wpdb;
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'redirect_rule' AND post_status = 'publish'"
			);
			$rows = array(
				array( 'label' => 'Redirect rules', 'value' => (string) $total ),
			);
			$codes = $wpdb->get_results(
				"SELECT pm.meta_value AS code, COUNT(*) AS c FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_redirect_rule_status_code'
				 WHERE p.post_type = 'redirect_rule' AND p.post_status = 'publish'
				 GROUP BY pm.meta_value ORDER BY c DESC LIMIT 3"
			);
			if ( $codes ) {
				$rows[] = array(
					'label' => 'Status codes',
					'value' => implode( ' · ', array_map( function ( $r ) {
						return $r->c . '×' . $r->code;
					}, $codes ) ),
				);
			}
			$regex = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_redirect_rule_from_regex'
				 WHERE p.post_type = 'redirect_rule' AND p.post_status = 'publish' AND pm.meta_value = '1'"
			);
			if ( $regex ) {
				$rows[] = array( 'label' => 'Regex rules', 'value' => (string) $regex );
			}
			return rest_ensure_response( array( 'rows' => $rows ) );
		},
	) );

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
				$total = count( $items );
				$page  = max( 1, (int) ( $request['page'] ?: 1 ) );
				$per   = 25;
				$items = array_slice( $items, ( $page - 1 ) * $per, $per );
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
		array(
			'methods'             => 'PUT',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$result = minn_admin_srm_update_redirect(
					(int) $request['id'],
					(string) $request['from'],
					(string) $request['to'],
					(int) ( $request['status_code'] ?: 301 )
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return rest_ensure_response( array(
					'id'          => (int) $result,
					'from'        => (string) $request['from'],
					'to'          => (string) $request['to'],
					'status_code' => (int) ( $request['status_code'] ?: 301 ),
				) );
			},
		),
		array(
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
		),
	) );
} );
