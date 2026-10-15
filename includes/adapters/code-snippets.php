<?php
/**
 * Bundled adapter: Code Snippets.
 *
 * Pure descriptor over Code Snippets' own REST API (code-snippets/v1). Lists
 * snippets with name, scope, active status, priority and last modified; detail
 * edits name/description/code/scope/priority/tags in place (same pattern as
 * Redirection); activate / deactivate / delete ride the plugin's own endpoints.
 * "Edit in Code Snippets ↗" remains for the full admin (safe mode, cloud, etc.).
 *
 * See docs/code-snippets.md for the source audit and why this plugin is the
 * first snippet adapter (full CRUD REST, manage_options cap, clean table).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * The capability Code Snippets itself gates on.
 *
 * get_cap_name() is the raw per-site string; get_cap() is the one that resolves
 * to a network capability when the network admin has not enabled the per-site
 * snippets menu. Both the surface's visibility and its status route have to ask
 * the same question, or the sidebar offers an entry whose route then refuses.
 *
 * @return string
 */
function minn_admin_code_snippets_cap() {
	if ( function_exists( 'Code_Snippets\\code_snippets' ) ) {
		$plugin = \Code_Snippets\code_snippets();
		if ( method_exists( $plugin, 'get_cap' ) ) {
			return (string) $plugin->get_cap();
		}
		if ( method_exists( $plugin, 'get_cap_name' ) ) {
			return (string) $plugin->get_cap_name();
		}
	}
	return 'manage_options';
}

/**
 * A row id as Minn's own Code Snippets routes take it: a site snippet's
 * number, or network:<number> for a shared network snippet, which is how
 * Minn's reading of the list names those rows (the list filter below).
 *
 * @param string $raw Route id.
 * @return array{0:int,1:bool} The snippet id and whether it is the network's.
 */
