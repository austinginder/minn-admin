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

/**
 * Whether network-administrator status can be changed at all.
 *
 * Defining $super_admins in wp-config.php pins the list in code, and core's
 * grant_super_admin()/revoke_super_admin() then return false without doing
 * anything. Offering buttons that silently no-op is worse than saying why,
 * so the rows explain instead.
 */
function minn_admin_network_super_admins_locked() {
	return isset( $GLOBALS['super_admins'] );
}

/** One network user's row. */
function minn_admin_network_user_row( $user, $site_counts = array(), $super_count = 0 ) {
	$id     = (int) $user->ID;
	$super  = is_super_admin( $id );
	$self   = $id === get_current_user_id();
	$locked = minn_admin_network_super_admins_locked();
	return array(
		'id'         => $id,
		'name'       => $user->display_name,
		'email'      => $user->user_email,
		'username'   => $user->user_login,
		'sites'      => isset( $site_counts[ $id ] ) ? (int) $site_counts[ $id ] : 0,
		'registered' => mysql_to_rfc3339( $user->user_registered ),
		'status'     => $super ? 'network admin' : 'member',
		// Gates (the routes re-derive all of these):
		// never grant twice, never revoke your own status (that is a
		// self-lockout) and never revoke the last network administrator.
		'canGrant'   => ( ! $super && ! $locked ) ? '1' : '0',
		'canRevoke'  => ( $super && ! $self && ! $locked && $super_count > 1 ) ? '1' : '0',
	);
}

/**
 * How many sites each listed user belongs to, in ONE query.
 *
 * Membership is a per-site capabilities meta row ({base_prefix}capabilities,
 * {base_prefix}N_capabilities), so counting those rows per user beats calling
 * get_blogs_of_user() once per row. The LIKE is anchored to this network's
 * table prefix; a stray plugin key ending in "capabilities" would inflate a
 * count, which is a display number only and never a permission input.
 *
 * @param int[] $user_ids Users on the current page.
 * @return array<int,int> user id => site count.
 */
function minn_admin_network_site_counts( $user_ids ) {
	global $wpdb;
	if ( ! $user_ids ) {
		return array();
	}
	$ids  = implode( ',', array_map( 'intval', $user_ids ) );
	$like = $wpdb->esc_like( $wpdb->base_prefix ) . '%' . $wpdb->esc_like( 'capabilities' );
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids cast to int above.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT user_id, COUNT(*) AS n FROM {$wpdb->usermeta}
			 WHERE user_id IN ({$ids}) AND meta_key LIKE %s GROUP BY user_id",
			$like
		)
	);
	// phpcs:enable
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[ (int) $r->user_id ] = (int) $r->n;
	}
	return $out;
}

/**
 * The network settings Minn edits: the daily slice of wp-admin's network
 * settings screen, not all of it.
 *
 * Registration, uploads, what site administrators may do, and where network
 * mail goes are the ones an operator actually revisits. The long tail (welcome
 * emails, the first post's text, reserved names, language defaults) stays in
 * Network Admin behind a link, the same stance Minn takes on the single-site
 * settings long tail.
 *
 * Each entry declares how its option is STORED, because core's shapes differ
 * per option ('yes'/'no', '1'/'0', an int as a string), and the save path
 * writes those shapes exactly rather than inventing its own.
 */
