<?php
/**
 * Bundled adapter: Transients Manager (WPBeginner).
 *
 * Lists, searches and deletes options-table transients through core
 * delete_transient / delete_site_transient (same path Transients Manager
 * uses). Value display is type + truncated raw string — never unserializes
 * third-party blobs. Bulk "Delete expired" calls their public
 * delete_expired_transients() when available.
 *
 * Nav: family `diagnostics` (label Diagnostics) with Scrutoscope and
 * WP Crontrol — one Tools slot, provider switcher. Complements the System
 * page's expired-transients count with the actual cleanup work.
 *
 * Caps: manage_options (their $capability).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_tm_ready() {
	return defined( 'AM_TM_VERSION' )
		&& class_exists( '\\AM\\TransientsManager\\TransientsManager' );
}

function minn_admin_tm_can() {
	return current_user_can( 'manage_options' );
}

function minn_admin_tm_admin_url() {
	return admin_url( 'tools.php?page=transients-manager' );
}

/**
 * Strip the option prefix to the human transient name.
 *
 * @param string $option_name Full option_name.
 * @return string
 */
function minn_admin_tm_name( $option_name ) {
	if ( 0 === strpos( $option_name, '_site_transient_timeout_' ) ) {
		return substr( $option_name, strlen( '_site_transient_timeout_' ) );
	}
	if ( 0 === strpos( $option_name, '_transient_timeout_' ) ) {
		return substr( $option_name, strlen( '_transient_timeout_' ) );
	}
	if ( 0 === strpos( $option_name, '_site_transient_' ) ) {
		return substr( $option_name, strlen( '_site_transient_' ) );
	}
	if ( 0 === strpos( $option_name, '_transient_' ) ) {
		return substr( $option_name, strlen( '_transient_' ) );
	}
	return $option_name;
}

function minn_admin_tm_is_site( $option_name ) {
	return false !== strpos( (string) $option_name, '_site_transient' );
}

/**
 * Guess value type without unserializing into usable objects when possible.
 * Serialized blobs are labeled "serialized" from the raw string prefix only.
 *
 * @param string $raw option_value.
 * @return string
 */
function minn_admin_tm_type( $raw ) {
	$raw = (string) $raw;
	if ( '' === $raw ) {
		return 'empty';
	}
	// Serialized PHP: a: / O: / s: / i: / b: / d: / N;
	if ( preg_match( '/^[aOsidbN]:/', $raw ) || 'N;' === $raw ) {
		if ( 0 === strpos( $raw, 'a:' ) ) {
			return 'array';
		}
		if ( 0 === strpos( $raw, 'O:' ) ) {
			return 'object';
		}
		return 'serialized';
	}
	$trim = trim( $raw );
	if ( ( '{' === $trim[0] || '[' === $trim[0] ) && null !== json_decode( $trim ) ) {
		return 'json';
	}
	if ( is_numeric( $raw ) ) {
		if ( 10 === strlen( $raw ) && (int) $raw > 1000000000 ) {
			return 'timestamp';
		}
		if ( in_array( $raw, array( '0', '1' ), true ) ) {
			return 'boolean';
		}
		return 'numeric';
	}
	if ( $raw !== wp_strip_all_tags( $raw ) ) {
		return 'html';
	}
	return 'string';
}

/**
 * Timeout epoch for a data-row option, or 0 if none / persistent.
 *
 * @param string $option_name Data option_name (_transient_X).
 * @return int
 */
function minn_admin_tm_timeout( $option_name ) {
	$name = minn_admin_tm_name( $option_name );
	if ( minn_admin_tm_is_site( $option_name ) ) {
		$t = get_option( '_site_transient_timeout_' . $name );
	} else {
		$t = get_option( '_transient_timeout_' . $name );
	}
	return $t ? (int) $t : 0;
}

/**
 * @return array{items: array, total: int}
 */
