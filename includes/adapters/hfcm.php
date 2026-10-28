<?php
/**
 * Bundled adapter: Header Footer Code Manager (HFCM).
 *
 * Snippets live in {prefix}hfcm_scripts. No REST: a table resource on the
 * kernel, writing the same column set their admin form writes. Display targeting beyond "All"
 * stays on HFCM's screen (pages/posts/categories pickers are a canvas).
 *
 * Cap: manage_options (their menu gate). Delete / activate / deactivate
 * mirror Hfcm_Snippets_List helpers when the class is loaded.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Their table is the durable signal: the class loads with the plugin, the table outlives a switch-off. */
function minn_admin_hfcm_active() {
	return Minn_Admin_Kernel_SQL::exists( 'hfcm_scripts' );
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
		// Stored as HFCM's own form stores it (htmlentities) and read back as
		// its renderer and edit screen read it.
		'code'         => isset( $row['snippet'] ) ? html_entity_decode( (string) $row['snippet'] ) : '',
		'snippet_type' => $type,
		'location'     => $location,
		'device_type'  => isset( $row['device_type'] ) ? (string) $row['device_type'] : 'both',
		'display_on'   => isset( $row['display_on'] ) ? (string) $row['display_on'] : 'All',
		'scope'        => strtoupper( $type ) . ' · ' . $location,
		'active'       => ( 'active' === $status ),
		'modified'     => $modified,
	);
}

/** One snippet as the list reads it, or null (kept for callers by name). */
function minn_admin_hfcm_get( $id ) {
	$row = (int) $id > 0 ? Minn_Admin_Kernel_SQL::row( 'hfcm_scripts', 'script_id', (int) $id ) : null;
	return $row ? minn_admin_hfcm_item( $row ) : null;
}

/**
 * HFCM snippets are raw markup that HFCM DECODES before echoing.
 *
 * Their renderer runs html_entity_decode( $snippet ) into wp_head on every
 * page, and their own admin form compensates by storing snippets
 * htmlentities-encoded. wp_kses_post() passes entities through untouched, so
 * using it as the fallback for a caller without unfiltered_html was no
 * protection at all: `&lt;script&gt;` survives kses and the renderer decodes it
 * back into a live script tag for every visitor.
 *
 * HFCM's own gate refuses snippet writes outright in that situation, so mirror
 * it rather than pretending to sanitize. Who this affects: every site
 * administrator on multisite (only super admins hold unfiltered_html there)
 * and any install using DISALLOW_UNFILTERED_HTML.
 */
function minn_admin_hfcm_can_write_code() {
	// HFCM html_entity_decode()s these snippets into wp_head for every
	// visitor, so authoring one is code editing and answers to the directive
	// that forbids it — the guard the custom-css-js and WPCode peers already
	// carry, which this adapter was not given.
	if ( class_exists( 'Minn_Admin' ) && ! Minn_Admin::code_edits_allowed() ) {
		return false;
	}
	if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
		return false;
	}
	return current_user_can( 'unfiltered_html' );
}

/** WP_Error explaining why a snippet write was refused. */
function minn_admin_hfcm_code_error() {
	return new WP_Error(
		'forbidden',
		__( 'Header Footer Code Manager snippets run as raw markup in the page head, so editing them needs the unfiltered_html capability on this site.', 'minn-admin' ),
		array( 'status' => 403 )
	);
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
		array( 'before_content', __( 'Before content', 'minn-admin' ) ),
		array( 'after_content', __( 'After content', 'minn-admin' ) ),
	);
	$device_options = array(
		array( 'both', __( 'All devices', 'minn-admin' ) ),
		array( 'desktop', 'Desktop' ),
		array( 'mobile', 'Mobile' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'placeholder' => __( 'Analytics pixel', 'minn-admin' ) ),
		array(
			'key'         => 'code',
			'label'       => __( 'Code', 'minn-admin' ),
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => __( '<!-- tracking snippet -->', 'minn-admin' ),
		),
		array( 'key' => 'snippet_type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_options ),
		// clearable: a manual (shortcode) snippet stores an empty location;
		// an edit wrote the first one over it. Empty is not written.
		array( 'key' => 'location', 'label' => __( 'Location', 'minn-admin' ), 'type' => 'select', 'options' => $location_options, 'clearable' => true ),
		array( 'key' => 'device_type', 'label' => __( 'Devices', 'minn-admin' ), 'type' => 'select', 'options' => $device_options ),
	);

	list( $actions, $bulk ) = minn_admin_snippets_actions( 'hfcm/snippets', array(
		'edit_in' => array(
			'label' => __( 'Edit in HFCM ↗', 'minn-admin' ),
			'href'  => admin_url( 'admin.php?page=hfcm-update&id={id}' ),
		),
	) );
	$surfaces['hfcm'] = minn_admin_snippets_surface( array(
		'sub'        => 'Header Footer Code Manager',
		'plugin'     => 'header-footer-code-manager',
		'cap'        => 'manage_options',
		'status'     => 'hfcm/status',
		'collection' => Minn_Admin_Kernel::collection( 'hfcm/snippets', array(
			'filter'  => minn_admin_snippets_filter( '1', '0' ),
			'create'  => array(
				'label'    => __( 'Add snippet', 'minn-admin' ),
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
			'columns' => minn_admin_snippets_columns( __( 'Type · location', 'minn-admin' ), array( 'width' => 'minmax(0,1.2fr)', 'priority' => false ) ),
			'detail'  => array(
				'detailRoute' => 'minn-admin/v1/hfcm/snippets/{id}',
				'skip'        => array( 'code', 'name', 'scope', 'snippet_type', 'location', 'device_type', 'display_on', 'active' ),
				'edit'        => array(
					'route'    => 'minn-admin/v1/hfcm/snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'display_on' ),
					'fields'   => $edit_fields,
				),
			),
			'actions' => $actions,
			'bulk'    => $bulk,
		) ),
	) );
	return $surfaces;
} );

