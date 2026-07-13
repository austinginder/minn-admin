<?php
/**
 * Bundled adapter: Scrutoscope (performance profiler).
 *
 * Scrutoscope is a read-only WordPress performance profiler with a real REST
 * API under scrutoscope/v1 (manage_options). Minn surfaces recent profiles as
 * a Tools list: open a row for top sources, queries and HTTP calls built from
 * their own /profile/{id} response (via rest_do_request so their sanitizer
 * stays in the path). Capture settings, pin UI, share, and the full timeline
 * stay on Scrutoscope's Tools screen — one deep link away.
 *
 * Complements the Query Monitor panel: QM is this-request; Scrutoscope is
 * sampled history across routes.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_scrutoscope_active() {
	return defined( 'SCRUTOSCOPE_VERSION' ) && class_exists( '\\Scrutoscope\\Profiler\\Storage' );
}

function minn_admin_scrutoscope_can() {
	return current_user_can( 'manage_options' );
}

function minn_admin_scrutoscope_admin_url() {
	return admin_url( 'tools.php?page=scrutoscope' );
}

/** Profiles table name via their Storage helper (prefix-safe). */
function minn_admin_scrutoscope_table() {
	return \Scrutoscope\Profiler\Storage::table_name();
}

/**
 * List rows for the collection, newest first.
 *
 * Scrutoscope's public REST groups by route; Minn lists individual captures
 * for open-and-inspect daily work. Columns only — heavy profile_data blobs
 * stay out of the list path.
 *
 * @return array{items: array, total: int}
 */
function minn_admin_scrutoscope_list( WP_REST_Request $request ) {
	global $wpdb;

	$table = minn_admin_scrutoscope_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $found || 0 !== strcasecmp( (string) $found, $table ) ) {
		return array( 'items' => array(), 'total' => 0 );
	}

	$where = array( '1=1' );
	$args  = array();

	$kind = (string) $request->get_param( 'kind' );
	if ( 'pinned' === $kind ) {
		$where[] = 'is_pinned = 1';
	} elseif ( 'session' === $kind ) {
		$where[] = 'profile_type = %s';
		$args[]  = 'session';
	} elseif ( 'background' === $kind ) {
		$where[] = 'profile_type = %s';
		$args[]  = 'background';
	}

	$search = (string) $request->get_param( 'search' );
	if ( $search ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '(route_key LIKE %s OR request_url LIKE %s OR note LIKE %s OR tags LIKE %s)';
		array_push( $args, $like, $like, $like, $like );
	}

	$where_sql = implode( ' AND ', $where );
	$per_page  = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
	$page      = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
	$offset    = ( $page - 1 ) * $per_page;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from Storage; WHERE placeholder-built.
	if ( $args ) {
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $args ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, route_key, request_method, request_url, profile_type, duration_ns, user_role, captured_at, is_pinned, note, tags, response_status
			 FROM {$table} WHERE {$where_sql} ORDER BY captured_at DESC, id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( $per_page, $offset ) )
		), ARRAY_A );
	} else {
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, route_key, request_method, request_url, profile_type, duration_ns, user_role, captured_at, is_pinned, note, tags, response_status
			 FROM {$table} WHERE {$where_sql} ORDER BY captured_at DESC, id DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		), ARRAY_A );
	}
	// phpcs:enable

	$items = array();
	foreach ( (array) $rows as $r ) {
		$ms     = round( ( (float) $r['duration_ns'] ) / 1e6, 1 );
		$route  = (string) $r['route_key'];
		$items[] = array(
			'id'       => (int) $r['id'],
			'route'    => $route ? $route : ( (string) $r['request_method'] . ' ' . (string) $r['request_url'] ),
			'duration' => $ms . ' ms',
			'duration_ms' => $ms,
			'type'     => (string) $r['profile_type'] ?: 'session',
			'role'     => (string) $r['user_role'] ?: '—',
			'status'   => ! empty( $r['is_pinned'] ) ? 'pinned' : ( (string) $r['profile_type'] ?: 'session' ),
			'pinned'   => ! empty( $r['is_pinned'] ),
			// captured_at is current_time('mysql') = site-local; emit raw.
			'date'     => (string) $r['captured_at'],
			'http'     => $r['response_status'] ? (string) (int) $r['response_status'] : '—',
		);
	}

	return array( 'items' => $items, 'total' => $total );
}

/**
 * Detail display model from Scrutoscope's own /profile/{id} endpoint.
 *
 * @param int $id Profile row id.
 * @return array|WP_Error
 */
