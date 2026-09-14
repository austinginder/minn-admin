<?php
/**
 * Bundled adapter: JetEngine Custom Content Types (Crocoblock).
 *
 * A Custom Content Type is JetEngine's table-backed record: its definition
 * sits in jet_post_types with status 'content-type', its rows in
 * {prefix}jet_cct_{slug} with one column per field plus the service
 * columns (_ID, cct_status publish|draft, cct_author_id, cct_created,
 * cct_modified, cct_single_post_id). Nothing of that is a post, and the
 * only listing is their Vue screen. This is the one Minn surface with a
 * view per type: the first type is the collection, the rest are views,
 * each with columns from the type's own fields (the same mapper the meta
 * box panel uses), search over its text columns, a create form and an
 * edit form over the simple fields, publish / draft, and delete.
 *
 * Every write goes through the type's own item handler
 * (Factory::get_item_handler()->update_item() / raw_delete_item()), so
 * their per-field sanitizers, the linked single post, the excluded
 * columns and their update/delete hooks all run exactly as from their
 * screen; reads are prepared SELECTs on the type's table. Access is the
 * type's own capability (Factory::user_has_access(), default
 * manage_options, filterable). Relations, repeaters and other complex
 * fields count as locked and link out.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_cct_active() {
	return class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) && function_exists( 'minn_admin_jet_map_field' );
}

/** slug => Factory for every registered type the current user may open. */
function minn_admin_jet_cct_types() {
	if ( ! minn_admin_jet_cct_active() ) {
		return array();
	}
	try {
		$m = \Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager;
		if ( ! is_object( $m ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) $m->get_content_types() as $slug => $factory ) {
			if ( is_object( $factory ) && method_exists( $factory, 'user_has_access' ) && $factory->user_has_access() ) {
				$out[ (string) $slug ] = $factory;
			}
		}
		return $out;
	} catch ( \Throwable $e ) {
		return array();
	}
}

function minn_admin_jet_cct_type( $slug ) {
	$types = minn_admin_jet_cct_types();
	return isset( $types[ $slug ] ) ? $types[ $slug ] : null;
}

function minn_admin_jet_cct_table( $slug ) {
	global $wpdb;
	return $wpdb->prefix . 'jet_cct_' . $slug;
}

/** The type's fields mapped onto the form vocabulary (name-keyed) + locked count. */
function minn_admin_jet_cct_fields( $factory ) {
	$raw    = isset( $factory->fields ) && is_array( $factory->fields ) ? $factory->fields : array();
	$fields = array();
	$locked = 0;
	foreach ( $raw as $f ) {
		if ( ! is_array( $f ) || ( isset( $f['object_type'] ) && 'field' !== $f['object_type'] ) || in_array( $f['type'] ?? '', array( 'html', 'heading' ), true ) ) {
			continue;
		}
		$m = minn_admin_jet_map_field( $f );
		if ( ! $m ) {
			$locked++;
			continue;
		}
		$fields[ $m['name'] ] = $m;
	}
	return array( 'fields' => $fields, 'locked' => $locked );
}

/** A mapped field as the surface form engine expects it. */
function minn_admin_jet_cct_form_field( $f ) {
	$sf = array( 'key' => $f['name'], 'label' => $f['label'], 'type' => $f['type'], 'required' => false );
	foreach ( array( 'min', 'max', 'step', 'help' ) as $k ) {
		if ( isset( $f[ $k ] ) ) {
			$sf[ $k ] = $f[ $k ];
		}
	}
	if ( isset( $f['choices'] ) ) {
		$sf['options'] = array();
		foreach ( (array) $f['choices'] as $v => $l ) {
			$sf['options'][] = array( (string) $v, (string) $l );
		}
	}
	return $sf;
}

function minn_admin_jet_cct_row( $slug, $id ) {
	global $wpdb;
	$t = minn_admin_jet_cct_table( $slug );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE _ID = %d", (int) $id ), ARRAY_A );
}

/**
 * Decode a stored CCT cell without instantiating classes.
 *
 * JetEngine decodes these cells through jet_engine_safe_unserialize(), and only
 * for columns its factory declares array-backed. Reading the table directly
 * means doing that work here: a CCT row can be inserted by an unauthenticated
 * visitor through a JetFormBuilder "Insert CCT item" action, so a bare
 * maybe_unserialize() would let a stored payload name a class and have PHP
 * build it the moment an administrator opens the list.
 *
 * @param mixed $value Raw column value as it came out of the table.
 * @return mixed Decoded array, or the value unchanged.
 */