function minn_admin_code_snippets_row_id( $raw ) {
	$raw     = (string) $raw;
	$network = 0 === strpos( $raw, 'network:' );
	return array( (int) ( $network ? substr( $raw, 8 ) : $raw ), $network );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! defined( 'CODE_SNIPPETS_VERSION' ) ) {
		return $surfaces;
	}

	// Cap is filterable in Code Snippets itself; mirror it so Minn and the
	// plugin's own admin stay in lockstep.
	$cap = minn_admin_code_snippets_cap();

	// The PHP and HTML scopes from Snippet::get_all_scopes(). The scope decides
	// the snippet's TYPE (Snippet::get_type), and the edit form sends it on every
	// save, so a stored scope this list does not offer (Pro's CSS, JS and
	// condition scopes) is kept by the form as its own choice rather than
	// replaced by the first entry, which turned a CSS snippet into global PHP.
	$scope_options = array(
		array( 'global', __( 'Global (everywhere)', 'minn-admin' ) ),
		array( 'admin', __( 'Admin only', 'minn-admin' ) ),
		array( 'front-end', __( 'Front-end only', 'minn-admin' ) ),
		array( 'single-use', __( 'Single use', 'minn-admin' ) ),
		array( 'content', __( 'Content (shortcode)', 'minn-admin' ) ),
		array( 'head-content', __( 'Site head', 'minn-admin' ) ),
		array( 'body-content', __( 'Site body', 'minn-admin' ) ),
		array( 'footer-content', __( 'Site footer', 'minn-admin' ) ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'placeholder' => __( 'Disable emojis', 'minn-admin' ) ),
		array( 'key' => 'desc', 'label' => __( 'Description', 'minn-admin' ), 'type' => 'textarea', 'rows' => 2, 'required' => false ),
		array(
			'key'         => 'code',
			'label'       => __( 'Code', 'minn-admin' ),
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => "add_filter( '…', '…' );",
		),
		array( 'key' => 'scope', 'label' => __( 'Scope', 'minn-admin' ), 'type' => 'select', 'options' => $scope_options ),
		array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'type' => 'number' ),
		array( 'key' => 'tags', 'label' => __( 'Tags', 'minn-admin' ), 'type' => 'tags', 'required' => false, 'placeholder' => __( 'media, sample', 'minn-admin' ) ),
	);

	// A Code Snippets snippet is PHP the plugin eval()s, so authoring one is code
	// editing in the sense DISALLOW_FILE_EDIT means, the same call Minn already
	// makes for WPCode PHP snippets. Writes here go to the plugin's OWN route, so
	// this is Minn declining to offer the affordance rather than a boundary; the
	// plugin's own screen is unaffected, exactly as it is for WPCode.
	$code_edits = ! class_exists( 'Minn_Admin' ) || Minn_Admin::code_edits_allowed();

	$surfaces['code-snippets'] = array(
		'label'      => __( 'Snippets', 'minn-admin' ),
		// Surfaces that share a family collapse to one sidebar item; the topbar
		// sub badge becomes a provider switcher when more than one is active.
		'family'     => 'snippets',
		'sub'        => 'Code Snippets',
		'plugin'     => array( 'code-snippets', 'code-snippets-pro' ),
		'icon'       => 'code',
		'cap'        => $cap,
		// Status card (v0.18.0): what's running at a glance. First card in
		// the snippets family.
		'status'     => array( 'route' => 'minn-admin/v1/code-snippets/status' ),
		'collection' => array(
			'route'     => 'code-snippets/v1/snippets',
			// Asks for shared network rows under their own ids (the
			// rest_request_after_callbacks filter below).
			'query'     => 'minn_view=1',
			'pageQuery' => 'per_page=25&page={page}',
			// Their list endpoint has no free-text search; names are scannable
			// in the title column and full code is editable in the detail.
			'create'    => array(
				'label'    => __( 'Add snippet', 'minn-admin' ),
				'route'    => 'code-snippets/v1/snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'   => false,
					'scope'    => 'global',
					'priority' => 10,
					'tags'     => array(),
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Snippet', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => __( 'Scope', 'minn-admin' ), 'format' => 'mono', 'width' => '100px' ),
				array( 'key' => 'active', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'format' => 'num', 'width' => '80px' ),
				// Code Snippets stores/returns modified via gmdate (UTC, no Z).
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				// Re-fetch so the modal always has full code + fresh active flag
				// (and the keys the action gates read, from the filter below).
				'detailRoute' => 'code-snippets/v1/snippets/{id}?minn_view=1',
				// Static rows hide editable fields; the form is the editor.
				'skip'        => array(
					'code', 'code_error', 'network', 'shared_network',
					'condition_id', 'cloud_id', 'revision', 'name', 'desc',
					'scope', 'priority', 'tags', 'active',
				),
				// In-place edit through PUT /snippets/{id} — same path as
				// activate/deactivate, so only fields present are updated.
				'edit'        => array(
					'route'    => 'code-snippets/v1/snippets/{id}',
					'method'   => 'PUT',
					// The network flags name which snippet this is. The on/off
					// switch is left out: the route keeps a field it is not sent,
					// and the copy a modal loaded would switch a snippet back on
					// (or off) after someone changed it.
					'preserve' => array( 'network', 'shared_network', 'condition_id' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				// PUT with {active} is the reliable toggle path; the dedicated
				// /activate|/deactivate routes exist but return an unprepared
				// Snippet object that can 500 under rest_ensure_response.
				// Deactivate goes through Minn's route, which knows a shared
				// network row's id and asks Code Snippets in that scope. Their
				// own routes take a site snippet's id alone, so a shared row
				// is not offered Activate, Delete or the Edit link: a when-gate
				// is one key and one value, so the list filter below names what
				// Activate needs (_activate) and gives every row its
				// shared_network flag. A shared snippet is switched on from
				// their own screen.
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => '_activate', 'equals' => 'site' ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/code-snippets/{id}/deactivate',
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => __( 'Switch on in Code Snippets ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=snippets' ),
					'when'  => array( 'key' => '_activate', 'equals' => 'network' ),
				),
				array(
					'label' => __( 'Edit in Code Snippets ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=edit-snippet&id={id}' ),
					'when'  => array( 'key' => 'shared_network', 'equals' => false ),
				),
				array(
					'label'   => __( 'Delete snippet', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'code-snippets/v1/snippets/{id}',
					'confirm' => __( 'Delete this snippet permanently? Its code will be gone.', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'shared_network', 'equals' => false ),
				),
			),
			// Their list has no active= filter, so bulk is the multi-item win.
			'bulk'      => array(
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'PUT',
					'route'  => 'code-snippets/v1/snippets/{id}',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => '_activate', 'equals' => 'site' ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/code-snippets/{id}/deactivate',
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'code-snippets/v1/snippets/{id}',
					'confirm' => __( 'Delete the selected snippets permanently?', 'minn-admin' ),
					'danger'  => true,
					'when'    => array( 'key' => 'shared_network', 'equals' => false ),
				),
			),
		),
	);

	if ( ! $code_edits ) {
		unset( $surfaces['code-snippets']['collection']['create'] );
		unset( $surfaces['code-snippets']['collection']['detail']['edit'] );
		// The page still shows the snippet, read-only, from a view of Minn's
		// own that answers GET alone, so there is no write for the client to
		// send through it. Their detail route goes: it takes the id alone,
		// so it cannot name a shared network row, and its 404 for one would
		// stop the view loading. Deactivate keys on the list row's flag. The
		// skip list stays, so a view that fails to load lists no raw keys.
		unset( $surfaces['code-snippets']['collection']['detail']['detailRoute'] );
		$surfaces['code-snippets']['collection']['detail']['sectionsRoute'] = 'minn-admin/v1/code-snippets/{id}';
		// Activating a snippet runs its code. Deactivating one stops code
		// running, so it stays available on a hardened site.
		// Identify the activation by the request it sends, never by its label:
		// the label is translated, so a literal comparison silently stops
		// matching on every site that is not running in English. The bulk list
		// carries the same action and is read independently by the client, so
		// it needs the same filter.
		$is_activate = function ( $a ) {
			return ! ( isset( $a['body']['active'] ) && true === $a['body']['active'] );
		};
		foreach ( array( 'actions', 'bulk' ) as $list ) {
			if ( ! empty( $surfaces['code-snippets']['collection'][ $list ] ) ) {
				$surfaces['code-snippets']['collection'][ $list ] = array_values(
					array_filter( $surfaces['code-snippets']['collection'][ $list ], $is_activate )
				);
			}
		}
	}

	return $surfaces;
} );