function minn_admin_scrutoscope_profile_sections( $id ) {
	$req = new WP_REST_Request( 'GET', '/scrutoscope/v1/profile/' . (int) $id );
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return $res->as_error();
	}
	$data = $res->get_data();
	if ( ! is_array( $data ) || empty( $data['id'] ) ) {
		return new WP_Error( 'not_found', 'Profile not found.', array( 'status' => 404 ) );
	}

	$summary = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : array();
	$meta    = array(
		array( 'label' => 'Route', 'value' => (string) ( $data['route'] ?? '' ) ),
		array( 'label' => 'Duration', 'value' => ( isset( $data['duration_ms'] ) ? $data['duration_ms'] . ' ms' : '—' ) ),
		array( 'label' => 'Memory peak', 'value' => isset( $data['memory_peak_mb'] ) ? $data['memory_peak_mb'] . ' MB' : '—' ),
		array( 'label' => 'Callbacks', 'value' => isset( $summary['total_callbacks'] ) ? (string) (int) $summary['total_callbacks'] : '—' ),
		array( 'label' => 'Sources', 'value' => isset( $summary['total_sources'] ) ? (string) (int) $summary['total_sources'] : '—' ),
		array(
			'label' => 'Unattributed',
			'value' => ( isset( $summary['unattributed_ms'] ) && null !== $summary['unattributed_ms'] )
				? $summary['unattributed_ms'] . ' ms'
					. ( isset( $summary['unattributed_pct'] ) ? ' (' . $summary['unattributed_pct'] . '%)' : '' )
				: '—',
		),
		array( 'label' => 'Captured', 'value' => (string) ( $data['captured_at'] ?? '' ) ),
		array( 'label' => 'Pinned', 'value' => ! empty( $data['pinned'] ) ? 'Yes' : 'No' ),
		array( 'label' => 'Note', 'value' => (string) ( $data['note'] ?? '' ) ),
		array( 'label' => 'Tags', 'value' => ! empty( $data['tags'] ) && is_array( $data['tags'] ) ? implode( ', ', $data['tags'] ) : '' ),
	);

	$sources = array();
	foreach ( array_slice( (array) ( $data['sources'] ?? array() ), 0, 25 ) as $src ) {
		if ( ! is_array( $src ) ) {
			continue;
		}
		$label = (string) ( $src['source'] ?? 'unknown' );
		$type  = (string) ( $src['type'] ?? '' );
		$excl  = isset( $src['exclusive_ms'] ) ? $src['exclusive_ms'] . ' ms' : '—';
		$pct   = isset( $src['exclusive_pct'] ) ? $src['exclusive_pct'] . '%' : '';
		$sources[] = array(
			'label' => $type ? ( $label . ' (' . $type . ')' ) : $label,
			'value' => trim( $excl . ( $pct ? ' · ' . $pct : '' )
				. ( isset( $src['callback_count'] ) ? ' · ' . (int) $src['callback_count'] . ' callbacks' : '' ) ),
		);
	}

	$queries = array();
	foreach ( array_slice( (array) ( $data['queries'] ?? array() ), 0, 20 ) as $q ) {
		if ( ! is_array( $q ) ) {
			continue;
		}
		$sql = (string) ( $q['sql'] ?? '' );
		if ( strlen( $sql ) > 240 ) {
			$sql = substr( $sql, 0, 240 ) . '…';
		}
		$ms = isset( $q['time_ms'] ) ? $q['time_ms'] . ' ms' : '—';
		$src = (string) ( $q['source'] ?? '' );
		$queries[] = array(
			'label' => $ms . ( $src ? ' · ' . $src : '' ),
			'value' => $sql,
		);
	}

	$http = array();
	foreach ( array_slice( (array) ( $data['http_calls'] ?? array() ), 0, 20 ) as $h ) {
		if ( ! is_array( $h ) ) {
			continue;
		}
		$url = (string) ( $h['url'] ?? '' );
		$http[] = array(
			'label' => trim(
				(string) ( $h['method'] ?? 'GET' ) . ' '
				. ( isset( $h['status'] ) ? (int) $h['status'] : '—' )
				. ( isset( $h['duration_ms'] ) ? ' · ' . $h['duration_ms'] . ' ms' : '' )
			),
			'value' => $url
				. ( ! empty( $h['source'] ) ? ' · ' . $h['source'] : '' )
				. ( isset( $h['blocking'] ) && ! $h['blocking'] ? ' · async' : '' ),
		);
	}

	$milestones = array();
	foreach ( array_slice( (array) ( $data['milestones'] ?? array() ), 0, 20 ) as $m ) {
		if ( ! is_array( $m ) ) {
			continue;
		}
		$milestones[] = array(
			'label' => (string) ( $m['label'] ?: $m['hook'] ?: '—' ),
			'value' => isset( $m['offset_ms'] ) ? $m['offset_ms'] . ' ms' : '—',
		);
	}

	$sections = array_values( array_filter( array(
		array(
			'title' => 'Summary',
			'rows'  => array_values( array_filter( $meta, function ( $r ) {
				return '' !== (string) $r['value'] && '—' !== (string) $r['value'];
			} ) ),
		),
		$sources ? array( 'title' => 'Top sources', 'rows' => $sources ) : null,
		$queries ? array( 'title' => 'Queries', 'rows' => $queries ) : null,
		$http ? array( 'title' => 'HTTP calls', 'rows' => $http ) : null,
		$milestones ? array( 'title' => 'Timeline milestones', 'rows' => $milestones ) : null,
	) ) );

	$title = (string) ( $data['route'] ?? ( 'Profile #' . (int) $data['id'] ) );
	if ( isset( $data['duration_ms'] ) ) {
		$title .= ' · ' . $data['duration_ms'] . ' ms';
	}

	return array(
		'title'    => $title,
		'status'   => ! empty( $data['pinned'] ) ? 'pinned' : 'profile',
		'sections' => $sections,
		'adminUrl' => minn_admin_scrutoscope_admin_url(),
	);
}