function minn_admin_jet_cct_decode( $value ) {
	if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
		return $value;
	}
	// Array payloads only. An object ("O:") or enum ("E:") payload is never
	// something their field types store, so it is refused rather than decoded.
	if ( 0 !== strpos( $value, 'a:' ) ) {
		return $value;
	}
	$decoded = Minn_Admin::decode_serialized( $value );
	return is_array( $decoded ) ? $decoded : $value;
}

/** A row as a list/detail item: mapped fields in panel shapes + service columns. */
function minn_admin_jet_cct_item( $factory, $row ) {
	$set  = minn_admin_jet_cct_fields( $factory );
	$item = array(
		'id'       => (int) $row['_ID'],
		'status'   => ! empty( $row['cct_status'] ) ? (string) $row['cct_status'] : 'publish',
		// cct_created is the table's CURRENT_TIMESTAMP default (the DB clock),
		// cct_modified their handler's current_time() (site local). Both are
		// read as site-local: their own screens format them that way, and
		// the DB clock follows the host, which is the same clock here.
		'created'  => ! empty( $row['cct_created'] ) ? mysql2date( 'c', $row['cct_created'], false ) : '',
		'modified' => ! empty( $row['cct_modified'] ) ? mysql2date( 'c', $row['cct_modified'], false ) : '',
	);
	$title = '';
	foreach ( $set['fields'] as $name => $f ) {
		$item[ $name ] = minn_admin_jet_value_out( $f, isset( $row[ $name ] ) ? minn_admin_jet_cct_decode( $row[ $name ] ) : '' );
		if ( '' === $title && 'text' === $f['type'] && is_string( $item[ $name ] ) && '' !== $item[ $name ] ) {
			$title = $item[ $name ];
		}
	}
	$item['title'] = '' !== $title ? $title : '#' . (int) $row['_ID'];
	return $item;
}

/** Form values → the itemarr their handler expects (stored shapes). */
function minn_admin_jet_cct_itemarr( $factory, $values ) {
	$set = minn_admin_jet_cct_fields( $factory );
	$out = array();
	foreach ( (array) $values as $name => $value ) {
		if ( ! isset( $set['fields'][ $name ] ) ) {
			continue;
		}
		$stored = minn_admin_jet_value_in( $set['fields'][ $name ], $value );
		if ( null === $stored ) {
			continue; // refused: their handler merges, so the column survives
		}
		$out[ $name ] = false === $stored ? '' : $stored;
	}
	return $out;
}

