<?php
/**
 * Bundled adapter: Safe Redirect Manager.
 *
 * SRM keeps redirects as a `redirect_rule` CPT with meta, not exposed over
 * REST — so this is the shim pattern (docs/for-plugin-authors.md): a small
 * REST collection over SRM's own public functions (srm_get_redirects /
 * srm_create_redirect), plus in-place edit via the same meta keys SRM's
 * admin screen writes. Regex/notes still live in SRM's own screen for
 * power users.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_srm_active() {
	return defined( 'SRM_VERSION' ) && function_exists( 'srm_get_redirects' ) && function_exists( 'srm_create_redirect' );
}

/**
 * The capability SRM itself gates redirects on.
 *
 * SRM maps EVERY meta cap of the redirect_rule CPT onto one filterable
 * custom capability (SRM_Post_Type::get_redirect_capability, applied in its
 * register_post_type call), and grants it to administrator at registration.
 * So manage_options coincides with it by default and is neither necessary
 * nor sufficient once a site uses the filter: locking redirects down to a
 * narrower cap left every manage_options holder writing them here, and the
 * ordinary delegation of granting an editor srm_manage_redirects worked on
 * SRM's screen while Minn refused it. The sibling redirect adapters already
 * read their vendor's hook this way.
 *
 * @return string
 */
