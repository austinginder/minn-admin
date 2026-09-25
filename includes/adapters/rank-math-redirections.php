<?php
/**
 * Bundled adapter: Rank Math Redirections + 404 Monitor (redirects family).
 *
 * Two Rank Math modules, one surface: the Redirections list (main view) and
 * the 404 log (extra view), plus a status card. Every read and write goes
 * through Rank Math's own classes:
 *
 * - Redirections: RankMath\Redirections\DB::get_redirections() for the list
 *   (their status/search/orderby semantics), Redirection::from() →
 *   is_infinite_loop() → save() for create/edit, which is exactly their REST
 *   save path (sanitize_source, url normalization, cache priming, update-or-
 *   insert on a matching source), followed by their
 *   `rank_math/redirection/saved` action. Status changes and deletes are
 *   DB::change_status() / DB::delete(), the calls their own list actions make.
 * - 404 Monitor: RankMath\Monitor\DB::get_logs(), delete_log(), clear_logs()
 *   and get_stats(). "Redirect…" on a log row builds the same exact-match
 *   source from the logged URI that their 404 screen's Redirect action does.
 *
 * The `sources` column is a PHP-serialized list of {pattern, comparison,
 * ignore}; it is read through Minn_Admin::decode_serialized() (arrays only,
 * never objects), never unserialize().
 *
 * Gates mirror theirs: rank_math_redirections for redirects,
 * rank_math_404_monitor for the log (both granted by their role manager).
 * Rank Math loads no module at all until the site is connected to a Rank Math
 * account or the setup step is skipped, and then only the modules switched
 * on; their is_module_active() (which checks the loaded registry) is the
 * gate, so a surface never appears for a module that is not running.
 *
 * Timestamps (created, updated, last_accessed, accessed) are
 * current_time( 'mysql' ): site-local, emitted raw. A never-hit redirect
 * stores the zero date, which is emitted as null.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_rm_module( $id ) {
	return class_exists( '\RankMath\Helper' )
		&& method_exists( '\RankMath\Helper', 'is_module_active' )
		&& \RankMath\Helper::is_module_active( $id );
}

function minn_admin_rm_redirects_ready() {
	return minn_admin_rm_module( 'redirections' ) && class_exists( '\RankMath\Redirections\DB' ) && class_exists( '\RankMath\Redirections\Redirection' );
}

function minn_admin_rm_404_ready() {
	return minn_admin_rm_module( '404-monitor' ) && class_exists( '\RankMath\Monitor\DB' );
}

function minn_admin_rm_can_redirects() {
	return minn_admin_rm_redirects_ready() && current_user_can( 'rank_math_redirections' );
}

function minn_admin_rm_can_404() {
	return minn_admin_rm_404_ready() && current_user_can( 'rank_math_404_monitor' );
}

/** Site-local datetime, or null for their zero date. */
function minn_admin_rm_date( $value ) {
	$value = (string) $value;
	return ( '' === $value || 0 === strpos( $value, '0000-00-00' ) ) ? null : $value;
}

function minn_admin_rm_codes() {
	return array(
		'301' => __( '301 Permanent', 'minn-admin' ),
		'302' => __( '302 Temporary', 'minn-admin' ),
		'307' => __( '307 Temporary', 'minn-admin' ),
		'410' => __( '410 Content deleted', 'minn-admin' ),
		'451' => __( '451 Unavailable for legal reasons', 'minn-admin' ),
	);
}

/**
 * Type choices for an edit form: the five Rank Math offers plus any other
 * code already stored on the site (their importers copy 303, 308 and the like
 * verbatim). Without them the form would seed an imported 308 as 301 and a
 * target-only edit would silently change the redirect's type.
 */
