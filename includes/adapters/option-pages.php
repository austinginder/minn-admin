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
 *             'adminPage' => 'business-info',       // its wp-admin menu slug, optional
 *         );
 *         return $pages;
 *     } );
 *
 * `route` answers GET with { groups, values, adminUrl } and POST with the
 * same after saving { values }, which is the settings contract in
 * docs/for-plugin-authors.md. `adminPage` names the wp-admin page the entry
 * mirrors (a tab may name its own); a tab whose page a menu tool such as
 * White Label CMS hid from this user leaves the surface too. Minn dispatches to it internally, so the
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
 * @param bool $all Keep tabs whose wp-admin page a menu tool hid from this
 *                  user. The surface declares those pages from this list, so
 *                  the next menu capture still asks about them.
 * @return array[] Entries with a normalized shape.
 */
function minn_admin_option_pages( $all = false ) {
	$hidden = $all ? array() : array_flip( Minn_Admin_Notices::hidden_admin_pages() );
	$out    = array();
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
		$host    = (string) ( $page['adminPage'] ?? '' );
		$tabs    = array();
		$dropped = false;
		foreach ( (array) ( $page['tabs'] ?? array() ) as $tab ) {
			if ( empty( $tab['id'] ) ) {
				continue;
			}
			$tab_host = isset( $tab['adminPage'] ) ? (string) $tab['adminPage'] : $host;
			if ( '' !== $tab_host && isset( $hidden[ $tab_host ] ) ) {
				$dropped = true;
				continue;
			}
			$tabs[] = array(
				'id'        => (string) $tab['id'],
				'label'     => (string) ( $tab['label'] ?? $tab['id'] ),
				'adminPage' => $tab_host,
			);
		}
		if ( ! $tabs ) {
			continue; // a page with nothing to edit is not a tab
		}
		$label = (string) ( $page['label'] ?? $page['id'] );
		// A provider names a page for every tab it sent. With the others
		// hidden, the one tab left says what is actually there.
		if ( $dropped && 1 === count( $tabs ) ) {
			$label = $tabs[0]['label'];
		}
		$out[] = array(
			'id'     => (string) $page['id'],
			'label'  => $label,
			'source' => (string) ( $page['source'] ?? '' ),
			'plugin' => (array) ( $page['plugin'] ?? array() ),
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
	$every = minn_admin_option_pages( true );
	if ( ! $every ) {
		return $surfaces;
	}
	// The wp-admin pages the tabs mirror. Declared even while hidden, or the
	// next menu capture would stop asking and bring them back. Only when
	// every tab names one: the surface leaves once all of them are hidden,
	// and a tab with no page of its own must not go with them.
	$hosts = array();
	foreach ( $every as $p ) {
		foreach ( $p['tabs'] as $t ) {
			if ( '' === $t['adminPage'] ) {
				$hosts = null;
				break 2;
			}
			$hosts[ $t['adminPage'] ] = true;
		}
	}
	$hosts = $hosts ? array_keys( $hosts ) : array();
	$tabs  = minn_admin_option_tabs_merged();
	$pages = $tabs ? minn_admin_option_pages() : $every;
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
		'label'      => $label,
		'sub'        => 1 === count( $sources ) ? (string) key( $sources ) : '',
		'plugin'     => array_values( array_unique( array_merge( ...array_map( function ( $p ) { return $p['plugin']; }, $pages ) ) ) ),
		'icon'       => 'gear',
		'group'      => 'tools',
		'cap'        => $pages[0]['cap'], // every page re-checks its own on request
		'adminPages' => $hosts,
		'settings'   => array(
			'label' => __( 'Settings', 'minn-admin' ),
			'tabs'  => $tab_list,
			'route' => 'minn-admin/v1/options/{tab}',
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	minn_admin_endpoint( '/options/(?P<tab>tab-\d+)', array(
		'methods'             => 'GET',
		// Someone who may open at least one contributed page; each
		// provider's own route still asks its capability.
		'gate'                => function () {
			return (bool) minn_admin_option_pages();
		},
		'run'                 => function ( WP_REST_Request $req ) {
			// A tab that does not resolve is either not there or not this
			// reader's; either way the honest answer is that there is no
			// such settings tab, and the provider decides the rest.
			$target = minn_admin_option_tab_target( (string) Minn_Admin::path_param( $req, 'tab' ) );
			if ( ! $target ) {
				return new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			return minn_admin_option_dispatch( $target, 'GET' );
		},
	) );
	minn_admin_endpoint( '/options/(?P<tab>tab-\d+)', array(
		'methods'             => 'POST',
		// Someone who may open at least one contributed page; each
		// provider's own route still asks its capability.
		'gate'                => function () {
			return (bool) minn_admin_option_pages();
		},
		'run'                 => function ( WP_REST_Request $req ) {
			// A tab that does not resolve is either not there or not this
			// reader's; either way the honest answer is that there is no
			// such settings tab, and the provider decides the rest.
			$target = minn_admin_option_tab_target( (string) Minn_Admin::path_param( $req, 'tab' ) );
			if ( ! $target ) {
				return new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			// The body only, as JSON: a form-encoded save would reach the
			// provider as no change and answer as saved.
			$body = $req->get_json_params();
			if ( ! is_array( $body ) ) {
				return new WP_Error( 'invalid_body', __( 'Send the values as JSON.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return minn_admin_option_dispatch( $target, 'POST', $body );
		},
	) );
} );