function minn_admin_srm_cap() {
	return (string) apply_filters( 'srm_restrict_to_capability', 'srm_manage_redirects' );
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
 * @return array|WP_Error The values as sanitized and stored: id, from, to, status_code.
 */
function minn_admin_srm_update_redirect( $id, $from, $to, $status_code = 301 ) {
	$post = get_post( (int) $id );
	if ( ! $post || 'redirect_rule' !== $post->post_type ) {
		return new WP_Error( 'not_found', __( 'Redirect not found.', 'minn-admin' ), array( 'status' => 404 ) );
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
		return new WP_Error( 'invalid', __( 'Source and target are both required.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( function_exists( 'srm_get_valid_status_codes' )
		&& ! in_array( $code, srm_get_valid_status_codes(), true ) ) {
		return new WP_Error( 'invalid', __( 'Invalid status code.', 'minn-admin' ), array( 'status' => 400 ) );
	}

	update_post_meta( $post->ID, '_redirect_rule_from', wp_slash( $from ) );
	update_post_meta( $post->ID, '_redirect_rule_to', $to );
	update_post_meta( $post->ID, '_redirect_rule_status_code', $code );
	if ( function_exists( 'srm_flush_cache' ) ) {
		srm_flush_cache();
	}
	return array( 'id' => (int) $post->ID, 'from' => $from, 'to' => $to, 'status_code' => $code );
}

function minn_admin_srm_can() {
	return current_user_can( minn_admin_srm_cap() );
}

/** One redirect_rule as the list reads it, or null for any other post. */
function minn_admin_srm_rule( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || 'redirect_rule' !== $post->post_type ) {
		return null;
	}
	return array(
		'id'          => (int) $post->ID,
		'from'        => (string) get_post_meta( $post->ID, '_redirect_rule_from', true ),
		'to'          => (string) get_post_meta( $post->ID, '_redirect_rule_to', true ),
		'status_code' => (int) get_post_meta( $post->ID, '_redirect_rule_status_code', true ),
		'regex'       => get_post_meta( $post->ID, '_redirect_rule_from_regex', true ) ? 'yes' : '',
	);
}

/** Their redirect_rule posts with one of the rule's meta rows, for the status card's counts. */
const MINN_ADMIN_SRM_META = array(
	'core'  => 'posts',
	'joins' => array( 'pm' => array( 'core' => 'postmeta', 'on' => array( 'post_id' => 'e.ID' ) ) ),
);

minn_admin_add_surface( 'safe-redirect-manager', 'minn_admin_srm_active', function () {
	$res = 'srm/redirects';
	list( $delete, $bulk ) = minn_admin_redirects_delete( $res );
	return minn_admin_redirects_surface( array(
		'sub'        => 'Safe Redirect Manager',
		'plugin'     => 'safe-redirect-manager',
		// Same cap the routes use, so the nav and the routes cannot drift.
		'cap'        => minn_admin_srm_cap(),
		'status'     => 'srm/status',
		'collection' => Minn_Admin_Kernel::collection( $res, array(
			'columns' => array(
				array( 'key' => 'from', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'to', 'label' => __( 'Target', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'status_code', 'label' => __( 'Code', 'minn-admin' ), 'format' => 'mono', 'width' => '64px' ),
				array( 'key' => 'regex', 'label' => __( 'Regex', 'minn-admin' ), 'width' => '64px' ),
			),
			'create'  => array(
				'label'    => __( 'Add redirect', 'minn-admin' ),
				'route'    => 'minn-admin/v1/' . $res,
				'method'   => 'POST',
				'defaults' => array( 'status_code' => 301 ),
				'fields'   => array( minn_admin_redirects_source( 'from' ), minn_admin_redirects_target( 'to' ), minn_admin_redirects_code( 'status_code' ) ),
			),
			'detail'  => array(
				'skip' => array( 'id', 'regex' ),
				'edit' => array(
					'route'  => Minn_Admin_Kernel::action_path( $res, 'edit' ),
					'method' => 'PUT',
					'fields' => array( minn_admin_redirects_source( 'from', false ), minn_admin_redirects_target( 'to', false ), minn_admin_redirects_code( 'status_code', false ) ),
				),
			),
			'actions' => array( $delete ),
			'bulk'    => array( $bulk ),
		) ),
	) );
} );

minn_admin_resource( 'srm/redirects', function () {
	return array(
		'active'  => 'minn_admin_srm_active',
		'gates'   => array( 'view' => 'minn_admin_srm_can' ),
		'labels'  => array( 'not_found' => __( 'Redirect not found.', 'minn-admin' ) ),
		'source'  => array(
			// Their own reader, in their order (published rules).
			'rows'   => function () {
				$items = array();
				foreach ( (array) srm_get_redirects() as $r ) {
					$items[] = array(
						'id'          => (int) ( $r['ID'] ?? 0 ),
						'from'        => (string) ( $r['redirect_from'] ?? '' ),
						'to'          => (string) ( $r['redirect_to'] ?? '' ),
						'status_code' => (int) ( $r['status_code'] ?? 302 ),
						'regex'       => ! empty( $r['enable_regex'] ) ? 'yes' : '',
					);
				}
				return $items;
			},
			// One rule by id: never another post type, which an edit or a
			// force-delete through core would otherwise reach.
			'get'    => 'minn_admin_srm_rule',
			'exists' => function ( $id ) {
				return null !== minn_admin_srm_rule( $id );
			},
		),
		'list'    => array(
			'search' => array( 'columns' => array( 'from', 'to' ) ),
			'fields' => array(
				'id'          => 'int',
				'from'        => 'text',
				'to'          => 'text',
				'status_code' => 'int',
				'regex'       => 'text',
			),
		),
		'create'  => array(
			'gate'   => 'view',
			// The rule they made reads back as a redirect_rule; a taken source
			// never got this far.
			'expect' => function ( $id, $fresh ) {
				return is_array( $fresh ) && $id === $fresh['id'];
			},
			'run'    => function ( WP_REST_Request $request ) {
				// srm_create_redirect() is an upsert: for a source that already
				// has a rule it rewrites that rule in place (republished,
				// de-regexed, notes wiped). Adding is adding, so a source that
				// is already taken is refused rather than silently replaced.
				if ( function_exists( 'srm_sanitize_redirect_from' ) ) {
					$from = srm_sanitize_redirect_from( (string) $request['from'] );
					if ( '' !== (string) $from && Minn_Admin_Kernel_SQL::count( array( 'core' => 'postmeta' ), array( 'meta_key' => '_redirect_rule_from', 'meta_value' => $from ) ) ) {
						return new WP_Error( 'srm_exists', __( 'A redirect from that address already exists. Edit it instead.', 'minn-admin' ), array( 'status' => 400 ) );
					}
				}
				$id = srm_create_redirect( (string) $request['from'], (string) $request['to'], (int) ( $request['status_code'] ?: 301 ) );
				return is_wp_error( $id ) ? new WP_Error( 'create_failed', $id->get_error_message(), array( 'status' => 400 ) ) : (int) $id;
			},
			'reply' => function ( $id ) {
				return array( 'created' => $id );
			},
		),
		'actions' => array(
			// Edit by id through the meta keys their own save_post handler
			// writes: srm_create_redirect() would fork a new rule on a rename.
			'edit'   => array(
				'method' => 'PUT',
				'gate'   => 'view',
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					return minn_admin_srm_update_redirect( $id, (string) $request['from'], (string) $request['to'], (int) ( $request['status_code'] ?: 301 ) );
				},
				'expect' => function ( $id, $fresh, $saved ) {
					return is_array( $fresh ) && is_array( $saved ) && $fresh['from'] === $saved['from'] && $fresh['to'] === $saved['to'] && $fresh['status_code'] === $saved['status_code'];
				},
				'reply'  => function ( $id, $fresh ) {
					return array( 'id' => $id, 'from' => $fresh['from'], 'to' => $fresh['to'], 'status_code' => $fresh['status_code'] );
				},
			),
			// SRM ships no delete helper: a force-delete through core, of a
			// redirect_rule only, then the cache flush their screen makes
			// (without it the removed rule redirects until the cache expires).
			'delete' => array(
				'method' => 'DELETE',
				'gate'   => 'view',
				'run'    => function ( $id ) {
					wp_delete_post( $id, true );
					if ( function_exists( 'srm_flush_cache' ) ) {
						srm_flush_cache();
					}
					return true;
				},
				'expect' => 'gone',
				'reply'  => function ( $id ) {
					return array( 'deleted' => $id );
				},
			),
		),
	);
} );

// Status card: counts over their redirect_rule posts, the status-code mix and
// regex rules from their own meta keys. SRM stores no hit counts, so the card
// honestly stops at rules.
minn_admin_endpoint( 'srm/status', array(
	'active' => 'minn_admin_srm_active',
	'gate'   => 'minn_admin_srm_can',
	'run'    => function () {
		$live  = array( 'e.post_type' => 'redirect_rule', 'e.post_status' => 'publish' );
		$rows  = array(
			array( 'label' => __( 'Redirect rules', 'minn-admin' ), 'value' => (string) Minn_Admin_Kernel_SQL::count( array( 'core' => 'posts' ), array( 'post_type' => 'redirect_rule', 'post_status' => 'publish' ) ) ),
		);
		$codes = Minn_Admin_Kernel_SQL::counts( MINN_ADMIN_SRM_META, 'pm.meta_value', $live + array( 'pm.meta_key' => '_redirect_rule_status_code' ), 3 );
		if ( $codes ) {
			$rows[] = array(
				'label' => __( 'Status codes', 'minn-admin' ),
				'value' => implode( ' · ', array_map( function ( $c ) {
					return $c['count'] . '×' . $c['value'];
				}, $codes ) ),
			);
		}
		$regex = Minn_Admin_Kernel_SQL::count( MINN_ADMIN_SRM_META, $live + array( 'pm.meta_key' => '_redirect_rule_from_regex', 'pm.meta_value' => '1' ) );
		if ( $regex ) {
			$rows[] = array( 'label' => __( 'Regex rules', 'minn-admin' ), 'value' => (string) $regex );
		}
		// Export (SRM 2.3+): their own signed admin-ajax download, minted per
		// status load for the viewer, so their nonce and their
		// srm_manage_redirects check stay the gate.
		$actions = array();
		if ( class_exists( 'SRM_Export' ) ) {
			foreach ( array( 'csv' => __( 'Export CSV ↗', 'minn-admin' ), 'json' => __( 'Export JSON ↗', 'minn-admin' ) ) as $format => $label ) {
				$actions[] = array(
					'label' => $label,
					'href'  => add_query_arg(
						array(
							'action'        => 'srm_export',
							'export_format' => $format,
							'_wpnonce'      => wp_create_nonce( 'srm_export' ),
						),
						admin_url( 'admin-ajax.php' )
					),
				);
			}
		}
		return array( 'rows' => $rows, 'actions' => $actions );
	},
) );