/** One collection descriptor for a type. */
function minn_admin_jet_cct_collection( $slug, $factory ) {
	$set     = minn_admin_jet_cct_fields( $factory );
	$name    = (string) $factory->get_arg( 'name', $slug );
	// The first text field is the row's title (it also names the item in
	// the detail); when the type has none the item id stands in.
	$title_key = 'title';
	foreach ( $set['fields'] as $fname => $f ) {
		if ( 'text' === $f['type'] ) {
			$title_key = $fname;
			break;
		}
	}
	$columns = array( array( 'key' => $title_key, 'label' => 'title' === $title_key ? $name : $set['fields'][ $title_key ]['label'], 'format' => 'title' ) );
	$n       = 0;
	foreach ( $set['fields'] as $fname => $f ) {
		if ( $fname === $title_key ) {
			continue;
		}
		if ( in_array( $f['type'], array( 'text', 'number', 'select', 'radio', 'date', 'true_false', 'color_picker' ), true ) && $n < 3 ) {
			$columns[] = array( 'key' => $fname, 'label' => $f['label'], 'width' => 'minmax(0,1fr)' );
			$n++;
		}
	}
	$columns[] = array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' );
	$columns[] = array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago' );
	$form      = array_map( 'minn_admin_jet_cct_form_field', array_values( $set['fields'] ) );
	$base      = 'minn-admin/v1/jet-cct/' . rawurlencode( $slug ) . '/items';
	$coll      = array(
		'viewLabel' => $name,
		'route'     => $base,
		'pageQuery' => 'per_page=25&page={page}',
		'search'    => 'search={q}',
		'itemsKey'  => 'items',
		'totalKey'  => 'total',
		'tabs'      => array( 'param' => 'status', 'static' => array( array( 'publish', __( 'Published', 'minn-admin' ) ), array( 'draft', __( 'Drafts', 'minn-admin' ) ) ), 'allLabel' => __( 'All', 'minn-admin' ) ),
		'columns'   => $columns,
		'detail'    => array(
			'skip' => array( 'id', 'title' ),
			'edit' => array( 'route' => $base . '/{id}', 'method' => 'POST', 'fields' => $form ),
		),
		'actions'   => array(
			array( 'label' => __( 'Publish', 'minn-admin' ), 'method' => 'POST', 'route' => $base . '/{id}/status', 'body' => array( 'status' => 'publish' ), 'when' => array( 'key' => 'status', 'equals' => 'draft' ) ),
			array( 'label' => __( 'Move to draft', 'minn-admin' ), 'method' => 'POST', 'route' => $base . '/{id}/status', 'body' => array( 'status' => 'draft' ), 'when' => array( 'key' => 'status', 'equals' => 'publish' ) ),
			array( 'label' => __( 'Delete', 'minn-admin' ), 'method' => 'DELETE', 'route' => $base . '/{id}', 'confirm' => __( 'Delete this item permanently? JetEngine content types have no trash.', 'minn-admin' ), 'danger' => true ),
			array( 'label' => __( 'Open in JetEngine ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=jet-cct-' . $slug ) ),
		),
		'bulk'      => array(
			array( 'label' => __( 'Publish', 'minn-admin' ), 'method' => 'POST', 'route' => $base . '/{id}/status', 'body' => array( 'status' => 'publish' ) ),
			array( 'label' => __( 'Move to draft', 'minn-admin' ), 'method' => 'POST', 'route' => $base . '/{id}/status', 'body' => array( 'status' => 'draft' ) ),
			array( 'label' => __( 'Delete', 'minn-admin' ), 'method' => 'DELETE', 'route' => $base . '/{id}', 'confirm' => __( 'Delete the selected items permanently?', 'minn-admin' ), 'danger' => true ),
		),
	);
	if ( $form ) {
		$coll['create'] = array( 'label' => __( 'Add item', 'minn-admin' ), 'route' => $base, 'method' => 'POST', 'fields' => $form );
	}
	return $coll;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	$types = minn_admin_jet_cct_types();
	if ( ! $types ) {
		return $surfaces;
	}
	$views = array();
	$first = null;
	foreach ( $types as $slug => $factory ) {
		$coll = minn_admin_jet_cct_collection( $slug, $factory );
		if ( null === $first ) {
			$first = $coll;
		} else {
			$views[] = $coll;
		}
	}
	// One type keeps its own name; a site with several gets the umbrella.
	$surfaces['jet-cct'] = array(
		'label'      => $views ? __( 'Content types', 'minn-admin' ) : $first['viewLabel'],
		'sub'        => 'JetEngine',
		'icon'       => 'database',
		'group'      => 'workspace',
		'cap'        => 'read', // each type's own capability gates its routes
		'collection' => $first,
		'views'      => $views,
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_cct_active() ) {
		return;
	}
	$type_of = function ( WP_REST_Request $request ) {
		return minn_admin_jet_cct_type( sanitize_key( rawurldecode( (string) $request['slug'] ) ) );
	};
	$perm = function ( WP_REST_Request $request ) use ( $type_of ) {
		return (bool) $type_of( $request ); // the type's own user_has_access()
	};
	$ns = 'minn-admin/v1';
	$re = '/jet-cct/(?P<slug>[a-z0-9_]+)/items';

	register_rest_route( $ns, $re, array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $type_of ) {
				global $wpdb;
				$factory  = $type_of( $request );
				$slug     = sanitize_key( $request['slug'] );
				$t        = minn_admin_jet_cct_table( $slug );
				$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
				$status   = sanitize_key( (string) $request->get_param( 'status' ) );
				$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
				$where    = array( '1=1' );
				$params   = array();
				if ( in_array( $status, array( 'publish', 'draft' ), true ) ) {
					$where[]  = 'cct_status = %s';
					$params[] = $status;
				}
				if ( '' !== $search ) {
					$set  = minn_admin_jet_cct_fields( $factory );
					$cols = array();
					foreach ( $set['fields'] as $name => $f ) {
						if ( in_array( $f['type'], array( 'text', 'textarea', 'wysiwyg' ), true ) ) {
							$cols[] = '`' . str_replace( '`', '', $name ) . '` LIKE %s';
						}
					}
					if ( $cols ) {
						$where[] = '(' . implode( ' OR ', $cols ) . ')';
						$params  = array_merge( $params, array_fill( 0, count( $cols ), '%' . $wpdb->esc_like( $search ) . '%' ) );
					} else {
						$where[]  = '_ID = %d';
						$params[] = (int) $search;
					}
				}
				$wsql = 'WHERE ' . implode( ' AND ', $where );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$count_sql = "SELECT COUNT(*) FROM {$t} {$wsql}";
				$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
				$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$wsql} ORDER BY _ID DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
				// phpcs:enable
				$items = array();
				foreach ( $rows ? $rows : array() as $row ) {
					$items[] = minn_admin_jet_cct_item( $factory, $row );
				}
				return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $type_of ) {
				$factory = $type_of( $request );
				$itemarr = minn_admin_jet_cct_itemarr( $factory, $request->get_params() );
				if ( ! $itemarr ) {
					return new WP_Error( 'empty', __( 'Fill in at least one field.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$itemarr['cct_status'] = 'publish';
				try {
					$id = $factory->get_item_handler()->update_item( $itemarr ); // no _ID = insert
				} catch ( \Throwable $e ) {
					return new WP_Error( 'insert_failed', __( 'JetEngine could not save that item.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				$row = $id ? minn_admin_jet_cct_row( sanitize_key( $request['slug'] ), $id ) : null;
				if ( ! $row ) {
					return new WP_Error( 'insert_failed', __( 'JetEngine could not save that item.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'id' => (int) $id, 'item' => minn_admin_jet_cct_item( $factory, $row ) ) );
			},
		),
	) );

	register_rest_route( $ns, $re . '/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $type_of ) {
				$factory = $type_of( $request );
				$row     = minn_admin_jet_cct_row( sanitize_key( $request['slug'] ), (int) $request['id'] );
				return $row ? rest_ensure_response( minn_admin_jet_cct_item( $factory, $row ) ) : new WP_Error( 'not_found', __( 'Item not found', 'minn-admin' ), array( 'status' => 404 ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $type_of ) {
				$factory = $type_of( $request );
				$slug    = sanitize_key( $request['slug'] );
				$id      = (int) $request['id'];
				if ( ! minn_admin_jet_cct_row( $slug, $id ) ) {
					return new WP_Error( 'not_found', __( 'Item not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$itemarr = minn_admin_jet_cct_itemarr( $factory, $request->get_params() );
				if ( ! $itemarr ) {
					return rest_ensure_response( minn_admin_jet_cct_item( $factory, minn_admin_jet_cct_row( $slug, $id ) ) );
				}
				$itemarr['_ID'] = $id;
				try {
					// Their handler merges the stored row under the edits, so
					// untouched columns survive; the stored status rides along.
					$factory->get_item_handler()->update_item( $itemarr );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'update_failed', __( 'JetEngine could not update that item.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( minn_admin_jet_cct_item( $factory, minn_admin_jet_cct_row( $slug, $id ) ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $type_of ) {
				$factory = $type_of( $request );
				$slug    = sanitize_key( $request['slug'] );
				$id      = (int) $request['id'];
				if ( ! minn_admin_jet_cct_row( $slug, $id ) ) {
					return new WP_Error( 'not_found', __( 'Item not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				try {
					$factory->get_item_handler()->raw_delete_item( $id ); // drops the linked single post too
				} catch ( \Throwable $e ) {
					return new WP_Error( 'delete_failed', __( 'JetEngine could not delete that item.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				if ( minn_admin_jet_cct_row( $slug, $id ) ) {
					return new WP_Error( 'delete_failed', __( 'JetEngine could not delete that item.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Item deleted.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( $ns, $re . '/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $type_of ) {
			$factory = $type_of( $request );
			$slug    = sanitize_key( $request['slug'] );
			$id      = (int) $request['id'];
			$status  = sanitize_key( (string) $request->get_param( 'status' ) );
			if ( ! isset( $factory->get_statuses()[ $status ] ) ) {
				return new WP_Error( 'bad_status', __( 'Unknown status', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( ! minn_admin_jet_cct_row( $slug, $id ) ) {
				return new WP_Error( 'not_found', __( 'Item not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			try {
				$factory->get_item_handler()->update_item( array( '_ID' => $id, 'cct_status' => $status ) );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'update_failed', __( 'JetEngine could not update that item.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$after = minn_admin_jet_cct_row( $slug, $id );
			if ( ! $after || $status !== (string) $after['cct_status'] ) {
				return new WP_Error( 'update_failed', __( 'JetEngine could not update that item.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			/* translators: %s: status label */
			return rest_ensure_response( array( 'ok' => true, 'status' => $status, 'message' => sprintf( __( 'Item marked %s.', 'minn-admin' ), strtolower( (string) $factory->get_statuses()[ $status ] ) ) ) );
		},
	) );
} );
