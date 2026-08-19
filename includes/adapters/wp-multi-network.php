<?php
/**
 * Bundled adapter: WP Multi Network.
 *
 * Adds the plugin's global Networks directory to Minn's existing Network
 * group. The adapter stays deliberately thin: creation, deletion and site
 * moves all run through WP Multi Network's public functions, while Minn adds
 * stricter guards around the destructive routes.
 *
 * Network domain and path editing remains in the plugin's own screen. That
 * operation rewrites every site URL in the target network and benefits from
 * the plugin's dedicated form rather than a generic inline edit.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Whether WP Multi Network has loaded the APIs this adapter uses. */
function minn_admin_wpmn_ready() {
	return is_multisite()
		&& function_exists( 'add_network' )
		&& function_exists( 'delete_network' )
		&& function_exists( 'move_site' )
		&& function_exists( 'switch_to_network' )
		&& function_exists( 'restore_current_network' );
}

/** Build the plugin's network management URL from the current network. */
function minn_admin_wpmn_edit_url( $network_id ) {
	return add_query_arg(
		array(
			'page'   => 'networks',
			'action' => 'edit_network',
			'id'     => (int) $network_id,
		),
		network_admin_url( 'admin.php' )
	);
}

/** One network row for the collection and detail modal. */
function minn_admin_wpmn_network_row( $network ) {
	$network = get_network( $network );
	if ( ! $network ) {
		return array();
	}

	$id     = (int) $network->id;
	$name   = Minn_Admin::plain_text( get_network_option( $id, 'site_name', '' ) );
	$admins = array_filter( (array) get_network_option( $id, 'site_admins', array() ) );
	$sites  = (int) get_network_option( $id, 'blog_count', 0 );
	if ( ! $sites ) {
		$sites = (int) get_sites( array( 'network_id' => $id, 'count' => true ) );
	}

	switch_to_network( $id );
	$dashboard = network_admin_url();
	$visit     = network_home_url();
	restore_current_network();

	return array(
		'id'             => $id,
		'name'           => $name ? $name : $network->domain,
		'address'        => $network->domain . $network->path,
		'sites'          => $sites,
		'administrators' => count( $admins ),
		'mainSiteId'     => (int) get_main_site_id( $id ),
		'dashboard'      => $dashboard,
		'visit'          => $visit,
		'edit'           => minn_admin_wpmn_edit_url( $id ),
		// Only offer the verb the route will actually accept: not the primary
		// network, not the one being worked in, and one the caller administers.
		'canDelete'      => ( ! is_main_network( $id )
			&& $id !== (int) get_current_network_id()
			&& true === minn_admin_wpmn_network_target( $id ) ) ? '1' : '0',
	);
}

