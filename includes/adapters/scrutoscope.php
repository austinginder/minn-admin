<?php
/**
 * Bundled adapter: Scrutoscope (performance profiler).
 *
 * Scrutoscope is a read-only WordPress performance profiler with a real REST
 * API under scrutoscope/v1 (manage_options). Minn surfaces recent profiles as
 * a Tools list built from their own routes via rest_do_request, so their
 * output sanitizer stays in the path: /profiles for the list (1.5+, with a
 * column-read fallback for older builds) and /profile/{id} for top sources,
 * queries and HTTP calls. The Cron view inventories scheduled hooks (via their
 * Diagnostics\Cron) and, on Scrutoscope 1.4+, offers Profile this hook —
 * which calls Profiler::profile_cron_hook so the hook's real side effects
 * fire under the profiler and a new on_demand profile is saved. Capture
 * settings, pin UI, share, and the full timeline stay on Scrutoscope's Tools
 * screen — one deep link away.
 *
 * Complements the Query Monitor panel: QM is this-request; Scrutoscope is
 * sampled history across routes.
 *
 * Nav: family `diagnostics` (label Diagnostics) shared with WP Crontrol so
 * Tools does not grow a top-level item per profiler/cron plugin. Provider
 * switcher picks Scrutoscope vs Cron when both are active.
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
 * Whether Scrutoscope 1.5+ serves the per-capture list over REST.
 *
 * Gated on the shaper their contract names (not a version string) so a
 * downgrade falls back cleanly. Preferred over the table read because their
 * output Sanitizer runs on that path: reduced SQL and host-only HTTP URLs are
 * re-applied at read time as defense-in-depth for rows written by older
 * builds, and a direct SELECT is exactly what skips it.
 */
function minn_admin_scrutoscope_has_list_api() {
	return method_exists( '\\Scrutoscope\\Api\\RestApi', 'shape_profile_list_item' );
}

/** Whether Storage exposes the aggregate stats helper (1.5+). */
function minn_admin_scrutoscope_has_table_stats() {
	return method_exists( '\\Scrutoscope\\Profiler\\Storage', 'get_table_stats' );
}

/** Human label for a route_class value; unknown classes pass through. */
function minn_admin_scrutoscope_route_class_label( $class ) {
	$map = array(
		'front'   => __( 'Front end', 'minn-admin' ),
		'admin'   => __( 'Admin', 'minn-admin' ),
		'ajax'    => __( 'Ajax', 'minn-admin' ),
		'rest'    => __( 'REST', 'minn-admin' ),
		'wp-cron' => __( 'Cron', 'minn-admin' ),
		'cli'     => __( 'CLI', 'minn-admin' ),
	);
	$class = (string) $class;
	if ( isset( $map[ $class ] ) ) {
		return $map[ $class ];
	}
	return '' !== $class ? $class : '—';
}

/**
 * Map one item in Scrutoscope's list shape (RestApi::shape_profile_list_item)
 * to a Minn collection row. The legacy table path converts its rows into the
 * same shape first, so both paths render identically.
 *
 * @param array $r Item in their list shape.
 */
function minn_admin_scrutoscope_shape_row( array $r ) {
	$ms    = isset( $r['duration_ms'] )
		? (float) $r['duration_ms']
		: round( ( (float) ( $r['duration_ns'] ?? 0 ) ) / 1e6, 1 );
	$route = (string) ( $r['route'] ?? '' );
	$type  = (string) ( $r['profile_type'] ?? '' );
	$type  = '' !== $type ? $type : 'session';

	return array(
		'id'          => (int) ( $r['id'] ?? 0 ),
		'route'       => '' !== $route
			? $route
			: trim( (string) ( $r['request_method'] ?? '' ) . ' ' . (string) ( $r['request_url'] ?? '' ) ),
		'context'     => minn_admin_scrutoscope_route_class_label( $r['route_class'] ?? '' ),
		'duration'    => $ms . ' ms',
		'duration_ms' => $ms,
		'type'        => $type,
		'role'        => (string) ( $r['user_role'] ?? '' ) ?: '—',
		'status'      => ! empty( $r['is_pinned'] ) ? 'pinned' : $type,
		'pinned'      => ! empty( $r['is_pinned'] ),
		// captured_at is current_time('mysql') = site-local; emit raw.
		'date'        => (string) ( $r['captured_at'] ?? '' ),
		'http'        => ! empty( $r['response_status'] ) ? (string) (int) $r['response_status'] : '—',
	);
}

