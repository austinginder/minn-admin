<?php
/**
 * Bundled adapter: Header Footer Code Manager (HFCM).
 *
 * Snippets live in {prefix}hfcm_scripts. No REST — shim over $wpdb using the
 * same column set their admin form writes. Display targeting beyond "All"
 * stays on HFCM's screen (pages/posts/categories pickers are a canvas).
 *
 * Cap: manage_options (their menu gate). Delete / activate / deactivate
 * mirror Hfcm_Snippets_List helpers when the class is loaded.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_hfcm_active() {
	global $wpdb;
	if ( ! class_exists( 'NNR_HFCM' ) && ! defined( 'HFCM_PLUGIN_FILE' ) ) {
		// Class loads with the plugin; table presence is the durable signal.
		if ( ! function_exists( 'get_plugins' ) ) {
			// still check table
		}
	}
	$table = $wpdb->prefix . 'hfcm_scripts';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

function minn_admin_hfcm_table() {
	global $wpdb;
	return $wpdb->prefix . 'hfcm_scripts';
}

function minn_admin_hfcm_can() {
	return current_user_can( 'manage_options' );
}

function minn_admin_hfcm_item( $row ) {
	if ( is_object( $row ) ) {
		$row = (array) $row;
	}
	if ( empty( $row['script_id'] ) ) {
		return null;
	}
	$type     = isset( $row['snippet_type'] ) ? (string) $row['snippet_type'] : 'html';
	$location = isset( $row['location'] ) ? (string) $row['location'] : 'header';
	$status   = isset( $row['status'] ) ? (string) $row['status'] : 'inactive';
	$modified = isset( $row['last_revision_date'] ) && $row['last_revision_date']
		? (string) $row['last_revision_date']
		: ( isset( $row['created'] ) ? (string) $row['created'] : '' );
	// HFCM stores site-local datetimes (current_time without gmt).
	return array(
		'id'           => (int) $row['script_id'],
		'name'         => isset( $row['name'] ) ? (string) $row['name'] : '',
		'code'         => isset( $row['snippet'] ) ? (string) $row['snippet'] : '',
		'snippet_type' => $type,
		'location'     => $location,
		'device_type'  => isset( $row['device_type'] ) ? (string) $row['device_type'] : 'both',
		'display_on'   => isset( $row['display_on'] ) ? (string) $row['display_on'] : 'All',
		'scope'        => strtoupper( $type ) . ' · ' . $location,
		'active'       => ( 'active' === $status ),
		'modified'     => $modified,
	);
}

function minn_admin_hfcm_rows( $args = array() ) {
	global $wpdb;
	if ( ! minn_admin_hfcm_active() ) {
		return array();
	}
	$table = minn_admin_hfcm_table();
	$sql   = "SELECT * FROM `{$table}` WHERE 1=1";
	$params = array();
	if ( isset( $args['active'] ) ) {
		$want = ( '1' === (string) $args['active'] || 'true' === $args['active'] || true === $args['active'] || 'active' === $args['active'] );
		$sql     .= ' AND status = %s';
		$params[] = $want ? 'active' : 'inactive';
	}
	if ( ! empty( $args['s'] ) ) {
		$sql     .= ' AND name LIKE %s';
		$params[] = '%' . $wpdb->esc_like( $args['s'] ) . '%';
	}
	$sql .= ' ORDER BY script_id DESC';
	if ( $params ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
	}
	$items = array();
	foreach ( (array) $rows as $row ) {
		$item = minn_admin_hfcm_item( $row );
		if ( $item ) {
			$items[] = $item;
		}
	}
	return $items;
}

function minn_admin_hfcm_get( $id ) {
	global $wpdb;
	$id = (int) $id;
	if ( $id < 1 ) {
		return null;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM `' . minn_admin_hfcm_table() . '` WHERE script_id = %d', $id ), ARRAY_A );
	return $row ? minn_admin_hfcm_item( $row ) : null;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_hfcm_active() || ! minn_admin_hfcm_can() ) {
		return $surfaces;
	}

	$type_options = array(
		array( 'html', 'HTML' ),
		array( 'css', 'CSS' ),
		array( 'js', 'JavaScript' ),
	);
	$location_options = array(
		array( 'header', 'Header' ),
		array( 'footer', 'Footer' ),
		array( 'before_content', 'Before content' ),
		array( 'after_content', 'After content' ),
	);
	$device_options = array(
		array( 'both', 'All devices' ),
		array( 'desktop', 'Desktop' ),
		array( 'mobile', 'Mobile' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => 'Name', 'placeholder' => 'Analytics pixel' ),
		array(
			'key'         => 'code',
			'label'       => 'Code',
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => '<!-- tracking snippet -->',
		),
		array( 'key' => 'snippet_type', 'label' => 'Type', 'type' => 'select', 'options' => $type_options ),
		array( 'key' => 'location', 'label' => 'Location', 'type' => 'select', 'options' => $location_options ),
		array( 'key' => 'device_type', 'label' => 'Devices', 'type' => 'select', 'options' => $device_options ),
	);

	$surfaces['hfcm'] = array(
		'label'      => 'Snippets',
		'family'     => 'snippets',
		'sub'        => 'Header Footer Code Manager',
		'icon'       => 'code',
		'cap'        => 'manage_options',
		'collection' => array(
			'route'     => 'minn-admin/v1/hfcm/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'filter'    => array(
				'label'   => 'Status',
				'options' => array(
					array( 'all', 'All' ),
					array( '1', 'Active' ),
					array( '0', 'Inactive' ),
				),
				'query'   => 'active={v}',
			),
			'create'    => array(
				'label'    => 'Add snippet',
				'route'    => 'minn-admin/v1/hfcm/snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'       => true,
					'snippet_type' => 'html',
					'location'     => 'header',
					'device_type'  => 'both',
					'code'         => '',
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'Snippet', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => 'Type · location', 'format' => 'mono', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'active', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'modified', 'label' => 'Modified', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/hfcm/snippets/{id}',
				'skip'        => array( 'code', 'name', 'scope', 'snippet_type', 'location', 'device_type', 'display_on', 'active' ),
				'edit'        => array(
					'route'    => 'minn-admin/v1/hfcm/snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'active', 'display_on' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/hfcm/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/hfcm/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => 'Edit in HFCM ↗',
					'href'  => admin_url( 'admin.php?page=hfcm-update&id={id}' ),
				),
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/hfcm/snippets/{id}',
					'confirm' => 'Delete this snippet permanently?',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/hfcm/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/hfcm/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/hfcm/snippets/{id}',
					'confirm' => 'Delete the selected snippets permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_hfcm_active() ) {
		return;
	}
	$perm = function () {
		return minn_admin_hfcm_can();
	};

	register_rest_route( 'minn-admin/v1', '/hfcm/snippets', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
				$args     = array();
				if ( $request->get_param( 'search' ) ) {
					$args['s'] = sanitize_text_field( $request->get_param( 'search' ) );
				}
				$active = $request->get_param( 'active' );
				if ( null !== $active && '' !== $active && 'all' !== $active ) {
					$args['active'] = $active;
				}
				$all = minn_admin_hfcm_rows( $args );
				return rest_ensure_response( array(
					'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
					'total' => count( $all ),
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$body = $request->get_json_params();
				if ( ! is_array( $body ) ) {
					$body = array();
				}
				$name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
				if ( ! $name ) {
					return new WP_Error( 'missing_name', 'Name is required.', array( 'status' => 400 ) );
				}
				$code = isset( $body['code'] ) ? (string) $body['code'] : '';
				if ( ! current_user_can( 'unfiltered_html' ) ) {
					$code = wp_kses_post( $code );
				}
				$type     = isset( $body['snippet_type'] ) && in_array( $body['snippet_type'], array( 'html', 'css', 'js' ), true )
					? $body['snippet_type'] : 'html';
				$location = isset( $body['location'] ) && in_array( $body['location'], array( 'header', 'footer', 'before_content', 'after_content' ), true )
					? $body['location'] : 'header';
				$device   = isset( $body['device_type'] ) && in_array( $body['device_type'], array( 'both', 'desktop', 'mobile' ), true )
					? $body['device_type'] : 'both';
				$status   = ! empty( $body['active'] ) ? 'active' : 'inactive';
				$now      = current_time( 'mysql' );
				$user     = wp_get_current_user();
				$who      = $user && $user->user_login ? $user->user_login : 'admin';
				$ok       = $wpdb->insert(
					minn_admin_hfcm_table(),
					array(
						'name'               => $name,
						'snippet'            => $code,
						'snippet_type'       => $type,
						'device_type'        => $device,
						'location'           => $location,
						'display_on'         => 'All',
						'status'             => $status,
						'created_by'         => $who,
						'last_modified_by'   => $who,
						'created'            => $now,
						'last_revision_date' => $now,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( ! $ok ) {
					return new WP_Error( 'insert_failed', 'Could not create the snippet.', array( 'status' => 500 ) );
				}
				return rest_ensure_response( minn_admin_hfcm_get( (int) $wpdb->insert_id ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/hfcm/snippets/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$item = minn_admin_hfcm_get( (int) $request['id'] );
				if ( ! $item ) {
					return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( $item );
			},
		),
		array(
			'methods'             => 'PUT',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$id   = (int) $request['id'];
				$item = minn_admin_hfcm_get( $id );
				if ( ! $item ) {
					return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
				}
				$body = $request->get_json_params();
				if ( ! is_array( $body ) ) {
					$body = array();
				}
				$data = array( 'last_revision_date' => current_time( 'mysql' ) );
				$fmt  = array( '%s' );
				$user = wp_get_current_user();
				if ( $user && $user->user_login ) {
					$data['last_modified_by'] = $user->user_login;
					$fmt[]                    = '%s';
				}
				if ( isset( $body['name'] ) ) {
					$data['name'] = sanitize_text_field( $body['name'] );
					$fmt[]        = '%s';
				}
				if ( array_key_exists( 'code', $body ) ) {
					$code = (string) $body['code'];
					if ( ! current_user_can( 'unfiltered_html' ) ) {
						$code = wp_kses_post( $code );
					}
					$data['snippet'] = $code;
					$fmt[]           = '%s';
				}
				if ( isset( $body['snippet_type'] ) && in_array( $body['snippet_type'], array( 'html', 'css', 'js' ), true ) ) {
					$data['snippet_type'] = $body['snippet_type'];
					$fmt[]                = '%s';
				}
				if ( isset( $body['location'] ) && in_array( $body['location'], array( 'header', 'footer', 'before_content', 'after_content' ), true ) ) {
					$data['location'] = $body['location'];
					$fmt[]            = '%s';
				}
				if ( isset( $body['device_type'] ) && in_array( $body['device_type'], array( 'both', 'desktop', 'mobile' ), true ) ) {
					$data['device_type'] = $body['device_type'];
					$fmt[]               = '%s';
				}
				if ( array_key_exists( 'active', $body ) ) {
					$data['status'] = $body['active'] ? 'active' : 'inactive';
					$fmt[]          = '%s';
				}
				$wpdb->update( minn_admin_hfcm_table(), $data, array( 'script_id' => $id ), $fmt, array( '%d' ) );
				return rest_ensure_response( minn_admin_hfcm_get( $id ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id = (int) $request['id'];
				if ( ! minn_admin_hfcm_get( $id ) ) {
					return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
				}
				if ( class_exists( 'Hfcm_Snippets_List' ) && method_exists( 'Hfcm_Snippets_List', 'delete_snippet' ) ) {
					Hfcm_Snippets_List::delete_snippet( $id );
				} else {
					global $wpdb;
					$wpdb->delete( minn_admin_hfcm_table(), array( 'script_id' => $id ), array( '%d' ) );
				}
				return rest_ensure_response( array( 'deleted' => true ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/hfcm/snippets/(?P<id>\d+)/active', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$id   = (int) $request['id'];
			$item = minn_admin_hfcm_get( $id );
			if ( ! $item ) {
				return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
			}
			$body   = $request->get_json_params();
			$active = is_array( $body ) ? ! empty( $body['active'] ) : true;
			if ( $active && class_exists( 'Hfcm_Snippets_List' ) && method_exists( 'Hfcm_Snippets_List', 'activate_snippet' ) ) {
				Hfcm_Snippets_List::activate_snippet( $id );
			} elseif ( ! $active && class_exists( 'Hfcm_Snippets_List' ) && method_exists( 'Hfcm_Snippets_List', 'deactivate_snippet' ) ) {
				Hfcm_Snippets_List::deactivate_snippet( $id );
			} else {
				global $wpdb;
				$wpdb->update(
					minn_admin_hfcm_table(),
					array( 'status' => $active ? 'active' : 'inactive' ),
					array( 'script_id' => $id ),
					array( '%s' ),
					array( '%d' )
				);
			}
			return rest_ensure_response( minn_admin_hfcm_get( $id ) );
		},
	) );
} );
