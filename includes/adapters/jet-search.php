<?php
/**
 * Bundled adapter: JetSearch suggestions (Crocoblock, Tools).
 *
 * JetSearch records what visitors type into its search suggestions table,
 * {prefix}jet_search_suggestions (id, name, weight = how often it was
 * searched or how strongly it is boosted, parent = the parent suggestion's
 * id, "0" for none, term), and offers a manage-suggestions screen behind
 * manage_options. This surface is that screen's daily half: the list
 * ordered by weight, search, add and edit (name and weight), delete, and
 * the plugin's own remove-duplicates sweep (its exact two-statement SQL:
 * fold weights onto the lowest id per trimmed name, then drop the rest).
 * The suggestion widgets, sources and display settings stay in JetSearch.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

const MINN_ADMIN_JET_SEARCH_TABLE = 'jet_search_suggestions';

function minn_admin_jet_search_active() {
	return function_exists( 'jet_search' ) && class_exists( 'Jet_Search' );
}

function minn_admin_jet_search_can() {
	return current_user_can( 'manage_options' ); // their handlers' gate
}

function minn_admin_jet_search_table() {
	global $wpdb;
	return $wpdb->prefix . MINN_ADMIN_JET_SEARCH_TABLE;
}

function minn_admin_jet_search_has_table() {
	return Minn_Admin_Kernel_SQL::exists( MINN_ADMIN_JET_SEARCH_TABLE );
}

/**
 * id => name for the parents a page of rows points at, in one query. Their
 * manage screen stores a child's parent as the parent's id, which the list
 * printed as "71".
 */
function minn_admin_jet_search_parent_names( array $rows ) {
	$ids = array();
	foreach ( $rows as $r ) {
		$p = trim( (string) ( $r['parent'] ?? '' ) );
		if ( ctype_digit( $p ) && (int) $p > 0 ) {
			$ids[] = (int) $p;
		}
	}
	$ids = array_values( array_unique( $ids ) );
	if ( ! $ids ) {
		return array();
	}
	$names = array();
	foreach ( Minn_Admin_Kernel_SQL::select( MINN_ADMIN_JET_SEARCH_TABLE, array( 'id', 'name' ), array( 'id' => $ids ) ) as $row ) {
		$names[ (int) $row['id'] ] = (string) $row['name'];
	}
	return $names;
}

/** The parent as a name: '' for none or a parent since deleted. */
function minn_admin_jet_search_parent_label( array $r, array $names ) {
	$p = trim( (string) ( $r['parent'] ?? '' ) );
	if ( ! ctype_digit( $p ) ) {
		return $p;
	}
	return isset( $names[ (int) $p ] ) ? $names[ (int) $p ] : '';
}

function minn_admin_jet_search_item( array $r, $names = null ) {
	if ( null === $names ) {
		$names = minn_admin_jet_search_parent_names( array( $r ) );
	}
	return array(
		'id'     => (int) $r['id'],
		'name'   => (string) $r['name'],
		'weight' => (int) $r['weight'],
		// The weight as served, carried back by the edit form: JetSearch adds
		// to it on every search, so a save that did not change it must not
		// write back the count it loaded.
		'weight_seen' => (int) $r['weight'],
		'parent' => minn_admin_jet_search_parent_label( $r, (array) $names ),
	);
}

/** Whether another suggestion already has this text. */
function minn_admin_jet_search_taken( $name, $except = 0 ) {
	$where = array( 'name' => (string) $name );
	if ( $except > 0 ) {
		$where['id'] = array( 'not' => array( (int) $except ) );
	}
	return Minn_Admin_Kernel_SQL::count( MINN_ADMIN_JET_SEARCH_TABLE, $where ) > 0;
}

function minn_admin_jet_search_admin_url() {
	return admin_url( 'admin.php?page=jet-search-settings&subpage=jet-search-suggestions-settings' );
}

