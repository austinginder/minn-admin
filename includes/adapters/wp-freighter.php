<?php
/**
 * Bundled adapter: WP Freighter (multi-tenant WordPress).
 *
 * WP Freighter runs many sites out of one install by giving each tenant its
 * own table prefix (stacked_<id>_) and, depending on its files mode, its own
 * content/<id>/ directory. It is the multisite idea without multisite: no
 * shared users table, no network options, one registry option
 * (`stacked_sites`) on the main site's prefix and a generated bootstrap
 * (wp-content/freighter.php) that picks the tenant per request by hostname
 * (domain mapping on) or by cookie (domain mapping off).
 *
 * This adapter gives that the same shape Minn gives a multisite network: a
 * Tenants group in the sidebar with a Tenant sites surface (list, status
 * card, create, clone, rename, delete, Minn on/off per tenant), the topbar
 * site switcher listing the main site and every tenant, and a settings tab
 * for the two Freighter settings that matter (files mode, domain mapping).
 * Everything is built on the ordinary surface descriptor contract, the same
 * primitives every third-party adapter gets.
 *
 * Switching is different from multisite in one important way. Tenants do
 * not share accounts, so there is no session to carry over; WP Freighter's
 * own answer is a one-time magic login that signs you in as an administrator
 * of the target (its `Log in` button, `wp freighter login`). Minn rides that
 * exact mechanism: the switcher and the Open actions ask a Minn route to mint
 * the link through \WPFreighter\Site::login() and then follow it. The token
 * is minted per click, never listed, and is single-use.
 *
 * Capability model: WP Freighter gates everything (its Tools page, its REST
 * namespace, the admin-bar switcher) on manage_options in whatever context
 * the request runs, main site or tenant. This adapter uses the same gate.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** WP Freighter's developer API is loaded (the plugin is active here). */
function minn_admin_freighter_active() {
	return class_exists( '\WPFreighter\Sites' ) && class_exists( '\WPFreighter\Site' ) && class_exists( '\WPFreighter\Configurations' );
}

/** WP Freighter's own gate, for every route and the surface itself. */
function minn_admin_freighter_can() {
	return minn_admin_freighter_active() && current_user_can( 'manage_options' );
}

/**
 * The tenant this request runs in, or 0 on the main site.
 *
 * The generated bootstrap sets $stacked_site_id at file scope (included from
 * wp-config.php), which makes it a global, and WP Freighter's own admin page
 * reads it the same way.
 */
function minn_admin_freighter_current_id() {
	return isset( $GLOBALS['stacked_site_id'] ) ? (int) $GLOBALS['stacked_site_id'] : 0;
}

/**
 * The main site's table prefix. Inside a tenant the bootstrap parks the
 * original prefix in TABLE_PREFIX before switching $table_prefix; on the main
 * site $wpdb->prefix already is it. (WP Freighter's own classes use this
 * exact resolution, including the odd 'TABLE_PREFIX' literal guard.)
 */
function minn_admin_freighter_primary_prefix() {
	global $wpdb;
	if ( defined( 'TABLE_PREFIX' ) && 'TABLE_PREFIX' !== TABLE_PREFIX ) {
		return TABLE_PREFIX;
	}
	return $wpdb->prefix;
}

/**
 * Read one option raw from a specific prefix's options table, without
 * entering that site's context. Prefixes are built from integer tenant ids
 * or the primary prefix, never from request input.
 */
function minn_admin_freighter_raw_option( $prefix, $name ) {
	global $wpdb;
	$table = $prefix . 'options';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- prefix built from a trusted integer id.
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$table} WHERE option_name = %s LIMIT 1", $name ) );
}

