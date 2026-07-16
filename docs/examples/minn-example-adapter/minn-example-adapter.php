<?php
/**
 * Plugin Name:       Minn Example Adapter — Campfire Feedback
 * Description:       A complete, copyable example of wiring a custom-table plugin into Minn Admin: table, REST shim, surface descriptor. Companion to Minn's docs/shim-tutorial.md.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Austin Ginder
 * License:           MIT
 *
 * The fictional plugin: "Campfire" collects visitor feedback into its own
 * database table (the shape most forms, log and queue plugins have — no
 * custom post type, no core REST route). This file is the WHOLE integration:
 * activate it next to Minn Admin and a paginated, searchable, capability-
 * gated Feedback view appears in the sidebar, with tabs, a status card,
 * a detail modal and row actions.
 *
 * Read it top to bottom with docs/shim-tutorial.md. Every section is
 * numbered to match the tutorial's steps.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ===== Step 1 — the plugin's own data =====
 * A custom table, created on activation. Nothing here is Minn-specific:
 * this is the part your plugin already has. Note the one deliberate choice
 * that pays off later: timestamps are stored in UTC (gmdate), so the
 * descriptor can declare `utc: true` and Minn renders correct local times.
 */

function campfire_table() {
	global $wpdb;
	return $wpdb->prefix . 'campfire_feedback';
}

register_activation_hook( __FILE__, 'campfire_install' );
function campfire_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . campfire_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(190) NOT NULL DEFAULT '',
		email VARCHAR(190) NOT NULL DEFAULT '',
		message TEXT,
		status VARCHAR(20) NOT NULL DEFAULT 'new',
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY status (status)
	) " . $wpdb->get_charset_collate() . ';' );

	// Demo rows so the surface has something to show on first activation.
	if ( ! (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . campfire_table() ) ) {
		$seed = array(
			array( 'Dana Lee', 'dana@example.com', 'Love the new dashboard — feedback took me one click.', 'new', 0 ),
			array( 'Miguel Ortiz', 'miguel@example.com', 'Found a typo on the pricing page, third paragraph.', 'new', 1 ),
			array( 'Priya Shah', 'priya@example.com', 'Could the weekly digest include product photos?', 'new', 2 ),
			array( 'Sam Rivera', 'sam@example.com', 'Checkout worked great on mobile this time. Thanks!', 'read', 4 ),
			array( 'Jo Park', 'jo@example.com', 'The contact form said my message sent twice.', 'read', 6 ),
			array( 'Ana Costa', 'ana@example.com', 'Please add a dark mode to the storefront.', 'archived', 8 ),
			array( 'Liam Chen', 'liam@example.com', 'Shipping to Canada was faster than promised.', 'archived', 10 ),
			array( 'Nora Weiss', 'nora@example.com', 'Old feedback from before the redesign.', 'archived', 12 ),
		);
		foreach ( $seed as $row ) {
			$wpdb->insert( campfire_table(), array(
				'name'       => $row[0],
				'email'      => $row[1],
				'message'    => $row[2],
				'status'     => $row[3],
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - $row[4] * DAY_IN_SECONDS - 3600 ),
			) );
		}
	}
}

/* ===== Step 2 — one capability helper =====
 * Minn checks the surface's `cap` before the view exists at all, but your
 * routes still need their own `permission_callback` — Minn's UI gating is
 * a convenience, the REST layer is the boundary. Centralize the answer so
 * the two can never disagree. Swap this for your plugin's real capability
 * model (an option-driven cap, a granular cap, a role check).
 */

function campfire_can() {
	return current_user_can( 'edit_posts' );
}

/* ===== Step 3 — the REST shim =====
 * Five small routes that translate "rows in my table" into the shapes
 * Minn's generic client renders. Rules that keep a shim safe:
 *   - every route declares `permission_callback` (never __return_true),
 *   - every query goes through $wpdb->prepare,
 *   - per_page is capped,
 *   - stored blobs are never unserialize()d (not needed here, but the rule
 *     that bites real log tables).
 */