minn_admin_resource( 'jet-search/suggestions', function () {
	$fields = array(
		'id'          => 'int',
		'name'        => 'text',
		'weight'      => 'int',
		'weight_seen' => 'int',
		'parent'      => 'text',
	);
	return array(
		'active'  => 'minn_admin_jet_search_active',
		'gates'   => array( 'view' => 'minn_admin_jet_search_can' ),
		'labels'  => array( 'not_found' => __( 'Suggestion not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => MINN_ADMIN_JET_SEARCH_TABLE,
			'key'     => 'id',
			'columns' => array( 'id', 'name', 'weight', 'parent' ),
		),
		'list'    => array(
			'search' => array( 'columns' => array( 'name' ) ),
			'order'  => array( 'column' => 'weight', 'dir' => 'DESC' ),
			'sort'   => array( 'columns' => array( 'name', 'weight' ) ),
			'batch'  => 'minn_admin_jet_search_parent_names',
			'row'    => function ( array $row, array $q ) {
				return minn_admin_jet_search_item( $row, (array) $q['batch'] );
			},
			'fields' => $fields,
		),
		'item'    => array(
			'view'   => function ( array $row ) {
				return minn_admin_jet_search_item( $row );
			},
			'fields' => $fields,
		),
		'create'  => array(
			'gate'  => 'view',
			'run'   => function ( WP_REST_Request $request ) {
				$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
				if ( '' === $name ) {
					return new WP_Error( 'missing_name', __( 'A suggestion needs some text.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$masked = Minn_Admin_Egress::refuse_masks( array( 'name' => $name ), array() );
				if ( $masked ) {
					return $masked;
				}
				if ( minn_admin_jet_search_taken( $name ) ) {
					/* translators: %s: the suggestion text. */
					return new WP_Error( 'exists', sprintf( __( 'The suggestion "%s" already exists.', 'minn-admin' ), $name ), array( 'status' => 409 ) );
				}
				return Minn_Admin_Kernel_SQL::insert( MINN_ADMIN_JET_SEARCH_TABLE, array(
					'name'   => $name,
					'weight' => max( 0, (int) $request->get_param( 'weight' ) ),
					'parent' => '',
					'term'   => '',
				) );
			},
			'fail_code' => 'insert_failed',
			'fail'      => __( 'JetSearch could not save that suggestion.', 'minn-admin' ),
		),
		'actions' => array(
			'edit'   => array(
				'method' => 'POST',
				'item'   => true,
				'gate'   => 'view',
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					$data = array();
					// The dialog sends every field, an untouched name as stored.
					// JetSearch keeps an admin's name as typed (add-suggestion
					// trims only), so a name sent back unchanged is left alone:
					// cleaning it would rewrite '50%DEAL' or 'Shoes <50' on an
					// edit of the weight.
					$sent = $request->get_param( 'name' );
					// As served counts as stored: a credential copy masked. Their
					// table has no restore, so an edited name still carrying a
					// mask is refused.
					if ( null !== $sent && ! Minn_Admin::unchanged( is_scalar( $sent ) ? (string) $sent : $sent, (string) $row['name'] ) ) {
						$masked = Minn_Admin_Egress::refuse_masks( array( 'name' => $sent ), array( 'name' => (string) $row['name'] ) );
						if ( $masked ) {
							return $masked;
						}
						$name = sanitize_text_field( (string) $sent );
						if ( '' === $name ) {
							return new WP_Error( 'missing_name', __( 'A suggestion needs some text.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						if ( minn_admin_jet_search_taken( $name, $id ) ) {
							/* translators: %s: the suggestion text. */
							return new WP_Error( 'exists', sprintf( __( 'The suggestion "%s" already exists.', 'minn-admin' ), $name ), array( 'status' => 409 ) );
						}
						$data['name'] = $name;
					}
					$seen = $request->get_param( 'weight_seen' );
					if ( null !== $request->get_param( 'weight' ) && ( null === $seen || (int) $request->get_param( 'weight' ) !== (int) $seen ) ) {
						$data['weight'] = max( 0, (int) $request->get_param( 'weight' ) );
					}
					if ( $data ) {
						Minn_Admin_Kernel_SQL::update( MINN_ADMIN_JET_SEARCH_TABLE, 'id', $id, $data );
					}
					return $data;
				},
				// Saved when the row reads as written (an unchanged value
				// changes no row, so the count proves nothing). JetSearch adds
				// to the weight on every visitor search, so a weight at or
				// above the one written is the one written.
				'expect' => function ( $id, $fresh, $data ) {
					if ( ! is_array( $fresh ) || ! is_array( $data ) ) {
						return false;
					}
					if ( isset( $data['name'] ) && (string) $fresh['name'] !== (string) $data['name'] ) {
						return false;
					}
					return ! isset( $data['weight'] ) || (int) $fresh['weight'] >= (int) $data['weight'];
				},
				'fail'   => __( 'JetSearch\'s table did not keep that change.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh ) {
					return minn_admin_jet_search_item( $fresh );
				},
			),
			'delete' => array(
				'method' => 'DELETE',
				'gate'   => 'view',
				'run'    => function ( $id ) {
					return Minn_Admin_Kernel_SQL::delete( MINN_ADMIN_JET_SEARCH_TABLE, 'id', $id );
				},
				'expect' => 'gone',
				'done'   => __( 'Suggestion deleted.', 'minn-admin' ),
			),
		),
	);
} );

minn_admin_add_surface( 'jet-search', function () {
	return minn_admin_jet_search_active() && minn_admin_jet_search_can();
}, function () {
	$res  = 'jet-search/suggestions';
	$item = 'minn-admin/v1/' . $res . '/{id}';
	// JetSearch's suggestions. JetSmartFilters' filters and indexer are a
	// Tools item of their own (jet-smart-filters.php), as their menu is.
	return array(
		'label'      => __( 'Search', 'minn-admin' ),
		'sub'        => 'JetSearch',
		'plugin'     => 'jet-search',
		// JetSearch has no admin page of its own: its settings are a tab of
		// Crocoblock's JetPlugins Settings, which a menu tool hides with the
		// Crocoblock menu.
		'adminPages' => array( 'jet-dashboard-settings-page' ),
		'icon'       => 'search',
		'group'      => 'tools',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/jet-search/status' ),
		'collection' => Minn_Admin_Kernel::collection( $res, array(
			'viewLabel' => __( 'Suggestions', 'minn-admin' ),
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Suggestion', 'minn-admin' ), 'format' => 'title', 'sort' => 'name' ),
				array( 'key' => 'weight', 'label' => __( 'Weight', 'minn-admin' ), 'format' => 'num', 'width' => '110px', 'sort' => 'weight' ),
				array( 'key' => 'parent', 'label' => __( 'Parent', 'minn-admin' ) ),
			),
			'create'    => array(
				'label'  => __( 'Add suggestion', 'minn-admin' ),
				'route'  => 'minn-admin/v1/' . $res,
				'method' => 'POST',
				'fields' => array(
					array( 'key' => 'name', 'label' => __( 'Suggestion', 'minn-admin' ) ),
					array( 'key' => 'weight', 'label' => __( 'Weight', 'minn-admin' ), 'type' => 'number', 'value' => 1, 'required' => false ),
				),
			),
			'detail'    => array(
				'skip' => array( 'id', 'weight_seen' ),
				'edit' => array(
					'route'    => $item,
					'method'   => 'POST',
					'preserve' => array( 'weight_seen' ),
					'fields'   => array(
						array( 'key' => 'name', 'label' => __( 'Suggestion', 'minn-admin' ) ),
						array( 'key' => 'weight', 'label' => __( 'Weight', 'minn-admin' ), 'type' => 'number' ),
					),
				),
			),
			'actions'   => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => $item,
					'confirm' => __( 'Delete this suggestion?', 'minn-admin' ),
					'danger'  => true,
				),
				array( 'label' => __( 'Open in JetSearch ↗', 'minn-admin' ), 'href' => minn_admin_jet_search_admin_url() ),
			),
			'bulk'      => array(
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => $item,
					'confirm' => __( 'Delete the selected suggestions?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		) ),
	);
} );

// Their suggestions_remove_duplicates(), statement for statement. It is an
// ajax handler that checks its own nonce and answers with wp_send_json, so
// it cannot be called; the two statements stay as JetSearch writes them
// (a multi-table UPDATE over a grouped derived table, then a self-join
// DELETE), which the kernel's statements do not express.
minn_admin_endpoint( 'jet-search/dedupe', array(
	'active'  => 'minn_admin_jet_search_active',
	'methods' => 'POST',
	'gate'    => 'minn_admin_jet_search_can',
	'run'     => function () {
		if ( ! minn_admin_jet_search_has_table() ) {
			return array( 'ok' => true, 'message' => __( 'Nothing to merge.', 'minn-admin' ) );
		}
		global $wpdb;
		$t = minn_admin_jet_search_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$t} t1 INNER JOIN ( SELECT MIN(id) as min_id, TRIM(name) as trimmed_name, SUM(weight) as total_weight FROM {$t} GROUP BY trimmed_name HAVING COUNT(*) > 1 ) t2 ON t1.id = t2.min_id SET t1.weight = t2.total_weight" );
		$removed = $wpdb->query( "DELETE t1 FROM {$t} t1 INNER JOIN {$t} t2 WHERE t1.id > t2.id AND TRIM(t1.name) = TRIM(t2.name)" );
		// phpcs:enable
		if ( false === $removed ) {
			return new WP_Error( 'dedupe_failed', __( 'JetSearch could not merge the duplicates.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		return array(
			'ok'      => true,
			/* translators: %d: number of duplicate rows removed. */
			'message' => sprintf( _n( '%d duplicate merged.', '%d duplicates merged.', (int) $removed, 'minn-admin' ), (int) $removed ),
		);
	},
) );

minn_admin_endpoint( 'jet-search/status', array(
	'active' => 'minn_admin_jet_search_active',
	'gate'   => 'minn_admin_jet_search_can',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open JetSearch ↗', 'minn-admin' ), 'href' => minn_admin_jet_search_admin_url() ) );
		if ( ! minn_admin_jet_search_has_table() ) {
			return array( 'rows' => array( array( 'label' => __( 'Suggestions', 'minn-admin' ), 'value' => '—' ) ), 'actions' => $open );
		}
		global $wpdb;
		$total = Minn_Admin_Kernel::count( 'jet-search/suggestions' );
		$top   = Minn_Admin_Kernel_SQL::select( MINN_ADMIN_JET_SEARCH_TABLE, array( 'name', 'weight' ), array(), array( 'weight' => 'DESC', 'id' => 'ASC' ), 1 );
		$top   = $top ? $top[0] : null;
		// Duplicates by the dedupe's own rule (the same TRIM), so the card
		// offers the merge exactly when it would remove something.
		$t     = minn_admin_jet_search_table();
		$dupes = (int) $wpdb->get_var( "SELECT COUNT(*) - COUNT(DISTINCT TRIM(name)) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			array( 'label' => __( 'Top', 'minn-admin' ), 'value' => $top ? (string) $top['name'] : '—', 'hint' => $top ? sprintf( /* translators: %s: weight */ __( 'weight %s', 'minn-admin' ), number_format_i18n( (int) $top['weight'] ) ) : '' ),
			array( 'label' => __( 'Duplicates', 'minn-admin' ), 'value' => number_format_i18n( $dupes ) ),
		);
		return array( 'rows' => $rows, 'actions' => array_merge( $actions, $open ) );
	},
) );
