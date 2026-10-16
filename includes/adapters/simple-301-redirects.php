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

minn_admin_add_surface( 'simple-301-redirects', 'minn_admin_s301_active', function () {
	list( $delete, $bulk ) = minn_admin_redirects_delete( 'minn-admin/v1/s301/redirects/{id}' );
	return minn_admin_redirects_surface( array(
		'sub'        => 'Simple 301 Redirects',
		'plugin'     => 'simple-301-redirects',
		'cap'        => 'manage_options',
		'status'     => 's301/status',
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
				'fields' => array( minn_admin_redirects_source( 'from' ), minn_admin_redirects_target( 'to' ) ),
			),
			'detail'   => array(
				'skip' => array( 'id' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/s301/redirects/{id}',
					'method' => 'PUT',
					'fields' => array( minn_admin_redirects_source( 'from', false ), minn_admin_redirects_target( 'to', false ) ),
				),
			),
			'actions'  => array( $delete ),
			'bulk'     => array( $bulk ),
		),
	) );
} );

function minn_admin_s301_can() {
	return current_user_can( 'manage_options' );
}

/**
 * A submitted rule, sanitized as their own writer (Admin/Ajax.php) does, or
 * why not. On an edit the dialog sends both fields, an untouched one as
 * stored: that one, or one the request leaves out, is kept as stored, since
 * cleaning it rewrites a source or target an older version or an import
 * left ('/caf%C3%A9' became '/caf', which also renamed the rule).
 *
 * @param WP_REST_Request $request The request.
 * @param string|null     $from_was The stored source on an edit.
 * @param string|null     $to_was   The stored target on an edit.
 * @return array|WP_Error array( from, to )
 */
function minn_admin_s301_rule( WP_REST_Request $request, $from_was = null, $to_was = null ) {
	$keep = function ( $key, $was ) use ( $request ) {
		return null !== $was && ( null === $request[ $key ] || (string) $request[ $key ] === (string) $was );
	};
	$from = $keep( 'from', $from_was ) ? (string) $from_was : sanitize_text_field( trim( (string) $request['from'] ) );
	$to   = $keep( 'to', $to_was ) ? (string) $to_was : sanitize_text_field( trim( (string) $request['to'] ) );
	if ( '' === $from || '' === $to ) {
		return new WP_Error( 'invalid', __( 'Source and target are both required.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return array( $from, $to );
}

/** The rule as the list shows it. */
function minn_admin_s301_item( $from, $to ) {
	return array( 'id' => minn_admin_s301_encode_id( $from ), 'from' => (string) $from, 'to' => (string) $to );
}

// The whole store is one option keyed by source, so a rule's id is its source
// (base64url): handler-tier endpoints the kernel gates, ids the pattern holds.
// Status card: no hits and no log, so the honest card is rule count + the
// wildcard toggle.
minn_admin_endpoint( 's301/status', array(
	'active' => 'minn_admin_s301_active',
	'gate'   => 'minn_admin_s301_can',
	'run'    => function () {
		$wildcards = get_option( '301_redirects_wildcard', false );
		return array(
			'rows' => array(
				array( 'label' => __( 'Redirect rules', 'minn-admin' ), 'value' => (string) count( (array) get_option( '301_redirects', array() ) ) ),
				array(
					'label' => __( 'Wildcards', 'minn-admin' ),
					'value' => $wildcards ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ),
					'hint'  => $wildcards ? '* patterns match in sources' : '',
				),
			),
		);
	},
) );

minn_admin_endpoint( 's301/redirects', array(
	'active' => 'minn_admin_s301_active',
	'gate'   => 'minn_admin_s301_can',
	'run'    => function ( WP_REST_Request $request ) {
		$search = strtolower( trim( (string) $request['search'] ) );
		$items  = array();
		foreach ( (array) get_option( '301_redirects', array() ) as $from => $to ) {
			if ( '' === $search || false !== strpos( strtolower( $from . ' ' . $to ), $search ) ) {
				$items[] = minn_admin_s301_item( $from, $to );
			}
		}
		$page = max( 1, (int) ( $request['page'] ?: 1 ) );
		return array( 'items' => array_values( array_slice( $items, ( $page - 1 ) * 25, 25 ) ), 'total' => count( $items ) );
	},
) );

minn_admin_endpoint( 's301/redirects', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_s301_active',
	'gate'    => 'minn_admin_s301_can',
	'run'     => function ( WP_REST_Request $request ) {
		$rule = minn_admin_s301_rule( $request );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		list( $from, $to ) = $rule;
		$rows              = (array) get_option( '301_redirects', array() );
		// The option is keyed by source, so writing one that is already there
		// would replace its rule; their own add refuses it.
		if ( array_key_exists( $from, $rows ) ) {
			return new WP_Error( 's301_exists', __( 'A redirect from that address already exists. Edit it instead.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$rows[ $from ] = $to;
		update_option( '301_redirects', $rows );
		return minn_admin_s301_item( $from, $to );
	},
) );

minn_admin_endpoint( 's301/redirects/(?P<id>[A-Za-z0-9\-_]+)', array(
	'methods' => 'PUT',
	'active'  => 'minn_admin_s301_active',
	'gate'    => 'minn_admin_s301_can',
	'run'     => function ( WP_REST_Request $request, array $ctx ) {
		$old  = minn_admin_s301_decode_id( $ctx['id'] );
		$rows = (array) get_option( '301_redirects', array() );
		if ( ! array_key_exists( $old, $rows ) ) {
			return new WP_Error( 'not_found', __( 'Redirect not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		$rule = minn_admin_s301_rule( $request, (string) $old, (string) $rows[ $old ] );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		list( $from, $to ) = $rule;
		// Renaming the source: drop the old key, write the new one; never onto
		// a source another rule already has, which that write would replace.
		if ( $old !== $from ) {
			if ( array_key_exists( $from, $rows ) ) {
				return new WP_Error( 's301_exists', __( 'A redirect from that address already exists. Edit it instead.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			unset( $rows[ $old ] );
		}
		$rows[ $from ] = $to;
		update_option( '301_redirects', $rows );
		return minn_admin_s301_item( $from, $to );
	},
) );

minn_admin_endpoint( 's301/redirects/(?P<id>[A-Za-z0-9\-_]+)', array(
	'methods' => 'DELETE',
	'active'  => 'minn_admin_s301_active',
	'gate'    => 'minn_admin_s301_can',
	'run'     => function ( WP_REST_Request $request, array $ctx ) {
		$from = minn_admin_s301_decode_id( $ctx['id'] );
		$rows = (array) get_option( '301_redirects', array() );
		if ( isset( $rows[ $from ] ) ) {
			unset( $rows[ $from ] );
			update_option( '301_redirects', $rows );
		}
		return array( 'deleted' => $from );
	},
) );
