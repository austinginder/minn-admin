<?php
/**
 * Bundled adapter: JetSearch suggestions (Crocoblock, Tools).
 *
 * JetSearch records what visitors type into its search suggestions table,
 * {prefix}jet_search_suggestions (id, name, weight = how often it was
 * searched or how strongly it is boosted, parent = a parent suggestion's
 * name, term), and offers a manage-suggestions screen behind
 * manage_options. This surface is that screen's daily half: the list
 * ordered by weight, search, add and edit (name and weight), delete, and
 * the plugin's own remove-duplicates sweep (its exact two-statement SQL:
 * fold weights onto the lowest id per trimmed name, then drop the rest).
 * The suggestion widgets, sources and display settings stay in JetSearch.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_search_active() {
	return function_exists( 'jet_search' ) && class_exists( 'Jet_Search' );
}

function minn_admin_jet_search_can() {
	return current_user_can( 'manage_options' ); // their handlers' gate
}

function minn_admin_jet_search_table() {
	global $wpdb;
	return $wpdb->prefix . 'jet_search_suggestions';
}

function minn_admin_jet_search_has_table() {
	global $wpdb;
	$t = minn_admin_jet_search_table();
	return 0 === strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ), $t );
}

function minn_admin_jet_search_row( $id ) {
	global $wpdb;
	$t = minn_admin_jet_search_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) );
}

function minn_admin_jet_search_item( $r ) {
	return array(
		'id'     => (int) $r->id,
		'name'   => (string) $r->name,
		'weight' => (int) $r->weight,
		'parent' => (string) $r->parent,
	);
}

function minn_admin_jet_search_admin_url() {
	return admin_url( 'admin.php?page=jet-search-settings&subpage=jet-search-suggestions-settings' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_jet_search_active() || ! minn_admin_jet_search_can() ) {
		return $surfaces;
	}
	// One Tools item for the site-search plugins: JetSearch's suggestions
	// here, JetSmartFilters' filters as a second view when it is active
	// (jet-smart-filters.php appends it), the indexer on this status card.
	$surfaces['jet-search'] = array(
		'label'      => __( 'Search', 'minn-admin' ),
		'sub'        => 'JetSearch',
		'plugin'     => 'jet-search',
		'icon'       => 'search',
		'group'      => 'tools',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/jet-search/status' ),
		'collection' => array(
			'viewLabel' => __( 'Suggestions', 'minn-admin' ),
			'route'     => 'minn-admin/v1/jet-search/suggestions',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'sortQuery' => 'orderby={by}&order={dir}',
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Suggestion', 'minn-admin' ), 'format' => 'title', 'sort' => 'name' ),
				array( 'key' => 'weight', 'label' => __( 'Weight', 'minn-admin' ), 'format' => 'num', 'width' => '110px', 'sort' => 'weight' ),
				array( 'key' => 'parent', 'label' => __( 'Parent', 'minn-admin' ) ),
			),
			'create'    => array(
				'label'  => __( 'Add suggestion', 'minn-admin' ),
				'route'  => 'minn-admin/v1/jet-search/suggestions',
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'name', 'label' => __( 'Suggestion', 'minn-admin' ) ),
					array( 'key' => 'weight', 'label' => __( 'Weight', 'minn-admin' ), 'type' => 'number', 'value' => 1, 'required' => false ),
				),
			),
			'detail'    => array(
				'skip' => array( 'id' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/jet-search/suggestions/{id}',
					'method' => 'POST',
					'fields' => array(
						array( 'key' => 'name', 'label' => __( 'Suggestion', 'minn-admin' ) ),
						array( 'key' => 'weight', 'label' => __( 'Weight', 'minn-admin' ), 'type' => 'number' ),
					),
				),
			),
			'actions'   => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/jet-search/suggestions/{id}',
					'confirm' => __( 'Delete this suggestion?', 'minn-admin' ),
					'danger'  => true,
				),
				array( 'label' => __( 'Open in JetSearch ↗', 'minn-admin' ), 'href' => minn_admin_jet_search_admin_url() ),
			),
			'bulk'      => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/jet-search/suggestions/{id}',
					'confirm' => __( 'Delete the selected suggestions?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_search_active() ) {
		return;
	}
	$perm = 'minn_admin_jet_search_can';

	register_rest_route( 'minn-admin/v1', '/jet-search/suggestions', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				if ( ! minn_admin_jet_search_has_table() ) {
					return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
				}
				global $wpdb;
				$t        = minn_admin_jet_search_table();
				$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
				$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
				$by       = in_array( $request->get_param( 'orderby' ), array( 'name', 'weight' ), true ) ? $request->get_param( 'orderby' ) : 'weight';
				$dir      = 'asc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'ASC' : 'DESC';
				$where    = '1=1';
				$params   = array();
				if ( '' !== $search ) {
					$where    = 'name LIKE %s';
					$params[] = '%' . $wpdb->esc_like( $search ) . '%';
				}
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
				$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
				$rows      = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$t} WHERE {$where} ORDER BY {$by} {$dir}, id DESC LIMIT %d OFFSET %d",
					array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
				) );
				// phpcs:enable
				return rest_ensure_response( array( 'items' => array_map( 'minn_admin_jet_search_item', $rows ? $rows : array() ), 'total' => $total ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$t      = minn_admin_jet_search_table();
				$name   = sanitize_text_field( (string) $request->get_param( 'name' ) );
				$weight = max( 0, (int) $request->get_param( 'weight' ) );
				if ( '' === $name ) {
					return new WP_Error( 'missing_name', __( 'A suggestion needs some text.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE name = %s", $name ) ) ) {
					/* translators: %s: the suggestion text. */
					return new WP_Error( 'exists', sprintf( __( 'The suggestion "%s" already exists.', 'minn-admin' ), $name ), array( 'status' => 409 ) );
				}
				$wpdb->insert( $t, array( 'name' => $name, 'weight' => $weight, 'parent' => '', 'term' => '' ), array( '%s', '%d', '%s', '%s' ) );
				if ( ! $wpdb->insert_id ) {
					return new WP_Error( 'insert_failed', __( 'JetSearch could not save that suggestion.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-search/suggestions/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$r = minn_admin_jet_search_has_table() ? minn_admin_jet_search_row( (int) Minn_Admin::path_param( $request ) ) : null;
				return $r ? rest_ensure_response( minn_admin_jet_search_item( $r ) ) : new WP_Error( 'not_found', __( 'Suggestion not found', 'minn-admin' ), array( 'status' => 404 ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$t  = minn_admin_jet_search_table();
				$id = (int) Minn_Admin::path_param( $request );
				$r  = minn_admin_jet_search_row( $id );
				if ( ! $r ) {
					return new WP_Error( 'not_found', __( 'Suggestion not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$data = array();
				if ( null !== $request->get_param( 'name' ) ) {
					$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
					if ( '' === $name ) {
						return new WP_Error( 'missing_name', __( 'A suggestion needs some text.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE id != %d AND name = %s", $id, $name ) ) ) {
						/* translators: %s: the suggestion text. */
						return new WP_Error( 'exists', sprintf( __( 'The suggestion "%s" already exists.', 'minn-admin' ), $name ), array( 'status' => 409 ) );
					}
					$data['name'] = $name;
				}
				if ( null !== $request->get_param( 'weight' ) ) {
					$data['weight'] = max( 0, (int) $request->get_param( 'weight' ) );
				}
				if ( $data ) {
					$wpdb->update( $t, $data, array( 'id' => $id ) );
				}
				return rest_ensure_response( minn_admin_jet_search_item( minn_admin_jet_search_row( $id ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$id = (int) Minn_Admin::path_param( $request );
				if ( ! minn_admin_jet_search_row( $id ) ) {
					return new WP_Error( 'not_found', __( 'Suggestion not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$wpdb->delete( minn_admin_jet_search_table(), array( 'id' => $id ), array( '%d' ) );
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Suggestion deleted.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-search/dedupe', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function () {
			if ( ! minn_admin_jet_search_has_table() ) {
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Nothing to merge.', 'minn-admin' ) ) );
			}
			global $wpdb;
			$t = minn_admin_jet_search_table();
			// Their suggestions_remove_duplicates(), statement for statement.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE {$t} t1 INNER JOIN ( SELECT MIN(id) as min_id, TRIM(name) as trimmed_name, SUM(weight) as total_weight FROM {$t} GROUP BY trimmed_name HAVING COUNT(*) > 1 ) t2 ON t1.id = t2.min_id SET t1.weight = t2.total_weight" );
			$removed = $wpdb->query( "DELETE t1 FROM {$t} t1 INNER JOIN {$t} t2 WHERE t1.id > t2.id AND TRIM(t1.name) = TRIM(t2.name)" );
			// phpcs:enable
			if ( false === $removed ) {
				return new WP_Error( 'dedupe_failed', __( 'JetSearch could not merge the duplicates.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'ok'      => true,
				/* translators: %d: number of duplicate rows removed. */
				'message' => sprintf( _n( '%d duplicate merged.', '%d duplicates merged.', (int) $removed, 'minn-admin' ), (int) $removed ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-search/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$open = array( array( 'label' => __( 'Open JetSearch ↗', 'minn-admin' ), 'href' => minn_admin_jet_search_admin_url() ) );
			if ( ! minn_admin_jet_search_has_table() ) {
				return rest_ensure_response( array( 'rows' => array( array( 'label' => __( 'Suggestions', 'minn-admin' ), 'value' => '—' ) ), 'actions' => $open ) );
			}
			global $wpdb;
			$t = minn_admin_jet_search_table();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
			$top   = $wpdb->get_row( "SELECT name, weight FROM {$t} ORDER BY weight DESC, id ASC LIMIT 1" );
			$dupes = (int) $wpdb->get_var( "SELECT COUNT(*) - COUNT(DISTINCT TRIM(name)) FROM {$t}" );
			// phpcs:enable
			$actions = array();
			if ( $dupes > 0 ) {
				$actions[] = array(
					'label'   => __( 'Merge duplicates', 'minn-admin' ),
					'route'   => 'minn-admin/v1/jet-search/dedupe',
					'method'  => 'POST',
					/* translators: %d: number of duplicate rows. */
					'confirm' => sprintf( _n( 'Merge %d duplicate into its first entry (weights are added up)?', 'Merge %d duplicates into their first entries (weights are added up)?', $dupes, 'minn-admin' ), $dupes ),
				);
			}
			$rows = array(
				array( 'label' => __( 'Suggestions', 'minn-admin' ), 'value' => number_format_i18n( $total ) ),
				array( 'label' => __( 'Top', 'minn-admin' ), 'value' => $top ? (string) $top->name : '—', 'hint' => $top ? sprintf( /* translators: %s: weight */ __( 'weight %s', 'minn-admin' ), number_format_i18n( (int) $top->weight ) ) : '' ),
				array( 'label' => __( 'Duplicates', 'minn-admin' ), 'value' => number_format_i18n( $dupes ) ),
			);
			// JetSmartFilters shares this card: its indexer row and Reindex.
			if ( function_exists( 'minn_admin_jsf_status_rows' ) && function_exists( 'minn_admin_jsf_active' ) && minn_admin_jsf_active() && minn_admin_jsf_can() ) {
				$jsf     = minn_admin_jsf_status_rows();
				$rows    = array_merge( $rows, $jsf['rows'] );
				$actions = array_merge( $actions, $jsf['actions'] );
			}
			return rest_ensure_response( array( 'rows' => $rows, 'actions' => array_merge( $actions, $open ) ) );
		},
	) );
} );