/**
 * List rows for the collection, newest first.
 *
 * Scrutoscope 1.5+ serves individual captures at GET /scrutoscope/v1/profiles
 * (their route-grouped endpoint never listed them), so Minn reads through
 * rest_do_request and their sanitizer stays in the path. Older builds fall
 * back to the column read below. Columns only either way — heavy profile_data
 * blobs stay out of the list path.
 *
 * @return array{items: array, total: int}
 */
function minn_admin_scrutoscope_list( WP_REST_Request $request ) {
	if ( minn_admin_scrutoscope_has_list_api() ) {
		$req = new WP_REST_Request( 'GET', '/scrutoscope/v1/profiles' );
		foreach ( array( 'kind', 'search', 'page', 'per_page' ) as $param ) {
			$value = $request->get_param( $param );
			if ( null !== $value && '' !== $value ) {
				$req->set_param( $param, $value );
			}
		}
		$res = rest_do_request( $req );
		if ( ! $res->is_error() ) {
			$data  = $res->get_data();
			$items = array();
			foreach ( (array) ( $data['items'] ?? array() ) as $r ) {
				if ( is_array( $r ) ) {
					$items[] = minn_admin_scrutoscope_shape_row( $r );
				}
			}
			return array( 'items' => $items, 'total' => (int) ( $data['total'] ?? 0 ) );
		}
		// Fall through to the column read if their route answered an error.
	}

	return minn_admin_scrutoscope_list_legacy( $request );
}

/**
 * Pre-1.5 fallback: read the profiles table columns directly.
 *
 * @return array{items: array, total: int}
 */