add_action( 'rest_api_init', 'campfire_routes' );
function campfire_routes() {
	register_rest_route( 'campfire/v1', '/feedback', array(
		'methods'             => 'GET',
		'callback'            => 'campfire_list',
		'permission_callback' => 'campfire_can',
	) );
	register_rest_route( 'campfire/v1', '/feedback/status', array(
		'methods'             => 'GET',
		'callback'            => 'campfire_status',
		'permission_callback' => 'campfire_can',
	) );
	register_rest_route( 'campfire/v1', '/feedback/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'callback'            => 'campfire_detail',
		'permission_callback' => 'campfire_can',
	) );
	register_rest_route( 'campfire/v1', '/feedback/(?P<id>\d+)/read', array(
		'methods'             => 'POST',
		'callback'            => function ( $req ) { return campfire_set_status( $req, 'read' ); },
		'permission_callback' => 'campfire_can',
	) );
	register_rest_route( 'campfire/v1', '/feedback/(?P<id>\d+)/archive', array(
		'methods'             => 'POST',
		'callback'            => function ( $req ) { return campfire_set_status( $req, 'archived' ); },
		'permission_callback' => 'campfire_can',
	) );
}

/**
 * The list route. Minn sends `page`, your `pageQuery` template's per_page,
 * the active tab's value (here as `status`, from the descriptor's tabs
 * param) and the debounced search term. Answer `{ items, total }` — the
 * descriptor's itemsKey/totalKey point Minn at them.
 */
function campfire_list( WP_REST_Request $req ) {
	global $wpdb;
	$per_page = min( 100, max( 1, (int) $req->get_param( 'per_page' ) ?: 25 ) );
	$page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
	$where    = array( '1=1' );
	$args     = array();

	$status = sanitize_key( (string) $req->get_param( 'status' ) );
	if ( $status ) {
		$where[] = 'status = %s';
		$args[]  = $status;
	}
	$search = trim( (string) $req->get_param( 'search' ) );
	if ( '' !== $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(name LIKE %s OR message LIKE %s)';
		$args[]  = $like;
		$args[]  = $like;
	}

	$sql   = 'FROM ' . campfire_table() . ' WHERE ' . implode( ' AND ', $where );
	$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) $sql", $args ) : "SELECT COUNT(*) $sql" );
	$q     = $wpdb->prepare(
		"SELECT id, name, email, message, status, created_at $sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
		array_merge( $args, array( $per_page, ( $page - 1 ) * $per_page ) )
	);

	$items = array();
	foreach ( $wpdb->get_results( $q, ARRAY_A ) as $row ) {
		$items[] = campfire_item( $row );
	}
	return array( 'items' => $items, 'total' => $total );
}

/**
 * One place that shapes a row for the client. `created` stays a raw UTC
 * datetime — the descriptor's `utc: true` column flag tells Minn how to
 * parse it (the classic shim bug is emitting UTC and letting it parse as
 * site-local; pick one story and declare it).
 */
function campfire_item( $row ) {
	return array(
		'id'      => (int) $row['id'],
		'name'    => $row['name'],
		'email'   => $row['email'],
		'message' => $row['message'],
		'status'  => $row['status'],
		'created' => $row['created_at'],
	);
}

function campfire_detail( WP_REST_Request $req ) {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare( 'SELECT * FROM ' . campfire_table() . ' WHERE id = %d', (int) $req['id'] ),
		ARRAY_A
	);
	if ( ! $row ) {
		return new WP_Error( 'campfire_not_found', 'No such feedback.', array( 'status' => 404 ) );
	}
	return campfire_item( $row );
}

function campfire_set_status( WP_REST_Request $req, $status ) {
	global $wpdb;
	$updated = $wpdb->update(
		campfire_table(),
		array( 'status' => $status ),
		array( 'id' => (int) $req['id'] ),
		array( '%s' ),
		array( '%d' )
	);
	if ( false === $updated ) {
		return new WP_Error( 'campfire_failed', 'Could not update.', array( 'status' => 500 ) );
	}
	// Returning { message } replaces Minn's default "⟨label⟩ — done" toast.
	return array( 'ok' => true, 'message' => 'archived' === $status ? 'Feedback archived' : 'Marked as read' );
}