function minn_admin_network_settings_spec() {
	return array(
		'registration' => array(
			'title'  => __( 'Registration', 'minn-admin' ),
			'fields' => array(
				array(
					'key'     => 'registration',
					'label'   => __( 'Anyone can register', 'minn-admin' ),
					'type'    => 'select',
					'store'   => 'enum',
					'options' => array(
						array( 'none', __( 'Registration is closed', 'minn-admin' ) ),
						array( 'user', __( 'Accounts may be registered', 'minn-admin' ) ),
						array( 'blog', __( 'Existing accounts may add sites', 'minn-admin' ) ),
						array( 'all', __( 'Accounts and sites may be registered', 'minn-admin' ) ),
					),
				),
				array(
					'key'   => 'registrationnotification',
					'label' => __( 'Email me when someone registers', 'minn-admin' ),
					'type'  => 'toggle',
					'store' => 'yesno',
				),
				array(
					'key'   => 'add_new_users',
					'label' => __( 'Site administrators may create accounts', 'minn-admin' ),
					'type'  => 'toggle',
					'store' => 'onezero',
				),
			),
		),
		'uploads'      => array(
			'title'  => __( 'Uploads', 'minn-admin' ),
			'fields' => array(
				array(
					'key'   => 'blog_upload_space',
					'label' => __( 'Storage per site (MB)', 'minn-admin' ),
					'type'  => 'number',
					'store' => 'int',
				),
				array(
					'key'   => 'fileupload_maxk',
					'label' => __( 'Largest single upload (KB)', 'minn-admin' ),
					'type'  => 'number',
					'store' => 'int',
				),
				array(
					'key'         => 'upload_filetypes',
					'label'       => __( 'Allowed file types', 'minn-admin' ),
					'type'        => 'textarea',
					'store'       => 'types',
					'rows'        => 4,
					'mono'        => true,
					'placeholder' => 'jpg jpeg png gif pdf',
				),
			),
		),
		'sites'        => array(
			'title'  => __( 'Sites', 'minn-admin' ),
			'fields' => array(
				array(
					'key'   => 'menu_items_plugins',
					'label' => __( 'Site administrators can manage plugins', 'minn-admin' ),
					'type'  => 'toggle',
					'store' => 'menu_items',
				),
				array(
					'key'   => 'admin_email',
					'label' => __( 'Network admin email', 'minn-admin' ),
					'type'  => 'email',
					'store' => 'email',
				),
			),
		),
	);
}

/** Current value of one spec field, in the shape the client's form wants. */
function minn_admin_network_setting_value( $field ) {
	$key = $field['key'];
	switch ( $field['store'] ) {
		case 'yesno':
			return 'yes' === get_site_option( $key, 'no' );
		case 'onezero':
			return (bool) (int) get_site_option( $key, 0 );
		case 'menu_items':
			$items = (array) get_site_option( 'menu_items', array() );
			return ! empty( $items['plugins'] );
		case 'int':
			return (int) get_site_option( $key, 0 );
		case 'email':
			return (string) get_site_option( $key, '' );
		case 'types':
			return (string) get_site_option( $key, '' );
		case 'enum':
		default:
			return (string) get_site_option( $key, '' );
	}
}

/**
 * Write edited network settings, one option at a time, in core's own shapes.
 * Keys are matched against the spec — never an arbitrary site-option write.
 */
