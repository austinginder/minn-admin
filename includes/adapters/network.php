<?php
/**
 * Bundled adapter: Network (multisite).
 *
 * The Network nav group — surfaces that belong to the whole network rather
 * than the site you happen to be standing in. This one covers SITES: the
 * list, its states (public, archived, spam, deleted), creating one, and the
 * lifecycle verbs, each through core's own multisite functions.
 *
 * Deliberately built on the ordinary surface descriptor contract rather than
 * bespoke client code (docs/for-plugin-authors.md): the list, tabs, search,
 * detail modal, row actions and create form are the same primitives every
 * third-party adapter gets. Network admin is the hardest test of that
 * contract, so if it needs a client branch, the contract has a gap worth
 * fixing instead.
 *
 * Capability model: these are NETWORK capabilities (manage_sites,
 * create_sites, delete_sites), which WordPress grants to super admins only.
 * They are checked per route, and the destructive verbs re-check the target
 * server-side rather than trusting the `can*` flags the list ships for the
 * UI — those flags decide what to OFFER, never what to allow.
 *
 * Not covered here on purpose: per-site option editing (that is the site's
 * own Settings, one click away through "Open in Minn") and network settings
 * (Network Admin). Restores, exports and the network setup screen stay in
 * wp-admin.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Every network route needs multisite plus a network capability. */
function minn_admin_network_can( $cap = 'manage_sites' ) {
	return is_multisite() && current_user_can( $cap );
}

/**
 * One site's row for the list and detail modal.
 *
 * The `can*` fields drive the descriptor's `when` gates so the SERVER decides
 * which verbs a row offers (the main site never offers archive/spam/delete;
 * the site you are browsing never offers delete). Each route re-derives the
 * same rules, so a hand-made request cannot act on what the UI would refuse.
 */
function minn_admin_network_site_row( $site, $counts = array() ) {
	$id      = (int) $site->blog_id;
	$main    = $id === (int) get_main_site_id();
	$current = $id === (int) get_current_blog_id();
	$flags   = array(
		'archived' => '1' === (string) $site->archived,
		'spam'     => '1' === (string) $site->spam,
		'deleted'  => '1' === (string) $site->deleted,
		'public'   => '1' === (string) $site->public,
	);
	if ( $flags['deleted'] ) {
		$status = 'deleted';
	} elseif ( $flags['spam'] ) {
		$status = 'spam';
	} elseif ( $flags['archived'] ) {
		$status = 'archived';
	} else {
		$status = $flags['public'] ? 'public' : 'private';
	}
	$url = 'https://' . $site->domain . $site->path;
	if ( ! is_ssl() ) {
		$url = set_url_scheme( $url, 'http' );
	}
	// The site's own Minn address: per-site permalink structure decides
	// whether it lives at /minn-admin/ or behind the query-var fallback.
	$app = $url;
	switch_to_blog( $id );
	$title = Minn_Admin::plain_text( get_bloginfo( 'name' ) );
	$app   = Minn_Admin::app_url();
	$url   = home_url( '/' );
	restore_current_blog();

	return array(
		'id'           => $id,
		'name'         => $title ? $title : $site->domain,
		'url'          => $url,
		'app'          => $app,
		'users'        => isset( $counts[ $id ] ) ? (int) $counts[ $id ] : 0,
		'registered'   => mysql_to_rfc3339( $site->registered ),
		'status'       => $status,
		// UI gates (see the docblock): what this row may OFFER.
		'canArchive'   => ( ! $main && ! $flags['archived'] ) ? '1' : '0',
		'canUnarchive' => $flags['archived'] ? '1' : '0',
		'canSpam'      => ( ! $main && ! $flags['spam'] ) ? '1' : '0',
		'canUnspam'    => $flags['spam'] ? '1' : '0',
		'canDelete'    => ( ! $main && ! $current ) ? '1' : '0',
	);
}

/**
 * Members per site for the listed page, in ONE query.
 *
 * A site's members are the users carrying that site's capabilities meta key
 * ({prefix}capabilities), so a single grouped count over the page's keys
 * beats a per-site count_users() (which joins all of usermeta each time).
 *
 * @param int[] $blog_ids Blog ids on the current page.
 * @return array<int,int> blog id => member count.
 */