/** Who wrote it, as their form stamps it. */
function minn_admin_hfcm_who() {
	$user = wp_get_current_user();
	return $user && $user->user_login ? $user->user_login : 'admin';
}

const MINN_ADMIN_HFCM_TYPES     = array( 'html', 'css', 'js' );
const MINN_ADMIN_HFCM_LOCATIONS = array( 'header', 'footer', 'before_content', 'after_content' );
const MINN_ADMIN_HFCM_DEVICES   = array( 'both', 'desktop', 'mobile' );

/**
 * An edit's columns from the request, the code gate asked first: changing
 * what a snippet IS, or where it runs, decides the context its stored bytes
 * execute in (retyping css to js, moving one into the header), so it is a
 * code write even with no code in the request, the rule the custom-css-js
 * peer applies to the same pair of fields.
 *
 * @return array|WP_Error Columns to write.
 */
function minn_admin_hfcm_edit( array $item, WP_REST_Request $request ) {
	$body = $request->get_json_params();
	$body = is_array( $body ) ? $body : array();
	$data = array( 'last_revision_date' => current_time( 'mysql' ), 'last_modified_by' => minn_admin_hfcm_who() );
	// A name the dialog sends back as stored is left as stored: cleaning an
	// untouched value rewrites it ("50%DEAL" loses "%DE").
	if ( isset( $body['name'] ) && ! Minn_Admin::unchanged( $body['name'], $item['name'] ) ) {
		$data['name'] = sanitize_text_field( $body['name'] );
	}
	$retargets = ( isset( $body['snippet_type'] ) && in_array( $body['snippet_type'], MINN_ADMIN_HFCM_TYPES, true ) && (string) $body['snippet_type'] !== (string) $item['snippet_type'] )
		|| ( isset( $body['location'] ) && in_array( $body['location'], MINN_ADMIN_HFCM_LOCATIONS, true ) && (string) $body['location'] !== (string) $item['location'] );
	if ( ( array_key_exists( 'code', $body ) || $retargets || ! empty( $body['active'] ) ) && ! minn_admin_hfcm_can_write_code() ) {
		return minn_admin_hfcm_code_error();
	}
	// Code sent back as stored (or as served: a credential in it reads as its
	// egress mask) is not written.
	if ( array_key_exists( 'code', $body ) && ! Minn_Admin::unchanged( $body['code'], $item['code'] ) ) {
		$data['snippet'] = (string) $body['code'];
	}
	foreach ( array( 'snippet_type' => MINN_ADMIN_HFCM_TYPES, 'location' => MINN_ADMIN_HFCM_LOCATIONS, 'device_type' => MINN_ADMIN_HFCM_DEVICES ) as $key => $allowed ) {
		if ( isset( $body[ $key ] ) && in_array( $body[ $key ], $allowed, true ) ) {
			$data[ $key ] = $body[ $key ];
		}
	}
	if ( array_key_exists( 'active', $body ) ) {
		$data['status'] = $body['active'] ? 'active' : 'inactive';
	}
	$masked = Minn_Admin_Egress::refuse_masks( $data, array( 'name' => $item['name'], 'snippet' => $item['code'] ) );
	if ( $masked ) {
		return $masked;
	}
	// HFCM's storage contract: its form stores htmlentities( $snippet ) and
	// its renderer echoes html_entity_decode() of it, so a snippet stored as
	// typed lost every entity its author meant ("&amp;&amp;" in a script ran
	// as "&&").
	if ( isset( $data['snippet'] ) ) {
		$data['snippet'] = htmlentities( $data['snippet'] );
	}
	return $data;
}