function minn_admin_network_settings_save( $tab, $values ) {
	$spec = minn_admin_network_settings_spec();
	if ( ! isset( $spec[ $tab ] ) ) {
		return new WP_Error( 'bad_tab', __( 'Unknown settings section.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$by_key = array();
	foreach ( $spec[ $tab ]['fields'] as $f ) {
		$by_key[ $f['key'] ] = $f;
	}
	foreach ( (array) $values as $key => $value ) {
		if ( ! isset( $by_key[ $key ] ) ) {
			continue; // not ours: ignore rather than write something unknown
		}
		$field = $by_key[ $key ];
		switch ( $field['store'] ) {
			case 'enum':
				$allowed = wp_list_pluck( $field['options'], 0 );
				if ( in_array( (string) $value, $allowed, true ) ) {
					update_site_option( $key, (string) $value );
				}
				break;
			case 'yesno':
				update_site_option( $key, $value ? 'yes' : 'no' );
				break;
			case 'onezero':
				update_site_option( $key, $value ? '1' : '0' );
				break;
			case 'menu_items':
				$items            = (array) get_site_option( 'menu_items', array() );
				$items['plugins'] = $value ? '1' : '0';
				update_site_option( 'menu_items', $items );
				break;
			case 'int':
				update_site_option( $key, (string) max( 0, (int) $value ) );
				break;
			case 'email':
				$email = sanitize_email( (string) $value );
				if ( ! is_email( $email ) ) {
					return new WP_Error( 'bad_email', __( 'Enter a valid email address.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				update_site_option( $key, $email );
				break;
			case 'types':
				// Core's own normalization: a space-separated lowercase list.
				$types = array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', strtolower( (string) $value ) ) ) );
				update_site_option( $key, implode( ' ', array_unique( $types ) ) );
				break;
		}
	}
	return true;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_network_can( 'manage_network_options' ) ) {
		return $surfaces;
	}
	// Settings tabs are {id, label} objects (the surface settings contract),
	// unlike a collection's [id, label] pairs.
	$tabs = array();
	foreach ( minn_admin_network_settings_spec() as $id => $section ) {
		$tabs[] = array( 'id' => $id, 'label' => $section['title'] );
	}
	// A settings-only surface: no collection, so the settings form IS the
	// page (the Perfmatters pattern).
	$surfaces['network-settings'] = array(
		'label'    => __( 'Network settings', 'minn-admin' ),
		'group'    => 'network',
		'icon'     => 'gear',
		'cap'      => 'manage_network_options',
		'settings' => array(
			'label' => __( 'Network settings', 'minn-admin' ),
			'tabs'  => $tabs,
			'route' => 'minn-admin/v1/network/settings/{tab}',
		),
	);
	return $surfaces;
} );

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_network_can( 'manage_network_users' ) ) {
		return $surfaces;
	}
	$locked = minn_admin_network_super_admins_locked();

	$surfaces['network-users'] = array(
		'label'      => __( 'Network users', 'minn-admin' ),
		'group'      => 'network',
		'icon'       => 'users',
		'cap'        => 'manage_network_users',
		'status'     => array( 'route' => 'minn-admin/v1/network/users/status' ),
		'collection' => array(
			'route'    => 'minn-admin/v1/network/users',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'search'   => 'search={q}',
			'tabs'     => array(
				'param'    => 'kind',
				'allLabel' => __( 'All accounts', 'minn-admin' ),
				'static'   => array(
					array( 'super', __( 'Network admins', 'minn-admin' ) ),
				),
			),
			'columns'  => array(
				array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'email', 'label' => __( 'Email', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'sites', 'label' => __( 'Sites', 'minn-admin' ), 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'registered', 'label' => __( 'Joined', 'minn-admin' ), 'format' => 'ago', 'utc' => true, 'width' => '120px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '130px' ),
			),
			'detail'   => array(
				'skip' => array( 'canGrant', 'canRevoke' ),
			),
			'actions'  => array(
				array(
					'label'   => __( 'Make network administrator', 'minn-admin' ),
					'route'   => 'minn-admin/v1/network/users/{id}/super',
					'body'    => array( 'on' => true ),
					'when'    => array( 'key' => 'canGrant', 'equals' => '1' ),
					'confirm' => __( 'Give this account full control of every site on the network?', 'minn-admin' ),
				),
				array(
					'label'   => __( 'Remove network administrator', 'minn-admin' ),
					'route'   => 'minn-admin/v1/network/users/{id}/super',
					'body'    => array( 'on' => false ),
					'when'    => array( 'key' => 'canRevoke', 'equals' => '1' ),
					'confirm' => __( 'Take away network-wide control? They keep their role on each site.', 'minn-admin' ),
				),
				// Account creation and deletion stay in Network Admin: deleting
				// a network account removes that person's posts on every site,
				// and only wp-admin's own flow offers to reassign them first.
				array( 'label' => __( 'Edit in Network Admin ↗', 'minn-admin' ), 'href' => network_admin_url( 'user-edit.php?user_id={id}' ) ),
			),
		),
	);
	if ( $locked ) {
		$surfaces['network-users']['sub'] = __( 'Set in wp-config', 'minn-admin' );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! is_multisite() ) {
		return;
	}
	$manage = function () {
		return minn_admin_network_can( 'manage_sites' );
	};
	$users = function () {
		return minn_admin_network_can( 'manage_network_users' );
	};

	register_rest_route( 'minn-admin/v1', '/network/users', array(
		'methods'             => 'GET',
		'permission_callback' => $users,
		'callback'            => 'minn_admin_network_users_list',
	) );

	register_rest_route( 'minn-admin/v1', '/network/users/(?P<id>\d+)/super', array(
		'methods'             => 'POST',
		'permission_callback' => $users,
		'callback'            => 'minn_admin_network_user_super',
		'args'                => array(
			'on' => array( 'type' => 'boolean', 'required' => true ),
		),
	) );

	register_rest_route( 'minn-admin/v1', '/network/users/status', array(
		'methods'             => 'GET',
		'permission_callback' => $users,
		'callback'            => 'minn_admin_network_users_status',
	) );

	// Network activation. The plugin file rides in the BODY, not the path:
	// it contains a slash ("folder/file.php"), and every candidate is checked
	// against the installed set before anything runs.
	register_rest_route( 'minn-admin/v1', '/network/plugins/activate', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return minn_admin_network_can( 'manage_network_plugins' );
		},
		'callback'            => 'minn_admin_network_plugin_activate',
		'args'                => array(
			'plugin' => array( 'type' => 'string', 'required' => true ),
			'on'     => array( 'type' => 'boolean', 'required' => true ),
		),
	) );

	$options = function () {
		return minn_admin_network_can( 'manage_network_options' );
	};
	register_rest_route( 'minn-admin/v1', '/network/settings/(?P<tab>[a-z0-9_-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $options,
			'callback'            => 'minn_admin_network_settings_get',
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $options,
			'callback'            => 'minn_admin_network_settings_post',
		),
	) );

	register_rest_route( 'minn-admin/v1', '/network/themes/enable', array(
		'methods'             => 'POST',
		'permission_callback' => function () {
			return minn_admin_network_can( 'manage_network_themes' );
		},
		'callback'            => 'minn_admin_network_theme_enable',
		'args'                => array(
			'theme' => array( 'type' => 'string', 'required' => true ),
			'on'    => array( 'type' => 'boolean', 'required' => true ),
		),
	) );

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