/** Destination choices for the site-move action. */
function minn_admin_wpmn_network_options( $exclude_id = 0 ) {
	$options = array();
	foreach ( get_networks( array( 'number' => 0, 'orderby' => 'domain', 'order' => 'ASC' ) ) as $network ) {
		if ( (int) $network->id === (int) $exclude_id ) {
			continue;
		}
		$name = Minn_Admin::plain_text( get_network_option( $network->id, 'site_name', '' ) );
		$options[] = array(
			(string) $network->id,
			sprintf(
				/* translators: 1: network name, 2: network address */
				__( '%1$s (%2$s)', 'minn-admin' ),
				$name ? $name : $network->domain,
				$network->domain . $network->path
			),
		);
	}
	return $options;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wpmn_ready() || ! current_user_can( 'list_networks' ) ) {
		return $surfaces;
	}

	$surfaces['wp-multi-network'] = array(
		'label'      => __( 'Networks', 'minn-admin' ),
		'sub'        => 'WP Multi Network',
		'group'      => 'network',
		'icon'       => 'grid',
		'cap'        => 'list_networks',
		'collection' => array(
			'route'     => 'minn-admin/v1/wp-multi-network/networks',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Network', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'address', 'label' => __( 'Address', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'sites', 'label' => __( 'Sites', 'minn-admin' ), 'format' => 'num', 'width' => '90px' ),
				array( 'key' => 'administrators', 'label' => __( 'Administrators', 'minn-admin' ), 'format' => 'num', 'width' => '130px' ),
			),
			'detail'    => array(
				'skip' => array( 'mainSiteId', 'dashboard', 'visit', 'edit', 'canDelete' ),
			),
			'actions'   => array(
				array( 'label' => __( 'Open dashboard ↗', 'minn-admin' ), 'href' => '{dashboard}' ),
				array( 'label' => __( 'Visit main site ↗', 'minn-admin' ), 'href' => '{visit}' ),
				array( 'label' => __( 'Edit in WP Multi Network ↗', 'minn-admin' ), 'href' => '{edit}' ),
				array(
					'label'   => __( 'Delete network and sites', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wp-multi-network/networks/{id}',
					'when'    => array( 'key' => 'canDelete', 'equals' => '1' ),
					'confirm' => __( 'Delete this network and every site in it permanently? Their posts, pages, media and settings are removed. This cannot be undone.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);

	if ( current_user_can( 'create_networks' ) ) {
		$surfaces['wp-multi-network']['collection']['create'] = array(
			'label'    => __( 'Add network', 'minn-admin' ),
			'route'    => 'minn-admin/v1/wp-multi-network/networks',
			'method'   => 'POST',
			'defaults' => array( 'path' => '/' ),
			'fields'   => array(
				array( 'key' => 'title', 'label' => __( 'Network title', 'minn-admin' ), 'required' => true ),
				array( 'key' => 'domain', 'label' => __( 'Domain', 'minn-admin' ), 'mono' => true, 'required' => true, 'placeholder' => __( 'network.example.com', 'minn-admin' ) ),
				array( 'key' => 'path', 'label' => __( 'Path', 'minn-admin' ), 'mono' => true, 'required' => true, 'value' => '/' ),
				array( 'key' => 'site_title', 'label' => __( 'Root site title', 'minn-admin' ), 'required' => false ),
			),
		);
	}

	$destinations = minn_admin_wpmn_network_options( get_current_network_id() );
	if ( isset( $surfaces['network-sites']['collection']['actions'] ) && $destinations ) {
		$surfaces['network-sites']['collection']['actions'][] = array(
			'label'   => __( 'Move to another network', 'minn-admin' ),
			'route'   => 'minn-admin/v1/wp-multi-network/sites/{id}/move',
			'list'    => true,
			'when'    => array( 'key' => 'canMove', 'equals' => '1' ),
			'confirm' => __( 'Move this site to the selected network? Its address stays the same, but its network settings and administration context change.', 'minn-admin' ),
			'fields'  => array(
				array(
					'key'      => 'network',
					'label'    => __( 'Destination network', 'minn-admin' ),
					'type'     => 'select',
					'required' => true,
					'options'  => $destinations,
				),
			),
		);
	}

	return $surfaces;
}, 20 );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpmn_ready() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/wp-multi-network/networks', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'list_networks' );
			},
			'callback'            => 'minn_admin_wpmn_networks_list',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'create_networks' );
			},
			'callback'            => 'minn_admin_wpmn_network_create',
			'args'                => array(
				'title'      => array( 'type' => 'string', 'required' => true ),
				'domain'     => array( 'type' => 'string', 'required' => true ),
				'path'       => array( 'type' => 'string', 'required' => true ),
				'site_title' => array( 'type' => 'string', 'required' => false ),
			),
		),
	) );

	register_rest_route( 'minn-admin/v1', '/wp-multi-network/networks/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => function ( WP_REST_Request $request ) {
			// Same source the handler acts on, so the gate and the action can
			// never be answering about different networks.
			// delete_network maps to the object-agnostic delete_networks, so pair the
			// capability with a real per-network check (see
			// minn_admin_wpmn_network_target).
			$id = minn_admin_wpmn_path_id( $request );
			return current_user_can( 'delete_network', $id )
				&& true === minn_admin_wpmn_network_target( $id );
		},
		'callback'            => 'minn_admin_wpmn_network_delete',
		'args'                => array(
			'delete_sites' => array( 'type' => 'boolean', 'required' => true ),
		),
	) );

	register_rest_route( 'minn-admin/v1', '/wp-multi-network/sites/(?P<id>\d+)/move', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return current_user_can( 'manage_networks' );
		},
		'callback'            => 'minn_admin_wpmn_site_move',
		'args'                => array(
			'network' => array( 'type' => 'integer', 'required' => true ),
		),
	) );
} );