/** Whether the stored row reads as written (the timestamps aside). */
function minn_admin_hfcm_saved( $fresh, $data ) {
	if ( ! is_array( $fresh ) || ! is_array( $data ) ) {
		return false;
	}
	$read = array( 'name' => 'name', 'snippet' => 'code', 'snippet_type' => 'snippet_type', 'location' => 'location', 'device_type' => 'device_type' );
	foreach ( $read as $col => $key ) {
		$want = 'snippet' === $col && array_key_exists( $col, $data ) ? html_entity_decode( (string) $data[ $col ] ) : ( $data[ $col ] ?? null );
		if ( array_key_exists( $col, $data ) && (string) $want !== (string) $fresh[ $key ] ) {
			return false;
		}
	}
	return ! array_key_exists( 'status', $data ) || ( 'active' === $data['status'] ) === $fresh['active'];
}

minn_admin_resource( 'hfcm/snippets', function () {
	$fields = array(
		'id'           => 'int',
		'name'         => 'text',
		'code'         => 'text',
		'snippet_type' => 'text',
		'location'     => 'text',
		'device_type'  => 'text',
		'display_on'   => 'text',
		'scope'        => 'text',
		'active'       => 'bool',
		'modified'     => 'text',
	);
	return array(
		'active'  => 'minn_admin_hfcm_active',
		'gates'   => array( 'view' => 'minn_admin_hfcm_can' ),
		'labels'  => array( 'not_found' => __( 'Snippet not found.', 'minn-admin' ) ),
		'source'  => array( 'table' => 'hfcm_scripts', 'key' => 'script_id', 'columns' => array( '*' ) ),
		'list'    => array(
			'facets' => array( array( 'param' => 'active', 'values' => function ( $v ) {
				return 'all' === $v ? null : array( 'status' => in_array( $v, array( '1', 'true', 'active' ), true ) ? 'active' : 'inactive' );
			} ) ),
			'search' => array( 'columns' => array( 'name' ) ),
			'order'  => array( 'column' => 'script_id' ),
			'row'    => 'minn_admin_hfcm_item',
			'fields' => $fields,
		),
		'item'    => array( 'view' => 'minn_admin_hfcm_item', 'fields' => $fields ),
		// The columns their admin form writes; display targeting beyond "All"
		// stays on their screen.
		'create'  => array(
			'gate'  => 'view',
			'run'   => function ( WP_REST_Request $request ) {
				$body = $request->get_json_params();
				$body = is_array( $body ) ? $body : array();
				$name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
				if ( ! $name ) {
					return new WP_Error( 'missing_name', __( 'Name is required.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( ! minn_admin_hfcm_can_write_code() ) {
					return minn_admin_hfcm_code_error();
				}
				// A new snippet holds nothing a mask could stand for.
				$masked = Minn_Admin_Egress::refuse_masks( array( 'name' => $name, 'snippet' => isset( $body['code'] ) ? $body['code'] : '' ), array() );
				if ( $masked ) {
					return $masked;
				}
				$now = current_time( 'mysql' );
				$who = minn_admin_hfcm_who();
				return Minn_Admin_Kernel_SQL::insert( 'hfcm_scripts', array(
					'name'               => $name,
					'snippet'            => isset( $body['code'] ) ? htmlentities( (string) $body['code'] ) : '',
					'snippet_type'       => isset( $body['snippet_type'] ) && in_array( $body['snippet_type'], MINN_ADMIN_HFCM_TYPES, true ) ? $body['snippet_type'] : 'html',
					'device_type'        => isset( $body['device_type'] ) && in_array( $body['device_type'], MINN_ADMIN_HFCM_DEVICES, true ) ? $body['device_type'] : 'both',
					'location'           => isset( $body['location'] ) && in_array( $body['location'], MINN_ADMIN_HFCM_LOCATIONS, true ) ? $body['location'] : 'header',
					'display_on'         => 'All',
					'status'             => ! empty( $body['active'] ) ? 'active' : 'inactive',
					'created_by'         => $who,
					'last_modified_by'   => $who,
					'created'            => $now,
					'last_revision_date' => $now,
				) );
			},
			'fail'  => __( 'Could not create the snippet.', 'minn-admin' ),
			'reply' => function ( $id, $fresh ) {
				return minn_admin_hfcm_item( $fresh );
			},
		),
		'actions' => array(
			'edit'   => array(
				'method' => 'PUT',
				'gate'   => 'view',
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					$data = minn_admin_hfcm_edit( minn_admin_hfcm_item( $row ), $request );
					if ( ! is_wp_error( $data ) ) {
						Minn_Admin_Kernel_SQL::update( 'hfcm_scripts', 'script_id', $id, $data );
					}
					return $data;
				},
				'expect' => function ( $id, $fresh, $data ) {
					return minn_admin_hfcm_saved( minn_admin_hfcm_item( (array) $fresh ), $data );
				},
				'reply'  => function ( $id, $fresh ) {
					return minn_admin_hfcm_item( $fresh );
				},
			),
			// Destroying a snippet is a write to the same raw-markup store.
			'delete' => array(
				'method' => 'DELETE',
				'gate'   => 'view',
				'run'    => function ( $id ) {
					if ( ! minn_admin_hfcm_can_write_code() ) {
						return minn_admin_hfcm_code_error();
					}
					if ( class_exists( 'Hfcm_Snippets_List' ) && method_exists( 'Hfcm_Snippets_List', 'delete_snippet' ) ) {
						Hfcm_Snippets_List::delete_snippet( $id );
						return true;
					}
					return Minn_Admin_Kernel_SQL::delete( 'hfcm_scripts', 'script_id', $id );
				},
				'expect' => 'gone',
				'reply'  => function () {
					return array( 'deleted' => true );
				},
			),
			// Publishing a snippet is the same privilege as writing one: HFCM
			// html_entity_decode()s the stored markup into wp_head for every
			// visitor, so switching a row on runs it. Off is always allowed.
			'active' => array(
				'gate'   => 'view',
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					$body   = $request->get_json_params();
					$active = is_array( $body ) ? ! empty( $body['active'] ) : true;
					if ( $active && ! minn_admin_hfcm_can_write_code() ) {
						return minn_admin_hfcm_code_error();
					}
					if ( $active && class_exists( 'Hfcm_Snippets_List' ) && method_exists( 'Hfcm_Snippets_List', 'activate_snippet' ) ) {
						Hfcm_Snippets_List::activate_snippet( $id );
					} elseif ( ! $active && class_exists( 'Hfcm_Snippets_List' ) && method_exists( 'Hfcm_Snippets_List', 'deactivate_snippet' ) ) {
						Hfcm_Snippets_List::deactivate_snippet( $id );
					} else {
						Minn_Admin_Kernel_SQL::update( 'hfcm_scripts', 'script_id', $id, array( 'status' => $active ? 'active' : 'inactive' ) );
					}
					return $active ? 'on' : 'off';
				},
				'expect' => function ( $id, $fresh, $want ) {
					return is_array( $fresh ) && ( 'on' === $want ) === ( 'active' === (string) $fresh['status'] );
				},
				'reply'  => function ( $id, $fresh ) {
					return minn_admin_hfcm_item( $fresh );
				},
			),
		),
	);
} );