function minn_admin_tm_list( WP_REST_Request $request ) {
	global $wpdb;

	$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
	$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
	$search   = (string) $request->get_param( 'search' );
	$kind     = (string) $request->get_param( 'kind' );
	$offset   = ( $page - 1 ) * $per_page;

	$esc_name = '%' . $wpdb->esc_like( '_transient_' ) . '%';
	$esc_time = '%' . $wpdb->esc_like( '_transient_timeout_' ) . '%';

	// Base: data rows only (exclude timeout siblings), same as Transients Manager.
	$where = 'option_name LIKE %s AND option_name NOT LIKE %s';
	$args  = array( $esc_name, $esc_time );

	if ( $search ) {
		$where .= ' AND option_name LIKE %s';
		$args[] = '%' . $wpdb->esc_like( $search ) . '%';
	}

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
	$total = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where}",
		$args
	) );

	// Expired filter: pull more rows and filter in PHP (timeout is a sibling option).
	// Cap scan at 500 for the expired tab so a huge options table stays safe.
	if ( 'expired' === $kind || 'persistent' === $kind || 'site' === $kind ) {
		$scan = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE {$where} ORDER BY option_id DESC LIMIT %d",
			array_merge( $args, array( 500 ) )
		) );
		$now   = time();
		$items = array();
		foreach ( (array) $scan as $row ) {
			$timeout = minn_admin_tm_timeout( $row->option_name );
			$is_site = minn_admin_tm_is_site( $row->option_name );
			if ( 'site' === $kind && ! $is_site ) {
				continue;
			}
			if ( 'expired' === $kind && ( ! $timeout || $timeout >= $now ) ) {
				continue;
			}
			if ( 'persistent' === $kind && $timeout ) {
				continue;
			}
			$items[] = minn_admin_tm_row( $row, $timeout, $now );
		}
		$total = count( $items );
		$items = array_slice( $items, $offset, $per_page );
		return array( 'items' => $items, 'total' => $total );
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE {$where} ORDER BY option_id DESC LIMIT %d OFFSET %d",
		array_merge( $args, array( $per_page, $offset ) )
	) );
	// phpcs:enable

	$now   = time();
	$items = array();
	foreach ( (array) $rows as $row ) {
		$items[] = minn_admin_tm_row( $row, minn_admin_tm_timeout( $row->option_name ), $now );
	}

	return array( 'items' => $items, 'total' => $total );
}

/**
 * @param object $row     options row.
 * @param int    $timeout Epoch or 0.
 * @param int    $now     Current time.
 * @return array
 */
function minn_admin_tm_row( $row, $timeout, $now ) {
	$name    = minn_admin_tm_name( $row->option_name );
	$is_site = minn_admin_tm_is_site( $row->option_name );
	$type    = minn_admin_tm_type( $row->option_value );
	$preview = (string) $row->option_value;
	if ( strlen( $preview ) > 120 ) {
		$preview = substr( $preview, 0, 120 ) . '…';
	}
	// Never show full serialized blobs in the list — type is enough for scan.
	if ( in_array( $type, array( 'array', 'object', 'serialized' ), true ) ) {
		$preview = '(' . $type . ', ' . size_format( strlen( (string) $row->option_value ), 1 ) . ')';
	}

	$status = 'persistent';
	$expires = '';
	if ( $timeout ) {
		$expires = gmdate( 'Y-m-d\TH:i:s\Z', $timeout );
		$status  = $timeout < $now ? 'expired' : 'active';
	}

	return array(
		'id'       => (int) $row->option_id,
		'name'     => $name,
		'scope'    => $is_site ? 'site' : 'blog',
		'type'     => $type,
		'preview'  => $preview,
		'status'   => $status,
		'date'     => $expires,
		'site'     => $is_site,
		'size'     => size_format( strlen( (string) $row->option_value ), 1 ),
	);
}

/**
 * @param int $id option_id.
 * @return array|WP_Error
 */
function minn_admin_tm_detail( $id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_id = %d",
		(int) $id
	) );
	if ( ! $row || false === strpos( $row->option_name, '_transient_' ) || false !== strpos( $row->option_name, '_timeout_' ) ) {
		return new WP_Error( 'not_found', 'Transient not found.', array( 'status' => 404 ) );
	}

	$now     = time();
	$timeout = minn_admin_tm_timeout( $row->option_name );
	$item    = minn_admin_tm_row( $row, $timeout, $now );
	$raw     = (string) $row->option_value;
	// Detail still avoids unserialize: show truncated raw for non-scalar types.
	$show = $raw;
	if ( strlen( $show ) > 2000 ) {
		$show = substr( $show, 0, 2000 ) . '…';
	}
	if ( in_array( $item['type'], array( 'array', 'object', 'serialized' ), true ) ) {
		// Opaque by design — length only, so Minn never materializes the payload.
		$show = '(serialized ' . $item['type'] . ', ' . number_format_i18n( strlen( $raw ) ) . ' bytes — open Transients Manager to inspect)';
	}

	$meta = array(
		array( 'label' => 'Name', 'value' => $item['name'] ),
		array( 'label' => 'Scope', 'value' => $item['scope'] ),
		array( 'label' => 'Type', 'value' => $item['type'] ),
		array( 'label' => 'Size', 'value' => $item['size'] ),
		array( 'label' => 'Status', 'value' => $item['status'] ),
		array( 'label' => 'Expires', 'value' => $item['date'] ? $item['date'] : 'Never (persistent)' ),
		array( 'label' => 'Option key', 'value' => $row->option_name ),
		array( 'label' => 'Value', 'value' => $show ),
	);

	return array(
		'title'    => $item['name'],
		'status'   => $item['status'],
		'sections' => array(
			array( 'title' => 'Transient', 'rows' => $meta ),
		),
		'adminUrl' => minn_admin_tm_admin_url(),
	);
}

