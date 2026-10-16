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
 * No REST surface upstream, so this is a table shim over the kernel. Every
 * read and write leaves status 404 rows alone (their 404 log). Capability
 * honors the plugin's own filter `eps_301_redirects_capability` (default
 * manage_options).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_eps301_active() {
	return class_exists( 'EPS_Redirects' );
}

function minn_admin_eps301_cap() {
	return (string) apply_filters( 'eps_301_redirects_capability', 'manage_options' );
}

function minn_admin_eps301_can() {
	return current_user_can( minn_admin_eps301_cap() );
}

/** Normalize a row for the surface: resolve post-ID targets for display. */
function minn_admin_eps301_item( $row ) {
	$row     = (object) $row;
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

/**
 * Validate + coerce a submitted rule; WP_Error on bad input.
 *
 * @param WP_REST_Request $request The request.
 * @param array|null      $row     The stored row on an edit. The dialog sends
 *                                 every field, an untouched one as the row
 *                                 serves it; that one, or one the request
 *                                 leaves out, keeps its stored bytes. Their
 *                                 CSV import and their v1 migration store
 *                                 sources and targets undecoded
 *                                 ('caf%C3%A9', '?q=c%2B%2B'), so decoding
 *                                 and cleaning an untouched one changed what
 *                                 the rule matches or where it sends.
 * @return array|WP_Error
 */
function minn_admin_eps301_payload( WP_REST_Request $request, $row = null ) {
	$served = is_array( $row ) ? minn_admin_eps301_item( $row ) : null;
	$keep   = function ( $key ) use ( $request, $served ) {
		return null !== $served && ( null === $request[ $key ] || (string) $request[ $key ] === (string) $served[ $key ] );
	};
	// sanitize_text_field( urldecode( … ) ) on both sides, matching their own
	// writer, the same rule the Simple 301 Redirects adapter already follows.
	// Without it this table ends up holding values the plugin itself would
	// never store, and a percent-encoded source that their form would have
	// decoded is kept encoded here and then never matches.
	$from   = $keep( 'from' ) ? (string) $row['url_from'] : sanitize_text_field( urldecode( trim( (string) $request['from'] ) ) );
	$to     = $keep( 'to' ) ? (string) $row['url_to'] : sanitize_text_field( urldecode( trim( (string) $request['to'] ) ) );
	$status = $keep( 'status' ) ? (string) $row['status'] : trim( (string) $request['status'] );
	if ( '' === $from || '' === $to ) {
		return new WP_Error( 'invalid', __( 'Source and target are both required.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( ! $keep( 'status' ) && ! in_array( $status, array( '301', '302', '307', 'inactive' ), true ) ) {
		$status = '301';
	}
	// Exactly their _save_redirects normalization: strip this site's root
	// from the source, then the leading slash (see the header note). A
	// source kept as stored is already in their shape.
	$root = get_bloginfo( 'url' ) . '/';
	return array(
		'url_from' => $keep( 'from' ) ? $from : trim( ltrim( str_replace( $root, '', $from ), '/' ) ),
		'url_to'   => $to,
		'status'   => $status,
		// Their convention: a numeric target is a post ID.
		'type'     => $keep( 'to' ) ? (string) $row['type'] : ( is_numeric( $to ) ? 'post' : 'url' ),
	);
}

// Status 404 rows are their 404 log, not rules: no read or write here reaches them.
const MINN_ADMIN_EPS301_RULES = array( 'status' => array( 'not' => array( '404' ) ) );

minn_admin_resource( 'eps301/redirects', function () {
	return array(
		'active'  => 'minn_admin_eps301_active',
		'gates'   => array( 'view' => 'minn_admin_eps301_can' ),
		'labels'  => array( 'not_found' => __( 'Redirect not found.', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'redirects',
			'key'     => 'id',
			'columns' => array( 'id', 'url_from', 'url_to', 'status', 'type', 'count' ),
			'where'   => MINN_ADMIN_EPS301_RULES,
		),
		'list'    => array(
			'search' => array( 'columns' => array( 'url_from', 'url_to' ) ),
			'order'  => array( 'column' => 'id' ),
			'row'    => 'minn_admin_eps301_item',
			'fields' => array(
				'id'     => 'int',
				'from'   => 'text',
				'to'     => 'text',
				'target' => 'text',
				'status' => 'text',
				'hits'   => 'int',
			),
		),
		'create'  => array(
			'gate'  => 'view',
			'run'   => function ( WP_REST_Request $request ) {
				$data = minn_admin_eps301_payload( $request );
				return is_wp_error( $data ) ? $data : Minn_Admin_Kernel_SQL::insert( 'redirects', $data + array( 'count' => 0 ) );
			},
			'reply' => function ( $id, $fresh ) {
				return minn_admin_eps301_item( $fresh );
			},
		),
		'actions' => array(
			'edit'   => array(
				'method' => 'PUT',
				'gate'   => 'view',
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					$data = minn_admin_eps301_payload( $request, $row );
					if ( is_wp_error( $data ) ) {
						return $data;
					}
					Minn_Admin_Kernel_SQL::update( 'redirects', 'id', $id, $data, MINN_ADMIN_EPS301_RULES );
					return $data;
				},
				// Saved when the stored rule reads as written (an unchanged
				// rule changes no row, so the count proves nothing).
				'expect' => function ( $id, $fresh, $data ) {
					return is_array( $fresh ) && is_array( $data ) && (string) $fresh['url_from'] === $data['url_from'] && (string) $fresh['url_to'] === $data['url_to'] && (string) $fresh['status'] === $data['status'];
				},
				'reply'  => function ( $id, $fresh ) {
					return minn_admin_eps301_item( $fresh );
				},
			),
			'delete' => array(
				'method' => 'DELETE',
				'gate'   => 'view',
				'run'    => function ( $id ) {
					return Minn_Admin_Kernel_SQL::delete( 'redirects', 'id', $id, MINN_ADMIN_EPS301_RULES );
				},
				'expect' => 'gone',
				'reply'  => function ( $id ) {
					return array( 'deleted' => $id );
				},
			),
		),
	);
} );

// Status card: rule counts + lifetime hits from their `count` column. The
// 404 log is counted on its own: current versions keep it in the
// eps_redirects_404_log option (newest first, capped by their
// eps_301_max_404_logs filter, 50 by default), and older ones wrote it into
// the table as status 404 rows, which the list leaves out.
minn_admin_endpoint( 'eps301/status', array(
	'active' => 'minn_admin_eps301_active',
	'gate'   => 'minn_admin_eps301_can',
	'run'    => function () {
		if ( ! Minn_Admin_Kernel_SQL::exists( 'redirects' ) ) {
			return array( 'rows' => array() );
		}
		$id       = 'eps301/redirects';
		$live     = array( 'status' => array( '301', '302', '307' ) );
		$active   = Minn_Admin_Kernel::count( $id, $live );
		$inactive = Minn_Admin_Kernel::count( $id, array( 'status' => 'inactive' ) );
		$log      = get_option( 'eps_redirects_404_log', array() );
		$misses   = is_array( $log ) && $log ? count( $log ) : Minn_Admin_Kernel_SQL::count( 'redirects', array( 'status' => '404' ) );
		$top  = Minn_Admin_Kernel_SQL::select( 'redirects', array( 'url_from', 'count' ), $live + array( 'count' => array( 'op' => '>', 'value' => 0 ) ), array( 'count' => 'DESC' ), 1 );
		$rows = array_merge(
			array(
				array(
					'label' => __( 'Redirect rules', 'minn-admin' ),
					'value' => (string) $active,
					'hint'  => $inactive ? $inactive . ' inactive' : __( 'all enabled', 'minn-admin' ),
				),
			),
			minn_admin_redirects_hits(
				Minn_Admin_Kernel::sum( $id, 'count', array( 'status' => array( '301', '302', '307', 'inactive' ) ) ),
				$top ? '/' . ltrim( (string) $top[0]['url_from'], '/' ) : null,
				$top ? $top[0]['count'] : 0
			)
		);
		$rows[] = array( 'label' => __( '404 log', 'minn-admin' ), 'value' => number_format_i18n( $misses ) );
		return array( 'rows' => $rows );
	},
) );

minn_admin_add_surface( 'eps-301-redirects', 'minn_admin_eps301_active', function () {
	$res    = 'eps301/redirects';
	$status = array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'type' => 'select', 'options' => array(
		array( '301', '301 permanent' ),
		array( '302', '302 temporary' ),
		array( '307', '307 temporary' ),
		array( 'inactive', 'Inactive' ),
	) );
	list( $delete, $bulk ) = minn_admin_redirects_delete( $res );
	return minn_admin_redirects_surface( array(
		'sub'        => '301 Redirects',
		'plugin'     => 'eps-301-redirects',
		'cap'        => minn_admin_eps301_cap(),
		'status'     => 'eps301/status',
		'collection' => Minn_Admin_Kernel::collection( $res, array(
			'columns' => array(
				array( 'key' => 'from', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'target', 'label' => __( 'Target', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
				array( 'key' => 'hits', 'label' => __( 'Hits', 'minn-admin' ), 'format' => 'num', 'width' => '70px' ),
			),
			'create'  => array(
				'label'  => __( 'Add redirect', 'minn-admin' ),
				'route'  => 'minn-admin/v1/' . $res,
				'method' => 'POST',
				'fields' => array( minn_admin_redirects_source( 'from' ), minn_admin_redirects_target( 'to', true, __( '/new-page, https://… or a post ID', 'minn-admin' ) ), $status ),
			),
			'detail'  => array(
				'skip' => array( 'id', 'target' ),
				'edit' => array(
					'route'  => Minn_Admin_Kernel::action_path( $res, 'edit' ),
					'method' => 'PUT',
					'fields' => array( minn_admin_redirects_source( 'from', false ), minn_admin_redirects_target( 'to', false ), $status ),
				),
			),
			'actions' => array( $delete ),
			'bulk'    => array( $bulk ),
		) ),
	) );
} );
