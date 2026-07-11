<?php
/**
 * Bundled adapter: 301 Redirects (WebFactory, slug eps-301-redirects).
 *
 * Rules live in the {prefix}redirects table: id, url_from, url_to, status,
 * type, count. status is '301' | '302' | '307' | 'inactive'; rows with
 * status '404' are the plugin's 404 log, not rules, and stay out of this
 * list exactly like the plugin's own table. type is 'post' when url_to is a
 * post ID (their matcher resolves it via get_permalink at redirect time),
 * 'url' otherwise; saving derives it from the target the same way their CSV
 * import does. HARD-WON: url_from must be stored WITHOUT a leading slash
 * (their _save_redirects strips the site root and ltrim's '/') — their
 * matcher rebuilds "home_url() . '/' . url_from", so a stored '/old-page'
 * becomes '//old-page' and never matches. The shim normalizes writes the
 * same way and displays rows with the slash restored.
 *
 * No REST surface upstream, so this is a table shim (prefix-scoped prepared
 * SQL only). Capability honors the plugin's own filter
 * `eps_301_redirects_capability` (default manage_options).
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_eps301_active() {
	return class_exists( 'EPS_Redirects' );
}

function minn_admin_eps301_cap() {
	return (string) apply_filters( 'eps_301_redirects_capability', 'manage_options' );
}

/** Normalize a row for the surface: resolve post-ID targets for display. */
function minn_admin_eps301_item( $row ) {
	$to      = (string) $row->url_to;
	$is_post = 'post' === (string) $row->type || is_numeric( $to );
	$target  = $to;
	if ( $is_post && is_numeric( $to ) ) {
		$link   = get_permalink( (int) $to );
		$target = $link ? $link : ( 'post #' . (int) $to );
	}
	// Stored source paths are slash-less (see the header note); show them
	// site-relative. Full URLs (foreign-host sources) pass through as-is.
	$from = (string) $row->url_from;
	if ( '' !== $from && ! preg_match( '#^https?://#i', $from ) ) {
		$from = '/' . ltrim( $from, '/' );
	}
	return array(
		'id'     => (int) $row->id,
		'from'   => $from,
		'to'     => $to,
		'target' => $target,
		'status' => (string) $row->status,
		'hits'   => (int) $row->count,
	);
}

/** Validate + coerce a submitted rule; WP_Error on bad input. */
function minn_admin_eps301_payload( WP_REST_Request $request ) {
	$from   = trim( (string) $request['from'] );
	$to     = trim( (string) $request['to'] );
	$status = trim( (string) $request['status'] );
	if ( '' === $from || '' === $to ) {
		return new WP_Error( 'invalid', 'Source and target are both required.', array( 'status' => 400 ) );
	}
	if ( ! in_array( $status, array( '301', '302', '307', 'inactive' ), true ) ) {
		$status = '301';
	}
	// Exactly their _save_redirects normalization: strip this site's root
	// from the source, then the leading slash (see the header note).
	$root = get_bloginfo( 'url' ) . '/';
	return array(
		'url_from' => trim( ltrim( str_replace( $root, '', $from ), '/' ) ),
		'url_to'   => $to,
		'status'   => $status,
		// Their convention: a numeric target is a post ID.
		'type'     => is_numeric( $to ) ? 'post' : 'url',
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_eps301_active() ) {
		return $surfaces;
	}
	$status_options = array(
		array( '301', '301 permanent' ),
		array( '302', '302 temporary' ),
		array( '307', '307 temporary' ),
		array( 'inactive', 'Inactive' ),
	);
	$surfaces['eps-301-redirects'] = array(
		'label'      => 'Redirects',
		'family'     => 'redirects',
		'sub'        => '301 Redirects',
		'icon'       => 'shuffle',
		'cap'        => minn_admin_eps301_cap(),
		'collection' => array(
			'route'    => 'minn-admin/v1/eps301/redirects',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'search'   => 'search={q}',
			'columns'  => array(
				array( 'key' => 'from', 'label' => 'Source', 'format' => 'title', 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'target', 'label' => 'Target', 'format' => 'mono', 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '110px' ),
				array( 'key' => 'hits', 'label' => 'Hits', 'num' => true, 'width' => '70px' ),
			),
			'create'   => array(
				'label'  => 'Add redirect',
				'route'  => 'minn-admin/v1/eps301/redirects',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'from', 'label' => 'Source URL', 'mono' => true, 'placeholder' => '/old-page' ),
					array( 'key' => 'to', 'label' => 'Target URL', 'mono' => true, 'placeholder' => '/new-page, https://… or a post ID' ),
					array( 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $status_options ),
				),
			),
			'detail'   => array(
				'skip' => array( 'id', 'target' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/eps301/redirects/{id}',
					'method' => 'PUT',
					'fields' => array(
						array( 'key' => 'from', 'label' => 'Source URL', 'mono' => true ),
						array( 'key' => 'to', 'label' => 'Target URL', 'mono' => true ),
						array( 'key' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $status_options ),
					),
				),
			),
			'actions'  => array(
				array(
					'label'   => 'Delete redirect',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/eps301/redirects/{id}',
					'confirm' => 'Delete this redirect permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_eps301_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( minn_admin_eps301_cap() );
	};
	$table = function () {
		global $wpdb;
		return $wpdb->prefix . 'redirects';
	};

	register_rest_route( 'minn-admin/v1', '/eps301/redirects', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$t        = $table();
				$search   = trim( (string) $request['search'] );
				$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
				$per_page = 25;
				$where    = "status != '404'";
				$args     = array();
				if ( '' !== $search ) {
					$like   = '%' . $wpdb->esc_like( $search ) . '%';
					$where .= ' AND (url_from LIKE %s OR url_to LIKE %s)';
					$args   = array( $like, $like );
				}
				// phpcs:disable WordPress.DB.PreparedSQL -- table name is prefix-built, where is prepared above
				$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$t} WHERE {$where}" );
				$sql   = "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
				$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );
				// phpcs:enable
				return rest_ensure_response( array(
					'items' => array_map( 'minn_admin_eps301_item', (array) $rows ),
					'total' => $total,
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$data = minn_admin_eps301_payload( $request );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$wpdb->insert( $table(), $data + array( 'count' => 0 ) );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table() . ' WHERE id = %d', $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				return rest_ensure_response( minn_admin_eps301_item( $row ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/eps301/redirects/(?P<id>\d+)', array(
		array(
			'methods'             => 'PUT',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$id  = (int) $request['id'];
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( ! $row ) {
					return new WP_Error( 'not_found', 'Redirect not found.', array( 'status' => 404 ) );
				}
				$data = minn_admin_eps301_payload( $request );
				if ( is_wp_error( $data ) ) {
					return $data;
				}
				$wpdb->update( $table(), $data, array( 'id' => $id ) );
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				return rest_ensure_response( minn_admin_eps301_item( $row ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
				global $wpdb;
				$wpdb->delete( $table(), array( 'id' => (int) $request['id'] ) );
				return rest_ensure_response( array( 'deleted' => (int) $request['id'] ) );
			},
		),
	) );
} );