function minn_admin_tm_status_model() {
	global $wpdb;

	$esc_name = '%' . $wpdb->esc_like( '_transient_' ) . '%';
	$esc_time = '%' . $wpdb->esc_like( '_transient_timeout_' ) . '%';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
		$esc_name,
		$esc_time
	) );

	// Expired: timeout rows past now (same shape System uses, scoped to timeout keys).
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$expired = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value+0 < %d AND option_value != ''",
		'%' . $wpdb->esc_like( '_transient_timeout_' ) . '%',
		time()
	) );

	$suspended = (bool) get_option( 'pw_tm_suspend' );
	$next_cron = wp_next_scheduled( 'delete_expired_transients' );
	$ver       = defined( 'AM_TM_VERSION' ) ? AM_TM_VERSION : '—';

	return array(
		'rows'    => array(
			array(
				'label' => 'Transients',
				'value' => number_format_i18n( $total ),
				'hint'  => 'In the options table (blog-level)',
			),
			array(
				'label' => 'Expired',
				'value' => number_format_i18n( $expired ),
				'hint'  => $expired ? 'Safe to purge' : 'None past due',
			),
			array(
				'label' => 'Auto-cleanup',
				'value' => $next_cron ? gmdate( 'Y-m-d H:i', $next_cron ) . ' UTC' : 'Not scheduled',
				'hint'  => 'WordPress delete_expired_transients cron',
			),
			array(
				'label' => 'Writes',
				'value' => $suspended ? 'Suspended' : 'Allowed',
				'hint'  => $suspended ? 'Transients Manager is blocking sets' : 'Normal',
			),
			array(
				'label' => 'Transients Manager',
				'value' => (string) $ver,
			),
		),
		'actions' => array(
			array(
				'label'   => 'Delete expired',
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/transients/delete-expired',
				'confirm' => 'Delete all expired transients? Active ones are kept.',
			),
			array(
				'label' => 'Open Transients Manager ↗',
				'href'  => minn_admin_tm_admin_url(),
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_tm_ready() || ! minn_admin_tm_can() ) {
		return $surfaces;
	}

	$surfaces['transients-manager'] = array(
		'label'      => 'Diagnostics',
		'sub'        => 'Transients',
		'family'     => 'diagnostics',
		'icon'       => 'activity',
		'cap'        => 'manage_options',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/transients/status' ),
		'collection' => array(
			'viewLabel' => 'Transients',
			'route'     => 'minn-admin/v1/transients',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'kind',
				'static'   => array(
					array( 'expired', 'Expired' ),
					array( 'persistent', 'Persistent' ),
					array( 'site', 'Site-wide' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'Name', 'format' => 'title' ),
				array( 'key' => 'type', 'label' => 'Type', 'format' => 'text' ),
				array( 'key' => 'scope', 'label' => 'Scope', 'format' => 'text' ),
				array( 'key' => 'size', 'label' => 'Size', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'Expires', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/transients/{id}',
			),
			'actions'   => array(
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/transients/{id}',
					'confirm' => 'Delete this transient? Plugins may recreate it on the next request.',
					'danger'  => true,
				),
				array(
					'label' => 'Open Transients Manager ↗',
					'href'  => minn_admin_tm_admin_url(),
				),
			),
			'bulk'      => array(
				array(
					'label'   => 'Delete selected',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/transients/{id}',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_tm_ready() ) {
		return;
	}

	$perm = 'minn_admin_tm_can';

	register_rest_route( 'minn-admin/v1', '/transients', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			return rest_ensure_response( minn_admin_tm_list( $request ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/transients/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$out = minn_admin_tm_detail( (int) $request['id'] );
				return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				global $wpdb;
				$id  = (int) $request['id'];
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT option_id, option_name FROM {$wpdb->options} WHERE option_id = %d",
					$id
				) );
				if ( ! $row || false === strpos( $row->option_name, '_transient_' ) || false !== strpos( $row->option_name, '_timeout_' ) ) {
					return new WP_Error( 'not_found', 'Transient not found.', array( 'status' => 404 ) );
				}
				$name = minn_admin_tm_name( $row->option_name );
				$ok   = minn_admin_tm_is_site( $row->option_name )
					? delete_site_transient( $name )
					: delete_transient( $name );
				// delete_* returns false when the key was already gone — still treat as done.
				return rest_ensure_response( array(
					'ok'      => true,
					'message' => $ok ? 'Transient deleted.' : 'Transient removed (or was already gone).',
				) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/transients/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_tm_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/transients/delete-expired', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function () {
			// Prefer their public method (same SQL + delete_transient path).
			$tm = \AM\TransientsManager\TransientsManager::getInstance();
			// time_now is set on admin_init; ensure it for REST.
			if ( empty( $tm->time_now ) ) {
				$tm->time_now = time();
			}
			$tm->delete_expired_transients();
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'Expired transients deleted.',
			) );
		},
	) );
} );