function minn_admin_network_user_counts( $blog_ids ) {
	global $wpdb;
	if ( ! $blog_ids ) {
		return array();
	}
	$keys = array();
	$map  = array();
	foreach ( $blog_ids as $id ) {
		$id  = (int) $id;
		$key = $wpdb->get_blog_prefix( $id ) . 'capabilities';
		$keys[]      = $key;
		$map[ $key ] = $id;
	}
	$in   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT meta_key, COUNT(*) AS n FROM {$wpdb->usermeta} WHERE meta_key IN ({$in}) GROUP BY meta_key",
			$keys
		)
	);
	// phpcs:enable
	$out = array();
	foreach ( (array) $rows as $r ) {
		if ( isset( $map[ $r->meta_key ] ) ) {
			$out[ $map[ $r->meta_key ] ] = (int) $r->n;
		}
	}
	return $out;
}

/**
 * Resolve a site id from a route, refusing the ones no verb may touch.
 *
 * @param int    $id   Target blog id.
 * @param string $verb 'modify' (archive/spam) or 'delete'.
 * @return WP_Site|WP_Error
 */
function minn_admin_network_target( $id, $verb = 'modify' ) {
	$id   = (int) $id;
	$site = $id ? get_site( $id ) : null;
	if ( ! $site || (int) $site->network_id !== (int) get_current_network_id() ) {
		return new WP_Error( 'no_such_site', __( 'That site is not part of this network.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( $id === (int) get_main_site_id() ) {
		return new WP_Error(
			'main_site',
			__( 'The main site of the network cannot be archived, flagged or deleted.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	if ( 'delete' === $verb && $id === (int) get_current_blog_id() ) {
		return new WP_Error(
			'current_site',
			__( 'You are working on this site right now. Switch to another site first, then delete it.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	return $site;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_network_can( 'manage_sites' ) ) {
		return $surfaces;
	}
	$subdomain = defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL;

	$surfaces['network-sites'] = array(
		'label'      => __( 'Sites', 'minn-admin' ),
		'group'      => 'network',
		'icon'       => 'grid',
		'cap'        => 'manage_sites',
		'status'     => array( 'route' => 'minn-admin/v1/network/status' ),
		'collection' => array(
			'route'    => 'minn-admin/v1/network/sites',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'search'   => 'search={q}',
			'tabs'     => array(
				'param'    => 'status',
				'allLabel' => __( 'All sites', 'minn-admin' ),
				'static'   => array(
					array( 'public', __( 'Public', 'minn-admin' ) ),
					array( 'archived', __( 'Archived', 'minn-admin' ) ),
					array( 'spam', __( 'Spam', 'minn-admin' ) ),
					array( 'deleted', __( 'Deleted', 'minn-admin' ) ),
				),
			),
			'columns'  => array(
				array( 'key' => 'name', 'label' => __( 'Site', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'url', 'label' => __( 'Address', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.3fr)' ),
				array( 'key' => 'users', 'label' => __( 'Members', 'minn-admin' ), 'format' => 'num', 'width' => '90px' ),
				array( 'key' => 'registered', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true, 'width' => '120px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
			),
			'create'   => array(
				'label'  => __( 'Add site', 'minn-admin' ),
				'route'  => 'minn-admin/v1/network/sites',
				'method' => 'POST',
				'fields' => array(
					array(
						'key'         => 'address',
						'label'       => $subdomain ? __( 'Subdomain', 'minn-admin' ) : __( 'Address', 'minn-admin' ),
						'mono'        => true,
						'required'    => true,
						'placeholder' => $subdomain ? 'store' : 'store',
					),
					array( 'key' => 'title', 'label' => __( 'Site title', 'minn-admin' ), 'required' => true ),
					array(
						'key'         => 'email',
						'label'       => __( 'Administrator email', 'minn-admin' ),
						'type'        => 'email',
						'required'    => true,
						'placeholder' => __( 'An existing account on this network', 'minn-admin' ),
					),
				),
			),
			'detail'   => array(
				'skip' => array( 'app', 'canArchive', 'canUnarchive', 'canSpam', 'canUnspam', 'canDelete' ),
			),
			'actions'  => array(
				array( 'label' => __( 'Open in Minn ↗', 'minn-admin' ), 'href' => '{app}' ),
				array( 'label' => __( 'Visit site ↗', 'minn-admin' ), 'href' => '{url}' ),
				array(
					'label'  => __( 'Archive site', 'minn-admin' ),
					'route'  => 'minn-admin/v1/network/sites/{id}/flag',
					'body'   => array( 'flag' => 'archived', 'on' => true ),
					'when'   => array( 'key' => 'canArchive', 'equals' => '1' ),
					'confirm' => __( 'Archive this site? Visitors see a notice instead of the site until it is restored.', 'minn-admin' ),
				),
				array(
					'label' => __( 'Restore from archive', 'minn-admin' ),
					'route' => 'minn-admin/v1/network/sites/{id}/flag',
					'body'  => array( 'flag' => 'archived', 'on' => false ),
					'when'  => array( 'key' => 'canUnarchive', 'equals' => '1' ),
				),
				array(
					'label'   => __( 'Mark as spam', 'minn-admin' ),
					'route'   => 'minn-admin/v1/network/sites/{id}/flag',
					'body'    => array( 'flag' => 'spam', 'on' => true ),
					'when'    => array( 'key' => 'canSpam', 'equals' => '1' ),
					'confirm' => __( 'Mark this site as spam? It stops serving visitors immediately.', 'minn-admin' ),
				),
				array(
					'label' => __( 'Not spam', 'minn-admin' ),
					'route' => 'minn-admin/v1/network/sites/{id}/flag',
					'body'  => array( 'flag' => 'spam', 'on' => false ),
					'when'  => array( 'key' => 'canUnspam', 'equals' => '1' ),
				),
				array(
					'label'   => __( 'Delete site', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/network/sites/{id}',
					'when'    => array( 'key' => 'canDelete', 'equals' => '1' ),
					'confirm' => __( 'Delete this site permanently? Its posts, pages, media and settings are removed. This cannot be undone.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! is_multisite() ) {
		return;
	}
	$manage = function () {
		return minn_admin_network_can( 'manage_sites' );
	};

	register_rest_route( 'minn-admin/v1', '/network/sites', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $manage,
			'callback'            => 'minn_admin_network_sites_list',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return minn_admin_network_can( 'create_sites' );
			},
			'callback'            => 'minn_admin_network_sites_create',
			'args'                => array(
				'address' => array( 'type' => 'string', 'required' => true ),
				'title'   => array( 'type' => 'string', 'required' => true ),
				'email'   => array( 'type' => 'string', 'required' => true ),
			),
		),
	) );

	register_rest_route( 'minn-admin/v1', '/network/sites/(?P<id>\d+)/flag', array(
		'methods'             => 'POST',
		'permission_callback' => $manage,
		'callback'            => 'minn_admin_network_site_flag',
		'args'                => array(
			'flag' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'archived', 'spam' ) ),
			'on'   => array( 'type' => 'boolean', 'required' => true ),
		),
	) );

	register_rest_route( 'minn-admin/v1', '/network/sites/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => function () {
			return minn_admin_network_can( 'delete_sites' );
		},
		'callback'            => 'minn_admin_network_site_delete',
	) );

	register_rest_route( 'minn-admin/v1', '/network/status', array(
		'methods'             => 'GET',
		'permission_callback' => $manage,
		'callback'            => 'minn_admin_network_status',
	) );

	// The switcher's search, for ANY signed-in user — not a network
	// capability, because these are the person's OWN memberships. The result
	// set is derived from get_blogs_of_user() for the current user only, so
	// it can never become a directory of a network someone does not belong
	// to. Needed because a person can belong to more sites than a menu can
	// hold, which the capped boot payload deliberately does not carry.
	register_rest_route( 'minn-admin/v1', '/my-sites', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return is_multisite() && is_user_logged_in() && current_user_can( 'edit_posts' );
		},
		'callback'            => 'minn_admin_my_sites',
	) );
} );

/**
 * GET /my-sites — search across the sites the CURRENT user belongs to.
 *
 * Matching runs in SQL over domain and path (WP_Site_Query's own `search`,
 * the same field core's network Sites screen searches), so a user with
 * thousands of memberships costs one query plus a page of per-site reads —
 * never a full sweep. Site titles live in each site's own options table and
 * cannot be searched without entering every site, which is exactly the work
 * being avoided; the client filters the visible page by title on top.
 */
function minn_admin_my_sites( WP_REST_Request $request ) {
	$ids = Minn_Admin::user_site_ids();
	if ( ! $ids ) {
		return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
	}
	$per_page = min( 50, max( 1, (int) ( $request['per_page'] ?: 20 ) ) );
	$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
	$search   = trim( (string) $request['search'] );

	$args = array(
		'site__in'   => $ids,
		'network_id' => get_current_network_id(),
		'number'     => $per_page,
		'offset'     => ( $page - 1 ) * $per_page,
		'archived'   => '0',
		'spam'       => '0',
		'deleted'    => '0',
		'orderby'    => 'domain',
		'order'      => 'ASC',
	);
	if ( '' !== $search ) {
		$args['search'] = $search;
	}
	$query = new WP_Site_Query();
	$sites = $query->query( $args );
	$total = (int) $query->query( array_merge( $args, array( 'count' => true, 'number' => 0, 'offset' => 0 ) ) );

	$page_ids = array_map( function ( $s ) { return (int) $s->blog_id; }, (array) $sites );
	return rest_ensure_response(
		array(
			'items' => Minn_Admin::describe_sites( $page_ids ),
			'total' => $total,
		)
	);
}

/** GET /network/sites — paginated, searchable, status-filtered. */
function minn_admin_network_sites_list( WP_REST_Request $request ) {
	$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
	$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
	$status   = sanitize_key( (string) $request['status'] );
	$search   = trim( (string) $request['search'] );

	$args = array(
		'network_id' => get_current_network_id(),
		'number'     => $per_page,
		'offset'     => ( $page - 1 ) * $per_page,
		'orderby'    => 'registered',
		'order'      => 'DESC',
	);
	// Core's get_sites() treats these as tri-state; the default list shows
	// everything so an archived or spammed site can never hide from its
	// administrator (wp-admin's Sites screen behaves the same way).
	if ( 'archived' === $status ) {
		$args['archived'] = '1';
	} elseif ( 'spam' === $status ) {
		$args['spam'] = '1';
	} elseif ( 'deleted' === $status ) {
		$args['deleted'] = '1';
	} elseif ( 'public' === $status ) {
		$args['public']   = 1;
		$args['archived'] = '0';
		$args['spam']     = '0';
		$args['deleted']  = '0';
	}
	if ( '' !== $search ) {
		$args['search'] = $search;
	}

	$query = new WP_Site_Query();
	$sites = $query->query( $args );
	$total = (int) $query->query( array_merge( $args, array( 'count' => true, 'number' => 0, 'offset' => 0 ) ) );

	$ids    = array_map( function ( $s ) { return (int) $s->blog_id; }, (array) $sites );
	$counts = minn_admin_network_user_counts( $ids );
	$items  = array();
	foreach ( (array) $sites as $site ) {
		$items[] = minn_admin_network_site_row( $site, $counts );
	}
	return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
}

/**
 * POST /network/sites — create a site owned by an EXISTING network account.
 *
 * Deliberately not a user-creation path: wp-admin's Add Site will invent an
 * account for an unknown address, which is a bigger thing than adding a site
 * and belongs with the rest of account creation in Network Admin. An unknown
 * address gets an honest refusal naming that route (the same stance as
 * "Add existing user" on a subsite).
 */
function minn_admin_network_sites_create( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';

	$address = strtolower( trim( (string) $request['address'] ) );
	$address = preg_replace( '|^/+|', '', $address );
	$address = preg_replace( '|/+$|', '', $address );
	$title   = trim( (string) $request['title'] );
	$email   = sanitize_email( (string) $request['email'] );

	if ( '' === $address || ! preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $address ) ) {
		return new WP_Error(
			'bad_address',
			__( 'The address may use lowercase letters, numbers and hyphens only.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	if ( '' === $title ) {
		return new WP_Error( 'no_title', __( 'Give the site a title.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'bad_email', __( 'Enter a valid email address.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$user = get_user_by( 'email', $email );
	if ( ! $user ) {
		return new WP_Error(
			'no_such_user',
			__( 'No account on this network uses that email. Create the account in Network Admin first, then add the site.', 'minn-admin' ),
			array( 'status' => 404 )
		);
	}
	// Core's own reserved-name rules (subdirectory installs reserve wp-admin,
	// files, feed and friends), plus the site-exists check below.
	if ( ! is_subdomain_install() ) {
		$illegal = get_network_option( null, 'illegal_names', array() );
		if ( in_array( $address, (array) $illegal, true ) ) {
			return new WP_Error( 'reserved', __( 'That address is reserved. Choose another.', 'minn-admin' ), array( 'status' => 400 ) );
		}
	}

	$network = get_network();
	if ( is_subdomain_install() ) {
		$domain = $address . '.' . preg_replace( '|^www\.|', '', $network->domain );
		$path   = $network->path;
	} else {
		$domain = $network->domain;
		$path   = $network->path . $address . '/';
	}
	if ( domain_exists( $domain, $path, $network->id ) ) {
		return new WP_Error( 'site_exists', __( 'A site already uses that address.', 'minn-admin' ), array( 'status' => 400 ) );
	}

	$blog_id = wpmu_create_blog( $domain, $path, $title, $user->ID, array( 'public' => 1 ), $network->id );
	if ( is_wp_error( $blog_id ) ) {
		return new WP_Error( 'create_failed', $blog_id->get_error_message(), array( 'status' => 500 ) );
	}
	// Core's own notification to the new site's administrator; harmless when
	// mail is not configured (it is fire-and-forget in wp-admin too).
	if ( function_exists( 'wpmu_welcome_notification' ) ) {
		wpmu_welcome_notification( $blog_id, $user->ID, '', $title, array( 'public' => 1 ) );
	}
	$site = get_site( $blog_id );
	return rest_ensure_response( minn_admin_network_site_row( $site, array( $blog_id => 1 ) ) );
}

/** POST /network/sites/{id}/flag — archive or spam, on or off. */
function minn_admin_network_site_flag( WP_REST_Request $request ) {
	$url  = $request->get_url_params();
	$site = minn_admin_network_target( isset( $url['id'] ) ? $url['id'] : 0, 'modify' );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	$flag = (string) $request['flag'];
	$on   = (bool) $request['on'];
	// update_blog_status fires core's make_spam_blog / archive_blog hooks, so
	// plugins listening for these transitions still hear about them.
	update_blog_status( (int) $site->blog_id, $flag, $on ? '1' : '0' );
	$fresh = get_site( (int) $site->blog_id );
	return rest_ensure_response( minn_admin_network_site_row( $fresh ) );
}

/** DELETE /network/sites/{id} — permanent, through core's own teardown. */
function minn_admin_network_site_delete( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	$url  = $request->get_url_params();
	$site = minn_admin_network_target( isset( $url['id'] ) ? $url['id'] : 0, 'delete' );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	$id = (int) $site->blog_id;
	// wp_delete_site drops the site's tables and fires wp_delete_site /
	// wp_uninitialize_site so plugins can clean up their own storage.
	$result = wp_delete_site( $id );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
}

/** GET /network/status — the card above the Sites list. */
function minn_admin_network_status() {
	$network = get_network();
	$sites   = (int) get_blog_count();
	$users   = 0;
	if ( function_exists( 'get_user_count' ) ) {
		$users = (int) get_user_count();
	}
	$rows = array(
		array(
			'label' => __( 'Sites', 'minn-admin' ),
			'value' => number_format_i18n( $sites ),
			'hint'  => is_subdomain_install()
				? __( 'Subdomain network', 'minn-admin' )
				: __( 'Subdirectory network', 'minn-admin' ),
		),
		array(
			'label' => __( 'Accounts', 'minn-admin' ),
			'value' => number_format_i18n( $users ),
			'hint'  => __( 'Shared by every site on the network', 'minn-admin' ),
		),
		array(
			'label' => __( 'Network', 'minn-admin' ),
			'value' => $network ? $network->domain : '',
			'hint'  => sprintf(
				/* translators: %s: whether new registrations are open. */
				__( 'Registration: %s', 'minn-admin' ),
				minn_admin_network_registration_label()
			),
		),
	);
	$actions = array();
	if ( current_user_can( 'manage_network' ) ) {
		$actions[] = array( 'label' => __( 'Network Admin ↗', 'minn-admin' ), 'href' => network_admin_url( 'sites.php' ) );
	}
	return rest_ensure_response( array( 'rows' => $rows, 'actions' => $actions ) );
}

/** Human label for the network's registration setting. */
function minn_admin_network_registration_label() {
	$reg = get_network_option( null, 'registration', 'none' );
	$map = array(
		'none' => __( 'closed', 'minn-admin' ),
		'user' => __( 'accounts only', 'minn-admin' ),
		'blog' => __( 'sites only', 'minn-admin' ),
		'all'  => __( 'accounts and sites', 'minn-admin' ),
	);
	return isset( $map[ $reg ] ) ? $map[ $reg ] : (string) $reg;
}