function minn_admin_code_snippets_active() {
	return defined( 'CODE_SNIPPETS_VERSION' );
}

/**
 * Their own capability: one resolver for the surface and the routes, so the
 * sidebar can never offer an entry a route then refuses.
 *
 * @return bool
 */
function minn_admin_code_snippets_can() {
	return current_user_can( minn_admin_code_snippets_cap() );
}

// Status card: counts from their own table ({prefix}snippets; active 1 =
// active, 0 = inactive, -1 = trashed per their class-db docblock), the latest
// change, and a safe-mode warning row when the constant is armed (safe mode
// means NOTHING executes, worth saying out loud).
minn_admin_endpoint( 'code-snippets/status', array(
	'active' => 'minn_admin_code_snippets_active',
	'gate'   => 'minn_admin_code_snippets_can',
	'run'    => function () {
		if ( ! Minn_Admin_Kernel_SQL::exists( 'snippets' ) ) {
			return array( 'rows' => array() );
		}
		$active   = 0;
		$inactive = 0;
		$trashed  = 0;
		foreach ( Minn_Admin_Kernel_SQL::counts( 'snippets', 'active', array() ) as $c ) {
			if ( 1 === (int) $c['value'] ) {
				$active = $c['count'];
			} elseif ( -1 === (int) $c['value'] ) {
				$trashed = $c['count'];
			} else {
				$inactive += $c['count'];
			}
		}
		$hint = array();
		if ( $inactive ) {
			/* translators: %d: number of inactive snippets. */
			$hint[] = sprintf( __( '%d inactive', 'minn-admin' ), $inactive );
		}
		if ( $trashed ) {
			/* translators: %d: number of trashed snippets. */
			$hint[] = sprintf( __( '%d trashed', 'minn-admin' ), $trashed );
		}
		$rows   = array(
			array(
				'label' => __( 'Active snippets', 'minn-admin' ),
				'value' => (string) $active,
				'hint'  => $hint ? implode( ' · ', $hint ) : __( 'nothing inactive', 'minn-admin' ),
			),
		);
		$scopes = Minn_Admin_Kernel_SQL::counts( 'snippets', 'scope', array( 'active' => 1 ), 3 );
		if ( $scopes ) {
			$rows[] = array(
				'label' => __( 'Running scopes', 'minn-admin' ),
				'value' => implode( ' · ', array_map( function ( $s ) {
					return $s['count'] . ' ' . $s['value'];
				}, $scopes ) ),
			);
		}
		$last = Minn_Admin_Kernel_SQL::select( 'snippets', array( 'name', 'modified' ), array( 'active' => array( 'op' => '>=', 'value' => 0 ) ), array( 'modified' => 'DESC' ), 1 );
		$last = $last ? $last[0] : null;
		// modified is stamped with gmdate(), so the day is the site's, not
		// the UTC one a substr() of the stamp would give.
		$last_ts = $last && $last['modified'] && '0000-00-00 00:00:00' !== $last['modified'] ? strtotime( $last['modified'] . ' UTC' ) : false;
		if ( $last_ts ) {
			$rows[] = array(
				'label' => __( 'Last change', 'minn-admin' ),
				'value' => (string) $last['name'],
				'hint'  => wp_date( get_option( 'date_format' ), $last_ts ),
			);
		}
		if ( defined( 'CODE_SNIPPETS_SAFE_MODE' ) && CODE_SNIPPETS_SAFE_MODE ) {
			$rows[] = array(
				'label' => __( 'Safe mode', 'minn-admin' ),
				'value' => __( 'On', 'minn-admin' ),
				'hint'  => __( 'No snippets are executing while safe mode is armed', 'minn-admin' ),
			);
		}
		return array( 'rows' => $rows );
	},
) );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_code_snippets_active() ) {
		return;
	}
	// On a network a site's list carries the network's shared snippets under
	// their network-table ids, which can equal a site snippet's, and every
	// route that takes the id alone reads the site table: opening a shared
	// row showed the site snippet with the same number, and Deactivate
	// switched that one off. Minn's reading of the list (minn_view=1) names
	// those rows network:<id>, which only Minn's routes below take and pass
	// on as network=true; their own routes answer 404 for one rather than
	// act on the wrong snippet. Their screens and every other client get
	// the list as they ship it. Each row (and the dialog's single read) also
	// carries a boolean shared_network and _activate, which the action gates
	// read: 'site' for an inactive site snippet, 'network' for an inactive
	// shared one, '' for one that is on.
	add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
		if ( ! $response instanceof WP_REST_Response || $response->is_error()
			|| ! $request instanceof WP_REST_Request
			|| 'GET' !== $request->get_method()
			|| ! preg_match( '#^/code-snippets/v1/snippets(/\d+)?$#', (string) $request->get_route(), $m )
			|| ! $request->get_param( 'minn_view' ) ) {
			return $response;
		}
		$single = ! empty( $m[1] );
		$items  = $response->get_data();
		if ( ! is_array( $items ) ) {
			return $response;
		}
		$items = $single ? array( $items ) : $items;
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$shared                        = ! empty( $item['shared_network'] );
			$items[ $i ]['shared_network'] = $shared;
			$items[ $i ]['_activate']      = ! empty( $item['active'] ) ? '' : ( $shared ? 'network' : 'site' );
			if ( $shared && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				$items[ $i ]['id'] = 'network:' . (int) $item['id'];
			}
		}
		$response->set_data( $single ? $items[0] : $items );
		return $response;
	}, 10, 3 );
} );