function minn_admin_scrutoscope_list_legacy( WP_REST_Request $request ) {
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
	} elseif ( in_array( $kind, array( 'session', 'background', 'on_demand' ), true ) ) {
		$where[] = 'profile_type = %s';
		$args[]  = $kind;
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
			"SELECT id, route_key, route_class, request_method, request_url, profile_type, duration_ns, user_role, captured_at, is_pinned, note, tags, response_status
			 FROM {$table} WHERE {$where_sql} ORDER BY captured_at DESC, id DESC LIMIT %d OFFSET %d",
			array_merge( $args, array( $per_page, $offset ) )
		), ARRAY_A );
	} else {
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, route_key, route_class, request_method, request_url, profile_type, duration_ns, user_role, captured_at, is_pinned, note, tags, response_status
			 FROM {$table} WHERE {$where_sql} ORDER BY captured_at DESC, id DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		), ARRAY_A );
	}
	// phpcs:enable

	$items = array();
	foreach ( (array) $rows as $r ) {
		// Normalize the column read into their list shape, then share the mapper.
		$r['route'] = (string) ( $r['route_key'] ?? '' );
		$items[]    = minn_admin_scrutoscope_shape_row( $r );
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
		return new WP_Error( 'not_found', __( 'Profile not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}

	$summary = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : array();
	$meta    = array(
		array( 'label' => __( 'Route', 'minn-admin' ), 'value' => (string) ( $data['route'] ?? '' ) ),
		array( 'label' => __( 'Duration', 'minn-admin' ), 'value' => ( isset( $data['duration_ms'] ) ? $data['duration_ms'] . ' ms' : '—' ) ),
		array( 'label' => __( 'Memory peak', 'minn-admin' ), 'value' => isset( $data['memory_peak_mb'] ) ? $data['memory_peak_mb'] . ' MB' : '—' ),
		array( 'label' => __( 'Callbacks', 'minn-admin' ), 'value' => isset( $summary['total_callbacks'] ) ? (string) (int) $summary['total_callbacks'] : '—' ),
		array( 'label' => __( 'Sources', 'minn-admin' ), 'value' => isset( $summary['total_sources'] ) ? (string) (int) $summary['total_sources'] : '—' ),
		array(
			'label' => __( 'Unattributed', 'minn-admin' ),
			'value' => ( isset( $summary['unattributed_ms'] ) && null !== $summary['unattributed_ms'] )
				? $summary['unattributed_ms'] . ' ms'
					. ( isset( $summary['unattributed_pct'] ) ? ' (' . $summary['unattributed_pct'] . '%)' : '' )
				: '—',
		),
		array( 'label' => __( 'Captured', 'minn-admin' ), 'value' => (string) ( $data['captured_at'] ?? '' ) ),
		array( 'label' => __( 'Pinned', 'minn-admin' ), 'value' => ! empty( $data['pinned'] ) ? 'Yes' : 'No' ),
		array( 'label' => __( 'Note', 'minn-admin' ), 'value' => (string) ( $data['note'] ?? '' ) ),
		array( 'label' => __( 'Tags', 'minn-admin' ), 'value' => ! empty( $data['tags'] ) && is_array( $data['tags'] ) ? implode( ', ', $data['tags'] ) : '' ),
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
			'title' => __( 'Summary', 'minn-admin' ),
			'rows'  => array_values( array_filter( $meta, function ( $r ) {
				return '' !== (string) $r['value'] && '—' !== (string) $r['value'];
			} ) ),
		),
		$sources ? array( 'title' => __( 'Top sources', 'minn-admin' ), 'rows' => $sources ) : null,
		$queries ? array( 'title' => __( 'Queries', 'minn-admin' ), 'rows' => $queries ) : null,
		$http ? array( 'title' => __( 'HTTP calls', 'minn-admin' ), 'rows' => $http ) : null,
		$milestones ? array( 'title' => __( 'Timeline milestones', 'minn-admin' ), 'rows' => $milestones ) : null,
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

/**
 * Storage totals for the status card.
 *
 * Scrutoscope 1.5+ answers this from Storage::get_table_stats() (and the
 * newest capture comes off their list route), so the adapter runs no SQL of
 * its own. Older builds fall back to the column read.
 *
 * @return array{total: int, last: string, routes: int, pinned: int, oldest: string, bytes: int}
 */
function minn_admin_scrutoscope_storage_stats() {
	$out = array( 'total' => 0, 'last' => '', 'routes' => 0, 'pinned' => 0, 'oldest' => '', 'bytes' => 0 );

	if ( minn_admin_scrutoscope_has_table_stats() ) {
		$stats         = (array) \Scrutoscope\Profiler\Storage::get_table_stats();
		$out['total']  = (int) ( $stats['rows'] ?? 0 );
		$out['routes'] = (int) ( $stats['route_count'] ?? 0 );
		$out['pinned'] = (int) ( $stats['pinned_count'] ?? 0 );
		$out['oldest'] = (string) ( $stats['oldest'] ?? '' );
		$out['bytes']  = (int) ( $stats['size_bytes'] ?? 0 );

		// get_table_stats() reports the oldest capture; the newest rides the
		// list route (already sorted newest first).
		if ( $out['total'] > 0 && minn_admin_scrutoscope_has_list_api() ) {
			$req = new WP_REST_Request( 'GET', '/scrutoscope/v1/profiles' );
			$req->set_param( 'per_page', 1 );
			$res = rest_do_request( $req );
			if ( ! $res->is_error() ) {
				$first        = (array) ( $res->get_data()['items'][0] ?? array() );
				$out['last'] = (string) ( $first['captured_at'] ?? '' );
			}
		}
		return $out;
	}

	global $wpdb;
	$table = minn_admin_scrutoscope_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $found && 0 === strcasecmp( (string) $found, $table ) ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out['last'] = (string) $wpdb->get_var( "SELECT captured_at FROM {$table} ORDER BY captured_at DESC, id DESC LIMIT 1" );
	}
	return $out;
}

/** Status card model (capture posture + counts). */
function minn_admin_scrutoscope_status_model() {
	$stats = minn_admin_scrutoscope_storage_stats();
	$total = $stats['total'];
	$last  = $stats['last'];

	$bg    = (bool) get_option( 'scrutoscope_background_profiling', false );
	$rate  = (float) get_option( 'scrutoscope_sample_rate', 10 );
	$qprof = function_exists( 'scrutoscope_query_profiling_state' )
		? scrutoscope_query_profiling_state()
		: array( 'active' => false, 'managed' => true );
	$light = (bool) get_option( 'scrutoscope_lightweight_mode', false );

	$rows = array(
		array(
			'label' => __( 'Background capture', 'minn-admin' ),
			'value' => $bg ? ( 'On · ' . rtrim( rtrim( sprintf( '%.1f', $rate ), '0' ), '.' ) . '% sample' ) : 'Off',
			'hint'  => $bg ? 'Sampled front-end and admin requests' : 'Start a session or enable sampling in Scrutoscope',
		),
		array(
			'label' => __( 'Profiles stored', 'minn-admin' ),
			'value' => number_format_i18n( $total ),
			'hint'  => $last
				? trim(
					/* translators: %s: date and time of the most recent capture. */
					sprintf( __( 'Latest %s', 'minn-admin' ), $last )
					. ( $stats['pinned'] > 0
						/* translators: %s: number of pinned profiles. */
						? ' · ' . sprintf( __( '%s pinned', 'minn-admin' ), number_format_i18n( $stats['pinned'] ) )
						: '' )
				)
				: 'None yet',
		),
		array(
			'label' => __( 'Query profiling', 'minn-admin' ),
			'value' => ! empty( $qprof['active'] ) ? 'On' : 'Off',
			'hint'  => ! empty( $qprof['managed'] )
				? 'SAVEQUERIES via Scrutoscope settings'
				: 'SAVEQUERIES set outside Scrutoscope',
		),
		array(
			'label' => __( 'Capture mode', 'minn-admin' ),
			'value' => $light ? 'Lightweight' : 'Full',
			'hint'  => $light ? 'Sources only (smaller profiles)' : 'Timeline + per-callback trace',
		),
		array(
			'label' => __( 'Version', 'minn-admin' ),
			'value' => defined( 'SCRUTOSCOPE_VERSION' ) ? (string) SCRUTOSCOPE_VERSION : '—',
		),
	);

	// Coverage breadth + retention window (Scrutoscope 1.5+ stats only).
	if ( $stats['routes'] > 0 ) {
		array_splice(
			$rows,
			2,
			0,
			array(
				array(
					'label' => __( 'Routes covered', 'minn-admin' ),
					'value' => number_format_i18n( $stats['routes'] ),
					'hint'  => $stats['oldest']
						/* translators: %s: date and time of the oldest stored capture. */
						? sprintf( __( 'History since %s', 'minn-admin' ), $stats['oldest'] )
						: '',
				),
			)
		);
	}

	return array(
		'rows'    => $rows,
		'actions' => array(
			array(
				'label' => __( 'Open Scrutoscope ↗', 'minn-admin' ),
				'href'  => minn_admin_scrutoscope_admin_url(),
			),
		),
	);
}

/**
 * Stable row id for a Scrutoscope Diagnostics\Cron event.
 * Must stay in lockstep between the list and the profile action lookup.
 *
 * @param array $ev Event from Cron::collect().
 * @param int   $i  Fallback index when args_hash is absent.
 */
function minn_admin_scrutoscope_cron_id( $ev, $i = 0 ) {
	$hook = (string) ( $ev['hook'] ?? '' );
	return md5( $hook . '|' . ( $ev['args_hash'] ?? $i ) . '|' . ( $ev['timestamp'] ?? 0 ) );
}

/**
 * Whether Scrutoscope 1.4+ on-demand cron profiling is available.
 * Gated on the public method so a downgrade hides the action cleanly.
 */
function minn_admin_scrutoscope_can_profile_cron() {
	if ( ! minn_admin_scrutoscope_active() ) {
		return false;
	}
	if ( ! class_exists( '\\Scrutoscope\\Profiler\\Profiler' ) ) {
		return false;
	}
	$profiler = \Scrutoscope\Profiler\Profiler::instance();
	return is_object( $profiler ) && method_exists( $profiler, 'profile_cron_hook' );
}

/**
 * Resolve a cron-row id back to its live event (hook + scheduled args).
 *
 * @return array{hook: string, args: array}|null
 */
function minn_admin_scrutoscope_cron_resolve( $id ) {
	if ( ! class_exists( '\\Scrutoscope\\Diagnostics\\Cron' ) ) {
		return null;
	}
	$id      = (string) $id;
	$collect = \Scrutoscope\Diagnostics\Cron::collect();
	$events  = isset( $collect['events'] ) && is_array( $collect['events'] ) ? $collect['events'] : array();
	foreach ( $events as $i => $ev ) {
		if ( ! is_array( $ev ) ) {
			continue;
		}
		if ( minn_admin_scrutoscope_cron_id( $ev, $i ) !== $id ) {
			continue;
		}
		$hook = (string) ( $ev['hook'] ?? '' );
		if ( '' === $hook ) {
			return null;
		}
		$args = isset( $ev['args'] ) && is_array( $ev['args'] ) ? $ev['args'] : array();
		return array(
			'hook' => $hook,
			'args' => $args,
		);
	}
	return null;
}

/**
 * Profile one cron hook on demand via Scrutoscope's own Profiler API
 * (1.4+: profile_cron_hook). Fires the hook with its scheduled args in this
 * request — side effects are real. Returns the new profile id.
 *
 * @param string $id Cron row id from the inventory.
 * @return array|WP_Error
 */
/**
 * May the current user cause this cron hook to actually run?
 *
 * "Profile this hook" is not a read: Scrutoscope's profile_cron_hook() does a
 * real do_action_ref_array(), so every callback on the hook fires with its
 * scheduled arguments. When WP Crontrol is also installed one of those hooks
 * is crontrol_cron_job, whose callback evals PHP the site stored, and WP
 * Crontrol guards running it with edit_files. Core maps edit_files to
 * do_not_allow under DISALLOW_FILE_EDIT and DISALLOW_FILE_MODS, and for every
 * non-super-admin on multisite, so manage_options alone is a weaker gate than
 * the one both WP Crontrol's screen and Minn's own Crontrol adapter apply.
 *
 * Defers to WP Crontrol's own runnable() when it is loaded, so one predicate
 * governs both Diagnostics providers.
 *
 * @param string $hook Cron hook name.
 * @return bool
 */
function minn_admin_scrutoscope_cron_runnable( $hook ) {
	$hook = (string) $hook;

	if ( function_exists( '\Crontrol\Event\find' ) && class_exists( '\Crontrol\Context\WordPressUserContext' ) ) {
		try {
			$event = \Crontrol\Event\find( $hook );
			if ( $event && method_exists( $event, 'runnable' ) ) {
				return (bool) $event->runnable(
					new \Crontrol\Context\WordPressUserContext(),
					new \Crontrol\Context\WordPressFeatureContext()
				);
			}
		} catch ( \Throwable $e ) {
			// Fall through to the standalone rule below.
		}
	}

	// Without WP Crontrol loaded, apply the part of its rule that matters: a
	// hook whose whole purpose is running stored PHP is code execution, so it
	// answers to the directive a site owner sets to forbid that.
	if ( 'crontrol_cron_job' === $hook ) {
		if ( class_exists( 'Minn_Admin' ) && ! Minn_Admin::code_edits_allowed() ) {
			return false;
		}
		return current_user_can( 'edit_files' );
	}

	return true;
}

function minn_admin_scrutoscope_profile_cron( $id ) {
	if ( ! minn_admin_scrutoscope_can_profile_cron() ) {
		return new WP_Error(
			'unavailable',
			__( 'This version of Scrutoscope cannot profile cron hooks on demand.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	$ev = minn_admin_scrutoscope_cron_resolve( $id );
	if ( ! $ev ) {
		// Match Scrutoscope's ajax fallback: allow a bare hook that still has
		// callbacks even if it fell off the schedule between list and click.
		return new WP_Error(
			'not_found',
			__( 'Cron event not found. It may have already run.', 'minn-admin' ),
			array( 'status' => 404 )
		);
	}
	if ( ! minn_admin_scrutoscope_cron_runnable( $ev['hook'] ) ) {
		return new WP_Error(
			'forbidden',
			__( 'This event cannot be run here.', 'minn-admin' ),
			array( 'status' => 403 )
		);
	}

	$profiler   = \Scrutoscope\Profiler\Profiler::instance();
	$profile_id = $profiler->profile_cron_hook( $ev['hook'], $ev['args'] );
	if ( is_wp_error( $profile_id ) ) {
		return $profile_id;
	}

	return array(
		'ok'         => true,
		'profile_id' => (int) $profile_id,
		'hook'       => $ev['hook'],
		/* translators: %s: cron hook name. */
		'message'    => sprintf( __( 'Profiled “%s”. Open Profiles to inspect it.', 'minn-admin' ), $ev['hook'] ),
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
			'id'       => minn_admin_scrutoscope_cron_id( $ev, $i ),
			'hook'     => $hook,
			'schedule' => (string) ( $ev['schedule'] ?? 'once' ),
			'source'   => $src,
			'status'   => ! empty( $ev['overdue'] ) ? 'overdue' : 'scheduled',
			// Their time_human is already "Y-m-d H:i:s UTC".
			'date'     => ! empty( $ev['timestamp'] )
				? gmdate( 'Y-m-d\TH:i:s\Z', (int) $ev['timestamp'] )
				: '',
			// Gates the Profile action: the method has to exist AND this caller
			// has to be allowed to make this particular hook run, so a hook
			// they cannot run is not offered rather than refused on click.
			'can_profile' => minn_admin_scrutoscope_can_profile_cron()
				&& minn_admin_scrutoscope_cron_runnable( $hook ),
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
		// Family collapses profiler/cron (and future transient tools) into one
		// Tools nav item so Dev tools don't each claim a top-level slot.
		'label'      => __( 'Diagnostics', 'minn-admin' ),
		'sub'        => 'Scrutoscope',
		'family'     => 'diagnostics',
		'icon'       => 'activity',
		'cap'        => 'manage_options',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/scrutoscope/status' ),
		'collection' => array(
			'viewLabel' => __( 'Profiles', 'minn-admin' ),
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
					// Profile this hook (below) writes profile_type=on_demand —
					// without this tab Minn's own captures land under All only.
					array( 'on_demand', 'On demand' ),
				),
				'allLabel' => 'All profiles',
			),
			'columns'   => array(
				array( 'key' => 'route', 'label' => __( 'Route', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'context', 'label' => __( 'Context', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'duration', 'label' => __( 'Duration', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'role', 'label' => __( 'Role', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'http', 'label' => 'HTTP', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/scrutoscope/profiles/{id}',
			),
			'actions'   => array(
				array(
					'label' => __( 'Open Scrutoscope ↗', 'minn-admin' ),
					'href'  => minn_admin_scrutoscope_admin_url(),
				),
				array(
					'label'   => __( 'Delete profile', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/scrutoscope/profiles/{id}',
					'confirm' => __( 'Delete this profile permanently? Pinned profiles can be deleted too.', 'minn-admin' ),
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
					array( 'key' => 'hook', 'label' => __( 'Hook', 'minn-admin' ), 'format' => 'title' ),
					array( 'key' => 'schedule', 'label' => __( 'Schedule', 'minn-admin' ), 'format' => 'text' ),
					array( 'key' => 'source', 'label' => __( 'Source', 'minn-admin' ), 'format' => 'text' ),
					array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
					array( 'key' => 'date', 'label' => __( 'Next run', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
				),
				// No detail modal for cron rows — inventory + row actions only.
				'detail'    => array(),
				// Profile this hook (Scrutoscope 1.4+): fires the hook with its
				// scheduled args under the profiler. Side effects are real —
				// the confirm says so. Hidden cleanly when the method is absent.
				'actions'   => minn_admin_scrutoscope_can_profile_cron() ? array(
					array(
						'label'   => __( 'Profile this hook', 'minn-admin' ),
						'method'  => 'POST',
						'route'   => 'minn-admin/v1/scrutoscope/cron/{id}/profile',
						'confirm' => __( 'Run this cron hook now while profiling it? The hook’s normal side effects will happen (emails, updates, cleanup, queue work). A profile is saved under Profiles afterward.', 'minn-admin' ),
						'when'    => array( 'key' => 'can_profile', 'equals' => true ),
					),
					array(
						'label' => __( 'Open Scrutoscope ↗', 'minn-admin' ),
						'href'  => minn_admin_scrutoscope_admin_url(),
					),
				) : array(
					array(
						'label' => __( 'Open Scrutoscope ↗', 'minn-admin' ),
						'href'  => minn_admin_scrutoscope_admin_url(),
					),
				),
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
					return new WP_Error( 'not_found', __( 'Profile not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				\Scrutoscope\Profiler\Storage::delete_profile( $id );
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Profile deleted.', 'minn-admin' ) ) );
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

	// On-demand cron profiling (Scrutoscope 1.4+). Id is the inventory row
	// md5; the handler re-resolves hook + scheduled args from live cron.
	register_rest_route( 'minn-admin/v1', '/scrutoscope/cron/(?P<id>[a-f0-9]{32})/profile', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$out = minn_admin_scrutoscope_profile_cron( (string) $request['id'] );
			return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
		},
	) );
} );
