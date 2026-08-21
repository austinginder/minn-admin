<?php
/**
 * Site options: one place for every plugin's option pages.
 *
 * ACF, ACPT and anything else that keeps site-wide fields each used to put
 * their own entries in the sidebar, so a site running two of them grew two
 * or three navigation items describing the same kind of thing. They gather
 * here instead, under one item, each page a tab.
 *
 * A provider contributes through `minn_admin_option_pages`:
 *
 *     add_filter( 'minn_admin_option_pages', function ( $pages ) {
 *         $pages[] = array(
 *             'id'     => 'my-plugin:business-info', // unique, stable
 *             'label'  => 'Business info',           // the tab's name
 *             'source' => 'My Plugin',               // badge, optional
 *             'cap'    => 'manage_options',          // who sees the item
 *             'tabs'   => array( array( 'id' => 'tab-0', 'label' => 'Details' ) ),
 *             'route'  => 'my-plugin/v1/options/business-info/{tab}',
 *         );
 *         return $pages;
 *     } );
 *
 * `route` answers GET with { groups, values, adminUrl } and POST with the
 * same after saving { values }, which is the settings contract in
 * docs/for-plugin-authors.md. Minn dispatches to it internally, so the
 * provider's own permission callback still runs on every request: this
 * surface gathers pages, it does not vouch for them.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** The surface id, and the sentinel its route carries. */
const MINN_ADMIN_OPTIONS_SURFACE = 'site-options';

/**
 * Every contributed page, filtered to the ones this user may open.
 *
 * @return array[] Entries with a normalized shape.
 */
function minn_admin_option_pages() {
	$out = array();
	foreach ( (array) apply_filters( 'minn_admin_option_pages', array() ) as $page ) {
		if ( ! is_array( $page ) || empty( $page['id'] ) || empty( $page['route'] ) ) {
			continue;
		}
		$cap = isset( $page['cap'] ) ? (string) $page['cap'] : 'manage_options';
		// An empty capability denies everyone, the way WordPress reads it on
		// its own menus; a provider that means "anyone" says 'read'.
		if ( '' === $cap || ! current_user_can( $cap ) ) {
			continue;
		}
		$tabs = array();
		foreach ( (array) ( $page['tabs'] ?? array() ) as $tab ) {
			if ( empty( $tab['id'] ) ) {
				continue;
			}
			$tabs[] = array(
				'id'    => (string) $tab['id'],
				'label' => (string) ( $tab['label'] ?? $tab['id'] ),
			);
		}
		if ( ! $tabs ) {
			continue; // a page with nothing to edit is not a tab
		}
		$out[] = array(
			'id'     => (string) $page['id'],
			'label'  => (string) ( $page['label'] ?? $page['id'] ),
			'source' => (string) ( $page['source'] ?? '' ),
			'cap'    => $cap,
			'tabs'   => $tabs,
			'route'  => (string) $page['route'],
		);
	}
	return $out;
}

/**
 * The merged tab list, and what each merged tab delegates to.
 *
 * Tab ids are positional over the whole merged list, which is what the
 * settings view asks for, and both the surface and the dispatch route
 * re-derive the same walk so they always agree.
 *
 * @return array[] { id, label, _route, _tab, _source }
 */
function minn_admin_option_tabs_merged() {
	$pages = minn_admin_option_pages();
	// Only say which page a tab belongs to when there is another page it
	// could be confused with. One page's tabs are already all its own, and
	// "Theme options · General" reads as a stutter under a heading that
	// already says Theme options.
	$qualify = count( $pages ) > 1;
	$out     = array();
	foreach ( $pages as $page ) {
		$single = 1 === count( $page['tabs'] );
		foreach ( $page['tabs'] as $tab ) {
			$out[] = array(
				// A page with one tab is named for the page: "Business info"
				// says more than the box inside it happens to be called.
				'label'   => $single ? $page['label'] : ( $qualify ? $page['label'] . ' · ' . $tab['label'] : $tab['label'] ),
				'id'      => 'tab-' . count( $out ),
				'_route'  => $page['route'],
				'_tab'    => $tab['id'],
				'_source' => $page['source'],
			);
		}
	}
	return $out;
}

/** Resolve a merged tab id to the provider request it stands for. */
function minn_admin_option_tab_target( $tab_id ) {
	foreach ( minn_admin_option_tabs_merged() as $tab ) {
		if ( $tab['id'] === $tab_id ) {
			return $tab;
		}
	}
	return null;
}

/**
 * Hand one request to the provider that owns the tab.
 *
 * Dispatched through the REST server rather than called directly, so the
 * provider's permission callback runs exactly as it would for a direct
 * request. Minn never decides on a provider's behalf who may read or write
 * its settings.
 *
 * @param array       $target Merged tab entry.
 * @param string      $method GET or POST.
 * @param array|null  $body   POST body.
 * @return WP_REST_Response|WP_Error
 */
function minn_admin_option_dispatch( $target, $method, $body = null ) {
	$path = '/' . ltrim( str_replace( '{tab}', rawurlencode( $target['_tab'] ), $target['_route'] ), '/' );
	$req  = new WP_REST_Request( $method, $path );
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	return rest_ensure_response( $res->get_data() );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	$tabs = minn_admin_option_tabs_merged();
	if ( ! $tabs ) {
		return $surfaces;
	}
	$pages = minn_admin_option_pages();
	// One page keeps its own name: a site whose only options page is
	// "Business info" should say so rather than be renamed by Minn.
	$label = 1 === count( $pages ) ? $pages[0]['label'] : __( 'Site Options', 'minn-admin' );
	// The badge names the source only while there is one to name.
	$sources = array();
	foreach ( $pages as $p ) {
		if ( '' !== $p['source'] ) {
			$sources[ $p['source'] ] = true;
		}
	}
	$tab_list = array();
	foreach ( $tabs as $t ) {
		$tab_list[] = array( 'id' => $t['id'], 'label' => $t['label'] );
	}
	$surfaces[ MINN_ADMIN_OPTIONS_SURFACE ] = array(
		'label'    => $label,
		'sub'      => 1 === count( $sources ) ? (string) key( $sources ) : '',
		'icon'     => 'gear',
		'group'    => 'tools',
		'cap'      => $pages[0]['cap'], // every page re-checks its own on request
		'settings' => array(
			'label' => __( 'Settings', 'minn-admin' ),
			'tabs'  => $tab_list,
			'route' => 'minn-admin/v1/options/{tab}',
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'minn-admin/v1', '/options/(?P<tab>tab-\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( $req ) {
				// A tab that does not resolve is either not there or not this
				// reader's; either way the honest answer is that there is no
				// such settings tab, and the provider decides the rest.
				return minn_admin_option_tab_target( (string) $req['tab'] )
					? true
					: new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
			},
			'callback'            => function ( $req ) {
				$target = minn_admin_option_tab_target( (string) $req['tab'] );
				return minn_admin_option_dispatch( $target, 'GET' );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) {
				// A tab that does not resolve is either not there or not this
				// reader's; either way the honest answer is that there is no
				// such settings tab, and the provider decides the rest.
				return minn_admin_option_tab_target( (string) $req['tab'] )
					? true
					: new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
			},
			'callback'            => function ( $req ) {
				$target = minn_admin_option_tab_target( (string) $req['tab'] );
				return minn_admin_option_dispatch( $target, 'POST', (array) $req->get_json_params() );
			},
		),
	) );
} );
