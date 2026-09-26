<?php
/**
 * Bundled adapter: Elementor MCP (Agent Access family).
 *
 * Elementor 4.3 bundles an MCP server (the elementor-mcp-composer package):
 * with it switched on, an AI client (Claude, Cursor, Codex…) that holds an
 * Application Password can read and edit Elementor pages through the
 * Abilities API. It is off by default. Minn answers the two questions a site
 * owner has about it: is it on, and which connections their setup created.
 * (Their server admits ANY application password of a user with `read`, so
 * the list is the setup-created credentials, not every possible caller.)
 *
 *  - Status card: on/off with a Turn on / Turn off action, the endpoint, how
 *    many connections exist and when one was last used. The switch writes
 *    their own option exactly as their settings route does (add_option with
 *    autoload off when missing, else update_option) and nothing else; their
 *    server re-reads it on every MCP request.
 *  - Connections: every core Application Password their setup flow created
 *    (named "Elementor MCP - <Client> (<date>)"), on every account the viewer
 *    may edit, with Revoke through core's WP_Application_Passwords. Core's own
 *    rule applies: edit_user on the owner (the same gate as core's
 *    /users/{id}/application-passwords routes). Passwords never ride a
 *    response.
 *
 * Creating a connection stays on their setup screen (it hands the secret to
 * one client and shows the per-client instructions), one link away.
 *
 * Gate: manage_options plus a valid wp_rest nonce on the writes, exactly as
 * their settings and credentials routes check (the nonce keeps an
 * application-password caller, such as an MCP client itself, from flipping
 * the switch back on or revoking connections).
 * Their REST namespace carries the package version, so Minn reads and writes
 * the option through their controller class instead of calling it.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

const MINN_ADMIN_ELEMENTOR_MCP_PREFIX = 'Elementor MCP - ';

function minn_admin_elementor_mcp_active() {
	return class_exists( '\Elementor\MCP\Composer\Admin\McpSettingsController' );
}

function minn_admin_elementor_mcp_can() {
	return minn_admin_elementor_mcp_active() && current_user_can( 'manage_options' );
}

/** Their write gate: manage_options AND a wp_rest nonce in X-WP-Nonce. */
function minn_admin_elementor_mcp_can_write( WP_REST_Request $request ) {
	$nonce = (string) $request->get_header( 'X-WP-Nonce' );
	return minn_admin_elementor_mcp_can() && '' !== $nonce && wp_verify_nonce( $nonce, 'wp_rest' );
}

function minn_admin_elementor_mcp_enabled() {
	return (bool) \Elementor\MCP\Composer\Admin\McpSettingsController::is_enabled();
}

/** Whether their server can run at all (Abilities API + MCP adapter present). */
function minn_admin_elementor_mcp_runnable() {
	if ( class_exists( '\Elementor\Modules\Mcp\Module' ) && method_exists( '\Elementor\Modules\Mcp\Module', 'is_active' ) ) {
		return (bool) \Elementor\Modules\Mcp\Module::is_active();
	}
	return function_exists( 'wp_register_ability' );
}

/** Mirror of their McpSettingsController::update_settings() write. */
function minn_admin_elementor_mcp_set_enabled( $on ) {
	$opt     = \Elementor\MCP\Composer\Admin\McpSettingsController::OPTION_NAME;
	$missing = '__minn_missing__';
	if ( $missing === get_option( $opt, $missing ) ) {
		add_option( $opt, (bool) $on, '', false );
	} else {
		update_option( $opt, (bool) $on );
	}
	return minn_admin_elementor_mcp_enabled() === (bool) $on;
}

/**
 * Elementor MCP application passwords on accounts the viewer may edit.
 *
 * @return array[]
 */
function minn_admin_elementor_mcp_connections() {
	if ( ! class_exists( 'WP_Application_Passwords' ) ) {
		return array();
	}
	$users = get_users(
		array(
			'meta_key' => WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'fields'   => array( 'ID', 'user_login', 'display_name' ),
			'number'      => -1,
			'count_total' => false,
		)
	);
	$items = array();
	foreach ( $users as $u ) {
		$uid = (int) $u->ID;
		if ( ! current_user_can( 'edit_user', $uid ) ) {
			continue;
		}
		foreach ( (array) WP_Application_Passwords::get_user_application_passwords( $uid ) as $pw ) {
			$name = (string) ( $pw['name'] ?? '' );
			if ( 0 !== strpos( $name, MINN_ADMIN_ELEMENTOR_MCP_PREFIX ) ) {
				continue;
			}
			// "Elementor MCP - Claude Code (2026-09-25 12:00:00)" → "Claude Code".
			$client  = trim( preg_replace( '/\s*\(\d{4}-\d\d-\d\d[^)]*\)\s*$/', '', substr( $name, strlen( MINN_ADMIN_ELEMENTOR_MCP_PREFIX ) ) ) );
			$items[] = array(
				'id'       => $uid . ':' . (string) $pw['uuid'],
				'client'   => '' !== $client ? $client : $name,
				'owner'    => (string) ( $u->display_name ?: $u->user_login ),
				'created'  => ! empty( $pw['created'] ) ? gmdate( 'c', (int) $pw['created'] ) : '',
				'lastUsed' => ! empty( $pw['last_used'] ) ? gmdate( 'c', (int) $pw['last_used'] ) : '',
				'lastIp'   => (string) ( $pw['last_ip'] ?? '' ),
			);
		}
	}
	usort( $items, function ( $a, $b ) {
		return strcmp( $b['created'], $a['created'] );
	} );
	return $items;
}