/**
 * The status card: display-ready strings, formatted server-side. The chart
 * uses the same shape as Minn's Overview charts — one point per day.
 */
function campfire_status() {
	global $wpdb;
	$new   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . campfire_table() . " WHERE status = 'new'" );
	$total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . campfire_table() );
	$last  = $wpdb->get_var( 'SELECT created_at FROM ' . campfire_table() . ' ORDER BY created_at DESC LIMIT 1' );

	$points = array();
	$counts = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT DATE(created_at) d, COUNT(*) n FROM ' . campfire_table() . ' WHERE created_at >= %s GROUP BY DATE(created_at)',
			gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS )
		),
		OBJECT_K
	);
	for ( $i = 13; $i >= 0; $i-- ) {
		$day      = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		$points[] = array(
			'label' => gmdate( 'M j', strtotime( $day ) ),
			'value' => isset( $counts[ $day ] ) ? (int) $counts[ $day ]->n : 0,
		);
	}

	return array(
		'rows'  => array(
			array( 'label' => 'Awaiting reply', 'value' => (string) $new, 'hint' => $new ? 'New feedback needs a human' : 'All caught up' ),
			array( 'label' => 'Total feedback', 'value' => (string) $total ),
			array( 'label' => 'Last received', 'value' => $last ? human_time_diff( strtotime( $last . ' UTC' ) ) . ' ago' : '—' ),
		),
		'chart' => array(
			'title'   => 'Last 14 days',
			'primary' => 'Received',
			'points'  => $points,
		),
	);
}

/* ===== Step 4 — the surface descriptor =====
 * Pure data: no callbacks reach the client, no JavaScript ships, and Minn
 * escapes every value it renders. This is the whole UI definition.
 */

add_filter( 'minn_admin_surfaces', 'campfire_surface' );
function campfire_surface( $surfaces ) {
	$surfaces['campfire'] = array(
		'label'      => 'Feedback',
		'sub'        => 'Campfire',
		'icon'       => 'inbox',
		// Checked server-side before the surface exists for the user. Your
		// routes' permission_callback stays the real boundary (step 2).
		'cap'        => 'edit_posts',
		// Workspace is for inbox shapes only: new items arrive and need a
		// human. Logs and plumbing belong in the default Tools group.
		'group'      => 'workspace',
		'status'     => array( 'route' => 'campfire/v1/feedback/status' ),
		'collection' => array(
			'route'     => 'campfire/v1/feedback',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			// A query-string template; {q} is the debounced term. (The object
			// form of `search` is only for APIs that take JSON criteria.)
			'search'    => 'search={q}',
			'tabs'      => array(
				'param'    => 'status',
				'allLabel' => 'All',
				'static'   => array(
					array( 'new', 'New' ),
					array( 'read', 'Read' ),
					array( 'archived', 'Archived' ),
				),
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'From', 'format' => 'title' ),
				array( 'key' => 'message', 'label' => 'Message' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				// created_at is stored UTC (step 1) — declare it, or every
				// timestamp renders shifted by the site's UTC offset.
				array( 'key' => 'created', 'label' => 'Received', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'detailRoute' => 'campfire/v1/feedback/{id}',
				// One field renders as the large message block; the rest
				// render as key/value rows with snake_case keys shown as
				// words — name your response keys for humans and you need
				// nothing else. (`labels` exists for APIs whose keys are
				// numeric field ids and points at a route that resolves
				// them; `sectionsRoute` hands Minn a fully server-built
				// view. Both are in the descriptor reference.)
				'messageKey'  => 'message',
				'skip'        => array( 'id' ),
			),
			'actions'   => array(
				array(
					'label'  => 'Mark read',
					'route'  => 'campfire/v1/feedback/{id}/read',
					'method' => 'POST',
					// Only offered while the item is actually new.
					'when'   => array( 'key' => 'status', 'equals' => 'new' ),
				),
				array(
					'label'   => 'Archive',
					'route'   => 'campfire/v1/feedback/{id}/archive',
					'method'  => 'POST',
					'confirm' => 'Archive this feedback?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
}
