<?php
/**
 * Bundled adapter: JetSmartFilters (Crocoblock, Tools).
 *
 * Filters are `jet-smart-filters` posts (public false, not in REST, so
 * Content cannot list them) with the filter kind in `_filter_type`, the
 * query variable in `_query_var` and the data source in `_data_source`.
 * The indexer keeps per-term counts in {prefix}jet_smart_filters_indexer
 * and is switched on by the `use_indexed_filters` setting; its
 * index_filters() is public and marked as callable by third parties,
 * gated on their screen by edit_posts. This surface lists the filters
 * (read-only rows with Edit ↗ to their editor) and carries the indexer on
 * a status card: on/off, rows indexed, and Reindex. Filter authoring stays
 * in JetSmartFilters.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jsf_active() {
	return function_exists( 'jet_smart_filters' ) && post_type_exists( 'jet-smart-filters' );
}

function minn_admin_jsf_can() {
	$pto = get_post_type_object( 'jet-smart-filters' );
	return $pto ? current_user_can( $pto->cap->edit_posts ) : current_user_can( 'edit_posts' );
}

function minn_admin_jsf_indexer_on() {
	try {
		return (bool) filter_var( jet_smart_filters()->settings->get( 'use_indexed_filters' ), FILTER_VALIDATE_BOOLEAN );
	} catch ( \Throwable $e ) {
		return false;
	}
}

function minn_admin_jsf_types() {
	try {
		$out = array();
		foreach ( (array) jet_smart_filters()->filter_types->get_filter_types() as $id => $type ) {
			$out[ (string) $id ] = is_object( $type ) && method_exists( $type, 'get_name' ) ? (string) $type->get_name() : (string) $id;
		}
		return $out;
	} catch ( \Throwable $e ) {
		return array();
	}
}

function minn_admin_jsf_item( $post, $types ) {
	$kind = (string) get_post_meta( $post->ID, '_filter_type', true );
	return array(
		'id'       => (int) $post->ID,
		'title'    => '' !== $post->post_title ? $post->post_title : __( '(no title)', 'minn-admin' ),
		'kind'     => $kind ? ( $types[ $kind ] ?? $kind ) : '—',
		'queryVar' => (string) get_post_meta( $post->ID, '_query_var', true ),
		'source'   => (string) get_post_meta( $post->ID, '_data_source', true ),
		'status'   => $post->post_status,
		'modified' => mysql2date( 'c', $post->post_modified, false ),
		'editUrl'  => get_edit_post_link( $post->ID, 'raw' ),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_jsf_active() || ! minn_admin_jsf_can() ) {
		return $surfaces;
	}
	$surfaces['jet-smart-filters'] = array(
		'label'      => __( 'Filters', 'minn-admin' ),
		'sub'        => 'JetSmartFilters',
		'icon'       => 'filter',
		'group'      => 'tools',
		'cap'        => 'edit_posts',
		'status'     => array( 'route' => 'minn-admin/v1/jet-smart-filters/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/jet-smart-filters/filters',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'title', 'label' => __( 'Filter', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'kind', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'pill', 'width' => '140px' ),
				array( 'key' => 'queryVar', 'label' => __( 'Query variable', 'minn-admin' ) ),
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array( 'skip' => array( 'id', 'editUrl' ) ),
			'actions'   => array(
				array( 'label' => __( 'Edit ↗', 'minn-admin' ), 'href' => '{editUrl}' ),
				array(
					'label'   => __( 'Move to trash', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/jet-smart-filters/filters/{id}',
					'confirm' => __( 'Move this filter to the trash? Any widget using it stops filtering.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jsf_active() ) {
		return;
	}
	$perm = 'minn_admin_jsf_can';

	register_rest_route( 'minn-admin/v1', '/jet-smart-filters/filters', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$q = new WP_Query( array(
				'post_type'      => 'jet-smart-filters',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) ),
				'paged'          => max( 1, (int) $request->get_param( 'page' ) ?: 1 ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			) );
			$types = minn_admin_jsf_types();
			$items = array();
			foreach ( $q->posts as $p ) {
				$items[] = minn_admin_jsf_item( $p, $types );
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => (int) $q->found_posts ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-smart-filters/filters/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$p = get_post( (int) $request['id'] );
				if ( ! $p || 'jet-smart-filters' !== $p->post_type ) {
					return new WP_Error( 'not_found', __( 'Filter not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_jsf_item( $p, minn_admin_jsf_types() ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$p = get_post( (int) $request['id'] );
				if ( ! $p || 'jet-smart-filters' !== $p->post_type || ! current_user_can( 'delete_post', $p->ID ) ) {
					return new WP_Error( 'not_found', __( 'Filter not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! wp_trash_post( $p->ID ) ) {
					return new WP_Error( 'trash_failed', __( 'Could not trash that filter.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Filter moved to the trash.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-smart-filters/reindex', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function () {
			if ( ! minn_admin_jsf_indexer_on() ) {
				return new WP_Error( 'indexer_off', __( 'The indexer is switched off in JetSmartFilters settings.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			try {
				$indexer = jet_smart_filters()->indexer;
				if ( ! is_object( $indexer ) || ! method_exists( $indexer, 'index_filters' ) ) {
					return new WP_Error( 'indexer_missing', __( 'The indexer is not loaded.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$indexer->index_filters(); // their own rebuild, the one their screen's button runs
			} catch ( \Throwable $e ) {
				return new WP_Error( 'reindex_failed', __( 'JetSmartFilters could not rebuild the index.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			global $wpdb;
			$t = $wpdb->prefix . 'jet_smart_filters_indexer';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
			/* translators: %s: number of index rows. */
			return rest_ensure_response( array( 'ok' => true, 'message' => sprintf( __( 'Index rebuilt: %s rows.', 'minn-admin' ), number_format_i18n( $n ) ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-smart-filters/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			global $wpdb;
			$counts = wp_count_posts( 'jet-smart-filters' );
			$on     = minn_admin_jsf_indexer_on();
			$t      = $wpdb->prefix . 'jet_smart_filters_indexer';
			$has    = 0 === strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ), $t );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows   = $has ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ) : 0;
			$actions = array();
			if ( $on ) {
				$actions[] = array( 'label' => __( 'Reindex', 'minn-admin' ), 'route' => 'minn-admin/v1/jet-smart-filters/reindex', 'method' => 'POST', 'confirm' => __( 'Rebuild the filter index now? Large sites take a moment.', 'minn-admin' ) );
			}
			$actions[] = array( 'label' => __( 'Open JetSmartFilters ↗', 'minn-admin' ), 'href' => admin_url( 'edit.php?post_type=jet-smart-filters' ) );
			return rest_ensure_response( array(
				'rows'    => array(
					array( 'label' => __( 'Filters', 'minn-admin' ), 'value' => number_format_i18n( (int) ( $counts->publish ?? 0 ) ) ),
					array( 'label' => __( 'Indexer', 'minn-admin' ), 'value' => $on ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ), 'hint' => $on ? '' : __( 'switch it on in JetSmartFilters settings', 'minn-admin' ) ),
					array( 'label' => __( 'Index rows', 'minn-admin' ), 'value' => number_format_i18n( $rows ) ),
				),
				'actions' => $actions,
			) );
		},
	) );
} );