// Status card: counts from their own table (status active/inactive,
// snippet_type html/css/js/php, last_revision_date).
minn_admin_endpoint( 'hfcm/status', array(
	'active' => 'minn_admin_hfcm_active',
	'gate'   => 'minn_admin_hfcm_can',
	'run'    => function () {
		$id       = 'hfcm/snippets';
		$on       = array( 'status' => 'active' );
		$inactive = Minn_Admin_Kernel::count( $id, array( 'status' => array( 'not' => array( 'active' ) ) ) );
		$types    = array_map( function ( $t ) {
			return array( 'value' => strtolower( $t['value'] ), 'count' => $t['count'] );
		}, Minn_Admin_Kernel_SQL::counts( 'hfcm_scripts', 'snippet_type', $on, 3 ) );
		$last     = Minn_Admin_Kernel_SQL::select( 'hfcm_scripts', array( 'name', 'last_revision_date' ), array(), array( 'last_revision_date' => 'DESC' ), 1 );
		$rows     = array(
			minn_admin_snippets_count_row( Minn_Admin_Kernel::count( $id, $on ), $inactive ),
			minn_admin_snippets_running_row( __( 'Running types', 'minn-admin' ), $types ),
			$last && $last[0]['last_revision_date'] ? minn_admin_snippets_last_row( $last[0]['name'], substr( (string) $last[0]['last_revision_date'], 0, 10 ) ) : null,
		);
		return array( 'rows' => array_values( array_filter( $rows ) ) );
	},
) );