function minn_admin_rm_edit_codes() {
	$codes = minn_admin_rm_codes();
	if ( ! minn_admin_rm_redirects_ready() ) {
		return $codes;
	}
	global $wpdb;
	$stored = $wpdb->get_col( "SELECT DISTINCT header_code FROM {$wpdb->prefix}rank_math_redirections" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	foreach ( (array) $stored as $code ) {
		$code = (string) (int) $code;
		if ( '0' !== $code && ! isset( $codes[ $code ] ) ) {
			/* translators: %s: an HTTP status code such as 308. */
			$codes[ $code ] = sprintf( __( '%s (imported)', 'minn-admin' ), $code );
		}
	}
	return $codes;
}

/** Their stored sources, decoded without unserialize(). */
function minn_admin_rm_sources( $raw ) {
	$list = Minn_Admin::decode_serialized( $raw, array() );
	$out  = array();
	foreach ( is_array( $list ) ? $list : array() as $s ) {
		if ( is_array( $s ) && isset( $s['pattern'] ) && '' !== (string) $s['pattern'] ) {
			$out[] = array(
				'pattern'    => (string) $s['pattern'],
				'comparison' => isset( $s['comparison'] ) ? (string) $s['comparison'] : 'exact',
				'ignore'     => isset( $s['ignore'] ) ? (string) $s['ignore'] : '',
			);
		}
	}
	return $out;
}

function minn_admin_rm_redirect_item( $row ) {
	$sources = minn_admin_rm_sources( $row['sources'] ?? '' );
	$labels  = array();
	foreach ( $sources as $s ) {
		$labels[] = 'exact' === $s['comparison'] ? $s['pattern'] : $s['pattern'] . ' (' . $s['comparison'] . ')';
	}
	$first = $labels ? $labels[0] : '';
	if ( count( $labels ) > 1 ) {
		/* translators: 1: the first source URL. 2: how many more sources the redirect has. */
		$first = sprintf( __( '%1$s +%2$d more', 'minn-admin' ), $first, count( $labels ) - 1 );
	}
	return array(
		'id'            => (int) $row['id'],
		'source'        => $first,
		'sources'       => implode( "\n", $labels ),
		'url_to'        => (string) ( $row['url_to'] ?? '' ),
		'header_code'   => (string) ( $row['header_code'] ?? '' ),
		'hits'          => (int) ( $row['hits'] ?? 0 ),
		'status'        => (string) ( $row['status'] ?? '' ),
		'trashed'       => 'trashed' === ( $row['status'] ?? '' ) ? '1' : '0',
		'created'       => minn_admin_rm_date( $row['created'] ?? '' ),
		'last_accessed' => minn_admin_rm_date( $row['last_accessed'] ?? '' ),
	);
}

/**
 * Save through their REST save path: from() → loop check → save() → action.
 *
 * @return int|WP_Error Redirect id.
 */
function minn_admin_rm_save_redirect( $data ) {
	$code = (string) ( $data['header_code'] ?? '301' );
	if ( ! isset( minn_admin_rm_codes()[ $code ] ) ) {
		return new WP_Error( 'minn_rm_code', __( 'Pick one of the redirect types Rank Math offers.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$needs_target = ! in_array( $code, array( '410', '451' ), true );
	if ( $needs_target && '' === trim( (string) ( $data['url_to'] ?? '' ) ) ) {
		return new WP_Error( 'minn_rm_target', __( 'Add the address to redirect to.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$settings    = array(
		'id'          => isset( $data['id'] ) ? absint( $data['id'] ) : '',
		'sources'     => $data['sources'],
		'url_to'      => $needs_target ? (string) $data['url_to'] : '',
		'header_code' => $code,
		'status'      => $data['status'] ?? 'active',
	);
	$redirection = \RankMath\Redirections\Redirection::from( $settings );
	// Every source can be rejected by their sanitizer (a bare "/", an external
	// URL); their loop check then reads a missing key, so refuse first.
	if ( ! $redirection->has_sources() ) {
		return new WP_Error( 'minn_rm_source', __( 'Add at least one valid source address.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( $redirection->is_infinite_loop() ) {
		return new WP_Error( 'minn_rm_loop', __( 'That redirect would send the address back to itself. Check the source and target.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$id = $redirection->save();
	if ( false === $id || ! $id ) {
		return new WP_Error( 'minn_rm_source', __( 'Add at least one valid source address.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	do_action( 'rank_math/redirection/saved', $redirection, $settings );
	return (int) $id;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	$redirects = minn_admin_rm_can_redirects();
	$monitor   = minn_admin_rm_can_404();
	if ( ! $redirects && ! $monitor ) {
		return $surfaces;
	}
	$code_options = array();
	foreach ( minn_admin_rm_codes() as $value => $label ) {
		$code_options[] = array( $value, $label );
	}
	$edit_code_options = array();
	foreach ( minn_admin_rm_edit_codes() as $value => $label ) {
		$edit_code_options[] = array( (string) $value, $label );
	}
	$redirect_fields = array(
		array( 'key' => 'url_to', 'label' => __( 'Target URL', 'minn-admin' ), 'mono' => true, 'placeholder' => __( '/new-page or https://…', 'minn-admin' ), 'required' => false ),
		array( 'key' => 'header_code', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $code_options, 'value' => '301' ),
	);

	$log_view = array(
		'viewLabel' => __( '404 log', 'minn-admin' ),
		'route'     => 'minn-admin/v1/rankmath/404',
		'pageQuery' => 'per_page=25&page={page}',
		'sortQuery' => 'orderby={by}&order={dir}',
		'itemsKey'  => 'items',
		'totalKey'  => 'total',
		'search'    => 'search={q}',
		'columns'   => array(
			array( 'key' => 'uri', 'label' => __( 'Address', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.6fr)', 'sort' => 'uri' ),
			array( 'key' => 'times_accessed', 'label' => __( 'Hits', 'minn-admin' ), 'format' => 'num', 'width' => '72px', 'sort' => 'times_accessed' ),
			array( 'key' => 'referer', 'label' => __( 'Referrer', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1fr)' ),
			array( 'key' => 'accessed', 'label' => __( 'Last hit', 'minn-admin' ), 'format' => 'ago', 'sort' => 'accessed' ),
		),
		'detail'    => array(),
		'actions'   => array_values( array_filter( array(
			$redirects ? array(
				'label'  => __( 'Redirect…', 'minn-admin' ),
				'route'  => 'minn-admin/v1/rankmath/404/{id}/redirect',
				'method' => 'POST',
				'list'   => true,
				'fields' => $redirect_fields,
			) : null,
			array(
				'label'   => __( 'Delete log entry', 'minn-admin' ),
				'route'   => 'minn-admin/v1/rankmath/404/{id}',
				'method'  => 'DELETE',
				'confirm' => __( 'Delete this 404 log entry?', 'minn-admin' ),
				'danger'  => true,
			),
		) ) ),
		'bulk'      => array(
			array(
				'label'   => __( 'Delete', 'minn-admin' ),
				'route'   => 'minn-admin/v1/rankmath/404/{id}',
				'method'  => 'DELETE',
				'confirm' => __( 'Delete the selected 404 log entries?', 'minn-admin' ),
				'danger'  => true,
			),
		),
	);

	$redirect_coll = array(
		'route'     => 'minn-admin/v1/rankmath/redirects',
		'pageQuery' => 'per_page=25&page={page}',
		'sortQuery' => 'orderby={by}&order={dir}',
		'itemsKey'  => 'items',
		'totalKey'  => 'total',
		'search'    => 'search={q}',
		'tabs'      => array(
			'param'    => 'status',
			'static'   => array(
				array( 'active', __( 'Active', 'minn-admin' ) ),
				array( 'inactive', __( 'Inactive', 'minn-admin' ) ),
				array( 'trashed', __( 'Trash', 'minn-admin' ) ),
			),
			'allLabel' => __( 'All', 'minn-admin' ),
		),
		'create'    => array(
			'label'    => __( 'Add redirect', 'minn-admin' ),
			'route'    => 'minn-admin/v1/rankmath/redirects',
			'method'   => 'POST',
			// Exact-match source like their quick form; contains/regex and
			// multi-source rules stay on Rank Math's own screen.
			'fields'   => array_merge(
				array( array( 'key' => 'source', 'label' => __( 'Source URL', 'minn-admin' ), 'mono' => true, 'placeholder' => __( '/old-page', 'minn-admin' ) ) ),
				$redirect_fields
			),
		),
		'columns'   => array(
			array( 'key' => 'source', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
			array( 'key' => 'url_to', 'label' => __( 'Target', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.4fr)', 'sort' => 'url_to' ),
			array( 'key' => 'header_code', 'label' => __( 'Code', 'minn-admin' ), 'format' => 'mono', 'width' => '64px', 'sort' => 'header_code' ),
			array( 'key' => 'hits', 'label' => __( 'Hits', 'minn-admin' ), 'format' => 'num', 'width' => '72px', 'sort' => 'hits' ),
			array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '96px' ),
			array( 'key' => 'last_accessed', 'label' => __( 'Last hit', 'minn-admin' ), 'format' => 'ago', 'sort' => 'last_accessed' ),
		),
		'detail'    => array(
			'skip' => array( 'trashed', 'source' ),
			'edit' => array(
				'route'  => 'minn-admin/v1/rankmath/redirects/{id}',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'url_to', 'label' => __( 'Target URL', 'minn-admin' ), 'mono' => true ),
					array( 'key' => 'header_code', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $edit_code_options ),
				),
			),
		),
		'actions'   => array(
			array( 'label' => __( 'Activate', 'minn-admin' ), 'route' => 'minn-admin/v1/rankmath/redirects/{id}/status', 'method' => 'POST', 'body' => array( 'status' => 'active' ), 'when' => array( 'key' => 'status', 'equals' => 'inactive' ) ),
			array( 'label' => __( 'Deactivate', 'minn-admin' ), 'route' => 'minn-admin/v1/rankmath/redirects/{id}/status', 'method' => 'POST', 'body' => array( 'status' => 'inactive' ), 'when' => array( 'key' => 'status', 'equals' => 'active' ) ),
			array( 'label' => __( 'Move to Trash', 'minn-admin' ), 'route' => 'minn-admin/v1/rankmath/redirects/{id}/status', 'method' => 'POST', 'body' => array( 'status' => 'trashed' ), 'when' => array( 'key' => 'trashed', 'equals' => '0' ) ),
			array( 'label' => __( 'Restore', 'minn-admin' ), 'route' => 'minn-admin/v1/rankmath/redirects/{id}/status', 'method' => 'POST', 'body' => array( 'status' => 'active' ), 'when' => array( 'key' => 'trashed', 'equals' => '1' ) ),
			array(
				'label'   => __( 'Delete permanently', 'minn-admin' ),
				'route'   => 'minn-admin/v1/rankmath/redirects/{id}',
				'method'  => 'DELETE',
				'confirm' => __( 'Delete this redirect permanently?', 'minn-admin' ),
				'danger'  => true,
				'when'    => array( 'key' => 'trashed', 'equals' => '1' ),
			),
		),
		'bulk'      => array(
			array( 'label' => __( 'Activate', 'minn-admin' ), 'route' => 'minn-admin/v1/rankmath/redirects/{id}/status', 'method' => 'POST', 'body' => array( 'status' => 'active' ) ),
			array( 'label' => __( 'Deactivate', 'minn-admin' ), 'route' => 'minn-admin/v1/rankmath/redirects/{id}/status', 'method' => 'POST', 'body' => array( 'status' => 'inactive' ) ),
			array( 'label' => __( 'Move to Trash', 'minn-admin' ), 'route' => 'minn-admin/v1/rankmath/redirects/{id}/status', 'method' => 'POST', 'body' => array( 'status' => 'trashed' ) ),
		),
	);

	$surfaces['rank-math-redirections'] = array(
		'label'      => __( 'Redirects', 'minn-admin' ),
		'family'     => 'redirects',
		'sub'        => 'Rank Math',
		'plugin'     => 'seo-by-rank-math',
		'icon'       => 'shuffle',
		// Each view and route gates on its own Rank Math capability.
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/rankmath/status' ),
		'collection' => $redirects ? $redirect_coll : $log_view,
	);
	if ( $redirects && $monitor ) {
		$surfaces['rank-math-redirections']['views'] = array( $log_view );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_rm_redirects_ready() && ! minn_admin_rm_404_ready() ) {
		return;
	}
	$can_redirects = 'minn_admin_rm_can_redirects';
	$can_404       = 'minn_admin_rm_can_404';
	$can_either    = function () {
		return minn_admin_rm_can_redirects() || minn_admin_rm_can_404();
	};

	register_rest_route( 'minn-admin/v1', '/rankmath/status', array(
		'methods'             => 'GET',
		'permission_callback' => $can_either,
		'callback'            => function () {
			$rows    = array();
			$actions = array();
			if ( minn_admin_rm_can_redirects() ) {
				$counts = \RankMath\Redirections\DB::get_counts();
				$stats  = \RankMath\Redirections\DB::get_stats();
				$hint   = array();
				if ( ! empty( $counts['inactive'] ) ) {
					/* translators: %d: number of inactive redirects. */
					$hint[] = sprintf( _n( '%d inactive', '%d inactive', (int) $counts['inactive'], 'minn-admin' ), (int) $counts['inactive'] );
				}
				if ( ! empty( $counts['trashed'] ) ) {
					/* translators: %d: number of redirects in the trash. */
					$hint[] = sprintf( _n( '%d in trash', '%d in trash', (int) $counts['trashed'], 'minn-admin' ), (int) $counts['trashed'] );
				}
				$rows[]    = array(
					'label' => __( 'Active redirects', 'minn-admin' ),
					'value' => number_format_i18n( (int) ( $counts['active'] ?? 0 ) ),
					'hint'  => $hint ? implode( ', ', $hint ) : '',
				);
				$rows[]    = array( 'label' => __( 'Redirect hits, all time', 'minn-admin' ), 'value' => number_format_i18n( (int) ( is_object( $stats ) ? $stats->hits : 0 ) ) );
				$actions[] = array( 'label' => __( 'Open Rank Math redirections ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=rank-math-redirections' ) );
			}
			if ( minn_admin_rm_can_404() ) {
				$stats = \RankMath\Monitor\DB::get_stats();
				$total = (int) ( is_object( $stats ) ? $stats->total : 0 );
				$rows[] = array(
					'label' => __( '404s logged', 'minn-admin' ),
					'value' => number_format_i18n( $total ),
					/* translators: %s: number of times logged 404 addresses were requested. */
					'hint'  => $total ? sprintf( __( '%s hits', 'minn-admin' ), number_format_i18n( (int) $stats->hits ) ) : '',
				);
				$top = \RankMath\Monitor\DB::get_logs( array( 'orderby' => 'times_accessed', 'order' => 'DESC', 'limit' => 1 ) );
				if ( ! empty( $top['logs'][0] ) ) {
					$rows[] = array(
						'label' => __( 'Most-hit 404', 'minn-admin' ),
						'value' => (string) $top['logs'][0]['uri'],
						/* translators: %s: hit count. */
						'hint'  => sprintf( __( '%s hits', 'minn-admin' ), number_format_i18n( (int) $top['logs'][0]['times_accessed'] ) ),
					);
				}
				if ( $total ) {
					$actions[] = array(
						'label'   => __( 'Clear 404 log', 'minn-admin' ),
						'route'   => 'minn-admin/v1/rankmath/404/clear',
						'method'  => 'POST',
						'confirm' => __( 'Clear every entry from the 404 log?', 'minn-admin' ),
						'danger'  => true,
					);
				}
				$actions[] = array( 'label' => __( 'Open 404 Monitor ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=rank-math-404-monitor' ) );
			}
			return rest_ensure_response( array( 'rows' => $rows, 'actions' => $actions ) );
		},
	) );

	if ( minn_admin_rm_redirects_ready() ) {
		register_rest_route( 'minn-admin/v1', '/rankmath/redirects', array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_redirects,
				'callback'            => function ( WP_REST_Request $request ) {
					$status = sanitize_key( (string) $request->get_param( 'status' ) );
					$res    = \RankMath\Redirections\DB::get_redirections( array(
						'status'  => in_array( $status, array( 'active', 'inactive', 'trashed' ), true ) ? $status : 'any',
						'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
						'paged'   => max( 1, (int) $request->get_param( 'page' ) ),
						'limit'   => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) ),
						'orderby' => sanitize_key( (string) ( $request->get_param( 'orderby' ) ?: 'id' ) ),
						'order'   => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
					) );
					return rest_ensure_response( array(
						'items' => array_map( 'minn_admin_rm_redirect_item', (array) ( $res['redirections'] ?? array() ) ),
						'total' => (int) ( $res['count'] ?? 0 ),
					) );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_redirects,
				'callback'            => function ( WP_REST_Request $request ) {
					$source = trim( (string) $request->get_param( 'source' ) );
					if ( '' === $source ) {
						return new WP_Error( 'minn_rm_source', __( 'Add the source address to redirect.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$id = minn_admin_rm_save_redirect( array(
						'sources'     => array( array( 'pattern' => $source, 'comparison' => 'exact' ) ),
						'url_to'      => (string) $request->get_param( 'url_to' ),
						'header_code' => (string) ( $request->get_param( 'header_code' ) ?: '301' ),
					) );
					if ( is_wp_error( $id ) ) {
						return $id;
					}
					return rest_ensure_response( array( 'id' => $id ) );
				},
			),
		) );

		register_rest_route( 'minn-admin/v1', '/rankmath/redirects/(?P<id>\d+)', array(
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_redirects,
				'callback'            => function ( WP_REST_Request $request ) {
					$row = \RankMath\Redirections\DB::get_redirection_by_id( (int) $request['id'] );
					if ( ! $row ) {
						return new WP_Error( 'not_found', __( 'Redirect not found', 'minn-admin' ), array( 'status' => 404 ) );
					}
					// The edit form carries target and type only. Sources (and
					// their contains/regex comparisons and case flag) are
					// written back exactly as stored: re-running them through
					// their sanitizer on every save can rewrite them (percent-
					// decoding, subdirectory stripping) where nobody sees it.
					// A field the request omits keeps its stored value.
					$stored = minn_admin_rm_sources( $row['sources'] );
					if ( ! $stored ) {
						return new WP_Error( 'minn_rm_source', __( 'This redirect has no source Minn can read. Edit it in Rank Math.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$code = $request->has_param( 'header_code' ) ? (string) $request->get_param( 'header_code' ) : (string) $row['header_code'];
					// Any of their five, or the code this redirect already has
					// (an imported 308 stays editable and stays a 308).
					if ( ! isset( minn_admin_rm_codes()[ $code ] ) && (string) (int) $row['header_code'] !== $code ) {
						return new WP_Error( 'minn_rm_code', __( 'Pick one of the redirect types Rank Math offers.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$needs_target = ! in_array( $code, array( '410', '451' ), true );
					$url_to       = $request->has_param( 'url_to' ) ? (string) $request->get_param( 'url_to' ) : (string) $row['url_to'];
					if ( $needs_target && '' === trim( $url_to ) ) {
						return new WP_Error( 'minn_rm_target', __( 'Add the address to redirect to.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					// Their model normalizes the destination (relative → home
					// URL) and runs the loop check; its re-sanitized sources are
					// used for nothing but that check.
					$probe = \RankMath\Redirections\Redirection::from( array(
						'id'          => (int) $row['id'],
						'sources'     => $stored,
						'url_to'      => $needs_target ? $url_to : '',
						'header_code' => $code,
						'status'      => (string) $row['status'],
					) );
					if ( $probe->has_sources() && $probe->is_infinite_loop() ) {
						return new WP_Error( 'minn_rm_loop', __( 'That redirect would send the address back to itself. Check the source and target.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$settings = array(
						'id'          => (int) $row['id'],
						'sources'     => $stored,
						'url_to'      => $needs_target ? (string) $probe->url_to : '',
						'header_code' => $code,
						'status'      => (string) $row['status'],
					);
					\RankMath\Redirections\DB::update( $settings );
					do_action( 'rank_math/redirection/saved', $probe, $settings );
					$id = (int) $row['id'];
					return rest_ensure_response( minn_admin_rm_redirect_item( \RankMath\Redirections\DB::get_redirection_by_id( $id ) ) );
				},
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $can_redirects,
				'callback'            => function ( WP_REST_Request $request ) {
					$id = (int) $request['id'];
					if ( ! \RankMath\Redirections\DB::get_redirection_by_id( $id ) ) {
						return new WP_Error( 'not_found', __( 'Redirect not found', 'minn-admin' ), array( 'status' => 404 ) );
					}
					\RankMath\Redirections\DB::delete( array( $id ) );
					return rest_ensure_response( array( 'deleted' => true, 'message' => __( 'Redirect deleted.', 'minn-admin' ) ) );
				},
			),
		) );

		register_rest_route( 'minn-admin/v1', '/rankmath/redirects/(?P<id>\d+)/status', array(
			'methods'             => 'POST',
			'permission_callback' => $can_redirects,
			'callback'            => function ( WP_REST_Request $request ) {
				$status = sanitize_key( (string) $request->get_param( 'status' ) );
				if ( ! in_array( $status, array( 'active', 'inactive', 'trashed' ), true ) ) {
					return new WP_Error( 'minn_rm_status', __( 'Unknown redirect status.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$id = (int) $request['id'];
				if ( ! \RankMath\Redirections\DB::get_redirection_by_id( $id ) ) {
					return new WP_Error( 'not_found', __( 'Redirect not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				\RankMath\Redirections\DB::change_status( array( $id ), $status );
				return rest_ensure_response( array( 'id' => $id, 'status' => $status ) );
			},
		) );
	}

	if ( minn_admin_rm_404_ready() ) {
		register_rest_route( 'minn-admin/v1', '/rankmath/404', array(
			'methods'             => 'GET',
			'permission_callback' => $can_404,
			'callback'            => function ( WP_REST_Request $request ) {
				$orderby = sanitize_key( (string) ( $request->get_param( 'orderby' ) ?: 'accessed' ) );
				$res     = \RankMath\Monitor\DB::get_logs( array(
					'search'  => sanitize_text_field( (string) $request->get_param( 'search' ) ),
					'paged'   => max( 1, (int) $request->get_param( 'page' ) ),
					'limit'   => min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) ),
					'orderby' => in_array( $orderby, array( 'id', 'uri', 'accessed', 'times_accessed' ), true ) ? $orderby : 'accessed',
					'order'   => 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC',
				) );
				$items = array();
				foreach ( (array) ( $res['logs'] ?? array() ) as $log ) {
					$items[] = array(
						'id'             => (int) $log['id'],
						'uri'            => (string) $log['uri'],
						'times_accessed' => (int) $log['times_accessed'],
						'referer'        => (string) $log['referer'],
						'user_agent'     => (string) $log['user_agent'],
						'accessed'       => minn_admin_rm_date( $log['accessed'] ),
					);
				}
				return rest_ensure_response( array( 'items' => $items, 'total' => (int) ( $res['count'] ?? 0 ) ) );
			},
		) );

		register_rest_route( 'minn-admin/v1', '/rankmath/404/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'permission_callback' => $can_404,
			'callback'            => function ( WP_REST_Request $request ) {
				$count = \RankMath\Monitor\DB::delete_log( array( (int) $request['id'] ) );
				if ( ! $count ) {
					return new WP_Error( 'not_found', __( 'Log entry not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( array( 'deleted' => true, 'message' => __( 'Log entry deleted.', 'minn-admin' ) ) );
			},
		) );

		register_rest_route( 'minn-admin/v1', '/rankmath/404/clear', array(
			'methods'             => 'POST',
			'permission_callback' => $can_404,
			'callback'            => function () {
				$count = (int) \RankMath\Monitor\DB::get_count();
				\RankMath\Monitor\DB::clear_logs();
				/* translators: %d: number of 404 log entries removed. */
				return rest_ensure_response( array( 'message' => sprintf( _n( 'Cleared %d log entry.', 'Cleared %d log entries.', $count, 'minn-admin' ), $count ) ) );
			},
		) );

		// Redirect a logged 404: their 404 screen's Redirect action builds an
		// exact-match source from the logged URI, and so does this. Needs the
		// redirections capability too, like creating any redirect.
		register_rest_route( 'minn-admin/v1', '/rankmath/404/(?P<id>\d+)/redirect', array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return minn_admin_rm_can_404() && minn_admin_rm_can_redirects();
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$res = \RankMath\Monitor\DB::get_logs( array( 'ids' => array( (int) $request['id'] ), 'limit' => 1 ) );
				$log = $res['logs'][0] ?? null;
				if ( ! $log ) {
					return new WP_Error( 'not_found', __( 'Log entry not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$id = minn_admin_rm_save_redirect( array(
					'sources'     => array( array( 'pattern' => (string) $log['uri'], 'comparison' => 'exact' ) ),
					'url_to'      => (string) $request->get_param( 'url_to' ),
					'header_code' => (string) ( $request->get_param( 'header_code' ) ?: '301' ),
				) );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				/* translators: %s: the address that now redirects. */
				return rest_ensure_response( array( 'id' => $id, 'message' => sprintf( __( '%s now redirects.', 'minn-admin' ), (string) $log['uri'] ) ) );
			},
		) );
	}
} );