/**
 * POST /network/plugins/activate — turn a plugin on (or off) for every site.
 *
 * Network activation is not per-site activation with a wider reach: it is a
 * separate switch core keeps in the active_sitewide_plugins network option,
 * and turning it off leaves each site's own activation exactly as it was.
 * Minn itself is refused: switching it off network-wide from inside Minn
 * would tear the app out from under the person doing it, which the Extensions
 * card already handles with its own dedicated flow.
 */
function minn_admin_network_plugin_activate( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$file = (string) $request['plugin'];
	$on   = (bool) $request['on'];

	if ( validate_file( $file ) || ! array_key_exists( $file, get_plugins() ) ) {
		return new WP_Error( 'no_such_plugin', __( 'That plugin is not installed.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( ! $on && plugin_basename( MINN_ADMIN_FILE ) === $file ) {
		return new WP_Error(
			'self',
			__( 'Turn Minn Admin off from its own card, which explains what happens first.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	if ( $on ) {
		$result = activate_plugin( $file, '', true );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'activate_failed', wp_strip_all_tags( $result->get_error_message() ), array( 'status' => 500 ) );
		}
	} else {
		deactivate_plugins( $file, false, true );
	}
	return rest_ensure_response(
		array(
			'plugin'  => $file,
			'network' => is_plugin_active_for_network( $file ),
		)
	);
}

/**
 * POST /network/themes/enable — let sites choose this theme, or stop them.
 *
 * Network enabling only decides which themes appear in each site's theme
 * picker; it never switches a site's theme. Disabling one that sites already
 * use leaves those sites on it, exactly as wp-admin does.
 */
function minn_admin_network_theme_enable( WP_REST_Request $request ) {
	$stylesheet = (string) $request['theme'];
	$on         = (bool) $request['on'];

	if ( validate_file( $stylesheet ) ) {
		return new WP_Error( 'bad_theme', __( 'That theme name is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$theme = wp_get_theme( $stylesheet );
	if ( ! $theme->exists() ) {
		return new WP_Error( 'no_such_theme', __( 'That theme is not installed.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( $on ) {
		WP_Theme::network_enable_theme( $stylesheet );
	} else {
		WP_Theme::network_disable_theme( $stylesheet );
	}
	$allowed = (array) get_site_option( 'allowedthemes', array() );
	return rest_ensure_response(
		array(
			'theme'   => $stylesheet,
			'network' => ! empty( $allowed[ $stylesheet ] ),
		)
	);
}

/** GET /network/settings/{tab} — the form model for one section. */
function minn_admin_network_settings_get( WP_REST_Request $request ) {
	$url  = $request->get_url_params();
	$tab  = isset( $url['tab'] ) ? sanitize_key( $url['tab'] ) : '';
	$spec = minn_admin_network_settings_spec();
	if ( ! isset( $spec[ $tab ] ) ) {
		return new WP_Error( 'bad_tab', __( 'Unknown settings section.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$fields = array();
	$values = array();
	foreach ( $spec[ $tab ]['fields'] as $f ) {
		$field = array(
			'key'   => $f['key'],
			'label' => $f['label'],
			'type'  => $f['type'],
		);
		foreach ( array( 'options', 'rows', 'mono', 'placeholder', 'desc' ) as $extra ) {
			if ( isset( $f[ $extra ] ) ) {
				$field[ $extra ] = $f[ $extra ];
			}
		}
		$fields[]              = $field;
		$values[ $f['key'] ]   = minn_admin_network_setting_value( $f );
	}
	return rest_ensure_response(
		array(
			'groups'   => array(
				array(
					'title'  => $spec[ $tab ]['title'],
					'fields' => $fields,
					'locked' => 0,
				),
			),
			'values'   => $values,
			'adminUrl' => network_admin_url( 'settings.php' ),
		)
	);
}

/** POST /network/settings/{tab} — save the edited keys, then re-read. */
function minn_admin_network_settings_post( WP_REST_Request $request ) {
	$url    = $request->get_url_params();
	$tab    = isset( $url['tab'] ) ? sanitize_key( $url['tab'] ) : '';
	$values = $request->get_param( 'values' );
	if ( ! is_array( $values ) ) {
		$values = array();
	}
	$saved = minn_admin_network_settings_save( $tab, $values );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}
	return minn_admin_network_settings_get( $request );
}

/** GET /network/users — every account on the network, paginated. */
function minn_admin_network_users_list( WP_REST_Request $request ) {
	$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
	$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
	$search   = trim( (string) $request['search'] );
	$kind     = sanitize_key( (string) $request['kind'] );

	$args = array(
		'number'  => $per_page,
		'paged'   => $page,
		'orderby' => 'registered',
		'order'   => 'DESC',
		'blog_id' => 0, // network-wide, not this site's members
		'fields'  => 'all',
	);
	if ( '' !== $search ) {
		$args['search']         = '*' . $search . '*';
		$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
	}
	// The network-admins tab: get_super_admins() is a login list, so resolve
	// it to ids and constrain the query rather than filtering after paging
	// (which would report the wrong total).
	if ( 'super' === $kind ) {
		$ids = array();
		foreach ( (array) get_super_admins() as $login ) {
			$u = get_user_by( 'login', $login );
			if ( $u ) {
				$ids[] = (int) $u->ID;
			}
		}
		if ( ! $ids ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
		}
		$args['include'] = $ids;
	}

	$query = new WP_User_Query( $args );
	$users = (array) $query->get_results();
	$total = (int) $query->get_total();

	$ids         = array_map( function ( $u ) { return (int) $u->ID; }, $users );
	$counts      = minn_admin_network_site_counts( $ids );
	$super_count = count( (array) get_super_admins() );
	$items       = array();
	foreach ( $users as $user ) {
		$items[] = minn_admin_network_user_row( $user, $counts, $super_count );
	}
	return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
}

/**
 * POST /network/users/{id}/super — grant or revoke network-administrator
 * status, with the guards core leaves to the screen: nobody may revoke their
 * own status (a self-lockout), and the last network administrator stays.
 */
function minn_admin_network_user_super( WP_REST_Request $request ) {
	require_once ABSPATH . 'wp-admin/includes/ms.php';
	$url = $request->get_url_params();
	$id  = isset( $url['id'] ) ? (int) $url['id'] : 0;
	$on  = (bool) $request['on'];

	$user = $id ? get_userdata( $id ) : null;
	if ( ! $user ) {
		return new WP_Error( 'no_such_user', __( 'That account does not exist.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( minn_admin_network_super_admins_locked() ) {
		return new WP_Error(
			'locked',
			__( 'Network administrators are set in wp-config.php on this network, so they cannot be changed here.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	$is_super = is_super_admin( $id );
	if ( ! $on ) {
		if ( $id === get_current_user_id() ) {
			return new WP_Error(
				'cannot_revoke_self',
				__( 'You cannot remove your own network-administrator status. Ask another network administrator to do it.', 'minn-admin' ),
				array( 'status' => 400 )
			);
		}
		if ( ! $is_super ) {
			return new WP_Error( 'not_super', __( 'That account is not a network administrator.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( count( (array) get_super_admins() ) <= 1 ) {
			return new WP_Error(
				'last_super_admin',
				__( 'This is the only network administrator. Promote someone else first.', 'minn-admin' ),
				array( 'status' => 400 )
			);
		}
		revoke_super_admin( $id );
	} else {
		if ( $is_super ) {
			return new WP_Error( 'already_super', __( 'That account is already a network administrator.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		grant_super_admin( $id );
	}
	$fresh = get_userdata( $id );
	return rest_ensure_response(
		minn_admin_network_user_row(
			$fresh,
			minn_admin_network_site_counts( array( $id ) ),
			count( (array) get_super_admins() )
		)
	);
}

/** GET /network/users/status — the card above the network users list. */
function minn_admin_network_users_status() {
	$supers = (array) get_super_admins();
	$rows   = array(
		array(
			'label' => __( 'Accounts', 'minn-admin' ),
			'value' => function_exists( 'get_user_count' ) ? number_format_i18n( (int) get_user_count() ) : '—',
			'hint'  => __( 'One account works across every site on the network', 'minn-admin' ),
		),
		array(
			'label' => __( 'Network administrators', 'minn-admin' ),
			'value' => number_format_i18n( count( $supers ) ),
			'hint'  => minn_admin_network_super_admins_locked()
				? __( 'Set in wp-config.php, so they cannot be changed here', 'minn-admin' )
				: __( 'Full control of every site and of the network itself', 'minn-admin' ),
		),
		array(
			'label' => __( 'New accounts', 'minn-admin' ),
			'value' => minn_admin_network_registration_label(),
			'hint'  => __( 'Accounts are created in Network Admin', 'minn-admin' ),
		),
	);
	$actions = array();
	if ( current_user_can( 'manage_network' ) ) {
		$actions[] = array( 'label' => __( 'Add account ↗', 'minn-admin' ), 'href' => network_admin_url( 'user-new.php' ) );
		$actions[] = array( 'label' => __( 'Network Admin ↗', 'minn-admin' ), 'href' => network_admin_url( 'users.php' ) );
	}
	return rest_ensure_response( array( 'rows' => $rows, 'actions' => $actions ) );
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