/** GET /wp-multi-network/networks. */
function minn_admin_wpmn_networks_list( WP_REST_Request $request ) {
	$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
	$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
	$args     = array(
		'number'        => $per_page,
		'offset'        => ( $page - 1 ) * $per_page,
		'orderby'       => 'domain',
		'order'         => 'ASC',
		'no_found_rows' => false,
	);
	$search = trim( (string) $request['search'] );
	if ( '' !== $search ) {
		$args['search'] = $search;
	}

	$query    = new WP_Network_Query( $args );
	$items    = array();
	$networks = (array) $query->networks;
	foreach ( $networks as $network ) {
		$row = minn_admin_wpmn_network_row( $network );
		if ( $row ) {
			$items[] = $row;
		}
	}
	return rest_ensure_response( array( 'items' => $items, 'total' => (int) $query->found_networks ) );
}

/** POST /wp-multi-network/networks. */
function minn_admin_wpmn_network_create( WP_REST_Request $request ) {
	$title      = Minn_Admin::plain_text( trim( (string) $request['title'] ) );
	$site_title = Minn_Admin::plain_text( trim( (string) $request['site_title'] ) );
	$domain     = strtolower( trim( sanitize_text_field( (string) $request['domain'] ) ) );
	$path       = wp_sanitize_site_path( (string) $request['path'] );

	if ( '' === $title ) {
		return new WP_Error( 'no_title', __( 'Give the network a title.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( '' === $domain || false !== strpos( $domain, '://' ) || ! preg_match( '/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $domain ) ) {
		return new WP_Error( 'bad_domain', __( 'Enter a domain name without a protocol or path.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( '/' !== substr( $path, 0, 1 ) || '/' !== substr( $path, -1 ) ) {
		return new WP_Error( 'bad_path', __( 'The path must begin and end with a slash.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( get_networks( array( 'domain' => $domain, 'path' => $path, 'number' => 1 ) ) ) {
		return new WP_Error( 'network_exists', __( 'A network already uses that domain and path.', 'minn-admin' ), array( 'status' => 400 ) );
	}

	$id = add_network(
		array(
			'domain'           => $domain,
			'path'             => $path,
			'site_name'        => $site_title ? $site_title : $title,
			'network_name'     => $title,
			'user_id'          => get_current_user_id(),
			'network_admin_id' => get_current_user_id(),
			'clone_network'    => get_current_network_id(),
			'options_to_clone' => array_keys( network_options_to_copy() ),
		)
	);
	if ( is_wp_error( $id ) ) {
		return new WP_Error( 'create_failed', $id->get_error_message(), array( 'status' => 500 ) );
	}
	if ( ! $id || ! get_network( $id ) ) {
		return new WP_Error( 'create_failed', __( 'The network could not be created.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response( minn_admin_wpmn_network_row( $id ) );
}

/**
 * The {id} segment from the route PATH.
 *
 * WP_REST_Request::offsetGet resolves body and query parameters ahead of URL
 * ones, so a stray id in the payload would silently retarget these verbs while
 * the path, and the confirmation the operator read, named something else.
 * network.php reads get_url_params() on every id-bearing handler for the same
 * reason. Destroying a network and re-parenting a site are the two least
 * reversible things this plugin does, so they get the same treatment.
 */
function minn_admin_wpmn_path_id( WP_REST_Request $request ) {
	$url = $request->get_url_params();
	return isset( $url['id'] ) ? (int) $url['id'] : 0;
}

/** DELETE /wp-multi-network/networks/{id}. */
/**
 * Does the caller administer THIS network?
 *
 * current_user_can( 'delete_network', $id ) reads like an object check and is not
 * one: WP Multi Network maps delete_network to the object-agnostic delete_networks,
 * which resolves to is_super_admin() -- and is_super_admin() answers for the CURRENT
 * network, because get_super_admins() reads the current network's own site_admins
 * list. On an install with several networks that means authority over the one you
 * are standing in was accepted as authority over the one being destroyed, along with
 * every site, user and setting inside it.
 *
 * @param int $id Network id.
 * @return true|WP_Error
 */
function minn_admin_wpmn_network_target( $id ) {
	$id = (int) $id;
	if ( ! $id || ! get_network( $id ) ) {
		return new WP_Error( 'no_such_network', __( 'That network does not exist.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	// A site-wide $super_admins pins one global list for every network, so there is
	// no per-network distinction to draw.
	if ( isset( $GLOBALS['super_admins'] ) ) {
		return true;
	}
	$admins = array_map( 'strtolower', (array) get_network_option( $id, 'site_admins', array() ) );
	$user   = wp_get_current_user();
	if ( ! $user || ! $user->exists() || ! in_array( strtolower( $user->user_login ), $admins, true ) ) {
		return new WP_Error(
			'foreign_network',
			__( 'You do not administer that network.', 'minn-admin' ),
			array( 'status' => 403 )
		);
	}
	return true;
}

function minn_admin_wpmn_network_delete( WP_REST_Request $request ) {
	$id      = minn_admin_wpmn_path_id( $request );
	$network = $id ? get_network( $id ) : null;
	if ( ! $network ) {
		return new WP_Error( 'no_such_network', __( 'That network does not exist.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( is_main_network( $id ) ) {
		return new WP_Error( 'main_network', __( 'The primary network cannot be deleted.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( $id === (int) get_current_network_id() ) {
		return new WP_Error( 'current_network', __( 'You are working in this network right now. Switch to another network first, then delete it.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$target = minn_admin_wpmn_network_target( $id );
	if ( is_wp_error( $target ) ) {
		return $target;
	}

	// The vendor defaults $delete_blogs to false and derives it from a checkbox on
	// its own screen; forcing it meant one request destroyed every site inside the
	// network with no server-side statement of intent.
	$delete_sites = rest_sanitize_boolean( $request['delete_sites'] );

	require_once ABSPATH . 'wp-admin/includes/ms.php';
	$result = delete_network( $id, $delete_sites );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'delete_failed', $result->get_error_message(), array( 'status' => 500 ) );
	}
	return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
}

/** POST /wp-multi-network/sites/{id}/move. */
function minn_admin_wpmn_site_move( WP_REST_Request $request ) {
	$site_id    = minn_admin_wpmn_path_id( $request );
	$network_id = (int) $request['network'];
	$site       = $site_id ? get_site( $site_id ) : null;
	$network    = get_network( $network_id );

	if ( ! $site ) {
		return new WP_Error( 'no_such_site', __( 'That site does not exist.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	// A network administrator is an administrator OF A NETWORK: is_super_admin
	// resolves against the current network's own site_admins list. Authority
	// over the destination says nothing about the network the site is leaving,
	// so scope the source the way every site-targeting route in network.php
	// does through minn_admin_network_target().
	if ( (int) $site->network_id !== (int) get_current_network_id() ) {
		return new WP_Error( 'foreign_site', __( 'That site is not part of this network.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( ! $network ) {
		return new WP_Error( 'no_such_network', __( 'That destination network does not exist.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( (int) get_main_site_id( $site->network_id ) === $site_id ) {
		return new WP_Error( 'main_site', __( 'A network main site cannot be moved.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( (int) $site->network_id === $network_id ) {
		return new WP_Error( 'same_network', __( 'That site already belongs to the selected network.', 'minn-admin' ), array( 'status' => 400 ) );
	}

	$result = move_site( $site_id, $network_id );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'move_failed', $result->get_error_message(), array( 'status' => 500 ) );
	}
	$fresh = get_site( $site_id );
	if ( ! $fresh || (int) $fresh->network_id !== $network_id ) {
		return new WP_Error( 'move_failed', __( 'The site could not be moved.', 'minn-admin' ), array( 'status' => 500 ) );
	}

	return rest_ensure_response(
		array(
			'moved'   => true,
			'id'      => $site_id,
			'network' => $network_id,
			'message' => __( 'Site moved to its new network.', 'minn-admin' ),
		)
	);
}