function minn_admin_elementor_mcp_status_model() {
	$on       = minn_admin_elementor_mcp_enabled();
	$runnable = minn_admin_elementor_mcp_runnable();
	$conns    = minn_admin_elementor_mcp_connections();
	$last     = '';
	foreach ( $conns as $c ) {
		if ( $c['lastUsed'] > $last ) {
			$last = $c['lastUsed'];
		}
	}
	if ( ! $on ) {
		$hint = __( 'AI clients cannot reach the site through Elementor', 'minn-admin' );
	} elseif ( ! $runnable ) {
		$hint = __( 'Switched on, but this WordPress lacks the Abilities API the server needs', 'minn-admin' );
	} else {
		/* translators: %s: the MCP endpoint URL. */
		$hint = sprintf( __( 'Endpoint %s', 'minn-admin' ), rest_url( 'elementor/mcp/' ) );
	}
	$rows    = array(
		array(
			'label' => __( 'Elementor MCP', 'minn-admin' ),
			'value' => $on ? __( 'On', 'minn-admin' ) : __( 'Off', 'minn-admin' ),
			'hint'  => $hint,
		),
		array(
			'label' => __( 'Connections', 'minn-admin' ),
			'value' => number_format_i18n( count( $conns ) ),
			// Created by their "Connect a client" setup; any other application
			// password of a user with read access also reaches the server.
			'hint'  => $last
				/* translators: %s: how long ago, like "2 days". */
				? sprintf( __( 'Last used %s ago', 'minn-admin' ), human_time_diff( strtotime( $last ) ) )
				: ( $conns ? __( 'Never used', 'minn-admin' ) : '' ),
		),
	);
	$actions = array(
		$on
			? array(
				'label'   => __( 'Turn off', 'minn-admin' ),
				'route'   => 'minn-admin/v1/elementor-mcp/toggle',
				'method'  => 'POST',
				'body'    => array( 'enabled' => false ),
				'confirm' => __( 'Turn off Elementor MCP? Connected AI clients stop working until it is turned back on. Their passwords are kept.', 'minn-admin' ),
			)
			: array(
				'label'  => __( 'Turn on', 'minn-admin' ),
				'route'  => 'minn-admin/v1/elementor-mcp/toggle',
				'method' => 'POST',
				'body'   => array( 'enabled' => true ),
			),
		array(
			'label' => __( 'Connect a client ↗', 'minn-admin' ),
			'href'  => admin_url( 'admin.php?page=elementor-mcp' ),
		),
	);
	return array( 'rows' => $rows, 'actions' => $actions );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_elementor_mcp_active() ) {
		return $surfaces;
	}
	$surfaces['elementor-mcp'] = array(
		'label'      => __( 'Agent Access', 'minn-admin' ),
		'family'     => 'agent-access',
		'sub'        => 'Elementor MCP',
		'plugin'     => 'elementor',
		'icon'       => 'plug',
		'cap'        => 'manage_options',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/elementor-mcp/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/elementor-mcp/connections',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'viewLabel' => __( 'Connections', 'minn-admin' ),
			'columns'   => array(
				array( 'key' => 'client', 'label' => __( 'Client', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'owner', 'label' => __( 'Account', 'minn-admin' ) ),
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
				array( 'key' => 'lastUsed', 'label' => __( 'Last used', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
				array( 'key' => 'lastIp', 'label' => __( 'Last IP', 'minn-admin' ), 'format' => 'mono' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label'   => __( 'Revoke', 'minn-admin' ),
					'method'  => 'POST',
					'route'   => 'minn-admin/v1/elementor-mcp/connections/{id}/revoke',
					'confirm' => __( 'Revoke this connection? The AI client using it loses access immediately.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_elementor_mcp_active() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/elementor-mcp/status', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_elementor_mcp_can',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_elementor_mcp_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor-mcp/toggle', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_elementor_mcp_can_write',
		'args'                => array(
			'enabled' => array(
				'required'          => true,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$on = (bool) $request->get_param( 'enabled' );
			if ( ! minn_admin_elementor_mcp_set_enabled( $on ) ) {
				return new WP_Error( 'minn_emcp_save', __( 'Could not save the Elementor MCP setting.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'enabled' => $on,
				'message' => $on ? __( 'Elementor MCP is on.', 'minn-admin' ) : __( 'Elementor MCP is off.', 'minn-admin' ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor-mcp/connections', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_elementor_mcp_can',
		'callback'            => function () {
			$items = minn_admin_elementor_mcp_connections();
			return rest_ensure_response( array( 'items' => $items, 'total' => count( $items ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/elementor-mcp/connections/(?P<uid>\d+):(?P<uuid>[0-9a-fA-F-]{36})/revoke', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			// Core's own rule for another account's application passwords.
			return minn_admin_elementor_mcp_can_write( $request ) && current_user_can( 'edit_user', (int) $request['uid'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$uid  = (int) $request['uid'];
			$uuid = (string) $request['uuid'];
			$pw   = class_exists( 'WP_Application_Passwords' ) ? WP_Application_Passwords::get_user_application_password( $uid, $uuid ) : null;
			// Only a password their setup flow created: this route is not a
			// general revoke for the account's other application passwords.
			if ( ! $pw || 0 !== strpos( (string) $pw['name'], MINN_ADMIN_ELEMENTOR_MCP_PREFIX ) ) {
				return new WP_Error( 'not_found', __( 'No such Elementor MCP connection.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$ok = WP_Application_Passwords::delete_application_password( $uid, $uuid );
			if ( true !== $ok ) {
				return new WP_Error( 'minn_emcp_revoke', __( 'Could not revoke that connection.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'revoked' => true, 'message' => __( 'Connection revoked.', 'minn-admin' ) ) );
		},
	) );
} );