// One snippet, read-only: the snippet page on a site that disallows code
// edits. It reads through their own route, so their capability and network
// rules decide what it returns, and it registers GET alone.
minn_admin_endpoint( 'code-snippets/(?P<id>(?:network:)?\d+)', array(
	'active' => 'minn_admin_code_snippets_active',
	'gate'   => 'minn_admin_code_snippets_can',
	'run'    => function ( WP_REST_Request $request, array $ctx ) {
		list( $id, $network ) = minn_admin_code_snippets_row_id( $ctx['id'] );
		$inner                = new WP_REST_Request( 'GET', '/code-snippets/v1/snippets/' . $id );
		if ( $network ) {
			$inner->set_param( 'network', true );
		}
		$res = rest_do_request( $inner );
		if ( $res->is_error() ) {
			return $res->as_error();
		}
		$snippet = (array) $res->get_data();
		if ( empty( $snippet['id'] ) ) {
			return new WP_Error( 'not_found', __( 'Snippet not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		$tags     = is_array( $snippet['tags'] ?? null ) ? implode( ', ', array_map( 'strval', $snippet['tags'] ) ) : (string) ( $snippet['tags'] ?? '' );
		$modified = ! empty( $snippet['modified'] ) ? strtotime( (string) $snippet['modified'] ) : false;
		$rows     = array_values( array_filter( array(
			array( 'label' => __( 'Name', 'minn-admin' ), 'value' => Minn_Admin::plain_text( $snippet['name'] ?? '' ) ),
			array( 'label' => __( 'Description', 'minn-admin' ), 'value' => Minn_Admin::plain_text( $snippet['desc'] ?? '' ) ),
			array( 'label' => __( 'Scope', 'minn-admin' ), 'value' => (string) ( $snippet['scope'] ?? '' ) ),
			array( 'label' => __( 'Priority', 'minn-admin' ), 'value' => isset( $snippet['priority'] ) ? (string) (int) $snippet['priority'] : '' ),
			array( 'label' => __( 'Tags', 'minn-admin' ), 'value' => Minn_Admin::plain_text( $tags ) ),
			array( 'label' => __( 'Modified', 'minn-admin' ), 'value' => $modified ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $modified ) : '' ),
			array( 'label' => __( 'Editing', 'minn-admin' ), 'value' => Minn_Admin::code_edits_allowed() ? '' : __( 'Off: this site disallows editing code from the dashboard', 'minn-admin' ) ),
			array( 'label' => __( 'Code', 'minn-admin' ), 'value' => (string) ( $snippet['code'] ?? '' ), 'type' => 'code' ),
		), function ( $r ) {
			return '' !== $r['value'];
		} ) );
		return array( 'sections' => array( array( 'title' => __( 'Snippet', 'minn-admin' ), 'rows' => $rows ) ) );
	},
) );

// Deactivate, for a site row or a shared network row. It only ever switches
// a snippet off, through their own routes and their gates: the PUT the action
// always sent for a site snippet, and for a shared one their deactivate route
// in the network scope, which clears this site's own switch
// (active_shared_network_snippets).
minn_admin_endpoint( 'code-snippets/(?P<id>(?:network:)?\d+)/deactivate', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_code_snippets_active',
	'gate'    => 'minn_admin_code_snippets_can',
	'run'     => function ( WP_REST_Request $request, array $ctx ) {
		list( $id, $network ) = minn_admin_code_snippets_row_id( $ctx['id'] );
		if ( $network ) {
			$inner = new WP_REST_Request( 'POST', '/code-snippets/v1/snippets/' . $id . '/deactivate' );
			$inner->set_param( 'network', true );
		} else {
			$inner = new WP_REST_Request( 'PUT', '/code-snippets/v1/snippets/' . $id );
			$inner->set_header( 'content-type', 'application/json' );
			$inner->set_body( wp_json_encode( array( 'active' => false ) ) );
		}
		$res = rest_do_request( $inner );
		if ( $res->is_error() ) {
			return $res->as_error();
		}
		return array( 'message' => __( 'Snippet deactivated.', 'minn-admin' ) );
	},
) );