/** Whether a tenant's tables exist (a half-created or half-deleted tenant has none). */
function minn_admin_freighter_tenant_exists( $id ) {
	global $wpdb;
	$table = 'stacked_' . (int) $id . '_options';
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

/** Freighter's configuration (files mode + domain mapping) as a plain object, without the lazy bootstrap write get() performs. */
function minn_admin_freighter_configs() {
	$raw = json_decode( ( new \WPFreighter\Configurations() )->get_json(), true );
	return (object) array(
		'files'          => isset( $raw['files'] ) ? (string) $raw['files'] : 'shared',
		'domain_mapping' => isset( $raw['domain_mapping'] ) && 'on' === $raw['domain_mapping'] ? 'on' : 'off',
	);
}

/** The main site's home URL, read raw so it is right from inside a tenant too. */
function minn_admin_freighter_main_url() {
	$home = minn_admin_freighter_raw_option( minn_admin_freighter_primary_prefix(), 'home' );
	return $home ? untrailingslashit( $home ) : untrailingslashit( home_url() );
}

/** The main site's title, read raw. */
function minn_admin_freighter_main_name() {
	$name = minn_admin_freighter_raw_option( minn_admin_freighter_primary_prefix(), 'blogname' );
	return Minn_Admin::plain_text( $name ? $name : __( 'Main site', 'minn-admin' ) );
}

/**
 * The address a tenant answers on. With domain mapping on and a domain set,
 * its own hostname; otherwise the main site's address (cookie mode: the
 * tenant is reached through the switcher).
 */
function minn_admin_freighter_tenant_url( $site, $configs ) {
	$domain = isset( $site['domain'] ) ? trim( (string) $site['domain'] ) : '';
	if ( 'on' === $configs->domain_mapping && '' !== $domain ) {
		return 'https://' . $domain;
	}
	return minn_admin_freighter_main_url();
}

/** Minn's app URL for a site whose options live at $prefix, from outside its context. */
function minn_admin_freighter_app_url_for( $prefix, $base_url ) {
	$pretty = (string) minn_admin_freighter_raw_option( $prefix, 'permalink_structure' );
	return '' !== $pretty
		? $base_url . '/minn-admin/'
		: $base_url . '/?' . Minn_Admin::QUERY_VAR . '=1';
}

/** Whether Minn Admin is in a tenant's active_plugins (its own option row, read raw). */
function minn_admin_freighter_tenant_has_minn( $id ) {
	$raw    = minn_admin_freighter_raw_option( 'stacked_' . (int) $id . '_', 'active_plugins' );
	$active = $raw ? Minn_Admin::decode_serialized( $raw, array() ) : array();
	return is_array( $active ) && in_array( plugin_basename( MINN_ADMIN_FILE ), $active, true );
}

/**
 * Whether Minn's files are reachable from a tenant's plugin root. Shared and
 * hybrid tenants read the host's plugins folder, where this file lives;
 * dedicated tenants have their own content/<id>/plugins and only carry Minn
 * when someone has put it there.
 */
function minn_admin_freighter_tenant_can_run_minn( $id, $configs ) {
	if ( 'dedicated' !== $configs->files ) {
		return true;
	}
	return file_exists( ABSPATH . 'content/' . (int) $id . '/plugins/minn-admin/minn-admin.php' );
}

/** Registered accounts on a tenant (its own users table). */
function minn_admin_freighter_tenant_user_count( $id ) {
	global $wpdb;
	$table = 'stacked_' . (int) $id . '_users';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return 0;
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table built from a trusted integer id.
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
}

/** Human label for a files mode. */
function minn_admin_freighter_files_label( $mode ) {
	switch ( $mode ) {
		case 'dedicated':
			return __( 'Dedicated', 'minn-admin' );
		case 'hybrid':
			return __( 'Hybrid', 'minn-admin' );
		default:
			return __( 'Shared', 'minn-admin' );
	}
}

/** One sentence on what a files mode means. */
function minn_admin_freighter_files_hint( $mode ) {
	switch ( $mode ) {
		case 'dedicated':
			return __( 'A whole content folder per tenant: its own plugins, themes and uploads', 'minn-admin' );
		case 'hybrid':
			return __( 'Shared plugins and themes; each tenant keeps its own uploads', 'minn-admin' );
		default:
			return __( 'One wp-content for every tenant: plugins, themes and uploads', 'minn-admin' );
	}
}

/**
 * One tenant's row for the list and detail modal.
 *
 * The `can*` fields drive the descriptor's `when` gates so the SERVER decides
 * what a row offers: the tenant you are standing in never offers delete or
 * an Open (you are already there), a tenant without Minn offers "Turn on
 * Minn Admin" only where the files can actually be reached. Every route
 * re-derives the same rules.
 */
function minn_admin_freighter_row( $site, $configs = null ) {
	$configs = $configs ? $configs : minn_admin_freighter_configs();
	$id      = (int) $site['stacked_site_id'];
	$current = $id === minn_admin_freighter_current_id();
	$domain  = isset( $site['domain'] ) ? trim( (string) $site['domain'] ) : '';
	$name    = isset( $site['name'] ) ? Minn_Admin::plain_text( $site['name'] ) : '';
	$exists  = minn_admin_freighter_tenant_exists( $id );
	$minn    = $exists && minn_admin_freighter_tenant_has_minn( $id );
	$runnable = minn_admin_freighter_tenant_can_run_minn( $id, $configs );
	// A row naming the main site's own prefix offers no actions (see target()).
	$primary  = minn_admin_freighter_is_primary_id( $id );

	switch ( $configs->files ) {
		case 'dedicated':
			$content = 'content/' . $id . '/';
			break;
		case 'hybrid':
			$content = 'content/' . $id . '/uploads/';
			break;
		default:
			$content = 'wp-content/';
	}

	return array(
		'id'         => $id,
		'name'       => '' !== $name ? $name : ( '' !== $domain ? $domain : sprintf(
			/* translators: %d: tenant id. */
			__( 'Tenant %d', 'minn-admin' ),
			$id
		) ),
		'domain'     => $domain,
		'url'        => minn_admin_freighter_tenant_url( $site, $configs ),
		'users'      => $exists ? minn_admin_freighter_tenant_user_count( $id ) : 0,
		'content'    => $content,
		'created'    => gmdate( 'Y-m-d\TH:i:s\Z', (int) $site['created_at'] ),
		'minn'       => $minn ? 'active' : 'inactive',
		'status'     => $primary ? __( 'main site', 'minn-admin' ) : ( $current ? __( 'current', 'minn-admin' ) : ( $exists ? __( 'ready', 'minn-admin' ) : __( 'missing', 'minn-admin' ) ) ),
		// UI gates (see the docblock): what this row may OFFER.
		'canOpen'    => ( ! $current && ! $primary && $exists ) ? '1' : '0',
		'canOpenMinn' => ( ! $current && ! $primary && $exists && $minn ) ? '1' : '0',
		'canEnableMinn' => ( ! $primary && $exists && ! $minn && $runnable ) ? '1' : '0',
		'canDisableMinn' => ( ! $current && ! $primary && $exists && $minn ) ? '1' : '0',
		'canDelete'  => ( $current || $primary ) ? '0' : '1',
	);
}

/** The registry entry for a tenant id, or a 404 error. */
function minn_admin_freighter_target( $id ) {
	$id = (int) $id;
	// A row whose id spells the main site's own prefix (a host whose main
	// site sits on stacked_N_) names the main database: delete would drop
	// it, clone would copy it, the Minn toggle would rewrite its plugins.
	// WP Freighter's id allocator no longer hands such an id out, but a row
	// inherited from before it did is refused here, ahead of the lookup.
	if ( minn_admin_freighter_is_primary_id( $id ) ) {
		return new WP_Error( 'primary_tenant', __( 'That entry points at the main site itself, not a tenant.', 'minn-admin' ), array( 'status' => 409 ) );
	}
	$site = $id ? \WPFreighter\Site::get( $id ) : null;
	if ( ! $site || ! is_array( $site ) ) {
		return new WP_Error( 'no_such_tenant', __( 'That tenant does not exist.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return $site;
}

/** Whether a tenant id's table prefix is the main site's own prefix. */
function minn_admin_freighter_is_primary_id( $id ) {
	return 'stacked_' . (int) $id . '_' === minn_admin_freighter_primary_prefix();
}

/** Hostname validation for a mapped domain: lowercase labels, dots, hyphens; nothing else. */
function minn_admin_freighter_clean_domain( $domain ) {
	$domain = strtolower( trim( (string) $domain ) );
	$domain = preg_replace( '#^https?://#', '', $domain );
	$domain = rtrim( $domain, '/' );
	if ( '' === $domain ) {
		return '';
	}
	if ( ! preg_match( '/^(?=.{1,253}$)(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $domain ) ) {
		return new WP_Error( 'bad_domain', __( 'Enter a hostname such as store.example.com, with no scheme or path.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return $domain;
}

/** A domain already used by another tenant or by the main site is refused. */
function minn_admin_freighter_domain_taken( $domain, $except_id = 0 ) {
	if ( '' === $domain ) {
		return false;
	}
	$main = wp_parse_url( minn_admin_freighter_main_url(), PHP_URL_HOST );
	if ( $main && strtolower( $main ) === $domain ) {
		return true;
	}
	foreach ( (array) \WPFreighter\Sites::fetch() as $site ) {
		if ( (int) $site['stacked_site_id'] !== (int) $except_id && isset( $site['domain'] ) && strtolower( trim( (string) $site['domain'] ) ) === $domain ) {
			return true;
		}
	}
	return false;
}

/* ---------- Surface ---------- */

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_freighter_can() ) {
		return $surfaces;
	}
	$configs = minn_admin_freighter_configs();
	$mapping = 'on' === $configs->domain_mapping;
	$user    = wp_get_current_user();

	$create_fields = array(
		array( 'key' => 'title', 'label' => __( 'Site title', 'minn-admin' ), 'required' => true ),
		array(
			'key'         => 'name',
			'label'       => __( 'Name in the list', 'minn-admin' ),
			'placeholder' => __( 'Staging, Client demo…', 'minn-admin' ),
			'required'    => false,
		),
	);
	if ( $mapping ) {
		$create_fields[] = array(
			'key'         => 'domain',
			'label'       => __( 'Domain', 'minn-admin' ),
			'mono'        => true,
			'placeholder' => 'store.example.com',
			'required'    => true,
		);
	}
	$create_fields[] = array( 'key' => 'username', 'label' => __( 'Administrator username', 'minn-admin' ), 'mono' => true, 'value' => $user->user_login, 'required' => true );
	$create_fields[] = array( 'key' => 'email', 'label' => __( 'Administrator email', 'minn-admin' ), 'type' => 'email', 'value' => $user->user_email, 'required' => true );
	$create_fields[] = array(
		'key'         => 'password',
		'label'       => __( 'Administrator password', 'minn-admin' ),
		'mono'        => true,
		'placeholder' => __( 'Leave empty to generate one', 'minn-admin' ),
		'required'    => false,
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name in the list', 'minn-admin' ) ),
	);
	if ( $mapping ) {
		$edit_fields[] = array( 'key' => 'domain', 'label' => __( 'Domain', 'minn-admin' ), 'mono' => true );
	}

	$clone_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name for the copy', 'minn-admin' ), 'required' => false, 'placeholder' => __( '⟨name⟩ (Clone)', 'minn-admin' ) ),
	);
	if ( $mapping ) {
		$clone_fields[] = array( 'key' => 'domain', 'label' => __( 'Domain for the copy', 'minn-admin' ), 'mono' => true, 'placeholder' => 'copy.example.com', 'required' => true );
	}

	$columns = array(
		array( 'key' => 'name', 'label' => __( 'Tenant', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.2fr)' ),
	);
	if ( $mapping ) {
		$columns[] = array( 'key' => 'domain', 'label' => __( 'Domain', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.3fr)' );
	}
	$columns[] = array( 'key' => 'users', 'label' => __( 'Accounts', 'minn-admin' ), 'format' => 'num', 'width' => '90px' );
	$columns[] = array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true, 'width' => '120px' );
	$columns[] = array( 'key' => 'minn', 'label' => __( 'Minn Admin', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' );

	$surfaces['freighter-sites'] = array(
		'label'      => __( 'Tenant sites', 'minn-admin' ),
		'sub'        => __( 'WP Freighter', 'minn-admin' ),
		'plugin'     => 'wp-freighter',
		'group'      => 'network',
		'icon'       => 'grid',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/freighter/status' ),
		'collection' => array(
			'route'    => 'minn-admin/v1/freighter/sites',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'viewLabel' => __( 'Tenants', 'minn-admin' ),
			'search'   => 'search={q}',
			'tabs'     => array(
				'param'    => 'minn',
				'allLabel' => __( 'All tenants', 'minn-admin' ),
				'static'   => array(
					array( 'active', __( 'Minn on', 'minn-admin' ) ),
					array( 'inactive', __( 'Minn off', 'minn-admin' ) ),
				),
			),
			'columns'  => $columns,
			'create'   => array(
				'label'  => __( 'New tenant', 'minn-admin' ),
				'route'  => 'minn-admin/v1/freighter/sites',
				'method' => 'POST',
				'fields' => $create_fields,
			),
			'detail'   => array(
				'skip' => array( 'canOpen', 'canOpenMinn', 'canEnableMinn', 'canDisableMinn', 'canDelete' ),
				'edit' => array(
					'route'  => 'minn-admin/v1/freighter/sites/{id}',
					'method' => 'POST',
					'fields' => $edit_fields,
				),
			),
			'actions'  => array(
				array(
					'label'  => __( 'Open in Minn', 'minn-admin' ),
					'route'  => 'minn-admin/v1/freighter/sites/{id}/login',
					'body'   => array( 'to' => 'minn' ),
					'follow' => true,
					'when'   => array( 'key' => 'canOpenMinn', 'equals' => '1' ),
				),
				array(
					'label'  => __( 'Open in WordPress', 'minn-admin' ),
					'route'  => 'minn-admin/v1/freighter/sites/{id}/login',
					'body'   => array( 'to' => 'admin' ),
					'follow' => true,
					'when'   => array( 'key' => 'canOpen', 'equals' => '1' ),
				),
				array( 'label' => __( 'Visit site ↗', 'minn-admin' ), 'href' => '{url}' ),
				array(
					'label' => __( 'Turn on Minn Admin', 'minn-admin' ),
					'route' => 'minn-admin/v1/freighter/sites/{id}/minn',
					'body'  => array( 'on' => true ),
					'when'  => array( 'key' => 'canEnableMinn', 'equals' => '1' ),
				),
				array(
					'label'   => __( 'Turn off Minn Admin', 'minn-admin' ),
					'route'   => 'minn-admin/v1/freighter/sites/{id}/minn',
					'body'    => array( 'on' => false ),
					'when'    => array( 'key' => 'canDisableMinn', 'equals' => '1' ),
					'confirm' => __( 'Turn Minn Admin off on this tenant? Its WordPress admin stays as it is.', 'minn-admin' ),
				),
				array(
					'label'  => __( 'Clone tenant', 'minn-admin' ),
					'route'  => 'minn-admin/v1/freighter/sites/{id}/clone',
					'fields' => $clone_fields,
					'list'   => true,
				),
				array(
					'label'   => __( 'Delete tenant', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/freighter/sites/{id}',
					'when'    => array( 'key' => 'canDelete', 'equals' => '1' ),
					'confirm' => __( 'Delete this tenant permanently? Its database tables and its own content folder are removed. This cannot be undone.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
		'settings'   => array(
			'label' => __( 'Freighter settings', 'minn-admin' ),
			'tabs'  => array(
				array( 'id' => 'freighter', 'label' => __( 'Freighter', 'minn-admin' ) ),
			),
			'route' => 'minn-admin/v1/freighter/settings/{tab}',
		),
	);
	return $surfaces;
} );

/** The sidebar group these surfaces live in is named for what it holds. */
add_filter( 'minn_admin_nav_group_labels', function ( $labels ) {
	if ( minn_admin_freighter_can() && ! is_multisite() ) {
		$labels['network'] = __( 'Tenants', 'minn-admin' );
	}
	return $labels;
} );

/**
 * The topbar site switcher: the main site plus every tenant.
 *
 * Entries other than the current one carry a `login` route instead of an
 * `app` URL; the client asks it for a one-time link and follows it, because
 * a tenant has no session to reuse (see the file docblock).
 */
add_filter( 'minn_admin_sites', function ( $payload ) {
	if ( is_multisite() || ! minn_admin_freighter_can() ) {
		return $payload;
	}
	$tenants = (array) \WPFreighter\Sites::fetch();
	if ( ! $tenants ) {
		return $payload;
	}
	$configs = minn_admin_freighter_configs();
	$current = minn_admin_freighter_current_id();
	$main    = minn_admin_freighter_main_url();
	$sites   = array(
		array(
			'id'      => 'main',
			'name'    => minn_admin_freighter_main_name(),
			'url'     => $main . '/',
			'app'     => 0 === $current ? Minn_Admin::app_url() : '',
			'login'   => 0 === $current ? '' : 'minn-admin/v1/freighter/sites/main/login',
			'current' => 0 === $current,
		),
	);
	foreach ( $tenants as $site ) {
		$id  = (int) $site['stacked_site_id'];
		$row = minn_admin_freighter_row( $site, $configs );
		$sites[] = array(
			'id'      => $id,
			'name'    => $row['name'],
			'url'     => $row['url'] . '/',
			'app'     => $id === $current ? Minn_Admin::app_url() : '',
			'login'   => $id === $current ? '' : 'minn-admin/v1/freighter/sites/' . $id . '/login',
			'current' => $id === $current,
		);
	}
	// Main first, then tenants by name; the cap keeps a big host to a menu.
	$rest = array_slice( $sites, 1 );
	usort( $rest, function ( $a, $b ) {
		return strcasecmp( $a['name'], $b['name'] );
	} );
	$sites = array_merge( array( $sites[0] ), $rest );
	$total = count( $sites );
	return array(
		'sites' => array_slice( $sites, 0, Minn_Admin::SITES_MENU_LIMIT ),
		'total' => $total,
	);
} );

/* ---------- REST ---------- */

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_freighter_active() ) {
		return;
	}
	$can = 'minn_admin_freighter_can';

	register_rest_route( 'minn-admin/v1', '/freighter/status', array(
		'methods'             => 'GET',
		'permission_callback' => $can,
		'callback'            => 'minn_admin_freighter_status',
	) );

	register_rest_route( 'minn-admin/v1', '/freighter/sites', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => 'minn_admin_freighter_sites_list',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => 'minn_admin_freighter_sites_create',
			'args'                => array(
				'title'    => array( 'type' => 'string', 'required' => true ),
				'name'     => array( 'type' => 'string' ),
				'domain'   => array( 'type' => 'string' ),
				'username' => array( 'type' => 'string', 'required' => true ),
				'email'    => array( 'type' => 'string', 'required' => true ),
				'password' => array( 'type' => 'string' ),
			),
		),
	) );

	// 'main' or a tenant id: the login route is the one verb the main site
	// answers to, so the switcher can carry you back.
	register_rest_route( 'minn-admin/v1', '/freighter/sites/(?P<id>main|\d+)/login', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => 'minn_admin_freighter_site_login',
		'args'                => array(
			'to' => array( 'type' => 'string', 'enum' => array( 'minn', 'admin' ), 'default' => 'minn' ),
		),
	) );

	register_rest_route( 'minn-admin/v1', '/freighter/sites/(?P<id>\d+)', array(
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => 'minn_admin_freighter_site_update',
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $can,
			'callback'            => 'minn_admin_freighter_site_delete',
		),
	) );

	register_rest_route( 'minn-admin/v1', '/freighter/sites/(?P<id>\d+)/clone', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => 'minn_admin_freighter_site_clone',
	) );

	register_rest_route( 'minn-admin/v1', '/freighter/sites/(?P<id>\d+)/minn', array(
		'methods'             => 'POST',
		'permission_callback' => $can,
		'callback'            => 'minn_admin_freighter_site_minn',
		'args'                => array(
			'on' => array( 'type' => 'boolean', 'required' => true ),
		),
	) );

	register_rest_route( 'minn-admin/v1', '/freighter/settings/(?P<tab>[a-z0-9_-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => 'minn_admin_freighter_settings_get',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => 'minn_admin_freighter_settings_post',
		),
	) );
} );

/** GET /freighter/status — the card above the tenant list. */
function minn_admin_freighter_status() {
	$configs = minn_admin_freighter_configs();
	$tenants = (array) \WPFreighter\Sites::fetch();
	$current = minn_admin_freighter_current_id();
	$main    = minn_admin_freighter_main_url();
	$mapping = 'on' === $configs->domain_mapping;

	$rows = array(
		array(
			'label' => __( 'Tenants', 'minn-admin' ),
			'value' => number_format_i18n( count( $tenants ) ),
			'hint'  => $current
				? sprintf(
					/* translators: %d: tenant id. */
					__( 'You are working in tenant %d', 'minn-admin' ),
					$current
				)
				: __( 'You are working on the main site', 'minn-admin' ),
		),
		array(
			'label' => __( 'Main site', 'minn-admin' ),
			'value' => preg_replace( '#^https?://#', '', $main ),
			'hint'  => __( 'The install every tenant runs out of', 'minn-admin' ),
		),
		array(
			'label' => __( 'Files', 'minn-admin' ),
			'value' => minn_admin_freighter_files_label( $configs->files ),
			'hint'  => minn_admin_freighter_files_hint( $configs->files ),
		),
		array(
			'label' => __( 'Domain mapping', 'minn-admin' ),
			'value' => $mapping ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ),
			'hint'  => $mapping
				? __( 'Each tenant answers on its own hostname; point DNS and the host at this install', 'minn-admin' )
				: __( 'Tenants ride on the main site\'s address and are reached through the switcher', 'minn-admin' ),
		),
	);
	$actions = array();
	if ( $current ) {
		$actions[] = array(
			'label'  => __( 'Back to the main site', 'minn-admin' ),
			'route'  => 'minn-admin/v1/freighter/sites/main/login',
			'method' => 'POST',
			'follow' => true,
		);
	}
	$actions[] = array( 'label' => __( 'WP Freighter ↗', 'minn-admin' ), 'href' => admin_url( 'tools.php?page=wp-freighter' ) );
	return rest_ensure_response(
		array(
			'rows'    => $rows,
			'command' => array(
				'label' => __( 'Run WP-CLI inside a tenant', 'minn-admin' ),
				'text'  => 'STACKED_SITE_ID=' . ( $current ? $current : '<id>' ) . ' wp option get home',
				'hint'  => __( 'Every wp command runs in the tenant named by STACKED_SITE_ID; without it you are on the main site.', 'minn-admin' ),
			),
			'actions' => $actions,
		)
	);
}

/** GET /freighter/sites — the registry, filtered and searched in PHP (it is one option, never large). */
function minn_admin_freighter_sites_list( WP_REST_Request $request ) {
	$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
	$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
	$search   = strtolower( trim( (string) $request['search'] ) );
	$minn     = sanitize_key( (string) $request['minn'] );
	$configs  = minn_admin_freighter_configs();

	$rows = array();
	foreach ( (array) \WPFreighter\Sites::fetch() as $site ) {
		$row = minn_admin_freighter_row( $site, $configs );
		if ( '' !== $minn && $row['minn'] !== $minn ) {
			continue;
		}
		if ( '' !== $search ) {
			$hay = strtolower( $row['name'] . ' ' . $row['domain'] . ' ' . $row['url'] . ' ' . $row['id'] );
			if ( false === strpos( $hay, $search ) ) {
				continue;
			}
		}
		$rows[] = $row;
	}
	// Newest first, the way WP Freighter's own table lists them.
	usort( $rows, function ( $a, $b ) {
		return strcmp( $b['created'], $a['created'] ) ?: $b['id'] <=> $a['id'];
	} );
	$total = count( $rows );
	$items = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
	return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
}

/** POST /freighter/sites — a new tenant through \WPFreighter\Site::create(). */
function minn_admin_freighter_sites_create( WP_REST_Request $request ) {
	$configs  = minn_admin_freighter_configs();
	$title    = sanitize_text_field( (string) $request['title'] );
	$name     = sanitize_text_field( (string) $request['name'] );
	$username = sanitize_user( (string) $request['username'], true );
	$email    = sanitize_email( (string) $request['email'] );
	$password = (string) $request['password'];
	$domain   = '';

	if ( '' === trim( $title ) ) {
		return new WP_Error( 'no_title', __( 'Give the tenant a site title.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( '' === $username ) {
		return new WP_Error( 'bad_username', __( 'Enter a username for the tenant\'s first administrator.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'bad_email', __( 'Enter a valid email address.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( 'on' === $configs->domain_mapping ) {
		$domain = minn_admin_freighter_clean_domain( $request['domain'] );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}
		if ( '' === $domain ) {
			return new WP_Error( 'no_domain', __( 'Domain mapping is on, so every tenant needs a hostname.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( minn_admin_freighter_domain_taken( $domain ) ) {
			return new WP_Error( 'domain_taken', __( 'Another site already answers on that hostname.', 'minn-admin' ), array( 'status' => 400 ) );
		}
	}
	if ( '' === $name ) {
		$name = $title;
	}

	$result = \WPFreighter\Site::create(
		array(
			'title'    => $title,
			'name'     => $name,
			'domain'   => $domain,
			'username' => $username,
			'email'    => $email,
			'password' => '' !== $password ? $password : wp_generate_password(),
		)
	);
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'create_failed', wp_strip_all_tags( $result->get_error_message() ), array( 'status' => 500 ) );
	}
	if ( ! is_array( $result ) ) {
		return new WP_Error( 'create_failed', __( 'WP Freighter did not report the new tenant.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response( minn_admin_freighter_row( $result ) );
}

/**
 * Whether a sign-in link (or the siteurl it will be built from) lands on
 * the exact origin this install knows the tenant by.
 *
 * Compares scheme, host and port, and refuses anything a browser and PHP
 * could read differently: userinfo, a backslash in the authority, or a
 * scheme that is not http(s). The one-time token rides this URL.
 *
 * @param string $url  The stored siteurl or the minted link.
 * @param string $base The origin the tenant answers on.
 * @return true|WP_Error
 */
function minn_admin_freighter_login_origin_check( $url, $base ) {
	$url  = trim( (string) $url );
	$want = wp_parse_url( $base );
	$got  = wp_parse_url( $url );
	$want_scheme = isset( $want['scheme'] ) ? strtolower( (string) $want['scheme'] ) : 'https';
	$want_host   = isset( $want['host'] ) ? strtolower( (string) $want['host'] ) : '';
	$want_port   = isset( $want['port'] ) ? (int) $want['port'] : 0;
	$got_scheme  = is_array( $got ) && isset( $got['scheme'] ) ? strtolower( (string) $got['scheme'] ) : '';
	$got_host    = is_array( $got ) && isset( $got['host'] ) ? strtolower( (string) $got['host'] ) : '';
	$got_port    = is_array( $got ) && isset( $got['port'] ) ? (int) $got['port'] : 0;
	$authority   = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $url );
	$authority   = (string) preg_replace( '#[/?\#].*$#s', '', $authority );
	$ok = is_array( $got )
		&& '' !== $got_host
		&& in_array( $got_scheme, array( 'http', 'https' ), true )
		&& $got_scheme === $want_scheme
		&& $got_host === $want_host
		&& $got_port === $want_port
		&& ! isset( $got['user'] ) && ! isset( $got['pass'] )
		&& false === strpos( $authority, '@' )
		&& false === strpos( $authority, '\\' )
		&& false === strpos( $url, '\\' );
	if ( $ok ) {
		return true;
	}
	return new WP_Error( 'login_host_mismatch', sprintf(
		/* translators: 1: the address the tenant answers on, 2: the address its settings name. */
		__( 'That site’s address (%2$s) does not match where this host expects it (%1$s), so the sign-in link was not followed. Check its Site Address setting.', 'minn-admin' ),
		$want_host . ( $want_port ? ':' . $want_port : '' ),
		'' !== $got_host ? $got_host . ( $got_port ? ':' . $got_port : '' ) : $url
	), array( 'status' => 409 ) );
}

/**
 * POST /freighter/sites/{id}/login — a one-time link into a site, the way
 * WP Freighter's own Log in button works, aimed at Minn or at wp-admin.
 *
 * {id} is 'main' or a tenant id. In cookie mode (domain mapping off) the
 * link itself carries the tenant id, and WP Freighter sets the cookie and
 * reloads before the token is checked; returning to main has to clear that
 * cookie here, which is what its own Exit does.
 */
function minn_admin_freighter_site_login( WP_REST_Request $request ) {
	$url = $request->get_url_params();
	$id  = isset( $url['id'] ) ? (string) $url['id'] : '';
	$to  = 'admin' === $request['to'] ? 'admin' : 'minn';
	$configs = minn_admin_freighter_configs();

	if ( 'main' === $id ) {
		if ( 0 === minn_admin_freighter_current_id() ) {
			return new WP_Error( 'current_tenant', __( 'You are already working on the main site.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$base   = minn_admin_freighter_main_url();
		$prefix = minn_admin_freighter_primary_prefix();
		$has_minn = true; // Minn answered this request from the same install's plugins folder.
		if ( 'dedicated' === $configs->files && minn_admin_freighter_current_id() ) {
			// Standing in a dedicated tenant, "this file" is the tenant's copy;
			// ask the main site's own option row.
			$raw      = minn_admin_freighter_raw_option( $prefix, 'active_plugins' );
			$active   = $raw ? Minn_Admin::decode_serialized( $raw, array() ) : array();
			$has_minn = is_array( $active ) && in_array( plugin_basename( MINN_ADMIN_FILE ), $active, true );
		}
		// Leaving a cookie-mode tenant: without this the bootstrap keeps
		// reading the old cookie and the main-site link signs into the tenant.
		if ( isset( $_COOKIE['stacked_site_id'] ) ) {
			setcookie( 'stacked_site_id', '', time() - 3600, '/' );
			unset( $_COOKIE['stacked_site_id'] );
		}
		$target = 'main';
	} else {
		$site = minn_admin_freighter_target( $id );
		if ( is_wp_error( $site ) ) {
			return $site;
		}
		$target = (int) $site['stacked_site_id'];
		if ( $target === minn_admin_freighter_current_id() ) {
			return new WP_Error( 'current_tenant', __( 'You are already working in that tenant.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( ! minn_admin_freighter_tenant_exists( $target ) ) {
			return new WP_Error( 'tenant_missing', __( 'That tenant has no database tables to sign in to.', 'minn-admin' ), array( 'status' => 409 ) );
		}
		$base     = minn_admin_freighter_tenant_url( $site, $configs );
		$prefix   = 'stacked_' . $target . '_';
		$has_minn = minn_admin_freighter_tenant_has_minn( $target );
		// What WP Freighter's own switch does first: on dedicated hosts the
		// plugin self-heals its files and activation on the target.
		\WPFreighter\Site::ensure_freighter( $target );
	}

	$redirect = 'minn' === $to && $has_minn
		? minn_admin_freighter_app_url_for( $prefix, $base )
		: $base . '/wp-admin/';

	// WP Freighter builds the link on the target's own siteurl row, which
	// that tenant's administrator controls. The browser is about to follow
	// it carrying a one-time token, so it must land on the origin this
	// install knows the tenant by (its mapped domain, or the main host),
	// never wherever siteurl was pointed. Checked on the stored row BEFORE
	// a token is minted, so a refused sign-in leaves nothing live behind.
	$origin = minn_admin_freighter_login_origin_check( (string) minn_admin_freighter_raw_option( $prefix, 'siteurl' ), $base );
	if ( is_wp_error( $origin ) ) {
		return $origin;
	}

	$link = \WPFreighter\Site::login( $target, $redirect );
	if ( is_wp_error( $link ) ) {
		return new WP_Error( 'login_failed', wp_strip_all_tags( $link->get_error_message() ), array( 'status' => 500 ) );
	}
	// The minted link should be siteurl plus a path; hold it to the same
	// origin in case the plugin composes it differently than the row reads.
	$origin = minn_admin_freighter_login_origin_check( $link, $base );
	if ( is_wp_error( $origin ) ) {
		return $origin;
	}
	return rest_ensure_response(
		array(
			'url'     => $link,
			'minn'    => 'minn' === $to && $has_minn,
			'message' => 'minn' === $to && ! $has_minn
				? __( 'Minn Admin is not turned on there, so this opens WordPress instead.', 'minn-admin' )
				: '',
		)
	);
}

/** POST /freighter/sites/{id} — rename, or change the mapped domain. */
function minn_admin_freighter_site_update( WP_REST_Request $request ) {
	$url  = $request->get_url_params();
	$site = minn_admin_freighter_target( isset( $url['id'] ) ? $url['id'] : 0 );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	$id      = (int) $site['stacked_site_id'];
	$configs = minn_admin_freighter_configs();
	$args    = array();

	if ( null !== $request->get_param( 'name' ) ) {
		$args['name'] = sanitize_text_field( (string) $request->get_param( 'name' ) );
	}
	if ( null !== $request->get_param( 'domain' ) ) {
		if ( 'on' !== $configs->domain_mapping ) {
			return new WP_Error( 'mapping_off', __( 'Turn domain mapping on in Freighter settings before giving tenants their own hostnames.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$domain = minn_admin_freighter_clean_domain( $request->get_param( 'domain' ) );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}
		if ( '' === $domain ) {
			return new WP_Error( 'no_domain', __( 'Domain mapping is on, so every tenant needs a hostname.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( minn_admin_freighter_domain_taken( $domain, $id ) ) {
			return new WP_Error( 'domain_taken', __( 'Another site already answers on that hostname.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$args['domain'] = $domain;
	}
	if ( ! $args ) {
		return rest_ensure_response( minn_admin_freighter_row( $site, $configs ) );
	}
	$fresh = \WPFreighter\Site::update( $id, $args );
	if ( ! is_array( $fresh ) ) {
		return new WP_Error( 'update_failed', __( 'WP Freighter did not save that change.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	// A new hostname is only live once the bootstrap's mapping table names it.
	if ( isset( $args['domain'] ) ) {
		( new \WPFreighter\Configurations() )->refresh_configs();
	}
	return rest_ensure_response( minn_admin_freighter_row( $fresh, $configs ) );
}

/** POST /freighter/sites/{id}/clone — a copy of a tenant, through WP Freighter's own cloner. */
function minn_admin_freighter_site_clone( WP_REST_Request $request ) {
	$url  = $request->get_url_params();
	$site = minn_admin_freighter_target( isset( $url['id'] ) ? $url['id'] : 0 );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	$id      = (int) $site['stacked_site_id'];
	$configs = minn_admin_freighter_configs();
	if ( ! minn_admin_freighter_tenant_exists( $id ) ) {
		return new WP_Error( 'tenant_missing', __( 'That tenant has no database tables to copy.', 'minn-admin' ), array( 'status' => 409 ) );
	}
	$args = array(
		'name'   => sanitize_text_field( (string) $request->get_param( 'name' ) ),
		'domain' => '',
	);
	if ( 'on' === $configs->domain_mapping ) {
		$domain = minn_admin_freighter_clean_domain( $request->get_param( 'domain' ) );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}
		if ( '' === $domain ) {
			return new WP_Error( 'no_domain', __( 'Domain mapping is on, so the copy needs its own hostname.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( minn_admin_freighter_domain_taken( $domain ) ) {
			return new WP_Error( 'domain_taken', __( 'Another site already answers on that hostname.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$args['domain'] = $domain;
	}
	$result = \WPFreighter\Site::clone( $id, $args );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'clone_failed', wp_strip_all_tags( $result->get_error_message() ), array( 'status' => 500 ) );
	}
	if ( ! is_array( $result ) ) {
		return new WP_Error( 'clone_failed', __( 'WP Freighter did not report the copy.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	$row = minn_admin_freighter_row( $result, $configs );
	$row['message'] = sprintf(
		/* translators: %s: the new tenant's name. */
		__( 'Copied as %s.', 'minn-admin' ),
		$row['name']
	);
	return rest_ensure_response( $row );
}

/**
 * POST /freighter/sites/{id}/minn — turn Minn on or off inside a tenant by
 * editing that tenant's own active_plugins row, the way WP Freighter keeps
 * itself active on tenants (Site::ensure_freighter). Minn's activation hook
 * does not run in the tenant, and does not need to: the plugin heals its own
 * rewrite rule on the tenant's next request (Minn_Admin::maybe_heal_rewrites).
 */
function minn_admin_freighter_site_minn( WP_REST_Request $request ) {
	global $wpdb;
	$url  = $request->get_url_params();
	$site = minn_admin_freighter_target( isset( $url['id'] ) ? $url['id'] : 0 );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	$id      = (int) $site['stacked_site_id'];
	$on      = (bool) $request['on'];
	$configs = minn_admin_freighter_configs();
	if ( ! minn_admin_freighter_tenant_exists( $id ) ) {
		return new WP_Error( 'tenant_missing', __( 'That tenant has no database tables.', 'minn-admin' ), array( 'status' => 409 ) );
	}
	if ( ! $on && $id === minn_admin_freighter_current_id() ) {
		return new WP_Error( 'self', __( 'Turn Minn Admin off from its own card, which explains what happens first.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( $on && ! minn_admin_freighter_tenant_can_run_minn( $id, $configs ) ) {
		return new WP_Error(
			'no_files',
			__( 'This tenant keeps its own plugins folder and Minn Admin is not in it. Install the plugin there first.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	$table  = 'stacked_' . $id . '_options';
	$file   = plugin_basename( MINN_ADMIN_FILE );
	$raw    = minn_admin_freighter_raw_option( 'stacked_' . $id . '_', 'active_plugins' );
	$active = $raw ? Minn_Admin::decode_serialized( $raw, array() ) : array();
	if ( ! is_array( $active ) ) {
		$active = array();
	}
	$active = array_values( array_filter( $active, function ( $p ) use ( $file ) {
		return $p !== $file;
	} ) );
	if ( $on ) {
		$active[] = $file;
		sort( $active );
	}
	$value  = serialize( $active ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- core's own option shape.
	$exists = null !== $raw;
	if ( $exists ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table built from a trusted integer id.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET option_value = %s WHERE option_name = 'active_plugins'", $value ) );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (option_name, option_value, autoload) VALUES ('active_plugins', %s, 'yes')", $value ) );
	}
	// The tenant caches its options in the object cache under its own salt,
	// so a persistent cache there may hold the old row until it expires or
	// the tenant's next option write; the raw row is the truth and is what
	// the next request loads when the cache misses.
	$row = minn_admin_freighter_row( \WPFreighter\Site::get( $id ), $configs );
	$row['message'] = $on
		? __( 'Minn Admin is on for that tenant. Open in Minn to use it there.', 'minn-admin' )
		: __( 'Minn Admin is off for that tenant.', 'minn-admin' );
	return rest_ensure_response( $row );
}

/** DELETE /freighter/sites/{id} — permanent, through WP Freighter's own teardown. */
function minn_admin_freighter_site_delete( WP_REST_Request $request ) {
	$url  = $request->get_url_params();
	$site = minn_admin_freighter_target( isset( $url['id'] ) ? $url['id'] : 0 );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	$id = (int) $site['stacked_site_id'];
	if ( $id === minn_admin_freighter_current_id() ) {
		return new WP_Error(
			'current_tenant',
			__( 'You are working in this tenant right now. Switch to the main site first, then delete it.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	$ok = \WPFreighter\Site::delete( $id );
	if ( ! $ok ) {
		return new WP_Error( 'delete_failed', __( 'WP Freighter could not delete that tenant.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return rest_ensure_response( array( 'deleted' => true, 'id' => $id ) );
}

/** The two Freighter settings Minn edits, in the settings form's own shape. */
function minn_admin_freighter_settings_fields() {
	return array(
		array(
			'title'  => __( 'Files', 'minn-admin' ),
			'fields' => array(
				array(
					'key'     => 'files',
					'label'   => __( 'How much of wp-content tenants share', 'minn-admin' ),
					'type'    => 'select',
					'options' => array(
						array( 'shared', __( 'Shared: one wp-content for every site', 'minn-admin' ) ),
						array( 'hybrid', __( 'Hybrid: shared plugins and themes, uploads per tenant', 'minn-admin' ) ),
						array( 'dedicated', __( 'Dedicated: a whole content folder per tenant', 'minn-admin' ) ),
					),
					'desc'    => __( 'Changing the mode does not move existing files.', 'minn-admin' ),
				),
			),
		),
		array(
			'title'  => __( 'Domain mapping', 'minn-admin' ),
			'fields' => array(
				array(
					'key'   => 'domain_mapping',
					'label' => __( 'Each tenant answers on its own hostname', 'minn-admin' ),
					'type'  => 'toggle',
					'desc'  => __( 'Off: tenants ride on the main site\'s address and are reached through the switcher. On: set a domain per tenant, then point DNS and your host at this install.', 'minn-admin' ),
				),
			),
		),
	);
}

/** GET /freighter/settings/{tab} — the form model. */
function minn_admin_freighter_settings_get( WP_REST_Request $request ) {
	$url = $request->get_url_params();
	$tab = isset( $url['tab'] ) ? sanitize_key( $url['tab'] ) : '';
	if ( 'freighter' !== $tab ) {
		return new WP_Error( 'bad_tab', __( 'Unknown settings section.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$configs = minn_admin_freighter_configs();
	$groups  = array();
	foreach ( minn_admin_freighter_settings_fields() as $group ) {
		$group['locked'] = 0;
		$groups[]        = $group;
	}
	return rest_ensure_response(
		array(
			'groups'   => $groups,
			'values'   => array(
				'files'          => $configs->files,
				'domain_mapping' => 'on' === $configs->domain_mapping,
			),
			'adminUrl' => admin_url( 'tools.php?page=wp-freighter' ),
		)
	);
}

/**
 * POST /freighter/settings/{tab} — save, then re-read.
 *
 * Writes go through WP Freighter's own Configurations::update() (which
 * regenerates the bootstrap) and then Sites::update() with the registry as
 * it stands, because that is the call that rewrites each tenant's home and
 * siteurl to match the new mapping mode.
 */
function minn_admin_freighter_settings_post( WP_REST_Request $request ) {
	$url = $request->get_url_params();
	$tab = isset( $url['tab'] ) ? sanitize_key( $url['tab'] ) : '';
	if ( 'freighter' !== $tab ) {
		return new WP_Error( 'bad_tab', __( 'Unknown settings section.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$values  = $request->get_param( 'values' );
	$values  = is_array( $values ) ? $values : array();
	$configs = minn_admin_freighter_configs();
	$next    = array(
		'files'          => $configs->files,
		'domain_mapping' => $configs->domain_mapping,
	);
	if ( array_key_exists( 'files', $values ) ) {
		$files = (string) $values['files'];
		if ( ! in_array( $files, array( 'shared', 'hybrid', 'dedicated' ), true ) ) {
			return new WP_Error( 'bad_files', __( 'Files mode must be shared, hybrid or dedicated.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$next['files'] = $files;
	}
	if ( array_key_exists( 'domain_mapping', $values ) ) {
		$next['domain_mapping'] = rest_sanitize_boolean( $values['domain_mapping'] ) ? 'on' : 'off';
	}
	if ( $next !== array( 'files' => $configs->files, 'domain_mapping' => $configs->domain_mapping ) ) {
		( new \WPFreighter\Configurations() )->update( $next );
		( new \WPFreighter\Sites() )->update( (array) \WPFreighter\Sites::fetch() );
	}
	return minn_admin_freighter_settings_get( $request );
}