/** Status card model (capture posture + counts). */
function minn_admin_scrutoscope_status_model() {
	global $wpdb;

	$table = minn_admin_scrutoscope_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$total = 0;
	$last  = '';
	if ( $found && 0 === strcasecmp( (string) $found, $table ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = (string) $wpdb->get_var( "SELECT captured_at FROM {$table} ORDER BY captured_at DESC, id DESC LIMIT 1" );
	}

	$bg    = (bool) get_option( 'scrutoscope_background_profiling', false );
	$rate  = (float) get_option( 'scrutoscope_sample_rate', 10 );
	$qprof = function_exists( 'scrutoscope_query_profiling_state' )
		? scrutoscope_query_profiling_state()
		: array( 'active' => false, 'managed' => true );
	$light = (bool) get_option( 'scrutoscope_lightweight_mode', false );

	$rows = array(
		array(
			'label' => 'Background capture',
			'value' => $bg ? ( 'On · ' . rtrim( rtrim( sprintf( '%.1f', $rate ), '0' ), '.' ) . '% sample' ) : 'Off',
			'hint'  => $bg ? 'Sampled front-end and admin requests' : 'Start a session or enable sampling in Scrutoscope',
		),
		array(
			'label' => 'Profiles stored',
			'value' => number_format_i18n( $total ),
			'hint'  => $last ? ( 'Latest ' . $last ) : 'None yet',
		),
		array(
			'label' => 'Query profiling',
			'value' => ! empty( $qprof['active'] ) ? 'On' : 'Off',
			'hint'  => ! empty( $qprof['managed'] )
				? 'SAVEQUERIES via Scrutoscope settings'
				: 'SAVEQUERIES set outside Scrutoscope',
		),
		array(
			'label' => 'Capture mode',
			'value' => $light ? 'Lightweight' : 'Full',
			'hint'  => $light ? 'Sources only (smaller profiles)' : 'Timeline + per-callback trace',
		),
		array(
			'label' => 'Version',
			'value' => defined( 'SCRUTOSCOPE_VERSION' ) ? (string) SCRUTOSCOPE_VERSION : '—',
		),
	);

	return array(
		'rows'    => $rows,
		'actions' => array(
			array(
				'label' => 'Open Scrutoscope ↗',
				'href'  => minn_admin_scrutoscope_admin_url(),
			),
		),
	);
}

/**
 * Cron inventory rows via Scrutoscope's Diagnostics\Cron when present.
 *
 * @return array{items: array, total: int}
 */
function minn_admin_scrutoscope_cron_list( WP_REST_Request $request ) {
	if ( ! class_exists( '\\Scrutoscope\\Diagnostics\\Cron' ) ) {
		return array( 'items' => array(), 'total' => 0 );
	}
	$collect = \Scrutoscope\Diagnostics\Cron::collect();
	$events  = isset( $collect['events'] ) && is_array( $collect['events'] ) ? $collect['events'] : array();
	$items   = array();
	foreach ( $events as $i => $ev ) {
		if ( ! is_array( $ev ) ) {
			continue;
		}
		$hook = (string) ( $ev['hook'] ?? '' );
		$attr = isset( $ev['attribution'] ) && is_array( $ev['attribution'] ) ? $ev['attribution'] : array();
		$src  = (string) ( $attr['name'] ?? $attr['slug'] ?? $attr['source'] ?? '—' );
		$items[] = array(
			'id'       => md5( $hook . '|' . ( $ev['args_hash'] ?? $i ) . '|' . ( $ev['timestamp'] ?? 0 ) ),
			'hook'     => $hook,
			'schedule' => (string) ( $ev['schedule'] ?? 'once' ),
			'source'   => $src,
			'status'   => ! empty( $ev['overdue'] ) ? 'overdue' : 'scheduled',
			// Their time_human is already "Y-m-d H:i:s UTC".
			'date'     => ! empty( $ev['timestamp'] )
				? gmdate( 'Y-m-d\TH:i:s\Z', (int) $ev['timestamp'] )
				: '',
		);
	}

	// Overdue first, then soonest.
	usort( $items, function ( $a, $b ) {
		if ( $a['status'] !== $b['status'] ) {
			return 'overdue' === $a['status'] ? -1 : 1;
		}
		return strcmp( $a['date'], $b['date'] );
	} );

	$kind = (string) $request->get_param( 'kind' );
	if ( 'overdue' === $kind ) {
		$items = array_values( array_filter( $items, function ( $r ) {
			return 'overdue' === $r['status'];
		} ) );
	}

	$search = (string) $request->get_param( 'search' );
	if ( $search ) {
		$q     = strtolower( $search );
		$items = array_values( array_filter( $items, function ( $r ) use ( $q ) {
			return false !== strpos( strtolower( $r['hook'] ), $q )
				|| false !== strpos( strtolower( $r['source'] ), $q );
		} ) );
	}

	$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
	$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
	$total    = count( $items );
	$items    = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

	return array( 'items' => $items, 'total' => $total );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_scrutoscope_active() || ! minn_admin_scrutoscope_can() ) {
		return $surfaces;
	}

	$surfaces['scrutoscope'] = array(
		'label'      => 'Profiler',
		'sub'        => 'Scrutoscope',
		'icon'       => 'cpu',
		'cap'        => 'manage_options',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/scrutoscope/status' ),
		'collection' => array(
			'viewLabel' => 'Profiles',
			'route'     => 'minn-admin/v1/scrutoscope/profiles',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'kind',
				'static'   => array(
					array( 'pinned', 'Pinned' ),
					array( 'session', 'Session' ),
					array( 'background', 'Background' ),
				),
				'allLabel' => 'All profiles',
			),
			'columns'   => array(
				array( 'key' => 'route', 'label' => 'Route', 'format' => 'title' ),
				array( 'key' => 'duration', 'label' => 'Duration', 'format' => 'text' ),
				array( 'key' => 'type', 'label' => 'Type', 'format' => 'text' ),
				array( 'key' => 'role', 'label' => 'Role', 'format' => 'text' ),
				array( 'key' => 'http', 'label' => 'HTTP', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/scrutoscope/profiles/{id}',
			),
			'actions'   => array(
				array(
					'label' => 'Open Scrutoscope ↗',
					'href'  => minn_admin_scrutoscope_admin_url(),
				),
				array(
					'label'   => 'Delete profile',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/scrutoscope/profiles/{id}',
					'confirm' => 'Delete this profile permanently? Pinned profiles can be deleted too.',
					'danger'  => true,
				),
			),
		),
		'views'      => array(
			array(
				'viewLabel' => 'Cron',
				'route'     => 'minn-admin/v1/scrutoscope/cron',
				'pageQuery' => 'per_page=50&page={page}',
				'search'    => 'search={q}',
				'itemsKey'  => 'items',
				'totalKey'  => 'total',
				'tabs'      => array(
					'param'    => 'kind',
					'static'   => array(
						array( 'overdue', 'Overdue' ),
					),
					'allLabel' => 'All events',
				),
				'columns'   => array(
					array( 'key' => 'hook', 'label' => 'Hook', 'format' => 'title' ),
					array( 'key' => 'schedule', 'label' => 'Schedule', 'format' => 'text' ),
					array( 'key' => 'source', 'label' => 'Source', 'format' => 'text' ),
					array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
					array( 'key' => 'date', 'label' => 'Next run', 'format' => 'ago', 'utc' => true ),
				),
				// No detail modal for cron rows — inventory only.
				'detail'    => array(),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_scrutoscope_active() ) {
		return;
	}

	$perm = 'minn_admin_scrutoscope_can';

	register_rest_route( 'minn-admin/v1', '/scrutoscope/profiles', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			return rest_ensure_response( minn_admin_scrutoscope_list( $request ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/scrutoscope/profiles/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$out = minn_admin_scrutoscope_profile_sections( (int) $request['id'] );
				if ( is_wp_error( $out ) ) {
					return $out;
				}
				return rest_ensure_response( $out );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id = (int) $request['id'];
				$row = \Scrutoscope\Profiler\Storage::get_profile( $id );
				if ( null === $row ) {
					return new WP_Error( 'not_found', 'Profile not found.', array( 'status' => 404 ) );
				}
				\Scrutoscope\Profiler\Storage::delete_profile( $id );
				return rest_ensure_response( array( 'ok' => true, 'message' => 'Profile deleted.' ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/scrutoscope/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_scrutoscope_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/scrutoscope/cron', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			return rest_ensure_response( minn_admin_scrutoscope_cron_list( $request ) );
		},
	) );
} );
